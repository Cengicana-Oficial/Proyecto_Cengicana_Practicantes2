CREATE TABLE IF NOT EXISTS estudiante_aula_bloqueos (
 usuario_id INT NOT NULL,
 curso_id INT NOT NULL,
 actualizado_por INT NOT NULL,
 actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (usuario_id, curso_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;