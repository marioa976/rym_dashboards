<?php
/**
 * Obras · librería: conexión (portal_qro) y consultas del POA.
 *
 * Tabla `obras`: id, num, clave, anio, nombre, rubro, delegacion, colonia,
 *   fecha_inicio, fecha_termino, ejercido, avance, estatus, ben_dir, ben_ind,
 *   maps_url, lat, lng, delegacion_geo, ficha, activo, creado_en.
 * La delegación autoritativa es la GEOMÉTRICA (`delegacion_geo`); el listado
 * (`delegacion`) puede decir "VARIAS".
 */
declare(strict_types=1);

function obr_config(): array {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $c;
}

function obr_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $db = obr_config()['db'];
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

/** Todas las obras activas (arreglos ligeros para el cliente). */
function obr_obras(PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT id, num, clave, anio, nombre, rubro,
                COALESCE(delegacion_geo, delegacion) deleg, delegacion delist, colonia,
                ejercido, avance, estatus, ben_dir, ben_ind, maps_url, lat, lng,
                DATE_FORMAT(fecha_inicio,'%Y-%m-%d') ini, DATE_FORMAT(fecha_termino,'%Y-%m-%d') fin
           FROM obras
          WHERE activo = 1
          ORDER BY anio DESC, clave"
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'  => (int)$r['id'],
            'c'   => $r['clave'],
            'y'   => (int)$r['anio'],
            'n'   => $r['nombre'],
            'r'   => $r['rubro'],
            'd'   => $r['deleg'],                 // delegación autoritativa (geo) o listado
            'dl'  => $r['delist'],                // etiqueta del listado (puede ser VARIAS)
            'col' => $r['colonia'],
            's'   => $r['estatus'],
            'e'   => $r['ejercido'] !== null ? (float)$r['ejercido'] : null,
            'av'  => $r['avance'] !== null ? (float)$r['avance'] : null,
            'bd'  => $r['ben_dir'] !== null ? (int)$r['ben_dir'] : null,
            'bi'  => $r['ben_ind'] !== null ? (int)$r['ben_ind'] : null,
            'ini' => $r['ini'], 'fin' => $r['fin'],
            'u'   => $r['maps_url'],
            'lat' => $r['lat'] !== null ? (float)$r['lat'] : null,
            'lng' => $r['lng'] !== null ? (float)$r['lng'] : null,
        ];
    }
    return $out;
}

/** KPIs globales del POA. */
function obr_kpis(PDO $pdo): array {
    $r = $pdo->query(
        "SELECT COUNT(*) total,
                SUM(lat IS NOT NULL) con_coords,
                SUM(ejercido) inversion,
                SUM(ben_dir) ben_dir,
                SUM(estatus='TERMINADA') terminadas,
                COUNT(DISTINCT anio) anios
           FROM obras WHERE activo = 1"
    )->fetch();
    return [
        'total'      => (int)$r['total'],
        'con_coords' => (int)$r['con_coords'],
        'inversion'  => (float)$r['inversion'],
        'ben_dir'    => (int)$r['ben_dir'],
        'terminadas' => (int)$r['terminadas'],
        'anios'      => (int)$r['anios'],
    ];
}

/**
 * Límites delegacionales oficiales (GeoJSON) para dibujar en el mapa.
 * Reutiliza la tabla `delegaciones_geo` (cargada por el módulo Áreas Verdes).
 */
function obr_limites(PDO $pdo): array {
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
