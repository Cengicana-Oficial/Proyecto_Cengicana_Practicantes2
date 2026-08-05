-- Seguimiento de notas por modulo (co-ensenanza): agrega la tabla
-- control_curso_modulos, una fila por participante (asignacion_id) + modulo
-- (curso_modulo_id), con asistencia/pre-evaluacion/pos-evaluacion propias de ese
-- modulo y trazabilidad de que instructor lo impartio/califico
-- (registrado_por_instructor_id, solo lo fija el super admin desde
-- cengicursos/ver_participante_curso.php; para el resto de roles que califican
-- queda NULL).
--
-- Es aditiva: NO migra ni borra nada de control_cursos. Esa tabla se queda tal
-- cual como resumen del curso completo (retrocompatibilidad con el diploma y con
-- los exports existentes en cengicursos/classes/export_helpers.php que puedan
-- leerla). control_curso_modulos es el detalle por modulo, nuevo y separado.
--
-- Usa "CREATE TABLE IF NOT EXISTS", asi que es segura de aplicar mas de una vez
-- sobre la misma base de datos. Ver tambien la actualizacion correspondiente en
-- deploy/mysql/init/03-cengicursos-modulos.sql para que una instalacion nueva ya
-- incluya esta tabla desde el CREATE TABLE.
--
-- Como aplicarla manualmente sobre un contenedor MySQL que ya existe (los scripts
-- de deploy/mysql/init solo se ejecutan automaticamente la primera vez que se crea
-- el volumen):
--   docker compose -f docker-compose.prod.yml exec -T mysql \
--     mysql -u root -p cengi_cursos < cengicursos/migrations/20260805_control_curso_modulos.sql

CREATE TABLE IF NOT EXISTS control_curso_modulos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  asignacion_id INT UNSIGNED NOT NULL,
  curso_modulo_id INT UNSIGNED NOT NULL,
  asistencia VARCHAR(50) DEFAULT NULL,
  evaluacion VARCHAR(50) DEFAULT NULL,
  posevaluacion VARCHAR(50) DEFAULT NULL,
  registrado_por_instructor_id INT UNSIGNED NULL,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_control_curso_modulos (asignacion_id, curso_modulo_id),
  KEY idx_control_curso_modulos_modulo (curso_modulo_id),
  KEY idx_control_curso_modulos_instructor (registrado_por_instructor_id),
  CONSTRAINT fk_control_curso_modulos_asignacion
    FOREIGN KEY (asignacion_id) REFERENCES asignaciones (id) ON DELETE CASCADE,
  CONSTRAINT fk_control_curso_modulos_modulo
    FOREIGN KEY (curso_modulo_id) REFERENCES curso_modulos (id) ON DELETE CASCADE,
  CONSTRAINT fk_control_curso_modulos_instructor
    FOREIGN KEY (registrado_por_instructor_id) REFERENCES instructores (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
