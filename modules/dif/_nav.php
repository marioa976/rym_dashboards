<?php
/**
 * _nav.php — Barra del portal para el módulo DIF.
 * Conserva la firma original: define $navActive antes de incluir.
 * Claves: home, dashboard, electoral, upload, dedupe, geocode
 */
$navActive = $navActive ?? '';
$items = [
    'home'      => ['emoji'=>'🏠', 'label'=>'Inicio',       'href'=>'index.php'],
    'dashboard' => ['emoji'=>'📊', 'label'=>'Dashboard',    'href'=>'dashboard.php'],
    'electoral' => ['emoji'=>'🗳', 'label'=>'Electoral',     'href'=>'electoral.php'],
];
// Herramientas de escritura (cargar, deduplicar, geocodificar): solo editor/admin.
if (function_exists('puede_editar') && puede_editar('dif')) {
    $items['upload']  = ['emoji'=>'📤', 'label'=>'Importar',     'href'=>'upload.php'];
    $items['dedupe']  = ['emoji'=>'🧹', 'label'=>'Deduplicar',   'href'=>'dedupe.php'];
    $items['geocode'] = ['emoji'=>'🌐', 'label'=>'Geocodificar', 'href'=>'geocode_ui.php'];
}
// Capa de homologación visual QRO (tipografía + paleta + chrome)
include __DIR__ . '/../_qro_theme.php';
?>
<style>
/* Nav de módulo (solo links). El logo, la píldora DIF y "Salir" los pone _portalbar. */
.qro-nav{background:#005ab2;color:#fff;padding:6px 22px;display:flex;align-items:center;
  gap:6px;flex-wrap:wrap;font-family:"Montserrat",Arial,sans-serif;font-size:14px;
  border-top:1px solid rgba(255,255,255,.14)}
.qro-nav a{color:#fff;text-decoration:none}
.qro-nav .links{display:flex;gap:4px;flex-wrap:wrap}
.qro-nav .links a{padding:7px 12px;border-radius:8px;color:#dbe7f7;font-size:13px;font-weight:600;
  display:flex;align-items:center;gap:6px;transition:.15s}
.qro-nav .links a:hover{background:rgba(255,255,255,.14);color:#fff}
.qro-nav .links a.active{background:#fff;color:#005ab2}
@media(max-width:760px){.qro-nav .links a .lbl{display:none}}
</style>
<div class="qro-nav">
  <div class="links">
    <?php foreach ($items as $k=>$it): ?>
      <a href="<?= $it['href'] ?>" class="<?= $navActive===$k?'active':'' ?>">
        <span><?= $it['emoji'] ?></span><span class="lbl"><?= $it['label'] ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
