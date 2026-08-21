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

function labSolicitudPermisoEdicionPorTipo($tipoMuestra): ?string
{
  $claveTipo = labSolicitudFormularioNormalizarTipoMuestra($tipoMuestra);

  $permisos = [
    'foliares' => 'laboratorio.solicitudes.editar.foliar',
    'suelos' => 'laboratorio.solicitudes.editar.suelo',
    'suelo-fisico' => 'laboratorio.solicitudes.editar.suelo',
    'suelo-quimico' => 'laboratorio.solicitudes.editar.suelo',
    'agua' => 'laboratorio.solicitudes.editar.aguas',
    'cana' => 'laboratorio.solicitudes.editar.cana',
  ];

  return $permisos[$claveTipo] ?? null;
}

function labSolicitudPuedeEditarTipo($tipoMuestra): bool
{
  if (!function_exists('lab_can')) {
    return false;
  }

  if (lab_can('laboratorio.solicitudes.editar')) {
    return true;
  }

  $permiso = labSolicitudPermisoEdicionPorTipo($tipoMuestra);

  return $permiso !== null && lab_can($permiso);
}

function labSolicitudRequerirPermisoEdicionTipo($tipoMuestra): void
{
  if (labSolicitudPuedeEditarTipo($tipoMuestra)) {
    return;
  }

  $claveTipo = labSolicitudFormularioNormalizarTipoMuestra($tipoMuestra);
  $etiqueta = labCatalogoMuestrasEtiquetaModuloPlural($claveTipo);
  lab_forbidden('No tiene permiso para editar solicitudes de ' . $etiqueta . '.');
}

function labSolicitudObtenerTipoActual(PDO $conexion, int $idSolicitud): ?array
{
  $stmt = $conexion->prepare("
    SELECT tm.id_tipo, tm.nombre, tm.prefijo
    FROM solicitud s
    LEFT JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo
    WHERE s.id_solicitud = ?
    LIMIT 1
  ");
  $stmt->execute([$idSolicitud]);
  $tipo = $stmt->fetch(PDO::FETCH_ASSOC);

  return $tipo ?: null;
}

function labSolicitudHistorialAsegurarEsquema(PDO $conexion): void
{
  $rutaMigracion = __DIR__ . '/../database/004_solicitud_historial_cambios.sql';
  $sql = is_file($rutaMigracion) ? file_get_contents($rutaMigracion) : false;

  if ($sql === false || trim($sql) === '') {
    throw new RuntimeException('No se encontro la migracion del historial de cambios de solicitudes.');
  }

  $conexion->exec($sql);
}

function labSolicitudObtenerSnapshot(PDO $conexion, int $idSolicitud): ?array
{
  $stmt = $conexion->prepare("
    SELECT
      s.codigo_muestreo,
      s.fecha_muestreo,
      s.numero_muestras,
      s.institucion,
      s.responsable_envio,
      s.ingresado_por,
      s.correo_ingresado,
      s.recibido_por,
      s.correo_recibido,
      s.fecha_ingreso,
      s.fecha_estimada,
      s.observaciones,
      s.firma_ingreso,
      s.firma_recibe,
      l.codigo_lote,
      tm.nombre AS tipo_muestra,
      tm.prefijo
    FROM solicitud s
    LEFT JOIN lote l ON l.id_lote = s.id_lote
    LEFT JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo
    WHERE s.id_solicitud = ?
    LIMIT 1
  ");
  $stmt->execute([$idSolicitud]);
  $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$solicitud) {
    return null;
  }

  $stmtAnalisis = $conexion->prepare("
    SELECT ta.nombre
    FROM solicitud_analisis sa
    INNER JOIN tipo_analisis ta ON ta.id_tipo = sa.id_tipo_analisis
    WHERE sa.id_solicitud = ?
    ORDER BY ta.nombre, ta.id_tipo
  ");
  $stmtAnalisis->execute([$idSolicitud]);

  $stmtMuestras = $conexion->prepare("
    SELECT codigo_lab
    FROM muestra
    WHERE id_solicitud = ?
    ORDER BY numero_muestra, id_muestra
  ");
  $stmtMuestras->execute([$idSolicitud]);

  return [
    'tipo_muestra' => (string) ($solicitud['tipo_muestra'] ?? ''),
    'numero_lote' => (string) ($solicitud['codigo_lote'] ?? ''),
    'codigo_muestreo' => (string) ($solicitud['codigo_muestreo'] ?? ''),
    'fecha_muestreo' => (string) ($solicitud['fecha_muestreo'] ?? ''),
    'numero_muestras' => (int) ($solicitud['numero_muestras'] ?? 0),
    'institucion' => (string) ($solicitud['institucion'] ?? ''),
    'responsable_envio' => (string) ($solicitud['responsable_envio'] ?? ''),
    'ingresado_por' => (string) ($solicitud['ingresado_por'] ?? ''),
    'correo_ingresado' => (string) ($solicitud['correo_ingresado'] ?? ''),
    'recibido_por' => (string) ($solicitud['recibido_por'] ?? ''),
    'correo_recibido' => (string) ($solicitud['correo_recibido'] ?? ''),
    'fecha_ingreso' => (string) ($solicitud['fecha_ingreso'] ?? ''),
    'fecha_estimada' => (string) ($solicitud['fecha_estimada'] ?? ''),
    'observaciones' => (string) ($solicitud['observaciones'] ?? ''),
    'firma_ingreso' => empty($solicitud['firma_ingreso']) ? 'Sin firma' : 'Registrada',
    'firma_recibe' => empty($solicitud['firma_recibe']) ? 'Sin firma' : 'Registrada',
    'analisis' => $stmtAnalisis->fetchAll(PDO::FETCH_COLUMN),
    'codigos_laboratorio' => $stmtMuestras->fetchAll(PDO::FETCH_COLUMN),
  ];
}

function labSolicitudCalcularCambios(array $anterior, array $nuevo): array
{
  $etiquetas = [
    'tipo_muestra' => 'Tipo de muestra',
    'numero_lote' => 'Numero de lote',
    'codigo_muestreo' => 'Codigo de muestreo',
    'fecha_muestreo' => 'Fecha de muestreo',
    'numero_muestras' => 'Numero de muestras',
    'institucion' => 'Cliente o institucion',
    'responsable_envio' => 'Responsable del envio',
    'ingresado_por' => 'Ingresado por',
    'correo_ingresado' => 'Correo de quien ingresa',
    'recibido_por' => 'Recibido por',
    'correo_recibido' => 'Correo de quien recibe',
    'fecha_ingreso' => 'Fecha de ingreso',
    'fecha_estimada' => 'Fecha estimada',
    'observaciones' => 'Observaciones',
    'firma_ingreso' => 'Firma de ingreso',
    'firma_recibe' => 'Firma de recepcion',
    'analisis' => 'Analisis solicitados',
    'codigos_laboratorio' => 'Codigos de laboratorio',
  ];

  $cambios = [];
  foreach ($etiquetas as $campo => $etiqueta) {
    $valorAnterior = $anterior[$campo] ?? null;
    $valorNuevo = $nuevo[$campo] ?? null;

    if ($valorAnterior === $valorNuevo) {
      continue;
    }

    $cambios[] = [
      'campo' => $campo,
      'etiqueta' => $etiqueta,
      'anterior' => $valorAnterior,
      'nuevo' => $valorNuevo,
    ];
  }

  return $cambios;
}

function labSolicitudRegistrarHistorialCambio(
  PDO $conexion,
  int $idSolicitud,
  array $anterior,
  array $nuevo,
  array $usuario
): bool {
  $cambios = labSolicitudCalcularCambios($anterior, $nuevo);
  if (!$cambios) {
    return false;
  }

  $campos = array_column($cambios, 'etiqueta');
  $resumen = count($cambios) . ' campo(s) modificado(s): ' . implode(', ', $campos);
  $resumen = function_exists('mb_substr') ? mb_substr($resumen, 0, 255) : substr($resumen, 0, 255);

  $json = json_encode(
    ['total' => count($cambios), 'cambios' => $cambios],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
  );
  if ($json === false) {
    throw new RuntimeException('No se pudo serializar el registro de cambios de la solicitud.');
  }

  $nombreUsuario = trim((string) ($usuario['nombre'] ?? ''));
  if ($nombreUsuario === '') {
    $nombreUsuario = trim((string) ($usuario['correo'] ?? 'Usuario no identificado'));
  }

  $stmt = $conexion->prepare("
    INSERT INTO solicitud_historial_cambios (
      id_solicitud, tipo_muestra, usuario_id, usuario_nombre,
      resumen, cambios_json, ip
    ) VALUES (?, ?, ?, ?, ?, ?, ?)
  ");
  $stmt->execute([
    $idSolicitud,
    (string) ($nuevo['tipo_muestra'] ?? ''),
    !empty($usuario['id']) ? (int) $usuario['id'] : null,
    $nombreUsuario,
    $resumen,
    $json,
    substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
  ]);

  return true;
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
