<?php
/**
 * cron_import.php — Importación INCREMENTAL automática de Zendesk (CLI).
 *
 * Usa la Incremental Export API (sin tope de 1000, paginación por cursor).
 * Arranca desde la última cobertura registrada (zendesk_import_log.max_hasta)
 * o desde un argumento. Pensado para correr por cron cada mañana.
 *
 * Uso:
 *   php cron_import.php              # incremental desde la última importación
 *   php cron_import.php 2026-01-01   # forzar un "desde" específico
 *
 * Cron (ejemplo, 7:00 AM diario):
 *   0 7 * * * /Applications/MAMP/bin/php/phpX/bin/php \
 *     /Applications/MAMP/htdocs/portal/modules/zendesk/cron_import.php \
 *     >> /tmp/zendesk_cron.log 2>&1
 */
declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Este script es solo de línea de comandos.\n"); }

require __DIR__ . '/db.php';                 // en CLI, el config NO dispara el guard
require_once __DIR__ . '/_zendesk_lib.php';

$pdo = db();
$cfg = require __DIR__ . '/config.php';
$api = $cfg['zendesk_api'] ?? [];

function out(string $m): void { fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] $m\n"); }
function fail(string $m): void { fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] ERROR: $m\n"); exit(1); }

if (empty($api['subdomain']) || empty($api['user']) || empty($api['token'])) fail('Faltan credenciales de la API de Zendesk en config.');
$mapeo = zd_cargar_mapeo($pdo);
if (!$mapeo) fail('No hay mapeo. Importa sql/zendesk_mapeo.sql primero.');

// Asegurar estructura
zd_sincronizar_estructura($pdo, $mapeo);

// Punto de partida: última cobertura o, si no hay bitácora, últimos 7 días.
$desde = null;
try { $desde = $pdo->query("SELECT MAX(`hasta`) FROM zendesk_import_log")->fetchColumn() ?: null; } catch (Throwable $e) {}
if (!empty($argv[1]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $argv[1])) $desde = $argv[1];   // override manual
if (!$desde) $desde = date('Y-m-d', strtotime('-7 days'));
$hasta = date('Y-m-d');

out("Exportación incremental Zendesk desde: $desde");

// Exportación incremental por cursor (sin tope de 1000).
$ts = strtotime($desde . ' 00:00:00');
$cursor = '';
$pagina = 0; $totOk = 0; $totFetch = 0; $totErr = 0;
$rate = 0;                                       // reintentos por rate limit

while (true) {
    $r = zd_incremental($api, $cursor, $ts);

    if (!empty($r['rate_limited'])) {
        if (++$rate > 30) { fwrite(STDERR, "Demasiados 429 seguidos, abortando.\n"); break; }
        out("  ⏳ Límite de Zendesk; espero 20s…");
        sleep(20);
        continue;
    }
    $rate = 0;
    if (!empty($r['error'])) { fwrite(STDERR, "  página " . ($pagina + 1) . ": " . $r['error'] . "\n"); $totErr++; break; }

    $pagina++;
    $tickets = $r['tickets'];
    [$ok, $errs] = zd_importar($pdo, $api, $tickets, $mapeo);
    zd_log($pdo, ['desde'=>$desde, 'hasta'=>$hasta, 'tag'=>'', 'traidos'=>count($tickets),
        'guardados'=>$ok, 'errores'=>count($errs), 'tope'=>0, 'origen'=>'cron', 'usuario_id'=>null]);
    zd_log_errores($pdo, $errs, 'cron');
    $totOk += $ok; $totFetch += count($tickets); $totErr += count($errs);
    out("  pág. $pagina: " . count($tickets) . " traídos, $ok guardados" . (count($errs) ? " · " . count($errs) . " err" : ''));

    if (!empty($r['fin']) || empty($r['next'])) break;
    if ($pagina >= 5000) { fwrite(STDERR, "Tope de seguridad (5000 páginas).\n"); break; }
    $cursor = (string)$r['next'];
    sleep(2);                                    // gentil con el rate limit incremental
}

out("Listo. Guardados/actualizados: $totOk · Traídos: $totFetch · Errores: $totErr · Páginas: $pagina");
exit($totErr > 0 ? 2 : 0);
