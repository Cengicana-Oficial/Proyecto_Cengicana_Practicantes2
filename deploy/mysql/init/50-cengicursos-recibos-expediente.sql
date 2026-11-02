USE cengi_cursos;
-- Recibos de pago a instructores: validación del Jefe de Transferencia por recibo y expediente.
-- Antes el Jefe firmaba la liquidación completa; ahora valida cada recibo firmado (las facturas
-- no pasan por él). El expediente guarda cada envío (con el PDF emitido), cada firmado subido
-- (también los reemplazados: no se borran), la validación y el pago.
-- La aplicacion ya no crea esta estructura: solo esta migracion (AUD-2026-001/I-2).

-- Idempotente: cada columna se agrega solo si falta (actualizar-bd.sh se puede repetir).
SET @c1=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='liquidacion_recibos' AND COLUMN_NAME='validado_en');
SET @s1=IF(@c1=0,"ALTER TABLE liquidacion_recibos ADD COLUMN validado_en DATETIME NULL","SELECT 1");
PREPARE st1 FROM @s1;EXECUTE st1;DEALLOCATE PREPARE st1;
SET @c2=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='liquidacion_recibos' AND COLUMN_NAME='validado_por');
SET @s2=IF(@c2=0,"ALTER TABLE liquidacion_recibos ADD COLUMN validado_por VARCHAR(120) NOT NULL DEFAULT ''","SELECT 1");
PREPARE st2 FROM @s2;EXECUTE st2;DEALLOCATE PREPARE st2;
SET @c3=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='liquidacion_recibos' AND COLUMN_NAME='validado_nombre');
SET @s3=IF(@c3=0,"ALTER TABLE liquidacion_recibos ADD COLUMN validado_nombre VARCHAR(160) NOT NULL DEFAULT ''","SELECT 1");
PREPARE st3 FROM @s3;EXECUTE st3;DEALLOCATE PREPARE st3;
SET @c4=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='liquidacion_recibos' AND COLUMN_NAME='validado_cargo');
SET @s4=IF(@c4=0,"ALTER TABLE liquidacion_recibos ADD COLUMN validado_cargo VARCHAR(160) NOT NULL DEFAULT ''","SELECT 1");
PREPARE st4 FROM @s4;EXECUTE st4;DEALLOCATE PREPARE st4;
SET @c5=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='liquidacion_recibos' AND COLUMN_NAME='validado_imagen');
SET @s5=IF(@c5=0,"ALTER TABLE liquidacion_recibos ADD COLUMN validado_imagen MEDIUMTEXT NULL","SELECT 1");
PREPARE st5 FROM @s5;EXECUTE st5;DEALLOCATE PREPARE st5;
SET @c6=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='liquidacion_recibos' AND COLUMN_NAME='validado_codigo');
SET @s6=IF(@c6=0,"ALTER TABLE liquidacion_recibos ADD COLUMN validado_codigo VARCHAR(40) NOT NULL DEFAULT ''","SELECT 1");
PREPARE st6 FROM @s6;EXECUTE st6;DEALLOCATE PREPARE st6;
SET @c7=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='liquidacion_recibos' AND COLUMN_NAME='validado_monto');
SET @s7=IF(@c7=0,"ALTER TABLE liquidacion_recibos ADD COLUMN validado_monto DECIMAL(12,2) NULL","SELECT 1");
PREPARE st7 FROM @s7;EXECUTE st7;DEALLOCATE PREPARE st7;

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
