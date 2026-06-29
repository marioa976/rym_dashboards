<?php
/**
 * Reporte: Índice de Afinidad Partidista (IAP) por sección.
 *
 * Por sección × año × nivel (gubernatura / ayuntamiento / diputación MR local):
 *   voto_efectivo = votos del partido objetivo + coaliciones que lo contienen
 *   rival_top     = máx. de cualquier otro voto_codigo (excl. coaliciones-objetivo, NULOS, NO_REGISTRADAS)
 *   margen_pct    = (voto_efectivo − rival_top) / votos_válidos × 100
 *   (se ignoran secciones con < 50 votos válidos)
 *
 * IAP = wg·margen_gub + wa·margen_ayu + wd·margen_dip  (pesos ajustables; si falta
 * un nivel, su peso se reparte proporcionalmente entre los presentes).
 *
 * Los márgenes por nivel se calculan UNA vez en el servidor (con caché de 10 min);
 * IAP, clasificación, gauge, mapa y tabla se recalculan en el navegador al mover
 * los sliders. Solo lectura.
 */
$REQUIRE_ROLES = ['administrador', 'gerente', 'cliente', 'consulta', 'lector'];
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/electoral_metrics.php';

$pdo = reporteador_pdo();
$U   = auth_user();
$cfg = reporteador_config();
$gmapsKey = $cfg['google_maps']['api_key'] ?? '';
$partido  = electoral_partido_objetivo();

/* ------------------------------ Catálogos ------------------------------ */
$procEstatal = $pdo->query("SELECT id, anio FROM procesos_electorales WHERE nivel='estatal' ORDER BY anio DESC")->fetchAll();
$tipoIds = [];
$st = $pdo->prepare("SELECT id, codigo FROM tipos_eleccion WHERE codigo IN ('gubernatura','ayuntamiento','diputacion_mr_loc')");
$st->execute();
foreach ($st as $r) $tipoIds[$r['codigo']] = (int)$r['id'];

$anios = array_column($procEstatal, 'anio', 'id');   // [proc_id => anio]
$procR = (int)($_GET['anio_actual'] ?? 0);
$procP = (int)($_GET['anio_previo'] ?? 0);
if (!$procR && $procEstatal) $procR = (int)$procEstatal[0]['id'];
if (!$procP && count($procEstatal) > 1) $procP = (int)$procEstatal[1]['id'];

/* ------------------------------ Cálculo de márgenes ------------------------------ */
/** @return array<int, array{ve:int,rival:int,validos:int,margen:float,ln:int}> */
function afinidad_dataset(PDO $pdo, int $proc, int $tipo, string $siglas): array {
    if (!$proc || !$tipo) return [];
    $st = $pdo->prepare(
        "SELECT DISTINCT c.codigo FROM coaliciones c
           JOIN coaliciones_partidos cp ON cp.coalicion_id=c.id
           JOIN partidos p ON p.id=cp.partido_id
           JOIN elecciones e ON e.id=c.eleccion_id
          WHERE p.siglas=? AND e.proceso_id=? AND e.tipo_id=?"
    );
    $st->execute([$siglas, $proc, $tipo]);
    $panset = array_flip(array_merge([$siglas], array_column($st->fetchAll(), 'codigo')));

    $st = $pdo->prepare(
        "SELECT cas.num_seccion s, rc.voto_codigo code, SUM(rc.votos) v
           FROM resultados_casilla rc
           JOIN casillas cas ON cas.id=rc.casilla_id
           JOIN elecciones e ON e.id=rc.eleccion_id
          WHERE e.proceso_id=? AND e.tipo_id=?
       GROUP BY cas.num_seccion, rc.voto_codigo"
    );
    $st->execute([$proc, $tipo]);
    $bySec = [];
    foreach ($st as $r) $bySec[(int)$r['s']][(string)$r['code']] = (int)$r['v'];

    $ln = [];
    $st = $pdo->prepare(
        "SELECT cas.num_seccion s, SUM(rcm.lista_nominal) ln
           FROM resultados_casilla_meta rcm
           JOIN casillas cas ON cas.id=rcm.casilla_id
           JOIN elecciones e ON e.id=rcm.eleccion_id
          WHERE e.proceso_id=? AND e.tipo_id=?
       GROUP BY cas.num_seccion"
    );
    $st->execute([$proc, $tipo]);
    foreach ($st as $r) $ln[(int)$r['s']] = (int)$r['ln'];

    $excl = ['NULOS' => 1, 'NO_REGISTRADAS' => 1];
    $out = [];
    foreach ($bySec as $s => $codes) {
        $ve = 0; $validos = 0; $rival = 0;
        foreach ($codes as $code => $v) {
            $up = strtoupper($code);
            $esPan = isset($panset[$code]);
            if ($esPan) $ve += $v;
            if (!isset($excl[$up])) $validos += $v;
            if (!$esPan && !isset($excl[$up]) && $v > $rival) $rival = $v;
        }
        if ($validos < 50) continue;
        $out[$s] = ['ve'=>$ve, 'rival'=>$rival, 'validos'=>$validos,
                    'margen'=>round(($ve - $rival) / $validos * 100, 2), 'ln'=>$ln[$s] ?? 0];
    }
    return $out;
}

/* ------------------------------ Ensamble (con caché 10 min) ------------------------------ */
$cacheKey  = 'afinidad_' . md5($procR . '|' . $procP . '|' . $partido);
$cacheFile = sys_get_temp_dir() . '/' . $cacheKey . '.json';
$noCache   = isset($_GET['nocache']);
$payload   = null;
if (!$noCache && is_file($cacheFile) && (time() - filemtime($cacheFile)) < 600) {
    $payload = json_decode((string)@file_get_contents($cacheFile), true);
}
if (!is_array($payload)) {
    $ds = [
        'cur' => ['gub'=>afinidad_dataset($pdo, $procR, $tipoIds['gubernatura'] ?? 0, $partido),
                  'ayu'=>afinidad_dataset($pdo, $procR, $tipoIds['ayuntamiento'] ?? 0, $partido),
                  'dip'=>afinidad_dataset($pdo, $procR, $tipoIds['diputacion_mr_loc'] ?? 0, $partido)],
        'pre' => ['gub'=>afinidad_dataset($pdo, $procP, $tipoIds['gubernatura'] ?? 0, $partido),
                  'ayu'=>afinidad_dataset($pdo, $procP, $tipoIds['ayuntamiento'] ?? 0, $partido),
                  'dip'=>afinidad_dataset($pdo, $procP, $tipoIds['diputacion_mr_loc'] ?? 0, $partido)],
    ];
    // Universo de secciones = las que tienen algún dato en cualquier año/nivel
    $secs = [];
    foreach ($ds as $yr) foreach ($yr as $lvl) foreach ($lvl as $s => $_) $secs[$s] = true;
    $secs = array_keys($secs);

    // Geografía + municipio/distrito
    $mun = []; $features = [];
    if ($secs) {
        $csv = implode(',', array_map('intval', $secs));
        $hayDist = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='distritos'")->fetchColumn();
        $joinD = $hayDist ? "LEFT JOIN distritos d ON d.id=s.distrito_id" : "";
        $selD  = $hayDist ? "d.numero AS dist" : "NULL AS dist";
        foreach ($pdo->query("SELECT s.num_seccion, s.municipio, $selD FROM secciones s $joinD WHERE s.num_seccion IN ($csv)") as $r) {
            $mun[(int)$r['num_seccion']] = ['mun'=>$r['municipio'], 'dist'=>$r['dist']];
        }
        $hayGeo = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='secciones_geo'")->fetchColumn();
        if ($hayGeo) {
            $vistos = [];
            foreach ($pdo->query("SELECT s.num_seccion, ST_AsGeoJSON(g.geom,5) gj
                                    FROM secciones s JOIN secciones_geo g ON g.seccion_id=s.id
                                   WHERE s.num_seccion IN ($csv)") as $g) {
                $s = (int)$g['num_seccion'];
                if (isset($vistos[$s]) || !$g['gj']) continue;
                $geom = json_decode($g['gj'], true); if (!$geom) continue;
                $vistos[$s] = true;
                $features[] = ['type'=>'Feature', 'geometry'=>$geom, 'properties'=>['s'=>$s]];
            }
        }
    }

    // Estructura por sección
    $rows = [];
    foreach ($secs as $s) {
        $cg = $ds['cur']['gub'][$s]['margen'] ?? null; $ca = $ds['cur']['ayu'][$s]['margen'] ?? null; $cd = $ds['cur']['dip'][$s]['margen'] ?? null;
        $pg = $ds['pre']['gub'][$s]['margen'] ?? null; $pa = $ds['pre']['ayu'][$s]['margen'] ?? null; $pdp = $ds['pre']['dip'][$s]['margen'] ?? null;
        $lnC = $ds['cur']['gub'][$s]['ln'] ?? max($ds['cur']['ayu'][$s]['ln'] ?? 0, $ds['cur']['dip'][$s]['ln'] ?? 0);
        $lnP = $ds['pre']['gub'][$s]['ln'] ?? max($ds['pre']['ayu'][$s]['ln'] ?? 0, $ds['pre']['dip'][$s]['ln'] ?? 0);
        $rows[] = [
            's'   => $s,
            'mun' => $mun[$s]['mun'] ?? null,
            'dist'=> isset($mun[$s]['dist']) && $mun[$s]['dist'] !== null ? (int)$mun[$s]['dist'] : null,
            'cur' => ['gub'=>$cg, 'ayu'=>$ca, 'dip'=>$cd],
            'pre' => ['gub'=>$pg, 'ayu'=>$pa, 'dip'=>$pdp],
            'lnC' => (int)$lnC, 'lnP' => (int)$lnP,
        ];
    }

    $payload = [
        'rows' => $rows,
        'geo'  => ['type'=>'FeatureCollection', 'features'=>$features],
        'anioActual' => $anios[$procR] ?? null,
        'anioPrevio' => $anios[$procP] ?? null,
    ];
    @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE));
}

$title = 'Afinidad partidista'; $active = 'afinidad';
include __DIR__ . '/../partials/layout_top.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
  .af-top{display:grid;grid-template-columns:1.1fr 1fr;gap:16px;margin-bottom:18px}
  @media(max-width:1050px){.af-top{grid-template-columns:1fr}}
  .af-sliders .sl{display:grid;grid-template-columns:120px 1fr 52px;gap:10px;align-items:center;margin:10px 0}
  .af-sliders input[type=range]{width:100%}
  .af-sliders .pesoval{font-weight:800;color:var(--qro-blue-dark);text-align:right}
  .af-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:8px}
  .af-kpi{border:1px solid var(--color-border);border-radius:12px;padding:12px 14px;text-align:center}
  .af-kpi .v{font-size:24px;font-weight:800}.af-kpi .l{font-size:12px;color:var(--color-text-secondary);font-weight:600;margin-top:2px}
  .af-grid{display:grid;grid-template-columns:1.55fr 1fr;gap:16px;margin-bottom:18px}
  @media(max-width:1050px){.af-grid{grid-template-columns:1fr}}
  #af-map{height:520px;border-radius:12px;border:1px solid var(--color-border);overflow:hidden}
  .af-legend{display:flex;gap:8px;flex-wrap:wrap;font-size:11px;color:var(--color-text-secondary);margin-bottom:8px}
  .af-legend i{width:14px;height:12px;border-radius:3px;display:inline-block;margin-right:3px;vertical-align:middle}
  .af-detail .row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px dashed #eef0f2;font-size:13px}
  .tag{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700;color:#fff}
  .filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:12px}
  .tbl{width:100%;border-collapse:collapse;font-size:13px}
  .tbl th,.tbl td{padding:6px 8px;border-bottom:1px solid #eef0f2;text-align:right;white-space:nowrap}
  .tbl th:nth-child(2),.tbl td:nth-child(2),.tbl th:nth-child(3),.tbl td:nth-child(3){text-align:left}
  .tbl thead th{position:sticky;top:0;background:#eef5fc;color:var(--qro-blue-dark);font-weight:700;cursor:pointer;z-index:1}
  .tbl tbody tr:hover{background:#f7fafe;cursor:pointer}
</style>

<div class="page-header">
  <div>
    <h1>Afinidad partidista · <?= htmlspecialchars($partido) ?></h1>
    <p>Índice de Afinidad (IAP) por sección, combinando el margen de <strong><?= htmlspecialchars($partido) ?></strong> en gubernatura, ayuntamiento y diputación local MR. Ajusta los pesos y todo se recalcula en vivo.</p>
  </div>
</div>

<form method="get" class="card" style="margin-bottom:14px">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;align-items:end">
    <div class="field"><label>Año actual</label>
      <select name="anio_actual" class="input" onchange="this.form.submit()">
        <?php foreach ($procEstatal as $p): ?><option value="<?= $p['id'] ?>" <?= $procR==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['anio']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Año previo (comparativo)</label>
      <select name="anio_previo" class="input" onchange="this.form.submit()">
        <?php foreach ($procEstatal as $p): ?><option value="<?= $p['id'] ?>" <?= $procP==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['anio']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field" style="align-self:end"><a class="btn btn-secondary" href="?anio_actual=<?= $procR ?>&anio_previo=<?= $procP ?>&nocache=1">↻ Recalcular (limpiar caché)</a></div>
  </div>
</form>

<div class="af-top">
  <!-- Gauge -->
  <div class="card">
    <strong>Termómetro de afinidad global</strong>
    <p class="muted" style="margin:2px 0 6px;font-size:12px">IAP global ponderado por lista nominal. Aguja gruesa = año actual; punteada = año previo. Rango −50 a +50.</p>
    <div id="af-gauge" style="text-align:center"></div>
    <div class="af-kpis" id="af-kpis"></div>
  </div>
  <!-- Sliders -->
  <div class="card af-sliders">
    <strong>Pesos por nivel</strong>
    <p class="muted" style="margin:2px 0 8px;font-size:12px">Se normalizan a 100%. Si una sección no tiene un nivel, su peso se reparte entre los presentes.</p>
    <div class="sl"><label>Gubernatura</label><input type="range" id="wg" min="0" max="100" value="40"><span class="pesoval" id="wgv">40%</span></div>
    <div class="sl"><label>Ayuntamiento</label><input type="range" id="wa" min="0" max="100" value="35"><span class="pesoval" id="wav">35%</span></div>
    <div class="sl"><label>Diputación MR</label><input type="range" id="wd" min="0" max="100" value="25"><span class="pesoval" id="wdv">25%</span></div>
    <div style="margin-top:10px;display:flex;gap:8px">
      <button type="button" class="btn btn-secondary" id="af-reset">Restablecer 40/35/25</button>
      <button type="button" class="btn" id="af-csv">Descargar CSV (vista filtrada)</button>
    </div>
  </div>
</div>

<div class="af-grid">
  <div class="card">
    <div class="af-legend" id="af-legend"></div>
    <div id="af-map"></div>
  </div>
  <div class="card">
    <strong>Detalle de sección</strong>
    <div id="af-detail" class="af-detail" style="margin-top:10px"><p class="muted">Haz clic en una sección del mapa o la tabla.</p></div>
  </div>
</div>

<div class="card">
  <strong>Secciones</strong>
  <div class="filters" style="margin-top:10px">
    <select id="f-mun" class="input"><option value="">Todos los municipios</option></select>
    <select id="f-dist" class="input"><option value="">Todos los distritos</option></select>
    <select id="f-clas" class="input"><option value="">Todas las clasificaciones</option></select>
    <input id="f-sec" class="input" placeholder="Nº de sección">
  </div>
  <div style="max-height:55vh;overflow:auto">
    <table class="tbl" id="af-table">
      <thead><tr>
        <th data-k="s">Sección</th><th data-k="mun">Municipio</th><th data-k="dist">Distrito</th>
        <th data-k="iapP">IAP previo</th><th data-k="iapC">IAP actual</th><th data-k="delta">Δ</th>
        <th data-k="cross">Voto cruzado</th><th data-k="clas">Clasificación</th>
        <th data-k="mg">Margen Gub</th><th data-k="ma">Margen Ayu</th><th data-k="md">Margen Dip</th>
      </tr></thead>
      <tbody id="af-tbody"></tbody>
    </table>
  </div>
  <div class="muted" id="af-count" style="margin-top:8px;font-size:12px"></div>
</div>

<script>
const AF = <?= json_encode($payload, JSON_UNESCAPED_UNICODE) ?>;
const PARTIDO = <?= json_encode($partido) ?>;
const HASKEY = <?= $gmapsKey ? 'true':'false' ?>;
const API_PROC = <?= (int)$procR ?>, API_TIPO = <?= (int)($tipoIds['gubernatura'] ?? 0) ?>;

/* ---- Clasificación (color + etiqueta) ---- */
const CLASES = [
  {k:'bastion',     t:'Bastión',          c:'#15803D'},
  {k:'ganada',      t:'Ganada',           c:'#22C55E'},
  {k:'riesgo',      t:'En riesgo',        c:'#F59E0B'},
  {k:'oportunidad', t:'Oportunidad',      c:'#F97316'},
  {k:'competitiva', t:'Competitiva',      c:'#60A5FA'},
  {k:'adversa',     t:'Adversa',          c:'#EF4444'},
  {k:'adversa_prof',t:'Adversa profunda', c:'#7F1D1D'},
  {k:'sindatos',    t:'Sin datos',        c:'#9CA3AF'},
];
const CLASMAP = Object.fromEntries(CLASES.map(x=>[x.k,x]));
function clasificar(iap, delta){
  if(iap===null||iap===undefined||isNaN(iap)) return 'sindatos';
  if(iap>=15) return delta>=0 ? 'bastion' : 'ganada';
  if(iap>=5)  return 'ganada';
  if(iap>=0)  return 'riesgo';
  if(iap>=-10) return (delta>3) ? 'oportunidad' : 'competitiva';
  if(iap>=-25) return 'adversa';
  return 'adversa_prof';
}

/* ---- IAP con redistribución de pesos faltantes ---- */
function iap(margins, w){
  const lv=[['gub',w.g],['ayu',w.a],['dip',w.d]];
  let sw=0, acc=0, present=0;
  for(const [k,wk] of lv){ const m=margins[k]; if(m!==null&&m!==undefined){ sw+=wk; present++; } }
  if(present===0||sw===0) return null;
  for(const [k,wk] of lv){ const m=margins[k]; if(m!==null&&m!==undefined) acc += (wk/sw)*m; }
  return acc;
}
function stddev(arr){
  const v=arr.filter(x=>x!==null&&x!==undefined);
  if(v.length<2) return null;
  const mean=v.reduce((a,b)=>a+b,0)/v.length;
  return Math.sqrt(v.reduce((a,b)=>a+(b-mean)**2,0)/v.length);
}

/* ---- Estado de pesos ---- */
function pesos(){
  let g=+wg.value, a=+wa.value, d=+wd.value; const sum=g+a+d||1;
  return {g:g/sum, a:a/sum, d:d/sum, raw:{g,a,d,sum}};
}
const wg=document.getElementById('wg'), wa=document.getElementById('wa'), wd=document.getElementById('wd');

/* ---- Cómputo por sección para los pesos actuales ---- */
let COMPUTED=[];
function recompute(){
  const w=pesos();
  document.getElementById('wgv').textContent=Math.round(w.g*100)+'%';
  document.getElementById('wav').textContent=Math.round(w.a*100)+'%';
  document.getElementById('wdv').textContent=Math.round(w.d*100)+'%';
  COMPUTED = AF.rows.map(r=>{
    const iapC=iap(r.cur,w), iapP=iap(r.pre,w);
    const delta = (iapC!==null&&iapP!==null) ? (iapC-iapP) : null;
    const clas = clasificar(iapC, delta===null?0:delta);
    const cross = stddev([r.cur.gub, r.cur.ayu, r.cur.dip]);
    return {...r, iapC, iapP, delta, clas, cross};
  });
  renderGauge(); renderKpis(); restyleMap(); renderTable();
}

/* ---- IAP global ponderado por lista nominal ---- */
function globalIap(useCur){
  let num=0, den=0;
  for(const r of COMPUTED){
    const v=useCur?r.iapC:r.iapP, ln=useCur?r.lnC:r.lnP;
    if(v!==null&&ln>0){ num+=v*ln; den+=ln; }
  }
  return den>0 ? num/den : null;
}

/* ---- KPIs por % de lista nominal ---- */
function renderKpis(){
  let total=0, dom=0, disp=0, adv=0;
  for(const r of COMPUTED){
    if(r.iapC===null||r.lnC<=0) continue; total+=r.lnC;
    if(r.clas==='bastion'||r.clas==='ganada') dom+=r.lnC;
    else if(r.clas==='riesgo'||r.clas==='oportunidad'||r.clas==='competitiva') disp+=r.lnC;
    else if(r.clas==='adversa'||r.clas==='adversa_prof') adv+=r.lnC;
  }
  const pct=x=>total>0?Math.round(x/total*100):0;
  document.getElementById('af-kpis').innerHTML=`
    <div class="af-kpi"><div class="v" style="color:#15803D">${pct(dom)}%</div><div class="l">Dominado (lista nominal)</div></div>
    <div class="af-kpi"><div class="v" style="color:#F59E0B">${pct(disp)}%</div><div class="l">En disputa</div></div>
    <div class="af-kpi"><div class="v" style="color:#EF4444">${pct(adv)}%</div><div class="l">Adverso</div></div>`;
}

/* ---- Gauge SVG semicircular ---- */
function gaugeAngle(v){ const c=Math.max(-50,Math.min(50,v)); return 180 - (c+50)/100*180; } // -50→180°, +50→0°
function polar(cx,cy,r,deg){ const a=deg*Math.PI/180; return [cx+r*Math.cos(a), cy-r*Math.sin(a)]; }
function arcPath(cx,cy,r,d0,d1){ const [x0,y0]=polar(cx,cy,r,d0),[x1,y1]=polar(cx,cy,r,d1); const large=Math.abs(d1-d0)>180?1:0; return `M ${x0} ${y0} A ${r} ${r} 0 ${large} 1 ${x1} ${y1}`; }
function renderGauge(){
  const W=360,H=210,cx=180,cy=190,r=150;
  const zonas=[[-50,-25,'#7F1D1D'],[-25,-10,'#EF4444'],[-10,0,'#60A5FA'],[0,5,'#F59E0B'],[5,15,'#22C55E'],[15,50,'#15803D']];
  let svg=`<svg viewBox="0 0 ${W} ${H}" width="100%" style="max-width:420px">`;
  for(const [a,b,c] of zonas){ svg+=`<path d="${arcPath(cx,cy,r,gaugeAngle(a),gaugeAngle(b))}" stroke="${c}" stroke-width="26" fill="none" stroke-linecap="butt"/>`; }
  for(const t of [-50,-25,-10,0,15,50]){ const [x,y]=polar(cx,cy,r-30,gaugeAngle(t)); svg+=`<text x="${x}" y="${y}" font-size="11" fill="#64748b" text-anchor="middle">${t>0?'+':''}${t}</text>`; }
  const gc=globalIap(true), gp=globalIap(false);
  const needle=(v,color,dash)=>{ if(v===null) return ''; const [x,y]=polar(cx,cy,r-18,gaugeAngle(v)); return `<line x1="${cx}" y1="${cy}" x2="${x}" y2="${y}" stroke="${color}" stroke-width="${dash?3:6}" ${dash?'stroke-dasharray="6 5"':''} stroke-linecap="round"/>`; };
  svg+=needle(gp,'#94a3b8',true)+needle(gc,'#1f2937',false);
  svg+=`<circle cx="${cx}" cy="${cy}" r="7" fill="#1f2937"/>`;
  svg+=`<text x="${cx}" y="${cy-46}" font-size="30" font-weight="800" text-anchor="middle" fill="${gc===null?'#9CA3AF':(gc>=0?'#15803D':'#B91C1C')}">${gc===null?'—':(gc>0?'+':'')+gc.toFixed(1)}</text>`;
  svg+=`<text x="${cx}" y="${cy-28}" font-size="11" text-anchor="middle" fill="#64748b">IAP global ${AF.anioActual??''}${gp!==null?' · previo '+(gp>0?'+':'')+gp.toFixed(1):''}</text>`;
  svg+='</svg>';
  document.getElementById('af-gauge').innerHTML=svg;
}

/* ---- Mapa (Google Maps data layer) ---- */
let gmap, featBySec={};
function propsS(f){ return f.getProperty('s'); }
function styleFor(feature){
  const r=bySec[feature.getProperty('s')];
  const c = r ? CLASMAP[r.clas].c : '#e5e7eb';
  return {fillColor:c, fillOpacity:0.82, strokeColor:'#fff', strokeWeight:0.7};
}
let bySec={};
window.initAfMap=function(){
  const el=document.getElementById('af-map');
  if(!AF.geo.features.length){ el.innerHTML='<div style="padding:24px;color:#6b7280">Sin polígonos disponibles.</div>'; return; }
  gmap=new google.maps.Map(el,{center:{lat:20.59,lng:-100.39},zoom:9,mapTypeControl:false,streetViewControl:false,styles:[{featureType:'poi',stylers:[{visibility:'off'}]}]});
  gmap.data.addGeoJson(AF.geo);
  const b=new google.maps.LatLngBounds();
  gmap.data.forEach(f=>{ featBySec[f.getProperty('s')]=f; f.getGeometry().forEachLatLng(ll=>b.extend(ll)); });
  if(!b.isEmpty()) gmap.fitBounds(b);
  gmap.data.setStyle(styleFor);
  gmap.data.addListener('mouseover',e=>{ gmap.data.overrideStyle(e.feature,{strokeWeight:2.5,strokeColor:'#111'}); showDetail(e.feature.getProperty('s')); });
  gmap.data.addListener('mouseout',()=>gmap.data.revertStyle());
  gmap.data.addListener('click',e=>showDetail(e.feature.getProperty('s'), true));
  restyleMap();
};
function restyleMap(){ bySec=Object.fromEntries(COMPUTED.map(r=>[r.s,r])); if(gmap) gmap.data.setStyle(styleFor); renderLegend(); }
function renderLegend(){ document.getElementById('af-legend').innerHTML=CLASES.map(c=>`<span><i style="background:${c.c}"></i>${c.t}</span>`).join(''); }
function zoomSec(s){ const f=featBySec[s]; if(!f||!gmap)return; const b=new google.maps.LatLngBounds(); f.getGeometry().forEachLatLng(ll=>b.extend(ll)); gmap.fitBounds(b); }

function fmtM(v){ return v===null||v===undefined?'—':(v>0?'+':'')+v.toFixed(1); }
function escapeHtml(s){ return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function showDetail(s, withServices=false){
  const r=bySec[s]; if(!r) return;
  const cl=CLASMAP[r.clas];
  document.getElementById('af-detail').innerHTML=`
    <h4 style="margin:0 0 4px;color:var(--qro-blue-dark)">Sección ${s} <span class="tag" style="background:${cl.c}">${cl.t}</span></h4>
    <div class="muted" style="margin-bottom:8px">${r.mun?r.mun:''}${r.dist?(' · Distrito '+r.dist):''}</div>
    <div class="row"><span>IAP ${AF.anioActual} (actual)</span><b style="color:${r.iapC>=0?'#15803D':'#B91C1C'}">${fmtM(r.iapC)}</b></div>
    <div class="row"><span>IAP ${AF.anioPrevio} (previo)</span><b>${fmtM(r.iapP)}</b></div>
    <div class="row"><span>Δ tendencia</span><b style="color:${(r.delta||0)>=0?'#15803D':'#B91C1C'}">${fmtM(r.delta)}</b></div>
    <div class="row"><span>Voto cruzado (inconsistencia)</span><b>${r.cross===null?'—':r.cross.toFixed(1)}</b></div>
    <div class="row"><span>Margen Gubernatura</span><b>${fmtM(r.cur.gub)}</b></div>
    <div class="row"><span>Margen Ayuntamiento</span><b>${fmtM(r.cur.ayu)}</b></div>
    <div class="row"><span>Margen Diputación MR</span><b>${fmtM(r.cur.dip)}</b></div>
    <div id="af-serv" style="margin-top:12px">${withServices?'<div class="muted" style="font-size:12px">Cargando apoyos y tickets…</div>':'<div class="muted" style="font-size:12px">Haz clic para ver apoyos DIF y tickets de esta sección.</div>'}</div>`;
  if (withServices) loadServicios(s);
}
function barRow(k,n,max,color){
  return `<div style="display:grid;grid-template-columns:1fr 60px;gap:6px;align-items:center;margin:3px 0;font-size:12px">
    <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="${escapeHtml(k)}">${escapeHtml(k)}</span>
    <span style="text-align:right;font-variant-numeric:tabular-nums">${(+n).toLocaleString()}</span></div>`;
}
function rankingHtml(rk){
  if(!rk) return '';
  const line=(lbl,val)=> val ? `<div style="font-size:12px;margin-top:2px"><b>${lbl}:</b> ${escapeHtml(val)}</div>` : '';
  const col = rk.colonias ? `<details style="margin-top:5px"><summary style="font-size:12px;cursor:pointer;color:#92400e">Colonias y localidades</summary><div style="font-size:11px;max-height:130px;overflow:auto;margin-top:4px;color:#475569;line-height:1.4">${escapeHtml(rk.colonias)}</div></details>` : '';
  return `<div style="border:1px solid #fde68a;background:#fffbeb;border-radius:10px;padding:10px 12px;margin-bottom:10px">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <span style="font-weight:800;color:#92400e;font-size:12px;text-transform:uppercase;letter-spacing:.4px">Ranking Alfredo</span>
        <span style="font-weight:800;color:#b45309;font-size:18px">#${rk.rank ?? '—'}</span>
      </div>
      ${line('Delegación', rk.delegacion)}${line('21-24', rk.p21_24)}${line('Identidad', rk.identidad)}${col}
    </div>`;
}
function loadServicios(s){
  const el=document.getElementById('af-serv'); if(!el) return;
  const qs=new URLSearchParams({num_seccion:s, proceso_id:API_PROC, tipo_id:API_TIPO});
  fetch('../api/cruce_seccion.php?'+qs).then(r=>r.json()).then(d=>{
    if(d.error){ el.innerHTML='<div class="muted" style="font-size:12px">Sin datos de servicios.</div>'; return; }
    const ap=d.apoyos||{total:0,beneficiarios:0,por_programa:[]};
    const tk=d.tickets||{total:0,abiertos:0,resueltos:0,por_tipo:[]};
    const progs=(ap.por_programa||[]).slice(0,4).map(i=>barRow(i.k,i.n)).join('') || '<div class="muted" style="font-size:12px">Sin apoyos registrados.</div>';
    const tipos=(tk.por_tipo||[]).slice(0,4).map(i=>barRow(i.k,i.n)).join('') || '<div class="muted" style="font-size:12px">Sin tickets registrados.</div>';
    el.innerHTML=`
      ${rankingHtml(d.ranking)}
      <div style="border-top:1px solid #eef0f2;margin-top:6px;padding-top:10px">
        <div style="font-size:12px;font-weight:700;color:#1d4ed8;margin-bottom:4px">Apoyos DIF · ${(+ap.total).toLocaleString()} (${(+ap.beneficiarios).toLocaleString()} benef.)</div>
        ${progs}
      </div>
      <div style="margin-top:10px">
        <div style="font-size:12px;font-weight:700;color:#b91c1c;margin-bottom:4px">Tickets · ${(+tk.abiertos).toLocaleString()} abiertos / ${(+tk.resueltos).toLocaleString()} resueltos</div>
        ${tipos}
      </div>`;
  }).catch(()=>{ el.innerHTML='<div class="muted" style="font-size:12px">No se pudieron cargar los servicios.</div>'; });
}

/* ---- Tabla + filtros + orden ---- */
let sortK='iapC', sortDir=1;
function filtered(){
  const fm=document.getElementById('f-mun').value, fd=document.getElementById('f-dist').value,
        fc=document.getElementById('f-clas').value, fs=document.getElementById('f-sec').value.trim();
  let r=COMPUTED.filter(x=>
    (!fm||x.mun===fm) && (!fd||String(x.dist)===fd) && (!fc||x.clas===fc) && (!fs||String(x.s).includes(fs)));
  r.sort((a,b)=>{
    const va=tblVal(a,sortK), vb=tblVal(b,sortK);
    if(va===null) return 1; if(vb===null) return -1;
    return (va>vb?1:va<vb?-1:0)*sortDir;
  });
  return r;
}
function tblVal(r,k){
  if(k==='clas') return CLASMAP[r.clas].t;
  if(k==='mg') return r.cur.gub; if(k==='ma') return r.cur.ayu; if(k==='md') return r.cur.dip;
  if(k==='iapP') return r.iapP; if(k==='iapC') return r.iapC;
  return r[k];
}
function renderTable(){
  const rows=filtered();
  document.getElementById('af-tbody').innerHTML = rows.map(r=>{
    const cl=CLASMAP[r.clas];
    return `<tr data-sec="${r.s}">
      <td><b>${r.s}</b></td><td>${r.mun||''}</td><td>${r.dist??''}</td>
      <td>${fmtM(r.iapP)}</td><td style="color:${r.iapC>=0?'#15803D':'#B91C1C'};font-weight:700">${fmtM(r.iapC)}</td>
      <td style="color:${(r.delta||0)>=0?'#15803D':'#B91C1C'}">${fmtM(r.delta)}</td>
      <td>${r.cross===null?'—':r.cross.toFixed(1)}</td>
      <td style="text-align:left"><span class="tag" style="background:${cl.c}">${cl.t}</span></td>
      <td>${fmtM(r.cur.gub)}</td><td>${fmtM(r.cur.ayu)}</td><td>${fmtM(r.cur.dip)}</td>
    </tr>`;
  }).join('');
  document.getElementById('af-count').textContent = `${rows.length} secciones · ordenadas por ${sortK} ${sortDir>0?'▲':'▼'}`;
  document.querySelectorAll('#af-tbody tr').forEach(tr=>tr.addEventListener('click',()=>{ const s=+tr.dataset.sec; showDetail(s, true); zoomSec(s); }));
}

/* ---- Poblar filtros ---- */
function fillFilters(){
  const muns=[...new Set(AF.rows.map(r=>r.mun).filter(Boolean))].sort();
  const dists=[...new Set(AF.rows.map(r=>r.dist).filter(x=>x!=null))].sort((a,b)=>a-b);
  document.getElementById('f-mun').innerHTML='<option value="">Todos los municipios</option>'+muns.map(m=>`<option>${m}</option>`).join('');
  document.getElementById('f-dist').innerHTML='<option value="">Todos los distritos</option>'+dists.map(d=>`<option value="${d}">Distrito ${d}</option>`).join('');
  document.getElementById('f-clas').innerHTML='<option value="">Todas las clasificaciones</option>'+CLASES.map(c=>`<option value="${c.k}">${c.t}</option>`).join('');
}

/* ---- CSV ---- */
function exportCsv(){
  const rows=filtered();
  const head=['seccion','municipio','distrito','iap_previo','iap_actual','delta','voto_cruzado','clasificacion','margen_gub','margen_ayu','margen_dip'];
  const lines=[head.join(',')];
  for(const r of rows){
    lines.push([r.s, '"'+(r.mun||'')+'"', r.dist??'', r.iapP??'', r.iapC??'', r.delta??'',
      r.cross===null?'':r.cross.toFixed(2), CLASMAP[r.clas].t, r.cur.gub??'', r.cur.ayu??'', r.cur.dip??''].join(','));
  }
  const blob=new Blob(['﻿'+lines.join('\n')],{type:'text/csv;charset=utf-8'});
  const a=document.createElement('a'); a.href=URL.createObjectURL(blob);
  a.download='afinidad_'+(AF.anioActual||'')+'.csv'; a.click();
}

/* ---- Eventos ---- */
[wg,wa,wd].forEach(s=>s.addEventListener('input',recompute));
document.getElementById('af-reset').addEventListener('click',()=>{ wg.value=40;wa.value=35;wd.value=25; recompute(); });
document.getElementById('af-csv').addEventListener('click',exportCsv);
['f-mun','f-dist','f-clas','f-sec'].forEach(id=>document.getElementById(id).addEventListener('input',renderTable));
document.querySelectorAll('#af-table thead th').forEach(th=>th.addEventListener('click',()=>{
  const k=th.dataset.k; if(sortK===k) sortDir*=-1; else { sortK=k; sortDir=1; } renderTable();
}));

fillFilters(); recompute();
if(!HASKEY){ document.getElementById('af-map').innerHTML='<div style="padding:20px;color:#991B1B">Google Maps API key no configurada.</div>'; }
</script>
<?php if ($gmapsKey): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($gmapsKey) ?>&callback=initAfMap&loading=async&v=weekly"></script>
<?php endif; ?>

<?php include __DIR__ . '/../partials/layout_bottom.php'; ?>
