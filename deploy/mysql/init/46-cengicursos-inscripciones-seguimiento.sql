USE cengi_cursos;
SET NAMES utf8mb4;
SET @exists_origin=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='solicitudes_inscripcion' AND COLUMN_NAME='origen_inscripcion');
SET @origin_ddl=IF(@exists_origin=0,"ALTER TABLE solicitudes_inscripcion ADD origen_inscripcion VARCHAR(30) NOT NULL DEFAULT 'sin_identificar'","SELECT 1");
PREPARE origin_stmt FROM @origin_ddl;EXECUTE origin_stmt;DEALLOCATE PREPARE origin_stmt;
-- Recupera las cargas antiguas que omitieron la convocatoria. No cambia validaciones ni pagos.
UPDATE solicitudes_inscripcion s JOIN planificacion_cursos p ON p.curso_id=s.curso_id
SET s.planificacion_id=p.id,
 s.cuota=COALESCE(s.cuota,'asociado'),
 s.monto=COALESCE(s.monto,CASE WHEN s.cuota='externo' THEN p.costo_externos WHEN s.cuota='extranjero' THEN p.costo_extranjero ELSE p.costo_asociados END),
 s.pago_estado=COALESCE(s.pago_estado,'pendiente'),
 s.validacion_ingenio=IF(s.tipo_pago='Ingenio',COALESCE(s.validacion_ingenio,'pendiente'),s.validacion_ingenio)
WHERE s.planificacion_id IS NULL;
INSERT INTO pagos_grupales(planificacion_id,ingenio_id)
SELECT DISTINCT s.planificacion_id,s.ingenio_id FROM solicitudes_inscripcion s
WHERE s.planificacion_id IS NOT NULL AND s.tipo_pago='Ingenio' AND COALESCE(s.pago_estado,'pendiente')<>'pagado' AND s.grupo_pago_id IS NULL AND s.ingenio_id IS NOT NULL
AND NOT EXISTS(SELECT 1 FROM pagos_grupales g WHERE g.planificacion_id=s.planificacion_id AND g.ingenio_id=s.ingenio_id AND g.estado<>'pagado' AND g.proforma_id IS NULL);
UPDATE solicitudes_inscripcion s SET s.grupo_pago_id=(SELECT MAX(g.id) FROM pagos_grupales g WHERE g.planificacion_id=s.planificacion_id AND g.ingenio_id=s.ingenio_id AND g.estado<>'pagado' AND g.proforma_id IS NULL)
WHERE s.planificacion_id IS NOT NULL AND s.tipo_pago='Ingenio' AND COALESCE(s.pago_estado,'pendiente')<>'pagado' AND s.grupo_pago_id IS NULL;
UPDATE pagos_grupales g SET monto=(SELECT COALESCE(SUM(s.monto),0) FROM solicitudes_inscripcion s WHERE s.grupo_pago_id=g.id AND s.estado<>'Rechazado') WHERE g.proforma_id IS NULL AND g.estado<>'pagado';
