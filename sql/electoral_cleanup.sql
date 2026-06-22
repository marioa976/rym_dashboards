-- =====================================================================
--  Limpieza: elimina las tablas del módulo electoral que NO usa el código.
--  Ambas están VACÍAS (nunca se insertó en ellas) y ninguna otra tabla las
--  referencia por FK, así que el borrado no pierde datos ni rompe relaciones.
--
--  Tablas eliminadas:
--    - partidos_proceso   (sin uso en el código)
--    - secciones_mapeo    (sin uso en el código)
--
--  Se CONSERVAN (sí las usa el importador):
--    - secciones_pendientes, secciones_historicas
--
--  Idempotente: DROP TABLE IF EXISTS. Correr una o varias veces es seguro.
-- =====================================================================
USE portal_qro;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `partidos_proceso`;
DROP TABLE IF EXISTS `secciones_mapeo`;
SET FOREIGN_KEY_CHECKS = 1;
