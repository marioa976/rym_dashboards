<?php
/**
 * Registro de auditoría ligero para acciones sensibles (exportar/imprimir datos
 * personales). Fire-and-forget desde el cliente (fetch/sendBeacon). Escribe una
 * línea estructurada al log del servidor (error_log) — sin tabla nueva. Para un
 * rastro consultable en BD, migrar a una tabla `auditoria` más adelante.
 */
declare(strict_types=1);
require_once __DIR__ . '/core/guard.php';
require_login();

$u      = Auth::user() ?? [];
$action = substr(trim((string)($_POST['action'] ?? $_GET['action'] ?? '')), 0, 80);
$detail = substr(trim((string)($_POST['detail'] ?? $_GET['detail'] ?? '')), 0, 240);
$ip     = $_SERVER['REMOTE_ADDR'] ?? '?';

error_log(sprintf(
    '[AUDIT PII] uid=%s email=%s action=%s detail=%s ip=%s',
    $u['id'] ?? '?', $u['email'] ?? '?', $action !== '' ? $action : '(sin accion)', $detail, $ip
));

http_response_code(204);
