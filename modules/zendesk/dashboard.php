<?php
/**
 * Dashboard dinámico — consulta MySQL y renderiza KPIs en vivo.
 * Cada vez que abres este archivo, hace las queries contra la BD.
 */
require __DIR__ . '/db.php';
$pdo = db();

// ============================================================
//  Helpers
// ============================================================
function q(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
function qOne(PDO $pdo, string $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

// Si no hay datos cargados, mostrar pantalla de bienvenida
$total_rows = (int)$pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
if ($total_rows === 0) {
    ?>
    <!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
    <title>Dashboard · sin datos</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>body{font-family:'Inter',system-ui;background:#fafafa;color:#1a1a1a;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}.box{background:#fff;border:1px solid #ececec;border-radius:12px;padding:40px;text-align:center;max-width:480px}h1{margin:0 0 8px;font-size:22px}p{color:#6b7280;line-height:1.6;margin:0 0 20px}a{display:inline-block;background:#254185;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:500}</style>
    </head><body><div class="box"><h1>Aún no hay tickets cargados</h1><p>La base de datos está conectada pero la tabla <code>tickets</code> está vacía. Descarga los tickets desde Zendesk.</p><a href="descargar_zendesk.php">Ir a descargar de Zendesk →</a></div></body></html>
    <?php
    exit;
}

// ============================================================
//  Filtro de fecha (por defecto: últimos 30 días)
// ============================================================
$hoy_     = date('Y-m-d');
$def_from = date('Y-m-d', strtotime('-30 days'));
$from = $_GET['from'] ?? $def_from;
$to   = $_GET['to']   ?? $hoy_;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$from)) $from = $def_from;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$to))   $to   = $hoy_;
require_once __DIR__ . '/_filtro_form.php';             // filtro por formulario de Zendesk
$FORM = zd_form_sql();                                  // p. ej. "ticket_form_id = 30573528367899"
$WF = "fecha_creacion BETWEEN '$from' AND '$to' AND $FORM";     // condición reutilizable (creación)
$WR = "fecha_resolucion BETWEEN '$from' AND '$to' AND $FORM";   // por resolución
$dias_periodo = max(1, (int)((strtotime($to) - strtotime($from)) / 86400) + 1);

// ============================================================
//  KPIs globales (acotados al periodo)
// ============================================================
$kpi = qOne($pdo, "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(es_resuelto),0) AS resueltos,
        COALESCE(ROUND(SUM(es_resuelto)/NULLIF(COUNT(*),0)*100,2),0) AS pct_resolucion,
        ROUND(AVG(dias_resolucion),2) AS dias_promedio_resolucion
      FROM v_tickets WHERE $WF");
$rango = ['d_min' => $from, 'd_max' => $to];

$abiertos       = (int)$pdo->query("SELECT COUNT(*) FROM v_tickets WHERE estado='Abierto' AND $WF")->fetchColumn();
$solicitantes   = (int)$pdo->query("SELECT COUNT(DISTINCT solicitante_nombre_completo) FROM tickets WHERE solicitante_nombre_completo IS NOT NULL AND solicitante_nombre_completo<>'' AND $WF")->fetchColumn();
$colonias_uniq  = (int)$pdo->query("SELECT COUNT(DISTINCT colonia) FROM tickets WHERE colonia IS NOT NULL AND TRIM(colonia)<>'' AND $WF")->fetchColumn();
$tipos_serv     = (int)$pdo->query("SELECT COUNT(DISTINCT tipo_servicio_id) FROM tickets WHERE tipo_servicio_id IS NOT NULL AND $WF")->fetchColumn();
$promedio_diario = $dias_periodo > 0 ? round((int)($kpi['total'] ?? 0)/$dias_periodo, 1) : 0;
$dia_pico        = (int)$pdo->query("SELECT MAX(c) FROM (SELECT COUNT(*) c FROM tickets WHERE $WF GROUP BY fecha_creacion) t")->fetchColumn();
$antiguedad_prom = $pdo->query("SELECT ROUND(AVG(DATEDIFF(CURDATE(), fecha_creacion)),1) FROM v_tickets WHERE es_resuelto=0 AND $WF")->fetchColumn();

// Tickets vencidos (en el periodo)
$vencidos_total = (int)$pdo->query("SELECT COUNT(*) FROM v_tickets WHERE vencido=1 AND $WF")->fetchColumn();
$no_resueltos   = (int)$pdo->query("SELECT COUNT(*) FROM v_tickets WHERE es_resuelto=0 AND $WF")->fetchColumn();
$pct_vencidos   = $no_resueltos > 0 ? round($vencidos_total/$no_resueltos*100, 1) : 0;

// SLA
$sla = qOne($pdo, "SELECT COALESCE(SUM(cumplio_sla),0) AS cumplio, COALESCE(SUM(cumplio_sla IS NOT NULL),0) AS total_evaluable FROM v_tickets WHERE $WF");
$pct_sla = ($sla['total_evaluable'] > 0) ? round($sla['cumplio']/$sla['total_evaluable']*100, 1) : 0;

// Tiempos resolución
$mediana = (float)$pdo->query("
  SELECT AVG(dias_resolucion) FROM (
    SELECT dias_resolucion, @rn := @rn + 1 AS rn
    FROM v_tickets, (SELECT @rn := 0) r
    WHERE dias_resolucion IS NOT NULL AND $WF
    ORDER BY dias_resolucion
  ) t, (SELECT COUNT(*) AS n FROM v_tickets WHERE dias_resolucion IS NOT NULL AND $WF) c
  WHERE rn IN (FLOOR((c.n+1)/2), FLOOR((c.n+2)/2))
")->fetchColumn();

$p90 = (float)$pdo->query("
  SELECT dias_resolucion FROM (
    SELECT dias_resolucion, @r := @r + 1 AS rn
    FROM v_tickets, (SELECT @r := 0) r
    WHERE dias_resolucion IS NOT NULL AND $WF
    ORDER BY dias_resolucion
  ) t, (SELECT COUNT(*) AS n FROM v_tickets WHERE dias_resolucion IS NOT NULL AND $WF) c
  WHERE rn = FLOOR(c.n * 0.9)
  LIMIT 1
")->fetchColumn();

// ============================================================
//  Distribuciones
// ============================================================
$status_rows = q($pdo, "SELECT estado, COUNT(*) c FROM v_tickets WHERE estado IS NOT NULL AND $WF GROUP BY estado ORDER BY c DESC");
$status = array_column($status_rows, 'c', 'estado');

$canal_rows = q($pdo, "SELECT canal_origen, COUNT(*) c FROM v_tickets WHERE canal_origen IS NOT NULL AND $WF GROUP BY canal_origen ORDER BY c DESC");
$canal = array_column($canal_rows, 'c', 'canal_origen');

$prio_rows = q($pdo, "SELECT prioridad, COUNT(*) c FROM v_tickets WHERE prioridad IS NOT NULL AND $WF GROUP BY prioridad ORDER BY c DESC");
$prioridad = array_column($prio_rows, 'c', 'prioridad');

// ============================================================
//  Serie temporal (en el periodo)
// ============================================================
$ts_creados = q($pdo, "SELECT fecha_creacion AS f, COUNT(*) c FROM tickets WHERE $WF GROUP BY fecha_creacion ORDER BY fecha_creacion");
$ts_resueltos = q($pdo, "SELECT fecha_resolucion AS f, COUNT(*) c FROM tickets WHERE fecha_resolucion IS NOT NULL AND $WR GROUP BY fecha_resolucion ORDER BY fecha_resolucion");

$fechas_set = [];
foreach ($ts_creados as $r) $fechas_set[$r['f']] = true;
foreach ($ts_resueltos as $r) $fechas_set[$r['f']] = true;
ksort($fechas_set);
$fechas = array_keys($fechas_set);
$creados_map   = array_column($ts_creados,   'c', 'f');
$resueltos_map = array_column($ts_resueltos, 'c', 'f');
$ts_data = [
    'fechas'    => $fechas,
    'creados'   => array_map(fn($f) => (int)($creados_map[$f]   ?? 0), $fechas),
    'resueltos' => array_map(fn($f) => (int)($resueltos_map[$f] ?? 0), $fechas),
];

// ============================================================
//  Día de la semana (MySQL DAYOFWEEK: 1=Dom, 2=Lun, ..., 7=Sab)
// ============================================================
$dow_rows = q($pdo, "SELECT DAYOFWEEK(fecha_creacion) d, COUNT(*) c FROM tickets WHERE $WF GROUP BY DAYOFWEEK(fecha_creacion)");
$dow_map = array_column($dow_rows, 'c', 'd');
$dow_chart = [
    'labels' => ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'],
    'values' => [
        (int)($dow_map[2]??0), (int)($dow_map[3]??0), (int)($dow_map[4]??0),
        (int)($dow_map[5]??0), (int)($dow_map[6]??0), (int)($dow_map[7]??0), (int)($dow_map[1]??0)
    ],
];

// ============================================================
//  Histograma tiempo de resolución
// ============================================================
$hist_rows = q($pdo, "
  SELECT bin, COUNT(*) c FROM (
    SELECT CASE
      WHEN dias_resolucion <= 1 THEN '0-1d'
      WHEN dias_resolucion <= 3 THEN '2-3d'
      WHEN dias_resolucion <= 5 THEN '4-5d'
      WHEN dias_resolucion <= 7 THEN '6-7d'
      WHEN dias_resolucion <= 10 THEN '8-10d'
      WHEN dias_resolucion <= 15 THEN '11-15d'
      WHEN dias_resolucion <= 30 THEN '16-30d'
      ELSE '>30d'
    END AS bin
    FROM v_tickets WHERE dias_resolucion IS NOT NULL AND $WF
  ) t GROUP BY bin
");
$bin_order = ['0-1d','2-3d','4-5d','6-7d','8-10d','11-15d','16-30d','>30d'];
$hist_map = array_column($hist_rows, 'c', 'bin');
$hist_data = [
    'labels' => $bin_order,
    'values' => array_map(fn($b) => (int)($hist_map[$b]??0), $bin_order),
];

// ============================================================
//  KPIs por delegación / grupo
// ============================================================
$deleg_rows = q($pdo, "SELECT delegacion, COUNT(*) total, COALESCE(SUM(es_resuelto),0) resueltos,
                              COALESCE(ROUND(SUM(es_resuelto)/NULLIF(COUNT(*),0)*100,2),0) pct_resolucion,
                              COALESCE(SUM(vencido),0) vencidos, ROUND(AVG(dias_resolucion),2) dias_promedio
                         FROM v_tickets WHERE delegacion IS NOT NULL AND $WF
                        GROUP BY delegacion HAVING total >= 10 ORDER BY total DESC");
$deleg_data = [
    'delegaciones' => array_column($deleg_rows, 'delegacion'),
    'total'        => array_map('intval',   array_column($deleg_rows, 'total')),
    'resueltos'    => array_map('intval',   array_column($deleg_rows, 'resueltos')),
    'pct'          => array_map('floatval', array_column($deleg_rows, 'pct_resolucion')),
];

$grupo_rows = q($pdo, "SELECT grupo, COUNT(*) total, COALESCE(SUM(es_resuelto),0) resueltos,
                              COALESCE(ROUND(SUM(es_resuelto)/NULLIF(COUNT(*),0)*100,2),0) pct_resolucion,
                              COALESCE(SUM(vencido),0) vencidos
                         FROM v_tickets WHERE grupo IS NOT NULL AND $WF
                        GROUP BY grupo ORDER BY total DESC LIMIT 10");
$grp_data = [
    'grupos'    => array_column($grupo_rows, 'grupo'),
    'total'     => array_map('intval',   array_column($grupo_rows, 'total')),
    'resueltos' => array_map('intval',   array_column($grupo_rows, 'resueltos')),
    'pct'       => array_map('floatval', array_column($grupo_rows, 'pct_resolucion')),
];

// ============================================================
//  Heatmap delegación × grupo (top 7 × top 8)
// ============================================================
$top_delegs  = array_slice(array_column($deleg_rows, 'delegacion'), 0, 7);
$top_grupos  = array_slice(array_column($grupo_rows, 'grupo'), 0, 8);

if (!empty($top_delegs) && !empty($top_grupos)) {
    $ph_d = implode(',', array_fill(0, count($top_delegs), '?'));
    $ph_g = implode(',', array_fill(0, count($top_grupos), '?'));
    $heat_rows = q($pdo,
        "SELECT delegacion, grupo, COUNT(*) c
         FROM v_tickets
         WHERE delegacion IN ($ph_d) AND grupo IN ($ph_g) AND $WF
         GROUP BY delegacion, grupo",
        array_merge($top_delegs, $top_grupos)
    );
    $heat_map = [];
    foreach ($heat_rows as $r) $heat_map[$r['delegacion']][$r['grupo']] = (int)$r['c'];
    $matriz = [];
    foreach ($top_delegs as $d) {
        $fila = [];
        foreach ($top_grupos as $g) $fila[] = (int)($heat_map[$d][$g] ?? 0);
        $matriz[] = $fila;
    }
    $heat_data = ['delegaciones'=>$top_delegs,'grupos'=>$top_grupos,'matriz'=>$matriz];
} else {
    $heat_data = ['delegaciones'=>[],'grupos'=>[],'matriz'=>[]];
}

// ============================================================
//  Top servicios y colonias
// ============================================================
$serv_rows = q($pdo, "SELECT tipo_servicio, COUNT(*) c FROM v_tickets
                      WHERE tipo_servicio IS NOT NULL AND $WF
                      GROUP BY tipo_servicio ORDER BY c DESC LIMIT 15");
$servicios = array_column($serv_rows, 'c', 'tipo_servicio');

$col_rows = q($pdo, "SELECT colonia, COUNT(*) c FROM tickets
                     WHERE colonia IS NOT NULL AND TRIM(colonia) <> '' AND $WF
                     GROUP BY colonia ORDER BY c DESC LIMIT 15");
$colonias = array_column($col_rows, 'c', 'colonia');

// ============================================================
//  Antigüedad backlog y vencidos por grupo
// ============================================================
$ant_rows = q($pdo, "
  SELECT bin, COUNT(*) c FROM (
    SELECT CASE
      WHEN DATEDIFF(CURDATE(), fecha_creacion) <= 3  THEN '0-3d'
      WHEN DATEDIFF(CURDATE(), fecha_creacion) <= 7  THEN '4-7d'
      WHEN DATEDIFF(CURDATE(), fecha_creacion) <= 14 THEN '8-14d'
      WHEN DATEDIFF(CURDATE(), fecha_creacion) <= 21 THEN '15-21d'
      WHEN DATEDIFF(CURDATE(), fecha_creacion) <= 30 THEN '22-30d'
      ELSE '>30d'
    END AS bin
    FROM v_tickets WHERE es_resuelto = 0 AND $WF
  ) t GROUP BY bin
");
$ant_order = ['0-3d','4-7d','8-14d','15-21d','22-30d','>30d'];
$ant_map = array_column($ant_rows, 'c', 'bin');
$antiguedad_chart = [
    'labels' => $ant_order,
    'values' => array_map(fn($b)=>(int)($ant_map[$b]??0), $ant_order),
];

$venc_grp_rows = q($pdo, "SELECT grupo, COUNT(*) c FROM v_tickets
                          WHERE vencido = 1 AND grupo IS NOT NULL AND $WF
                          GROUP BY grupo ORDER BY c DESC LIMIT 10");
$venc_grp = array_column($venc_grp_rows, 'c', 'grupo');

// ============================================================
//  Tablas detalle
// ============================================================
$tabla_deleg = $deleg_rows;
$tabla_grupo = $grupo_rows;

// Última carga
$ult_carga = qOne($pdo, "SELECT MAX(fecha_inicio) AS fecha FROM cargas WHERE estado='exitoso'");

// ============================================================
//  Empaquetar para JS
// ============================================================
$DATA = [
    'status'       => $status,
    'canal'        => $canal,
    'priority'     => $prioridad,
    'ts_data'      => $ts_data,
    'dow_chart'    => $dow_chart,
    'hist_data'    => $hist_data,
    'deleg_data'   => $deleg_data,
    'grp_data'     => $grp_data,
    'heat_data'    => $heat_data,
    'servicios'    => $servicios,
    'colonias'     => $colonias,
    'antiguedad_chart' => $antiguedad_chart,
    'venc_grp'     => $venc_grp,
];

// Formato fechas español
$meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$fmt = function($s) use ($meses) {
    if (!$s) return '—';
    [$y,$m,$d] = explode('-', $s);
    return (int)$d.' '.$meses[(int)$m-1].' '.$y;
};
$periodo_str = $fmt($rango['d_min']) . ' — ' . $fmt($rango['d_max']);
$num_deleg = (int)$pdo->query("SELECT COUNT(DISTINCT delegacion_id) FROM tickets WHERE delegacion_id IS NOT NULL AND $WF")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Dashboard Reportes de Servicio · Querétaro</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --bg:#fafafa;--surface:#fff;--border:#ececec;--border-strong:#e0e0e0;
    --text:#1a1a1a;--text-muted:#6b7280;--text-faint:#9ca3af;
    --accent:#254185;--positive:#188a5b;--warning:#d99000;--negative:#ce3a2b;--neutral:#005ab2;
  }
  *{box-sizing:border-box;-webkit-font-smoothing:antialiased}
  body{margin:0;font-family:'Inter',-apple-system,BlinkMacSystemFont,system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:14px;line-height:1.5}
  .container{max-width:1400px;margin:0 auto;padding:48px 32px 80px}
  header{margin-bottom:48px;display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px}
  header h1{font-size:24px;font-weight:600;letter-spacing:-.02em;margin:0 0 6px}
  header .meta{color:var(--text-muted);font-size:13px}
  header .meta b{color:var(--text);font-weight:500}
  .nav{display:flex;gap:8px}
  .nav a{font-size:12px;padding:8px 14px;border:1px solid var(--border);border-radius:8px;color:var(--text);text-decoration:none;background:#fff;font-weight:500}
  .nav a:hover{background:#f9fafb}
  .nav a.primary{background:var(--accent);color:#fff;border-color:var(--accent)}
  .section{margin-top:56px}.section:first-child{margin-top:0}
  .section-title{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--text-faint);margin:0 0 16px}
  .grid{display:grid;gap:16px}
  .kpi-grid{grid-template-columns:repeat(4,1fr)}.row-2{grid-template-columns:1fr 1fr}.row-3{grid-template-columns:repeat(3,1fr)}
  @media(max-width:1100px){.kpi-grid{grid-template-columns:repeat(2,1fr)}.row-3{grid-template-columns:1fr 1fr}}
  @media(max-width:720px){.kpi-grid,.row-2,.row-3{grid-template-columns:1fr}.container{padding:32px 20px}}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:20px}
  .card-header{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:16px}
  .card-title{font-size:13px;font-weight:600;margin:0}
  .card-sub{font-size:12px;color:var(--text-faint)}
  .kpi .label{font-size:12px;color:var(--text-muted);font-weight:500;margin-bottom:8px}
  .kpi .value{font-size:30px;font-weight:600;letter-spacing:-.02em;line-height:1;color:var(--text)}
  .kpi .value .unit{font-size:14px;font-weight:500;color:var(--text-muted);margin-left:4px}
  .kpi .meta{font-size:12px;color:var(--text-muted);margin-top:10px;line-height:1.4}
  .kpi .meta b{color:var(--text);font-weight:500}
  .kpi.positive .value{color:var(--positive)}
  .kpi.warning .value{color:var(--warning)}
  .kpi.negative .value{color:var(--negative)}
  .insight{background:var(--surface);border:1px solid var(--border);border-left:3px solid var(--accent);border-radius:6px;padding:14px 18px;font-size:13px;line-height:1.6;margin-top:16px}
  .insight.warning{border-left-color:var(--warning)}.insight.negative{border-left-color:var(--negative)}.insight.positive{border-left-color:var(--positive)}
  .insight b{font-weight:600}
  .chart-wrap{position:relative;height:280px}.chart-wrap.tall{height:360px}.chart-wrap.xtall{height:440px}
  table{width:100%;border-collapse:collapse;font-size:13px}
  thead th{text-align:left;font-weight:500;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-faint);padding:10px 12px;border-bottom:1px solid var(--border-strong)}
  tbody td{padding:12px;border-bottom:1px solid var(--border)}
  tbody tr:last-child td{border-bottom:none}
  td.num{text-align:right;font-variant-numeric:tabular-nums}
  .progress{height:4px;background:#f3f4f6;border-radius:2px;overflow:hidden;margin-top:2px}
  .progress>span{display:block;height:100%;border-radius:2px;background:var(--accent)}
  .heatmap{display:grid;gap:3px;font-size:11px}
  .heatmap .cell{padding:10px 6px;text-align:center;border-radius:4px;font-weight:500}
  .heatmap .label{color:var(--text-muted);font-size:12px;display:flex;align-items:center;padding:6px 0}
  .heatmap .label.col{justify-content:center;text-align:center;font-size:10px;line-height:1.3;color:var(--text-faint);padding:6px 4px}
  .pill{display:inline-block;font-size:11px;padding:2px 8px;border-radius:999px;font-weight:500}
  .pill.positive{background:#ecfdf5;color:#047857}
  .pill.warning{background:#fffbeb;color:#b45309}
  .pill.negative{background:#fef2f2;color:#b91c1c}
  footer{margin-top:48px;padding-top:24px;border-top:1px solid var(--border);font-size:12px;color:var(--text-faint)}
</style>
</head>
<body>
<?php $portalModulo='Zendesk'; @include __DIR__.'/../_portalbar.php'; ?>
<div class="container">

<header>
  <div>
    <h1>Reportes de Servicio · Municipio de Querétaro</h1>
    <div class="meta">
      Sistema Zendesk · <b><?= $periodo_str ?></b> ·
      <b><?= number_format($kpi['total']) ?></b> tickets ·
      <b><?= $num_deleg ?></b> delegaciones
      <?php if ($ult_carga['fecha']): ?>
        · Última carga: <b><?= $ult_carga['fecha'] ?></b>
      <?php endif; ?>
    </div>
  </div>
  <?php $navExtra = ['?refresh=1' => '↻ Refrescar']; include __DIR__ . '/_navzendesk.php'; ?>
</header>

<form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin:-28px 0 28px">
  <div><label style="font-size:11px;color:var(--text-muted);font-weight:600;display:block;margin-bottom:4px">Desde</label>
    <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" style="border:1px solid var(--border);border-radius:8px;padding:8px 10px;font:inherit;font-size:13px"></div>
  <div><label style="font-size:11px;color:var(--text-muted);font-weight:600;display:block;margin-bottom:4px">Hasta</label>
    <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" style="border:1px solid var(--border);border-radius:8px;padding:8px 10px;font:inherit;font-size:13px"></div>
  <div><label style="font-size:11px;color:var(--text-muted);font-weight:600;display:block;margin-bottom:4px">Formulario</label>
    <?= zd_form_select('style="border:1px solid var(--border);border-radius:8px;padding:8px 10px;font:inherit;font-size:13px"') ?></div>
  <button type="submit" style="background:var(--accent);color:#fff;border:0;border-radius:8px;padding:9px 16px;font:inherit;font-weight:600;font-size:13px;cursor:pointer">Aplicar</button>
  <a href="dashboard.php" style="font-size:12px;color:var(--text-muted);align-self:center;text-decoration:none">últimos 30 días</a>
  <span class="card-sub" style="align-self:center;margin-left:auto">Periodo: <b><?= htmlspecialchars($from) ?> → <?= htmlspecialchars($to) ?></b> · <b><?= htmlspecialchars(zd_form_nombre()) ?></b></span>
</form>

<!-- ====================== KPIs ====================== -->
<section class="section">
  <div class="section-title">Indicadores principales</div>
  <div class="grid kpi-grid">
    <div class="card kpi">
      <div class="label">Total de reportes</div>
      <div class="value"><?= number_format($kpi['total']) ?></div>
      <div class="meta">Promedio diario <b><?= $promedio_diario ?></b> · Pico <b><?= number_format($dia_pico) ?></b></div>
    </div>
    <div class="card kpi positive">
      <div class="label">Tasa de resolución</div>
      <div class="value"><?= $kpi['pct_resolucion'] ?><span class="unit">%</span></div>
      <div class="meta"><b><?= number_format($kpi['resueltos']) ?></b> resueltos de <?= number_format($kpi['total']) ?></div>
    </div>
    <div class="card kpi negative">
      <div class="label">Tickets vencidos</div>
      <div class="value"><?= number_format($vencidos_total) ?></div>
      <div class="meta"><?= $pct_vencidos ?>% de los <b><?= number_format($no_resueltos) ?></b> sin resolver</div>
    </div>
    <div class="card kpi">
      <div class="label">Tiempo medio</div>
      <div class="value"><?= $kpi['dias_promedio_resolucion'] ?? '—' ?><span class="unit">días</span></div>
      <div class="meta">Mediana <b><?= round($mediana) ?>d</b> · P90 <b><?= round($p90) ?>d</b></div>
    </div>
    <div class="card kpi positive">
      <div class="label">Cumplimiento SLA</div>
      <div class="value"><?= $pct_sla ?><span class="unit">%</span></div>
      <div class="meta"><b><?= number_format($sla['cumplio']) ?></b> resueltos en tiempo</div>
    </div>
    <div class="card kpi warning">
      <div class="label">Tickets abiertos</div>
      <div class="value"><?= number_format($abiertos) ?></div>
      <div class="meta"><?= $kpi['total']>0 ? round($abiertos/$kpi['total']*100) : 0 ?>% del total · Antigüedad <b><?= $antiguedad_prom ?>d</b></div>
    </div>
    <div class="card kpi">
      <div class="label">Solicitantes únicos</div>
      <div class="value"><?= number_format($solicitantes) ?></div>
      <div class="meta"><b><?= $solicitantes>0?round($kpi['total']/$solicitantes,2):'—' ?></b> tickets por solicitante</div>
    </div>
    <div class="card kpi">
      <div class="label">Cobertura</div>
      <div class="value"><?= number_format($colonias_uniq) ?></div>
      <div class="meta">colonias · <b><?= $tipos_serv ?></b> tipos de servicio</div>
    </div>
  </div>
</section>

<!-- ====================== Distribución ====================== -->
<section class="section">
  <div class="section-title">Distribución de tickets</div>
  <div class="grid row-3">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Estado</h3><span class="card-sub"><?= count($status) ?> estados</span></div>
      <div class="chart-wrap"><canvas id="chStatus"></canvas></div>
    </div>
    <div class="card">
      <div class="card-header"><h3 class="card-title">Canal de origen</h3><span class="card-sub"><?= count($canal) ?> canales</span></div>
      <div class="chart-wrap"><canvas id="chCanal"></canvas></div>
    </div>
    <div class="card">
      <div class="card-header"><h3 class="card-title">Prioridad</h3><span class="card-sub"><?= count($prioridad) ?> niveles</span></div>
      <div class="chart-wrap"><canvas id="chPriority"></canvas></div>
    </div>
  </div>
</section>

<!-- ====================== Tendencia ====================== -->
<section class="section">
  <div class="section-title">Tendencia diaria</div>
  <div class="card">
    <div class="card-header"><h3 class="card-title">Tickets creados vs resueltos</h3><span class="card-sub">Caídas marcan fines de semana</span></div>
    <div class="chart-wrap tall"><canvas id="chTime"></canvas></div>
  </div>
  <div class="grid row-2" style="margin-top:16px">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Por día de la semana</h3></div>
      <div class="chart-wrap"><canvas id="chDow"></canvas></div>
    </div>
    <div class="card">
      <div class="card-header"><h3 class="card-title">Tiempo de resolución</h3><span class="card-sub"><?= number_format($kpi['resueltos']) ?> tickets cerrados</span></div>
      <div class="chart-wrap"><canvas id="chHist"></canvas></div>
    </div>
  </div>
</section>

<!-- ====================== Delegaciones / Grupos ====================== -->
<section class="section">
  <div class="section-title">Desempeño por delegación y grupo</div>
  <div class="grid row-2">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Delegaciones</h3><span class="card-sub">Volumen y % resolución</span></div>
      <div class="chart-wrap tall"><canvas id="chDeleg"></canvas></div>
    </div>
    <div class="card">
      <div class="card-header"><h3 class="card-title">Grupos de servicio (top 10)</h3><span class="card-sub">Volumen y % resolución</span></div>
      <div class="chart-wrap tall"><canvas id="chGrupos"></canvas></div>
    </div>
  </div>
</section>

<!-- ====================== Heatmap ====================== -->
<section class="section">
  <div class="section-title">Mapa de calor</div>
  <div class="card">
    <div class="card-header"><h3 class="card-title">Delegación × grupo de servicio</h3><span class="card-sub">Top 7 × top 8</span></div>
    <div style="overflow-x:auto" id="heatmap"></div>
  </div>
</section>

<!-- ====================== Servicios / Colonias ====================== -->
<section class="section">
  <div class="section-title">Servicios y zonas con más demanda</div>
  <div class="grid row-2">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Tipos de servicio (top 15)</h3></div>
      <div class="chart-wrap xtall"><canvas id="chServ"></canvas></div>
    </div>
    <div class="card">
      <div class="card-header"><h3 class="card-title">Colonias (top 15)</h3></div>
      <div class="chart-wrap xtall"><canvas id="chCol"></canvas></div>
    </div>
  </div>
</section>

<!-- ====================== Backlog ====================== -->
<section class="section">
  <div class="section-title">Backlog y tickets vencidos</div>
  <div class="grid row-2">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Antigüedad del backlog</h3><span class="card-sub"><?= number_format($no_resueltos) ?> no resueltos · media <?= $antiguedad_prom ?>d</span></div>
      <div class="chart-wrap"><canvas id="chAnt"></canvas></div>
    </div>
    <div class="card">
      <div class="card-header"><h3 class="card-title">Vencidos por grupo (top 10)</h3></div>
      <div class="chart-wrap"><canvas id="chVenc"></canvas></div>
    </div>
  </div>
</section>

<!-- ====================== Tablas ====================== -->
<section class="section">
  <div class="section-title">Detalle por delegación</div>
  <div class="card" style="padding:8px 0">
    <table>
      <thead><tr>
        <th style="padding-left:20px">Delegación</th><th class="num">Total</th>
        <th class="num">Resueltos</th><th class="num">Sin resolver</th>
        <th class="num">% Resolución</th><th style="width:160px;padding-right:20px"></th>
      </tr></thead>
      <tbody>
        <?php foreach ($tabla_deleg as $r):
          $pct = (float)$r['pct_resolucion'];
          $cls = $pct>=40?'positive':($pct>=30?'warning':'negative');
          $color = $pct>=40?'#188a5b':($pct>=30?'#d99000':'#ce3a2b');
          $noRes = $r['total']-$r['resueltos'];
        ?>
        <tr>
          <td style="padding-left:20px"><?= htmlspecialchars($r['delegacion']) ?></td>
          <td class="num"><?= number_format($r['total']) ?></td>
          <td class="num"><?= number_format($r['resueltos']) ?></td>
          <td class="num"><?= number_format($noRes) ?></td>
          <td class="num"><span class="pill <?= $cls ?>"><?= $pct ?>%</span></td>
          <td style="padding-right:20px"><div class="progress"><span style="width:<?= $pct ?>%;background:<?= $color ?>"></span></div></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="section">
  <div class="section-title">Detalle por grupo de servicio</div>
  <div class="card" style="padding:8px 0">
    <table>
      <thead><tr>
        <th style="padding-left:20px">Grupo</th><th class="num">Total</th>
        <th class="num">Resueltos</th><th class="num">% Resolución</th>
        <th style="width:160px;padding-right:20px"></th>
      </tr></thead>
      <tbody>
        <?php foreach ($tabla_grupo as $r):
          $pct = (float)$r['pct_resolucion'];
          $cls = $pct>=70?'positive':($pct>=40?'warning':'negative');
          $color = $pct>=70?'#188a5b':($pct>=40?'#d99000':'#ce3a2b');
        ?>
        <tr>
          <td style="padding-left:20px"><?= htmlspecialchars($r['grupo']) ?></td>
          <td class="num"><?= number_format($r['total']) ?></td>
          <td class="num"><?= number_format($r['resueltos']) ?></td>
          <td class="num"><span class="pill <?= $cls ?>"><?= $pct ?>%</span></td>
          <td style="padding-right:20px"><div class="progress"><span style="width:<?= $pct ?>%;background:<?= $color ?>"></span></div></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<footer>
  Generado en vivo desde MySQL · <?= number_format($kpi['total']) ?> registros · Renderizado: <?= date('d M Y H:i') ?>
</footer>

</div>

<script>
const DATA = <?= json_encode($DATA, JSON_UNESCAPED_UNICODE) ?>;

const COLORS = {primary:'#254185',positive:'#188a5b',warning:'#d99000',negative:'#ce3a2b',neutral:'#005ab2',accent:'#2a9eda',pink:'#5b667a',slate:'#5b667a'};
const PALETTE = [COLORS.primary,COLORS.positive,COLORS.warning,COLORS.negative,COLORS.neutral,COLORS.accent,COLORS.pink,COLORS.slate];

Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
Chart.defaults.font.size = 11;
Chart.defaults.color = '#6b7280';
Chart.defaults.borderColor = '#ececec';
Chart.defaults.plugins.legend.labels.boxWidth = 8;
Chart.defaults.plugins.legend.labels.boxHeight = 8;
Chart.defaults.plugins.legend.labels.padding = 14;
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.pointStyle = 'circle';
Chart.defaults.plugins.tooltip.backgroundColor = '#1a1a1a';
Chart.defaults.plugins.tooltip.titleColor = '#fff';
Chart.defaults.plugins.tooltip.bodyColor = '#fff';
Chart.defaults.plugins.tooltip.padding = 10;
Chart.defaults.plugins.tooltip.cornerRadius = 6;

const baseGrid = {grid:{color:'#f3f4f6',drawTicks:false},border:{display:false},ticks:{padding:8}};
const noGrid = {grid:{display:false},border:{display:false},ticks:{padding:8}};

/* Status / Canal / Priority — doughnuts */
new Chart(document.getElementById('chStatus'),{type:'doughnut',
  data:{labels:Object.keys(DATA.status),datasets:[{data:Object.values(DATA.status),backgroundColor:PALETTE,borderWidth:2,borderColor:'#fff'}]},
  options:{plugins:{legend:{position:'right'}},cutout:'70%'}});

new Chart(document.getElementById('chCanal'),{type:'doughnut',
  data:{labels:Object.keys(DATA.canal),datasets:[{data:Object.values(DATA.canal),backgroundColor:PALETTE,borderWidth:2,borderColor:'#fff'}]},
  options:{plugins:{legend:{position:'right'}},cutout:'70%'}});

new Chart(document.getElementById('chPriority'),{type:'doughnut',
  data:{labels:Object.keys(DATA.priority),datasets:[{data:Object.values(DATA.priority),backgroundColor:[COLORS.primary,COLORS.warning,COLORS.negative,COLORS.neutral],borderWidth:2,borderColor:'#fff'}]},
  options:{plugins:{legend:{position:'right'}},cutout:'70%'}});

/* Time series */
new Chart(document.getElementById('chTime'),{type:'line',
  data:{labels:DATA.ts_data.fechas,datasets:[
    {label:'Creados',data:DATA.ts_data.creados,borderColor:COLORS.primary,backgroundColor:'rgba(37,65,133,.08)',tension:.35,fill:true,borderWidth:2,pointRadius:0,pointHoverRadius:5},
    {label:'Resueltos',data:DATA.ts_data.resueltos,borderColor:COLORS.positive,backgroundColor:'rgba(5,150,105,.06)',tension:.35,fill:true,borderWidth:2,pointRadius:0,pointHoverRadius:5}
  ]},
  options:{plugins:{legend:{position:'top',align:'end'}},
    scales:{x:{...noGrid,ticks:{...noGrid.ticks,maxRotation:0,autoSkip:true,maxTicksLimit:12,font:{size:10}}},y:baseGrid}}});

/* DOW */
new Chart(document.getElementById('chDow'),{type:'bar',
  data:{labels:DATA.dow_chart.labels,datasets:[{data:DATA.dow_chart.values,backgroundColor:COLORS.primary,borderRadius:4,barThickness:24}]},
  options:{plugins:{legend:{display:false}},scales:{x:noGrid,y:baseGrid}}});

/* Histogram */
new Chart(document.getElementById('chHist'),{type:'bar',
  data:{labels:DATA.hist_data.labels,datasets:[{data:DATA.hist_data.values,backgroundColor:COLORS.positive,borderRadius:4,barThickness:28}]},
  options:{plugins:{legend:{display:false}},scales:{x:noGrid,y:baseGrid}}});

/* Delegación combo */
new Chart(document.getElementById('chDeleg'),{
  data:{labels:DATA.deleg_data.delegaciones,datasets:[
    {type:'bar',label:'Total',data:DATA.deleg_data.total,backgroundColor:COLORS.primary,borderRadius:3,yAxisID:'y',order:2},
    {type:'bar',label:'Resueltos',data:DATA.deleg_data.resueltos,backgroundColor:COLORS.positive,borderRadius:3,yAxisID:'y',order:2},
    {type:'line',label:'% resolución',data:DATA.deleg_data.pct,borderColor:COLORS.warning,backgroundColor:COLORS.warning,borderWidth:2,tension:.3,yAxisID:'y1',pointRadius:4,pointBackgroundColor:'#fff',pointBorderWidth:2,order:1}
  ]},
  options:{indexAxis:'y',plugins:{legend:{position:'top',align:'end'}},
    scales:{x:{...baseGrid,beginAtZero:true},y:{...noGrid,ticks:{...noGrid.ticks,font:{size:11}}},
            y1:{position:'top',min:0,max:100,grid:{display:false},border:{display:false},ticks:{callback:v=>v+'%',font:{size:10},color:COLORS.warning}}}}});

/* Grupos combo */
new Chart(document.getElementById('chGrupos'),{
  data:{labels:DATA.grp_data.grupos,datasets:[
    {type:'bar',label:'Total',data:DATA.grp_data.total,backgroundColor:COLORS.primary,borderRadius:3,yAxisID:'y',order:2},
    {type:'bar',label:'Resueltos',data:DATA.grp_data.resueltos,backgroundColor:COLORS.positive,borderRadius:3,yAxisID:'y',order:2},
    {type:'line',label:'% resolución',data:DATA.grp_data.pct,borderColor:COLORS.warning,backgroundColor:COLORS.warning,borderWidth:2,tension:.3,yAxisID:'y1',pointRadius:4,pointBackgroundColor:'#fff',pointBorderWidth:2,order:1}
  ]},
  options:{indexAxis:'y',plugins:{legend:{position:'top',align:'end'}},
    scales:{x:{...baseGrid,beginAtZero:true},y:{...noGrid,ticks:{...noGrid.ticks,font:{size:11}}},
            y1:{position:'top',min:0,max:100,grid:{display:false},border:{display:false},ticks:{callback:v=>v+'%',font:{size:10},color:COLORS.warning}}}}});

/* Heatmap */
(function(){
  const h = DATA.heat_data;
  if (!h.delegaciones.length) { document.getElementById('heatmap').innerHTML='<div style="color:#9ca3af;font-size:13px">Sin datos suficientes</div>'; return; }
  const max = Math.max(...h.matriz.flat());
  const cols = h.grupos.length;
  let html = `<div class="heatmap" style="grid-template-columns: 220px repeat(${cols},minmax(70px,1fr));margin-top:8px">`;
  html += `<div></div>`;
  h.grupos.forEach(g=>{html += `<div class="label col">${g}</div>`});
  h.delegaciones.forEach((d,i)=>{
    html += `<div class="label">${d}</div>`;
    h.matriz[i].forEach(v=>{
      const t = max>0 ? v/max : 0;
      const r = Math.round(248 - (248-37)*t);
      const g_ = Math.round(250 - (250-99)*t);
      const b = Math.round(252 - (252-235)*t);
      const color = t > 0.5 ? '#fff' : '#1a1a1a';
      html += `<div class="cell" style="background:rgb(${r},${g_},${b});color:${color}">${v}</div>`;
    });
  });
  html += `</div>`;
  document.getElementById('heatmap').innerHTML = html;
})();

/* Servicios / Colonias */
new Chart(document.getElementById('chServ'),{type:'bar',
  data:{labels:Object.keys(DATA.servicios),datasets:[{data:Object.values(DATA.servicios),backgroundColor:COLORS.neutral,borderRadius:3}]},
  options:{indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{...baseGrid,beginAtZero:true},y:{...noGrid,ticks:{...noGrid.ticks,font:{size:10.5}}}}}});

new Chart(document.getElementById('chCol'),{type:'bar',
  data:{labels:Object.keys(DATA.colonias),datasets:[{data:Object.values(DATA.colonias),backgroundColor:COLORS.accent,borderRadius:3}]},
  options:{indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{...baseGrid,beginAtZero:true},y:{...noGrid,ticks:{...noGrid.ticks,font:{size:10.5}}}}}});

/* Antigüedad */
new Chart(document.getElementById('chAnt'),{type:'bar',
  data:{labels:DATA.antiguedad_chart.labels,datasets:[{data:DATA.antiguedad_chart.values,
    backgroundColor:[COLORS.positive,'#84cc16',COLORS.warning,'#f97316',COLORS.negative,'#7f1d1d'],borderRadius:4,barThickness:32}]},
  options:{plugins:{legend:{display:false}},scales:{x:noGrid,y:baseGrid}}});

/* Vencidos */
new Chart(document.getElementById('chVenc'),{type:'bar',
  data:{labels:Object.keys(DATA.venc_grp),datasets:[{data:Object.values(DATA.venc_grp),backgroundColor:COLORS.negative,borderRadius:3}]},
  options:{indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{...baseGrid,beginAtZero:true},y:{...noGrid,ticks:{...noGrid.ticks,font:{size:10.5}}}}}});
</script>
</body>
</html>
