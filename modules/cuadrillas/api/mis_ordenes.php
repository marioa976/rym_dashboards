<?php
/**
 * GET /modules/cuadrillas/api/mis_ordenes.php   (Authorization: Bearer <token>)
 * Query opcional: ?fecha=YYYY-MM-DD  (por defecto: todas las abiertas)
 * -> { ok:true, operador:{...}, ordenes:[ { ...orden, paradas:[...] } ] }
 *
 * Devuelve las órdenes ABIERTAS (despachada|en_proceso) de la cuadrilla del
 * operador, con sus paradas en orden de visita (para pintar la ruta en la app).
 */
declare(strict_types=1);
require __DIR__ . '/_api.php';
api_metodo('GET');

$pdo = cuad_pdo();
cuad_ensure_schema($pdo);
$op = api_operador($pdo);

$resp = [
    'ok' => true,
    'operador' => [
        'id' => (int)$op['id'], 'nombre' => $op['nombre'], 'rol' => $op['rol'],
        'cuadrilla_id' => $op['cuadrilla_id'] ? (int)$op['cuadrilla_id'] : null,
        'cuadrilla' => $op['cuadrilla'],
    ],
    'ordenes' => [],
];

if (!$op['cuadrilla_id']) api_json($resp);   // sin cuadrilla => sin rutas

$fecha = $_GET['fecha'] ?? null;
$sql = "SELECT id, fecha, titulo, estatus, n_paradas, n_resueltas, km
          FROM orden
         WHERE cuadrilla_id = ? AND estatus IN ('despachada','en_proceso')";
$args = [(int)$op['cuadrilla_id']];
if ($fecha !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$fecha)) {
    $sql .= " AND fecha = ?"; $args[] = $fecha;
}
$sql .= " ORDER BY fecha, id";
$st = $pdo->prepare($sql); $st->execute($args);
$ordenes = $st->fetchAll(PDO::FETCH_ASSOC);

if ($ordenes) {
    $ids = implode(',', array_map('intval', array_column($ordenes, 'id')));
    $pr = $pdo->query(
        "SELECT id, orden_id, idx, ticket_id, titulo, direccion, lat, lng, estatus, motivo_no, resuelta_en
           FROM orden_parada WHERE orden_id IN ($ids) ORDER BY orden_id, idx"
    )->fetchAll(PDO::FETCH_ASSOC);
    $evid = cuad_evidencias_por_parada($pdo, array_column($pr, 'id'));
    $porOrden = [];
    foreach ($pr as $p) {
        $pid = (int)$p['id'];
        $porOrden[(int)$p['orden_id']][] = [
            'id'        => $pid,
            'idx'       => (int)$p['idx'],
            'ticket_id' => $p['ticket_id'] !== null ? (int)$p['ticket_id'] : null,
            'titulo'    => $p['titulo'],
            'direccion' => $p['direccion'],
            'lat'       => $p['lat'] !== null ? (float)$p['lat'] : null,
            'lng'       => $p['lng'] !== null ? (float)$p['lng'] : null,
            'estatus'   => $p['estatus'],
            'motivo_no' => $p['motivo_no'],
            'resuelta_en' => $p['resuelta_en'],
            'evidencias' => array_map(fn($e) => [
                'id' => (int)$e['id'], 'tipo' => $e['tipo'],
            ], $evid[$pid] ?? []),
        ];
    }
    foreach ($ordenes as $o) {
        $oid = (int)$o['id'];
        $resp['ordenes'][] = [
            'id'          => $oid,
            'fecha'       => $o['fecha'],
            'titulo'      => $o['titulo'],
            'estatus'     => $o['estatus'],
            'n_paradas'   => (int)$o['n_paradas'],
            'n_resueltas' => (int)$o['n_resueltas'],
            'km'          => (float)$o['km'],
            'paradas'     => $porOrden[$oid] ?? [],
        ];
    }
}

api_json($resp);
