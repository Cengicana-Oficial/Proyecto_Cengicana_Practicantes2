-- Materiales del curso: seccion "Materiales del curso" en
-- cengicursos/ver_participante_curso.php, con un titulo + link a una carpeta
-- compartida externa (Google Drive, OneDrive, etc.) por item. Igual que
-- curso_modulos, curso_id referencia la edicion especifica del curso (cursos.id),
-- no se replica manualmente entre "hermanos" del mismo nombre_cursos.
--
-- creado_por/creado_por_nombre son un snapshot (no hay FK cruzada posible: los
-- usuarios viven en la base de datos "usuarios_menu", separada de "cengi_cursos" --
-- mismo patron que columnas snapshot ya existentes en otras tablas de este modulo).
--
-- curso_id usa ON DELETE CASCADE (igual que curso_modulos.curso_id), para no dejar
-- materiales huerfanos si la edicion de curso se elimina.
--
-- Es idempotente (CREATE TABLE IF NOT EXISTS), asi que es segura de aplicar mas de
-- una vez sobre la misma base de datos. Ver tambien la actualizacion correspondiente
-- en deploy/mysql/init/03-cengicursos-modulos.sql para que una instalacion nueva ya
-- incluya el esquema completo desde el CREATE TABLE.
--
-- Como aplicarla manualmente sobre un contenedor MySQL que ya existe (los scripts de
-- deploy/mysql/init solo se ejecutan automaticamente la primera vez que se crea el
-- volumen):
--   docker compose -f docker-compose.prod.yml exec -T mysql \
--     mysql -u root -p cengi_cursos < cengicursos/migrations/20260810_curso_contenidos.sql

CREATE TABLE IF NOT EXISTS curso_contenidos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  curso_id INT UNSIGNED NOT NULL,
  titulo VARCHAR(255) NOT NULL,
  url VARCHAR(500) NOT NULL,
  orden INT UNSIGNED NOT NULL DEFAULT 0,
  creado_por INT NULL,
  creado_por_nombre VARCHAR(255) NOT NULL DEFAULT '',
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_curso_contenidos_curso (curso_id),
  CONSTRAINT fk_curso_contenidos_curso
    FOREIGN KEY (curso_id) REFERENCES cursos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
