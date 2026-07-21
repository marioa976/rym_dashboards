<?php
/**
 * Qrobus · Inicio — beneficiarios Unidos (dwh_unidos, BD remota).
 * Muestra el avance de geocodificación y da acceso a las herramientas.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';     // guard: require_module('qrobus')
require_once __DIR__ . '/lib.php';

$puedeEditar = function_exists('puede_editar') && puede_editar('qrobus');
$nombre = htmlspecialchars((string)(Auth::user()['nombre'] ?? 'usuario'));

$stats = null; $dbError = null;
try { $stats = qb_stats(qb_pdo()); }
catch (Throwable $e) { $dbError = $e->getMessage(); }
$pct = ($stats && $stats['total'] > 0) ? round($stats['con_coords'] / $stats['total'] * 100) : 0;
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Qrobus · Inicio</title>
  <link rel="stylesheet" href="../../assets/css/qro.css">
</head>
<body>
<?php $portalModulo = 'Qrobus'; $navActive = 'home'; include __DIR__ . '/_nav.php'; ?>

<main class="content" style="padding:28px 32px">
  <div style="background:linear-gradient(120deg,var(--qro-blue-dark),var(--qro-blue));color:#fff;border-radius:16px;padding:24px 26px;margin-bottom:22px">
    <h1 style="color:#fff;margin:0 0 4px;font-size:24px">Hola, <?= $nombre ?></h1>
    <p style="margin:0;opacity:.9;font-size:14px">Beneficiarios <strong>Unidos</strong> · geocodificación y análisis territorial.</p>
  </div>

  <?php if ($dbError): ?>
    <div class="alert alert-danger">No se pudo conectar a la base de datos de Qrobus. Revisa las variables <code>QROBUS_DB_*</code>.<br><span style="font-size:12px;opacity:.8"><?= htmlspecialchars($dbError) ?></span></div>
  <?php else: ?>
    <div class="kpi-grid" style="margin-bottom:24px">
      <div class="card"><div class="kpi"><div class="kpi-label">Beneficiarios</div><div class="kpi-value"><?= number_format($stats['total']) ?></div></div></div>
      <div class="card"><div class="kpi"><div class="kpi-label">Con coordenadas</div><div class="kpi-value" style="color:var(--qro-success)"><?= number_format($stats['con_coords']) ?></div><div class="kpi-delta"><?= $pct ?>% geocodificado</div></div></div>
      <div class="card"><div class="kpi"><div class="kpi-label">Sin coordenadas</div><div class="kpi-value" style="color:var(--qro-danger)"><?= number_format($stats['sin_coords']) ?></div></div></div>
      <div class="card"><div class="kpi"><div class="kpi-label">Pendientes con dirección</div><div class="kpi-value" style="color:var(--qro-warning)"><?= number_format($stats['pendientes']) ?></div><div class="kpi-delta">geocodificables ahora</div></div></div>
    </div>
  <?php endif; ?>

  <div class="grid-cards">
    <a class="card card-modulo" href="kpis.php" style="--accent:#254185">
      <span class="card-modulo-dot"></span>
      <h3>📊 Principales KPIs</h3>
      <p class="text-secondary" style="font-size:13px">Perfil demográfico y de operación: sexo, edad, municipios, escolaridad, estatus y tendencia.</p>
    </a>
    <a class="card card-modulo" href="mapa.php" style="--accent:#005ab2">
      <span class="card-modulo-dot"></span>
      <h3>🗺 Mapa seccional</h3>
      <p class="text-secondary" style="font-size:13px">Mapa de calor de beneficiarios y secciones coloreadas por concentración, con filtros.</p>
    </a>
    <?php if ($puedeEditar): ?>
    <a class="card card-modulo" href="geocode.php" style="--accent:#188a5b">
      <span class="card-modulo-dot"></span>
      <h3>🌐 Geocodificar</h3>
      <p class="text-secondary" style="font-size:13px">Convierte direcciones en latitud/longitud: prueba una dirección al vuelo o procesa por lotes la tabla.</p>
    </a>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
