<?php
/**
 * import.php  —  Importador del Padrón DIF (PHP 8.2+).
 *
 * Lee el .xlsx con OpenSpout en streaming e inserta en la tabla `padron`.
 *
 * v2:
 *  - Detecta columnas POR NOMBRE DE ENCABEZADO (robusto a columnas extra
 *    como `id` al inicio o `Latitud`/`Lognitud` al final).
 *  - Helpers a prueba de DateTimeImmutable (ya no truena
 *    "Object of class DateTimeImmutable could not be converted to string").
 *  - Reporte de errores más claro.
 *
 * Uso:
 *   php import.php
 *   php import.php --file=/ruta/otro.xlsx
 *   php import.php --truncate     (vacía padron antes)
 *   php import.php --limit=100    (prueba)
 */

declare(strict_types=1);

set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M');

if (!defined('STDOUT')) define('STDOUT', fopen('php://output', 'wb'));
if (!defined('STDERR')) define('STDERR', fopen('php://output', 'wb'));
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    @ob_implicit_flush(true);
    if (ob_get_level() > 0) @ob_end_flush();
}

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/import_lib.php';

use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;

$config = require __DIR__ . '/config.php';
$opts = getopt('', ['file::', 'truncate', 'sheet::', 'limit::']);

$xlsxPath  = $opts['file']  ?? $config['paths']['xlsx'];
$sheetName = $opts['sheet'] ?? $config['import']['sheet'];
$truncate  = isset($opts['truncate']) || !empty($config['import']['truncate_first']);
$limit     = isset($opts['limit']) ? (int)$opts['limit'] : 0;

if (!is_file($xlsxPath)) {
    fwrite(STDERR, "ERROR: no encontré el archivo: $xlsxPath\n");
    exit(1);
}

echo "Archivo : $xlsxPath\n";
echo "Hoja    : " . ($sheetName ?? '(primera)') . "\n";
echo "Truncate: " . ($truncate ? 'SI' : 'NO') . "\n";
if ($limit > 0) echo "Límite  : $limit filas\n";
echo str_repeat('-', 60) . "\n";

$pdo = padronConnect($config['db']);

$result = padronImport(
    pdo: $pdo,
    xlsxPath: $xlsxPath,
    sheetName: $sheetName,
    truncate: $truncate,
    limit: $limit,
    batchSize: (int)($config['import']['batch_size'] ?? 500),
    onProgress: function(array $ev) {
        if ($ev['event'] === 'progress') {
            echo sprintf("  ... %d leídas (insertadas: %d, errores: %d, mem: %s)\n",
                $ev['leidas'], $ev['insertadas'], $ev['errores'], formatMem(memory_get_usage(true)));
        } elseif ($ev['event'] === 'error') {
            fwrite(STDERR, "Fila {$ev['fila']} - ERROR: {$ev['mensaje']}\n");
        } elseif ($ev['event'] === 'header_detected') {
            echo "Columnas detectadas: ";
            foreach ($ev['mapping'] as $k => $v) echo "$k=$v ";
            echo "\n";
        }
    }
);

echo str_repeat('-', 60) . "\n";
echo "TERMINADO\n";
echo "  Filas leídas:      {$result['leidas']}\n";
echo "  Filas insertadas:  {$result['insertadas']}\n";
echo "  Filas con error:   {$result['errores']}\n";
echo "  Memoria pico:      " . formatMem(memory_get_peak_usage(true)) . "\n";
echo "\nSiguiente paso:  abre http://localhost:8888/dif/geocode_ui.php\n";

function formatMem(int $b): string { return number_format($b/1048576, 1) . ' MB'; }
