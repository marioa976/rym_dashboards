<?php
/**
 * Cuadrillas · API — helper común (para la app Flutter).
 *
 * Máquina-a-máquina: NO usa la sesión del portal. Cada endpoint valida un token
 * de operador (emitido por login.php) en la tabla operador_sesion. El token se
 * guarda SOLO como sha256 en BD; el texto plano vive en el dispositivo.
 *
 * Auth: header  Authorization: Bearer <token>   (o  X-Auth-Token: <token>).
 * Respuestas: JSON  { ok: true, ... }  |  { ok:false, error:"..." }.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../lib.php';   // carga guard (funciones+sesión), cuad_* y Database

/** Responde JSON y termina. */
function api_json($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function api_error(string $msg, int $code = 400): void { api_json(['ok' => false, 'error' => $msg], $code); }

/** Exige un método HTTP; si no, 405. */
function api_metodo(string $m): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $m) { api_error('Método no permitido.', 405); }
}

/** Cuerpo JSON (o form) de la petición. */
function api_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $b = json_decode($raw, true);
    if (is_array($b)) return $b;
    return $_POST;
}

/** Extrae el token del header Authorization/X-Auth-Token (o ?token= como respaldo). */
function api_token(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h !== '' && preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
    return (string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? $_GET['token'] ?? $_POST['token'] ?? '');
}

/**
 * Valida el token y devuelve el operador (+ cuadrilla + sesion_id). Aborta si
 * el token es inválido/expirado o el operador está inactivo.
 */
function api_operador(PDO $pdo): array
{
    $tok = api_token();
    if ($tok === '') api_error('Falta el token de autenticación.', 401);
    $st = $pdo->prepare(
        "SELECT o.id, o.nombre, o.usuario, o.rol, o.activo, o.cuadrilla_id,
                c.nombre AS cuadrilla, c.color,
                s.id AS sesion_id, s.expira_en
           FROM operador_sesion s
           JOIN cuadrilla_operador o ON o.id = s.operador_id
           LEFT JOIN cuadrilla c ON c.id = o.cuadrilla_id
          WHERE s.token_hash = ? LIMIT 1");
    $st->execute([hash('sha256', $tok)]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r)                                            api_error('Sesión inválida. Inicia sesión de nuevo.', 401);
    if (!(int)$r['activo'])                             api_error('Operador inactivo.', 403);
    if ($r['expira_en'] && strtotime((string)$r['expira_en']) < time()) api_error('La sesión expiró.', 401);
    $pdo->prepare("UPDATE operador_sesion SET ult_uso_en = NOW() WHERE id = ?")->execute([(int)$r['sesion_id']]);
    return $r;
}
