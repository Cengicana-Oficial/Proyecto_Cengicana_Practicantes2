# CengiSalas — Cengicaña

Portal institucional modular para salones y visitas de Cengicaña, conectado a Supabase Auth, PostgreSQL y Realtime.

## Organización del repositorio

- Las páginas públicas y el portal permanecen en la raíz para conservar las URLs existentes.
- `modules/salas` contiene el JavaScript y CSS del módulo de salones.
- `../visitas` contiene el módulo independiente de visitas, accesible desde el menú del portal. Su panel de roles está en `../login/visitas_roles.php`.
- `database/supabase` contiene el esquema de Salones, módulos, permisos y Visitas.
- `supabase/functions` contiene las operaciones que requieren privilegios de servidor.
- `scripts/migrate_aiven_to_supabase.php` conserva una migración histórica opcional; una instalación nueva usa únicamente Supabase.

Las dependencias de Composer, los documentos cargados y los archivos `.env` no
se suben a GitHub; se reconstruyen o migran durante el despliegue.

## Abrir con XAMPP

Con Apache iniciado, visita:

`http://localhost/demo-salas/`

Tablero para recepción o pantalla grande:

`http://localhost/demo-salas/tablero.html`

Formulario para personas que no tienen una cuenta:

`http://localhost/demo-salas/solicitud-externa.html`

Seguimiento de solicitudes externas:

`http://localhost/demo-salas/seguimiento.html`

Debe abrirse mediante XAMPP para conservar un origen web estable. La configuración pública del proyecto está en `supabase-config.js`.

## Base de datos y primer acceso

Los scripts PostgreSQL se encuentran en `database/supabase`; consulta `database/supabase/README.md` para conocer el orden de instalación.

1. Ejecuta todas las migraciones hasta `33_visit_area_contacts.sql`, en el orden descrito en `database/supabase/README.md`.
2. Comparte con empresas externas el enlace `solicitud-pago.html`. Desde el panel de administración puedes revisar estas solicitudes en la pestaña **Solicitudes de pago** y editar sus precios/capacidades desde **Salones**.
3. Para el primer administrador, crea la cuenta en **Supabase > Authentication > Users** y ejecuta el ejemplo de `database/supabase/08_reference_queries.sql` para promoverla y asignarle Cengicaña.
4. Inicia sesión en `http://localhost/demo-salas/`. Desde ese momento las demás cuentas pueden solicitar acceso en el login y ser administradas desde **Usuarios**.

No existen credenciales ficticias en el frontend. Las contraseñas y sesiones son administradas por Supabase Auth.

## Incluye

- Agenda diaria para Salón 1 a Salón 8.
- Vista mensual de calendario; al seleccionar una fecha se abre la solicitud con ese día bloqueado.
- Agenda diaria por salones y horas como vista predeterminada, con una vista de lista alternativa agrupada por salón.
- Desde la vista de lista, el administrador puede consultar, mover o cancelar reuniones aprobadas y pendientes.
- Tablero independiente con estado y agenda diaria de los ocho salones.
- Sincronización con Supabase Realtime al aprobar, cancelar, mover reuniones o programar mantenimientos.
- Actualización automática entre navegadores y equipos conectados al proyecto Supabase.
- Módulo administrativo para programar y cancelar avisos de mantenimiento por día completo o por horario, con estados Programado, Cancelado y Finalizado calculados automáticamente.
- Módulo de incidencias por reunión aprobada: el administrador registra pantalla encendida, aire acondicionado sin personal y equipos faltantes; cada usuario consulta las incidencias de sus reuniones en tarjetas.
- Reportería administrativa mensual de reuniones aprobadas con cálculo de horas, kWh y consumo energético, exportable a PDF y XLSX.
- Bloqueo de nuevas reservas durante mantenimientos y señalización inmediata del salón en el tablero.
- Tres distribuciones disponibles según el salón: Tipo U, Tipo Escuela y Tipo Juntas.
- Salones 4, 5, 6, 7 y 8 limitados a Tipo Juntas; Salón 3 permite las tres; Salones 1 y 2 permiten Tipo U y Tipo Escuela.
- Datos de contacto del responsable del salón: nombre, correo electrónico y teléfono.
- Observaciones opcionales en solicitudes internas y externas, visibles en el detalle administrativo.
- Formulario público para solicitar salones sin registrarse; cada solicitud queda pendiente de aprobación administrativa.
- Seguimiento público estilo rastreo únicamente mediante un código corto, con avance y estado independiente para cada fecha recurrente.
- Clasificación por tipo: Reunión de Equipo de Trabajo, Comité o Visita.
- Solicitud opcional de café, gaseosa, galletas y agua pura restringida a Cengicaña, tanto para cuentas registradas como en el formulario público.
- Reuniones recurrentes semanales, quincenales, mensuales o con fechas específicas.
- Validación de disponibilidad en todas las fechas antes de crear o aprobar una serie.
- Revisión administrativa de series en una ventana con todas sus sesiones, salones, distribuciones y refacciones; aprobación completa o decisión fecha por fecha.
- Capacidad diferente según la configuración.
- Edición administrativa de capacidades y tarifas de uso para los salones 1, 2 y 3.
- Registro editable del consumo eléctrico estimado por hora, expresado en kWh, para cada salón.
- Separación entre Aprobación y Estado operativo de cada reunión; las reuniones finalizadas quedan solo para consulta.
- Enlace externo para solicitudes comerciales con cotización y bloqueo provisional de Salón principal o Salón de Formación.
- Reserva combinada de Salón 1 + Salón 2.
- Bloqueo y validación de conflictos en ambos salones cuando se combinan.
- Historial administrativo en lista, con pestañas por estado y filtro por fecha.
- Módulo administrativo independiente **Solicitudes pago**, con precio total cotizado y filtros por estado y fecha.
- Menús compactos de tres puntos para las acciones de solicitudes generales y comerciales.
- Registro público sujeto a aprobación administrativa.
- Selección de organización durante el registro: Cengicaña, ICC, Atagua, Asazgua, Fundazucar, Innovahub o Externo.
- Catálogo ampliable de instituciones administrado desde el propio sistema.
- Gestión de usuarios pendientes, activos, rechazados y eliminados.
- Separación visual de administradores y usuarios estándar.
- Creación administrativa de cuentas con contraseña temporal.
- Cambio administrativo de contraseñas mediante una función segura del servidor.
- Eliminación lógica y restauración de usuarios, conservando historial y reuniones.
- Registro de fecha y hora de aprobación, rechazo o cancelación, con identificación de quién canceló.
- El detalle de una solicitud rechazada conserva y muestra el motivo registrado.
- El administrador puede mover o cancelar reuniones aprobadas y volver a aprobar solicitudes rechazadas.
- El administrador puede cambiar el horario de reuniones pendientes, aprobadas o rechazadas, para una fecha específica o para toda la serie.
- El administrador puede promover usuarios activos a administrador o devolverlos a usuario estándar.
- Consulta y cancelación de reuniones propias.
- Persistencia PostgreSQL y diseño adaptable a dispositivos móviles.

Las operaciones sensibles se ejecutan mediante funciones RPC y políticas RLS. Los catálogos y datos operativos se cargan desde Supabase; el frontend no contiene reuniones, usuarios ni salones ficticios de respaldo.

## Función para cambiar contraseñas

El botón **Cambiar contraseña** de **Administración > Usuarios > Activos** invoca la Edge Function `admin-set-password`. Despliégala en el proyecto Supabase antes de utilizar el botón:

```bash
npx supabase init
npx supabase login
npx supabase link --project-ref bjggelfbrgjfshzctopj
npx supabase functions deploy admin-set-password
```

También puedes crearla desde **Supabase Dashboard > Edge Functions > Deploy a new function > Via Editor**, usando como nombre `admin-set-password` y copiando el contenido de `supabase/functions/admin-set-password/index.ts`.

Supabase proporciona las claves secretas a la función en el servidor. No agregues una clave `service_role` o `sb_secret_...` a `supabase-config.js` ni a ningún archivo público.
