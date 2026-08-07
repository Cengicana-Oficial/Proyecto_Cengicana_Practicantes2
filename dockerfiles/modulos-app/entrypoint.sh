#!/bin/sh
set -e

# El Dockerfile hace `chown -R www-data:www-data /var/www/html` en tiempo de
# BUILD, pero docker-compose.prod.yml monta `./uploads` como bind mount en
# tiempo de RUNTIME. Un bind mount reemplaza por completo el contenido y los
# permisos que tenia esa ruta dentro de la imagen, asi que el chown del build
# nunca llega a aplicarse a los archivos reales servidos desde el host. Si el
# directorio del host no es escribible por www-data (uid 33 en esta imagen),
# move_uploaded_file() falla en silencio (p. ej. al subir el CV de un
# instructor en cengicursos/instructores.php).
#
# Este script corre como root al iniciar el contenedor (antes de que Apache
# baje privilegios a www-data), asi que puede corregir la propiedad/permisos
# del volumen montado en cada arranque, sin depender de como haya quedado
# configurado el directorio en el host.
if [ -d /var/www/html/uploads ]; then
    chown -R www-data:www-data /var/www/html/uploads 2>/dev/null || true
    find /var/www/html/uploads -type d -exec chmod 775 {} + 2>/dev/null || true
    find /var/www/html/uploads -type f -exec chmod 664 {} + 2>/dev/null || true
fi

exec docker-php-entrypoint "$@"
