<?php
/**
 * QroBici Analytics · config (homologado al portal).
 * Credenciales remotas, API key y feed de Waze desde el config CENTRAL.
 * Se conserva la forma de arreglo que espera el código original de QroBici.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../../core/guard.php';
    require_module('qrobici');
}

$__c = require __DIR__ . '/../../config/config.php';
$__m = $__c['modulos']['qrobici'];

return [
    'db' => $__m['db'],
    'vistas' => [
        'viajes' => 'dwh_viajes',
        'planes' => 'dwh_planes',
    ],
    'google_maps_api_key' => $__m['google_maps_api_key'],
    'map_id'              => $__m['map_id'] ?? 'DEMO_MAP_ID',
    'waze_feed_url'       => $__m['waze_feed_url'],
    'waze_cache_segundos' => 120,
    'waze_bbox'           => [20.40, 20.80, -100.55, -100.20],
    'fecha_desde' => null,
    'fecha_hasta' => null,
    'titulo'         => 'Reporte integral de viajes y recorridos QroBici',
    'subtitulo'      => 'Análisis de uso, demografía, estaciones y rutas del sistema de bicicleta pública de Querétaro.',
    'zona_horaria'   => 'America/Mexico_City',
    'cache_segundos' => 0,
    'co2_g_por_km'    => 210,
    'calorias_por_km' => 40,
    'debug' => false,
];
