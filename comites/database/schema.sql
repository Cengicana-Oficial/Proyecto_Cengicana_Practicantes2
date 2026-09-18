-- Esquema de referencia del modulo Comites, Transferencia y Comunicacion.
-- El bootstrap real (idempotente, CREATE TABLE IF NOT EXISTS) vive en
-- comites/config/database.php y se ejecuta automaticamente en cada carga
-- del modulo. Este archivo se deja como documentacion / script aplicable
-- manualmente si se necesita crear la base fuera de la aplicacion.

CREATE DATABASE IF NOT EXISTS sistema_comites
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE sistema_comites;

CREATE TABLE IF NOT EXISTS comites (
  id VARCHAR(40) PRIMARY KEY,
  nombre VARCHAR(160) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contactos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(180) NOT NULL,
  cargo VARCHAR(180) NULL,
  empresa VARCHAR(180) NULL,
  telefono VARCHAR(40) NULL,
  email VARCHAR(180) NULL,
  estado ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo',
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contacto_comite (
  contacto_id INT NOT NULL,
  comite_id VARCHAR(40) NOT NULL,
  PRIMARY KEY (contacto_id, comite_id),
  FOREIGN KEY (contacto_id) REFERENCES contactos(id) ON DELETE CASCADE,
  FOREIGN KEY (comite_id) REFERENCES comites(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reuniones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  comite_id VARCHAR(40) NULL,
  fecha DATE NULL,
  realizada TINYINT(1) NOT NULL DEFAULT 0,
  temas TEXT NULL,
  proximos_pasos TEXT NULL,
  responsable VARCHAR(180) NULL,
  cumplimiento INT NOT NULL DEFAULT 0,
  ayuda_memoria_nombre VARCHAR(255) NULL,
  ayuda_memoria_ruta VARCHAR(255) NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (comite_id) REFERENCES comites(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reunion_asistentes (
  reunion_id INT NOT NULL,
  contacto_id INT NOT NULL,
  PRIMARY KEY (reunion_id, contacto_id),
  FOREIGN KEY (reunion_id) REFERENCES reuniones(id) ON DELETE CASCADE,
  FOREIGN KEY (contacto_id) REFERENCES contactos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS acuerdos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  comite_id VARCHAR(40) NULL,
  reunion_id INT NULL,
  fecha DATE NULL,
  acuerdo TEXT NOT NULL,
  responsable VARCHAR(180) NULL,
  plazo DATE NULL,
  fecha_seguimiento DATE NULL,
  avance INT NOT NULL DEFAULT 0,
  estado ENUM('Pendiente','En proceso','Completado') NOT NULL DEFAULT 'Pendiente',
  observaciones TEXT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (comite_id) REFERENCES comites(id) ON DELETE SET NULL,
  FOREIGN KEY (reunion_id) REFERENCES reuniones(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Carpetas anidadas de la biblioteca documental. `ambito` separa el arbol de
-- "documentos" (ingenios) del de "biblioteca_interna" (interna); nunca se
-- mezclan. Se crea antes que `documentos` porque esta ultima referencia
-- `carpeta_id` a esta tabla.
CREATE TABLE IF NOT EXISTS documento_carpetas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ambito ENUM('ingenios','interna') NOT NULL DEFAULT 'ingenios',
  nombre VARCHAR(160) NOT NULL,
  parent_id INT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (parent_id) REFERENCES documento_carpetas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- `ambito` separa la "Biblioteca documental para ingenios" de la
-- "Biblioteca documental interna" (dos secciones de menu sobre el mismo modelo).
-- `carpeta` (texto libre) se conserva por compatibilidad con instalaciones
-- previas, pero ya no se usa: la navegacion real es por `carpeta_id`
-- (carpetas anidadas en documento_carpetas).
CREATE TABLE IF NOT EXISTS documentos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ambito ENUM('ingenios','interna') NOT NULL DEFAULT 'ingenios',
  tipo ENUM('Boletin','Memoria','Informe','Ayudamemoria','Presentacion','Fotografia','Video','Otro') NOT NULL DEFAULT 'Otro',
  carpeta VARCHAR(160) NULL,
  carpeta_id INT NULL,
  titulo VARCHAR(220) NOT NULL,
  fecha DATE NULL,
  comite_id VARCHAR(40) NULL,
  autor VARCHAR(180) NULL,
  enlace VARCHAR(500) NULL,
  descripcion TEXT NULL,
  archivo_nombre VARCHAR(255) NULL,
  archivo_ruta VARCHAR(255) NULL,
  version INT NOT NULL DEFAULT 1,
  estado VARCHAR(80) NOT NULL DEFAULT 'En revision',
  observaciones TEXT NULL,
  causa_rechazo TEXT NULL,
  habilitado_envio TINYINT(1) NOT NULL DEFAULT 0,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (comite_id) REFERENCES comites(id) ON DELETE SET NULL,
  FOREIGN KEY (carpeta_id) REFERENCES documento_carpetas(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS documento_historial (
  id INT AUTO_INCREMENT PRIMARY KEY,
  documento_id INT NOT NULL,
  estado VARCHAR(120) NOT NULL,
  actor VARCHAR(180) NULL,
  fecha DATE NULL,
  observaciones TEXT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (documento_id) REFERENCES documentos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS correos_enviados (
  id INT AUTO_INCREMENT PRIMARY KEY,
  documento_id INT NULL,
  asunto VARCHAR(220) NOT NULL,
  cuerpo TEXT NULL,
  destinatarios TEXT NULL,
  grupo_destino VARCHAR(160) NULL,
  enviado_por VARCHAR(180) NULL,
  enviado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (documento_id) REFERENCES documentos(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tableros (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(180) NOT NULL,
  descripcion TEXT NULL,
  url VARCHAR(500) NULL,
  tipo VARCHAR(80) NULL,
  estado ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo',
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS informes_cuatrimestrales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  periodo VARCHAR(80) NOT NULL,
  area VARCHAR(180) NULL,
  plan_operativo_nombre VARCHAR(255) NULL,
  plan_operativo_ruta VARCHAR(255) NULL,
  informe_nombre VARCHAR(255) NULL,
  informe_ruta VARCHAR(255) NULL,
  evaluacion VARCHAR(80) NOT NULL DEFAULT 'En revision',
  observaciones TEXT NULL,
  fecha DATE NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS informe_soportes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  informe_id INT NOT NULL,
  nombre VARCHAR(255) NOT NULL,
  ruta VARCHAR(255) NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (informe_id) REFERENCES informes_cuatrimestrales(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS comite_editorial (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(180) NOT NULL,
  cargo VARCHAR(180) NULL,
  email VARCHAR(180) NULL,
  estado ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo',
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS memorias_resultados (
  id INT AUTO_INCREMENT PRIMARY KEY,
  numero_memoria VARCHAR(40) NULL UNIQUE,
  titulo VARCHAR(220) NOT NULL,
  autor VARCHAR(180) NULL,
  autor_email VARCHAR(180) NULL,
  fecha DATE NULL,
  archivo_nombre VARCHAR(255) NULL,
  archivo_ruta VARCHAR(255) NULL,
  version INT NOT NULL DEFAULT 1,
  revisor_id INT NULL,
  estado VARCHAR(80) NOT NULL DEFAULT 'Recibida',
  criterios_archivo_nombre VARCHAR(255) NULL,
  criterios_archivo_ruta VARCHAR(255) NULL,
  revision_archivo VARCHAR(255) NULL,
  observaciones_revisor TEXT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (revisor_id) REFERENCES comite_editorial(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS memoria_track (
  id INT AUTO_INCREMENT PRIMARY KEY,
  memoria_id INT NOT NULL,
  etapa VARCHAR(120) NOT NULL,
  fecha DATE NULL,
  estado VARCHAR(80) NULL,
  observaciones TEXT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (memoria_id) REFERENCES memorias_resultados(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS publicaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NULL,
  plataforma VARCHAR(40) NULL,
  tipo VARCHAR(60) NULL,
  titulo VARCHAR(220) NOT NULL,
  copy_texto TEXT NULL,
  material VARCHAR(255) NULL,
  estado ENUM('Borrador','Programada','Publicada','Pausada') NOT NULL DEFAULT 'Borrador',
  responsable VARCHAR(180) NULL,
  url VARCHAR(500) NULL,
  alcance INT NOT NULL DEFAULT 0,
  impresiones INT NOT NULL DEFAULT 0,
  interacciones INT NOT NULL DEFAULT 0,
  reacciones INT NOT NULL DEFAULT 0,
  comentarios INT NOT NULL DEFAULT 0,
  compartidos INT NOT NULL DEFAULT 0,
  clics INT NOT NULL DEFAULT 0,
  reproducciones INT NOT NULL DEFAULT 0,
  guardados INT NOT NULL DEFAULT 0,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO comites (id, nombre) VALUES
  ('riego', 'Comite de Riego'),
  ('nutricion', 'Comite de Nutricion'),
  ('malezas', 'Comite de Malezas y Madurantes'),
  ('canamip', 'Comite CAÑAMIP'),
  ('capacitacion', 'Comite de Capacitacion'),
  ('cosecha', 'Comite de Cosecha'),
  ('rtk', 'Comite RTK'),
  ('siembra', 'Comite de Siembra'),
  ('variedades', 'Comite de Variedades'),
  ('agri-inteligente', 'Comite de Agricultura Inteligente'),
  ('investigacion', 'Comite de Investigacion'),
  ('estrategico', 'Comite Estrategico'),
  ('tactico', 'Comite Tactico'),
  ('industrial', 'Comite Industrial');
