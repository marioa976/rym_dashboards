<?php
declare(strict_types=1);
require_once __DIR__ . '/core/auth.php';
Auth::start();

if (Auth::check()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Sesión expirada, vuelve a intentar.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $pass  = (string)($_POST['password'] ?? '');
        if ($email === '' || $pass === '') {
            $error = 'Captura correo y contraseña.';
        } else {
            [$ok, $msg] = Auth::attempt($email, $pass);
            if ($ok) {
                redirect('index.php');
            }
            $error = $msg;
        }
    }
}
$cfg = require __DIR__ . '/config/config.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Acceso — <?= e($cfg['app']['nombre']) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/css/qro.css')) ?>">
</head>
<body class="login-body">
  <main class="login-wrap">
    <div class="login-card">
      <div class="login-brand">
        <img src="<?= e(url('assets/img/logo.png')) ?>" alt="Querétaro con Futuro" class="login-logo">
        <p class="login-sub">Portal de Dashboards</p>
      </div>

      <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" action="<?= e(url('login.php')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="field">
          <label for="email">Correo institucional</label>
          <input class="input" type="email" id="email" name="email"
                 autocomplete="username" required
                 value="<?= e($_POST['email'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="password">Contraseña</label>
          <input class="input" type="password" id="password" name="password"
                 autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary btn-block" type="submit">Ingresar</button>
      </form>
    </div>
    <p class="login-foot">© <?= date('Y') ?> Querétaro con Futuro</p>
  </main>
</body>
</html>
