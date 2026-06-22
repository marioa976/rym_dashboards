<?php
/**
 * Tablero de cruce territorial: electoral × padrón DIF × atención ciudadana (Zendesk).
 *
 * Cruza por sección la métrica electoral (rentabilidad del partido objetivo,
 * participación) con apoyos del DIF y reportes de Zendesk, con filtros analíticos:
 *   - Zendesk: estado (abiertos / resueltos / finalizados), tipo de servicio, rango de fechas.
 *   - DIF:     programa, rango de fecha de entrega.
 *
 * Puente: secciones.id ← padron.seccion_id / tickets.seccion_id → secciones.num_seccion.
 * Geografía: ST_AsGeoJSON(secciones_geo.geom) renderizada con Google Maps (data layer).
 *
 * Solo lectura: visible para cualquier nivel con acceso al módulo (lector incluido).
 */
$REQUIRE_ROLES = ['administrador', 'gerente', 'cliente', 'consulta', 'lector'];
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/electoral_metrics.php';

$pdo = reporteador_pdo();
$U   = auth_user();
$cfg = reporteador_config();
$gmapsKey = $cfg['google_maps']['api_key'] ?? '';

/* ------------------------------ Helpers ------------------------------ */
function col_existe(PDO $pdo, string $tabla, string $col): bool {
    static $cache = [];
    $k = "$tabla.$col";
    if (isset($cache[$k])) return $cache[$k];
    $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1");
    $st->execute([$tabla, $col]);
    return $cache[$k] = (bool)$st->fetchColumn();
}
function tabla_existe(PDO $pdo, string $tabla): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1");
    $st->execute([$tabla]);
    return (bool)$st->fetchColumn();
}
function mediana(array $vals): float {
    $vals = array_values(array_filter($vals, fn($v) => $v !== null));
    sort($vals); $n = count($vals);
    if ($n === 0) return 0.0;
    $m = intdiv($n, 2);
    return $n % 2 ? (float)$vals[$m] : (float)(($vals[$m-1] + $vals[$m]) / 2);
}

/* ------------------------------ Filtros ------------------------------ */
$procesoId  = (int)($_GET['proceso_id'] ?? 0);
$tipoId     = (int)($_GET['tipo_id'] ?? 0);
$ambitoCode = trim($_GET['ambito_codigo'] ?? '');
$secSearch  = trim($_GET['sec_search'] ?? '');
$soloConDatos = isset($_GET['solo_datos']) && $_GET['solo_datos'] !== '' && $_GET['solo_datos'] !== '0';
$orden      = $_GET['orden'] ?? 'rentabilidad';
$dir        = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$export     = ($_GET['export'] ?? '') === 'csv';

// Zendesk
$tkEstado = trim($_GET['tk_estado'] ?? '');     // ''|abiertos|resueltos|finalizados
$tkTipo   = (int)($_GET['tk_tipo'] ?? 0);
$tkDesde  = trim($_GET['tk_desde'] ?? '');
$tkHasta  = trim($_GET['tk_hasta'] ?? '');
// DIF
$difPrograma = trim($_GET['dif_programa'] ?? '');
$difDesde    = trim($_GET['dif_desde'] ?? '');
$difHasta    = trim($_GET['dif_hasta'] ?? '');
$advActivo = ($tkEstado||$tkTipo||$tkDesde||$tkHasta||$difPrograma!==''||$difDesde||$difHasta);

if (!$procesoId) $procesoId = (int)$pdo->query("SELECT id FROM procesos_electorales WHERE anio=2024 AND nivel='estatal' LIMIT 1")->fetchColumn();
if (!$tipoId)    $tipoId    = (int)$pdo->query("SELECT id FROM tipos_eleccion WHERE codigo='diputacion_mr_loc' LIMIT 1")->fetchColumn();

$procesos = $pdo->query("SELECT id, anio, nivel, descripcion FROM procesos_electorales ORDER BY anio DESC, nivel")->fetchAll();
$tipos    = $pdo->query("SELECT id, codigo, nombre, ambito FROM tipos_eleccion ORDER BY nivel, ambito")->fetchAll();

$ambStmt = $pdo->prepare("SELECT ambito_codigo, ambito_nombre FROM elecciones WHERE proceso_id=? AND tipo_id=? ORDER BY CAST(ambito_codigo AS UNSIGNED), ambito_nombre");
$ambStmt->execute([$procesoId, $tipoId]);
$ambitos = $ambStmt->fetchAll();

// Catálogos para filtros
$tiposServicio = [];
if (tabla_existe($pdo, 'cat_tipo_servicio') && tabla_existe($pdo, 'tickets')) {
    $tiposServicio = $pdo->query("SELECT c.id, c.nombre FROM cat_tipo_servicio c
                                   WHERE EXISTS (SELECT 1 FROM tickets t WHERE t.tipo_servicio_id=c.id)
                                   ORDER BY c.nombre")->fetchAll();
}
$programas = [];
if (col_existe($pdo, 'padron', 'programa')) {
    $programas = $pdo->query("SELECT DISTINCT programa FROM padron WHERE programa IS NOT NULL AND programa<>'' ORDER BY programa LIMIT 300")->fetchAll(PDO::FETCH_COLUMN);
}

/* --------------------------- Métrica electoral --------------------------- */
$elec     = metricas_por_seccion($pdo, $procesoId, $tipoId, $ambitoCode ?: null);
$rowsElec = $elec['rows'];
$partido  = electoral_partido_objetivo();

/* --------------------------- Cruce padrón DIF (con filtros) --------------------------- */
$apoyos = [];
$padronOk = col_existe($pdo, 'padron', 'seccion_id');
if ($padronOk) {
    $tieneCurp = col_existe($pdo, 'padron', 'curp');
    $selBenef  = $tieneCurp ? "COUNT(DISTINCT p.curp)" : "COUNT(*)";
    $w = ["p.seccion_id IS NOT NULL"]; $pa = [];
    if ($difPrograma !== '' && col_existe($pdo,'padron','programa')) { $w[] = "p.programa = ?"; $pa[] = $difPrograma; }
    if ($difDesde !== '' && col_existe($pdo,'padron','fecha_entrega')) { $w[] = "p.fecha_entrega >= ?"; $pa[] = $difDesde; }
    if ($difHasta !== '' && col_existe($pdo,'padron','fecha_entrega')) { $w[] = "p.fecha_entrega <= ?"; $pa[] = $difHasta; }
    $sql = "SELECT s.num_seccion, COUNT(*) AS apoyos, $selBenef AS beneficiarios
              FROM padron p JOIN secciones s ON s.id = p.seccion_id
             WHERE " . implode(' AND ', $w) . " GROUP BY s.num_seccion";
    $st = $pdo->prepare($sql); $st->execute($pa);
    foreach ($st as $r) $apoyos[(int)$r['num_seccion']] = ['apoyos'=>(int)$r['apoyos'], 'beneficiarios'=>(int)$r['beneficiarios']];
}

/* --------------------------- Cruce Zendesk (con filtros + estado) --------------------------- */
$tickets = [];
$ticketsOk = col_existe($pdo, 'tickets', 'seccion_id');
$hayCatEstado = tabla_existe($pdo, 'cat_estado');
if ($ticketsOk) {
    $joinE = $hayCatEstado ? "LEFT JOIN cat_estado e ON e.id = t.estado_id" : "";
    $colAb = $hayCatEstado ? "COALESCE(e.es_cerrado,0)=0" : "t.fecha_resolucion IS NULL";
    $colRe = $hayCatEstado ? "COALESCE(e.es_resuelto,0)=1" : "t.fecha_resolucion IS NOT NULL";
    $w = ["t.seccion_id IS NOT NULL"]; $pa = [];
    if ($tkEstado === 'abiertos')        $w[] = $colAb;
    elseif ($tkEstado === 'resueltos')   $w[] = $colRe;
    elseif ($tkEstado === 'finalizados') $w[] = $hayCatEstado ? "COALESCE(e.es_cerrado,0)=1" : "t.fecha_resolucion IS NOT NULL";
    if ($tkTipo > 0)     { $w[] = "t.tipo_servicio_id = ?"; $pa[] = $tkTipo; }
    if ($tkDesde !== '') { $w[] = "t.fecha_creacion >= ?"; $pa[] = $tkDesde; }
    if ($tkHasta !== '') { $w[] = "t.fecha_creacion <= ?"; $pa[] = $tkHasta; }
    $sql = "SELECT s.num_seccion, COUNT(*) AS tickets,
                   SUM(CASE WHEN $colAb THEN 1 ELSE 0 END) AS abiertos,
                   SUM(CASE WHEN $colRe THEN 1 ELSE 0 END) AS resueltos
              FROM tickets t JOIN secciones s ON s.id = t.seccion_id
              $joinE
             WHERE " . implode(' AND ', $w) . " GROUP BY s.num_seccion";
    $st = $pdo->prepare($sql); $st->execute($pa);
    foreach ($st as $r) $tickets[(int)$r['num_seccion']] = ['tickets'=>(int)$r['tickets'], 'abiertos'=>(int)$r['abiertos'], 'resueltos'=>(int)$r['resueltos']];
}

/* --------------------------- Merge + índices --------------------------- */
$rows = [];
foreach ($rowsElec as $sec => $r) {
    if ($secSearch !== '' && is_numeric($secSearch) && (int)$secSearch !== (int)$sec) continue;
    $ap = $apoyos[$sec] ?? ['apoyos'=>0, 'beneficiarios'=>0];
    $tk = $tickets[$sec] ?? ['tickets'=>0, 'abiertos'=>0, 'resueltos'=>0];
    if ($soloConDatos && $ap['apoyos'] === 0 && $tk['tickets'] === 0) continue;
    $ln = (int)$r['lista_nominal'];
    $rows[$sec] = [
        'num_seccion'   => (int)$sec,
        'ambito_nombre' => $r['ambito_nombre'] ?: $r['ambito_codigo'],
        'n_casillas'    => (int)$r['n_casillas'],
        'voto_efectivo' => (int)$r['voto_efectivo'],
        'validos'       => (int)$r['validos'],
        'lista_nominal' => $ln,
        'rentabilidad'  => round((float)$r['rentabilidad'], 2),
        'participacion' => round((float)$r['participacion'], 2),
        'apoyos'        => $ap['apoyos'],
        'beneficiarios' => $ap['beneficiarios'],
        'tickets'       => $tk['tickets'],
        'abiertos'      => $tk['abiertos'],
        'resueltos'     => $tk['resueltos'],
        'apoyos_x1000'  => $ln > 0 ? round($ap['apoyos'] / $ln * 1000, 1) : 0,
        'tickets_x1000' => $ln > 0 ? round($tk['tickets'] / $ln * 1000, 1) : 0,
    ];
}

/* ------------------------- Cuadrantes estratégicos ------------------------- */
$rentMed   = mediana(array_column($rows, 'rentabilidad'));
$apoyosMed = mediana(array_column($rows, 'apoyos'));
$quadDefs = [
    'prioridad'   => ['t'=>'Prioridad de inversión', 'd'=>'Afín (rentabilidad ≥ mediana) pero con pocos apoyos. Capital electoral sin atender.', 'c'=>'#16a34a'],
    'consolidada' => ['t'=>'Consolidada',            'd'=>'Afín y bien atendida. Mantener.', 'c'=>'#0ea5e9'],
    'fidelizar'   => ['t'=>'Revisar ROI',            'd'=>'Poco afín pero con muchos apoyos. Evaluar retorno.', 'c'=>'#d99000'],
    'explorar'    => ['t'=>'Explorar',               'd'=>'Poco afín y poco atendida. Bajo costo de oportunidad.', 'c'=>'#94a3b8'],
];
foreach ($rows as $sec => &$r) {
    $afin = $r['rentabilidad'] >= $rentMed;
    $aten = $r['apoyos'] >= $apoyosMed;
    $r['quad'] = $afin ? ($aten ? 'consolidada' : 'prioridad') : ($aten ? 'fidelizar' : 'explorar');
}
unset($r);

/* ------------------------------ Orden ------------------------------ */
$ordCols = ['num_seccion','rentabilidad','participacion','apoyos','beneficiarios','tickets','abiertos'];
if (!in_array($orden, $ordCols, true)) $orden = 'rentabilidad';
uasort($rows, function ($a, $b) use ($orden, $dir) {
    $cmp = $a[$orden] <=> $b[$orden];
    return $dir === 'asc' ? $cmp : -$cmp;
});

/* ------------------------------ Agregados / KPIs ------------------------------ */
$nSecs = count($rows);
$totApoyos=$totBenef=$totTickets=$totAbiertos=$totResueltos=$totEf=$totVal=0;
$conApoyos=$conTickets=0; $afinesSinApoyo=0; $demandaSinCobertura=0;
$quadCount = ['prioridad'=>0,'consolidada'=>0,'fidelizar'=>0,'explorar'=>0];
foreach ($rows as $r) {
    $totApoyos+=$r['apoyos']; $totBenef+=$r['beneficiarios']; $totTickets+=$r['tickets'];
    $totAbiertos+=$r['abiertos']; $totResueltos+=$r['resueltos'];
    $totEf+=$r['voto_efectivo']; $totVal+=$r['validos'];
    if ($r['apoyos']>0) $conApoyos++;
    if ($r['tickets']>0) $conTickets++;
    if ($r['rentabilidad']>=$rentMed && $r['apoyos']===0) $afinesSinApoyo++;
    if ($r['abiertos']>0 && $r['apoyos']===0) $demandaSinCobertura++;
    $quadCount[$r['quad']]++;
}
$rentGlobal = $totVal>0 ? $totEf/$totVal*100 : 0;
$coberturaDif = $nSecs>0 ? $conApoyos/$nSecs*100 : 0;
$tasaResol = $totTickets>0 ? $totResueltos/$totTickets*100 : 0;

/* ------------------------------ Geografía (GeoJSON) ------------------------------ */
$geoReady = tabla_existe($pdo, 'secciones') && tabla_existe($pdo, 'secciones_geo');
$features = []; $secConPoligono = 0;
if ($geoReady && $rows) {
    $secsCsv = implode(',', array_map(fn($s) => (int)$s, array_keys($rows)));
    $tieneDistritos = tabla_existe($pdo, 'distritos');
    $joinD = $tieneDistritos ? "LEFT JOIN distritos d ON d.id = s.distrito_id" : "";
    $selD  = $tieneDistritos ? "d.numero AS distrito_num" : "NULL AS distrito_num";
    $sqlGeo = "SELECT s.num_seccion, s.municipio, $selD, ST_AsGeoJSON(g.geom, 5) AS gj
                 FROM secciones s JOIN secciones_geo g ON g.seccion_id = s.id $joinD
                WHERE s.num_seccion IN ($secsCsv)";
    $vistos = [];
    foreach ($pdo->query($sqlGeo) as $g) {
        $sec = (int)$g['num_seccion'];
        if (isset($vistos[$sec]) || !isset($rows[$sec]) || !$g['gj']) continue;
        $geom = json_decode($g['gj'], true); if (!$geom) continue;
        $vistos[$sec] = true; $secConPoligono++;
        $r = $rows[$sec];
        $features[] = ['type'=>'Feature','geometry'=>$geom,'properties'=>[
            's'=>$sec,'mun'=>$g['municipio'],'amb'=>$r['ambito_nombre'],
            'rent'=>$r['rentabilidad'],'part'=>$r['participacion'],
            'ap'=>$r['apoyos'],'ben'=>$r['beneficiarios'],
            'tk'=>$r['tickets'],'ab'=>$r['abiertos'],'res'=>$r['resueltos'],'q'=>$r['quad'],
        ]];
    }
}
$geojson = ['type'=>'FeatureCollection', 'features'=>$features];

/* ------------------------------ Datos para charts ------------------------------ */
$scatter = [];
foreach ($rows as $r) $scatter[] = ['x'=>$r['rentabilidad'],'y'=>$r['apoyos'],'s'=>$r['num_seccion'],'t'=>$r['tickets'],'ln'=>$r['lista_nominal'],'q'=>$r['quad']];

$afines = array_filter($rows, fn($r) => $r['rentabilidad'] >= $rentMed && $r['apoyos'] === 0);
uasort($afines, fn($a,$b) => $b['rentabilidad'] <=> $a['rentabilidad']);
$afines = array_slice($afines, 0, 8, true);

$demanda = array_filter($rows, fn($r) => $r['abiertos'] > 0 && $r['apoyos'] === 0);
uasort($demanda, fn($a,$b) => $b['abiertos'] <=> $a['abiertos']);
$demanda = array_slice($demanda, 0, 8, true);

/* ------------------------------ Export CSV ------------------------------ */
if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cruce_seccion_' . $procesoId . '_' . $tipoId . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Seccion','Ambito','Casillas','Rentabilidad_%','Participacion_%','Apoyos_DIF','Beneficiarios','Tickets','Tickets_abiertos','Tickets_resueltos','Apoyos_x1000LN','Tickets_x1000LN','Cuadrante']);
    foreach ($rows as $r) fputcsv($out, [$r['num_seccion'],$r['ambito_nombre'],$r['n_casillas'],$r['rentabilidad'],$r['participacion'],$r['apoyos'],$r['beneficiarios'],$r['tickets'],$r['abiertos'],$r['resueltos'],$r['apoyos_x1000'],$r['tickets_x1000'],$quadDefs[$r['quad']]['t']]);
    fclose($out); exit;
}

/* ------------------------------ Helpers de render ------------------------------ */
function ordLink(string $col, string $label, string $ordActual, string $dirActual): string {
    $params = $_GET; $params['orden']=$col;
    $params['dir'] = ($ordActual===$col && $dirActual==='desc') ? 'asc' : 'desc';
    unset($params['export']);
    $flecha = $ordActual===$col ? ($dirActual==='desc'?' ▾':' ▴') : '';
    return '<a href="?'.htmlspecialchars(http_build_query($params)).'" style="color:inherit;text-decoration:none">'.htmlspecialchars($label).$flecha.'</a>';
}
function rentColor(float $v): string {
    if ($v>=55) return '#15803d'; if ($v>=45) return '#65a30d'; if ($v>=35) return '#ca8a04'; if ($v>=25) return '#ea580c'; return '#dc2626';
}

$title='Cruce por sección'; $active='cruce';
include __DIR__ . '/../partials/layout_top.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
  .cx-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(148px,1fr));gap:12px;margin-bottom:18px}
  .cx-kpi{background:var(--color-surface);border:1px solid var(--color-border);border-radius:14px;padding:14px 16px;position:relative;overflow:hidden}
  .cx-kpi .v{font-size:25px;font-weight:800;line-height:1.05;color:var(--qro-blue-dark)}
  .cx-kpi .l{font-size:12px;color:var(--color-text-secondary);margin-top:4px;font-weight:600}
  .cx-kpi .s{font-size:11px;color:var(--color-text-muted);margin-top:2px}
  .cx-kpi .bar-accent{position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--qro-blue)}
  .cx-grid{display:grid;grid-template-columns:1.55fr 1fr;gap:16px;margin-bottom:18px}
  @media(max-width:1050px){.cx-grid{grid-template-columns:1fr}}
  #cx-map{height:520px;border-radius:12px;border:1px solid var(--color-border);overflow:hidden}
  .cx-maphead{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}
  .cx-metric-btns{display:flex;gap:6px;flex-wrap:wrap}
  .cx-mbtn{padding:6px 12px;border-radius:999px;border:1px solid var(--color-border);background:#fff;cursor:pointer;font-size:12px;font-weight:700;color:var(--color-text-secondary)}
  .cx-mbtn.on{background:var(--qro-blue-dark);color:#fff;border-color:var(--qro-blue-dark)}
  .cx-legend{display:flex;gap:8px;align-items:center;font-size:11px;color:var(--color-text-secondary);flex-wrap:wrap}
  .cx-legend i{width:16px;height:12px;border-radius:3px;display:inline-block;margin-right:3px;vertical-align:middle}
  .cx-detail{font-size:13px}
  .cx-detail h4{margin:0 0 4px;color:var(--qro-blue-dark)}
  .cx-detail .row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px dashed #eef0f2}
  .cx-detail .row b{font-variant-numeric:tabular-nums}
  .cx-quadtag{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700;color:#fff}
  .cx-charts{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px}
  @media(max-width:1050px){.cx-charts{grid-template-columns:1fr}}
  .cx-lists{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px}
  @media(max-width:900px){.cx-lists{grid-template-columns:1fr}}
  .cx-li{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid #f1f3f5;font-size:13px}
  .cx-li .sec{font-weight:700}.cx-li .mut{color:var(--color-text-muted);font-size:12px}
  .cx-adv{margin-bottom:18px}
  .cx-adv summary{cursor:pointer;font-weight:700;color:var(--qro-blue-dark);padding:4px 0}
  .cx-advgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:12px}
  .cx-fsec{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--color-text-muted);font-weight:700;grid-column:1/-1;margin-top:4px}
  .tbl{width:100%;border-collapse:collapse;font-size:13px}
  .tbl th,.tbl td{padding:7px 9px;border-bottom:1px solid #eef0f2;text-align:right;white-space:nowrap}
  .tbl th:nth-child(2),.tbl td:nth-child(2){text-align:left}
  .tbl thead th{position:sticky;top:0;background:#eef5fc;font-weight:700;color:var(--qro-blue-dark);z-index:1}
  .tbl tbody tr:hover{background:#f7fafe;cursor:pointer}
  .pill{display:inline-block;min-width:24px;padding:1px 7px;border-radius:999px;font-weight:700;font-size:12px}
  .p-ap{background:#eff6ff;color:#1d4ed8}.p-tk{background:#fef2f2;color:#b91c1c}.p-ab{background:#fff7ed;color:#c2410c}.p-0{background:#f3f4f6;color:#9ca3af}
  .aviso{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px}
  /* Modal de drill-down */
  .cx-modal{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:flex-start;justify-content:center;z-index:9999;padding:34px 16px;overflow:auto}
  .cx-modal.on{display:flex}
  .cx-modal-card{background:#fff;border-radius:16px;width:100%;max-width:880px;box-shadow:0 24px 60px rgba(0,0,0,.3);overflow:hidden}
  .cx-modal-head{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:var(--qro-blue-dark);color:#fff}
  .cx-modal-head h3{margin:0;color:#fff;font-size:18px}
  .cx-modal-head .x{cursor:pointer;font-size:22px;line-height:1;opacity:.85;background:none;border:none;color:#fff}
  .cx-modal-body{padding:20px}
  .cx-mini{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px;margin-bottom:18px}
  .cx-mini .b{background:#f5f7fb;border:1px solid var(--color-border);border-radius:10px;padding:10px 12px}
  .cx-mini .b .v{font-size:20px;font-weight:800;color:var(--qro-blue-dark);line-height:1.1}
  .cx-mini .b .l{font-size:11px;color:var(--color-text-secondary);font-weight:600;margin-top:2px}
  .cx-sec-h{font-size:13px;font-weight:700;color:var(--qro-blue-dark);margin:16px 0 8px;text-transform:uppercase;letter-spacing:.4px}
  .cx-bar{display:grid;grid-template-columns:140px 1fr 92px;gap:8px;align-items:center;margin:4px 0;font-size:13px}
  .cx-bar .nm{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .cx-bar .tr{background:#eef2f6;border-radius:999px;height:14px;overflow:hidden}
  .cx-bar .tr>span{display:block;height:100%;border-radius:999px}
  .cx-bar .vl{text-align:right;font-variant-numeric:tabular-nums;color:var(--color-text-secondary)}
  .cx-two{display:grid;grid-template-columns:1fr 1fr;gap:18px}
  @media(max-width:720px){.cx-two{grid-template-columns:1fr}}
</style>

<div class="page-header">
  <div>
    <h1>Cruce territorial · electoral × ciudadanía × padrón</h1>
    <p>Afinidad electoral de <strong><?= htmlspecialchars($partido) ?></strong>, apoyos del DIF y reportes de Zendesk, cruzados por sección. Enlace por <code>num_seccion</code>.</p>
  </div>
</div>

<?php if (!$padronOk || !$ticketsOk): ?>
  <div class="aviso">
    <?php if (!$padronOk): ?>El padrón DIF aún no tiene <code>seccion_id</code> (corre una importación DIF). <?php endif; ?>
    <?php if (!$ticketsOk): ?>Los tickets de Zendesk aún no tienen <code>seccion_id</code> (sincroniza Zendesk). <?php endif; ?>
    Esas columnas se mostrarán en cero mientras tanto.
  </div>
<?php endif; ?>

<!-- ===== Filtros ===== -->
<form method="get" class="card" style="margin-bottom:14px">
  <div style="display:grid;grid-template-columns:1fr 1.2fr 1fr 0.7fr auto auto;gap:10px;align-items:end">
    <div class="field"><label>Proceso</label>
      <select name="proceso_id" class="input" onchange="this.form.submit()">
        <?php foreach ($procesos as $p): ?><option value="<?= $p['id'] ?>" <?= $procesoId==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['anio'].' · '.ucfirst($p['nivel'])) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Tipo de elección</label>
      <select name="tipo_id" class="input" onchange="this.form.submit()">
        <?php foreach ($tipos as $t): ?><option value="<?= $t['id'] ?>" <?= $tipoId==$t['id']?'selected':'' ?>><?= htmlspecialchars($t['nombre']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Ámbito</label>
      <select name="ambito_codigo" class="input" onchange="this.form.submit()">
        <option value="">Todos</option>
        <?php foreach ($ambitos as $a): ?><option value="<?= htmlspecialchars($a['ambito_codigo']) ?>" <?= $ambitoCode===$a['ambito_codigo']?'selected':'' ?>><?= htmlspecialchars($a['ambito_nombre']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Sección</label><input type="text" name="sec_search" class="input" value="<?= htmlspecialchars($secSearch) ?>" placeholder="Nº"></div>
    <div class="field"><label><input type="checkbox" name="solo_datos" value="1" <?= $soloConDatos?'checked':'' ?>> Solo con apoyos/tickets</label></div>
    <div class="field"><button class="btn" type="submit">Aplicar</button></div>
  </div>

  <!-- ===== Filtros avanzados ===== -->
  <details class="cx-adv" <?= $advActivo?'open':'' ?>>
    <summary>Filtros avanzados — Zendesk &amp; DIF <?= $advActivo?'· <span style="color:#16a34a">activos</span>':'' ?></summary>
    <div class="cx-advgrid">
      <div class="cx-fsec">Atención ciudadana (Zendesk)</div>
      <div class="field"><label>Estado del ticket</label>
        <select name="tk_estado" class="input">
          <option value="">Todos</option>
          <option value="abiertos"    <?= $tkEstado==='abiertos'?'selected':'' ?>>Abiertos / sin resolver</option>
          <option value="resueltos"   <?= $tkEstado==='resueltos'?'selected':'' ?>>Resueltos</option>
          <option value="finalizados" <?= $tkEstado==='finalizados'?'selected':'' ?>>Finalizados (cerrados)</option>
        </select></div>
      <div class="field"><label>Tipo de servicio</label>
        <select name="tk_tipo" class="input">
          <option value="0">Todos</option>
          <?php foreach ($tiposServicio as $ts): ?><option value="<?= $ts['id'] ?>" <?= $tkTipo==$ts['id']?'selected':'' ?>><?= htmlspecialchars($ts['nombre']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label>Creados desde</label><input type="date" name="tk_desde" class="input" value="<?= htmlspecialchars($tkDesde) ?>"></div>
      <div class="field"><label>Creados hasta</label><input type="date" name="tk_hasta" class="input" value="<?= htmlspecialchars($tkHasta) ?>"></div>

      <div class="cx-fsec">Padrón de apoyos (DIF)</div>
      <div class="field"><label>Programa</label>
        <select name="dif_programa" class="input">
          <option value="">Todos</option>
          <?php foreach ($programas as $pr): ?><option value="<?= htmlspecialchars($pr) ?>" <?= $difPrograma===$pr?'selected':'' ?>><?= htmlspecialchars($pr) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label>Entregados desde</label><input type="date" name="dif_desde" class="input" value="<?= htmlspecialchars($difDesde) ?>"></div>
      <div class="field"><label>Entregados hasta</label><input type="date" name="dif_hasta" class="input" value="<?= htmlspecialchars($difHasta) ?>"></div>
      <div class="field" style="align-self:end"><button class="btn" type="submit">Aplicar filtros</button>
        <a class="btn btn-secondary" style="margin-left:6px" href="?proceso_id=<?= $procesoId ?>&tipo_id=<?= $tipoId ?>">Limpiar</a></div>
    </div>
  </details>
</form>

<!-- ===== KPIs ===== -->
<div class="cx-kpis">
  <div class="cx-kpi"><span class="bar-accent"></span><div class="v"><?= number_format($nSecs) ?></div><div class="l">Secciones</div><div class="s"><?= number_format($secConPoligono) ?> con polígono</div></div>
  <div class="cx-kpi"><span class="bar-accent" style="background:<?= rentColor($rentGlobal) ?>"></span><div class="v" style="color:<?= rentColor($rentGlobal) ?>"><?= number_format($rentGlobal,1) ?>%</div><div class="l">Rentabilidad <?= htmlspecialchars($partido) ?></div><div class="s">mediana <?= number_format($rentMed,1) ?>%</div></div>
  <div class="cx-kpi"><span class="bar-accent" style="background:#1d4ed8"></span><div class="v"><?= number_format($totApoyos) ?></div><div class="l">Apoyos DIF</div><div class="s"><?= number_format($totBenef) ?> beneficiarios</div></div>
  <div class="cx-kpi"><span class="bar-accent" style="background:#0ea5e9"></span><div class="v"><?= number_format($coberturaDif,0) ?>%</div><div class="l">Cobertura DIF</div><div class="s"><?= number_format($conApoyos) ?> secc. con apoyo</div></div>
  <div class="cx-kpi"><span class="bar-accent" style="background:#b91c1c"></span><div class="v"><?= number_format($totTickets) ?></div><div class="l">Tickets</div><div class="s"><?= number_format($conTickets) ?> secc. con reportes</div></div>
  <div class="cx-kpi"><span class="bar-accent" style="background:#c2410c"></span><div class="v" style="color:#c2410c"><?= number_format($totAbiertos) ?></div><div class="l">Tickets abiertos</div><div class="s"><?= number_format($tasaResol,0) ?>% resueltos</div></div>
  <div class="cx-kpi"><span class="bar-accent" style="background:#16a34a"></span><div class="v" style="color:#16a34a"><?= number_format($afinesSinApoyo) ?></div><div class="l">Afines sin apoyo</div><div class="s">rentabilidad alta, 0 apoyos</div></div>
  <div class="cx-kpi"><span class="bar-accent" style="background:#d99000"></span><div class="v" style="color:#d99000"><?= number_format($demandaSinCobertura) ?></div><div class="l">Demanda sin cobertura</div><div class="s">tickets abiertos, 0 apoyos</div></div>
</div>

<!-- ===== Mapa + detalle ===== -->
<div class="cx-grid">
  <div class="card">
    <div class="cx-maphead">
      <strong>Mapa coroplético</strong>
      <div class="cx-metric-btns" id="cx-metric-btns">
        <button type="button" class="cx-mbtn on" data-m="rent">Rentabilidad</button>
        <button type="button" class="cx-mbtn" data-m="ap">Apoyos DIF</button>
        <button type="button" class="cx-mbtn" data-m="tk">Tickets</button>
        <button type="button" class="cx-mbtn" data-m="ab">Abiertos</button>
        <button type="button" class="cx-mbtn" data-m="q">Cuadrante</button>
      </div>
    </div>
    <div class="cx-legend" id="cx-legend" style="margin-bottom:8px"></div>
    <div id="cx-map"></div>
  </div>
  <div class="card">
    <strong>Detalle de sección</strong>
    <div id="cx-detail" class="cx-detail" style="margin-top:10px">
      <p class="muted">Pasa el cursor o haz clic en una sección del mapa (o en una fila de la tabla) para ver su detalle.</p>
    </div>
  </div>
</div>

<!-- ===== Charts ===== -->
<div class="cx-charts">
  <div class="card">
    <strong>Afinidad electoral vs apoyos DIF</strong>
    <p class="muted" style="margin:2px 0 10px;font-size:12px">Cada burbuja es una sección (tamaño = lista nominal). Líneas punteadas = medianas. Color = cuadrante.</p>
    <div style="height:320px"><canvas id="cx-scatter"></canvas></div>
  </div>
  <div class="card">
    <strong>Cuadrantes estratégicos</strong>
    <p class="muted" style="margin:2px 0 10px;font-size:12px">Afinidad (rentabilidad) × atención (apoyos) contra sus medianas.</p>
    <div style="height:320px"><canvas id="cx-quad"></canvas></div>
    <div id="cx-quad-leg" style="margin-top:10px;font-size:12px"></div>
  </div>
</div>

<!-- ===== Listas accionables ===== -->
<div class="cx-lists">
  <div class="card">
    <strong style="color:#16a34a">⬤ Secciones afines sin apoyo</strong>
    <p class="muted" style="margin:2px 0 8px;font-size:12px">Rentabilidad alta y 0 apoyos del DIF — capital electoral desatendido.</p>
    <?php if (!$afines): ?><p class="muted">Sin secciones en esta categoría.</p><?php endif; ?>
    <?php foreach ($afines as $r): ?>
      <div class="cx-li"><span><span class="sec">Sec. <?= $r['num_seccion'] ?></span> <span class="mut"><?= htmlspecialchars($r['ambito_nombre']) ?></span></span>
        <span style="color:<?= rentColor($r['rentabilidad']) ?>;font-weight:700"><?= number_format($r['rentabilidad'],1) ?>%</span></div>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <strong style="color:#c2410c">⬤ Mayor demanda abierta sin cobertura</strong>
    <p class="muted" style="margin:2px 0 8px;font-size:12px">Más tickets <em>abiertos</em> de Zendesk y 0 apoyos del DIF — presión ciudadana sin respuesta.</p>
    <?php if (!$demanda): ?><p class="muted">Sin secciones en esta categoría.</p><?php endif; ?>
    <?php foreach ($demanda as $r): ?>
      <div class="cx-li"><span><span class="sec">Sec. <?= $r['num_seccion'] ?></span> <span class="mut"><?= htmlspecialchars($r['ambito_nombre']) ?></span></span>
        <span class="pill p-ab"><?= number_format($r['abiertos']) ?> abiertos</span></div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ===== Tabla ===== -->
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
    <strong>Detalle por sección</strong>
    <a class="btn" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>">Descargar CSV</a>
  </div>
  <div style="max-height:55vh;overflow:auto">
    <table class="tbl" id="cx-table">
      <thead><tr>
        <th><?= ordLink('num_seccion','Sección',$orden,$dir) ?></th>
        <th>Ámbito</th><th>Casillas</th>
        <th><?= ordLink('rentabilidad','Rentab.',$orden,$dir) ?></th>
        <th><?= ordLink('participacion','Particip.',$orden,$dir) ?></th>
        <th><?= ordLink('apoyos','Apoyos',$orden,$dir) ?></th>
        <th><?= ordLink('beneficiarios','Benefic.',$orden,$dir) ?></th>
        <th><?= ordLink('tickets','Tickets',$orden,$dir) ?></th>
        <th><?= ordLink('abiertos','Abiertos',$orden,$dir) ?></th>
        <th>Cuadrante</th>
      </tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="10" style="text-align:center;color:#9ca3af;padding:24px">Sin datos para el filtro seleccionado.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr data-sec="<?= $r['num_seccion'] ?>">
            <td><strong><?= $r['num_seccion'] ?></strong></td>
            <td><?= htmlspecialchars($r['ambito_nombre']) ?></td>
            <td><?= number_format($r['n_casillas']) ?></td>
            <td style="color:<?= rentColor($r['rentabilidad']) ?>;font-weight:700"><?= number_format($r['rentabilidad'],1) ?>%</td>
            <td><?= number_format($r['participacion'],1) ?>%</td>
            <td><span class="pill <?= $r['apoyos']?'p-ap':'p-0' ?>"><?= number_format($r['apoyos']) ?></span></td>
            <td><?= number_format($r['beneficiarios']) ?></td>
            <td><span class="pill <?= $r['tickets']?'p-tk':'p-0' ?>"><?= number_format($r['tickets']) ?></span></td>
            <td><span class="pill <?= $r['abiertos']?'p-ab':'p-0' ?>"><?= number_format($r['abiertos']) ?></span></td>
            <td style="text-align:left"><span class="cx-quadtag" style="background:<?= $quadDefs[$r['quad']]['c'] ?>"><?= htmlspecialchars($quadDefs[$r['quad']]['t']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== Modal drill-down ===== -->
<div class="cx-modal" id="cx-modal">
  <div class="cx-modal-card">
    <div class="cx-modal-head">
      <h3 id="cx-modal-title">Detalle de sección</h3>
      <button type="button" class="x" onclick="closeModal()">×</button>
    </div>
    <div class="cx-modal-body" id="cx-modal-body"></div>
  </div>
</div>

<script>
const CX = {
  geo: <?= json_encode($geojson, JSON_UNESCAPED_UNICODE) ?>,
  scatter: <?= json_encode($scatter, JSON_UNESCAPED_UNICODE) ?>,
  quadCount: <?= json_encode($quadCount) ?>,
  quadDefs: <?= json_encode($quadDefs, JSON_UNESCAPED_UNICODE) ?>,
  rentMed: <?= json_encode(round($rentMed,2)) ?>,
  apoyosMed: <?= json_encode(round($apoyosMed,2)) ?>,
  partido: <?= json_encode($partido) ?>,
  hasKey: <?= $gmapsKey ? 'true' : 'false' ?>,
  filters: <?= json_encode([
      'proceso_id'=>$procesoId, 'tipo_id'=>$tipoId, 'ambito_codigo'=>$ambitoCode,
      'tk_estado'=>$tkEstado, 'tk_tipo'=>$tkTipo ?: '', 'tk_desde'=>$tkDesde, 'tk_hasta'=>$tkHasta,
      'dif_programa'=>$difPrograma, 'dif_desde'=>$difDesde, 'dif_hasta'=>$difHasta,
  ], JSON_UNESCAPED_UNICODE) ?>,
};
function rentColor(v){ return v>=55?'#15803d':v>=45?'#65a30d':v>=35?'#ca8a04':v>=25?'#ea580c':'#dc2626'; }
function rampColor(v,max,base){ if(max<=0||v===0) return '#eef2f6'; const t=Math.min(1,v/max);
  if(base==='b') return `rgba(29,78,216,${0.15+0.8*t})`; if(base==='o') return `rgba(194,65,12,${0.15+0.8*t})`; return `rgba(185,28,28,${0.15+0.8*t})`; }
const quadColors={}; for(const k in CX.quadDefs) quadColors[k]=CX.quadDefs[k].c;
let MAXAP=0,MAXTK=0,MAXAB=0;
CX.geo.features.forEach(f=>{const p=f.properties;MAXAP=Math.max(MAXAP,p.ap);MAXTK=Math.max(MAXTK,p.tk);MAXAB=Math.max(MAXAB,p.ab);});
let metric='rent';
function featColor(p){
  if(metric==='rent') return rentColor(p.rent);
  if(metric==='ap')   return rampColor(p.ap,MAXAP,'b');
  if(metric==='tk')   return rampColor(p.tk,MAXTK,'r');
  if(metric==='ab')   return rampColor(p.ab,MAXAB,'o');
  return quadColors[p.q]||'#94a3b8';
}
function escapeHtml(s){ return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

/* ---------- Google Maps ---------- */
let gmap, infoWin, featBySec={};
function propsOf(feature){ const g=feature.getProperty.bind(feature);
  return {s:g('s'),mun:g('mun'),amb:g('amb'),rent:g('rent'),part:g('part'),ap:g('ap'),ben:g('ben'),tk:g('tk'),ab:g('ab'),res:g('res'),q:g('q')}; }
function styleFor(feature){ return {fillColor:featColor(propsOf(feature)),fillOpacity:0.82,strokeColor:'#ffffff',strokeWeight:0.7,clickable:true}; }
window.initCxMap = function(){
  const el=document.getElementById('cx-map');
  if(!CX.geo.features.length){ el.innerHTML='<div style="padding:24px;color:#6b7280">Sin polígonos para las secciones filtradas.</div>'; return; }
  gmap=new google.maps.Map(el,{center:{lat:20.59,lng:-100.39},zoom:9,mapTypeControl:false,streetViewControl:false,fullscreenControl:true,styles:[{featureType:'poi',stylers:[{visibility:'off'}]}]});
  infoWin=new google.maps.InfoWindow();
  gmap.data.addGeoJson(CX.geo);
  gmap.data.setStyle(styleFor);
  const bounds=new google.maps.LatLngBounds();
  gmap.data.forEach(f=>{ featBySec[f.getProperty('s')]=f; f.getGeometry().forEachLatLng(ll=>bounds.extend(ll)); });
  if(!bounds.isEmpty()) gmap.fitBounds(bounds);
  gmap.data.addListener('mouseover',e=>{ gmap.data.overrideStyle(e.feature,{strokeWeight:2.5,strokeColor:'#111',fillOpacity:0.9}); showDetail(propsOf(e.feature)); });
  gmap.data.addListener('mouseout', ()=> gmap.data.revertStyle());
  gmap.data.addListener('click', e=>{ const b=new google.maps.LatLngBounds(); e.feature.getGeometry().forEachLatLng(ll=>b.extend(ll)); gmap.fitBounds(b); openSeccionModal(e.feature.getProperty('s')); });
  renderLegend();
};
function zoomToSec(sec){ const f=featBySec[sec]; if(!f||!gmap) return; const b=new google.maps.LatLngBounds(); f.getGeometry().forEachLatLng(ll=>b.extend(ll)); gmap.fitBounds(b); gmap.data.overrideStyle(f,{strokeWeight:3,strokeColor:'#111'}); setTimeout(()=>gmap.data.revertStyle(),1200); }
function renderLegend(){
  const el=document.getElementById('cx-legend'); let h='';
  if(metric==='rent'){ const s=[['<25%','#dc2626'],['25-35','#ea580c'],['35-45','#ca8a04'],['45-55','#65a30d'],['≥55%','#15803d']]; h=s.map(x=>`<span><i style="background:${x[1]}"></i>${x[0]}</span>`).join(''); }
  else if(metric==='ap'){ h=`<span><i style="background:#eef2f6"></i>0</span><span><i style="background:rgba(29,78,216,.35)"></i>bajo</span><span><i style="background:rgba(29,78,216,.95)"></i>alto (máx ${MAXAP})</span>`; }
  else if(metric==='tk'){ h=`<span><i style="background:#eef2f6"></i>0</span><span><i style="background:rgba(185,28,28,.35)"></i>bajo</span><span><i style="background:rgba(185,28,28,.95)"></i>alto (máx ${MAXTK})</span>`; }
  else if(metric==='ab'){ h=`<span><i style="background:#eef2f6"></i>0</span><span><i style="background:rgba(194,65,12,.35)"></i>bajo</span><span><i style="background:rgba(194,65,12,.95)"></i>alto (máx ${MAXAB})</span>`; }
  else { h=Object.keys(CX.quadDefs).map(k=>`<span><i style="background:${CX.quadDefs[k].c}"></i>${CX.quadDefs[k].t}</span>`).join(''); }
  el.innerHTML=h;
}
document.getElementById('cx-metric-btns').addEventListener('click',e=>{
  const b=e.target.closest('.cx-mbtn'); if(!b) return; metric=b.dataset.m;
  document.querySelectorAll('.cx-mbtn').forEach(x=>x.classList.toggle('on',x===b));
  if(gmap) gmap.data.setStyle(styleFor); renderLegend();
});
function showDetail(p){
  const q=CX.quadDefs[p.q]||{t:'—',c:'#94a3b8'};
  document.getElementById('cx-detail').innerHTML=`
    <h4>Sección ${p.s} <span class="cx-quadtag" style="background:${q.c}">${q.t}</span></h4>
    <div class="muted" style="margin-bottom:8px">${p.mun?escapeHtml(p.mun)+' · ':''}${escapeHtml(p.amb||'')}</div>
    <div class="row"><span>Rentabilidad ${escapeHtml(CX.partido)}</span><b style="color:${rentColor(p.rent)}">${(+p.rent).toFixed(1)}%</b></div>
    <div class="row"><span>Participación</span><b>${(+p.part).toFixed(1)}%</b></div>
    <div class="row"><span>Apoyos DIF</span><b>${(+p.ap).toLocaleString()}</b></div>
    <div class="row"><span>Beneficiarios distintos</span><b>${(+p.ben).toLocaleString()}</b></div>
    <div class="row"><span>Tickets totales</span><b>${(+p.tk).toLocaleString()}</b></div>
    <div class="row"><span>· Abiertos</span><b style="color:#c2410c">${(+p.ab).toLocaleString()}</b></div>
    <div class="row"><span>· Resueltos</span><b style="color:#16a34a">${(+p.res).toLocaleString()}</b></div>`;
}

/* ---------- Tabla ↔ mapa ---------- */
const secProps={}; CX.geo.features.forEach(f=>secProps[f.properties.s]=f.properties);
document.querySelectorAll('#cx-table tbody tr[data-sec]').forEach(tr=>{
  tr.addEventListener('mouseenter',()=>{ const p=secProps[tr.dataset.sec]; if(p) showDetail(p); });
  tr.addEventListener('click',()=>{ const sec=+tr.dataset.sec; zoomToSec(sec); openSeccionModal(sec); });
});

/* ---------- Modal de drill-down profundo ---------- */
function buildQS(sec){
  const f=CX.filters, p=new URLSearchParams();
  p.set('num_seccion',sec); p.set('proceso_id',f.proceso_id); p.set('tipo_id',f.tipo_id);
  if(f.ambito_codigo) p.set('ambito_codigo',f.ambito_codigo);
  ['tk_estado','tk_tipo','tk_desde','tk_hasta','dif_programa','dif_desde','dif_hasta'].forEach(k=>{ if(f[k]!=='' && f[k]!=null) p.set(k,f[k]); });
  return p.toString();
}
function openSeccionModal(sec){
  const ov=document.getElementById('cx-modal'), body=document.getElementById('cx-modal-body');
  document.getElementById('cx-modal-title').textContent='Sección '+sec;
  ov.classList.add('on');
  body.innerHTML='<p class="muted" style="padding:30px;text-align:center">Cargando detalle…</p>';
  fetch('../api/cruce_seccion.php?'+buildQS(sec))
    .then(r=>r.json())
    .then(d=>{ body.innerHTML = d.error ? '<p style="color:#b91c1c;padding:20px">'+escapeHtml(d.error)+'</p>' : renderModal(d); })
    .catch(e=>{ body.innerHTML='<p style="color:#b91c1c;padding:20px">Error de red: '+escapeHtml(String(e))+'</p>'; });
}
function closeModal(){ document.getElementById('cx-modal').classList.remove('on'); }
document.getElementById('cx-modal').addEventListener('click',e=>{ if(e.target.id==='cx-modal') closeModal(); });
document.addEventListener('keydown',e=>{ if(e.key==='Escape') closeModal(); });

function barList(items, color){
  if(!items||!items.length) return '<p class="muted" style="font-size:12px">Sin datos.</p>';
  const max=Math.max(...items.map(i=>i.n))||1;
  return items.map(i=>`<div class="cx-bar"><span class="nm" title="${escapeHtml(i.k)}">${escapeHtml(i.k)}</span>
    <span class="tr"><span style="width:${Math.max(2,i.n/max*100)}%;background:${color}"></span></span>
    <span class="vl">${(+i.n).toLocaleString()}</span></div>`).join('');
}
function renderModal(d){
  const sub=[d.municipio, d.distrito_num?('Distrito '+d.distrito_num):null, d.ambito_nombre].filter(Boolean).map(escapeHtml).join(' · ');
  const maxV=Math.max(...(d.votos||[]).map(v=>v.votos),1);
  const votosHtml=(d.votos||[]).map(v=>`<div class="cx-bar">
      <span class="nm" title="${escapeHtml(v.nombre)}">${v.es_objetivo?'★ ':''}${escapeHtml(v.nombre)}</span>
      <span class="tr"><span style="width:${Math.max(2,v.votos/maxV*100)}%;background:${v.color}"></span></span>
      <span class="vl">${(+v.votos).toLocaleString()}${v.pct!=null?' · '+v.pct+'%':''}</span></div>`).join('') || '<p class="muted">Sin resultados electorales.</p>';
  const rent=d.rentabilidad!=null?d.rentabilidad.toFixed(1)+'%':'—';
  const part=d.participacion!=null?d.participacion.toFixed(1)+'%':'—';
  return `
    <div class="muted" style="margin:-6px 0 14px">${sub||''}</div>
    <div class="cx-mini">
      <div class="b"><div class="v">${(+d.lista_nominal).toLocaleString()}</div><div class="l">Lista nominal</div></div>
      <div class="b"><div class="v">${(+d.emitidos).toLocaleString()}</div><div class="l">Votos emitidos</div></div>
      <div class="b"><div class="v">${part}</div><div class="l">Participación</div></div>
      <div class="b"><div class="v" style="color:${rentColor(d.rentabilidad||0)}">${rent}</div><div class="l">Rentabilidad ${escapeHtml(d.partido)}</div></div>
      <div class="b"><div class="v">${(+d.apoyos.total).toLocaleString()}</div><div class="l">Apoyos DIF</div></div>
      <div class="b"><div class="v">${(+d.tickets.total).toLocaleString()}</div><div class="l">Tickets (${(+d.tickets.abiertos).toLocaleString()} ab.)</div></div>
    </div>
    <div class="cx-sec-h">Votos por partido / coalición</div>
    ${votosHtml}
    <div class="cx-two">
      <div>
        <div class="cx-sec-h">Apoyos DIF · ${(+d.apoyos.total).toLocaleString()} (${(+d.apoyos.beneficiarios).toLocaleString()} benef.)</div>
        ${barList(d.apoyos.por_programa,'#1d4ed8')}
      </div>
      <div>
        <div class="cx-sec-h">Tickets · ${(+d.tickets.abiertos).toLocaleString()} abiertos / ${(+d.tickets.resueltos).toLocaleString()} resueltos</div>
        <div style="font-size:11px;color:#94a3b8;margin-bottom:4px">Por tipo de servicio</div>
        ${barList(d.tickets.por_tipo,'#b91c1c')}
        <div style="font-size:11px;color:#94a3b8;margin:10px 0 4px">Por estado</div>
        ${barList(d.tickets.por_estado,'#c2410c')}
      </div>
    </div>`;
}

/* ---------- Charts ---------- */
new Chart(document.getElementById('cx-scatter'),{
  type:'bubble',
  data:{datasets:[{
    data:CX.scatter.map(d=>({x:d.x,y:d.y,r:Math.max(3,Math.min(14,Math.sqrt((d.ln||0)/40))),s:d.s,t:d.t,q:d.q})),
    parsing:false, backgroundColor:c=>{const q=c.raw&&c.raw.q;return (quadColors[q]||'#94a3b8')+'cc';}, borderColor:'#fff', borderWidth:0.5
  }]},
  options:{plugins:{legend:{display:false},
    tooltip:{callbacks:{label:c=>{const d=c.raw;return `Sec ${d.s}: ${(+d.x).toFixed(1)}% · ${d.y} apoyos · ${d.t} tickets`;}}}},
    scales:{x:{title:{display:true,text:'Rentabilidad '+CX.partido+' (%)'},grid:{color:'#eef2f6'}},
            y:{title:{display:true,text:'Apoyos DIF'},grid:{color:'#eef2f6'},beginAtZero:true}}},
  plugins:[{id:'med',afterDraw:ch=>{const{ctx,scales:{x,y}}=ch;ctx.save();ctx.strokeStyle='#9ca3af';ctx.setLineDash([5,4]);ctx.lineWidth=1;
    const xm=x.getPixelForValue(CX.rentMed),ym=y.getPixelForValue(CX.apoyosMed);
    ctx.beginPath();ctx.moveTo(xm,y.top);ctx.lineTo(xm,y.bottom);ctx.stroke();
    ctx.beginPath();ctx.moveTo(x.left,ym);ctx.lineTo(x.right,ym);ctx.stroke();ctx.restore();}}]
});
const qk=Object.keys(CX.quadDefs);
new Chart(document.getElementById('cx-quad'),{
  type:'bar',
  data:{labels:qk.map(k=>CX.quadDefs[k].t),datasets:[{data:qk.map(k=>CX.quadCount[k]||0),backgroundColor:qk.map(k=>CX.quadDefs[k].c),borderRadius:6}]},
  options:{indexAxis:'y',plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>` ${c.raw} secciones`}}},
    scales:{x:{grid:{color:'#eef2f6'},title:{display:true,text:'Secciones'}},y:{grid:{display:false}}}}
});
document.getElementById('cx-quad-leg').innerHTML=qk.map(k=>`<div style="margin:3px 0"><span class="cx-quadtag" style="background:${CX.quadDefs[k].c}">${CX.quadDefs[k].t}</span> <span class="muted">${CX.quadDefs[k].d}</span></div>`).join('');

if(!CX.hasKey){ document.getElementById('cx-map').innerHTML='<div style="padding:20px;color:#991B1B">Google Maps API key no configurada (define GOOGLE_MAPS_API_KEY).</div>'; }
</script>
<?php if ($gmapsKey): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($gmapsKey) ?>&callback=initCxMap&loading=async&v=weekly"></script>
<?php endif; ?>

<?php include __DIR__ . '/../partials/layout_bottom.php'; ?>
