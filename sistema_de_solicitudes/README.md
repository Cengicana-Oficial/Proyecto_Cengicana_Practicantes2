# Sistema de solicitudes CENGICANA

Aplicacion PHP + MySQL para registrar, consultar y gestionar solicitudes internas.

## Estructura

- `index.php`: pantalla principal y procesamiento de formularios.
- `config/database.php`: conexion a MySQL.
- `includes/functions.php`: funciones auxiliares.
- `assets/css/styles.css`: estilos separados.
- `assets/js/app.js`: filtros y comportamiento del formulario.
- `database/schema.sql`: base de datos, tablas y datos iniciales para importar en Workbench.
- `uploads/`: archivos adjuntos subidos desde el formulario.

## Crear la base de datos en Workbench

1. Abre XAMPP Control Panel.
2. Inicia `MySQL`.
3. Abre MySQL Workbench y conectate al servidor local.
4. Abre el archivo `database/schema.sql`.
5. Ejecuta todo el script.

El script crea la base `sistema_solicitudes` con estas tablas:

- `programas`
- `usuarios`
- `solicitudes`
- `seguimientos`
- `adjuntos`

## Configuracion

La conexion por defecto esta en `config/database.php`. El proyecto tambien puede leer un archivo `.env` ubicado en `C:\xampp\htdocs\.env`, usando el mismo formato del otro proyecto:

```text
DB_HOST=127.0.0.1
DB_PORT=3307
DB_NAME=usuarios_menu
DB_USER=root
DB_PASS=
DB_SOLICITUDES_NAME=sistema_solicitudes

DB_MENU_HOST=127.0.0.1
DB_MENU_PORT=3307
DB_MENU_NAME=usuarios_menu
DB_MENU_USER=root
DB_MENU_PASS=
```

Si existe `.env`, primero se usan las variables `DB_MENU_*` y luego `DB_*` para host, puerto, usuario y contrasena. La base de esta app siempre es `sistema_solicitudes`, salvo que definas `DB_SOLICITUDES_NAME` o `SISTEMA_SOLICITUDES_DB_NAME`.

La app busca `.env` en este orden:

- `C:\xampp\htdocs\sistema de solicitudes\.env`
- `C:\xampp\htdocs\.env`
- `C:\xampp\htdocs\Proyecto_Cengicana_Practicantes2_github\login\.env`

Si no existe ningun `.env`, la app usa por defecto `127.0.0.1`, puerto `3307`, base `sistema_solicitudes`, usuario `root` y contrasena vacia.

La app crea la base automaticamente si no existe. Las tablas y datos iniciales se crean importando `database/schema.sql`.

## Abrir la app

Con Apache y MySQL encendidos en XAMPP, abre:

```text
http://localhost/sistema%20de%20solicitudes/
```

## Usuarios y roles

La app usa esta jerarquia:

- `super_admin`: puede ver todo, gestionar solicitudes, modificar usuarios y asignar permisos individuales.
- `administrador`: puede ver todo, gestionar solicitudes y modificar usuarios, pero no puede asignar permisos individuales.
- `direccion`: puede ver todo y gestionar solicitudes.
- `gestor`: puede ver todo y gestionar solicitudes.
- `solicitante`: puede crear solicitudes y ver las solicitudes del programa que tiene asignado.

Los permisos individuales disponibles para el super admin son:

- Crear solicitudes
- Gestionar solicitudes
- Ver solicitudes del area
- Ver todas las solicitudes
- Administrar usuarios

En la creacion/edicion de usuarios, el apartado de permisos aparece al seleccionar estos roles:

- `solicitante`
- `gestor`
- `direccion`

Para `administrador` y `super_admin`, los permisos se aplican automaticamente por jerarquia.

Regla de visibilidad:

- Sin permiso especial, un solicitante ve solo las solicitudes que el mismo creo.
- Con `Ver solicitudes del area`, ve todas las solicitudes cuyo programa origen sea su programa asignado.
- Con `Ver todas las solicitudes`, ve solicitudes de todos los programas.

## Reglas de solicitudes

- Si el tipo es `compra` o `ti`, la solicitud se asigna automaticamente a `Administracion`.
- Si el tipo es `apoyo`, la solicitud sale del programa del usuario y se envia al programa destino seleccionado.
- Las solicitudes en estado `completado` ya no pueden cambiar de estado.

Usuario super admin inicial:

```text
superadmin@cengicana.org
```

Contrasena demo de los usuarios iniciales:

```text
hash
```
