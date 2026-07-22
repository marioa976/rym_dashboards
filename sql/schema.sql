-- =====================================================================
--  ESQUEMA ÚNICO · Portal Querétaro con Futuro
--  UNA sola base de datos: portal_qro
--  Contiene: Portal (usuarios/roles/módulos) + DIF + Zendesk.
--  Qrobici NO va aquí: usa BD remota con vistas (dwh_viajes/dwh_planes).
--
--  Importar (MAMP + MySQL 8.0.44):
--    /Applications/MAMP/Library/bin/mysql -u root -proot -P8889 -h127.0.0.1 < schema.sql
--
--  Idempotente. Para cargar datos reales de las bases viejas: sql/migrar_datos.sh
-- =====================================================================
CREATE DATABASE IF NOT EXISTS portal_qro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE portal_qro;

-- #####################################################################
-- ## 1) PORTAL (usuarios / roles / módulos)
-- #####################################################################
-- =====================================================================
--  Portal Querétaro con Futuro — Esquema base
--  Stack: MariaDB 10.4+ / MySQL 8
--  Modelo: usuarios + módulos + roles por módulo (DIF, Zendesk, Qrobici)
-- =====================================================================
--  Notas:
--   * InnoDB para FK e integridad transaccional.
--   * utf8mb4 para soporte completo de caracteres.
--   * Índices en columnas de filtro/JOIN y UNIQUE para evitar duplicados.
-- =====================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Catálogo de módulos -------------------------------------------------
CREATE TABLE IF NOT EXISTS modulos (
    id            SMALLINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    clave         VARCHAR(40)         NOT NULL,
    nombre        VARCHAR(120)        NOT NULL,
    descripcion   VARCHAR(255)        NULL,
    icono         VARCHAR(60)         NULL,
    ruta          VARCHAR(120)        NOT NULL,
    color         CHAR(7)             NULL,
    orden         SMALLINT UNSIGNED   NOT NULL DEFAULT 0,
    activo        TINYINT(1)          NOT NULL DEFAULT 1,
    creado_en     TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_modulos_clave (clave),
    KEY idx_modulos_activo_orden (activo, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuarios ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id                INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    nombre            VARCHAR(120)      NOT NULL,
    email             VARCHAR(160)      NOT NULL,
    password_hash     VARCHAR(255)      NOT NULL,
    es_admin          TINYINT(1)        NOT NULL DEFAULT 0,
    activo            TINYINT(1)        NOT NULL DEFAULT 1,
    ultimo_acceso     TIMESTAMP         NULL DEFAULT NULL,
    intentos_fallidos SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    bloqueado_hasta   TIMESTAMP         NULL DEFAULT NULL,
    creado_en         TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_email (email),
    KEY idx_usuarios_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rol por módulo ------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuario_modulo (
    usuario_id   INT UNSIGNED        NOT NULL,
    modulo_id    SMALLINT UNSIGNED   NOT NULL,
    nivel        ENUM('lector','editor','admin') NOT NULL DEFAULT 'lector',
    asignado_en  TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id, modulo_id),
    KEY idx_um_modulo (modulo_id),
    CONSTRAINT fk_um_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_um_modulo FOREIGN KEY (modulo_id)
        REFERENCES modulos (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sesiones (opcional, auditoría) -------------------------------------
CREATE TABLE IF NOT EXISTS sesiones (
    id           CHAR(64)            NOT NULL,
    usuario_id   INT UNSIGNED        NOT NULL,
    ip           VARBINARY(16)       NULL,
    user_agent   VARCHAR(255)        NULL,
    creada_en    TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_en    TIMESTAMP           NOT NULL,
    revocada     TINYINT(1)          NOT NULL DEFAULT 0,   -- cierre remoto desde el panel admin
    PRIMARY KEY (id),
    KEY idx_sesiones_usuario (usuario_id),
    KEY idx_sesiones_expira (expira_en),
    CONSTRAINT fk_sesiones_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
--  SEED
-- =====================================================================
INSERT INTO modulos (clave, nombre, descripcion, icono, ruta, color, orden) VALUES
    ('dif',     'DIF',     'Reportes y atención ciudadana DIF',       'heart',  'modules/dif/index.php',     '#005ab2', 1),
    ('zendesk', 'Zendesk', 'Métricas de tickets y soporte (Zendesk)', 'ticket', 'modules/zendesk/dashboard.php', '#2a9eda', 2),
    ('qrobici', 'Qrobici', 'Indicadores de movilidad Qrobici',        'bike',   'modules/qrobici/index.php', '#188a5b', 3)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- Admin inicial. Contraseña: Cambiar.2026  (CAMBIAR EN PRODUCCIÓN)
INSERT INTO usuarios (nombre, email, password_hash, es_admin, activo) VALUES
    ('Administrador', 'admin@qro.gob.mx',
     '$2y$10$eU0C7oGNAE/hMtgJdd/0TOR.89lxbyo9nezzYfP/cdlIcOnSbLOJi', 1, 1)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- #####################################################################
-- ## 2) MÓDULO DIF (padron, geocode_cache, import_log)
-- #####################################################################
-- =====================================================================
-- Padrón DIF - Esquema MariaDB
-- Archivo: schema.sql
-- Uso:
--   mysql -u root -p < schema.sql
--   (En MAMP suele ser:  /Applications/MAMP/Library/bin/mysql -u root -proot < schema.sql)
-- =====================================================================

-- ---------------------------------------------------------------------
-- Tabla principal: una fila por entrega de apoyo (lo que viene en el xlsx)
-- Todos los campos opcionales se permiten en NULL para poder insertar
-- aunque vengan vacíos en el origen.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS padron (
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

CREATE TABLE IF NOT EXISTS geocode_cache (
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

CREATE TABLE IF NOT EXISTS import_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    archivo         VARCHAR(255) NOT NULL,
    filas_leidas    INT NOT NULL DEFAULT 0,
    filas_insertadas INT NOT NULL DEFAULT 0,
    filas_error     INT NOT NULL DEFAULT 0,
    iniciado_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    terminado_at    DATETIME NULL,
    notas           TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- #####################################################################
-- ## 3) MÓDULO ZENDESK (tickets, catálogos, cargas, vistas v_*)
-- #####################################################################
-- =====================================================================
--  Base de datos: reportes_servicio
--  Sistema:      Municipio de Querétaro · Tickets Zendesk
--  Motor:        MySQL 5.7+ / MariaDB 10+ (compatible con MAMP)
--  Charset:      utf8mb4 (necesario para tildes, ñ y emojis si llegan)
--
--  ⚠️ Este script es IDEMPOTENTE: puedes ejecutarlo varias veces y
--  no se rompe. Si quieres BORRAR la BD desde cero, descomenta la línea

-- =====================================================================

-- =====================================================================
-- 1. CATÁLOGOS (lookup tables)
-- Permiten normalizar y evitar inconsistencias ortográficas
-- (p.ej. "Aseo Público" vs "ASEO PUBLICO"). Opcionales pero recomendados.
-- =====================================================================

CREATE TABLE IF NOT EXISTS cat_estado (
  id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre          VARCHAR(60)  NOT NULL,
  es_cerrado      TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '1 si cuenta como ticket finalizado (Resuelto, Cancelado, Improcedente)',
  es_resuelto     TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '1 sólo para Resuelto (para tasa de resolución)',
  PRIMARY KEY (id),
  UNIQUE KEY uk_estado_nombre (nombre)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cat_prioridad (
  id      TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre  VARCHAR(20) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_prioridad_nombre (nombre)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cat_canal (
  id      SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre  VARCHAR(40) NOT NULL  COMMENT 'Ticket channel: Web, Api, Voice',
  PRIMARY KEY (id),
  UNIQUE KEY uk_canal_nombre (nombre)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cat_canal_origen (
  id      SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre  VARCHAR(60) NOT NULL  COMMENT '070, WHATSAPP, APP MUNICIPIO, etc.',
  PRIMARY KEY (id),
  UNIQUE KEY uk_canal_origen_nombre (nombre)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cat_grupo (
  id      SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre  VARCHAR(120) NOT NULL  COMMENT 'Aseo Público, Áreas verdes, Alumbrado Público, etc.',
  PRIMARY KEY (id),
  UNIQUE KEY uk_grupo_nombre (nombre)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cat_tipo_servicio (
  id      SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre  VARCHAR(200) NOT NULL  COMMENT 'Recolección de tiliches, Reporte de baches, etc.',
  PRIMARY KEY (id),
  UNIQUE KEY uk_tipo_servicio_nombre (nombre)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cat_delegacion (
  id      SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre  VARCHAR(80) NOT NULL  COMMENT 'FELIX OSORES SOTOMAYOR, CENTRO HISTORICO, etc.',
  PRIMARY KEY (id),
  UNIQUE KEY uk_delegacion_nombre (nombre)
) ENGINE=InnoDB;

-- =====================================================================
-- 2. TABLA PRINCIPAL: tickets
-- Una fila por ticket. ticket_id es PK natural (viene de Zendesk),
-- de modo que la carga mensual sea idempotente vía INSERT ... ON DUPLICATE KEY UPDATE.
-- =====================================================================

CREATE TABLE IF NOT EXISTS tickets (
  ticket_id              BIGINT UNSIGNED NOT NULL  COMMENT 'Ticket ID de Zendesk',

  -- relaciones a catálogos
  estado_id              SMALLINT UNSIGNED NULL,
  prioridad_id           TINYINT  UNSIGNED NULL,
  canal_id               SMALLINT UNSIGNED NULL,
  canal_origen_id        SMALLINT UNSIGNED NULL,
  grupo_id               SMALLINT UNSIGNED NULL,
  tipo_servicio_id       SMALLINT UNSIGNED NULL,
  delegacion_id          SMALLINT UNSIGNED NULL,

  -- fechas (todas opcionales salvo created)
  fecha_creacion         DATE NOT NULL,
  fecha_estimada         DATE NULL,
  fecha_resolucion       DATE NULL,

  -- ubicación (texto libre — alta cardinalidad, no se normaliza)
  colonia                VARCHAR(180) NULL,
  direccion              VARCHAR(400) NULL,
  latitud                DECIMAL(10, 7) NULL  COMMENT 'Latitud (rango -90 a 90)',
  longitud               DECIMAL(10, 7) NULL  COMMENT 'Longitud (rango -180 a 180)',
  coordenadas_raw        VARCHAR(500)   NULL  COMMENT 'Valor original de COORDENADAS del CSV',

  -- solicitante (texto libre)
  solicitante_nombre_completo VARCHAR(200) NULL  COMMENT 'Requester name (tal como llega)',
  solicitante_nombre     VARCHAR(120) NULL,
  solicitante_apellido_p VARCHAR(80)  NULL,
  solicitante_apellido_m VARCHAR(80)  NULL,

  -- métrica numérica original
  cantidad_reportes      DECIMAL(10,2) NOT NULL DEFAULT 1.00,

  -- columnas calculadas (MySQL las recalcula al leer/insertar)
  dias_resolucion        INT GENERATED ALWAYS AS (
                            CASE WHEN fecha_resolucion IS NOT NULL
                                 THEN DATEDIFF(fecha_resolucion, fecha_creacion)
                                 ELSE NULL END
                         ) VIRTUAL,
  cumplio_sla            TINYINT(1) GENERATED ALWAYS AS (
                            CASE
                              WHEN fecha_resolucion IS NULL OR fecha_estimada IS NULL THEN NULL
                              WHEN fecha_resolucion <= fecha_estimada THEN 1
                              ELSE 0
                            END
                         ) VIRTUAL,

  -- trazabilidad de carga
  fuente_archivo         VARCHAR(180) NULL  COMMENT 'Nombre del CSV de origen',
  fecha_carga            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_actualizacion    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (ticket_id),

  -- índices para los filtros típicos del dashboard
  KEY idx_fecha_creacion   (fecha_creacion),
  KEY idx_fecha_resolucion (fecha_resolucion),
  KEY idx_estado           (estado_id),
  KEY idx_delegacion       (delegacion_id),
  KEY idx_grupo            (grupo_id),
  KEY idx_tipo_servicio    (tipo_servicio_id),
  KEY idx_canal_origen     (canal_origen_id),
  KEY idx_prioridad        (prioridad_id),
  KEY idx_geo              (latitud, longitud),

  -- foreign keys (opcionales — coméntalas si prefieres no validar referencialmente)
  CONSTRAINT fk_tickets_estado         FOREIGN KEY (estado_id)         REFERENCES cat_estado(id),
  CONSTRAINT fk_tickets_prioridad      FOREIGN KEY (prioridad_id)      REFERENCES cat_prioridad(id),
  CONSTRAINT fk_tickets_canal          FOREIGN KEY (canal_id)          REFERENCES cat_canal(id),
  CONSTRAINT fk_tickets_canal_origen   FOREIGN KEY (canal_origen_id)   REFERENCES cat_canal_origen(id),
  CONSTRAINT fk_tickets_grupo          FOREIGN KEY (grupo_id)          REFERENCES cat_grupo(id),
  CONSTRAINT fk_tickets_tipo_servicio  FOREIGN KEY (tipo_servicio_id)  REFERENCES cat_tipo_servicio(id),
  CONSTRAINT fk_tickets_delegacion     FOREIGN KEY (delegacion_id)     REFERENCES cat_delegacion(id)
) ENGINE=InnoDB;

-- =====================================================================
-- 3. BITÁCORA DE CARGAS (auditoría)
-- Registra cada importación para saber qué archivo aportó qué tickets
-- y poder rehacer una carga si algo sale mal.
-- =====================================================================

CREATE TABLE IF NOT EXISTS cargas (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre_archivo      VARCHAR(180) NOT NULL,
  fecha_inicio        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_fin           DATETIME NULL,
  filas_archivo       INT UNSIGNED NULL,
  filas_insertadas    INT UNSIGNED NULL,
  filas_actualizadas  INT UNSIGNED NULL,
  filas_ignoradas     INT UNSIGNED NULL,
  estado              ENUM('en_proceso','exitoso','fallido') NOT NULL DEFAULT 'en_proceso',
  mensaje             TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_archivo (nombre_archivo)
) ENGINE=InnoDB;

-- =====================================================================
-- 4. SEEDS · catálogos pre-cargados con valores observados
-- =====================================================================

INSERT IGNORE INTO cat_estado (nombre, es_cerrado, es_resuelto) VALUES
  ('Nuevo',                   0, 0),
  ('Abierto',                 0, 0),
  ('Asignado cuadrilla',      0, 0),
  ('En proceso cuadrilla',    0, 0),
  ('Pendiente de Información',0, 0),
  ('Resuelto',                1, 1),
  ('Cancelado',               1, 0),
  ('Improcedente',            1, 0);

INSERT IGNORE INTO cat_prioridad (nombre) VALUES ('Normal'),('High'),('Urgent');

INSERT IGNORE INTO cat_canal (nombre) VALUES ('Web'),('Api'),('Voice'),('Email'),('Chat');

INSERT IGNORE INTO cat_canal_origen (nombre) VALUES
  ('070'),
  ('WHATSAPP'),
  ('APP MUNICIPIO'),
  ('CAJEROS INTELIGENTES'),
  ('EXPEDIENTE ELECTRONICO'),
  ('SITIO WEB'),
  ('TELEFONÍA');

INSERT IGNORE INTO cat_delegacion (nombre) VALUES
  ('FELIX OSORES SOTOMAYOR'),
  ('CENTRO HISTORICO'),
  ('EPIGMENIO GONZÁLEZ'),
  ('FELIPE CARRILLO PUERTO'),
  ('JOSEFA VERGARA Y HERNÁNDEZ'),
  ('SANTA ROSA JÁUREGUI'),
  ('CAYETANO RUBIO'),
  ('CENTRO CIVICO');

-- =====================================================================
-- 5. VISTAS ÚTILES PARA EL DASHBOARD
-- =====================================================================

CREATE OR REPLACE VIEW v_tickets AS
SELECT
  t.ticket_id,
  e.nombre   AS estado,
  e.es_cerrado,
  e.es_resuelto,
  p.nombre   AS prioridad,
  c.nombre   AS canal,
  co.nombre  AS canal_origen,
  g.nombre   AS grupo,
  ts.nombre  AS tipo_servicio,
  d.nombre   AS delegacion,
  t.colonia,
  t.direccion,
  t.latitud,
  t.longitud,
  t.coordenadas_raw,
  CASE WHEN t.latitud IS NOT NULL AND t.longitud IS NOT NULL THEN 1 ELSE 0 END AS tiene_geo,
  t.solicitante_nombre_completo AS solicitante,
  t.fecha_creacion,
  t.fecha_estimada,
  t.fecha_resolucion,
  t.dias_resolucion,
  t.cumplio_sla,
  CASE
    WHEN e.es_resuelto = 0 AND t.fecha_estimada < CURDATE() THEN 1
    ELSE 0
  END AS vencido,
  t.cantidad_reportes,
  t.ticket_form_id,
  t.fuente_archivo,
  t.fecha_carga
FROM tickets t
LEFT JOIN cat_estado        e  ON e.id  = t.estado_id
LEFT JOIN cat_prioridad     p  ON p.id  = t.prioridad_id
LEFT JOIN cat_canal         c  ON c.id  = t.canal_id
LEFT JOIN cat_canal_origen  co ON co.id = t.canal_origen_id
LEFT JOIN cat_grupo         g  ON g.id  = t.grupo_id
LEFT JOIN cat_tipo_servicio ts ON ts.id = t.tipo_servicio_id
LEFT JOIN cat_delegacion    d  ON d.id  = t.delegacion_id;

-- KPI global (rápido de consultar)
CREATE OR REPLACE VIEW v_kpis_globales AS
SELECT
  COUNT(*)                                                              AS total,
  SUM(es_resuelto)                                                      AS resueltos,
  ROUND(SUM(es_resuelto)/COUNT(*)*100, 2)                               AS pct_resolucion,
  SUM(CASE WHEN es_resuelto=0 THEN 1 ELSE 0 END)                        AS no_resueltos,
  SUM(vencido)                                                          AS vencidos,
  ROUND(AVG(dias_resolucion), 2)                                        AS dias_promedio_resolucion,
  ROUND(SUM(cumplio_sla) / NULLIF(SUM(cumplio_sla IS NOT NULL),0)*100,2) AS pct_sla
FROM v_tickets;

-- KPI por delegación
CREATE OR REPLACE VIEW v_kpis_delegacion AS
SELECT
  delegacion,
  COUNT(*)                                  AS total,
  SUM(es_resuelto)                          AS resueltos,
  ROUND(SUM(es_resuelto)/COUNT(*)*100, 2)   AS pct_resolucion,
  SUM(vencido)                              AS vencidos,
  ROUND(AVG(dias_resolucion), 2)            AS dias_promedio
FROM v_tickets
WHERE delegacion IS NOT NULL
GROUP BY delegacion
ORDER BY total DESC;

-- KPI por grupo
CREATE OR REPLACE VIEW v_kpis_grupo AS
SELECT
  grupo,
  COUNT(*)                                  AS total,
  SUM(es_resuelto)                          AS resueltos,
  ROUND(SUM(es_resuelto)/COUNT(*)*100, 2)   AS pct_resolucion,
  SUM(vencido)                              AS vencidos
FROM v_tickets
WHERE grupo IS NOT NULL
GROUP BY grupo
ORDER BY total DESC;

-- Serie temporal por día
CREATE OR REPLACE VIEW v_serie_diaria AS
SELECT
  fecha_creacion AS fecha,
  COUNT(*) AS creados,
  SUM(CASE WHEN fecha_resolucion = fecha_creacion THEN 1 ELSE 0 END) AS resueltos_mismo_dia
FROM v_tickets
GROUP BY fecha_creacion
ORDER BY fecha_creacion;
