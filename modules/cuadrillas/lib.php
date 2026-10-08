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

/* ============================================================
   PLANES (origen del trazo) · ASIGNAR / DESPACHAR
   ============================================================ */

/** Planes guardados por el planificador (tabla cuadrillas_planes). */
function cuad_planes(PDO $pdo): array
{
    try {
        return $pdo->query(
            "SELECT id, nombre, n_cuadrillas, n_tickets, km, creado_en
               FROM cuadrillas_planes ORDER BY creado_en DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }   // la tabla puede no existir aún
}

/** Un plan decodificado: ['id','nombre','pl'=>[...]]. null si no existe. */
function cuad_plan(PDO $pdo, int $id): ?array
{
    try {
        $st = $pdo->prepare("SELECT id, nombre, payload FROM cuadrillas_planes WHERE id=?");
        $st->execute([$id]);
    } catch (Throwable $e) { return null; }
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $pl = json_decode((string)$r['payload'], true);
    if (!is_array($pl) || empty($pl['plan'])) return null;
    return ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre'], 'pl' => $pl];
}

/**
 * Despacha un plan: por cada ruta del plan ASIGNADA a una cuadrilla real crea
 * una `orden` por día (fecha = base + (día-1)) con sus `orden_parada` (una por
 * ticket, en orden de visita) y registra el evento de despacho. Transaccional.
 *
 * $asig = [ indiceRutaDelPlan(0-based) => ['cuadrilla_id'=>int, 'fecha'=>'Y-m-d'] ]
 * Devuelve ['ordenes'=>N, 'paradas'=>M, 'ids'=>[...]].
 */
function cuad_despachar(PDO $pdo, array $plan, array $asig, ?int $creado_por): array
{
    $rutas = $plan['pl']['plan'] ?? [];
    $nOrd = 0; $nPar = 0; $ids = [];

    $pdo->beginTransaction();
    try {
        $insOrden = $pdo->prepare(
            "INSERT INTO orden (plan_id,cuadrilla_id,fecha,titulo,estatus,n_paradas,km,creado_por,despachada_en)
             VALUES (?,?,?,?,'despachada',?,?,?,NOW())");
        $insParada = $pdo->prepare(
            "INSERT INTO orden_parada (orden_id,idx,ticket_id,titulo,direccion,lat,lng,estatus)
             VALUES (?,?,?,?,?,?,?,'pendiente')");
        $insEvento = $pdo->prepare(
            "INSERT INTO orden_evento (orden_id,tipo,detalle) VALUES (?,'despacho',?)");

        foreach ($rutas as $i => $cu) {
            $cuadId = (int)($asig[$i]['cuadrilla_id'] ?? 0);
            $fechaB = (string)($asig[$i]['fecha'] ?? '');
            if ($cuadId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaB)) continue;

            foreach (($cu['dias'] ?? []) as $dia) {
                $tickets = $dia['tickets'] ?? [];
                if (!$tickets) continue;
                $dnum  = (int)($dia['dia'] ?? 1);
                $fecha = date('Y-m-d', strtotime($fechaB . ' +' . ($dnum - 1) . ' days'));
                $titulo = trim(($plan['nombre'] ?: 'Plan') . ' · Cuadrilla ' . ($cu['cuadrilla'] ?? ($i + 1))
                          . ($dnum > 1 ? " · Día $dnum" : ''));

                $insOrden->execute([$plan['id'], $cuadId, $fecha, mb_substr($titulo, 0, 160),
                                    count($tickets), round((float)($dia['km'] ?? 0), 1), $creado_por]);
                $ordenId = (int)$pdo->lastInsertId();
                $ids[] = $ordenId; $nOrd++;

                $idx = 0;
                foreach ($tickets as $t) {
                    $tit = trim(($t['servicio'] ?? 'Ticket') . (!empty($t['colonia']) ? ' — ' . $t['colonia'] : ''));
                    $insParada->execute([
                        $ordenId, $idx++,
                        (int)($t['id'] ?? 0) ?: null,
                        mb_substr($tit, 0, 255),
                        ($t['direccion'] ?? '') !== '' ? mb_substr((string)$t['direccion'], 0, 255) : null,
                        isset($t['lat']) ? (float)$t['lat'] : null,
                        isset($t['lng']) ? (float)$t['lng'] : null,
                    ]);
                    $nPar++;
                }
                $insEvento->execute([$ordenId, json_encode(['n_paradas' => count($tickets), 'plan_id' => $plan['id']])]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ordenes' => $nOrd, 'paradas' => $nPar, 'ids' => $ids];
}

/** Lista de órdenes para seguimiento. */
function cuad_ordenes(PDO $pdo, int $limite = 200): array
{
    $limite = max(1, min(500, $limite));
    return $pdo->query(
        "SELECT o.*, c.nombre AS cuadrilla, c.color,
                (SELECT COUNT(*) FROM orden_parada p WHERE p.orden_id=o.id AND p.estatus='resuelta') AS resueltas
           FROM orden o
           LEFT JOIN cuadrilla c ON c.id = o.cuadrilla_id
          ORDER BY o.fecha DESC, o.id DESC
          LIMIT $limite"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Recalcula contadores y estatus de una orden a partir de sus paradas:
 *  - todas cerradas (resuelta|no_resuelta)  -> 'cerrada' (+ cerrada_en)
 *  - alguna tocada (no todas pendientes)    -> 'en_proceso'
 *  - todas pendientes                       -> 'despachada'
 * (No reabre una orden ya 'cancelada'.) Devuelve el nuevo estado.
 */
function cuad_recalc_orden(PDO $pdo, int $ordenId): array
{
    $actual = $pdo->prepare("SELECT estatus FROM orden WHERE id=?");
    $actual->execute([$ordenId]);
    $est0 = (string)$actual->fetchColumn();
    if ($est0 === 'cancelada' || $est0 === '') {
        return ['estatus' => $est0, 'n_resueltas' => 0, 'total' => 0];
    }
    $st = $pdo->prepare("SELECT estatus, COUNT(*) n FROM orden_parada WHERE orden_id=? GROUP BY estatus");
    $st->execute([$ordenId]);
    $by = []; $total = 0;
    foreach ($st as $r) { $by[$r['estatus']] = (int)$r['n']; $total += (int)$r['n']; }
    $res   = $by['resuelta'] ?? 0;
    $nores = $by['no_resuelta'] ?? 0;
    $pend  = $by['pendiente'] ?? 0;
    $cerradas = $res + $nores;

    if ($total > 0 && $cerradas >= $total) {
        $pdo->prepare("UPDATE orden SET estatus='cerrada', n_resueltas=?, cerrada_en=COALESCE(cerrada_en,NOW()) WHERE id=?")
            ->execute([$res, $ordenId]);
        $nuevo = 'cerrada';
    } else {
        $nuevo = ($pend < $total) ? 'en_proceso' : 'despachada';
        $pdo->prepare("UPDATE orden SET estatus=?, n_resueltas=?, cerrada_en=NULL WHERE id=?")
            ->execute([$nuevo, $res, $ordenId]);
    }
    return ['estatus' => $nuevo, 'n_resueltas' => $res, 'total' => $total];
}

/** Paradas de un conjunto de órdenes, agrupadas por orden_id. */
function cuad_paradas_por_orden(PDO $pdo, array $ordenIds): array
{
    $ids = array_values(array_filter(array_map('intval', $ordenIds)));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $rows = $pdo->query(
        "SELECT id, orden_id, idx, ticket_id, titulo, direccion, estatus, motivo_no
           FROM orden_parada WHERE orden_id IN ($in) ORDER BY orden_id, idx"
    )->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) { $out[(int)$r['orden_id']][] = $r; }
    return $out;
}

/** Operador válido a partir de un token (o null). Reutilizable fuera de la API. */
function cuad_operador_por_token(PDO $pdo, string $token): ?array
{
    if ($token === '') return null;
    $st = $pdo->prepare(
        "SELECT o.id, o.nombre, o.usuario, o.rol, o.activo, o.cuadrilla_id,
                c.nombre AS cuadrilla, c.color, s.id AS sesion_id, s.expira_en
           FROM operador_sesion s
           JOIN cuadrilla_operador o ON o.id = s.operador_id
           LEFT JOIN cuadrilla c ON c.id = o.cuadrilla_id
          WHERE s.token_hash = ? LIMIT 1");
    $st->execute([hash('sha256', $token)]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || !(int)$r['activo']) return null;
    if ($r['expira_en'] && strtotime((string)$r['expira_en']) < time()) return null;
    return $r;
}

/** Evidencias de un conjunto de paradas, agrupadas por parada_id. */
function cuad_evidencias_por_parada(PDO $pdo, array $paradaIds): array
{
    $ids = array_values(array_filter(array_map('intval', $paradaIds)));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $rows = $pdo->query(
        "SELECT id, parada_id, tipo, nota, creado_en FROM parada_evidencia
          WHERE parada_id IN ($in) ORDER BY parada_id, id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) { $out[(int)$r['parada_id']][] = $r; }
    return $out;
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

/**
 * Payload del tablero EN VIVO: KPIs + paradas (con coords/estatus) de las órdenes
 * abiertas + última posición conocida de cada cuadrilla (bitácora con GPS) +
 * feed de actividad reciente. Pensado para refrescar por polling.
 */
function cuad_vivo_payload(PDO $pdo): array
{
    // Órdenes abiertas (con su cuadrilla).
    $ordenes = $pdo->query(
        "SELECT o.id, o.titulo, o.estatus, o.n_paradas, o.n_resueltas, o.cuadrilla_id,
                c.nombre AS cuadrilla, c.color
           FROM orden o LEFT JOIN cuadrilla c ON c.id = o.cuadrilla_id
          WHERE o.estatus IN ('despachada','en_proceso')
          ORDER BY o.id"
    )->fetchAll(PDO::FETCH_ASSOC);

    $ids = array_map('intval', array_column($ordenes, 'id'));
    $meta = [];
    foreach ($ordenes as $o) $meta[(int)$o['id']] = $o;

    $paradas = [];
    $posiciones = [];
    if ($ids) {
        $in = implode(',', $ids);

        // Paradas con coordenadas.
        $rows = $pdo->query(
            "SELECT id, orden_id, idx, titulo, estatus, lat, lng
               FROM orden_parada WHERE orden_id IN ($in)
                AND lat IS NOT NULL AND lng IS NOT NULL
              ORDER BY orden_id, idx"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $o = $meta[(int)$r['orden_id']] ?? [];
            $paradas[] = [
                'id'        => (int)$r['id'],
                'orden_id'  => (int)$r['orden_id'],
                'idx'       => (int)$r['idx'],
                'titulo'    => $r['titulo'],
                'estatus'   => $r['estatus'],
                'lat'       => (float)$r['lat'],
                'lng'       => (float)$r['lng'],
                'cuadrilla' => $o['cuadrilla'] ?? null,
                'color'     => $o['color'] ?? '#0f766e',
            ];
        }

        // Última posición conocida por orden (último evento con GPS).
        $rows = $pdo->query(
            "SELECT e.orden_id, e.lat, e.lng, e.tipo, e.creado_en, op.nombre AS operador
               FROM orden_evento e
               JOIN (SELECT orden_id, MAX(id) AS mid FROM orden_evento
                      WHERE lat IS NOT NULL AND orden_id IN ($in) GROUP BY orden_id) u ON u.mid = e.id
               LEFT JOIN cuadrilla_operador op ON op.id = e.operador_id"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $o = $meta[(int)$r['orden_id']] ?? [];
            $posiciones[] = [
                'orden_id'  => (int)$r['orden_id'],
                'lat'       => (float)$r['lat'],
                'lng'       => (float)$r['lng'],
                'tipo'      => $r['tipo'],
                'cuando'    => $r['creado_en'],
                'operador'  => $r['operador'],
                'cuadrilla' => $o['cuadrilla'] ?? null,
                'color'     => $o['color'] ?? '#0f766e',
            ];
        }
    }

    // Actividad reciente (últimos eventos de campo).
    $actividad = $pdo->query(
        "SELECT e.tipo, e.creado_en, e.detalle, op.nombre AS operador,
                c.nombre AS cuadrilla, p.titulo AS parada
           FROM orden_evento e
           JOIN orden o ON o.id = e.orden_id
           LEFT JOIN cuadrilla c ON c.id = o.cuadrilla_id
           LEFT JOIN cuadrilla_operador op ON op.id = e.operador_id
           LEFT JOIN orden_parada p ON p.id = e.parada_id
          WHERE e.tipo <> 'despacho'
          ORDER BY e.id DESC LIMIT 25"
    )->fetchAll(PDO::FETCH_ASSOC);

    return [
        'ok'         => true,
        'ts'         => date('H:i:s'),
        'kpis'       => cuad_kpis($pdo),
        'ordenes'    => array_map(fn($o) => [
            'id' => (int)$o['id'], 'titulo' => $o['titulo'], 'estatus' => $o['estatus'],
            'n_paradas' => (int)$o['n_paradas'], 'n_resueltas' => (int)$o['n_resueltas'],
            'cuadrilla' => $o['cuadrilla'], 'color' => $o['color'] ?? '#0f766e',
        ], $ordenes),
        'paradas'    => $paradas,
        'posiciones' => $posiciones,
        'actividad'  => $actividad,
    ];
}
