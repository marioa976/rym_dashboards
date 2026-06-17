<?php
/**
 * Autenticación y manejo de sesión.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

final class Auth
{
    /** Inicia una sesión endurecida. Llamar al inicio de cada request. */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $cfg = require __DIR__ . '/../config/config.php';

        session_name($cfg['session']['nombre']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
        session_start();

        // Expiración por inactividad + rotación periódica del id.
        $ahora    = time();
        $vida     = $cfg['session']['vida_minutos'] * 60;
        $rotar    = $cfg['session']['rotar_minutos'] * 60;

        if (isset($_SESSION['ult_act']) && ($ahora - $_SESSION['ult_act']) > $vida) {
            self::logout();
            return;
        }
        $_SESSION['ult_act'] = $ahora;

        if (!isset($_SESSION['creada'])) {
            $_SESSION['creada'] = $ahora;
        } elseif (($ahora - $_SESSION['creada']) > $rotar) {
            $oldSid = self::sid();
            session_regenerate_id(true);
            $_SESSION['creada'] = $ahora;
            if (!empty($_SESSION['uid'])) self::rekeySesion($oldSid);
        }

        // Auditoría / cierre remoto: valida y refresca la fila de sesión.
        if (!empty($_SESSION['uid'])) {
            self::tocarSesion($cfg);
        }
    }

    /** Hash del id de sesión (clave en la tabla `sesiones`). */
    private static function sid(): string
    {
        return hash('sha256', session_id());
    }

    /** Registra/actualiza la sesión actual en la tabla `sesiones`.
     *  Todo el cálculo de tiempo se hace en SQL (NOW()/DATE_ADD) para NO
     *  depender de la zona horaria de PHP, que varía entre páginas. */
    private static function registrarSesion(int $uid, array $cfg): void
    {
        try {
            $pdo = Database::conn();
            $ip  = ($_SERVER['REMOTE_ADDR'] ?? '') !== '' ? @inet_pton($_SERVER['REMOTE_ADDR']) : null;
            $ua  = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $min = (int)$cfg['session']['vida_minutos'];
            $pdo->prepare(
                'INSERT INTO sesiones (id, usuario_id, ip, user_agent, creada_en, expira_en, revocada)
                 VALUES (:id, :uid, :ip, :ua, NOW(), DATE_ADD(NOW(), INTERVAL :min MINUTE), 0)
                 ON DUPLICATE KEY UPDATE usuario_id=VALUES(usuario_id), ip=VALUES(ip),
                                         user_agent=VALUES(user_agent),
                                         expira_en=DATE_ADD(NOW(), INTERVAL :min2 MINUTE), revocada=0'
            )->execute([':id' => self::sid(), ':uid' => $uid, ':ip' => $ip, ':ua' => $ua, ':min' => $min, ':min2' => $min]);
        } catch (Throwable $e) { /* la auditoría no debe bloquear el login */ }
    }

    /**
     * Solo para auditoría y CIERRE REMOTO. La expiración por inactividad ya
     * la controla start() con $_SESSION['ult_act'] (epoch, inmune a zona
     * horaria), así que aquí NO comparamos tiempos: navegar nunca debe sacar
     * a un usuario válido.
     *  - Fila REVOCADA por un admin  → cierra la sesión.
     *  - Fila inexistente (rotación / sesión previa) → la re-registra.
     *  - En cualquier otro caso → solo refresca expira_en (vía SQL).
     */
    private static function tocarSesion(array $cfg): void
    {
        try {
            $pdo = Database::conn();
            $st  = $pdo->prepare('SELECT revocada FROM sesiones WHERE id = :id LIMIT 1');
            $st->execute([':id' => self::sid()]);
            $row = $st->fetch();

            if (!$row) {                                   // auto-reparar (no cerrar)
                self::registrarSesion((int)$_SESSION['uid'], $cfg);
                return;
            }
            if ((int)$row['revocada'] === 1) {             // cierre remoto desde el admin
                $pdo->prepare('DELETE FROM sesiones WHERE id = :id')->execute([':id' => self::sid()]);
                self::logout();
                return;
            }
            $pdo->prepare('UPDATE sesiones SET expira_en = DATE_ADD(NOW(), INTERVAL :min MINUTE) WHERE id = :id')
                ->execute([':min' => (int)$cfg['session']['vida_minutos'], ':id' => self::sid()]);
        } catch (Throwable $e) { /* tabla/columna ausente u otra falla: no bloquear el acceso */ }
    }

    /** Re-asigna la fila de sesión al nuevo id tras session_regenerate_id. */
    private static function rekeySesion(string $oldSid): void
    {
        try {
            Database::conn()->prepare('UPDATE sesiones SET id = :new WHERE id = :old')
                ->execute([':new' => self::sid(), ':old' => $oldSid]);
        } catch (Throwable $e) { /* no crítico */ }
    }

    /** ¿Hay usuario autenticado? */
    public static function check(): bool
    {
        return !empty($_SESSION['uid']);
    }

    /** Datos del usuario en sesión. */
    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    /**
     * Intenta autenticar. Devuelve [ok, mensaje].
     * Aplica bloqueo por intentos fallidos.
     */
    public static function attempt(string $email, string $password): array
    {
        $cfg = require __DIR__ . '/../config/config.php';
        $pdo = Database::conn();

        $stmt = $pdo->prepare(
            'SELECT id, nombre, email, password_hash, es_admin, activo,
                    intentos_fallidos, bloqueado_hasta
             FROM usuarios WHERE email = :email LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $u = $stmt->fetch();

        // Respuesta genérica para no revelar si el correo existe.
        $generico = [false, 'Credenciales incorrectas.'];

        if (!$u || (int)$u['activo'] !== 1) {
            return $generico;
        }

        if ($u['bloqueado_hasta'] !== null && strtotime($u['bloqueado_hasta']) > time()) {
            return [false, 'Cuenta bloqueada temporalmente. Intenta más tarde.'];
        }

        if (!password_verify($password, $u['password_hash'])) {
            self::registrarFallo($pdo, (int)$u['id'], (int)$u['intentos_fallidos'] + 1, $cfg);
            return $generico;
        }

        // Re-hash si el algoritmo cambió.
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            $nuevo = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE usuarios SET password_hash = :h WHERE id = :id')
                ->execute([':h' => $nuevo, ':id' => $u['id']]);
        }

        // Éxito: limpiar contadores, registrar acceso.
        $pdo->prepare(
            'UPDATE usuarios
             SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_acceso = NOW()
             WHERE id = :id'
        )->execute([':id' => $u['id']]);

        session_regenerate_id(true);
        $_SESSION['uid']    = (int)$u['id'];
        $_SESSION['user']   = [
            'id'       => (int)$u['id'],
            'nombre'   => $u['nombre'],
            'email'    => $u['email'],
            'es_admin' => (int)$u['es_admin'] === 1,
        ];
        $_SESSION['creada'] = time();
        $_SESSION['modulos'] = self::cargarModulos((int)$u['id'], (int)$u['es_admin'] === 1);

        self::registrarSesion((int)$u['id'], $cfg);

        return [true, 'OK'];
    }

    private static function registrarFallo(PDO $pdo, int $id, int $intentos, array $cfg): void
    {
        $bloqueo = null;
        if ($intentos >= $cfg['seguridad']['max_intentos']) {
            $bloqueo = date('Y-m-d H:i:s', time() + $cfg['seguridad']['bloqueo_minutos'] * 60);
            $intentos = 0; // reinicia tras aplicar bloqueo
        }
        $pdo->prepare(
            'UPDATE usuarios SET intentos_fallidos = :i, bloqueado_hasta = :b WHERE id = :id'
        )->execute([':i' => $intentos, ':b' => $bloqueo, ':id' => $id]);
    }

    /** Carga módulos permitidos (admin = todos los activos). */
    public static function cargarModulos(int $uid, bool $esAdmin): array
    {
        $pdo = Database::conn();
        if ($esAdmin) {
            $stmt = $pdo->query(
                "SELECT clave, nombre, descripcion, icono, ruta, color, 'admin' AS nivel
                 FROM modulos WHERE activo = 1 ORDER BY orden"
            );
            return $stmt->fetchAll();
        }
        $stmt = $pdo->prepare(
            'SELECT m.clave, m.nombre, m.descripcion, m.icono, m.ruta, m.color, um.nivel
             FROM usuario_modulo um
             JOIN modulos m ON m.id = um.modulo_id AND m.activo = 1
             WHERE um.usuario_id = :uid
             ORDER BY m.orden'
        );
        $stmt->execute([':uid' => $uid]);
        return $stmt->fetchAll();
    }

    public static function logout(): void
    {
        // Quitar la fila de sesión de la auditoría (si existe).
        if (session_status() === PHP_SESSION_ACTIVE && session_id() !== '') {
            try { Database::conn()->prepare('DELETE FROM sesiones WHERE id = :id')
                    ->execute([':id' => self::sid()]); } catch (Throwable $e) {}
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
