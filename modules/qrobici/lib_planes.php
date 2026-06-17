<?php
/**
 * QroBici Analytics — Librería de planes (suscripciones)
 * ------------------------------------------------------------
 * Funciones que leen la vista `planes` y producen los KPIs y
 * series para la nueva sección "Suscripciones y planes" del
 * reporte.
 *
 * Convenciones de los campos PAGADO y PLAN_ACTIVO:
 *   Se aceptan valores 'S'/'N', 'SI'/'NO', 'SÍ'/'NO', 'Y'/'N',
 *   'YES'/'NO', '1'/'0', 'TRUE'/'FALSE'. La normalización es
 *   case-insensitive y tolera acentos y espacios.
 */

require_once __DIR__ . '/db.php';

/* ============================================================
   HELPER — interpretación booleana tolerante
   ============================================================ */

/**
 * Devuelve true cuando $valor representa afirmación en cualquiera
 * de las variantes comunes (S, SI, SÍ, Y, YES, 1, TRUE, V, T).
 * Cualquier otro valor (incluyendo null, '', N, NO, 0, FALSE) → false.
 */
function qrb_es_si($valor): bool
{
    if (is_bool($valor))    { return $valor; }
    if (is_numeric($valor)) { return (int)$valor === 1; }
    $v = strtoupper(trim((string)$valor));
    // quita acentos para tolerar 'SÍ'
    $v = strtr($v, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U']);
    return in_array($v, ['S', 'SI', 'Y', 'YES', 'TRUE', '1', 'V', 'T'], true);
}

/* ============================================================
   CARGA
   ============================================================ */

function qrb_carga_planes(array $cfg): array
{
    $pdo = qrb_db();
    $vista = $cfg['vistas']['planes'];

    // filtramos por PLAN_CREA cuando hay rango de fechas configurado
    [$wfe, $params] = qrb_where_fecha('PLAN_CREA', $cfg['fecha_desde'], $cfg['fecha_hasta']);
    $sql = "SELECT USUARIO_ID, PLAN_SEC, USUARIO_NOMBRE,
                   PLAN, PLAN_CREA, PLAN_INICIO, PLAN_FIN,
                   PAGADO, PLAN_ACTIVO
            FROM `$vista`
            WHERE 1=1 $wfe
            ORDER BY PLAN_CREA ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/* ============================================================
   NORMALIZACIÓN
   ============================================================ */

function qrb_normaliza_planes(array &$planes): void
{
    $hoy = time();
    foreach ($planes as &$p) {
        $p['TS_CREA']    = $p['PLAN_CREA']    ? strtotime($p['PLAN_CREA'])    : null;
        $p['TS_INICIO']  = $p['PLAN_INICIO']  ? strtotime($p['PLAN_INICIO'])  : null;
        $p['TS_FIN']     = $p['PLAN_FIN']     ? strtotime($p['PLAN_FIN'])     : null;
        $p['ES_PAGADO']  = qrb_es_si($p['PAGADO']      ?? '');
        $p['ES_ACTIVO']  = qrb_es_si($p['PLAN_ACTIVO'] ?? '');
        // vigencia real: activo Y dentro del rango [inicio, fin] respecto a hoy
        $p['VIGENTE'] = $p['ES_ACTIVO']
            && ($p['TS_INICIO'] === null || $p['TS_INICIO'] <= $hoy)
            && ($p['TS_FIN']    === null || $p['TS_FIN']    >= $hoy);
        // duración del plan en días (si tiene ambas fechas)
        $p['DIAS'] = ($p['TS_INICIO'] && $p['TS_FIN'])
            ? max(0, (int)round(($p['TS_FIN'] - $p['TS_INICIO']) / 86400))
            : null;
    }
    unset($p);
}

/* ============================================================
   KPIs DE PLANES
   ============================================================ */

function qrb_kpis_planes(array $planes): array
{
    $n = count($planes);
    if ($n === 0) {
        return [
            'total_planes' => 0, 'usuarios_con_plan' => 0,
            'planes_vigentes' => 0, 'planes_pagados' => 0,
            'tasa_pago' => 0, 'tasa_vigencia' => 0,
            'tipos_distintos' => 0, 'duracion_prom_dias' => 0,
            'renovaciones' => 0, 'pct_renovacion' => 0,
        ];
    }

    $usuarios = [];
    $pagados  = 0;
    $vigentes = 0;
    $tipos    = [];
    $dias     = [];
    $planes_por_user = [];

    foreach ($planes as $p) {
        $usuarios[$p['USUARIO_ID']] = true;
        $planes_por_user[$p['USUARIO_ID']] = ($planes_por_user[$p['USUARIO_ID']] ?? 0) + 1;
        if ($p['ES_PAGADO'])  { $pagados++; }
        if ($p['VIGENTE'])    { $vigentes++; }
        $tipo = $p['PLAN'] ?: 'Sin tipo';
        $tipos[$tipo] = true;
        if ($p['DIAS'] !== null) { $dias[] = $p['DIAS']; }
    }

    // renovaciones = usuarios con más de 1 plan en el periodo
    $renov = 0;
    foreach ($planes_por_user as $c) { if ($c > 1) { $renov++; } }

    return [
        'total_planes'        => $n,
        'usuarios_con_plan'   => count($usuarios),
        'planes_vigentes'     => $vigentes,
        'planes_pagados'      => $pagados,
        'tasa_pago'           => round(100 * $pagados / $n, 1),
        'tasa_vigencia'       => round(100 * $vigentes / $n, 1),
        'tipos_distintos'     => count($tipos),
        'duracion_prom_dias'  => count($dias) ? round(array_sum($dias) / count($dias), 1) : 0,
        'renovaciones'        => $renov,
        'pct_renovacion'      => round(100 * $renov / max(1, count($usuarios)), 1),
    ];
}

/* ============================================================
   DISTRIBUCIONES
   ============================================================ */

function qrb_planes_por_tipo(array $planes): array
{
    $tipo = [];
    foreach ($planes as $p) {
        $t = $p['PLAN'] ?: 'Sin tipo';
        if (!isset($tipo[$t])) {
            $tipo[$t] = ['plan' => $t, 'total' => 0, 'pagados' => 0,
                          'vigentes' => 0, 'usuarios' => []];
        }
        $tipo[$t]['total']++;
        if ($p['ES_PAGADO']) { $tipo[$t]['pagados']++; }
        if ($p['VIGENTE'])   { $tipo[$t]['vigentes']++; }
        $tipo[$t]['usuarios'][$p['USUARIO_ID']] = true;
    }
    $out = [];
    foreach ($tipo as $t) {
        $out[] = [
            'plan'       => $t['plan'],
            'total'      => $t['total'],
            'pagados'    => $t['pagados'],
            'vigentes'   => $t['vigentes'],
            'usuarios'   => count($t['usuarios']),
            'tasa_pago'  => $t['total'] ? round(100 * $t['pagados'] / $t['total'], 1) : 0,
        ];
    }
    usort($out, fn($a, $b) => $b['total'] <=> $a['total']);
    return $out;
}

function qrb_estado_plan(array $planes): array
{
    $out = ['Vigentes' => 0, 'Caducados' => 0, 'No iniciados' => 0, 'Inactivos' => 0];
    $hoy = time();
    foreach ($planes as $p) {
        if (!$p['ES_ACTIVO'])                                       { $out['Inactivos']++; }
        elseif ($p['TS_INICIO'] !== null && $p['TS_INICIO'] > $hoy) { $out['No iniciados']++; }
        elseif ($p['TS_FIN']    !== null && $p['TS_FIN']    < $hoy) { $out['Caducados']++; }
        else                                                        { $out['Vigentes']++; }
    }
    return $out;
}

function qrb_pago_dist(array $planes): array
{
    $pag = 0; $no = 0;
    foreach ($planes as $p) {
        if ($p['ES_PAGADO']) { $pag++; } else { $no++; }
    }
    return ['Pagados' => $pag, 'No pagados' => $no];
}

/* ============================================================
   SERIES TEMPORALES DE PLANES
   ============================================================ */

function qrb_planes_serie_dia(array $planes): array
{
    $por_dia = [];
    foreach ($planes as $p) {
        if (!$p['TS_CREA']) { continue; }
        $f = date('Y-m-d', $p['TS_CREA']);
        if (!isset($por_dia[$f])) { $por_dia[$f] = ['altas' => 0, 'pagados' => 0]; }
        $por_dia[$f]['altas']++;
        if ($p['ES_PAGADO']) { $por_dia[$f]['pagados']++; }
    }
    ksort($por_dia);
    $out = [];
    $dias_sem = ['Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mié','Thu'=>'Jue',
                 'Fri'=>'Vie','Sat'=>'Sáb','Sun'=>'Dom'];
    foreach ($por_dia as $f => $d) {
        $dn = $dias_sem[date('D', strtotime($f))] ?? '';
        $out[] = [
            'fecha'   => $f,
            'dia'     => $dn . ' ' . date('d', strtotime($f)),
            'altas'   => $d['altas'],
            'pagados' => $d['pagados'],
        ];
    }
    return $out;
}

function qrb_planes_caducidad_proxima(array $planes, int $dias_ventana = 7): array
{
    $hoy = time();
    $limite = $hoy + ($dias_ventana * 86400);
    $out = [];
    foreach ($planes as $p) {
        if (!$p['VIGENTE'] || !$p['TS_FIN']) { continue; }
        if ($p['TS_FIN'] >= $hoy && $p['TS_FIN'] <= $limite) {
            $out[] = [
                'usuario_id' => $p['USUARIO_ID'],
                'nombre'     => $p['USUARIO_NOMBRE'] ?: ('Usuario ' . $p['USUARIO_ID']),
                'plan'       => $p['PLAN'],
                'vence'      => date('d/m/Y', $p['TS_FIN']),
                'dias'       => max(0, (int)ceil(($p['TS_FIN'] - $hoy) / 86400)),
            ];
        }
    }
    usort($out, fn($a, $b) => $a['dias'] <=> $b['dias']);
    return array_slice($out, 0, 20);
}

/* ============================================================
   CROSS-VISTA: VIAJES AGRUPADOS POR TIPO DE PLAN
   ============================================================ */

/**
 * Para cada viaje del periodo, busca el plan vigente del usuario
 * en la fecha del viaje y devuelve el conteo de viajes por tipo
 * de plan. Los viajes de usuarios sin plan vigente que cubra la
 * fecha (cuentas operativas, accesos legacy, demos, etc.) entran
 * en la categoría 'Pruebas'.
 *
 * Usa subquery correlacionada con LIMIT 1 — recomendable que
 * dwh_planes tenga índice (USUARIO_ID, PLAN_INICIO).
 */
function qrb_viajes_por_tipo_plan(array $cfg): array
{
    $pdo  = qrb_db();
    $vv   = $cfg['vistas']['viajes'];
    $vp   = $cfg['vistas']['planes'];

    [$wfe, $params] = qrb_where_fecha('v.FECHA', $cfg['fecha_desde'], $cfg['fecha_hasta']);

    // Subquery: por cada viaje, encuentra el plan más reciente
    // que cubra la fecha del viaje. NULL si no hay coincidencia
    // (esos viajes se catalogan como 'Pruebas').
    $sql = "SELECT COALESCE(plan, 'Pruebas') AS plan,
                   COUNT(*) AS viajes,
                   COUNT(DISTINCT usuario_id) AS usuarios
            FROM (
              SELECT v.USUARIO_ID AS usuario_id,
                     (
                       SELECT p.PLAN
                       FROM `$vp` p
                       WHERE p.USUARIO_ID = v.USUARIO_ID
                         AND p.PLAN_INICIO <= v.FECHA
                         AND COALESCE(p.PLAN_FIN, '2099-12-31') >= v.FECHA
                       ORDER BY p.PLAN_INICIO DESC
                       LIMIT 1
                     ) AS plan
              FROM `$vv` v
              WHERE 1=1 $wfe
            ) sub
            GROUP BY plan
            ORDER BY viajes DESC";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        // si las vistas no permiten subquery (raro), regresamos vacío
        // para no romper el reporte
        return ['vacio' => true, 'motivo' => $e->getMessage()];
    }

    $total = 0;
    foreach ($rows as $r) { $total += (int)$r['viajes']; }

    $out = [];
    foreach ($rows as $r) {
        $n = (int)$r['viajes'];
        $out[] = [
            'plan'     => (string)$r['plan'],
            'viajes'   => $n,
            'usuarios' => (int)$r['usuarios'],
            'pct'      => $total > 0 ? round(100 * $n / $total, 1) : 0,
        ];
    }

    return [
        'vacio'  => empty($out),
        'total'  => $total,
        'filas'  => $out,
    ];
}

/* ============================================================
   ORQUESTADOR
   ============================================================ */

function qrb_construye_dataset_planes(array $cfg): array
{
    $planes = qrb_carga_planes($cfg);
    qrb_normaliza_planes($planes);

    if (empty($planes)) {
        return ['kpis_planes' => ['total_planes' => 0], 'vacio_planes' => true];
    }

    return [
        'kpis_planes'         => qrb_kpis_planes($planes),
        'planes_por_tipo'     => qrb_planes_por_tipo($planes),
        'planes_estado'       => qrb_estado_plan($planes),
        'planes_pago'         => qrb_pago_dist($planes),
        'planes_serie_dia'    => qrb_planes_serie_dia($planes),
        'planes_caducan'      => qrb_planes_caducidad_proxima($planes, 7),
        'viajes_por_plan'     => qrb_viajes_por_tipo_plan($cfg),
    ];
}
