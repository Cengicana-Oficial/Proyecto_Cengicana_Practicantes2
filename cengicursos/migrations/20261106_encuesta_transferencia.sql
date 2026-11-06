-- Encuesta de percepcion por formato (general, transferencia, investigacion) y asistencia
-- registrada desde la encuesta: quien responde por el enlace general queda con ingreso
-- (ingreso_via = 'encuesta') y, si no estaba inscrito, se crea su registro en el evento.
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eventos' AND COLUMN_NAME='encuesta_formato');
SET @s = IF(@c=0,"ALTER TABLE eventos ADD COLUMN encuesta_formato VARCHAR(20) NOT NULL DEFAULT 'general'",'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='evento_participantes' AND COLUMN_NAME='ingreso_via');
SET @s = IF(@c=0,'ALTER TABLE evento_participantes ADD COLUMN ingreso_via VARCHAR(20) NULL AFTER ingreso_en','SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='evento_encuesta_percep' AND COLUMN_NAME='logistica');
SET @s = IF(@c=0,'ALTER TABLE evento_encuesta_percep
  ADD COLUMN nombre VARCHAR(255) NULL, ADD COLUMN correo VARCHAR(255) NULL, ADD COLUMN institucion VARCHAR(255) NULL,
  ADD COLUMN cargo VARCHAR(150) NULL, ADD COLUMN area VARCHAR(150) NULL,
  ADD COLUMN logistica TINYINT UNSIGNED NULL, ADD COLUMN permanencia VARCHAR(20) NULL','SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
