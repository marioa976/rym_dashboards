-- =====================================================================
--  MIGRACIÓN DE DATOS  ->  base única portal_qro
--  Copia los datos reales desde las bases viejas (mismo servidor MySQL):
--     padron_dif         -> portal_qro   (módulo DIF)
--     reportes_servicio  -> portal_qro   (módulo Zendesk)
--
--  Cómo correrlo:
--    · MySQL Workbench: abre este archivo y ejecuta (rayo).
--    · CLI: /Applications/MAMP/Library/bin/mysql -u root -proot -P8889 -h127.0.0.1 < migrar_datos.sql
--
--  SEGURO E IDEMPOTENTE:
--    - Vacía SOLO las tablas de los módulos antes de copiar (puedes re-correrlo).
--    - NO toca usuarios / modulos / usuario_modulo / sesiones del portal.
--    - tickets usa columnas explícitas (excluye las generadas dias_resolucion/cumplio_sla).
--    - Catálogos se vacían y se copian con sus IDs originales, para que los
--      FK de tickets sigan apuntando correcto.
--    - Qrobici NO se migra: vive en BD remota con vistas.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = '';

-- ---------------------------------------------------------------------
-- DIF :  padron_dif  ->  portal_qro
-- ---------------------------------------------------------------------
TRUNCATE TABLE portal_qro.padron;
INSERT INTO portal_qro.padron
    (id,
     fecha_registro,
     fecha_entrega,
     cantidad,
     programa,
     coordinacion,
     tipo_apoyo,
     recibe_ciudadano,
     lugar_entrega,
     nombre_recibe,
     ciudadano,
     sexo,
     curp,
     fecha_nacimiento,
     edad,
     cp,
     delegacion,
     colonia,
     calle_numero,
     latitud,
     longitud,
     geocode_status,
     geocode_source,
     geocode_estrategia,
     geocode_precision,
     geocode_address,
     geocode_intentos,
     geocode_at,
     fila_origen,
     archivo_origen,
     created_at,
     updated_at)
SELECT
     id,
     fecha_registro,
     fecha_entrega,
     cantidad,
     programa,
     coordinacion,
     tipo_apoyo,
     recibe_ciudadano,
     lugar_entrega,
     nombre_recibe,
     ciudadano,
     sexo,
     curp,
     fecha_nacimiento,
     edad,
     cp,
     delegacion,
     colonia,
     calle_numero,
     latitud,
     longitud,
     geocode_status,
     geocode_source,
     geocode_estrategia,
     geocode_precision,
     geocode_address,
     geocode_intentos,
     geocode_at,
     fila_origen,
     archivo_origen,
     created_at,
     updated_at
FROM padron_dif.padron;

TRUNCATE TABLE portal_qro.geocode_cache;
INSERT INTO portal_qro.geocode_cache
    (id,
     query_hash,
     query_text,
     estrategia,
     status,
     latitud,
     longitud,
     formatted_address,
     location_type,
     raw_response,
     created_at)
SELECT
     id,
     query_hash,
     query_text,
     estrategia,
     status,
     latitud,
     longitud,
     formatted_address,
     location_type,
     raw_response,
     created_at
FROM padron_dif.geocode_cache;

TRUNCATE TABLE portal_qro.import_log;
INSERT INTO portal_qro.import_log
    (id,
     archivo,
     filas_leidas,
     filas_insertadas,
     filas_error,
     iniciado_at,
     terminado_at,
     notas)
SELECT
     id,
     archivo,
     filas_leidas,
     filas_insertadas,
     filas_error,
     iniciado_at,
     terminado_at,
     notas
FROM padron_dif.import_log;

-- ---------------------------------------------------------------------
-- ZENDESK :  reportes_servicio  ->  portal_qro
--   Orden: vaciar tickets (hijo) -> copiar catálogos (padres) -> copiar tickets
-- ---------------------------------------------------------------------
TRUNCATE TABLE portal_qro.tickets;
TRUNCATE TABLE portal_qro.cat_estado;
INSERT INTO portal_qro.cat_estado SELECT * FROM reportes_servicio.cat_estado;

TRUNCATE TABLE portal_qro.cat_prioridad;
INSERT INTO portal_qro.cat_prioridad SELECT * FROM reportes_servicio.cat_prioridad;

TRUNCATE TABLE portal_qro.cat_canal;
INSERT INTO portal_qro.cat_canal SELECT * FROM reportes_servicio.cat_canal;

TRUNCATE TABLE portal_qro.cat_canal_origen;
INSERT INTO portal_qro.cat_canal_origen SELECT * FROM reportes_servicio.cat_canal_origen;

TRUNCATE TABLE portal_qro.cat_grupo;
INSERT INTO portal_qro.cat_grupo SELECT * FROM reportes_servicio.cat_grupo;

TRUNCATE TABLE portal_qro.cat_tipo_servicio;
INSERT INTO portal_qro.cat_tipo_servicio SELECT * FROM reportes_servicio.cat_tipo_servicio;

TRUNCATE TABLE portal_qro.cat_delegacion;
INSERT INTO portal_qro.cat_delegacion SELECT * FROM reportes_servicio.cat_delegacion;

TRUNCATE TABLE portal_qro.cargas;
INSERT INTO portal_qro.cargas SELECT * FROM reportes_servicio.cargas;


-- tickets al final (columnas explícitas, sin generadas)
TRUNCATE TABLE portal_qro.tickets;
INSERT INTO portal_qro.tickets
    (ticket_id,
     estado_id,
     prioridad_id,
     canal_id,
     canal_origen_id,
     grupo_id,
     tipo_servicio_id,
     delegacion_id,
     fecha_creacion,
     fecha_estimada,
     fecha_resolucion,
     colonia,
     direccion,
     latitud,
     longitud,
     coordenadas_raw,
     solicitante_nombre_completo,
     solicitante_nombre,
     solicitante_apellido_p,
     solicitante_apellido_m,
     cantidad_reportes,
     fuente_archivo,
     fecha_carga,
     fecha_actualizacion)
SELECT
     ticket_id,
     estado_id,
     prioridad_id,
     canal_id,
     canal_origen_id,
     grupo_id,
     tipo_servicio_id,
     delegacion_id,
     fecha_creacion,
     fecha_estimada,
     fecha_resolucion,
     colonia,
     direccion,
     latitud,
     longitud,
     coordenadas_raw,
     solicitante_nombre_completo,
     solicitante_nombre,
     solicitante_apellido_p,
     solicitante_apellido_m,
     cantidad_reportes,
     fuente_archivo,
     fecha_carga,
     fecha_actualizacion
FROM reportes_servicio.tickets;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Verificación rápida (conteos cargados)
-- ---------------------------------------------------------------------
SELECT 'padron'        AS tabla, COUNT(*) AS filas FROM portal_qro.padron
UNION ALL SELECT 'geocode_cache', COUNT(*) FROM portal_qro.geocode_cache
UNION ALL SELECT 'import_log',    COUNT(*) FROM portal_qro.import_log
UNION ALL SELECT 'tickets',       COUNT(*) FROM portal_qro.tickets
UNION ALL SELECT 'cat_grupo',     COUNT(*) FROM portal_qro.cat_grupo
UNION ALL SELECT 'cat_delegacion',COUNT(*) FROM portal_qro.cat_delegacion
UNION ALL SELECT 'cargas',        COUNT(*) FROM portal_qro.cargas;
