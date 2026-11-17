# Servicios técnicos · Cengicaña

Rediseño del sistema PHP a partir del HTML proporcionado. Conserva las tablas MySQL `Encuestas` y `Respuestas`; no reemplaza ni carga datos de ejemplo en la base existente.

## Iniciar

1. Iniciar sesión en Módulos: `http://127.0.0.1:8085/login/login.php`.
2. Entrar desde **Servicios Técnicos** o abrir `http://127.0.0.1:8085/Pruebas/index.php`.
3. El superadmin de Módulos conserva acceso completo. En **Usuarios y Permisos**, un Admin puede asignar a cada cuenta central el perfil **Admin** o **Profesional**, seleccionar su área y guardar. **Sin acceso** retira el acceso a este módulo sin borrar la cuenta central.

Se reutiliza la sesión central (`id_usuario`) y se verifica la cuenta y su asignación en cada solicitud protegida. Admin accede a todas las áreas, responde encuestas, edita servicios y gestiona accesos. Profesional consulta y crea únicamente en su área; sus contactos, estadísticas y exportaciones también se limitan a esa área. No puede responder encuestas, editar servicios existentes ni administrar accesos. Las asignaciones se guardan en `user_access` dentro del SQLite privado; los cambios aplican en la siguiente solicitud. El superadmin y el acceso propio no pueden modificarse desde el panel.

El menú central consulta `module_access.php` para mostrar el acceso a los usuarios asignados. **Menú principal** conserva la sesión; **Cerrar sesión** cierra la sesión compartida. Los clientes externos pueden seguir respondiendo mediante sus enlaces públicos con token; una sesión de Profesional no puede responder por esa vía.

La configuración Docker local está documentada en [.docker/README.md](.docker/README.md).

Requiere PHP 8.1+ con PDO MySQL, PDO SQLite, mbstring y fileinfo. Chart.js 4.4.0 y jsPDF 2.5.1 se incluyen en `assets/vendor`; la interfaz no depende de CDNs.

## Funciones

- Registro, búsqueda, filtros, detalle y edición de servicios.
- Conservación de fecha de cierre, participantes, contacto y datos de transferencia.
- Evidencia JPG, PNG, WebP o PDF de hasta 10 MB, descargable con sesión y acceso al área.
- Enlaces individuales con token para respuestas públicas y registro de respuestas telefónicas. Se valida la calificación y se impide responder dos veces, incluso con solicitudes concurrentes.
- Seguimiento de calificaciones, notas, clasificación e historial persistente.
- Directorio consolidado de asesores, clientes y destinos de transferencia.
- Estadísticas por institución y mes, gráficos, reportes CSV y PDF.
- Usuarios, permisos y restricciones de área comprobados en el servidor.

Los botones de correo abren un borrador en la aplicación de correo del usuario; no confirman ni registran un envío automático. El sistema externo del prototipo era simulado: los contactos se exportan como CSV para su importación. No se simula una sincronización con un CRM sin configurar.

## Persistencia y respaldos

`Conexion.php` utiliza la base MySQL local `servicios_tecnicos`, con tablas `Encuestas` y `Respuestas`. Prepararla con `.docker/init-db.ps1`; la configuración privada está en `.docker/private/database.json`. Para otro entorno se pueden establecer `TECH_DB_DSN`, `TECH_DB_USER` y `TECH_DB_PASSWORD`. La base local empieza vacía: no se importaron registros del antiguo servidor Aiven.

Las cuentas y contraseñas pertenecen a Módulos y se consultan mediante su conexión central. Los permisos del módulo, tokens e historial se guardan en SQLite. En Docker, `TECH_STORAGE_DIR` apunta a `/var/lib/servicios-tecnicos`, respaldado por `.docker/private` y protegido del acceso HTTP. Respaldar esa carpeta junto con MySQL y `uploads/`. Si se mueve el proyecto, conservar ese almacenamiento para mantener los enlaces e historial.

Las páginas PHP anteriores redirigen a la interfaz unificada. Para compartir encuestas se deben generar los nuevos enlaces desde el detalle; contienen un token y apuntan a `encuesta.php`. En una instalación local, los destinatarios necesitan acceso a la dirección donde esté alojado el sistema. Para uso externo, utilizar el dominio HTTPS de la instalación al generar el enlace.

Los registros históricos no tienen eventos inventados; el historial empieza a registrar acciones desde esta actualización.

## Pruebas

Ejecutar `powershell -ExecutionPolicy Bypass -File tests/run.ps1` desde `Pruebas`. Crea bases, sesiones y una copia de prueba aisladas en `tests/.runtime`, inicia un servidor local de prueba y lo detiene al finalizar. No escribe en MySQL ni en el almacén real de usuarios. Las pruebas cubren la sesión central, superadmin, usuarios sin acceso, revocación, cuentas eliminadas, cierre compartido, rechazo de sesiones locales antiguas, CSRF, encuestas públicas, respuestas duplicadas, seguimiento, registro/edición y evidencias.

Se verificaron también las vistas de listado, detalle, formulario, contactos, estadísticas y usuarios en el navegador. Los archivos y proyectos ajenos al sistema (por ejemplo `demo-salas` y los listados de asistencia) se conservan.
