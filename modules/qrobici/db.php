<?php
/**
 * QroBici Analytics — Conexión a base de datos
 * ------------------------------------------------------------
 * Devuelve una instancia única (singleton) de PDO usando la
 * configuración cargada desde config.php.
 */

function qrb_db(array $cfg = null): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if ($cfg === null) {
        throw new RuntimeException('qrb_db(): la primera llamada requiere el array de config.');
    }

    $d = $cfg['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $d['host'], $d['port'], $d['name'], $d['charset']
    );

    try {
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . date('P') . "', sql_mode = ''",
        ]);
    } catch (PDOException $e) {
        if (!empty($cfg['debug'])) {
            throw $e;
        }
        http_response_code(500);
        die('Error de conexión a la base de datos. Revisa config.php');
    }

    return $pdo;
}

/**
 * Helper para construir el fragmento WHERE de fecha sobre una columna.
 * Devuelve [sql, params].
 */
function qrb_where_fecha(string $columna, ?string $desde, ?string $hasta): array
{
    $sql = '';
    $params = [];
    if ($desde) { $sql .= " AND $columna >= :fdesde"; $params[':fdesde'] = $desde; }
    if ($hasta) { $sql .= " AND $columna <= :fhasta"; $params[':fhasta'] = $hasta; }
    return [$sql, $params];
}
