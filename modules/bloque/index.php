<?php
/**
 * Bloque · Inicio — edificio de innovación y tecnología (cursos y actividades).
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';   // guard: require_module('bloque')
require_once __DIR__ . '/lib.php';

$nombre = htmlspecialchars((string)(Auth::user()['nombre'] ?? 'usuario'));
$D = null; $dbError = null;
try {
    $pdo = bl_pdo();
    $one = fn(string $s) => $pdo->query($s)->fetchColumn();
    $D = [
        'usuarios'    => (int)$one("SELECT COUNT(*) FROM v_usuarios"),
        'asistencias' => (int)$one("SELECT COUNT(*) FROM asistencias"),
        'presentes'   => (int)$one("SELECT COUNT(*) FROM asistencias WHERE asistencia_estatus='present'"),
        'actividades' => bl_existe($pdo,'actividades') ? (int)$one("SELECT COUNT(*) FROM actividades") : 0,
    ];
} catch (Throwable $e) { $dbError = $e->getMessage(); }
$tasa = ($D && $D['asistencias']>0) ? round($D['presentes']/$D['asistencias']*100) : 0;
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bloque · Inicio</title>
  <link rel="stylesheet" href="../../assets/css/qro.css">
</head>
<body>
<?php $portalModulo='Bloque'; $navActive='home'; include __DIR__ . '/_nav.php'; ?>

<main class="content" style="padding:28px 32px">
  <div style="background:linear-gradient(120deg,var(--qro-blue-dark),var(--qro-blue));color:#fff;border-radius:16px;padding:24px 26px;margin-bottom:22px">
    <h1 style="color:#fff;margin:0 0 4px;font-size:24px">Hola, <?= $nombre ?></h1>
    <p style="margin:0;opacity:.9;font-size:14px"><strong>Bloque</strong> · edificio de innovación y tecnología: cursos, actividades y asistencia.</p>
  </div>

  <?php if ($dbError): ?>
    <div class="alert alert-danger">No se pudieron leer las tablas de Bloque en <code>portal_qro</code>.<br><span style="font-size:12px"><?= htmlspecialchars($dbError) ?></span></div>
  <?php else: ?>
    <div class="kpi-grid" style="margin-bottom:24px">
      <div class="card"><div class="kpi"><div class="kpi-label">Usuarios registrados</div><div class="kpi-value"><?= number_format($D['usuarios']) ?></div></div></div>
      <div class="card"><div class="kpi"><div class="kpi-label">Asistencias</div><div class="kpi-value"><?= number_format($D['asistencias']) ?></div></div></div>
      <div class="card"><div class="kpi"><div class="kpi-label">Tasa de asistencia</div><div class="kpi-value" style="color:var(--qro-success)"><?= $tasa ?>%</div><div class="kpi-delta"><?= number_format($D['presentes']) ?> presentes</div></div></div>
      <div class="card"><div class="kpi"><div class="kpi-label">Actividades</div><div class="kpi-value"><?= number_format($D['actividades']) ?></div></div></div>
    </div>
  <?php endif; ?>

  <div class="grid-cards">
    <a class="card card-modulo" href="kpis.php" style="--accent:#254185">
      <span class="card-modulo-dot"></span>
      <h3>📊 Principales KPIs</h3>
      <p class="text-secondary" style="font-size:13px">Asistencia, perfil de usuarios (edad, género, tipo de cuenta), actividades más concurridas y tendencia.</p>
    </a>
    <a class="card card-modulo" href="delegaciones.php" style="--accent:#188a5b">
      <span class="card-modulo-dot"></span>
      <h3>🏙 Por delegación</h3>
      <p class="text-secondary" style="font-size:13px">De dónde viene la gente: usuarios, asistencias y tasa de asistencia por delegación, con cruces gráficos.</p>
    </a>
  </div>
</main>
</body>
</html>
