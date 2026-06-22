<?php
/**
 * Bootstrap del módulo electoral, integrado al portal "Querétaro con Futuro".
 *
 * Reemplaza la sesión/login propios del módulo por el sistema de permisos del
 * portal. Las páginas no cambian: siguen definiendo $REQUIRE_ROLES y usando
 * auth_user() / reporteador_pdo().
 *
 *   - Páginas de SOLO escritura ($REQUIRE_ROLES = ['administrador'])  -> require_editor('electoral')
 *   - Reportes / inicio (incluyen rol de vista, o sin $REQUIRE_ROLES) -> require_module('electoral')
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';   // carga el guard del portal (Auth, require_module, etc.)

$__dbg = (bool)(reporteador_config()['app']['debug'] ?? false);
ini_set('display_errors', $__dbg ? '1' : '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/** Base URL del módulo (sin trailing slash). Conserva el comportamiento original. */
function reporteador_base_url_safe(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if (preg_match('#/(admin|api|reports|partials|assets)(/|$)#', $dir)) {
        $dir = preg_replace('#/(admin|api|reports|partials|assets).*$#', '', $dir);
    }
    return $dir;
}

// ---- Control de acceso con el sistema del portal ----
if (PHP_SAPI !== 'cli') {
    $viewRoles = ['consulta', 'gerente', 'cliente', 'lector'];
    $soloAdmin = !empty($REQUIRE_ROLES) && !array_intersect((array)$REQUIRE_ROLES, $viewRoles);
    if ($soloAdmin) {
        require_editor('electoral');   // importar resultados / admin = escritura
    } else {
        require_module('electoral');    // ver reportes = cualquier nivel del módulo
    }
}
