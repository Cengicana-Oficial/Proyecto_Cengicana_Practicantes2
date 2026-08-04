USE cengi_cursos;

-- Campos del formulario ampliado de cursos. Cada alteracion es idempotente para
-- que esta migracion pueda ejecutarse tanto en instalaciones nuevas como existentes.
SET @col_codigo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cursos' AND COLUMN_NAME = 'codigo_curso'
);
SET @ddl_codigo := IF(
  @col_codigo = 0,
  'ALTER TABLE cursos ADD COLUMN codigo_curso VARCHAR(30) NULL AFTER id',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_codigo; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_actividad := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cursos' AND COLUMN_NAME = 'actividad_tipo'
);
SET @ddl_actividad := IF(
  @col_actividad = 0,
  "ALTER TABLE cursos ADD COLUMN actividad_tipo VARCHAR(40) NOT NULL DEFAULT 'formacion_academica' AFTER instructor_id",
  'SELECT 1'
);
PREPARE stmt FROM @ddl_actividad; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_area := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cursos' AND COLUMN_NAME = 'area_tecnica'
);
SET @ddl_area := IF(
  @col_area = 0,
  "ALTER TABLE cursos ADD COLUMN area_tecnica VARCHAR(120) NOT NULL DEFAULT '' AFTER nombre_cursos",
  'SELECT 1'
);
PREPARE stmt FROM @ddl_area; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_cupo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cursos' AND COLUMN_NAME = 'cupo'
);
SET @ddl_cupo := IF(
  @col_cupo = 0,
  'ALTER TABLE cursos ADD COLUMN cupo INT UNSIGNED NULL AFTER horario',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_cupo; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_codigo := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cursos' AND INDEX_NAME = 'uq_cursos_codigo'
);
SET @ddl_idx_codigo := IF(
  @idx_codigo = 0,
  'ALTER TABLE cursos ADD UNIQUE KEY uq_cursos_codigo (codigo_curso)',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_idx_codigo; EXECUTE stmt; DEALLOCATE PREPARE stmt;
