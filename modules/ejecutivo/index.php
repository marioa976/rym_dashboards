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
?><?php
$ktTitle  = 'Tablero Ejecutivo';
$ktActive = 'ejecutivo';
require __DIR__ . '/../../views/layout/kt_top.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
  <!-- Hero -->
  <div class="rounded-xl p-6 mb-5 text-white" style="background:linear-gradient(120deg,#14224a,#254185)">
    <h1 class="text-2xl font-bold text-white">📊 Tablero Ejecutivo</h1>
    <p class="text-sm opacity-90 mt-1">Hola, <?= $nombre ?>. Indicadores cruzados de todos los módulos, por delegación.</p>
  </div>

  <?php if ($dbError): ?>
    <div class="rounded-lg border border-destructive/30 bg-destructive/10 text-destructive px-4 py-3 mb-5 text-sm">No se pudieron cargar los datos.<br><span class="text-xs opacity-80"><?= htmlspecialchars($dbError) ?></span></div>
  <?php endif; ?>

  <!-- KPI tiles -->
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4 mb-5">
    <?php
    $tiles = [
      ['🏗 Obras',           number_format($kpis['obras']['n']),   ej_money($kpis['obras']['inv']).' · '.$kpis['obras']['term'].' terminadas', '#c85a2b', '../obras/index.php'],
      ['🌳 Áreas verdes',    number_format($kpis['areas']['n']),   'áreas mapeadas', '#2e9e5b', '../areasverdes/index.php'],
      ['🤝 DIF · padrón',    number_format($kpis['dif']['n']),     number_format($kpis['dif']['geo']).' geolocalizados', '#8e44ad', '../dif/dashboard.php'],
      ['📮 Zendesk',         number_format($kpis['zendesk']['n']), number_format($kpis['zendesk']['geo']).' geolocalizados', '#005ab2', '../zendesk/dashboard.php'],
      ['💡 Bloque',          number_format($kpis['bloque']['n']),  'beneficiarios', '#159c9c', '../bloque/index.php'],
      ['🚲 Qrobici',         $qrobici ? number_format($qrobici['kpis']['viajes']) : '—', $qrobici ? number_format($qrobici['kpis']['estaciones']).' estaciones · '.number_format($qrobici['kpis']['km']).' km' : 'sin conexión remota', '#e0872b', '../qrobici/index.php'],
    ];
    foreach ($tiles as [$t, $v, $s, $ac, $href]): ?>
      <a class="kt-card hover:shadow-md transition-shadow" href="<?= $href ?>" style="border-inline-start:4px solid <?= $ac ?>">
        <div class="kt-card-content p-4 flex flex-col gap-1">
          <span class="text-xs font-bold uppercase tracking-wide" style="color:<?= $ac ?>"><?= $t ?></span>
          <span class="text-2xl font-bold text-mono leading-tight"><?= $v ?></span>
          <span class="text-xs text-secondary-foreground"><?= $s ?></span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Gráficas -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">
    <?php foreach ([['c-inv','Inversión en obras por delegación (MDP)'],['c-tickets','Atención ciudadana · tickets por delegación'],['c-dif','Apoyos DIF por delegación'],['c-est','Obras por estatus']] as [$cid, $ctitle]): ?>
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title"><?= $ctitle ?></h3></div>
        <div class="kt-card-content"><div class="relative h-[250px]"><canvas id="<?= $cid ?>"></canvas></div></div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Matriz cruzada -->
  <div class="kt-card">
    <div class="kt-card-header flex-col items-start gap-1 py-4">
      <h3 class="kt-card-title">Matriz cruzada por delegación</h3>
      <span class="text-xs text-secondary-foreground font-normal">Intensidad de color = valor relativo dentro de cada columna.</span>
    </div>
    <div class="kt-card-content p-0">
      <div class="kt-scrollable-x-auto">
        <table class="kt-table kt-table-border text-sm">
          <thead>
            <tr>
              <th class="text-start">Delegación</th>
              <?php foreach ($cols as $c): ?><th class="text-end" style="color:<?= $c[2] ?>"><?= $c[1] ?></th><?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($matriz as $d => $v): ?>
            <tr>
              <td class="font-semibold"><?= htmlspecialchars($d) ?></td>
              <?php foreach ($cols as $c):
                $val = $v[$c[0]]; $mx = $maxc[$c[0]] ?: 1; $a = $mx>0 ? round(($val/$mx)*0.55, 3) : 0;
                [$r,$g,$b] = sscanf($c[2], "#%02x%02x%02x");
                $cell = $c[0]==='inv' ? ej_money($val) : number_format($val); ?>
                <td class="text-end tabular-nums" style="background:rgba(<?= $r ?>,<?= $g ?>,<?= $b ?>,<?= $a ?>)"><?= $cell ?></td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="font-bold">
              <td>Total</td>
              <?php foreach ($cols as $c): ?><td class="text-end tabular-nums"><?= $c[0]==='inv' ? ej_money($tot[$c[0]]) : number_format($tot[$c[0]]) ?></td><?php endforeach; ?>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
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
<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
