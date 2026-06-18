<?php
/**
 * Configuración central del portal Querétaro con Futuro.
 *
 * TODOS los valores salen de variables de entorno:
 *  - En local (MAMP): un archivo `.env` en la raíz del proyecto las provee
 *    (NO se sube a git). Así corre idéntico a hoy.
 *  - En la nube (Cloud Run): las variables las define el entorno/Secret Manager.
 *
 * Los secretos (tokens, contraseñas, API keys) NO viven en este archivo, así que
 * es seguro subirlo a GitHub. Los valores no sensibles tienen un default local.
 */
declare(strict_types=1);

// ---- Cargador de .env (solo si el archivo existe; en la nube no estará) ----
if (!function_exists('qro_cargar_env')) {
    function qro_cargar_env(string $archivo): void {
        if (!is_file($archivo)) return;
        foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
            $linea = trim($linea);
            if ($linea === '' || $linea[0] === '#' || !str_contains($linea, '=')) continue;
            [$k, $v] = explode('=', $linea, 2);
            $k = trim($k); $v = trim($v);
            // quita comillas envolventes si las hay
            $len = strlen($v);
            if ($len >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[$len - 1] === $v[0]) {
                $v = substr($v, 1, -1);
            }
            if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
        }
    }
}
if (!function_exists('env_str')) {
    function env_str(string $k, string $def = ''): string { $v = getenv($k); return $v === false ? $def : $v; }
}
if (!function_exists('env_int')) {
    function env_int(string $k, int $def = 0): int { $v = getenv($k); return ($v === false || $v === '') ? $def : (int)$v; }
}

qro_cargar_env(dirname(__DIR__) . '/.env');

// Conexión a la BD unificada del portal (la usan portal, DIF y Zendesk)
$dbPortal = [
    'host'    => env_str('DB_HOST', '127.0.0.1'),
    'port'    => env_int('DB_PORT', 8889),       // MAMP por defecto
    'name'    => env_str('DB_NAME', 'portal_qro'),
    'user'    => env_str('DB_USER', 'root'),
    'pass'    => env_str('DB_PASS', 'root'),      // MAMP por defecto
    'charset' => 'utf8mb4',
];
$gmaps = env_str('GOOGLE_MAPS_API_KEY');
$mapId = env_str('MAP_ID', 'DEMO_MAP_ID') ?: 'DEMO_MAP_ID';  // vacío -> DEMO (los marcadores avanzados exigen un Map ID)

return [
    'app' => [
        'nombre'   => env_str('APP_NOMBRE', 'Portal Querétaro con Futuro'),
        'env'      => env_str('APP_ENV', 'local'),
        'base_url' => env_str('APP_BASE_URL', '/portal'),
        'debug'    => env_str('APP_ENV', 'local') !== 'production',
    ],

    // ---- Base de datos del PORTAL (usuarios/roles/módulos) ----
    'db' => $dbPortal,

    'session' => [
        'nombre'        => 'QRO_PORTAL',
        'vida_minutos'  => env_int('SESSION_VIDA_MIN', 60),
        'rotar_minutos' => env_int('SESSION_ROTAR_MIN', 15),
    ],

    'seguridad' => [
        'max_intentos'    => env_int('SEC_MAX_INTENTOS', 5),
        'bloqueo_minutos' => env_int('SEC_BLOQUEO_MIN', 15),
    ],

    // =================================================================
    //  CREDENCIALES Y SECRETOS DE LOS MÓDULOS (todo desde entorno)
    // =================================================================
    'modulos' => [

        // ---------------- DIF (Padrón de ayudas) ----------------
        'dif' => [
            'db' => $dbPortal,                  // DIF vive en la BD unificada
            'google_maps_api_key' => $gmaps,
            'map_id' => $mapId,
        ],

        // ---------------- Zendesk (Reportes de Servicio) --------
        'zendesk' => [
            'db' => $dbPortal,                  // Zendesk vive en la BD unificada
            'google_maps_api_key' => $gmaps,
            'map_id' => $mapId,
            // ---- API de Zendesk (descarga de tickets en vivo) ----
            'zendesk_api' => [
                'subdomain'   => env_str('ZENDESK_SUBDOMAIN'),
                'user'        => env_str('ZENDESK_USER'),
                'token'       => env_str('ZENDESK_TOKEN'),
                'tag_default' => env_str('ZENDESK_TAG_DEFAULT', 'servicio_recoleccion_tiliches'),
            ],
        ],

        // ---------------- Qrobici (Movilidad) -------------------
        'qrobici' => [
            'db' => [                           // BD remota propia de Qrobici
                'host'    => env_str('QROBICI_DB_HOST', '34.136.63.53'),
                'port'    => env_int('QROBICI_DB_PORT', 3306),
                'name'    => env_str('QROBICI_DB_NAME', 'qrobici'),
                'user'    => env_str('QROBICI_DB_USER'),
                'pass'    => env_str('QROBICI_DB_PASS'),
                'charset' => 'utf8mb4',
            ],
            'google_maps_api_key' => $gmaps,
            'map_id' => $mapId,
            'waze_feed_url' => env_str('WAZE_FEED_URL'),
        ],
    ],
];
