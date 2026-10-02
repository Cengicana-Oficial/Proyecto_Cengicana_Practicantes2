-- Editar evento, difusion, encuestas y evaluacion de eventos (cengicursos/eventos_qr.php,
-- cengicursos/eventos_gestion.php y la encuesta publica cengicursos/encuesta_evento.php),
-- replicado del prototipo SIGEC v29 ("Eventos y control QR"):
--
--   eventos                 horario, lugar, cupo, descripcion y color del evento; datos de la
--                           colaboracion (en_colaboracion, colab_modalidad, colab_financia);
--                           token del enlace general / QR de la encuesta de percepcion;
--                           material de difusion (flyer, landing, Canva) y aprobacion de la
--                           empresa aliada.
--   evento_participantes    token_encuesta: enlace personal de la encuesta de percepcion.
--   evento_aliados          empresas aliadas de un evento en colaboracion.
--   evento_encuesta_colab   respuestas de la encuesta de colaboracion (una por empresa).
--   evento_encuesta_percep  respuestas de la encuesta de percepcion de participantes.
--   evento_difusion         bitacora de divulgacion (canal, fecha, detalle).
--
-- Idempotente (information_schema / CREATE TABLE IF NOT EXISTS), segura de aplicar mas de
-- una vez. Espejo: deploy/mysql/init/22-cengicursos-eventos-difusion-encuestas.sql.
--
-- Como aplicarla manualmente sobre un contenedor MySQL que ya existe:
--   docker cp cengicursos/migrations/20261001_eventos_difusion_encuestas.sql cengicana-ui-new-mysql:/tmp/m.sql
--   docker exec cengicana-ui-new-mysql sh -c 'mysql -u root -p$MYSQL_ROOT_PASSWORD cengi_cursos < /tmp/m.sql'

USE cengi_cursos;

-- ---------- eventos: datos de "Editar evento", colaboracion y difusion ----------

SET @col_hora := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'hora'
);
SET @ddl_hora := IF(
  @col_hora = 0,
  'ALTER TABLE eventos ADD COLUMN hora VARCHAR(40) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_hora; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_lugar := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'lugar'
);
SET @ddl_lugar := IF(
  @col_lugar = 0,
  'ALTER TABLE eventos ADD COLUMN lugar VARCHAR(255) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_lugar; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_cupo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'cupo'
);
SET @ddl_cupo := IF(
  @col_cupo = 0,
  'ALTER TABLE eventos ADD COLUMN cupo INT UNSIGNED NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_cupo; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_descripcion := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'descripcion'
);
SET @ddl_descripcion := IF(
  @col_descripcion = 0,
  'ALTER TABLE eventos ADD COLUMN descripcion TEXT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_descripcion; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_color := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'color'
);
SET @ddl_color := IF(
  @col_color = 0,
  'ALTER TABLE eventos ADD COLUMN color VARCHAR(7) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_color; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_en_colaboracion := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'en_colaboracion'
);
SET @ddl_en_colaboracion := IF(
  @col_en_colaboracion = 0,
  'ALTER TABLE eventos ADD COLUMN en_colaboracion TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_en_colaboracion; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_colab_modalidad := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'colab_modalidad'
);
SET @ddl_colab_modalidad := IF(
  @col_colab_modalidad = 0,
  'ALTER TABLE eventos ADD COLUMN colab_modalidad TINYINT UNSIGNED NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_colab_modalidad; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_colab_financia := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'colab_financia'
);
SET @ddl_colab_financia := IF(
  @col_colab_financia = 0,
  'ALTER TABLE eventos ADD COLUMN colab_financia VARCHAR(12) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_colab_financia; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_token_percepcion := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'token_percepcion'
);
SET @ddl_token_percepcion := IF(
  @col_token_percepcion = 0,
  'ALTER TABLE eventos ADD COLUMN token_percepcion VARCHAR(32) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_token_percepcion; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_percep_enviada_en := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'percep_enviada_en'
);
SET @ddl_percep_enviada_en := IF(
  @col_percep_enviada_en = 0,
  'ALTER TABLE eventos ADD COLUMN percep_enviada_en DATETIME NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_percep_enviada_en; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_dif_flyer := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'dif_flyer'
);
SET @ddl_dif_flyer := IF(
  @col_dif_flyer = 0,
  'ALTER TABLE eventos ADD COLUMN dif_flyer VARCHAR(255) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_dif_flyer; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_dif_flyer_nombre := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'dif_flyer_nombre'
);
SET @ddl_dif_flyer_nombre := IF(
  @col_dif_flyer_nombre = 0,
  'ALTER TABLE eventos ADD COLUMN dif_flyer_nombre VARCHAR(255) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_dif_flyer_nombre; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_dif_landing := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'dif_landing'
);
SET @ddl_dif_landing := IF(
  @col_dif_landing = 0,
  'ALTER TABLE eventos ADD COLUMN dif_landing VARCHAR(500) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_dif_landing; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_dif_canva := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'dif_canva'
);
SET @ddl_dif_canva := IF(
  @col_dif_canva = 0,
  'ALTER TABLE eventos ADD COLUMN dif_canva VARCHAR(500) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_dif_canva; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_dif_aprob_por := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'dif_aprob_por'
);
SET @ddl_dif_aprob_por := IF(
  @col_dif_aprob_por = 0,
  'ALTER TABLE eventos ADD COLUMN dif_aprob_por VARCHAR(255) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_dif_aprob_por; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_dif_aprob_fecha := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'dif_aprob_fecha'
);
SET @ddl_dif_aprob_fecha := IF(
  @col_dif_aprob_fecha = 0,
  'ALTER TABLE eventos ADD COLUMN dif_aprob_fecha DATE NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_dif_aprob_fecha; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_dif_aprob_nota := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'dif_aprob_nota'
);
SET @ddl_dif_aprob_nota := IF(
  @col_dif_aprob_nota = 0,
  'ALTER TABLE eventos ADD COLUMN dif_aprob_nota VARCHAR(500) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_dif_aprob_nota; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_idx_eventos_token_percepcion := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND INDEX_NAME = 'idx_eventos_token_percepcion'
);
SET @ddl_idx_idx_eventos_token_percepcion := IF(
  @idx_idx_eventos_token_percepcion = 0,
  'ALTER TABLE eventos ADD UNIQUE KEY idx_eventos_token_percepcion (token_percepcion)',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_idx_idx_eventos_token_percepcion; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- evento_participantes: enlace personal a la encuesta de percepcion ----------

SET @col_token_encuesta := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'token_encuesta'
);
SET @ddl_token_encuesta := IF(
  @col_token_encuesta = 0,
  'ALTER TABLE evento_participantes ADD COLUMN token_encuesta VARCHAR(32) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_token_encuesta; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_idx_evtp_token_encuesta := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND INDEX_NAME = 'idx_evtp_token_encuesta'
);
SET @ddl_idx_idx_evtp_token_encuesta := IF(
  @idx_idx_evtp_token_encuesta = 0,
  'ALTER TABLE evento_participantes ADD UNIQUE KEY idx_evtp_token_encuesta (token_encuesta)',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_idx_idx_evtp_token_encuesta; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- empresas aliadas de un evento en colaboracion ----------
-- token: enlace de la encuesta de colaboracion de esa empresa (encuesta_evento.php?t=...).

CREATE TABLE IF NOT EXISTS evento_aliados (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  evento_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(255) NOT NULL,
  contacto VARCHAR(255) NULL,
  correo VARCHAR(255) NULL,
  token VARCHAR(32) NOT NULL,
  orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  enviado_en DATETIME NULL,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_evento_aliados_token (token),
  KEY idx_evento_aliados_evento (evento_id),
  CONSTRAINT fk_evali_evento FOREIGN KEY (evento_id) REFERENCES eventos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- encuesta de colaboracion (una respuesta por empresa aliada) ----------
-- Calificaciones de 1 a 5; audit admite NULL ("No aplica").

CREATE TABLE IF NOT EXISTS evento_encuesta_colab (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  evento_id INT UNSIGNED NOT NULL,
  aliado_id INT UNSIGNED NOT NULL,
  empresa VARCHAR(255) NOT NULL,
  nombre_responde VARCHAR(255) NULL,
  coord TINYINT UNSIGNED NOT NULL,
  cumpl TINYINT UNSIGNED NOT NULL,
  difus TINYINT UNSIGNED NOT NULL,
  organ TINYINT UNSIGNED NOT NULL,
  audit TINYINT UNSIGNED NULL,
  valor TINYINT UNSIGNED NOT NULL,
  util TINYINT UNSIGNED NOT NULL,
  fortalecio VARCHAR(12) NOT NULL,
  participaria VARCHAR(2) NOT NULL,
  mejorar TEXT NULL,
  apoyo TEXT NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_evento_encuesta_colab_aliado (aliado_id),
  KEY idx_evento_encuesta_colab_evento (evento_id),
  CONSTRAINT fk_evcol_evento FOREIGN KEY (evento_id) REFERENCES eventos (id) ON DELETE CASCADE,
  CONSTRAINT fk_evcol_aliado FOREIGN KEY (aliado_id) REFERENCES evento_aliados (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- encuesta de percepcion de participantes ----------
-- evento_participante_id: NULL cuando se responde por el QR / enlace general (anonima).

CREATE TABLE IF NOT EXISTS evento_encuesta_percep (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  evento_id INT UNSIGNED NOT NULL,
  evento_participante_id INT UNSIGNED NULL,
  via VARCHAR(30) NOT NULL,
  estrellas TINYINT UNSIGNED NOT NULL,
  mejora TEXT NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_evento_encuesta_percep_participante (evento_participante_id),
  KEY idx_evento_encuesta_percep_evento (evento_id),
  CONSTRAINT fk_evper_evento FOREIGN KEY (evento_id) REFERENCES eventos (id) ON DELETE CASCADE,
  CONSTRAINT fk_evper_participante FOREIGN KEY (evento_participante_id) REFERENCES evento_participantes (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- bitacora de difusion ----------

CREATE TABLE IF NOT EXISTS evento_difusion (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  evento_id INT UNSIGNED NOT NULL,
  canal VARCHAR(20) NOT NULL,
  fecha DATE NOT NULL,
  detalle VARCHAR(500) NULL,
  registrado_por VARCHAR(255) NULL,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_evento_difusion_evento (evento_id),
  CONSTRAINT fk_evdif_evento FOREIGN KEY (evento_id) REFERENCES eventos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
