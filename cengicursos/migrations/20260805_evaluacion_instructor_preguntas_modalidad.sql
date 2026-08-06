-- Agrega dos preguntas Likert (1=Deficiente..4=Excelente) a evaluaciones_instructor que,
-- a diferencia de recomendaria_contexto (misma pregunta conceptual, texto distinto segun
-- modalidad, una sola columna), son preguntas genuinamente distintas y por eso usan una
-- columna separada cada una, NULL cuando no aplica su rama:
--
--  - instructor_manejo_plataforma: solo se pregunta cuando el curso es Virtual. Va dentro
--    de la seccion "Acerca del instructor" (evalua el desempeno del instructor, no la
--    logistica): "¿El instructor manejó adecuadamente la plataforma virtual (conexión,
--    herramientas, cámara, pantalla compartida, etc.)?" (cengicursos/evaluacion.php).
--  - calidad_instalaciones: solo se pregunta cuando el curso es Presencial. Va dentro de
--    la seccion "El tema y la logística del evento": "¿Cómo calificaría las instalaciones
--    donde se impartió la capacitación?" (cengicursos/evaluacion.php).
--
-- Ambas se agregan como NULL (no NOT NULL DEFAULT 0 como el resto de columnas Likert de
-- esta tabla) porque aqui si es valido y esperado que una de las dos quede NULL en cada
-- fila, segun la modalidad real del curso (guardar_evaluacion_instructor.php solo valida
-- y exige la que aplica). Ver cengicursos/migrations/20260805_evaluacion_instructor_formulario_completo.sql
-- para el patron idempotente y el resto del esquema de evaluaciones_instructor.
--
-- Es idempotente (revisa information_schema antes de cada ALTER), asi que es segura de
-- aplicar mas de una vez sobre la misma base de datos. Ver tambien la actualizacion
-- correspondiente en deploy/mysql/init/06-cengicursos-evaluaciones-instructor.sql para
-- que una instalacion nueva ya incluya el esquema completo desde el CREATE TABLE.
--
-- Como aplicarla manualmente sobre un contenedor MySQL que ya existe (los scripts de
-- deploy/mysql/init solo se ejecutan automaticamente la primera vez que se crea el
-- volumen):
--   docker compose -f docker-compose.prod.yml exec -T mysql \
--     mysql -u root -p cengi_cursos < cengicursos/migrations/20260805_evaluacion_instructor_preguntas_modalidad.sql

SET @col_instructor_manejo_plataforma_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'instructor_manejo_plataforma'
);
SET @ddl_col_instructor_manejo_plataforma := IF(
  @col_instructor_manejo_plataforma_existe = 0,
  'ALTER TABLE evaluaciones_instructor ADD COLUMN instructor_manejo_plataforma TINYINT UNSIGNED NULL AFTER instructor_puntualidad_objetivos',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_instructor_manejo_plataforma; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_calidad_instalaciones_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'calidad_instalaciones'
);
SET @ddl_col_calidad_instalaciones := IF(
  @col_calidad_instalaciones_existe = 0,
  'ALTER TABLE evaluaciones_instructor ADD COLUMN calidad_instalaciones TINYINT UNSIGNED NULL AFTER logistica_evento',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_calidad_instalaciones; EXECUTE stmt; DEALLOCATE PREPARE stmt;
