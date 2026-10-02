USE cengi_cursos;

-- Telefono, institucion y datos del pago (boleta, banco, fecha, monto) por participante
-- de evento, capturados en el formulario publico de inscripcion.
-- Se mantiene como ALTER idempotente para instalaciones nuevas y actualizaciones.
-- Espejo de cengicursos/migrations/20261001_evento_participantes_datos_pago.sql.

SET @col_telefono_invitado := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'telefono_invitado'
);
SET @ddl_telefono_invitado := IF(
  @col_telefono_invitado = 0,
  'ALTER TABLE evento_participantes ADD COLUMN telefono_invitado VARCHAR(30) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_telefono_invitado; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_institucion_invitado := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'institucion_invitado'
);
SET @ddl_institucion_invitado := IF(
  @col_institucion_invitado = 0,
  'ALTER TABLE evento_participantes ADD COLUMN institucion_invitado VARCHAR(255) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_institucion_invitado; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_pago_boleta := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'pago_boleta'
);
SET @ddl_pago_boleta := IF(
  @col_pago_boleta = 0,
  'ALTER TABLE evento_participantes ADD COLUMN pago_boleta VARCHAR(60) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_pago_boleta; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_pago_banco := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'pago_banco'
);
SET @ddl_pago_banco := IF(
  @col_pago_banco = 0,
  'ALTER TABLE evento_participantes ADD COLUMN pago_banco VARCHAR(120) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_pago_banco; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_pago_fecha := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'pago_fecha'
);
SET @ddl_pago_fecha := IF(
  @col_pago_fecha = 0,
  'ALTER TABLE evento_participantes ADD COLUMN pago_fecha DATE NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_pago_fecha; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_pago_monto := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'pago_monto'
);
SET @ddl_pago_monto := IF(
  @col_pago_monto = 0,
  'ALTER TABLE evento_participantes ADD COLUMN pago_monto DECIMAL(10,2) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_pago_monto; EXECUTE stmt; DEALLOCATE PREPARE stmt;

