<?php
require __DIR__ . '/_boot.php';

$tot      = (int)$pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
$activos  = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE activo=1")->fetchColumn();
$admins   = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE es_admin=1 AND activo=1")->fetchColumn();
$bloq     = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE bloqueado_hasta IS NOT NULL AND bloqueado_hasta > NOW()")->fetchColumn();
$sinMod   = (int)$pdo->query("SELECT COUNT(*) FROM usuarios u WHERE u.es_admin=0 AND NOT EXISTS (SELECT 1 FROM usuario_modulo um WHERE um.usuario_id=u.id)")->fetchColumn();
try { $sesAct = (int)$pdo->query("SELECT COUNT(*) FROM sesiones WHERE expira_en > NOW()")->fetchColumn(); } catch (Throwable $e) { $sesAct = 0; }

$porModulo = $pdo->query("
    SELECT m.nombre, m.color, m.activo,
           (SELECT COUNT(*) FROM usuario_modulo um WHERE um.modulo_id=m.id) AS n
      FROM modulos m ORDER BY m.orden")->fetchAll(PDO::FETCH_ASSOC);

$recientes = $pdo->query("
    SELECT nombre, email, ultimo_acceso, es_admin
      FROM usuarios WHERE ultimo_acceso IS NOT NULL
     ORDER BY ultimo_acceso DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

$adminActive = 'index';
$adminTitulo = 'Panel';
require __DIR__ . '/_head.php';

function kpi($lbl,$val,$color='var(--qro-blue-dark)'){
  return '<div class="card kpi"><div class="kpi-label">'.e($lbl).'</div><div class="kpi-value" style="color:'.$color.'">'.e((string)$val).'</div></div>';
}
?>
<div class="page-head">
  <h1>Panel de administración</h1>
  <p class="text-secondary">Resumen de cuentas, accesos y actividad del portal.</p>
</div>

<div class="kpi-grid">
  <?= kpi('Usuarios', $tot) ?>
  <?= kpi('Activos', $activos, 'var(--qro-success)') ?>
  <?= kpi('Administradores', $admins) ?>
  <?= kpi('Bloqueados', $bloq, $bloq>0?'var(--qro-danger)':'var(--qro-text-muted)') ?>
  <?= kpi('Sin módulos', $sinMod, $sinMod>0?'var(--qro-warning)':'var(--qro-text-muted)') ?>
  <?= kpi('Sesiones activas', $sesAct, 'var(--qro-blue)') ?>
</div>

<div class="adm-grid" style="margin-top:18px;align-items:start">
  <div class="card">
    <h3>Accesos por módulo</h3>
    <table style="width:100%;border-collapse:collapse;margin-top:8px">
      <?php foreach ($porModulo as $m): ?>
      <tr>
        <td style="padding:7px 0"><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:<?= e($m['color'] ?: '#254185') ?>;margin-right:8px"></span><?= e($m['nombre']) ?>
          <?= $m['activo'] ? '' : ' <span class="chip chip-off">oculto</span>' ?></td>
        <td style="text-align:right;font-weight:700;color:var(--qro-blue-dark)"><?= (int)$m['n'] ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <a class="btn btn-secondary btn-sm" href="usuarios.php" style="margin-top:12px">Gestionar usuarios</a>
  </div>

  <div class="card">
    <h3>Últimos accesos</h3>
    <table style="width:100%;border-collapse:collapse;margin-top:8px">
      <?php if (!$recientes): ?><tr><td class="muted" style="padding:8px 0">Sin accesos registrados.</td></tr><?php endif; ?>
      <?php foreach ($recientes as $r): ?>
      <tr>
        <td style="padding:7px 0"><strong><?= e($r['nombre']) ?></strong><?= $r['es_admin']?' <span class="chip chip-admin">admin</span>':'' ?><br><span class="muted" style="font-size:12px"><?= e($r['email']) ?></span></td>
        <td style="text-align:right" class="muted"><?= e($r['ultimo_acceso']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <a class="btn btn-secondary btn-sm" href="sesiones.php" style="margin-top:12px">Ver sesiones</a>
  </div>
</div>

<?php require __DIR__ . '/_foot.php'; ?>
