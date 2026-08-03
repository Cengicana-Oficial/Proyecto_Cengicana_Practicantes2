CREATE DATABASE IF NOT EXISTS sistema_solicitudes
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE sistema_solicitudes;

CREATE TABLE IF NOT EXISTS programas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL UNIQUE,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  correo VARCHAR(160) NOT NULL UNIQUE,
  rol ENUM('super_admin','administrador','direccion','gestor','solicitante') NOT NULL DEFAULT 'solicitante',
  programa_id INT NULL,
  password_hash VARCHAR(255) NULL,
  permiso_crear_solicitudes TINYINT(1) NOT NULL DEFAULT 1,
  permiso_gestionar_solicitudes TINYINT(1) NOT NULL DEFAULT 0,
  permiso_administrar_usuarios TINYINT(1) NOT NULL DEFAULT 0,
  permiso_ver_area TINYINT(1) NOT NULL DEFAULT 0,
  permiso_ver_todo TINYINT(1) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (programa_id) REFERENCES programas(id)
) ENGINE=InnoDB;

ALTER TABLE usuarios MODIFY rol ENUM('super_admin','administrador','direccion','gestor','solicitante','responsable','admin') NOT NULL DEFAULT 'solicitante';
UPDATE usuarios SET rol = 'administrador' WHERE rol IN ('responsable','admin');
UPDATE usuarios SET rol = 'direccion' WHERE correo = 'direccion@cengicana.org';
ALTER TABLE usuarios MODIFY rol ENUM('super_admin','administrador','direccion','gestor','solicitante') NOT NULL DEFAULT 'solicitante';

CREATE TABLE IF NOT EXISTS solicitudes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(20) NOT NULL UNIQUE,
  solicitante_id INT NOT NULL,
  programa_origen_id INT NOT NULL,
  programa_destino_id INT NULL,
  tipo ENUM('compra','ti','apoyo') NOT NULL,
  prioridad ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
  estado ENUM('recibido','proceso','completado','rechazado') NOT NULL DEFAULT 'recibido',
  titulo VARCHAR(180) NOT NULL,
  descripcion TEXT NOT NULL,
  fecha_requerida DATE NULL,
  proveedor_sugerido VARCHAR(160) NULL,
  monto_estimado DECIMAL(12,2) NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (solicitante_id) REFERENCES usuarios(id),
  FOREIGN KEY (programa_origen_id) REFERENCES programas(id),
  FOREIGN KEY (programa_destino_id) REFERENCES programas(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS seguimientos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  solicitud_id INT NOT NULL,
  usuario_id INT NOT NULL,
  estado ENUM('recibido','proceso','completado','rechazado') NOT NULL,
  observacion TEXT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (solicitud_id) REFERENCES solicitudes(id) ON DELETE CASCADE,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS adjuntos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  solicitud_id INT NOT NULL,
  nombre_original VARCHAR(255) NOT NULL,
  ruta VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NULL,
  tamano_bytes INT NULL,
  subido_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (solicitud_id) REFERENCES solicitudes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO programas (id, nombre) VALUES
  (1, 'Variedades'),
  (2, 'Fisiologia y produccion agricola'),
  (3, 'Transferencia de Tecnologia'),
  (4, 'Manejo Integrado de Plagas y Enfermedades'),
  (5, 'Agromecanica Digital'),
  (6, 'Administracion'),
  (7, 'Laboratorio Agroindustrial'),
  (8, 'Direccion');

INSERT IGNORE INTO usuarios
  (id, nombre, correo, rol, programa_id, password_hash, permiso_crear_solicitudes, permiso_gestionar_solicitudes, permiso_administrar_usuarios, permiso_ver_area, permiso_ver_todo)
VALUES
  (1, 'Juan Perez', 'juan.perez@cengicana.org', 'solicitante', 7, '$2y$10$x4X3AfxVYlDJT7neuq96g.d2bZRowToBHlREc6Ro9CHDUQBSBxbFS', 1, 0, 0, 0, 0),
  (2, 'Ana Rodriguez', 'ana.rodriguez@cengicana.org', 'administrador', 5, '$2y$10$x4X3AfxVYlDJT7neuq96g.d2bZRowToBHlREc6Ro9CHDUQBSBxbFS', 1, 1, 1, 1, 1),
  (3, 'Direccion CENGICANA', 'direccion@cengicana.org', 'direccion', 8, '$2y$10$x4X3AfxVYlDJT7neuq96g.d2bZRowToBHlREc6Ro9CHDUQBSBxbFS', 1, 1, 0, 1, 1),
  (4, 'Carlos Lopez', 'carlos.lopez@cengicana.org', 'solicitante', 1, '$2y$10$x4X3AfxVYlDJT7neuq96g.d2bZRowToBHlREc6Ro9CHDUQBSBxbFS', 1, 0, 0, 0, 0),
  (5, 'Maria Garcia', 'maria.garcia@cengicana.org', 'solicitante', 2, '$2y$10$x4X3AfxVYlDJT7neuq96g.d2bZRowToBHlREc6Ro9CHDUQBSBxbFS', 1, 0, 0, 0, 0),
  (6, 'Luis Ramirez', 'luis.ramirez@cengicana.org', 'solicitante', 6, '$2y$10$x4X3AfxVYlDJT7neuq96g.d2bZRowToBHlREc6Ro9CHDUQBSBxbFS', 1, 0, 0, 0, 0),
  (7, 'Super Admin', 'superadmin@cengicana.org', 'super_admin', 8, '$2y$10$x4X3AfxVYlDJT7neuq96g.d2bZRowToBHlREc6Ro9CHDUQBSBxbFS', 1, 1, 1, 1, 1);

UPDATE usuarios
SET password_hash = '$2y$10$x4X3AfxVYlDJT7neuq96g.d2bZRowToBHlREc6Ro9CHDUQBSBxbFS';

UPDATE usuarios
SET rol = 'administrador',
    permiso_crear_solicitudes = 1,
    permiso_gestionar_solicitudes = 1,
    permiso_administrar_usuarios = 1,
    permiso_ver_area = 1,
    permiso_ver_todo = 1
WHERE correo = 'ana.rodriguez@cengicana.org';

UPDATE usuarios
SET rol = 'direccion',
    permiso_crear_solicitudes = 1,
    permiso_gestionar_solicitudes = 1,
    permiso_administrar_usuarios = 0,
    permiso_ver_area = 1,
    permiso_ver_todo = 1
WHERE correo = 'direccion@cengicana.org';

INSERT IGNORE INTO solicitudes
  (id, codigo, solicitante_id, programa_origen_id, programa_destino_id, tipo, prioridad, estado, titulo, descripcion, fecha_requerida, proveedor_sugerido, monto_estimado, creado_en)
VALUES
  (1, 'SOL-2026-001', 1, 6, 6, 'compra', 'alta', 'proceso', 'Reactivos de laboratorio', 'Reactivos para analisis de muestras de cana. Se requieren kits y material complementario para temporada de cosecha.', '2026-06-25', 'Proveedor Agricola GT', 12500.00, '2026-06-14 09:15:00'),
  (2, 'SOL-2026-002', 1, 6, 6, 'ti', 'media', 'completado', 'Reinstalacion de sistema operativo', 'Reinstalacion de Windows y configuracion de herramientas institucionales en equipo de escritorio.', '2026-06-20', NULL, NULL, '2026-06-10 11:20:00'),
  (3, 'SOL-2026-003', 5, 2, 5, 'apoyo', 'media', 'recibido', 'Analisis de datos de campo', 'Apoyo para procesamiento y analisis estadistico de datos de campo de la temporada actual.', '2026-06-28', NULL, NULL, '2026-06-17 08:40:00'),
  (4, 'SOL-2026-004', 1, 6, 6, 'compra', 'baja', 'rechazado', 'Equipo de medicion portatil', 'Equipo portatil de medicion de humedad y temperatura para pruebas en campo.', '2026-07-05', 'Mediciones GT', 8700.00, '2026-06-02 15:00:00'),
  (5, 'SOL-2026-005', 4, 6, 6, 'ti', 'alta', 'proceso', 'Configuracion de VPN', 'Configuracion de acceso VPN para personal tecnico que necesita conectarse fuera de oficina.', '2026-06-21', NULL, NULL, '2026-06-15 10:05:00'),
  (6, 'SOL-2026-006', 6, 6, 1, 'apoyo', 'media', 'recibido', 'Apoyo en muestreo de campo', 'Apoyo del programa de Variedades para muestreo en parcelas experimentales.', '2026-06-26', NULL, NULL, '2026-06-17 13:25:00');

INSERT IGNORE INTO seguimientos (solicitud_id, usuario_id, estado, observacion, creado_en) VALUES
  (1, 1, 'recibido', 'Solicitud registrada por el sistema.', '2026-06-14 09:15:00'),
  (1, 2, 'proceso', 'Se inicio validacion de proveedor y disponibilidad presupuestaria.', '2026-06-14 10:30:00'),
  (2, 2, 'recibido', 'Solicitud recibida por soporte.', '2026-06-10 11:20:00'),
  (2, 2, 'completado', 'Equipo reinstalado y entregado al usuario.', '2026-06-11 16:10:00'),
  (3, 5, 'recibido', 'Solicitud de apoyo enviada al programa destino.', '2026-06-17 08:40:00'),
  (4, 2, 'rechazado', 'No se aprobo por falta de justificacion tecnica suficiente.', '2026-06-03 09:00:00'),
  (5, 2, 'proceso', 'Se estan validando permisos de red.', '2026-06-15 14:45:00'),
  (6, 6, 'recibido', 'Solicitud enviada al programa de Variedades.', '2026-06-17 13:25:00');
