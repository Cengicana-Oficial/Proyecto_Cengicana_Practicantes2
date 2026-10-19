USE cengi_cursos;

-- Espejo de cengicursos/migrations/20261016_liquidacion_versiones.sql (ver comentarios alli).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS liquidacion_versiones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  liquidacion_id INT UNSIGNED NOT NULL,
  version SMALLINT UNSIGNED NOT NULL,
  motivo VARCHAR(500) NOT NULL DEFAULT '',
  cambios TEXT NULL,
  datos LONGTEXT NOT NULL,
  total_antes DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_despues DECIMAL(12,2) NOT NULL DEFAULT 0,
  usuario VARCHAR(255) NOT NULL DEFAULT '',
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_liquidacion_version (liquidacion_id, version),
  CONSTRAINT fk_liquidacion_versiones_liq FOREIGN KEY (liquidacion_id) REFERENCES liquidaciones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
