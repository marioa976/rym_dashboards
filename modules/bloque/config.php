<?php
/**
 * Bloque · config (homologado al portal).
 * Las tablas de Bloque (usuarios_bloque / v_usuarios / asistencias / actividades)
 * viven en la BD UNIFICADA del portal (portal_qro), así que usa la conexión central.
 * Dispara el guard del portal (require_module('bloque')) salvo en CLI.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../../core/guard.php';
    require_module('bloque');
}

$__c = require __DIR__ . '/../../config/config.php';

return [
    'db'                  => $__c['db'],                       // portal_qro
    'google_maps_api_key' => $__c['google_maps']['api_key'] ?? '',
    'zona_horaria'        => 'America/Mexico_City',
];
