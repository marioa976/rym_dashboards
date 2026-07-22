<?php
/**
 * Filtro compartido por FORMULARIO de Zendesk (tickets.ticket_form_id).
 *
 * Los reportes lo incluyen con require_once y usan:
 *   zd_form_actual()  → id seleccionado ('all' o el id)
 *   zd_form_sql('t')  → condición SQL segura (ids validados contra el catálogo)
 *   zd_form_select()  → <select name="form"> listo para la barra de filtros
 *
 * Por defecto se muestra SERVICIOS.
 */
declare(strict_types=1);

if (!function_exists('zd_forms')) {

    /** Catálogo de formularios (ticket_form_id → nombre). */
    function zd_forms(): array {
        return [
            30573528367899 => 'Servicios',
            30192793057179 => 'Trámites',
            42162642911131 => 'Quejas, Sugerencias o Felicitaciones',
            31383989656987 => 'Servicio (Histórico Siebel)',
        ];
    }

    function zd_form_default(): string { return '30573528367899'; }   // Servicios

    /** Id seleccionado: 'all' o un id válido del catálogo (default: Servicios). */
    function zd_form_actual(): string {
        $v = trim((string)($_GET['form'] ?? ''));
        if ($v === 'all') return 'all';
        if ($v !== '' && array_key_exists((int)$v, zd_forms())) return (string)(int)$v;
        return zd_form_default();
    }

    /**
     * ¿Ya existe la columna tickets.ticket_form_id?
     * Si aún no se ha importado/migrado, el filtro se desactiva solo (no rompe
     * los reportes con "Unknown column"). Usa la conexión del módulo (db()).
     */
    function zd_form_col_existe(): bool {
        static $ok = null;
        if ($ok !== null) return $ok;
        $ok = false;
        if (function_exists('db')) {
            try {
                // Debe existir en la tabla Y en la vista v_tickets: los reportes
                // consultan ambas, y las vistas de MySQL congelan sus columnas.
                $n = (int)db()->query(
                    "SELECT COUNT(DISTINCT table_name) FROM information_schema.columns
                      WHERE table_schema = DATABASE()
                        AND table_name IN ('tickets','v_tickets')
                        AND column_name = 'ticket_form_id'")->fetchColumn();
                $ok = ($n >= 2);
            } catch (Throwable $e) { $ok = false; }
        }
        return $ok;
    }

    /**
     * Condición SQL. $alias = prefijo de tabla ('t' → t.ticket_form_id).
     * El id se valida contra el catálogo y se castea, así que no hay inyección.
     * Devuelve '1=1' (sin filtrar) si la columna todavía no existe.
     */
    function zd_form_sql(string $alias = '', ?string $form = null): string {
        if (!zd_form_col_existe()) return '1=1';
        $f   = $form ?? zd_form_actual();
        $col = ($alias !== '' ? $alias . '.' : '') . 'ticket_form_id';
        return $f === 'all' ? '1=1' : "$col = " . (int)$f;
    }

    function zd_form_nombre(?string $form = null): string {
        $f = $form ?? zd_form_actual();
        return $f === 'all' ? 'Todos los formularios' : (zd_forms()[(int)$f] ?? 'Formulario');
    }

    /** <select> del filtro (conserva el resto de parámetros al enviarse el form). */
    function zd_form_select(string $attrs = ''): string {
        if (!zd_form_col_existe()) {
            return '<select disabled title="Aún no se ha importado el campo ticket_form_id: '
                 . 'vuelve a sincronizar Zendesk para activar este filtro." ' . $attrs . '>'
                 . '<option>Todos (sin dato aún)</option></select>';
        }
        $sel = zd_form_actual();
        $h   = '<select name="form" ' . $attrs . '>';
        foreach (zd_forms() as $id => $nom) {
            $h .= '<option value="' . $id . '"' . ($sel === (string)$id ? ' selected' : '') . '>'
                . htmlspecialchars($nom) . '</option>';
        }
        $h .= '<option value="all"' . ($sel === 'all' ? ' selected' : '') . '>Todos los formularios</option>';
        return $h . '</select>';
    }
}
