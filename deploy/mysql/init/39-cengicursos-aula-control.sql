USE cengi_cursos;
-- Control del aula: Teams, encuesta unica y correcciones auditadas. Idempotente.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS aula_curso_config (
 curso_id INT UNSIGNED PRIMARY KEY,
 teams_url VARCHAR(2048) NOT NULL DEFAULT '',
 FOREIGN KEY(curso_id) REFERENCES cursos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aula_sesion_config (
 sesion_id INT UNSIGNED PRIMARY KEY,
 teams_url VARCHAR(2048) NOT NULL DEFAULT '',
 FOREIGN KEY(sesion_id) REFERENCES curso_sesiones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aula_instructor_config (
 curso_id INT UNSIGNED NOT NULL,
 instructor_id INT UNSIGNED NOT NULL,
 habilitada TINYINT NOT NULL DEFAULT 1,
 abre DATETIME NULL,
 cierra DATETIME NULL,
 PRIMARY KEY(curso_id,instructor_id),
 FOREIGN KEY(curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
 FOREIGN KEY(instructor_id) REFERENCES instructores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aula_instructor_respuestas (
 asignacion_id INT UNSIGNED NOT NULL,
 curso_id INT UNSIGNED NOT NULL,
 instructor_id INT UNSIGNED NOT NULL,
 evaluacion_id INT UNSIGNED NULL,
 enviado DATETIME NOT NULL,
 PRIMARY KEY(asignacion_id,instructor_id),
 FOREIGN KEY(asignacion_id) REFERENCES asignaciones(id),
 FOREIGN KEY(curso_id) REFERENCES cursos(id),
 FOREIGN KEY(instructor_id) REFERENCES instructores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aula_asistencia_historial (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sesion_id INT UNSIGNED NOT NULL,
 asignacion_id INT UNSIGNED NOT NULL,
 antes TEXT NULL,
 despues TEXT NOT NULL,
 motivo VARCHAR(500) NOT NULL,
 modificado_por INT NOT NULL,
 fecha DATETIME NOT NULL,
 FOREIGN KEY(sesion_id) REFERENCES curso_sesiones(id),
 FOREIGN KEY(asignacion_id) REFERENCES asignaciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_asistencias' AND COLUMN_NAME='presente');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_asistencias ADD COLUMN presente TINYINT NOT NULL DEFAULT 1','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;

SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_asistencias' AND COLUMN_NAME='origen');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_asistencias ADD COLUMN origen ENUM(''estudiante'',''coordinacion'') NOT NULL DEFAULT ''estudiante''','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;

SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_asistencias' AND COLUMN_NAME='validada');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_asistencias ADD COLUMN validada TINYINT NOT NULL DEFAULT 0','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;

SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_asistencias' AND COLUMN_NAME='motivo');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_asistencias ADD COLUMN motivo VARCHAR(500) NOT NULL DEFAULT ''''','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;

SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_asistencias' AND COLUMN_NAME='modificado_por');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_asistencias ADD COLUMN modificado_por INT NULL','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_asistencia_ventanas' AND COLUMN_NAME='horario_manual');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_asistencia_ventanas ADD COLUMN horario_manual TINYINT NOT NULL DEFAULT 0','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;