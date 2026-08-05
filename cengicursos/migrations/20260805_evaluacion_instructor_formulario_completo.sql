-- Formulario completo de evaluacion de instructor: replica el formulario oficial de
-- Microsoft Forms "EVALUACION DEL INSTRUCTOR (Prototipo)" que Cengicana ya usa
-- (cengicursos/evaluacion.php + cengicursos/guardar_evaluacion_instructor.php).
--
-- Agrega a evaluaciones_instructor:
--  - Datos generales del participante: ingenio_id (FK a ingenios; NULL cuando elige
--    "Otro" y escribe el nombre en ingenio_otro), cargo, seccion.
--  - Una "foto" (snapshot) de modalidad/curso_nombre/conferencista/fecha_curso tomada
--    del JOIN de enlaces_evaluacion_instructor + cursos + instructores en el momento
--    del envio -- NO son datos que el participante escriba libremente, para no permitir
--    valores inconsistentes con el enlace real.
--  - Las 5 preguntas Likert "Acerca del instructor" (1=Deficiente..4=Excelente; mismo
--    texto en la rama Presencial y Virtual del formulario oficial):
--    instructor_lenguaje_claro, instructor_material_adecuado, instructor_conocimiento_tema,
--    instructor_respeto_participantes, instructor_puntualidad_objetivos.
--  - recomendaria_instructor (1-10): "Le gustaria recibir otro curso con este instructor?"
--  - Las 2 preguntas Likert "El tema y la logistica del evento":
--    tema_relevancia_utilidad, logistica_evento.
--  - recomendaria_contexto (1-10): "otro curso en estas instalaciones" (Presencial) o
--    "a traves de la misma plataforma de videoconferencia" (Virtual) -- cual de las dos
--    preguntas es se sabe por la columna modalidad, no hace falta una columna por rama.
--  - capacitaciones_necesarias (texto libre): "Que otras capacitaciones necesita para
--    desempenar mejor su trabajo?"
--
-- Elimina:
--  - calificacion (1-5 estrellas generico, con su CHECK constraint) y comentario (texto
--    libre generico): ninguno de los dos existe en el formulario oficial, se sustituyen
--    por las preguntas especificas de arriba.
--
-- Conserva:
--  - areas_mejora (texto libre): mapea a la pregunta "Oportunidades de mejora" del
--    formulario oficial (misma idea, se reutiliza la columna para no perder respuestas
--    ya recibidas con el formulario anterior).
--
-- No se agregan CHECK constraints de rango para las columnas Likert/rating nuevas
-- (rango 1-4 o 1-10 segun la pregunta): la validacion de rango vive en
-- guardar_evaluacion_instructor.php, igual que el resto del esquema de cengicursos casi
-- no usa CHECK a nivel de base de datos.
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
--     mysql -u root -p cengi_cursos < cengicursos/migrations/20260805_evaluacion_instructor_formulario_completo.sql

-- 1) Elimina el CHECK constraint y la columna "calificacion".
SET @chk_calificacion_existe := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'evaluaciones_instructor'
    AND CONSTRAINT_NAME = 'chk_eval_instructor_calificacion'
);
SET @ddl_chk_calificacion := IF(
  @chk_calificacion_existe > 0,
  'ALTER TABLE evaluaciones_instructor DROP CHECK chk_eval_instructor_calificacion',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_chk_calificacion; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_calificacion_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'calificacion'
);
SET @ddl_col_calificacion := IF(
  @col_calificacion_existe > 0,
  'ALTER TABLE evaluaciones_instructor DROP COLUMN calificacion',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_calificacion; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Elimina la columna "comentario".
SET @col_comentario_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'comentario'
);
SET @ddl_col_comentario := IF(
  @col_comentario_existe > 0,
  'ALTER TABLE evaluaciones_instructor DROP COLUMN comentario',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_comentario; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) Agrega las columnas nuevas, una por una de forma idempotente.
SET @col_ingenio_id_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'ingenio_id'
);
SET @ddl_col_ingenio_id := IF(
  @col_ingenio_id_existe = 0,
  'ALTER TABLE evaluaciones_instructor ADD COLUMN ingenio_id INT UNSIGNED NULL AFTER enlace_id',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_ingenio_id; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_ingenio_otro_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'ingenio_otro'
);
SET @ddl_col_ingenio_otro := IF(
  @col_ingenio_otro_existe = 0,
  "ALTER TABLE evaluaciones_instructor ADD COLUMN ingenio_otro VARCHAR(255) NOT NULL DEFAULT '' AFTER ingenio_id",
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_ingenio_otro; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_cargo_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'cargo'
);
SET @ddl_col_cargo := IF(
  @col_cargo_existe = 0,
  "ALTER TABLE evaluaciones_instructor ADD COLUMN cargo VARCHAR(255) NOT NULL DEFAULT '' AFTER ingenio_otro",
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_cargo; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_seccion_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'seccion'
);
SET @ddl_col_seccion := IF(
  @col_seccion_existe = 0,
  "ALTER TABLE evaluaciones_instructor ADD COLUMN seccion VARCHAR(255) NOT NULL DEFAULT '' AFTER cargo",
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_seccion; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_modalidad_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'modalidad'
);
SET @ddl_col_modalidad := IF(
  @col_modalidad_existe = 0,
  "ALTER TABLE evaluaciones_instructor ADD COLUMN modalidad VARCHAR(60) NOT NULL DEFAULT '' AFTER seccion",
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_modalidad; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_curso_nombre_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'curso_nombre'
);
SET @ddl_col_curso_nombre := IF(
  @col_curso_nombre_existe = 0,
  "ALTER TABLE evaluaciones_instructor ADD COLUMN curso_nombre VARCHAR(255) NOT NULL DEFAULT '' AFTER modalidad",
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_curso_nombre; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_conferencista_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'conferencista'
);
SET @ddl_col_conferencista := IF(
  @col_conferencista_existe = 0,
  "ALTER TABLE evaluaciones_instructor ADD COLUMN conferencista VARCHAR(255) NOT NULL DEFAULT '' AFTER curso_nombre",
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_conferencista; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_fecha_curso_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'fecha_curso'
);
SET @ddl_col_fecha_curso := IF(
  @col_fecha_curso_existe = 0,
  'ALTER TABLE evaluaciones_instructor ADD COLUMN fecha_curso DATE NULL AFTER conferencista',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_fecha_curso; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_instructor_lenguaje_claro_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'instructor_lenguaje_claro'
);
SET @ddl_col_instructor_lenguaje_claro := IF(
  @col_instructor_lenguaje_claro_existe = 0,
  'ALTER TABLE evaluaciones_instructor ADD COLUMN instructor_lenguaje_claro TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER fecha_curso',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_instructor_lenguaje_claro; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_instructor_material_adecuado_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'instructor_material_adecuado'
);
SET @ddl_col_instructor_material_adecuado := IF(
  @col_instructor_material_adecuado_existe = 0,
  'ALTER TABLE evaluaciones_instructor ADD COLUMN instructor_material_adecuado TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER instructor_lenguaje_claro',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_instructor_material_adecuado; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_instructor_conocimiento_tema_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'instructor_conocimiento_tema'
);
SET @ddl_col_instructor_conocimiento_tema := IF(
  @col_instructor_conocimiento_tema_existe = 0,
  'ALTER TABLE evaluaciones_instructor ADD COLUMN instructor_conocimiento_tema TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER instructor_material_adecuado',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_instructor_conocimiento_tema; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_instructor_respeto_participantes_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'instructor_respeto_participantes'
);
SET @ddl_col_instructor_respeto_participantes := IF(
  @col_instructor_respeto_participantes_existe = 0,
  'ALTER TABLE evaluaciones_instructor ADD COLUMN instructor_respeto_participantes TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER instructor_conocimiento_tema',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_instructor_respeto_participantes; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_instructor_puntualidad_objetivos_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'instructor_puntualidad_objetivos'
);
SET @ddl_col_instructor_puntualidad_objetivos := IF(
  @col_instructor_puntualidad_objetivos_existe = 0,
  'ALTER TABLE evaluaciones_instructor ADD COLUMN instructor_puntualidad_objetivos TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER instructor_respeto_participantes',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_instructor_puntualidad_objetivos; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_recomendaria_instructor_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'recomendaria_instructor'
);
SET @ddl_col_recomendaria_instructor := IF(
  @col_recomendaria_instructor_existe = 0,
  'ALTER TABLE evaluaciones_instructor ADD COLUMN recomendaria_instructor TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER instructor_puntualidad_objetivos',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_recomendaria_instructor; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_tema_relevancia_utilidad_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'tema_relevancia_utilidad'
);
SET @ddl_col_tema_relevancia_utilidad := IF(
  @col_tema_relevancia_utilidad_existe = 0,
  'ALTER TABLE evaluaciones_instructor ADD COLUMN tema_relevancia_utilidad TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER recomendaria_instructor',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_tema_relevancia_utilidad; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_logistica_evento_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'logistica_evento'
);
SET @ddl_col_logistica_evento := IF(
  @col_logistica_evento_existe = 0,
  'ALTER TABLE evaluaciones_instructor ADD COLUMN logistica_evento TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER tema_relevancia_utilidad',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_logistica_evento; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_recomendaria_contexto_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'recomendaria_contexto'
);
SET @ddl_col_recomendaria_contexto := IF(
  @col_recomendaria_contexto_existe = 0,
  'ALTER TABLE evaluaciones_instructor ADD COLUMN recomendaria_contexto TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER logistica_evento',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_recomendaria_contexto; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_capacitaciones_necesarias_existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND COLUMN_NAME = 'capacitaciones_necesarias'
);
SET @ddl_col_capacitaciones_necesarias := IF(
  @col_capacitaciones_necesarias_existe = 0,
  'ALTER TABLE evaluaciones_instructor ADD COLUMN capacitaciones_necesarias TEXT NULL AFTER recomendaria_contexto',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_col_capacitaciones_necesarias; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) Indice + llave foranea para ingenio_id (idempotentes).
SET @idx_ingenio_existe := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluaciones_instructor' AND INDEX_NAME = 'idx_eval_instructor_ingenio'
);
SET @ddl_idx_ingenio := IF(
  @idx_ingenio_existe = 0,
  'ALTER TABLE evaluaciones_instructor ADD KEY idx_eval_instructor_ingenio (ingenio_id)',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_idx_ingenio; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_ingenio_existe := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'evaluaciones_instructor'
    AND CONSTRAINT_NAME = 'fk_eval_instructor_ingenio'
);
SET @ddl_fk_ingenio := IF(
  @fk_ingenio_existe = 0,
  'ALTER TABLE evaluaciones_instructor ADD CONSTRAINT fk_eval_instructor_ingenio FOREIGN KEY (ingenio_id) REFERENCES ingenios (id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_fk_ingenio; EXECUTE stmt; DEALLOCATE PREPARE stmt;
