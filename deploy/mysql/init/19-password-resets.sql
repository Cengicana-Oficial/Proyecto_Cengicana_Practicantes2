USE usuarios_menu;

-- Flujo "olvide mi contrasena" (login/forgot_password.php + login/reset_password.php).
-- Tabla separada (en vez de columnas nuevas en `usuarios`) para poder guardar
-- varias solicitudes/expirar/auditar sin ensuciar la tabla de usuarios con
-- columnas nulleables la mayor parte del tiempo. El token en si NUNCA se
-- guarda en texto plano: solo se persiste token_hash = SHA-256(token), el
-- mismo token crudo solo viaja una vez, por correo, en el enlace de reseteo
-- (ver login/config/password_reset.php). Un solo uso: usado_en se marca al
-- consumir el token y las lecturas posteriores lo tratan como invalido.
CREATE TABLE IF NOT EXISTS password_resets (
  id int NOT NULL AUTO_INCREMENT,
  usuario_id int NOT NULL,
  token_hash char(64) NOT NULL,
  expira_en datetime NOT NULL,
  usado_en datetime DEFAULT NULL,
  ip_solicitud varchar(64) DEFAULT NULL,
  creado_en timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY token_hash (token_hash),
  KEY usuario_id (usuario_id),
  KEY idx_password_resets_expira (expira_en),
  CONSTRAINT fk_password_resets_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
