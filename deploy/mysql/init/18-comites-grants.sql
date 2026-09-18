-- Modulo "Comites, Transferencia y Comunicacion" (carpeta comites/): mismo
-- patron que 12-laboratorio-grants.sql y 17-visitas-schema.sql. El esquema
-- completo (16 tablas) lo crea comites/config/database.php de forma
-- idempotente en el primer request (CREATE TABLE IF NOT EXISTS), igual que
-- sistema_solicitudes. Este archivo solo hace lo que ese bootstrap PHP no
-- puede hacer por si solo: crear la base antes del primer request y dar
-- permisos al usuario compartido de la app sobre ella, y registrar el
-- modulo en usuarios_menu.modulos para que aparezca en el menu principal
-- aunque nadie haya entrado todavia al modulo.
--
-- Orden de ejecucion (docker-entrypoint-initdb.d corre los .sql en orden por
-- nombre de archivo): 01-usuarios-menu.sql ya creo usuarios_menu.modulos
-- antes de que este archivo (18-...) se ejecute.

CREATE DATABASE IF NOT EXISTS sistema_comites CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON sistema_comites.* TO 'modulos_user'@'%';
FLUSH PRIVILEGES;

INSERT IGNORE INTO usuarios_menu.modulos (nombre) VALUES ('Comites, Transferencia y Comunicacion');
