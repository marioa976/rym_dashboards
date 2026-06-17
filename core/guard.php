<?php
/**
 * Guards de acceso. Incluir al inicio de cada página protegida.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

Auth::start();

/** Exige sesión iniciada. */
function require_login(): void
{
    if (!Auth::check()) {
        redirect('login.php');
    }
}

/** ¿El usuario tiene acceso a un módulo por su clave? */
function puede_modulo(string $clave): bool
{
    foreach (($_SESSION['modulos'] ?? []) as $m) {
        if ($m['clave'] === $clave) {
            return true;
        }
    }
    return false;
}

/** Exige login + acceso al módulo indicado. */
function require_module(string $clave): void
{
    require_login();
    if (!puede_modulo($clave)) {
        http_response_code(403);
        redirect('acceso-denegado.php');
    }
}

/** ¿El usuario en sesión es administrador? */
function es_admin(): bool
{
    return !empty(Auth::user()['es_admin']);
}

/** Exige login + ser administrador (para el panel de administración). */
function require_admin(): void
{
    require_login();
    if (!es_admin()) {
        http_response_code(403);
        redirect('acceso-denegado.php');
    }
}

/**
 * Nivel del usuario en un módulo: 'lector' | 'editor' | 'admin' | null.
 * Los administradores tienen nivel 'admin' en todo.
 */
function nivel_modulo(string $clave): ?string
{
    if (es_admin()) return 'admin';
    foreach (($_SESSION['modulos'] ?? []) as $m) {
        if ($m['clave'] === $clave) {
            return $m['nivel'] ?? 'lector';
        }
    }
    return null;
}

/** ¿Puede editar (editor o admin) en un módulo? Helper expuesto para los módulos. */
function puede_editar(string $clave): bool
{
    return in_array(nivel_modulo($clave), ['editor', 'admin'], true);
}
