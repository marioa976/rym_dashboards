<?php
/**
 * Planificador de rutas para cuadrillas.
 *
 * Toma todos los tickets candidatos (filtrados), los prioriza según un score,
 * los asigna a N cuadrillas con K-means geográfico y dentro de cada cuadrilla
 * los reparte por día ordenando con nearest-neighbor TSP desde el punto de partida.
 *
 * Resultado: tabla por día y cuadrilla + mapa con rutas + botón para abrir
 * directamente en Google Maps cada ruta para navegar.
 */
require __DIR__ . '/db.php';
$pdo = db();
$cfg = require __DIR__ . '/config.php';
$api_key = trim($cfg['google_maps_api_key'] ?? '');

// ============================================================
//  Helpers geográficos y de optimización
// ============================================================
function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2)) * sin($dLng/2)**2;
    return 2 * $R * asin(min(1.0, sqrt($a)));
}

/** Nearest-neighbor TSP simple desde un origen. */
function ruta_nn(array $depot, array $puntos): array {
    $ruta = [];
    $current = $depot;
    while (count($puntos)) {
        $bestIdx = 0; $bestDist = INF;
        foreach ($puntos as $i => $p) {
            $d = haversine_km($current['lat'], $current['lng'], $p['lat'], $p['lng']);
            if ($d < $bestDist) { $bestDist = $d; $bestIdx = $i; }
        }
        $picked = $puntos[$bestIdx];
        $picked['dist_prev_km'] = round($bestDist, 2);
        $ruta[] = $picked;
        $current = ['lat' => $picked['lat'], 'lng' => $picked['lng']];
        array_splice($puntos, $bestIdx, 1);
    }
    return $ruta;
}

/** K-means geográfico (Haversine), k clusters, max_iter iteraciones. */
function kmeans(array $puntos, int $k, int $max_iter = 30): array {
    if (count($puntos) <= $k) {
        // Un cluster por punto, los faltantes vacíos
        $clusters = [];
        foreach ($puntos as $p) $clusters[] = [$p];
        while (count($clusters) < $k) $clusters[] = [];
        return $clusters;
    }
    // k-means++ ligero, DETERMINISTA (siempre arranca con el primer punto
    // ordenado por lat, así el plan se puede regenerar en otra página)
    $centroids = [];
    $puntos_init = $puntos;
    usort($puntos_init, fn($a,$b) => $a['lat'] <=> $b['lat']);
    $centroids[] = $puntos_init[0];
    while (count($centroids) < $k) {
        $bestPt = null; $bestDist = -1;
        foreach ($puntos as $p) {
            $minToCentroid = INF;
            foreach ($centroids as $c) {
                $d = haversine_km($p['lat'],$p['lng'],$c['lat'],$c['lng']);
                if ($d < $minToCentroid) $minToCentroid = $d;
            }
            if ($minToCentroid > $bestDist) { $bestDist = $minToCentroid; $bestPt = $p; }
        }
        $centroids[] = $bestPt;
    }

    for ($iter = 0; $iter < $max_iter; $iter++) {
        $clusters = array_fill(0, $k, []);
        foreach ($puntos as $p) {
            $best = 0; $bestD = INF;
            foreach ($centroids as $idx => $c) {
                $d = haversine_km($p['lat'],$p['lng'],$c['lat'],$c['lng']);
                if ($d < $bestD) { $bestD = $d; $best = $idx; }
            }
            $clusters[$best][] = $p;
        }
        // Recalcular centroides
        $changed = false;
        foreach ($clusters as $idx => $cl) {
            if (empty($cl)) continue;
            $newLat = array_sum(array_column($cl,'lat')) / count($cl);
            $newLng = array_sum(array_column($cl,'lng')) / count($cl);
            if (abs($newLat - $centroids[$idx]['lat']) > 1e-6 || abs($newLng - $centroids[$idx]['lng']) > 1e-6) {
                $changed = true;
                $centroids[$idx] = ['lat'=>$newLat, 'lng'=>$newLng];
            }
        }
        if (!$changed) break;
    }
    return $clusters;
}

/** Distancia total de una secuencia de puntos partiendo de un depósito. */
function dist_total(array $depot, array $ruta): float {
    $total = 0; $prev = $depot;
    foreach ($ruta as $p) {
        $total += haversine_km($prev['lat'],$prev['lng'],$p['lat'],$p['lng']);
        $prev = $p;
    }
    return $total;
}

/** Punto dentro de polígono (ray casting). $poly = [['lat'=>..,'lng'=>..], ...]. */
function punto_en_poligono(float $lat, float $lng, array $poly): bool {
    $n = count($poly);
    if ($n < 3) return false;
    $dentro = false;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $yi = $poly[$i]['lat']; $xi = $poly[$i]['lng'];
        $yj = $poly[$j]['lat']; $xj = $poly[$j]['lng'];
        $cruza = (($yi > $lat) !== ($yj > $lat))
            && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
        if ($cruza) $dentro = !$dentro;
    }
    return $dentro;
}

// ============================================================
//  Catálogos para el form
// ============================================================
$cat_grupos       = $pdo->query("SELECT id,nombre FROM cat_grupo ORDER BY nombre")->fetchAll();
$cat_delegaciones = $pdo->query("SELECT id,nombre FROM cat_delegacion ORDER BY nombre")->fetchAll();
$cat_servicios    = $pdo->query("SELECT id,nombre FROM cat_tipo_servicio ORDER BY nombre")->fetchAll();

// ============================================================
//  Parámetros del plan
// ============================================================
$p_grupo      = isset($_GET['grupo'])         && $_GET['grupo']!==''         ? (int)$_GET['grupo'] : null;
$p_servicio   = isset($_GET['tipo_servicio']) && $_GET['tipo_servicio']!=='' ? (int)$_GET['tipo_servicio'] : null;
$p_delegacion = isset($_GET['delegacion'])    && $_GET['delegacion']!==''    ? (int)$_GET['delegacion'] : null;
$p_estado     = $_GET['estado'] ?? 'sin_resolver';

$p_cuadrillas = max(1, min(10, (int)($_GET['cuadrillas'] ?? 2)));
$p_capacidad  = max(1, min(200, (int)($_GET['capacidad'] ?? 30)));
$p_dias       = max(1, min(14, (int)($_GET['dias'] ?? 5)));

// Jornada y operación
$p_hora_inicio = $_GET['hora_inicio'] ?? '08:00';                                   // HH:MM
$p_min_serv    = max(0, min(240, (int)($_GET['min_serv'] ?? 15)));                  // min por ticket
$p_vel_kmh     = max(5, min(120, (int)($_GET['vel_kmh']  ?? 25)));                  // velocidad de traslado

$p_lat        = isset($_GET['lat']) && $_GET['lat']!=='' ? (float)$_GET['lat'] : (float)$cfg['mapa_centro_lat'];
$p_lng        = isset($_GET['lng']) && $_GET['lng']!=='' ? (float)$_GET['lng'] : (float)$cfg['mapa_centro_lng'];

// Pesos del score (defaults razonables)
$p_w_antig    = (float)($_GET['w_antig']    ?? 1.0);   // 1 punto por cada día abierto
$p_w_vencido  = (float)($_GET['w_vencido']  ?? 15.0);  // bonus si está vencido
$p_w_urgente  = (float)($_GET['w_urgente']  ?? 30.0);  // bonus si prioridad High/Urgent
$p_w_geo      = (float)($_GET['w_geo']      ?? 0.0);   // penaliza distancia desde depósito (km)

$p_metodo     = $_GET['metodo'] ?? 'kmeans';  // 'kmeans' | 'delegacion' | 'roundrobin' | 'mapa'

// Recencia del reporte: por default solo lo reciente (basura/poda caduca).
// recencia = días hacia atrás sobre fecha_creacion; 0 = sin límite.
// Si se da rango desde/hasta, manda el rango.
$p_recencia = isset($_GET['recencia']) ? max(0, min(365, (int)$_GET['recencia'])) : 7;
$p_desde    = (isset($_GET['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'])) ? $_GET['desde'] : '';
$p_hasta    = (isset($_GET['hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'])) ? $_GET['hasta'] : '';

// Selección por polígono (modo mapa). Array de [{lat,lng}, ...] cerrado.
$poligono = [];
if (!empty($_GET['poligono'])) {
    $dec = json_decode((string)$_GET['poligono'], true);
    if (is_array($dec)) {
        foreach ($dec as $pt) {
            if (isset($pt['lat'], $pt['lng'])) $poligono[] = ['lat'=>(float)$pt['lat'], 'lng'=>(float)$pt['lng']];
        }
    }
    if (count($poligono) < 3) $poligono = [];   // un polígono necesita >=3 vértices
}

$ejecutar = isset($_GET['plan']) && $_GET['plan']=='1';

// ============================================================
//  Persistencia de planes (guardar / reabrir / listar / eliminar)
// ============================================================
function cuad_tabla(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cuadrillas_planes (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        nombre       VARCHAR(160) NOT NULL,
        payload      LONGTEXT     NOT NULL,
        n_cuadrillas SMALLINT     NOT NULL DEFAULT 0,
        n_tickets    INT          NOT NULL DEFAULT 0,
        km           DECIMAL(10,1) NOT NULL DEFAULT 0,
        usuario_id   INT UNSIGNED NULL,
        creado_en    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_fecha (creado_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

$save_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_plan' && csrf_check()) {
    $pl = json_decode((string)($_POST['payload'] ?? ''), true);
    if (is_array($pl) && !empty($pl['plan'])) {
        try {
            cuad_tabla($pdo);
            $nombre = trim((string)($_POST['nombre'] ?? '')) ?: ('Plan ' . date('Y-m-d H:i'));
            $nCu = count($pl['plan']); $nTk = 0; $km = 0.0;
            foreach ($pl['plan'] as $c) { $nTk += (int)($c['total_tickets'] ?? 0); $km += (float)($c['total_km'] ?? 0); }
            $pdo->prepare("INSERT INTO cuadrillas_planes (nombre,payload,n_cuadrillas,n_tickets,km,usuario_id) VALUES (?,?,?,?,?,?)")
                ->execute([$nombre, json_encode($pl, JSON_UNESCAPED_UNICODE), $nCu, $nTk, round($km,1), (int)(Auth::user()['id'] ?? 0) ?: null]);
            header('Location: cuadrillas.php?plan_id=' . (int)$pdo->lastInsertId() . '&guardado=1'); exit;
        } catch (Throwable $e) { $save_error = $e->getMessage(); }
    } else { $save_error = 'No hay plan que guardar.'; }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar_plan' && csrf_check()) {
    try {
        $pid = (int)($_POST['id'] ?? 0);
        $uid = (int)(Auth::user()['id'] ?? 0);
        // Un editor/admin puede eliminar cualquier plan; un visor SOLO los suyos
        // (no puede limpiar datos ajenos).
        if (function_exists('puede_editar') && puede_editar('zendesk')) {
            $pdo->prepare("DELETE FROM cuadrillas_planes WHERE id=?")->execute([$pid]);
        } else {
            $pdo->prepare("DELETE FROM cuadrillas_planes WHERE id=? AND usuario_id=?")->execute([$pid, $uid]);
        }
    } catch (Throwable $e) {}
    header('Location: cuadrillas.php?planes=1'); exit;
}

// Listado de planes guardados
$vista_planes = isset($_GET['planes']);
$listaPlanes = [];
if ($vista_planes) {
    try { $listaPlanes = $pdo->query("SELECT id,nombre,n_cuadrillas,n_tickets,km,creado_en,usuario_id FROM cuadrillas_planes ORDER BY creado_en DESC")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) {}
}

// ============================================================
//  Si se pidió generar plan, ejecutar
// ============================================================
$plan = null;
$pool = [];
$stats_pool = ['candidatos'=>0, 'requeridos'=>0];
$desde_guardado = false;
$plan_nombre = '';

// Reabrir un plan guardado
$plan_id = (int)($_GET['plan_id'] ?? 0);
if ($plan_id > 0) {
    try {
        $st = $pdo->prepare("SELECT nombre,payload FROM cuadrillas_planes WHERE id=?");
        $st->execute([$plan_id]);
        if ($row = $st->fetch()) {
            $pl = json_decode($row['payload'], true);
            if (is_array($pl) && !empty($pl['plan'])) {
                $plan         = $pl['plan'];
                $stats_pool   = $pl['stats_pool'] ?? $stats_pool;
                foreach (($pl['params'] ?? []) as $k => $v) $_GET[$k] = $v;   // para links Simular/Hoja
                $p_lat        = (float)($pl['params']['lat'] ?? $p_lat);
                $p_lng        = (float)($pl['params']['lng'] ?? $p_lng);
                $ejecutar     = true;
                $desde_guardado = true;
                $plan_nombre  = $row['nombre'];
            }
        }
    } catch (Throwable $e) {}
}

// Modo mapa: 'mapa' tiene dos fases — dibujar (sin polígono) y rutear (con polígono).
$modo_mapa    = ($p_metodo === 'mapa');
$mapa_dibujar = $ejecutar && !$desde_guardado && $modo_mapa && empty($poligono);

if ($ejecutar && !$desde_guardado) {
    $where = ["t.latitud IS NOT NULL", "t.longitud IS NOT NULL"];
    $params = [];

    if ($p_grupo)      { $where[] = "t.grupo_id = ?";         $params[] = $p_grupo; }
    if ($p_servicio)   { $where[] = "t.tipo_servicio_id = ?"; $params[] = $p_servicio; }
    if ($p_delegacion) { $where[] = "t.delegacion_id = ?";    $params[] = $p_delegacion; }

    if ($p_estado === 'sin_resolver') $where[] = "e.es_resuelto = 0";
    elseif ($p_estado === 'vencidos') $where[] = "e.es_resuelto = 0 AND t.fecha_estimada < CURDATE()";
    elseif ($p_estado === 'abiertos') $where[] = "e.nombre IN ('Abierto','Nuevo','Asignado cuadrilla','En proceso cuadrilla')";

    // Recencia: rango explícito o ventana de N días sobre fecha_creacion.
    if ($p_desde) { $where[] = "t.fecha_creacion >= ?"; $params[] = $p_desde . ' 00:00:00'; }
    if ($p_hasta) { $where[] = "t.fecha_creacion <= ?"; $params[] = $p_hasta . ' 23:59:59'; }
    if (!$p_desde && !$p_hasta && $p_recencia > 0) {
        $where[] = "t.fecha_creacion >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
        $params[] = $p_recencia;
    }

    $W = implode(' AND ', $where);

    $sql = "SELECT t.ticket_id AS id,
                   t.latitud   AS lat,
                   t.longitud  AS lng,
                   t.fecha_creacion,
                   t.fecha_estimada,
                   e.nombre    AS estado,
                   e.es_resuelto,
                   CASE WHEN e.es_resuelto = 0 AND t.fecha_estimada < CURDATE() THEN 1 ELSE 0 END AS vencido,
                   p.nombre    AS prioridad,
                   g.nombre    AS grupo,
                   ts.nombre   AS servicio,
                   d.nombre    AS delegacion,
                   t.colonia,
                   t.direccion,
                   DATEDIFF(CURDATE(), t.fecha_creacion) AS dias_abierto
            FROM tickets t
            LEFT JOIN cat_estado        e  ON e.id=t.estado_id
            LEFT JOIN cat_prioridad     p  ON p.id=t.prioridad_id
            LEFT JOIN cat_grupo         g  ON g.id=t.grupo_id
            LEFT JOIN cat_tipo_servicio ts ON ts.id=t.tipo_servicio_id
            LEFT JOIN cat_delegacion    d  ON d.id=t.delegacion_id
            WHERE $W";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $pool = $st->fetchAll();
    $stats_pool['candidatos'] = count($pool);

    // ---- Cuántos quedaron fuera por NO tener coords (transparencia) ----
    // Mismo WHERE pero sin la restricción de lat/lng
    $where_sin_geo = array_filter($where, fn($w) => stripos($w,'latitud')===false && stripos($w,'longitud')===false);
    $sql_total = "SELECT COUNT(*) FROM tickets t
                  LEFT JOIN cat_estado e ON e.id=t.estado_id
                  WHERE " . implode(' AND ', $where_sin_geo);
    $st2 = $pdo->prepare($sql_total);
    $st2->execute($params);
    $stats_pool['total_filtros']  = (int)$st2->fetchColumn();
    $stats_pool['sin_coords']     = $stats_pool['total_filtros'] - $stats_pool['candidatos'];

    // Normaliza coordenadas (las usa el mapa de dibujo y el ruteo)
    foreach ($pool as &$t) { $t['lat'] = (float)$t['lat']; $t['lng'] = (float)$t['lng']; }
    unset($t);

    // Modo mapa con polígono: nos quedamos SOLO con lo de dentro (lo de fuera se ignora)
    if ($modo_mapa && $poligono) {
        $fuera = count($pool);
        $pool  = array_values(array_filter($pool, fn($t) => punto_en_poligono($t['lat'], $t['lng'], $poligono)));
        $stats_pool['en_poligono'] = count($pool);
        $stats_pool['fuera_poligono'] = $fuera - count($pool);
        $stats_pool['candidatos'] = count($pool);   // dentro de la zona = universo a rutear
    }

if (!$mapa_dibujar):   // en fase de dibujo no se rutea todavía, solo se muestran candidatos

    // Calcular score
    foreach ($pool as &$t) {
        $score  = $p_w_antig    * (int)$t['dias_abierto'];
        $score += $p_w_vencido  * (int)$t['vencido'];
        if (in_array($t['prioridad'], ['High','Urgent'])) $score += $p_w_urgente;
        if ($p_w_geo > 0) {
            $d = haversine_km($p_lat, $p_lng, (float)$t['lat'], (float)$t['lng']);
            $score -= $p_w_geo * $d;  // más lejos = menos prioritario
        }
        $t['score'] = $score;
        $t['lat']   = (float)$t['lat'];
        $t['lng']   = (float)$t['lng'];
    }
    unset($t);

    // Ordenar por score descendente y truncar al total requerido
    usort($pool, fn($a,$b) => $b['score'] <=> $a['score']);
    $requeridos = $p_cuadrillas * $p_capacidad * $p_dias;
    $stats_pool['requeridos'] = $requeridos;
    $seleccionados = array_slice($pool, 0, $requeridos);

    // Asignar a cuadrillas
    if ($p_metodo === 'delegacion' && $p_cuadrillas <= count($cat_delegaciones)) {
        // Una cuadrilla por delegación más cargada
        $por_deleg = [];
        foreach ($seleccionados as $t) $por_deleg[$t['delegacion']][] = $t;
        uksort($por_deleg, fn($a,$b) => count($por_deleg[$b]) - count($por_deleg[$a]));
        $clusters = array_slice(array_values($por_deleg), 0, $p_cuadrillas);
        // overflow al cluster más chico
        $sobrantes = [];
        foreach (array_slice(array_values($por_deleg), $p_cuadrillas) as $sub) {
            foreach ($sub as $t) $sobrantes[] = $t;
        }
        foreach ($sobrantes as $t) {
            $minIdx = 0; $minN = INF;
            foreach ($clusters as $i=>$cl) if (count($cl)<$minN) { $minN=count($cl); $minIdx=$i; }
            $clusters[$minIdx][] = $t;
        }
        while (count($clusters) < $p_cuadrillas) $clusters[] = [];
    } elseif ($p_metodo === 'roundrobin') {
        $clusters = array_fill(0, $p_cuadrillas, []);
        foreach ($seleccionados as $i => $t) $clusters[$i % $p_cuadrillas][] = $t;
    } else {
        $clusters = kmeans($seleccionados, $p_cuadrillas);
    }

    // Balancear cuadrillas si quedaron muy desbalanceadas
    $capacidad_total = $p_capacidad * $p_dias;
    foreach ($clusters as $idx => $cl) {
        if (count($cl) > $capacidad_total) {
            // mover excedente al cluster con menos
            $excedente = array_splice($clusters[$idx], $capacidad_total);
            foreach ($excedente as $t) {
                $minIdx = 0; $minN = count($clusters[0]);
                foreach ($clusters as $j=>$other) if (count($other)<$minN) { $minN=count($other); $minIdx=$j; }
                $clusters[$minIdx][] = $t;
            }
        }
    }

    // Por cada cuadrilla, distribuir por días y ordenar la ruta del día
    $depot = ['lat'=>$p_lat, 'lng'=>$p_lng];

    // Parseo de hora inicio → segundos desde medianoche
    $hi = explode(':', $p_hora_inicio);
    $start_sec = ((int)($hi[0] ?? 8)) * 3600 + ((int)($hi[1] ?? 0)) * 60;
    $fmtT = function($s) {
        $s = (int)$s;
        return sprintf('%02d:%02d', intdiv($s, 3600) % 24, intdiv($s % 3600, 60));
    };

    $plan = [];
    foreach ($clusters as $idx => $tickets_cu) {
        if (empty($tickets_cu)) {
            $plan[] = ['cuadrilla'=>$idx+1, 'dias'=>[], 'total_tickets'=>0, 'total_km'=>0];
            continue;
        }
        usort($tickets_cu, fn($a,$b) => $b['score'] <=> $a['score']);
        $dias_plan = [];
        $rem = $tickets_cu;
        $sumKm = 0;
        for ($d = 1; $d <= $p_dias && count($rem); $d++) {
            $chunk = array_slice($rem, 0, $p_capacidad);
            $rem   = array_slice($rem, $p_capacidad);
            $ruta = ruta_nn($depot, $chunk);
            $km   = dist_total($depot, $ruta);
            $sumKm += $km;

            // ETA por ticket: inicia a hora_inicio, suma viaje + servicio
            $t = $start_sec;
            foreach ($ruta as $i => &$tk) {
                $t += ($tk['dist_prev_km'] / $p_vel_kmh) * 3600;     // viaje desde anterior
                $tk['eta_seg']     = (int)$t;
                $tk['eta_hhmm']    = $fmtT($t);
                $t += $p_min_serv * 60;                              // tiempo de servicio
                $tk['salida_seg']  = (int)$t;
                $tk['salida_hhmm'] = $fmtT($t);
            }
            unset($tk);

            // Hora estimada de regreso al depósito
            if (!empty($ruta)) {
                $ult = end($ruta);
                $regreso_km = haversine_km($ult['lat'], $ult['lng'], $depot['lat'], $depot['lng']);
                $t += ($regreso_km / $p_vel_kmh) * 3600;
            }
            $hora_fin = $fmtT($t);
            $duracion_min = (int)round(($t - $start_sec) / 60);

            $dias_plan[] = [
                'dia'         => $d,
                'tickets'     => $ruta,
                'km'          => round($km, 1),
                'hora_inicio' => $fmtT($start_sec),
                'hora_fin'    => $hora_fin,
                'duracion_min'=> $duracion_min,
            ];
        }
        $plan[] = [
            'cuadrilla'      => $idx + 1,
            'dias'           => $dias_plan,
            'total_tickets'  => count($tickets_cu) - count($rem),
            'no_atendidos'   => count($rem),
            'total_km'       => round($sumKm, 1),
        ];
    }

endif; // !$mapa_dibujar
}

// Resumen del plan
$plan_stats = ['atendidos'=>0, 'km'=>0, 'antig_media'=>0, 'vencidos'=>0];
if ($plan) {
    $sumDias = 0;
    foreach ($plan as $cu) {
        $plan_stats['atendidos'] += $cu['total_tickets'];
        $plan_stats['km']        += $cu['total_km'];
        foreach ($cu['dias'] as $dia) foreach ($dia['tickets'] as $t) {
            $sumDias += (int)$t['dias_abierto'];
            $plan_stats['vencidos'] += (int)$t['vencido'];
        }
    }
    if ($plan_stats['atendidos']) $plan_stats['antig_media'] = round($sumDias / $plan_stats['atendidos'], 1);
}

// Para el mapa
$rutas_js = [];
if ($plan) {
    foreach ($plan as $cu) {
        $coords = [['lat'=>$p_lat, 'lng'=>$p_lng, 'depot'=>true]];
        foreach ($cu['dias'] as $dia) foreach ($dia['tickets'] as $t) {
            $coords[] = [
                'lat' => $t['lat'], 'lng' => $t['lng'],
                'id'  => $t['id'], 'dia' => $dia['dia'],
                'colonia' => $t['colonia'], 'servicio'=> $t['servicio']
            ];
        }
        $rutas_js[] = $coords;
    }
}
$colores = ['#254185','#ce3a2b','#188a5b','#d99000','#7c3aed','#2a9eda','#5b667a','#84cc16','#f97316','#0ea5e9'];

// Estado del wizard: form (1) · mapa (2-dibujo) · resultado (2-3) · lista (guardados)
$vista = $vista_planes ? 'lista'
       : ($mapa_dibujar ? 'mapa'
       : (($ejecutar && $plan) ? 'resultado' : 'form'));

// Candidatos para el mapa de dibujo (ligero: solo lo que pinta el mapa)
$mapa_pool = [];
if ($vista === 'mapa') {
    foreach ($pool as $t) {
        $mapa_pool[] = ['lat'=>$t['lat'], 'lng'=>$t['lng'], 'id'=>$t['id'],
                        'v'=>(int)$t['vencido'], 'd'=>(int)$t['dias_abierto'],
                        's'=>$t['servicio'] ?? '', 'c'=>$t['colonia'] ?? ''];
    }
}

// Payload para guardar exactamente lo que se está mostrando (WYSIWYG)
$param_keys = ['grupo','tipo_servicio','delegacion','estado','recencia','desde','hasta','cuadrillas','capacidad','dias',
               'hora_inicio','min_serv','vel_kmh','lat','lng','w_antig','w_vencido','w_urgente','w_geo','metodo','poligono'];
$params_save = [];
foreach ($param_keys as $k) if (isset($_GET[$k])) $params_save[$k] = $_GET[$k];
$params_save['lat'] = $p_lat; $params_save['lng'] = $p_lng;
$payload_save = ['params'=>$params_save, 'plan'=>$plan, 'stats_pool'=>$stats_pool, 'poligono'=>$poligono];
?>
<?php
$ktTitle  = 'Planificador de cuadrillas';
$ktActive = 'zendesk';
require __DIR__ . '/../../views/layout/kt_top.php';
?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{--bg:#fafafa;--surface:#fff;--border:#ececec;--border-strong:#e0e0e0;
    --text:#1a1a1a;--text-muted:#6b7280;--text-faint:#9ca3af;
    --accent:#254185;--positive:#188a5b;--warning:#d99000;--negative:#ce3a2b;--neutral:#005ab2}
  *{box-sizing:border-box;-webkit-font-smoothing:antialiased}
  body{margin:0;font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:14px;line-height:1.5}
  .container{max-width:1400px;margin:0 auto;padding:24px 32px 80px}
  header{margin-bottom:24px;display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px}
  header h1{font-size:22px;font-weight:600;letter-spacing:-.02em;margin:0 0 6px}
  header .crumb{color:var(--text-muted);font-size:13px}
  header .crumb a{color:var(--accent);text-decoration:none}
  .nav{display:flex;gap:8px;flex-wrap:wrap}
  .nav a{font-size:12px;padding:7px 12px;border:1px solid var(--border);border-radius:7px;color:var(--text);text-decoration:none;background:#fff;font-weight:500}
  .nav a.active{background:var(--text);color:#fff;border-color:var(--text)}

  .form-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:18px}
  .form-card h3{font-size:11px;text-transform:uppercase;letter-spacing:.08em;font-weight:600;color:var(--text-faint);margin:0 0 14px}
  .grid{display:grid;gap:14px}
  .grid-3{grid-template-columns:repeat(3,1fr)}
  .grid-4{grid-template-columns:repeat(4,1fr)}
  .grid-auto{grid-template-columns:repeat(auto-fit,minmax(170px,1fr))}
  @media(max-width:780px){.grid-3,.grid-4{grid-template-columns:1fr 1fr}}

  label{display:block;font-size:11px;color:var(--text-muted);font-weight:500;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em}
  select,input[type=text],input[type=number],input[type=date]{width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:7px;font:inherit;font-size:13px;background:#fff;color:var(--text)}
  .btn-row{display:flex;justify-content:flex-end;gap:8px;margin-top:8px}
  button.primary{background:var(--accent);color:#fff;border:0;padding:10px 18px;border-radius:7px;font:inherit;font-weight:500;cursor:pointer;font-size:13px}
  button.primary:hover{filter:brightness(1.05)}
  a.btn{padding:10px 16px;border:1px solid var(--border);border-radius:7px;color:var(--text);text-decoration:none;background:#fff;font-size:13px;font-weight:500}

  .stats{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin:24px 0 18px}
  @media(max-width:780px){.stats{grid-template-columns:repeat(2,1fr)}}
  .stat{background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px}
  .stat .l{font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;font-weight:600}
  .stat .v{font-size:24px;font-weight:600;line-height:1;margin-top:6px}

  .cuadrilla-card{background:#fff;border:1px solid var(--border);border-radius:10px;margin-bottom:18px;overflow:hidden}
  .cu-header{padding:14px 20px;background:#f9fafb;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}
  .cu-title{font-weight:600;font-size:15px;display:flex;align-items:center;gap:10px}
  .cu-dot{width:14px;height:14px;border-radius:50%}
  .cu-meta{font-size:12px;color:var(--text-muted)}
  .dia-section{border-top:1px solid var(--border);padding:0}
  .dia-section:first-child{border-top:0}
  .dia-header{padding:10px 20px;background:#fff;font-size:12px;color:var(--text);font-weight:600;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border)}
  .dia-header .meta{color:var(--text-muted);font-weight:400;font-size:11px}
  table{width:100%;border-collapse:collapse;font-size:13px}
  th{font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.05em;color:var(--text-faint);padding:8px 12px;text-align:left;border-bottom:1px solid var(--border)}
  td{padding:9px 12px;border-bottom:1px solid var(--border);vertical-align:top}
  tr:last-child td{border-bottom:none}
  td.num{text-align:right;font-variant-numeric:tabular-nums;color:var(--text-muted)}
  td.code{font-family:ui-monospace,Menlo,monospace;font-size:11px;color:var(--text-muted)}
  .pill{display:inline-block;font-size:10px;padding:2px 7px;border-radius:999px;font-weight:500}
  .pill.negative{background:#fef2f2;color:#b91c1c}
  .pill.warning{background:#fffbeb;color:#b45309}
  .pill.neutral{background:#f3f4f6;color:#374151}
  .open-route{display:inline-flex;align-items:center;gap:6px;font-size:12px;padding:6px 12px;border:1px solid var(--border);border-radius:6px;color:var(--text);text-decoration:none;background:#fff;font-weight:500}
  .open-route:hover{background:#f9fafb}
  .map-wrap{height:560px;border-radius:10px;overflow:hidden;border:1px solid var(--border);background:#eef0f3;margin-top:16px}
  .map-wrap.empty{display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:13px;padding:30px}
  .help{font-size:11px;color:var(--text-faint);margin-top:6px;line-height:1.45}
  .insight{background:#eff6ff;border:1px solid #bfdbfe;border-left:3px solid var(--accent);border-radius:6px;padding:12px 16px;font-size:13px;line-height:1.55;margin:14px 0}
  .insight b{font-weight:600}
  details summary{cursor:pointer;padding:8px 0;color:var(--text-muted);font-size:12px;font-weight:500;list-style:none}
  details summary::-webkit-details-marker{display:none}
  details summary:before{content:'▸ ';display:inline-block;margin-right:4px}
  details[open] summary:before{content:'▾ '}

  /* ===== Stepper ===== */
  .stepper{display:flex;align-items:center;gap:0;margin:0 0 26px;padding:18px 22px;background:#fff;border:1px solid var(--border);border-radius:12px}
  .step{display:flex;align-items:center;gap:11px;flex:1;min-width:0}
  .step .num{flex:none;width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;background:#eef1f6;color:var(--text-faint);border:2px solid #eef1f6;transition:.2s}
  .step .txt{display:flex;flex-direction:column;line-height:1.2;min-width:0}
  .step .txt b{font-size:13px;font-weight:600;color:var(--text-faint)}
  .step .txt span{font-size:11px;color:var(--text-faint)}
  .step.active .num{background:var(--accent);color:#fff;border-color:var(--accent);box-shadow:0 0 0 4px rgba(37,65,133,.12)}
  .step.active .txt b{color:var(--text)}
  .step.active .txt span{color:var(--text-muted)}
  .step.done .num{background:var(--positive);color:#fff;border-color:var(--positive)}
  .step.done .txt b{color:var(--text)}
  .step .bar{flex:1;height:2px;background:#eef1f6;margin:0 14px;border-radius:2px}
  .step.done .bar{background:var(--positive)}
  @media(max-width:680px){.step .txt span{display:none}.step .bar{margin:0 6px}}

  /* ===== Barra de acción del paso 2 ===== */
  .action-bar{position:sticky;top:0;z-index:30;display:flex;justify-content:space-between;align-items:center;gap:12px;
    background:rgba(255,255,255,.92);backdrop-filter:blur(6px);border:1px solid var(--border);border-radius:10px;padding:12px 18px;margin-bottom:20px;flex-wrap:wrap}
  .action-bar .lead{font-size:13px;color:var(--text-muted)}
  .action-bar .lead b{color:var(--text);font-weight:600}
  .btn-ghost{padding:9px 16px;border:1px solid var(--border);border-radius:8px;color:var(--text);text-decoration:none;background:#fff;font-size:13px;font-weight:500;cursor:pointer}
  .btn-ghost:hover{background:#f9fafb}
  .btn-save{background:var(--positive);color:#fff;border:0;padding:10px 20px;border-radius:8px;font:inherit;font-weight:600;font-size:13px;cursor:pointer}
  .btn-save:hover{filter:brightness(1.05)}

  /* ===== Panel guardar (paso 3) ===== */
  .save-card{background:linear-gradient(180deg,#f0fdf4,#fff);border:1px solid #bbf7d0;border-radius:12px;padding:22px;margin:26px 0}
  .save-card h3{margin:0 0 6px;font-size:15px;font-weight:600;color:var(--text)}
  .save-card p{margin:0 0 14px;font-size:12px;color:var(--text-muted)}
  .save-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .save-row input[type=text]{flex:1;min-width:220px}
  .saved-banner{background:#ecfdf5;border:1px solid #6ee7b7;border-left:3px solid var(--positive);border-radius:8px;padding:14px 18px;margin:0 0 20px;font-size:13px;color:#065f46;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .saved-banner b{font-weight:600}

  /* ===== Lista de planes ===== */
  .plan-list{background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden}
  .plan-list table{width:100%}
  .plan-list td,.plan-list th{padding:13px 18px}
  .plan-list tr:hover td{background:#fafbfc}
  .plan-name{font-weight:600;color:var(--text);text-decoration:none}
  .plan-name:hover{color:var(--accent)}
  .mini-pill{display:inline-block;font-size:11px;padding:3px 9px;border-radius:999px;background:#eef1f6;color:#475569;font-weight:500}
  .empty-box{text-align:center;padding:60px 20px;color:var(--text-muted)}
  .empty-box .big{font-size:42px;margin-bottom:10px}

  /* ===== Campo hero (servicio) ===== */
  .hero-field label{font-size:12px;color:var(--text);font-weight:600}
  .hero-select{font-size:16px !important;padding:13px 14px !important;border-color:var(--border-strong) !important;font-weight:500}

  /* ===== Tiles de método ===== */
  .metodo-tiles{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  @media(max-width:600px){.metodo-tiles{grid-template-columns:1fr}}
  .tile{display:flex;align-items:center;gap:12px;padding:14px 16px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;transition:.15s;background:#fff}
  .tile:hover{border-color:var(--border-strong)}
  .tile.sel{border-color:var(--accent);background:#f5f8ff;box-shadow:0 0 0 3px rgba(37,65,133,.10)}
  .tile input{position:absolute;opacity:0;pointer-events:none}
  .tile-ico{font-size:24px;flex:none}
  .tile-txt{display:flex;flex-direction:column;line-height:1.25}
  .tile-txt b{font-size:14px}
  .tile-txt span{font-size:12px;color:var(--text-muted)}

  /* ===== Mapa de dibujo (paso 2 · selección) ===== */
  .draw-hint{display:flex;align-items:center;gap:12px;background:#eff6ff;border:1px solid #bfdbfe;border-left:3px solid var(--accent);border-radius:8px;padding:12px 16px;margin-bottom:14px;font-size:13px;flex-wrap:wrap}
  .draw-hint b{font-weight:600}
  .draw-toolbar{display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
  #drawmap{height:620px;border-radius:12px;overflow:hidden;border:1px solid var(--border);background:#eef0f3}
  .legend-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:5px;vertical-align:middle}
  .count-badge{display:inline-flex;align-items:center;gap:6px;background:var(--accent);color:#fff;font-weight:600;font-size:13px;padding:8px 14px;border-radius:8px}
</style>

<div class="container">

<header>
  <div>
    <h1>🚛 Planificador de cuadrillas</h1>
    <div class="crumb"><a href="dashboard.php">Dashboard</a> → Cuadrillas · genera rutas óptimas</div>
  </div>
  </header>

<?php
  // Pasos: 1 Parámetros · 2 Ruta · 3 Guardar
  $en_paso2 = in_array($vista, ['mapa','resultado'], true);
  $s1 = $vista==='form' ? 'active' : ($en_paso2 ? 'done' : '');
  $s2 = $en_paso2 ? 'active' : '';
  $s3 = $desde_guardado ? 'done' : '';
?>
<?php if ($vista !== 'lista'): ?>
<div class="stepper">
  <div class="step <?= $s1 ?>"><div class="num"><?= $en_paso2?'✓':'1' ?></div>
    <div class="txt"><b>Parámetros</b><span>Servicio, fecha y recursos</span></div></div>
  <div class="step <?= $s2 ?: ($s3?'done':'') ?>"><div class="bar"></div></div>
  <div class="step <?= $s2 ?: ($s3?'done':'') ?>"><div class="num"><?= $desde_guardado?'✓':'2' ?></div>
    <div class="txt"><b><?= $vista==='mapa'?'Selecciona zona':'Ver ruta' ?></b><span><?= $vista==='mapa'?'Dibuja el polígono':'Mapa, tablas y resumen' ?></span></div></div>
  <div class="step <?= $s3 ?>"><div class="bar"></div></div>
  <div class="step <?= $s3 ?>"><div class="num"><?= $desde_guardado?'✓':'3' ?></div>
    <div class="txt"><b>Guardar</b><span>Nombrar y reabrir luego</span></div></div>
</div>
<?php endif; ?>

<?php
// ===================== VISTA: LISTA DE PLANES GUARDADOS =====================
if ($vista === 'lista'):
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
  <h2 style="font-size:16px;font-weight:600;margin:0">Planes guardados</h2>
  <a class="btn" href="cuadrillas.php">+ Nuevo plan</a>
</div>
<?php if (empty($listaPlanes)): ?>
  <div class="plan-list"><div class="empty-box">
    <div class="big">🗂️</div>
    Aún no tienes planes guardados.<br>
    <a class="plan-name" href="cuadrillas.php">Genera tu primer plan →</a>
  </div></div>
<?php else: ?>
  <div class="plan-list">
    <table>
      <thead><tr>
        <th>Plan</th><th class="num">Cuadrillas</th><th class="num">Tickets</th>
        <th class="num">Km</th><th>Creado</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($listaPlanes as $pl): ?>
        <tr>
          <td><a class="plan-name" href="cuadrillas.php?plan_id=<?= (int)$pl['id'] ?>"><?= htmlspecialchars($pl['nombre']) ?></a></td>
          <td class="num"><span class="mini-pill"><?= (int)$pl['n_cuadrillas'] ?></span></td>
          <td class="num"><?= number_format((int)$pl['n_tickets']) ?></td>
          <td class="num"><?= number_format((float)$pl['km'],1) ?></td>
          <td style="color:var(--text-muted);font-size:12px"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($pl['creado_en']))) ?></td>
          <td style="text-align:right;white-space:nowrap">
            <a class="open-route" href="cuadrillas.php?plan_id=<?= (int)$pl['id'] ?>" style="display:inline-flex">Abrir →</a>
            <?php
              $puedeBorrar = (function_exists('puede_editar') && puede_editar('zendesk'))
                          || ((int)($pl['usuario_id'] ?? 0) === (int)(Auth::user()['id'] ?? 0) && (int)($pl['usuario_id'] ?? 0) > 0);
            ?>
            <?php if ($puedeBorrar): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar este plan guardado?')">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="eliminar_plan">
              <input type="hidden" name="id" value="<?= (int)$pl['id'] ?>">
              <button class="btn-ghost" style="color:var(--negative);border-color:#fecaca;cursor:pointer">Eliminar</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</div>
</body>
</html>
<?php
  return; // fin de la vista lista
endif;
?>

<?php if ($vista === 'form'): ?>
<form method="get">
  <input type="hidden" name="plan" value="1">

  <!-- ============= 1. SERVICIO (decisión principal) ============= -->
  <div class="form-card">
    <h3>1 · ¿Qué servicio van a atender?</h3>
    <div class="hero-field">
      <label>Tipo de servicio</label>
      <select name="tipo_servicio" class="hero-select">
        <option value="">— Todos los servicios —</option>
        <?php foreach ($cat_servicios as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $p_servicio==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="help">Cada cuadrilla atiende un tipo distinto (tiliches, bacheo, poda…). Elige primero el servicio; los demás filtros lo acotan.</div>
    </div>
    <div class="grid grid-3" style="margin-top:14px">
      <div>
        <label>Grupo de servicio</label>
        <select name="grupo">
          <option value="">— Todos —</option>
          <?php foreach ($cat_grupos as $g): ?>
            <option value="<?= $g['id'] ?>" <?= $p_grupo==$g['id']?'selected':'' ?>><?= htmlspecialchars($g['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Delegación</label>
        <select name="delegacion">
          <option value="">— Todas —</option>
          <?php foreach ($cat_delegaciones as $d): ?>
            <option value="<?= $d['id'] ?>" <?= $p_delegacion==$d['id']?'selected':'' ?>><?= htmlspecialchars($d['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Estado</label>
        <select name="estado">
          <option value="sin_resolver" <?= $p_estado==='sin_resolver'?'selected':'' ?>>Sin resolver (todos)</option>
          <option value="vencidos"     <?= $p_estado==='vencidos'?'selected':'' ?>>Solo vencidos</option>
          <option value="abiertos"     <?= $p_estado==='abiertos'?'selected':'' ?>>Abiertos / asignados</option>
        </select>
      </div>
    </div>
  </div>

  <!-- ============= 1b. RECENCIA DEL REPORTE ============= -->
  <div class="form-card">
    <h3>2 · ¿Qué tan recientes?</h3>
    <div class="grid grid-3">
      <div>
        <label>Reportados en los últimos (días)</label>
        <input type="number" name="recencia" min="0" max="365" value="<?= (int)$p_recencia ?>">
        <div class="help">0 = sin límite. Útil porque un reporte viejo (p. ej. tras lluvia) puede ya no existir.</div>
      </div>
      <div>
        <label>O desde una fecha</label>
        <input type="date" name="desde" value="<?= htmlspecialchars($p_desde) ?>">
      </div>
      <div>
        <label>… hasta</label>
        <input type="date" name="hasta" value="<?= htmlspecialchars($p_hasta) ?>">
      </div>
    </div>
    <div class="help">Si capturas un rango de fechas, manda el rango y se ignora la ventana de días. Solo entran tickets con coordenadas; los demás se omiten.</div>
  </div>

  <!-- ============= 2. RECURSOS ============= -->
  <div class="form-card">
    <h3>3 · Recursos disponibles</h3>
    <div class="grid grid-3">
      <div>
        <label>Cuadrillas</label>
        <input type="number" name="cuadrillas" min="1" max="10" value="<?= $p_cuadrillas ?>">
      </div>
      <div>
        <label>Capacidad por cuadrilla / día (tickets)</label>
        <input type="number" name="capacidad" min="1" max="200" value="<?= $p_capacidad ?>">
      </div>
      <div>
        <label>Días a planear</label>
        <input type="number" name="dias" min="1" max="14" value="<?= $p_dias ?>">
      </div>
    </div>
    <div class="help">Capacidad total = <b><?= $p_cuadrillas * $p_capacidad * $p_dias ?></b> tickets en el horizonte.</div>

    <div class="grid grid-3" style="margin-top:14px">
      <div>
        <label>Hora de inicio de jornada</label>
        <input type="time" name="hora_inicio" value="<?= htmlspecialchars($p_hora_inicio) ?>">
      </div>
      <div>
        <label>Min. de servicio por ticket</label>
        <input type="number" name="min_serv" min="0" max="240" value="<?= $p_min_serv ?>">
        <div class="help">tiempo estimado atendiendo cada reporte</div>
      </div>
      <div>
        <label>Velocidad promedio (km/h)</label>
        <input type="number" name="vel_kmh" min="5" max="120" value="<?= $p_vel_kmh ?>">
        <div class="help">25 km/h es típico urbano</div>
      </div>
    </div>
  </div>

  <!-- ============= 4. PRIORIDAD ============= -->
  <div class="form-card">
    <h3>4 · Cómo priorizar los tickets</h3>
    <div class="grid grid-4">
      <div>
        <label>Peso por día abierto</label>
        <input type="number" name="w_antig" step="0.1" min="0" value="<?= $p_w_antig ?>">
        <div class="help">+puntos por cada día que lleva abierto</div>
      </div>
      <div>
        <label>Bonus si está vencido</label>
        <input type="number" name="w_vencido" step="0.1" min="0" value="<?= $p_w_vencido ?>">
      </div>
      <div>
        <label>Bonus prioridad alta/urgente</label>
        <input type="number" name="w_urgente" step="0.1" min="0" value="<?= $p_w_urgente ?>">
      </div>
      <div>
        <label>Penalización por distancia (km)</label>
        <input type="number" name="w_geo" step="0.1" min="0" value="<?= $p_w_geo ?>">
        <div class="help">0 = no penaliza distancia al punto de partida</div>
      </div>
    </div>
    <div class="help">El score se calcula como: <code>w_antig × días_abiertos + w_vencido × (vencido) + w_urgente × (prioridad alta) − w_geo × km_desde_depósito</code></div>
  </div>

  <!-- ============= 5. MÉTODO Y PUNTO DE PARTIDA ============= -->
  <div class="form-card">
    <h3>5 · ¿Cómo armamos la ruta?</h3>
    <div class="metodo-tiles">
      <label class="tile <?= $p_metodo!=='mapa'?'sel':'' ?>">
        <input type="radio" name="metodo_modo" value="auto" <?= $p_metodo!=='mapa'?'checked':'' ?>>
        <div class="tile-ico">🤖</div>
        <div class="tile-txt"><b>Recomendado</b><span>El sistema agrupa y prioriza por ti</span></div>
      </label>
      <label class="tile <?= $p_metodo==='mapa'?'sel':'' ?>">
        <input type="radio" name="metodo_modo" value="mapa" <?= $p_metodo==='mapa'?'checked':'' ?>>
        <div class="tile-ico">🗺️</div>
        <div class="tile-txt"><b>Selección en mapa</b><span>Dibujas el polígono de la zona</span></div>
      </label>
    </div>

    <div class="grid grid-4" style="margin-top:16px">
      <div id="metodo-auto-wrap" style="grid-column:span 2">
        <label>Estrategia (modo recomendado)</label>
        <select name="metodo_auto">
          <option value="kmeans"     <?= $p_metodo==='kmeans'?'selected':'' ?>>K-means geográfico (recomendado)</option>
          <option value="delegacion" <?= $p_metodo==='delegacion'?'selected':'' ?>>Por delegación (una cuadrilla = una delegación)</option>
          <option value="roundrobin" <?= $p_metodo==='roundrobin'?'selected':'' ?>>Round-robin por score (ignora geografía)</option>
        </select>
        <div class="help"><b>K-means</b> agrupa por zonas; <b>Delegación</b> respeta límites; <b>Round-robin</b> reparte por prioridad. En <b>Selección en mapa</b> se usa K-means dentro de tu polígono.</div>
      </div>
      <div>
        <label>Lat punto de partida</label>
        <input type="text" name="lat" value="<?= $p_lat ?>">
      </div>
      <div>
        <label>Lng punto de partida</label>
        <input type="text" name="lng" value="<?= $p_lng ?>">
      </div>
    </div>
    <!-- método real que viaja al servidor -->
    <input type="hidden" name="metodo" id="metodo-real" value="<?= htmlspecialchars($p_metodo) ?>">
  </div>

  <div class="btn-row">
    <a class="btn" href="cuadrillas.php">Limpiar</a>
    <button type="submit" class="primary" id="btn-generar"><?= $p_metodo==='mapa'?'Ir al mapa →':'Generar plan →' ?></button>
  </div>
</form>
<script>
(function(){
  const radios = document.querySelectorAll('input[name="metodo_modo"]');
  const real   = document.getElementById('metodo-real');
  const autoSel= document.querySelector('select[name="metodo_auto"]');
  const autoWrap = document.getElementById('metodo-auto-wrap');
  const btn    = document.getElementById('btn-generar');
  function sync(){
    const modo = document.querySelector('input[name="metodo_modo"]:checked').value;
    if (modo === 'mapa') { real.value = 'mapa'; btn.textContent = 'Ir al mapa →'; autoWrap.style.opacity = .45; }
    else { real.value = autoSel.value; btn.textContent = 'Generar plan →'; autoWrap.style.opacity = 1; }
    document.querySelectorAll('.metodo-tiles .tile').forEach(t=>t.classList.toggle('sel', t.querySelector('input').checked));
  }
  radios.forEach(r=>r.addEventListener('change', sync));
  autoSel.addEventListener('change', sync);
  sync();
})();
</script>
<?php endif; /* fin vista form */ ?>

<?php if ($vista === 'mapa'): ?>
  <!-- ============= PASO 2 · SELECCIÓN POR POLÍGONO ============= -->
  <div class="draw-hint">
    🗺️ <b>Dibuja la zona:</b> pulsa <b>✏️ Dibujar zona</b>, haz clic en el mapa para marcar cada vértice y cierra con <b>doble clic</b> o el botón <b>✓ Cerrar zona</b>.
    Solo se ruteará lo que quede <b>dentro</b>; el resto se ignora.
  </div>

  <?php if (empty($api_key)): ?>
    <div class="map-wrap empty">Configura <code>google_maps_api_key</code> en <code>config.php</code> para usar el modo mapa.</div>
  <?php elseif (empty($mapa_pool)): ?>
    <div class="insight">No hay candidatos con coordenadas para estos filtros. Cambia el servicio, el estado o amplía la recencia.</div>
    <div class="btn-row"><a class="btn" href="cuadrillas.php?<?= htmlspecialchars(http_build_query(array_diff_key($_GET, ['plan'=>1,'poligono'=>1]))) ?>">← Ajustar parámetros</a></div>
  <?php else: ?>
    <div class="draw-toolbar">
      <span class="count-badge">📍 <span id="cnt-total"><?= count($mapa_pool) ?></span> candidatos</span>
      <span class="count-badge" style="background:var(--positive)">✓ <span id="cnt-sel">0</span> dentro del polígono</span>
      <button type="button" class="btn-ghost" id="btn-poly" onclick="startDraw()">✏️ Dibujar zona</button>
      <button type="button" class="btn-save" id="btn-finish" onclick="finishDraw()" style="display:none;padding:7px 14px">✓ Cerrar zona</button>
      <button type="button" class="btn-ghost" id="btn-cancel" onclick="cancelDraw()" style="display:none">Cancelar</button>
      <button type="button" class="btn-ghost" id="btn-clear" onclick="clearDraw()" style="display:none">✖ Borrar zona</button>
      <span style="margin-left:auto;font-size:12px;color:var(--text-muted)">
        <span class="legend-dot" style="background:#ce3a2b"></span>vencido
        <span class="legend-dot" style="background:#2a9eda;margin-left:10px"></span>en plazo
      </span>
    </div>
    <div id="drawmap"></div>

    <form method="get" id="form-poly">
      <?php foreach ($param_keys as $k): if ($k==='poligono') continue; ?>
        <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars((string)($_GET[$k] ?? '')) ?>">
      <?php endforeach; ?>
      <input type="hidden" name="plan" value="1">
      <input type="hidden" name="poligono" id="poligono-field" value="">
      <div class="btn-row" style="margin-top:16px">
        <a class="btn" href="cuadrillas.php?<?= htmlspecialchars(http_build_query(array_diff_key($_GET, ['plan'=>1,'poligono'=>1]))) ?>">← Ajustar parámetros</a>
        <button type="submit" class="btn-save" id="btn-rutear" disabled style="opacity:.5">Generar ruta con esta zona →</button>
      </div>
    </form>

    <script src="https://unpkg.com/deck.gl@8.9.35/dist.min.js"></script>
    <script>
      const CAND  = <?= json_encode($mapa_pool, JSON_UNESCAPED_UNICODE) ?>;
      const DEPOT = { lat: <?= $p_lat ?>, lng: <?= $p_lng ?> };
      // DrawingManager se removió en Maps JS 3.65 → dibujamos el polígono a mano (clics).
      let dmap, overlay = null, polygon = null;
      let drawing = false, tempPath = [], tempLine = null, vMarkers = [];
      const POLY_STYLE = { fillColor:'#254185', fillOpacity:.12, strokeColor:'#254185', strokeWeight:2 };

      const $ = id => document.getElementById(id);
      function pointInPoly(lat, lng, path){
        let inside = false;
        for (let i=0, j=path.length-1; i<path.length; j=i++){
          const yi=path[i].lat, xi=path[i].lng, yj=path[j].lat, xj=path[j].lng;
          const hit = ((yi>lat)!==(yj>lat)) && (lng < (xj-xi)*(lat-yi)/((yj-yi)||1e-12)+xi);
          if (hit) inside=!inside;
        }
        return inside;
      }

      function refreshCount(){
        const f = $('poligono-field'), btn = $('btn-rutear');
        if (!polygon){ $('cnt-sel').textContent='0'; f.value=''; btn.disabled=true; btn.style.opacity=.5; return; }
        const path = polygon.getPath().getArray().map(p=>({lat:p.lat(), lng:p.lng()}));
        let n=0; CAND.forEach(c=>{ if (pointInPoly(c.lat, c.lng, path)) n++; });
        $('cnt-sel').textContent = n;
        f.value = JSON.stringify(path);
        btn.disabled = (n===0); btn.style.opacity = n===0 ? .5 : 1;
      }

      function toolbar(state){ // 'idle' | 'drawing' | 'done'
        $('btn-poly').style.display   = state==='idle'    ? '' : 'none';
        $('btn-finish').style.display = state==='drawing' ? '' : 'none';
        $('btn-cancel').style.display = state==='drawing' ? '' : 'none';
        $('btn-clear').style.display  = state==='done'    ? '' : 'none';
        if (dmap) dmap.setOptions({ draggableCursor: state==='drawing' ? 'crosshair' : null });
      }

      function clearTemp(){
        if (tempLine){ tempLine.setMap(null); tempLine=null; }
        vMarkers.forEach(m=>m.setMap(null)); vMarkers=[];
        tempPath = [];
      }
      function redrawTemp(){
        const pts = tempPath.map(p=>({lat:p.lat, lng:p.lng}));
        if (!tempLine){
          tempLine = new google.maps.Polyline(Object.assign({ map:dmap, path:pts }, POLY_STYLE, { strokeOpacity:.9 }));
        } else tempLine.setPath(pts);
      }

      function startDraw(){
        clearDraw();
        drawing = true; tempPath = [];
        toolbar('drawing');
      }
      function cancelDraw(){
        drawing = false; clearTemp(); toolbar(polygon ? 'done' : 'idle');
      }
      function finishDraw(){
        if (tempPath.length < 3){ alert('Marca al menos 3 puntos para cerrar la zona.'); return; }
        drawing = false;
        const path = tempPath.slice();
        clearTemp();
        polygon = new google.maps.Polygon(Object.assign({ map:dmap, path, editable:true, zIndex:5 }, POLY_STYLE));
        ['set_at','insert_at','remove_at'].forEach(ev=>{
          google.maps.event.addListener(polygon.getPath(), ev, refreshCount);
        });
        toolbar('done');
        refreshCount();
      }
      function clearDraw(){
        if (polygon){ polygon.setMap(null); polygon=null; }
        clearTemp(); drawing=false;
        toolbar('idle'); refreshCount();
      }

      function initDrawMap(){
        const bounds = new google.maps.LatLngBounds();
        dmap = new google.maps.Map($('drawmap'), {
          center: DEPOT, zoom: 12, mapTypeControl:true, streetViewControl:false,
          disableDoubleClickZoom:true,
          styles:[{featureType:'poi', elementType:'labels', stylers:[{visibility:'off'}]}]
        });

        if (typeof deck !== 'undefined' && deck.GoogleMapsOverlay){
          overlay = new deck.GoogleMapsOverlay({ layers: [
            new deck.HeatmapLayer({ id:'heat', data:CAND, getPosition:d=>[d.lng,d.lat], radiusPixels:45, intensity:1, opacity:.45 }),
            new deck.ScatterplotLayer({ id:'pts', data:CAND, getPosition:d=>[d.lng,d.lat],
              getFillColor:d=> d.v ? [206,58,43] : [42,158,218], getRadius:38, radiusMinPixels:3, radiusMaxPixels:7,
              stroked:true, getLineColor:[255,255,255], lineWidthMinPixels:1 })
          ]});
          overlay.setMap(dmap);
        }
        CAND.forEach(c=> bounds.extend({lat:c.lat, lng:c.lng}));
        if (!bounds.isEmpty()) dmap.fitBounds(bounds, 40);

        // Cada clic agrega un vértice mientras dibujas
        dmap.addListener('click', e=>{
          if (!drawing) return;
          tempPath.push({ lat:e.latLng.lat(), lng:e.latLng.lng() });
          const m = new google.maps.Marker({ position:e.latLng, map:dmap,
            icon:{ path:google.maps.SymbolPath.CIRCLE, scale:4, fillColor:'#254185', fillOpacity:1, strokeColor:'#fff', strokeWeight:1.5 } });
          vMarkers.push(m);
          redrawTemp();
        });
        // Doble clic cierra la zona
        dmap.addListener('dblclick', e=>{ if (drawing){ finishDraw(); } });
      }
      window.initDrawMap = initDrawMap;
    </script>
    <script async defer src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($api_key) ?>&loading=async&v=weekly&callback=initDrawMap"></script>
  <?php endif; ?>
<?php endif; /* fin vista mapa */ ?>

<?php if ($vista === 'resultado'): ?>
  <!-- ============= BARRA DE ACCIÓN (PASO 2) ============= -->
  <?php if ($desde_guardado): ?>
    <div class="saved-banner">
      ✅ <b>Plan guardado:</b> «<?= htmlspecialchars($plan_nombre) ?>». Estás viendo la versión guardada.
      <a class="open-route" href="cuadrillas.php" style="margin-left:auto">+ Crear otro plan</a>
    </div>
  <?php elseif (isset($_GET['guardado'])): ?>
    <div class="saved-banner">✅ <b>¡Plan guardado!</b> Ya puedes reabrirlo desde «Planes guardados».</div>
  <?php endif; ?>

  <div class="action-bar">
    <div class="lead">
      <b><?= number_format($plan_stats['atendidos']) ?></b> tickets ruteados ·
      <b><?= count(array_filter($plan, fn($c)=>$c['total_tickets']>0)) ?></b> cuadrillas ·
      <b><?= $plan_stats['km'] ?></b> km
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <a class="btn-ghost" href="cuadrillas.php?<?= htmlspecialchars(http_build_query(array_diff_key($_GET, ['plan'=>1,'plan_id'=>1,'guardado'=>1]))) ?>">← Ajustar parámetros</a>
      <?php if (!$desde_guardado): ?>
        <button type="button" class="btn-save" onclick="document.getElementById('guardar').scrollIntoView({behavior:'smooth'});document.getElementById('nombre_plan').focus()">💾 Guardar plan</button>
      <?php endif; ?>
    </div>
  </div>

  <!-- ============= STATS DEL PLAN ============= -->
  <div class="stats">
    <div class="stat">
      <div class="l">Candidatos con geo</div>
      <div class="v"><?= number_format($stats_pool['candidatos']) ?></div>
      <?php if (!empty($stats_pool['sin_coords'])): ?>
        <div style="font-size:11px;color:var(--text-faint);margin-top:4px"><?= number_format($stats_pool['sin_coords']) ?> sin coords (excluidos)</div>
      <?php endif; ?>
    </div>
    <div class="stat"><div class="l">Atendidos</div><div class="v" style="color:var(--positive)"><?= number_format($plan_stats['atendidos']) ?></div></div>
    <div class="stat"><div class="l">Vencidos en el plan</div><div class="v" style="color:var(--negative)"><?= number_format($plan_stats['vencidos']) ?></div></div>
    <div class="stat"><div class="l">Km totales</div><div class="v"><?= $plan_stats['km'] ?></div></div>
    <div class="stat"><div class="l">Antigüedad media</div><div class="v"><?= $plan_stats['antig_media'] ?><span style="font-size:14px;color:var(--text-muted)"> d</span></div></div>
  </div>

  <?php
    $no_alcanza = $stats_pool['candidatos'] - $plan_stats['atendidos'];
    if ($no_alcanza > 0):
  ?>
    <div class="insight">
      ⚠️ Hay <b><?= number_format($no_alcanza) ?></b> tickets que no entran en el plan por capacidad. Aumenta el número de cuadrillas, capacidad diaria o días para cubrirlos.
    </div>
  <?php endif; ?>

  <?php if (!empty($stats_pool['sin_coords'])):
    $pct_sin = round($stats_pool['sin_coords'] / max(1,$stats_pool['total_filtros']) * 100, 1);
  ?>
    <div class="insight" style="background:#fffbeb;border-color:#fcd34d;border-left-color:var(--warning)">
      📍 Hay <b><?= number_format($stats_pool['sin_coords']) ?></b> tickets adicionales que cumplen los filtros pero <b>no tienen coordenadas</b> (<?= $pct_sin ?>% del subconjunto). No se pueden rutear automáticamente sin geocodificarlos primero.
    </div>
  <?php endif; ?>

  <!-- ============= CUADRILLAS ============= -->
  <?php foreach ($plan as $idx => $cu):
    $color = $colores[$idx % count($colores)];
    if ($cu['total_tickets'] === 0) continue;
  ?>
    <div class="cuadrilla-card">
      <div class="cu-header">
        <div class="cu-title">
          <span class="cu-dot" style="background:<?= $color ?>"></span>
          Cuadrilla <?= $cu['cuadrilla'] ?>
        </div>
        <div class="cu-meta">
          <?= number_format($cu['total_tickets']) ?> tickets · <?= $cu['total_km'] ?> km · <?= count($cu['dias']) ?> días
          <?php if (!empty($cu['no_atendidos'])): ?>
            · <span class="pill warning"><?= $cu['no_atendidos'] ?> no atendidos</span>
          <?php endif; ?>
        </div>
      </div>
      <?php foreach ($cu['dias'] as $dia_idx => $dia):
        // URL de Google Maps con waypoints (navegación real)
        $origin = "{$p_lat},{$p_lng}";
        $puntos = array_map(fn($t)=>"{$t['lat']},{$t['lng']}", $dia['tickets']);
        $waypoints = array_slice($puntos, 0, -1);
        $destination = end($puntos);
        $url_gmaps = "https://www.google.com/maps/dir/?api=1"
             . "&origin=" . urlencode($origin)
             . "&destination=" . urlencode($destination)
             . ($waypoints ? "&waypoints=" . urlencode(implode('|', array_slice($waypoints, 0, 9))) : "")
             . "&travelmode=driving";

        // URL de la simulación animada (pasamos todo en query string)
        $url_anim = 'ruta_animada.php?' . http_build_query(array_merge($_GET, [
            'cuadrilla_idx' => $idx + 1,
            'dia_idx'       => $dia_idx + 1,
        ]));
        // URL de la hoja imprimible (mismos params)
        $url_print = 'hoja_ruta.php?' . http_build_query(array_merge($_GET, [
            'cuadrilla_idx' => $idx + 1,
            'dia_idx'       => $dia_idx + 1,
        ]));
      ?>
        <div class="dia-section">
          <div class="dia-header">
            <div>
              <b>Día <?= $dia['dia'] ?></b> ·
              <?= count($dia['tickets']) ?> tickets ·
              <span style="color:var(--text-muted);font-weight:400"><?= $dia['hora_inicio'] ?> → <?= $dia['hora_fin'] ?> · <?= floor($dia['duracion_min']/60) ?>h <?= $dia['duracion_min']%60 ?>m</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
              <span class="meta"><?= $dia['km'] ?> km</span>
              <a class="open-route" href="<?= htmlspecialchars($url_anim) ?>" target="_blank" style="background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe">▶ Simular</a>
              <a class="open-route" href="<?= htmlspecialchars($url_print) ?>" target="_blank" style="background:#f0fdf4;color:#15803d;border-color:#bbf7d0">🖨️ Hoja imprimible</a>
              <a class="open-route" href="<?= htmlspecialchars($url_gmaps) ?>" target="_blank">📍 Google Maps</a>
            </div>
          </div>
          <table>
            <thead><tr>
              <th style="width:40px">#</th>
              <th style="width:80px">Ticket</th>
              <th style="width:90px">Hora ETA</th>
              <th>Servicio</th>
              <th>Delegación / Colonia</th>
              <th>Dirección</th>
              <th class="num">Días abierto</th>
              <th class="num">→ km</th>
              <th></th>
            </tr></thead>
            <tbody>
              <?php foreach ($dia['tickets'] as $i => $t): ?>
                <tr>
                  <td class="code"><?= $i + 1 ?></td>
                  <td class="code">#<?= $t['id'] ?></td>
                  <td class="code" style="font-weight:600;color:var(--text)"><?= $t['eta_hhmm'] ?? '—' ?></td>
                  <td><?= htmlspecialchars(mb_strimwidth($t['servicio']??'—',0,30,'…')) ?></td>
                  <td>
                    <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($t['delegacion']??'—') ?></div>
                    <div><?= htmlspecialchars(mb_strimwidth($t['colonia']??'—',0,30,'…')) ?></div>
                  </td>
                  <td style="font-size:12px;max-width:260px"><?= htmlspecialchars(mb_strimwidth($t['direccion']??'—',0,80,'…')) ?></td>
                  <td class="num">
                    <?= $t['dias_abierto'] ?>
                    <?php if ($t['vencido']): ?><br><span class="pill negative">vencido</span><?php endif; ?>
                  </td>
                  <td class="num"><?= $t['dist_prev_km'] ?? '—' ?></td>
                  <td><a class="open-route" href="https://www.google.com/maps?q=<?= $t['lat'] ?>,<?= $t['lng'] ?>" target="_blank" title="Ver ubicación">📍</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <!-- ============= MAPA DE RUTAS ============= -->
  <?php if (!empty($api_key)): ?>
    <h3 style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--text-faint);margin:30px 0 10px">Mapa de rutas</h3>
    <div id="map" class="map-wrap"></div>
  <?php else: ?>
    <div class="map-wrap empty">
      Configura tu <code>google_maps_api_key</code> en <code>config.php</code> para ver el mapa interactivo de rutas.
    </div>
  <?php endif; ?>

  <!-- ============= PASO 3 · GUARDAR PLAN ============= -->
  <?php if (!$desde_guardado): ?>
    <div class="save-card" id="guardar">
      <h3>💾 Guardar este plan</h3>
      <p>Se guarda la asignación completa (cuadrillas, días, tickets y orden de ruta) para reabrirla cuando quieras, ver el mapa o navegarla.</p>
      <?php if ($save_error): ?>
        <div class="insight" style="background:#fef2f2;border-color:#fecaca;border-left-color:var(--negative)">No se pudo guardar: <?= htmlspecialchars($save_error) ?></div>
      <?php endif; ?>
      <form method="post" class="save-row">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar_plan">
        <input type="hidden" name="payload" value="<?= htmlspecialchars(json_encode($payload_save, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
        <input type="text" name="nombre" id="nombre_plan" maxlength="160"
               placeholder="Nombre del plan (ej. Semana 24 · bacheo norte)"
               value="<?= htmlspecialchars('Plan ' . date('d/m/Y')) ?>">
        <button type="submit" class="btn-save">Guardar plan</button>
        <a class="btn-ghost" href="cuadrillas.php?planes=1">Ver planes guardados</a>
      </form>
    </div>
  <?php endif; ?>

<?php elseif ($ejecutar && empty($plan)): ?>
  <div class="insight">No hay tickets con coordenadas que cumplan estos filtros. Ajusta los criterios y vuelve a generar.</div>
<?php endif; ?>

</div>

<?php if ($ejecutar && $plan && !empty($api_key)): ?>
<script>
const RUTAS  = <?= json_encode($rutas_js, JSON_UNESCAPED_UNICODE) ?>;
const COLORS = <?= json_encode($colores) ?>;
const CENTRO = { lat: <?= $p_lat ?>, lng: <?= $p_lng ?> };
const COLORES_DIA = ['#1e40af','#005ab2','#8b5cf6','#a855f7','#d946ef','#ec4899','#f43f5e','#ce3a2b','#ea580c','#d99000','#ca8a04','#65a30d','#16a34a','#2a9eda'];

function initMap() {
  const map = new google.maps.Map(document.getElementById('map'), {
    center: CENTRO, zoom: 12,
    mapTypeControl: true, streetViewControl: false, fullscreenControl: true,
    styles: [{featureType:'poi', elementType:'labels', stylers:[{visibility:'off'}]}]
  });

  // Punto de partida
  new google.maps.Marker({
    position: CENTRO, map,
    icon: { path: google.maps.SymbolPath.CIRCLE, scale: 9, fillColor:'#111', fillOpacity:1, strokeColor:'#fff', strokeWeight:2 },
    title: 'Punto de partida',
    zIndex: 1000
  });

  const bounds = new google.maps.LatLngBounds(CENTRO);
  const infoWin = new google.maps.InfoWindow();

  RUTAS.forEach((ruta, idx) => {
    const color = COLORS[idx % COLORS.length];
    // Trazar polilínea para cada cuadrilla (concatenando todos los días)
    const path = ruta.map(p => ({ lat: p.lat, lng: p.lng }));
    new google.maps.Polyline({
      path, geodesic:true, strokeColor: color, strokeOpacity: 0.85, strokeWeight: 3, map
    });
    // Marcadores numerados por orden de visita
    let ordenGlobal = 0;
    ruta.forEach((p, j) => {
      if (p.depot) return;
      ordenGlobal++;
      const m = new google.maps.Marker({
        position: { lat: p.lat, lng: p.lng },
        map,
        label: { text: String(ordenGlobal), color:'#fff', fontSize:'10px', fontWeight:'600' },
        icon: {
          path: google.maps.SymbolPath.CIRCLE, scale: 10,
          fillColor: color, fillOpacity: 1, strokeColor:'#fff', strokeWeight:2
        },
        title: `#${p.id} · día ${p.dia}`
      });
      m.addListener('click', () => {
        infoWin.setContent(`
          <div style="font-family:Inter,system-ui;font-size:13px;max-width:240px">
            <b>Cuadrilla ${idx+1} · día ${p.dia}</b><br>
            Ticket #${p.id}<br>
            ${p.servicio || ''}<br>
            <span style="color:#6b7280">${p.colonia || ''}</span>
          </div>`);
        infoWin.open(map, m);
      });
      bounds.extend(m.getPosition());
    });
  });

  if (!bounds.isEmpty()) map.fitBounds(bounds, 60);
}
</script>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($api_key) ?>&callback=initMap"></script>
<?php endif; ?>

<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
