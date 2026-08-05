-- Enlaces publicos de evaluacion de instructor (uno por combinacion curso+instructor,
-- generado la primera vez que el instructor queda asignado al curso, ver
-- cengicursos/curso_form_helpers.php::cengi_asegurar_enlace_evaluacion_instructor) y las
-- evaluaciones (1 a 5 estrellas + comentario opcional) que los participantes envian usando
-- ese enlace publico, sin necesidad de iniciar sesion.
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
  calificacion TINYINT UNSIGNED NOT NULL,
  comentario TEXT NULL,
  areas_mejora TEXT NULL,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_eval_instructor_enlace (enlace_id),
  CONSTRAINT fk_eval_instructor_enlace
    FOREIGN KEY (enlace_id) REFERENCES enlaces_evaluacion_instructor (id) ON DELETE CASCADE,
  CONSTRAINT chk_eval_instructor_calificacion CHECK (calificacion BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
