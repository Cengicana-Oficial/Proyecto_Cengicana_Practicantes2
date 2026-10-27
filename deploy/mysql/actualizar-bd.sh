#!/usr/bin/env bash
# =====================================================================
# Actualiza el esquema de una base EXISTENTE (produccion o local) a la version del codigo.
#
# Los scripts de deploy/mysql/init/ solo corren cuando el volumen de MySQL es NUEVO. Sobre una
# base que ya tiene datos NO se debe ejecutar el init completo:
#   - 01-usuarios-menu.sql restablece la contrasena de developer@cengicana.org al hash del repo;
#   - 11-laboratorio-schema.sql hace DROP TABLE de todo el laboratorio (borra sus datos);
#   - deploy/mysql/seed/ son datos de prueba.
# Este script aplica en orden solo archivos IDEMPOTENTES (CREATE TABLE IF NOT EXISTS, ALTER
# condicionados, INSERT IGNORE / WHERE NOT EXISTS): se puede ejecutar las veces que haga falta
# y solo agrega lo que falte. Antes hace un respaldo completo.
#
# Uso (desde la raiz del repo, en el servidor):
#   deploy/mysql/actualizar-bd.sh                 # respalda y actualiza
#   deploy/mysql/actualizar-bd.sh --listar        # solo muestra que se aplicaria
#   deploy/mysql/actualizar-bd.sh --sin-respaldo  # no recomendado
# Por defecto usa "docker compose -f docker-compose.prod.yml exec mysql" (y "run" de app y sigec
# para el esquema PHP y las migraciones de SIGEC). Para contenedores ya levantados:
#   CONTENEDOR=cengicana-ui-new-mysql CONTENEDOR_APP=... CONTENEDOR_SIGEC=... deploy/mysql/actualizar-bd.sh
#
# Despues de los .sql aplica los permisos (init/62: modulos_user solo datos, modulos_migrador
# para el esquema), el esquema que Comites, Solicitudes, Laboratorio y la central definen en PHP
# (deploy/mysql/migrar-esquema.php) y "php artisan migrate" de SIGEC. Requiere MIGRADOR_PASSWORD
# en deploy/env/mysql.env. AUD-2026-001/I-2.
#
# Al agregar una migracion nueva: crear su archivo idempotente, su espejo en init/ y, si no
# esta en una carpeta que ya se recorre abajo, agregarla a PASOS.
# El espejo de init/ toma el siguiente prefijo numerico libre; no se repiten numeros.
# =====================================================================
set -euo pipefail
cd "$(dirname "$0")/../.."

LISTAR=0
RESPALDO=1
for arg in "$@"; do
  case "$arg" in
    --listar) LISTAR=1 ;;
    --sin-respaldo) RESPALDO=0 ;;
    *) echo "Opcion desconocida: $arg" >&2; exit 2 ;;
  esac
done

mysql_exec() {
  if [ -n "${CONTENEDOR:-}" ]; then
    docker exec -i "$CONTENEDOR" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec "$0" -uroot "$@"' "$@"
  else
    docker compose -f docker-compose.prod.yml exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec "$0" -uroot "$@"' "$@"
  fi
}

# Comando dentro del contenedor de MySQL.
en_mysql() {
  if [ -n "${CONTENEDOR:-}" ]; then
    docker exec -i "$CONTENEDOR" "$@"
  else
    docker compose -f docker-compose.prod.yml exec -T mysql "$@"
  fi
}

# PHP de los modulos con la imagen nueva; MIGRADOR_PASS viaja por el entorno, no en la linea de comandos.
en_app() {
  if [ -n "${CONTENEDOR:-}" ]; then
    docker exec -i -e MIGRADOR_PASS "${CONTENEDOR_APP:-cengicana-ui-new-app}" "$@"
  else
    docker compose -f docker-compose.prod.yml run --rm --no-deps -T -e MIGRADOR_PASS app "$@"
  fi
}

# Artisan de SIGEC como www-data (no deja logs de root en storage/), con el usuario de migraciones.
en_sigec() {
  if [ -n "${CONTENEDOR:-}" ]; then
    docker exec -i -u www-data -e DB_USERNAME -e DB_PASSWORD "${CONTENEDOR_SIGEC:-cengicana-ui-new-sigec}" "$@"
  else
    docker compose -f docker-compose.prod.yml run --rm --no-deps -T -u www-data -e DB_USERNAME -e DB_PASSWORD sigec "$@"
  fi
}

# Esquema definido en PHP, un modulo por proceso (deploy/mysql/migrar-esquema.php).
MODULOS_PHP=(login comites solicitudes laboratorio)

# base|archivo, en orden. Las bases las crean los propios archivos cuando hace falta.
PASOS=(
  # Central de usuarios. 32 renombra SIGEC -> Experimentos ANTES de 13, que inserta
  # "Experimentos": al reves quedarian los dos modulos.
  "usuarios_menu|deploy/mysql/init/36-renombrar-sigec-experimentos.sql"
  "usuarios_menu|deploy/mysql/init/12-laboratorio-grants.sql"
  "usuarios_menu|deploy/mysql/init/13-sigec-schema.sql"
  "usuarios_menu|deploy/mysql/init/17-visitas-schema.sql"
  "usuarios_menu|deploy/mysql/init/18-comites-grants.sql"
  "usuarios_menu|deploy/mysql/init/19-password-resets.sql"
  "usuarios_menu|login/config/migracion_roles_por_modulo.sql"
  # Roles de Cursos (Gestor de capacitacion, Coordinador, Jefe de transferencia) y permiso de firma.
  "usuarios_menu|login/config/migracion_roles_cursos.sql"
)
for f in cengicursos/migrations/*.sql; do PASOS+=("cengi_cursos|$f"); done
for f in laboratorio/database/[0-9][0-9][0-9]_*.sql; do PASOS+=("laboratorios_prueba|$f"); done
# Comites (sistema_comites) y Solicitudes internas (sistema_solicitudes) definen su esquema en PHP
# (config/database.php e includes/*_preparar); lo aplica migrar-esquema.php al final, no las paginas.

if [ "$LISTAR" = 1 ]; then
  for p in "${PASOS[@]}"; do echo "  ${p%%|*}  <-  ${p#*|}"; done
  echo "  (permisos)  <-  deploy/mysql/init/62-permisos-modulos.sh"
  for m in "${MODULOS_PHP[@]}"; do echo "  (php)       <-  deploy/mysql/migrar-esquema.php $m"; done
  echo "  sigec       <-  php artisan migrate"
  exit 0
fi

if [ "$RESPALDO" = 1 ]; then
  mkdir -p respaldos
  archivo="respaldos/antes-de-actualizar-$(date +%Y%m%d-%H%M%S).sql"
  echo "Respaldo completo en $archivo ..."
  bases=$(mysql_exec mysql -N -e "SELECT GROUP_CONCAT(schema_name SEPARATOR ' ') FROM information_schema.SCHEMATA WHERE schema_name IN ('usuarios_menu','cengi_cursos','laboratorios_prueba','cengi_visitas','sistema_comites','sistema_solicitudes','sigec')")
  # shellcheck disable=SC2086
  mysql_exec mysqldump --single-transaction --routines --triggers --databases $bases > "$archivo"
  tail -1 "$archivo" | grep -q "Dump completed" || { echo "El respaldo no termino bien; no se actualiza nada." >&2; exit 1; }
fi

total=${#PASOS[@]}
n=0
for p in "${PASOS[@]}"; do
  n=$((n + 1))
  base=${p%%|*}
  archivo=${p#*|}
  printf '[%2d/%d] %-20s %s\n' "$n" "$total" "$base" "$archivo"
  # La base puede no existir aun (la crea el archivo con su USE / CREATE DATABASE).
  existe=$(mysql_exec mysql -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE schema_name = '$base'")
  if [ "$existe" = 1 ]; then
    mysql_exec mysql "$base" < "$archivo" > /dev/null || { echo "ERROR en $archivo: se detiene la actualizacion (lo anterior ya quedo aplicado; el respaldo esta en respaldos/)." >&2; exit 1; }
  else
    mysql_exec mysql < "$archivo" > /dev/null || { echo "ERROR en $archivo: se detiene la actualizacion (lo anterior ya quedo aplicado; el respaldo esta en respaldos/)." >&2; exit 1; }
  fi
done

echo "[permisos] deploy/mysql/init/62-permisos-modulos.sh"
en_mysql bash -s < deploy/mysql/init/62-permisos-modulos.sh || { echo "ERROR en los permisos: revisar MIGRADOR_PASSWORD en deploy/env/mysql.env y recrear mysql (up -d mysql)." >&2; exit 1; }
MIGRADOR_PASS=$(en_mysql printenv MIGRADOR_PASSWORD | tr -d '\r')
export MIGRADOR_PASS
for m in "${MODULOS_PHP[@]}"; do
  echo "[php] migrar-esquema.php $m"
  en_app php /var/www/html/deploy/mysql/migrar-esquema.php "$m" || { echo "ERROR en el esquema de $m: se detiene la actualizacion (el respaldo esta en respaldos/)." >&2; exit 1; }
done
echo "[sigec] php artisan migrate"
DB_USERNAME=modulos_migrador DB_PASSWORD="$MIGRADOR_PASS" en_sigec php artisan migrate --force || { echo "ERROR en las migraciones de SIGEC." >&2; exit 1; }
echo "Listo: $total archivos, permisos, esquema PHP y SIGEC aplicados."
