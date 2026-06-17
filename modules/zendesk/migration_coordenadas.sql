-- =====================================================================
--  Migración: agregar latitud, longitud y coordenadas_raw a `tickets`
--  Fecha:     2026-05-27
--  Propósito: nuevo CSV trae columna COORDENADAS con geolocalización
-- =====================================================================

USE reportes_servicio;

-- 1. Agregar columnas si no existen
ALTER TABLE tickets
  ADD COLUMN latitud         DECIMAL(10, 7) NULL  COMMENT 'Latitud extraída de COORDENADAS (rango -90 a 90)'  AFTER direccion,
  ADD COLUMN longitud        DECIMAL(10, 7) NULL  COMMENT 'Longitud extraída de COORDENADAS (rango -180 a 180)' AFTER latitud,
  ADD COLUMN coordenadas_raw VARCHAR(500)   NULL  COMMENT 'Texto original de la columna COORDENADAS del CSV'  AFTER longitud;

-- 2. Índices: uno geográfico (para buscar por bounding box) y otro de "tiene_geo" para filtros rápidos
ALTER TABLE tickets
  ADD INDEX idx_geo (latitud, longitud);

-- 3. Vista actualizada que incluye los nuevos campos
DROP VIEW IF EXISTS v_tickets;
CREATE VIEW v_tickets AS
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

-- Comprobación rápida
SELECT
  COUNT(*)                                       AS total_tickets,
  SUM(CASE WHEN latitud IS NOT NULL THEN 1 ELSE 0 END) AS con_geo,
  SUM(CASE WHEN latitud IS NULL THEN 1 ELSE 0 END)     AS sin_geo
FROM tickets;
