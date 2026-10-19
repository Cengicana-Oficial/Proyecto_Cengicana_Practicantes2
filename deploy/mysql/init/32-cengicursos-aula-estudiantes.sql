-- Aula propia. Aplicar en cengi_cursos antes de utilizar Evaluaciones/Asistencia.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS aula_preguntas (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 curso_id INT UNSIGNED NOT NULL,
 tipo ENUM('seleccion','abierta','tarea') NOT NULL,
 tema VARCHAR(160) NOT NULL DEFAULT '',
 enunciado TEXT NOT NULL,
 opciones TEXT NULL,
 correcta SMALLINT NULL,
 criterio TEXT NOT NULL,
 creado_por INT NOT NULL,
 creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aula_evaluaciones (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 curso_id INT UNSIGNED NOT NULL,
 titulo VARCHAR(180) NOT NULL,
 habilitada TINYINT NOT NULL DEFAULT 0,
 abre DATETIME NOT NULL,
 cierra DATETIME NOT NULL,
 minutos SMALLINT UNSIGNED NOT NULL DEFAULT 60,
 intentos TINYINT UNSIGNED NOT NULL DEFAULT 1,
 publicar TINYINT NOT NULL DEFAULT 0,
 creado_por INT NOT NULL,
 FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aula_evaluacion_preguntas (
 evaluacion_id INT UNSIGNED NOT NULL,
 pregunta_id INT UNSIGNED NOT NULL,
 PRIMARY KEY(evaluacion_id,pregunta_id),
 FOREIGN KEY (evaluacion_id) REFERENCES aula_evaluaciones(id) ON DELETE CASCADE,
 FOREIGN KEY (pregunta_id) REFERENCES aula_preguntas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aula_intentos (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 evaluacion_id INT UNSIGNED NOT NULL,
 asignacion_id INT UNSIGNED NOT NULL,
 numero TINYINT UNSIGNED NOT NULL,
 preguntas MEDIUMTEXT NOT NULL,
 respuestas MEDIUMTEXT NOT NULL,
 revision MEDIUMTEXT NULL,
 inicio DATETIME NOT NULL,
 vence DATETIME NOT NULL,
 entregado DATETIME NULL,
 nota DECIMAL(5,2) NULL,
 revisado_por INT NULL,
 revisado_en DATETIME NULL,
 UNIQUE KEY intento_unico(evaluacion_id,asignacion_id,numero),
 FOREIGN KEY (evaluacion_id) REFERENCES aula_evaluaciones(id),
 FOREIGN KEY (asignacion_id) REFERENCES asignaciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aula_archivos (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 curso_id INT UNSIGNED NOT NULL,
 intento_id INT UNSIGNED NULL,
 pregunta_id INT UNSIGNED NULL,
 titulo VARCHAR(180) NOT NULL,
 nombre VARCHAR(180) NOT NULL,
 mime VARCHAR(100) NOT NULL,
 contenido MEDIUMBLOB NOT NULL,
 creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY entrega_archivo(intento_id,pregunta_id),
 FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
 FOREIGN KEY (intento_id) REFERENCES aula_intentos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aula_asistencia_ventanas (
 sesion_id INT UNSIGNED PRIMARY KEY,
 abre DATETIME NOT NULL,
 cierra DATETIME NOT NULL,
 codigo_hash VARCHAR(255) NOT NULL,
 habilitada TINYINT NOT NULL DEFAULT 1,
 creado_por INT NOT NULL,
 FOREIGN KEY (sesion_id) REFERENCES curso_sesiones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aula_asistencias (
 sesion_id INT UNSIGNED NOT NULL,
 asignacion_id INT UNSIGNED NOT NULL,
 registrado DATETIME NOT NULL,
 PRIMARY KEY(sesion_id,asignacion_id),
 FOREIGN KEY (sesion_id) REFERENCES curso_sesiones(id),
 FOREIGN KEY (asignacion_id) REFERENCES asignaciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
