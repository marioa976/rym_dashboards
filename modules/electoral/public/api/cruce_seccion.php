<?php
/**
 * API JSON — drill-down profundo de UNA sección para el tablero de cruce.
 *
 * GET /api/cruce_seccion.php?proceso_id=&tipo_id=&num_seccion=&ambito_codigo=
 *     &tk_estado=&tk_tipo=&tk_desde=&tk_hasta=&dif_programa=&dif_desde=&dif_hasta=
 *
 * Devuelve: métricas electorales (lista nominal, participación, rentabilidad),
 * votos por partido/coalición, desglose de apoyos DIF (por programa) y de
 * tickets de Zendesk (por tipo de servicio y por estado), honrando los filtros.
 */
ini_set('display_errors', '0');
error_reporting(E_ERROR | E_PARSE);
ob_start();

try {
    require_once __DIR__ . '/../../lib/bootstrap.php';
    require_once __DIR__ . '/../../lib/electoral_metrics.php';
    require_once __DIR__ . '/../../lib/ranking_alfredo.php';
    $pdo = reporteador_pdo();

    $procesoId  = (int)($_GET['proceso_id'] ?? 0);
    $tipoId     = (int)($_GET['tipo_id'] ?? 0);
    $secNum     = (int)($_GET['num_seccion'] ?? 0);
    $ambitoCode = trim($_GET['ambito_codigo'] ?? '');
    if (!$procesoId || !$tipoId || !$secNum) throw new RuntimeException('Faltan proceso_id, tipo_id o num_seccion');

    $partido = electoral_partido_objetivo();

    $colExiste = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1");
        $st->execute([$t, $c]); return (bool)$st->fetchColumn();
    };
    $tabExiste = function (string $t) use ($pdo): bool {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1");
        $st->execute([$t]); return (bool)$st->fetchColumn();
    };

    /* -------- Coaliciones del partido objetivo -------- */
    $st = $pdo->prepare(
        "SELECT DISTINCT c.codigo FROM coaliciones c
           JOIN coaliciones_partidos cp ON cp.coalicion_id=c.id
           JOIN partidos p ON p.id=cp.partido_id
           JOIN elecciones e ON e.id=c.eleccion_id
          WHERE p.siglas=? AND e.proceso_id=? AND e.tipo_id=?"
    );
    $st->execute([$partido, $procesoId, $tipoId]);
    $coal = array_column($st->fetchAll(), 'codigo');
    $allPan = array_merge([$partido], $coal);
    $allPanCsv = "'" . implode("','", array_map(fn($c) => addslashes($c), $allPan)) . "'";

    /* -------- Métricas + lista nominal -------- */
    $where = ["e.proceso_id=:proc", "e.tipo_id=:tipo", "cas.num_seccion=:sec"];
    $params = [':proc'=>$procesoId, ':tipo'=>$tipoId, ':sec'=>$secNum];
    if ($ambitoCode !== '') { $where[] = "e.ambito_codigo=:amb"; $params[':amb']=$ambitoCode; }
    $whereSql = implode(' AND ', $where);

    $st = $pdo->prepare(
        "SELECT e.ambito_nombre,
                SUM(CASE WHEN rc.voto_codigo IN ($allPanCsv) THEN rc.votos ELSE 0 END) AS voto_efectivo,
                SUM(CASE WHEN rc.voto_codigo NOT IN ('NULOS','NO_REGISTRADAS') THEN rc.votos ELSE 0 END) AS validos,
                SUM(rc.votos) AS emitidos,
                COUNT(DISTINCT rc.casilla_id) AS n_casillas
           FROM resultados_casilla rc
           JOIN casillas cas ON cas.id=rc.casilla_id
           JOIN elecciones e ON e.id=rc.eleccion_id
          WHERE $whereSql"
    );
    $st->execute($params);
    $m = $st->fetch() ?: [];
    $votoEf = (int)($m['voto_efectivo'] ?? 0);
    $validos = (int)($m['validos'] ?? 0);
    $emitidos = (int)($m['emitidos'] ?? 0);

    $st = $pdo->prepare(
        "SELECT SUM(rcm.lista_nominal) FROM resultados_casilla_meta rcm
           JOIN casillas cas ON cas.id=rcm.casilla_id
           JOIN elecciones e ON e.id=rcm.eleccion_id
          WHERE $whereSql"
    );
    $st->execute($params);
    $listaNominal = (int)($st->fetchColumn() ?: 0);

    /* -------- Votos por partido/coalición -------- */
    $st = $pdo->prepare(
        "SELECT rc.voto_codigo, SUM(rc.votos) AS votos
           FROM resultados_casilla rc
           JOIN casillas cas ON cas.id=rc.casilla_id
           JOIN elecciones e ON e.id=rc.eleccion_id
          WHERE $whereSql
       GROUP BY rc.voto_codigo ORDER BY votos DESC"
    );
    $st->execute($params);
    $crudo = $st->fetchAll();

    // catálogo de partidos (siglas → nombre/color)
    $partMap = [];
    foreach ($pdo->query("SELECT siglas, nombre, color_hex FROM partidos") as $p) {
        $partMap[strtoupper($p['siglas'])] = ['nombre'=>$p['nombre'], 'color'=>$p['color_hex']];
    }
    $noValidos = ['NULOS'=>'Votos nulos', 'NO_REGISTRADAS'=>'No registradas/os'];
    $votos = [];
    foreach ($crudo as $r) {
        $code = (string)$r['voto_codigo'];
        $v = (int)$r['votos'];
        $esNoValido = isset($noValidos[strtoupper($code)]);
        $info = $partMap[strtoupper($code)] ?? null;
        $votos[] = [
            'codigo'   => $code,
            'nombre'   => $esNoValido ? $noValidos[strtoupper($code)] : ($info['nombre'] ?? $code),
            'color'    => $esNoValido ? '#9ca3af' : ($info['color'] ?? '#64748b'),
            'votos'    => $v,
            'pct'      => $validos > 0 && !$esNoValido ? round($v / $validos * 100, 1) : null,
            'es_objetivo' => in_array($code, $allPan, true),
            'no_valido'   => $esNoValido,
        ];
    }

    /* -------- Apoyos DIF (con filtros) -------- */
    $apTotal = 0; $apBenef = 0; $apProgramas = []; $apPorTipo = [];
    if ($colExiste('padron', 'seccion_id')) {
        $w = ["p.seccion_id IS NOT NULL", "s.num_seccion = :sec"]; $pa = [':sec'=>$secNum];
        if (($g = trim($_GET['dif_programa'] ?? '')) !== '' && $colExiste('padron','programa')) { $w[]="p.programa=:prog"; $pa[':prog']=$g; }
        if (($g = trim($_GET['dif_desde'] ?? '')) !== '' && $colExiste('padron','fecha_entrega')) { $w[]="p.fecha_entrega>=:dd"; $pa[':dd']=$g; }
        if (($g = trim($_GET['dif_hasta'] ?? '')) !== '' && $colExiste('padron','fecha_entrega')) { $w[]="p.fecha_entrega<=:dh"; $pa[':dh']=$g; }
        $wsql = implode(' AND ', $w);
        $selBenef = $colExiste('padron','curp') ? "COUNT(DISTINCT p.curp)" : "COUNT(*)";
        $st = $pdo->prepare("SELECT COUNT(*) tot, $selBenef ben FROM padron p JOIN secciones s ON s.id=p.seccion_id WHERE $wsql");
        $st->execute($pa); $row = $st->fetch();
        $apTotal = (int)($row['tot'] ?? 0); $apBenef = (int)($row['ben'] ?? 0);
        if ($colExiste('padron','programa')) {
            $st = $pdo->prepare("SELECT COALESCE(NULLIF(p.programa,''),'(sin programa)') k, COUNT(*) n
                                   FROM padron p JOIN secciones s ON s.id=p.seccion_id WHERE $wsql
                                  GROUP BY k ORDER BY n DESC LIMIT 12");
            $st->execute($pa);
            foreach ($st as $r) $apProgramas[] = ['k'=>$r['k'], 'n'=>(int)$r['n']];
        }
        if ($colExiste('padron','tipo_apoyo')) {
            $st = $pdo->prepare("SELECT COALESCE(NULLIF(p.tipo_apoyo,''),'(sin tipo)') k, COUNT(*) n
                                   FROM padron p JOIN secciones s ON s.id=p.seccion_id WHERE $wsql
                                  GROUP BY k ORDER BY n DESC LIMIT 12");
            $st->execute($pa);
            foreach ($st as $r) $apPorTipo[] = ['k'=>$r['k'], 'n'=>(int)$r['n']];
        }
    }

    /* -------- Tickets Zendesk (con filtros + estado) -------- */
    $tkTotal=0; $tkAb=0; $tkRe=0; $tkPorTipo=[]; $tkPorEstado=[];
    if ($colExiste('tickets', 'seccion_id')) {
        $hayCat = $tabExiste('cat_estado');
        $joinE = $hayCat ? "LEFT JOIN cat_estado e ON e.id=t.estado_id" : "";
        $colAb = $hayCat ? "COALESCE(e.es_cerrado,0)=0" : "t.fecha_resolucion IS NULL";
        $colRe = $hayCat ? "COALESCE(e.es_resuelto,0)=1" : "t.fecha_resolucion IS NOT NULL";
        $w = ["t.seccion_id IS NOT NULL", "s.num_seccion=:sec"]; $pa=[':sec'=>$secNum];
        $est = trim($_GET['tk_estado'] ?? '');
        if ($est==='abiertos')        $w[]=$colAb;
        elseif ($est==='resueltos')   $w[]=$colRe;
        elseif ($est==='finalizados') $w[]= $hayCat ? "COALESCE(e.es_cerrado,0)=1" : "t.fecha_resolucion IS NOT NULL";
        if (($g=(int)($_GET['tk_tipo'] ?? 0))>0) { $w[]="t.tipo_servicio_id=:tt"; $pa[':tt']=$g; }
        if (($g=trim($_GET['tk_desde'] ?? ''))!=='') { $w[]="t.fecha_creacion>=:td"; $pa[':td']=$g; }
        if (($g=trim($_GET['tk_hasta'] ?? ''))!=='') { $w[]="t.fecha_creacion<=:th"; $pa[':th']=$g; }
        $wsql = implode(' AND ', $w);
        $st = $pdo->prepare("SELECT COUNT(*) tot,
                                    SUM(CASE WHEN $colAb THEN 1 ELSE 0 END) ab,
                                    SUM(CASE WHEN $colRe THEN 1 ELSE 0 END) re
                               FROM tickets t JOIN secciones s ON s.id=t.seccion_id $joinE WHERE $wsql");
        $st->execute($pa); $row=$st->fetch();
        $tkTotal=(int)($row['tot']??0); $tkAb=(int)($row['ab']??0); $tkRe=(int)($row['re']??0);
        if ($tabExiste('cat_tipo_servicio')) {
            $st=$pdo->prepare("SELECT COALESCE(cs.nombre,'(sin tipo)') k, COUNT(*) n
                                 FROM tickets t JOIN secciones s ON s.id=t.seccion_id
                                 LEFT JOIN cat_tipo_servicio cs ON cs.id=t.tipo_servicio_id $joinE
                                WHERE $wsql GROUP BY k ORDER BY n DESC LIMIT 12");
            $st->execute($pa); foreach ($st as $r) $tkPorTipo[]=['k'=>$r['k'],'n'=>(int)$r['n']];
        }
        if ($hayCat) {
            $st=$pdo->prepare("SELECT COALESCE(e.nombre,'(sin estado)') k, COUNT(*) n
                                 FROM tickets t JOIN secciones s ON s.id=t.seccion_id $joinE
                                WHERE $wsql GROUP BY k ORDER BY n DESC LIMIT 12");
            $st->execute($pa); foreach ($st as $r) $tkPorEstado[]=['k'=>$r['k'],'n'=>(int)$r['n']];
        }
    }

    /* -------- Catálogo (municipio / distrito) -------- */
    $st = $pdo->prepare(
        "SELECT s.municipio, d.numero AS distrito_num, d.nombre AS distrito_nombre
           FROM secciones s LEFT JOIN distritos d ON d.id=s.distrito_id
          WHERE s.num_seccion=? LIMIT 1"
    );
    $st->execute([$secNum]);
    $cat = $st->fetch() ?: [];

    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'num_seccion'   => $secNum,
        'partido'       => $partido,
        'ambito_nombre' => $m['ambito_nombre'] ?? null,
        'municipio'     => $cat['municipio'] ?? null,
        'distrito_num'  => isset($cat['distrito_num']) && $cat['distrito_num'] !== null ? (int)$cat['distrito_num'] : null,
        'distrito_nombre'=> $cat['distrito_nombre'] ?? null,
        'lista_nominal' => $listaNominal,
        'emitidos'      => $emitidos,
        'validos'       => $validos,
        'voto_efectivo' => $votoEf,
        'n_casillas'    => (int)($m['n_casillas'] ?? 0),
        'rentabilidad'  => $validos > 0 ? round($votoEf / $validos * 100, 2) : null,
        'participacion' => $listaNominal > 0 ? round($emitidos / $listaNominal * 100, 2) : null,
        'votos'         => $votos,
        'apoyos'        => ['total'=>$apTotal, 'beneficiarios'=>$apBenef, 'por_programa'=>$apProgramas, 'por_tipo'=>$apPorTipo],
        'tickets'       => ['total'=>$tkTotal, 'abiertos'=>$tkAb, 'resueltos'=>$tkRe, 'por_tipo'=>$tkPorTipo, 'por_estado'=>$tkPorEstado],
        'ranking'       => ranking_alfredo_seccion($secNum),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $e->getMessage()]);
}
