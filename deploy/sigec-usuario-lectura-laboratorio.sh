#!/bin/bash
# Crea (o actualiza) en un servidor YA instalado el usuario de SOLO LECTURA con el
# que SIGEC consulta el modulo Laboratorio. No modifica el modulo Laboratorio:
# solo agrega un usuario de MySQL con permiso SELECT sobre su base.
#
# Uso (desde la raiz del proyecto, en el servidor):
#   bash deploy/sigec-usuario-lectura-laboratorio.sh
#
# Toma la contrasena de LAB_DB_PASSWORD en deploy/env/sigec.env (si no existe, la
# genera y la agrega a ese archivo) y la base de LAB_DB_DATABASE.
set -euo pipefail
cd "$(dirname "$0")"
ENV_SIGEC="env/sigec.env"
CONTENEDOR="${MYSQL_CONTAINER:-cengicana-ui-new-mysql}"

valor() { grep -E "^$1=" "$ENV_SIGEC" | tail -1 | cut -d= -f2-; }

PASS="$(valor LAB_DB_PASSWORD || true)"
BASE="$(valor LAB_DB_DATABASE || true)"
BASE="${BASE:-laboratorios_prueba}"
if [ -z "$PASS" ]; then
    PASS="$(head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)"
    printf '\n# Modulo Laboratorio: conexion de SOLO LECTURA (usuario con permiso SELECT unicamente)\nLAB_DB_HOST=mysql\nLAB_DB_PORT=3306\nLAB_DB_DATABASE=%s\nLAB_DB_USERNAME=sigec_lab_lectura\nLAB_DB_PASSWORD=%s\n' "$BASE" "$PASS" >> "$ENV_SIGEC"
    echo "Se agregaron las variables LAB_DB_* a $ENV_SIGEC."
fi

docker exec -i -e LAB_PASS="$PASS" -e LAB_BASE="$BASE" "$CONTENEDOR" sh -c '
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" 2>/dev/null <<SQL
CREATE USER IF NOT EXISTS '"'"'sigec_lab_lectura'"'"'@'"'"'%'"'"' IDENTIFIED BY '"'"'$LAB_PASS'"'"';
ALTER USER '"'"'sigec_lab_lectura'"'"'@'"'"'%'"'"' IDENTIFIED BY '"'"'$LAB_PASS'"'"';
GRANT SELECT ON \`$LAB_BASE\`.* TO '"'"'sigec_lab_lectura'"'"'@'"'"'%'"'"';
FLUSH PRIVILEGES;
SQL
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -e "SHOW GRANTS FOR '"'"'sigec_lab_lectura'"'"'@'"'"'%'"'"'" 2>/dev/null'
echo "Listo. Reinicie o recargue SIGEC para que lea las variables nuevas del .env."
