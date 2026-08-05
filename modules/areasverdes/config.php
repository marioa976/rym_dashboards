<?php
/**
 * Áreas Verdes · config (homologado al portal).
 * Los datos viven en la BD unificada portal_qro (tabla `areas_verdes`).
 * Dispara el guard del portal (require_module('areasverdes')) salvo en CLI.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../../core/guard.php';
    require_module('areasverdes');
}

$__c = require __DIR__ . '/../../config/config.php';

return [
    'db'                  => $__c['db'],                       // BD unificada del portal
    'google_maps_api_key' => $__c['google_maps']['api_key'] ?? '',
    'map_id'              => getenv('MAP_ID') ?: 'DEMO_MAP_ID',
    'zona_horaria'        => 'America/Mexico_City',
];
