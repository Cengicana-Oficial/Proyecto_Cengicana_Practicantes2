# Aula integrada de Cengicursos

El estudiante utiliza su cuenta central y ve únicamente cursos asignados a ella. No requiere Moodle ni otro acceso.

## Preparar el curso

1. Crear curso, módulos e instructores con las herramientas existentes. Programar las sesiones en **Programa de sesiones**.
2. Abrir **Gestionar aula** desde Cursos o Seguimiento. La ruta muestra las sesiones reales y sus instructores.
3. En cada sesión, **Agregar grabación / material** permite publicar Vimeo, enlaces externos, PDF (5 MB) o MP4 (15 MB). Para videos mayores, utilizar Vimeo. También se admite material general del curso.
4. Crear preguntas en el banco. Al crear una evaluación, elegir sesión, tipo (evaluación/tarea/actividad), módulo, destino Pre/Pos, peso y orden. En cursos con módulos, la nota se vincula al módulo correspondiente. Al seleccionar sesión puede tomarse su módulo automáticamente por el nombre almacenado en Programa de sesiones.
5. Configurar apertura, cierre, duración e intentos. Habilitar la actividad cuando esté lista. Revisar preguntas abiertas/tareas y publicar resultados.
6. Emitir diplomas desde **Certificación**. Aparecen automáticamente en el aula, resumen y tarjeta del curso.

## Asistencia por sesión

Se habilita desde la hora de inicio hasta antes de la hora de fin en Guatemala, sin ventanas manuales. La reprogramación utiliza el nuevo horario. Coordinación puede bloquear el registro o exigir código opcional; sin código el estudiante confirma directamente su asistencia.

El servidor valida asignación, horario y sesión no cancelada. Cada registro alimenta `control_cursos.sesiones_asistidas` y `control_cursos.asistencia`: sesiones con registro válido / total programado no cancelado × 100. Es un porcentaje acumulado de todas las sesiones del curso, incluidas las futuras.

Los registros personales normales fuera del horario nuevo no cuentan después de reprogramar; se puede volver a confirmar durante la nueva sesión. Coordinación puede validar un registro tardío o una corrección explícita. Al cancelar se ajusta el denominador. También se recalcula al cambiar la programación y consultar aula/seguimiento. Las cuentas sin registros del aula conservan su asistencia histórica importada; al empezar a registrar en el aula, ese detalle pasa a alimentar su resumen.

Las sesiones con asistencia, recursos o actividades no se eliminan: se cancelan para conservar historial. La eliminación masiva conserva esas sesiones. Confirmar asistencia no demuestra permanencia durante toda la clase.

## Notas integradas

Selección múltiple se autocalifica. Preguntas abiertas y tareas requieren revisión docente con puntos 0–1 por pregunta y retroalimentación. Todas las preguntas de una evaluación tienen igual peso; su orden se mezcla al iniciar. Una revisión pendiente conserva nota NULL.

Al publicar resultados con **Alimentar Seguimiento** activo, se usa el mejor intento calificado por actividad y el promedio ponderado por los pesos de las actividades publicadas con nota. Las actividades pendientes de revisión todavía no contribuyen al promedio. El destino es Pre/Pos del participante en el curso o módulo seleccionado. La nota final por módulos reutiliza `cengi_recalcular_resumen_curso` y su ponderación por horas.

Se conserva el valor previo del campo administrado por el aula. Al retirar publicación/relación se restaura ese valor y se recalcula el resumen por módulos. Los diplomas y la asistencia no cambian por sincronizar notas.

**Evaluaciones anteriores:** conservan desactivada la sincronización para no asignarlas a un módulo incorrecto. Abrir su configuración, elegir sesión/módulo, destino y peso y activar **Alimentar Seguimiento**. Las notas del aula se corrigen desde intentos/configuración: un cambio manual en Seguimiento se recalcula al sincronizar.

## Portal del estudiante

- Mis cursos: tarjetas con estado, fechas, asistencia, nota, tareas pendientes, próxima sesión y diploma disponible; búsqueda y filtro por estado.
- Resumen: progreso, próximas sesiones, entregas pendientes y diploma emitido.
- Sesiones y grabaciones: orden cronológico, recursos, videos, actividades y docente real de cada sesión.
- Asistencia: registro dentro del horario programado.
- Evaluaciones / Tareas y actividades: separación por tipo; orden por sesión y orden docente. Estados de pendiente, entregada, por revisar y vencida. Una tarea cerrada por tiempo sin envío explícito no se marca completada. Para entregar preguntas de tarea se exige texto o PDF.
- Evaluar al instructor: enlaces oficiales de `enlaces_evaluacion_instructor` por instructor de sesión/módulo; se conserva el formulario y sus informes existentes. En el aula se registra una sola respuesta por estudiante, instructor y curso. La apertura es automática al terminar su última sesión o se programa desde Gestión del aula. Se reutiliza el formulario y sus informes; los enlaces de cursos con este control requieren iniciar sesión y respetan la apertura/cierre y el límite de un envío.
- Mis notas / Mis diplomas: los mismos registros del seguimiento y certificación.

Los intentos guardan copia de preguntas y claves, con guardado manual y automático cada 30 segundos. Al vencer se conservan respuestas previamente guardadas. Los cierres se procesan al abrir curso, tarjetas, gestión o guardar, sin otro proceso en segundo plano.

Los archivos PDF/MP4 se almacenan en BD y se sirven mediante autorización; MP4 admite solicitudes de rango para reproducción. No se aceptan HTML, ejecutables, Word o ZIP.

## Cuentas, comunicaciones y activación

Crear cuentas centrales con correo accesible, acceso a Cursos y rol Estudiante/Alumno/Participante. Administración las vincula con una asignación existente desde Gestión del aula. No se reemplazan identidades vinculadas automáticamente.

Recuperación de contraseña y recordatorios utilizan `login/forgot_password.php`, Comunicaciones y `cron_comunicaciones.php`. Verificar SMTP, reglas y cron. El desarrollo no envía correos ni cambia contraseñas.

Aplicar en orden `20261018_aula_estudiantes.sql` y `20261019_aula_integracion.sql`. Aplicar también `20261020_aula_control.sql` para Teams, encuesta única, correcciones y horario especial. Son idempotentes; copias para instalaciones nuevas en `deploy/mysql/init/32-*`, `33-*` y `34-*`. Actualizar código; el despliegue definitivo debe reconstruir la imagen Docker. La actualización se realiza en el entorno Docker local, sin publicar en servidor remoto.

## Validación

Ejecutar `C:/xampp/php/php.exe cengicursos/tests/aula_test.php --mysql`. Utiliza una base aislada `codex_aula_test_*` en MySQL local (puerto 33063) y la elimina al finalizar. Comprueba migraciones repetibles, propiedad, horarios, asistencia oficial, reprogramación/cancelación, intentos, snapshots, pesos, cálculo por horas, restauración de notas, instructor, tareas y diplomas. Renderiza las nueve secciones y gestión con datos ficticios. `--preview` genera ejemplos HTML en `output/`.

Pendiente de comprobar con servicios/cuentas reales: privacidad de Vimeo, correo y cargas de archivos por HTTP. El navegador de automatización no está disponible en esta sesión. No se implementó autocorrección de redacciones con IA ni importación de bancos Moodle.
## Teams, asistencia y vista previa

- **Gestión del aula → Enlace de Teams:** guardar enlace general del curso o uno especial para una sesión. La sesión usa primero su enlace especial, luego un URL válido en Lugar/enlace de Programa de sesiones y finalmente el enlace general del curso.
- **Evaluación de cada instructor:** una tarjeta por instructor, no una por sesión. Por defecto abre al finalizar su última sesión activa (incluye el cambio de fecha si se reprograma); también admite apertura personalizada, cierre opcional y habilitación. El control autentica al estudiante y usa una clave única por asignación/instructor dentro de la misma transacción que guarda la encuesta, de modo que un doble envío no genera otra respuesta. Las encuestas históricas externas sin configuración de aula conservan su flujo original.
- **Corregir asistencia:** seleccionar participante y sesión. Se carga su registro actual si existe; corregir fecha/hora, estado Presente/Ausente y motivo. El registro se valida por coordinación, actualiza Seguimiento y conserva antes/después, responsable y fecha. El estudiante no puede sustituir una corrección administrativa.
- **Asistencia → Usar horario especial:** abrir/cerrar el registro para una sesión pasada o de hoy; permite a los estudiantes confirmar fuera del horario original. Los registros aceptados en esa ventana conservan su validación después de cerrarla. No se permite confirmar sesiones futuras.
- **Sistema → Aulas y estudiantes:** consultar cursos, avances por estudiante, tareas entregadas, asistencia, notas y encuestas respondidas. Abrir seguimiento individual o Gestionar aula para corregir.
- **Ver como estudiante:** vista previa de sus cursos y secciones dentro del alcance del docente/coordinador. La sesión real y sus permisos no se cambian. Los formularios están deshabilitados, cualquier POST se rechaza y las consultas de vista previa no cierran intentos ni sincronizan/modifican registros. Se identifica claramente como vista previa. Los videos y materiales sí pueden consultarse con los permisos de coordinación.
- **Videos:** acepta enlace Vimeo o código iframe de Vimeo (se extrae y normaliza solo el URL del reproductor). Nunca se publica el HTML pegado. También reproduce MP4 guardados, con controles y solicitudes de rango.

Las pruebas adicionales verifican una sola encuesta para cinco sesiones, bloqueo del segundo envío, apertura programada, enlace Teams general/específico, correcciones auditadas, horario tardío, conservación del registro al cerrar la ventana y vista previa sin cambios de asistencia/intentos.