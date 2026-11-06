<?php
/**
 * backfill_telefono.php — Rellena `zd_telefono` en los tickets ya almacenados,
 * extrayendo el teléfono del texto (zd_primer_mensaje / zd_descripcion) con el
 * MISMO helper que usa el import (zd_extraer_telefono). Proceso de una sola vez.
 *
 * Por lotes con cursor por ticket_id (de mayor a menor) y UPDATE con CASE
 * (rápido, un statement por lote), con reintento ante deadlock. Acotado por
 * GEO_CAP lotes. Protegido por ZENDESK_CRON_KEY.
 *
 *   GET /modules/zendesk/backfill_telefono.php?key=SECRETO
 *   GET ...?key=SECRETO&lote=1000&cap=200   (opcionales)
 */
declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');
header('Content-Type: application/json; charset=utf-8');

$__c   = require __DIR__ . '/../../config/config.php';
$key   = (string)($__c['modulos']['zendesk']['cron_key'] ?? '');
$given = (string)($_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '');
if ($key === '' || !hash_equals($key, $given)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

define('ZD_NO_GUARD', 1);
@set_time_limit(0);
ignore_user_abort(true);

require __DIR__ . '/db.php';
require_once __DIR__ . '/_zendesk_lib.php';

$LOTE = max(100, min(5000, (int)($_GET['lote'] ?? 1000)));
$CAP  = max(1, min(500, (int)($_GET['cap'] ?? 200)));

/** Ejecuta un statement reintentando ante deadlock (1213). */
function bf_exec(PDO $pdo, string $sql, array $args = [], int $tries = 4): void {
    for ($i = 1; ; $i++) {
        try { $pdo->prepare($sql)->execute($args); return; }
        catch (Throwable $e) {
            if ($i >= $tries || !str_contains($e->getMessage(), '1213')) {
                error_log('[portal][zendesk-bf] ' . $e->getMessage());
                return;
            }
            usleep(300000 * $i);
        }
    }
}

$t0 = microtime(true);
try {
    $pdo = db();
    $pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");

    $vacios_antes = (int)$pdo->query("SELECT COUNT(*) FROM tickets
        WHERE (zd_telefono IS NULL OR zd_telefono='')
          AND (zd_primer_mensaje REGEXP '[0-9]{10}' OR zd_descripcion REGEXP '[0-9]{10}')")->fetchColumn();

    $sel = $pdo->prepare("SELECT ticket_id, zd_primer_mensaje, zd_descripcion
          FROM tickets
         WHERE (zd_telefono IS NULL OR zd_telefono='')
           AND ticket_id < :cursor
           AND (zd_primer_mensaje REGEXP '[0-9]{10}' OR zd_descripcion REGEXP '[0-9]{10}')
         ORDER BY ticket_id DESC LIMIT {$LOTE}");

    $cursor = PHP_INT_MAX; $vistos = 0; $asignados = 0; $lotes = 0;
    while ($lotes < $CAP) {
        $sel->execute([':cursor' => $cursor]);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) break;

        $case = ''; $ids = []; $args = [];
        foreach ($rows as $k => $r) {
            $tel = zd_extraer_telefono((string)$r['zd_primer_mensaje'], (string)$r['zd_descripcion']);
            if ($tel !== null) {
                $id = (int)$r['ticket_id'];           // entero: seguro inline
                $case .= " WHEN {$id} THEN :tel{$k}";  // solo el teléfono va bindeado
                $ids[] = $id;
                $args[":tel{$k}"] = $tel;
                $asignados++;
            }
        }
        if ($ids) {
            $sql = "UPDATE tickets SET zd_telefono = CASE ticket_id{$case} END
                     WHERE ticket_id IN (" . implode(',', $ids) . ")
                       AND (zd_telefono IS NULL OR zd_telefono='')";
            bf_exec($pdo, $sql, $args);
        }

        $cursor = (int)min(array_column($rows, 'ticket_id'));
        $vistos += count($rows);
        $lotes++;
    }

    $vacios_despues = (int)$pdo->query("SELECT COUNT(*) FROM tickets
        WHERE (zd_telefono IS NULL OR zd_telefono='')
          AND (zd_primer_mensaje REGEXP '[0-9]{10}' OR zd_descripcion REGEXP '[0-9]{10}')")->fetchColumn();

    $res = [
        'ok'             => true,
        'candidatos_antes'   => $vacios_antes,
        'candidatos_despues' => $vacios_despues,
        'vistos'         => $vistos,
        'asignados'      => $asignados,
        'lotes'          => $lotes,
        'quedan'         => $vacios_despues,   // si > 0, vuelve a correr
    ];
} catch (Throwable $e) {
    error_log('[portal][zendesk-bf] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'fallo en el backfill']);
    exit;
}

$res['segundos'] = round(microtime(true) - $t0, 1);
error_log('[portal][zendesk-bf] ' . json_encode($res));
echo json_encode($res, JSON_UNESCAPED_UNICODE);
