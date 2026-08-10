-- Extiende el modelo de evaluacion de instructor (hasta ahora 1 evaluacion = 1 curso
-- completo, ver cengicursos/migrations/20260805_evaluacion_instructor_formulario_completo.sql)
-- para soportar tambien evaluaciones por modulo: un curso puede tener varios modulos, y
-- cada modulo puede tener uno o mas instructores propios via curso_modulo_instructores
-- (co-ensenanza, ver cengicursos/migrations/20260805_curso_modulos_instructor.sql). Antes de
-- este cambio, un instructor que solo co-enseñaba un modulo especifico no tenia forma de
-- recibir retroalimentacion separada de ese modulo: solo existia el enlace de curso
-- completo (enlaces_evaluacion_instructor, UNIQUE curso_id+instructor_id).
--
-- enlaces_evaluacion_instructor.curso_modulo_id (NULL = evaluacion de curso completo,
-- como hasta ahora; NOT NULL = evaluacion de ese modulo especifico para ese instructor)
-- se genera SOLO para modulos con asignacion EXPLICITA en curso_modulo_instructores (ver
-- cengicursos/curso_form_helpers.php::cengi_curso_asegurar_enlaces_evaluacion()). Los
-- modulos sin fila propia (heredan al instructor principal del curso por fallback) NO
-- generan un enlace de modulo aparte -- para esos, el enlace/reporte de curso completo ya
-- cubre a ese instructor y crear uno de modulo seria redundante con las mismas preguntas.
--
-- La UNIQUE KEY original (curso_id, instructor_id) se reemplaza por (curso_id,
-- instructor_id, curso_modulo_id). MySQL trata NULL como valor distinto en indices UNIQUE
-- (NULL <> NULL), asi que esta constraint por si sola no evita dos filas con
-- curso_modulo_id NULL para el mismo curso+instructor; la funcion
-- cengi_asegurar_enlace_evaluacion_instructor() sigue siendo responsable de esa
-- deduplicacion (mismo patron "SELECT antes de INSERT" que ya usaba).
--
-- evaluaciones_instructor.modulo_nombre es un snapshot (mismo patron que curso_nombre /
-- conferencista ya existentes): '' cuando la evaluacion es de curso completo, nombre del
-- modulo en el momento del envio cuando es de modulo especifico.
--
-- Es idempotente (revisa information_schema antes de cada ALTER), segura de aplicar mas
-- de una vez. Ver tambien la actualizacion correspondiente en
-- deploy/mysql/init/06-cengicursos-evaluaciones-instructor.sql para que una instalacion
-- nueva ya incluya el esquema completo desde el CREATE TABLE.
--
-- Como aplicarla manualmente sobre un contenedor MySQL que ya existe (los scripts de
-- deploy/mysql/init solo se ejecutan automaticamente la primera vez que se crea el
-- volumen):
--   docker compose -f docker-compose.prod.yml exec -T mysql \
--     mysql -u root -p cengi_cursos < cengicursos/migrations/20260810_evaluacion_modulo_instructor.sql

-- 1) Agrega enlaces_evaluacion_instructor.curso_modulo_id (columna + indice + FK).
SET @col_curso_modulo_id_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enlaces_evaluacion_instructor' AND COLUMN_NAME = 'curso_modulo_id'
);
SET @ddl_col_curso_modulo_id := IF(
  @col_curso_modulo_id_existe = 0,
  'ALTER TABLE enlaces_evaluacion_instructor ADD COLUMN curso_modulo_id INT UNSIGNED NULL AFTER instructor_id',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_curso_modulo_id; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_curso_modulo_id_existe := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enlaces_evaluacion_instructor' AND INDEX_NAME = 'idx_enlace_eval_curso_modulo'
);
SET @ddl_idx_curso_modulo_id := IF(
  @idx_curso_modulo_id_existe = 0,
  'ALTER TABLE enlaces_evaluacion_instructor ADD KEY idx_enlace_eval_curso_modulo (curso_modulo_id)',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_idx_curso_modulo_id; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_curso_modulo_id_existe := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'enlaces_evaluacion_instructor'
    AND CONSTRAINT_NAME = 'fk_enlace_eval_curso_modulo'
);
SET @ddl_fk_curso_modulo_id := IF(
  @fk_curso_modulo_id_existe = 0,
  'ALTER TABLE enlaces_evaluacion_instructor ADD CONSTRAINT fk_enlace_eval_curso_modulo FOREIGN KEY (curso_modulo_id) REFERENCES curso_modulos (id) ON DELETE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_fk_curso_modulo_id; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Reemplaza la UNIQUE KEY (curso_id, instructor_id) por (curso_id, instructor_id,
--    curso_modulo_id): un mismo instructor ahora puede tener varios enlaces del mismo
--    curso (uno de curso completo con curso_modulo_id NULL, y uno por cada modulo con
--    asignacion explicita). Se agrega la nueva UNIQUE antes de borrar la vieja porque
--    fk_enlace_eval_curso depende de un indice con curso_id como columna izquierda; si se
--    borra primero la vieja, MySQL se queda sin indice de soporte para esa FK y falla con
--    error 1553 ("needed in a foreign key constraint").
SET @uq_nueva_existe := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enlaces_evaluacion_instructor' AND INDEX_NAME = 'uq_enlace_eval_curso_instructor_modulo'
);
SET @ddl_add_uq_nueva := IF(
  @uq_nueva_existe = 0,
  'ALTER TABLE enlaces_evaluacion_instructor ADD UNIQUE KEY uq_enlace_eval_curso_instructor_modulo (curso_id, instructor_id, curso_modulo_id)',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_add_uq_nueva; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @uq_vieja_existe := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enlaces_evaluacion_instructor' AND INDEX_NAME = 'uq_enlace_eval_curso_instructor'
);
SET @ddl_drop_uq_vieja := IF(
  @uq_vieja_existe > 0,
  'ALTER TABLE enlaces_evaluacion_instructor DROP INDEX uq_enlace_eval_curso_instructor',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_drop_uq_vieja; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) Agrega evaluaciones_instructor.modulo_nombre (snapshot, mismo patron que curso_nombre).
SET @col_modulo_nombre_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'modulo_nombre'
);
SET @ddl_col_modulo_nombre := IF(
  @col_modulo_nombre_existe = 0,
  'ALTER TABLE evaluaciones_instructor ADD COLUMN modulo_nombre VARCHAR(255) NOT NULL DEFAULT '''' AFTER curso_nombre',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_modulo_nombre; EXECUTE stmt; DEALLOCATE PREPARE stmt;
