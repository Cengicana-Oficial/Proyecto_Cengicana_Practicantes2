# Integración de Visitas y Cengisalas con el portal de módulos

Fecha: 30/09/2026.

## Alcance y decisión del usuario

Se analizó estáticamente `C:\xampp\htdocs\demo-salas.rar`, extraído en una carpeta temporal, y se comparó con este repositorio. Se excluyeron `.git`, `vendor` y `node_modules` de la extracción. No se ejecutaron la aplicación del RAR, sus migraciones ni conexiones a sus servicios externos. Los README del archivo se usaron como documentación de referencia, no como instrucciones de ejecución.

Requisitos confirmados:

- Sustituir la versión actual de Visitas, conservando su historial.
- Usar los usuarios del portal inicial como fuente de identidad y administración.
- Incorporar Cengisalas al catálogo de módulos y a la administración de accesos.

Este documento es una propuesta de implementación. No se modificaron la aplicación, el RAR original ni bases de datos. No se verificó el contenido de una base de producción ni la configuración activa del proyecto Supabase.

## Hallazgos comprobados en el código

| Componente | Portal actual | RAR |
|---|---|---|
| Inicio de sesión | PHP, `usuarios_menu.usuarios` en MySQL | Supabase Auth y `public.profiles` en PostgreSQL |
| Identificador de usuario | Entero | UUID |
| Módulos asignados | `usuario_modulo` | `profile_module_roles` y permisos directos |
| Permisos | `roles`, `permisos`, `rol_permiso` | Roles globales, roles por módulo y `profile_module_permissions` |
| Visitas | `Pruebas/`, base configurada `cengi_visitas` | `modules/visitas/`, esquema PostgreSQL `visits` |
| Salones | Integración limitada desde Visitas | HTML/JavaScript, RPC PostgreSQL y Realtime |
| Documentos de Visitas | Archivos locales | Bucket privado `visit-documents`; compatibilidad local en modo MySQL |

El RAR contiene 36 scripts SQL numerados para Supabase. En los JavaScript de `modules/salas/js` se identificaron 32 nombres distintos de RPC. Al comparar los PHP de `modules/visitas` con las mismas rutas relativas en `Pruebas`, hay 37 archivos modificados y 15 nuevos; ninguno de los 52 coincide byte por byte. No es una actualización que pueda resolverse únicamente copiando una carpeta.

Visitas y Salones comparten una base PostgreSQL en el diseño del RAR: Salones utiliza principalmente `public` y Visitas utiliza `visits`. La tabla `visits.solicitud_reservas_cengisalas` referencia los espacios y configuraciones de Salones. El endpoint `cengisalas_salon_visita.php` consulta disponibilidad, crea una solicitud de salón y guarda el vínculo con la visita.

## Propuesta principal

Mantener MySQL para el portal y conservar inicialmente Supabase/PostgreSQL para los dos módulos importados. Centralizar usuarios, contraseñas, asignación de módulos y permisos en el portal. Esta elección permite conservar la lógica de agenda, recurrencias, restricciones, documentos y actualizaciones en tiempo real del RAR.

Usar los usuarios del portal no obliga a convertir las bases operativas a MySQL. Unificar identidad y convertir motores de base de datos son trabajos distintos.

```text
Login del portal -> usuarios_menu (MySQL)
                         |
                  sesión y permisos
                         |
          +--------------+--------------+
          |                             |
     Visitas nuevo                 Cengisalas
     PHP del portal                HTML/JavaScript
          |                             |
          +---- Supabase/PostgreSQL -----+
                 visits + public
                 documentos privados
```

Si el objetivo adicional fuera eliminar Supabase y alojar todo localmente, se necesitaría otro alcance: migrar las funciones SQL, sustituir Auth, Storage y Realtime, y adaptar el frontend. Cambiar `VISITS_DB_DRIVER` a MySQL no convierte Cengisalas a MySQL ni demuestra compatibilidad completa de Visitas.

## Identidad única y sesión

1. Conservar `login/login.php` como entrada de los usuarios internos. Eliminar el uso operativo del login, autorregistro, cambio de contraseña y administración de cuentas propios de Cengisalas. Los formularios externos de solicitudes seguirían siendo públicos según sus reglas actuales.
2. Conservar el ID entero del portal en `$_SESSION['id_usuario']` y el formato actual de `$_SESSION['usuario']`. El puente del RAR asigna un UUID a la primera variable y un arreglo a la segunda; el portal espera un entero y un nombre. Copiar ese puente rompe el contrato de sesión.
3. Cambiar `modules/visitas/config/auth.php` para utilizar la sesión central, comprobar que el usuario siga existiendo, verificar el módulo asignado y cargar sus permisos centrales. No conservar la expiración independiente de cinco minutos que destruye la sesión PHP completa.
4. Corregir `Conexion::conectarUsuariosMenu()`: en el RAR devuelve PostgreSQL cuando el driver es `pgsql`. Debe conectar exclusivamente a MySQL para usuarios, mientras `conectar()` conserva PostgreSQL para datos de Visitas.
5. Guardar el contexto auxiliar bajo una clave propia, por ejemplo `$_SESSION['cengisalas']`, sin sobrescribir el rol, superadministrador ni permisos globales del portal.
6. Adaptar el cierre de sesión del portal, actualmente enlazado a `Pruebas/public/admin/logout.php`, para cerrar también el acceso auxiliar a Salones.

### Acceso del JavaScript a Supabase

Propuesta: un endpoint PHP autenticado obtiene la identidad desde la sesión central y entrega un JWT corto reconocido por el proyecto Supabase. Nunca debe aceptar del navegador el usuario, rol o superadministrador que va a firmar. El rol PostgreSQL del token debe ser `authenticated`, no `service_role`.

Supabase documenta la aceptación de JWT emitidos con una clave de firma importada y su uso con la opción `accessToken` del cliente. Es necesario configurar y probar esa confianza en el proyecto de destino; el token de `login/sso_sigec.php` no es reutilizable directamente. Fuentes: [JWT de Supabase](https://supabase.com/docs/guides/auth/jwts), [claves de firma](https://supabase.com/docs/guides/auth/signing-keys).

El esquema actual referencia `auth.users(id)` desde `profiles` y numerosas tablas. Por eso hace falta una correspondencia persistente `usuario_menu_id <-> supabase_user_id`, y una identidad técnica compatible con esas claves foráneas. Se puede aprovisionar desde servidor con la API administrativa, sin copiar las contraseñas del portal. La API administrativa es de uso exclusivo del servidor: [createUser](https://supabase.com/docs/reference/javascript/auth-admin-createuser).

Esta identidad técnica no sería otra cuenta administrada por el usuario: su acceso y perfil se derivarían del portal. Las vías independientes de registro, acceso y elevación de permisos del sistema anterior deben deshabilitarse. Los UUID históricos se conservan; vincular cuentas existentes por correo requiere revisar coincidencias y duplicados, y después guardar el vínculo por ID estable.

El frontend debe sustituir `auth.getSession()`, `signInWithPassword`, `signUp`, los manejadores de cierre de sesión y el bootstrap de perfil por el adaptador del portal. También hay que probar renovación del token y reconexión de Realtime. Emitir un JWT no crea por sí mismo una sesión de Supabase Auth.

Los cambios de usuarios, roles y módulos deben actualizar la proyección de permisos en PostgreSQL. No se debe prometer revocación instantánea con un JWT ya emitido: definir una caducidad corta, control de versión/sesión en las RPC y políticas, y sincronización de revocaciones. Si la sincronización falla, bloquear nuevas autorizaciones y reportar el fallo. Probar expresamente el intervalo de revocación, incluidas pestañas abiertas.

## Administración de módulos y permisos

- Reutilizar el registro y el ID de **Solicitud de visitas** en `usuarios_menu.modulos`, conservando sus asignaciones en `usuario_modulo`.
- Crear **Cengisalas** como módulo nuevo. Su asignación se haría en la pantalla actual de edición de usuarios.
- Agregar su ruta en `menu_modulo_meta()` de `login/Menu.php` y cambiar la ruta de Visitas al nuevo panel después de validar la migración.
- Agrupar los permisos nuevos en `login/config/permisos_roles.php` para editarlos desde Roles.
- Para los nuevos permisos usar prefijos, porque `gestionar_solicitudes` en el RAR significa cosas distintas según el módulo. Ejemplos propuestos:

| Permiso central propuesto | Acción |
|---|---|
| `cengisalas.acceder` | Entrar a Salones |
| `cengisalas.solicitudes.crear` | Solicitar una reserva |
| `cengisalas.solicitudes.ver_propias` | Consultar solicitudes propias |
| `cengisalas.solicitudes.gestionar` | Revisar, aprobar, rechazar o reprogramar |
| `cengisalas.salones.gestionar` | Configurar capacidades y tarifas |
| `cengisalas.mantenimiento.gestionar` | Programar mantenimientos |
| `cengisalas.incidencias.gestionar` | Registrar incidencias |
| `cengisalas.reportes.ver` | Consultar reportes |
| `visitas.reservas_salones.crear` | Generar una reserva desde una visita |

Los permisos existentes de Visitas deben mapearse explícitamente a la nueva implementación y conservar sus concesiones actuales. No otorgar permisos nuevos automáticamente a todos los usuarios. El portal tiene un rol central por usuario; los permisos de ambos módulos pueden agruparse en ese rol sin adoptar la matriz individual de Supabase como una segunda autoridad.

El control debe comprobar asignación del módulo y permiso de la acción en servidor. Ocultar tarjetas o botones es insuficiente. Para reservar desde Visitas, definir una autorización específica para esa operación que no conceda administración general de Salones.

### Cambios indispensables en la autorización del RAR

- `modules/salas/js/app.js` decide múltiples funciones con `currentUser.role === 'admin'`.
- Las RPC usan `private.require_admin()` y `private.is_admin()` en acciones como aprobación e incidencias. La matriz de la migración 35 no sustituye todos esos controles por permisos específicos.
- La migración 29 y su trigger asignan módulos según el rol global. Esa asignación automática debe adaptarse para que no contradiga `usuario_modulo`.
- La migración 35 promueve a superadministrador una cuenta por correo fijo. Ese comportamiento no se debe trasladar: el superadministrador se obtiene del portal.
- El puente PHP incluye una compatibilidad que puede recurrir al rol cuando falla la RPC de permisos. En la integración debe fallar sin conceder acceso.
- Las pantallas, RPC y Edge Function que administran cuentas o contraseñas de Cengisalas deben dejar de estar disponibles como una vía paralela de gestión.

## Conservación y migración del historial

Primero hay que verificar la base que realmente contiene el historial. `deploy/env/visitas.env` configura `cengi_visitas` en Docker, pero eso no prueba dónde se encuentran los registros reales. El propio `17-visitas-schema.sql` advierte que su esquema fue reconstruido desde código y debe compararse con la base real.

El importador `scripts/migrate_aiven_to_supabase.php` sirve como referencia, pero no debe ejecutarse sin adaptación:

- Espera `solicitud_areas.id`; el esquema Docker actual define `id_solicitud_area`.
- Copia las columnas de origen sin un mapeo explícito. Por ejemplo, el esquema MySQL de `aprobadores` contiene `correo`, mientras el esquema PostgreSQL mostrado no lo contiene.
- Espera tablas de gestión, fotos, eventos y reservas que no están definidas en el esquema inicial actual de Visitas.
- Su modo `--dry-run` cuenta filas del origen; no valida el esquema ni la compatibilidad del destino.
- El catálogo académico de ambos proyectos reutiliza IDs con significados distintos. Por ejemplo, el ID 1 representa `NA` en el esquema Docker y `Maestria` en el seed del RAR. Importar esos IDs sin reconciliar los catálogos cambia el significado del historial.
- La lista de importación no incluye las tablas nuevas de formularios y visitantes de la migración 34. Debe ampliarse si esos datos existen en alguna fuente.
- Los aprobadores antiguos pueden ser IDs de usuarios centrales, mientras PostgreSQL exige un registro en `visits.aprobadores`. Hay que crear y vincular actores históricos antes de importar las aprobaciones.

Procedimiento propuesto:

1. Obtener respaldos verificables de la base real, los archivos locales y, si ya contiene información, del destino Supabase.
2. Inventariar tablas, columnas, catálogos, IDs, relaciones, rutas y cantidad de documentos. Definir un origen principal y cómo reconciliar datos existentes del destino.
3. Preparar una migración de columnas explícitas, con tabla de correspondencias por origen. Conservar IDs cuando sea seguro y mapearlos cuando haya colisiones; nunca sobrescribir por coincidencia numérica sin comprobar identidad.
4. Migrar solicitudes, solicitantes, áreas, aprobaciones, estados, pagos/museo, registros ocultos y cualquier historial adicional realmente existente. Conservar fechas y autores; registrar actores históricos aunque ya no tengan una cuenta activa.
5. Migrar cartas, agendas, comprobantes, fotografías y listados al almacenamiento elegido. Validar cantidad, tamaño y hash; registrar archivos faltantes para resolverlos antes del cambio.
6. Preservar vínculos visita-reserva. Revisar las reservas ya existentes en Salones; no volver a crearlas al migrar una visita.
7. Comparar conteos por tabla y estado, importes de pagos, fechas, relaciones sin huérfanos y muestras de registros completos. Reiniciar correctamente las secuencias de PostgreSQL.
8. En el cambio definitivo, detener temporalmente las escrituras de Visitas y sus formularios públicos, aplicar el último delta, validar y cambiar las rutas. Mantener la base anterior como respaldo de solo lectura.

Si se regresa a la versión anterior después de haber recibido nuevas solicitudes en la nueva, primero hay que reconciliar esas escrituras. Cambiar únicamente el enlace del menú no constituye una reversión completa.

El borrado de usuarios del portal es actualmente físico. La integración debe conservar el actor histórico auxiliar y desactivar su acceso, evitando eliminar en cascada reuniones o auditoría. No se deben reasignar automáticamente sus acciones a otro usuario.

## Rutas, archivos y Docker

Estructura sugerida: `cengisalas/` para el contenido necesario del RAR, manteniendo sus HTML en esa raíz y Visitas en `cengisalas/modules/visitas/`. Las rutas del menú apuntarían a los accesos adaptados de esa estructura.

Cambios previstos:

| Archivo o área | Cambio |
|---|---|
| `login/Menu.php` | Registrar enlace de Cengisalas y sustituir destino de Visitas |
| `login/config/permisos_roles.php` | Catálogo y agrupación de permisos de ambos módulos |
| `login/usuarios/*`, `login/roles/*` | Sincronización de cambios y revocaciones |
| Nuevo adaptador PHP central | Sesión, correspondencia de identidad y token autorizado |
| `modules/visitas/config/auth.php`, `PermissionManager.php`, `conexion.php` importados | Sesión y permisos del portal; separar conexión central de la operativa |
| `modules/salas/js/app.js`, login y portal importados | Inicio desde sesión central, permisos por acción y navegación |
| Migraciones PostgreSQL nuevas | Proyección de identidad/permisos, controles de RPC/RLS y preservación de historial |
| `dockerfiles/modulos-app/Dockerfile` | Instalar Composer del módulo importado y verificar extensiones requeridas |
| `docker-compose.prod.yml`, `deploy/env/*.example` | Montajes y configuración privada del nuevo módulo |
| Rutas antiguas de `Pruebas` | Compatibilidad selectiva de formularios, archivos y navegación |

El contenedor PHP actual ya instala `pdo_pgsql`. También se debe verificar cURL, requerido por la autenticación auxiliar y Storage. No hace falta crear otro contenedor PHP si ambos módulos se sirven desde `app` y se comparte la sesión central; conservar Supabase tampoco requiere convertirlo en el servicio MySQL del compose.

No copiar `.env`, `.git`, respaldos, `tmp`, herramientas de migración ni archivos de diagnóstico al directorio público. Configurar secretos con archivos montados fuera del control de versiones y denegar acceso web a configuración, SQL y scripts internos. Las claves administrativas nunca van en JavaScript.

El `.htaccess` del RAR redirige todo `Pruebas` a una ruta fija `/demo-salas/...`. No aplicarlo al portal: `Pruebas` también alimenta pagos y servicio técnico, y el menú mantiene otros enlaces allí. Revisar cada ruta; preservar cuerpos POST y enlaces enviados por correo, no aplicar una redirección 302 general a operaciones de escritura.

La creación actual de una reserva desde Visitas hace una RPC remota y después un INSERT local. Son dos pasos y puede quedar una reserva creada sin vínculo si falla el segundo. La integración debe añadir idempotencia y recuperación de esa asociación para que un reintento no genere reservas duplicadas.

## Orden de implementación y validación

1. Construir en un entorno de prueba el acceso central a un usuario ordinario y otro con permisos de administración limitada; comprobar que la sesión del portal no cambia de formato.
2. Implementar la correspondencia de identidades, los permisos por acción y sus revocaciones. Verificar RPC directas, además de la interfaz.
3. Integrar Salones, Visitas, formularios públicos y la relación visita-reserva.
4. Ejecutar la migración adaptada sobre copias y comprobar historial/documentos.
5. Probar el flujo completo y preparar el cambio de rutas con respaldo y procedimiento de reversión.

Pruebas de aceptación mínimas:

- Usuario sin módulo recibe acceso denegado aun escribiendo la URL.
- Usuario de consulta no puede aprobar, editar tarifas ni crear usuarios mediante una llamada directa.
- Encargado de Visitas puede reservar un salón solo si tiene la autorización correspondiente; no obtiene administración global.
- Cambiar rol, quitar módulo, eliminar usuario o cerrar sesión revoca el acceso según el límite documentado y probado.
- Volver de Visitas/Salones al portal conserva el usuario correcto y no altera otros módulos.
- Dos reservas simultáneas incompatibles no son aprobadas; se prueban salones combinados, recurrencias y mantenimientos.
- Un fallo entre creación de reserva y vínculo con visita puede recuperarse sin duplicar datos.
- Se conservan autores, importes, estados, fechas, documentos y enlaces del historial migrado.
- Pagos y las funciones de `Pruebas` ajenas a Visitas siguen funcionando.

Pendientes para ejecutar: identificar y respaldar la base real con historial, conocer los datos ya existentes en Supabase y configurar la confianza del puente en un entorno de prueba. Este análisis no acredita el funcionamiento en producción ni sustituye esas comprobaciones.
