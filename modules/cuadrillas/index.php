<?php
/**
 * Cuadrillas · Tablero. Punto de entrada del módulo: KPIs + accesos.
 * (La asignación/despacho y el seguimiento de órdenes llegan en las siguientes
 *  fases; por ahora el tablero muestra el estado y liga al padrón.)
 */
declare(strict_types=1);
require_once __DIR__ . '/../../core/guard.php';
require_module('cuadrillas');
require_once __DIR__ . '/lib.php';

$pdo = cuad_pdo();
cuad_ensure_schema($pdo);
$k = cuad_kpis($pdo);

$ktTitle = 'Cuadrillas';
$ktActive = 'cuadrillas';
require __DIR__ . '/../../views/layout/kt_top.php';

$kpis = [
  ['delivery',     'Cuadrillas activas', $k['cuadrillas']],
  ['people',       'Operadores',         $k['operadores']],
  ['dispatch',     'Órdenes abiertas',   $k['ordenes_abiertas']],
  ['geolocation',  'Paradas pendientes', $k['paradas_pend']],
];
?>
<style>
:where(#cuad) .kgrid{ display:grid; grid-template-columns:repeat(4,1fr); gap:16px; }
:where(#cuad) .kc{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:18px 20px; }
:where(#cuad) .kc .ic{ width:38px; height:38px; border-radius:10px; background:color-mix(in srgb,var(--primary) 12%, transparent); color:var(--primary); display:flex; align-items:center; justify-content:center; font-size:19px; margin-bottom:12px; }
:where(#cuad) .kc .v{ font-size:30px; font-weight:800; letter-spacing:-.02em; line-height:1; }
:where(#cuad) .kc .l{ font-size:13px; color:var(--muted-foreground); margin-top:6px; }
:where(#cuad) .acts{ display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-top:6px; }
:where(#cuad) .ac{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:20px; display:flex; flex-direction:column; gap:7px; text-decoration:none; color:inherit; transition:.15s; }
:where(#cuad) .ac:hover{ border-color:var(--primary); transform:translateY(-2px); }
:where(#cuad) .ac .t{ font-weight:700; font-size:15px; display:flex; align-items:center; gap:9px; }
:where(#cuad) .ac .t i{ color:var(--primary); }
:where(#cuad) .ac .d{ font-size:13px; color:var(--muted-foreground); }
:where(#cuad) .ac.soon{ opacity:.6; pointer-events:none; }
:where(#cuad) .badge-soon{ font-size:10px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; background:var(--muted); color:var(--muted-foreground); padding:2px 7px; border-radius:100px; }
@media (max-width:900px){ :where(#cuad) .kgrid{ grid-template-columns:repeat(2,1fr); } :where(#cuad) .acts{ grid-template-columns:1fr; } }
</style>

<div id="cuad" class="flex flex-col gap-6">
  <div class="kgrid">
    <?php foreach ($kpis as [$ic,$lbl,$val]): ?>
      <div class="kc"><div class="ic"><i class="ki-filled ki-<?= $ic ?>"></i></div><div class="v"><?= number_format((int)$val) ?></div><div class="l"><?= e($lbl) ?></div></div>
    <?php endforeach; ?>
  </div>

  <div class="acts">
    <a class="ac" href="<?= e(url('modules/cuadrillas/padron.php')) ?>">
      <div class="t"><i class="ki-filled ki-people"></i> Padrón</div>
      <div class="d">Administra cuadrillas y operadores (con su acceso a la app).</div>
    </a>
    <div class="ac soon">
      <div class="t"><i class="ki-filled ki-geolocation"></i> Asignar y despachar <span class="badge-soon">Próximo</span></div>
      <div class="d">Toma un plan de rutas, ligalo a una cuadrilla y despáchalo a campo.</div>
    </div>
    <div class="ac soon">
      <div class="t"><i class="ki-filled ki-chart-line"></i> Seguimiento <span class="badge-soon">Próximo</span></div>
      <div class="d">Avance en vivo, estatus de paradas y evidencias de campo.</div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
