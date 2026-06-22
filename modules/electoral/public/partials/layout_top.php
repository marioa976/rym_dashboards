<?php
$title = $title ?? 'Reporteador Electoral';
$active = $active ?? '';

if (!function_exists('reporteador_base_url')) {
    function reporteador_base_url(): string {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if (preg_match('#/(import|partials|assets|admin|api|reports)(/|$)#', $dir)) {
            $dir = preg_replace('#/(import|partials|assets|admin|api|reports).*$#', '', $dir);
        }
        return $dir;
    }
}
$BASE = reporteador_base_url();
$U = function_exists('auth_user') ? auth_user() : null;
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title) ?> · Reporteador Electoral</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $BASE ?>/assets/css/pan.css">
</head>
<body>
<?php $PORTAL_ROOT = preg_replace('#/modules/electoral/public$#', '', $BASE); ?>
<div class="app">
  <aside class="sidebar">
    <div class="brand">
      <img src="<?= $PORTAL_ROOT ?>/assets/img/logo.png" alt="Querétaro con Futuro" class="sidebar-logo">
      <small>Reporteador Electoral · IEEQ / INE</small>
    </div>
    <nav>
      <a href="<?= $BASE ?>/index.php" class="<?= $active === 'dashboard' ? 'active' : '' ?>"><span class="nav-ico">▦</span> Inicio</a>
      <a href="<?= $BASE ?>/reports/rentabilidad.php" class="<?= $active === 'rentabilidad' ? 'active' : '' ?>"><span class="nav-ico">▣</span> Rentabilidad electoral</a>
      <a href="<?= $BASE ?>/reports/cruce.php" class="<?= $active === 'cruce' ? 'active' : '' ?>"><span class="nav-ico">▣</span> Cruce por sección</a>
      <?php if ($U && $U['role'] === 'administrador'): ?>
        <a href="<?= $BASE ?>/admin/importar_resultados.php" class="<?= $active === 'importar-resultados' ? 'active' : '' ?>"><span class="nav-ico">⬆</span> Importar resultados</a>
        <a href="<?= $BASE ?>/admin/elecciones.php" class="<?= $active === 'elecciones-admin' ? 'active' : '' ?>"><span class="nav-ico">⚙</span> Elecciones</a>
      <?php endif; ?>
      <a href="<?= $PORTAL_ROOT ?>/index.php" style="margin-top:14px"><span class="nav-ico">←</span> Portal</a>
    </nav>
  </aside>
  <div class="main">
    <header class="topbar">
      <div class="topbar-title">Querétaro con Futuro · Electoral</div>
      <div class="topbar-user">
        <?php if ($U): ?>
          <span class="tu-name"><?= htmlspecialchars($U['name']) ?></span>
          <span class="chip info"><?= htmlspecialchars($U['role']) ?></span>
          <a class="btn btn-secondary" style="padding:8px 14px" href="<?= $PORTAL_ROOT ?>/logout.php">Salir</a>
        <?php endif; ?>
      </div>
    </header>
    <main class="content">
