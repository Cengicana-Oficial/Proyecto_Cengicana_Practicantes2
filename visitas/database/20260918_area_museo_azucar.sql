-- Agrega el Museo del Azúcar como área seleccionable en las visitas a Cengicaña.
-- Puede ejecutarse más de una vez sin crear registros duplicados.
UPDATE areas_interes
SET estado = 1,
    correo_area = CASE
        WHEN TRIM(correo_area) = '' THEN 'scapacitacion@cengicana.org'
        ELSE correo_area
    END
WHERE LOWER(TRIM(nombre_area)) = LOWER('Museo del Azúcar');

INSERT INTO areas_interes (nombre_area, estado, correo_area)
SELECT 'Museo del Azúcar', 1, 'scapacitacion@cengicana.org'
WHERE NOT EXISTS (
    SELECT 1
    FROM areas_interes
    WHERE LOWER(TRIM(nombre_area)) = LOWER('Museo del Azúcar')
);
