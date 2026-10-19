-- Anuncios del aula (Gestion del aula -> Anuncios). Varios por curso; cada uno con mensaje, enlace
-- opcional (p. ej. Teams) y su texto de boton, sesion opcional, fecha de publicacion, visible y fijado.
-- El estudiante los ve en el Resumen (y los ligados a una sesion, tambien dentro de esa sesion).
-- Requiere 20261021_aula_vista_estudiante.sql. Idempotente; espejo en deploy/mysql/init/36-*.
-- aula_vista.php tambien crea la tabla si falta.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS aula_anuncios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  curso_id INT UNSIGNED NOT NULL,
  sesion_id INT UNSIGNED NULL,
  titulo VARCHAR(180) NOT NULL,
  mensaje TEXT NULL,
  enlace_url VARCHAR(2048) NOT NULL DEFAULT '',
  enlace_texto VARCHAR(80) NOT NULL DEFAULT '',
  publicar_desde DATETIME NULL,
  visible TINYINT NOT NULL DEFAULT 1,
  fijado TINYINT NOT NULL DEFAULT 0,
  creado_por INT NULL,
  creado_por_nombre VARCHAR(255) NOT NULL DEFAULT '',
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_aula_anuncios_curso (curso_id),
  FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
  FOREIGN KEY (sesion_id) REFERENCES curso_sesiones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
