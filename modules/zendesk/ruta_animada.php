<?php
/**
 * Simulación animada de la ruta de UN día de UNA cuadrilla.
 *
 * Reutiliza los mismos parámetros que cuadrillas.php (incluyendo todos los filtros y
 * pesos), regenera el plan de forma determinista y muestra una animación interactiva
 * del recorrido sobre Google Maps con:
 *   - Marcador "vehículo" que avanza por la ruta
 *   - Tiempo simulado en tiempo real
 *   - Pausa en cada ticket por los minutos de servicio configurados
 *   - Controles: play/pausa, velocidad 1×/5×/30×/120×, ir a ticket
 */
require __DIR__ . '/db.php';
$pdo = db();
$cfg = require __DIR__ . '/config.php';
$api_key = trim($cfg['google_maps_api_key'] ?? '');

// ============================================================
//  Helpers idénticos a cuadrillas.php
// ============================================================
function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2)) * sin($dLng/2)**2;
    return 2 * $R * asin(min(1.0, sqrt($a)));
}
function ruta_nn(array $depot, array $puntos): array {
    $ruta = []; $current = $depot;
    while (count($puntos)) {
        $bestIdx = 0; $bestDist = INF;
        foreach ($puntos as $i => $p) {
            $d = haversine_km($current['lat'], $current['lng'], $p['lat'], $p['lng']);
            if ($d < $bestDist) { $bestDist = $d; $bestIdx = $i; }
        }
        $picked = $puntos[$bestIdx];
        $picked['dist_prev_km'] = round($bestDist, 3);
        $ruta[] = $picked;
        $current = ['lat'=>$picked['lat'],'lng'=>$picked['lng']];
        array_splice($puntos, $bestIdx, 1);
    }
    return $ruta;
}
function kmeans(array $puntos, int $k, int $max_iter = 30): array {
    if (count($puntos) <= $k) {
        $clusters = [];
        foreach ($puntos as $p) $clusters[] = [$p];
        while (count($clusters) < $k) $clusters[] = [];
        return $clusters;
    }
    $centroids = [];
    $puntos_init = $puntos;
    usort($puntos_init, fn($a,$b) => $a['lat'] <=> $b['lat']);
    $centroids[] = $puntos_init[0];
    while (count($centroids) < $k) {
        $bestPt = null; $bestDist = -1;
        foreach ($puntos as $p) {
            $minToCentroid = INF;
            foreach ($centroids as $c) {
                $d = haversine_km($p['lat'],$p['lng'],$c['lat'],$c['lng']);
                if ($d < $minToCentroid) $minToCentroid = $d;
            }
            if ($minToCentroid > $bestDist) { $bestDist = $minToCentroid; $bestPt = $p; }
        }
        $centroids[] = $bestPt;
    }
    for ($iter = 0; $iter < $max_iter; $iter++) {
        $clusters = array_fill(0, $k, []);
        foreach ($puntos as $p) {
            $best = 0; $bestD = INF;
            foreach ($centroids as $idx => $c) {
                $d = haversine_km($p['lat'],$p['lng'],$c['lat'],$c['lng']);
                if ($d < $bestD) { $bestD = $d; $best = $idx; }
            }
            $clusters[$best][] = $p;
        }
        $changed = false;
        foreach ($clusters as $idx => $cl) {
            if (empty($cl)) continue;
            $newLat = array_sum(array_column($cl,'lat')) / count($cl);
            $newLng = array_sum(array_column($cl,'lng')) / count($cl);
            if (abs($newLat - $centroids[$idx]['lat']) > 1e-6 || abs($newLng - $centroids[$idx]['lng']) > 1e-6) {
                $changed = true;
                $centroids[$idx] = ['lat'=>$newLat, 'lng'=>$newLng];
            }
        }
        if (!$changed) break;
    }
    return $clusters;
}

// ============================================================
//  Leer mismos parámetros que cuadrillas.php
// ============================================================
$p_grupo      = isset($_GET['grupo']) && $_GET['grupo']!=='' ? (int)$_GET['grupo'] : null;
$p_servicio   = isset($_GET['tipo_servicio']) && $_GET['tipo_servicio']!=='' ? (int)$_GET['tipo_servicio'] : null;
$p_delegacion = isset($_GET['delegacion']) && $_GET['delegacion']!=='' ? (int)$_GET['delegacion'] : null;
$p_estado     = $_GET['estado'] ?? 'sin_resolver';
$p_cuadrillas = max(1, min(10, (int)($_GET['cuadrillas'] ?? 2)));
$p_capacidad  = max(1, min(200, (int)($_GET['capacidad'] ?? 30)));
$p_dias       = max(1, min(14, (int)($_GET['dias'] ?? 5)));
$p_hora_inicio= $_GET['hora_inicio'] ?? '08:00';
$p_min_serv   = max(0, min(240, (int)($_GET['min_serv'] ?? 15)));
$p_vel_kmh    = max(5, min(120, (int)($_GET['vel_kmh']  ?? 25)));
$p_lat        = isset($_GET['lat']) && $_GET['lat']!=='' ? (float)$_GET['lat'] : (float)$cfg['mapa_centro_lat'];
$p_lng        = isset($_GET['lng']) && $_GET['lng']!=='' ? (float)$_GET['lng'] : (float)$cfg['mapa_centro_lng'];
$p_w_antig    = (float)($_GET['w_antig']    ?? 1.0);
$p_w_vencido  = (float)($_GET['w_vencido']  ?? 15.0);
$p_w_urgente  = (float)($_GET['w_urgente']  ?? 30.0);
$p_w_geo      = (float)($_GET['w_geo']      ?? 0.0);
$p_metodo     = $_GET['metodo'] ?? 'kmeans';

$cuadrilla_idx = max(1, (int)($_GET['cuadrilla_idx'] ?? 1)) - 1;  // 0-indexed
$dia_idx       = max(1, (int)($_GET['dia_idx'] ?? 1)) - 1;        // 0-indexed

// ============================================================
//  Regenerar plan (mismo algoritmo que cuadrillas.php)
// ============================================================
$where = ["t.latitud IS NOT NULL", "t.longitud IS NOT NULL"];
$params = [];
if ($p_grupo)      { $where[] = "t.grupo_id = ?";         $params[] = $p_grupo; }
if ($p_servicio)   { $where[] = "t.tipo_servicio_id = ?"; $params[] = $p_servicio; }
if ($p_delegacion) { $where[] = "t.delegacion_id = ?";    $params[] = $p_delegacion; }
if ($p_estado === 'sin_resolver') $where[] = "e.es_resuelto = 0";
elseif ($p_estado === 'vencidos') $where[] = "e.es_resuelto = 0 AND t.fecha_estimada < CURDATE()";
elseif ($p_estado === 'abiertos') $where[] = "e.nombre IN ('Abierto','Nuevo','Asignado cuadrilla','En proceso cuadrilla')";

$sql = "SELECT t.ticket_id AS id, t.latitud AS lat, t.longitud AS lng,
               t.fecha_creacion, t.fecha_estimada,
               e.nombre AS estado, e.es_resuelto,
               CASE WHEN e.es_resuelto = 0 AND t.fecha_estimada < CURDATE() THEN 1 ELSE 0 END AS vencido,
               p.nombre AS prioridad, g.nombre AS grupo,
               ts.nombre AS servicio, d.nombre AS delegacion,
               t.colonia, t.direccion,
               DATEDIFF(CURDATE(), t.fecha_creacion) AS dias_abierto
        FROM tickets t
        LEFT JOIN cat_estado e ON e.id=t.estado_id
        LEFT JOIN cat_prioridad p ON p.id=t.prioridad_id
        LEFT JOIN cat_grupo g ON g.id=t.grupo_id
        LEFT JOIN cat_tipo_servicio ts ON ts.id=t.tipo_servicio_id
        LEFT JOIN cat_delegacion d ON d.id=t.delegacion_id
        WHERE " . implode(' AND ', $where);
$st = $pdo->prepare($sql);
$st->execute($params);
$pool = $st->fetchAll();

foreach ($pool as &$t) {
    $score  = $p_w_antig * (int)$t['dias_abierto'];
    $score += $p_w_vencido * (int)$t['vencido'];
    if (in_array($t['prioridad'], ['High','Urgent'])) $score += $p_w_urgente;
    if ($p_w_geo > 0) {
        $score -= $p_w_geo * haversine_km($p_lat, $p_lng, (float)$t['lat'], (float)$t['lng']);
    }
    $t['score'] = $score;
    $t['lat'] = (float)$t['lat'];
    $t['lng'] = (float)$t['lng'];
}
unset($t);
usort($pool, fn($a,$b) => $b['score'] <=> $a['score']);
$seleccionados = array_slice($pool, 0, $p_cuadrillas * $p_capacidad * $p_dias);

// Asignación (mismas reglas)
if ($p_metodo === 'roundrobin') {
    $clusters = array_fill(0, $p_cuadrillas, []);
    foreach ($seleccionados as $i => $t) $clusters[$i % $p_cuadrillas][] = $t;
} elseif ($p_metodo === 'delegacion') {
    $por_deleg = [];
    foreach ($seleccionados as $t) $por_deleg[$t['delegacion']][] = $t;
    uksort($por_deleg, fn($a,$b) => count($por_deleg[$b]) - count($por_deleg[$a]));
    $clusters = array_slice(array_values($por_deleg), 0, $p_cuadrillas);
    while (count($clusters) < $p_cuadrillas) $clusters[] = [];
} else {
    $clusters = kmeans($seleccionados, $p_cuadrillas);
}

// Balanceo
$capacidad_total = $p_capacidad * $p_dias;
foreach ($clusters as $idx => $cl) {
    if (count($cl) > $capacidad_total) {
        $exc = array_splice($clusters[$idx], $capacidad_total);
        foreach ($exc as $t) {
            $minIdx = 0; $minN = count($clusters[0]);
            foreach ($clusters as $j=>$o) if (count($o)<$minN) { $minN=count($o); $minIdx=$j; }
            $clusters[$minIdx][] = $t;
        }
    }
}

$depot = ['lat'=>$p_lat, 'lng'=>$p_lng];
if (!isset($clusters[$cuadrilla_idx]) || empty($clusters[$cuadrilla_idx])) {
    die("Cuadrilla no encontrada o vacía.");
}

$tickets_cu = $clusters[$cuadrilla_idx];
usort($tickets_cu, fn($a,$b) => $b['score'] <=> $a['score']);

// Saltar a día correcto
$rem = $tickets_cu;
for ($d = 0; $d < $dia_idx; $d++) {
    $rem = array_slice($rem, $p_capacidad);
}
$chunk = array_slice($rem, 0, $p_capacidad);
if (empty($chunk)) die("Ese día no tiene tickets.");

$ruta_dia = ruta_nn($depot, $chunk);

// ============================================================
//  Construir timeline para la animación
// ============================================================
$hi = explode(':', $p_hora_inicio);
$start_sec = ((int)($hi[0] ?? 8)) * 3600 + ((int)($hi[1] ?? 0)) * 60;

$timeline = [];
$current = ['lat' => $p_lat, 'lng' => $p_lng];
$t = $start_sec;

foreach ($ruta_dia as $i => $tk) {
    // tramo de viaje
    $duracion = ($tk['dist_prev_km'] / $p_vel_kmh) * 3600;  // segundos
    $timeline[] = [
        'tipo'   => 'viaje',
        'from'   => $t,
        'to'     => $t + $duracion,
        'from_lat' => $current['lat'], 'from_lng' => $current['lng'],
        'to_lat'   => $tk['lat'],      'to_lng'   => $tk['lng'],
        'km'     => round($tk['dist_prev_km'], 2),
        'destino_idx' => $i,
    ];
    $t += $duracion;
    // servicio
    $timeline[] = [
        'tipo'    => 'servicio',
        'from'    => $t,
        'to'      => $t + $p_min_serv * 60,
        'ticket'  => [
            'id'        => $tk['id'],
            'lat'       => $tk['lat'], 'lng' => $tk['lng'],
            'servicio'  => $tk['servicio'],
            'colonia'   => $tk['colonia'],
            'delegacion'=> $tk['delegacion'],
            'direccion' => $tk['direccion'],
            'dias_abierto' => $tk['dias_abierto'],
            'vencido'   => $tk['vencido'],
            'eta_hhmm'  => (function($s){$s=(int)$s;return sprintf('%02d:%02d', intdiv($s,3600)%24, intdiv($s%3600,60));})($t),
        ],
        'idx'     => $i,
    ];
    $t += $p_min_serv * 60;
    $current = ['lat' => $tk['lat'], 'lng' => $tk['lng']];
}
// Regreso al depósito
$dist_regreso = haversine_km($current['lat'], $current['lng'], $p_lat, $p_lng);
$dur_regreso = ($dist_regreso / $p_vel_kmh) * 3600;
$timeline[] = [
    'tipo' => 'viaje',
    'from' => $t, 'to' => $t + $dur_regreso,
    'from_lat' => $current['lat'], 'from_lng' => $current['lng'],
    'to_lat'   => $p_lat,          'to_lng'   => $p_lng,
    'km' => round($dist_regreso, 2),
    'destino_idx' => -1,  // -1 = depósito
];
$t += $dur_regreso;
$end_sec = $t;

$fmt = function($s) {
    $s = (int)$s;
    return sprintf('%02d:%02d', intdiv($s, 3600) % 24, intdiv($s % 3600, 60));
};
$duracion_total_min = (int)round(($end_sec - $start_sec) / 60);
$km_dia = round(array_sum(array_map(fn($x)=>$x['km'], array_filter($timeline, fn($x)=>$x['tipo']==='viaje'))), 1);
?>
<?php
$ktTitle  = 'Simulación · Cuadrilla ' . ($cuadrilla_idx + 1) . ' · Día ' . ($dia_idx + 1);
$ktActive = 'zendesk';
require __DIR__ . '/../../views/layout/kt_top.php';
?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#0f172a;--surface:#1e293b;--surface-2:#334155;--border:#334155;--text:#e2e8f0;--text-muted:#94a3b8;--text-faint:#5b667a;--ok:#22c55e;--warn:#f59e0b;--err:#ef4444}
*{box-sizing:border-box;-webkit-font-smoothing:antialiased}
html,body{margin:0;height:100%;font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:14px}
.app{display:grid;grid-template-rows:auto 1fr auto;height:100vh}
.app-header{padding:12px 20px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px}
.title{font-weight:600;font-size:15px}
.title b{color:var(--primary)}
.meta-bar{display:flex;gap:18px;color:var(--text-muted);font-size:12px;flex-wrap:wrap}
.meta-bar b{color:var(--text);font-weight:500}
.back{font-size:12px;color:var(--text-muted);text-decoration:none;padding:6px 12px;border:1px solid var(--border);border-radius:6px}
.back:hover{background:var(--surface-2);color:var(--text)}

.app-body{display:grid;grid-template-columns:1fr 340px;min-height:0}
@media(max-width:900px){.app-body{grid-template-columns:1fr} .side{display:none}}
#map{background:#0c1424}
.side{background:var(--surface);border-left:1px solid var(--border);overflow:auto;padding:14px}
.side h4{font-size:11px;color:var(--text-faint);text-transform:uppercase;letter-spacing:.08em;margin:0 0 10px}
.tk{background:var(--surface-2);padding:10px;border-radius:8px;margin-bottom:8px;border-left:3px solid var(--border);transition:.15s}
.tk.done{opacity:.5;border-left-color:var(--ok)}
.tk.current{border-left-color:var(--primary);background:#1e3a8a;animation:pulse 1.2s ease-in-out infinite}
@keyframes pulse{50%{background:#1e40af}}
.tk .hdr{display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);margin-bottom:4px}
.tk .hdr b{color:var(--text);font-family:ui-monospace,Menlo,monospace}
.tk .body{font-size:12px;line-height:1.4}
.tk .col{color:var(--text-muted);font-size:11px;margin-top:2px}
.tk .badge{display:inline-block;font-size:9px;padding:1px 6px;border-radius:999px;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.tk .badge.done{background:#14532d;color:#86efac}
.tk .badge.now{background:#1d4ed8;color:#dbeafe}
.tk .badge.pending{background:#334155;color:#94a3b8}

.app-footer{background:var(--surface);border-top:1px solid var(--border);padding:14px 20px}
.controls{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.btn{background:var(--surface-2);color:var(--text);border:1px solid var(--border);border-radius:7px;padding:8px 14px;font:inherit;font-size:13px;cursor:pointer;font-weight:500}
.btn:hover{background:#475569}
.btn.primary{background:var(--primary);border-color:var(--primary);color:#fff}
.btn.primary:hover{background:#254185}
.btn-play{font-size:18px;width:44px;height:44px;padding:0;border-radius:50%;display:flex;align-items:center;justify-content:center}
.speed-pick{display:flex;gap:4px;background:var(--surface-2);border-radius:7px;padding:3px}
.speed-pick button{background:transparent;border:0;color:var(--text-muted);padding:5px 9px;font:inherit;font-size:11px;font-weight:600;cursor:pointer;border-radius:5px}
.speed-pick button.active{background:var(--primary);color:#fff}
.clock{font-family:ui-monospace,Menlo,monospace;font-size:22px;font-weight:600;color:var(--text)}
.clock-label{font-size:10px;color:var(--text-faint);text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin-bottom:2px}
.progress-wrap{flex:1;min-width:200px}
.progress-bar{width:100%;height:8px;background:var(--surface-2);border-radius:4px;overflow:hidden;cursor:pointer;position:relative}
.progress-bar > .fill{height:100%;background:var(--primary);width:0%;transition:width .15s linear}
.progress-meta{font-size:11px;color:var(--text-muted);margin-top:4px;display:flex;justify-content:space-between}
.status-pill{display:inline-flex;align-items:center;gap:6px;background:var(--surface-2);padding:6px 11px;border-radius:7px;font-size:12px;font-weight:500}
.status-pill .dot{width:8px;height:8px;border-radius:50%}
.status-pill.viaje .dot{background:var(--warn)}
.status-pill.servicio .dot{background:var(--ok)}
.status-pill.fin .dot{background:var(--text-faint)}
</style>

<div class="app">

<div class="app-header">
  <div>
    <div class="title">▶ Simulación de ruta · <b>Cuadrilla <?= $cuadrilla_idx+1 ?></b> · Día <?= $dia_idx+1 ?></div>
    <div style="font-size:11px;color:var(--text-faint);margin-top:2px"><a href="javascript:history.back()" class="back" style="border:0;padding:0;color:var(--primary)">← volver al planificador</a></div>
  </div>
  <div class="meta-bar">
    <span><b><?= count($ruta_dia) ?></b> tickets</span>
    <span><b><?= $km_dia ?></b> km</span>
    <span>Inicio <b><?= $fmt($start_sec) ?></b></span>
    <span>Fin estimado <b><?= $fmt($end_sec) ?></b></span>
    <span>Duración <b><?= floor($duracion_total_min/60) ?>h <?= $duracion_total_min%60 ?>m</b></span>
    <span>Vel <b><?= $p_vel_kmh ?> km/h</b></span>
    <span>Servicio <b><?= $p_min_serv ?> min/ticket</b></span>
  </div>
</div>

<div class="app-body">
  <?php if (!empty($api_key)): ?>
    <div style="position:relative;width:100%;height:100%;min-height:0">
      <div id="map" style="position:absolute;inset:0"></div>
      <div id="loading" style="position:absolute;inset:0;background:rgba(15,23,42,.9);display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:50;font-size:13px;color:var(--text-muted)">
        <div style="width:36px;height:36px;border:3px solid var(--surface-2);border-top-color:var(--primary);border-radius:50%;animation:spin 1s linear infinite;margin-bottom:14px"></div>
        <div id="loading-text">Trazando rutas viales…</div>
        <div style="font-size:11px;color:var(--text-faint);margin-top:6px" id="loading-sub"></div>
      </div>
    </div>
    <style>@keyframes spin{to{transform:rotate(360deg)}}</style>
  <?php else: ?>
    <div style="display:flex;align-items:center;justify-content:center;color:var(--text-muted);padding:30px">
      Falta tu API Key en config.php
    </div>
  <?php endif; ?>

  <aside class="side">
    <h4>Tickets de hoy</h4>
    <div id="tickets-list"></div>
  </aside>
</div>

<div class="app-footer">
  <div class="controls">
    <button id="btn-play" class="btn primary btn-play" title="Play/Pause">▶</button>
    <button id="btn-reset" class="btn" title="Reiniciar">⟲</button>

    <div>
      <div class="clock-label">Hora simulada</div>
      <div class="clock" id="clock"><?= $fmt($start_sec) ?></div>
    </div>

    <div class="status-pill viaje" id="status">
      <span class="dot"></span>
      <span id="status-text">Listo para iniciar</span>
    </div>

    <div class="progress-wrap">
      <div class="progress-bar" id="bar"><div class="fill" id="bar-fill"></div></div>
      <div class="progress-meta"><span id="bar-now">00:00</span><span id="bar-end"><?= floor($duracion_total_min/60) ?>h <?= $duracion_total_min%60 ?>m</span></div>
    </div>

    <div>
      <div class="clock-label">Velocidad simulación</div>
      <div class="speed-pick" id="speed">
        <button data-s="1">1×</button>
        <button data-s="10">10×</button>
        <button data-s="60" class="active">60×</button>
        <button data-s="300">5min/s</button>
        <button data-s="900">15min/s</button>
      </div>
    </div>
  </div>
</div>

</div>

<?php if (!empty($api_key)): ?>
<script>
const TIMELINE = <?= json_encode($timeline, JSON_UNESCAPED_UNICODE) ?>;
const RUTA     = <?= json_encode($ruta_dia, JSON_UNESCAPED_UNICODE) ?>;
const DEPOT    = { lat: <?= $p_lat ?>, lng: <?= $p_lng ?> };
const START    = <?= $start_sec ?>;
const END      = <?= $end_sec ?>;

let map, vehicle, infoWin;
let simTime = START;
let lastTick = 0;
let playing = false;
let speed = 60;
let routeLegs = [];                    // routeLegs[i] = {path:[LatLng…], totalDist:meters} (uno por tramo viaje)
let travelIdxOfTL = [];                // para cada índice de TIMELINE, índice de viaje correspondiente (-1 si es servicio)
let mapReady = false;

// ===== Helpers =====
function fmt(s) {
  s = ((s % 86400) + 86400) % 86400;
  return String(Math.floor(s/3600)).padStart(2,'0') + ':' + String(Math.floor((s%3600)/60)).padStart(2,'0');
}
function lerp(a, b, t) { return a + (b - a) * t; }

async function initMap() {
  map = new google.maps.Map(document.getElementById('map'), {
    center: DEPOT, zoom: 13,
    mapTypeControl: false, streetViewControl: false, fullscreenControl: true,
    styles: [
      {elementType:'geometry',stylers:[{color:'#1d2c4d'}]},
      {elementType:'labels.text.fill',stylers:[{color:'#8ec3b9'}]},
      {elementType:'labels.text.stroke',stylers:[{color:'#1a3646'}]},
      {featureType:'road',elementType:'geometry',stylers:[{color:'#304a7d'}]},
      {featureType:'water',elementType:'geometry',stylers:[{color:'#0e1626'}]},
      {featureType:'poi',stylers:[{visibility:'off'}]}
    ]
  });
  infoWin = new google.maps.InfoWindow();

  // Depot
  new google.maps.Marker({
    position: DEPOT, map,
    icon: { path: google.maps.SymbolPath.CIRCLE, scale: 9, fillColor:'#fff', fillOpacity:1, strokeColor:'#3b82f6', strokeWeight:3 },
    title: 'Punto de partida', zIndex: 100
  });

  // Marcadores numerados
  const bounds = new google.maps.LatLngBounds(DEPOT);
  RUTA.forEach((t, i) => {
    const m = new google.maps.Marker({
      position: { lat: t.lat, lng: t.lng }, map,
      label: { text: String(i+1), color:'#fff', fontSize:'10px', fontWeight:'600' },
      icon: { path: google.maps.SymbolPath.CIRCLE, scale: 11, fillColor:'#475569', fillOpacity:1, strokeColor:'#fff', strokeWeight:2 }
    });
    m.addListener('click', () => {
      infoWin.setContent(`<div style="font-family:Inter;font-size:12px;color:#000;max-width:240px">
        <b>#${t.id}</b> · ETA ${t.eta_hhmm || ''}<br>${t.servicio || ''}<br>
        <span style="color:#6b7280">${t.colonia || ''}</span></div>`);
      infoWin.open(map, m);
    });
    bounds.extend(m.getPosition());
  });
  map.fitBounds(bounds, 80);

  // Vehículo (oculto hasta tener rutas)
  vehicle = new google.maps.Marker({
    position: DEPOT, map,
    icon: {
      path: 'M -10,0 L 0,-14 L 10,0 Z',
      fillColor: '#ef4444', fillOpacity: 1, strokeColor:'#fff', strokeWeight:2, scale: 1, rotation: 0
    },
    zIndex: 1000
  });

  // Pre-mapear TIMELINE → travel index
  let tCount = 0;
  TIMELINE.forEach(s => {
    if (s.tipo === 'viaje') { travelIdxOfTL.push(tCount); tCount++; }
    else travelIdxOfTL.push(-1);
  });

  // Cargar rutas reales
  await fetchAllRoutes();

  document.getElementById('loading').style.display = 'none';
  mapReady = true;
  renderTickets();
  requestAnimationFrame(tick);
}

// Routing con OSRM (Open Source Routing Machine, datos de OpenStreetMap).
// Es gratis y no necesita API key — usa el servidor público demo.
// Para producción intensiva conviene self-host OSRM o pasar a Mapbox/OpenRouteService.
async function fetchAllRoutes() {
  const stops = [DEPOT, ...RUTA.map(t => ({lat:t.lat, lng:t.lng})), DEPOT];
  const MAX = 100;  // OSRM acepta muchos más waypoints por request que Google

  routeLegs = [];
  const totalLegs = stops.length - 1;
  let huboError = false;

  document.getElementById('loading-text').textContent = 'Trazando rutas viales con OSRM (OpenStreetMap)…';

  for (let s = 0; s < stops.length - 1; s += (MAX - 1)) {
    const e = Math.min(s + MAX - 1, stops.length - 1);
    const chunk = stops.slice(s, e + 1);

    document.getElementById('loading-sub').textContent = `Tramos ${s+1}–${e} de ${totalLegs}`;

    // OSRM: coordenadas en formato lng,lat;lng,lat;…
    const coords = chunk.map(p => `${p.lng.toFixed(6)},${p.lat.toFixed(6)}`).join(';');
    const url = `https://router.project-osrm.org/route/v1/driving/${coords}?steps=true&geometries=geojson&overview=false`;

    try {
      const resp = await fetch(url);
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      const data = await resp.json();
      if (data.code !== 'Ok' || !data.routes || !data.routes[0] || !data.routes[0].legs) {
        throw new Error('OSRM respuesta: ' + (data.code || 'sin rutas'));
      }

      // Por cada leg (tramo entre dos paradas consecutivas), concatenamos la geometría
      // de todos sus steps en una sola polilínea
      for (const leg of data.routes[0].legs) {
        const path = [];
        for (const step of leg.steps) {
          if (step.geometry && step.geometry.coordinates) {
            for (const [lng, lat] of step.geometry.coordinates) {
              path.push(new google.maps.LatLng(lat, lng));
            }
          }
        }
        if (path.length === 0) {
          // Sin geometría disponible para este leg
          path.push(new google.maps.LatLng(chunk[0].lat, chunk[0].lng));
          path.push(new google.maps.LatLng(chunk[chunk.length-1].lat, chunk[chunk.length-1].lng));
        }
        routeLegs.push({
          path,
          totalDist: leg.distance || 0
        });
      }
    } catch (err) {
      console.warn('OSRM falló en chunk', err);
      huboError = true;
      // Fallback: línea recta por tramo
      for (let i = s; i < e; i++) {
        routeLegs.push({
          path: [
            new google.maps.LatLng(stops[i].lat, stops[i].lng),
            new google.maps.LatLng(stops[i+1].lat, stops[i+1].lng)
          ],
          totalDist: 0
        });
      }
    }
  }

  if (huboError) {
    document.getElementById('loading-text').textContent = '⚠️ OSRM no respondió — usando líneas rectas';
    setTimeout(() => { document.getElementById('loading').style.display = 'none'; }, 3000);
  }

  // Pintar polilíneas viales
  routeLegs.forEach(leg => {
    new google.maps.Polyline({
      path: leg.path, geodesic: false, strokeColor: '#3b82f6',
      strokeOpacity: 0.55, strokeWeight: 4, map
    });
  });
}

function positionInLeg(legIdx, progress) {
  const leg = routeLegs[legIdx];
  if (!leg || leg.path.length < 2) return null;
  // Fallback (Directions falló): lerp entre primer y último punto
  if (leg.totalDist === 0) {
    const a = leg.path[0], b = leg.path[leg.path.length - 1];
    return { lat: a.lat() + (b.lat()-a.lat())*progress, lng: a.lng() + (b.lng()-a.lng())*progress };
  }
  const target = progress * leg.totalDist;
  let acc = 0;
  for (let i = 0; i < leg.path.length - 1; i++) {
    const d = google.maps.geometry.spherical.computeDistanceBetween(leg.path[i], leg.path[i+1]);
    if (acc + d >= target) {
      const t = d > 0 ? (target - acc) / d : 0;
      return {
        lat: leg.path[i].lat() + (leg.path[i+1].lat() - leg.path[i].lat()) * t,
        lng: leg.path[i].lng() + (leg.path[i+1].lng() - leg.path[i].lng()) * t,
        prevLat: leg.path[i].lat(), prevLng: leg.path[i].lng(),
        nextLat: leg.path[i+1].lat(), nextLng: leg.path[i+1].lng()
      };
    }
    acc += d;
  }
  const last = leg.path[leg.path.length - 1];
  return { lat: last.lat(), lng: last.lng() };
}

function findSegment(t) {
  for (let i = 0; i < TIMELINE.length; i++) {
    if (t >= TIMELINE[i].from && t <= TIMELINE[i].to) return [i, TIMELINE[i]];
  }
  if (t < TIMELINE[0].from) return [0, TIMELINE[0]];
  return [TIMELINE.length - 1, TIMELINE[TIMELINE.length - 1]];
}

function update() {
  if (!mapReady) return;
  const [tlIdx, seg] = findSegment(simTime);
  let lat, lng, statusText, statusKind;
  if (seg.tipo === 'viaje') {
    const progress = (simTime - seg.from) / Math.max(1, seg.to - seg.from);
    const legIdx = travelIdxOfTL[tlIdx];
    const pos = positionInLeg(legIdx, Math.min(1, Math.max(0, progress)));
    if (pos) {
      lat = pos.lat; lng = pos.lng;
      // Rotar hacia el siguiente segmento de la polilínea
      if (pos.nextLat !== undefined) {
        const h = Math.atan2(pos.nextLng - pos.prevLng, pos.nextLat - pos.prevLat) * 180 / Math.PI;
        const icon = vehicle.getIcon();
        icon.rotation = h;
        vehicle.setIcon(icon);
      }
    } else {
      // Sin ruta cargada → quedarse en el destino
      lat = seg.to_lat; lng = seg.to_lng;
    }
    statusKind = 'viaje';
    statusText = seg.destino_idx === -1
      ? `Regresando al depósito (${seg.km} km estimados)`
      : `Viajando a ticket #${seg.destino_idx + 1} (${seg.km} km estimados)`;
  } else {
    lat = seg.ticket.lat; lng = seg.ticket.lng;
    statusKind = 'servicio';
    const restantes = Math.ceil((seg.to - simTime) / 60);
    statusText = `Atendiendo ticket #${seg.idx + 1} (#${seg.ticket.id}) — falta ${restantes} min`;
  }
  if (simTime >= END) { statusKind = 'fin'; statusText = '✓ Jornada completada'; playing = false; updatePlayBtn(); }

  vehicle.setPosition({ lat, lng });

  // UI
  document.getElementById('clock').textContent = fmt(simTime);
  const pct = ((simTime - START) / (END - START)) * 100;
  document.getElementById('bar-fill').style.width = pct + '%';
  const elapsed = simTime - START;
  document.getElementById('bar-now').textContent = `${Math.floor(elapsed/3600)}h ${Math.floor((elapsed%3600)/60)}m`;
  const st = document.getElementById('status');
  st.className = 'status-pill ' + statusKind;
  document.getElementById('status-text').textContent = statusText;

  renderTickets();
}

function renderTickets() {
  const cont = document.getElementById('tickets-list');
  if (!cont._built) {
    cont.innerHTML = RUTA.map((t,i) => `
      <div class="tk" data-i="${i}" id="tk-${i}">
        <div class="hdr">
          <b>${i+1}. #${t.id}</b>
          <span class="badge pending" id="badge-${i}">${t.eta_hhmm || ''}</span>
        </div>
        <div class="body">${(t.servicio||'').slice(0,42)}</div>
        <div class="col">${(t.colonia||'').slice(0,42)}</div>
      </div>
    `).join('');
    cont._built = true;
  }
  // Actualizar estado
  RUTA.forEach((t,i) => {
    const el = document.getElementById('tk-' + i);
    const b  = document.getElementById('badge-' + i);
    if (!el) return;
    // Encontrar el segmento de servicio de este ticket
    const segServ = TIMELINE.find(s => s.tipo === 'servicio' && s.idx === i);
    if (!segServ) return;
    if (simTime >= segServ.to) {
      el.classList.remove('current'); el.classList.add('done');
      b.className='badge done'; b.textContent='✓ atendido';
    } else if (simTime >= segServ.from) {
      el.classList.add('current'); el.classList.remove('done');
      b.className='badge now'; b.textContent='atendiendo…';
      el.scrollIntoView({behavior:'smooth',block:'nearest'});
    } else {
      el.classList.remove('current','done');
      b.className='badge pending'; b.textContent=t.eta_hhmm || '';
    }
  });
}

function tick(ts) {
  if (!lastTick) lastTick = ts;
  const dtMs = ts - lastTick; lastTick = ts;
  if (playing) {
    simTime += (dtMs / 1000) * speed;
    if (simTime > END) simTime = END;
    update();
  }
  requestAnimationFrame(tick);
}

function updatePlayBtn() {
  document.getElementById('btn-play').textContent = playing ? '⏸' : '▶';
}

// ===== Controles =====
document.getElementById('btn-play').onclick = () => {
  if (simTime >= END) simTime = START;
  playing = !playing;
  updatePlayBtn();
};
document.getElementById('btn-reset').onclick = () => {
  simTime = START; playing = false; updatePlayBtn(); update();
};
document.getElementById('speed').onclick = (e) => {
  if (e.target.tagName !== 'BUTTON') return;
  document.querySelectorAll('#speed button').forEach(b => b.classList.remove('active'));
  e.target.classList.add('active');
  speed = parseFloat(e.target.dataset.s);
};
document.getElementById('bar').onclick = (e) => {
  const r = e.currentTarget.getBoundingClientRect();
  const pct = (e.clientX - r.left) / r.width;
  simTime = START + pct * (END - START);
  update();
};
</script>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($api_key) ?>&libraries=geometry&callback=initMap"></script>
<?php endif; ?>
<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
