<?php
/**
 * Bloque · Mapa de procedencia — usuarios geocodificados desde su dirección.
 * Mapa de calor (deck.gl) + marcadores, con límites delegacionales y seccionales
 * de contexto, y una tabla de beneficiarios que se filtra al hacer clic en una
 * sección (exportable a CSV). PII (nombre) solo para editor/admin del módulo.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';     // guard: require_module('bloque')
require_once __DIR__ . '/lib.php';

$cfg    = bloq_config();
$apiKey = $cfg['google_maps_api_key'] ?? '';
$verPII = function_exists('puede_editar') && puede_editar('bloque');
$pts = []; $limites = []; $secFeatures = []; $gs = ['total'=>0,'geo'=>0]; $dbError = null;
try {
    $pdo     = bloq_pdo();
    $pts     = bloq_puntos_detalle($pdo, $verPII);
    $limites = bloq_limites($pdo);
    $secData = bloq_secciones($pdo);
    bloq_asigna_seccion($pts, $secData['idx']);
    // Al cliente solo van las secciones CON beneficiarios (relevancia + payload).
    $usadas = [];
    foreach ($pts as $p) if (($p['s'] ?? null) !== null) $usadas[$p['s']] = true;
    $secFeatures = array_values(array_filter($secData['features'], fn($f) => isset($usadas[$f['properties']['s'] ?? -1])));
    $gs      = bloq_geo_stats($pdo);
} catch (Throwable $e) { $dbError = $e->getMessage(); }
$pct = $gs['total'] > 0 ? round($gs['geo'] / $gs['total'] * 100) : 0;
?><?php
$ktTitle  = 'Bloque · Mapa de procedencia';
$ktActive = 'bloque';
$ktFluid = true;
require __DIR__ . '/../../views/layout/kt_top.php';
?>
  <script src="https://unpkg.com/deck.gl@8.9.35/dist.min.js"></script>
  <style>
    .page-head h1{color:#005ab2;font-weight:700}
    .bl-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:14px}
    .bl-kpi{background:#fff;border:1px solid var(--qro-border);border-radius:12px;padding:13px 15px}
    .bl-kpi .v{font-size:22px;font-weight:800;color:#005ab2;line-height:1.1}
    .bl-kpi .l{font-size:12px;color:var(--qro-text-secondary);font-weight:600;margin-top:2px}
    #bl-map{height:clamp(520px,calc(100vh - 250px),880px);border-radius:12px;border:1px solid var(--qro-border);overflow:hidden}
    .bl-ctrl{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
    .bl-ctrl label{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--qro-text-secondary);cursor:pointer}
    /* Tabla de beneficiarios */
    .bl-tablecard{margin-top:16px;background:#fff;border:1px solid var(--qro-border);border-radius:12px;overflow:hidden}
    .bl-tablehead{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid var(--qro-border)}
    .bl-tablehead strong{color:#005ab2}
    .bl-tactions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .bl-chip{display:inline-flex;align-items:center;gap:6px;background:#e8f1fb;color:#005ab2;font-weight:700;font-size:12px;padding:4px 10px;border-radius:999px}
    .bl-btn{border:1px solid var(--qro-border);background:#fff;border-radius:8px;padding:7px 12px;font:inherit;font-size:13px;font-weight:600;color:var(--qro-text-secondary);cursor:pointer}
    .bl-btn.primary{background:#005ab2;color:#fff;border-color:#005ab2}
    .bl-tablewrap{max-height:440px;overflow:auto}
    table.bl-table{width:100%;border-collapse:collapse;font-size:13px}
    table.bl-table th{position:sticky;top:0;background:#eef4fb;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:var(--qro-text-secondary);padding:9px 14px;white-space:nowrap}
    table.bl-table td{padding:8px 14px;border-bottom:1px solid #eef0f2;color:var(--qro-text-primary);white-space:nowrap}
    table.bl-table td.num{text-align:right;font-variant-numeric:tabular-nums}
    table.bl-table tr:hover td{background:#f5f9fe}
    .bl-muted{color:var(--qro-text-muted);font-weight:400}
  </style>

  <div class="page-head"><h1>Mapa de procedencia</h1>
    <p class="text-secondary">Dónde viven los usuarios de Bloque (geocodificado desde su dirección).</p></div>

  <?php if ($dbError): ?><div class="alert alert-danger">Error: <?= htmlspecialchars($dbError) ?></div><?php endif; ?>
  <?php if (!$apiKey): ?><div class="alert alert-danger">Falta <code>GOOGLE_MAPS_API_KEY</code>.</div><?php endif; ?>

  <div class="bl-kpis">
    <div class="bl-kpi"><div class="v"><?= number_format($gs['geo']) ?> <span style="font-size:13px;color:var(--qro-text-muted)">/ <?= number_format($gs['total']) ?></span></div><div class="l">Usuarios ubicados (<?= $pct ?>%)</div></div>
    <div class="bl-kpi"><div class="v" id="k-vista"><?= number_format(count($pts)) ?></div><div class="l">En el mapa</div></div>
  </div>

  <div class="bl-ctrl">
    <label><input type="checkbox" id="t-heat" checked> 🔥 Mapa de calor</label>
    <label><input type="checkbox" id="t-pts"> 📍 Puntos</label>
    <label><input type="checkbox" id="t-lim" checked> 🗺 Límites delegacionales</label>
    <label><input type="checkbox" id="t-sec"> 🧭 Límites seccionales</label>
    <span class="bl-muted" style="font-size:12px">Con los límites seccionales activos, haz clic en una sección para filtrar la tabla.</span>
  </div>

  <div id="bl-map"></div>

  <!-- ============ TABLA DE BENEFICIARIOS ============ -->
  <div class="bl-tablecard">
    <div class="bl-tablehead">
      <div><strong>Beneficiarios</strong> <span id="bl-tcount" class="bl-muted"></span></div>
      <div class="bl-tactions">
        <span id="bl-tfilter" class="bl-chip" style="display:none"></span>
        <button id="bl-clear" class="bl-btn" style="display:none">Ver todos</button>
        <button id="bl-export" class="bl-btn primary">⬇ Exportar CSV</button>
      </div>
    </div>
    <div class="bl-tablewrap">
      <table class="bl-table" id="bl-table">
        <thead><tr>
          <?php if ($verPII): ?><th>Nombre</th><?php endif; ?>
          <th>Delegación</th><th>Colonia</th><th>Empresa</th><th>Sección</th>
        </tr></thead>
        <tbody id="bl-tbody"></tbody>
      </table>
    </div>
  </div>

<script>
const PTS = <?= json_encode($pts, JSON_UNESCAPED_UNICODE) ?>;
const LIMITES = <?= json_encode($limites, JSON_UNESCAPED_UNICODE) ?>;
const SECS = <?= json_encode($secFeatures, JSON_UNESCAPED_UNICODE) ?>;
const HASKEY = <?= $apiKey ? 'true':'false' ?>;
const VERPII = <?= $verPII ? 'true':'false' ?>;
const $ = id => document.getElementById(id);
function esc(s){ return String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

let map, info, deckOverlay=null, boundary=null, secBoundary=null, blabels=[], mkPts=[];
let showHeat=true, showPts=false, showLim=true, showSec=false, filterSec=null;
const RAMP=[[224,235,247],[122,170,220],[49,110,180],[0,90,178],[10,45,110]];

window.initBlMap = function(){
  map=new google.maps.Map($('bl-map'),{center:{lat:20.59,lng:-100.39},zoom:11,
    mapTypeControl:false,streetViewControl:false,fullscreenControl:true,
    styles:[{featureType:'poi',stylers:[{visibility:'off'}]}]});
  info=new google.maps.InfoWindow();

  if(LIMITES.length){
    boundary=new google.maps.Data();
    boundary.addGeoJson({type:'FeatureCollection',features:LIMITES});
    boundary.setStyle({strokeColor:'#111',strokeWeight:1.6,strokeOpacity:.55,fillOpacity:0,clickable:false});
    LIMITES.forEach(ft=>{ let c=ft.geometry.type==='Polygon'?ft.geometry.coordinates[0]:ft.geometry.coordinates[0][0];
      if(!c||!c.length) return; let sx=0,sy=0; c.forEach(p=>{sx+=p[0];sy+=p[1];});
      blabels.push(new google.maps.Marker({position:{lat:sy/c.length,lng:sx/c.length},clickable:false,
        icon:{path:google.maps.SymbolPath.CIRCLE,scale:0,strokeOpacity:0,fillOpacity:0},
        label:{text:ft.properties.d,color:'#333',fontSize:'11px',fontWeight:'700'}})); });
  }

  // Capa de secciones (oculta hasta activar). Clic = filtrar la tabla.
  if(SECS.length){
    secBoundary=new google.maps.Data();
    secBoundary.addGeoJson({type:'FeatureCollection',features:SECS});
    secBoundary.setStyle(styleSec);
    secBoundary.addListener('click', e=>{ setFilter(e.feature.getProperty('s')); });
  }

  mkPts = PTS.map(p=>new google.maps.Marker({position:{lat:p.lat,lng:p.lng},
    icon:{path:google.maps.SymbolPath.CIRCLE,scale:3.5,fillColor:'#005ab2',fillOpacity:.7,strokeColor:'#fff',strokeWeight:.8}}));
  renderTable();
  apply();
};

function styleSec(feature){
  const s=feature.getProperty('s'); const on=(s===filterSec);
  return {strokeColor: on?'#005ab2':'#8aa0c8', strokeWeight: on?2.6:0.7, strokeOpacity: on?0.95:0.5,
          fillColor:'#005ab2', fillOpacity: on?0.14:0.0, clickable:true, zIndex: on?3:1};
}

function apply(){
  const layers=[];
  if(showHeat && PTS.length && typeof deck!=='undefined' && deck.GoogleMapsOverlay){
    layers.push(new deck.HeatmapLayer({id:'bl-heat',data:PTS,getPosition:p=>[p.lng,p.lat],getWeight:1,radiusPixels:28,intensity:1,threshold:0.05,colorRange:RAMP}));
  }
  if(!deckOverlay){ deckOverlay=new deck.GoogleMapsOverlay({layers}); deckOverlay.setMap(map); } else deckOverlay.setProps({layers});
  mkPts.forEach(m=>m.setMap(showPts?map:null));
  if(boundary) boundary.setMap(showLim?map:null);
  blabels.forEach(l=>l.setMap(showLim?map:null));
  if(secBoundary) secBoundary.setMap(showSec?map:null);
}

// ---------- tabla ----------
function setFilter(s){
  filterSec = (filterSec===s)?null:s;
  if(secBoundary) secBoundary.setStyle(styleSec);
  renderTable();
}
function currentRows(){ return filterSec==null ? PTS : PTS.filter(p=>p.s===filterSec); }
function renderTable(){
  const rows = currentRows();
  $('bl-tcount').textContent = '· '+rows.length.toLocaleString('es-MX')+(rows.length===1?' beneficiario':' beneficiarios');
  const f = filterSec!=null;
  $('bl-tfilter').style.display = f?'':'none'; $('bl-tfilter').textContent = f?('Sección '+filterSec):'';
  $('bl-clear').style.display = f?'':'none';
  const LIMIT=1500;
  const cols = VERPII?5:4;
  let html = rows.slice(0,LIMIT).map(p=>{
    let tds='';
    if(VERPII) tds+='<td>'+esc(p.nombre||'—')+'</td>';
    tds+='<td>'+esc(p.d||'')+'</td><td>'+esc(p.col||'')+'</td><td>'+esc(p.emp||'')+'</td><td class="num">'+(p.s??'—')+'</td>';
    return '<tr>'+tds+'</tr>';
  }).join('');
  if(!rows.length) html='<tr><td colspan="'+cols+'" class="bl-muted" style="padding:16px">Sin beneficiarios en esta sección.</td></tr>';
  else if(rows.length>LIMIT) html+='<tr><td colspan="'+cols+'" class="bl-muted" style="padding:10px 14px">… '+(rows.length-LIMIT).toLocaleString('es-MX')+' filas más. Exporta el CSV para verlas todas.</td></tr>';
  $('bl-tbody').innerHTML = html;
}
function csvCell(v){ v=String(v==null?'':v); return /[",\n]/.test(v)?'"'+v.replace(/"/g,'""')+'"':v; }
function exportCSV(){
  const rows=currentRows();
  const head=(VERPII?['Nombre']:[]).concat(['Delegación','Colonia','Empresa','Sección']);
  const lines=[head.join(',')];
  rows.forEach(p=>{ const vals=(VERPII?[p.nombre||'']:[]).concat([p.d||'',p.col||'',p.emp||'',(p.s==null?'':p.s)]);
    lines.push(vals.map(csvCell).join(',')); });
  const blob=new Blob(['﻿'+lines.join('\r\n')],{type:'text/csv;charset=utf-8'});
  const a=document.createElement('a'); a.href=URL.createObjectURL(blob);
  a.download='beneficiarios_bloque'+(filterSec!=null?'_seccion_'+filterSec:'')+'.csv';
  document.body.appendChild(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(a.href),500);
}

$('t-heat').addEventListener('change',()=>{showHeat=$('t-heat').checked;apply();});
$('t-pts').addEventListener('change',()=>{showPts=$('t-pts').checked;apply();});
$('t-lim').addEventListener('change',()=>{showLim=$('t-lim').checked;apply();});
$('t-sec').addEventListener('change',()=>{showSec=$('t-sec').checked;apply();});
$('bl-clear').addEventListener('click',()=>setFilter(filterSec));   // toggle off
$('bl-export').addEventListener('click',exportCSV);
if(!HASKEY){ $('bl-map').innerHTML='<div style="padding:20px;color:#991B1B">Google Maps API key no configurada.</div>'; renderTable(); }
</script>
<?php if ($apiKey): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($apiKey) ?>&callback=initBlMap&loading=async&v=weekly"></script>
<?php endif; ?>
<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
