<?php
declare(strict_types=1);

/** Escape para salida HTML (anti XSS). */
function e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** URL base + ruta relativa. */
function url(string $path = ''): string
{
    static $base;
    if ($base === null) {
        $cfg  = require __DIR__ . '/../config/config.php';
        $base = rtrim($cfg['app']['base_url'], '/');
    }
    return $base . '/' . ltrim($path, '/');
}

/** Redirección segura interna. */
function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/** Token CSRF por sesión. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Campo oculto CSRF listo para formularios. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Verifica el token CSRF de un POST. */
function csrf_check(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}
