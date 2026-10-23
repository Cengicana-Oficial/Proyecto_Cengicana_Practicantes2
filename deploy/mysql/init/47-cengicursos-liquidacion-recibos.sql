USE cengi_cursos;

-- Espejo de cengicursos/migrations/20261023_liquidacion_recibos.sql (ver comentarios alli).
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS liquidacion_recibos (
  detalle_id INT UNSIGNED NOT NULL,
  token CHAR(40) NOT NULL,
  monto DECIMAL(12,2) NOT NULL DEFAULT 0,
  enviado_en DATETIME NULL,
  enviado_a VARCHAR(255) NOT NULL DEFAULT '',
  envios SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  firmado_path VARCHAR(255) NOT NULL DEFAULT '',
  firmado_nombre VARCHAR(255) NOT NULL DEFAULT '',
  firmado_en DATETIME NULL,
  firmado_por VARCHAR(20) NOT NULL DEFAULT '',
  PRIMARY KEY (detalle_id),
  UNIQUE KEY uq_liquidacion_recibos_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
