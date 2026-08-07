<?php
/**
 * Bloque · Mapa de procedencia — usuarios geocodificados desde su dirección.
 * Mapa de calor (deck.gl) + marcadores, con límites delegacionales de contexto.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';     // guard: require_module('bloque')
require_once __DIR__ . '/lib.php';

$cfg    = bloq_config();
$apiKey = $cfg['google_maps_api_key'] ?? '';
$pts = []; $limites = []; $gs = ['total'=>0,'geo'=>0]; $dbError = null;
try {
    $pdo     = bloq_pdo();
    $pts     = bloq_puntos($pdo);
    $limites = bloq_limites($pdo);
    $gs      = bloq_geo_stats($pdo);
} catch (Throwable $e) { $dbError = $e->getMessage(); }
$pct = $gs['total'] > 0 ? round($gs['geo'] / $gs['total'] * 100) : 0;
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bloque · Mapa de procedencia</title>
  <link rel="stylesheet" href="../../assets/css/qro.css">
  <script src="https://unpkg.com/deck.gl@8.9.35/dist.min.js"></script>
  <style>
    .bl-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:14px}
    .bl-kpi{background:#fff;border:1px solid var(--qro-border);border-radius:12px;padding:13px 15px}
    .bl-kpi .v{font-size:22px;font-weight:800;color:#005ab2;line-height:1.1}
    .bl-kpi .l{font-size:12px;color:var(--qro-text-secondary);font-weight:600;margin-top:2px}
    #bl-map{height:620px;border-radius:12px;border:1px solid var(--qro-border);overflow:hidden}
    .bl-ctrl{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
    .bl-ctrl label{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--qro-text-secondary);cursor:pointer}
  </style>
</head>
<body>
<?php $portalModulo = 'Bloque'; $navActive = 'mapa'; include __DIR__ . '/_nav.php'; ?>

<main class="content" style="padding:28px 32px">
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
  </div>

  <div id="bl-map"></div>
</main>

<script>
const PTS = <?= json_encode($pts, JSON_UNESCAPED_UNICODE) ?>;
const LIMITES = <?= json_encode($limites, JSON_UNESCAPED_UNICODE) ?>;
const HASKEY = <?= $apiKey ? 'true':'false' ?>;
const $ = id => document.getElementById(id);
function esc(s){ return String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

let map, info, deckOverlay=null, boundary=null, blabels=[], mkPts=[];
let showHeat=true, showPts=false, showLim=true;
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
  mkPts = PTS.map(p=>new google.maps.Marker({position:{lat:p.lat,lng:p.lng},
    icon:{path:google.maps.SymbolPath.CIRCLE,scale:3.5,fillColor:'#005ab2',fillOpacity:.7,strokeColor:'#fff',strokeWeight:.8}}));
  apply();
};
function apply(){
  // heat
  const layers=[];
  if(showHeat && PTS.length && typeof deck!=='undefined' && deck.GoogleMapsOverlay){
    layers.push(new deck.HeatmapLayer({id:'bl-heat',data:PTS,getPosition:p=>[p.lng,p.lat],getWeight:1,radiusPixels:28,intensity:1,threshold:0.05,colorRange:RAMP}));
  }
  if(!deckOverlay){ deckOverlay=new deck.GoogleMapsOverlay({layers}); deckOverlay.setMap(map); } else deckOverlay.setProps({layers});
  mkPts.forEach(m=>m.setMap(showPts?map:null));
  if(boundary) boundary.setMap(showLim?map:null);
  blabels.forEach(l=>l.setMap(showLim?map:null));
}
$('t-heat').addEventListener('change',()=>{showHeat=$('t-heat').checked;apply();});
$('t-pts').addEventListener('change',()=>{showPts=$('t-pts').checked;apply();});
$('t-lim').addEventListener('change',()=>{showLim=$('t-lim').checked;apply();});
if(!HASKEY){ $('bl-map').innerHTML='<div style="padding:20px;color:#991B1B">Google Maps API key no configurada.</div>'; }
</script>
<?php if ($apiKey): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($apiKey) ?>&callback=initBlMap&loading=async&v=weekly"></script>
<?php endif; ?>
</body>
</html>
