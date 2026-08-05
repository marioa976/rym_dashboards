<?php
/** Barra del portal + navegación del módulo Obras. Define $navActive antes de incluir. */
$navActive    = $navActive ?? '';
$portalModulo = $portalModulo ?? 'Obras';
include __DIR__ . '/../_portalbar.php';
?>
<style>
.ob-nav{background:#a8481f;color:#fff;padding:6px 22px;display:flex;gap:4px;flex-wrap:wrap;
  border-top:1px solid rgba(255,255,255,.14);font-family:"Montserrat",Arial,sans-serif}
.ob-nav a{color:#f7e0d3;text-decoration:none;padding:7px 12px;border-radius:8px;font-size:13px;font-weight:600}
.ob-nav a:hover{background:rgba(255,255,255,.16);color:#fff}
.ob-nav a.active{background:#fff;color:#a8481f}
</style>
<div class="ob-nav">
  <a href="index.php" class="<?= $navActive==='home'?'active':'' ?>">🏗 Reporte geográfico</a>
</div>
