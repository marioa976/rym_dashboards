<?php
/**
 * geocode.php  —  Completa latitud/longitud usando Google Maps Geocoding API.
 *
 * Estrategias (en orden de preferencia):
 *   1. colonia_cp     : colonia + cp + delegacion + estado + pais
 *   2. colonia        : colonia + delegacion + estado + pais
 *   3. calle_colonia  : calle + colonia + delegacion + estado + pais
 *   4. cp             : cp + estado + pais
 *
 * Cada consulta única se guarda en `geocode_cache` para no gastar API key dos veces.
 *
 * Modo recomendado ANTES de correr el batch:
 *   php geocode.php --test
 *      → hace UNA llamada con una dirección de prueba y muestra el JSON crudo
 *        para saber si la key/Geocoding API/billing está bien configurado.
 *
 * Uso:
 *   php geocode.php --test                 (valida la API key)
 *   php geocode.php --limit=20             (procesa sólo 20, para probar)
 *   php geocode.php                        (procesa todo lo NULL)
 *   php geocode.php --reintenta-errores    (vuelve a intentar status != OK)
 *   php geocode.php --solo-id=12345        (un registro puntual)
 *   php geocode.php --clear-cache          (vacía geocode_cache; los errores viejos quedaron envenenados)
 *   php geocode.php --clear-errores        (sólo limpia entradas con status != OK del cache)
 *   php geocode.php --reset-padron         (vuelve a NULL los geocodificados previamente con ERROR)
 *   php geocode.php --dry-run              (no toca BD, sólo imprime queries)
 */

declare(strict_types=1);

set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M');

if (!defined('STDOUT')) { define('STDOUT', fopen('php://output', 'wb')); }
if (!defined('STDERR')) { define('STDERR', fopen('php://output', 'wb')); }
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    @ob_implicit_flush(true);
    if (ob_get_level() > 0) @ob_end_flush();
}

$config = require __DIR__ . '/config.php';

// Endpoint de geocodificación (escribe coordenadas). Por web solo editor/admin.
if (PHP_SAPI !== 'cli') require_editor('dif');

$opts  = getopt('', [
    'test', 'limit::', 'reintenta-errores', 'solo-id::', 'dry-run',
    'clear-cache', 'clear-errores', 'reset-padron'
]);

$apiKey = $config['google_maps']['api_key'] ?? '';
if (!$apiKey || $apiKey === 'TU_GOOGLE_MAPS_API_KEY_AQUI') {
    fwrite(STDERR, "ERROR: configura tu Google Maps API Key en config.php\n");
    exit(1);
}

$db  = $config['db'];
$dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";
$pdo = new PDO($dsn, $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// ----------------------------------------------------------------------
// MODOS DE MANTENIMIENTO
// ----------------------------------------------------------------------
if (isset($opts['clear-cache'])) {
    $n = $pdo->exec("TRUNCATE TABLE geocode_cache");
    echo "Cache geocode_cache vaciado.\n";
    exit(0);
}
if (isset($opts['clear-errores'])) {
    $n = $pdo->exec("DELETE FROM geocode_cache WHERE status <> 'OK'");
    echo "Cache: $n entradas con error eliminadas.\n";
    exit(0);
}
if (isset($opts['reset-padron'])) {
    $n = $pdo->exec("UPDATE padron
                        SET geocode_status=NULL, geocode_estrategia=NULL,
                            geocode_precision=NULL, geocode_address=NULL,
                            geocode_at=NULL, geocode_intentos=0
                      WHERE (latitud IS NULL OR longitud IS NULL)
                        AND geocode_status IS NOT NULL");
    echo "Padron: $n filas con error reseteadas (intentos = 0).\n";
    exit(0);
}

// ----------------------------------------------------------------------
// MODO TEST: una sola llamada, imprime JSON crudo
// ----------------------------------------------------------------------
if (isset($opts['test'])) {
    echo "Validando API key con una dirección de prueba...\n\n";
    $direccion = "Centro Histórico, Querétaro, Querétaro, México";
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
        'address'    => $direccion,
        'key'        => $apiKey,
        'region'     => 'mx',
        'language'   => 'es',
        'components' => 'country:MX', // restringe estrictamente a México
    ]);
    echo "URL: " . preg_replace('/key=[^&]+/', 'key=REDACTED', $url) . "\n\n";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    echo "HTTP code: $http\n";
    if ($err) echo "cURL error: $err\n";
    echo "\n--- RESPUESTA DE GOOGLE ---\n";
    if ($body) {
        $json = json_decode((string)$body, true);
        if (is_array($json)) {
            echo "status:        " . ($json['status']  ?? '(no status)') . "\n";
            echo "error_message: " . ($json['error_message'] ?? '(ninguno)') . "\n";
            if (!empty($json['results'][0])) {
                $r = $json['results'][0];
                echo "lat,lng:       " . ($r['geometry']['location']['lat'] ?? '?') .
                     ", " . ($r['geometry']['location']['lng'] ?? '?') . "\n";
                echo "address:       " . ($r['formatted_address'] ?? '?') . "\n";
            }
            echo "\nJSON completo:\n" . json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            if (($json['status'] ?? '') === 'OK') {
                echo "\n[OK] La API key funciona. Puedes correr el geocodificador.\n";
            } else {
                echo "\n[ERROR] Revisa lo siguiente en Google Cloud Console:\n";
                echo "  1. Está habilitada la 'Geocoding API' para tu proyecto.\n";
                echo "  2. El proyecto tiene billing activado (Google Maps lo exige).\n";
                echo "  3. La key NO tiene restricciones de Application/HTTP referrer\n";
                echo "     que excluyan tu IP/CLI (para uso desde servidor usa 'None' o IP).\n";
                echo "  4. La key NO tiene restricciones de API que excluyan Geocoding.\n";
            }
        } else {
            echo $body . "\n";
        }
    }
    exit(0);
}

// ----------------------------------------------------------------------
// SELECCIÓN DE REGISTROS A PROCESAR
// ----------------------------------------------------------------------
$limit = isset($opts['limit'])    ? (int)$opts['limit']    : 0;
$soloId = isset($opts['solo-id']) ? (int)$opts['solo-id']  : 0;
$reintenta = isset($opts['reintenta-errores']);
$dryRun = isset($opts['dry-run']);

$where = "(latitud IS NULL OR longitud IS NULL)";
$params = [];

if ($reintenta) {
    $where = "(latitud IS NULL OR longitud IS NULL) OR (geocode_status IS NOT NULL AND geocode_status NOT IN ('OK','OK_ORIGEN'))";
}
if ($soloId > 0) {
    $where  = "id = :id";
    $params = [':id' => $soloId];
}

$sql = "SELECT id, cp, delegacion, colonia, calle_numero, geocode_intentos
          FROM padron
         WHERE $where
         ORDER BY id ASC";
if ($limit > 0) $sql .= " LIMIT " . (int)$limit;

$select = $pdo->prepare($sql);
$select->execute($params);

$updateStmt = $pdo->prepare("
    UPDATE padron
       SET latitud = :lat, longitud = :lng,
           geocode_status = :status,
           geocode_estrategia = :estrategia,
           geocode_precision = :precision,
           geocode_address = :address,
           geocode_source = 'google_maps',
           geocode_intentos = geocode_intentos + 1,
           geocode_at = NOW()
     WHERE id = :id
");

$markFailStmt = $pdo->prepare("
    UPDATE padron
       SET geocode_status = :status,
           geocode_intentos = geocode_intentos + 1,
           geocode_at = NOW()
     WHERE id = :id
");

$maxIntentos = (int)($config['google_maps']['max_intentos'] ?? 2);
$sleepUs     = (int)($config['google_maps']['sleep_us'] ?? 120000);

$procesados = 0;
$ok         = 0;
$sinResult  = 0;
$errores    = 0;
$cacheHits  = 0;
$primerError = null;

echo "Iniciando geocodificación...\n";
echo str_repeat('-', 60) . "\n";

while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
    $procesados++;

    if ((int)$row['geocode_intentos'] >= $maxIntentos) {
        continue;
    }

    [$query, $estrategia] = construirQuery($row, $config['google_maps']);
    if ($query === null) {
        $markFailStmt->execute([':id' => $row['id'], ':status' => 'SIN_DATOS']);
        $errores++;
        continue;
    }

    $resultado = obtenerGeocodeConCache($pdo, $query, $estrategia, $apiKey, $config['google_maps'], $sleepUs, $cacheHits);

    if ($dryRun) {
        echo sprintf("[DRY] id=%d  %s :: %s -> %s\n",
            $row['id'], $estrategia, $query, $resultado['status']);
        continue;
    }

    if ($resultado['status'] === 'OK' && $resultado['lat'] !== null) {
        $updateStmt->execute([
            ':lat'        => $resultado['lat'],
            ':lng'        => $resultado['lng'],
            ':status'     => 'OK',
            ':estrategia' => $estrategia,
            ':precision'  => $resultado['location_type'],
            ':address'    => $resultado['formatted_address'],
            ':id'         => $row['id'],
        ]);
        $ok++;
    } elseif ($resultado['status'] === 'ZERO_RESULTS') {
        $markFailStmt->execute([':id' => $row['id'], ':status' => 'ZERO_RESULTS']);
        $sinResult++;
    } else {
        $markFailStmt->execute([
            ':id'     => $row['id'],
            ':status' => 'ERROR:' . substr($resultado['status'], 0, 24),
        ]);
        $errores++;

        // Capturar primer error con el error_message real de Google
        if ($primerError === null && !empty($resultado['error_message'])) {
            $primerError = [
                'status'        => $resultado['status'],
                'error_message' => $resultado['error_message'],
                'query'         => $query,
            ];
            echo "\n!!! PRIMER ERROR DE GOOGLE !!!\n";
            echo "  status:        " . $resultado['status'] . "\n";
            echo "  error_message: " . $resultado['error_message'] . "\n";
            echo "  query:         " . $query . "\n";
            echo "  >>> Detén con Ctrl+C y revisa Google Cloud Console <<<\n\n";
        }
    }

    if ($procesados % 50 === 0) {
        echo sprintf("  ... %d  (OK:%d  ZERO:%d  ERR:%d  cache:%d)\n",
            $procesados, $ok, $sinResult, $errores, $cacheHits);
    }
}

echo str_repeat('-', 60) . "\n";
echo "TERMINADO\n";
echo "  Registros procesados : $procesados\n";
echo "  Geocodificados OK    : $ok\n";
echo "  Sin resultado        : $sinResult\n";
echo "  Errores              : $errores\n";
echo "  Cache hits           : $cacheHits\n";


// ======================================================================
// FUNCIONES
// ======================================================================

function construirQuery(array $row, array $gm): array
{
    $estado = $gm['default_estado'] ?? 'Querétaro';
    $pais   = $gm['default_pais']   ?? 'México';

    $calle      = limpiarParte($row['calle_numero'] ?? null);
    $colonia    = limpiarParte($row['colonia']       ?? null);
    $cp         = limpiarParte($row['cp']            ?? null);
    $delegacion = limpiarParte($row['delegacion']    ?? null);

    if ($calle && preg_match('/domicilio\s+conocido|sin\s+dato|^sn$|^s\/n$/iu', $calle)) {
        $calle = null;
    }

    $base = trim(($delegacion ? "$delegacion, " : '') . "$estado, $pais", ', ');

    // Priorizamos colonia (como pidió Mario): colonia+cp > colonia > calle+colonia > cp
    if ($colonia && $cp) {
        return [implode(', ', array_filter([$colonia, "CP $cp", $base])), 'colonia_cp'];
    }
    if ($colonia) {
        return [implode(', ', array_filter([$colonia, $base])), 'colonia'];
    }
    if ($calle && $colonia) {
        return [implode(', ', array_filter([$calle, $colonia, $base])), 'calle_colonia'];
    }
    if ($cp) {
        return [implode(', ', array_filter(["CP $cp", $base])), 'cp'];
    }
    return [null, 'sin_datos'];
}

function limpiarParte(?string $v): ?string
{
    if ($v === null) return null;
    $v = trim($v);
    if ($v === '') return null;
    $v = preg_replace('/\s+/u', ' ', $v);
    return $v;
}

function obtenerGeocodeConCache(PDO $pdo, string $query, string $estrategia, string $apiKey, array $gm, int $sleepUs, int &$cacheHits): array
{
    $hash = hash('sha256', mb_strtolower($estrategia . '|' . $query, 'UTF-8'));

    $stmt = $pdo->prepare("SELECT status, latitud, longitud, location_type, formatted_address
                             FROM geocode_cache WHERE query_hash = ? LIMIT 1");
    $stmt->execute([$hash]);
    if ($cached = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cacheHits++;
        return [
            'status'            => $cached['status'],
            'lat'               => $cached['latitud']  !== null ? (float)$cached['latitud']  : null,
            'lng'               => $cached['longitud'] !== null ? (float)$cached['longitud'] : null,
            'location_type'     => $cached['location_type'],
            'formatted_address' => $cached['formatted_address'],
            'error_message'     => null,
        ];
    }

    $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
        'address'    => $query,
        'key'        => $apiKey,
        'region'     => $gm['region']   ?? 'mx',
        'language'   => $gm['language'] ?? 'es',
        'components' => 'country:MX', // restringe estrictamente a México
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($sleepUs > 0) usleep($sleepUs);

    $resultado = [
        'status'            => 'ERROR_HTTP',
        'lat'               => null,
        'lng'               => null,
        'location_type'     => null,
        'formatted_address' => null,
        'error_message'     => $err ?: null,
    ];

    if ($body === false || $http >= 400) {
        $resultado['status'] = 'ERROR_HTTP_' . $http;
        guardarCache($pdo, $hash, $query, $estrategia, $resultado, $err ?: $body);
        return $resultado;
    }

    $json = json_decode((string)$body, true);
    if (!is_array($json)) {
        $resultado['status'] = 'ERROR_JSON';
        guardarCache($pdo, $hash, $query, $estrategia, $resultado, $body);
        return $resultado;
    }

    $status = $json['status'] ?? 'ERROR';
    $resultado['error_message'] = $json['error_message'] ?? null;

    if ($status === 'OK' && !empty($json['results'][0])) {
        $r = $json['results'][0];
        $resultado = [
            'status'            => 'OK',
            'lat'               => isset($r['geometry']['location']['lat']) ? (float)$r['geometry']['location']['lat'] : null,
            'lng'               => isset($r['geometry']['location']['lng']) ? (float)$r['geometry']['location']['lng'] : null,
            'location_type'     => $r['geometry']['location_type'] ?? null,
            'formatted_address' => $r['formatted_address'] ?? null,
            'error_message'     => null,
        ];
    } else {
        $resultado['status'] = $status;
    }

    guardarCache($pdo, $hash, $query, $estrategia, $resultado, $body);

    if ($status === 'OVER_QUERY_LIMIT') sleep(2);

    return $resultado;
}

function guardarCache(PDO $pdo, string $hash, string $query, string $estrategia, array $resultado, ?string $raw): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO geocode_cache
                (query_hash, query_text, estrategia, status, latitud, longitud,
                 formatted_address, location_type, raw_response)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status=VALUES(status)
        ");
        $stmt->execute([
            $hash, $query, $estrategia, $resultado['status'],
            $resultado['lat'], $resultado['lng'],
            $resultado['formatted_address'], $resultado['location_type'],
            $raw,
        ]);
    } catch (Throwable $e) {
        fwrite(STDERR, "WARN cache: " . $e->getMessage() . "\n");
    }
}
