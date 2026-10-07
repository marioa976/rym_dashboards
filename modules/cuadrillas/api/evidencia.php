<?php
/**
 * POST /modules/cuadrillas/api/evidencia.php   (Authorization: Bearer <token>)
 * multipart/form-data:
 *   foto        (archivo imagen, obligatorio)
 *   parada_id   (int, obligatorio)
 *   tipo        (antes|despues|otro, opcional)
 *   nota, lat, lng (opcionales)
 * -> { ok:true, evidencia:{ id, tipo, ver_url } }
 *
 * Sube la imagen a GCS (privado) y registra la evidencia + evento. La imagen se
 * ve SIEMPRE a través del portal (evidencia_ver.php), nunca por URL pública.
 */
declare(strict_types=1);
require __DIR__ . '/_api.php';
require_once __DIR__ . '/../lib_gcs.php';
api_metodo('POST');

$pdo = cuad_pdo();
cuad_ensure_schema($pdo);
$op = api_operador($pdo);

if (!$op['cuadrilla_id']) api_error('No tienes una cuadrilla asignada.', 403);

$paradaId = (int)($_POST['parada_id'] ?? 0);
$tipo     = in_array(($_POST['tipo'] ?? ''), ['antes','despues','otro'], true) ? $_POST['tipo'] : 'otro';
$nota     = trim((string)($_POST['nota'] ?? ''));
$lat      = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
$lng      = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;
if ($paradaId <= 0) api_error('Falta parada_id.', 400);

// La parada debe ser de una orden de la cuadrilla del operador.
$st = $pdo->prepare(
    "SELECT p.id, p.orden_id FROM orden_parada p JOIN orden o ON o.id = p.orden_id
      WHERE p.id = ? AND o.cuadrilla_id = ? LIMIT 1");
$st->execute([$paradaId, (int)$op['cuadrilla_id']]);
$par = $st->fetch(PDO::FETCH_ASSOC);
if (!$par) api_error('La parada no existe o no es de tu cuadrilla.', 404);

// Archivo
if (empty($_FILES['foto']) || ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    api_error('No llegó la foto.', 400);
}
$f = $_FILES['foto'];
if ((int)$f['size'] > 12 * 1024 * 1024) api_error('La foto es muy grande (máx. 12 MB).', 413);

$mime = function_exists('mime_content_type') ? (mime_content_type($f['tmp_name']) ?: '') : '';
if ($mime === '') $mime = (string)($f['type'] ?? '');
$permitidos = ['image/jpeg','image/jpg','image/png','image/webp','image/heic'];
if (!in_array($mime, $permitidos, true)) api_error('Formato de imagen no permitido.', 415);

$bytes = file_get_contents($f['tmp_name']);
if ($bytes === false || $bytes === '') api_error('No se pudo leer la foto.', 400);

$objeto = 'cuadrillas/evidencias/' . (int)$par['orden_id'] . '/' . $paradaId . '/'
        . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . cuad_evid_ext($mime);

if (!cuad_evid_guardar($objeto, $bytes, $mime)) {
    api_error('No se pudo guardar la evidencia. Intenta de nuevo.', 500);
}

try {
    $pdo->prepare(
        "INSERT INTO parada_evidencia (parada_id, url, tipo, nota, lat, lng, operador_id)
         VALUES (?,?,?,?,?,?,?)"
    )->execute([$paradaId, $objeto, $tipo, $nota !== '' ? mb_substr($nota, 0, 500) : null, $lat, $lng, (int)$op['id']]);
    $eid = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO orden_evento (orden_id, parada_id, operador_id, tipo, detalle, lat, lng) VALUES (?,?,?,?,?,?,?)"
    )->execute([(int)$par['orden_id'], $paradaId, (int)$op['id'], 'evidencia',
                json_encode(['evidencia_id' => $eid, 'tipo' => $tipo]), $lat, $lng]);
} catch (Throwable $e) {
    error_log('[portal][cuadrillas-api] evidencia: ' . $e->getMessage());
    api_error('No se pudo registrar la evidencia.', 500);
}

api_json([
    'ok' => true,
    'evidencia' => ['id' => $eid, 'tipo' => $tipo, 'ver_url' => '../evidencia_ver.php?id=' . $eid],
]);
