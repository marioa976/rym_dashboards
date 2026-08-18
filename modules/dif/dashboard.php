<?php
/**
 * dashboard.php  —  Dashboard ejecutivo + geográfico del Padrón DIF.
 *
 * Abre: http://localhost:8888/dif/dashboard.php
 *
 * - Lee directamente de la tabla `padron` de MariaDB.
 * - Si la conexión falla, usa datos simulados embebidos para que el dashboard
 *   se siga viendo (modo demo).
 * - Toda la lógica de filtros, KPIs y gráficos corre client-side sobre el
 *   array global window.PADRON, así los filtros recalculan en vivo.
 */

declare(strict_types=1);

ini_set('memory_limit', '512M');

$config = @include __DIR__ . '/config.php';
$rows   = [];
$modoFallback = false;

if (is_array($config)) {
    try {
        $db  = $config['db'];
        $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4");

        // Detectar si la columna `activo` existe; si sí, sólo traemos activos.
        $cols = $pdo->query("SHOW COLUMNS FROM padron")->fetchAll(PDO::FETCH_COLUMN);
        $whereActivo = in_array('activo', $cols, true) ? "WHERE activo = 1" : "";

        // Sólo los campos que vamos a usar; CURP enmascarada por privacidad.
        $sql = "SELECT
                    id,
                    DATE_FORMAT(fecha_registro,  '%Y-%m-%d') AS fecha_registro,
                    DATE_FORMAT(fecha_entrega,   '%Y-%m-%d') AS fecha_entrega,
                    cantidad,
                    programa,
                    coordinacion,
                    tipo_apoyo,
                    recibe_ciudadano,
                    lugar_entrega,
                    ciudadano,
                    sexo,
                    CASE WHEN curp IS NULL OR curp='' THEN NULL ELSE curp END AS curp,
                    DATE_FORMAT(fecha_nacimiento,'%Y-%m-%d') AS fecha_nacimiento,
                    edad,
                    cp,
                    delegacion,
                    colonia,
                    latitud,
                    longitud
                  FROM padron
                  $whereActivo";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $modoFallback = true;
    }
}

if (empty($rows)) {
    $modoFallback = true;
    // 30 filas simuladas para que el dashboard cargue sin BD
    $rows = generarSimulados();
}

// Cast numéricos
foreach ($rows as &$r) {
    $r['cantidad'] = isset($r['cantidad']) && $r['cantidad'] !== null && $r['cantidad'] !== ''
        ? (int)$r['cantidad'] : null;
    $r['edad']     = isset($r['edad']) && $r['edad'] !== null && $r['edad'] !== ''
        ? (int)$r['edad'] : null;
    $r['latitud']  = isset($r['latitud']) && $r['latitud'] !== null && $r['latitud'] !== ''
        ? (float)$r['latitud'] : null;
    $r['longitud'] = isset($r['longitud']) && $r['longitud'] !== null && $r['longitud'] !== ''
        ? (float)$r['longitud'] : null;
}
unset($r);

$jsonRows = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$gmapsKey = isset($config['google_maps']['api_key']) ? (string)$config['google_maps']['api_key'] : '';
$gmapsMapId = isset($config['google_maps']['map_id']) ? (string)$config['google_maps']['map_id'] : 'DEMO_MAP_ID';

function generarSimulados(): array {
    $programas = ['ACCIÓN POR TU SALUD','APOYO ALIMENTARIO','BECAS','TERCERA EDAD','DISCAPACIDAD'];
    $coords    = ['COORD. PREVENCIÓN','COORD. ALIMENTARIA','COORD. INCLUSIÓN','COORD. JUVENTUDES'];
    $tipos     = ['CONSULTA','DESPENSA','BECA ECONÓMICA','APOYO RENAL','SILLA DE RUEDAS'];
    $delegs    = ['FELIPE CARRILLO PUERTO','EPIGMENIO GONZALEZ','JOSEFA VERGARA Y HERNANDEZ','SANTA ROSA JÁUREGUI'];
    $colonias  = ['EL ZAPOTE','SAN FRANCISCO DE LA PALMA','TLACOTE EL BAJO','ALTOS DEL SALITRE','CENTRO'];
    $sexos     = ['MUJER','HOMBRE'];
    $recibe    = ['SI','NO'];
    $out = [];
    for ($i=1; $i<=30; $i++) {
        $hasCoords = $i % 4 !== 0;
        $out[] = [
            'id' => $i,
            'fecha_registro'  => '2025-' . str_pad((string)random_int(1,12),2,'0',STR_PAD_LEFT) . '-' . str_pad((string)random_int(1,28),2,'0',STR_PAD_LEFT),
            'fecha_entrega'   => '2025-' . str_pad((string)random_int(1,12),2,'0',STR_PAD_LEFT) . '-' . str_pad((string)random_int(1,28),2,'0',STR_PAD_LEFT),
            'cantidad'        => random_int(1,3),
            'programa'        => $programas[array_rand($programas)],
            'coordinacion'    => $coords[array_rand($coords)],
            'tipo_apoyo'      => $tipos[array_rand($tipos)],
            'recibe_ciudadano'=> $recibe[array_rand($recibe)],
            'lugar_entrega'   => 'CENTRO DE ENTREGA ' . random_int(1,5),
            'ciudadano'       => 'CIUDADANO SIMULADO ' . $i,
            'sexo'            => $sexos[array_rand($sexos)],
            'curp'            => $i % 3 === 0 ? null : 'CURP' . str_pad((string)$i,14,'0',STR_PAD_LEFT),
            'fecha_nacimiento'=> (1940 + random_int(0,70)) . '-01-01',
            'edad'            => random_int(0,90),
            'cp'              => '7620' . random_int(0,9),
            'delegacion'      => $delegs[array_rand($delegs)],
            'colonia'         => $colonias[array_rand($colonias)],
            'latitud'         => $hasCoords ? 20.5888 + (random_int(-50,50)/1000) : null,
            'longitud'        => $hasCoords ? -100.3899 + (random_int(-50,50)/1000) : null,
        ];
    }
    return $out;
}
?><?php
$ktTitle  = 'Padrón DIF — Dashboard';
$ktActive = 'dif';
$ktFluid = true;
require __DIR__ . '/../../views/layout/kt_top.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="https://unpkg.com/@googlemaps/markerclusterer@2.5.3/dist/index.min.js"></script>
<!-- deck.gl: reemplazo del HeatmapLayer (removido de Google Maps en v3.65) -->
<script src="https://unpkg.com/deck.gl@8.9.35/dist.min.js"></script>
<script>
  // Google Maps JS API — carga dinámica oficial
  (g=>{var h,a,k,p="The Google Maps JavaScript API",c="google",l="importLibrary",q="__ib__",m=document,b=window;b=b[c]||(b[c]={});var d=b.maps||(b.maps={}),r=new Set,e=new URLSearchParams,u=()=>h||(h=new Promise(async(f,n)=>{await (a=m.createElement("script"));e.set("libraries",[...r]+"");for(k in g)e.set(k.replace(/[A-Z]/g,t=>"_"+t[0].toLowerCase()),g[k]);e.set("callback",c+".maps."+q);a.src=`https://maps.${c}apis.com/maps/api/js?`+e;d[q]=f;a.onerror=()=>h=n(Error(p+" could not load."));a.nonce=m.querySelector("script[nonce]")?.nonce||"";m.head.append(a)}));d[l]?console.warn(p+" only loads once. Ignoring:",g):d[l]=(f,...n)=>r.add(f)&&u().then(()=>d[l](f,...n))})({
    key: <?= json_encode($gmapsKey) ?>,
    v: "weekly",
    region: "MX",
    language: "es"
  });
</script>
<style>
  /* Paleta de gráficas (Chart.js) y semáforos de calidad — se conservan */
  :root{
    --chart1:#254185;--chart2:#005ab2;--chart3:#188a5b;--chart4:#d99000;
    --chart5:#2a9eda;--chart6:#ce3a2b;--chart7:#1a2f63;--chart8:#5b667a;
    --ok:#188a5b;--warn:#d99000;--err:#ce3a2b;--info:#2a9eda;
  }

  /* Tab control (Ejecutivo / Geográfico) — segmentado Metronic */
  .topbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}
  .badge-demo{background:var(--warn);color:#fff;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:600}
  .tabs{display:inline-flex;gap:4px;background:var(--muted);padding:4px;border-radius:.625rem}
  .tab{padding:7px 14px;border-radius:.5rem;cursor:pointer;color:var(--muted-foreground);font-size:13px;font-weight:600;transition:.15s}
  .tab:hover{color:var(--foreground)}
  .tab.active{background:var(--card);color:var(--primary);box-shadow:0 1px 2px rgba(0,0,0,.08)}

  /* Filtros */
  .filtros{background:var(--card);padding:16px 18px;border:1px solid var(--border);border-radius:var(--radius);margin-bottom:20px}
  .filtros-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}
  .filtros-grid .field{display:flex;flex-direction:column;gap:4px}
  .filtros-grid label{font-size:11px;color:var(--muted-foreground);text-transform:uppercase;letter-spacing:.3px;font-weight:600}
  .filtros-grid input,.filtros-grid select{background:var(--background);border:1px solid var(--input);border-radius:.5rem;padding:8px 10px;font-size:13px;color:var(--foreground);width:100%}
  .filtros-grid input:focus,.filtros-grid select:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px color-mix(in oklab, var(--primary) 18%, transparent)}
  .filtros-actions{margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .btn{background:var(--primary);color:var(--primary-foreground);border:0;border-radius:.5rem;padding:8px 14px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:.15s}
  .btn:hover{filter:brightness(.94)}
  .btn.secondary{background:var(--card);color:var(--foreground);border:1px solid var(--border)}
  .btn.secondary:hover{background:var(--accent)}
  .btn.ghost{background:transparent;color:var(--muted-foreground);border:1px solid var(--border)}
  .btn.ghost:hover{background:var(--accent);color:var(--foreground)}

  /* Layout */
  .container{padding:0}
  .section{display:none}
  .section.active{display:block}
  h2.title{font-size:12px;color:var(--muted-foreground);text-transform:uppercase;letter-spacing:.5px;font-weight:700;margin:22px 0 12px}
  h2.title:first-child{margin-top:0}

  /* KPI cards */
  .kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:16px}
  .kpi{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:16px;transition:box-shadow .15s}
  .kpi:hover{box-shadow:0 4px 14px rgba(15,23,42,.07)}
  .kpi .lbl{font-size:11px;color:var(--muted-foreground);text-transform:uppercase;letter-spacing:.3px;font-weight:600}
  .kpi .val{font-size:26px;font-weight:700;margin-top:6px;color:var(--primary)}
  .kpi .val.ok{color:var(--ok)}.kpi .val.warn{color:var(--warn)}.kpi .val.err{color:var(--err)}
  .kpi .sub{font-size:12px;color:var(--muted-foreground);margin-top:4px}
  .kpi .icon{float:right;font-size:20px;opacity:.45}

  /* Charts grid */
  .charts{display:grid;grid-template-columns:repeat(auto-fill,minmax(380px,1fr));gap:16px}
  .chart-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:16px}
  .chart-card h3{margin:0 0 12px;font-size:13px;color:var(--foreground);font-weight:600}
  .chart-card.wide{grid-column:span 2}
  .chart-card .canvas-wrap{position:relative;height:260px}

  /* Calidad */
  .calidad{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}
  .qbox{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:14px;border-inline-start:4px solid var(--ok)}
  .qbox.warn{border-inline-start-color:var(--warn)}
  .qbox.err{border-inline-start-color:var(--err)}
  .qbox .lbl{font-size:12px;color:var(--muted-foreground);font-weight:600}
  .qbox .val{font-size:20px;font-weight:700;margin-top:4px;color:var(--foreground)}
  .qbox .pct{font-size:12px;color:var(--muted-foreground)}
  .qbox.semaforo{padding:10px 14px}
  .qbox .dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--ok);margin-right:6px;vertical-align:middle}
  .qbox.warn .dot{background:var(--warn)}.qbox.err .dot{background:var(--err)}

  /* Tablas */
  .table-wrap{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
  table{width:100%;border-collapse:collapse;font-size:13px}
  thead{background:var(--secondary)}
  th{text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:var(--muted-foreground);font-weight:600;border-bottom:1px solid var(--border)}
  td{padding:9px 12px;border-bottom:1px solid var(--border);color:var(--foreground)}
  tbody tr:hover{background:var(--accent)}
  .table-wrap.scroll{max-height:420px;overflow:auto}

  /* Geo */
  .geo-grid{display:grid;grid-template-columns:300px 1fr 280px;gap:16px;height:calc(100vh - 220px)}
  .geo-side{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:14px;overflow:auto}
  #map{border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;height:100%}
  .geo-kpis{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px}
  .geo-kpis .kpi{padding:10px}
  .geo-kpis .kpi .val{font-size:18px}
  .ranking{font-size:12px}
  .ranking .row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed var(--border)}
  .ranking .row:last-child{border:0}
  .ranking strong{color:var(--primary)}
  .layer-ctrl{margin-top:12px;display:flex;gap:6px;flex-wrap:wrap}
  .layer-ctrl label{font-size:12px;display:flex;align-items:center;gap:4px;color:var(--foreground)}

  @media (max-width: 980px){
    .geo-grid{grid-template-columns:1fr;height:auto}
    #map{height:clamp(520px,calc(100vh - 250px),880px)}
    .chart-card.wide{grid-column:span 1}
  }
  .leaflet-popup-content{font-size:12px;line-height:1.6}
  .leaflet-popup-content b{color:var(--primary)}

  /* Tarjetas clickeables (duplicados) */
  .kpi.clickable,.qbox.clickable{cursor:pointer}
  .kpi.clickable:hover{border-color:var(--primary);box-shadow:0 6px 16px rgba(37,65,133,.14)}
  .qbox.clickable:hover{border-color:var(--primary)}
  /* Overlay de detalle de duplicados */
  .dup-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:2000;
               display:none;align-items:flex-start;justify-content:center;padding:36px 18px;overflow:auto}
  .dup-overlay.open{display:flex}
  .dup-modal{background:var(--card);border-radius:12px;max-width:1080px;width:100%;
             box-shadow:0 24px 64px rgba(0,0,0,.32);padding:22px 24px}
  .dup-modal h2{margin:0;color:var(--primary);font-size:20px}
  .dup-grp{border:1px solid var(--border);border-radius:.5rem;margin-bottom:12px;overflow:hidden}
  .dup-grp .grp-head{background:var(--secondary);padding:8px 12px;font-size:12.5px;font-weight:700;color:var(--primary)}
  .dup-grp table{font-size:12px}
  .dup-grp td,.dup-grp th{padding:7px 10px}
</style>

<div class="topbar">
  <div class="tabs">
    <div class="tab active" data-tab="ejecutivo">📊 Ejecutivo</div>
    <div class="tab" data-tab="geo">🗺️ Geográfico</div>
  </div>
  <?php if ($modoFallback): ?><span class="badge-demo">MODO DEMO (datos simulados)</span><?php endif; ?>
</div>

<!-- Filtros globales -->
<div class="filtros">
  <div class="filtros-grid">
    <div class="field"><label>Fecha registro desde</label><input type="date" id="f-fr-desde"></div>
    <div class="field"><label>Fecha registro hasta</label><input type="date" id="f-fr-hasta"></div>
    <div class="field"><label>Fecha entrega desde</label><input type="date" id="f-fe-desde"></div>
    <div class="field"><label>Fecha entrega hasta</label><input type="date" id="f-fe-hasta"></div>
    <div class="field"><label>Programa</label><select id="f-programa"><option value="">— todos —</option></select></div>
    <div class="field"><label>Coordinación</label><select id="f-coordinacion"><option value="">— todas —</option></select></div>
    <div class="field"><label>Tipo de apoyo</label><select id="f-tipo"><option value="">— todos —</option></select></div>
    <div class="field"><label>Lugar de entrega</label><select id="f-lugar"><option value="">— todos —</option></select></div>
    <div class="field"><label>Sexo</label><select id="f-sexo"><option value="">— todos —</option><option>MUJER</option><option>HOMBRE</option></select></div>
    <div class="field"><label>Rango de edad</label>
      <select id="f-edad">
        <option value="">— todas —</option>
        <option value="0-17">0-17</option>
        <option value="18-29">18-29</option>
        <option value="30-44">30-44</option>
        <option value="45-59">45-59</option>
        <option value="60+">60+</option>
      </select>
    </div>
    <div class="field"><label>Delegación</label><select id="f-delegacion"><option value="">— todas —</option></select></div>
    <div class="field"><label>Colonia</label><select id="f-colonia"><option value="">— todas —</option></select></div>
    <div class="field"><label>¿Recibe el ciudadano?</label>
      <select id="f-recibe"><option value="">— ambos —</option><option>SI</option><option>NO</option></select>
    </div>
    <div class="field"><label>Coordenadas</label>
      <select id="f-coords">
        <option value="">— todos —</option>
        <option value="con">Con coordenadas</option>
        <option value="sin">Sin coordenadas</option>
      </select>
    </div>
  </div>
  <div class="filtros-actions">
    <button class="btn" onclick="aplicarFiltros()">🔎 Aplicar filtros</button>
    <button class="btn ghost" onclick="limpiarFiltros()">🧹 Limpiar</button>
    <button class="btn secondary" onclick="exportCSV()">⤓ Exportar CSV</button>
    <span style="margin-left:auto;font-size:12px;color:var(--muted-foreground);align-self:center"
          id="contador-filtrados"></span>
  </div>
</div>

<div class="container">

<!-- ============================ EJECUTIVO ============================ -->
<div class="section active" id="sec-ejecutivo">

  <h2 class="title">KPIs principales</h2>
  <div class="kpis" id="kpis-main"></div>

  <h2 class="title">Calidad de datos</h2>
  <div class="calidad" id="calidad"></div>

  <h2 class="title">Visualización</h2>
  <div class="charts">
    <div class="chart-card"><h3>Apoyos por programa</h3><div class="canvas-wrap"><canvas id="ch-programa"></canvas></div></div>
    <div class="chart-card"><h3>Apoyos por tipo de apoyo</h3><div class="canvas-wrap"><canvas id="ch-tipo"></canvas></div></div>
    <div class="chart-card"><h3>Apoyos por coordinación</h3><div class="canvas-wrap"><canvas id="ch-coord"></canvas></div></div>
    <div class="chart-card"><h3>Beneficiarios por delegación</h3><div class="canvas-wrap"><canvas id="ch-delegacion"></canvas></div></div>
    <div class="chart-card"><h3>Top 10 colonias con más apoyos</h3><div class="canvas-wrap"><canvas id="ch-colonia"></canvas></div></div>
    <div class="chart-card"><h3>Distribución por sexo</h3><div class="canvas-wrap"><canvas id="ch-sexo"></canvas></div></div>
    <div class="chart-card"><h3>Distribución por rango de edad</h3><div class="canvas-wrap"><canvas id="ch-edad"></canvas></div></div>
    <div class="chart-card"><h3>Lugar de entrega con más apoyos (top 10)</h3><div class="canvas-wrap"><canvas id="ch-lugar"></canvas></div></div>
    <div class="chart-card wide"><h3>Tendencia mensual por fecha de registro</h3><div class="canvas-wrap"><canvas id="ch-treg"></canvas></div></div>
    <div class="chart-card wide"><h3>Tendencia mensual por fecha de entrega</h3><div class="canvas-wrap"><canvas id="ch-tent"></canvas></div></div>
  </div>

  <h2 class="title">Tabla resumen</h2>
  <div class="table-wrap scroll">
    <table id="tbl-resumen">
      <thead><tr>
        <th>Programa</th><th>Tipo de apoyo</th><th>Coordinación</th>
        <th style="text-align:right">Apoyos</th><th style="text-align:right">Benef. únicos</th>
        <th style="text-align:right">Cant. total</th>
        <th style="text-align:right">Delegaciones</th><th style="text-align:right">Colonias</th>
      </tr></thead>
      <tbody></tbody>
    </table>
  </div>

</div>

<!-- ============================ GEO ============================ -->
<div class="section" id="sec-geo">

  <h2 class="title">Dashboard geográfico</h2>
  <div class="geo-grid">

    <!-- LEFT: filtros territoriales y KPIs -->
    <div class="geo-side">
      <div class="geo-kpis" id="geo-kpis"></div>

      <div style="font-size:11px;color:var(--muted-foreground);text-transform:uppercase;letter-spacing:.3px;
                  font-weight:600;margin-bottom:6px">Modo del mapa</div>
      <div class="layer-ctrl" style="flex-direction:column;align-items:flex-start;gap:8px">
        <label style="display:flex;align-items:center;gap:6px">
          <input type="radio" name="ly-mode" value="cluster" checked> 🎯 Cluster (agrupa por zoom)
        </label>
        <label style="display:flex;align-items:center;gap:6px">
          <input type="radio" name="ly-mode" value="heatmap"> 🔥 Heatmap (densidad)
        </label>
        <label id="ly-color-wrap" style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted-foreground);margin-top:4px">
          <input type="checkbox" id="ly-color" checked> Color por programa (sólo cluster)
        </label>
      </div>

      <div style="margin-top:14px;font-size:11px;color:var(--muted-foreground);text-transform:uppercase;letter-spacing:.3px;
                  font-weight:600;margin-bottom:6px">Top 10 colonias</div>
      <div class="ranking" id="rank-colonias"></div>

      <div style="margin-top:14px;font-size:11px;color:var(--muted-foreground);text-transform:uppercase;letter-spacing:.3px;
                  font-weight:600;margin-bottom:6px">Top 10 delegaciones</div>
      <div class="ranking" id="rank-delegaciones"></div>
    </div>

    <!-- CENTER: mapa -->
    <div id="map"></div>

    <!-- RIGHT: tabla filtrada -->
    <div class="geo-side">
      <div style="font-size:11px;color:var(--muted-foreground);text-transform:uppercase;letter-spacing:.3px;
                  font-weight:600;margin-bottom:6px">Registros (primeros 200)</div>
      <table style="font-size:11.5px">
        <thead><tr><th>Programa</th><th>Colonia</th><th style="text-align:right">Cant.</th></tr></thead>
        <tbody id="geo-tbl"></tbody>
      </table>
    </div>
  </div>

</div>

</div><!-- /container -->

<!-- Detalle de posibles duplicados -->
<div class="dup-overlay" id="dup-overlay" onclick="if(event.target===this)cerrarDuplicados()">
  <div class="dup-modal">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
      <h2>Posibles duplicados</h2>
      <button class="btn ghost" onclick="cerrarDuplicados()">✕ Cerrar</button>
    </div>
    <p style="color:var(--muted-foreground);font-size:13px;margin:6px 0 12px" id="dup-sub"></p>
    <div style="margin-bottom:14px">
      <a class="btn secondary" href="dedupe.php">🧹 Ir a la herramienta de deduplicación</a>
    </div>
    <div id="dup-body"></div>
  </div>
</div>

<script>
// ======================================================================
// DATOS
// ======================================================================
window.PADRON = <?= $jsonRows ?? '[]' ?>;
const HOY = new Date();

// ======================================================================
// REGLAS DE CÁLCULO
// ======================================================================
function isCoordValid(r){
  return r.latitud != null && r.longitud != null &&
         !isNaN(parseFloat(r.latitud)) && !isNaN(parseFloat(r.longitud)) &&
         parseFloat(r.latitud) !== 0 && parseFloat(r.longitud) !== 0;
}
function clavePersona(r){
  if (r.curp && r.curp.trim() !== '') return 'C:' + r.curp.trim().toUpperCase();
  return 'X:' + (r.ciudadano||'').trim().toUpperCase() +
         '|' + (r.fecha_nacimiento||'') +
         '|' + (r.colonia||'').trim().toUpperCase();
}
function rangoEdad(e){
  if (e==null||e==='') return 'N/D';
  e = +e;
  if (e<=17) return '0-17';
  if (e<=29) return '18-29';
  if (e<=44) return '30-44';
  if (e<=59) return '45-59';
  return '60+';
}
function fmt(n){ return (n||0).toLocaleString('es-MX'); }
function pct(n,d){ return d>0 ? ((n/d)*100).toFixed(1)+'%' : '0%'; }
function monthKey(s){ return s ? s.substring(0,7) : null; }

// ======================================================================
// COLORES
// ======================================================================
const PALETTE = ['#254185','#005ab2','#188a5b','#d99000','#2a9eda',
                 '#ce3a2b','#1a2f63','#5b667a','#ca8a04','#475569',
                 '#7c3aed','#db2777','#0369a1','#15803d','#a16207'];
const programColor = {};
function colorFor(p){
  if (!programColor[p]) {
    programColor[p] = PALETTE[Object.keys(programColor).length % PALETTE.length];
  }
  return programColor[p];
}

// ======================================================================
// CARGA INICIAL DE FILTROS
// ======================================================================
function uniqSorted(field){
  const s = new Set();
  for (const r of window.PADRON) {
    const v = r[field];
    if (v != null && String(v).trim() !== '') s.add(String(v));
  }
  return Array.from(s).sort((a,b)=>a.localeCompare(b,'es'));
}
function fillSelect(id, vals){
  const el = document.getElementById(id);
  vals.forEach(v => {
    const o = document.createElement('option');
    o.value = v; o.textContent = v.length>50? v.slice(0,50)+'…' : v;
    el.appendChild(o);
  });
}
fillSelect('f-programa',     uniqSorted('programa'));
fillSelect('f-coordinacion', uniqSorted('coordinacion'));
fillSelect('f-tipo',         uniqSorted('tipo_apoyo'));
fillSelect('f-lugar',        uniqSorted('lugar_entrega'));
fillSelect('f-delegacion',   uniqSorted('delegacion'));
fillSelect('f-colonia',      uniqSorted('colonia'));

// Pre-genera colores para programas (estable visualmente)
uniqSorted('programa').forEach(colorFor);

// ======================================================================
// FILTRADO
// ======================================================================
function readFilters(){
  return {
    frD: document.getElementById('f-fr-desde').value,
    frH: document.getElementById('f-fr-hasta').value,
    feD: document.getElementById('f-fe-desde').value,
    feH: document.getElementById('f-fe-hasta').value,
    prog: document.getElementById('f-programa').value,
    coor: document.getElementById('f-coordinacion').value,
    tipo: document.getElementById('f-tipo').value,
    lug:  document.getElementById('f-lugar').value,
    sex:  document.getElementById('f-sexo').value,
    eda:  document.getElementById('f-edad').value,
    del:  document.getElementById('f-delegacion').value,
    col:  document.getElementById('f-colonia').value,
    rec:  document.getElementById('f-recibe').value,
    crd:  document.getElementById('f-coords').value,
  };
}
let FILTRADOS = [];
function aplicarFiltros(){
  const f = readFilters();
  FILTRADOS = window.PADRON.filter(r => {
    if (f.frD && (!r.fecha_registro || r.fecha_registro < f.frD)) return false;
    if (f.frH && (!r.fecha_registro || r.fecha_registro > f.frH)) return false;
    if (f.feD && (!r.fecha_entrega  || r.fecha_entrega  < f.feD)) return false;
    if (f.feH && (!r.fecha_entrega  || r.fecha_entrega  > f.feH)) return false;
    if (f.prog && r.programa !== f.prog) return false;
    if (f.coor && r.coordinacion !== f.coor) return false;
    if (f.tipo && r.tipo_apoyo !== f.tipo) return false;
    if (f.lug  && r.lugar_entrega !== f.lug) return false;
    if (f.sex  && r.sexo !== f.sex) return false;
    if (f.eda  && rangoEdad(r.edad) !== f.eda) return false;
    if (f.del  && r.delegacion !== f.del) return false;
    if (f.col  && r.colonia !== f.col) return false;
    if (f.rec  && r.recibe_ciudadano !== f.rec) return false;
    if (f.crd === 'con' && !isCoordValid(r)) return false;
    if (f.crd === 'sin' &&  isCoordValid(r)) return false;
    return true;
  });
  document.getElementById('contador-filtrados').textContent =
    fmt(FILTRADOS.length) + ' de ' + fmt(window.PADRON.length) + ' registros';
  renderAll();
}
function limpiarFiltros(){
  ['f-fr-desde','f-fr-hasta','f-fe-desde','f-fe-hasta',
   'f-programa','f-coordinacion','f-tipo','f-lugar','f-sexo','f-edad',
   'f-delegacion','f-colonia','f-recibe','f-coords']
    .forEach(id => document.getElementById(id).value='');
  aplicarFiltros();
}
function exportCSV(){
  if (!FILTRADOS.length) return;
  const cols = ['id','fecha_registro','fecha_entrega','cantidad','programa','coordinacion',
                'tipo_apoyo','recibe_ciudadano','lugar_entrega','sexo','edad',
                'cp','delegacion','colonia','latitud','longitud'];
  const csv = [cols.join(',')].concat(
    FILTRADOS.map(r => cols.map(c => {
      const v = r[c]==null ? '' : String(r[c]).replace(/"/g,'""');
      return /[",\n]/.test(v) ? '"'+v+'"' : v;
    }).join(','))
  ).join('\n');
  const blob = new Blob(['﻿'+csv], {type:'text/csv;charset=utf-8'});
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = 'padron_filtrado_' + Date.now() + '.csv';
  a.click(); URL.revokeObjectURL(url);
}

// ======================================================================
// KPIs
// ======================================================================
function calcKPIs(rows){
  const benUnicos = new Set();
  const programas = new Set(), coords = new Set(), delegs = new Set(), colonias = new Set();
  let cantTotal = 0, conCoords=0, sinCoords=0, sinCurp=0;
  let recibeSi=0, recibeNo=0;
  const claves = new Map();
  for (const r of rows) {
    benUnicos.add(clavePersona(r));
    if (r.programa)    programas.add(r.programa);
    if (r.coordinacion)coords.add(r.coordinacion);
    if (r.delegacion)  delegs.add(r.delegacion);
    if (r.colonia)     colonias.add(r.colonia);
    cantTotal += +(r.cantidad||0);
    if (isCoordValid(r)) conCoords++; else sinCoords++;
    if (!r.curp) sinCurp++;
    if (r.recibe_ciudadano==='SI') recibeSi++;
    else if (r.recibe_ciudadano==='NO') recibeNo++;
    const k = clavePersona(r);
    claves.set(k, (claves.get(k)||0)+1);
  }
  let dup = 0;
  for (const v of claves.values()) if (v>1) dup += v;
  const totalRec = rows.length;
  return {
    total: totalRec,
    benUnicos: benUnicos.size,
    apoyosEnt: totalRec, // 1 fila = 1 entrega
    cantTotal,
    programas: programas.size,
    coords:    coords.size,
    delegs:    delegs.size,
    colonias:  colonias.size,
    conCoords, sinCoords,
    sinCurp,
    dup,
    promApoyos: benUnicos.size>0 ? (totalRec/benUnicos.size).toFixed(2) : 0,
    pctSi: pct(recibeSi, recibeSi+recibeNo),
    pctNo: pct(recibeNo, recibeSi+recibeNo),
  };
}

function renderKPIs(){
  const k = calcKPIs(FILTRADOS);
  const cards = [
    ['Total registros',         fmt(k.total)],
    ['Beneficiarios únicos',    fmt(k.benUnicos)],
    ['Apoyos entregados',       fmt(k.apoyosEnt)],
    ['Cantidad total',          fmt(k.cantTotal)],
    ['Programas activos',       fmt(k.programas)],
    ['Coordinaciones',          fmt(k.coords)],
    ['Delegaciones',            fmt(k.delegs)],
    ['Colonias',                fmt(k.colonias)],
    ['Con coordenadas',         fmt(k.conCoords), 'ok'],
    ['Sin coordenadas',         fmt(k.sinCoords), 'warn'],
    ['Sin CURP',                fmt(k.sinCurp),   k.sinCurp>0?'warn':''],
    ['Posibles duplicados',     fmt(k.dup),       k.dup>0?'err':'ok'],
    ['Apoyos/beneficiario',     k.promApoyos],
    ['% recibido por ciudadano',k.pctSi, 'ok'],
    ['% recibido por tercero',  k.pctNo],
  ];
  document.getElementById('kpis-main').innerHTML = cards.map(([l,v,c]) => {
    const dup = (l === 'Posibles duplicados');
    const attrs = dup ? ' clickable" onclick="verDuplicados()" title="Ver detalle de duplicados' : '';
    return `<div class="kpi${attrs}"><div class="lbl">${l}${dup?' ↗':''}</div><div class="val ${c||''}">${v}</div></div>`;
  }).join('');

  // Calidad de datos
  const totals = {
    sinCurp:       k.sinCurp,
    sinCoords:     k.sinCoords,
    sinEdad:       FILTRADOS.filter(r => r.edad==null||r.edad===''||r.edad===0).length,
    sinDeleg:      FILTRADOS.filter(r => !r.delegacion).length,
    sinCol:        FILTRADOS.filter(r => !r.colonia).length,
    sinFEnt:       FILTRADOS.filter(r => !r.fecha_entrega).length,
    dup:           k.dup,
  };
  const total = Math.max(1, FILTRADOS.length);
  const completitud = (1 - (
    (totals.sinCurp + totals.sinCoords + totals.sinEdad + totals.sinDeleg +
     totals.sinCol + totals.sinFEnt) / (total*6)
  )) * 100;

  const qcards = [
    ['Registros sin CURP',          totals.sinCurp, total],
    ['Sin coordenadas',             totals.sinCoords, total],
    ['Edad vacía',                  totals.sinEdad, total],
    ['Delegación vacía',            totals.sinDeleg, total],
    ['Colonia vacía',               totals.sinCol, total],
    ['Fecha de entrega vacía',      totals.sinFEnt, total],
    ['Posibles duplicados',         totals.dup, total],
    ['% completitud general',       completitud.toFixed(1)+'%', null],
  ];
  document.getElementById('calidad').innerHTML = qcards.map(([l,v,d]) => {
    let cls = 'ok'; let p = '';
    if (d != null) {
      const r = v/d;
      if (r > 0.25) cls = 'err';
      else if (r > 0.05) cls = 'warn';
      p = ' (' + pct(v,d) + ')';
    } else {
      const num = parseFloat(v);
      if (num < 70) cls = 'err';
      else if (num < 90) cls = 'warn';
    }
    const dup = (l === 'Posibles duplicados');
    const attrs = dup ? ' clickable" onclick="verDuplicados()" title="Ver detalle de duplicados' : '';
    return `<div class="qbox ${cls} semaforo${attrs}">
      <div class="lbl"><span class="dot"></span>${l}${dup?' ↗':''}</div>
      <div class="val">${typeof v==='number'?fmt(v):v}<span class="pct">${p}</span></div>
    </div>`;
  }).join('');
}

// ======================================================================
// GRÁFICAS
// ======================================================================
const charts = {};
function topN(rows, field, n=12){
  const m = {};
  for (const r of rows) {
    const v = r[field] || '(sin dato)';
    m[v] = (m[v]||0) + 1;
  }
  return Object.entries(m).sort((a,b)=>b[1]-a[1]).slice(0,n);
}
function distrib(rows, fn){
  const m = {};
  for (const r of rows) {
    const v = fn(r) || '(sin dato)';
    m[v] = (m[v]||0) + 1;
  }
  return Object.entries(m).sort((a,b)=>b[1]-a[1]);
}
function tendencia(rows, dateField){
  const m = {};
  for (const r of rows) {
    const k = monthKey(r[dateField]);
    if (!k) continue;
    m[k] = (m[k]||0)+1;
  }
  const keys = Object.keys(m).sort();
  return [keys, keys.map(k=>m[k])];
}
function buildBar(id, labels, data, color, horiz=false){
  if (charts[id]) charts[id].destroy();
  charts[id] = new Chart(document.getElementById(id), {
    type: 'bar',
    data: {labels, datasets:[{data, backgroundColor:color, borderRadius:4}]},
    options: {
      indexAxis: horiz?'y':'x',
      responsive:true, maintainAspectRatio:false,
      plugins:{legend:{display:false}},
      scales: { x:{ticks:{font:{size:11}}}, y:{ticks:{font:{size:11}}, beginAtZero:true} },
    }
  });
}
function buildDoughnut(id, labels, data){
  if (charts[id]) charts[id].destroy();
  charts[id] = new Chart(document.getElementById(id), {
    type: 'doughnut',
    data: {labels, datasets:[{data, backgroundColor: labels.map((_,i)=>PALETTE[i%PALETTE.length])}]},
    options: {
      responsive:true, maintainAspectRatio:false, cutout:'60%',
      plugins:{legend:{position:'right', labels:{font:{size:11}}}}
    }
  });
}
function buildLine(id, labels, data, color){
  if (charts[id]) charts[id].destroy();
  charts[id] = new Chart(document.getElementById(id), {
    type:'line',
    data:{labels, datasets:[{data, borderColor:color, backgroundColor:color+'33',
                              fill:true, tension:.3, pointRadius:3}]},
    options:{responsive:true, maintainAspectRatio:false,
             plugins:{legend:{display:false}},
             scales:{x:{ticks:{font:{size:10}, maxRotation:60, minRotation:0}}}}
  });
}

function renderCharts(){
  const r = FILTRADOS;
  let d;
  d = topN(r,'programa',12);
  buildBar('ch-programa', d.map(x=>x[0]), d.map(x=>x[1]), 'var(--chart1)'.replace(/.*/,'#254185'));
  d = topN(r,'tipo_apoyo',12);
  buildBar('ch-tipo', d.map(x=>x[0]), d.map(x=>x[1]), '#005ab2');
  d = topN(r,'coordinacion',10);
  buildBar('ch-coord', d.map(x=>x[0]), d.map(x=>x[1]), '#188a5b', true);

  // Beneficiarios únicos por delegación
  const benByDel = {};
  for (const x of r) {
    if (!x.delegacion) continue;
    benByDel[x.delegacion] = benByDel[x.delegacion] || new Set();
    benByDel[x.delegacion].add(clavePersona(x));
  }
  const dpairs = Object.entries(benByDel)
                       .map(([k,s])=>[k,s.size]).sort((a,b)=>b[1]-a[1]).slice(0,10);
  buildBar('ch-delegacion', dpairs.map(x=>x[0]), dpairs.map(x=>x[1]), '#d99000', true);

  d = topN(r,'colonia',10);
  buildBar('ch-colonia', d.map(x=>x[0]), d.map(x=>x[1]), '#2a9eda', true);

  d = distrib(r, x=>x.sexo);
  buildDoughnut('ch-sexo', d.map(x=>x[0]), d.map(x=>x[1]));

  d = distrib(r, x=>rangoEdad(x.edad));
  buildBar('ch-edad', d.map(x=>x[0]), d.map(x=>x[1]), '#1a2f63');

  d = topN(r,'lugar_entrega',10);
  buildBar('ch-lugar', d.map(x=>x[0]), d.map(x=>x[1]), '#5b667a', true);

  let [labs,vals] = tendencia(r,'fecha_registro');
  buildLine('ch-treg', labs, vals, '#254185');
  [labs,vals] = tendencia(r,'fecha_entrega');
  buildLine('ch-tent', labs, vals, '#188a5b');
}

// ======================================================================
// TABLA RESUMEN
// ======================================================================
function renderTablaResumen(){
  const m = new Map();
  for (const r of FILTRADOS) {
    const k = (r.programa||'') + '||' + (r.tipo_apoyo||'') + '||' + (r.coordinacion||'');
    if (!m.has(k)) m.set(k, {
      prog:r.programa||'', tipo:r.tipo_apoyo||'', coor:r.coordinacion||'',
      apoyos:0, ben:new Set(), cant:0, deleg:new Set(), col:new Set()
    });
    const x = m.get(k);
    x.apoyos++;
    x.ben.add(clavePersona(r));
    x.cant += +(r.cantidad||0);
    if (r.delegacion) x.deleg.add(r.delegacion);
    if (r.colonia)    x.col.add(r.colonia);
  }
  const rows = Array.from(m.values()).sort((a,b)=>b.apoyos-a.apoyos);
  document.querySelector('#tbl-resumen tbody').innerHTML = rows.map(x =>
    `<tr>
      <td>${escapeHtml(x.prog)}</td>
      <td>${escapeHtml(x.tipo)}</td>
      <td>${escapeHtml(x.coor)}</td>
      <td style="text-align:right">${fmt(x.apoyos)}</td>
      <td style="text-align:right">${fmt(x.ben.size)}</td>
      <td style="text-align:right">${fmt(x.cant)}</td>
      <td style="text-align:right">${fmt(x.deleg.size)}</td>
      <td style="text-align:right">${fmt(x.col.size)}</td>
    </tr>`
  ).join('');
}
function escapeHtml(s){ return String(s||'').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

// ======================================================================
// DETALLE DE POSIBLES DUPLICADOS (respeta los filtros actuales)
// ======================================================================
function gruposDuplicados(){
  const groups = new Map();
  for (const r of FILTRADOS){
    const k = clavePersona(r);
    if (!groups.has(k)) groups.set(k, []);
    groups.get(k).push(r);
  }
  return [...groups.values()].filter(arr => arr.length > 1).sort((a,b) => b.length - a.length);
}
function verDuplicados(){
  const grupos = gruposDuplicados();
  const nRec = grupos.reduce((s,a) => s + a.length, 0);
  document.getElementById('dup-sub').textContent =
    `${fmt(grupos.length)} grupo(s) · ${fmt(nRec)} registros que comparten la misma persona ` +
    `(misma CURP, o nombre + fecha de nacimiento + colonia). Respeta los filtros aplicados.`;
  const body = document.getElementById('dup-body');
  if (!grupos.length){
    body.innerHTML = '<p style="color:var(--ok);font-weight:600">No hay duplicados con los filtros actuales. 🎉</p>';
  } else {
    const MAX = 200;
    body.innerHTML = grupos.slice(0, MAX).map(arr => {
      const nombre = escapeHtml(arr[0].ciudadano || '(sin nombre)');
      const curp   = arr[0].curp ? ' · CURP ' + escapeHtml(arr[0].curp) : '';
      const filas = arr.map(r => `<tr>
          <td>${r.id ?? ''}</td>
          <td>${escapeHtml(r.programa||'')}</td>
          <td>${escapeHtml(r.tipo_apoyo||'')}</td>
          <td>${escapeHtml(r.fecha_entrega||'')}</td>
          <td style="text-align:right">${fmt(r.cantidad||0)}</td>
          <td>${escapeHtml(r.colonia||'')}</td>
          <td style="text-align:center">${isCoordValid(r)?'✓':'—'}</td>
        </tr>`).join('');
      return `<div class="dup-grp">
        <div class="grp-head">${nombre}${curp} — ${arr.length} registros</div>
        <table>
          <thead><tr><th>ID</th><th>Programa</th><th>Tipo de apoyo</th><th>Entrega</th>
            <th style="text-align:right">Cant.</th><th>Colonia</th><th style="text-align:center">Geo</th></tr></thead>
          <tbody>${filas}</tbody>
        </table>
      </div>`;
    }).join('') + (grupos.length > MAX
      ? `<p style="color:var(--muted-foreground)">Mostrando ${MAX} de ${fmt(grupos.length)} grupos. Acota con los filtros para ver el resto.</p>` : '');
  }
  document.getElementById('dup-overlay').classList.add('open');
}
function cerrarDuplicados(){ document.getElementById('dup-overlay').classList.remove('open'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarDuplicados(); });

// ======================================================================
// MAPA — Google Maps
// ======================================================================
let map = null;
let markers = [];
let infoWindow = null;
let cluster = null;
let mapReady = false;

// Clases que vienen del loader dinámico — hay que guardarlas explícitamente,
// ya NO se cuelgan de google.maps.xxx automáticamente.
let heatOverlay = null;          // deck.gl GoogleMapsOverlay (heatmap)
let LatLngClass = null;
let LatLngBoundsClass = null;
let AdvancedMarkerClass = null;          // marcador avanzado (reemplaza google.maps.Marker)
const MAP_ID = <?= json_encode($gmapsMapId) ?>;   // requerido por AdvancedMarkerElement

// Renderer de clusters con marcador avanzado (evita el google.maps.Marker legacy)
const qroClusterRenderer = {
  render: ({ count, position }) => {
    const size = 34 + Math.min(22, Math.log2(count + 1) * 6);
    const el = document.createElement('div');
    el.style.cssText =
      'display:flex;align-items:center;justify-content:center;border-radius:50%;' +
      'background:#254185;color:#fff;font:600 13px Montserrat,Arial,sans-serif;' +
      'border:2px solid #fff;box-shadow:0 2px 6px rgba(37,65,133,.35);' +
      'width:' + size + 'px;height:' + size + 'px';
    el.textContent = count;
    return new AdvancedMarkerClass({ position, content: el, zIndex: 1000 + count });
  }
};

async function initMap(){
  if (mapReady) return;
  try {
    const core = await google.maps.importLibrary("core");
    const mapsLib = await google.maps.importLibrary("maps");
    const markerLib = await google.maps.importLibrary("marker");

    LatLngClass         = core.LatLng;
    LatLngBoundsClass   = core.LatLngBounds;
    AdvancedMarkerClass = markerLib.AdvancedMarkerElement;

    map = new mapsLib.Map(document.getElementById('map'), {
      center: { lat: 20.5888, lng: -100.3899 },
      zoom: 11,
      mapId: MAP_ID,                 // habilita marcadores avanzados
      mapTypeId: 'roadmap',
      streetViewControl: false,
      fullscreenControl: true,
      gestureHandling: 'greedy',
    });
    infoWindow = new mapsLib.InfoWindow();
    mapReady = true;
  } catch (e) {
    document.getElementById('map').innerHTML =
      '<div style="padding:20px;color:#ce3a2b">No se pudo cargar Google Maps. ' +
      'Revisa que la <b>Maps JavaScript API</b> esté habilitada en Google Cloud Console ' +
      'y que la API key no tenga restricciones de referrer que bloqueen localhost.</div>';
    console.error(e);
    return;
  }
}

function clearMarkers(){
  if (cluster) { cluster.clearMarkers(); cluster = null; }
  markers.forEach(m => { m.map = null; });   // AdvancedMarkerElement
  markers = [];
  if (heatOverlay) { heatOverlay.setMap(null); heatOverlay = null; }   // deck.gl
}

function getMapMode(){
  const r = document.querySelector('input[name="ly-mode"]:checked');
  return r ? r.value : 'cluster';
}

async function renderMap(){
  await initMap();
  if (!mapReady) return;
  clearMarkers();

  const modo      = getMapMode();
  const usarColor = document.getElementById('ly-color').checked;

  // Mostrar/ocultar la opción de color (sólo aplica en modo cluster)
  document.getElementById('ly-color-wrap').style.display = (modo === 'cluster') ? 'flex' : 'none';

  const validos = FILTRADOS.filter(isCoordValid);

  if (modo === 'heatmap') {
    if (typeof deck === 'undefined' || !deck.GoogleMapsOverlay) {
      console.error('deck.gl no disponible: no se pudo cargar el script de deck.gl.');
    } else if (validos.length) {
      // Heatmap con deck.gl (reemplazo del HeatmapLayer removido de Google Maps)
      const capa = new deck.HeatmapLayer({
        id: 'dif-heat',
        data: validos,
        getPosition: r => [ +r.longitud, +r.latitud ],
        getWeight: 1,
        radiusPixels: 32,
        intensity: 1,
        threshold: 0.05,
        // Rampa en azules institucionales -> rojo para zonas calientes
        colorRange: [
          [42,158,218], [0,90,178], [37,65,133], [217,144,0], [206,58,43], [142,30,22]
        ],
      });
      heatOverlay = new deck.GoogleMapsOverlay({ layers: [capa] });
      heatOverlay.setMap(map);
    }
  } else {
    // Cluster: markers ligeros agrupados
    for (const r of validos) {
      const color = usarColor ? colorFor(r.programa||'(s/d)') : '#254185';
      // Contenido del marcador: punto circular de color (equivale al icono legacy)
      const dot = document.createElement('div');
      dot.style.cssText =
        'width:13px;height:13px;border-radius:50%;background:' + color +
        ';border:1.5px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.18);cursor:pointer';
      const m = new AdvancedMarkerClass({
        position: { lat: +r.latitud, lng: +r.longitud },
        content: dot,
        title: r.programa || '',
        gmpClickable: true,
      });
      // Click sobre el contenido DOM del marcador avanzado
      dot.addEventListener('click', () => {
        infoWindow.setContent(popupHtml(r));
        infoWindow.open({ map, anchor: m });
      });
      markers.push(m);
    }
    if (markers.length) {
      cluster = new markerClusterer.MarkerClusterer({ map, markers, renderer: qroClusterRenderer });
    }
  }

  // Ajustar bounds
  if (validos.length) {
    const bounds = new LatLngBoundsClass();
    validos.forEach(r => bounds.extend({ lat: +r.latitud, lng: +r.longitud }));
    map.fitBounds(bounds, 40);
    google.maps.event.addListenerOnce(map, 'idle', () => {
      if (map.getZoom() > 15) map.setZoom(15);
    });
  }
}

function popupHtml(r){
  // Sólo datos NO sensibles
  return `
    <div>
      <div><b>${escapeHtml(r.programa||'')}</b></div>
      <div>Tipo: ${escapeHtml(r.tipo_apoyo||'')}</div>
      <div>Coord.: ${escapeHtml(r.coordinacion||'')}</div>
      <div>Delegación: ${escapeHtml(r.delegacion||'')}</div>
      <div>Colonia: ${escapeHtml(r.colonia||'')}</div>
      <div>Entrega: ${escapeHtml(r.fecha_entrega||'')}</div>
      <div>Sexo: ${escapeHtml(r.sexo||'')} · Edad: ${rangoEdad(r.edad)}</div>
      <div>Cantidad: ${fmt(r.cantidad||0)}</div>
    </div>`;
}

function renderGeoKPIs(){
  const validos = FILTRADOS.filter(isCoordValid);
  const ben = new Set(validos.map(clavePersona));
  const cards = [
    ['Puntos en mapa', fmt(validos.length)],
    ['Beneficiarios', fmt(ben.size)],
    ['Sin coords', fmt(FILTRADOS.length - validos.length), 'warn'],
    ['Colonias', fmt(new Set(validos.map(r=>r.colonia).filter(Boolean)).size)],
  ];
  document.getElementById('geo-kpis').innerHTML = cards.map(([l,v,c]) =>
    `<div class="kpi"><div class="lbl">${l}</div><div class="val ${c||''}">${v}</div></div>`
  ).join('');
}
function renderRankings(){
  const colMap = {}, delMap = {};
  for (const r of FILTRADOS) {
    if (r.colonia)    colMap[r.colonia] = (colMap[r.colonia]||0)+1;
    if (r.delegacion) delMap[r.delegacion] = (delMap[r.delegacion]||0)+1;
  }
  const renderRank = (m,topId) => {
    const arr = Object.entries(m).sort((a,b)=>b[1]-a[1]).slice(0,10);
    document.getElementById(topId).innerHTML = arr.map(([k,v]) =>
      `<div class="row"><span>${escapeHtml(k)}</span><strong>${fmt(v)}</strong></div>`
    ).join('') || '<div class="row"><span>—</span></div>';
  };
  renderRank(colMap, 'rank-colonias');
  renderRank(delMap, 'rank-delegaciones');
}
function renderGeoTabla(){
  const rows = FILTRADOS.slice(0, 200);
  document.getElementById('geo-tbl').innerHTML = rows.map(r =>
    `<tr><td>${escapeHtml(r.programa||'')}</td>
         <td>${escapeHtml(r.colonia||'')}</td>
         <td style="text-align:right">${fmt(r.cantidad||0)}</td></tr>`
  ).join('');
}

// ======================================================================
// TABS
// ======================================================================
document.querySelectorAll('.tab').forEach(t => {
  t.addEventListener('click', async () => {
    document.querySelectorAll('.tab').forEach(x => x.classList.remove('active'));
    t.classList.add('active');
    document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
    const sec = document.getElementById('sec-'+t.dataset.tab);
    sec.classList.add('active');
    if (t.dataset.tab === 'geo') {
      // Esperamos un tick para que el div tenga su tamaño real, luego renderizamos
      await new Promise(r => setTimeout(r, 50));
      await renderMap();
    }
  });
});
document.querySelectorAll('input[name="ly-mode"]').forEach(r =>
  r.addEventListener('change', renderMap));
document.getElementById('ly-color').addEventListener('change', renderMap);

// ======================================================================
// RENDER TOTAL
// ======================================================================
function renderAll(){
  // Ejecutivo
  renderKPIs();
  renderCharts();
  renderTablaResumen();

  // Geo: panel lateral SIEMPRE se actualiza (es barato), no depende del mapa
  renderGeoKPIs();
  renderRankings();
  renderGeoTabla();

  // El mapa sólo si ya está inicializado (sólo se inicializa al visitar geo)
  if (mapReady) renderMap();
}

// ======================================================================
// DEFAULTS: filtros de fecha ABIERTOS (sin rango) — se muestran todos los
// registros al cargar. El usuario acota con los campos si lo necesita.
// ======================================================================
// (Sin valores por defecto: f-fe-desde / f-fe-hasta quedan vacíos = sin filtro)

// Inicial
aplicarFiltros();
</script>

<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
