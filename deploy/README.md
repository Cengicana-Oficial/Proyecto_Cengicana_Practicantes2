# Despliegue con Docker

Ejecutar los comandos desde la raiz del proyecto, con Docker Desktop iniciado
en modo de contenedores Linux.

## Configuracion de esta maquina

En esta maquina el portal integrado funciona en el puerto 8085. Las
migraciones de bases existentes no se ejecutan automaticamente al recrear
los contenedores.

- Aplicacion: http://localhost:8085
- SIGEC: http://localhost:8085/sigec/login (tambien accesible desde el menu).
- MySQL: `127.0.0.1:33063`; dentro de Docker se usa `mysql:3306`.
- Los archivos `deploy/env/*.env` contienen la configuracion local y estan
  excluidos de Git y de las imagenes. Conservarlos junto con los respaldos.
- SMTP requiere completar las variables `MAIL_*` de los modulos que envian
  correo. Los valores de ejemplo no permiten enviar mensajes.

## Iniciar o actualizar

```powershell
docker compose -f docker-compose.prod.yml up -d --build --wait --wait-timeout 180
docker compose -f docker-compose.prod.yml exec -T -u www-data sigec php artisan migrate --force
```

En una instalacion nueva, crear los permisos y catalogos basicos de SIGEC:

```powershell
docker compose -f docker-compose.prod.yml exec -T -u www-data sigec php artisan db:seed --class=RolePermissionSeeder --force
docker compose -f docker-compose.prod.yml exec -T -u www-data sigec php artisan db:seed --class=IngenioSeeder --force
docker compose -f docker-compose.prod.yml exec -T -u www-data sigec php artisan db:seed --class=AnaliticoPorTipoSeeder --force
```

El esquema de los otros modulos se crea con `deploy/mysql/init` unicamente
cuando el volumen de MySQL esta vacio. Los cambios posteriores en esos SQL
requieren aplicar sus migraciones correspondientes a la base existente.

Para instalar los roles de CengiSalas en otra maquina que ya tenga la
integracion con Supabase, ejecutar:

```powershell
docker compose -f docker-compose.prod.yml exec -T app php deploy/migrate_cengisalas_manager.php --apply
```

Despues, ejecutar una vez en el editor SQL de Supabase
`cengisalas/database/supabase/38_salas_role_presets.sql`. El archivo solo
actualiza los perfiles de CengiSalas; no modifica los roles de Visitas.

Un administrador de CengiSalas entra a Administracion > Roles y permisos,
elige un usuario registrado en el portal de Modulos y asigna uno de tres
perfiles fijos: Usuario estandar (cuatro opciones de Principal), Gestor
(Principal, Solicitudes pago y Reporteria) o Administrador (panel completo).
Los superadministradores del portal mantienen acceso completo y no se editan
desde esa pantalla. Los permisos individuales antiguos ya no se aplican.

En Administracion > Usuarios de CengiSalas, un administrador tambien puede
crear una cuenta nueva. La cuenta se registra en el portal de Modulos con un
rol base dedicado sin permisos globales y solo la asignacion `CengiSalas` en
`usuario_modulo`. El rol elegido (Estandar, Gestor o Administrador) se guarda
para CengiSalas; cualquier otro modulo debe asignarlo despues un
superadministrador del portal. El alta no envia la contrasena por correo.

## Estado, registros y parada

```powershell
docker compose -f docker-compose.prod.yml ps
docker compose -f docker-compose.prod.yml logs --tail=100
docker compose -f docker-compose.prod.yml stop
docker compose -f docker-compose.prod.yml start
```

MySQL y los archivos publicos de SIGEC se conservan en volumenes Docker;
los adjuntos de los demas modulos se guardan en `uploads/` del proyecto.
`docker compose down` conserva los volumenes; agregar `-v` borra la base
de datos y el almacenamiento de SIGEC.

## Otra maquina o dominio publico

Copiar cada `deploy/env/*.env.example` a su correspondiente archivo `.env`,
configurar la misma clave de MySQL en todos los modulos y generar secretos
propios para MySQL, SSO, cron y `APP_KEY` de Laravel. Los dos valores de
`SIGEC_SSO_SECRET` deben coincidir.

Para un dominio HTTPS, ajustar `SIGEC_URL` en `login.env` y `APP_URL` en
`sigec.env` a `https://DOMINIO/sigec`, establecer
`SESSION_SECURE_COOKIE=true` y configurar un proxy HTTPS hacia
`127.0.0.1:8085`. Los puertos del compose estan limitados al equipo local.
Despues de modificar los archivos de configuracion, ejecutar:

```powershell
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml exec -T -u www-data sigec php artisan config:clear
```

## Visitas independiente

Visitas vive en `visitas/` y comparte las cuentas del portal. Aplicar `php deploy/migrate_visitas.php --apply` después de la migración de acceso de CengiSalas para consolidar los registros anteriores. El panel de roles está en `/login/visitas_roles.php`. Ver [instalación, roles y pruebas](../visitas/README.md).
