-- Un solo catálogo: «Ingenios e instituciones» absorbe a «Organizaciones».
-- ingenios gana tipo y datos de contacto; se copian desde organizaciones. La tabla organizaciones
-- se conserva como respaldo (ninguna pantalla la usa ya).
-- Idempotente: los datos se copian solo la vez que se crean las columnas (@c = 0); al repetirla en
-- cada despliegue no pisa el tipo ni el contacto editados en Ingenios (AUD-2026-001/I-2).
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ingenios' AND COLUMN_NAME='tipo');
SET @s = IF(@c=0, 'ALTER TABLE ingenios ADD COLUMN tipo VARCHAR(60) NOT NULL DEFAULT ''Ingenio azucarero'', ADD COLUMN contacto_nombre VARCHAR(255) NOT NULL DEFAULT '''', ADD COLUMN contacto_correo VARCHAR(255) NOT NULL DEFAULT '''', ADD COLUMN contacto_telefono VARCHAR(50) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Organizaciones que no estaban ligadas a un ingenio: pasan al catálogo (si el nombre no existe).
INSERT INTO ingenios (nombre_ingenios, creado)
SELECT o.nombre, NOW() FROM organizaciones o
WHERE @c = 0 AND o.ingenio_id IS NULL AND NOT EXISTS (SELECT 1 FROM ingenios i WHERE LOWER(TRIM(i.nombre_ingenios)) = LOWER(TRIM(o.nombre)));
UPDATE organizaciones o JOIN ingenios i ON LOWER(TRIM(i.nombre_ingenios)) = LOWER(TRIM(o.nombre)) SET o.ingenio_id = i.id WHERE @c = 0 AND o.ingenio_id IS NULL;

-- Tipo y contacto (de la primera organización de cada ingenio).
UPDATE ingenios i
JOIN organizaciones o ON o.id = (SELECT MIN(o2.id) FROM organizaciones o2 WHERE o2.ingenio_id = i.id)
SET i.tipo = IF(o.tipo = 'Institucion tecnica', 'Institución técnica', o.tipo),
    i.contacto_nombre = IF(i.contacto_nombre = '', o.contacto_nombre, i.contacto_nombre),
    i.contacto_correo = IF(i.contacto_correo = '', o.contacto_correo, i.contacto_correo),
    i.contacto_telefono = IF(i.contacto_telefono = '', o.contacto_telefono, i.contacto_telefono)
WHERE @c = 0;
