<?php
require __DIR__ . '/_boot.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf('modulos.php');
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'guardar': {
                $id     = (int)($_POST['id'] ?? 0);
                $nombre = trim((string)($_POST['nombre'] ?? ''));
                $desc   = trim((string)($_POST['descripcion'] ?? ''));
                $color  = trim((string)($_POST['color'] ?? ''));
                $orden  = (int)($_POST['orden'] ?? 0);
                $ruta   = trim((string)($_POST['ruta'] ?? ''));
                $icono  = trim((string)($_POST['icono'] ?? ''));
                $activo = !empty($_POST['activo']) ? 1 : 0;
                if ($id <= 0 || $nombre === '' || $ruta === '') { flash('err','Datos inválidos.'); admin_redirect('modulos.php'); }
                if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) { flash('err','Color inválido (#RRGGBB).'); admin_redirect('modulos.php?id='.$id); }
                $pdo->prepare("UPDATE modulos SET nombre=?, descripcion=?, color=?, orden=?, ruta=?, icono=?, activo=? WHERE id=?")
                    ->execute([$nombre, $desc, ($color ?: null), $orden, $ruta, ($icono ?: null), $activo, $id]);
                flash('ok', "Módulo «{$nombre}» actualizado.");
                admin_redirect('modulos.php');
            }
            case 'crear': {
                $clave  = strtolower(trim((string)($_POST['clave'] ?? '')));
                $nombre = trim((string)($_POST['nombre'] ?? ''));
                $ruta   = trim((string)($_POST['ruta'] ?? ''));
                $desc   = trim((string)($_POST['descripcion'] ?? ''));
                $color  = trim((string)($_POST['color'] ?? '')) ?: '#254185';
                $orden  = (int)($_POST['orden'] ?? 0);
                if (!preg_match('/^[a-z0-9_]{2,40}$/', $clave) || $nombre === '' || $ruta === '') {
                    flash('err','Clave (a-z0-9_), nombre y ruta son obligatorios.'); admin_redirect('modulos.php');
                }
                $dup = $pdo->prepare("SELECT 1 FROM modulos WHERE clave=?"); $dup->execute([$clave]);
                if ($dup->fetchColumn()) { flash('err','Ya existe un módulo con esa clave.'); admin_redirect('modulos.php'); }
                $pdo->prepare("INSERT INTO modulos (clave,nombre,descripcion,ruta,color,orden,activo) VALUES (?,?,?,?,?,?,1)")
                    ->execute([$clave,$nombre,$desc,$ruta,$color,$orden]);
                flash('ok', "Módulo «{$nombre}» creado.");
                admin_redirect('modulos.php');
            }
            case 'eliminar': {
                $id = (int)($_POST['id'] ?? 0);
                $pdo->prepare("DELETE FROM modulos WHERE id=?")->execute([$id]);
                flash('ok','Módulo eliminado (y sus asignaciones).');
                admin_redirect('modulos.php');
            }
            default: admin_redirect('modulos.php');
        }
    } catch (Throwable $e) { flash('err','Error: '.$e->getMessage()); admin_redirect('modulos.php'); }
}

$editId = (int)($_GET['id'] ?? 0);
$edit = null;
if ($editId > 0) {
    $st = $pdo->prepare("SELECT * FROM modulos WHERE id=?"); $st->execute([$editId]);
    $edit = $st->fetch(PDO::FETCH_ASSOC);
}
$modulos = $pdo->query("
    SELECT m.*, (SELECT COUNT(*) FROM usuario_modulo um WHERE um.modulo_id=m.id) AS usuarios
      FROM modulos m ORDER BY m.orden")->fetchAll(PDO::FETCH_ASSOC);

$adminActive = 'modulos';
$adminTitulo = 'Módulos';
require __DIR__ . '/_head.php';
?>

<?php if ($edit): ?>
  <div class="page-head">
    <a class="muted" href="modulos.php">← Todos los módulos</a>
    <h1 style="margin-top:6px">Editar módulo: <?= e($edit['nombre']) ?></h1>
  </div>
  <form class="adm-form" method="post" action="modulos.php" style="max-width:640px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="guardar">
    <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
    <div class="field"><label>Clave (fija)</label><input class="input" value="<?= e($edit['clave']) ?>" disabled></div>
    <div class="adm-grid">
      <div class="field"><label>Nombre</label><input class="input" name="nombre" value="<?= e($edit['nombre']) ?>" required></div>
      <div class="field"><label>Orden</label><input class="input" type="number" name="orden" value="<?= (int)$edit['orden'] ?>"></div>
    </div>
    <div class="field"><label>Descripción</label><input class="input" name="descripcion" value="<?= e($edit['descripcion']) ?>"></div>
    <div class="field"><label>Ruta de entrada</label><input class="input" name="ruta" value="<?= e($edit['ruta']) ?>" required></div>
    <div class="adm-grid">
      <div class="field"><label>Color</label><input type="color" name="color" value="<?= e($edit['color'] ?: '#254185') ?>" style="width:54px;height:38px;border:1px solid var(--qro-border);border-radius:8px;background:#fff"></div>
      <div class="field"><label>Ícono (texto/emoji)</label><input class="input" name="icono" value="<?= e($edit['icono']) ?>"></div>
      <div class="field" style="justify-content:flex-end"><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="activo" value="1" <?= $edit['activo'] ? 'checked' : '' ?>> Activo</label></div>
    </div>
    <button class="btn btn-primary" style="margin-top:6px">Guardar cambios</button>
  </form>

<?php else: ?>
  <div class="page-head">
    <h1>Catálogo de módulos</h1>
    <p class="text-secondary">Nombre, descripción, color, orden y disponibilidad. La <strong>clave</strong> no se edita (la usan los controles de acceso).</p>
  </div>

  <table class="adm-table" style="margin-bottom:20px">
    <thead><tr><th>Clave</th><th>Nombre</th><th>Ruta</th><th>Color</th><th>Orden</th><th>Estado</th><th>Usuarios</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($modulos as $m): ?>
      <tr>
        <td><span class="chip chip-admin"><?= e($m['clave']) ?></span></td>
        <td><strong><?= e($m['nombre']) ?></strong><br><span class="muted"><?= e($m['descripcion']) ?></span></td>
        <td class="muted" style="font-size:12.5px"><?= e($m['ruta']) ?></td>
        <td><span style="display:inline-block;width:20px;height:20px;border-radius:5px;border:1px solid var(--qro-border);background:<?= e($m['color'] ?: '#254185') ?>"></span></td>
        <td class="muted"><?= (int)$m['orden'] ?></td>
        <td><?= $m['activo'] ? '<span class="badge-on">Activo</span>' : '<span class="badge-off">Oculto</span>' ?></td>
        <td class="muted"><?= (int)$m['usuarios'] ?></td>
        <td style="text-align:right;white-space:nowrap">
          <a class="btn btn-secondary btn-xs" href="modulos.php?id=<?= (int)$m['id'] ?>">Editar</a>
          <form method="post" action="modulos.php" class="inline-form" onsubmit="return confirm('¿Eliminar «<?= e(addslashes($m['nombre'])) ?>» y sus asignaciones?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="eliminar">
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <button class="btn btn-danger btn-xs">✕</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <details class="adm-form">
    <summary style="cursor:pointer;font-weight:700;color:var(--qro-blue-dark)">＋ Nuevo módulo</summary>
    <form method="post" action="modulos.php" style="margin-top:14px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="crear">
      <div class="adm-grid">
        <div class="field"><label>Clave (a-z0-9_)</label><input class="input" name="clave" pattern="[a-z0-9_]{2,40}" required></div>
        <div class="field"><label>Nombre</label><input class="input" name="nombre" required></div>
        <div class="field"><label>Ruta</label><input class="input" name="ruta" placeholder="modules/x/index.php" required></div>
        <div class="field"><label>Descripción</label><input class="input" name="descripcion"></div>
        <div class="field"><label>Color</label><input type="color" name="color" value="#254185" style="width:54px;height:38px;border:1px solid var(--qro-border);border-radius:8px;background:#fff"></div>
        <div class="field"><label>Orden</label><input class="input" type="number" name="orden" value="0"></div>
      </div>
      <button class="btn btn-primary" style="margin-top:8px">Crear módulo</button>
    </form>
  </details>
<?php endif; ?>

<?php require __DIR__ . '/_foot.php'; ?>
