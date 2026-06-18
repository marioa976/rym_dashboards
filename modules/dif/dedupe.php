<?php
/**
 * dedupe.php  —  Limpieza de duplicados en `padron`, conservando las
 * coordenadas ya obtenidas vía geocoding.
 *
 * Idea: en vez de borrar, agrega una columna `activo TINYINT` y marca
 * los duplicados como inactivos. Si te equivocas, se revierten con un click.
 *
 * Reglas de dedup (en cascada):
 *   1) Por (archivo_origen, fila_origen)
 *      → captura imports repetidos del mismo XLSX.
 *   2) Por (ciudadano, fecha_entrega, tipo_apoyo, cantidad, programa)
 *      → captura duplicados aunque no compartan fila_origen.
 *
 * Dentro de cada grupo, el "elegido" (keeper) es:
 *   - El que tenga lat/lng (si hay alguno).
 *   - Si nadie tiene, el de id más bajo.
 *
 * Abre:  http://localhost:8888/dif/dedupe.php
 */

declare(strict_types=1);

set_time_limit(0);
ini_set('memory_limit', '512M');

$config = require __DIR__ . '/config.php';

// Deduplicar modifica el padrón = escritura. Solo editor/admin.
if (PHP_SAPI !== 'cli') require_editor('dif');

$db = $config['db'];
$pdo = new PDO(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}",
    $db['user'], $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec("SET NAMES utf8mb4");

$action = $_GET['action'] ?? '';

// =====================================================================
// API endpoints
// =====================================================================
if ($action !== '') {
    try {
        header('Content-Type: application/json; charset=utf-8');
        switch ($action) {
            case 'init':          echo json_encode(initColumn($pdo)); break;
            case 'stats':         echo json_encode(getStats($pdo)); break;
            case 'preview-fila':  echo json_encode(previewByFila($pdo, (int)($_GET['limit'] ?? 50))); break;
            case 'preview-nat':   echo json_encode(previewByNatural($pdo, (int)($_GET['limit'] ?? 50))); break;
            case 'apply':         echo json_encode(applyDedup($pdo)); break;
            case 'restore':       echo json_encode(restoreAll($pdo)); break;
            case 'purge':         echo json_encode(purgeInactive($pdo)); break;
            default:              echo json_encode(['error'=>'acción desconocida']);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

function initColumn(PDO $pdo): array {
    $cols = $pdo->query("SHOW COLUMNS FROM padron")->fetchAll(PDO::FETCH_COLUMN);
    $created = false;
    if (!in_array('activo', $cols, true)) {
        $pdo->exec("ALTER TABLE padron
                      ADD COLUMN activo TINYINT NOT NULL DEFAULT 1,
                      ADD COLUMN dedup_motivo VARCHAR(64) NULL,
                      ADD COLUMN dedup_keeper_id BIGINT UNSIGNED NULL,
                      ADD INDEX idx_activo (activo)");
        $created = true;
    }
    return ['ok'=>true, 'columna_creada'=>$created];
}

function getStats(PDO $pdo): array {
    $cols = $pdo->query("SHOW COLUMNS FROM padron")->fetchAll(PDO::FETCH_COLUMN);
    $tieneActivo = in_array('activo', $cols, true);
    $r = $pdo->query("
        SELECT
            COUNT(*) AS total,
            " . ($tieneActivo ? "SUM(activo=1)" : "COUNT(*)") . " AS activos,
            " . ($tieneActivo ? "SUM(activo=0)" : "0") . " AS inactivos,
            SUM(latitud IS NOT NULL) AS con_coords,
            COUNT(DISTINCT archivo_origen) AS archivos
          FROM padron
    ")->fetch(PDO::FETCH_ASSOC);
    return array_map('intval', $r) + ['tiene_activo' => $tieneActivo];
}

function previewByFila(PDO $pdo, int $limit): array {
    // Grupos duplicados por (archivo_origen, fila_origen)
    $cnt = (int)$pdo->query("
        SELECT COUNT(*) FROM (
            SELECT 1 FROM padron
             WHERE fila_origen IS NOT NULL AND archivo_origen IS NOT NULL
             GROUP BY archivo_origen, fila_origen
            HAVING COUNT(*) > 1
        ) x
    ")->fetchColumn();

    $totalDup = (int)$pdo->query("
        SELECT COALESCE(SUM(c-1),0) FROM (
            SELECT COUNT(*) c FROM padron
             WHERE fila_origen IS NOT NULL AND archivo_origen IS NOT NULL
             GROUP BY archivo_origen, fila_origen
            HAVING c > 1
        ) x
    ")->fetchColumn();

    $sample = $pdo->query("
        SELECT archivo_origen, fila_origen, COUNT(*) AS copias,
               GROUP_CONCAT(id ORDER BY id) AS ids,
               MAX(latitud IS NOT NULL) AS alguno_con_coords,
               MIN(ciudadano) AS ejemplo_ciudadano,
               MIN(colonia) AS ejemplo_colonia
          FROM padron
         WHERE fila_origen IS NOT NULL AND archivo_origen IS NOT NULL
         GROUP BY archivo_origen, fila_origen
        HAVING copias > 1
         ORDER BY copias DESC, fila_origen ASC
         LIMIT $limit
    ")->fetchAll(PDO::FETCH_ASSOC);

    return ['grupos'=>$cnt, 'filas_a_inactivar'=>$totalDup, 'sample'=>$sample];
}

function previewByNatural(PDO $pdo, int $limit): array {
    $cnt = (int)$pdo->query("
        SELECT COUNT(*) FROM (
            SELECT 1 FROM padron
             WHERE ciudadano IS NOT NULL
             GROUP BY ciudadano, fecha_entrega, tipo_apoyo, cantidad, programa
            HAVING COUNT(*) > 1
        ) x
    ")->fetchColumn();
    $totalDup = (int)$pdo->query("
        SELECT COALESCE(SUM(c-1),0) FROM (
            SELECT COUNT(*) c FROM padron
             WHERE ciudadano IS NOT NULL
             GROUP BY ciudadano, fecha_entrega, tipo_apoyo, cantidad, programa
            HAVING c > 1
        ) x
    ")->fetchColumn();
    $sample = $pdo->query("
        SELECT ciudadano, fecha_entrega, tipo_apoyo, cantidad, programa,
               COUNT(*) AS copias, GROUP_CONCAT(id ORDER BY id) AS ids,
               MAX(latitud IS NOT NULL) AS alguno_con_coords
          FROM padron
         WHERE ciudadano IS NOT NULL
         GROUP BY ciudadano, fecha_entrega, tipo_apoyo, cantidad, programa
        HAVING copias > 1
         ORDER BY copias DESC
         LIMIT $limit
    ")->fetchAll(PDO::FETCH_ASSOC);
    return ['grupos'=>$cnt, 'filas_a_inactivar'=>$totalDup, 'sample'=>$sample];
}

function applyDedup(PDO $pdo): array {
    initColumn($pdo); // asegura columna

    // PASE 1: duplicados por (archivo_origen, fila_origen)
    // Keeper = primero con coords, sino el id más bajo
    $sql1 = "
        UPDATE padron p
        JOIN (
            SELECT archivo_origen, fila_origen,
                   COALESCE(MIN(CASE WHEN latitud IS NOT NULL THEN id END), MIN(id)) AS keeper
              FROM padron
             WHERE fila_origen IS NOT NULL AND archivo_origen IS NOT NULL
               AND activo = 1
             GROUP BY archivo_origen, fila_origen
            HAVING COUNT(*) > 1
        ) g ON g.archivo_origen = p.archivo_origen
           AND g.fila_origen   = p.fila_origen
        SET p.activo = 0,
            p.dedup_motivo = 'dup_fila_origen',
            p.dedup_keeper_id = g.keeper
        WHERE p.id <> g.keeper AND p.activo = 1
    ";
    $st1 = $pdo->prepare($sql1);
    $st1->execute();
    $n1 = $st1->rowCount();

    // PASE 2: duplicados por clave natural (entre los que SIGUEN activos)
    $sql2 = "
        UPDATE padron p
        JOIN (
            SELECT ciudadano, fecha_entrega, tipo_apoyo, cantidad, programa,
                   COALESCE(MIN(CASE WHEN latitud IS NOT NULL THEN id END), MIN(id)) AS keeper
              FROM padron
             WHERE activo = 1 AND ciudadano IS NOT NULL
             GROUP BY ciudadano, fecha_entrega, tipo_apoyo, cantidad, programa
            HAVING COUNT(*) > 1
        ) g ON g.ciudadano <=> p.ciudadano
           AND g.fecha_entrega <=> p.fecha_entrega
           AND g.tipo_apoyo <=> p.tipo_apoyo
           AND g.cantidad <=> p.cantidad
           AND g.programa <=> p.programa
        SET p.activo = 0,
            p.dedup_motivo = 'dup_natural',
            p.dedup_keeper_id = g.keeper
        WHERE p.id <> g.keeper AND p.activo = 1
    ";
    $st2 = $pdo->prepare($sql2);
    $st2->execute();
    $n2 = $st2->rowCount();

    return ['ok'=>true, 'pase1_fila_origen'=>$n1, 'pase2_natural'=>$n2, 'total'=>$n1+$n2];
}

function restoreAll(PDO $pdo): array {
    $n = $pdo->exec("UPDATE padron SET activo=1, dedup_motivo=NULL, dedup_keeper_id=NULL WHERE activo=0");
    return ['ok'=>true, 'restaurados'=>$n];
}

function purgeInactive(PDO $pdo): array {
    $n = $pdo->exec("DELETE FROM padron WHERE activo=0");
    return ['ok'=>true, 'eliminados'=>$n];
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Deduplicación Padrón DIF</title>
<style>
  :root{--bg:#0f172a;--card:#1e293b;--bd:#334155;--mut:#94a3b8;--fg:#e2e8f0;
       --ok:#10b981;--warn:#f59e0b;--err:#ef4444;--accent:#005ab2}
  *{box-sizing:border-box}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
       background:var(--bg);color:var(--fg);line-height:1.5}
  header{padding:16px 24px;border-bottom:1px solid var(--bd);
         display:flex;align-items:center;justify-content:space-between;gap:16px}
  h1{margin:0;font-size:20px}
  .container{padding:20px 24px;max-width:1200px;margin:0 auto}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:20px}
  .stat{background:var(--card);border:1px solid var(--bd);border-radius:8px;padding:14px}
  .stat .lbl{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.5px}
  .stat .val{font-size:22px;font-weight:600;margin-top:4px}
  .stat .val.ok{color:var(--ok)} .stat .val.warn{color:var(--warn)} .stat .val.err{color:var(--err)}
  .panel{background:var(--card);border:1px solid var(--bd);border-radius:8px;
         padding:16px;margin-bottom:14px}
  .panel h2{margin:0 0 10px;font-size:14px;color:var(--mut);text-transform:uppercase;letter-spacing:.5px}
  .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  button{background:var(--accent);border:0;color:#fff;padding:8px 14px;border-radius:6px;
         cursor:pointer;font-size:13px;font-weight:500}
  button.secondary{background:#475569}
  button.danger{background:var(--err)}
  button.warn{background:var(--warn);color:#0f172a}
  button.ghost{background:transparent;color:var(--mut);border:1px solid var(--bd)}
  button:hover{filter:brightness(1.15)}
  .small{font-size:12px;color:var(--mut)}
  .badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600}
  .badge.ok{background:rgba(16,185,129,.15);color:var(--ok)}
  .badge.err{background:rgba(239,68,68,.15);color:var(--err)}
  .badge.warn{background:rgba(245,158,11,.15);color:var(--warn)}
  .badge.info{background:rgba(59,130,246,.15);color:var(--accent)}
  table{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px}
  th,td{padding:6px 8px;border-bottom:1px solid var(--bd);text-align:left}
  th{color:var(--mut);font-size:10.5px;text-transform:uppercase;letter-spacing:.3px}
  .pill{font-family:monospace;background:#334155;padding:1px 5px;border-radius:3px;font-size:11px}
  .ok-tag{color:var(--ok);font-weight:700}
</style>
</head>
<body>

<?php $portalModulo='DIF'; @include __DIR__.'/../_portalbar.php'; ?>
<?php $navActive = 'dedupe'; include __DIR__ . '/_nav.php'; ?>

<header>
  <h1>🧹 Deduplicación Padrón DIF</h1>
  <button class="secondary" onclick="loadStats()">⟳ Refrescar</button>
</header>

<div class="container">

<div class="stats" id="stats"></div>

<div class="panel">
  <h2>0. Inicialización</h2>
  <div class="row">
    <button onclick="init()">🛠 Asegurar columna <code>activo</code></button>
    <span class="small">Crea (si no existe) <code>activo TINYINT</code>, <code>dedup_motivo</code> y <code>dedup_keeper_id</code> en <code>padron</code>.</span>
  </div>
  <pre id="init-out" class="small" style="margin-top:8px;color:var(--ok)"></pre>
</div>

<div class="panel">
  <h2>1. Preview: duplicados por <code>(archivo_origen, fila_origen)</code></h2>
  <div class="small">Captura el caso "importé el mismo XLSX dos veces". Pase 1.</div>
  <div class="row" style="margin-top:8px">
    <button onclick="prevFila()">👁 Ver duplicados pase 1</button>
  </div>
  <div id="prev-fila"></div>
</div>

<div class="panel">
  <h2>2. Preview: duplicados por clave natural</h2>
  <div class="small">Agrupa por <code>ciudadano + fecha_entrega + tipo_apoyo + cantidad + programa</code>. Pase 2.</div>
  <div class="row" style="margin-top:8px">
    <button onclick="prevNat()">👁 Ver duplicados pase 2</button>
  </div>
  <div id="prev-nat"></div>
</div>

<div class="panel" style="border-color:var(--warn)">
  <h2>3. Aplicar dedup</h2>
  <div class="small">
    Marca como <code>activo=0</code> todos los duplicados, conservando uno por grupo.
    <strong>Conserva preferentemente el que ya tiene lat/lng</strong> (así no se pierden las coords).
    No borra nada — es reversible con el botón restaurar.
  </div>
  <div class="row" style="margin-top:10px">
    <button class="warn" onclick="apply()">✓ Aplicar dedup (ambos pases)</button>
    <button class="ghost" onclick="restore()">↺ Restaurar todo (activo=1)</button>
  </div>
  <div id="apply-out"></div>
</div>

<div class="panel" style="border-color:var(--err)">
  <h2>4. (Opcional) Purga definitiva</h2>
  <div class="small">Borra físicamente las filas con <code>activo=0</code>. <strong>Esto sí es irreversible.</strong>
  Sólo recomendado cuando ya validaste con el dashboard que todo está correcto.</div>
  <div class="row" style="margin-top:10px">
    <button class="danger" onclick="purge()">🗑 Eliminar inactivos definitivamente</button>
  </div>
  <div id="purge-out"></div>
</div>

<div class="panel">
  <h2>5. Siguiente paso</h2>
  <div class="small" style="color:var(--fg)">
    Después de deduplicar, los registros que quedaron <strong>activos</strong> pero <strong>sin coords</strong>
    se pueden geocodificar de nuevo. Como el <code>geocode_cache</code> está intacto,
    <strong>la mayoría se llenará gratis desde el cache</strong> sin tocar Google.<br>
    👉 Abre <a href="geocode_ui.php" style="color:var(--accent)">geocode_ui.php</a> y dale ▶ Procesar.
  </div>
</div>

</div>

<script>
const $ = id => document.getElementById(id);

async function loadStats(){
  const r = await fetch('?action=stats').then(r=>r.json());
  const cards = [
    ['Total filas', r.total||0, ''],
    ['Activos', r.activos||0, 'ok'],
    ['Inactivos', r.inactivos||0, r.inactivos>0?'warn':''],
    ['Con coords', r.con_coords||0, 'ok'],
    ['Archivos importados', r.archivos||0, ''],
    ['Columna activo', r.tiene_activo ? '✓' : '✗', r.tiene_activo?'ok':'err'],
  ];
  $('stats').innerHTML = cards.map(([l,v,c]) =>
    `<div class="stat"><div class="lbl">${l}</div><div class="val ${c}">${typeof v==='number'?v.toLocaleString():v}</div></div>`
  ).join('');
}
loadStats();

async function init(){
  const r = await fetch('?action=init').then(r=>r.json());
  $('init-out').textContent = r.columna_creada ? '✓ Columna creada' : '✓ Ya existía la columna';
  loadStats();
}

function renderPreview(boxId, r, key){
  let html = `<div style="margin-top:10px">
    <span class="badge ${r.grupos>0?'warn':'ok'}">${(r.grupos||0).toLocaleString()} grupos de duplicados</span>
    <span class="badge err">${(r.filas_a_inactivar||0).toLocaleString()} filas se inactivarían</span>
  </div>`;
  if (r.sample && r.sample.length) {
    html += '<table><thead><tr>';
    if (key === 'fila') html += '<th>Archivo</th><th>Fila</th>';
    else html += '<th>Ciudadano</th><th>F.entrega</th><th>Tipo apoyo</th><th>Programa</th>';
    html += '<th>Copias</th><th>IDs</th><th>¿Alguno con coords?</th></tr></thead><tbody>';
    r.sample.forEach(row => {
      html += '<tr>';
      if (key === 'fila') {
        html += `<td>${row.archivo_origen||''}</td><td><span class="pill">${row.fila_origen}</span></td>`;
      } else {
        html += `<td>${(row.ciudadano||'').substring(0,40)}</td>
                 <td>${row.fecha_entrega||''}</td>
                 <td>${row.tipo_apoyo||''}</td>
                 <td>${(row.programa||'').substring(0,30)}</td>`;
      }
      html += `<td><strong>${row.copias}</strong></td>
               <td class="small"><span class="pill">${row.ids}</span></td>
               <td>${row.alguno_con_coords==1?'<span class="ok-tag">SÍ</span>':'—'}</td></tr>`;
    });
    html += '</tbody></table>';
  }
  $(boxId).innerHTML = html;
}

async function prevFila(){
  const r = await fetch('?action=preview-fila').then(r=>r.json());
  renderPreview('prev-fila', r, 'fila');
}
async function prevNat(){
  const r = await fetch('?action=preview-nat').then(r=>r.json());
  renderPreview('prev-nat', r, 'natural');
}

async function apply(){
  if (!confirm('Se marcará activo=0 a los duplicados (conservando el que tiene coords). ¿Continuar?')) return;
  const r = await fetch('?action=apply').then(r=>r.json());
  $('apply-out').innerHTML = `<div style="margin-top:10px">
    <span class="badge ok">Aplicado</span>
    Pase 1 (fila_origen): <strong>${(r.pase1_fila_origen||0).toLocaleString()}</strong> ·
    Pase 2 (clave natural): <strong>${(r.pase2_natural||0).toLocaleString()}</strong> ·
    Total inactivados: <strong>${(r.total||0).toLocaleString()}</strong>
  </div>`;
  loadStats();
}
async function restore(){
  if (!confirm('¿Restaurar TODOS los inactivos a activo=1?')) return;
  const r = await fetch('?action=restore').then(r=>r.json());
  $('apply-out').innerHTML = `<div style="margin-top:10px">
    <span class="badge ok">Restaurados: <strong>${(r.restaurados||0).toLocaleString()}</strong></span>
  </div>`;
  loadStats();
}
async function purge(){
  if (!confirm('Esto BORRA físicamente las filas inactivas. NO se puede deshacer. ¿Continuar?')) return;
  if (!confirm('Confirma una vez más: estás a punto de eliminar las filas inactivas para siempre.')) return;
  const r = await fetch('?action=purge').then(r=>r.json());
  $('purge-out').innerHTML = `<div style="margin-top:10px">
    <span class="badge err">Eliminados definitivamente: <strong>${(r.eliminados||0).toLocaleString()}</strong></span>
  </div>`;
  loadStats();
}
</script>
</body>
</html>
