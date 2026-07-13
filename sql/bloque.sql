-- =====================================================================
--  Módulo Bloque (edificio de innovación y tecnología) — registro en el portal.
--  Idempotente. Las tablas de datos (usuarios_bloque / v_usuarios / asistencias /
--  actividades) ya viven en portal_qro; aquí solo se registra el módulo.
-- =====================================================================
SET NAMES utf8mb4;
USE portal_qro;

INSERT INTO modulos (clave, nombre, descripcion, icono, ruta, color, orden)
VALUES ('bloque', 'Bloque', 'Innovación y tecnología · cursos, actividades y asistencia',
        'chip', 'modules/bloque/index.php', '#2a9eda', 6)
ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre), descripcion = VALUES(descripcion), icono = VALUES(icono),
  ruta = VALUES(ruta), color = VALUES(color), orden = VALUES(orden);
