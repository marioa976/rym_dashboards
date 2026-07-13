<?php
/**
 * Bloque · Principales KPIs.
 * Asistencia (present/absent), perfil de usuarios y actividades más concurridas.
 * Detecta en runtime las columnas de `actividades`/`sesiones` (esquema variable).
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';   // guard: require_module('bloque')
require_once __DIR__ . '/lib.php';

$D = null; $dbError = null; $meta = [];
try {
    $pdo  = bl_pdo();
    $meta = bl_meta($pdo);
    $q    = fn(string $s) => $pdo->query($s)->fetchAll();
    $one  = fn(string $s) => $pdo->query($s)->fetchColumn();

    $actExpr = bl_expr_actividad($pdo, 'a');
    $fecha   = bl_expr_fecha($pdo);   // ['join'=>..,'expr'=>..] o null

    $D = [
        'usuarios'     => (int)$one("SELECT COUNT(*) FROM v_usuarios"),
        'asistencias'  => (int)$one("SELECT COUNT(*) FROM asistencias"),
        'presentes'    => (int)$one("SELECT COUNT(*) FROM asistencias WHERE asistencia_estatus='present'"),
        'ausentes'     => (int)$one("SELECT COUNT(*) FROM asistencias WHERE asistencia_estatus='absent'"),
        'usuarios_act' => (int)$one("SELECT COUNT(DISTINCT usuario_id) FROM asistencias"),
        'actividades'  => bl_existe($pdo,'actividades') ? (int)$one("SELECT COUNT(*) FROM actividades") : 0,
        'edad_prom'    => round((float)$one("SELECT AVG(edad) FROM v_usuarios WHERE edad > 0"), 1),
        'con_dependiente' => (int)$one("SELECT COUNT(*) FROM asistencias WHERE dependiente_id IS NOT NULL"),

        'genero'   => $q("SELECT COALESCE(NULLIF(TRIM(genero),''),'N/D') k, COUNT(*) n FROM v_usuarios GROUP BY k ORDER BY n DESC"),
        'cuenta'   => $q("SELECT COALESCE(NULLIF(TRIM(account_type),''),'N/D') k, COUNT(*) n FROM v_usuarios GROUP BY k ORDER BY n DESC LIMIT 8"),
        'edad'     => $q("SELECT CASE
                              WHEN edad < 12 THEN '0-11'  WHEN edad < 18 THEN '12-17'
                              WHEN edad < 26 THEN '18-25' WHEN edad < 36 THEN '26-35'
                              WHEN edad < 46 THEN '36-45' WHEN edad < 60 THEN '46-59'
                              ELSE '60+' END g, COUNT(*) n
                           FROM v_usuarios WHERE edad > 0 GROUP BY g"),
        'municipio'=> $q("SELECT COALESCE(NULLIF(TRIM(municipio),''),'N/D') k, COUNT(*) n FROM v_usuarios GROUP BY k ORDER BY n DESC LIMIT 10"),
        'deleg'    => $q("SELECT COALESCE(NULLIF(TRIM(delegacion),''),'N/D') k, COUNT(*) n FROM v_usuarios GROUP BY k ORDER BY n DESC LIMIT 12"),
    ];

    // Top actividades por asistencias (present)
    $D['actividad'] = bl_existe($pdo,'actividades')
        ? $q("SELECT $actExpr k,
                     SUM(CASE WHEN asi.asistencia_estatus='present' THEN 1 ELSE 0 END) presentes,
                     SUM(CASE WHEN asi.asistencia_estatus='absent'  THEN 1 ELSE 0 END) ausentes,
                     COUNT(*) n
                FROM asistencias asi
                JOIN actividades a ON a.actividad_id = asi.actividad_id
            GROUP BY k ORDER BY n DESC LIMIT 12")
        : [];

    // Categoría de actividad (si existe la columna)
    $D['categoria'] = ($meta['hay_actividades'] && $meta['act_cat'])
        ? $q("SELECT COALESCE(NULLIF(TRIM(a.`{$meta['act_cat']}`),''),'N/D') k, COUNT(*) n
                FROM asistencias asi JOIN actividades a ON a.actividad_id = asi.actividad_id
            GROUP BY k ORDER BY n DESC LIMIT 10")
        : [];

    // Tendencia mensual de asistencias (si hay alguna fecha)
    $D['mes'] = $fecha
        ? $q("SELECT DATE_FORMAT({$fecha['expr']}, '%Y-%m') m,
                     SUM(CASE WHEN asi.asistencia_estatus='present' THEN 1 ELSE 0 END) presentes,
                     COUNT(*) n
                FROM asistencias asi {$fecha['join']}
               WHERE {$fecha['expr']} IS NOT NULL
            GROUP BY m ORDER BY m")
        : [];

    // Recurrencia: nº de actividades distintas por usuario
    $D['recurrencia'] = $q("SELECT CASE WHEN c = 1 THEN '1 actividad'
                                        WHEN c BETWEEN 2 AND 3 THEN '2-3'
                                        WHEN c BETWEEN 4 AND 6 THEN '4-6'
                                        ELSE '7+' END g, COUNT(*) n
                              FROM (SELECT usuario_id, COUNT(DISTINCT actividad_id) c
                                      FROM asistencias GROUP BY usuario_id) t
                          GROUP BY g");
} catch (Throwable $e) { $dbError = $e->getMessage(); }

$tasa = ($D && $D['asistencias']>0) ? round($D['presentes']/$D['asistencias']*100,1) : 0;
if ($D) {
    $ord = ['0-11'=>0,'12-17'=>1,'18-25'=>2,'26-35'=>3,'36-45'=>4,'46-59'=>5,'60+'=>6];
    usort($D['edad'], fn($a,$b)=>($ord[$a['g']]??9)<=>($ord[$b['g']]??9));
    $ordR = ['1 actividad'=>0,'2-3'=>1,'4-6'=>2,'7+'=>3];
    usort($D['recurrencia'], fn($a,$b)=>($ordR[$a['g']]??9)<=>($ordR[$b['g']]??9));
}
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bloque · KPIs</title>
  <link rel="stylesheet" href="../../assets/css/qro.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <style>
    .b-hero{background:linear-gradient(120deg,var(--qro-blue-dark),var(--qro-blue));color:#fff;border-radius:16px;padding:22px 26px;margin-bottom:18px}
    .b-hero h1{color:#fff;margin:0 0 4px;font-size:24px}.b-hero p{margin:0;opacity:.9;font-size:14px}
    .b-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:22px}
    .b-card{background:#fff;border:1px solid var(--qro-border);border-radius:14px;padding:16px 18px;position:relative;overflow:hidden;box-shadow:var(--qro-shadow-sm)}
    .b-card .acc{position:absolute;left:0;top:0;bottom:0;width:4px}
    .b-card .v{font-size:26px;font-weight:800;color:var(--qro-blue-dark);line-height:1.05}
    .b-card .l{font-size:12px;color:var(--qro-text-secondary);font-weight:600;margin-top:4px}
    .b-card .s{font-size:11px;color:var(--qro-text-muted);margin-top:2px}
    .b-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    @media(max-width:900px){.b-grid{grid-template-columns:1fr}}
    .b-panel{background:#fff;border:1px solid var(--qro-border);border-radius:14px;padding:16px 18px;box-shadow:var(--qro-shadow-sm)}
    .b-panel h3{margin:0 0 10px;font-size:14px;color:var(--qro-blue-dark)}
    .b-panel .box{height:300px}
    .b-wide{grid-column:1/-1}
  </style>
</head>
<body>
<?php $portalModulo='Bloque'; $navActive='kpis'; include __DIR__ . '/_nav.php'; ?>

<main class="content" style="padding:28px 32px">
  <?php if ($dbError): ?>
    <div class="alert alert-danger">No se pudieron consultar las tablas de Bloque.<br><span style="font-size:12px"><?= htmlspecialchars($dbError) ?></span></div>
  <?php else: ?>
    <div class="b-hero">
      <h1>Principales KPIs · Bloque</h1>
      <p><strong><?= number_format($D['usuarios']) ?></strong> usuarios registrados · <strong><?= number_format($D['asistencias']) ?></strong> asistencias registradas a actividades.</p>
    </div>

    <div class="b-kpis">
      <div class="b-card"><span class="acc" style="background:#254185"></span><div class="v"><?= number_format($D['usuarios']) ?></div><div class="l">Usuarios registrados</div></div>
      <div class="b-card"><span class="acc" style="background:#005ab2"></span><div class="v"><?= number_format($D['usuarios_act']) ?></div><div class="l">Usuarios con asistencia</div><div class="s"><?= $D['usuarios']>0 ? round($D['usuarios_act']/$D['usuarios']*100) : 0 ?>% de los registrados</div></div>
      <div class="b-card"><span class="acc" style="background:#188a5b"></span><div class="v" style="color:#188a5b"><?= number_format($tasa,1) ?>%</div><div class="l">Tasa de asistencia</div><div class="s"><?= number_format($D['presentes']) ?> presentes / <?= number_format($D['ausentes']) ?> ausentes</div></div>
      <div class="b-card"><span class="acc" style="background:#ce3a2b"></span><div class="v"><?= number_format($D['actividades']) ?></div><div class="l">Actividades</div></div>
      <div class="b-card"><span class="acc" style="background:#d99000"></span><div class="v"><?= number_format($D['edad_prom'],1) ?></div><div class="l">Edad promedio</div></div>
      <div class="b-card"><span class="acc" style="background:#2a9eda"></span><div class="v"><?= number_format($D['con_dependiente']) ?></div><div class="l">Asistencias de dependientes</div></div>
    </div>

    <div class="b-grid">
      <?php if ($D['actividad']): ?>
      <div class="b-panel b-wide"><h3>Actividades más concurridas (presentes vs ausentes)</h3><div class="box"><canvas id="c-act"></canvas></div></div>
      <?php endif; ?>
      <div class="b-panel"><h3>Asistencia (present / absent)</h3><div class="box"><canvas id="c-asis"></canvas></div></div>
      <div class="b-panel"><h3>Recurrencia (actividades por usuario)</h3><div class="box"><canvas id="c-rec"></canvas></div></div>
      <div class="b-panel"><h3>Edad de los usuarios</h3><div class="box"><canvas id="c-edad"></canvas></div></div>
      <div class="b-panel"><h3>Género</h3><div class="box"><canvas id="c-gen"></canvas></div></div>
      <div class="b-panel"><h3>Tipo de cuenta</h3><div class="box"><canvas id="c-cta"></canvas></div></div>
      <div class="b-panel"><h3>Top delegaciones</h3><div class="box"><canvas id="c-deleg"></canvas></div></div>
      <?php if ($D['categoria']): ?>
      <div class="b-panel"><h3>Asistencias por categoría de actividad</h3><div class="box"><canvas id="c-cat"></canvas></div></div>
      <?php endif; ?>
      <?php if ($D['mes']): ?>
      <div class="b-panel b-wide"><h3>Tendencia mensual de asistencias</h3><div class="box"><canvas id="c-mes"></canvas></div></div>
      <?php else: ?>
      <div class="b-panel b-wide"><h3>Tendencia mensual</h3><p class="text-secondary" style="font-size:13px">No se detectó una columna de fecha en <code>actividades</code>/<code>sesiones</code>, así que no se puede graficar la tendencia. Si me dices cuál es, la habilito.</p></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</main>

<?php if ($D): ?>
<script>
const D = <?= json_encode($D, JSON_UNESCAPED_UNICODE) ?>;
const QC = ['#254185','#005ab2','#2a9eda','#188a5b','#d99000','#ce3a2b','#1a2f63','#5b667a','#65a30d','#8b5cf6','#0ea5e9','#b45309'];
Chart.defaults.font.family = "'Montserrat',Arial,sans-serif";
const donut=(id,rows,kk='k')=>{ const el=document.getElementById(id); if(!el)return;
  new Chart(el,{type:'doughnut',data:{labels:rows.map(r=>r[kk]),datasets:[{data:rows.map(r=>+r.n),backgroundColor:QC,borderWidth:1,borderColor:'#fff'}]},
    options:{plugins:{legend:{position:'right',labels:{boxWidth:12,font:{size:11}}}},cutout:'55%',maintainAspectRatio:false}}); };
const bars=(id,labels,data,color='#005ab2',horizontal=false)=>{ const el=document.getElementById(id); if(!el)return;
  new Chart(el,{type:'bar',data:{labels,datasets:[{data,backgroundColor:color,borderRadius:6}]},
    options:{indexAxis:horizontal?'y':'x',plugins:{legend:{display:false}},maintainAspectRatio:false,
      scales:{x:{grid:{color:'#eef2f6'}},y:{grid:{color:'#eef2f6'}}}}}); };

/* Asistencia present/absent */
(function(){ const el=document.getElementById('c-asis'); if(!el)return;
  new Chart(el,{type:'doughnut',data:{labels:['Presentes','Ausentes'],datasets:[{data:[D.presentes,D.ausentes],backgroundColor:['#188a5b','#ce3a2b'],borderWidth:1,borderColor:'#fff'}]},
    options:{plugins:{legend:{position:'right'}},cutout:'55%',maintainAspectRatio:false}}); })();

/* Actividades: presentes vs ausentes (barras apiladas horizontales) */
(function(){ const el=document.getElementById('c-act'); if(!el||!D.actividad.length)return;
  new Chart(el,{type:'bar',data:{labels:D.actividad.map(r=>r.k),datasets:[
      {label:'Presentes',data:D.actividad.map(r=>+r.presentes),backgroundColor:'#188a5b',borderRadius:4},
      {label:'Ausentes', data:D.actividad.map(r=>+r.ausentes), backgroundColor:'#ce3a2b',borderRadius:4}]},
    options:{indexAxis:'y',maintainAspectRatio:false,plugins:{legend:{position:'top'}},
      scales:{x:{stacked:true,grid:{color:'#eef2f6'}},y:{stacked:true,grid:{display:false}}}}}); })();

bars('c-rec', D.recurrencia.map(r=>r.g), D.recurrencia.map(r=>+r.n), '#254185');
bars('c-edad', D.edad.map(r=>r.g), D.edad.map(r=>+r.n), '#005ab2');
donut('c-gen', D.genero);
donut('c-cta', D.cuenta);
bars('c-deleg', D.deleg.map(r=>r.k), D.deleg.map(r=>+r.n), '#188a5b', true);
if (D.categoria.length) donut('c-cat', D.categoria);

(function(){ const el=document.getElementById('c-mes'); if(!el||!D.mes.length)return;
  new Chart(el,{type:'line',data:{labels:D.mes.map(r=>r.m),datasets:[
      {label:'Asistencias',data:D.mes.map(r=>+r.n),borderColor:'#005ab2',backgroundColor:'rgba(0,90,178,.12)',fill:true,tension:.3,pointRadius:2},
      {label:'Presentes',data:D.mes.map(r=>+r.presentes),borderColor:'#188a5b',fill:false,tension:.3,pointRadius:2}]},
    options:{maintainAspectRatio:false,plugins:{legend:{position:'top'}},scales:{x:{grid:{display:false}},y:{grid:{color:'#eef2f6'},beginAtZero:true}}}}); })();
</script>
<?php endif; ?>
</body>
</html>
