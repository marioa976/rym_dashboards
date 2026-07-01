<?php
/**
 * Qrobus · config (homologado al portal).
 * Credenciales de la BD remota y API key desde el config CENTRAL.
 * Dispara el guard del portal (require_module('qrobus')) salvo en CLI.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../../core/guard.php';
    require_module('qrobus');
}

$__c = require __DIR__ . '/../../config/config.php';
$__m = $__c['modulos']['qrobus'];

return [
    'db'                  => $__m['db'],
    'tabla'               => $__m['tabla'] ?? 'dwh_unidos',
    'google_maps_api_key' => $__m['google_maps_api_key'],
    'map_id'              => $__m['map_id'] ?? 'DEMO_MAP_ID',
    'geocode'             => $__m['geocode'] ?? ['region' => 'mx', 'language' => 'es'],
    'zona_horaria'        => 'America/Mexico_City',
];
