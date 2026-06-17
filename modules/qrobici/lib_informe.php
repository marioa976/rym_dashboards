<?php
/**
 * QroBici Analytics — Librería del informe ejecutivo
 * ------------------------------------------------------------
 * Toma los datasets crudos producidos por lib_viajes.php y
 * lib_planes.php, calcula:
 *
 *   • Deltas del periodo actual contra el mismo número de días
 *     inmediatamente anteriores (cuando hay fechas en el config).
 *   • Hora pico, día pico, estación con mayor desbalance.
 *   • Recomendaciones data-driven (4-6) derivadas de las métricas.
 *
 * Cada recomendación lleva: prioridad, titular, métrica que la
 * respalda y acción concreta.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib_viajes.php';
require_once __DIR__ . '/lib_planes.php';

/* ============================================================
   COMPARATIVO CONTRA PERIODO ANTERIOR
   ============================================================ */

/**
 * Devuelve un cfg "shifted" cuyo rango es el mismo número de
 * días pero inmediatamente antes del periodo actual.
 * Si no hay fechas en config, intenta derivar el rango a partir
 * de los KPIs (fechas reales de la BD).
 */
function qrb_informe_rango_anterior(array $cfg, array $kpis): ?array
{
    // 1) caso ideal: fechas explícitas en config
    if ($cfg['fecha_desde'] && $cfg['fecha_hasta']) {
        $ts_d = strtotime($cfg['fecha_desde']);
        $ts_h = strtotime($cfg['fecha_hasta']);
    } else {
        // 2) fallback: usar fechas reales de los datos (kpis.fecha_min/max)
        if (empty($kpis['fecha_min']) || empty($kpis['fecha_max'])) { return null; }
        $ts_d = strtotime(str_replace('/', '-', $kpis['fecha_min'])
                         ? strtr($kpis['fecha_min'], ['/' => '-'])
                         : $kpis['fecha_min']);
        // 'd/m/Y' → 'Y-m-d'
        $parts = explode('/', $kpis['fecha_min']);
        if (count($parts) === 3) {
            $ts_d = strtotime("$parts[2]-$parts[1]-$parts[0] 00:00:00");
        }
        $parts = explode('/', $kpis['fecha_max']);
        if (count($parts) === 3) {
            $ts_h = strtotime("$parts[2]-$parts[1]-$parts[0] 23:59:59");
        }
    }
    if (!$ts_d || !$ts_h || $ts_h <= $ts_d) { return null; }

    $delta = $ts_h - $ts_d;
    $anterior_h = $ts_d - 1;          // un segundo antes
    $anterior_d = $anterior_h - $delta;
    $cfg_prev = $cfg;
    $cfg_prev['fecha_desde'] = date('Y-m-d H:i:s', $anterior_d);
    $cfg_prev['fecha_hasta'] = date('Y-m-d H:i:s', $anterior_h);
    return $cfg_prev;
}

/**
 * Recibe los KPIs del periodo actual y devuelve un array con
 * deltas porcentuales contra el periodo anterior, además de los
 * valores absolutos de referencia.
 */
function qrb_informe_deltas(array $cfg, array $kpis_actual): array
{
    $cfg_prev = qrb_informe_rango_anterior($cfg, $kpis_actual);
    if ($cfg_prev === null) {
        return ['disponible' => false];
    }

    $viajes_prev = qrb_carga_viajes($cfg_prev);
    if (empty($viajes_prev)) {
        return ['disponible' => false];
    }
    qrb_normaliza_viajes($viajes_prev);
    $kpis_prev = qrb_kpis_globales($viajes_prev, $cfg);

    $delta_pct = function ($a, $b) {
        if (!$b) { return null; }
        return round(100 * ($a - $b) / $b, 1);
    };

    return [
        'disponible'     => true,
        'rango_prev'     => $kpis_prev['fecha_min'] . ' a ' . $kpis_prev['fecha_max'],
        'viajes_prev'    => (int)($kpis_prev['total_viajes'] ?? 0),
        'usuarios_prev'  => (int)($kpis_prev['usuarios_unicos'] ?? 0),
        'km_prev'        => (float)($kpis_prev['dist_total_km'] ?? 0),
        'd_viajes'       => $delta_pct($kpis_actual['total_viajes'], $kpis_prev['total_viajes'] ?? 0),
        'd_usuarios'     => $delta_pct($kpis_actual['usuarios_unicos'], $kpis_prev['usuarios_unicos'] ?? 0),
        'd_km'           => $delta_pct($kpis_actual['dist_total_km'], $kpis_prev['dist_total_km'] ?? 0),
        'd_dur_prom'     => $delta_pct($kpis_actual['dur_prom_min'], $kpis_prev['dur_prom_min'] ?? 0),
    ];
}

/* ============================================================
   MOMENTOS / LUGARES CLAVE
   ============================================================ */

function qrb_informe_hora_pico(array $serie_hora): array
{
    if (empty($serie_hora)) { return ['hora' => null]; }
    $tot = array_sum(array_column($serie_hora, 'viajes'));
    $top = $serie_hora[0];
    foreach ($serie_hora as $r) {
        if ($r['viajes'] > $top['viajes']) { $top = $r; }
    }
    return [
        'hora'    => (int)$top['hora'],
        'viajes'  => (int)$top['viajes'],
        'pct'     => $tot > 0 ? round(100 * $top['viajes'] / $tot, 1) : 0,
        'rango'   => sprintf('%02d:00 — %02d:59', (int)$top['hora'], (int)$top['hora']),
    ];
}

function qrb_informe_dia_pico(array $serie_dow): array
{
    if (empty($serie_dow)) { return ['dia' => null]; }
    $tot = array_sum(array_column($serie_dow, 'viajes'));
    $top = $serie_dow[0];
    foreach ($serie_dow as $r) {
        if ($r['viajes'] > $top['viajes']) { $top = $r; }
    }
    return [
        'dia'     => $top['dia'],
        'viajes'  => (int)$top['viajes'],
        'pct'     => $tot > 0 ? round(100 * $top['viajes'] / $tot, 1) : 0,
    ];
}

/**
 * Identifica la estación con mayor desbalance neto (|llegadas - salidas|)
 * dentro de aquellas con volumen significativo (>= 1% del total).
 */
function qrb_informe_estacion_desbalance(array $estaciones): array
{
    if (empty($estaciones)) { return ['nombre' => null]; }
    $total_global = array_sum(array_column($estaciones, 'total'));
    $umbral = $total_global * 0.01;
    $top = null;
    foreach ($estaciones as $e) {
        if ($e['total'] < $umbral) { continue; }
        if ($top === null || abs($e['balance']) > abs($top['balance'])) {
            $top = $e;
        }
    }
    if ($top === null) { return ['nombre' => null]; }
    return [
        'nombre'   => $top['nombre'],
        'balance'  => (int)$top['balance'],
        'total'    => (int)$top['total'],
        'sentido'  => $top['balance'] > 0 ? 'acumula' : 'expulsa',
        'pct_desb' => $top['total'] > 0
                        ? round(100 * abs($top['balance']) / $top['total'], 1) : 0,
    ];
}

/**
 * Top estación con más volumen total (salidas + llegadas).
 */
function qrb_informe_estacion_top(array $estaciones): array
{
    if (empty($estaciones)) { return ['nombre' => null]; }
    return [
        'nombre' => $estaciones[0]['nombre'],
        'total'  => (int)$estaciones[0]['total'],
    ];
}

/* ============================================================
   RECOMENDACIONES DATA-DRIVEN
   ============================================================ */

/**
 * Genera un array de 4-6 recomendaciones basadas en los KPIs.
 * Cada item: ['titulo','accion','metrica','prioridad'=>alta|media|baja].
 *
 * Las reglas que disparan recomendaciones son intencionalmente
 * conservadoras para que aparezcan solo cuando hay evidencia.
 */
function qrb_informe_recomendaciones(array $dataset, array $planes_data, array $deltas): array
{
    $recos = [];
    $k     = $dataset['kpis'];
    $est_desb = qrb_informe_estacion_desbalance($dataset['estaciones'] ?? []);
    $hora     = qrb_informe_hora_pico($dataset['serie_hora'] ?? []);
    $kp       = $planes_data['kpis_planes'] ?? [];

    /* 1. Desbalance fuerte de estación → rebalanceo */
    if ($est_desb['nombre'] && $est_desb['pct_desb'] >= 15 && abs($est_desb['balance']) >= 20) {
        $verbo = $est_desb['sentido'] === 'acumula' ? 'retirar' : 'reabastecer';
        $recos[] = [
            'titulo'    => 'Operar rebalanceo en ' . $est_desb['nombre'],
            'accion'    => "Programa rutas de $verbo bicicletas en esta estación. El desbalance neto representa el {$est_desb['pct_desb']}% de su volumen.",
            'metrica'   => sprintf('%+d bicis netas en el periodo (%s)',
                                   $est_desb['balance'],
                                   $est_desb['sentido'] === 'acumula' ? 'acumula' : 'pierde'),
            'prioridad' => 'alta',
        ];
    }

    /* 2. Concentración alta en hora pico → flota de refuerzo */
    if ($hora['hora'] !== null && $hora['pct'] >= 10) {
        $recos[] = [
            'titulo'    => sprintf('Refuerza la flota a las %02d:00', $hora['hora']),
            'accion'    => "El {$hora['pct']}% de los viajes ocurre en esa franja. Asegura disponibilidad mediante prepoblación de estaciones de origen frecuente.",
            'metrica'   => $hora['viajes'] . ' viajes en esa hora',
            'prioridad' => $hora['pct'] >= 15 ? 'alta' : 'media',
        ];
    }

    /* 3. Tasa de pago de planes baja → cobro */
    if (!empty($kp) && ($kp['total_planes'] ?? 0) > 0) {
        $tp = $kp['tasa_pago'] ?? 0;
        if ($tp < 80) {
            $recos[] = [
                'titulo'    => 'Cierra el ciclo de cobro de suscripciones',
                'accion'    => "Solo {$tp}% de los planes están marcados como pagados. Audita el flujo de pago, recordatorios automáticos y reintentos.",
                'metrica'   => sprintf('%s de %s planes pagados',
                                       number_format($kp['planes_pagados']),
                                       number_format($kp['total_planes'])),
                'prioridad' => $tp < 60 ? 'alta' : 'media',
            ];
        }
    }

    /* 4. Renovación baja → fidelización */
    if (!empty($kp) && ($kp['usuarios_con_plan'] ?? 0) >= 20) {
        $pr = $kp['pct_renovacion'] ?? 0;
        if ($pr < 25) {
            $recos[] = [
                'titulo'    => 'Activa una campaña de retención',
                'accion'    => "Solo {$pr}% de los usuarios con plan ha renovado. Considera descuentos por antigüedad, recordatorio previo al vencimiento y onboarding al segundo plan.",
                'metrica'   => sprintf('%d de %d usuarios renueva',
                                       $kp['renovaciones'], $kp['usuarios_con_plan']),
                'prioridad' => 'media',
            ];
        }
    }

    /* 5. Captura de CURP baja → onboarding */
    if (!empty($k['pct_curp']) && $k['pct_curp'] < 70) {
        $recos[] = [
            'titulo'    => 'Mejora la captura de CURP en alta de usuarios',
            'accion'    => "Sin CURP no se puede segmentar por edad y sexo, lo que limita reportes de impacto. Refuerza el onboarding obligatorio y limpia históricos.",
            'metrica'   => "{$k['pct_curp']}% de viajes con CURP completo",
            'prioridad' => $k['pct_curp'] < 50 ? 'alta' : 'media',
        ];
    }

    /* 6. Bici eléctrica subutilizada o sobrerrepresentada */
    if (!empty($k['pct_electrica']) && $k['total_viajes'] >= 100) {
        if ($k['pct_electrica'] < 8) {
            $recos[] = [
                'titulo'    => 'Evalúa ampliar la flota eléctrica',
                'accion'    => 'La proporción de viajes en eléctrica es baja. Si la flota es chica, mide si los usuarios la prefieren cuando está disponible para justificar inversión.',
                'metrica'   => "{$k['pct_electrica']}% de viajes en eléctrica",
                'prioridad' => 'baja',
            ];
        } elseif ($k['pct_electrica'] >= 50) {
            $recos[] = [
                'titulo'    => 'Vigila el costo operativo de la flota eléctrica',
                'accion'    => 'Más de la mitad de los viajes son eléctricos. Monitorea baterías, ciclos de carga y desgaste — el costo unitario es mayor que la mecánica.',
                'metrica'   => "{$k['pct_electrica']}% de viajes en eléctrica",
                'prioridad' => 'media',
            ];
        }
    }

    /* 7. Tendencia del periodo (delta vs anterior) */
    if (!empty($deltas['disponible']) && $deltas['d_viajes'] !== null) {
        $dv = $deltas['d_viajes'];
        if ($dv >= 15) {
            $recos[] = [
                'titulo'    => 'Crecimiento sostenido: blinda la capacidad operativa',
                'accion'    => 'El volumen sube con fuerza. Verifica que el rebalanceo, soporte técnico y atención a usuarios escalan al mismo ritmo antes de saturarse.',
                'metrica'   => sprintf('+%s%% viajes vs. periodo anterior', $dv),
                'prioridad' => 'media',
            ];
        } elseif ($dv <= -10) {
            $recos[] = [
                'titulo'    => 'Investiga la caída de uso',
                'accion'    => 'Hay una baja relevante respecto al periodo anterior. Cruza con clima, eventos, mantenimientos masivos y cambios de tarifa para identificar la causa.',
                'metrica'   => sprintf('%s%% viajes vs. periodo anterior', $dv),
                'prioridad' => 'alta',
            ];
        }
    }

    /* 8. Viajes sin distancia o GPS perdido */
    $pct_sin_dist = $k['total_viajes'] > 0
        ? round(100 * $k['viajes_sin_distancia'] / $k['total_viajes'], 1)
        : 0;
    if ($pct_sin_dist >= 5) {
        $recos[] = [
            'titulo'    => 'Atiende fallas de GPS / odómetro en la flota',
            'accion'    => "Un {$pct_sin_dist}% de los viajes no registró distancia. Cruza con el número de serie para detectar bicicletas con sensor defectuoso.",
            'metrica'   => number_format($k['viajes_sin_distancia']) . ' viajes sin distancia',
            'prioridad' => $pct_sin_dist >= 10 ? 'alta' : 'media',
        ];
    }

    // si nada disparó, agregamos una recomendación general
    if (empty($recos)) {
        $recos[] = [
            'titulo'    => 'Mantén el ritmo operativo',
            'accion'    => 'Las métricas clave se encuentran dentro de rangos saludables. Aprovecha para documentar buenas prácticas y planear la siguiente fase de crecimiento.',
            'metrica'   => 'Sin desviaciones críticas detectadas',
            'prioridad' => 'baja',
        ];
    }

    // ordenamos por prioridad (alta > media > baja) y cortamos a 6 máximo
    $rank = ['alta' => 0, 'media' => 1, 'baja' => 2];
    usort($recos, fn($a, $b) => $rank[$a['prioridad']] <=> $rank[$b['prioridad']]);
    return array_slice($recos, 0, 6);
}

/* ============================================================
   ORQUESTADOR
   ============================================================ */

function qrb_construye_dataset_informe(array $cfg): array
{
    $viajes_ds = qrb_construye_dataset_viajes($cfg);
    $planes_ds = qrb_construye_dataset_planes($cfg);

    if (!empty($viajes_ds['vacio']) || empty($viajes_ds['kpis']['total_viajes'])) {
        return ['vacio' => true,
                'mensaje' => 'No hay viajes en el periodo configurado para generar el informe.'];
    }

    $deltas = qrb_informe_deltas($cfg, $viajes_ds['kpis']);
    $recos  = qrb_informe_recomendaciones($viajes_ds, $planes_ds, $deltas);

    return [
        'vacio'       => false,
        'kpis'        => $viajes_ds['kpis'],
        'serie_dia'   => $viajes_ds['serie_dia'],
        'serie_hora'  => $viajes_ds['serie_hora'],
        'serie_dow'   => $viajes_ds['serie_dow'],
        'estaciones'  => $viajes_ds['estaciones'],
        'pares_od'    => $viajes_ds['pares_od'],
        'sexo_dist'   => $viajes_ds['sexo_dist'],
        'edad_dist'   => $viajes_ds['edad_dist'],
        'tipo_dist'   => $viajes_ds['tipo_dist'],
        'kpis_planes' => $planes_ds['kpis_planes'] ?? [],
        'planes_vacio'=> !empty($planes_ds['vacio_planes']),
        'deltas'      => $deltas,
        'hora_pico'   => qrb_informe_hora_pico($viajes_ds['serie_hora']),
        'dia_pico'    => qrb_informe_dia_pico($viajes_ds['serie_dow']),
        'est_desb'    => qrb_informe_estacion_desbalance($viajes_ds['estaciones']),
        'est_top'     => qrb_informe_estacion_top($viajes_ds['estaciones']),
        'recos'       => $recos,
    ];
}
