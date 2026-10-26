USE cengi_cursos;

-- Espejo de cengicursos/migrations/20261014_proformas_pago.sql (ver comentarios alli).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS proformas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  numero INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  vence DATE NOT NULL,
  ingenio_id INT UNSIGNED NOT NULL,
  dirigido VARCHAR(255) NOT NULL DEFAULT '',
  contacto VARCHAR(80) NOT NULL DEFAULT '',
  correo VARCHAR(255) NOT NULL DEFAULT '',
  nit VARCHAR(30) NOT NULL DEFAULT '',
  pais VARCHAR(80) NOT NULL DEFAULT 'Guatemala',
  tipo_cliente VARCHAR(20) NOT NULL DEFAULT 'asociado',
  moneda CHAR(3) NOT NULL DEFAULT 'GTQ',
  cargo_monto DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  obs VARCHAR(500) NOT NULL DEFAULT '',
  estado VARCHAR(12) NOT NULL DEFAULT 'emitida',
  pago_forma VARCHAR(60) NOT NULL DEFAULT '',
  pago_referencia VARCHAR(120) NOT NULL DEFAULT '',
  pago_fecha DATE NULL,
  creado_por_nombre VARCHAR(255) NOT NULL DEFAULT '',
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_proformas_numero (numero),
  KEY idx_proformas_ingenio (ingenio_id, estado),
  CONSTRAINT fk_proformas_ingenio FOREIGN KEY (ingenio_id) REFERENCES ingenios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proforma_lineas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  proforma_id INT UNSIGNED NOT NULL,
  planificacion_id INT UNSIGNED NOT NULL,
  descripcion VARCHAR(255) NOT NULL,
  cantidad INT UNSIGNED NOT NULL DEFAULT 1,
  precio DECIMAL(10,2) NOT NULL DEFAULT 0,
  desc_tipo VARCHAR(6) NOT NULL DEFAULT 'monto',
  desc_valor DECIMAL(10,2) NOT NULL DEFAULT 0,
  desc_unit DECIMAL(10,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  grupo_pago_id INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_proforma_lineas_pf (proforma_id),
  KEY idx_proforma_lineas_plan (planificacion_id),
  CONSTRAINT fk_proforma_lineas_pf FOREIGN KEY (proforma_id) REFERENCES proformas (id) ON DELETE CASCADE,
  CONSTRAINT fk_proforma_lineas_plan FOREIGN KEY (planificacion_id) REFERENCES planificacion_cursos (id),
  CONSTRAINT fk_proforma_lineas_grupo FOREIGN KEY (grupo_pago_id) REFERENCES pagos_grupales (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proforma_historial (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  proforma_id INT UNSIGNED NOT NULL,
  texto VARCHAR(120) NOT NULL,
  detalle VARCHAR(500) NOT NULL DEFAULT '',
  por VARCHAR(255) NOT NULL DEFAULT '',
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_proforma_historial_pf (proforma_id),
  CONSTRAINT fk_proforma_historial_pf FOREIGN KEY (proforma_id) REFERENCES proformas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Orden grupal ligada a una proforma: monto fijo (el de la linea), cupos y moneda.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos_grupales' AND COLUMN_NAME = 'proforma_id');
SET @ddl := IF(@col = 0,
  'ALTER TABLE pagos_grupales
     ADD COLUMN proforma_id INT UNSIGNED NULL AFTER ingenio_id,
     ADD COLUMN cupos INT UNSIGNED NULL AFTER proforma_id,
     ADD COLUMN moneda CHAR(3) NOT NULL DEFAULT ''GTQ'' AFTER monto,
     ADD KEY idx_pagos_grupales_proforma (proforma_id),
     ADD CONSTRAINT fk_pagos_grupales_proforma FOREIGN KEY (proforma_id) REFERENCES proformas (id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Descuento por participante que viene de la proforma (monto ya lo trae aplicado).
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'descuento');
SET @ddl := IF(@col = 0, 'ALTER TABLE solicitudes_inscripcion ADD COLUMN descuento DECIMAL(10,2) NULL AFTER monto', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
