<?php
/**
 * Cuadrillas · Tablero en vivo.
 * Mapa en tiempo real: paradas por estatus, última posición de cada cuadrilla,
 * KPIs y feed de actividad. Refresca por polling contra vivo_data.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../core/guard.php';
require_module('cuadrillas');
require_once __DIR__ . '/lib.php';

$pdo = cuad_pdo();
cuad_ensure_schema($pdo);

$cfg  = require __DIR__ . '/../../config/config.php';
$GMAP = htmlspecialchars($cfg['google_maps']['api_key'] ?? '', ENT_QUOTES, 'UTF-8');

$ktTitle  = 'Cuadrillas · Tablero en vivo';
$ktActive = 'cuadrillas';
$ktFluid  = true;
require __DIR__ . '/../../views/layout/kt_top.php';
?>
<style>
:where(#cvivo){ --cv-line:var(--border); }
:where(#cvivo) .bar{ display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; margin-bottom:14px; }
:where(#cvivo) .live{ display:inline-flex; align-items:center; gap:8px; font-size:13px; color:var(--muted-foreground); font-family:'Montserrat',monospace; }
:where(#cvivo) .dot-live{ width:9px; height:9px; border-radius:50%; background:#15803d; box-shadow:0 0 0 0 rgba(21,128,61,.5); animation:cvpulse 2s infinite; }
@keyframes cvpulse{0%{box-shadow:0 0 0 0 rgba(21,128,61,.5)}70%{box-shadow:0 0 0 7px rgba(21,128,61,0)}100%{box-shadow:0 0 0 0 rgba(21,128,61,0)}}
:where(#cvivo) .legend{ display:flex; gap:14px; flex-wrap:wrap; font-size:12px; color:var(--muted-foreground); }
:where(#cvivo) .legend i{ width:10px; height:10px; border-radius:50%; display:inline-block; margin-right:5px; vertical-align:-1px; }
:where(#cvivo) .btn{ all:unset; cursor:pointer; display:inline-flex; align-items:center; gap:7px; height:34px; padding:0 13px; border-radius:8px; font-size:13px; font-weight:700; border:1px solid var(--border); color:var(--foreground); }
:where(#cvivo) .btn:hover{ background:var(--muted); }

:where(#cvivo) .kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:14px; }
:where(#cvivo) .kpi{ background:var(--card); border:1px solid var(--border); border-radius:12px; padding:13px 15px; }
:where(#cvivo) .kpi .v{ font-size:26px; font-weight:800; letter-spacing:-.02em; line-height:1; font-variant-numeric:tabular-nums; }
:where(#cvivo) .kpi .l{ font-size:12px; color:var(--muted-foreground); margin-top:5px; }

:where(#cvivo) .grid{ display:grid; grid-template-columns:1fr 340px; gap:16px; height:clamp(520px, calc(100vh - 320px), 820px); }
:where(#cvivo) #cvmap{ width:100%; height:100%; border-radius:14px; border:1px solid var(--border); background:var(--muted); }
:where(#cvivo) .side{ background:var(--card); border:1px solid var(--border); border-radius:14px; display:flex; flex-direction:column; overflow:hidden; }
:where(#cvivo) .side h3{ font-size:13px; font-weight:800; letter-spacing:.02em; padding:13px 15px; border-bottom:1px solid var(--border); margin:0; }
:where(#cvivo) .feed{ overflow-y:auto; flex:1; }
:where(#cvivo) .ev{ display:flex; gap:11px; padding:11px 15px; border-bottom:1px solid var(--border); }
:where(#cvivo) .ev:last-child{ border-bottom:0; }
:where(#cvivo) .ev .ic{ width:9px; height:9px; border-radius:50%; margin-top:5px; flex-shrink:0; }
:where(#cvivo) .ev .tx{ font-size:13px; line-height:1.35; }
:where(#cvivo) .ev .tx b{ font-weight:700; }
:where(#cvivo) .ev .mt{ font-size:11px; color:var(--muted-foreground); font-family:'Montserrat',monospace; margin-top:2px; }
:where(#cvivo) .empty{ padding:26px 16px; color:var(--muted-foreground); text-align:center; font-size:13px; }
@media (max-width:900px){
  :where(#cvivo) .kpis{ grid-template-columns:repeat(2,1fr); }
  :where(#cvivo) .grid{ grid-template-columns:1fr; height:auto; }
  :where(#cvivo) #cvmap{ height:60vh; }
  :where(#cvivo) .side{ height:50vh; }
}
</style>

<div id="cvivo">
  <div class="bar">
    <div class="legend">
      <span><i style="background:#64748b"></i>Pendiente</span>
      <span><i style="background:#1d4ed8"></i>En camino</span>
      <span><i style="background:#b45309"></i>En sitio</span>
      <span><i style="background:#15803d"></i>Resuelta</span>
      <span><i style="background:#b91c1c"></i>No resuelta</span>
    </div>
    <div class="flex items-center gap-3">
      <span class="live"><span class="dot-live" id="cvdot"></span> Actualizado <b id="cvts">—</b></span>
      <button class="btn" id="cvtoggle"><i class="ki-filled ki-pause"></i> Pausar</button>
    </div>
  </div>

  <div class="kpis">
    <div class="kpi"><div class="v" id="k-cuad">0</div><div class="l">Cuadrillas activas</div></div>
    <div class="kpi"><div class="v" id="k-ord">0</div><div class="l">Órdenes abiertas</div></div>
    <div class="kpi"><div class="v" id="k-pend">0</div><div class="l">Paradas pendientes</div></div>
    <div class="kpi"><div class="v" id="k-oper">0</div><div class="l">Operadores</div></div>
  </div>

  <div class="grid">
    <div id="cvmap"></div>
    <div class="side">
      <h3>Actividad reciente</h3>
      <div class="feed" id="cvfeed"><div class="empty">Esperando movimiento en campo…</div></div>
    </div>
  </div>
</div>

<script id="cvcfg" type="application/json"><?= json_encode(['key' => $GMAP], JSON_UNESCAPED_SLASHES) ?></script>
<script>
const CV = JSON.parse(document.getElementById('cvcfg').textContent);
const SCOL = {pendiente:'#64748b', en_camino:'#1d4ed8', en_sitio:'#b45309', resuelta:'#15803d', no_resuelta:'#b91c1c'};
const SLAB = {pendiente:'Pendiente', en_camino:'En camino', en_sitio:'En sitio', resuelta:'Resuelta', no_resuelta:'No resuelta', evidencia:'Subió evidencia', zendesk_reflejo:'Reflejado a Zendesk'};
const $ = id => document.getElementById(id);
const fmt = n => Number(n).toLocaleString('es-MX');

let map, info, pMarkers = [], cMarkers = [], fitted = false, playing = true, timer = null;

function initMap(){
  map = new google.maps.Map($('cvmap'), {
    center:{lat:20.5888,lng:-100.3899}, zoom:12, mapTypeControl:false, streetViewControl:false,
    fullscreenControl:true, styles:[{featureType:'poi',stylers:[{visibility:'off'}]}]
  });
  info = new google.maps.InfoWindow();
  poll();
  timer = setInterval(()=>{ if(playing) poll(); }, 15000);
}
window.initMap = initMap;

function clearMarkers(arr){ arr.forEach(m=>m.setMap(null)); arr.length = 0; }

function dotIcon(color, scale){
  return {path:google.maps.SymbolPath.CIRCLE, scale:scale||6, fillColor:color, fillOpacity:1, strokeColor:'#fff', strokeWeight:2};
}
function crewIcon(color){
  // pin-ish: flecha cerrada grande con el color de la cuadrilla
  return {path:'M 0,0 C -2,-20 -10,-22 -10,-30 A 10,10 0 1,1 10,-30 C 10,-22 2,-20 0,0 z',
          fillColor:color, fillOpacity:1, strokeColor:'#fff', strokeWeight:2, scale:0.8, anchor:new google.maps.Point(0,0)};
}

async function poll(){
  let data;
  try{
    const r = await fetch('vivo_data.php', {headers:{'X-Requested-With':'fetch'}});
    data = await r.json();
    if(!data.ok) throw new Error(data.error||'error');
  }catch(e){ $('cvdot').style.background='#b91c1c'; return; }
  $('cvdot').style.background='#15803d';
  $('cvts').textContent = data.ts;

  // KPIs
  $('k-cuad').textContent = fmt(data.kpis.cuadrillas);
  $('k-ord').textContent  = fmt(data.kpis.ordenes_abiertas);
  $('k-pend').textContent = fmt(data.kpis.paradas_pend);
  $('k-oper').textContent = fmt(data.kpis.operadores);

  // Paradas
  clearMarkers(pMarkers);
  const bounds = new google.maps.LatLngBounds();
  (data.paradas||[]).forEach(p=>{
    const m = new google.maps.Marker({position:{lat:p.lat,lng:p.lng}, map, icon:dotIcon(SCOL[p.estatus]||'#64748b',6), zIndex:1});
    m.addListener('click', ()=>{ info.setContent(
      `<div style="font:13px Montserrat,sans-serif"><b>${esc(p.titulo||'Parada')}</b><br>`+
      `${p.cuadrilla?esc(p.cuadrilla)+' · ':''}<span style="color:${SCOL[p.estatus]}">${SLAB[p.estatus]||p.estatus}</span></div>`);
      info.open(map,m); });
    pMarkers.push(m); bounds.extend(m.getPosition());
  });

  // Posiciones de cuadrilla
  clearMarkers(cMarkers);
  (data.posiciones||[]).forEach(q=>{
    const m = new google.maps.Marker({position:{lat:q.lat,lng:q.lng}, map, icon:crewIcon(q.color||'#0f766e'), zIndex:5, title:q.cuadrilla||''});
    m.addListener('click', ()=>{ info.setContent(
      `<div style="font:13px Montserrat,sans-serif"><b>${esc(q.cuadrilla||'Cuadrilla')}</b><br>`+
      `${q.operador?esc(q.operador)+'<br>':''}<span style="color:#64748b">última señal: ${esc(q.cuando||'')}</span></div>`);
      info.open(map,m); });
    cMarkers.push(m); bounds.extend(m.getPosition());
  });

  if(!fitted && !bounds.isEmpty()){ map.fitBounds(bounds, 60); fitted = true; }

  // Feed
  const feed = $('cvfeed');
  const acts = data.actividad||[];
  if(!acts.length){ feed.innerHTML = '<div class="empty">Sin actividad todavía.</div>'; }
  else {
    feed.innerHTML = acts.map(a=>{
      const col = SCOL[a.tipo] || '#475569';
      const lab = SLAB[a.tipo] || a.tipo;
      const hora = (a.creado_en||'').slice(11,16);
      return `<div class="ev"><div class="ic" style="background:${col}"></div><div><div class="tx">`+
        `<b>${esc(a.operador||'—')}</b> · ${lab}${a.parada?` <span style="color:#64748b">(${esc(a.parada)})</span>`:''}`+
        `</div><div class="mt">${esc(a.cuadrilla||'')}${a.cuadrilla?' · ':''}${hora}</div></div></div>`;
    }).join('');
  }
}

function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

$('cvtoggle').onclick = function(){
  playing = !playing;
  this.innerHTML = playing ? '<i class="ki-filled ki-pause"></i> Pausar' : '<i class="ki-filled ki-play"></i> Reanudar';
  $('cvdot').style.animationPlayState = playing ? 'running' : 'paused';
  if(playing) poll();
};

(function load(){
  if(!CV.key || CV.key.length<10){ $('cvmap').innerHTML='<div style="padding:30px;color:#64748b">Falta GOOGLE_MAPS_API_KEY.</div>'; return; }
  const s=document.createElement('script');
  s.src='https://maps.googleapis.com/maps/api/js?key='+encodeURIComponent(CV.key)+'&callback=initMap&loading=async';
  s.async=true; document.head.appendChild(s);
})();
</script>

<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
