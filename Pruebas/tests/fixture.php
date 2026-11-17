<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$db = new PDO(getenv('TECH_DB_DSN'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE Encuestas (ID INTEGER PRIMARY KEY AUTOINCREMENT, ID_Servicio_Tecnico TEXT UNIQUE, ID_Digiforms TEXT, Area TEXT, Asesor TEXT, Institucion TEXT, Nombre_Cliente TEXT, Fecha_Solicitud TEXT, Fecha_Cierre TEXT, Tipo_Servicio TEXT, Descripcion_Actividad TEXT, Medio_Respuesta TEXT, Email_Profesional TEXT, Email_Cliente TEXT, Celular_Cliente TEXT, Estado TEXT, Evidencia TEXT, Envio_Encuesta TEXT, Transferencia TEXT, Fecha2 TEXT, Lugar TEXT, Numero_Participantes INTEGER, descripcion_transferencia TEXT, Lugar_transferencia TEXT)');
$db->exec('CREATE TABLE Respuestas (ID_Respuesta INTEGER PRIMARY KEY AUTOINCREMENT, ID_Servicio_Tecnico TEXT, Puntuacion INTEGER, Comentario TEXT, Persona_Respondio TEXT, Fecha_Respuesta TEXT)');
$stmt = $db->prepare('INSERT INTO Encuestas (ID_Servicio_Tecnico, Area, Asesor, Institucion, Nombre_Cliente, Fecha_Solicitud, Tipo_Servicio, Descripcion_Actividad, Estado, Envio_Encuesta, Transferencia, Numero_Participantes, Email_Profesional, Email_Cliente, Lugar) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$stmt->execute(['TEST-001','Riegos','Asesor de prueba','Ingenio de prueba','Cliente de prueba','2026-09-01','Asesoría/consultoría','Evaluación técnica de riego.','Abierto','No','No',4,'asesor@example.test','cliente@example.test','Estación experimental']);
$stmt->execute(['TEST-002','Variedades','Otro asesor','Otra institución','Otro cliente','2026-08-05','Capacitación','Taller de variedades.','Finalizado','Si','Si',12,'asesor2@example.test','cliente2@example.test','Campo de prueba']);
$db->exec("INSERT INTO Respuestas (ID_Servicio_Tecnico,Puntuacion,Comentario,Persona_Respondio,Fecha_Respuesta) VALUES ('TEST-002',4,'Buena atención.','Cliente de prueba','2026-08-06')");
echo "Fixture created.\n";
