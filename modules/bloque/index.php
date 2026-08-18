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
?><?php
$ktTitle  = 'Bloque · Tablero';
$ktActive = 'bloque';
require __DIR__ . '/../../views/layout/kt_top.php';
?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
  <div class="rounded-xl p-6 mb-5 text-white" style="background:linear-gradient(120deg,var(--qro-blue-dark),var(--qro-blue))">
    <h1 class="text-2xl font-bold text-white">💡 Bloque · Innovación y tecnología</h1>
    <p class="text-sm opacity-90 mt-1">Hola, <?= $nombre ?>. Usuarios, eventos y asistencia del edificio Bloque.</p>
  </div>

  <?php if ($dbError): ?>
    <div class="rounded-lg border border-destructive/30 bg-destructive/10 text-destructive px-4 py-3 mb-5 text-sm">No se pudieron cargar los datos.<br><span class="text-xs opacity-80"><?= htmlspecialchars($dbError) ?></span></div>
  <?php endif; ?>

  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-5">
    <?php foreach ([
      ['Usuarios registrados', $k['usuarios']], ['Eventos', $k['eventos']], ['Sesiones', $k['sesiones']],
      ['Asistentes únicos', $k['asistentes']], ['Registros de asistencia', $k['registros']], ['Cupo total ofertado', $k['cupo_total']],
    ] as [$l, $v]): ?>
      <div class="kt-card"><div class="kt-card-content p-4 flex flex-col gap-0.5">
        <span class="text-2xl font-bold text-primary leading-tight"><?= number_format($v) ?></span>
        <span class="text-xs text-secondary-foreground font-semibold"><?= $l ?></span>
      </div></div>
    <?php endforeach; ?>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">
    <div class="kt-card"><div class="kt-card-header"><h3 class="kt-card-title">Sexo</h3></div><div class="kt-card-content"><div class="relative h-[250px]"><canvas id="c-sexo"></canvas></div></div></div>
    <div class="kt-card"><div class="kt-card-header"><h3 class="kt-card-title">Edad<?= $demo['edad_prom']!==null?' · promedio '.$demo['edad_prom'].' años':'' ?></h3></div><div class="kt-card-content"><div class="relative h-[250px]"><canvas id="c-edad"></canvas></div></div></div>
    <div class="kt-card lg:col-span-2"><div class="kt-card-header"><h3 class="kt-card-title">Asistencia por día (últimos meses)</h3></div><div class="kt-card-content"><div class="relative h-[250px]"><canvas id="c-serie"></canvas></div></div></div>
    <div class="kt-card lg:col-span-2"><div class="kt-card-header"><h3 class="kt-card-title">Usuarios por delegación / municipio (top 10)</h3></div><div class="kt-card-content"><div class="relative h-[300px]"><canvas id="c-deleg"></canvas></div></div></div>
  </div>

  <a class="kt-btn kt-btn-primary" href="eventos.php">Ver eventos y ocupación →</a>

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
<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
