<?php
/**
 * Helper de conexión PDO con manejo de errores amigable.
 * Uso:  $pdo = db();   // singleton
 */

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $cfg = require __DIR__ . '/config.php';
    date_default_timezone_set($cfg['timezone'] ?? 'America/Mexico_City');

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'], $cfg['port'], $cfg['database'], $cfg['charset']
    );

    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ]);
    } catch (PDOException $e) {
        // Mensaje legible cuando la BD aún no existe
        $msg = $e->getMessage();
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "❌ No pude conectar a MySQL: $msg\n");
            fwrite(STDERR, "Revisa MAMP (host {$cfg['host']}, puerto {$cfg['port']}) y que la BD '{$cfg['database']}' exista.\n");
            exit(1);
        }
        http_response_code(500);
        echo "<pre style='font-family:system-ui;padding:30px;color:#b91c1c'>";
        echo "❌ No se pudo conectar a la base de datos.\n\n";
        echo htmlspecialchars($msg) . "\n\n";
        echo "Pasos:\n";
        echo "1. Arranca los servicios en MAMP (Apache + MySQL).\n";
        echo "2. Verifica host/puerto en config.php (default MAMP: 127.0.0.1:8889).\n";
        echo "3. Asegúrate de haber ejecutado schema.sql para crear la BD '{$cfg['database']}'.\n";
        echo "</pre>";
        exit;
    }

    return $pdo;
}

/** Helper rápido para insertar/obtener id de un catálogo. */
function get_or_insert_id(PDO $pdo, string $tabla, string $valor): ?int {
    static $cache = [];
    $valor = trim($valor);
    if ($valor === '' || $valor === ' ') return null;

    $key = $tabla . '|' . $valor;
    if (isset($cache[$key])) return $cache[$key];

    $stmt = $pdo->prepare("SELECT id FROM {$tabla} WHERE nombre = ? LIMIT 1");
    $stmt->execute([$valor]);
    $id = $stmt->fetchColumn();

    if (!$id) {
        $ins = $pdo->prepare("INSERT INTO {$tabla} (nombre) VALUES (?)");
        $ins->execute([$valor]);
        $id = (int)$pdo->lastInsertId();
    }
    return $cache[$key] = (int)$id;
}
