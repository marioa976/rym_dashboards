<?php
/**
 * Ejecutivo · Recorrido territorial — datos para el planeador.
 *
 * Dos modos:
 *   1) geomonly=1 + (sec|dist|deleg)  -> solo geometría + contexto (para ubicar/
 *      hacer zoom en el mapa, sin cargar puntos: rápido).
 *   2) shape=poly&poly=lat,lng;...    -> carga SOLO los puntos que caen dentro del
 *      shape=corr&line=lat,lng;...&buffer=150   trazo (polígono o corredor). Mucho
 *      más eficiente que traer un distrito entero y descartar en el cliente.
 *      Se puede pasar layers=tickets,dif,obras,areas,bloque y dias=N (tickets).
 *   (compat) sin shape ni geomonly + (sec|dist|deleg) -> carga todo el territorio.
 *
 * Guard: require_module('ejecutivo'). PII de beneficiarios (nombre/domicilio)
 * SOLO para editor/admin del módulo.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';     // guard: require_module('ejecutivo')
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

$verPII    = function_exists('puede_editar') && puede_editar('ejecutivo');
$geomonly  = !empty($_GET['geomonly']);
$sec       = isset($_GET['sec'])   ? (int)$_GET['sec']  : 0;
$dist      = isset($_GET['dist'])  ? (int)$_GET['dist'] : 0;
$deleg     = isset($_GET['deleg']) ? trim((string)$_GET['deleg']) : '';
$dias      = isset($_GET['dias'])  ? max(0, (int)$_GET['dias']) : 0;
$shape     = (string)($_GET['shape'] ?? '');   // '' | 'poly' | 'corr'
$layersReq = array_values(array_filter(array_map('trim', explode(',', (string)($_GET['layers'] ?? 'tickets,dif,obras,areas,bloque')))));
$LIMIT     = 6000;

/** Ray-casting sobre anillos [[lng,lat],...]. */
if (!function_exists('rec_pip')) {
function rec_pip(array $rings, float $lat, float $lng): bool {
    foreach ($rings as $ring) {
        $inside = false; $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi=$ring[$i][0]; $yi=$ring[$i][1]; $xj=$ring[$j][0]; $yj=$ring[$j][1];
            $dy = ($yj - $yi) ?: 1e-12;
            if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / $dy + $xi)) $inside = !$inside;
        }
        if ($inside) return true;
    }
    return false;
}
}
/** Anillos exteriores de un GeoJSON Polygon/MultiPolygon. */
if (!function_exists('rec_rings_from_geojson')) {
function rec_rings_from_geojson(array $g): array {
    $rings = [];
    if (($g['type'] ?? '') === 'Polygon')            { $rings[] = $g['coordinates'][0] ?? []; }
    elseif (($g['type'] ?? '') === 'MultiPolygon')   { foreach ($g['coordinates'] as $poly) $rings[] = $poly[0] ?? []; }
    return array_values(array_filter($rings, fn($r) => count($r) >= 3));
}
}
/** Distancia (m) de un punto al segmento a-b, con escala local (equirectangular). */
if (!function_exists('rec_seg_dist_m')) {
function rec_seg_dist_m(float $plat,float $plng,float $alat,float $alng,float $blat,float $blng): float {
    $mlat = 111320.0; $mlng = 111320.0 * cos(deg2rad($plat));
    $ax = ($alng-$plng)*$mlng; $ay = ($alat-$plat)*$mlat;
    $bx = ($blng-$plng)*$mlng; $by = ($blat-$plat)*$mlat;
    $dx = $bx-$ax; $dy = $by-$ay; $len2 = $dx*$dx + $dy*$dy;
    if ($len2 <= 0) return sqrt($ax*$ax + $ay*$ay);
    $t = max(0.0, min(1.0, -($ax*$dx + $ay*$dy)/$len2));
    $cx = $ax + $t*$dx; $cy = $ay + $t*$dy;
    return sqrt($cx*$cx + $cy*$cy);
}
}
if (!function_exists('rec_near_line')) {
function rec_near_line(array $path, float $lat, float $lng, float $buffer): bool {
    for ($i = 0, $n = count($path); $i < $n - 1; $i++) {
        if (rec_seg_dist_m($lat,$lng,$path[$i][0],$path[$i][1],$path[$i+1][0],$path[$i+1][1]) <= $buffer) return true;
    }
    return false;
}
}

try {
    $pdo = ej_pdo();

    $rings = []; $line = []; $buffer = 0; $inside = null;
    $geom = null; $titulo = ''; $part = null; $gan = null;

    if ($shape === 'poly' && !empty($_GET['poly'])) {
        $ring = [];
        foreach (explode(';', (string)$_GET['poly']) as $pair) {
            $xy = array_map('floatval', explode(',', $pair));
            if (count($xy) === 2 && $xy[0] && $xy[1]) $ring[] = [$xy[1], $xy[0]]; // [lng,lat]
        }
        if (count($ring) < 3) { echo json_encode(['ok'=>false,'error'=>'Polígono inválido.']); exit; }
        $rings  = [$ring];
        $titulo = 'Zona trazada';
        $inside = fn($lat,$lng) => rec_pip($rings, $lat, $lng);
    } elseif ($shape === 'corr' && !empty($_GET['line'])) {
        foreach (explode(';', (string)$_GET['line']) as $pair) {
            $xy = array_map('floatval', explode(',', $pair));
            if (count($xy) === 2 && $xy[0] && $xy[1]) $line[] = [$xy[0], $xy[1]]; // [lat,lng]
        }
        if (count($line) < 2) { echo json_encode(['ok'=>false,'error'=>'Corredor inválido.']); exit; }
        $buffer = max(20, (int)($_GET['buffer'] ?? 150));
        $titulo = 'Corredor trazado';
        $inside = fn($lat,$lng) => rec_near_line($line, $lat, $lng, $buffer);
    } else {
        // Territorio por sección / distrito / delegación.
        if ($sec > 0) {
            $st = $pdo->prepare("SELECT ST_AsGeoJSON(g.geom,6) gj
                                   FROM secciones s JOIN secciones_geo g ON g.seccion_id=s.id
                                  WHERE s.num_seccion=? LIMIT 1");
            $st->execute([$sec]);
            $gj = $st->fetchColumn();
            if ($gj) { $geom = json_decode((string)$gj, true); if (is_array($geom)) $rings = rec_rings_from_geojson($geom); }
            $titulo = "Sección $sec";
        } elseif ($dist > 0) {
            $st = $pdo->prepare("SELECT ST_AsGeoJSON(g.geom,6) gj
                                   FROM secciones s JOIN secciones_geo g ON g.seccion_id=s.id
                                  WHERE s.distrito_id=?");
            $st->execute([$dist]);
            foreach ($st as $r) { $gg = json_decode((string)$r['gj'], true); if (is_array($gg)) foreach (rec_rings_from_geojson($gg) as $rg) $rings[] = $rg; }
            $dn = $pdo->prepare("SELECT numero,nombre FROM distritos WHERE id=? LIMIT 1"); $dn->execute([$dist]); $dr = $dn->fetch();
            $titulo = 'Distrito ' . ($dr['numero'] ?? $dist) . (!empty($dr['nombre']) ? ' — ' . $dr['nombre'] : '');
            if ($rings) $geom = ['type'=>'MultiPolygon','coordinates'=>array_map(fn($ri)=>[$ri], $rings)];
        } elseif ($deleg !== '') {
            foreach (ej_poligonos($pdo) as $p) {
                if (ej_canon($p['n']) === ej_canon($deleg)) { $rings = $p['rings']; $geom = ['type'=>'MultiPolygon','coordinates'=>array_map(fn($ri)=>[$ri], $rings)]; break; }
            }
            $titulo = $deleg;
        }
        if (!$rings) { echo json_encode(['ok'=>false,'error'=>'Territorio sin geometría.']); exit; }
        $inside = fn($lat,$lng) => rec_pip($rings, $lat, $lng);
    }

    // Contexto electoral (si se pasó una sección, aun en modo trazo).
    if ($sec > 0) {
        $el = ej_electoral($pdo);
        if (isset($el['sec'][$sec])) { $part = $el['sec'][$sec]['part'] ?? null; $gan = $el['sec'][$sec]['gan'] ?? null; }
    }

    // Bounding-box (de los anillos o de la línea +/- buffer).
    $minLat=90;$maxLat=-90;$minLng=180;$maxLng=-180;
    if ($rings) {
        foreach ($rings as $ring) foreach ($ring as $pt) { $lng=$pt[0];$lat=$pt[1];
            $minLat=min($minLat,$lat);$maxLat=max($maxLat,$lat);$minLng=min($minLng,$lng);$maxLng=max($maxLng,$lng); }
    } elseif ($line) {
        foreach ($line as $pt) { $lat=$pt[0];$lng=$pt[1];
            $minLat=min($minLat,$lat);$maxLat=max($maxLat,$lat);$minLng=min($minLng,$lng);$maxLng=max($maxLng,$lng); }
        $dLat = $buffer/111320.0; $dLng = $buffer/(111320.0*max(0.1,cos(deg2rad(($minLat+$maxLat)/2))));
        $minLat-=$dLat;$maxLat+=$dLat;$minLng-=$dLng;$maxLng+=$dLng;
    }
    $center = ['lat'=>($minLat+$maxLat)/2,'lng'=>($minLng+$maxLng)/2];
    $bb = [$minLat,$maxLat,$minLng,$maxLng];

    // geomonly: geometría + contexto, sin puntos.
    if ($geomonly) {
        echo json_encode(['ok'=>true,'titulo'=>$titulo,'geom'=>$geom,'center'=>$center,'part'=>$part,'gan'=>$gan], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Carga de puntos: bbox en SQL + refinado por $inside (PIP / corredor).
    $collect = function(string $sql, callable $map) use ($pdo,$bb,$inside,$LIMIT): array {
        $out = []; $st = $pdo->prepare($sql); $st->execute([$bb[0],$bb[1],$bb[2],$bb[3]]);
        foreach ($st as $r) {
            $lat=(float)$r['lat']; $lng=(float)$r['lng']; if (!$lat || !$lng) continue;
            if (!$inside($lat,$lng)) continue;
            $out[] = $map($r,$lat,$lng);
            if (count($out) >= $LIMIT) break;
        }
        return $out;
    };
    $want = fn($k) => in_array($k, $layersReq, true);
    $layers = [];

    if ($want('tickets')) {
        $ticketFecha = $dias > 0 ? " AND t.fecha_creacion >= (CURDATE() - INTERVAL " . (int)$dias . " DAY)" : "";
        try {
            $layers['tickets'] = $collect(
                "SELECT t.ticket_id id, t.latitud lat, t.longitud lng, ts.nombre servicio, e.nombre estado,
                        DATEDIFF(CURDATE(), t.fecha_creacion) dias,
                        CASE WHEN e.es_resuelto=0 AND t.fecha_estimada < CURDATE() THEN 1 ELSE 0 END vencido,
                        t.colonia, t.direccion
                   FROM tickets t
                   LEFT JOIN cat_estado e ON e.id=t.estado_id
                   LEFT JOIN cat_tipo_servicio ts ON ts.id=t.tipo_servicio_id
                  WHERE t.latitud BETWEEN ? AND ? AND t.longitud BETWEEN ? AND ? AND e.es_resuelto = 0" . $ticketFecha,
                fn($r,$lat,$lng) => ['lat'=>round($lat,6),'lng'=>round($lng,6),'id'=>(int)$r['id'],
                    'tipo'=>$r['servicio'] ?: 'Reporte','estado'=>$r['estado'],'dias'=>(int)$r['dias'],
                    'vencido'=>(int)$r['vencido'],'dir'=>trim(($r['direccion'] ?? '').' '.($r['colonia'] ?? ''))]);
        } catch (Throwable $e) { $layers['tickets'] = []; }
    }
    if ($want('dif')) {
        try {
            $layers['dif'] = $collect(
                "SELECT id, latitud lat, longitud lng, programa, tipo_apoyo, ciudadano, colonia
                   FROM padron WHERE latitud BETWEEN ? AND ? AND longitud BETWEEN ? AND ?",
                function($r,$lat,$lng) use ($verPII) {
                    $p = ['lat'=>round($lat,6),'lng'=>round($lng,6),'prog'=>$r['programa'] ?: 'Apoyo DIF','apoyo'=>$r['tipo_apoyo'] ?: null];
                    if ($verPII) { $p['nombre']=$r['ciudadano'] ?: null; $p['col']=$r['colonia'] ?: null; }
                    return $p;
                });
        } catch (Throwable $e) { $layers['dif'] = []; }
    }
    if ($want('bloque')) {
        try {
            $layers['bloque'] = $collect(
                "SELECT id, dLatitud lat, dLongitud lng, sNombre, sPaterno, sMaterno,
                        sColonia, sCalle, sNumExterior, sEmpresa, sDelegacion
                   FROM bloque_usuario
                  WHERE dLatitud BETWEEN ? AND ? AND dLongitud BETWEEN ? AND ?",
                function($r,$lat,$lng) use ($verPII) {
                    $p = ['lat'=>round($lat,6),'lng'=>round($lng,6),
                          'emp'=>$r['sEmpresa'] ?: null,'deleg'=>$r['sDelegacion'] ?: null];
                    if ($verPII) {
                        $p['nombre'] = trim(($r['sNombre'] ?? '').' '.($r['sPaterno'] ?? '').' '.($r['sMaterno'] ?? '')) ?: null;
                        $p['col']    = $r['sColonia'] ?: null;
                        $p['dir']    = trim(($r['sCalle'] ?? '').' '.($r['sNumExterior'] ?? '')) ?: null;
                    }
                    return $p;
                });
        } catch (Throwable $e) { $layers['bloque'] = []; }
    }
    if ($want('obras')) {
        try {
            $layers['obras'] = $collect(
                "SELECT nombre, estatus, ejercido, lat, lng FROM obras
                  WHERE lat BETWEEN ? AND ? AND lng BETWEEN ? AND ? AND activo=1",
                fn($r,$lat,$lng) => ['lat'=>round($lat,6),'lng'=>round($lng,6),'n'=>$r['nombre'],
                    'estatus'=>$r['estatus'],'inv'=>$r['ejercido']!==null?(float)$r['ejercido']:null]);
        } catch (Throwable $e) { $layers['obras'] = []; }
    }
    if ($want('areas')) {
        try {
            $layers['areas'] = $collect(
                "SELECT nombre, lat, lng FROM areas_verdes
                  WHERE lat BETWEEN ? AND ? AND lng BETWEEN ? AND ? AND activo=1",
                fn($r,$lat,$lng) => ['lat'=>round($lat,6),'lng'=>round($lng,6),'n'=>$r['nombre']]);
        } catch (Throwable $e) { $layers['areas'] = []; }
    }

    $counts = []; foreach ($layers as $k=>$v) $counts[$k] = count($v);

    echo json_encode([
        'ok'=>true, 'titulo'=>$titulo, 'sec'=>$sec ?: null, 'dist'=>$dist ?: null,
        'deleg'=>$deleg ?: null, 'dias'=>$dias ?: null, 'shape'=>$shape ?: null,
        'part'=>$part, 'gan'=>$gan, 'verPII'=>$verPII,
        'center'=>$center, 'geom'=>$geom, 'counts'=>$counts, 'layers'=>$layers,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'No se pudieron cargar los datos.']);
}
