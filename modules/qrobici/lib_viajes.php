<?php
/**
 * QroBici Analytics — Librería de viajes
 * ------------------------------------------------------------
 * Funciones que leen la vista `viajes` y producen los bloques
 * de datos que consume el reporte HTML.
 *
 * Cada función es independiente; orquestadas por
 * qrb_construye_dataset_viajes() al final del archivo.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib_polyline.php';

/* ============================================================
   UTILIDADES
   ============================================================ */

function qrb_curp_info(?string $curp, int $anio_ref = null): array
{
    if ($curp === null || strlen($curp) < 11) {
        return ['edad' => null, 'sexo' => null];
    }
    $anio_ref = $anio_ref ?? (int)date('Y');
    try {
        $yy = (int)substr($curp, 4, 2);
        $mm = (int)substr($curp, 6, 2);
        $dd = (int)substr($curp, 8, 2);
        $sx = substr($curp, 10, 1);
        if ($mm < 1 || $mm > 12 || $dd < 1 || $dd > 31) {
            return ['edad' => null, 'sexo' => null];
        }
        // Heurística: si yy > (año actual % 100) entonces 1900s, si no 2000s
        $umbral = $anio_ref % 100;
        $anio = ($yy > $umbral) ? (1900 + $yy) : (2000 + $yy);
        $hoy_m = (int)date('n'); $hoy_d = (int)date('j');
        $edad = $anio_ref - $anio - ((($mm > $hoy_m) || ($mm === $hoy_m && $dd > $hoy_d)) ? 1 : 0);
        if ($edad < 5 || $edad > 100) {
            return ['edad' => null, 'sexo' => null];
        }
        $sexo = ($sx === 'H') ? 'H' : (($sx === 'M') ? 'M' : null);
        return ['edad' => $edad, 'sexo' => $sexo];
    } catch (Throwable $e) {
        return ['edad' => null, 'sexo' => null];
    }
}

function qrb_estacion_base(?string $nombre): ?string
{
    if ($nombre === null) { return null; }
    // quita el sufijo "(NN)" final
    return trim(preg_replace('/\s*\(\d+\)\s*$/u', '', $nombre));
}

/* ============================================================
   CARGA DE FILAS DESDE LA VISTA
   ============================================================ */

function qrb_carga_viajes(array $cfg): array
{
    $pdo = qrb_db();
    $vista = $cfg['vistas']['viajes'];

    [$wfe, $params] = qrb_where_fecha('FECHA', $cfg['fecha_desde'], $cfg['fecha_hasta']);
    $sql = "SELECT
                USUARIO_ID, VIAJE_SEC, USUARIO_NOMBRE, CURP, FOLIO, FECHA,
                DURACION, DISTANCIA, NUMERO_SERIE, TIPO_BICICLETA,
                ESTACION_ORIGEN, NUM_ESPACIO_ORIGEN,
                ESTACION_LATITUD_ORIGEN, ESTACION_LONGITUD_ORIGEN,
                ESTACION_DESTINO, NUM_ESPACIO_DESTINO,
                ESTACION_LATITUD_DESTINO, ESTACION_LONGITUD_DESTINO,
                VIAJE_ESTATUS, RECORRIDO, PLAN_ACTIVO
            FROM `$vista`
            WHERE 1=1 $wfe
            ORDER BY FECHA ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/* ============================================================
   NORMALIZACIÓN: añade campos derivados a cada viaje
   ============================================================ */

function qrb_normaliza_viajes(array &$viajes): void
{
    foreach ($viajes as &$v) {
        $info = qrb_curp_info($v['CURP'] ?? null);
        $v['EDAD']   = $info['edad'];
        $v['SEXO']   = $info['sexo'];
        $v['EST_O']  = qrb_estacion_base($v['ESTACION_ORIGEN']);
        $v['EST_D']  = qrb_estacion_base($v['ESTACION_DESTINO']);
        $v['DUR']    = (int)($v['DURACION'] ?? 0);
        $v['DIST']   = (int)($v['DISTANCIA'] ?? 0);
        $v['TS']     = strtotime($v['FECHA']);
        $v['CIRC']   = (
            (float)$v['ESTACION_LATITUD_ORIGEN']  === (float)$v['ESTACION_LATITUD_DESTINO'] &&
            (float)$v['ESTACION_LONGITUD_ORIGEN'] === (float)$v['ESTACION_LONGITUD_DESTINO']
        );
    }
    unset($v);
}

/* ============================================================
   KPIs GLOBALES
   ============================================================ */

function qrb_kpis_globales(array $viajes, array $cfg): array
{
    $n = count($viajes);
    if ($n === 0) {
        return ['total_viajes' => 0];
    }

    $dist_total = 0; $dur_total = 0;
    $usuarios = []; $dur_pos = []; $dist_pos = [];
    $electrica = 0; $mecanica = 0; $circ = 0; $sin_dist = 0; $curp_ok = 0;
    $edades = []; $fmin = PHP_INT_MAX; $fmax = 0;
    $vel = [];
    $con_origen = 0; $con_destino = 0;

    // === Desglose por tipo de bicicleta (mec vs elec) ===
    $dist_e = 0; $dist_m = 0;          // metros acumulados por tipo
    $dur_e  = 0; $dur_m  = 0;           // segundos acumulados por tipo
    $vel_e  = []; $vel_m  = [];         // velocidades por viaje, por tipo
    $dur_pos_e = []; $dur_pos_m = [];
    $dist_pos_e = []; $dist_pos_m = [];

    foreach ($viajes as $v) {
        $dist_total += $v['DIST'];
        $dur_total  += $v['DUR'];
        $usuarios[$v['USUARIO_ID']] = true;
        if ($v['DUR'] > 0)  { $dur_pos[]  = $v['DUR']; }
        if ($v['DIST'] > 0) { $dist_pos[] = $v['DIST']; }
        else                { $sin_dist++; }

        // detección tolerante de tipo: normaliza acentos y compara contiene
        $tipo_norm = strtr((string)$v['TIPO_BICICLETA'],
            ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
             'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U']);
        $tipo_upper = strtoupper($tipo_norm);
        $es_elec = strpos($tipo_upper, 'ELEC') !== false;
        $es_mec  = strpos($tipo_upper, 'MECANIC') !== false || strpos($tipo_upper, 'MECA') !== false;

        if ($es_elec) {
            $electrica++;
            $dist_e += $v['DIST']; $dur_e += $v['DUR'];
            if ($v['DUR']  > 0) { $dur_pos_e[]  = $v['DUR']; }
            if ($v['DIST'] > 0) { $dist_pos_e[] = $v['DIST']; }
        } elseif ($es_mec) {
            $mecanica++;
            $dist_m += $v['DIST']; $dur_m += $v['DUR'];
            if ($v['DUR']  > 0) { $dur_pos_m[]  = $v['DUR']; }
            if ($v['DIST'] > 0) { $dist_pos_m[] = $v['DIST']; }
        }

        if ($v['CIRC']) { $circ++; }
        if (!empty($v['CURP'])) { $curp_ok++; }
        if (!empty($v['EST_O'])) { $con_origen++; }
        if (!empty($v['EST_D'])) { $con_destino++; }
        if ($v['EDAD'] !== null) { $edades[] = $v['EDAD']; }
        if ($v['TS'] < $fmin) { $fmin = $v['TS']; }
        if ($v['TS'] > $fmax) { $fmax = $v['TS']; }

        // velocidad: solo viajes con duración > 30s y distancia > 50m
        if ($v['DUR'] > 30 && $v['DIST'] > 50) {
            $kmh = ($v['DIST']/1000) / ($v['DUR']/3600);
            if ($kmh > 0 && $kmh < 40) {
                $vel[] = $kmh;
                if ($es_elec) { $vel_e[] = $kmh; }
                elseif ($es_mec) { $vel_m[] = $kmh; }
            }
        }
    }

    $dist_km    = $dist_total / 1000;
    $dur_horas  = $dur_total / 3600;
    $dur_prom_m = count($dur_pos)  ? array_sum($dur_pos)  / count($dur_pos)  / 60 : 0;
    $dist_prom  = count($dist_pos) ? array_sum($dist_pos) / count($dist_pos) : 0;

    sort($vel);
    $vel_prom    = count($vel) ? array_sum($vel) / count($vel) : 0;
    $vel_mediana = count($vel) ? $vel[(int)floor(count($vel)/2)] : 0;
    $vel_p25     = count($vel) ? $vel[(int)floor(count($vel)*0.25)] : 0;
    $vel_p75     = count($vel) ? $vel[(int)floor(count($vel)*0.75)] : 0;
    // Velocidad efectiva total = suma km / suma horas (no es promedio de
    // promedios). Suele ser más representativa de la flota como un todo.
    $vel_efectiva = ($dur_horas > 0) ? $dist_km / $dur_horas : 0;

    // === Cálculos por tipo (eléctrica vs mecánica) ===
    sort($vel_e); sort($vel_m);
    $stats = function (array $arr_vel, int $dist_acum_m, int $dur_acum_s,
                       array $dur_pos_arr, array $dist_pos_arr) {
        $n   = count($arr_vel);
        $km  = $dist_acum_m / 1000;
        $h   = $dur_acum_s / 3600;
        return [
            'viajes'      => $n,
            'km_total'    => round($km, 1),
            'horas_total' => round($h, 1),
            'vel_prom'    => $n ? round(array_sum($arr_vel) / $n, 1) : 0,
            'vel_mediana' => $n ? round($arr_vel[(int)floor($n/2)], 1) : 0,
            'vel_p25'     => $n ? round($arr_vel[(int)floor($n*0.25)], 1) : 0,
            'vel_p75'     => $n ? round($arr_vel[(int)floor($n*0.75)], 1) : 0,
            'vel_efectiva'=> $h > 0 ? round($km / $h, 1) : 0,
            'dist_prom_m' => count($dist_pos_arr)
                              ? (int)round(array_sum($dist_pos_arr) / count($dist_pos_arr)) : 0,
            'dur_prom_min'=> count($dur_pos_arr)
                              ? round((array_sum($dur_pos_arr) / count($dur_pos_arr)) / 60, 1) : 0,
        ];
    };
    $por_tipo = [
        'mecanica'  => $stats($vel_m, $dist_m, $dur_m, $dur_pos_m, $dist_pos_m),
        'electrica' => $stats($vel_e, $dist_e, $dur_e, $dur_pos_e, $dist_pos_e),
    ];

    sort($edades);
    $edad_prom = count($edades) ? array_sum($edades) / count($edades) : 0;

    // estaciones activas
    $estaciones = [];
    foreach ($viajes as $v) {
        if ($v['EST_O']) { $estaciones[$v['EST_O']] = true; }
        if ($v['EST_D']) { $estaciones[$v['EST_D']] = true; }
    }

    $dias = max(1, ceil(($fmax - $fmin) / 86400) + 1);

    return [
        'total_viajes'         => $n,
        'usuarios_unicos'      => count($usuarios),
        'dist_total_km'        => round($dist_km, 1),
        'dur_total_horas'      => round($dur_horas, 1),
        'dur_prom_min'         => round($dur_prom_m, 1),
        'dist_prom_m'          => round($dist_prom, 0),
        'vel_prom'             => round($vel_prom, 1),
        'vel_mediana'          => round($vel_mediana, 1),
        'vel_p25'              => round($vel_p25, 1),
        'vel_p75'              => round($vel_p75, 1),
        'vel_efectiva'         => round($vel_efectiva, 1),
        'vel_muestra_n'        => count($vel),
        'viajes_circulares'    => $circ,
        'pct_electrica'        => round(100 * $electrica / $n, 1),
        'pct_mecanica'         => round(100 * $mecanica / $n, 1),
        'viajes_sin_distancia' => $sin_dist,
        'estaciones_activas'   => count($estaciones),
        'fecha_min'            => date('d/m/Y', $fmin),
        'fecha_max'            => date('d/m/Y', $fmax),
        'dias_operacion'       => (int)$dias,
        'co2_kg'               => round($dist_km * ($cfg['co2_g_por_km'] / 1000), 1),
        'calorias_total'       => (int)round($dist_km * $cfg['calorias_por_km']),
        'edad_prom'            => round($edad_prom, 1),
        'pct_curp'             => round(100 * $curp_ok / $n, 1),
        'viajes_con_origen'    => $con_origen,
        'viajes_con_destino'   => $con_destino,
        'pct_con_origen'       => round(100 * $con_origen  / $n, 1),
        'pct_con_destino'      => round(100 * $con_destino / $n, 1),
        // Desglose por tipo de bicicleta — alimenta la card comparativa
        'por_tipo'             => $por_tipo,
    ];
}

/* ============================================================
   SERIES TEMPORALES
   ============================================================ */

function qrb_series_temporales(array $viajes): array
{
    $por_dia = []; $por_hora = []; $por_dow = []; $heat = [];

    foreach ($viajes as $v) {
        $fecha = date('Y-m-d', $v['TS']);
        $hora  = (int)date('G',  $v['TS']);
        $dow   = (int)date('N',  $v['TS']) - 1; // 0=Lun ... 6=Dom

        if (!isset($por_dia[$fecha])) { $por_dia[$fecha] = ['viajes' => 0, 'dist' => 0]; }
        $por_dia[$fecha]['viajes']++;
        $por_dia[$fecha]['dist'] += $v['DIST'];

        $por_hora[$hora] = ($por_hora[$hora] ?? 0) + 1;
        $por_dow[$dow]   = ($por_dow[$dow]   ?? 0) + 1;

        $key = $dow . '_' . $hora;
        $heat[$key] = ($heat[$key] ?? 0) + 1;
    }

    ksort($por_dia);
    $serie_dia = [];
    $dias_sem  = ['Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mié','Thu'=>'Jue','Fri'=>'Vie','Sat'=>'Sáb','Sun'=>'Dom'];
    foreach ($por_dia as $fecha => $d) {
        $dn = $dias_sem[date('D', strtotime($fecha))] ?? '';
        $serie_dia[] = [
            'fecha'  => $fecha,
            'dia'    => $dn . ' ' . date('d', strtotime($fecha)),
            'viajes' => $d['viajes'],
            'km'     => round($d['dist'] / 1000, 1),
        ];
    }

    $serie_hora = [];
    for ($h = 6; $h <= 22; $h++) {
        $serie_hora[] = ['hora' => $h, 'viajes' => $por_hora[$h] ?? 0];
    }

    $dias_lbl  = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];
    $serie_dow = [];
    for ($d = 0; $d < 7; $d++) {
        $serie_dow[] = ['dia' => $dias_lbl[$d], 'viajes' => $por_dow[$d] ?? 0];
    }

    $heat_out = [];
    for ($d = 0; $d < 7; $d++) {
        for ($h = 6; $h <= 22; $h++) {
            $heat_out[] = [
                'dia'    => $dias_lbl[$d],
                'diaIdx' => $d,
                'hora'   => $h,
                'viajes' => $heat[$d . '_' . $h] ?? 0,
            ];
        }
    }

    return compact('serie_dia', 'serie_hora', 'serie_dow') + ['heat' => $heat_out];
}

/* ============================================================
   ESTACIONES Y PARES OD
   ============================================================ */

function qrb_estaciones(array $viajes): array
{
    $coords = [];
    $stats  = [];

    foreach ($viajes as $v) {
        if ($v['EST_O']) {
            $coords[$v['EST_O']][] = [(float)$v['ESTACION_LATITUD_ORIGEN'], (float)$v['ESTACION_LONGITUD_ORIGEN']];
            $stats[$v['EST_O']]['salidas']  = ($stats[$v['EST_O']]['salidas']  ?? 0) + 1;
            $stats[$v['EST_O']]['llegadas'] = $stats[$v['EST_O']]['llegadas'] ?? 0;
        }
        if ($v['EST_D']) {
            $coords[$v['EST_D']][] = [(float)$v['ESTACION_LATITUD_DESTINO'], (float)$v['ESTACION_LONGITUD_DESTINO']];
            $stats[$v['EST_D']]['llegadas'] = ($stats[$v['EST_D']]['llegadas'] ?? 0) + 1;
            $stats[$v['EST_D']]['salidas']  = $stats[$v['EST_D']]['salidas']  ?? 0;
        }
    }

    $out = [];
    foreach ($stats as $nombre => $s) {
        $cs = $coords[$nombre] ?? [];
        if (empty($cs)) { continue; }
        $lat = array_sum(array_column($cs, 0)) / count($cs);
        $lng = array_sum(array_column($cs, 1)) / count($cs);
        $sal = $s['salidas']; $lle = $s['llegadas'];
        $out[] = [
            'nombre'   => $nombre,
            'lat'      => round($lat, 6),
            'lng'      => round($lng, 6),
            'salidas'  => $sal,
            'llegadas' => $lle,
            'total'    => $sal + $lle,
            'balance'  => $lle - $sal,
        ];
    }
    usort($out, fn($a, $b) => $b['total'] <=> $a['total']);
    return $out;
}

function qrb_pares_od(array $viajes, int $limite = 20): array
{
    $cnt = [];
    foreach ($viajes as $v) {
        if (!$v['EST_O'] || !$v['EST_D']) { continue; }
        $key = $v['EST_O'] . '||' . $v['EST_D'];
        $cnt[$key] = ($cnt[$key] ?? 0) + 1;
    }
    arsort($cnt);
    $out = [];
    $i = 0;
    foreach ($cnt as $key => $v) {
        if ($i++ >= $limite) { break; }
        [$o, $d] = explode('||', $key);
        $out[] = ['origen' => $o, 'destino' => $d, 'viajes' => $v, 'circular' => $o === $d];
    }
    return $out;
}

/* ============================================================
   DEMOGRAFÍA, USUARIOS, FRECUENCIA
   ============================================================ */

function qrb_demografia(array $viajes): array
{
    $rangos = [
        [0, 17,  '≤17'],
        [18, 24, '18-24'],
        [25, 34, '25-34'],
        [35, 44, '35-44'],
        [45, 54, '45-54'],
        [55, 120,'55+'],
    ];

    $edad_dist = []; $edad_sexo = [];
    foreach ($rangos as $r) {
        $edad_dist[] = ['rango' => $r[2], 'cantidad' => 0];
        $edad_sexo[] = ['rango' => $r[2], 'H' => 0, 'M' => 0];
    }

    $sexo = ['Hombres' => 0, 'Mujeres' => 0, 'Sin dato' => 0];

    foreach ($viajes as $v) {
        if ($v['SEXO'] === 'H')      { $sexo['Hombres']++; }
        elseif ($v['SEXO'] === 'M')  { $sexo['Mujeres']++; }
        else                         { $sexo['Sin dato']++; }

        if ($v['EDAD'] === null) { continue; }
        foreach ($rangos as $i => $r) {
            if ($v['EDAD'] >= $r[0] && $v['EDAD'] <= $r[1]) {
                $edad_dist[$i]['cantidad']++;
                if ($v['SEXO'] === 'H') { $edad_sexo[$i]['H']++; }
                if ($v['SEXO'] === 'M') { $edad_sexo[$i]['M']++; }
                break;
            }
        }
    }

    return compact('edad_dist', 'edad_sexo') + ['sexo_dist' => $sexo];
}

function qrb_top_usuarios(array $viajes, int $limite = 10): array
{
    $u = [];
    foreach ($viajes as $v) {
        $id = $v['USUARIO_ID'];
        if (!isset($u[$id])) {
            $u[$id] = ['nombre' => $v['USUARIO_NOMBRE'] ?: ('Usuario ' . $id),
                       'viajes' => 0, 'dist' => 0, 'dur' => 0];
        }
        $u[$id]['viajes']++;
        $u[$id]['dist'] += $v['DIST'];
        $u[$id]['dur']  += $v['DUR'];
    }
    usort($u, fn($a, $b) => $b['viajes'] <=> $a['viajes']);
    $top = array_slice($u, 0, $limite);
    return array_map(fn($x) => [
        'nombre' => $x['nombre'],
        'viajes' => $x['viajes'],
        'km'     => round($x['dist'] / 1000, 1),
        'horas'  => round($x['dur']  / 3600, 1),
    ], $top);
}

function qrb_frecuencia(array $viajes): array
{
    $vc = [];
    foreach ($viajes as $v) { $vc[$v['USUARIO_ID']] = ($vc[$v['USUARIO_ID']] ?? 0) + 1; }
    $f = ['1 viaje' => 0, '2-3 viajes' => 0, '4-6 viajes' => 0, '7-10 viajes' => 0, '11+ viajes' => 0];
    foreach ($vc as $c) {
        if ($c === 1)            { $f['1 viaje']++; }
        elseif ($c <= 3)         { $f['2-3 viajes']++; }
        elseif ($c <= 6)         { $f['4-6 viajes']++; }
        elseif ($c <= 10)        { $f['7-10 viajes']++; }
        else                     { $f['11+ viajes']++; }
    }
    return $f;
}

/* ============================================================
   DISTRIBUCIONES (duración, distancia, planes, tipos)
   ============================================================ */

function qrb_distribucion_duracion(array $viajes): array
{
    $bins = [
        [0, 0, 'Sin uso (0 min)'],
        [0.01, 5, '0-5 min'],
        [5, 10, '5-10 min'],
        [10, 20, '10-20 min'],
        [20, 30, '20-30 min'],
        [30, 60, '30-60 min'],
        [60, 99999, '60+ min'],
    ];
    $out = array_map(fn($b) => ['rango' => $b[2], 'cantidad' => 0], $bins);
    foreach ($viajes as $v) {
        $m = $v['DUR'] / 60;
        foreach ($bins as $i => $b) {
            if ($b[0] === 0 && $b[1] === 0) {
                if ($m == 0) { $out[$i]['cantidad']++; break; }
            } else {
                if ($m > $b[0] && $m <= $b[1]) { $out[$i]['cantidad']++; break; }
            }
        }
    }
    return $out;
}

function qrb_distribucion_distancia(array $viajes): array
{
    $bins = [
        [0, 0, 'Sin recorrido'],
        [0.001, 0.5, '0-500 m'],
        [0.5, 1, '500m-1km'],
        [1, 2, '1-2 km'],
        [2, 3, '2-3 km'],
        [3, 5, '3-5 km'],
        [5, 99, '5+ km'],
    ];
    $out = array_map(fn($b) => ['rango' => $b[2], 'cantidad' => 0], $bins);
    foreach ($viajes as $v) {
        $km = $v['DIST'] / 1000;
        foreach ($bins as $i => $b) {
            if ($b[0] === 0 && $b[1] === 0) {
                if ($km == 0) { $out[$i]['cantidad']++; break; }
            } else {
                if ($km > $b[0] && $km <= $b[1]) { $out[$i]['cantidad']++; break; }
            }
        }
    }
    return $out;
}

function qrb_planes_dist_viajes(array $viajes): array
{
    // El campo PLAN_ACTIVO de la vista `viajes` puede venir de dos formas:
    //   a) como bandera "SI"/"NO" → indica si el usuario tenía plan vigente
    //   b) como nombre literal del plan ("Mensual", "Anual"...)
    // Detectamos qué caso es por fila y agrupamos en consecuencia.
    $si_vals = ['S','SI','Y','YES','1','TRUE','V','T'];
    $no_vals = ['N','NO','FALSE','0','F'];

    $cnt = [];
    foreach ($viajes as $v) {
        $raw = trim((string)($v['PLAN_ACTIVO'] ?? ''));
        // normaliza acentos para tolerar 'SÍ'
        $up = strtr(strtoupper($raw),
              ['á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U',
               'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U']);
        if ($raw === '') {
            $p = 'Sin plan';
        } elseif (in_array($up, $si_vals, true)) {
            $p = 'Con plan vigente';
        } elseif (in_array($up, $no_vals, true)) {
            $p = 'Sin plan';
        } else {
            // valor literal: lo respetamos como nombre del plan
            $p = $raw;
        }
        $cnt[$p] = ($cnt[$p] ?? 0) + 1;
    }
    arsort($cnt);
    $out = [];
    foreach ($cnt as $k => $val) { $out[] = ['plan' => $k, 'viajes' => $val]; }
    return $out;
}

function qrb_tipos_dist(array $viajes): array
{
    $cnt = [];
    foreach ($viajes as $v) {
        $t = $v['TIPO_BICICLETA'] ?: 'Sin tipo';
        $cnt[$t] = ($cnt[$t] ?? 0) + 1;
    }
    arsort($cnt);
    $out = [];
    foreach ($cnt as $k => $val) { $out[] = ['tipo' => $k, 'viajes' => $val]; }
    return $out;
}

function qrb_tipo_por_hora(array $viajes): array
{
    $h_e = []; $h_m = [];
    foreach ($viajes as $v) {
        $h = (int)date('G', $v['TS']);
        if ($v['TIPO_BICICLETA'] === 'Eléctrica')      { $h_e[$h] = ($h_e[$h] ?? 0) + 1; }
        elseif ($v['TIPO_BICICLETA'] === 'Mecánica')   { $h_m[$h] = ($h_m[$h] ?? 0) + 1; }
    }
    $out = [];
    for ($h = 6; $h <= 22; $h++) {
        $out[] = ['hora' => $h, 'Eléctrica' => $h_e[$h] ?? 0, 'Mecánica' => $h_m[$h] ?? 0];
    }
    return $out;
}

/* ============================================================
   RUTAS (polilíneas)
   ============================================================ */

function qrb_rutas(array $viajes, int $min_puntos = 8): array
{
    $rutas = [];
    foreach ($viajes as $v) {
        $pts = qrb_decodifica_y_simplifica($v['RECORRIDO'] ?? null);
        if (count($pts) < 2) { continue; }
        // descartar si tras simplificar quedan < min/4 puntos significativos
        $pts_full = qrb_polyline_decode($v['RECORRIDO'] ?? null);
        if (count($pts_full) < $min_puntos) { continue; }
        $rutas[] = [
            'folio'   => $v['FOLIO'],
            'tipo'    => $v['TIPO_BICICLETA'],
            'origen'  => $v['EST_O'] ?? '',
            'destino' => $v['EST_D'] ?? $v['EST_O'] ?? '',
            'dist'    => $v['DIST'],
            'dur'     => $v['DUR'],
            'puntos'  => $pts,
        ];
    }
    // ordenar por distancia desc (para que las destacadas estén al inicio)
    usort($rutas, fn($a, $b) => $b['dist'] <=> $a['dist']);
    return $rutas;
}

/* ============================================================
   FILTRO DE ANOMALÍAS (uso exclusivo del reporte.php)
   ============================================================ */

/**
 * Aplica una serie de filtros para descartar viajes anómalos:
 *   - Velocidad fuera de rango (brincos GPS o bicis arrastradas)
 *   - Duración fuera de rango (desbloqueos en falso o viajes olvidados)
 *   - Distancia insuficiente
 *   - Coordenadas de estación inválidas (lat=0, lng=0)
 *
 * Devuelve ['viajes' => filtrados, 'descartes' => conteo por regla].
 *
 * Espera valores numéricos o null en cada clave de $f. Si una clave
 * es null no aplica esa regla.
 */
function qrb_filtra_anomalias(array $viajes, array $f): array
{
    $vel_min        = isset($f['vel_min'])  ? (float)$f['vel_min']  : null;
    $vel_max        = isset($f['vel_max'])  ? (float)$f['vel_max']  : null;
    $dur_min        = isset($f['dur_min'])  ? (int)$f['dur_min']    : null;
    $dur_max        = isset($f['dur_max'])  ? (int)$f['dur_max']    : null;
    $dist_min       = isset($f['dist_min']) ? (int)$f['dist_min']   : null;
    $coords_validas = !empty($f['coords_validas']);

    $out = [];
    $descartes = [
        'duracion'    => 0,
        'distancia'   => 0,
        'velocidad'   => 0,
        'coordenadas' => 0,
        'total'       => 0,
    ];

    foreach ($viajes as $v) {
        // duración fuera de rango
        if ($dur_min !== null && $v['DUR'] < $dur_min) { $descartes['duracion']++; $descartes['total']++; continue; }
        if ($dur_max !== null && $v['DUR'] > $dur_max) { $descartes['duracion']++; $descartes['total']++; continue; }

        // distancia mínima
        if ($dist_min !== null && $v['DIST'] < $dist_min) { $descartes['distancia']++; $descartes['total']++; continue; }

        // velocidad derivada — solo aplicable si hay duración y distancia útiles
        if ($v['DUR'] > 0 && $v['DIST'] > 0) {
            $kmh = ($v['DIST'] / 1000) / ($v['DUR'] / 3600);
            if ($vel_min !== null && $kmh < $vel_min) { $descartes['velocidad']++; $descartes['total']++; continue; }
            if ($vel_max !== null && $kmh > $vel_max) { $descartes['velocidad']++; $descartes['total']++; continue; }
        }

        // coordenadas inválidas (lat=0 y lng=0)
        if ($coords_validas) {
            $latO = (float)($v['ESTACION_LATITUD_ORIGEN']  ?? 0);
            $lngO = (float)($v['ESTACION_LONGITUD_ORIGEN'] ?? 0);
            $latD = (float)($v['ESTACION_LATITUD_DESTINO']  ?? 0);
            $lngD = (float)($v['ESTACION_LONGITUD_DESTINO'] ?? 0);
            if (($latO == 0.0 && $lngO == 0.0) || ($latD == 0.0 && $lngD == 0.0)) {
                $descartes['coordenadas']++; $descartes['total']++; continue;
            }
        }

        $out[] = $v;
    }

    return ['viajes' => $out, 'descartes' => $descartes];
}

/* ============================================================
   CALIFICACIONES (uso exclusivo del reporte.php)
   ============================================================ */

/**
 * Detecta los nombres reales de las columnas de calificación
 * en la vista, tolerando variantes con/sin acento y typos.
 * Devuelve un mapeo dimensión → nombre real de columna, o []
 * si no existe la dimensión.
 *
 *   $cols['flag']      → columna que indica si el usuario calificó (S/N)
 *   $cols['bicicleta'] → columna con la calificación de la bicicleta
 *   $cols['estacion']  → columna con la calificación de la estación
 *   $cols['app']       → columna con la calificación de la app
 */
function qrb_calif_detecta_columnas(array $cfg): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $pdo = qrb_db();
    $vista = $cfg['vistas']['viajes'];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$vista` WHERE Field LIKE 'calif%'");
        $reales = array_column($stmt->fetchAll(), 'Field');
    } catch (Throwable $e) {
        return $cache = [];
    }

    // mapping dimensión lógica → posibles nombres en orden de preferencia
    $candidatas = [
        'flag'      => ['califico', 'calificó', 'califica'],
        'bicicleta' => ['califica_bicicleta', 'califica_bicileta', 'califica_bici', 'calificacion_bicicleta'],
        'estacion'  => ['califica_estacion', 'califica_estación', 'calificacion_estacion', 'calificacion_estación', 'califica_est'],
        'app'       => ['califica_app', 'califica_aplicacion', 'califica_aplicación', 'calificacion_app'],
    ];

    $reales_lc = array_change_key_case(array_flip($reales), CASE_LOWER);
    $cols = [];
    foreach ($candidatas as $dim => $opciones) {
        foreach ($opciones as $opt) {
            if (isset($reales_lc[strtolower($opt)])) {
                // recuperar el nombre real con su casing original
                foreach ($reales as $r) {
                    if (strtolower($r) === strtolower($opt)) {
                        $cols[$dim] = $r; break 2;
                    }
                }
            }
        }
    }
    return $cache = $cols;
}

/**
 * Fragmento SQL que evalúa la columna flag como verdadero
 * para cualquier variante común: S, SI, SÍ, Y, YES, 1, TRUE.
 */
function qrb_calif_sql_es_si(string $col): string
{
    // normalizamos acentos y espacios en SQL puro para no asumir collation
    return "REPLACE(REPLACE(UPPER(TRIM(`$col`)), 'Í', 'I'), 'É', 'E')"
         . " IN ('S','SI','Y','YES','1','TRUE','V','T')";
}

/**
 * KPIs y distribuciones de las calificaciones del periodo.
 * Hace 4 queries agregadas (1 KPIs + 3 distribuciones).
 * Si no hay columnas de calificación detectadas → ['vacio' => true].
 */
function qrb_construye_dataset_calificaciones(array $cfg): array
{
    $cols = qrb_calif_detecta_columnas($cfg);
    if (empty($cols['flag'])) {
        return ['vacio' => true, 'motivo' => 'No se detectó la columna de bandera de calificación.'];
    }
    // necesitamos al menos una de las 3 dimensiones
    $dims_ok = array_intersect_key($cols, array_flip(['bicicleta', 'estacion', 'app']));
    if (empty($dims_ok)) {
        return ['vacio' => true,
                'motivo' => 'Solo se detectó la bandera "calificó"; ninguna columna de puntaje.',
                'cols_detectadas' => $cols];
    }

    $pdo   = qrb_db();
    $vista = $cfg['vistas']['viajes'];
    $flag  = $cols['flag'];
    $es_si = qrb_calif_sql_es_si($flag);

    [$wfe, $params] = qrb_where_fecha('FECHA', $cfg['fecha_desde'], $cfg['fecha_hasta']);

    // === Query agregada principal ===
    $exprs = ["COUNT(*) AS total",
              "SUM(CASE WHEN $es_si THEN 1 ELSE 0 END) AS calificaron"];
    foreach (['bicicleta', 'estacion', 'app'] as $d) {
        if (!empty($cols[$d])) {
            $c = $cols[$d];
            $exprs[] = "AVG(CASE WHEN $es_si AND `$c` IS NOT NULL THEN `$c` END) AS prom_$d";
            $exprs[] = "MAX(CASE WHEN $es_si THEN `$c` END) AS max_$d";
            $exprs[] = "MIN(CASE WHEN $es_si AND `$c` > 0 THEN `$c` END) AS min_$d";
            $exprs[] = "COUNT(CASE WHEN $es_si AND `$c` IS NOT NULL THEN 1 END) AS n_$d";
        }
    }
    $sql = "SELECT " . implode(",\n  ", $exprs) . "
            FROM `$vista`
            WHERE 1=1 $wfe";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    if (!$row || (int)$row['total'] === 0) {
        return ['vacio' => true, 'motivo' => 'No hay viajes en el periodo.'];
    }

    $total = (int)$row['total'];
    $calif = (int)$row['calificaron'];
    $tasa  = $total > 0 ? round(100 * $calif / $total, 1) : 0;

    // detectar escala global: usa el max de cualquier dimensión disponible
    $escala = 0;
    foreach (['bicicleta', 'estacion', 'app'] as $d) {
        if (!empty($cols[$d]) && $row["max_$d"] !== null) {
            $escala = max($escala, (int)$row["max_$d"]);
        }
    }
    if ($escala <= 0) { $escala = 5; }   // fallback razonable
    // ajuste estético: 6,7,8,9 → presentar como 10; 4 → 5
    if ($escala >= 6 && $escala <= 10) { $escala = 10; }
    elseif ($escala >= 11) { $escala = 100; }
    elseif ($escala <= 5)  { $escala = 5; }

    $dim_kpis = [];
    foreach (['bicicleta', 'estacion', 'app'] as $d) {
        if (empty($cols[$d])) { continue; }
        $prom = $row["prom_$d"];
        $n    = (int)$row["n_$d"];
        if ($n === 0 || $prom === null) {
            $dim_kpis[$d] = ['n' => 0, 'prom' => null, 'pct' => null];
        } else {
            $prom = (float)$prom;
            $dim_kpis[$d] = [
                'n'    => $n,
                'prom' => round($prom, 2),
                'pct'  => round(100 * $prom / $escala, 1),
            ];
        }
    }

    // === Distribuciones ===
    // una query por dimensión con GROUP BY
    $distribs = [];
    foreach (['bicicleta', 'estacion', 'app'] as $d) {
        if (empty($cols[$d])) { $distribs[$d] = []; continue; }
        $c = $cols[$d];
        $sql = "SELECT `$c` AS valor, COUNT(*) AS n
                FROM `$vista`
                WHERE $es_si AND `$c` IS NOT NULL $wfe
                GROUP BY `$c`
                ORDER BY `$c` ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = ['valor' => (float)$r['valor'], 'n' => (int)$r['n']];
        }
        $distribs[$d] = $out;
    }

    return [
        'vacio'          => false,
        'total'          => $total,
        'calificaron'    => $calif,
        'tasa_resp'      => $tasa,
        'escala'         => $escala,
        'dimensiones'    => $dim_kpis,
        'distribuciones' => $distribs,
        'cols_detectadas'=> $cols,
    ];
}

/* ============================================================
   ORQUESTADOR
   ============================================================ */

function qrb_construye_dataset_viajes(array $cfg, ?array $filtros = null): array
{
    $viajes = qrb_carga_viajes($cfg);
    qrb_normaliza_viajes($viajes);
    $total_original = count($viajes);

    // Aplica filtros opcionales para descartar anomalías
    $info_descartes = null;
    if ($filtros !== null && !empty($filtros['activos'])) {
        $r = qrb_filtra_anomalias($viajes, $filtros);
        $viajes = $r['viajes'];
        $info_descartes = $r['descartes'];
    }

    if (empty($viajes)) {
        return [
            'kpis'           => ['total_viajes' => 0],
            'vacio'          => true,
            'total_original' => $total_original,
            'descartes'      => $info_descartes,
        ];
    }

    $kpis = qrb_kpis_globales($viajes, $cfg);
    $temp = qrb_series_temporales($viajes);
    $rutas = qrb_rutas($viajes);

    // centro del mapa = promedio de coords de estación origen
    $sum_lat = 0; $sum_lng = 0; $n = 0;
    foreach ($viajes as $v) {
        $sum_lat += (float)$v['ESTACION_LATITUD_ORIGEN'];
        $sum_lng += (float)$v['ESTACION_LONGITUD_ORIGEN'];
        $n++;
    }

    return [
        'kpis'             => $kpis,
        'serie_dia'        => $temp['serie_dia'],
        'serie_hora'       => $temp['serie_hora'],
        'serie_dow'        => $temp['serie_dow'],
        'heat'             => $temp['heat'],
        'estaciones'       => qrb_estaciones($viajes),
        'pares_od'         => qrb_pares_od($viajes),
        'top_usuarios'     => qrb_top_usuarios($viajes),
        'freq_dist'        => qrb_frecuencia($viajes),
        'edad_dist'        => qrb_demografia($viajes)['edad_dist'],
        'sexo_dist'        => qrb_demografia($viajes)['sexo_dist'],
        'edad_sexo'        => qrb_demografia($viajes)['edad_sexo'],
        'plan_dist'        => qrb_planes_dist_viajes($viajes),
        'tipo_dist'        => qrb_tipos_dist($viajes),
        'tipo_hora'        => qrb_tipo_por_hora($viajes),
        'dur_dist'         => qrb_distribucion_duracion($viajes),
        'dist_dist'        => qrb_distribucion_distancia($viajes),
        'rutas'            => $rutas,
        'rutas_destacadas' => array_slice($rutas, 0, 6),
        'centro'           => [round($sum_lat / $n, 5), round($sum_lng / $n, 5)],
        'total_original'   => $total_original,
        'descartes'        => $info_descartes,
    ];
}
