<?php
/** Barra del portal + navegación del módulo Qrobus. Define $navActive antes de incluir. */
$navActive    = $navActive ?? '';
$portalModulo = $portalModulo ?? 'Qrobus';
include __DIR__ . '/../_portalbar.php';
$editable = function_exists('puede_editar') && puede_editar('qrobus');
?>
<style>
.qb-nav{background:#005ab2;color:#fff;padding:6px 22px;display:flex;gap:4px;flex-wrap:wrap;
  border-top:1px solid rgba(255,255,255,.14);font-family:"Montserrat",Arial,sans-serif}
.qb-nav a{color:#dbe7f7;text-decoration:none;padding:7px 12px;border-radius:8px;font-size:13px;font-weight:600}
.qb-nav a:hover{background:rgba(255,255,255,.14);color:#fff}
.qb-nav a.active{background:#fff;color:#005ab2}
</style>
<div class="qb-nav">
  <a href="index.php" class="<?= $navActive==='home'?'active':'' ?>">🏠 Inicio</a>
  <a href="kpis.php" class="<?= $navActive==='kpis'?'active':'' ?>">📊 KPIs</a>
  <a href="mapa.php" class="<?= $navActive==='mapa'?'active':'' ?>">🗺 Mapa seccional</a>
  <?php if ($editable): ?><a href="geocode.php" class="<?= $navActive==='geocode'?'active':'' ?>">🌐 Geocodificar</a><?php endif; ?>
</div>
