USE cengi_cursos;
-- Integracion del aula con sesiones y seguimiento. Idempotente.
SET NAMES utf8mb4;
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_evaluaciones' AND COLUMN_NAME='sesion_id');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_evaluaciones ADD COLUMN sesion_id INT UNSIGNED NULL','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_evaluaciones' AND COLUMN_NAME='curso_modulo_id');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_evaluaciones ADD COLUMN curso_modulo_id INT UNSIGNED NULL','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_evaluaciones' AND COLUMN_NAME='tipo_actividad');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_evaluaciones ADD COLUMN tipo_actividad ENUM(''evaluacion'',''tarea'',''actividad'') NOT NULL DEFAULT ''evaluacion''','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_evaluaciones' AND COLUMN_NAME='campo_nota');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_evaluaciones ADD COLUMN campo_nota ENUM(''evaluacion'',''posevaluacion'') NOT NULL DEFAULT ''posevaluacion''','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_evaluaciones' AND COLUMN_NAME='peso');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_evaluaciones ADD COLUMN peso DECIMAL(8,2) NOT NULL DEFAULT 1','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_evaluaciones' AND COLUMN_NAME='sincronizar');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_evaluaciones ADD COLUMN sincronizar TINYINT NOT NULL DEFAULT 0','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_evaluaciones' AND COLUMN_NAME='orden');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_evaluaciones ADD COLUMN orden INT NOT NULL DEFAULT 0','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_intentos' AND COLUMN_NAME='entrega_usuario');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_intentos ADD COLUMN entrega_usuario TINYINT NOT NULL DEFAULT 0','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
SET @ddl := IF(@existe=0,'UPDATE aula_intentos SET entrega_usuario=1 WHERE entregado IS NOT NULL','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
CREATE TABLE IF NOT EXISTS aula_recursos (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 curso_id INT UNSIGNED NOT NULL,
 sesion_id INT UNSIGNED NULL,
 tipo ENUM('material','video') NOT NULL DEFAULT 'material',
 titulo VARCHAR(180) NOT NULL,
 contenido_id INT UNSIGNED NULL,
 archivo_id INT UNSIGNED NULL,
 orden INT NOT NULL DEFAULT 0,
 FOREIGN KEY(curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
 FOREIGN KEY(sesion_id) REFERENCES curso_sesiones(id),
 FOREIGN KEY(contenido_id) REFERENCES curso_contenidos(id),
 FOREIGN KEY(archivo_id) REFERENCES aula_archivos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aula_notas_fuente (
 asignacion_id INT UNSIGNED NOT NULL,
 modulo_id INT UNSIGNED NOT NULL DEFAULT 0,
 campo ENUM('evaluacion','posevaluacion') NOT NULL,
 nota_anterior VARCHAR(50) NULL,
 PRIMARY KEY(asignacion_id,modulo_id,campo),
 FOREIGN KEY(asignacion_id) REFERENCES asignaciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
