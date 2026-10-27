-- Control QR de eventos (eventos_qr.php, eventos_pagos_api.php, eventos_pagos_helpers.php):
-- flujo de eventos pagados en dos pasos, como en el prototipo:
--   1. Administracion valida (o rechaza con motivo) el comprobante: pago_estado.
--   2. Capacitacion confirma la participacion: confirmado_en; en ese momento el codigo QR
--      queda habilitado para el ingreso y se envia el gafete por correo.
-- Eventos gratuitos: la participacion queda confirmada al inscribirse (pago_estado NULL).
--
-- evento_participantes.pago_estado: 'pendiente' (sin comprobante), 'por_validar',
--   'validado', 'rechazado' (pago_nota = motivo) o 'exonerado'. La columna pagado se
--   mantiene sincronizada (1 = validado o exonerado) para los reportes existentes.
-- eventos.costo_usd: costo para quien paga desde el extranjero (opcional).
-- eventos.permitir_sin_comprobante: la persona puede inscribirse y enviar el comprobante despues.
-- eventos_config: contacto de soporte de pagos que se muestra en el formulario publico
--   y en los recordatorios (clave/valor).
--
-- Es idempotente. Espejo en deploy/mysql/init/26-cengicursos-eventos-pagos.sql.

SET NAMES utf8mb4;

-- Cada columna, indice y llave se comprueba por separado: si el esquema quedo a medias
-- (version anterior o aplicacion parcial) se completa lo que falte.
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'pago_estado') = 0,
  'ALTER TABLE evento_participantes ADD COLUMN pago_estado VARCHAR(12) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'pago_origen') = 0,
  'ALTER TABLE evento_participantes ADD COLUMN pago_origen VARCHAR(10) NOT NULL DEFAULT \'nacional\'', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'pago_validado_por') = 0,
  'ALTER TABLE evento_participantes ADD COLUMN pago_validado_por VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'pago_validado_en') = 0,
  'ALTER TABLE evento_participantes ADD COLUMN pago_validado_en DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'pago_nota') = 0,
  'ALTER TABLE evento_participantes ADD COLUMN pago_nota VARCHAR(500) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'pago_recordatorio_en') = 0,
  'ALTER TABLE evento_participantes ADD COLUMN pago_recordatorio_en DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'confirmado_en') = 0,
  'ALTER TABLE evento_participantes ADD COLUMN confirmado_en DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'confirmado_por') = 0,
  'ALTER TABLE evento_participantes ADD COLUMN confirmado_por VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'gafete_enviado_en') = 0,
  'ALTER TABLE evento_participantes ADD COLUMN gafete_enviado_en DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento_participantes' AND COLUMN_NAME = 'categoria_gafete') = 0,
  'ALTER TABLE evento_participantes ADD COLUMN categoria_gafete VARCHAR(40) NOT NULL DEFAULT \'Participante\'', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

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

-- Cada columna, indice y llave se comprueba por separado: si el esquema quedo a medias
-- (version anterior o aplicacion parcial) se completa lo que falte.
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'costo_usd') = 0,
  'ALTER TABLE eventos ADD COLUMN costo_usd DECIMAL(10,2) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'permitir_sin_comprobante') = 0,
  'ALTER TABLE eventos ADD COLUMN permitir_sin_comprobante TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS eventos_config (
  clave VARCHAR(40) NOT NULL,
  valor TEXT NOT NULL,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
