<?php
/**
 * Barra superior del portal para páginas de módulo.
 * Se inyecta justo después de <body>. Enlaces relativos a la raíz del portal
 * (../../) por lo que funciona sin depender de base_url.
 * Define $portalModulo (DIF | Zendesk | Qrobici) antes de incluir, si quieres
 * mostrar el nombre del módulo.
 */
$__pm = $portalModulo ?? '';
// Capa de homologación visual QRO (tipografía + paleta + chrome)
include __DIR__ . '/_qro_theme.php';
?>
<style>
.qro-portalbar{display:flex;align-items:center;gap:14px;background:#005ab2;color:#fff;
  padding:6px 22px;font-family:"Montserrat",Arial,sans-serif;font-size:14px;
  box-shadow:0 2px 6px rgba(0,90,178,.20)}
.qro-portalbar a{color:#fff;text-decoration:none}
.qro-portalbar .qpb-brand{display:flex;align-items:center}
.qro-portalbar .qpb-logo{height:42px;width:auto;display:block}
.qro-portalbar .qpb-mod{background:rgba(255,255,255,.18);padding:4px 10px;border-radius:999px;
  font-weight:600;font-size:12.5px}
.qro-portalbar .qpb-spacer{flex:1}
.qro-portalbar .qpb-link{padding:7px 12px;border-radius:8px;font-weight:600;font-size:13px}
.qro-portalbar .qpb-link:hover{background:rgba(255,255,255,.12)}
.qro-portalbar .qpb-out{border:1px solid rgba(255,255,255,.5)}
</style>
<div class="qro-portalbar">
  <a class="qpb-brand" href="../../index.php"><img class="qpb-logo" src="../../assets/img/logo.png" alt="Querétaro con Futuro"></a>
  <?php if ($__pm !== ''): ?><span class="qpb-mod"><?= htmlspecialchars($__pm) ?></span><?php endif; ?>
  <span class="qpb-spacer"></span>
  <a class="qpb-link" href="../../index.php">▦ Portal</a>
  <a class="qpb-link qpb-out" href="../../logout.php">Salir</a>
</div>
