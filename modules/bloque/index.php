<?php
/**
 * Bloque · Tablero (esquema nuevo). Edificio de innovación: usuarios, eventos,
 * sesiones y asistencia. KPIs + demografía (CURP) + por delegación + tendencia.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';     // guard: require_module('bloque')
require_once __DIR__ . '/lib.php';

$nombre = htmlspecialchars((string)(Auth::user()['nombre'] ?? 'usuario'));
$k = ['usuarios'=>0,'eventos'=>0,'sesiones'=>0,'registros'=>0,'asistentes'=>0,'cupo_total'=>0];
$demo = ['sexo'=>['Hombres'=>0,'Mujeres'=>0,'N/D'=>0],'edad'=>[],'edad_prom'=>null];
$deleg = []; $serie = []; $dbError = null;
try {
    $pdo   = bloq_pdo();
    $k     = bloq_kpis($pdo);
    $demo  = bloq_demografia($pdo);
    $deleg = bloq_por_delegacion($pdo, 10);
    $serie = bloq_serie_dia($pdo, 120);
} catch (Throwable $e) { $dbError = $e->getMessage(); }
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bloque · Tablero</title>
  <link rel="stylesheet" href="../../assets/css/qro.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
  <style>
    .bl-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px}
    .bl-kpi{background:#fff;border:1px solid var(--qro-border);border-radius:12px;padding:14px 16px}
    .bl-kpi .v{font-size:24px;font-weight:800;color:#005ab2;line-height:1.1}
    .bl-kpi .l{font-size:12px;color:var(--qro-text-secondary);font-weight:600;margin-top:2px}
    .bl-charts{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}
    @media(max-width:900px){.bl-charts{grid-template-columns:1fr}}
    .bl-card{background:#fff;border:1px solid var(--qro-border);border-radius:14px;padding:16px 18px}
    .bl-card h3{margin:0 0 10px;font-size:14px}
    .bl-wrap{position:relative;height:250px}
  </style>
</head>
<body>
<?php $portalModulo = 'Bloque'; $navActive = 'home'; include __DIR__ . '/_nav.php'; ?>

<main class="content" style="padding:28px 32px">
  <div style="background:linear-gradient(120deg,var(--qro-blue-dark),var(--qro-blue));color:#fff;border-radius:16px;padding:22px 26px;margin-bottom:20px">
    <h1 style="color:#fff;margin:0 0 4px;font-size:23px">💡 Bloque · Innovación y tecnología</h1>
    <p style="margin:0;opacity:.92;font-size:14px">Hola, <?= $nombre ?>. Usuarios, eventos y asistencia del edificio Bloque.</p>
  </div>

  <?php if ($dbError): ?><div class="alert alert-danger">No se pudieron cargar los datos.<br><span style="font-size:12px"><?= htmlspecialchars($dbError) ?></span></div><?php endif; ?>

  <div class="bl-kpis">
    <div class="bl-kpi"><div class="v"><?= number_format($k['usuarios']) ?></div><div class="l">Usuarios registrados</div></div>
    <div class="bl-kpi"><div class="v"><?= number_format($k['eventos']) ?></div><div class="l">Eventos</div></div>
    <div class="bl-kpi"><div class="v"><?= number_format($k['sesiones']) ?></div><div class="l">Sesiones</div></div>
    <div class="bl-kpi"><div class="v"><?= number_format($k['asistentes']) ?></div><div class="l">Asistentes únicos</div></div>
    <div class="bl-kpi"><div class="v"><?= number_format($k['registros']) ?></div><div class="l">Registros de asistencia</div></div>
    <div class="bl-kpi"><div class="v"><?= number_format($k['cupo_total']) ?></div><div class="l">Cupo total ofertado</div></div>
  </div>

  <div class="bl-charts">
    <div class="bl-card"><h3>Sexo</h3><div class="bl-wrap"><canvas id="c-sexo"></canvas></div></div>
    <div class="bl-card"><h3>Edad<?= $demo['edad_prom']!==null?' · promedio '.$demo['edad_prom'].' años':'' ?></h3><div class="bl-wrap"><canvas id="c-edad"></canvas></div></div>
    <div class="bl-card" style="grid-column:1/-1"><h3>Asistencia por día (últimos meses)</h3><div class="bl-wrap"><canvas id="c-serie"></canvas></div></div>
    <div class="bl-card" style="grid-column:1/-1"><h3>Usuarios por delegación / municipio (top 10)</h3><div class="bl-wrap" style="height:300px"><canvas id="c-deleg"></canvas></div></div>
  </div>

  <a class="btn btn-primary" href="eventos.php">Ver eventos y ocupación →</a>
</main>

<script>
const SEXO = <?= json_encode($demo['sexo'], JSON_UNESCAPED_UNICODE) ?>;
const EDAD = <?= json_encode($demo['edad'], JSON_UNESCAPED_UNICODE) ?>;
const SERIE = <?= json_encode($serie, JSON_UNESCAPED_UNICODE) ?>;
const DELEG = <?= json_encode($deleg, JSON_UNESCAPED_UNICODE) ?>;
const base = {responsive:true,maintainAspectRatio:false};

new Chart(document.getElementById('c-sexo'),{type:'doughnut',
  data:{labels:Object.keys(SEXO),datasets:[{data:Object.values(SEXO),backgroundColor:['#005ab2','#e0559b','#c9ced6']}]},
  options:{...base,plugins:{legend:{position:'bottom',labels:{font:{size:12}}}}}});

new Chart(document.getElementById('c-edad'),{type:'bar',
  data:{labels:Object.keys(EDAD),datasets:[{data:Object.values(EDAD),backgroundColor:'#2a9eda',borderRadius:5}]},
  options:{...base,plugins:{legend:{display:false}}}});

new Chart(document.getElementById('c-serie'),{type:'line',
  data:{labels:SERIE.map(r=>r.d),datasets:[{data:SERIE.map(r=>+r.n),borderColor:'#005ab2',backgroundColor:'rgba(0,90,178,.12)',fill:true,tension:.3,pointRadius:2}]},
  options:{...base,plugins:{legend:{display:false}},scales:{x:{ticks:{maxTicksLimit:12}}}}});

new Chart(document.getElementById('c-deleg'),{type:'bar',
  data:{labels:DELEG.map(r=>r.d),datasets:[{data:DELEG.map(r=>+r.n),backgroundColor:'#254185',borderRadius:5}]},
  options:{...base,indexAxis:'y',plugins:{legend:{display:false}}}});
</script>
</body>
</html>
