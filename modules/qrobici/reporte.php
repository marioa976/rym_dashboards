<?php
/**
 * QroBici Analytics — Reporte principal
 * ------------------------------------------------------------
 * Entrada del reporte. Carga config + librerías, ejecuta las
 * consultas a MariaDB y genera el HTML autocontenido con los
 * datos en vivo embebidos como JSON.
 *
 * Uso:  http://tu-servidor/qrobici/reporte.php
 */

// ---------- bootstrap ----------
$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['zona_horaria']);
if ($cfg['debug']) { error_reporting(E_ALL); ini_set('display_errors', '1'); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib_viajes.php';
require_once __DIR__ . '/lib_planes.php';

// Inicializar conexión (singleton)
qrb_db($cfg);

// ---------- Filtros de anomalías (GET params, individualizables) ----------
// Cada filtro tiene su propio checkbox _on. Si el checkbox no está marcado,
// ese filtro no descarta nada. Sin GET params = sin filtros = datos crudos.
$F_DEFAULTS = [
    'vel_min'  => 3,        // km/h
    'vel_max'  => 35,       // km/h
    'dur_min'  => 60,       // segundos
    'dur_max'  => 14400,    // segundos = 4 h
    'dist_min' => 100,      // metros
];

// ¿El usuario envió el form? (hidden input f=1)
$tiene_filtros_get = isset($_GET['f']);

// Atajo "preset recomendado": marca todos los filtros con defaults
$preset = isset($_GET['preset']) && $_GET['preset'] === 'reco';

// Estado de cada checkbox (lo usaremos en HTML para mostrar marcado o no)
$F_ON = [
    'vel_min'  => $preset || ($tiene_filtros_get && !empty($_GET['vel_min_on'])),
    'vel_max'  => $preset || ($tiene_filtros_get && !empty($_GET['vel_max_on'])),
    'dur_min'  => $preset || ($tiene_filtros_get && !empty($_GET['dur_min_on'])),
    'dur_max'  => $preset || ($tiene_filtros_get && !empty($_GET['dur_max_on'])),
    'dist_min' => $preset || ($tiene_filtros_get && !empty($_GET['dist_min_on'])),
    'coords'   => $preset || ($tiene_filtros_get && !empty($_GET['coords'])),
];

// Valores actuales (siempre con un número para que el input se vea poblado,
// pero el filtro solo aplica si su checkbox correspondiente está activo)
$F_VAL = [
    'vel_min'  => isset($_GET['vel_min'])  && $_GET['vel_min']  !== '' ? (float)$_GET['vel_min']  : $F_DEFAULTS['vel_min'],
    'vel_max'  => isset($_GET['vel_max'])  && $_GET['vel_max']  !== '' ? (float)$_GET['vel_max']  : $F_DEFAULTS['vel_max'],
    'dur_min'  => isset($_GET['dur_min'])  && $_GET['dur_min']  !== '' ? (int)$_GET['dur_min']    : $F_DEFAULTS['dur_min'],
    'dur_max'  => isset($_GET['dur_max'])  && $_GET['dur_max']  !== '' ? (int)$_GET['dur_max']    : $F_DEFAULTS['dur_max'],
    'dist_min' => isset($_GET['dist_min']) && $_GET['dist_min'] !== '' ? (int)$_GET['dist_min']   : $F_DEFAULTS['dist_min'],
];

// Construcción del array $filtros_usr que se pasa al orquestador.
// Un valor null = ese filtro NO descarta.
$alguno_activo = false;
foreach ($F_ON as $k => $on) { if ($on) { $alguno_activo = true; break; } }

$filtros_usr = [
    'activos'        => $alguno_activo,
    'vel_min'        => $F_ON['vel_min']  ? $F_VAL['vel_min']  : null,
    'vel_max'        => $F_ON['vel_max']  ? $F_VAL['vel_max']  : null,
    'dur_min'        => $F_ON['dur_min']  ? $F_VAL['dur_min']  : null,
    'dur_max'        => $F_ON['dur_max']  ? $F_VAL['dur_max']  : null,
    'dist_min'       => $F_ON['dist_min'] ? $F_VAL['dist_min'] : null,
    'coords_validas' => $F_ON['coords'],
];

// Fechas: override del config si vienen por GET (independientes de los toggles)
if (!empty($_GET['desde'])) {
    $cfg['fecha_desde'] = $_GET['desde'] . ' 00:00:00';
}
if (!empty($_GET['hasta'])) {
    $cfg['fecha_hasta'] = $_GET['hasta'] . ' 23:59:59';
}

// ---------- caché simple por archivo ----------
// La clave de cache incluye los filtros activos para no contaminar resultados
$cache_key  = md5(json_encode($filtros_usr) . ($cfg['fecha_desde'] ?? '') . ($cfg['fecha_hasta'] ?? ''));
$cache_file = sys_get_temp_dir() . '/qrobici_cache_' . $cache_key . '.json';
$usar_cache = $cfg['cache_segundos'] > 0
    && file_exists($cache_file)
    && (time() - filemtime($cache_file)) < $cfg['cache_segundos'];

if ($usar_cache) {
    $DATA = json_decode(file_get_contents($cache_file), true);
} else {
    try {
        $DATA = qrb_construye_dataset_viajes($cfg, $filtros_usr)
              + qrb_construye_dataset_planes($cfg);
        // dataset de calificaciones (exclusivo del reporte; no se cuela a informe ni mapa)
        $DATA['calificaciones'] = qrb_construye_dataset_calificaciones($cfg);
    } catch (Throwable $e) {
        http_response_code(500);
        if ($cfg['debug']) { throw $e; }
        die('<h1>Error al construir el reporte</h1><p>Revisa config.php y los nombres de las vistas. Activa <code>debug => true</code> para ver detalles.</p>');
    }
    if ($cfg['cache_segundos'] > 0) {
        @file_put_contents($cache_file, json_encode($DATA, JSON_UNESCAPED_UNICODE));
    }
}

// Inyectamos al DATA los filtros aplicados para que el HTML pueda mostrarlos
$DATA['filtros_aplicados'] = $filtros_usr;
$DATA['fecha_desde_cfg']   = $cfg['fecha_desde'];
$DATA['fecha_hasta_cfg']   = $cfg['fecha_hasta'];

// JSON listo para embeber en el HTML
$DATA_JSON = json_encode($DATA, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$GMAP_KEY   = htmlspecialchars($cfg['google_maps_api_key'], ENT_QUOTES, 'UTF-8');
$GMAP_MAPID = htmlspecialchars($cfg['map_id'] ?? 'DEMO_MAP_ID', ENT_QUOTES, 'UTF-8');
$TITULO    = htmlspecialchars($cfg['titulo'],    ENT_QUOTES, 'UTF-8');
$SUBTITULO = htmlspecialchars($cfg['subtitulo'], ENT_QUOTES, 'UTF-8');

header('Content-Type: text/html; charset=utf-8');
?><?php
$ktTitle  = 'Reporte de Movilidad · QroBici';
$ktActive = 'qrobici';
require __DIR__ . '/../../views/layout/kt_top.php';
?><link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800;900&family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap">
<style>
:root{
  --azul:#005ab2; --azul-d:#254185; --azul-l:#e8f0ff; --azul-ll:#f4f8ff;
  --cielo:#2a9eda; --tinta:#0a1b3d; --gris:#5b6b8c; --gris-l:#9aa7c0;
  --linea:#e3e9f5; --bg:#fbfcfe; --blanco:#ffffff;
  --verde:#188a5b; --ambar:#d99000; --rojo:#ce3a2b; --rosa:#5b667a;
  --sombra:0 1px 3px rgba(10,27,61,.04),0 8px 24px rgba(10,27,61,.06);
  --sombra-h:0 4px 12px rgba(10,27,61,.08),0 16px 40px rgba(10,27,61,.10);
}
*{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{font-family:'Montserrat',sans-serif;background:var(--bg);color:var(--tinta);line-height:1.5;-webkit-font-smoothing:antialiased}
.wrap{max-width:1280px;margin:0 auto;padding:0 28px}

header{background:linear-gradient(150deg,#254185 0%,#1a2f63 60%,#13234d 100%);color:#fff;padding:48px 0 130px;position:relative;overflow:hidden}
header::before{content:"";position:absolute;inset:0;background-image:radial-gradient(circle at 1px 1px,rgba(255,255,255,.12) 1px,transparent 0);background-size:28px 28px;opacity:.6}
header .wrap{position:relative;z-index:2}
.brand{display:flex;align-items:center;justify-content:space-between;margin-bottom:40px}
.brand .name{display:flex;align-items:center;gap:11px;font-family:'Archivo',sans-serif;font-weight:900;font-size:19px;letter-spacing:-.02em}
.brand .name .dot{width:32px;height:32px;border-radius:9px;background:#fff;color:var(--azul);display:flex;align-items:center;justify-content:center;font-size:17px}
.brand .meta{font-family:'Space Mono',monospace;font-size:12px;opacity:.7;text-align:right}
.htitle{font-family:'Archivo',sans-serif;font-size:clamp(34px,5vw,58px);font-weight:900;letter-spacing:-.04em;line-height:1.02;max-width:760px}
.htitle em{font-style:normal;color:#9bbfe6}
.hsub{font-size:16px;opacity:.82;margin-top:16px;max-width:560px}
.hbadges{display:flex;gap:10px;margin-top:26px;flex-wrap:wrap}
.hbadge{background:rgba(255,255,255,.13);backdrop-filter:blur(4px);border:1px solid rgba(255,255,255,.18);padding:8px 14px;border-radius:100px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:7px}
.hbadge b{font-family:'Space Mono',monospace;font-weight:700}
.live-dot{width:8px;height:8px;border-radius:50%;background:#00ff95;box-shadow:0 0 10px #00ff95;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}

.kpis{margin-top:18px;position:relative;z-index:5;display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
.kpi{background:var(--blanco);border-radius:18px;padding:22px 22px 20px;box-shadow:var(--sombra);border:1px solid var(--linea);transition:.25s;position:relative;overflow:hidden}
.kpi:hover{transform:translateY(-3px);box-shadow:var(--sombra-h)}
.kpi .ico{width:38px;height:38px;border-radius:11px;background:var(--azul-l);color:var(--azul);display:flex;align-items:center;justify-content:center;font-size:19px;margin-bottom:14px}
.kpi .val{font-family:'Archivo',sans-serif;font-size:34px;font-weight:800;letter-spacing:-.03em;line-height:1}
.kpi .val .u{font-size:15px;color:var(--gris-l);font-weight:600;margin-left:3px}
.kpi .lbl{font-size:13px;color:var(--gris);margin-top:6px;font-weight:500}
.kpi .sub{font-size:11.5px;color:var(--gris-l);margin-top:9px;font-family:'Space Mono',monospace}
.kpi.accent{background:linear-gradient(155deg,var(--azul),var(--azul-d));border-color:transparent}
.kpi.accent .ico{background:rgba(255,255,255,.18);color:#fff}
.kpi.accent .val,.kpi.accent .lbl{color:#fff}
.kpi.accent .sub{color:rgba(255,255,255,.7)}

section{padding:54px 0 0}

/* ===== PANEL DE FILTROS ===== */
.filters{margin:-92px 0 0;position:relative;z-index:6}
.filters details{
  background:#fff;border:1px solid var(--bd);border-radius:14px;
  overflow:hidden;transition:box-shadow .2s ease;
}
.filters details[open]{box-shadow:0 12px 32px rgba(10,27,61,.06)}
.filters summary{
  list-style:none;cursor:pointer;
  display:flex;align-items:center;gap:14px;
  padding:14px 20px;
  font-family:'Montserrat',sans-serif;
  user-select:none;
}
.filters summary::-webkit-details-marker{display:none}
.filters-icon{font-size:18px;color:var(--azul)}
.filters-title{
  font-family:'Archivo',sans-serif;font-weight:800;
  font-size:14px;letter-spacing:-.01em;color:var(--tinta);
}
.filters-badge{
  font-family:'Space Mono',monospace;
  font-size:11px;font-weight:700;letter-spacing:.3px;
  padding:4px 10px;border-radius:6px;
  background:rgba(255,149,0,.12);color:var(--ambar);
  margin-left:auto;
}
.filters-badge.ok{background:rgba(0,184,124,.12);color:var(--verde)}
.filters-badge.neutral{background:var(--gris-2);color:var(--gris)}
.filters-chev{
  font-size:9px;color:var(--gris-l);
  transition:transform .2s ease;
}
.filters details[open] .filters-chev{transform:rotate(180deg)}
.filters-body{padding:6px 20px 20px;border-top:1px solid var(--bd)}
.filters-section-title{
  font-family:'Space Mono',monospace;
  font-size:10px;letter-spacing:1.5px;text-transform:uppercase;
  color:var(--gris);font-weight:700;
  margin:18px 0 8px;
  display:flex;align-items:baseline;gap:10px;
}
.filters-section-title .section-help{
  font-size:11px;text-transform:none;letter-spacing:0;
  color:var(--gris-l);font-weight:400;
}
.filters-grid{
  display:grid;grid-template-columns:repeat(3,1fr);
  gap:10px;margin-top:4px;
}
.filters-grid label.filt{
  display:flex;flex-direction:column;gap:6px;
  padding:10px 12px;
  border:1px solid var(--bd);
  border-radius:10px;
  background:var(--gris-2);
  font-family:'Montserrat',sans-serif;
  transition:all .15s ease;
  cursor:default;
}
.filters-grid label.filt .lbl{
  font-family:'Space Mono',monospace;
  font-size:10.5px;letter-spacing:1.2px;text-transform:uppercase;
  color:var(--gris);font-weight:700;
  display:flex;align-items:center;gap:8px;
}
.filters-grid label.filt .lbl i{
  font-style:normal;color:var(--gris-l);text-transform:none;
  letter-spacing:0;font-weight:400;
}
.filters-grid label.filt .lbl-aside{
  font-family:'Space Mono',monospace;
  font-size:11px;color:var(--gris-l);
  padding:9px 0;
}
.filters-grid label.filt input[type=date],
.filters-grid label.filt input[type=number]{
  font-family:'Space Mono',monospace;
  font-size:13px;font-weight:700;color:var(--tinta);
  padding:8px 11px;border:1px solid var(--bd);
  border-radius:7px;background:#fff;
  transition:all .15s ease;
  width:100%;
}
.filters-grid input:focus{
  outline:none;border-color:var(--azul);
  box-shadow:0 0 0 3px rgba(0,87,255,.12);
}
.filters-grid input.rule-on{
  width:15px;height:15px;cursor:pointer;
  margin:0; flex-shrink:0;
  accent-color:var(--azul);
}

/* Estado del toggle: activo (filtro aplica) vs inactivo (atenuado) */
.filters-grid label.toggle{
  cursor:pointer;
}
.filters-grid label.toggle.on{
  background:#fff;
  border-color:var(--azul-l);
  box-shadow:0 1px 4px rgba(0,87,255,.08), inset 0 0 0 1px rgba(0,87,255,.06);
}
.filters-grid label.toggle.on .lbl{color:var(--azul-d)}
.filters-grid label.toggle.off input[type=number]{
  opacity:.45;
  pointer-events:none;
  background:transparent;
  color:var(--gris);
}
.filters-grid label.toggle.off .lbl-aside{
  opacity:.5;
}
.filters-grid label.toggle.off:hover{
  background:#fff;border-color:var(--gris-l);
}
.filters-grid label.toggle.off:hover .lbl{color:var(--tinta-2)}
.filters-breakdown{
  display:flex;flex-wrap:wrap;gap:8px;
  margin-top:14px;
  padding:10px 12px;
  background:var(--gris-2);border-radius:8px;
  font-family:'Space Mono',monospace;
  font-size:11px;color:var(--gris);
}
.filters-breakdown .bd-item{
  padding:3px 9px;border-radius:5px;background:#fff;
  border:1px solid var(--bd);
}
.filters-breakdown b{color:var(--tinta);font-weight:700}
.filters-actions{
  display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;
}
.btn-apply,.btn-reset,.btn-clear{
  display:inline-flex;align-items:center;gap:6px;
  padding:10px 16px;border-radius:8px;
  font-family:'Space Mono',monospace;
  font-size:11px;font-weight:700;letter-spacing:1px;
  text-transform:uppercase;cursor:pointer;
  text-decoration:none;border:none;
  transition:all .15s ease;
}
.btn-apply{background:var(--azul);color:#fff}
.btn-apply:hover{background:var(--azul-d);transform:translateY(-1px);
  box-shadow:0 8px 20px rgba(0,87,255,.3)}
.btn-reset{background:#fff;color:var(--tinta-2);border:1px solid var(--bd)}
.btn-reset:hover{border-color:var(--azul);color:var(--azul)}
.btn-clear{background:transparent;color:var(--gris)}
.btn-clear:hover{color:var(--rojo)}

@media (max-width:780px){
  .filters-grid{grid-template-columns:repeat(2,1fr)}
}
@media (max-width:480px){
  .filters-grid{grid-template-columns:1fr}
  .filters-badge{display:none}
}
.shead{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:24px;gap:20px}
.shead .l{display:flex;flex-direction:column;gap:5px}
.skicker{font-family:'Space Mono',monospace;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--azul);display:flex;align-items:center;gap:8px}
.skicker::before{content:"";width:22px;height:2px;background:var(--azul);display:inline-block}
.shead h2{font-family:'Archivo',sans-serif;font-size:27px;font-weight:800;letter-spacing:-.03em}
.shead .desc{font-size:14px;color:var(--gris);max-width:420px;text-align:right}

.grid{display:grid;gap:16px}
.g2{grid-template-columns:1fr 1fr}
.g3{grid-template-columns:repeat(3,1fr)}
.g4{grid-template-columns:repeat(4,1fr)}
.g-2-1{grid-template-columns:2fr 1fr}
.g-1-2{grid-template-columns:1fr 2fr}

.card{background:var(--blanco);border:1px solid var(--linea);border-radius:18px;padding:24px;box-shadow:var(--sombra)}
.card.pad0{padding:0;overflow:hidden}
.ctitle{font-family:'Archivo',sans-serif;font-size:16px;font-weight:700;letter-spacing:-.02em;display:flex;align-items:center;justify-content:space-between;margin-bottom:4px}
.ctitle .tag{font-family:'Space Mono',monospace;font-size:11px;font-weight:700;background:var(--azul-ll);color:var(--azul-d);padding:3px 9px;border-radius:6px;letter-spacing:0}
.cdesc{font-size:12.5px;color:var(--gris-l);margin-bottom:18px}

.chart{width:100%}
.bar-row{display:flex;align-items:center;gap:12px;margin-bottom:11px}
.bar-row:last-child{margin-bottom:0}
.bar-label{font-size:12.5px;color:var(--gris);width:120px;flex-shrink:0;text-align:right;font-weight:500}
.bar-track{flex:1;height:26px;background:var(--azul-ll);border-radius:7px;position:relative;overflow:hidden}
.bar-fill{height:100%;background:linear-gradient(90deg,var(--cielo),var(--azul));border-radius:7px;display:flex;align-items:center;justify-content:flex-end;padding-right:9px;color:#fff;font-size:11.5px;font-weight:700;font-family:'Space Mono',monospace;min-width:32px;transition:width 1s cubic-bezier(.4,0,.2,1)}

.legend{display:flex;gap:16px;flex-wrap:wrap;margin-top:16px;padding-top:14px;border-top:1px solid var(--linea)}
.legend .it{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--gris)}
.legend .sw{width:11px;height:11px;border-radius:3px}

svg .gridline{stroke:var(--linea);stroke-width:1;stroke-dasharray:3 4}
svg text{font-family:'Montserrat',sans-serif}
svg .axlbl{font-size:10.5px;fill:var(--gris-l)}
svg .vlbl{font-size:11px;fill:var(--tinta);font-weight:700;font-family:'Space Mono',monospace}

.heatgrid{display:grid;grid-template-columns:34px repeat(17,1fr);gap:3px;margin-top:8px}
.heatcell{aspect-ratio:1;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:9px;font-family:'Space Mono',monospace;color:transparent;transition:.15s;cursor:default}
.heatcell:hover{transform:scale(1.25);z-index:3;color:#fff;box-shadow:0 2px 8px rgba(10,27,61,.3)}
.heatcell.lbl{background:none!important;color:var(--gris-l);font-size:10px;font-weight:700}
.heatcell.hr{background:none!important;color:var(--gris-l);font-size:9px}

#map{width:100%;height:560px;background:var(--azul-ll)}
.map-wrap{position:relative}
.map-ctrls{position:absolute;top:16px;left:16px;z-index:10;background:var(--blanco);border-radius:14px;padding:14px;box-shadow:var(--sombra-h);max-width:240px}
.map-ctrls .t{font-family:'Archivo',sans-serif;font-weight:700;font-size:13px;margin-bottom:10px}
.mtoggle{display:flex;align-items:center;gap:9px;padding:7px 0;cursor:pointer;font-size:13px;color:var(--gris)}
.mtoggle input{accent-color:var(--azul);width:15px;height:15px;cursor:pointer}
.mtoggle .sw{width:22px;height:4px;border-radius:2px;flex-shrink:0}
.map-legend{position:absolute;bottom:16px;left:16px;z-index:10;background:var(--blanco);border-radius:12px;padding:12px 14px;box-shadow:var(--sombra-h);font-size:11.5px}
.map-legend .it{display:flex;align-items:center;gap:8px;margin:4px 0;color:var(--gris)}
.map-nokey{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;background:var(--azul-ll);color:var(--gris);text-align:center;padding:40px}
.map-nokey .big{font-size:46px}
.map-nokey h3{font-family:'Archivo',sans-serif;font-size:19px;color:var(--tinta)}

.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--gris-l);font-weight:700;padding:0 12px 12px;border-bottom:1px solid var(--linea)}
.tbl td{padding:13px 12px;border-bottom:1px solid var(--azul-ll);color:var(--tinta)}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:var(--azul-ll)}
.tbl .num{font-family:'Space Mono',monospace;font-weight:700}
.tbl .rank{width:28px;height:28px;border-radius:8px;background:var(--azul-l);color:var(--azul-d);display:flex;align-items:center;justify-content:center;font-family:'Space Mono',monospace;font-weight:700;font-size:12px}
.tbl .rank.top{background:var(--azul);color:#fff}
.pill{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:100px;font-size:11px;font-weight:600;font-family:'Space Mono',monospace}
.pill.elec{background:#fff4e0;color:#b56a00}
.pill.meca{background:var(--azul-l);color:var(--azul-d)}
.pill.circ{background:#e0f7ef;color:#007a52}
.pill.dir{background:#f0eaff;color:#5b3fc4}
.pill.ok{background:#e0f7ef;color:#007a52}
.pill.bad{background:#ffe4e8;color:#c4234d}
.pill.warn{background:#fff4d6;color:#8a5a00}
.bal{font-family:'Space Mono',monospace;font-weight:700;font-size:12px}

/* ===== Comparativa elec vs mec ===== */
.comp-head{
  display:grid;grid-template-columns:1fr 200px 1fr;
  gap:10px;align-items:center;
  margin-bottom:14px;padding-bottom:10px;
  border-bottom:1px solid var(--bd);
}
.comp-side-h{
  font-family:'Archivo',sans-serif;font-weight:800;
  font-size:14px;letter-spacing:-.01em;
  display:flex;align-items:center;gap:8px;
}
.comp-side-h.mec  { color:var(--azul-d); justify-content:flex-end }
.comp-side-h.elec { color:#b56a00; justify-content:flex-start; grid-column:3 }
.comp-row{
  display:grid;grid-template-columns:1fr 200px 1fr;
  gap:10px;align-items:center;
  padding:9px 0;border-bottom:1px solid var(--gris-2);
}
.comp-row:last-child{border-bottom:none}
.comp-label{
  text-align:center;
  font-family:'Space Mono',monospace;
  font-size:11px;color:var(--gris);
  text-transform:uppercase;letter-spacing:.8px;
  font-weight:700;
}
.comp-bars{display:contents}
.comp-side{
  display:flex;align-items:center;gap:8px;
  min-width:0;
}
.comp-side.mec  { justify-content:flex-end }
.comp-side.elec { justify-content:flex-start }
.comp-val{
  font-family:'Space Mono',monospace;
  font-size:13px;font-weight:700;color:var(--tinta);
  font-variant-numeric:tabular-nums;
  flex-shrink:0;
}
.comp-val .comp-u{
  color:var(--gris-l);font-weight:400;
  margin-left:2px;
}
.comp-bar{
  flex:1;height:10px;border-radius:5px;
  background:var(--gris-2);overflow:hidden;
  min-width:60px;
}
.comp-side.mec .comp-bar  { transform:scaleX(-1) }   /* refleja el rellenado hacia la izquierda */
.comp-fill{
  height:100%;border-radius:5px;
  transition:width .9s cubic-bezier(.16,.84,.42,1.05);
}
.comp-fill.mec  { background:linear-gradient(90deg, var(--azul-l), var(--azul)) }
.comp-fill.elec { background:linear-gradient(90deg, #ffd9a8, var(--ambar)) }
.comp-note{
  margin-top:14px;padding:12px 14px;
  background:var(--azul-ll);border-radius:8px;
  font-size:13px;color:var(--tinta-2);
  line-height:1.5;
}
@media (max-width:780px){
  .comp-head,.comp-row{grid-template-columns:1fr;gap:6px}
  .comp-side-h{justify-content:flex-start !important}
  .comp-side-h.elec{grid-column:1}
  .comp-side{justify-content:flex-start !important}
  .comp-side.mec .comp-bar{transform:none}
  .comp-label{text-align:left}
}
.bal.pos{color:var(--verde)} .bal.neg{color:var(--rojo)} .bal.zero{color:var(--gris-l)}

.donut-wrap{display:flex;align-items:center;gap:24px}
.donut-legend{display:flex;flex-direction:column;gap:11px;flex:1}
.donut-legend .it{display:flex;align-items:center;gap:10px;font-size:13px}
.donut-legend .sw{width:13px;height:13px;border-radius:4px;flex-shrink:0}
.donut-legend .nm{color:var(--gris);flex:1}
.donut-legend .vl{font-family:'Space Mono',monospace;font-weight:700;font-size:13px}
.donut-legend .pc{font-family:'Space Mono',monospace;font-size:11px;color:var(--gris-l);width:42px;text-align:right}

.insight{background:linear-gradient(155deg,var(--azul-ll),var(--azul-l));border:1px solid #d4e3ff;border-radius:16px;padding:20px;display:flex;gap:14px;align-items:flex-start}
.insight .ico{font-size:22px;flex-shrink:0}
.insight .tx{font-size:13.5px;color:var(--tinta);line-height:1.55}
.insight .tx b{color:var(--azul-d);font-weight:700}
.note{background:#fff8ec;border:1px solid #ffe4b8;border-radius:14px;padding:16px 18px;font-size:13px;color:#8a5a00;display:flex;gap:11px;align-items:flex-start;margin-top:16px}
.note .ico{font-size:17px;flex-shrink:0}

.minigrid{display:grid;grid-template-columns:repeat(2,1fr);gap:13px}
.mini{background:var(--azul-ll);border-radius:13px;padding:16px}
.mini .v{font-family:'Archivo',sans-serif;font-size:26px;font-weight:800;letter-spacing:-.03em;color:var(--azul-d)}
.mini .l{font-size:12px;color:var(--gris);margin-top:3px}

footer{margin-top:64px;padding:38px 0;border-top:1px solid var(--linea);display:flex;align-items:center;justify-content:space-between;color:var(--gris-l);font-size:12.5px}
footer .name{display:flex;align-items:center;gap:9px;font-family:'Archivo',sans-serif;font-weight:800;color:var(--tinta);font-size:15px}
footer .name .dot{width:26px;height:26px;border-radius:7px;background:var(--azul);color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px}
footer .meta{font-family:'Space Mono',monospace;text-align:right;line-height:1.7}

.reveal{opacity:0;transform:translateY(16px);transition:opacity .6s,transform .6s}
.reveal.in{opacity:1;transform:none}

@media(max-width:1000px){
  .kpis{grid-template-columns:repeat(2,1fr)}
  .g2,.g3,.g4,.g-2-1,.g-1-2{grid-template-columns:1fr}
  .shead{flex-direction:column;align-items:flex-start} .shead .desc{text-align:left}
  .heatgrid{grid-template-columns:30px repeat(17,1fr)}
}
@media(max-width:560px){
  .wrap{padding:0 16px} .kpis{grid-template-columns:1fr}
  .donut-wrap{flex-direction:column} .bar-label{width:88px}
}
</style>

<header>
  <div class="wrap">
    <div class="brand">
      <div class="name"><span class="dot">◉</span> QroBici</div>
      <div class="meta">
        <span class="live-dot" style="display:inline-block;margin-right:6px"></span>DATOS EN VIVO<br>
        <span id="genfecha"><?= date('d M Y · H:i') ?></span>
      </div>
    </div>
    <div class="skicker" style="color:#9bbfe6;margin-bottom:14px">Inteligencia de movilidad · Sistema de bicicleta pública</div>
    <h1 class="htitle"><?= $TITULO ?></h1>
    <p class="hsub"><?= $SUBTITULO ?></p>
    <div class="hbadges" id="hbadges"></div>
  </div>
</header>

<div class="wrap">

  <!-- ====== PANEL DE FILTROS ====== -->
  <?php
    $f = $filtros_usr;
    $desde_val = '';
    $hasta_val = '';
    if (!empty($cfg['fecha_desde'])) { $desde_val = substr($cfg['fecha_desde'], 0, 10); }
    if (!empty($cfg['fecha_hasta'])) { $hasta_val = substr($cfg['fecha_hasta'], 0, 10); }
    $desc = $DATA['descartes'] ?? null;
    $tot_orig = (int)($DATA['total_original'] ?? 0);
    $tot_desc = $desc ? (int)$desc['total'] : 0;
    $pct_desc = $tot_orig > 0 ? round(100 * $tot_desc / $tot_orig, 1) : 0;
  ?>
  <form class="filters" method="get" action="reporte.php" id="filters-form">
    <input type="hidden" name="f" value="1">
    <details <?= ($tiene_filtros_get || $preset) ? 'open' : '' ?>>
      <summary>
        <span class="filters-icon">⚙</span>
        <span class="filters-title">Filtros del análisis</span>
        <?php if ($tot_desc > 0): ?>
          <span class="filters-badge"><?= number_format($tot_desc) ?> viajes descartados · <?= $pct_desc ?>% del total</span>
        <?php elseif ($alguno_activo): ?>
          <span class="filters-badge ok">filtros activos · 0 descartes</span>
        <?php else: ?>
          <span class="filters-badge neutral">sin filtros · <?= number_format($tot_orig) ?> viajes (datos crudos)</span>
        <?php endif; ?>
        <span class="filters-chev">▼</span>
      </summary>
      <div class="filters-body">

        <div class="filters-section-title">Rango de fechas</div>
        <div class="filters-grid">
          <label class="filt">
            <span class="lbl">Fecha desde</span>
            <input type="date" name="desde" value="<?= htmlspecialchars($desde_val, ENT_QUOTES, 'UTF-8') ?>">
          </label>
          <label class="filt">
            <span class="lbl">Fecha hasta</span>
            <input type="date" name="hasta" value="<?= htmlspecialchars($hasta_val, ENT_QUOTES, 'UTF-8') ?>">
          </label>
        </div>

        <div class="filters-section-title">
          Reglas de descarte
          <span class="section-help">marca el checkbox de cada regla que quieras aplicar</span>
        </div>
        <div class="filters-grid">

          <label class="filt toggle <?= $F_ON['vel_min'] ? 'on' : 'off' ?>">
            <span class="lbl">
              <input type="checkbox" name="vel_min_on" value="1" class="rule-on" <?= $F_ON['vel_min'] ? 'checked' : '' ?>>
              Velocidad mín · <i>km/h</i>
            </span>
            <input type="number" name="vel_min" value="<?= $F_VAL['vel_min'] ?>" min="0" max="80" step="0.5">
          </label>

          <label class="filt toggle <?= $F_ON['vel_max'] ? 'on' : 'off' ?>">
            <span class="lbl">
              <input type="checkbox" name="vel_max_on" value="1" class="rule-on" <?= $F_ON['vel_max'] ? 'checked' : '' ?>>
              Velocidad máx · <i>km/h</i>
            </span>
            <input type="number" name="vel_max" value="<?= $F_VAL['vel_max'] ?>" min="5" max="120" step="1">
          </label>

          <label class="filt toggle <?= $F_ON['dur_min'] ? 'on' : 'off' ?>">
            <span class="lbl">
              <input type="checkbox" name="dur_min_on" value="1" class="rule-on" <?= $F_ON['dur_min'] ? 'checked' : '' ?>>
              Duración mín · <i>seg</i>
            </span>
            <input type="number" name="dur_min" value="<?= $F_VAL['dur_min'] ?>" min="0" step="10">
          </label>

          <label class="filt toggle <?= $F_ON['dur_max'] ? 'on' : 'off' ?>">
            <span class="lbl">
              <input type="checkbox" name="dur_max_on" value="1" class="rule-on" <?= $F_ON['dur_max'] ? 'checked' : '' ?>>
              Duración máx · <i>seg</i>
            </span>
            <input type="number" name="dur_max" value="<?= $F_VAL['dur_max'] ?>" min="60" step="60">
          </label>

          <label class="filt toggle <?= $F_ON['dist_min'] ? 'on' : 'off' ?>">
            <span class="lbl">
              <input type="checkbox" name="dist_min_on" value="1" class="rule-on" <?= $F_ON['dist_min'] ? 'checked' : '' ?>>
              Distancia mín · <i>m</i>
            </span>
            <input type="number" name="dist_min" value="<?= $F_VAL['dist_min'] ?>" min="0" step="50">
          </label>

          <label class="filt toggle <?= $F_ON['coords'] ? 'on' : 'off' ?>">
            <span class="lbl">
              <input type="checkbox" name="coords" value="1" class="rule-on" <?= $F_ON['coords'] ? 'checked' : '' ?>>
              Coords inválidas (0,0)
            </span>
            <span class="lbl-aside">solo descarte, sin valor</span>
          </label>

        </div>

        <?php if ($desc && $tot_desc > 0): ?>
          <div class="filters-breakdown">
            <span class="bd-item">Por <b>velocidad</b>: <?= number_format($desc['velocidad']) ?></span>
            <span class="bd-item">Por <b>duración</b>: <?= number_format($desc['duracion']) ?></span>
            <span class="bd-item">Por <b>distancia</b>: <?= number_format($desc['distancia']) ?></span>
            <span class="bd-item">Por <b>coordenadas</b>: <?= number_format($desc['coordenadas']) ?></span>
          </div>
        <?php endif; ?>

        <div class="filters-actions">
          <button type="submit" class="btn-apply">Aplicar selección</button>
          <button type="button" class="btn-reset" id="btn-mark-all">Marcar todos</button>
          <button type="button" class="btn-reset" id="btn-unmark-all">Desmarcar todos</button>
          <a href="reporte.php?preset=reco&f=1" class="btn-reset">Preset recomendado</a>
          <a href="reporte.php" class="btn-clear">Quitar todo (datos crudos)</a>
        </div>
      </div>
    </details>
  </form>

  <script>
  // Toggle visual de cada filtro: si el checkbox está marcado la fila se ve
  // "encendida"; si no, se atenúa y el input no se aplica (PHP lee el checkbox).
  document.querySelectorAll('.filters-grid label.toggle').forEach(lbl => {
    const cb = lbl.querySelector('input.rule-on');
    if (!cb) return;
    const sync = () => { lbl.classList.toggle('on', cb.checked); lbl.classList.toggle('off', !cb.checked); };
    cb.addEventListener('change', sync);
    // Permitir click en el input numérico sin disparar el checkbox del label padre
    const num = lbl.querySelector('input[type=number]');
    if (num) {
      num.addEventListener('click',  e => e.stopPropagation());
      num.addEventListener('focus',  e => { cb.checked = true; sync(); });
    }
  });
  // Botones bulk
  document.getElementById('btn-mark-all')?.addEventListener('click', () => {
    document.querySelectorAll('.filters-grid input.rule-on').forEach(cb => {
      cb.checked = true; cb.dispatchEvent(new Event('change'));
    });
  });
  document.getElementById('btn-unmark-all')?.addEventListener('click', () => {
    document.querySelectorAll('.filters-grid input.rule-on').forEach(cb => {
      cb.checked = false; cb.dispatchEvent(new Event('change'));
    });
  });
  </script>

  <!-- KPIs -->
  <div class="kpis" id="kpis"></div>

  <!-- 1. PULSO -->
  <section>
    <div class="shead reveal">
      <div class="l"><span class="skicker">01 · Pulso de operación</span><h2>¿Cuánto se mueve el sistema?</h2></div>
      <div class="desc">Volumen de viajes y kilómetros recorridos durante el periodo analizado.</div>
    </div>
    <div class="grid g-2-1">
      <div class="card reveal"><div class="ctitle">Viajes por día <span class="tag" id="tag-dia"></span></div><div class="cdesc">Conteo diario de viajes y kilómetros acumulados</div><div id="chart-dia"></div></div>
      <div class="card reveal"><div class="ctitle">Distribución semanal</div><div class="cdesc">Viajes acumulados por día de la semana</div><div id="chart-dow"></div></div>
    </div>
    <div class="grid g-1-2" style="margin-top:16px">
      <div class="card reveal"><div class="ctitle">Mezcla de flota</div><div class="cdesc">Viajes por tipo de bicicleta</div><div id="donut-tipo" class="donut-wrap"></div></div>
      <div class="card reveal"><div class="ctitle">Curva horaria de demanda <span class="tag">06:00–22:00</span></div><div class="cdesc">Viajes iniciados por hora del día — define los picos operativos</div><div id="chart-hora"></div></div>
    </div>
    <div class="card reveal" style="margin-top:16px">
      <div class="ctitle">Velocidades y distancias por tipo de bicicleta <span class="tag" id="tag-tipo-stats"></span></div>
      <div class="cdesc">Comparativo de desempeño operativo entre la flota mecánica y la eléctrica</div>
      <div id="tipo-compare" style="margin-top:12px"></div>
    </div>
    <div class="insight reveal" style="margin-top:16px" id="insight-pulso"></div>
  </section>

  <!-- 2. MAPA -->
  <section>
    <div class="shead reveal">
      <div class="l"><span class="skicker">02 · Recorridos georreferenciados</span><h2>Mapa de rutas y estaciones</h2></div>
      <div class="desc">Trazado real de los viajes con datos de recorrido, sobre imagen satelital.</div>
    </div>
    <div class="card pad0 reveal">
      <div class="map-wrap">
        <div class="map-ctrls" id="mapctrls" style="display:none">
          <div class="t">Capas del mapa</div>
          <label class="mtoggle"><input type="checkbox" id="tg-rutas" checked><span class="sw" style="background:var(--azul)"></span> Recorridos (<span id="n-rutas"></span>)</label>
          <label class="mtoggle"><input type="checkbox" id="tg-elec"><span class="sw" style="background:var(--ambar)"></span> Solo eléctricas</label>
          <label class="mtoggle"><input type="checkbox" id="tg-est" checked><span class="sw" style="background:var(--azul)"></span> Estaciones (<span id="n-est"></span>)</label>
          <label class="mtoggle"><input type="checkbox" id="tg-heat"><span class="sw" style="background:linear-gradient(90deg,#2a9eda,#ce3a2b)"></span> Mapa de calor</label>
        </div>
        <div class="map-legend" id="maplegend" style="display:none">
          <div class="it"><span style="width:18px;height:3px;background:var(--azul);border-radius:2px"></span> Ruta en bici mecánica</div>
          <div class="it"><span style="width:18px;height:3px;background:var(--ambar);border-radius:2px"></span> Ruta en bici eléctrica</div>
          <div class="it"><span style="width:12px;height:12px;border-radius:50%;background:#fff;border:3px solid var(--azul)"></span> Estación (tamaño ∝ uso)</div>
        </div>
        <div id="map"></div>
        <div class="map-nokey" id="mapnokey" style="display:none">
          <div class="big">🗺️</div>
          <h3>No se pudo cargar el mapa</h3>
          <p>Verifica que la <code>google_maps_api_key</code> en <code>config.php</code> sea válida y tenga habilitada la <i>Maps JavaScript API</i>.</p>
        </div>
      </div>
    </div>
    <div class="grid g3 reveal" style="margin-top:16px" id="ruta-destacadas"></div>
  </section>

  <!-- 3. ESTACIONES -->
  <section>
    <div class="shead reveal">
      <div class="l"><span class="skicker">03 · Red de estaciones</span><h2>Desempeño por estación</h2></div>
      <div class="desc">Salidas, llegadas y balance de cada estación. El balance revela dónde se acumulan o faltan bicicletas.</div>
    </div>
    <div class="grid g-2-1">
      <div class="card reveal"><div class="ctitle">Ranking de estaciones por uso total <span class="tag" id="tag-est"></span></div><div class="cdesc">Salidas + llegadas · ordenado por volumen</div><div style="overflow-x:auto"><table class="tbl" id="tbl-est"></table></div></div>
      <div class="card reveal"><div class="ctitle">Balance de rebalanceo</div><div class="cdesc">Estaciones que ganan (+) o pierden (−) bicicletas. Útil para planear redistribución.</div><div id="chart-balance"></div><div class="note"><span class="ico">⚙️</span><span>Un balance muy negativo indica que la estación se vacía y requiere reabastecimiento; uno muy positivo, que se satura.</span></div></div>
    </div>
    <div class="card reveal" style="margin-top:16px">
      <div class="ctitle">Corredores más transitados <span class="tag">Top 12 pares Origen → Destino</span></div>
      <div class="cdesc">Rutas entre estaciones con mayor número de viajes — incluye viajes circulares (misma estación)</div>
      <div style="overflow-x:auto"><table class="tbl" id="tbl-od"></table></div>
    </div>
  </section>

  <!-- 4. DEMOGRAFÍA -->
  <section>
    <div class="shead reveal">
      <div class="l"><span class="skicker">04 · Perfil de usuarios</span><h2>¿Quién usa QroBici?</h2></div>
      <div class="desc">Edad y sexo derivados de la CURP registrada en cada viaje.</div>
    </div>
    <div class="grid g3">
      <div class="card reveal"><div class="ctitle">Rango de edad</div><div class="cdesc">Viajes por grupo etario</div><div id="chart-edad"></div></div>
      <div class="card reveal"><div class="ctitle">Distribución por sexo</div><div class="cdesc">Proporción de viajes H / M</div><div id="donut-sexo" class="donut-wrap"></div></div>
      <div class="card reveal"><div class="ctitle">Recurrencia de usuarios</div><div class="cdesc">¿Cuántos viajes hace cada persona?</div><div id="chart-freq"></div></div>
    </div>
    <div class="grid g-2-1 reveal" style="margin-top:16px">
      <div class="card"><div class="ctitle">Edad cruzada con sexo</div><div class="cdesc">Composición de cada rango etario</div><div id="chart-edadsexo"></div></div>
      <div class="card"><div class="ctitle">Usuarios más activos <span class="tag">Top 10</span></div><div class="cdesc">Por número de viajes en el periodo</div><div style="overflow-x:auto"><table class="tbl" id="tbl-users"></table></div></div>
    </div>
  </section>

  <!-- 5. PATRONES -->
  <section>
    <div class="shead reveal">
      <div class="l"><span class="skicker">05 · Patrones de viaje</span><h2>Duración, distancia y temporalidad</h2></div>
      <div class="desc">Cómo son los viajes y cuándo ocurren.</div>
    </div>
    <div class="grid g2">
      <div class="card reveal"><div class="ctitle">Distribución de duración</div><div class="cdesc">Viajes agrupados por minutos de uso</div><div id="chart-dur"></div></div>
      <div class="card reveal"><div class="ctitle">Distribución de distancia</div><div class="cdesc">Viajes agrupados por kilómetros recorridos</div><div id="chart-distk"></div></div>
    </div>
    <div class="card reveal" style="margin-top:16px">
      <div class="ctitle">Mapa de calor: hora × día de la semana</div>
      <div class="cdesc">Intensidad de uso — identifica las ventanas de máxima demanda para programar mantenimiento y rebalanceo</div>
      <div class="heatgrid" id="heatgrid"></div>
      <div class="legend">
        <div class="it"><span class="sw" style="background:var(--azul-ll)"></span> Sin uso</div>
        <div class="it"><span class="sw" style="background:#9bbfe6"></span> Bajo</div>
        <div class="it"><span class="sw" style="background:var(--azul)"></span> Medio</div>
        <div class="it"><span class="sw" style="background:var(--azul-d)"></span> Alto</div>
        <div class="it"><span class="sw" style="background:#0d1733"></span> Pico</div>
      </div>
    </div>
    <div class="grid g2 reveal" style="margin-top:16px">
      <div class="card"><div class="ctitle">Planes utilizados en viajes</div><div class="cdesc">Viajes por tipo de plan registrado al momento del viaje</div><div id="chart-plan"></div></div>
      <div class="card"><div class="ctitle">Eléctrica vs mecánica a lo largo del día</div><div class="cdesc">¿En qué horario se prefiere cada tipo?</div><div id="chart-tipohora"></div></div>
    </div>
    <div class="insight reveal" style="margin-top:16px" id="insight-patrones"></div>
  </section>

  <!-- 6. IMPACTO -->
  <section>
    <div class="shead reveal">
      <div class="l"><span class="skicker">06 · Impacto y calidad de datos</span><h2>Huella del sistema y notas técnicas</h2></div>
      <div class="desc">Beneficio ambiental estimado y observaciones sobre la integridad de los datos.</div>
    </div>
    <div class="grid g2">
      <div class="card reveal"><div class="ctitle">Impacto estimado del periodo</div><div class="cdesc">Estimaciones a partir de los kilómetros recorridos</div><div class="minigrid" id="impacto-mini"></div></div>
      <div class="card reveal"><div class="ctitle">Calidad y cobertura de datos</div><div class="cdesc">Completitud de los campos clave en la exportación</div><div id="chart-calidad"></div></div>
    </div>
    <div class="note reveal" id="notas-tecnicas" style="margin-top:16px"></div>
  </section>

  <!-- 7. SUSCRIPCIONES (NUEVA) -->
  <section id="sec-suscripciones">
    <div class="shead reveal">
      <div class="l"><span class="skicker">07 · Suscripciones y planes</span><h2>Monetización y vigencia de planes</h2></div>
      <div class="desc">Análisis de la vista <code>planes</code>: altas, pagos, vigencias y renovaciones.</div>
    </div>
    <div class="grid g4" id="kpis-planes"></div>
    <div class="grid g2 reveal" style="margin-top:16px">
      <div class="card"><div class="ctitle">Estado actual de planes</div><div class="cdesc">Vigentes, caducados, no iniciados e inactivos</div><div id="donut-estado" class="donut-wrap"></div></div>
      <div class="card"><div class="ctitle">Tasa de pago</div><div class="cdesc">Planes con pago confirmado vs. pendientes</div><div id="donut-pago" class="donut-wrap"></div></div>
    </div>
    <div class="card reveal" style="margin-top:16px">
      <div class="ctitle">Desempeño por tipo de plan <span class="tag" id="tag-planes-tipo"></span></div>
      <div class="cdesc">Volumen, usuarios alcanzados, vigentes y tasa de pago para cada tipo de suscripción</div>
      <div style="overflow-x:auto"><table class="tbl" id="tbl-planes"></table></div>
    </div>
    <div class="card reveal" style="margin-top:16px">
      <div class="ctitle">Viajes por tipo de plan <span class="tag" id="tag-viajesplan"></span></div>
      <div class="cdesc">Cada viaje se asigna al plan que estaba vigente en su fecha. Los viajes de usuarios sin plan vigente que cubra esa fecha (cuentas operativas, accesos legacy o demos) se catalogan como <b>Pruebas</b>.</div>
      <div id="chart-viajesplan" style="margin-top:8px"></div>
    </div>
    <div class="grid g-2-1 reveal" style="margin-top:16px">
      <div class="card"><div class="ctitle">Altas de planes por día</div><div class="cdesc">Nuevos planes creados, con altas pagadas resaltadas</div><div id="chart-altas"></div></div>
      <div class="card"><div class="ctitle">Próximos vencimientos <span class="tag">7 días</span></div><div class="cdesc">Planes vigentes que caducan pronto — oportunidad de renovación</div><div id="tbl-caducan" style="max-height:340px;overflow:auto"></div></div>
    </div>
    <div class="insight reveal" style="margin-top:16px" id="insight-planes"></div>
  </section>

  <section id="sec-calificaciones">
    <div class="shead reveal">
      <div class="l"><span class="skicker">08 · Calificaciones de usuarios</span><h2>Lo que opinan quienes pedalean</h2></div>
      <div class="desc">Puntajes derivados de la encuesta opt-in al cerrar el viaje. Solo se promedian las respuestas de usuarios que marcaron "califico = sí".</div>
    </div>
    <div id="calif-wrap"><!-- el JS rellena aquí --></div>
  </section>

  <footer>
    <div class="name"><span class="dot">◉</span> QroBici Analytics</div>
    <div class="meta" id="footmeta"></div>
  </footer>
</div>

<script>
const DATA = <?= $DATA_JSON ?>;
const GMAPS_KEY = <?= json_encode($GMAP_KEY) ?>;
const MAP_ID    = <?= json_encode($GMAP_MAPID) ?>;

/* ------------------ helpers ------------------ */
const $ = id => document.getElementById(id);
const fmt = n => Number(n).toLocaleString('es-MX');
const fmt1 = n => Number(n).toLocaleString('es-MX',{minimumFractionDigits:1,maximumFractionDigits:1});
const BLUES = ['#9bbfe6','#5b8fd0','#2a9eda','#254185','#1a2f63','#13234d','#0d1733'];

/* ------------------ HEADER + KPIs ------------------ */
function renderHeader(){
  const k = DATA.kpis;
  $('footmeta').innerHTML = `Periodo · ${k.fecha_min} – ${k.fecha_max}<br>${fmt(k.total_viajes)} viajes · ${fmt(k.usuarios_unicos)} usuarios · ${k.estaciones_activas} estaciones`;
  $('hbadges').innerHTML = `
    <div class="hbadge">📅 <b>${k.fecha_min} – ${k.fecha_max}</b></div>
    <div class="hbadge">🚲 <b>${fmt(k.total_viajes)}</b> viajes</div>
    <div class="hbadge">👥 <b>${fmt(k.usuarios_unicos)}</b> usuarios</div>
    <div class="hbadge">📍 <b>${k.estaciones_activas}</b> estaciones</div>`;
  const kpis = [
    {ico:'🚲',val:fmt(k.total_viajes),u:'',lbl:'Viajes totales',sub:`${k.dias_operacion} días de operación`,accent:true},
    {ico:'📏',val:fmt1(k.dist_total_km),u:'km',lbl:'Distancia recorrida',sub:`prom. ${fmt(k.dist_prom_m)} m / viaje`},
    {ico:'⏱️',val:fmt1(k.dur_total_horas),u:'h',lbl:'Tiempo de pedaleo',sub:`prom. ${fmt1(k.dur_prom_min)} min / viaje`},
    {ico:'👥',val:fmt(k.usuarios_unicos),u:'',lbl:'Usuarios únicos',sub:`${fmt1(k.total_viajes/k.usuarios_unicos)} viajes / usuario`},
    {ico:'⚡',val:k.vel_prom,u:'km/h',lbl:'Velocidad promedio',sub:`mediana ${k.vel_mediana} · p75 ${k.vel_p75} · efectiva ${k.vel_efectiva} km/h`},
    {ico:'🔄',val:fmt(k.viajes_circulares),u:'',lbl:'Viajes circulares',sub:`${fmt1(100*k.viajes_circulares/k.total_viajes)}% mismo origen/destino`},
    {ico:'🌱',val:fmt1(k.co2_kg),u:'kg',lbl:'CO₂ evitado (est.)',sub:`vs. recorrido en automóvil`},
    {ico:'🎂',val:k.edad_prom,u:'años',lbl:'Edad promedio',sub:`${k.pct_curp}% de viajes con CURP`},
  ];
  $('kpis').innerHTML = kpis.map(x=>`
    <div class="kpi ${x.accent?'accent':''}">
      <div class="ico">${x.ico}</div>
      <div class="val">${x.val}<span class="u">${x.u}</span></div>
      <div class="lbl">${x.lbl}</div>
      <div class="sub">${x.sub}</div>
    </div>`).join('');
}

/* ------------------ CHARTS ------------------ */
function lineChart(elId, series){
  const W=640,H=230,pad={t:18,r:18,b:34,l:42};
  const iw=W-pad.l-pad.r,ih=H-pad.t-pad.b;
  const max=Math.max(...series.map(d=>d.v))*1.12||1;
  const n=series.length;
  const x=i=>pad.l+(n<=1?iw/2:iw*i/(n-1));
  const y=v=>pad.t+ih-ih*v/max;
  let grid='',xlbls='',dots='';
  for(let g=0;g<=4;g++){const gy=pad.t+ih-ih*g/4;
    grid+=`<line class="gridline" x1="${pad.l}" y1="${gy}" x2="${W-pad.r}" y2="${gy}"/>`;
    grid+=`<text class="axlbl" x="${pad.l-8}" y="${gy+3}" text-anchor="end">${Math.round(max*g/4)}</text>`;}
  const path=series.map((d,i)=>`${i?'L':'M'}${x(i)},${y(d.v)}`).join(' ');
  const area=`<path d="${path} L${x(n-1)},${pad.t+ih} L${x(0)},${pad.t+ih} Z" fill="url(#grad-${elId})" opacity=".5"/>`;
  const line=`<path d="${path}" fill="none" stroke="var(--azul)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>`;
  series.forEach((d,i)=>{
    dots+=`<circle cx="${x(i)}" cy="${y(d.v)}" r="3.5" fill="#fff" stroke="var(--azul)" stroke-width="2"><title>${d.label}: ${d.v}</title></circle>`;
    if(n<=12||i%2===0) xlbls+=`<text class="axlbl" x="${x(i)}" y="${H-12}" text-anchor="middle">${d.label}</text>`;
  });
  $(elId).innerHTML=`<svg viewBox="0 0 ${W} ${H}" class="chart">
    <defs><linearGradient id="grad-${elId}" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#2a9eda" stop-opacity=".35"/>
      <stop offset="1" stop-color="#2a9eda" stop-opacity="0"/></linearGradient></defs>
    ${grid}${area}${line}${dots}${xlbls}</svg>`;
}

function barChartV(elId, series, opts={}){
  const W=640,H=opts.h||230,pad={t:18,r:14,b:36,l:42};
  const iw=W-pad.l-pad.r,ih=H-pad.t-pad.b;
  const max=Math.max(...series.map(d=>d.v))*1.14||1;
  const n=series.length,gap=opts.gap||0.32;
  const bw=iw/n*(1-gap);
  let grid='',bars='';
  for(let g=0;g<=4;g++){const gy=pad.t+ih-ih*g/4;
    grid+=`<line class="gridline" x1="${pad.l}" y1="${gy}" x2="${W-pad.r}" y2="${gy}"/>`;
    grid+=`<text class="axlbl" x="${pad.l-8}" y="${gy+3}" text-anchor="end">${Math.round(max*g/4)}</text>`;}
  series.forEach((d,i)=>{
    const cx=pad.l+iw*i/n+iw/n/2;const bh=ih*d.v/max;const by=pad.t+ih-bh;
    const col=d.color||'var(--azul)';
    bars+=`<rect x="${cx-bw/2}" y="${by}" width="${bw}" height="${bh}" rx="5" fill="${col}"><title>${d.label}: ${d.v}</title></rect>`;
    if(bh>16) bars+=`<text class="vlbl" x="${cx}" y="${by-6}" text-anchor="middle">${d.v}</text>`;
    bars+=`<text class="axlbl" x="${cx}" y="${H-14}" text-anchor="middle">${d.label}</text>`;
  });
  $(elId).innerHTML=`<svg viewBox="0 0 ${W} ${H}" class="chart">${grid}${bars}</svg>`;
}

function barChartGrouped(elId, cats, sA, sB, opts={}){
  const W=640,H=opts.h||240,pad={t:18,r:14,b:36,l:42};
  const iw=W-pad.l-pad.r,ih=H-pad.t-pad.b;
  const max=Math.max(...sA.concat(sB))*1.16||1;
  const n=cats.length,slot=iw/n,bw=slot*0.30;
  let grid='',bars='';
  for(let g=0;g<=4;g++){const gy=pad.t+ih-ih*g/4;
    grid+=`<line class="gridline" x1="${pad.l}" y1="${gy}" x2="${W-pad.r}" y2="${gy}"/>`;
    grid+=`<text class="axlbl" x="${pad.l-8}" y="${gy+3}" text-anchor="end">${Math.round(max*g/4)}</text>`;}
  cats.forEach((c,i)=>{const cx=pad.l+slot*i+slot/2;
    [[sA[i],-bw*0.55,opts.cA||'var(--azul)'],[sB[i],bw*0.55,opts.cB||'var(--ambar)']].forEach(([v,off,col])=>{
      const bh=ih*v/max,by=pad.t+ih-bh;
      bars+=`<rect x="${cx+off-bw/2}" y="${by}" width="${bw}" height="${bh}" rx="4" fill="${col}"><title>${c}: ${v}</title></rect>`;
      if(bh>14)bars+=`<text class="vlbl" x="${cx+off}" y="${by-5}" text-anchor="middle" style="font-size:9.5px">${v}</text>`;
    });
    bars+=`<text class="axlbl" x="${cx}" y="${H-14}" text-anchor="middle">${c}</text>`;
  });
  $(elId).innerHTML=`<svg viewBox="0 0 ${W} ${H}" class="chart">${grid}${bars}</svg>`;
}

function divergingBar(elId, series){
  const max=Math.max(...series.map(d=>Math.abs(d.v)))||1;
  $(elId).innerHTML = series.map(d=>{
    const pct=Math.abs(d.v)/max*50; const pos=d.v>=0;
    return `<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
      <div style="width:96px;font-size:11.5px;color:var(--gris);text-align:right;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${d.label}</div>
      <div style="flex:1;display:flex;align-items:center;height:20px;position:relative">
        <div style="position:absolute;left:50%;top:0;bottom:0;width:1px;background:var(--linea)"></div>
        <div style="width:50%;display:flex;justify-content:flex-end">${!pos?`<div style="width:${pct}%;height:14px;background:var(--rojo);border-radius:4px 0 0 4px"></div>`:''}</div>
        <div style="width:50%">${pos?`<div style="width:${pct}%;height:14px;background:var(--verde);border-radius:0 4px 4px 0"></div>`:''}</div>
      </div>
      <div class="bal ${pos?(d.v===0?'zero':'pos'):'neg'}" style="width:38px">${pos&&d.v!==0?'+':''}${d.v}</div>
    </div>`;
  }).join('');
}

function barsH(elId, series){
  const max=Math.max(...series.map(d=>d.v))||1;
  $(elId).innerHTML = series.map(d=>`
    <div class="bar-row">
      <div class="bar-label">${d.label}</div>
      <div class="bar-track"><div class="bar-fill" style="width:${Math.max(d.v/max*100,3)}%">${d.v>0?d.v:''}</div></div>
    </div>`).join('');
}

function donut(elId, items, opts={}){
  const total=items.reduce((s,i)=>s+i.v,0);
  if(total===0){ $(elId).innerHTML='<div style="color:var(--gris-l);padding:20px;text-align:center">Sin datos</div>'; return; }
  const R=58,r=36,cx=70,cy=70;
  let acc=0,segs='';
  items.forEach(it=>{
    const frac=it.v/total;
    const a0=acc*2*Math.PI-Math.PI/2;
    const a1=(acc+frac)*2*Math.PI-Math.PI/2; acc+=frac;
    const large=frac>0.5?1:0;
    const x0=cx+R*Math.cos(a0),y0=cy+R*Math.sin(a0);
    const x1=cx+R*Math.cos(a1),y1=cy+R*Math.sin(a1);
    const xi1=cx+r*Math.cos(a1),yi1=cy+r*Math.sin(a1);
    const xi0=cx+r*Math.cos(a0),yi0=cy+r*Math.sin(a0);
    segs+=`<path d="M${x0},${y0} A${R},${R} 0 ${large} 1 ${x1},${y1} L${xi1},${yi1} A${r},${r} 0 ${large} 0 ${xi0},${yi0} Z" fill="${it.color}"><title>${it.label}: ${it.v} (${(frac*100).toFixed(1)}%)</title></path>`;
  });
  const legend=items.map(it=>`<div class="it"><span class="sw" style="background:${it.color}"></span><span class="nm">${it.label}</span><span class="vl">${fmt(it.v)}</span><span class="pc">${(it.v/total*100).toFixed(1)}%</span></div>`).join('');
  $(elId).innerHTML=`<svg viewBox="0 0 140 140" style="width:140px;height:140px;flex-shrink:0">${segs}
    <text x="70" y="66" text-anchor="middle" style="font-family:'Archivo';font-weight:800;font-size:22px;fill:var(--tinta)">${fmt(total)}</text>
    <text x="70" y="82" text-anchor="middle" style="font-size:9px;fill:var(--gris-l);font-family:'Space Mono'">${opts.unit||'TOTAL'}</text>
  </svg><div class="donut-legend">${legend}</div>`;
}

/* ------------------ SECCIONES ------------------ */
function renderPulso(){
  $('tag-dia').textContent=`${DATA.serie_dia.length} días`;
  const dmap={Mon:'Lun',Tue:'Mar',Wed:'Mié',Thu:'Jue',Fri:'Vie',Sat:'Sáb',Sun:'Dom'};
  lineChart('chart-dia', DATA.serie_dia.map(d=>{
    const parts=d.dia.split(' ');return{label:(dmap[parts[0]]||parts[0])+' '+(parts[1]||''),v:d.viajes};}));
  barChartV('chart-dow', DATA.serie_dow.map((d,i)=>({label:d.dia,v:d.viajes,color:BLUES[Math.min(3+Math.floor(i/2),6)]})));
  barChartV('chart-hora', DATA.serie_hora.map(d=>{
    const mx=Math.max(...DATA.serie_hora.map(x=>x.viajes));let c=BLUES[1];
    if(d.viajes>mx*0.75)c=BLUES[5];else if(d.viajes>mx*0.5)c=BLUES[4];else if(d.viajes>mx*0.25)c=BLUES[3];
    return{label:d.hora,v:d.viajes,color:c};}),{gap:0.25});
  donut('donut-tipo', DATA.tipo_dist.map(t=>({label:t.tipo,v:t.viajes,color:t.tipo==='Eléctrica'?'var(--ambar)':'var(--azul)'})),{unit:'VIAJES'});
  const pico=DATA.serie_hora.reduce((a,b)=>b.viajes>a.viajes?b:a);
  const diaMax=DATA.serie_dia.reduce((a,b)=>b.viajes>a.viajes?b:a);
  const k=DATA.kpis;
  $('insight-pulso').innerHTML=`<span class="ico">💡</span><span class="tx">El sistema registró un promedio de <b>${Math.round(k.total_viajes/k.dias_operacion)} viajes diarios</b>, con su pico a las <b>${pico.hora}:00 h</b> (${pico.viajes} viajes). La flota es predominantemente <b>mecánica (${k.pct_mecanica}%)</b> frente a la eléctrica (${k.pct_electrica}%). El día más activo acumuló <b>${diaMax.viajes} viajes y ${fmt1(diaMax.km)} km</b>.</span>`;

  // === Comparativa por tipo (mec vs elec) ===
  const pt = k.por_tipo || {};
  const m = pt.mecanica  || {viajes:0};
  const e = pt.electrica || {viajes:0};
  $('tag-tipo-stats').textContent = `${fmt(m.viajes + e.viajes)} viajes clasificados`;

  // helper: barra comparativa horizontal centrada (mec izq / elec der)
  const compRow = (label, valM, valE, unit, fmtFn) => {
    const max = Math.max(valM, valE) || 1;
    const wM = (valM / max * 100).toFixed(1);
    const wE = (valE / max * 100).toFixed(1);
    const fmtVal = fmtFn || (n => fmt1(n));
    return `
      <div class="comp-row">
        <div class="comp-label">${label}</div>
        <div class="comp-bars">
          <div class="comp-side mec">
            <span class="comp-val">${fmtVal(valM)}${unit?'<span class="comp-u">'+unit+'</span>':''}</span>
            <div class="comp-bar"><div class="comp-fill mec" style="width:${wM}%"></div></div>
          </div>
          <div class="comp-side elec">
            <div class="comp-bar"><div class="comp-fill elec" style="width:${wE}%"></div></div>
            <span class="comp-val">${fmtVal(valE)}${unit?'<span class="comp-u">'+unit+'</span>':''}</span>
          </div>
        </div>
      </div>`;
  };

  const rows = [
    {l:'Viajes',                     m:m.viajes,        e:e.viajes,        u:'',     f:fmt},
    {l:'Km totales',                 m:m.km_total,      e:e.km_total,      u:' km',  f:fmt1},
    {l:'Horas totales',              m:m.horas_total,   e:e.horas_total,   u:' h',   f:fmt1},
    {l:'Distancia prom · viaje',     m:m.dist_prom_m,   e:e.dist_prom_m,   u:' m',   f:fmt},
    {l:'Duración prom · viaje',      m:m.dur_prom_min,  e:e.dur_prom_min,  u:' min', f:fmt1},
    {l:'Velocidad promedio',         m:m.vel_prom,      e:e.vel_prom,      u:' km/h',f:fmt1},
    {l:'Velocidad mediana',          m:m.vel_mediana,   e:e.vel_mediana,   u:' km/h',f:fmt1},
    {l:'Velocidad p75',              m:m.vel_p75,       e:e.vel_p75,       u:' km/h',f:fmt1},
    {l:'Velocidad efectiva',         m:m.vel_efectiva,  e:e.vel_efectiva,  u:' km/h',f:fmt1},
  ];

  let html = `
    <div class="comp-head">
      <div class="comp-side-h mec">🚲 <b>Mecánica</b></div>
      <div class="comp-side-h elec">⚡ <b>Eléctrica</b></div>
    </div>
    ${rows.map(r => compRow(r.l, r.m, r.e, r.u, r.f)).join('')}
  `;

  // insight breve comparativo
  const diff_vel = e.vel_efectiva - m.vel_efectiva;
  const diff_dist = e.dist_prom_m - m.dist_prom_m;
  let nota = '';
  if (e.viajes > 0 && m.viajes > 0) {
    const direc = diff_vel >= 0 ? 'más rápida' : 'más lenta';
    const direcD = diff_dist >= 0 ? 'recorren más' : 'recorren menos';
    nota = `<div class="comp-note">⚡ La flota eléctrica circula <b>${Math.abs(diff_vel).toFixed(1)} km/h ${direc}</b> que la mecánica, y los viajes eléctricos <b>${direcD} ${Math.abs(diff_dist)} m</b> en promedio.</div>`;
  }

  $('tipo-compare').innerHTML = html + nota;
}

function renderEstaciones(){
  $('tag-est').textContent=`${DATA.estaciones.length} estaciones`;
  $('tbl-est').innerHTML=`<thead><tr><th>#</th><th>Estación</th><th>Salidas</th><th>Llegadas</th><th>Uso total</th><th>Balance</th></tr></thead>
    <tbody>${DATA.estaciones.map((e,i)=>`<tr>
      <td><span class="rank ${i<3?'top':''}">${i+1}</span></td>
      <td style="font-weight:600">${e.nombre}</td>
      <td class="num">${e.salidas}</td><td class="num">${e.llegadas}</td>
      <td class="num" style="color:var(--azul-d)">${e.total}</td>
      <td><span class="bal ${e.balance>0?'pos':e.balance<0?'neg':'zero'}">${e.balance>0?'+':''}${e.balance}</span></td>
    </tr>`).join('')}</tbody>`;
  const bal=[...DATA.estaciones].sort((a,b)=>Math.abs(b.balance)-Math.abs(a.balance)).slice(0,12).sort((a,b)=>b.balance-a.balance);
  divergingBar('chart-balance', bal.map(e=>({label:e.nombre,v:e.balance})));
  $('tbl-od').innerHTML=`<thead><tr><th>#</th><th>Origen</th><th></th><th>Destino</th><th>Tipo</th><th>Viajes</th></tr></thead>
    <tbody>${DATA.pares_od.slice(0,12).map((p,i)=>`<tr>
      <td><span class="rank ${i<3?'top':''}">${i+1}</span></td>
      <td style="font-weight:600">${p.origen}</td><td style="color:var(--gris-l)">→</td>
      <td style="font-weight:600">${p.destino}</td>
      <td>${p.circular?'<span class="pill circ">↻ Circular</span>':'<span class="pill dir">→ Directo</span>'}</td>
      <td class="num" style="color:var(--azul-d)">${p.viajes}</td></tr>`).join('')}</tbody>`;
}

function renderDemo(){
  barChartV('chart-edad', DATA.edad_dist.map((d,i)=>({label:d.rango,v:d.cantidad,color:BLUES[Math.min(2+i,6)]})),{h:220});
  donut('donut-sexo', [
    {label:'Hombres',v:DATA.sexo_dist.Hombres,color:'var(--azul)'},
    {label:'Mujeres',v:DATA.sexo_dist.Mujeres,color:'var(--rosa)'},
    {label:'Sin dato CURP',v:DATA.sexo_dist['Sin dato'],color:'var(--gris-l)'},
  ],{unit:'VIAJES'});
  const fd=DATA.freq_dist;
  barsH('chart-freq', Object.keys(fd).map(k=>({label:k,v:fd[k]})));
  barChartGrouped('chart-edadsexo', DATA.edad_sexo.map(d=>d.rango), DATA.edad_sexo.map(d=>d.H), DATA.edad_sexo.map(d=>d.M), {cA:'var(--azul)',cB:'var(--rosa)',h:230});
  $('chart-edadsexo').insertAdjacentHTML('afterend', `<div class="legend"><div class="it"><span class="sw" style="background:var(--azul)"></span> Hombres</div><div class="it"><span class="sw" style="background:var(--rosa)"></span> Mujeres</div></div>`);
  $('tbl-users').innerHTML=`<thead><tr><th>#</th><th>Usuario</th><th>Viajes</th><th>Km</th><th>Horas</th></tr></thead>
    <tbody>${DATA.top_usuarios.map((u,i)=>`<tr>
      <td><span class="rank ${i<3?'top':''}">${i+1}</span></td>
      <td style="font-weight:600">${u.nombre}</td>
      <td class="num" style="color:var(--azul-d)">${u.viajes}</td>
      <td class="num">${fmt1(u.km)}</td><td class="num">${fmt1(u.horas)}</td></tr>`).join('')}</tbody>`;
}

function renderPatrones(){
  barChartV('chart-dur', DATA.dur_dist.map((d,i)=>({label:d.rango.replace(' min','').replace('Sin uso (0)','0'),v:d.cantidad,color:i===0?'var(--gris-l)':BLUES[Math.min(2+i,6)]})),{h:220,gap:0.30});
  barChartV('chart-distk', DATA.dist_dist.map((d,i)=>({label:d.rango.replace('Sin recorrido','0').replace(' km','').replace('500m-1km','.5-1'),v:d.cantidad,color:i===0?'var(--gris-l)':BLUES[Math.min(2+i,6)]})),{h:220,gap:0.30});
  renderHeatmap();
  barsH('chart-plan', DATA.plan_dist.map(p=>({label:p.plan,v:p.viajes})));
  barChartGrouped('chart-tipohora', DATA.tipo_hora.map(d=>d.hora), DATA.tipo_hora.map(d=>d['Eléctrica']), DATA.tipo_hora.map(d=>d['Mecánica']), {cA:'var(--ambar)',cB:'var(--azul)',h:240});
  $('chart-tipohora').insertAdjacentHTML('afterend', `<div class="legend"><div class="it"><span class="sw" style="background:var(--ambar)"></span> Eléctrica</div><div class="it"><span class="sw" style="background:var(--azul)"></span> Mecánica</div></div>`);
  const k=DATA.kpis;
  const durMode=DATA.dur_dist.filter((d,i)=>i>0).reduce((a,b)=>b.cantidad>a.cantidad?b:a);
  const heatPico=DATA.heat.reduce((a,b)=>b.viajes>a.viajes?b:a);
  $('insight-patrones').innerHTML=`<span class="ico">📊</span><span class="tx">La mayoría de los viajes dura <b>${durMode.rango}</b>. La ventana de máxima intensidad fue <b>${heatPico.dia} a las ${heatPico.hora}:00 h</b>. Hay <b>${fmt(k.viajes_sin_distancia)} viajes con distancia 0</b> — probablemente desbloqueos sin uso real, conviene auditarlos.</span>`;
}

function renderHeatmap(){
  const dias=['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];
  const horas=[]; for(let h=6;h<=22;h++)horas.push(h);
  // Defensivo: si el dataset vino sin heat (cache viejo o filtros agresivos)
  // mostramos un placeholder en vez de tronar el resto del JS.
  if (!Array.isArray(DATA.heat) || DATA.heat.length === 0) {
    $('heatgrid').innerHTML =
      '<div style="grid-column:1/-1;padding:30px;text-align:center;color:var(--gris-l);font-family:Space Mono,monospace;font-size:12px">' +
      'Sin datos del mapa de calor en el dataset actual.<br>' +
      'Si tienes filtros activos, prueba con "Quitar todo (datos crudos)" o borra <code>/tmp/qrobici_cache_*.json</code>.' +
      '</div>';
    return;
  }
  const max=Math.max(...DATA.heat.map(d=>d.viajes))||1;
  let html='<div class="heatcell lbl"></div>';
  horas.forEach(h=>html+=`<div class="heatcell hr">${h}</div>`);
  dias.forEach((dia,di)=>{
    html+=`<div class="heatcell lbl">${dia}</div>`;
    horas.forEach(h=>{
      const cell=DATA.heat.find(c=>c.diaIdx===di&&c.hora===h);
      const v=cell?cell.viajes:0; const t=max>0?v/max:0;
      // === Escala con cota inferior explícita en cada rango ===
      let bg, fg='transparent';
      if(v===0){
        bg='#f1f4fa';                    // gris neutro: sin viajes (fuera de horario o sin demanda)
      } else if(t<=0.20){
        bg='#dbe7ff';                    // intensidad muy baja
      } else if(t<=0.40){
        bg='#9bbfe6';                    // baja
      } else if(t<=0.60){
        bg='#5b8fd0';                    // media
      } else if(t<=0.80){
        bg='#254185';  fg='#fff';        // alta
      } else {
        bg='#0d1733';  fg='#fff';        // muy alta (pico)
      }
      const title=v===0?`${dia} ${h}:00 — sin viajes`:`${dia} ${h}:00 — ${v} viajes`;
      html+=`<div class="heatcell" style="background:${bg};color:${fg}" title="${title}">${v||''}</div>`;
    });
  });
  $('heatgrid').innerHTML=html;
}

function renderImpacto(){
  const k=DATA.kpis;
  $('impacto-mini').innerHTML=`
    <div class="mini"><div class="v">${fmt1(k.co2_kg)} kg</div><div class="l">CO₂ no emitido vs. automóvil</div></div>
    <div class="mini"><div class="v">${fmt(k.calorias_total)}</div><div class="l">Calorías quemadas (estimado)</div></div>
    <div class="mini"><div class="v">${fmt1(k.dist_total_km)} km</div><div class="l">Equivale a ${fmt1(k.dist_total_km/42.195)} maratones</div></div>
    <div class="mini"><div class="v">${fmt1(k.dur_total_horas)} h</div><div class="l">${fmt1(k.dur_total_horas/24)} días de pedaleo continuo</div></div>`;
  const total=k.total_viajes;
  const con_plan=DATA.plan_dist.filter(p=>p.plan!=='Sin plan').reduce((s,p)=>s+p.viajes,0);
  const calidad=[
    {label:'CURP registrada',v:Math.round(k.pct_curp)},
    {label:'Con recorrido GPS',v:Math.round(100*DATA.rutas.length/total)},
    {label:'Con estación de origen',v:Math.round(k.pct_con_origen??0)},
    {label:'Con estación de destino',v:Math.round(k.pct_con_destino??0)},
    {label:'Con distancia > 0',v:Math.round(100*(total-k.viajes_sin_distancia)/total)},
    {label:'Plan asignado',v:Math.round(100*con_plan/total)},
  ];
  $('chart-calidad').innerHTML=calidad.map(c=>`
    <div class="bar-row">
      <div class="bar-label">${c.label}</div>
      <div class="bar-track">
        <div class="bar-fill" style="width:${c.v}%;background:${c.v>85?'linear-gradient(90deg,#00d28e,#188a5b)':c.v>60?'linear-gradient(90deg,var(--cielo),var(--azul))':'linear-gradient(90deg,#ffb84d,var(--ambar))'}">${c.v}%</div>
      </div></div>`).join('');
  $('notas-tecnicas').innerHTML=`<span class="ico">📋</span><span><b>Notas técnicas:</b> El reporte cubre ${fmt(total)} viajes entre el ${k.fecha_min} y el ${k.fecha_max}. Edad y sexo derivados de la CURP (${k.pct_curp}% de cobertura). ${DATA.rutas.length} viajes tienen recorrido GPS detallado. Las estimaciones de CO₂ y calorías usan factores de referencia configurables en <code>config.php</code>. Se detectaron ${fmt(k.viajes_sin_distancia)} viajes con duración y distancia en 0 que conviene auditar.</span>`;
}

function renderRutasDestacadas(){
  $('n-rutas').textContent=DATA.rutas.length;
  $('n-est').textContent=DATA.estaciones.length;
  $('ruta-destacadas').innerHTML=DATA.rutas_destacadas.map(r=>{
    // Distancia: dos decimales para que cuadre con el cálculo manual
    const kmStr = (r.dist/1000).toFixed(2);
    // Duración: formato min:seg precisa (29:34 en vez de "30 min")
    const totSec = Math.round(r.dur);
    const mm = Math.floor(totSec/60);
    const ss = (totSec - mm*60).toString().padStart(2,'0');
    const durStr = `${mm}:${ss}`;
    // Velocidad: cálculo sobre valores crudos (consistente con km y dur de arriba)
    const kmh = r.dur > 0 ? (r.dist/1000) / (r.dur/3600) : 0;
    return `<div class="card" style="padding:18px" title="dist=${r.dist} m · dur=${r.dur} s">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <span class="pill ${r.tipo==='Eléctrica'?'elec':'meca'}">${r.tipo==='Eléctrica'?'⚡':'🚲'} ${r.tipo}</span>
        <span style="font-family:'Space Mono';font-size:11px;color:var(--gris-l)">${r.folio}</span>
      </div>
      <div style="font-family:'Archivo';font-weight:800;font-size:21px;color:var(--azul-d)">${kmStr} km</div>
      <div style="font-size:12.5px;color:var(--gris);margin-top:3px">${r.origen} → ${r.destino}</div>
      <div style="font-size:11.5px;color:var(--gris-l);margin-top:8px;font-family:'Space Mono'">⏱ ${durStr} min · ${kmh.toFixed(2)} km/h prom.</div>
    </div>`;
  }).join('');
}

/* ------------------ NUEVA SECCIÓN: SUSCRIPCIONES ------------------ */
function renderPlanes(){
  if(!DATA.kpis_planes || DATA.kpis_planes.total_planes===0){
    $('sec-suscripciones').innerHTML='<div class="shead reveal"><div class="l"><span class="skicker">07 · Suscripciones</span><h2>Sin datos de planes</h2></div></div><div class="card"><p style="color:var(--gris)">La vista <code>planes</code> está vacía o no devolvió registros en el rango configurado.</p></div>';
    return;
  }
  const kp=DATA.kpis_planes;
  // 4 KPIs principales
  const kpis=[
    {ico:'🎫',val:fmt(kp.total_planes),u:'',lbl:'Planes registrados',sub:`${fmt(kp.usuarios_con_plan)} usuarios distintos`,accent:true},
    {ico:'✅',val:fmt(kp.planes_vigentes),u:'',lbl:'Planes vigentes hoy',sub:`${kp.tasa_vigencia}% del total`},
    {ico:'💳',val:`${kp.tasa_pago}`,u:'%',lbl:'Tasa de pago',sub:`${fmt(kp.planes_pagados)} planes pagados`},
    {ico:'🔁',val:`${kp.pct_renovacion}`,u:'%',lbl:'Tasa de renovación',sub:`${fmt(kp.renovaciones)} usuarios con > 1 plan`},
  ];
  $('kpis-planes').innerHTML=kpis.map(x=>`
    <div class="kpi ${x.accent?'accent':''}">
      <div class="ico">${x.ico}</div>
      <div class="val">${x.val}<span class="u">${x.u}</span></div>
      <div class="lbl">${x.lbl}</div>
      <div class="sub">${x.sub}</div>
    </div>`).join('');

  // Donuts estado y pago
  donut('donut-estado', [
    {label:'Vigentes',v:DATA.planes_estado.Vigentes,color:'var(--verde)'},
    {label:'Caducados',v:DATA.planes_estado.Caducados,color:'var(--rojo)'},
    {label:'No iniciados',v:DATA.planes_estado['No iniciados'],color:'var(--ambar)'},
    {label:'Inactivos',v:DATA.planes_estado.Inactivos,color:'var(--gris-l)'},
  ],{unit:'PLANES'});
  donut('donut-pago', [
    {label:'Pagados',v:DATA.planes_pago.Pagados,color:'var(--verde)'},
    {label:'No pagados',v:DATA.planes_pago['No pagados'],color:'var(--rojo)'},
  ],{unit:'PLANES'});

  // Tabla de tipos
  const pt=DATA.planes_por_tipo;
  $('tag-planes-tipo').textContent=`${pt.length} tipos`;
  $('tbl-planes').innerHTML=`<thead><tr><th>#</th><th>Plan</th><th>Total</th><th>Usuarios</th><th>Pagados</th><th>Vigentes</th><th>Tasa pago</th></tr></thead>
    <tbody>${pt.map((p,i)=>{
      let pillCls='warn'; if(p.tasa_pago>=80)pillCls='ok'; else if(p.tasa_pago<50)pillCls='bad';
      return `<tr>
        <td><span class="rank ${i<3?'top':''}">${i+1}</span></td>
        <td style="font-weight:600">${p.plan}</td>
        <td class="num" style="color:var(--azul-d)">${p.total}</td>
        <td class="num">${p.usuarios}</td>
        <td class="num">${p.pagados}</td>
        <td class="num">${p.vigentes}</td>
        <td><span class="pill ${pillCls}">${p.tasa_pago}%</span></td>
      </tr>`;}).join('')}</tbody>`;

  // Altas por día (gráfico apilado: pagadas + no pagadas)
  if(DATA.planes_serie_dia.length){
    const W=640,H=240,pad={t:18,r:14,b:36,l:42};
    const iw=W-pad.l-pad.r,ih=H-pad.t-pad.b;
    const s=DATA.planes_serie_dia;
    const max=Math.max(...s.map(d=>d.altas))*1.14||1;
    const n=s.length,bw=iw/n*0.65;
    let grid='',bars='';
    for(let g=0;g<=4;g++){const gy=pad.t+ih-ih*g/4;
      grid+=`<line class="gridline" x1="${pad.l}" y1="${gy}" x2="${W-pad.r}" y2="${gy}"/>`;
      grid+=`<text class="axlbl" x="${pad.l-8}" y="${gy+3}" text-anchor="end">${Math.round(max*g/4)}</text>`;}
    s.forEach((d,i)=>{
      const cx=pad.l+iw*i/n+iw/n/2;
      const bhTotal=ih*d.altas/max,by=pad.t+ih-bhTotal;
      const bhPag=ih*d.pagados/max,byPag=pad.t+ih-bhPag;
      const noPag=d.altas-d.pagados;
      if(noPag>0) bars+=`<rect x="${cx-bw/2}" y="${by}" width="${bw}" height="${bhTotal-bhPag}" rx="3" fill="var(--gris-l)"><title>No pagados: ${noPag}</title></rect>`;
      if(d.pagados>0) bars+=`<rect x="${cx-bw/2}" y="${byPag}" width="${bw}" height="${bhPag}" rx="3" fill="var(--verde)"><title>Pagados: ${d.pagados}</title></rect>`;
      if(bhTotal>16) bars+=`<text class="vlbl" x="${cx}" y="${by-5}" text-anchor="middle">${d.altas}</text>`;
      const lbl=d.dia.split(' ')[1]||'';
      if(n<=14||i%2===0) bars+=`<text class="axlbl" x="${cx}" y="${H-14}" text-anchor="middle">${lbl}</text>`;
    });
    $('chart-altas').innerHTML=`<svg viewBox="0 0 ${W} ${H}" class="chart">${grid}${bars}</svg>
      <div class="legend"><div class="it"><span class="sw" style="background:var(--verde)"></span> Pagados</div><div class="it"><span class="sw" style="background:var(--gris-l)"></span> No pagados</div></div>`;
  }

  // Próximos vencimientos
  if(DATA.planes_caducan && DATA.planes_caducan.length){
    $('tbl-caducan').innerHTML=`<table class="tbl"><thead><tr><th>Usuario</th><th>Plan</th><th>Vence</th><th>Días</th></tr></thead>
      <tbody>${DATA.planes_caducan.map(c=>{
        let cls='warn'; if(c.dias<=2)cls='bad'; else if(c.dias>=5)cls='ok';
        return `<tr>
          <td style="font-weight:600">${c.nombre}</td>
          <td>${c.plan}</td>
          <td class="num">${c.vence}</td>
          <td><span class="pill ${cls}">${c.dias}d</span></td>
        </tr>`;}).join('')}</tbody></table>`;
  } else {
    $('tbl-caducan').innerHTML=`<div style="color:var(--gris-l);padding:20px;text-align:center">Sin planes próximos a vencer en los próximos 7 días</div>`;
  }

  // === Viajes por tipo de plan (cross-vista) ===
  const vp = DATA.viajes_por_plan;
  if (vp && !vp.vacio && vp.filas && vp.filas.length) {
    const filas = vp.filas;
    const max = Math.max(...filas.map(r => r.viajes)) || 1;
    // ordena: planes reales primero, "Pruebas" al final para fácil lectura
    filas.sort((a,b) => {
      const aSin = a.plan === 'Pruebas' ? 1 : 0;
      const bSin = b.plan === 'Pruebas' ? 1 : 0;
      if (aSin !== bSin) return aSin - bSin;
      return b.viajes - a.viajes;
    });
    // paleta: tonos azules para planes; gris para "Sin plan"
    const W = 720, H = Math.max(180, filas.length * 38 + 30);
    const padL = 160, padR = 80, padT = 8, padB = 10;
    const iw = W - padL - padR;
    const rowH = (H - padT - padB) / filas.length;
    const FONT_SANS = "font-family:'Montserrat',sans-serif";
    const FONT_MONO = "font-family:'Space Mono',monospace";
    let svg = '';
    filas.forEach((r, i) => {
      const y = padT + i * rowH + rowH * 0.18;
      const bh = rowH * 0.64;
      const bw = iw * (r.viajes / max);
      const isPruebas = r.plan === 'Pruebas';
      const col = isPruebas ? 'var(--gris-l)' : BLUES[Math.min(2 + i, 6)];
      const lbl = r.plan.length > 22 ? r.plan.slice(0,21)+'…' : r.plan;
      // label izquierda (nombre del plan) — Space Grotesk semibold
      svg += `<text x="${padL - 10}" y="${y + bh/2 + 4}" text-anchor="end"
                style="${FONT_SANS};font-size:13px;font-weight:600;fill:var(--tinta-2)">${lbl}</text>`;
      // barra
      svg += `<rect x="${padL}" y="${y}" width="${bw}" height="${bh}" rx="4" fill="${col}"><title>${r.plan}: ${fmt(r.viajes)} viajes (${r.pct}%) · ${fmt(r.usuarios)} usuarios</title></rect>`;
      // valor absoluto a la derecha — Space Mono bold
      svg += `<text x="${padL + bw + 8}" y="${y + bh/2 + 4}"
                style="${FONT_MONO};font-size:13px;font-weight:700;fill:var(--azul-d)">${fmt(r.viajes)}</text>`;
      // sublínea con % y usuarios — Space Mono normal en gris
      svg += `<text x="${padL + bw + 8}" y="${y + bh/2 + 19}"
                style="${FONT_MONO};font-size:10.5px;font-weight:400;fill:var(--gris-l)">${r.pct}% · ${fmt(r.usuarios)} usu.</text>`;
    });
    $('chart-viajesplan').innerHTML = `<svg viewBox="0 0 ${W} ${H}" class="chart" preserveAspectRatio="xMidYMid meet" style="${FONT_SANS}">${svg}</svg>`;
    // tag con total
    const tag = $('tag-viajesplan');
    if (tag) tag.textContent = `${fmt(vp.total)} viajes mapeados`;
  } else {
    $('chart-viajesplan').innerHTML = `<div style="color:var(--gris-l);padding:20px;text-align:center;font-size:13px">Sin viajes que mapear contra planes en el periodo${vp && vp.motivo ? ' · ' + vp.motivo : ''}</div>`;
  }

  // Insight
  const topPlan=pt[0];
  $('insight-planes').innerHTML=`<span class="ico">💎</span><span class="tx">Hay <b>${fmt(kp.planes_vigentes)} planes vigentes</b> al día de hoy (${kp.tasa_vigencia}%). El plan más popular es <b>${topPlan.plan}</b> con ${topPlan.total} altas y tasa de pago del ${topPlan.tasa_pago}%. El <b>${kp.pct_renovacion}%</b> de los usuarios ha contratado más de un plan en el periodo. Duración promedio del plan: <b>${kp.duracion_prom_dias} días</b>.</span>`;
}

/* ------------------ GOOGLE MAPS ------------------ */
let mapObj=null,routePolylines=[],stationMarkers=[],heatLayer=null;

function initMap(){
  $('mapctrls').style.display='block';
  $('maplegend').style.display='block';
  mapObj=new google.maps.Map($('map'),{
    center:{lat:DATA.centro[0],lng:DATA.centro[1]},zoom:14,
    mapId:MAP_ID,                 // habilita marcadores avanzados
    mapTypeId:'hybrid',streetViewControl:false,fullscreenControl:true,mapTypeControl:true
  });
  drawRoutes(); drawStations(); buildHeatLayer();
  $('tg-rutas').addEventListener('change',e=>{ routePolylines.forEach(p=>p.setMap(e.target.checked?mapObj:null)); if(e.target.checked&&$('tg-elec').checked)filterElectric();});
  $('tg-elec').addEventListener('change',filterElectric);
  $('tg-est').addEventListener('change',e=>stationMarkers.forEach(m=>{ m.map = e.target.checked?mapObj:null; }));
  $('tg-heat').addEventListener('change',e=>{
    if(heatLayer)heatLayer.setMap(e.target.checked?mapObj:null);
    if(e.target.checked){$('tg-rutas').checked=false; routePolylines.forEach(p=>p.setMap(null));}
  });
}
function drawRoutes(){
  routePolylines.forEach(p=>p.setMap(null)); routePolylines=[];
  DATA.rutas.forEach(r=>{
    if(r.puntos.length<2)return;
    const path=r.puntos.map(p=>({lat:p[0],lng:p[1]}));
    const elec=r.tipo==='Eléctrica';
    const pl=new google.maps.Polyline({path,strokeColor:elec?'#d99000':'#254185',strokeOpacity:0.55,strokeWeight:2.5,map:mapObj});
    pl._elec=elec;
    pl.addListener('mouseover',()=>pl.setOptions({strokeWeight:4.5,strokeOpacity:1}));
    pl.addListener('mouseout',()=>pl.setOptions({strokeWeight:2.5,strokeOpacity:0.55}));
    const iw=new google.maps.InfoWindow();
    pl.addListener('click',e=>{
      iw.setContent(`<div style="font-family:'Montserrat',sans-serif;font-size:12.5px;padding:2px 4px">
        <b style="font-family:'Archivo'">${r.origen} → ${r.destino}</b><br>
        ${r.tipo} · ${fmt1(r.dist/1000)} km · ${Math.round(r.dur/60)} min<br>
        <span style="color:#5b6b8c;font-family:'Space Mono';font-size:11px">${r.folio}</span></div>`);
      iw.setPosition(e.latLng); iw.open(mapObj);
    });
    routePolylines.push(pl);
  });
}
function filterElectric(){
  const onlyElec=$('tg-elec').checked, showRoutes=$('tg-rutas').checked;
  routePolylines.forEach(p=>{
    if(!showRoutes){p.setMap(null);return;}
    if(onlyElec)p.setMap(p._elec?mapObj:null); else p.setMap(mapObj);
  });
}
function drawStations(){
  const maxUso=Math.max(...DATA.estaciones.map(e=>e.total));
  DATA.estaciones.forEach(e=>{
    const d=6+(e.total/maxUso)*20;          // radio (px) según uso
    const dot=document.createElement('div');
    dot.style.cssText=`width:${d*2}px;height:${d*2}px;border-radius:50%;background:#254185;`
      +`opacity:.88;border:2.5px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.15);cursor:pointer`;
    const m=new google.maps.marker.AdvancedMarkerElement({
      position:{lat:e.lat,lng:e.lng},map:mapObj,title:e.nombre,content:dot,zIndex:1000,gmpClickable:true
    });
    const iw=new google.maps.InfoWindow({content:`<div style="font-size:12.5px;padding:3px 5px;min-width:150px">
      <b style="font-size:14px">${e.nombre}</b><br>
      <div style="margin-top:5px;color:#5b6b8c">
        ↗ Salidas: <b style="color:#0a1b3d">${e.salidas}</b><br>
        ↘ Llegadas: <b style="color:#0a1b3d">${e.llegadas}</b><br>
        Uso total: <b style="color:#1a2f63">${e.total}</b><br>
        Balance: <b style="color:${e.balance>0?'#188a5b':e.balance<0?'#ce3a2b':'#9aa7c0'}">${e.balance>0?'+':''}${e.balance}</b>
      </div></div>`});
    dot.addEventListener('click',()=>iw.open({map:mapObj,anchor:m}));
    stationMarkers.push(m);
  });
}
/**
 * Heatmap con deck.gl (GoogleMapsOverlay + HeatmapLayer).
 * Reemplaza al HeatmapLayer nativo de Google Maps, removido en v3.65.
 * deck.gl se carga antes que el API de Maps (ver loader al final).
 */
function buildHeatLayer(){
  // Heatmap con deck.gl (el HeatmapLayer nativo de Google fue removido en v3.65)
  if(typeof deck==='undefined' || !deck.GoogleMapsOverlay){
    console.warn('[heatmap] deck.gl no cargado');
    return;
  }
  const pts=[];
  if(Array.isArray(DATA.rutas)){
    DATA.rutas.forEach(r=>{
      if(Array.isArray(r.puntos)){
        r.puntos.forEach(p=>{
          if(Array.isArray(p) && p.length>=2) pts.push([p[1],p[0]]); // [lng,lat] para deck.gl
        });
      }
    });
  }
  console.log('[heatmap] puntos cargados:', pts.length);
  if(pts.length === 0){
    console.warn('[heatmap] no hay puntos GPS — el dataset no trae rutas con polyline.');
    return;
  }
  const capa=new deck.HeatmapLayer({
    id:'qrb-heat', data:pts, getPosition:d=>d, getWeight:1,
    radiusPixels:18, intensity:1, threshold:0.05,
    colorRange:[[42,158,218],[0,90,178],[37,65,133],[217,144,0],[206,58,43],[142,30,22]]
  });
  heatLayer=new deck.GoogleMapsOverlay({ layers:[capa] });
  heatLayer.setMap(null);   // oculto hasta activar el toggle
}
window.initMap=initMap;
window.gm_authFailure=()=>{ $('mapnokey').style.display='flex'; $('mapctrls').style.display='none'; $('maplegend').style.display='none';};

/* ------------------ CALIFICACIONES (sección 08) ------------------ */
function renderCalif(){
  const c = DATA.calificaciones;
  const wrap = $('calif-wrap');
  if (!wrap) return;
  if (!c || c.vacio) {
    wrap.innerHTML =
      '<div class="card reveal" style="text-align:center;padding:34px">'
      + '<div class="ctitle">Calificaciones no disponibles</div>'
      + '<div class="cdesc" style="max-width:60ch;margin:8px auto 0">'
      + (c && c.motivo ? c.motivo : 'No se detectaron columnas de calificación en la vista de viajes.')
      + '</div></div>';
    return;
  }

  const fmt = n => (n === null || n === undefined) ? '—' : Number(n).toLocaleString('es-MX');
  const fmtAvg = n => (n === null || n === undefined) ? '—' : Number(n).toFixed(2);
  const dimLabel = {bicicleta:'Bicicleta', estacion:'Estación', app:'App'};
  const dimIcon  = {bicicleta:'🚲', estacion:'📍', app:'📱'};
  const escala = c.escala || 5;

  // === FILA 1: tasa de respuesta + 3 promedios ===
  let html = '<div class="grid g4 reveal">';
  // tasa de respuesta
  html += `
    <div class="card kpi">
      <div class="kicker">respuesta</div>
      <div class="big">${c.tasa_resp.toLocaleString('es-MX')}%</div>
      <div class="sub">${fmt(c.calificaron)} de ${fmt(c.total)} viajes calificados</div>
    </div>`;
  // dimensiones disponibles
  ['bicicleta','estacion','app'].forEach(d => {
    const k = c.dimensiones && c.dimensiones[d];
    if (!k) return;
    const prom = k.prom;
    const pct  = k.pct;
    const color = prom === null ? '#6b7a98'
                : pct >= 80 ? '#188a5b'
                : pct >= 60 ? '#d99000' : '#ce3a2b';
    html += `
      <div class="card kpi">
        <div class="kicker">${dimIcon[d]} ${dimLabel[d]}</div>
        <div class="big" style="color:${color}">${fmtAvg(prom)}<span style="font-size:.55em;color:#6b7a98">/${escala}</span></div>
        <div class="sub">${prom !== null ? pct.toFixed(0) + '% de satisfacción · ' + fmt(k.n) + ' respuestas' : 'Sin respuestas'}</div>
      </div>`;
  });
  html += '</div>';

  // === FILA 2: distribuciones de las 3 dimensiones ===
  const dimsDisp = ['bicicleta','estacion','app'].filter(d => c.dimensiones && c.dimensiones[d]);
  const colsGrid = dimsDisp.length >= 3 ? 'g3' : (dimsDisp.length === 2 ? 'g2' : '');
  if (dimsDisp.length > 0) {
    html += `<div class="grid ${colsGrid} reveal" style="margin-top:16px">`;
    dimsDisp.forEach(d => {
      const dist = (c.distribuciones && c.distribuciones[d]) || [];
      const total = dist.reduce((s,r) => s + r.n, 0);
      let rows = '';
      if (total === 0) {
        rows = '<div class="cdesc" style="padding:20px 0;text-align:center">Sin respuestas en el periodo.</div>';
      } else {
        // ordenar de mayor valor a menor (de "5★" hacia "1★")
        const sorted = dist.slice().sort((a,b) => b.valor - a.valor);
        const maxN = Math.max.apply(null, sorted.map(r => r.n)) || 1;
        rows = sorted.map(r => {
          const pct = (r.n / total * 100).toFixed(1);
          const w   = (r.n / maxN * 100).toFixed(1);
          const stars = '★'.repeat(Math.min(escala, Math.round(r.valor)));
          // color graduado: 5/5 verde, 1/5 rojo
          const k = r.valor / escala;
          const col = k >= .8 ? '#188a5b' : k >= .6 ? '#7fbf3f' : k >= .4 ? '#d99000' : '#ce3a2b';
          return `
            <div class="bar-row">
              <div class="bar-label" style="min-width:90px">
                <span style="font-weight:700">${r.valor}</span>
                <span style="color:${col};margin-left:4px">${stars}</span>
              </div>
              <div class="bar-track"><div class="bar-fill" style="width:${w}%;background:${col}"></div></div>
              <div class="bar-value">${fmt(r.n)} <span style="color:#9aa5bd">(${pct}%)</span></div>
            </div>`;
        }).join('');
      }
      html += `
        <div class="card">
          <div class="ctitle">${dimIcon[d]} ${dimLabel[d]}</div>
          <div class="cdesc">Distribución de calificaciones</div>
          <div style="margin-top:12px">${rows}</div>
        </div>`;
    });
    html += '</div>';
  }

  // === Insight: identifica la dimensión mejor/peor calificada ===
  const ranking = dimsDisp
    .map(d => ({d, pct: (c.dimensiones[d].pct ?? 0), n: c.dimensiones[d].n}))
    .filter(x => x.n > 0)
    .sort((a,b) => b.pct - a.pct);
  if (ranking.length > 0) {
    const best  = ranking[0];
    const worst = ranking[ranking.length - 1];
    const bestL  = dimLabel[best.d];
    const worstL = dimLabel[worst.d];
    let mensaje;
    if (ranking.length === 1 || best.d === worst.d) {
      mensaje = `<b>${bestL}</b> alcanza ${best.pct.toFixed(0)}% de satisfacción con ${fmt(best.n)} respuestas. `
              + (best.pct >= 80
                  ? 'Es un puntaje alto: la experiencia se percibe consistente.'
                  : best.pct >= 60
                    ? 'El puntaje es aceptable pero deja margen de mejora.'
                    : 'El puntaje es bajo y debería investigarse la causa raíz.');
    } else {
      const gap = best.pct - worst.pct;
      mensaje = `Mejor evaluado: <b>${bestL}</b> con ${best.pct.toFixed(0)}% de satisfacción. `
              + `Más oportunidades en <b>${worstL}</b> (${worst.pct.toFixed(0)}%). `
              + (gap >= 20
                  ? 'La brecha entre dimensiones es amplia — vale la pena enfocar acciones específicas.'
                  : 'Las tres dimensiones están en rangos similares, lo cual sugiere percepción equilibrada.');
    }
    if (c.tasa_resp < 5) {
      mensaje += ` <b>Atención:</b> la tasa de respuesta es de solo ${c.tasa_resp}%, la muestra puede no ser representativa.`;
    }
    html += `<div class="insight reveal" style="margin-top:16px">${mensaje}</div>`;
  }

  wrap.innerHTML = html;
}

/* ------------------ BOOT ------------------ */
function renderAll(){
  renderHeader(); renderPulso(); renderEstaciones(); renderDemo();
  renderPatrones(); renderImpacto(); renderRutasDestacadas(); renderPlanes();
  renderCalif();
  const obs=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting){e.target.classList.add('in');obs.unobserve(e.target);}}),{threshold:0.08});
  document.querySelectorAll('.reveal').forEach(el=>obs.observe(el));
  setTimeout(()=>{document.querySelectorAll('.bar-fill').forEach(b=>{const w=b.style.width;b.style.width='0';requestAnimationFrame(()=>{b.style.width=w;});});},100);
}
renderAll();

// Cargar Google Maps si hay llave configurada
if(GMAPS_KEY && GMAPS_KEY.length>10 && GMAPS_KEY!=='AIzaSy...PEGAR_AQUI...'){
  // Cargar deck.gl primero (heatmap) y, cuando esté listo, el API de Maps.
  // Así garantizamos que deck exista antes de initMap → buildHeatLayer.
  const cargarMaps=()=>{
    const s=document.createElement('script');
    s.src=`https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(GMAPS_KEY)}&callback=initMap&libraries=marker&loading=async&v=weekly`;
    s.async=true;
    s.onerror=()=>{ $('mapnokey').style.display='flex'; };
    document.head.appendChild(s);
  };
  const dk=document.createElement('script');
  dk.src='https://unpkg.com/deck.gl@8.9.35/dist.min.js';
  dk.onload=cargarMaps;
  dk.onerror=cargarMaps;   // si deck falla, igual carga el mapa (sin heatmap)
  document.head.appendChild(dk);
} else {
  $('mapnokey').style.display='flex';
}
</script>
<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
