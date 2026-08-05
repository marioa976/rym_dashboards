<?php
/**
 * Ejecutivo · librería. Cruza indicadores de todos los módulos por su dimensión
 * común (delegación) y provee las capas geográficas del mapa.
 *
 * Fuentes (todas en portal_qro):
 *   obras, areas_verdes, padron (DIF), tickets (Zendesk), usuarios_bloque (Bloque).
 * Cada consulta va en su propio try/catch: si una fuente falta, el tablero sigue.
 */
declare(strict_types=1);

function ej_config(): array {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $c;
}

function ej_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $db = ej_config()['db'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'], (int)$db['port'], $db['name'], $db['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
    ]);
    return $pdo;
}

/** Las 7 delegaciones canónicas (orden fijo para el tablero). */
function ej_delegaciones(): array {
    return ['Centro Histórico','Epigmenio González','Felipe Carrillo Puerto',
            'Félix Osores Sotomayor','Josefa Vergara y Hernández',
            'Santa Rosa Jáuregui','Villa Cayetano Rubio'];
}

/** Normaliza cualquier texto de delegación a una de las 7 canónicas, o null. */
function ej_canon(?string $s): ?string {
    if ($s === null) return null;
    $s = mb_strtoupper(trim($s), 'UTF-8');
    $s = strtr($s, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','Ü'=>'U']);
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    static $map = [
        'CENTRO HISTORICO'           => 'Centro Histórico',
        'EPIGMENIO GONZALEZ'         => 'Epigmenio González',
        'FELIPE CARRILLO PUERTO'     => 'Felipe Carrillo Puerto',
        'FELIX OSORES SOTOMAYOR'     => 'Félix Osores Sotomayor',
        'JOSEFA VERGARA Y HERNANDEZ' => 'Josefa Vergara y Hernández',
        'SANTA ROSA JAUREGUI'        => 'Santa Rosa Jáuregui',
        'CAYETANO RUBIO'             => 'Villa Cayetano Rubio',
    ];
    foreach ($map as $k => $v) if (strpos($s, $k) !== false) return $v;
    return null;
}

/** KPIs de portada: totales por módulo (con degradación si una fuente falta). */
function ej_kpis(PDO $pdo): array {
    $q = function(string $sql) use ($pdo) {
        try { return $pdo->query($sql)->fetch(); } catch (Throwable $e) { return null; }
    };
    $ob  = $q("SELECT COUNT(*) n, COALESCE(SUM(ejercido),0) inv, SUM(estatus='TERMINADA') term FROM obras WHERE activo=1");
    $av  = $q("SELECT COUNT(*) n FROM areas_verdes WHERE activo=1");
    $dif = $q("SELECT COUNT(*) n, SUM(latitud IS NOT NULL AND latitud<>0) geo FROM padron");
    $zd  = $q("SELECT COUNT(*) n, SUM(latitud IS NOT NULL AND latitud<>0) geo FROM tickets");
    $bl  = $q("SELECT COUNT(*) n FROM usuarios_bloque");
    return [
        'obras'      => ['n'=>(int)($ob['n']??0), 'inv'=>(float)($ob['inv']??0), 'term'=>(int)($ob['term']??0)],
        'areas'      => ['n'=>(int)($av['n']??0)],
        'dif'        => ['n'=>(int)($dif['n']??0), 'geo'=>(int)($dif['geo']??0)],
        'zendesk'    => ['n'=>(int)($zd['n']??0),  'geo'=>(int)($zd['geo']??0)],
        'bloque'     => ['n'=>(int)($bl['n']??0)],
    ];
}

/**
 * Matriz cruzada por delegación canónica:
 *   [delegacion => ['obras'=>,'inv'=>,'areas'=>,'dif'=>,'tickets'=>,'bloque'=>]]
 * Incluye fila 'Otras / sin ubicar' para lo que no cae en las 7.
 */
function ej_matriz(PDO $pdo): array {
    $filas = [];
    foreach (array_merge(ej_delegaciones(), ['Otras / sin ubicar']) as $d)
        $filas[$d] = ['obras'=>0,'inv'=>0.0,'areas'=>0,'dif'=>0,'tickets'=>0,'bloque'=>0];
    $OTRAS = 'Otras / sin ubicar';
    $add = function(?string $raw, string $col, $val) use (&$filas, $OTRAS) {
        $d = ej_canon($raw) ?? $OTRAS;
        $filas[$d][$col] += $val;
    };
    $try = function(string $sql, callable $fn) use ($pdo) {
        try { foreach ($pdo->query($sql) as $r) $fn($r); } catch (Throwable $e) {}
    };
    // Obras (n + inversión), canonizando la delegación en PHP con $add
    $try("SELECT COALESCE(delegacion_geo,delegacion) d, COUNT(*) n, COALESCE(SUM(ejercido),0) inv FROM obras WHERE activo=1 GROUP BY d",
        function($r) use ($add){ $add($r['d'],'obras',(int)$r['n']); $add($r['d'],'inv',(float)$r['inv']); });
    $try("SELECT COALESCE(delegacion_geo,delegacion) d, COUNT(*) n FROM areas_verdes WHERE activo=1 GROUP BY d",
        function($r) use ($add){ $add($r['d'],'areas',(int)$r['n']); });
    $try("SELECT delegacion d, COUNT(*) n FROM padron GROUP BY delegacion",
        function($r) use ($add){ $add($r['d'],'dif',(int)$r['n']); });
    $try("SELECT c.nombre d, COUNT(*) n FROM tickets t LEFT JOIN cat_delegacion c ON c.id=t.delegacion_id GROUP BY c.nombre",
        function($r) use ($add){ $add($r['d'],'tickets',(int)$r['n']); });
    $try("SELECT delegacion d, COUNT(*) n FROM usuarios_bloque GROUP BY delegacion",
        function($r) use ($add){ $add($r['d'],'bloque',(int)$r['n']); });
    return $filas;
}

/** Obras terminadas/en proceso para el donut ejecutivo. */
function ej_obras_estatus(PDO $pdo): array {
    try {
        return $pdo->query("SELECT estatus, COUNT(*) n, COALESCE(SUM(ejercido),0) inv
                              FROM obras WHERE activo=1 GROUP BY estatus ORDER BY n DESC")->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* =====================================================================
 *  QROBICI (BD remota) — agregado cacheado y resiliente
 * ===================================================================== */

/** Polígonos de delegación parseados desde delegaciones_geo (para point-in-polygon en PHP). */
function ej_poligonos(PDO $pdo): array {
    static $polys = null;
    if ($polys !== null) return $polys;
    $polys = [];
    try {
        foreach ($pdo->query("SELECT nombre, geojson FROM delegaciones_geo") as $r) {
            $g = json_decode((string)$r['geojson'], true); if (!is_array($g)) continue;
            $rings = [];
            if ($g['type'] === 'Polygon')            $rings[] = $g['coordinates'][0] ?? [];
            elseif ($g['type'] === 'MultiPolygon') foreach ($g['coordinates'] as $poly) $rings[] = $poly[0] ?? [];
            $polys[] = ['n' => $r['nombre'], 'rings' => $rings];
        }
    } catch (Throwable $e) {}
    return $polys;
}

/** Delegación (nombre canónico) donde cae un punto, o null. */
function ej_deleg_punto(PDO $pdo, float $lat, float $lng): ?string {
    foreach (ej_poligonos($pdo) as $p) {
        foreach ($p['rings'] as $ring) {
            $inside = false; $n = count($ring);
            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                $xi=$ring[$i][0]; $yi=$ring[$i][1]; $xj=$ring[$j][0]; $yj=$ring[$j][1];
                $dy = ($yj - $yi) ?: 1e-12;
                if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / $dy + $xi)) $inside = !$inside;
            }
            if ($inside) return $p['n'];
        }
    }
    return null;
}

/**
 * Agregado de Qrobici desde su BD remota (dwh_viajes). Cacheado 30 min y con
 * degradación: si el remoto no responde usa el caché previo, o null.
 * Devuelve ['kpis'=>[viajes,km,usuarios,estaciones], 'por_deleg'=>[d=>viajes], 'estaciones'=>[...]].
 */
function ej_qrobici(PDO $pdo): ?array {
    $cache = sys_get_temp_dir() . '/ejec_qrobici.json';
    if (is_file($cache) && (time() - filemtime($cache)) < 1800) {
        $d = json_decode((string)@file_get_contents($cache), true);
        if (is_array($d)) return $d;
    }
    try {
        $cfg = require __DIR__ . '/../../config/config.php';
        $qd  = $cfg['modulos']['qrobici']['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $qd['host'], (int)$qd['port'], $qd['name'], $qd['charset'] ?? 'utf8mb4');
        $q   = new PDO($dsn, $qd['user'], $qd['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>8]);
        $t   = $q->query("SELECT COUNT(*) viajes, COALESCE(SUM(DISTANCIA),0) dist_m, COUNT(DISTINCT USUARIO_ID) usuarios FROM dwh_viajes")->fetch();
        $est = $q->query("SELECT ESTACION_ORIGEN nombre, AVG(ESTACION_LATITUD_ORIGEN) lat, AVG(ESTACION_LONGITUD_ORIGEN) lng, COUNT(*) viajes
                            FROM dwh_viajes
                           WHERE ESTACION_LATITUD_ORIGEN IS NOT NULL AND ESTACION_LATITUD_ORIGEN<>0
                           GROUP BY ESTACION_ORIGEN")->fetchAll();
    } catch (Throwable $e) {
        if (is_file($cache)) { $d = json_decode((string)@file_get_contents($cache), true); if (is_array($d)) return $d; }
        return null;
    }
    $porDeleg = []; $estaciones = [];
    foreach ($est as $e) {
        $lat = (float)$e['lat']; $lng = (float)$e['lng']; if (!$lat || !$lng) continue;
        $d = ej_deleg_punto($pdo, $lat, $lng);
        $estaciones[] = ['n'=>$e['nombre'],'lat'=>round($lat,6),'lng'=>round($lng,6),'v'=>(int)$e['viajes'],'d'=>$d];
        $k = $d ?? 'Otras / sin ubicar'; $porDeleg[$k] = ($porDeleg[$k] ?? 0) + (int)$e['viajes'];
    }
    $res = [
        'kpis' => ['viajes'=>(int)$t['viajes'], 'km'=>(int)round(((float)$t['dist_m'])/1000),
                   'usuarios'=>(int)$t['usuarios'], 'estaciones'=>count($estaciones)],
        'por_deleg' => $porDeleg, 'estaciones' => $estaciones,
    ];
    @file_put_contents($cache, json_encode($res, JSON_UNESCAPED_UNICODE));
    return $res;
}

/* =====================================================================
 *  ELECTORAL (Reporte 3) — Ayuntamiento 2024 por sección
 * ===================================================================== */

/**
 * Resultado seccional del Ayuntamiento 2024: participación, ganador y márgenes
 * por sección + polígonos + KPIs globales. Cacheado 1 h (datos estáticos).
 */
function ej_electoral(PDO $pdo): array {
    $cache = sys_get_temp_dir() . '/ejec_electoral_ayto2024.json';
    if (is_file($cache) && (time() - filemtime($cache)) < 3600) {
        $d = json_decode((string)@file_get_contents($cache), true);
        if (is_array($d)) return $d;
    }
    $vacio = ['sec'=>[], 'geo'=>['type'=>'FeatureCollection','features'=>[]],
              'kpis'=>['ln'=>0,'emit'=>0,'val'=>0,'part'=>0,'secciones'=>0]];
    try {
        $proc = (int)$pdo->query("SELECT id FROM procesos_electorales WHERE anio=2024 AND nivel='estatal' ORDER BY anio DESC LIMIT 1")->fetchColumn();
        $tipo = (int)$pdo->query("SELECT id FROM tipos_eleccion WHERE codigo='ayuntamiento' LIMIT 1")->fetchColumn();
        if (!$proc || !$tipo) return $vacio;

        $votos = [];
        $st = $pdo->prepare("SELECT cas.num_seccion s, rc.voto_codigo c, SUM(rc.votos) v
                               FROM resultados_casilla rc
                               JOIN casillas cas ON cas.id=rc.casilla_id
                               JOIN elecciones e ON e.id=rc.eleccion_id
                              WHERE e.proceso_id=? AND e.tipo_id=? GROUP BY cas.num_seccion, rc.voto_codigo");
        $st->execute([$proc,$tipo]);
        foreach ($st as $r) $votos[(int)$r['s']][(string)$r['c']] = (int)$r['v'];

        $ln = [];
        $st = $pdo->prepare("SELECT cas.num_seccion s, SUM(rcm.lista_nominal) ln
                               FROM resultados_casilla_meta rcm
                               JOIN casillas cas ON cas.id=rcm.casilla_id
                               JOIN elecciones e ON e.id=rcm.eleccion_id
                              WHERE e.proceso_id=? AND e.tipo_id=? GROUP BY cas.num_seccion");
        $st->execute([$proc,$tipo]);
        foreach ($st as $r) $ln[(int)$r['s']] = (int)$r['ln'];

        $excl = ['NULOS'=>1,'NO_REGISTRADAS'=>1,'CANDIDATO_NO_REGISTRADO'=>1];
        $sec = []; $totLN=0; $totEmit=0; $totVal=0;
        foreach ($votos as $s => $cs) {
            $val=0; $emit=0; $gan=null; $ganv=0;
            foreach ($cs as $c => $v) {
                $emit += $v; $up = strtoupper($c);
                if (!isset($excl[$up])) { $val += $v; if ($v > $ganv) { $ganv=$v; $gan=$c; } }
            }
            $lnv = $ln[$s] ?? 0; $totLN+=$lnv; $totEmit+=$emit; $totVal+=$val;
            $sec[$s] = ['part'=>$lnv>0?round($emit/$lnv*100,1):null, 'gan'=>$gan,
                        'ganp'=>$val>0?round($ganv/$val*100,1):null, 'ln'=>$lnv, 'val'=>$val];
        }

        $features = []; $vistos = [];
        foreach ($pdo->query("SELECT s.num_seccion, ST_AsGeoJSON(g.geom,5) gj
                                FROM secciones s JOIN secciones_geo g ON g.seccion_id=s.id") as $r) {
            $s = (int)$r['num_seccion'];
            if (isset($vistos[$s]) || !$r['gj']) continue;
            $geom = json_decode($r['gj'], true); if (!$geom) continue;
            $vistos[$s] = true;
            $features[] = ['type'=>'Feature','geometry'=>$geom,'properties'=>['s'=>$s]];
        }
        $res = ['sec'=>$sec, 'geo'=>['type'=>'FeatureCollection','features'=>$features],
                'kpis'=>['ln'=>$totLN,'emit'=>$totEmit,'val'=>$totVal,
                         'part'=>$totLN>0?round($totEmit/$totLN*100,1):0, 'secciones'=>count($sec)]];
        @file_put_contents($cache, json_encode($res, JSON_UNESCAPED_UNICODE));
        return $res;
    } catch (Throwable $e) { return $vacio; }
}

/* =====================================================================
 *  CAPAS ESPECIALES: calor de rutas Qrobici + alertas Waze
 * ===================================================================== */

/** Bounding box de Querétaro para acotar Waze. [minlat,maxlat,minlng,maxlng] */
function ej_bbox_qro(): array { return [20.40, 20.85, -100.60, -100.20]; }

/**
 * Mapa de calor de las RUTAS de Qrobici: decodifica la polilínea RECORRIDO de
 * cada viaje (BD remota) y devuelve una nube de puntos [[lat,lng],...] muestreada.
 * Cacheado 30 min y resiliente. Requiere el decoder del módulo qrobici.
 */
function ej_qrobici_calor(PDO $pdo, int $maxPuntos = 25000): array {
    $cache = sys_get_temp_dir() . '/ejec_qrobici_calor.json';
    if (is_file($cache) && (time() - filemtime($cache)) < 1800) {
        $d = json_decode((string)@file_get_contents($cache), true);
        if (is_array($d)) return $d;
    }
    require_once __DIR__ . '/../qrobici/lib_polyline.php';   // qrb_polyline_decode (solo funciones)
    @ini_set('memory_limit', '256M');
    [$minLat,$maxLat,$minLng,$maxLng] = ej_bbox_qro();
    $pts = []; $tope = $maxPuntos * 2;                         // cota dura en memoria
    try {
        $cfg = require __DIR__ . '/../../config/config.php';
        $qd  = $cfg['modulos']['qrobici']['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $qd['host'], (int)$qd['port'], $qd['name'], $qd['charset'] ?? 'utf8mb4');
        $q   = new PDO($dsn, $qd['user'], $qd['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,      // streaming: no retiene todo en memoria
        ]);
        $st = $q->query("SELECT RECORRIDO FROM dwh_viajes
                          WHERE RECORRIDO IS NOT NULL AND RECORRIDO<>'' AND UPPER(RECORRIDO)<>'NULL'");
        foreach ($st as $row) {
            $line = qrb_polyline_decode((string)$row['RECORRIDO']);
            for ($i = 0, $n = count($line); $i < $n; $i += 6) {   // 1 de cada 6 puntos por ruta
                $p = $line[$i];
                if ($p[0] >= $minLat && $p[0] <= $maxLat && $p[1] >= $minLng && $p[1] <= $maxLng)
                    $pts[] = [$p[0], $p[1]];
            }
            if (count($pts) >= $tope) break;                  // no crecer sin límite
        }
    } catch (Throwable $e) {
        if (is_file($cache)) { $d = json_decode((string)@file_get_contents($cache), true); if (is_array($d)) return $d; }
        return [];
    }
    if (count($pts) > $maxPuntos) {                            // downsample sistemático a la meta
        $step = (int)ceil(count($pts) / $maxPuntos);
        $tmp = []; for ($i = 0, $n = count($pts); $i < $n; $i += $step) $tmp[] = $pts[$i];
        $pts = $tmp;
    }
    @file_put_contents($cache, json_encode($pts));
    return $pts;
}

/**
 * Alertas Waze (tiempo real) normalizadas y agrupadas por categoría, más los
 * embotellamientos (jams) como líneas. Usa el feed configurado en Qrobici.
 * NO incluye modules/qrobici/config.php (dispararía su guard): toma la URL del
 * config central y sólo requiere la librería de funciones lib_waze.php.
 */
function ej_waze(): array {
    require_once __DIR__ . '/../qrobici/lib_waze.php';        // qrb_waze_fetch/_norm_* (solo funciones)
    $cfg = require __DIR__ . '/../../config/config.php';
    $url = $cfg['modulos']['qrobici']['waze_feed_url'] ?? '';
    if ($url === '') return ['ok'=>false, 'error'=>'Sin feed Waze configurado', 'alerts'=>[], 'jams'=>[]];

    $j = qrb_waze_fetch($url, 120);
    if (isset($j['__error'])) return ['ok'=>false, 'error'=>$j['__error'], 'alerts'=>[], 'jams'=>[]];

    [$minLat,$maxLat,$minLng,$maxLng] = ej_bbox_qro();
    $inBbox = fn($lat,$lng) => $lat>=$minLat && $lat<=$maxLat && $lng>=$minLng && $lng<=$maxLng;
    $cat = ['HAZARD'=>'hazard','ROAD_CLOSED'=>'closed','ACCIDENT'=>'accident','JAM'=>'jam'];

    $alerts = [];
    foreach ($j['alerts'] ?? [] as $a) {
        $n = qrb_waze_norm_alert($a); if (!$n || !$inBbox($n['lat'],$n['lng'])) continue;
        $alerts[] = ['lat'=>$n['lat'],'lng'=>$n['lng'],'cat'=>$cat[$n['type']] ?? 'other',
                     'type'=>$n['type'],'sub'=>$n['subtype'],'street'=>$n['street']];
    }
    $jams = [];
    foreach ($j['jams'] ?? [] as $jm) {
        $n = qrb_waze_norm_jam($jm); if (!$n) continue;
        $p0 = $n['puntos'][0]; if (!$inBbox($p0[0],$p0[1])) continue;
        $jams[] = ['pts'=>$n['puntos'],'level'=>$n['level'],'speed'=>$n['speedKMH'],
                   'street'=>$n['street'],'delay'=>$n['delaySec']];
    }
    return ['ok'=>true, 'source'=>$j['__source'] ?? '', 'alerts'=>$alerts, 'jams'=>$jams];
}

/* =====================================================================
 *  CAPAS DEL MAPA (para mapa.php / data.php)
 * ===================================================================== */

/** Límites delegacionales oficiales (FeatureCollection). */
function ej_limites(PDO $pdo): array {
    try { $rows = $pdo->query("SELECT nombre, geojson FROM delegaciones_geo ORDER BY nombre")->fetchAll(); }
    catch (Throwable $e) { return []; }
    $f = [];
    foreach ($rows as $r) {
        $g = json_decode((string)$r['geojson'], true);
        if (is_array($g)) $f[] = ['type'=>'Feature','geometry'=>$g,'properties'=>['d'=>$r['nombre']]];
    }
    return $f;
}

/** Capa de obras (puntos con meta ligera). */
function ej_capa_obras(PDO $pdo): array {
    $out = [];
    try {
        foreach ($pdo->query("SELECT nombre,estatus,ejercido,lat,lng FROM obras
                               WHERE activo=1 AND lat IS NOT NULL") as $r)
            $out[] = ['lat'=>(float)$r['lat'],'lng'=>(float)$r['lng'],'n'=>$r['nombre'],
                      's'=>$r['estatus'],'e'=>$r['ejercido']!==null?(float)$r['ejercido']:null];
    } catch (Throwable $e) {}
    return $out;
}

/** Capa de áreas verdes (puntos con meta ligera). */
function ej_capa_areas(PDO $pdo): array {
    $out = [];
    try {
        foreach ($pdo->query("SELECT nombre, COALESCE(delegacion_geo,delegacion) d, lat, lng
                               FROM areas_verdes WHERE activo=1 AND lat IS NOT NULL") as $r)
            $out[] = ['lat'=>(float)$r['lat'],'lng'=>(float)$r['lng'],'n'=>$r['nombre'],'d'=>$r['d']];
    } catch (Throwable $e) {}
    return $out;
}

/**
 * Capa densa (heatmap) de una tabla con latitud/longitud. Muestrea con id%step
 * para acotar el payload, y cachea 10 min. Devuelve [[lat,lng],...].
 * @param string $tabla 'tickets' | 'padron'
 */
function ej_capa_calor(PDO $pdo, string $tabla, int $maxPuntos = 15000): array {
    $tabla = $tabla === 'padron' ? 'padron' : 'tickets';   // whitelist
    $idcol = $tabla === 'padron' ? 'id' : 'ticket_id';     // PK numérica para muestrear
    $cache = sys_get_temp_dir() . "/ejec_calor_{$tabla}.json";
    if (is_file($cache) && (time() - filemtime($cache)) < 600) {
        $d = json_decode((string)@file_get_contents($cache), true);
        if (is_array($d)) return $d;
    }
    $pts = [];
    try {
        $total = (int)$pdo->query("SELECT COUNT(*) FROM `$tabla` WHERE latitud IS NOT NULL AND latitud<>0")->fetchColumn();
        $step  = $total > $maxPuntos ? (int)ceil($total / $maxPuntos) : 1;
        // muestreo estable por PK (evita ORDER BY RAND() costoso)
        $sql = "SELECT latitud lat, longitud lng FROM `$tabla`
                 WHERE latitud IS NOT NULL AND latitud<>0 AND longitud IS NOT NULL AND longitud<>0"
             . ($step > 1 ? " AND (`$idcol` % $step)=0" : "");
        foreach ($pdo->query($sql) as $r) {
            $lat=(float)$r['lat']; $lng=(float)$r['lng'];
            if ($lat>20.0 && $lat<21.6 && $lng>-100.95 && $lng<-99.5) $pts[] = [round($lat,6),round($lng,6)];
        }
        @file_put_contents($cache, json_encode($pts));
    } catch (Throwable $e) {}
    return $pts;
}
