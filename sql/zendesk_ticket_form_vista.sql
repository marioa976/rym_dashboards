-- =====================================================================
--  Recrea la vista v_tickets incluyendo ticket_form_id.
--  IMPRESCINDIBLE tras agregar la columna: las vistas de MySQL congelan su
--  lista de columnas al crearse, así que sin esto los reportes que consultan
--  v_tickets fallan con "Unknown column 'ticket_form_id'".
--  Correr DESPUÉS de zendesk_ticket_form.sql.
-- =====================================================================
USE portal_qro;

CREATE OR REPLACE VIEW v_tickets AS
SELECT
  t.ticket_id,
  e.nombre   AS estado,
  e.es_cerrado,
  e.es_resuelto,
  p.nombre   AS prioridad,
  c.nombre   AS canal,
  co.nombre  AS canal_origen,
  g.nombre   AS grupo,
  ts.nombre  AS tipo_servicio,
  d.nombre   AS delegacion,
  t.colonia,
  t.direccion,
  t.latitud,
  t.longitud,
  t.coordenadas_raw,
  CASE WHEN t.latitud IS NOT NULL AND t.longitud IS NOT NULL THEN 1 ELSE 0 END AS tiene_geo,
  t.solicitante_nombre_completo AS solicitante,
  t.fecha_creacion,
  t.fecha_estimada,
  t.fecha_resolucion,
  t.dias_resolucion,
  t.cumplio_sla,
  CASE
    WHEN e.es_resuelto = 0 AND t.fecha_estimada < CURDATE() THEN 1
    ELSE 0
  END AS vencido,
  t.cantidad_reportes,
  t.ticket_form_id,
  t.fuente_archivo,
  t.fecha_carga
FROM tickets t
LEFT JOIN cat_estado        e  ON e.id  = t.estado_id
LEFT JOIN cat_prioridad     p  ON p.id  = t.prioridad_id
LEFT JOIN cat_canal         c  ON c.id  = t.canal_id
LEFT JOIN cat_canal_origen  co ON co.id = t.canal_origen_id
LEFT JOIN cat_grupo         g  ON g.id  = t.grupo_id
LEFT JOIN cat_tipo_servicio ts ON ts.id = t.tipo_servicio_id
LEFT JOIN cat_delegacion    d  ON d.id  = t.delegacion_id;
