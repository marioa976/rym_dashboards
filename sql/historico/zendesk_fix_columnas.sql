-- ====================================================================
-- zendesk_fix_columnas.sql · arregla "Data too long" (1406) en la importación
-- Ensancha a TEXT los campos que pueden venir largos desde Zendesk y marca
-- su tipo como 'textolargo' en el mapeo para que las próximas sincronizaciones
-- los mantengan así. Idempotente. BD: portal_qro.
-- ====================================================================
SET NAMES utf8mb4;

-- 1) Ensancha las columnas afectadas (VARCHAR(255) -> TEXT)
ALTER TABLE tickets MODIFY `zd_direccion`                        TEXT NULL;
ALTER TABLE tickets MODIFY `zd_motivo_de_no_resolucion_exitosa`  TEXT NULL;
ALTER TABLE tickets MODIFY `zd_motivo_de_no_resolucion`          TEXT NULL;
ALTER TABLE tickets MODIFY `zd_foto`                             TEXT NULL;
ALTER TABLE tickets MODIFY `zd_imagen_evidencia_url`             TEXT NULL;

-- 2) Marca esos campos como 'textolargo' en el mapeo (consistencia a futuro)
UPDATE zendesk_mapeo SET tipo = 'textolargo'
 WHERE columna IN (
   'zd_direccion',
   'zd_motivo_de_no_resolucion_exitosa',
   'zd_motivo_de_no_resolucion',
   'zd_foto',
   'zd_imagen_evidencia_url'
 );
