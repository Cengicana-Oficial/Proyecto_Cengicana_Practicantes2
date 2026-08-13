-- Envio del gafete de evento por correo (cengicursos/enviar_gafetes_evento.php): para un
-- participante vinculado al directorio (evento_participantes.participante_id no nulo) el
-- correo destino se resuelve de participantes.correo_participantes, pero para un invitado
-- externo (participante_id NULL, solo nombre_invitado/cui_invitado) no existia ninguna
-- columna de correo. correo_invitado guarda ese dato para invitados externos; se captura
-- en el mismo formulario "Registrar participante" de cengicursos/eventos_qr.php.
--
-- Idempotente, mismo patron PREPARE/EXECUTE que
-- cengicursos/migrations/20260811_recordatorio_cupo_cursos.sql.
--
-- Ver tambien la actualizacion correspondiente en
-- deploy/mysql/init/09-cengicursos-gafetes-evento.sql para que una instalacion nueva ya
-- incluya esta columna desde el CREATE TABLE + ALTER TABLE inicial.
--
-- Como aplicarla manualmente sobre un contenedor MySQL que ya existe:
--   docker compose -f docker-compose.prod.yml exec -T mysql \
--     mysql -u root -p cengi_cursos < cengicursos/migrations/20260813_gafetes_evento_correo_invitado.sql

USE cengi_cursos;

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
