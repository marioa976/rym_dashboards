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
     * Condición SQL. $alias = prefijo de tabla ('t' → t.ticket_form_id).
     * El id se valida contra el catálogo y se castea, así que no hay inyección.
     */
    function zd_form_sql(string $alias = '', ?string $form = null): string {
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
