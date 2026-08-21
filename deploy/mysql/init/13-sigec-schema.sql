-- A diferencia de laboratorio (PHP plano sin migraciones, ver
-- 11-laboratorio-schema.sql), SIGEC es una app Laravel: su esquema real de
-- tablas lo crean las migraciones (`php artisan migrate`), no un dump SQL.
-- Este archivo solo hace lo que las migraciones no pueden hacer por si
-- solas: crear la base vacia, dar permisos al usuario compartido de la app,
-- y registrar el modulo "SIGEC" en usuarios_menu.modulos (mismo patron ya
-- usado por 12-laboratorio-grants.sql) para que aparezca en el menu
-- principal tras iniciar sesion.
--
-- Orden de ejecucion (docker-entrypoint-initdb.d corre los .sql en orden
-- por nombre de archivo): 01-usuarios-menu.sql ya creo la tabla modulos
-- antes de que este archivo (13-...) se ejecute.

CREATE DATABASE IF NOT EXISTS sigec CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON sigec.* TO 'modulos_user'@'%';
FLUSH PRIVILEGES;

INSERT IGNORE INTO usuarios_menu.modulos (nombre) VALUES ('SIGEC');
