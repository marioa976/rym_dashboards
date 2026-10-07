<?php
/**
 * POST /modules/cuadrillas/api/login.php
 * Body JSON: { "usuario": "...", "password": "...", "device"?, "fcm_token"? }
 * -> { ok:true, token, operador:{...} }   (token válido 90 días)
 */
declare(strict_types=1);
require __DIR__ . '/_api.php';
api_metodo('POST');

$pdo = cuad_pdo();
cuad_ensure_schema($pdo);

$b       = api_body();
$usuario = strtolower(trim((string)($b['usuario'] ?? '')));
$pass    = (string)($b['password'] ?? '');
if ($usuario === '' || $pass === '') api_error('Usuario y contraseña son obligatorios.', 400);

$st = $pdo->prepare("SELECT * FROM cuadrilla_operador WHERE usuario = ? LIMIT 1");
$st->execute([$usuario]);
$op = $st->fetch(PDO::FETCH_ASSOC);

// Mensaje genérico (no revela si el usuario existe). password_verify es constante.
if (!$op || !password_verify($pass, (string)$op['pass_hash'])) {
    api_error('Usuario o contraseña incorrectos.', 401);
}
if (!(int)$op['activo']) api_error('Tu acceso está desactivado. Contacta a tu supervisor.', 403);

// Rehash si el algoritmo cambió
if (password_needs_rehash((string)$op['pass_hash'], PASSWORD_DEFAULT)) {
    $pdo->prepare("UPDATE cuadrilla_operador SET pass_hash=? WHERE id=?")
        ->execute([password_hash($pass, PASSWORD_DEFAULT), (int)$op['id']]);
}

$token  = bin2hex(random_bytes(32));
$device = ($b['device'] ?? '') !== '' ? mb_substr((string)$b['device'], 0, 160) : null;
$fcm    = ($b['fcm_token'] ?? '') !== '' ? mb_substr((string)$b['fcm_token'], 0, 255) : null;

$pdo->prepare(
    "INSERT INTO operador_sesion (operador_id, token_hash, device, fcm_token, expira_en)
     VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL 90 DAY))"
)->execute([(int)$op['id'], hash('sha256', $token), $device, $fcm]);

// Nombre de la cuadrilla (si tiene)
$cuad = null;
if ($op['cuadrilla_id']) {
    $cs = $pdo->prepare("SELECT nombre FROM cuadrilla WHERE id=?");
    $cs->execute([(int)$op['cuadrilla_id']]);
    $cuad = $cs->fetchColumn() ?: null;
}

api_json([
    'ok'    => true,
    'token' => $token,
    'operador' => [
        'id'           => (int)$op['id'],
        'nombre'       => $op['nombre'],
        'usuario'      => $op['usuario'],
        'rol'          => $op['rol'],
        'cuadrilla_id' => $op['cuadrilla_id'] ? (int)$op['cuadrilla_id'] : null,
        'cuadrilla'    => $cuad,
    ],
]);
