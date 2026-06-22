<?php
/**
 * API JSON — detalle completo de UNA sección para una elección dada.
 *
 * GET /api/seccion_detalle.php?proceso_id=2&tipo_id=2&num_seccion=416
 *
 * Devuelve métricas, desglose por voto_codigo, comparativo histórico,
 * cruce con estructura ciudadana y catálogo IEEQ (municipio, distrito,
 * colonias) — útil para secciones SIN polígono que no aparecen en el mapa.
 */

ini_set('display_errors', '0');
error_reporting(E_ERROR | E_PARSE);
ob_start();

try {
    require_once __DIR__ . '/../../lib/bootstrap.php';

    $pdo = reporteador_pdo();
    $procesoId  = (int)($_GET['proceso_id'] ?? 0);
    $tipoId     = (int)($_GET['tipo_id'] ?? 0);
    $secNum     = (int)($_GET['num_seccion'] ?? 0);
    $ambitoCode = trim($_GET['ambito_codigo'] ?? '');
    if (!$procesoId || !$tipoId || !$secNum) {
        throw new RuntimeException('Faltan proceso_id, tipo_id o num_seccion');
    }

    // Coaliciones con PAN
    $st = $pdo->prepare(
        "SELECT DISTINCT c.codigo FROM coaliciones c
           JOIN coaliciones_partidos cp ON cp.coalicion_id=c.id
           JOIN partidos p ON p.id=cp.partido_id
           JOIN elecciones e ON e.id=c.eleccion_id
          WHERE p.siglas='PAN' AND e.proceso_id=? AND e.tipo_id=?"
    );
    $st->execute([$procesoId, $tipoId]);
    $coal = array_column($st->fetchAll(), 'codigo');
    $allPan = array_merge(['PAN'], $coal);
    $allPanCsv = "'" . implode("','", array_map(fn($c)=>addslashes($c), $allPan)) . "'";

    // Métricas agregadas
    $where = ["e.proceso_id=:proc", "e.tipo_id=:tipo", "cas.num_seccion=:sec"];
    $params = [':proc'=>$procesoId, ':tipo'=>$tipoId, ':sec'=>$secNum];
    if ($ambitoCode !== '') { $where[] = "e.ambito_codigo=:amb"; $params[':amb']=$ambitoCode; }
    $whereSql = implode(' AND ', $where);

    $st = $pdo->prepare(
        "SELECT e.id AS eleccion_id, e.ambito_codigo, e.ambito_nombre,
                SUM(CASE WHEN rc.voto_codigo IN ($allPanCsv) THEN rc.votos ELSE 0 END) AS voto_efectivo,
                SUM(CASE WHEN rc.voto_codigo NOT IN ('NULOS','NO_REGISTRADAS') THEN rc.votos ELSE 0 END) AS validos,
                SUM(rc.votos) AS emitidos,
                COUNT(DISTINCT rc.casilla_id) AS n_casillas
           FROM resultados_casilla rc
           JOIN casillas cas ON cas.id=rc.casilla_id
           JOIN elecciones e ON e.id=rc.eleccion_id
          WHERE $whereSql
       GROUP BY e.id, e.ambito_codigo, e.ambito_nombre"
    );
    $st->execute($params);
    $metricas = $st->fetchAll();

    // Desglose por voto_codigo
    $st = $pdo->prepare(
        "SELECT rc.voto_codigo, SUM(rc.votos) AS votos
           FROM resultados_casilla rc
           JOIN casillas cas ON cas.id=rc.casilla_id
           JOIN elecciones e ON e.id=rc.eleccion_id
          WHERE $whereSql
       GROUP BY rc.voto_codigo
       ORDER BY votos DESC"
    );
    $st->execute($params);
    $desglose = [];
    foreach ($st as $r) $desglose[(string)$r['voto_codigo']] = (int)$r['votos'];

    // Lista nominal
    $st = $pdo->prepare(
        "SELECT SUM(rcm.lista_nominal) AS lista_nominal
           FROM resultados_casilla_meta rcm
           JOIN casillas cas ON cas.id=rcm.casilla_id
           JOIN elecciones e ON e.id=rcm.eleccion_id
          WHERE $whereSql"
    );
    $st->execute($params);
    $listaNominal = (int)($st->fetchColumn() ?: 0);

    // Comparativo elección anterior del mismo tipo
    $prev = null;
    $st = $pdo->prepare(
        "SELECT pe.id, pe.anio FROM procesos_electorales pe
          WHERE pe.anio < (SELECT anio FROM procesos_electorales WHERE id=?)
            AND pe.nivel = (SELECT nivel FROM procesos_electorales WHERE id=?)
       ORDER BY pe.anio DESC LIMIT 1"
    );
    $st->execute([$procesoId, $procesoId]);
    $prevProc = $st->fetch();
    if ($prevProc) {
        // Coaliciones PAN del proceso anterior
        $st = $pdo->prepare(
            "SELECT DISTINCT c.codigo FROM coaliciones c
               JOIN coaliciones_partidos cp ON cp.coalicion_id=c.id
               JOIN partidos p ON p.id=cp.partido_id
               JOIN elecciones e ON e.id=c.eleccion_id
              WHERE p.siglas='PAN' AND e.proceso_id=? AND e.tipo_id=?"
        );
        $st->execute([(int)$prevProc['id'], $tipoId]);
        $coalPrev = array_column($st->fetchAll(), 'codigo');
        $allPanPrev = array_merge(['PAN'], $coalPrev);
        $allPanPrevCsv = "'" . implode("','", array_map(fn($c)=>addslashes($c), $allPanPrev)) . "'";

        $st = $pdo->prepare(
            "SELECT SUM(CASE WHEN rc.voto_codigo IN ($allPanPrevCsv) THEN rc.votos ELSE 0 END) AS voto_efectivo,
                    SUM(CASE WHEN rc.voto_codigo NOT IN ('NULOS','NO_REGISTRADAS') THEN rc.votos ELSE 0 END) AS validos
               FROM resultados_casilla rc
               JOIN casillas cas ON cas.id=rc.casilla_id
               JOIN elecciones e ON e.id=rc.eleccion_id
              WHERE cas.num_seccion=? AND e.proceso_id=? AND e.tipo_id=?"
        );
        $st->execute([$secNum, (int)$prevProc['id'], $tipoId]);
        $row = $st->fetch();
        if ($row && $row['validos'] > 0) {
            $prev = [
                'anio'         => (int)$prevProc['anio'],
                'voto_efectivo'=> (int)$row['voto_efectivo'],
                'validos'      => (int)$row['validos'],
                'rentabilidad' => round($row['voto_efectivo']/$row['validos']*100, 2),
            ];
        }
    }

    // Catálogo IEEQ (puede haber duplicados por municipio/distrito)
    $st = $pdo->prepare(
        "SELECT s.id, s.num_seccion, s.municipio, s.col_localidad,
                d.numero AS distrito_num, d.nombre AS distrito_nombre,
                EXISTS (SELECT 1 FROM secciones_geo g WHERE g.seccion_id = s.id) AS tiene_poligono
           FROM secciones s
      LEFT JOIN distritos d ON d.id = s.distrito_id
          WHERE s.num_seccion = ?"
    );
    $st->execute([$secNum]);
    $catalogo = $st->fetchAll();

    $estructura = 0; // sin cruce con red ciudadana

    $meta = null; // sin metas en este módulo

    // Parsear colonias (toma del primer registro del catálogo)
    $colonias = [];
    if (!empty($catalogo) && !empty($catalogo[0]['col_localidad'])) {
        foreach (preg_split('/[,;]/u', (string)$catalogo[0]['col_localidad']) as $p) {
            $p = trim($p);
            if ($p !== '') $colonias[] = $p;
        }
    }

    // Construir respuesta
    $total = 0;
    foreach ($metricas as $m) $total += (int)$m['voto_efectivo'];
    $totalValidos = 0; $totalEmitidos = 0; $nCasillas = 0;
    foreach ($metricas as $m) {
        $totalValidos += (int)$m['validos'];
        $totalEmitidos += (int)$m['emitidos'];
        $nCasillas += (int)$m['n_casillas'];
    }

    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'num_seccion'    => $secNum,
        'voto_efectivo'  => $total,
        'votos_validos'  => $totalValidos,
        'votos_emitidos' => $totalEmitidos,
        'lista_nominal'  => $listaNominal,
        'n_casillas'     => $nCasillas,
        'rentabilidad'   => $totalValidos > 0 ? round($total/$totalValidos*100, 2) : null,
        'participacion'  => $listaNominal > 0 ? round($totalEmitidos/$listaNominal*100, 2) : null,
        'pan_coalitions' => $coal,
        'desglose'       => $desglose,
        'prev'           => $prev,
        'delta_rent'     => $prev !== null && $totalValidos > 0
                              ? round(($total/$totalValidos*100) - $prev['rentabilidad'], 2)
                              : null,
        'catalogo'       => array_map(fn($c) => [
            'municipio'        => $c['municipio'],
            'distrito_num'     => $c['distrito_num'] !== null ? (int)$c['distrito_num'] : null,
            'distrito_nombre'  => $c['distrito_nombre'],
            'tiene_poligono'   => (bool)$c['tiene_poligono'],
        ], $catalogo),
        'colonias'       => $colonias,
        'estructura'     => $estructura,
        'meta'           => $meta,
        'elecciones'     => array_map(fn($m) => [
            'eleccion_id'   => (int)$m['eleccion_id'],
            'ambito_codigo' => $m['ambito_codigo'],
            'ambito_nombre' => $m['ambito_nombre'],
            'voto_efectivo' => (int)$m['voto_efectivo'],
            'validos'       => (int)$m['validos'],
            'emitidos'      => (int)$m['emitidos'],
            'n_casillas'    => (int)$m['n_casillas'],
        ], $metricas),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $e->getMessage()]);
}
