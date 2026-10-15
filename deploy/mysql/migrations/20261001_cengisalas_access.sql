USE usuarios_menu;

INSERT INTO modulos(nombre) VALUES ('CengiSalas'), ('Visitas')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

CREATE TABLE IF NOT EXISTS cengisalas_module_access (
  usuario_id INT NOT NULL,
  module_code ENUM('salas','visitas') NOT NULL,
  role_code ENUM('user','payments','admin','manager','asistente_direccion','director_general','coordinador_visitas','gestor_pagos') NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id, module_code),
  CONSTRAINT cengisalas_access_user_fk FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO usuario_modulo(usuario_id, modulo_id)
SELECT u.id, m.id FROM usuarios u JOIN modulos m ON m.nombre IN ('CengiSalas','Visitas')
WHERE u.es_superadmin = 1;
