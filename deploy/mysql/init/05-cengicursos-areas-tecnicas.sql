-- Catalogo administrable de areas tecnicas para clasificar los cursos.
-- Es aditivo e idempotente. Los cursos conservan el nombre en area_tecnica
-- para mantener compatibilidad con los reportes y registros existentes.

USE cengi_cursos;

CREATE TABLE IF NOT EXISTS areas_tecnicas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(120) NOT NULL,
  estado TINYINT NOT NULL DEFAULT 1,
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_areas_tecnicas_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO areas_tecnicas (nombre, estado) VALUES
  ('Agronomía', 1),
  ('Riego', 1),
  ('Fitosanidad', 1),
  ('Industrial', 1),
  ('Gestión', 1);

INSERT IGNORE INTO areas_tecnicas (nombre, estado)
SELECT DISTINCT TRIM(area_tecnica), 1
FROM cursos
WHERE area_tecnica IS NOT NULL
  AND TRIM(area_tecnica) <> '';
