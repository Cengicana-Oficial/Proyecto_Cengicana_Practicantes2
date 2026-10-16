# Despliegue a producción

Pasos para subir esta versión a un servidor que **ya tiene datos**. Todos los comandos van
desde la raíz del repositorio en el servidor.

> **Nunca** ejecutes los scripts de `deploy/mysql/init/` a mano sobre una base existente:
> `01-usuarios-menu.sql` restablece la contraseña de `developer@cengicana.org` y
> `11-laboratorio-schema.sql` hace `DROP TABLE` de todo el laboratorio. Esos scripts solo
> corren solos cuando el volumen de MySQL es nuevo. Para actualizar una base existente se usa
> `deploy/mysql/actualizar-bd.sh` (paso 4).

## 1. Respaldar los archivos subidos

`uploads/` se monta desde esta carpeta del repo. Los documentos de prueba que estaban
versionados en git se sacaron del repositorio, y `git pull` los borra del disco. Respalda antes:

```bash
tar czf ~/uploads-antes-de-actualizar-$(date +%Y%m%d).tgz uploads
```

## 2. Variables de entorno (`deploy/env/*.env` del servidor)

| Archivo | Variable | Qué hacer |
|---|---|---|
| `login.env` | `COMITES_SECRET_KEY` | **Obligatoria.** Cifra las credenciales de redes sociales de Comités. Generar una vez con `php -r "echo bin2hex(random_bytes(32));"` y no cambiarla después. Sin ella fallan guardar conexiones de redes y renovar el token de LinkedIn. |
| `login.env` | `DB_SOLICITUDES_NAME` | Opcional (por defecto `sistema_solicitudes`). |
| `cengicursos.env` | `CRON_RECORDATORIO_SECRET` | Si los cron se llaman por HTTP, el token va ahora en una cabecera: `curl -H "Authorization: Bearer <token>" https://.../cengicursos/cron_comunicaciones.php`. `?token=` en la URL ya no funciona. Por CLI no cambia nada. |
| `cengicursos.env` | `APP_URL` | Recomendada (ej. `https://modulos.cengicana.org`). Se usa en los enlaces de los correos (crear contraseña del estudiante, diplomas, encuestas). Sin ella se usa el host de la petición. |
| `sigec.env` | `APP_URL`, `SESSION_SECURE_COOKIE` | Verificar que no queden al final las líneas de entorno local (`localhost`, `false`). |

## 3. Código e imagen

El código va dentro de la imagen (`COPY . /var/www/html`), igual que la configuración de Apache
que bloquea PHP en las carpetas `uploads`. Hay que reconstruirla:

```bash
git pull
docker compose -f docker-compose.prod.yml build app sigec
docker compose -f docker-compose.prod.yml up -d
```

## 4. Base de datos

```bash
deploy/mysql/actualizar-bd.sh --listar   # ver qué se va a aplicar
deploy/mysql/actualizar-bd.sh            # respalda en respaldos/ y actualiza
```

El script hace un respaldo completo (`respaldos/antes-de-actualizar-<fecha>.sql`, fuera de git
y de la imagen) y aplica en orden solo archivos idempotentes: se puede repetir sin riesgo y solo
agrega lo que falte. Se detiene en el primer error. Incluye, entre otros:

- Central de usuarios: roles por módulo, `password_resets`, módulos Solicitud de visitas,
  Servicio técnico y Comités, renombre de SIGEC a Experimentos.
- Cursos: todas las migraciones de `cengicursos/migrations/` (planificación, inscripciones por
  convocatoria, pagos y acuerdos de eventos, informe de gestión, proformas…).
- Laboratorio: `laboratorio/database/002`–`005` (documentos versionados, firmas, trazabilidad,
  identificación de muestras, historial de solicitudes, campos por tipo de muestra).
- Solicitud de visitas: la base `cengi_visitas` completa si no existe.

Comités y Solicitudes internas crean y actualizan sus tablas solos al abrirse; los permisos se
siembran desde `login/config/permisos_roles.php`.

## 5. Verificar

- Entrar como superadmin y abrir: Cursos → Planificación, Pagos de inscripción, Proformas,
  Informe de gestión; Laboratorio → Roles y permisos, Trazabilidad; Solicitud de visitas;
  Comités → Redes sociales.
- Con un usuario no superadmin: confirmar que solo administra los usuarios de su módulo.
- `curl -s -o /dev/null -w "%{http_code}" https://.../uploads/x.php` debe dar 403 o 404.

## Cambios que notarán los usuarios

- **Eliminar usuario o rol** muestra una pantalla de confirmación (antes bastaba el enlace).
  Si el usuario también usa módulos que el administrador no gestiona, solo se le quita el acceso
  a ese módulo y la cuenta se conserva.
- **Administración de usuarios por módulo:** solo entra quien tiene el permiso de gestionar
  usuarios de ese módulo, y solo puede asignar roles que no superen el suyo.
- **Estudiantes nuevos:** ya no entran con su CUI. El correo de aprobación o asignación trae un
  enlace «Crear mi contraseña» que vence en 7 días; después pueden usar «¿Olvidaste tu contraseña?».
- **Adjuntos de Solicitudes internas:** solo PDF, JPG, PNG, Word y Excel, de hasta 20 MB.

## Volver atrás

```bash
docker compose -f docker-compose.prod.yml exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' < respaldos/antes-de-actualizar-<fecha>.sql
git checkout <commit-anterior>
docker compose -f docker-compose.prod.yml build app sigec && docker compose -f docker-compose.prod.yml up -d
tar xzf ~/uploads-antes-de-actualizar-<fecha>.tgz   # si hiciera falta
```
