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
for dir in \
    /var/www/html/uploads \
    /var/www/html/Pruebas/uploads \
    /var/www/html/sistema_de_solicitudes/uploads
do
    [ -d "$dir" ] || continue
    chown -R www-data:www-data "$dir" 2>/dev/null || true
    find "$dir" -type d -exec chmod 775 {} + 2>/dev/null || true
    find "$dir" -type f -exec chmod 664 {} + 2>/dev/null || true
done

# Varias paginas arman enlaces absolutos (correos, restablecer contrasena, diplomas) con la cabecera
# Host, que la controla el cliente: con un Host falso los enlaces apuntarian a otro dominio. Si
# HOSTS_PERMITIDOS tiene valor (lista separada por comas), Apache responde 403 a cualquier otro Host.
# Vacio (desarrollo local) no se restringe. AUD-2026-001/M-3.
conf_hosts=/etc/apache2/conf-enabled/zz-hosts-permitidos.conf
patron=$(printf '%s' "${HOSTS_PERMITIDOS:-}" | tr ',' '\n' | sed 's/^ *//; s/ *$//; /^$/d; s/[^A-Za-z0-9.-]//g; s/\./\\./g' | paste -sd'|' -)
if [ -n "$patron" ]; then
    cat > "$conf_hosts" <<EOF
<If "%{HTTP_HOST} !~ m#^($patron)(:[0-9]+)?\$#i">
    Require all denied
</If>
EOF
else
    : > "$conf_hosts"
fi

exec docker-php-entrypoint "$@"
