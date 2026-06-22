<?php
/**
 * descargar_zendesk.php — Consulta en vivo de tickets a la Zendesk Search API
 * y previsualización (no importa nada a la BD; solo muestra lo que baja).
 */
declare(strict_types=1);
set_time_limit(120);
ignore_user_abort(true);   // que un corte del navegador no deje la importación a medias

require __DIR__ . '/db.php';           // dispara el guard del portal (zendesk)
require_once __DIR__ . '/_zendesk_lib.php';
$pdo = db();
$cfg = require __DIR__ . '/config.php';
$api = $cfg['zendesk_api'] ?? [];

// Conector = herramienta de escritura. Solo editor/admin; los visores no entran.
require_editor('zendesk');

function cf(array $ticket, $id) {
    foreach (($ticket['custom_fields'] ?? []) as $f) {
        if ((string)$f['id'] === (string)$id) return $f['value'];
    }
    return null;
}

$config_ok = !empty($api['subdomain']) && !empty($api['user']) && !empty($api['token']);

/* Mapeo: campo_id => columna destino (para mostrar y para importar) */
$mapeo   = zd_cargar_mapeo($pdo);
$mapaCol = [];
foreach ($mapeo as $m) { $mapaCol[(string)$m['campo_id']] = $m; }

/* ---- AJAX (devuelve JSON y termina): sync / ventana / incremental ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['accion'] ?? '', ['ajax_sync', 'ajax_importar', 'ajax_incremental', 'ajax_asignar'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_check())      { echo json_encode(['error' => 'Sesión expirada']); exit; }
    if (!$mapeo)            { echo json_encode(['error' => 'Sin mapeo: importa sql/zendesk_mapeo.sql']); exit; }
    if (!$config_ok)        { echo json_encode(['error' => 'Faltan credenciales del API']); exit; }
    $uid = (int)(Auth::user()['id'] ?? 0) ?: null;
    try {
        if (($_POST['accion']) === 'ajax_sync') {
            [$add] = zd_sincronizar_estructura($pdo, $mapeo);
            echo json_encode(['ok' => true, 'agregadas' => $add]); exit;
        }

        if (($_POST['accion']) === 'ajax_asignar') {
            // Cruce espacial en una sola pasada al terminar el sync (no por página).
            @set_time_limit(300);
            zd_asignar_secciones($pdo);
            echo json_encode(['ok' => true]); exit;
        }

        if (($_POST['accion']) === 'ajax_incremental') {
            // Exportación incremental: 1 página por llamada (cursor-based, sin tope de 1000)
            $cursor = (string)($_POST['cursor'] ?? '');
            $start  = (string)($_POST['start'] ?? '');
            $ts = ($start !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) ? strtotime($start . ' 00:00:00') : strtotime('-7 days');
            $r = zd_incremental($api, $cursor, $ts);
            if (!empty($r['rate_limited'])) { echo json_encode(['rate_limited' => true]); exit; }
            if (!empty($r['error']))        { echo json_encode(['error' => $r['error']]); exit; }
            [$ok, $errs] = zd_importar($pdo, $api, $r['tickets'], $mapeo);
            zd_log($pdo, ['desde'=>($start ?: date('Y-m-d', $ts)), 'hasta'=>date('Y-m-d'), 'tag'=>'', 'traidos'=>count($r['tickets']),
                'guardados'=>$ok, 'errores'=>count($errs), 'tope'=>0, 'origen'=>'incremental', 'usuario_id'=>$uid]);
            zd_log_errores($pdo, $errs, 'incremental');
            echo json_encode([
                'ok' => $ok, 'fetched' => count($r['tickets']), 'errores' => count($errs),
                'next' => $r['fin'] ? null : ($r['next'] ?? null), 'fin' => $r['fin'], 'end_time' => $r['end_time'],
            ]); exit;
        }

        // ajax_importar: importa UNA ventana [desde, hasta] (Search API)
        $d  = (string)($_POST['desde'] ?? '');
        $h  = (string)($_POST['hasta'] ?? '');
        $tg = trim((string)($_POST['tag'] ?? ''));
        [$res, $err, $tot, $q] = zd_buscar($api, $d, $h, $tg, 1000);
        if ($err) { echo json_encode(['error' => $err, 'desde' => $d, 'hasta' => $h]); exit; }
        [$ok, $errs] = zd_importar($pdo, $api, $res, $mapeo);
        zd_log($pdo, ['desde'=>$d, 'hasta'=>$h, 'tag'=>$tg, 'traidos'=>count($res),
            'guardados'=>$ok, 'errores'=>count($errs), 'tope'=>count($res) >= 1000,
            'origen'=>'backfill', 'usuario_id'=>$uid]);
        zd_log_errores($pdo, $errs, 'backfill');
        echo json_encode([
            'ok' => $ok, 'fetched' => count($res), 'total' => (int)$tot,
            'errores' => count($errs), 'desde' => $d, 'hasta' => $h,
            'tope' => count($res) >= 1000,
        ]); exit;
    } catch (Throwable $e) { echo json_encode(['error' => $e->getMessage()]); exit; }
}

/* ---- Acciones POST (sincronizar estructura / importar) ---- */
$accionMsg = ''; $accionTipo = 'ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $accionMsg = 'Sesión expirada, recarga e intenta de nuevo.'; $accionTipo = 'err';
    } elseif (($_POST['accion'] ?? '') === 'sincronizar') {
        try {
            if (!$mapeo) throw new RuntimeException('No hay mapeo. Importa sql/zendesk_mapeo.sql primero.');
            [$add, $tot] = zd_sincronizar_estructura($pdo, $mapeo);
            $accionMsg = $add
                ? 'Estructura sincronizada. Columnas agregadas a tickets: ' . implode(', ', $add)
                : 'La estructura ya estaba al día (sin columnas nuevas).';
        } catch (Throwable $e) { $accionMsg = 'Error al sincronizar: ' . $e->getMessage(); $accionTipo = 'err'; }
    } elseif (($_POST['accion'] ?? '') === 'importar') {
        $pdesde = (string)($_POST['desde'] ?? ''); $phasta = (string)($_POST['hasta'] ?? '');
        $ptag   = trim((string)($_POST['tag'] ?? '')); $plim = min(2000, max(10, (int)($_POST['limite'] ?? 50)));
        try {
            if (!$mapeo) throw new RuntimeException('No hay mapeo. Importa sql/zendesk_mapeo.sql primero.');
            zd_sincronizar_estructura($pdo, $mapeo);   // asegura columnas antes de insertar
            [$res, $err, $tot, $q] = zd_buscar($api, $pdesde, $phasta, $ptag, $plim);
            if ($err) throw new RuntimeException('Al consultar Zendesk: ' . $err);
            [$ok, $errs] = zd_importar($pdo, $api, $res, $mapeo);
            zd_log($pdo, ['desde'=>$pdesde, 'hasta'=>$phasta, 'tag'=>$ptag, 'traidos'=>count($res),
                'guardados'=>$ok, 'errores'=>count($errs), 'tope'=>count($res) >= $plim,
                'origen'=>'manual', 'usuario_id'=>(int)(Auth::user()['id'] ?? 0) ?: null]);
            zd_log_errores($pdo, $errs, 'manual');
            $accionMsg = "Importados/actualizados $ok de " . count($res) . " ticket(s) en la tabla tickets."
                       . ($errs ? ' Con ' . count($errs) . ' error(es): ' . htmlspecialchars(implode(' · ', array_slice($errs, 0, 3))) : '');
            $accionTipo = $errs ? 'err' : 'ok';
        } catch (Throwable $e) { $accionMsg = 'Error al importar: ' . $e->getMessage(); $accionTipo = 'err'; }
    }
}

/* ---- Parámetros del formulario (GET, previsualización) ---- */
$buscar  = isset($_GET['buscar']);
$desde   = $_GET['desde'] ?? date('Y-m-d', strtotime('-7 days'));
$hasta   = $_GET['hasta'] ?? date('Y-m-d');
$tag     = trim((string)($_GET['tag'] ?? ($api['tag_default'] ?? '')));
$limite  = min(2000, max(10, (int)($_GET['limite'] ?? 50)));

$resultados = []; $error = ''; $total_api = 0; $query = '';
if ($buscar && $config_ok) {
    [$resultados, $error, $total_api, $query] = zd_buscar($api, $desde, $hasta, $tag, $limite);
}

/* Campos detectados en los resultados (con su columna destino del mapeo) */
$detectados = [];
foreach ($resultados as $t) {
    foreach (($t['custom_fields'] ?? []) as $f) {
        $id = (string)$f['id'];
        if (!isset($detectados[$id])) {
            $m = $mapaCol[$id] ?? null;
            $detectados[$id] = [
                'label'   => $m['nombre'] ?? '—',
                'columna' => $m['columna'] ?? null,
                'sample'  => $f['value'],
                'novacios'=> 0,
            ];
        }
        if ($f['value'] !== null && $f['value'] !== '') {
            $detectados[$id]['novacios']++;
            if ($detectados[$id]['sample'] === null || $detectados[$id]['sample'] === '') $detectados[$id]['sample'] = $f['value'];
        }
    }
}
ksort($detectados);

/* Bitácora de importaciones (trazabilidad) */
$logRows = []; $logResumen = null;
try {
    $logRows = $pdo->query("SELECT * FROM zendesk_import_log ORDER BY ejecutado_en DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
    $logResumen = $pdo->query("SELECT COUNT(*) ejecuciones, COALESCE(SUM(guardados),0) guardados,
                                      COALESCE(SUM(traidos),0) traidos, MIN(`desde`) min_desde,
                                      MAX(`hasta`) max_hasta, MAX(ejecutado_en) ultima
                                 FROM zendesk_import_log")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* tabla aún no creada */ }

/* Visor de errores de importación */
$errRows = []; $errTotal = 0;
if (($_POST['accion'] ?? '') === 'limpiar_errores' && csrf_check()) {
    try { $pdo->exec("DELETE FROM zendesk_import_errores"); } catch (Throwable $e) {}
}
try {
    $errTotal = (int)$pdo->query("SELECT COUNT(*) FROM zendesk_import_errores")->fetchColumn();
    $errRows  = $pdo->query("SELECT ejecutado_en, ticket_id, origen, mensaje
                               FROM zendesk_import_errores ORDER BY ejecutado_en DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* tabla aún no creada */ }
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Descargar tickets de Zendesk · Querétaro</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{--bg:#f5f7fb;--surface:#fff;--border:#d9e2f0;--text:#1f2937;--mut:#5b667a;--accent:#254185;--accent2:#005ab2;--ok:#188a5b;--warn:#d99000;--err:#ce3a2b}
  *{box-sizing:border-box} html,body{margin:0;background:var(--bg);color:var(--text);font-size:14px}
  .crumb{padding:12px 24px;font-size:13px;color:var(--mut)} .crumb a{color:var(--accent2);text-decoration:none}
  .nav{display:flex;gap:6px;flex-wrap:wrap;padding:0 24px 12px}
  .nav a{font-size:12px;padding:7px 12px;border:1px solid var(--border);border-radius:7px;color:var(--text);text-decoration:none;background:#fff;font-weight:500}
  .nav a.active{background:var(--accent);color:#fff;border-color:var(--accent)}
  .wrap{padding:6px 24px 28px}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:18px;box-shadow:0 2px 6px rgba(37,65,133,.06);margin-bottom:18px}
  form.filtros{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end}
  .field{display:flex;flex-direction:column;gap:4px} .field label{font-size:11px;font-weight:600;color:var(--mut);text-transform:uppercase;letter-spacing:.3px}
  .field input{border:1px solid var(--border);border-radius:8px;padding:9px 11px;font-size:14px;font-family:inherit}
  .btn{background:var(--accent);color:#fff;border:0;border-radius:8px;padding:11px 18px;font-weight:700;font-size:14px;cursor:pointer;font-family:inherit}
  .btn.sec{background:#fff;color:var(--accent);border:1px solid var(--accent)}
  table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden}
  th{background:#eef5fc;color:var(--accent);text-align:left;padding:10px 12px;font-size:12px;text-transform:uppercase;letter-spacing:.3px}
  td{padding:10px 12px;border-top:1px solid var(--border);font-size:13px;vertical-align:top}
  tbody tr:hover{background:#f7fafe}
  .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;background:rgba(42,158,218,.12);color:var(--accent2)}
  .muted{color:var(--mut)} .mono{font-family:'Space Mono',monospace,monospace;font-size:12px}
  .alert{padding:12px 14px;border-radius:10px;margin-bottom:16px;font-size:13.5px}
  .alert.err{background:rgba(206,58,43,.10);color:#991b1b;border:1px solid rgba(206,58,43,.25)}
  .alert.ok{background:rgba(24,138,91,.10);color:#166534;border:1px solid rgba(24,138,91,.25)}
  details summary{cursor:pointer;color:var(--accent2);font-size:12px}
  pre{background:#0b1430;color:#cfe0ff;padding:12px;border-radius:8px;overflow:auto;font-size:11.5px;max-height:320px}
  h1{color:var(--accent)} h3{color:var(--accent)}
</style>
</head>
<body>
<?php $portalModulo = 'Zendesk'; include __DIR__ . '/../_portalbar.php'; ?>

<div class="crumb"><a href="dashboard.php">Dashboard</a> &rarr; Descargar de Zendesk</div>
<?php include __DIR__ . '/_navzendesk.php'; ?>

<div class="wrap">
  <div class="page-head" style="margin-bottom:14px">
    <h1 style="margin:0">Descargar tickets de Zendesk</h1>
    <p class="muted" style="margin:4px 0 0">Consulta en vivo a la API. Esta pantalla <strong>solo previsualiza</strong> — no guarda nada todavía.</p>
  </div>

  <?php if (!$config_ok): ?>
    <div class="alert err">Faltan credenciales de la API de Zendesk en <code>config/config.php</code> (modulos.zendesk.zendesk_api).</div>
  <?php endif; ?>
  <?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($accionMsg): ?><div class="alert <?= $accionTipo === 'ok' ? 'ok' : 'err' ?>"><?= $accionMsg ?></div><?php endif; ?>

  <!-- Conector: sincronizar estructura + importar a la base -->
  <div class="card" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between">
    <div>
      <strong style="color:var(--accent)">Conector Zendesk → tabla <code>tickets</code></strong>
      <div class="muted" style="font-size:12.5px">
        Mapeo cargado: <?= count($mapeo) ?> campo(s).
        <?= $mapeo ? '' : ' <span style="color:var(--err)">Importa <code>sql/zendesk_mapeo.sql</code> primero.</span>' ?>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <form method="post" action="descargar_zendesk.php" style="display:inline"
            onsubmit="return confirm('Agregará a la tabla tickets las columnas que falten del mapeo. ¿Continuar?')">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="sincronizar">
        <button class="btn sec" <?= $mapeo ? '' : 'disabled' ?>>🧱 Sincronizar estructura</button>
      </form>
      <form method="post" action="descargar_zendesk.php" style="display:inline"
            onsubmit="return confirm('Descargará e IMPORTARÁ a la base los tickets del rango/tag actuales (upsert por ID). ¿Continuar?')">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="importar">
        <input type="hidden" name="desde" value="<?= htmlspecialchars($desde) ?>">
        <input type="hidden" name="hasta" value="<?= htmlspecialchars($hasta) ?>">
        <input type="hidden" name="tag"   value="<?= htmlspecialchars($tag) ?>">
        <input type="hidden" name="limite" value="<?= (int)$limite ?>">
        <button class="btn" <?= ($mapeo && $config_ok) ? '' : 'disabled' ?>>⬇ Importar a la base</button>
      </form>
    </div>
  </div>

  <?php $bf_desde_def = ($logResumen['max_hasta'] ?? '') ?: (date('Y') . '-01-01'); ?>

  <!-- MODO A: Exportación incremental (sin tope de 1000) -->
  <div class="card">
    <h3 style="margin-top:0">Exportación incremental <span class="pill" style="background:rgba(24,138,91,.14);color:#166534">recomendado</span></h3>
    <p class="muted">
      Trae <strong>todo desde la fecha indicada</strong> sin el límite de 1000: pagina por cursor hasta terminar. Ideal para el año completo o para sincronizar cuando quieras. Idempotente.
      <?php if (!empty($logResumen['max_hasta'])): ?> Última importación: hasta <strong><?= htmlspecialchars($logResumen['max_hasta']) ?></strong>.<?php endif; ?>
    </p>
    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
      <div class="field"><label>Sincronizar desde</label>
        <input type="date" id="inc-desde" value="<?= htmlspecialchars($bf_desde_def) ?>" max="<?= date('Y-m-d') ?>">
        <a href="#" id="inc-anio" style="font-size:11px;color:var(--accent2);text-decoration:none">↺ todo el año</a>
      </div>
      <button class="btn" id="inc-start" <?= ($mapeo && $config_ok) ? '' : 'disabled' ?>>▶ Sincronizar (incremental)</button>
      <button class="btn sec" id="inc-stop" style="display:none">⏹ Detener</button>
    </div>
    <div id="inc-wrap" style="display:none;margin-top:16px">
      <div id="inc-status" class="muted mono" style="font-size:12px"></div>
      <div id="inc-totales" style="margin-top:6px;font-weight:700;color:var(--accent)"></div>
      <div id="inc-log" class="mono" style="margin-top:8px;font-size:11px;max-height:160px;overflow:auto;color:var(--mut)"></div>
    </div>
  </div>

  <!-- MODO B: por rango y tag (Search API, ventanas con auto-división) -->
  <div class="card">
    <h3 style="margin-top:0">Importar por rango / tag (Search API)</h3>
    <p class="muted">
      Para un <strong>rango o tag específicos</strong>. Va por ventanas; si alguna llega al tope de 1000 se re-divide por día sola.
    </p>
    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
      <div class="field"><label>Importar desde</label>
        <input type="date" id="bf-desde" value="<?= htmlspecialchars($bf_desde_def) ?>" max="<?= date('Y-m-d') ?>">
        <a href="#" id="bf-anio" style="font-size:11px;color:var(--accent2);text-decoration:none">↺ todo el año en curso</a>
      </div>
      <div class="field"><label>Tamaño de ventana</label>
        <select id="bf-ventana">
          <option value="1">Diario (alto volumen)</option>
          <option value="3">Cada 3 días</option>
          <option value="7" selected>Semanal (recomendado)</option>
          <option value="15">Quincenal</option>
          <option value="30">Mensual</option>
        </select>
      </div>
      <div class="field"><label>Tag (opcional)</label>
        <input type="text" id="bf-tag" value="<?= htmlspecialchars($tag) ?>" placeholder="(todos)" style="min-width:200px">
      </div>
      <button class="btn" id="bf-start" <?= ($mapeo && $config_ok) ? '' : 'disabled' ?>>▶ Importar hasta hoy</button>
      <button class="btn sec" id="bf-stop" style="display:none">⏹ Detener</button>
    </div>
    <div id="bf-wrap" style="display:none;margin-top:16px">
      <div style="height:12px;background:#e7eef9;border-radius:999px;overflow:hidden">
        <div id="bf-bar" style="height:100%;width:0;background:var(--accent);transition:width .2s"></div>
      </div>
      <div id="bf-status" class="muted mono" style="margin-top:8px;font-size:12px"></div>
      <div id="bf-totales" style="margin-top:6px;font-weight:700;color:var(--accent)"></div>
      <div id="bf-log" class="mono" style="margin-top:8px;font-size:11px;max-height:160px;overflow:auto;color:var(--mut)"></div>
    </div>
  </div>

  <script>
  const BF_CSRF = <?= json_encode(csrf_token()) ?>;
  (function(){
    const $ = id => document.getElementById(id);
    let detener = false;
    const fmt = d => d.toISOString().slice(0,10);
    const sleep = ms => new Promise(r => setTimeout(r, ms));

    async function post(data){
      const body = new URLSearchParams(Object.assign({csrf: BF_CSRF}, data));
      const r = await fetch('descargar_zendesk.php', {method:'POST', body, headers:{'X-Requested-With':'fetch'}});
      return r.json();
    }

    function ventanas(dias, desdeStr){
      const hoy = new Date();
      const ini = desdeStr ? new Date(desdeStr + 'T00:00:00') : new Date(hoy.getFullYear(), 0, 1);
      const out = [];
      let cur = new Date(ini);
      while (cur <= hoy){
        const a = new Date(cur);
        const b = new Date(cur); b.setDate(b.getDate() + (dias - 1));
        if (b > hoy) b.setTime(hoy.getTime());
        out.push([fmt(a), fmt(b)]);
        cur.setDate(cur.getDate() + dias);
      }
      return out;
    }

    // Lista de días [día,…] entre dos fechas (inclusive)
    function ventanasDias(d, h){
      const out = []; let cur = new Date(d + 'T00:00:00'); const fin = new Date(h + 'T00:00:00');
      while (cur <= fin){ out.push(fmt(cur)); cur.setDate(cur.getDate() + 1); }
      return out;
    }
    async function importarUno(d, h, tag){
      try { return await post({accion:'ajax_importar', desde:d, hasta:h, tag}); }
      catch(e){ return {error:'red/timeout'}; }
    }
    // Importa una ventana; si Zendesk la corta en 1000, la re-divide por día.
    async function importarVentana(d, h, tag){
      const r = await importarUno(d, h, tag);
      if (r.error || !r.tope || d === h) return r;          // sin tope o ya es 1 día
      log(`↳ ${d}→${h} llena (1000): re-dividiendo por día…`);
      const dias = ventanasDias(d, h);
      let ok = 0, fetched = 0, errores = 0, tope = false;
      for (const dd of dias){
        if (detener) break;
        const rr = await importarUno(dd, dd, tag);
        if (rr.error){ errores++; log(`  ✗ ${dd}: ${rr.error}`); }
        else { ok += rr.ok||0; fetched += rr.fetched||0; errores += rr.errores||0;
               if (rr.tope){ tope = true; log(`  ⚠ ${dd}: 1000+ en un solo día (revisa la exportación incremental)`); } }
        await sleep(300);
      }
      return {ok, fetched, errores, tope, split:true};
    }

    // Atajo: "todo el año en curso" -> fija la fecha al 1 de enero
    $('bf-anio').addEventListener('click', (e) => {
      e.preventDefault();
      $('bf-desde').value = new Date().getFullYear() + '-01-01';
    });

    $('bf-start').addEventListener('click', async () => {
      const dias  = parseInt($('bf-ventana').value, 10) || 7;
      const tag   = $('bf-tag').value.trim();
      const desde = $('bf-desde').value || (new Date().getFullYear() + '-01-01');
      const wins  = ventanas(dias, desde);
      detener = false;
      $('bf-start').style.display = 'none';
      $('bf-stop').style.display  = '';
      $('bf-wrap').style.display  = '';
      $('bf-log').innerHTML = '';
      $('bf-status').textContent = 'Preparando estructura…';

      const sync = await post({accion:'ajax_sync'});
      if (sync.error){ $('bf-status').textContent = '✗ ' + sync.error; fin(); return; }

      let okTot = 0, errTot = 0, fetchTot = 0;
      for (let i = 0; i < wins.length; i++){
        if (detener){ $('bf-status').textContent = '⏸ Detenido en la ventana ' + (i+1) + '/' + wins.length; break; }
        const [d,h] = wins[i];
        $('bf-status').textContent = `Ventana ${i+1}/${wins.length} · ${d} → ${h}…`;
        const r = await importarVentana(d, h, tag);   // auto-divide por día si se llena
        if (r.error){
          errTot++;
          log(`✗ ${d}→${h}: ${r.error}`);
        } else {
          okTot += r.ok||0; fetchTot += r.fetched||0;
          if (r.errores) errTot += r.errores;
          log(`✓ ${d}→${h}: ${r.ok||0} guardados${r.split?' (re-dividido por día)':''}`);
        }
        $('bf-bar').style.width = Math.round((i+1)/wins.length*100) + '%';
        $('bf-totales').textContent = `Guardados/actualizados: ${okTot} · Traídos: ${fetchTot} · Errores: ${errTot}`;
        await sleep(450);  // pausa gentil con el API
      }
      // Cruce espacial UNA sola vez al terminar (no por ventana).
      if (!detener) {
        $('bf-status').textContent = 'Asignando secciones (cruce espacial)…';
        try { await post({accion:'ajax_asignar'}); log('✓ Secciones asignadas a los tickets nuevos.'); $('bf-status').textContent = `✓ Año en curso completado (${wins.length} ventanas).`; }
        catch(e){ log('⚠ Tickets importados, pero la asignación de secciones quedó pendiente (córrela por CLI).'); }
      }
      fin();
    });

    $('bf-stop').addEventListener('click', () => { detener = true; });

    function fin(){ $('bf-stop').style.display='none'; $('bf-start').style.display=''; }
    function log(t){ const d=document.createElement('div'); d.textContent=t; $('bf-log').prepend(d); }

    /* ===== MODO A: Exportación incremental (cursor, sin tope de 1000) ===== */
    let incStop = false;
    function incLog(t){ const d=document.createElement('div'); d.textContent=t; $('inc-log').prepend(d); }
    function incFin(){ $('inc-stop').style.display='none'; $('inc-start').style.display=''; }
    if ($('inc-anio')) $('inc-anio').addEventListener('click', e=>{ e.preventDefault(); $('inc-desde').value = new Date().getFullYear()+'-01-01'; });
    if ($('inc-stop')) $('inc-stop').addEventListener('click', ()=>{ incStop = true; });
    if ($('inc-start')) $('inc-start').addEventListener('click', async () => {
      incStop = false;
      $('inc-start').style.display='none'; $('inc-stop').style.display=''; $('inc-wrap').style.display=''; $('inc-log').innerHTML='';
      $('inc-status').textContent = 'Preparando estructura…';
      const sync = await post({accion:'ajax_sync'});
      if (sync.error){ $('inc-status').textContent = '✗ ' + sync.error; incFin(); return; }

      const start = $('inc-desde').value || (new Date().getFullYear()+'-01-01');
      let cursor = '', pagina = 0, okTot = 0, fetchTot = 0, errTot = 0;
      while (true){
        if (incStop){ $('inc-status').textContent = '⏸ Detenido en la página ' + pagina; break; }
        pagina++;
        $('inc-status').textContent = 'Página ' + pagina + ' · importando…';
        let r;
        try { r = await post(cursor ? {accion:'ajax_incremental', cursor} : {accion:'ajax_incremental', start}); }
        catch(e){ r = {error:'red/timeout'}; }
        if (r.rate_limited){ $('inc-status').textContent = '⏳ Límite de Zendesk; esperando 20s…'; pagina--; await sleep(20000); continue; }
        if (r.error){ errTot++; incLog('✗ página ' + pagina + ': ' + r.error); break; }
        okTot += r.ok||0; fetchTot += r.fetched||0; errTot += r.errores||0;
        incLog('✓ pág. ' + pagina + ': ' + (r.fetched||0) + ' traídos, ' + (r.ok||0) + ' guardados' + (r.errores ? (' · '+r.errores+' err') : ''));
        $('inc-totales').textContent = `Páginas: ${pagina} · Guardados: ${okTot} · Traídos: ${fetchTot} · Errores: ${errTot}`;
        if (r.fin || !r.next){ $('inc-status').textContent = '✓ Sincronización completa (' + pagina + ' páginas, ' + okTot + ' guardados).'; break; }
        cursor = r.next;
        await sleep(2000);   // gentil con el rate limit del endpoint incremental
      }
      if (errTot > 0) incLog('⚠ Hubo ' + errTot + ' errores — recarga la página para verlos en el visor de errores.');
      // Cruce espacial UNA sola vez al terminar (no por página): asigna seccion_id.
      if (!incStop && pagina > 0) {
        $('inc-status').textContent = 'Asignando secciones (cruce espacial)…';
        try { await post({accion:'ajax_asignar'}); incLog('✓ Secciones asignadas a los tickets nuevos.'); $('inc-status').textContent = '✓ Sincronización completa (' + pagina + ' páginas, ' + okTot + ' guardados).'; }
        catch(e){ incLog('⚠ Tickets importados, pero la asignación de secciones quedó pendiente (vuelve a sincronizar o córrela por CLI).'); }
      }
      incFin();
    });
  })();
  </script>

  <!-- Historial / trazabilidad de importaciones -->
  <?php if ($logResumen && (int)$logResumen['ejecuciones'] > 0): ?>
  <div class="card">
    <h3 style="margin-top:0">Historial de importaciones</h3>
    <div style="display:flex;gap:22px;flex-wrap:wrap;margin-bottom:12px">
      <div><div class="muted" style="font-size:11px;text-transform:uppercase">Ejecuciones</div><div style="font-size:20px;font-weight:700;color:var(--accent)"><?= (int)$logResumen['ejecuciones'] ?></div></div>
      <div><div class="muted" style="font-size:11px;text-transform:uppercase">Guardados (acum.)</div><div style="font-size:20px;font-weight:700;color:var(--accent)"><?= (int)$logResumen['guardados'] ?></div></div>
      <div><div class="muted" style="font-size:11px;text-transform:uppercase">Rango cubierto</div><div style="font-size:14px;font-weight:600"><?= htmlspecialchars(($logResumen['min_desde'] ?? '—').' → '.($logResumen['max_hasta'] ?? '—')) ?></div></div>
      <div><div class="muted" style="font-size:11px;text-transform:uppercase">Última</div><div style="font-size:14px;font-weight:600"><?= htmlspecialchars($logResumen['ultima'] ?? '—') ?></div></div>
    </div>
    <table>
      <thead><tr><th>Cuándo</th><th>Rango</th><th>Tag</th><th>Origen</th><th>Traídos</th><th>Guardados</th><th>Errores</th></tr></thead>
      <tbody>
        <?php foreach ($logRows as $l): ?>
        <tr>
          <td class="muted mono" style="font-size:12px"><?= htmlspecialchars($l['ejecutado_en']) ?></td>
          <td class="mono" style="font-size:12px"><?= htmlspecialchars(($l['desde'] ?? '—').' → '.($l['hasta'] ?? '—')) ?></td>
          <td class="muted" style="font-size:12px"><?= $l['tag'] ? htmlspecialchars($l['tag']) : '—' ?></td>
          <td><span class="pill"><?= htmlspecialchars($l['origen']) ?></span><?= $l['tope'] ? ' <span style="color:var(--warn)">⚠ llena</span>' : '' ?></td>
          <td class="muted"><?= (int)$l['traidos'] ?></td>
          <td style="font-weight:600;color:var(--accent)"><?= (int)$l['guardados'] ?></td>
          <td class="muted" style="<?= (int)$l['errores']>0?'color:var(--err)':'' ?>"><?= (int)$l['errores'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Visor de errores de importación -->
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <h3 style="margin:0">Errores de importación
        <?php if ($errTotal > 0): ?><span class="pill" style="background:rgba(206,58,43,.12);color:#991b1b"><?= (int)$errTotal ?></span><?php endif; ?>
      </h3>
      <?php if ($errTotal > 0): ?>
      <form method="post" action="descargar_zendesk.php" onsubmit="return confirm('¿Borrar el registro de errores?')">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="limpiar_errores">
        <button class="btn sec" style="padding:6px 12px;font-size:13px">Limpiar</button>
      </form>
      <?php endif; ?>
    </div>
    <?php if (!$errRows): ?>
      <p class="muted" style="margin:10px 0 0">Sin errores registrados. 🎉</p>
    <?php else: ?>
      <p class="muted" style="margin:8px 0 12px">Tickets que no se pudieron guardar (últimos 100). Revisa el mensaje para corregir el mapeo o el dato.</p>
      <table>
        <thead><tr><th>Cuándo</th><th>Ticket</th><th>Origen</th><th>Error</th></tr></thead>
        <tbody>
          <?php foreach ($errRows as $er): ?>
          <tr>
            <td class="muted mono" style="font-size:12px;white-space:nowrap"><?= htmlspecialchars($er['ejecutado_en']) ?></td>
            <td class="mono"><?= $er['ticket_id'] ? '#'.htmlspecialchars($er['ticket_id']) : '—' ?></td>
            <td><span class="pill"><?= htmlspecialchars($er['origen'] ?? '—') ?></span></td>
            <td style="color:#991b1b;font-size:12.5px"><?= htmlspecialchars($er['mensaje'] ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <form class="filtros" method="get" action="descargar_zendesk.php">
      <div class="field"><label>Desde</label><input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>"></div>
      <div class="field"><label>Hasta</label><input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>"></div>
      <div class="field"><label>Tag (opcional)</label><input type="text" name="tag" value="<?= htmlspecialchars($tag) ?>" placeholder="servicio_recoleccion_tiliches" style="min-width:240px"></div>
      <div class="field"><label>Límite</label><input type="number" name="limite" value="<?= (int)$limite ?>" min="10" max="2000" style="width:90px"></div>
      <button class="btn" name="buscar" value="1">🔎 Consultar Zendesk</button>
    </form>
    <?php if ($query): ?><p class="muted mono" style="margin:12px 0 0">query: <?= htmlspecialchars($query) ?></p><?php endif; ?>
  </div>

  <?php if ($buscar && !$error): ?>
    <div class="alert ok">
      Se trajeron <strong><?= count($resultados) ?></strong> ticket(s)
      <?php if ($total_api): ?>(Zendesk reporta <strong><?= (int)$total_api ?></strong> coincidencias en total para esa búsqueda)<?php endif; ?>.
    </div>

    <?php if ($detectados): ?>
    <div class="card">
      <h3 style="margin-top:0">Campos personalizados detectados</h3>
      <p class="muted">Útil para mapear qué guardar. Los que tu integración ya usa salen con nombre.</p>
      <table>
        <thead><tr><th>ID del campo</th><th>Nombre conocido</th><th>Columna destino</th><th>Con valor</th><th>Ejemplo</th></tr></thead>
        <tbody>
          <?php foreach ($detectados as $id => $d): ?>
          <tr>
            <td class="mono"><?= htmlspecialchars((string)$id) ?></td>
            <td><?= $d['label'] !== '—' ? '<span class="pill">'.htmlspecialchars($d['label']).'</span>' : '<span class="muted">— sin mapear —</span>' ?></td>
            <td class="mono muted"><?= $d['columna'] ? htmlspecialchars($d['columna']) : '<span style="color:var(--warn)">— no se guarda —</span>' ?></td>
            <td class="muted"><?= (int)$d['novacios'] ?>/<?= count($resultados) ?></td>
            <td class="muted"><?= htmlspecialchars(mb_strimwidth(is_scalar($d['sample']) ? (string)$d['sample'] : json_encode($d['sample']), 0, 80, '…')) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <div class="card">
      <h3 style="margin-top:0">Previsualización de tickets</h3>
      <table>
        <thead><tr><th>ID</th><th>Creado</th><th>Estado</th><th>Asunto</th><th>Solicitante (campos)</th><th>Dirección</th><th>Detalle</th></tr></thead>
        <tbody>
          <?php foreach ($resultados as $t):
            $nom = trim((string)cf($t,30774801051675).' '.(string)cf($t,30774806575643).' '.(string)cf($t,30774844883355));
            $dir = (string)cf($t,30774968022939);
            $foto= (string)cf($t,32633428068507);
          ?>
          <tr>
            <td class="mono">#<?= htmlspecialchars((string)($t['id'] ?? '')) ?></td>
            <td class="muted mono"><?= htmlspecialchars(substr((string)($t['created_at'] ?? ''),0,10)) ?></td>
            <td><span class="pill"><?= htmlspecialchars((string)($t['status'] ?? '—')) ?></span></td>
            <td><?= htmlspecialchars(mb_strimwidth((string)($t['subject'] ?? ''),0,60,'…')) ?></td>
            <td><?= $nom !== '' ? htmlspecialchars($nom) : '<span class="muted">—</span>' ?></td>
            <td class="muted"><?= $dir !== '' ? htmlspecialchars(mb_strimwidth($dir,0,60,'…')) : '—' ?></td>
            <td>
              <details>
                <summary>ver JSON</summary>
                <pre><?= htmlspecialchars(json_encode($t, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php if ($foto): ?><a href="<?= htmlspecialchars($foto) ?>" target="_blank" class="pill">foto</a><?php endif; ?>
              </details>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$resultados): ?><tr><td colspan="7" class="muted" style="text-align:center;padding:20px">Sin tickets para ese rango/tag.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
