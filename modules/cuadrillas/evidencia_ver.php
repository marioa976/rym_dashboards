<?php
/**
 * evidencia_ver.php?id=N — sirve una imagen de evidencia desde GCS (privado).
 *
 * Autoriza por: (a) sesión del portal con acceso al módulo cuadrillas, o
 * (b) token de operador (Bearer / X-Auth-Token / ?token=) de la MISMA cuadrilla
 * de la orden. Nunca expone el objeto de GCS directamente.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';        // carga guard (sesión) + cuad_*
require_once __DIR__ . '/lib_gcs.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('bad request'); }

$pdo = cuad_pdo();

$autorizado = false;
$cuadrillaOperador = null;   // si entra por token, limita a su cuadrilla

// (a) sesión del portal
if (!empty($_SESSION['uid']) && function_exists('puede_modulo') && puede_modulo('cuadrillas')) {
    $autorizado = true;
} else {
    // (b) token de operador
    $tok = '';
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h !== '' && preg_match('/Bearer\s+(.+)/i', $h, $m)) $tok = trim($m[1]);
    if ($tok === '') $tok = (string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? $_GET['token'] ?? '');
    $operador = $tok !== '' ? cuad_operador_por_token($pdo, $tok) : null;
    if ($operador && $operador['cuadrilla_id']) {
        $autorizado = true;
        $cuadrillaOperador = (int)$operador['cuadrilla_id'];
    }
}
if (!$autorizado) { http_response_code(401); exit('no autorizado'); }

// Evidencia + cuadrilla de su orden
$st = $pdo->prepare(
    "SELECT e.url, o.cuadrilla_id
       FROM parada_evidencia e
       JOIN orden_parada p ON p.id = e.parada_id
       JOIN orden o ON o.id = p.orden_id
      WHERE e.id = ? LIMIT 1");
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit('no encontrada'); }
if ($cuadrillaOperador !== null && (int)$row['cuadrilla_id'] !== $cuadrillaOperador) {
    http_response_code(403); exit('prohibido');
}

$obj = cuad_evid_leer((string)$row['url']);
if (!$obj) { http_response_code(404); exit('archivo no disponible'); }

header('Content-Type: ' . $obj['mime']);
header('Content-Length: ' . strlen($obj['bytes']));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
echo $obj['bytes'];
