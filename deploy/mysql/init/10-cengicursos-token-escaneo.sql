USE cengi_cursos;

-- Enlace publico de escaneo QR en la entrada de un evento (cengicursos/escanear_evento.php):
-- token de 128 bits (bin2hex(random_bytes(16))), generado de forma perezosa desde
-- cengicursos/eventos_qr.php la primera vez que se pulsa "Enlace de escaneo". Quien tenga
-- la URL puede marcar el ingreso de participantes de ESE evento sin iniciar sesion (mismo
-- patron de "acceso publico controlado por posesion del link" que
-- enlaces_evaluacion_instructor.token, ver 06-cengicursos-evaluaciones-instructor.sql).
-- Idempotente, mismo patron PREPARE/EXECUTE que
-- deploy/mysql/init/09-cengicursos-gafetes-evento.sql.
SET @col_token_escaneo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'token_escaneo'
);
SET @ddl_token_escaneo := IF(
  @col_token_escaneo = 0,
  'ALTER TABLE eventos ADD COLUMN token_escaneo VARCHAR(32) NULL AFTER estado',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_token_escaneo; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_token_escaneo := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND INDEX_NAME = 'idx_eventos_token_escaneo'
);
SET @ddl_idx_token_escaneo := IF(
  @idx_token_escaneo = 0,
  'ALTER TABLE eventos ADD UNIQUE INDEX idx_eventos_token_escaneo (token_escaneo)',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_idx_token_escaneo; EXECUTE stmt; DEALLOCATE PREPARE stmt;
