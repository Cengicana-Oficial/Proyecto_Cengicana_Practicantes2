-- Recordatorio automatico de cupo (cengicursos/cron_recordatorio_cupo.php): cuando un
-- curso todavia no se ha llenado (inscritos activos < cursos.cupo) y su fecha de
-- "inicio" esta entre hoy y los proximos 7 dias, se envia un correo de aviso al
-- instructor y a todos los participantes ya inscritos activamente.
--
-- recordatorio_cupo_enviado_en guarda cuando se envio ese aviso para el curso. El script
-- solo selecciona cursos con esta columna NULL, y la marca con NOW() al terminar de
-- procesarlo (haya fallado o no algun correo individual), asi que el aviso se manda una
-- sola vez por curso sin importar cuantas veces al dia se vuelva a correr el script.
--
-- Es aditiva e idempotente (ALTER TABLE ... ADD COLUMN via PREPARE/EXECUTE condicionado
-- a information_schema.COLUMNS), mismo patron que la columna "cupo" agregada en
-- cengicursos/migrations/20260805_datos_contacto_participantes.sql y
-- deploy/mysql/init/04-cengicursos-cursos-ampliados.sql.
--
-- Ver tambien la actualizacion correspondiente en
-- deploy/mysql/init/07-cengicursos-recordatorio-cupo.sql para que una instalacion nueva
-- ya incluya esta columna desde el CREATE TABLE + ALTER TABLE inicial.
--
-- Como aplicarla manualmente sobre un contenedor MySQL que ya existe (los scripts de
-- deploy/mysql/init solo se ejecutan automaticamente la primera vez que se crea el
-- volumen):
--   docker compose -f docker-compose.prod.yml exec -T mysql \
--     mysql -u root -p cengi_cursos < cengicursos/migrations/20260811_recordatorio_cupo_cursos.sql

USE cengi_cursos;

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
