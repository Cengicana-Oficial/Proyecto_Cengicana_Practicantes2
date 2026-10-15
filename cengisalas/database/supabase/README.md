# SalaFlow en Supabase

Estos scripts convierten el diseño del prototipo en un backend PostgreSQL para Supabase con Auth, RLS, funciones RPC, prevención transaccional de traslapes y Realtime.

## Instalación

En un proyecto nuevo de Supabase, abre **SQL Editor** y ejecuta cada archivo completo en este orden:

1. `01_extensions_and_types.sql`
2. `02_schema.sql`
3. `03_functions.sql`
4. `04_rls_policies.sql`
5. `05_realtime.sql`
6. `06_seed.sql`
7. `07_views.sql`
8. `09_user_management.sql`
9. `10_fix_meeting_request_ordinality.sql`
10. `11_public_requests_and_contact.sql`
11. `12_boardroom_configuration.sql`
12. `13_reschedule_and_roles.sql`
13. `14_external_request_tracking.sql`
14. `15_short_tracking_code.sql`
15. `16_request_observations.sql`
16. `17_room_capacities_and_costs.sql`
17. `18_room_hourly_energy_consumption.sql`
18. `19_finished_meeting_protection.sql`
19. `20_paid_external_requests.sql`
20. `21_paid_pricing_rules.sql`
21. `22_executive_offering.sql`
22. `23_paid_availability.sql`
23. `24_public_room_availability.sql`
24. `25_per_occurrence_space_configuration.sql`
25. `26_public_refreshments_and_series_review.sql`
26. `27_meeting_incidents.sql`
27. `28_annual_request_numbers.sql`
28. `29_modules_and_permissions.sql`
29. `30_visits_schema.sql`
30. `31_visits_seed.sql`
31. `32_visits_management_workflow.sql`
32. `33_visit_area_contacts.sql`
33. `34_visit_guest_forms.sql`
34. `35_superadmin_permission_matrix.sql`
35. `36_visit_payment_receipts.sql`

`08_reference_queries.sql` contiene ejemplos y comprobaciones; no es una migración obligatoria.

`29_modules_and_permissions.sql` centraliza los módulos, roles y permisos en los
perfiles de Supabase. `30_visits_schema.sql` crea el esquema PostgreSQL `visits`
y el bucket privado de documentos. `31_visits_seed.sql` agrega los catálogos
mínimos para instalaciones nuevas. `32_visits_management_workflow.sql` agrega
los estados y registros persistentes usados por Gestionar visita: agenda,
fotografías, notificaciones e historial de acciones.

## Activar el backend Supabase

1. En un proyecto limpio ejecuta los scripts 01 a 34 desde SQL Editor, en el orden indicado.
2. En `C:\xampp\php\php.ini`, habilita `extension=pdo_pgsql` y reinicia Apache.
3. Copia `modules/visitas/.env.example` como `modules/visitas/.env`, completa el
   **Session pooler** de Supabase y la clave secreta (`SUPABASE_SECRET_KEY`)
   únicamente en ese archivo local. No uses la publishable key para Storage.
4. Valida el origen sin escribir datos:

   ```powershell
   C:\xampp\php\php.exe scripts\migrate_aiven_to_supabase.php --dry-run
   ```

5. Ejecuta la migración de filas y documentos:

   ```powershell
   C:\xampp\php\php.exe scripts\migrate_aiven_to_supabase.php
   ```

6. El script histórico migra también el historial de Gestionar visita (`solicitud_eventos_visita`) y,
   si existen en `uploads/gestion_visitas`, las agendas y fotografías al bucket privado.
   Cuando el script confirme los conteos, cambia `VISITS_DB_DRIVER=pgsql`,
   prueba el formulario y el panel, y solo entonces retira las credenciales de
   Aiven. El script usa `upsert`, por lo que puede reanudarse sin duplicar IDs.

Cuando se inicia con una base Supabase limpia no es necesario ejecutar el script
histórico de Aiven: deja `VISITS_DB_DRIVER=pgsql`, prueba el login, formularios,
panel, documentos, reportes y tablero, y puedes retirar las credenciales antiguas.

Los usuarios y contraseñas de la base antigua no se copian: la identidad oficial
es `auth.users` y los permisos se asignan mediante `profile_module_roles`.

Si la base ya estaba instalada y al solicitar un salón aparece `WITH ORDINALITY cannot be used with a column definition list`, ejecuta `10_fix_meeting_request_ordinality.sql` y después `11_public_requests_and_contact.sql`. No es necesario volver a ejecutar `03_functions.sql`.

`11_public_requests_and_contact.sql` agrega los datos del responsable y habilita solicitudes públicas sin cuenta. `12_boardroom_configuration.sql` agrega Tipo Juntas y sustituye la regla anterior de los salones 5 y 8 por la matriz definitiva de distribuciones. `13_reschedule_and_roles.sql` permite reprogramar horarios y cambiar roles. `14_external_request_tracking.sql` habilita la primera versión del seguimiento. `15_short_tracking_code.sql` genera códigos abreviados y permite consultarlos sin correo. `16_request_observations.sql` guarda observaciones opcionales y las incorpora a la vista administrativa. `17_room_capacities_and_costs.sql` agrega capacidades editables y las tres tarifas de uso para los salones 1, 2 y 3. `18_room_hourly_energy_consumption.sql` agrega el consumo eléctrico estimado por hora para todos los salones. `19_finished_meeting_protection.sql` bloquea cambios sobre reuniones finalizadas y expone el último cambio de horario para mostrar el estado Reprogramado. `20_paid_external_requests.sql` agrega las ofertas Salón principal y Salón de Formación, cotización, enlace externo y bloqueos provisionales hasta aprobación o rechazo. `21_paid_pricing_rules.sql` actualiza el cálculo para cobrar las primeras 3 horas por hora, de 4 a 8 horas como tarifa fija y las horas posteriores como extras. `22_executive_offering.sql` agrega la oferta Salón Ejecutivo basada en el Salón 3. `23_paid_availability.sql` oculta ofertas comerciales ocupadas antes de que el usuario las seleccione. `24_public_room_availability.sql` permite que el formulario público muestre los salones disponibles para la fecha y el horario indicados, sin exponer detalles de otras reuniones. `25_per_occurrence_space_configuration.sql` permite seleccionar y guardar un salón y una distribución distintos para cada fecha de una serie, incluyendo ofertas y cotizaciones comerciales por fecha. `26_public_refreshments_and_series_review.sql` habilita refacciones para solicitudes públicas de Cengicaña y protege la aprobación individual o completa de sesiones futuras. `27_meeting_incidents.sql` agrega el registro de pantalla encendida, aire acondicionado encendido sin personal y equipos faltantes por sesión aprobada. `28_annual_request_numbers.sql` convierte las solicitudes existentes y genera las nuevas con un correlativo anual como `2026-001`, reiniciado automáticamente cada año. Los códigos históricos quedan guardados como alias para que el seguimiento y los comprobantes anteriores continúen funcionando. Deben ejecutarse también en instalaciones existentes antes de usar el frontend actualizado.
`33_visit_area_contacts.sql` agrega el profesional responsable de cada área y conserva el correo para las notificaciones administrativas. `34_visit_guest_forms.sql` crea los enlaces seguros y el listado de nombres de visitantes que se completa desde el correo de aprobación. `35_superadmin_permission_matrix.sql` centraliza la asignación granular de módulos y permisos. `36_visit_payment_receipts.sql` crea los enlaces seguros de pago y los campos para recibir y revisar comprobantes comerciales. En el panel de solicitudes, `Gestionar pago` permite copiar el enlace, enviarlo por correo y cargar el comprobante con permiso de revisión de pagos.

Los scripts no guardan contraseñas en tablas públicas. Supabase Auth conserva las credenciales y el trigger `handle_new_user` crea el perfil de aplicación. Después de instalar `09_user_management.sql`, toda cuenta nueva queda con estado `pending` y sin acceso hasta que un administrador la apruebe.

## Crear el primer administrador

Después de crear la cuenta en Authentication, ejecuta sustituyendo el correo:

```sql
update public.profiles
set role = 'admin',
    account_status = 'active',
    is_active = true,
    status_reason = null,
    organization_id = (select id from public.organizations where code='CENGICANA'),
    department_id = (
      select d.id from public.departments d
      join public.organizations o on o.id=d.organization_id
      where o.code='CENGICANA' and d.name='Administración'
    )
where id = (select id from auth.users where email='admin@ejemplo.com');
```

La refacción se habilita por `organizations.can_request_refreshments`; no se confía en un valor enviado por el navegador. Para un empleado de Cengicaña, asigna su organización y departamento desde una cuenta administradora.

## Operaciones disponibles por RPC

| Función | Uso |
|---|---|
| `create_meeting_request` | Crea una solicitud y todas sus fechas en una transacción. |
| `create_external_meeting_request` | Crea una solicitud pendiente para una persona sin cuenta. |
| `get_public_request_catalogs` | Devuelve los catálogos mínimos del formulario público. |
| `get_public_space_availability_by_occurrence` | Devuelve los salones libres para cada fecha y horario enviados. |
| `get_paid_offering_availability_by_occurrence` | Devuelve las ofertas comerciales disponibles para cada fecha. |
| `track_external_request` | Consulta el estado de una solicitud pública utilizando únicamente su código. |
| `check_space_availability` | Comprueba un espacio y horario. |
| `approve_occurrence` | Aprueba una fecha o toda una serie; permite reaprobar rechazadas. |
| `reject_occurrence` | Rechaza pendientes y conserva el motivo. |
| `cancel_occurrence` | Cancela una fecha/serie propia o, siendo admin, cualquiera. |
| `move_occurrence` | Admin mueve una fecha/serie a otro salón. |
| `admin_reschedule_occurrence` | Admin cambia el horario de una fecha o de toda una serie y vuelve a validar conflictos. |
| `schedule_maintenance` | Admin bloquea un salón por mantenimiento. |
| `cancel_maintenance` | Admin cancela el mantenimiento y libera el salón. |
| `admin_create_room` | Crea salón físico, espacio y capacidades. |
| `admin_update_room` | Modifica salón y capacidades. |
| `admin_update_room_capacities_and_costs` | Modifica capacidades, consumo eléctrico por hora y tarifas de uso del salón. |
| `get_agenda` | Agenda autenticada del día. |
| `get_public_board` | Snapshot público del tablero del día. |
| `get_admin_users` | Devuelve al administrador el listado de cuentas por estado y rol. |
| `admin_approve_user` | Aprueba una cuenta pendiente o rechazada y asigna rol/organización. |
| `admin_reject_user` | Rechaza una solicitud conservando el motivo. |
| `admin_delete_user_logically` | Desactiva una cuenta sin borrar su perfil ni historial. |
| `admin_restore_user` | Restaura una cuenta eliminada lógicamente. |
| `admin_change_user_role` | Cambia una cuenta activa entre usuario estándar y administrador. |
| `get_signup_organizations` | Catálogo público de organizaciones activas para el registro. |
| `admin_create_organization` | Crea una institución adicional desde el módulo administrativo. |

## Registro y gestión de usuarios

- En el login, **Solicitar acceso** crea la identidad en Supabase Auth y un perfil `pending`.
- La persona selecciona su organización entre Cengicaña, ICC, Atagua, Asazgua, Fundazucar, Innovahub, Externo y las instituciones activas agregadas posteriormente.
- El administrador recibe la solicitud en **Administración > Usuarios** y puede aprobarla como usuario estándar o administrador, o rechazarla indicando el motivo.
- Desde **Instituciones**, el administrador puede agregar organizaciones adicionales; aparecen inmediatamente en el registro y en los formularios administrativos.
- Las cuentas activas se muestran separadas entre administradores y usuarios estándar.
- El administrador puede cambiar el rol de otras cuentas activas; no puede modificar su propia sesión ni quitar el rol al último administrador.
- **Eliminar** es una eliminación lógica: cambia la cuenta a `deleted`, bloquea su acceso y conserva todas sus reuniones e historial.
- La pestaña **Eliminados** permite restaurar la cuenta.
- El administrador también puede crear una cuenta con contraseña temporal. Si **Confirm email** está habilitado en Supabase, la persona deberá confirmar su correo antes de iniciar sesión.
- Las contraseñas de cuentas activas pueden cambiarse desde el listado administrativo. Esta operación usa la Edge Function `admin-set-password`, porque la API administrativa de Auth solo debe ejecutarse en el servidor.

En **Authentication > Providers > Email**, la opción para permitir nuevos registros debe estar activa. Para pruebas locales, configura también `http://localhost/demo-salas/` en **Authentication > URL Configuration > Redirect URLs**.

Ejemplo de solicitud recurrente desde `supabase-js`:

```js
const { data: meetingId, error } = await supabase.rpc('create_meeting_request', {
  p_title: 'Comité mensual',
  p_description: 'Revisión de indicadores',
  p_meeting_type_id: 2,
  p_configuration_type_id: 1,
  p_attendees: 18,
  p_contact_name: 'María López',
  p_contact_email: 'maria@ejemplo.org',
  p_contact_phone: '5555-5555',
  p_occurrences: [
    { space_id: 9, starts_at: '2026-09-03T09:00:00-06:00', ends_at: '2026-09-03T11:00:00-06:00' },
    { space_id: 9, starts_at: '2026-10-01T09:00:00-06:00', ends_at: '2026-10-01T11:00:00-06:00' }
  ],
  p_recurrence_type: 'monthly',
  p_recurrence_end_date: '2026-10-01',
  p_refreshment_ids: [1, 3, 4]
})
```

Los identificadores numéricos del ejemplo coinciden en una instalación nueva, pero en el frontend conviene obtenerlos consultando los catálogos por `code`.

## Solicitudes sin cuenta

El formulario `solicitud-externa.html` usa únicamente la clave pública de Supabase y las RPC `get_public_request_catalogs` y `create_external_meeting_request`. La solicitud entra con estado `pending`, se identifica como `public` y aparece en el módulo administrativo para ser aprobada, rechazada, movida o cancelada igual que una solicitud interna.

La RPC pública valida organización, tipo, capacidad, fechas, conflictos y datos de contacto. También incluye un campo trampa y un límite básico de cinco solicitudes por correo por hora. No se otorgan permisos anónimos de escritura directa sobre las tablas.

`seguimiento.html` permite consultar el avance únicamente con el código de referencia. Los códigos nuevos tienen el formato anual `AAAA-NNN`, por ejemplo `2026-001`. Los códigos anteriores `CS-XXXXXXXXXXXX` y `SF-AAAAMMDD-XXXXXXXX` continúan funcionando. `track_external_request` solo devuelve datos operativos seguros de solicitudes con origen `public` o `paid`; no expone teléfono, correo, identificadores internos ni nombres de administradores. Las solicitudes recurrentes muestran el estado independiente de cada fecha.

## Tablero en tiempo real

El tablero obtiene el estado con `get_public_board` y escucha inserciones de `board_events`. Cada aprobación, cancelación, traslado o mantenimiento genera un evento en la misma transacción. Al recibirlo se vuelve a cargar el snapshot:

```js
async function loadBoard(date) {
  const { data, error } = await supabase.rpc('get_public_board', { p_date: date })
  if (error) throw error
  renderBoard(data)
}

const channel = supabase
  .channel('room-board')
  .on(
    'postgres_changes',
    { event: 'INSERT', schema: 'public', table: 'board_events' },
    () => loadBoard(selectedDate)
  )
  .subscribe()
```

Al abrir la página ejecuta primero `loadBoard`; Realtime entrega cambios futuros, no el estado inicial. Ante una reconexión también conviene volver a cargar el snapshot.

## Seguridad y conflictos

- Supabase Auth administra login, hash de contraseñas, recuperación y sesiones.
- RLS restringe perfiles, solicitudes e historiales al propietario o administrador.
- El cliente no recibe permisos directos de escritura sobre reservas, bloqueos o mantenimientos; usa las RPC.
- Las RPC sensibles son `security definer`, usan `search_path` vacío y validan al actor.
- `room_blocks_no_overlap` es una restricción de exclusión GiST. Evita carreras y dobles reservas en PostgreSQL.
- `SPACE_1_2` contiene `ROOM_1` y `ROOM_2`; al aprobarlo se insertan dos bloqueos, por lo que ninguno puede reservarse aparte en ese horario.
- `SPACE_1`, `SPACE_2` y `SPACE_1_2` admiten únicamente Tipo U o Tipo Escuela.
- `SPACE_3` admite Tipo U, Tipo Escuela y Tipo Juntas.
- `SPACE_4` a `SPACE_8` admiten únicamente Tipo Juntas. La función del servidor aplica esta matriz aunque otro cliente intente enviar una configuración distinta.
- Una serie se procesa en una sola transacción. Si una fecha presenta conflicto, toda la operación se revierte.
- Los tiempos se almacenan como `timestamptz`; las fechas del tablero se interpretan en `America/Guatemala`.

## Vista administrativa

`vw_request_occurrences` soporta las pestañas Pendientes, Aprobadas, Rechazadas y Canceladas. Se puede filtrar por `starts_at` y `status`, e incluye observaciones, `approved_at`, `rejected_at`, `rejection_reason`, `cancelled_at`, `cancelled_by_role`, `cancelled_by_name` y el último movimiento de salón.

## Nota sobre el frontend

`modules/salas/js/app.js` y `modules/salas/js/tablero.js` ya utilizan Auth, consultas, RPC y Realtime de Supabase. Nunca coloques la clave `service_role` en el navegador; allí solo se usa la clave pública (`anon`/publishable).
