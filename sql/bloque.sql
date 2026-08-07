-- =====================================================================
--  Módulo Bloque (edificio de innovación y tecnología) — registro en el portal.
--  Idempotente. Las tablas de datos (esquema NUEVO: bloque_usuario / bloque_evento /
--  bloque_sesion / bloque_invitado / bloque_evento_invitado) ya viven en portal_qro;
--  aquí solo se registra el módulo. (Las tablas viejas usuarios_bloque/asistencias/
--  actividades quedan deprecadas.)
-- =====================================================================
SET NAMES utf8mb4;
USE portal_qro;

INSERT INTO modulos (clave, nombre, descripcion, icono, ruta, color, orden)
VALUES ('bloque', 'Bloque', 'Innovación y tecnología · cursos, actividades y asistencia',
        'chip', 'modules/bloque/index.php', '#2a9eda', 6)
ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre), descripcion = VALUES(descripcion), icono = VALUES(icono),
  ruta = VALUES(ruta), color = VALUES(color), orden = VALUES(orden);
