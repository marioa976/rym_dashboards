<?php
/**
 * Ejecutivo · Recorrido territorial — datos de una sección (o delegación).
 * Devuelve, para el territorio pedido, los puntos de cada capa CON metadata,
 * acotados por bounding-box + point-in-polygon en PHP (el subconjunto es chico,
 * así que no se muestrea como en el heatmap).
 *
 * Uso:  recorrido_data.php?sec=123     ó    recorrido_data.php?deleg=Centro%20Histórico
 * Guard: require_module('ejecutivo').  PII (nombres/domicilio de beneficiarios)
 * solo se incluye si el usuario es editor/admin del módulo.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';     // guard: require_module('ejecutivo')
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

$verPII = function_exists('puede_editar') && puede_editar('ejecutivo');
$sec    = isset($_GET['sec'])   ? (int)$_GET['sec']            : 0;
$deleg  = isset($_GET['deleg']) ? trim((string)$_GET['deleg']) : '';
$LIMIT  = 4000;   // tope duro por capa (una delegación grande no debe reventar)

/** Ray-casting: ¿el punto cae dentro de alguno de los anillos? */
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

/** Extrae los anillos exteriores de una geometría GeoJSON (Polygon/MultiPolygon). */
if (!function_exists('rec_rings_from_geojson')) {
function rec_rings_from_geojson(array $g): array {
    $rings = [];
    if (($g['type'] ?? '') === 'Polygon')            { $rings[] = $g['coordinates'][0] ?? []; }
    elseif (($g['type'] ?? '') === 'MultiPolygon')   { foreach ($g['coordinates'] as $poly) $rings[] = $poly[0] ?? []; }
    return array_values(array_filter($rings, fn($r) => count($r) >= 3));
}
}

try {
    $pdo = ej_pdo();

    // --- 1) Geometría del territorio + anillos + bbox --------------------
    $rings = []; $geom = null; $titulo = ''; $part = null; $gan = null;

    if ($sec > 0) {
        $st = $pdo->prepare("SELECT ST_AsGeoJSON(g.geom,6) gj
                               FROM secciones s JOIN secciones_geo g ON g.seccion_id=s.id
                              WHERE s.num_seccion=? LIMIT 1");
        $st->execute([$sec]);
        $gj = $st->fetchColumn();
        if ($gj) { $geom = json_decode((string)$gj, true); if (is_array($geom)) $rings = rec_rings_from_geojson($geom); }
        $titulo = "Sección $sec";
        // contexto electoral (cacheado)
        $el = ej_electoral($pdo);
        if (isset($el['sec'][$sec])) { $part = $el['sec'][$sec]['part'] ?? null; $gan = $el['sec'][$sec]['gan'] ?? null; }
    } elseif ($deleg !== '') {
        foreach (ej_poligonos($pdo) as $p) {
            if (ej_canon($p['n']) === ej_canon($deleg)) {
                $rings = $p['rings'];
                $geom = ['type'=>'MultiPolygon','coordinates'=>array_map(fn($r)=>[$r], $rings)];
                break;
            }
        }
        $titulo = $deleg;
    }

    if (!$rings) { echo json_encode(['ok'=>false,'error'=>'Territorio sin geometría.']); exit; }

    // bbox
    $minLat=90; $maxLat=-90; $minLng=180; $maxLng=-180;
    foreach ($rings as $ring) foreach ($ring as $pt) {
        $lng=$pt[0]; $lat=$pt[1];
        if ($lat<$minLat)$minLat=$lat; if ($lat>$maxLat)$maxLat=$lat;
        if ($lng<$minLng)$minLng=$lng; if ($lng>$maxLng)$maxLng=$lng;
    }
    $cLat = ($minLat+$maxLat)/2; $cLng = ($minLng+$maxLng)/2;
    $bb = [$minLat,$maxLat,$minLng,$maxLng];

    // helper: recorre filas de una query bbox y filtra por PIP
    $collect = function(string $sql, callable $map) use ($pdo,$bb,$rings,$LIMIT): array {
        $out = [];
        $st = $pdo->prepare($sql);
        $st->execute([$bb[0],$bb[1],$bb[2],$bb[3]]);
        foreach ($st as $r) {
            $lat=(float)$r['lat']; $lng=(float)$r['lng'];
            if (!$lat || !$lng) continue;
            if (!rec_pip($rings,$lat,$lng)) continue;
            $out[] = $map($r,$lat,$lng);
            if (count($out) >= $LIMIT) break;
        }
        return $out;
    };

    $layers = [];

    // --- Tickets abiertos (problemas) -----------------------------------
    try {
        $layers['tickets'] = $collect(
            "SELECT t.ticket_id id, t.latitud lat, t.longitud lng,
                    ts.nombre servicio, e.nombre estado,
                    DATEDIFF(CURDATE(), t.fecha_creacion) dias,
                    CASE WHEN e.es_resuelto=0 AND t.fecha_estimada < CURDATE() THEN 1 ELSE 0 END vencido,
                    t.colonia, t.direccion
               FROM tickets t
               LEFT JOIN cat_estado e ON e.id=t.estado_id
               LEFT JOIN cat_tipo_servicio ts ON ts.id=t.tipo_servicio_id
              WHERE t.latitud BETWEEN ? AND ? AND t.longitud BETWEEN ? AND ?
                AND e.es_resuelto = 0",
            fn($r,$lat,$lng) => [
                'lat'=>round($lat,6),'lng'=>round($lng,6),
                'id'=>(int)$r['id'],'tipo'=>$r['servicio'] ?: 'Reporte',
                'estado'=>$r['estado'],'dias'=>(int)$r['dias'],'vencido'=>(int)$r['vencido'],
                'dir'=>trim(($r['direccion'] ?? '').' '.($r['colonia'] ?? '')),
            ]);
    } catch (Throwable $e) { $layers['tickets'] = []; }

    // --- Beneficiarios DIF (padrón) -------------------------------------
    try {
        $layers['dif'] = $collect(
            "SELECT id, latitud lat, longitud lng, programa, tipo_apoyo, ciudadano, colonia
               FROM padron
              WHERE latitud BETWEEN ? AND ? AND longitud BETWEEN ? AND ?",
            function($r,$lat,$lng) use ($verPII) {
                $p = ['lat'=>round($lat,6),'lng'=>round($lng,6),
                      'prog'=>$r['programa'] ?: 'Apoyo DIF','apoyo'=>$r['tipo_apoyo'] ?: null];
                if ($verPII) { $p['nombre']=$r['ciudadano'] ?: null; $p['col']=$r['colonia'] ?: null; }
                return $p;
            });
    } catch (Throwable $e) { $layers['dif'] = []; }

    // --- Obras públicas --------------------------------------------------
    try {
        $layers['obras'] = $collect(
            "SELECT nombre, estatus, ejercido, lat, lng FROM obras
              WHERE lat BETWEEN ? AND ? AND lng BETWEEN ? AND ? AND activo=1",
            fn($r,$lat,$lng) => [
                'lat'=>round($lat,6),'lng'=>round($lng,6),
                'n'=>$r['nombre'],'estatus'=>$r['estatus'],
                'inv'=>$r['ejercido']!==null?(float)$r['ejercido']:null,
            ]);
    } catch (Throwable $e) { $layers['obras'] = []; }

    // --- Áreas verdes ----------------------------------------------------
    try {
        $layers['areas'] = $collect(
            "SELECT nombre, lat, lng FROM areas_verdes
              WHERE lat BETWEEN ? AND ? AND lng BETWEEN ? AND ? AND activo=1",
            fn($r,$lat,$lng) => ['lat'=>round($lat,6),'lng'=>round($lng,6),'n'=>$r['nombre']]);
    } catch (Throwable $e) { $layers['areas'] = []; }

    $counts = [];
    foreach ($layers as $k=>$v) $counts[$k] = count($v);

    echo json_encode([
        'ok'=>true, 'titulo'=>$titulo, 'sec'=>$sec ?: null, 'deleg'=>$deleg ?: null,
        'part'=>$part, 'gan'=>$gan, 'verPII'=>$verPII,
        'center'=>['lat'=>$cLat,'lng'=>$cLng], 'geom'=>$geom,
        'counts'=>$counts, 'layers'=>$layers,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'No se pudieron cargar los datos del territorio.']);
}
