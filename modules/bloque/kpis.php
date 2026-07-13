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

    /* ---- Demografía derivada de la CURP ----
       pos 5-10  = fecha de nacimiento (AAMMDD)
       pos 11    = sexo (H / M)
       pos 12-13 = entidad de nacimiento (clave de 2 letras)
       pos 17    = dígito si nació antes de 2000, letra si nació de 2000 en adelante  */
    $CURP_OK = "CHAR_LENGTH(TRIM(curp)) = 18";
    $FNAC    = "STR_TO_DATE(CONCAT(
                    CASE WHEN SUBSTRING(curp,17,1) REGEXP '[0-9]' THEN '19' ELSE '20' END,
                    SUBSTRING(curp,5,6)), '%Y%m%d')";
    $EDADC   = "TIMESTAMPDIFF(YEAR, $FNAC, CURDATE())";
    $SEXOC   = "UPPER(SUBSTRING(curp,11,1))";
    $ENTC    = "UPPER(SUBSTRING(curp,12,2))";

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

        // ---- Derivados de la CURP ----
        'curp_ok'      => (int)$one("SELECT COUNT(*) FROM v_usuarios WHERE $CURP_OK"),
        'edad_prom_curp' => round((float)$one("SELECT AVG($EDADC) FROM v_usuarios WHERE $CURP_OK AND $EDADC BETWEEN 0 AND 110"), 1),
        'sexo_curp'    => $q("SELECT $SEXOC k, COUNT(*) n FROM v_usuarios
                               WHERE $CURP_OK AND $SEXOC IN ('H','M') GROUP BY k ORDER BY n DESC"),
        'edad_curp'    => $q("SELECT CASE
                                  WHEN $EDADC < 12 THEN '0-11'  WHEN $EDADC < 18 THEN '12-17'
                                  WHEN $EDADC < 26 THEN '18-25' WHEN $EDADC < 36 THEN '26-35'
                                  WHEN $EDADC < 46 THEN '36-45' WHEN $EDADC < 60 THEN '46-59'
                                  ELSE '60+' END g, COUNT(*) n
                                FROM v_usuarios WHERE $CURP_OK AND $EDADC BETWEEN 0 AND 110
                            GROUP BY g"),
        'entidad_curp' => $q("SELECT $ENTC k, COUNT(*) n FROM v_usuarios
                               WHERE $CURP_OK GROUP BY k ORDER BY n DESC LIMIT 15"),
        'gen_curp'     => $q("SELECT CASE
                                  WHEN YEAR($FNAC) >= 2013 THEN 'Alpha (2013+)'
                                  WHEN YEAR($FNAC) >= 1997 THEN 'Z (1997-2012)'
                                  WHEN YEAR($FNAC) >= 1981 THEN 'Millennial (1981-1996)'
                                  WHEN YEAR($FNAC) >= 1965 THEN 'X (1965-1980)'
                                  ELSE 'Boomer y antes' END g, COUNT(*) n
                                FROM v_usuarios WHERE $CURP_OK AND YEAR($FNAC) BETWEEN 1920 AND YEAR(CURDATE())
                            GROUP BY g"),
    ];

    // Claves de entidad de nacimiento (CURP) → nombre
    $ENT = ['AS'=>'Aguascalientes','BC'=>'Baja California','BS'=>'Baja California Sur','CC'=>'Campeche',
            'CL'=>'Coahuila','CM'=>'Colima','CS'=>'Chiapas','CH'=>'Chihuahua','DF'=>'Ciudad de México',
            'DG'=>'Durango','GT'=>'Guanajuato','GR'=>'Guerrero','HG'=>'Hidalgo','JC'=>'Jalisco',
            'MC'=>'Estado de México','MN'=>'Michoacán','MS'=>'Morelos','NT'=>'Nayarit','NL'=>'Nuevo León',
            'OC'=>'Oaxaca','PL'=>'Puebla','QT'=>'Querétaro','QR'=>'Quintana Roo','SP'=>'San Luis Potosí',
            'SL'=>'Sinaloa','SR'=>'Sonora','TC'=>'Tabasco','TS'=>'Tamaulipas','TL'=>'Tlaxcala',
            'VZ'=>'Veracruz','YN'=>'Yucatán','ZS'=>'Zacatecas','NE'=>'Nacido en el extranjero'];
    foreach ($D['entidad_curp'] as &$r) { $r['k'] = $ENT[$r['k']] ?? ('(' . $r['k'] . ')'); } unset($r);
    foreach ($D['sexo_curp'] as &$r)    { $r['k'] = $r['k'] === 'H' ? 'Hombre' : 'Mujer'; } unset($r);

    /* ---- CALIDAD DE DATOS ----
       El campo `genero` puede usar H/M (Hombre/Mujer) o M/F (Masculino/Femenino):
       detectamos la convención mirando los valores reales antes de comparar con la CURP. */
    $vals  = array_map(fn($r) => mb_strtoupper(trim((string)$r['k'])), $D['genero']);
    $hayF  = count(array_intersect($vals, ['F','FEMENINO','FEMALE'])) > 0;
    $listH = $hayF ? ['M','MASCULINO','H','HOMBRE','MALE'] : ['H','HOMBRE','MASCULINO','MALE'];
    $listM = $hayF ? ['F','FEMENINO','FEMALE','MUJER']     : ['M','MUJER','FEMENINO','FEMALE','F'];
    $inH   = "'" . implode("','", $listH) . "'";
    $inM   = "'" . implode("','", $listM) . "'";
    $GENN  = "CASE WHEN UPPER(TRIM(genero)) IN ($inH) THEN 'H'
                   WHEN UPPER(TRIM(genero)) IN ($inM) THEN 'M' ELSE NULL END";
    $CURP_BAD = "COALESCE(CHAR_LENGTH(TRIM(curp)),0) <> 18";

    $D['cal'] = [
        'convencion'       => $hayF ? 'M/F (Masculino/Femenino)' : 'H/M (Hombre/Mujer)',
        'curp_mala'        => $D['usuarios'] - $D['curp_ok'],
        'sexo_comparables' => (int)$one("SELECT COUNT(*) FROM v_usuarios WHERE $CURP_OK AND ($GENN) IS NOT NULL"),
        'sexo_mismatch'    => (int)$one("SELECT COUNT(*) FROM v_usuarios WHERE $CURP_OK AND ($GENN) IS NOT NULL AND ($GENN) <> $SEXOC"),
        'edad_comparables' => (int)$one("SELECT COUNT(*) FROM v_usuarios WHERE $CURP_OK AND edad > 0"),
        'edad_mismatch'    => (int)$one("SELECT COUNT(*) FROM v_usuarios WHERE $CURP_OK AND edad > 0 AND ABS(edad - ($EDADC)) > 1"),
    ];
    $D['vacios'] = $q("SELECT
            SUM(CASE WHEN TRIM(COALESCE(correo,''))='' THEN 1 ELSE 0 END)      correo,
            SUM(CASE WHEN TRIM(COALESCE(telefono,''))='' THEN 1 ELSE 0 END)    telefono,
            SUM(CASE WHEN TRIM(COALESCE(delegacion,''))='' THEN 1 ELSE 0 END)  delegacion,
            SUM(CASE WHEN TRIM(COALESCE(municipio,''))='' THEN 1 ELSE 0 END)   municipio,
            SUM(CASE WHEN TRIM(COALESCE(genero,''))='' THEN 1 ELSE 0 END)      genero,
            SUM(CASE WHEN fecha_nacimiento IS NULL THEN 1 ELSE 0 END)          fecha_nacimiento,
            SUM(CASE WHEN $CURP_BAD THEN 1 ELSE 0 END)                          curp
          FROM v_usuarios")[0] ?? [];
    $D['curp_deleg'] = $q("SELECT COALESCE(NULLIF(TRIM(delegacion),''),'N/D') k, COUNT(*) total,
                                  SUM(CASE WHEN $CURP_OK THEN 1 ELSE 0 END) validas
                             FROM v_usuarios GROUP BY k ORDER BY total DESC LIMIT 12");

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
    usort($D['edad_curp'], fn($a,$b)=>($ord[$a['g']]??9)<=>($ord[$b['g']]??9));
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
      <div class="b-card"><span class="acc" style="background:#1a2f63"></span><div class="v"><?= $D['usuarios']>0 ? round($D['curp_ok']/$D['usuarios']*100) : 0 ?>%</div><div class="l">CURP válidas</div><div class="s"><?= number_format($D['curp_ok']) ?> de <?= number_format($D['usuarios']) ?></div></div>
      <div class="b-card"><span class="acc" style="background:#65a30d"></span><div class="v"><?= number_format($D['edad_prom_curp'],1) ?></div><div class="l">Edad promedio (CURP)</div><div class="s">calculada de la fecha de nacimiento</div></div>
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

      <!-- ===== Demografía derivada de la CURP ===== -->
      <div class="b-panel b-wide" style="background:#f8fafc;border-style:dashed">
        <h3 style="margin:0">🪪 Demografía derivada de la CURP</h3>
        <p class="text-secondary" style="margin:4px 0 0;font-size:12px">Sexo, fecha de nacimiento (con el siglo de la posición 17) y entidad de nacimiento, extraídos de la CURP — suele estar más completa que los campos declarados. Base: <strong><?= number_format($D['curp_ok']) ?></strong> CURP válidas.</p>
      </div>
      <div class="b-panel"><h3>Sexo (CURP)</h3><div class="box"><canvas id="c-sexo-curp"></canvas></div></div>
      <div class="b-panel"><h3>Edad (CURP)</h3><div class="box"><canvas id="c-edad-curp"></canvas></div></div>
      <div class="b-panel"><h3>Entidad de nacimiento (CURP)</h3><div class="box"><canvas id="c-ent-curp"></canvas></div></div>
      <div class="b-panel"><h3>Generación (CURP)</h3><div class="box"><canvas id="c-gen-curp"></canvas></div></div>

      <!-- ===== Calidad de datos ===== -->
      <div class="b-panel b-wide" style="background:#fffbeb;border-color:#fde68a">
        <h3 style="margin:0;color:#92400e">🧪 Calidad de datos</h3>
        <p class="text-secondary" style="margin:4px 0 12px;font-size:12px">
          Contrastamos lo declarado contra lo que dice la CURP. Convención detectada del campo <code>genero</code>: <strong><?= htmlspecialchars($D['cal']['convencion']) ?></strong>.
        </p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
          <div class="b-card"><span class="acc" style="background:#ce3a2b"></span>
            <div class="v" style="color:#ce3a2b"><?= number_format($D['cal']['curp_mala']) ?></div>
            <div class="l">CURP inválida o faltante</div>
            <div class="s"><?= $D['usuarios']>0 ? round($D['cal']['curp_mala']/$D['usuarios']*100,1) : 0 ?>% de los usuarios</div></div>
          <div class="b-card"><span class="acc" style="background:#d99000"></span>
            <div class="v" style="color:#d99000"><?= number_format($D['cal']['sexo_mismatch']) ?></div>
            <div class="l">Sexo declarado ≠ CURP</div>
            <div class="s"><?= $D['cal']['sexo_comparables']>0 ? round($D['cal']['sexo_mismatch']/$D['cal']['sexo_comparables']*100,1) : 0 ?>% de <?= number_format($D['cal']['sexo_comparables']) ?> comparables</div></div>
          <div class="b-card"><span class="acc" style="background:#8b5cf6"></span>
            <div class="v" style="color:#8b5cf6"><?= number_format($D['cal']['edad_mismatch']) ?></div>
            <div class="l">Edad declarada ≠ CURP (&gt;1 año)</div>
            <div class="s"><?= $D['cal']['edad_comparables']>0 ? round($D['cal']['edad_mismatch']/$D['cal']['edad_comparables']*100,1) : 0 ?>% de <?= number_format($D['cal']['edad_comparables']) ?> comparables</div></div>
        </div>
      </div>
      <div class="b-panel"><h3>Campos vacíos (nº de usuarios)</h3><div class="box"><canvas id="c-vacios"></canvas></div></div>
      <div class="b-panel"><h3>Completitud de CURP por delegación</h3><div class="box"><canvas id="c-curpdel"></canvas></div></div>
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

/* ---- Demografía derivada de la CURP ---- */
(function(){ const el=document.getElementById('c-sexo-curp'); if(!el||!D.sexo_curp.length)return;
  new Chart(el,{type:'doughnut',data:{labels:D.sexo_curp.map(r=>r.k),
      datasets:[{data:D.sexo_curp.map(r=>+r.n),backgroundColor:['#005ab2','#ce3a2b'],borderWidth:1,borderColor:'#fff'}]},
    options:{plugins:{legend:{position:'right'}},cutout:'55%',maintainAspectRatio:false}}); })();
bars('c-edad-curp', D.edad_curp.map(r=>r.g), D.edad_curp.map(r=>+r.n), '#1a2f63');
bars('c-ent-curp',  D.entidad_curp.map(r=>r.k), D.entidad_curp.map(r=>+r.n), '#65a30d', true);
donut('c-gen-curp', D.gen_curp, 'g');

/* ---- Calidad de datos ---- */
(function(){
  const el=document.getElementById('c-vacios'); if(!el)return;
  const et={correo:'Correo',telefono:'Teléfono',delegacion:'Delegación',municipio:'Municipio',
            genero:'Género',fecha_nacimiento:'Fecha nacimiento',curp:'CURP'};
  const ks=Object.keys(et).filter(k=>k in D.vacios);
  const vals=ks.map(k=>+D.vacios[k]||0);
  new Chart(el,{type:'bar',data:{labels:ks.map(k=>et[k]),datasets:[{data:vals,
      backgroundColor:vals.map(v=>v===0?'#188a5b':(v/D.usuarios>0.3?'#ce3a2b':'#d99000')),borderRadius:6}]},
    options:{indexAxis:'y',plugins:{legend:{display:false},
      tooltip:{callbacks:{label:c=>c.raw.toLocaleString()+' usuarios ('+(D.usuarios?(c.raw/D.usuarios*100).toFixed(1):0)+'%)'}}},
      maintainAspectRatio:false,scales:{x:{grid:{color:'#eef2f6'}},y:{grid:{display:false}}}}});
})();
(function(){
  const el=document.getElementById('c-curpdel'); if(!el||!D.curp_deleg.length)return;
  const rows=D.curp_deleg.map(r=>({k:r.k,pct:+r.total>0?+(r.validas/r.total*100).toFixed(1):0,total:+r.total}));
  new Chart(el,{type:'bar',data:{labels:rows.map(r=>r.k),datasets:[{data:rows.map(r=>r.pct),
      backgroundColor:rows.map(r=>r.pct>=95?'#15803d':r.pct>=80?'#65a30d':r.pct>=60?'#d99000':'#ce3a2b'),borderRadius:6}]},
    options:{indexAxis:'y',plugins:{legend:{display:false},
      tooltip:{callbacks:{label:c=>c.raw+'% CURP válida · '+rows[c.dataIndex].total.toLocaleString()+' usuarios'}}},
      maintainAspectRatio:false,scales:{x:{max:100,grid:{color:'#eef2f6'},ticks:{callback:v=>v+'%'}},y:{grid:{display:false}}}}});
})();

(function(){ const el=document.getElementById('c-mes'); if(!el||!D.mes.length)return;
  new Chart(el,{type:'line',data:{labels:D.mes.map(r=>r.m),datasets:[
      {label:'Asistencias',data:D.mes.map(r=>+r.n),borderColor:'#005ab2',backgroundColor:'rgba(0,90,178,.12)',fill:true,tension:.3,pointRadius:2},
      {label:'Presentes',data:D.mes.map(r=>+r.presentes),borderColor:'#188a5b',fill:false,tension:.3,pointRadius:2}]},
    options:{maintainAspectRatio:false,plugins:{legend:{position:'top'}},scales:{x:{grid:{display:false}},y:{grid:{color:'#eef2f6'},beginAtZero:true}}}}); })();
</script>
<?php endif; ?>
</body>
</html>
