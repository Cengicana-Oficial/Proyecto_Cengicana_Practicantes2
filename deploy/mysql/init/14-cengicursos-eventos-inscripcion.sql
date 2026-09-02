USE cengi_cursos;

-- Formulario publico de eventos y estadistica de eventos gratuitos/pagados.
-- Se mantiene como ALTER idempotente para instalaciones nuevas y actualizaciones.
SET @col_modalidad_pago := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'modalidad_pago'
);
SET @ddl_modalidad_pago := IF(
  @col_modalidad_pago = 0,
  "ALTER TABLE eventos ADD COLUMN modalidad_pago VARCHAR(10) NOT NULL DEFAULT 'Gratuito' AFTER tipo",
  'SELECT 1'
);
PREPARE stmt FROM @ddl_modalidad_pago; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_costo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'costo'
);
SET @ddl_costo := IF(
  @col_costo = 0,
  'ALTER TABLE eventos ADD COLUMN costo DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER modalidad_pago',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_costo; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_token_inscripcion := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'token_inscripcion'
);
SET @ddl_token_inscripcion := IF(
  @col_token_inscripcion = 0,
  'ALTER TABLE eventos ADD COLUMN token_inscripcion VARCHAR(32) NULL AFTER token_escaneo',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_token_inscripcion; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_token_inscripcion := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND INDEX_NAME = 'idx_eventos_token_inscripcion'
);
SET @ddl_idx_token_inscripcion := IF(
  @idx_token_inscripcion = 0,
  'ALTER TABLE eventos ADD UNIQUE INDEX idx_eventos_token_inscripcion (token_inscripcion)',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_idx_token_inscripcion; EXECUTE stmt; DEALLOCATE PREPARE stmt;

