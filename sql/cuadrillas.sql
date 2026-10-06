-- =====================================================================
--  Módulo Cuadrillas — rutas asignables a cuadrillas de campo, atendidas
--  desde una app Android (Flutter), con estatus y evidencias.
--  Decisiones: backend en el mismo portal; evidencias en Google Cloud
--  Storage (solo se guarda la URL); paradas = tickets de Zendesk;
--  operadores en padrón APARTE (login propio, no la tabla `usuarios`).
--  Idempotente (CREATE TABLE IF NOT EXISTS + ON DUPLICATE KEY UPDATE).
-- =====================================================================
SET NAMES utf8mb4;
USE portal_qro;

-- -------- Registro del módulo en el portal --------
INSERT INTO modulos (clave, nombre, descripcion, icono, ruta, color, orden)
VALUES ('cuadrillas', 'Cuadrillas',
        'Genera rutas, asígnalas a cuadrillas de campo y dale seguimiento por app (estatus + evidencias)',
        'delivery', 'modules/cuadrillas/index.php', '#0f766e', 20)
ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre), descripcion = VALUES(descripcion), icono = VALUES(icono),
  ruta = VALUES(ruta), color = VALUES(color), orden = VALUES(orden);

-- =====================================================================
--  PADRÓN DE CUADRILLAS Y OPERADORES (aparte de `usuarios`)
-- =====================================================================
CREATE TABLE IF NOT EXISTS cuadrilla (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre      VARCHAR(120) NOT NULL,
  zona_base   VARCHAR(160) NULL,                 -- delegación/base de operación
  color       CHAR(7)      NOT NULL DEFAULT '#0f766e',
  activa      TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_activa (activa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cuadrilla_operador (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cuadrilla_id INT UNSIGNED NULL,                -- puede estar sin cuadrilla asignada
  nombre       VARCHAR(160) NOT NULL,
  telefono     VARCHAR(32)  NULL,
  usuario      VARCHAR(80)  NOT NULL,            -- login para la app (único)
  pass_hash    VARCHAR(255) NOT NULL,            -- password_hash() (bcrypt/argon)
  rol          ENUM('lider','operador') NOT NULL DEFAULT 'operador',
  activo       TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuario (usuario),
  KEY idx_cuadrilla (cuadrilla_id),
  CONSTRAINT fk_oper_cuadrilla FOREIGN KEY (cuadrilla_id)
    REFERENCES cuadrilla(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sesiones/tokens de la app (revocables). Guardamos solo el hash del token.
CREATE TABLE IF NOT EXISTS operador_sesion (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  operador_id  INT UNSIGNED NOT NULL,
  token_hash   CHAR(64)     NOT NULL,            -- sha256 del token emitido
  device       VARCHAR(160) NULL,               -- modelo/FCM token corto para push
  fcm_token    VARCHAR(255) NULL,               -- token de Firebase Cloud Messaging
  expira_en    DATETIME     NULL,
  creado_en    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ult_uso_en   TIMESTAMP    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token_hash),
  KEY idx_oper (operador_id),
  CONSTRAINT fk_sesion_oper FOREIGN KEY (operador_id)
    REFERENCES cuadrilla_operador(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  ÓRDENES (una ruta despachada a una cuadrilla en una fecha)
--  Nace de un plan guardado en `cuadrillas_planes`.
-- =====================================================================
CREATE TABLE IF NOT EXISTS orden (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  plan_id      INT UNSIGNED NULL,               -- cuadrillas_planes.id (origen del trazo)
  cuadrilla_id INT UNSIGNED NULL,
  fecha        DATE         NOT NULL,
  titulo       VARCHAR(160) NOT NULL,
  estatus      ENUM('borrador','despachada','en_proceso','cerrada','cancelada')
               NOT NULL DEFAULT 'borrador',
  n_paradas    INT          NOT NULL DEFAULT 0,
  n_resueltas  INT          NOT NULL DEFAULT 0,
  km           DECIMAL(10,1) NOT NULL DEFAULT 0,
  creado_por   INT UNSIGNED NULL,               -- usuarios.id (supervisor del portal)
  creado_en    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  despachada_en DATETIME    NULL,
  cerrada_en   DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_cuadrilla_fecha (cuadrilla_id, fecha),
  KEY idx_estatus (estatus),
  CONSTRAINT fk_orden_cuadrilla FOREIGN KEY (cuadrilla_id)
    REFERENCES cuadrilla(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orden_parada (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  orden_id     INT UNSIGNED NOT NULL,
  idx          SMALLINT     NOT NULL DEFAULT 0,  -- orden de visita dentro de la ruta
  ticket_id    BIGINT       NULL,               -- tickets de Zendesk (fuente única por ahora)
  titulo       VARCHAR(255) NULL,
  direccion    VARCHAR(255) NULL,
  lat          DECIMAL(10,7) NULL,
  lng          DECIMAL(10,7) NULL,
  estatus      ENUM('pendiente','en_camino','en_sitio','resuelta','no_resuelta')
               NOT NULL DEFAULT 'pendiente',
  motivo_no    VARCHAR(255) NULL,               -- por qué no se resolvió
  resuelta_en  DATETIME     NULL,
  actualizado_en TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_orden (orden_id, idx),
  KEY idx_ticket (ticket_id),
  KEY idx_estatus (estatus),
  CONSTRAINT fk_parada_orden FOREIGN KEY (orden_id)
    REFERENCES orden(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Evidencias (foto/nota). La imagen vive en Google Cloud Storage; aquí solo la URL.
CREATE TABLE IF NOT EXISTS parada_evidencia (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parada_id    INT UNSIGNED NOT NULL,
  url          VARCHAR(512) NOT NULL,           -- objeto en GCS (gs:// o https firmado)
  tipo         ENUM('antes','despues','otro') NOT NULL DEFAULT 'otro',
  nota         VARCHAR(500) NULL,
  lat          DECIMAL(10,7) NULL,
  lng          DECIMAL(10,7) NULL,
  operador_id  INT UNSIGNED NULL,
  creado_en    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_parada (parada_id),
  CONSTRAINT fk_evid_parada FOREIGN KEY (parada_id)
    REFERENCES orden_parada(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitácora inmutable (auditoría): cada cambio de estatus / ubicación.
CREATE TABLE IF NOT EXISTS orden_evento (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  orden_id     INT UNSIGNED NOT NULL,
  parada_id    INT UNSIGNED NULL,
  operador_id  INT UNSIGNED NULL,
  tipo         VARCHAR(40)  NOT NULL,           -- 'despacho','en_camino','en_sitio','resuelta',...
  detalle      JSON         NULL,
  lat          DECIMAL(10,7) NULL,
  lng          DECIMAL(10,7) NULL,
  creado_en    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_orden (orden_id, creado_en),
  KEY idx_parada (parada_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
