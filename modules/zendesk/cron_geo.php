<?php
/**
 * cron_geo.php — Asigna SECCIÓN y DELEGACIÓN por geometría (cruce espacial),
 * en una sola pasada. Es una operación PESADA, por eso NO corre en el webhook
 * ni en los imports (ahí disparaba decenas de consultas pesadas en paralelo y
 * congelaba la BD). Cloud Scheduler lo llama cada X minutos.
 *
 * Protegido por el mismo secreto que el sync (ZENDESK_CRON_KEY): ?key=... o
 * header X-Cron-Key. Máquina-a-máquina, sin login.
 *
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

/** Cuenta tickets sin X con coords; 0 si la columna aún no existe. */
function geo_pend(PDO $pdo, string $col): int {
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM tickets
            WHERE {$col} IS NULL AND latitud IS NOT NULL AND longitud IS NOT NULL")->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

$t0 = microtime(true);
try {
    $pdo = db();

    $antesSec = geo_pend($pdo, 'seccion_id');
    $antesDel = geo_pend($pdo, 'delegacion_id');

    zd_asignar_secciones($pdo);       // bbox (sin cartesiano); crea seccion_id si falta
    zd_asignar_delegaciones($pdo);    // 7 polígonos oficiales

    $despSec = geo_pend($pdo, 'seccion_id');
    $despDel = geo_pend($pdo, 'delegacion_id');

    $res = [
        'ok' => true,
        'secciones_asignadas'    => max(0, $antesSec - $despSec),
        'delegaciones_asignadas' => max(0, $antesDel - $despDel),
        'pendientes_seccion'     => $despSec,
        'pendientes_delegacion'  => $despDel,
    ];
} catch (Throwable $e) {
    error_log('[portal][zendesk-geo] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'fallo en el cruce geográfico']);
    exit;
}

$res['segundos'] = round(microtime(true) - $t0, 1);
error_log('[portal][zendesk-geo] ' . json_encode($res));
echo json_encode($res, JSON_UNESCAPED_UNICODE);
