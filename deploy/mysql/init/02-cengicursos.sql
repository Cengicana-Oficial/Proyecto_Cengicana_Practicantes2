CREATE DATABASE IF NOT EXISTS cengi_cursos
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON cengi_cursos.* TO 'modulos_user'@'%';

USE cengi_cursos;

CREATE TABLE IF NOT EXISTS ingenios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre_ingenios VARCHAR(255) NOT NULL,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ingenios_nombre (nombre_ingenios)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categorias_cursos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  descripcion_categorias_cursos VARCHAR(255) NOT NULL,
  estado_categorias_cursos TINYINT NOT NULL DEFAULT 1,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categorias_descripcion (descripcion_categorias_cursos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cursos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  categoria_curso_id INT UNSIGNED NOT NULL,
  ingenio_id INT UNSIGNED NOT NULL,
  tipo VARCHAR(60) NOT NULL DEFAULT 'Presencial',
  nombre_cursos VARCHAR(255) NOT NULL,
  jornada_cursos VARCHAR(60) NOT NULL DEFAULT 'Matutina',
  dias VARCHAR(255) NOT NULL DEFAULT '',
  horario VARCHAR(255) NOT NULL DEFAULT '',
  inicio DATE NULL,
  fin DATE NULL,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cursos_categoria (categoria_curso_id),
  KEY idx_cursos_ingenio (ingenio_id),
  CONSTRAINT fk_cursos_categoria
    FOREIGN KEY (categoria_curso_id) REFERENCES categorias_cursos (id),
  CONSTRAINT fk_cursos_ingenio
    FOREIGN KEY (ingenio_id) REFERENCES ingenios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS participantes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ingenio_id INT UNSIGNED NOT NULL,
  usuarios_id INT NULL,
  cui_participantes VARCHAR(25) DEFAULT NULL,
  nombre_participantes VARCHAR(255) NOT NULL,
  puesto_participantes VARCHAR(255) NOT NULL DEFAULT '',
  area_participantes VARCHAR(255) NOT NULL DEFAULT '',
  correo_participantes VARCHAR(255) NOT NULL DEFAULT '',
  grado_academico_participantes VARCHAR(255) NOT NULL DEFAULT '',
  telefono_participantes VARCHAR(50) NOT NULL DEFAULT '',
  estado_participantes TINYINT NOT NULL DEFAULT 1,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_participantes_cui (cui_participantes),
  KEY idx_participantes_ingenio (ingenio_id),
  KEY idx_participantes_usuario (usuarios_id),
  CONSTRAINT fk_participantes_ingenio
    FOREIGN KEY (ingenio_id) REFERENCES ingenios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asignaciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  participantes_id INT UNSIGNED NOT NULL,
  usuarios_id INT NULL,
  cursos_id INT UNSIGNED NOT NULL,
  estado_asignaciones TINYINT NOT NULL DEFAULT 1,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_asignacion_participante_curso (participantes_id, cursos_id),
  KEY idx_asignaciones_usuario (usuarios_id),
  KEY idx_asignaciones_curso (cursos_id),
  CONSTRAINT fk_asignaciones_participante
    FOREIGN KEY (participantes_id) REFERENCES participantes (id),
  CONSTRAINT fk_asignaciones_curso
    FOREIGN KEY (cursos_id) REFERENCES cursos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS control_cursos (
  id_control INT UNSIGNED NOT NULL AUTO_INCREMENT,
  asignacion_id INT UNSIGNED NOT NULL,
  asistencia VARCHAR(50) DEFAULT NULL,
  evaluacion VARCHAR(50) DEFAULT NULL,
  posevaluacion VARCHAR(50) DEFAULT NULL,
  diploma VARCHAR(255) NOT NULL DEFAULT '',
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_control),
  UNIQUE KEY uq_control_asignacion (asignacion_id),
  CONSTRAINT fk_control_asignacion
    FOREIGN KEY (asignacion_id) REFERENCES asignaciones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS solicitudes_inscripcion (
  id_solicitud INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre_participante VARCHAR(255) NOT NULL,
  cui_participante VARCHAR(25) NOT NULL,
  puesto_participante VARCHAR(255) NOT NULL DEFAULT '',
  area_participante VARCHAR(255) NOT NULL DEFAULT '',
  grado_academico VARCHAR(255) NOT NULL DEFAULT '',
  correo VARCHAR(255) NOT NULL,
  telefono VARCHAR(50) NOT NULL DEFAULT '',
  ingenio_id INT UNSIGNED NULL,
  curso_id INT UNSIGNED NOT NULL,
  tipo_pago VARCHAR(50) NOT NULL DEFAULT 'Ingenio',
  estado VARCHAR(30) NOT NULL DEFAULT 'Pendiente',
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_solicitud),
  KEY idx_solicitudes_ingenio (ingenio_id),
  KEY idx_solicitudes_curso (curso_id),
  CONSTRAINT fk_solicitudes_ingenio
    FOREIGN KEY (ingenio_id) REFERENCES ingenios (id),
  CONSTRAINT fk_solicitudes_curso
    FOREIGN KEY (curso_id) REFERENCES cursos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asistencia (
  id_asistencia BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_asignacion INT UNSIGNED NOT NULL,
  fecha DATE DEFAULT NULL,
  PRIMARY KEY (id_asistencia),
  KEY idx_asistencia_asignacion (id_asignacion),
  CONSTRAINT fk_asistencia_asignacion
    FOREIGN KEY (id_asignacion) REFERENCES asignaciones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(191) NOT NULL,
  email VARCHAR(191) NOT NULL,
  password VARCHAR(255) NOT NULL,
  ingenio_id INT UNSIGNED NOT NULL DEFAULT 1,
  rol VARCHAR(50) NOT NULL DEFAULT 'Delegado',
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  CONSTRAINT fk_users_ingenio
    FOREIGN KEY (ingenio_id) REFERENCES ingenios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ingenios (id, nombre_ingenios) VALUES
  (1, 'Cengicana'),
  (2, 'Pantaleon'),
  (3, 'Madre Tierra'),
  (4, 'La Union'),
  (5, 'Santa Ana');

INSERT IGNORE INTO categorias_cursos (
  id,
  descripcion_categorias_cursos,
  estado_categorias_cursos
) VALUES (
  1,
  'Capacitacion general',
  1
);

INSERT IGNORE INTO cursos (
  id,
  categoria_curso_id,
  ingenio_id,
  tipo,
  nombre_cursos,
  jornada_cursos,
  dias,
  horario,
  inicio,
  fin
) VALUES (
  1,
  1,
  1,
  'Presencial',
  'Curso de prueba',
  'Matutina',
  'Por definir',
  'Por definir',
  CURRENT_DATE,
  CURRENT_DATE
);
