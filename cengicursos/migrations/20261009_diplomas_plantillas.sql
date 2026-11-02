-- Certificacion (diplomas.php, diplomas_api.php, validar_diploma.php, js/diplomas.js):
-- diseño de diploma por actividad y emision masiva con codigo y QR de validacion.
--
-- diploma_plantillas: un diseño por actividad. clave = 'cu:<cursos.id>' o 'ev:<eventos.id>'.
--   datos es el JSON del diseño (estilo, colores, textos con campos {nombre}, {actividad}...,
--   logos y firmas como imagenes data: ya reducidas en el navegador, y el diseño propio
--   "Canva" con su imagen de fondo y campos arrastrables).
-- diplomas: se agregan datos "congelados" al emitir (documento, actividad, nombre) para
--   validar y volver a dibujar el diploma aunque despues cambie el curso, y la fecha de
--   envio por correo. pdf_path sigue sirviendo para los PDF cargados manualmente.
--
-- Es idempotente. Espejo en deploy/mysql/init/25-cengicursos-diplomas-plantillas.sql.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS diploma_plantillas (
  clave VARCHAR(30) NOT NULL,
  datos MEDIUMTEXT NOT NULL,
  actualizado_por VARCHAR(255) NOT NULL DEFAULT '',
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_dip_doc := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diplomas' AND COLUMN_NAME = 'documento'
);
SET @ddl_dip_doc := IF(
  @col_dip_doc = 0,
  'ALTER TABLE diplomas
     ADD COLUMN plantilla_clave VARCHAR(30) NULL,
     ADD COLUMN documento VARCHAR(60) NOT NULL DEFAULT \'\',
     ADD COLUMN actividad VARCHAR(255) NOT NULL DEFAULT \'\',
     ADD COLUMN nombre_participante VARCHAR(255) NOT NULL DEFAULT \'\',
     ADD COLUMN enviado_en DATETIME NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_dip_doc; EXECUTE stmt; DEALLOCATE PREPARE stmt;
