<?php
/**
 * Bootstrap del panel de administración.
 * Exige sesión + rol admin, expone $pdo y utilidades de flash.
 */
declare(strict_types=1);
require_once __DIR__ . '/../core/guard.php';
require_admin();

$pdo = Database::conn();

/** Guarda un mensaje flash para mostrar tras un redirect. */
function flash(string $tipo, string $msg): void {
    $_SESSION['flash'] = ['tipo' => $tipo, 'msg' => $msg];
}
/** Toma y limpia el flash actual. */
function flash_take(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
/** Redirige dentro del panel admin. */
function admin_redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}
/** Exige POST con CSRF válido o aborta con flash + redirect. */
function require_post_csrf(string $back): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
        flash('err', 'Solicitud inválida o sesión expirada.');
        admin_redirect($back);
    }
}
