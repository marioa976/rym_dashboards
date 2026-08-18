<?php
/**
 * QroBici Analytics — Página de aterrizaje
 * ------------------------------------------------------------
 * Index del proyecto: hub que apunta a los tres módulos
 * (reporte analítico, mapa animado e informe ejecutivo) y
 * muestra un par de números rápidos del estado actual.
 */

declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/db.php';

$cfg = require __DIR__ . '/config.php';

if (!empty($cfg['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}
date_default_timezone_set($cfg['zona_horaria'] ?? 'America/Mexico_City');

// Snapshot ligero para los tarjetones — una sola query agregada
$snap = [
    'total_viajes'    => 0,
    'usuarios'        => 0,
    'estaciones'      => 0,
    'planes'          => 0,
    'ultima_fecha'    => null,
    'primera_fecha'   => null,
    'conexion'        => false,
    'error'           => null,
];

try {
    $pdo = qrb_db($cfg);
    $snap['conexion'] = true;

    $vista_v = $cfg['vistas']['viajes'];
    [$wfe, $params] = qrb_where_fecha('FECHA', $cfg['fecha_desde'], $cfg['fecha_hasta']);
    $sql = "SELECT COUNT(*) AS n,
                   COUNT(DISTINCT USUARIO_ID) AS uu,
                   COUNT(DISTINCT ESTACION_ORIGEN) AS est,
                   MIN(DATE(FECHA)) AS fmin,
                   MAX(DATE(FECHA)) AS fmax
            FROM `$vista_v`
            WHERE 1=1 $wfe";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    if ($row) {
        $snap['total_viajes']  = (int)$row['n'];
        $snap['usuarios']      = (int)$row['uu'];
        $snap['estaciones']    = (int)$row['est'];
        $snap['primera_fecha'] = $row['fmin'];
        $snap['ultima_fecha']  = $row['fmax'];
    }

    // planes (opcional, no debe romper si la vista no existe)
    try {
        $vista_p = $cfg['vistas']['planes'];
        $stmt = $pdo->query("SELECT COUNT(*) FROM `$vista_p`");
        $snap['planes'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) { /* vista de planes no accesible: silencio */ }
} catch (Throwable $e) {
    $snap['error'] = !empty($cfg['debug']) ? $e->getMessage() : 'No fue posible conectar a la BD.';
}

function ix_fmt(int $n): string { return number_format($n); }
function ix_fecha_es(?string $d): string {
    if (!$d) return '—';
    $meses = [1=>'enero','febrero','marzo','abril','mayo','junio',
              'julio','agosto','septiembre','octubre','noviembre','diciembre'];
    $ts = strtotime($d);
    return (int)date('j', $ts) . ' de ' . $meses[(int)date('n', $ts)] . ' de ' . date('Y', $ts);
}

?><?php
$ktTitle  = 'QroBici Analytics';
$ktActive = 'qrobici';
require __DIR__ . '/../../views/layout/kt_top.php';
?><style>
  /* qrobici · index — homologado con tokens Metronic del portal */
  .qb-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:22px}
  .qb-head h1{font-size:22px;font-weight:700;color:var(--foreground);letter-spacing:-.01em}
  .qb-head p{font-size:14px;color:var(--muted-foreground);margin-top:6px;max-width:72ch;line-height:1.6}
  .qb-status{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;background:#ecfdf5;color:#047857;font-size:12px;font-weight:600;white-space:nowrap}
  .qb-status.err{background:#fef2f2;color:#b91c1c}
  .qb-status .dot{width:8px;height:8px;border-radius:50%;background:currentColor}
  .qb-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:26px}
  @media(max-width:1100px){.qb-kpis{grid-template-columns:repeat(2,1fr)}}
  @media(max-width:560px){.qb-kpis{grid-template-columns:1fr}}
  .qb-card{background:var(--card);border:1px solid var(--border);border-radius:.75rem;padding:18px 20px}
  .qb-kpi .l{font-size:12px;color:var(--muted-foreground);font-weight:500;margin-bottom:8px}
  .qb-kpi .v{font-size:30px;font-weight:600;letter-spacing:-.02em;line-height:1;color:var(--primary);font-variant-numeric:tabular-nums}
  .qb-label{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted-foreground);font-weight:600;margin:4px 0 14px}
  .qb-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px}
  .qb-mod{position:relative;display:flex;flex-direction:column;background:var(--card);border:1px solid var(--border);border-radius:.75rem;padding:22px;text-decoration:none;color:inherit;transition:box-shadow .2s ease,border-color .2s ease,transform .2s ease}
  .qb-mod:hover{box-shadow:0 12px 30px rgba(10,27,61,.08);border-color:var(--primary);transform:translateY(-3px)}
  .qb-mod .num{position:absolute;top:20px;right:22px;font-size:11px;font-weight:600;color:var(--muted-foreground);font-variant-numeric:tabular-nums}
  .qb-mod .ic{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#e8f1fb;color:var(--primary);margin-bottom:16px}
  .qb-mod .ic svg{width:22px;height:22px}
  .qb-mod h2{font-size:17px;font-weight:700;color:var(--foreground);line-height:1.25}
  .qb-mod p{font-size:13px;color:var(--muted-foreground);margin-top:8px;line-height:1.55;flex:1}
  .qb-mod .tags{display:flex;gap:6px;margin-top:14px;flex-wrap:wrap}
  .qb-mod .tg{font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted-foreground);padding:3px 8px;background:var(--muted);border-radius:6px}
  .qb-mod .go{margin-top:14px;font-size:12px;font-weight:600;color:var(--primary);text-transform:uppercase;letter-spacing:.04em}
  .qb-err{background:var(--card);border:1px solid var(--border);border-inline-start:3px solid #dc2626;border-radius:.5rem;padding:16px 18px;margin-bottom:22px}
  .qb-err .t{font-weight:700;color:#dc2626;font-size:14px}
  .qb-err .m{font-size:12px;color:var(--muted-foreground);margin-top:6px}
  .qb-foot{margin-top:28px;padding-top:18px;border-top:1px solid var(--border);font-size:12px;color:var(--muted-foreground);display:flex;justify-content:space-between;flex-wrap:wrap;gap:14px}
  .qb-foot .right{display:flex;gap:20px;flex-wrap:wrap}
</style>

<div class="qb-head">
  <div>
    <h1>QroBici · Inteligencia de movilidad</h1>
    <p>Tres vistas complementarias del mismo conjunto de datos: el reporte analítico para entender, el mapa animado para ver el flujo, y el informe ejecutivo para decidir. Conectado en vivo a la base operativa.</p>
  </div>
  <?php if ($snap['conexion']): ?>
    <div class="qb-status"><span class="dot"></span> Datos en vivo</div>
  <?php else: ?>
    <div class="qb-status err"><span class="dot"></span> Sin conexión</div>
  <?php endif; ?>
</div>

<?php if ($snap['error']): ?>
  <div class="qb-err">
    <div class="t">No fue posible cargar el snapshot</div>
    <div class="m"><?= htmlspecialchars($snap['error'], ENT_QUOTES, 'UTF-8') ?></div>
  </div>
<?php else: ?>
  <div class="qb-kpis">
    <div class="qb-card qb-kpi"><div class="l">Viajes registrados</div><div class="v"><?= ix_fmt($snap['total_viajes']) ?></div></div>
    <div class="qb-card qb-kpi"><div class="l">Usuarios únicos</div><div class="v"><?= ix_fmt($snap['usuarios']) ?></div></div>
    <div class="qb-card qb-kpi"><div class="l">Estaciones activas</div><div class="v"><?= ix_fmt($snap['estaciones']) ?></div></div>
    <div class="qb-card qb-kpi"><div class="l">Suscripciones</div><div class="v"><?= ix_fmt($snap['planes']) ?></div></div>
  </div>
<?php endif; ?>

<div class="qb-label">Tres formas de mirar los datos</div>
<div class="qb-cards">

  <a href="reporte.php" class="qb-mod">
    <div class="num">01</div>
    <div class="ic">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/>
        <line x1="6" y1="20" x2="6" y2="14"/><line x1="3" y1="20" x2="21" y2="20"/>
      </svg>
    </div>
    <h2>Reporte analítico</h2>
    <p>El estudio completo: 7 secciones con KPIs, distribuciones, mapa de estaciones, demografía, patrones temporales, suscripciones e impacto ambiental.</p>
    <div class="tags"><span class="tg">Análisis</span><span class="tg">Charts</span><span class="tg">Mapa</span></div>
    <div class="go">Abrir →</div>
  </a>

  <a href="mapa_animado.php" class="qb-mod">
    <div class="num">02</div>
    <div class="ic">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M3 7l6-3 6 3 6-3v13l-6 3-6-3-6 3V7z"/><line x1="9" y1="4" x2="9" y2="17"/><line x1="15" y1="7" x2="15" y2="20"/>
      </svg>
    </div>
    <h2>Mapa animado de flujo</h2>
    <p>Trails neón sobre Google Maps oscuro: partículas brillantes recorren las rutas reales mientras avanza un reloj acelerado del día seleccionado.</p>
    <div class="tags"><span class="tg">Tiempo real</span><span class="tg">GPS</span><span class="tg">Animado</span></div>
    <div class="go">Abrir →</div>
  </a>

  <a href="informe.php" class="qb-mod">
    <div class="num">03</div>
    <div class="ic">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
        <line x1="9" y1="14" x2="15" y2="14"/><line x1="9" y1="18" x2="13" y2="18"/>
      </svg>
    </div>
    <h2>Informe ejecutivo</h2>
    <p>Presentación por slides para directivos: 10 secciones con el comportamiento del periodo y recomendaciones data-driven generadas al vuelo.</p>
    <div class="tags"><span class="tg">Slides</span><span class="tg">Recos</span><span class="tg">Ejecutivo</span></div>
    <div class="go">Abrir →</div>
  </a>

  <a href="mapa_riesgos.php" class="qb-mod">
    <div class="num">04</div>
    <div class="ic">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
        <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
      </svg>
    </div>
    <h2>Mapa de riesgos</h2>
    <p>Cruza el feed de incidentes Waze en tiempo real con las rutas reales de bicicleta para detectar tramos donde la operación coincide con problemas viales.</p>
    <div class="tags"><span class="tg">Waze</span><span class="tg">Heatmap</span><span class="tg">Tiempo real</span></div>
    <div class="go">Abrir →</div>
  </a>

  <a href="reporte_bicis.php" class="qb-mod">
    <div class="num">05</div>
    <div class="ic">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="6" cy="17" r="4"/><circle cx="18" cy="17" r="4"/><path d="M6 17l3-6h6l3 6"/><path d="M9 11l1.5-3H14"/><circle cx="12" cy="6" r="1"/>
      </svg>
    </div>
    <h2>Performance de bicicletas</h2>
    <p>Cada bici de la flota medida por uso, salud operativa y percepción del usuario: las que más cargan la operación, las ociosas, las que requieren atención.</p>
    <div class="tags"><span class="tg">Flota</span><span class="tg">Mantenimiento</span><span class="tg">KPIs</span></div>
    <div class="go">Abrir →</div>
  </a>

</div>

<div class="qb-foot">
  <div class="left">Periodo:
    <?php if ($cfg['fecha_desde'] || $cfg['fecha_hasta']): ?>
      <?= htmlspecialchars(($cfg['fecha_desde'] ?: 'inicio'), ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars(($cfg['fecha_hasta'] ?: 'hoy'), ENT_QUOTES, 'UTF-8') ?>
    <?php else: ?>
      <?= ix_fecha_es($snap['primera_fecha']) ?> — <?= ix_fecha_es($snap['ultima_fecha']) ?>
    <?php endif; ?>
  </div>
  <div class="right">
    <span>Cache: <?= (int)($cfg['cache_segundos'] ?? 0) ?>s</span>
    <span>Zona: <?= htmlspecialchars($cfg['zona_horaria'] ?? 'America/Mexico_City', ENT_QUOTES, 'UTF-8') ?></span>
    <span>Generado: <?= date('d/m/Y H:i') ?></span>
  </div>
</div>

<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
