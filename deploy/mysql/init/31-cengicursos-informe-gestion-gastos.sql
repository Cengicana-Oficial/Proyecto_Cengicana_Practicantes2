USE cengi_cursos;

-- Espejo de cengicursos/migrations/20261013_informe_gestion_gastos.sql (ver comentarios alli).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS gestion_gastos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo VARCHAR(10) NOT NULL,
  ref_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  concepto VARCHAR(255) NOT NULL,
  categoria VARCHAR(60) NOT NULL DEFAULT 'Otro',
  documento VARCHAR(80) NOT NULL DEFAULT '',
  monto DECIMAL(12,2) NOT NULL DEFAULT 0,
  creado_por_nombre VARCHAR(255) NOT NULL DEFAULT '',
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_gestion_gastos_ref (tipo, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
