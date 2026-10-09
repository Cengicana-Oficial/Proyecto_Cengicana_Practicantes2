-- Inscripciones por convocatoria y pagos de inscripcion (inscripcion.php,
-- inscripciones.php, pagos_inscripcion.php; logica en inscripcion_helpers.php).
--
-- Flujo: aprobar una planificacion abre la convocatoria (sin crear el curso todavia).
-- Cada solicitud queda ligada a la planificacion (planificacion_id) con su cuota
-- (asociado/externo/extranjero), monto y quien paga (Ingenio, Propio o Exonerado).
-- Validacion: el ingenio valida a los suyos (validacion_ingenio) o Administracion valida
-- el comprobante del pago propio (pago_estado = 'validado'). Cuando las validadas llegan
-- al cupo minimo, se crea el curso y se asignan; despues se asignan directo o pasan a
-- lista de espera si el curso esta lleno. curso_id queda NULL hasta que exista el curso.
--
-- Las solicitudes antiguas (planificacion_id NULL) siguen el flujo de solicitudes.php.
-- Es idempotente. Espejo en deploy/mysql/init/24-cengicursos-inscripciones-convocatoria.sql.

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

SET @col_sol_plan := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes_inscripcion' AND COLUMN_NAME = 'planificacion_id'
);
SET @ddl_sol_plan := IF(
  @col_sol_plan = 0,
  'ALTER TABLE solicitudes_inscripcion
     MODIFY curso_id INT UNSIGNED NULL,
     ADD COLUMN planificacion_id INT UNSIGNED NULL AFTER curso_id,
     ADD COLUMN institucion_otra VARCHAR(255) NOT NULL DEFAULT \'\' AFTER ingenio_id,
     ADD COLUMN como_se_entero VARCHAR(60) NOT NULL DEFAULT \'\',
     ADD COLUMN cuota VARCHAR(20) NULL,
     ADD COLUMN monto DECIMAL(10,2) NULL,
     ADD COLUMN moneda CHAR(3) NOT NULL DEFAULT \'GTQ\',
     ADD COLUMN pago_estado VARCHAR(20) NULL,
     ADD COLUMN boleta VARCHAR(60) NOT NULL DEFAULT \'\',
     ADD COLUMN pago_fecha DATE NULL,
     ADD COLUMN monto_comprobante DECIMAL(10,2) NULL,
     ADD COLUMN comprobante_ruta VARCHAR(255) NULL,
     ADD COLUMN comprobante_nombre VARCHAR(255) NOT NULL DEFAULT \'\',
     ADD COLUMN validacion_ingenio VARCHAR(20) NULL,
     ADD COLUMN validacion_ingenio_por VARCHAR(255) NOT NULL DEFAULT \'\',
     ADD COLUMN validacion_ingenio_en DATETIME NULL,
     ADD COLUMN validacion_ingenio_nota VARCHAR(500) NOT NULL DEFAULT \'\',
     ADD COLUMN validacion_solicitada_en DATETIME NULL,
     ADD COLUMN pago_verificado_por VARCHAR(255) NOT NULL DEFAULT \'\',
     ADD COLUMN pago_verificado_en DATETIME NULL,
     ADD COLUMN pago_nota VARCHAR(500) NOT NULL DEFAULT \'\',
     ADD COLUMN pago_forma VARCHAR(60) NOT NULL DEFAULT \'\',
     ADD COLUMN pago_referencia VARCHAR(120) NOT NULL DEFAULT \'\',
     ADD COLUMN pago_registrado_en DATETIME NULL,
     ADD COLUMN pago_registrado_por VARCHAR(255) NOT NULL DEFAULT \'\',
     ADD COLUMN grupo_pago_id INT UNSIGNED NULL,
     ADD COLUMN asignado_por VARCHAR(255) NOT NULL DEFAULT \'\',
     ADD COLUMN asignado_en DATETIME NULL,
     ADD KEY idx_solicitudes_plan (planificacion_id, estado),
     ADD KEY idx_solicitudes_grupo (grupo_pago_id),
     ADD CONSTRAINT fk_solicitudes_plan FOREIGN KEY (planificacion_id) REFERENCES planificacion_cursos (id) ON DELETE SET NULL,
     ADD CONSTRAINT fk_solicitudes_grupo FOREIGN KEY (grupo_pago_id) REFERENCES pagos_grupales (id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_sol_plan; EXECUTE stmt; DEALLOCATE PREPARE stmt;
