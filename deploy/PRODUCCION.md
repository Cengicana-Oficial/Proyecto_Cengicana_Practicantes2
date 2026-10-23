# Despliegue a producción

Pasos para subir esta versión a un servidor que **ya tiene datos**. Todos los comandos van
desde la raíz del repositorio en el servidor.

> **Nunca** ejecutes los scripts de `deploy/mysql/init/` a mano sobre una base existente:
> `01-usuarios-menu.sql` restablece la contraseña de `developer@cengicana.org` y
> `11-laboratorio-schema.sql` hace `DROP TABLE` de todo el laboratorio. Esos scripts solo
> corren solos cuando el volumen de MySQL es nuevo. Para actualizar una base existente se usa
> `deploy/mysql/actualizar-bd.sh` (paso 5).
>
> Los scripts de `init/` corren en orden alfabético, así que cada uno lleva un prefijo numérico
> **único y correlativo**: uno nuevo toma el siguiente número libre (hoy `63-`), aunque venga de
> otra rama. Si dos ramas usan el mismo número, se renumera al integrar.

## 1. Respaldar los archivos subidos

`uploads/` se monta desde esta carpeta del repo. Los documentos de prueba que estaban
versionados en git se sacaron del repositorio, y `git pull` los borra del disco. Respalda antes:

```bash
tar czf ~/uploads-antes-de-actualizar-$(date +%Y%m%d).tgz uploads
```

## 2. Variables de entorno (`deploy/env/*.env` del servidor)

| Archivo | Variable | Qué hacer |
|---|---|---|
| `mysql.env` | `MIGRADOR_PASSWORD` | **Obligatoria (nueva).** Contraseña de `modulos_migrador`, el único usuario que crea y altera tablas, y solo al desplegar. `modulos_user` (el de la aplicación) queda con permisos de datos. Generarla con `openssl rand -hex 24`. MySQL la toma al recrearse (`up -d mysql`, paso 4). |
| `login.env` | `COMITES_SECRET_KEY` | **Obligatoria.** Cifra las credenciales de redes sociales de Comités. Generar una vez con `php -r "echo bin2hex(random_bytes(32));"` y no cambiarla después. Sin ella fallan guardar conexiones de redes y renovar el token de LinkedIn. |
| `login.env` | `DB_SOLICITUDES_NAME` | Opcional (por defecto `sistema_solicitudes`). |
| `cengicursos.env` | `CRON_RECORDATORIO_SECRET` | Si los cron se llaman por HTTP, el token va ahora en una cabecera: `curl -H "Authorization: Bearer <token>" https://.../cengicursos/cron_comunicaciones.php`. `?token=` en la URL ya no funciona (tampoco en `cron_recordatorio_pago.php`). No puede quedar en `change-me`. Por CLI no cambia nada. |
| `cengicursos.env` | `APP_URL` | **Obligatoria:** `https://modulos.cengicana.org`. Se usa en los enlaces de los correos (crear contraseña del estudiante, diplomas, encuestas, comprobante de pago). Sin ella, el cron de recordatorios de pago no envía (no tiene dominio) y la web usa el host de la petición. |
| `login.env` | `SOLICITUDES_URL` | `https://modulos.cengicana.org/sistema_de_solicitudes`: botón «Ver solicitud» de los correos de Solicitudes internas (el módulo lee `login/.env` en producción). |
| Comités → Configuración → Correos | Dirección del sistema | `https://modulos.cengicana.org/comites`. Ya no se toma ni se guarda sola desde la petición; sin ella, los correos del cron van sin botón. |
| `docker-compose.prod.yml` | `COMITES_CORREO_POR_WORKER` | Ya trae `"1"`: los correos de Comités los envía el servicio `comites-correos-worker` y la página no espera al SMTP. Si se quita el servicio, quitar también esta variable o los correos se quedan en la cola. |
| `docker-compose.prod.yml` | `HOSTS_PERMITIDOS` | Ya trae `modulos.cengicana.org,localhost,127.0.0.1`. Apache responde 403 a cualquier otro `Host`, para que nadie pueda hacer que los enlaces de los correos apunten a otro dominio. Si el sitio se publica con otro nombre, agregarlo aquí. |
| `sigec.env` | `APP_URL`, `SESSION_SECURE_COOKIE` | Verificar que no queden al final las líneas de entorno local (`localhost`, `false`). |

## 3. Código e imagen

El código va dentro de la imagen (`COPY . /var/www/html`), igual que la configuración de Apache
que bloquea PHP en las carpetas `uploads`. Hay que reconstruirla:

```bash
git pull
docker compose -f docker-compose.prod.yml build app sigec recibos-worker comites-correos-worker
```

SIGEC no compila su JavaScript al construir la imagen: usa el `sigec/public/dist/` que está en git.
Quien cambie `sigec/resources/js` debe correr `npm run build` dentro de `sigec/` y versionar el `dist/`
resultante en el mismo commit.

Todavía no levantes los servicios: primero va el corte de zona horaria (paso 4).

## 4. Corte de zona horaria (una sola vez)

Hasta ahora MySQL y PHP corrían en UTC; esta versión usa la hora de Guatemala (`--default-time-zone=-06:00`,
`timezone.ini`, SIGEC en `America/Guatemala`). Las columnas `TIMESTAMP` se ajustan solas. Las `DATETIME`
que llenaba MySQL con `NOW()` tienen hora UTC y hay que restarles 6 horas **antes** de que la aplicación
empiece a guardar hora local y antes de las migraciones del paso 5:

```bash
docker compose -f docker-compose.prod.yml stop app recibos-worker comites-correos-worker sigec
CORTE="$(date -u '+%Y-%m-%d %H:%M:%S')"          # ahora, en UTC
deploy/mysql/corregir-zona-horaria.sh --hasta "$CORTE"            # simula: revisar los conteos
deploy/mysql/corregir-zona-horaria.sh --hasta "$CORTE" --aplicar  # respalda en respaldos/ y corrige
docker compose -f docker-compose.prod.yml up -d mysql             # MySQL con la zona nueva
```

Solo corrige las columnas de su lista (las llenadas por MySQL) y cada una una sola vez (queda anotada en
`usuarios_menu.correccion_zona_horaria`). Al final lista columnas que también llena PHP: esas no se tocan
y hay que revisarlas caso por caso.

## 5. Base de datos

```bash
deploy/mysql/actualizar-bd.sh --listar   # ver qué se va a aplicar
deploy/mysql/actualizar-bd.sh            # respalda en respaldos/ y actualiza
```

Después:

```bash
docker compose -f docker-compose.prod.yml up -d
```

El script hace un respaldo completo (`respaldos/antes-de-actualizar-<fecha>.sql`, fuera de git
y de la imagen) y aplica en orden solo pasos idempotentes: se puede repetir sin riesgo y solo
agrega lo que falte. Se detiene en el primer error. Usa la imagen recién construida
(`docker compose run`), así que no hace falta tener levantados `app` ni `sigec`. Incluye, entre otros:

- Central de usuarios: roles por módulo, `password_resets`, módulos Solicitud de visitas,
  Servicio técnico y Comités, renombre de SIGEC a Experimentos.
- Cursos: todas las migraciones de `cengicursos/migrations/` (planificación, inscripciones por
  convocatoria, pagos y acuerdos de eventos, informe de gestión, proformas…).
- Laboratorio: `laboratorio/database/002`–`005` (documentos versionados, firmas, trazabilidad,
  identificación de muestras, historial de solicitudes, campos por tipo de muestra).
- Solicitud de visitas: la base `cengi_visitas` completa si no existe.
- Permisos (`init/62-permisos-modulos.sh`): crea `modulos_migrador` y deja a `modulos_user` solo
  con permisos de datos en las siete bases.
- Esquema que Comités, Solicitudes internas, Laboratorio y la central de usuarios definen en PHP
  (`deploy/mysql/migrar-esquema.php`, con `modulos_migrador`). Las páginas ya no crean tablas al
  abrirse.
- SIGEC: `php artisan migrate --force` (estaciones meteorológicas, NDVI…), también con `modulos_migrador`.

**Instalación nueva:** el volumen vacío corre `init/` (incluido el 62), pero las tablas de Comités,
Solicitudes y parte de Laboratorio las crea este script: después del primer `up -d mysql`, correr
`deploy/mysql/actualizar-bd.sh` antes de abrir el sistema.

**Para el equipo:** si una pantalla necesita una tabla o columna nueva, va en una migración (Cursos y
Laboratorio: archivo `.sql` y su espejo en `init/`; Comités, Solicitudes y Laboratorio en PHP: dentro
de las funciones de esquema, que solo corren con `ESQUEMA_MIGRANDO`). Con los permisos nuevos, un
`ALTER TABLE` desde una página falla.

## 6. Verificar

- Entrar como superadmin y abrir: Cursos → Planificación, Pagos de inscripción, Proformas,
  Informe de gestión; Laboratorio → Roles y permisos, Trazabilidad; Solicitud de visitas;
  Comités → Redes sociales.
- Con un usuario no superadmin: confirmar que solo administra los usuarios de su módulo.
- `curl -s -o /dev/null -w "%{http_code}" https://.../uploads/x.php` debe dar 403 o 404.
- `https://.../uploads/recibos_firmados/<cualquier archivo>` debe dar 403.
- `curl -s -o /dev/null -w "%{http_code}" -H "Host: otro.ejemplo" http://127.0.0.1:8085/login/` debe dar 403.
- `docker compose -f docker-compose.prod.yml exec app php cengicursos/cron_recordatorio_pago.php --simular`:
  revisar a quién se escribiría antes de programar el cron.
- `docker compose -f docker-compose.prod.yml ps recibos-worker comites-correos-worker` en estado `running`;
  `docker compose -f docker-compose.prod.yml logs comites-correos-worker` no debe decir «SMTP sin configurar».

## Tareas programadas

Dos procesos corren solos como servicios de Docker (`restart: unless-stopped`):

| Servicio | Qué hace |
|---|---|
| `recibos-worker` | Genera y envía los recibos contables de los pagos validados (`cengicursos/cron_recibos_contables.php --loop`). |
| `comites-correos-worker` | Envía la cola de correos de Comités cada minuto y genera los recordatorios de plazos del día (`comites/cron/correos.php --loop`). |

Los de Cursos van en el crontab del servidor, por CLI (no necesitan token):

```cron
0 7 * * *  cd /ruta/al/repo && docker compose -f docker-compose.prod.yml exec -T app php cengicursos/cron_comunicaciones.php
15 7 * * * cd /ruta/al/repo && docker compose -f docker-compose.prod.yml exec -T app php cengicursos/cron_recordatorio_cupo.php
30 7 * * * cd /ruta/al/repo && docker compose -f docker-compose.prod.yml exec -T app php cengicursos/cron_recordatorio_pago.php
```

Antes de activar el de pagos, revisar su salida con `--simular` (paso 6).

## Cambios que notarán los usuarios

- **Eliminar usuario o rol** muestra una pantalla de confirmación (antes bastaba el enlace).
  Si el usuario también usa módulos que el administrador no gestiona, solo se le quita el acceso
  a ese módulo y la cuenta se conserva.
- **Administración de usuarios por módulo:** solo entra quien tiene el permiso de gestionar
  usuarios de ese módulo, y solo puede asignar roles que no superen el suyo.
- **Estudiantes nuevos:** ya no entran con su CUI. El correo de aprobación o asignación trae un
  enlace «Crear mi contraseña» que vence en 7 días; después pueden usar «¿Olvidaste tu contraseña?».
- **Adjuntos de Solicitudes internas:** solo PDF, JPG, PNG, Word y Excel, de hasta 20 MB.
- **Hora:** fechas y horas se muestran en hora de Guatemala en todos los módulos.
- **Códigos de instructor:** se renumeran una vez de forma correlativa (`INS-001`, `INS-002`…) por
  orden de registro. Los documentos ya emitidos conservan el código anterior; la equivalencia está en
  `cengi_cursos.instructor_codigos_secuencia_auditoria`.
- **Asignaciones históricas:** las inscripciones que se crean para asignaciones antiguas quedan con el
  cobro «por revisar». El recordatorio automático de pago no escribe a las órdenes que las incluyen;
  Administración las revisa a mano en Pagos de inscripción.
- **Aula virtual (instructores):** un instructor o maestro solo administra el aula de los cursos que
  imparte (instructor del curso o de alguno de sus módulos). Se le reconoce por el **correo**: el de su
  cuenta debe coincidir con el de su ficha en Instructores; si no, no verá ningún curso. Gestores y
  administradores no cambian.
- **Documentos privados:** recibos firmados y emitidos, facturas, comprobantes de inscripción y archivos
  de instructores ya no se abren por su dirección `/uploads/...`; solo desde el sistema, con sesión y
  permiso. Enlaces copiados antes dejarán de funcionar.
- **Solicitudes internas:** un formulario abierto desde antes del despliegue pide recargar la página.

## Volver atrás

```bash
docker compose -f docker-compose.prod.yml exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' < respaldos/antes-de-actualizar-<fecha>.sql
git checkout <commit-anterior>
docker compose -f docker-compose.prod.yml build app sigec recibos-worker && docker compose -f docker-compose.prod.yml up -d
tar xzf ~/uploads-antes-de-actualizar-<fecha>.tgz   # si hiciera falta
```

El respaldo no incluye los usuarios de MySQL. El código anterior crea tablas al abrir cada página, así
que hay que devolverle los permisos a `modulos_user` (cada base):

```bash
for b in usuarios_menu cengi_cursos laboratorios_prueba cengi_visitas sistema_comites sistema_solicitudes sigec; do
  echo "GRANT ALL PRIVILEGES ON \`$b\`.* TO 'modulos_user'@'%';"
done | docker compose -f docker-compose.prod.yml exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'
```
