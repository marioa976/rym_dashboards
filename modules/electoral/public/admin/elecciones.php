<?php
/**
 * Administración de elecciones.
 *
 *  - Lista de elecciones agrupadas por proceso × tipo
 *  - Detalle de una elección: coaliciones, candidatos, ámbito_nombre editable
 *  - Mapeo de candidatos huérfanos (códigos como C1, C5, PQS que no matchean
 *    ni partido ni coalición existente) → asignarlos a un partido o coalición real
 */
$REQUIRE_ROLES = ['administrador'];
require_once __DIR__ . '/../../lib/bootstrap.php';

$pdo  = reporteador_pdo();
$BASE = reporteador_base_url_safe();
$flash = null; $flashType = 'success';

/* ---------------------------- Acciones ---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'rename') {
            $eid = (int)($_POST['eleccion_id'] ?? 0);
            $name = trim($_POST['ambito_nombre'] ?? '');
            if (!$eid) throw new RuntimeException('Falta id de elección');
            $pdo->prepare("UPDATE elecciones SET ambito_nombre = ? WHERE id = ?")
                ->execute([$name ?: null, $eid]);
            $flash = 'Nombre actualizado';
        }

        if ($action === 'remap_candidato') {
            $cid = (int)($_POST['candidato_id'] ?? 0);
            $newCode = trim($_POST['nuevo_codigo'] ?? '');
            if (!$cid || $newCode === '') throw new RuntimeException('Faltan datos');
            $pdo->prepare("UPDATE candidatos SET partido_o_coalicion_codigo = ? WHERE id = ?")
                ->execute([$newCode, $cid]);
            $flash = 'Candidato re-mapeado';
        }

        if ($action === 'crear_coalicion') {
            $eid = (int)($_POST['eleccion_id'] ?? 0);
            $cod = trim($_POST['codigo'] ?? '');
            $nom = trim($_POST['nombre'] ?? '') ?: $cod;
            $partidos = array_filter(array_map('intval', $_POST['partido_ids'] ?? []));
            if (!$eid || $cod === '' || empty($partidos)) {
                throw new RuntimeException('Falta elección, código o partidos componentes');
            }
            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO coaliciones (eleccion_id, codigo, nombre, created_at)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)"
            )->execute([$eid, $cod, $nom]);
            $coalId = (int)$pdo->lastInsertId();
            if (!$coalId) {
                $s = $pdo->prepare("SELECT id FROM coaliciones WHERE eleccion_id=? AND codigo=?");
                $s->execute([$eid, $cod]);
                $coalId = (int)$s->fetchColumn();
            }
            $ins = $pdo->prepare("INSERT IGNORE INTO coaliciones_partidos (coalicion_id, partido_id) VALUES (?, ?)");
            foreach ($partidos as $pid) $ins->execute([$coalId, $pid]);
            $pdo->commit();
            $flash = 'Coalición creada · ' . $cod;
        }

        if ($action === 'borrar_coalicion') {
            $coalId = (int)($_POST['coalicion_id'] ?? 0);
            if (!$coalId) throw new RuntimeException('Falta id');
            // borrar componentes primero
            $pdo->prepare("DELETE FROM coaliciones_partidos WHERE coalicion_id=?")->execute([$coalId]);
            $pdo->prepare("DELETE FROM coaliciones WHERE id=?")->execute([$coalId]);
            $flash = 'Coalición eliminada';
        }
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'error';
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

/* ---------------------------- Datos para la vista ---------------------------- */
$selectedId = (int)($_GET['eleccion_id'] ?? 0);

// Lista agrupada
$elecciones = $pdo->query(
    "SELECT e.id, e.ambito_codigo, e.ambito_nombre,
            pe.anio, pe.nivel, pe.descripcion AS proceso_desc,
            te.codigo AS tipo_codigo, te.nombre AS tipo_nombre, te.ambito AS tipo_ambito,
            (SELECT COUNT(*) FROM resultados_casilla rc WHERE rc.eleccion_id = e.id) AS n_resultados,
            (SELECT COUNT(*) FROM coaliciones c WHERE c.eleccion_id = e.id)          AS n_coaliciones,
            (SELECT COUNT(*) FROM candidatos cd WHERE cd.eleccion_id = e.id)         AS n_candidatos
       FROM elecciones e
       JOIN procesos_electorales pe ON pe.id = e.proceso_id
       JOIN tipos_eleccion te       ON te.id = e.tipo_id
   ORDER BY pe.anio DESC, te.nombre, CAST(e.ambito_codigo AS UNSIGNED), e.ambito_nombre"
)->fetchAll();

// Detalle si hay selección
$selectedEleccion = null;
$coaliciones = $candidatos = $orphans = [];
$partidos = [];
if ($selectedId) {
    $stmt = $pdo->prepare(
        "SELECT e.*, pe.anio, pe.nivel, te.nombre AS tipo_nombre, te.codigo AS tipo_codigo
           FROM elecciones e
           JOIN procesos_electorales pe ON pe.id = e.proceso_id
           JOIN tipos_eleccion te       ON te.id = e.tipo_id
          WHERE e.id = ?"
    );
    $stmt->execute([$selectedId]);
    $selectedEleccion = $stmt->fetch();

    if ($selectedEleccion) {
        $coaliciones = $pdo->prepare(
            "SELECT c.id, c.codigo, c.nombre,
                    GROUP_CONCAT(p.siglas ORDER BY p.siglas SEPARATOR ', ') AS partidos
               FROM coaliciones c
          LEFT JOIN coaliciones_partidos cp ON cp.coalicion_id = c.id
          LEFT JOIN partidos p ON p.id = cp.partido_id
              WHERE c.eleccion_id = ?
           GROUP BY c.id, c.codigo, c.nombre
           ORDER BY c.codigo"
        );
        $coaliciones->execute([$selectedId]);
        $coaliciones = $coaliciones->fetchAll();

        $candidatos = $pdo->prepare(
            "SELECT cd.*,
                    (SELECT 1 FROM partidos p WHERE p.siglas = cd.partido_o_coalicion_codigo) AS es_partido,
                    (SELECT 1 FROM coaliciones c WHERE c.eleccion_id = cd.eleccion_id AND c.codigo = cd.partido_o_coalicion_codigo) AS es_coalicion
               FROM candidatos cd
              WHERE cd.eleccion_id = ?
           ORDER BY cd.partido_o_coalicion_codigo"
        );
        $candidatos->execute([$selectedId]);
        $candidatos = $candidatos->fetchAll();

        foreach ($candidatos as $c) {
            if (!$c['es_partido'] && !$c['es_coalicion']) $orphans[] = $c;
        }

        $partidos = $pdo->query("SELECT id, siglas, nombre FROM partidos WHERE vigente=1 ORDER BY siglas")->fetchAll();
    }
}

// Códigos válidos en esta elección (para el dropdown de re-mapeo)
$validCodes = [];
foreach ($partidos as $p) $validCodes[] = ['code'=>$p['siglas'], 'kind'=>'partido'];
foreach ($coaliciones as $c) $validCodes[] = ['code'=>$c['codigo'], 'kind'=>'coalición'];

$title = 'Administración de elecciones';
$active = 'elecciones-admin';
include __DIR__ . '/../partials/layout_top.php';
?>

<div class="page-header">
  <div>
    <h1>Elecciones, coaliciones y candidatos</h1>
    <p>Renombrar ámbitos, registrar coaliciones manualmente y mapear códigos de candidato a su partido o coalición real.</p>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert <?= htmlspecialchars($flashType) ?>"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:380px 1fr;gap:18px">
  <!-- Lista de elecciones -->
  <div class="card" style="padding:0;max-height:80vh;overflow-y:auto">
    <h2 style="padding:14px 18px;margin:0;border-bottom:1px solid var(--color-border)">Elecciones</h2>
    <?php
      $currentGroup = null;
      foreach ($elecciones as $e):
        $group = $e['anio'] . ' · ' . $e['tipo_nombre'];
        if ($group !== $currentGroup):
          if ($currentGroup !== null) echo '</div>';
          $currentGroup = $group;
    ?>
        <div style="padding:8px 14px;background:#F9FAFB;font-size:12px;font-weight:600;color:var(--color-text-secondary);text-transform:uppercase">
          <?= htmlspecialchars($group) ?>
        </div>
        <div>
    <?php endif; ?>
      <a href="?eleccion_id=<?= $e['id'] ?>"
         style="display:block;padding:8px 18px;border-bottom:1px solid #F3F4F6;text-decoration:none;color:inherit;<?= $selectedId === (int)$e['id'] ? 'background:#FEF3C7' : '' ?>">
        <div style="font-size:13px;font-weight:500">
          <?= $e['ambito_nombre'] ? htmlspecialchars($e['ambito_nombre']) : '<em style="color:#9CA3AF">(sin nombre)</em>' ?>
        </div>
        <div style="font-size:11px;color:var(--color-text-secondary)">
          #<?= $e['id'] ?> · ámbito <?= htmlspecialchars($e['ambito_codigo'] ?? 'estado') ?> ·
          <?= number_format($e['n_resultados']) ?> votos · <?= $e['n_coaliciones'] ?> coal · <?= $e['n_candidatos'] ?> cand
        </div>
      </a>
    <?php endforeach; if ($currentGroup !== null) echo '</div>'; ?>
  </div>

  <!-- Detalle de elección -->
  <div>
    <?php if (!$selectedEleccion): ?>
      <div class="card">
        <p class="muted">Selecciona una elección de la lista para ver su detalle.</p>
      </div>
    <?php else: ?>
      <!-- Datos básicos / rename -->
      <div class="card">
        <h2 style="margin-top:0">
          <?= htmlspecialchars($selectedEleccion['tipo_nombre']) ?>
          <span style="font-weight:normal;color:var(--color-text-secondary);font-size:14px">
            · <?= htmlspecialchars($selectedEleccion['anio']) ?> · ámbito <?= htmlspecialchars($selectedEleccion['ambito_codigo'] ?? 'estado') ?>
          </span>
        </h2>
        <form method="post" style="display:flex;gap:8px;align-items:end">
          <input type="hidden" name="action" value="rename">
          <input type="hidden" name="eleccion_id" value="<?= $selectedEleccion['id'] ?>">
          <div class="field" style="flex:1">
            <label>Nombre del ámbito</label>
            <input class="input" name="ambito_nombre"
                   value="<?= htmlspecialchars($selectedEleccion['ambito_nombre'] ?? '') ?>"
                   placeholder="Ej. QUERÉTARO 1, CORREGIDORA, etc.">
          </div>
          <button class="btn primary" type="submit">Guardar</button>
        </form>
      </div>

      <!-- Coaliciones -->
      <div class="card" style="margin-top:14px">
        <h3 style="margin-top:0">Coaliciones registradas (<?= count($coaliciones) ?>)</h3>
        <?php if (empty($coaliciones)): ?>
          <p class="muted">No hay coaliciones registradas en esta elección.</p>
        <?php else: ?>
          <table class="table">
            <thead><tr><th>Código</th><th>Nombre</th><th>Partidos componentes</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($coaliciones as $c): ?>
                <tr>
                  <td class="mono"><?= htmlspecialchars($c['codigo']) ?></td>
                  <td><?= htmlspecialchars($c['nombre']) ?></td>
                  <td style="font-size:12px"><?= htmlspecialchars($c['partidos'] ?? '—') ?></td>
                  <td>
                    <form method="post" style="display:inline" onsubmit="return confirm('¿Borrar coalición <?= htmlspecialchars($c['codigo']) ?>?')">
                      <input type="hidden" name="action" value="borrar_coalicion">
                      <input type="hidden" name="coalicion_id" value="<?= $c['id'] ?>">
                      <button class="btn-mini" type="submit" style="background:#FEE2E2;color:#991B1B">borrar</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

        <details style="margin-top:14px">
          <summary style="cursor:pointer;font-weight:500">+ Registrar coalición manualmente</summary>
          <form method="post" style="margin-top:10px;padding:12px;background:#F9FAFB;border-radius:6px">
            <input type="hidden" name="action" value="crear_coalicion">
            <input type="hidden" name="eleccion_id" value="<?= $selectedEleccion['id'] ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
              <div class="field">
                <label>Código (como aparece en archivos)</label>
                <input class="input" name="codigo" required placeholder="ej. C1 ó PAN-PRI-PRD">
              </div>
              <div class="field">
                <label>Nombre (opcional)</label>
                <input class="input" name="nombre" placeholder="Sigamos Haciendo Historia">
              </div>
            </div>
            <div class="field" style="margin-top:8px">
              <label>Partidos componentes</label>
              <div style="display:flex;flex-wrap:wrap;gap:6px;padding:8px;border:1px solid var(--color-border);border-radius:6px;max-height:140px;overflow-y:auto">
                <?php foreach ($partidos as $p): ?>
                  <label style="display:inline-flex;align-items:center;gap:4px;font-size:12px;padding:3px 6px;background:white;border:1px solid #E5E7EB;border-radius:14px">
                    <input type="checkbox" name="partido_ids[]" value="<?= $p['id'] ?>">
                    <?= htmlspecialchars($p['siglas']) ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
            <div style="margin-top:10px"><button class="btn primary" type="submit">Crear coalición</button></div>
          </form>
        </details>
      </div>

      <!-- Candidatos -->
      <div class="card" style="margin-top:14px">
        <h3 style="margin-top:0">Candidatos (<?= count($candidatos) ?>)</h3>
        <?php if (!empty($orphans)): ?>
          <div class="alert error" style="margin-bottom:14px">
            <strong><?= count($orphans) ?> candidatos sin enlace:</strong> sus códigos (<span class="mono"><?= htmlspecialchars(implode(', ', array_unique(array_column($orphans, 'partido_o_coalicion_codigo')))) ?></span>) no corresponden a partidos ni coaliciones registradas. Mapea cada uno a su entidad real abajo.
          </div>
        <?php endif; ?>

        <?php if (empty($candidatos)): ?>
          <p class="muted">No hay candidatos registrados.</p>
        <?php else: ?>
          <table class="table">
            <thead><tr><th>Código actual</th><th>Estado</th><th>Propietario</th><th>Suplente</th><th>Re-mapear</th></tr></thead>
            <tbody>
              <?php foreach ($candidatos as $c): ?>
                <tr style="<?= (!$c['es_partido'] && !$c['es_coalicion']) ? 'background:#FEF2F2' : '' ?>">
                  <td class="mono"><?= htmlspecialchars($c['partido_o_coalicion_codigo']) ?></td>
                  <td>
                    <?php if ($c['es_partido']): ?>
                      <span class="chip success">✓ partido</span>
                    <?php elseif ($c['es_coalicion']): ?>
                      <span class="chip success">✓ coalición</span>
                    <?php else: ?>
                      <span class="chip error">⚠ sin enlace</span>
                    <?php endif; ?>
                  </td>
                  <td style="font-size:12px"><?= htmlspecialchars($c['candidatura_propietaria'] ?? '—') ?></td>
                  <td style="font-size:12px"><?= htmlspecialchars($c['candidatura_suplente'] ?? '—') ?></td>
                  <td>
                    <form method="post" style="display:flex;gap:4px">
                      <input type="hidden" name="action" value="remap_candidato">
                      <input type="hidden" name="candidato_id" value="<?= $c['id'] ?>">
                      <select name="nuevo_codigo" class="input" style="font-size:11px;padding:3px 6px;min-width:160px">
                        <option value="">— elegir —</option>
                        <optgroup label="Partidos">
                          <?php foreach ($partidos as $p): ?>
                            <option value="<?= htmlspecialchars($p['siglas']) ?>"
                              <?= $c['partido_o_coalicion_codigo'] === $p['siglas'] ? 'selected' : '' ?>>
                              <?= htmlspecialchars($p['siglas']) ?>
                            </option>
                          <?php endforeach; ?>
                        </optgroup>
                        <?php if (!empty($coaliciones)): ?>
                          <optgroup label="Coaliciones (esta elección)">
                            <?php foreach ($coaliciones as $coa): ?>
                              <option value="<?= htmlspecialchars($coa['codigo']) ?>"
                                <?= $c['partido_o_coalicion_codigo'] === $coa['codigo'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($coa['codigo']) ?>
                              </option>
                            <?php endforeach; ?>
                          </optgroup>
                        <?php endif; ?>
                      </select>
                      <button class="btn-mini" type="submit">↻</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<style>
.btn-mini { font-size:11px; padding:3px 8px; border:1px solid var(--color-border); border-radius:4px; cursor:pointer; background:#F3F4F6; }
.btn-mini:hover { background:#E5E7EB; }
</style>

<?php include __DIR__ . '/../partials/layout_bottom.php'; ?>
