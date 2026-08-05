<?php
/**
 * Ejecutivo · config (homologado al portal).
 * Solo lee la BD unificada portal_qro. Guard: require_module('ejecutivo').
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../../core/guard.php';
    require_module('ejecutivo');
}

$__c = require __DIR__ . '/../../config/config.php';

return [
    'db'                  => $__c['db'],
    'google_maps_api_key' => $__c['google_maps']['api_key'] ?? '',
    'map_id'              => getenv('MAP_ID') ?: 'DEMO_MAP_ID',
    'zona_horaria'        => 'America/Mexico_City',
];
