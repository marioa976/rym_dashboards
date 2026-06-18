<?php
/**
 * Navegación homologada del módulo Zendesk.
 * Inclúyelo con:  <?php include __DIR__ . '/_navzendesk.php'; ?>
 * Marca activo el archivo actual. Para enlaces extra de una pantalla,
 * define $navExtra = ['archivo.php' => 'Etiqueta'] antes del include.
 */
$navActual = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$navItems = [
    'dashboard.php'         => 'Dashboard',
    'analisis.php'          => 'Análisis',
    'tickets.php'           => 'Tickets',
    'mapa.php'              => 'Mapa',
    'secciones.php'         => 'Por sección',
    'cuadrillas.php'        => 'Cuadrillas',
];
// El conector (escritura) solo para editores/admin; los visores no lo ven.
if (function_exists('puede_editar') && puede_editar('zendesk')) {
    $navItems['descargar_zendesk.php'] = 'Descargar de Zendesk';
}
?>
<div class="nav">
  <?php foreach ($navItems as $archivo => $etiqueta): ?>
    <a href="<?= $archivo ?>"<?= $navActual === $archivo ? ' class="active"' : '' ?>><?= $etiqueta ?></a>
  <?php endforeach; ?>
  <?php foreach (($navExtra ?? []) as $archivo => $etiqueta): ?>
    <a href="<?= htmlspecialchars($archivo) ?>"><?= htmlspecialchars($etiqueta) ?></a>
  <?php endforeach; ?>
</div>
