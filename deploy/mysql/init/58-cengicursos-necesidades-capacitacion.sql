USE cengi_cursos;
-- Área laboral histórica para el análisis de necesidades; sección Única no es un área.
SET @area_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='evaluaciones_instructor' AND COLUMN_NAME='area_participante');
SET @area_sql = IF(@area_exists=0,'ALTER TABLE evaluaciones_instructor ADD COLUMN area_participante VARCHAR(255) NULL','SELECT 1');
PREPARE area_stmt FROM @area_sql;
EXECUTE area_stmt;
DEALLOCATE PREPARE area_stmt;
UPDATE evaluaciones_instructor SET area_participante=seccion WHERE area_participante IS NULL AND TRIM(COALESCE(seccion,''))<>'' AND LOWER(TRIM(seccion)) NOT IN ('única','unica');