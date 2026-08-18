<?php
/**
 * Análisis detallado con drill-down y filtros.
 *
 * Filtros (vía GET):
 *   grupo=ID, delegacion=ID, canal=ID, estado=resuelto|abierto|vencido|sin_resolver
 *   from=YYYY-MM-DD, to=YYYY-MM-DD
 */
require __DIR__ . '/db.php';
$pdo = db();

// ============================================================
//  Catálogos para los filtros
// ============================================================
$cat_grupos      = $pdo->query("SELECT id,nombre FROM cat_grupo ORDER BY nombre")->fetchAll();
$cat_delegaciones= $pdo->query("SELECT id,nombre FROM cat_delegacion ORDER BY nombre")->fetchAll();
$cat_canales     = $pdo->query("SELECT id,nombre FROM cat_canal_origen ORDER BY nombre")->fetchAll();
$cat_servicios   = $pdo->query("SELECT id,nombre FROM cat_tipo_servicio ORDER BY nombre")->fetchAll();

// Rango disponible
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

// ============================================================
//  Construcción dinámica de WHERE
// ============================================================
require_once __DIR__ . '/_filtro_form.php';          // filtro por formulario de Zendesk
$where = ['1=1', zd_form_sql('t')]; $params = [];
if ($f_grupo)      { $where[] = "t.grupo_id = ?";         $params[] = $f_grupo; }
if ($f_delegacion) { $where[] = "t.delegacion_id = ?";    $params[] = $f_delegacion; }
if ($f_canal)      { $where[] = "t.canal_origen_id = ?";  $params[] = $f_canal; }
if ($f_servicio)   { $where[] = "t.tipo_servicio_id = ?"; $params[] = $f_servicio; }
if ($f_from)       { $where[] = "t.fecha_creacion >= ?"; $params[] = $f_from; }
if ($f_to)         { $where[] = "t.fecha_creacion <= ?"; $params[] = $f_to; }
if ($f_estado === 'resuelto')    { $where[] = "e.es_resuelto = 1"; }
if ($f_estado === 'sin_resolver'){ $where[] = "e.es_resuelto = 0"; }
if ($f_estado === 'abierto')     { $where[] = "e.nombre = 'Abierto'"; }
if ($f_estado === 'vencido')     { $where[] = "e.es_resuelto = 0 AND t.fecha_estimada < CURDATE()"; }
$W = implode(' AND ', $where);

// FROM con joins
$FROM = "FROM tickets t
         LEFT JOIN cat_estado       e  ON e.id  = t.estado_id
         LEFT JOIN cat_grupo        g  ON g.id  = t.grupo_id
         LEFT JOIN cat_delegacion   d  ON d.id  = t.delegacion_id
         LEFT JOIN cat_canal_origen co ON co.id = t.canal_origen_id
         LEFT JOIN cat_tipo_servicio ts ON ts.id = t.tipo_servicio_id";

function q(PDO $p, string $sql, array $params=[]): array {
    $s = $p->prepare($sql); $s->execute($params); return $s->fetchAll();
}
function qOne(PDO $p, string $sql, array $params=[]) {
    $s = $p->prepare($sql); $s->execute($params); return $s->fetch();
}

// ============================================================
//  KPIs filtrados
// ============================================================
$kpis = qOne($pdo, "
    SELECT
        COUNT(*) AS total,
        SUM(e.es_resuelto) AS resueltos,
        SUM(CASE WHEN e.es_resuelto = 0 THEN 1 ELSE 0 END) AS no_resueltos,
        SUM(CASE WHEN e.es_resuelto = 0 AND t.fecha_estimada < CURDATE() THEN 1 ELSE 0 END) AS vencidos,
        ROUND(AVG(CASE WHEN t.fecha_resolucion IS NOT NULL THEN DATEDIFF(t.fecha_resolucion,t.fecha_creacion) END),2) AS dias_promedio,
        ROUND(AVG(CASE WHEN e.es_resuelto = 0 THEN DATEDIFF(CURDATE(),t.fecha_creacion) END),1) AS edad_promedio,
        SUM(CASE WHEN t.fecha_resolucion IS NOT NULL AND t.fecha_estimada IS NOT NULL AND t.fecha_resolucion <= t.fecha_estimada THEN 1 ELSE 0 END) AS sla_ok,
        SUM(CASE WHEN t.fecha_resolucion IS NOT NULL AND t.fecha_estimada IS NOT NULL THEN 1 ELSE 0 END) AS sla_tot,
        COUNT(DISTINCT t.colonia) AS colonias,
        COUNT(DISTINCT t.solicitante_nombre_completo) AS solicitantes
    $FROM
    WHERE $W
", $params);
// Sin filas, los SUM() regresan NULL -> number_format(null) truena en PHP 8. Normaliza a 0.
foreach (['total','resueltos','no_resueltos','vencidos','sla_ok','sla_tot','colonias','solicitantes'] as $kc) {
    $kpis[$kc] = (int)($kpis[$kc] ?? 0);
}
$pct_res  = $kpis['total']>0 ? round($kpis['resueltos']/$kpis['total']*100,1) : 0;
$pct_venc = $kpis['no_resueltos']>0 ? round($kpis['vencidos']/$kpis['no_resueltos']*100,1) : 0;
$pct_sla  = $kpis['sla_tot']>0 ? round($kpis['sla_ok']/$kpis['sla_tot']*100,1) : 0;

// ============================================================
//  Análisis por CANAL (resolución y tiempo)
// ============================================================
$canal_perf = q($pdo, "
    SELECT co.nombre AS canal,
           COUNT(*) AS total,
           SUM(e.es_resuelto) AS resueltos,
           ROUND(SUM(e.es_resuelto)/COUNT(*)*100,1) AS pct_resolucion,
           ROUND(AVG(CASE WHEN t.fecha_resolucion IS NOT NULL THEN DATEDIFF(t.fecha_resolucion,t.fecha_creacion) END),1) AS dias_promedio,
           SUM(CASE WHEN e.es_resuelto = 0 AND t.fecha_estimada < CURDATE() THEN 1 ELSE 0 END) AS vencidos
    $FROM
    WHERE $W AND co.nombre IS NOT NULL
    GROUP BY co.nombre
    ORDER BY total DESC
", $params);

// ============================================================
//  Análisis por TIPO DE SERVICIO (drill-down principal)
// ============================================================
$servicio_perf = q($pdo, "
    SELECT ts.nombre AS servicio,
           COUNT(*) AS total,
           SUM(e.es_resuelto) AS resueltos,
           ROUND(SUM(e.es_resuelto)/COUNT(*)*100,1) AS pct_resolucion,
           ROUND(AVG(CASE WHEN t.fecha_resolucion IS NOT NULL THEN DATEDIFF(t.fecha_resolucion,t.fecha_creacion) END),1) AS dias_promedio,
           SUM(CASE WHEN e.es_resuelto = 0 AND t.fecha_estimada < CURDATE() THEN 1 ELSE 0 END) AS vencidos,
           ROUND(AVG(CASE WHEN e.es_resuelto = 0 THEN DATEDIFF(CURDATE(),t.fecha_creacion) END),1) AS edad_promedio
    $FROM
    WHERE $W AND ts.nombre IS NOT NULL
    GROUP BY ts.nombre
    ORDER BY total DESC
    LIMIT 20
", $params);

// ============================================================
//  Heatmap servicio × delegación (% resolución)
// ============================================================
$top_servicios_ids = q($pdo, "
    SELECT ts.id, ts.nombre, COUNT(*) AS c
    $FROM WHERE $W AND ts.id IS NOT NULL
    GROUP BY ts.id, ts.nombre
    ORDER BY c DESC LIMIT 8
", $params);
$top_delegs_ids = q($pdo, "
    SELECT d.id, d.nombre, COUNT(*) AS c
    $FROM WHERE $W AND d.id IS NOT NULL
    GROUP BY d.id, d.nombre
    ORDER BY c DESC LIMIT 7
", $params);
$heat_pct = [];
$heat_vol = [];
if ($top_servicios_ids && $top_delegs_ids) {
    $sids = array_column($top_servicios_ids,'id');
    $dids = array_column($top_delegs_ids,'id');
    $ph_s = implode(',', array_fill(0,count($sids),'?'));
    $ph_d = implode(',', array_fill(0,count($dids),'?'));
    $extra = array_merge($params, $sids, $dids);
    $rows = q($pdo, "
        SELECT ts.id AS sid, d.id AS did,
               COUNT(*) AS total,
               SUM(e.es_resuelto) AS resueltos,
               ROUND(SUM(e.es_resuelto)/COUNT(*)*100,1) AS pct
        $FROM
        WHERE $W AND ts.id IN ($ph_s) AND d.id IN ($ph_d)
        GROUP BY ts.id, d.id
    ", $extra);
    foreach ($rows as $r) {
        $heat_pct[$r['did']][$r['sid']] = (float)$r['pct'];
        $heat_vol[$r['did']][$r['sid']] = (int)$r['total'];
    }
}

// ============================================================
//  Antigüedad backlog (buckets) según filtros
// ============================================================
$ant_rows = q($pdo, "
    SELECT bin, COUNT(*) c FROM (
        SELECT CASE
            WHEN DATEDIFF(CURDATE(), t.fecha_creacion) <= 7  THEN '0-7d'
            WHEN DATEDIFF(CURDATE(), t.fecha_creacion) <= 14 THEN '8-14d'
            WHEN DATEDIFF(CURDATE(), t.fecha_creacion) <= 30 THEN '15-30d'
            WHEN DATEDIFF(CURDATE(), t.fecha_creacion) <= 60 THEN '31-60d'
            WHEN DATEDIFF(CURDATE(), t.fecha_creacion) <= 90 THEN '61-90d'
            ELSE '>90d'
        END AS bin
        $FROM
        WHERE $W AND e.es_resuelto = 0
    ) x GROUP BY bin
", $params);
$ant_order = ['0-7d','8-14d','15-30d','31-60d','61-90d','>90d'];
$ant_map = array_column($ant_rows,'c','bin');
$ant_chart = ['labels'=>$ant_order, 'values'=>array_map(fn($b)=>(int)($ant_map[$b]??0),$ant_order)];

// ============================================================
//  Tickets críticos (más viejos sin resolver)
// ============================================================
$criticos = q($pdo, "
    SELECT t.ticket_id, t.fecha_creacion, t.fecha_estimada,
           DATEDIFF(CURDATE(), t.fecha_creacion) AS dias_abierto,
           e.nombre AS estado, ts.nombre AS servicio, g.nombre AS grupo,
           d.nombre AS delegacion, co.nombre AS canal,
           t.colonia, t.solicitante_nombre_completo AS solicitante
    $FROM
    WHERE $W AND e.es_resuelto = 0
    ORDER BY t.fecha_creacion ASC
    LIMIT 25
", $params);

// ============================================================
//  Top solicitantes recurrentes
// ============================================================
$top_solicitantes = q($pdo, "
    SELECT t.solicitante_nombre_completo AS nombre,
           COUNT(*) AS total,
           SUM(e.es_resuelto) AS resueltos,
           ROUND(SUM(e.es_resuelto)/COUNT(*)*100,1) AS pct_resolucion
    $FROM
    WHERE $W AND t.solicitante_nombre_completo IS NOT NULL AND t.solicitante_nombre_completo <> ''
    GROUP BY t.solicitante_nombre_completo
    HAVING COUNT(*) >= 3
    ORDER BY COUNT(*) DESC
    LIMIT 15
", $params);

// ============================================================
//  Top colonias problemáticas (alto volumen + baja resolución)
// ============================================================
$top_colonias_prob = q($pdo, "
    SELECT t.colonia,
           COUNT(*) AS total,
           SUM(e.es_resuelto) AS resueltos,
           ROUND(SUM(e.es_resuelto)/COUNT(*)*100,1) AS pct_resolucion,
           SUM(CASE WHEN e.es_resuelto = 0 AND t.fecha_estimada < CURDATE() THEN 1 ELSE 0 END) AS vencidos
    $FROM
    WHERE $W AND t.colonia IS NOT NULL AND TRIM(t.colonia) <> ''
    GROUP BY t.colonia
    HAVING COUNT(*) >= 5
    ORDER BY (COUNT(*) - SUM(e.es_resuelto)) DESC
    LIMIT 15
", $params);

// ============================================================
//  Serie temporal (filtrada)
// ============================================================
$ts_data_creados = q($pdo, "
    SELECT t.fecha_creacion AS f, COUNT(*) c
    $FROM WHERE $W GROUP BY t.fecha_creacion ORDER BY t.fecha_creacion
", $params);
$ts_data_resueltos = q($pdo, "
    SELECT t.fecha_resolucion AS f, COUNT(*) c
    $FROM WHERE $W AND t.fecha_resolucion IS NOT NULL
    GROUP BY t.fecha_resolucion ORDER BY t.fecha_resolucion
", $params);
$fechas_set = [];
foreach ($ts_data_creados   as $r) $fechas_set[$r['f']] = true;
foreach ($ts_data_resueltos as $r) $fechas_set[$r['f']] = true;
ksort($fechas_set);
$fechas = array_keys($fechas_set);
$cm = array_column($ts_data_creados,'c','f');
$rm = array_column($ts_data_resueltos,'c','f');
$ts_data = [
    'fechas' => $fechas,
    'creados' => array_map(fn($f)=>(int)($cm[$f]??0),$fechas),
    'resueltos' => array_map(fn($f)=>(int)($rm[$f]??0),$fechas),
];

// Empaque de datos JS
$DATA = [
    'canal'      => $canal_perf,
    'antiguedad' => $ant_chart,
    'ts_data'    => $ts_data,
    'heat'       => [
        'servicios' => array_map(fn($s)=>['id'=>(int)$s['id'],'nombre'=>$s['nombre']],$top_servicios_ids),
        'delegaciones' => array_map(fn($d)=>['id'=>(int)$d['id'],'nombre'=>$d['nombre']],$top_delegs_ids),
        'pct' => $heat_pct,
        'vol' => $heat_vol,
    ],
];

// Helper para construir URLs preservando filtros
function url_con(array $cambios): string {
    $params = array_merge($_GET, $cambios);
    foreach ($params as $k=>$v) if ($v==='' || $v===null) unset($params[$k]);
    return '?' . http_build_query($params);
}

// Nombre del filtro activo (para encabezado)
$filtro_label = [];
if ($f_grupo) {
    $n = qOne($pdo,"SELECT nombre FROM cat_grupo WHERE id=?",[$f_grupo]);
    if ($n) $filtro_label[] = "Grupo: <b>{$n['nombre']}</b>";
}
if ($f_delegacion) {
    $n = qOne($pdo,"SELECT nombre FROM cat_delegacion WHERE id=?",[$f_delegacion]);
    if ($n) $filtro_label[] = "Delegación: <b>{$n['nombre']}</b>";
}
if ($f_canal) {
    $n = qOne($pdo,"SELECT nombre FROM cat_canal_origen WHERE id=?",[$f_canal]);
    if ($n) $filtro_label[] = "Canal: <b>{$n['nombre']}</b>";
}
if ($f_servicio) {
    $n = qOne($pdo,"SELECT nombre FROM cat_tipo_servicio WHERE id=?",[$f_servicio]);
    if ($n) $filtro_label[] = "Servicio: <b>{$n['nombre']}</b>";
}
if ($f_estado) {
    $labels = ['resuelto'=>'Resueltos','sin_resolver'=>'Sin resolver','abierto'=>'Abiertos','vencido'=>'Vencidos'];
    $filtro_label[] = "Estado: <b>".($labels[$f_estado]??$f_estado)."</b>";
}
?>
<?php
$ktTitle  = 'Análisis detallado · Reportes de Servicio';
$ktActive = 'zendesk';
require __DIR__ . '/../../views/layout/kt_top.php';
?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{--bg:#fafafa;--surface:#fff;--border:#ececec;--border-strong:#e0e0e0;
    --text:#1a1a1a;--text-muted:#6b7280;--text-faint:#9ca3af;
    --positive:#188a5b;--warning:#d99000;--negative:#ce3a2b;--neutral:#005ab2;--accent2:#2a9eda}
  *{box-sizing:border-box;-webkit-font-smoothing:antialiased}
  body{margin:0;font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:14px;line-height:1.5}
  .container{max-width:1400px;margin:0 auto;padding:32px 32px 80px}
  header{margin-bottom:24px;display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px}
  header h1{font-size:22px;font-weight:600;letter-spacing:-.02em;margin:0 0 6px}
  header .crumb{color:var(--text-muted);font-size:13px}
  header .crumb a{color:var(--primary);text-decoration:none}
  .nav{display:flex;gap:8px}
  .nav a{font-size:12px;padding:8px 14px;border:1px solid var(--border);border-radius:8px;color:var(--text);text-decoration:none;background:#fff;font-weight:500}
  .nav a.active{background:var(--text);color:#fff;border-color:var(--text)}
  .filter-bar{background:#fff;border:1px solid var(--border);border-radius:10px;padding:16px 18px;margin-bottom:24px}
  .filter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;align-items:end}
  @media(max-width:680px){.filter-grid{grid-template-columns:repeat(2,1fr)}}
  .filter-grid label{display:block;font-size:11px;font-weight:600;color:var(--text-faint);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
  .filter-grid select,.filter-grid input{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font:inherit;font-size:13px;background:#fff;color:var(--text)}
  .filter-grid button{padding:8px 16px;background:var(--primary);color:#fff;border:0;border-radius:6px;font:inherit;font-weight:500;cursor:pointer;font-size:13px}
  .filter-grid button:hover{filter:brightness(1.05)}
  .filter-bar .applied{margin-top:12px;font-size:12px;color:var(--text-muted);display:flex;flex-wrap:wrap;gap:8px;align-items:center}
  .chip{background:#eff6ff;color:#1d4ed8;padding:3px 9px;border-radius:999px;font-size:12px;font-weight:500;display:inline-flex;align-items:center;gap:6px}
  .chip a{color:#1d4ed8;text-decoration:none;font-weight:700;opacity:.6}
  .chip a:hover{opacity:1}
  .section{margin-top:32px}
  .section-title{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--text-faint);margin:0 0 14px}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:16px}
  .card-header{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:14px}
  .card-title{font-size:13px;font-weight:600;margin:0}
  .card-sub{font-size:12px;color:var(--text-faint)}
  .grid{display:grid;gap:16px}
  .kpi-grid{grid-template-columns:repeat(6,1fr)}
  .row-2{grid-template-columns:1fr 1fr}
  @media(max-width:1100px){.kpi-grid{grid-template-columns:repeat(3,1fr)}.row-2{grid-template-columns:1fr}}
  @media(max-width:680px){.kpi-grid{grid-template-columns:repeat(2,1fr)}}
  .kpi .label{font-size:11px;color:var(--text-muted);font-weight:500;margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em}
  .kpi .value{font-size:24px;font-weight:600;letter-spacing:-.02em;line-height:1}
  .kpi .meta{font-size:11px;color:var(--text-muted);margin-top:6px}
  .kpi.positive .value{color:var(--positive)}
  .kpi.negative .value{color:var(--negative)}
  .kpi.warning .value{color:var(--warning)}
  table{width:100%;border-collapse:collapse;font-size:13px}
  thead th{text-align:left;font-weight:500;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-faint);padding:9px 10px;border-bottom:1px solid var(--border-strong)}
  tbody td{padding:10px;border-bottom:1px solid var(--border);vertical-align:top}
  tbody tr:last-child td{border-bottom:none}
  tbody tr:hover{background:#fafbfc}
  td.num{text-align:right;font-variant-numeric:tabular-nums}
  td.code{font-family:ui-monospace,Menlo,monospace;font-size:12px;color:var(--text-muted)}
  .pill{display:inline-block;font-size:11px;padding:2px 8px;border-radius:999px;font-weight:500}
  .pill.positive{background:#ecfdf5;color:#047857}
  .pill.warning{background:#fffbeb;color:#b45309}
  .pill.negative{background:#fef2f2;color:#b91c1c}
  .pill.neutral{background:#f3f4f6;color:#374151}
  .progress{height:6px;background:#f3f4f6;border-radius:3px;overflow:hidden}
  .progress>span{display:block;height:100%}
  .chart-wrap{position:relative;height:280px}
  .chart-wrap.tall{height:380px}
  .heat{display:grid;gap:3px;font-size:11px}
  .heat .cell{padding:8px 4px;text-align:center;border-radius:4px;font-weight:600;cursor:default}
  .heat .lbl{color:var(--text-muted);font-size:11px;display:flex;align-items:center;padding:4px 6px}
  .heat .col{font-size:10px;line-height:1.2;color:var(--text-faint);text-align:center;padding:4px}
  .row-link{cursor:pointer}
  .row-link:hover{background:#eff6ff !important}
  a.action{font-size:11px;color:var(--primary);text-decoration:none;font-weight:500}
  .insight{background:#fffbeb;border:1px solid #fcd34d;border-left:3px solid var(--warning);border-radius:6px;padding:12px 16px;font-size:13px;line-height:1.55;margin-top:14px}
  .insight b{font-weight:600}
  footer{margin-top:48px;padding-top:24px;border-top:1px solid var(--border);font-size:12px;color:var(--text-faint)}
</style>

<div class="container">

<header>
  <div>
    <h1>Análisis detallado</h1>
    <div class="crumb"><a href="dashboard.php">Dashboard</a> → Análisis · explora con filtros y haz drill-down</div>
  </div>
  </header>

<!-- ============= FILTROS ============= -->
<form class="filter-bar" method="get">
  <div class="filter-grid">
    <div>
      <label>Grupo de servicio</label>
      <select name="grupo">
        <option value="">— Todos —</option>
        <?php foreach ($cat_grupos as $g): ?>
          <option value="<?= $g['id'] ?>" <?= $f_grupo==$g['id']?'selected':'' ?>><?= htmlspecialchars($g['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Tipo de servicio</label>
      <select name="tipo_servicio">
        <option value="">— Todos —</option>
        <?php foreach ($cat_servicios as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $f_servicio==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Delegación</label>
      <select name="delegacion">
        <option value="">— Todas —</option>
        <?php foreach ($cat_delegaciones as $d): ?>
          <option value="<?= $d['id'] ?>" <?= $f_delegacion==$d['id']?'selected':'' ?>><?= htmlspecialchars($d['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Canal origen</label>
      <select name="canal">
        <option value="">— Todos —</option>
        <?php foreach ($cat_canales as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $f_canal==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Estado</label>
      <select name="estado">
        <option value="">— Todos —</option>
        <option value="resuelto"     <?= $f_estado==='resuelto'?'selected':'' ?>>Sólo resueltos</option>
        <option value="sin_resolver" <?= $f_estado==='sin_resolver'?'selected':'' ?>>Sin resolver</option>
        <option value="abierto"      <?= $f_estado==='abierto'?'selected':'' ?>>Abiertos</option>
        <option value="vencido"      <?= $f_estado==='vencido'?'selected':'' ?>>Vencidos</option>
      </select>
    </div>
    <div>
      <label>Desde</label>
      <input type="date" name="from" value="<?= htmlspecialchars($f_from) ?>" min="<?= $rango['d_min'] ?>" max="<?= $rango['d_max'] ?>">
    </div>
    <div>
      <label>Hasta</label>
      <input type="date" name="to"   value="<?= htmlspecialchars($f_to) ?>"   min="<?= $rango['d_min'] ?>" max="<?= $rango['d_max'] ?>">
    </div>
    <div>
      <label>Formulario</label>
      <?= zd_form_select() ?>
    </div>
  </div>
  <div style="display:flex;gap:8px;margin-top:12px;justify-content:flex-end">
    <a href="analisis.php" style="padding:8px 14px;border:1px solid var(--border);border-radius:6px;color:var(--text);text-decoration:none;font-size:13px;background:#fff">Limpiar</a>
    <button type="submit">Aplicar filtros</button>
  </div>
  <?php if (!empty($filtro_label)): ?>
    <div class="applied">
      Filtros activos:
      <?php foreach ($filtro_label as $l): ?><span class="chip"><?= $l ?></span><?php endforeach; ?>
    </div>
  <?php endif; ?>
</form>

<!-- ============= KPIs FILTRADOS ============= -->
<section>
  <div class="section-title">Indicadores (sobre la selección)</div>
  <div class="grid kpi-grid">
    <div class="card kpi"><div class="label">Tickets</div><div class="value"><?= number_format($kpis['total']) ?></div></div>
    <div class="card kpi positive"><div class="label">% Resolución</div><div class="value"><?= $pct_res ?>%</div><div class="meta"><?= number_format($kpis['resueltos']) ?> resueltos</div></div>
    <div class="card kpi negative"><div class="label">Vencidos</div><div class="value"><?= number_format($kpis['vencidos']) ?></div><div class="meta"><?= $pct_venc ?>% de los sin resolver</div></div>
    <div class="card kpi"><div class="label">Tiempo medio</div><div class="value"><?= $kpis['dias_promedio'] ?? '—' ?><span style="font-size:14px;color:var(--text-muted)"> d</span></div></div>
    <div class="card kpi warning"><div class="label">Edad media</div><div class="value"><?= $kpis['edad_promedio'] ?? '—' ?><span style="font-size:14px;color:var(--text-muted)"> d</span></div><div class="meta">tickets sin resolver</div></div>
    <div class="card kpi positive"><div class="label">SLA cumplido</div><div class="value"><?= $pct_sla ?>%</div><div class="meta"><?= number_format($kpis['sla_ok']) ?> de <?= number_format($kpis['sla_tot']) ?></div></div>
  </div>
</section>

<!-- ============= TENDENCIA FILTRADA ============= -->
<?php if (count($ts_data['fechas']) > 1): ?>
<section class="section">
  <div class="section-title">Tendencia (con filtros aplicados)</div>
  <div class="card">
    <div class="card-header"><h3 class="card-title">Creados vs resueltos por día</h3><span class="card-sub"><?= count($ts_data['fechas']) ?> días</span></div>
    <div class="chart-wrap"><canvas id="chTime"></canvas></div>
  </div>
</section>
<?php endif; ?>

<!-- ============= ANÁLISIS POR CANAL ============= -->
<section class="section">
  <div class="section-title">Desempeño por canal de entrada</div>
  <div class="card" style="padding:8px 0">
    <table>
      <thead><tr>
        <th style="padding-left:20px">Canal</th>
        <th class="num">Volumen</th>
        <th class="num">Resueltos</th>
        <th class="num">% Resolución</th>
        <th class="num">Tiempo medio</th>
        <th class="num">Vencidos</th>
        <th style="width:180px">Resolución</th>
        <th style="padding-right:20px"></th>
      </tr></thead>
      <tbody>
        <?php foreach ($canal_perf as $c):
          $p = (float)$c['pct_resolucion'];
          $cls = $p>=70?'positive':($p>=40?'warning':'negative');
          $color = $p>=70?'#188a5b':($p>=40?'#d99000':'#ce3a2b');
          $canal_id = qOne($pdo,"SELECT id FROM cat_canal_origen WHERE nombre=?",[$c['canal']])['id'] ?? null;
        ?>
        <tr>
          <td style="padding-left:20px"><b><?= htmlspecialchars($c['canal']) ?></b></td>
          <td class="num"><?= number_format($c['total']) ?></td>
          <td class="num"><?= number_format($c['resueltos']) ?></td>
          <td class="num"><span class="pill <?= $cls ?>"><?= $p ?>%</span></td>
          <td class="num"><?= $c['dias_promedio']?$c['dias_promedio'].' d':'—' ?></td>
          <td class="num"><?= number_format($c['vencidos']) ?></td>
          <td><div class="progress"><span style="width:<?= $p ?>%;background:<?= $color ?>"></span></div></td>
          <td style="padding-right:20px">
            <?php if ($canal_id): ?><a class="action" href="<?= url_con(['canal'=>$canal_id]) ?>">Filtrar →</a><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (!empty($canal_perf)):
    $mejor = $canal_perf[0]; $peor = end($canal_perf); $canal_perf = array_values($canal_perf);
    // mejor por % resolución
    usort($canal_perf, fn($a,$b)=>($b['pct_resolucion']<=>$a['pct_resolucion']));
    $mejor = $canal_perf[0]; $peor = end($canal_perf);
  ?>
  <div class="insight">
    💡 Mejor canal por % resolución: <b><?= htmlspecialchars($mejor['canal']) ?></b> (<?= $mejor['pct_resolucion'] ?>%) ·
    Peor: <b><?= htmlspecialchars($peor['canal']) ?></b> (<?= $peor['pct_resolucion'] ?>%).
  </div>
  <?php endif; ?>
</section>

<!-- ============= ANÁLISIS POR TIPO DE SERVICIO ============= -->
<section class="section">
  <div class="section-title">Tipos de servicio (top 20)</div>
  <div class="card" style="padding:8px 0">
    <table>
      <thead><tr>
        <th style="padding-left:20px">Servicio</th>
        <th class="num">Vol.</th>
        <th class="num">Resueltos</th>
        <th class="num">% Resol.</th>
        <th class="num">T. medio</th>
        <th class="num">Vencidos</th>
        <th class="num">Edad media</th>
        <th style="width:140px">Resolución</th>
      </tr></thead>
      <tbody>
        <?php foreach ($servicio_perf as $s):
          $p = (float)$s['pct_resolucion'];
          $cls = $p>=70?'positive':($p>=40?'warning':'negative');
          $color = $p>=70?'#188a5b':($p>=40?'#d99000':'#ce3a2b');
        ?>
        <tr>
          <td style="padding-left:20px"><?= htmlspecialchars($s['servicio']) ?></td>
          <td class="num"><?= number_format($s['total']) ?></td>
          <td class="num"><?= number_format($s['resueltos']) ?></td>
          <td class="num"><span class="pill <?= $cls ?>"><?= $p ?>%</span></td>
          <td class="num"><?= $s['dias_promedio']?$s['dias_promedio'].' d':'—' ?></td>
          <td class="num"><?php if ($s['vencidos']>0): ?><span class="pill negative"><?= number_format($s['vencidos']) ?></span><?php else: ?>0<?php endif; ?></td>
          <td class="num"><?= $s['edad_promedio']?$s['edad_promedio'].' d':'—' ?></td>
          <td style="padding-right:20px"><div class="progress"><span style="width:<?= $p ?>%;background:<?= $color ?>"></span></div></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- ============= HEATMAP SERVICIO × DELEGACIÓN ============= -->
<?php if (!empty($heat_pct)): ?>
<section class="section">
  <div class="section-title">Heatmap: % de resolución por servicio × delegación</div>
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">Verde = se resuelve · Rojo = no se resuelve</h3>
      <span class="card-sub">Top 8 servicios × top 7 delegaciones</span>
    </div>
    <div style="overflow-x:auto">
      <div class="heat" style="grid-template-columns:200px repeat(<?= count($top_servicios_ids) ?>,minmax(90px,1fr));margin-top:8px">
        <div></div>
        <?php foreach ($top_servicios_ids as $s): ?>
          <div class="col"><?= htmlspecialchars(mb_strimwidth($s['nombre'],0,42,'…')) ?></div>
        <?php endforeach; ?>
        <?php foreach ($top_delegs_ids as $d): ?>
          <div class="lbl"><?= htmlspecialchars($d['nombre']) ?></div>
          <?php foreach ($top_servicios_ids as $s):
            $pct = $heat_pct[$d['id']][$s['id']] ?? null;
            $vol = $heat_vol[$d['id']][$s['id']] ?? 0;
            if ($pct === null) {
              $bg = '#f9fafb'; $color = '#9ca3af'; $txt = '—';
            } else {
              // verde a rojo
              $r = $pct >= 50 ? round(220-($pct-50)*4) : 220;
              $g_ = $pct >= 50 ? 200 : round(150+($pct/50)*70);
              $b = $pct >= 50 ? 120 : 80;
              if ($pct >= 70) { $bg='#188a5b'; $color='#fff'; }
              elseif ($pct >= 40) { $bg='#fbbf24'; $color='#1a1a1a'; }
              else { $bg='#ce3a2b'; $color='#fff'; }
              $txt = $pct.'%';
            }
          ?>
            <div class="cell" style="background:<?= $bg ?>;color:<?= $color ?>" title="<?= $vol ?> tickets"><?= $txt ?><div style="font-weight:400;font-size:9px;opacity:.8"><?= $vol ?></div></div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ============= ANTIGÜEDAD + TICKETS CRÍTICOS ============= -->
<section class="section">
  <div class="section-title">Antigüedad del backlog</div>
  <div class="grid row-2">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Distribución por antigüedad</h3><span class="card-sub"><?= number_format($kpis['no_resueltos']) ?> sin resolver</span></div>
      <div class="chart-wrap"><canvas id="chAnt"></canvas></div>
    </div>
    <div class="card">
      <div class="card-header"><h3 class="card-title">Tickets más viejos sin resolver (top 25)</h3><span class="card-sub">Críticos para intervenir</span></div>
      <div style="max-height:380px;overflow:auto;margin:0 -8px">
        <table>
          <thead style="position:sticky;top:0;background:#fff;z-index:1"><tr>
            <th>ID</th><th class="num">Días</th><th>Estado</th><th>Servicio</th><th>Delegación</th>
          </tr></thead>
          <tbody>
          <?php foreach ($criticos as $r): ?>
            <tr>
              <td class="code">#<?= $r['ticket_id'] ?></td>
              <td class="num"><span class="pill negative"><?= $r['dias_abierto'] ?> d</span></td>
              <td><?= htmlspecialchars($r['estado'] ?? '—') ?></td>
              <td><?= htmlspecialchars(mb_strimwidth($r['servicio']??'—',0,30,'…')) ?></td>
              <td><?= htmlspecialchars(mb_strimwidth($r['delegacion']??'—',0,18,'…')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<!-- ============= TOP SOLICITANTES + COLONIAS PROBLEMÁTICAS ============= -->
<section class="section">
  <div class="section-title">Concentraciones</div>
  <div class="grid row-2">
    <div class="card" style="padding:8px 0">
      <div style="padding:0 20px 12px"><b style="font-size:13px">Solicitantes recurrentes (≥3 tickets)</b><br><span style="font-size:12px;color:var(--text-faint)">Quién reporta más en este filtro</span></div>
      <table>
        <thead><tr>
          <th style="padding-left:20px">Solicitante</th>
          <th class="num">Tickets</th>
          <th class="num">% Resuelto</th>
          <th style="padding-right:20px"></th>
        </tr></thead>
        <tbody>
          <?php if (empty($top_solicitantes)): ?>
            <tr><td colspan="4" style="padding:20px;text-align:center;color:var(--text-muted)">Sin solicitantes recurrentes en esta selección.</td></tr>
          <?php else: foreach ($top_solicitantes as $s):
            $p = (float)$s['pct_resolucion'];
            $cls = $p>=50?'positive':($p>=25?'warning':'negative');
          ?>
            <tr>
              <td style="padding-left:20px"><?= htmlspecialchars(mb_strimwidth($s['nombre'],0,40,'…')) ?></td>
              <td class="num"><?= $s['total'] ?></td>
              <td class="num"><span class="pill <?= $cls ?>"><?= $p ?>%</span></td>
              <td style="padding-right:20px"></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card" style="padding:8px 0">
      <div style="padding:0 20px 12px"><b style="font-size:13px">Colonias más problemáticas</b><br><span style="font-size:12px;color:var(--text-faint)">Más tickets sin resolver (≥5 tickets)</span></div>
      <table>
        <thead><tr>
          <th style="padding-left:20px">Colonia</th>
          <th class="num">Vol.</th>
          <th class="num">% Resol.</th>
          <th class="num">Vencidos</th>
          <th style="padding-right:20px"></th>
        </tr></thead>
        <tbody>
          <?php if (empty($top_colonias_prob)): ?>
            <tr><td colspan="5" style="padding:20px;text-align:center;color:var(--text-muted)">Sin datos en esta selección.</td></tr>
          <?php else: foreach ($top_colonias_prob as $c):
            $p = (float)$c['pct_resolucion'];
            $cls = $p>=50?'positive':($p>=25?'warning':'negative');
          ?>
            <tr>
              <td style="padding-left:20px"><?= htmlspecialchars(mb_strimwidth($c['colonia'],0,38,'…')) ?></td>
              <td class="num"><?= $c['total'] ?></td>
              <td class="num"><span class="pill <?= $cls ?>"><?= $p ?>%</span></td>
              <td class="num"><?php if ($c['vencidos']>0): ?><span class="pill negative"><?= $c['vencidos'] ?></span><?php else: ?>0<?php endif; ?></td>
              <td style="padding-right:20px"></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<footer>Datos en vivo · <?= number_format($kpis['total']) ?> tickets en esta selección · <?= date('d M Y H:i') ?></footer>
</div>

<script>
const DATA = <?= json_encode($DATA, JSON_UNESCAPED_UNICODE) ?>;

Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
Chart.defaults.font.size = 11;
Chart.defaults.color = '#6b7280';
Chart.defaults.borderColor = '#ececec';
Chart.defaults.plugins.legend.labels.boxWidth = 8;
Chart.defaults.plugins.legend.labels.boxHeight = 8;
Chart.defaults.plugins.legend.labels.padding = 14;
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.pointStyle = 'circle';
Chart.defaults.plugins.tooltip.backgroundColor='#1a1a1a';
Chart.defaults.plugins.tooltip.titleColor='#fff';
Chart.defaults.plugins.tooltip.bodyColor='#fff';
Chart.defaults.plugins.tooltip.padding=10;
Chart.defaults.plugins.tooltip.cornerRadius=6;

const baseGrid = {grid:{color:'#f3f4f6',drawTicks:false},border:{display:false},ticks:{padding:8}};
const noGrid = {grid:{display:false},border:{display:false},ticks:{padding:8}};

// Tendencia
const elT = document.getElementById('chTime');
if (elT) {
  new Chart(elT,{type:'line',
    data:{labels:DATA.ts_data.fechas,datasets:[
      {label:'Creados',data:DATA.ts_data.creados,borderColor:'#254185',backgroundColor:'rgba(37,65,133,.08)',tension:.35,fill:true,borderWidth:2,pointRadius:0,pointHoverRadius:5},
      {label:'Resueltos',data:DATA.ts_data.resueltos,borderColor:'#188a5b',backgroundColor:'rgba(5,150,105,.06)',tension:.35,fill:true,borderWidth:2,pointRadius:0,pointHoverRadius:5}
    ]},
    options:{plugins:{legend:{position:'top',align:'end'}},
      scales:{x:{...noGrid,ticks:{...noGrid.ticks,maxRotation:0,autoSkip:true,maxTicksLimit:12,font:{size:10}}},y:baseGrid}}});
}

// Antigüedad
new Chart(document.getElementById('chAnt'),{type:'bar',
  data:{labels:DATA.antiguedad.labels,datasets:[{data:DATA.antiguedad.values,
    backgroundColor:['#188a5b','#84cc16','#d99000','#f97316','#ce3a2b','#7f1d1d'],borderRadius:4,barThickness:36}]},
  options:{plugins:{legend:{display:false}},scales:{x:noGrid,y:baseGrid}}});
</script>
<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
