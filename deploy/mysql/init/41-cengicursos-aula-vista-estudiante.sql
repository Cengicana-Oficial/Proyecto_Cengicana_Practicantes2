USE cengi_cursos;

-- Espejo de cengicursos/migrations/20261021_aula_vista_estudiante.sql (ver comentarios alli).
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
