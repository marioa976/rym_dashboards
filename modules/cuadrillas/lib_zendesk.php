<?php
/**
 * Cuadrillas · reflejo a Zendesk (F5).
 *
 * Cuando una parada (= ticket de Zendesk) se cierra en campo (resuelta /
 * no_resuelta), agrega al ticket un comentario con el resultado y adjunta las
 * evidencias (descargadas de GCS y subidas a Zendesk, para que el agente las
 * vea sin pasar por el portal). Best-effort: cualquier fallo se registra pero
 * NO rompe la actualización de estatus.
 *
 * Config por entorno:
 *   CUADRILLAS_REFLEJAR_ZENDESK = '0'  -> desactiva el reflejo.
 *   CUADRILLAS_ZENDESK_PUBLIC   = '1'  -> comentario público (por defecto: interno).
 */
declare(strict_types=1);

require_once __DIR__ . '/lib_gcs.php';
require_once __DIR__ . '/../zendesk/_zendesk_lib.php';   // solo funciones (zd_sub)

function cz_zendesk_api(): array
{
    $c = require __DIR__ . '/../../config/config.php';
    return $c['modulos']['zendesk']['zendesk_api'] ?? [];
}

function cz_reflejar_activo(array $api): bool
{
    if ((string)getenv('CUADRILLAS_REFLEJAR_ZENDESK') === '0') return false;
    return !empty($api['subdomain']) && !empty($api['user']) && !empty($api['token']);
}

function cz_base(array $api): string { return 'https://' . zd_sub($api) . '.zendesk.com/api/v2'; }

/** Sube bytes a Zendesk Uploads API. Devuelve el token de adjunto o null. */
function cz_upload(array $api, string $filename, string $bytes, string $mime): ?string
{
    $url = cz_base($api) . '/uploads.json?filename=' . rawurlencode($filename);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $bytes,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $api['user'] . ':' . $api['token'],
        CURLOPT_HTTPHEADER     => ['Content-Type: ' . $mime],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $r = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        error_log('[portal][cuadrillas-zd] upload HTTP ' . $code . ' ' . substr((string)$r, 0, 160));
        return null;
    }
    $j = json_decode((string)$r, true);
    return $j['upload']['token'] ?? null;
}

/** PUT de actualización de ticket. Devuelve true si ok. */
function cz_put_ticket(array $api, int $ticketId, array $ticket): bool
{
    $url = cz_base($api) . '/tickets/' . $ticketId . '.json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => json_encode(['ticket' => $ticket], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $api['user'] . ':' . $api['token'],
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $r = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        error_log('[portal][cuadrillas-zd] put ticket ' . $ticketId . ' HTTP ' . $code . ' ' . substr((string)$r, 0, 160));
        return false;
    }
    return true;
}

/**
 * Refleja el cierre de una parada al ticket de Zendesk. Best-effort (no lanza).
 * $op es el operador (con 'id','nombre','cuadrilla').
 */
function cz_reflejar_parada(PDO $pdo, int $paradaId, string $estatus, array $op, ?string $motivo): void
{
    try {
        $api = cz_zendesk_api();
        if (!cz_reflejar_activo($api)) return;
        if (!in_array($estatus, ['resuelta', 'no_resuelta'], true)) return;

        $st = $pdo->prepare(
            "SELECT p.ticket_id, p.orden_id FROM orden_parada p WHERE p.id = ? LIMIT 1");
        $st->execute([$paradaId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p || empty($p['ticket_id'])) return;   // parada sin ticket de Zendesk
        $tid = (int)$p['ticket_id'];

        // Evidencias (hasta 3 recientes) -> subir a Zendesk como adjuntos.
        $ev = $pdo->prepare("SELECT url FROM parada_evidencia WHERE parada_id = ? ORDER BY id DESC LIMIT 3");
        $ev->execute([$paradaId]);
        $uploads = [];
        foreach ($ev->fetchAll(PDO::FETCH_COLUMN) as $objeto) {
            $f = cuad_evid_leer((string)$objeto);
            if (!$f) continue;
            $name = 'evidencia_' . $paradaId . '_' . substr(md5((string)$objeto), 0, 6) . '.' . cuad_evid_ext($f['mime']);
            $tok = cz_upload($api, $name, $f['bytes'], $f['mime']);
            if ($tok) $uploads[] = $tok;
        }

        $label = $estatus === 'resuelta' ? 'RESUELTA' : 'NO RESUELTA';
        $body  = "Actualización de cuadrilla\n"
               . "Resultado: {$label}\n"
               . 'Cuadrilla: ' . ($op['cuadrilla'] ?? '—') . "\n"
               . 'Operador: ' . ($op['nombre'] ?? '—') . "\n"
               . 'Fecha: ' . date('Y-m-d H:i');
        if ($estatus === 'no_resuelta' && $motivo) $body .= "\nMotivo: " . $motivo;
        if ($uploads) $body .= "\nSe adjuntan " . count($uploads) . ' evidencia(s).';

        $publico = (string)getenv('CUADRILLAS_ZENDESK_PUBLIC') === '1';
        $ticket = [
            'comment' => ['body' => $body, 'public' => $publico, 'uploads' => $uploads],
            'additional_tags' => ['cuadrilla_' . $estatus],   // ADD, no reemplaza
        ];
        $ok = cz_put_ticket($api, $tid, $ticket);

        $pdo->prepare(
            "INSERT INTO orden_evento (orden_id, parada_id, operador_id, tipo, detalle)
             VALUES (?,?,?,?,?)"
        )->execute([(int)$p['orden_id'], $paradaId, (int)($op['id'] ?? 0),
                    'zendesk_reflejo',
                    json_encode(['ticket' => $tid, 'estatus' => $estatus, 'adjuntos' => count($uploads), 'ok' => $ok])]);
    } catch (Throwable $e) {
        error_log('[portal][cuadrillas-zd] reflejar parada ' . $paradaId . ': ' . $e->getMessage());
    }
}
