<?php
/**
 * Conexión del módulo electoral — integrada a la BD UNIFICADA del portal (portal_qro).
 * Mantiene los nombres que esperan las páginas: reporteador_config() y reporteador_pdo().
 */
declare(strict_types=1);

function reporteador_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        // Config central del portal (lee variables de entorno / .env).
        $cfg = require __DIR__ . '/../../../config/config.php';
    }
    return $cfg;
}

function reporteador_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $db  = reporteador_config()['db'];                 // misma BD que portal/DIF/Zendesk
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'], (int)$db['port'], $db['name'], $db['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        // El módulo electoral fue escrito asumiendo sql_mode SIN only_full_group_by
        // (sus consultas tienen GROUP BY con columnas no agregadas). Lo replicamos
        // SOLO en esta conexión para no tocar el resto del portal.
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
    ]);
    // El servidor de prod quedó en READ-UNCOMMITTED; forzamos un nivel sano por conexión.
    $pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
    return $pdo;
}
