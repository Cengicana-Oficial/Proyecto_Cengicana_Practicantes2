-- Roles por modulo (ver login/config/roles_modulo.php). Base: usuarios_menu.
--
--  1. usuario_modulo.rol_id : rol del usuario en cada modulo.
--  2. rol_modulo            : modulos a los que pertenece cada rol (un rol puede servir en
--                             varios, p. ej. Director en Comites y Solicitudes internas).
--                             Un rol sin filas es generico (Superadmin, Administrador).
--  3. Se clasifican los roles existentes y se copia el rol actual de cada usuario SOLO a los
--     modulos donde ese rol aplica. Si su rol actual es de otro modulo (p. ej. "Gestor" de
--     Cursos en Laboratorio), esa fila queda sin rol para asignarlo en Usuarios: asi se
--     elimina el cruce de roles y permisos.
--
-- Idempotente: se puede ejecutar mas de una vez.

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuario_modulo' AND COLUMN_NAME = 'rol_id');
SET @ddl := IF(@col = 0,
  'ALTER TABLE usuario_modulo ADD COLUMN rol_id INT NULL, ADD KEY idx_usuario_modulo_rol (rol_id),
     ADD CONSTRAINT fk_usuario_modulo_rol FOREIGN KEY (rol_id) REFERENCES roles (id)',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS rol_modulo (
  rol_id INT NOT NULL,
  modulo_id INT NOT NULL,
  PRIMARY KEY (rol_id, modulo_id),
  KEY idx_rol_modulo_modulo (modulo_id),
  CONSTRAINT fk_rol_modulo_rol FOREIGN KEY (rol_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_rol_modulo_modulo FOREIGN KEY (modulo_id) REFERENCES modulos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Version previa de esta migracion usaba roles.modulo_id: se pasa a rol_modulo y se quita.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'modulo_id');
SET @ddl := IF(@col = 1, 'INSERT IGNORE INTO rol_modulo (rol_id, modulo_id) SELECT id, modulo_id FROM roles WHERE modulo_id IS NOT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF(@col = 1, 'ALTER TABLE roles DROP FOREIGN KEY fk_roles_modulo', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF(@col = 1, 'ALTER TABLE roles DROP COLUMN modulo_id', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Clasificacion de los roles existentes (solo agrega; no quita lo que ya se configuro).
INSERT IGNORE INTO rol_modulo (rol_id, modulo_id)
  SELECT r.id, m.id FROM roles r JOIN modulos m ON LOWER(m.nombre) = 'cursos'
  WHERE r.nombre_rol IN ('Instructor', 'Gestor', 'Estudiante', 'Administrador de ingenio');

INSERT IGNORE INTO rol_modulo (rol_id, modulo_id)
  SELECT r.id, m.id FROM roles r JOIN modulos m ON LOWER(m.nombre) = 'laboratorio'
  WHERE r.nombre_rol IN ('Analista', 'Recepcionista', 'Jefe de laboratorio') OR r.nombre_rol LIKE 'T%cnico';

-- Los roles de programa sirven en Comites y en Solicitudes internas (comparten los programas).
INSERT IGNORE INTO rol_modulo (rol_id, modulo_id)
  SELECT r.id, m.id FROM roles r JOIN modulos m ON LOWER(m.nombre) LIKE 'comites%' OR LOWER(m.nombre) = 'solicitudes internas'
  WHERE r.nombre_rol IN ('Jefe de programa', 'Colaborador de programa', 'Director');

-- Rol de cada usuario en cada modulo: el actual, solo donde aplica (generico o del modulo).
UPDATE usuario_modulo um
  JOIN usuarios u ON u.id = um.usuario_id
  SET um.rol_id = u.rol_id
  WHERE um.rol_id IS NULL
    AND (NOT EXISTS (SELECT 1 FROM rol_modulo rm WHERE rm.rol_id = u.rol_id)
         OR EXISTS (SELECT 1 FROM rol_modulo rm WHERE rm.rol_id = u.rol_id AND rm.modulo_id = um.modulo_id));
