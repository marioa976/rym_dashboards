<?php
/**
 * _zendesk_lib.php — Conector Zendesk: mapeo de campos, sincronización de
 * estructura (crece columnas en `tickets`) e import (upsert por ticket_id).
 */
declare(strict_types=1);

/** GET autenticado a Zendesk (Basic: email/token : api_token). */
function zd_get(string $url, string $user, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_USERPWD        => $user . ':' . $token,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('cURL: ' . $err);
    return [$status, (string)$body];
}

/** Normaliza el subdominio: acepta "municipioqueretaro", "https://municipioqueretaro"
 *  o "https://municipioqueretaro.zendesk.com" y devuelve solo "municipioqueretaro". */
function zd_sub(array $api): string {
    $s = (string)($api['subdomain'] ?? '');
    $s = preg_replace('#^https?://#i', '', $s);          // quita esquema
    $s = preg_replace('#\.zendesk\.com.*$#i', '', $s);   // quita dominio/ruta si los pusieron
    return trim($s, "/ \t");
}

/** Busca tickets en la Search API. Devuelve [resultados, error, total, query]. */
function zd_buscar(array $api, string $desde, string $hasta, string $tag, int $limite): array {
    $partes = ['type:ticket'];
    if ($tag !== '')   $partes[] = 'tags:' . $tag;
    if ($desde !== '') $partes[] = 'created>=' . $desde;
    if ($hasta !== '') $partes[] = 'created<=' . $hasta;
    $query = implode(' ', $partes);
    $url = 'https://' . zd_sub($api) . '.zendesk.com/api/v2/search.json?query='
         . urlencode($query) . '&sort_by=created_at&sort_order=desc';
    $res = []; $err = ''; $total = 0; $pages = 0;
    try {
        while ($url && count($res) < $limite && $pages < 60) {
            $pages++;
            [$status, $body] = zd_get($url, $api['user'] ?? '', $api['token'] ?? '');
            if ($status !== 200) { $err = "HTTP $status. " . substr($body, 0, 300); break; }
            $data = json_decode($body, true);
            if (!is_array($data)) { $err = 'Respuesta no es JSON válido.'; break; }
            $total = $data['count'] ?? $total;
            foreach (($data['results'] ?? []) as $r) { $res[] = $r; if (count($res) >= $limite) break; }
            $url = $data['next_page'] ?? null;
        }
    } catch (Throwable $e) { $err = $e->getMessage(); }
    return [$res, $err, $total, $query];
}

/**
 * Exportación INCREMENTAL de tickets (sin el límite de 1000 de la Search API).
 * Pagina por cursor de tiempo: pasa $url = next_page de la página previa, o ''
 * para arrancar desde $startTs (unix). Devuelve la página parseada.
 *   ['tickets'=>[], 'next'=>url|null, 'end_time'=>int, 'fin'=>bool, 'count'=>int]
 *   o ['rate_limited'=>true] / ['error'=>msg]
 */
function zd_incremental(array $api, string $url, int $startTs = 0): array {
    if ($url === '') {
        $url = 'https://' . zd_sub($api) . '.zendesk.com/api/v2/incremental/tickets.json?start_time=' . max(0, $startTs);
    }
    try {
        [$status, $body] = zd_get($url, $api['user'] ?? '', $api['token'] ?? '');
    } catch (Throwable $e) { return ['error' => $e->getMessage()]; }
    if ($status === 429) return ['rate_limited' => true];
    if ($status !== 200) return ['error' => "HTTP $status: " . substr($body, 0, 200)];
    $d = json_decode($body, true);
    if (!is_array($d)) return ['error' => 'Respuesta no es JSON válido.'];
    return [
        'tickets'  => $d['tickets'] ?? [],
        'next'     => $d['next_page'] ?? null,
        'end_time' => (int)($d['end_time'] ?? 0),
        'fin'      => !empty($d['end_of_stream']),
        'count'    => (int)($d['count'] ?? 0),
    ];
}

/** Guarda los errores por ticket de una importación en zendesk_import_errores. */
function zd_log_errores(PDO $pdo, array $errs, string $origen): void {
    if (!$errs) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS zendesk_import_errores (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ejecutado_en TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ticket_id    VARCHAR(30)  NULL,
            mensaje      TEXT         NULL,
            origen       VARCHAR(20)  NULL,
            PRIMARY KEY (id), KEY idx_fecha (ejecutado_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $st = $pdo->prepare("INSERT INTO zendesk_import_errores (ticket_id,mensaje,origen) VALUES (?,?,?)");
        foreach ($errs as $e) {
            $tid = null; $msg = (string)$e;
            if (preg_match('/^#(\S+):\s*(.*)$/s', (string)$e, $m)) { $tid = $m[1]; $msg = $m[2]; }
            $st->execute([$tid, mb_substr($msg, 0, 1000), $origen]);
        }
    } catch (Throwable $e) { /* el visor de errores no debe romper el import */ }
}

/** Carga el mapeo activo (guardar=1) desde la tabla zendesk_mapeo. */
function zd_cargar_mapeo(PDO $pdo): array {
    try {
        return $pdo->query("SELECT campo_id,nombre,columna,tipo,estandar,guardar
                              FROM zendesk_mapeo WHERE guardar=1 ORDER BY orden")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** Columnas que YA existen en `tickets`. */
function zd_columnas_tickets(PDO $pdo): array {
    $c = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tickets'")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('strtolower', $c);
}

/** Tipo SQL para una columna nueva según el tipo lógico del mapeo. */
function zd_sql_tipo(string $tipo): string {
    switch ($tipo) {
        case 'textolargo': return 'TEXT NULL';
        case 'fecha':      return 'DATETIME NULL';
        case 'decimal':    return 'DECIMAL(14,4) NULL';
        case 'entero':     return 'BIGINT NULL';
        default:           return 'VARCHAR(255) NULL';
    }
}

/**
 * Sincroniza la estructura: agrega a `tickets` las columnas del mapeo que
 * falten (los campos 'geo' usan latitud/longitud existentes). Idempotente.
 * Devuelve [agregadas[], ya_existian_n].
 */
function zd_sincronizar_estructura(PDO $pdo, array $mapeo): array {
    $existentes = zd_columnas_tickets($pdo);
    $agregadas = [];

    // columnas de servicio del conector
    $servicio = [
        'zd_raw'         => 'LONGTEXT NULL',
        'zd_importado_en'=> 'DATETIME NULL',
    ];
    foreach ($servicio as $col => $def) {
        if (!in_array($col, $existentes, true)) {
            $pdo->exec("ALTER TABLE tickets ADD COLUMN `$col` $def");
            $existentes[] = $col; $agregadas[] = $col;
        }
    }

    foreach ($mapeo as $m) {
        if ($m['tipo'] === 'geo') continue;                 // usa latitud/longitud existentes
        $col = strtolower($m['columna']);
        if ($col === '' || in_array($col, $existentes, true)) continue;
        if (!preg_match('/^[a-z0-9_]{1,64}$/', $col)) continue;  // nombre seguro
        $pdo->exec("ALTER TABLE tickets ADD COLUMN `$col` " . zd_sql_tipo($m['tipo']));
        $existentes[] = $col; $agregadas[] = $col;
    }

    // Auto-ensanche: campos 'textolargo' que ya existen como VARCHAR -> TEXT
    // (evita "Data too long" cuando un campo creció de tamaño en Zendesk).
    $tipo_actual = [];
    $rs = $pdo->query("SELECT LOWER(COLUMN_NAME) c, LOWER(DATA_TYPE) d
                       FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tickets'");
    foreach ($rs as $r) $tipo_actual[$r['c']] = $r['d'];
    foreach ($mapeo as $m) {
        if ($m['tipo'] !== 'textolargo') continue;
        $col = strtolower($m['columna']);
        if (!preg_match('/^[a-z0-9_]{1,64}$/', $col)) continue;
        if (!isset($tipo_actual[$col])) continue;
        if (!in_array($tipo_actual[$col], ['text','mediumtext','longtext','tinytext'], true)) {
            $pdo->exec("ALTER TABLE tickets MODIFY `$col` TEXT NULL");
            $agregadas[] = $col . ' (→TEXT)';
        }
    }
    return [$agregadas, count($existentes)];
}

/** Lee un custom field del ticket por id. */
function zd_cf(array $ticket, string $id) {
    foreach (($ticket['custom_fields'] ?? []) as $f) {
        if ((string)$f['id'] === $id) return $f['value'];
    }
    return null;
}

/** Valor de un campo estándar del ticket. */
function zd_std(array $ticket, string $campo_id) {
    switch ($campo_id) {
        case 'std_id':          return $ticket['id'] ?? null;
        case 'std_created_at':  return $ticket['created_at'] ?? null;
        case 'std_updated_at':  return $ticket['updated_at'] ?? null;
        case 'std_subject':     return $ticket['subject'] ?? ($ticket['raw_subject'] ?? null);
        case 'std_description': return $ticket['description'] ?? null;
        case 'std_status':      return $ticket['status'] ?? null;
        case 'std_priority':    return $ticket['priority'] ?? null;
        case 'std_tags':        return isset($ticket['tags']) ? implode(',', (array)$ticket['tags']) : null;
    }
    return null;
}

/** Normaliza una fecha ISO de Zendesk a 'Y-m-d H:i:s' (o null). */
function zd_fecha($v): ?string {
    if (!$v) return null;
    $t = strtotime((string)$v);
    return $t ? date('Y-m-d H:i:s', $t) : null;
}

/**
 * Busca (o crea) una fila de catálogo por nombre y devuelve su id.
 * $tabla debe ser un catálogo controlado (cat_*). Cachea por request.
 */
function zd_get_or_create(PDO $pdo, string $tabla, ?string $nombre, array $extra = []): ?int {
    static $cache = [];
    static $ok = ['cat_estado','cat_prioridad','cat_canal','cat_canal_origen','cat_grupo','cat_tipo_servicio','cat_delegacion'];
    if (!in_array($tabla, $ok, true)) return null;
    $nombre = trim((string)$nombre);
    if ($nombre === '') return null;
    $key = $tabla . '|' . mb_strtolower($nombre);
    if (isset($cache[$key])) return $cache[$key];
    try {
        $st = $pdo->prepare("SELECT id FROM `$tabla` WHERE nombre = ? LIMIT 1");
        $st->execute([$nombre]);
        $id = $st->fetchColumn();
        if ($id === false) {
            $cols  = array_merge(['nombre' => $nombre], $extra);
            $names = array_keys($cols);
            $ph    = array_map(fn($c) => ':' . $c, $names);
            $ins = $pdo->prepare("INSERT INTO `$tabla` (`" . implode('`,`', $names) . "`) VALUES (" . implode(',', $ph) . ")");
            $p = []; foreach ($cols as $k => $v) $p[':' . $k] = $v;
            $ins->execute($p);
            $id = (int)$pdo->lastInsertId();
        }
        return $cache[$key] = (int)$id;
    } catch (Throwable $e) { return null; }
}

/** Status de Zendesk -> id de cat_estado (con flags es_cerrado/es_resuelto). */
function zd_estado_id(PDO $pdo, ?string $status): ?int {
    if (!$status) return null;
    $d = [
        'new'     => ['Nuevo', 0, 0],
        'open'    => ['Abierto', 0, 0],
        'pending' => ['Pendiente de Información', 0, 0],
        'hold'    => ['En proceso cuadrilla', 0, 0],
        'solved'  => ['Resuelto', 1, 1],
        'closed'  => ['Resuelto', 1, 1],
    ][strtolower($status)] ?? [ucfirst($status), 0, 0];
    return zd_get_or_create($pdo, 'cat_estado', $d[0], ['es_cerrado' => $d[1], 'es_resuelto' => $d[2]]);
}

/** Priority de Zendesk -> id de cat_prioridad. */
function zd_prioridad_id(PDO $pdo, ?string $p): ?int {
    if (!$p) return null;
    $d = ['low' => 'Normal', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'][strtolower($p)] ?? ucfirst($p);
    return zd_get_or_create($pdo, 'cat_prioridad', $d);
}

/**
 * Metadatos de Zendesk para resolver etiquetas (igual que la vista que generaba
 * el CSV): nombres de grupos y opciones (value => label) de los campos tipo lista.
 * Se trae UNA vez por import. Si una llamada falla, ese mapa queda vacío y se usa
 * el valor crudo como respaldo.
 */
function zd_meta(array $api): array {
    $meta = ['grupos' => [], 'opc' => []];
    if (empty($api['subdomain'])) return $meta;

    // Cache en disco (1 h) para no re-pedir grupos/opciones en cada ventana del backfill.
    // v2: las opciones ahora guardan la hoja del dropdown jerárquico (sin "Categoría::").
    $cacheFile = sys_get_temp_dir() . '/zd_meta_v2_' . md5((string)$api['subdomain']) . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $c = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($c) && isset($c['grupos'])) return $c;
    }

    $base = 'https://' . zd_sub($api) . '.zendesk.com/api/v2/';
    $u = $api['user'] ?? ''; $t = $api['token'] ?? '';

    // Grupos (group_id -> nombre, p.ej. "Aseo Público")
    try {
        $url = $base . 'groups.json?page[size]=100';
        $guard = 0;
        while ($url && $guard++ < 20) {
            [$s, $b] = zd_get($url, $u, $t);
            if ($s !== 200) break;
            $d = json_decode($b, true);
            foreach (($d['groups'] ?? []) as $g) $meta['grupos'][(string)$g['id']] = $g['name'];
            $url = $d['next_page'] ?? ($d['links']['next'] ?? null);
        }
    } catch (Throwable $e) {}

    // Opciones de los campos lista que nos interesan (value -> label)
    $campos = ['30954708072347','30954716982683','30874463523099','31657758911387','32061554573851'];
    foreach ($campos as $fid) {
        try {
            [$s, $b] = zd_get($base . 'ticket_fields/' . $fid . '.json', $u, $t);
            if ($s !== 200) continue;
            $d = json_decode($b, true);
            $map = [];
            foreach (($d['ticket_field']['custom_field_options'] ?? []) as $o) {
                // Los dropdowns jerárquicos de Zendesk traen la ruta completa
                // "Categoría::Subcategoría"; nos quedamos con la hoja.
                $nm = (string)$o['name'];
                if (($pp = mb_strrpos($nm, '::')) !== false) $nm = trim(mb_substr($nm, $pp + 2));
                $map[(string)$o['value']] = $nm;
            }
            if ($map) $meta['opc'][$fid] = $map;
        } catch (Throwable $e) {}
    }
    @file_put_contents($cacheFile, json_encode($meta));
    return $meta;
}

/**
 * Catálogo de colonias/delegaciones (objetos personalizados de Zendesk).
 * Traduce el ULID que llega en el ticket a su nombre legible.
 * Devuelve ['col'=>[ulid=>['nombre','deleg']], 'deleg'=>[ulid=>nombre]].
 * Cachea por request. Si las tablas no existen aún, regresa mapas vacíos
 * (el importador sigue funcionando con el respaldo anterior).
 */
function zd_colonias_map(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = ['col' => [], 'deleg' => []];
    try {
        $rs = $pdo->query("SELECT colonia_ulid, nombre, delegacion_nombre FROM zendesk_colonias");
        foreach ($rs as $r) {
            $cache['col'][$r['colonia_ulid']] = ['nombre' => $r['nombre'], 'deleg' => $r['delegacion_nombre']];
        }
        $rd = $pdo->query("SELECT delegacion_ulid, nombre FROM zendesk_delegaciones");
        foreach ($rd as $r) {
            $cache['deleg'][$r['delegacion_ulid']] = $r['nombre'];
        }
    } catch (Throwable $e) {
        // tablas aún no importadas; se queda vacío
    }
    return $cache;
}

/**
 * Convierte un ticket de Zendesk en una fila para `tickets` según el mapeo,
 * y RESUELVE los catálogos (estado/prioridad/canal/grupo/servicio…) para que
 * los dashboards y mapas reflejen los tickets importados.
 * Maneja el campo 'geo' (parsea "lat,long" a latitud/longitud/coordenadas_raw).
 */
function zd_ticket_a_fila(PDO $pdo, array $ticket, array $mapeo, array $meta = []): array {
    $row = [];
    // Resuelve el valor de un campo lista a su etiqueta legible (o el valor crudo).
    $label = function (string $fid, $val) use ($meta) {
        if (!is_string($val) || $val === '') return null;
        return $meta['opc'][$fid][$val] ?? $val;
    };
    foreach ($mapeo as $m) {
        $val = ((int)$m['estandar'] === 1) ? zd_std($ticket, $m['campo_id']) : zd_cf($ticket, $m['campo_id']);

        if ($m['tipo'] === 'geo') {
            if (is_string($val) && preg_match('/(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)/', $val, $mm)) {
                $row['latitud']  = (float)$mm[1];
                $row['longitud'] = (float)$mm[2];
                $row['coordenadas_raw'] = $val;
            }
            continue;
        }
        if ($val === null || $val === '') { continue; }     // en blanco si no trae dato

        $col = $m['columna'];
        if ($m['tipo'] === 'fecha') {
            $val = zd_fecha($val);
            if ($col === 'fecha_creacion' && $val) $val = substr($val, 0, 10); // columna DATE
        } elseif (is_array($val)) {
            $val = implode(',', $val);
        }
        if ($val !== null && $val !== '') $row[$col] = $val;
    }
    // ---- Resolución a catálogos (con etiquetas legibles, como la vista del CSV) ----
    $eid = zd_estado_id($pdo, $ticket['status'] ?? null);
    if ($eid)  $row['estado_id'] = $eid;
    $pid = zd_prioridad_id($pdo, $ticket['priority'] ?? null);
    if ($pid)  $row['prioridad_id'] = $pid;
    $canal = $ticket['via']['channel'] ?? null;
    if ($canal) $row['canal_id'] = zd_get_or_create($pdo, 'cat_canal', ucfirst((string)$canal));

    // Canal origen (campo lista "Origen")
    $origen = $label('32061554573851', zd_cf($ticket, '32061554573851'));
    if ($origen) $row['canal_origen_id'] = zd_get_or_create($pdo, 'cat_canal_origen', $origen);

    // Tipo de servicio (campo lista "Servicio")
    $servicio = $label('30874463523099', zd_cf($ticket, '30874463523099'));
    if ($servicio) $row['tipo_servicio_id'] = zd_get_or_create($pdo, 'cat_tipo_servicio', $servicio);

    // Grupo: el departamento real, por group_id -> nombre (ej. "Aseo Público").
    // Respaldo: servicio o trámite si el ticket no trae grupo.
    $gname = null;
    $gid = $ticket['group_id'] ?? null;
    if ($gid !== null && isset($meta['grupos'][(string)$gid])) $gname = $meta['grupos'][(string)$gid];
    if (!$gname) $gname = $servicio ?: $label('31657758911387', zd_cf($ticket, '31657758911387'));
    if ($gname) $row['grupo_id'] = zd_get_or_create($pdo, 'cat_grupo', $gname);

    // Catálogo de colonias/delegaciones (objetos personalizados): ULID -> nombre.
    $cmap = zd_colonias_map($pdo);

    // Delegación: el campo es una relación a objeto -> llega como ULID.
    // Traduce con el catálogo; si no está, intenta las opciones del campo.
    $dUlid = zd_cf($ticket, '30954708072347');
    $deleg = (is_string($dUlid) && isset($cmap['deleg'][$dUlid]))
        ? $cmap['deleg'][$dUlid]
        : $label('30954708072347', $dUlid);

    // Colonia: igual, ULID -> nombre legible vía catálogo.
    $cUlid = zd_cf($ticket, '30954716982683');
    $col = null;
    if (is_string($cUlid) && isset($cmap['col'][$cUlid])) {
        $col = $cmap['col'][$cUlid]['nombre'];
        if (!$deleg && !empty($cmap['col'][$cUlid]['deleg'])) $deleg = $cmap['col'][$cUlid]['deleg']; // deriva delegación de la colonia
    } else {
        $col = $label('30954716982683', $cUlid);   // respaldo: etiqueta del campo o crudo
    }

    if ($deleg) $row['delegacion_id'] = zd_get_or_create($pdo, 'cat_delegacion', $deleg);
    if (is_string($col) && $col !== '') $row['colonia'] = $col;

    // Fecha estimada -> fecha_estimada (para SLA / vencidos)
    $fe = zd_cf($ticket, '30278458271131');
    if ($fe) { $d = zd_fecha($fe); if ($d) $row['fecha_estimada'] = substr($d, 0, 10); }

    // ---- Campos de servicio del conector ----
    $row['ticket_id']       = (int)($ticket['id'] ?? 0);
    if (empty($row['fecha_creacion'])) $row['fecha_creacion'] = substr((string)zd_fecha($ticket['created_at'] ?? 'now'), 0, 10);
    $row['fuente_archivo']  = 'zendesk-api';
    $row['zd_raw']          = json_encode($ticket, JSON_UNESCAPED_UNICODE);
    $row['zd_importado_en'] = date('Y-m-d H:i:s');
    return $row;
}

/** Longitud máxima de cada columna string de `tickets` (cache por request). */
function zd_col_limites(PDO $pdo): array {
    static $lim = null;
    if ($lim !== null) return $lim;
    $lim = [];
    try {
        $rs = $pdo->query("SELECT LOWER(COLUMN_NAME) c, CHARACTER_MAXIMUM_LENGTH m
                           FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tickets'
                             AND CHARACTER_MAXIMUM_LENGTH IS NOT NULL");
        foreach ($rs as $r) $lim[$r['c']] = (int)$r['m'];
    } catch (Throwable $e) {}
    return $lim;
}

/** Upsert de una fila en `tickets` (por ticket_id). Devuelve true si OK. */
function zd_upsert(PDO $pdo, array $row): bool {
    if (empty($row['ticket_id'])) return false;
    // Salvaguarda: trunca strings que excedan el tamaño de su columna,
    // así un solo campo largo nunca rechaza el ticket completo (1406).
    $lim = zd_col_limites($pdo);
    foreach ($row as $k => $v) {
        if (is_string($v)) {
            $max = $lim[strtolower($k)] ?? 0;
            if ($max > 0 && mb_strlen($v) > $max) $row[$k] = mb_substr($v, 0, $max);
        }
    }
    $cols = array_keys($row);
    $ph   = array_map(fn($c) => ':' . $c, $cols);
    $set  = [];
    foreach ($cols as $c) { if ($c !== 'ticket_id') $set[] = "`$c`=VALUES(`$c`)"; }
    $sql = 'INSERT INTO tickets (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', $ph) . ')'
         . ' ON DUPLICATE KEY UPDATE ' . implode(',', $set);
    $st = $pdo->prepare($sql);
    $params = [];
    foreach ($row as $k => $v) { $params[':' . $k] = $v; }
    return $st->execute($params);
}

/**
 * Importa un arreglo de tickets de Zendesk a `tickets`.
 * Trae UNA vez los metadatos (grupos + opciones de campos) para resolver
 * etiquetas legibles. Devuelve [insertados/actualizados_ok, errores[]].
 */
/**
 * Asigna la sección electoral (tickets.seccion_id) a los tickets nuevos.
 * Precálculo para el reporte "por sección": así NO se hace ST_Contains en cada
 * carga del dashboard. Crea la columna si falta. Corre en READ COMMITTED para
 * que el índice espacial funcione. No rompe el import si algo falla.
 */
function zd_asignar_secciones(PDO $pdo): void {
    static $listo = null;     // null=sin revisar · false=no aplica · true=listo
    try {
        if ($listo === null) {
            $listo = false;
            $haySecGeo = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables
                           WHERE table_schema=DATABASE() AND table_name='secciones_geo'")->fetchColumn();
            if (!$haySecGeo) return;                       // sin tablas IEEQ no hay nada que cruzar
            $cols = $pdo->query("SHOW COLUMNS FROM tickets")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('seccion_id', $cols, true)) {
                $pdo->exec("ALTER TABLE tickets ADD COLUMN seccion_id INT NULL,
                            ADD INDEX idx_tickets_seccion (seccion_id), ALGORITHM=INPLACE, LOCK=NONE");
            }
            $pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
            $listo = true;
        }
        if ($listo) {
            $pdo->exec("
                UPDATE tickets t
                  JOIN secciones_geo sg
                    ON ST_Contains(sg.geom, ST_GeomFromText(CONCAT('POINT(',t.latitud,' ',t.longitud,')'), 4326))
                  SET t.seccion_id = sg.seccion_id
                  WHERE t.seccion_id IS NULL AND t.latitud IS NOT NULL AND t.longitud IS NOT NULL
            ");
        }
    } catch (Throwable $e) { /* no rompemos el import */ }
}

function zd_importar(PDO $pdo, array $api, array $tickets, array $mapeo): array {
    $meta = zd_meta($api);
    $ok = 0; $err = [];
    foreach ($tickets as $t) {
        try {
            if (zd_upsert($pdo, zd_ticket_a_fila($pdo, $t, $mapeo, $meta))) $ok++;
        } catch (Throwable $e) {
            $err[] = '#' . ($t['id'] ?? '?') . ': ' . $e->getMessage();
        }
    }
    zd_asignar_secciones($pdo);   // precálculo de seccion_id para los tickets nuevos
    return [$ok, $err];
}

/** Crea (si no existe) la tabla de bitácora y registra una ejecución de import. */
function zd_log(PDO $pdo, array $d): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS zendesk_import_log (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ejecutado_en TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `desde`      DATE         NULL,
            `hasta`      DATE         NULL,
            tag          VARCHAR(120) NULL,
            traidos      INT          NOT NULL DEFAULT 0,
            guardados    INT          NOT NULL DEFAULT 0,
            errores      INT          NOT NULL DEFAULT 0,
            tope         TINYINT(1)   NOT NULL DEFAULT 0,
            origen       VARCHAR(20)  NOT NULL DEFAULT 'manual',
            usuario_id   INT UNSIGNED NULL,
            PRIMARY KEY (id), KEY idx_fecha (ejecutado_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $st = $pdo->prepare("INSERT INTO zendesk_import_log
            (`desde`,`hasta`,tag,traidos,guardados,errores,tope,origen,usuario_id)
            VALUES (:de,:ha,:tg,:tr,:gu,:er,:to,:or,:us)");
        $st->execute([
            ':de' => ($d['desde'] ?? '') ?: null,
            ':ha' => ($d['hasta'] ?? '') ?: null,
            ':tg' => ($d['tag']   ?? '') ?: null,
            ':tr' => (int)($d['traidos']   ?? 0),
            ':gu' => (int)($d['guardados'] ?? 0),
            ':er' => (int)($d['errores']   ?? 0),
            ':to' => !empty($d['tope']) ? 1 : 0,
            ':or' => $d['origen'] ?? 'manual',
            ':us' => $d['usuario_id'] ?? null,
        ]);
    } catch (Throwable $e) { /* la bitácora no debe romper el import */ }
}
