<?php

require_once __DIR__ . '/catalogo_muestras_helper.php';
require_once __DIR__ . '/schema_safe_helper.php';

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

  // Motivo de la edición (versionado de la recepción, igual que Identificación).
  $columna = $conexion->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitud_historial_cambios' AND COLUMN_NAME = 'motivo'");
  if (!(int) $columna->fetchColumn()) {
    $conexion->exec("ALTER TABLE solicitud_historial_cambios ADD COLUMN motivo VARCHAR(500) NULL AFTER resumen");
  }
}

/**
 * Versiones de una recepción: v1 es el registro original y cada edición guardada
 * agrega una versión con su motivo, usuario, fecha y los campos que cambiaron.
 */
function labSolicitudVersiones(PDO $conexion, int $idSolicitud): array
{
  $stmt = $conexion->prepare('SELECT fecha_ingreso, recibido_por FROM solicitud WHERE id_solicitud = ?');
  $stmt->execute([$idSolicitud]);
  $registro = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$registro) {
    return [];
  }
  $versiones = [[
    'version' => 1, 'fecha' => (string) $registro['fecha_ingreso'], 'usuario' => (string) $registro['recibido_por'],
    'motivo' => 'Registro de la recepción', 'resumen' => '', 'cambios' => [],
  ]];
  $stmt = $conexion->prepare('SELECT fecha_cambio, usuario_nombre, resumen, motivo, cambios_json FROM solicitud_historial_cambios WHERE id_solicitud = ? ORDER BY fecha_cambio, id_historial');
  $stmt->execute([$idSolicitud]);
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $detalle = json_decode((string) $fila['cambios_json'], true);
    $versiones[] = [
      'version' => count($versiones) + 1, 'fecha' => (string) $fila['fecha_cambio'], 'usuario' => (string) $fila['usuario_nombre'],
      'motivo' => trim((string) ($fila['motivo'] ?? '')) ?: 'Sin motivo registrado (edición anterior al versionado)',
      'resumen' => (string) $fila['resumen'], 'cambios' => is_array($detalle['cambios'] ?? null) ? $detalle['cambios'] : [],
    ];
  }
  return $versiones;
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
      COALESCE(pl.prioridad, 'media') AS prioridad,
      tm.nombre AS tipo_muestra,
      tm.prefijo
    FROM solicitud s
    LEFT JOIN lote l ON l.id_lote = s.id_lote
    LEFT JOIN lab_plan_lote pl ON pl.id_lote = s.id_lote
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
    'prioridad' => ucfirst((string) ($solicitud['prioridad'] ?? 'media')),
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
    'prioridad' => 'Prioridad del lote',
    'codigo_muestreo' => 'Nombre del ensayo',
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
  array $usuario,
  string $motivo = ''
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
      resumen, motivo, cambios_json, ip
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  ");
  $stmt->execute([
    $idSolicitud,
    (string) ($nuevo['tipo_muestra'] ?? ''),
    !empty($usuario['id']) ? (int) $usuario['id'] : null,
    $nombreUsuario,
    $resumen,
    trim($motivo) !== '' ? (function_exists('mb_substr') ? mb_substr(trim($motivo), 0, 500) : substr(trim($motivo), 0, 500)) : null,
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

/**
 * Días de entrega de una recepción: la mayor "Duración (días)" del Catálogo de análisis entre
 * los análisis solicitados (el reporte sale cuando termina el más largo). Si ninguno tiene
 * duración configurada, se usan los días de compromiso por tipo de muestra.
 */
function obtenerDiasEntregaAnalisis(?PDO $conexion, array $idsAnalisis): int
{
  $ids = array_values(array_unique(array_filter(array_map('intval', $idsAnalisis))));
  if (!$conexion || !$ids) {
    return 0;
  }
  try {
    $stmt = $conexion->prepare('SELECT MAX(duracion_dias) FROM tipo_analisis WHERE id_tipo IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $stmt->execute($ids);
    return max(0, (int) $stmt->fetchColumn());
  } catch (Throwable $e) {
    error_log('[laboratorio][recepcion] duracion_dias: ' . $e->getMessage());
    return 0;
  }
}

function calcularFechaEstimadaSolicitud($fechaIngreso, $tipoMuestra, array $idsAnalisis = [], ?PDO $conexion = null): string
{
  $fechaIngreso = trim((string) $fechaIngreso);
  if ($fechaIngreso === '') {
    return '';
  }

  $diasCompromiso = obtenerDiasEntregaAnalisis($conexion, $idsAnalisis) ?: obtenerDiasCompromiso($tipoMuestra);
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

/**
 * Obtiene el siguiente lote (Lote-001, Lote-002…) del correlativo propio del
 * tipo de muestra: Suelos, Aguas, Foliar y Caña inician cada uno en Lote-001.
 * Como el código se repite entre tipos, el lote se identifica por id_lote.
 * Con $bloquear reserva el número dentro de la transacción del registro.
 */
function labSolicitudSiguienteLotePorTipo(PDO $conexion, array $tipoMuestra, bool $bloquear = false): array
{
  $idTipo = (int) ($tipoMuestra['id_tipo'] ?? 0);
  $prefijo = strtoupper(trim((string) ($tipoMuestra['prefijo'] ?? '')));

  if ($idTipo <= 0 || $prefijo === '') {
    throw new RuntimeException('El tipo de muestra no tiene un prefijo valido para generar el lote.');
  }

  if ($bloquear) {
    // Serializa la asignacion para que dos recepciones simultaneas no
    // obtengan el mismo correlativo.
    $lock = $conexion->prepare('SELECT id_tipo FROM tipo_muestra WHERE id_tipo = ? FOR UPDATE');
    $lock->execute([$idTipo]);
    if (!$lock->fetchColumn()) {
      throw new RuntimeException('El tipo de muestra seleccionado ya no existe.');
    }
  }

  $correlativo = labCorrelativoRecepcion($conexion, $idTipo, $bloquear);

  // El correlativo sale del mayor "Lote-NNN" ya registrado para este tipo, de
  // modo que los lotes con el formato anterior (S-001…) no lo afectan.
  $stmtUltimo = $conexion->prepare("
    SELECT COALESCE(MAX(CAST(SUBSTRING(l.codigo_lote, 6) AS UNSIGNED)), 0)
      FROM lote l
      INNER JOIN solicitud s ON s.id_lote = l.id_lote
     WHERE s.id_tipo = ? AND l.codigo_lote LIKE 'Lote-%'
  ");
  $stmtUltimo->execute([$idTipo]);
  $ultimoNumero = (int) $stmtUltimo->fetchColumn();
  $siguienteNumero = $ultimoNumero + 1;
  $siguienteCodigo = 'Lote-' . str_pad((string) $siguienteNumero, 3, '0', STR_PAD_LEFT);

  if ($bloquear) {
    $conexion->prepare('UPDATE lab_correlativo_recepcion SET ultimo_lote = ? WHERE id_tipo = ?')->execute([$siguienteNumero, $idTipo]);
  }

  return [
    'id_tipo' => $idTipo,
    'clave' => labSolicitudFormularioNormalizarTipoMuestra($tipoMuestra),
    'prefijo' => $prefijo,
    'ultimo_numero' => $ultimoNumero,
    'siguiente_numero' => $siguienteNumero,
    'siguiente_codigo' => $siguienteCodigo,
    // Vista previa del primer número de laboratorio (se confirma al registrar).
    'siguiente_laboratorio' => (int) $correlativo['ultimo_laboratorio'] + 1,
  ];
}

/**
 * Correlativos de recepción independientes por tipo de muestra (prototipo):
 * el lote (S-001, S-002…) y el número de laboratorio (S-001-MM-AA…) inician
 * en 1 para cada tipo. Se crea la fila del tipo en cero la primera vez.
 */
function labCorrelativoRecepcionEsquema(PDO $conexion): void
{
  $conexion->exec("CREATE TABLE IF NOT EXISTS lab_correlativo_recepcion (
    id_tipo INT NOT NULL PRIMARY KEY,
    ultimo_lote INT NOT NULL DEFAULT 0,
    ultimo_laboratorio INT NOT NULL DEFAULT 0,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_correlativo_recepcion_tipo FOREIGN KEY (id_tipo) REFERENCES tipo_muestra(id_tipo)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function labCorrelativoRecepcion(PDO $conexion, int $idTipo, bool $bloquear = false): array
{
  $conexion->prepare('INSERT IGNORE INTO lab_correlativo_recepcion (id_tipo) VALUES (?)')->execute([$idTipo]);
  $stmt = $conexion->prepare('SELECT ultimo_lote, ultimo_laboratorio FROM lab_correlativo_recepcion WHERE id_tipo = ?' . ($bloquear ? ' FOR UPDATE' : ''));
  $stmt->execute([$idTipo]);
  return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['ultimo_lote' => 0, 'ultimo_laboratorio' => 0];
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

/**
 * Reserva el bloque de números de laboratorio de una recepción nueva. Inicia en
 * 1 por tipo de muestra y salta cualquier bloque cuyo código ya exista
 * (mismo prefijo, número y mes-año), para no duplicar códigos de muestra.
 */
function obtenerInicioLaboratorioNuevo(PDO $conexion, array $tipoMuestra, int $cantidadMuestras, string $mesAnio): int
{
  $idTipo = (int) $tipoMuestra['id_tipo'];
  $prefijo = strtoupper((string) $tipoMuestra['prefijo']);
  $inicio = (int) labCorrelativoRecepcion($conexion, $idTipo, true)['ultimo_laboratorio'] + 1;

  while (true) {
    $codigos = [];
    for ($n = $inicio; $n < $inicio + $cantidadMuestras; $n++) {
      $codigos[] = construirCodigoLab($prefijo, $n, $mesAnio, max(3, strlen((string) ($inicio + $cantidadMuestras - 1))));
    }
    $usado = $conexion->prepare('SELECT MAX(numero_muestra) FROM muestra WHERE codigo_lab IN (' . implode(',', array_fill(0, count($codigos), '?')) . ')');
    $usado->execute($codigos);
    $maximo = $usado->fetchColumn();
    if ($maximo === null || $maximo === false) {
      break;
    }
    $inicio = max($inicio + 1, (int) $maximo + 1);
  }

  $conexion->prepare('UPDATE lab_correlativo_recepcion SET ultimo_laboratorio = ? WHERE id_tipo = ?')
    ->execute([$inicio + $cantidadMuestras - 1, $idTipo]);

  return $inicio;
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
