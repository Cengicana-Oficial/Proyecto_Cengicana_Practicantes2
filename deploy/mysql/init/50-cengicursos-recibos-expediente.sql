-- Recibos de pago a instructores: validación del Jefe de Transferencia por recibo y expediente.
-- Antes el Jefe firmaba la liquidación completa; ahora valida cada recibo firmado (las facturas
-- no pasan por él). El expediente guarda cada envío (con el PDF emitido), cada firmado subido
-- (también los reemplazados: no se borran), la validación y el pago.
-- pago_recibos_helpers.php (cengi_pr_asegurar) crea lo mismo si falta.

ALTER TABLE liquidacion_recibos
  ADD COLUMN validado_en DATETIME NULL,
  ADD COLUMN validado_por VARCHAR(120) NOT NULL DEFAULT '',
  ADD COLUMN validado_nombre VARCHAR(160) NOT NULL DEFAULT '',
  ADD COLUMN validado_cargo VARCHAR(160) NOT NULL DEFAULT '',
  ADD COLUMN validado_imagen MEDIUMTEXT NULL,
  ADD COLUMN validado_codigo VARCHAR(40) NOT NULL DEFAULT '',
  ADD COLUMN validado_monto DECIMAL(12,2) NULL;

CREATE TABLE IF NOT EXISTS liquidacion_recibo_historial (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  detalle_id INT UNSIGNED NOT NULL,
  accion VARCHAR(30) NOT NULL,
  detalle VARCHAR(500) NOT NULL DEFAULT '',
  archivo_path VARCHAR(255) NOT NULL DEFAULT '',
  archivo_nombre VARCHAR(255) NOT NULL DEFAULT '',
  monto DECIMAL(12,2) NULL,
  usuario VARCHAR(160) NOT NULL DEFAULT '',
  creado DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_recibo_historial_detalle (detalle_id, creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
