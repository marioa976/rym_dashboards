<?php
/**
 * Ejecutivo · Reporte electoral (Reporte 3).
 * Mapa seccional del Ayuntamiento 2024: secciones coloreadas por participación
 * o por partido ganador, con KPIs y detalle al hacer clic. Datos: portal_qro
 * (resultados_casilla) agregados por sección en lib.php (cacheado).
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';     // guard: require_module('ejecutivo')
require_once __DIR__ . '/lib.php';

$cfg    = ej_config();
$apiKey = $cfg['google_maps_api_key'] ?? '';

$el = ['sec'=>[], 'geo'=>['type'=>'FeatureCollection','features'=>[]], 'kpis'=>['ln'=>0,'emit'=>0,'val'=>0,'part'=>0,'secciones'=>0]];
$limites = []; $dbError = null;
try {
    $pdo = ej_pdo();
    $el = ej_electoral($pdo);
    $limites = ej_limites($pdo);
} catch (Throwable $e) { $dbError = $e->getMessage(); }
$k = $el['kpis'];
?><?php
$ktTitle  = 'Ejecutivo · Electoral seccional';
$ktActive = 'ejecutivo';
require __DIR__ . '/../../views/layout/kt_top.php';
?>
  <style>
    .el-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:14px}
    .el-kpi{background:#fff;border:1px solid var(--qro-border);border-radius:12px;padding:13px 15px}
    .el-kpi .v{font-size:22px;font-weight:800;color:#1b2f5e;line-height:1.1}
    .el-kpi .l{font-size:12px;color:var(--qro-text-secondary);font-weight:600;margin-top:2px}
    .el-controls{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:8px}
    .switchbtn{padding:6px 12px;border-radius:999px;border:1px solid var(--qro-border);background:#fff;cursor:pointer;font-size:12px;font-weight:700;color:var(--qro-text-secondary)}
    .switchbtn.on{background:#1b2f5e;color:#fff;border-color:#1b2f5e}
    .el-wrap{display:grid;grid-template-columns:1fr 320px;gap:16px}
    @media(max-width:1000px){.el-wrap{grid-template-columns:1fr}}
    #el-map{height:620px;border-radius:12px;border:1px solid var(--qro-border);overflow:hidden}
    .el-side{background:#fff;border:1px solid var(--qro-border);border-radius:12px;padding:14px 16px;max-height:620px;overflow:auto}
    .el-legend{display:flex;gap:10px;align-items:center;font-size:11px;color:var(--qro-text-secondary);margin:8px 0;flex-wrap:wrap}
    .el-legend i{width:16px;height:12px;border-radius:3px;display:inline-block;margin-right:3px;vertical-align:middle}
    .el-row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px dashed #eef0f2;font-size:13px}
  </style>

  <div class="page-head"><h1>Electoral seccional · Ayuntamiento 2024</h1>
    <p class="text-secondary">Resultado por sección de la elección de ayuntamiento (proceso local 2023-2024).</p></div>

  <?php if ($dbError): ?><div class="alert alert-danger">Error: <?= htmlspecialchars($dbError) ?></div><?php endif; ?>
  <?php if (!$apiKey): ?><div class="alert alert-danger">Falta <code>GOOGLE_MAPS_API_KEY</code>.</div><?php endif; ?>

  <div class="el-kpis">
    <div class="el-kpi"><div class="v"><?= number_format($k['secciones']) ?></div><div class="l">Secciones</div></div>
    <div class="el-kpi"><div class="v"><?= number_format($k['ln']) ?></div><div class="l">Lista nominal</div></div>
    <div class="el-kpi"><div class="v"><?= number_format($k['part'],1) ?>%</div><div class="l">Participación</div></div>
    <div class="el-kpi"><div class="v"><?= number_format($k['val']) ?></div><div class="l">Votos válidos</div></div>
    <div class="el-kpi"><div class="v" id="k-sel">—</div><div class="l">Sección seleccionada</div></div>
  </div>

  <div class="el-controls">
    <span style="font-size:12px;font-weight:700;color:var(--qro-text-secondary)">Colorear por:</span>
    <button class="switchbtn on" data-metric="part">Participación</button>
    <button class="switchbtn" data-metric="gan">Partido ganador</button>
    <label style="margin-left:10px;display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:var(--qro-text-secondary);cursor:pointer">
      <input type="checkbox" id="t-limites" checked> Límites delegacionales
    </label>
  </div>
  <div class="el-legend" id="el-legend"></div>

  <div class="el-wrap">
    <div><div id="el-map"></div></div>
    <div class="el-side">
      <strong>Detalle de sección</strong>
      <div id="el-detail" style="margin-top:8px"><p class="text-secondary" style="font-size:13px">Haz clic en una sección para ver su resultado.</p></div>
    </div>
  </div>

<script>
const EL = <?= json_encode($el, JSON_UNESCAPED_UNICODE) ?>;
const LIMITES = <?= json_encode($limites, JSON_UNESCAPED_UNICODE) ?>;
const HASKEY = <?= $apiKey ? 'true':'false' ?>;
const $ = id => document.getElementById(id);
function esc(s){ return String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

/* Colores de partido (incluye coaliciones por partido dominante) */
const PARTY = {PAN:'#0a4bce',MORENA:'#a6212b',MC:'#f58220',PRI:'#0f9d58',PVEM:'#7ac142',PT:'#d5202a',PRD:'#f4c20d',QS:'#00a3a3'};
function partyColor(c){
  if(!c) return '#ccc'; if(PARTY[c]) return PARTY[c];
  if(c.indexOf('MORENA')>=0) return PARTY.MORENA;
  if(c.indexOf('PAN')===0) return PARTY.PAN;
  if(c.indexOf('PVEM')===0) return PARTY.PVEM;
  if(c.indexOf('PRI')===0) return PARTY.PRI;
  return '#8a8a8a';
}
/* Rampa de participación (secuencial azul) */
function partColor(v){ if(v==null) return '#eef2f6'; const t=Math.min(1,Math.max(0,(v-30)/50)); return `rgba(27,47,94,${0.15+0.85*t})`; }

let map, dataLayer=null, boundary=null, metric='part', showLim=true;
window.initElMap = function(){
  map=new google.maps.Map($('el-map'),{center:{lat:20.59,lng:-100.39},zoom:11,
    mapTypeControl:false,streetViewControl:false,fullscreenControl:true,
    styles:[{featureType:'poi',stylers:[{visibility:'off'}]}]});
  // secciones
  dataLayer=new google.maps.Data({map});
  dataLayer.addGeoJson(EL.geo);
  dataLayer.addListener('click',e=>detalle(e.feature.getProperty('s')));
  dataLayer.addListener('mouseover',e=>dataLayer.overrideStyle(e.feature,{strokeWeight:2,strokeColor:'#111'}));
  dataLayer.addListener('mouseout',()=>dataLayer.revertStyle());
  const b=new google.maps.LatLngBounds();
  dataLayer.forEach(f=>f.getGeometry().forEachLatLng(ll=>b.extend(ll)));
  if(!b.isEmpty()) map.fitBounds(b);
  // límites delegacionales (contexto, no clickeable)
  if(LIMITES.length){
    boundary=new google.maps.Data();
    boundary.addGeoJson({type:'FeatureCollection',features:LIMITES});
    boundary.setStyle({strokeColor:'#111',strokeWeight:2,strokeOpacity:.55,fillOpacity:0,clickable:false});
    boundary.setMap(showLim?map:null);
  }
  restyle();
};
function secColor(s){
  const d=EL.sec[s]; if(!d) return '#eef2f6';
  return metric==='part' ? partColor(d.part) : partyColor(d.gan);
}
function restyle(){
  if(dataLayer) dataLayer.setStyle(f=>({fillColor:secColor(f.getProperty('s')),
    fillOpacity:0.72, strokeColor:'#5b6b86', strokeWeight:0.4, strokeOpacity:0.6}));
  renderLegend();
}
function renderLegend(){
  if(metric==='part'){
    $('el-legend').innerHTML='Participación: '+
      '<span><i style="background:'+partColor(35)+'"></i>baja</span>'+
      '<span><i style="background:'+partColor(60)+'"></i>media</span>'+
      '<span><i style="background:'+partColor(80)+'"></i>alta</span>';
  } else {
    const keys=['PAN','MORENA','MC','PRI','PVEM','PT','PRD'];
    $('el-legend').innerHTML='Ganador: '+keys.map(k=>`<span><i style="background:${PARTY[k]}"></i>${k}</span>`).join('')+'<span><i style="background:#8a8a8a"></i>otros</span>';
  }
}
function detalle(s){
  const d=EL.sec[s];
  $('k-sel').textContent='#'+s;
  if(!d){ $('el-detail').innerHTML=`<h4 style="margin:0">Sección ${s}</h4><p class="text-secondary" style="font-size:12px">Sin resultados.</p>`; return; }
  $('el-detail').innerHTML=`<h4 style="margin:0 0 6px;color:#1b2f5e">Sección ${s}</h4>
    <div class="el-row"><span>Ganador</span><b style="color:${partyColor(d.gan)}">${esc(d.gan||'—')}${d.ganp!=null?' ('+d.ganp+'%)':''}</b></div>
    <div class="el-row"><span>Participación</span><b>${d.part!=null?d.part+'%':'—'}</b></div>
    <div class="el-row"><span>Lista nominal</span><b>${(+d.ln).toLocaleString()}</b></div>
    <div class="el-row"><span>Votos válidos</span><b>${(+d.val).toLocaleString()}</b></div>`;
}

document.querySelectorAll('[data-metric]').forEach(btn=>btn.addEventListener('click',()=>{
  metric=btn.dataset.metric;
  document.querySelectorAll('[data-metric]').forEach(x=>x.classList.toggle('on',x===btn));
  restyle();
}));
$('t-limites').addEventListener('change',()=>{ showLim=$('t-limites').checked; if(boundary) boundary.setMap(showLim?map:null); });

if(!HASKEY){ $('el-map').innerHTML='<div style="padding:20px;color:#991B1B">Google Maps API key no configurada.</div>'; }
</script>
<?php if ($apiKey): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($apiKey) ?>&callback=initElMap&loading=async&v=weekly"></script>
<?php endif; ?>
<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
