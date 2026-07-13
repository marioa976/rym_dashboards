<?php
/**
 * Bloque · librería: conexión (portal_qro) e INTROSPECCIÓN del esquema.
 *
 * Estructura conocida:
 *   v_usuarios (vista de usuarios_bloque): usuario_id, account_type, nombre, apellido,
 *      segundo_apellido, correo, telefono, edad, curp, fecha_nacimiento, genero,
 *      estado, municipio, delegacion
 *   asistencias: asistencia_id, usuario_id, dependiente_id, actividad_id, sesion_id,
 *      asistencia_estatus ENUM('present','absent'), checkin_id
 *   actividades / sesiones / dependientes: estructura variable → se detecta en runtime.
 */
declare(strict_types=1);

function bl_config(): array {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $c;
}

function bl_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $db = bl_config()['db'];
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

/** ¿Existe la tabla o vista? */
function bl_existe(PDO $pdo, string $t): bool {
    static $c = [];
    if (isset($c[$t])) return $c[$t];
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema = DATABASE() AND table_name = ?");
    $st->execute([$t]);
    return $c[$t] = ((int)$st->fetchColumn() > 0);
}

/** Columnas (en minúscula) de una tabla/vista. */
function bl_cols(PDO $pdo, string $t): array {
    static $c = [];
    if (isset($c[$t])) return $c[$t];
    try {
        $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.columns
                              WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$t]);
        return $c[$t] = array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return $c[$t] = []; }
}

/** Primera columna candidata que exista (o $def). */
function bl_pick(PDO $pdo, string $t, array $cands, ?string $def = null): ?string {
    $cols = bl_cols($pdo, $t);
    foreach ($cands as $x) if (in_array(strtolower($x), $cols, true)) return $x;
    return $def;
}

/** Metadatos detectados del esquema variable (actividades / sesiones). */
function bl_meta(PDO $pdo): array {
    static $m = null;
    if ($m !== null) return $m;
    $haySes = bl_existe($pdo, 'sesiones');
    $m = [
        'hay_actividades' => bl_existe($pdo, 'actividades'),
        'act_nombre' => bl_pick($pdo, 'actividades', ['nombre','titulo','actividad_nombre','name','descripcion','title']),
        'act_cat'    => bl_pick($pdo, 'actividades', ['categoria','tipo','area','tipo_actividad','clasificacion','category']),
        'act_fecha'  => bl_pick($pdo, 'actividades', ['fecha','fecha_inicio','fecha_actividad','inicio','start_date','created_at']),
        'act_cupo'   => bl_pick($pdo, 'actividades', ['cupo','capacidad','cupo_maximo','max_participantes']),
        'hay_sesiones' => $haySes,
        'ses_fecha'  => $haySes ? bl_pick($pdo, 'sesiones', ['fecha','fecha_sesion','inicio','fecha_inicio','start_date','created_at']) : null,
        'ses_pk'     => $haySes ? bl_pick($pdo, 'sesiones', ['sesion_id','id']) : null,
    ];
    return $m;
}

/** Expresión SQL para el nombre legible de la actividad (o su id si no hay nombre). */
function bl_expr_actividad(PDO $pdo, string $alias = 'a'): string {
    $m = bl_meta($pdo);
    return $m['act_nombre']
        ? "COALESCE(NULLIF(TRIM($alias.`{$m['act_nombre']}`),''), CONCAT('Actividad #', $alias.actividad_id))"
        : "CONCAT('Actividad #', $alias.actividad_id)";
}

/** Expresión de fecha para tendencias (sesión si existe, si no la actividad). */
function bl_expr_fecha(PDO $pdo): ?array {
    $m = bl_meta($pdo);
    if ($m['hay_sesiones'] && $m['ses_fecha'] && $m['ses_pk']) {
        return ['join' => "LEFT JOIN sesiones s ON s.`{$m['ses_pk']}` = asi.sesion_id",
                'expr' => "s.`{$m['ses_fecha']}`"];
    }
    if ($m['act_fecha']) {
        return ['join' => "LEFT JOIN actividades a ON a.actividad_id = asi.actividad_id",
                'expr' => "a.`{$m['act_fecha']}`"];
    }
    return null;
}
