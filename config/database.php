<?php
/**
 * Conexión PDO única (singleton) a MariaDB/MySQL.
 * - Prepared statements reales (emulación desactivada).
 * - Excepciones en errores.
 * - utf8mb4.
 */
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = require __DIR__ . '/config.php';
        $db  = $cfg['db'];

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'], $db['port'], $db['name'], $db['charset']
        );

        try {
            self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            // No exponer credenciales ni stack al usuario final.
            error_log('[DB] ' . $e->getMessage());
            http_response_code(500);
            exit('Error de conexión a la base de datos.');
        }

        return self::$pdo;
    }
}
