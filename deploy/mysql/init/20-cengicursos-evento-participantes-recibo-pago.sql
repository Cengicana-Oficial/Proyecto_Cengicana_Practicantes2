USE cengi_cursos;

-- Recibo de pago (comprobante PDF/JPG/PNG) por participante de evento.
-- Se mantiene como ALTER idempotente para instalaciones nuevas y actualizaciones.
-- Espejo de cengicursos/migrations/20260928_evento_participantes_recibo_pago.sql.

SET @col_recibo_pago := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'recibo_pago'
);
SET @ddl_recibo_pago := IF(
  @col_recibo_pago = 0,
  'ALTER TABLE evento_participantes ADD COLUMN recibo_pago VARCHAR(255) NULL AFTER pagado',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_recibo_pago; EXECUTE stmt; DEALLOCATE PREPARE stmt;
