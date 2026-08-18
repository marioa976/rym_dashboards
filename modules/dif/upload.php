<?php
/**
 * upload.php  —  Subir un XLSX y ver el log de importación en vivo.
 *
 * Abre:  http://localhost:8888/dif/upload.php
 */

declare(strict_types=1);

set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M');
ini_set('upload_max_filesize', '64M');
ini_set('post_max_size', '64M');

if (!defined('STDOUT')) define('STDOUT', fopen('php://output', 'wb'));
if (!defined('STDERR')) define('STDERR', fopen('php://output', 'wb'));

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/import_lib.php';

$config = require __DIR__ . '/config.php';

// Cargar padrón = escritura. Solo editor/admin; los visores no entran.
if (PHP_SAPI !== 'cli') require_editor('dif');

$action = $_GET['action'] ?? '';

if ($action === 'upload') {
    handleUpload();
    exit;
}
if ($action === 'run') {
    runImport($config);
    exit;
}
if ($action === 'stats') {
    statsJson($config);
    exit;
}

// =====================================================================
// Vista HTML
// =====================================================================

function statsJson(array $config): void
{
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = padronConnect($config['db']);
        $cols = $pdo->query("SHOW COLUMNS FROM padron")->fetchAll(PDO::FETCH_COLUMN);
        $tieneActivo = in_array('activo', $cols, true);
        $r = $pdo->query("SELECT
            COUNT(*) AS total,
            " . ($tieneActivo ? "SUM(activo=1)" : "COUNT(*)") . " AS activos,
            " . ($tieneActivo ? "SUM(activo=0)" : "0") . " AS inactivos,
            COUNT(DISTINCT archivo_origen) AS archivos,
            MAX(created_at) AS ultimo
            FROM padron")->fetch(PDO::FETCH_ASSOC);
        echo json_encode($r);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function handleUpload(): void
{
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (empty($_FILES['xlsx']) || $_FILES['xlsx']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Error al subir el archivo (' . ($_FILES['xlsx']['error'] ?? '?') . ').');
        }
        $tmp = $_FILES['xlsx']['tmp_name'];
        $name = $_FILES['xlsx']['name'];
        if (!preg_match('/\.xlsx$/i', $name)) {
            throw new RuntimeException('El archivo debe ser .xlsx');
        }
        $token = bin2hex(random_bytes(16));
        $dir = sys_get_temp_dir() . '/dif_uploads';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $dest = $dir . '/' . $token . '.xlsx';
        if (!move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('No se pudo mover el archivo subido.');
        }
        echo json_encode([
            'ok' => true, 'token' => $token,
            'name' => $name, 'size' => filesize($dest),
        ]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function runImport(array $config): void
{
    @ob_implicit_flush(true);
    while (ob_get_level() > 0) @ob_end_flush();
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    header('X-Accel-Buffering: no');

    $token = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
    if (!$token) {
        echo json_encode(['event'=>'error','mensaje'=>'token inválido'])."\n";
        return;
    }
    $path = sys_get_temp_dir() . '/dif_uploads/' . $token . '.xlsx';
    if (!is_file($path)) {
        echo json_encode(['event'=>'error','mensaje'=>'archivo no encontrado'])."\n";
        return;
    }
    $truncate = !empty($_GET['truncate']);
    $sheet    = $_GET['sheet'] ?? $config['import']['sheet'];

    try {
        $pdo = padronConnect($config['db']);
        echo json_encode(['event'=>'start','file'=>basename($path),'truncate'=>$truncate])."\n";
        @flush();

        $result = padronImport(
            pdo: $pdo,
            xlsxPath: $path,
            sheetName: $sheet ?: null,
            truncate: $truncate,
            limit: 0,
            batchSize: (int)($config['import']['batch_size'] ?? 500),
            onProgress: function(array $ev) {
                echo json_encode($ev, JSON_UNESCAPED_UNICODE)."\n";
                @flush();
            }
        );
        echo json_encode(['event'=>'done'] + $result)."\n";
    } catch (Throwable $e) {
        echo json_encode(['event'=>'fatal','mensaje'=>$e->getMessage()])."\n";
    } finally {
        @unlink($path);
    }
}
?><?php
$ktTitle  = 'Importador Padrón DIF';
$ktActive = 'dif';
require __DIR__ . '/../../views/layout/kt_top.php';
?><style>
  :root{--bg:#0f172a;--card:#1e293b;--bd:#334155;--mut:#94a3b8;--fg:#e2e8f0;
       --ok:#10b981;--warn:#f59e0b;--err:#ef4444;--accent:#005ab2}
  *{box-sizing:border-box}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
       background:var(--bg);color:var(--fg);line-height:1.5}
  header{padding:16px 24px;border-bottom:1px solid var(--bd);
         display:flex;align-items:center;justify-content:space-between}
  h1{margin:0;font-size:20px}
  .container{padding:20px 24px;max-width:1100px;margin:0 auto}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:18px}
  .stat{background:var(--card);border:1px solid var(--bd);border-radius:8px;padding:12px}
  .stat .lbl{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.5px}
  .stat .val{font-size:22px;font-weight:600;margin-top:4px}
  .stat .val.ok{color:var(--ok)} .stat .val.warn{color:var(--warn)}
  .panel{background:var(--card);border:1px solid var(--bd);border-radius:8px;padding:16px;margin-bottom:14px}
  .panel h2{margin:0 0 10px;font-size:14px;color:var(--mut);text-transform:uppercase;letter-spacing:.5px}
  .drop{border:2px dashed var(--bd);border-radius:10px;padding:32px;text-align:center;
        cursor:pointer;transition:all .15s}
  .drop:hover, .drop.over{border-color:var(--accent);background:rgba(59,130,246,.05)}
  .drop input{display:none}
  .drop .file-info{margin-top:10px;color:var(--accent);font-size:13px}
  .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:10px}
  button{background:var(--accent);border:0;color:#fff;padding:9px 16px;border-radius:6px;
         cursor:pointer;font-size:13px;font-weight:500}
  button:hover{filter:brightness(1.15)}
  button:disabled{opacity:.4;cursor:not-allowed}
  button.secondary{background:#475569}
  button.danger{background:var(--err)}
  label.chk{display:flex;align-items:center;gap:6px;cursor:pointer}
  .log{background:#0f172a;border:1px solid var(--bd);border-radius:8px;padding:12px;
       font-family:monospace;font-size:11.5px;max-height:520px;overflow:auto;line-height:1.6}
  .log-line{padding:2px 0;border-bottom:1px dashed rgba(255,255,255,.04)}
  .log-line.info{color:var(--mut)}
  .log-line.ok{color:var(--ok)}
  .log-line.err{color:var(--err)}
  .log-line.warn{color:var(--warn)}
  .bar{height:8px;background:#0f172a;border:1px solid var(--bd);border-radius:4px;overflow:hidden;margin:10px 0}
  .bar > div{height:100%;background:var(--accent);width:0%;transition:width .2s}
  .badge{display:inline-block;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:600}
  .badge.ok{background:rgba(16,185,129,.15);color:var(--ok)}
  .badge.err{background:rgba(239,68,68,.15);color:var(--err)}
  .badge.warn{background:rgba(245,158,11,.15);color:var(--warn)}
  .badge.info{background:rgba(59,130,246,.15);color:var(--accent)}
  .small{font-size:12px;color:var(--mut)}
  .pill{font-family:monospace;background:#334155;padding:1px 6px;border-radius:3px}
</style>

<header>
  <h1>📤 Importador Padrón DIF</h1>
  <button class="secondary" onclick="loadStats()">⟳ Refrescar stats</button>
</header>

<div class="container">

<div class="stats" id="stats"></div>

<div class="panel">
  <h2>1. Sube tu archivo .xlsx</h2>
  <label class="drop" id="drop">
    <input type="file" id="file" accept=".xlsx">
    <div style="font-size:24px">📁</div>
    <div><strong>Arrastra el XLSX aquí</strong> o haz click para seleccionar</div>
    <div class="small">Máximo 64 MB</div>
    <div class="file-info" id="file-info"></div>
  </label>

  <div class="row">
    <label class="chk"><input type="checkbox" id="truncate" checked> Vaciar tabla padron antes (TRUNCATE)</label>
    <span class="small">⚠️ Si está marcado, borra TODA la tabla padron — incluyendo lat/lng. Sólo úsalo en carga inicial.</span>
  </div>

  <div class="row">
    <button id="btn-upload" onclick="startUpload()" disabled>⬆ Subir e importar</button>
    <button class="secondary" id="btn-clear" onclick="clearLog()">Limpiar log</button>
    <span class="small" id="status"></span>
  </div>
</div>

<div class="panel">
  <h2>2. Progreso</h2>
  <div class="row" style="margin-top:0">
    <span class="badge info">Leídas: <strong id="cnt-leidas">0</strong></span>
    <span class="badge ok">Insertadas: <strong id="cnt-ins">0</strong></span>
    <span class="badge err">Errores: <strong id="cnt-err">0</strong></span>
  </div>
  <div class="bar"><div id="bar"></div></div>
  <div class="log" id="log"></div>
</div>

<div class="panel">
  <h2>3. Siguientes pasos</h2>
  <div class="small" style="color:var(--fg)">
    Después de importar, recomendado en orden:
    <ol>
      <li><a href="dedupe.php" style="color:var(--accent)">dedupe.php</a> — si por accidente cargaste el XLSX dos veces, deduplica conservando los registros con coords.</li>
      <li><a href="geocode_ui.php" style="color:var(--accent)">geocode_ui.php</a> — completa lat/lng faltantes (mucho cae gratis del cache).</li>
      <li><a href="dashboard.php" style="color:var(--accent)">dashboard.php</a> — visualiza KPIs y mapa.</li>
    </ol>
  </div>
</div>

</div>

<script>
const $ = id => document.getElementById(id);
let chosenFile = null, uploadedToken = null, totalRows = 55000;

async function loadStats(){
  const r = await fetch('?action=stats').then(r=>r.json());
  if (r.error) { $('stats').innerHTML = `<div class="stat"><div class="lbl">Error</div><div class="val warn">${r.error}</div></div>`; return; }
  const cards = [
    ['Total filas', r.total||0, ''],
    ['Activos', r.activos||0, 'ok'],
    ['Inactivos', r.inactivos||0, r.inactivos>0?'warn':''],
    ['Archivos importados', r.archivos||0, ''],
    ['Último insert', r.ultimo||'—', ''],
  ];
  $('stats').innerHTML = cards.map(([l,v,c]) =>
    `<div class="stat"><div class="lbl">${l}</div><div class="val ${c}">${typeof v==='number'?v.toLocaleString():v}</div></div>`
  ).join('');
}
loadStats();

// Drop zone
const drop = $('drop'), fileInput = $('file');
drop.addEventListener('click', () => fileInput.click());
fileInput.addEventListener('change', e => onFile(e.target.files[0]));
['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => {
  e.preventDefault(); drop.classList.add('over');
}));
['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => {
  e.preventDefault(); drop.classList.remove('over');
}));
drop.addEventListener('drop', e => onFile(e.dataTransfer.files[0]));

function onFile(f){
  if (!f) return;
  if (!f.name.toLowerCase().endsWith('.xlsx')) { alert('Sólo .xlsx'); return; }
  chosenFile = f;
  $('file-info').textContent = `✓ ${f.name} (${(f.size/1024/1024).toFixed(2)} MB)`;
  $('btn-upload').disabled = false;
}

function addLog(line, cls='info'){
  const div = document.createElement('div');
  div.className = 'log-line ' + cls;
  div.textContent = line;
  $('log').appendChild(div);
  $('log').scrollTop = $('log').scrollHeight;
}

function clearLog(){
  $('log').innerHTML = '';
  $('cnt-leidas').textContent = '0';
  $('cnt-ins').textContent = '0';
  $('cnt-err').textContent = '0';
  $('bar').style.width = '0%';
}

async function startUpload(){
  if (!chosenFile) return;
  clearLog();
  $('btn-upload').disabled = true;
  $('status').textContent = 'Subiendo archivo...';
  addLog('→ Subiendo ' + chosenFile.name + ' ...', 'info');

  const fd = new FormData();
  fd.append('xlsx', chosenFile);
  let up;
  try {
    up = await fetch('?action=upload', { method:'POST', body: fd }).then(r=>r.json());
  } catch (e) {
    addLog('✗ Error de red al subir: ' + e, 'err');
    $('btn-upload').disabled = false;
    return;
  }
  if (up.error) {
    addLog('✗ ' + up.error, 'err');
    $('btn-upload').disabled = false;
    return;
  }
  uploadedToken = up.token;
  addLog(`✓ Subido (${(up.size/1024/1024).toFixed(2)} MB). Iniciando importación...`, 'ok');
  $('status').textContent = 'Importando...';

  // Stream
  const trunc = $('truncate').checked ? 1 : 0;
  const res = await fetch(`?action=run&token=${uploadedToken}&truncate=${trunc}`);
  const reader = res.body.getReader();
  const dec = new TextDecoder();
  let buf = '';
  while (true) {
    const {value, done} = await reader.read();
    if (done) break;
    buf += dec.decode(value, {stream:true});
    const lines = buf.split('\n');
    buf = lines.pop();
    for (const line of lines) {
      if (!line.trim()) continue;
      try { handleEvent(JSON.parse(line)); }
      catch(e) { addLog('? ' + line, 'warn'); }
    }
  }
  $('btn-upload').disabled = false;
  $('status').textContent = 'Listo';
  loadStats();
}

function handleEvent(ev){
  switch (ev.event) {
    case 'start':
      addLog(`▶ Iniciando${ev.truncate?' (TRUNCATE primero)':''}`, 'info');
      break;
    case 'sheet':
      addLog(`📄 Hoja: ${ev.name}`, 'info');
      break;
    case 'header_detected':
      const cols = Object.entries(ev.mapping).map(([k,v]) => `${k}→col${v}`).join(', ');
      addLog(`🔎 Columnas detectadas: ${cols}`, 'ok');
      const expected = ['fecha_registro','fecha_entrega','cantidad','programa','coordinacion',
                        'tipo_apoyo','recibe_ciudadano','lugar_entrega','nombre_recibe','ciudadano',
                        'sexo','curp','fecha_nacimiento','edad','cp','delegacion','colonia',
                        'calle_numero','latitud','longitud'];
      const faltan = expected.filter(c => !(c in ev.mapping));
      if (faltan.length) addLog('⚠️  Faltan columnas en el header: ' + faltan.join(', '), 'warn');
      break;
    case 'progress':
      $('cnt-leidas').textContent = ev.leidas.toLocaleString();
      $('cnt-ins').textContent    = ev.insertadas.toLocaleString();
      $('cnt-err').textContent    = ev.errores.toLocaleString();
      $('bar').style.width = Math.min(100, ev.leidas/totalRows*100).toFixed(0) + '%';
      addLog(`... ${ev.leidas.toLocaleString()} leídas (insertadas: ${ev.insertadas.toLocaleString()}, errores: ${ev.errores})`, 'info');
      break;
    case 'error':
      addLog(`✗ Fila ${ev.fila}: ${ev.mensaje}`, 'err');
      break;
    case 'done':
      $('cnt-leidas').textContent = ev.leidas.toLocaleString();
      $('cnt-ins').textContent    = ev.insertadas.toLocaleString();
      $('cnt-err').textContent    = ev.errores.toLocaleString();
      $('bar').style.width = '100%';
      addLog(`✓ TERMINADO. Leídas: ${ev.leidas.toLocaleString()} · Insertadas: ${ev.insertadas.toLocaleString()} · Errores: ${ev.errores.toLocaleString()}`, 'ok');
      break;
    case 'fatal':
      addLog('💥 FATAL: ' + ev.mensaje, 'err');
      break;
    default:
      addLog(JSON.stringify(ev), 'info');
  }
}
</script>
<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
