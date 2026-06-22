-- ====================================================================
-- electoral.sql · Integración del módulo Electoral en portal_qro
-- Solo tablas NUEVAS (las de geografía y usuarios se comparten con el portal).
-- Idempotente. NO borra ni modifica datos existentes.
-- ====================================================================
SET NAMES utf8mb4;
USE portal_qro;

-- (Nota: NO se agregan columnas a secciones/secciones_geo. El módulo no usa
--  municipio_id/geom_type/kml_raw; comparte tu catálogo tal como está.)

-- -------------------------------------------------------------------
-- Tablas nuevas del módulo electoral (sin FK a la geografía; enlazan por num_seccion)
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `secciones_pendientes` (
  `num_seccion`    SMALLINT UNSIGNED NOT NULL,
  `municipio`      VARCHAR(120)      NULL,
  `motivo`         VARCHAR(255)      NULL,
  `created_at`     DATETIME          NOT NULL,
  PRIMARY KEY (`num_seccion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- MÓDULO ELECTORAL
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `partidos` (
  `id`           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `siglas`       VARCHAR(30)     NOT NULL,
  `nombre`       VARCHAR(160)    NOT NULL,
  `color_hex`    CHAR(7)         NULL,
  `naturaleza`   ENUM('partido','independiente') NOT NULL DEFAULT 'partido',
  `vigente`      TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`   DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_partido_siglas` (`siglas`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pre-seed de partidos comunes en México 2021-2024 (no borrables, solo edit)
INSERT INTO `partidos` (`siglas`,`nombre`,`color_hex`,`naturaleza`,`vigente`,`created_at`) VALUES
 ('PAN','Partido Acción Nacional','#003C71','partido',1,NOW()),
 ('PRI','Partido Revolucionario Institucional','#D81E2A','partido',1,NOW()),
 ('PRD','Partido de la Revolución Democrática','#FFD800','partido',1,NOW()),
 ('MC','Movimiento Ciudadano','#FF8200','partido',1,NOW()),
 ('PVEM','Partido Verde Ecologista de México','#27A03A','partido',1,NOW()),
 ('MORENA','Movimiento de Regeneración Nacional','#8B1F2E','partido',1,NOW()),
 ('PT','Partido del Trabajo','#D71920','partido',1,NOW()),
 ('RSP','Redes Sociales Progresistas','#7E1F3B','partido',0,NOW()),
 ('FXM','Fuerza por México','#5D2C8C','partido',0,NOW()),
 ('QS','Querétaro Seguro','#1E40AF','partido',1,NOW()),
 ('EMC','Encuentro Solidario','#7C3AED','partido',1,NOW()),
 ('JBLL','Juntos Buscamos un Lugar mejor','#0EA5E9','partido',1,NOW()),
 ('RHR','Renovación','#F59E0B','partido',1,NOW()),
 ('LDBG','Libertad de Buena Gente','#10B981','partido',1,NOW()),
 ('ATV','Acción de Transformación Vital','#EF4444','partido',1,NOW()),
 ('SSP','Somos Sin Partido','#6366F1','partido',1,NOW()),
 ('MRGH','Movimiento Renovador','#EC4899','partido',1,NOW()),
 ('SMG','Sumando para México','#14B8A6','partido',1,NOW())
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

CREATE TABLE IF NOT EXISTS `procesos_electorales` (
  `id`           TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `anio`         SMALLINT UNSIGNED NOT NULL,
  `nivel`        ENUM('federal','estatal') NOT NULL,
  `descripcion`  VARCHAR(180)    NOT NULL,
  `status`       ENUM('publicado','en_proceso','pendiente') NOT NULL DEFAULT 'publicado',
  `created_at`   DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_proc_anio_nivel` (`anio`, `nivel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `procesos_electorales` (`anio`,`nivel`,`descripcion`,`status`,`created_at`) VALUES
 (2021, 'estatal', 'Proceso Electoral Local 2020-2021',          'publicado', NOW()),
 (2024, 'estatal', 'Proceso Electoral Local 2023-2024',          'publicado', NOW()),
 (2024, 'federal', 'Proceso Electoral Federal 2023-2024',        'publicado', NOW())
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- (Eliminada `partidos_proceso`: no la usa ningún código del módulo.)

CREATE TABLE IF NOT EXISTS `tipos_eleccion` (
  `id`         TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo`     VARCHAR(40)     NOT NULL,
  `nombre`     VARCHAR(120)    NOT NULL,
  `ambito`     ENUM('estado','distrito','municipio','seccion') NOT NULL,
  `nivel`      ENUM('federal','estatal') NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tipo_codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tipos_eleccion` (`codigo`,`nombre`,`ambito`,`nivel`) VALUES
 ('gubernatura',        'Gubernatura',                                  'estado',    'estatal'),
 ('diputacion_mr_loc',  'Diputación local mayoría relativa',            'distrito',  'estatal'),
 ('diputacion_rp_loc',  'Diputación local representación proporcional', 'estado',    'estatal'),
 ('ayuntamiento',       'Ayuntamiento (presidencia municipal)',         'municipio', 'estatal'),
 ('diputacion_mr_fed',  'Diputación federal mayoría relativa',          'distrito',  'federal'),
 ('diputacion_rp_fed',  'Diputación federal representación proporcional','estado',   'federal'),
 ('senaduria',          'Senaduría',                                    'estado',    'federal'),
 ('presidencia',        'Presidencia de la República',                  'estado',    'federal')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

CREATE TABLE IF NOT EXISTS `elecciones` (
  `id`              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `proceso_id`      TINYINT UNSIGNED NOT NULL,
  `tipo_id`         TINYINT UNSIGNED NOT NULL,
  `ambito_codigo`   VARCHAR(20)    NULL,
  `ambito_nombre`   VARCHAR(120)   NULL,
  `status`          ENUM('publicada','pendiente','no_aplica') NOT NULL DEFAULT 'pendiente',
  `notas`           VARCHAR(255)   NULL,
  `created_at`      DATETIME       NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_eleccion` (`proceso_id`,`tipo_id`,`ambito_codigo`),
  KEY `idx_eleccion_tipo`   (`tipo_id`),
  KEY `idx_eleccion_status` (`status`),
  CONSTRAINT `fk_eleccion_proceso` FOREIGN KEY (`proceso_id`) REFERENCES `procesos_electorales`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_eleccion_tipo`    FOREIGN KEY (`tipo_id`)    REFERENCES `tipos_eleccion`(`id`)      ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coaliciones` (
  `id`           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `eleccion_id`  INT UNSIGNED   NOT NULL,
  `codigo`       VARCHAR(60)    NOT NULL,
  `nombre`       VARCHAR(180)   NULL,
  `created_at`   DATETIME       NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_coal` (`eleccion_id`,`codigo`),
  KEY `idx_coal_eleccion` (`eleccion_id`),
  CONSTRAINT `fk_coal_eleccion` FOREIGN KEY (`eleccion_id`) REFERENCES `elecciones`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coaliciones_partidos` (
  `coalicion_id` INT UNSIGNED      NOT NULL,
  `partido_id`   SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (`coalicion_id`,`partido_id`),
  CONSTRAINT `fk_cp_coalicion` FOREIGN KEY (`coalicion_id`) REFERENCES `coaliciones`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cp_partido`   FOREIGN KEY (`partido_id`)   REFERENCES `partidos`(`id`)   ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `secciones_historicas` (
  `num_seccion`  SMALLINT UNSIGNED NOT NULL,
  `anio_ultimo`  SMALLINT UNSIGNED NULL,
  `municipio`    VARCHAR(120)    NULL,
  `distrito_id`  TINYINT UNSIGNED NULL,
  `motivo`       ENUM('renumerada','dividida','fusionada','eliminada','desconocido') NOT NULL DEFAULT 'desconocido',
  `notas`        VARCHAR(255)    NULL,
  `created_at`   DATETIME        NOT NULL,
  PRIMARY KEY (`num_seccion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (Eliminada `secciones_mapeo`: no la usa ningún código del módulo.)

CREATE TABLE IF NOT EXISTS `casillas` (
  `id`            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `num_seccion`   SMALLINT UNSIGNED NOT NULL,
  `tipo_casilla`  CHAR(2)        NOT NULL,
  `num_casilla`   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `ext_contigua`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `casilla_codigo` VARCHAR(20)   NULL,
  `ubicacion`     VARCHAR(50)    NULL,
  `created_at`    DATETIME       NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_casilla` (`num_seccion`,`tipo_casilla`,`num_casilla`,`ext_contigua`),
  KEY `idx_casilla_sec` (`num_seccion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `candidatos` (
  `id`                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `eleccion_id`                INT UNSIGNED NOT NULL,
  `partido_o_coalicion_codigo` VARCHAR(60)  NOT NULL,
  `candidatura_propietaria`    VARCHAR(255) NULL,
  `candidatura_suplente`       VARCHAR(255) NULL,
  `notas`                      VARCHAR(255) NULL,
  `created_at`                 DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cand` (`eleccion_id`,`partido_o_coalicion_codigo`),
  KEY `idx_cand_eleccion` (`eleccion_id`),
  CONSTRAINT `fk_cand_eleccion` FOREIGN KEY (`eleccion_id`) REFERENCES `elecciones`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `resultados_casilla` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `eleccion_id`    INT UNSIGNED   NOT NULL,
  `casilla_id`     INT UNSIGNED   NOT NULL,
  `voto_codigo`    VARCHAR(60)    NOT NULL,
  `votos`          INT UNSIGNED   NOT NULL DEFAULT 0,
  `import_log_id`  BIGINT UNSIGNED NULL,
  `created_at`     DATETIME       NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resultado` (`eleccion_id`,`casilla_id`,`voto_codigo`),
  KEY `idx_res_eleccion` (`eleccion_id`),
  KEY `idx_res_casilla`  (`casilla_id`),
  KEY `idx_res_voto`     (`voto_codigo`),
  KEY `idx_res_import`   (`import_log_id`),
  CONSTRAINT `fk_res_eleccion` FOREIGN KEY (`eleccion_id`) REFERENCES `elecciones`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_res_casilla`  FOREIGN KEY (`casilla_id`)  REFERENCES `casillas`(`id`)   ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `resultados_casilla_meta` (
  `eleccion_id`    INT UNSIGNED   NOT NULL,
  `casilla_id`     INT UNSIGNED   NOT NULL,
  `lista_nominal`  INT UNSIGNED   NULL,
  `total_votos`    INT UNSIGNED   NULL,
  `observaciones`  VARCHAR(255)   NULL,
  `import_log_id`  BIGINT UNSIGNED NULL,
  PRIMARY KEY (`eleccion_id`,`casilla_id`),
  CONSTRAINT `fk_resm_eleccion` FOREIGN KEY (`eleccion_id`) REFERENCES `elecciones`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_resm_casilla`  FOREIGN KEY (`casilla_id`)  REFERENCES `casillas`(`id`)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `import_log_resultados` (
  `id`                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `archivo`                     VARCHAR(255)    NOT NULL,
  `tipo`                        ENUM('csv_2024_resultados','csv_2024_candidaturas','xlsx_2021_casilla','xlsx_2021_otro','otro') NOT NULL,
  `proceso_id`                  TINYINT UNSIGNED NULL,
  `eleccion_id`                 INT UNSIGNED    NULL,
  `source`                      ENUM('web','cli') NOT NULL DEFAULT 'web',
  `rows_total`                  INT UNSIGNED    NOT NULL DEFAULT 0,
  `rows_ok`                     INT UNSIGNED    NOT NULL DEFAULT 0,
  `rows_failed`                 INT UNSIGNED    NOT NULL DEFAULT 0,
  `rows_skipped`                INT UNSIGNED    NOT NULL DEFAULT 0,
  `casillas_creadas`            INT UNSIGNED    NOT NULL DEFAULT 0,
  `resultados_insertados`       INT UNSIGNED    NOT NULL DEFAULT 0,
  `secciones_historicas_creadas` INT UNSIGNED   NOT NULL DEFAULT 0,
  `partidos_nuevos`             INT UNSIGNED    NOT NULL DEFAULT 0,
  `coaliciones_nuevas`          INT UNSIGNED    NOT NULL DEFAULT 0,
  `candidatos_creados`          INT UNSIGNED    NOT NULL DEFAULT 0,
  `errores_json`                LONGTEXT        NULL,
  `started_at`                  DATETIME        NOT NULL,
  `finished_at`                 DATETIME        NULL,
  `created_by`                  INT UNSIGNED    NULL,
  PRIMARY KEY (`id`),
  KEY `idx_log_archivo` (`archivo`),
  KEY `idx_log_proceso` (`proceso_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- Registro del módulo en el portal (aparece para los admin al re-loguear;
-- a los demás se les asigna desde Administración → Usuarios).
-- ====================================================================
INSERT INTO modulos (clave, nombre, descripcion, icono, ruta, color, orden) VALUES
  ('electoral', 'Electoral', 'Resultados IEEQ y rentabilidad por sección',
   'chart', 'modules/electoral/public/index.php', '#ce3a2b', 4)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion),
                        ruta = VALUES(ruta), icono = VALUES(icono), color = VALUES(color);
