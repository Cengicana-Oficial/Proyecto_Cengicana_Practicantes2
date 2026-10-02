-- Datos del formulario publico de inscripcion a eventos (cengicursos/inscripcion_evento.php),
-- replicado del prototipo SIGEC v29 ("Inscripcion a evento"):
--
--   telefono_invitado     Telefono / WhatsApp del participante (todos los eventos).
--   institucion_invitado  "Institucion o ingenio" tal como lo escribe el participante. Si
--                         coincide con un ingenio del catalogo tambien se guarda ingenio_id.
--   pago_boleta           N.° de boleta o transferencia      (solo eventos Pagado)
--   pago_banco            Banco                               (solo eventos Pagado)
--   pago_fecha            Fecha del pago                      (solo eventos Pagado)
--   pago_monto            Monto pagado, en quetzales          (solo eventos Pagado)
--
-- Se aplica despues de 20260928_evento_participantes_recibo_pago.sql. Todas las columnas
-- son NULL: los participantes registrados antes, o desde el panel de administracion
-- (cengicursos/eventos_qr.php), quedan sin estos datos.
--
-- Idempotente (revisa information_schema antes de cada ALTER), segura de aplicar mas de
-- una vez. Espejo: deploy/mysql/init/21-cengicursos-evento-participantes-datos-pago.sql.
--
-- Como aplicarla manualmente sobre un contenedor MySQL que ya existe:
--   docker cp cengicursos/migrations/20261001_evento_participantes_datos_pago.sql cengicana-ui-new-mysql:/tmp/m.sql
--   docker exec cengicana-ui-new-mysql sh -c 'mysql -u root -p$MYSQL_ROOT_PASSWORD cengi_cursos < /tmp/m.sql'

USE cengi_cursos;

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

