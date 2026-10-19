# Roles y alcance de Cursos

| Rol | Alcance | Trabajo principal |
| --- | --- | --- |
| Gestor de capacitación de ingenio | Solo el ingenio asignado en su cuenta | Validar interesados del ingenio, consultar próximos cursos, seguimiento de participantes y estudiantes, consultar proformas y pagos del ingenio. |
| Coordinador de capacitación | Todos los ingenios de Cursos | Administración completa, usuarios, cursos, pagos y liquidaciones. |
| Jefe de transferencia de tecnología | Todos los ingenios de Cursos | Administración completa, usuarios, cursos, pagos, liquidaciones y firma. |

El gestor no emite proformas ni valida pagos contables; consulta los documentos de su ingenio y valida el patrocinio de los interesados. Desde Usuarios puede ayudar con las cuentas de estudiantes de su ingenio, sin asignarles roles administrativos. Sin ingenio asignado, el alcance institucional queda cerrado.

Próximos cursos presenta la oferta aprobada o reprogramada por iniciar. Esa oferta puede consultarse antes de que existan participantes del ingenio. Los datos de personas y documentos de pago conservan el filtro institucional.

Crear cuentas: Usuarios → Nuevo usuario, seleccionar el rol de Cursos y el ingenio. Se requiere nombre, correo y contraseña de al menos 8 caracteres con letras y números. El formulario de Cursos verifica el permiso del operador y obliga al gestor a utilizar su propio ingenio y el rol de estudiante.

Base existente: ejecutar login/config/migracion_roles_cursos.sql en usuarios_menu. Para instalaciones nuevas, deploy/mysql/init/43-cengicursos-roles-alcance.sql aplica los mismos roles. Esta migración no crea cuentas personales ni cambia sus contraseñas.