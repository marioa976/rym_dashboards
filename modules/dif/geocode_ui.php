<?php
/**
 * geocode_ui.php  —  Panel web para controlar la geocodificación.
 *
 * Abrir en navegador:  http://localhost:8888/dif/geocode_ui.php
 * (ajusta el puerto a tu MAMP)
 *
 * Permite:
 *  - Ver stats en vivo (cuántos OK, pendientes, errores).
 *  - Probar la API key con una sola llamada (sin gastar batch).
 *  - PREVIEW: ver las próximas N consultas sin gastar API.
 *  - PROCESAR: enviar exactamente N peticiones, viendo cada una en vivo.
 *  - Limpiar errores del cache / resetear marcados para reintento.
 */

declare(strict_types=1);

set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M');

$config = require __DIR__ . '/config.php';
$db = $config['db'];
$pdo = new PDO(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}",
    $db['user'], $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$pdo->exec("SET NAMES utf8mb4");

$action = $_GET['action'] ?? '';

// =====================================================================
// API endpoints (AJAX)
// =====================================================================
if ($action !== '') {
    try {
        switch ($action) {
            case 'stats':        json_out(getStats($pdo)); break;
            case 'sample':       json_out(getSample($pdo, (int)($_GET['limit'] ?? 10), !empty($_GET['reintenta']), $_GET['strategy'] ?? 'auto')); break;
            case 'pending-count': json_out(getPendingCount($pdo, !empty($_GET['reintenta']))); break;
            case 'next-pending':  json_out(getNextPending($pdo, !empty($_GET['reintenta']), (int)($_GET['after_id'] ?? 0))); break;
            case 'get-record':    json_out(getRecord($pdo, (int)($_GET['id'] ?? 0))); break;
            case 'lookup':        json_out(lookupManual($_GET['address'] ?? '', $config, !empty($_GET['raw']))); break;
            case 'apply':         json_out(applyResult(
                                      $pdo,
                                      (int)($_POST['id'] ?? 0),
                                      (float)($_POST['lat'] ?? 0),
                                      (float)($_POST['lng'] ?? 0),
                                      $_POST['address'] ?? '',
                                      $_POST['precision'] ?? null,
                                      $_POST['estrategia'] ?? 'manual'
                                  )); break;
            case 'skip':          json_out(skipRecord($pdo, (int)($_GET['id'] ?? 0), $_GET['motivo'] ?? 'SKIPPED')); break;
            case 'bbox-preview':  json_out(bboxPreview($pdo, bboxParams())); break;
            case 'bbox-cleanup':  json_out(bboxCleanup($pdo, bboxParams(), !empty($_GET['also_cache']))); break;
            case 'test':         json_out(testApi($config)); break;
            case 'clear-errores':
                $n = $pdo->exec("DELETE FROM geocode_cache WHERE status <> 'OK'");
                json_out(['ok' => true, 'eliminados' => (int)$n]);
                break;
            case 'reset-padron':
                $n = $pdo->exec("UPDATE padron
                                    SET geocode_status=NULL, geocode_estrategia=NULL,
                                        geocode_precision=NULL, geocode_address=NULL,
                                        geocode_at=NULL, geocode_intentos=0
                                  WHERE (latitud IS NULL OR longitud IS NULL)
                                    AND geocode_status IS NOT NULL");
                json_out(['ok' => true, 'reseteados' => (int)$n]);
                break;
            case 'process':
                streamProcess($pdo, $config, (int)($_GET['limit'] ?? 10), !empty($_GET['reintenta']), $_GET['strategy'] ?? 'auto');
                break;
            default:
                json_out(['error' => 'acción desconocida'], 400);
        }
    } catch (Throwable $e) {
        json_out(['error' => $e->getMessage()], 500);
    }
    exit;
}

// =====================================================================
// HTML
// =====================================================================
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Geocodificador Padrón DIF</title>
<style>
  :root{--bg:#0f172a;--card:#1e293b;--bd:#334155;--mut:#94a3b8;--fg:#e2e8f0;
       --ok:#10b981;--warn:#f59e0b;--err:#ef4444;--accent:#005ab2}
  *{box-sizing:border-box}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
       background:var(--bg);color:var(--fg);line-height:1.5}
  header{padding:16px 24px;border-bottom:1px solid var(--bd);
         display:flex;align-items:center;justify-content:space-between;gap:16px}
  h1{margin:0;font-size:20px}
  .container{padding:24px;max-width:1200px;margin:0 auto}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:24px}
  .stat{background:var(--card);border:1px solid var(--bd);border-radius:8px;padding:14px}
  .stat .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--mut)}
  .stat .val{font-size:24px;font-weight:600;margin-top:4px}
  .stat .val.ok{color:var(--ok)} .stat .val.err{color:var(--err)} .stat .val.warn{color:var(--warn)}

  .panel{background:var(--card);border:1px solid var(--bd);border-radius:8px;
         padding:18px;margin-bottom:18px}
  .panel h2{margin:0 0 12px;font-size:16px;color:var(--mut);text-transform:uppercase;letter-spacing:.5px}
  .row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
  label{font-size:13px;color:var(--mut)}
  input[type=number],select{background:#0f172a;border:1px solid var(--bd);color:var(--fg);
         padding:8px 12px;border-radius:6px;font-size:14px;min-width:90px}
  button{background:var(--accent);border:0;color:white;padding:8px 16px;border-radius:6px;
         cursor:pointer;font-size:14px;font-weight:500}
  button:hover{filter:brightness(1.15)}
  button:disabled{opacity:.4;cursor:not-allowed}
  button.secondary{background:#475569}
  button.danger{background:var(--err)}
  button.warn{background:var(--warn);color:#0f172a}
  .quick{display:inline-flex;gap:4px}
  .quick button{background:#334155;font-weight:400;padding:6px 10px;font-size:12px}
  .quick button.sel{background:var(--accent)}

  table{width:100%;border-collapse:collapse;font-size:13px}
  th,td{padding:8px 10px;text-align:left;border-bottom:1px solid var(--bd)}
  th{color:var(--mut);font-weight:500;font-size:11px;text-transform:uppercase;letter-spacing:.5px}
  tr:hover td{background:rgba(255,255,255,.02)}
  .tbl-wrap{max-height:480px;overflow:auto;border:1px solid var(--bd);border-radius:6px}
  .badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600}
  .badge.ok{background:rgba(16,185,129,.15);color:var(--ok)}
  .badge.err{background:rgba(239,68,68,.15);color:var(--err)}
  .badge.warn{background:rgba(245,158,11,.15);color:var(--warn)}
  .badge.info{background:rgba(59,130,246,.15);color:var(--accent)}
  .badge.gray{background:rgba(148,163,184,.15);color:var(--mut)}

  .bar{height:8px;background:#0f172a;border-radius:4px;overflow:hidden;margin:8px 0}
  .bar > div{height:100%;background:var(--accent);width:0%;transition:width .2s}

  .cost{font-size:12px;color:var(--mut);margin-top:4px}
  .cost strong{color:var(--fg)}

  pre{background:#0f172a;border:1px solid var(--bd);border-radius:6px;padding:12px;
      font-size:12px;max-height:300px;overflow:auto;margin:8px 0;white-space:pre-wrap;word-break:break-all}
  .hide{display:none}
  .small{font-size:12px;color:var(--mut)}
  .pill{padding:1px 6px;background:#334155;border-radius:3px;font-family:monospace;font-size:11px}
</style>
</head>
<body>

<?php $portalModulo='DIF'; @include __DIR__.'/../_portalbar.php'; ?>
<?php $navActive = 'geocode'; include __DIR__ . '/_nav.php'; ?>

<header>
  <h1>🗺️ Geocodificador Padrón DIF</h1>
  <button class="secondary" onclick="loadStats()">⟳ Actualizar stats</button>
</header>

<div class="container">

  <!-- STATS -->
  <div class="stats" id="stats"></div>

  <!-- API TEST -->
  <div class="panel">
    <h2>1. Validar API Key</h2>
    <div class="row">
      <button onclick="testApi()">🧪 Probar API (1 llamada)</button>
      <span class="small">Hace UNA petición de prueba a Google y muestra la respuesta cruda.</span>
    </div>
    <pre id="test-out" class="hide"></pre>
  </div>

  <!-- CONTROL -->
  <div class="panel">
    <h2>2. Procesar registros</h2>
    <div class="row">
      <label>Cantidad:
        <input type="number" id="limit" value="10" min="1" max="5000" style="width:90px">
      </label>
      <div class="quick">
        <button onclick="setLimit(1)">1</button>
        <button onclick="setLimit(10)" class="sel">10</button>
        <button onclick="setLimit(50)">50</button>
        <button onclick="setLimit(100)">100</button>
        <button onclick="setLimit(500)">500</button>
      </div>
      <label><input type="checkbox" id="reintenta" onchange="updateFilter()"> Reintentar errores</label>
    </div>
    <div class="row" style="margin-top:10px">
      <label>Estrategia:
        <select id="strategy" onchange="updateFilter()" style="background:#0f172a;border:1px solid var(--bd);color:var(--fg);padding:8px;border-radius:6px;min-width:240px">
          <option value="auto">Auto (mejor disponible por registro)</option>
          <option value="colonia">Sólo colonia (más barato, menos preciso)</option>
          <option value="colonia_cp">Colonia + CP</option>
          <option value="calle_colonia">Dirección completa (calle + colonia + CP)</option>
          <option value="cp">Sólo CP (último recurso)</option>
        </select>
      </label>
      <span class="small" id="strategy-hint"></span>
    </div>
    <div id="filter-info" style="margin-top:10px;padding:10px;background:#0f172a;border:1px solid var(--bd);border-radius:6px;font-size:13px">
      <span class="badge info" id="filter-badge">Filtrando…</span>
      <span style="margin-left:8px" id="filter-text"></span>
      <code style="display:block;margin-top:6px;font-size:11px;color:var(--mut)" id="filter-sql"></code>
    </div>
    <div class="row" style="margin-top:12px">
      <button onclick="preview()" class="secondary">👁 Preview (sin gastar)</button>
      <button onclick="process()" id="btn-process">▶ Procesar N</button>
      <button onclick="stop()" id="btn-stop" class="danger hide">■ Detener</button>
      <span id="cost" class="cost"></span>
    </div>
    <div class="bar"><div id="bar"></div></div>
    <div class="row" id="progress" style="font-size:13px;color:var(--mut)"></div>
  </div>

  <!-- RESULTADOS -->
  <div class="panel">
    <h2>3. Resultados</h2>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th><th>ID</th><th>Estrategia</th><th>Query enviada</th>
            <th>Status</th><th>Lat / Lng</th><th>Fuente</th>
          </tr>
        </thead>
        <tbody id="rows"></tbody>
      </table>
    </div>
  </div>

  <!-- MODO MANUAL -->
  <div class="panel">
    <h2>5. Geocodificación manual — tú decides</h2>

    <div class="row" style="margin-bottom:12px">
      <button onclick="loadNextPending()">⏭ Siguiente pendiente</button>
      <label>o ID puntual:
        <input type="number" id="man-id" placeholder="123" style="width:110px">
      </label>
      <button class="secondary" onclick="loadById()">Cargar</button>
      <span class="small" id="man-info"></span>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:12px">
      <div>
        <label class="small">Calle y número</label>
        <input type="text" id="man-calle" style="width:100%;background:#0f172a;border:1px solid var(--bd);color:var(--fg);padding:8px;border-radius:6px">
      </div>
      <div>
        <label class="small">Colonia</label>
        <input type="text" id="man-colonia" style="width:100%;background:#0f172a;border:1px solid var(--bd);color:var(--fg);padding:8px;border-radius:6px">
      </div>
      <div>
        <label class="small">CP</label>
        <input type="text" id="man-cp" style="width:100%;background:#0f172a;border:1px solid var(--bd);color:var(--fg);padding:8px;border-radius:6px">
      </div>
      <div>
        <label class="small">Delegación / Municipio</label>
        <input type="text" id="man-delegacion" style="width:100%;background:#0f172a;border:1px solid var(--bd);color:var(--fg);padding:8px;border-radius:6px">
      </div>
    </div>

    <div class="row" style="margin-bottom:8px">
      <label><input type="checkbox" id="man-raw"> Enviar tal cual (no agregar "Querétaro, México")</label>
    </div>

    <div class="row" style="margin-bottom:6px">
      <button onclick="manualSearch()">🔎 Buscar en Google</button>
      <button class="warn" onclick="manualSkip()">⤼ Saltar este (marcar SKIPPED)</button>
      <span class="small" id="man-query"></span>
    </div>

    <div id="man-results" style="margin-top:12px"></div>
  </div>

  <!-- LIMPIEZA GEOGRÁFICA -->
  <div class="panel">
    <h2>6. Limpieza geográfica — quitar coords fuera del Municipio</h2>
    <div class="small" style="margin-bottom:12px">
      Detecta y resetea registros cuya lat/lng cayó fuera del rectángulo del Municipio de Querétaro.
      El default cubre la mancha urbana; ajústalo si necesitas.
      <br>Lugares <em>fuera</em> que se filtran: San Juan del Río, Tequisquiapan, Jalpan, León, África, etc.
    </div>

    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:10px">
      <div>
        <label class="small">Lat mínimo (sur)</label>
        <input type="number" step="0.0001" id="bb-lat-min" value="20.50"
               style="width:100%;background:#0f172a;border:1px solid var(--bd);color:var(--fg);padding:8px;border-radius:6px">
      </div>
      <div>
        <label class="small">Lat máximo (norte)</label>
        <input type="number" step="0.0001" id="bb-lat-max" value="20.80"
               style="width:100%;background:#0f172a;border:1px solid var(--bd);color:var(--fg);padding:8px;border-radius:6px">
      </div>
      <div>
        <label class="small">Lng mínimo (oeste)</label>
        <input type="number" step="0.0001" id="bb-lng-min" value="-100.55"
               style="width:100%;background:#0f172a;border:1px solid var(--bd);color:var(--fg);padding:8px;border-radius:6px">
      </div>
      <div>
        <label class="small">Lng máximo (este)</label>
        <input type="number" step="0.0001" id="bb-lng-max" value="-100.20"
               style="width:100%;background:#0f172a;border:1px solid var(--bd);color:var(--fg);padding:8px;border-radius:6px">
      </div>
    </div>

    <div class="row" style="margin-bottom:10px">
      <button onclick="bboxPreview()" class="secondary">👁 Verificar (sin cambios)</button>
      <button onclick="bboxCleanup(false)" class="warn">🗑 Limpiar padron (reset a NULL)</button>
      <button onclick="bboxCleanup(true)" class="danger">🗑+ Limpiar padron + cache</button>
      <span class="small">El “+ cache” borra entradas envenenadas del cache para que reintentos den resultado distinto.</span>
    </div>

    <div id="bbox-out"></div>
  </div>

  <!-- MANTENIMIENTO -->
  <div class="panel">
    <h2>4. Mantenimiento</h2>
    <div class="row">
      <button onclick="clearErr()" class="warn">🗑 Limpiar cache de errores</button>
      <button onclick="resetPadron()" class="warn">↻ Reset padron (errores → NULL)</button>
      <span class="small">Úsalo si el cache se envenenó con respuestas ERROR antes de tener la key bien configurada.</span>
    </div>
  </div>

</div>

<script>
// ----------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------
const $ = (id) => document.getElementById(id);
let abortCtrl = null;
let okCount = 0, errCount = 0, zeroCount = 0, total = 0, processed = 0;

const COST_PER_1K = 5.0;        // $5 USD por 1000 calls Geocoding API
const FREE_TIER_MONTH = 10000;  // 10k llamadas gratis al mes
function updateCost(){
  const n = parseInt($('limit').value || 0);
  const maxCost = (n * COST_PER_1K / 1000);
  const maxCostStr = maxCost.toFixed(2);
  const dentroGratis = n <= FREE_TIER_MONTH;
  $('cost').innerHTML =
    `<div>· <strong>${n}</strong> llamadas × $0.005 = <strong>$${maxCostStr} USD</strong> máximo</div>` +
    `<div style="margin-top:2px;color:#10b981">🎁 Free tier: ${FREE_TIER_MONTH.toLocaleString()} llamadas gratis/mes` +
    (dentroGratis ? ` → si no lo has consumido este mes, <strong>$0</strong>` : '') + `</div>` +
    `<div style="margin-top:2px">💾 Cache hits y REQUEST_DENIED <strong>no se cobran</strong></div>`;
}
$('limit').addEventListener('input', updateCost);
function setLimit(n){
  $('limit').value = n;
  document.querySelectorAll('.quick button').forEach(b => b.classList.remove('sel'));
  event.target.classList.add('sel');
  updateCost();
}

// ----------------------------------------------------------------------
// Stats
// ----------------------------------------------------------------------
async function loadStats(){
  const r = await fetch('?action=stats').then(r => r.json());
  const cards = [
    ['Total padrón',  r.total, ''],
    ['Con coords',    r.con_coords, 'ok'],
    ['Sin coords',    r.sin_coords, 'warn'],
    ['Errores',       r.errores, r.errores > 0 ? 'err' : ''],
    ['OK desde origen', r.ok_origen, ''],
    ['Vía Google',    r.ok_google, ''],
    ['Cache total',   r.cache_total, ''],
    ['Cache errores', r.cache_err,   r.cache_err > 0 ? 'err' : ''],
  ];
  $('stats').innerHTML = cards.map(([l,v,cls]) =>
    `<div class="stat"><div class="lbl">${l}</div><div class="val ${cls}">${(v||0).toLocaleString()}</div></div>`
  ).join('');
}
loadStats();
updateCost();
updateFilter();

const STRATEGY_HINTS = {
  'auto':          'Auto: usa la mejor query disponible para cada registro.',
  'colonia':       'Sólo colonia: ignora calle y CP. Útil cuando "calle" trae basura. Resultado: GEOMETRIC_CENTER/APPROXIMATE.',
  'colonia_cp':    'Colonia + CP: balanceada. Falla en registros sin CP.',
  'calle_colonia': 'Dirección completa: requiere calle Y colonia. Máxima precisión, pero muchos registros van a fallar.',
  'cp':            'Sólo CP: rápido pero impreciso (centroide del CP). Para los que no tienen colonia.',
};
async function updateFilter(){
  const re = $('reintenta').checked ? 1 : 0;
  const r = await fetch(`?action=pending-count&reintenta=${re}`).then(r => r.json());
  $('filter-text').innerHTML =
    `<strong>${r.filtro}</strong> · <strong>${(r.pendientes||0).toLocaleString()}</strong> filas elegibles`;
  $('filter-sql').textContent = 'WHERE ' + r.sql_where;
  const lim = parseInt($('limit').value || 0);
  if (lim > r.pendientes) {
    $('filter-badge').className = 'badge warn';
    $('filter-badge').textContent = 'Heads up';
    $('filter-text').innerHTML += ` <span style="color:var(--warn)">· solicitaste ${lim} pero sólo hay ${r.pendientes}</span>`;
  } else {
    $('filter-badge').className = 'badge info';
    $('filter-badge').textContent = 'Filtro activo';
  }
  $('strategy-hint').textContent = STRATEGY_HINTS[$('strategy').value] || '';
}
$('limit').addEventListener('input', updateFilter);

// ----------------------------------------------------------------------
// Test API
// ----------------------------------------------------------------------
async function testApi(){
  const out = $('test-out');
  out.classList.remove('hide');
  out.textContent = 'Probando...';
  const r = await fetch('?action=test').then(r => r.json());
  out.textContent = JSON.stringify(r, null, 2);
  if (r.status === 'OK') out.style.borderColor = 'var(--ok)';
  else out.style.borderColor = 'var(--err)';
}

// ----------------------------------------------------------------------
// Preview
// ----------------------------------------------------------------------
async function preview(){
  $('rows').innerHTML = '';
  okCount=errCount=zeroCount=processed=0; updateProgress();
  const lim = parseInt($('limit').value || 10);
  const re  = $('reintenta').checked ? 1 : 0;
  const st  = $('strategy').value;
  const r = await fetch(`?action=sample&limit=${lim}&reintenta=${re}&strategy=${st}`).then(r => r.json());
  total = r.length;
  $('progress').textContent = `Preview: ${r.length} próximas consultas (no se envían)`;
  r.forEach((row,i) => addRow(i+1, {
    id: row.id, estrategia: row.estrategia, query: row.query,
    status: 'PREVIEW', source: '—'
  }));
}

// ----------------------------------------------------------------------
// Process (streaming)
// ----------------------------------------------------------------------
async function process(){
  $('rows').innerHTML = '';
  okCount=errCount=zeroCount=processed=0;
  $('bar').style.width = '0%';
  const lim = parseInt($('limit').value || 10);
  const re  = $('reintenta').checked ? 1 : 0;
  const st  = $('strategy').value;
  total = lim;

  $('btn-process').classList.add('hide');
  $('btn-stop').classList.remove('hide');
  abortCtrl = new AbortController();

  try {
    const res = await fetch(`?action=process&limit=${lim}&reintenta=${re}&strategy=${st}`, { signal: abortCtrl.signal });
    const reader = res.body.getReader();
    const dec = new TextDecoder();
    let buf = '';
    while (true) {
      const {value,done} = await reader.read();
      if (done) break;
      buf += dec.decode(value, {stream:true});
      const lines = buf.split('\n');
      buf = lines.pop();
      for (const line of lines) {
        if (!line.trim()) continue;
        try {
          const ev = JSON.parse(line);
          handleEvent(ev);
        } catch(e) { console.warn('Bad line', line); }
      }
    }
  } catch (e) {
    if (e.name !== 'AbortError') console.error(e);
  }

  $('btn-process').classList.remove('hide');
  $('btn-stop').classList.add('hide');
  loadStats();
}

function stop(){ if (abortCtrl) abortCtrl.abort(); }

function handleEvent(ev){
  processed++;
  if (ev.status === 'OK') okCount++;
  else if (ev.status === 'ZERO_RESULTS') zeroCount++;
  else errCount++;
  addRow(processed, ev);
  updateProgress();
}

function updateProgress(){
  const pct = total > 0 ? Math.round(processed * 100 / total) : 0;
  $('bar').style.width = pct + '%';
  $('progress').innerHTML = `<strong>${processed}/${total}</strong> &nbsp;` +
    `<span class="badge ok">OK ${okCount}</span> ` +
    `<span class="badge warn">ZERO ${zeroCount}</span> ` +
    `<span class="badge err">ERR ${errCount}</span>`;
}

function addRow(n, ev){
  const tr = document.createElement('tr');
  const badge = ev.status === 'OK' ? 'ok' :
                ev.status === 'ZERO_RESULTS' ? 'warn' :
                ev.status === 'PREVIEW' ? 'info' : 'err';
  const coords = (ev.lat != null && ev.lng != null) ?
    `<span class="pill">${Number(ev.lat).toFixed(5)}, ${Number(ev.lng).toFixed(5)}</span>` : '—';
  tr.innerHTML = `
    <td>${n}</td>
    <td><span class="pill">${ev.id||'—'}</span></td>
    <td>${ev.estrategia||'—'}</td>
    <td style="max-width:380px;word-break:break-word">${escapeHtml(ev.query||'')}</td>
    <td><span class="badge ${badge}">${ev.status}${ev.error_message? ' · '+escapeHtml(ev.error_message):''}</span></td>
    <td>${coords}</td>
    <td>${ev.source||'—'}${ev.cache_hit?' <span class="badge gray">cache</span>':''}</td>
  `;
  $('rows').prepend(tr);
}

function escapeHtml(s){ return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

// ----------------------------------------------------------------------
// Mantenimiento
// ----------------------------------------------------------------------
async function clearErr(){
  if (!confirm('¿Borrar todas las entradas con error del cache?')) return;
  const r = await fetch('?action=clear-errores').then(r => r.json());
  alert('Eliminados: ' + r.eliminados);
  loadStats();
}
// ----------------------------------------------------------------------
// Modo manual
// ----------------------------------------------------------------------
let manCurrentId = 0;

async function loadNextPending(){
  const re = $('reintenta').checked ? 1 : 0;
  const r = await fetch(`?action=next-pending&reintenta=${re}&after_id=${manCurrentId}`).then(r => r.json());
  if (r.empty || !r.id) {
    $('man-info').textContent = 'No hay más pendientes.';
    return;
  }
  fillManFromRecord(r);
}

async function loadById(){
  const id = parseInt($('man-id').value || 0);
  if (!id) return;
  const r = await fetch(`?action=get-record&id=${id}`).then(r => r.json());
  if (r.error) { $('man-info').innerHTML = `<span class="badge err">${r.error}</span>`; return; }
  fillManFromRecord(r);
}

function fillManFromRecord(r){
  manCurrentId = r.id;
  $('man-id').value = r.id;
  $('man-calle').value      = r.calle_numero || '';
  $('man-colonia').value    = r.colonia || '';
  $('man-cp').value         = r.cp || '';
  $('man-delegacion').value = r.delegacion || '';
  $('man-results').innerHTML = '';
  let info = `<span class="badge info">ID ${r.id}</span>`;
  if (r.ciudadano)   info += ` · ${escapeHtml(r.ciudadano)}`;
  if (r.geocode_status) info += ` · <span class="badge ${r.geocode_status.startsWith('ERROR')?'err':'warn'}">${r.geocode_status}</span>`;
  if (r.latitud)     info += ` · <span class="badge ok">YA tiene coords</span>`;
  $('man-info').innerHTML = info;
  updateManQuery();
}

function buildManQuery(){
  const calle = ($('man-calle').value || '').trim();
  const col   = ($('man-colonia').value || '').trim();
  const cp    = ($('man-cp').value || '').trim();
  const del   = ($('man-delegacion').value || '').trim();
  const raw   = $('man-raw').checked;

  // Construye query priorizando colonia
  let parts = [];
  if (calle) parts.push(calle);
  if (col)   parts.push(col);
  if (cp)    parts.push('CP ' + cp);
  if (del)   parts.push(del);

  if (!parts.length) return '';
  let q = parts.join(', ');
  if (!raw) q += ', Querétaro, México';
  return q;
}

function updateManQuery(){
  const q = buildManQuery();
  $('man-query').innerHTML = q
    ? `→ <span class="pill">${escapeHtml(q)}</span>`
    : '<span class="small">(faltan datos)</span>';
}
['man-calle','man-colonia','man-cp','man-delegacion','man-raw'].forEach(id =>
  document.addEventListener('input', e => { if (e.target.id === id) updateManQuery(); }));
$('man-raw').addEventListener('change', updateManQuery);

async function manualSearch(){
  const q = buildManQuery();
  if (!q) { alert('Ingresa al menos un dato'); return; }
  $('man-results').innerHTML = '<div class="small">Buscando…</div>';
  const r = await fetch(`?action=lookup&address=${encodeURIComponent(q)}&raw=${$('man-raw').checked?1:0}`)
                    .then(r => r.json());
  renderManResults(r);
}

function renderManResults(r){
  const box = $('man-results');
  if (r.status !== 'OK' || !r.resultados || r.resultados.length === 0) {
    box.innerHTML = `<div class="panel" style="margin:0;padding:14px;border-color:var(--err)">
      <div><span class="badge err">${r.status||'ERROR'}</span></div>
      ${r.error_message ? `<div class="small" style="margin-top:6px">${escapeHtml(r.error_message)}</div>` : ''}
      <div class="small" style="margin-top:6px">Query: <code>${escapeHtml(r.query||'')}</code></div>
    </div>`;
    return;
  }
  const id = manCurrentId;
  let html = `<div class="small" style="margin-bottom:8px">Google devolvió <strong>${r.resultados.length}</strong> candidato(s) ${id?`para ID <span class="pill">${id}</span>`:''}:</div>`;
  r.resultados.forEach((res, i) => {
    const precBadge = res.precision === 'ROOFTOP' ? 'ok' :
                      res.precision === 'RANGE_INTERPOLATED' ? 'info' :
                      res.precision === 'GEOMETRIC_CENTER' ? 'warn' : 'gray';
    html += `
      <div class="panel" style="margin:0 0 8px;padding:12px">
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:start">
          <div style="flex:1">
            <div><span class="badge ${precBadge}">${res.precision||'?'}</span>
              <span class="pill">${Number(res.lat).toFixed(6)}, ${Number(res.lng).toFixed(6)}</span></div>
            <div style="margin-top:6px">${escapeHtml(res.address||'')}</div>
            <div class="small" style="margin-top:4px">tipos: ${(res.types||[]).join(', ')}</div>
          </div>
          <div style="display:flex;flex-direction:column;gap:6px">
            <a href="https://www.google.com/maps/search/?api=1&query=${res.lat},${res.lng}" target="_blank"
               style="color:var(--accent);font-size:12px;text-decoration:none">🗺 Ver mapa</a>
            <button ${id?'':'disabled title="Carga un registro primero"'}
                    onclick='applyManual(${id}, ${res.lat}, ${res.lng}, ${JSON.stringify(res.address)}, ${JSON.stringify(res.precision)})'>
              ✓ Guardar en ID ${id||'?'}
            </button>
          </div>
        </div>
      </div>`;
  });
  box.innerHTML = html;
}

async function applyManual(id, lat, lng, address, precision){
  if (!id) { alert('Carga un registro primero'); return; }
  const fd = new FormData();
  fd.append('id', id);
  fd.append('lat', lat);
  fd.append('lng', lng);
  fd.append('address', address);
  fd.append('precision', precision || '');
  fd.append('estrategia', 'manual');
  const r = await fetch('?action=apply', { method:'POST', body: fd }).then(r => r.json());
  if (r.ok) {
    $('man-results').innerHTML = `<div class="panel" style="margin:0;padding:14px;border-color:var(--ok)">
      <span class="badge ok">Guardado</span> ID ${r.id} actualizado (${Number(lat).toFixed(6)}, ${Number(lng).toFixed(6)}).
      <div style="margin-top:8px"><button onclick="loadNextPending()">⏭ Cargar siguiente pendiente</button></div>
    </div>`;
    loadStats();
  } else {
    alert('Error: ' + (r.error || 'desconocido'));
  }
}

async function manualSkip(){
  if (!manCurrentId) { alert('Carga un registro primero'); return; }
  if (!confirm('Marcar ID ' + manCurrentId + ' como SKIPPED?')) return;
  await fetch(`?action=skip&id=${manCurrentId}&motivo=SKIPPED`).then(r => r.json());
  loadNextPending();
  loadStats();
}

// ----------------------------------------------------------------------
// Limpieza geográfica por bounding box
// ----------------------------------------------------------------------
function bboxQS(){
  return new URLSearchParams({
    lat_min: $('bb-lat-min').value,
    lat_max: $('bb-lat-max').value,
    lng_min: $('bb-lng-min').value,
    lng_max: $('bb-lng-max').value,
  }).toString();
}

async function bboxPreview(){
  const r = await fetch(`?action=bbox-preview&${bboxQS()}`).then(r => r.json());
  let html = `<div class="panel" style="margin:0;padding:14px">
      <div><span class="badge ${r.afectados>0?'err':'ok'}">${r.afectados.toLocaleString()} fuera de rango</span>
           <span class="small"> de ${r.con_coords.toLocaleString()} con coords (${r.con_coords?((r.afectados*100/r.con_coords).toFixed(1)+'%'):'0%'})</span>
           ${r.cache_afectado>0 ? `· <span class="badge warn">${r.cache_afectado} entradas envenenadas en cache</span>` : ''}
      </div>`;
  if (r.sample.length) {
    html += '<div style="margin-top:10px;font-size:12px"><strong>Muestra (primeros 20):</strong></div>';
    html += '<table style="width:100%;border-collapse:collapse;font-size:11.5px;margin-top:6px">';
    html += '<thead><tr><th>ID</th><th>CP</th><th>Delegación</th><th>Colonia</th><th>Lat,Lng</th><th>Estrategia</th><th>Address Google</th></tr></thead><tbody>';
    r.sample.forEach(row => {
      html += `<tr>
        <td><span class="pill">${row.id}</span></td>
        <td>${row.cp||''}</td>
        <td>${row.delegacion||''}</td>
        <td>${row.colonia||''}</td>
        <td><a href="https://www.google.com/maps/search/?api=1&query=${row.latitud},${row.longitud}" target="_blank">${(+row.latitud).toFixed(4)}, ${(+row.longitud).toFixed(4)}</a></td>
        <td>${row.geocode_estrategia||''}</td>
        <td style="font-size:10.5px;color:#94a3b8">${(row.geocode_address||'').substring(0,80)}</td>
      </tr>`;
    });
    html += '</tbody></table>';
  }
  html += '</div>';
  $('bbox-out').innerHTML = html;
}

async function bboxCleanup(alsoCache){
  const r0 = await fetch(`?action=bbox-preview&${bboxQS()}`).then(r => r.json());
  const msg = alsoCache
    ? `Esto reseteará ${r0.afectados.toLocaleString()} registros a NULL y eliminará ${r0.cache_afectado.toLocaleString()} entradas del cache. ¿Continuar?`
    : `Esto reseteará ${r0.afectados.toLocaleString()} registros a NULL (el cache no se toca). ¿Continuar?`;
  if (!confirm(msg)) return;
  const r = await fetch(`?action=bbox-cleanup&${bboxQS()}&also_cache=${alsoCache?1:0}`).then(r => r.json());
  $('bbox-out').innerHTML = `<div class="panel" style="margin:0;padding:14px;border-color:var(--ok)">
    <span class="badge ok">Limpieza completa</span>
    Padron reseteado: <strong>${r.padron_reset.toLocaleString()}</strong> filas
    ${alsoCache?` · Cache eliminado: <strong>${r.cache_eliminados.toLocaleString()}</strong>`:''}
  </div>`;
  loadStats();
}

async function resetPadron(){
  if (!confirm('¿Resetear marcadores de error en padron para reintentar?')) return;
  const r = await fetch('?action=reset-padron').then(r => r.json());
  alert('Reseteados: ' + r.reseteados);
  loadStats();
}
</script>
</body>
</html>
<?php

// =====================================================================
// FUNCIONES BACKEND
// =====================================================================

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

function getStats(PDO $pdo): array
{
    $r = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(latitud IS NOT NULL AND longitud IS NOT NULL) AS con_coords,
            SUM(latitud IS NULL OR longitud IS NULL)          AS sin_coords,
            SUM(geocode_status LIKE 'ERROR%' OR geocode_status='ZERO_RESULTS' OR geocode_status='SIN_DATOS') AS errores,
            SUM(geocode_status='OK_ORIGEN')   AS ok_origen,
            SUM(geocode_source='google_maps' AND geocode_status='OK') AS ok_google
          FROM padron
    ")->fetch(PDO::FETCH_ASSOC);

    $cache = $pdo->query("
        SELECT
            COUNT(*)                            AS cache_total,
            SUM(status<>'OK')                   AS cache_err
          FROM geocode_cache
    ")->fetch(PDO::FETCH_ASSOC);

    return array_map('intval', array_merge($r, $cache));
}

function buildSelect(bool $reintenta, int $limit): array
{
    // Regla de oro: NUNCA tocar filas que YA tienen latitud Y longitud.
    // El modo "reintenta" sólo afecta a las que están NULL y antes fallaron.
    if ($reintenta) {
        $where = "latitud IS NULL AND longitud IS NULL";
    } else {
        // Sin reintento: filas NULL que aún no se han marcado con error.
        // Esto evita re-gastar API contra direcciones ya marcadas como
        // ZERO_RESULTS o ERROR (a menos que actives "reintentar errores").
        $where = "latitud IS NULL AND longitud IS NULL
                  AND (geocode_status IS NULL OR geocode_status = '')";
    }
    $sql = "SELECT id, cp, delegacion, colonia, calle_numero, geocode_intentos
              FROM padron
             WHERE $where
             ORDER BY id ASC
             LIMIT " . (int)$limit;
    return [$sql, []];
}

function getPendingCount(PDO $pdo, bool $reintenta): array
{
    [$sql] = buildSelect($reintenta, 1);
    // Reemplazo SELECT … LIMIT 1 por COUNT(*)
    $count = $pdo->query(
        "SELECT COUNT(*) FROM padron WHERE " .
        ($reintenta
            ? "latitud IS NULL AND longitud IS NULL"
            : "latitud IS NULL AND longitud IS NULL AND (geocode_status IS NULL OR geocode_status='')")
    )->fetchColumn();
    return [
        'pendientes'    => (int)$count,
        'filtro'        => $reintenta ? 'Sin coords (incluye errores previos)' : 'Sin coords NO procesadas',
        'sql_where'     => $reintenta
            ? "latitud IS NULL AND longitud IS NULL"
            : "latitud IS NULL AND longitud IS NULL AND (geocode_status IS NULL OR geocode_status='')",
    ];
}

function getSample(PDO $pdo, int $limit, bool $reintenta, string $strategy = 'auto'): array
{
    $config = require __DIR__ . '/config.php';
    [$sql] = buildSelect($reintenta, $limit);
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        [$q, $est] = construirQuery($r, $config['google_maps'], $strategy);
        $out[] = ['id' => (int)$r['id'], 'estrategia' => $est, 'query' => $q ?: '(sin datos)'];
    }
    return $out;
}

function testApi(array $config): array
{
    $apiKey = $config['google_maps']['api_key'] ?? '';
    $direccion = "Centro Histórico, Querétaro, Querétaro, México";
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
        'address'    => $direccion,
        'key'        => $apiKey,
        'region'     => 'mx',
        'language'   => 'es',
        'components' => 'country:MX',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $json = json_decode((string)$body, true);
    $out = [
        'http' => $http, 'curl_error' => $err ?: null,
        'status' => $json['status'] ?? 'ERROR',
        'error_message' => $json['error_message'] ?? null,
        'address' => $direccion,
        'sample_response' => $json,
    ];
    if (($json['status'] ?? '') !== 'OK') {
        $out['hint'] = 'Revisa: 1) Geocoding API habilitada, 2) Billing activo, ' .
                       '3) Sin restricciones HTTP referrer (usa "None" o restringe por IP), ' .
                       '4) Restricciones de API incluyen Geocoding.';
    }
    return $out;
}

function getNextPending(PDO $pdo, bool $reintenta, int $afterId): ?array
{
    $where = $reintenta
        ? "latitud IS NULL AND longitud IS NULL"
        : "latitud IS NULL AND longitud IS NULL AND (geocode_status IS NULL OR geocode_status='')";
    $st = $pdo->prepare("SELECT id, ciudadano, cp, delegacion, colonia, calle_numero, geocode_status
                           FROM padron
                          WHERE $where AND id > ?
                          ORDER BY id ASC LIMIT 1");
    $st->execute([$afterId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['empty' => true];
}

function getRecord(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return ['error' => 'id inválido'];
    $st = $pdo->prepare("SELECT id, ciudadano, cp, delegacion, colonia, calle_numero,
                                latitud, longitud, geocode_status, geocode_address
                           FROM padron WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['error' => 'no encontrado'];
}

function lookupManual(string $address, array $config, bool $raw): array
{
    $address = trim($address);
    if ($address === '') return ['error' => 'dirección vacía'];

    $gm = $config['google_maps'];
    if (!$raw) {
        // Concatenar contexto Querétaro/México si no lo trae ya
        if (!preg_match('/m[eé]xico/iu', $address)) {
            $address .= ', ' . ($gm['default_estado'] ?? 'Querétaro') . ', ' . ($gm['default_pais'] ?? 'México');
        }
    }
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
        'address'    => $address,
        'key'        => $gm['api_key'],
        'region'     => $gm['region']   ?? 'mx',
        'language'   => $gm['language'] ?? 'es',
        'components' => 'country:MX',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode((string)$body, true) ?: [];
    $resultados = [];
    foreach (($json['results'] ?? []) as $r) {
        $resultados[] = [
            'lat'         => $r['geometry']['location']['lat'] ?? null,
            'lng'         => $r['geometry']['location']['lng'] ?? null,
            'address'     => $r['formatted_address'] ?? null,
            'precision'   => $r['geometry']['location_type'] ?? null,
            'place_id'    => $r['place_id'] ?? null,
            'types'       => $r['types'] ?? [],
        ];
    }
    return [
        'query'         => $address,
        'http'          => $http,
        'status'        => $json['status'] ?? 'ERROR',
        'error_message' => $json['error_message'] ?? null,
        'resultados'    => $resultados,
    ];
}

function applyResult(PDO $pdo, int $id, float $lat, float $lng, string $address, ?string $precision, string $estrategia): array
{
    if ($id <= 0)      return ['error' => 'id inválido'];
    if ($lat == 0.0 && $lng == 0.0) return ['error' => 'lat/lng inválidos'];

    $st = $pdo->prepare("
        UPDATE padron
           SET latitud=:lat, longitud=:lng,
               geocode_status='OK',
               geocode_estrategia=:est,
               geocode_precision=:prec,
               geocode_address=:addr,
               geocode_source='manual',
               geocode_intentos=geocode_intentos+1,
               geocode_at=NOW()
         WHERE id=:id
    ");
    $st->execute([
        ':lat'  => $lat, ':lng' => $lng,
        ':est'  => $estrategia,
        ':prec' => $precision,
        ':addr' => $address,
        ':id'   => $id,
    ]);
    return ['ok' => true, 'id' => $id, 'updated' => $st->rowCount()];
}

function bboxParams(): array
{
    return [
        'lat_min' => (float)($_GET['lat_min'] ?? 20.50),
        'lat_max' => (float)($_GET['lat_max'] ?? 20.80),
        'lng_min' => (float)($_GET['lng_min'] ?? -100.55),
        'lng_max' => (float)($_GET['lng_max'] ?? -100.20),
    ];
}

function bboxWhere(): string
{
    // Coordenadas FUERA del rectángulo
    return "latitud IS NOT NULL AND longitud IS NOT NULL
            AND (latitud < :lat_min OR latitud > :lat_max
              OR longitud < :lng_min OR longitud > :lng_max)";
}

function bboxPreview(PDO $pdo, array $bb): array
{
    $where = bboxWhere();

    $stCnt = $pdo->prepare("SELECT COUNT(*) FROM padron WHERE $where");
    $stCnt->execute($bb);
    $afectados = (int)$stCnt->fetchColumn();

    $stTot = $pdo->prepare("SELECT COUNT(*) FROM padron WHERE latitud IS NOT NULL");
    $stTot->execute();
    $conCoords = (int)$stTot->fetchColumn();

    // Sample
    $sample = $pdo->prepare("SELECT id, cp, delegacion, colonia, calle_numero, latitud, longitud,
                                    geocode_estrategia, geocode_address
                               FROM padron WHERE $where
                              ORDER BY id ASC LIMIT 20");
    $sample->execute($bb);
    $rows = $sample->fetchAll(PDO::FETCH_ASSOC);

    // Cache
    $stCache = $pdo->prepare("SELECT COUNT(*) FROM geocode_cache
                                WHERE status='OK' AND latitud IS NOT NULL
                                  AND (latitud < :lat_min OR latitud > :lat_max
                                    OR longitud < :lng_min OR longitud > :lng_max)");
    $stCache->execute($bb);
    $cacheAfectado = (int)$stCache->fetchColumn();

    return [
        'bbox'           => $bb,
        'afectados'      => $afectados,
        'con_coords'     => $conCoords,
        'cache_afectado' => $cacheAfectado,
        'sample'         => $rows,
    ];
}

function bboxCleanup(PDO $pdo, array $bb, bool $alsoCache): array
{
    $where = bboxWhere();
    $st = $pdo->prepare("
        UPDATE padron
           SET latitud=NULL, longitud=NULL,
               geocode_status='FUERA_BBOX',
               geocode_estrategia=NULL,
               geocode_precision=NULL,
               geocode_address=NULL,
               geocode_at=NOW()
         WHERE $where
    ");
    $st->execute($bb);
    $reset = $st->rowCount();

    $cache = 0;
    if ($alsoCache) {
        $cst = $pdo->prepare("DELETE FROM geocode_cache
                                WHERE status='OK' AND latitud IS NOT NULL
                                  AND (latitud < :lat_min OR latitud > :lat_max
                                    OR longitud < :lng_min OR longitud > :lng_max)");
        $cst->execute($bb);
        $cache = $cst->rowCount();
    }

    return ['ok' => true, 'padron_reset' => $reset, 'cache_eliminados' => $cache];
}

function skipRecord(PDO $pdo, int $id, string $motivo): array
{
    if ($id <= 0) return ['error' => 'id inválido'];
    $st = $pdo->prepare("UPDATE padron
                            SET geocode_status=:status,
                                geocode_intentos=geocode_intentos+1,
                                geocode_at=NOW()
                          WHERE id=:id");
    $st->execute([':status' => substr($motivo, 0, 32), ':id' => $id]);
    return ['ok' => true, 'id' => $id];
}

function streamProcess(PDO $pdo, array $config, int $limit, bool $reintenta, string $strategy = 'auto'): void
{
    @ob_implicit_flush(true);
    while (ob_get_level() > 0) @ob_end_flush();
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    header('X-Accel-Buffering: no');

    [$sql] = buildSelect($reintenta, $limit);
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $apiKey = $config['google_maps']['api_key'];
    $sleepUs = (int)($config['google_maps']['sleep_us'] ?? 120000);

    $updateStmt = $pdo->prepare("
        UPDATE padron
           SET latitud=:lat, longitud=:lng, geocode_status=:status,
               geocode_estrategia=:est, geocode_precision=:prec, geocode_address=:addr,
               geocode_source='google_maps', geocode_intentos=geocode_intentos+1, geocode_at=NOW()
         WHERE id=:id");
    $failStmt = $pdo->prepare("
        UPDATE padron
           SET geocode_status=:status, geocode_intentos=geocode_intentos+1, geocode_at=NOW()
         WHERE id=:id");

    foreach ($rows as $r) {
        [$query, $est] = construirQuery($r, $config['google_maps'], $strategy);
        if ($query === null) {
            $failStmt->execute([':status' => 'SIN_DATOS', ':id' => $r['id']]);
            emit(['id'=>(int)$r['id'],'estrategia'=>$est,'query'=>'(sin datos)','status'=>'SIN_DATOS','source'=>'—']);
            continue;
        }

        $res = geocodeConCache($pdo, $query, $est, $apiKey, $config['google_maps'], $sleepUs);

        if ($res['status'] === 'OK') {
            $updateStmt->execute([
                ':lat'=>$res['lat'], ':lng'=>$res['lng'], ':status'=>'OK',
                ':est'=>$est, ':prec'=>$res['location_type'], ':addr'=>$res['formatted_address'],
                ':id'=>$r['id'],
            ]);
        } elseif ($res['status'] === 'ZERO_RESULTS') {
            $failStmt->execute([':status'=>'ZERO_RESULTS', ':id'=>$r['id']]);
        } else {
            $failStmt->execute([':status'=>'ERROR:'.substr($res['status'],0,24), ':id'=>$r['id']]);
        }

        emit([
            'id' => (int)$r['id'], 'estrategia' => $est, 'query' => $query,
            'status' => $res['status'], 'lat' => $res['lat'], 'lng' => $res['lng'],
            'source' => $res['cache_hit'] ? 'cache' : 'google',
            'cache_hit' => $res['cache_hit'],
            'error_message' => $res['error_message'] ?? null,
        ]);
    }
}

function emit(array $obj): void
{
    echo json_encode($obj, JSON_UNESCAPED_UNICODE) . "\n";
    @flush();
}

// ---------------------------------------------------------------------
// Lógica de queries y geocode (compartida con geocode.php)
// ---------------------------------------------------------------------
function construirQuery(array $row, array $gm, string $strategy = 'auto'): array
{
    $estado = $gm['default_estado'] ?? 'Querétaro';
    $pais   = $gm['default_pais']   ?? 'México';

    $calle      = limpiarParte($row['calle_numero'] ?? null);
    $colonia    = limpiarParte($row['colonia']       ?? null);
    $cp         = limpiarParte($row['cp']            ?? null);
    $delegacion = limpiarParte($row['delegacion']    ?? null);

    if ($calle && preg_match('/domicilio\s+conocido|sin\s+dato|^sn$|^s\/n$/iu', $calle)) {
        $calle = null;
    }
    $base = trim(($delegacion ? "$delegacion, " : '') . "$estado, $pais", ', ');

    // Estrategia forzada: si no hay datos para ella, devolvemos null y se marca SIN_DATOS.
    switch ($strategy) {
        case 'colonia':
            // Sólo colonia (ignorar calle y cp)
            return $colonia
                ? [implode(', ', array_filter([$colonia, $base])), 'colonia']
                : [null, 'sin_colonia'];

        case 'colonia_cp':
            return ($colonia && $cp)
                ? [implode(', ', array_filter([$colonia, "CP $cp", $base])), 'colonia_cp']
                : [null, 'sin_colonia_cp'];

        case 'calle_colonia':
            // Dirección completa: requiere calle + colonia
            return ($calle && $colonia)
                ? [implode(', ', array_filter([$calle, $colonia, "CP $cp", $base])), 'calle_colonia']
                : [null, 'sin_calle_colonia'];

        case 'cp':
            return $cp
                ? [implode(', ', array_filter(["CP $cp", $base])), 'cp']
                : [null, 'sin_cp'];

        case 'auto':
        default:
            // Comportamiento anterior: usa lo mejor disponible
            if ($calle && $colonia) return [implode(', ', array_filter([$calle, $colonia, "CP $cp", $base])), 'calle_colonia'];
            if ($colonia && $cp)    return [implode(', ', array_filter([$colonia, "CP $cp", $base])), 'colonia_cp'];
            if ($colonia)           return [implode(', ', array_filter([$colonia, $base])), 'colonia'];
            if ($cp)                return [implode(', ', array_filter(["CP $cp", $base])), 'cp'];
            return [null, 'sin_datos'];
    }
}

function limpiarParte(?string $v): ?string
{
    if ($v === null) return null;
    $v = trim($v);
    if ($v === '') return null;
    return preg_replace('/\s+/u', ' ', $v);
}

function geocodeConCache(PDO $pdo, string $query, string $est, string $apiKey, array $gm, int $sleepUs): array
{
    $hash = hash('sha256', mb_strtolower($est . '|' . $query, 'UTF-8'));

    $st = $pdo->prepare("SELECT status, latitud, longitud, location_type, formatted_address
                           FROM geocode_cache WHERE query_hash=? LIMIT 1");
    $st->execute([$hash]);
    if ($cached = $st->fetch(PDO::FETCH_ASSOC)) {
        return [
            'status' => $cached['status'],
            'lat' => $cached['latitud']  !== null ? (float)$cached['latitud']  : null,
            'lng' => $cached['longitud'] !== null ? (float)$cached['longitud'] : null,
            'location_type' => $cached['location_type'],
            'formatted_address' => $cached['formatted_address'],
            'error_message' => null,
            'cache_hit' => true,
        ];
    }

    $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
        'address'    => $query,
        'key'        => $apiKey,
        'region'     => $gm['region']   ?? 'mx',
        'language'   => $gm['language'] ?? 'es',
        'components' => 'country:MX',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($sleepUs > 0) usleep($sleepUs);

    $out = [
        'status' => 'ERROR_HTTP', 'lat' => null, 'lng' => null,
        'location_type' => null, 'formatted_address' => null,
        'error_message' => $err ?: null, 'cache_hit' => false,
    ];

    if ($body === false || $http >= 400) {
        $out['status'] = 'ERROR_HTTP_' . $http;
        guardarCache($pdo, $hash, $query, $est, $out, $err ?: $body);
        return $out;
    }
    $json = json_decode((string)$body, true);
    if (!is_array($json)) {
        $out['status'] = 'ERROR_JSON';
        guardarCache($pdo, $hash, $query, $est, $out, $body);
        return $out;
    }
    $st_g = $json['status'] ?? 'ERROR';
    $out['error_message'] = $json['error_message'] ?? null;
    if ($st_g === 'OK' && !empty($json['results'][0])) {
        $r = $json['results'][0];
        $out = [
            'status' => 'OK',
            'lat' => (float)($r['geometry']['location']['lat'] ?? 0),
            'lng' => (float)($r['geometry']['location']['lng'] ?? 0),
            'location_type' => $r['geometry']['location_type'] ?? null,
            'formatted_address' => $r['formatted_address'] ?? null,
            'error_message' => null,
            'cache_hit' => false,
        ];
    } else {
        $out['status'] = $st_g;
    }
    guardarCache($pdo, $hash, $query, $est, $out, $body);
    if ($st_g === 'OVER_QUERY_LIMIT') sleep(2);
    return $out;
}

function guardarCache(PDO $pdo, string $hash, string $query, string $est, array $r, ?string $raw): void
{
    try {
        $st = $pdo->prepare("INSERT INTO geocode_cache
                (query_hash, query_text, estrategia, status, latitud, longitud,
                 formatted_address, location_type, raw_response)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status=VALUES(status)");
        $st->execute([$hash, $query, $est, $r['status'], $r['lat'], $r['lng'],
                      $r['formatted_address'] ?? null, $r['location_type'] ?? null, $raw]);
    } catch (Throwable) {}
}
