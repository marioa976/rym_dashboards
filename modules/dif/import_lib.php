<?php
/**
 * import_lib.php  —  Lógica común del importador (CLI y web).
 *
 * Detecta columnas POR NOMBRE DE ENCABEZADO normalizado.
 * Es a prueba de columnas extra (`id`, `Latitud`, etc) o faltantes.
 */

declare(strict_types=1);

use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;

// ---------------------------------------------------------------------
// Conexión PDO
// ---------------------------------------------------------------------
function padronConnect(array $db): PDO
{
    $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ]);
    return $pdo;
}

// ---------------------------------------------------------------------
// Normalización de encabezado: 'Coordinación' -> 'coordinacion'
// ---------------------------------------------------------------------
function normalizeHeader(?string $h): string
{
    if ($h === null) return '';
    $s = trim($h);
    if ($s === '') return '';
    // Quitar acentos
    $s = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    // A minúsculas
    $s = strtolower($s);
    // Sólo letras, números y espacio
    $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s) ?? '';
    // Compactar espacios
    $s = preg_replace('/\s+/', ' ', $s) ?? '';
    return trim($s);
}

// ---------------------------------------------------------------------
// Aliases: a qué campo de DB corresponde cada encabezado normalizado
// ---------------------------------------------------------------------
function headerAliases(): array
{
    return [
        // campo_db                  => [posibles encabezados normalizados]
        'fecha_registro'   => ['fecha registro', 'fecha de registro'],
        'fecha_entrega'    => ['fecha entrega', 'fecha de entrega'],
        'cantidad'         => ['cantidad'],
        'programa'         => ['programa'],
        'coordinacion'     => ['coordinacion'],
        'tipo_apoyo'       => ['tipo de apoyo', 'tipo apoyo'],
        'recibe_ciudadano' => ['recibe el ciudadano', 'recibe ciudadano'],
        'lugar_entrega'    => ['lugar de entrega', 'lugar entrega'],
        'nombre_recibe'    => ['nombre quien recibe', 'quien recibe', 'nombre del que recibe'],
        'ciudadano'        => ['ciudadano'],
        'sexo'             => ['sexo'],
        'curp'             => ['curp'],
        'fecha_nacimiento' => ['fecha nacimiento', 'fecha de nacimiento'],
        'edad'             => ['edad'],
        'cp'               => ['cp', 'codigo postal', 'c p'],
        'delegacion'       => ['delegacion', 'municipio'],
        'colonia'          => ['colonia'],
        'calle_numero'     => ['calle y numero', 'calle y num', 'calle numero', 'domicilio'],
        'latitud'          => ['latitud', 'lat'],
        'longitud'         => ['longitud', 'lognitud', 'lng', 'lon'], // "Lognitud" típo del original
        // Estos NO se mapean a campos de DB, pero los detectamos para ignorarlos:
        'id_origen'        => ['id'],
    ];
}

// ---------------------------------------------------------------------
// Construye mapping {campo_db => indice_columna} desde el array de header
// ---------------------------------------------------------------------
function buildColumnMap(array $headerRow): array
{
    $norm = array_map('normalizeHeader', $headerRow);
    $aliases = headerAliases();
    $map = [];
    foreach ($aliases as $field => $opts) {
        foreach ($opts as $alias) {
            $idx = array_search($alias, $norm, true);
            if ($idx !== false) {
                $map[$field] = $idx;
                break;
            }
        }
    }
    return $map;
}

// ---------------------------------------------------------------------
// Helpers de limpieza A PRUEBA DE DateTime / objetos sin __toString
// ---------------------------------------------------------------------
function valToScalar(mixed $v): mixed
{
    if ($v instanceof \DateTimeInterface) {
        return $v->format('Y-m-d');
    }
    if (is_object($v)) {
        // Cualquier otro objeto: si tiene __toString lo respetamos
        if (method_exists($v, '__toString')) return (string)$v;
        return null;
    }
    return $v;
}

function excelToDate(mixed $val): ?string
{
    if ($val === null || $val === '') return null;
    if ($val instanceof \DateTimeInterface) return $val->format('Y-m-d');
    if (is_numeric($val)) {
        try {
            $unix = ((float)$val - 25569) * 86400;
            return gmdate('Y-m-d', (int)$unix);
        } catch (Throwable) { return null; }
    }
    $val = valToScalar($val);
    if (!is_scalar($val)) return null;
    $s = trim((string)$val);
    if ($s === '') return null;
    foreach (['Y-m-d','d/m/Y','d-m-Y','m/d/Y','Y/m/d','d/m/y'] as $f) {
        $d = DateTime::createFromFormat($f, $s);
        if ($d && $d->format($f) === $s) return $d->format('Y-m-d');
    }
    $ts = @strtotime($s);
    return $ts ? date('Y-m-d', $ts) : null;
}

function toIntOrNull(mixed $v): ?int
{
    $v = valToScalar($v);
    if ($v === null || $v === '') return null;
    if (!is_scalar($v) || !is_numeric($v)) return null;
    return (int)$v;
}

function toFloatOrNull(mixed $v): ?float
{
    $v = valToScalar($v);
    if ($v === null || $v === '') return null;
    if (!is_scalar($v) || !is_numeric($v)) return null;
    return (float)$v;
}

function clean(mixed $v, int $maxLen = 255, bool $upper = false): ?string
{
    $v = valToScalar($v);
    if ($v === null) return null;
    if (!is_scalar($v)) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if ($upper) $s = mb_strtoupper($s, 'UTF-8');
    if (mb_strlen($s, 'UTF-8') > $maxLen) $s = mb_substr($s, 0, $maxLen, 'UTF-8');
    return $s;
}

function cleanCp(mixed $v): ?string
{
    $v = valToScalar($v);
    if ($v === null || $v === '') return null;
    if (!is_scalar($v)) return null;
    $s = preg_replace('/\D+/', '', (string)$v) ?? '';
    if ($s === '') return null;
    if (strlen($s) === 4) $s = '0' . $s;
    return substr($s, 0, 10);
}

// ---------------------------------------------------------------------
// Importador principal
// ---------------------------------------------------------------------
function padronImport(
    PDO $pdo,
    string $xlsxPath,
    ?string $sheetName = null,
    bool $truncate = false,
    int $limit = 0,
    int $batchSize = 500,
    ?callable $onProgress = null
): array {
    if ($truncate) {
        $pdo->exec('TRUNCATE TABLE padron');
    }

    // Log de importación
    $pdo->prepare("INSERT INTO import_log (archivo) VALUES (?)")
        ->execute([basename($xlsxPath)]);
    $importId = (int)$pdo->lastInsertId();

    $options = new XlsxOptions();
    $options->SHOULD_FORMAT_DATES = false;
    $options->SHOULD_USE_1904_DATES = false;
    $reader = new XlsxReader($options);
    $reader->open($xlsxPath);

    $insertSql = "INSERT INTO padron (
        fecha_registro, fecha_entrega, cantidad, programa, coordinacion,
        tipo_apoyo, recibe_ciudadano, lugar_entrega, nombre_recibe, ciudadano,
        sexo, curp, fecha_nacimiento, edad, cp,
        delegacion, colonia, calle_numero, latitud, longitud,
        geocode_status, fila_origen, archivo_origen
    ) VALUES (
        :fecha_registro, :fecha_entrega, :cantidad, :programa, :coordinacion,
        :tipo_apoyo, :recibe_ciudadano, :lugar_entrega, :nombre_recibe, :ciudadano,
        :sexo, :curp, :fecha_nacimiento, :edad, :cp,
        :delegacion, :colonia, :calle_numero, :latitud, :longitud,
        :geocode_status, :fila_origen, :archivo_origen
    )";
    $stmt = $pdo->prepare($insertSql);
    $archivoOrigen = basename($xlsxPath);

    $totalLeidas = $totalInsert = $totalError = 0;
    $sheetEncontrada = false;
    $colMap = null;
    $rowIndex = 0;

    $emit = function(array $ev) use ($onProgress) {
        if ($onProgress) $onProgress($ev);
    };

    $pdo->beginTransaction();
    try {
        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheetName !== null && $sheet->getName() !== $sheetName) continue;
            $sheetEncontrada = true;
            $emit(['event'=>'sheet', 'name'=>$sheet->getName()]);

            $rowIndex = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $rowIndex++;
                $values = $row->toArray();

                // Detectar header en primera fila
                if ($rowIndex === 1) {
                    $colMap = buildColumnMap($values);
                    $emit(['event'=>'header_detected', 'mapping'=>$colMap, 'raw_headers'=>$values]);
                    if (empty($colMap)) {
                        throw new RuntimeException("No se detectó ninguna columna conocida en el encabezado. Headers vistos: " . implode(' | ', array_map('strval', $values)));
                    }
                    continue;
                }

                // Saltar filas totalmente vacías
                $vaciaTotal = true;
                foreach ($values as $v) {
                    if ($v !== null && $v !== '') { $vaciaTotal = false; break; }
                }
                if ($vaciaTotal) continue;

                $totalLeidas++;
                $get = fn(string $f) => isset($colMap[$f]) ? ($values[$colMap[$f]] ?? null) : null;

                try {
                    $lat = toFloatOrNull($get('latitud'));
                    $lng = toFloatOrNull($get('longitud'));

                    $params = [
                        ':fecha_registro'   => excelToDate($get('fecha_registro')),
                        ':fecha_entrega'    => excelToDate($get('fecha_entrega')),
                        ':cantidad'         => toIntOrNull($get('cantidad')),
                        ':programa'         => clean($get('programa'), 255),
                        ':coordinacion'     => clean($get('coordinacion'), 255),
                        ':tipo_apoyo'       => clean($get('tipo_apoyo'), 255),
                        ':recibe_ciudadano' => clean($get('recibe_ciudadano'), 10),
                        ':lugar_entrega'    => clean($get('lugar_entrega'), 255),
                        ':nombre_recibe'    => clean($get('nombre_recibe'), 255),
                        ':ciudadano'        => clean($get('ciudadano'), 255),
                        ':sexo'             => clean($get('sexo'), 20),
                        ':curp'             => clean($get('curp'), 20, upper: true),
                        ':fecha_nacimiento' => excelToDate($get('fecha_nacimiento')),
                        ':edad'             => toIntOrNull($get('edad')),
                        ':cp'               => cleanCp($get('cp')),
                        ':delegacion'       => clean($get('delegacion'), 255),
                        ':colonia'          => clean($get('colonia'), 255),
                        ':calle_numero'     => clean($get('calle_numero'), 255),
                        ':latitud'          => $lat,
                        ':longitud'         => $lng,
                        ':geocode_status'   => ($lat !== null && $lng !== null) ? 'OK_ORIGEN' : null,
                        ':fila_origen'      => $rowIndex,
                        ':archivo_origen'   => $archivoOrigen,
                    ];

                    $stmt->execute($params);
                    $totalInsert++;
                } catch (Throwable $e) {
                    $totalError++;
                    $emit(['event'=>'error', 'fila'=>$rowIndex, 'mensaje'=>$e->getMessage()]);
                }

                if ($totalLeidas % $batchSize === 0) {
                    $pdo->commit();
                    $emit(['event'=>'progress',
                        'leidas'=>$totalLeidas, 'insertadas'=>$totalInsert, 'errores'=>$totalError]);
                    $pdo->beginTransaction();
                }

                if ($limit > 0 && $totalLeidas >= $limit) break 2;
            }
        }
    } finally {
        if ($pdo->inTransaction()) $pdo->commit();
        $reader->close();
    }

    if (!$sheetEncontrada && $sheetName !== null) {
        throw new RuntimeException("No se encontró la hoja '$sheetName'");
    }

    $pdo->prepare("UPDATE import_log SET filas_leidas=?, filas_insertadas=?, filas_error=?, terminado_at=NOW() WHERE id=?")
        ->execute([$totalLeidas, $totalInsert, $totalError, $importId]);

    return [
        'leidas'     => $totalLeidas,
        'insertadas' => $totalInsert,
        'errores'    => $totalError,
        'import_id'  => $importId,
        'col_map'    => $colMap ?? [],
    ];
}
