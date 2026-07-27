# Auditoria tecnica del proyecto CENGICANA

Fecha de revision: 2026-06-22  
Repositorio revisado: `Cengicana-Oficial/Proyecto_Cengicana_Practicantes2`  
Rama revisada: `main`  
Ultimo commit local revisado: `1981e33 Merge pull request #5 from cesargg56/medina`

## 1. Resumen ejecutivo

El proyecto contiene una plataforma web institucional desarrollada principalmente en PHP, orientada a centralizar procesos de CENGICANA. El repositorio incluye cuatro areas principales:

- `login/`: autenticacion, usuarios, roles, permisos, modulos e ingenios.
- `cengicursos/`: gestion de cursos, participantes, solicitudes de inscripcion, aprobaciones, asignaciones y reportes.
- `Pruebas/`: modulo de solicitud de visitas, administracion de solicitudes, pagos, areas y envio de correos.
- `laboratorio/`: modulo tecnico de laboratorio para solicitudes, muestras, analisis, formularios, revision, consolidacion y exportaciones.

La idea funcional del proyecto es valiosa y existe trabajo importante ya desarrollado. Sin embargo, el repositorio no esta listo para subirse tal como esta a produccion. Tiene problemas de seguridad, arquitectura, despliegue, organizacion, versionado de datos y dependencia de tecnologia obsoleta.

El veredicto tecnico es:

**No se recomienda subir el proyecto actual directamente a produccion.**  
**Si es viable llevarlo a produccion despues de una fase de limpieza, estabilizacion y endurecimiento tecnico.**

## 2. Objetivo de la auditoria

El objetivo de esta auditoria es evaluar el estado general del proyecto desde el punto de vista de:

- buenas practicas de desarrollo
- seguridad
- arquitectura
- mantenibilidad
- despliegue
- claridad del alcance
- viabilidad de puesta en produccion
- posibilidad de modernizacion futura

La revision se realizo sobre el codigo disponible en el repositorio clonado localmente. No se ejecuto la aplicacion completa porque el entorno local no tiene `php` ni `composer` en el PATH, y Docker Desktop no tenia activo el motor Linux al momento de probar `docker compose build`.

## 3. Alcance funcional detectado

### 3.1 Modulo `login/`

Este modulo funciona como la base central de acceso. Maneja:

- inicio de sesion
- usuarios
- roles
- permisos
- modulos disponibles
- ingenios
- menu principal
- vinculacion de usuarios con modulos

Puntos positivos:

- usa `password_hash()` y `password_verify()` en el flujo principal de login
- usa consultas preparadas con PDO en varias partes
- intenta centralizar permisos mediante tablas como `usuarios`, `roles`, `modulos`, `usuario_modulo`, `permisos` y `rol_permiso`

Puntos criticos:

- todavia contiene referencias a modulos fuera del alcance actual, como `Pruebas`, `Laboratorio`, `Solicitud de visitas`, `Ensayos` y `Pago`
- mezcla logica de negocio, SQL, HTML y permisos en archivos de vista
- no existe una capa clara de controladores, servicios o repositorios
- parte de los permisos registrados corresponden a modulos que tal vez ya no se desean incluir

### 3.2 Modulo `cengicursos/`

Este modulo gestiona cursos y procesos relacionados:

- cursos
- categorias
- ingenios
- participantes
- carga de participantes por CSV
- solicitudes de inscripcion
- aprobacion y rechazo de solicitudes
- asignacion de participantes a cursos
- consulta por rol e ingenio
- reportes y exportaciones

Puntos positivos:

- tiene una funcionalidad clara y aprovechable
- existe integracion con el login central mediante sesion y permisos
- `revisar_permisos.php` centraliza buena parte de la autorizacion del modulo
- varias consultas ya usan `prepare()`
- hay reglas de visibilidad por rol e ingenio

Puntos criticos:

- conviven codigo moderno y codigo legacy
- existe una autenticacion antigua dentro del propio modulo
- hay uso de `md5()` en archivos relacionados con usuarios
- hay mezcla de PDO, mysqli, SQL directo, HTML y reglas de negocio
- hay carpeta `_Respaldo/` con duplicacion de codigo
- hay SQL versionado con datos de ejemplo y contrasenas hash MD5
- el Docker actual usa versiones obsoletas de PHP, MySQL y Nginx

### 3.3 Modulo `Pruebas/`

Aunque el nombre sugiere un area temporal, este modulo parece implementar un sistema de solicitud de visitas a CENGICANA:

- formulario publico de solicitud
- subida de cartas y listados
- panel administrativo
- estados de solicitudes
- areas de interes
- pagos
- envio de correos

Puntos criticos:

- el nombre `Pruebas` no comunica que sea un modulo funcional
- contiene uploads versionados
- contiene archivos enviados por usuarios o pruebas
- depende del login central
- agrega complejidad importante si se incluye en el alcance de produccion

### 3.4 Modulo `laboratorio/`

Este modulo es el mas grande y tecnicamente mas elaborado. Gestiona procesos de laboratorio:

- solicitudes
- muestras
- tipos de analisis
- aguas
- suelos
- cana
- foliares
- mieles
- formularios tecnicos
- curvas e historiales
- blanco y control
- consolidacion
- exportacion a Excel/PDF

Puntos positivos:

- tiene una estructura mas cercana a MVC: `controllers`, `models`, `views`, `includes`, `config`
- tiene separacion funcional mas clara que otros modulos
- maneja muchas reglas de negocio del laboratorio

Puntos criticos:

- agrega alta complejidad al proyecto completo
- tiene sus propias bases SQL versionadas
- mantiene acoplamiento con el login
- no parece estar listo para combinarse sin una fase de integracion formal

## 4. Interconexion entre modulos

Los modulos si se interconectan, principalmente a traves de `login/`.

El patron general es:

1. El usuario inicia sesion en `login/login.php`.
2. Se guardan datos de sesion: usuario, correo, rol, rol_id, ingenio, modulos y permisos.
3. `login/Menu.php` muestra accesos segun los modulos asignados.
4. Los modulos como `cengicursos`, `Pruebas` y `laboratorio` leen la sesion y consultan la base `usuarios_menu`.

Esto demuestra una intencion correcta: tener un login central y modulos separados. Sin embargo, la interconexion actual esta implementada con acoplamiento directo entre carpetas, rutas relativas y archivos compartidos sin una capa comun formal.

Ejemplos de acoplamiento:

- `cengicursos` lee datos del login y usa la base de usuarios.
- `Pruebas` lee variables del `.env` de `login`.
- `laboratorio` importa conexion desde `login/config/conexion.php`.
- `login/Menu.php` contiene rutas hardcodeadas a varios modulos.

## 5. Hallazgos principales

### 5.1 Credenciales y secretos en el repositorio

Se detecto una contrasena de MySQL directamente en `cengicursos/docker-compose.yml`:

- `MYSQL_ROOT_PASSWORD=...`

Esto no debe estar versionado. Las credenciales deben manejarse por variables de entorno y archivos `.env` no versionados.

### 5.2 Datos y archivos sensibles versionados

El repositorio contiene archivos que no deberian estar en una rama de produccion sin revision:

- archivos `.sql`
- certificados `ca.pem`
- archivos `.zip`
- `composer.phar`
- uploads de documentos en `Pruebas/uploads`
- respaldos completos en `_Respaldo`

Esto representa riesgo de seguridad, fuga de informacion, peso innecesario del repositorio y confusion operativa.

### 5.3 Uso de tecnologia obsoleta

El entorno Docker de `cengicursos` usa:

- `php:7.1-fpm`
- `mysql:5.7`
- `nginx:1.13`

Estas versiones son antiguas para un entorno de produccion moderno. PHP 7.1 esta fuera de soporte desde hace anos. Esto implica riesgos de seguridad, compatibilidad y mantenimiento.

### 5.4 Uso de `md5()` para contrasenas

El flujo principal de `login` usa `password_hash()`, lo cual es correcto. Pero todavia existen archivos en `cengicursos` que usan `md5()` para contrasenas, especialmente en codigo legacy o flujos antiguos de usuarios.

Esto debe eliminarse antes de produccion.

### 5.5 Duplicacion y codigo legacy

La carpeta `cengicursos/_Respaldo/` duplica gran parte del modulo. Esto no debe estar dentro del despliegue final.

Tambien existe un login antiguo en `cengicursos`:

- `Login_v6/`
- `Lou_login.php`
- `classes/auth/Lou_login.class.php`
- `classes/auth/Lou_registo.php`

Si el sistema usara `login/` como autenticacion central, estos archivos deben retirarse o aislarse para evitar rutas alternativas de autenticacion.

### 5.6 Arquitectura inconsistente

El proyecto no sigue una arquitectura uniforme:

- `laboratorio` tiene estructura por controladores, modelos y vistas.
- `cengicursos` mezcla PHP procedural, clases antiguas, HTML, SQL y permisos.
- `login` mezcla consultas, vistas, reglas de permisos y redirecciones.
- `Pruebas` tiene estructura propia.

Esto dificulta:

- mantenimiento
- pruebas
- incorporacion de nuevos desarrolladores
- seguridad
- despliegue
- migracion a framework

### 5.7 Falta de pruebas automatizadas

No se detecto una suite clara de pruebas automatizadas. Para produccion, al menos deberian existir pruebas sobre:

- login
- permisos
- creacion de usuarios
- restricciones por modulo
- flujos principales de cursos
- solicitudes de inscripcion
- aprobacion y rechazo

### 5.8 Falta de documentacion operativa

No se encontro una documentacion central clara que explique:

- requisitos del sistema
- instalacion
- variables de entorno
- bases de datos necesarias
- comandos de despliegue
- usuarios iniciales
- migraciones o seeds
- configuracion de Docker
- alcance funcional real

### 5.9 Exposicion de errores internos

Hay varios `die()` que muestran detalles de error o mensajes directos. En produccion, los errores deben registrarse en logs y mostrarse al usuario de forma controlada.

### 5.10 Rutas hardcodeadas

Hay rutas relativas y referencias directas a carpetas especificas. Esto hace fragil el despliegue si cambia la estructura del servidor.

## 6. Evaluacion por area

| Area | Estado | Observacion |
|---|---|---|
| Idea funcional | Buena | El producto tiene sentido institucional. |
| Login central | Aceptable | Tiene buena base, pero requiere limpieza. |
| Gestion de cursos | Aprovechable | Funcionalidad clara, pero con deuda tecnica. |
| Seguridad | Riesgo medio/alto | Secretos, MD5, archivos sensibles y datos versionados. |
| Arquitectura | Inconsistente | Falta patron comun y separacion clara de responsabilidades. |
| Mantenibilidad | Media/baja | Hay duplicacion, legacy y mezcla de estilos. |
| Despliegue | No listo | Docker viejo, sin documentacion completa y sin entorno verificado. |
| Produccion | No recomendado aun | Requiere saneamiento previo. |
| Viabilidad de mejora | Alta | Hay una base funcional rescatable. |

## 7. Veredicto de produccion

### 7.1 Se puede subir asi a produccion?

**No se recomienda.**

El proyecto no deberia subirse tal como esta a produccion por las siguientes razones:

1. Contiene credenciales en archivos versionados.
2. Contiene datos, SQL dumps, certificados, respaldos y uploads que deben revisarse.
3. Usa tecnologia obsoleta en Docker.
4. Mantiene codigo legacy de autenticacion paralelo al login central.
5. Tiene uso de `md5()` en flujos relacionados con usuarios.
6. No tiene documentacion operativa suficiente.
7. No hay pruebas automatizadas detectadas.
8. La arquitectura no esta estabilizada.
9. Hay rutas hacia modulos que podrian no formar parte del alcance real.
10. El despliegue no fue validado en un entorno limpio.

Subirlo asi podria provocar:

- exposicion de informacion sensible
- errores por rutas rotas
- problemas de autenticacion
- accesos no deseados
- dificultad para diagnosticar fallos
- alto costo de mantenimiento
- deuda tecnica creciente

### 7.2 Es viable llevarlo a produccion?

**Si, es viable.**

La razon principal es que el proyecto ya tiene una base funcional real:

- existe una idea de plataforma modular
- existe un login central
- existen permisos y roles
- el modulo de cursos tiene flujos funcionales definidos
- hay trabajo avanzado en vistas, consultas y reglas por rol
- la logica del negocio esta presente, aunque de forma dispersa

No se recomienda descartarlo. La estrategia correcta es estabilizarlo.

## 8. Recomendacion de alcance

Segun la indicacion recibida, si el alcance real es solo:

- `login/`
- `cengicursos/`

entonces se recomienda retirar temporalmente de produccion:

- `Pruebas/`
- `laboratorio/`

Esto reduce mucho el riesgo y permite entregar un sistema mas coherente:

**Sistema de gestion de cursos CENGICANA con login centralizado.**

Esta decision mejora la viabilidad porque:

- baja la complejidad tecnica
- reduce dependencias cruzadas
- simplifica permisos
- evita modulos no requeridos
- permite enfocar pruebas
- facilita despliegue inicial

## 9. Plan recomendado de saneamiento

### Fase 1: Limpieza de repositorio

- Crear una rama de trabajo.
- Retirar o excluir `_Respaldo/`.
- Retirar `Login_v6/` y autenticacion antigua si ya no se usara.
- Retirar uploads reales o de prueba.
- Revisar y retirar `.sql` con datos sensibles.
- Retirar zips, `composer.phar` y archivos generados.
- Mantener solo scripts SQL necesarios como migraciones limpias o seeds anonimizados.

### Fase 2: Seguridad y configuracion

- Mover credenciales a `.env`.
- Crear `.env.example`.
- Asegurar que `.env` este en `.gitignore`.
- Reemplazar `md5()` por `password_hash()`.
- Eliminar rutas alternativas de login.
- Revisar sesiones y permisos.
- Evitar mensajes de error internos visibles al usuario.

### Fase 3: Unificacion funcional

- Dejar `login` como unica autenticacion.
- Hacer que `cengicursos` use exclusivamente usuarios del login.
- Eliminar o adaptar referencias a tabla vieja `users` si ya no corresponde.
- Limpiar `login/Menu.php` para mostrar solo modulos vigentes.
- Limpiar permisos ajenos al alcance actual.

### Fase 4: Modernizacion de entorno

- Actualizar Docker a versiones soportadas.
- Definir servicios claros: web, php, base de datos.
- Documentar puertos, variables y comandos.
- Verificar build en un entorno limpio.

### Fase 5: Pruebas

Validar manualmente y, si es posible, automatizar:

- inicio de sesion
- cierre de sesion
- creacion de usuarios
- roles y permisos
- acceso al modulo cursos
- restriccion por ingenio
- creacion/edicion de cursos
- carga de participantes
- solicitud de inscripcion
- aprobacion y rechazo
- reportes principales

### Fase 6: Documentacion

Crear documentacion minima:

- README principal
- guia de instalacion
- guia de despliegue
- variables de entorno
- estructura de base de datos
- usuarios/roles esperados
- alcance funcional

## 10. Framework o modernizacion futura

Una migracion futura a framework es recomendable, especialmente a Laravel, por ser el framework PHP mas natural para este tipo de proyecto.

Laravel aportaria:

- rutas centralizadas
- controladores
- middleware de autenticacion
- migraciones
- seeders
- validacion de formularios
- Eloquent ORM
- Blade
- manejo de archivos
- colas para correos
- testing
- estructura estandar para futuros desarrolladores

Sin embargo, no se recomienda reescribir todo de inmediato. La mejor estrategia es:

1. estabilizar lo actual
2. limpiar seguridad y alcance
3. documentar funcionamiento
4. migrar gradualmente cuando los flujos esten claros

## 11. Priorizacion de acciones

### Prioridad critica

- sacar credenciales del repositorio
- eliminar `md5()` activo
- retirar autenticacion legacy
- limpiar archivos sensibles
- definir `.env.example`
- limitar alcance a `login + cengicursos`

### Prioridad alta

- limpiar menu y permisos de modulos no usados
- eliminar `_Respaldo`
- unificar conexion y usuarios
- actualizar Docker
- documentar instalacion

### Prioridad media

- refactorizar archivos grandes
- separar vistas/logica/SQL
- agregar pruebas
- mejorar manejo de errores
- preparar migracion futura a Laravel

## 12. Conclusion

El proyecto tiene una base funcional y representa una buena idea: centralizar procesos de CENGICANA en una plataforma modular. El trabajo realizado por los practicantes es aprovechable y no deberia descartarse.

No obstante, el repositorio actual no cumple todavia con las condiciones minimas recomendables para produccion. La principal preocupacion no es la idea del sistema, sino su estado tecnico: seguridad, arquitectura, limpieza, despliegue y mantenibilidad.

El camino recomendado es no subirlo tal como esta, sino realizar una fase corta de saneamiento y estabilizacion enfocada en el alcance real. Si el alcance confirmado es `login + cengicursos`, la viabilidad aumenta considerablemente.

**Veredicto final:**  
El proyecto es viable para produccion despues de una etapa de limpieza, seguridad, estabilizacion y documentacion. No es recomendable publicarlo en produccion en su estado actual.
