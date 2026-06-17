<?php
declare(strict_types=1);
require_once __DIR__ . '/core/guard.php';
require_login();
$titulo = 'Acceso denegado';
require __DIR__ . '/views/layout/header.php';
?>
<div class="card" style="max-width:560px;margin:40px auto;text-align:center">
  <h2 style="color:var(--qro-danger)">403 · Sin permiso</h2>
  <p class="text-secondary">No tienes acceso a este módulo. Si crees que es un error,
     contacta al administrador del portal.</p>
  <a class="btn btn-secondary" href="<?= e(url('index.php')) ?>">Volver al inicio</a>
</div>
<?php require __DIR__ . '/views/layout/footer.php'; ?>
