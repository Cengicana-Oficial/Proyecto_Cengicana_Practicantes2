-- Renombra el modulo "SIGEC" a "Experimentos" en el menu principal.
-- Solo cambia el nombre visible: la clave interna sigue siendo 'sigec'
-- (login/config/roles_modulo.php, sso_sigec.php, permisos sigec.*).
-- En una base ya existente ejecutar este UPDATE a mano.

UPDATE usuarios_menu.modulos SET nombre = 'Experimentos' WHERE LOWER(nombre) = 'sigec';
