-- Liquidaciones: el Jefe de Transferencia aprueba la liquidación antes de enviarla a Administración.
-- Estados nuevos (liquidaciones.estado es VARCHAR): 'revision' (por aprobar del Jefe) y 'autorizada'
-- (aprobada por el Jefe, lista para enviar). Si el Jefe la devuelve, queda su observación.
-- pago_instructores.php (cengi_pago_asegurar_esquema) crea la columna si falta.

ALTER TABLE liquidaciones ADD COLUMN observacion_jefe VARCHAR(500) NOT NULL DEFAULT '';
