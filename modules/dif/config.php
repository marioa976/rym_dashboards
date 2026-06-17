<?php
/**
 * DIF · config (homologado al portal).
 * Las credenciales y la API key se toman del config CENTRAL del portal.
 * Se conserva la forma de arreglo que espera el código original de DIF.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../../core/guard.php';
    require_module('dif');
}

$__c = require __DIR__ . '/../../config/config.php';
$__m = $__c['modulos']['dif'];

return [
    'db' => $__m['db'],
    'google_maps' => [
        'api_key'         => $__m['google_maps_api_key'],
        'map_id'          => $__m['map_id'] ?? 'DEMO_MAP_ID',
        'region'          => 'mx',
        'language'        => 'es',
        'default_estado'  => 'Querétaro',
        'default_pais'    => 'México',
        'sleep_us'        => 120000,
        'max_intentos'    => 2,
    ],
    'paths' => [
        'xlsx' => __DIR__ . '/PADRON-AYUDAS-255000.xlsx',
    ],
    'import' => [
        'batch_size'     => 500,
        'sheet'          => 'Hoja2',
        'truncate_first' => false,
    ],
];
