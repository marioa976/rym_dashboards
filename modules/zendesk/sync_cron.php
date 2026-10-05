<?php
/**
 * sync_cron.php — Sincronización incremental de Zendesk disparada por HTTP.
 *
 * Pensado para Cloud Scheduler (Cloud Run no corre crons por sí solo): cada
 * noche a las 3:00 (America/Mexico_City) trae los últimos ~2 meses por la
 * Incremental Export API y actualiza estatus de los tickets que se movieron.
 *
 * Protegido por un secreto (ZENDESK_CRON_KEY): se pasa como ?key=... o en el
 * header X-Cron-Key. NO requiere login (es máquina-a-máquina).
 *
 *   GET /modules/zendesk/sync_cron.php?key=SECRETO
 *   GET /modules/zendesk/sync_cron.php?key=SECRETO&meses=2   (ventana en meses, 1-6)
 */
declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');
header('Content-Type: application/json; charset=utf-8');

// --- Validar secreto ANTES de cargar nada pesado ---------------------------
$__c  = require __DIR__ . '/../../config/config.php';
$key  = (string)($__c['modulos']['zendesk']['cron_key'] ?? '');
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

$meses = (int)($_GET['meses'] ?? 2);
if ($meses < 1 || $meses > 6) $meses = 2;
$desde = date('Y-m-d', strtotime("-{$meses} months"));
$hasta = date('Y-m-d');

$t0  = microtime(true);
try {
    $pdo = db();
    $cfg = require __DIR__ . '/config.php';
    $api = $cfg['zendesk_api'] ?? [];
    $res = zd_importar_rango($pdo, $api, $desde, $hasta, 'cron');
} catch (Throwable $e) {
    error_log('[portal][zendesk-cron] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'fallo en la sincronización']);
    exit;
}

$res['segundos'] = round(microtime(true) - $t0, 1);
error_log('[portal][zendesk-cron] ' . json_encode($res));
echo json_encode(['ok' => empty($res['error'])] + $res, JSON_UNESCAPED_UNICODE);
