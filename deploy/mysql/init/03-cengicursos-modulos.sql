-- Migracion aditiva para los modulos nuevos de cengicursos (Organizaciones, Instructores,
-- Control QR de eventos, Certificacion/Diplomas).
--
-- NO modifica ni recrea las tablas de 02-cengicursos.sql. Es segura de aplicar sobre un
-- contenedor ya inicializado porque usa "CREATE TABLE IF NOT EXISTS" y una adicion de
-- columna/llave foranea idempotente para "cursos.instructor_id".
--
-- Como aplicarla manualmente sobre un contenedor MySQL que ya existe (los scripts de
-- deploy/mysql/init solo se ejecutan automaticamente la primera vez que se crea el volumen):
--   docker compose -f docker-compose.prod.yml exec -T mysql \
--     mysql -u root -p cengi_cursos < deploy/mysql/init/03-cengicursos-modulos.sql
-- (o vía phpMyAdmin/Workbench si se trabaja con XAMPP).

USE cengi_cursos;

-- ============================================================
-- Organizaciones: directorio ampliado (ingenios, universidades,
-- empresas proveedoras, instituciones tecnicas). No sustituye a
-- la tabla "ingenios" (tiene FKs entrantes desde cursos/participantes).
-- ============================================================
CREATE TABLE IF NOT EXISTS organizaciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(255) NOT NULL,
  tipo VARCHAR(60) NOT NULL DEFAULT 'Ingenio azucarero',
  contacto_nombre VARCHAR(255) NOT NULL DEFAULT '',
  contacto_correo VARCHAR(255) NOT NULL DEFAULT '',
  contacto_telefono VARCHAR(50) NOT NULL DEFAULT '',
  ingenio_id INT UNSIGNED NULL,
  estado TINYINT NOT NULL DEFAULT 1,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_organizaciones_ingenio (ingenio_id),
  CONSTRAINT fk_organizaciones_ingenio
    FOREIGN KEY (ingenio_id) REFERENCES ingenios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Siembra una organizacion "Ingenio azucarero" por cada ingenio existente,
-- solo si todavia no tiene una organizacion vinculada (idempotente sin
-- depender de una restriccion UNIQUE sobre el nombre).
INSERT INTO organizaciones (nombre, tipo, ingenio_id, estado)
SELECT i.nombre_ingenios, 'Ingenio azucarero', i.id, 1
FROM ingenios i
WHERE NOT EXISTS (
  SELECT 1 FROM organizaciones o WHERE o.ingenio_id = i.id
);

-- ============================================================
-- Instructores
-- ============================================================
CREATE TABLE IF NOT EXISTS instructores (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(255) NOT NULL,
  especialidad VARCHAR(255) NOT NULL DEFAULT '',
  correo VARCHAR(255) NOT NULL DEFAULT '',
  telefono VARCHAR(50) NOT NULL DEFAULT '',
  cv_path VARCHAR(255) NULL,
  estado TINYINT NOT NULL DEFAULT 1,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Columna aditiva en cursos: MySQL (a diferencia de MariaDB) no soporta
-- "ADD COLUMN IF NOT EXISTS", asi que se agrega con el mismo patron
-- PREPARE/EXECUTE idempotente que la llave foranea de abajo.
SET @col_cursos_instructor_existe := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cursos'
    AND COLUMN_NAME = 'instructor_id'
);

SET @ddl_col_cursos_instructor := IF(
  @col_cursos_instructor_existe = 0,
  'ALTER TABLE cursos ADD COLUMN instructor_id INT UNSIGNED NULL AFTER ingenio_id',
  'SELECT 1'
);

PREPARE stmt_col_cursos_instructor FROM @ddl_col_cursos_instructor;
EXECUTE stmt_col_cursos_instructor;
DEALLOCATE PREPARE stmt_col_cursos_instructor;

-- Llave foranea idempotente (evita error "duplicate foreign key" si el script
-- se corre mas de una vez sobre la misma base de datos).
SET @fk_cursos_instructor_existe := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cursos'
    AND CONSTRAINT_NAME = 'fk_cursos_instructor'
);

SET @ddl_fk_cursos_instructor := IF(
  @fk_cursos_instructor_existe = 0,
  'ALTER TABLE cursos ADD CONSTRAINT fk_cursos_instructor FOREIGN KEY (instructor_id) REFERENCES instructores (id)',
  'SELECT 1'
);

PREPARE stmt_fk_cursos_instructor FROM @ddl_fk_cursos_instructor;
EXECUTE stmt_fk_cursos_instructor;
DEALLOCATE PREPARE stmt_fk_cursos_instructor;

-- ============================================================
-- Control QR de eventos
-- ============================================================
CREATE TABLE IF NOT EXISTS eventos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(255) NOT NULL,
  tipo VARCHAR(60) NOT NULL DEFAULT 'Capacitacion',
  fecha DATE NULL,
  estado VARCHAR(30) NOT NULL DEFAULT 'Planificado',
  creado_por INT NULL,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evento_participantes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  evento_id INT UNSIGNED NOT NULL,
  participante_id INT UNSIGNED NULL,
  nombre_invitado VARCHAR(255) NOT NULL DEFAULT '',
  cui_invitado VARCHAR(25) NOT NULL DEFAULT '',
  codigo_qr VARCHAR(60) NOT NULL,
  ingreso_en DATETIME NULL,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_evento_participantes_codigo (codigo_qr),
  KEY idx_evento_participantes_evento (evento_id),
  KEY idx_evento_participantes_participante (participante_id),
  CONSTRAINT fk_evtp_evento
    FOREIGN KEY (evento_id) REFERENCES eventos (id) ON DELETE CASCADE,
  CONSTRAINT fk_evtp_participante
    FOREIGN KEY (participante_id) REFERENCES participantes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Certificacion / diplomas (registro y trazabilidad; la generacion
-- real de PDF con QR de validacion queda pendiente, ver auditoria)
-- ============================================================
-- ============================================================
-- Contenido / temario de curso (modulos + temas). Es el mismo
-- temario para todas las ediciones de un curso "hermano" (mismo
-- nombre_cursos); se guarda por curso.id porque hoy cada edicion
-- es una fila distinta en "cursos" y no existe un identificador
-- de "curso maestro" compartido entre ediciones.
-- ============================================================
CREATE TABLE IF NOT EXISTS curso_modulos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  curso_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(255) NOT NULL,
  horas DECIMAL(6,2) NOT NULL DEFAULT 0,
  orden INT UNSIGNED NOT NULL DEFAULT 0,
  acepta_pre TINYINT NOT NULL DEFAULT 1,
  acepta_post TINYINT NOT NULL DEFAULT 1,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_curso_modulos_curso (curso_id),
  CONSTRAINT fk_curso_modulos_curso
    FOREIGN KEY (curso_id) REFERENCES cursos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Instructor(es) por modulo (co-ensenanza): un modulo puede tener 0, 1 o varios
-- instructores propios (distintos o iguales al instructor principal del curso,
-- cursos.instructor_id). 0 filas para un modulo hereda implicitamente al instructor
-- principal del curso al momento de mostrarlo/usarlo; ese fallback se resuelve en la
-- capa de aplicacion (cengicursos/ver_cursos.php, cengicursos/curso_form_helpers.php),
-- no en la base de datos. Ver tambien
-- cengicursos/migrations/20260805_curso_modulos_instructor.sql para la migracion
-- idempotente equivalente sobre un contenedor ya inicializado.
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

-- Notas por modulo (co-ensenanza): una fila por participante (asignacion_id) +
-- modulo (curso_modulo_id), con asistencia/pre-evaluacion/pos-evaluacion propias de
-- ese modulo. Es aditiva y NO sustituye a control_cursos: esa tabla sigue siendo el
-- resumen del curso completo (retrocompatibilidad con diploma/exports existentes).
-- registrado_por_instructor_id es trazabilidad de que instructor impartio/califico
-- el modulo; solo lo fija el super admin desde cengicursos/ver_participante_curso.php,
-- para el resto de roles que califican queda NULL. Ver tambien
-- cengicursos/migrations/20260805_control_curso_modulos.sql para la migracion
-- idempotente equivalente sobre un contenedor ya inicializado.
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

CREATE TABLE IF NOT EXISTS curso_modulo_temas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  curso_modulo_id INT UNSIGNED NOT NULL,
  tema VARCHAR(255) NOT NULL,
  orden INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_curso_modulo_temas_modulo (curso_modulo_id),
  CONSTRAINT fk_curso_modulo_temas_modulo
    FOREIGN KEY (curso_modulo_id) REFERENCES curso_modulos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diplomas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo ENUM('curso','evento') NOT NULL,
  asignacion_id INT UNSIGNED NULL,
  evento_participante_id INT UNSIGNED NULL,
  codigo_unico VARCHAR(60) NOT NULL,
  pdf_path VARCHAR(255) NULL,
  emitido_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  creado_por INT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_diplomas_codigo (codigo_unico),
  KEY idx_diplomas_asignacion (asignacion_id),
  KEY idx_diplomas_evento_participante (evento_participante_id),
  CONSTRAINT fk_diplomas_asignacion
    FOREIGN KEY (asignacion_id) REFERENCES asignaciones (id),
  CONSTRAINT fk_diplomas_evento_participante
    FOREIGN KEY (evento_participante_id) REFERENCES evento_participantes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
