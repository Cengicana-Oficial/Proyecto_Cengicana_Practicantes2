USE cengi_cursos;

-- Espejo de cengicursos/migrations/20261015_curso_modulos_sesiones.sql (ver comentarios alli).

SET NAMES utf8mb4;

SET @col_modulo_sesiones := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curso_modulos' AND COLUMN_NAME = 'sesiones'
);
SET @ddl_modulo_sesiones := IF(
  @col_modulo_sesiones = 0,
  'ALTER TABLE curso_modulos ADD COLUMN sesiones SMALLINT UNSIGNED NULL AFTER horas',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_modulo_sesiones; EXECUTE stmt; DEALLOCATE PREPARE stmt;
