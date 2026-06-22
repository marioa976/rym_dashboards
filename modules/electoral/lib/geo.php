<?php
/**
 * Helpers geográficos mínimos para el módulo electoral.
 *
 * Verifica que el catálogo IEEQ (distritos / municipios / secciones /
 * secciones_geo) esté instalado. Si trabajas en otro estado, basta con
 * apuntar `GEO_STATE_NAME` y poblar las cuatro tablas con tus polígonos
 * (puedes usar `bin/export_geo.php` para portar un dump entre proyectos).
 */

const GEO_STATE_NAME = 'Queretaro';

/**
 * True si las cuatro tablas espaciales existen.
 */
function geo_schema_ready(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $pdo = reporteador_pdo();
        $needed = ['distritos', 'municipios', 'secciones', 'secciones_geo'];
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name IN ('" . implode("','", $needed) . "')"
        );
        $stmt->execute();
        $cached = ((int)$stmt->fetchColumn()) === count($needed);
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}
