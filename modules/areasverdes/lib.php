<?php
/**
 * Áreas Verdes · librería: conexión (portal_qro) y consultas del catálogo.
 *
 * Tabla `areas_verdes`:
 *   id, no_orig, nombre, delegacion, delegacion_norm,
 *   lat DECIMAL(10,7), lng DECIMAL(10,7), puntos JSON, n_puntos, activo, creado_en
 */
declare(strict_types=1);

function av_config(): array {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $c;
}

function av_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $db = av_config()['db'];
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

/**
 * Todas las áreas verdes activas con coordenada, listas para el mapa/tabla.
 * Devuelve arreglos ligeros (claves cortas) para no inflar el JSON del cliente.
 *
 * La delegación autoritativa ('d') es la GEOMÉTRICA (`delegacion_geo`, calculada
 * por point-in-polygon contra los límites oficiales); si el área cayera fuera de
 * todo polígono se usa la del listado. 'dl' conserva la etiqueta del listado y
 * 'mm' marca cuando ambas difieren (etiqueta a revisar).
 */
function av_areas(PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT id, no_orig, nombre, delegacion, delegacion_geo, lat, lng, n_puntos, puntos
           FROM areas_verdes
          WHERE activo = 1 AND lat IS NOT NULL AND lng IS NOT NULL
          ORDER BY COALESCE(delegacion_geo, delegacion), nombre"
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $pts = null;
        if (($r['n_puntos'] ?? 1) > 1 && !empty($r['puntos'])) {
            $dec = json_decode((string)$r['puntos'], true);
            if (is_array($dec)) $pts = $dec;
        }
        $geo   = $r['delegacion_geo'] ?: null;
        $lista = $r['delegacion'];
        $out[] = [
            'id'  => (int)$r['id'],
            'no'  => $r['no_orig'] !== null ? (int)$r['no_orig'] : null,
            'n'   => $r['nombre'],
            'd'   => $geo ?: $lista,                                 // delegación autoritativa (geometría)
            'dl'  => $lista,                                         // etiqueta del listado original
            'mm'  => ($geo !== null && $geo !== $lista) ? 1 : 0,     // etiqueta a revisar
            'lat' => (float)$r['lat'],
            'lng' => (float)$r['lng'],
            'np'  => (int)$r['n_puntos'],
            'pts' => $pts,          // sólo cuando el área trae varios vértices
        ];
    }
    return $out;
}

/**
 * Conteo por delegación GEOMÉTRICA (para KPIs y leyenda), de mayor a menor.
 * Usa la delegación oficial; cae a la del listado sólo si no hubiera geometría.
 */
function av_por_delegacion(PDO $pdo): array {
    return $pdo->query(
        "SELECT COALESCE(delegacion_geo, delegacion) delegacion, COUNT(*) n
           FROM areas_verdes
          WHERE activo = 1
          GROUP BY COALESCE(delegacion_geo, delegacion)
          ORDER BY n DESC, delegacion"
    )->fetchAll();
}

/** Nº de áreas cuya etiqueta de delegación NO coincide con la geometría oficial. */
function av_num_discrepancias(PDO $pdo): int {
    return (int)$pdo->query(
        "SELECT COUNT(*) FROM areas_verdes
          WHERE activo = 1 AND delegacion_geo IS NOT NULL AND delegacion_geo <> delegacion"
    )->fetchColumn();
}

/**
 * Límites delegacionales oficiales como FeatureCollection GeoJSON (para el mapa).
 * Devuelve [] si aún no se han cargado los polígonos (tabla ausente/vacía).
 */
function av_limites(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT nombre, geojson FROM delegaciones_geo ORDER BY nombre")->fetchAll();
    } catch (Throwable $e) { return []; }
    $features = [];
    foreach ($rows as $r) {
        $geom = json_decode((string)$r['geojson'], true);
        if (!is_array($geom)) continue;
        $features[] = ['type' => 'Feature', 'geometry' => $geom, 'properties' => ['d' => $r['nombre']]];
    }
    return $features;
}
