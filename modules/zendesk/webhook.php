<?php
/**
 * webhook.php — Recibe actualizaciones de Zendesk (p.ej. cambio de estatus) y
 * actualiza el ticket en la BD casi en tiempo real.
 *
 * Seguridad (cualquiera de las dos):
 *   A) Firma HMAC de Zendesk: headers X-Zendesk-Webhook-Signature y
 *      X-Zendesk-Webhook-Signature-Timestamp. El secreto (ZENDESK_WEBHOOK_SECRET)
 *      debe ser el "signing secret" del webhook en Zendesk.
 *   B) Secreto compartido en la URL: ?key=SECRETO (o header X-Webhook-Key).
 *
 * Flujo: del cuerpo saca el ticket id, trae el ticket autoritativo de la API y
 * lo hace upsert con el MISMO mapeo que el import (estatus, prioridad, etc.).
 * NO requiere login (máquina-a-máquina).
 *
 *   POST /modules/zendesk/webhook.php      (JSON con el id del ticket)
 */
declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input') ?: '';

$__c    = require __DIR__ . '/../../config/config.php';
$secret = (string)($__c['modulos']['zendesk']['webhook_secret'] ?? '');
if ($secret === '') { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'webhook no configurado']); exit; }

// --- Validación -------------------------------------------------------------
$ok  = false;
$sig = $_SERVER['HTTP_X_ZENDESK_WEBHOOK_SIGNATURE'] ?? '';
if ($sig !== '') {
    $tsp      = $_SERVER['HTTP_X_ZENDESK_WEBHOOK_SIGNATURE_TIMESTAMP'] ?? '';
    $computed = base64_encode(hash_hmac('sha256', $tsp . $raw, $secret, true));
    $ok = hash_equals($computed, $sig);
}
if (!$ok) {                                   // respaldo: secreto compartido en URL/header
    $given = (string)($_GET['key'] ?? $_SERVER['HTTP_X_WEBHOOK_KEY'] ?? '');
    $ok = $given !== '' && hash_equals($secret, $given);
}
if (!$ok) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'firma inválida']); exit; }

// --- Ticket id del cuerpo (acepta varias formas) ----------------------------
$body = json_decode($raw, true);
$body = is_array($body) ? $body : [];
$tid  = $body['ticket_id']
      ?? ($body['ticket']['id'] ?? null)
      ?? ($body['id'] ?? null)
      ?? ($body['detail']['id'] ?? null);
$tid  = is_scalar($tid) ? (int)$tid : 0;
if ($tid <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'sin ticket id en el cuerpo']); exit; }

// --- Traer el ticket autoritativo + upsert ----------------------------------
define('ZD_NO_GUARD', 1);
@set_time_limit(60);
require __DIR__ . '/db.php';
require_once __DIR__ . '/_zendesk_lib.php';

try {
    $pdo = db();
    $cfg = require __DIR__ . '/config.php';
    $api = $cfg['zendesk_api'] ?? [];
    if (empty($api['subdomain'])) throw new RuntimeException('API de Zendesk sin configurar.');

    $mapeo = zd_cargar_mapeo($pdo);
    if (!$mapeo) throw new RuntimeException('Sin mapeo de campos.');
    zd_sincronizar_estructura($pdo, $mapeo);

    $url = 'https://' . zd_sub($api) . '.zendesk.com/api/v2/tickets/' . $tid . '.json';
    [$st, $resp] = zd_get($url, $api['user'] ?? '', $api['token'] ?? '');
    if ($st !== 200) throw new RuntimeException("Zendesk HTTP $st al traer el ticket $tid");
    $d = json_decode($resp, true);
    $ticket = $d['ticket'] ?? null;
    if (!$ticket) throw new RuntimeException('Respuesta sin ticket.');

    [$okN, $errs] = zd_importar($pdo, $api, [$ticket], $mapeo);
    // Cruce espacial solo si el ticket trae coordenadas nuevas (barato: 1 ticket).
    try { zd_asignar_secciones($pdo); } catch (Throwable $e) {}

    error_log("[portal][zendesk-webhook] ticket=$tid status=" . ($ticket['status'] ?? '?') . " guardado=$okN");
    echo json_encode(['ok'=>true, 'ticket'=>$tid, 'status'=>$ticket['status'] ?? null, 'guardado'=>$okN, 'errores'=>$errs], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[portal][zendesk-webhook] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'no se pudo procesar el webhook']);
}
