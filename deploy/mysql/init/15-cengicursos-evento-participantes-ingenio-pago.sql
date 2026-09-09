USE cengi_cursos;

-- Ingenio y estado de pago por participante de evento.
-- Se mantiene como ALTER idempotente para instalaciones nuevas y actualizaciones.
-- Espejo de cengicursos/migrations/20260908_evento_participantes_ingenio_pago.sql.

SET @col_ingenio_id := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'ingenio_id'
);
SET @ddl_ingenio_id := IF(
  @col_ingenio_id = 0,
  'ALTER TABLE evento_participantes ADD COLUMN ingenio_id INT UNSIGNED NULL AFTER correo_invitado',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_ingenio_id; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_ingenio_id := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND INDEX_NAME = 'idx_evento_participantes_ingenio'
);
SET @ddl_idx_ingenio_id := IF(
  @idx_ingenio_id = 0,
  'ALTER TABLE evento_participantes ADD KEY idx_evento_participantes_ingenio (ingenio_id)',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_idx_ingenio_id; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_ingenio_id := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes'
    AND CONSTRAINT_NAME = 'fk_evtp_ingenio'
);
SET @ddl_fk_ingenio_id := IF(
  @fk_ingenio_id = 0,
  'ALTER TABLE evento_participantes ADD CONSTRAINT fk_evtp_ingenio FOREIGN KEY (ingenio_id) REFERENCES ingenios (id)',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_fk_ingenio_id; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_pagado := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'pagado'
);
SET @ddl_pagado := IF(
  @col_pagado = 0,
  'ALTER TABLE evento_participantes ADD COLUMN pagado TINYINT(1) NOT NULL DEFAULT 0 AFTER ingreso_en',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_pagado; EXECUTE stmt; DEALLOCATE PREPARE stmt;
