<?php
/**
 * Cuadrillas · almacenamiento de evidencias.
 *
 * En producción (Cloud Run) sube/lee de Google Cloud Storage usando el token del
 * service account (metadata server) — sin SDK ni llave de archivo. El bucket se
 * define en la variable de entorno GCS_EVIDENCIAS_BUCKET y es PRIVADO (la
 * evidencia es PII): se sirve SIEMPRE a través del portal, nunca público.
 *
 * En local (sin bucket/metadata) cae a un directorio temporal, para poder probar
 * el flujo completo sin GCP.
 */
declare(strict_types=1);

function cuad_gcs_bucket(): string { return (string)(getenv('GCS_EVIDENCIAS_BUCKET') ?: ''); }

/** Token de acceso del service account (metadata de Cloud Run). Cacheado. */
function cuad_gcs_token(): ?string
{
    static $cache = null;
    if ($cache !== null && $cache['exp'] > time() + 60) return $cache['token'];

    $ch = curl_init('http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Metadata-Flavor: Google'],
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $r = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$r) return null;
    $j = json_decode((string)$r, true);
    if (!isset($j['access_token'])) return null;
    $cache = ['token' => (string)$j['access_token'], 'exp' => time() + (int)($j['expires_in'] ?? 3000)];
    return $cache['token'];
}

/** ¿Está configurado GCS y accesible el token? */
function cuad_gcs_activo(): bool { return cuad_gcs_bucket() !== '' && cuad_gcs_token() !== null; }

/** Directorio local de respaldo (solo desarrollo). */
function cuad_evid_dir_local(): string
{
    $d = sys_get_temp_dir() . '/cuad_evidencias';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}

/**
 * Guarda los bytes de una evidencia bajo la clave $objeto. Devuelve true si ok.
 * Usa GCS si está activo; si no, el directorio temporal local.
 */
function cuad_evid_guardar(string $objeto, string $bytes, string $mime): bool
{
    if (cuad_gcs_activo()) {
        $bucket = cuad_gcs_bucket();
        $token  = cuad_gcs_token();
        $url = 'https://storage.googleapis.com/upload/storage/v1/b/' . rawurlencode($bucket)
             . '/o?uploadType=media&name=' . rawurlencode($objeto);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bytes,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: ' . $mime],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $r = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300) return true;
        error_log('[portal][cuadrillas-gcs] upload HTTP ' . $code . ' ' . substr((string)$r, 0, 200));
        return false;
    }
    // Fallback local
    $path = cuad_evid_dir_local() . '/' . str_replace('/', '__', $objeto);
    return @file_put_contents($path, $bytes) !== false;
}

/** Lee una evidencia. Devuelve ['bytes','mime'] o null. */
function cuad_evid_leer(string $objeto): ?array
{
    if (cuad_gcs_activo()) {
        $bucket = cuad_gcs_bucket();
        $token  = cuad_gcs_token();
        $url = 'https://storage.googleapis.com/storage/v1/b/' . rawurlencode($bucket)
             . '/o/' . rawurlencode($objeto) . '?alt=media';
        $mime = null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$mime) {
                if (stripos($h, 'Content-Type:') === 0) $mime = trim(substr($h, 13));
                return strlen($h);
            },
        ]);
        $r = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) return null;
        return ['bytes' => (string)$r, 'mime' => $mime ?: 'application/octet-stream'];
    }
    // Fallback local
    $path = cuad_evid_dir_local() . '/' . str_replace('/', '__', $objeto);
    if (!is_file($path)) return null;
    $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'image/jpeg') : 'image/jpeg';
    return ['bytes' => (string)file_get_contents($path), 'mime' => $mime];
}

/** Extensión segura a partir del mime de imagen. */
function cuad_evid_ext(string $mime): string
{
    return [
        'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png',
        'image/webp' => 'webp', 'image/heic' => 'heic',
    ][$mime] ?? 'jpg';
}
