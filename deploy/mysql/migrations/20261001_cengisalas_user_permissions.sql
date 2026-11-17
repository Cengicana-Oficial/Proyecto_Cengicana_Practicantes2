USE usuarios_menu;

CREATE TABLE IF NOT EXISTS cengisalas_permission_overrides (
  usuario_id INT NOT NULL,
  module_code ENUM('salas','visitas') NOT NULL,
  permission_code VARCHAR(80) NOT NULL,
  allowed TINYINT(1) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id,module_code,permission_code),
  CONSTRAINT cengisalas_permission_user_fk FOREIGN KEY (usuario_id)
    REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
