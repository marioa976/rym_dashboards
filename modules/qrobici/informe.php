<?php
/**
 * QroBici Analytics — Informe ejecutivo
 * ------------------------------------------------------------
 * Presentación por slides (scroll-snap fullscreen) que se
 * actualiza al vuelo con datos reales del periodo configurado
 * y cierra con recomendaciones derivadas automáticamente.
 *
 * Reusa todo el pipeline existente vía lib_informe.php.
 *
 * Navegación:
 *   • ↓ / Espacio / PgDn → siguiente slide
 *   • ↑ / PgUp           → slide anterior
 *   • Home / End         → primera / última
 *   • Click en puntos    → salta a ese slide
 */

declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib_polyline.php';
require_once __DIR__ . '/lib_viajes.php';
require_once __DIR__ . '/lib_planes.php';
require_once __DIR__ . '/lib_informe.php';

$cfg = require __DIR__ . '/config.php';

// Periodo del informe: por default ÚLTIMOS 15 DÍAS (antes jalaba todo el histórico y tardaba).
// Override:  ?dias=N   o   ?desde=YYYY-MM-DD&hasta=YYYY-MM-DD
$dias = isset($_GET['dias']) ? max(1, min(365, (int)$_GET['dias'])) : 15;
if (!empty($_GET['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'])) {
    $cfg['fecha_desde'] = $_GET['desde'] . ' 00:00:00';
    $cfg['fecha_hasta'] = (!empty($_GET['hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta']))
        ? $_GET['hasta'] . ' 23:59:59'
        : date('Y-m-d 23:59:59');
} else {
    $cfg['fecha_hasta'] = date('Y-m-d H:i:s');
    $cfg['fecha_desde'] = date('Y-m-d 00:00:00', strtotime("-{$dias} days"));
}

if (!empty($cfg['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}
date_default_timezone_set($cfg['zona_horaria'] ?? 'America/Mexico_City');

try {
    qrb_db($cfg);
} catch (Throwable $e) {
    if (!empty($cfg['debug'])) { throw $e; }
    http_response_code(500);
    die('Error de conexión. Revisa config.php');
}

// cache opcional
$cache_file = sys_get_temp_dir() . '/qrobici_informe_' . md5($cfg['fecha_desde'] . '|' . $cfg['fecha_hasta']) . '.json';
$data = null;
$cache_seg = (int)($cfg['cache_segundos'] ?? 0);
if ($cache_seg > 0 && is_file($cache_file)
    && (time() - filemtime($cache_file)) < $cache_seg) {
    $data = json_decode(file_get_contents($cache_file), true);
}
if ($data === null) {
    $data = qrb_construye_dataset_informe($cfg);
    if ($cache_seg > 0) {
        @file_put_contents($cache_file, json_encode($data));
    }
}

// helpers de presentación
function inf_fmt(int $n): string { return number_format($n); }
function inf_fmt_f(float $n, int $dec = 1): string { return number_format($n, $dec); }
function inf_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function inf_delta_str(?float $d): string {
    if ($d === null) return '—';
    $sign = $d > 0 ? '+' : '';
    return $sign . inf_fmt_f($d, 1) . '%';
}
function inf_delta_class(?float $d): string {
    if ($d === null) return 'neutro';
    if ($d > 1)  return 'pos';
    if ($d < -1) return 'neg';
    return 'neutro';
}

$k        = $data['kpis']        ?? [];
$deltas   = $data['deltas']      ?? ['disponible' => false];
$horaP    = $data['hora_pico']   ?? [];
$diaP     = $data['dia_pico']    ?? [];
$estDesb  = $data['est_desb']    ?? [];
$estTop   = $data['est_top']     ?? [];
$kp       = $data['kpis_planes'] ?? [];
$recos    = $data['recos']       ?? [];

$total_recos    = count($recos);
$prioridad_alta = count(array_filter($recos, fn($r) => $r['prioridad'] === 'alta'));

?><?php
$ktTitle  = 'QroBici · Informe ejecutivo';
$ktActive = 'qrobici';
require __DIR__ . '/../../views/layout/kt_top.php';
?><link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800;900&family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap">
<style>
:root {
  --azul:#005ab2;
  --azul-d:#254185;
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
  --blanco:#ffffff;
}
* { box-sizing:border-box; margin:0; padding:0; }
html, body { height:100%; }
html {
  scroll-snap-type:y mandatory;
  scroll-behavior:smooth;
  overflow-y:scroll;
  overflow-x:hidden;
  background:var(--azul-ll);
}
body {
  font-family:'Montserrat', system-ui, sans-serif;
  color:var(--tinta);
  -webkit-font-smoothing:antialiased;
  text-rendering:optimizeLegibility;
}

/* ====== SLIDES ====== */
.slide {
  height:100vh;
  scroll-snap-align:start;
  scroll-snap-stop:always;
  display:flex;
  align-items:center;
  justify-content:center;
  position:relative;
  overflow:hidden;
  padding:64px 8vw;
}
.slide-inner {
  width:100%;
  max-width:1240px;
  position:relative;
  z-index:2;
}
.slide-num {
  position:absolute;
  top:34px; right:48px;
  font-family:'Space Mono', monospace;
  font-size:11px; letter-spacing:2px;
  color:var(--tinta-3);
  text-transform:uppercase;
}
.slide-tag {
  display:inline-block;
  font-family:'Space Mono', monospace;
  font-size:11px; letter-spacing:2.5px;
  color:var(--azul);
  text-transform:uppercase;
  margin-bottom:18px;
  padding:5px 12px;
  background:var(--azul-l);
  border-radius:999px;
}
.slide h1 {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:clamp(40px, 5vw, 76px);
  line-height:1.05;
  letter-spacing:-1.2px;
  color:var(--tinta);
}
.slide h2 {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:clamp(32px, 3.8vw, 56px);
  line-height:1.08;
  letter-spacing:-.8px;
  color:var(--tinta);
}
.slide h3 {
  font-family:'Archivo', sans-serif; font-weight:800;
  font-size:clamp(20px, 1.7vw, 26px);
  letter-spacing:-.2px;
  color:var(--tinta);
}
.slide p {
  font-size:clamp(15px, 1.1vw, 17px);
  line-height:1.65; color:var(--tinta-2);
  max-width:60ch;
}
.lead {
  font-size:clamp(18px, 1.4vw, 22px) !important;
  color:var(--tinta-2) !important;
  max-width:62ch;
  margin-top:18px;
}

/* Fondo decorativo de cada slide */
.bg-deco {
  position:absolute; inset:0;
  pointer-events:none; z-index:1;
}
.bg-deco::before {
  content:'';
  position:absolute;
  width:780px; height:780px;
  border-radius:50%;
  background:radial-gradient(circle, rgba(0,87,255,.07) 0%, transparent 60%);
  top:-200px; right:-220px;
}
.bg-deco::after {
  content:'';
  position:absolute;
  width:580px; height:580px;
  border-radius:50%;
  background:radial-gradient(circle, rgba(0,184,124,.05) 0%, transparent 60%);
  bottom:-180px; left:-160px;
}

/* ====== SLIDE 1 — PORTADA ====== */
.cover {
  background:linear-gradient(135deg, #ffffff 0%, var(--azul-l) 100%);
}
.cover .logo {
  width:72px; height:72px;
  border-radius:18px;
  background:linear-gradient(135deg, var(--azul), var(--azul-d));
  display:flex; align-items:center; justify-content:center;
  font-family:'Archivo', sans-serif; font-weight:900; color:#fff;
  font-size:36px; letter-spacing:-1.5px;
  box-shadow:0 18px 50px rgba(0,87,255,.25);
  margin-bottom:36px;
}
.cover .ttl {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:clamp(56px, 8vw, 120px);
  line-height:.96;
  letter-spacing:-3px;
  background:linear-gradient(135deg, var(--tinta) 0%, var(--azul) 100%);
  -webkit-background-clip:text; background-clip:text; color:transparent;
}
.cover .sub {
  font-family:'Archivo', sans-serif; font-weight:800;
  font-size:clamp(22px, 2.4vw, 36px);
  color:var(--tinta-2); margin-top:18px;
  max-width:18ch;
}
.cover .meta {
  display:flex; gap:48px;
  margin-top:60px;
  flex-wrap:wrap;
}
.cover .meta div .lbl {
  font-family:'Space Mono', monospace; font-size:11px;
  letter-spacing:2.5px; text-transform:uppercase;
  color:var(--tinta-3); margin-bottom:8px;
}
.cover .meta div .val {
  font-family:'Space Mono', monospace; font-size:16px;
  color:var(--tinta); font-weight:700;
}
.scroll-hint {
  position:absolute;
  bottom:36px; left:50%; transform:translateX(-50%);
  font-family:'Space Mono', monospace; font-size:11px;
  letter-spacing:2px; color:var(--tinta-3); text-transform:uppercase;
  display:flex; flex-direction:column; align-items:center; gap:10px;
  animation:bob 2s ease-in-out infinite;
}
.scroll-hint .arrow { font-size:18px; color:var(--azul); }
@keyframes bob { 0%,100%{transform:translate(-50%,0);} 50%{transform:translate(-50%,8px);} }

/* ====== BIG STAT ====== */
.big-stat {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:clamp(80px, 12vw, 200px);
  line-height:.92;
  letter-spacing:-5px;
  color:var(--azul);
  font-variant-numeric:tabular-nums;
}
.big-stat .unit {
  font-size:.45em; color:var(--tinta-3); letter-spacing:-2px;
  margin-left:8px;
}
.big-row {
  display:flex; align-items:baseline;
  gap:28px;
  margin-top:14px;
  flex-wrap:wrap;
}
.delta-pill {
  display:inline-flex; align-items:center; gap:6px;
  font-family:'Space Mono', monospace; font-size:14px; font-weight:700;
  padding:7px 14px; border-radius:999px;
  letter-spacing:.5px;
}
.delta-pill.pos { background:rgba(0,184,124,.15); color:var(--verde); }
.delta-pill.neg { background:rgba(255,77,94,.13); color:var(--rojo); }
.delta-pill.neutro { background:var(--gris); color:var(--tinta-3); }

/* ====== LAYOUTS ====== */
.split {
  display:grid;
  grid-template-columns: 1.1fr 1fr;
  gap:56px;
  align-items:center;
}
.split-3 {
  display:grid;
  grid-template-columns: repeat(3, 1fr);
  gap:32px;
  margin-top:40px;
}
.split-4 {
  display:grid;
  grid-template-columns: repeat(4, 1fr);
  gap:24px;
  margin-top:40px;
}
.stat-card {
  background:#fff;
  border-radius:18px;
  padding:24px 24px 22px;
  border:1px solid var(--gris);
  box-shadow:0 10px 30px rgba(10,27,61,.04);
  position:relative;
  overflow:hidden;
}
.stat-card .lbl {
  font-family:'Space Mono', monospace;
  font-size:10px; letter-spacing:2px;
  text-transform:uppercase;
  color:var(--tinta-3);
}
.stat-card .val {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:38px; line-height:1;
  margin-top:8px;
  color:var(--tinta);
  font-variant-numeric:tabular-nums;
}
.stat-card .val .unit {
  font-size:14px; color:var(--tinta-3);
  font-family:'Space Mono', monospace; font-weight:400;
  margin-left:6px;
}
.stat-card .ctx {
  font-size:12px; color:var(--tinta-3);
  margin-top:10px;
  font-family:'Space Mono', monospace;
}
.stat-card.accent-blue { border-left:4px solid var(--azul); }
.stat-card.accent-green { border-left:4px solid var(--verde); }
.stat-card.accent-rosa { border-left:4px solid var(--rosa); }
.stat-card.accent-amber { border-left:4px solid var(--ambar); }

/* ====== BARRA HORIZONTAL ====== */
.hbar-list { margin-top:18px; }
.hbar-row {
  display:flex; align-items:center;
  gap:14px; margin-bottom:10px;
}
.hbar-row .nm {
  flex:0 0 130px;
  font-family:'Space Mono', monospace;
  font-size:12px; color:var(--tinta-2);
  text-align:right;
}
.hbar-row .br {
  flex:1; height:14px;
  background:var(--gris-2);
  border-radius:7px;
  overflow:hidden;
}
.hbar-row .br > div {
  height:100%; width:0;
  background:linear-gradient(90deg, var(--azul), var(--azul-d));
  border-radius:7px;
  transition:width 1.2s cubic-bezier(.16,.84,.42,1.05);
}
.hbar-row.is-in .br > div { width:var(--w); }
.hbar-row .vl {
  flex:0 0 50px;
  font-family:'Space Mono', monospace;
  font-size:12px; color:var(--tinta); font-weight:700;
  text-align:right;
}

/* ====== SLIDE 4: HORA PICO con curva SVG ====== */
.curve-wrap { width:100%; margin-top:22px; }
.curve-wrap svg { width:100%; height:auto; }
.curve-fill { fill:url(#gradAzul); opacity:.35; }
.curve-line { fill:none; stroke:var(--azul); stroke-width:2.5; }
.curve-dot {
  fill:#fff; stroke:var(--azul); stroke-width:3;
}
.curve-dot.peak {
  fill:var(--azul); stroke:#fff; r:8;
  filter:drop-shadow(0 4px 14px rgba(0,87,255,.5));
}

/* ====== DONUT ====== */
.donut {
  display:flex; align-items:center; gap:30px;
}
.donut svg { width:200px; height:200px; flex:0 0 200px; }
.donut .legends { display:flex; flex-direction:column; gap:12px; }
.donut .lg-row {
  display:flex; align-items:center; gap:10px;
  font-family:'Space Mono', monospace;
  font-size:12px; letter-spacing:.5px;
}
.donut .lg-row b {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:22px; color:var(--tinta);
}
.donut .lg-dot {
  width:12px; height:12px; border-radius:3px;
}

/* ====== RECOMENDACIONES ====== */
.recos-grid {
  display:grid;
  grid-template-columns: repeat(2, 1fr);
  gap:18px;
  margin-top:32px;
}
.reco {
  background:#fff;
  border-radius:18px;
  padding:24px 26px;
  border:1px solid var(--gris);
  box-shadow:0 10px 30px rgba(10,27,61,.04);
  position:relative;
  overflow:hidden;
}
.reco::before {
  content:''; position:absolute; left:0; top:0;
  width:5px; height:100%;
}
.reco.alta::before { background:var(--rojo); }
.reco.media::before { background:var(--ambar); }
.reco.baja::before { background:var(--verde); }
.reco-head {
  display:flex; justify-content:space-between; align-items:flex-start;
  gap:12px; margin-bottom:12px;
}
.reco-prio {
  font-family:'Space Mono', monospace;
  font-size:10px; letter-spacing:2px;
  text-transform:uppercase; font-weight:700;
  padding:5px 10px; border-radius:6px;
  flex-shrink:0;
}
.reco-prio.alta  { color:var(--rojo); background:rgba(255,77,94,.1); }
.reco-prio.media { color:var(--ambar); background:rgba(255,149,0,.1); }
.reco-prio.baja  { color:var(--verde); background:rgba(0,184,124,.1); }
.reco-titulo {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:18px; line-height:1.25; color:var(--tinta);
}
.reco-accion {
  font-size:14px; line-height:1.55; color:var(--tinta-2);
  margin-top:6px;
}
.reco-metrica {
  font-family:'Space Mono', monospace;
  font-size:11px; letter-spacing:.5px;
  color:var(--azul);
  margin-top:12px; padding-top:12px;
  border-top:1px solid var(--gris);
}

/* ====== CIERRE ====== */
.outro {
  background:linear-gradient(135deg, var(--tinta) 0%, var(--azul-d) 100%);
  color:#fff;
}
.outro .ttl {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:clamp(40px, 6vw, 84px);
  line-height:1.02; letter-spacing:-2px;
  max-width:18ch;
}
.outro .ttl span { color:#7fb1ff; }
.outro p { color:rgba(255,255,255,.78); }
.outro .cta-row { display:flex; gap:14px; margin-top:48px; flex-wrap:wrap; }
.outro .cta {
  display:inline-flex; align-items:center; gap:8px;
  padding:14px 22px;
  border-radius:12px;
  font-family:'Space Mono', monospace;
  font-size:12px; font-weight:700; letter-spacing:1px;
  text-transform:uppercase; text-decoration:none;
  transition:all .2s ease;
}
.outro .cta.primary {
  background:#fff; color:var(--tinta);
}
.outro .cta.primary:hover {
  background:#7fb1ff; color:#fff;
  transform:translateY(-2px);
  box-shadow:0 18px 40px rgba(127,177,255,.4);
}
.outro .cta.ghost {
  border:1px solid rgba(255,255,255,.3); color:#fff;
}
.outro .cta.ghost:hover {
  border-color:#fff;
  background:rgba(255,255,255,.08);
}
.outro .sig {
  margin-top:80px;
  font-family:'Space Mono', monospace;
  font-size:11px; color:rgba(255,255,255,.5);
  letter-spacing:2px; text-transform:uppercase;
}

/* ====== NAV (puntos + flechas) ====== */
.nav-dots {
  position:fixed; right:28px; top:50%;
  transform:translateY(-50%);
  display:flex; flex-direction:column; gap:10px;
  z-index:20;
}
.nav-dots .dot {
  width:8px; height:8px; border-radius:50%;
  background:var(--tinta-3);
  opacity:.35;
  cursor:pointer;
  transition:all .2s ease;
  border:none; padding:0;
}
.nav-dots .dot:hover { opacity:.7; transform:scale(1.3); }
.nav-dots .dot.active {
  opacity:1;
  background:var(--azul);
  transform:scale(1.5);
  box-shadow:0 0 0 4px rgba(0,87,255,.18);
}
.nav-arrows {
  position:fixed; right:28px; bottom:28px;
  display:flex; gap:8px;
  z-index:20;
}
.nav-arrows button {
  width:42px; height:42px; border-radius:12px;
  background:#fff; border:1px solid var(--gris);
  color:var(--tinta);
  cursor:pointer;
  font-size:18px; font-weight:700;
  box-shadow:0 10px 30px rgba(10,27,61,.08);
  transition:all .15s ease;
  display:flex; align-items:center; justify-content:center;
}
.nav-arrows button:hover {
  background:var(--azul); color:#fff;
  transform:translateY(-2px);
  box-shadow:0 14px 30px rgba(0,87,255,.3);
}
.nav-arrows button:disabled {
  opacity:.35; cursor:not-allowed;
  transform:none !important;
  background:#fff !important; color:var(--tinta) !important;
}
.progress-bar {
  position:fixed; top:0; left:0;
  height:3px; background:var(--azul);
  z-index:30;
  transition:width .3s ease;
}

/* ====== REVEAL ON SLIDE-IN ====== */
.reveal { opacity:0; transform:translateY(28px); transition:opacity .8s ease, transform .8s cubic-bezier(.16,.84,.42,1.05); }
.slide.is-active .reveal { opacity:1; transform:translateY(0); }
.slide.is-active .reveal:nth-of-type(2) { transition-delay:.12s; }
.slide.is-active .reveal:nth-of-type(3) { transition-delay:.22s; }
.slide.is-active .reveal:nth-of-type(4) { transition-delay:.32s; }
.slide.is-active .reveal:nth-of-type(5) { transition-delay:.42s; }
.slide.is-active .reveal:nth-of-type(6) { transition-delay:.52s; }
.slide.is-active .reveal:nth-of-type(7) { transition-delay:.62s; }

/* ====== MOBILE ====== */
@media (max-width:780px) {
  .slide { padding:64px 22px; }
  .split { grid-template-columns:1fr; gap:32px; }
  .split-3, .split-4 { grid-template-columns:1fr 1fr; gap:14px; }
  .recos-grid { grid-template-columns:1fr; }
  .donut { flex-direction:column; align-items:flex-start; gap:18px; }
  .donut svg { width:170px; height:170px; flex:0 0 170px; }
  .stat-card .val { font-size:30px; }
  .big-stat { font-size:80px; letter-spacing:-3px; }
  .nav-dots { display:none; }
  .nav-arrows { right:14px; bottom:14px; }
  .slide-num { right:18px; top:18px; }
}

/* ====== EMPTY STATE ====== */
.empty-state {
  background:#fff;
  padding:64px;
  border-radius:24px;
  border:1px solid var(--gris);
  text-align:center;
  max-width:480px;
}
</style>

<?php if (!empty($data['vacio'])): ?>
  <div class="slide cover">
    <div class="slide-inner empty-state">
      <h1>Sin datos suficientes</h1>
      <p class="lead"><?= inf_h($data['mensaje'] ?? 'No fue posible generar el informe.') ?></p>
    </div>
  </div>
<?php else: ?>

<div class="progress-bar" id="progress"></div>

<!-- =========================================================
     SLIDE 1 · PORTADA
========================================================= -->
<section class="slide cover" data-i="1">
  <div class="bg-deco"></div>
  <div class="slide-inner">
    <div class="logo reveal">Q</div>
    <div class="ttl reveal">QroBici<br>Informe<br>ejecutivo.</div>
    <div class="sub reveal">El pulso del sistema de bici pública de Querétaro.</div>
    <div class="meta reveal">
      <div>
        <div class="lbl">Periodo analizado</div>
        <div class="val"><?= inf_h(($k['fecha_min'] ?? '—') . ' — ' . ($k['fecha_max'] ?? '—')) ?></div>
      </div>
      <div>
        <div class="lbl">Días de operación</div>
        <div class="val"><?= inf_fmt((int)($k['dias_operacion'] ?? 0)) ?></div>
      </div>
      <div>
        <div class="lbl">Generado</div>
        <div class="val"><?= date('d/m/Y H:i') ?></div>
      </div>
    </div>
  </div>
  <div class="scroll-hint">Desliza<span class="arrow">↓</span></div>
</section>

<!-- =========================================================
     SLIDE 2 · EL PULSO (viajes totales + delta)
========================================================= -->
<section class="slide" data-i="2">
  <div class="bg-deco"></div>
  <div class="slide-num">02 / 10</div>
  <div class="slide-inner">
    <div class="slide-tag reveal">01 — el pulso</div>
    <h2 class="reveal">Querétaro pedaleó.</h2>
    <div class="big-row reveal" style="margin-top:24px">
      <div class="big-stat" data-count="<?= (int)($k['total_viajes'] ?? 0) ?>"><?= inf_fmt((int)($k['total_viajes'] ?? 0)) ?></div>
      <div>
        <div style="font-family:'Space Mono',monospace;font-size:13px;letter-spacing:2px;text-transform:uppercase;color:var(--tinta-3)">viajes totales</div>
        <?php if (!empty($deltas['disponible']) && $deltas['d_viajes'] !== null): ?>
          <span class="delta-pill <?= inf_delta_class($deltas['d_viajes']) ?>" style="margin-top:10px;display:inline-flex">
            <?= inf_delta_str($deltas['d_viajes']) ?> vs. periodo anterior
          </span>
        <?php endif; ?>
      </div>
    </div>
    <p class="lead reveal">
      <?= inf_fmt((int)($k['usuarios_unicos'] ?? 0)) ?> usuarios únicos recorrieron
      <b><?= inf_fmt_f((float)($k['dist_total_km'] ?? 0)) ?> km</b> a lo largo de
      <?= inf_fmt_f((float)($k['dur_total_horas'] ?? 0)) ?> horas pedaleando en bicicleta pública.
    </p>

    <div class="split-4 reveal">
      <div class="stat-card accent-blue">
        <div class="lbl">Usuarios únicos</div>
        <div class="val"><?= inf_fmt((int)($k['usuarios_unicos'] ?? 0)) ?></div>
        <?php if (!empty($deltas['disponible']) && $deltas['d_usuarios'] !== null): ?>
          <div class="ctx <?= inf_delta_class($deltas['d_usuarios']) ?>"><?= inf_delta_str($deltas['d_usuarios']) ?> vs. anterior</div>
        <?php endif; ?>
      </div>
      <div class="stat-card accent-green">
        <div class="lbl">Distancia total</div>
        <div class="val"><?= inf_fmt_f((float)($k['dist_total_km'] ?? 0)) ?><span class="unit">km</span></div>
        <?php if (!empty($deltas['disponible']) && $deltas['d_km'] !== null): ?>
          <div class="ctx"><?= inf_delta_str($deltas['d_km']) ?> vs. anterior</div>
        <?php endif; ?>
      </div>
      <div class="stat-card accent-rosa">
        <div class="lbl">Duración promedio</div>
        <div class="val"><?= inf_fmt_f((float)($k['dur_prom_min'] ?? 0)) ?><span class="unit">min</span></div>
        <div class="ctx">por viaje</div>
      </div>
      <div class="stat-card accent-amber">
        <div class="lbl">Velocidad mediana</div>
        <div class="val"><?= inf_fmt_f((float)($k['vel_mediana'] ?? 0)) ?><span class="unit">km/h</span></div>
        <div class="ctx">en ruta efectiva</div>
      </div>
    </div>
  </div>
</section>

<!-- =========================================================
     SLIDE 3 · LA GENTE
========================================================= -->
<section class="slide" data-i="3">
  <div class="bg-deco"></div>
  <div class="slide-num">03 / 10</div>
  <div class="slide-inner">
    <div class="slide-tag reveal">02 — la gente</div>
    <h2 class="reveal">Quién pedalea.</h2>
    <p class="lead reveal">
      La edad promedio de quien usa el sistema es de
      <b><?= inf_fmt_f((float)($k['edad_prom'] ?? 0), 1) ?> años</b>.
      <?php
        $sx = $data['sexo_dist'] ?? [];
        $tot_sx = ($sx['Hombres'] ?? 0) + ($sx['Mujeres'] ?? 0);
        if ($tot_sx > 0) {
            $pH = round(100 * ($sx['Hombres'] ?? 0) / $tot_sx);
            $pM = 100 - $pH;
            echo "Por cada 10 viajes, <b>{$pH} son de hombres</b> y <b>{$pM} de mujeres</b>.";
        }
      ?>
    </p>
    <div class="split reveal" style="margin-top:36px">
      <div>
        <h3 style="margin-bottom:14px">Distribución por edad</h3>
        <div class="hbar-list" id="edadList">
          <?php
            $maxE = 1;
            foreach (($data['edad_dist'] ?? []) as $e) { if ($e['cantidad'] > $maxE) { $maxE = $e['cantidad']; } }
            foreach (($data['edad_dist'] ?? []) as $e):
              $w = round(100 * $e['cantidad'] / $maxE, 1);
          ?>
            <div class="hbar-row" data-w="<?= $w ?>">
              <div class="nm"><?= inf_h($e['rango']) ?></div>
              <div class="br"><div style="--w:<?= $w ?>%"></div></div>
              <div class="vl"><?= inf_fmt((int)$e['cantidad']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div>
        <h3 style="margin-bottom:14px">Mezcla por sexo</h3>
        <?php $sx = $data['sexo_dist'] ?? []; $tot = max(1, array_sum($sx)); ?>
        <div class="donut">
          <?php
            $pH = ($sx['Hombres'] ?? 0) / $tot;
            $pM = ($sx['Mujeres'] ?? 0) / $tot;
            $pS = ($sx['Sin dato'] ?? 0) / $tot;
            $c  = 2 * M_PI * 70;
            $oH = 0;
            $oM = $pH * $c;
            $oS = ($pH + $pM) * $c;
          ?>
          <svg viewBox="0 0 200 200">
            <circle cx="100" cy="100" r="70" fill="none" stroke="#e6ecf5" stroke-width="22"/>
            <circle cx="100" cy="100" r="70" fill="none" stroke="#254185" stroke-width="22"
                    stroke-dasharray="<?= $pH * $c ?> <?= $c ?>" stroke-dashoffset="0"
                    transform="rotate(-90 100 100)" stroke-linecap="butt"/>
            <circle cx="100" cy="100" r="70" fill="none" stroke="#5b667a" stroke-width="22"
                    stroke-dasharray="<?= $pM * $c ?> <?= $c ?>" stroke-dashoffset="<?= -$oM ?>"
                    transform="rotate(-90 100 100)" stroke-linecap="butt"/>
            <circle cx="100" cy="100" r="70" fill="none" stroke="#d9e2f0" stroke-width="22"
                    stroke-dasharray="<?= $pS * $c ?> <?= $c ?>" stroke-dashoffset="<?= -$oS ?>"
                    transform="rotate(-90 100 100)" stroke-linecap="butt"/>
          </svg>
          <div class="legends">
            <div class="lg-row"><span class="lg-dot" style="background:#254185"></span> Hombres &nbsp; <b><?= round($pH*100) ?>%</b></div>
            <div class="lg-row"><span class="lg-dot" style="background:#5b667a"></span> Mujeres &nbsp; <b><?= round($pM*100) ?>%</b></div>
            <?php if (($sx['Sin dato'] ?? 0) > 0): ?>
              <div class="lg-row"><span class="lg-dot" style="background:#d9e2f0"></span> Sin dato &nbsp; <b><?= round($pS*100) ?>%</b></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- =========================================================
     SLIDE 4 · CUÁNDO SE MUEVE LA CIUDAD
========================================================= -->
<section class="slide" data-i="4">
  <div class="bg-deco"></div>
  <div class="slide-num">04 / 10</div>
  <div class="slide-inner">
    <div class="slide-tag reveal">03 — los momentos</div>
    <h2 class="reveal">La ciudad se mueve a las <span style="color:var(--azul)"><?= sprintf('%02d:00', (int)($horaP['hora'] ?? 0)) ?></span>.</h2>
    <p class="lead reveal">
      <?php if (!empty($horaP['hora'])): ?>
        Es la hora con mayor número de salidas: <b><?= inf_fmt((int)$horaP['viajes']) ?> viajes</b>
        que concentran el <b><?= inf_fmt_f((float)$horaP['pct'], 1) ?>%</b> del total.
        <?php if (!empty($diaP['dia'])): ?>
          El día más activo de la semana es <b><?= inf_h($diaP['dia']) ?></b> con <?= inf_fmt_f((float)$diaP['pct'], 1) ?>% de los viajes.
        <?php endif; ?>
      <?php endif; ?>
    </p>

    <div class="curve-wrap reveal">
      <?php
        // construye curva SVG con la serie horaria
        $serie = $data['serie_hora'] ?? [];
        $maxV = 1;
        foreach ($serie as $r) { if ($r['viajes'] > $maxV) { $maxV = $r['viajes']; } }
        $W = 1180; $H = 220; $P = 16;
        $nPts = count($serie);
        $pts_x_y = [];
        foreach ($serie as $i => $r) {
            $x = $P + ($i * ($W - 2*$P) / max(1, $nPts - 1));
            $y = $H - $P - (($r['viajes'] / $maxV) * ($H - 2*$P - 14));
            $pts_x_y[] = [$x, $y, $r['hora'], $r['viajes']];
        }
        $line_d = '';
        foreach ($pts_x_y as $i => $p) {
            $line_d .= ($i === 0 ? 'M ' : 'L ') . round($p[0], 1) . ' ' . round($p[1], 1) . ' ';
        }
        $fill_d = $line_d . 'L ' . round($pts_x_y[$nPts-1][0], 1) . ' ' . ($H - $P) . ' L ' . round($pts_x_y[0][0], 1) . ' ' . ($H - $P) . ' Z';
      ?>
      <svg viewBox="0 0 <?= $W ?> <?= $H + 30 ?>" preserveAspectRatio="none">
        <defs>
          <linearGradient id="gradAzul" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#254185" stop-opacity=".5"/>
            <stop offset="100%" stop-color="#254185" stop-opacity="0"/>
          </linearGradient>
        </defs>
        <path d="<?= $fill_d ?>" class="curve-fill"/>
        <path d="<?= $line_d ?>" class="curve-line"/>
        <?php foreach ($pts_x_y as $p):
            $is_peak = ($p[2] == ($horaP['hora'] ?? -1));
        ?>
          <circle cx="<?= round($p[0], 1) ?>" cy="<?= round($p[1], 1) ?>" r="<?= $is_peak ? 8 : 4 ?>"
                  class="curve-dot<?= $is_peak ? ' peak' : '' ?>"/>
          <text x="<?= round($p[0], 1) ?>" y="<?= $H + 18 ?>" text-anchor="middle"
                style="font-family:'Space Mono',monospace;font-size:10px;fill:#7287ac"><?= $p[2] ?></text>
        <?php endforeach; ?>
      </svg>
    </div>

    <div class="split-3 reveal">
      <div class="stat-card accent-blue">
        <div class="lbl">Hora pico</div>
        <div class="val"><?= sprintf('%02d', (int)($horaP['hora'] ?? 0)) ?><span class="unit">:00</span></div>
        <div class="ctx"><?= inf_fmt_f((float)($horaP['pct'] ?? 0), 1) ?>% del total</div>
      </div>
      <div class="stat-card accent-amber">
        <div class="lbl">Día más activo</div>
        <div class="val"><?= inf_h($diaP['dia'] ?? '—') ?></div>
        <div class="ctx"><?= inf_fmt_f((float)($diaP['pct'] ?? 0), 1) ?>% del total</div>
      </div>
      <div class="stat-card accent-green">
        <div class="lbl">Estaciones activas</div>
        <div class="val"><?= inf_fmt((int)($k['estaciones_activas'] ?? 0)) ?></div>
        <div class="ctx">con al menos 1 viaje</div>
      </div>
    </div>
  </div>
</section>

<!-- =========================================================
     SLIDE 5 · ESTACIONES CLAVE
========================================================= -->
<section class="slide" data-i="5">
  <div class="bg-deco"></div>
  <div class="slide-num">05 / 10</div>
  <div class="slide-inner">
    <div class="slide-tag reveal">04 — los puntos calientes</div>
    <h2 class="reveal">Las estaciones que mueven todo.</h2>
    <div class="split reveal" style="margin-top:30px">
      <div>
        <h3 style="margin-bottom:14px">Top 10 por volumen total</h3>
        <div class="hbar-list">
          <?php
            $top = array_slice($data['estaciones'] ?? [], 0, 10);
            $maxE = $top[0]['total'] ?? 1;
            foreach ($top as $e):
              $w = $maxE > 0 ? round(100 * $e['total'] / $maxE, 1) : 0;
          ?>
            <div class="hbar-row">
              <div class="nm" title="<?= inf_h($e['nombre']) ?>"><?= inf_h(mb_strimwidth($e['nombre'], 0, 22, '…', 'UTF-8')) ?></div>
              <div class="br"><div style="--w:<?= $w ?>%"></div></div>
              <div class="vl"><?= inf_fmt((int)$e['total']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div>
        <div class="stat-card accent-blue" style="margin-bottom:14px">
          <div class="lbl">Estación más usada</div>
          <div class="val" style="font-size:24px;line-height:1.2"><?= inf_h($estTop['nombre'] ?? '—') ?></div>
          <div class="ctx"><?= inf_fmt((int)($estTop['total'] ?? 0)) ?> viajes (entradas + salidas)</div>
        </div>
        <?php if (!empty($estDesb['nombre'])): ?>
          <div class="stat-card accent-amber">
            <div class="lbl">Mayor desbalance neto</div>
            <div class="val" style="font-size:24px;line-height:1.2"><?= inf_h($estDesb['nombre']) ?></div>
            <div class="ctx">
              <?= $estDesb['balance'] > 0 ? '+' : '' ?><?= inf_fmt((int)$estDesb['balance']) ?>
              bicis netas — <?= $estDesb['sentido'] ?>
              (<?= inf_fmt_f((float)$estDesb['pct_desb'], 1) ?>% de su volumen)
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- =========================================================
     SLIDE 6 · FLOTA
========================================================= -->
<section class="slide" data-i="6">
  <div class="bg-deco"></div>
  <div class="slide-num">06 / 10</div>
  <div class="slide-inner">
    <div class="slide-tag reveal">05 — la flota</div>
    <h2 class="reveal">Mecánica vs eléctrica.</h2>
    <p class="lead reveal">
      Una mezcla saludable de flota mantiene cubiertas distintas necesidades:
      la mecánica para trayectos cortos y recurrentes, la eléctrica para distancias mayores y subidas.
    </p>
    <div class="split reveal" style="margin-top:30px">
      <div>
        <?php
          $pM = $k['pct_mecanica'] ?? 0;
          $pE = $k['pct_electrica'] ?? 0;
          $resto = 100 - $pM - $pE;
          $c  = 2 * M_PI * 70;
        ?>
        <div class="donut">
          <svg viewBox="0 0 200 200">
            <circle cx="100" cy="100" r="70" fill="none" stroke="#e6ecf5" stroke-width="22"/>
            <circle cx="100" cy="100" r="70" fill="none" stroke="#254185" stroke-width="22"
                    stroke-dasharray="<?= ($pM/100)*$c ?> <?= $c ?>"
                    transform="rotate(-90 100 100)" stroke-linecap="butt"/>
            <circle cx="100" cy="100" r="70" fill="none" stroke="#5b667a" stroke-width="22"
                    stroke-dasharray="<?= ($pE/100)*$c ?> <?= $c ?>"
                    stroke-dashoffset="<?= -($pM/100)*$c ?>"
                    transform="rotate(-90 100 100)" stroke-linecap="butt"/>
          </svg>
          <div class="legends">
            <div class="lg-row"><span class="lg-dot" style="background:#254185"></span> Mecánica &nbsp; <b><?= inf_fmt_f((float)$pM, 1) ?>%</b></div>
            <div class="lg-row"><span class="lg-dot" style="background:#5b667a"></span> Eléctrica &nbsp; <b><?= inf_fmt_f((float)$pE, 1) ?>%</b></div>
            <?php if ($resto > 0.5): ?>
              <div class="lg-row"><span class="lg-dot" style="background:#d9e2f0"></span> Sin tipo &nbsp; <b><?= inf_fmt_f((float)$resto, 1) ?>%</b></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div>
        <div class="split-3" style="margin-top:0">
          <div class="stat-card">
            <div class="lbl">Duración prom</div>
            <div class="val"><?= inf_fmt_f((float)($k['dur_prom_min'] ?? 0), 1) ?><span class="unit">min</span></div>
          </div>
          <div class="stat-card">
            <div class="lbl">Distancia prom</div>
            <div class="val"><?= inf_fmt_f(((float)($k['dist_prom_m'] ?? 0)) / 1000, 2) ?><span class="unit">km</span></div>
          </div>
          <div class="stat-card">
            <div class="lbl">Vel. promedio</div>
            <div class="val"><?= inf_fmt_f((float)($k['vel_prom'] ?? 0), 1) ?><span class="unit">km/h</span></div>
          </div>
        </div>
        <div class="stat-card accent-green" style="margin-top:14px">
          <div class="lbl">Viajes circulares (vuelven a su estación)</div>
          <div class="val"><?= inf_fmt((int)($k['viajes_circulares'] ?? 0)) ?></div>
          <div class="ctx">
            <?= $k['total_viajes'] > 0
                ? inf_fmt_f(100 * (float)$k['viajes_circulares'] / (float)$k['total_viajes'], 1) : 0 ?>%
            del total — uso recreativo o de descanso
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- =========================================================
     SLIDE 7 · SUSCRIPCIONES
========================================================= -->
<section class="slide" data-i="7">
  <div class="bg-deco"></div>
  <div class="slide-num">07 / 10</div>
  <div class="slide-inner">
    <div class="slide-tag reveal">06 — suscripciones</div>
    <h2 class="reveal">El motor financiero.</h2>
    <?php if (!empty($data['planes_vacio']) || empty($kp) || ($kp['total_planes'] ?? 0) === 0): ?>
      <p class="lead reveal">No hay datos de suscripciones en el periodo configurado.</p>
    <?php else: ?>
      <p class="lead reveal">
        El sistema registra <b><?= inf_fmt((int)$kp['total_planes']) ?> planes activos en el periodo</b>,
        con una tasa de pago del <b><?= inf_fmt_f((float)$kp['tasa_pago'], 1) ?>%</b>
        y <b><?= inf_fmt((int)$kp['planes_vigentes']) ?> vigentes</b> al día de hoy.
      </p>
      <div class="split-4 reveal">
        <div class="stat-card accent-blue">
          <div class="lbl">Planes registrados</div>
          <div class="val"><?= inf_fmt((int)$kp['total_planes']) ?></div>
          <div class="ctx"><?= inf_fmt((int)$kp['usuarios_con_plan']) ?> usuarios distintos</div>
        </div>
        <div class="stat-card accent-green">
          <div class="lbl">Vigentes</div>
          <div class="val"><?= inf_fmt((int)$kp['planes_vigentes']) ?></div>
          <div class="ctx"><?= inf_fmt_f((float)$kp['tasa_vigencia'], 1) ?>% del registrado</div>
        </div>
        <div class="stat-card accent-amber">
          <div class="lbl">Tasa de pago</div>
          <div class="val"><?= inf_fmt_f((float)$kp['tasa_pago'], 1) ?><span class="unit">%</span></div>
          <div class="ctx"><?= inf_fmt((int)$kp['planes_pagados']) ?> planes pagados</div>
        </div>
        <div class="stat-card accent-rosa">
          <div class="lbl">Renovación</div>
          <div class="val"><?= inf_fmt_f((float)$kp['pct_renovacion'], 1) ?><span class="unit">%</span></div>
          <div class="ctx"><?= inf_fmt((int)$kp['renovaciones']) ?> usuarios con 2+ planes</div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- =========================================================
     SLIDE 8 · IMPACTO
========================================================= -->
<section class="slide" data-i="8">
  <div class="bg-deco"></div>
  <div class="slide-num">08 / 10</div>
  <div class="slide-inner">
    <div class="slide-tag reveal">07 — el impacto</div>
    <h2 class="reveal">Lo que le devolvimos a la ciudad.</h2>
    <?php
      $km    = (float)($k['dist_total_km'] ?? 0);
      $co2   = (float)($k['co2_kg'] ?? 0);
      $kcal  = (int)($k['calorias_total'] ?? 0);
      // equivalencias estimadas
      $arboles  = (int)round($co2 / 21);     // ~21 kg CO2 / año por árbol urbano
      $autos    = (int)round($km / 120);     // 120 km recorrido típico diario auto
      $hamburgs = (int)round($kcal / 250);   // 1 hamburguesa ≈ 250 kcal
    ?>
    <p class="lead reveal">
      <?= inf_fmt_f($km) ?> km en bicicleta significaron
      <b><?= inf_fmt_f($co2) ?> kg de CO₂ evitados</b> y
      <b><?= inf_fmt($kcal) ?> calorías quemadas</b> por los usuarios.
    </p>
    <div class="split-3 reveal" style="margin-top:30px">
      <div class="stat-card accent-green">
        <div class="lbl">CO₂ evitado</div>
        <div class="val"><?= inf_fmt_f($co2) ?><span class="unit">kg</span></div>
        <div class="ctx">≈ <?= inf_fmt($arboles) ?> árboles de captura anual</div>
      </div>
      <div class="stat-card accent-blue">
        <div class="lbl">Autos retirados (eq.)</div>
        <div class="val"><?= inf_fmt($autos) ?></div>
        <div class="ctx">días-auto reemplazados</div>
      </div>
      <div class="stat-card accent-rosa">
        <div class="lbl">Calorías quemadas</div>
        <div class="val"><?= inf_fmt($kcal) ?></div>
        <div class="ctx">≈ <?= inf_fmt($hamburgs) ?> hamburguesas</div>
      </div>
    </div>
  </div>
</section>

<!-- =========================================================
     SLIDE 9 · RECOMENDACIONES
========================================================= -->
<section class="slide" data-i="9">
  <div class="bg-deco"></div>
  <div class="slide-num">09 / 10</div>
  <div class="slide-inner">
    <div class="slide-tag reveal">08 — qué hacer ahora</div>
    <h2 class="reveal">Recomendaciones operativas.</h2>
    <p class="lead reveal">
      <?= $total_recos ?> hallazgos derivados directamente de las métricas del periodo.
      <?php if ($prioridad_alta > 0): ?>
        <?= $prioridad_alta ?> <?= $prioridad_alta === 1 ? 'requiere' : 'requieren' ?> atención prioritaria.
      <?php endif; ?>
    </p>
    <div class="recos-grid reveal">
      <?php foreach ($recos as $r): ?>
        <div class="reco <?= inf_h($r['prioridad']) ?>">
          <div class="reco-head">
            <div class="reco-titulo"><?= inf_h($r['titulo']) ?></div>
            <div class="reco-prio <?= inf_h($r['prioridad']) ?>"><?= inf_h($r['prioridad']) ?></div>
          </div>
          <div class="reco-accion"><?= inf_h($r['accion']) ?></div>
          <div class="reco-metrica">▸ <?= inf_h($r['metrica']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- =========================================================
     SLIDE 10 · CIERRE
========================================================= -->
<section class="slide outro" data-i="10">
  <div class="slide-num" style="color:rgba(255,255,255,.4)">10 / 10</div>
  <div class="slide-inner">
    <div class="slide-tag reveal" style="background:rgba(255,255,255,.1);color:#7fb1ff">09 — cierre</div>
    <div class="ttl reveal">
      Querétaro pedaleó<br>
      <span><?= inf_fmt_f((float)($k['dist_total_km'] ?? 0)) ?> km</span><br>
      en este periodo.
    </div>
    <p class="lead reveal" style="margin-top:32px;color:rgba(255,255,255,.78);max-width:55ch">
      <?= inf_fmt((int)($k['total_viajes'] ?? 0)) ?> viajes,
      <?= inf_fmt((int)($k['usuarios_unicos'] ?? 0)) ?> usuarios únicos,
      <?= inf_fmt((int)($k['estaciones_activas'] ?? 0)) ?> estaciones operando.
      El siguiente paso son las <?= $total_recos ?> acciones priorizadas en la sección anterior.
    </p>
    <div class="cta-row reveal">
      <a href="reporte.php" class="cta primary">Reporte analítico ›</a>
      <a href="mapa_animado.php" class="cta ghost">Mapa animado de flujo ›</a>
    </div>
    <div class="sig reveal">QroBici Analytics · <?= date('d / m / Y') ?></div>
  </div>
</section>

<!-- =========================================================
     NAV
========================================================= -->
<div class="nav-dots" id="navDots"></div>
<div class="nav-arrows">
  <button id="btnPrev" title="Anterior (↑)">↑</button>
  <button id="btnNext" title="Siguiente (↓)">↓</button>
</div>

<script>
const slides = document.querySelectorAll('.slide');
const dotsEl = document.getElementById('navDots');
const progress = document.getElementById('progress');
const btnPrev = document.getElementById('btnPrev');
const btnNext = document.getElementById('btnNext');

// === construye puntitos laterales ===
slides.forEach((s, i) => {
  const b = document.createElement('button');
  b.className = 'dot';
  b.title = `Slide ${i + 1}`;
  b.addEventListener('click', () => s.scrollIntoView({behavior: 'smooth'}));
  dotsEl.appendChild(b);
});
const dots = document.querySelectorAll('.nav-dots .dot');

// === detecta slide activo y actualiza progreso ===
let activeIdx = 0;
const io = new IntersectionObserver((entries) => {
  entries.forEach(e => {
    if (e.intersectionRatio > 0.5) {
      const idx = parseInt(e.target.dataset.i, 10) - 1;
      activeIdx = idx;
      slides.forEach(s => s.classList.remove('is-active'));
      e.target.classList.add('is-active');
      dots.forEach((d, i) => d.classList.toggle('active', i === idx));
      progress.style.width = ((idx + 1) / slides.length * 100) + '%';
      btnPrev.disabled = (idx === 0);
      btnNext.disabled = (idx === slides.length - 1);

      // anima count-up de números marcados con data-count
      e.target.querySelectorAll('[data-count]:not(.counted)').forEach(animateCount);
      // anima barras horizontales
      e.target.querySelectorAll('.hbar-row').forEach(r => r.classList.add('is-in'));
    }
  });
}, {threshold: [0.5]});
slides.forEach(s => io.observe(s));

// === count-up de números grandes ===
function animateCount(el) {
  el.classList.add('counted');
  const target = parseInt(el.dataset.count, 10) || 0;
  const start = performance.now();
  const dur = 1400;
  function step(t) {
    const k = Math.min(1, (t - start) / dur);
    const ease = 1 - Math.pow(1 - k, 3);
    const val = Math.floor(target * ease);
    el.textContent = val.toLocaleString('es-MX');
    if (k < 1) requestAnimationFrame(step);
  }
  requestAnimationFrame(step);
}

// === navegación con flechas ===
function goTo(idx) {
  idx = Math.max(0, Math.min(slides.length - 1, idx));
  slides[idx].scrollIntoView({behavior: 'smooth'});
}
btnPrev.addEventListener('click', () => goTo(activeIdx - 1));
btnNext.addEventListener('click', () => goTo(activeIdx + 1));

document.addEventListener('keydown', (e) => {
  if (e.target.matches('input, textarea, select')) return;
  if (e.key === 'ArrowDown' || e.key === 'PageDown' || e.code === 'Space') {
    e.preventDefault(); goTo(activeIdx + 1);
  }
  if (e.key === 'ArrowUp' || e.key === 'PageUp') {
    e.preventDefault(); goTo(activeIdx - 1);
  }
  if (e.key === 'Home') { e.preventDefault(); goTo(0); }
  if (e.key === 'End')  { e.preventDefault(); goTo(slides.length - 1); }
});

// arranca activando la primera slide visible
window.addEventListener('load', () => {
  slides[0].classList.add('is-active');
  dots[0]?.classList.add('active');
});
</script>

<?php endif; ?>
<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
