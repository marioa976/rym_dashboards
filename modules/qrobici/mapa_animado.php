<?php
/**
 * QroBici Analytics — Mapa animado de flujo
 * ------------------------------------------------------------
 * Página independiente que muestra un Google Maps oscuro con
 * trails neón animados sobre los recorridos GPS reales de los
 * viajes del último día con datos. Día acelerado en loop.
 *
 *   • Cyan  (#00d4ff) → bici mecánica
 *   • Magenta (#ff3da6) → bici eléctrica
 *   • Gold (#ffd700) → estaciones titilantes
 *
 * Reutiliza config.php, db.php, lib_polyline.php, lib_mapa.php.
 */

declare(strict_types=1);

date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib_polyline.php';
require_once __DIR__ . '/lib_mapa.php';

$cfg = require __DIR__ . '/config.php';

if (!empty($cfg['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}
date_default_timezone_set($cfg['zona_horaria'] ?? 'America/Mexico_City');

// inicializa PDO singleton
try {
    qrb_db($cfg);
} catch (Throwable $e) {
    if (!empty($cfg['debug'])) { throw $e; }
    http_response_code(500);
    die('Error de conexión. Revisa config.php');
}

// día solicitado por querystring (?dia=YYYY-MM-DD). null = último con datos.
$dia_req = $_GET['dia'] ?? null;
if ($dia_req !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia_req)) {
    $dia_req = null;
}

// cache opcional, separado por día para no contaminar resultados
$cache_key = $dia_req ?: 'ultimo';
$cache_file = sys_get_temp_dir() . '/qrobici_mapa_' . $cache_key . '.json';
$data = null;
$cache_seg = (int)($cfg['cache_segundos'] ?? 0);
if ($cache_seg > 0 && is_file($cache_file)
    && (time() - filemtime($cache_file)) < $cache_seg) {
    $data = json_decode(file_get_contents($cache_file), true);
}
if ($data === null) {
    $data = qrb_construye_dataset_mapa($cfg, $dia_req);
    if ($cache_seg > 0) {
        @file_put_contents($cache_file, json_encode($data));
    }
}

$api_key = htmlspecialchars($cfg['google_maps_api_key'] ?? '', ENT_QUOTES, 'UTF-8');
$json    = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
// blindaje: evita que un '</script>' en cualquier campo rompa el HTML
$json    = str_replace(['</', "\u{2028}", "\u{2029}"], ['<\/', ' ', ' '], $json);

// === Navegación entre días ===
$dia_actual    = $data['fecha'] ?? null;
$dias_lista    = $data['dias_disponibles'] ?? [];
$dias_solo     = array_column($dias_lista, 'dia');
$idx_actual    = $dia_actual !== null ? array_search($dia_actual, $dias_solo, true) : false;
// la lista viene DESC (más reciente primero):
//   "anterior" (más viejo) = idx + 1
//   "siguiente" (más nuevo) = idx - 1
$dia_anterior  = ($idx_actual !== false && isset($dias_solo[$idx_actual + 1])) ? $dias_solo[$idx_actual + 1] : null;
$dia_siguiente = ($idx_actual !== false && $idx_actual > 0) ? $dias_solo[$idx_actual - 1] : null;

// helper para fecha corta tipo "mié 13/may"
function qrb_fecha_corta(string $dia): string {
    $dias_sem = ['Sun'=>'dom','Mon'=>'lun','Tue'=>'mar','Wed'=>'mié',
                 'Thu'=>'jue','Fri'=>'vie','Sat'=>'sáb'];
    $meses    = [1=>'ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    $ts = strtotime($dia);
    return ($dias_sem[date('D', $ts)] ?? '') . ' ' . (int)date('j', $ts) . '/' . ($meses[(int)date('n', $ts)] ?? '');
}

?><?php
$ktTitle  = 'QroBici — Flujo de la ciudad';
$ktActive = 'qrobici';
require __DIR__ . '/../../views/layout/kt_top.php';
?><link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800;900&family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap">
<style>
:root {
  --bg:#040711;
  --bg-grad-a:#06091a;
  --bg-grad-b:#020308;
  --panel:rgba(8,12,28,.78);
  --panel-bd:rgba(120,160,255,.18);
  --txt:#e8efff;
  --txt-d:#8aa0d8;
  --txt-dim:#5970a8;
  --cyan:#00d4ff;
  --magenta:#ff3da6;
  --gold:#ffd700;
  --green:#33ffb0;
  --grid:rgba(120,160,255,.06);
}
* { box-sizing:border-box; margin:0; padding:0; }
html, body { height:100%; overflow:hidden; }
body {
  font-family:'Space Grotesk', system-ui, sans-serif;
  color:var(--txt);
  background:
    radial-gradient(1200px 800px at 20% -10%, #0b1234 0%, transparent 60%),
    radial-gradient(900px 700px at 110% 110%, #1a0830 0%, transparent 55%),
    linear-gradient(180deg, var(--bg-grad-a), var(--bg-grad-b));
  -webkit-font-smoothing:antialiased;
  text-rendering:optimizeLegibility;
  display:flex; flex-direction:column;
}

/* ===== TOPBAR (header sólido arriba) ===== */
.topbar {
  flex:0 0 auto;
  display:flex; align-items:center; gap:18px;
  padding:12px 22px;
  background:rgba(6,9,22,.88);
  border-bottom:1px solid rgba(120,160,255,.15);
  backdrop-filter:blur(22px) saturate(140%);
  -webkit-backdrop-filter:blur(22px) saturate(140%);
  box-shadow:0 6px 24px rgba(0,0,0,.4);
  z-index:10;
  position:relative;
}
.topbar .sect {
  display:flex; align-items:center; gap:14px;
}
.topbar .grow { flex:1 1 auto; }
.topbar .sep {
  width:1px; height:30px;
  background:rgba(120,160,255,.15);
}

/* ===== MAPA ===== */
#map {
  flex:1 1 auto;
  position:relative;
  width:100%;
  background:var(--bg);
}
/* Anula el cursor de Google y el banner inferior */
.gm-style-cc, .gmnoprint a, .gmnoprint span { display:none !important; }
.gm-style { background:#040711 !important; }
#flow-canvas { pointer-events:none; }

/* ===== OVERLAY HUD ===== */
.hud {
  position:fixed;
  z-index:5;
  pointer-events:none;
}
.hud > * { pointer-events:auto; }

/* Bloque del logo + título (parte del topbar) */
.brand {
  display:flex; align-items:center; gap:12px;
  padding:0;
  background:transparent;
  border:none;
  box-shadow:none;
}
.brand .logo {
  width:36px; height:36px; border-radius:10px;
  background:linear-gradient(135deg, var(--cyan), var(--magenta));
  display:flex; align-items:center; justify-content:center;
  font-family:'Archivo', sans-serif; font-weight:900; color:#0a1024;
  font-size:18px; letter-spacing:-1px;
  box-shadow:0 0 22px rgba(0,212,255,.45);
}
.brand .meta { line-height:1.15; }
.brand .meta .t1 {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:16px; letter-spacing:.5px; text-transform:uppercase;
}
.brand .meta .t2 {
  font-family:'Space Mono', monospace;
  font-size:11px; color:var(--txt-d); letter-spacing:.5px;
  text-transform:uppercase;
  margin-top:2px;
}

/* Navegación de días */
.brand { position:relative; }
.day-nav {
  display:flex; align-items:center; gap:4px;
  margin-top:3px;
}
.navbtn {
  display:inline-flex; align-items:center; justify-content:center;
  width:22px; height:22px;
  border-radius:6px;
  font-family:'Space Mono', monospace; font-size:14px; font-weight:700;
  color:var(--cyan); text-decoration:none;
  border:1px solid rgba(0,212,255,.25);
  background:rgba(0,212,255,.05);
  transition:all .15s ease;
  line-height:1;
}
.navbtn:hover {
  background:rgba(0,212,255,.18);
  border-color:var(--cyan);
  box-shadow:0 0 12px rgba(0,212,255,.35);
}
.navbtn.disabled {
  color:var(--txt-dim); border-color:rgba(120,160,255,.1);
  background:transparent;
  cursor:not-allowed; pointer-events:none;
}
.day-current {
  background:transparent; border:1px solid rgba(120,160,255,.18);
  color:var(--txt);
  font-family:'Space Mono', monospace;
  font-size:10px; letter-spacing:.4px;
  text-transform:uppercase;
  padding:5px 10px;
  border-radius:6px;
  cursor:pointer;
  display:inline-flex; align-items:center; gap:5px;
  transition:all .15s ease;
}
.day-current:hover {
  border-color:var(--cyan); color:var(--cyan);
}
.day-current .caret { font-size:8px; color:var(--txt-dim); }
.day-current:hover .caret { color:var(--cyan); }

.day-list {
  position:fixed;
  top:100px; left:18px;       /* se reposiciona con JS al abrir */
  z-index:200;
  min-width:260px; max-height:380px; overflow-y:auto;
  background:rgba(8,12,28,.96);
  border:1px solid rgba(0,212,255,.35);
  border-radius:12px;
  padding:6px;
  backdrop-filter:blur(22px) saturate(140%);
  -webkit-backdrop-filter:blur(22px) saturate(140%);
  box-shadow:0 22px 60px rgba(0,0,0,.7), 0 0 28px rgba(0,212,255,.18);
  font-family:'Space Mono', monospace;
  pointer-events:auto;
  display:none;               /* oculto por defecto */
}
.day-list.is-open { display:block; }
.day-row {
  display:flex; align-items:center; justify-content:space-between;
  padding:7px 12px;
  border-radius:8px;
  text-decoration:none;
  color:var(--txt-d);
  font-size:11px; letter-spacing:.5px;
  text-transform:uppercase;
  transition:all .12s ease;
}
.day-row:hover {
  background:rgba(0,212,255,.08);
  color:var(--cyan);
}
.day-row.active {
  background:linear-gradient(135deg, rgba(0,212,255,.22), rgba(255,61,166,.18));
  color:#fff;
  box-shadow:inset 0 0 0 1px rgba(0,212,255,.4);
}
.day-row-n {
  font-size:10px; color:var(--txt-dim);
  font-variant-numeric:tabular-nums;
}
.day-row.active .day-row-n { color:rgba(255,255,255,.7); }

/* scrollbar fino del dropdown */
.day-list::-webkit-scrollbar { width:6px; }
.day-list::-webkit-scrollbar-track { background:transparent; }
.day-list::-webkit-scrollbar-thumb { background:rgba(120,160,255,.18); border-radius:3px; }
.day-list::-webkit-scrollbar-thumb:hover { background:rgba(120,160,255,.35); }

/* Reloj (parte del topbar, centrado) */
.clock {
  display:flex; flex-direction:column; align-items:center;
  padding:0;
  background:transparent; border:none; box-shadow:none;
  min-width:auto;
}
.clock .lbl {
  font-family:'Space Mono', monospace;
  font-size:10px; letter-spacing:2px; color:var(--cyan);
  text-transform:uppercase;
  text-shadow:0 0 12px rgba(0,212,255,.5);
}
.clock .time {
  font-family:'Space Mono', monospace;
  font-weight:700; font-size:32px; line-height:1;
  letter-spacing:1px;
  background:linear-gradient(180deg, #fff, #b8d0ff);
  -webkit-background-clip:text; background-clip:text; color:transparent;
  font-variant-numeric:tabular-nums;
  margin-top:2px;
}
.clock .date {
  font-family:'Space Grotesk', sans-serif;
  font-size:10px; color:var(--txt-d);
  text-transform:capitalize; margin-top:3px;
  letter-spacing:.3px;
}

/* KPIs (parte del topbar) */
.kpis {
  display:flex; gap:14px;
}
.kpi {
  display:flex; flex-direction:column;
  padding:0 12px;
  border-left:1px solid rgba(120,160,255,.12);
  min-width:auto;
  background:transparent;
}
.kpi:first-child { border-left:none; padding-left:0; }
.kpi .lbl {
  font-family:'Space Mono', monospace;
  font-size:9px; letter-spacing:1.5px; color:var(--txt-dim);
  text-transform:uppercase;
}
.kpi .val {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:20px; line-height:1; margin-top:3px;
  font-variant-numeric:tabular-nums;
}
.kpi.mec .val { color:var(--cyan); text-shadow:0 0 16px rgba(0,212,255,.45); }
.kpi.elec .val { color:var(--magenta); text-shadow:0 0 16px rgba(255,61,166,.45); }
.kpi.live .val { color:var(--green); text-shadow:0 0 16px rgba(51,255,176,.45); }

/* Controles abajo-centro */
.controls {
  bottom:22px; left:50%; transform:translateX(-50%);
  display:flex; align-items:center; gap:6px;
  background:var(--panel);
  border:1px solid var(--panel-bd);
  border-radius:14px;
  padding:8px 10px;
  backdrop-filter:blur(18px) saturate(140%);
  -webkit-backdrop-filter:blur(18px) saturate(140%);
  box-shadow:0 18px 50px rgba(0,0,0,.5), inset 0 0 0 1px rgba(255,255,255,.04);
}
.btn {
  background:transparent; border:1px solid transparent;
  color:var(--txt-d);
  font-family:'Space Mono', monospace; font-size:11px;
  letter-spacing:1px; text-transform:uppercase;
  padding:8px 12px; border-radius:9px; cursor:pointer;
  transition:all .15s ease;
  font-weight:700;
}
.btn:hover {
  color:#fff; border-color:rgba(120,160,255,.25);
  background:rgba(120,160,255,.05);
}
.btn.active {
  color:#040711;
  background:linear-gradient(135deg, var(--cyan), #6fe6ff);
  box-shadow:0 0 18px rgba(0,212,255,.45), 0 0 32px rgba(0,212,255,.2);
}
.btn.toggle-elec.active {
  background:linear-gradient(135deg, var(--magenta), #ff8dc7);
  box-shadow:0 0 18px rgba(255,61,166,.45), 0 0 32px rgba(255,61,166,.2);
}
.btn .icon { font-size:13px; }
.sep {
  width:1px; height:20px; background:rgba(120,160,255,.15);
  margin:0 4px;
}

/* Leyenda + créditos abajo-izquierda */
.legend {
  bottom:22px; left:18px;
  display:flex; flex-direction:column; gap:6px;
  background:var(--panel);
  border:1px solid var(--panel-bd);
  border-radius:14px;
  padding:12px 14px;
  backdrop-filter:blur(18px) saturate(140%);
  -webkit-backdrop-filter:blur(18px) saturate(140%);
  box-shadow:0 18px 50px rgba(0,0,0,.5);
  font-family:'Space Mono', monospace;
  font-size:10px; letter-spacing:.8px;
}
.legend-row { display:flex; align-items:center; gap:8px; color:var(--txt-d); text-transform:uppercase; }
.legend-dot {
  width:10px; height:10px; border-radius:50%;
  box-shadow:0 0 10px currentColor;
}
.legend-dot.cyan { background:var(--cyan); color:var(--cyan); }
.legend-dot.mag  { background:var(--magenta); color:var(--magenta); }
.legend-dot.gold { background:var(--gold); color:var(--gold); }

/* Botón "ver reporte" abajo-derecha */
.actions {
  bottom:22px; right:18px;
  display:flex; gap:8px;
}
.actions a {
  display:inline-flex; align-items:center; gap:6px;
  background:var(--panel); color:var(--txt);
  text-decoration:none;
  border:1px solid var(--panel-bd);
  border-radius:14px;
  padding:10px 14px;
  font-family:'Space Mono', monospace; font-size:11px;
  letter-spacing:1px; text-transform:uppercase; font-weight:700;
  backdrop-filter:blur(18px) saturate(140%);
  -webkit-backdrop-filter:blur(18px) saturate(140%);
  box-shadow:0 18px 50px rgba(0,0,0,.5);
  transition:all .15s ease;
}
.actions a:hover {
  border-color:var(--cyan);
  color:var(--cyan);
  box-shadow:0 0 22px rgba(0,212,255,.25), 0 18px 50px rgba(0,0,0,.5);
}

/* Splash / loader inicial */
.splash {
  position:fixed; inset:0; z-index:50;
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  background:radial-gradient(ellipse at center, #0a1230 0%, #02030a 70%);
  transition:opacity .6s ease;
}
.splash.fade { opacity:0; pointer-events:none; }
.splash .logo {
  width:56px; height:56px; border-radius:14px;
  background:linear-gradient(135deg, var(--cyan), var(--magenta));
  display:flex; align-items:center; justify-content:center;
  font-family:'Archivo', sans-serif; font-weight:900; color:#0a1024;
  font-size:26px; letter-spacing:-1px;
  box-shadow:0 0 40px rgba(0,212,255,.6);
  animation:pulse 1.5s ease-in-out infinite;
}
.splash .ttl {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:28px; letter-spacing:2px;
  margin-top:18px; text-transform:uppercase;
}
.splash .sub {
  font-family:'Space Mono', monospace;
  font-size:11px; letter-spacing:2px;
  color:var(--cyan); margin-top:6px;
  animation:blink 1.2s ease-in-out infinite;
}
@keyframes pulse { 0%,100%{transform:scale(1);} 50%{transform:scale(1.07);} }
@keyframes blink { 0%,100%{opacity:1;} 50%{opacity:.35;} }

/* Mensaje sin datos */
.empty {
  position:fixed; inset:0;
  display:flex; align-items:center; justify-content:center;
  flex-direction:column;
  background:radial-gradient(ellipse at center, #0a1230 0%, #02030a 70%);
  z-index:30;
  text-align:center;
}
.empty h1 {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:36px; letter-spacing:1px;
  background:linear-gradient(135deg, var(--cyan), var(--magenta));
  -webkit-background-clip:text; background-clip:text; color:transparent;
}
.empty p {
  font-family:'Space Mono', monospace;
  color:var(--txt-d); margin-top:14px; font-size:13px;
  max-width:480px; line-height:1.6;
}

/* Mobile ajustes */
@media (max-width:780px) {
  .brand { padding:8px 14px 8px 10px; gap:10px; }
  .brand .logo { width:30px; height:30px; font-size:15px; }
  .brand .meta .t1 { font-size:13px; }
  .brand .meta .t2 { font-size:9px; }
  .clock { top:auto; bottom:130px; min-width:200px; padding:8px 16px 10px; }
  .clock .time { font-size:34px; }
  .kpis { top:14px; right:14px; gap:6px; }
  .kpi { padding:8px 10px; min-width:auto; }
  .kpi .val { font-size:18px; }
  .controls { padding:6px 8px; }
  .btn { padding:7px 9px; font-size:10px; }
  .legend { display:none; }
  .actions { bottom:80px; right:14px; }
}
</style>

<?php if (!empty($data['vacio'])): ?>
  <div class="empty">
    <h1>SIN DATOS GPS</h1>
    <p><?= htmlspecialchars($data['mensaje'] ?? 'No hay viajes con recorrido disponible para animar.', ENT_QUOTES, 'UTF-8') ?></p>
    <p style="margin-top:24px">
      <a href="reporte.php" style="color:var(--cyan);text-decoration:none;border-bottom:1px solid var(--cyan)">← Volver al reporte</a>
    </p>
  </div>
<?php else: ?>

  <div class="splash" id="splash">
    <div class="logo">Q</div>
    <div class="ttl">QroBici · Flujo</div>
    <div class="sub">cargando datos · sintetizando rutas</div>
  </div>

  <!-- ===== TOPBAR ===== -->
  <header class="topbar">
    <div class="sect brand">
      <div class="logo">Q</div>
      <div class="meta">
        <div class="t1">Flujo de la ciudad</div>
        <div class="day-nav">
          <a class="navbtn<?= $dia_anterior ? '' : ' disabled' ?>"
             <?= $dia_anterior ? 'href="?dia=' . urlencode($dia_anterior) . '"' : '' ?>
             title="<?= $dia_anterior ? 'Día anterior: ' . qrb_fecha_corta($dia_anterior) : 'No hay día anterior' ?>">‹</a>
          <button class="day-current" id="btnDayPicker" type="button"
                  title="Ver días disponibles">
            <?= htmlspecialchars($data['fecha_label'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            <span class="caret">▾</span>
          </button>
          <a class="navbtn<?= $dia_siguiente ? '' : ' disabled' ?>"
             <?= $dia_siguiente ? 'href="?dia=' . urlencode($dia_siguiente) . '"' : '' ?>
             title="<?= $dia_siguiente ? 'Día siguiente: ' . qrb_fecha_corta($dia_siguiente) : 'No hay día más nuevo' ?>">›</a>
        </div>
      </div>
    </div>

    <div class="grow"></div>

    <div class="sect clock">
      <div style="text-align:center">
        <div class="lbl">Hora del día</div>
        <div class="time" id="clockTime">00:00</div>
        <div class="date" id="speedLbl">velocidad 1×</div>
      </div>
    </div>

    <div class="grow"></div>

    <div class="sect kpis">
      <div class="kpi live">
        <div class="lbl">Activos</div>
        <div class="val" id="kActivos">0</div>
      </div>
      <div class="kpi mec">
        <div class="lbl">Mecánicas</div>
        <div class="val" id="kMec"><?= number_format((int)($data['mecanicas'] ?? 0)) ?></div>
      </div>
      <div class="kpi elec">
        <div class="lbl">Eléctricas</div>
        <div class="val" id="kElec"><?= number_format((int)($data['electricas'] ?? 0)) ?></div>
      </div>
    </div>
  </header>

  <div id="map"></div>

  <!-- Dropdown de días (fixed, posicionado con JS al abrir) -->
  <div class="day-list" id="dayList">
    <?php foreach ($dias_lista as $d):
        $is_active = ($d['dia'] === $dia_actual); ?>
      <a class="day-row<?= $is_active ? ' active' : '' ?>"
         href="?dia=<?= urlencode($d['dia']) ?>">
        <span class="day-row-lbl"><?= qrb_fecha_corta($d['dia']) ?></span>
        <span class="day-row-n"><?= number_format((int)$d['n']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="hud controls">
    <button class="btn active" id="btnPlay">
      <span class="icon">▶</span> <span id="playLbl">Pause</span>
    </button>
    <div class="sep"></div>
    <button class="btn speed" data-speed="0.5">0.5×</button>
    <button class="btn speed active" data-speed="1">1×</button>
    <button class="btn speed" data-speed="2">2×</button>
    <button class="btn speed" data-speed="4">4×</button>
    <div class="sep"></div>
    <button class="btn toggle-mec active" id="btnMec">Mecánica</button>
    <button class="btn toggle-elec active" id="btnElec">Eléctrica</button>
  </div>

  <div class="hud legend">
    <div class="legend-row"><span class="legend-dot cyan"></span> Bici mecánica</div>
    <div class="legend-row"><span class="legend-dot mag"></span> Bici eléctrica</div>
    <div class="legend-row"><span class="legend-dot gold"></span> Estaciones</div>
  </div>

  <div class="hud actions">
    <a href="reporte.php">← Reporte completo</a>
  </div>

  <!-- panel diagnóstico: se quita con ?debug=0 -->
  <?php $dbg = !isset($_GET['debug']) || $_GET['debug'] !== '0'; ?>
  <?php if ($dbg): ?>
  <div class="hud" id="debug" style="
       top:90px; right:18px;
       background:rgba(8,12,28,.78);
       border:1px solid rgba(255,61,166,.4);
       border-radius:12px; padding:12px 14px;
       font-family:'Space Mono',monospace; font-size:10px;
       color:#e8efff; line-height:1.7; letter-spacing:.5px;
       backdrop-filter:blur(18px); -webkit-backdrop-filter:blur(18px);
       box-shadow:0 0 24px rgba(255,61,166,.18);
       max-width:300px;">
    <div style="color:#ff3da6;font-weight:700;letter-spacing:1.5px;margin-bottom:6px">DIAGNÓSTICO</div>
    <div>fecha: <b><?= htmlspecialchars($data['fecha'] ?? '?', ENT_QUOTES, 'UTF-8') ?></b></div>
    <div>crudos BD: <b id="dCrudos"><?= (int)($data['diag']['crudos'] ?? 0) ?></b></div>
    <div>desc. decode: <b id="dDecode"><?= (int)($data['diag']['descartes_decode'] ?? 0) ?></b></div>
    <div>desc. coords: <b id="dCoords"><?= (int)($data['diag']['descartes_coords'] ?? 0) ?></b></div>
    <div>viajes payload: <b><?= (int)($data['total'] ?? 0) ?></b></div>
    <div>dur prom (s): <b><?= (int)($data['diag']['duracion_prom_s'] ?? 0) ?></b></div>
    <div>dur max (s): <b><?= (int)($data['diag']['duracion_max_s'] ?? 0) ?></b></div>
    <div>centro: <b><?= round($data['centro']['lat'] ?? 0, 4) ?>, <?= round($data['centro']['lng'] ?? 0, 4) ?></b></div>
    <div style="margin-top:6px;padding-top:6px;border-top:1px solid rgba(120,160,255,.15)">
      tipos vistos en BD:
      <?php foreach (($data['diag']['tipos_vistos'] ?? []) as $tipo => $n): ?>
        <div style="margin-left:6px;color:var(--cyan)">· <b><?= htmlspecialchars((string)$tipo, ENT_QUOTES, 'UTF-8') ?: '(vacío)' ?></b>: <?= (int)$n ?></div>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:6px;padding-top:6px;border-top:1px solid rgba(120,160,255,.15)">
      <div>map: <b id="dMap">?</b></div>
      <div>mapDiv: <b id="dMapDiv">?</b></div>
      <div>overlay: <b id="dOver">?</b></div>
      <div>canvas: <b id="dCanvas">?</b></div>
      <div>zoom: <b id="dZoom">?</b></div>
      <div>tiles: <b id="dTiles">?</b></div>
      <div>1er viaje m,d,t: <b id="dPrimer">?</b></div>
      <div>1er punto: <b id="dPunto">?</b></div>
      <div>1er punto en px: <b id="dPx">?</b></div>
    </div>
  </div>
  <?php endif; ?>

<script id="payload" type="application/json"><?= $json ?></script>
<script>
// ============================================================
// QROBICI — animación de flujo
// ============================================================
const DATA = JSON.parse(document.getElementById('payload').textContent);

// ----- Pre-cómputo: distancia acumulada por viaje (en grados) -----
for (const v of DATA.viajes) {
  const cum = [0];
  let total = 0;
  for (let i = 1; i < v.p.length; i++) {
    const dx = v.p[i][1] - v.p[i-1][1];
    const dy = v.p[i][0] - v.p[i-1][0];
    total += Math.sqrt(dx*dx + dy*dy);
    cum.push(total);
  }
  v.cum = cum;
  v.tot = total || 1e-9;
}

// Estado de la animación
const STATE = {
  clock: 6 * 60,        // arranca a las 06:00 (más interesante visualmente)
  speed: 1,             // multiplicador
  playing: true,
  showMec: true,
  showElec: true,
  active: 0,
  lastTs: null,
};

// 24h del día completo se reproducen en 60s a 1× → 1440/60 = 24 min reloj por segundo real
const MIN_PER_SEC = 24;

// ============================================================
// Mapa: estilo dark futurista
// ============================================================
const DARK_STYLE = [
  {elementType:'geometry', stylers:[{color:'#0b1430'}]},
  {elementType:'labels.text.fill', stylers:[{color:'#7088c4'}]},
  {elementType:'labels.text.stroke', stylers:[{color:'#020308'}]},
  {featureType:'administrative', elementType:'geometry', stylers:[{color:'#152055'}, {weight:0.8}]},
  {featureType:'administrative.country', elementType:'labels.text.fill', stylers:[{color:'#8aa0d8'}]},
  {featureType:'administrative.locality', elementType:'labels.text.fill', stylers:[{color:'#c3d3f5'}]},
  {featureType:'poi', stylers:[{visibility:'off'}]},
  {featureType:'road', elementType:'geometry', stylers:[{color:'#1c2a5e'}]},
  {featureType:'road', elementType:'geometry.stroke', stylers:[{color:'#2e3f88'}]},
  {featureType:'road.highway', elementType:'geometry', stylers:[{color:'#2e3f88'}]},
  {featureType:'road.highway', elementType:'geometry.stroke', stylers:[{color:'#4858b8'}]},
  {featureType:'road.arterial', elementType:'geometry', stylers:[{color:'#1c2a5e'}]},
  {featureType:'road.local', elementType:'geometry', stylers:[{color:'#162049'}]},
  {featureType:'road', elementType:'labels.text.fill', stylers:[{color:'#6079b5'}]},
  {featureType:'water', elementType:'geometry', stylers:[{color:'#040c24'}]},
  {featureType:'water', elementType:'labels.text.fill', stylers:[{color:'#3d52a0'}]},
  {featureType:'transit', stylers:[{visibility:'off'}]},
  {featureType:'landscape', elementType:'geometry', stylers:[{color:'#0b1430'}]},
];

let map, overlay, canvas, ctx, projection;
let mapW = 0, mapH = 0;
let offX = 0, offY = 0;

function initMap() {
  map = new google.maps.Map(document.getElementById('map'), {
    center: DATA.centro,
    zoom: 13,
    styles: DARK_STYLE,
    disableDefaultUI: true,
    gestureHandling: 'greedy',
    backgroundColor: '#040711',
    mapTypeId: 'roadmap',
  });

  // ajusta bounds a los viajes (primer punto de cada uno)
  if (DATA.viajes.length > 0) {
    const b = new google.maps.LatLngBounds();
    for (const v of DATA.viajes) {
      b.extend({lat:v.p[0][0], lng:v.p[0][1]});
      const last = v.p[v.p.length-1];
      b.extend({lat:last[0], lng:last[1]});
    }
    map.fitBounds(b, {top:120, bottom:120, left:80, right:80});
  }

  // Overlay con canvas
  class FlowOverlay extends google.maps.OverlayView {
    onAdd() {
      canvas = document.createElement('canvas');
      canvas.id = 'flow-canvas';
      canvas.style.cssText = 'position:absolute;left:0;top:0;pointer-events:none;will-change:transform;';
      this.getPanes().overlayLayer.appendChild(canvas);
      ctx = canvas.getContext('2d');
    }
    draw() {
      projection = this.getProjection();
      if (!projection) return;
      this.fit();
    }
    fit() {
      const bounds = map.getBounds();
      if (!bounds) return;
      const ne = bounds.getNorthEast();
      const sw = bounds.getSouthWest();
      // NW = max lat, min lng — SE = min lat, max lng (esquinas que SÍ difieren en x e y)
      const pNW = projection.fromLatLngToDivPixel(new google.maps.LatLng(ne.lat(), sw.lng()));
      const pSE = projection.fromLatLngToDivPixel(new google.maps.LatLng(sw.lat(), ne.lng()));
      const left = pNW.x;
      const top  = pNW.y;
      // si por alguna razón los píxeles vienen invertidos, hacemos abs
      let w = Math.abs(pSE.x - pNW.x);
      let h = Math.abs(pSE.y - pNW.y);
      // fallback al tamaño real del div del mapa por si todo lo anterior da 0
      const mapDiv = map.getDiv();
      if (w < 1) w = mapDiv.offsetWidth;
      if (h < 1) h = mapDiv.offsetHeight;
      mapW = w; mapH = h;
      offX = left; offY = top;
      const dpr = window.devicePixelRatio || 1;
      canvas.style.left = left + 'px';
      canvas.style.top  = top + 'px';
      canvas.style.width  = w + 'px';
      canvas.style.height = h + 'px';
      canvas.width  = Math.max(1, Math.floor(w * dpr));
      canvas.height = Math.max(1, Math.floor(h * dpr));
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }
    project(lat, lng) {
      if (!projection) return null;
      const p = projection.fromLatLngToDivPixel(new google.maps.LatLng(lat, lng));
      return [p.x - offX, p.y - offY];
    }
    onRemove() { canvas.remove(); }
  }
  overlay = new FlowOverlay();
  overlay.setMap(map);

  // arrancar render loop después de que el overlay esté pintado
  google.maps.event.addListenerOnce(map, 'idle', () => {
    // dispara resize por si el contenedor cambió tras la carga inicial
    google.maps.event.trigger(map, 'resize');
    // segundo fitBounds tras resize: ahora con dimensiones reales
    if (DATA.viajes.length > 0) {
      const b = new google.maps.LatLngBounds();
      for (const v of DATA.viajes) {
        b.extend({lat:v.p[0][0], lng:v.p[0][1]});
        const last = v.p[v.p.length-1];
        b.extend({lat:last[0], lng:last[1]});
      }
      map.fitBounds(b, {top:140, bottom:140, left:80, right:80});
    }
    document.getElementById('splash').classList.add('fade');
    requestAnimationFrame(frame);
  });

  // listener permanente: cada cambio de zoom/pan reajusta el canvas
  map.addListener('bounds_changed', () => {
    if (overlay && overlay.getProjection && overlay.getProjection()) {
      overlay.fit();
    }
  });

  // marcar tiles cargadas para el debug
  google.maps.event.addListenerOnce(map, 'tilesloaded', () => {
    STATE.tilesLoaded = true;
  });
}
window.initMap = initMap;

// ============================================================
// Interpolar posición del viaje a un porcentaje 0..1
// ============================================================
function interp(v, t) {
  const target = t * v.tot;
  // búsqueda binaria en cum
  let lo = 0, hi = v.cum.length - 1;
  while (lo < hi - 1) {
    const mid = (lo + hi) >> 1;
    if (v.cum[mid] <= target) lo = mid; else hi = mid;
  }
  const seg = v.cum[hi] - v.cum[lo];
  const k = seg > 0 ? (target - v.cum[lo]) / seg : 0;
  const a = v.p[lo], b = v.p[hi];
  return [a[0] + (b[0] - a[0]) * k, a[1] + (b[1] - a[1]) * k];
}

// ============================================================
// Loop principal
// ============================================================
function frame(ts) {
  if (!ctx) { requestAnimationFrame(frame); return; }
  if (STATE.lastTs == null) STATE.lastTs = ts;
  const dt = Math.min(0.1, (ts - STATE.lastTs) / 1000);   // sec
  STATE.lastTs = ts;

  // avance del reloj
  if (STATE.playing) {
    STATE.clock += MIN_PER_SEC * STATE.speed * dt;
    if (STATE.clock >= 1440) STATE.clock -= 1440;
  }

  // === fade del canvas: borra los píxeles previos con alpha bajo ===
  // 'destination-out' resta alpha sin tocar el fondo transparente
  ctx.globalCompositeOperation = 'destination-out';
  ctx.fillStyle = 'rgba(0,0,0,0.04)';
  ctx.fillRect(0, 0, mapW, mapH);

  // === capa principal: glow aditivo ===
  ctx.globalCompositeOperation = 'lighter';

  // Estaciones titilantes (suaves, debajo de partículas)
  const pulse = 0.5 + 0.5 * Math.sin(ts * 0.002);
  for (const e of DATA.estaciones) {
    const pos = overlay.project(e.lat, e.lng);
    if (!pos) continue;
    const [x, y] = pos;
    if (x < -20 || y < -20 || x > mapW + 20 || y > mapH + 20) continue;
    const r = 3 + Math.min(6, Math.sqrt(e.n));
    const grad = ctx.createRadialGradient(x, y, 0, x, y, r * 2.4);
    grad.addColorStop(0, 'rgba(255,215,0,' + (0.55 + 0.25 * pulse) + ')');
    grad.addColorStop(0.4, 'rgba(255,180,0,0.25)');
    grad.addColorStop(1, 'rgba(255,150,0,0)');
    ctx.fillStyle = grad;
    ctx.beginPath();
    ctx.arc(x, y, r * 2.4, 0, Math.PI * 2);
    ctx.fill();
  }

  // Viajes activos
  let activos = 0;
  for (const v of DATA.viajes) {
    if (v.t === 'M' && !STATE.showMec) continue;
    if (v.t === 'E' && !STATE.showElec) continue;
    const tprog = (STATE.clock - v.m) / v.d;
    if (tprog < 0 || tprog > 1) continue;
    const pos = interp(v, tprog);
    const pxy = overlay.project(pos[0], pos[1]);
    if (!pxy) continue;
    const [x, y] = pxy;
    // clip fuera de viewport
    if (x < -30 || y < -30 || x > mapW + 30 || y > mapH + 30) { activos++; continue; }

    const isE = (v.t === 'E');
    const colCore = isE ? '#ff3da6' : '#00d4ff';
    const colMid  = isE ? 'rgba(255,61,166,' : 'rgba(0,212,255,';

    // halo grande
    const r = 22;
    const g1 = ctx.createRadialGradient(x, y, 0, x, y, r);
    g1.addColorStop(0, colMid + '0.85)');
    g1.addColorStop(0.35, colMid + '0.35)');
    g1.addColorStop(1, colMid + '0)');
    ctx.fillStyle = g1;
    ctx.beginPath(); ctx.arc(x, y, r, 0, Math.PI * 2); ctx.fill();

    // núcleo brillante
    ctx.fillStyle = colCore;
    ctx.beginPath(); ctx.arc(x, y, 2.4, 0, Math.PI * 2); ctx.fill();

    activos++;
  }

  // restaurar
  ctx.globalCompositeOperation = 'source-over';

  // Actualizar HUD cada ~6 frames
  STATE.hudTick = (STATE.hudTick || 0) + 1;
  if (STATE.hudTick % 6 === 0) updateHud(activos);
  STATE.active = activos;

  requestAnimationFrame(frame);
}

// ============================================================
// HUD
// ============================================================
function updateHud(activos) {
  const h = Math.floor(STATE.clock / 60);
  const m = Math.floor(STATE.clock % 60);
  document.getElementById('clockTime').textContent =
    String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
  document.getElementById('kActivos').textContent = activos;

  // Diagnóstico (si el panel existe)
  const dMap = document.getElementById('dMap');
  if (dMap) {
    dMap.textContent = (typeof map !== 'undefined' && map) ? 'ok' : 'no';
    if (map) {
      const md = map.getDiv();
      document.getElementById('dMapDiv').textContent = md.offsetWidth + '×' + md.offsetHeight;
    }
    document.getElementById('dOver').textContent = (typeof overlay !== 'undefined' && overlay) ? 'ok' : 'no';
    document.getElementById('dCanvas').textContent = canvas
      ? Math.round(mapW) + '×' + Math.round(mapH) : 'no';
    document.getElementById('dZoom').textContent = map ? map.getZoom() : '?';
    document.getElementById('dTiles').textContent = STATE.tilesLoaded ? 'ok' : 'esperando';
    if (DATA.viajes && DATA.viajes[0]) {
      const v0 = DATA.viajes[0];
      document.getElementById('dPrimer').textContent =
        v0.m + ',' + v0.d + ',' + v0.t;
      document.getElementById('dPunto').textContent =
        v0.p[0][0].toFixed(4) + ',' + v0.p[0][1].toFixed(4);
      const px = overlay && overlay.getProjection && overlay.getProjection()
        ? overlay.project(v0.p[0][0], v0.p[0][1]) : null;
      document.getElementById('dPx').textContent = px
        ? Math.round(px[0]) + ',' + Math.round(px[1]) : 'no proj';
    }
  }
}

// ============================================================
// Controles
// ============================================================
const btnPlay = document.getElementById('btnPlay');
btnPlay.addEventListener('click', () => {
  STATE.playing = !STATE.playing;
  btnPlay.querySelector('.icon').textContent = STATE.playing ? '⏸' : '▶';
  document.getElementById('playLbl').textContent = STATE.playing ? 'Pause' : 'Play';
  btnPlay.classList.toggle('active', STATE.playing);
});

document.querySelectorAll('.btn.speed').forEach(b => {
  b.addEventListener('click', () => {
    document.querySelectorAll('.btn.speed').forEach(x => x.classList.remove('active'));
    b.classList.add('active');
    STATE.speed = parseFloat(b.dataset.speed);
    document.getElementById('speedLbl').textContent = 'velocidad ' + STATE.speed + '×';
  });
});

document.getElementById('btnMec').addEventListener('click', e => {
  STATE.showMec = !STATE.showMec;
  e.currentTarget.classList.toggle('active', STATE.showMec);
});
document.getElementById('btnElec').addEventListener('click', e => {
  STATE.showElec = !STATE.showElec;
  e.currentTarget.classList.toggle('active', STATE.showElec);
});

// Atajos de teclado
document.addEventListener('keydown', e => {
  // No interceptar cuando el foco está en un input
  if (e.target.matches('input, textarea, select')) return;
  if (e.code === 'Space') { btnPlay.click(); e.preventDefault(); }
  if (e.key === '1') document.querySelector('.btn.speed[data-speed="1"]').click();
  if (e.key === '2') document.querySelector('.btn.speed[data-speed="2"]').click();
  if (e.key === '3') document.querySelector('.btn.speed[data-speed="4"]').click();
  // Flechas: día anterior/siguiente
  if (e.key === 'ArrowLeft') {
    const a = document.querySelector('.navbtn[href]:not(.disabled):nth-of-type(1)');
    if (a) { window.location.href = a.href; }
  }
  if (e.key === 'ArrowRight') {
    const a = document.querySelectorAll('.navbtn[href]:not(.disabled)');
    if (a.length) { window.location.href = a[a.length-1].href; }
  }
});

// Dropdown del selector de día
const btnDay  = document.getElementById('btnDayPicker');
const dayList = document.getElementById('dayList');
if (btnDay && dayList) {
  // posiciona el dropdown justo abajo del botón usando getBoundingClientRect
  function positionDay() {
    const r = btnDay.getBoundingClientRect();
    dayList.style.top  = (r.bottom + 8) + 'px';
    dayList.style.left = r.left + 'px';
  }
  btnDay.addEventListener('click', (e) => {
    e.stopPropagation();
    const willOpen = !dayList.classList.contains('is-open');
    if (willOpen) {
      positionDay();
      dayList.classList.add('is-open');
      const active = dayList.querySelector('.day-row.active');
      if (active) setTimeout(() => active.scrollIntoView({block:'center'}), 0);
    } else {
      dayList.classList.remove('is-open');
    }
  });
  // cerrar al hacer click fuera
  document.addEventListener('click', (e) => {
    if (!dayList.contains(e.target) && !btnDay.contains(e.target)) {
      dayList.classList.remove('is-open');
    }
  });
  // cerrar con ESC
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') dayList.classList.remove('is-open');
  });
  // reposicionar si la ventana cambia mientras está abierto
  window.addEventListener('resize', () => {
    if (dayList.classList.contains('is-open')) positionDay();
  });
}

</script>

<script defer src="https://maps.googleapis.com/maps/api/js?key=<?= $api_key ?>&libraries=&callback=initMap&v=quarterly"></script>

<?php endif; ?>
<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
