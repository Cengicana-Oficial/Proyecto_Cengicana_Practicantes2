-- Puesto o cargo del inscrito a un evento (obligatorio en la inscripcion publica,
-- opcional en el registro individual y la carga masiva). Comites lo usa al integrar
-- al asistente en su directorio.
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='evento_participantes' AND COLUMN_NAME='cargo_invitado');
SET @s = IF(@c=0,'ALTER TABLE evento_participantes ADD COLUMN cargo_invitado VARCHAR(150) NULL AFTER institucion_invitado','SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
