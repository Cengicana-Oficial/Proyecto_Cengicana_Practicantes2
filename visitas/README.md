# Visitas

Módulo independiente de solicitudes de visitas. Entrada desde /login/Menu.php o /visitas/; formulario público en /visitas/public/solicitud_visita.php.

Comparte usuarios e inicio de sesión con el portal y CengiSalas. Conserva la base de datos de visitas, los archivos de Supabase Storage y la integración para solicitar salones. Las cuentas creadas en Visitas inician sesión con el mismo correo y contraseña en Módulos.

## Roles

El panel /login/visitas_roles.php permite crear usuarios del portal y asignar cuatro roles:

- Asistente Dirección: acceso completo.
- Director General: acceso completo.
- Coordinador de Visitas: acceso completo.
- Gestor de Pagos: únicamente Reportes y Pagos, incluida la exportación CSV.

Los tres primeros roles y los superadministradores pueden crear usuarios y administrar accesos. El alta pide nombre, correo, contraseña y confirmación, institución, departamento opcional y rol. Registra la cuenta en Módulos y vincula su perfil compartido; asigna únicamente Visitas. Las asignaciones de usuarios existentes usan cengisalas_module_access con module_code=visitas y conservan el acceso a otros módulos. Los roles base creados desde Visitas no conceden permisos globales.

El Gestor de Pagos tiene una lista explícita de rutas permitidas en el servidor; los reportes no incluyen datos internos de las otras secciones.

La autorización se revalida contra el portal en cada petición privada. La cookie CENGISALAS_VISIT conserva la identidad técnica necesaria para reservar salones. Los permisos de Visitas se sincronizan con Supabase al ingresar. Los administradores no pueden cambiar su propio acceso ni el de superadministradores.

## Instalación y actualización

1. Construir la imagen con docker compose -f docker-compose.prod.yml build app.
2. Iniciar con docker compose -f docker-compose.prod.yml up -d app.
3. Ejecutar docker compose -f docker-compose.prod.yml exec app php deploy/migrate_visitas.php --check y después --apply. La migración es repetible y unifica Visitas CengiSalas y Solicitud de visitas conservando asignaciones; los roles explícitos tienen prioridad.
4. Ejecutar php deploy/migrate_visitas_roles.php --apply. Instala los cuatro roles en ambas bases. Convierte admin a Coordinador de Visitas, user a Asistente Dirección y payments a Gestor de Pagos, solo en Visitas.
5. Ejecutar php deploy/check_visitas.php y php deploy/test_visitas.php dentro del contenedor; la segunda prueba debe ejecutarse con -u www-data. Crea y elimina cuentas temporales en el portal y el proveedor de identidad, prueba asignaciones, alta, restricciones, ingreso con contraseña y reportes y usa una sesión temporal del superadministrador para validar SSO, sin enviar correos.

La configuración privada sigue montándose desde deploy/env/cengisalas.env en visitas/.env. VISITS_PUBLIC_URL puede indicar el origen o la carpeta /visitas. Las dependencias usan el volumen visitas-vendor; después de cambiar composer.lock ejecutar composer install --no-dev dentro de /var/www/html/visitas. Los adjuntos locales persisten en uploads/visitas.

Apache redirige las rutas anteriores /cengisalas/modules/visitas/ y /Pruebas/public/ al único módulo nuevo, preservando parámetros y método HTTP. La carpeta Pruebas sigue disponible para el módulo de servicio técnico.

## Diseño

Las pantallas comparten la navegación y cabecera de `config/presentation.php`, el tema `assets/module-theme.css` y la navegación móvil de `assets/module-shell.js`. La paleta, los tamaños y las tipografías Inter/Space Grotesk siguen los módulos de Cursos y Solicitudes internas. El tema se carga al final para adaptar también las tablas, los formularios y los diálogos heredados.
