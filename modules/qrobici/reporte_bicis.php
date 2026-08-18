<?php
/**
 * QroBici Analytics — Reporte de performance de bicicletas
 * ------------------------------------------------------------
 * Analiza la flota a nivel de bicicleta: cuáles son las más usadas,
 * cuáles llevan más mantenimientos, cuáles están ociosas, cuál es
 * la calidad percibida y la eficiencia operativa (km por
 * mantenimiento, viajes por mantenimiento, etc.).
 *
 * Independiente de los otros reportes. Reusa db, lib_viajes (solo
 * helpers de calificación) y lib_bicis (queries propias).
 */

declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib_viajes.php';      // qrb_calif_detecta_columnas
require_once __DIR__ . '/lib_bicis.php';

$cfg = require __DIR__ . '/config.php';

if (!empty($cfg['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}
date_default_timezone_set($cfg['zona_horaria'] ?? 'America/Mexico_City');

try {
    qrb_db($cfg);
} catch (Throwable $e) {
    if (!empty($cfg['debug'])) { throw $e; }
    http_response_code(500);
    die('Error de conexión. Revisa config.php');
}

// cache opcional (sólo para este reporte)
$cache_file = sys_get_temp_dir() . '/qrobici_bicis_cache.json';
$DATA = null;
$cache_seg = (int)($cfg['cache_segundos'] ?? 0);
if ($cache_seg > 0 && is_file($cache_file)
    && (time() - filemtime($cache_file)) < $cache_seg) {
    $DATA = json_decode(file_get_contents($cache_file), true);
}
if ($DATA === null) {
    $DATA = qrb_construye_dataset_bicis($cfg);
    if ($cache_seg > 0) {
        @file_put_contents($cache_file, json_encode($DATA, JSON_UNESCAPED_UNICODE));
    }
}

$JSON = json_encode($DATA, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$JSON = str_replace(['</', "\u{2028}", "\u{2029}"], ['<\/', ' ', ' '], $JSON);

?><?php
$ktTitle  = 'QroBici · Performance bicicletas';
$ktActive = 'qrobici';
require __DIR__ . '/../../views/layout/kt_top.php';
?><link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800;900&family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap">
<style>
:root {
  --azul:#005ab2;
  --azul-d:#254185;
  --azul-l:#e8f0ff;
  --azul-ll:#f4f8ff;
  --cielo:#5b8fd0;
  --tinta:#0a1b3d;
  --tinta-2:#3c4a6e;
  --gris:#6b7a98;
  --gris-l:#9aa5bd;
  --gris-2:#f4f6fb;
  --bd:#e6ecf5;
  --verde:#188a5b;
  --ambar:#d99000;
  --rojo:#ce3a2b;
  --rosa:#5b667a;
  --bg:#fbfcfe;
}
* { box-sizing:border-box; margin:0; padding:0; }
html, body { width:100%; }
body {
  font-family:'Montserrat', system-ui, sans-serif;
  background:var(--bg);
  color:var(--tinta);
  line-height:1.5;
  -webkit-font-smoothing:antialiased;
}
.wrap { max-width:1200px; margin:0 auto; padding:0 24px 80px; }

/* ===== HEADER ===== */
header.top {
  padding:32px 0 36px;
  border-bottom:1px solid var(--bd);
  margin-bottom:36px;
  background:linear-gradient(180deg, var(--azul-ll) 0%, transparent 100%);
}
header.top .row {
  display:flex; justify-content:space-between; align-items:flex-start;
  gap:24px; flex-wrap:wrap;
  max-width:1200px; margin:0 auto; padding:0 24px;
}
.brand { display:flex; align-items:center; gap:12px; }
.brand .logo {
  width:42px; height:42px; border-radius:11px;
  background:linear-gradient(135deg, var(--azul), var(--azul-d));
  color:#fff; font-family:'Archivo', sans-serif; font-weight:900;
  font-size:19px; letter-spacing:-1px;
  display:flex; align-items:center; justify-content:center;
  box-shadow:0 8px 22px rgba(0,87,255,.22);
}
.brand .name {
  font-family:'Archivo', sans-serif; font-weight:900;
  font-size:18px; letter-spacing:-.5px;
}
.brand .meta {
  font-family:'Space Mono', monospace;
  font-size:11px; color:var(--gris); margin-top:2px;
  letter-spacing:.4px; text-transform:uppercase;
}
.htitle {
  font-family:'Archivo', sans-serif;
  font-size:clamp(34px, 4.8vw, 56px);
  font-weight:900; letter-spacing:-.04em; line-height:1.02;
  max-width:780px; margin-top:24px;
}
.hsub {
  font-size:16px; color:var(--tinta-2);
  max-width:62ch; margin-top:14px; line-height:1.6;
}
.hbadges {
  display:flex; flex-wrap:wrap; gap:8px; margin-top:18px;
}
.hbadge {
  font-family:'Space Mono', monospace;
  font-size:11px; padding:5px 11px; border-radius:6px;
  background:#fff; border:1px solid var(--bd);
  color:var(--tinta-2); letter-spacing:.4px;
}
.hbadge b { color:var(--azul); font-weight:700; }

.backlink {
  display:inline-flex; align-items:center; gap:6px;
  font-family:'Space Mono', monospace;
  font-size:11px; letter-spacing:1px; text-transform:uppercase;
  color:var(--gris); text-decoration:none;
  padding:6px 12px; border-radius:8px;
  border:1px solid var(--bd); background:#fff;
  font-weight:700;
}
.backlink:hover { color:var(--azul); border-color:var(--azul); }

/* ===== KPIs ===== */
.kpis {
  display:grid;
  grid-template-columns:repeat(4, 1fr);
  gap:12px;
}
.kpi {
  background:#fff;
  border:1px solid var(--bd);
  border-radius:16px;
  padding:18px 18px 16px;
  position:relative;
  overflow:hidden;
}
.kpi.accent { border-left:4px solid var(--azul); }
.kpi .lbl {
  font-family:'Space Mono', monospace;
  font-size:10.5px; letter-spacing:1.5px;
  color:var(--gris); text-transform:uppercase;
}
.kpi .val {
  font-family:'Archivo', sans-serif;
  font-size:30px; font-weight:900;
  letter-spacing:-.03em; line-height:1;
  margin-top:7px;
  font-variant-numeric:tabular-nums;
}
.kpi .val .unit {
  font-family:'Space Mono', monospace;
  font-size:13px; color:var(--gris); font-weight:400;
  margin-left:5px;
}
.kpi .sub {
  font-family:'Space Mono', monospace;
  font-size:11px; color:var(--gris-l);
  margin-top:8px; letter-spacing:.3px;
}
.kpi.green .val { color:var(--verde); }
.kpi.amber .val { color:var(--ambar); }
.kpi.red   .val { color:var(--rojo); }
.kpi.azul  .val { color:var(--azul); }

/* ===== SECCIONES ===== */
section { padding:52px 0 0; }
.shead {
  display:flex; justify-content:space-between; align-items:flex-start;
  margin-bottom:22px; gap:16px; flex-wrap:wrap;
}
.skicker {
  font-family:'Space Mono', monospace;
  font-size:11.5px; font-weight:700;
  letter-spacing:.14em; color:var(--azul);
  text-transform:uppercase;
  margin-bottom:5px; display:inline-block;
}
.shead h2 {
  font-family:'Archivo', sans-serif;
  font-size:27px; font-weight:800;
  letter-spacing:-.03em;
}
.shead .desc {
  font-size:13.5px; color:var(--gris);
  max-width:44ch; line-height:1.55;
}
.card {
  background:#fff;
  border:1px solid var(--bd);
  border-radius:16px;
  padding:22px;
}
.ctitle {
  font-family:'Archivo', sans-serif;
  font-size:16px; font-weight:700;
  letter-spacing:-.02em;
  display:flex; align-items:center; justify-content:space-between;
  margin-bottom:4px;
}
.ctitle .tag {
  font-family:'Space Mono', monospace;
  font-size:11px; font-weight:700;
  background:var(--azul-ll); color:var(--azul-d);
  padding:3px 9px; border-radius:6px;
  letter-spacing:0;
}
.cdesc {
  font-size:13px; color:var(--gris); margin-bottom:14px;
  line-height:1.5;
}

.grid { display:grid; gap:14px; }
.g2 { grid-template-columns:repeat(2, 1fr); }
.g3 { grid-template-columns:repeat(3, 1fr); }
.g4 { grid-template-columns:repeat(4, 1fr); }

/* ===== TABLAS ===== */
.tbl {
  width:100%; border-collapse:collapse;
  font-size:13px;
}
.tbl th {
  text-align:left;
  font-family:'Space Mono', monospace;
  font-size:10px; letter-spacing:1.5px;
  color:var(--gris); text-transform:uppercase; font-weight:700;
  padding:10px 8px; border-bottom:1px solid var(--bd);
}
.tbl td {
  padding:10px 8px;
  border-bottom:1px solid var(--gris-2);
}
.tbl tr:last-child td { border-bottom:none; }
.tbl tr:hover td { background:var(--gris-2); }
.tbl .num {
  font-family:'Space Mono', monospace;
  font-weight:700; text-align:right;
}
.tbl .rank {
  display:inline-flex; align-items:center; justify-content:center;
  width:24px; height:24px; border-radius:6px;
  background:var(--gris-2); color:var(--gris);
  font-family:'Space Mono', monospace;
  font-size:11px; font-weight:700;
}
.tbl .rank.top { background:var(--azul-l); color:var(--azul-d); }
.pill {
  display:inline-block;
  font-family:'Space Mono', monospace;
  font-size:10.5px; font-weight:700;
  padding:3px 9px; border-radius:5px;
  letter-spacing:.3px;
}
.pill.ok    { background:rgba(0,184,124,.12);  color:var(--verde); }
.pill.warn  { background:rgba(255,149,0,.12);  color:var(--ambar); }
.pill.bad   { background:rgba(255,77,94,.12);  color:var(--rojo); }
.pill.azul  { background:var(--azul-ll);       color:var(--azul-d); }
.pill.gris  { background:var(--gris-2);        color:var(--gris); }

/* ===== BARRA HORIZONTAL ===== */
.bar-row {
  display:flex; align-items:center;
  gap:12px; margin-bottom:8px;
}
.bar-label {
  flex:0 0 130px;
  font-size:12.5px; color:var(--tinta-2);
  font-family:'Montserrat', sans-serif; font-weight:500;
}
.bar-track {
  flex:1; height:14px;
  background:var(--gris-2);
  border-radius:7px; overflow:hidden;
}
.bar-fill {
  height:100%;
  background:linear-gradient(90deg, var(--cielo), var(--azul));
  border-radius:7px;
  display:flex; align-items:center; justify-content:flex-end;
  padding-right:8px; color:#fff;
  font-family:'Space Mono', monospace;
  font-size:10.5px; font-weight:700;
  min-width:30px;
  transition:width .9s cubic-bezier(.16,.84,.42,1.05);
}
.bar-value {
  flex:0 0 60px;
  font-family:'Space Mono', monospace;
  font-size:12px; font-weight:700; text-align:right;
}

/* ===== DONUT ===== */
.donut-wrap {
  display:flex; align-items:center; gap:24px;
  margin-top:8px;
}
.donut-wrap svg { width:170px; height:170px; flex:0 0 170px; }
.donut-legend { display:flex; flex-direction:column; gap:9px; flex:1; }
.donut-row {
  display:flex; align-items:center; gap:10px;
  font-family:'Space Mono', monospace;
  font-size:12px; color:var(--tinta-2);
}
.donut-row b {
  font-family:'Archivo', sans-serif; font-weight:800;
  font-size:18px; color:var(--tinta);
  margin-left:auto;
}
.donut-dot {
  width:11px; height:11px; border-radius:3px;
}

/* ===== INSIGHTS ===== */
.insight {
  background:linear-gradient(135deg, var(--azul-ll), #fff);
  border:1px solid var(--bd);
  border-left:4px solid var(--azul);
  border-radius:14px;
  padding:18px 22px;
  display:flex; gap:14px; align-items:flex-start;
  margin-bottom:10px;
  font-size:14px; line-height:1.55; color:var(--tinta-2);
}
.insight.alta  { border-left-color:var(--rojo); }
.insight.media { border-left-color:var(--ambar); }
.insight.baja  { border-left-color:var(--verde); }
.insight .ico { font-size:22px; flex-shrink:0; line-height:1.2; }
.insight b { color:var(--tinta); }

/* ===== SVG charts ===== */
.chart { width:100%; height:auto; display:block; }
svg text { font-family:'Montserrat', sans-serif; }
svg .axlbl { font-size:10.5px; fill:var(--gris-l); }
svg .vlbl  { font-size:11px;   fill:var(--tinta); font-weight:700; font-family:'Space Mono', monospace; }
svg .gridline { stroke:var(--bd); stroke-width:1; stroke-dasharray:2,4; }

/* ===== FOOTER ===== */
footer {
  margin-top:60px;
  padding-top:24px;
  border-top:1px solid var(--bd);
  display:flex; justify-content:space-between;
  flex-wrap:wrap; gap:14px;
  font-family:'Space Mono', monospace;
  font-size:11px; color:var(--gris-l);
}

/* ===== RESPONSIVE ===== */
@media (max-width:900px) {
  .kpis, .g2, .g3, .g4 { grid-template-columns:1fr 1fr; }
  .htitle { font-size:36px; }
  .bar-label { flex:0 0 100px; font-size:11.5px; }
  .donut-wrap { flex-direction:column; align-items:flex-start; }
}
@media (max-width:560px) {
  .kpis, .g2, .g3, .g4 { grid-template-columns:1fr; }
  header.top { padding:22px 0 26px; }
}

/* ===== EMPTY ===== */
.empty {
  text-align:center;
  padding:48px 24px;
  color:var(--gris);
  font-size:14px;
}
</style>

<header class="top">
  <div class="row">
    <div class="brand">
      <div class="logo">Q</div>
      <div>
        <div class="name">QroBici · Performance</div>
        <div class="meta">Analítica operativa de la flota</div>
      </div>
    </div>
    <a class="backlink" href="index.php">← Inicio</a>
  </div>
  <div style="max-width:1200px;margin:0 auto;padding:0 24px">
    <h1 class="htitle" id="htitle">Cargando…</h1>
    <p class="hsub">Cada bicicleta de QroBici, medida por uso, salud operativa y percepción del usuario. Identifica las más rentables, las que requieren atención y las que están ociosas.</p>
    <div class="hbadges" id="hbadges"></div>
  </div>
</header>

<div class="wrap">

  <?php if (!empty($DATA['vacio'])): ?>
    <div class="empty">
      <h2 style="font-family:'Archivo',sans-serif;font-weight:900;font-size:24px;color:var(--tinta)">Sin datos para analizar</h2>
      <p style="margin-top:8px"><?= htmlspecialchars($DATA['mensaje'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
    </div>
  <?php else: ?>

  <!-- ============ 01 · FLOTA EN NÚMEROS ============ -->
  <section>
    <div class="shead">
      <div>
        <span class="skicker">01 · La flota en números</span>
        <h2>Tamaño y desempeño general</h2>
      </div>
      <div class="desc">Vista agregada de la operación: bicicletas con viajes en el periodo, kilometraje total acumulado, horas pedaleadas y volumen de mantenimientos.</div>
    </div>
    <div class="kpis" id="kpis"></div>
  </section>

  <!-- ============ 02 · COMPOSICIÓN ============ -->
  <section>
    <div class="shead">
      <div>
        <span class="skicker">02 · Composición</span>
        <h2>Estatus y tipo de bicicleta</h2>
      </div>
      <div class="desc">Cómo se distribuye la flota por estado operativo y por tipo (mecánica vs eléctrica). Útil para validar el balance de la inversión.</div>
    </div>
    <div class="grid g2">
      <div class="card">
        <div class="ctitle">Distribución por estatus</div>
        <div class="cdesc">Estado físico/operativo reportado en la BD para cada bicicleta</div>
        <div id="donut-estatus" class="donut-wrap"></div>
      </div>
      <div class="card">
        <div class="ctitle">Distribución por tipo</div>
        <div class="cdesc">Mecánicas vs eléctricas — proporción de la flota</div>
        <div id="donut-tipo" class="donut-wrap"></div>
      </div>
    </div>
  </section>

  <!-- ============ 03 · TOP USADAS ============ -->
  <section>
    <div class="shead">
      <div>
        <span class="skicker">03 · Las que cargan la operación</span>
        <h2>Top 20 bicicletas por kilometraje</h2>
      </div>
      <div class="desc">Las más utilizadas en el periodo. Estas concentran el mayor desgaste físico y son las que más rápido requerirán mantenimiento o reemplazo.</div>
    </div>
    <div class="card">
      <div style="overflow-x:auto"><table class="tbl" id="tbl-top"></table></div>
    </div>
  </section>

  <!-- ============ 04 · USO POR BICICLETA ============ -->
  <section>
    <div class="shead">
      <div>
        <span class="skicker">04 · Distribución de uso</span>
        <h2>Cuántos viajes carga cada bicicleta</h2>
      </div>
      <div class="desc">Cuántas bicicletas caen en cada rango de viajes. Una flota saludable tiene la mayor parte en los rangos medios; muchas con 0 viajes indica subutilización.</div>
    </div>
    <div class="card">
      <div class="ctitle">Histograma de viajes por bicicleta</div>
      <div class="cdesc">Cada barra cuenta cuántas bicis hicieron esa cantidad de viajes</div>
      <div id="chart-uso" style="margin-top:8px"></div>
    </div>
  </section>

  <!-- ============ 05 · MANTENIMIENTOS ============ -->
  <section>
    <div class="shead">
      <div>
        <span class="skicker">05 · Salud operativa</span>
        <h2>Mantenimientos y eficiencia</h2>
      </div>
      <div class="desc">Distribución de intervenciones por bicicleta y ranking de las que más mantenimientos llevan. El ratio km/mantenimiento detecta bicis problemáticas.</div>
    </div>
    <div class="grid g2">
      <div class="card">
        <div class="ctitle">Mantenimientos por bicicleta</div>
        <div class="cdesc">Cuántas bicis tienen cada nivel de intervenciones</div>
        <div id="chart-mant" style="margin-top:8px"></div>
      </div>
      <div class="card">
        <div class="ctitle">Bicicletas con más mantenimientos</div>
        <div class="cdesc">Candidatas a auditoría de partes o baja</div>
        <div style="overflow-x:auto"><table class="tbl" id="tbl-mant"></table></div>
      </div>
    </div>
    <div class="card" style="margin-top:14px">
      <div class="ctitle">Mantenimientos vs km recorridos <span class="tag">scatter</span></div>
      <div class="cdesc">Cada punto es una bicicleta. Esquina superior-izquierda = mucha intervención con pocos km (problemáticas). Esquina inferior-derecha = muchos km sin intervención (al día con mantenimiento preventivo o ya por venir).</div>
      <div id="chart-scatter" style="margin-top:8px"></div>
    </div>
  </section>

  <!-- ============ 06 · OCIOSAS ============ -->
  <section>
    <div class="shead">
      <div>
        <span class="skicker">06 · Bicicletas ociosas</span>
        <h2>Días sin generar valor</h2>
      </div>
      <div class="desc">Bicicletas que llevan más tiempo sin un viaje. Pueden estar en mantenimiento prolongado, mal ubicadas o requerir rebalanceo a estaciones más demandadas.</div>
    </div>
    <div class="card">
      <div style="overflow-x:auto"><table class="tbl" id="tbl-ociosas"></table></div>
    </div>
  </section>

  <!-- ============ 07 · CALIDAD PERCIBIDA ============ -->
  <section>
    <div class="shead">
      <div>
        <span class="skicker">07 · Calidad percibida</span>
        <h2>Lo que opinan los usuarios de cada bici</h2>
      </div>
      <div class="desc">Bicicletas con calificación promedio más baja por usuarios que efectivamente las usaron y respondieron la encuesta. Mínimo 5 respuestas para que sea estadísticamente útil.</div>
    </div>
    <div class="card">
      <div style="overflow-x:auto"><table class="tbl" id="tbl-calif"></table></div>
    </div>
  </section>

  <!-- ============ 08 · INSIGHTS ============ -->
  <section>
    <div class="shead">
      <div>
        <span class="skicker">08 · Lo que hay que mirar</span>
        <h2>Hallazgos y oportunidades</h2>
      </div>
      <div class="desc">Conclusiones derivadas automáticamente de los KPIs del periodo. Lo que normalmente pasaría por alto un dashboard.</div>
    </div>
    <div id="insights"></div>
  </section>

  <footer>
    <div>QroBici Analytics · Performance bicicletas</div>
    <div>Generado <?= date('d/m/Y H:i') ?></div>
  </footer>

  <?php endif; ?>
</div>

<script>
const DATA = <?= $JSON ?>;

const $ = id => document.getElementById(id);
const fmt  = n => Number(n).toLocaleString('es-MX');
const fmt1 = n => Number(n).toLocaleString('es-MX', {maximumFractionDigits:1, minimumFractionDigits:1});
const fmt2 = n => Number(n).toLocaleString('es-MX', {maximumFractionDigits:2, minimumFractionDigits:2});

// Paleta de azules para charts
const BLUES = ['#cfe0f3','#9bbfe6','#5b8fd0','#254185','#1a2f63','#13234d','#0d1733'];

/* ===== HEADER ===== */
function renderHeader(){
  if (DATA.vacio) return;
  const k = DATA.kpis;
  $('htitle').textContent = `${fmt(k.total_bicis)} bicicletas, ${fmt1(k.km_total_flota)} km de operación`;
  $('hbadges').innerHTML = `
    <span class="hbadge"><b>${fmt(k.viajes_total)}</b> viajes</span>
    <span class="hbadge"><b>${fmt1(k.horas_total_flota)} h</b> de uso</span>
    <span class="hbadge"><b>${fmt(k.mantenimientos_total)}</b> mantenimientos</span>
    <span class="hbadge"><b>${k.bicis_ociosas_30d}</b> bicis ociosas (+30d)</span>
  `;
}

/* ===== KPIS ===== */
function renderKpis(){
  if (DATA.vacio) return;
  const k = DATA.kpis;
  const items = [
    {lbl:'Bicicletas activas',   val:fmt(k.total_bicis),  sub:'con al menos 1 viaje', cls:'azul accent'},
    {lbl:'Km totales',           val:fmt1(k.km_total_flota), unit:'km', sub:`${fmt1(k.km_prom_por_bici)} km prom · bici`, cls:'green'},
    {lbl:'Viajes totales',       val:fmt(k.viajes_total), sub:`${fmt(k.viajes_prom_por_bici)} prom · ${fmt(k.viajes_mediana)} mediana`, cls:'azul'},
    {lbl:'Mantenimientos',       val:fmt(k.mantenimientos_total), sub:`${fmt2(k.mant_prom_por_bici)} prom · bici`, cls:'amber'},
    {lbl:'Ociosas (+30d)',       val:fmt(k.bicis_ociosas_30d), sub:`${k.pct_ociosas}% de la flota`, cls:k.pct_ociosas >= 25 ? 'red' : (k.pct_ociosas >= 10 ? 'amber' : 'green')},
    {lbl:'Km por mantenimiento', val: k.km_por_mant_global !== null ? fmt(k.km_por_mant_global) : '—', unit:'km', sub:'ratio operativo', cls:'azul'},
    {lbl:'Mecánicas',            val:fmt(k.tipo_mecanica), sub:`${k.total_bicis>0 ? Math.round(100*k.tipo_mecanica/k.total_bicis) : 0}% de la flota`, cls:'azul'},
    {lbl:'Calidad promedio',     val: k.calif_global_prom !== null ? fmt2(k.calif_global_prom) : '—', sub: k.calif_global_n > 0 ? `${fmt(k.calif_global_n)} respuestas` : 'sin respuestas', cls:'green'},
  ];
  $('kpis').innerHTML = items.map(i => `
    <div class="kpi ${i.cls||''}">
      <div class="lbl">${i.lbl}</div>
      <div class="val">${i.val}${i.unit?`<span class="unit">${i.unit}</span>`:''}</div>
      <div class="sub">${i.sub||''}</div>
    </div>`).join('');
}

/* ===== DONUT genérico ===== */
function renderDonut(elId, data, getKey, getVal, palette){
  const tot = data.reduce((s,d) => s + getVal(d), 0) || 1;
  const c = 2 * Math.PI * 60;
  let offset = 0;
  const slices = data.map((d, i) => {
    const v = getVal(d);
    const frac = v / tot;
    const dash = frac * c;
    const col = palette[i % palette.length];
    const html = `<circle cx="85" cy="85" r="60" fill="none" stroke="${col}" stroke-width="20"
      stroke-dasharray="${dash} ${c}" stroke-dashoffset="${-offset}"
      transform="rotate(-90 85 85)" stroke-linecap="butt"/>`;
    offset += dash;
    return {html, key: getKey(d), val: v, pct: Math.round(100*frac), col};
  });
  const svg = `<svg viewBox="0 0 170 170">
    <circle cx="85" cy="85" r="60" fill="none" stroke="${'#e6ecf5'}" stroke-width="20"/>
    ${slices.map(s => s.html).join('')}
  </svg>`;
  const legend = slices.map(s => `
    <div class="donut-row">
      <span class="donut-dot" style="background:${s.col}"></span>
      <span style="flex:1">${s.key}</span>
      <span style="color:var(--gris)">${s.pct}%</span>
      <b>${fmt(s.val)}</b>
    </div>`).join('');
  $(elId).innerHTML = svg + `<div class="donut-legend">${legend}</div>`;
}

/* ===== COMPOSICIÓN ===== */
function renderComposicion(){
  if (DATA.vacio) return;
  renderDonut('donut-estatus', DATA.por_estatus, d => d.estatus, d => d.bicis,
    ['#254185','#5b8fd0','#d99000','#ce3a2b','#9bbfe6','#d9e2f0']);
  renderDonut('donut-tipo', DATA.por_tipo, d => d.tipo, d => d.bicis,
    ['#254185','#5b667a','#d9e2f0']);
}

/* ===== TABLA TOP USADAS ===== */
function renderTop(){
  if (DATA.vacio) return;
  const rows = DATA.top_usadas;
  if (!rows.length) { $('tbl-top').innerHTML = '<tr><td class="empty">Sin datos</td></tr>'; return; }
  $('tbl-top').innerHTML = `
    <thead><tr>
      <th>#</th><th>ID</th><th>Serie</th><th>Tipo</th><th>Estatus</th>
      <th class="num">Viajes</th><th class="num">Km</th><th class="num">Horas</th>
      <th class="num">Mant.</th><th class="num">Km/mant</th><th>Calif.</th>
    </tr></thead>
    <tbody>${rows.map((r,i) => `
      <tr>
        <td><span class="rank ${i<3?'top':''}">${i+1}</span></td>
        <td style="font-weight:700">#${r.id}</td>
        <td><span class="pill gris">${escapeHtml(r.serie || '—')}</span></td>
        <td>${tipoPill(r.tipo)}</td>
        <td>${estatusPill(r.estatus)}</td>
        <td class="num">${fmt(r.viajes)}</td>
        <td class="num" style="color:var(--azul-d)">${fmt1(r.km_total)}</td>
        <td class="num">${fmt1(r.horas_total)}</td>
        <td class="num">${r.mantenimientos}</td>
        <td class="num">${r.km_por_mant !== null ? fmt(r.km_por_mant) : '—'}</td>
        <td>${califPill(r.calif_prom, r.calif_n)}</td>
      </tr>`).join('')}</tbody>`;
}

/* ===== HISTOGRAMA USO ===== */
function renderUso(){
  if (DATA.vacio) return;
  const data = DATA.dist_uso;
  const max = Math.max(...data.map(d => d.cantidad)) || 1;
  const W = 760, H = 220, padT = 20, padB = 36, padL = 36, padR = 16;
  const iw = W - padL - padR, ih = H - padT - padB;
  const n = data.length, bw = iw / n * 0.7;
  let svg = '';
  for (let g = 0; g <= 4; g++) {
    const y = padT + ih - ih * g / 4;
    svg += `<line class="gridline" x1="${padL}" y1="${y}" x2="${W - padR}" y2="${y}"/>`;
    svg += `<text class="axlbl" x="${padL - 8}" y="${y + 3}" text-anchor="end">${Math.round(max * g / 4)}</text>`;
  }
  data.forEach((d, i) => {
    const cx = padL + iw * (i + 0.5) / n;
    const bh = (d.cantidad / max) * ih;
    const y = padT + ih - bh;
    const col = i === 0 ? 'var(--gris-l)' : BLUES[Math.min(2 + i, 6)];
    svg += `<rect x="${cx - bw/2}" y="${y}" width="${bw}" height="${bh}" rx="4" fill="${col}"><title>${d.rango}: ${d.cantidad} bicis</title></rect>`;
    if (bh > 16) svg += `<text class="vlbl" x="${cx}" y="${y - 5}" text-anchor="middle">${d.cantidad}</text>`;
    svg += `<text class="axlbl" x="${cx}" y="${H - 12}" text-anchor="middle">${escapeHtml(d.rango)}</text>`;
  });
  $('chart-uso').innerHTML = `<svg viewBox="0 0 ${W} ${H}" class="chart">${svg}</svg>`;
}

/* ===== HISTOGRAMA MANTENIMIENTOS ===== */
function renderMant(){
  if (DATA.vacio) return;
  const data = DATA.dist_mant;
  const max = Math.max(...data.map(d => d.cantidad)) || 1;
  const W = 380, H = 200, padT = 16, padB = 32, padL = 32, padR = 14;
  const iw = W - padL - padR, ih = H - padT - padB;
  const n = data.length, bw = iw / n * 0.72;
  let svg = '';
  data.forEach((d, i) => {
    const cx = padL + iw * (i + 0.5) / n;
    const bh = (d.cantidad / max) * ih;
    const y = padT + ih - bh;
    const col = i === 0 ? 'var(--verde)' : i <= 2 ? 'var(--cielo)' : i <= 4 ? 'var(--ambar)' : 'var(--rojo)';
    svg += `<rect x="${cx - bw/2}" y="${y}" width="${bw}" height="${bh}" rx="4" fill="${col}"><title>${d.rango} mantenimientos: ${d.cantidad} bicis</title></rect>`;
    if (bh > 14) svg += `<text class="vlbl" x="${cx}" y="${y - 4}" text-anchor="middle" style="font-size:10px">${d.cantidad}</text>`;
    svg += `<text class="axlbl" x="${cx}" y="${H - 10}" text-anchor="middle">${escapeHtml(d.rango)}</text>`;
  });
  $('chart-mant').innerHTML = `<svg viewBox="0 0 ${W} ${H}" class="chart">${svg}</svg>`;

  // Tabla
  const rows = DATA.mas_mantenidas;
  if (!rows.length) {
    $('tbl-mant').innerHTML = '<tr><td class="empty">Ninguna bicicleta con mantenimientos registrados</td></tr>';
    return;
  }
  $('tbl-mant').innerHTML = `
    <thead><tr><th>#</th><th>ID</th><th>Serie</th><th class="num">Mant.</th><th class="num">Km</th><th class="num">Km/mant</th></tr></thead>
    <tbody>${rows.map((r,i) => `
      <tr>
        <td><span class="rank ${i<3?'top':''}">${i+1}</span></td>
        <td style="font-weight:700">#${r.id}</td>
        <td style="font-family:'Space Mono',monospace;font-size:11.5px;color:var(--gris)">${escapeHtml(r.serie || '—')}</td>
        <td class="num"><span class="pill ${r.mantenimientos>=10?'bad':r.mantenimientos>=5?'warn':'azul'}">${r.mantenimientos}</span></td>
        <td class="num">${fmt1(r.km_total)}</td>
        <td class="num">${r.km_por_mant !== null ? fmt(r.km_por_mant) : '—'}</td>
      </tr>`).join('')}</tbody>`;
}

/* ===== SCATTER km vs mantenimientos ===== */
function renderScatter(){
  if (DATA.vacio) return;
  const data = DATA.scatter_km_mant;
  if (!data.length) { $('chart-scatter').innerHTML = '<div class="empty">Sin datos</div>'; return; }
  const W = 760, H = 320, padT = 24, padB = 44, padL = 50, padR = 20;
  const iw = W - padL - padR, ih = H - padT - padB;
  const maxKm = Math.max(...data.map(d => d.km)) || 1;
  const maxMt = Math.max(...data.map(d => d.mant)) || 1;
  let svg = '';
  // grid horizontal
  for (let g = 0; g <= 4; g++) {
    const y = padT + ih - ih * g / 4;
    svg += `<line class="gridline" x1="${padL}" y1="${y}" x2="${W - padR}" y2="${y}"/>`;
    svg += `<text class="axlbl" x="${padL - 8}" y="${y + 3}" text-anchor="end">${Math.round(maxMt * g / 4)}</text>`;
  }
  // grid vertical
  for (let g = 0; g <= 4; g++) {
    const x = padL + iw * g / 4;
    svg += `<line class="gridline" x1="${x}" y1="${padT}" x2="${x}" y2="${padT + ih}"/>`;
    svg += `<text class="axlbl" x="${x}" y="${H - 22}" text-anchor="middle">${Math.round(maxKm * g / 4)} km</text>`;
  }
  // ejes labels
  svg += `<text class="axlbl" x="${padL - 40}" y="${padT + ih/2}" text-anchor="middle" transform="rotate(-90 ${padL - 40} ${padT + ih/2})" style="font-size:11px;font-weight:600">Mantenimientos</text>`;
  svg += `<text class="axlbl" x="${padL + iw/2}" y="${H - 6}" text-anchor="middle" style="font-size:11px;font-weight:600">Kilómetros totales</text>`;
  // puntos
  data.forEach(d => {
    const x = padL + iw * (d.km / maxKm);
    const y = padT + ih - ih * (d.mant / maxMt);
    const tipo_norm = (d.tipo || '').toUpperCase()
      .replace(/Á/g,'A').replace(/É/g,'E').replace(/Í/g,'I').replace(/Ó/g,'O').replace(/Ú/g,'U');
    const col = tipo_norm.includes('ELEC') ? '#5b667a' : '#254185';
    const r = 3 + Math.min(6, Math.sqrt(d.viajes/5));
    svg += `<circle cx="${x}" cy="${y}" r="${r}" fill="${col}" fill-opacity="0.55" stroke="${col}" stroke-width="1"><title>Bici #${d.id}\n${d.km} km · ${d.mant} mantenimientos · ${d.viajes} viajes</title></circle>`;
  });
  // leyenda
  svg += `<rect x="${W - 200}" y="${padT}" width="184" height="44" rx="6" fill="#fff" stroke="var(--bd)"/>`;
  svg += `<circle cx="${W - 188}" cy="${padT + 14}" r="5" fill="#254185" fill-opacity="0.55" stroke="#254185"/>`;
  svg += `<text x="${W - 178}" y="${padT + 18}" style="font-size:11px;fill:var(--tinta)">Mecánica</text>`;
  svg += `<circle cx="${W - 100}" cy="${padT + 14}" r="5" fill="#5b667a" fill-opacity="0.55" stroke="#5b667a"/>`;
  svg += `<text x="${W - 90}" y="${padT + 18}" style="font-size:11px;fill:var(--tinta)">Eléctrica</text>`;
  svg += `<text x="${W - 188}" y="${padT + 36}" class="axlbl" style="font-size:10px">tamaño ∝ viajes</text>`;
  $('chart-scatter').innerHTML = `<svg viewBox="0 0 ${W} ${H}" class="chart">${svg}</svg>`;
}

/* ===== TABLA OCIOSAS ===== */
function renderOciosas(){
  if (DATA.vacio) return;
  const rows = DATA.ociosas;
  if (!rows.length) {
    $('tbl-ociosas').innerHTML = '<tr><td class="empty">No hay bicicletas ociosas en el periodo</td></tr>';
    return;
  }
  $('tbl-ociosas').innerHTML = `
    <thead><tr>
      <th>#</th><th>ID</th><th>Serie</th><th>Tipo</th><th>Estatus</th>
      <th class="num">Último uso</th><th class="num">Días sin uso</th>
      <th class="num">Viajes históricos</th><th class="num">Km hist.</th>
    </tr></thead>
    <tbody>${rows.map((r,i) => {
      const dias = r.dias_sin_uso;
      let cls = 'warn';
      if (dias === null) cls = 'bad';
      else if (dias > 60) cls = 'bad';
      else if (dias > 14) cls = 'warn';
      else cls = 'azul';
      const diasLbl = dias === null ? 'sin registro' : `${dias} d`;
      return `<tr>
        <td><span class="rank">${i+1}</span></td>
        <td style="font-weight:700">#${r.id}</td>
        <td style="font-family:'Space Mono',monospace;font-size:11.5px;color:var(--gris)">${escapeHtml(r.serie || '—')}</td>
        <td>${tipoPill(r.tipo)}</td>
        <td>${estatusPill(r.estatus)}</td>
        <td class="num">${r.ultimo_viaje ? r.ultimo_viaje.split(' ')[0] : '—'}</td>
        <td class="num"><span class="pill ${cls}">${diasLbl}</span></td>
        <td class="num">${fmt(r.viajes)}</td>
        <td class="num">${fmt1(r.km_total)}</td>
      </tr>`;
    }).join('')}</tbody>`;
}

/* ===== TABLA PEOR CALIFICADAS ===== */
function renderCalif(){
  if (DATA.vacio) return;
  const rows = DATA.peor_calif;
  if (!rows.length) {
    $('tbl-calif').innerHTML = '<tr><td class="empty">No hay bicicletas con suficientes calificaciones para clasificar (mínimo 5 respuestas).</td></tr>';
    return;
  }
  $('tbl-calif').innerHTML = `
    <thead><tr>
      <th>#</th><th>ID</th><th>Serie</th><th>Tipo</th>
      <th class="num">Calificación</th><th class="num">Respuestas</th>
      <th class="num">Viajes</th><th class="num">Mant.</th>
    </tr></thead>
    <tbody>${rows.map((r,i) => `
      <tr>
        <td><span class="rank">${i+1}</span></td>
        <td style="font-weight:700">#${r.id}</td>
        <td style="font-family:'Space Mono',monospace;font-size:11.5px;color:var(--gris)">${escapeHtml(r.serie || '—')}</td>
        <td>${tipoPill(r.tipo)}</td>
        <td class="num">${califPill(r.calif_prom, r.calif_n)}</td>
        <td class="num">${fmt(r.calif_n)}</td>
        <td class="num">${fmt(r.viajes)}</td>
        <td class="num">${r.mantenimientos}</td>
      </tr>`).join('')}</tbody>`;
}

/* ===== INSIGHTS ===== */
function renderInsights(){
  if (DATA.vacio) return;
  $('insights').innerHTML = DATA.insights.map(i => `
    <div class="insight ${i.level||''}">
      <div class="ico">${i.icon}</div>
      <div>${i.texto}</div>
    </div>`).join('');
}

/* ===== HELPERS visuales ===== */
function escapeHtml(s){
  return (s == null ? '' : String(s)).replace(/[&<>"]/g, c =>
    ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
}
function tipoPill(tipo){
  if (!tipo) return '<span class="pill gris">—</span>';
  const n = tipo.toUpperCase()
    .replace(/Á/g,'A').replace(/É/g,'E').replace(/Í/g,'I').replace(/Ó/g,'O').replace(/Ú/g,'U');
  if (n.includes('ELEC')) return `<span class="pill" style="background:rgba(255,111,165,.12);color:#c54382">⚡ ${escapeHtml(tipo)}</span>`;
  return `<span class="pill azul">🚲 ${escapeHtml(tipo)}</span>`;
}
function estatusPill(estatus){
  if (!estatus) return '<span class="pill gris">—</span>';
  const n = estatus.toUpperCase()
    .replace(/Á/g,'A').replace(/É/g,'E').replace(/Í/g,'I').replace(/Ó/g,'O').replace(/Ú/g,'U');
  if (n.includes('OPER') || n.includes('ACTIV') || n.includes('DISPON')) return `<span class="pill ok">${escapeHtml(estatus)}</span>`;
  if (n.includes('MANTEN') || n.includes('REPAR')) return `<span class="pill warn">${escapeHtml(estatus)}</span>`;
  if (n.includes('BAJA') || n.includes('FUERA') || n.includes('PERDID')) return `<span class="pill bad">${escapeHtml(estatus)}</span>`;
  return `<span class="pill gris">${escapeHtml(estatus)}</span>`;
}
function califPill(prom, n){
  if (prom === null || prom === undefined) return '<span class="pill gris">—</span>';
  // detectar escala (5 o 10) por el max observado en DATA
  let escala = 5;
  DATA.bicis.forEach(b => { if (b.calif_prom !== null && b.calif_prom > escala) escala = b.calif_prom; });
  if (escala > 5 && escala <= 10) escala = 10;
  const pct = prom / escala;
  const cls = pct >= 0.8 ? 'ok' : pct >= 0.6 ? 'warn' : 'bad';
  return `<span class="pill ${cls}">${fmt2(prom)}/${escala}</span>`;
}

/* ===== BOOT ===== */
function renderAll(){
  renderHeader(); renderKpis(); renderComposicion();
  renderTop(); renderUso(); renderMant(); renderScatter();
  renderOciosas(); renderCalif(); renderInsights();
}
renderAll();
</script>

<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
