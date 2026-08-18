<?php
$title  = $title  ?? 'Reporteador Seccional';
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
$PORTAL_ROOT = preg_replace('#/modules/electoral/public$#', '', $BASE);
$U = function_exists('auth_user') ? auth_user() : null;
$esAdmin = $U && ($U['role'] ?? '') === 'administrador';

// Nav del reporteador seccional: [clave activa, href relativo a $BASE, etiqueta, keenicon]
$__nav = [
    ['dashboard',    'index.php',                 'Inicio',                'home-2'],
    ['rentabilidad', 'reports/rentabilidad.php',  'Rentabilidad seccional','chart-simple'],
    ['cruce',        'reports/cruce.php',         'Cruce por sección',     'map'],
    ['afinidad',     'reports/afinidad.php',      'Afinidad partidista',   'abstract-26'],
];
$__admNav = [
    ['importar-resultados', 'admin/importar_resultados.php', 'Importar resultados', 'exit-up'],
    ['elecciones-admin',    'admin/elecciones.php',          'Elecciones',          'setting-2'],
];
function el_side_link(string $href, string $icon, string $label, bool $active): string {
    $base = 'kt-menu-link border border-transparent items-center grow gap-[10px] ps-[10px] pe-[10px] py-[8px] '
          . ($active ? 'bg-accent/60 rounded-lg' : 'hover:bg-accent/60 hover:rounded-lg');
    $tcls = 'kt-menu-title text-sm ' . ($active ? 'font-semibold text-primary' : 'font-medium text-foreground');
    $icls = 'kt-menu-icon items-start w-[20px] ' . ($active ? 'text-primary' : 'text-muted-foreground');
    return '<div class="kt-menu-item"><a class="' . $base . '" href="' . htmlspecialchars($href) . '">'
         . '<span class="' . $icls . '"><i class="ki-filled ki-' . $icon . ' text-lg"></i></span>'
         . '<span class="' . $tcls . '">' . htmlspecialchars($label) . '</span></a></div>';
}
?><!DOCTYPE html>
<html class="h-full" data-kt-theme="true" data-kt-theme-mode="light" dir="ltr" lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title) ?> · Seccional</title>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="<?= $PORTAL_ROOT ?>/assets/metronic/vendors/keenicons/styles.bundle.css" rel="stylesheet">
  <link href="<?= $BASE ?>/assets/css/pan.css" rel="stylesheet"><!-- compat: estilos de contenido del reporteador -->
  <link href="<?= $PORTAL_ROOT ?>/assets/metronic/css/styles.css" rel="stylesheet">
</head>
<body class="antialiased flex h-full text-base text-foreground bg-background demo1 kt-sidebar-fixed kt-header-fixed">

<div class="kt-sidebar bg-background border-e border-e-border fixed top-0 bottom-0 z-20 hidden lg:flex flex-col items-stretch shrink-0" id="sidebar">
  <div class="kt-sidebar-header hidden lg:flex items-center px-6 shrink-0">
    <a href="<?= $PORTAL_ROOT ?>/index.php" class="flex items-center gap-2 min-w-0">
      <img src="<?= $PORTAL_ROOT ?>/assets/img/logo.png" class="max-h-[40px] w-auto" alt="Querétaro con Futuro">
    </a>
  </div>
  <div class="kt-sidebar-content flex grow shrink-0 py-5 pe-2">
    <div class="grow flex ps-5 pe-3">
      <div class="kt-menu flex flex-col grow gap-1">
        <div class="kt-menu-item pt-1 pb-1"><span class="uppercase text-2xs font-medium text-muted-foreground ps-[10px]">Reporteador Seccional</span></div>
        <?php foreach ($__nav as $n) echo el_side_link($BASE . '/' . $n[1], $n[3], $n[2], $active === $n[0]); ?>
        <?php if ($esAdmin): ?>
          <div class="kt-menu-item pt-3 pb-1"><span class="uppercase text-2xs font-medium text-muted-foreground ps-[10px]">Administración</span></div>
          <?php foreach ($__admNav as $n) echo el_side_link($BASE . '/' . $n[1], $n[3], $n[2], $active === $n[0]); ?>
        <?php endif; ?>
        <div class="kt-menu-item pt-3"><a class="kt-menu-link items-center gap-[10px] ps-[10px] py-[8px] text-secondary-foreground hover:text-primary" href="<?= $PORTAL_ROOT ?>/index.php"><span class="kt-menu-icon w-[20px]"><i class="ki-filled ki-exit-left text-lg"></i></span><span class="kt-menu-title text-sm font-medium">Volver al portal</span></a></div>
      </div>
    </div>
  </div>
</div>

<div class="kt-wrapper flex grow flex-col">
  <header class="kt-header fixed top-0 z-10 start-0 end-0 flex items-stretch shrink-0 bg-background border-b border-border" id="header">
    <div class="kt-container-fixed flex justify-between items-center grow gap-4">
      <h1 class="text-lg font-semibold text-mono truncate"><?= htmlspecialchars($title) ?></h1>
      <?php if ($U): ?>
      <div class="flex items-center gap-3 shrink-0">
        <span class="hidden sm:inline text-sm text-secondary-foreground"><strong class="font-semibold text-foreground"><?= htmlspecialchars($U['name']) ?></strong> · <?= htmlspecialchars($U['role']) ?></span>
        <a href="<?= $PORTAL_ROOT ?>/logout.php" class="kt-btn kt-btn-sm kt-btn-outline"><i class="ki-filled ki-exit-right"></i> Salir</a>
      </div>
      <?php endif; ?>
    </div>
  </header>

  <main class="grow pt-5 pb-10" id="content" role="content">
    <div class="kt-container-fixed">
