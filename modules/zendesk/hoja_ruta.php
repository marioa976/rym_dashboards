<?php
/**
 * Hoja de ruta imprimible para una cuadrilla en un día específico.
 *
 * Misma firma de parámetros que ruta_animada.php — regenera el plan en forma
 * determinista y renderiza un formato pensado para imprimir en papel carta.
 *
 * El operario recibe la lista ordenada de visitas con todos los datos para
 * trabajar sin computadora: hora estimada, dirección, coordenadas, espacio
 * para checkear estatus y escribir comentarios.
 */
require __DIR__ . '/db.php';
$pdo = db();
$cfg = require __DIR__ . '/config.php';

// ============================================================
//  Helpers de planeación (idénticos a ruta_animada.php)
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
//  Leer parámetros (mismos que cuadrillas.php / ruta_animada.php)
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

$cuadrilla_idx = max(1, (int)($_GET['cuadrilla_idx'] ?? 1)) - 1;
$dia_idx       = max(1, (int)($_GET['dia_idx'] ?? 1)) - 1;

// ============================================================
//  Regenerar plan
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
               t.solicitante_nombre_completo AS solicitante,
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

$rem = $tickets_cu;
for ($d = 0; $d < $dia_idx; $d++) {
    $rem = array_slice($rem, $p_capacidad);
}
$chunk = array_slice($rem, 0, $p_capacidad);
if (empty($chunk)) die("Ese día no tiene tickets.");

$ruta_dia = ruta_nn($depot, $chunk);

// ============================================================
//  Calcular ETAs
// ============================================================
$hi = explode(':', $p_hora_inicio);
$start_sec = ((int)($hi[0] ?? 8)) * 3600 + ((int)($hi[1] ?? 0)) * 60;
$fmtT = function($s) { $s = (int)$s; return sprintf('%02d:%02d', intdiv($s, 3600) % 24, intdiv($s % 3600, 60)); };

$t = $start_sec;
foreach ($ruta_dia as $i => &$tk) {
    $t += ($tk['dist_prev_km'] / $p_vel_kmh) * 3600;
    $tk['eta_hhmm'] = $fmtT($t);
    $tk['eta_seg']  = (int)$t;
    $t += $p_min_serv * 60;
    $tk['salida_hhmm'] = $fmtT($t);
}
unset($tk);

// Hora fin estimada
$ult = end($ruta_dia);
$regreso_km = haversine_km($ult['lat'], $ult['lng'], $depot['lat'], $depot['lng']);
$t += ($regreso_km / $p_vel_kmh) * 3600;
$hora_fin = $fmtT($t);
$duracion_min = (int)round(($t - $start_sec) / 60);
$km_dia = round(
    array_sum(array_map(fn($x) => $x['dist_prev_km'], $ruta_dia)) + $regreso_km,
    1
);
$total_vencidos = count(array_filter($ruta_dia, fn($x) => $x['vencido']));

// Nombre del filtro de grupo / servicio
$nombre_grupo = $nombre_servicio = $nombre_deleg = null;
if ($p_grupo) {
    $st = $pdo->prepare("SELECT nombre FROM cat_grupo WHERE id=?"); $st->execute([$p_grupo]);
    if ($r=$st->fetch()) $nombre_grupo = $r['nombre'];
}
if ($p_servicio) {
    $st = $pdo->prepare("SELECT nombre FROM cat_tipo_servicio WHERE id=?"); $st->execute([$p_servicio]);
    if ($r=$st->fetch()) $nombre_servicio = $r['nombre'];
}
if ($p_delegacion) {
    $st = $pdo->prepare("SELECT nombre FROM cat_delegacion WHERE id=?"); $st->execute([$p_delegacion]);
    if ($r=$st->fetch()) $nombre_deleg = $r['nombre'];
}
$meses = ['ENE','FEB','MAR','ABR','MAY','JUN','JUL','AGO','SEP','OCT','NOV','DIC'];
$fecha_gen = (int)date('d').' '.$meses[(int)date('n')-1].' '.date('Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Hoja de ruta · Cuadrilla <?= $cuadrilla_idx+1 ?> · Día <?= $dia_idx+1 ?></title>
<style>
@page { size: letter portrait; margin: 14mm 12mm; }

@media screen {
  body { background:#e5e7eb; margin:0; padding:24px 0; font-family:'Helvetica Neue',Arial,sans-serif; color:#000; }
  .page { background:#fff; max-width:8.5in; margin:0 auto 24px; padding:0.55in 0.5in; box-shadow:0 4px 16px rgba(0,0,0,.12); border-radius:4px; }
  .toolbar { max-width:8.5in; margin:0 auto 16px; padding:10px 16px; background:#fff; border:1px solid #d1d5db; border-radius:6px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 1px 3px rgba(0,0,0,.05) }
  .toolbar button { background:#254185; color:#fff; border:0; padding:9px 18px; border-radius:6px; font:inherit; font-weight:600; cursor:pointer; font-size:14px; }
  .toolbar button:hover { background:#1d4ed8 }
  .toolbar a { color:#254185; text-decoration:none; font-size:13px; padding:7px 12px; border:1px solid #d1d5db; border-radius:6px; }
  .toolbar a:hover { background:#f9fafb }
}
@media print {
  body { background:#fff; margin:0; padding:0; font-family:'Helvetica Neue',Arial,sans-serif; color:#000; }
  .page { padding:0; margin:0; box-shadow:none; max-width:none; }
  .no-print { display:none !important; }
  .ticket { page-break-inside: avoid; }
}

body { font-size:10.5pt; line-height:1.35; }

/* Encabezado */
.head { border-bottom:2px solid #000; padding-bottom:10px; margin-bottom:14px; }
.head .top { display:flex; justify-content:space-between; align-items:flex-start; }
.head h1 { font-size:18pt; margin:0 0 4px; letter-spacing:-.02em; font-weight:700; }
.head .sub { font-size:10pt; color:#444; }
.head .right { text-align:right; font-size:9pt; color:#555; line-height:1.5; }
.head .badge { display:inline-block; background:#000; color:#fff; padding:3px 9px; font-size:9pt; font-weight:700; letter-spacing:.04em; margin-bottom:4px }

/* Resumen */
.resumen { display:grid; grid-template-columns:repeat(6, 1fr); gap:8px; margin:0 0 14px; }
.resumen .box { border:1px solid #999; padding:6px 9px; }
.resumen .box .l { font-size:7.5pt; color:#555; text-transform:uppercase; letter-spacing:.04em; font-weight:600; }
.resumen .box .v { font-size:13pt; font-weight:700; line-height:1.1; margin-top:1px }

/* Filtros activos */
.filters { margin-bottom:12px; font-size:9pt; color:#444; padding:5px 9px; background:#f3f4f6; border-left:3px solid #000; }
.filters b { color:#000 }

/* Tickets */
.ticket { border:1px solid #999; margin-bottom:6px; padding:8px 10px; display:grid; grid-template-columns: 28px 60px 50px 1fr 110px; gap:8px; align-items:start; }
.ticket .num { font-size:18pt; font-weight:700; text-align:center; border-right:1px solid #ccc; padding-right:6px; }
.ticket .id { font-family:'Courier New',monospace; font-size:9pt; font-weight:600; }
.ticket .id .lbl { color:#666; font-weight:400; }
.ticket .hora { font-size:13pt; font-weight:700; font-family:'Courier New',monospace; }
.ticket .hora .salida { font-size:8pt; color:#666; font-weight:400; }
.ticket .info { font-size:10pt; line-height:1.4 }
.ticket .info .svc { font-weight:600; }
.ticket .info .col { color:#444; font-size:9pt; }
.ticket .info .dir { font-size:9.5pt; margin-top:2px }
.ticket .info .meta { font-size:8.5pt; color:#666; margin-top:3px; }
.ticket .info .meta b { color:#000; font-weight:600; }
.ticket .info .gps { font-family:'Courier New',monospace; font-size:8pt; color:#555; }

/* Estado checkboxes */
.estado-box { font-size:9pt; line-height:1.6; }
.estado-box .check { display:flex; align-items:center; gap:4px; }
.estado-box .check input { transform:scale(1.2); margin:0; }
.estado-box .check label { font-size:8.5pt; }
.estado-box .notas { border-top:1px dashed #ccc; margin-top:4px; padding-top:3px; }
.estado-box .notas .line { border-bottom:1px solid #999; height:9pt; margin-top:5px }

/* Distintivos */
.vencido-tag { background:#000; color:#fff; padding:1px 5px; font-size:7.5pt; font-weight:700; display:inline-block; letter-spacing:.05em }

/* Footer */
.footer { margin-top:20px; padding-top:14px; border-top:2px solid #000; display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; }
.footer .field { font-size:9pt; }
.footer .field .lbl { color:#555; text-transform:uppercase; font-size:7.5pt; letter-spacing:.05em; font-weight:600; }
.footer .field .line { border-bottom:1px solid #000; height:18pt; margin-top:3px; }

.tally { font-size:10pt; font-weight:600; margin:0 0 14px; padding:8px 12px; background:#fff8db; border:1px dashed #999; }
.tally .num { font-size:14pt; }

/* Página break controles */
.page-break { page-break-after: always; }
</style>
</head>
<body>
<?php $portalModulo='Zendesk'; @include __DIR__.'/../_portalbar.php'; ?>

<div class="toolbar no-print">
  <div>
    <b>Hoja de ruta</b> · Cuadrilla <?= $cuadrilla_idx+1 ?> · Día <?= $dia_idx+1 ?> ·
    <span style="color:#6b7280"><?= count($ruta_dia) ?> tickets · <?= $km_dia ?> km</span>
  </div>
  <div>
    <a href="javascript:history.back()">← Volver al planificador</a>
    <button onclick="window.print()">🖨️ Imprimir</button>
  </div>
</div>

<div class="page">

  <!-- ===== HEADER ===== -->
  <div class="head">
    <div class="top">
      <div>
        <div class="badge">HOJA DE TRABAJO · DÍA <?= $dia_idx+1 ?></div>
        <h1>Cuadrilla <?= $cuadrilla_idx+1 ?></h1>
        <div class="sub">Reportes de servicio · Municipio de Querétaro</div>
      </div>
      <div class="right">
        Generado: <?= $fecha_gen ?><br>
        Folio: CU<?= str_pad($cuadrilla_idx+1, 2, '0', STR_PAD_LEFT) ?>-D<?= str_pad($dia_idx+1, 2, '0', STR_PAD_LEFT) ?>-<?= date('ymd') ?>
      </div>
    </div>
  </div>

  <!-- ===== RESUMEN ===== -->
  <div class="resumen">
    <div class="box"><div class="l">Hora inicio</div><div class="v"><?= $fmtT($start_sec) ?></div></div>
    <div class="box"><div class="l">Fin estimado</div><div class="v"><?= $hora_fin ?></div></div>
    <div class="box"><div class="l">Duración</div><div class="v"><?= floor($duracion_min/60) ?>h <?= $duracion_min%60 ?>m</div></div>
    <div class="box"><div class="l">Tickets</div><div class="v"><?= count($ruta_dia) ?></div></div>
    <div class="box"><div class="l">Km ruta</div><div class="v"><?= $km_dia ?></div></div>
    <div class="box"><div class="l">Vencidos</div><div class="v"><?= $total_vencidos ?></div></div>
  </div>

  <?php if ($nombre_grupo || $nombre_servicio || $nombre_deleg): ?>
  <div class="filters">
    <b>Atender:</b>
    <?php
      $bits = [];
      if ($nombre_grupo)    $bits[] = $nombre_grupo;
      if ($nombre_servicio) $bits[] = $nombre_servicio;
      if ($nombre_deleg)    $bits[] = "delegación {$nombre_deleg}";
      echo htmlspecialchars(implode(' · ', $bits));
    ?>
  </div>
  <?php endif; ?>

  <!-- ===== LISTA DE TICKETS ===== -->
  <?php foreach ($ruta_dia as $i => $t): ?>
    <div class="ticket">
      <div class="num"><?= $i + 1 ?></div>

      <div>
        <div class="id"><span class="lbl">ID</span><br>#<?= $t['id'] ?></div>
        <?php if ($t['vencido']): ?><div style="margin-top:4px"><span class="vencido-tag">VENCIDO</span></div><?php endif; ?>
      </div>

      <div class="hora">
        <?= $t['eta_hhmm'] ?>
        <div class="salida">salida ~<?= $t['salida_hhmm'] ?></div>
      </div>

      <div class="info">
        <div class="svc"><?= htmlspecialchars($t['servicio'] ?? '—') ?></div>
        <div class="col"><?= htmlspecialchars($t['colonia'] ?? '—') ?> · <?= htmlspecialchars($t['delegacion'] ?? '—') ?></div>
        <?php
          $dir = trim((string)($t['direccion'] ?? ''));
          // Si la dirección es solo "lat,lng" la ocultamos (es ruido)
          $esCoord = preg_match('/^\s*-?\d{1,3}\.\d+\s*,\s*-?\d{1,3}\.\d+\s*$/', $dir);
        ?>
        <?php if ($dir !== '' && !$esCoord): ?>
          <div class="dir"><?= htmlspecialchars($dir) ?></div>
        <?php endif; ?>
        <div class="meta">
          <b>Días abierto:</b> <?= $t['dias_abierto'] ?> ·
          <b>Estado actual:</b> <?= htmlspecialchars($t['estado'] ?? '—') ?>
          <?php if (!empty($t['solicitante'])): ?>
            · <b>Solicitante:</b> <?= htmlspecialchars(mb_strimwidth($t['solicitante'], 0, 40, '…')) ?>
          <?php endif; ?>
        </div>
        <div class="gps">📍 <?= number_format($t['lat'],6) ?>, <?= number_format($t['lng'],6) ?>
          <?php if ($i > 0): ?> · <?= $t['dist_prev_km'] ?> km desde anterior<?php endif; ?>
        </div>
      </div>

      <div class="estado-box">
        <div class="check"><input type="checkbox" id="ok-<?= $i ?>"> <label for="ok-<?= $i ?>">Atendido</label></div>
        <div class="check"><input type="checkbox" id="nl-<?= $i ?>"> <label for="nl-<?= $i ?>">No localizado</label></div>
        <div class="check"><input type="checkbox" id="re-<?= $i ?>"> <label for="re-<?= $i ?>">Reprogramar</label></div>
        <div class="notas">
          <div class="lbl" style="font-size:7.5pt;color:#555;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Notas</div>
          <div class="line"></div>
          <div class="line"></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <!-- ===== FOOTER / FIRMAS ===== -->
  <div class="tally">
    Total atendidos hoy: <span class="num">_____</span> de <?= count($ruta_dia) ?>
    &nbsp;·&nbsp; Hora real de regreso: <span class="num">_____</span>
    &nbsp;·&nbsp; KM real: <span class="num">_____</span>
  </div>

  <div class="footer">
    <div class="field">
      <div class="lbl">Nombre operario / responsable</div>
      <div class="line"></div>
    </div>
    <div class="field">
      <div class="lbl">Firma</div>
      <div class="line"></div>
    </div>
    <div class="field">
      <div class="lbl">Supervisor / Validación</div>
      <div class="line"></div>
    </div>
  </div>

  <div style="margin-top:14px;font-size:8pt;color:#666;text-align:center;border-top:1px solid #ccc;padding-top:6px">
    Hoja generada por el sistema de planeación · Reportes de servicio · <?= date('Y') ?>
  </div>

</div>

</body>
</html>
