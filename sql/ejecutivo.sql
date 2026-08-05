-- =====================================================================
--  Módulo Ejecutivo — tablero de dirección que CRUZA los demás módulos.
--  Solo LEE datos de portal_qro (DIF, Zendesk, Bloque, Áreas Verdes, Obras,
--  Electoral). No crea tablas. Idempotente.
-- =====================================================================
SET NAMES utf8mb4;
USE portal_qro;

INSERT INTO modulos (clave, nombre, descripcion, icono, ruta, color, orden)
VALUES ('ejecutivo', 'Ejecutivo',
        'Tablero de dirección · cruza indicadores y mapa por capas de todos los módulos',
        'chart', 'modules/ejecutivo/index.php', '#254185', 0)
ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre), descripcion = VALUES(descripcion), icono = VALUES(icono),
  ruta = VALUES(ruta), color = VALUES(color), orden = VALUES(orden);
