<?php
/**
 * vivo_data.php — JSON del tablero en vivo (paradas, posiciones, KPIs, actividad).
 * Lo consume vivo.php por polling. Requiere sesión del portal con acceso al módulo.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../core/guard.php';
require_module('cuadrillas');
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $pdo = cuad_pdo();
    cuad_ensure_schema($pdo);
    echo json_encode(cuad_vivo_payload($pdo), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[portal][cuadrillas] vivo_data: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo cargar el tablero.']);
}
