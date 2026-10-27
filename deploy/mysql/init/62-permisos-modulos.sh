#!/bin/bash
# =====================================================================
# Permisos de MySQL de la aplicacion (AUD-2026-001/I-2).
#
#   modulos_user      (MYSQL_USER) lo usan las paginas: solo datos (SELECT, INSERT, UPDATE,
#                     DELETE...). Ninguna peticion puede crear, alterar ni borrar tablas.
#   modulos_migrador  lo usan deploy/mysql/migrar-esquema.php y "php artisan migrate" de SIGEC,
#                     solo al desplegar. Su contrasena es MIGRADOR_PASSWORD en deploy/env/mysql.env.
#
# Corre solo como ultimo script de una instalacion nueva y deploy/mysql/actualizar-bd.sh lo
# vuelve a aplicar en cada despliegue (los scripts 12, 13, 17 y 18 que ese repite dan permisos).
# Es idempotente. Funciona ejecutado o con "source" (docker-entrypoint hace cualquiera de los dos).
# =====================================================================

permisos_modulos() {
  local app="${MYSQL_USER:-modulos_user}" migrador="${MIGRADOR_USER:-modulos_migrador}" clave b
  if [ -z "${MIGRADOR_PASSWORD:-}" ]; then
    echo "Falta MIGRADOR_PASSWORD en deploy/env/mysql.env (contrasena de $migrador)." >&2
    return 1
  fi
  # Literal SQL: se duplican comillas simples y barras invertidas.
  clave=$(printf '%s' "$MIGRADOR_PASSWORD" | sed -e 's/\\/\\\\/g' -e "s/'/''/g")
  {
    echo "CREATE USER IF NOT EXISTS '$migrador'@'%' IDENTIFIED BY '$clave';"
    echo "ALTER USER '$migrador'@'%' IDENTIFIED BY '$clave';"
    # El entrypoint de MySQL da ALL sobre MYSQL_DATABASE con el "_" escapado.
    echo "REVOKE IF EXISTS ALL PRIVILEGES ON \`usuarios\\_menu\`.* FROM '$app'@'%' IGNORE UNKNOWN USER;"
    for b in usuarios_menu cengi_cursos laboratorios_prueba cengi_visitas sistema_comites sistema_solicitudes sigec; do
      echo "GRANT ALL PRIVILEGES ON \`$b\`.* TO '$migrador'@'%';"
      echo "REVOKE IF EXISTS ALL PRIVILEGES ON \`$b\`.* FROM '$app'@'%' IGNORE UNKNOWN USER;"
      echo "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE ON \`$b\`.* TO '$app'@'%';"
    done
    echo "FLUSH PRIVILEGES;"
  } | MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot
}

permisos_modulos
