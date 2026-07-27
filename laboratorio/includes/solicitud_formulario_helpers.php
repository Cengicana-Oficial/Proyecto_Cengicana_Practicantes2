<?php

require_once __DIR__ . '/catalogo_muestras_helper.php';

function solicitudColumnExists(PDO $conexion, string $column): bool
{
  $columns = [];

  $stmt = $conexion->query("SHOW COLUMNS FROM solicitud");
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $columnInfo) {
    $columns[strtolower((string) $columnInfo['Field'])] = true;
  }

  return isset($columns[strtolower($column)]);
}

function asegurarColumnasFirmasSolicitud(PDO $conexion): void
{
  $columnas = [
    'correo_ingresado' => 'VARCHAR(255) NULL',
    'correo_recibido' => 'VARCHAR(255) NULL',
    'firma_ingreso' => 'LONGTEXT NULL',
    'firma_recibe' => 'LONGTEXT NULL',
  ];

  foreach ($columnas as $columna => $definicion) {
    if (!solicitudColumnExists($conexion, $columna)) {
      $conexion->exec("ALTER TABLE solicitud ADD COLUMN {$columna} {$definicion}");
    }
  }
}

function normalizarFirmaSolicitud($firma): string
{
  $firma = trim((string) $firma);

  if ($firma === '') {
    return '';
  }

  return preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $firma) ? $firma : '';
}

function labSolicitudFormularioNormalizarTipoMuestra($tipoMuestra): string
{
  if (is_array($tipoMuestra)) {
    if (!empty($tipoMuestra['clave'])) {
      return labCatalogoMuestrasNormalizarTexto((string) $tipoMuestra['clave']);
    }

    if (!empty($tipoMuestra['prefijo']) || !empty($tipoMuestra['nombre'])) {
      return labCatalogoMuestrasClaveDesdePrefijo(
        isset($tipoMuestra['prefijo']) ? (string) $tipoMuestra['prefijo'] : null,
        isset($tipoMuestra['nombre']) ? (string) $tipoMuestra['nombre'] : null
      );
    }
  }

  return labCatalogoMuestrasClaveDesdePrefijo(null, (string) $tipoMuestra);
}

function obtenerDiasCompromiso($tipoMuestra): int
{
  $claveTipo = labSolicitudFormularioNormalizarTipoMuestra($tipoMuestra);

  switch ($claveTipo) {
    case 'suelos':
    case 'foliares':
      return 28;
    case 'agua':
      return 15;
    case 'cana':
    case 'miel':
      return 9;
    default:
      return 0;
  }
}

function calcularFechaEstimadaSolicitud($fechaIngreso, $tipoMuestra): string
{
  $fechaIngreso = trim((string) $fechaIngreso);
  if ($fechaIngreso === '') {
    return '';
  }

  $diasCompromiso = obtenerDiasCompromiso($tipoMuestra);
  if ($diasCompromiso <= 0) {
    return '';
  }

  $fecha = DateTimeImmutable::createFromFormat('Y-m-d', $fechaIngreso);
  if (!$fecha instanceof DateTimeImmutable) {
    $timestamp = strtotime($fechaIngreso);
    if ($timestamp === false) {
      return '';
    }

    $fecha = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
  }

  return $fecha->modify('+' . $diasCompromiso . ' days')->format('Y-m-d');
}

function tipoMuestraNombreDesdeClave($tipo)
{
  $map = [
    'suelos' => 'suelos',
    'suelo-fisico' => 'suelos',
    'suelo-quimico' => 'suelos',
    'foliares' => 'foliares',
    'cana' => 'cañas',
    'miel' => 'mieles',
    'agua' => 'agua',
  ];

  return $map[$tipo] ?? 'suelos';
}

function mesAnioDesdeFecha($fecha)
{
  if (empty($fecha)) return '';

  $timestamp = strtotime($fecha);
  if (!$timestamp) return '';

  return date('m-y', $timestamp);
}

function construirCodigoLab($prefijo, $loteNumero, $mesAnio, $longitudLote)
{
  return strtoupper($prefijo) . '-' . str_pad((string) $loteNumero, $longitudLote, '0', STR_PAD_LEFT) . '-' . $mesAnio;
}

function obtenerTipoMuestra(PDO $conexion, $tipoFormulario)
{
  $tipo = labCatalogoMuestrasObtenerPorClave($conexion, (string) $tipoFormulario, true);

  if ($tipo) {
    return $tipo;
  }

  throw new RuntimeException('El tipo de muestra "' . (string) $tipoFormulario . '" no existe en el catálogo activo del laboratorio.');
}

function obtenerLote(PDO $conexion, $codigoLote)
{
  $stmt = $conexion->prepare("SELECT id_lote FROM lote WHERE codigo_lote = ? LIMIT 1");
  $stmt->execute([$codigoLote]);
  $lote = $stmt->fetch();

  if ($lote) return (int) $lote['id_lote'];

  $insert = $conexion->prepare("INSERT INTO lote (codigo_lote) VALUES (?)");
  $insert->execute([$codigoLote]);

  return (int) $conexion->lastInsertId();
}

function obtenerTipoAnalisis(PDO $conexion, $idTipoMuestra, $nombreAnalisis)
{
  $stmt = $conexion->prepare("
    SELECT id_tipo
    FROM tipo_analisis
    WHERE id_tipo_muestra = ? AND LOWER(nombre) = LOWER(?)
    LIMIT 1
  ");
  $stmt->execute([$idTipoMuestra, $nombreAnalisis]);
  $analisis = $stmt->fetch();

  return $analisis ? (int) $analisis['id_tipo'] : null;
}

function obtenerNumeroCodigoLab($codigoLab)
{
  if (preg_match('/^[A-Z]-([0-9]+)-[0-9]{2}-[0-9]{2}$/i', (string) $codigoLab, $matches)) {
    return (int) $matches[1];
  }

  return null;
}

function obtenerInicioLaboratorioSolicitud(PDO $conexion, $idSolicitud)
{
  if (!$idSolicitud) {
    return null;
  }

  $stmt = $conexion->prepare("SELECT codigo_lab FROM muestra WHERE id_solicitud = ?");
  $stmt->execute([$idSolicitud]);

  $numeros = [];
  foreach ($stmt->fetchAll() as $muestra) {
    $numero = obtenerNumeroCodigoLab($muestra['codigo_lab'] ?? '');
    if ($numero !== null) {
      $numeros[] = $numero;
    }
  }

  return $numeros ? min($numeros) : null;
}

function obtenerInicioLaboratorioNuevo(PDO $conexion, $prefijo, $tipoMuestraNombre, $cantidadMuestras)
{
  $stmt = $conexion->prepare("
    SELECT ultimo_numero
    FROM correlativo_envio_solicitud
    WHERE prefijo = ?
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->execute([strtoupper($prefijo)]);
  $correlativo = $stmt->fetch();

  if (!$correlativo) {
    $insert = $conexion->prepare("
      INSERT INTO correlativo_envio_solicitud (tipo_muestra, prefijo, ultimo_numero, descripcion)
      VALUES (?, ?, ?, ?)
    ");
    $insert->execute([
      $tipoMuestraNombre,
      strtoupper($prefijo),
      491,
      'Correlativo para solicitudes de ' . $tipoMuestraNombre,
    ]);
    $ultimoNumero = 491;
  } else {
    $ultimoNumero = (int) $correlativo['ultimo_numero'];
  }

  $inicio = $ultimoNumero + 1;
  $fin = $inicio + $cantidadMuestras - 1;

  $update = $conexion->prepare("
    UPDATE correlativo_envio_solicitud
    SET ultimo_numero = ?
    WHERE prefijo = ?
  ");
  $update->execute([$fin, strtoupper($prefijo)]);

  return $inicio;
}

function sincronizarCorrelativo(PDO $conexion, $prefijo, $ultimoNumero)
{
  $stmt = $conexion->prepare("
    UPDATE correlativo_envio_solicitud
    SET ultimo_numero = ?
    WHERE prefijo = ?
  ");
  $stmt->execute([$ultimoNumero, strtoupper($prefijo)]);
}

function sincronizarCorrelativosConMuestras(PDO $conexion)
{
  $stmt = $conexion->query("
    SELECT
      UPPER(SUBSTRING_INDEX(codigo_lab, '-', 1)) AS prefijo,
      MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(codigo_lab, '-', 2), '-', -1) AS UNSIGNED)) AS ultimo_numero
    FROM muestra
    WHERE codigo_lab IS NOT NULL AND codigo_lab <> ''
    GROUP BY UPPER(SUBSTRING_INDEX(codigo_lab, '-', 1))
  ");

  $update = $conexion->prepare("
    UPDATE correlativo_envio_solicitud
    SET ultimo_numero = ?
    WHERE prefijo = ?
  ");

  foreach ($stmt->fetchAll() as $row) {
    if (!empty($row['prefijo']) && $row['ultimo_numero'] !== null) {
      $update->execute([(int) $row['ultimo_numero'], strtoupper($row['prefijo'])]);
    }
  }
}

function obtenerRango(PDO $conexion, $idLote, $inicio, $fin)
{
  $stmt = $conexion->prepare("SELECT id_rango FROM lote_rango WHERE id_lote = ? AND inicio = ? AND fin = ? LIMIT 1");
  $stmt->execute([$idLote, $inicio, $fin]);
  $rango = $stmt->fetch();

  if ($rango) return (int) $rango['id_rango'];

  $insert = $conexion->prepare("INSERT INTO lote_rango (id_lote, inicio, fin) VALUES (?, ?, ?)");
  $insert->execute([$idLote, $inicio, $fin]);

  return (int) $conexion->lastInsertId();
}

function insertarLoteAnalisis(PDO $conexion, $idRango, $idTipoAnalisis)
{
  $stmt = $conexion->prepare("SELECT id FROM lote_analisis WHERE id_rango = ? AND id_tipo_analisis = ? LIMIT 1");
  $stmt->execute([$idRango, $idTipoAnalisis]);

  if ($stmt->fetch()) return;

  $insert = $conexion->prepare("INSERT INTO lote_analisis (id_rango, id_tipo_analisis, estado) VALUES (?, ?, ?)");
  $insert->execute([$idRango, $idTipoAnalisis, 'Pendiente']);
}
