<?php
/**
 * QroBici Analytics — Performance de bicicletas
 * ------------------------------------------------------------
 * Lee la vista `viajes` agrupando por bicicleta (ID_BICICLETA)
 * para producir KPIs operativos de la flota:
 *
 *   • Top usadas (km, viajes, horas)
 *   • Distribución por estatus operativo
 *   • Bicis ociosas (más días sin viajar)
 *   • Mantenimientos vs km (eficiencia)
 *   • Calidad percibida (calificación promedio por bici)
 *
 * Para no cargar todos los viajes a PHP, casi todo se calcula
 * en SQL con queries agregadas. Mucho más rápido cuando la flota
 * crece a miles de bicis con cientos de miles de viajes.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/* ============================================================
   QUERY AGREGADA PRINCIPAL: una fila por bicicleta
   ============================================================ */

/**
 * Agrupa todos los viajes del periodo por ID_BICICLETA y devuelve
 * los KPIs clave de cada una. Si una bici no tiene ID_BICICLETA,
 * cae al NUMERO_SERIE como identificador alterno.
 *
 * Cada fila trae:
 *   id, serie, tipo, estatus, mantenimientos,
 *   viajes, km_total, horas_total, dur_prom_min,
 *   primer_viaje, ultimo_viaje, dias_sin_uso,
 *   calif_bici_prom, calif_bici_n
 */
function qrb_bicis_carga(array $cfg): array
{
    $pdo   = qrb_db();
    $vista = $cfg['vistas']['viajes'];

    [$wfe, $params] = qrb_where_fecha('FECHA', $cfg['fecha_desde'], $cfg['fecha_hasta']);

    // Detectar columnas de calificación una vez (existencia tolerante)
    $cols_calif = qrb_calif_detecta_columnas($cfg);  // de lib_viajes.php
    $tiene_calif = !empty($cols_calif['flag']) && !empty($cols_calif['bicicleta']);

    $sql_calif = '';
    $sql_calif_n = '';
    if ($tiene_calif) {
        $flag = $cols_calif['flag'];
        $col  = $cols_calif['bicicleta'];
        $es_si = qrb_calif_sql_es_si($flag);
        $sql_calif   = ", AVG(CASE WHEN $es_si AND `$col` IS NOT NULL THEN `$col` END) AS calif_bici_prom";
        $sql_calif_n = ", COUNT(CASE WHEN $es_si AND `$col` IS NOT NULL THEN 1 END) AS calif_bici_n";
    }

    // Usamos MAX en lugar de ANY_VALUE: no requiere permisos especiales y
    // funciona en cualquier versión de MariaDB/MySQL.
    //   - serie, tipo, RFID: atributos físicos, mismo valor en todas las filas
    //     del grupo → MAX devuelve el único valor real.
    //   - mantenimientos: contador acumulado, MAX = el más reciente del periodo.
    //   - estatus: si cambia con el tiempo, MAX da el último alfabéticamente,
    //     que es determinista y suficiente para el reporte de flota.
    $sql = "SELECT
              COALESCE(ID_BICICLETA, 0) AS id,
              MAX(NUMERO_SERIE)         AS serie,
              MAX(TIPO_BICICLETA)       AS tipo,
              MAX(ESTATUS_BICICLETA)    AS estatus,
              MAX(MANTENIMIENTOS)       AS mantenimientos,
              COUNT(*)                  AS viajes,
              SUM(DISTANCIA)            AS dist_m,
              SUM(DURACION)             AS dur_s,
              MIN(FECHA)                AS primer_viaje,
              MAX(FECHA)                AS ultimo_viaje
              $sql_calif
              $sql_calif_n
            FROM `$vista`
            WHERE 1=1 $wfe
            GROUP BY COALESCE(ID_BICICLETA, 0)
            ORDER BY viajes DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $now = time();
    $out = [];
    foreach ($rows as $r) {
        $ultimo_ts = $r['ultimo_viaje'] ? strtotime($r['ultimo_viaje']) : null;
        $dias_sin  = $ultimo_ts ? (int)floor(($now - $ultimo_ts) / 86400) : null;
        $dist_m = (int)$r['dist_m'];
        $dur_s  = (int)$r['dur_s'];
        $viajes = (int)$r['viajes'];
        $out[] = [
            'id'             => (int)$r['id'],
            'serie'          => (string)($r['serie'] ?? ''),
            'tipo'           => (string)($r['tipo'] ?? ''),
            'estatus'        => (string)($r['estatus'] ?? ''),
            'mantenimientos' => (int)($r['mantenimientos'] ?? 0),
            'viajes'         => $viajes,
            'km_total'       => round($dist_m / 1000, 1),
            'horas_total'    => round($dur_s / 3600, 1),
            'dur_prom_min'   => $viajes > 0 ? round(($dur_s / $viajes) / 60, 1) : 0,
            'km_prom'        => $viajes > 0 ? round(($dist_m / 1000) / $viajes, 2) : 0,
            'primer_viaje'   => $r['primer_viaje'],
            'ultimo_viaje'   => $r['ultimo_viaje'],
            'dias_sin_uso'   => $dias_sin,
            'calif_prom'     => isset($r['calif_bici_prom']) && $r['calif_bici_prom'] !== null
                                ? round((float)$r['calif_bici_prom'], 2) : null,
            'calif_n'        => isset($r['calif_bici_n']) ? (int)$r['calif_bici_n'] : 0,
            // ratios operativos
            'km_por_mant'    => ((int)$r['mantenimientos']) > 0
                                ? round(($dist_m / 1000) / (int)$r['mantenimientos'], 1) : null,
            'viajes_por_mant'=> ((int)$r['mantenimientos']) > 0
                                ? round($viajes / (int)$r['mantenimientos'], 1) : null,
        ];
    }
    return $out;
}

/* ============================================================
   KPIs DE LA FLOTA
   ============================================================ */

function qrb_bicis_kpis(array $bicis): array
{
    if (empty($bicis)) {
        return ['total_bicis' => 0];
    }
    $n           = count($bicis);
    $km_tot      = 0; $viajes_tot = 0; $hrs_tot = 0; $mant_tot = 0;
    $n_elec = 0; $n_mec = 0;
    $califs = []; $califs_n = 0;
    $viajes_arr = [];
    foreach ($bicis as $b) {
        $km_tot     += $b['km_total'];
        $viajes_tot += $b['viajes'];
        $hrs_tot    += $b['horas_total'];
        $mant_tot   += $b['mantenimientos'];
        $tipo_norm = strtoupper(strtr($b['tipo'] ?? '',
            ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
             'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U']));
        if (strpos($tipo_norm, 'ELEC') !== false) { $n_elec++; }
        elseif (strpos($tipo_norm, 'MECA') !== false || strpos($tipo_norm, 'MECANIC') !== false) { $n_mec++; }
        if ($b['calif_prom'] !== null) {
            $califs[] = $b['calif_prom'] * $b['calif_n'];
            $califs_n += $b['calif_n'];
        }
        $viajes_arr[] = $b['viajes'];
    }
    // bicicletas ociosas (>30 días sin uso o nunca usadas)
    $ociosas = 0;
    foreach ($bicis as $b) {
        if ($b['dias_sin_uso'] === null || $b['dias_sin_uso'] > 30) { $ociosas++; }
    }
    // mediana de viajes
    sort($viajes_arr);
    $med = $viajes_arr[(int)floor($n / 2)] ?? 0;

    return [
        'total_bicis'      => $n,
        'km_total_flota'   => round($km_tot, 1),
        'viajes_total'     => $viajes_tot,
        'horas_total_flota'=> round($hrs_tot, 1),
        'mantenimientos_total' => $mant_tot,
        'tipo_electrica'   => $n_elec,
        'tipo_mecanica'    => $n_mec,
        'km_prom_por_bici' => $n > 0 ? round($km_tot / $n, 1) : 0,
        'viajes_prom_por_bici' => $n > 0 ? round($viajes_tot / $n, 1) : 0,
        'viajes_mediana'   => $med,
        'mant_prom_por_bici'   => $n > 0 ? round($mant_tot / $n, 2) : 0,
        'bicis_ociosas_30d'    => $ociosas,
        'pct_ociosas'      => $n > 0 ? round(100 * $ociosas / $n, 1) : 0,
        'calif_global_prom'    => $califs_n > 0
                                  ? round(array_sum($califs) / $califs_n, 2) : null,
        'calif_global_n'   => $califs_n,
        'km_por_mant_global'   => $mant_tot > 0 ? round($km_tot / $mant_tot, 1) : null,
    ];
}

/* ============================================================
   DISTRIBUCIONES
   ============================================================ */

function qrb_bicis_por_estatus(array $bicis): array
{
    $cnt = [];
    foreach ($bicis as $b) {
        $k = trim($b['estatus'] ?? '') ?: 'Sin estatus';
        $cnt[$k] = ($cnt[$k] ?? 0) + 1;
    }
    arsort($cnt);
    $out = [];
    foreach ($cnt as $k => $v) { $out[] = ['estatus' => $k, 'bicis' => $v]; }
    return $out;
}

function qrb_bicis_por_tipo(array $bicis): array
{
    $cnt = [];
    foreach ($bicis as $b) {
        $k = trim($b['tipo'] ?? '') ?: 'Sin tipo';
        $cnt[$k] = ($cnt[$k] ?? 0) + 1;
    }
    arsort($cnt);
    $out = [];
    foreach ($cnt as $k => $v) { $out[] = ['tipo' => $k, 'bicis' => $v]; }
    return $out;
}

/**
 * Distribución de uso: histograma de viajes por bici.
 */
function qrb_bicis_dist_uso(array $bicis): array
{
    $bins = [
        [0,   0,    '0 (ociosa)'],
        [1,   5,    '1-5'],
        [6,   20,   '6-20'],
        [21,  50,   '21-50'],
        [51,  100,  '51-100'],
        [101, 250,  '101-250'],
        [251, 99999,'250+'],
    ];
    $out = array_map(fn($b) => ['rango' => $b[2], 'cantidad' => 0], $bins);
    foreach ($bicis as $b) {
        $v = $b['viajes'];
        foreach ($bins as $i => $bb) {
            if ($v >= $bb[0] && $v <= $bb[1]) { $out[$i]['cantidad']++; break; }
        }
    }
    return $out;
}

/**
 * Distribución de mantenimientos por bicicleta.
 */
function qrb_bicis_dist_mantenimientos(array $bicis): array
{
    $bins = [
        [0, 0,   '0'],
        [1, 1,   '1'],
        [2, 3,   '2-3'],
        [4, 6,   '4-6'],
        [7, 10,  '7-10'],
        [11, 99999, '11+'],
    ];
    $out = array_map(fn($b) => ['rango' => $b[2], 'cantidad' => 0], $bins);
    foreach ($bicis as $b) {
        $m = $b['mantenimientos'];
        foreach ($bins as $i => $bb) {
            if ($m >= $bb[0] && $m <= $bb[1]) { $out[$i]['cantidad']++; break; }
        }
    }
    return $out;
}

/* ============================================================
   TOPS Y LISTADOS
   ============================================================ */

function qrb_bicis_top_usadas(array $bicis, int $limite = 20): array
{
    $copia = $bicis;
    usort($copia, fn($a, $b) => $b['km_total'] <=> $a['km_total']);
    return array_slice($copia, 0, $limite);
}

function qrb_bicis_ociosas(array $bicis, int $limite = 20): array
{
    // ordenar por días sin uso descendente, las "nunca usadas" (null) al final
    $copia = $bicis;
    usort($copia, function ($a, $b) {
        $da = $a['dias_sin_uso'] ?? -1;
        $db = $b['dias_sin_uso'] ?? -1;
        return $db <=> $da;
    });
    // filtra a las que tienen al menos 7 días sin uso o nunca usadas
    $out = [];
    foreach ($copia as $b) {
        if ($b['dias_sin_uso'] === null || $b['dias_sin_uso'] >= 7) {
            $out[] = $b;
            if (count($out) >= $limite) { break; }
        }
    }
    return $out;
}

function qrb_bicis_mas_mantenidas(array $bicis, int $limite = 15): array
{
    $copia = array_filter($bicis, fn($b) => $b['mantenimientos'] > 0);
    usort($copia, fn($a, $b) => $b['mantenimientos'] <=> $a['mantenimientos']);
    return array_slice($copia, 0, $limite);
}

function qrb_bicis_peor_calificadas(array $bicis, int $limite = 10): array
{
    // solo bicis con al menos 5 calificaciones para que sea significativo
    $copia = array_filter($bicis, fn($b) => $b['calif_prom'] !== null && $b['calif_n'] >= 5);
    usort($copia, fn($a, $b) => $a['calif_prom'] <=> $b['calif_prom']);
    return array_slice($copia, 0, $limite);
}

/* ============================================================
   SCATTER PARA MANTENIMIENTOS vs KM
   ============================================================ */

/**
 * Devuelve puntos para un scatter plot km_total vs mantenimientos.
 * Útil para detectar outliers: bicis con muchos km y pocos mantenimientos
 * (vienen sus mantenimientos) y bicis con muchos mantenimientos pero pocos km
 * (¿problemáticas?).
 */
function qrb_bicis_scatter_km_mant(array $bicis): array
{
    $out = [];
    foreach ($bicis as $b) {
        if ($b['km_total'] <= 0 && $b['mantenimientos'] <= 0) { continue; }
        $out[] = [
            'id'   => $b['id'],
            'km'   => $b['km_total'],
            'mant' => $b['mantenimientos'],
            'viajes' => $b['viajes'],
            'tipo' => $b['tipo'],
        ];
    }
    return $out;
}

/* ============================================================
   INSIGHTS
   ============================================================ */

function qrb_bicis_insights(array $kpis, array $bicis): array
{
    $insights = [];

    if (($kpis['pct_ociosas'] ?? 0) >= 25) {
        $insights[] = [
            'icon'  => '🛏️',
            'level' => 'alta',
            'texto' => "El <b>{$kpis['pct_ociosas']}%</b> de la flota está ociosa (más de 30 días sin un viaje, o nunca usada en el periodo). "
                     . "Son <b>{$kpis['bicis_ociosas_30d']}</b> bicicletas sin generar valor — vale la pena auditar mantenimiento, ubicación o redistribución.",
        ];
    }

    $top = qrb_bicis_top_usadas($bicis, 5);
    if (count($top) > 0 && $top[0]['km_total'] > 0) {
        $insights[] = [
            'icon'  => '🏆',
            'level' => 'info',
            'texto' => "La bicicleta más usada acumula <b>" . number_format($top[0]['km_total'], 1) . " km</b> "
                     . "en <b>" . number_format($top[0]['viajes']) . " viajes</b>. "
                     . "Las top 5 representan en conjunto <b>" . number_format(array_sum(array_column($top, 'km_total')), 1) . " km</b>.",
        ];
    }

    if (($kpis['km_por_mant_global'] ?? null) !== null) {
        $kmpm = $kpis['km_por_mant_global'];
        $msg  = "La flota promedia <b>" . number_format($kmpm) . " km por mantenimiento</b>. ";
        if ($kmpm < 200)      { $msg .= "Es un ratio muy bajo — investigar fallas recurrentes."; }
        elseif ($kmpm < 500)  { $msg .= "Ratio típico de operación intensiva; mantén el calendario preventivo."; }
        else                  { $msg .= "Ratio holgado — la flota tiene buen desempeño antes de requerir intervención."; }
        $insights[] = ['icon' => '🔧', 'level' => 'info', 'texto' => $msg];
    }

    if (($kpis['calif_global_prom'] ?? null) !== null) {
        $c = $kpis['calif_global_prom'];
        $insights[] = [
            'icon'  => '⭐',
            'level' => $c < 3 ? 'alta' : 'info',
            'texto' => "Calificación promedio del estado de las bicicletas: <b>" . number_format($c, 2)
                     . "</b> (sobre " . number_format($kpis['calif_global_n']) . " respuestas). "
                     . ($c < 3
                        ? "Es bajo — los usuarios perciben problemas físicos en las bicis."
                        : "Es saludable; usuarios cómodos con el estado físico de la flota."),
        ];
    }

    $mas_mant = qrb_bicis_mas_mantenidas($bicis, 1);
    if (!empty($mas_mant) && $mas_mant[0]['mantenimientos'] >= 5) {
        $m = $mas_mant[0];
        $insights[] = [
            'icon'  => '⚠️',
            'level' => 'media',
            'texto' => "La bicicleta con más intervenciones es la <b>#{$m['id']}</b> ({$m['serie']}) con <b>{$m['mantenimientos']} mantenimientos</b>. "
                     . "Si ha cargado solo " . number_format($m['km_total'], 1) . " km, conviene evaluar reemplazo de partes o baja definitiva.",
        ];
    }

    if (empty($insights)) {
        $insights[] = ['icon' => '✅', 'level' => 'info',
            'texto' => 'Métricas operativas dentro de rangos saludables. Sin alertas críticas detectadas.'];
    }

    return $insights;
}

/* ============================================================
   ORQUESTADOR
   ============================================================ */

function qrb_construye_dataset_bicis(array $cfg): array
{
    require_once __DIR__ . '/lib_viajes.php';   // para qrb_calif_*
    $bicis = qrb_bicis_carga($cfg);
    if (empty($bicis)) {
        return ['vacio' => true, 'mensaje' => 'No hay viajes en el periodo configurado.'];
    }
    $kpis = qrb_bicis_kpis($bicis);
    return [
        'vacio'           => false,
        'kpis'            => $kpis,
        'bicis'           => $bicis,
        'por_estatus'     => qrb_bicis_por_estatus($bicis),
        'por_tipo'        => qrb_bicis_por_tipo($bicis),
        'dist_uso'        => qrb_bicis_dist_uso($bicis),
        'dist_mant'       => qrb_bicis_dist_mantenimientos($bicis),
        'top_usadas'      => qrb_bicis_top_usadas($bicis, 20),
        'ociosas'         => qrb_bicis_ociosas($bicis, 15),
        'mas_mantenidas'  => qrb_bicis_mas_mantenidas($bicis, 12),
        'peor_calif'      => qrb_bicis_peor_calificadas($bicis, 10),
        'scatter_km_mant' => qrb_bicis_scatter_km_mant($bicis),
        'insights'        => qrb_bicis_insights($kpis, $bicis),
    ];
}
