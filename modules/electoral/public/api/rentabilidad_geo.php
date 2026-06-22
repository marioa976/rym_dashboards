<?php
/**
 * API GeoJSON — polígonos de secciones coloreados por rentabilidad PAN
 * para una elección dada (proceso × tipo).
 *
 * GET /api/rentabilidad_geo.php?proceso_id=2&tipo_id=2&ambito_codigo=
 *
 * Devuelve FeatureCollection con properties:
 *   - num_seccion, ambito_codigo, ambito_nombre
 *   - voto_efectivo, votos_validos, rentabilidad
 *   - rent_prev, delta_rent (si hay elección anterior comparable)
 *   - estructura (red ciudadana por sección)
 */

ini_set('display_errors', '0');
error_reporting(E_ERROR | E_PARSE);
ob_start();

try {
    require_once __DIR__ . '/../../lib/bootstrap.php';
    require_once __DIR__ . '/../../lib/geo.php';

    if (!geo_schema_ready()) throw new RuntimeException('Esquema geográfico no instalado');

    $pdo = reporteador_pdo();
    $procesoId  = (int)($_GET['proceso_id'] ?? 0);
    $tipoId     = (int)($_GET['tipo_id'] ?? 0);
    $ambitoCode = trim($_GET['ambito_codigo'] ?? '');
    $secSearch  = trim($_GET['sec_search'] ?? '');
    if (!$procesoId || !$tipoId) throw new RuntimeException('Faltan proceso_id o tipo_id');

    // Coaliciones PAN para esta elección
    $st = $pdo->prepare(
        "SELECT DISTINCT c.codigo FROM coaliciones c
           JOIN coaliciones_partidos cp ON cp.coalicion_id=c.id
           JOIN partidos p ON p.id=cp.partido_id
           JOIN elecciones e ON e.id=c.eleccion_id
          WHERE p.siglas='PAN' AND e.proceso_id=? AND e.tipo_id=?"
    );
    $st->execute([$procesoId, $tipoId]);
    $coal = array_column($st->fetchAll(), 'codigo');
    $allPan = array_merge(['PAN'], $coal);
    $allPanCsv = "'" . implode("','", array_map(fn($c)=>addslashes($c), $allPan)) . "'";

    // Métricas por sección (elección actual)
    $where = ["e.proceso_id=:proc", "e.tipo_id=:tipo"];
    $params = [':proc'=>$procesoId, ':tipo'=>$tipoId];
    if ($ambitoCode !== '') { $where[] = "e.ambito_codigo=:amb"; $params[':amb']=$ambitoCode; }
    if ($secSearch !== '' && is_numeric($secSearch)) {
        $where[] = "cas.num_seccion=:sec";
        $params[':sec'] = (int)$secSearch;
    }
    $whereSql = implode(' AND ', $where);

    $sql = "
      SELECT cas.num_seccion, e.ambito_codigo, e.ambito_nombre,
             SUM(CASE WHEN rc.voto_codigo IN ($allPanCsv) THEN rc.votos ELSE 0 END) AS voto_efectivo,
             SUM(CASE WHEN rc.voto_codigo NOT IN ('NULOS','NO_REGISTRADAS') THEN rc.votos ELSE 0 END) AS validos,
             SUM(rc.votos) AS emitidos
        FROM resultados_casilla rc
        JOIN casillas cas ON cas.id=rc.casilla_id
        JOIN elecciones e ON e.id=rc.eleccion_id
       WHERE $whereSql
    GROUP BY cas.num_seccion, e.ambito_codigo, e.ambito_nombre
      HAVING validos > 0";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $bySec = [];
    foreach ($st as $r) {
        $sec = (int)$r['num_seccion'];
        $rent = $r['validos']>0 ? ($r['voto_efectivo']/$r['validos'])*100 : 0;
        $bySec[$sec] = [
            'voto_efectivo' => (int)$r['voto_efectivo'],
            'validos'       => (int)$r['validos'],
            'emitidos'      => (int)$r['emitidos'],
            'rentabilidad'  => $rent,
            'ambito_codigo' => $r['ambito_codigo'],
            'ambito_nombre' => $r['ambito_nombre'],
            'desglose'      => [],  // se llena abajo
            'lista_nominal' => 0,   // se llena abajo
        ];
    }

    // Desglose por voto_codigo (PAN, PRI, coaliciones, NULOS, etc.)
    $sql = "
      SELECT cas.num_seccion, rc.voto_codigo, SUM(rc.votos) AS votos
        FROM resultados_casilla rc
        JOIN casillas cas ON cas.id=rc.casilla_id
        JOIN elecciones e ON e.id=rc.eleccion_id
       WHERE $whereSql
    GROUP BY cas.num_seccion, rc.voto_codigo";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    foreach ($st as $r) {
        $sec = (int)$r['num_seccion'];
        if (!isset($bySec[$sec])) continue;
        $bySec[$sec]['desglose'][(string)$r['voto_codigo']] = (int)$r['votos'];
    }

    // Lista nominal por sección (suma del meta de cada casilla)
    $sql = "
      SELECT cas.num_seccion, SUM(rcm.lista_nominal) AS lista_nominal
        FROM resultados_casilla_meta rcm
        JOIN casillas cas ON cas.id = rcm.casilla_id
        JOIN elecciones e ON e.id = rcm.eleccion_id
       WHERE $whereSql
    GROUP BY cas.num_seccion";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    foreach ($st as $r) {
        $sec = (int)$r['num_seccion'];
        if (!isset($bySec[$sec])) continue;
        $bySec[$sec]['lista_nominal'] = (int)$r['lista_nominal'];
    }

    // Comparativo año anterior (mismo tipo) si existe
    $st = $pdo->prepare(
        "SELECT pe.id FROM procesos_electorales pe
          WHERE pe.anio < (SELECT anio FROM procesos_electorales WHERE id=?)
            AND pe.nivel = (SELECT nivel FROM procesos_electorales WHERE id=?)
       ORDER BY pe.anio DESC LIMIT 1"
    );
    $st->execute([$procesoId, $procesoId]);
    $prevProcId = (int)$st->fetchColumn();

    $bySecPrev = [];
    if ($prevProcId) {
        // Verificar que existe el tipo
        $st = $pdo->prepare("SELECT 1 FROM elecciones WHERE proceso_id=? AND tipo_id=? LIMIT 1");
        $st->execute([$prevProcId, $tipoId]);
        if ($st->fetchColumn()) {
            // Coaliciones PAN del proceso anterior (pueden diferir)
            $st = $pdo->prepare(
                "SELECT DISTINCT c.codigo FROM coaliciones c
                   JOIN coaliciones_partidos cp ON cp.coalicion_id=c.id
                   JOIN partidos p ON p.id=cp.partido_id
                   JOIN elecciones e ON e.id=c.eleccion_id
                  WHERE p.siglas='PAN' AND e.proceso_id=? AND e.tipo_id=?"
            );
            $st->execute([$prevProcId, $tipoId]);
            $coalPrev = array_column($st->fetchAll(), 'codigo');
            $allPanPrev = array_merge(['PAN'], $coalPrev);
            $allPanPrevCsv = "'" . implode("','", array_map(fn($c)=>addslashes($c), $allPanPrev)) . "'";

            $sql = "
              SELECT cas.num_seccion,
                     SUM(CASE WHEN rc.voto_codigo IN ($allPanPrevCsv) THEN rc.votos ELSE 0 END) AS voto_efectivo,
                     SUM(CASE WHEN rc.voto_codigo NOT IN ('NULOS','NO_REGISTRADAS') THEN rc.votos ELSE 0 END) AS validos
                FROM resultados_casilla rc
                JOIN casillas cas ON cas.id=rc.casilla_id
                JOIN elecciones e ON e.id=rc.eleccion_id
               WHERE e.proceso_id=? AND e.tipo_id=?
            GROUP BY cas.num_seccion HAVING validos > 0";
            $st = $pdo->prepare($sql);
            $st->execute([$prevProcId, $tipoId]);
            foreach ($st as $r) {
                $bySecPrev[(int)$r['num_seccion']] = $r['validos']>0 ? ($r['voto_efectivo']/$r['validos'])*100 : 0;
            }
        }
    }

    $estructura = []; // sin cruce con red ciudadana en este módulo

    // Tipo de ámbito (estado | municipio | distrito | seccion)
    $st = $pdo->prepare("SELECT ambito FROM tipos_eleccion WHERE id=?");
    $st->execute([$tipoId]);
    $tipoAmbito = (string)$st->fetchColumn();

    // Polígonos del IEEQ — pueden haber duplicados por num_seccion (mismo número en
    // distintos municipios). Traemos todos y luego elegimos el correcto por sección.
    $polysBySec = [];
    if (!empty($bySec)) {
        $secsCsv = implode(',', array_map('intval', array_keys($bySec)));
        $st = $pdo->query(
            "SELECT s.num_seccion, s.municipio, s.col_localidad,
                    d.numero AS distrito_num,
                    ST_AsGeoJSON(g.geom, 6) AS gj
               FROM secciones s
               JOIN secciones_geo g ON g.seccion_id = s.id
          LEFT JOIN distritos d ON d.id = s.distrito_id
              WHERE s.num_seccion IN ($secsCsv)"
        );
        foreach ($st as $r) $polysBySec[(int)$r['num_seccion']][] = $r;
    }

    // Helper para normalizar nombres (uppercase + sin acentos)
    $norm = function (?string $s): string {
        return mb_strtoupper(strtr((string)$s, [
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N',
            'á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U','ñ'=>'N',
        ]), 'UTF-8');
    };

    $features = [];
    foreach ($bySec as $sec => $data) {
        if (!isset($polysBySec[$sec])) continue;
        $polys = $polysBySec[$sec];
        $chosen = null;

        if ($tipoAmbito === 'municipio' && !empty($data['ambito_nombre'])) {
            // Match estricto: polígono físicamente en el municipio de la elección.
            // Si no coincide (= votos vinieron de una casilla especial), se omite
            // del mapa — siguen sumando en KPIs y tabla.
            $target = $norm($data['ambito_nombre']);
            foreach ($polys as $p) {
                if ($norm($p['municipio']) === $target) { $chosen = $p; break; }
            }
            if (!$chosen) continue;
        } elseif ($tipoAmbito === 'distrito' && $data['ambito_codigo'] !== null && $data['ambito_codigo'] !== '') {
            // Match estricto por número de distrito
            $tgt = (int)$data['ambito_codigo'];
            foreach ($polys as $p) {
                if ((int)$p['distrito_num'] === $tgt) { $chosen = $p; break; }
            }
            if (!$chosen) continue;
        } else {
            // Estado o ámbito desconocido: el primer polígono disponible
            $chosen = $polys[0];
        }

        $rent = $bySec[$sec]['rentabilidad'];
        $rentPrev = $bySecPrev[$sec] ?? null;
        $delta    = $rentPrev !== null ? $rent - $rentPrev : null;
        $geom = json_decode($chosen['gj'], true);
        if (!$geom) continue;

        // Parsear lista de colonias (vienen separadas por coma/punto y coma)
        $colonias = [];
        if (!empty($chosen['col_localidad'])) {
            $parts = preg_split('/[,;]/u', (string)$chosen['col_localidad']);
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p !== '') $colonias[] = $p;
            }
        }

        // Desglose ordenado por votos (mayor a menor) para mostrar en popup
        $desglose = $bySec[$sec]['desglose'] ?? [];
        arsort($desglose);

        $ln = $bySec[$sec]['lista_nominal'];
        $emitidos = $bySec[$sec]['emitidos'];
        $participacion = $ln > 0 ? ($emitidos / $ln) * 100 : null;

        $features[] = [
            'type' => 'Feature',
            'geometry' => $geom,
            'properties' => [
                'num_seccion'    => $sec,
                'municipio'      => $chosen['municipio'],
                'distrito_num'   => $chosen['distrito_num'],
                'colonias'       => $colonias,
                'ambito_codigo'  => $bySec[$sec]['ambito_codigo'],
                'ambito_nombre'  => $bySec[$sec]['ambito_nombre'],
                'voto_efectivo'  => $bySec[$sec]['voto_efectivo'],
                'votos_validos'  => $bySec[$sec]['validos'],
                'votos_emitidos' => $emitidos,
                'lista_nominal'  => $ln,
                'participacion'  => $participacion !== null ? round($participacion, 2) : null,
                'desglose'       => $desglose, // {voto_codigo: votos}
                'rentabilidad'   => round($rent, 2),
                'rent_prev'      => $rentPrev !== null ? round($rentPrev, 2) : null,
                'delta_rent'     => $delta !== null ? round($delta, 2) : null,
                'estructura'     => $estructura[$sec] ?? 0,
            ],
        ];
    }

    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'type' => 'FeatureCollection',
        'features' => $features,
        'meta' => [
            'proceso_id' => $procesoId, 'tipo_id' => $tipoId,
            'pan_coalitions' => $coal,
            'prev_proceso_id' => $prevProcId,
            'n_secs' => count($features),
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $e->getMessage()]);
}
