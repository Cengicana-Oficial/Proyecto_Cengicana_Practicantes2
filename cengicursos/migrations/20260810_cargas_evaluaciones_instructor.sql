-- Historial de cargas de evaluaciones de instructor: permite retirar (hard delete) una
-- carga masiva completa de una sola vez, en vez de tener que borrar fila por fila, y
-- distinguir en el historial cuales evaluaciones llegaron por carga masiva (Excel/CSV,
-- cengicursos/carga_evaluaciones_instructor.php) vs envio individual del formulario
-- publico con token (cengicursos/guardar_evaluacion_instructor.php).
--
-- cargas_evaluaciones_instructor: una fila por cada archivo procesado en
-- carga_evaluaciones_instructor.php (incluso si el archivo solo trae una fila valida).
-- Se referencia por curso_id (no por enlace_id/instructor) porque una sola carga puede
-- incluir boletas de VARIOS instructores distintos a la vez -- el formulario de carga
-- deliberadamente pide una sola "Edicion del curso/diplomado" y resuelve el instructor
-- fila por fila desde la columna "Instructor" del Excel (ver el aviso en el modal
-- "modalCargaEvaluaciones" de cengicursos/instructores.php: "No selecciones un solo
-- instructor: un diplomado puede tener varios modulos con distintos instructores"). Por
-- eso NO se usa enlaces_evaluacion_instructor.id (que es 1:1 curso+instructor) como FK de
-- esta tabla: una carga real puede abarcar varios enlaces distintos del mismo curso.
-- curso_id SI usa ON DELETE CASCADE (igual que enlaces_evaluacion_instructor.curso_id),
-- para no dejar historial huerfano si la edicion de curso se elimina.
--
-- usuario_id/usuario_nombre son un snapshot (no hay FK cruzada posible: los usuarios
-- viven en la base de datos "usuarios_menu", separada de "cengi_cursos" -- mismo patron
-- que columnas snapshot ya existentes en evaluaciones_instructor como "conferencista" o
-- "curso_nombre", ver cengicursos/migrations/20260805_evaluacion_instructor_formulario_completo.sql).
--
-- evaluaciones_instructor.carga_id: NULL en un envio individual del formulario publico
-- (guardar_evaluacion_instructor.php, sin cambios), y apunta a
-- cargas_evaluaciones_instructor.id cuando la fila vino de una carga masiva
-- (carga_evaluaciones_instructor.php crea primero la fila en cargas_evaluaciones_instructor
-- y usa ese id en cada INSERT del lote). ON DELETE CASCADE: al borrar una carga se
-- retiran automaticamente todas sus evaluaciones asociadas (aunque
-- eliminar_carga_evaluacion_instructor.php tambien borra explicitamente por seguridad,
-- sin depender solo del CASCADE).
--
-- Es idempotente (revisa information_schema antes de cada ALTER / usa CREATE TABLE IF NOT
-- EXISTS), asi que es segura de aplicar mas de una vez sobre la misma base de datos. Ver
-- tambien la actualizacion correspondiente en
-- deploy/mysql/init/06-cengicursos-evaluaciones-instructor.sql para que una instalacion
-- nueva ya incluya el esquema completo desde el CREATE TABLE.
--
-- Como aplicarla manualmente sobre un contenedor MySQL que ya existe (los scripts de
-- deploy/mysql/init solo se ejecutan automaticamente la primera vez que se crea el
-- volumen):
--   docker compose -f docker-compose.prod.yml exec -T mysql \
--     mysql -u root -p cengi_cursos < cengicursos/migrations/20260810_cargas_evaluaciones_instructor.sql

-- 1) Crea la tabla de historial de cargas masivas.
CREATE TABLE IF NOT EXISTS cargas_evaluaciones_instructor (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  curso_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NULL,
  usuario_nombre VARCHAR(255) NOT NULL DEFAULT '',
  archivo_nombre VARCHAR(255) NULL,
  total_filas INT UNSIGNED NOT NULL DEFAULT 0,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_carga_eval_instructor_curso (curso_id),
  CONSTRAINT fk_carga_eval_instructor_curso
    FOREIGN KEY (curso_id) REFERENCES cursos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Agrega evaluaciones_instructor.carga_id (idempotente, columna + indice + FK).
SET @col_carga_id_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'carga_id'
);
SET @ddl_col_carga_id := IF(
  @col_carga_id_existe = 0,
  'ALTER TABLE evaluaciones_instructor ADD COLUMN carga_id INT UNSIGNED NULL AFTER enlace_id',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_carga_id; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_carga_id_existe := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND INDEX_NAME = 'idx_eval_instructor_carga'
);
SET @ddl_idx_carga_id := IF(
  @idx_carga_id_existe = 0,
  'ALTER TABLE evaluaciones_instructor ADD KEY idx_eval_instructor_carga (carga_id)',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_idx_carga_id; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_carga_id_existe := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'evaluaciones_instructor'
    AND CONSTRAINT_NAME = 'fk_eval_instructor_carga'
);
SET @ddl_fk_carga_id := IF(
  @fk_carga_id_existe = 0,
  'ALTER TABLE evaluaciones_instructor ADD CONSTRAINT fk_eval_instructor_carga FOREIGN KEY (carga_id) REFERENCES cargas_evaluaciones_instructor (id) ON DELETE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_fk_carga_id; EXECUTE stmt; DEALLOCATE PREPARE stmt;
