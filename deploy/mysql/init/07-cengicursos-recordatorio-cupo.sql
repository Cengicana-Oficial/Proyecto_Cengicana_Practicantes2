USE cengi_cursos;

-- Recordatorio automatico de cupo: cengicursos/cron_recordatorio_cupo.php avisa por
-- correo al instructor y a los participantes ya inscritos de un curso cuando su fecha
-- de "inicio" esta a 7 dias o menos y todavia no se ha llenado el cupo (inscritos
-- activos < cursos.cupo). recordatorio_cupo_enviado_en marca que ya se envio ese aviso
-- para este curso, para garantizar el envio de una sola vez por curso aunque el script
-- se siga ejecutando a diario (ver cron_recordatorio_cupo.php para el detalle completo).
-- Idempotente, mismo patron PREPARE/EXECUTE que la columna "cupo" en
-- deploy/mysql/init/04-cengicursos-cursos-ampliados.sql.
SET @col_recordatorio_cupo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cursos' AND COLUMN_NAME = 'recordatorio_cupo_enviado_en'
);
SET @ddl_recordatorio_cupo := IF(
  @col_recordatorio_cupo = 0,
  'ALTER TABLE cursos ADD COLUMN recordatorio_cupo_enviado_en TIMESTAMP NULL DEFAULT NULL AFTER cupo',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_recordatorio_cupo; EXECUTE stmt; DEALLOCATE PREPARE stmt;
