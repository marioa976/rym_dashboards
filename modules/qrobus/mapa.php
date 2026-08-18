<?php
/**
 * Qrobus · Mapa electoral de beneficiarios Unidos.
 *  - Mapa de calor (deck.gl) de beneficiarios geocodificados.
 *  - Secciones electorales del portal, coloreables por: nº de beneficiarios,
 *    afinidad del partido objetivo o participación.
 *  - Clic en sección → detalle DUAL (beneficiarios + electoral).
 * Cruce lat/long ↔ polígono por punto-en-polígono con índice de rejilla (cacheado).
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';   // guard: require_module('qrobus')
require_once __DIR__ . '/lib.php';

$cfg    = qb_config();
$apiKey = $cfg['google_maps_api_key'] ?? '';
$tabla  = qb_tabla();
$PARTIDO = getenv('PARTIDO_OBJETIVO') ?: 'PAN';

function qb_en_ring(float $x, float $y, array $ring): bool {
    $inside = false; $n = count($ring);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi=$ring[$i][0]; $yi=$ring[$i][1]; $xj=$ring[$j][0]; $yj=$ring[$j][1];
        $dy = ($yj - $yi) ?: 1e-12;
        if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / $dy + $xi)) $inside = !$inside;
    }
    return $inside;
}

$cacheFile = sys_get_temp_dir() . '/qrobus_mapa_ayto2024pl_' . md5($tabla . '|' . $PARTIDO) . '.json';
$noCache   = isset($_GET['nocache']);
$payload   = null; $dbError = null;
if (!$noCache && is_file($cacheFile) && (time() - filemtime($cacheFile)) < 600) {
    $payload = json_decode((string)@file_get_contents($cacheFile), true);
}
if (!is_array($payload)) {
    try {
        // 1) Puntos geocodificados (BD remota)
        $pdo = qb_pdo();
        $rows = $pdo->query(
            "SELECT latitud lat, longitud lng,
                    COALESCE(NULLIF(TRIM(sexo),''),'N/D') g,
                    edad_anios edad,
                    COALESCE(NULLIF(TRIM(estatus_nombre),''),'N/D') t,
                    COALESCE(NULLIF(TRIM(municipio),''),'N/D') m,
                    COALESCE(NULLIF(TRIM(tipo_nombre),''),'N/D') tp,
                    COALESCE(NULLIF(TRIM(plataforma_nombre),''),'N/D') pl
               FROM `$tabla`
              WHERE latitud IS NOT NULL AND latitud <> 0 AND longitud IS NOT NULL AND longitud <> 0"
        )->fetchAll();

        // 2) Secciones + 3) resumen electoral (BD del portal)
        $secGeo = []; $features = []; $elec = [];
        try {
            $pc  = require __DIR__ . '/../../config/config.php';
            $db  = $pc['db'];
            $pdb = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int)$db['port'], $db['name']),
                           $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            // Polígonos
            $vistos = [];
            foreach ($pdb->query("SELECT s.num_seccion, s.municipio, ST_AsGeoJSON(g.geom,5) gj
                                    FROM secciones s JOIN secciones_geo g ON g.seccion_id=s.id") as $r) {
                $sec = (int)$r['num_seccion'];
                if (isset($vistos[$sec]) || !$r['gj']) continue;
                $geom = json_decode($r['gj'], true); if (!$geom) continue;
                $vistos[$sec] = true;
                $rings = [];
                if ($geom['type'] === 'Polygon')      $rings[] = $geom['coordinates'][0] ?? [];
                elseif ($geom['type'] === 'MultiPolygon') foreach ($geom['coordinates'] as $poly) $rings[] = $poly[0] ?? [];
                $minx=$miny=INF; $maxx=$maxy=-INF;
                foreach ($rings as $rg) foreach ($rg as $pt){ $minx=min($minx,$pt[0]);$maxx=max($maxx,$pt[0]);$miny=min($miny,$pt[1]);$maxy=max($maxy,$pt[1]); }
                if ($minx === INF) continue;
                $secGeo[] = ['sec'=>$sec,'rings'=>$rings,'bbox'=>[$minx,$miny,$maxx,$maxy]];
                $features[] = ['type'=>'Feature','geometry'=>$geom,'properties'=>['s'=>$sec,'mun'=>$r['municipio']]];
            }

            // Resumen electoral por sección (proceso estatal reciente + gubernatura)
            $hayElec = (int)$pdb->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='resultados_casilla'")->fetchColumn();
            if ($hayElec) {
                $proc = (int)$pdb->query("SELECT id FROM procesos_electorales WHERE anio=2024 AND nivel='estatal' ORDER BY anio DESC LIMIT 1")->fetchColumn();
                $tipo = (int)$pdb->query("SELECT id FROM tipos_eleccion WHERE codigo='ayuntamiento' LIMIT 1")->fetchColumn();
                if ($proc && $tipo) {
                    $votos = [];
                    $st = $pdb->prepare("SELECT cas.num_seccion s, rc.voto_codigo c, SUM(rc.votos) v
                                           FROM resultados_casilla rc JOIN casillas cas ON cas.id=rc.casilla_id JOIN elecciones e ON e.id=rc.eleccion_id
                                          WHERE e.proceso_id=? AND e.tipo_id=? GROUP BY cas.num_seccion, rc.voto_codigo");
                    $st->execute([$proc,$tipo]);
                    foreach ($st as $r) $votos[(int)$r['s']][(string)$r['c']] = (int)$r['v'];
                    $ln = [];
                    $st = $pdb->prepare("SELECT cas.num_seccion s, SUM(rcm.lista_nominal) ln
                                           FROM resultados_casilla_meta rcm JOIN casillas cas ON cas.id=rcm.casilla_id JOIN elecciones e ON e.id=rcm.eleccion_id
                                          WHERE e.proceso_id=? AND e.tipo_id=? GROUP BY cas.num_seccion");
                    $st->execute([$proc,$tipo]);
                    foreach ($st as $r) $ln[(int)$r['s']] = (int)$r['ln'];
                    $excl = ['NULOS'=>1,'NO_REGISTRADAS'=>1];
                    foreach ($votos as $sec => $cs) {
                        $val=0; $emit=0; $gan=null; $ganv=0; $obj=0;
                        foreach ($cs as $c=>$v) {
                            $emit+=$v; $up=strtoupper($c);
                            if (!isset($excl[$up])) { $val+=$v; if ($v>$ganv){$ganv=$v;$gan=$c;} }
                            if ($c===$PARTIDO) $obj+=$v;
                        }
                        $lnv = $ln[$sec] ?? 0;
                        $elec[$sec] = [
                            'rent' => $val>0 ? round($obj/$val*100,1) : null,
                            'part' => $lnv>0 ? round($emit/$lnv*100,1) : null,
                            'gan'  => $gan, 'ganp' => $val>0 ? round($ganv/$val*100,1) : null,
                            'ln'   => $lnv, 'val' => $val,
                        ];
                    }
                }
            }
        } catch (Throwable $e) { /* sin portal/electoral: solo heatmap */ }

        // Rejilla + asignación de sección a cada punto
        $CELL=0.02; $grid=[];
        foreach ($secGeo as $idx=>$sg){ [$minx,$miny,$maxx,$maxy]=$sg['bbox'];
            for($cx=(int)floor($minx/$CELL);$cx<=(int)floor($maxx/$CELL);$cx++)
                for($cy=(int)floor($miny/$CELL);$cy<=(int)floor($maxy/$CELL);$cy++) $grid["$cx:$cy"][]=$idx; }
        $asignar = function(float $lng,float $lat) use ($grid,$secGeo,$CELL){
            $key=((int)floor($lng/$CELL)).':'.((int)floor($lat/$CELL));
            foreach($grid[$key]??[] as $idx){ $sg=$secGeo[$idx]; [$minx,$miny,$maxx,$maxy]=$sg['bbox'];
                if($lng<$minx||$lng>$maxx||$lat<$miny||$lat>$maxy) continue;
                foreach($sg['rings'] as $rg) if(qb_en_ring($lng,$lat,$rg)) return $sg['sec']; }
            return null;
        };
        $pts=[];
        foreach($rows as $r){ $lat=(float)$r['lat'];$lng=(float)$r['lng']; $ed=(int)$r['edad'];
            $eg = $ed<=0?'N/D':($ed<18?'0-17':($ed<26?'18-25':($ed<36?'26-35':($ed<46?'36-45':($ed<60?'46-59':'60+')))));
            $pts[]=['y'=>$lat,'x'=>$lng,'s'=>$asignar($lng,$lat),'g'=>$r['g'],'e'=>$eg,'t'=>$r['t'],'m'=>$r['m'],'tp'=>$r['tp'],'pl'=>$r['pl']]; }

        $payload = ['pts'=>$pts,'geo'=>['type'=>'FeatureCollection','features'=>$features],'elec'=>$elec,'partido'=>$PARTIDO];
        @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) { $dbError=$e->getMessage(); $payload=['pts'=>[],'geo'=>['type'=>'FeatureCollection','features'=>[]],'elec'=>[],'partido'=>$PARTIDO]; }
}
?><?php
$ktTitle  = 'Qrobus · Mapa seccional';
$ktActive = 'qrobus';
require __DIR__ . '/../../views/layout/kt_top.php';
?>
  <script src="https://unpkg.com/deck.gl@8.9.35/dist.min.js"></script>
  <style>
    .m-filtros{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:12px}
    .m-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:12px}
    .m-kpi{background:#fff;border:1px solid var(--qro-border);border-radius:12px;padding:12px 14px}
    .m-kpi .v{font-size:22px;font-weight:800;color:var(--qro-blue-dark)}.m-kpi .l{font-size:12px;color:var(--qro-text-secondary);font-weight:600}
    #m-map{height:620px;border-radius:12px;border:1px solid var(--qro-border);overflow:hidden}
    .m-legend{display:flex;gap:10px;align-items:center;font-size:11px;color:var(--qro-text-secondary);margin:8px 0;flex-wrap:wrap}
    .m-legend i{width:16px;height:12px;border-radius:3px;display:inline-block;margin-right:3px;vertical-align:middle}
    .m-side{background:#fff;border:1px solid var(--qro-border);border-radius:12px;padding:14px 16px;max-height:620px;overflow:auto}
    .m-wrap{display:grid;grid-template-columns:1fr 330px;gap:16px}
    @media(max-width:1000px){.m-wrap{grid-template-columns:1fr}}
    .switchbtn{padding:6px 12px;border-radius:999px;border:1px solid var(--qro-border);background:#fff;cursor:pointer;font-size:12px;font-weight:700;color:var(--qro-text-secondary)}
    .switchbtn.on{background:var(--qro-blue-dark);color:#fff;border-color:var(--qro-blue-dark)}
    .m-sec-h{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;margin:14px 0 6px}
    .m-row{display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px dashed #eef0f2;font-size:13px}
    .m-bar{display:grid;grid-template-columns:1fr 46px;gap:6px;align-items:center;font-size:12px;margin:3px 0}
    .m-bar .tr{background:#eef2f6;border-radius:999px;height:12px;overflow:hidden}.m-bar .tr>span{display:block;height:100%;background:#005ab2}
  </style>

  <div class="page-head"><h1>Mapa seccional · Unidos</h1><p class="text-secondary">Densidad de beneficiarios cruzada con la geografía por sección (partido objetivo: <strong><?= htmlspecialchars($PARTIDO) ?></strong>).</p></div>

  <?php if ($dbError): ?><div class="alert alert-danger">No se pudieron cargar los datos.<br><span style="font-size:12px"><?= htmlspecialchars($dbError) ?></span></div><?php endif; ?>
  <?php if (!$apiKey): ?><div class="alert alert-danger">Falta <code>GOOGLE_MAPS_API_KEY</code>.</div><?php endif; ?>

  <div class="m-filtros">
    <select id="f-mun" class="input"><option value="">Todos los municipios</option></select>
    <select id="f-tipo" class="input"><option value="">Todos los tipos de aplicante</option></select>
    <select id="f-plat" class="input"><option value="">Todas las plataformas</option></select>
    <select id="f-sexo" class="input"><option value="">Todos los sexos</option></select>
    <select id="f-edad" class="input"><option value="">Todas las edades</option></select>
    <select id="f-est" class="input"><option value="">Todos los estatus</option></select>
  </div>

  <div class="m-kpis">
    <div class="m-kpi"><div class="v" id="k-vista">0</div><div class="l">Beneficiarios (filtros)</div><div class="l" id="k-vista-sub" style="color:var(--qro-text-muted);font-weight:500"></div></div>
    <div class="m-kpi"><div class="v" id="k-secs">0</div><div class="l">Secciones con beneficiarios</div></div>
    <div class="m-kpi"><div class="v" id="k-sinsec">0</div><div class="l">Fuera de sección</div></div>
    <div class="m-kpi"><div class="v" id="k-top">—</div><div class="l">Sección con más</div></div>
  </div>

  <div style="display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap;align-items:center">
    <span style="font-size:12px;font-weight:700;color:var(--qro-text-secondary)">Colorear secciones por:</span>
    <button class="switchbtn on" data-metric="ben">Beneficiarios</button>
    <button class="switchbtn" data-metric="rent">Afinidad <?= htmlspecialchars($PARTIDO) ?></button>
    <button class="switchbtn" data-metric="part">Participación</button>
    <span style="width:14px"></span>
    <button class="switchbtn" id="sw-heat">🔥 Calor</button>
    <button class="switchbtn on" id="sw-sec">🗳 Secciones</button>
  </div>
  <div class="m-legend" id="m-legend"></div>

  <div class="m-wrap">
    <div id="m-map"></div>
    <div class="m-side">
      <strong>Detalle de sección</strong>
      <div id="m-detail" style="margin-top:8px"><p class="text-secondary" style="font-size:13px">Haz clic en una sección para ver beneficiarios y resultado por sección.</p></div>
    </div>
  </div>

<script>
const MP = <?= json_encode($payload, JSON_UNESCAPED_UNICODE) ?>;
const HASKEY = <?= $apiKey ? 'true':'false' ?>;
const PARTIDO = <?= json_encode($PARTIDO) ?>;
const $ = id => document.getElementById(id);
function esc(s){ return String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

/* Filtros */
function uniq(k){ return [...new Set(MP.pts.map(p=>p[k]).filter(v=>v&&v!=='N/D'))].sort(); }
function fill(id,vals,label){ $(id).innerHTML=`<option value="">${label}</option>`+vals.map(v=>`<option>${esc(v)}</option>`).join(''); }
fill('f-mun',uniq('m'),'Todos los municipios');
fill('f-tipo',uniq('tp'),'Todos los tipos de aplicante');
fill('f-plat',uniq('pl'),'Todas las plataformas');
fill('f-sexo',uniq('g'),'Todos los sexos');
fill('f-edad',['0-17','18-25','26-35','36-45','46-59','60+'],'Todas las edades');
fill('f-est',uniq('t'),'Todos los estatus');
function filtrados(){ const fm=$('f-mun').value,fp=$('f-tipo').value,fpl=$('f-plat').value,fs=$('f-sexo').value,fe=$('f-edad').value,ft=$('f-est').value;
  return MP.pts.filter(p=>(!fm||p.m===fm)&&(!fp||p.tp===fp)&&(!fpl||p.pl===fpl)&&(!fs||p.g===fs)&&(!fe||p.e===fe)&&(!ft||p.t===ft)); }

/* Colores */
const GRIS='#E5E7EB';
function rampBlue(v,max){ if(max<=0||!v) return GRIS; const t=Math.min(1,v/max); return `rgba(29,78,216,${0.2+0.8*t})`; }
function greenPct(v){ if(v==null) return GRIS; return v>=55?'#15803d':v>=45?'#65a30d':v>=35?'#ca8a04':v>=25?'#ea580c':'#dc2626'; }
function tealPct(v){ if(v==null) return GRIS; const t=Math.min(1,v/100); return `rgba(13,148,136,${0.2+0.8*t})`; }

let map, heatOverlay=null, showHeat=false, showSec=true, metric='ben', dataLayer, curPts=[], bySecCount={}, maxC=0;
window.initMMap=function(){
  map=new google.maps.Map($('m-map'),{center:{lat:20.59,lng:-100.39},zoom:9,mapTypeControl:false,streetViewControl:false,fullscreenControl:true,styles:[{featureType:'poi',stylers:[{visibility:'off'}]}]});
  if(MP.geo.features.length){
    dataLayer=new google.maps.Data({map});
    dataLayer.addGeoJson(MP.geo);
    dataLayer.addListener('click',e=>detalle(e.feature.getProperty('s')));
    dataLayer.addListener('mouseover',e=>dataLayer.overrideStyle(e.feature,{strokeWeight:2,strokeColor:'#111',strokeOpacity:1}));
    dataLayer.addListener('mouseout',()=>dataLayer.revertStyle());
    const b=new google.maps.LatLngBounds();
    dataLayer.forEach(f=>f.getGeometry().forEachLatLng(ll=>b.extend(ll)));
    if(!b.isEmpty()) map.fitBounds(b);
  }
  render();
};
function secColor(s){
  if(metric==='ben') return rampBlue(bySecCount[s]||0, maxC);
  const e=MP.elec[s]; if(!e) return '#eef2f6';
  return metric==='rent' ? greenPct(e.rent) : tealPct(e.part);
}
function render(){
  curPts=filtrados();
  // heatmap deck.gl
  if(heatOverlay){ heatOverlay.setMap(null); heatOverlay=null; }
  if(showHeat && curPts.length && typeof deck!=='undefined' && deck.GoogleMapsOverlay){
    const capa=new deck.HeatmapLayer({id:'qb-heat',data:curPts,getPosition:p=>[p.x,p.y],getWeight:1,radiusPixels:30,intensity:1,threshold:0.05,
      colorRange:[[42,158,218],[0,90,178],[37,65,133],[217,144,0],[206,58,43],[142,30,22]]});
    heatOverlay=new deck.GoogleMapsOverlay({layers:[capa]}); heatOverlay.setMap(map);
  }
  // conteo por sección
  bySecCount={}; let sinSec=0;
  for(const p of curPts){ if(p.s==null){sinSec++;continue;} bySecCount[p.s]=(bySecCount[p.s]||0)+1; }
  maxC=Math.max(0,...Object.values(bySecCount));
  if(dataLayer) dataLayer.setStyle(f=>({visible:showSec, fillColor:secColor(f.getProperty('s')),
      fillOpacity:0.7, strokeColor:'#1F2937', strokeWeight:0.5, strokeOpacity:0.6}));
  // KPIs
  const secs=Object.keys(bySecCount);
  $('k-vista').textContent=curPts.length.toLocaleString();
  $('k-vista-sub').textContent='de '+MP.pts.length.toLocaleString()+' geocodificados cargados';
  $('k-secs').textContent=secs.length.toLocaleString();
  $('k-sinsec').textContent=sinSec.toLocaleString();
  let top='—',tv=0; for(const s of secs) if(bySecCount[s]>tv){tv=bySecCount[s];top=s;}
  $('k-top').textContent=top==='—'?'—':('#'+top+' ('+tv+')');
  renderLegend();
}
function renderLegend(){
  let h='';
  if(metric==='ben') h=`<span><i style="background:#eef2f6"></i>0</span><span><i style="background:rgba(29,78,216,.35)"></i>pocos</span><span><i style="background:rgba(29,78,216,.95)"></i>muchos (máx ${maxC})</span>`;
  else if(metric==='rent') h=`Afinidad ${esc(PARTIDO)}: <span><i style="background:#dc2626"></i>&lt;25%</span><span><i style="background:#ca8a04"></i>35-45</span><span><i style="background:#15803d"></i>≥55%</span>`;
  else h=`Participación: <span><i style="background:rgba(13,148,136,.25)"></i>baja</span><span><i style="background:rgba(13,148,136,.95)"></i>alta</span>`;
  $('m-legend').innerHTML='🔥 densidad de beneficiarios · '+h;
}
function bar(label,val,max){ const w=max>0?Math.max(3,val/max*100):0;
  return `<div class="m-bar"><span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="${esc(label)}">${esc(label)}</span>
    <span class="tr"><span style="width:${w}%"></span></span></div><div style="text-align:right;font-size:11px;color:#64748b;margin-top:-14px;margin-bottom:6px">${val.toLocaleString()}</div>`; }
function detalle(s){
  const pts=curPts.filter(p=>p.s===s);
  const cnt=(key)=>{ const o={}; for(const p of pts) o[p[key]]=(o[p[key]]||0)+1; return Object.entries(o).sort((a,b)=>b[1]-a[1]); };
  const tipos=cnt('tp'), sexos=cnt('g');
  const maxT=tipos.length?tipos[0][1]:0;
  const e=MP.elec[s];
  let html=`<h4 style="margin:0 0 2px;color:var(--qro-blue-dark)">Sección ${s}</h4>`;
  // Beneficiarios
  html+=`<div class="m-sec-h" style="color:#1d4ed8">👤 Beneficiarios · ${pts.length.toLocaleString()}</div>`;
  if(pts.length){
    html+=`<div style="font-size:11px;color:#64748b;margin-bottom:2px">Por tipo de aplicante</div>`;
    html+=tipos.slice(0,6).map(([k,v])=>bar(k,v,maxT)).join('');
    html+=`<div style="font-size:11px;color:#64748b;margin:6px 0 2px">Por dónde llega la solicitud</div>`;
    html+=cnt('pl').slice(0,6).map(([k,v])=>`<div class="m-row"><span>${esc(k)}</span><b>${v.toLocaleString()}</b></div>`).join('');
    html+=`<div style="font-size:11px;color:#64748b;margin:6px 0 2px">Por sexo</div>`;
    html+=sexos.map(([k,v])=>`<div class="m-row"><span>${esc(k)}</span><b>${v.toLocaleString()}</b></div>`).join('');
  } else html+=`<div class="text-secondary" style="font-size:12px">Sin beneficiarios en la vista.</div>`;
  // Electoral
  html+=`<div class="m-sec-h" style="color:#ce3a2b">🗳 Resultado por sección (ayuntamiento 2024)</div>`;
  if(e){
    html+=`<div class="m-row"><span>Afinidad ${esc(PARTIDO)}</span><b style="color:${greenPct(e.rent)}">${e.rent!=null?e.rent+'%':'—'}</b></div>`;
    html+=`<div class="m-row"><span>Participación</span><b>${e.part!=null?e.part+'%':'—'}</b></div>`;
    html+=`<div class="m-row"><span>Ganador</span><b>${esc(e.gan||'—')}${e.ganp!=null?' ('+e.ganp+'%)':''}</b></div>`;
    html+=`<div class="m-row"><span>Lista nominal</span><b>${(+e.ln).toLocaleString()}</b></div>`;
    html+=`<div class="m-row"><span>Votos válidos</span><b>${(+e.val).toLocaleString()}</b></div>`;
  } else html+=`<div class="text-secondary" style="font-size:12px">Sin datos de resultados para esta sección.</div>`;
  $('m-detail').innerHTML=html;
}

['f-mun','f-tipo','f-plat','f-sexo','f-edad','f-est'].forEach(id=>$(id).addEventListener('change',render));
document.querySelectorAll('[data-metric]').forEach(b=>b.addEventListener('click',()=>{
  metric=b.dataset.metric; document.querySelectorAll('[data-metric]').forEach(x=>x.classList.toggle('on',x===b)); render();
}));
$('sw-heat').addEventListener('click',()=>{ showHeat=!showHeat; $('sw-heat').classList.toggle('on',showHeat); render(); });
$('sw-sec').addEventListener('click',()=>{ showSec=!showSec; $('sw-sec').classList.toggle('on',showSec); render(); });

if(!HASKEY){ $('m-map').innerHTML='<div style="padding:20px;color:#991B1B">Google Maps API key no configurada.</div>'; }
</script>
<?php if ($apiKey): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($apiKey) ?>&callback=initMMap&loading=async&v=weekly"></script>
<?php endif; ?>
<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
