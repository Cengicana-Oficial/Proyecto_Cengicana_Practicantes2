USE cengi_cursos;

-- Espejo de cengicursos/migrations/20261007_planificacion_cursos.sql (ver comentarios alli).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS planificacion_cursos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  anio SMALLINT UNSIGNED NOT NULL,
  nombre VARCHAR(255) NOT NULL,
  categoria_curso_id INT UNSIGNED NULL,
  area_tecnica VARCHAR(120) NOT NULL DEFAULT '',
  actividad_tipo VARCHAR(40) NOT NULL DEFAULT 'formacion_academica',
  modalidad VARCHAR(60) NOT NULL DEFAULT 'Presencial',
  dirigido_a VARCHAR(255) NOT NULL DEFAULT 'Todos los ingenios',
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE NOT NULL,
  hora_inicio TIME NOT NULL,
  hora_fin TIME NOT NULL,
  dias VARCHAR(255) NOT NULL DEFAULT '',
  sesiones_planificadas SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  cupo_minimo INT UNSIGNED NOT NULL DEFAULT 1,
  cupo_maximo INT UNSIGNED NULL,
  costo_asociados DECIMAL(10,2) NOT NULL DEFAULT 0,
  costo_externos DECIMAL(10,2) NOT NULL DEFAULT 0,
  costo_extranjero DECIMAL(10,2) NOT NULL DEFAULT 0,
  instructor_id INT UNSIGNED NULL,
  modulos TEXT NULL,
  observaciones TEXT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'planificado',
  motivo_cancelacion VARCHAR(500) NOT NULL DEFAULT '',
  curso_id INT UNSIGNED NULL,
  creado_por INT NULL,
  creado_por_nombre VARCHAR(255) NOT NULL DEFAULT '',
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_planificacion_curso (curso_id),
  KEY idx_planificacion_anio (anio, fecha_inicio),
  KEY idx_planificacion_categoria (categoria_curso_id),
  KEY idx_planificacion_instructor (instructor_id),
  CONSTRAINT fk_planificacion_categoria
    FOREIGN KEY (categoria_curso_id) REFERENCES categorias_cursos (id) ON DELETE SET NULL,
  CONSTRAINT fk_planificacion_instructor
    FOREIGN KEY (instructor_id) REFERENCES instructores (id) ON DELETE SET NULL,
  CONSTRAINT fk_planificacion_curso
    FOREIGN KEY (curso_id) REFERENCES cursos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Instructores: codigo de catedratico y tarifa por sesion (base para la liquidacion).
SET @col_codigo_catedratico := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instructores' AND COLUMN_NAME = 'codigo_catedratico'
);
SET @ddl_codigo_catedratico := IF(
  @col_codigo_catedratico = 0,
  'ALTER TABLE instructores ADD COLUMN codigo_catedratico VARCHAR(20) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_codigo_catedratico; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_tarifa_sesion := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instructores' AND COLUMN_NAME = 'tarifa_sesion'
);
SET @ddl_tarifa_sesion := IF(
  @col_tarifa_sesion = 0,
  'ALTER TABLE instructores ADD COLUMN tarifa_sesion DECIMAL(10,2) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_tarifa_sesion; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS soporte_tecnico (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo VARCHAR(20) NOT NULL,
  nombre VARCHAR(255) NOT NULL,
  tipo VARCHAR(20) NOT NULL DEFAULT 'Interno',
  correo VARCHAR(255) NOT NULL DEFAULT '',
  telefono VARCHAR(50) NOT NULL DEFAULT '',
  tarifa DECIMAL(10,2) NOT NULL DEFAULT 0,
  estado TINYINT NOT NULL DEFAULT 1,
  notas VARCHAR(500) NOT NULL DEFAULT '',
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_soporte_codigo (codigo),
  UNIQUE KEY uq_soporte_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS liquidaciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  numero VARCHAR(40) NOT NULL,
  desde DATE NOT NULL,
  hasta DATE NOT NULL,
  destino VARCHAR(255) NOT NULL DEFAULT 'Administración',
  nota TEXT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'enviada',
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  pago_forma VARCHAR(60) NOT NULL DEFAULT '',
  pago_referencia VARCHAR(120) NOT NULL DEFAULT '',
  pago_fecha DATE NULL,
  aprobada_en DATETIME NULL,
  aprobada_por VARCHAR(255) NOT NULL DEFAULT '',
  pagada_en DATETIME NULL,
  pagada_por VARCHAR(255) NOT NULL DEFAULT '',
  anulada_en DATETIME NULL,
  anulada_por VARCHAR(255) NOT NULL DEFAULT '',
  motivo_anulacion VARCHAR(500) NOT NULL DEFAULT '',
  creado_por INT NULL,
  creado_por_nombre VARCHAR(255) NOT NULL DEFAULT '',
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_liquidaciones_numero (numero),
  KEY idx_liquidaciones_periodo (desde, hasta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS liquidacion_detalle (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  liquidacion_id INT UNSIGNED NOT NULL,
  tipo VARCHAR(20) NOT NULL DEFAULT 'instructor',
  persona_id INT UNSIGNED NULL,
  persona_nombre VARCHAR(255) NOT NULL,
  persona_codigo VARCHAR(20) NOT NULL DEFAULT '',
  planificacion_id INT UNSIGNED NULL,
  curso_nombre VARCHAR(255) NOT NULL DEFAULT '',
  sesiones SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  fechas TEXT NULL,
  tarifa DECIMAL(10,2) NOT NULL DEFAULT 0,
  neto DECIMAL(12,2) NOT NULL DEFAULT 0,
  impuesto DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  observaciones VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_liquidacion_detalle_liq (liquidacion_id),
  CONSTRAINT fk_liquidacion_detalle_liq
    FOREIGN KEY (liquidacion_id) REFERENCES liquidaciones (id) ON DELETE CASCADE,
  CONSTRAINT fk_liquidacion_detalle_plan
    FOREIGN KEY (planificacion_id) REFERENCES planificacion_cursos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS curso_sesiones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  planificacion_id INT UNSIGNED NOT NULL,
  modulo VARCHAR(255) NOT NULL DEFAULT 'Sesión única',
  tema VARCHAR(255) NOT NULL,
  fecha DATE NOT NULL,
  hora_inicio TIME NOT NULL,
  hora_fin TIME NOT NULL,
  instructor_id INT UNSIGNED NULL,
  soporte_id INT UNSIGNED NULL,
  modalidad VARCHAR(60) NOT NULL DEFAULT 'Presencial',
  lugar VARCHAR(255) NOT NULL DEFAULT '',
  estado VARCHAR(20) NOT NULL DEFAULT 'programada',
  liquidacion_id INT UNSIGNED NULL,
  liquidacion_soporte_id INT UNSIGNED NULL,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_curso_sesiones_plan (planificacion_id, fecha),
  KEY idx_curso_sesiones_fecha (fecha),
  KEY idx_curso_sesiones_instructor (instructor_id, fecha),
  KEY idx_curso_sesiones_soporte (soporte_id, fecha),
  CONSTRAINT fk_curso_sesiones_plan
    FOREIGN KEY (planificacion_id) REFERENCES planificacion_cursos (id) ON DELETE CASCADE,
  CONSTRAINT fk_curso_sesiones_instructor
    FOREIGN KEY (instructor_id) REFERENCES instructores (id) ON DELETE SET NULL,
  CONSTRAINT fk_curso_sesiones_soporte
    FOREIGN KEY (soporte_id) REFERENCES soporte_tecnico (id) ON DELETE SET NULL,
  CONSTRAINT fk_curso_sesiones_liq
    FOREIGN KEY (liquidacion_id) REFERENCES liquidaciones (id) ON DELETE SET NULL,
  CONSTRAINT fk_curso_sesiones_liq_soporte
    FOREIGN KEY (liquidacion_soporte_id) REFERENCES liquidaciones (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comunicaciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  categoria VARCHAR(60) NOT NULL DEFAULT 'General',
  origen VARCHAR(20) NOT NULL DEFAULT 'manual',
  regla VARCHAR(40) NULL,
  referencia VARCHAR(80) NULL,
  planificacion_id INT UNSIGNED NULL,
  asunto VARCHAR(255) NOT NULL,
  mensaje TEXT NOT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'enviada',
  programada_para DATETIME NULL,
  enviados INT UNSIGNED NOT NULL DEFAULT 0,
  fallidos INT UNSIGNED NOT NULL DEFAULT 0,
  creado_por_nombre VARCHAR(255) NOT NULL DEFAULT '',
  enviado_en DATETIME NULL,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_comunicaciones_regla_ref (regla, referencia),
  KEY idx_comunicaciones_estado (estado, programada_para),
  KEY idx_comunicaciones_plan (planificacion_id),
  CONSTRAINT fk_comunicaciones_plan
    FOREIGN KEY (planificacion_id) REFERENCES planificacion_cursos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comunicacion_destinatarios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  comunicacion_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(255) NOT NULL DEFAULT '',
  correo VARCHAR(255) NOT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
  error VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_comunicacion_destinatarios_com (comunicacion_id),
  CONSTRAINT fk_comunicacion_destinatarios_com
    FOREIGN KEY (comunicacion_id) REFERENCES comunicaciones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comunicacion_reglas (
  clave VARCHAR(40) NOT NULL,
  nombre VARCHAR(120) NOT NULL,
  descripcion VARCHAR(255) NOT NULL DEFAULT '',
  activo TINYINT NOT NULL DEFAULT 1,
  dias_antes TINYINT UNSIGNED NOT NULL DEFAULT 1,
  asunto VARCHAR(255) NOT NULL,
  mensaje TEXT NOT NULL,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO comunicacion_reglas (clave, nombre, descripcion, activo, dias_antes, asunto, mensaje) VALUES
  ('sesion', 'Recordatorio de sesión de curso',
   'A los participantes asignados al curso, antes de cada sesión programada.', 1, 1,
   'Recordatorio: sesión de {curso} el {fecha}',
   'Hola {nombre}:\n\nTe recordamos la próxima sesión de {curso}:\n\nTema: {tema}\nFecha: {fecha}\nHorario: {horario}\nModalidad: {modalidad}\nLugar o enlace: {lugar}\n\n¡Te esperamos!'),
  ('inicio_curso', 'Inicio de curso',
   'A los participantes asignados, antes de la fecha de inicio del curso.', 1, 3,
   '{curso} inicia el {fecha}',
   'Hola {nombre}:\n\nTe recordamos que {curso} ({codigo}) inicia el {fecha}.\n\nHorario: {horario}\nModalidad: {modalidad}\n\nRevisa que tengas lo necesario para participar.'),
  ('instructor_sesion', 'Recordatorio al instructor',
   'Al instructor de cada sesión programada, antes de la fecha.', 1, 2,
   'Recordatorio: imparte {tema} el {fecha}',
   'Hola {nombre}:\n\nTe recordamos tu sesión en {curso}:\n\nTema: {tema}\nFecha: {fecha}\nHorario: {horario}\nModalidad: {modalidad}\nLugar o enlace: {lugar}\n\nGracias por tu apoyo.');

CREATE TABLE IF NOT EXISTS planificacion_cambios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  planificacion_id INT UNSIGNED NOT NULL,
  sesion_id INT UNSIGNED NULL,
  tipo VARCHAR(60) NOT NULL,
  detalle VARCHAR(500) NOT NULL DEFAULT '',
  antes VARCHAR(500) NOT NULL DEFAULT '',
  despues VARCHAR(500) NOT NULL DEFAULT '',
  motivo VARCHAR(500) NOT NULL DEFAULT '',
  usuario_id INT NULL,
  usuario_nombre VARCHAR(255) NOT NULL DEFAULT '',
  afecta TINYINT NOT NULL DEFAULT 0,
  notificacion VARCHAR(20) NOT NULL DEFAULT 'no aplica',
  comunicacion_id INT UNSIGNED NULL,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_planificacion_cambios_plan (planificacion_id, creado),
  KEY idx_planificacion_cambios_notif (notificacion),
  CONSTRAINT fk_planificacion_cambios_plan
    FOREIGN KEY (planificacion_id) REFERENCES planificacion_cursos (id) ON DELETE CASCADE,
  CONSTRAINT fk_planificacion_cambios_com
    FOREIGN KEY (comunicacion_id) REFERENCES comunicaciones (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tarifa por sesion de un instructor en un curso especifico (carga masiva de tarifas).
-- Tiene prioridad sobre instructores.tarifa_sesion al armar "Por liquidar" en
-- pago_instructores.php; las liquidaciones ya generadas conservan su tarifa.
CREATE TABLE IF NOT EXISTS tarifas_instructor_plan (
  instructor_id INT UNSIGNED NOT NULL,
  planificacion_id INT UNSIGNED NOT NULL,
  tarifa DECIMAL(10,2) NOT NULL,
  observaciones VARCHAR(500) NOT NULL DEFAULT '',
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (instructor_id, planificacion_id),
  KEY idx_tarifas_plan (planificacion_id),
  CONSTRAINT fk_tarifas_instructor
    FOREIGN KEY (instructor_id) REFERENCES instructores (id) ON DELETE CASCADE,
  CONSTRAINT fk_tarifas_plan
    FOREIGN KEY (planificacion_id) REFERENCES planificacion_cursos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ficha y documentos del instructor (ficha_instructor.php, base_instructores.php).
-- Documentacion completa = CV (instructores.cv_path, que sigue usando instructores.php)
-- + carta de comprobacion de su formacion + formacion/titulo registrado.
-- instructor_documentos guarda el historial de archivos (el vigente es el mas reciente
-- por tipo); el CV nuevo tambien actualiza instructores.cv_path.
SET @col_inst_institucion := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instructores' AND COLUMN_NAME = 'institucion'
);
SET @ddl_inst_institucion := IF(
  @col_inst_institucion = 0,
  'ALTER TABLE instructores ADD COLUMN institucion VARCHAR(255) NULL, ADD COLUMN titulo VARCHAR(255) NULL, ADD COLUMN notas TEXT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_inst_institucion; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS instructor_documentos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  instructor_id INT UNSIGNED NOT NULL,
  tipo VARCHAR(20) NOT NULL,
  nombre_original VARCHAR(255) NOT NULL,
  ruta VARCHAR(255) NOT NULL,
  tamano INT UNSIGNED NOT NULL DEFAULT 0,
  emisor VARCHAR(255) NOT NULL DEFAULT '',
  fecha_emision DATE NULL,
  descripcion VARCHAR(255) NOT NULL DEFAULT '',
  vigente TINYINT NOT NULL DEFAULT 1,
  subido_por_nombre VARCHAR(255) NOT NULL DEFAULT '',
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_instructor_documentos (instructor_id, tipo, vigente),
  CONSTRAINT fk_instructor_documentos_instructor
    FOREIGN KEY (instructor_id) REFERENCES instructores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
