-- =====================================================================
-- Padrón DIF - Esquema MariaDB
-- Archivo: schema.sql
-- Uso:
--   mysql -u root -p < schema.sql
--   (En MAMP suele ser:  /Applications/MAMP/Library/bin/mysql -u root -proot < schema.sql)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS padron_dif
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE padron_dif;

-- ---------------------------------------------------------------------
-- Tabla principal: una fila por entrega de apoyo (lo que viene en el xlsx)
-- Todos los campos opcionales se permiten en NULL para poder insertar
-- aunque vengan vacíos en el origen.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS padron;
CREATE TABLE padron (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Datos del apoyo
    fecha_registro    DATE         NULL,
    fecha_entrega     DATE         NULL,
    cantidad          INT          NULL,
    programa          VARCHAR(255) NULL,
    coordinacion      VARCHAR(255) NULL,
    tipo_apoyo        VARCHAR(255) NULL,
    recibe_ciudadano  VARCHAR(10)  NULL,
    lugar_entrega     VARCHAR(255) NULL,
    nombre_recibe     VARCHAR(255) NULL,

    -- Datos del ciudadano
    ciudadano         VARCHAR(255) NULL,
    sexo              VARCHAR(20)  NULL,
    curp              VARCHAR(20)  NULL,
    fecha_nacimiento  DATE         NULL,
    edad              SMALLINT     NULL,

    -- Domicilio
    cp                VARCHAR(10)  NULL,
    delegacion        VARCHAR(255) NULL,
    colonia           VARCHAR(255) NULL,
    calle_numero      VARCHAR(255) NULL,

    -- Geolocalización
    latitud           DECIMAL(10,7) NULL,
    longitud          DECIMAL(10,7) NULL,
    geocode_status    VARCHAR(32)   NULL,  -- OK, ZERO_RESULTS, ERROR, PENDIENTE
    geocode_source    VARCHAR(64)   NULL,  -- google_maps / origen
    geocode_estrategia VARCHAR(64)  NULL,  -- calle_completa, colonia_cp, cp, colonia, etc.
    geocode_precision VARCHAR(32)   NULL,  -- ROOFTOP, RANGE_INTERPOLATED, GEOMETRIC_CENTER, APPROXIMATE
    geocode_address   TEXT          NULL,  -- formatted_address devuelto por Google
    geocode_intentos  INT NOT NULL DEFAULT 0,
    geocode_at        DATETIME      NULL,

    -- Metadatos
    fila_origen       INT           NULL,  -- número de fila en el xlsx
    archivo_origen    VARCHAR(255)  NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_curp           (curp),
    KEY idx_cp             (cp),
    KEY idx_colonia        (colonia(64)),
    KEY idx_delegacion     (delegacion(64)),
    KEY idx_geocode_status (geocode_status),
    KEY idx_geo_pendiente  (latitud, longitud),
    KEY idx_fecha_entrega  (fecha_entrega)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- Cache de geocodificación: para no repetir llamadas a Google Maps
-- cuando varios registros comparten la misma dirección / CP / colonia.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS geocode_cache;
CREATE TABLE geocode_cache (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    query_hash        CHAR(64)     NOT NULL,
    query_text        VARCHAR(512) NOT NULL,
    estrategia        VARCHAR(64)  NOT NULL,
    status            VARCHAR(32)  NOT NULL,  -- OK, ZERO_RESULTS, OVER_QUERY_LIMIT, etc.
    latitud           DECIMAL(10,7) NULL,
    longitud          DECIMAL(10,7) NULL,
    formatted_address TEXT          NULL,
    location_type     VARCHAR(32)   NULL,
    raw_response      LONGTEXT      NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_query_hash (query_hash),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- Tabla de log de importaciones (opcional pero útil)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS import_log;
CREATE TABLE import_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    archivo         VARCHAR(255) NOT NULL,
    filas_leidas    INT NOT NULL DEFAULT 0,
    filas_insertadas INT NOT NULL DEFAULT 0,
    filas_error     INT NOT NULL DEFAULT 0,
    iniciado_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    terminado_at    DATETIME NULL,
    notas           TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
