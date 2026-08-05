-- Enlaces publicos de evaluacion de instructor (uno por combinacion curso+instructor,
-- generado la primera vez que el instructor queda asignado al curso, ver
-- cengicursos/curso_form_helpers.php::cengi_asegurar_enlace_evaluacion_instructor) y las
-- evaluaciones que los participantes envian usando ese enlace publico, sin necesidad de
-- iniciar sesion.
--
-- evaluaciones_instructor replica el formulario oficial de Microsoft Forms "EVALUACION
-- DEL INSTRUCTOR (Prototipo)" que Cengicana ya usa: datos generales (ingenio/cargo/
-- seccion), una "foto" (snapshot) de modalidad/curso/conferencista/fecha tomada del
-- enlace en el momento del envio (no editable por el participante), las 5 preguntas
-- Likert "Acerca del instructor" (1=Deficiente..4=Excelente, mismo texto en la rama
-- Presencial y Virtual del formulario oficial), la calificacion 1-10 "recomendaria al
-- instructor", las 2 preguntas Likert "El tema y la logistica del evento", la
-- calificacion 1-10 "recomendaria el lugar/plataforma" (cual de las dos preguntas es se
-- sabe por la columna modalidad) y las 2 preguntas de texto libre finales
-- (capacitaciones_necesarias / areas_mejora, esta ultima mapea a "Oportunidades de
-- mejora" del formulario oficial). Ver tambien
-- cengicursos/migrations/20260805_evaluaciones_instructor.sql (version original, 3
-- campos) y cengicursos/migrations/20260805_evaluacion_instructor_formulario_completo.sql
-- (migracion idempotente equivalente para transformar un contenedor que ya tenia el
-- esquema original de 3 campos).
--
-- Es aditiva e idempotente (CREATE TABLE IF NOT EXISTS), igual que 03-/04-/05-.
-- Como aplicarla manualmente sobre un contenedor MySQL que ya existe:
--   docker compose -f docker-compose.prod.yml exec -T mysql \
--     mysql -u root -p cengi_cursos < deploy/mysql/init/06-cengicursos-evaluaciones-instructor.sql

USE cengi_cursos;

CREATE TABLE IF NOT EXISTS enlaces_evaluacion_instructor (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  curso_id INT UNSIGNED NOT NULL,
  instructor_id INT UNSIGNED NOT NULL,
  token VARCHAR(64) NOT NULL,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_enlace_eval_curso_instructor (curso_id, instructor_id),
  UNIQUE KEY uq_enlace_eval_token (token),
  CONSTRAINT fk_enlace_eval_curso
    FOREIGN KEY (curso_id) REFERENCES cursos (id) ON DELETE CASCADE,
  CONSTRAINT fk_enlace_eval_instructor
    FOREIGN KEY (instructor_id) REFERENCES instructores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluaciones_instructor (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  enlace_id INT UNSIGNED NOT NULL,
  ingenio_id INT UNSIGNED NULL,
  ingenio_otro VARCHAR(255) NOT NULL DEFAULT '',
  cargo VARCHAR(255) NOT NULL DEFAULT '',
  seccion VARCHAR(255) NOT NULL DEFAULT '',
  modalidad VARCHAR(60) NOT NULL DEFAULT '',
  curso_nombre VARCHAR(255) NOT NULL DEFAULT '',
  conferencista VARCHAR(255) NOT NULL DEFAULT '',
  fecha_curso DATE NULL,
  instructor_lenguaje_claro TINYINT UNSIGNED NOT NULL DEFAULT 0,
  instructor_material_adecuado TINYINT UNSIGNED NOT NULL DEFAULT 0,
  instructor_conocimiento_tema TINYINT UNSIGNED NOT NULL DEFAULT 0,
  instructor_respeto_participantes TINYINT UNSIGNED NOT NULL DEFAULT 0,
  instructor_puntualidad_objetivos TINYINT UNSIGNED NOT NULL DEFAULT 0,
  recomendaria_instructor TINYINT UNSIGNED NOT NULL DEFAULT 0,
  tema_relevancia_utilidad TINYINT UNSIGNED NOT NULL DEFAULT 0,
  logistica_evento TINYINT UNSIGNED NOT NULL DEFAULT 0,
  recomendaria_contexto TINYINT UNSIGNED NOT NULL DEFAULT 0,
  capacitaciones_necesarias TEXT NULL,
  areas_mejora TEXT NULL,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_eval_instructor_enlace (enlace_id),
  KEY idx_eval_instructor_ingenio (ingenio_id),
  CONSTRAINT fk_eval_instructor_enlace
    FOREIGN KEY (enlace_id) REFERENCES enlaces_evaluacion_instructor (id) ON DELETE CASCADE,
  CONSTRAINT fk_eval_instructor_ingenio
    FOREIGN KEY (ingenio_id) REFERENCES ingenios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
