<?php
/**
 * Importador electoral — 3 pasos con preview y transacción.
 *
 *   Paso 1: subir archivo + escoger proceso + tipo de elección
 *   Paso 2: preview con chips de secciones, partidos nuevos, coaliciones,
 *           sanity check de totales. Opciones: registrar fantasmas como
 *           históricas, registrar partidos nuevos, registrar coaliciones.
 *   Paso 3: commit dentro de transacción. Resumen + descarga de errores CSV.
 *
 * Historial al final de la pantalla con botón "Revertir" por carga.
 */
$REQUIRE_ROLES = ['administrador'];
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/import_resultados.php';
require_once __DIR__ . '/../../lib/import_resultados_catalog.php';

ini_set('display_errors', '1');
set_time_limit(600);

$pdo  = reporteador_pdo();
$BASE = reporteador_base_url_safe();
$flash = null; $flashType = 'success';

/* ---------------------------- Catálogos ---------------------------- */
$procesos = $pdo->query(
    "SELECT id, anio, nivel, descripcion, status FROM procesos_electorales
     ORDER BY anio DESC, nivel"
)->fetchAll();

$tiposEleccion = $pdo->query(
    "SELECT id, codigo, nombre, ambito, nivel FROM tipos_eleccion ORDER BY nivel, ambito"
)->fetchAll();

$catalog = ir_catalog();
$catalogStatus = ir_catalog_status($pdo);

/* ---------------------------- Acciones ---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        /* ---- Paso 1 (modo guiado por catálogo) ---- */
        if ($action === 'upload_catalog' && !empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $catalogId = $_POST['catalog_id'] ?? '';
            if (!isset($catalog[$catalogId])) throw new RuntimeException('Archivo no reconocido en catálogo.');
            $entry = $catalog[$catalogId];
            $resolved = ir_catalog_resolve($entry, $pdo);
            if (!$resolved['proceso_id'] || !$resolved['tipo_id']) {
                throw new RuntimeException('No se pudo resolver proceso/tipo del catálogo. Revisa schema.sql.');
            }
            $procesoId = $resolved['proceso_id'];
            $tipoId    = $resolved['tipo_id'];
            $kind      = $resolved['archivo_tipo'];
            $catalogContext = $entry;
        }
        /* ---- Paso 1 (modo libre — backup avanzado) ---- */
        elseif ($action === 'upload' && !empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $procesoId = (int)($_POST['proceso_id'] ?? 0);
            $tipoId    = (int)($_POST['tipo_eleccion_id'] ?? 0);
            $kind      = $_POST['archivo_tipo'] ?? '';
            $catalogContext = null;

            if ($procesoId === 0 || $tipoId === 0 || $kind === '') {
                throw new RuntimeException('Falta seleccionar proceso, tipo de elección o tipo de archivo.');
            }
        }

        /* ---- Parsing común (después de catalog o manual) ---- */
        if (in_array($action, ['upload_catalog', 'upload'], true)
            && !empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK
            && !empty($procesoId)) {

            $tmp = $_FILES['file']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            $dest = sys_get_temp_dir() . '/pan_resultados_' . session_id() . '.' . $ext;
            move_uploaded_file($tmp, $dest);

            // Validación extensión vs tipo elegido (evita "No se pudo abrir XLSX" con un .csv)
            if ($kind === 'xlsx_2021_casilla' && $ext !== 'xlsx') {
                throw new RuntimeException("Elegiste un formato XLSX 2021, pero el archivo es .$ext. "
                    . "Si es un archivo 2024, escoge la opción CSV (resultados o candidaturas).");
            }
            if (in_array($kind, ['csv_2024_resultados','csv_2024_candidaturas'], true) && $ext !== 'csv') {
                throw new RuntimeException("Elegiste un formato CSV 2024, pero el archivo es .$ext. "
                    . "Si es un archivo 2021, escoge la opción XLSX.");
            }

            if ($kind === 'csv_2024_resultados')        $parsed = ir_parse_csv_2024_resultados($dest);
            elseif ($kind === 'csv_2024_candidaturas')  $parsed = ir_parse_csv_2024_candidaturas($dest);
            elseif ($kind === 'xlsx_2021_casilla')      $parsed = ir_parse_xlsx_2021_casilla($dest);
            else throw new RuntimeException('Tipo de archivo no soportado: ' . $kind);

            if (empty($parsed['rows']) && !empty($parsed['errors'])) {
                throw new RuntimeException('No se extrajo nada. Errores: ' . json_encode($parsed['errors']));
            }
            if (empty($parsed['rows'])) {
                throw new RuntimeException('No se detectaron filas válidas en el archivo.');
            }

            // Sólo los tipos de resultados van por validación cruzada
            $validation = null;
            if ($kind !== 'csv_2024_candidaturas') {
                $validation = ir_validate($parsed, $pdo, $procesoId, $tipoId);
            }

            // Para el commit usamos el nombre del CATÁLOGO (no del archivo subido).
            // Así puedes subir "QRO_AYUN_RESULTADOS_2024 (1).csv" y la bitácora dice
            // "QRO_AYUN_RESULTADOS_2024.csv" para que el status del catálogo coincida.
            $logFilename = $catalogContext['filename'] ?? basename($_FILES['file']['name']);

            $_SESSION['ir_upload'] = [
                'parsed'       => $parsed,
                'validation'   => $validation,
                'proceso_id'   => $procesoId,
                'tipo_id'      => $tipoId,
                'archivo_tipo' => $kind,
                'file'         => $logFilename,
                'real_file'    => basename($_FILES['file']['name']),
                'tmp_path'     => $dest,
                'uploaded'     => date('Y-m-d H:i:s'),
                'catalog'      => $catalogContext,
            ];
            header('Location: ' . $BASE . '/admin/importar_resultados.php?preview=1');
            exit;
        }

        /* ---- Paso 3: confirmar e insertar ---- */
        if ($action === 'commit') {
            $cache = $_SESSION['ir_upload'] ?? null;
            if (!$cache) throw new RuntimeException('No hay carga en sesión, vuelve a subir el archivo.');

            $opts = [
                'register_historicas'    => !empty($_POST['register_historicas']),
                'register_new_parties'   => !empty($_POST['register_new_parties']),
                'register_new_coaliciones' => !empty($_POST['register_new_coaliciones']),
                'anio_historico'         => (int)($_POST['anio_historico'] ?? 2021),
                'source' => 'web',
            ];

            $stats = ir_commit(
                $cache['parsed'], $cache['validation'] ?? [],
                $cache['proceso_id'], $cache['tipo_id'],
                $opts, $pdo, $cache['file']
            );

            $_SESSION['ir_last_result'] = ['stats' => $stats, 'file' => $cache['file']];
            unset($_SESSION['ir_upload']);
            header('Location: ' . $BASE . '/admin/importar_resultados.php?done=1');
            exit;
        }

        if ($action === 'cancel') {
            unset($_SESSION['ir_upload']);
            $flash = 'Carga cancelada.';
            $flashType = 'info';
        }

        /* ---- Revertir una carga histórica ---- */
        if ($action === 'revert') {
            $logId = (int)($_POST['log_id'] ?? 0);
            if (!$logId) throw new RuntimeException('log_id requerido');
            $r = ir_revert($logId, $pdo);
            $flash = "Reversa OK · log #$logId · resultados borrados: " . number_format($r['resultados_borrados']);
        }

        /* ---- Descargar CSV de errores de una carga ---- */
        if ($action === 'errors_csv') {
            $logId = (int)($_POST['log_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, archivo, errores_json FROM import_log_resultados WHERE id = ?");
            $stmt->execute([$logId]);
            $log = $stmt->fetch();
            if (!$log) throw new RuntimeException('log no encontrado');
            $errs = json_decode($log['errores_json'] ?? '[]', true) ?: [];
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="errores_log_' . $logId . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['line', 'seccion', 'error']);
            foreach ($errs as $e) fputcsv($out, [$e['line'] ?? '', $e['sec'] ?? '', $e['msg'] ?? '']);
            fclose($out); exit;
        }

    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}

/* ---------------------------- Vista ---------------------------- */
$preview = $_SESSION['ir_upload'] ?? null;
$lastResult = $_SESSION['ir_last_result'] ?? null;
if (isset($_GET['done'])) { /* mantener */ } else { unset($_SESSION['ir_last_result']); }

// Historial reciente
$historial = $pdo->query(
    "SELECT l.*, u.nombre AS by_name
       FROM import_log_resultados l
  LEFT JOIN usuarios u ON u.id = l.created_by
   ORDER BY l.id DESC
      LIMIT 25"
)->fetchAll();

$title = 'Importar resultados por sección';
$active = 'importar-resultados';
include __DIR__ . '/../partials/layout_top.php';
?>

<div class="page-header">
  <div>
    <h1>Importar resultados por sección</h1>
    <p>Carga atómica con validación previa, bitácora y reversa. Si algo falla, no queda nada a medias.</p>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert <?= htmlspecialchars($flashType) ?>"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<?php if ($lastResult && isset($_GET['done'])): ?>
  <?php $s = $lastResult['stats']; ?>
  <div class="card" style="background:#ECFDF5;border-color:#86EFAC">
    <h2 style="margin-top:0">Carga exitosa · <span class="mono"><?= htmlspecialchars($lastResult['file']) ?></span></h2>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px">
      <span class="chip success">✓ <?= number_format($s['rows_ok']) ?> filas OK</span>
      <?php if ($s['rows_failed']>0): ?><span class="chip error">✕ <?= number_format($s['rows_failed']) ?> con error</span><?php endif; ?>
      <?php if ($s['rows_skipped']>0): ?><span class="chip muted">↷ <?= number_format($s['rows_skipped']) ?> omitidas</span><?php endif; ?>
      <span class="chip info">Casillas creadas · <?= number_format($s['casillas_creadas']) ?></span>
      <span class="chip info">Resultados insertados · <?= number_format($s['resultados_insertados']) ?></span>
      <?php if ($s['partidos_nuevos']>0): ?><span class="chip info">Partidos nuevos · <?= $s['partidos_nuevos'] ?></span><?php endif; ?>
      <?php if ($s['coaliciones_nuevas']>0): ?><span class="chip info">Coaliciones · <?= $s['coaliciones_nuevas'] ?></span><?php endif; ?>
      <?php if ($s['secciones_historicas_creadas']>0): ?><span class="chip info">Secciones históricas · <?= $s['secciones_historicas_creadas'] ?></span><?php endif; ?>
    </div>
    <p class="muted" style="margin:0">Bitácora #<?= $s['log_id'] ?>. Si detectas un problema, usa <strong>Revertir</strong> en el historial.</p>
  </div>
<?php endif; ?>

<?php if (!$preview): ?>
  <!-- ===== Checklist guiada de archivos esperados ===== -->
  <div class="card">
    <h2 style="margin-top:0">Carga guiada — archivos oficiales del IEEQ</h2>
    <p class="muted" style="margin-top:0">
      Cada renglón corresponde a un archivo exacto del catálogo IEEQ. El sistema ya sabe a qué proceso y tipo de elección pertenece — solo escoges el archivo. <strong>Sigue el orden de arriba abajo</strong>: las candidaturas dependen de que sus resultados ya estén cargados.
    </p>

    <?php
      // Agrupar por proceso
      $byProceso = [];
      foreach ($catalog as $id => $e) {
          $k = $e['proceso']['anio'] . ' · ' . ucfirst($e['proceso']['nivel']);
          $byProceso[$k][] = $id;
      }
      ksort($byProceso);
    ?>

    <?php foreach ($byProceso as $procLabel => $ids): ?>
      <h3 style="margin:18px 0 8px"><?= htmlspecialchars($procLabel) ?></h3>
      <table class="table" style="margin:0">
        <thead>
          <tr><th style="width:30px">#</th><th>Elección / Archivo</th><th>Estado</th><th style="width:240px">Subir</th></tr>
        </thead>
        <tbody>
          <?php foreach ($ids as $id):
            $e = $catalog[$id];
            $st = $catalogStatus[$id];
            $depOk = !$e['depends_on'] || ($catalogStatus[$e['depends_on']]['status'] === 'ok');
          ?>
            <tr>
              <td><?= $e['orden'] ?></td>
              <td>
                <div style="font-weight:600"><?= htmlspecialchars($e['label']) ?></div>
                <div style="font-size:11px;color:var(--color-text-secondary)">
                  <span class="mono"><?= htmlspecialchars($e['filename']) ?></span> ·
                  <?= htmlspecialchars($e['notas']) ?>
                  <?php if ($e['depends_on']): ?>
                    · requiere <span class="mono"><?= htmlspecialchars($catalog[$e['depends_on']]['filename']) ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <?php if ($st['status'] === 'ok'): ?>
                  <span class="chip success">✓ Cargado #<?= $st['log']['id'] ?></span>
                  <div style="font-size:11px;color:var(--color-text-secondary);margin-top:2px">
                    <?= number_format($st['log']['rows_ok']) ?> filas ·
                    <?= number_format($st['log']['resultados_insertados']) ?> resultados
                    <?php if ($st['log']['candidatos_creados']>0): ?>
                      · <?= number_format($st['log']['candidatos_creados']) ?> candidatos
                    <?php endif; ?>
                  </div>
                <?php elseif ($st['status'] === 'con_errores'): ?>
                  <span class="chip error">⚠ Con errores #<?= $st['log']['id'] ?></span>
                  <div style="font-size:11px;color:var(--color-text-secondary);margin-top:2px">
                    <?= number_format($st['log']['rows_failed']) ?> fallaron
                  </div>
                <?php elseif ($st['status'] === 'vacio'): ?>
                  <span class="chip muted">↷ Cargado pero 0 filas</span>
                <?php else: ?>
                  <span class="chip muted">Pendiente</span>
                <?php endif; ?>
              </td>
              <td>
                <form method="post" enctype="multipart/form-data" style="display:flex;gap:6px;align-items:center">
                  <input type="hidden" name="action" value="upload_catalog">
                  <input type="hidden" name="catalog_id" value="<?= htmlspecialchars($id) ?>">
                  <input type="file" name="file" required accept=".csv,.xlsx" style="max-width:160px;font-size:11px">
                  <button class="btn-mini" type="submit"
                          <?php if (!$depOk): ?>disabled title="Carga primero el archivo de resultados"<?php endif; ?>>
                    <?= $st['status'] === 'ok' ? 'Recargar' : 'Cargar' ?>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endforeach; ?>
  </div>

  <!-- ===== Modo libre (oculto en acordeón) ===== -->
  <details class="card" style="margin-top:18px">
    <summary style="cursor:pointer;font-weight:600">Modo libre (archivos fuera del catálogo)</summary>
    <p class="muted">Solo úsalo si el archivo no está en la checklist de arriba. Aquí elegies tú los parámetros.</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div class="field">
          <label>Proceso electoral</label>
          <select name="proceso_id" class="input" required>
            <option value="">— elige proceso —</option>
            <?php foreach ($procesos as $p): ?>
              <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['anio'] . ' · ' . ucfirst($p['nivel']) . ' — ' . $p['descripcion']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Tipo de elección</label>
          <select name="tipo_eleccion_id" class="input" required>
            <option value="">— elige tipo —</option>
            <?php foreach ($tiposEleccion as $t): ?>
              <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['nombre']) ?> (<?= htmlspecialchars($t['ambito']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="field" style="margin-top:14px">
        <label>Tipo de archivo</label>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <label class="chip-radio"><input type="radio" name="archivo_tipo" value="csv_2024_resultados" required><span>CSV 2024 — Resultados</span></label>
          <label class="chip-radio"><input type="radio" name="archivo_tipo" value="csv_2024_candidaturas"><span>CSV 2024 — Candidaturas</span></label>
          <label class="chip-radio"><input type="radio" name="archivo_tipo" value="xlsx_2021_casilla"><span>XLSX 2021 — Hoja "Casilla"</span></label>
        </div>
      </div>

      <div class="field" style="margin-top:14px">
        <label>Archivo</label>
        <input type="file" name="file" required accept=".csv,.xlsx">
      </div>

      <div style="margin-top:12px"><button class="btn primary" type="submit">Analizar archivo</button></div>
    </form>
  </details>

  <!-- ===== Historial ===== -->
  <div class="card" style="margin-top:18px">
    <h2 style="margin-top:0">Historial de cargas</h2>
    <?php if (empty($historial)): ?>
      <p class="muted">No hay cargas previas.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>#</th><th>Archivo</th><th>Tipo</th><th>Filas OK</th>
            <th>Casillas</th><th>Resultados</th><th>Errores</th>
            <th>Inicio</th><th>Fin</th><th>Por</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($historial as $h): ?>
            <tr>
              <td>#<?= $h['id'] ?></td>
              <td class="mono" style="font-size:12px"><?= htmlspecialchars($h['archivo']) ?></td>
              <td><span class="chip muted"><?= htmlspecialchars($h['tipo']) ?></span></td>
              <td><?= number_format($h['rows_ok']) ?></td>
              <td><?= number_format($h['casillas_creadas']) ?></td>
              <td><?= number_format($h['resultados_insertados']) ?></td>
              <td><?= $h['rows_failed'] > 0 ? '<span class="chip error">'.$h['rows_failed'].'</span>' : '—' ?></td>
              <td style="font-size:12px"><?= htmlspecialchars($h['started_at']) ?></td>
              <td style="font-size:12px"><?= htmlspecialchars($h['finished_at'] ?? '—') ?></td>
              <td><?= htmlspecialchars($h['by_name'] ?? '—') ?></td>
              <td style="white-space:nowrap">
                <?php if ($h['rows_failed'] > 0): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="errors_csv">
                    <input type="hidden" name="log_id" value="<?= $h['id'] ?>">
                    <button class="btn-mini" type="submit" title="Descargar errores CSV">errores</button>
                  </form>
                <?php endif; ?>
                <?php if ($h['resultados_insertados'] > 0): ?>
                  <form method="post" style="display:inline"
                        onsubmit="return confirm('¿Revertir carga #<?= $h['id'] ?>?\n\nSe borrarán <?= number_format($h['resultados_insertados']) ?> resultados y los metadatos asociados.\nNo se borran casillas, partidos ni coaliciones.');">
                    <input type="hidden" name="action" value="revert">
                    <input type="hidden" name="log_id" value="<?= $h['id'] ?>">
                    <button class="btn-mini" type="submit" style="background:#FEE2E2;color:#991B1B" title="Revertir">revertir</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

<?php else:
  /* ===== Paso 2: preview ===== */
  $parsed = $preview['parsed'];
  $val    = $preview['validation'];
  $tipoId = $preview['tipo_id'];
  $procId = $preview['proceso_id'];
?>
  <div class="card" style="background:#FEF3C7;border-color:#FCD34D">
    <h2 style="margin-top:0">Paso 2 — Revisa antes de confirmar</h2>
    <p class="muted" style="margin-top:0">
      Archivo: <span class="mono"><?= htmlspecialchars($preview['file']) ?></span> ·
      Tipo: <span class="mono"><?= htmlspecialchars($preview['archivo_tipo']) ?></span> ·
      Subido <?= htmlspecialchars($preview['uploaded']) ?>
    </p>

    <?php if ($val): ?>
      <h3>Totales</h3>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
        <span class="chip info">Casillas · <?= number_format($val['totals']['casillas']) ?></span>
        <span class="chip info">Secciones · <?= number_format($val['totals']['secciones']) ?></span>
        <span class="chip info">Ámbitos (<?= htmlspecialchars($parsed['ambito_tipo'] ?? '?') ?>) · <?= number_format($val['totals']['ambitos']) ?></span>
        <span class="chip info">Partidos · <?= number_format($val['totals']['partidos']) ?></span>
        <span class="chip info">Coaliciones · <?= number_format($val['totals']['coaliciones']) ?></span>
        <?php if ($val['totals']['sanity_mismatch']>0): ?>
          <span class="chip error">⚠ Suma vs TOTAL_VOTOS no cuadra · <?= number_format($val['totals']['sanity_mismatch']) ?></span>
        <?php endif; ?>
      </div>

      <h3>Secciones</h3>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:8px">
        <span class="chip success">✓ IEEQ vigentes · <?= number_format($val['secciones_status']['ieeq']) ?></span>
        <span class="chip info">◷ IEEQ pendientes · <?= number_format($val['secciones_status']['pendiente']) ?></span>
        <span class="chip info">📜 Históricas · <?= number_format($val['secciones_status']['historica']) ?></span>
        <span class="chip <?= $val['secciones_status']['fantasma']>0 ? 'error' : 'muted' ?>">
          ⚠ Fantasma · <?= number_format($val['secciones_status']['fantasma']) ?>
        </span>
      </div>
      <?php if (!empty($val['secciones_fantasma'])): ?>
        <details style="margin-bottom:14px">
          <summary>Ver secciones fantasma (<?= count($val['secciones_fantasma']) ?>)</summary>
          <div style="margin-top:8px;display:flex;gap:4px;flex-wrap:wrap;max-height:240px;overflow:auto">
            <?php foreach ($val['secciones_fantasma'] as $sec => $cnt): ?>
              <span class="chip muted" style="font-size:11px"><?= $sec ?> · <?= $cnt ?> filas</span>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endif; ?>

      <h3>Partidos detectados</h3>
      <?php if (!empty($val['partidos_existentes'])): ?>
        <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:6px">
          <span style="font-size:12px;color:var(--color-text-secondary);align-self:center">Ya en catálogo:</span>
          <?php foreach ($val['partidos_existentes'] as $p): ?>
            <span class="chip success" style="font-size:11px"><?= htmlspecialchars($p) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if (!empty($val['partidos_nuevos'])): ?>
        <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:6px">
          <span style="font-size:12px;color:#991B1B;align-self:center">Nuevos (requiere confirmar abajo):</span>
          <?php foreach ($val['partidos_nuevos'] as $p): ?>
            <span class="chip error" style="font-size:11px"><?= htmlspecialchars($p) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($val['coaliciones_nuevas'])): ?>
        <h3>Coaliciones detectadas</h3>
        <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:14px">
          <?php foreach ($val['coaliciones_nuevas'] as $c): ?>
            <span class="chip info" style="font-size:11px"><?= htmlspecialchars($c) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($val['sanity_issues'])): ?>
        <h3>Inconsistencias de totales (primeras 50)</h3>
        <div style="max-height:200px;overflow:auto;border:1px solid var(--color-border);border-radius:6px">
          <table class="table" style="margin:0">
            <thead><tr><th>Línea</th><th>Sección</th><th>TOTAL_VOTOS</th><th>Suma</th><th>Δ</th></tr></thead>
            <tbody>
              <?php foreach ($val['sanity_issues'] as $i): ?>
                <tr>
                  <td><?= $i['line'] ?></td>
                  <td><?= $i['sec'] ?></td>
                  <td><?= number_format($i['declared']) ?></td>
                  <td><?= number_format($i['summed']) ?></td>
                  <td style="color:<?= $i['diff'] < 0 ? '#991B1B' : '#065F46' ?>">
                    <?= $i['diff'] > 0 ? '+' : '' ?><?= number_format($i['diff']) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted">Tipo "candidaturas": no requiere validación cruzada. <?= number_format(count($parsed['rows'])) ?> filas detectadas.</p>
    <?php endif; ?>

    <hr style="border:none;border-top:1px solid #FCD34D;margin:18px 0">

    <form method="post">
      <input type="hidden" name="action" value="commit">

      <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:14px">
        <?php if ($val && !empty($val['secciones_fantasma'])): ?>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px">
            <input type="checkbox" name="register_historicas" value="1">
            Registrar <?= count($val['secciones_fantasma']) ?> secciones fantasma como históricas
          </label>
          <input type="number" name="anio_historico" value="2021" min="2000" max="2030"
                 style="width:80px" class="input" title="Año histórico para registrar">
        <?php endif; ?>

        <?php if ($val && !empty($val['partidos_nuevos'])): ?>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px">
            <input type="checkbox" name="register_new_parties" value="1">
            Crear partidos nuevos en catálogo (<?= count($val['partidos_nuevos']) ?>)
          </label>
        <?php endif; ?>

        <?php if ($val && !empty($val['coaliciones_nuevas'])): ?>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px">
            <input type="checkbox" name="register_new_coaliciones" value="1" checked>
            Registrar coaliciones de esta elección (<?= count($val['coaliciones_nuevas']) ?>)
          </label>
        <?php endif; ?>
      </div>

      <div style="display:flex;gap:10px">
        <button class="btn primary" type="submit"
                onclick="return confirm('Va a insertar en BD dentro de una transacción atómica. ¿Continuar?')">
          Confirmar e importar
        </button>
        <button class="btn" type="submit" name="action" value="cancel" formnovalidate>Cancelar</button>
      </div>
    </form>
  </div>
<?php endif; ?>

<style>
.chip-radio { display:inline-flex; align-items:center; gap:6px; padding:6px 10px;
              border:1px solid var(--color-border); border-radius:18px; cursor:pointer; font-size:13px; }
.chip-radio input { margin:0; }
.chip-radio input:checked + span { font-weight:600; color:var(--color-primary); }
.btn-mini { font-size:11px; padding:3px 8px; border:1px solid var(--color-border); border-radius:4px; cursor:pointer; background:#F3F4F6; }
.btn-mini:hover { background:#E5E7EB; }
</style>

<?php include __DIR__ . '/../partials/layout_bottom.php'; ?>
