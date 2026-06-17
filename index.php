<?php
/** Inicio: muestra solo los módulos que el usuario puede ver. */
declare(strict_types=1);
require_once __DIR__ . '/core/guard.php';
require_login();

$user    = Auth::user();
$modulos = $_SESSION['modulos'] ?? [];
$titulo  = 'Inicio';
require __DIR__ . '/views/layout/header.php';
?>
<div class="page-head">
  <h1>Hola, <?= e($user['nombre']) ?></h1>
  <p class="text-secondary">Selecciona un módulo de reporteo para comenzar.</p>
</div>

<?php if (empty($modulos)): ?>
  <div class="card">
    <p>No tienes módulos asignados todavía. Contacta al administrador.</p>
  </div>
<?php else: ?>
  <div class="grid-cards">
    <?php foreach ($modulos as $m): ?>
      <a class="card card-modulo" href="<?= e(url($m['ruta'])) ?>"
         style="--accent: <?= e($m['color'] ?: 'var(--qro-blue)') ?>">
        <span class="card-modulo-dot"></span>
        <h3><?= e($m['nombre']) ?></h3>
        <p class="text-secondary"><?= e($m['descripcion']) ?></p>
        <span class="badge badge-info"><?= e(ucfirst($m['nivel'])) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/views/layout/footer.php'; ?>
