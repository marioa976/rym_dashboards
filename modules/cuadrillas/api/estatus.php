<?php
/**
 * POST /modules/cuadrillas/api/estatus.php   (Authorization: Bearer <token>)
 * Body JSON: { parada_id, estatus, motivo?, lat?, lng? }
 *   estatus ∈ pendiente | en_camino | en_sitio | resuelta | no_resuelta
 *   motivo: obligatorio si estatus = no_resuelta
 * -> { ok:true, parada:{...}, orden:{ id, estatus, n_resueltas, n_paradas } }
 *
 * Verifica que la parada pertenezca a una orden de la cuadrilla del operador,
 * actualiza su estatus, registra el evento en la bitácora y recalcula la orden.
 */
declare(strict_types=1);
require __DIR__ . '/_api.php';
require_once __DIR__ . '/../lib_zendesk.php';
api_metodo('POST');

$pdo = cuad_pdo();
cuad_ensure_schema($pdo);
$op = api_operador($pdo);

$b        = api_body();
$paradaId = (int)($b['parada_id'] ?? 0);
$nuevo    = (string)($b['estatus'] ?? '');
$motivo   = trim((string)($b['motivo'] ?? ''));
$lat      = isset($b['lat']) && $b['lat'] !== '' ? (float)$b['lat'] : null;
$lng      = isset($b['lng']) && $b['lng'] !== '' ? (float)$b['lng'] : null;

$validos = ['pendiente','en_camino','en_sitio','resuelta','no_resuelta'];
if ($paradaId <= 0)                     api_error('Falta parada_id.', 400);
if (!in_array($nuevo, $validos, true))  api_error('Estatus inválido.', 400);
if ($nuevo === 'no_resuelta' && $motivo === '') api_error('Indica el motivo por el que no se resolvió.', 400);
if (!$op['cuadrilla_id'])               api_error('No tienes una cuadrilla asignada.', 403);

// La parada debe ser de una orden de la cuadrilla del operador.
$st = $pdo->prepare(
    "SELECT p.id, p.orden_id, p.estatus
       FROM orden_parada p JOIN orden o ON o.id = p.orden_id
      WHERE p.id = ? AND o.cuadrilla_id = ? LIMIT 1");
$st->execute([$paradaId, (int)$op['cuadrilla_id']]);
$par = $st->fetch(PDO::FETCH_ASSOC);
if (!$par) api_error('La parada no existe o no es de tu cuadrilla.', 404);

$ordenId = (int)$par['orden_id'];

$pdo->beginTransaction();
try {
    // Actualiza la parada
    if ($nuevo === 'resuelta') {
        $pdo->prepare("UPDATE orden_parada SET estatus='resuelta', motivo_no=NULL, resuelta_en=NOW() WHERE id=?")
            ->execute([$paradaId]);
    } elseif ($nuevo === 'no_resuelta') {
        $pdo->prepare("UPDATE orden_parada SET estatus='no_resuelta', motivo_no=?, resuelta_en=NOW() WHERE id=?")
            ->execute([mb_substr($motivo, 0, 255), $paradaId]);
    } else {
        $pdo->prepare("UPDATE orden_parada SET estatus=?, resuelta_en=NULL WHERE id=?")
            ->execute([$nuevo, $paradaId]);
    }

    // Bitácora
    $pdo->prepare("INSERT INTO orden_evento (orden_id, parada_id, operador_id, tipo, detalle, lat, lng) VALUES (?,?,?,?,?,?,?)")
        ->execute([$ordenId, $paradaId, (int)$op['id'], $nuevo,
                   json_encode(['de' => $par['estatus'], 'a' => $nuevo] + ($motivo !== '' ? ['motivo' => $motivo] : [])),
                   $lat, $lng]);

    // Recalcula estatus/contadores de la orden
    $recalc = cuad_recalc_orden($pdo, $ordenId);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[portal][cuadrillas-api] estatus: ' . $e->getMessage());
    api_error('No se pudo guardar el cambio. Intenta de nuevo.', 500);
}

// Reflejo a Zendesk (best-effort) al CERRAR una parada que viene de un ticket.
// Solo si cambió a un estado terminal distinto del anterior.
if (in_array($nuevo, ['resuelta', 'no_resuelta'], true) && $par['estatus'] !== $nuevo) {
    cz_reflejar_parada($pdo, $paradaId, $nuevo, $op, $nuevo === 'no_resuelta' ? $motivo : null);
}

$o = $pdo->prepare("SELECT n_paradas FROM orden WHERE id=?");
$o->execute([$ordenId]);
$nParadas = (int)$o->fetchColumn();

api_json([
    'ok' => true,
    'parada' => ['id' => $paradaId, 'estatus' => $nuevo, 'motivo' => $nuevo === 'no_resuelta' ? $motivo : null],
    'orden'  => ['id' => $ordenId, 'estatus' => $recalc['estatus'], 'n_resueltas' => $recalc['n_resueltas'], 'n_paradas' => $nParadas],
]);
