<?php
/**
 * Qrobus · librería: conexión a la BD remota (dwh_unidos), caché de
 * geocodificación (auto-creada en la misma BD remota) y geocodificador
 * Google Maps con caché — reutiliza la lógica probada del módulo DIF.
 */
declare(strict_types=1);

function qb_config(): array {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $c;
}

function qb_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $db = qb_config()['db'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'], (int)$db['port'], $db['name'], $db['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ]);
    return $pdo;
}

/** Nombre de tabla validado (evita inyección en identificadores). */
function qb_tabla(): string {
    $t = qb_config()['tabla'] ?? 'dwh_unidos';
    return preg_match('/^[A-Za-z0-9_]+$/', $t) ? $t : 'dwh_unidos';
}

/**
 * ¿Existe la tabla de caché geocode_cache? (No la creamos: el usuario de la BD
 * remota no tiene permiso CREATE. Si existe se usa; si no, se geocodifica sin caché.)
 */
function qb_cache_ok(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'geocode_cache'")->fetchColumn();
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** Arma la dirección a geocodificar a partir de los campos del beneficiario. */
function qb_query_dir(array $r): string {
    $partes = [];
    foreach (['calle', 'colonia', 'municipio'] as $k) {
        $v = trim((string)($r[$k] ?? ''));
        if ($v !== '') $partes[] = $v;
    }
    $cp = trim((string)($r['cp'] ?? ''));
    if ($cp !== '') $partes[] = 'C.P. ' . $cp;
    if (!$partes) return '';
    $partes[] = 'Querétaro, México';
    return implode(', ', $partes);
}

/** Geocodifica con caché (idéntico patrón al del DIF). */
function qb_geocode(PDO $pdo, string $query, string $apiKey, array $gm = [], int $sleepUs = 0): array {
    $usarCache = qb_cache_ok($pdo);
    $est  = 'unidos';
    $hash = hash('sha256', mb_strtolower($est . '|' . $query, 'UTF-8'));

    if ($usarCache) {
        try {
            $st = $pdo->prepare("SELECT status, latitud, longitud, location_type, formatted_address
                                   FROM geocode_cache WHERE query_hash=? LIMIT 1");
            $st->execute([$hash]);
            if ($c = $st->fetch()) {
                return ['status'=>$c['status'], 'lat'=>$c['latitud']!==null?(float)$c['latitud']:null,
                        'lng'=>$c['longitud']!==null?(float)$c['longitud']:null,
                        'location_type'=>$c['location_type'], 'formatted_address'=>$c['formatted_address'],
                        'error_message'=>null, 'cache_hit'=>true];
            }
        } catch (Throwable $e) { $usarCache = false; }  // sin permiso de lectura → seguimos sin caché
    }

    $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
        'address'    => $query,
        'key'        => $apiKey,
        'region'     => $gm['region']   ?? 'mx',
        'language'   => $gm['language'] ?? 'es',
        'components' => 'country:MX',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_CONNECTTIMEOUT=>8]);
    $body = curl_exec($ch); $err = curl_error($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($sleepUs > 0) usleep($sleepUs);

    $out = ['status'=>'ERROR_HTTP', 'lat'=>null, 'lng'=>null, 'location_type'=>null,
            'formatted_address'=>null, 'error_message'=>$err ?: null, 'cache_hit'=>false];
    if ($body === false || $http >= 400) { $out['status']='ERROR_HTTP_'.$http; if ($usarCache) qb_guardar_cache($pdo,$hash,$query,$est,$out,$err ?: $body); return $out; }
    $json = json_decode((string)$body, true);
    if (!is_array($json)) { $out['status']='ERROR_JSON'; if ($usarCache) qb_guardar_cache($pdo,$hash,$query,$est,$out,$body); return $out; }
    $sg = $json['status'] ?? 'ERROR';
    $out['error_message'] = $json['error_message'] ?? null;
    if ($sg === 'OK' && !empty($json['results'][0])) {
        $g = $json['results'][0];
        $out = ['status'=>'OK', 'lat'=>(float)($g['geometry']['location']['lat'] ?? 0),
                'lng'=>(float)($g['geometry']['location']['lng'] ?? 0),
                'location_type'=>$g['geometry']['location_type'] ?? null,
                'formatted_address'=>$g['formatted_address'] ?? null, 'error_message'=>null, 'cache_hit'=>false];
    } else { $out['status'] = $sg; }
    if ($usarCache) qb_guardar_cache($pdo, $hash, $query, $est, $out, $body);
    if ($sg === 'OVER_QUERY_LIMIT') sleep(2);
    return $out;
}

function qb_guardar_cache(PDO $pdo, string $hash, string $query, string $est, array $r, ?string $raw): void {
    try {
        $st = $pdo->prepare("INSERT INTO geocode_cache
                (query_hash, query_text, estrategia, status, latitud, longitud, formatted_address, location_type, raw_response)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status=VALUES(status), latitud=VALUES(latitud), longitud=VALUES(longitud),
                                    formatted_address=VALUES(formatted_address), location_type=VALUES(location_type)");
        $st->execute([$hash, $query, $est, $r['status'], $r['lat'], $r['lng'],
                      $r['formatted_address'] ?? null, $r['location_type'] ?? null, $raw]);
    } catch (Throwable $e) { /* la caché no debe romper el flujo */ }
}

/** Estadísticas de geocodificación de la tabla de beneficiarios. */
function qb_stats(PDO $pdo): array {
    $t = qb_tabla();
    $con = "latitud IS NOT NULL AND latitud <> 0 AND longitud IS NOT NULL AND longitud <> 0";
    $conDir = "(TRIM(COALESCE(calle,''))<>'' OR TRIM(COALESCE(colonia,''))<>'' OR TRIM(COALESCE(municipio,''))<>'')";
    $q = fn($w) => (int)$pdo->query("SELECT COUNT(*) FROM `$t`" . ($w ? " WHERE $w" : ''))->fetchColumn();
    return [
        'total'      => $q(''),
        'con_coords' => $q($con),
        'sin_coords' => $q("NOT ($con)"),
        'pendientes' => $q("NOT ($con) AND $conDir"),
    ];
}
