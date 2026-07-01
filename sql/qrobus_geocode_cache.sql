-- =====================================================================
--  Tabla de caché de geocodificación para Qrobus.
--  Córrela UNA vez con una cuenta con permiso CREATE, en la BD remota
--  donde vive dwh_unidos (la de Qrobus, p. ej. `iqt`).
--
--  El usuario de la app (Usr8IntL00k) NO necesita CREATE: solo necesita
--  SELECT + INSERT sobre esta tabla para leer/escribir la caché.
--  Si algún día quieres quitarla:  DROP TABLE geocode_cache;
-- =====================================================================

CREATE TABLE IF NOT EXISTS `geocode_cache` (
  `id`                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `query_hash`        CHAR(64)     NOT NULL,
  `query_text`        VARCHAR(512) NOT NULL,
  `estrategia`        VARCHAR(64)  NOT NULL,
  `status`            VARCHAR(32)  NOT NULL,
  `latitud`           DOUBLE       NULL,
  `longitud`          DOUBLE       NULL,
  `formatted_address` TEXT         NULL,
  `location_type`     VARCHAR(32)  NULL,
  `raw_response`      LONGTEXT     NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_query_hash` (`query_hash`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Si el usuario de la app tiene permisos por tabla (no a nivel BD), otorga:
-- GRANT SELECT, INSERT, UPDATE ON `iqt`.`geocode_cache` TO 'Usr8IntL00k'@'%';
