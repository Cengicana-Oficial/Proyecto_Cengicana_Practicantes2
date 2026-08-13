-- El esquema+datos de prueba de laboratorios_prueba se monta aparte, directo
-- desde laboratorio/database/laboratorios_prueba.sql (ver docker-compose.prod.yml,
-- servicio mysql, "11-laboratorio-schema.sql") en vez de duplicarse aqui: ese
-- archivo ya trae su propio CREATE DATABASE + USE + schema completo.
--
-- Este archivo solo hace lo que ese dump no puede hacer por si solo: dar
-- permisos al usuario compartido de la app sobre esa base nueva, y registrar
-- el modulo "Laboratorio" en usuarios_menu.modulos (mismo patron ya usado por
-- login/Menu.php para "Solicitudes internas": INSERT solo si no existe) para
-- que aparezca en el menu principal tras iniciar sesion.
--
-- Orden de ejecucion (docker-entrypoint-initdb.d corre los .sql en orden por
-- nombre de archivo): 01-usuarios-menu.sql ya creo la tabla modulos antes de
-- que este archivo (12-...) se ejecute.

GRANT ALL PRIVILEGES ON laboratorios_prueba.* TO 'modulos_user'@'%';
FLUSH PRIVILEGES;

INSERT IGNORE INTO usuarios_menu.modulos (nombre) VALUES ('Laboratorio');
