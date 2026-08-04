CREATE DATABASE IF NOT EXISTS usuarios_menu CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS sistema_solicitudes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON sistema_solicitudes.* TO 'modulos_user'@'%';
USE usuarios_menu;

CREATE TABLE IF NOT EXISTS ingenios (
  id int NOT NULL AUTO_INCREMENT,
  nombre_ingenio varchar(150) NOT NULL,
  estado tinyint(1) DEFAULT 1,
  creado timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  id int NOT NULL AUTO_INCREMENT,
  nombre_rol varchar(60) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY nombre_rol (nombre_rol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS modulos (
  id int NOT NULL AUTO_INCREMENT,
  nombre varchar(100) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permisos (
  id int NOT NULL AUTO_INCREMENT,
  nombre_permiso varchar(100) NOT NULL,
  descripcion varchar(255) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY nombre_permiso (nombre_permiso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios (
  id int NOT NULL AUTO_INCREMENT,
  nombre varchar(120) NOT NULL,
  correo varchar(120) NOT NULL,
  contrasena varchar(255) NOT NULL,
  rol_id int NOT NULL,
  es_superadmin tinyint(1) DEFAULT 0,
  ingenio_id int DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY correo (correo),
  KEY rol_id (rol_id),
  KEY fk_usuario_ingenio (ingenio_id),
  CONSTRAINT fk_usuario_ingenio FOREIGN KEY (ingenio_id) REFERENCES ingenios (id),
  CONSTRAINT usuarios_ibfk_1 FOREIGN KEY (rol_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rol_permiso (
  id int NOT NULL AUTO_INCREMENT,
  rol_id int NOT NULL,
  permiso_id int NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY rol_permiso_unique (rol_id, permiso_id),
  KEY permiso_id (permiso_id),
  CONSTRAINT rol_permiso_ibfk_1 FOREIGN KEY (rol_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT rol_permiso_ibfk_2 FOREIGN KEY (permiso_id) REFERENCES permisos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuario_modulo (
  usuario_id int NOT NULL,
  modulo_id int NOT NULL,
  PRIMARY KEY (usuario_id, modulo_id),
  KEY modulo_id (modulo_id),
  CONSTRAINT usuario_modulo_ibfk_1 FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE,
  CONSTRAINT usuario_modulo_ibfk_2 FOREIGN KEY (modulo_id) REFERENCES modulos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ingenios (id, nombre_ingenio, estado) VALUES
  (1, 'Cengicana', 1),
  (2, 'Pantaleon', 1),
  (3, 'Madre Tierra', 1),
  (4, 'La Union', 1),
  (5, 'Santa Ana', 1);

INSERT IGNORE INTO roles (id, nombre_rol) VALUES
  (1, 'Superadmin'),
  (2, 'Administrador'),
  (6, 'Instructor'),
  (11, 'Estudiante'),
  (12, 'Gestor'),
  (13, 'Administrador de ingenio');

INSERT IGNORE INTO modulos (id, nombre) VALUES
  (5, 'Cursos'),
  (6, 'Solicitudes internas');

INSERT IGNORE INTO permisos (id, nombre_permiso, descripcion) VALUES
  (1, 'ver_dashboard', 'Permite ver el dashboard general'),
  (6, 'gestionar_usuarios', 'Permite crear y editar usuarios'),
  (7, 'gestionar_roles', 'Permite editar roles y sus permisos'),
  (8, 'gestionar_modulos', 'Permite gestionar los modulos del sistema'),
  (9, 'gestionar_ingenios', 'Permite gestionar los ingenios'),
  (17, 'gestionar_cursos_cengi', 'Permite gestionar cursos'),
  (18, 'ver_usuarios_cengi', 'Permite ver usuarios de cursos'),
  (19, 'gestionar_usuarios_cengi', 'Permite gestionar usuarios de cursos'),
  (20, 'ver_ingenios_cengi', 'Permite ver ingenios de cursos'),
  (21, 'gestionar_ingenios_cengi', 'Permite gestionar ingenios de cursos'),
  (22, 'ver_participantes_cengi', 'Permite ver participantes'),
  (23, 'gestionar_participantes_cengi', 'Permite gestionar participantes'),
  (24, 'cargar_participantes_cengi', 'Permite cargar participantes'),
  (25, 'editar_participantes_cengi', 'Permite editar participantes'),
  (26, 'eliminar_participantes_cengi', 'Permite eliminar participantes'),
  (27, 'ver_solicitudes_cengi', 'Permite ver solicitudes de cursos'),
  (28, 'gestionar_solicitudes_cengi', 'Permite gestionar solicitudes de cursos'),
  (29, 'editar_solicitudes_cengi', 'Permite editar solicitudes de cursos'),
  (30, 'aprobar_solicitudes_cengi', 'Permite aprobar solicitudes de cursos'),
  (31, 'rechazar_solicitudes_cengi', 'Permite rechazar solicitudes de cursos'),
  (32, 'gestionar_notas_cengi', 'Permite gestionar notas'),
  (33, 'subir_diplomas_cengi', 'Permite subir diplomas'),
  (34, 'solicitudes_internas.crear', 'Permite crear solicitudes internas'),
  (35, 'solicitudes_internas.ver', 'Permite ver solicitudes internas'),
  (36, 'solicitudes_internas.gestionar', 'Permite gestionar solicitudes internas'),
  (37, 'solicitudes_internas.usuarios.ver', 'Permite ver usuarios de solicitudes internas'),
  (38, 'solicitudes_internas.usuarios.gestionar', 'Permite gestionar usuarios de solicitudes internas'),
  (39, 'solicitudes_internas.programas.ver', 'Permite ver programas de solicitudes internas'),
  (40, 'solicitudes_internas.programas.gestionar', 'Permite gestionar programas de solicitudes internas'),
  (41, 'roles_gestionar_cengi', 'Permite gestionar roles y permisos de Cengicursos desde el modulo de cursos'),
  (42, 'ver_roles_cengi', 'Permite ver la matriz de roles y permisos de Cengicursos sin editarla'),
  (43, 'ver_organizaciones_cengi', 'Permite ver el directorio de organizaciones de Cengicursos'),
  (44, 'gestionar_organizaciones_cengi', 'Permite crear y editar organizaciones de Cengicursos'),
  (45, 'ver_instructores_cengi', 'Permite ver el directorio de instructores de Cengicursos'),
  (46, 'gestionar_instructores_cengi', 'Permite crear y editar instructores de Cengicursos'),
  (47, 'ver_eventos_cengi', 'Permite ver los eventos con control QR de Cengicursos'),
  (48, 'gestionar_eventos_cengi', 'Permite crear eventos y registrar participantes con QR en Cengicursos'),
  (49, 'ver_diplomas_cengi', 'Permite ver el modulo de certificacion y diplomas de Cengicursos'),
  (50, 'gestionar_diplomas_cengi', 'Permite generar y cargar diplomas en Cengicursos');

INSERT INTO usuarios (
  nombre,
  correo,
  contrasena,
  rol_id,
  es_superadmin,
  ingenio_id
) VALUES (
  'Developer CENGICANA',
  'developer@cengicana.org',
  '$2y$10$LEjx4vYtDG7NkRZuHmI1n.4FiiyEtn/iVdc6NL9if6kNq.Q8pN0cy',
  1,
  1,
  1
)
ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre),
  contrasena = VALUES(contrasena),
  rol_id = VALUES(rol_id),
  es_superadmin = VALUES(es_superadmin),
  ingenio_id = VALUES(ingenio_id);

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT 1, id FROM permisos;

INSERT IGNORE INTO usuario_modulo (usuario_id, modulo_id)
SELECT u.id, m.id
FROM usuarios u
CROSS JOIN modulos m
WHERE u.es_superadmin = 1;
