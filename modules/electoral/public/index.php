<?php
require_once __DIR__ . '/../lib/bootstrap.php';
$pdo  = reporteador_pdo();
$BASE = reporteador_base_url_safe();
$U    = auth_user();

$kpis = [];
foreach (['elecciones', 'casillas', 'resultados_casilla', 'candidatos', 'partidos', 'import_log_resultados'] as $t) {
    try {
        $kpis[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    } catch (Throwable $e) { $kpis[$t] = 0; }
}

$title = 'Inicio';
$active = 'dashboard';
include __DIR__ . '/partials/layout_top.php';
?>
<div class="page-header">
  <h1>Bienvenido, <?= htmlspecialchars($U['name']) ?></h1>
  <p>Reporteador electoral · datos del IEEQ / INE para análisis territorial.</p>
</div>

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px">
  <?php foreach ($kpis as $k => $v): ?>
    <div class="card" style="padding:14px">
      <div style="font-size:11px;color:var(--color-text-secondary);text-transform:uppercase"><?= htmlspecialchars($k) ?></div>
      <div style="font-size:24px;font-weight:700"><?= number_format($v) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div style="margin-top:18px">
  <a class="btn primary" href="<?= $BASE ?>/reports/rentabilidad.php">Ver reporte de rentabilidad</a>
  <?php if ($U['role']==='administrador'): ?>
    <a class="btn" href="<?= $BASE ?>/admin/importar_resultados.php">Importar archivos</a>
    <a class="btn" href="<?= $BASE ?>/admin/elecciones.php">Gestionar elecciones</a>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/layout_bottom.php'; ?>
