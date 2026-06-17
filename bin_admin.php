<?php
/**
 * Utilidad CLI de administración.
 *  Crear usuario:   php bin_admin.php crear "Nombre" correo@dom.com "Pass" [admin]
 *  Asignar módulo:  php bin_admin.php asignar correo@dom.com dif lector
 *  Quitar módulo:   php bin_admin.php quitar  correo@dom.com dif
 *  Listar:          php bin_admin.php listar
 */
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';

$pdo = Database::conn();
$cmd = $argv[1] ?? '';

switch ($cmd) {
    case 'crear':
        [, , $nombre, $email, $pass] = $argv + [null, null, null, null, null];
        $admin = (($argv[5] ?? '') === 'admin') ? 1 : 0;
        if (!$nombre || !$email || !$pass) { exit("Uso: crear \"Nombre\" correo pass [admin]\n"); }
        $pdo->prepare('INSERT INTO usuarios (nombre,email,password_hash,es_admin,activo) VALUES (?,?,?,?,1)')
            ->execute([$nombre, $email, password_hash($pass, PASSWORD_DEFAULT), $admin]);
        echo "Usuario creado: $email\n";
        break;

    case 'asignar':
        [, , $email, $clave] = $argv + [null, null, null, null];
        $nivel = $argv[4] ?? 'lector';
        $uid = uid($pdo, $email); $mid = mid($pdo, $clave);
        $pdo->prepare('INSERT INTO usuario_modulo (usuario_id,modulo_id,nivel) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE nivel=VALUES(nivel)')
            ->execute([$uid, $mid, $nivel]);
        echo "Asignado $clave ($nivel) a $email\n";
        break;

    case 'quitar':
        [, , $email, $clave] = $argv + [null, null, null, null];
        $pdo->prepare('DELETE FROM usuario_modulo WHERE usuario_id=? AND modulo_id=?')
            ->execute([uid($pdo, $email), mid($pdo, $clave)]);
        echo "Removido $clave de $email\n";
        break;

    case 'listar':
        foreach ($pdo->query('SELECT id,nombre,email,es_admin,activo FROM usuarios ORDER BY id') as $u) {
            $mods = $pdo->prepare('SELECT m.clave,um.nivel FROM usuario_modulo um
                                   JOIN modulos m ON m.id=um.modulo_id WHERE um.usuario_id=?');
            $mods->execute([$u['id']]);
            $lst = array_map(fn($r) => $r['clave'].':'.$r['nivel'], $mods->fetchAll());
            printf("#%d %-22s %-26s admin=%d  [%s]\n",
                $u['id'], $u['nombre'], $u['email'], $u['es_admin'], implode(', ', $lst));
        }
        break;

    default:
        echo "Comandos: crear | asignar | quitar | listar\n";
}

function uid(PDO $p, string $email): int {
    $s = $p->prepare('SELECT id FROM usuarios WHERE email=?'); $s->execute([$email]);
    $id = $s->fetchColumn(); if (!$id) exit("Usuario no encontrado: $email\n"); return (int)$id;
}
function mid(PDO $p, string $clave): int {
    $s = $p->prepare('SELECT id FROM modulos WHERE clave=?'); $s->execute([$clave]);
    $id = $s->fetchColumn(); if (!$id) exit("Módulo no encontrado: $clave\n"); return (int)$id;
}
