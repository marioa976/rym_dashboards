<?php
/**
 * Mapa interactivo con Google Maps.
 * Muestra los tickets georreferenciados con filtros, clustering y heatmap.
 */
require __DIR__ . '/db.php';
$pdo = db();
$cfg = require __DIR__ . '/config.php';
$api_key = trim($cfg['google_maps_api_key'] ?? '');
$map_id  = trim($cfg['map_id'] ?? 'DEMO_MAP_ID');

// ============================================================
//  Catálogos para los filtros
// ============================================================
$cat_grupos       = $pdo->query("SELECT id,nombre FROM cat_grupo ORDER BY nombre")->fetchAll();
$cat_delegaciones = $pdo->query("SELECT id,nombre FROM cat_delegacion ORDER BY nombre")->fetchAll();
$cat_canales      = $pdo->query("SELECT id,nombre FROM cat_canal_origen ORDER BY nombre")->fetchAll();
$cat_servicios    = $pdo->query("SELECT id,nombre FROM cat_tipo_servicio ORDER BY nombre")->fetchAll();
$rango = $pdo->query("SELECT MIN(fecha_creacion) AS d_min, MAX(fecha_creacion) AS d_max FROM tickets")->fetch();

// ============================================================
//  Lectura de filtros
// ============================================================
$f_grupo      = isset($_GET['grupo'])      && $_GET['grupo']!=='' ? (int)$_GET['grupo']      : null;
$f_delegacion = isset($_GET['delegacion']) && $_GET['delegacion']!=='' ? (int)$_GET['delegacion'] : null;
$f_canal      = isset($_GET['canal'])      && $_GET['canal']!=='' ? (int)$_GET['canal']      : null;
$f_servicio   = isset($_GET['tipo_servicio']) && $_GET['tipo_servicio']!=='' ? (int)$_GET['tipo_servicio'] : null;
$f_estado     = $_GET['estado']    ?? '';
$f_from       = $_GET['from']      ?? date('Y-m-d', strtotime('-30 days'));   // últimos 30 días por defecto
$f_to         = $_GET['to']        ?? date('Y-m-d');
$f_limite     = (int)($_GET['limite'] ?? 2000);
if ($f_limite < 100) $f_limite = 100;
if ($f_limite > 10000) $f_limite = 10000;

// ============================================================
//  ¿Existe la columna latitud? (la migración pudo no haberse corrido)
// ============================================================
$tiene_geo = (int)$pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME='tickets' AND COLUMN_NAME='latitud'
")->fetchColumn() > 0;

// ============================================================
//  WHERE dinámico
//  $W_filtros = filtros del usuario (para stats)
//  $W         = filtros + restricción geo (para markers)
// ============================================================
$where_user = ['1=1']; $params = [];
if ($f_grupo)      { $where_user[] = "t.grupo_id = ?";         $params[] = $f_grupo; }
if ($f_delegacion) { $where_user[] = "t.delegacion_id = ?";    $params[] = $f_delegacion; }
if ($f_canal)      { $where_user[] = "t.canal_origen_id = ?";  $params[] = $f_canal; }
if ($f_servicio)   { $where_user[] = "t.tipo_servicio_id = ?"; $params[] = $f_servicio; }
if ($f_from)       { $where_user[] = "t.fecha_creacion >= ?"; $params[] = $f_from; }
if ($f_to)         { $where_user[] = "t.fecha_creacion <= ?"; $params[] = $f_to; }
if ($f_estado === 'resuelto')    { $where_user[] = "e.es_resuelto = 1"; }
if ($f_estado === 'sin_resolver'){ $where_user[] = "e.es_resuelto = 0"; }
if ($f_estado === 'abierto')     { $where_user[] = "e.nombre = 'Abierto'"; }
if ($f_estado === 'vencido')     { $where_user[] = "e.es_resuelto = 0 AND t.fecha_estimada < CURDATE()"; }
$W_filtros = implode(' AND ', $where_user);

// Para markers, añadir restricción geo
$where_markers = $where_user;
if ($tiene_geo) $where_markers[] = "t.latitud IS NOT NULL AND t.longitud IS NOT NULL";
$W = implode(' AND ', $where_markers);

$FROM = "FROM tickets t
         LEFT JOIN cat_estado        e  ON e.id  = t.estado_id
         LEFT JOIN cat_grupo         g  ON g.id  = t.grupo_id
         LEFT JOIN cat_delegacion    d  ON d.id  = t.delegacion_id
         LEFT JOIN cat_canal_origen  co ON co.id = t.canal_origen_id
         LEFT JOIN cat_tipo_servicio ts ON ts.id = t.tipo_servicio_id";

function q(PDO $p, string $sql, array $params=[]): array {
    $s = $p->prepare($sql); $s->execute($params); return $s->fetchAll();
}

// ============================================================
//  Tickets para el mapa
// ============================================================
$markers = [];
$stats = ['total'=>0, 'con_geo'=>0, 'sin_geo'=>0];
if ($tiene_geo) {
    // Stats RESPETANDO filtros (pero sin restricción geo)
    $st = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN t.latitud IS NOT NULL AND t.longitud IS NOT NULL THEN 1 ELSE 0 END) AS con_geo
        $FROM
        WHERE $W_filtros
    ");
    $st->execute($params);
    $row = $st->fetch();
    $stats['total']   = (int)$row['total'];
    $stats['con_geo'] = (int)$row['con_geo'];
    $stats['sin_geo'] = $stats['total'] - $stats['con_geo'];

    $markers = q($pdo, "
        SELECT t.ticket_id   AS id,
               t.latitud     AS lat,
               t.longitud    AS lng,
               t.fecha_creacion AS fecha,
               e.nombre      AS estado,
               e.es_resuelto AS resuelto,
               g.nombre      AS grupo,
               ts.nombre     AS servicio,
               d.nombre      AS delegacion,
               co.nombre     AS canal,
               t.colonia,
               t.direccion,
               CASE WHEN e.es_resuelto = 0 AND t.fecha_estimada < CURDATE() THEN 1 ELSE 0 END AS vencido
        $FROM
        WHERE $W
        ORDER BY t.fecha_creacion DESC
        LIMIT $f_limite
    ", $params);
}

// Filtros con label legible
$filtro_label = [];
if ($f_grupo) {
    $n = $pdo->prepare("SELECT nombre FROM cat_grupo WHERE id=?"); $n->execute([$f_grupo]);
    if ($r = $n->fetch()) $filtro_label[] = "Grupo: <b>{$r['nombre']}</b>";
}
if ($f_delegacion) {
    $n = $pdo->prepare("SELECT nombre FROM cat_delegacion WHERE id=?"); $n->execute([$f_delegacion]);
    if ($r = $n->fetch()) $filtro_label[] = "Delegación: <b>{$r['nombre']}</b>";
}
if ($f_canal) {
    $n = $pdo->prepare("SELECT nombre FROM cat_canal_origen WHERE id=?"); $n->execute([$f_canal]);
    if ($r = $n->fetch()) $filtro_label[] = "Canal: <b>{$r['nombre']}</b>";
}
if ($f_servicio) {
    $n = $pdo->prepare("SELECT nombre FROM cat_tipo_servicio WHERE id=?"); $n->execute([$f_servicio]);
    if ($r = $n->fetch()) $filtro_label[] = "Servicio: <b>{$r['nombre']}</b>";
}
if ($f_estado) {
    $labels = ['resuelto'=>'Resueltos','sin_resolver'=>'Sin resolver','abierto'=>'Abiertos','vencido'=>'Vencidos'];
    $filtro_label[] = "Estado: <b>".($labels[$f_estado]??$f_estado)."</b>";
}

$pintados = count($markers);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Mapa de reportes · Querétaro</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{--bg:#fafafa;--surface:#fff;--border:#ececec;--text:#1a1a1a;--text-muted:#6b7280;--text-faint:#9ca3af;
    --accent:#254185;--positive:#188a5b;--warning:#d99000;--negative:#ce3a2b;--neutral:#005ab2}
  *{box-sizing:border-box;-webkit-font-smoothing:antialiased}
  html,body{margin:0;height:100%;font-family:'Inter',system-ui,sans-serif;color:var(--text);font-size:14px;background:var(--bg)}
  .topbar{padding:14px 24px;background:#fff;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
  .topbar h1{font-size:17px;font-weight:600;margin:0;letter-spacing:-.01em}
  .topbar .crumb{color:var(--text-muted);font-size:12px;margin-top:2px}
  .topbar .crumb a{color:var(--accent);text-decoration:none}
  .nav{display:flex;gap:8px}
  .nav a{font-size:12px;padding:7px 12px;border:1px solid var(--border);border-radius:7px;color:var(--text);text-decoration:none;background:#fff;font-weight:500}
  .nav a.active{background:var(--text);color:#fff;border-color:var(--text)}

  .layout{display:grid;grid-template-columns:300px 1fr;height:calc(100vh - 60px)}
  @media(max-width:900px){.layout{grid-template-columns:1fr;height:auto} .panel{height:auto;border-right:0;border-bottom:1px solid var(--border)} #map{height:60vh}}

  .panel{background:#fff;border-right:1px solid var(--border);overflow:auto;padding:18px}
  .panel h3{font-size:11px;text-transform:uppercase;letter-spacing:.08em;font-weight:600;color:var(--text-faint);margin:0 0 12px}
  .panel label{display:block;font-size:11px;color:var(--text-muted);font-weight:500;margin:10px 0 4px}
  .panel select,.panel input{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font:inherit;font-size:13px;background:#fff;color:var(--text)}
  .panel .btn-row{display:flex;gap:8px;margin-top:14px}
  .panel button,.panel a.btn{flex:1;padding:9px 12px;border:1px solid var(--border);border-radius:6px;font:inherit;font-size:13px;font-weight:500;cursor:pointer;text-decoration:none;text-align:center;color:var(--text);background:#fff}
  .panel button.primary{background:var(--accent);color:#fff;border-color:var(--accent)}
  .panel button.primary:hover{filter:brightness(1.05)}
  .panel .chip{display:inline-block;background:#eff6ff;color:#1d4ed8;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:500;margin:2px 4px 0 0}

  .stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px}
  .stat{background:#f9fafb;border:1px solid var(--border);border-radius:8px;padding:10px}
  .stat .l{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;font-weight:600}
  .stat .v{font-size:18px;font-weight:600;line-height:1.1}

  .legend{margin-top:18px;padding-top:14px;border-top:1px solid var(--border)}
  .legend-item{display:flex;align-items:center;gap:8px;font-size:12px;margin-bottom:6px}
  .legend-dot{width:14px;height:14px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.2)}

  .view-toggle{display:flex;background:#f3f4f6;border-radius:8px;padding:3px;margin-bottom:14px}
  .view-toggle button{flex:1;border:0;padding:7px;font:inherit;font-size:12px;font-weight:500;cursor:pointer;border-radius:6px;background:transparent;color:var(--text-muted)}
  .view-toggle button.active{background:#fff;color:var(--text);box-shadow:0 1px 3px rgba(0,0,0,.08)}

  #map{width:100%;height:100%}
  .map-warning{padding:40px;text-align:center;color:var(--text-muted)}
  .map-warning h2{color:var(--text);font-size:18px;margin:0 0 12px}
  .map-warning code{background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:12px}

  .infobox{font-family:Inter,system-ui;font-size:13px;max-width:300px;line-height:1.45}
  .infobox h4{margin:0 0 6px;font-size:14px;font-weight:600}
  .infobox .row{display:flex;gap:6px;margin-top:4px}
  .infobox .row b{font-weight:500;color:#6b7280;min-width:80px;font-size:12px}
  .pill{display:inline-block;font-size:10px;padding:2px 7px;border-radius:999px;font-weight:500}
  .pill.positive{background:#ecfdf5;color:#047857}
  .pill.warning{background:#fffbeb;color:#b45309}
  .pill.negative{background:#fef2f2;color:#b91c1c}
  .pill.neutral{background:#f3f4f6;color:#374151}
</style>
</head>
<body>
<?php $portalModulo='Zendesk'; @include __DIR__.'/../_portalbar.php'; ?>

<div class="topbar">
  <div>
    <h1>Mapa de reportes</h1>
    <div class="crumb"><a href="dashboard.php">Dashboard</a> → Mapa · <?= number_format($pintados) ?> tickets visibles</div>
  </div>
  <?php include __DIR__ . '/_navzendesk.php'; ?>
</div>

<div class="layout">
  <!-- ============= PANEL LATERAL ============= -->
  <aside class="panel">

    <div class="stat-grid">
      <div class="stat"><div class="l">Con geo</div><div class="v"><?= number_format($stats['con_geo']) ?></div></div>
      <div class="stat"><div class="l">Sin geo</div><div class="v"><?= number_format($stats['sin_geo']) ?></div></div>
    </div>

    <h3>Filtros</h3>
    <form method="get" id="filtros">
      <label>Grupo</label>
      <select name="grupo">
        <option value="">— Todos —</option>
        <?php foreach ($cat_grupos as $g): ?>
          <option value="<?= $g['id'] ?>" <?= $f_grupo==$g['id']?'selected':'' ?>><?= htmlspecialchars($g['nombre']) ?></option>
        <?php endforeach; ?>
      </select>

      <label>Tipo de servicio</label>
      <select name="tipo_servicio">
        <option value="">— Todos —</option>
        <?php foreach ($cat_servicios as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $f_servicio==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['nombre']) ?></option>
        <?php endforeach; ?>
      </select>

      <label>Delegación</label>
      <select name="delegacion">
        <option value="">— Todas —</option>
        <?php foreach ($cat_delegaciones as $d): ?>
          <option value="<?= $d['id'] ?>" <?= $f_delegacion==$d['id']?'selected':'' ?>><?= htmlspecialchars($d['nombre']) ?></option>
        <?php endforeach; ?>
      </select>

      <label>Canal</label>
      <select name="canal">
        <option value="">— Todos —</option>
        <?php foreach ($cat_canales as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $f_canal==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['nombre']) ?></option>
        <?php endforeach; ?>
      </select>

      <label>Estado</label>
      <select name="estado">
        <option value="">— Todos —</option>
        <option value="resuelto"     <?= $f_estado==='resuelto'?'selected':'' ?>>Resueltos</option>
        <option value="sin_resolver" <?= $f_estado==='sin_resolver'?'selected':'' ?>>Sin resolver</option>
        <option value="abierto"      <?= $f_estado==='abierto'?'selected':'' ?>>Abiertos</option>
        <option value="vencido"      <?= $f_estado==='vencido'?'selected':'' ?>>Vencidos</option>
      </select>

      <label>Desde</label>
      <input type="date" name="from" value="<?= htmlspecialchars($f_from) ?>">
      <label>Hasta</label>
      <input type="date" name="to" value="<?= htmlspecialchars($f_to) ?>">

      <label>Límite de marcadores (max 10,000)</label>
      <input type="number" name="limite" min="100" max="10000" step="100" value="<?= $f_limite ?>">

      <div class="btn-row">
        <a class="btn" href="mapa.php">Limpiar</a>
        <button type="submit" class="primary">Aplicar</button>
      </div>
    </form>

    <?php if (!empty($filtro_label)): ?>
      <div style="margin-top:14px;font-size:12px;color:var(--text-muted)">Activos:<br>
      <?php foreach ($filtro_label as $l): ?><span class="chip"><?= $l ?></span><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <h3 style="margin-top:24px">Vista</h3>
    <div class="view-toggle">
      <button type="button" id="btnMarkers" class="active">Marcadores</button>
      <button type="button" id="btnHeatmap">Heatmap</button>
    </div>

    <div class="legend">
      <div class="legend-item"><span class="legend-dot" style="background:#ce3a2b"></span>Vencido (sin resolver)</div>
      <div class="legend-item"><span class="legend-dot" style="background:#d99000"></span>Sin resolver</div>
      <div class="legend-item"><span class="legend-dot" style="background:#188a5b"></span>Resuelto</div>
      <div class="legend-item"><span class="legend-dot" style="background:#6b7280"></span>Otro</div>
    </div>

    <div style="margin-top:18px;font-size:11px;color:var(--text-faint);line-height:1.5">
      <b>Tip:</b> los marcadores se agrupan en clusters al alejarte. Click en un cluster te acerca; click en un marcador muestra el detalle.
    </div>
  </aside>

  <!-- ============= MAPA ============= -->
  <main id="map-container">
    <?php if (!$tiene_geo): ?>
      <div class="map-warning">
        <h2>⚠️ La tabla aún no tiene columnas geográficas</h2>
        <p>Corre primero la migración para habilitar el mapa:<br>
        <code>migration_coordenadas.sql</code></p>
        <p>Luego importa el CSV nuevo para llenar latitud y longitud.</p>
      </div>
    <?php elseif (empty($api_key)): ?>
      <div class="map-warning">
        <h2>🔑 Falta la API Key de Google Maps</h2>
        <p>Pégala en <code>config.php</code> en el campo <code>google_maps_api_key</code> y recarga esta página.</p>
        <p style="margin-top:24px;color:var(--text-faint);font-size:12px">
          Activa "Maps JavaScript API" en tu proyecto de Google Cloud y crea una key.
          Si quieres restringir la key, agrega <code>http://localhost:8888/*</code> a los referrers.
        </p>
      </div>
    <?php elseif (empty($markers)): ?>
      <div class="map-warning">
        <h2>Sin tickets con geolocalización en este filtro</h2>
        <p>Ajusta los filtros del panel izquierdo o limpia para ver todo.</p>
      </div>
    <?php else: ?>
      <div id="map"></div>
    <?php endif; ?>
  </main>
</div>

<?php if ($tiene_geo && !empty($api_key) && !empty($markers)): ?>
<script>
const MARKERS  = <?= json_encode($markers, JSON_UNESCAPED_UNICODE) ?>;
const CENTRO   = { lat: <?= $cfg['mapa_centro_lat'] ?>, lng: <?= $cfg['mapa_centro_lng'] ?> };
const ZOOM     = <?= $cfg['mapa_zoom'] ?>;
const MAP_ID   = <?= json_encode($map_id) ?>;

let map, infoWindow, markerCluster;
let mkObjs = [];
let heatOverlay = null;
let modoActual = 'markers';

// Renderer de clusters con marcador avanzado (sin google.maps.Marker legacy)
const clusterRendererQRO = {
  render: ({ count, position }) => {
    const size = 36 + Math.min(24, Math.log2(count + 1) * 6);
    const el = document.createElement('div');
    el.style.cssText =
      'display:flex;align-items:center;justify-content:center;border-radius:50%;' +
      'background:#254185;color:#fff;font:600 13px Montserrat,Arial,sans-serif;' +
      'border:2px solid #fff;box-shadow:0 2px 6px rgba(37,65,133,.35);' +
      'width:' + size + 'px;height:' + size + 'px';
    el.textContent = count;
    return new google.maps.marker.AdvancedMarkerElement({ position, content: el, zIndex: 1000 + count });
  }
};

// ===== HeatOverlay: heatmap basado en canvas (reemplazo del API deprecado) =====
function makeHeatOverlay(points, opts = {}) {
  class HeatOverlay extends google.maps.OverlayView {
    constructor() {
      super();
      this.points  = points;
      this.radius  = opts.radius  || 28;
      this.opacity = opts.opacity || 0.75;
      this.gradient = opts.gradient || [
        [0.00, [0,   0, 255, 0]],
        [0.25, [0, 200, 255, 180]],
        [0.50, [0, 255,  80, 220]],
        [0.75, [255,230,  0, 240]],
        [1.00, [255, 40,  0, 255]],
      ];
      this._gradPalette = null;
    }
    onAdd() {
      this.canvas = document.createElement('canvas');
      this.canvas.style.position = 'absolute';
      this.canvas.style.pointerEvents = 'none';
      this.getPanes().overlayLayer.appendChild(this.canvas);
    }
    onRemove() {
      if (this.canvas && this.canvas.parentNode) this.canvas.parentNode.removeChild(this.canvas);
      this.canvas = null;
    }
    _palette() {
      if (this._gradPalette) return this._gradPalette;
      const c = document.createElement('canvas');
      c.width = 1; c.height = 256;
      const ctx = c.getContext('2d');
      const g = ctx.createLinearGradient(0, 0, 0, 256);
      for (const [stop, rgba] of this.gradient) {
        g.addColorStop(stop, `rgba(${rgba[0]},${rgba[1]},${rgba[2]},${(rgba[3]||255)/255})`);
      }
      ctx.fillStyle = g;
      ctx.fillRect(0, 0, 1, 256);
      this._gradPalette = ctx.getImageData(0, 0, 1, 256).data;
      return this._gradPalette;
    }
    _circleTemplate() {
      if (this._tpl && this._tpl.r === this.radius) return this._tpl.canvas;
      const r = this.radius;
      const c = document.createElement('canvas');
      c.width = c.height = r * 2;
      const ctx = c.getContext('2d');
      const g = ctx.createRadialGradient(r, r, 0, r, r, r);
      g.addColorStop(0, 'rgba(0,0,0,1)');
      g.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = g;
      ctx.fillRect(0, 0, r * 2, r * 2);
      this._tpl = { r, canvas: c };
      return c;
    }
    draw() {
      const projection = this.getProjection();
      if (!projection) return;
      const mapInst = this.getMap();
      const bounds = mapInst.getBounds();
      if (!bounds) return;

      const ne = projection.fromLatLngToDivPixel(bounds.getNorthEast());
      const sw = projection.fromLatLngToDivPixel(bounds.getSouthWest());
      const left = Math.min(ne.x, sw.x);
      const top  = Math.min(ne.y, sw.y);
      const width  = Math.abs(ne.x - sw.x);
      const height = Math.abs(sw.y - ne.y);

      this.canvas.style.left = left + 'px';
      this.canvas.style.top  = top  + 'px';
      this.canvas.width  = Math.max(1, Math.floor(width));
      this.canvas.height = Math.max(1, Math.floor(height));

      const ctx = this.canvas.getContext('2d');
      const r = this.radius;
      const tpl = this._circleTemplate();

      // Apilar puntos con alpha acumulado
      ctx.globalCompositeOperation = 'source-over';
      ctx.globalAlpha = 0.35;
      for (const p of this.points) {
        const px = projection.fromLatLngToDivPixel(new google.maps.LatLng(parseFloat(p.lat), parseFloat(p.lng)));
        const x = px.x - left - r;
        const y = px.y - top  - r;
        if (x + r*2 < 0 || y + r*2 < 0 || x > this.canvas.width || y > this.canvas.height) continue;
        ctx.drawImage(tpl, x, y);
      }
      ctx.globalAlpha = 1;

      // Mapear alpha → color
      const img = ctx.getImageData(0, 0, this.canvas.width, this.canvas.height);
      const data = img.data;
      const palette = this._palette();
      const op = this.opacity;
      for (let i = 0; i < data.length; i += 4) {
        const a = data[i + 3];
        if (a === 0) continue;
        const off = a * 4;
        data[i]     = palette[off];
        data[i + 1] = palette[off + 1];
        data[i + 2] = palette[off + 2];
        data[i + 3] = Math.floor(palette[off + 3] * op);
      }
      ctx.putImageData(img, 0, 0);
    }
  }
  return new HeatOverlay();
}

function colorParaTicket(t) {
  if (t.vencido == 1)      return '#ce3a2b'; // rojo
  if (t.resuelto == 1)     return '#188a5b'; // verde
  if (t.estado === 'Abierto' || t.estado === 'Nuevo' || t.estado === 'Asignado cuadrilla') return '#d99000'; // ámbar
  return '#6b7280';                          // gris
}

function iconoSVG(color) {
  return {
    path: 'M12 0C5.4 0 0 5.4 0 12c0 9 12 24 12 24s12-15 12-24c0-6.6-5.4-12-12-12z',
    fillColor: color,
    fillOpacity: 0.95,
    strokeColor: '#ffffff',
    strokeWeight: 2,
    scale: 0.85,
    anchor: new google.maps.Point(12, 36),
  };
}

function contenidoPopup(t) {
  const pillEstado =
    t.vencido == 1 ? '<span class="pill negative">VENCIDO</span>' :
    t.resuelto == 1 ? '<span class="pill positive">RESUELTO</span>' :
    `<span class="pill warning">${t.estado || '—'}</span>`;
  return `
    <div class="infobox">
      <h4>#${t.id} ${pillEstado}</h4>
      <div class="row"><b>Servicio</b><span>${t.servicio || '—'}</span></div>
      <div class="row"><b>Grupo</b><span>${t.grupo || '—'}</span></div>
      <div class="row"><b>Delegación</b><span>${t.delegacion || '—'}</span></div>
      <div class="row"><b>Colonia</b><span>${t.colonia || '—'}</span></div>
      <div class="row"><b>Dirección</b><span>${t.direccion || '—'}</span></div>
      <div class="row"><b>Canal</b><span>${t.canal || '—'}</span></div>
      <div class="row"><b>Fecha</b><span>${t.fecha || '—'}</span></div>
    </div>
  `;
}

function initMap() {
  map = new google.maps.Map(document.getElementById('map'), {
    center: CENTRO,
    zoom: ZOOM,
    mapId: MAP_ID,                 // habilita marcadores avanzados
    mapTypeControl: true,
    streetViewControl: false,
    fullscreenControl: true,
  });

  infoWindow = new google.maps.InfoWindow();

  // Marcadores avanzados (AdvancedMarkerElement con pin de color)
  mkObjs = MARKERS.map(t => {
    const pin = new google.maps.marker.PinElement({
      background: colorParaTicket(t),
      borderColor: '#ffffff',
      glyphColor: '#ffffff',
      scale: 0.9,
    });
    pin.element.style.cursor = 'pointer';
    const m = new google.maps.marker.AdvancedMarkerElement({
      position: { lat: parseFloat(t.lat), lng: parseFloat(t.lng) },
      content: pin.element,
      title: `#${t.id} · ${t.servicio || ''}`,
      gmpClickable: true,
    });
    pin.element.addEventListener('click', () => {
      infoWindow.setContent(contenidoPopup(t));
      infoWindow.open({ map, anchor: m });
    });
    return m;
  });

  // Cluster con renderer avanzado (evita el google.maps.Marker legacy en las burbujas)
  markerCluster = new markerClusterer.MarkerClusterer({ map, markers: mkObjs, renderer: clusterRendererQRO });

  // Ajustar bounds si hay muchos marcadores
  if (mkObjs.length > 3) {
    const bounds = new google.maps.LatLngBounds();
    mkObjs.forEach(m => bounds.extend(m.position));
    map.fitBounds(bounds, 50);
  }

  // Toggle marcadores / heatmap
  document.getElementById('btnMarkers').onclick = () => activarModo('markers');
  document.getElementById('btnHeatmap').onclick = () => activarModo('heatmap');
}

function activarModo(modo) {
  modoActual = modo;
  document.getElementById('btnMarkers').classList.toggle('active', modo==='markers');
  document.getElementById('btnHeatmap').classList.toggle('active', modo==='heatmap');

  if (modo === 'markers') {
    if (heatOverlay) heatOverlay.setMap(null);
    markerCluster.setMap(map);
  } else {
    markerCluster.setMap(null);
    if (!heatOverlay) {
      heatOverlay = makeHeatOverlay(MARKERS, { radius: 28, opacity: 0.75 });
    }
    heatOverlay.setMap(map);
  }
}

</script>
<!-- MarkerClusterer se carga ANTES del API (bloqueante) para evitar la carrera:
     initMap (callback de Google) lo usa, así nunca llega undefined. -->
<script src="https://unpkg.com/@googlemaps/markerclusterer@2.5.3/dist/index.min.js"></script>
<script async
  src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($api_key) ?>&libraries=marker&loading=async&v=weekly&callback=initMap">
</script>
<?php endif; ?>

</body>
</html>
