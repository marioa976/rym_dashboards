<?php
/**
 * Shell Metronic (demo1) del portal. Incluir al inicio de cada página autenticada.
 * Define antes: $ktTitle (título) y $ktActive (clave del módulo activo, 'home' o 'admin').
 * Requiere sesión iniciada (Auth) y helpers (url/e) — ya cargados por el guard.
 */
$ktActive = $ktActive ?? '';
$ktTitle  = $ktTitle  ?? 'Portal';
// Las páginas de reporte/mapa pueden pedir ancho completo con $ktFluid = true
// (mapas y tablas anchas). El resto usa el contenedor con ancho máximo cómodo.
$__container = !empty($ktFluid) ? 'kt-container-fluid' : 'kt-container-fixed';
$__user   = Auth::user() ?? ['nombre' => 'usuario'];
$__mods   = $_SESSION['modulos'] ?? [];
$__admin  = !empty($__user['es_admin']);
$__cfg    = require __DIR__ . '/../../config/config.php';

/** Icono keenicon por clave de módulo. */
$__icons = [
    'ejecutivo' => 'chart-line-star', 'dif' => 'heart', 'zendesk' => 'abstract-14',
    'qrobici' => 'route', 'electoral' => 'map', 'qrobus' => 'bus',
    'bloque' => 'technology-4', 'areasverdes' => 'tree', 'obras' => 'abstract-26',
];

/**
 * Sub-páginas por módulo (para la navegación interna dentro del módulo activo).
 * Cada item: [etiqueta, archivo, soloEditor?]. Se muestran indentadas bajo el
 * módulo activo. Los módulos de una sola página no aparecen aquí.
 */
$__subpages = [
    'ejecutivo' => [['Tablero','index.php'],['Mapa por capas','mapa.php'],['Electoral seccional','electoral.php']],
    'bloque'    => [['Tablero','index.php'],['Eventos','eventos.php'],['Procedencia','mapa.php']],
    'qrobus'    => [['Inicio','index.php'],['KPIs','kpis.php'],['Mapa seccional','mapa.php'],['Geocodificar','geocode.php',true]],
    'dif'       => [['Inicio','index.php'],['Dashboard','dashboard.php'],['Electoral','electoral.php'],
                    ['Importar','upload.php',true],['Deduplicar','dedupe.php',true],['Geocodificar','geocode_ui.php',true]],
    'zendesk'   => [['Dashboard','dashboard.php'],['Análisis','analisis.php'],['Tickets','tickets.php'],
                    ['Mapa','mapa.php'],['Por sección','secciones.php'],['Cuadrillas','cuadrillas.php'],
                    ['Descargar de Zendesk','descargar_zendesk.php',true]],
    'qrobici'   => [['Inicio','index.php'],['Informe ejecutivo','informe.php'],['Reporte de movilidad','reporte.php'],
                    ['Performance bicis','reporte_bicis.php'],['Flujo de la ciudad','mapa_animado.php'],
                    ['Mapa de riesgos','mapa_riesgos.php']],
];
$__page = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

/** Renderiza una sub-liga (página interna del módulo). */
function kt_sublink(string $href, string $label, bool $active): string {
    $cls = 'flex items-center gap-2 ps-[38px] pe-[10px] py-[6px] text-2sm rounded-lg '
         . ($active ? 'bg-accent/60 text-primary font-semibold' : 'text-secondary-foreground hover:bg-accent/60 hover:text-primary');
    $dot = $active ? 'bg-primary' : 'bg-muted-foreground/50';
    return '<a class="' . $cls . '" href="' . e($href) . '">'
         . '<span class="size-[5px] rounded-full ' . $dot . ' shrink-0"></span>' . e($label) . '</a>';
}

/** Renderiza un item de menú del sidebar. */
function kt_menu_item(string $href, string $icon, string $label, bool $active): string {
    $linkBase = 'kt-menu-link border border-transparent items-center grow gap-[10px] ps-[10px] pe-[10px] py-[8px] '
              . ($active ? 'bg-accent/60 rounded-lg' : 'hover:bg-accent/60 hover:rounded-lg');
    $titleCls = 'kt-menu-title text-sm ' . ($active ? 'font-semibold text-primary' : 'font-medium text-foreground');
    $iconCls  = 'kt-menu-icon items-start w-[20px] ' . ($active ? 'text-primary' : 'text-muted-foreground');
    return '<div class="kt-menu-item">'
        . '<a class="' . $linkBase . '" href="' . e($href) . '" tabindex="0">'
        . '<span class="' . $iconCls . '"><i class="ki-filled ki-' . e($icon) . ' text-lg"></i></span>'
        . '<span class="' . $titleCls . '">' . e($label) . '</span>'
        . '</a></div>';
}
?><!DOCTYPE html>
<html class="h-full" data-kt-theme="true" data-kt-theme-mode="light" dir="ltr" lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($ktTitle) ?> — <?= e($__cfg['app']['nombre']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="<?= e(url('assets/metronic/vendors/keenicons/styles.bundle.css')) ?>" rel="stylesheet">
<link href="<?= e(url('assets/css/qro.css')) ?>" rel="stylesheet"><!-- compat: variables --qro-* y clases heredadas de los módulos -->
<link href="<?= e(url('assets/metronic/css/styles.css')) ?>" rel="stylesheet">
<style>
  /* Sidebar colapsado (solo iconos): oculta la sub-navegación y los
     encabezados de sección para que no queden textos partidos ni puntos
     sueltos. Las sub-páginas siguen accesibles al expandir el sidebar. */
  body.kt-sidebar-collapse .kt-submenu-group,
  body.kt-sidebar-collapse .kt-heading-item { display: none !important; }
</style>
<?php if (!empty($ktHead)) echo $ktHead; ?>
</head>
<body class="antialiased flex h-full text-base text-foreground bg-background demo1 kt-sidebar-fixed kt-header-fixed">

<!-- Sidebar -->
<div class="kt-sidebar bg-background border-e border-e-border fixed top-0 bottom-0 z-20 hidden lg:flex flex-col items-stretch shrink-0 [--kt-drawer-enable:true] lg:[--kt-drawer-enable:false]"
     data-kt-drawer="true" data-kt-drawer-class="kt-drawer kt-drawer-start top-0 bottom-0" id="sidebar">
  <div class="kt-sidebar-header hidden lg:flex items-center relative justify-between px-6 shrink-0" id="sidebar_header">
    <a href="<?= e(url('index.php')) ?>" class="kt-sidebar-logo flex items-center gap-2 min-w-0">
      <img src="<?= e(url('assets/img/logo.png')) ?>" class="default-logo max-h-[40px] w-auto" alt="Querétaro con Futuro">
      <img src="<?= e(url('assets/img/logo.png')) ?>" class="small-logo max-h-[34px] w-auto" alt="QRO">
    </a>
    <button class="kt-btn kt-btn-outline kt-btn-icon size-[30px] absolute start-full top-2/4 z-40 -translate-x-2/4 -translate-y-2/4"
            data-kt-toggle="body" data-kt-toggle-class="kt-sidebar-collapse" id="sidebar_toggle">
      <i class="ki-filled ki-black-left-line kt-toggle-active:rotate-180 transition-all duration-300"></i>
    </button>
  </div>
  <div class="kt-sidebar-content flex grow shrink-0 py-5 pe-2" id="sidebar_content">
    <div class="kt-scrollable-y-hover grow flex ps-5 pe-3" data-kt-scrollable="true"
         data-kt-scrollable-dependencies="#sidebar_header" data-kt-scrollable-height="auto"
         data-kt-scrollable-offset="0px" data-kt-scrollable-wrappers="#sidebar_content" id="sidebar_scrollable">
      <div class="kt-menu flex flex-col grow gap-1" data-kt-menu="true" id="sidebar_menu">
        <?= kt_menu_item(url('index.php'), 'home-2', 'Inicio', $ktActive === 'home') ?>
        <div class="kt-heading-item kt-menu-item pt-3 pb-1">
          <span class="kt-menu-heading uppercase text-2xs font-medium text-muted-foreground ps-[10px]">Módulos</span>
        </div>
        <?php foreach ($__mods as $m):
            $icon = $__icons[$m['clave']] ?? 'element-11';
            $esActivo = ($ktActive === $m['clave']);
            echo kt_menu_item(url($m['ruta']), $icon, $m['nombre'], $esActivo);
            // Sub-páginas del módulo activo (navegación interna)
            if ($esActivo && !empty($__subpages[$m['clave']])):
                $dir = trim(dirname($m['ruta']), '/');   // p.ej. modules/ejecutivo
                echo '<div class="kt-submenu-group flex flex-col gap-0.5 mt-0.5 mb-1">';
                foreach ($__subpages[$m['clave']] as $sp):
                    if (!empty($sp[2]) && !(function_exists('puede_editar') && puede_editar($m['clave']))) continue;
                    echo kt_sublink(url($dir . '/' . $sp[1]), $sp[0], $__page === $sp[1]);
                endforeach;
                echo '</div>';
            endif;
        endforeach; ?>
        <?php if ($__admin): ?>
          <div class="kt-heading-item kt-menu-item pt-3 pb-1">
            <span class="kt-menu-heading uppercase text-2xs font-medium text-muted-foreground ps-[10px]">Administración</span>
          </div>
          <?= kt_menu_item(url('admin/index.php'), 'badge', 'Administración', $ktActive === 'admin') ?>
          <?php if ($ktActive === 'admin'):
            $__adm = ['index'=>'Panel','usuarios'=>'Usuarios','modulos'=>'Módulos','sesiones'=>'Sesiones'];
            $__aa  = $ktAdminActive ?? 'index';
            echo '<div class="kt-submenu-group flex flex-col gap-0.5 mt-0.5 mb-1">';
            foreach ($__adm as $af => $al) echo kt_sublink(url('admin/' . $af . '.php'), $al, $__aa === $af);
            echo '</div>';
          endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Wrapper -->
<div class="kt-wrapper flex grow flex-col">
  <!-- Header -->
  <header class="kt-header fixed top-0 z-10 start-0 end-0 flex items-stretch shrink-0 bg-background border-b border-border"
          data-kt-sticky="true" data-kt-sticky-name="header" id="header">
    <div class="<?= $__container ?> flex justify-between items-center grow gap-4">
      <div class="flex items-center gap-2.5 min-w-0">
        <button class="kt-btn kt-btn-outline kt-btn-icon size-9 lg:hidden" data-kt-drawer-toggle="#sidebar">
          <i class="ki-filled ki-menu"></i>
        </button>
        <h1 class="text-lg font-semibold text-mono truncate"><?= e($ktTitle) ?></h1>
      </div>
      <div class="flex items-center gap-3 shrink-0">
        <span class="hidden sm:inline text-sm text-secondary-foreground">Hola, <strong class="font-semibold text-foreground"><?= e($__user['nombre']) ?></strong></span>
        <a href="<?= e(url('logout.php')) ?>" class="kt-btn kt-btn-sm kt-btn-outline">
          <i class="ki-filled ki-exit-right"></i> Salir
        </a>
      </div>
    </div>
  </header>

  <!-- Content -->
  <main class="grow pt-5 pb-10" id="content" role="content">
    <div class="<?= $__container ?>">
