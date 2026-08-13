USE cengi_cursos;

-- Envio del gafete de evento por correo: para invitados externos registrados en
-- evento_participantes (participante_id NULL) no existia ninguna columna de correo, solo
-- nombre_invitado/cui_invitado. correo_invitado guarda el correo capturado en el
-- formulario "Registrar participante" de cengicursos/eventos_qr.php, usado por
-- cengicursos/enviar_gafetes_evento.php cuando no hay participante_id (y por lo tanto no
-- hay participantes.correo_participantes que resolver).
-- Idempotente, mismo patron PREPARE/EXECUTE que
-- deploy/mysql/init/07-cengicursos-recordatorio-cupo.sql.
SET @col_correo_invitado := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'correo_invitado'
);
SET @ddl_correo_invitado := IF(
  @col_correo_invitado = 0,
  'ALTER TABLE evento_participantes ADD COLUMN correo_invitado VARCHAR(255) NULL AFTER cui_invitado',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_correo_invitado; EXECUTE stmt; DEALLOCATE PREPARE stmt;
