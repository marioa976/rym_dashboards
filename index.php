<?php
/** Inicio: muestra solo los módulos que el usuario puede ver. */
declare(strict_types=1);
require_once __DIR__ . '/core/guard.php';
require_login();

$user    = Auth::user();
$modulos = $_SESSION['modulos'] ?? [];
$ktTitle = 'Inicio';
$ktActive = 'home';
require __DIR__ . '/views/layout/kt_top.php';
?>
<div class="mb-6">
  <h2 class="text-xl font-semibold text-mono">Hola, <?= e($user['nombre']) ?></h2>
  <p class="text-sm text-secondary-foreground mt-1">Selecciona un módulo de reporteo para comenzar.</p>
</div>

<?php if (empty($modulos)): ?>
  <div class="kt-card">
    <div class="kt-card-content p-6 text-sm text-secondary-foreground">
      No tienes módulos asignados todavía. Contacta al administrador.
    </div>
  </div>
<?php else: ?>
  <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">
    <?php foreach ($modulos as $m):
        $icon = $__icons[$m['clave']] ?? 'element-11'; ?>
      <a class="kt-card hover:shadow-lg transition-shadow" href="<?= e(url($m['ruta'])) ?>">
        <div class="kt-card-content p-5 flex items-start gap-4">
          <div class="flex items-center justify-center size-12 rounded-lg bg-primary/10 text-primary shrink-0">
            <i class="ki-filled ki-<?= e($icon) ?> text-2xl"></i>
          </div>
          <div class="min-w-0 grow">
            <div class="flex items-center gap-2 flex-wrap">
              <h3 class="text-base font-semibold text-mono"><?= e($m['nombre']) ?></h3>
              <span class="kt-badge kt-badge-sm kt-badge-outline"><?= e(ucfirst($m['nivel'])) ?></span>
            </div>
            <p class="text-sm text-secondary-foreground mt-1"><?= e($m['descripcion']) ?></p>
          </div>
          <i class="ki-filled ki-black-right-line text-muted-foreground shrink-0 mt-1"></i>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/views/layout/kt_bottom.php'; ?>
