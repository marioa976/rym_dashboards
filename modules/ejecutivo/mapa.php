<?php
/**
 * Ejecutivo · Mapa por capas (Reporte 2).
 * Un mapa único donde se prenden/apagan capas de los distintos módulos:
 *  - Límites delegacionales (oficiales)
 *  - Obras (marcadores, color = estatus)
 *  - Áreas verdes (marcadores)
 *  - Tickets Zendesk (mapa de calor)  ← carga bajo demanda (data.php)
 *  - Padrón DIF (mapa de calor)        ← carga bajo demanda (data.php)
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';     // guard: require_module('ejecutivo')
require_once __DIR__ . '/lib.php';

$cfg    = ej_config();
$apiKey = $cfg['google_maps_api_key'] ?? '';

$obras = []; $areas = []; $limites = []; $estaciones = []; $dbError = null;
try {
    $pdo = ej_pdo();
    $obras   = ej_capa_obras($pdo);   // ligero (97)
    $areas   = ej_capa_areas($pdo);   // ligero (404)
    $limites = ej_limites($pdo);
    $qb = ej_qrobici($pdo);           // remoto cacheado
    if ($qb) $estaciones = $qb['estaciones'];
} catch (Throwable $e) { $dbError = $e->getMessage(); }
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ejecutivo · Mapa por capas</title>
  <link rel="stylesheet" href="../../assets/css/qro.css">
  <script src="https://unpkg.com/deck.gl@8.9.35/dist.min.js"></script>
  <style>
    .ej-map-wrap{display:grid;grid-template-columns:280px 1fr;gap:16px}
    @media(max-width:900px){.ej-map-wrap{grid-template-columns:1fr}}
    #ej-map{height:640px;border-radius:12px;border:1px solid var(--qro-border);overflow:hidden}
    .ej-panel{background:#fff;border:1px solid var(--qro-border);border-radius:12px;padding:14px 16px;align-self:start}
    .ej-panel h3{margin:0 0 6px;font-size:14px}
    .ej-layer{display:flex;align-items:flex-start;gap:9px;padding:10px 0;border-bottom:1px dashed #eef0f2}
    .ej-layer:last-child{border-bottom:0}
    .ej-layer input{margin-top:3px}
    .ej-layer .lbl{font-size:13px;font-weight:700;color:var(--qro-text-primary);display:flex;align-items:center;gap:7px}
    .ej-layer .sub{font-size:11px;color:var(--qro-text-secondary);margin-top:1px}
    .ej-sw{width:12px;height:12px;border-radius:3px;display:inline-block}
    .ej-load{font-size:11px;color:#b26b00}
  </style>
</head>
<body>
<?php $portalModulo = 'Ejecutivo'; $navActive = 'mapa'; include __DIR__ . '/_nav.php'; ?>

<main class="content" style="padding:28px 32px">
  <div class="page-head"><h1>Mapa por capas</h1><p class="text-secondary">Superpone la geografía de los distintos módulos. Prende y apaga capas para cruzar territorio.</p></div>

  <?php if ($dbError): ?><div class="alert alert-danger">Error: <?= htmlspecialchars($dbError) ?></div><?php endif; ?>
  <?php if (!$apiKey): ?><div class="alert alert-danger">Falta <code>GOOGLE_MAPS_API_KEY</code>.</div><?php endif; ?>

  <div class="ej-map-wrap">
    <div class="ej-panel">
      <h3>Capas</h3>
      <label class="ej-layer">
        <input type="checkbox" id="l-limites" checked>
        <span><span class="lbl">🗺 Límites delegacionales</span><span class="sub">Fronteras oficiales de las 7 delegaciones</span></span>
      </label>
      <label class="ej-layer">
        <input type="checkbox" id="l-obras" checked>
        <span><span class="lbl"><span class="ej-sw" style="background:#c85a2b"></span>🏗 Obras</span><span class="sub" id="s-obras">marcadores · color = estatus</span></span>
      </label>
      <label class="ej-layer">
        <input type="checkbox" id="l-areas" checked>
        <span><span class="lbl"><span class="ej-sw" style="background:#2e9e5b"></span>🌳 Áreas verdes</span><span class="sub" id="s-areas">marcadores</span></span>
      </label>
      <label class="ej-layer">
        <input type="checkbox" id="l-tickets">
        <span><span class="lbl"><span class="ej-sw" style="background:#005ab2"></span>📮 Tickets Zendesk</span><span class="sub" id="s-tickets">mapa de calor · carga al prender</span></span>
      </label>
      <label class="ej-layer">
        <input type="checkbox" id="l-dif">
        <span><span class="lbl"><span class="ej-sw" style="background:#8e44ad"></span>🤝 Padrón DIF</span><span class="sub" id="s-dif">mapa de calor · carga al prender</span></span>
      </label>
      <label class="ej-layer">
        <input type="checkbox" id="l-qrobici">
        <span><span class="lbl"><span class="ej-sw" style="background:#e0872b"></span>🚲 Estaciones Qrobici</span><span class="sub"><?= $estaciones ? count($estaciones).' estaciones · tamaño = viajes' : 'sin conexión remota' ?></span></span>
      </label>
      <label class="ej-layer">
        <input type="checkbox" id="l-qrcalor">
        <span><span class="lbl"><span class="ej-sw" style="background:#e0872b"></span>🔥 Calor de viajes Qrobici</span><span class="sub" id="s-qrcalor">rutas reales · carga al prender</span></span>
      </label>
      <label class="ej-layer">
        <input type="checkbox" id="l-waze">
        <span><span class="lbl">🚦 Waze (tiempo real)</span><span class="sub" id="s-waze">alertas y embotellamientos · carga al prender</span></span>
      </label>
      <div id="waze-subs" style="display:none;padding:2px 0 6px 26px">
        <label class="ej-layer" style="padding:5px 0;border-bottom:0"><input type="checkbox" id="wz-hazard" checked>
          <span class="lbl" style="font-size:12px"><span class="ej-sw" style="background:#f9a825"></span>🕳 Peligros / baches</span></label>
        <label class="ej-layer" style="padding:5px 0;border-bottom:0"><input type="checkbox" id="wz-closed" checked>
          <span class="lbl" style="font-size:12px"><span class="ej-sw" style="background:#b71c1c"></span>🚧 Cierres de vía</span></label>
        <label class="ej-layer" style="padding:5px 0;border-bottom:0"><input type="checkbox" id="wz-accident" checked>
          <span class="lbl" style="font-size:12px"><span class="ej-sw" style="background:#e53935"></span>🚗 Accidentes</span></label>
        <label class="ej-layer" style="padding:5px 0;border-bottom:0"><input type="checkbox" id="wz-jam" checked>
          <span class="lbl" style="font-size:12px"><span class="ej-sw" style="background:#fb8c00"></span>🐌 Tráfico (reportes)</span></label>
        <label class="ej-layer" style="padding:5px 0;border-bottom:0"><input type="checkbox" id="wz-jams" checked>
          <span class="lbl" style="font-size:12px"><span class="ej-sw" style="background:linear-gradient(90deg,#fdd835,#c62828)"></span>🚦 Embotellamientos</span></label>
      </div>
    </div>
    <div><div id="ej-map"></div></div>
  </div>
</main>

<script>
const OBRAS = <?= json_encode($obras, JSON_UNESCAPED_UNICODE) ?>;
const AREAS = <?= json_encode($areas, JSON_UNESCAPED_UNICODE) ?>;
const ESTACIONES = <?= json_encode($estaciones, JSON_UNESCAPED_UNICODE) ?>;
const LIMITES = <?= json_encode($limites, JSON_UNESCAPED_UNICODE) ?>;
const HASKEY = <?= $apiKey ? 'true':'false' ?>;
const $ = id => document.getElementById(id);
function esc(s){ return String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
function fmtMoney(v){ if(v==null) return '—'; if(v>=1e6) return '$'+(v/1e6).toFixed(1)+' M'; return '$'+Math.round(v).toLocaleString(); }
const EST_COLOR = {'TERMINADA':'#2e7d32','EN EJECUCIÓN':'#1565c0','EN LICITACIÓN':'#f9a825','EN SUSPENSIÓN':'#c62828'};
function estColor(s){ return EST_COLOR[s]||'#757575'; }
const PALETA=['#2e9e5b','#005ab2','#e0872b','#8e44ad','#159c9c','#c0392b','#7a8b1f'];
const DELEGS=[...new Set(LIMITES.map(f=>f.properties.d))].sort();
const DCOLOR={}; DELEGS.forEach((d,i)=>DCOLOR[d]=PALETA[i%PALETA.length]);

let map, info, boundary=null, blabels=[], mkObras=[], mkAreas=[], mkEst=[];
let deckOverlay=null;
const heat = { tickets:{data:null,loading:false}, dif:{data:null,loading:false}, qrcalor:{data:null,loading:false} };
const on = { limites:true, obras:true, areas:true, tickets:false, dif:false, qrobici:false, qrcalor:false,
             waze:false, wz_hazard:true, wz_closed:true, wz_accident:true, wz_jam:true, wz_jams:true };
const maxViajes = Math.max(1, ...ESTACIONES.map(e=>e.v||0));

/* Waze (marcadores por categoría + líneas de embotellamiento) */
let wazeData=null, wazeLoading=false;
const wazeMk={hazard:[],closed:[],accident:[],jam:[]}, wazeJams=[];
const WZ_COLOR={hazard:'#f9a825',closed:'#b71c1c',accident:'#e53935',jam:'#fb8c00'};
const WZ_LABEL={hazard:'🕳 Peligro',closed:'🚧 Cierre',accident:'🚗 Accidente',jam:'🐌 Tráfico'};

window.initEjMap = function(){
  map=new google.maps.Map($('ej-map'),{center:{lat:20.59,lng:-100.39},zoom:11,
    mapTypeControl:false,streetViewControl:false,fullscreenControl:true,
    styles:[{featureType:'poi',stylers:[{visibility:'off'}]}]});
  info=new google.maps.InfoWindow();
  buildBoundaries(); buildObras(); buildAreas(); buildEstaciones();
  applyAll();
};

/* Límites */
function ringCentroid(g){ let c=g.type==='Polygon'?g.coordinates[0]:g.type==='MultiPolygon'?g.coordinates[0][0]:null;
  if(!c||!c.length) return null; let sx=0,sy=0; c.forEach(p=>{sx+=p[0];sy+=p[1];}); return {lat:sy/c.length,lng:sx/c.length}; }
function buildBoundaries(){
  if(!LIMITES.length) return;
  boundary=new google.maps.Data();
  boundary.addGeoJson({type:'FeatureCollection',features:LIMITES});
  boundary.setStyle(f=>{const c=DCOLOR[f.getProperty('d')]||'#888';
    return {strokeColor:c,strokeWeight:1.8,strokeOpacity:.85,fillColor:c,fillOpacity:.03,clickable:false};});
  LIMITES.forEach(ft=>{const c=ringCentroid(ft.geometry); if(!c) return;
    blabels.push(new google.maps.Marker({position:c,clickable:false,
      icon:{path:google.maps.SymbolPath.CIRCLE,scale:0,strokeOpacity:0,fillOpacity:0},
      label:{text:ft.properties.d,color:DCOLOR[ft.properties.d]||'#333',fontSize:'11px',fontWeight:'700'}}));});
}
/* Obras */
function buildObras(){
  mkObras = OBRAS.map(o=>{
    const mk=new google.maps.Marker({position:{lat:o.lat,lng:o.lng},title:o.n,
      icon:{path:google.maps.SymbolPath.CIRCLE,scale:6,fillColor:estColor(o.s),fillOpacity:.85,strokeColor:'#fff',strokeWeight:1.2}});
    mk.addListener('click',()=>{ info.setContent(
      `<div style="font-family:Montserrat,Arial;max-width:230px"><b style="color:#7a3315">${esc(o.n)}</b>
       <div style="font-size:12px;margin-top:3px">${esc(o.s||'')} · 💰 ${fmtMoney(o.e)}</div></div>`); info.open(map,mk); });
    return mk;
  });
}
/* Áreas */
function buildAreas(){
  mkAreas = AREAS.map(a=>{
    const mk=new google.maps.Marker({position:{lat:a.lat,lng:a.lng},title:a.n,
      icon:{path:google.maps.SymbolPath.CIRCLE,scale:4.5,fillColor:'#2e9e5b',fillOpacity:.9,strokeColor:'#fff',strokeWeight:1}});
    mk.addListener('click',()=>{ info.setContent(
      `<div style="font-family:Montserrat,Arial;max-width:220px"><b style="color:#155d34">${esc(a.n)}</b>
       <div style="font-size:12px;margin-top:2px">🌳 ${esc(a.d||'')}</div></div>`); info.open(map,mk); });
    return mk;
  });
}

/* Estaciones Qrobici (marcadores, tamaño = viajes) */
function buildEstaciones(){
  mkEst = ESTACIONES.map(e=>{
    const r = 5 + Math.sqrt((e.v||0)/maxViajes)*14;
    const mk=new google.maps.Marker({position:{lat:e.lat,lng:e.lng},title:e.n,
      icon:{path:google.maps.SymbolPath.CIRCLE,scale:r,fillColor:'#e0872b',fillOpacity:.8,strokeColor:'#7a4a12',strokeWeight:1.2}});
    mk.addListener('click',()=>{ info.setContent(
      `<div style="font-family:Montserrat,Arial;max-width:220px"><b style="color:#7a4a12">🚲 ${esc(e.n)}</b>
       <div style="font-size:12px;margin-top:3px">${(e.v||0).toLocaleString()} viajes origen</div>
       <div style="font-size:11px;color:#777">${esc(e.d||'')}</div></div>`); info.open(map,mk); });
    return mk;
  });
}

/* deck.gl heatmaps (tickets + dif) */
function heatLayer(id, pts, ramp){
  return new deck.HeatmapLayer({id, data:pts, getPosition:p=>[p[1],p[0]], getWeight:1,
    radiusPixels:28, intensity:1, threshold:0.05, colorRange:ramp});
}
const RAMP_TICKETS=[[224,235,247],[122,170,220],[49,110,180],[0,90,178],[10,45,110]];
const RAMP_DIF=[[237,231,246],[190,160,220],[142,68,173],[110,44,140],[74,20,100]];
const RAMP_QROBICI=[[255,243,224],[255,204,128],[255,152,0],[230,110,0],[150,60,0]];
function rebuildDeck(){
  const layers=[];
  if(on.tickets && heat.tickets.data) layers.push(heatLayer('h-tickets',heat.tickets.data,RAMP_TICKETS));
  if(on.dif && heat.dif.data)         layers.push(heatLayer('h-dif',heat.dif.data,RAMP_DIF));
  if(on.qrcalor && heat.qrcalor.data) layers.push(heatLayer('h-qrcalor',heat.qrcalor.data,RAMP_QROBICI));
  if(!deckOverlay){ deckOverlay=new deck.GoogleMapsOverlay({layers}); deckOverlay.setMap(map); }
  else deckOverlay.setProps({layers});
}
/* name = clave de estado/DOM ; layerParam = capa en data.php (por defecto = name) */
function loadHeat(name, layerParam){
  layerParam = layerParam || name;
  if(heat[name].data || heat[name].loading) { rebuildDeck(); return; }
  heat[name].loading=true;
  $('s-'+name).innerHTML='<span class="ej-load">cargando…</span>';
  fetch('data.php?layer='+layerParam).then(r=>r.json()).then(j=>{
    heat[name].data = j.ok ? j.points : [];
    $('s-'+name).textContent = (j.n||0).toLocaleString()+' puntos (muestra)';
    heat[name].loading=false; rebuildDeck();
  }).catch(()=>{ heat[name].loading=false; $('s-'+name).textContent='error al cargar'; });
}

/* ---- Waze ---- */
function jamColor(l){ return l>=5?'#7f0000':l>=4?'#c62828':l>=3?'#ef6c00':l>=2?'#f9a825':'#fdd835'; }
function ensureWaze(){
  if(wazeData || wazeLoading){ applyWaze(); return; }
  wazeLoading=true; $('s-waze').innerHTML='<span class="ej-load">cargando…</span>';
  fetch('data.php?layer=waze').then(r=>r.json()).then(j=>{
    wazeLoading=false; wazeData=j;
    if(!j.ok){ $('s-waze').textContent='no disponible'; return; }
    buildWaze(j);
    $('s-waze').textContent=(j.alerts.length+j.jams.length)+' elementos · '+(j.source||'live');
    applyWaze();
  }).catch(()=>{ wazeLoading=false; $('s-waze').textContent='error al cargar'; });
}
function buildWaze(j){
  j.alerts.forEach(a=>{
    const arr=wazeMk[a.cat]; if(!arr) return;
    const mk=new google.maps.Marker({position:{lat:a.lat,lng:a.lng},
      icon:{path:google.maps.SymbolPath.CIRCLE,scale:5,fillColor:WZ_COLOR[a.cat],fillOpacity:.9,strokeColor:'#fff',strokeWeight:1}});
    mk.addListener('click',()=>{ info.setContent(
      `<div style="font-family:Montserrat,Arial;max-width:220px"><b>${WZ_LABEL[a.cat]||'Waze'}</b>
       <div style="font-size:12px;margin-top:2px">${esc(a.street||'—')}</div>
       <div style="font-size:11px;color:#777">${esc(a.sub||a.type||'')}</div></div>`); info.open(map,mk); });
    arr.push(mk);
  });
  j.jams.forEach(jm=>{
    const path=jm.pts.map(p=>({lat:p[0],lng:p[1]}));
    const pl=new google.maps.Polyline({path,strokeColor:jamColor(jm.level),strokeOpacity:.85,strokeWeight:5});
    pl.addListener('click',()=>{ info.setContent(
      `<div style="font-family:Montserrat,Arial;max-width:220px"><b>🚦 Embotellamiento</b>
       <div style="font-size:12px;margin-top:2px">${esc(jm.street||'—')}</div>
       <div style="font-size:11px;color:#777">${jm.speed} km/h · nivel ${jm.level}</div></div>`);
      info.setPosition({lat:path[0].lat,lng:path[0].lng}); info.open(map); });
    wazeJams.push(pl);
  });
}
function applyWaze(){
  const parent=on.waze;
  Object.keys(wazeMk).forEach(cat=>{ const show=parent && on['wz_'+cat]; wazeMk[cat].forEach(m=>m.setMap(show?map:null)); });
  wazeJams.forEach(l=>l.setMap(parent && on.wz_jams ? map : null));
  $('waze-subs').style.display = parent ? 'block' : 'none';
}

/* Aplicar visibilidad */
function applyAll(){
  if(boundary) boundary.setMap(on.limites?map:null);
  blabels.forEach(l=>l.setMap(on.limites?map:null));
  mkObras.forEach(m=>m.setMap(on.obras?map:null));
  mkAreas.forEach(m=>m.setMap(on.areas?map:null));
  mkEst.forEach(m=>m.setMap(on.qrobici?map:null));
  if(on.tickets) loadHeat('tickets');
  if(on.dif) loadHeat('dif');
  if(on.qrcalor) loadHeat('qrcalor','qrobici_calor');
  applyWaze();
  rebuildDeck();
}

[['l-limites','limites'],['l-obras','obras'],['l-areas','areas'],['l-tickets','tickets'],['l-dif','dif'],['l-qrobici','qrobici'],['l-qrcalor','qrcalor']]
  .forEach(([id,key])=>$(id).addEventListener('change',()=>{ on[key]=$(id).checked; applyAll(); }));

// Waze: padre + subcategorías
$('l-waze').addEventListener('change',()=>{ on.waze=$('l-waze').checked; if(on.waze) ensureWaze(); else applyWaze(); });
[['wz-hazard','wz_hazard'],['wz-closed','wz_closed'],['wz-accident','wz_accident'],['wz-jam','wz_jam'],['wz-jams','wz_jams']]
  .forEach(([id,key])=>$(id).addEventListener('change',()=>{ on[key]=$(id).checked; applyWaze(); }));

if(!HASKEY){ $('ej-map').innerHTML='<div style="padding:20px;color:#991B1B">Google Maps API key no configurada.</div>'; }
</script>
<?php if ($apiKey): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($apiKey) ?>&callback=initEjMap&loading=async&v=weekly"></script>
<?php endif; ?>
</body>
</html>
