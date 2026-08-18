<?php
/**
 * secciones.php — Reporte de tickets Zendesk × secciones electorales IEEQ.
 * Cruce espacial (ST_Contains) de los tickets georreferenciados contra los
 * polígonos de secciones. Choropleth por: total, % resueltos, % vencidos, distrito.
 *
 * Requiere las tablas IEEQ: secciones, secciones_geo, distritos.
 */
declare(strict_types=1);
ini_set('memory_limit', '768M');
set_time_limit(0);

require __DIR__ . '/db.php';          // dispara el guard del portal (zendesk)
require_once __DIR__ . '/_filtro_form.php';   // filtro por formulario de Zendesk
$pdo = db();
$cfg = require __DIR__ . '/config.php';
$gmapsKey = (string)($cfg['google_maps_api_key'] ?? '');
$db_name  = (string)($cfg['database'] ?? '');
$action   = $_GET['action'] ?? '';

// ---------------------------------------------------------------------
// SQL helpers
// ---------------------------------------------------------------------
// Ya no se hace cruce espacial en el request: el reporte usa tickets.seccion_id
// precalculado por el importador. Solo queda el join al estado.
$JOINEST = "LEFT JOIN cat_estado e ON e.id = t.estado_id";

function buildWhere(array $f): array {
    $where = []; $params = [];
    if (!empty($f['grupo']))         { $where[] = "t.grupo_id = :grupo";          $params[':grupo'] = (int)$f['grupo']; }
    if (!empty($f['delegacion']))    { $where[] = "t.delegacion_id = :deleg";     $params[':deleg'] = (int)$f['delegacion']; }
    if (!empty($f['canal']))         { $where[] = "t.canal_origen_id = :canal";   $params[':canal'] = (int)$f['canal']; }
    if (!empty($f['tipo_servicio'])) { $where[] = "t.tipo_servicio_id = :serv";   $params[':serv']  = (int)$f['tipo_servicio']; }
    if (!empty($f['from']))          { $where[] = "t.fecha_creacion >= :from";    $params[':from']  = $f['from']; }
    if (!empty($f['to']))            { $where[] = "t.fecha_creacion <= :to";      $params[':to']    = $f['to']; }
    $est = $f['estado'] ?? '';
    if ($est === 'resuelto')     $where[] = "e.es_resuelto = 1";
    if ($est === 'sin_resolver') $where[] = "e.es_resuelto = 0";
    if ($est === 'abierto')      $where[] = "e.nombre = 'Abierto'";
    if ($est === 'vencido')      $where[] = "e.es_resuelto = 0 AND t.fecha_estimada < CURDATE()";
    if (!empty($f['distrito']))  { $where[] = "t.seccion_id IN (SELECT id FROM secciones WHERE distrito_id = :dist)"; $params[':dist'] = (int)$f['distrito']; }
    // Formulario de Zendesk (default: Servicios). El id se valida contra el catálogo.
    $where[] = zd_form_sql('t', isset($f['form']) && $f['form'] !== '' ? (string)$f['form'] : null);
    return [$where, $params];
}

function countsByFilter(PDO $pdo, array $f): array {
    global $JOINEST;
    [$where, $params] = buildWhere($f);
    $where[] = "t.seccion_id IS NOT NULL";
    $wsql = "WHERE " . implode(" AND ", $where);
    $sql = "SELECT t.seccion_id,
                   COUNT(*) AS n,
                   COALESCE(SUM(e.es_resuelto),0) AS resueltos,
                   SUM(CASE WHEN e.es_resuelto = 0 AND t.fecha_estimada < CURDATE() THEN 1 ELSE 0 END) AS vencidos
              FROM tickets t $JOINEST
              $wsql
             GROUP BY t.seccion_id";
    try {
        $st = $pdo->prepare($sql); $st->execute($params);
        $out = []; $tot = 0;
        foreach ($st as $r) {
            $out[(int)$r['seccion_id']] = [
                'n'         => (int)$r['n'],
                'resueltos' => (int)$r['resueltos'],
                'vencidos'  => (int)$r['vencidos'],
            ];
            $tot += (int)$r['n'];
        }
        return ['ok'=>true, 'counts'=>(object)$out, 'total'=>$tot, 'matches'=>count($out), 'sql_filters'=>array_keys($params)];
    } catch (Throwable $e) {
        return ['ok'=>false, 'error'=>$e->getMessage(), 'counts'=>(object)[], 'total'=>0];
    }
}

function sectionDetail(PDO $pdo, int $id): array {
    global $JOINEST;
    if ($id <= 0) return ['error' => 'id inválido'];

    $sec = $pdo->prepare("SELECT s.*, d.numero AS distrito_numero, d.nombre AS distrito_nombre
                            FROM secciones s LEFT JOIN distritos d ON d.id = s.distrito_id
                           WHERE s.id = ?");
    $sec->execute([$id]);
    $secRow = $sec->fetch(PDO::FETCH_ASSOC);

    $res = $pdo->prepare("SELECT COUNT(*) AS n,
                                 COALESCE(SUM(e.es_resuelto),0) AS resueltos,
                                 SUM(CASE WHEN e.es_resuelto = 0 AND t.fecha_estimada < CURDATE() THEN 1 ELSE 0 END) AS vencidos
                            FROM tickets t $JOINEST
                           WHERE t.seccion_id = :id");
    $res->execute([':id' => $id]);
    $resumen = $res->fetch(PDO::FETCH_ASSOC) ?: ['n'=>0,'resueltos'=>0,'vencidos'=>0];

    $tsv = $pdo->prepare("SELECT ts.nombre, COUNT(*) AS n
                            FROM tickets t LEFT JOIN cat_tipo_servicio ts ON ts.id = t.tipo_servicio_id
                           WHERE t.seccion_id = :id
                           GROUP BY ts.nombre ORDER BY n DESC LIMIT 10");
    $tsv->execute([':id' => $id]);

    $grp = $pdo->prepare("SELECT g.nombre, COUNT(*) AS n
                            FROM tickets t LEFT JOIN cat_grupo g ON g.id = t.grupo_id
                           WHERE t.seccion_id = :id
                           GROUP BY g.nombre ORDER BY n DESC LIMIT 10");
    $grp->execute([':id' => $id]);

    return [
        'seccion'       => $secRow,
        'resumen'       => $resumen,
        'top_servicios' => $tsv->fetchAll(PDO::FETCH_ASSOC),
        'top_grupos'    => $grp->fetchAll(PDO::FETCH_ASSOC),
    ];
}

// ---------------------------------------------------------------------
// AJAX endpoints
// ---------------------------------------------------------------------
if ($action === 'counts') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(countsByFilter($pdo, $_GET), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($action === 'section') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(sectionDetail($pdo, (int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------
// Carga inicial
// ---------------------------------------------------------------------
$ok = true; $missing = [];
foreach (['secciones','secciones_geo','distritos'] as $t) {
    $q = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$t]);
    if (!$q->fetchColumn()) { $ok = false; $missing[] = $t; }
}

$secs = []; $distritos = []; $cat_grupos = []; $cat_deleg = []; $cat_canal = []; $cat_serv = [];
$initialCounts = (object)[]; $spatialError = '';
$rango = ['d_min'=>null,'d_max'=>null];
$def_from = date('Y-m-d', strtotime('-30 days'));   // últimos 30 días por defecto
$def_to   = date('Y-m-d');

if ($ok) {
    $secs = $pdo->query("
        SELECT s.id, s.num_seccion, s.distrito_id, s.municipio, s.col_localidad,
               s.ln_total, d.numero AS distrito_numero, d.nombre AS distrito_nombre, sg.wkt
          FROM secciones s
          JOIN secciones_geo sg ON sg.seccion_id = s.id
          LEFT JOIN distritos d ON d.id = s.distrito_id
         ORDER BY s.distrito_id, s.num_seccion
    ")->fetchAll(PDO::FETCH_ASSOC);

    $distritos  = $pdo->query("SELECT id, numero, nombre FROM distritos ORDER BY numero")->fetchAll(PDO::FETCH_ASSOC);
    $cat_grupos = $pdo->query("SELECT id,nombre FROM cat_grupo ORDER BY nombre")->fetchAll();
    $cat_deleg  = $pdo->query("SELECT id,nombre FROM cat_delegacion ORDER BY nombre")->fetchAll();
    $cat_canal  = $pdo->query("SELECT id,nombre FROM cat_canal_origen ORDER BY nombre")->fetchAll();
    $cat_serv   = $pdo->query("SELECT id,nombre FROM cat_tipo_servicio ORDER BY nombre")->fetchAll();
    $rango      = $pdo->query("SELECT MIN(fecha_creacion) d_min, MAX(fecha_creacion) d_max FROM tickets")->fetch(PDO::FETCH_ASSOC) ?: $rango;

    // Por defecto: últimos 30 días
    $ini = countsByFilter($pdo, ['from' => $def_from, 'to' => $def_to]);
    if (!empty($ini['ok'])) $initialCounts = $ini['counts'];
    else $spatialError = $ini['error'] ?? '';
}
?><?php
$ktTitle  = 'Tickets por sección — Zendesk · Querétaro';
$ktActive = 'zendesk';
$ktFluid = true;
require __DIR__ . '/../../views/layout/kt_top.php';
?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --bg:#f5f7fb; --panel:#fff; --bd:#d9e2f0; --fg:#1f2937; --mut:#5b667a;
     --accent2:#005ab2; --ok:#188a5b; --warn:#d99000; --err:#ce3a2b;
    --shadow:0 2px 6px rgba(37,65,133,.08);
  }
  *{box-sizing:border-box}
  html,body{margin:0;background:var(--bg);color:var(--fg);font-size:14px;line-height:1.5}
  .crumb{padding:12px 24px;font-size:13px;color:var(--mut)}
  .crumb a{color:var(--accent2);text-decoration:none}
  .nav{display:flex;gap:6px;flex-wrap:wrap;padding:0 24px 12px}
  .nav a{font-size:12px;padding:7px 12px;border:1px solid var(--bd);border-radius:7px;color:var(--fg);text-decoration:none;background:#fff;font-weight:500}
  .nav a:hover{background:#eef5fc}
  .nav a.active{background:var(--primary);color:#fff;border-color:var(--primary)}

  .container{padding:6px 24px 18px}
  .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:12px;margin-bottom:16px}
  .kpi{background:var(--panel);border:1px solid var(--bd);border-radius:10px;padding:14px;box-shadow:var(--shadow)}
  .kpi .lbl{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.3px;font-weight:600}
  .kpi .val{font-size:23px;font-weight:700;margin-top:6px;color:var(--primary)}

  .layout{display:grid;grid-template-columns:280px 1fr 320px;gap:14px;height:calc(100vh - 300px);min-height:520px}
  .side{background:var(--panel);border:1px solid var(--bd);border-radius:10px;padding:14px;overflow:auto;box-shadow:var(--shadow)}
  #map{border:1px solid var(--bd);border-radius:10px;overflow:hidden;height:100%;background:#e5e7eb}

  h3.t{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.3px;margin:0 0 8px;padding-bottom:5px;border-bottom:1px solid var(--bd);font-weight:600}
  h3.t:not(:first-child){margin-top:16px}
  .field{display:flex;flex-direction:column;gap:3px;margin-bottom:8px}
  .field label{font-size:11px;color:var(--mut);font-weight:600;text-transform:uppercase;letter-spacing:.3px}
  .field input,.field select{background:#fff;border:1px solid var(--bd);border-radius:6px;padding:7px 10px;font-size:13px;width:100%}
  .field input:focus,.field select:focus{outline:none;border-color:var(--accent2)}
  .btn{background:var(--primary);color:#fff;border:0;border-radius:6px;padding:9px 14px;font-size:13px;font-weight:600;cursor:pointer;width:100%}
  .btn:hover{filter:brightness(1.12)}
  .btn.ghost{background:transparent;color:var(--mut);border:1px solid var(--bd)}
  .btn.ghost:hover{background:#eef5fc}
  .ranking{font-size:12px}
  .ranking .row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px dashed var(--bd);cursor:pointer}
  .ranking .row:last-child{border:0}
  .ranking .row:hover{background:#f7fafe}
  .ranking .row strong{color:var(--primary)}
  .badge{display:inline-block;padding:2px 7px;border-radius:4px;font-size:10.5px;font-weight:600;background:#e8eef9;color:#254185}
  .legend{background:rgba(255,255,255,.96);border-radius:8px;padding:10px;position:absolute;bottom:18px;left:18px;box-shadow:0 4px 12px rgba(0,0,0,.15);font-size:11px;border:1px solid var(--bd);z-index:10}
  .legend h4{margin:0 0 6px;font-size:11px;color:var(--mut);text-transform:uppercase}
  .legend .item{display:flex;align-items:center;gap:6px;margin:2px 0}
  .legend .sw{width:18px;height:10px;border:1px solid #999;border-radius:2px}
  .err-banner{background:var(--err);color:#fff;padding:14px 24px;font-size:13px;border-radius:8px;margin:0 24px}
  .small{font-size:11.5px;color:var(--mut)}
  @media (max-width:1100px){ .layout{grid-template-columns:1fr;height:auto} #map{height:clamp(520px,calc(100vh - 250px),880px)} }
</style>

<div class="crumb"><a href="dashboard.php">Dashboard</a> &rarr; Tickets por sección</div>
<?php if (!$ok): ?>
  <div class="err-banner">
    ⚠️ Faltan tablas IEEQ en <strong><?= htmlspecialchars($db_name) ?></strong>: <?= implode(', ', $missing) ?>.
    Importa el dump de geometría (las mismas tablas que usa el reporte electoral del DIF).
  </div>
<?php else: ?>

<div class="container">
  <div class="kpis" id="kpis"></div>
  <?php if ($spatialError): ?><div class="err-banner">Error en el cruce espacial: <?= htmlspecialchars($spatialError) ?></div><?php endif; ?>

  <div class="layout">
    <!-- Filtros -->
    <div class="side">
      <h3 class="t">Filtros</h3>
      <div class="field"><label>Distrito</label>
        <select id="f-distrito" onchange="redraw()">
          <option value="">— todos —</option>
          <?php foreach ($distritos as $d): ?>
            <option value="<?= $d['id'] ?>">D<?= $d['numero'] ?> — <?= htmlspecialchars($d['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Grupo</label>
        <select id="f-grupo"><option value="">— todos —</option>
          <?php foreach ($cat_grupos as $g): ?><option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Delegación</label>
        <select id="f-deleg"><option value="">— todas —</option>
          <?php foreach ($cat_deleg as $d): ?><option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Canal origen</label>
        <select id="f-canal"><option value="">— todos —</option>
          <?php foreach ($cat_canal as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Tipo de servicio</label>
        <select id="f-serv"><option value="">— todos —</option>
          <?php foreach ($cat_serv as $s): ?><option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Estado</label>
        <select id="f-estado">
          <option value="">— todos —</option>
          <option value="resuelto">Resueltos</option>
          <option value="sin_resolver">Sin resolver</option>
          <option value="abierto">Abierto</option>
          <option value="vencido">Vencidos</option>
        </select>
      </div>
      <div class="field"><label>Creado desde</label><input type="date" id="f-from" value="<?= htmlspecialchars($def_from) ?>"></div>
      <div class="field"><label>Creado hasta</label><input type="date" id="f-to" value="<?= htmlspecialchars($def_to) ?>"></div>
      <div class="field"><label>Formulario</label><?= zd_form_select('id="f-form"') ?></div>
      <button class="btn" onclick="aplicar()">🔎 Aplicar</button>
      <button class="btn ghost" onclick="limpiar()" style="margin-top:6px">🧹 Limpiar</button>
      <div id="filter-status" style="margin-top:8px;font-size:11.5px;color:var(--mut);min-height:16px"></div>

      <h3 class="t">Mapa</h3>
      <div class="field"><label>Modo color</label>
        <select id="f-color" onchange="redraw()">
          <option value="total">Total de tickets</option>
          <option value="resueltos">% resueltos</option>
          <option value="vencidos">% vencidos</option>
          <option value="distrito">Por distrito</option>
        </select>
      </div>
      <div class="field"><label><input type="checkbox" id="ly-bord" checked onchange="redraw()"> Mostrar bordes</label></div>
    </div>

    <!-- Mapa -->
    <div style="position:relative">
      <div id="map"></div>
      <div class="legend" id="legend"></div>
    </div>

    <!-- Rankings + detalle -->
    <div class="side">
      <h3 class="t">Top 10 distritos por tickets</h3>
      <div class="ranking" id="rank-distrito"></div>
      <h3 class="t">Top 15 secciones por tickets</h3>
      <div class="ranking" id="rank-secciones"></div>
      <h3 class="t">Detalle de sección</h3>
      <div id="detalle"><div class="small">Da click en una sección del mapa para ver detalles.</div></div>
    </div>
  </div>
</div>

<script>
const SECCIONES      = <?= json_encode($secs, JSON_UNESCAPED_UNICODE) ?>;
const DISTRITOS      = <?= json_encode($distritos, JSON_UNESCAPED_UNICODE) ?>;
const GMAPS_KEY      = <?= json_encode($gmapsKey) ?>;
let   CURRENT_COUNTS = <?= json_encode($initialCounts) ?>;
let   CURRENT_MAX    = 0;
const DISTRITO_COLORS = ['#254185','#005ab2','#188a5b','#d99000','#2a9eda',
                         '#ce3a2b','#1a2f63','#5b667a','#ca8a04','#475569',
                         '#7c3aed','#db2777','#0369a1','#15803d','#a16207'];

// Loader dinámico (async) de Google Maps
(g=>{var h,a,k,p="The Google Maps JavaScript API",c="google",l="importLibrary",q="__ib__",m=document,b=window;b=b[c]||(b[c]={});var d=b.maps||(b.maps={}),r=new Set,e=new URLSearchParams,u=()=>h||(h=new Promise(async(f,n)=>{await (a=m.createElement("script"));e.set("libraries",[...r]+"");for(k in g)e.set(k.replace(/[A-Z]/g,t=>"_"+t[0].toLowerCase()),g[k]);e.set("callback",c+".maps."+q);a.src=`https://maps.${c}apis.com/maps/api/js?`+e;d[q]=f;a.onerror=()=>h=n(Error(p+" could not load."));a.nonce=m.querySelector("script[nonce]")?.nonce||"";m.head.append(a)}));d[l]?console.warn(p+" only loads once. Ignoring:",g):d[l]=(f,...n)=>r.add(f)&&u().then(()=>d[l](f,...n))})({key:GMAPS_KEY,v:"weekly",region:"MX",language:"es"});

let map, polygons = [], LatLngBoundsCls, infoWin;

function parseWkt(wkt){
  const m = (wkt||'').match(/POLYGON\s*\(\s*\(([^)]+)\)\s*\)/i);
  if (!m) return [];
  return m[1].split(',').map(pt => { const [a,b] = pt.trim().split(/\s+/).map(Number); return { lat:a, lng:b }; });
}

// ---- Escalas de color ----
function scaleCount(v,max){ if(max<=0)return '#dbe4f3'; const r=v/max;
  if(r<=0.05)return '#e7eef9'; if(r<=0.15)return '#c3d6ef'; if(r<=0.30)return '#8fb3e0';
  if(r<=0.50)return '#2a9eda'; if(r<=0.75)return '#005ab2'; return '#254185'; }
function scaleGood(p){ if(p>=85)return '#188a5b'; if(p>=70)return '#5cae84'; if(p>=50)return '#d7d98a';
  if(p>=30)return '#e2a857'; return '#ce3a2b'; }
function scaleBad(p){ if(p>=50)return '#8a1f16'; if(p>=30)return '#ce3a2b'; if(p>=15)return '#e08a4f';
  if(p>=5)return '#e7c768'; return '#188a5b'; }
function cnt(id){ return CURRENT_COUNTS[id] || {n:0,resueltos:0,vencidos:0}; }
function fillFor(s,mode){
  const c = cnt(s.id);
  if (mode==='distrito') return DISTRITO_COLORS[((s.distrito_numero||1)-1) % DISTRITO_COLORS.length];
  if (c.n===0) return '#eef1f6';
  if (mode==='resueltos') return scaleGood(c.resueltos / c.n * 100);
  if (mode==='vencidos')  return scaleBad(c.vencidos / c.n * 100);
  return scaleCount(c.n, CURRENT_MAX);
}

async function init(){
  const mapsLib = await google.maps.importLibrary("maps");
  const core    = await google.maps.importLibrary("core");
  LatLngBoundsCls = core.LatLngBounds;
  map = new mapsLib.Map(document.getElementById('map'), {
    center:{ lat:20.5888, lng:-100.3899 }, zoom:11, mapTypeId:'roadmap',
    streetViewControl:false, fullscreenControl:true,
  });
  infoWin = new mapsLib.InfoWindow();
  recomputeMax();
  drawPolygons(); fitToData(); renderKPIs(); renderRankings(); renderLegend();
}
init().catch(e => {
  document.getElementById('map').innerHTML =
    '<div style="padding:20px;color:#ce3a2b">No se pudo cargar Google Maps. Habilita Maps JavaScript API en Cloud Console.</div>';
  console.error(e);
});

function recomputeMax(){
  let mx = 0;
  for (const s of SECCIONES){ const n = cnt(s.id).n; if (n > mx) mx = n; }
  CURRENT_MAX = mx;
}

function drawPolygons(){
  polygons.forEach(p => p.setMap(null)); polygons = [];
  const mode = document.getElementById('f-color').value;
  const showBord = document.getElementById('ly-bord').checked;
  const selDist = document.getElementById('f-distrito').value;
  for (const s of SECCIONES){
    const path = parseWkt(s.wkt); if (!path.length) continue;
    const enDistrito = !selDist || String(s.distrito_id) === String(selDist);
    const fill = enDistrito ? fillFor(s, mode) : '#e5e9f0';
    const baseFill   = enDistrito ? 0.62 : 0.10;
    const baseStroke = enDistrito ? (showBord ? 0.4 : 0) : 0.10;
    const poly = new google.maps.Polygon({
      paths: path, strokeColor: showBord ? '#1e293b' : 'transparent',
      strokeOpacity: baseStroke, strokeWeight: 0.8, fillColor: fill, fillOpacity: baseFill,
    });
    poly.setMap(map); poly.zk = s;
    if (enDistrito){
      poly.addListener('click', ev => onSectionClick(s, ev.latLng));
      poly.addListener('mouseover', () => poly.setOptions({ fillOpacity:0.85, strokeOpacity:0.8, strokeWeight:1.5 }));
      poly.addListener('mouseout',  () => poly.setOptions({ fillOpacity:baseFill, strokeOpacity:baseStroke, strokeWeight:0.8 }));
    }
    polygons.push(poly);
  }
}
function redraw(){ drawPolygons(); renderLegend(); }
function fitToData(){ if(!polygons.length)return; const b=new LatLngBoundsCls(); polygons.forEach(p=>p.getPath().forEach(pt=>b.extend(pt))); map.fitBounds(b); }

async function onSectionClick(s, latLng){
  infoWin.setContent(`<div style="padding:6px"><b>Sección ${s.num_seccion}</b><br>Cargando…</div>`);
  infoWin.setPosition(latLng); infoWin.open(map);
  const r = await fetch('?' + new URLSearchParams({ action:'section', id:s.id })).then(r=>r.json());
  const rs = r.resumen || {n:0,resueltos:0,vencidos:0};
  let html = `<div style="font-size:12px;max-width:280px">
    <div><b>Sección ${s.num_seccion}</b> · D${s.distrito_numero||'?'}</div>
    <div class="small">${escapeHtml(s.municipio||'')}</div>
    <div style="margin:6px 0">
      <span class="badge">Tickets: ${(+rs.n||0).toLocaleString()}</span>
      <span class="badge" style="background:#dcfce7;color:#166534">Resueltos: ${(+rs.resueltos||0)}</span>
      <span class="badge" style="background:#fee2e2;color:#991b1b">Vencidos: ${(+rs.vencidos||0)}</span>
    </div></div>`;
  infoWin.setContent(html);
  renderDetalle(s, r);
}
function renderDetalle(s, r){
  const rs = r.resumen || {n:0,resueltos:0,vencidos:0};
  let h = `<div><strong>Sección ${s.num_seccion}</strong> · D${s.distrito_numero||'?'}</div>
    <div class="small">${escapeHtml(s.municipio||'')}</div>
    <div style="margin:8px 0">
      <span class="badge">Tickets ${(+rs.n||0).toLocaleString()}</span>
      <span class="badge" style="background:#dcfce7;color:#166534">Resueltos ${(+rs.resueltos||0)}</span>
      <span class="badge" style="background:#fee2e2;color:#991b1b">Vencidos ${(+rs.vencidos||0)}</span>
    </div>`;
  if (r.top_servicios?.length){
    h += '<h3 class="t" style="margin-top:8px">Tipos de servicio</h3><div class="ranking">';
    r.top_servicios.forEach(p => h += `<div class="row"><span>${escapeHtml(p.nombre||'—')}</span><strong>${p.n}</strong></div>`);
    h += '</div>';
  }
  if (r.top_grupos?.length){
    h += '<h3 class="t" style="margin-top:8px">Grupos</h3><div class="ranking">';
    r.top_grupos.forEach(p => h += `<div class="row"><span>${escapeHtml(p.nombre||'—')}</span><strong>${p.n}</strong></div>`);
    h += '</div>';
  }
  document.getElementById('detalle').innerHTML = h;
}

function renderKPIs(){
  let total=0, res=0, ven=0, con=0, max=0, maxS=null; const byD={};
  for (const s of SECCIONES){
    const c = cnt(s.id); total += c.n; res += c.resueltos; ven += c.vencidos;
    if (c.n>0) con++; if (c.n>max){ max=c.n; maxS=s; }
    byD[s.distrito_id] = (byD[s.distrito_id]||0) + c.n;
  }
  let topD=null, topN=0;
  for (const [did,n] of Object.entries(byD)){ if (n>topN){ topN=n; topD=DISTRITOS.find(d=>+d.id===+did); } }
  const cards = [
    ['Tickets georef.', total.toLocaleString()],
    ['Secciones con tickets', con + ' / ' + SECCIONES.length],
    ['% resueltos', total ? (res/total*100).toFixed(1)+'%' : '—'],
    ['% vencidos', total ? (ven/total*100).toFixed(1)+'%' : '—'],
    ['Sección más reportada', maxS ? `S${maxS.num_seccion} (${max})` : '—'],
    ['Distrito más reportado', topD ? `D${topD.numero} (${topN.toLocaleString()})` : '—'],
  ];
  document.getElementById('kpis').innerHTML = cards.map(([l,v]) =>
    `<div class="kpi"><div class="lbl">${l}</div><div class="val">${v}</div></div>`).join('');
}

function renderRankings(){
  const byD = {};
  for (const s of SECCIONES){
    const did = s.distrito_id;
    if (!byD[did]) byD[did] = { distrito:s, n:0 };
    byD[did].n += cnt(s.id).n;
  }
  const top = Object.values(byD).sort((a,b)=>b.n-a.n).slice(0,10);
  document.getElementById('rank-distrito').innerHTML = top.map(x => {
    const d = DISTRITOS.find(d=>+d.id===+x.distrito.distrito_id);
    return `<div class="row" onclick="zoomDistrito(${x.distrito.distrito_id})">
      <span>D${d?d.numero:'?'} — ${escapeHtml((d?d.nombre:'').replace('Distrito Local ',''))}</span>
      <strong>${x.n.toLocaleString()}</strong></div>`;
  }).join('') || '<div class="small">Sin datos</div>';

  const topS = SECCIONES.map(s => ({ ...s, n: cnt(s.id).n })).sort((a,b)=>b.n-a.n).slice(0,15);
  document.getElementById('rank-secciones').innerHTML = topS.map(s =>
    `<div class="row" onclick="zoomSeccion(${s.id})">
      <span>S${s.num_seccion} <span class="badge">D${s.distrito_numero||'?'}</span></span>
      <strong>${s.n.toLocaleString()}</strong></div>`).join('');
}

function zoomDistrito(did){
  const b = new LatLngBoundsCls(); let any=false;
  for (const p of polygons){ if (p.zk.distrito_id===did){ p.getPath().forEach(pt=>b.extend(pt)); any=true; } }
  if (any) map.fitBounds(b);
}
function zoomSeccion(sid){
  const p = polygons.find(p=>p.zk.id===sid); if(!p)return;
  const b = new LatLngBoundsCls(); p.getPath().forEach(pt=>b.extend(pt)); map.fitBounds(b);
  setTimeout(()=>onSectionClick(p.zk, p.getPath().getAt(0)), 300);
}

function renderLegend(){
  const mode = document.getElementById('f-color').value;
  let html='';
  if (mode==='distrito'){
    html = '<h4>Distrito</h4>';
    DISTRITOS.slice(0,15).forEach((d,i)=> html += `<div class="item"><span class="sw" style="background:${DISTRITO_COLORS[i%DISTRITO_COLORS.length]}"></span>D${d.numero}</div>`);
  } else if (mode==='resueltos'){
    html = '<h4>% resueltos</h4>'
      + sw('#ce3a2b','&lt;30%') + sw('#e2a857','30–50%') + sw('#d7d98a','50–70%') + sw('#5cae84','70–85%') + sw('#188a5b','≥85%');
  } else if (mode==='vencidos'){
    html = '<h4>% vencidos</h4>'
      + sw('#188a5b','&lt;5%') + sw('#e7c768','5–15%') + sw('#e08a4f','15–30%') + sw('#ce3a2b','30–50%') + sw('#8a1f16','≥50%');
  } else {
    html = '<h4>Total de tickets</h4>'
      + sw('#e7eef9','muy bajo') + sw('#8fb3e0','bajo') + sw('#2a9eda','medio') + sw('#005ab2','alto') + sw('#254185','muy alto');
  }
  document.getElementById('legend').innerHTML = html;
}
function sw(color,label){ return `<div class="item"><span class="sw" style="background:${color}"></span>${label}</div>`; }

function readFilters(){
  return {
    distrito:      document.getElementById('f-distrito').value,
    grupo:         document.getElementById('f-grupo').value,
    delegacion:    document.getElementById('f-deleg').value,
    canal:         document.getElementById('f-canal').value,
    tipo_servicio: document.getElementById('f-serv').value,
    estado:        document.getElementById('f-estado').value,
    from:          document.getElementById('f-from').value,
    to:            document.getElementById('f-to').value,
    form:          document.getElementById('f-form').value,
  };
}
async function aplicar(){
  const status = document.getElementById('filter-status');
  status.textContent = 'Consultando cruce espacial…'; status.style.color = 'var(--mut)';
  const f = readFilters();
  const qsObj = { action:'counts' };
  for (const k of Object.keys(f)) if (f[k] !== '' && f[k] != null) qsObj[k] = f[k];
  try {
    const r = await fetch('?' + new URLSearchParams(qsObj)).then(r=>r.json());
    if (r.ok === false) throw new Error(r.error || 'Error del backend');
    CURRENT_COUNTS = r.counts || {};
    recomputeMax();
    drawPolygons(); renderKPIs(); renderRankings(); renderLegend();
    if (f.distrito) zoomDistrito(+f.distrito);
    status.textContent = `✓ ${(r.total||0).toLocaleString()} tickets en ${r.matches||0} secciones`;
    status.style.color = (r.total>0) ? 'var(--ok)' : 'var(--warn)';
  } catch (e) {
    status.textContent = '✗ ' + e.message; status.style.color = 'var(--err)';
    console.error(e);
  }
}
function limpiar(){
  ['f-distrito','f-grupo','f-deleg','f-canal','f-serv','f-estado','f-from','f-to'].forEach(id => document.getElementById(id).value='');
  aplicar();
}
function escapeHtml(s){ return String(s||'').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
