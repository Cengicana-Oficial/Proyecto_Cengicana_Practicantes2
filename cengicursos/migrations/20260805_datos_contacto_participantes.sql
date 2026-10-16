-- Datos de contacto de participantes y grado academico en las solicitudes de inscripcion.
-- Idempotente: cada columna se agrega solo si falta (antes era un ALTER directo que fallaba
-- con "Duplicate column" al volver a ejecutarse). Las columnas ya estan en
-- deploy/mysql/init/02-cengicursos.sql para las instalaciones desde cero.

SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'participantes' AND COLUMN_NAME = 'correo_participantes') = 0,
  'ALTER TABLE participantes ADD COLUMN correo_participantes VARCHAR(255) NOT NULL DEFAULT \'\' AFTER area_participantes', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'participantes' AND COLUMN_NAME = 'grado_academico_participantes') = 0,
  'ALTER TABLE participantes ADD COLUMN grado_academico_participantes VARCHAR(255) NOT NULL DEFAULT \'\' AFTER correo_participantes', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'participantes' AND COLUMN_NAME = 'telefono_participantes') = 0,
  'ALTER TABLE participantes ADD COLUMN telefono_participantes VARCHAR(50) NOT NULL DEFAULT \'\' AFTER grado_academico_participantes', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'grado_academico') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN grado_academico VARCHAR(255) NOT NULL DEFAULT \'\' AFTER area_participante', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
