USE cengi_cursos;

-- Espejo de cengicursos/migrations/20261011_eventos_imagen_logo.sql (ver comentarios alli).

SET NAMES utf8mb4;

SET @col_ev_img := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'imagen'
);
SET @ddl_ev_img := IF(
  @col_ev_img = 0,
  'ALTER TABLE eventos ADD COLUMN imagen VARCHAR(255) NULL, ADD COLUMN logo VARCHAR(255) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_ev_img; EXECUTE stmt; DEALLOCATE PREPARE stmt;
