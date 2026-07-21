<?php
require_once __DIR__ . '/../lib/bootstrap.php';
$pdo  = reporteador_pdo();
$BASE = reporteador_base_url_safe();
$U    = auth_user();
$esAdmin = ($U['role'] ?? '') === 'administrador';

/* KPIs con etiqueta legible (no el nombre de tabla crudo) */
$kpiDefs = [
    'elecciones'           => ['Elecciones cargadas',  'Procesos × tipo × ámbito', '#254185'],
    'casillas'             => ['Casillas',             'Mesas receptoras',          '#005ab2'],
    'resultados_casilla'   => ['Registros de voto',    'Votos por casilla y código','#2a9eda'],
    'partidos'             => ['Partidos',             'Catálogo de fuerzas',       '#188a5b'],
    'candidatos'           => ['Candidatos',           'Registrados por elección',  '#d99000'],
    'import_log_resultados'=> ['Importaciones',        'Cargas de resultados',      '#ce3a2b'],
];
$kpis = [];
foreach ($kpiDefs as $t => $_) {
    try { $kpis[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); }
    catch (Throwable $e) { $kpis[$t] = 0; }
}

/* Última importación (defensivo: no asumimos nombres de columna) */
$ultima = null;
try {
    $row = $pdo->query("SELECT * FROM import_log_resultados ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $fecha = $row['finished_at'] ?? $row['created_at'] ?? $row['fecha'] ?? null;
        $arch  = $row['archivo'] ?? $row['source_file'] ?? $row['nombre_archivo'] ?? null;
        $ultima = ['fecha' => $fecha, 'archivo' => $arch];
    }
} catch (Throwable $e) {}

/* Reportes disponibles (el "cajón") */
$reportes = [
    ['Rentabilidad seccional', 'Voto efectivo del partido objetivo por sección, tendencia y cuadrantes BCG.', 'reports/rentabilidad.php', '#254185', '▣'],
    ['Cruce por sección',      'Voto por sección × padrón DIF × atención ciudadana (Zendesk), con mapa y filtros.', 'reports/cruce.php', '#0ea5e9', '◍'],
    ['Afinidad partidista',    'Índice de afinidad (IAP) por sección con pesos ajustables, gauge y mapa.',  'reports/afinidad.php', '#ce3a2b', '◐'],
];

$title = 'Inicio';
$active = 'dashboard';
include __DIR__ . '/partials/layout_top.php';
?>
<style>
  .el-hero{background:linear-gradient(120deg,var(--qro-blue-dark),var(--qro-blue));color:#fff;border-radius:16px;padding:26px 28px;margin-bottom:22px;box-shadow:var(--shadow-card)}
  .el-hero h1{color:#fff;margin:0 0 4px;font-size:26px}
  .el-hero p{margin:0;opacity:.9;font-size:14px}
  .el-hero .chip-upd{display:inline-block;margin-top:14px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.25);border-radius:999px;padding:5px 12px;font-size:12px}
  .el-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:26px}
  .el-kpi{background:var(--color-surface);border:1px solid var(--color-border);border-radius:14px;padding:16px 18px;position:relative;overflow:hidden;box-shadow:var(--shadow-card)}
  .el-kpi .acc{position:absolute;left:0;top:0;bottom:0;width:4px}
  .el-kpi .v{font-size:28px;font-weight:800;color:var(--qro-blue-dark);line-height:1.05}
  .el-kpi .l{font-size:13px;font-weight:700;margin-top:4px}
  .el-kpi .s{font-size:11px;color:var(--color-text-muted);margin-top:2px}
  .el-sec-h{font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:var(--color-text-muted);font-weight:700;margin:0 0 12px}
  .el-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
  .el-card{display:block;background:var(--color-surface);border:1px solid var(--color-border);border-radius:14px;padding:20px;box-shadow:var(--shadow-card);color:inherit;text-decoration:none;transition:.15s;position:relative}
  .el-card:hover{box-shadow:var(--shadow-md);transform:translateY(-2px);text-decoration:none}
  .el-card .ico{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;margin-bottom:12px}
  .el-card h3{margin:0 0 6px;font-size:16px;color:var(--qro-blue-dark)}
  .el-card p{margin:0;font-size:13px;color:var(--color-text-secondary);line-height:1.45}
  .el-card .go{margin-top:12px;font-size:13px;font-weight:700;color:var(--qro-blue)}
  .el-admin{margin-top:26px}
  .el-admin .row{display:flex;gap:10px;flex-wrap:wrap}
</style>

<section class="el-hero">
  <h1>Hola, <?= htmlspecialchars($U['name']) ?></h1>
  <p>Reporteador seccional · análisis territorial con datos del IEEQ / INE.</p>
  <?php if ($ultima && $ultima['fecha']): ?>
    <span class="chip-upd">Última importación: <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$ultima['fecha']))) ?><?= $ultima['archivo'] ? ' · ' . htmlspecialchars($ultima['archivo']) : '' ?></span>
  <?php endif; ?>
</section>

<div class="el-kpis">
  <?php foreach ($kpiDefs as $t => $d): ?>
    <div class="el-kpi">
      <span class="acc" style="background:<?= $d[2] ?>"></span>
      <div class="v"><?= number_format($kpis[$t]) ?></div>
      <div class="l"><?= htmlspecialchars($d[0]) ?></div>
      <div class="s"><?= htmlspecialchars($d[1]) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<p class="el-sec-h">Reportes</p>
<div class="el-cards">
  <?php foreach ($reportes as $r): ?>
    <a class="el-card" href="<?= $BASE ?>/<?= $r[2] ?>">
      <div class="ico" style="background:<?= $r[3] ?>"><?= $r[4] ?></div>
      <h3><?= htmlspecialchars($r[0]) ?></h3>
      <p><?= htmlspecialchars($r[1]) ?></p>
      <div class="go">Abrir →</div>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($esAdmin): ?>
<div class="el-admin">
  <p class="el-sec-h">Administración</p>
  <div class="row">
    <a class="btn" href="<?= $BASE ?>/admin/importar_resultados.php">⬆ Importar resultados</a>
    <a class="btn btn-secondary" href="<?= $BASE ?>/admin/elecciones.php">⚙ Gestionar elecciones</a>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/partials/layout_bottom.php'; ?>
