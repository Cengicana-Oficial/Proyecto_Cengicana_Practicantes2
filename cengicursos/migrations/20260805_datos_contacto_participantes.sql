ALTER TABLE participantes
  ADD COLUMN correo_participantes VARCHAR(255) NOT NULL DEFAULT '' AFTER area_participantes,
  ADD COLUMN grado_academico_participantes VARCHAR(255) NOT NULL DEFAULT '' AFTER correo_participantes,
  ADD COLUMN telefono_participantes VARCHAR(50) NOT NULL DEFAULT '' AFTER grado_academico_participantes;

ALTER TABLE solicitudes_inscripcion
  ADD COLUMN grado_academico VARCHAR(255) NOT NULL DEFAULT '' AFTER area_participante;
