-- Ingenio y estado de pago por participante de evento (cengicursos/eventos_qr.php).
--
-- evento_participantes.ingenio_id: para un participante vinculado al directorio
-- (participante_id no nulo) el ingenio se resuelve de participantes.ingenio_id, pero para
-- un invitado externo (participante_id NULL, solo nombre_invitado/cui_invitado) no existia
-- forma de guardar su ingenio. ingenio_id guarda ese dato para invitados externos; se
-- captura en el formulario "Registrar participante" y en la carga masiva de
-- cengicursos/eventos_qr.php (donde el nombre del ingenio se resuelve contra la tabla
-- ingenios igual que en cengicursos/carga_inscripcion.php).
--
-- evento_participantes.pagado: estado "pagado / no pagado" por participante, solo
-- relevante para eventos con eventos.modalidad_pago = 'Pagado'. Se alterna desde el modal
-- de participantes (accion POST marcar_pago) y se puede fijar al registrar/editar.
--
-- Idempotente (revisa information_schema antes de cada ALTER), segura de aplicar mas de
-- una vez. Ver tambien la actualizacion correspondiente en
-- deploy/mysql/init/15-cengicursos-evento-participantes-ingenio-pago.sql para que una
-- instalacion nueva ya incluya estas columnas.
--
-- Como aplicarla manualmente sobre un contenedor MySQL que ya existe (los scripts de
-- deploy/mysql/init solo se ejecutan automaticamente la primera vez que se crea el
-- volumen):
--   docker compose -f docker-compose.prod.yml exec -T mysql \
--     mysql -u root -p cengi_cursos < cengicursos/migrations/20260908_evento_participantes_ingenio_pago.sql

USE cengi_cursos;

-- 1) evento_participantes.ingenio_id (columna + indice + FK).
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

-- 2) evento_participantes.pagado.
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
