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

/* ---- Pines con estilo (gota + icono FontAwesome embebido, sin dependencias) ---- */
const FA = {
  obras: {w:576, h:512, d:"M256 32c-17.7 0-32 14.3-32 32v2.3 99.6c0 5.6-4.5 10.1-10.1 10.1c-3.6 0-7-1.9-8.8-5.1L157.1 87C83 123.5 32 199.8 32 288v64H544l0-66.4c-.9-87.2-51.7-162.4-125.1-198.6l-48 83.9c-1.8 3.2-5.2 5.1-8.8 5.1c-5.6 0-10.1-4.5-10.1-10.1V66.3 64c0-17.7-14.3-32-32-32H256zM16.6 384C7.4 384 0 391.4 0 400.6c0 4.7 2 9.2 5.8 11.9C27.5 428.4 111.8 480 288 480s260.5-51.6 282.2-67.5c3.8-2.8 5.8-7.2 5.8-11.9c0-9.2-7.4-16.6-16.6-16.6H16.6z"},
  areas: {w:448, h:512, d:"M210.6 5.9L62 169.4c-3.9 4.2-6 9.8-6 15.5C56 197.7 66.3 208 79.1 208H104L30.6 281.4c-4.2 4.2-6.6 10-6.6 16C24 309.9 34.1 320 46.6 320H80L5.4 409.5C1.9 413.7 0 419 0 424.5c0 13 10.5 23.5 23.5 23.5H192v32c0 17.7 14.3 32 32 32s32-14.3 32-32V448H424.5c13 0 23.5-10.5 23.5-23.5c0-5.5-1.9-10.8-5.4-15L368 320h33.4c12.5 0 22.6-10.1 22.6-22.6c0-6-2.4-11.8-6.6-16L344 208h24.9c12.7 0 23.1-10.3 23.1-23.1c0-5.7-2.1-11.3-6-15.5L237.4 5.9C234 2.1 229.1 0 224 0s-10 2.1-13.4 5.9z"},
  bici: {w:640, h:512, d:"M312 32c-13.3 0-24 10.7-24 24s10.7 24 24 24h25.7l34.6 64H222.9l-27.4-38C191 99.7 183.7 96 176 96H120c-13.3 0-24 10.7-24 24s10.7 24 24 24h43.7l22.1 30.7-26.6 53.1c-10-2.5-20.5-3.8-31.2-3.8C57.3 224 0 281.3 0 352s57.3 128 128 128c65.3 0 119.1-48.9 127-112h49c8.5 0 16.3-4.5 20.7-11.8l84.8-143.5 21.7 40.1C402.4 276.3 384 312 384 352c0 70.7 57.3 128 128 128s128-57.3 128-128s-57.3-128-128-128c-13.5 0-26.5 2.1-38.7 6L375.4 48.8C369.8 38.4 359 32 347.2 32H312zM458.6 303.7l32.3 59.7c6.3 11.7 20.9 16 32.5 9.7s16-20.9 9.7-32.5l-32.3-59.7c3.6-.6 7.4-.9 11.2-.9c39.8 0 72 32.2 72 72s-32.2 72-72 72s-72-32.2-72-72c0-18.6 7-35.5 18.6-48.3zM133.2 368h65c-7.3 32.1-36 56-70.2 56c-39.8 0-72-32.2-72-72s32.2-72 72-72c1.7 0 3.4 .1 5.1 .2l-24.2 48.5c-9 18.1 4.1 39.4 24.3 39.4zm33.7-48l50.7-101.3 72.9 101.2-.1 .1H166.8zm90.6-128H365.9L317 274.8 257.4 192z"},
  hazard: {w:512, h:512, d:"M256 32c14.2 0 27.3 7.5 34.5 19.8l216 368c7.3 12.4 7.3 27.7 .2 40.1S486.3 480 472 480H40c-14.3 0-27.6-7.7-34.7-20.1s-7-27.8 .2-40.1l216-368C228.7 39.5 241.8 32 256 32zm0 128c-13.3 0-24 10.7-24 24V296c0 13.3 10.7 24 24 24s24-10.7 24-24V184c0-13.3-10.7-24-24-24zm32 224a32 32 0 1 0 -64 0 32 32 0 1 0 64 0z"},
  closed: {w:640, h:512, d:"M32 32C14.3 32 0 46.3 0 64V448c0 17.7 14.3 32 32 32s32-14.3 32-32V266.3L149.2 96H64V64c0-17.7-14.3-32-32-32zM405.2 96H330.8l-5.4 10.7L234.8 288h74.3l5.4-10.7L405.2 96zM362.8 288h74.3l5.4-10.7L533.2 96H458.8l-5.4 10.7L362.8 288zM202.8 96l-5.4 10.7L106.8 288h74.3l5.4-10.7L277.2 96H202.8zm288 192H576V448c0 17.7 14.3 32 32 32s32-14.3 32-32V64c0-17.7-14.3-32-32-32s-32 14.3-32 32v53.7L490.8 288z"},
  accident: {w:640, h:512, d:"M176 8c-6.6 0-12.4 4-14.9 10.1l-29.4 74L55.6 68.9c-6.3-1.9-13.1 .2-17.2 5.3s-4.6 12.2-1.4 17.9l39.5 69.1L10.9 206.4c-5.4 3.7-8 10.3-6.5 16.7s6.7 11.2 13.1 12.2l78.7 12.2L90.6 327c-.5 6.5 3.1 12.7 9 15.5s12.9 1.8 17.8-2.6l35.3-32.5 9.5-35.4 10.4-38.6c8-29.9 30.5-52.1 57.9-60.9l41-59.2c11.3-16.3 26.4-28.9 43.5-37.2c-.4-.6-.8-1.2-1.3-1.8c-4.1-5.1-10.9-7.2-17.2-5.3L220.3 92.1l-29.4-74C188.4 12 182.6 8 176 8zM367.7 161.5l135.6 36.3c6.5 1.8 11.3 7.4 11.8 14.2l4.6 56.5-201.5-54 32.2-46.6c3.8-5.6 10.8-8.1 17.3-6.4zm-69.9-30l-47.9 69.3c-21.6 3-40.3 18.6-46.3 41l-10.4 38.6-16.6 61.8-8.3 30.9c-4.6 17.1 5.6 34.6 22.6 39.2l15.5 4.1c17.1 4.6 34.6-5.6 39.2-22.6l8.3-30.9 247.3 66.3-8.3 30.9c-4.6 17.1 5.6 34.6 22.6 39.2l15.5 4.1c17.1 4.6 34.6-5.6 39.2-22.6l8.3-30.9L595 388l10.4-38.6c6-22.4-2.5-45.2-19.6-58.7l-6.8-84c-2.7-33.7-26.4-62-59-70.8L384.2 99.7c-32.7-8.8-67.3 4-86.5 31.8zm-17 131a24 24 0 1 1 -12.4 46.4 24 24 0 1 1 12.4-46.4zm217.9 83.2A24 24 0 1 1 545 358.1a24 24 0 1 1 -46.4-12.4z"},
  jam: {w:320, h:512, d:"M64 0C28.7 0 0 28.7 0 64V352c0 88.4 71.6 160 160 160s160-71.6 160-160V64c0-35.3-28.7-64-64-64H64zm96 416a48 48 0 1 1 0-96 48 48 0 1 1 0 96zm48-176a48 48 0 1 1 -96 0 48 48 0 1 1 96 0zm-48-80a48 48 0 1 1 0-96 48 48 0 1 1 0 96z"},
};
const PIN_PATH='M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z';
function pinIcon(color, key, mag){
  mag = mag || 1;
  const W = Math.round(30*mag), H = Math.round(30*mag);
  const g = FA[key]; let glyph='';
  if(g){ const gs = 8/Math.max(g.w,g.h); const gx = 12-(g.w*gs)/2, gy = 9-(g.h*gs)/2;
    glyph = `<g transform="translate(${gx.toFixed(2)} ${gy.toFixed(2)}) scale(${gs.toFixed(4)})"><path d="${g.d}" fill="${color}"/></g>`; }
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 24 24">`
    + `<path d="${PIN_PATH}" fill="${color}" stroke="#ffffff" stroke-width="1.1"/>`
    + `<circle cx="12" cy="9" r="4.7" fill="#ffffff"/>` + glyph + `</svg>`;
  return { url:'data:image/svg+xml;charset=UTF-8,'+encodeURIComponent(svg),
           scaledSize:new google.maps.Size(W,H), anchor:new google.maps.Point(W/2, Math.round(H*0.92)) };
}

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
      icon:pinIcon(estColor(o.s),'obras',1)});
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
      icon:pinIcon('#2e9e5b','areas',0.82)});
    mk.addListener('click',()=>{ info.setContent(
      `<div style="font-family:Montserrat,Arial;max-width:220px"><b style="color:#155d34">${esc(a.n)}</b>
       <div style="font-size:12px;margin-top:2px">🌳 ${esc(a.d||'')}</div></div>`); info.open(map,mk); });
    return mk;
  });
}

/* Estaciones Qrobici (marcadores, tamaño = viajes) */
function buildEstaciones(){
  mkEst = ESTACIONES.map(e=>{
    const mag = 0.8 + Math.sqrt((e.v||0)/maxViajes)*0.9;   // tamaño del pin ~ viajes
    const mk=new google.maps.Marker({position:{lat:e.lat,lng:e.lng},title:e.n,
      icon:pinIcon('#e0872b','bici',mag)});
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
      icon:pinIcon(WZ_COLOR[a.cat], a.cat, 0.82)});
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
