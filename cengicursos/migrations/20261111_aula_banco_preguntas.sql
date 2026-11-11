-- Banco de preguntas del aula: modulo y valor (puntos) de cada pregunta, preguntas con varias
-- respuestas correctas (puntaje parcial), metodo de calificacion con varios intentos y
-- autocalificacion con retroalimentacion configurable.
-- Idempotente: se puede ejecutar mas de una vez.
SET NAMES utf8mb4;

-- Tipo "multiple": el estudiante marca casillas; se califica con puntaje parcial.
ALTER TABLE aula_preguntas MODIFY tipo ENUM('seleccion','multiple','abierta','tarea') NOT NULL;

-- Modulo del curso al que pertenece la pregunta (filtro del banco y de las evaluaciones).
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_preguntas' AND COLUMN_NAME='curso_modulo_id');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_preguntas ADD COLUMN curso_modulo_id INT UNSIGNED NULL AFTER curso_id, ADD KEY aula_preguntas_modulo (curso_modulo_id)','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;

-- Respuestas correctas de las preguntas "multiple": arreglo JSON de indices (base 0).
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_preguntas' AND COLUMN_NAME='correctas');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_preguntas ADD COLUMN correctas VARCHAR(255) NULL AFTER correcta','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;

-- Valor de la pregunta: puntos sugeridos en el banco y puntos usados en cada evaluacion.
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_preguntas' AND COLUMN_NAME='puntos');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_preguntas ADD COLUMN puntos DECIMAL(6,2) NOT NULL DEFAULT 1 AFTER criterio','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_evaluacion_preguntas' AND COLUMN_NAME='puntos');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_evaluacion_preguntas ADD COLUMN puntos DECIMAL(6,2) NOT NULL DEFAULT 1','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;

-- Autocalificacion: cuando el estudiante ve su nota (manual = el docente publica; al entregar; al cerrar)
-- y si recibe retroalimentacion (no / acierto / respuestas correctas) y desde cuando.
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_evaluaciones' AND COLUMN_NAME='nota_visible');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_evaluaciones ADD COLUMN nota_visible ENUM(''manual'',''entrega'',''cierre'') NOT NULL DEFAULT ''manual'' AFTER publicar','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_evaluaciones' AND COLUMN_NAME='retro');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_evaluaciones ADD COLUMN retro ENUM(''no'',''acierto'',''correctas'') NOT NULL DEFAULT ''acierto'' AFTER nota_visible','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_evaluaciones' AND COLUMN_NAME='retro_momento');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_evaluaciones ADD COLUMN retro_momento ENUM(''nota'',''cierre'',''fecha'') NOT NULL DEFAULT ''nota'' AFTER retro','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_evaluaciones' AND COLUMN_NAME='retro_desde');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_evaluaciones ADD COLUMN retro_desde DATETIME NULL AFTER retro_momento','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;

-- Nota que alimenta Seguimiento cuando hay varios intentos: la mas alta o la del ultimo intento.
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aula_evaluaciones' AND COLUMN_NAME='calificacion');
SET @ddl := IF(@existe=0,'ALTER TABLE aula_evaluaciones ADD COLUMN calificacion ENUM(''mayor'',''ultimo'') NOT NULL DEFAULT ''mayor'' AFTER intentos','SELECT 1');
PREPARE aula_stmt FROM @ddl; EXECUTE aula_stmt; DEALLOCATE PREPARE aula_stmt;
