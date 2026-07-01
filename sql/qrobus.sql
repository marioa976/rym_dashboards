-- =====================================================================
--  Módulo Qrobus (Beneficiarios Unidos) — registro en el portal.
--  Idempotente: correr una o varias veces es seguro.
--  NOTA: la tabla de datos (dwh_unidos) vive en la BD REMOTA de Qrobus
--  y la pueblas tú; aquí solo se registra el módulo en el portal.
-- =====================================================================
SET NAMES utf8mb4;
USE portal_qro;

INSERT INTO modulos (clave, nombre, descripcion, icono, ruta, color, orden)
VALUES ('qrobus', 'Qrobus', 'Beneficiarios Unidos · geocodificación y análisis territorial',
        'map', 'modules/qrobus/index.php', '#188a5b', 5)
ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre), descripcion = VALUES(descripcion), icono = VALUES(icono),
  ruta = VALUES(ruta), color = VALUES(color), orden = VALUES(orden);
