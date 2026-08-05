<?php
/** Barra del portal + navegación del módulo Áreas Verdes. Define $navActive antes de incluir. */
$navActive    = $navActive ?? '';
$portalModulo = $portalModulo ?? 'Áreas Verdes';
include __DIR__ . '/../_portalbar.php';
?>
<style>
.av-nav{background:#1f7a45;color:#fff;padding:6px 22px;display:flex;gap:4px;flex-wrap:wrap;
  border-top:1px solid rgba(255,255,255,.14);font-family:"Montserrat",Arial,sans-serif}
.av-nav a{color:#dff0e5;text-decoration:none;padding:7px 12px;border-radius:8px;font-size:13px;font-weight:600}
.av-nav a:hover{background:rgba(255,255,255,.16);color:#fff}
.av-nav a.active{background:#fff;color:#1f7a45}
</style>
<div class="av-nav">
  <a href="index.php" class="<?= $navActive==='home'?'active':'' ?>">🌳 Reporte geográfico</a>
</div>
