<?php
/**
 * Biblioteca de import de resultados electorales.
 *
 * Maneja 3 tipos de archivo:
 *   - csv_2024_resultados    → CSV por casilla con columnas de partidos/coaliciones
 *   - csv_2024_candidaturas  → CSV de catálogo de candidaturas por distrito/municipio
 *   - xlsx_2021_casilla      → XLSX del IEEQ 2020-2021, hoja "Casilla"
 *
 * Output normalizado de parser:
 *   [
 *     'tipo' => 'csv_2024_resultados',
 *     'ambito_tipo' => 'distrito' | 'municipio' | 'estado',
 *     'partidos'    => ['PAN','PRI',...],
 *     'coaliciones' => ['PAN-PRI','PAN-PRD',...],
 *     'columnas_especiales' => ['NULOS','NO_REGISTRADAS'],
 *     'rows' => [
 *        ['line'=>3, 'seccion'=>416, 'tipo_casilla'=>'B', 'num_casilla'=>1, 'ext_contigua'=>0,
 *         'ubicacion'=>'URBANA', 'ambito_codigo'=>'001', 'ambito_nombre'=>'QUERETARO',
 *         'votos'=>['PAN'=>352,'PRI'=>24,...], 'lista_nominal'=>572, 'total_votos'=>463,
 *         'observaciones'=>null],
 *        ...
 *     ],
 *     'errors' => [['line'=>5, 'msg'=>'...'], ...]
 *   ]
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/xlsx_reader.php';

// Códigos canónicos de votos especiales (lo que se guarda en BD como voto_codigo).
const IR_SPECIAL_CANONICAL = ['NULOS', 'NO_REGISTRADAS'];

// Mapa de aliases → canónico. Las llaves son nombres tal como pueden aparecer
// en cabeceras de archivos (uppercased, trimmed).
const IR_SPECIAL_ALIASES = [
    'NULOS'                       => 'NULOS',
    'VOTOS_NULOS'                 => 'NULOS',
    'NUM_VOTOS_NULOS'             => 'NULOS',
    'NO_REGISTRADAS'              => 'NO_REGISTRADAS',
    'NO REGISTRADAS'              => 'NO_REGISTRADAS',
    'CANDIDATOS_NO_REGISTRADOS'   => 'NO_REGISTRADAS',
    'CANDIDATOS NO REGISTRADOS'   => 'NO_REGISTRADAS',
    'NUM_VOTOS_CAN_NREG'          => 'NO_REGISTRADAS',
];

// Cualquier columna que NO sea voto por opción debe estar acá.
// NO incluimos NUM_VOTOS_NULOS ni NUM_VOTOS_CAN_NREG — esas son votos especiales (ver IR_SPECIAL_ALIASES).
// Esto cubre identificadores geográficos, metadatos de casilla y AGREGADOS pre-calculados
// (NUM_VOTOS_VALIDOS, TOTAL_VOTOS_CALCULADOS, etc.)
const IR_META_COLS = [
    // Identificadores geográficos
    'ID_ESTADO', 'ESTADO', 'NOMBRE_ESTADO',
    'ID_DISTRITO_LOCAL', 'DISTRITO_LOCAL', 'CABECERA_DISTRITAL_LOCAL',
    'ID_DISTRITO_FEDERAL', 'DISTRITO_FEDERAL', 'CABECERA_DISTRITAL_FEDERAL',
    'ID_MUNICIPIO', 'MUNICIPIO', 'ID_MUNICIPIO_LOCAL', 'MUNICIPIO_LOCAL',
    // Casilla / sección
    'SECCION', 'SECCIÓN', 'ID_CASILLA', 'TIPO_CASILLA', 'CASILLA',
    'EXT_CONTIGUA', 'UBICACION_CASILLA', 'UBICACION',
    'CASILLA_CONTIGUA',
    // Agregados pre-calculados (NO son una columna de voto por opción)
    'TOTAL_VOTOS', 'TOTAL VOTOS', 'TOTAL_VOTOS_CALCULADOS', 'TOTAL DE VOTOS',
    'NUM_VOTOS_VALIDOS', 'VOTOS_VALIDOS', 'VOTOS VÁLIDOS',
    'LISTA_NOMINAL', 'LISTA NOMINAL', 'LISTA_NOMINAL_CASILLA',
    // Metadatos administrativos
    'OBSERVACIONES', 'ESTATUS_ACTA', 'TRIBUNAL', 'RUTA_ACTA',
    'TIPO_ACTA', 'FECHA_HORA_ACOPIO', 'FECHA_HORA_CAPTURA', 'CONTABILIZADA',
];

/**
 * Si la columna es un voto especial (con alias), devuelve su código canónico.
 * Si no, null.
 */
function ir_special_canonical(string $col): ?string
{
    $u = mb_strtoupper(trim($col), 'UTF-8');
    return IR_SPECIAL_ALIASES[$u] ?? null;
}

/**
 * True si la columna debe ignorarse como metadata.
 * (CI_N NO es metadata — es candidatura independiente, se maneja como partido.)
 */
function ir_is_metadata(string $col): bool
{
    $u = mb_strtoupper(trim($col), 'UTF-8');
    return in_array($u, IR_META_COLS, true);
}

/**
 * Detecta si una sigla es un código de coalición.
 * 2024 usa "-" como separador (PAN-PRI-PRD). 2021 usaba "_" (PAN_PRD_QI).
 * Aceptamos ambos.
 */
function ir_is_coalition_code(string $col): bool
{
    $u = mb_strtoupper(trim($col), 'UTF-8');
    if (ir_special_canonical($u) !== null || ir_is_metadata($u)) return false;
    // Forma: SIGLA[sep]SIGLA([sep]SIGLA)* — al menos dos componentes
    if (preg_match('/^[A-Z0-9]+(-[A-Z0-9]+)+$/', $u) === 1) return true;
    if (preg_match('/^[A-Z0-9]+(_[A-Z0-9]+)+$/', $u) === 1) {
        // Excluir prefijos típicos de metadata que también contienen "_"
        if (preg_match('/^(ID_|NUM_|TOTAL_|VOTOS_|LISTA_|TIPO_|EXT_|UBICACION_|FECHA_|RUTA_|ESTATUS_|CABECERA_|DISTRITO_|MUNICIPIO_)/', $u)) return false;
        return true;
    }
    return false;
}

/**
 * Detecta si una sigla parece un partido o candidatura independiente (sin separadores).
 * También maneja CI_1, CI_2... como candidatura independiente (acepta un único guión bajo).
 */
function ir_is_party_code(string $col): bool
{
    $u = mb_strtoupper(trim($col), 'UTF-8');
    if (ir_special_canonical($u) !== null || ir_is_metadata($u)) return false;
    if (str_contains($u, '-')) return false;
    // Caso especial: candidaturas independientes CI_N (N variable: CI_1, CI_2, …)
    if (preg_match('/^CI_\d+$/', $u) === 1) return true;
    // No aceptamos guiones bajos (eso es coalición 2021)
    if (str_contains($u, '_')) return false;
    return preg_match('/^[A-Z][A-Z0-9]{1,19}$/', $u) === 1;
}

/**
 * Normaliza string a entero o null.
 */
function ir_int_or_null($v): ?int
{
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '' || !is_numeric(str_replace(['.', ','], '', $s))) return null;
    return (int)preg_replace('/[^\-0-9]/', '', $s);
}

/* ============================================================
 * PARSER 2024 CSV (RESULTADOS)
 * ============================================================ */
function ir_parse_csv_2024_resultados(string $path): array
{
    $result = [
        'tipo' => 'csv_2024_resultados',
        'ambito_tipo' => null,
        'partidos' => [],
        'coaliciones' => [],
        'columnas_especiales' => [],
        'rows' => [],
        'errors' => [],
    ];

    $fh = fopen($path, 'r');
    if (!$fh) {
        $result['errors'][] = ['line' => 0, 'msg' => 'No se pudo abrir el archivo'];
        return $result;
    }

    // BOM tolerante
    $header = fgetcsv($fh);
    if ($header && isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    }
    if (!$header) {
        $result['errors'][] = ['line' => 1, 'msg' => 'Header vacío'];
        fclose($fh); return $result;
    }
    $headerUp = array_map(fn($v) => mb_strtoupper(trim((string)$v), 'UTF-8'), $header);

    // Detección del ámbito (distrito o municipio)
    $idxAmbitoCode = $idxAmbitoName = null;
    $ambitoTipo = null;
    foreach ($headerUp as $i => $h) {
        if ($h === 'ID_DISTRITO_LOCAL') { $idxAmbitoCode = $i; $ambitoTipo = 'distrito'; }
        if ($h === 'DISTRITO_LOCAL' && $ambitoTipo === 'distrito') $idxAmbitoName = $i;
        if ($h === 'ID_MUNICIPIO')      { $idxAmbitoCode = $i; $ambitoTipo = 'municipio'; }
        if ($h === 'MUNICIPIO' && $ambitoTipo === 'municipio') $idxAmbitoName = $i;
    }
    $result['ambito_tipo'] = $ambitoTipo;

    $idxSec = array_search('SECCION', $headerUp, true);
    $idxIdCas = array_search('ID_CASILLA', $headerUp, true);
    $idxTipoCas = array_search('TIPO_CASILLA', $headerUp, true);
    $idxExt = array_search('EXT_CONTIGUA', $headerUp, true);
    $idxUbic = array_search('UBICACION_CASILLA', $headerUp, true);
    $idxTotal = array_search('TOTAL_VOTOS', $headerUp, true);
    $idxLN = array_search('LISTA_NOMINAL', $headerUp, true);
    $idxObs = array_search('OBSERVACIONES', $headerUp, true);
    $idxNulos = array_search('NULOS', $headerUp, true);
    $idxNoReg = array_search('NO_REGISTRADAS', $headerUp, true);

    foreach ($headerUp as $i => $h) {
        if ($canonical = ir_special_canonical($h)) {
            // Guardar con código canónico; si ya hay otra columna alias, la última gana
            $result['columnas_especiales'][$canonical] = $i;
        }
        elseif (ir_is_party_code($h))      $result['partidos'][$h] = $i;
        elseif (ir_is_coalition_code($h))  $result['coaliciones'][$h] = $i;
    }

    if ($idxSec === false || $idxAmbitoCode === null) {
        $result['errors'][] = ['line' => 1, 'msg' => 'Faltan columnas obligatorias (SECCION / ID_DISTRITO_LOCAL o ID_MUNICIPIO)'];
        fclose($fh); return $result;
    }

    $line = 1;
    while (($row = fgetcsv($fh)) !== false) {
        $line++;
        $sec = ir_int_or_null($row[$idxSec] ?? null);
        if ($sec === null) { continue; } // skip filas en blanco

        $ambitoCode = trim(ltrim((string)($row[$idxAmbitoCode] ?? ''), "'"));
        $ambitoCode = preg_replace('/^0+/', '', $ambitoCode) ?: $ambitoCode;

        $tipoCas = trim((string)($row[$idxTipoCas] ?? ''));
        $numCas  = ir_int_or_null($row[$idxIdCas] ?? null) ?? 1;
        $ext     = ir_int_or_null($row[$idxExt] ?? 0) ?? 0;

        $votos = [];
        foreach ($result['partidos'] as $code => $i)    $votos[$code] = ir_int_or_null($row[$i] ?? null) ?? 0;
        foreach ($result['coaliciones'] as $code => $i) $votos[$code] = ir_int_or_null($row[$i] ?? null) ?? 0;
        if ($idxNulos !== false) $votos['NULOS']          = ir_int_or_null($row[$idxNulos] ?? null) ?? 0;
        if ($idxNoReg !== false) $votos['NO_REGISTRADAS'] = ir_int_or_null($row[$idxNoReg] ?? null) ?? 0;

        $result['rows'][] = [
            'line'         => $line,
            'seccion'      => $sec,
            'tipo_casilla' => $tipoCas,
            'num_casilla'  => $numCas,
            'ext_contigua' => $ext,
            'ubicacion'    => $idxUbic !== false ? trim((string)($row[$idxUbic] ?? '')) : null,
            'ambito_codigo' => $ambitoCode,
            'ambito_nombre' => $idxAmbitoName !== null ? trim((string)($row[$idxAmbitoName] ?? '')) : null,
            'votos'         => $votos,
            'lista_nominal' => $idxLN !== false ? ir_int_or_null($row[$idxLN] ?? null) : null,
            'total_votos'   => $idxTotal !== false ? ir_int_or_null($row[$idxTotal] ?? null) : null,
            'observaciones' => $idxObs !== false ? trim((string)($row[$idxObs] ?? '')) : null,
        ];
    }
    fclose($fh);
    return $result;
}

/* ============================================================
 * PARSER 2024 CSV (CANDIDATURAS)
 * ============================================================ */
function ir_parse_csv_2024_candidaturas(string $path): array
{
    $result = [
        'tipo' => 'csv_2024_candidaturas',
        'ambito_tipo' => null,
        'rows' => [],
        'errors' => [],
    ];
    $fh = fopen($path, 'r');
    if (!$fh) { $result['errors'][] = ['line' => 0, 'msg' => 'No se pudo abrir']; return $result; }
    $header = fgetcsv($fh);
    if ($header && isset($header[0])) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    $headerUp = array_map(fn($v) => mb_strtoupper(trim((string)$v), 'UTF-8'), $header ?: []);

    $idxAmbito = null;
    if (in_array('ID_DISTRITO_LOCAL', $headerUp, true)) {
        $idxAmbito = array_search('ID_DISTRITO_LOCAL', $headerUp, true);
        $result['ambito_tipo'] = 'distrito';
    } elseif (in_array('ID_MUNICIPIO_LOCAL', $headerUp, true)) {
        $idxAmbito = array_search('ID_MUNICIPIO_LOCAL', $headerUp, true);
        $result['ambito_tipo'] = 'municipio';
    } elseif (in_array('ID_MUNICIPIO', $headerUp, true)) {
        $idxAmbito = array_search('ID_MUNICIPIO', $headerUp, true);
        $result['ambito_tipo'] = 'municipio';
    }
    $idxPartido = array_search('PARTIDO_CI', $headerUp, true);
    $idxProp = array_search('CANDIDATURA_PROPIETARIA', $headerUp, true);
    $idxSupl = array_search('CANDIDATURA_SUPLENTE', $headerUp, true);

    if ($idxAmbito === null || $idxPartido === false) {
        $result['errors'][] = ['line' => 1, 'msg' => 'Faltan columnas ID_DISTRITO_LOCAL/ID_MUNICIPIO o PARTIDO_CI'];
        fclose($fh); return $result;
    }

    $line = 1;
    while (($row = fgetcsv($fh)) !== false) {
        $line++;
        if (empty(array_filter($row))) continue;
        $ambitoCode = trim(ltrim((string)($row[$idxAmbito] ?? ''), "'"));
        $ambitoCode = preg_replace('/^0+/', '', $ambitoCode) ?: $ambitoCode;
        $partidoCode = trim((string)($row[$idxPartido] ?? ''));
        if ($partidoCode === '' || $ambitoCode === '') continue;
        $result['rows'][] = [
            'line' => $line,
            'ambito_codigo' => $ambitoCode,
            'partido_o_coalicion_codigo' => $partidoCode,
            'candidatura_propietaria' => $idxProp !== false ? trim((string)($row[$idxProp] ?? '')) : null,
            'candidatura_suplente'    => $idxSupl !== false ? trim((string)($row[$idxSupl] ?? '')) : null,
        ];
    }
    fclose($fh);
    return $result;
}

/* ============================================================
 * PARSER 2021 XLSX (hoja Casilla)
 * ============================================================ */
function ir_parse_xlsx_2021_casilla(string $path, ?string $sheetName = null): array
{
    $xlsx = new SimpleXlsx($path);
    $sheets = $xlsx->sheets();
    $sheetName = $sheetName ?? 'Casilla';
    if (!isset($sheets[$sheetName])) {
        // 1) Buscar case-insensitive con el nombre exacto
        $foundExact = null;
        foreach ($sheets as $n => $p) if (strcasecmp($n, $sheetName) === 0) { $foundExact = $n; break; }
        if ($foundExact) {
            $sheetName = $foundExact;
        } else {
            // 2) Fallback: cualquier hoja que comience con CASILLA (CASILLAS, CASILLAESPECIAL)
            foreach ($sheets as $n => $p) {
                $u = mb_strtoupper($n, 'UTF-8');
                if (str_starts_with($u, 'CASILLA')) { $sheetName = $n; break; }
            }
        }
    }
    if (!isset($sheets[$sheetName])) {
        $found = implode(', ', array_keys($sheets));
        return ['tipo'=>'xlsx_2021_casilla','rows'=>[], 'errors'=>[['line'=>0,'msg'=>"Hoja '$sheetName' no encontrada. Hojas disponibles: $found"]]];
    }
    $rows = $xlsx->readSheetByPath($sheets[$sheetName]);

    // Encontrar fila de encabezado (la que contiene SECCION o SECCIÓN)
    $headerIdx = -1;
    foreach ($rows as $i => $r) {
        foreach ($r as $v) {
            $u = mb_strtoupper(trim((string)$v), 'UTF-8');
            if ($u === 'SECCION' || $u === 'SECCIÓN') { $headerIdx = $i; break 2; }
        }
    }
    if ($headerIdx < 0) {
        return ['tipo'=>'xlsx_2021_casilla','rows'=>[], 'errors'=>[['line'=>0,'msg'=>'No se encontró fila de encabezado']]];
    }
    $header = $rows[$headerIdx];
    $headerUp = [];
    foreach ($header as $col => $v) $headerUp[$col] = mb_strtoupper(trim((string)$v), 'UTF-8');

    // El XLSX 2021 trae a la vez ID_DISTRITO_LOCAL e ID_MUNICIPIO (por casilla).
    // Guardamos AMBOS índices — el commit elige cuál usar según el tipo de elección.
    $idxDistCode = $idxDistName = null;
    $idxMuniCode = $idxMuniName = null;
    foreach ($headerUp as $col => $h) {
        if ($h === 'ID_DISTRITO_LOCAL')             $idxDistCode = $col;
        if ($h === 'CABECERA_DISTRITAL_LOCAL')      $idxDistName = $col;
        if ($h === 'ID_MUNICIPIO')                  $idxMuniCode = $col;
        if ($h === 'MUNICIPIO')                     $idxMuniName = $col;
    }
    // Fallback default: prefiere distrito si está disponible; si no, municipio
    $ambitoTipo = $idxDistCode !== null ? 'distrito' : ($idxMuniCode !== null ? 'municipio' : null);
    $idxAmbitoCode = $idxDistCode ?? $idxMuniCode;
    $idxAmbitoName = $idxDistName ?? $idxMuniName;
    $idxSec      = array_search('SECCION',     $headerUp, true);
    if ($idxSec === false) $idxSec = array_search('SECCIÓN', $headerUp, true);
    $idxIdCas    = array_search('ID_CASILLA',  $headerUp, true);
    $idxTipoCas  = array_search('TIPO_CASILLA',$headerUp, true);
    $idxExt      = array_search('EXT_CONTIGUA',$headerUp, true);
    $idxCasilla  = array_search('CASILLA',     $headerUp, true);
    $idxLN       = false; $idxTotal = false; $idxObs = false;
    foreach ($headerUp as $c => $h) {
        if ($h === 'LISTA_NOMINAL_CASILLA' || $h === 'LISTA NOMINAL CASILLA' || $h === 'LISTA_NOMINAL') $idxLN = $c;
        if ($h === 'TOTAL_VOTOS_CALCULADOS' || $h === 'TOTAL DE VOTOS' || $h === 'TOTAL_VOTOS') $idxTotal = $c;
        if ($h === 'OBSERVACIONES') $idxObs = $c;
    }

    $result = [
        'tipo' => 'xlsx_2021_casilla',
        'ambito_tipo' => $ambitoTipo,
        'partidos' => [],
        'coaliciones' => [],
        'columnas_especiales' => [],
        'rows' => [],
        'errors' => [],
    ];

    foreach ($headerUp as $col => $h) {
        if ($h === '' || $col === $idxAmbitoCode || $col === $idxAmbitoName ||
            $col === $idxSec || $col === $idxIdCas || $col === $idxTipoCas ||
            $col === $idxExt || $col === $idxCasilla || $col === $idxLN ||
            $col === $idxTotal || $col === $idxObs) continue;
        if ($canonical = ir_special_canonical($h)) {
            $result['columnas_especiales'][$canonical] = $col;
        }
        elseif (ir_is_party_code($h))     $result['partidos'][$h] = $col;
        elseif (ir_is_coalition_code($h)) $result['coaliciones'][$h] = $col;
    }

    for ($i = $headerIdx + 1; $i < count($rows); $i++) {
        $r = $rows[$i];
        $sec = ir_int_or_null($r[$idxSec] ?? null);
        if ($sec === null) continue;
        $line = $i + 1;
        $ambitoCode = preg_replace('/^0+/', '', trim((string)($r[$idxAmbitoCode] ?? ''))) ?: trim((string)($r[$idxAmbitoCode] ?? ''));
        $tipoCas = $idxTipoCas !== false ? trim((string)($r[$idxTipoCas] ?? '')) : '';
        $numCas  = $idxIdCas   !== false ? (ir_int_or_null($r[$idxIdCas] ?? null) ?? 1) : 1;
        $ext     = $idxExt     !== false ? (ir_int_or_null($r[$idxExt] ?? 0) ?? 0) : 0;

        $votos = [];
        foreach ($result['partidos']    as $code => $col) $votos[$code] = ir_int_or_null($r[$col] ?? null) ?? 0;
        foreach ($result['coaliciones'] as $code => $col) $votos[$code] = ir_int_or_null($r[$col] ?? null) ?? 0;
        foreach ($result['columnas_especiales'] as $code => $col) $votos[$code] = ir_int_or_null($r[$col] ?? null) ?? 0;

        // Capturar ambos códigos por fila si están disponibles
        $distCode = $idxDistCode !== null
            ? (preg_replace('/^0+/', '', trim((string)($r[$idxDistCode] ?? ''))) ?: trim((string)($r[$idxDistCode] ?? '')))
            : null;
        $muniCode = $idxMuniCode !== null
            ? (preg_replace('/^0+/', '', trim((string)($r[$idxMuniCode] ?? ''))) ?: trim((string)($r[$idxMuniCode] ?? '')))
            : null;
        $distName = $idxDistName !== null ? trim((string)($r[$idxDistName] ?? '')) : null;
        $muniName = $idxMuniName !== null ? trim((string)($r[$idxMuniName] ?? '')) : null;

        $result['rows'][] = [
            'line'         => $line,
            'seccion'      => $sec,
            'tipo_casilla' => $tipoCas,
            'num_casilla'  => $numCas,
            'ext_contigua' => $ext,
            'ubicacion'    => null,
            'ambito_codigo' => $ambitoCode,
            'ambito_nombre' => $idxAmbitoName !== null ? trim((string)($r[$idxAmbitoName] ?? '')) : null,
            // Datos adicionales para que el commit elija según tipo de elección
            'ambito_codigo_distrito'  => $distCode,
            'ambito_nombre_distrito'  => $distName,
            'ambito_codigo_municipio' => $muniCode,
            'ambito_nombre_municipio' => $muniName,
            'votos'         => $votos,
            'lista_nominal' => $idxLN    !== false ? ir_int_or_null($r[$idxLN] ?? null)    : null,
            'total_votos'   => $idxTotal !== false ? ir_int_or_null($r[$idxTotal] ?? null) : null,
            'observaciones' => $idxObs   !== false ? trim((string)($r[$idxObs] ?? null))   : null,
        ];
    }
    return $result;
}

/* ============================================================
 * VALIDACIÓN cruzada con BD
 * ============================================================ */
function ir_validate(array $parsed, PDO $pdo, int $procesoId, int $tipoEleccionId): array
{
    $report = [
        'totals' => [
            'casillas' => count($parsed['rows']),
            'secciones' => 0,
            'ambitos' => 0,
            'partidos' => count($parsed['partidos'] ?? []),
            'coaliciones' => count($parsed['coaliciones'] ?? []),
            'errores' => count($parsed['errors'] ?? []),
            'sanity_mismatch' => 0,
        ],
        'secciones_status' => ['ieeq' => 0, 'pendiente' => 0, 'historica' => 0, 'fantasma' => 0],
        'secciones_fantasma' => [],   // [sec => count]
        'partidos_nuevos' => [],
        'coaliciones_nuevas' => [],
        'sanity_issues' => [],        // ['line', 'sec', 'declared', 'summed']
        'rows' => $parsed['rows'],
        'partidos_existentes' => [],
        'coaliciones_existentes_codes' => [],
    ];

    // Secciones distintas
    $secs = [];
    $ambitos = [];
    foreach ($parsed['rows'] as $r) {
        $secs[$r['seccion']] = true;
        if (!empty($r['ambito_codigo'])) $ambitos[$r['ambito_codigo']] = true;
    }
    $report['totals']['secciones'] = count($secs);
    $report['totals']['ambitos']   = count($ambitos);

    // Status de secciones
    if (!empty($secs)) {
        $ids = array_keys($secs);
        $place = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $pdo->prepare("SELECT num_seccion FROM secciones WHERE num_seccion IN ($place)");
        $stmt->execute($ids); $inIeeq = array_flip(array_column($stmt->fetchAll(), 'num_seccion'));
        $stmt = $pdo->prepare("SELECT num_seccion FROM secciones_pendientes WHERE num_seccion IN ($place)");
        $stmt->execute($ids); $inPend = array_flip(array_column($stmt->fetchAll(), 'num_seccion'));
        $stmt = $pdo->prepare("SELECT num_seccion FROM secciones_historicas WHERE num_seccion IN ($place)");
        $stmt->execute($ids); $inHist = array_flip(array_column($stmt->fetchAll(), 'num_seccion'));

        $counter = [];
        foreach ($parsed['rows'] as $r) $counter[$r['seccion']] = ($counter[$r['seccion']] ?? 0) + 1;
        foreach ($ids as $s) {
            if     (isset($inIeeq[$s])) $report['secciones_status']['ieeq']++;
            elseif (isset($inPend[$s])) $report['secciones_status']['pendiente']++;
            elseif (isset($inHist[$s])) $report['secciones_status']['historica']++;
            else                        { $report['secciones_status']['fantasma']++; $report['secciones_fantasma'][$s] = $counter[$s] ?? 0; }
        }
    }

    // Partidos nuevos (códigos en archivo que no están en `partidos`)
    if (!empty($parsed['partidos'])) {
        $codes = array_keys($parsed['partidos']);
        $place = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $pdo->prepare("SELECT siglas FROM partidos WHERE siglas IN ($place)");
        $stmt->execute($codes);
        $existing = array_flip(array_column($stmt->fetchAll(), 'siglas'));
        foreach ($codes as $c) {
            if (isset($existing[$c])) $report['partidos_existentes'][] = $c;
            else                       $report['partidos_nuevos'][] = $c;
        }
    }
    // Coaliciones existentes para la elección (al nivel proceso+tipo, sin ambito_codigo aún resuelto)
    // Las nuevas las identificamos como "siempre nuevas" porque las coaliciones se crean por elección.
    $report['coaliciones_nuevas'] = array_keys($parsed['coaliciones'] ?? []);

    // Sanity check: suma de votos por casilla vs total_votos declarado
    foreach ($parsed['rows'] as $r) {
        $suma = 0;
        foreach ($r['votos'] as $v) $suma += $v;
        if ($r['total_votos'] !== null && (int)$suma !== (int)$r['total_votos']) {
            $report['totals']['sanity_mismatch']++;
            if (count($report['sanity_issues']) < 50) {
                $report['sanity_issues'][] = [
                    'line' => $r['line'], 'sec' => $r['seccion'],
                    'declared' => (int)$r['total_votos'], 'summed' => (int)$suma,
                    'diff' => (int)$suma - (int)$r['total_votos'],
                ];
            }
        }
    }

    return $report;
}

/* ============================================================
 * COMMIT — inserta todo dentro de UNA transacción atómica.
 * Devuelve el resumen de lo creado.
 * ============================================================ */
function ir_commit(
    array $parsed,
    array $validation,
    int $procesoId,
    int $tipoEleccionId,
    array $opts,
    PDO $pdo,
    string $sourceFile
): array {
    $now = date('Y-m-d H:i:s');
    $userId = function_exists('auth_user') ? (auth_user()['id'] ?? null) : null;
    $registerHistoricas = !empty($opts['register_historicas']);
    $registerNewParties = !empty($opts['register_new_parties']);
    $registerNewCoaliciones = !empty($opts['register_new_coaliciones']);
    $tipo = $parsed['tipo'];

    // Abrir bitácora
    $logStmt = $pdo->prepare(
        "INSERT INTO import_log_resultados
           (archivo, tipo, proceso_id, source, rows_total, started_at, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $logStmt->execute([$sourceFile, $tipo, $procesoId, $opts['source'] ?? 'web', count($parsed['rows']), $now, $userId]);
    $logId = (int)$pdo->lastInsertId();

    $stats = [
        'log_id' => $logId,
        'rows_ok' => 0, 'rows_failed' => 0, 'rows_skipped' => 0,
        'casillas_creadas' => 0, 'resultados_insertados' => 0,
        'secciones_historicas_creadas' => 0, 'partidos_nuevos' => 0,
        'coaliciones_nuevas' => 0, 'candidatos_creados' => 0,
        'errors' => [],
    ];

    $pdo->beginTransaction();
    try {
        /* ============================================================
         * Rama especial: CSV de CANDIDATURAS (catálogo, no votos)
         * ============================================================ */
        if ($tipo === 'csv_2024_candidaturas') {
            // Detectar si el tipo de elección es estatal
            $tA = $pdo->prepare("SELECT ambito FROM tipos_eleccion WHERE id=?");
            $tA->execute([$tipoEleccionId]);
            $candForceEstado = ($tA->fetchColumn() === 'estado');

            $selEleccion = $pdo->prepare(
                "SELECT id FROM elecciones WHERE proceso_id=? AND tipo_id=? AND ambito_codigo=? LIMIT 1"
            );
            $selEleccionEstado = $pdo->prepare(
                "SELECT id FROM elecciones WHERE proceso_id=? AND tipo_id=? AND ambito_codigo IS NULL LIMIT 1"
            );
            $insCand = $pdo->prepare(
                "INSERT INTO candidatos
                   (eleccion_id, partido_o_coalicion_codigo, candidatura_propietaria, candidatura_suplente, created_at)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   candidatura_propietaria = VALUES(candidatura_propietaria),
                   candidatura_suplente    = VALUES(candidatura_suplente)"
            );
            foreach ($parsed['rows'] as $r) {
                try {
                    if ($candForceEstado) {
                        $selEleccionEstado->execute([$procesoId, $tipoEleccionId]);
                        $eid = $selEleccionEstado->fetchColumn();
                    } else {
                        $selEleccion->execute([$procesoId, $tipoEleccionId, $r['ambito_codigo']]);
                        $eid = $selEleccion->fetchColumn();
                    }
                    if (!$eid) {
                        $stats['rows_skipped']++;
                        if (count($stats['errors']) < 200) {
                            $stats['errors'][] = ['line'=>$r['line'], 'sec'=>'', 'msg'=>"Elección no existe para ambito '{$r['ambito_codigo']}' — carga primero los resultados de esta elección"];
                        }
                        continue;
                    }
                    $insCand->execute([
                        (int)$eid,
                        $r['partido_o_coalicion_codigo'],
                        $r['candidatura_propietaria'],
                        $r['candidatura_suplente'],
                        $now,
                    ]);
                    if ($insCand->rowCount() > 0) $stats['candidatos_creados']++;
                    $stats['rows_ok']++;
                } catch (Throwable $e) {
                    $stats['rows_failed']++;
                    if (count($stats['errors']) < 200) {
                        $stats['errors'][] = ['line'=>$r['line'], 'sec'=>'', 'msg'=>$e->getMessage()];
                    }
                }
            }

            // Cerrar bitácora y salir
            $pdo->prepare(
                "UPDATE import_log_resultados
                    SET rows_ok=?, rows_failed=?, rows_skipped=?,
                        candidatos_creados=?, errores_json=?, finished_at=?
                  WHERE id=?"
            )->execute([
                $stats['rows_ok'], $stats['rows_failed'], $stats['rows_skipped'],
                $stats['candidatos_creados'],
                json_encode($stats['errors'], JSON_UNESCAPED_UNICODE),
                date('Y-m-d H:i:s'),
                $logId
            ]);
            $pdo->commit();
            return $stats;
        }

        // 1) Partidos nuevos — CI_N van con naturaleza 'candidatura_independiente'
        if ($registerNewParties && !empty($validation['partidos_nuevos'])) {
            $ins = $pdo->prepare(
                "INSERT IGNORE INTO partidos (siglas, nombre, naturaleza, vigente, created_at)
                 VALUES (?, ?, ?, 1, ?)"
            );
            foreach ($validation['partidos_nuevos'] as $sig) {
                $naturaleza = preg_match('/^CI_\d+$/', $sig) ? 'independiente' : 'partido';
                $ins->execute([$sig, $sig, $naturaleza, $now]);
                if ($ins->rowCount() > 0) $stats['partidos_nuevos']++;
            }
        }

        // 2) Secciones históricas (fantasma marcadas para registrar)
        if ($registerHistoricas && !empty($validation['secciones_fantasma'])) {
            $ins = $pdo->prepare(
                "INSERT IGNORE INTO secciones_historicas (num_seccion, anio_ultimo, motivo, notas, created_at)
                 VALUES (?, ?, 'desconocido', ?, ?)"
            );
            $anioHist = (int)($opts['anio_historico'] ?? 2021);
            foreach (array_keys($validation['secciones_fantasma']) as $sec) {
                $ins->execute([$sec, $anioHist, "Detectada en import #$logId", $now]);
                if ($ins->rowCount() > 0) $stats['secciones_historicas_creadas']++;
            }
        }

        // 3) Mapas de elecciones, casillas, votos
        // El TIPO DE ELECCIÓN define cuántas elecciones hay: si ámbito=estado
        // (Gubernatura, RP, Senaduría, Presidencia), TODAS las casillas suman
        // a UNA sola elección con ambito_codigo=NULL. Si ámbito=distrito/municipio,
        // se crea una elección por cada ámbito único del dataset.
        $tipoAmbitoStmt = $pdo->prepare("SELECT ambito FROM tipos_eleccion WHERE id = ?");
        $tipoAmbitoStmt->execute([$tipoEleccionId]);
        $tipoAmbito = (string)$tipoAmbitoStmt->fetchColumn(); // 'estado' | 'distrito' | 'municipio' | 'seccion'
        $forceEstado = ($tipoAmbito === 'estado');
        $estadoEleccionId = null;

        $eleccionByAmbito = [];
        $insEleccion = $pdo->prepare(
            "INSERT INTO elecciones (proceso_id, tipo_id, ambito_codigo, ambito_nombre, status, created_at)
             VALUES (?, ?, ?, ?, 'publicada', ?)
             ON DUPLICATE KEY UPDATE ambito_nombre = COALESCE(VALUES(ambito_nombre), ambito_nombre)"
        );
        $selEleccion = $pdo->prepare(
            "SELECT id FROM elecciones WHERE proceso_id = ? AND tipo_id = ? AND
              (ambito_codigo = ? OR (ambito_codigo IS NULL AND ? IS NULL)) LIMIT 1"
        );

        // Para tipo=estado: pre-crear/obtener la única elección antes del bucle.
        // No usamos ON DUPLICATE KEY porque MySQL trata múltiples NULL como distintos
        // en UNIQUE KEY — eso crearía duplicados.
        if ($forceEstado) {
            $selEstado = $pdo->prepare(
                "SELECT id FROM elecciones WHERE proceso_id = ? AND tipo_id = ? AND ambito_codigo IS NULL LIMIT 1"
            );
            $selEstado->execute([$procesoId, $tipoEleccionId]);
            $eid = $selEstado->fetchColumn();
            if (!$eid) {
                $insEst = $pdo->prepare(
                    "INSERT INTO elecciones (proceso_id, tipo_id, ambito_codigo, ambito_nombre, status, created_at)
                     VALUES (?, ?, NULL, 'Estado completo', 'publicada', ?)"
                );
                $insEst->execute([$procesoId, $tipoEleccionId, $now]);
                $eid = (int)$pdo->lastInsertId();
            }
            $estadoEleccionId = (int)$eid;
        }

        $coalCache = []; // [eleccion_id][codigo] = coalicion_id
        // Sin IGNORE: queremos que el try/catch detecte el race; con IGNORE lastInsertId queda en 0 sin señal.
        $insCoal = $pdo->prepare(
            "INSERT INTO coaliciones (eleccion_id, codigo, nombre, created_at) VALUES (?, ?, ?, ?)"
        );
        $selCoal = $pdo->prepare("SELECT id FROM coaliciones WHERE eleccion_id=? AND codigo=? LIMIT 1");
        $insCoalPartido = $pdo->prepare(
            "INSERT IGNORE INTO coaliciones_partidos (coalicion_id, partido_id) VALUES (?, ?)"
        );
        $selPartidoId = $pdo->prepare("SELECT id FROM partidos WHERE siglas = ? LIMIT 1");
        $partidoIds = [];

        // Casillas cache
        $casillaCache = [];
        $selCasilla = $pdo->prepare(
            "SELECT id FROM casillas WHERE num_seccion=? AND tipo_casilla=? AND num_casilla=? AND ext_contigua=? LIMIT 1"
        );
        $insCasilla = $pdo->prepare(
            "INSERT INTO casillas (num_seccion, tipo_casilla, num_casilla, ext_contigua, casilla_codigo, ubicacion, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        $insResultado = $pdo->prepare(
            "INSERT INTO resultados_casilla (eleccion_id, casilla_id, voto_codigo, votos, import_log_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE votos = VALUES(votos), import_log_id = VALUES(import_log_id)"
        );
        $insMeta = $pdo->prepare(
            "INSERT INTO resultados_casilla_meta (eleccion_id, casilla_id, lista_nominal, total_votos, observaciones, import_log_id)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE lista_nominal=VALUES(lista_nominal), total_votos=VALUES(total_votos),
                                     observaciones=VALUES(observaciones), import_log_id=VALUES(import_log_id)"
        );

        foreach ($parsed['rows'] as $r) {
            try {
                // Sección fantasma sin registrar → skip
                if (isset($validation['secciones_fantasma'][$r['seccion']]) && !$registerHistoricas) {
                    $stats['rows_skipped']++;
                    continue;
                }

                if ($forceEstado) {
                    // Tipo de elección estatal: todas las filas van a la única elección
                    $eid = $estadoEleccionId;
                } else {
                    // Elegir el campo correcto del XLSX (que trae ambos distrito y municipio).
                    // Si el tipo de elección es distrito, prefiere el código distrital; si es
                    // municipio, prefiere el municipal. Fallback al ambito_codigo genérico.
                    $tipoAmbitoStr = $tipoAmbito; // 'distrito' | 'municipio'
                    if ($tipoAmbitoStr === 'distrito' && !empty($r['ambito_codigo_distrito'])) {
                        $amb     = (string)$r['ambito_codigo_distrito'];
                        $ambName = $r['ambito_nombre_distrito'] ?? null;
                    } elseif ($tipoAmbitoStr === 'municipio' && !empty($r['ambito_codigo_municipio'])) {
                        $amb     = (string)$r['ambito_codigo_municipio'];
                        $ambName = $r['ambito_nombre_municipio'] ?? null;
                    } else {
                        $amb     = (string)$r['ambito_codigo'];
                        $ambName = $r['ambito_nombre'] ?? null;
                    }
                    if (!isset($eleccionByAmbito[$amb])) {
                        $selEleccion->execute([$procesoId, $tipoEleccionId, $amb, $amb]);
                        $eid = $selEleccion->fetchColumn();
                        if (!$eid) {
                            $insEleccion->execute([$procesoId, $tipoEleccionId, $amb, $ambName, $now]);
                            $selEleccion->execute([$procesoId, $tipoEleccionId, $amb, $amb]);
                            $eid = $selEleccion->fetchColumn();
                        }
                        $eleccionByAmbito[$amb] = (int)$eid;
                    }
                    $eid = $eleccionByAmbito[$amb];
                }

                // El IEEQ a veces incluye múltiples filas con (sección, tipo, num_casilla, ext_contigua)
                // idénticos para representar "Extraordinaria 1", "Extraordinaria 1 Contigua 1",
                // "Extraordinaria 1 Contigua 2", etc. Para no perderlas (mismo UK), incrementamos
                // ext_contigua dentro de la carga si ya vimos la combinación.
                $secKey = "{$r['seccion']}|{$r['tipo_casilla']}|{$r['num_casilla']}";
                $origExt = (int)$r['ext_contigua'];
                $effectiveExt = $origExt;
                if (!isset($seenSecTipoNum)) $seenSecTipoNum = [];
                if (!isset($seenSecTipoNum[$secKey])) {
                    $seenSecTipoNum[$secKey] = $origExt;
                } else {
                    $effectiveExt = $seenSecTipoNum[$secKey] + 1;
                    $seenSecTipoNum[$secKey] = $effectiveExt;
                }

                // Casilla
                $ck = "{$r['seccion']}|{$r['tipo_casilla']}|{$r['num_casilla']}|{$effectiveExt}";
                if (!isset($casillaCache[$ck])) {
                    $selCasilla->execute([$r['seccion'], $r['tipo_casilla'], $r['num_casilla'], $effectiveExt]);
                    $cid = $selCasilla->fetchColumn();
                    if (!$cid) {
                        $casillaCodigo = $r['tipo_casilla'] . sprintf('%02d', $r['num_casilla'])
                                       . ($effectiveExt > 0 ? '_C' . $effectiveExt : '');
                        $insCasilla->execute([
                            $r['seccion'], $r['tipo_casilla'], $r['num_casilla'], $effectiveExt,
                            $casillaCodigo,
                            $r['ubicacion'], $now
                        ]);
                        $cid = (int)$pdo->lastInsertId();
                        $stats['casillas_creadas']++;
                    }
                    $casillaCache[$ck] = (int)$cid;
                }
                $cid = $casillaCache[$ck];

                // Coaliciones: crearlas a nivel elección si no existen, ligadas a partidos por código
                if ($registerNewCoaliciones && !empty($parsed['coaliciones'])) {
                    foreach ($parsed['coaliciones'] as $coalCode => $_idx) {
                        if (isset($coalCache[$eid][$coalCode])) continue;

                        // 1) ¿Ya existe?
                        $selCoal->execute([$eid, $coalCode]);
                        $existingId = $selCoal->fetchColumn();
                        if ($existingId) {
                            $coalCache[$eid][$coalCode] = (int)$existingId;
                            continue;
                        }
                        // 2) Insertar (sin IGNORE → si choca con uk_coal arroja, que es la señal correcta)
                        try {
                            $insCoal->execute([$eid, $coalCode, $coalCode, $now]);
                            $coalId = (int)$pdo->lastInsertId();
                        } catch (PDOException $e) {
                            // Race con otra fila del mismo batch: releer
                            $selCoal->execute([$eid, $coalCode]);
                            $coalId = (int)$selCoal->fetchColumn();
                        }
                        if ($coalId > 0) {
                            $stats['coaliciones_nuevas']++;
                            // Asociar partidos componentes
                            foreach (explode('-', $coalCode) as $sig) {
                                if (!isset($partidoIds[$sig])) {
                                    $selPartidoId->execute([$sig]);
                                    $partidoIds[$sig] = (int)($selPartidoId->fetchColumn() ?: 0);
                                }
                                if ($partidoIds[$sig] > 0) $insCoalPartido->execute([$coalId, $partidoIds[$sig]]);
                            }
                            $coalCache[$eid][$coalCode] = $coalId;
                        }
                    }
                }

                // Insertar votos
                foreach ($r['votos'] as $code => $votos) {
                    $insResultado->execute([$eid, $cid, $code, (int)$votos, $logId, $now]);
                    $stats['resultados_insertados']++;
                }
                // Meta
                $insMeta->execute([$eid, $cid, $r['lista_nominal'], $r['total_votos'], $r['observaciones'], $logId]);

                $stats['rows_ok']++;
            } catch (Throwable $e) {
                $stats['rows_failed']++;
                if (count($stats['errors']) < 200) {
                    $stats['errors'][] = ['line' => $r['line'], 'sec' => $r['seccion'], 'msg' => $e->getMessage()];
                }
            }
        }

        // Cierra bitácora
        $pdo->prepare(
            "UPDATE import_log_resultados
                SET rows_ok=?, rows_failed=?, rows_skipped=?,
                    casillas_creadas=?, resultados_insertados=?,
                    secciones_historicas_creadas=?, partidos_nuevos=?,
                    coaliciones_nuevas=?, candidatos_creados=?,
                    errores_json=?, finished_at=?
              WHERE id=?"
        )->execute([
            $stats['rows_ok'], $stats['rows_failed'], $stats['rows_skipped'],
            $stats['casillas_creadas'], $stats['resultados_insertados'],
            $stats['secciones_historicas_creadas'], $stats['partidos_nuevos'],
            $stats['coaliciones_nuevas'], $stats['candidatos_creados'],
            json_encode($stats['errors'], JSON_UNESCAPED_UNICODE),
            date('Y-m-d H:i:s'),
            $logId
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw new RuntimeException('Falló la transacción: ' . $e->getMessage());
    }
    return $stats;
}

/**
 * Revierte una carga: borra los resultados, meta y candidatos creados
 * por ese import_log_id. Las casillas/coaliciones/partidos NO se borran
 * (porque pueden estar siendo usados por otras cargas).
 */
function ir_revert(int $logId, PDO $pdo): array
{
    $pdo->beginTransaction();
    try {
        $delRes = $pdo->prepare("DELETE FROM resultados_casilla WHERE import_log_id = ?");
        $delRes->execute([$logId]);
        $nRes = $delRes->rowCount();

        $delMeta = $pdo->prepare("DELETE FROM resultados_casilla_meta WHERE import_log_id = ?");
        $delMeta->execute([$logId]);
        $nMeta = $delMeta->rowCount();

        // Marcar el log como revertido (no lo eliminamos para auditoría)
        $pdo->prepare("UPDATE import_log_resultados SET errores_json = CONCAT(COALESCE(errores_json,'[]'), ' /*REVERTIDO ', NOW(), '*/') WHERE id = ?")
            ->execute([$logId]);

        $pdo->commit();
        return ['log_id' => $logId, 'resultados_borrados' => $nRes, 'meta_borrada' => $nMeta];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
