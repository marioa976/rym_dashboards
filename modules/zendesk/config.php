<?php
/**
 * Zendesk (Reportes de Servicio) · config (homologado al portal).
 * Credenciales y API key desde el config CENTRAL. Forma plana preservada
 * (la espera db.php y las páginas de la app).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../../core/guard.php';
    require_module('zendesk');
}

$__c = require __DIR__ . '/../../config/config.php';
$__m = $__c['modulos']['zendesk'];

return [
    'host'     => $__m['db']['host'],
    'port'     => $__m['db']['port'],
    'user'     => $__m['db']['user'],
    'password' => $__m['db']['pass'],
    'database' => $__m['db']['name'],
    'charset'  => $__m['db']['charset'],

    'csv_dir'  => __DIR__,
    'timezone' => 'America/Mexico_City',

    'google_maps_api_key' => $__m['google_maps_api_key'],
    'map_id'              => $__m['map_id'] ?? 'DEMO_MAP_ID',
    'mapa_centro_lat' => 20.5888,
    'mapa_centro_lng' => -100.3899,
    'mapa_zoom'       => 11,

    'zendesk_api' => $__m['zendesk_api'] ?? [],
];
