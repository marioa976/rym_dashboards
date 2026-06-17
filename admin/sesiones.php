<?php
require __DIR__ . '/_boot.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf('sesiones.php');
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'cerrar') {
            $sid = (string)($_POST['sid'] ?? '');
            if ($sid !== '') {
                // Marcar revocada: en su próxima acción el portal cerrará esa sesión.
                $pdo->prepare("UPDATE sesiones SET revocada=1 WHERE id=?")->execute([$sid]);
                flash('ok', 'Sesión revocada. Se cerrará en su próxima acción.');
            }
        } elseif ($action === 'purgar') {
            $pdo->query("DELETE FROM sesiones WHERE expira_en <= NOW() OR revocada=1");
            flash('ok', 'Sesiones expiradas/revocadas purgadas.');
        }
    } catch (Throwable $e) { flash('err','Error: '.$e->getMessage()); }
    admin_redirect('sesiones.php');
}

$tabla_ok = true; $sesiones = [];
try {
    $sesiones = $pdo->query("
        SELECT s.id, s.usuario_id, INET6_NTOA(s.ip) AS ip, s.user_agent, s.creada_en, s.expira_en, s.revocada,
               (s.expira_en > NOW() AND s.revocada = 0) AS activa, u.nombre, u.email
          FROM sesiones s JOIN usuarios u ON u.id = s.usuario_id
         ORDER BY s.expira_en DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $tabla_ok = false; }

$miSid = hash('sha256', session_id());

$adminActive = 'sesiones';
$adminTitulo = 'Sesiones';
require __DIR__ . '/_head.php';
?>
<div class="page-head">
  <h1>Sesiones y auditoría</h1>
  <p class="text-secondary">Sesiones registradas en el portal. Puedes cerrar cualquiera de forma remota.</p>
</div>

<?php if (!$tabla_ok): ?>
  <div class="card"><p>La tabla <code>sesiones</code> no está disponible. Impórtala desde <code>sql/schema.sql</code>.</p></div>
<?php else: ?>
  <div class="admin-tools">
    <form method="post" action="sesiones.php" class="inline-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="purgar">
      <button class="btn btn-secondary btn-sm">Purgar expiradas</button>
    </form>
    <span class="muted"><?= count($sesiones) ?> registro(s)</span>
  </div>

  <table class="adm-table">
    <thead><tr><th>Usuario</th><th>Estado</th><th>IP</th><th>Navegador</th><th>Inicio</th><th>Expira</th><th></th></tr></thead>
    <tbody>
      <?php if (!$sesiones): ?>
        <tr><td colspan="7" class="muted" style="text-align:center;padding:20px">Sin sesiones registradas.</td></tr>
      <?php endif; ?>
      <?php foreach ($sesiones as $s): $mia = hash_equals($miSid, $s['id']); ?>
      <tr>
        <td><strong><?= e($s['nombre']) ?></strong><br><span class="muted" style="font-size:12px"><?= e($s['email']) ?></span></td>
        <td><?= $s['activa'] ? '<span class="badge-on">Activa</span>' : '<span class="badge-off">Expirada</span>' ?><?= $mia ? ' <span class="chip chip-mod">esta sesión</span>' : '' ?></td>
        <td class="muted"><?= e($s['ip'] ?: '—') ?></td>
        <td class="muted" style="font-size:11.5px;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($s['user_agent'] ?: '—') ?></td>
        <td class="muted" style="font-size:12px"><?= e($s['creada_en']) ?></td>
        <td class="muted" style="font-size:12px"><?= e($s['expira_en']) ?></td>
        <td style="text-align:right">
          <?php if (!$mia): ?>
          <form method="post" action="sesiones.php" class="inline-form" onsubmit="return confirm('¿Cerrar esta sesión de <?= e(addslashes($s['nombre'])) ?>?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cerrar">
            <input type="hidden" name="sid" value="<?= e($s['id']) ?>">
            <button class="btn btn-danger btn-xs">Cerrar</button>
          </form>
          <?php else: ?><span class="muted">—</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php require __DIR__ . '/_foot.php'; ?>
