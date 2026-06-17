-- =====================================================================
--  Base de datos: reportes_servicio
--  Sistema:      Municipio de Querétaro · Tickets Zendesk
--  Motor:        MySQL 5.7+ / MariaDB 10+ (compatible con MAMP)
--  Charset:      utf8mb4 (necesario para tildes, ñ y emojis si llegan)
--
--  ⚠️ Este script es IDEMPOTENTE: puedes ejecutarlo varias veces y
--  no se rompe. Si quieres BORRAR la BD desde cero, descomenta la línea
--  DROP DATABASE de abajo.
-- =====================================================================

-- DROP DATABASE IF EXISTS reportes_servicio;  -- ← descomenta para reset total

CREATE DATABASE IF NOT EXISTS reportes_servicio
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE reportes_servicio;


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
