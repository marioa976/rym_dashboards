<?php
/**
 * Auth del módulo electoral — shim sobre el sistema de permisos del PORTAL.
 * Ya no hay usuarios propios (app_user): el login, usuarios y niveles los maneja
 * el portal. Aquí solo traducimos el usuario en sesión al formato que esperan las
 * páginas electorales: ['id','email','name','role'].
 *
 * Mapa de roles:  editor/admin del módulo  ->  'administrador' (puede importar/editar)
 *                 lector                   ->  'consulta'      (solo ve reportes)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/guard.php';   // Auth + require_module/require_editor/puede_editar

function auth_user(): ?array
{
    $u = Auth::user();
    if (!$u) return null;
    return [
        'id'    => (int)($u['id'] ?? 0),
        'email' => (string)($u['email'] ?? ''),
        'name'  => (string)($u['nombre'] ?? ($u['name'] ?? 'Usuario')),
        'role'  => puede_editar('electoral') ? 'administrador' : 'consulta',
    ];
}
