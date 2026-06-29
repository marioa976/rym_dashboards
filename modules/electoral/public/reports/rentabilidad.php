<?php
/**
 * Reporte: Rentabilidad electoral con análisis estratégico.
 *
 * Definiciones:
 *   - voto_efectivo_pan  = PAN puro + coaliciones que incluyen PAN
 *   - rentabilidad %     = voto_efectivo_pan / votos_validos × 100
 *   - participacion %    = total_emitidos / lista_nominal × 100
 *   - Δ rentabilidad     = rentabilidad_actual - rentabilidad_anterior (en puntos porcentuales)
 *
 * Cruces:
 *   - Tendencia 2024 vs 2021 (mismo tipo de elección)
 *
 * Cuadrantes (BCG electoral):
 *   - Estrellas: rentabilidad alta + creciendo  → mantener
 *   - Riesgo:    rentabilidad alta + cayendo    → defender
 *   - Oportunidad: rentabilidad baja + creciendo → invertir
 *   - Crítica:   rentabilidad baja + cayendo    → reconstruir
 */
$REQUIRE_ROLES = ['administrador', 'gerente', 'cliente'];
require_once __DIR__ . '/../../lib/bootstrap.php';

$pdo  = reporteador_pdo();
$BASE = reporteador_base_url_safe();
$U    = auth_user();

/* ---------------------------- Filtros ---------------------------- */
$procesoId   = (int)($_GET['proceso_id'] ?? 0);
$tipoId      = (int)($_GET['tipo_id'] ?? 0);
$ambitoCode  = trim($_GET['ambito_codigo'] ?? '');
$secSearch   = trim($_GET['sec_search'] ?? '');

if (!$procesoId) $procesoId = (int)$pdo->query("SELECT id FROM procesos_electorales WHERE anio=2024 AND nivel='estatal' LIMIT 1")->fetchColumn();
if (!$tipoId)    $tipoId    = (int)$pdo->query("SELECT id FROM tipos_eleccion WHERE codigo='diputacion_mr_loc' LIMIT 1")->fetchColumn();

$procesos = $pdo->query("SELECT id, anio, nivel, descripcion FROM procesos_electorales ORDER BY anio DESC, nivel")->fetchAll();
$tipos    = $pdo->query("SELECT id, codigo, nombre, ambito FROM tipos_eleccion ORDER BY nivel, ambito")->fetchAll();

$ambitos = $pdo->prepare(
    "SELECT ambito_codigo, ambito_nombre FROM elecciones
      WHERE proceso_id=? AND tipo_id=?
   ORDER BY CAST(ambito_codigo AS UNSIGNED), ambito_nombre"
);
$ambitos->execute([$procesoId, $tipoId]);
$ambitos = $ambitos->fetchAll();

$tipoActual    = $pdo->prepare("SELECT codigo, nombre, ambito FROM tipos_eleccion WHERE id=?"); $tipoActual->execute([$tipoId]); $tipoActual = $tipoActual->fetch();
$procesoActual = $pdo->prepare("SELECT anio, nivel, descripcion FROM procesos_electorales WHERE id=?"); $procesoActual->execute([$procesoId]); $procesoActual = $procesoActual->fetch();

/* ---------------------------- Función central de métricas ---------------------------- */
// Extraída a lib para reusarse en el cruce con padrón/Zendesk (Fase 4).
require_once __DIR__ . '/../../lib/electoral_metrics.php';

/* ---------------------------- Datos ---------------------------- */
$actual = metricas_por_seccion($pdo, $procesoId, $tipoId, $ambitoCode ?: null);
$rowsAct = $actual['rows'];
$panCoalCodes = $actual['panCoal'];

// Comparación con elección anterior del mismo tipo
$st = $pdo->prepare("SELECT id, anio FROM procesos_electorales WHERE anio<? AND nivel=? ORDER BY anio DESC LIMIT 1");
$st->execute([(int)$procesoActual['anio'], $procesoActual['nivel']]);
$prevProc = $st->fetch();

$rowsPrev = [];
$prevAnio = null;
if ($prevProc) {
    // Verificar que el tipo de elección exista en el proceso anterior
    $st = $pdo->prepare("SELECT 1 FROM elecciones WHERE proceso_id=? AND tipo_id=? LIMIT 1");
    $st->execute([(int)$prevProc['id'], $tipoId]);
    if ($st->fetchColumn()) {
        $prev = metricas_por_seccion($pdo, (int)$prevProc['id'], $tipoId, $ambitoCode ?: null);
        $rowsPrev = $prev['rows'];
        $prevAnio = (int)$prevProc['anio'];
    }
}

$secEstruct = []; // sin cruce con tree_user en este módulo

$secMetas = []; // sin metas en este módulo

// Merge en estructura unificada
$rows = [];
foreach ($rowsAct as $sec => $r) {
    if ($secSearch !== '' && is_numeric($secSearch) && (int)$secSearch !== $sec) continue;
    $prev = $rowsPrev[$sec] ?? null;
    $rentPrev = $prev['rentabilidad'] ?? null;
    $partPrev = $prev['participacion'] ?? null;
    $rows[$sec] = [
        'num_seccion' => $sec,
        'ambito_codigo' => $r['ambito_codigo'],
        'ambito_nombre' => $r['ambito_nombre'],
        'pan_solo'      => (int)$r['pan_solo'],
        'pan_coal'      => (int)$r['pan_coal'],
        'voto_efectivo' => (int)$r['voto_efectivo'],
        'validos'       => (int)$r['validos'],
        'emitidos'      => (int)$r['emitidos'],
        'lista_nominal' => (int)$r['lista_nominal'],
        'n_casillas'    => (int)$r['n_casillas'],
        'rentabilidad'  => (float)$r['rentabilidad'],
        'participacion' => (float)$r['participacion'],
        'rent_prev'     => $rentPrev,
        'part_prev'     => $partPrev,
        'delta_rent'    => $rentPrev !== null ? ((float)$r['rentabilidad'] - (float)$rentPrev) : null,
        'delta_part'    => $partPrev !== null ? ((float)$r['participacion'] - (float)$partPrev) : null,
        'estructura'    => $secEstruct[$sec] ?? 0,
        'meta'          => $secMetas[$sec] ?? null,
    ];
}

/* ---------------------------- Agregados estratégicos ---------------------------- */
$nSecs = count($rows);
$totEfectivo = $totValidos = $totEmitidos = $totLN = 0;
foreach ($rows as $r) {
    $totEfectivo += $r['voto_efectivo'];
    $totValidos  += $r['validos'];
    $totEmitidos += $r['emitidos'];
    $totLN       += $r['lista_nominal'];
}
$rentGlobal  = $totValidos > 0 ? ($totEfectivo / $totValidos) * 100 : 0;
$partGlobal  = $totLN > 0 ? ($totEmitidos / $totLN) * 100 : 0;

// Comparativos globales
$rentGlobalPrev = $partGlobalPrev = null;
if (!empty($rowsPrev)) {
    $tE = $tV = $tEm = $tLN = 0;
    foreach ($rowsPrev as $r) {
        $tE  += $r['voto_efectivo'];
        $tV  += $r['validos'];
        $tEm += $r['emitidos'];
        $tLN += $r['lista_nominal'];
    }
    $rentGlobalPrev = $tV > 0 ? ($tE / $tV) * 100 : 0;
    $partGlobalPrev = $tLN > 0 ? ($tEm / $tLN) * 100 : 0;
}

/**
 * Asigna cada sección a un cuadrante BCG electoral.
 * Ejes: rentabilidad (mediana del dataset) vs delta_rent (0 = sin cambio).
 */
$rentValues = array_column($rows, 'rentabilidad');
sort($rentValues);
$medianaRent = count($rentValues) > 0 ? $rentValues[(int)floor(count($rentValues)/2)] : 0;

$cuadrantes = ['estrella'=>[], 'riesgo'=>[], 'oportunidad'=>[], 'critica'=>[], 'sin_comparativo'=>[]];
foreach ($rows as $sec => $r) {
    if ($r['delta_rent'] === null) {
        $cuadrantes['sin_comparativo'][] = $sec;
        continue;
    }
    $alta = $r['rentabilidad'] >= $medianaRent;
    $sube = $r['delta_rent']  >= 0;
    if ($alta && $sube)   $cuadrantes['estrella'][]   = $sec;
    elseif ($alta && !$sube) $cuadrantes['riesgo'][]    = $sec;
    elseif (!$alta && $sube) $cuadrantes['oportunidad'][]= $sec;
    else                      $cuadrantes['critica'][]   = $sec;
}

// Top movimientos
$rowsConDelta = array_filter($rows, fn($r) => $r['delta_rent'] !== null);
uasort($rowsConDelta, fn($a,$b) => $b['delta_rent'] <=> $a['delta_rent']);
$topGana = array_slice($rowsConDelta, 0, 10, true);
$topPier = array_slice(array_reverse($rowsConDelta, true), 0, 10, true);

// Secciones bisagra (35-50% rentabilidad — cerca del umbral de ganar)
$bisagra = array_filter($rows, fn($r) => $r['rentabilidad'] >= 35 && $r['rentabilidad'] <= 50);
uasort($bisagra, fn($a,$b) => $b['rentabilidad'] <=> $a['rentabilidad']);
$bisagra = array_slice($bisagra, 0, 20, true);

// Subexplotadas: red ciudadana alta + rentabilidad baja (potencial sin convertir)
$subexp = [];
foreach ($rows as $sec => $r) {
    if ($r['estructura'] >= 10 && $r['rentabilidad'] < 40) $subexp[$sec] = $r;
}
uasort($subexp, fn($a,$b) => $b['estructura'] <=> $a['estructura']);
$subexp = array_slice($subexp, 0, 15, true);

// Ordenar tabla principal por rentabilidad desc por default
uasort($rows, fn($a,$b) => $b['rentabilidad'] <=> $a['rentabilidad']);

// Para el scatter, normalizar
$scatterPoints = [];
foreach ($rows as $sec => $r) {
    if ($r['delta_rent'] === null) continue;
    $scatterPoints[] = [
        'sec' => $sec,
        'x'   => round($r['rentabilidad'], 2),
        'y'   => round($r['delta_rent'], 2),
        'r'   => max(2, min(12, sqrt($r['validos']/30))),
        'amb' => $r['ambito_nombre'] ?? $r['ambito_codigo'],
        'val' => $r['validos'],
        'est' => $r['estructura'],
    ];
}

$cfg = reporteador_config();
$gmapsKey = $cfg['google_maps']['api_key'] ?? '';

// Definiciones de cálculo (usadas en tooltips de las cabeceras)
$tips = [
    // KPIs
    'secciones'       => 'Número de secciones que tienen al menos 1 voto válido contabilizado en esta elección, según el filtro aplicado.',
    'voto_efectivo'   => 'Voto efectivo PAN = votos a "PAN" + votos a cualquier coalición que tenga PAN como componente registrado en BD (ej. PAN-PRI-PRD, PAN-PRI, etc.). Coaliciones detectadas: '
                        . (empty($panCoalCodes) ? 'ninguna (solo PAN puro)' : implode(', ', $panCoalCodes)) . '.',
    'rentabilidad'    => 'Rentabilidad % = voto efectivo PAN / votos válidos × 100. Mide qué porcentaje del voto útil capturó el PAN (excluye nulos y no registrados).',
    'rent_prev'       => 'Rentabilidad de la elección anterior del mismo tipo (' . ($prevAnio ?? 'N/A') . '), calculada con la misma fórmula pero usando las coaliciones PAN de ese año.',
    'delta_rent'      => 'Δ rentabilidad = rentabilidad actual − rentabilidad ' . ($prevAnio ?? 'anterior') . ', en puntos porcentuales (pp). Positivo = subió, negativo = bajó.',
    'participacion'   => 'Participación % = votos emitidos (incluye nulos) / lista nominal × 100. Mide cuánta gente acudió a votar.',
    'lista_nominal'   => 'Suma de la lista nominal de las casillas de cada sección, tomada del archivo del IEEQ (resultados_casilla_meta.lista_nominal).',
    'validos'         => 'Suma de votos por sección excluyendo NULOS y NO_REGISTRADAS. Es el denominador de la rentabilidad.',
    'votos_emitidos'  => 'Votos emitidos = todos los votos en las urnas, incluyendo nulos y no registrados. Es el numerador de la participación.',
    'red'             => 'Personas activas en tu estructura ciudadana cuyo número de sección (extraído de electoralSectionName) coincide con esta sección. Excluye espejos y deshabilitados.',
    'meta'            => 'Meta total asignada a la sección en el sistema (secciones_metas.meta_total). Si dice "—" no hay meta cargada.',

    // Filtros
    'filtro_proceso'  => 'Proceso electoral: año + nivel (estatal/federal). El reporte agrega todos los tipos de elección del proceso si no filtras por tipo específico.',
    'filtro_tipo'     => 'Tipo de elección: gubernatura, ayuntamiento, diputaciones MR/RP, senaduría, presidencia. Cambia las coaliciones PAN consideradas porque cada elección tiene sus propias.',
    'filtro_ambito'   => 'Ámbito específico: filtra a un solo distrito o municipio. Vacío = todo el estado.',
    'filtro_sec'      => 'Buscar sección: filtra los datos y el mapa a una sección específica por su número.',
    'coal_pan'        => 'Las coaliciones que se consideran como "PAN" para sumar al voto efectivo. Una coalición se cuenta si tiene a PAN como partido componente registrado en /admin/elecciones.',

    // Cuadrantes
    'cuadrantes'      => 'Matriz BCG electoral. Eje X: rentabilidad % (corte en la MEDIANA del dataset filtrado = ' . round($medianaRent ?? 0, 1) . '%). Eje Y: Δ rentabilidad en pp (corte en 0).',
    'estrellas'       => 'Rentabilidad ≥ mediana Y Δ ≥ 0 — secciones rentables que están al alza. Acción: mantener inversión, son tu base sólida.',
    'oportunidad'     => 'Rentabilidad < mediana Y Δ ≥ 0 — bajas pero creciendo. Acción: meter recursos ahora porque el momentum está a tu favor.',
    'criticas'        => 'Rentabilidad < mediana Y Δ < 0 — bajas y cayendo. Acción: evaluar si vale la pena reconstruir o reasignar recursos.',
    'riesgo'          => 'Rentabilidad ≥ mediana Y Δ < 0 — todavía rentables pero cayendo. Acción: defender, entender la causa de la caída antes que se vuelvan críticas.',

    // Tops y secciones especiales
    'top_gana'        => 'Las 10 secciones con mayor INCREMENTO de rentabilidad respecto a ' . ($prevAnio ?? 'la elección anterior') . '. Ordenadas por Δ descendente.',
    'top_pierde'      => 'Las 10 secciones con mayor CAÍDA de rentabilidad respecto a ' . ($prevAnio ?? 'la elección anterior') . '. Ordenadas por Δ ascendente.',
    'bisagra'         => 'Secciones con rentabilidad entre 35% y 50%. Están cerca del umbral de mayoría. Una inversión marginal puede mover el resultado.',
    'bisagra_faltan'  => 'Votos efectivos adicionales que se necesitan para llegar al 50% de los votos válidos actuales en esa sección. Asume la base de válidos constante.',
    'subexp'          => 'Secciones con ≥10 personas en tu red ciudadana PERO rentabilidad < 40%. Capital humano que no está convirtiendo en voto. Acción: revisar líderes seccionales y plan de territorio.',

    // Mapa
    'mapa_rent'       => 'Cada polígono se colorea según su % de rentabilidad PAN en esta elección. Verde oscuro = alta rentabilidad (≥55%), rojo oscuro = muy baja (<15%).',
    'mapa_delta'      => 'Cada polígono se colorea según el cambio en puntos porcentuales vs la elección anterior del mismo tipo. Verde = subió, amarillo = sin cambio significativo, rojo = bajó.',
    'mapa_red'        => 'Cada polígono se colorea según el número de personas en tu estructura ciudadana activa en esa sección. Azul oscuro = ≥50 personas, gris = sin red.',

    // Drawer (panel lateral)
    'drawer_titulo'   => 'Detalle completo de la sección. Útil cuando no tiene polígono en el mapa.',
    'drawer_desglose' => 'Votos por cada partido individual, coalición y opción no válida (NULOS, NO_REGISTRADAS). Coincide con el archivo del IEEQ.',
    'drawer_pct'      => 'Porcentaje de votos válidos. Suma de todas las opciones reales (excluyendo nulos y no registrados) debería dar 100%.',
];

// Helper para renderizar etiqueta con tooltip
function hint(string $label, string $tip): string {
    return '<span class="hint" title="' . htmlspecialchars($tip) . '">' . htmlspecialchars($label) . '</span>';
}

$title = 'Rentabilidad electoral';
$active = 'rentabilidad';
include __DIR__ . '/../partials/layout_top.php';
?>
<style>
.hint { border-bottom: 1px dotted #9CA3AF; cursor: help; }
.hint:hover { border-bottom-color: #2563EB; }
.kpi-help { cursor: help; }
</style>

<div class="page-header">
  <div>
    <h1>Rentabilidad electoral · análisis estratégico</h1>
    <p>
      Voto efectivo PAN (PAN puro + coaliciones) por sección, con tendencia respecto a la elección anterior del mismo tipo,
      cruzado con estructura ciudadana y metas. Cuadrantes BCG para priorizar acción.
    </p>
  </div>
</div>

<!-- ===== Filtros ===== -->
<form method="get" class="card" style="margin-bottom:18px">
  <div style="display:grid;grid-template-columns:1fr 1.2fr 1fr 0.8fr auto;gap:10px;align-items:end">
    <div class="field"><label><?= hint('Proceso', $tips['filtro_proceso']) ?></label>
      <select name="proceso_id" class="input" onchange="this.form.submit()">
        <?php foreach ($procesos as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $procesoId==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['anio'].' · '.ucfirst($p['nivel'])) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label><?= hint('Tipo de elección', $tips['filtro_tipo']) ?></label>
      <select name="tipo_id" class="input" onchange="this.form.submit()">
        <?php foreach ($tipos as $t): ?>
          <option value="<?= $t['id'] ?>" <?= $tipoId==$t['id']?'selected':'' ?>><?= htmlspecialchars($t['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label><?= hint('Ámbito', $tips['filtro_ambito']) ?></label>
      <select name="ambito_codigo" class="input" onchange="this.form.submit()">
        <option value="">Todos</option>
        <?php foreach ($ambitos as $a): ?>
          <option value="<?= htmlspecialchars($a['ambito_codigo']) ?>" <?= $ambitoCode===$a['ambito_codigo']?'selected':'' ?>>
            <?= htmlspecialchars(($a['ambito_nombre']??'—').' ('.($a['ambito_codigo']??'').')') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label><?= hint('Sección', $tips['filtro_sec']) ?></label>
      <input class="input" name="sec_search" value="<?= htmlspecialchars($secSearch) ?>" placeholder="416">
    </div>
    <div><button class="btn primary" type="submit">Aplicar</button></div>
  </div>
  <div style="margin-top:8px;font-size:12px;color:var(--color-text-secondary)">
    <strong><?= htmlspecialchars($procesoActual['anio'].' · '.$tipoActual['nombre']) ?></strong>
    <?php if ($prevAnio): ?> · comparando vs <strong><?= $prevAnio ?></strong> <?php else: ?> · sin elección anterior comparable <?php endif; ?>
    <?php if (!empty($panCoalCodes)): ?>
      · <?= hint('coaliciones PAN', $tips['coal_pan']) ?>:
      <?php foreach ($panCoalCodes as $c): ?><span class="chip info" style="font-size:10px" title="Esta coalición tiene PAN como componente registrado en BD"><?= htmlspecialchars($c) ?></span><?php endforeach; ?>
    <?php endif; ?>
  </div>
</form>

<!-- ===== KPIs con delta ===== -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px">
  <div class="card kpi-help" style="padding:14px" title="<?= htmlspecialchars($tips['secciones']) ?>">
    <div style="font-size:11px;color:var(--color-text-secondary);text-transform:uppercase">Secciones con voto</div>
    <div style="font-size:24px;font-weight:700"><?= number_format($nSecs) ?></div>
  </div>
  <div class="card kpi-help" style="padding:14px;background:#FEF3C7;border-color:#FCD34D" title="<?= htmlspecialchars($tips['rentabilidad']) ?>">
    <div style="font-size:11px;color:#92400E;text-transform:uppercase">Rentabilidad global</div>
    <div style="font-size:24px;font-weight:700;color:#92400E"><?= number_format($rentGlobal, 2) ?>%</div>
    <?php if ($rentGlobalPrev !== null): $d = $rentGlobal - $rentGlobalPrev; ?>
      <div style="font-size:12px;color:<?= $d>=0?'#065F46':'#991B1B' ?>;font-weight:600">
        <?= $d>=0?'▲':'▼' ?> <?= number_format(abs($d),2) ?> pp vs <?= $prevAnio ?>
      </div>
    <?php else: ?>
      <div style="font-size:11px;color:#92400E">de <?= number_format($totValidos) ?> votos válidos</div>
    <?php endif; ?>
  </div>
  <div class="card kpi-help" style="padding:14px" title="<?= htmlspecialchars($tips['voto_efectivo']) ?>">
    <div style="font-size:11px;color:var(--color-text-secondary);text-transform:uppercase">Voto efectivo PAN</div>
    <div style="font-size:24px;font-weight:700"><?= number_format($totEfectivo) ?></div>
    <div style="font-size:11px;color:var(--color-text-secondary)">PAN + coaliciones · <?= number_format($totValidos) ?> válidos</div>
  </div>
  <div class="card kpi-help" style="padding:14px" title="<?= htmlspecialchars($tips['participacion']) ?>">
    <div style="font-size:11px;color:var(--color-text-secondary);text-transform:uppercase">Participación</div>
    <div style="font-size:24px;font-weight:700"><?= number_format($partGlobal, 2) ?>%</div>
    <?php if ($partGlobalPrev !== null): $d = $partGlobal - $partGlobalPrev; ?>
      <div style="font-size:12px;color:<?= $d>=0?'#065F46':'#991B1B' ?>;font-weight:600">
        <?= $d>=0?'▲':'▼' ?> <?= number_format(abs($d),2) ?> pp vs <?= $prevAnio ?>
      </div>
    <?php else: ?>
      <div style="font-size:11px;color:var(--color-text-secondary)"><?= number_format($totLN) ?> lista nominal</div>
    <?php endif; ?>
  </div>
</div>

<!-- ===== Cuadrantes BCG con scatter ===== -->
<?php if (!empty($scatterPoints)): ?>
<div class="card" style="margin-bottom:18px">
  <h3 style="margin-top:0"><?= hint('Cuadrantes de prioridad · rentabilidad vs tendencia', $tips['cuadrantes']) ?></h3>
  <p class="muted" style="margin-top:0">Tamaño de burbuja = volumen de votos válidos. Eje X = rentabilidad %. Eje Y = cambio en puntos porcentuales vs <?= $prevAnio ?>.</p>

  <div style="display:grid;grid-template-columns:1fr 320px;gap:18px">
    <div id="scatter-wrap" style="position:relative;height:380px;border:1px solid var(--color-border);border-radius:8px;overflow:hidden;background:linear-gradient(to right, #FEF2F2 0%, #FEF2F2 50%, #ECFDF5 50%, #ECFDF5 100%);background:linear-gradient(135deg, #FEF2F2 0%, #FEF2F2 49.9%, #FEF3C7 49.9%, #FEF3C7 50.1%, #ECFDF5 50.1%, #ECFDF5 100%)">
      <svg id="scatter" viewBox="0 0 800 400" style="width:100%;height:100%" preserveAspectRatio="none"></svg>
      <div style="position:absolute;top:6px;left:6px;font-size:10px;font-weight:700;color:#991B1B;background:white;padding:2px 6px;border-radius:3px">⚠ CRÍTICAS</div>
      <div style="position:absolute;top:6px;right:6px;font-size:10px;font-weight:700;color:#065F46;background:white;padding:2px 6px;border-radius:3px">★ ESTRELLAS</div>
      <div style="position:absolute;bottom:6px;left:6px;font-size:10px;font-weight:700;color:#92400E;background:white;padding:2px 6px;border-radius:3px">💡 OPORTUNIDAD</div>
      <div style="position:absolute;bottom:6px;right:6px;font-size:10px;font-weight:700;color:#7C2D12;background:white;padding:2px 6px;border-radius:3px">⚡ RIESGO</div>
    </div>

    <div style="display:flex;flex-direction:column;gap:8px;font-size:13px">
      <div class="kpi-help" style="padding:10px;background:#ECFDF5;border-left:3px solid #059669;border-radius:4px" title="<?= htmlspecialchars($tips['estrellas']) ?>">
        <div style="font-weight:600">★ Estrellas · <?= count($cuadrantes['estrella']) ?> secciones</div>
        <div style="font-size:11px;color:var(--color-text-secondary)">Rentables y al alza · mantener inversión</div>
      </div>
      <div class="kpi-help" style="padding:10px;background:#FEF3C7;border-left:3px solid #D97706;border-radius:4px" title="<?= htmlspecialchars($tips['oportunidad']) ?>">
        <div style="font-weight:600">💡 Oportunidad · <?= count($cuadrantes['oportunidad']) ?> secciones</div>
        <div style="font-size:11px;color:var(--color-text-secondary)">Bajas pero subiendo · meter recursos ahora</div>
      </div>
      <div class="kpi-help" style="padding:10px;background:#FEF2F2;border-left:3px solid #DC2626;border-radius:4px" title="<?= htmlspecialchars($tips['criticas']) ?>">
        <div style="font-weight:600">⚠ Críticas · <?= count($cuadrantes['critica']) ?> secciones</div>
        <div style="font-size:11px;color:var(--color-text-secondary)">Bajas y cayendo · evaluar reconstrucción</div>
      </div>
      <div class="kpi-help" style="padding:10px;background:#FEE2E2;border-left:3px solid #7C2D12;border-radius:4px" title="<?= htmlspecialchars($tips['riesgo']) ?>">
        <div style="font-weight:600">⚡ Riesgo · <?= count($cuadrantes['riesgo']) ?> secciones</div>
        <div style="font-size:11px;color:var(--color-text-secondary)">Rentables pero cayendo · defender</div>
      </div>
      <?php if (!empty($cuadrantes['sin_comparativo'])): ?>
        <div style="padding:8px;background:#F3F4F6;border-radius:4px;font-size:11px;color:var(--color-text-secondary)">
          <?= count($cuadrantes['sin_comparativo']) ?> secciones sin elección anterior comparable
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ===== Top movimientos + Subexplotadas ===== -->
<?php if ($prevAnio): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px">
  <div class="card">
    <h3 style="margin-top:0;color:#065F46"><?= hint('▲ Top 10 que más subieron', $tips['top_gana']) ?></h3>
    <table class="table" style="margin:0">
      <thead><tr><th>Sec</th><th>Ámbito</th><th style="text-align:right" title="Rentabilidad % en <?= $prevAnio ?>"><?= $prevAnio ?></th><th style="text-align:right" title="Rentabilidad % en <?= $procesoActual['anio'] ?>"><?= $procesoActual['anio'] ?></th><th style="text-align:right" title="Δ rentabilidad en puntos porcentuales">Δ</th></tr></thead>
      <tbody>
        <?php foreach ($topGana as $r): ?>
          <tr>
            <td class="mono"><?= $r['num_seccion'] ?></td>
            <td style="font-size:11px"><?= htmlspecialchars($r['ambito_nombre']??'') ?></td>
            <td style="text-align:right;font-size:11px"><?= number_format($r['rent_prev'],1) ?>%</td>
            <td style="text-align:right;font-size:11px;font-weight:600"><?= number_format($r['rentabilidad'],1) ?>%</td>
            <td style="text-align:right;color:#065F46;font-weight:700">+<?= number_format($r['delta_rent'],1) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card">
    <h3 style="margin-top:0;color:#991B1B"><?= hint('▼ Top 10 que más bajaron', $tips['top_pierde']) ?></h3>
    <table class="table" style="margin:0">
      <thead><tr><th>Sec</th><th>Ámbito</th><th style="text-align:right" title="Rentabilidad % en <?= $prevAnio ?>"><?= $prevAnio ?></th><th style="text-align:right" title="Rentabilidad % en <?= $procesoActual['anio'] ?>"><?= $procesoActual['anio'] ?></th><th style="text-align:right" title="Δ rentabilidad en puntos porcentuales">Δ</th></tr></thead>
      <tbody>
        <?php foreach ($topPier as $r): ?>
          <tr>
            <td class="mono"><?= $r['num_seccion'] ?></td>
            <td style="font-size:11px"><?= htmlspecialchars($r['ambito_nombre']??'') ?></td>
            <td style="text-align:right;font-size:11px"><?= number_format($r['rent_prev'],1) ?>%</td>
            <td style="text-align:right;font-size:11px;font-weight:600"><?= number_format($r['rentabilidad'],1) ?>%</td>
            <td style="text-align:right;color:#991B1B;font-weight:700"><?= number_format($r['delta_rent'],1) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ===== Secciones bisagra + Subexplotadas ===== -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px">
  <div class="card">
    <h3 style="margin-top:0"><?= hint('🎯 Secciones bisagra · 35–50% rentabilidad', $tips['bisagra']) ?></h3>
    <p class="muted" style="margin-top:0;font-size:12px">Donde el voto efectivo está cerca del umbral de ganar. Inversión marginal puede mover resultado.</p>
    <?php if (empty($bisagra)): ?>
      <p class="muted">No hay secciones en ese rango.</p>
    <?php else: ?>
      <table class="table" style="margin:0">
        <thead><tr><th>Sec</th><th>Ámbito</th><th style="text-align:right"><?= hint('Rent.', $tips['rentabilidad']) ?></th><th style="text-align:right"><?= hint('Faltan', $tips['bisagra_faltan']) ?></th></tr></thead>
        <tbody>
          <?php foreach ($bisagra as $r):
            $faltan = max(0, (int)ceil($r['validos']*0.5) - $r['voto_efectivo']);
          ?>
            <tr>
              <td class="mono"><?= $r['num_seccion'] ?></td>
              <td style="font-size:11px"><?= htmlspecialchars($r['ambito_nombre']??'') ?></td>
              <td style="text-align:right;font-weight:600"><?= number_format($r['rentabilidad'],1) ?>%</td>
              <td style="text-align:right;color:#92400E;font-weight:600"><?= number_format($faltan) ?></td>
              
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<!-- ===== Mapa geográfico ===== -->
<div class="card" style="margin-bottom:18px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
    <h3 style="margin:0">🗺 Mapa de rentabilidad</h3>
    <div style="display:flex;gap:6px;align-items:center;font-size:11px">
      <span>Pintar por:</span>
      <label class="chip-radio" style="font-size:11px" title="<?= htmlspecialchars($tips['mapa_rent']) ?>"><input type="radio" name="mapMode" value="rentabilidad" checked><span>Rentabilidad</span></label>
      <?php if ($prevAnio): ?>
      <label class="chip-radio" style="font-size:11px" title="<?= htmlspecialchars($tips['mapa_delta']) ?>"><input type="radio" name="mapMode" value="delta"><span>Tendencia Δ</span></label>
      <?php endif; ?>
    </div>
  </div>
  <div id="map-rent" style="width:100%;height:560px;border-radius:8px;border:1px solid var(--color-border)"></div>
  <div id="map-legend" style="margin-top:8px;display:flex;gap:14px;flex-wrap:wrap;font-size:11px;align-items:center"></div>
</div>

<!-- ===== Tabla completa ===== -->
<div class="card" style="padding:0">
  <h3 style="padding:14px 18px;margin:0;border-bottom:1px solid var(--color-border)">
    Todas las secciones <span style="font-weight:normal;color:var(--color-text-secondary);font-size:13px">· <?= number_format($nSecs) ?> · click para ver detalle</span>
  </h3>
  <div style="overflow-x:auto;max-height:600px">
    <table class="table" style="margin:0">
      <thead style="position:sticky;top:0;background:white;z-index:1">
        <tr>
          <th>Sec</th><th>Ámbito</th>
          <th style="text-align:right"><?= hint('Lista', $tips['lista_nominal']) ?></th>
          <th style="text-align:right"><?= hint('Válidos', $tips['validos']) ?></th>
          <th style="text-align:right"><?= hint('Rent.', $tips['rentabilidad']) ?></th>
          <?php if ($prevAnio): ?>
            <th style="text-align:right"><?= hint('Rent. ' . $prevAnio, $tips['rent_prev']) ?></th>
            <th style="text-align:right"><?= hint('Δ rent.', $tips['delta_rent']) ?></th>
          <?php endif; ?>
          <th style="text-align:right"><?= hint('Partic.', $tips['participacion']) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $rentColor = $r['rentabilidad']>=50?'#059669':($r['rentabilidad']>=35?'#10B981':($r['rentabilidad']>=20?'#F59E0B':'#DC2626'));
        ?>
          <tr class="sec-row" data-sec="<?= $r['num_seccion'] ?>" style="cursor:pointer">
            <td class="mono" style="font-weight:600"><?= $r['num_seccion'] ?></td>
            <td style="font-size:11px"><?= htmlspecialchars($r['ambito_nombre']??$r['ambito_codigo']) ?></td>
            <td style="text-align:right;font-size:12px"><?= number_format($r['lista_nominal']) ?></td>
            <td style="text-align:right;font-size:12px"><?= number_format($r['validos']) ?></td>
            <td style="text-align:right">
              <span style="display:inline-block;padding:2px 8px;border-radius:10px;color:white;background:<?= $rentColor ?>;font-weight:600;font-size:11px">
                <?= number_format($r['rentabilidad'],1) ?>%
              </span>
            </td>
            <?php if ($prevAnio): ?>
              <td style="text-align:right;font-size:12px;color:var(--color-text-secondary)">
                <?= $r['rent_prev']!==null ? number_format($r['rent_prev'],1).'%' : '—' ?>
              </td>
              <td style="text-align:right;font-weight:600;font-size:12px;color:<?= ($r['delta_rent']??0)>=0?'#065F46':'#991B1B' ?>">
                <?php if ($r['delta_rent']!==null): ?>
                  <?= ($r['delta_rent']>=0?'+':'').number_format($r['delta_rent'],1) ?>
                <?php else: ?>—<?php endif; ?>
              </td>
            <?php endif; ?>
            <td style="text-align:right;font-size:12px"><?= number_format($r['participacion'],1) ?>%</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// Scatter plot SVG con puntos
(function() {
  const points = <?= json_encode($scatterPoints) ?>;
  if (!points.length) return;
  const svg = document.getElementById('scatter');
  if (!svg) return;
  const W = 800, H = 400, PAD = 30;
  const xMin = 0, xMax = 100;
  const yVals = points.map(p => p.y);
  const yMax = Math.max(10, Math.ceil(Math.max(...yVals.map(Math.abs))));
  const yMin = -yMax;
  const sx = v => PAD + ((v - xMin)/(xMax-xMin))*(W-2*PAD);
  const sy = v => H - PAD - ((v - yMin)/(yMax-yMin))*(H-2*PAD);

  // Ejes guía
  let svgContent = '';
  // Eje horizontal (y=0)
  svgContent += `<line x1="${PAD}" y1="${sy(0)}" x2="${W-PAD}" y2="${sy(0)}" stroke="#94A3B8" stroke-width="1.5" stroke-dasharray="4 4"/>`;
  // Eje vertical (mediana de rentabilidad)
  const mediana = <?= round($medianaRent, 2) ?>;
  svgContent += `<line x1="${sx(mediana)}" y1="${PAD}" x2="${sx(mediana)}" y2="${H-PAD}" stroke="#94A3B8" stroke-width="1.5" stroke-dasharray="4 4"/>`;
  // Etiquetas de eje X
  for (let v = 0; v <= 100; v += 25) {
    svgContent += `<text x="${sx(v)}" y="${H-8}" font-size="10" fill="#64748B" text-anchor="middle">${v}%</text>`;
  }
  // Etiquetas de eje Y
  for (let v = -yMax; v <= yMax; v += yMax/2) {
    if (v === 0) continue;
    svgContent += `<text x="6" y="${sy(v)+3}" font-size="10" fill="#64748B">${v>0?'+':''}${v.toFixed(0)}</text>`;
  }
  // Puntos
  for (const p of points) {
    const color = p.y >= 0 ? (p.x >= mediana ? '#059669' : '#D97706') : (p.x >= mediana ? '#7C2D12' : '#DC2626');
    svgContent += `<circle cx="${sx(p.x)}" cy="${sy(p.y)}" r="${p.r}" fill="${color}" fill-opacity="0.55" stroke="${color}" stroke-width="1"><title>Sección ${p.sec} (${p.amb})\nRentabilidad: ${p.x}%\nΔ: ${p.y>0?'+':''}${p.y} pp\nVálidos: ${p.val}\nRed: ${p.est}</title></circle>`;
  }
  svg.innerHTML = svgContent;
})();
</script>

<!-- ===== Panel lateral de detalle por sección ===== -->
<div id="sec-drawer" style="position:fixed;top:0;right:-440px;width:440px;height:100vh;background:white;border-left:1px solid var(--color-border);box-shadow:-4px 0 16px rgba(0,0,0,0.1);z-index:1000;transition:right .25s ease;overflow-y:auto">
  <div style="padding:14px 18px;border-bottom:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:white;z-index:2">
    <div>
      <div id="sec-drawer-title" style="font-weight:700;font-size:16px"></div>
      <div id="sec-drawer-subtitle" style="font-size:12px;color:var(--color-text-secondary)"></div>
    </div>
    <button id="sec-drawer-close" style="background:none;border:none;font-size:24px;cursor:pointer;color:#6B7280;padding:0 6px;line-height:1">×</button>
  </div>
  <div id="sec-drawer-body" style="padding:14px 18px"></div>
</div>
<div id="sec-drawer-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.2);z-index:999"></div>

<script>
// =====================================================================
// Panel lateral con detalle de sección (click en fila o búsqueda manual)
// =====================================================================
(function(){
  const drawer  = document.getElementById('sec-drawer');
  const overlay = document.getElementById('sec-drawer-overlay');
  const title   = document.getElementById('sec-drawer-title');
  const subtitle= document.getElementById('sec-drawer-subtitle');
  const body    = document.getElementById('sec-drawer-body');
  const closeBtn= document.getElementById('sec-drawer-close');

  const PROCESO_ID = <?= $procesoId ?>;
  const TIPO_ID    = <?= $tipoId ?>;
  const AMBITO     = '<?= htmlspecialchars($ambitoCode, ENT_QUOTES) ?>';

  const colorPartido = {
    PAN: '#003C71', PRI: '#D81E2A', PRD: '#FFD800', MC: '#FF8200',
    PVEM: '#27A03A', MORENA: '#8B1F2E', PT: '#D71920', RSP: '#7E1F3B',
    QS: '#1E40AF', PES: '#7C3AED', FM: '#0EA5E9',
    NULOS: '#9CA3AF', NO_REGISTRADAS: '#6B7280'
  };
  const isCoalicion = code => /[-_]/.test(code);

  function open() {
    drawer.style.right = '0';
    overlay.style.display = 'block';
  }
  function close() {
    drawer.style.right = '-440px';
    overlay.style.display = 'none';
  }
  closeBtn.addEventListener('click', close);
  overlay.addEventListener('click', close);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

  function rentColor(r) {
    if (r == null) return '#9CA3AF';
    if (r >= 55) return '#065F46';
    if (r >= 45) return '#10B981';
    if (r >= 35) return '#84CC16';
    if (r >= 25) return '#F59E0B';
    if (r >= 15) return '#DC2626';
    return '#7F1D1D';
  }
  function fmtNum(n) { return (n||0).toLocaleString(); }

  function render(d) {
    if (d.error) {
      body.innerHTML = '<div style="color:#991B1B">'+d.error+'</div>';
      return;
    }
    title.textContent = 'Sección ' + d.num_seccion;
    subtitle.textContent = (d.elecciones[0] && (d.elecciones[0].ambito_nombre || d.elecciones[0].ambito_codigo)) || '';

    // Catálogo (ubicación física)
    const catalogoChips = (d.catalogo||[]).map(c => {
      const cp = [];
      if (c.municipio) cp.push(`<span style="padding:2px 8px;background:#DBEAFE;color:#1E40AF;border-radius:10px;font-size:11px;font-weight:600">${c.municipio}</span>`);
      if (c.distrito_num) cp.push(`<span style="padding:2px 8px;background:#FEF3C7;color:#92400E;border-radius:10px;font-size:11px;font-weight:600">Distrito ${c.distrito_num}</span>`);
      if (!c.tiene_poligono) cp.push(`<span style="padding:2px 8px;background:#FEE2E2;color:#991B1B;border-radius:10px;font-size:11px">⚠ sin polígono</span>`);
      return cp.join(' ');
    }).join('<br>') || '<span style="color:#9CA3AF;font-size:12px">No está en catálogo IEEQ — sección fantasma o de proceso anterior</span>';

    // Desglose por opción
    const desglose = d.desglose || {};
    const validos = d.votos_validos || 1;
    const partidos = [], coaliciones = [], especiales = [];
    for (const [code, votos] of Object.entries(desglose)) {
      const e = { code, votos, pct: validos > 0 ? (votos/validos*100) : 0 };
      if (code === 'NULOS' || code === 'NO_REGISTRADAS') especiales.push(e);
      else if (isCoalicion(code)) coaliciones.push(e);
      else partidos.push(e);
    }
    const renderFila = (e) => {
      const c = colorPartido[e.code] || '#6B7280';
      return `<tr>
        <td style="padding:4px 0"><span style="display:inline-block;width:10px;height:10px;background:${c};border-radius:2px;margin-right:6px;vertical-align:middle"></span>${e.code}</td>
        <td style="text-align:right;font-variant-numeric:tabular-nums;padding:4px 0">${fmtNum(e.votos)}</td>
        <td style="text-align:right;color:#6B7280;padding:4px 0">${e.pct.toFixed(2)}%</td>
      </tr>`;
    };

    // Tips embebidos (mismas explicaciones que la página principal)
    const T = <?= json_encode($tips, JSON_HEX_QUOT | JSON_HEX_APOS) ?>;
    const H = (label, key) => `<span style="border-bottom:1px dotted #9CA3AF;cursor:help" title="${T[key]||''}">${label}</span>`;

    const rk = d.ranking;
    const esc = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    const rline = (l, v) => v ? `<div style="font-size:12px;margin-top:2px"><b>${l}:</b> ${esc(v)}</div>` : '';
    const rankingBlock = rk ? `<div style="border:1px solid #FDE68A;background:#FFFBEB;border-radius:10px;padding:10px 12px;margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <span style="font-weight:800;color:#92400E;font-size:12px;text-transform:uppercase;letter-spacing:.4px">Ranking Alfredo</span>
          <span style="font-weight:800;color:#B45309;font-size:18px">#${rk.rank ?? '—'}</span></div>
        ${rline('Delegación', rk.delegacion)}${rline('21-24', rk.p21_24)}${rline('Identidad', rk.identidad)}
        ${rk.colonias ? `<details style="margin-top:5px"><summary style="font-size:12px;cursor:pointer;color:#92400E">Colonias y localidades</summary><div style="font-size:11px;max-height:130px;overflow:auto;margin-top:4px;color:#475569;line-height:1.4">${esc(rk.colonias)}</div></details>` : ''}
      </div>` : '';

    body.innerHTML = `
      <div style="margin-bottom:14px">${catalogoChips}</div>
      ${rankingBlock}

      <table style="width:100%;font-size:13px;margin-bottom:14px">
        <tr><td>${H('Voto efectivo PAN','voto_efectivo')}</td><td style="text-align:right;font-weight:700">${fmtNum(d.voto_efectivo)}</td></tr>
        <tr><td>${H('Votos válidos','validos')}</td><td style="text-align:right">${fmtNum(d.votos_validos)}</td></tr>
        <tr><td>${H('Votos emitidos','votos_emitidos')}</td><td style="text-align:right">${fmtNum(d.votos_emitidos)}</td></tr>
        <tr><td>${H('Lista nominal','lista_nominal')}</td><td style="text-align:right">${fmtNum(d.lista_nominal)}</td></tr>
        <tr><td title="Total de casillas con voto en esta sección">Casillas</td><td style="text-align:right">${d.n_casillas}</td></tr>
        <tr><td style="padding-top:6px">${H('Rentabilidad','rentabilidad')}</td>
            <td style="text-align:right;font-weight:700;padding-top:6px;color:${rentColor(d.rentabilidad)}">${d.rentabilidad ?? '—'}%</td></tr>
        ${d.participacion !== null ? `<tr><td>${H('Participación','participacion')}</td><td style="text-align:right">${d.participacion}%</td></tr>` : ''}
        ${d.prev ? `<tr><td>${H('Rentabilidad '+d.prev.anio,'rent_prev')}</td><td style="text-align:right;color:#6B7280">${d.prev.rentabilidad}%</td></tr>` : ''}
        ${d.delta_rent !== null ? `<tr><td>${H('Δ tendencia','delta_rent')}</td><td style="text-align:right;font-weight:600;color:${d.delta_rent>=0?'#065F46':'#991B1B'}">${d.delta_rent>=0?'+':''}${d.delta_rent} pp</td></tr>` : ''}
                      </table>

      <div style="margin-bottom:14px">
        <div style="font-size:11px;color:#6B7280;text-transform:uppercase;font-weight:600;margin-bottom:6px">${H('Votos por opción','drawer_desglose')}</div>
        <table style="width:100%;font-size:12px;border-collapse:collapse">
          <thead><tr style="color:#9CA3AF;font-weight:400;border-bottom:1px solid #E5E7EB">
            <th style="text-align:left;font-weight:400" title="Partido, coalición o tipo de voto especial">Opción</th>
            <th style="text-align:right;font-weight:400" title="Suma de votos contabilizados">Votos</th>
            <th style="text-align:right;font-weight:400" title="${T.drawer_pct||''}">% válidos</th>
          </tr></thead>
          <tbody>${partidos.map(renderFila).join('')}</tbody>
          ${coaliciones.length ? `<tbody><tr><td colspan="3" style="font-size:10px;color:#9CA3AF;padding-top:8px;padding-bottom:2px">Coaliciones</td></tr>${coaliciones.map(renderFila).join('')}</tbody>` : ''}
          ${especiales.length ? `<tbody><tr><td colspan="3" style="font-size:10px;color:#9CA3AF;padding-top:8px;padding-bottom:2px">No válidos</td></tr>${especiales.map(renderFila).join('')}</tbody>` : ''}
        </table>
      </div>

      ${d.colonias && d.colonias.length ? `
        <div style="margin-bottom:14px">
          <div style="font-size:11px;color:#6B7280;text-transform:uppercase;font-weight:600;margin-bottom:6px">Colonias / localidades · ${d.colonias.length}</div>
          <div style="display:flex;flex-wrap:wrap;gap:4px">
            ${d.colonias.map(c => `<span style="font-size:11px;padding:3px 8px;background:#F3F4F6;border-radius:10px">${c}</span>`).join('')}
          </div>
        </div>` : ''}
    `;
  }

  function load(sec) {
    open();
    title.textContent = 'Sección ' + sec;
    subtitle.textContent = 'cargando...';
    body.innerHTML = '<div style="padding:20px;text-align:center;color:#9CA3AF">cargando</div>';
    const url = `<?= $BASE ?>/api/seccion_detalle.php?proceso_id=${PROCESO_ID}&tipo_id=${TIPO_ID}&num_seccion=${sec}&ambito_codigo=${encodeURIComponent(AMBITO)}`;
    fetch(url).then(r => r.json()).then(render).catch(err => {
      body.innerHTML = '<div style="color:#991B1B">Error: '+err.message+'</div>';
    });
  }

  // Click en filas de la tabla
  document.querySelectorAll('.sec-row').forEach(tr => {
    tr.addEventListener('click', () => load(tr.dataset.sec));
    tr.addEventListener('mouseenter', () => tr.style.background = '#F9FAFB');
    tr.addEventListener('mouseleave', () => tr.style.background = '');
  });

  // Función global para abrir desde otros lugares (mapa, etc.)
  window.openSeccionDetalle = load;
})();
</script>

<script>
// =====================================================================
// Mapa Google Maps coloreado por rentabilidad / delta / estructura
// =====================================================================
(function(){
  const API_URL = '<?= $BASE ?>/api/rentabilidad_geo.php?proceso_id=<?= $procesoId ?>&tipo_id=<?= $tipoId ?>&ambito_codigo=<?= urlencode($ambitoCode) ?>&sec_search=<?= urlencode($secSearch) ?>';
  let map, infoWindow, currentMode = 'rentabilidad', dataLoaded = null;

  function colorRentabilidad(v) {
    if (v == null) return '#D1D5DB';
    if (v >= 55) return '#065F46';
    if (v >= 45) return '#10B981';
    if (v >= 35) return '#84CC16';
    if (v >= 25) return '#F59E0B';
    if (v >= 15) return '#DC2626';
    return '#7F1D1D';
  }
  function colorDelta(v) {
    if (v == null) return '#D1D5DB';
    if (v >= 8)   return '#065F46';
    if (v >= 3)   return '#10B981';
    if (v >= -3)  return '#FCD34D';
    if (v >= -8)  return '#F87171';
    return '#991B1B';
  }
  function colorEstructura(v) {
    if (v == null || v === 0) return '#F3F4F6';
    if (v >= 50) return '#1E3A8A';
    if (v >= 20) return '#3B82F6';
    if (v >= 10) return '#60A5FA';
    if (v >= 5)  return '#93C5FD';
    return '#DBEAFE';
  }

  function styleFor(feat) {
    const p = feat.getProperty('rentabilidad');
    const d = feat.getProperty('delta_rent');
    const e = feat.getProperty('estructura');
    let fill = '#D1D5DB';
    if (currentMode === 'rentabilidad') fill = colorRentabilidad(p);
    else if (currentMode === 'delta')    fill = colorDelta(d);
    else if (currentMode === 'estructura') fill = colorEstructura(e);
    return {
      fillColor: fill,
      fillOpacity: 0.7,
      strokeColor: '#1F2937',
      strokeWeight: 0.5,
      strokeOpacity: 0.6,
    };
  }

  function renderLegend() {
    const wrap = document.getElementById('map-legend');
    if (!wrap) return;
    let entries;
    if (currentMode === 'rentabilidad') {
      entries = [
        ['<15%','#7F1D1D'],['15-25%','#DC2626'],['25-35%','#F59E0B'],
        ['35-45%','#84CC16'],['45-55%','#10B981'],['≥55%','#065F46']
      ];
    } else if (currentMode === 'delta') {
      entries = [
        ['<-8 pp','#991B1B'],['-8 a -3','#F87171'],['-3 a +3','#FCD34D'],
        ['+3 a +8','#10B981'],['>+8 pp','#065F46']
      ];
    } else {
      entries = [
        ['Sin red','#F3F4F6'],['1-4','#DBEAFE'],['5-9','#93C5FD'],
        ['10-19','#60A5FA'],['20-49','#3B82F6'],['≥50','#1E3A8A']
      ];
    }
    wrap.innerHTML = entries.map(e =>
      `<span style="display:inline-flex;align-items:center;gap:4px">
        <span style="display:inline-block;width:14px;height:14px;background:${e[1]};border:1px solid #9CA3AF;border-radius:2px"></span>${e[0]}
       </span>`).join('');
  }

  window.initMapRent = function() {
    map = new google.maps.Map(document.getElementById('map-rent'), {
      center: { lat: 20.59, lng: -100.39 }, zoom: 9,
      mapTypeControl: false, streetViewControl: false, fullscreenControl: true,
      styles: [{featureType:'poi',stylers:[{visibility:'off'}]}],
    });
    infoWindow = new google.maps.InfoWindow();

    fetch(API_URL).then(r => r.json()).then(data => {
      if (data.error) { document.getElementById('map-rent').innerHTML = '<div style="padding:20px;color:#991B1B">'+data.error+'</div>'; return; }
      dataLoaded = data;
      map.data.addGeoJson(data);
      map.data.setStyle(styleFor);

      const bounds = new google.maps.LatLngBounds();
      map.data.forEach(f => {
        f.getGeometry().forEachLatLng(ll => bounds.extend(ll));
      });
      if (!bounds.isEmpty()) map.fitBounds(bounds);
      renderLegend();
    });

    map.data.addListener('mouseover', e => {
      map.data.overrideStyle(e.feature, { strokeWeight: 2, strokeOpacity: 1, fillOpacity: 0.85 });
    });
    map.data.addListener('mouseout', e => {
      map.data.revertStyle();
    });
    map.data.addListener('click', e => {
      const p = e.feature.getProperty.bind(e.feature);
      const colonias = p('colonias') || [];
      const coloniasHtml = colonias.length
        ? `<div style="margin-top:8px;padding-top:8px;border-top:1px solid #E5E7EB">
             <div style="font-size:11px;color:#6B7280;text-transform:uppercase;margin-bottom:4px">
               Colonias / localidades · ${colonias.length}
             </div>
             <div style="display:flex;flex-wrap:wrap;gap:3px;max-height:80px;overflow-y:auto">
               ${colonias.map(c => `<span style="font-size:10px;padding:2px 6px;background:#F3F4F6;border-radius:8px">${c}</span>`).join('')}
             </div>
           </div>`
        : '';

      // Desglose por partido / coalición / especial
      const desglose = p('desglose') || {};
      const validos = p('votos_validos') || 1;
      // Colores por partido conocido
      const colorPartido = {
        PAN: '#003C71', PRI: '#D81E2A', PRD: '#FFD800', MC: '#FF8200',
        PVEM: '#27A03A', MORENA: '#8B1F2E', PT: '#D71920', RSP: '#7E1F3B',
        QS: '#1E40AF', PES: '#7C3AED', FM: '#0EA5E9',
        NULOS: '#9CA3AF', NO_REGISTRADAS: '#6B7280'
      };
      const isCoalicion = code => /[-_]/.test(code);
      const desglosePartidos = [];
      const desgloseCoaliciones = [];
      const desgloseEspeciales = [];
      for (const [code, votos] of Object.entries(desglose)) {
        const entry = { code, votos, pct: validos > 0 ? (votos / validos) * 100 : 0 };
        if (code === 'NULOS' || code === 'NO_REGISTRADAS') desgloseEspeciales.push(entry);
        else if (isCoalicion(code)) desgloseCoaliciones.push(entry);
        else desglosePartidos.push(entry);
      }
      const renderFila = (e) => {
        const c = colorPartido[e.code] || '#6B7280';
        return `<tr>
          <td><span style="display:inline-block;width:10px;height:10px;background:${c};border-radius:2px;margin-right:6px;vertical-align:middle"></span>${e.code}</td>
          <td style="text-align:right;font-variant-numeric:tabular-nums">${e.votos.toLocaleString()}</td>
          <td style="text-align:right;color:#6B7280;font-variant-numeric:tabular-nums">${e.pct.toFixed(1)}%</td>
        </tr>`;
      };
      const desgloseHtml = (desglosePartidos.length || desgloseCoaliciones.length)
        ? `<details style="margin-top:8px;padding-top:8px;border-top:1px solid #E5E7EB" open>
             <summary style="cursor:pointer;font-size:11px;color:#6B7280;text-transform:uppercase;font-weight:600;margin-bottom:4px;list-style:none">
               ▾ Votos por opción · ${Object.keys(desglose).length}
             </summary>
             <table style="font-size:11px;width:100%;margin-top:6px">
               <thead><tr style="color:#9CA3AF;font-weight:400">
                 <th style="text-align:left;font-weight:400">Opción</th>
                 <th style="text-align:right;font-weight:400">Votos</th>
                 <th style="text-align:right;font-weight:400">% válidos</th>
               </tr></thead>
               <tbody>${desglosePartidos.map(renderFila).join('')}</tbody>
               ${desgloseCoaliciones.length ? `<tbody><tr><td colspan="3" style="font-size:10px;color:#9CA3AF;padding-top:6px">Coaliciones</td></tr>${desgloseCoaliciones.map(renderFila).join('')}</tbody>` : ''}
               ${desgloseEspeciales.length ? `<tbody><tr><td colspan="3" style="font-size:10px;color:#9CA3AF;padding-top:6px">No válidos</td></tr>${desgloseEspeciales.map(renderFila).join('')}</tbody>` : ''}
             </table>
           </details>`
        : '';

      // Subtítulos: ámbito de la elección + ubicación geográfica
      const meta = [];
      if (p('ambito_nombre') || p('ambito_codigo')) meta.push(p('ambito_nombre') || p('ambito_codigo'));
      const ubic = [];
      if (p('municipio'))    ubic.push(`<span style="display:inline-block;padding:1px 6px;background:#DBEAFE;color:#1E40AF;border-radius:8px;font-size:10px;font-weight:600">${p('municipio')}</span>`);
      if (p('distrito_num')) ubic.push(`<span style="display:inline-block;padding:1px 6px;background:#FEF3C7;color:#92400E;border-radius:8px;font-size:10px;font-weight:600">Distrito ${p('distrito_num')}</span>`);

      const html = `
        <div style="font-family:Inter,sans-serif;font-size:13px;min-width:280px;max-width:360px">
          <div style="font-weight:700;font-size:15px;margin-bottom:4px">Sección ${p('num_seccion')}</div>
          ${meta.length ? `<div style="font-size:12px;color:#6B7280;margin-bottom:6px">${meta.join(' · ')}</div>` : ''}
          ${ubic.length ? `<div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:10px">${ubic.join('')}</div>` : ''}
          <table style="font-size:12px;width:100%">
            <tr><td>Voto efectivo PAN</td><td style="text-align:right;font-weight:600">${(p('voto_efectivo')||0).toLocaleString()}</td></tr>
            <tr><td>Votos válidos</td><td style="text-align:right">${(p('votos_validos')||0).toLocaleString()}</td></tr>
            <tr><td>Votos emitidos</td><td style="text-align:right">${(p('votos_emitidos')||0).toLocaleString()}</td></tr>
            <tr><td>Lista nominal</td><td style="text-align:right">${(p('lista_nominal')||0).toLocaleString()}</td></tr>
            <tr><td>Rentabilidad</td><td style="text-align:right;font-weight:700;color:${colorRentabilidad(p('rentabilidad'))}">${p('rentabilidad')}%</td></tr>
            ${p('participacion') !== null ? `<tr><td>Participación</td><td style="text-align:right">${p('participacion')}%</td></tr>` : ''}
            ${p('rent_prev') !== null ? `<tr><td>Anterior</td><td style="text-align:right;color:#6B7280">${p('rent_prev')}%</td></tr>` : ''}
            ${p('delta_rent') !== null ? `<tr><td>Δ tendencia</td><td style="text-align:right;font-weight:600;color:${p('delta_rent')>=0?'#065F46':'#991B1B'}">${p('delta_rent')>=0?'+':''}${p('delta_rent')} pp</td></tr>` : ''}
            <tr><td>Red ciudadana</td><td style="text-align:right">${p('estructura') || 0}</td></tr>
          </table>
          ${desgloseHtml}
          ${coloniasHtml}
        </div>`;
      infoWindow.setContent(html);
      infoWindow.setPosition(e.latLng);
      infoWindow.open(map);
    });
  };

  // Selector de modo
  document.querySelectorAll('input[name="mapMode"]').forEach(r => {
    r.addEventListener('change', e => {
      currentMode = e.target.value;
      if (map) map.data.setStyle(styleFor);
      renderLegend();
    });
  });
})();
</script>
<?php if ($gmapsKey): ?>
  <script async src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($gmapsKey) ?>&callback=initMapRent&loading=async&v=weekly"></script>
<?php else: ?>
  <script>document.getElementById('map-rent').innerHTML = '<div style="padding:20px;color:#991B1B">Google Maps API key no configurada</div>';</script>
<?php endif; ?>

<?php include __DIR__ . '/../partials/layout_bottom.php'; ?>
