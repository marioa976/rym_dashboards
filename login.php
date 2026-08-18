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
<html class="h-full" data-kt-theme="true" data-kt-theme-mode="light" dir="ltr" lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Acceso — <?= e($cfg['app']['nombre']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="<?= e(url('assets/metronic/vendors/keenicons/styles.bundle.css')) ?>" rel="stylesheet">
<link href="<?= e(url('assets/metronic/css/styles.css')) ?>" rel="stylesheet">
</head>
<body class="antialiased flex h-full text-base text-foreground bg-muted">
  <main class="grow flex flex-col items-center justify-center p-6">
    <div class="kt-card w-full max-w-[400px] shadow-lg">
      <div class="kt-card-content flex flex-col gap-5 p-8 lg:p-10">
        <div class="flex flex-col items-center gap-3 mb-2">
          <img src="<?= e(url('assets/img/logo.png')) ?>" alt="Querétaro con Futuro" class="h-14 w-auto">
          <span class="text-sm font-medium text-secondary-foreground">Portal de Dashboards</span>
        </div>

        <?php if ($error !== ''): ?>
          <div class="flex items-center gap-2 rounded-lg border border-destructive/30 bg-destructive/10 text-destructive px-3.5 py-2.5 text-sm" role="alert">
            <i class="ki-filled ki-information-2 text-base"></i>
            <span><?= e($error) ?></span>
          </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('login.php')) ?>" class="flex flex-col gap-4" novalidate>
          <?= csrf_field() ?>
          <div class="flex flex-col gap-1.5">
            <label class="text-sm font-medium text-foreground" for="email">Correo institucional</label>
            <input class="kt-input" type="email" id="email" name="email"
                   autocomplete="username" required placeholder="correo@municipiodequeretaro.gob.mx"
                   value="<?= e($_POST['email'] ?? '') ?>">
          </div>
          <div class="flex flex-col gap-1.5">
            <label class="text-sm font-medium text-foreground" for="password">Contraseña</label>
            <input class="kt-input" type="password" id="password" name="password"
                   autocomplete="current-password" required placeholder="••••••••">
          </div>
          <button class="kt-btn kt-btn-primary justify-center w-full mt-1" type="submit">Ingresar</button>
        </form>
      </div>
    </div>
    <p class="text-xs text-muted-foreground mt-5">© <?= date('Y') ?> Querétaro con Futuro</p>
  </main>
</body>
</html>
