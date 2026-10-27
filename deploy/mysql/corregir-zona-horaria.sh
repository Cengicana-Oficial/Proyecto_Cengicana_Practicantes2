#!/usr/bin/env bash
# =====================================================================
# Corte de zona horaria UTC -> America/Guatemala (-06:00). AUD-2026-001/M-1.
#
# Hasta el despliegue de 09eb925 MySQL corria en UTC: lo que guardo NOW() / CURRENT_TIMESTAMP en
# columnas DATETIME es hora UTC. Despues del corte se guarda hora de Guatemala, asi que esas columnas
# quedarian mezcladas. Este script resta 6 horas a los valores anteriores al corte, SOLO en las
# columnas de la lista COLUMNAS (llenadas por MySQL, no por el usuario ni por PHP).
#
# No se tocan:
#   - columnas TIMESTAMP: MySQL las guarda en UTC y las convierte solo a la zona de la sesion;
#   - fechas que escribe el usuario (horarios, plazos, fecha de muestreo...): ya son hora local;
#   - columnas que tambien llena PHP con date(): varias paginas ya usaban America/Guatemala
#     (date_default_timezone_set), asi que no se sabe que filas estan en UTC. Se listan en REVISAR
#     para que el equipo decida.
#
# Cada columna se corrige UNA sola vez: queda registrada en usuarios_menu.correccion_zona_horaria y
# una segunda ejecucion la salta.
#
# Procedimiento (ver deploy/PRODUCCION.md):
#   1. Detener app y recibos-worker (docker compose -f docker-compose.prod.yml stop app recibos-worker).
#   2. deploy/mysql/corregir-zona-horaria.sh --hasta "AAAA-MM-DD HH:MM:SS"            (simula)
#   3. deploy/mysql/corregir-zona-horaria.sh --hasta "AAAA-MM-DD HH:MM:SS" --aplicar  (respalda y corrige)
#   4. Recrear mysql y app con la configuracion nueva.
# --hasta es el momento del corte en hora UTC (la hora de los datos viejos): solo se corrigen
# valores menores a ese instante.
# =====================================================================
set -euo pipefail
cd "$(dirname "$0")/../.."

HASTA=""
APLICAR=0
while [ $# -gt 0 ]; do
  case "$1" in
    --hasta) HASTA="${2:-}"; shift 2 ;;
    --aplicar) APLICAR=1; shift ;;
    *) echo "Opcion desconocida: $1" >&2; exit 2 ;;
  esac
done
if ! [[ "$HASTA" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}\ [0-9]{2}:[0-9]{2}:[0-9]{2}$ ]]; then
  echo 'Uso: deploy/mysql/corregir-zona-horaria.sh --hasta "AAAA-MM-DD HH:MM:SS" [--aplicar]' >&2
  exit 2
fi

mysql_exec() {
  if [ -n "${CONTENEDOR:-}" ]; then
    docker exec -i "$CONTENEDOR" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec "$0" -uroot "$@"' "$@"
  else
    docker compose -f docker-compose.prod.yml exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec "$0" -uroot "$@"' "$@"
  fi
}

# base.tabla.columna: DATETIME llenadas solo por MySQL (DEFAULT CURRENT_TIMESTAMP o NOW() en el codigo).
COLUMNAS=(
  cengi_cursos.evento_encuesta_colab.creado
  cengi_cursos.evento_encuesta_percep.creado
  cengi_cursos.eventos.percep_enviada_en
  cengi_cursos.liquidaciones.anulada_en
  cengi_cursos.liquidaciones.aprobada_en
  cengi_cursos.liquidaciones.pagada_en
  cengi_cursos.pagos_grupales.verificado_en
  cengi_cursos.solicitudes_inscripcion.asignado_en
  cengi_cursos.solicitudes_inscripcion.pago_registrado_en
  cengi_cursos.solicitudes_inscripcion.pago_verificado_en
  cengi_cursos.solicitudes_inscripcion.validacion_ingenio_en
  cengi_cursos.solicitudes_inscripcion.validacion_solicitada_en
  cengi_visitas.aprobacion_solicitud.fecha_aprobacion
  cengi_visitas.solicitudes_ocultas.fecha_ocultamiento
  laboratorios_prueba.formulario_version.fecha
  laboratorios_prueba.lab_documento.generado_en
  laboratorios_prueba.lab_documento_historial.fecha
  laboratorios_prueba.lab_evento_trazabilidad.fecha
  laboratorios_prueba.lab_firma_documento.fecha_firma
  laboratorios_prueba.lab_informe.fecha_firma
)

# Llenadas por NOW() y tambien por PHP (o solo por PHP): no se corrigen; decidir caso por caso.
REVISAR=(
  cengi_cursos.comunicaciones.enviado_en
  cengi_cursos.diplomas.enviado_en
  cengi_cursos.evento_acuerdo_historial.creado
  cengi_cursos.evento_acuerdo_pagos.creado
  cengi_cursos.evento_acuerdo_recibos.cargado_en
  cengi_cursos.evento_acuerdo_recibos.enviado_en
  cengi_cursos.evento_acuerdo_recibos.solicitado_en
  cengi_cursos.evento_acuerdos.acept_archivo_fecha
  cengi_cursos.evento_acuerdos.propuesta_enviada_en
  cengi_cursos.evento_aliados.enviado_en
  cengi_cursos.evento_participantes.confirmado_en
  cengi_cursos.evento_participantes.gafete_enviado_en
  cengi_cursos.evento_participantes.ingreso_en
  cengi_cursos.evento_participantes.pago_recordatorio_en
  cengi_cursos.evento_participantes.pago_validado_en
)

mysql_exec mysql usuarios_menu -e "CREATE TABLE IF NOT EXISTS correccion_zona_horaria (
  columna VARCHAR(200) NOT NULL PRIMARY KEY, filas INT NOT NULL, hasta DATETIME NOT NULL,
  aplicado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"

if [ "$APLICAR" = 1 ]; then
  mkdir -p respaldos
  archivo="respaldos/antes-de-zona-horaria-$(date +%Y%m%d-%H%M%S).sql"
  echo "Respaldo completo en $archivo ..."
  bases=$(mysql_exec mysql -N -e "SELECT GROUP_CONCAT(schema_name SEPARATOR ' ') FROM information_schema.SCHEMATA WHERE schema_name IN ('usuarios_menu','cengi_cursos','laboratorios_prueba','cengi_visitas')")
  # shellcheck disable=SC2086
  mysql_exec mysqldump --single-transaction --routines --triggers --databases $bases > "$archivo"
  tail -1 "$archivo" | grep -q "Dump completed" || { echo "El respaldo no termino bien; no se corrige nada." >&2; exit 1; }
fi

[ "$APLICAR" = 1 ] && echo "Corrigiendo (restar 6 h a valores < '$HASTA'):" || echo "SIMULACION (nada se modifica). Valores < '$HASTA' a los que se restarian 6 h:"
for c in "${COLUMNAS[@]}"; do
  IFS=. read -r base tabla columna <<< "$c"
  existe=$(mysql_exec mysql -N -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$base' AND TABLE_NAME='$tabla' AND COLUMN_NAME='$columna' AND DATA_TYPE='datetime'")
  if [ "$existe" != 1 ]; then printf '  %-60s no existe en esta base, se omite\n' "$c"; continue; fi
  hecho=$(mysql_exec mysql usuarios_menu -N -e "SELECT COUNT(*) FROM correccion_zona_horaria WHERE columna='$c'")
  if [ "$hecho" = 1 ]; then printf '  %-60s ya corregida antes, se omite\n' "$c"; continue; fi
  filas=$(mysql_exec mysql "$base" -N -e "SELECT COUNT(*) FROM \`$tabla\` WHERE \`$columna\` < '$HASTA'")
  if [ "$APLICAR" = 1 ]; then
    # Las columnas ON UPDATE CURRENT_TIMESTAMP de la tabla se asignan a si mismas para que la
    # correccion no les ponga la hora actual.
    conservar=$(mysql_exec mysql -N -e "SELECT IFNULL(GROUP_CONCAT(CONCAT(', \`', COLUMN_NAME, '\` = \`', COLUMN_NAME, '\`') SEPARATOR ''), '') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$base' AND TABLE_NAME='$tabla' AND COLUMN_NAME<>'$columna' AND EXTRA LIKE '%on update%'")
    mysql_exec mysql "$base" -e "START TRANSACTION;
      UPDATE \`$tabla\` SET \`$columna\` = \`$columna\` - INTERVAL 6 HOUR$conservar WHERE \`$columna\` < '$HASTA';
      INSERT INTO usuarios_menu.correccion_zona_horaria (columna, filas, hasta) VALUES ('$c', $filas, '$HASTA');
      COMMIT;"
  fi
  printf '  %-60s %s fila(s)\n' "$c" "$filas"
done

echo
echo "Columnas para revisar a mano (no se corrigen; llenadas tambien por PHP):"
for c in "${REVISAR[@]}"; do echo "  $c"; done
