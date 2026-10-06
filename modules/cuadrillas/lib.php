<?php
/**
 * Cuadrillas · librería (BD unificada portal_qro).
 *
 * Crea su esquema la primera vez (CREATE TABLE IF NOT EXISTS) para no depender
 * de correr sql/cuadrillas.sql a mano. Expone el PDO del portal y el CRUD del
 * padrón (cuadrillas + operadores). Paradas = tickets de Zendesk; evidencias
 * viven en Google Cloud Storage (en BD solo la URL).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/guard.php';

/** PDO del portal (BD unificada). */
function cuad_pdo(): PDO { return Database::conn(); }

/** Crea las tablas del módulo si no existen (idempotente, barato). */
function cuad_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $ddl = [
    "CREATE TABLE IF NOT EXISTS cuadrilla (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        nombre VARCHAR(120) NOT NULL,
        zona_base VARCHAR(160) NULL,
        color CHAR(7) NOT NULL DEFAULT '#0f766e',
        activa TINYINT(1) NOT NULL DEFAULT 1,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_activa (activa)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS cuadrilla_operador (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        cuadrilla_id INT UNSIGNED NULL,
        nombre VARCHAR(160) NOT NULL,
        telefono VARCHAR(32) NULL,
        usuario VARCHAR(80) NOT NULL,
        pass_hash VARCHAR(255) NOT NULL,
        rol ENUM('lider','operador') NOT NULL DEFAULT 'operador',
        activo TINYINT(1) NOT NULL DEFAULT 1,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY uq_usuario (usuario), KEY idx_cuadrilla (cuadrilla_id),
        CONSTRAINT fk_oper_cuadrilla FOREIGN KEY (cuadrilla_id)
          REFERENCES cuadrilla(id) ON DELETE SET NULL ON UPDATE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS operador_sesion (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        operador_id INT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        device VARCHAR(160) NULL,
        fcm_token VARCHAR(255) NULL,
        expira_en DATETIME NULL,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ult_uso_en TIMESTAMP NULL,
        PRIMARY KEY (id), UNIQUE KEY uq_token (token_hash), KEY idx_oper (operador_id),
        CONSTRAINT fk_sesion_oper FOREIGN KEY (operador_id)
          REFERENCES cuadrilla_operador(id) ON DELETE CASCADE ON UPDATE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS orden (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        plan_id INT UNSIGNED NULL,
        cuadrilla_id INT UNSIGNED NULL,
        fecha DATE NOT NULL,
        titulo VARCHAR(160) NOT NULL,
        estatus ENUM('borrador','despachada','en_proceso','cerrada','cancelada') NOT NULL DEFAULT 'borrador',
        n_paradas INT NOT NULL DEFAULT 0,
        n_resueltas INT NOT NULL DEFAULT 0,
        km DECIMAL(10,1) NOT NULL DEFAULT 0,
        creado_por INT UNSIGNED NULL,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        despachada_en DATETIME NULL,
        cerrada_en DATETIME NULL,
        PRIMARY KEY (id), KEY idx_cuadrilla_fecha (cuadrilla_id, fecha), KEY idx_estatus (estatus),
        CONSTRAINT fk_orden_cuadrilla FOREIGN KEY (cuadrilla_id)
          REFERENCES cuadrilla(id) ON DELETE SET NULL ON UPDATE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS orden_parada (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        orden_id INT UNSIGNED NOT NULL,
        idx SMALLINT NOT NULL DEFAULT 0,
        ticket_id BIGINT NULL,
        titulo VARCHAR(255) NULL,
        direccion VARCHAR(255) NULL,
        lat DECIMAL(10,7) NULL,
        lng DECIMAL(10,7) NULL,
        estatus ENUM('pendiente','en_camino','en_sitio','resuelta','no_resuelta') NOT NULL DEFAULT 'pendiente',
        motivo_no VARCHAR(255) NULL,
        resuelta_en DATETIME NULL,
        actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_orden (orden_id, idx), KEY idx_ticket (ticket_id), KEY idx_estatus (estatus),
        CONSTRAINT fk_parada_orden FOREIGN KEY (orden_id)
          REFERENCES orden(id) ON DELETE CASCADE ON UPDATE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS parada_evidencia (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        parada_id INT UNSIGNED NOT NULL,
        url VARCHAR(512) NOT NULL,
        tipo ENUM('antes','despues','otro') NOT NULL DEFAULT 'otro',
        nota VARCHAR(500) NULL,
        lat DECIMAL(10,7) NULL,
        lng DECIMAL(10,7) NULL,
        operador_id INT UNSIGNED NULL,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_parada (parada_id),
        CONSTRAINT fk_evid_parada FOREIGN KEY (parada_id)
          REFERENCES orden_parada(id) ON DELETE CASCADE ON UPDATE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS orden_evento (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        orden_id INT UNSIGNED NOT NULL,
        parada_id INT UNSIGNED NULL,
        operador_id INT UNSIGNED NULL,
        tipo VARCHAR(40) NOT NULL,
        detalle JSON NULL,
        lat DECIMAL(10,7) NULL,
        lng DECIMAL(10,7) NULL,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_orden (orden_id, creado_en), KEY idx_parada (parada_id)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($ddl as $sql) { $pdo->exec($sql); }
    $done = true;
}

/* ============================================================
   PADRÓN · Cuadrillas
   ============================================================ */

function cuad_cuadrillas(PDO $pdo, bool $solo_activas = false): array
{
    $w = $solo_activas ? 'WHERE activa = 1' : '';
    return $pdo->query(
        "SELECT c.*, (SELECT COUNT(*) FROM cuadrilla_operador o WHERE o.cuadrilla_id=c.id AND o.activo=1) AS n_operadores
           FROM cuadrilla c $w ORDER BY c.activa DESC, c.nombre"
    )->fetchAll(PDO::FETCH_ASSOC);
}

function cuad_cuadrilla_guardar(PDO $pdo, array $d): int
{
    $id     = (int)($d['id'] ?? 0);
    $nombre = trim((string)($d['nombre'] ?? ''));
    $zona   = trim((string)($d['zona_base'] ?? '')) ?: null;
    $color  = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($d['color'] ?? '')) ? $d['color'] : '#0f766e';
    $activa = !empty($d['activa']) ? 1 : 0;
    if ($nombre === '') { throw new InvalidArgumentException('El nombre de la cuadrilla es obligatorio.'); }

    if ($id > 0) {
        $pdo->prepare("UPDATE cuadrilla SET nombre=?, zona_base=?, color=?, activa=? WHERE id=?")
            ->execute([$nombre, $zona, $color, $activa, $id]);
        return $id;
    }
    $pdo->prepare("INSERT INTO cuadrilla (nombre, zona_base, color, activa) VALUES (?,?,?,?)")
        ->execute([$nombre, $zona, $color, $activa]);
    return (int)$pdo->lastInsertId();
}

/* ============================================================
   PADRÓN · Operadores (login propio para la app)
   ============================================================ */

function cuad_operadores(PDO $pdo): array
{
    return $pdo->query(
        "SELECT o.id, o.cuadrilla_id, o.nombre, o.telefono, o.usuario, o.rol, o.activo, o.creado_en,
                c.nombre AS cuadrilla
           FROM cuadrilla_operador o
           LEFT JOIN cuadrilla c ON c.id = o.cuadrilla_id
          ORDER BY o.activo DESC, o.nombre"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Alta/edición de operador. En edición, la contraseña solo se cambia si viene
 * no vacía. Devuelve el id. Lanza si el usuario (login) ya existe en otro.
 */
function cuad_operador_guardar(PDO $pdo, array $d): int
{
    $id      = (int)($d['id'] ?? 0);
    $nombre  = trim((string)($d['nombre'] ?? ''));
    $tel     = trim((string)($d['telefono'] ?? '')) ?: null;
    $usuario = strtolower(trim((string)($d['usuario'] ?? '')));
    $rol     = in_array(($d['rol'] ?? ''), ['lider','operador'], true) ? $d['rol'] : 'operador';
    $cuad    = (int)($d['cuadrilla_id'] ?? 0) ?: null;
    $activo  = !empty($d['activo']) ? 1 : 0;
    $pass    = (string)($d['password'] ?? '');

    if ($nombre === '')  { throw new InvalidArgumentException('El nombre del operador es obligatorio.'); }
    if (!preg_match('/^[a-z0-9._-]{3,80}$/', $usuario)) {
        throw new InvalidArgumentException('Usuario inválido: usa 3–80 caracteres (letras, números, . _ -).');
    }
    // unicidad del login
    $q = $pdo->prepare("SELECT id FROM cuadrilla_operador WHERE usuario=? AND id<>? LIMIT 1");
    $q->execute([$usuario, $id]);
    if ($q->fetchColumn()) { throw new InvalidArgumentException('Ese usuario ya está en uso.'); }

    if ($id > 0) {
        if ($pass !== '') {
            if (strlen($pass) < 6) { throw new InvalidArgumentException('La contraseña debe tener al menos 6 caracteres.'); }
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE cuadrilla_operador SET cuadrilla_id=?, nombre=?, telefono=?, usuario=?, rol=?, activo=?, pass_hash=? WHERE id=?")
                ->execute([$cuad, $nombre, $tel, $usuario, $rol, $activo, $hash, $id]);
        } else {
            $pdo->prepare("UPDATE cuadrilla_operador SET cuadrilla_id=?, nombre=?, telefono=?, usuario=?, rol=?, activo=? WHERE id=?")
                ->execute([$cuad, $nombre, $tel, $usuario, $rol, $activo, $id]);
        }
        return $id;
    }

    if (strlen($pass) < 6) { throw new InvalidArgumentException('La contraseña inicial debe tener al menos 6 caracteres.'); }
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO cuadrilla_operador (cuadrilla_id, nombre, telefono, usuario, pass_hash, rol, activo) VALUES (?,?,?,?,?,?,?)")
        ->execute([$cuad, $nombre, $tel, $usuario, $hash, $rol, $activo]);
    return (int)$pdo->lastInsertId();
}

/** KPIs ligeros para el tablero. */
function cuad_kpis(PDO $pdo): array
{
    $one = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();
    return [
        'cuadrillas'  => $one("SELECT COUNT(*) FROM cuadrilla WHERE activa=1"),
        'operadores'  => $one("SELECT COUNT(*) FROM cuadrilla_operador WHERE activo=1"),
        'ordenes_abiertas' => $one("SELECT COUNT(*) FROM orden WHERE estatus IN ('despachada','en_proceso')"),
        'paradas_pend' => $one("SELECT COUNT(*) FROM orden_parada p JOIN orden o ON o.id=p.orden_id WHERE o.estatus IN ('despachada','en_proceso') AND p.estatus IN ('pendiente','en_camino','en_sitio')"),
    ];
}
