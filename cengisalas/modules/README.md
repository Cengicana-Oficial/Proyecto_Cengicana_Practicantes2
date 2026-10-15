# Modulos de Cengicaña

La autenticacion, los perfiles y los permisos se centralizan en Supabase. Cada
modulo conserva su propia logica y puede evolucionar sin duplicar usuarios.

## Estructura

- `salas`: JavaScript y CSS de agenda, solicitudes, administración, portal y
  tablero. Sus HTML públicos permanecen en la raíz para conservar las URLs.
- `visitas`: aplicacion PHP de solicitudes de visitas, con sus propios assets,
  configuracion, vistas y dependencias de Composer.

Las rutas publicas de la raiz son deliberadas: funcionan como la capa `public/`
del repositorio y evitan romper enlaces ya compartidos.
