-- Enlace publico de escaneo QR en la entrada de un evento (cengicursos/escanear_evento.php):
-- igual que enlaces_evaluacion_instructor.token (ver cengicursos/curso_form_helpers.php,
-- funcion cengi_asegurar_enlace_evaluacion_instructor()), es un token de 128 bits
-- (bin2hex(random_bytes(16))) que sirve como "acceso publico controlado por posesion del
-- link": quien tenga la URL escanear_evento.php?token=... puede marcar el ingreso de
-- participantes de ESE evento sin necesidad de iniciar sesion (es intencional, para que
-- la persona en la puerta del evento pueda escanear desde su celular sin cuenta).
--
-- Se genera de forma perezosa (lazy) la primera vez que alguien pulsa "Enlace de escaneo"
-- en cengicursos/eventos_qr.php, no al crear el evento: los eventos ya existentes no
-- requieren backfill, la columna simplemente queda NULL hasta que se usa.
--
-- Idempotente, mismo patron PREPARE/EXECUTE que
-- cengicursos/migrations/20260813_gafetes_evento_correo_invitado.sql.
--
-- Ver tambien la actualizacion correspondiente en
-- deploy/mysql/init/10-cengicursos-token-escaneo.sql para que una instalacion nueva ya
-- incluya esta columna desde el CREATE TABLE + ALTER TABLE inicial.
--
-- Como aplicarla manualmente sobre un contenedor MySQL que ya existe:
--   docker compose -f docker-compose.prod.yml exec -T mysql \
--     mysql -u root -p cengi_cursos < cengicursos/migrations/20260813_token_escaneo_eventos.sql

USE cengi_cursos;

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

-- Unico (permite multiples NULL en MySQL, ya que NULL nunca es igual a NULL en una
-- restriccion UNIQUE), para que un token nunca pueda resolver a mas de un evento.
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
