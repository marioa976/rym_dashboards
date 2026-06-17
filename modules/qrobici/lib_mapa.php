<?php
/**
 * QroBici Analytics — Librería del mapa animado
 * ------------------------------------------------------------
 * Carga los viajes del "día representativo" (último día con
 * datos en la vista `viajes`), decodifica sus polilíneas GPS,
 * las simplifica con Douglas-Peucker y devuelve un payload
 * compacto listo para alimentar la animación de partículas.
 *
 * Las claves del payload se acortan adrede para reducir el
 * tamaño del JSON embebido en el HTML (puede haber miles
 * de viajes con muchos puntos):
 *   m  → minuto de inicio dentro del día (0..1439)
 *   d  → duración en minutos
 *   t  → tipo de bici: 'M' (mecánica) | 'E' (eléctrica)
 *   p  → polilínea [[lat,lng], ...]
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib_polyline.php';

/* ============================================================
   DETECCIÓN DEL DÍA REPRESENTATIVO
   ============================================================ */

/**
 * Devuelve la fecha (YYYY-MM-DD) del día más reciente que tenga
 * viajes en la vista, respetando el rango opcional fecha_desde /
 * fecha_hasta del config. Devuelve null si no hay datos.
 */
function qrb_mapa_ultimo_dia(array $cfg): ?string
{
    $pdo   = qrb_db();
    $vista = $cfg['vistas']['viajes'];
    [$wfe, $params] = qrb_where_fecha('FECHA', $cfg['fecha_desde'], $cfg['fecha_hasta']);
    $sql = "SELECT DATE(MAX(FECHA)) AS dia
            FROM `$vista`
            WHERE 1=1 $wfe";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row && $row['dia'] ? (string)$row['dia'] : null;
}

/**
 * Devuelve los días (YYYY-MM-DD) con al menos un viaje con
 * recorrido GPS, ordenados de más reciente a más antiguo.
 * Limitado a $limite días para no saturar el JSON.
 */
function qrb_mapa_dias_disponibles(array $cfg, int $limite = 60): array
{
    $pdo   = qrb_db();
    $vista = $cfg['vistas']['viajes'];
    [$wfe, $params] = qrb_where_fecha('FECHA', $cfg['fecha_desde'], $cfg['fecha_hasta']);
    $sql = "SELECT DATE(FECHA) AS dia, COUNT(*) AS n
            FROM `$vista`
            WHERE RECORRIDO IS NOT NULL
              AND RECORRIDO <> ''
              AND UPPER(RECORRIDO) <> 'NULL'
              AND DURACION >= 30
              $wfe
            GROUP BY DATE(FECHA)
            ORDER BY DATE(FECHA) DESC
            LIMIT $limite";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = ['dia' => (string)$r['dia'], 'n' => (int)$r['n']];
    }
    return $out;
}

/* ============================================================
   CARGA DE VIAJES DEL DÍA
   ============================================================ */

/**
 * Carga todos los viajes de un día específico que tengan
 * recorrido GPS válido y datos espaciales coherentes.
 */
function qrb_mapa_carga_viajes_dia(array $cfg, string $dia): array
{
    $pdo   = qrb_db();
    $vista = $cfg['vistas']['viajes'];
    $sql = "SELECT FECHA, DURACION, TIPO_BICICLETA,
                   ESTACION_LATITUD_ORIGEN, ESTACION_LONGITUD_ORIGEN,
                   ESTACION_LATITUD_DESTINO, ESTACION_LONGITUD_DESTINO,
                   RECORRIDO
            FROM `$vista`
            WHERE DATE(FECHA) = :dia
              AND RECORRIDO IS NOT NULL
              AND RECORRIDO <> ''
              AND UPPER(RECORRIDO) <> 'NULL'
              AND DURACION >= 30
            ORDER BY FECHA ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':dia' => $dia]);
    return $stmt->fetchAll();
}

/* ============================================================
   CARGA DE ESTACIONES (para puntos brillantes en el mapa)
   ============================================================ */

/**
 * Agrega lat/lng únicos de estaciones (origen y destino) del día
 * con el conteo de viajes que pasan por cada una. Sirve para
 * pintar puntos de "estación" titilantes en el mapa.
 */
function qrb_mapa_estaciones(array $viajes): array
{
    $est = [];
    foreach ($viajes as $v) {
        foreach (['ORIGEN', 'DESTINO'] as $cual) {
            $lat = (float)($v["ESTACION_LATITUD_$cual"]  ?? 0);
            $lng = (float)($v["ESTACION_LONGITUD_$cual"] ?? 0);
            if ($lat == 0.0 || $lng == 0.0) { continue; }
            $k = round($lat, 5) . ',' . round($lng, 5);
            if (!isset($est[$k])) {
                $est[$k] = ['lat' => $lat, 'lng' => $lng, 'n' => 0];
            }
            $est[$k]['n']++;
        }
    }
    return array_values($est);
}

/* ============================================================
   CARGA DE POLYLINES ACUMULADAS DE LOS ÚLTIMOS N DÍAS
   (uso exclusivo del mapa de riesgos: alimenta el heatmap)
   ============================================================ */

/**
 * Carga viajes con recorrido GPS de los últimos $dias días
 * que tengan datos (no calendario, sino días con viajes).
 * Devuelve solo el campo RECORRIDO ya decodificado y simplificado
 * + el tipo de bicicleta, sin la información del usuario.
 *
 * Si la cantidad total de puntos pasa de $max_puntos, se hace
 * sampleo determinista para mantener el JSON manejable.
 */
function qrb_mapa_polylines_recientes(array $cfg, int $dias = 7, int $max_puntos = 25000): array
{
    $pdo   = qrb_db();
    $vista = $cfg['vistas']['viajes'];

    // primero: obtener los últimos $dias días con datos GPS
    [$wfe, $params] = qrb_where_fecha('FECHA', $cfg['fecha_desde'], $cfg['fecha_hasta']);
    $sql = "SELECT DATE(FECHA) AS dia
            FROM `$vista`
            WHERE RECORRIDO IS NOT NULL AND RECORRIDO <> ''
              AND UPPER(RECORRIDO) <> 'NULL' AND DURACION >= 30 $wfe
            GROUP BY DATE(FECHA)
            ORDER BY DATE(FECHA) DESC
            LIMIT $dias";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $dias_arr = array_column($stmt->fetchAll(), 'dia');
    if (empty($dias_arr)) { return ['polylines' => [], 'dias' => [], 'total_puntos' => 0]; }

    // ahora cargar los viajes de esos días
    $placeholders = implode(',', array_fill(0, count($dias_arr), '?'));
    $sql = "SELECT TIPO_BICICLETA, RECORRIDO
            FROM `$vista`
            WHERE DATE(FECHA) IN ($placeholders)
              AND RECORRIDO IS NOT NULL AND RECORRIDO <> ''
              AND UPPER(RECORRIDO) <> 'NULL' AND DURACION >= 30";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($dias_arr);
    $rows = $stmt->fetchAll();

    // decodificar + simplificar
    $polylines = [];
    $total_puntos = 0;
    foreach ($rows as $r) {
        $pts = qrb_decodifica_y_simplifica($r['RECORRIDO'] ?? null);
        if (count($pts) < 2) { continue; }
        $tipo = strtoupper(trim((string)$r['TIPO_BICICLETA']));
        $tipo = strtr($tipo, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
                              'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U']);
        $t = (strpos($tipo, 'ELEC') !== false) ? 'E' : 'M';
        $polylines[] = ['t' => $t, 'p' => $pts];
        $total_puntos += count($pts);
    }

    // si nos pasamos del cap, sampleo proporcional
    if ($total_puntos > $max_puntos && $total_puntos > 0) {
        $ratio = $max_puntos / $total_puntos;
        foreach ($polylines as &$pl) {
            $n = count($pl['p']);
            $keep = max(2, (int)round($n * $ratio));
            if ($keep < $n) {
                $step = $n / $keep;
                $new = [];
                for ($i = 0; $i < $keep; $i++) {
                    $new[] = $pl['p'][(int)floor($i * $step)];
                }
                $pl['p'] = $new;
            }
        }
        unset($pl);
    }

    // recalcula
    $total_puntos_final = 0;
    foreach ($polylines as $pl) { $total_puntos_final += count($pl['p']); }

    return [
        'polylines'    => $polylines,
        'dias'         => $dias_arr,
        'total_puntos' => $total_puntos_final,
        'total_viajes' => count($polylines),
    ];
}

/* ============================================================
   ARMADO DEL PAYLOAD PARA LA ANIMACIÓN
   ============================================================ */

/**
 * Transforma las filas crudas a la estructura mínima que el
 * canvas overlay va a consumir. Tira viajes con < 2 puntos
 * decodificados y los que caen fuera de Querétaro (sanity).
 *
 *   $bbox = [lat_min, lat_max, lng_min, lng_max] de Querétaro
 *           grande (suficiente para todo el área metropolitana)
 */
function qrb_mapa_arma_payload(array $viajes_raw): array
{
    $viajes = [];
    $sum_lat = 0.0; $sum_lng = 0.0; $n_centro = 0;
    $descartes_decode = 0;       // polyline no decodificable
    $descartes_coords = 0;       // coordenadas absurdas (fuera del planeta)
    $duraciones_segundos = 0;    // suma de duraciones tal cual vienen
    $duraciones_max = 0;
    $tipos_vistos = [];          // qué llega exactamente en TIPO_BICICLETA

    foreach ($viajes_raw as $v) {
        $pts = qrb_decodifica_y_simplifica($v['RECORRIDO'] ?? null);
        if (count($pts) < 2) { $descartes_decode++; continue; }

        // sanity geográfica laxa: cualquier coord válida del planeta
        [$la, $lo] = $pts[0];
        if ($la < -90 || $la > 90 || $lo < -180 || $lo > 180 ||
            ($la == 0.0 && $lo == 0.0)) {
            $descartes_coords++; continue;
        }

        // minuto del día (0..1439)
        $ts = strtotime($v['FECHA']);
        $minuto = (int)date('G', $ts) * 60 + (int)date('i', $ts);

        // duración: la vista regresa segundos (filtro de >30s lo confirma)
        $dur_raw = (int)$v['DURACION'];
        $duraciones_segundos += $dur_raw;
        if ($dur_raw > $duraciones_max) { $duraciones_max = $dur_raw; }
        $dur = max(1, (int)round($dur_raw / 60));   // segundos → minutos

        // strtoupper() de PHP NO es UTF-8 safe: deja 'é' minúscula intacta.
        // Por eso normalizamos acentos ANTES (mapeando tanto minúsculas
        // como mayúsculas) y luego pasamos a mayúsculas con ASCII puro.
        $tipo_raw = trim((string)$v['TIPO_BICICLETA']);
        $tipos_vistos[$tipo_raw] = ($tipos_vistos[$tipo_raw] ?? 0) + 1;
        $tipo = strtr($tipo_raw, [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u',
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U',
        ]);
        $tipo = strtoupper($tipo);
        $t = (strpos($tipo, 'ELEC') !== false || $tipo === 'E') ? 'E' : 'M';

        $viajes[] = [
            'm' => $minuto,
            'd' => $dur,
            't' => $t,
            'p' => $pts,
        ];

        // centro promedio del primer punto de cada viaje
        $sum_lat += $la; $sum_lng += $lo; $n_centro++;
    }

    // centro: promedio de los orígenes o, si no hubo, centro de QRO
    $centro = $n_centro > 0
        ? ['lat' => round($sum_lat / $n_centro, 6), 'lng' => round($sum_lng / $n_centro, 6)]
        : ['lat' => 20.5888, 'lng' => -100.3899];

    // si hay demasiados viajes, hacemos muestreo determinista para
    // mantener la animación fluida (~60fps en laptop promedio)
    $LIMITE = 5000;
    $total_real = count($viajes);
    if ($total_real > $LIMITE) {
        $paso = $total_real / $LIMITE;
        $sample = [];
        for ($i = 0; $i < $LIMITE; $i++) {
            $sample[] = $viajes[(int)floor($i * $paso)];
        }
        $viajes = $sample;
    }

    // métricas agregadas para los KPIs del header
    $tot_M = 0; $tot_E = 0;
    foreach ($viajes as $v) {
        if ($v['t'] === 'E') { $tot_E++; } else { $tot_M++; }
    }

    $dur_prom = count($viajes) > 0 ? (int)round($duraciones_segundos / count($viajes)) : 0;

    return [
        'viajes'      => $viajes,
        'centro'      => $centro,
        'total'       => count($viajes),
        'total_real'  => $total_real,
        'mecanicas'   => $tot_M,
        'electricas'  => $tot_E,
        'diag'        => [
            'crudos'           => count($viajes_raw),
            'descartes_decode' => $descartes_decode,
            'descartes_coords' => $descartes_coords,
            'duracion_prom_s'  => $dur_prom,
            'duracion_max_s'   => $duraciones_max,
            'tipos_vistos'     => $tipos_vistos,
        ],
    ];
}

/* ============================================================
   ORQUESTADOR
   ============================================================ */

/**
 * Devuelve el dataset completo para la página del mapa animado.
 * Estructura:
 *   [
 *     'fecha'       => 'YYYY-MM-DD',
 *     'fecha_label' => 'jueves 14 de mayo de 2026',
 *     'centro'      => ['lat'=>..,'lng'=>..],
 *     'total'       => N,
 *     'mecanicas'   => N,
 *     'electricas'  => N,
 *     'estaciones'  => [['lat','lng','n'], ...],
 *     'viajes'      => [{m,d,t,p:[[lat,lng],...]}, ...],
 *     'vacio'       => bool,
 *   ]
 */
function qrb_construye_dataset_mapa(array $cfg, ?string $dia_solicitado = null): array
{
    // lista de días con datos (últimos 60 días con viajes válidos)
    $dias_disp = qrb_mapa_dias_disponibles($cfg, 60);
    $dias_set  = array_column($dias_disp, 'dia');   // sólo strings de fechas

    // resuelve día: solicitado (si existe y tiene datos) | último con datos
    $dia = null;
    if ($dia_solicitado !== null
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia_solicitado)
        && in_array($dia_solicitado, $dias_set, true)) {
        $dia = $dia_solicitado;
    } else {
        $dia = $dias_set[0] ?? qrb_mapa_ultimo_dia($cfg);
    }

    if (!$dia) {
        return ['vacio' => true,
                'dias_disponibles' => $dias_disp,
                'mensaje' => 'No hay viajes con datos GPS en el periodo configurado.'];
    }

    $raw = qrb_mapa_carga_viajes_dia($cfg, $dia);
    if (empty($raw)) {
        return ['vacio' => true, 'fecha' => $dia,
                'mensaje' => 'El día más reciente no contiene viajes con recorrido GPS.'];
    }

    $payload = qrb_mapa_arma_payload($raw);
    $estaciones = qrb_mapa_estaciones($raw);

    // etiqueta humana de la fecha en español
    $meses = ['enero','febrero','marzo','abril','mayo','junio',
              'julio','agosto','septiembre','octubre','noviembre','diciembre'];
    $dias_sem = ['Sunday'=>'domingo','Monday'=>'lunes','Tuesday'=>'martes',
                 'Wednesday'=>'miércoles','Thursday'=>'jueves',
                 'Friday'=>'viernes','Saturday'=>'sábado'];
    $ts = strtotime($dia);
    $fecha_label = sprintf(
        '%s %d de %s de %d',
        $dias_sem[date('l', $ts)] ?? '',
        (int)date('j', $ts),
        $meses[(int)date('n', $ts) - 1] ?? '',
        (int)date('Y', $ts)
    );

    return [
        'vacio'       => false,
        'fecha'       => $dia,
        'fecha_label' => $fecha_label,
        'centro'      => $payload['centro'],
        'total'       => $payload['total'],
        'total_real'  => $payload['total_real'],
        'mecanicas'   => $payload['mecanicas'],
        'electricas'  => $payload['electricas'],
        'estaciones'  => $estaciones,
        'viajes'      => $payload['viajes'],
        'dias_disponibles' => $dias_disp,
        'diag'        => $payload['diag'] ?? null,
    ];
}
