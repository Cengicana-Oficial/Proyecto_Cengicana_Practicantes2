USE cengi_cursos;

-- Espejo de cengicursos/migrations/20261010_eventos_pagos_confirmacion.sql (ver comentarios alli).

SET NAMES utf8mb4;

SET @col_ep_estado := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'pago_estado'
);
SET @ddl_ep_estado := IF(
  @col_ep_estado = 0,
  'ALTER TABLE evento_participantes
     ADD COLUMN pago_estado VARCHAR(12) NULL,
     ADD COLUMN pago_origen VARCHAR(10) NOT NULL DEFAULT \'nacional\',
     ADD COLUMN pago_validado_por VARCHAR(150) NULL,
     ADD COLUMN pago_validado_en DATETIME NULL,
     ADD COLUMN pago_nota VARCHAR(500) NULL,
     ADD COLUMN pago_recordatorio_en DATETIME NULL,
     ADD COLUMN confirmado_en DATETIME NULL,
     ADD COLUMN confirmado_por VARCHAR(150) NULL,
     ADD COLUMN gafete_enviado_en DATETIME NULL,
     ADD COLUMN categoria_gafete VARCHAR(40) NOT NULL DEFAULT \'Participante\'',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_ep_estado; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Datos existentes: en eventos pagados, pagado = 1 equivale a pago validado y participacion
-- confirmada (antes el QR quedaba listo al marcar el pago); en gratuitos todos confirmados.
UPDATE evento_participantes ep INNER JOIN eventos e ON e.id = ep.evento_id
SET ep.pago_estado = CASE
      WHEN ep.pagado = 1 THEN 'validado'
      WHEN ep.recibo_pago IS NOT NULL AND ep.recibo_pago <> '' THEN 'por_validar'
      ELSE 'pendiente' END
WHERE e.modalidad_pago = 'Pagado' AND ep.pago_estado IS NULL;

UPDATE evento_participantes ep INNER JOIN eventos e ON e.id = ep.evento_id
SET ep.confirmado_en = COALESCE(ep.creado, NOW())
WHERE ep.confirmado_en IS NULL AND (e.modalidad_pago <> 'Pagado' OR ep.pagado = 1);

SET @col_ev_usd := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'costo_usd'
);
SET @ddl_ev_usd := IF(
  @col_ev_usd = 0,
  'ALTER TABLE eventos
     ADD COLUMN costo_usd DECIMAL(10,2) NULL,
     ADD COLUMN permitir_sin_comprobante TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_ev_usd; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS eventos_config (
  clave VARCHAR(40) NOT NULL,
  valor TEXT NOT NULL,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
