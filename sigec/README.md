# SIGEC
Sistema de Gestión de Ensayos Experimentales de Cengicaña.

## Stack

- PHP 7.4 / Laravel 8
- Vue 2 + Laravel Mix 6
- AdminLTE 3 (jeroennoten/laravel-adminlte)
- spatie/laravel-permission (roles y permisos)
- MySQL 5.7
- Redis + Laravel Horizon
- Docker Compose

Arquitectura calcada de `Cengiportal` (mismo stack base), sustituyendo sus
paquetes privados `csgt/*` por equivalentes publicos (ver detalle en el plan
de scaffolding del proyecto).

## Instalación local (sin Docker)

Requiere PHP 7.4 con extensiones `pdo_mysql`, `gd`, `fileinfo`, `intl`,
`exif`, `mbstring`, `zip`, `curl` habilitadas, MySQL y Node.js.

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan migrate --seed
npm run dev
php artisan serve
```

Usuarios demo (mismo password `password` para todos, un usuario por rol):

```text
admin@sigec.local          administrador
director@sigec.local        director
juan.perez@sigec.local      encargado
maria.lopez@sigec.local     encargado
investigador@sigec.local    investigador
ing.magdalena@sigec.local   ingenio
experto@sigec.local         experto
muestreador@sigec.local     muestreador
muestreador2@sigec.local    muestreador
```

## Instalación con Docker Compose

```bash
cp docker-compose.yml.example docker-compose.yml
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app npm install && docker compose exec app npm run build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

Puertos (ver `docker-compose.yml.example`):

```text
8082  -> app:80   (HTTP)
8888  -> app:8080
33062 -> mysql:3306
```

## Estado del scaffold

Modulos con backend + UI funcional (CRUD completo): Dashboard, Programas,
Proyectos, Ensayos, Variables, Evaluaciones, Archivos (subida real a
storage/app/public), Bitácora, Usuarios y permisos (rol, programas e ingenio
asignables desde la UI), Laboratorio (analitos dinamicos por tipo de
muestra + generacion automatica de id_muestra), Generación de ID de
Muestras (alta por lote + codigos QR imprimibles), Consulta de Muestras
(tracker de ciclo + actualizacion de estado/resultado), Gráficas (series
Highcharts de Evaluaciones y Laboratorio por tratamiento), Formularios de
campo (constructor de campos dinamicos + asignacion a muestreadores) e
Imágenes Geoespaciales (subida real con metadatos, sin visor de bandas),
Reportes (exportación Excel real de Evaluaciones/Muestras de laboratorio +
resumen de ensayo imprimible/PDF via el navegador) e Importar y Analizar
(importación real de Excel/CSV que crea Evaluaciones, emparejando Variable
por nombre y Parcela por código, con reporte de filas omitidas).

Todos los modulos de la lista de navegación tienen ahora backend real —
no quedan placeholders "en construcción".

Pendiente (fuera de alcance, decisiones de infraestructura no tomadas
todavia):
- La "matriz de acceso" por proyecto (tabla proyecto_asignaciones) que el
  prototipo mostraba como grid separado dentro de Usuarios.
- La vista de captura de Respuestas para el rol muestreador (llenar el
  formulario asignado por parcela) — el muestreador hoy solo ve sus
  asignaciones en modo lectura.
- El visor GeoTIFF client-side (banda/paleta/contraste/histograma) del
  prototipo — Imágenes Geoespaciales guarda el archivo real y sus metadatos
  pero no lo visualiza en el navegador.
- El motor de análisis estadístico (ANOVA/Tukey vía Python o R) y la
  generación de reportes narrativos con IA — el prototipo los presentaba
  como simulados; aquí quedan marcados explícitamente como no
  implementados en vez de simularse, ya que requieren decidir como
  ejecutar un motor externo (Python/R) y configurar acceso a la API de
  Claude, respectivamente.

El esquema de base de datos completo (migraciones + modelos Eloquent) ya
existe para todas las entidades del prototipo `SIGEC_v12.html`.
