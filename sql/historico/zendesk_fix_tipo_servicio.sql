-- ====================================================================
-- zendesk_fix_tipo_servicio.sql
-- Corrige catálogos creados con la RUTA COMPLETA del dropdown jerárquico
-- de Zendesk ("Categoría::Subcategoría") en vez de la hoja.
-- Mergea cada fila con '::' hacia su versión hoja y repunta los tickets.
-- Idempotente. BD: portal_qro.
-- ====================================================================
SET NAMES utf8mb4;

-- 1) Asegura que exista la fila "hoja" (texto tras el último '::')
INSERT IGNORE INTO cat_tipo_servicio (nombre)
SELECT TRIM(SUBSTRING_INDEX(nombre, '::', -1))
FROM cat_tipo_servicio
WHERE nombre LIKE '%::%';

-- 2) Repunta los tickets de la fila anidada a la fila hoja
UPDATE tickets t
  JOIN cat_tipo_servicio old  ON old.id = t.tipo_servicio_id AND old.nombre LIKE '%::%'
  JOIN cat_tipo_servicio leaf ON leaf.nombre = TRIM(SUBSTRING_INDEX(old.nombre, '::', -1))
  SET t.tipo_servicio_id = leaf.id
  WHERE leaf.id <> old.id;

-- 3) Borra las filas anidadas que ya no tienen tickets
DELETE c FROM cat_tipo_servicio c
  LEFT JOIN tickets t ON t.tipo_servicio_id = c.id
  WHERE c.nombre LIKE '%::%' AND t.ticket_id IS NULL;

-- Verificación:
-- SELECT id, nombre FROM cat_tipo_servicio WHERE nombre LIKE '%::%';   -- debe dar 0 filas
-- SELECT COUNT(*) FROM tickets WHERE tipo_servicio_id = 1;             -- tiliches ya unificado
