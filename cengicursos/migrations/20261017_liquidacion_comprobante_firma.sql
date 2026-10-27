-- Pago a instructores: comprobante (factura o recibo), DPI y firma del Jefe de Transferencia.
--
--   instructores / soporte_tecnico
--     dpi          DPI para el recibo.
--     comprobante  'factura' o 'recibo': lo que la persona presenta al cobrar (valor por
--                  defecto al liquidar; el % de impuesto de cada uno está en eventos_config
--                  clave pi_config, editable en Pago a instructores → Configuración).
--   liquidacion_detalle
--     comprobante, impuesto_pct  quedan fijos en la liquidación (el % puede cambiar después).
--     persona_dpi                DPI al momento de liquidar.
--     documento_numero           recibo: correlativo R-AAAA-0000; factura: número que se
--                                anota al registrar el pago.
--   liquidaciones
--     firmada_en / firmada_por / firma_codigo / firma_imagen / firma_nombre / firma_cargo
--     Validación del Jefe de Transferencia de Tecnología (con su contraseña). El código es un
--     HMAC del contenido: si la liquidación cambia, la firma deja de ser válida. Una corrección
--     retira la firma.
--
-- Idempotente. Espejo en deploy/mysql/init/33-cengicursos-liquidacion-comprobante-firma.sql.
-- pago_instructores.php también agrega las columnas que falten.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS cengi_agregar_columna;
DELIMITER //
CREATE PROCEDURE cengi_agregar_columna(IN tabla VARCHAR(64), IN columna VARCHAR(64), IN definicion VARCHAR(255))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tabla AND COLUMN_NAME = columna) THEN
    SET @ddl = CONCAT('ALTER TABLE ', tabla, ' ADD COLUMN ', columna, ' ', definicion);
    PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END //
DELIMITER ;

CALL cengi_agregar_columna('instructores', 'dpi', "VARCHAR(20) NOT NULL DEFAULT ''");
CALL cengi_agregar_columna('instructores', 'comprobante', "VARCHAR(10) NOT NULL DEFAULT 'factura'");
CALL cengi_agregar_columna('soporte_tecnico', 'dpi', "VARCHAR(20) NOT NULL DEFAULT ''");
CALL cengi_agregar_columna('soporte_tecnico', 'comprobante', "VARCHAR(10) NOT NULL DEFAULT 'factura'");
CALL cengi_agregar_columna('liquidacion_detalle', 'comprobante', "VARCHAR(10) NOT NULL DEFAULT 'factura'");
CALL cengi_agregar_columna('liquidacion_detalle', 'impuesto_pct', "DECIMAL(5,2) NOT NULL DEFAULT 5.00");
CALL cengi_agregar_columna('liquidacion_detalle', 'persona_dpi', "VARCHAR(20) NOT NULL DEFAULT ''");
CALL cengi_agregar_columna('liquidacion_detalle', 'documento_numero', "VARCHAR(40) NOT NULL DEFAULT ''");
CALL cengi_agregar_columna('liquidaciones', 'firmada_en', 'DATETIME NULL');
CALL cengi_agregar_columna('liquidaciones', 'firmada_por', "VARCHAR(255) NOT NULL DEFAULT ''");
CALL cengi_agregar_columna('liquidaciones', 'firma_codigo', "VARCHAR(20) NOT NULL DEFAULT ''");
CALL cengi_agregar_columna('liquidaciones', 'firma_imagen', 'MEDIUMTEXT NULL');
CALL cengi_agregar_columna('liquidaciones', 'firma_nombre', "VARCHAR(255) NOT NULL DEFAULT ''");
CALL cengi_agregar_columna('liquidaciones', 'firma_cargo', "VARCHAR(255) NOT NULL DEFAULT ''");

DROP PROCEDURE IF EXISTS cengi_agregar_columna;
