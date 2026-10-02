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
$dbError = null; $limites = []; $secciones = []; $delegaciones = []; $distritos = []; $distSecs = [];
try {
    $pdo     = ej_pdo();
    $limites = ej_limites($pdo);
    $delegaciones = array_map(fn($p) => $p['n'], ej_poligonos($pdo));
    foreach ($pdo->query("SELECT DISTINCT s.num_seccion n
                            FROM secciones s JOIN secciones_geo g ON g.seccion_id=s.id
                           ORDER BY s.num_seccion") as $r) $secciones[] = (int)$r['n'];
    // distritos que tienen secciones con geometría
    try {
        foreach ($pdo->query("SELECT DISTINCT d.id, d.numero, d.nombre
                                FROM distritos d
                                JOIN secciones s ON s.distrito_id = d.id
                                JOIN secciones_geo g ON g.seccion_id = s.id
                               ORDER BY d.numero") as $r)
            $distritos[] = ['id'=>(int)$r['id'],'numero'=>(int)$r['numero'],'nombre'=>$r['nombre']];
    } catch (Throwable $e) { /* sin catálogo de distritos: se omite el scope */ }
    // Mapa distrito_id -> [num_seccion,...] para la cascada distrito->sección.
    try {
        foreach ($pdo->query("SELECT s.distrito_id d, s.num_seccion n
                                FROM secciones s JOIN secciones_geo g ON g.seccion_id=s.id
                               WHERE s.distrito_id IS NOT NULL
                               ORDER BY s.distrito_id, s.num_seccion") as $r)
            $distSecs[(int)$r['d']][] = (int)$r['n'];
    } catch (Throwable $e) { /* sin mapeo: la sección listará todas */ }
} catch (Throwable $e) { $dbError = $e->getMessage(); }
$distSecs = $distSecs ?? [];

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
  /* Segunda pantalla (presentación del recorrido) */
  #rc-screen{position:fixed;inset:0;z-index:60;background:var(--background);display:none;flex-direction:column}
  #rc-screen.open{display:flex}
  #rc-screen .sc-head{display:flex;align-items:center;gap:14px;padding:12px 18px;border-bottom:1px solid var(--border);flex-wrap:wrap}
  #rc-screen .sc-head h2{font-size:16px;font-weight:700;color:var(--foreground)}
  #rc-screen .sc-chips{display:flex;gap:8px;flex-wrap:wrap}
  #rc-screen .sc-chip{background:var(--muted);border-radius:999px;padding:4px 10px;font-size:12px;font-weight:600;color:var(--foreground)}
  #rc-screen .sc-actions{margin-left:auto;display:flex;gap:8px}
  #rc-screen .sc-body{flex:1;display:grid;grid-template-columns:1fr 400px;min-height:0}
  @media(max-width:900px){#rc-screen .sc-body{grid-template-columns:1fr;grid-template-rows:1fr 45%}}
  #rc-screen-map{width:100%;height:100%}
  #rc-screen-map #rc-map{height:100%!important;border-radius:0;border:0}
  #rc-screen .sc-list{border-inline-start:1px solid var(--border);overflow:auto;padding:10px 14px}
  /* Indicador electoral (afinidad / rentabilidad) */
  .rc-elec{background:var(--muted);border-radius:8px;padding:10px 12px}
  .rc-elec .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted-foreground);font-weight:600;margin-bottom:8px}
  .rc-elec .row{display:flex;justify-content:space-between;align-items:center;font-size:12px;color:var(--muted-foreground);margin-bottom:6px}
  .rc-elec .row b{color:var(--foreground);font-weight:600}
  .rc-elec .bar{height:7px;border-radius:999px;background:var(--border);overflow:hidden;margin-top:3px}
  .rc-elec .bar>span{display:block;height:100%;border-radius:999px}
  .rc-elec .tag{display:inline-block;padding:2px 8px;border-radius:999px;color:#fff;font-weight:700;font-size:11px}
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
      <h3>1 · Elige tu zona</h3>
      <div class="rc-field">
        <label>Distrito</label>
        <select id="rc-dist">
          <option value="">— elige un distrito —</option>
          <?php foreach ($distritos as $d): ?><option value="<?= $d['id'] ?>">Distrito <?= $d['numero'] ?><?= $d['nombre'] ? ' — '.htmlspecialchars($d['nombre']) : '' ?></option><?php endforeach; ?>
        </select>
        <?php if (!$distritos): ?><div class="rc-hint">Sin catálogo de distritos disponible.</div><?php endif; ?>
      </div>
      <div class="rc-field">
        <label>Sección <span style="font-weight:400">(del distrito)</span></label>
        <select id="rc-sec" disabled>
          <option value="">— elige primero un distrito —</option>
        </select>
      </div>
      <div class="rc-field">
        <label style="font-weight:400;font-size:11px">…o por delegación (alternativa)</label>
        <select id="rc-deleg">
          <option value="">— ninguna —</option>
          <?php foreach ($delegaciones as $d): ?><option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="rc-field">
        <label>Tickets: solo reportados en los últimos… (días)</label>
        <select id="rc-dias">
          <option value="7">7 días</option>
          <option value="15">15 días</option>
          <option value="30" selected>30 días</option>
          <option value="90">90 días</option>
          <option value="0">Todos</option>
        </select>
      </div>
      <button class="rc-btn ghost" id="rc-load">Pintar la zona en el mapa</button>
      <div id="rc-ctx" class="rc-ctx" style="margin-top:8px">Elige distrito y sección, pinta la zona, traza tu polígono y genera la ruta. Los puntos se cargan solo dentro de tu trazo.</div>
      <div id="rc-elec" style="display:none;margin-top:10px"></div>
    </div>

    <div class="rc-panel" style="margin-top:14px">
      <h3>2 · Capas</h3>
      <div class="rc-layers" id="rc-layers">
        <label><input type="checkbox" class="rc-cb" data-layer="tickets" checked> <span class="rc-swatch" style="background:#dc2626"></span> Tickets abiertos <span class="cnt" data-cnt="tickets">—</span></label>
        <label><input type="checkbox" class="rc-cb" data-layer="dif" checked> <span class="rc-swatch" style="background:#059669"></span> Beneficiarios DIF <span class="cnt" data-cnt="dif">—</span></label>
        <label><input type="checkbox" class="rc-cb" data-layer="bloque" checked> <span class="rc-swatch" style="background:#7c3aed"></span> Beneficiarios Bloque <span class="cnt" data-cnt="bloque">—</span></label>
        <label><input type="checkbox" class="rc-cb" data-layer="obras" checked> <span class="rc-swatch" style="background:#2563eb"></span> Obras <span class="cnt" data-cnt="obras">—</span></label>
        <label><input type="checkbox" class="rc-cb" data-layer="areas" checked> <span class="rc-swatch" style="background:#0891b2"></span> Áreas verdes <span class="cnt" data-cnt="areas">—</span></label>
      </div>
      <div class="rc-hint">Palomea las capas que quieres incluir. Al generar el recorrido solo se cargan estas capas dentro de tu trazo.</div>
    </div>

    <div class="rc-panel" style="margin-top:14px">
      <h3>3 · Traza tu recorrido</h3>
      <div class="rc-tools">
        <button class="rc-btn ghost" id="rc-poly" style="width:auto;flex:1"><i class="ki-filled ki-pencil" aria-hidden="true"></i> Polígono</button>
        <button class="rc-btn ghost" id="rc-corr" style="width:auto;flex:1"><i class="ki-filled ki-route" aria-hidden="true"></i> Corredor</button>
        <button class="rc-btn ghost" id="rc-clear" style="width:auto" aria-label="Borrar el trazo" title="Borrar el trazo"><i class="ki-filled ki-trash" aria-hidden="true"></i></button>
      </div>
      <div class="rc-tools" id="rc-draw-actions" style="display:none">
        <button class="rc-btn" id="rc-finish" style="flex:1" disabled><i class="ki-filled ki-check"></i> Terminar</button>
        <button class="rc-btn ghost" id="rc-cancel" style="width:auto"><i class="ki-filled ki-cross"></i> Cancelar</button>
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
      <button class="rc-btn" id="rc-screen-btn" style="margin-top:12px"><i class="ki-filled ki-maximize"></i> Ver a pantalla completa</button>
      <div class="rc-row" style="margin-top:8px">
        <button class="rc-btn ghost" id="rc-print-btn" style="flex:1"><i class="ki-filled ki-printer" aria-hidden="true"></i> Imprimir<?php if ($verPII): ?> <i class="ki-filled ki-lock" aria-hidden="true"></i><?php endif; ?></button>
        <a class="rc-btn ghost" id="rc-gmaps" target="_blank" rel="noopener" style="flex:1;text-decoration:none"><i class="ki-filled ki-geolocation"></i> Abrir en Maps</a>
      </div>
    </div>
  </div>

  <!-- ============ MAPA ============ -->
  <div><div id="rc-map"></div></div>
</div>

<!-- ============ SEGUNDA PANTALLA (presentación) ============ -->
<div id="rc-screen">
  <div class="sc-head">
    <h2 id="sc-title">Recorrido</h2>
    <div class="sc-chips" id="sc-chips"></div>
    <div class="sc-actions">
      <button class="rc-btn ghost" id="sc-print" style="width:auto"><i class="ki-filled ki-printer" aria-hidden="true"></i> Imprimir<?php if ($verPII): ?> <i class="ki-filled ki-lock" aria-hidden="true"></i><?php endif; ?></button>
      <a class="rc-btn" id="sc-gmaps" target="_blank" rel="noopener" style="width:auto;text-decoration:none"><i class="ki-filled ki-geolocation"></i> Maps</a>
      <button class="rc-btn ghost" id="sc-close" style="width:auto">✕ Cerrar</button>
    </div>
  </div>
  <div class="sc-body">
    <div id="rc-screen-map"></div>
    <div class="sc-list" id="sc-list"></div>
  </div>
</div>

<script>
const LIMITES = <?= json_encode($limites, JSON_UNESCAPED_UNICODE) ?>;
const DIST_SECS = <?= json_encode($distSecs) ?>;
const HASKEY  = <?= $apiKey ? 'true' : 'false' ?>;
const VERPII  = <?= $verPII ? 'true' : 'false' ?>;
const BASE    = <?= json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/')) ?>;
const AUDIT_URL = <?= json_encode(url('audit.php')) ?>;
function auditBeacon(action,detail){ try{ const fd=new FormData(); fd.append('action',action); fd.append('detail',detail||''); if(navigator.sendBeacon){navigator.sendBeacon(AUDIT_URL,fd);} else {fetch(AUDIT_URL,{method:'POST',body:fd,keepalive:true});} }catch(e){} }
function imprimirFicha(){
  if(VERPII){
    const n = lastOrdered ? lastOrdered.length : 0;
    if(!confirm('La ficha del recorrido incluye nombres de ciudadanos. La impresión queda registrada en auditoría. ¿Continuar? ('+n+' paradas)')) return;
    auditBeacon('print_recorrido', n+' paradas'+(selSec?' · sec '+selSec:''));
  }
  window.print();
}
const $ = id => document.getElementById(id);
function esc(s){ return String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

const LAYER_META = {
  tickets:{color:'#dc2626', label:'Problema'},
  dif:    {color:'#059669', label:'Beneficiario DIF'},
  bloque: {color:'#7c3aed', label:'Beneficiario Bloque'},
  obras:  {color:'#2563eb', label:'Obra'},
  areas:  {color:'#0891b2', label:'Área verde'},
};

let map, info, boundary=null, secLayer=null;
let TERR=null;                 // datos del territorio cargado
const markers={};              // layer -> [google marker]
const enabled={tickets:true,dif:true,bloque:true,obras:true,areas:true};
let cluster=null;              // MarkerClusterer combinado (evita que trabe el mapa)
let drawnPoly=null, corridorLine=null, routeLine=null;
// Dibujo manual: el DrawingManager fue removido del Maps JS API en v3.65.
let drawMode=null, draftPts=[], draftLine=null, draftDots=[];
let lastOrdered=null, mapHome=null;   // para la segunda pantalla

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

  // dibujo manual: clic en el mapa agrega vértices; el botón "Terminar" cierra.
  map.addListener('click', e=>{ if(drawMode) addVertex(e.latLng); });
};

// ---------- dibujo manual (reemplaza al DrawingManager removido) ----------
function startDraw(m){
  clearShapes(true); cancelDraft(); $('rc-ficha').style.display='none';
  drawMode=m;
  map.setOptions({draggableCursor:'crosshair', disableDoubleClickZoom:true});
  $('rc-buffer-wrap').style.display = (m==='corr') ? '' : 'none';
  $('rc-draw-actions').style.display='flex';
  $('rc-poly').disabled=true; $('rc-corr').disabled=true;
  $('rc-draw-hint').textContent = 'Haz clic en el mapa para ir marcando '+(m==='poly'?'los vértices de la zona':'la ruta de la calle')+'. Cuando termines, pulsa "Terminar".';
}
function addVertex(ll){ draftPts.push(ll); redrawDraft(); $('rc-finish').disabled = draftPts.length < (drawMode==='poly'?3:2); }
function redrawDraft(){
  if(draftLine) draftLine.setMap(null);
  draftLine = new google.maps.Polyline({path:draftPts, map, strokeColor:'#005ab2', strokeWeight:2, strokeOpacity:.9});
  draftDots.forEach(d=>d.setMap(null)); draftDots=[];
  draftPts.forEach(p=>draftDots.push(new google.maps.Marker({position:p, map,
    icon:{path:google.maps.SymbolPath.CIRCLE, scale:4, fillColor:'#005ab2', fillOpacity:1, strokeColor:'#fff', strokeWeight:1.5}})));
}
function finishDraw(){
  const need = drawMode==='poly'?3:2;
  if(draftPts.length < need) return;
  const pts = draftPts.slice(); const wasPoly = drawMode==='poly';
  cancelDraft();
  if(wasPoly) drawnPoly = new google.maps.Polygon({paths:pts, map, fillColor:'#005ab2', fillOpacity:.10, strokeColor:'#005ab2', strokeWeight:2, zIndex:5});
  else        corridorLine = new google.maps.Polyline({path:pts, map, strokeColor:'#005ab2', strokeWeight:3, strokeOpacity:.9, zIndex:5});
  enableGen();
}
function cancelDraft(){
  drawMode=null; draftPts=[];
  if(draftLine){ draftLine.setMap(null); draftLine=null; }
  draftDots.forEach(d=>d.setMap(null)); draftDots=[];
  if(map) map.setOptions({draggableCursor:null, disableDoubleClickZoom:false});
  const da=$('rc-draw-actions'); if(da) da.style.display='none';
  $('rc-poly').disabled=false; $('rc-corr').disabled=false;
}

// ---------- clustering (una sola capa combinada) ----------
function rebuildCluster(){
  const list=[];
  for(const layer of Object.keys(LAYER_META)) if(enabled[layer]) list.push(...(markers[layer]||[]));
  if(!window.markerClusterer){ // fallback: sin librería, pinta directo
    for(const layer of Object.keys(LAYER_META)) (markers[layer]||[]).forEach(m=>m.setMap(enabled[layer]?map:null));
    return;
  }
  if(cluster){ cluster.clearMarkers(); } else { cluster = new markerClusterer.MarkerClusterer({map, markers:[]}); }
  cluster.addMarkers(list);
}

// ---------- carga de territorio ----------
let selSec=null;   // sección seleccionada (para adjuntar contexto electoral al trazo)

// Cascada Distrito -> Sección.
$('rc-dist').addEventListener('change', ()=>{
  const id=$('rc-dist').value; const sel=$('rc-sec');
  if(!id){ sel.innerHTML='<option value="">— elige primero un distrito —</option>'; sel.disabled=true; return; }
  const secs=DIST_SECS[id]||[];
  sel.innerHTML='<option value="">— todo el distrito —</option>'+secs.map(n=>'<option value="'+n+'">Sección '+n+'</option>').join('');
  sel.disabled = secs.length===0;
});

// Pinta el contorno de la zona elegida + indicador electoral. Sin cargar puntos.
async function ubicar(){
  const sv=$('rc-sec').value, dv=$('rc-dist').value, gv=$('rc-deleg').value;
  let param='';
  if(sv){ param='sec='+encodeURIComponent(sv); selSec=sv; }
  else if(dv){ param='dist='+encodeURIComponent(dv); selSec=null; }
  else if(gv){ param='deleg='+encodeURIComponent(gv); selSec=null; }
  else { $('rc-ctx').textContent='Elige un distrito (y opcionalmente una sección) o una delegación.'; return; }
  $('rc-load').disabled=true; $('rc-load').textContent='Pintando…';
  try{
    const r = await fetch(BASE+'/recorrido_data.php?geomonly=1&'+param, {headers:{'X-Requested-With':'fetch'}});
    const d = await r.json();
    if(!d.ok){ $('rc-ctx').textContent = d.error||'No se pudo pintar la zona.'; return; }
    outlineTerritory(d);
  }catch(e){ $('rc-ctx').textContent='Error de red.'; }
  finally{ $('rc-load').disabled=false; $('rc-load').textContent='Pintar la zona en el mapa'; }
}

function outlineTerritory(d){
  if(secLayer){ secLayer.setMap(null); secLayer=null; }
  if(d.geom){
    secLayer = new google.maps.Data();
    secLayer.addGeoJson({type:'Feature',geometry:d.geom,properties:{}});
    secLayer.setStyle({fillColor:'#005ab2',fillOpacity:.04,strokeColor:'#005ab2',strokeWeight:2,strokeOpacity:.7,clickable:false});
    secLayer.setMap(map);
    const b=new google.maps.LatLngBounds();
    secLayer.forEach(f=>f.getGeometry().forEachLatLng(ll=>b.extend(ll)));
    if(!b.isEmpty()) map.fitBounds(b);
  } else if(d.center){ map.setCenter(d.center); map.setZoom(14); }
  $('rc-ctx').innerHTML = '<b>'+esc(d.titulo)+'</b><br><span style="font-size:11px">Ahora traza tu polígono o corredor; se cargará solo lo que quede dentro.</span>';
  renderElec(d.elec);
}

// Indicador de afinidad partidista / rentabilidad electoral (por sección).
const PARTY_COLORS={PAN:'#0a4bce',MORENA:'#a6212b',MC:'#f58220',PRI:'#0f9d58',PVEM:'#7ac142',PT:'#d5202a',PRD:'#f4c20d',QS:'#00a3a3'};
function partyColor(c){ if(!c) return '#8a8a8a'; const u=String(c).toUpperCase(); for(const k in PARTY_COLORS){ if(u.indexOf(k)>=0) return PARTY_COLORS[k]; } return '#8a8a8a'; }
function renderElec(e){
  const box=$('rc-elec');
  if(!e){ box.style.display='none'; box.innerHTML=''; return; }
  const pan=(e.pan!=null)?e.pan:null;
  const rentColor = pan==null?'#999':(pan>=50?'#0a4bce':pan>=40?'#5b8fd0':pan>=30?'#e0872b':'#c0392b');
  box.style.display=''; box.className='rc-elec';
  box.innerHTML =
    '<div class="lbl">Indicador electoral · Ayuntamiento 2024</div>'+
    (e.gan?'<div class="row"><span>Afinidad (partido ganador)</span><span class="tag" style="background:'+partyColor(e.gan)+'">'+esc(e.gan)+(e.ganp!=null?' · '+e.ganp+'%':'')+'</span></div>':'')+
    (e.part!=null?'<div class="row"><span>Participación</span><b>'+e.part+'%</b></div>':'')+
    (pan!=null?'<div class="row" style="display:block"><div style="display:flex;justify-content:space-between"><span>Rentabilidad PAN</span><b>'+pan+'%</b></div><div class="bar"><span style="width:'+Math.min(100,Math.max(2,pan))+'%;background:'+rentColor+'"></span></div></div>':'')+
    (e.ln!=null?'<div class="row"><span>Lista nominal</span><b>'+Number(e.ln).toLocaleString('es-MX')+'</b></div>':'');
}

// Pinta los puntos devueltos (ya vienen filtrados por el trazo) + conteos.
function renderPoints(d){
  clearMarkers();
  for(const layer of Object.keys(LAYER_META)){
    const pts = d.layers[layer]||[];
    const cntEl = document.querySelector('[data-cnt="'+layer+'"]');
    if(cntEl) cntEl.textContent = d.layers[layer] ? pts.length : '—';
    markers[layer] = pts.map(p=>{
      const m = new google.maps.Marker({position:{lat:p.lat,lng:p.lng},
        icon:{path:google.maps.SymbolPath.CIRCLE, scale:6, fillColor:LAYER_META[layer].color, fillOpacity:.9, strokeColor:'#fff', strokeWeight:1.5},
        zIndex: layer==='tickets'?4:2});
      m._layer=layer; m._p=p;
      m.addListener('click', ()=>{ info.setContent(stopHtml(layer,p)); info.open(map,m); });
      return m;
    });
  }
  rebuildCluster();
}

function stopHtml(layer, p){
  const c=LAYER_META[layer].color;
  let t='', s='';
  if(layer==='tickets'){ t='Problema · '+esc(p.tipo); s=(p.dias!=null?p.dias+' días abierto':'')+(p.vencido?' · <b style="color:#dc2626">VENCIDO</b>':'')+(p.dir?'<br>'+esc(p.dir):'')+' · ticket #'+p.id; }
  else if(layer==='dif'){ t='Beneficiario DIF';
    s=(p.nombre?'<b>'+esc(p.nombre)+'</b>':'(sin nombre)')+(p.col?' · '+esc(p.col):'')
     +(p.n?'<br>'+p.n+' apoyo'+(p.n>1?'s':''):'')
     +(p.apoyos&&p.apoyos.length?'<br>· '+p.apoyos.map(esc).join('<br>· '):''); }
  else if(layer==='bloque'){ t='Beneficiario Bloque';
    s=(p.nombre?'<b>'+esc(p.nombre)+'</b>':'(sin nombre)')+(p.emp?' · '+esc(p.emp):'')
     +(p.dir?'<br>'+esc(p.dir):'')+(p.deleg?'<br>'+esc(p.deleg):''); }
  else if(layer==='obras'){ t='Obra · '+esc(p.estatus||''); s=esc(p.n||'')+(p.inv?'<br>Inversión: $'+Number(p.inv).toLocaleString('es-MX'):''); }
  else if(layer==='areas'){ t='Área verde'; s=esc(p.n||''); }
  return '<div style="font:13px/1.5 Montserrat,sans-serif;max-width:240px"><b style="color:'+c+'">'+t+'</b><br>'+s+'</div>';
}

// ---------- capas on/off (palomear) ----------
document.querySelectorAll('.rc-cb').forEach(cb=>{
  cb.addEventListener('change', ()=>{
    const layer=cb.dataset.layer; enabled[layer]=cb.checked;
    cb.closest('label').style.opacity = cb.checked?'1':'.5';
    rebuildCluster();
  });
});

$('rc-load').addEventListener('click', ubicar);

// ---------- herramientas de dibujo (manual) ----------
$('rc-poly').addEventListener('click', ()=>{ if(map) startDraw('poly'); });
$('rc-corr').addEventListener('click', ()=>{ if(map) startDraw('corr'); });
$('rc-finish').addEventListener('click', finishDraw);
$('rc-cancel').addEventListener('click', ()=>{ cancelDraft(); enableGen(); });
$('rc-clear').addEventListener('click', ()=>{ clearShapes(true); cancelDraft(); $('rc-ficha').style.display='none'; enableGen(); });
$('rc-gen').addEventListener('click', generar);

function clearShapes(clearRoute){
  if(drawnPoly){ drawnPoly.setMap(null); drawnPoly=null; }
  if(corridorLine){ corridorLine.setMap(null); corridorLine=null; }
  if(clearRoute && routeLine){ routeLine.setMap(null); routeLine=null; }
}
function enableGen(){
  const hasShape = !!(drawnPoly||corridorLine);
  $('rc-gen').disabled = !hasShape;
  $('rc-draw-hint').textContent = hasShape
    ? 'Listo: al generar se cargan las capas palomeadas SOLO dentro de tu trazo.'
    : 'Dibuja un polígono o un corredor sobre el mapa (paso 3).';
}

// ---------- generar recorrido (carga solo lo que hay en el trazo) ----------
function metersBetween(a,b){ return google.maps.geometry.spherical.computeDistanceBetween(a,b); }

async function generar(){
  if(!(drawnPoly||corridorLine)){ alert('Primero traza un polígono o un corredor.'); return; }
  selSec = $('rc-sec').value || selSec || null;   // contexto electoral de la sección elegida
  const layers = Object.keys(LAYER_META).filter(k=>enabled[k]);
  if(!layers.length){ alert('Palomea al menos una capa.'); return; }
  let param = 'shape='+(drawnPoly?'poly':'corr')
            + '&layers='+encodeURIComponent(layers.join(','))
            + '&dias='+encodeURIComponent($('rc-dias').value||'30');
  if(selSec) param += '&sec='+encodeURIComponent(selSec);
  if(drawnPoly){
    const pts = drawnPoly.getPath().getArray().map(ll=>ll.lat().toFixed(6)+','+ll.lng().toFixed(6)).join(';');
    param += '&poly='+encodeURIComponent(pts);
  } else {
    const pts = corridorLine.getPath().getArray().map(ll=>ll.lat().toFixed(6)+','+ll.lng().toFixed(6)).join(';');
    param += '&line='+encodeURIComponent(pts)+'&buffer='+encodeURIComponent(Math.max(20,+$('rc-buffer').value||150));
  }
  $('rc-gen').disabled=true; $('rc-gen').textContent='Cargando…';
  try{
    const r = await fetch(BASE+'/recorrido_data.php?'+param, {headers:{'X-Requested-With':'fetch'}});
    const d = await r.json();
    if(!d.ok){ alert(d.error||'No se pudieron cargar los datos.'); return; }
    TERR=d;
    renderPoints(d);
    const stops=[];
    for(const layer of layers) for(const p of (d.layers[layer]||[])) stops.push({layer, p, pos:new google.maps.LatLng(p.lat,p.lng)});
    if(!stops.length){ alert('No hay puntos (de las capas palomeadas) dentro de tu trazo.'); $('rc-ficha').style.display='none'; return; }
    routeStops(stops);
  }catch(e){ alert('Error de red al cargar el trazo.'); }
  finally{ $('rc-gen').disabled=false; $('rc-gen').textContent='Generar recorrido'; }
}

function routeStops(stops){
  // orden vecino-más-cercano desde el punto más al noroeste
  let start=0, best=1e9;
  stops.forEach((s,i)=>{ const v=-s.pos.lat()+s.pos.lng(); if(v<best){best=v;start=i;} });
  const ordered=[]; const used=new Array(stops.length).fill(false);
  let cur=start; used[cur]=true; ordered.push(stops[cur]);
  for(let k=1;k<stops.length;k++){
    let nn=-1, nd=1e18;
    for(let j=0;j<stops.length;j++){ if(used[j])continue; const dd=metersBetween(stops[cur].pos,stops[j].pos); if(dd<nd){nd=dd;nn=j;} }
    used[nn]=true; ordered.push(stops[nn]); cur=nn;
  }
  if(routeLine) routeLine.setMap(null);
  routeLine = new google.maps.Polyline({path:ordered.map(o=>o.pos), map,
    strokeColor:'#005ab2', strokeOpacity:.9, strokeWeight:3, icons:[{icon:{path:google.maps.SymbolPath.FORWARD_CLOSED_ARROW},offset:'50%',repeat:'120px'}]});
  // zoom a la ruta
  const b=new google.maps.LatLngBounds(); ordered.forEach(o=>b.extend(o.pos));
  if(!b.isEmpty()) map.fitBounds(b, {top:40,bottom:40,left:40,right:40});
  renderFicha(ordered);
}

function renderFicha(ordered){
  lastOrdered = ordered;
  const dist = google.maps.geometry.spherical.computeLength(ordered.map(o=>o.pos)); // metros
  const walkMin = dist/1.35/60;                 // ~1.35 m/s caminando
  const stopMin = ordered.length*2;             // ~2 min por parada
  const cnt={tickets:0,dif:0,bloque:0,obras:0,areas:0}; ordered.forEach(o=>cnt[o.layer]++);
  // resumen
  $('rc-sum').innerHTML =
    box(ordered.length,'paradas')+
    box((dist/1000).toFixed(2)+' km','distancia')+
    box(Math.round(walkMin+stopMin)+' min','tiempo estimado')+
    box(cnt.tickets,'problemas')+
    box(cnt.dif+cnt.bloque,'beneficiarios')+
    box(cnt.obras+cnt.areas,'obras/áreas');
  // territorio
  let terr = TERR? ('<b>'+esc(TERR.titulo)+'</b>'+(TERR.part!=null?' · participación '+TERR.part+'%':'')+(TERR.gan?' · ganó '+esc(TERR.gan):'')) : '';
  $('rc-ficha-terr').innerHTML = terr;
  // paradas
  $('rc-stops').innerHTML = ordered.map((o,i)=>{
    const meta=LAYER_META[o.layer]; const p=o.p; let t='',s='';
    if(o.layer==='tickets'){ t='Problema · '+esc(p.tipo); s=(p.dias!=null?p.dias+'d abierto':'')+(p.vencido?' · VENCIDO':'')+(p.dir?' · '+esc(p.dir):''); }
    else if(o.layer==='dif'){ t='Beneficiario DIF'; s=(p.nombre?esc(p.nombre):'(sin nombre)')+(p.n>1?' · '+p.n+' apoyos':(p.apoyos&&p.apoyos.length?' · '+esc(p.apoyos[0]):'')); }
    else if(o.layer==='bloque'){ t='Beneficiario Bloque'; s=(p.nombre?esc(p.nombre):(p.emp?esc(p.emp):'—'))+(p.nombre&&p.emp?' · '+esc(p.emp):''); }
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

$('rc-print-btn').addEventListener('click', imprimirFicha);

// ---------- segunda pantalla (presentación del recorrido) ----------
function scStopHtml(o,i){
  const meta=LAYER_META[o.layer]; const p=o.p; let t='',s='';
  if(o.layer==='tickets'){ t='Problema · '+esc(p.tipo); s=(p.dias!=null?p.dias+'d abierto':'')+(p.vencido?' · <b style="color:#dc2626">VENCIDO</b>':'')+(p.dir?'<br>'+esc(p.dir):''); }
  else if(o.layer==='dif'){ t='Beneficiario DIF'; s=(p.nombre?'<b>'+esc(p.nombre)+'</b>':'(sin nombre)')+(p.n>1?' · '+p.n+' apoyos':'')+(p.apoyos&&p.apoyos.length?'<br>'+p.apoyos.map(esc).join(' · '):''); }
  else if(o.layer==='bloque'){ t='Beneficiario Bloque'; s=(p.nombre?'<b>'+esc(p.nombre)+'</b>':(p.emp?esc(p.emp):''))+(p.emp&&p.nombre?' · '+esc(p.emp):'')+(p.dir?'<br>'+esc(p.dir):''); }
  else if(o.layer==='obras'){ t='Obra · '+esc(p.estatus||''); s=esc(p.n||''); }
  else { t='Área verde'; s=esc(p.n||''); }
  return '<div class="rc-stop"><div class="num" style="background:'+meta.color+'">'+(i+1)+'</div><div><div class="t">'+t+'</div><div class="s">'+s+'</div></div></div>';
}
function openScreen(){
  if(!lastOrdered||!lastOrdered.length) return;
  const rcMap=$('rc-map'); if(!mapHome) mapHome=rcMap.parentElement;
  $('rc-screen-map').appendChild(rcMap);
  $('rc-screen').classList.add('open');
  $('sc-title').textContent = TERR ? TERR.titulo : 'Recorrido';
  const sums=[...document.querySelectorAll('#rc-sum .b')].map(b=>'<span class="sc-chip">'+b.querySelector('.v').textContent+' '+b.querySelector('.l').textContent+'</span>').join('');
  $('sc-chips').innerHTML = sums
    + (TERR&&TERR.part!=null?'<span class="sc-chip">participación '+TERR.part+'%</span>':'')
    + (TERR&&TERR.gan?'<span class="sc-chip">ganó '+esc(TERR.gan)+'</span>':'');
  $('sc-gmaps').href = $('rc-gmaps').href;
  $('sc-list').innerHTML = lastOrdered.map(scStopHtml).join('');
  setTimeout(()=>{ google.maps.event.trigger(map,'resize');
    const b=new google.maps.LatLngBounds(); lastOrdered.forEach(o=>b.extend(o.pos));
    if(!b.isEmpty()) map.fitBounds(b,{top:50,bottom:50,left:50,right:50}); }, 80);
}
function closeScreen(){
  const rcMap=$('rc-map'); if(mapHome) mapHome.appendChild(rcMap);
  $('rc-screen').classList.remove('open');
  setTimeout(()=>google.maps.event.trigger(map,'resize'), 80);
}
$('rc-screen-btn').addEventListener('click', openScreen);
$('sc-close').addEventListener('click', closeScreen);
$('sc-print').addEventListener('click', imprimirFicha);
document.addEventListener('keydown', e=>{ if(e.key==='Escape' && $('rc-screen').classList.contains('open')) closeScreen(); });

function clearMarkers(){ if(cluster) cluster.clearMarkers(); for(const k of Object.keys(markers)){ (markers[k]||[]).forEach(m=>m.setMap(null)); markers[k]=[]; } }

if(!HASKEY){ $('rc-map').innerHTML='<div style="padding:20px;color:#991B1B">Google Maps API key no configurada.</div>'; }
</script>
<!-- MarkerClusterer (agrupa los pines para que no trabe el mapa) — antes del API -->
<script src="https://unpkg.com/@googlemaps/markerclusterer@2.5.3/dist/index.min.js"></script>
<?php if ($apiKey): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($apiKey) ?>&libraries=geometry&callback=initRcMap&loading=async&v=weekly"></script>
<?php endif; ?>
<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
