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
?><link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800;900&family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap">
<style>
:root {
  --azul:#254185;
  --azul-d:#1a2f63;
  --azul-l:#e8f0ff;
  --azul-ll:#f4f8ff;
  --tinta:#0a1b3d;
  --tinta-2:#3c4a6e;
  --tinta-3:#7287ac;
  --verde:#188a5b;
  --ambar:#d99000;
  --rojo:#ce3a2b;
  --rosa:#5b667a;
  --gris:#e6ecf5;
  --gris-2:#f4f6fb;
}
* { box-sizing:border-box; margin:0; padding:0; }
html, body { height:100%; }
body {
  font-family:'Space Grotesk', system-ui, sans-serif;
  color:var(--tinta);
  -webkit-font-smoothing:antialiased;
  text-rendering:optimizeLegibility;
  background:
    radial-gradient(900px 600px at 15% -10%, rgba(0,87,255,.08) 0%, transparent 60%),
    radial-gradient(800px 500px at 100% 110%, rgba(255,111,165,.06) 0%, transparent 55%),
    var(--azul-ll);
  min-height:100vh;
  display:flex; flex-direction:column;
}

/* ===== HEADER ===== */
header.topbar {
  display:flex; justify-content:space-between; align-items:center;
  padding:18px 36px;
  border-bottom:1px solid var(--gris);
  background:rgba(255,255,255,.6);
  backdrop-filter:blur(14px);
  -webkit-backdrop-filter:blur(14px);
}
.brand-mini {
  display:flex; align-items:center; gap:12px;
}
.brand-mini .logo {
  width:38px; height:38px; border-radius:10px;
  background:linear-gradient(135deg, var(--azul), var(--azul-d));
  color:#fff;
  display:flex; align-items:center; justify-content:center;
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:18px; letter-spacing:-1px;
  box-shadow:0 8px 22px rgba(0,87,255,.25);
}
.brand-mini .nm {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:18px; letter-spacing:-.5px; color:var(--tinta);
}
.brand-mini .sl {
  font-family:'Space Mono', monospace;
  font-size:10px; letter-spacing:1.5px;
  text-transform:uppercase; color:var(--tinta-3);
  margin-top:2px;
}
.status {
  display:inline-flex; align-items:center; gap:8px;
  padding:6px 12px; border-radius:999px;
  background:rgba(0,184,124,.1);
  font-family:'Space Mono', monospace;
  font-size:11px; letter-spacing:1.5px; text-transform:uppercase;
  color:var(--verde); font-weight:700;
}
.status.err { background:rgba(255,77,94,.1); color:var(--rojo); }
.status .dot {
  width:8px; height:8px; border-radius:50%;
  background:currentColor;
  box-shadow:0 0 0 4px rgba(0,184,124,.2);
  animation:pulse 2s ease-in-out infinite;
}
.status.err .dot { box-shadow:0 0 0 4px rgba(255,77,94,.2); }
@keyframes pulse { 0%,100% { opacity:1 } 50% { opacity:.5 } }

/* ===== HERO ===== */
.hero {
  padding:100px 36px 60px;
  max-width:1280px;
  margin:0 auto;
  width:100%;
}
.hero .tag {
  display:inline-block;
  font-family:'Space Mono', monospace; font-size:11px;
  letter-spacing:2.5px; text-transform:uppercase;
  color:var(--azul); background:var(--azul-l);
  padding:6px 14px; border-radius:999px;
  margin-bottom:24px;
}
.hero h1 {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:clamp(48px, 7vw, 96px);
  line-height:.96; letter-spacing:-2.5px;
  color:var(--tinta);
  max-width:14ch;
}
.hero h1 span {
  background:linear-gradient(135deg, var(--azul), var(--rosa));
  -webkit-background-clip:text; background-clip:text; color:transparent;
}
.hero .sub {
  font-size:clamp(17px, 1.3vw, 20px);
  color:var(--tinta-2);
  margin-top:24px;
  line-height:1.6;
  max-width:62ch;
}

/* ===== STRIP DE KPIS HOT ===== */
.kpi-strip {
  display:grid;
  grid-template-columns:repeat(4, 1fr);
  gap:0;
  margin:60px auto 0;
  max-width:1280px;
  width:calc(100% - 72px);
  background:#fff;
  border:1px solid var(--gris);
  border-radius:18px;
  overflow:hidden;
  box-shadow:0 18px 50px rgba(10,27,61,.06);
}
.kpi-strip .k {
  padding:24px 28px;
  border-right:1px solid var(--gris);
}
.kpi-strip .k:last-child { border-right:none; }
.kpi-strip .k .lbl {
  font-family:'Space Mono', monospace;
  font-size:10px; letter-spacing:2px;
  color:var(--tinta-3); text-transform:uppercase;
}
.kpi-strip .k .val {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:36px; line-height:1; margin-top:8px;
  color:var(--tinta);
  font-variant-numeric:tabular-nums;
}
.kpi-strip .k .val .unit {
  font-size:13px; color:var(--tinta-3);
  font-family:'Space Mono', monospace; font-weight:400;
  margin-left:6px;
}

/* ===== CARDS DE MÓDULOS ===== */
.modules {
  padding:80px 36px 100px;
  max-width:1280px;
  margin:0 auto;
  width:100%;
}
.modules .label {
  font-family:'Space Mono', monospace;
  font-size:11px; letter-spacing:2.5px; text-transform:uppercase;
  color:var(--tinta-3); margin-bottom:28px;
}
.cards {
  display:grid;
  grid-template-columns:repeat(5, 1fr);
  gap:14px;
}
@media (max-width:1400px) {
  .cards { grid-template-columns:repeat(3, 1fr); }
}
@media (max-width:980px) {
  .cards { grid-template-columns:repeat(2, 1fr); }
}
@media (max-width:560px) {
  .cards { grid-template-columns:1fr; }
}
.card {
  position:relative;
  display:flex; flex-direction:column;
  background:#fff;
  border:1px solid var(--gris);
  border-radius:22px;
  padding:34px 30px 30px;
  text-decoration:none;
  color:inherit;
  overflow:hidden;
  transition:transform .25s cubic-bezier(.16,.84,.42,1.05),
             box-shadow .25s ease,
             border-color .25s ease;
}
.card:hover {
  transform:translateY(-6px);
  box-shadow:0 28px 60px rgba(10,27,61,.12);
  border-color:transparent;
}
.card .icon {
  width:54px; height:54px; border-radius:14px;
  display:flex; align-items:center; justify-content:center;
  margin-bottom:24px;
  position:relative;
}
.card .icon svg { width:28px; height:28px; }
.card .num {
  position:absolute;
  top:34px; right:34px;
  font-family:'Space Mono', monospace;
  font-size:11px; letter-spacing:1.5px;
  color:var(--tinta-3);
}
.card h2 {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:26px; line-height:1.15; letter-spacing:-.5px;
  color:var(--tinta);
}
.card p {
  font-size:14px; line-height:1.6;
  color:var(--tinta-2);
  margin-top:12px;
  flex:1;
}
.card .arrow {
  display:inline-flex; align-items:center; gap:8px;
  margin-top:22px;
  font-family:'Space Mono', monospace;
  font-size:12px; font-weight:700;
  letter-spacing:1.5px; text-transform:uppercase;
}
.card .arrow .a { font-size:16px; transition:transform .25s ease; }
.card:hover .arrow .a { transform:translateX(4px); }
.card .tags {
  display:flex; gap:6px; margin-top:16px; flex-wrap:wrap;
}
.card .tg {
  font-family:'Space Mono', monospace;
  font-size:10px; letter-spacing:1px; text-transform:uppercase;
  color:var(--tinta-3);
  padding:4px 8px;
  background:var(--gris-2);
  border-radius:6px;
}

/* variantes de color por módulo */
.card.reporte .icon { background:linear-gradient(135deg, var(--azul-l), #d6e2ff); color:var(--azul); }
.card.reporte:hover { background:linear-gradient(180deg, #fff 0%, #f8fbff 100%); }
.card.reporte .arrow { color:var(--azul); }

.card.mapa .icon { background:linear-gradient(135deg, #0a1230, #1a0830); color:#00d4ff; }
.card.mapa { color:var(--tinta); }
.card.mapa:hover {
  background:linear-gradient(180deg, #0b1430 0%, #02030a 100%);
  color:#e8efff;
  border-color:transparent;
}
.card.mapa:hover h2 { color:#fff; }
.card.mapa:hover p { color:rgba(232,239,255,.7); }
.card.mapa:hover .tg { background:rgba(120,160,255,.12); color:#7fb1ff; }
.card.mapa:hover .num { color:#7fb1ff; }
.card.mapa .arrow { color:#00d4ff; }

.card.informe .icon { background:linear-gradient(135deg, #ffe6f0, #ffcedd); color:var(--rosa); }
.card.informe:hover { background:linear-gradient(180deg, #fff 0%, #fff8fb 100%); }
.card.informe .arrow { color:var(--rosa); }

.card.riesgos .icon { background:linear-gradient(135deg, #ffe2d2, #ffd0d0); color:var(--rojo); }
.card.riesgos:hover { background:linear-gradient(180deg, #fff 0%, #fffaf8 100%); }
.card.riesgos .arrow { color:var(--rojo); }

.card.flota .icon { background:linear-gradient(135deg, #d8f5e8, #b9ecd2); color:var(--verde); }
.card.flota:hover { background:linear-gradient(180deg, #fff 0%, #f7fdfa 100%); }
.card.flota .arrow { color:var(--verde); }

/* ===== FOOTER ===== */
footer {
  margin-top:auto;
  padding:24px 36px;
  border-top:1px solid var(--gris);
  background:rgba(255,255,255,.6);
  font-family:'Space Mono', monospace;
  font-size:11px; letter-spacing:1px;
  color:var(--tinta-3);
  display:flex; justify-content:space-between;
  flex-wrap:wrap; gap:14px;
}
footer .right { display:flex; gap:24px; flex-wrap:wrap; }
footer a { color:var(--tinta-3); text-decoration:none; }
footer a:hover { color:var(--azul); }

/* ===== ERROR CARD ===== */
.err-card {
  max-width:560px;
  background:#fff; border:1px solid var(--gris);
  border-left:4px solid var(--rojo);
  border-radius:14px;
  padding:22px 26px;
  margin:30px auto 0;
}
.err-card .tt {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:16px; color:var(--rojo);
  letter-spacing:.5px;
}
.err-card .msg {
  font-family:'Space Mono', monospace;
  font-size:12px; color:var(--tinta-2);
  margin-top:8px; line-height:1.55;
}

/* ===== RESPONSIVE ===== */
@media (max-width:980px) {
  .cards { grid-template-columns:1fr; }
  .kpi-strip { grid-template-columns:repeat(2, 1fr); }
  .kpi-strip .k:nth-child(2) { border-right:none; }
  .kpi-strip .k:nth-child(1), .kpi-strip .k:nth-child(2) { border-bottom:1px solid var(--gris); }
}
@media (max-width:560px) {
  .kpi-strip { grid-template-columns:1fr; width:calc(100% - 32px); }
  .kpi-strip .k { border-right:none; border-bottom:1px solid var(--gris); }
  .kpi-strip .k:last-child { border-bottom:none; }
  .hero, .modules { padding-left:20px; padding-right:20px; }
  .hero { padding-top:60px; }
  header.topbar { padding:14px 20px; }
}
</style>

<header class="topbar">
  <div class="brand-mini">
    <div class="logo">Q</div>
    <div>
      <div class="nm">QroBici Analytics</div>
      <div class="sl">Plataforma de inteligencia de bicicleta pública</div>
    </div>
  </div>
  <?php if ($snap['conexion']): ?>
    <div class="status"><span class="dot"></span> Datos en vivo</div>
  <?php else: ?>
    <div class="status err"><span class="dot"></span> Sin conexión</div>
  <?php endif; ?>
</header>

<section class="hero">
  <div class="tag">v1 · <?= date('M Y') ?></div>
  <h1>El sistema de bicicleta pública de Querétaro, <span>medido al pulso</span>.</h1>
  <p class="sub">
    Tres vistas complementarias del mismo conjunto de datos:
    el reporte analítico para entender, el mapa animado para sentir,
    y el informe ejecutivo para decidir. Conectado en vivo a la base
    de datos operativa, sin pasos intermedios ni exportaciones.
  </p>
</section>

<?php if ($snap['error']): ?>
  <div class="err-card">
    <div class="tt">No fue posible cargar el snapshot</div>
    <div class="msg"><?= htmlspecialchars($snap['error'], ENT_QUOTES, 'UTF-8') ?></div>
  </div>
<?php else: ?>
  <div class="kpi-strip">
    <div class="k">
      <div class="lbl">Viajes registrados</div>
      <div class="val"><?= ix_fmt($snap['total_viajes']) ?></div>
    </div>
    <div class="k">
      <div class="lbl">Usuarios únicos</div>
      <div class="val"><?= ix_fmt($snap['usuarios']) ?></div>
    </div>
    <div class="k">
      <div class="lbl">Estaciones activas</div>
      <div class="val"><?= ix_fmt($snap['estaciones']) ?></div>
    </div>
    <div class="k">
      <div class="lbl">Suscripciones</div>
      <div class="val"><?= ix_fmt($snap['planes']) ?></div>
    </div>
  </div>
<?php endif; ?>

<section class="modules">
  <div class="label">Tres formas de mirar los datos</div>
  <div class="cards">

    <a href="reporte.php" class="card reporte">
      <div class="num">01</div>
      <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="20" x2="18" y2="10"/>
          <line x1="12" y1="20" x2="12" y2="4"/>
          <line x1="6"  y1="20" x2="6"  y2="14"/>
          <line x1="3"  y1="20" x2="21" y2="20"/>
        </svg>
      </div>
      <h2>Reporte<br>analítico</h2>
      <p>El estudio completo: 7 secciones con KPIs, distribuciones, mapa de estaciones, demografía, patrones temporales, suscripciones e impacto ambiental.</p>
      <div class="tags">
        <span class="tg">Análisis</span>
        <span class="tg">SVG charts</span>
        <span class="tg">Mapa</span>
      </div>
      <div class="arrow">Abrir <span class="a">→</span></div>
    </a>

    <a href="mapa_animado.php" class="card mapa">
      <div class="num">02</div>
      <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 7l6-3 6 3 6-3v13l-6 3-6-3-6 3V7z"/>
          <line x1="9"  y1="4"  x2="9"  y2="17"/>
          <line x1="15" y1="7"  x2="15" y2="20"/>
        </svg>
      </div>
      <h2>Mapa animado<br>de flujo</h2>
      <p>Trails neón sobre Google Maps oscuro: partículas brillantes recorren las rutas reales mientras avanza un reloj acelerado del día seleccionado.</p>
      <div class="tags">
        <span class="tg">Tiempo real</span>
        <span class="tg">GPS</span>
        <span class="tg">Animado</span>
      </div>
      <div class="arrow">Abrir <span class="a">→</span></div>
    </a>

    <a href="informe.php" class="card informe">
      <div class="num">03</div>
      <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
          <polyline points="14 2 14 8 20 8"/>
          <line x1="9" y1="14" x2="15" y2="14"/>
          <line x1="9" y1="18" x2="13" y2="18"/>
        </svg>
      </div>
      <h2>Informe<br>ejecutivo</h2>
      <p>Presentación por slides para directivos: 10 secciones con el comportamiento del periodo y recomendaciones data-driven generadas al vuelo.</p>
      <div class="tags">
        <span class="tg">Slides</span>
        <span class="tg">Recos</span>
        <span class="tg">Ejecutivo</span>
      </div>
      <div class="arrow">Abrir <span class="a">→</span></div>
    </a>

    <a href="mapa_riesgos.php" class="card riesgos">
      <div class="num">04</div>
      <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
          <line x1="12" y1="9"  x2="12" y2="13"/>
          <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
      </div>
      <h2>Mapa<br>de riesgos</h2>
      <p>Cruza el feed de incidentes Waze en tiempo real con las rutas reales de bicicleta para detectar tramos donde la operación coincide con problemas viales.</p>
      <div class="tags">
        <span class="tg">Waze</span>
        <span class="tg">Heatmap</span>
        <span class="tg">Tiempo real</span>
      </div>
      <div class="arrow">Abrir <span class="a">→</span></div>
    </a>

    <a href="reporte_bicis.php" class="card flota">
      <div class="num">05</div>
      <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="6"  cy="17" r="4"/>
          <circle cx="18" cy="17" r="4"/>
          <path d="M6 17l3-6h6l3 6"/>
          <path d="M9 11l1.5-3H14"/>
          <circle cx="12" cy="6" r="1"/>
        </svg>
      </div>
      <h2>Performance<br>de bicicletas</h2>
      <p>Cada bici de la flota medida por uso, salud operativa y percepción del usuario: las que más cargan la operación, las ociosas, las que requieren atención.</p>
      <div class="tags">
        <span class="tg">Flota</span>
        <span class="tg">Mantenimiento</span>
        <span class="tg">KPIs</span>
      </div>
      <div class="arrow">Abrir <span class="a">→</span></div>
    </a>

  </div>
</section>

<footer>
  <div class="left">
    Periodo:
    <?php if ($cfg['fecha_desde'] || $cfg['fecha_hasta']): ?>
      <?= htmlspecialchars(($cfg['fecha_desde'] ?: 'inicio'), ENT_QUOTES, 'UTF-8') ?>
      —
      <?= htmlspecialchars(($cfg['fecha_hasta'] ?: 'hoy'), ENT_QUOTES, 'UTF-8') ?>
    <?php else: ?>
      <?= ix_fecha_es($snap['primera_fecha']) ?> — <?= ix_fecha_es($snap['ultima_fecha']) ?>
    <?php endif; ?>
  </div>
  <div class="right">
    <span>Cache: <?= (int)($cfg['cache_segundos'] ?? 0) ?>s</span>
    <span>Zona: <?= htmlspecialchars($cfg['zona_horaria'] ?? 'America/Mexico_City', ENT_QUOTES, 'UTF-8') ?></span>
    <span>Generado: <?= date('d/m/Y H:i') ?></span>
  </div>
</footer>

<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
