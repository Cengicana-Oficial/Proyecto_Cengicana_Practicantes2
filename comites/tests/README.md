# Pruebas del catálogo de Comités

Con los servicios locales iniciados:

```powershell
docker compose -f docker-compose.prod.yml exec -T app php /var/www/html/comites/tests/catalogo.php
```

El script solo admite CLI. Usa tablas temporales de MySQL que desaparecen al cerrar la conexión; no inserta datos de prueba en las tablas reales. El bootstrap aplica las mismas ampliaciones aditivas de esquema que la vista Comités.

Cubre creación, edición, líder e integrantes, duplicados por correo y nombre, activación, eliminación protegida por historial e importación CSV/XLSX.

La vista se abre en `/comites/index.php?view=comites`. Reutiliza los permisos `comites.configuracion.ver` y `comites.configuracion.gestionar` existentes. Las operaciones de escritura verifican un token CSRF. Las demás secciones conservan sus rutas y diseño.

## Directorio de contactos (referencia v38)

```powershell
docker compose -f docker-compose.prod.yml exec -T app php /var/www/html/comites/tests/directorio.php
```

Prueba clasificación, conservación del origen al editar, duplicados, selección masiva, segmentos privados, trazabilidad real, importación CSV/XLSX y generación de Excel/PDF. Las tablas de prueba son temporales y no se envían correos. `directorio-ui.js` contiene `probarDirectorio(codigoFuente)` para probar el renderizado, filtros combinados, selección y escape HTML con un DOM mínimo; no sustituye una revisión visual en navegador.

La nueva vista `/comites/index.php?view=contactos` conserva los datos del directorio y añade:

- Área, nivel, origen/detalle/autor/fecha de alta y última interacción.
- Filtros por texto, área, nivel, comité, empresa, origen y estado; mapa de clasificación y segmentos por usuario en MySQL.
- Carga CSV, XLSX y XLS (hasta 2000 filas/5 MB), plantilla XLSX con valores permitidos y exportación de los resultados filtrados.
- Clasificación automática sin sobrescribir áreas/niveles existentes y asignación masiva sobre la selección.
- Compartir selección o resultados: columnas, título, descripción, agrupación, color, logo, vista previa, HTML, Excel, PDF y copiar correos.
- Envío real por SMTP, con PDF adjunto, solo para usuarios con `comites.correos.gestionar`. Requiere configurar `MAIL_*` en `deploy/env/login.env`; no se marca un envío como exitoso cuando faltan credenciales o falla SMTP.
- Historial a partir de asistentes a reuniones y eventos realizados, correos registrados, autoría/revisión de memorias y responsables de acuerdos. Los eventos enlazan por contacto o correo; no se inventan interacciones.

Los registros anteriores se identifican como “Base existente”, conservando su fecha de creación. El origen no cambia al editar. Los permisos de lectura/gestión de contactos siguen siendo los existentes; exportaciones y descargas exigen acceso al directorio, y las escrituras verifican CSRF. Los contactos creados desde Comités también registran su procedencia.

## Reuniones y eventos de transferencia (referencia v38)

```powershell
docker compose -f docker-compose.prod.yml exec -T app php /var/www/html/comites/tests/agenda.php
```

La suite usa tablas temporales y cubre recurrencia con ajuste al fin de mes, duplicados y rollback, permisos del líder, edición del plan, reprogramación con historial, ejecución que conserva el plan, cancelación, acuerdos/asistentes, eventos fijos idempotentes, integración al directorio, trazabilidad, importación y encuestas. `agenda-ui.js` expone `probarAgenda(codigoFuente)` para comprobar ambas vistas con un DOM mínimo; no sustituye una revisión visual en navegador.

| Sección de v38 | Implementación |
| --- | --- |
| Reuniones: planificación anual | Matriz comité × mes, estados, año y comité, recurrencia, edición de temas/responsable, carga CSV/Excel y plantilla |
| Reuniones: ejecución | Reuniones planificadas y extraordinarias, asistentes, nuevos contactos, ayuda memoria y próximos pasos sincronizados con Acuerdos |
| Reuniones: análisis | Cumplimiento exigible, cumplimiento en el mes, reprogramaciones, cancelaciones, atrasos, extraordinarias, comparación mensual y desviación por comité |
| Eventos: planificación | Calendario anual, cuatro eventos fijos por año, adicionales y sobre la marcha; lugar, modalidad, público y objetivo |
| Eventos: ejecución | Fecha real, participantes, ingenios, informe opcional y notas; detalle con resumen, asistentes y evaluación |
| Eventos: asistentes | CSV/Excel, plantilla, enlace CSV público con CORS, deduplicación, revisión e integración/descartado; comité opcional y origen en directorio |
| Eventos: evaluación | Importación CSV/Excel, escala 1–5, promedio, distribución, comentarios y limpieza de respuestas |
| Compartir planificación | Título, descripción, nota, color, logo, vista previa, CSV, HTML, PDF y envío por SMTP configurado |

Rutas: `/comites/index.php?view=reuniones` y `/comites/index.php?view=eventos`. Se añaden permisos `comites.eventos.ver` y `comites.eventos.gestionar`; el superadministrador tiene acceso automáticamente. Los demás roles deben recibir esos permisos mediante la administración existente. El líder identificado por su correo en el directorio puede gestionar reuniones de su comité si tiene permiso de lectura de reuniones. Crear/integrar contactos requiere además gestión de contactos; enviar requiere gestión de correos.

El esquema se amplía sin borrar datos. Las reuniones pendientes anteriores reciben su fecha como planificación; a las realizadas antiguas no se les inventa un plan. Los cuatro eventos fijos se agregan con el botón del año correspondiente; no se cargan resultados ficticios del prototipo. No se enviaron correos durante las pruebas. La sincronización CSV necesita que el proveedor permita lectura desde el navegador; la carga de archivos funciona como alternativa.

## Biblioteca documental para ingenios (referencia v38)

Ruta: `/comites/index.php?view=documentos`. Los documentos se mantienen separados de la biblioteca interna.

| Comparación con v38 | Implementación |
| --- | --- |
| Carpetas y navegación | Tarjetas con conteos, subcarpetas y ruta de navegación; carpeta inicial Boletines del año actual si la biblioteca está vacía |
| Filtros globales | Todos, pendientes de mí, en revisión, devueltos, aprobados, rechazados y borradores; búsqueda por título, autor, tipo y descripción en todas las carpetas |
| Carga individual y múltiple | Archivo o enlace, título, autor, fecha, descripción, tipo y carpeta; arrastrar archivos, títulos/tipos sugeridos y datos comunes para el lote |
| Proceso de revisión | Primera revisión y verificación de correcciones por Transferencia; aprobación final por Dirección; sin anotaciones se omite la segunda revisión |
| Devoluciones y versiones | Anotaciones y adjuntos, corrección hacia la etapa correspondiente, rechazo y nueva versión; reemplazar contenido aprobado reinicia la revisión |
| Seguimiento | Estado, responsable, días pendientes, etapas y bitácora con actor, fecha, versión, observaciones y archivos conservados |
| Compartir | Propietario autorizado, documento aprobado, comités, segmentos privados y contactos individuales, exclusiones, logo, mensaje, adjunto o enlace y registro del envío real |

Los documentos se almacenan realmente en el repositorio: hasta 20 MB por archivo y 24 MB/20 archivos por lote. Un fallo revierte todo el lote y elimina sus archivos nuevos. No se simula la carga de archivos grandes como en el prototipo. Las escrituras verifican CSRF y las descargas verifican acceso y rutas. Los documentos en revisión quedan bloqueados; los formularios desactualizados se rechazan.

El esquema se amplía conservando documentos e historial. Los registros anteriores no reciben propietarios o aprobadores inventados; la administración puede gestionarlos. Se mantienen los permisos de documentos y correos, y se agregan `comites.documentos.revisar_transferencia` y `comites.documentos.aprobar_direccion` para roles personalizados. Los roles Jefe de Transferencia y Director General, con acceso a documentos, reciben las acciones de su etapa. El administrador puede operar todo el flujo. Enviar requiere además gestión de correos y configuración SMTP; las pruebas no envían mensajes.

```powershell
docker compose -f docker-compose.prod.yml exec -T app php /var/www/html/comites/tests/biblioteca.php
./comites/tests/biblioteca-http.ps1 -Credential (Get-Credential)
```

`biblioteca.php` prueba el flujo, versiones, permisos, aislamiento, preparación de destinatarios y protección de descargas con tablas temporales. `biblioteca-http.ps1` comprueba rollback de lote incompleto y carga/descarga real de dos archivos, y elimina los registros/archivos de prueba. `biblioteca-ui.js` expone `probarBiblioteca(codigoFuente)` para probar navegación, filtros, renderizado, carga múltiple y selección de destinatarios con un DOM mínimo; no sustituye una revisión visual en navegador.

## Biblioteca documental interna (referencia v38)

Ruta: `/comites/index.php?view=biblioteca_interna`.

| Plantilla | Implementación |
| --- | --- |
| Carpetas internas | Tarjetas con conteo, subcarpetas, navegación y carpeta inicial Memorias y presentaciones del año actual cuando la biblioteca está vacía |
| Consulta | Búsqueda global por título, autor, tipo y descripción; contador de documentos, versión, fecha, autor y responsable de carga |
| Carga | Individual o múltiple por selección/arrastre, título y tipo sugeridos, metadatos comunes, archivo o enlace y selección de carpeta |
| Administración | Carga, edición, cambio de carpeta y eliminación para superadministradores o rol Administrador con permiso de gestión de biblioteca interna |
| Uso interno | Sin revisión, aprobación ni envío a ingenios; estas acciones también se rechazan en el servidor |

Los usuarios con acceso de lectura pueden consultar y descargar. Se conservan archivos, versiones e historial anteriores; los indicadores de revisión/envío de los documentos internos se desactivan para reflejar su uso interno. Editar metadatos conserva la versión; reemplazar archivo o enlace la incrementa. Las descargas y operaciones se limitan a documentos de ámbito interno, con CSRF y detección de formularios desactualizados. La biblioteca para ingenios conserva su flujo de aprobación.

```powershell
docker compose -f docker-compose.prod.yml exec -T app php /var/www/html/comites/tests/interna.php
./comites/tests/biblioteca-http.ps1 -Credential (Get-Credential) -View biblioteca_interna
```

Las pruebas comprueban permisos, separación de bibliotecas, versiones, rechazo de acciones de revisión/correo, carga real, descarga y rollback de lotes incompletos. `interna-ui.js` expone `probarInterna(codigoFuente)` para verificar carpetas, búsqueda global, formularios y controles para administración/consulta con DOM mínimo. La revisión visual completa requiere un navegador.

## Memoria de resultados (referencia v38)

Ruta: `/comites/index.php?view=memorias`.

| Plantilla | Implementación |
| --- | --- |
| Memorias anuales | Tarjetas de ediciones, fecha límite, plazo de revisión, formato de criterios y plantilla de artículo descargables |
| Consulta | Los siete filtros de la plantilla, pendientes de mí y búsqueda global por número, título, autor y área |
| Entrega | Archivo real, autor, correo, coautores, área, numeración anual automática, versión inicial y datos generales inmutables |
| Revisores | Integrantes activos del comité seleccionado, carga de trabajo, agregar contactos existentes o registrar en directorio, asignación/reasignación con plazo y confirmación de conflicto de interés |
| Revisión y corrección | Artículo revisado y criterios llenos obligatorios, recomendación, comentarios, declinación con motivo, respuesta del autor y nueva versión lista para diagramación |
| Seguimiento | Etapas, fechas, responsables, archivos anteriores, observaciones y descarga registrada por diagramación |
| Consolidado MPR | Carga/versiones, observaciones finales y respuestas, aprobación de Transferencia seguida de Dirección, registro de publicación con fecha y enlace |

Los selectores de identidad de demostración se sustituyen por la cuenta autenticada. Autores ven sus artículos y revisores los asignados; las acciones se comprueban también en el servidor. Administradores pueden operar todo el flujo. Coordinación utiliza gestión de memorias; Diagramación, Jefe de Transferencia, Director General, Autor y Jefe de Área reciben acciones según su rol y requieren acceso al módulo. Se registran permisos específicos `comites.memorias.entregar`, `.diagramar`, `.aprobar_transferencia`, `.aprobar_direccion` y `.ver_todos` para roles personalizados. Modificar integrantes requiere además gestión de contactos o configuración.

Los avisos son internos al módulo; no se envían correos. Registrar publicación guarda la publicación ya realizada, sin publicar en servicios externos. Las observaciones no marcadas como atendidas se mantienen pendientes entre versiones. Cargas con artículos pendientes y aprobaciones con observaciones pendientes exigen confirmación explícita. Una nueva versión u observación final reinicia las aprobaciones; publicado queda cerrado.

La preparación conserva artículos, archivos y registros previos, vincula revisores existentes al directorio y agrupa artículos antiguos en «Memorias existentes». Si no hay ediciones crea una vacía del año actual; no carga ejemplos ficticios. Hasta 20 MB por archivo y 24 MB por formulario; las cargas fallidas se revierten, las transiciones se serializan y los formularios obsoletos se rechazan.

```powershell
docker compose -f docker-compose.prod.yml exec -T app php /var/www/html/comites/tests/memorias.php
./comites/tests/memorias-http.ps1 -Credential (Get-Credential)
```

`memorias.php` prueba permisos, privacidad, transiciones y aprobaciones con tablas temporales. `memorias-http.ps1` prueba entrega, asignación, revisión con dos archivos, corrección, diagramación, descargas y CSRF; `memorias-limpiar.php` elimina exclusivamente sus fixtures y archivos con identificador único. `memorias-ui.js` expone `probarMemorias(codigoFuente)` para verificar navegación, filtros, formularios y escape HTML con DOM mínimo; no sustituye una revisión visual en navegador.

## Informes cuatrimestrales (referencia v38)

Ruta: `/comites/index.php?view=informes`.

| Plantilla | Implementación |
| --- | --- |
| Organización | Selector de año, tres cuatrimestres con entregas/revisiones y resumen anual por área |
| Fechas límite | 15 de mayo, 15 de septiembre y 15 de enero del año siguiente; señal de entrega vencida |
| Áreas | Catálogo editable, responsable por correo de cuenta y desactivación sin perder historial |
| Entrega | Plan operativo e informe obligatorios para enviar, guardar borrador, actualizar entrega, enlace a carpeta y múltiples archivos complementarios |
| Revisión | Dirección consulta documentos y POA, registra comentarios y pendientes, marca revisado o devuelve con pendientes |
| Atención | Respuesta a cada pendiente, nuevos respaldos y regreso a Dirección para revisión |
| Seguimiento | Estados, pendientes abiertos/resueltos, respuestas, actor/rol/fecha, bitácora y archivos anteriores descargables |
| POA | Plantilla CSV, importación CSV/XLSX/XLS, sincronización desde enlace CSV publicado, fuente/fecha, metas/logros, cumplimiento por área y cuatrimestre y enlaces de evidencia |

La identidad del responsable se configura en **Áreas** mediante el correo de su cuenta con acceso a informes; no se utiliza el selector de área de demostración. Administración, Jefe de Transferencia y usuarios con gestión de informes coordinan áreas, entregas y POA. Dirección revisa, con permiso opcional `comites.informes.revisar` para roles personalizados. Administración puede operar todo el flujo; consulta ve documentos de todas las áreas sin modificarlos.

El enlace de sincronización se lee manualmente desde el navegador y necesita un CSV público con acceso CORS. También se puede exportar el libro privado y cargarlo. No se configura sincronización automática con Microsoft Graph ni se accede a cuentas externas. Excel se procesa en el servidor con la dependencia ya instalada, sin CDN. El POA admite 5 MB y 2000 filas; cada carga reemplaza únicamente el año seleccionado, valida filas y advierte áreas desconocidas. El promedio limita cada meta al 100%, como la plantilla.

Las cargas de documentos admiten 20 MB por archivo y 24 MB por formulario. Las entregas incompletas se revierten y sus archivos nuevos se limpian. CSRF, permisos y revisión del registro se validan en el servidor. Se conservan informes anteriores: los períodos reconocibles se integran y los demás siguen disponibles en una lista histórica, sin inventar fechas o sobrescribir duplicados. Desactivar un área no borra sus informes.

```powershell
docker compose -f docker-compose.prod.yml exec -T app php /var/www/html/comites/tests/informes.php
./comites/tests/informes-http.ps1 -Credential (Get-Credential)
```

`informes.php` usa tablas temporales para verificar permisos, borradores, revisión, respuestas, POA real CSV/XLSX y migración. `informes-http.ps1` carga plan, informe y respaldos, comprueba rollback, revisión/atención, descargas y CSRF; limpia sus fixtures y archivos únicos. `informes-ui.js` expone `probarInformes(codigoFuente)` para navegación, resumen anual, cumplimiento POA, formularios, permisos de consulta y escape HTML. No sustituye la revisión visual en navegador.

## Tableros embebidos

`/comites/index.php?view=tableros` presenta los enlaces existentes en tarjetas con iframe, búsqueda y administración con los permisos existentes. Cada tablero permite contraer/mostrar, ampliar a todo el ancho, pantalla completa, altura compacta/normal/grande, recargar y abrir aparte. Los controles mantienen el mismo iframe y las preferencias de presentación se guardan en sessionStorage durante la sesión de la pestaña. En pantallas pequeñas se muestra una columna.

Guardar acepta URLs HTTP/HTTPS de inserción, no HTML ni esquemas ejecutables o URLs con credenciales. El iframe usa sandbox, acceso a origen externo para autenticación y fullscreen; el mismo origen de la aplicación queda aislado. No hay proxy ni descarga del reporte desde el servidor. No se omiten permisos del proveedor ni restricciones de inserción: Power BI privado requiere la cuenta con acceso al informe; usar Archivo → Insertar informe → Sitio web o portal. Los sitios que prohíben iframe se pueden abrir aparte. No se detecta éxito de autenticación por el evento load del iframe.

Guardar/eliminar verifica CSRF y permisos. `tests/tableros.php` comprueba URLs seguras y conservación de parámetros. Se verificaron por HTTP creación, renderizado del iframe escapado y controles, rechazo de eliminación sin token válido y limpieza del tablero temporal. La visualización real del proveedor requiere un enlace habilitado y sesión autorizada en navegador.

## Seguimiento a próximos pasos (antes Acuerdos y seguimiento)

Ruta conservada: `/comites/index.php?view=acuerdos`.

| Plantilla v38 | Implementación |
| --- | --- |
| Filtros | Abiertos por defecto, vencidos, en proceso, completados, cancelados y todos, con conteos |
| Búsqueda y comité | Búsqueda por próximo paso, responsable o comité, combinada con estado y comité |
| Prioridad | Vencidos primero y después fecha límite; días vencidos/restantes calculados |
| Tabla | Comité/fecha/origen en reunión, próximo paso, observaciones, responsable, plazo, seguimiento, barra de avance y estado |
| Formulario | Nuevo/actualizar, fecha límite obligatoria, seguimiento, avance por deslizador de 5%, estado y observaciones |
| Reglas | 100% completa automáticamente, Completado fuerza 100%; Cancelado prevalece; Vencido se calcula sin guardarlo |
| Exportación | CSV UTF-8 con las nueve columnas de la plantilla y únicamente las filas filtradas, neutralizando fórmulas |

Se conservan los acuerdos existentes y su relación con reuniones. El comité de un paso originado en reunión se conserva; cambiarlo corresponde a la reunión. La edición de reuniones admite Cancelado y mantiene la normalización del avance. No se alteran ni borran registros al migrar el ENUM para admitir cancelación. Los antiguos Pendiente se muestran En proceso o Vencido según plazo.

Consulta permite filtrar y exportar. Gestión de acuerdos habilita alta, actualización y eliminación; las escrituras comprueban permisos, CSRF, fechas y una revisión del contenido para rechazar formularios desactualizados. Eliminar un paso lo retira también de su reunión de origen.

`tests/pasos.php` prueba estados, fechas, permisos, edición obsoleta y compatibilidad con reuniones en tablas temporales. `tests/pasos-ui.js` expone `probarPasos(codigoFuente)` y verifica filtros, orden, formulario, escape HTML y exportación de filas filtradas. Se probaron por HTTP alta, observaciones, finalización al 100%, CSRF y eliminación del registro temporal; la suite de agenda también pasa. La validación visual completa requiere un navegador.
