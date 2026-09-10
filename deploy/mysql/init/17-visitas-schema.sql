-- Modulo "Solicitud de visitas" / "Servicio tecnico" (carpeta Pruebas/): PHP
-- plano sin migraciones, igual que laboratorio (ver 11-laboratorio-schema.sql).
-- El repositorio nunca tuvo un dump versionado de esta base, asi que este
-- esquema fue RECONSTRUIDO a partir de las consultas del codigo
-- (Pruebas/public/**, Pruebas/config/**). Revisar contra la base real de
-- produccion/XAMPP antes de confiar en tipos y longitudes exactas.
--
-- Ademas del esquema, este archivo hace lo mismo que 12-laboratorio-grants.sql
-- y 13-sigec-schema.sql: da permisos al usuario compartido de la app y registra
-- los modulos en usuarios_menu.modulos para que aparezcan en el menu principal.
--
-- Orden de ejecucion (docker-entrypoint-initdb.d corre los .sql en orden por
-- nombre de archivo): 01-usuarios-menu.sql ya creo usuarios_menu.modulos y
-- usuarios_menu.permisos antes de que este archivo (17-...) se ejecute.

CREATE DATABASE IF NOT EXISTS cengi_visitas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON cengi_visitas.* TO 'modulos_user'@'%';
FLUSH PRIVILEGES;

USE cengi_visitas;

-- ---------------------------------------------------------------------------
-- Catalogos
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS estados (
  id_estado INT NOT NULL AUTO_INCREMENT,
  nombre_estado VARCHAR(40) NOT NULL,
  PRIMARY KEY (id_estado),
  UNIQUE KEY nombre_estado (nombre_estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS estado_pago (
  id_estado_pago INT NOT NULL AUTO_INCREMENT,
  nombre_estado_pago VARCHAR(40) NOT NULL,
  PRIMARY KEY (id_estado_pago),
  UNIQUE KEY nombre_estado_pago (nombre_estado_pago)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS niveles_academicos (
  id_nivel INT NOT NULL AUTO_INCREMENT,
  nombre_nivel VARCHAR(120) NOT NULL,
  estado TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id_nivel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS areas_interes (
  id_area INT NOT NULL AUTO_INCREMENT,
  nombre_area VARCHAR(150) NOT NULL,
  correo_area VARCHAR(160) DEFAULT NULL,
  estado TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id_area)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- id_aprobador que guarda la app proviene del login central (usuarios_menu),
-- pero el dashboard hace LEFT JOIN aprobadores a ON aps.id_aprobador =
-- a.id_aprobador para mostrar a.nombre / a.apellido. Se deja sin FK a
-- proposito para no romper el INSERT de aprobacion_solicitud.
CREATE TABLE IF NOT EXISTS aprobadores (
  id_aprobador INT NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(120) NOT NULL,
  apellido VARCHAR(120) NOT NULL DEFAULT '',
  correo VARCHAR(160) DEFAULT NULL,
  PRIMARY KEY (id_aprobador)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Solicitantes y solicitudes
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS solicitantes (
  id_solicitante INT NOT NULL AUTO_INCREMENT,
  nombre_solicitante VARCHAR(160) NOT NULL,
  nombre_institucion VARCHAR(180) NOT NULL,
  correo VARCHAR(160) NOT NULL,
  telefono VARCHAR(40) NOT NULL,
  PRIMARY KEY (id_solicitante)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS solicitudes (
  id_solicitud INT NOT NULL AUTO_INCREMENT,
  id_solicitante INT NOT NULL,
  fecha_visita DATE NOT NULL,
  hora_visita TIME NOT NULL,
  cantidad_visitantes INT NOT NULL DEFAULT 0,
  id_nivel INT NOT NULL,
  ruta_carta_pdf VARCHAR(255) DEFAULT NULL,
  nombre_archivo_pdf VARCHAR(255) DEFAULT NULL,
  id_estado INT NOT NULL DEFAULT 4,
  correo_enviado TINYINT(1) NOT NULL DEFAULT 0,
  fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_solicitud),
  KEY id_solicitante (id_solicitante),
  KEY id_nivel (id_nivel),
  KEY id_estado (id_estado),
  KEY fecha_registro (fecha_registro),
  CONSTRAINT fk_solicitud_solicitante FOREIGN KEY (id_solicitante) REFERENCES solicitantes (id_solicitante),
  CONSTRAINT fk_solicitud_nivel FOREIGN KEY (id_nivel) REFERENCES niveles_academicos (id_nivel),
  CONSTRAINT fk_solicitud_estado FOREIGN KEY (id_estado) REFERENCES estados (id_estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS solicitud_areas (
  id_solicitud_area INT NOT NULL AUTO_INCREMENT,
  id_solicitud INT NOT NULL,
  id_area INT NOT NULL,
  id_area_asignada INT DEFAULT NULL,
  PRIMARY KEY (id_solicitud_area),
  KEY id_solicitud (id_solicitud),
  KEY id_area (id_area),
  KEY id_area_asignada (id_area_asignada),
  CONSTRAINT fk_solarea_solicitud FOREIGN KEY (id_solicitud) REFERENCES solicitudes (id_solicitud) ON DELETE CASCADE,
  CONSTRAINT fk_solarea_area FOREIGN KEY (id_area) REFERENCES areas_interes (id_area),
  CONSTRAINT fk_solarea_area_asignada FOREIGN KEY (id_area_asignada) REFERENCES areas_interes (id_area)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS solicitud_museo (
  id_solicitud_museo INT NOT NULL AUTO_INCREMENT,
  id_solicitud INT NOT NULL,
  cant_extranjeros INT NOT NULL DEFAULT 0,
  precio_extranjero DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  cant_adultos INT NOT NULL DEFAULT 0,
  precio_adulto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  cant_adultos_mayores INT NOT NULL DEFAULT 0,
  precio_adulto_mayor DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  cant_estudiantes INT NOT NULL DEFAULT 0,
  precio_estudiante DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  cant_ninos INT NOT NULL DEFAULT 0,
  precio_nino DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  forma_pago VARCHAR(40) DEFAULT NULL,
  moneda VARCHAR(10) DEFAULT NULL,
  nombre_factura VARCHAR(180) DEFAULT NULL,
  nit VARCHAR(40) DEFAULT NULL,
  direccion VARCHAR(255) DEFAULT NULL,
  ruta_carta_pdf VARCHAR(255) DEFAULT NULL,
  nombre_archivo_pdf VARCHAR(255) DEFAULT NULL,
  id_estado_pago INT NOT NULL DEFAULT 1,
  PRIMARY KEY (id_solicitud_museo),
  UNIQUE KEY id_solicitud (id_solicitud),
  KEY id_estado_pago (id_estado_pago),
  CONSTRAINT fk_museo_solicitud FOREIGN KEY (id_solicitud) REFERENCES solicitudes (id_solicitud) ON DELETE CASCADE,
  CONSTRAINT fk_museo_estado_pago FOREIGN KEY (id_estado_pago) REFERENCES estado_pago (id_estado_pago)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aprobacion_solicitud (
  id_aprobacion INT NOT NULL AUTO_INCREMENT,
  id_solicitud INT NOT NULL,
  id_aprobador INT NOT NULL,
  id_estado INT NOT NULL,
  fecha_aprobacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_aprobacion),
  KEY id_solicitud (id_solicitud),
  KEY id_aprobador (id_aprobador),
  CONSTRAINT fk_aprobacion_solicitud FOREIGN KEY (id_solicitud) REFERENCES solicitudes (id_solicitud) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS solicitudes_ocultas (
  id_solicitud INT NOT NULL,
  fecha_ocultamiento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_solicitud),
  CONSTRAINT fk_oculta_solicitud FOREIGN KEY (id_solicitud) REFERENCES solicitudes (id_solicitud) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Datos iniciales de catalogos
-- IDs de estados fijados a los valores que el codigo asume literalmente
-- (2=APROBADO, 3=RECHAZADO, 4=PENDIENTE; ver Pruebas/public/admin/modulos/
-- actualizar_estado_solicitud.php y solicitudes_unificadas.php).
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO estados (id_estado, nombre_estado) VALUES
  (1, 'ENVIADO'),
  (2, 'APROBADO'),
  (3, 'RECHAZADO'),
  (4, 'PENDIENTE');

INSERT IGNORE INTO estado_pago (id_estado_pago, nombre_estado_pago) VALUES
  (1, 'PENDIENTE'),
  (2, 'PAGADO');

INSERT IGNORE INTO niveles_academicos (id_nivel, nombre_nivel, estado) VALUES
  (1, 'NA', 1),
  (2, 'Primaria', 1),
  (3, 'Basicos', 1),
  (4, 'Diversificado', 1),
  (5, 'Universitario', 1),
  (6, 'Postgrado', 1);

INSERT IGNORE INTO areas_interes (id_area, nombre_area, correo_area, estado) VALUES
  (1, 'Variedades', NULL, 1),
  (2, 'Fisiologia y produccion agricola', NULL, 1),
  (3, 'Transferencia de Tecnologia', NULL, 1),
  (4, 'Manejo Integrado de Plagas y Enfermedades', NULL, 1),
  (5, 'Agromecanica Digital', NULL, 1),
  (6, 'Laboratorio Agroindustrial', NULL, 1),
  (7, 'Museo', NULL, 1);

-- ---------------------------------------------------------------------------
-- Registro en el menu principal + permisos (usuarios_menu)
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO usuarios_menu.modulos (nombre) VALUES
  ('Solicitud de visitas'),
  ('Servicio tecnico');

-- Permisos que Pruebas/config/setup_permissions.php espera en usuarios_menu.
-- INSERT IGNORE: los que ya sembro 01-usuarios-menu.sql (ver_dashboard,
-- gestionar_usuarios, gestionar_roles, gestionar_modulos, gestionar_ingenios)
-- se omiten por la clave unica nombre_permiso.
INSERT IGNORE INTO usuarios_menu.permisos (nombre_permiso, descripcion) VALUES
  ('ver_dashboard', 'Permite ver el dashboard general'),
  ('gestionar_solicitudes', 'Permite aprobar/rechazar solicitudes de visitas'),
  ('ver_solicitudes', 'Permite ver el listado de solicitudes de visitas'),
  ('gestionar_pagos', 'Permite marcar solicitudes de visitas como pagadas'),
  ('ver_pagos', 'Permite ver el dashboard de pagos de visitas'),
  ('gestionar_usuarios', 'Permite crear y editar usuarios'),
  ('gestionar_roles', 'Permite editar roles y sus permisos'),
  ('gestionar_modulos', 'Permite gestionar los modulos del sistema'),
  ('gestionar_ingenios', 'Permite gestionar los ingenios'),
  ('gestionar_areas', 'Permite crear y editar areas del modulo de visitas'),
  ('gestionar_reserva_salones', 'Permite preparar y enviar reservaciones de salones desde el dashboard de visitas'),
  ('ver_solicitudes_aprobadas', 'Permite ver solo solicitudes de visitas aprobadas'),
  ('enviar_correos', 'Permite enviar correos de solicitudes de visitas'),
  ('ocultar_solicitudes', 'Permite ocultar solicitudes de visitas del dashboard');
