-- =====================================================================
-- 002_lab_flujo_generico.sql
--
-- Migracion ADITIVA para el modulo Laboratorio. Agrega el soporte de
-- datos que le faltaba al esquema existente (laboratorios_prueba.sql)
-- para implementar, con datos reales, las piezas del flujo tipo SIGELAB
-- que no tenian equivalente: trazabilidad transversal por lote,
-- documentos versionados (boletas/informes) y firma electronica.
--
-- No modifica ni elimina ninguna tabla existente. Ademas, reutiliza al
-- maximo lo que ya existe en vez de duplicarlo:
--   - Planificacion (Kanban)      -> tablas `formulario` + `estado_formulario`
--                                    ya existentes (formulario.analista,
--                                    formulario.id_estado). No se crea tabla
--                                    nueva para esto.
--   - Historial por analisis      -> tabla `historial_formulario` ya
--                                    existente (se sigue usando tal cual).
--   - Validacion tecnica          -> mismo par formulario/estado_formulario
--                                    + historial_formulario para el detalle
--                                    de quien aprobo/rechazo y por que.
--   - Trazabilidad completa       -> NUEVA tabla `lab_evento_trazabilidad`
--                                    (eventos a nivel de lote/solicitud que
--                                    no pertenecen a un analisis puntual:
--                                    recepcion, boleta generada, envio a
--                                    validacion, entrega). La vista de
--                                    trazabilidad de un lote se arma
--                                    combinando esta tabla con
--                                    `historial_formulario`.
--   - Documentos (boleta/informe) -> NUEVAS tablas `lab_documento` y
--                                    `lab_documento_historial` (versionado).
--   - Firma electronica           -> NUEVA tabla `lab_firma_documento`,
--                                    reutilizando el mismo patron que
--                                    `solicitud.firma_ingreso` /
--                                    `solicitud.firma_recibe`
--                                    (LONGTEXT con data URI base64 PNG,
--                                    ver includes/solicitud_formulario_helpers.php
--                                    -> normalizarFirmaSolicitud()).
--
-- Aplicar despues de laboratorios_prueba.sql. Seguro de ejecutar mas de
-- una vez (usa IF NOT EXISTS / DROP TABLE IF EXISTS solo para las tablas
-- que crea esta misma migracion).
-- =====================================================================

-- ---------------------------------------------------------------------
-- Semilla minima de estados en estado_formulario, usados como columnas
-- del Kanban de planificacion y como estados de validacion tecnica.
-- Solo inserta los que no existan aun (por nombre), para no duplicar los
-- que ya pudiera tener cargados un ambiente existente.
-- ---------------------------------------------------------------------
INSERT INTO estado_formulario (nombre, descripcion)
SELECT * FROM (
    SELECT 'Pendiente' AS nombre, 'Analisis asignado al lote, aun sin analista ni captura' AS descripcion
    UNION ALL SELECT 'Asignado', 'Analisis asignado a un analista, captura no iniciada'
    UNION ALL SELECT 'En proceso', 'Captura de resultados en curso'
    UNION ALL SELECT 'En revision', 'Resultados capturados, pendientes de validacion tecnica'
    UNION ALL SELECT 'Aprobado', 'Resultado validado y aprobado por el revisor/jefe de laboratorio'
    UNION ALL SELECT 'Rechazado', 'Resultado rechazado en validacion tecnica, requiere repetirse'
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM estado_formulario ef WHERE LOWER(ef.nombre) = LOWER(seed.nombre)
);

-- ---------------------------------------------------------------------
-- lab_evento_trazabilidad
-- Bitacora generica de eventos a nivel de lote/solicitud, para construir
-- la linea de tiempo de trazabilidad completa de un lote (recepcion,
-- generacion/envio de boleta, envio a laboratorio, entrega, etc).
-- `id_formulario` es opcional: cuando el evento corresponde a un analisis
-- puntual se prefiere usar historial_formulario; este campo solo permite
-- enlazar el evento genérico con el analisis relacionado si aplica.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `lab_evento_trazabilidad`;
CREATE TABLE `lab_evento_trazabilidad` (
  `id_evento` INT NOT NULL AUTO_INCREMENT,
  `id_lote` INT NOT NULL,
  `id_solicitud` INT DEFAULT NULL,
  `id_formulario` INT DEFAULT NULL,
  `codigo_muestra` VARCHAR(255) DEFAULT NULL,
  `tipo_evento` VARCHAR(60) NOT NULL COMMENT 'recepcion|boleta_generada|boleta_enviada|analisis_asignado|analisis_en_proceso|enviado_validacion|aprobado|rechazado|informe_generado|informe_firmado|entregado|otro',
  `descripcion` VARCHAR(500) DEFAULT NULL,
  `es_alerta` TINYINT(1) NOT NULL DEFAULT 0,
  `usuario_id` INT DEFAULT NULL,
  `usuario_nombre` VARCHAR(255) DEFAULT NULL,
  `fecha` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_evento`),
  KEY `fk_evtraz_lote` (`id_lote`),
  KEY `fk_evtraz_solicitud` (`id_solicitud`),
  KEY `fk_evtraz_formulario` (`id_formulario`),
  KEY `idx_evtraz_fecha` (`fecha`),
  CONSTRAINT `fk_evtraz_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`),
  CONSTRAINT `fk_evtraz_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `fk_evtraz_formulario` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- lab_documento
-- Documentos generados del flujo (boleta de recepcion, informe de
-- resultados), versionados. `contenido_html` guarda el render (misma
-- idea que components/encabezado_doc.php + pie_pagina.php) vigente al
-- momento de generar/editar el documento, para poder reabrirlo tal cual
-- se entrego aunque los datos de origen cambien despues.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `lab_documento`;
CREATE TABLE `lab_documento` (
  `id_documento` INT NOT NULL AUTO_INCREMENT,
  `tipo_documento` ENUM('boleta','informe') NOT NULL,
  `id_lote` INT NOT NULL,
  `id_solicitud` INT DEFAULT NULL,
  `version` INT NOT NULL DEFAULT 1,
  `titulo` VARCHAR(255) DEFAULT NULL,
  `contenido_html` LONGTEXT DEFAULT NULL,
  `vigente` TINYINT(1) NOT NULL DEFAULT 1,
  `generado_por` VARCHAR(255) DEFAULT NULL,
  `generado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_documento`),
  KEY `fk_labdoc_lote` (`id_lote`),
  KEY `fk_labdoc_solicitud` (`id_solicitud`),
  CONSTRAINT `fk_labdoc_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`),
  CONSTRAINT `fk_labdoc_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- lab_documento_historial
-- Control de cambios de un documento (quien edito que version y por
-- que), analogo a historial_formulario pero para documentos.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `lab_documento_historial`;
CREATE TABLE `lab_documento_historial` (
  `id_historial` INT NOT NULL AUTO_INCREMENT,
  `id_documento` INT NOT NULL,
  `version` INT NOT NULL,
  `cambios` TEXT DEFAULT NULL,
  `usuario` VARCHAR(255) DEFAULT NULL,
  `fecha` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_historial`),
  KEY `fk_labdochist_doc` (`id_documento`),
  CONSTRAINT `fk_labdochist_doc` FOREIGN KEY (`id_documento`) REFERENCES `lab_documento` (`id_documento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- lab_firma_documento
-- Firma electronica de un documento (informe). Reutiliza el patron de
-- `solicitud.firma_ingreso` / `solicitud.firma_recibe`: LONGTEXT con un
-- data URI "data:image/png;base64,...", validado con la misma regla que
-- normalizarFirmaSolicitud() en includes/solicitud_formulario_helpers.php.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `lab_firma_documento`;
CREATE TABLE `lab_firma_documento` (
  `id_firma` INT NOT NULL AUTO_INCREMENT,
  `id_documento` INT NOT NULL,
  `rol_firma` VARCHAR(60) NOT NULL COMMENT 'analista|revisor|jefe_laboratorio',
  `firmante_id` INT DEFAULT NULL,
  `firmante_nombre` VARCHAR(255) DEFAULT NULL,
  `firmante_correo` VARCHAR(255) DEFAULT NULL,
  `firma_png` LONGTEXT DEFAULT NULL,
  `fecha_firma` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_firma`),
  KEY `fk_labfirma_doc` (`id_documento`),
  CONSTRAINT `fk_labfirma_doc` FOREIGN KEY (`id_documento`) REFERENCES `lab_documento` (`id_documento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
