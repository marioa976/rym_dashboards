<?php
/**
 * Ejecutivo · Tablero de dirección (Reporte 1).
 * Cruza los indicadores de todos los módulos por delegación:
 *  - KPI tiles por módulo (Obras/inversión, Áreas verdes, DIF, Zendesk, Bloque).
 *  - Gráficas: inversión, atención ciudadana y apoyos por delegación; obras por estatus.
 *  - Matriz cruzada delegación × módulo con sombreado por intensidad.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';     // guard: require_module('ejecutivo')
require_once __DIR__ . '/lib.php';

$nombre = htmlspecialchars((string)(Auth::user()['nombre'] ?? 'usuario'));
$kpis = ['obras'=>['n'=>0,'inv'=>0,'term'=>0],'areas'=>['n'=>0],'dif'=>['n'=>0,'geo'=>0],'zendesk'=>['n'=>0,'geo'=>0],'bloque'=>['n'=>0]];
$matriz = []; $estatus = []; $qrobici = null; $dbError = null;
try {
    $pdo = ej_pdo();
    $kpis = ej_kpis($pdo);
    $matriz = ej_matriz($pdo);
    $estatus = ej_obras_estatus($pdo);
    $qrobici = ej_qrobici($pdo);       // remoto, cacheado (puede ser null si no responde)
} catch (Throwable $e) { $dbError = $e->getMessage(); }

// Inyecta la columna Qrobici (viajes por delegación de estación origen) en la matriz.
foreach ($matriz as $d => &$row) { $row['qrobici'] = $qrobici['por_deleg'][$d] ?? 0; }
unset($row);

function ej_money($v): string { return $v >= 1e6 ? '$'.number_format($v/1e6,1).' MDP' : '$'.number_format((float)$v); }

// columnas de la matriz (clave, etiqueta, color acento)
$cols = [
    ['obras',  'Obras',      '#c85a2b'],
    ['inv',    'Inversión',  '#a8481f'],
    ['areas',  'Áreas v.',   '#2e9e5b'],
    ['dif',    'DIF apoyos', '#8e44ad'],
    ['tickets','Tickets',    '#005ab2'],
    ['bloque', 'Bloque',     '#159c9c'],
    ['qrobici','Qrobici',    '#e0872b'],
];
// máximos por columna (para el sombreado) y totales
$maxc = []; $tot = [];
foreach ($cols as $c) { $maxc[$c[0]] = 0; $tot[$c[0]] = 0; }
foreach ($matriz as $d => $v) foreach ($cols as $c) { $maxc[$c[0]] = max($maxc[$c[0]], $v[$c[0]]); $tot[$c[0]] += $v[$c[0]]; }

// datos para Chart.js (solo las 7 delegaciones, sin "Otras")
$degs = ej_delegaciones();
$chart = ['labels'=>[], 'inv'=>[], 'tickets'=>[], 'dif'=>[]];
foreach ($degs as $d) {
    $chart['labels'][]  = $d;
    $chart['inv'][]     = round(($matriz[$d]['inv'] ?? 0)/1e6, 2);   // MDP
    $chart['tickets'][] = (int)($matriz[$d]['tickets'] ?? 0);
    $chart['dif'][]     = (int)($matriz[$d]['dif'] ?? 0);
}
$estLabels = array_map(fn($r)=>$r['estatus'], $estatus);
$estData   = array_map(fn($r)=>(int)$r['n'], $estatus);
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ejecutivo · Tablero</title>
  <link rel="stylesheet" href="../../assets/css/qro.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
  <style>
    .ej-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:20px}
    .ej-tile{background:#fff;border:1px solid var(--qro-border);border-radius:14px;padding:16px 18px;
      text-decoration:none;color:inherit;border-left:5px solid var(--ac);display:block;transition:box-shadow .15s}
    .ej-tile:hover{box-shadow:0 6px 18px rgba(0,0,0,.08)}
    .ej-tile .t{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--ac)}
    .ej-tile .v{font-size:26px;font-weight:800;color:var(--qro-text-primary);line-height:1.15;margin-top:3px}
    .ej-tile .s{font-size:12px;color:var(--qro-text-secondary);margin-top:2px}
    .ej-grid{display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:20px}
    @media(max-width:900px){.ej-grid{grid-template-columns:1fr}}
    .ej-card{background:#fff;border:1px solid var(--qro-border);border-radius:14px;padding:16px 18px}
    .ej-card h3{margin:0 0 10px;font-size:14px}
    .ej-charts{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}
    @media(max-width:900px){.ej-charts{grid-template-columns:1fr}}
    .ej-chart-wrap{position:relative;height:250px}
    table.ej-mtx{width:100%;border-collapse:collapse;font-size:13px}
    table.ej-mtx th,table.ej-mtx td{padding:9px 12px;border-bottom:1px solid #eef0f2;text-align:right;font-variant-numeric:tabular-nums}
    table.ej-mtx th:first-child,table.ej-mtx td:first-child{text-align:left;font-weight:600}
    table.ej-mtx thead th{position:sticky;top:0;background:#f5f7fb;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:var(--qro-text-secondary)}
    table.ej-mtx tfoot td{font-weight:800;border-top:2px solid var(--qro-border);background:#fafbfe}
    .ej-scroll{overflow:auto;border:1px solid var(--qro-border);border-radius:14px}
  </style>
</head>
<body>
<?php $portalModulo = 'Ejecutivo'; $navActive = 'home'; include __DIR__ . '/_nav.php'; ?>

<main class="content" style="padding:28px 32px">
  <div style="background:linear-gradient(120deg,#14224a,#254185);color:#fff;border-radius:16px;padding:22px 26px;margin-bottom:20px">
    <h1 style="color:#fff;margin:0 0 4px;font-size:23px">📊 Tablero Ejecutivo</h1>
    <p style="margin:0;opacity:.92;font-size:14px">Hola, <?= $nombre ?>. Indicadores cruzados de todos los módulos, por delegación.</p>
  </div>

  <?php if ($dbError): ?><div class="alert alert-danger">No se pudieron cargar los datos.<br><span style="font-size:12px"><?= htmlspecialchars($dbError) ?></span></div><?php endif; ?>

  <div class="ej-tiles">
    <a class="ej-tile" href="../obras/index.php" style="--ac:#c85a2b">
      <div class="t">🏗 Obras</div><div class="v"><?= number_format($kpis['obras']['n']) ?></div>
      <div class="s"><?= ej_money($kpis['obras']['inv']) ?> · <?= $kpis['obras']['term'] ?> terminadas</div></a>
    <a class="ej-tile" href="../areasverdes/index.php" style="--ac:#2e9e5b">
      <div class="t">🌳 Áreas verdes</div><div class="v"><?= number_format($kpis['areas']['n']) ?></div>
      <div class="s">áreas mapeadas</div></a>
    <a class="ej-tile" href="../dif/dashboard.php" style="--ac:#8e44ad">
      <div class="t">🤝 DIF · padrón</div><div class="v"><?= number_format($kpis['dif']['n']) ?></div>
      <div class="s"><?= number_format($kpis['dif']['geo']) ?> geolocalizados</div></a>
    <a class="ej-tile" href="../zendesk/dashboard.php" style="--ac:#005ab2">
      <div class="t">📮 Zendesk · tickets</div><div class="v"><?= number_format($kpis['zendesk']['n']) ?></div>
      <div class="s"><?= number_format($kpis['zendesk']['geo']) ?> geolocalizados</div></a>
    <a class="ej-tile" href="../bloque/index.php" style="--ac:#159c9c">
      <div class="t">💡 Bloque</div><div class="v"><?= number_format($kpis['bloque']['n']) ?></div>
      <div class="s">beneficiarios</div></a>
    <a class="ej-tile" href="../qrobici/index.php" style="--ac:#e0872b">
      <div class="t">🚲 Qrobici</div><div class="v"><?= $qrobici ? number_format($qrobici['kpis']['viajes']) : '—' ?></div>
      <div class="s"><?= $qrobici ? number_format($qrobici['kpis']['estaciones']).' estaciones · '.number_format($qrobici['kpis']['km']).' km' : 'sin conexión remota' ?></div></a>
  </div>

  <div class="ej-charts">
    <div class="ej-card"><h3>Inversión en obras por delegación (MDP)</h3><div class="ej-chart-wrap"><canvas id="c-inv"></canvas></div></div>
    <div class="ej-card"><h3>Atención ciudadana · tickets por delegación</h3><div class="ej-chart-wrap"><canvas id="c-tickets"></canvas></div></div>
    <div class="ej-card"><h3>Apoyos DIF por delegación</h3><div class="ej-chart-wrap"><canvas id="c-dif"></canvas></div></div>
    <div class="ej-card"><h3>Obras por estatus</h3><div class="ej-chart-wrap"><canvas id="c-est"></canvas></div></div>
  </div>

  <div class="ej-card" style="padding:0;overflow:hidden">
    <h3 style="padding:16px 18px 0">Matriz cruzada por delegación</h3>
    <p class="text-secondary" style="padding:0 18px;font-size:12px;margin:2px 0 8px">Intensidad de color = valor relativo dentro de cada columna.</p>
    <div class="ej-scroll" style="border:0;border-radius:0">
      <table class="ej-mtx">
        <thead><tr><th>Delegación</th>
          <?php foreach ($cols as $c): ?><th style="color:<?= $c[2] ?>"><?= $c[1] ?></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
          <?php foreach ($matriz as $d => $v): ?>
          <tr>
            <td><?= htmlspecialchars($d) ?></td>
            <?php foreach ($cols as $c):
              $val = $v[$c[0]]; $mx = $maxc[$c[0]] ?: 1; $a = $mx>0 ? round(($val/$mx)*0.55, 3) : 0;
              [$r,$g,$b] = sscanf($c[2], "#%02x%02x%02x");
              $cell = $c[0]==='inv' ? ej_money($val) : number_format($val);
            ?>
              <td style="background:rgba(<?= $r ?>,<?= $g ?>,<?= $b ?>,<?= $a ?>)"><?= $cell ?></td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td>Total</td>
          <?php foreach ($cols as $c): ?><td><?= $c[0]==='inv' ? ej_money($tot[$c[0]]) : number_format($tot[$c[0]]) ?></td><?php endforeach; ?>
        </tr></tfoot>
      </table>
    </div>
  </div>
</main>

<script>
const CH = <?= json_encode($chart, JSON_UNESCAPED_UNICODE) ?>;
const EST = {labels: <?= json_encode($estLabels, JSON_UNESCAPED_UNICODE) ?>, data: <?= json_encode($estData) ?>};
const shortLabels = CH.labels.map(s=>s.replace('Villa ','').replace(' y Hernández','').replace('Santa Rosa Jáuregui','Sta Rosa').replace('Félix Osores Sotomayor','Félix Osores').replace('Felipe Carrillo Puerto','F. Carrillo').replace('Epigmenio González','Epigmenio').replace('Centro Histórico','Centro Hist.'));
const EST_COLOR = {'TERMINADA':'#2e7d32','EN EJECUCIÓN':'#1565c0','EN LICITACIÓN':'#f9a825','EN SUSPENSIÓN':'#c62828'};
const baseOpts = {responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}}};

new Chart(document.getElementById('c-inv'), {type:'bar',
  data:{labels:shortLabels,datasets:[{data:CH.inv,backgroundColor:'#c85a2b',borderRadius:5}]},
  options:{...baseOpts,scales:{y:{ticks:{callback:v=>'$'+v+'M'}}}}});
new Chart(document.getElementById('c-tickets'), {type:'bar',
  data:{labels:shortLabels,datasets:[{data:CH.tickets,backgroundColor:'#005ab2',borderRadius:5}]},
  options:baseOpts});
new Chart(document.getElementById('c-dif'), {type:'bar',
  data:{labels:shortLabels,datasets:[{data:CH.dif,backgroundColor:'#8e44ad',borderRadius:5}]},
  options:baseOpts});
new Chart(document.getElementById('c-est'), {type:'doughnut',
  data:{labels:EST.labels,datasets:[{data:EST.data,backgroundColor:EST.labels.map(s=>EST_COLOR[s]||'#757575')}]},
  options:{...baseOpts,plugins:{legend:{display:true,position:'bottom',labels:{font:{size:11}}}}}});
</script>
</body>
</html>
