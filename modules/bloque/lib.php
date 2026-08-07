<?php
/**
 * Bloque · librería (esquema NUEVO). Edificio de innovación: usuarios, eventos,
 * sesiones y asistencia. Todo en portal_qro.
 *
 * Tablas:
 *   bloque_usuario(id, sNombre, sPaterno, sMaterno, sCurp, sDelegacion, ...,
 *                  sEmpresa, sTransporte, dLatitud, dLongitud, dFechaCrea)
 *   bloque_evento(id, sNombre, dFechaInicio, dFechaFin, iCupo, dLatitud, dLongitud)
 *   bloque_sesion(iEventoId, iConsecutivo, sNombre, dFechaInicio, dFechaFin, iCupo)   [PK compuesta]
 *   bloque_invitado(id, iUsuarioId, sNombre, sPaterno, sMaterno, sCurp)   [1:1 con usuario]
 *   bloque_evento_invitado(id, iEventoId, iInvitadoId, iSesionId, dFecha)  [asistencia]
 *     iSesionId referencia bloque_sesion.iConsecutivo dentro del mismo iEventoId.
 */
declare(strict_types=1);

function bloq_config(): array {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $c;
}

function bloq_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $db = bloq_config()['db'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'], (int)$db['port'], $db['name'], $db['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
    ]);
    return $pdo;
}

/** KPIs globales del módulo. */
function bloq_kpis(PDO $pdo): array {
    $q = fn(string $s) => $pdo->query($s)->fetchColumn();
    return [
        'usuarios'    => (int)$q("SELECT COUNT(*) FROM bloque_usuario"),
        'eventos'     => (int)$q("SELECT COUNT(*) FROM bloque_evento"),
        'sesiones'    => (int)$q("SELECT COUNT(*) FROM bloque_sesion"),
        'registros'   => (int)$q("SELECT COUNT(*) FROM bloque_evento_invitado"),
        'asistentes'  => (int)$q("SELECT COUNT(DISTINCT iInvitadoId) FROM bloque_evento_invitado"),
        'cupo_total'  => (int)$q("SELECT COALESCE(SUM(iCupo),0) FROM bloque_evento"),
    ];
}

/**
 * Demografía a partir del CURP: sexo y rangos de edad. Se parsea en PHP para
 * manejar bien el siglo (posición 17: letra=1900s, dígito=2000s).
 * Devuelve ['sexo'=>[...], 'edad'=>[rango=>n], 'edad_prom'=>x].
 */
function bloq_demografia(PDO $pdo): array {
    $hoy = new DateTimeImmutable(date('Y-m-d'));
    $sexo = ['Hombres'=>0,'Mujeres'=>0,'N/D'=>0];
    $rangos = ['<18'=>0,'18-25'=>0,'26-35'=>0,'36-45'=>0,'46-59'=>0,'60+'=>0,'N/D'=>0];
    $sumEdad = 0; $nEdad = 0;
    foreach ($pdo->query("SELECT sCurp FROM bloque_usuario") as $r) {
        $c = strtoupper(trim((string)$r['sCurp']));
        if (preg_match('/^[A-Z]{4}[0-9]{6}([HM])/', $c, $m)) {
            $sexo[$m[1] === 'H' ? 'Hombres' : 'Mujeres']++;
        } else { $sexo['N/D']++; }
        if (preg_match('/^[A-Z]{4}([0-9]{2})([0-9]{2})([0-9]{2})/', $c, $m)) {
            $yy=(int)$m[1]; $mm=(int)$m[2]; $dd=(int)$m[3];
            $siglo = (strlen($c) >= 17 && ctype_digit($c[16])) ? 2000 : 1900;
            $anio = $siglo + $yy;
            if ($mm>=1 && $mm<=12 && $dd>=1 && $dd<=31) {
                try {
                    $nac = new DateTimeImmutable(sprintf('%04d-%02d-%02d',$anio,$mm,$dd));
                    $edad = (int)$nac->diff($hoy)->y;
                    if ($edad>=0 && $edad<=110) {
                        $sumEdad += $edad; $nEdad++;
                        $r2 = $edad<18?'<18':($edad<26?'18-25':($edad<36?'26-35':($edad<46?'36-45':($edad<60?'46-59':'60+'))));
                        $rangos[$r2]++; continue;
                    }
                } catch (Throwable $e) {}
            }
            $rangos['N/D']++;
        } else { $rangos['N/D']++; }
    }
    return ['sexo'=>$sexo, 'edad'=>$rangos, 'edad_prom'=>$nEdad?round($sumEdad/$nEdad,1):null];
}

/** Usuarios por delegación/municipio (texto tal cual, saneado). */
function bloq_por_delegacion(PDO $pdo, int $limite = 15): array {
    $sql = "SELECT COALESCE(NULLIF(TRIM(sDelegacion),''),'(sin dato)') d, COUNT(*) n
              FROM bloque_usuario GROUP BY d ORDER BY n DESC LIMIT " . (int)$limite;
    return $pdo->query($sql)->fetchAll();
}

/**
 * Eventos con métricas de asistencia y ocupación.
 * asistentes = invitados distintos que registraron asistencia al evento.
 */
function bloq_eventos(PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT e.id, e.sNombre, e.dFechaInicio, e.dFechaFin, e.iCupo,
                (SELECT COUNT(*) FROM bloque_sesion s WHERE s.iEventoId=e.id) sesiones,
                (SELECT COUNT(*) FROM bloque_evento_invitado ei WHERE ei.iEventoId=e.id) registros,
                (SELECT COUNT(DISTINCT ei.iInvitadoId) FROM bloque_evento_invitado ei WHERE ei.iEventoId=e.id) asistentes
           FROM bloque_evento e
          ORDER BY e.dFechaInicio DESC, e.id DESC"
    )->fetchAll();
    foreach ($rows as &$r) {
        $cupo = (int)$r['iCupo'];
        $r['ocupacion'] = $cupo > 0 ? round((int)$r['asistentes'] / $cupo * 100) : null;
    }
    unset($r);
    return $rows;
}

/** Usuarios geocodificados (para el mapa de procedencia). */
function bloq_puntos(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query("SELECT dLatitud lat, dLongitud lng,
                                 COALESCE(NULLIF(TRIM(sDelegacion),''),'(sin dato)') d
                            FROM bloque_usuario
                           WHERE dLatitud IS NOT NULL AND dLatitud<>0
                             AND dLongitud IS NOT NULL AND dLongitud<>0") as $r)
        $out[] = ['lat'=>(float)$r['lat'], 'lng'=>(float)$r['lng'], 'd'=>$r['d']];
    return $out;
}

/** Cobertura de geocodificación. */
function bloq_geo_stats(PDO $pdo): array {
    $r = $pdo->query("SELECT COUNT(*) total, SUM(dLatitud IS NOT NULL AND dLatitud<>0) geo FROM bloque_usuario")->fetch();
    return ['total'=>(int)$r['total'], 'geo'=>(int)$r['geo']];
}

/** Límites delegacionales oficiales (GeoJSON) para contexto del mapa. */
function bloq_limites(PDO $pdo): array {
    try { $rows = $pdo->query("SELECT nombre, geojson FROM delegaciones_geo ORDER BY nombre")->fetchAll(); }
    catch (Throwable $e) { return []; }
    $f = [];
    foreach ($rows as $r) { $g = json_decode((string)$r['geojson'], true);
        if (is_array($g)) $f[] = ['type'=>'Feature','geometry'=>$g,'properties'=>['d'=>$r['nombre']]]; }
    return $f;
}

/** Serie de asistencia por día (registros de bloque_evento_invitado). */
function bloq_serie_dia(PDO $pdo, int $dias = 120): array {
    $sql = "SELECT DATE(dFecha) d, COUNT(*) n
              FROM bloque_evento_invitado
             WHERE dFecha IS NOT NULL AND dFecha >= (CURRENT_DATE - INTERVAL " . (int)$dias . " DAY)
             GROUP BY DATE(dFecha) ORDER BY d";
    return $pdo->query($sql)->fetchAll();
}
