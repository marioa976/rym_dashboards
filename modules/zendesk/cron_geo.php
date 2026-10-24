<?php
/**
 * cron_geo.php — Asigna SECCIÓN y DELEGACIÓN por geometría (cruce espacial).
 *
 * Operación PESADA y que compite por locks con los upserts de tickets → NO corre
 * en el webhook ni en los imports (ahí abría decenas de consultas en paralelo y
 * congelaba la BD). Cloud Scheduler lo llama una vez en la madrugada (poco
 * tráfico). Recorre los tickets pendientes con un CURSOR por ticket_id (de mayor
 * a menor), en lotes acotados por `ticket_id IN (...)` (buen plan desde la PK),
 * con reintento ante deadlock. Un ticket que cae FUERA de toda sección se queda
 * sin asignar (coords en bordes o ajenas); se revisa de nuevo la próxima corrida,
 * lo cual es despreciable al correr una vez al día.
 *
 * Protegido por ZENDESK_CRON_KEY: ?key=... o header X-Cron-Key.
 *   GET /modules/zendesk/cron_geo.php?key=SECRETO
 */
declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');
header('Content-Type: application/json; charset=utf-8');

$__c   = require __DIR__ . '/../../config/config.php';
$key   = (string)($__c['modulos']['zendesk']['cron_key'] ?? '');
$given = (string)($_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '');
if ($key === '' || !hash_equals($key, $given)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

define('ZD_NO_GUARD', 1);
@set_time_limit(0);
ignore_user_abort(true);

require __DIR__ . '/db.php';
require_once __DIR__ . '/_zendesk_lib.php';

const GEO_BATCH = 200;     // tickets por lote
const GEO_CAP   = 200;     // tope de lotes por columna (200*200 = 40k) por seguridad

/** Ejecuta un UPDATE reintentando ante deadlock (1213). */
function geo_exec(PDO $pdo, string $sql, int $tries = 4): void {
    for ($i = 1; ; $i++) {
        try { $pdo->exec($sql); return; }
        catch (Throwable $e) {
            if ($i >= $tries || !str_contains($e->getMessage(), '1213')) {
                error_log('[portal][zendesk-geo] ' . $e->getMessage());
                return;
            }
            usleep(300000 * $i);   // backoff 0.3s, 0.6s, 0.9s
        }
    }
}

/**
 * Recorre con cursor (ticket_id DESC) los tickets donde $col es NULL y aplica
 * $sqlTpl (un %s para la lista de ids). Devuelve cuántos tickets recorrió.
 */
function geo_recorrer(PDO $pdo, string $col, string $sqlTpl): int {
    $cursor = PHP_INT_MAX; $vistos = 0; $lotes = 0;
    while ($lotes < GEO_CAP) {
        $ids = $pdo->query("SELECT ticket_id FROM tickets
                 WHERE {$col} IS NULL AND latitud IS NOT NULL AND longitud IS NOT NULL
                   AND ticket_id < {$cursor}
                 ORDER BY ticket_id DESC LIMIT " . GEO_BATCH)->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) break;
        $in = implode(',', array_map('intval', $ids));
        geo_exec($pdo, sprintf($sqlTpl, $in));
        $cursor = (int)min($ids);
        $vistos += count($ids);
        $lotes++;
    }
    return $vistos;
}

$t0 = microtime(true);
try {
    $pdo = db();
    $pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");

    $res = ['ok' => true, 'sec_antes' => 0, 'sec_vistos' => 0, 'del_antes' => 0, 'del_vistos' => 0];

    // ---- Secciones (bbox) ----
    $haySec = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='secciones_geo'")->fetchColumn();
    if ($haySec) {
        $cols = $pdo->query("SHOW COLUMNS FROM tickets")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('seccion_id', $cols, true)) {
            try { zd_construir_bbox_secciones($pdo); } catch (Throwable $e) { $haySec = 0; }
            if ($haySec) {
                $res['sec_antes'] = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE seccion_id IS NULL AND latitud IS NOT NULL AND longitud IS NOT NULL")->fetchColumn();
                $res['sec_vistos'] = geo_recorrer($pdo, 'seccion_id', "
                    UPDATE tickets t
                      JOIN secciones_bbox b
                        ON t.longitud BETWEEN b.minlng AND b.maxlng
                       AND t.latitud  BETWEEN b.minlat AND b.maxlat
                      JOIN secciones_geo sg ON sg.seccion_id = b.seccion_id
                      SET t.seccion_id = b.seccion_id
                     WHERE t.seccion_id IS NULL AND t.ticket_id IN (%s)
                       AND ST_Contains(ST_GeomFromWKB(ST_AsWKB(sg.geom),0),
                                       ST_GeomFromText(CONCAT('POINT(',t.longitud,' ',t.latitud,')'),0))");
                $res['sec_pendientes'] = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE seccion_id IS NULL AND latitud IS NOT NULL AND longitud IS NOT NULL")->fetchColumn();
                $res['sec_asignadas'] = max(0, $res['sec_antes'] - $res['sec_pendientes']);
            }
        }
    }

    // ---- Delegaciones (7 polígonos oficiales) ----
    $hayDel = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='delegaciones_geo'")->fetchColumn();
    if ($hayDel) {
        $cat = [];
        foreach ($pdo->query("SELECT id,nombre FROM cat_delegacion") as $r) { $cat[$r['nombre']] = (int)$r['id']; }
        $pares = [];
        foreach ($pdo->query("SELECT id,nombre FROM delegaciones_geo") as $g) {
            $canon = zd_deleg_canon($g['nombre']);
            if ($canon && isset($cat[$canon])) $pares[(int)$g['id']] = $cat[$canon];
        }
        if ($pares) {
            $res['del_antes'] = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE delegacion_id IS NULL AND latitud IS NOT NULL AND longitud IS NOT NULL")->fetchColumn();
            // Un solo recorrido por cursor; dentro de cada lote, los 7 polígonos.
            $cursor = PHP_INT_MAX; $lotes = 0; $vistos = 0;
            while ($lotes < GEO_CAP) {
                $ids = $pdo->query("SELECT ticket_id FROM tickets
                         WHERE delegacion_id IS NULL AND latitud IS NOT NULL AND longitud IS NOT NULL
                           AND ticket_id < {$cursor}
                         ORDER BY ticket_id DESC LIMIT " . GEO_BATCH)->fetchAll(PDO::FETCH_COLUMN);
                if (!$ids) break;
                $in = implode(',', array_map('intval', $ids));
                foreach ($pares as $gid => $cid) {
                    geo_exec($pdo, "UPDATE tickets t JOIN delegaciones_geo g ON g.id = {$gid}
                                       SET t.delegacion_id = {$cid}
                                     WHERE t.delegacion_id IS NULL AND t.ticket_id IN ({$in})
                                       AND ST_Contains(ST_GeomFromWKB(ST_AsWKB(g.geom),0),
                                                       ST_GeomFromText(CONCAT('POINT(',t.longitud,' ',t.latitud,')'),0))");
                }
                $cursor = (int)min($ids);
                $vistos += count($ids);
                $lotes++;
            }
            $res['del_vistos'] = $vistos;
            $res['del_pendientes'] = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE delegacion_id IS NULL AND latitud IS NOT NULL AND longitud IS NOT NULL")->fetchColumn();
            $res['del_asignadas'] = max(0, $res['del_antes'] - $res['del_pendientes']);
        }
    }
} catch (Throwable $e) {
    error_log('[portal][zendesk-geo] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'fallo en el cruce geográfico']);
    exit;
}

$res['segundos'] = round(microtime(true) - $t0, 1);
error_log('[portal][zendesk-geo] ' . json_encode($res));
echo json_encode($res, JSON_UNESCAPED_UNICODE);
