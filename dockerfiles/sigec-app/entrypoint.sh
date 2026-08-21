#!/bin/sh
set -e

# El Dockerfile hace `chown -R www-data:www-data` en tiempo de BUILD, pero
# docker-compose.prod.yml monta el volumen `sigec-storage` en runtime, lo
# que reemplaza el contenido/permisos que tenia esa ruta dentro de la
# imagen (mismo problema documentado en dockerfiles/modulos-app/entrypoint.sh
# para `uploads`). Este script corre como root al iniciar el contenedor
# (antes de que Apache baje privilegios a www-data), asi que corrige la
# propiedad en cada arranque sin depender de como haya quedado el volumen.
if [ -d /var/www/html/sigec/storage ]; then
    chown -R www-data:www-data /var/www/html/sigec/storage 2>/dev/null || true
fi

if [ -d /var/www/html/sigec/bootstrap/cache ]; then
    chown -R www-data:www-data /var/www/html/sigec/bootstrap/cache 2>/dev/null || true
fi

# Regenera el symlink public/storage -> storage/app/public si no existe
# (se excluyo intencionalmente de la copia al repo, ver plan de
# integracion). Idempotente: no repite el link si ya esta creado.
if [ ! -L /var/www/html/sigec/public/storage ]; then
    su -s /bin/sh www-data -c "cd /var/www/html/sigec && php artisan storage:link" 2>/dev/null || true
fi

exec docker-php-entrypoint "$@"
