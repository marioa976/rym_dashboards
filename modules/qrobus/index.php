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
?><?php
$ktTitle  = 'Qrobus · Inicio';
$ktActive = 'qrobus';
require __DIR__ . '/../../views/layout/kt_top.php';
?>

  <div style="background:linear-gradient(120deg,var(--qro-blue-dark),var(--qro-blue));color:#fff;border-radius:16px;padding:24px 26px;margin-bottom:22px">
    <h1 style="color:#fff;margin:0 0 4px;font-size:24px">Hola, <?= $nombre ?></h1>
    <p style="margin:0;opacity:.9;font-size:14px">Beneficiarios <strong>Unidos</strong> · geocodificación y análisis territorial.</p>
  </div>

  <?php if ($dbError): ?>
    <div class="alert alert-danger">No se pudo conectar a la base de datos de Qrobus. Revisa las variables <code>QROBUS_DB_*</code>.<br><span style="font-size:12px;opacity:.8"><?= htmlspecialchars($dbError) ?></span></div>
  <?php else: ?>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
      <div class="kt-card"><div class="kt-card-content p-4 flex flex-col gap-0.5"><span class="text-xs text-secondary-foreground font-semibold">Beneficiarios</span><span class="text-2xl font-bold text-primary leading-tight"><?= number_format($stats['total']) ?></span></div></div>
      <div class="kt-card"><div class="kt-card-content p-4 flex flex-col gap-0.5"><span class="text-xs text-secondary-foreground font-semibold">Con coordenadas</span><span class="text-2xl font-bold leading-tight" style="color:var(--qro-success)"><?= number_format($stats['con_coords']) ?></span><span class="text-xs text-muted-foreground"><?= $pct ?>% geocodificado</span></div></div>
      <div class="kt-card"><div class="kt-card-content p-4 flex flex-col gap-0.5"><span class="text-xs text-secondary-foreground font-semibold">Sin coordenadas</span><span class="text-2xl font-bold leading-tight" style="color:var(--qro-danger)"><?= number_format($stats['sin_coords']) ?></span></div></div>
      <div class="kt-card"><div class="kt-card-content p-4 flex flex-col gap-0.5"><span class="text-xs text-secondary-foreground font-semibold">Pendientes con dirección</span><span class="text-2xl font-bold leading-tight" style="color:var(--qro-warning)"><?= number_format($stats['pendientes']) ?></span><span class="text-xs text-muted-foreground">geocodificables ahora</span></div></div>
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
<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
