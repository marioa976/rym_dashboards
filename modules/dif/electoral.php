<?php
/**
 * electoral.php  —  Reporte electoral: cruce padrón DIF × secciones IEEQ.
 *
 * Requiere las tablas creadas por el dump IEEQ:
 *   municipios, distritos, secciones, secciones_geo
 *
 * Abre:  http://localhost:8888/dif/electoral.php
 */

declare(strict_types=1);

ini_set('memory_limit', '768M');
set_time_limit(0);

$config = require __DIR__ . '/config.php';
$db = $config['db'];
$pdo = new PDO(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}",
    $db['user'], $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$pdo->exec("SET NAMES utf8mb4");
// El índice espacial (R-tree) necesita un nivel de aislamiento que permita locks.
// Si el servidor quedó en READ-UNCOMMITTED, ST_Contains truena con error 1207.
$pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");

$gmapsKey = (string)($config['google_maps']['api_key'] ?? '');
$action = $_GET['action'] ?? '';

// =====================================================================
// AJAX endpoints
// =====================================================================
if ($action === 'counts') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        echo json_encode(getCountsByFilter($pdo, $_GET), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage(), 'counts'=>(object)[]]);
    }
    exit;
}
if ($action === 'section') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(getSectionDetail($pdo, (int)($_GET['id'] ?? 0), $_GET));
    exit;
}

// =====================================================================
// CARGA INICIAL
// =====================================================================

// Verificar que existen las tablas electorales
$ok = true;
$missing = [];
foreach (['municipios','distritos','secciones','secciones_geo'] as $t) {
    $exists = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name=?");
    $exists->execute([$db['name'], $t]);
    if (!$exists->fetchColumn()) { $ok = false; $missing[] = $t; }
}

$padronTieneActivo = false;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM padron")->fetchAll(PDO::FETCH_COLUMN);
    $padronTieneActivo = in_array('activo', $cols, true);
} catch (Throwable) { /* padron no existe */ }

$activoWhere = $padronTieneActivo ? "p.activo=1 AND " : "";

if ($ok) {
    // Polígonos + metadata (una sola query)
    $secs = $pdo->query("
        SELECT s.id, s.num_seccion, s.distrito_id, s.municipio, s.col_localidad,
               s.padron_total, s.ln_total, s.padron_h, s.padron_m,
               d.numero AS distrito_numero, d.nombre AS distrito_nombre,
               sg.wkt
          FROM secciones s
          JOIN secciones_geo sg ON sg.seccion_id = s.id
          LEFT JOIN distritos d ON d.id = s.distrito_id
         ORDER BY s.distrito_id, s.num_seccion
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Conteo de apoyos por sección (spatial join)
    $aidCounts = [];
    try {
        $rs = $pdo->query("
            SELECT sg.seccion_id, COUNT(*) AS n
              FROM padron p
              JOIN secciones_geo sg
                ON ST_Contains(sg.geom, ST_GeomFromText(CONCAT('POINT(',p.latitud,' ',p.longitud,')'), 4326))
             WHERE $activoWhere p.latitud IS NOT NULL AND p.longitud IS NOT NULL
             GROUP BY sg.seccion_id
        ");
        foreach ($rs as $r) $aidCounts[(int)$r['seccion_id']] = (int)$r['n'];
    } catch (Throwable $e) {
        $spatialError = $e->getMessage();
    }

    // Catálogos para filtros
    $programas    = $pdo->query("SELECT DISTINCT programa FROM padron WHERE programa IS NOT NULL ORDER BY programa")->fetchAll(PDO::FETCH_COLUMN);
    $tipos        = $pdo->query("SELECT DISTINCT tipo_apoyo FROM padron WHERE tipo_apoyo IS NOT NULL ORDER BY tipo_apoyo")->fetchAll(PDO::FETCH_COLUMN);
    $coordinaciones = $pdo->query("SELECT DISTINCT coordinacion FROM padron WHERE coordinacion IS NOT NULL ORDER BY coordinacion")->fetchAll(PDO::FETCH_COLUMN);
    $distritos    = $pdo->query("SELECT id, numero, nombre FROM distritos ORDER BY numero")->fetchAll(PDO::FETCH_ASSOC);

    // Inyectar el conteo en cada sección
    foreach ($secs as &$s) {
        $s['apoyos'] = $aidCounts[(int)$s['id']] ?? 0;
    }
    unset($s);
}

// ---------------------------------------------------------------------
// Endpoint helpers
// ---------------------------------------------------------------------
function getCountsByFilter(PDO $pdo, array $f): array
{
    $cols = $pdo->query("SHOW COLUMNS FROM padron")->fetchAll(PDO::FETCH_COLUMN);
    $tieneActivo = in_array('activo', $cols, true);

    $where = [];
    $params = [];
    if ($tieneActivo) $where[] = "p.activo=1";
    $where[] = "p.latitud IS NOT NULL AND p.longitud IS NOT NULL";

    if (!empty($f['programa']))    { $where[] = "p.programa=:programa";       $params[':programa']=$f['programa']; }
    if (!empty($f['tipo_apoyo']))  { $where[] = "p.tipo_apoyo=:tipo";         $params[':tipo']=$f['tipo_apoyo']; }
    if (!empty($f['coordinacion'])){ $where[] = "p.coordinacion=:coor";       $params[':coor']=$f['coordinacion']; }
    if (!empty($f['sexo']))        { $where[] = "p.sexo=:sexo";               $params[':sexo']=$f['sexo']; }
    if (!empty($f['recibe']))      { $where[] = "p.recibe_ciudadano=:rec";    $params[':rec']=$f['recibe']; }
    if (!empty($f['fecha_desde'])) { $where[] = "p.fecha_entrega>=:fd";       $params[':fd']=$f['fecha_desde']; }
    if (!empty($f['fecha_hasta'])) { $where[] = "p.fecha_entrega<=:fh";       $params[':fh']=$f['fecha_hasta']; }
    if (!empty($f['distrito_id'])) { $where[] = "sg.seccion_id IN (SELECT id FROM secciones WHERE distrito_id=:dist)"; $params[':dist']=(int)$f['distrito_id']; }

    $wsql = "WHERE " . implode(" AND ", $where);
    $sql = "
        SELECT sg.seccion_id, COUNT(*) AS n
          FROM padron p
          JOIN secciones_geo sg
            ON ST_Contains(sg.geom, ST_GeomFromText(CONCAT('POINT(',p.latitud,' ',p.longitud,')'), 4326))
         $wsql
         GROUP BY sg.seccion_id
    ";
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st as $r) $out[(int)$r['seccion_id']] = (int)$r['n'];
        // forzar objeto JSON (no array) para acceso por key en JS
        $countsObj = (object)$out;
        return [
            'ok'      => true,
            'counts'  => $countsObj,
            'total'   => array_sum($out),
            'matches' => count($out),
            'sql_filters' => array_keys($params),
        ];
    } catch (Throwable $e) {
        return ['ok'=>false, 'error'=>$e->getMessage(), 'counts'=>(object)[], 'total'=>0];
    }
}

function getSectionDetail(PDO $pdo, int $id, array $f): array
{
    if ($id <= 0) return ['error' => 'id inválido'];
    $cols = $pdo->query("SHOW COLUMNS FROM padron")->fetchAll(PDO::FETCH_COLUMN);
    $tieneActivo = in_array('activo', $cols, true);
    $aw = $tieneActivo ? "p.activo=1 AND " : "";

    $sec = $pdo->prepare("
        SELECT s.*, d.numero AS distrito_numero, d.nombre AS distrito_nombre
          FROM secciones s LEFT JOIN distritos d ON d.id = s.distrito_id
         WHERE s.id = ?");
    $sec->execute([$id]);
    $secRow = $sec->fetch(PDO::FETCH_ASSOC);

    // Top programas/tipos en esa sección
    $topProg = $pdo->prepare("
        SELECT p.programa, COUNT(*) n
          FROM padron p
          JOIN secciones_geo sg ON sg.seccion_id=:id
         WHERE $aw p.latitud IS NOT NULL
           AND ST_Contains(sg.geom, ST_GeomFromText(CONCAT('POINT(',p.latitud,' ',p.longitud,')'), 4326))
         GROUP BY p.programa ORDER BY n DESC LIMIT 10
    ");
    $topProg->execute([':id' => $id]);

    $topTipo = $pdo->prepare("
        SELECT p.tipo_apoyo, COUNT(*) n
          FROM padron p
          JOIN secciones_geo sg ON sg.seccion_id=:id
         WHERE $aw p.latitud IS NOT NULL
           AND ST_Contains(sg.geom, ST_GeomFromText(CONCAT('POINT(',p.latitud,' ',p.longitud,')'), 4326))
         GROUP BY p.tipo_apoyo ORDER BY n DESC LIMIT 10
    ");
    $topTipo->execute([':id' => $id]);

    $apoyos = $pdo->prepare("
        SELECT COUNT(*) n
          FROM padron p
          JOIN secciones_geo sg ON sg.seccion_id=:id
         WHERE $aw p.latitud IS NOT NULL
           AND ST_Contains(sg.geom, ST_GeomFromText(CONCAT('POINT(',p.latitud,' ',p.longitud,')'), 4326))");
    $apoyos->execute([':id'=>$id]);

    return [
        'seccion' => $secRow,
        'apoyos'  => (int)$apoyos->fetchColumn(),
        'top_programas' => $topProg->fetchAll(PDO::FETCH_ASSOC),
        'top_tipos'     => $topTipo->fetchAll(PDO::FETCH_ASSOC),
    ];
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Reporte Electoral DIF — Querétaro</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --bg:#f5f7fa; --panel:#ffffff; --bd:#e2e8f0; --fg:#0f172a; --mut:#64748b;
    --accent:#254185; --accent2:#005ab2;
    --ok:#188a5b; --warn:#d99000; --err:#ce3a2b;
    --shadow:0 1px 3px rgba(15,23,42,.05);
  }
  *{box-sizing:border-box}
  html,body{margin:0;background:var(--bg);color:var(--fg);
            font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Inter',sans-serif;
            font-size:14px;line-height:1.5}
  .topbar{background:#0f172a;color:#fff;padding:14px 24px;
          display:flex;align-items:center;gap:16px;flex-wrap:wrap;
          box-shadow:0 2px 8px rgba(0,0,0,.1)}
  .topbar h1{margin:0;font-size:17px;font-weight:600}
  .topbar .subtitle{font-size:12px;color:#94a3b8}
  .topbar .spacer{flex:1}
  .topbar .nav{display:flex;gap:6px}
  .topbar .nav a{padding:6px 12px;border-radius:6px;color:#cbd5e1;font-size:12px;
                 background:#1e293b;text-decoration:none}
  .topbar .nav a:hover{background:#334155;color:#fff}
  .topbar .nav a.active{background:#fff;color:#0f172a}

  .container{padding:18px 24px}
  .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px}
  .kpi{background:var(--panel);border:1px solid var(--bd);border-radius:8px;padding:14px;box-shadow:var(--shadow)}
  .kpi .lbl{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.3px;font-weight:600}
  .kpi .val{font-size:24px;font-weight:700;margin-top:6px}
  .kpi .val.ok{color:var(--ok)} .kpi .val.warn{color:var(--warn)}

  .layout{display:grid;grid-template-columns:300px 1fr 320px;gap:14px;height:calc(100vh - 240px)}
  .side{background:var(--panel);border:1px solid var(--bd);border-radius:8px;
        padding:14px;overflow:auto;box-shadow:var(--shadow)}
  #map{border:1px solid var(--bd);border-radius:8px;overflow:hidden;height:100%;background:#e5e7eb}

  h3.t{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.3px;
       margin:0 0 8px;padding-bottom:5px;border-bottom:1px solid var(--bd);font-weight:600}
  h3.t:not(:first-child){margin-top:16px}

  .field{display:flex;flex-direction:column;gap:3px;margin-bottom:8px}
  .field label{font-size:11px;color:var(--mut);font-weight:600;text-transform:uppercase;letter-spacing:.3px}
  .field input,.field select{
    background:#fff;border:1px solid var(--bd);border-radius:6px;padding:7px 10px;font-size:13px;width:100%}
  .field input:focus,.field select:focus{outline:none;border-color:var(--accent2)}

  .btn{background:var(--accent);color:#fff;border:0;border-radius:6px;
       padding:8px 14px;font-size:13px;font-weight:500;cursor:pointer;width:100%}
  .btn:hover{filter:brightness(1.15)}
  .btn.ghost{background:transparent;color:var(--mut);border:1px solid var(--bd)}
  .btn.ghost:hover{background:#f1f5f9}

  .ranking{font-size:12px}
  .ranking .row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px dashed var(--bd);cursor:pointer}
  .ranking .row:last-child{border:0}
  .ranking .row:hover{background:#f8fafc}
  .ranking .row strong{color:var(--accent)}
  .badge{display:inline-block;padding:1px 6px;border-radius:3px;font-size:10px;font-weight:600;background:#e2e8f0;color:#475569}

  /* Legend */
  .legend{background:rgba(255,255,255,.95);border-radius:8px;padding:10px;
          position:absolute;bottom:24px;left:24px;box-shadow:0 4px 12px rgba(0,0,0,.15);
          font-size:11px;border:1px solid var(--bd);z-index:10}
  .legend h4{margin:0 0 6px;font-size:11px;color:var(--mut);text-transform:uppercase}
  .legend .item{display:flex;align-items:center;gap:6px;margin:2px 0}
  .legend .sw{width:18px;height:10px;border:1px solid #999;border-radius:2px}

  .err-banner{background:var(--err);color:#fff;padding:14px 24px;font-size:13px}
  .ok-banner{background:rgba(22,163,74,.08);color:#166534;padding:10px 14px;border:1px solid rgba(22,163,74,.2);border-radius:6px;font-size:13px;margin-bottom:14px}

  .small{font-size:11.5px;color:var(--mut)}

  @media (max-width: 1100px){
    .layout{grid-template-columns:1fr;height:auto}
    #map{height:560px}
  }
</style>
</head>
<body>

<?php $portalModulo='DIF'; @include __DIR__.'/../_portalbar.php'; ?>
<?php $navActive = 'electoral'; include __DIR__ . '/_nav.php'; ?>

<?php if (!$ok): ?>
<div class="err-banner">
  ⚠️ Faltan tablas del catálogo IEEQ en la base <strong><?= htmlspecialchars($db['name']) ?></strong>: <?= implode(', ', $missing) ?>.<br>
  Importa el dump primero:<br>
  <code style="font-family:monospace">mysql -uroot -proot <?= htmlspecialchars($db['name']) ?> &lt; ieeq_geo.sql</code>
</div>
<?php else: ?>

<div class="container">

  <!-- KPIs -->
  <div class="kpis" id="kpis"></div>

  <?php if (!empty($spatialError)): ?>
    <div class="err-banner">Error en el spatial join: <?= htmlspecialchars($spatialError) ?></div>
  <?php endif; ?>

  <div class="layout">

    <!-- IZQUIERDA: Filtros -->
    <div class="side">
      <h3 class="t">Filtros</h3>

      <div class="field"><label>Distrito local</label>
        <select id="f-distrito" onchange="redraw()">
          <option value="">— todos —</option>
          <?php foreach ($distritos as $d): ?>
            <option value="<?= $d['id'] ?>">D<?= $d['numero'] ?> — <?= htmlspecialchars($d['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field"><label>Programa</label>
        <select id="f-programa">
          <option value="">— todos —</option>
          <?php foreach ($programas as $p): ?>
            <option><?= htmlspecialchars($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field"><label>Tipo de apoyo</label>
        <select id="f-tipo">
          <option value="">— todos —</option>
          <?php foreach ($tipos as $t): ?>
            <option><?= htmlspecialchars($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field"><label>Coordinación</label>
        <select id="f-coord">
          <option value="">— todas —</option>
          <?php foreach ($coordinaciones as $c): ?>
            <option><?= htmlspecialchars($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field"><label>Sexo</label>
        <select id="f-sexo">
          <option value="">— ambos —</option>
          <option>MUJER</option><option>HOMBRE</option>
        </select>
      </div>

      <div class="field"><label>¿Recibe el ciudadano?</label>
        <select id="f-recibe">
          <option value="">— todos —</option>
          <option>SI</option><option>NO</option>
        </select>
      </div>

      <div class="field"><label>Entrega desde</label><input type="date" id="f-fd"></div>
      <div class="field"><label>Entrega hasta</label><input type="date" id="f-fh"></div>

      <button class="btn" id="btn-aplicar" onclick="aplicar()">🔎 Aplicar</button>
      <button class="btn ghost" onclick="limpiar()" style="margin-top:6px">🧹 Limpiar</button>
      <div id="filter-status" style="margin-top:8px;font-size:11.5px;color:var(--mut);min-height:16px"></div>

      <h3 class="t">Mapa</h3>
      <div class="field"><label>Modo color</label>
        <select id="f-color" onchange="redraw()">
          <option value="apoyos">Apoyos DIF (choropleth)</option>
          <option value="distrito">Color por distrito</option>
          <option value="density">Apoyos / Lista nominal (%)</option>
        </select>
      </div>
      <div class="field">
        <label><input type="checkbox" id="ly-bord" checked onchange="redraw()"> Mostrar bordes</label>
      </div>
    </div>

    <!-- CENTRO: Mapa -->
    <div style="position:relative">
      <div id="map"></div>
      <div class="legend" id="legend"></div>
    </div>

    <!-- DERECHA: Rankings + Detalle -->
    <div class="side">
      <h3 class="t">Top 10 distritos por apoyos</h3>
      <div class="ranking" id="rank-distrito"></div>

      <h3 class="t">Top 15 secciones por apoyos</h3>
      <div class="ranking" id="rank-secciones"></div>

      <h3 class="t">Detalle de sección</h3>
      <div id="detalle">
        <div class="small">Da click en una sección del mapa para ver detalles.</div>
      </div>
    </div>

  </div>
</div>

<script>
// =====================================================================
// Datos del servidor
// =====================================================================
const SECCIONES = <?= json_encode($secs, JSON_UNESCAPED_UNICODE) ?>;
const GMAPS_KEY = <?= json_encode($gmapsKey) ?>;
const DISTRITOS = <?= json_encode($distritos, JSON_UNESCAPED_UNICODE) ?>;
const DISTRITO_COLORS = ['#254185','#005ab2','#188a5b','#d99000','#2a9eda',
                        '#ce3a2b','#1a2f63','#5b667a','#ca8a04','#475569',
                        '#7c3aed','#db2777','#0369a1','#15803d','#a16207'];

// =====================================================================
// Google Maps loader
// =====================================================================
(g=>{var h,a,k,p="The Google Maps JavaScript API",c="google",l="importLibrary",q="__ib__",m=document,b=window;b=b[c]||(b[c]={});var d=b.maps||(b.maps={}),r=new Set,e=new URLSearchParams,u=()=>h||(h=new Promise(async(f,n)=>{await (a=m.createElement("script"));e.set("libraries",[...r]+"");for(k in g)e.set(k.replace(/[A-Z]/g,t=>"_"+t[0].toLowerCase()),g[k]);e.set("callback",c+".maps."+q);a.src=`https://maps.${c}apis.com/maps/api/js?`+e;d[q]=f;a.onerror=()=>h=n(Error(p+" could not load."));a.nonce=m.querySelector("script[nonce]")?.nonce||"";m.head.append(a)}));d[l]?console.warn(p+" only loads once. Ignoring:",g):d[l]=(f,...n)=>r.add(f)&&u().then(()=>d[l](f,...n))})({key:GMAPS_KEY,v:"weekly",region:"MX",language:"es"});

let map, polygons = [], LatLngBoundsCls, infoWin;

// =====================================================================
// Parseo WKT → array de paths
// El dump tiene "lat lng" en cada par (orden no estándar, pero consistente).
// =====================================================================
function parseWkt(wkt) {
  // POLYGON((a b,a b,...)) — devolvemos un array (ring) de {lat,lng}
  const m = wkt.match(/POLYGON\s*\(\s*\(([^)]+)\)\s*\)/i);
  if (!m) return [];
  return m[1].split(',').map(pt => {
    const [a,b] = pt.trim().split(/\s+/).map(Number);
    return { lat: a, lng: b };
  });
}

// =====================================================================
// Color helpers — choropleth por quantiles
// =====================================================================
function colorScale(value, max, mode){
  if (mode === 'distrito') return '#000';
  if (max === 0) return '#cbd5e1';
  const r = value / max;
  if (r === 0)     return '#f1f5f9';
  if (r <= 0.05)  return '#fef3c7';
  if (r <= 0.15)  return '#fde68a';
  if (r <= 0.30)  return '#fbbf24';
  if (r <= 0.50)  return '#f59e0b';
  if (r <= 0.75)  return '#ea580c';
  return '#b91c1c';
}

let CURRENT_COUNTS = {};
let CURRENT_MAX = 0;

// =====================================================================
// Init
// =====================================================================
async function init(){
  const mapsLib = await google.maps.importLibrary("maps");
  const core    = await google.maps.importLibrary("core");
  LatLngBoundsCls = core.LatLngBounds;

  map = new mapsLib.Map(document.getElementById('map'), {
    center: { lat: 20.5888, lng: -100.3899 },
    zoom: 11,
    mapTypeId: 'roadmap',
    streetViewControl: false,
    fullscreenControl: true,
  });
  infoWin = new mapsLib.InfoWindow();

  // Conteo inicial: viene precomputado en SECCIONES[i].apoyos
  CURRENT_COUNTS = {};
  for (const s of SECCIONES) CURRENT_COUNTS[s.id] = s.apoyos || 0;
  CURRENT_MAX = Math.max(0, ...Object.values(CURRENT_COUNTS));

  drawPolygons();
  fitToData();
  renderKPIs();
  renderRankings();
  renderLegend();
}
init().catch(e => {
  document.getElementById('map').innerHTML =
    '<div style="padding:20px;color:#ce3a2b">No se pudo cargar Google Maps. Habilita Maps JavaScript API en Google Cloud Console.</div>';
  console.error(e);
});

// =====================================================================
// Dibujar polígonos
// =====================================================================
function drawPolygons(){
  polygons.forEach(p => p.setMap(null));
  polygons = [];

  const mode = document.getElementById('f-color').value;
  const showBord = document.getElementById('ly-bord').checked;
  const selDist = document.getElementById('f-distrito').value;   // distrito_id o ''

  for (const s of SECCIONES) {
    const path = parseWkt(s.wkt);
    if (!path.length) continue;

    // ¿La sección pertenece al distrito seleccionado? (si no hay selección, todas)
    const enDistrito = !selDist || String(s.distrito_id) === String(selDist);

    const apoyos = CURRENT_COUNTS[s.id] || 0;
    let fill;
    if (!enDistrito) {
      fill = '#e5e9f0';                       // gris neutro: fuera del distrito
    } else if (mode === 'distrito') {
      fill = DISTRITO_COLORS[((s.distrito_numero||1)-1) % DISTRITO_COLORS.length];
    } else if (mode === 'density') {
      const ln = +s.ln_total || 0;
      const pct = ln > 0 ? apoyos/ln : 0;
      fill = colorScale(pct*1000, 50, 'density');
    } else {
      fill = colorScale(apoyos, CURRENT_MAX, 'apoyos');
    }

    const baseFill   = enDistrito ? 0.6  : 0.12;   // atenuar las de fuera
    const baseStroke = enDistrito ? (showBord ? 0.4 : 0) : 0.12;

    const poly = new google.maps.Polygon({
      paths: path,
      strokeColor: showBord ? '#1e293b' : 'transparent',
      strokeOpacity: baseStroke,
      strokeWeight: 0.8,
      fillColor: fill,
      fillOpacity: baseFill,
    });
    poly.setMap(map);
    poly.dif_seccion = s;
    if (enDistrito) {
      poly.addListener('click', (ev) => onSectionClick(s, ev.latLng));
      poly.addListener('mouseover', () => poly.setOptions({ fillOpacity: 0.85, strokeOpacity: 0.8, strokeWeight: 1.5 }));
      poly.addListener('mouseout',  () => poly.setOptions({ fillOpacity: baseFill, strokeOpacity: baseStroke, strokeWeight: 0.8 }));
    }
    polygons.push(poly);
  }
}

function redraw(){ drawPolygons(); renderLegend(); }

function fitToData(){
  if (!polygons.length) return;
  const b = new LatLngBoundsCls();
  polygons.forEach(p => p.getPath().forEach(pt => b.extend(pt)));
  map.fitBounds(b);
}

// =====================================================================
// Click en sección → carga detalle
// =====================================================================
async function onSectionClick(s, latLng){
  // Loader rápido en infowindow
  infoWin.setContent(`<div style="padding:6px"><b>Sección ${s.num_seccion}</b><br>Cargando…</div>`);
  infoWin.setPosition(latLng);
  infoWin.open(map);

  const f = readFilters();
  const qs = new URLSearchParams({ action:'section', id:s.id, ...f });
  const r = await fetch('?' + qs).then(r=>r.json());

  let html = `<div style="font-size:12px;max-width:280px">
    <div><b>Sección ${s.num_seccion}</b> · D${s.distrito_numero || '?'}</div>
    <div class="small">${escapeHtml(s.municipio||'')}</div>
    <div style="margin:6px 0"><span class="badge">Lista nominal: ${(s.ln_total||0).toLocaleString()}</span>
      <span class="badge" style="background:#dbeafe;color:#254185">Apoyos: ${(r.apoyos||0).toLocaleString()}</span></div>`;
  if (r.top_programas && r.top_programas.length) {
    html += `<div style="margin-top:8px"><strong>Top programas:</strong><ol style="margin:4px 0 0 18px;padding:0">`;
    r.top_programas.slice(0,5).forEach(p => html += `<li>${escapeHtml(p.programa||'')} <span class="small">(${p.n})</span></li>`);
    html += '</ol></div>';
  }
  if (s.col_localidad) {
    html += `<div style="margin-top:6px" class="small">${escapeHtml(s.col_localidad.substring(0,200))}${s.col_localidad.length>200?'…':''}</div>`;
  }
  html += '</div>';

  infoWin.setContent(html);
  renderDetalle(s, r);
}

function renderDetalle(s, r){
  let h = `<div><strong>Sección ${s.num_seccion}</strong> · D${s.distrito_numero || '?'}</div>
    <div class="small">${escapeHtml(s.municipio||'')}</div>
    <div style="margin:8px 0">
      <span class="badge">Padrón ${(s.padron_total||0).toLocaleString()}</span>
      <span class="badge">LN ${(s.ln_total||0).toLocaleString()}</span>
      <span class="badge" style="background:#dbeafe;color:#254185">Apoyos ${(r.apoyos||0).toLocaleString()}</span>
    </div>`;
  if (r.top_programas?.length) {
    h += '<h3 class="t" style="margin-top:8px">Programas</h3><div class="ranking">';
    r.top_programas.forEach(p => h += `<div class="row"><span>${escapeHtml(p.programa||'')}</span><strong>${p.n}</strong></div>`);
    h += '</div>';
  }
  if (r.top_tipos?.length) {
    h += '<h3 class="t" style="margin-top:8px">Tipos de apoyo</h3><div class="ranking">';
    r.top_tipos.forEach(p => h += `<div class="row"><span>${escapeHtml(p.tipo_apoyo||'')}</span><strong>${p.n}</strong></div>`);
    h += '</div>';
  }
  document.getElementById('detalle').innerHTML = h;
}

// =====================================================================
// KPIs
// =====================================================================
function renderKPIs(){
  let total = 0, conApoyos = 0, max = 0, maxId = null;
  const byDistrito = {};
  for (const s of SECCIONES) {
    const n = CURRENT_COUNTS[s.id] || 0;
    total += n;
    if (n > 0) conApoyos++;
    if (n > max) { max = n; maxId = s; }
    byDistrito[s.distrito_id] = (byDistrito[s.distrito_id]||0) + n;
  }
  let topD = null, topDn = 0;
  for (const [did, n] of Object.entries(byDistrito)) {
    if (n > topDn) { topDn = n; topD = DISTRITOS.find(d => +d.id === +did); }
  }
  const cards = [
    ['Total apoyos georef.', total.toLocaleString()],
    ['Secciones con apoyos', conApoyos + ' / ' + SECCIONES.length],
    ['Promedio por sección', conApoyos > 0 ? (total/conApoyos).toFixed(1) : '0'],
    ['Sección más atendida', maxId ? `S${maxId.num_seccion} (${max})` : '—'],
    ['Distrito más atendido', topD ? `D${topD.numero} (${topDn.toLocaleString()})` : '—'],
    ['Distritos atendidos', Object.values(byDistrito).filter(n=>n>0).length],
  ];
  document.getElementById('kpis').innerHTML = cards.map(([l,v]) =>
    `<div class="kpi"><div class="lbl">${l}</div><div class="val">${v}</div></div>`
  ).join('');
}

// =====================================================================
// Rankings
// =====================================================================
function renderRankings(){
  // Por distrito
  const byD = {};
  for (const s of SECCIONES) {
    const did = s.distrito_id;
    if (!byD[did]) byD[did] = { distrito: s, n:0, secciones:0 };
    byD[did].n += CURRENT_COUNTS[s.id] || 0;
    if ((CURRENT_COUNTS[s.id]||0) > 0) byD[did].secciones++;
  }
  const top = Object.values(byD).sort((a,b)=>b.n-a.n).slice(0,10);
  document.getElementById('rank-distrito').innerHTML = top.map(x => {
    const d = DISTRITOS.find(d => +d.id === +x.distrito.distrito_id);
    return `<div class="row" onclick="zoomDistrito(${x.distrito.distrito_id})">
      <span>D${d?d.numero:'?'} — ${escapeHtml((d?d.nombre:'').replace('Distrito Local ',''))}</span>
      <strong>${x.n.toLocaleString()}</strong>
    </div>`;
  }).join('') || '<div class="small">Sin datos</div>';

  // Por sección
  const topS = SECCIONES.map(s => ({ ...s, n: CURRENT_COUNTS[s.id]||0 }))
                        .sort((a,b)=>b.n-a.n).slice(0,15);
  document.getElementById('rank-secciones').innerHTML = topS.map(s =>
    `<div class="row" onclick="zoomSeccion(${s.id})">
      <span>S${s.num_seccion} <span class="badge">D${s.distrito_numero||'?'}</span></span>
      <strong>${s.n.toLocaleString()}</strong>
    </div>`
  ).join('');
}

function zoomDistrito(did) {
  const b = new LatLngBoundsCls();
  let any = false;
  for (const p of polygons) {
    if (p.dif_seccion.distrito_id === did) {
      p.getPath().forEach(pt => b.extend(pt));
      any = true;
    }
  }
  if (any) map.fitBounds(b);
}
function zoomSeccion(sid) {
  const p = polygons.find(p => p.dif_seccion.id === sid);
  if (!p) return;
  const b = new LatLngBoundsCls();
  p.getPath().forEach(pt => b.extend(pt));
  map.fitBounds(b);
  setTimeout(() => onSectionClick(p.dif_seccion, p.getPath().getAt(0)), 300);
}

// =====================================================================
// Legend
// =====================================================================
function renderLegend(){
  const mode = document.getElementById('f-color').value;
  let html = `<h4>Color: ${mode==='distrito'?'Distrito':mode==='density'?'Densidad (apoyos/LN)':'Apoyos DIF'}</h4>`;
  if (mode === 'distrito') {
    DISTRITOS.slice(0,15).forEach((d,i) => {
      html += `<div class="item"><span class="sw" style="background:${DISTRITO_COLORS[i % DISTRITO_COLORS.length]}"></span>D${d.numero}</div>`;
    });
  } else {
    const max = CURRENT_MAX;
    const buckets = [0, .05, .15, .30, .50, .75, 1];
    const labels = ['0', `1-${Math.round(max*.05)}`, `-${Math.round(max*.15)}`, `-${Math.round(max*.30)}`, `-${Math.round(max*.50)}`, `-${Math.round(max*.75)}`, `-${max}`];
    buckets.forEach((b,i) => {
      html += `<div class="item"><span class="sw" style="background:${colorScale(b*max + .001, max, 'apoyos')}"></span>${labels[i]}</div>`;
    });
  }
  document.getElementById('legend').innerHTML = html;
}

// =====================================================================
// Filtros
// =====================================================================
function readFilters(){
  return {
    distrito_id: document.getElementById('f-distrito').value,
    programa:    document.getElementById('f-programa').value,
    tipo_apoyo:  document.getElementById('f-tipo').value,
    coordinacion:document.getElementById('f-coord').value,
    sexo:        document.getElementById('f-sexo').value,
    recibe:      document.getElementById('f-recibe').value,
    fecha_desde: document.getElementById('f-fd').value,
    fecha_hasta: document.getElementById('f-fh').value,
  };
}
async function aplicar(){
  const btn = document.getElementById('btn-aplicar');
  const status = document.getElementById('filter-status');
  const f = readFilters();

  // Quitar valores vacíos para no mandarlos
  const qsObj = { action:'counts' };
  for (const k of Object.keys(f)) {
    if (f[k] !== '' && f[k] != null) qsObj[k] = f[k];
  }
  const qs = new URLSearchParams(qsObj);
  const url = '?' + qs;

  btn.disabled = true;
  btn.textContent = '⏳ Aplicando...';
  status.textContent = 'Consultando spatial join...';
  status.style.color = '#64748b';

  try {
    console.log('[electoral] GET', url);
    const res = await fetch(url);
    const txt = await res.text();
    let r;
    try { r = JSON.parse(txt); }
    catch (e) {
      throw new Error('Respuesta no es JSON. Status ' + res.status + '. Inicio: ' + txt.substring(0, 200));
    }
    console.log('[electoral] response', r);

    if (r.ok === false) {
      throw new Error(r.error || 'Error desconocido del backend');
    }

    CURRENT_COUNTS = r.counts || {};
    const values = Object.values(CURRENT_COUNTS).map(Number);
    CURRENT_MAX = values.length ? Math.max(0, ...values) : 0;

    drawPolygons();
    renderKPIs();
    renderRankings();
    renderLegend();
    if (f.distrito_id) zoomDistrito(+f.distrito_id);

    const total = r.total || 0;
    const matches = r.matches || 0;
    status.textContent = `✓ ${total.toLocaleString()} apoyos en ${matches} secciones (filtros: ${(r.sql_filters||[]).length})`;
    status.style.color = total > 0 ? 'var(--ok)' : 'var(--warn)';
  } catch (e) {
    console.error('[electoral] error', e);
    status.textContent = '✗ ' + e.message;
    status.style.color = 'var(--err)';
  } finally {
    btn.disabled = false;
    btn.textContent = '🔎 Aplicar';
  }
}
function limpiar(){
  ['f-distrito','f-programa','f-tipo','f-coord','f-sexo','f-recibe','f-fd','f-fh']
    .forEach(id => document.getElementById(id).value='');
  aplicar();
}

function escapeHtml(s){ return String(s||'').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
</script>

<?php endif; ?>

</body>
</html>
