SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS inscripcion_historial (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 solicitud_id INT UNSIGNED NOT NULL,
 accion VARCHAR(40) NOT NULL,
 usuario VARCHAR(255) NOT NULL,
 motivo VARCHAR(500) NOT NULL,
 antes MEDIUMTEXT NULL,
 despues MEDIUMTEXT NULL,
 creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id), KEY idx_ins_historial(solicitud_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reconstruye registros faltantes, sin alterar acceso, notas, diplomas ni pagos existentes.
-- No afirma que una asignación histórica haya sido pagada o validada.
-- Identificador administrativo correlativo para personas históricas sin CUI (formato existente NNNND).
-- Mismo formato y correlativo que conexion.php (cengi_cui_nd_ultimo_correlativo / cengi_cui_nd_formatear):
-- 4 dígitos + "ND", continuando el mayor ya usado en participantes y solicitudes_inscripcion.
SET @ins_nd = (SELECT COALESCE(MAX(n),0) FROM (
 SELECT CAST(REPLACE(UPPER(cui_participantes),'ND','') AS UNSIGNED) AS n FROM participantes WHERE cui_participantes REGEXP '^[0-9]+ND$'
 UNION ALL SELECT CAST(REPLACE(UPPER(cui_participante),'ND','') AS UNSIGNED) FROM solicitudes_inscripcion WHERE cui_participante REGEXP '^[0-9]+ND$') AS correlativos_nd);
UPDATE participantes pa JOIN (
 SELECT id, CAST(@ins_nd + ROW_NUMBER() OVER (ORDER BY id) AS UNSIGNED) AS n FROM participantes WHERE cui_participantes IS NULL OR TRIM(cui_participantes)=''
) sin_cui ON sin_cui.id = pa.id
SET pa.cui_participantes = CONCAT(LPAD(sin_cui.n, GREATEST(4, CHAR_LENGTH(sin_cui.n)), '0'), 'ND');
INSERT INTO solicitudes_inscripcion
 (nombre_participante,cui_participante,puesto_participante,area_participante,grado_academico,correo,telefono,ingenio_id,curso_id,planificacion_id,tipo_pago,estado,cuota,monto,moneda,pago_estado,validacion_ingenio,asignado_por,asignado_en,como_se_entero)
SELECT pa.nombre_participantes,pa.cui_participantes,pa.puesto_participantes,pa.area_participantes,pa.grado_academico_participantes,pa.correo_participantes,pa.telefono_participantes,pa.ingenio_id,a.cursos_id,p.id,
 'Ingenio',IF(a.estado_asignaciones=1,'Aprobado','Rechazado'),'asociado',p.costo_asociados,'GTQ','pendiente','pendiente','Asignación histórica; cobro por revisar',a.creado,'Conciliación de asignaciones históricas'
FROM asignaciones a JOIN participantes pa ON pa.id=a.participantes_id
LEFT JOIN planificacion_cursos p ON p.id=(SELECT MAX(p2.id) FROM planificacion_cursos p2 WHERE p2.curso_id=a.cursos_id)
WHERE pa.cui_participantes IS NOT NULL AND pa.cui_participantes<>''
 AND NOT EXISTS (SELECT 1 FROM solicitudes_inscripcion s WHERE s.curso_id=a.cursos_id AND (s.cui_participante=pa.cui_participantes OR (pa.correo_participantes<>'' AND s.correo=pa.correo_participantes)));

INSERT INTO pagos_grupales(planificacion_id,ingenio_id)
SELECT DISTINCT s.planificacion_id,s.ingenio_id FROM solicitudes_inscripcion s
WHERE s.planificacion_id IS NOT NULL AND s.tipo_pago='Ingenio' AND s.grupo_pago_id IS NULL AND s.ingenio_id IS NOT NULL
AND NOT EXISTS (SELECT 1 FROM pagos_grupales g WHERE g.planificacion_id=s.planificacion_id AND g.ingenio_id=s.ingenio_id AND g.estado<>'pagado' AND g.proforma_id IS NULL);
UPDATE solicitudes_inscripcion s SET s.grupo_pago_id=(SELECT MAX(g.id) FROM pagos_grupales g WHERE g.planificacion_id=s.planificacion_id AND g.ingenio_id=s.ingenio_id AND g.estado<>'pagado' AND g.proforma_id IS NULL)
WHERE s.planificacion_id IS NOT NULL AND s.tipo_pago='Ingenio' AND s.grupo_pago_id IS NULL;
UPDATE pagos_grupales g SET monto=(SELECT COALESCE(SUM(s.monto),0) FROM solicitudes_inscripcion s WHERE s.grupo_pago_id=g.id AND s.estado<>'Rechazado') WHERE g.proforma_id IS NULL AND g.estado<>'pagado';
