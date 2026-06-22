<?php
/**
 * Métricas electorales por sección — extraído de rentabilidad.php para reusarse
 * también en el cruce con padrón/Zendesk. El partido objetivo es CONFIGURABLE:
 * variable de entorno PARTIDO_OBJETIVO (default 'PAN').
 *
 * metricas_por_seccion() devuelve [num_seccion => row] con voto efectivo,
 * válidos, emitidos, lista_nominal, rentabilidad % y participación %.
 */
declare(strict_types=1);

if (!function_exists('electoral_partido_objetivo')) {
    function electoral_partido_objetivo(): string {
        $p = function_exists('env_str') ? env_str('PARTIDO_OBJETIVO', 'PAN') : (getenv('PARTIDO_OBJETIVO') ?: 'PAN');
        return $p !== '' ? $p : 'PAN';
    }
}

if (!function_exists('metricas_por_seccion')) {
    function metricas_por_seccion(PDO $pdo, int $procesoId, int $tipoId, ?string $ambitoCode = null, ?string $siglas = null): array
    {
        $siglas = $siglas ?: electoral_partido_objetivo();

        // Coaliciones que incluyen al partido objetivo en este proceso/tipo
        $stmt = $pdo->prepare(
            "SELECT DISTINCT c.codigo FROM coaliciones c
               JOIN coaliciones_partidos cp ON cp.coalicion_id=c.id
               JOIN partidos p ON p.id=cp.partido_id
               JOIN elecciones e ON e.id=c.eleccion_id
              WHERE p.siglas=? AND e.proceso_id=? AND e.tipo_id=?"
        );
        $stmt->execute([$siglas, $procesoId, $tipoId]);
        $panCoal   = array_column($stmt->fetchAll(), 'codigo');
        $allPan    = array_merge([$siglas], $panCoal);
        $allPanCsv = "'" . implode("','", array_map(fn($c) => addslashes($c), $allPan)) . "'";
        $sigEsc    = addslashes($siglas);

        $where  = ["e.proceso_id=:proc", "e.tipo_id=:tipo"];
        $params = [':proc' => $procesoId, ':tipo' => $tipoId];
        if ($ambitoCode !== null && $ambitoCode !== '') { $where[] = "e.ambito_codigo=:amb"; $params[':amb'] = $ambitoCode; }
        $whereSql = implode(' AND ', $where);

        $sql = "
          SELECT cas.num_seccion, e.ambito_codigo, e.ambito_nombre,
            SUM(CASE WHEN rc.voto_codigo='$sigEsc' THEN rc.votos ELSE 0 END) AS pan_solo,
            SUM(CASE WHEN rc.voto_codigo IN ($allPanCsv) AND rc.voto_codigo!='$sigEsc' THEN rc.votos ELSE 0 END) AS pan_coal,
            SUM(CASE WHEN rc.voto_codigo IN ($allPanCsv) THEN rc.votos ELSE 0 END) AS voto_efectivo,
            SUM(CASE WHEN rc.voto_codigo NOT IN ('NULOS','NO_REGISTRADAS') THEN rc.votos ELSE 0 END) AS validos,
            SUM(rc.votos) AS emitidos,
            COALESCE((SELECT SUM(rcm.lista_nominal) FROM resultados_casilla_meta rcm
                        JOIN casillas cas2 ON cas2.id=rcm.casilla_id
                       WHERE cas2.num_seccion=cas.num_seccion AND rcm.eleccion_id=e.id), 0) AS lista_nominal,
            COUNT(DISTINCT rc.casilla_id) AS n_casillas
          FROM resultados_casilla rc
          JOIN casillas cas ON cas.id=rc.casilla_id
          JOIN elecciones e ON e.id=rc.eleccion_id
          WHERE $whereSql
          GROUP BY cas.num_seccion, e.ambito_codigo, e.ambito_nombre
          HAVING validos > 0
        ";
        $st = $pdo->prepare($sql);
        $st->execute($params);

        $out = [];
        foreach ($st as $r) {
            $sec = (int)$r['num_seccion'];
            $r['rentabilidad']  = $r['validos'] > 0 ? ($r['voto_efectivo'] / $r['validos']) * 100 : 0;
            $r['participacion'] = $r['lista_nominal'] > 0 ? ($r['emitidos'] / $r['lista_nominal']) * 100 : 0;
            $out[$sec] = $r;
        }
        return ['rows' => $out, 'panCoal' => $panCoal];
    }
}
