USE cengi_cursos;

-- Espejo de cengicursos/migrations/20261012_eventos_acuerdos.sql (ver comentarios alli).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS evento_acuerdos (
  evento_id INT UNSIGNED NOT NULL,
  estado VARCHAR(12) NOT NULL DEFAULT 'borrador',
  modo VARCHAR(12) NOT NULL DEFAULT 'presencial',
  dias SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  participantes INT UNSIGNED NOT NULL DEFAULT 50,
  alims_json TEXT NULL,
  extras_json TEXT NULL,
  precios_json TEXT NULL,
  descuento DECIMAL(12,2) NOT NULL DEFAULT 0,
  tareas_json TEXT NULL,
  fiscal_recibo VARCHAR(255) NULL,
  fiscal_nit VARCHAR(30) NULL,
  fiscal_direccion VARCHAR(255) NULL,
  fiscal_descripcion VARCHAR(500) NULL,
  fiscal_objetivo VARCHAR(500) NULL,
  propuesta_enviada_en DATETIME NULL,
  propuesta_enviada_a VARCHAR(255) NULL,
  acept_representante VARCHAR(255) NULL,
  acept_cargo VARCHAR(150) NULL,
  acept_fecha DATE NULL,
  acept_participantes INT UNSIGNED NULL,
  acept_archivo VARCHAR(255) NULL,
  acept_archivo_nombre VARCHAR(255) NULL,
  acept_archivo_fecha DATETIME NULL,
  fecha_limite DATE NULL,
  excedente_json TEXT NULL,
  cargos_json TEXT NULL,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (evento_id),
  CONSTRAINT fk_acuerdo_evento FOREIGN KEY (evento_id) REFERENCES eventos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evento_acuerdo_recibos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  evento_id INT UNSIGNED NOT NULL,
  tipo VARCHAR(10) NOT NULL DEFAULT 'aporte',
  estado VARCHAR(12) NOT NULL DEFAULT 'solicitado',
  monto DECIMAL(12,2) NOT NULL DEFAULT 0,
  a_nombre VARCHAR(255) NOT NULL,
  nit VARCHAR(30) NOT NULL,
  direccion VARCHAR(255) NULL,
  concepto VARCHAR(600) NOT NULL,
  solicitado_en DATETIME NOT NULL,
  solicitado_por VARCHAR(150) NULL,
  numero VARCHAR(60) NULL,
  fecha DATE NULL,
  archivo VARCHAR(255) NULL,
  archivo_nombre VARCHAR(255) NULL,
  cargado_en DATETIME NULL,
  cargado_por VARCHAR(150) NULL,
  enviado_en DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_acuerdo_recibo_numero (numero),
  KEY idx_acuerdo_recibo_evento (evento_id),
  CONSTRAINT fk_acuerdo_recibo_evento FOREIGN KEY (evento_id) REFERENCES eventos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evento_acuerdo_pagos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  evento_id INT UNSIGNED NOT NULL,
  tipo VARCHAR(10) NOT NULL DEFAULT 'aporte',
  forma VARCHAR(20) NOT NULL,
  banco VARCHAR(120) NULL,
  referencia VARCHAR(80) NOT NULL,
  fecha DATE NOT NULL,
  monto DECIMAL(12,2) NOT NULL,
  comprobante VARCHAR(255) NULL,
  comprobante_nombre VARCHAR(255) NULL,
  obs VARCHAR(255) NULL,
  recibo_id INT UNSIGNED NULL,
  registrado_por VARCHAR(150) NULL,
  creado DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_acuerdo_pago_evento (evento_id),
  CONSTRAINT fk_acuerdo_pago_evento FOREIGN KEY (evento_id) REFERENCES eventos (id) ON DELETE CASCADE,
  CONSTRAINT fk_acuerdo_pago_recibo FOREIGN KEY (recibo_id) REFERENCES evento_acuerdo_recibos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evento_acuerdo_historial (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  evento_id INT UNSIGNED NOT NULL,
  texto VARCHAR(200) NOT NULL,
  detalle VARCHAR(500) NULL,
  por VARCHAR(150) NULL,
  creado DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_acuerdo_hist_evento (evento_id),
  CONSTRAINT fk_acuerdo_hist_evento FOREIGN KEY (evento_id) REFERENCES eventos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
