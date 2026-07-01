<?php
/**
 * Qrobus · Principales KPIs de beneficiarios Unidos (dwh_unidos).
 * Perfil demográfico y de operación, con foco en el TIPO de aplicante.
 * Solo lectura.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';   // guard: require_module('qrobus')
require_once __DIR__ . '/lib.php';

$tabla = qb_tabla();
$D = null; $dbError = null;
try {
    $pdo = qb_pdo();
    $q   = fn(string $sql) => $pdo->query($sql)->fetchAll();
    $one = fn(string $sql) => $pdo->query($sql)->fetchColumn();
    $CO  = "latitud IS NOT NULL AND latitud <> 0 AND longitud IS NOT NULL AND longitud <> 0";

    $D = [
        'total'      => (int)$one("SELECT COUNT(*) FROM `$tabla`"),
        'geocod'     => (int)$one("SELECT COUNT(*) FROM `$tabla` WHERE $CO"),
        'municipios' => (int)$one("SELECT COUNT(DISTINCT municipio) FROM `$tabla` WHERE TRIM(COALESCE(municipio,''))<>''"),
        'tipos'      => (int)$one("SELECT COUNT(DISTINCT tipo_nombre) FROM `$tabla` WHERE TRIM(COALESCE(tipo_nombre,''))<>''"),
        'edad_prom'  => round((float)$one("SELECT AVG(edad_anios) FROM `$tabla` WHERE edad_anios>0"), 1),
        'viajes_prom'=> round((float)$one("SELECT AVG(viajes_por_dia) FROM `$tabla` WHERE viajes_por_dia>0"), 1),

        'tipo'       => $q("SELECT COALESCE(NULLIF(TRIM(tipo_nombre),''),'N/D') k, COUNT(*) n FROM `$tabla` GROUP BY k ORDER BY n DESC LIMIT 14"),
        'sexo'       => $q("SELECT COALESCE(NULLIF(TRIM(sexo),''),'N/D') k, COUNT(*) n FROM `$tabla` GROUP BY k ORDER BY n DESC"),
        'estatus'    => $q("SELECT COALESCE(NULLIF(TRIM(estatus_nombre),''),'N/D') k, COUNT(*) n FROM `$tabla` GROUP BY k ORDER BY n DESC LIMIT 8"),
        'municipio'  => $q("SELECT COALESCE(NULLIF(TRIM(municipio),''),'N/D') k, COUNT(*) n FROM `$tabla` GROUP BY k ORDER BY n DESC LIMIT 12"),
        'colonia'    => $q("SELECT COALESCE(NULLIF(TRIM(colonia),''),'N/D') k, COUNT(*) n FROM `$tabla` GROUP BY k ORDER BY n DESC LIMIT 12"),
        'educativo'  => $q("SELECT COALESCE(NULLIF(TRIM(nivel_educativo),''),'N/D') k, COUNT(*) n FROM `$tabla` GROUP BY k ORDER BY n DESC LIMIT 10"),
        'mes'        => $q("SELECT DATE_FORMAT(fecha,'%Y-%m') m, COUNT(*) n FROM `$tabla` WHERE fecha IS NOT NULL GROUP BY m ORDER BY m"),
        // Pirámide: grupo de edad × sexo
        'pir'        => $q("SELECT CASE
                                WHEN edad_anios<18 THEN '0-17' WHEN edad_anios<26 THEN '18-25'
                                WHEN edad_anios<36 THEN '26-35' WHEN edad_anios<46 THEN '36-45'
                                WHEN edad_anios<60 THEN '46-59' ELSE '60+' END g,
                                COALESCE(NULLIF(TRIM(sexo),''),'N/D') s, COUNT(*) n
                             FROM `$tabla` WHERE edad_anios>0 GROUP BY g, s"),
        // Cruce tipo × sexo (para stacked)
        'tipo_sexo'  => $q("SELECT COALESCE(NULLIF(TRIM(tipo_nombre),''),'N/D') t, COALESCE(NULLIF(TRIM(sexo),''),'N/D') s, COUNT(*) n
                              FROM `$tabla` GROUP BY t, s"),
    ];
    $D['sexosTop2'] = array_slice(array_column($D['sexo'], 'k'), 0, 2);
} catch (Throwable $e) { $dbError = $e->getMessage(); }

$pct = ($D && $D['total']>0) ? round($D['geocod']/$D['total']*100) : 0;
$topTipo = ($D && $D['tipo']) ? $D['tipo'][0] : null;
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Qrobus · KPIs</title>
  <link rel="stylesheet" href="../../assets/css/qro.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <style>
    .k-hero{background:linear-gradient(120deg,var(--qro-blue-dark),var(--qro-blue));color:#fff;border-radius:16px;padding:22px 26px;margin-bottom:18px}
    .k-hero h1{color:#fff;margin:0 0 4px;font-size:24px}.k-hero p{margin:0;opacity:.9;font-size:14px}
    .k-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:22px}
    .k-card{background:#fff;border:1px solid var(--qro-border);border-radius:14px;padding:16px 18px;position:relative;overflow:hidden;box-shadow:var(--qro-shadow-sm)}
    .k-card .acc{position:absolute;left:0;top:0;bottom:0;width:4px}
    .k-card .v{font-size:26px;font-weight:800;color:var(--qro-blue-dark);line-height:1.05}
    .k-card .l{font-size:12px;color:var(--qro-text-secondary);font-weight:600;margin-top:4px}
    .k-card .s{font-size:11px;color:var(--qro-text-muted);margin-top:2px}
    .k-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    @media(max-width:900px){.k-grid{grid-template-columns:1fr}}
    .k-panel{background:#fff;border:1px solid var(--qro-border);border-radius:14px;padding:16px 18px;box-shadow:var(--qro-shadow-sm)}
    .k-panel h3{margin:0 0 10px;font-size:14px;color:var(--qro-blue-dark)}
    .k-panel .box{height:300px}
    .k-wide{grid-column:1/-1}
  </style>
</head>
<body>
<?php $portalModulo='Qrobus'; $navActive='kpis'; include __DIR__ . '/_nav.php'; ?>

<main class="content" style="padding:28px 32px">
  <?php if ($dbError): ?>
    <div class="alert alert-danger">No se pudo consultar la BD de Qrobus.<br><span style="font-size:12px"><?= htmlspecialchars($dbError) ?></span></div>
  <?php else: ?>
    <div class="k-hero">
      <h1>Principales KPIs · Unidos</h1>
      <p>Perfil demográfico y de operación de <strong><?= number_format($D['total']) ?></strong> beneficiarios<?= $topTipo ? ' · tipo predominante: <strong>'.htmlspecialchars($topTipo['k']).'</strong> ('.number_format($topTipo['n']).')' : '' ?>.</p>
    </div>

    <div class="k-kpis">
      <div class="k-card"><span class="acc" style="background:#254185"></span><div class="v"><?= number_format($D['total']) ?></div><div class="l">Beneficiarios</div></div>
      <div class="k-card"><span class="acc" style="background:#188a5b"></span><div class="v"><?= number_format($D['geocod']) ?></div><div class="l">Geocodificados</div><div class="s"><?= $pct ?>% del total</div></div>
      <div class="k-card"><span class="acc" style="background:#ce3a2b"></span><div class="v"><?= number_format($D['tipos']) ?></div><div class="l">Tipos de aplicante</div></div>
      <div class="k-card"><span class="acc" style="background:#005ab2"></span><div class="v"><?= number_format($D['municipios']) ?></div><div class="l">Municipios</div></div>
      <div class="k-card"><span class="acc" style="background:#d99000"></span><div class="v"><?= number_format($D['edad_prom'],1) ?></div><div class="l">Edad promedio</div></div>
      <div class="k-card"><span class="acc" style="background:#2a9eda"></span><div class="v"><?= number_format($D['viajes_prom'],1) ?></div><div class="l">Viajes/día (prom.)</div></div>
    </div>

    <div class="k-grid">
      <div class="k-panel k-wide"><h3>Tipo de aplicante</h3><div class="box"><canvas id="c-tipo"></canvas></div></div>
      <div class="k-panel"><h3>Pirámide de edad por sexo</h3><div class="box"><canvas id="c-pir"></canvas></div></div>
      <div class="k-panel"><h3>Tipo de aplicante × sexo</h3><div class="box"><canvas id="c-tiposexo"></canvas></div></div>
      <div class="k-panel"><h3>Top municipios</h3><div class="box"><canvas id="c-mun"></canvas></div></div>
      <div class="k-panel"><h3>Top colonias</h3><div class="box"><canvas id="c-col"></canvas></div></div>
      <div class="k-panel"><h3>Estatus</h3><div class="box"><canvas id="c-est"></canvas></div></div>
      <div class="k-panel"><h3>Nivel educativo</h3><div class="box"><canvas id="c-edu"></canvas></div></div>
      <div class="k-panel k-wide"><h3>Registros por mes</h3><div class="box"><canvas id="c-mes"></canvas></div></div>
    </div>
  <?php endif; ?>
</main>

<?php if ($D): ?>
<script>
const D = <?= json_encode($D, JSON_UNESCAPED_UNICODE) ?>;
const QC = ['#254185','#005ab2','#2a9eda','#188a5b','#d99000','#ce3a2b','#1a2f63','#5b667a','#65a30d','#8b5cf6','#0ea5e9','#b45309','#16a34a','#7f1d1d'];
Chart.defaults.font.family = "'Montserrat',Arial,sans-serif";
const donut=(id,rows)=>{ const el=document.getElementById(id); if(!el)return;
  new Chart(el,{type:'doughnut',data:{labels:rows.map(r=>r.k),datasets:[{data:rows.map(r=>+r.n),backgroundColor:QC,borderWidth:1,borderColor:'#fff'}]},
    options:{plugins:{legend:{position:'right',labels:{boxWidth:12,font:{size:11}}}},cutout:'55%',maintainAspectRatio:false}}); };
const bars=(id,labels,data,color='#005ab2',horizontal=false)=>{ const el=document.getElementById(id); if(!el)return;
  new Chart(el,{type:'bar',data:{labels,datasets:[{data,backgroundColor:color,borderRadius:6}]},
    options:{indexAxis:horizontal?'y':'x',plugins:{legend:{display:false}},maintainAspectRatio:false,
      scales:{x:{grid:{color:'#eef2f6'}},y:{grid:{color:'#eef2f6'}}}}}); };

bars('c-tipo', D.tipo.map(r=>r.k), D.tipo.map(r=>+r.n), '#ce3a2b', true);
bars('c-mun', D.municipio.map(r=>r.k), D.municipio.map(r=>+r.n), '#254185', true);
bars('c-col', D.colonia.map(r=>r.k), D.colonia.map(r=>+r.n), '#005ab2', true);
donut('c-est', D.estatus);
bars('c-edu', D.educativo.map(r=>r.k), D.educativo.map(r=>+r.n), '#188a5b', true);

/* Pirámide de edad por sexo */
(function(){
  const el=document.getElementById('c-pir'); if(!el)return;
  const grupos=['0-17','18-25','26-35','36-45','46-59','60+'];
  const [sA,sB] = D.sexosTop2.length>=2 ? D.sexosTop2 : (D.sexosTop2.concat(['—','—'])).slice(0,2);
  const get=(g,s)=>{ const r=D.pir.find(x=>x.g===g&&x.s===s); return r?+r.n:0; };
  new Chart(el,{type:'bar',data:{labels:grupos,datasets:[
      {label:sA,data:grupos.map(g=>-get(g,sA)),backgroundColor:'#005ab2',borderRadius:4},
      {label:sB,data:grupos.map(g=> get(g,sB)),backgroundColor:'#ce3a2b',borderRadius:4}]},
    options:{indexAxis:'y',maintainAspectRatio:false,plugins:{legend:{position:'top'},
      tooltip:{callbacks:{label:c=>c.dataset.label+': '+Math.abs(c.raw).toLocaleString()}}},
      scales:{x:{stacked:true,grid:{color:'#eef2f6'},ticks:{callback:v=>Math.abs(v)}},y:{stacked:true,grid:{display:false}}}}});
})();

/* Tipo × sexo (stacked, top tipos) */
(function(){
  const el=document.getElementById('c-tiposexo'); if(!el)return;
  const topTipos=D.tipo.slice(0,7).map(r=>r.k);
  const sexos=[...new Set(D.tipo_sexo.map(r=>r.s))];
  const val=(t,s)=>{ const r=D.tipo_sexo.find(x=>x.t===t&&x.s===s); return r?+r.n:0; };
  const ds=sexos.map((s,i)=>({label:s,data:topTipos.map(t=>val(t,s)),backgroundColor:QC[i%QC.length],borderRadius:4}));
  new Chart(el,{type:'bar',data:{labels:topTipos,datasets:ds},
    options:{indexAxis:'y',maintainAspectRatio:false,plugins:{legend:{position:'top'}},
      scales:{x:{stacked:true,grid:{color:'#eef2f6'}},y:{stacked:true,grid:{display:false}}}}});
})();

(function(){ const el=document.getElementById('c-mes'); if(!el)return;
  new Chart(el,{type:'line',data:{labels:D.mes.map(r=>r.m),datasets:[{data:D.mes.map(r=>+r.n),borderColor:'#005ab2',backgroundColor:'rgba(0,90,178,.12)',fill:true,tension:.3,pointRadius:2}]},
    options:{plugins:{legend:{display:false}},maintainAspectRatio:false,scales:{x:{grid:{display:false}},y:{grid:{color:'#eef2f6'},beginAtZero:true}}}}); })();
</script>
<?php endif; ?>
</body>
</html>
