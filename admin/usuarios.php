<?php
require __DIR__ . '/_boot.php';

$yo = (int) Auth::user()['id'];

/* ---- cuántos admins activos hay (para no quedarnos sin admin) ---- */
function admins_activos(PDO $pdo): int {
    return (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE es_admin=1 AND activo=1")->fetchColumn();
}

/* =====================================================================
 *  POST: acciones
 * ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf('usuarios.php');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'crear': {
                $nombre = trim((string)($_POST['nombre'] ?? ''));
                $email  = trim((string)($_POST['email'] ?? ''));
                $pass   = (string)($_POST['password'] ?? '');
                $admin  = !empty($_POST['es_admin']) ? 1 : 0;
                if ($nombre === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
                    flash('err', 'Revisa: nombre, correo válido y contraseña de al menos 8 caracteres.');
                    admin_redirect('usuarios.php');
                }
                $dup = $pdo->prepare("SELECT 1 FROM usuarios WHERE email=?");
                $dup->execute([$email]);
                if ($dup->fetchColumn()) { flash('err', 'Ya existe un usuario con ese correo.'); admin_redirect('usuarios.php'); }

                $pdo->prepare("INSERT INTO usuarios (nombre,email,password_hash,es_admin,activo) VALUES (?,?,?,?,1)")
                    ->execute([$nombre, $email, password_hash($pass, PASSWORD_DEFAULT), $admin]);
                flash('ok', "Usuario «{$nombre}» creado.");
                admin_redirect('usuarios.php?id=' . (int)$pdo->lastInsertId());
            }

            case 'actualizar': {
                $id     = (int)($_POST['id'] ?? 0);
                $nombre = trim((string)($_POST['nombre'] ?? ''));
                $email  = trim((string)($_POST['email'] ?? ''));
                $admin  = !empty($_POST['es_admin']) ? 1 : 0;
                $activo = !empty($_POST['activo']) ? 1 : 0;
                if ($id <= 0 || $nombre === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    flash('err', 'Datos inválidos.'); admin_redirect('usuarios.php');
                }
                // No permitir auto-degradarse ni auto-desactivarse (evita quedar fuera)
                if ($id === $yo && ($admin === 0 || $activo === 0)) {
                    flash('err', 'No puedes quitarte tu propio acceso de administrador ni desactivarte.');
                    admin_redirect('usuarios.php?id=' . $id);
                }
                // No dejar el sistema sin admins
                if ($admin === 0) {
                    $eraAdmin = (int)$pdo->query("SELECT es_admin FROM usuarios WHERE id=" . $id)->fetchColumn();
                    if ($eraAdmin === 1 && admins_activos($pdo) <= 1) {
                        flash('err', 'Debe quedar al menos un administrador activo.');
                        admin_redirect('usuarios.php?id=' . $id);
                    }
                }
                $dup = $pdo->prepare("SELECT 1 FROM usuarios WHERE email=? AND id<>?");
                $dup->execute([$email, $id]);
                if ($dup->fetchColumn()) { flash('err', 'Ese correo ya está en uso.'); admin_redirect('usuarios.php?id=' . $id); }

                $pdo->prepare("UPDATE usuarios SET nombre=?, email=?, es_admin=?, activo=? WHERE id=?")
                    ->execute([$nombre, $email, $admin, $activo, $id]);
                flash('ok', 'Usuario actualizado.');
                admin_redirect('usuarios.php?id=' . $id);
            }

            case 'reset_pass': {
                $id   = (int)($_POST['id'] ?? 0);
                $pass = (string)($_POST['password'] ?? '');
                if ($id <= 0 || strlen($pass) < 8) { flash('err', 'La contraseña debe tener al menos 8 caracteres.'); admin_redirect('usuarios.php?id=' . $id); }
                $pdo->prepare("UPDATE usuarios SET password_hash=?, intentos_fallidos=0, bloqueado_hasta=NULL WHERE id=?")
                    ->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
                flash('ok', 'Contraseña restablecida.');
                admin_redirect('usuarios.php?id=' . $id);
            }

            case 'eliminar': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id === $yo) { flash('err', 'No puedes eliminar tu propia cuenta.'); admin_redirect('usuarios.php'); }
                $esAdmin = (int)$pdo->query("SELECT es_admin FROM usuarios WHERE id=" . $id)->fetchColumn();
                if ($esAdmin === 1 && admins_activos($pdo) <= 1) {
                    flash('err', 'No puedes eliminar al último administrador.'); admin_redirect('usuarios.php');
                }
                $pdo->prepare("DELETE FROM usuarios WHERE id=?")->execute([$id]); // CASCADE limpia módulos/sesiones
                flash('ok', 'Usuario eliminado.');
                admin_redirect('usuarios.php');
            }

            case 'set_modulos': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { flash('err', 'Usuario inválido.'); admin_redirect('usuarios.php'); }
                $mods = $_POST['mod'] ?? [];
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM usuario_modulo WHERE usuario_id=?")->execute([$id]);
                $ins = $pdo->prepare("INSERT INTO usuario_modulo (usuario_id,modulo_id,nivel) VALUES (?,?,?)");
                foreach ($mods as $mid => $data) {
                    if (empty($data['on'])) continue;
                    $nivel = in_array(($data['nivel'] ?? 'lector'), ['lector','editor','admin'], true) ? $data['nivel'] : 'lector';
                    $ins->execute([$id, (int)$mid, $nivel]);
                }
                $pdo->commit();
                flash('ok', 'Módulos y niveles actualizados.');
                admin_redirect('usuarios.php?id=' . $id);
            }

            default:
                flash('err', 'Acción desconocida.');
                admin_redirect('usuarios.php');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('err', 'Error: ' . $e->getMessage());
        admin_redirect('usuarios.php');
    }
}

/* =====================================================================
 *  GET: datos
 * ===================================================================== */
$modulos = $pdo->query("SELECT id, clave, nombre, color FROM modulos ORDER BY orden")->fetchAll(PDO::FETCH_ASSOC);

$usuarios = $pdo->query("
    SELECT u.id, u.nombre, u.email, u.es_admin, u.activo, u.ultimo_acceso,
           GROUP_CONCAT(CONCAT(m.nombre,'::',um.nivel) ORDER BY m.orden SEPARATOR '|') AS mods
      FROM usuarios u
      LEFT JOIN usuario_modulo um ON um.usuario_id = u.id
      LEFT JOIN modulos m ON m.id = um.modulo_id
     GROUP BY u.id
     ORDER BY u.es_admin DESC, u.nombre
")->fetchAll(PDO::FETCH_ASSOC);

$editId = (int)($_GET['id'] ?? 0);
$edit = null; $asignados = [];
if ($editId > 0) {
    $st = $pdo->prepare("SELECT * FROM usuarios WHERE id=?");
    $st->execute([$editId]);
    $edit = $st->fetch(PDO::FETCH_ASSOC);
    if ($edit) {
        $a = $pdo->prepare("SELECT modulo_id, nivel FROM usuario_modulo WHERE usuario_id=?");
        $a->execute([$editId]);
        foreach ($a as $r) $asignados[(int)$r['modulo_id']] = $r['nivel'];
    }
}

$adminActive = 'usuarios';
$adminTitulo = 'Usuarios';
require __DIR__ . '/_head.php';
?>

<?php if ($edit): ?>
  <!-- ====================== EDITAR USUARIO ====================== -->
  <div class="page-head">
    <a class="muted" href="usuarios.php">← Todos los usuarios</a>
    <h1 style="margin-top:6px"><?= e($edit['nombre']) ?></h1>
  </div>

  <div class="adm-grid" style="align-items:start">
    <!-- Datos -->
    <form class="adm-form" method="post" action="usuarios.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="actualizar">
      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <h3>Datos</h3>
      <div class="field"><label>Nombre</label><input class="input" name="nombre" value="<?= e($edit['nombre']) ?>" required></div>
      <div class="field"><label>Correo</label><input class="input" type="email" name="email" value="<?= e($edit['email']) ?>" required></div>
      <label style="display:flex;gap:8px;align-items:center;margin:8px 0">
        <input type="checkbox" name="es_admin" value="1" <?= $edit['es_admin'] ? 'checked' : '' ?>> Administrador (ve todo)
      </label>
      <label style="display:flex;gap:8px;align-items:center;margin:8px 0">
        <input type="checkbox" name="activo" value="1" <?= $edit['activo'] ? 'checked' : '' ?>> Cuenta activa
      </label>
      <button class="btn btn-primary">Guardar cambios</button>
    </form>

    <!-- Contraseña + eliminar -->
    <div style="display:flex;flex-direction:column;gap:14px">
      <form class="adm-form" method="post" action="usuarios.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset_pass">
        <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
        <h3>Restablecer contraseña</h3>
        <div class="field"><label>Nueva contraseña (mín. 8)</label><input class="input" type="text" name="password" minlength="8" required></div>
        <button class="btn btn-secondary">Restablecer</button>
      </form>

      <?php if ((int)$edit['id'] !== $yo): ?>
      <form class="adm-form" method="post" action="usuarios.php" onsubmit="return confirm('¿Eliminar a <?= e(addslashes($edit['nombre'])) ?>? Esta acción no se puede deshacer.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="eliminar">
        <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
        <h3 style="color:var(--qro-danger)">Eliminar usuario</h3>
        <p class="muted">Borra la cuenta y todos sus accesos.</p>
        <button class="btn btn-danger">Eliminar</button>
      </form>
      <?php endif; ?>
    </div>

    <!-- Módulos + niveles -->
    <form class="adm-form" method="post" action="usuarios.php" style="grid-column:1/-1">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="set_modulos">
      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <h3>Acceso a módulos</h3>
      <?php if ($edit['es_admin']): ?>
        <p class="muted">Este usuario es <strong>administrador</strong>: ve todos los módulos automáticamente. La asignación de abajo aplica si dejas de ser admin.</p>
      <?php endif; ?>
      <div class="adm-grid">
        <?php foreach ($modulos as $m): $on = isset($asignados[$m['id']]); $nv = $asignados[$m['id']] ?? 'lector'; ?>
          <div style="border:1px solid var(--qro-border);border-radius:10px;padding:12px">
            <label style="display:flex;gap:8px;align-items:center;font-weight:600">
              <input type="checkbox" name="mod[<?= (int)$m['id'] ?>][on]" value="1" <?= $on ? 'checked' : '' ?>>
              <span class="chip-mod chip"><?= e($m['nombre']) ?></span>
            </label>
            <div class="field" style="margin-top:8px;margin-bottom:0">
              <label>Nivel</label>
              <select class="input" name="mod[<?= (int)$m['id'] ?>][nivel]">
                <?php foreach (['lector'=>'Lector','editor'=>'Editor','admin'=>'Administrador'] as $val=>$lbl): ?>
                  <option value="<?= $val ?>" <?= $nv === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-primary" style="margin-top:14px">Guardar accesos</button>
    </form>
  </div>

<?php else: ?>
  <!-- ====================== LISTA + CREAR ====================== -->
  <div class="page-head">
    <h1>Usuarios</h1>
    <p class="text-secondary">Gestiona cuentas, roles y accesos por módulo.</p>
  </div>

  <details class="adm-form" style="margin-bottom:18px">
    <summary style="cursor:pointer;font-weight:700;color:var(--qro-blue-dark)">＋ Nuevo usuario</summary>
    <form method="post" action="usuarios.php" style="margin-top:14px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="crear">
      <div class="adm-grid">
        <div class="field"><label>Nombre</label><input class="input" name="nombre" required></div>
        <div class="field"><label>Correo</label><input class="input" type="email" name="email" required></div>
        <div class="field"><label>Contraseña (mín. 8)</label><input class="input" type="text" name="password" minlength="8" required></div>
        <div class="field" style="justify-content:flex-end">
          <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="es_admin" value="1"> Administrador</label>
        </div>
      </div>
      <button class="btn btn-primary" style="margin-top:6px">Crear usuario</button>
    </form>
  </details>

  <table class="adm-table">
    <thead><tr><th>Usuario</th><th>Estado</th><th>Módulos</th><th>Último acceso</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($usuarios as $u): ?>
      <tr>
        <td>
          <strong><?= e($u['nombre']) ?></strong><br>
          <span class="muted"><?= e($u['email']) ?></span>
        </td>
        <td>
          <?= $u['activo'] ? '<span class="badge-on">Activo</span>' : '<span class="badge-off">Inactivo</span>' ?>
          <?= $u['es_admin'] ? ' <span class="chip chip-admin">Admin</span>' : '' ?>
        </td>
        <td>
          <?php if ($u['es_admin']): ?>
            <span class="chip chip-admin">Todos (admin)</span>
          <?php elseif ($u['mods']): ?>
            <?php foreach (explode('|', $u['mods']) as $pair): [$mn,$nv] = array_pad(explode('::',$pair),2,''); ?>
              <span class="chip chip-mod"><?= e($mn) ?> · <?= e($nv) ?></span>
            <?php endforeach; ?>
          <?php else: ?>
            <span class="chip chip-off">Sin módulos</span>
          <?php endif; ?>
        </td>
        <td class="muted"><?= $u['ultimo_acceso'] ? e($u['ultimo_acceso']) : '—' ?></td>
        <td style="text-align:right"><a class="btn btn-secondary btn-xs" href="usuarios.php?id=<?= (int)$u['id'] ?>">Editar</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php require __DIR__ . '/_foot.php'; ?>
