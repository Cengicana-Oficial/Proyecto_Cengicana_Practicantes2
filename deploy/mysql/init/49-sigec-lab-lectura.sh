#!/bin/bash
# Usuario de SOLO LECTURA con el que SIGEC consulta los resultados del modulo
# Laboratorio (base laboratorios_prueba). SIGEC nunca escribe en esa base.
# Se ejecuta solo al crear el volumen de MySQL por primera vez; en un servidor
# existente use deploy/sigec-usuario-lectura-laboratorio.sh.
# La contrasena viene de SIGEC_LAB_LECTURA_PASSWORD (deploy/env/mysql.env) y debe
# ser la misma que LAB_DB_PASSWORD en deploy/env/sigec.env.
set -e
if [ -z "${SIGEC_LAB_LECTURA_PASSWORD:-}" ]; then
    echo "[49-sigec-lab-lectura] SIGEC_LAB_LECTURA_PASSWORD vacio: no se crea el usuario de lectura."
    exit 0
fi
mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE USER IF NOT EXISTS 'sigec_lab_lectura'@'%' IDENTIFIED BY '${SIGEC_LAB_LECTURA_PASSWORD}';
ALTER USER 'sigec_lab_lectura'@'%' IDENTIFIED BY '${SIGEC_LAB_LECTURA_PASSWORD}';
GRANT SELECT ON laboratorios_prueba.* TO 'sigec_lab_lectura'@'%';
FLUSH PRIVILEGES;
SQL
echo "[49-sigec-lab-lectura] usuario sigec_lab_lectura listo (solo SELECT en laboratorios_prueba)."
