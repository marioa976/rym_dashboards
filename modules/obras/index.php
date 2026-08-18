<?php
/**
 * Obras · Reporte geográfico (POA 2024-2026).
 *  - Mapa con un marcador por obra: color = estatus, tamaño = inversión ejercida.
 *  - Límites delegacionales oficiales (reusa delegaciones_geo) con toggle.
 *  - KPIs (obras, inversión, con ubicación, % terminadas) y filtros por año,
 *    rubro, estatus y delegación + búsqueda.
 *  - Tabla con inversión, avance y estatus; clic → ubicar en el mapa.
 * Coordenadas extraídas de los links de Google Maps del archivo fuente.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';     // guard: require_module('obras')
require_once __DIR__ . '/lib.php';

$cfg    = obr_config();
$apiKey = $cfg['google_maps_api_key'] ?? '';
$nombre = htmlspecialchars((string)(Auth::user()['nombre'] ?? 'usuario'));

$obras = []; $kpis = ['total'=>0,'con_coords'=>0,'inversion'=>0.0,'ben_dir'=>0,'terminadas'=>0,'anios'=>0];
$limites = []; $dbError = null;
try {
    $pdo     = obr_pdo();
    $obras   = obr_obras($pdo);
    $kpis    = obr_kpis($pdo);
    $limites = obr_limites($pdo);
} catch (Throwable $e) { $dbError = $e->getMessage(); }

$invMDP  = $kpis['inversion'] / 1e6;
$pctTerm = $kpis['total'] > 0 ? round($kpis['terminadas'] / $kpis['total'] * 100) : 0;
?><?php
$ktTitle  = 'Obras · POA 2024-2026';
$ktActive = 'obras';
$ktFluid = true;
require __DIR__ . '/../../views/layout/kt_top.php';
?>
<style>
    .ob-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:12px;margin-bottom:16px}
    .ob-kpi{background:#fff;border:1px solid var(--qro-border);border-radius:12px;padding:14px 16px}
    .ob-kpi .v{font-size:23px;font-weight:800;color:#a8481f;line-height:1.1}
    .ob-kpi .l{font-size:12px;color:var(--qro-text-secondary);font-weight:600;margin-top:2px}
    .ob-tools{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:12px}
    #ob-map{height:clamp(520px,calc(100vh - 250px),880px);border-radius:12px;border:1px solid var(--qro-border);overflow:hidden}
    .ob-legend{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}
    .ob-chip{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;
      border:1px solid var(--qro-border);background:#fff;border-radius:999px;padding:5px 11px;cursor:pointer;
      color:var(--qro-text-secondary);user-select:none}
    .ob-chip.off{opacity:.4}
    .ob-chip i{width:12px;height:12px;border-radius:50%;display:inline-block}
    .ob-chip b{color:var(--qro-text-primary);font-weight:700}
    .ob-wrap{display:grid;grid-template-columns:1fr 460px;gap:16px}
    @media(max-width:1100px){.ob-wrap{grid-template-columns:1fr}}
    .ob-tablecard{background:#fff;border:1px solid var(--qro-border);border-radius:12px;overflow:hidden;max-height:600px;display:flex;flex-direction:column}
    .ob-tablecard h3{margin:0;padding:12px 16px;border-bottom:1px solid var(--qro-border);font-size:14px}
    .ob-tablescroll{overflow:auto}
    table.ob-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
    table.ob-tbl th,table.ob-tbl td{text-align:left;padding:7px 10px;border-bottom:1px solid #eef0f2;white-space:nowrap}
    table.ob-tbl th{position:sticky;top:0;background:#fbf5f2;z-index:1;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:var(--qro-text-secondary)}
    table.ob-tbl td.n{white-space:normal;min-width:210px}
    table.ob-tbl td.num{text-align:right;font-variant-numeric:tabular-nums}
    table.ob-tbl tr:hover td{background:#fbf3ef}
    .ob-loc{cursor:pointer;color:#a8481f;font-weight:700;text-decoration:none}
    .ob-badge{font-size:10px;font-weight:700;padding:1px 6px;border-radius:6px;color:#fff;white-space:nowrap}
    .ob-empty{padding:24px;text-align:center;color:var(--qro-text-secondary);font-size:13px}
  </style>
  <div style="background:linear-gradient(120deg,#7a3315,#c85a2b);color:#fff;border-radius:16px;padding:22px 26px;margin-bottom:20px">
    <h1 style="color:#fff;margin:0 0 4px;font-size:23px">🏗 Obras · POA 2024–2026</h1>
    <p style="margin:0;opacity:.92;font-size:14px">Hola, <?= $nombre ?>. Reporte geográfico de obra pública municipal: ubicación, inversión y avance.</p>
  </div>

  <?php if ($dbError): ?>
    <div class="alert alert-danger">No se pudieron cargar los datos.<br><span style="font-size:12px"><?= htmlspecialchars($dbError) ?></span></div>
  <?php endif; ?>
  <?php if (!$apiKey): ?>
    <div class="alert alert-danger">Falta <code>GOOGLE_MAPS_API_KEY</code>: el mapa no se mostrará, pero el listado sí.</div>
  <?php endif; ?>

  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mb-4">
    <div class="kt-card"><div class="kt-card-content p-4 flex flex-col gap-0.5"><span class="text-2xl font-bold text-primary leading-tight"><?= number_format($kpis['total']) ?></span><span class="text-xs text-secondary-foreground font-semibold">Obras (POA <?= $kpis['anios'] ?> años)</span></div></div>
    <div class="kt-card"><div class="kt-card-content p-4 flex flex-col gap-0.5"><span class="text-2xl font-bold text-primary leading-tight" title="$<?= number_format($kpis['inversion'],2) ?>">$<?= number_format($invMDP,1) ?> <span class="text-sm">MDP</span></span><span class="text-xs text-secondary-foreground font-semibold">Inversión ejercida</span></div></div>
    <div class="kt-card"><div class="kt-card-content p-4 flex flex-col gap-0.5"><span class="text-2xl font-bold text-primary leading-tight"><?= number_format($kpis['con_coords']) ?> <span class="text-sm text-muted-foreground">/ <?= number_format($kpis['total']) ?></span></span><span class="text-xs text-secondary-foreground font-semibold">Con ubicación en el mapa</span></div></div>
    <div class="kt-card"><div class="kt-card-content p-4 flex flex-col gap-0.5"><span class="text-2xl font-bold text-primary leading-tight"><?= $pctTerm ?>%</span><span class="text-xs text-secondary-foreground font-semibold"><?= number_format($kpis['terminadas']) ?> terminadas</span></div></div>
    <div class="kt-card"><div class="kt-card-content p-4 flex flex-col gap-0.5"><span class="text-2xl font-bold text-primary leading-tight" id="k-vista"><?= number_format($kpis['total']) ?></span><span class="text-xs text-secondary-foreground font-semibold" id="k-vista-inv">En vista (filtro)</span></div></div>
  </div>

  <div class="ob-tools">
    <select id="f-anio"  class="input"><option value="">Todos los años</option></select>
    <select id="f-rubro" class="input"><option value="">Todos los rubros</option></select>
    <select id="f-est"   class="input"><option value="">Todos los estatus</option></select>
    <select id="f-deleg" class="input"><option value="">Todas las delegaciones</option></select>
    <input  id="f-buscar" class="input" type="search" placeholder="Buscar nombre o clave…">
    <button id="f-reset" class="btn btn-secondary" type="button">Limpiar</button>
  </div>

  <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-bottom:2px">
    <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--qro-text-secondary);cursor:pointer">
      <input type="checkbox" id="t-limites" checked> Mostrar límites delegacionales
    </label>
    <span style="font-size:12px;color:var(--qro-text-muted)">Color = estatus · tamaño = inversión. Clic en un estatus para filtrarlo.</span>
  </div>
  <div class="ob-legend" id="ob-legend"></div>

  <div class="ob-wrap">
    <div><div id="ob-map"></div></div>
    <div class="ob-tablecard">
      <h3>Listado <span id="tbl-count" class="text-secondary" style="font-weight:500"></span></h3>
      <div class="ob-tablescroll">
        <table class="ob-tbl">
          <thead><tr><th>Clave</th><th>Obra</th><th>Rubro</th><th>Deleg.</th><th class="num">Inversión</th><th>Estatus</th><th>Ubicar</th></tr></thead>
          <tbody id="tbl-body"></tbody>
        </table>
        <div class="ob-empty" id="tbl-empty" style="display:none">Sin resultados para el filtro.</div>
      </div>
    </div>
  </div>
<script>
const OBRAS   = <?= json_encode($obras, JSON_UNESCAPED_UNICODE) ?>;
const LIMITES = <?= json_encode($limites, JSON_UNESCAPED_UNICODE) ?>;
const HASKEY  = <?= $apiKey ? 'true' : 'false' ?>;
const $ = id => document.getElementById(id);
function esc(s){ return String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
function fmtMoney(v){ if(v==null) return '—'; if(v>=1e6) return '$'+(v/1e6).toFixed(1)+' M'; return '$'+Math.round(v).toLocaleString(); }
function fmtPct(v){ return v==null?'—':Math.round(v*100)+'%'; }

/* Colores por estatus (paleta fija) y delegación (para los límites) */
const EST_COLOR = {'TERMINADA':'#2e7d32','EN EJECUCIÓN':'#1565c0','EN LICITACIÓN':'#f9a825','EN SUSPENSIÓN':'#c62828'};
function estColor(s){ return EST_COLOR[s] || '#757575'; }
const PALETA = ['#2e9e5b','#005ab2','#e0872b','#8e44ad','#159c9c','#c0392b','#7a8b1f','#d43f8d'];
const DELEGS = [...new Set(LIMITES.map(f=>f.properties.d))].sort();
const DCOLOR = {}; DELEGS.forEach((d,i)=> DCOLOR[d]=PALETA[i%PALETA.length]);
const maxE = Math.max(1, ...OBRAS.map(o=>o.e||0));

/* Filtros */
const hidden = new Set();          // estatus ocultos (leyenda)
function activos(){
  const fa=$('f-anio').value, fr=$('f-rubro').value, fe=$('f-est').value, fd=$('f-deleg').value;
  const q=$('f-buscar').value.trim().toLowerCase();
  return OBRAS.filter(o =>
    (!fa || String(o.y)===fa) && (!fr || o.r===fr) && (!fe || o.s===fe) && (!fd || o.d===fd) &&
    !hidden.has(o.s) &&
    (!q || (o.n||'').toLowerCase().includes(q) || (o.c||'').toLowerCase().includes(q))
  );
}
function fill(id,vals,label){ $(id).innerHTML=`<option value="">${label}</option>`+vals.map(v=>`<option>${esc(v)}</option>`).join(''); }
fill('f-anio',[...new Set(OBRAS.map(o=>o.y))].sort((a,b)=>b-a),'Todos los años');
fill('f-rubro',[...new Set(OBRAS.map(o=>o.r).filter(Boolean))].sort(),'Todos los rubros');
fill('f-est',[...new Set(OBRAS.map(o=>o.s).filter(Boolean))],'Todos los estatus');
fill('f-deleg',[...new Set(OBRAS.map(o=>o.d).filter(Boolean))].sort(),'Todas las delegaciones');

/* Leyenda por estatus */
function renderLegend(){
  const cnt={}; OBRAS.forEach(o=>cnt[o.s]=(cnt[o.s]||0)+1);
  const orden=Object.keys(EST_COLOR).filter(s=>cnt[s]).concat(Object.keys(cnt).filter(s=>!(s in EST_COLOR)));
  $('ob-legend').innerHTML = orden.map(s=>
    `<span class="ob-chip ${hidden.has(s)?'off':''}" data-s="${esc(s)}"><i style="background:${estColor(s)}"></i>${esc(s)} <b>${cnt[s]||0}</b></span>`).join('');
  $('ob-legend').querySelectorAll('.ob-chip').forEach(ch=>ch.addEventListener('click',()=>{
    const s=ch.dataset.s; if(hidden.has(s)) hidden.delete(s); else hidden.add(s);
    ch.classList.toggle('off',hidden.has(s)); refresh();
  }));
}

/* Tabla */
function renderTable(list){
  $('tbl-body').innerHTML = list.map(o=>
    `<tr>
       <td>${esc(o.c)}<div style="font-size:10px;color:#999">${o.y}</div></td>
       <td class="n">${esc(o.n)}</td>
       <td>${esc(o.r||'')}</td>
       <td>${esc(o.d||'—')}${o.dl==='VARIAS'?' <span style="font-size:10px;color:#999">(varias)</span>':''}</td>
       <td class="num">${fmtMoney(o.e)}</td>
       <td><span class="ob-badge" style="background:${estColor(o.s)}">${esc(o.s||'—')}</span><div style="font-size:10px;color:#777;margin-top:2px">avance ${fmtPct(o.av)}</div></td>
       <td>${o.lat!=null?`<a class="ob-loc" data-id="${o.id}">📍</a> `:''}${o.u?`<a href="${esc(o.u)}" target="_blank" rel="noopener" style="color:#999" title="Google Maps">↗</a>`:''}</td>
     </tr>`).join('');
  $('tbl-empty').style.display = list.length?'none':'block';
  $('tbl-count').textContent = '· '+list.length.toLocaleString();
  $('tbl-body').querySelectorAll('.ob-loc').forEach(el=>el.addEventListener('click',()=>localizar(+el.dataset.id)));
}

/* Mapa */
let map, info, markers=new Map(), boundary=null, labels=[], showLimites=true;
window.initObMap = function(){
  map=new google.maps.Map($('ob-map'),{center:{lat:20.59,lng:-100.39},zoom:11,
    mapTypeControl:false,streetViewControl:false,fullscreenControl:true,
    styles:[{featureType:'poi',stylers:[{visibility:'off'}]}]});
  info=new google.maps.InfoWindow();
  drawBoundaries();
  refresh();
};
function ringCentroid(geom){
  let c = geom.type==='Polygon'?geom.coordinates[0]:geom.type==='MultiPolygon'?geom.coordinates[0][0]:null;
  if(!c||!c.length) return null; let sx=0,sy=0; c.forEach(p=>{sx+=p[0];sy+=p[1];});
  return {lat:sy/c.length,lng:sx/c.length};
}
function drawBoundaries(){
  if(!map||!LIMITES.length) return;
  boundary=new google.maps.Data();
  boundary.addGeoJson({type:'FeatureCollection',features:LIMITES});
  boundary.setStyle(f=>{ const c=DCOLOR[f.getProperty('d')]||'#888';
    return {strokeColor:c,strokeWeight:1.6,strokeOpacity:.75,fillColor:c,fillOpacity:.04,clickable:false}; });
  LIMITES.forEach(ft=>{ const c=ringCentroid(ft.geometry); if(!c) return;
    labels.push(new google.maps.Marker({position:c,clickable:false,
      icon:{path:google.maps.SymbolPath.CIRCLE,scale:0,strokeOpacity:0,fillOpacity:0},
      label:{text:ft.properties.d,color:DCOLOR[ft.properties.d]||'#333',fontSize:'11px',fontWeight:'700'}})); });
  applyLimitesVis();
}
function applyLimitesVis(){ if(boundary) boundary.setMap(showLimites?map:null); labels.forEach(l=>l.setMap(showLimites?map:null)); }

function markerIcon(o){
  const r = 5 + Math.sqrt((o.e||0)/maxE)*13;   // tamaño ~ inversión
  return {path:google.maps.SymbolPath.CIRCLE, scale:r, fillColor:estColor(o.s), fillOpacity:.82,
          strokeColor:'#fff', strokeWeight:1.3};
}
function infoHtml(o){
  return `<div style="font-family:Montserrat,Arial,sans-serif;max-width:260px">
    <div style="font-weight:800;color:#7a3315;margin-bottom:2px">${esc(o.n)}</div>
    <div style="font-size:11px;color:#888;margin-bottom:6px">${esc(o.c)} · ${o.y} · ${esc(o.r||'')}</div>
    <div style="font-size:12px"><span class="ob-badge" style="background:${estColor(o.s)}">${esc(o.s||'—')}</span> &nbsp;avance <b>${fmtPct(o.av)}</b></div>
    <div style="font-size:12px;margin-top:4px">💰 Inversión: <b>${fmtMoney(o.e)}</b></div>
    <div style="font-size:12px">📍 ${esc(o.d||'—')}${o.col?` · ${esc(o.col)}`:''}</div>
    ${o.bd!=null?`<div style="font-size:12px">👥 Beneficiarios directos: ${o.bd.toLocaleString()}</div>`:''}
    ${o.u?`<a href="${esc(o.u)}" target="_blank" rel="noopener" style="font-size:12px;color:#a8481f;font-weight:700;text-decoration:none">Abrir en Google Maps ↗</a>`:''}
  </div>`;
}
function drawMap(list){
  if(!map) return;
  markers.forEach(m=>m.setMap(null)); markers.clear();
  const b=new google.maps.LatLngBounds(); let n=0;
  list.forEach(o=>{
    if(o.lat==null) return;
    const mk=new google.maps.Marker({position:{lat:o.lat,lng:o.lng},map,title:o.n,icon:markerIcon(o)});
    mk.addListener('click',()=>{ info.setContent(infoHtml(o)); info.open(map,mk); });
    markers.set(o.id,mk); b.extend(mk.getPosition()); n++;
  });
  if(n && !b.isEmpty()) map.fitBounds(b);
}
function localizar(id){
  const o=OBRAS.find(x=>x.id===id); if(!o||o.lat==null) return;
  if(!map){ window.open(o.u||`https://www.google.com/maps?q=${o.lat},${o.lng}`,'_blank'); return; }
  map.panTo({lat:o.lat,lng:o.lng}); map.setZoom(16);
  const mk=markers.get(id); if(mk){ info.setContent(infoHtml(o)); info.open(map,mk); }
  $('ob-map').scrollIntoView({behavior:'smooth',block:'center'});
}

/* Orquestación */
function refresh(){
  const list=activos();
  const inv=list.reduce((s,o)=>s+(o.e||0),0);
  $('k-vista').textContent=list.length.toLocaleString();
  $('k-vista-inv').textContent='En vista · '+fmtMoney(inv);
  renderTable(list); drawMap(list);
}
['f-anio','f-rubro','f-est','f-deleg'].forEach(id=>$(id).addEventListener('change',refresh));
$('f-buscar').addEventListener('input',refresh);
$('f-reset').addEventListener('click',()=>{ ['f-anio','f-rubro','f-est','f-deleg','f-buscar'].forEach(id=>$(id).value=''); hidden.clear(); renderLegend(); refresh(); });
const tLim=$('t-limites'); if(tLim) tLim.addEventListener('change',()=>{ showLimites=tLim.checked; applyLimitesVis(); });

renderLegend();
renderTable(OBRAS);
if(!HASKEY){ $('ob-map').innerHTML='<div style="padding:20px;color:#991B1B">Google Maps API key no configurada.</div>'; }
</script>
<?php if ($apiKey): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($apiKey) ?>&callback=initObMap&loading=async&v=weekly"></script>
<?php endif; ?>
<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
