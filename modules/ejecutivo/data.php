<?php
/**
 * Ejecutivo · endpoint JSON de capas del mapa (carga bajo demanda).
 * Guard: require_module('ejecutivo'). Uso: data.php?layer=obras|areas|tickets|dif
 * Las capas densas (tickets/dif) van muestreadas y cacheadas (ver lib.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';     // guard
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

$layer = (string)($_GET['layer'] ?? '');
try {
    $pdo = ej_pdo();
    switch ($layer) {
        case 'obras':   $data = ej_capa_obras($pdo); break;
        case 'areas':   $data = ej_capa_areas($pdo); break;
        case 'tickets': $data = ej_capa_calor($pdo, 'tickets'); break;
        case 'dif':     $data = ej_capa_calor($pdo, 'padron'); break;
        case 'qrobici_calor': $data = ej_qrobici_calor($pdo); break;
        case 'waze':
            $w = ej_waze();
            echo json_encode($w, JSON_UNESCAPED_UNICODE); exit;   // estructura propia (alerts+jams)
        default:
            http_response_code(400);
            echo json_encode(['ok'=>false,'error'=>'Capa desconocida']); exit;
    }
    echo json_encode(['ok'=>true,'layer'=>$layer,'n'=>count($data),'points'=>$data], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'No se pudo cargar la capa.']);
}
