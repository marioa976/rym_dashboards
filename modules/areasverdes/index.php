<?php
/**
 * Áreas Verdes · Reporte geográfico.
 *  - Mapa con un marcador por área verde, coloreado por delegación (leyenda que
 *    filtra al hacer clic). Áreas con varios vértices se dibujan como polígono.
 *  - KPIs (total, delegaciones, delegación con más, en vista).
 *  - Tabla buscable; cada fila localiza el área en el mapa y enlaza a Google Maps.
 * Datos: portal_qro.areas_verdes (coordenadas del listado oficial; sin geocodificar).
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';     // guard: require_module('areasverdes')
require_once __DIR__ . '/lib.php';

$cfg    = av_config();
$apiKey = $cfg['google_maps_api_key'] ?? '';
$nombre = htmlspecialchars((string)(Auth::user()['nombre'] ?? 'usuario'));

$areas = []; $porDeleg = []; $limites = []; $nDisc = 0; $dbError = null;
try {
    $pdo      = av_pdo();
    $areas    = av_areas($pdo);
    $porDeleg = av_por_delegacion($pdo);
    $limites  = av_limites($pdo);          // FeatureCollection de límites oficiales
    $nDisc    = av_num_discrepancias($pdo);
} catch (Throwable $e) { $dbError = $e->getMessage(); }

$total   = count($areas);
$nDeleg  = count($porDeleg);
$topDel  = $porDeleg[0] ?? null;
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Áreas Verdes · Reporte geográfico</title>
  <link rel="stylesheet" href="../../assets/css/qro.css">
  <style>
    .av-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px}
    .av-kpi{background:#fff;border:1px solid var(--qro-border);border-radius:12px;padding:14px 16px}
    .av-kpi .v{font-size:24px;font-weight:800;color:#1f7a45;line-height:1.1}
    .av-kpi .l{font-size:12px;color:var(--qro-text-secondary);font-weight:600;margin-top:2px}
    .av-tools{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
    .av-tools .input{max-width:280px}
    #av-map{height:600px;border-radius:12px;border:1px solid var(--qro-border);overflow:hidden}
    .av-legend{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}
    .av-chip{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;
      border:1px solid var(--qro-border);background:#fff;border-radius:999px;padding:5px 11px;cursor:pointer;
      color:var(--qro-text-secondary);user-select:none}
    .av-chip.off{opacity:.4}
    .av-chip i{width:12px;height:12px;border-radius:50%;display:inline-block}
    .av-chip b{color:var(--qro-text-primary);font-weight:700}
    .av-wrap{display:grid;grid-template-columns:1fr 420px;gap:16px}
    @media(max-width:1050px){.av-wrap{grid-template-columns:1fr}}
    .av-tablecard{background:#fff;border:1px solid var(--qro-border);border-radius:12px;overflow:hidden;max-height:600px;display:flex;flex-direction:column}
    .av-tablecard h3{margin:0;padding:12px 16px;border-bottom:1px solid var(--qro-border);font-size:14px}
    .av-tablescroll{overflow:auto}
    table.av-tbl{width:100%;border-collapse:collapse;font-size:13px}
    table.av-tbl th,table.av-tbl td{text-align:left;padding:8px 12px;border-bottom:1px solid #eef0f2;white-space:nowrap}
    table.av-tbl th{position:sticky;top:0;background:#f7faf8;z-index:1;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--qro-text-secondary)}
    table.av-tbl td.n{white-space:normal;min-width:180px}
    table.av-tbl tr:hover td{background:#f3faf5}
    .av-loc{cursor:pointer;color:#1f7a45;font-weight:700;text-decoration:none}
    .av-gm{color:var(--qro-text-secondary);text-decoration:none}
    .av-gm:hover{color:#1f7a45}
    .av-empty{padding:24px;text-align:center;color:var(--qro-text-secondary);font-size:13px}
  </style>
</head>
<body>
<?php $portalModulo = 'Áreas Verdes'; $navActive = 'home'; include __DIR__ . '/_nav.php'; ?>

<main class="content" style="padding:28px 32px">
  <div style="background:linear-gradient(120deg,#155d34,#2e9e5b);color:#fff;border-radius:16px;padding:22px 26px;margin-bottom:20px">
    <h1 style="color:#fff;margin:0 0 4px;font-size:23px">🌳 Áreas Verdes</h1>
    <p style="margin:0;opacity:.92;font-size:14px">Hola, <?= $nombre ?>. Reporte geográfico de las áreas verdes municipales por delegación.</p>
  </div>

  <?php if ($dbError): ?>
    <div class="alert alert-danger">No se pudieron cargar los datos.<br><span style="font-size:12px"><?= htmlspecialchars($dbError) ?></span></div>
  <?php endif; ?>
  <?php if (!$apiKey): ?>
    <div class="alert alert-danger">Falta <code>GOOGLE_MAPS_API_KEY</code>: el mapa no se mostrará, pero el listado sí.</div>
  <?php endif; ?>
  <?php if ($nDisc > 0): ?>
    <div class="alert" style="background:#fff7e6;border:1px solid #f0d98a;color:#7a5b00" id="av-disc">
      ⚠️ <strong><?= $nDisc ?></strong> área<?= $nDisc==1?'':'s' ?> con delegación del listado distinta a la geometría oficial.
      El mapa y los conteos usan la <strong>delegación real (geometría)</strong>; el listado se conserva como referencia.
      <a href="#" id="av-disc-link" style="color:#7a5b00;font-weight:700">Ver cuáles ↓</a>
    </div>
  <?php endif; ?>

  <div class="av-kpis">
    <div class="av-kpi"><div class="v"><?= number_format($total) ?></div><div class="l">Áreas verdes mapeadas</div></div>
    <div class="av-kpi"><div class="v"><?= number_format($nDeleg) ?></div><div class="l">Delegaciones</div></div>
    <div class="av-kpi"><div class="v" style="font-size:16px;line-height:1.25"><?= $topDel ? htmlspecialchars($topDel['delegacion']) : '—' ?></div><div class="l">Delegación con más<?= $topDel ? ' · '.number_format((int)$topDel['n']).' áreas' : '' ?></div></div>
    <div class="av-kpi"><div class="v" id="k-vista"><?= number_format($total) ?></div><div class="l">En vista (filtro actual)</div></div>
  </div>

  <div class="av-tools">
    <select id="f-deleg" class="input">
      <option value="">Todas las delegaciones</option>
      <?php foreach ($porDeleg as $d): ?>
        <option value="<?= htmlspecialchars($d['delegacion']) ?>"><?= htmlspecialchars($d['delegacion']) ?> (<?= (int)$d['n'] ?>)</option>
      <?php endforeach; ?>
    </select>
    <input id="f-buscar" class="input" type="search" placeholder="Buscar por nombre del área…">
    <button id="f-reset" class="btn btn-secondary" type="button">Limpiar</button>
  </div>

  <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-bottom:2px">
    <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--qro-text-secondary);cursor:pointer">
      <input type="checkbox" id="t-limites" checked> Mostrar límites delegacionales
    </label>
    <span style="font-size:12px;color:var(--qro-text-muted)">Clic en un chip para mostrar/ocultar esa delegación.</span>
  </div>
  <div class="av-legend" id="av-legend"></div>

  <div class="av-wrap">
    <div>
      <div id="av-map"></div>
    </div>
    <div class="av-tablecard">
      <h3>Listado <span id="tbl-count" class="text-secondary" style="font-weight:500"></span></h3>
      <div class="av-tablescroll">
        <table class="av-tbl">
          <thead><tr><th>No.</th><th>Área</th><th>Delegación</th><th>Ubicar</th></tr></thead>
          <tbody id="tbl-body"></tbody>
        </table>
        <div class="av-empty" id="tbl-empty" style="display:none">Sin resultados para el filtro.</div>
      </div>
    </div>
  </div>
</main>

<script>
const AREAS   = <?= json_encode($areas, JSON_UNESCAPED_UNICODE) ?>;
const LIMITES = <?= json_encode($limites, JSON_UNESCAPED_UNICODE) ?>;
const HASKEY  = <?= $apiKey ? 'true' : 'false' ?>;
const $ = id => document.getElementById(id);
function esc(s){ return String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

/* Paleta por delegación (cualitativa, 8 tonos) */
const PALETA = ['#2e9e5b','#005ab2','#e0872b','#8e44ad','#159c9c','#c0392b','#7a8b1f','#d43f8d'];
const DELEGS = [...new Set(AREAS.map(a=>a.d))].sort();
const COLOR = {};
DELEGS.forEach((d,i)=> COLOR[d] = PALETA[i % PALETA.length]);

/* Estado de filtros */
const hidden = new Set();          // delegaciones ocultas (leyenda)
let onlyMM = false;                // sólo áreas con etiqueta a revisar
function activos(){
  const dv = $('f-deleg').value;
  const q  = $('f-buscar').value.trim().toLowerCase();
  return AREAS.filter(a =>
    (!dv || a.d === dv) &&
    !hidden.has(a.d) &&
    (!onlyMM || a.mm) &&
    (!q || (a.n||'').toLowerCase().includes(q))
  );
}

/* ---- Leyenda ---- */
function renderLegend(){
  const cont = $('av-legend');
  const cnt = {};
  AREAS.forEach(a=> cnt[a.d]=(cnt[a.d]||0)+1);
  cont.innerHTML = DELEGS.map(d =>
    `<span class="av-chip ${hidden.has(d)?'off':''}" data-d="${esc(d)}">
       <i style="background:${COLOR[d]}"></i>${esc(d)} <b>${cnt[d]||0}</b>
     </span>`).join('');
  cont.querySelectorAll('.av-chip').forEach(ch=>ch.addEventListener('click',()=>{
    const d = ch.dataset.d;
    if (hidden.has(d)) hidden.delete(d); else hidden.add(d);
    ch.classList.toggle('off', hidden.has(d));
    refresh();
  }));
}

/* ---- Tabla ---- */
function renderTable(list){
  const body = $('tbl-body');
  body.innerHTML = list.map(a =>
    `<tr>
       <td>${a.no ?? ''}</td>
       <td class="n">${esc(a.n)}${a.np>1?` <span class="text-secondary" style="font-size:11px">(${a.np} pts)</span>`:''}</td>
       <td><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:${COLOR[a.d]};margin-right:5px"></span>${esc(a.d)}${a.mm?` <span title="El listado la ubicaba en ${esc(a.dl)}" style="background:#fdecc8;color:#7a5b00;font-size:10px;font-weight:700;padding:1px 5px;border-radius:6px;white-space:nowrap">≠ listado</span>`:''}</td>
       <td>
         <a class="av-loc" data-id="${a.id}" title="Ubicar en el mapa">📍 Ver</a>
         &nbsp;<a class="av-gm" href="https://www.google.com/maps?q=${a.lat},${a.lng}" target="_blank" rel="noopener" title="Abrir en Google Maps">↗</a>
       </td>
     </tr>`).join('');
  $('tbl-empty').style.display = list.length ? 'none' : 'block';
  $('tbl-count').textContent = '· ' + list.length.toLocaleString();
  body.querySelectorAll('.av-loc').forEach(el=>el.addEventListener('click',()=>localizar(+el.dataset.id)));
}

/* ---- Mapa ---- */
let map, info, markers = new Map(), polys = [], boundary = null, labels = [], showLimites = true;
window.initAvMap = function(){
  map = new google.maps.Map($('av-map'), {
    center:{lat:20.59,lng:-100.39}, zoom:11,
    mapTypeControl:false, streetViewControl:false, fullscreenControl:true,
    styles:[{featureType:'poi',stylers:[{visibility:'off'}]}]
  });
  info = new google.maps.InfoWindow();
  drawBoundaries();
  refresh();
};

/* ---- Límites delegacionales oficiales ---- */
function ringCentroid(geom){
  let coords = geom.type==='Polygon' ? geom.coordinates[0]
             : geom.type==='MultiPolygon' ? geom.coordinates[0][0] : null;
  if(!coords || !coords.length) return null;
  let sx=0, sy=0; coords.forEach(p=>{sx+=p[0]; sy+=p[1];});
  return {lat:sy/coords.length, lng:sx/coords.length};
}
function drawBoundaries(){
  if(!map || !LIMITES.length) return;
  boundary = new google.maps.Data();
  boundary.addGeoJson({type:'FeatureCollection', features:LIMITES});
  boundary.setStyle(f=>{
    const c = COLOR[f.getProperty('d')] || '#888';
    return {strokeColor:c, strokeWeight:2, strokeOpacity:.9, fillColor:c, fillOpacity:.05, clickable:false};
  });
  LIMITES.forEach(ft=>{
    const c = ringCentroid(ft.geometry); if(!c) return;
    labels.push(new google.maps.Marker({position:c, clickable:false,
      icon:{path:google.maps.SymbolPath.CIRCLE, scale:0, strokeOpacity:0, fillOpacity:0},
      label:{text:ft.properties.d, color:COLOR[ft.properties.d]||'#333', fontSize:'12px', fontWeight:'800'}}));
  });
  applyLimitesVis();
}
function applyLimitesVis(){
  if(boundary) boundary.setMap(showLimites ? map : null);
  labels.forEach(l=>l.setMap(showLimites ? map : null));
}

function markerIcon(color, mm){
  return {path:google.maps.SymbolPath.CIRCLE, scale:6, fillColor:color, fillOpacity:.95,
          strokeColor: mm ? '#c0392b' : '#fff', strokeWeight: mm ? 2.4 : 1.4};
}
function infoHtml(a){
  return `<div style="font-family:Montserrat,Arial,sans-serif;max-width:230px">
    <div style="font-weight:800;color:#155d34;margin-bottom:2px">${esc(a.n)}</div>
    <div style="font-size:12px;color:#555"><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:${COLOR[a.d]};margin-right:5px"></span>${esc(a.d)}</div>
    <div style="font-size:11px;color:#777;margin-top:4px">${a.lat.toFixed(6)}, ${a.lng.toFixed(6)}${a.np>1?` · ${a.np} vértices`:''}</div>
    <a href="https://www.google.com/maps?q=${a.lat},${a.lng}" target="_blank" rel="noopener" style="font-size:12px;color:#1f7a45;font-weight:700;text-decoration:none">Abrir en Google Maps ↗</a>
  </div>`;
}
function drawMap(list){
  if (!map) return;
  markers.forEach(m=>m.setMap(null)); markers.clear();
  polys.forEach(p=>p.setMap(null)); polys = [];
  const bounds = new google.maps.LatLngBounds();
  list.forEach(a=>{
    const mk = new google.maps.Marker({
      position:{lat:a.lat,lng:a.lng}, map, title:a.n, icon:markerIcon(COLOR[a.d], a.mm)
    });
    mk.addListener('click',()=>{ info.setContent(infoHtml(a)); info.open(map,mk); });
    markers.set(a.id, mk);
    bounds.extend(mk.getPosition());
    // áreas con varios vértices → polígono/línea tenue del mismo color
    if (a.pts && a.pts.length>1){
      const path = a.pts.map(p=>({lat:p[0],lng:p[1]}));
      const shape = a.pts.length>=3
        ? new google.maps.Polygon({paths:path,map,strokeColor:COLOR[a.d],strokeOpacity:.8,strokeWeight:1.5,fillColor:COLOR[a.d],fillOpacity:.18})
        : new google.maps.Polyline({path,map,strokeColor:COLOR[a.d],strokeOpacity:.8,strokeWeight:2});
      polys.push(shape);
      path.forEach(p=>bounds.extend(p));
    }
  });
  if (list.length && !bounds.isEmpty()) map.fitBounds(bounds);
}
function localizar(id){
  const a = AREAS.find(x=>x.id===id); if(!a) return;
  if(!map){ window.open(`https://www.google.com/maps?q=${a.lat},${a.lng}`,'_blank'); return; }
  map.panTo({lat:a.lat,lng:a.lng}); map.setZoom(16);
  const mk = markers.get(id);
  if(mk){ info.setContent(infoHtml(a)); info.open(map,mk); }
  $('av-map').scrollIntoView({behavior:'smooth',block:'center'});
}

/* ---- Orquestación ---- */
function refresh(){
  const list = activos();
  $('k-vista').textContent = list.length.toLocaleString();
  renderTable(list);
  drawMap(list);
}

$('f-deleg').addEventListener('change', ()=>{ onlyMM=false; refresh(); });
$('f-buscar').addEventListener('input', ()=>{ onlyMM=false; refresh(); });
$('f-reset').addEventListener('click', ()=>{ $('f-deleg').value=''; $('f-buscar').value=''; hidden.clear(); onlyMM=false; renderLegend(); refresh(); });
const tLim = $('t-limites'); if(tLim) tLim.addEventListener('change', ()=>{ showLimites = tLim.checked; applyLimitesVis(); });
const dLink = $('av-disc-link'); if(dLink) dLink.addEventListener('click', ev=>{ ev.preventDefault(); onlyMM=true; $('f-deleg').value=''; $('f-buscar').value=''; refresh(); document.querySelector('.av-tablecard').scrollIntoView({behavior:'smooth',block:'center'}); });

renderLegend();
renderTable(AREAS);            // tabla lista aunque el mapa aún no cargue
if(!HASKEY){ $('av-map').innerHTML='<div style="padding:20px;color:#991B1B">Google Maps API key no configurada.</div>'; }
</script>
<?php if ($apiKey): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($apiKey) ?>&callback=initAvMap&loading=async&v=weekly"></script>
<?php endif; ?>
</body>
</html>
