# SalaFlow — demo local

Prototipo interactivo de solicitud y administración de salones de reuniones.

## Abrir con XAMPP

Con Apache iniciado, visita:

`http://localhost/EncuestasSatisfacci%C3%B3n/demo-salas/`

También puedes abrir `index.html` directamente. No utiliza base de datos ni necesita instalar paquetes.

## Accesos ficticios

- Administrador: `admin@salaflow.demo` / `admin123`
- Usuario: `usuario@salaflow.demo` / `usuario123`

## Incluye

- Agenda diaria para Salón 1 a Salón 8.
- Solicitudes con configuración Tipo U o Tipo Escuela.
- Capacidad diferente según la configuración.
- Reserva combinada de Salón 1 + Salón 2.
- Bloqueo y validación de conflictos en ambos salones cuando se combinan.
- Aprobación, rechazo y cambio de salón para el administrador.
- Consulta y cancelación de reuniones propias.
- Datos ficticios y diseño adaptable a dispositivos móviles.

Los cambios se guardan solamente en memoria y se reinician al recargar la página. La demo sirve para validar diseño y funcionamiento antes de conectarla a PHP y MySQL.
