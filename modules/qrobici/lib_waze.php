<?php
/**
 * QroBici Analytics — Capa Waze Partner Hub
 * ------------------------------------------------------------
 * Obtiene el feed Waze for Cities (formato JSON), lo cachea
 * localmente y lo normaliza a una estructura limpia que
 * consume mapa_riesgos.php.
 *
 * Estructura del feed Waze (resumen):
 *   {
 *     "alerts": [
 *       {type, subtype, street, location:{x:lng,y:lat}, pubMillis,
 *        reliability, confidence, reportRating, uuid, ...}
 *     ],
 *     "jams": [
 *       {street, line:[{x:lng,y:lat},...], speedKMH, length,
 *        delay, level, severity, pubMillis, uuid, ...}
 *     ],
 *     "irregularities": [
 *       {street, line:[...], severity, speed, jamLevel, ...}
 *     ]
 *   }
 *
 * Importante: las coordenadas vienen como (x = longitud, y = latitud).
 */

declare(strict_types=1);

/* ============================================================
   FETCH + CACHE
   ============================================================ */

/**
 * Descarga el feed Waze. Cachea en /tmp respetando $cache_seg.
 * Devuelve el JSON crudo decodificado, o ['__error' => msg] si falla.
 */
function qrb_waze_fetch(string $url, int $cache_seg = 120, bool $force = false): array
{
    $cache_file = sys_get_temp_dir() . '/qrobici_waze_feed.json';

    if (!$force && $cache_seg > 0 && is_file($cache_file)
        && (time() - filemtime($cache_file)) < $cache_seg) {
        $raw = file_get_contents($cache_file);
        $j = json_decode($raw, true);
        if (is_array($j)) {
            $j['__source'] = 'cache';
            $j['__cache_age'] = time() - filemtime($cache_file);
            return $j;
        }
    }

    // fetch real con timeout y user-agent
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 15,
            'header'  => "User-Agent: QroBici-Analytics/1.0\r\nAccept: application/json\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return ['__error' => 'No se pudo conectar al endpoint de Waze (timeout o red).'];
    }

    $j = json_decode($body, true);
    if (!is_array($j)) {
        return ['__error' => 'Respuesta del feed Waze no es JSON válido (' . substr($body, 0, 120) . '...).'];
    }

    // guarda cache solo si fue exitoso
    @file_put_contents($cache_file, $body);

    $j['__source'] = 'live';
    $j['__cache_age'] = 0;
    return $j;
}

/* ============================================================
   NORMALIZACIÓN
   ============================================================ */

/**
 * Convierte una alerta Waze al formato compacto consumido por
 * el frontend. Maneja gracefully campos faltantes.
 */
function qrb_waze_norm_alert(array $a): ?array
{
    $loc = $a['location'] ?? null;
    if (!$loc || !isset($loc['x'], $loc['y'])) { return null; }
    $lat = (float)$loc['y'];
    $lng = (float)$loc['x'];
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) { return null; }
    return [
        'uuid'         => (string)($a['uuid'] ?? ''),
        'type'         => strtoupper((string)($a['type'] ?? 'OTHER')),
        'subtype'      => (string)($a['subtype'] ?? ''),
        'street'       => (string)($a['street'] ?? ''),
        'city'         => (string)($a['city'] ?? ''),
        'descripcion'  => (string)($a['reportDescription'] ?? ''),
        'lat'          => round($lat, 6),
        'lng'          => round($lng, 6),
        'reliability'  => (int)($a['reliability'] ?? 0),
        'confidence'   => (int)($a['confidence'] ?? 0),
        'rating'       => (int)($a['reportRating'] ?? 0),
        'pubMillis'    => (int)($a['pubMillis'] ?? 0),
        'roadType'     => (int)($a['roadType'] ?? 0),
        'magvar'       => (int)($a['magvar'] ?? 0),
    ];
}

/**
 * Normaliza un "jam" (tramo de tráfico). Devuelve una polilínea
 * en [lat,lng] (orden invertido respecto al formato Waze).
 */
function qrb_waze_norm_jam(array $j): ?array
{
    $line = $j['line'] ?? [];
    if (!is_array($line) || count($line) < 2) { return null; }
    $pts = [];
    foreach ($line as $p) {
        if (!isset($p['x'], $p['y'])) { continue; }
        $pts[] = [round((float)$p['y'], 6), round((float)$p['x'], 6)];
    }
    if (count($pts) < 2) { return null; }
    return [
        'uuid'         => (string)($j['uuid'] ?? ''),
        'street'       => (string)($j['street'] ?? ''),
        'city'         => (string)($j['city'] ?? ''),
        'level'        => (int)($j['level'] ?? 0),       // 0-5
        'severity'     => (int)($j['severity'] ?? 0),    // 0-3
        'speedKMH'     => round((float)($j['speedKMH'] ?? 0), 1),
        'lengthM'      => (int)($j['length'] ?? 0),
        'delaySec'     => (int)($j['delay'] ?? 0),
        'roadType'     => (int)($j['roadType'] ?? 0),
        'pubMillis'    => (int)($j['pubMillis'] ?? 0),
        'puntos'       => $pts,
    ];
}

/**
 * Normaliza una irregularidad. Igual que jam con campos extra
 * de tipo de irregularidad.
 */
function qrb_waze_norm_irreg(array $i): ?array
{
    $line = $i['line'] ?? [];
    if (!is_array($line) || count($line) < 2) { return null; }
    $pts = [];
    foreach ($line as $p) {
        if (!isset($p['x'], $p['y'])) { continue; }
        $pts[] = [round((float)$p['y'], 6), round((float)$p['x'], 6)];
    }
    if (count($pts) < 2) { return null; }
    return [
        'uuid'         => (string)($i['uuid'] ?? ''),
        'street'       => (string)($i['street'] ?? ''),
        'city'         => (string)($i['city'] ?? ''),
        'tipo'         => (string)($i['type'] ?? ''),
        'severity'     => (int)($i['severity'] ?? 0),
        'jamLevel'     => (int)($i['jamLevel'] ?? 0),
        'speed'        => round((float)($i['speed'] ?? 0), 1),
        'regularSpeed' => round((float)($i['regularSpeed'] ?? 0), 1),
        'delaySec'     => (int)($i['delaySeconds'] ?? 0),
        'puntos'       => $pts,
    ];
}

/* ============================================================
   FILTRO POR BOUNDING BOX
   ============================================================ */

function qrb_waze_en_bbox(float $lat, float $lng, array $bbox): bool
{
    return $lat >= $bbox[0] && $lat <= $bbox[1]
        && $lng >= $bbox[2] && $lng <= $bbox[3];
}

/* ============================================================
   ORQUESTADOR
   ============================================================ */

/**
 * Devuelve el dataset Waze listo para el frontend:
 *   [
 *     'ok'             => bool,
 *     'fuente'         => 'live' | 'cache',
 *     'cache_age'      => segundos,
 *     'tiempo_feed'    => fecha del feed (si está disponible),
 *     'alerts'         => [...],
 *     'jams'           => [...],
 *     'irregularities' => [...],
 *     'totales'        => ['alerts','jams','irreg','alerts_filt','jams_filt','irreg_filt'],
 *     'error'          => msg si ok=false,
 *   ]
 */
function qrb_construye_dataset_waze(array $cfg, bool $force = false): array
{
    $url   = $cfg['waze_feed_url']        ?? '';
    $ttl   = (int)($cfg['waze_cache_segundos'] ?? 120);
    $bbox  = $cfg['waze_bbox']            ?? [-90, 90, -180, 180];

    if (!$url) {
        return ['ok' => false, 'error' => 'waze_feed_url no configurada en config.php.'];
    }

    $raw = qrb_waze_fetch($url, $ttl, $force);
    if (!empty($raw['__error'])) {
        return ['ok' => false, 'error' => $raw['__error']];
    }

    // Procesar alerts
    $alerts = [];
    foreach (($raw['alerts'] ?? []) as $a) {
        $n = qrb_waze_norm_alert($a);
        if ($n === null) { continue; }
        if (!qrb_waze_en_bbox($n['lat'], $n['lng'], $bbox)) { continue; }
        $alerts[] = $n;
    }
    // ordenar más recientes primero
    usort($alerts, fn($a, $b) => $b['pubMillis'] <=> $a['pubMillis']);

    // Procesar jams
    $jams = [];
    foreach (($raw['jams'] ?? []) as $j) {
        $n = qrb_waze_norm_jam($j);
        if ($n === null) { continue; }
        // jam pasa filtro si al menos un punto está en bbox
        $en = false;
        foreach ($n['puntos'] as $p) {
            if (qrb_waze_en_bbox($p[0], $p[1], $bbox)) { $en = true; break; }
        }
        if (!$en) { continue; }
        $jams[] = $n;
    }
    usort($jams, fn($a, $b) => $b['severity'] <=> $a['severity']);

    // Procesar irregularidades
    $irreg = [];
    foreach (($raw['irregularities'] ?? []) as $i) {
        $n = qrb_waze_norm_irreg($i);
        if ($n === null) { continue; }
        $en = false;
        foreach ($n['puntos'] as $p) {
            if (qrb_waze_en_bbox($p[0], $p[1], $bbox)) { $en = true; break; }
        }
        if (!$en) { continue; }
        $irreg[] = $n;
    }
    usort($irreg, fn($a, $b) => $b['severity'] <=> $a['severity']);

    // Conteo por tipo de alerta para el resumen
    $by_type = [];
    foreach ($alerts as $a) {
        $by_type[$a['type']] = ($by_type[$a['type']] ?? 0) + 1;
    }
    arsort($by_type);

    return [
        'ok'             => true,
        'fuente'         => $raw['__source'] ?? 'unknown',
        'cache_age'      => $raw['__cache_age'] ?? 0,
        'tiempo_feed'    => $raw['endTime']   ?? null,
        'tiempo_inicio'  => $raw['startTime'] ?? null,
        'alerts'         => $alerts,
        'jams'           => $jams,
        'irregularities' => $irreg,
        'por_tipo'       => $by_type,
        'totales'        => [
            'alerts_brutos' => count($raw['alerts'] ?? []),
            'jams_brutos'   => count($raw['jams']   ?? []),
            'irreg_brutos'  => count($raw['irregularities'] ?? []),
            'alerts_filt'   => count($alerts),
            'jams_filt'     => count($jams),
            'irreg_filt'    => count($irreg),
        ],
    ];
}
