<?php
/**
 * Bloque · Reporte por DELEGACIÓN (muy visual).
 * De dónde viene la gente: usuarios, asistencias, tasa de asistencia,
 * perfil (género/edad) y actividades preferidas por delegación.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';   // guard: require_module('bloque')
require_once __DIR__ . '/lib.php';

$D = null; $dbError = null;
try {
    $pdo  = bl_pdo();
    $meta = bl_meta($pdo);
    $q    = fn(string $s) => $pdo->query($s)->fetchAll();
    $DEL  = "COALESCE(NULLIF(TRIM(u.delegacion),''),'N/D')";
    $actExpr = bl_expr_actividad($pdo, 'a');

    // Base por delegación: usuarios, asistencias, presentes/ausentes, edad prom.
    $base = $q("SELECT $DEL k,
                       COUNT(DISTINCT u.usuario_id) usuarios,
                       COUNT(asi.asistencia_id) asistencias,
                       SUM(CASE WHEN asi.asistencia_estatus='present' THEN 1 ELSE 0 END) presentes,
                       SUM(CASE WHEN asi.asistencia_estatus='absent'  THEN 1 ELSE 0 END) ausentes,
                       ROUND(AVG(NULLIF(u.edad,0)),1) edad_prom
                  FROM v_usuarios u
             LEFT JOIN asistencias asi ON asi.usuario_id = u.usuario_id
              GROUP BY k
              ORDER BY usuarios DESC");

    // Género por delegación (para apiladas)
    $gen = $q("SELECT $DEL k, COALESCE(NULLIF(TRIM(u.genero),''),'N/D') g, COUNT(*) n
                 FROM v_usuarios u GROUP BY k, g");

    // Actividades por delegación (top global, matriz para el selector)
    $act = bl_existe($pdo,'actividades')
        ? $q("SELECT $DEL k, $actExpr act, COUNT(*) n
                FROM asistencias asi
                JOIN v_usuarios u  ON u.usuario_id  = asi.usuario_id
                JOIN actividades a ON a.actividad_id = asi.actividad_id
               WHERE asi.asistencia_estatus='present'
            GROUP BY k, act")
        : [];

    /* ---- Ubicación geográfica de las delegaciones ----
       El portal NO tiene polígonos de delegación (solo catálogos de nombre y los
       polígonos de secciones electorales). Derivamos un CENTROIDE por delegación
       a partir de datos ya geocodificados: tickets de Zendesk (delegacion_id + coords)
       y, como respaldo, el padrón DIF (delegacion + coords). */
    $cent = [];
    try {
        if (bl_existe($pdo,'tickets') && bl_existe($pdo,'cat_delegacion')) {
            foreach ($pdo->query("SELECT cd.nombre k, AVG(t.latitud) lat, AVG(t.longitud) lng, COUNT(*) n
                                    FROM tickets t JOIN cat_delegacion cd ON cd.id = t.delegacion_id
                                   WHERE t.latitud IS NOT NULL AND t.latitud <> 0
                                     AND t.longitud IS NOT NULL AND t.longitud <> 0
                                GROUP BY cd.nombre") as $r) {
                $cent[bl_norm((string)$r['k'])] = ['lat'=>(float)$r['lat'], 'lng'=>(float)$r['lng'], 'src'=>'tickets', 'n'=>(int)$r['n']];
            }
        }
        if (bl_existe($pdo,'padron')) {
            foreach ($pdo->query("SELECT delegacion k, AVG(latitud) lat, AVG(longitud) lng, COUNT(*) n
                                    FROM padron
                                   WHERE TRIM(COALESCE(delegacion,'')) <> ''
                                     AND latitud IS NOT NULL AND latitud <> 0
                                     AND longitud IS NOT NULL AND longitud <> 0
                                GROUP BY delegacion") as $r) {
                $k = bl_norm((string)$r['k']);
                if (!isset($cent[$k])) $cent[$k] = ['lat'=>(float)$r['lat'], 'lng'=>(float)$r['lng'], 'src'=>'padron', 'n'=>(int)$r['n']];
            }
        }
    } catch (Throwable $e) { /* sin centroides: el mapa lo dirá */ }

    foreach ($base as &$b) {
        $k = bl_norm((string)$b['k']);
        $b['lat'] = isset($cent[$k]) ? $cent[$k]['lat'] : null;
        $b['lng'] = isset($cent[$k]) ? $cent[$k]['lng'] : null;
    }
    unset($b);

    $D = ['base'=>$base, 'gen'=>$gen, 'act'=>$act];
} catch (Throwable $e) { $dbError = $e->getMessage(); }
$apiKey = bl_config()['google_maps_api_key'] ?? '';
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bloque · Por delegación</title>
  <link rel="stylesheet" href="../../assets/css/qro.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <style>
    .d-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:18px}
    .d-card{background:#fff;border:1px solid var(--qro-border);border-radius:14px;padding:14px 16px;position:relative;overflow:hidden}
    .d-card .acc{position:absolute;left:0;top:0;bottom:0;width:4px}
    .d-card .v{font-size:24px;font-weight:800;color:var(--qro-blue-dark)}
    .d-card .l{font-size:12px;color:var(--qro-text-secondary);font-weight:600;margin-top:2px}
    .d-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
    @media(max-width:900px){.d-grid{grid-template-columns:1fr}}
    .d-panel{background:#fff;border:1px solid var(--qro-border);border-radius:14px;padding:16px 18px;box-shadow:var(--qro-shadow-sm)}
    .d-panel h3{margin:0 0 10px;font-size:14px;color:var(--qro-blue-dark)}
    .d-panel .box{height:330px}
    .d-wide{grid-column:1/-1}
    .tbl{width:100%;border-collapse:collapse;font-size:13px}
    .tbl th,.tbl td{padding:7px 9px;border-bottom:1px solid #eef0f2;text-align:right;white-space:nowrap}
    .tbl th:first-child,.tbl td:first-child{text-align:left}
    .tbl thead th{background:#eef5fc;color:var(--qro-blue-dark);font-weight:700;cursor:pointer}
    .tbl tbody tr:hover{background:#f7fafe}
    .pillp{display:inline-block;padding:1px 8px;border-radius:999px;font-weight:700;font-size:12px}
  </style>
</head>
<body>
<?php $portalModulo='Bloque'; $navActive='deleg'; include __DIR__ . '/_nav.php'; ?>

<main class="content" style="padding:28px 32px">
  <div class="page-head"><h1>Por delegación · Bloque</h1><p class="text-secondary">De dónde viene la gente que asiste a las actividades.</p></div>

  <?php if ($dbError): ?>
    <div class="alert alert-danger">No se pudieron consultar las tablas de Bloque.<br><span style="font-size:12px"><?= htmlspecialchars($dbError) ?></span></div>
  <?php else: ?>
    <div class="d-kpis" id="d-kpis"></div>

    <div class="d-grid">
      <div class="d-panel d-wide">
        <h3>🗺 Mapa de delegaciones</h3>
        <p class="text-secondary" style="margin:0 0 8px;font-size:12px">
          El portal no tiene polígonos de delegación, así que ubicamos cada una con un <strong>centroide</strong> derivado de datos ya geocodificados (tickets de Zendesk y padrón DIF).
          El <strong>tamaño</strong> de la burbuja = usuarios de Bloque; el <strong>color</strong> = tasa de asistencia. <span id="d-map-nota"></span>
        </p>
        <div class="d-legend" id="d-legend" style="display:flex;gap:10px;flex-wrap:wrap;font-size:11px;color:var(--qro-text-secondary);margin-bottom:8px"></div>
        <div id="d-map" style="height:520px;border-radius:12px;border:1px solid var(--qro-border);overflow:hidden"></div>
      </div>
      <div class="d-panel d-wide"><h3>Usuarios por delegación</h3><div class="box"><canvas id="c-usu"></canvas></div></div>
      <div class="d-panel d-wide"><h3>Asistencias por delegación (presentes vs ausentes)</h3><div class="box"><canvas id="c-asis"></canvas></div></div>
      <div class="d-panel"><h3>Tasa de asistencia por delegación</h3><div class="box"><canvas id="c-tasa"></canvas></div></div>
      <div class="d-panel"><h3>Género por delegación</h3><div class="box"><canvas id="c-gen"></canvas></div></div>
      <div class="d-panel d-wide">
        <h3>Actividades preferidas por delegación</h3>
        <select id="f-deleg" class="input" style="max-width:320px;margin-bottom:10px"></select>
        <div class="box"><canvas id="c-act"></canvas></div>
      </div>
    </div>

    <div class="d-panel">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <strong>Detalle por delegación</strong>
        <button class="btn btn-secondary" id="btn-csv">Descargar CSV</button>
      </div>
      <table class="tbl" id="tabla">
        <thead><tr>
          <th data-k="k">Delegación</th><th data-k="usuarios">Usuarios</th><th data-k="asistencias">Asistencias</th>
          <th data-k="presentes">Presentes</th><th data-k="ausentes">Ausentes</th>
          <th data-k="tasa">Tasa asistencia</th><th data-k="edad_prom">Edad prom.</th>
        </tr></thead>
        <tbody id="tbody"></tbody>
      </table>
    </div>
  <?php endif; ?>
</main>

<?php if ($D): ?>
<script>
const D = <?= json_encode($D, JSON_UNESCAPED_UNICODE) ?>;
const QC = ['#254185','#005ab2','#2a9eda','#188a5b','#d99000','#ce3a2b','#1a2f63','#5b667a','#65a30d','#8b5cf6'];
Chart.defaults.font.family = "'Montserrat',Arial,sans-serif";
const $ = id => document.getElementById(id);
function esc(s){ return String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

/* Enriquecer con tasa */
const rows = D.base.map(r=>{
  const a=+r.asistencias||0, p=+r.presentes||0;
  return {...r, usuarios:+r.usuarios||0, asistencias:a, presentes:p, ausentes:+r.ausentes||0,
          edad_prom:r.edad_prom!==null?+r.edad_prom:null, tasa: a>0 ? +(p/a*100).toFixed(1) : null};
});
const topDel = rows.slice(0,12);   // 'top' es global del navegador (window.top): no usar

/* KPIs */
(function(){
  const tU=rows.reduce((s,r)=>s+r.usuarios,0), tA=rows.reduce((s,r)=>s+r.asistencias,0), tP=rows.reduce((s,r)=>s+r.presentes,0);
  const conDatos=rows.filter(r=>r.k!=='N/D');
  const mejor=[...conDatos].filter(r=>r.asistencias>=10).sort((a,b)=>(b.tasa??-1)-(a.tasa??-1))[0];
  $('d-kpis').innerHTML=`
    <div class="d-card"><span class="acc" style="background:#254185"></span><div class="v">${conDatos.length}</div><div class="l">Delegaciones representadas</div></div>
    <div class="d-card"><span class="acc" style="background:#005ab2"></span><div class="v">${tU.toLocaleString()}</div><div class="l">Usuarios</div></div>
    <div class="d-card"><span class="acc" style="background:#188a5b"></span><div class="v">${tA>0?(tP/tA*100).toFixed(1):0}%</div><div class="l">Tasa de asistencia global</div></div>
    <div class="d-card"><span class="acc" style="background:#d99000"></span><div class="v" style="font-size:17px">${mejor?esc(mejor.k):'—'}</div><div class="l">Mejor tasa (≥10 asist.)${mejor?' · '+mejor.tasa+'%':''}</div></div>`;
})();

/* Usuarios por delegación */
new Chart($('c-usu'),{type:'bar',data:{labels:topDel.map(r=>r.k),datasets:[{data:topDel.map(r=>r.usuarios),backgroundColor:'#254185',borderRadius:6}]},
  options:{indexAxis:'y',plugins:{legend:{display:false}},maintainAspectRatio:false,scales:{x:{grid:{color:'#eef2f6'}},y:{grid:{display:false}}}}});

/* Asistencias apiladas */
new Chart($('c-asis'),{type:'bar',data:{labels:topDel.map(r=>r.k),datasets:[
    {label:'Presentes',data:topDel.map(r=>r.presentes),backgroundColor:'#188a5b',borderRadius:4},
    {label:'Ausentes', data:topDel.map(r=>r.ausentes), backgroundColor:'#ce3a2b',borderRadius:4}]},
  options:{indexAxis:'y',maintainAspectRatio:false,plugins:{legend:{position:'top'}},
    scales:{x:{stacked:true,grid:{color:'#eef2f6'}},y:{stacked:true,grid:{display:false}}}}});

/* Tasa de asistencia (ordenada) */
(function(){
  const t=[...rows].filter(r=>r.asistencias>=5).sort((a,b)=>(b.tasa??-1)-(a.tasa??-1)).slice(0,12);
  new Chart($('c-tasa'),{type:'bar',data:{labels:t.map(r=>r.k),datasets:[{data:t.map(r=>r.tasa),
      backgroundColor:t.map(r=>r.tasa>=80?'#15803d':r.tasa>=60?'#65a30d':r.tasa>=40?'#d99000':'#ce3a2b'),borderRadius:6}]},
    options:{indexAxis:'y',plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>c.raw+'%'}}},maintainAspectRatio:false,
      scales:{x:{grid:{color:'#eef2f6'},max:100,ticks:{callback:v=>v+'%'}},y:{grid:{display:false}}}}});
})();

/* Género por delegación (apiladas) */
(function(){
  const generos=[...new Set(D.gen.map(r=>r.g))];
  const val=(k,g)=>{const r=D.gen.find(x=>x.k===k&&x.g===g); return r?+r.n:0;};
  new Chart($('c-gen'),{type:'bar',data:{labels:topDel.map(r=>r.k),
      datasets:generos.map((g,i)=>({label:g,data:topDel.map(r=>val(r.k,g)),backgroundColor:QC[i%QC.length],borderRadius:4}))},
    options:{indexAxis:'y',maintainAspectRatio:false,plugins:{legend:{position:'top'}},
      scales:{x:{stacked:true,grid:{color:'#eef2f6'}},y:{stacked:true,grid:{display:false}}}}});
})();

/* Actividades preferidas por delegación */
let chartAct=null;
(function(){
  const dels=[...new Set(D.act.map(r=>r.k))].sort();
  $('f-deleg').innerHTML = dels.length ? dels.map(d=>`<option>${esc(d)}</option>`).join('') : '<option>Sin datos</option>';
  function pinta(){
    const d=$('f-deleg').value;
    const items=D.act.filter(r=>r.k===d).sort((a,b)=>b.n-a.n).slice(0,10);
    if(chartAct) chartAct.destroy();
    chartAct=new Chart($('c-act'),{type:'bar',data:{labels:items.map(r=>r.act),datasets:[{data:items.map(r=>+r.n),backgroundColor:'#005ab2',borderRadius:6}]},
      options:{indexAxis:'y',plugins:{legend:{display:false}},maintainAspectRatio:false,scales:{x:{grid:{color:'#eef2f6'}},y:{grid:{display:false}}}}});
  }
  $('f-deleg').addEventListener('change',pinta);
  if(dels.length) pinta();
})();

/* ---- Mapa de delegaciones (burbujas sobre centroides) ---- */
const HASKEY = <?= $apiKey ? 'true' : 'false' ?>;
function colorTasa(t){ return t===null?'#9ca3af':(t>=80?'#15803d':t>=60?'#65a30d':t>=40?'#d99000':'#ce3a2b'); }
window.initBlMap = function(){
  const el=$('d-map');
  const geo = rows.filter(r=>r.lat!==null && r.lng!==null && r.k!=='N/D');
  $('d-map-nota').innerHTML = `<b>${geo.length}</b> de ${rows.filter(r=>r.k!=='N/D').length} delegaciones ubicadas.`;
  if(!geo.length){ el.innerHTML='<div style="padding:24px;color:#6b7280">No se pudo ubicar ninguna delegación (no hay datos geocodificados con delegación en el portal).</div>'; return; }
  const map=new google.maps.Map(el,{center:{lat:20.59,lng:-100.39},zoom:11,mapTypeControl:false,streetViewControl:false,
    styles:[{featureType:'poi',stylers:[{visibility:'off'}]}]});
  const info=new google.maps.InfoWindow();
  const bounds=new google.maps.LatLngBounds();
  const maxU=Math.max(...geo.map(r=>r.usuarios),1);
  for(const r of geo){
    const c=colorTasa(r.tasa);
    const circle=new google.maps.Circle({
      map, center:{lat:r.lat,lng:r.lng},
      radius: 400 + Math.sqrt(r.usuarios/maxU)*2600,
      fillColor:c, fillOpacity:0.45, strokeColor:c, strokeOpacity:0.9, strokeWeight:1.5, clickable:true
    });
    bounds.extend(new google.maps.LatLng(r.lat,r.lng));
    circle.addListener('click',()=>{
      info.setContent(`<div style="font-family:Montserrat,Arial;font-size:13px;min-width:190px">
        <div style="font-weight:800;color:#254185;margin-bottom:4px">${esc(r.k)}</div>
        <div style="display:flex;justify-content:space-between"><span>Usuarios</span><b>${r.usuarios.toLocaleString()}</b></div>
        <div style="display:flex;justify-content:space-between"><span>Asistencias</span><b>${r.asistencias.toLocaleString()}</b></div>
        <div style="display:flex;justify-content:space-between"><span>Presentes</span><b style="color:#15803d">${r.presentes.toLocaleString()}</b></div>
        <div style="display:flex;justify-content:space-between"><span>Ausentes</span><b style="color:#ce3a2b">${r.ausentes.toLocaleString()}</b></div>
        <div style="display:flex;justify-content:space-between"><span>Tasa asistencia</span><b style="color:${c}">${r.tasa===null?'—':r.tasa+'%'}</b></div>
        <div style="display:flex;justify-content:space-between"><span>Edad prom.</span><b>${r.edad_prom===null?'—':r.edad_prom}</b></div>
      </div>`);
      info.setPosition({lat:r.lat,lng:r.lng});
      info.open(map);
    });
  }
  if(!bounds.isEmpty()) map.fitBounds(bounds);
  $('d-legend').innerHTML = `<span>Tamaño = usuarios · Color = tasa de asistencia:</span>`
    + [['<40%','#ce3a2b'],['40-60','#d99000'],['60-80','#65a30d'],['≥80%','#15803d'],['sin asistencias','#9ca3af']]
        .map(x=>`<span><i style="display:inline-block;width:14px;height:12px;border-radius:3px;background:${x[1]};margin-right:3px;vertical-align:middle"></i>${x[0]}</span>`).join('');
};
if(!HASKEY){ $('d-map').innerHTML='<div style="padding:20px;color:#991B1B">Google Maps API key no configurada.</div>'; }

/* Tabla + orden + CSV */
let sk='usuarios', sd=-1;
function pintaTabla(){
  const t=[...rows].sort((a,b)=>{const va=a[sk],vb=b[sk];
    if(va===null)return 1; if(vb===null)return -1;
    return (va>vb?1:va<vb?-1:0)*sd;});
  $('tbody').innerHTML=t.map(r=>{
    const c=r.tasa===null?'#9ca3af':(r.tasa>=80?'#15803d':r.tasa>=60?'#65a30d':r.tasa>=40?'#d99000':'#ce3a2b');
    return `<tr><td><b>${esc(r.k)}</b></td><td>${r.usuarios.toLocaleString()}</td><td>${r.asistencias.toLocaleString()}</td>
      <td>${r.presentes.toLocaleString()}</td><td>${r.ausentes.toLocaleString()}</td>
      <td><span class="pillp" style="background:${c}22;color:${c}">${r.tasa===null?'—':r.tasa+'%'}</span></td>
      <td>${r.edad_prom===null?'—':r.edad_prom}</td></tr>`;
  }).join('');
}
document.querySelectorAll('#tabla thead th').forEach(th=>th.addEventListener('click',()=>{
  const k=th.dataset.k; if(sk===k) sd*=-1; else {sk=k; sd=-1;} pintaTabla();
}));
$('btn-csv').addEventListener('click',()=>{
  const head=['delegacion','usuarios','asistencias','presentes','ausentes','tasa_asistencia_%','edad_promedio'];
  const lines=[head.join(',')].concat(rows.map(r=>[`"${r.k}"`,r.usuarios,r.asistencias,r.presentes,r.ausentes,r.tasa??'',r.edad_prom??''].join(',')));
  const blob=new Blob(['﻿'+lines.join('\n')],{type:'text/csv;charset=utf-8'});
  const a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download='bloque_delegaciones.csv'; a.click();
});
pintaTabla();
</script>
<?php if ($apiKey): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($apiKey) ?>&callback=initBlMap&loading=async&v=weekly"></script>
<?php endif; ?>
<?php endif; ?>
</body>
</html>
