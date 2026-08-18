<?php
/**
 * Visor de tickets — listado con filtros, vista resumida (tabla paginada)
 * y panel lateral con el ticket completo (campos legibles + JSON crudo).
 *
 * Filtros (GET): grupo, delegacion, canal, tipo_servicio, estado, from, to.
 * AJAX: ?detalle=ID  -> fragmento HTML del panel de detalle.
 */
require __DIR__ . '/db.php';
$pdo = db();
$cfg = require __DIR__ . '/config.php';
$zsub = $cfg['zendesk_api']['subdomain'] ?? '';

function q(PDO $p, string $sql, array $params = []): array { $s = $p->prepare($sql); $s->execute($params); return $s->fetchAll(); }
function qOne(PDO $p, string $sql, array $params = []) { $s = $p->prepare($sql); $s->execute($params); return $s->fetch(); }

// Etiquetas legibles de columnas (del mapeo de Zendesk)
$labels = [];
try { foreach ($pdo->query("SELECT columna,nombre FROM zendesk_mapeo") as $r) $labels[strtolower($r['columna'])] = $r['nombre']; } catch (Throwable $e) {}
function etiqueta(string $col, array $labels): string {
    if (isset($labels[$col])) return $labels[$col];
    $c = preg_replace('/^zd_/', '', $col);
    return ucfirst(str_replace('_', ' ', $c));
}

// ============================================================
//  Lectura de filtros (mismo set que Análisis)
// ============================================================
$f_grupo      = isset($_GET['grupo'])         && $_GET['grupo']!=='' ? (int)$_GET['grupo'] : null;
$f_delegacion = isset($_GET['delegacion'])    && $_GET['delegacion']!=='' ? (int)$_GET['delegacion'] : null;
$f_canal      = isset($_GET['canal'])         && $_GET['canal']!=='' ? (int)$_GET['canal'] : null;
$f_servicio   = isset($_GET['tipo_servicio']) && $_GET['tipo_servicio']!=='' ? (int)$_GET['tipo_servicio'] : null;
$f_estado     = $_GET['estado'] ?? '';
$f_from       = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$f_to         = $_GET['to']   ?? date('Y-m-d');

require_once __DIR__ . '/_filtro_form.php';          // filtro por formulario de Zendesk
$where = ['1=1', zd_form_sql('t')]; $params = [];
if ($f_grupo)      { $where[] = "t.grupo_id = ?";         $params[] = $f_grupo; }
if ($f_delegacion) { $where[] = "t.delegacion_id = ?";    $params[] = $f_delegacion; }
if ($f_canal)      { $where[] = "t.canal_origen_id = ?";  $params[] = $f_canal; }
if ($f_servicio)   { $where[] = "t.tipo_servicio_id = ?"; $params[] = $f_servicio; }
if ($f_from)       { $where[] = "t.fecha_creacion >= ?";  $params[] = $f_from; }
if ($f_to)         { $where[] = "t.fecha_creacion <= ?";  $params[] = $f_to; }
if ($f_estado === 'resuelto')     { $where[] = "e.es_resuelto = 1"; }
if ($f_estado === 'sin_resolver') { $where[] = "e.es_resuelto = 0"; }
if ($f_estado === 'vencido')      { $where[] = "e.es_resuelto = 0 AND t.fecha_estimada < CURDATE()"; }
$W = implode(' AND ', $where);

$FROM = "FROM tickets t
         LEFT JOIN cat_estado        e  ON e.id  = t.estado_id
         LEFT JOIN cat_prioridad     p  ON p.id  = t.prioridad_id
         LEFT JOIN cat_grupo         g  ON g.id  = t.grupo_id
         LEFT JOIN cat_delegacion    d  ON d.id  = t.delegacion_id
         LEFT JOIN cat_canal_origen  co ON co.id = t.canal_origen_id
         LEFT JOIN cat_tipo_servicio ts ON ts.id = t.tipo_servicio_id";

// ============================================================
//  ENDPOINT AJAX · detalle de un ticket (panel lateral)
// ============================================================
if (isset($_GET['detalle'])) {
    header('Content-Type: text/html; charset=utf-8');
    $id = (int)$_GET['detalle'];
    $t = qOne($pdo, "SELECT t.*, e.nombre AS _estado, e.es_resuelto AS _resuelto,
                            p.nombre AS _prioridad, g.nombre AS _grupo,
                            d.nombre AS _delegacion, co.nombre AS _canal, ts.nombre AS _servicio
                     $FROM WHERE t.ticket_id = ?", [$id]);
    if (!$t) { echo '<div class="dpad">No se encontró el ticket.</div>'; exit; }

    $vencido = ((int)$t['_resuelto'] === 0 && !empty($t['fecha_estimada']) && $t['fecha_estimada'] < date('Y-m-d'));
    $e = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);
    // helper de fila
    $f = function (string $k, $v, bool $mono = false) use ($e) {
        if ($v === null || $v === '') return '';
        return '<div class="f"><div class="k">' . $e($k) . '</div><div class="v' . ($mono ? ' mono' : '') . '">' . nl2br($e($v)) . '</div></div>';
    };

    // columnas ya mostradas en secciones fijas (no repetir en "otros campos")
    $shown = ['ticket_id','estado_id','prioridad_id','grupo_id','tipo_servicio_id','delegacion_id','canal_id','canal_origen_id',
              'fecha_creacion','fecha_estimada','fecha_resolucion','colonia','direccion','latitud','longitud','coordenadas_raw',
              'solicitante_nombre_completo','solicitante_nombre','fuente_archivo','fuente_archivo','zd_raw','zd_importado_en',
              'zd_estado','zd_delegacion','zd_colonia','zd_servicio','zd_direccion'];
    ob_start(); ?>
      <div class="dhead">
        <div>
          <div class="dt-id">#<?= (int)$t['ticket_id'] ?></div>
          <div class="dt-sub"><?= $e($t['_servicio'] ?: '—') ?> · <?= $e($t['_grupo'] ?: '—') ?></div>
        </div>
        <div class="dt-pills">
          <span class="pill <?= (int)$t['_resuelto']===1?'positive':'neutral' ?>"><?= $e($t['_estado'] ?: '—') ?></span>
          <?php if ($vencido): ?><span class="pill negative">vencido</span><?php endif; ?>
          <?php if ($t['_prioridad']): ?><span class="pill warning"><?= $e($t['_prioridad']) ?></span><?php endif; ?>
        </div>
      </div>

      <?php if ($zsub): ?>
        <a class="zd-link" href="https://<?= $e($zsub) ?>.zendesk.com/agent/tickets/<?= (int)$t['ticket_id'] ?>" target="_blank" rel="noopener">↗ Abrir en Zendesk</a>
      <?php endif; ?>

      <div class="dsec">Fechas</div>
      <?= $f('Creación', $t['fecha_creacion']) ?>
      <?= $f('Estimada de resolución', $t['fecha_estimada']) ?>
      <?= $f('Resolución', $t['fecha_resolucion']) ?>

      <div class="dsec">Ubicación</div>
      <?= $f('Delegación', $t['_delegacion']) ?>
      <?= $f('Colonia', $t['colonia']) ?>
      <?= $f('Dirección', $t['direccion']) ?>
      <?php if (!empty($t['latitud']) && !empty($t['longitud'])): ?>
        <div class="f"><div class="k">Coordenadas</div><div class="v mono">
          <a class="zd-link" style="padding:0;border:0" href="https://www.google.com/maps?q=<?= $e($t['latitud']) ?>,<?= $e($t['longitud']) ?>" target="_blank"><?= $e($t['latitud']) ?>, <?= $e($t['longitud']) ?> ↗</a>
        </div></div>
      <?php endif; ?>

      <div class="dsec">Solicitante / canal</div>
      <?= $f('Solicitante', $t['solicitante_nombre_completo'] ?? ($t['solicitante_nombre'] ?? null)) ?>
      <?= $f('Canal', $t['_canal']) ?>

      <?php
        // Otros campos personalizados con valor
        $otros = '';
        foreach ($t as $col => $val) {
            if (in_array($col, $shown, true) || $col[0] === '_') continue;
            if ($val === null || $val === '') continue;
            $otros .= $f(etiqueta($col, $labels), $val, true);
        }
        if ($otros !== ''): ?>
        <div class="dsec">Campos personalizados</div>
        <?= $otros ?>
      <?php endif; ?>

      <?php if (!empty($t['zd_raw'])):
        $pretty = json_encode(json_decode($t['zd_raw'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      ?>
        <details class="draw">
          <summary>JSON crudo de Zendesk (auditoría)</summary>
          <pre><?= $e($pretty ?: $t['zd_raw']) ?></pre>
        </details>
      <?php endif; ?>
    <?php
    echo ob_get_clean();
    exit;
}

// ============================================================
//  Catálogos + paginación + listado
// ============================================================
$cat_grupos       = $pdo->query("SELECT id,nombre FROM cat_grupo ORDER BY nombre")->fetchAll();
$cat_delegaciones = $pdo->query("SELECT id,nombre FROM cat_delegacion ORDER BY nombre")->fetchAll();
$cat_canales      = $pdo->query("SELECT id,nombre FROM cat_canal_origen ORDER BY nombre")->fetchAll();
$cat_servicios    = $pdo->query("SELECT id,nombre FROM cat_tipo_servicio ORDER BY nombre")->fetchAll();

$total = (int) qOne($pdo, "SELECT COUNT(*) AS n $FROM WHERE $W", $params)['n'];

$por_pag = 50;
$pags    = max(1, (int)ceil($total / $por_pag));
$pag     = max(1, min($pags, (int)($_GET['p'] ?? 1)));
$offset  = ($pag - 1) * $por_pag;

$rows = q($pdo, "SELECT t.ticket_id, t.fecha_creacion, t.fecha_estimada, t.colonia, t.direccion,
                        t.latitud, t.longitud,
                        e.nombre AS estado, e.es_resuelto, p.nombre AS prioridad,
                        g.nombre AS grupo, d.nombre AS delegacion, ts.nombre AS servicio
                 $FROM WHERE $W
                 ORDER BY t.fecha_creacion DESC, t.ticket_id DESC
                 LIMIT $por_pag OFFSET $offset", $params);

// querystring base para paginación (sin 'p')
$qs = $_GET; unset($qs['p']);
$base_qs = http_build_query($qs);

// chips de filtros aplicados
$filtros = [];
if ($f_servicio)   foreach ($cat_servicios as $s)    if ($s['id']==$f_servicio)   $filtros[] = 'Servicio: ' . $s['nombre'];
if ($f_grupo)      foreach ($cat_grupos as $g)       if ($g['id']==$f_grupo)      $filtros[] = 'Grupo: ' . $g['nombre'];
if ($f_delegacion) foreach ($cat_delegaciones as $d) if ($d['id']==$f_delegacion) $filtros[] = 'Delegación: ' . $d['nombre'];
if ($f_canal)      foreach ($cat_canales as $c)      if ($c['id']==$f_canal)      $filtros[] = 'Canal: ' . $c['nombre'];
if ($f_estado)     $filtros[] = 'Estado: ' . $f_estado;
?>
<?php
$ktTitle  = 'Visor de tickets · Zendesk';
$ktActive = 'zendesk';
require __DIR__ . '/../../views/layout/kt_top.php';
?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{--bg:#fafafa;--surface:#fff;--border:#ececec;--border-strong:#e0e0e0;
    --text:#1a1a1a;--text-muted:#6b7280;--text-faint:#9ca3af;
    --accent:#254185;--positive:#188a5b;--warning:#d99000;--negative:#ce3a2b;--neutral:#005ab2;--accent2:#2a9eda}
  *{box-sizing:border-box;-webkit-font-smoothing:antialiased}
  body{margin:0;font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:14px;line-height:1.5}
  .container{max-width:1400px;margin:0 auto;padding:32px 32px 80px}
  header{margin-bottom:24px;display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px}
  header h1{font-size:22px;font-weight:600;letter-spacing:-.02em;margin:0 0 6px}
  header .crumb{color:var(--text-muted);font-size:13px}
  header .crumb a{color:var(--accent);text-decoration:none}
  .nav{display:flex;gap:8px;flex-wrap:wrap}
  .nav a{font-size:12px;padding:8px 14px;border:1px solid var(--border);border-radius:8px;color:var(--text);text-decoration:none;background:#fff;font-weight:500}
  .nav a.active{background:var(--text);color:#fff;border-color:var(--text)}
  .filter-bar{background:#fff;border:1px solid var(--border);border-radius:10px;padding:16px 18px;margin-bottom:18px}
  .filter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;align-items:end}
  @media(max-width:680px){.filter-grid{grid-template-columns:repeat(2,1fr)}}
  .filter-grid label{display:block;font-size:11px;font-weight:600;color:var(--text-faint);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
  .filter-grid select,.filter-grid input{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font:inherit;font-size:13px;background:#fff;color:var(--text)}
  .filter-grid button{padding:8px 16px;background:var(--accent);color:#fff;border:0;border-radius:6px;font:inherit;font-weight:500;cursor:pointer;font-size:13px}
  .chip{background:#eff6ff;color:#1d4ed8;padding:3px 9px;border-radius:999px;font-size:12px;font-weight:500}
  .toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:10px}
  .count{font-size:13px;color:var(--text-muted)}
  .count b{color:var(--text);font-weight:600}
  .applied{display:flex;flex-wrap:wrap;gap:6px}
  .table-card{background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden}
  table{width:100%;border-collapse:collapse;font-size:13px}
  thead th{text-align:left;font-weight:500;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-faint);padding:10px 12px;border-bottom:1px solid var(--border-strong);background:#fbfbfc}
  tbody td{padding:10px 12px;border-bottom:1px solid var(--border);vertical-align:top}
  tbody tr:last-child td{border-bottom:none}
  tbody tr{cursor:pointer}
  tbody tr:hover{background:#eff6ff}
  td.code{font-family:ui-monospace,Menlo,monospace;font-size:12px;color:var(--text-muted)}
  .pill{display:inline-block;font-size:11px;padding:2px 8px;border-radius:999px;font-weight:500}
  .pill.positive{background:#ecfdf5;color:#047857}
  .pill.warning{background:#fffbeb;color:#b45309}
  .pill.negative{background:#fef2f2;color:#b91c1c}
  .pill.neutral{background:#f3f4f6;color:#374151}
  .muted{color:var(--text-muted);font-size:12px}
  .empty-box{text-align:center;padding:60px 20px;color:var(--text-muted)}
  .empty-box .big{font-size:40px;margin-bottom:8px}
  .pager{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap}
  .pager a,.pager span.cur{font-size:13px;padding:7px 13px;border:1px solid var(--border);border-radius:7px;color:var(--text);text-decoration:none;background:#fff;font-weight:500}
  .pager a:hover{background:#f5f8ff}
  .pager .disabled{opacity:.4;pointer-events:none}
  .pager .cur{background:var(--text);color:#fff;border-color:var(--text)}

  /* ===== Panel lateral de detalle ===== */
  .overlay{position:fixed;inset:0;background:rgba(15,23,42,.35);opacity:0;visibility:hidden;transition:.2s;z-index:50}
  .overlay.open{opacity:1;visibility:visible}
  .panel{position:fixed;top:0;right:0;height:100%;width:min(560px,94vw);background:#fff;box-shadow:-8px 0 30px rgba(0,0,0,.12);
    transform:translateX(100%);transition:transform .25s ease;z-index:51;display:flex;flex-direction:column}
  .panel.open{transform:translateX(0)}
  .panel-top{display:flex;justify-content:flex-end;padding:10px 14px;border-bottom:1px solid var(--border)}
  .panel-top button{background:none;border:0;font-size:22px;cursor:pointer;color:var(--text-muted);line-height:1}
  .panel-body{overflow-y:auto;padding:0 22px 30px}
  .dpad{padding:30px}
  .dhead{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:18px 0 10px;flex-wrap:wrap}
  .dt-id{font-size:20px;font-weight:700}
  .dt-sub{font-size:13px;color:var(--text-muted);margin-top:2px}
  .dt-pills{display:flex;gap:6px;flex-wrap:wrap}
  .zd-link{display:inline-block;margin:4px 0 8px;font-size:12px;font-weight:600;color:var(--accent);text-decoration:none;border:1px solid #bfdbfe;background:#eff6ff;padding:6px 11px;border-radius:7px}
  .dsec{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--text-faint);font-weight:600;margin:18px 0 8px;border-top:1px solid var(--border);padding-top:14px}
  .f{display:grid;grid-template-columns:170px 1fr;gap:10px;padding:5px 0;font-size:13px}
  .f .k{color:var(--text-muted)}
  .f .v{color:var(--text);word-break:break-word}
  .f .v.mono{font-family:ui-monospace,Menlo,monospace;font-size:12px}
  .draw{margin-top:18px}
  .draw summary{cursor:pointer;font-size:12px;font-weight:600;color:var(--text-muted)}
  .draw pre{background:#0f172a;color:#e2e8f0;border-radius:8px;padding:14px;overflow:auto;font-size:11px;line-height:1.5;max-height:380px;margin-top:10px}
  .loading{padding:40px;text-align:center;color:var(--text-muted)}
</style>

<div class="container">

<header>
  <div>
    <h1>Visor de tickets</h1>
    <div class="crumb"><a href="dashboard.php">Dashboard</a> → Tickets · filtra, abre y revisa el detalle completo</div>
  </div>
  </header>

<!-- ============= FILTROS ============= -->
<form class="filter-bar" method="get">
  <div class="filter-grid">
    <div>
      <label>Tipo de servicio</label>
      <select name="tipo_servicio">
        <option value="">— Todos —</option>
        <?php foreach ($cat_servicios as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $f_servicio==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Grupo</label>
      <select name="grupo">
        <option value="">— Todos —</option>
        <?php foreach ($cat_grupos as $g): ?>
          <option value="<?= $g['id'] ?>" <?= $f_grupo==$g['id']?'selected':'' ?>><?= htmlspecialchars($g['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Delegación</label>
      <select name="delegacion">
        <option value="">— Todas —</option>
        <?php foreach ($cat_delegaciones as $d): ?>
          <option value="<?= $d['id'] ?>" <?= $f_delegacion==$d['id']?'selected':'' ?>><?= htmlspecialchars($d['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Canal</label>
      <select name="canal">
        <option value="">— Todos —</option>
        <?php foreach ($cat_canales as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $f_canal==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Estado</label>
      <select name="estado">
        <option value="">— Todos —</option>
        <option value="sin_resolver" <?= $f_estado==='sin_resolver'?'selected':'' ?>>Sin resolver</option>
        <option value="vencido"      <?= $f_estado==='vencido'?'selected':'' ?>>Vencidos</option>
        <option value="resuelto"     <?= $f_estado==='resuelto'?'selected':'' ?>>Resueltos</option>
      </select>
    </div>
    <div>
      <label>Desde</label>
      <input type="date" name="from" value="<?= htmlspecialchars($f_from) ?>">
    </div>
    <div>
      <label>Hasta</label>
      <input type="date" name="to" value="<?= htmlspecialchars($f_to) ?>">
    </div>
    <div>
      <label>Formulario</label>
      <?= zd_form_select() ?>
    </div>
    <div><button type="submit">Filtrar</button></div>
  </div>
</form>

<div class="toolbar">
  <div class="count"><b><?= number_format($total) ?></b> tickets · página <?= $pag ?> de <?= $pags ?></div>
  <div class="applied">
    <?php foreach ($filtros as $fl): ?><span class="chip"><?= htmlspecialchars($fl) ?></span><?php endforeach; ?>
  </div>
</div>

<div class="table-card">
  <?php if (!$rows): ?>
    <div class="empty-box"><div class="big">🔍</div>No hay tickets con estos filtros.</div>
  <?php else: ?>
    <table>
      <thead><tr>
        <th style="width:90px">Ticket</th>
        <th style="width:100px">Creación</th>
        <th>Servicio</th>
        <th>Grupo</th>
        <th>Delegación / Colonia</th>
        <th style="width:130px">Estado</th>
        <th style="width:90px">Prioridad</th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $r):
          $venc = ((int)$r['es_resuelto']===0 && !empty($r['fecha_estimada']) && $r['fecha_estimada'] < date('Y-m-d'));
        ?>
          <tr onclick="abrir(<?= (int)$r['ticket_id'] ?>)">
            <td class="code">#<?= (int)$r['ticket_id'] ?></td>
            <td class="muted"><?= htmlspecialchars($r['fecha_creacion']) ?></td>
            <td><?= htmlspecialchars(mb_strimwidth($r['servicio'] ?? '—', 0, 34, '…')) ?></td>
            <td class="muted"><?= htmlspecialchars(mb_strimwidth($r['grupo'] ?? '—', 0, 28, '…')) ?></td>
            <td>
              <div class="muted"><?= htmlspecialchars($r['delegacion'] ?? '—') ?></div>
              <div><?= htmlspecialchars(mb_strimwidth($r['colonia'] ?? '—', 0, 34, '…')) ?></div>
            </td>
            <td>
              <span class="pill <?= (int)$r['es_resuelto']===1?'positive':'neutral' ?>"><?= htmlspecialchars($r['estado'] ?? '—') ?></span>
              <?php if ($venc): ?><span class="pill negative">vencido</span><?php endif; ?>
            </td>
            <td class="muted"><?= htmlspecialchars($r['prioridad'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php if ($pags > 1): ?>
<div class="pager">
  <a class="<?= $pag<=1?'disabled':'' ?>" href="?<?= htmlspecialchars($base_qs) ?>&p=<?= $pag-1 ?>">← Anteriores</a>
  <span class="cur">Página <?= $pag ?> / <?= $pags ?></span>
  <a class="<?= $pag>=$pags?'disabled':'' ?>" href="?<?= htmlspecialchars($base_qs) ?>&p=<?= $pag+1 ?>">Siguientes →</a>
</div>
<?php endif; ?>

</div>

<!-- ===== Panel lateral de detalle ===== -->
<div class="overlay" id="ov" onclick="cerrar()"></div>
<aside class="panel" id="panel" aria-label="Detalle del ticket">
  <div class="panel-top"><button onclick="cerrar()" title="Cerrar">×</button></div>
  <div class="panel-body" id="panel-body"><div class="loading">Cargando…</div></div>
</aside>

<script>
  const ov = document.getElementById('ov');
  const panel = document.getElementById('panel');
  const body = document.getElementById('panel-body');
  function abrir(id){
    body.innerHTML = '<div class="loading">Cargando ticket #' + id + '…</div>';
    ov.classList.add('open'); panel.classList.add('open');
    fetch('tickets.php?detalle=' + id, {headers:{'X-Requested-With':'fetch'}})
      .then(r => r.text())
      .then(html => { body.innerHTML = html; })
      .catch(() => { body.innerHTML = '<div class="dpad">No se pudo cargar el ticket.</div>'; });
  }
  function cerrar(){ ov.classList.remove('open'); panel.classList.remove('open'); }
  document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrar(); });
</script>
<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
