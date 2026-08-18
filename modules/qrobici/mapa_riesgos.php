<?php
/**
 * QroBici Analytics — Mapa de riesgos en ciclopistas
 * ------------------------------------------------------------
 * Combina dos capas sobre Google Maps oscuro:
 *
 *   1) Heatmap con las rutas de bicicleta de los últimos 7 días
 *      (calles donde efectivamente circulan los usuarios).
 *
 *   2) Feed de incidentes Waze for Cities en tiempo real
 *      (alertas, jams, irregularidades) renderizados como
 *      markers + polilíneas. Se autoactualiza cada 2 minutos.
 *
 * El objetivo es ver de un vistazo dónde el flujo bici se cruza
 * con problemas viales (accidentes, riesgos, obras, embotellamientos).
 *
 * También expone un endpoint AJAX para refrescar solo el feed
 * sin tocar las rutas bici:
 *   GET mapa_riesgos.php?ajax=waze[&force=1]
 */

declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib_polyline.php';
require_once __DIR__ . '/lib_mapa.php';
require_once __DIR__ . '/lib_waze.php';

$cfg = require __DIR__ . '/config.php';

if (!empty($cfg['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}
date_default_timezone_set($cfg['zona_horaria'] ?? 'America/Mexico_City');

// =====================================================================
//  Cruce con secciones electorales (las tablas viven en la BD del PORTAL,
//  no en la BD remota de Qrobici). Conexión y asignación punto-en-polígono.
// =====================================================================
function qrb_portal_pdo(): ?PDO {
    static $pdb = false;
    if ($pdb !== false) return $pdb;
    try {
        $pc = require __DIR__ . '/../../config/config.php';
        $d  = $pc['db'];
        $pdb = new PDO(
            "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4",
            $d['user'], $d['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $pdb->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED"); // índice espacial + READ-UNCOMMITTED = error 1207
    } catch (Throwable $e) { $pdb = null; }
    return $pdb;
}
function qrb_tiene_secciones(PDO $pdb): bool {
    try {
        return (int)$pdb->query("SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema=DATABASE() AND table_name IN ('secciones','secciones_geo','distritos')")
            ->fetchColumn() === 3;
    } catch (Throwable $e) { return false; }
}
/** Añade 'seccion' y 'distrito_id' a cada alerta del dataset Waze. */
function qrb_asigna_secciones(array $waze): array {
    $pdb = qrb_portal_pdo();
    if (!$pdb || empty($waze['alerts']) || !qrb_tiene_secciones($pdb)) return $waze;
    $q = $pdb->prepare("
        SELECT s.num_seccion, s.distrito_id
          FROM secciones_geo sg JOIN secciones s ON s.id = sg.seccion_id
         WHERE ST_Contains(sg.geom, ST_GeomFromText(CONCAT('POINT(',:lat,' ',:lng,')'), 4326))
         LIMIT 1");
    foreach ($waze['alerts'] as &$a) {
        $a['seccion'] = null; $a['distrito_id'] = null;
        if (!isset($a['lat'], $a['lng'])) continue;
        try {
            $q->execute([':lat' => $a['lat'], ':lng' => $a['lng']]);
            if ($r = $q->fetch(PDO::FETCH_ASSOC)) {
                $a['seccion']     = (int)$r['num_seccion'];
                $a['distrito_id'] = (int)$r['distrito_id'];
            }
        } catch (Throwable $e) { /* alerta sin sección */ }
    }
    unset($a);
    return $waze;
}

// === MODO AJAX: solo retorna feed Waze ===
if (isset($_GET['ajax']) && $_GET['ajax'] === 'waze') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $force = !empty($_GET['force']);
    echo json_encode(qrb_asigna_secciones(qrb_construye_dataset_waze($cfg, $force)), JSON_UNESCAPED_UNICODE);
    exit;
}

// === MODO HTML completo ===
try {
    qrb_db($cfg);
} catch (Throwable $e) {
    if (!empty($cfg['debug'])) { throw $e; }
    http_response_code(500);
    die('Error de conexión. Revisa config.php');
}

// 1) Rutas bici (últimos 7 días, cap a 25k puntos)
$cache_bici = sys_get_temp_dir() . '/qrobici_riesgos_bici.json';
$bici = null;
$cache_seg = (int)($cfg['cache_segundos'] ?? 0);
if ($cache_seg > 0 && is_file($cache_bici)
    && (time() - filemtime($cache_bici)) < $cache_seg) {
    $bici = json_decode(file_get_contents($cache_bici), true);
}
if ($bici === null) {
    $bici = qrb_mapa_polylines_recientes($cfg, 7, 25000);
    if ($cache_seg > 0) { @file_put_contents($cache_bici, json_encode($bici)); }
}

// 2) Feed Waze (con sección/distrito por alerta)
$waze = qrb_asigna_secciones(qrb_construye_dataset_waze($cfg, false));

// 2b) Distritos + polígonos de secciones para dibujar y filtrar
$distritos = [];
$secciones = [];
if (($pdb = qrb_portal_pdo()) && qrb_tiene_secciones($pdb)) {
    try {
        $distritos = $pdb->query("SELECT id,numero,nombre FROM distritos ORDER BY numero")->fetchAll(PDO::FETCH_ASSOC);
        $secciones = $pdb->query("
            SELECT s.id, s.num_seccion, s.distrito_id, sg.wkt
              FROM secciones s JOIN secciones_geo sg ON sg.seccion_id = s.id
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}
$geo_ok = !empty($distritos);

// Centro del mapa
$centro = ['lat' => 20.5888, 'lng' => -100.3899];   // Querétaro centro

$api_key = htmlspecialchars($cfg['google_maps_api_key'] ?? '', ENT_QUOTES, 'UTF-8');

// Serializar JSON con blindaje contra </script>
$payload = [
    'bici'      => $bici,
    'waze'      => $waze,
    'centro'    => $centro,
    'distritos' => $distritos,
    'secciones' => $secciones,
    'geo_ok'    => $geo_ok,
];
$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$json = str_replace(['</', "\u{2028}", "\u{2029}"], ['<\/', ' ', ' '], $json);

?><?php
$ktTitle  = 'QroBici · Mapa de riesgos';
$ktActive = 'qrobici';
require __DIR__ . '/../../views/layout/kt_top.php';
?><link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800;900&family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap">
<style>
:root {
  --bg:#040711;
  --panel:rgba(8,12,28,.88);
  --panel-bd:rgba(120,160,255,.18);
  --txt:#e8efff;
  --txt-d:#8aa0d8;
  --txt-dim:#5970a8;
  --cyan:#00d4ff;
  --azul:#3a7cff;
  --verde:#33ffb0;
  --ambar:#d99000;
  --rojo:#ce3a2b;
  --rosa:#5b667a;
  --pol:#7fb1ff;
}
* { box-sizing:border-box; margin:0; padding:0; }
html, body { height:100%; overflow:hidden; }
body {
  font-family:'Space Grotesk', system-ui, sans-serif;
  color:var(--txt);
  background:linear-gradient(180deg,#06091a,#020308);
  -webkit-font-smoothing:antialiased;
  display:flex; flex-direction:column;
}

/* ===== TOPBAR ===== */
.topbar {
  flex:0 0 auto;
  display:flex; align-items:center; gap:18px;
  padding:12px 22px;
  background:rgba(6,9,22,.88);
  border-bottom:1px solid rgba(120,160,255,.15);
  backdrop-filter:blur(22px) saturate(140%);
  -webkit-backdrop-filter:blur(22px) saturate(140%);
  z-index:10; position:relative;
}
.topbar .grow { flex:1 1 auto; }
.brand { display:flex; align-items:center; gap:12px; }
.brand .logo {
  width:36px; height:36px; border-radius:10px;
  background:linear-gradient(135deg, var(--rojo), var(--ambar));
  display:flex; align-items:center; justify-content:center;
  font-family:'Archivo', sans-serif; font-weight:900;
  color:#0a1024; font-size:17px; letter-spacing:-1px;
  box-shadow:0 0 22px rgba(255,77,94,.5);
}
.brand .nm {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:16px; letter-spacing:.4px; text-transform:uppercase;
}
.brand .sl {
  font-family:'Space Mono', monospace;
  font-size:10px; letter-spacing:1.5px; color:var(--txt-dim);
  text-transform:uppercase; margin-top:2px;
}

.kpis { display:flex; gap:14px; }
.kpi {
  display:flex; flex-direction:column;
  padding:0 12px;
  border-left:1px solid rgba(120,160,255,.12);
}
.kpi:first-child { border-left:none; padding-left:0; }
.kpi .lbl {
  font-family:'Space Mono', monospace;
  font-size:9px; letter-spacing:1.5px; color:var(--txt-dim);
  text-transform:uppercase;
}
.kpi .val {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:20px; margin-top:3px;
  font-variant-numeric:tabular-nums;
}
.kpi.alerts .val { color:var(--rojo); text-shadow:0 0 12px rgba(255,77,94,.35); }
.kpi.jams .val   { color:var(--ambar); text-shadow:0 0 12px rgba(255,149,0,.35); }
.kpi.bici .val   { color:var(--cyan); text-shadow:0 0 12px rgba(0,212,255,.35); }

.feed-status {
  display:flex; align-items:center; gap:8px;
  padding:6px 14px;
  border-radius:999px;
  background:rgba(51,255,176,.1);
  color:var(--verde);
  font-family:'Space Mono', monospace;
  font-size:10px; letter-spacing:1.5px; text-transform:uppercase; font-weight:700;
}
.feed-status .dot {
  width:7px; height:7px; border-radius:50%;
  background:currentColor;
  animation:blink 2s ease-in-out infinite;
}
.feed-status.err {
  background:rgba(255,77,94,.1); color:var(--rojo);
}
.feed-status.stale {
  background:rgba(255,149,0,.1); color:var(--ambar);
}
@keyframes blink { 0%,100%{opacity:1;} 50%{opacity:.45;} }

.btn-refresh {
  padding:7px 13px;
  border-radius:8px;
  background:rgba(0,212,255,.1);
  border:1px solid rgba(0,212,255,.25);
  color:var(--cyan);
  font-family:'Space Mono', monospace;
  font-size:10px; letter-spacing:1.5px; text-transform:uppercase; font-weight:700;
  cursor:pointer;
  transition:all .15s ease;
}
.btn-refresh:hover {
  background:rgba(0,212,255,.22);
  box-shadow:0 0 16px rgba(0,212,255,.3);
}
.btn-refresh:disabled { opacity:.4; cursor:wait; }

/* ===== MAPA + SIDEBAR ===== */
.stage { flex:1 1 auto; display:flex; min-height:0; }
#map { flex:1 1 auto; background:var(--bg); position:relative; }

.sidebar {
  flex:0 0 360px;
  background:rgba(8,12,28,.92);
  border-left:1px solid rgba(120,160,255,.15);
  display:flex; flex-direction:column;
  min-height:0;
}
.sidebar-head {
  padding:14px 18px;
  border-bottom:1px solid rgba(120,160,255,.1);
}
.sidebar-head .t {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:13px; letter-spacing:.5px; text-transform:uppercase;
}
.sidebar-head .s {
  font-family:'Space Mono', monospace;
  font-size:10px; color:var(--txt-dim);
  margin-top:3px;
}

/* filtros */
.filters {
  display:flex; flex-wrap:wrap; gap:6px;
  padding:10px 18px;
  border-bottom:1px solid rgba(120,160,255,.1);
}
/* filtros geográficos (distrito / sección) */
.geo-filtros {
  display:flex; gap:8px; padding:10px 18px 4px;
}
.geo-filtros select, .geo-filtros input {
  flex:1; min-width:0;
  font-family:'Space Mono', monospace; font-size:10px;
  padding:7px 9px; border-radius:6px;
  background:rgba(120,160,255,.06);
  border:1px solid rgba(120,160,255,.18);
  color:var(--txt); outline:none;
}
.geo-filtros select option { background:#0b1430; color:#e6ecff; }
.geo-filtros input::placeholder { color:var(--txt-dim); }
/* subcategorías */
.subfilters {
  display:flex; flex-wrap:wrap; gap:5px;
  padding:4px 18px 8px;
}
.subfilters:empty { display:none; }
.subflt {
  font-family:'Space Mono', monospace;
  font-size:8.5px; letter-spacing:.5px; text-transform:lowercase; font-weight:700;
  padding:4px 8px; border-radius:5px;
  background:rgba(120,160,255,.04);
  border:1px dashed rgba(120,160,255,.22);
  color:var(--txt-dim); cursor:pointer; transition:all .15s ease;
}
.subflt:hover { color:var(--txt); }
.subflt.active { color:#fff; background:rgba(58,124,255,.30); border-style:solid; border-color:var(--azul); }
.flt {
  font-family:'Space Mono', monospace;
  font-size:9px; letter-spacing:1px; text-transform:uppercase; font-weight:700;
  padding:5px 9px;
  border-radius:6px;
  background:rgba(120,160,255,.06);
  border:1px solid rgba(120,160,255,.15);
  color:var(--txt-d);
  cursor:pointer;
  transition:all .15s ease;
}
.flt:hover { color:var(--txt); background:rgba(120,160,255,.12); }
.flt.active {
  background:var(--cyan); color:#06091a;
  border-color:var(--cyan);
  box-shadow:0 0 10px rgba(0,212,255,.3);
}

/* lista de alertas */
.alert-list {
  flex:1 1 auto; overflow-y:auto;
  padding:6px 8px;
}
.alert-list::-webkit-scrollbar { width:6px; }
.alert-list::-webkit-scrollbar-thumb { background:rgba(120,160,255,.18); border-radius:3px; }
.alert-list::-webkit-scrollbar-thumb:hover { background:rgba(120,160,255,.35); }

.alert {
  display:flex; gap:11px;
  padding:10px 12px;
  border-radius:9px;
  cursor:pointer;
  margin-bottom:4px;
  transition:background .15s ease;
}
.alert:hover { background:rgba(120,160,255,.06); }
.alert.is-focus {
  background:rgba(0,212,255,.1);
  box-shadow:inset 0 0 0 1px rgba(0,212,255,.35);
}
.alert .icon {
  flex:0 0 32px; width:32px; height:32px;
  border-radius:8px;
  display:flex; align-items:center; justify-content:center;
  font-size:16px; line-height:1;
}
.alert.t-ACCIDENT       .icon { background:rgba(255,77,94,.18);  color:var(--rojo); }
.alert.t-HAZARD         .icon { background:rgba(255,149,0,.18);  color:var(--ambar); }
.alert.t-WEATHERHAZARD  .icon { background:rgba(58,124,255,.18); color:var(--azul); }
.alert.t-ROAD_CLOSED    .icon { background:rgba(255,77,94,.18);  color:var(--rojo); }
.alert.t-CONSTRUCTION   .icon { background:rgba(255,149,0,.18);  color:var(--ambar); }
.alert.t-JAM            .icon { background:rgba(255,149,0,.18);  color:var(--ambar); }
.alert.t-POLICE         .icon { background:rgba(58,124,255,.18); color:var(--azul); }
.alert.t-OTHER          .icon { background:rgba(127,177,255,.18);color:var(--pol); }
.alert .info { min-width:0; flex:1 1 auto; }
.alert .ttl {
  font-family:'Archivo', sans-serif; font-weight:800;
  font-size:12px;
  text-transform:uppercase; letter-spacing:.3px;
  color:var(--txt);
}
.alert .st {
  font-size:12px; color:var(--txt-d);
  margin-top:2px;
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.alert .meta {
  font-family:'Space Mono', monospace;
  font-size:9.5px; color:var(--txt-dim);
  margin-top:4px; letter-spacing:.4px;
  text-transform:uppercase;
}

.alert-empty {
  text-align:center;
  padding:40px 20px;
  color:var(--txt-dim);
  font-family:'Space Mono', monospace;
  font-size:11px; letter-spacing:1.5px; text-transform:uppercase;
}

/* leyenda */
.legend {
  position:absolute;
  bottom:18px; left:18px;
  background:var(--panel);
  border:1px solid var(--panel-bd);
  border-radius:14px;
  padding:12px 14px;
  backdrop-filter:blur(18px);
  -webkit-backdrop-filter:blur(18px);
  box-shadow:0 18px 50px rgba(0,0,0,.5);
  font-family:'Space Mono', monospace;
  font-size:10px; letter-spacing:.8px;
  z-index:5;
}
.legend .row {
  display:flex; align-items:center; gap:8px;
  color:var(--txt-d); text-transform:uppercase;
  margin-bottom:5px;
}
.legend .row:last-child { margin-bottom:0; }
.legend .sw {
  width:14px; height:8px; border-radius:3px;
}
.legend .sw.heatmap {
  background:linear-gradient(90deg, #001f3f, #00d4ff, #ffffff);
}
.legend .sw.jam-low { background:#ffd700; }
.legend .sw.jam-med { background:#d99000; }
.legend .sw.jam-high { background:#ce3a2b; }
.legend .sw.iconmark {
  border-radius:50%; background:#ce3a2b;
  box-shadow:0 0 8px rgba(255,77,94,.5);
}

/* botón "ver reporte" abajo derecha */
.actions {
  position:absolute;
  bottom:18px;
  right:calc(360px + 18px);  /* ajustar por sidebar */
  display:flex; gap:8px;
  z-index:5;
}
.actions a {
  display:inline-flex; align-items:center; gap:6px;
  background:var(--panel); color:var(--txt);
  text-decoration:none;
  border:1px solid var(--panel-bd);
  border-radius:14px;
  padding:10px 14px;
  font-family:'Space Mono', monospace; font-size:10px;
  letter-spacing:1px; text-transform:uppercase; font-weight:700;
  backdrop-filter:blur(18px);
  -webkit-backdrop-filter:blur(18px);
  transition:all .15s ease;
}
.actions a:hover {
  border-color:var(--cyan); color:var(--cyan);
}

/* mobile: sidebar oculta detrás de un toggle */
@media (max-width:900px) {
  .sidebar { position:absolute; right:0; top:64px; bottom:0; z-index:8;
             transform:translateX(100%); transition:transform .3s ease;
             border-left:1px solid rgba(120,160,255,.2);
             box-shadow:-8px 0 30px rgba(0,0,0,.4); }
  .sidebar.open { transform:translateX(0); }
  .actions { right:18px; }
  .legend { display:none; }
}

/* splash */
.splash {
  position:fixed; inset:0; z-index:40;
  display:flex; align-items:center; justify-content:center;
  flex-direction:column;
  background:radial-gradient(ellipse at center, #0a1230 0%, #02030a 70%);
  transition:opacity .5s ease;
}
.splash.fade { opacity:0; pointer-events:none; }
.splash .logo {
  width:56px; height:56px; border-radius:14px;
  background:linear-gradient(135deg, var(--rojo), var(--ambar));
  display:flex; align-items:center; justify-content:center;
  font-family:'Archivo', sans-serif; font-weight:900; color:#0a1024;
  font-size:26px;
  box-shadow:0 0 40px rgba(255,77,94,.6);
  animation:pulse 1.5s ease-in-out infinite;
}
.splash .ttl {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:22px; letter-spacing:1px;
  margin-top:18px; text-transform:uppercase;
}
.splash .sub {
  font-family:'Space Mono', monospace;
  font-size:11px; letter-spacing:2px;
  color:var(--ambar); margin-top:6px;
}
@keyframes pulse { 0%,100%{transform:scale(1);} 50%{transform:scale(1.07);} }

/* tooltip de marker */
.gm-style .gm-style-iw { color:#0a1024; }
</style>

<header class="topbar">
  <div class="brand">
    <div class="logo">!</div>
    <div>
      <div class="nm">Mapa de riesgos</div>
      <div class="sl">Bicicletas QroBici × Incidentes Waze</div>
    </div>
  </div>

  <div class="grow"></div>

  <div class="kpis">
    <div class="kpi alerts">
      <div class="lbl">Alertas</div>
      <div class="val" id="kAlerts">0</div>
    </div>
    <div class="kpi jams">
      <div class="lbl">Jams</div>
      <div class="val" id="kJams">0</div>
    </div>
    <div class="kpi bici">
      <div class="lbl">Recorridos bici</div>
      <div class="val" id="kBici">0</div>
    </div>
  </div>

  <div class="feed-status" id="feedStatus">
    <span class="dot"></span> <span id="feedLbl">conectando…</span>
  </div>

  <button class="btn-refresh" id="btnRefresh" title="Forzar actualización del feed Waze">↻ refrescar</button>
</header>

<div class="stage">
  <div id="map"></div>

  <aside class="sidebar" id="sidebar">
    <div class="sidebar-head">
      <div class="t">Incidentes activos</div>
      <div class="s" id="sideSub">cargando…</div>
    </div>
    <?php if ($geo_ok): ?>
    <div class="geo-filtros">
      <select id="f-distrito" title="Filtrar por distrito electoral">
        <option value="">Todos los distritos</option>
        <?php foreach ($distritos as $d): ?>
          <option value="<?= (int)$d['id'] ?>">D<?= (int)$d['numero'] ?> — <?= htmlspecialchars($d['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <input id="f-seccion" type="number" inputmode="numeric" placeholder="Sección #" title="Filtrar por número de sección">
    </div>
    <?php endif; ?>
    <div class="filters" id="filters"></div>
    <div class="subfilters" id="subfilters"></div>
    <div class="alert-list" id="alertList"></div>
  </aside>

  <div class="legend">
    <div class="row"><span class="sw heatmap"></span> Densidad de rutas bici (7 días)</div>
    <div class="row"><span class="sw jam-low"></span> Jam leve (lvl 1-2)</div>
    <div class="row"><span class="sw jam-med"></span> Jam moderado (lvl 3)</div>
    <div class="row"><span class="sw jam-high"></span> Jam severo (lvl 4-5)</div>
    <div class="row"><span class="sw iconmark"></span> Alerta Waze (accidente, riesgo, obra…)</div>
    <?php if ($geo_ok): ?><div class="row"><span class="sw" style="border:1px solid #fff;background:transparent;width:14px;height:9px;display:inline-block"></span> Sección electoral</div><?php endif; ?>
  </div>

  <div class="actions">
    <a href="index.php">← Inicio</a>
  </div>
</div>

<div class="splash" id="splash">
  <div class="logo">!</div>
  <div class="ttl">Riesgos</div>
  <div class="sub">cargando feed waze · trazando ciclopistas</div>
</div>

<script id="payload" type="application/json"><?= $json ?></script>
<script>
/* ========================================================
   QROBICI — Mapa de riesgos
======================================================== */
const DATA = JSON.parse(document.getElementById('payload').textContent);

/* ---- DARK STYLE de Google Maps ---- */
const DARK_STYLE = [
  {elementType:'geometry', stylers:[{color:'#0b1430'}]},
  {elementType:'labels.text.fill', stylers:[{color:'#7088c4'}]},
  {elementType:'labels.text.stroke', stylers:[{color:'#020308'}]},
  {featureType:'administrative', elementType:'geometry', stylers:[{color:'#152055'}, {weight:0.8}]},
  {featureType:'administrative.locality', elementType:'labels.text.fill', stylers:[{color:'#c3d3f5'}]},
  {featureType:'poi', stylers:[{visibility:'off'}]},
  {featureType:'road', elementType:'geometry', stylers:[{color:'#1c2a5e'}]},
  {featureType:'road', elementType:'geometry.stroke', stylers:[{color:'#2e3f88'}]},
  {featureType:'road.highway', elementType:'geometry', stylers:[{color:'#2e3f88'}]},
  {featureType:'road.highway', elementType:'geometry.stroke', stylers:[{color:'#4858b8'}]},
  {featureType:'road.local', elementType:'geometry', stylers:[{color:'#162049'}]},
  {featureType:'road', elementType:'labels.text.fill', stylers:[{color:'#6079b5'}]},
  {featureType:'water', elementType:'geometry', stylers:[{color:'#040c24'}]},
  {featureType:'transit', stylers:[{visibility:'off'}]},
  {featureType:'landscape', elementType:'geometry', stylers:[{color:'#0b1430'}]},
];

/* ---- Definición visual de tipos de alerta Waze ---- */
const ALERT_VISUAL = {
  ACCIDENT:      {icon:'🚨', label:'Accidente',    color:'#ce3a2b'},
  HAZARD:        {icon:'⚠️', label:'Peligro',       color:'#d99000'},
  WEATHERHAZARD: {icon:'🌧️', label:'Clima',         color:'#3a7cff'},
  ROAD_CLOSED:   {icon:'🚧', label:'Vía cerrada',   color:'#ce3a2b'},
  CONSTRUCTION:  {icon:'🛠️', label:'Construcción',  color:'#d99000'},
  JAM:           {icon:'🚗', label:'Embotellamiento',color:'#d99000'},
  POLICE:        {icon:'👮', label:'Policía',       color:'#3a7cff'},
  OTHER:         {icon:'•',  label:'Otro',          color:'#7fb1ff'},
};

let map, heatmap, waze_markers = [], waze_polylines = [];
let seccionPolys = [];   // polígonos de secciones electorales

/* Parsea un WKT POLYGON("lat lng,...") -> array de {lat,lng} */
function parseWktSecc(wkt){
  const m = (wkt || '').match(/POLYGON\s*\(\s*\(([^)]+)\)\s*\)/i);
  if (!m) return [];
  return m[1].split(',').map(pt => {
    const [a,b] = pt.trim().split(/\s+/).map(Number);
    return { lat:a, lng:b };
  });
}

/* Dibuja todas las secciones con líneas BLANCAS (contraste sobre el mapa oscuro) */
function drawSecciones(){
  seccionPolys.forEach(p => p.setMap(null));
  seccionPolys = [];
  const secs = (DATA.secciones || []);
  for (const s of secs){
    const path = parseWktSecc(s.wkt);
    if (path.length < 3) continue;
    const poly = new google.maps.Polygon({
      paths: path,
      strokeColor: '#ffffff',
      strokeOpacity: 0.45,
      strokeWeight: 1,
      fillColor: '#ffffff',
      fillOpacity: 0.0,
      clickable: false,        // no bloquear el click de las alertas
      zIndex: 5,
      map: map,
    });
    poly.qsecc = s;
    seccionPolys.push(poly);
  }
  applySeccionView();
}

/* Muestra/resalta según distrito y sección seleccionados */
function applySeccionView(){
  let bounds = null;
  for (const poly of seccionPolys){
    const s = poly.qsecc;
    const enDist = !selDist || String(s.distrito_id) === String(selDist);
    const enSecc = !selSecc || String(s.num_seccion) === String(selSecc);
    const visible = enDist && enSecc;
    poly.setMap(visible ? map : null);
    if (!visible) continue;
    // Resaltar cuando hay un filtro activo
    const resaltado = (selDist || selSecc);
    poly.setOptions({
      strokeOpacity: resaltado ? 0.95 : 0.45,
      strokeWeight:  resaltado ? 2 : 1,
      fillOpacity:   resaltado ? 0.10 : 0.0,
    });
    if (resaltado){
      if (!bounds) bounds = new google.maps.LatLngBounds();
      poly.getPath().forEach(pt => bounds.extend(pt));
    }
  }
  if (bounds && map) map.fitBounds(bounds, 40);
}
let alertsFilter = new Set();   // tipos (categoría) visibles; vacío = todos
let subtypeFilter = new Set();  // subtipos (subcategoría) visibles; vacío = todos
let selDist = '';               // distrito electoral seleccionado ('' = todos)
let selSecc = '';               // número de sección ('' = todas)
let geoWired = false;           // controles geo enganchados una sola vez
let focusUuid = null;
let lastRefresh = 0;

/* Mapa id→distrito para mostrar el número */
const DIST_BY_ID = {};
(DATA.distritos || []).forEach(d => { DIST_BY_ID[d.id] = d; });
function distNum(id){ return DIST_BY_ID[id] ? DIST_BY_ID[id].numero : '?'; }
function seccTxt(a){ return a && a.seccion ? `Sección ${a.seccion} · D${distNum(a.distrito_id)}` : ''; }

/* Etiqueta legible de un subtipo Waze: quita el prefijo del tipo y normaliza */
function subLabel(sub, type){
  if(!sub) return '(sin subtipo)';
  let s = sub.toUpperCase();
  if(type && s.startsWith(type + '_')) s = s.slice(type.length + 1);
  return s.replace(/_/g,' ').toLowerCase();
}

/* ¿La alerta pasa TODOS los filtros activos? (categoría + subcategoría + sección) */
function pasaFiltros(a){
  if (alertsFilter.size > 0 && !alertsFilter.has(a.type)) return false;
  if (subtypeFilter.size > 0 && !subtypeFilter.has(a.subtype || '')) return false;
  if (selDist && String(a.distrito_id) !== String(selDist)) return false;
  if (selSecc && String(a.seccion) !== String(selSecc)) return false;
  return true;
}

/* ===== INIT MAPA ===== */
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

  // === Heatmap de rutas bici ===
  buildHeatmap();

  // === Polígonos de secciones electorales ===
  drawSecciones();

  // === Capa Waze inicial ===
  renderWaze(DATA.waze);

  google.maps.event.addListenerOnce(map, 'idle', () => {
    document.getElementById('splash').classList.add('fade');
  });

  // arrancar auto-refresh cada 2 minutos
  setInterval(refreshWaze, 120 * 1000);
  lastRefresh = Date.now();
  updateFeedStatus(DATA.waze);
}
window.initMap = initMap;

/* ===== HEATMAP DE BICI ===== */
function buildHeatmap() {
  if (!DATA.bici || !DATA.bici.polylines) return;
  const pts = [];
  for (const pl of DATA.bici.polylines) {
    for (const p of pl.p) {
      // p = [lat, lng]
      pts.push(new google.maps.LatLng(p[0], p[1]));
    }
  }
  heatmap = new google.maps.visualization.HeatmapLayer({
    data: pts,
    map: map,
    radius: 16,
    opacity: 0.75,
    maxIntensity: 12,
    gradient: [
      'rgba(0, 0, 0, 0)',
      'rgba(0, 31, 63, 0.5)',
      'rgba(0, 100, 180, 0.7)',
      'rgba(0, 160, 240, 0.85)',
      'rgba(0, 212, 255, 0.95)',
      'rgba(127, 230, 255, 1)',
      'rgba(255, 255, 255, 1)',
    ],
  });
  document.getElementById('kBici').textContent = DATA.bici.total_viajes.toLocaleString('es-MX');
}

/* ===== RENDER WAZE LAYER ===== */
function clearWazeLayer() {
  waze_markers.forEach(m => m.setMap(null));
  waze_polylines.forEach(p => p.setMap(null));
  waze_markers = []; waze_polylines = [];
}

function colorJam(level) {
  if (level >= 4) return '#ce3a2b';
  if (level >= 3) return '#d99000';
  if (level >= 1) return '#ffd700';
  return '#d9e2f0';
}

function renderWaze(waze) {
  clearWazeLayer();
  if (!waze || !waze.ok) {
    document.getElementById('kAlerts').textContent = '—';
    document.getElementById('kJams').textContent = '—';
    return;
  }

  // === Markers de alertas ===
  for (const a of waze.alerts) {
    if (!pasaFiltros(a)) continue;
    const vis = ALERT_VISUAL[a.type] || ALERT_VISUAL.OTHER;
    const m = new google.maps.Marker({
      position: {lat: a.lat, lng: a.lng},
      map: map,
      title: `${vis.label} — ${a.street || 'sin calle'}`,
      icon: makeAlertIcon(vis.color, a.uuid === focusUuid),
      zIndex: a.uuid === focusUuid ? 100 : 50,
    });
    const html =
      `<div style="font-family:Space Grotesk,sans-serif;padding:4px 8px;min-width:180px">
         <div style="font-weight:800;color:${vis.color};font-size:13px;letter-spacing:.3px;text-transform:uppercase">
           ${vis.icon} ${vis.label}${a.subtype ? ' · ' + a.subtype : ''}
         </div>
         <div style="margin-top:5px;font-size:13px;color:#0a1024">${escapeHtml(a.street || 'Calle desconocida')}</div>
         ${a.seccion ? `<div style="margin-top:4px;font-size:11px;color:#254185;font-weight:700">📍 ${seccTxt(a)}</div>` : ''}
         ${a.descripcion ? `<div style="margin-top:4px;font-size:12px;color:#3c4a6e">${escapeHtml(a.descripcion)}</div>` : ''}
         <div style="margin-top:6px;font-family:'Space Mono',monospace;font-size:10px;color:#7287ac">
           ${minutosDesde(a.pubMillis)} · confiabilidad ${a.reliability}/10
         </div>
       </div>`;
    const iw = new google.maps.InfoWindow({content: html});
    m.addListener('click', () => {
      iw.open(map, m);
      focusUuid = a.uuid;
      renderAlertList(waze);
    });
    waze_markers.push(m);
  }

  // === Polilíneas de jams ===
  for (const j of waze.jams) {
    const path = j.puntos.map(p => ({lat: p[0], lng: p[1]}));
    const col = colorJam(j.level);
    const weight = 3 + Math.min(5, j.severity);
    const line = new google.maps.Polyline({
      path: path,
      strokeColor: col,
      strokeOpacity: 0.85,
      strokeWeight: weight,
      map: map,
      zIndex: 30,
    });
    line.addListener('click', (e) => {
      new google.maps.InfoWindow({
        content:
          `<div style="font-family:Space Grotesk,sans-serif;padding:4px 8px">
             <div style="font-weight:800;color:${col};text-transform:uppercase;font-size:12px">
               🚗 Jam nivel ${j.level} / 5
             </div>
             <div style="margin-top:4px;font-size:13px;color:#0a1024">${escapeHtml(j.street || 'Vía desconocida')}</div>
             <div style="margin-top:4px;font-family:'Space Mono',monospace;font-size:11px;color:#3c4a6e">
               ${j.speedKMH} km/h · ${j.lengthM} m · retraso ${Math.round(j.delaySec/60)} min
             </div>
           </div>`,
        position: e.latLng,
      }).open(map);
    });
    waze_polylines.push(line);
  }

  // === Polilíneas de irregularidades (líneas punteadas) ===
  const dashedSymbol = {
    path: 'M 0,-1 0,1',
    strokeOpacity: 1,
    scale: 3,
  };
  for (const ir of waze.irregularities) {
    const path = ir.puntos.map(p => ({lat: p[0], lng: p[1]}));
    const line = new google.maps.Polyline({
      path: path,
      strokeOpacity: 0,
      icons: [{icon: dashedSymbol, offset: '0', repeat: '12px'}],
      strokeColor: colorJam(ir.jamLevel),
      map: map,
      zIndex: 25,
    });
    waze_polylines.push(line);
  }

  // === Actualizar KPIs y panel ===
  document.getElementById('kAlerts').textContent = waze.alerts.length;
  document.getElementById('kJams').textContent = waze.jams.length;
  renderFilters(waze);
  renderAlertList(waze);
}

/* ===== ICONO CUSTOM SVG PARA MARKERS ===== */
function makeAlertIcon(color, isFocus) {
  const size = isFocus ? 28 : 22;
  const ring = isFocus
    ? `<circle cx="14" cy="14" r="13" fill="none" stroke="${color}" stroke-width="2" opacity="0.6"/>`
    : '';
  const svg = `
    <svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 28 28">
      ${ring}
      <circle cx="14" cy="14" r="8" fill="${color}" stroke="#fff" stroke-width="2"/>
      <circle cx="14" cy="14" r="3" fill="#fff"/>
    </svg>`;
  return {
    url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg),
    anchor: new google.maps.Point(size/2, size/2),
    scaledSize: new google.maps.Size(size, size),
  };
}

/* ===== FILTROS POR TIPO ===== */
function renderFilters(waze) {
  const cont = document.getElementById('filters');
  cont.innerHTML = '';
  const tipos = Object.keys(waze.por_tipo || {});
  if (tipos.length === 0) {
    cont.innerHTML = '<div style="color:#5970a8;font-family:Space Mono,monospace;font-size:10px;padding:6px 0">sin alertas en el área</div>';
    return;
  }
  const todosBtn = document.createElement('button');
  todosBtn.className = 'flt' + (alertsFilter.size === 0 ? ' active' : '');
  todosBtn.textContent = 'Todos';
  todosBtn.addEventListener('click', () => {
    alertsFilter.clear();
    subtypeFilter.clear();
    renderWaze(waze);
  });
  cont.appendChild(todosBtn);

  tipos.forEach(t => {
    const vis = ALERT_VISUAL[t] || ALERT_VISUAL.OTHER;
    const n = waze.por_tipo[t];
    const b = document.createElement('button');
    b.className = 'flt' + (alertsFilter.has(t) ? ' active' : '');
    b.textContent = `${vis.icon} ${vis.label} ${n}`;
    b.addEventListener('click', () => {
      if (alertsFilter.has(t)) alertsFilter.delete(t);
      else alertsFilter.add(t);
      renderWaze(waze);
    });
    cont.appendChild(b);
  });

  renderSubfilters(waze);
  wireGeo(waze);
}

/* ===== SUBCATEGORÍAS (subtipos Waze) ===== */
function renderSubfilters(waze){
  const cont = document.getElementById('subfilters');
  if (!cont) return;
  cont.innerHTML = '';
  // Contar subtipos sobre las alertas que pasan los filtros de categoría/sección
  // (para que las subcategorías reflejen el contexto actual)
  const counts = {};
  for (const a of (waze.alerts || [])) {
    const okTipo = alertsFilter.size === 0 || alertsFilter.has(a.type);
    const okDist = !selDist || String(a.distrito_id) === String(selDist);
    const okSecc = !selSecc || String(a.seccion) === String(selSecc);
    if (!okTipo || !okDist || !okSecc) continue;
    const s = a.subtype || '';
    if (!s) continue;                       // ignoramos las sin subtipo en este filtro
    counts[s] = (counts[s] || 0) + 1;
  }
  const subs = Object.keys(counts).sort((a,b)=>counts[b]-counts[a]);
  if (subs.length === 0) return;            // .subfilters:empty se oculta por CSS

  subs.forEach(s => {
    const tipo = s.split('_')[0];
    const b = document.createElement('button');
    b.className = 'subflt' + (subtypeFilter.has(s) ? ' active' : '');
    b.textContent = `${subLabel(s, tipo)} ${counts[s]}`;
    b.addEventListener('click', () => {
      if (subtypeFilter.has(s)) subtypeFilter.delete(s);
      else subtypeFilter.add(s);
      renderWaze(waze);
    });
    cont.appendChild(b);
  });
}

/* ===== Controles de distrito / sección (se enganchan una vez) ===== */
function wireGeo(){
  if (geoWired) return;
  const dSel = document.getElementById('f-distrito');
  const sInp = document.getElementById('f-seccion');
  if (dSel) dSel.addEventListener('change', () => {
    selDist = dSel.value;
    subtypeFilter.clear();                  // reset de subcategorías al cambiar ámbito
    applySeccionView();
    renderWaze(DATA.waze);
  });
  if (sInp) sInp.addEventListener('input', () => {
    selSecc = sInp.value.trim();
    applySeccionView();
    renderWaze(DATA.waze);
  });
  geoWired = true;
}

/* ===== LISTA DE ALERTAS ===== */
function renderAlertList(waze) {
  const list = document.getElementById('alertList');
  const sub  = document.getElementById('sideSub');
  list.innerHTML = '';

  const items = waze.alerts.filter(pasaFiltros);

  if (items.length === 0) {
    list.innerHTML = '<div class="alert-empty">Sin alertas para mostrar</div>';
    sub.textContent = '0 alertas';
    return;
  }
  sub.textContent = `${items.length} ${items.length === 1 ? 'alerta' : 'alertas'} activas`;

  for (const a of items) {
    const vis = ALERT_VISUAL[a.type] || ALERT_VISUAL.OTHER;
    const div = document.createElement('div');
    div.className = `alert t-${a.type}` + (a.uuid === focusUuid ? ' is-focus' : '');
    div.innerHTML = `
      <div class="icon">${vis.icon}</div>
      <div class="info">
        <div class="ttl">${vis.label}${a.subtype ? ' · ' + escapeHtml(subLabel(a.subtype, a.type)) : ''}</div>
        <div class="st">${escapeHtml(a.street || 'Calle desconocida')}</div>
        <div class="meta">${minutosDesde(a.pubMillis)} · conf ${a.reliability}/10${a.seccion ? ' · ' + escapeHtml(seccTxt(a)) : ''}</div>
      </div>`;
    div.addEventListener('click', () => {
      map.panTo({lat: a.lat, lng: a.lng});
      if (map.getZoom() < 16) map.setZoom(16);
      focusUuid = a.uuid;
      renderAlertList(waze);
      // resaltar el marker correspondiente abriendo info
      const m = waze_markers.find(x => x.getTitle().includes(a.street || ''));
      if (m) google.maps.event.trigger(m, 'click');
    });
    list.appendChild(div);
  }
}

/* ===== AUTO-REFRESH WAZE ===== */
async function refreshWaze(force) {
  const btn = document.getElementById('btnRefresh');
  btn.disabled = true;
  try {
    const url = 'mapa_riesgos.php?ajax=waze' + (force ? '&force=1' : '');
    const r = await fetch(url, {cache: 'no-store'});
    const waze = await r.json();
    DATA.waze = waze;
    renderWaze(waze);
    updateFeedStatus(waze);
    lastRefresh = Date.now();
  } catch (e) {
    document.getElementById('feedStatus').className = 'feed-status err';
    document.getElementById('feedLbl').textContent = 'error fetch';
  } finally {
    btn.disabled = false;
  }
}
document.getElementById('btnRefresh').addEventListener('click', () => refreshWaze(true));

function updateFeedStatus(waze) {
  const el = document.getElementById('feedStatus');
  const lbl = document.getElementById('feedLbl');
  if (!waze.ok) {
    el.className = 'feed-status err';
    lbl.textContent = 'feed error';
    lbl.title = waze.error || '';
    return;
  }
  const age = waze.cache_age || 0;
  el.className = 'feed-status' + (age > 240 ? ' stale' : '');
  lbl.textContent = waze.fuente === 'live'
    ? 'feed en vivo'
    : `cache · ${age}s`;
}

/* ===== HELPERS ===== */
function escapeHtml(s) {
  return (s || '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
}
function minutosDesde(millis) {
  if (!millis) return 'hace ¿?';
  const min = Math.floor((Date.now() - millis) / 60000);
  if (min < 1) return 'hace segundos';
  if (min < 60) return `hace ${min} min`;
  const h = Math.floor(min / 60);
  if (h < 24) return `hace ${h} h`;
  return `hace ${Math.floor(h/24)} d`;
}

/* re-render minutosDesde cada 30s para que envejezca */
setInterval(() => {
  if (DATA.waze && DATA.waze.ok) renderAlertList(DATA.waze);
}, 30000);
</script>

<script defer src="https://maps.googleapis.com/maps/api/js?key=<?= $api_key ?>&libraries=visualization&callback=initMap&v=quarterly"></script>

<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
