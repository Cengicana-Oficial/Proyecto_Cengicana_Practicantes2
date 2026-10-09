USE cengi_cursos;

-- Espejo de cengicursos/migrations/20261009_diplomas_plantillas.sql (ver comentarios alli).

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
