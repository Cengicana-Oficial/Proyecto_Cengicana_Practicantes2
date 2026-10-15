CREATE DATABASE IF NOT EXISTS servicios_tecnicos
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE servicios_tecnicos;

CREATE TABLE IF NOT EXISTS Encuestas (
  ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ID_Servicio_Tecnico VARCHAR(20) NULL,
  ID_Digiforms VARCHAR(100) NULL,
  Area VARCHAR(100) NOT NULL,
  Asesor VARCHAR(100) NOT NULL,
  Institucion VARCHAR(150) NOT NULL,
  Nombre_Cliente VARCHAR(150) NOT NULL,
  Fecha_Solicitud DATE NOT NULL,
  Fecha_Cierre DATE NULL,
  Tipo_Servicio VARCHAR(100) NOT NULL,
  Descripcion_Actividad TEXT NOT NULL,
  Medio_Respuesta VARCHAR(100) NOT NULL DEFAULT '',
  Email_Profesional VARCHAR(150) NOT NULL DEFAULT '',
  Email_Cliente VARCHAR(150) NOT NULL DEFAULT '',
  Celular_Cliente VARCHAR(50) NOT NULL DEFAULT '',
  Estado VARCHAR(20) NOT NULL DEFAULT 'Abierto',
  Evidencia VARCHAR(255) NULL,
  Envio_Encuesta VARCHAR(2) NOT NULL DEFAULT 'No',
  Transferencia VARCHAR(2) NOT NULL DEFAULT 'No',
  Fecha2 DATE NULL,
  Lugar VARCHAR(150) NOT NULL DEFAULT '',
  Numero_Participantes INT UNSIGNED NOT NULL DEFAULT 0,
  descripcion_transferencia VARCHAR(255) NOT NULL DEFAULT '',
  Lugar_transferencia VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (ID),
  UNIQUE KEY uq_encuesta_servicio (ID_Servicio_Tecnico),
  KEY ix_encuesta_area_fecha (Area, Fecha_Solicitud),
  KEY ix_encuesta_estado_fecha (Estado, Fecha_Solicitud),
  CONSTRAINT chk_encuesta_estado CHECK (Estado IN ('Abierto', 'Finalizado')),
  CONSTRAINT chk_encuesta_envio CHECK (Envio_Encuesta IN ('Si', 'No')),
  CONSTRAINT chk_encuesta_transferencia CHECK (Transferencia IN ('Si', 'No')),
  CONSTRAINT chk_encuesta_fechas CHECK (Fecha_Cierre IS NULL OR Fecha_Cierre >= Fecha_Solicitud)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS Respuestas (
  ID_Respuesta BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ID_Servicio_Tecnico VARCHAR(20) NOT NULL,
  Puntuacion TINYINT UNSIGNED NOT NULL,
  Comentario TEXT NOT NULL,
  Persona_Respondio VARCHAR(150) NOT NULL,
  Fecha_Respuesta DATE NOT NULL,
  PRIMARY KEY (ID_Respuesta),
  UNIQUE KEY uq_respuesta_servicio (ID_Servicio_Tecnico),
  CONSTRAINT fk_respuesta_servicio FOREIGN KEY (ID_Servicio_Tecnico)
    REFERENCES Encuestas (ID_Servicio_Tecnico) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_respuesta_puntuacion CHECK (Puntuacion BETWEEN 1 AND 5)
) ENGINE=InnoDB;
