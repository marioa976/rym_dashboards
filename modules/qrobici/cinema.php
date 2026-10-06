<?php
/**
 * QroBici — "La ciudad en movimiento" (reporte-película / motion graphics)
 * ------------------------------------------------------------------------
 * Un día de QroBici contado como data-film: los viajes reales (RECORRIDO GPS)
 * fluyen como estelas de neón sobre un mapa oscuro mientras el reloj avanza de
 * 00:00 a 24:00. Capítulos por franja horaria, contadores animados, histograma
 * por hora con playhead y recap final.
 *
 * Reutiliza el MISMO pipeline de datos que "Flujo de la ciudad" (mapa_animado):
 * qrb_construye_dataset_mapa() -> {viajes:[{m,d,t,p}], estaciones, centro, ...}.
 * Todo el CSS vive bajo :where(#cine) para no filtrarse al shell del portal.
 */
declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib_polyline.php';
require_once __DIR__ . '/lib_mapa.php';

$cfg = require __DIR__ . '/config.php';
if (!empty($cfg['debug'])) { error_reporting(E_ALL); ini_set('display_errors', '1'); }
date_default_timezone_set($cfg['zona_horaria'] ?? 'America/Mexico_City');

try { qrb_db($cfg); }
catch (Throwable $e) {
    if (!empty($cfg['debug'])) { throw $e; }
    http_response_code(500); die('Error de conexión. Revisa config.php');
}

// Día solicitado (?dia=YYYY-MM-DD); null = último con datos.
$dia_req = $_GET['dia'] ?? null;
if ($dia_req !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia_req)) { $dia_req = null; }

$cache_key  = $dia_req ?: 'ultimo';
$cache_file = sys_get_temp_dir() . '/qrobici_mapa_' . $cache_key . '.json';   // comparte caché con mapa_animado
$data = null;
$cache_seg = (int)($cfg['cache_segundos'] ?? 0);
if ($cache_seg > 0 && is_file($cache_file) && (time() - filemtime($cache_file)) < $cache_seg) {
    $data = json_decode(file_get_contents($cache_file), true);
}
if ($data === null) {
    $data = qrb_construye_dataset_mapa($cfg, $dia_req);
    if ($cache_seg > 0) { @file_put_contents($cache_file, json_encode($data)); }
}

// ---- Agregados para el film (histograma por hora + totales) ----
$horas   = array_fill(0, 24, 0);
$horas_m = array_fill(0, 24, 0);
$horas_e = array_fill(0, 24, 0);
$dur_sum = 0; $dur_n = 0;
foreach (($data['viajes'] ?? []) as $v) {
    $h = max(0, min(23, intdiv((int)$v['m'], 60)));
    $horas[$h]++;
    if (($v['t'] ?? 'M') === 'E') { $horas_e[$h]++; } else { $horas_m[$h]++; }
    $dur_sum += (int)$v['d']; $dur_n++;
}
$hora_pico = 0; $pico_max = -1;
foreach ($horas as $h => $n) { if ($n > $pico_max) { $pico_max = $n; $hora_pico = $h; } }
$dur_prom = $dur_n > 0 ? (int)round($dur_sum / $dur_n) : 0;   // minutos (muestra)

// fecha_label viene en minúsculas ("viernes 28 de agosto de 2026"); capitaliza
// SOLO la inicial (no cada palabra, que pondría "De" en mayúscula).
$fecha_lbl = (string)($data['fecha_label'] ?? '');
$fecha_cap = $fecha_lbl !== '' ? mb_strtoupper(mb_substr($fecha_lbl, 0, 1)) . mb_substr($fecha_lbl, 1) : '';

$total_real = (int)($data['total_real'] ?? ($data['total'] ?? 0));
$mec = (int)($data['mecanicas'] ?? 0);
$ele = (int)($data['electricas'] ?? 0);
$muestra = (int)($data['total'] ?? 0);

$cine = [
    'horas'     => array_values($horas),
    'horas_m'   => array_values($horas_m),
    'horas_e'   => array_values($horas_e),
    'hora_pico' => $hora_pico,
    'pico'      => $pico_max,
    'dur_prom'  => $dur_prom,
    'total_real'=> $total_real,
    'muestra'   => $muestra,
    'mec'       => $mec,
    'ele'       => $ele,
    'fecha'     => $data['fecha'] ?? null,
    'fecha_label'=> $data['fecha_label'] ?? '',
    'vacio'     => !empty($data['vacio']) || $muestra === 0,
];

$api_key = htmlspecialchars($cfg['google_maps_api_key'] ?? '', ENT_QUOTES, 'UTF-8');
$json    = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$json    = str_replace(['</', "\u{2028}", "\u{2029}"], ['<\/', ' ', ' '], $json);
$cineJson= json_encode($cine, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$cineJson= str_replace('</', '<\/', $cineJson);

// Navegación entre días
$dia_actual    = $data['fecha'] ?? null;
$dias_solo     = array_column($data['dias_disponibles'] ?? [], 'dia');
$idx           = $dia_actual !== null ? array_search($dia_actual, $dias_solo, true) : false;
$dia_anterior  = ($idx !== false && isset($dias_solo[$idx + 1])) ? $dias_solo[$idx + 1] : null;
$dia_siguiente = ($idx !== false && $idx > 0) ? $dias_solo[$idx - 1] : null;

$ktTitle  = 'QroBici — La ciudad en movimiento';
$ktActive = 'qrobici';
$ktFluid  = true;
require __DIR__ . '/../../views/layout/kt_top.php';
?>
<style>
/* Todo scopeado con :where(#cine): especificidad 0, no toca el shell. */
#cine{ --cine-bg:#060c1e; --cine-mec:#00e0ff; --cine-ele:#ff54b0; --cine-gold:#ffd264;
       --cine-ink:#eaf1ff; --cine-dim:#8ea3d6; --cine-line:rgba(138,160,216,.18); }
:where(#cine){ position:relative; border-radius:18px; overflow:hidden;
  background:var(--cine-bg); color:var(--cine-ink);
  font-family:'Montserrat',sans-serif; box-shadow:0 20px 60px rgba(4,8,22,.45);
  height:clamp(560px, calc(100vh - 150px), 920px); }
:where(#cine):fullscreen{ height:100vh; border-radius:0; }
:where(#cine) *{ box-sizing:border-box; }

:where(#cine) #map{ position:absolute; inset:0; background:var(--cine-bg); }
:where(#cine) #flow-canvas{ pointer-events:none; }

/* Letterbox + viñeta cinematográfica */
:where(#cine) .cine-vign{ position:absolute; inset:0; pointer-events:none; z-index:3;
  background:
    radial-gradient(130% 120% at 50% 42%, transparent 52%, rgba(3,6,16,.72) 100%),
    linear-gradient(to bottom, rgba(3,6,16,.55), transparent 16%, transparent 72%, rgba(3,6,16,.92)); }

/* ---------- Franja superior (identidad + fecha) ---------- */
:where(#cine) .cine-top{ position:absolute; z-index:5; top:0; left:0; right:0;
  display:flex; align-items:flex-start; justify-content:space-between; gap:16px;
  padding:20px 24px; pointer-events:none; }
:where(#cine) .cine-eyebrow{ font-size:11px; font-weight:700; letter-spacing:.22em;
  text-transform:uppercase; color:var(--cine-dim); }
:where(#cine) .cine-h1{ font-size:clamp(18px,2.4vw,28px); font-weight:800; letter-spacing:-.02em;
  margin-top:3px; text-shadow:0 2px 18px rgba(3,6,16,.8); }
:where(#cine) .cine-date{ text-align:right; font-size:12.5px; color:var(--cine-dim); line-height:1.5;
  text-shadow:0 2px 12px rgba(3,6,16,.9); }
:where(#cine) .cine-date b{ color:var(--cine-ink); font-weight:700; }

/* ---------- Capítulo (franja horaria) ---------- */
:where(#cine) .cine-chapter{ position:absolute; z-index:5; left:26px; top:50%;
  transform:translateY(-50%); max-width:min(42%,420px); pointer-events:none;
  opacity:0; transition:opacity .7s ease; }
:where(#cine) .cine-chapter.show{ opacity:1; }
:where(#cine) .cine-chapter .rule{ width:40px; height:3px; border-radius:2px;
  background:linear-gradient(90deg,var(--cine-mec),var(--cine-ele)); margin-bottom:14px; }
:where(#cine) .cine-chapter .hr{ font-family:'Montserrat',monospace; font-size:12px;
  letter-spacing:.2em; color:var(--cine-dim); font-weight:700; }
:where(#cine) .cine-chapter .ti{ font-size:clamp(26px,4vw,46px); font-weight:800;
  letter-spacing:-.03em; line-height:1.02; margin:6px 0 8px;
  text-shadow:0 4px 30px rgba(3,6,16,.9); }
:where(#cine) .cine-chapter .su{ font-size:15px; color:var(--cine-dim); line-height:1.45; }

/* ---------- HUD inferior ---------- */
:where(#cine) .cine-hud{ position:absolute; z-index:5; left:0; right:0; bottom:0;
  padding:16px 24px 20px; display:flex; align-items:flex-end; gap:26px; }
:where(#cine) .cine-clock{ flex-shrink:0; }
:where(#cine) .cine-clock .t{ font-family:'Montserrat',monospace; font-weight:800;
  font-size:clamp(40px,6vw,66px); line-height:.9; letter-spacing:-.04em;
  font-variant-numeric:tabular-nums; text-shadow:0 2px 24px rgba(0,224,255,.25); }
:where(#cine) .cine-clock .lbl{ font-size:10.5px; letter-spacing:.2em; text-transform:uppercase;
  color:var(--cine-dim); font-weight:700; margin-top:4px; }

/* histograma por hora */
:where(#cine) .cine-hist{ flex:1; min-width:0; }
:where(#cine) .cine-hist .bars{ display:grid; grid-template-columns:repeat(24,1fr); gap:3px;
  align-items:flex-end; height:70px; }
:where(#cine) .cine-hist .bar{ position:relative; border-radius:3px 3px 0 0; min-height:2px;
  background:rgba(138,160,216,.22); transition:background .25s; }
:where(#cine) .cine-hist .bar.past{ background:linear-gradient(to top,var(--cine-mec),#7ae8ff); }
:where(#cine) .cine-hist .bar.now{ background:linear-gradient(to top,var(--cine-ele),#ffa6d8);
  box-shadow:0 0 14px rgba(255,84,176,.6); }
:where(#cine) .cine-hist .axis{ display:flex; justify-content:space-between;
  font-family:'Montserrat',monospace; font-size:10px; color:var(--cine-dim); margin-top:7px; }

/* contadores a la derecha */
:where(#cine) .cine-counts{ flex-shrink:0; display:flex; gap:22px; text-align:right; }
:where(#cine) .cine-counts .c .v{ font-family:'Montserrat',monospace; font-weight:800;
  font-size:clamp(20px,2.4vw,30px); letter-spacing:-.02em; font-variant-numeric:tabular-nums; }
:where(#cine) .cine-counts .c .k{ font-size:10px; letter-spacing:.14em; text-transform:uppercase;
  color:var(--cine-dim); font-weight:700; margin-top:3px; display:flex; align-items:center; gap:6px; justify-content:flex-end; }
:where(#cine) .cine-counts .dot{ width:8px; height:8px; border-radius:50%; }
:where(#cine) .cine-counts .dot.mec{ background:var(--cine-mec); box-shadow:0 0 8px var(--cine-mec); }
:where(#cine) .cine-counts .dot.ele{ background:var(--cine-ele); box-shadow:0 0 8px var(--cine-ele); }

/* barra de progreso del día (scrub) */
:where(#cine) .cine-scrub{ position:absolute; z-index:6; left:24px; right:24px; bottom:12px;
  height:4px; border-radius:3px; background:rgba(138,160,216,.18); cursor:pointer; }
:where(#cine) .cine-scrub .fill{ height:100%; border-radius:3px; width:0%;
  background:linear-gradient(90deg,var(--cine-mec),var(--cine-ele)); }

/* ---------- Controles ---------- */
:where(#cine) .cine-ctl{ position:absolute; z-index:7; top:16px; right:50%; transform:translateX(50%);
  display:flex; gap:8px; align-items:center; background:rgba(8,14,32,.6);
  border:1px solid var(--cine-line); border-radius:100px; padding:6px 8px;
  backdrop-filter:blur(8px); }
:where(#cine) .cine-ctl button, :where(#cine) .cine-ctl a{ all:unset; cursor:pointer;
  display:inline-flex; align-items:center; justify-content:center; gap:6px;
  height:34px; min-width:34px; padding:0 10px; border-radius:100px;
  color:var(--cine-ink); font-size:13px; font-weight:700; transition:background .15s; }
:where(#cine) .cine-ctl button:hover, :where(#cine) .cine-ctl a:hover{ background:rgba(138,160,216,.16); }
:where(#cine) .cine-ctl .sep{ width:1px; height:20px; background:var(--cine-line); }
:where(#cine) .cine-ctl .spd{ font-family:'Montserrat',monospace; font-size:12px; min-width:44px; }
:where(#cine) .cine-ctl .ico{ width:16px; height:16px; display:block; }
:where(#cine) .cine-ctl a.off{ opacity:.35; pointer-events:none; }

/* ---------- Intro + recap ---------- */
:where(#cine) .cine-card{ position:absolute; inset:0; z-index:8; display:flex;
  flex-direction:column; align-items:center; justify-content:center; text-align:center;
  gap:10px; padding:32px; background:radial-gradient(120% 100% at 50% 40%, rgba(8,14,34,.72), rgba(4,7,18,.94));
  backdrop-filter:blur(2px); transition:opacity .8s ease; }
:where(#cine) .cine-card.hide{ opacity:0; pointer-events:none; }
:where(#cine) .cine-card .kick{ font-size:12px; letter-spacing:.24em; text-transform:uppercase;
  color:var(--cine-dim); font-weight:700; }
:where(#cine) .cine-card .big{ font-size:clamp(30px,5vw,56px); font-weight:800; letter-spacing:-.03em;
  line-height:1.03; max-width:16ch; }
:where(#cine) .cine-card .num{ font-family:'Montserrat',monospace; font-weight:800;
  font-size:clamp(48px,9vw,104px); letter-spacing:-.04em; font-variant-numeric:tabular-nums;
  background:linear-gradient(90deg,var(--cine-mec),var(--cine-ele));
  -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent; }
:where(#cine) .cine-card .sub{ font-size:15px; color:var(--cine-dim); max-width:46ch; line-height:1.5; }
:where(#cine) .cine-card .recap{ display:flex; gap:34px; margin:14px 0 6px; flex-wrap:wrap; justify-content:center; }
:where(#cine) .cine-card .recap .v{ font-family:'Montserrat',monospace; font-weight:800; font-size:clamp(22px,3vw,34px); }
:where(#cine) .cine-card .recap .k{ font-size:11px; letter-spacing:.14em; text-transform:uppercase; color:var(--cine-dim); font-weight:700; margin-top:4px; }
:where(#cine) .cine-card .cta{ all:unset; cursor:pointer; margin-top:10px;
  display:inline-flex; align-items:center; gap:9px; padding:12px 22px; border-radius:100px;
  background:linear-gradient(90deg,var(--cine-mec),var(--cine-ele)); color:#06101f; font-weight:800; font-size:14px;
  box-shadow:0 10px 30px rgba(0,224,255,.25); }
:where(#cine) .cine-card .cta:hover{ filter:brightness(1.07); }

/* estado vacío */
:where(#cine) .cine-empty{ position:absolute; inset:0; z-index:8; display:flex; flex-direction:column;
  align-items:center; justify-content:center; gap:10px; text-align:center; color:var(--cine-dim); padding:32px; }

@media (max-width:760px){
  :where(#cine) .cine-counts{ display:none; }
  :where(#cine) .cine-top{ display:none; }                 /* el topbar del portal ya nombra la página */
  :where(#cine) .cine-chapter{ max-width:82%; top:62px; bottom:auto; transform:none; }
  :where(#cine) .cine-chapter .ti{ font-size:30px; }
  :where(#cine) .cine-hud{ gap:16px; flex-wrap:wrap; }
  :where(#cine) .cine-hist .bars{ height:48px; }
  :where(#cine) .cine-ctl{ left:8px; right:8px; transform:none; justify-content:center; flex-wrap:wrap; gap:3px; padding:5px 6px; }
  :where(#cine) .cine-ctl .sep{ display:none; }
}
@media (prefers-reduced-motion:reduce){
  :where(#cine) .cine-chapter, :where(#cine) .cine-card{ transition:none; }
}
</style>

<div id="cine">
  <div id="map"></div>
  <div class="cine-vign"></div>

  <?php if ($cine['vacio']): ?>
    <div class="cine-empty">
      <div style="font-size:40px;opacity:.5">○</div>
      <div style="font-size:19px;font-weight:800;color:var(--cine-ink)">Sin recorridos para este día</div>
      <div>No hay viajes con GPS para <b><?= htmlspecialchars($cine['fecha_label'] ?: 'la fecha seleccionada', ENT_QUOTES, 'UTF-8') ?></b>.
        <?php if ($dia_anterior): ?><a style="color:var(--cine-mec)" href="?dia=<?= $dia_anterior ?>">Ver día anterior</a><?php endif; ?></div>
    </div>
  <?php else: ?>

  <div class="cine-top">
    <div>
      <div class="cine-eyebrow">QroBici · Inteligencia de movilidad</div>
      <div class="cine-h1">La ciudad en movimiento</div>
    </div>
    <div class="cine-date">
      <b><?= htmlspecialchars($fecha_cap, ENT_QUOTES, 'UTF-8') ?></b><br>
      un día de bicicleta pública
    </div>
  </div>

  <div class="cine-ctl" role="group" aria-label="Controles de reproducción">
    <a id="cDayPrev" class="<?= $dia_anterior ? '' : 'off' ?>" <?= $dia_anterior ? 'href="?dia='.$dia_anterior.'"' : '' ?> title="Día anterior" aria-label="Día anterior">‹</a>
    <button id="cPlay" title="Pausa / Play" aria-label="Pausa o reproducir">
      <svg class="ico" id="cPlayIco" viewBox="0 0 16 16" fill="currentColor"><rect x="3" y="2.5" width="3.3" height="11" rx="1"/><rect x="9.7" y="2.5" width="3.3" height="11" rx="1"/></svg>
    </button>
    <button id="cRestart" title="Reiniciar el día" aria-label="Reiniciar">
      <svg class="ico" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M13 8a5 5 0 1 1-1.5-3.6"/><path d="M13 2.5V5h-2.5"/></svg>
    </button>
    <span class="sep"></span>
    <button id="cSpeed" class="spd" title="Velocidad">1×</button>
    <span class="sep"></span>
    <button id="cMec" title="Mecánicas"><span style="color:var(--cine-mec)">●</span> Mec</button>
    <button id="cEle" title="Eléctricas"><span style="color:var(--cine-ele)">●</span> Eléc</button>
    <span class="sep"></span>
    <button id="cFull" title="Pantalla completa" aria-label="Pantalla completa">
      <svg class="ico" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 6V2.5h3.5M14 6V2.5h-3.5M2 10v3.5h3.5M14 10v3.5h-3.5"/></svg>
    </button>
    <a id="cDayNext" class="<?= $dia_siguiente ? '' : 'off' ?>" <?= $dia_siguiente ? 'href="?dia='.$dia_siguiente.'"' : '' ?> title="Día siguiente" aria-label="Día siguiente">›</a>
  </div>

  <div class="cine-chapter" id="chapter">
    <div class="rule"></div>
    <div class="hr" id="chHr">00:00</div>
    <div class="ti" id="chTi">Medianoche</div>
    <div class="su" id="chSu">La ciudad descansa</div>
  </div>

  <div class="cine-hud">
    <div class="cine-clock">
      <div class="t" id="clockT">00:00</div>
      <div class="lbl">Hora del día</div>
    </div>
    <div class="cine-hist">
      <div class="bars" id="histBars"></div>
      <div class="axis"><span>00h</span><span>06h</span><span>12h</span><span>18h</span><span>24h</span></div>
    </div>
    <div class="cine-counts">
      <div class="c"><div class="v" id="cntTotal">0</div><div class="k">viajes</div></div>
      <div class="c"><div class="v" id="cntMec" style="color:var(--cine-mec)">0</div><div class="k"><span class="dot mec"></span>mecánica</div></div>
      <div class="c"><div class="v" id="cntEle" style="color:var(--cine-ele)">0</div><div class="k"><span class="dot ele"></span>eléctrica</div></div>
    </div>
  </div>
  <div class="cine-scrub" id="scrub" title="Mover en el tiempo"><div class="fill" id="scrubFill"></div></div>

  <!-- Intro -->
  <div class="cine-card" id="intro">
    <div class="kick">QroBici · Querétaro</div>
    <div class="num" id="introNum">0</div>
    <div class="big">viajes en un solo día</div>
    <div class="sub"><b style="color:var(--cine-ink)"><?= htmlspecialchars($fecha_cap, ENT_QUOTES, 'UTF-8') ?></b> — mira cómo se mueve la ciudad, hora por hora, siguiendo las rutas reales de cada bici.</div>
    <button class="cta" id="introGo">
      <svg class="ico" viewBox="0 0 16 16" fill="currentColor"><path d="M4 3l9 5-9 5z"/></svg>
      Reproducir el día
    </button>
  </div>

  <!-- Recap -->
  <div class="cine-card hide" id="recap">
    <div class="kick">Fin del día</div>
    <div class="big" style="font-size:clamp(26px,4vw,42px)">Así se movió Querétaro</div>
    <div class="recap">
      <div><div class="v" id="rTotal">0</div><div class="k">viajes</div></div>
      <div><div class="v" id="rPico">0h</div><div class="k">hora pico</div></div>
      <div><div class="v" id="rDur">0<span style="font-size:.6em"> min</span></div><div class="k">duración media</div></div>
      <div><div class="v"><span id="rMec" style="color:var(--cine-mec)">0%</span> / <span id="rEle" style="color:var(--cine-ele)">0%</span></div><div class="k">mecánica / eléctrica</div></div>
    </div>
    <button class="cta" id="recapGo">
      <svg class="ico" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M13 8a5 5 0 1 1-1.5-3.6"/><path d="M13 2.5V5h-2.5"/></svg>
      Reproducir de nuevo
    </button>
  </div>

  <?php endif; ?>
</div>

<?php if (!$cine['vacio']): ?>
<script id="payload" type="application/json"><?= $json ?></script>
<script id="cinepay" type="application/json"><?= $cineJson ?></script>
<script>
const DATA = JSON.parse(document.getElementById('payload').textContent);
const CINE = JSON.parse(document.getElementById('cinepay').textContent);
const GMAPS_KEY = <?= json_encode($api_key) ?>;

/* Pre-cómputo: distancia acumulada por viaje (grados) para interpolar. */
for (const v of DATA.viajes){
  const cum=[0]; let total=0;
  for(let i=1;i<v.p.length;i++){const dx=v.p[i][1]-v.p[i-1][1],dy=v.p[i][0]-v.p[i-1][0];total+=Math.sqrt(dx*dx+dy*dy);cum.push(total);}
  v.cum=cum; v.tot=total||1e-9;
}
/* Minutos de arranque ordenados para el contador acumulado. */
const STARTS = DATA.viajes.map(v=>({m:v.m,t:v.t})).sort((a,b)=>a.m-b.m);

const $ = id=>document.getElementById(id);
const fmt = n=>Math.round(n).toLocaleString('es-MX');

const STATE = { clock:0, speed:1, playing:false, showMec:true, showElec:true,
  lastTs:null, started:false, done:false, accIdx:0, accM:0, accE:0 };
const SPEEDS=[1,2,4];
const LOOP_SEC=78;                         // 24h del día en ~78s a 1×
const MIN_PER_SEC = 1440/LOOP_SEC;

const CHAPTERS=[
  {h:0, ti:'Medianoche',  su:'La ciudad descansa'},
  {h:5, ti:'Amanece',     su:'Salen las primeras bicis'},
  {h:7, ti:'Hora pico',   su:'Al trabajo y a la escuela'},
  {h:11,ti:'Media mañana',su:'Trámites y vueltas'},
  {h:14,ti:'Tarde',       su:'La ciudad en marcha'},
  {h:18,ti:'Regreso',     su:'De vuelta a casa'},
  {h:21,ti:'Noche',       su:'Baja el ritmo'},
];
let curChapter=-1;

/* ---------------- Histograma por hora ---------------- */
const maxHora = Math.max(1, ...CINE.horas);
(function buildHist(){
  const wrap=$('histBars'); if(!wrap) return;
  CINE.horas.forEach((n,h)=>{
    const b=document.createElement('div'); b.className='bar'; b.dataset.h=h;
    b.style.height=Math.max(2,(n/maxHora)*100)+'%';
    b.title=String(h).padStart(2,'0')+':00 · '+fmt(n)+' viajes';
    wrap.appendChild(b);
  });
})();

/* ---------------- Mapa + overlay (reusa patrón de mapa_animado) ---------------- */
let map, overlay, canvas, ctx, projection, mapW=0, mapH=0, offX=0, offY=0;
const DARK_STYLE=<?php
echo json_encode([
  ['elementType'=>'geometry','stylers'=>[['color'=>'#060c1e']]],
  ['elementType'=>'labels.text.fill','stylers'=>[['color'=>'#6a80bd']]],
  ['elementType'=>'labels.text.stroke','stylers'=>[['color'=>'#02040c']]],
  ['featureType'=>'administrative','elementType'=>'geometry','stylers'=>[['color'=>'#14204d'],['weight'=>0.8]]],
  ['featureType'=>'administrative.locality','elementType'=>'labels.text.fill','stylers'=>[['color'=>'#b8c9f2']]],
  ['featureType'=>'poi','stylers'=>[['visibility'=>'off']]],
  ['featureType'=>'road','elementType'=>'geometry','stylers'=>[['color'=>'#16224f']]],
  ['featureType'=>'road.highway','elementType'=>'geometry','stylers'=>[['color'=>'#243675']]],
  ['featureType'=>'road.local','elementType'=>'geometry','stylers'=>[['color'=>'#111b40']]],
  ['featureType'=>'road','elementType'=>'labels','stylers'=>[['visibility'=>'off']]],
  ['featureType'=>'water','elementType'=>'geometry','stylers'=>[['color'=>'#030a1f']]],
  ['featureType'=>'transit','stylers'=>[['visibility'=>'off']]],
  ['featureType'=>'landscape','elementType'=>'geometry','stylers'=>[['color'=>'#060c1e']]],
]);
?>;

function fitToTrips(pad){
  if(!DATA.viajes.length) return;
  const b=new google.maps.LatLngBounds();
  for(const v of DATA.viajes){ b.extend({lat:v.p[0][0],lng:v.p[0][1]}); const l=v.p[v.p.length-1]; b.extend({lat:l[0],lng:l[1]}); }
  map.fitBounds(b, pad||{top:90,bottom:150,left:70,right:70});
}

function initMap(){
  map=new google.maps.Map($('map'),{center:DATA.centro||{lat:20.5888,lng:-100.3899},zoom:13,
    styles:DARK_STYLE,disableDefaultUI:true,gestureHandling:'greedy',backgroundColor:'#060c1e',keyboardShortcuts:false});
  fitToTrips();
  class FlowOverlay extends google.maps.OverlayView{
    onAdd(){ canvas=document.createElement('canvas'); canvas.id='flow-canvas';
      canvas.style.cssText='position:absolute;left:0;top:0;pointer-events:none;will-change:transform;';
      this.getPanes().overlayLayer.appendChild(canvas); ctx=canvas.getContext('2d'); }
    draw(){ projection=this.getProjection(); if(projection) this.fit(); }
    fit(){ const bd=map.getBounds(); if(!bd) return;
      const ne=bd.getNorthEast(), sw=bd.getSouthWest();
      const pNW=projection.fromLatLngToDivPixel(new google.maps.LatLng(ne.lat(),sw.lng()));
      const pSE=projection.fromLatLngToDivPixel(new google.maps.LatLng(sw.lat(),ne.lng()));
      let w=Math.abs(pSE.x-pNW.x), h=Math.abs(pSE.y-pNW.y);
      const md=map.getDiv(); if(w<1)w=md.offsetWidth; if(h<1)h=md.offsetHeight;
      mapW=w; mapH=h; offX=pNW.x; offY=pNW.y;
      const dpr=window.devicePixelRatio||1;
      canvas.style.left=pNW.x+'px'; canvas.style.top=pNW.y+'px'; canvas.style.width=w+'px'; canvas.style.height=h+'px';
      canvas.width=Math.max(1,Math.floor(w*dpr)); canvas.height=Math.max(1,Math.floor(h*dpr));
      ctx.setTransform(dpr,0,0,dpr,0,0); }
    project(lat,lng){ if(!projection) return null; const p=projection.fromLatLngToDivPixel(new google.maps.LatLng(lat,lng)); return [p.x-offX,p.y-offY]; }
    onRemove(){ canvas.remove(); }
  }
  overlay=new FlowOverlay(); overlay.setMap(map);
  google.maps.event.addListenerOnce(map,'idle',()=>{ google.maps.event.trigger(map,'resize'); fitToTrips(); requestAnimationFrame(frame); });
  map.addListener('bounds_changed',()=>{ if(overlay&&overlay.getProjection&&overlay.getProjection()) overlay.fit(); });
}
window.initMap=initMap;

function interp(v,t){ const target=t*v.tot; let lo=0,hi=v.cum.length-1;
  while(lo<hi-1){const mid=(lo+hi)>>1; if(v.cum[mid]<=target)lo=mid; else hi=mid;}
  const seg=v.cum[hi]-v.cum[lo], k=seg>0?(target-v.cum[lo])/seg:0; const a=v.p[lo],b=v.p[hi];
  return [a[0]+(b[0]-a[0])*k, a[1]+(b[1]-a[1])*k]; }

/* ---------------- Loop ---------------- */
function frame(ts){
  if(!ctx){ requestAnimationFrame(frame); return; }
  if(STATE.lastTs==null) STATE.lastTs=ts;
  const dt=Math.min(0.1,(ts-STATE.lastTs)/1000); STATE.lastTs=ts;

  if(STATE.playing && !STATE.done){
    STATE.clock += MIN_PER_SEC*STATE.speed*dt;
    if(STATE.clock>=1440){ STATE.clock=1440; endDay(); }
  }

  // fade de estelas
  ctx.globalCompositeOperation='destination-out';
  ctx.fillStyle='rgba(0,0,0,0.035)'; ctx.fillRect(0,0,mapW,mapH);
  ctx.globalCompositeOperation='lighter';

  // estaciones
  const pulse=0.5+0.5*Math.sin(ts*0.002);
  for(const e of DATA.estaciones){ const pos=overlay.project(e.lat,e.lng); if(!pos) continue;
    const [x,y]=pos; if(x<-20||y<-20||x>mapW+20||y>mapH+20) continue;
    const r=3+Math.min(6,Math.sqrt(e.n));
    const g=ctx.createRadialGradient(x,y,0,x,y,r*2.4);
    g.addColorStop(0,'rgba(255,210,100,'+(0.5+0.25*pulse)+')'); g.addColorStop(0.4,'rgba(255,180,60,0.22)'); g.addColorStop(1,'rgba(255,160,40,0)');
    ctx.fillStyle=g; ctx.beginPath(); ctx.arc(x,y,r*2.4,0,Math.PI*2); ctx.fill(); }

  // viajes activos (comet-head; la estela la deja el fade)
  let activos=0;
  for(const v of DATA.viajes){
    if(v.t==='M'&&!STATE.showMec) continue; if(v.t==='E'&&!STATE.showElec) continue;
    const tp=(STATE.clock-v.m)/v.d; if(tp<0||tp>1) continue;
    const pos=interp(v,tp), pxy=overlay.project(pos[0],pos[1]); if(!pxy) continue;
    const [x,y]=pxy; if(x<-30||y<-30||x>mapW+30||y>mapH+30){activos++;continue;}
    const isE=v.t==='E'; const core=isE?'#ff54b0':'#00e0ff'; const mid=isE?'rgba(255,84,176,':'rgba(0,224,255,';
    const r=20; const g1=ctx.createRadialGradient(x,y,0,x,y,r);
    g1.addColorStop(0,mid+'0.9)'); g1.addColorStop(0.35,mid+'0.33)'); g1.addColorStop(1,mid+'0)');
    ctx.fillStyle=g1; ctx.beginPath(); ctx.arc(x,y,r,0,Math.PI*2); ctx.fill();
    ctx.fillStyle=core; ctx.beginPath(); ctx.arc(x,y,2.3,0,Math.PI*2); ctx.fill();
    activos++;
  }
  ctx.globalCompositeOperation='source-over';

  STATE.hudTick=(STATE.hudTick||0)+1;
  if(STATE.hudTick%4===0) updateHud(activos);
  requestAnimationFrame(frame);
}

/* ---------------- HUD / capítulos ---------------- */
function syncAcc(){
  // recalcula acumulados segun el reloj (soporta scrub hacia atrás)
  if(STATE.clock < STATE.accM_clock){ STATE.accIdx=0; STATE.accM=0; STATE.accE=0; }
  while(STATE.accIdx<STARTS.length && STARTS[STATE.accIdx].m<=STATE.clock){
    if(STARTS[STATE.accIdx].t==='E') STATE.accE++; else STATE.accM++; STATE.accIdx++;
  }
  STATE.accM_clock=STATE.clock;
}
function updateHud(activos){
  const h=Math.floor(STATE.clock/60), m=Math.floor(STATE.clock%60);
  const hh=String(Math.min(23,h)).padStart(2,'0'), mm=String(m).padStart(2,'0');
  $('clockT').textContent=hh+':'+mm;
  syncAcc();
  const scaleReal = CINE.total_real / Math.max(1, CINE.muestra);   // extrapola de la muestra al total real
  $('cntMec').textContent=fmt(STATE.accM*scaleReal);
  $('cntEle').textContent=fmt(STATE.accE*scaleReal);
  $('cntTotal').textContent=fmt((STATE.accM+STATE.accE)*scaleReal);
  // histograma: pasadas vs actual
  const curH=Math.min(23,h);
  const bars=$('histBars').children;
  for(let i=0;i<bars.length;i++){ bars[i].className='bar'+(i<curH?' past':(i===curH?' now':'')); }
  // scrub
  $('scrubFill').style.width=(STATE.clock/1440*100)+'%';
  // capítulo
  let ch=0; for(let i=0;i<CHAPTERS.length;i++){ if(h>=CHAPTERS[i].h) ch=i; }
  if(ch!==curChapter){ curChapter=ch; const c=CHAPTERS[ch];
    const el=$('chapter'); el.classList.remove('show');
    setTimeout(()=>{ $('chHr').textContent=String(c.h).padStart(2,'0')+':00';
      $('chTi').textContent=c.ti; $('chSu').textContent=c.su; el.classList.add('show'); },180);
  }
}

/* ---------------- Flujo de reproducción ---------------- */
function startDay(){ STATE.clock=0; STATE.done=false; STATE.accIdx=0; STATE.accM=0; STATE.accE=0; STATE.accM_clock=0;
  curChapter=-1; STATE.playing=true; setPlayIcon(); $('recap').classList.add('hide'); $('intro').classList.add('hide'); }
function endDay(){ STATE.done=true; STATE.playing=false; setPlayIcon();
  $('rTotal').textContent=fmt(CINE.total_real);
  $('rPico').textContent=String(CINE.hora_pico).padStart(2,'0')+'h';
  $('rDur').innerHTML=CINE.dur_prom+'<span style="font-size:.6em"> min</span>';
  const tot=Math.max(1,CINE.mec+CINE.ele);
  $('rMec').textContent=Math.round(CINE.mec/tot*100)+'%';
  $('rEle').textContent=Math.round(CINE.ele/tot*100)+'%';
  $('recap').classList.remove('hide');
}
function setPlayIcon(){ const ic=$('cPlayIco');
  if(STATE.playing){ ic.innerHTML='<rect x="3" y="2.5" width="3.3" height="11" rx="1"/><rect x="9.7" y="2.5" width="3.3" height="11" rx="1"/>'; }
  else{ ic.innerHTML='<path d="M4 3l9 5-9 5z"/>'; } }

/* Intro: cuenta el total antes de arrancar */
(function introCounter(){
  const el=$('introNum'); if(!el) return; const target=CINE.total_real; const T=1100; let t0=null;
  function step(ts){ if(t0==null)t0=ts; const k=Math.min(1,(ts-t0)/T); const e=1-Math.pow(1-k,3);
    el.textContent=fmt(target*e); if(k<1) requestAnimationFrame(step); }
  requestAnimationFrame(step);
})();

/* Controles */
$('introGo').onclick=startDay;
$('recapGo').onclick=startDay;
$('cPlay').onclick=()=>{ if(STATE.done){ startDay(); return; } STATE.playing=!STATE.playing; setPlayIcon(); };
$('cRestart').onclick=startDay;
$('cSpeed').onclick=()=>{ const i=(SPEEDS.indexOf(STATE.speed)+1)%SPEEDS.length; STATE.speed=SPEEDS[i]; $('cSpeed').textContent=STATE.speed+'×'; };
$('cMec').onclick=()=>{ STATE.showMec=!STATE.showMec; $('cMec').style.opacity=STATE.showMec?1:.4; };
$('cEle').onclick=()=>{ STATE.showElec=!STATE.showElec; $('cEle').style.opacity=STATE.showElec?1:.4; };
$('cFull').onclick=()=>{ const el=$('cine'); if(!document.fullscreenElement){ el.requestFullscreen&&el.requestFullscreen(); } else { document.exitFullscreen&&document.exitFullscreen(); } };
(function scrub(){ const s=$('scrub'); function set(e){ const r=s.getBoundingClientRect(); const k=Math.max(0,Math.min(1,(e.clientX-r.left)/r.width)); STATE.clock=k*1440; STATE.done=false; $('recap').classList.add('hide'); $('intro').classList.add('hide'); }
  let drag=false; s.addEventListener('pointerdown',e=>{drag=true;s.setPointerCapture(e.pointerId);set(e);});
  s.addEventListener('pointermove',e=>{if(drag)set(e);}); s.addEventListener('pointerup',()=>drag=false); })();
window.addEventListener('keydown',e=>{ if(e.code==='Space'){ e.preventDefault(); $('cPlay').click(); } });
document.addEventListener('fullscreenchange',()=>{ setTimeout(()=>{ if(map){ google.maps.event.trigger(map,'resize'); fitToTrips(); } },120); });

/* Cargar Google Maps */
(function loadMaps(){
  if(!GMAPS_KEY || GMAPS_KEY.length<10){ $('intro').innerHTML='<div class="kick">Mapa no disponible</div><div class="sub">Falta <code>GOOGLE_MAPS_API_KEY</code> en la configuración.</div>'; return; }
  const s=document.createElement('script');
  s.src='https://maps.googleapis.com/maps/api/js?key='+encodeURIComponent(GMAPS_KEY)+'&callback=initMap&loading=async&v=weekly';
  s.async=true; document.head.appendChild(s);
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
