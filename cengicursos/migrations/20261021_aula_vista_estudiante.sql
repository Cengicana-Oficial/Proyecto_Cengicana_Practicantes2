-- Vista del estudiante configurable (Gestion del aula -> "Lo que ve el estudiante").
--   aula_curso_config.secciones_ocultas  claves separadas por coma (sesiones, material, asistencia,
--                                         evaluaciones, tareas, notas, instructores, diplomas).
--   aula_curso_config.anuncio_titulo / anuncio  mensaje de bienvenida o aviso en el Resumen.
--   aula_recursos.visible / disponible_desde    ocultar un recurso o publicarlo a partir de una fecha.
-- Requiere 20261020_aula_control.sql. Idempotente; espejo en deploy/mysql/init/41-cengicursos-aula-vista-estudiante.sql.
-- aula_vista.php tambien agrega las columnas que falten.
SET NAMES utf8mb4;
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_curso_config' AND COLUMN_NAME='secciones_ocultas');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_curso_config ADD COLUMN secciones_ocultas VARCHAR(255) NOT NULL DEFAULT ''''','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_curso_config' AND COLUMN_NAME='anuncio_titulo');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_curso_config ADD COLUMN anuncio_titulo VARCHAR(180) NOT NULL DEFAULT ''''','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_curso_config' AND COLUMN_NAME='anuncio');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_curso_config ADD COLUMN anuncio TEXT NULL','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_recursos' AND COLUMN_NAME='visible');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_recursos ADD COLUMN visible TINYINT NOT NULL DEFAULT 1','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_recursos' AND COLUMN_NAME='disponible_desde');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_recursos ADD COLUMN disponible_desde DATETIME NULL','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
