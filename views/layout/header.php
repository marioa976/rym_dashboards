<?php
/** Cabecera + sidebar. Requiere sesión iniciada (guard). */
if (!isset($titulo)) { $titulo = 'Portal'; }
$user    = Auth::user();
$modulos = $_SESSION['modulos'] ?? [];
$cfg     = require __DIR__ . '/../../config/config.php';
$actual  = basename($_SERVER['SCRIPT_NAME']);
$rutaAct = $_SERVER['SCRIPT_NAME'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> — <?= e($cfg['app']['nombre']) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/css/qro.css')) ?>">
</head>
<body>
<div class="layout">
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <img src="<?= e(url('assets/img/logo.png')) ?>" alt="Querétaro con Futuro" class="sidebar-logo">
    </div>
    <nav class="sidebar-nav">
      <a href="<?= e(url('index.php')) ?>"
         class="nav-item <?= $actual === 'index.php' ? 'active' : '' ?>">
        <span class="nav-ico">▦</span> Inicio
      </a>
      <?php foreach ($modulos as $m): ?>
        <?php $on = strpos($rutaAct, '/modules/' . $m['clave'] . '/') !== false; ?>
        <a href="<?= e(url($m['ruta'])) ?>" class="nav-item <?= $on ? 'active' : '' ?>">
          <span class="nav-ico">▣</span> <?= e($m['nombre']) ?>
        </a>
      <?php endforeach; ?>
      <?php if (!empty($user['es_admin'])): ?>
        <a href="<?= e(url('admin/index.php')) ?>" class="nav-item" style="margin-top:14px">
          <span class="nav-ico">⚙</span> Administración
        </a>
      <?php endif; ?>
    </nav>
  </aside>

  <!-- Columna principal -->
  <div class="main">
    <header class="topbar">
      <div class="topbar-title"><?= e($cfg['app']['nombre']) ?></div>
      <div class="topbar-user">
        <span class="user-name"><?= e($user['nombre']) ?></span>
        <?php if ($user['es_admin']): ?>
          <span class="badge badge-info">Admin</span>
        <?php endif; ?>
        <a class="btn btn-secondary btn-sm" href="<?= e(url('logout.php')) ?>">Salir</a>
      </div>
    </header>
    <main class="content">
