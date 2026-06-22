<?php
/**
 * Catálogo de archivos electorales esperados.
 *
 * Cada entrada describe un archivo conocido con sus parámetros pre-configurados.
 * El importer "guiado" usa este catálogo para que el usuario solo escoja el
 * archivo y no pueda equivocarse al elegir proceso/tipo/tipo_archivo.
 *
 * Campos por archivo:
 *   - id          slug único interno
 *   - filename    nombre esperado del archivo (informativo, no se valida)
 *   - label       título para la UI
 *   - proceso     anio + nivel para resolver proceso_id
 *   - tipo_codigo código del tipo_eleccion (gubernatura, diputacion_mr_loc, ...)
 *   - archivo_tipo (csv_2024_resultados | csv_2024_candidaturas | xlsx_2021_casilla)
 *   - depends_on  id de otro archivo que debe cargarse primero (candidaturas dependen de resultados)
 *   - notas       texto descriptivo para la UI
 *   - orden       número para ordenar visualmente
 *
 * Como las elecciones pasadas no van a cambiar, este catálogo se hardcodea.
 */

function ir_catalog(): array
{
    return [
        // ============== 2021 — Proceso Local QRO 2020-2021 ==============
        '2021_ayuntamiento' => [
            'id' => '2021_ayuntamiento',
            'filename' => '2021_Ayuntamiento.xlsx',
            'label' => 'Ayuntamiento 2021',
            'proceso' => ['anio'=>2021, 'nivel'=>'estatal'],
            'tipo_codigo' => 'ayuntamiento',
            'archivo_tipo' => 'xlsx_2021_casilla',
            'depends_on' => null,
            'notas' => '18 municipios · hoja "Casilla"',
            'orden' => 1,
        ],
        '2021_gubernatura' => [
            'id' => '2021_gubernatura',
            'filename' => '2021_Gubernatura.xlsx',
            'label' => 'Gubernatura 2021',
            'proceso' => ['anio'=>2021, 'nivel'=>'estatal'],
            'tipo_codigo' => 'gubernatura',
            'archivo_tipo' => 'xlsx_2021_casilla',
            'depends_on' => null,
            'notas' => '1 elección estatal · hoja "Casilla"',
            'orden' => 2,
        ],
        '2021_dip_mr' => [
            'id' => '2021_dip_mr',
            'filename' => '2021_DIPUTACIÓNMR.xlsx',
            'label' => 'Diputaciones locales MR 2021',
            'proceso' => ['anio'=>2021, 'nivel'=>'estatal'],
            'tipo_codigo' => 'diputacion_mr_loc',
            'archivo_tipo' => 'xlsx_2021_casilla',
            'depends_on' => null,
            'notas' => '15 distritos locales · hoja "Casilla"',
            'orden' => 3,
        ],
        '2021_dip_rp' => [
            'id' => '2021_dip_rp',
            'filename' => '2021_DIPUTACIÓNRP.xlsx',
            'label' => 'Diputaciones locales RP 2021',
            'proceso' => ['anio'=>2021, 'nivel'=>'estatal'],
            'tipo_codigo' => 'diputacion_rp_loc',
            'archivo_tipo' => 'xlsx_2021_casilla',
            'depends_on' => null,
            'notas' => '1 elección estatal · hoja "CASILLAESPECIAL" (solo SMR)',
            'orden' => 4,
        ],

        // ============== 2024 — Proceso Local QRO 2023-2024 ==============
        '2024_ayun_resultados' => [
            'id' => '2024_ayun_resultados',
            'filename' => 'QRO_AYUN_RESULTADOS_2024.csv',
            'label' => 'Ayuntamiento 2024 — Resultados',
            'proceso' => ['anio'=>2024, 'nivel'=>'estatal'],
            'tipo_codigo' => 'ayuntamiento',
            'archivo_tipo' => 'csv_2024_resultados',
            'depends_on' => null,
            'notas' => '18 municipios',
            'orden' => 5,
        ],
        '2024_ayun_candidaturas' => [
            'id' => '2024_ayun_candidaturas',
            'filename' => 'QRO_AYUN_CANDIDATURAS_2024.csv',
            'label' => 'Ayuntamiento 2024 — Candidaturas',
            'proceso' => ['anio'=>2024, 'nivel'=>'estatal'],
            'tipo_codigo' => 'ayuntamiento',
            'archivo_tipo' => 'csv_2024_candidaturas',
            'depends_on' => '2024_ayun_resultados',
            'notas' => 'Catálogo de candidatos por municipio',
            'orden' => 6,
        ],
        '2024_dip_resultados' => [
            'id' => '2024_dip_resultados',
            'filename' => 'QRO_DIP_LOC_RESULTADOS_2024.csv',
            'label' => 'Diputaciones locales MR 2024 — Resultados',
            'proceso' => ['anio'=>2024, 'nivel'=>'estatal'],
            'tipo_codigo' => 'diputacion_mr_loc',
            'archivo_tipo' => 'csv_2024_resultados',
            'depends_on' => null,
            'notas' => '15 distritos locales',
            'orden' => 7,
        ],
        '2024_dip_candidaturas' => [
            'id' => '2024_dip_candidaturas',
            'filename' => 'QRO_DIP_LOC_CANDIDATURAS_2024.csv',
            'label' => 'Diputaciones locales MR 2024 — Candidaturas',
            'proceso' => ['anio'=>2024, 'nivel'=>'estatal'],
            'tipo_codigo' => 'diputacion_mr_loc',
            'archivo_tipo' => 'csv_2024_candidaturas',
            'depends_on' => '2024_dip_resultados',
            'notas' => 'Catálogo de candidatos por distrito',
            'orden' => 8,
        ],
    ];
}

/**
 * Resuelve un slug del catálogo a sus IDs reales (proceso_id, tipo_id).
 */
function ir_catalog_resolve(array $entry, PDO $pdo): array
{
    $p = $entry['proceso'];
    $procStmt = $pdo->prepare("SELECT id FROM procesos_electorales WHERE anio=? AND nivel=? LIMIT 1");
    $procStmt->execute([$p['anio'], $p['nivel']]);
    $procesoId = (int)$procStmt->fetchColumn();

    $tipoStmt = $pdo->prepare("SELECT id FROM tipos_eleccion WHERE codigo=? LIMIT 1");
    $tipoStmt->execute([$entry['tipo_codigo']]);
    $tipoId = (int)$tipoStmt->fetchColumn();

    return [
        'proceso_id' => $procesoId,
        'tipo_id' => $tipoId,
        'archivo_tipo' => $entry['archivo_tipo'],
        'filename' => $entry['filename'],
    ];
}

/**
 * Para cada entrada del catálogo, busca el estado actual: cargado/pendiente/falló.
 * Devuelve [catalog_id => ['status'=>..., 'log_id'=>..., 'rows_ok'=>..., 'rows_failed'=>...]].
 */
function ir_catalog_status(PDO $pdo): array
{
    $catalog = ir_catalog();
    $status = [];
    $logStmt = $pdo->prepare(
        "SELECT l.id, l.rows_ok, l.rows_failed, l.rows_skipped,
                l.casillas_creadas, l.resultados_insertados, l.candidatos_creados,
                l.errores_json, l.finished_at
           FROM import_log_resultados l
          WHERE l.archivo = ? AND l.errores_json NOT LIKE '%REVERTIDO%'
          ORDER BY l.id DESC LIMIT 1"
    );
    foreach ($catalog as $id => $entry) {
        $logStmt->execute([$entry['filename']]);
        $log = $logStmt->fetch();
        if (!$log) {
            $status[$id] = ['status' => 'pendiente', 'log' => null];
        } elseif ($log['rows_failed'] > 0) {
            $status[$id] = ['status' => 'con_errores', 'log' => $log];
        } elseif ($log['rows_ok'] > 0) {
            $status[$id] = ['status' => 'ok', 'log' => $log];
        } else {
            $status[$id] = ['status' => 'vacio', 'log' => $log];
        }
    }
    return $status;
}
