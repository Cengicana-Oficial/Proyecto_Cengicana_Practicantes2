# Docker local: Servicios Técnicos

Desde la raíz del proyecto:

```powershell
powershell -ExecutionPolicy Bypass -File Pruebas/.docker/start.ps1
```

## Base local

Con el contenedor `cengicana-ui-new-mysql` iniciado, preparar la base una vez:

```powershell
powershell -ExecutionPolicy Bypass -File Pruebas/.docker/init-db.ps1
```

Crea la base MySQL `servicios_tecnicos` y las tablas `Encuestas` y `Respuestas`
según `schema.sql`. El usuario `servicios_tecnicos_app` puede consultar y modificar
datos únicamente en esa base. Las credenciales aleatorias se guardan en
`private/database.json`, excluido de Git y bloqueado para HTTP. El script puede
repetirse sin borrar registros ni cambiar las credenciales existentes.

`Conexion.php` lee esa configuración desde el montaje privado. Los datos MySQL
persisten en el volumen existente `cengicana-ui-new-mysql-data`. Las cuentas de
usuario siguen en Módulos; los permisos, tokens e historial siguen en SQLite.
Respaldar también `private`, `uploads` y la base `servicios_tecnicos`.

Verificar MySQL con operaciones temporales que se revierten al terminar:

```powershell
docker exec --user www-data cengicana-ui-new-app php /var/www/html/Pruebas/.docker/check-db.php
```

## Montaje del módulo

Esta configuración conecta la carpeta local `Pruebas` directamente al contenedor.
No necesita reconstruir la imagen para ver cambios en los archivos del módulo.
Conserva los montajes de los otros módulos y sustituye los montajes antiguos de
`Pruebas/.env` y `Pruebas/uploads`.

El script genera `compose.yml` a partir de la configuración principal vigente,
conservando también los montajes nuevos de otros módulos, como Visitas.
Recrea únicamente `app`; no reconstruye imágenes ni reinicia las bases de datos.
Para revisar la configuración sin recrear el contenedor, agregar `-PrepareOnly`.

El almacenamiento SQLite de permisos e historial se guarda en `.docker/private`
y se monta fuera de la raíz pública del contenedor. Apache bloquea el acceso HTTP
a `.docker` mediante `.htaccess`. Respaldar `private` junto con MySQL y `uploads`.

El entrypoint estándar de PHP evita que recrear este servicio cambie permisos en
las carpetas de adjuntos de otros módulos. Los permisos de estas se conservan.

Usar este script al recrear el servicio. Usar solamente `docker-compose.prod.yml`
omite el montaje de Servicios Técnicos y vuelve a servir la copia antigua de la
imagen: `/Pruebas/index.php` devuelve 404. La configuración principal no se modifica
porque el trabajo de este módulo está limitado a la carpeta `Pruebas`.
