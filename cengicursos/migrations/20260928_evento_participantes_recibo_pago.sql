-- Recibo de pago por participante de evento (cengicursos/guardar_inscripcion_evento.php).
--
-- evento_participantes.recibo_pago: URL raiz-absoluta ("/uploads/recibos_evento/...",
-- misma convencion que control_cursos.diploma / diplomas.pdf_path, ver
-- cengi_guardar_archivo_subido() en conexion.php) del comprobante de pago (PDF/JPG/PNG)
-- que el participante adjunta en el formulario publico de inscripcion
-- (cengicursos/inscripcion_evento.php) cuando el evento es de modalidad_pago = 'Pagado'.
-- NULL para eventos gratuitos y para participantes registrados manualmente desde el
-- panel de administracion (cengicursos/eventos_qr.php), donde no se pide el recibo.
--
-- Se usa junto con la columna evento_participantes.pagado (ver
-- 20260908_evento_participantes_ingenio_pago.sql) para que el equipo organizador
-- revise el recibo antes de marcar el pago como verificado y recien entonces enviar
-- el gafete (cengicursos/enviar_gafetes_evento.php).
--
-- Idempotente (revisa information_schema antes de cada ALTER), segura de aplicar mas de
-- una vez. Ver tambien la actualizacion correspondiente en
-- deploy/mysql/init/20-cengicursos-evento-participantes-recibo-pago.sql para que una
-- instalacion nueva ya incluya esta columna.
--
-- Como aplicarla manualmente sobre un contenedor MySQL que ya existe (los scripts de
-- deploy/mysql/init solo se ejecutan automaticamente la primera vez que se crea el
-- volumen):
--   docker compose -f docker-compose.prod.yml exec -T mysql \
--     mysql -u root -p cengi_cursos < cengicursos/migrations/20260928_evento_participantes_recibo_pago.sql

USE cengi_cursos;

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
