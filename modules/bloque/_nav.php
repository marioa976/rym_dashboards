<?php
/** Barra del portal + navegación del módulo Bloque. Define $navActive antes de incluir. */
$navActive    = $navActive ?? '';
$portalModulo = $portalModulo ?? 'Bloque';
include __DIR__ . '/../_portalbar.php';
?>
<style>
.bl-nav{background:#005ab2;color:#fff;padding:6px 22px;display:flex;gap:4px;flex-wrap:wrap;
  border-top:1px solid rgba(255,255,255,.14);font-family:"Montserrat",Arial,sans-serif}
.bl-nav a{color:#dbe7f7;text-decoration:none;padding:7px 12px;border-radius:8px;font-size:13px;font-weight:600}
.bl-nav a:hover{background:rgba(255,255,255,.14);color:#fff}
.bl-nav a.active{background:#fff;color:#005ab2}
</style>
<div class="bl-nav">
  <a href="index.php" class="<?= $navActive==='home'?'active':'' ?>">📊 Tablero</a>
  <a href="eventos.php" class="<?= $navActive==='eventos'?'active':'' ?>">🎫 Eventos</a>
  <a href="mapa.php" class="<?= $navActive==='mapa'?'active':'' ?>">🗺 Procedencia</a>
</div>
