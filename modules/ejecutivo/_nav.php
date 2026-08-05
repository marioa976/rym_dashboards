<?php
/** Barra del portal + navegación del módulo Ejecutivo. Define $navActive antes de incluir. */
$navActive    = $navActive ?? '';
$portalModulo = $portalModulo ?? 'Ejecutivo';
include __DIR__ . '/../_portalbar.php';
?>
<style>
.ej-nav{background:#1b2f5e;color:#fff;padding:6px 22px;display:flex;gap:4px;flex-wrap:wrap;
  border-top:1px solid rgba(255,255,255,.14);font-family:"Montserrat",Arial,sans-serif}
.ej-nav a{color:#cdd8ef;text-decoration:none;padding:7px 12px;border-radius:8px;font-size:13px;font-weight:600}
.ej-nav a:hover{background:rgba(255,255,255,.16);color:#fff}
.ej-nav a.active{background:#fff;color:#1b2f5e}
</style>
<div class="ej-nav">
  <a href="index.php"    class="<?= $navActive==='home'?'active':'' ?>">📊 Tablero ejecutivo</a>
  <a href="mapa.php"     class="<?= $navActive==='mapa'?'active':'' ?>">🗺 Mapa por capas</a>
  <a href="electoral.php" class="<?= $navActive==='electoral'?'active':'' ?>">🗳 Electoral seccional</a>
</div>
