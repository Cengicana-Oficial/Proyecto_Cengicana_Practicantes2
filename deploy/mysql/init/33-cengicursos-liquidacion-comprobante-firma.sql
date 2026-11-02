USE cengi_cursos;

-- Espejo de cengicursos/migrations/20261017_liquidacion_comprobante_firma.sql (ver comentarios alli).

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
