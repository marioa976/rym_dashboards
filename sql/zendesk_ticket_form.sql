-- =====================================================================
--  Filtro por FORMULARIO de Zendesk (ticket_form_id).
--  El importador crea la columna solo si falta (zd_asegurar_form_id), así que
--  este script es opcional: úsalo si prefieres aplicarlo a mano o si el
--  usuario de la BD no tiene permiso de ALTER desde la app.
--
--  Formularios conocidos:
--    30573528367899  Servicios                              (default en los reportes)
--    30192793057179  Trámites
--    42162642911131  Quejas, Sugerencias o Felicitaciones
--    31383989656987  Servicio (Histórico Siebel)
-- =====================================================================
USE portal_qro;

ALTER TABLE tickets
  ADD COLUMN ticket_form_id BIGINT UNSIGNED NULL,
  ADD INDEX idx_tickets_form (ticket_form_id);

-- Los tickets ya importados quedan en NULL hasta que vuelvas a sincronizar
-- (el incremental los re-baja por fecha de actualización y los completa).
-- Para ver cómo van quedando:
--   SELECT ticket_form_id, COUNT(*) FROM tickets GROUP BY ticket_form_id;
