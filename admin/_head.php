<?php
$user = Auth::user();
$adminActive = $adminActive ?? 'index';
$adminTitulo = $adminTitulo ?? 'Panel';
$nav = [
    'index'    => ['Panel',    '▦'],
    'usuarios' => ['Usuarios', '👤'],
    'modulos'  => ['Módulos',  '▣'],
    'sesiones' => ['Sesiones', '🔑'],
];
$f = flash_take();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Administración · <?= e($adminTitulo) ?> — Portal QRO</title>
<link rel="stylesheet" href="../assets/css/qro.css">
<style>
  .admin-tools{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:18px}
  .adm-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--qro-border);border-radius:12px;overflow:hidden}
  .adm-table th{background:#eef5fc;color:var(--qro-blue-dark);text-align:left;padding:11px 13px;font-size:12.5px;text-transform:uppercase;letter-spacing:.3px}
  .adm-table td{padding:11px 13px;border-top:1px solid var(--qro-border);font-size:14px;vertical-align:middle}
  .adm-table tbody tr:hover{background:#f7fafe}
  .chip{display:inline-block;padding:3px 9px;border-radius:999px;font-size:11.5px;font-weight:600;margin:2px 3px 2px 0}
  .chip-mod{background:rgba(42,158,218,.12);color:var(--qro-blue)}
  .chip-admin{background:rgba(37,65,133,.12);color:var(--qro-blue-dark)}
  .chip-off{background:#f0f1f4;color:#8a94a6}
  .badge-on{background:rgba(24,138,91,.12);color:var(--qro-success);border-radius:999px;padding:3px 9px;font-size:11.5px;font-weight:600}
  .badge-off{background:rgba(206,58,43,.10);color:var(--qro-danger);border-radius:999px;padding:3px 9px;font-size:11.5px;font-weight:600}
  .adm-form{background:#fff;border:1px solid var(--qro-border);border-radius:14px;padding:20px;box-shadow:var(--qro-shadow-sm)}
  .adm-grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr))}
  .inline-form{display:inline}
  .btn-xs{padding:6px 10px;font-size:13px;border-radius:8px}
  .muted{color:var(--qro-text-muted);font-size:13px}
  .kpi-grid{display:grid;gap:16px;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));margin-bottom:8px}
  .sidebar-nav .nav-item .nav-ico{width:18px;display:inline-block}
</style>
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="sidebar-brand">
      <span class="sidebar-brand-text">Querétaro<br>con Futuro</span>
    </div>
    <nav class="sidebar-nav">
      <?php foreach ($nav as $k => $it): ?>
        <a href="<?= $k ?>.php" class="nav-item <?= $adminActive === $k ? 'active' : '' ?>">
          <span class="nav-ico"><?= $it[1] ?></span> <?= $it[0] ?>
        </a>
      <?php endforeach; ?>
      <a href="../index.php" class="nav-item" style="margin-top:14px;opacity:.85">↩ Volver al portal</a>
    </nav>
  </aside>
  <div class="main">
    <header class="topbar">
      <div class="topbar-title">Administración · <?= e($adminTitulo) ?></div>
      <div class="topbar-user">
        <span class="user-name"><?= e($user['nombre']) ?></span>
        <span class="badge badge-info">Admin</span>
        <a class="btn btn-secondary btn-sm" href="../logout.php">Salir</a>
      </div>
    </header>
    <main class="content">
      <?php if ($f): ?>
        <div class="alert <?= $f['tipo'] === 'ok' ? 'alert-ok' : 'alert-danger' ?>"
             style="<?= $f['tipo']==='ok' ? 'background:rgba(24,138,91,.10);color:#166534;border:1px solid rgba(24,138,91,.25)' : '' ?>">
          <?= e($f['msg']) ?>
        </div>
      <?php endif; ?>
