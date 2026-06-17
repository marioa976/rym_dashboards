<?php
declare(strict_types=1);
require_once __DIR__ . '/core/auth.php';
Auth::start();
Auth::logout();
redirect('login.php');
