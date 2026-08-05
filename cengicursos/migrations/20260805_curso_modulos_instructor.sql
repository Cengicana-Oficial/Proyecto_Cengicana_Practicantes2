-- Instructor(es) por modulo de evaluacion (co-ensenanza): un modulo de un curso puede
-- tener cero, uno o varios instructores propios (distintos o iguales al instructor
-- principal del curso, guardado en cursos.instructor_id), mediante la tabla puente
-- curso_modulo_instructores. Un modulo sin filas en esa tabla (0 instructores propios)
-- hereda implicitamente al instructor principal del curso al momento de mostrarlo/usarlo;
-- ese fallback se resuelve en la capa de aplicacion (cengicursos/ver_cursos.php,
-- cengicursos/curso_form_helpers.php), no aqui.
--
-- Reemplaza el intento anterior de esta misma migracion, que agregaba una columna simple
-- "curso_modulos.instructor_id" (FK 1:1). Esa columna nunca llego a desplegarse a ningun
-- entorno real, asi que aqui se elimina por completo en vez de mantenerla en paralelo:
-- si la columna existe (por ejemplo en un contenedor local que ya la tenia), este script
-- la elimina antes de crear la tabla puente nueva.
--
-- Es aditiva/idempotente (usa el mismo patron PREPARE/EXECUTE condicional que
-- deploy/mysql/init/03-cengicursos-modulos.sql), asi que es segura de aplicar mas de una
-- vez sobre la misma base de datos. Ver tambien la actualizacion correspondiente en
-- deploy/mysql/init/03-cengicursos-modulos.sql para que una instalacion nueva ya incluya
-- la tabla puente desde el CREATE TABLE (y ya no incluya la columna vieja).
--
-- Como aplicarla manualmente sobre un contenedor MySQL que ya existe (los scripts de
-- deploy/mysql/init solo se ejecutan automaticamente la primera vez que se crea el
-- volumen):
--   docker compose -f docker-compose.prod.yml exec -T mysql \
--     mysql -u root -p cengi_cursos < cengicursos/migrations/20260805_curso_modulos_instructor.sql

-- 1) Elimina la llave foranea, el indice y la columna "instructor_id" de curso_modulos si
--    quedaron de un intento anterior de esta migracion (idempotente: no falla si ya no
--    existen).
SET @fk_curso_modulos_instructor_existe := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'curso_modulos'
    AND CONSTRAINT_NAME = 'fk_curso_modulos_instructor'
);

SET @ddl_fk_curso_modulos_instructor := IF(
  @fk_curso_modulos_instructor_existe > 0,
  'ALTER TABLE curso_modulos DROP FOREIGN KEY fk_curso_modulos_instructor',
  'SELECT 1'
);

PREPARE stmt_fk_curso_modulos_instructor FROM @ddl_fk_curso_modulos_instructor;
EXECUTE stmt_fk_curso_modulos_instructor;
DEALLOCATE PREPARE stmt_fk_curso_modulos_instructor;

SET @idx_curso_modulos_instructor_existe := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'curso_modulos'
    AND INDEX_NAME = 'idx_curso_modulos_instructor'
);

SET @ddl_idx_curso_modulos_instructor := IF(
  @idx_curso_modulos_instructor_existe > 0,
  'ALTER TABLE curso_modulos DROP INDEX idx_curso_modulos_instructor',
  'SELECT 1'
);

PREPARE stmt_idx_curso_modulos_instructor FROM @ddl_idx_curso_modulos_instructor;
EXECUTE stmt_idx_curso_modulos_instructor;
DEALLOCATE PREPARE stmt_idx_curso_modulos_instructor;

SET @col_curso_modulos_instructor_existe := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'curso_modulos'
    AND COLUMN_NAME = 'instructor_id'
);

SET @ddl_col_curso_modulos_instructor := IF(
  @col_curso_modulos_instructor_existe > 0,
  'ALTER TABLE curso_modulos DROP COLUMN instructor_id',
  'SELECT 1'
);

PREPARE stmt_col_curso_modulos_instructor FROM @ddl_col_curso_modulos_instructor;
EXECUTE stmt_col_curso_modulos_instructor;
DEALLOCATE PREPARE stmt_col_curso_modulos_instructor;

-- 2) Crea la tabla puente curso_modulo_instructores (0..N instructores por modulo).
CREATE TABLE IF NOT EXISTS curso_modulo_instructores (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  curso_modulo_id INT UNSIGNED NOT NULL,
  instructor_id INT UNSIGNED NOT NULL,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_curso_modulo_instructores (curso_modulo_id, instructor_id),
  KEY idx_curso_modulo_instructores_modulo (curso_modulo_id),
  KEY idx_curso_modulo_instructores_instructor (instructor_id),
  CONSTRAINT fk_curso_modulo_instructores_modulo
    FOREIGN KEY (curso_modulo_id) REFERENCES curso_modulos (id) ON DELETE CASCADE,
  CONSTRAINT fk_curso_modulo_instructores_instructor
    FOREIGN KEY (instructor_id) REFERENCES instructores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
