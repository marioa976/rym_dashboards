<?php
/**
 * Ejecutivo · Recorrido territorial (planeador de caminata).
 * Eliges una sección (o delegación) -> ves los puntos de cada módulo dentro
 * (tickets abiertos, beneficiarios DIF, obras, áreas verdes) -> dibujas un
 * polígono o un corredor -> se genera una ruta ordenada con la "ficha de
 * recorrido" (paradas con contexto + resumen + exportar).
 *
 * Los datos por territorio los sirve recorrido_data.php (bbox + point-in-polygon,
 * PII de beneficiarios solo para editor/admin).
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';     // guard: require_module('ejecutivo')
require_once __DIR__ . '/lib.php';

$cfg     = ej_config();
$apiKey  = $cfg['google_maps_api_key'] ?? '';
$verPII  = function_exists('puede_editar') && puede_editar('ejecutivo');
$dbError = null; $limites = []; $secciones = []; $delegaciones = [];
try {
    $pdo     = ej_pdo();
    $limites = ej_limites($pdo);
    $delegaciones = array_map(fn($p) => $p['n'], ej_poligonos($pdo));
    foreach ($pdo->query("SELECT DISTINCT s.num_seccion n
                            FROM secciones s JOIN secciones_geo g ON g.seccion_id=s.id
                           ORDER BY s.num_seccion") as $r) $secciones[] = (int)$r['n'];
} catch (Throwable $e) { $dbError = $e->getMessage(); }

$ktTitle  = 'Ejecutivo · Recorrido territorial';
$ktActive = 'ejecutivo';
$ktFluid  = true;
require __DIR__ . '/../../views/layout/kt_top.php';
?>
<style>
  .rc-wrap{display:grid;grid-template-columns:360px 1fr;gap:16px;align-items:start}
  @media(max-width:1100px){.rc-wrap{grid-template-columns:1fr}}
  .rc-panel{background:var(--card);border:1px solid var(--border);border-radius:.75rem;padding:16px 18px}
  .rc-panel h3{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted-foreground);font-weight:600;margin-bottom:10px}
  .rc-field{margin-bottom:12px}
  .rc-field label{display:block;font-size:12px;font-weight:600;color:var(--muted-foreground);margin-bottom:4px}
  .rc-field select,.rc-field input{width:100%;border:1px solid var(--border);border-radius:8px;padding:8px 10px;font:inherit;font-size:13px;background:var(--background)}
  .rc-seg{display:flex;gap:6px;margin-bottom:12px}
  .rc-seg button{flex:1;padding:7px 8px;border:1px solid var(--border);background:var(--background);border-radius:8px;font:inherit;font-size:12px;font-weight:600;color:var(--muted-foreground);cursor:pointer}
  .rc-seg button.on{background:var(--primary);color:var(--primary-foreground);border-color:var(--primary)}
  .rc-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;width:100%;padding:9px 12px;border:0;border-radius:8px;background:var(--primary);color:var(--primary-foreground);font:inherit;font-weight:600;font-size:13px;cursor:pointer}
  .rc-btn.ghost{background:var(--background);color:var(--foreground);border:1px solid var(--border)}
  .rc-btn:disabled{opacity:.5;cursor:not-allowed}
  .rc-row{display:flex;gap:8px}
  .rc-ctx{font-size:12px;color:var(--muted-foreground);line-height:1.5;margin-top:6px}
  .rc-ctx b{color:var(--foreground)}
  .rc-chip{display:inline-flex;align-items:center;gap:6px;padding:3px 9px;border-radius:999px;font-size:12px;font-weight:600;margin:3px 4px 0 0;border:1px solid var(--border)}
  .rc-chip .dot{width:9px;height:9px;border-radius:50%}
  .rc-layers label{display:flex;align-items:center;gap:9px;padding:7px 6px;border-radius:8px;font-size:13px;cursor:pointer}
  .rc-layers label:hover{background:var(--muted)}
  .rc-layers .cnt{margin-left:auto;font-variant-numeric:tabular-nums;color:var(--muted-foreground);font-size:12px;font-weight:600}
  .rc-swatch{width:12px;height:12px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.15)}
  #rc-map{height:clamp(560px,calc(100vh - 190px),920px);border-radius:.75rem;border:1px solid var(--border);overflow:hidden}
  .rc-tools{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
  .rc-hint{font-size:12px;color:var(--muted-foreground);margin-top:8px;line-height:1.5}
  /* Ficha */
  .rc-ficha{margin-top:16px}
  .rc-sum{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:12px}
  .rc-sum .b{background:var(--muted);border-radius:8px;padding:10px 12px}
  .rc-sum .b .v{font-size:20px;font-weight:700;color:var(--foreground);line-height:1}
  .rc-sum .b .l{font-size:11px;color:var(--muted-foreground);margin-top:3px}
  .rc-stop{display:flex;gap:10px;padding:9px 4px;border-bottom:1px dashed var(--border)}
  .rc-stop .num{flex-shrink:0;width:24px;height:24px;border-radius:50%;background:var(--primary);color:var(--primary-foreground);font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center}
  .rc-stop .t{font-size:13px;color:var(--foreground);font-weight:600}
  .rc-stop .s{font-size:12px;color:var(--muted-foreground);margin-top:2px}
  .rc-empty{font-size:13px;color:var(--muted-foreground);padding:8px 0}
  @media print{
    body *{visibility:hidden}
    #rc-print,#rc-print *{visibility:visible}
    #rc-print{position:absolute;left:0;top:0;width:100%}
  }
</style>

<?php if ($dbError): ?><div class="rc-panel" style="border-inline-start:3px solid #dc2626;margin-bottom:14px"><b style="color:#dc2626">Error:</b> <?= htmlspecialchars($dbError) ?></div><?php endif; ?>
<?php if (!$apiKey): ?><div class="rc-panel" style="border-inline-start:3px solid #dc2626;margin-bottom:14px">Falta <code>GOOGLE_MAPS_API_KEY</code>.</div><?php endif; ?>

<div class="page-head" style="margin-bottom:16px">
  <h1 style="font-size:22px;font-weight:700;color:var(--foreground)">Recorrido territorial</h1>
  <p class="text-secondary" style="font-size:14px;color:var(--muted-foreground);margin-top:4px">
    Elige un territorio, mira qué hay dentro (problemas, beneficiarios, obras), traza tu zona y genera una ruta de caminata con su ficha.
  </p>
</div>

<div class="rc-wrap">
  <!-- ============ PANEL DE CONTROL ============ -->
  <div>
    <div class="rc-panel">
      <h3>1 · Territorio</h3>
      <div class="rc-seg" id="rc-scope">
        <button data-scope="sec" class="on">Por sección</button>
        <button data-scope="deleg">Por delegación</button>
      </div>
      <div class="rc-field" id="rc-f-sec">
        <label>Sección</label>
        <select id="rc-sec">
          <option value="">— elige una sección —</option>
          <?php foreach ($secciones as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="rc-field" id="rc-f-deleg" style="display:none">
        <label>Delegación</label>
        <select id="rc-deleg">
          <option value="">— elige una delegación —</option>
          <?php foreach ($delegaciones as $d): ?><option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option><?php endforeach; ?>
        </select>
      </div>
      <button class="rc-btn" id="rc-load">Cargar territorio</button>
      <div id="rc-ctx" class="rc-ctx"></div>
    </div>

    <div class="rc-panel" style="margin-top:14px">
      <h3>2 · Capas</h3>
      <div class="rc-layers" id="rc-layers">
        <label><span class="rc-swatch" style="background:#dc2626"></span> Tickets abiertos <input type="checkbox" data-layer="tickets" checked style="display:none"><span class="cnt" data-cnt="tickets">—</span></label>
        <label><span class="rc-swatch" style="background:#059669"></span> Beneficiarios DIF <input type="checkbox" data-layer="dif" checked style="display:none"><span class="cnt" data-cnt="dif">—</span></label>
        <label><span class="rc-swatch" style="background:#2563eb"></span> Obras <input type="checkbox" data-layer="obras" checked style="display:none"><span class="cnt" data-cnt="obras">—</span></label>
        <label><span class="rc-swatch" style="background:#0891b2"></span> Áreas verdes <input type="checkbox" data-layer="areas" checked style="display:none"><span class="cnt" data-cnt="areas">—</span></label>
      </div>
      <div class="rc-hint">Clic en cada capa para prender/apagar. Los conteos son del territorio cargado.</div>
    </div>

    <div class="rc-panel" style="margin-top:14px">
      <h3>3 · Traza tu recorrido</h3>
      <div class="rc-tools">
        <button class="rc-btn ghost" id="rc-poly" style="width:auto;flex:1">✏️ Polígono</button>
        <button class="rc-btn ghost" id="rc-corr" style="width:auto;flex:1">📏 Corredor</button>
        <button class="rc-btn ghost" id="rc-clear" style="width:auto">🗑</button>
      </div>
      <div class="rc-field" id="rc-buffer-wrap" style="display:none">
        <label>Ancho del corredor (metros a cada lado)</label>
        <input type="number" id="rc-buffer" value="150" min="20" max="1000" step="10">
      </div>
      <button class="rc-btn" id="rc-gen" disabled>Generar recorrido</button>
      <div class="rc-hint" id="rc-draw-hint">Elige un territorio primero. Luego dibuja un polígono (rodea la zona) o un corredor (una calle + ancho).</div>
    </div>

    <!-- ============ FICHA ============ -->
    <div class="rc-panel rc-ficha" id="rc-ficha" style="display:none">
      <div id="rc-print">
        <h3 style="margin-bottom:6px">Ficha de recorrido</h3>
        <div id="rc-ficha-terr" class="rc-ctx" style="margin-bottom:10px"></div>
        <div class="rc-sum" id="rc-sum"></div>
        <div id="rc-stops"></div>
      </div>
      <div class="rc-row" style="margin-top:12px">
        <button class="rc-btn ghost" id="rc-print-btn" style="flex:1">🖨 Imprimir</button>
        <a class="rc-btn" id="rc-gmaps" target="_blank" rel="noopener" style="flex:1;text-decoration:none">📍 Abrir en Maps</a>
      </div>
    </div>
  </div>

  <!-- ============ MAPA ============ -->
  <div><div id="rc-map"></div></div>
</div>

<script>
const LIMITES = <?= json_encode($limites, JSON_UNESCAPED_UNICODE) ?>;
const HASKEY  = <?= $apiKey ? 'true' : 'false' ?>;
const VERPII  = <?= $verPII ? 'true' : 'false' ?>;
const BASE    = <?= json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/')) ?>;
const $ = id => document.getElementById(id);
function esc(s){ return String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

const LAYER_META = {
  tickets:{color:'#dc2626', label:'Problema'},
  dif:    {color:'#059669', label:'Beneficiario'},
  obras:  {color:'#2563eb', label:'Obra'},
  areas:  {color:'#0891b2', label:'Área verde'},
};

let map, info, boundary=null, secLayer=null;
let scope='sec';
let TERR=null;                 // datos del territorio cargado
const markers={};              // layer -> [google marker]
const enabled={tickets:true,dif:true,obras:true,areas:true};
let drawMgr=null, drawnPoly=null, corridorLine=null, routeLine=null;
let mode=null;                 // 'poly' | 'corr' | null

window.initRcMap = function(){
  map = new google.maps.Map($('rc-map'), {center:{lat:20.59,lng:-100.39}, zoom:11,
    mapTypeControl:false, streetViewControl:false, fullscreenControl:true,
    styles:[{featureType:'poi',stylers:[{visibility:'off'}]}]});
  info = new google.maps.InfoWindow();

  if (LIMITES.length){
    boundary = new google.maps.Data();
    boundary.addGeoJson({type:'FeatureCollection',features:LIMITES});
    boundary.setStyle({strokeColor:'#64748b',strokeWeight:1.4,strokeOpacity:.6,fillOpacity:0,clickable:false});
    boundary.setMap(map);
    const b=new google.maps.LatLngBounds();
    boundary.forEach(f=>f.getGeometry().forEachLatLng(ll=>b.extend(ll)));
    if(!b.isEmpty()) map.fitBounds(b);
  }

  drawMgr = new google.maps.drawing.DrawingManager({
    drawingMode:null, drawingControl:false,
    polygonOptions:{fillColor:'#005ab2',fillOpacity:.10,strokeColor:'#005ab2',strokeWeight:2,editable:true,zIndex:5},
    polylineOptions:{strokeColor:'#005ab2',strokeWeight:3,editable:true,zIndex:5},
  });
  drawMgr.setMap(map);
  google.maps.event.addListener(drawMgr,'polygoncomplete', p=>{ clearShapes(false); drawnPoly=p; drawMgr.setDrawingMode(null); enableGen(); });
  google.maps.event.addListener(drawMgr,'polylinecomplete', l=>{ clearShapes(false); corridorLine=l; drawMgr.setDrawingMode(null); enableGen(); });
};

// ---------- carga de territorio ----------
async function loadTerritory(){
  const val = scope==='sec' ? $('rc-sec').value : $('rc-deleg').value;
  if(!val){ $('rc-ctx').textContent = 'Elige un '+(scope==='sec'?'número de sección':'delegación')+'.'; return; }
  $('rc-load').disabled=true; $('rc-load').textContent='Cargando…';
  try{
    const url = BASE + '/recorrido_data.php?' + (scope==='sec' ? 'sec='+encodeURIComponent(val) : 'deleg='+encodeURIComponent(val));
    const r = await fetch(url, {headers:{'X-Requested-With':'fetch'}});
    const d = await r.json();
    if(!d.ok){ $('rc-ctx').textContent = d.error||'No se pudo cargar.'; return; }
    renderTerritory(d);
  }catch(e){ $('rc-ctx').textContent='Error de red.'; }
  finally{ $('rc-load').disabled=false; $('rc-load').textContent='Cargar territorio'; }
}

function renderTerritory(d){
  TERR = d;
  clearMarkers(); clearShapes(true); $('rc-ficha').style.display='none';
  // geometría del territorio
  if(secLayer){ secLayer.setMap(null); secLayer=null; }
  if(d.geom){
    secLayer = new google.maps.Data();
    secLayer.addGeoJson({type:'Feature',geometry:d.geom,properties:{}});
    secLayer.setStyle({fillColor:'#005ab2',fillOpacity:.05,strokeColor:'#005ab2',strokeWeight:2,strokeOpacity:.7,clickable:false});
    secLayer.setMap(map);
    const b=new google.maps.LatLngBounds();
    secLayer.forEach(f=>f.getGeometry().forEachLatLng(ll=>b.extend(ll)));
    if(!b.isEmpty()) map.fitBounds(b);
  }
  // contexto
  let ctx = '<b>'+esc(d.titulo)+'</b>';
  if(d.part!=null) ctx += ' · participación <b>'+d.part+'%</b>';
  if(d.gan) ctx += ' · ganó <b>'+esc(d.gan)+'</b>';
  $('rc-ctx').innerHTML = ctx;
  // markers + counts
  for(const layer of Object.keys(LAYER_META)){
    const pts = d.layers[layer]||[];
    const cntEl = document.querySelector('[data-cnt="'+layer+'"]'); if(cntEl) cntEl.textContent = pts.length;
    markers[layer] = pts.map(p=>{
      const m = new google.maps.Marker({position:{lat:p.lat,lng:p.lng}, map:enabled[layer]?map:null,
        icon:{path:google.maps.SymbolPath.CIRCLE, scale:6, fillColor:LAYER_META[layer].color, fillOpacity:.9, strokeColor:'#fff', strokeWeight:1.5},
        zIndex: layer==='tickets'?4:2});
      m._layer=layer; m._p=p;
      m.addListener('click', ()=>{ info.setContent(stopHtml(layer,p)); info.open(map,m); });
      return m;
    });
  }
  enableGen();
}

function stopHtml(layer, p){
  const c=LAYER_META[layer].color;
  let t='', s='';
  if(layer==='tickets'){ t='Problema · '+esc(p.tipo); s=(p.dias!=null?p.dias+' días abierto':'')+(p.vencido?' · <b style="color:#dc2626">VENCIDO</b>':'')+(p.dir?'<br>'+esc(p.dir):'')+' · ticket #'+p.id; }
  else if(layer==='dif'){ t='Beneficiario DIF'; s=esc(p.prog||'')+(p.apoyo?' · '+esc(p.apoyo):'')+(p.nombre?'<br><b>'+esc(p.nombre)+'</b>'+(p.col?' · '+esc(p.col):''):''); }
  else if(layer==='obras'){ t='Obra · '+esc(p.estatus||''); s=esc(p.n||'')+(p.inv?'<br>Inversión: $'+Number(p.inv).toLocaleString('es-MX'):''); }
  else if(layer==='areas'){ t='Área verde'; s=esc(p.n||''); }
  return '<div style="font:13px/1.5 Montserrat,sans-serif;max-width:240px"><b style="color:'+c+'">'+t+'</b><br>'+s+'</div>';
}

// ---------- capas on/off ----------
document.querySelectorAll('#rc-layers label').forEach(l=>{
  l.addEventListener('click', e=>{
    const cb=l.querySelector('input'); const layer=cb.dataset.layer;
    enabled[layer]=!enabled[layer];
    l.style.opacity = enabled[layer]?'1':'.45';
    (markers[layer]||[]).forEach(m=>m.setMap(enabled[layer]?map:null));
  });
});

// ---------- scope selector ----------
$('rc-scope').addEventListener('click', e=>{
  const b=e.target.closest('button'); if(!b) return;
  scope=b.dataset.scope;
  document.querySelectorAll('#rc-scope button').forEach(x=>x.classList.toggle('on', x===b));
  $('rc-f-sec').style.display   = scope==='sec' ? '' : 'none';
  $('rc-f-deleg').style.display = scope==='deleg' ? '' : 'none';
});
$('rc-load').addEventListener('click', loadTerritory);

// ---------- herramientas de dibujo ----------
$('rc-poly').addEventListener('click', ()=>{ if(!map) return; mode='poly'; $('rc-buffer-wrap').style.display='none'; drawMgr.setDrawingMode(google.maps.drawing.OverlayType.POLYGON); });
$('rc-corr').addEventListener('click', ()=>{ if(!map) return; mode='corr'; $('rc-buffer-wrap').style.display=''; drawMgr.setDrawingMode(google.maps.drawing.OverlayType.POLYLINE); });
$('rc-clear').addEventListener('click', ()=>{ clearShapes(true); $('rc-ficha').style.display='none'; enableGen(); });
$('rc-gen').addEventListener('click', generar);

function clearShapes(clearRoute){
  if(drawnPoly){ drawnPoly.setMap(null); drawnPoly=null; }
  if(corridorLine){ corridorLine.setMap(null); corridorLine=null; }
  if(clearRoute && routeLine){ routeLine.setMap(null); routeLine=null; }
  if(drawMgr) drawMgr.setDrawingMode(null);
}
function enableGen(){
  const hasShape = !!(drawnPoly||corridorLine);
  const hasTerr  = !!TERR;
  $('rc-gen').disabled = !(hasShape && hasTerr);
  $('rc-draw-hint').textContent = !hasTerr ? 'Elige un territorio primero.' :
    (!hasShape ? 'Dibuja un polígono o un corredor sobre el mapa.' : 'Listo: genera el recorrido.');
}

// ---------- generar recorrido ----------
function metersBetween(a,b){ return google.maps.geometry.spherical.computeDistanceBetween(a,b); }

function pointsInShape(){
  // reúne los puntos de las capas encendidas que caen en el polígono / corredor
  const all=[];
  for(const layer of Object.keys(LAYER_META)){
    if(!enabled[layer]) continue;
    for(const m of (markers[layer]||[])){
      const pos=m.getPosition();
      if(inShape(pos)) all.push({layer, p:m._p, pos});
    }
  }
  return all;
}
function inShape(latlng){
  if(drawnPoly){ return google.maps.geometry.poly.containsLocation(latlng, drawnPoly); }
  if(corridorLine){
    const buf = Math.max(20, +$('rc-buffer').value||150);
    const path = corridorLine.getPath().getArray();
    // muestreo denso de la polilínea y prueba de cercanía
    for(let i=0;i<path.length-1;i++){
      const a=path[i], b=path[i+1];
      const segLen=metersBetween(a,b);
      const steps=Math.max(1, Math.ceil(segLen/(buf/2)));
      for(let s=0;s<=steps;s++){
        const t=s/steps;
        const ll=google.maps.geometry.spherical.interpolate(a,b,t);
        if(metersBetween(ll,latlng)<=buf) return true;
      }
    }
    return false;
  }
  return false;
}

function generar(){
  const stops = pointsInShape();
  if(stops.length===0){ alert('No hay puntos (de las capas encendidas) dentro de tu trazo.'); return; }
  // orden vecino-más-cercano desde el punto más al noroeste
  let start=0, best=1e9;
  stops.forEach((s,i)=>{ const v=-s.pos.lat()+s.pos.lng(); if(v<best){best=v;start=i;} });
  const ordered=[]; const used=new Array(stops.length).fill(false);
  let cur=start; used[cur]=true; ordered.push(stops[cur]);
  for(let k=1;k<stops.length;k++){
    let nn=-1, nd=1e18;
    for(let j=0;j<stops.length;j++){ if(used[j])continue; const d=metersBetween(stops[cur].pos,stops[j].pos); if(d<nd){nd=d;nn=j;} }
    used[nn]=true; ordered.push(stops[nn]); cur=nn;
  }
  // dibuja la ruta
  if(routeLine) routeLine.setMap(null);
  routeLine = new google.maps.Polyline({path:ordered.map(o=>o.pos), map,
    strokeColor:'#005ab2', strokeOpacity:.9, strokeWeight:3, icons:[{icon:{path:google.maps.SymbolPath.FORWARD_CLOSED_ARROW},offset:'50%',repeat:'120px'}]});
  renderFicha(ordered);
}

function renderFicha(ordered){
  const dist = google.maps.geometry.spherical.computeLength(ordered.map(o=>o.pos)); // metros
  const walkMin = dist/1.35/60;                 // ~1.35 m/s caminando
  const stopMin = ordered.length*2;             // ~2 min por parada
  const cnt={tickets:0,dif:0,obras:0,areas:0}; ordered.forEach(o=>cnt[o.layer]++);
  // resumen
  $('rc-sum').innerHTML =
    box(ordered.length,'paradas')+
    box((dist/1000).toFixed(2)+' km','distancia')+
    box(Math.round(walkMin+stopMin)+' min','tiempo estimado')+
    box(cnt.tickets,'problemas')+
    box(cnt.dif,'beneficiarios')+
    box(cnt.obras+cnt.areas,'obras/áreas');
  // territorio
  let terr = TERR? ('<b>'+esc(TERR.titulo)+'</b>'+(TERR.part!=null?' · participación '+TERR.part+'%':'')+(TERR.gan?' · ganó '+esc(TERR.gan):'')) : '';
  $('rc-ficha-terr').innerHTML = terr;
  // paradas
  $('rc-stops').innerHTML = ordered.map((o,i)=>{
    const meta=LAYER_META[o.layer]; const p=o.p; let t='',s='';
    if(o.layer==='tickets'){ t='Problema · '+esc(p.tipo); s=(p.dias!=null?p.dias+'d abierto':'')+(p.vencido?' · VENCIDO':'')+(p.dir?' · '+esc(p.dir):''); }
    else if(o.layer==='dif'){ t='Beneficiario DIF'; s=esc(p.prog||'')+(p.nombre?' · '+esc(p.nombre):''); }
    else if(o.layer==='obras'){ t='Obra · '+esc(p.estatus||''); s=esc(p.n||''); }
    else { t='Área verde'; s=esc(p.n||''); }
    return '<div class="rc-stop"><div class="num" style="background:'+meta.color+'">'+(i+1)+'</div>'+
           '<div><div class="t">'+t+'</div><div class="s">'+s+'</div></div></div>';
  }).join('');
  // Google Maps (hasta 25 paradas)
  const wp = ordered.slice(0,25).map(o=>o.pos.lat().toFixed(6)+','+o.pos.lng().toFixed(6));
  $('rc-gmaps').href = 'https://www.google.com/maps/dir/'+wp.join('/')+'/data=!4m2!4m1!3e2';
  $('rc-ficha').style.display='';
}
function box(v,l){ return '<div class="b"><div class="v">'+v+'</div><div class="l">'+l+'</div></div>'; }

$('rc-print-btn').addEventListener('click', ()=>window.print());

function clearMarkers(){ for(const k of Object.keys(markers)){ (markers[k]||[]).forEach(m=>m.setMap(null)); markers[k]=[]; } }

if(!HASKEY){ $('rc-map').innerHTML='<div style="padding:20px;color:#991B1B">Google Maps API key no configurada.</div>'; }
</script>
<?php if ($apiKey): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($apiKey) ?>&libraries=drawing,geometry&callback=initRcMap&loading=async&v=weekly"></script>
<?php endif; ?>
<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
