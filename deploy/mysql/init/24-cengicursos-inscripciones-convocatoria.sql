USE cengi_cursos;

-- Espejo de cengicursos/migrations/20261008_inscripciones_convocatoria.sql (ver comentarios alli).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS pagos_grupales (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  planificacion_id INT UNSIGNED NOT NULL,
  ingenio_id INT UNSIGNED NOT NULL,
  orden VARCHAR(80) NOT NULL DEFAULT '',
  monto DECIMAL(12,2) NOT NULL DEFAULT 0,
  estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
  comprobante_ruta VARCHAR(255) NULL,
  comprobante_nombre VARCHAR(255) NOT NULL DEFAULT '',
  pago_forma VARCHAR(60) NOT NULL DEFAULT '',
  pago_referencia VARCHAR(120) NOT NULL DEFAULT '',
  pago_fecha DATE NULL,
  verificado_por VARCHAR(255) NOT NULL DEFAULT '',
  verificado_en DATETIME NULL,
  nota VARCHAR(500) NOT NULL DEFAULT '',
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pagos_grupales_plan (planificacion_id, ingenio_id),
  CONSTRAINT fk_pagos_grupales_plan
    FOREIGN KEY (planificacion_id) REFERENCES planificacion_cursos (id) ON DELETE CASCADE,
  CONSTRAINT fk_pagos_grupales_ingenio
    FOREIGN KEY (ingenio_id) REFERENCES ingenios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada columna, indice y llave se comprueba por separado: si el esquema quedo a medias
-- (version anterior o aplicacion parcial) se completa lo que falte.
-- curso_id pasa a ser opcional (inscripciones a una convocatoria aun sin curso).
SET @ddl := IF((SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'curso_id') = 'NO',
  'ALTER TABLE solicitudes_inscripcion MODIFY curso_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'planificacion_id') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN planificacion_id INT UNSIGNED NULL AFTER curso_id', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'institucion_otra') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN institucion_otra VARCHAR(255) NOT NULL DEFAULT \'\' AFTER ingenio_id', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'como_se_entero') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN como_se_entero VARCHAR(60) NOT NULL DEFAULT \'\'', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'cuota') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN cuota VARCHAR(20) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'monto') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN monto DECIMAL(10,2) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'moneda') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN moneda CHAR(3) NOT NULL DEFAULT \'GTQ\'', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'pago_estado') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN pago_estado VARCHAR(20) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'boleta') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN boleta VARCHAR(60) NOT NULL DEFAULT \'\'', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'pago_fecha') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN pago_fecha DATE NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'monto_comprobante') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN monto_comprobante DECIMAL(10,2) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'comprobante_ruta') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN comprobante_ruta VARCHAR(255) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'comprobante_nombre') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN comprobante_nombre VARCHAR(255) NOT NULL DEFAULT \'\'', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'validacion_ingenio') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN validacion_ingenio VARCHAR(20) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'validacion_ingenio_por') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN validacion_ingenio_por VARCHAR(255) NOT NULL DEFAULT \'\'', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'validacion_ingenio_en') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN validacion_ingenio_en DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'validacion_ingenio_nota') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN validacion_ingenio_nota VARCHAR(500) NOT NULL DEFAULT \'\'', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'validacion_solicitada_en') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN validacion_solicitada_en DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'pago_verificado_por') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN pago_verificado_por VARCHAR(255) NOT NULL DEFAULT \'\'', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'pago_verificado_en') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN pago_verificado_en DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'pago_nota') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN pago_nota VARCHAR(500) NOT NULL DEFAULT \'\'', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'pago_forma') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN pago_forma VARCHAR(60) NOT NULL DEFAULT \'\'', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'pago_referencia') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN pago_referencia VARCHAR(120) NOT NULL DEFAULT \'\'', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'pago_registrado_en') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN pago_registrado_en DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'pago_registrado_por') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN pago_registrado_por VARCHAR(255) NOT NULL DEFAULT \'\'', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'grupo_pago_id') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN grupo_pago_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'asignado_por') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN asignado_por VARCHAR(255) NOT NULL DEFAULT \'\'', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'asignado_en') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD COLUMN asignado_en DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND INDEX_NAME = 'idx_solicitudes_plan') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD KEY idx_solicitudes_plan (planificacion_id, estado)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND INDEX_NAME = 'idx_solicitudes_grupo') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD KEY idx_solicitudes_grupo (grupo_pago_id)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND CONSTRAINT_NAME = 'fk_solicitudes_plan' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD CONSTRAINT fk_solicitudes_plan FOREIGN KEY (planificacion_id) REFERENCES planificacion_cursos (id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND CONSTRAINT_NAME = 'fk_solicitudes_grupo' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0,
  'ALTER TABLE solicitudes_inscripcion ADD CONSTRAINT fk_solicitudes_grupo FOREIGN KEY (grupo_pago_id) REFERENCES pagos_grupales (id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
