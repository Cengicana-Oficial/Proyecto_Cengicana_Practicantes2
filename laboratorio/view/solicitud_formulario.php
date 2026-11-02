<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/solicitud_formulario_helpers.php';
require_once __DIR__ . '/../includes/recepcion_catalogo_helper.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../models/trazabilidad_model.php';
require_once __DIR__ . '/../includes/workflow_trabajo_helper.php';
require_once __DIR__ . '/../includes/recepciones_historial_helper.php';

lab_require_module_access();
lab_ensure_schema_safe(fn() => wfSchema($conexion), 'workflow_trabajo_v3');
lab_ensure_schema_safe(fn() => labCorrelativoRecepcionEsquema($conexion), 'correlativo_recepcion_v1');
require_once __DIR__ . '/../models/documentos_model.php';
lab_ensure_schema_safe(fn() => lab_documentos_esquema($conexion), 'documentos_v1');
lab_ensure_schema_safe(fn() => asegurarColumnasFirmasSolicitud($conexion), 'solicitud_firmas');
lab_ensure_schema_safe(fn() => labSolicitudHistorialAsegurarEsquema($conexion), 'solicitud_historial');
lab_ensure_schema_safe(fn() => labCatalogoAnalisisAsegurarEsquema($conexion), 'catalogo_analisis');

$catalogoMuestras = labCatalogoMuestrasFormularioData($conexion, false);
lab_ensure_schema_safe(fn() => labRecepcionPrepararCatalogo($conexion), 'recepcion_catalogo_v3');
$catalogoAnalisis = labRecepcionCatalogoFormulario($conexion, (int) ($_POST['id_solicitud'] ?? $_GET['id_solicitud'] ?? 0));
$usuarioActualSolicitud = lab_current_user();

$tipoFormularioInicial = null;
foreach ($catalogoMuestras as $clave => $muestra) {
  if (!empty($muestra['activo'])) {
    $tipoFormularioInicial = $clave;
    break;
  }
}

if ($tipoFormularioInicial === null && !empty($catalogoMuestras)) {
  $tipoFormularioInicial = array_key_first($catalogoMuestras);
}

if ($tipoFormularioInicial === null) {
  $tipoFormularioInicial = 'suelos';
}

$message = '';
$dbWarning = '';
$solicitudesDb = [];
$tabsRecepcion = [];
$tipoInicialRec = 0;
$paginaRecepciones = ['filas' => [], 'total' => 0, 'muestras' => 0, 'pagina' => 1, 'paginas' => 1];
$messageEsError = false;
// Accesos del menú "Acciones" de Recepciones registradas (según permisos).
$puedeVerLotesRec = lab_can('laboratorio.lotes.ver');
$puedeVerInformesRec = lab_can('laboratorio.informes.ver');
$correlativosLoteDb = [];
$loteSeleccionado = trim((string) ($_GET['lote'] ?? ''));
$idSolicitudGet = !empty($_GET['id_solicitud']) ? (int) $_GET['id_solicitud'] : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $idSolicitudPost = !empty($_POST['id_solicitud']) ? (int) $_POST['id_solicitud'] : null;
  $tipoFormularioPost = (string) ($_POST['tipo_form'] ?? $tipoFormularioInicial);

  if ($idSolicitudPost) {
    $tipoSolicitudActual = labSolicitudObtenerTipoActual($conexion, $idSolicitudPost);
    if (!$tipoSolicitudActual) {
      lab_forbidden('La solicitud que intenta editar no existe.');
    }

    labSolicitudRequerirPermisoEdicionTipo($tipoSolicitudActual);

    $tipoSolicitudObjetivo = labCatalogoMuestrasObtenerPorClave($conexion, $tipoFormularioPost, false);
    if ($tipoSolicitudObjetivo) {
      labSolicitudRequerirPermisoEdicionTipo($tipoSolicitudObjetivo);
    }
  } else {
    lab_require_permission('laboratorio.solicitudes.crear');
  }

  try {
    $conexion->beginTransaction();

    $tipoFormulario = $tipoFormularioPost;
    $tipoMuestra = labCatalogoMuestrasObtenerPorClave($conexion, $tipoFormulario, !$idSolicitudPost ? true : false);
    if (!$tipoMuestra) {
      throw new RuntimeException('El tipo de muestra seleccionado ya no está disponible.');
    }
    $codigoMuestreo = trim((string) ($_POST['codigo_muestreo'] ?? ''));
    $fechaMuestreo = $_POST['fecha_de_muestreo'] ?? null;
    $numeroMuestras = max(1, (int) ($_POST['numero_muestras'] ?? 1));
    $institucion = trim((string) ($_POST['institucion'] ?? ''));
    $responsableEnvio = trim((string) ($_POST['responsable_envio'] ?? ''));
    $observaciones = trim($_POST['observaciones'] ?? '');
    $ingresadoPor = trim($_POST['ingresado_por'] ?? '');
    $correoIngresado = trim($_POST['correo_ingresado_por'] ?? '');
    $recibidoPor = trim($_POST['recibido_por'] ?? '');
    $correoRecibido = trim($_POST['correo_recibido_por'] ?? '');
    $firmaIngreso = normalizarFirmaSolicitud($_POST['firma_ingreso'] ?? '');
    $firmaRecibe = normalizarFirmaSolicitud($_POST['firma_recibe'] ?? '');
    $analisisSeleccionados = $_POST['analisis'] ?? [];
    $motivoEdicion = trim((string) ($_POST['motivo_edicion'] ?? ''));
    if ($idSolicitudPost && mb_strlen($motivoEdicion) < 5) {
      throw new RuntimeException('Escriba el motivo de la edición (queda en el historial de versiones).');
    }
    $idSolicitud = $idSolicitudPost;

    if (!is_array($analisisSeleccionados)) {
      $analisisSeleccionados = [$analisisSeleccionados];
    }

    if (!$fechaMuestreo) {
      throw new RuntimeException('Ingrese la fecha de muestreo.');
    }

    if ($ingresadoPor === '') {
      throw new RuntimeException('Ingrese el nombre de quien ingresa las muestras.');
    }

    if (!$idSolicitudPost) {
      $nombreRecepcionista = trim((string) ($usuarioActualSolicitud['nombre'] ?? ''));
      $correoRecepcionista = trim((string) ($usuarioActualSolicitud['correo'] ?? ''));
      if ($nombreRecepcionista !== '') {
        $recibidoPor = $nombreRecepcionista;
      }
      if ($correoRecepcionista !== '') {
        $correoRecibido = $correoRecepcionista;
      }
    }

    if ($recibidoPor === '') {
      throw new RuntimeException('No se pudo identificar a quien recibe las muestras.');
    }

    foreach ([$correoIngresado, $correoRecibido] as $correoResponsable) {
      if ($correoResponsable !== '' && !filter_var($correoResponsable, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Revise los correos de los responsables.');
      }
    }

    $fechaIngresoActual = date('Y-m-d');
    $solicitudExistente = null;
    $snapshotAnterior = null;

    if ($idSolicitud) {
      $stmtSolicitudExistente = $conexion->prepare("
        SELECT s.id_solicitud, s.id_tipo, s.id_lote, s.fecha_ingreso, s.fecha_estimada, s.numero_muestras, l.codigo_lote
        FROM solicitud s
        INNER JOIN lote l ON l.id_lote = s.id_lote
        WHERE s.id_solicitud = ?
        LIMIT 1
        FOR UPDATE
      ");
      $stmtSolicitudExistente->execute([$idSolicitud]);
      $solicitudExistente = $stmtSolicitudExistente->fetch(PDO::FETCH_ASSOC) ?: null;

      if (!$solicitudExistente) {
        throw new RuntimeException('La solicitud que intenta editar no existe.');
      }

      if ((int) $solicitudExistente['id_tipo'] !== (int) $tipoMuestra['id_tipo']
          || (int) $solicitudExistente['numero_muestras'] !== $numeroMuestras) {
        throw new RuntimeException('Al editar se conservan el tipo y la cantidad de muestras. Registre otra recepción para agregar muestras.');
      }
      $snapshotAnterior = labSolicitudObtenerSnapshot($conexion, $idSolicitud);
    }

    $fechaEstimadaNueva = calcularFechaEstimadaSolicitud($fechaIngresoActual, $tipoMuestra, $analisisSeleccionados, $conexion);
    $tipoMuestraCambia = $solicitudExistente
      && (int) ($solicitudExistente['id_tipo'] ?? 0) !== (int) $tipoMuestra['id_tipo'];

    if ($solicitudExistente && !$tipoMuestraCambia) {
      $idLote = (int) $solicitudExistente['id_lote'];
      $codigoLote = (string) $solicitudExistente['codigo_lote'];
    } else {
      $loteNuevo = labSolicitudSiguienteLotePorTipo($conexion, $tipoMuestra, true);
      $codigoLote = (string) $loteNuevo['siguiente_codigo'];
      // "Lote-NNN" se repite entre tipos de muestra: siempre se crea un lote nuevo.
      $conexion->prepare('INSERT INTO lote (codigo_lote) VALUES (?)')->execute([$codigoLote]);
      $idLote = (int) $conexion->lastInsertId();
    }

    if ($idSolicitud) {
      if ($tipoMuestraCambia) {
        $fechaIngresoOriginal = trim((string) ($solicitudExistente['fecha_ingreso'] ?? ''));
        if ($fechaIngresoOriginal === '') {
          $fechaIngresoOriginal = $fechaIngresoActual;
        }

        $fechaEstimadaPersistida = calcularFechaEstimadaSolicitud($fechaIngresoOriginal, $tipoMuestra, $analisisSeleccionados, $conexion);

        $updateSolicitud = $conexion->prepare("
          UPDATE solicitud
          SET id_tipo = ?, id_lote = ?, codigo_muestreo = ?, fecha_muestreo = ?, numero_muestras = ?,
              institucion = ?, responsable_envio = ?,
              ingresado_por = ?, correo_ingresado = ?, recibido_por = ?, correo_recibido = ?,
              fecha_estimada = ?, observaciones = ?,
              firma_ingreso = COALESCE(NULLIF(?, ''), firma_ingreso),
              firma_recibe = COALESCE(NULLIF(?, ''), firma_recibe)
          WHERE id_solicitud = ?
        ");
        $paramsActualizar = [
          $tipoMuestra['id_tipo'],
          $idLote,
          $codigoMuestreo,
          $fechaMuestreo,
          $numeroMuestras,
          $institucion,
          $responsableEnvio,
          $ingresadoPor,
          $correoIngresado,
          $recibidoPor,
          $correoRecibido,
          $fechaEstimadaPersistida,
          $observaciones,
          $firmaIngreso,
          $firmaRecibe,
          $idSolicitud,
        ];
      } else {
        $updateSolicitud = $conexion->prepare("
          UPDATE solicitud
          SET id_tipo = ?, id_lote = ?, codigo_muestreo = ?, fecha_muestreo = ?, numero_muestras = ?,
              institucion = ?, responsable_envio = ?,
              ingresado_por = ?, correo_ingresado = ?, recibido_por = ?, correo_recibido = ?,
              observaciones = ?,
              firma_ingreso = COALESCE(NULLIF(?, ''), firma_ingreso),
              firma_recibe = COALESCE(NULLIF(?, ''), firma_recibe)
          WHERE id_solicitud = ?
        ");
        $paramsActualizar = [
          $tipoMuestra['id_tipo'],
          $idLote,
          $codigoMuestreo,
          $fechaMuestreo,
          $numeroMuestras,
          $institucion,
          $responsableEnvio,
          $ingresadoPor,
          $correoIngresado,
          $recibidoPor,
          $correoRecibido,
          $observaciones,
          $firmaIngreso,
          $firmaRecibe,
          $idSolicitud,
        ];
      }
      $updateSolicitud->execute($paramsActualizar);
    } else {
      $paramsSolicitud = [
        $tipoMuestra['id_tipo'],
        $idLote,
        $codigoMuestreo,
        $fechaMuestreo,
        $numeroMuestras,
        $institucion,
        $responsableEnvio,
        $ingresadoPor,
        $correoIngresado,
        $recibidoPor,
        $correoRecibido,
        $fechaIngresoActual,
        $fechaEstimadaNueva,
        $observaciones,
        $firmaIngreso,
        $firmaRecibe,
      ];

      $insertSolicitud = $conexion->prepare("
        INSERT INTO solicitud (
          id_tipo, id_lote, codigo_muestreo, fecha_muestreo, numero_muestras,
          institucion, responsable_envio,
          ingresado_por, correo_ingresado, recibido_por, correo_recibido,
          fecha_ingreso, fecha_estimada, observaciones, firma_ingreso, firma_recibe
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ");
      $insertSolicitud->execute($paramsSolicitud);
      $idSolicitud = (int) $conexion->lastInsertId();
    }

    $mesAnio = mesAnioDesdeFecha($fechaMuestreo);
    $inicioExistente = obtenerInicioLaboratorioSolicitud($conexion, $idSolicitud);
    $loteInicial = $inicioExistente ?: obtenerInicioLaboratorioNuevo($conexion, $tipoMuestra, $numeroMuestras, $mesAnio);
    $loteFinal = $loteInicial + $numeroMuestras - 1;
    $longitudLote = max(3, strlen((string) $loteFinal));
    $codigoInicio = construirCodigoLab($tipoMuestra['prefijo'], $loteInicial, $mesAnio, $longitudLote);
    $codigoFin = construirCodigoLab($tipoMuestra['prefijo'], $loteFinal, $mesAnio, $longitudLote);

    if (!$idSolicitudPost) {
      $insertMuestra = $conexion->prepare("INSERT INTO muestra (id_solicitud, numero_muestra, codigo_lab) VALUES (?, ?, ?)");
      for ($i = 0; $i < $numeroMuestras; $i++) {
        $numeroLaboratorio = $loteInicial + $i;
        $codigoLab = construirCodigoLab($tipoMuestra['prefijo'], $numeroLaboratorio, $mesAnio, $longitudLote);
        $insertMuestra->execute([$idSolicitud, $numeroLaboratorio, $codigoLab]);
      }
    } else {
      $codigosGuardados = $conexion->prepare("SELECT codigo_lab FROM muestra WHERE id_solicitud = ? ORDER BY numero_muestra, id_muestra");
      $codigosGuardados->execute([$idSolicitud]);
      $codigos = $codigosGuardados->fetchAll(PDO::FETCH_COLUMN);
      $codigoInicio = $codigos ? reset($codigos) : '';
      $codigoFin = $codigos ? end($codigos) : '';
    }
    $idRango = obtenerRango($conexion, $idLote, $loteInicial, $loteFinal);

    $analisisSeleccionados = labRecepcionCompletarAnalisis($catalogoAnalisis[$tipoFormulario]['items'] ?? [], $analisisSeleccionados);

    if ($idSolicitudPost) {
      // Planificación, Bandeja y Validación técnica se generan desde solicitud_analisis:
      // no se puede quitar un análisis que ya tiene hojas capturadas para este lote.
      $stmtConResultados = $conexion->prepare("
        SELECT DISTINCT sa.id_tipo_analisis, ta.nombre
        FROM solicitud_analisis sa
        INNER JOIN tipo_analisis ta ON ta.id_tipo = sa.id_tipo_analisis
        WHERE sa.id_solicitud = ?
          AND EXISTS (
            SELECT 1
            FROM formulario f
            INNER JOIN lote_rango lr ON lr.id_rango = f.id_rango
            INNER JOIN muestra m ON m.id_solicitud = sa.id_solicitud AND m.numero_muestra BETWEEN lr.inicio AND lr.fin
            WHERE lr.id_lote = ? AND f.id_tipo_analisis = sa.id_tipo_analisis
          )
      ");
      $stmtConResultados->execute([$idSolicitud, $idLote]);
      $seleccionNueva = array_map('intval', $analisisSeleccionados);
      $bloqueados = array_filter($stmtConResultados->fetchAll(PDO::FETCH_ASSOC), static function ($fila) use ($seleccionNueva) {
        return !in_array((int) $fila['id_tipo_analisis'], $seleccionNueva, true);
      });
      if ($bloqueados) {
        throw new RuntimeException('No se puede quitar ' . implode(', ', array_column($bloqueados, 'nombre'))
          . ': ya tiene resultados capturados o en validación técnica.');
      }
    }

    $conexion->prepare("DELETE FROM solicitud_analisis WHERE id_solicitud = ?")->execute([$idSolicitud]);
    $insertSolicitudAnalisis = $conexion->prepare("INSERT INTO solicitud_analisis (id_solicitud, id_tipo_analisis) VALUES (?, ?)");

    $analisisPermitidos = [];
    foreach (($catalogoAnalisis[$tipoFormulario]['items'] ?? []) as $analisisDisponible) {
      $analisisPermitidos[(int) ($analisisDisponible['id_tipo'] ?? 0)] = true;
    }
    foreach ($analisisSeleccionados as $idAnalisisSeleccionado) {
      $idTipoAnalisis = (int) $idAnalisisSeleccionado;
      if ($idTipoAnalisis <= 0) {
        continue;
      }

      if (!isset($analisisPermitidos[$idTipoAnalisis])) {
        throw new RuntimeException('Uno de los análisis seleccionados ya no está disponible para este tipo de muestra.');
      }

      $insertSolicitudAnalisis->execute([$idSolicitud, $idTipoAnalisis]);
      insertarLoteAnalisis($conexion, $idRango, $idTipoAnalisis);
    }

    $usuarioActualTraz = function_exists('lab_current_user') ? lab_current_user() : [];
    $versionNueva = false;
    if ($idSolicitudPost && $snapshotAnterior) {
      $snapshotNuevo = labSolicitudObtenerSnapshot($conexion, $idSolicitud);
      if ($snapshotNuevo) {
        $versionNueva = labSolicitudRegistrarHistorialCambio(
          $conexion,
          $idSolicitud,
          $snapshotAnterior,
          $snapshotNuevo,
          $usuarioActualTraz,
          $motivoEdicion
        );
      }
    }

    // Documentos (PDF): boleta v1 al registrar; al editar con cambios, nueva versión con el motivo.
    $usuarioDocumento = (string) ($usuarioActualTraz['nombre'] ?? $recibidoPor);
    if (!$idSolicitudPost) {
      lab_documento_boleta_guardar($conexion, $idSolicitud, $usuarioDocumento);
    } elseif ($versionNueva) {
      $camposCambiados = array_column(labSolicitudCalcularCambios($snapshotAnterior, $snapshotNuevo), 'etiqueta');
      lab_documento_boleta_guardar($conexion, $idSolicitud, $usuarioDocumento, $motivoEdicion, 'Campos: ' . implode(', ', $camposCambiados));
    }

    $conexion->commit();

    lab_trazabilidad_registrar_evento($conexion, [
      'id_lote' => $idLote,
      'id_solicitud' => $idSolicitud,
      'codigo_muestra' => $codigoInicio,
      'tipo_evento' => $idSolicitudPost ? 'boleta_generada' : 'recepcion',
      'descripcion' => $idSolicitudPost
        ? "Solicitud #{$idSolicitud} actualizada. Numero de laboratorio: {$codigoInicio} a {$codigoFin}."
        : "Lote recibido. Solicitud #{$idSolicitud} registrada con {$numeroMuestras} muestra(s). Numero de laboratorio: {$codigoInicio} a {$codigoFin}.",
      'usuario_id' => $usuarioActualTraz['id'] ?? null,
      'usuario_nombre' => $usuarioActualTraz['nombre'] ?? ($ingresadoPor ?: $recibidoPor),
    ]);

    // Post/Redirect/Get: recargar la página no vuelve a registrar la recepción.
    // La boleta del registro guardado se genera y se envía por correo después (recepcion_workflow.js).
    $_SESSION['recepcion_flash'] = [
      'mensaje' => ($idSolicitudPost
          ? ($versionNueva
            ? "Lote {$codigoLote} actualizado · versión " . count(labSolicitudVersiones($conexion, $idSolicitud)) . " guardada con su motivo"
            : "Lote {$codigoLote} sin cambios · no se creó una versión nueva")
          : "Lote {$codigoLote} registrado")
        . " · {$numeroMuestras} muestra(s) · Número de laboratorio {$codigoInicio} a {$codigoFin}.",
      'id_solicitud' => $idSolicitud,
      'enviar_correo' => !$idSolicitudPost,
    ];
    header('Location: solicitud_formulario.php');
    exit;
  } catch (Exception $e) {
    if ($conexion->inTransaction()) {
      $conexion->rollBack();
    }
    $message = 'Error al guardar la solicitud: ' . $e->getMessage();
    $messageEsError = true;
  }
}
$recepcionGuardada = $_SESSION['recepcion_flash'] ?? null;
unset($_SESSION['recepcion_flash']);
if ($recepcionGuardada) {
  $message = (string) $recepcionGuardada['mensaje'];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  if ($idSolicitudGet) {
    $tipoSolicitudActual = labSolicitudObtenerTipoActual($conexion, $idSolicitudGet);
    if (!$tipoSolicitudActual) {
      lab_forbidden('La solicitud que intenta editar no existe.');
    }
    labSolicitudRequerirPermisoEdicionTipo($tipoSolicitudActual);
  } else {
    lab_require_permission('laboratorio.solicitudes.crear');
  }
}

try {
  foreach ($catalogoMuestras as $clave => $tipoMuestraCatalogo) {
    if (!empty($tipoMuestraCatalogo['activo'])) {
      $correlativosLoteDb[$clave] = labSolicitudSiguienteLotePorTipo($conexion, $tipoMuestraCatalogo);
    }
  }

  // Recepciones registradas: 25 por página y por tipo de muestra, más recientes primero; las
  // páginas siguientes y la búsqueda se piden a controllers/recepciones_historial.php.
  $idSolicitudPrioritaria = (int) ($idSolicitudGet ?? 0);
  $tabsRecepcion = labRecepcionesTabs($conexion);
  $tipoInicialRec = (int) ($tabsRecepcion[0]['id'] ?? 0);
  $idRecienteRec = (int) ($recepcionGuardada['id_solicitud'] ?? 0) ?: $idSolicitudPrioritaria;
  if ($idRecienteRec > 0) {
    $recepcionReciente = labRecepcionPorId($conexion, $idRecienteRec);
    if ($recepcionReciente) {
      $tipoInicialRec = (int) $recepcionReciente['id_tipo'];
      if ($idSolicitudPrioritaria > 0) $solicitudesDb[] = $recepcionReciente;
    }
  }
  $paginaRecepciones = labRecepcionesPagina($conexion, $tipoInicialRec, 1);
} catch (Exception $e) {
  $dbWarning = 'No se pudieron cargar las solicitudes desde la base de datos: ' . $e->getMessage();
}

// Edición: motivo obligatorio e historial de versiones de la recepción.
$idEdicion = (int) ($_POST['id_solicitud'] ?? $_GET['id_solicitud'] ?? 0);
$versionesEdicion = $idEdicion > 0 ? labSolicitudVersiones($conexion, $idEdicion) : [];
$versionValor = static function ($valor): string {
  if (is_array($valor)) return $valor ? implode(', ', array_map('strval', $valor)) : '—';
  $valor = trim((string) $valor);
  return $valor === '' ? '—' : $valor;
};


?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Laboratorios AgroLab — Boleta de Solicitud</title>
<link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../css/lab_shell.css?v=20261006">
<link rel="stylesheet" href="../css/solicitud_formulario.css?v=<?= (int) filemtime(__DIR__ . '/../css/solicitud_formulario.css') ?>">
<link rel="stylesheet" href="../styles/recepcion_template.css?v=<?= (int) filemtime(__DIR__ . '/../styles/recepcion_template.css') ?>">
</head>
<body class="cengi-canvas">
<?php lab_shell_open('solicitud_formulario.php', 'Recepcion', 'Boleta de solicitud de analisis por lote'); ?>

<!-- NAV -->
<nav>
  <div class="nav-brand">Laboratorios AgroLab</div>
  <div class="nav-links">
    <a class="nav-link back" href="../index.php" title="Volver al inicio">Inicio</a>
    <a class="nav-link back" href="../index.php" title="Volver al menu principal">Cambiar de Formulario</a>
    <a class="nav-link active" href="#">Análisis Nuevos</a>
  </div>
</nav>

<!-- MAIN -->
<main class="reception-page">

  <?php if (!empty($message) && $messageEsError): ?>
    <div role="alert" style="padding:12px;margin-bottom:14px;border-radius:8px;background:#FFF0E6;border:1px solid #FFD9BF;color:#B34B00">
      <?php echo htmlspecialchars($message); ?> Los datos ingresados se conservan; corrija y vuelva a registrar.
    </div>
  <?php elseif (!empty($message)): ?>
    <div class="reception-saved" id="recepcion-guardada" data-solicitud="<?= (int) ($recepcionGuardada['id_solicitud'] ?? 0) ?>" data-enviar-correo="<?= !empty($recepcionGuardada['enviar_correo']) ? '1' : '0' ?>" role="status">
      <div><strong><?php echo htmlspecialchars($message); ?></strong>
        <div class="reception-saved-status" id="recepcion-guardada-estado">Las muestras ya están en Planificación del trabajo.</div></div>
      <div class="reception-saved-actions">
        <button type="button" class="reception-btn reception-btn--ghost" data-boleta-action="ver" data-solicitud="<?= (int) ($recepcionGuardada['id_solicitud'] ?? 0) ?>">Ver boleta</button>
        <button type="button" class="reception-btn reception-btn--ghost" data-boleta-action="pdf" data-solicitud="<?= (int) ($recepcionGuardada['id_solicitud'] ?? 0) ?>">Descargar PDF</button>
        <button type="button" class="reception-btn reception-btn--ghost" data-boleta-action="correo" data-solicitud="<?= (int) ($recepcionGuardada['id_solicitud'] ?? 0) ?>">Enviar por correo</button>
        <a class="reception-btn reception-btn--ghost" href="etiquetas_laboratorio.php?id_solicitud=<?= (int) ($recepcionGuardada['id_solicitud'] ?? 0) ?>" target="_blank" rel="noopener">Imprimir etiquetas</a>
        <a class="reception-btn reception-btn--ghost" href="listar_lotes.php?id_solicitud=<?= (int) ($recepcionGuardada['id_solicitud'] ?? 0) ?>">Identificar muestras</a>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($dbWarning)): ?>
    <div style="padding:12px;margin-bottom:14px;border-radius:8px;background:#FFF8DB;border:1px solid #FFCC00;color:#5C4A00">
      <?php echo htmlspecialchars($dbWarning); ?>
    </div>
  <?php endif; ?>

  <form id="solicitud-form" method="post">
  <input type="hidden" id="id_solicitud" name="id_solicitud" value="<?= $idSolicitudGet ? (int) $idSolicitudGet : '' ?>"/>
  <input type="hidden" id="tipo_form" name="tipo_form" value="<?= htmlspecialchars($tipoFormularioInicial, ENT_QUOTES, 'UTF-8') ?>"/>
  <input type="hidden" id="firma_ingreso" name="firma_ingreso" value=""/>
  <input type="hidden" id="firma_recibe" name="firma_recibe" value=""/>
  <?php
    $getTypes = $_GET['tipo'] ?? [];
    if (!is_array($getTypes) && !empty($getTypes)) {
        $getTypes = [$getTypes];
    }
    if (is_array($getTypes)) {
        foreach ($getTypes as $t) {
            echo '<input type="hidden" name="tipo[]" value="' . htmlspecialchars($t) . '"/>';
        }
    }
  ?>

  <!-- ENCABEZADO -->
  <header class="doc-header">
    <div class="doc-header-left">
      <div class="logo-circle">
        <span class="material-symbols-outlined">eco</span>
      </div>
      <div>
        <div class="doc-title">1. Datos del cliente y procedencia del lote</div>
        <div class="doc-subtitle">
          Boleta de solicitud de análisis de <strong id="tipo-label-header"><?= htmlspecialchars((string) ($catalogoMuestras[$tipoFormularioInicial]['label'] ?? 'Suelos'), ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
      </div>
    </div>
    <div class="doc-header-right">
      <div class="meta-badge"><span>VF</span> 005</div>
      <div class="meta-badge">
        <span>Lote</span>
        <input class="lote-input" id="lote-resumen" type="text" placeholder="Automático" aria-label="Número de lote asignado" readonly tabindex="-1"/>
      </div>
    </div>
  </header>

  <!-- TIPO DE ANÁLISIS --->
  <div class="tipo-btns" id="tipo-btns">
    <?php foreach ($catalogoMuestras as $clave => $muestra): ?>
      <?php
        $activo = !empty($muestra['activo']);
        $classes = ['tipo-btn'];
        if ($clave === $tipoFormularioInicial && $activo) {
          $classes[] = 'active';
        }
        if (!$activo) {
          $classes[] = 'tipo-btn--disabled';
        }
      ?>
      <button
        type="button"
        class="<?= htmlspecialchars(implode(' ', $classes), ENT_QUOTES, 'UTF-8') ?>"
        data-tipo="<?= htmlspecialchars($clave, ENT_QUOTES, 'UTF-8') ?>"
        <?= $activo ? '' : 'disabled aria-disabled="true" title="Tipo de muestra desactivado"' ?>>
        <?= htmlspecialchars((string) ($muestra['label_plural'] ?? $muestra['label'] ?? $clave), ENT_QUOTES, 'UTF-8') ?>
      </button>
    <?php endforeach; ?>
  </div>
<ol class="reception-progress" aria-label="Avance del registro">
  <li data-step="datos"><span>1</span> Datos</li>
  <li data-step="analisis"><span>2</span> Análisis</li>
  <li data-step="firmas"><span>3</span> Firmas</li>
</ol>
<!-- DATOS DEL MUESTREO -->

<div class="section-title">
    Datos del muestreo
</div>

<div class="field-grid">
    <div class="field">
        <label for="institucion">Cliente / institucion</label>
        <input id="institucion" name="institucion" type="text" placeholder="Nombre del cliente" value="<?= htmlspecialchars((string) ($_POST['institucion'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"/>
    </div>
    <div class="field">
        <label for="responsable_envio">Responsable del envio</label>
        <input id="responsable_envio" name="responsable_envio" type="text" placeholder="Nombre o contacto" value="<?= htmlspecialchars((string) ($_POST['responsable_envio'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"/>
    </div>
    <div class="field">
        <label for="lote">
            Número de lote
        </label>

        <input
            id="lote"
            name="lote"
            type="text"
            required
            readonly
            aria-readonly="true"
            title="Se asigna automáticamente según el último lote del tipo de muestra"
            placeholder="Se asignará automáticamente"
            value="<?= htmlspecialchars($loteSeleccionado, ENT_QUOTES, 'UTF-8') ?>"/>
        <small>Correlativo automático e independiente para cada tipo de muestra.</small>
    </div>
    <div class="field">
        <label for="numero_de_muestra">Nombre del ensayo</label>
        <input id="numero_de_muestra" name="codigo_muestreo" type="text" placeholder="Ej. Ensayo de variedades 2026" value="<?= htmlspecialchars((string) ($_POST['codigo_muestreo'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"/>
    </div>
    <div class="field">
        <label for="fecha_muestreo">
            Fecha de muestreo
        </label>

        <input
            id="fecha_muestreo"
            name="fecha_de_muestreo"
            type="date"
            required/>
    </div>
    <div class="field">
        <label for="fecha_ingreso">
            Fecha de ingreso
        </label>

        <input
            id="fecha_ingreso"
            type="text"
            placeholder="DD/MM/AAAA"
            readonly
            aria-readonly="true"
            autocomplete="off"/>
    </div>
    <div class="field">
        <label for="fecha_estimada">
            Fecha estimada
        </label>

        <input
            id="fecha_estimada"
            type="text"
            placeholder="DD/MM/AAAA"
            readonly
            aria-readonly="true"
            autocomplete="off"/>
    </div>

    <div class="field">
        <label for="numero_muestras">
            Número de muestras
        </label>

        <input
            id="numero_muestras"
            name="numero_muestras"
            type="number"
            min="1"
            required
            placeholder="Ej. 7"/>
    </div>
    <div class="field">
        <label for="n_laboratorio_inicio">
            Número de laboratorio
        </label>

        <div class="laboratorio-range">
            <input
                id="n_laboratorio_inicio"
                name="numero_laboratorio_inicio"
                type="text"
                placeholder="Ej. S-492-03-26"
                readonly/>
            <input
                id="n_laboratorio_fin"
                name="numero_laboratorio_fin"
                type="text"
                placeholder="Ej. S-498-03-26"
                readonly/>
        </div>
        <input id="n_laboratorio" name="numero_laboratorio" type="hidden"/>
    </div>
</div>  <!-- ANÁLISIS SOLICITADOS -->
  <div class="section-head">
    <div class="section-title">Análisis solicitados</div>
    <label class="select-all-analisis" for="select-all-analisis">
      <input type="checkbox" id="select-all-analisis" />
      <span>Seleccionar todos los análisis</span>
    </label>
  </div>
  <div class="reception-search">
    <label for="analisis-buscar">Buscar análisis</label>
    <input type="search" id="analisis-buscar" placeholder="Buscar por nombre o método…" autocomplete="off">
  </div>
  <div id="analisis-paquetes" class="reception-packages" aria-label="Paquetes frecuentes"></div><div id="analisis-body"></div><p id="analisis-aviso" class="card-sub" role="status"></p>
  <p id="analisis-vacio" class="reception-empty" hidden>No hay análisis que coincidan con la búsqueda.</p>
  <p id="analisis-resumen" class="card-sub" role="status" aria-live="polite"></p>
  <!-- FIRMAS -->
  <div class="section-title">Responsables y firmas</div>
  <div class="firma-grid">
    <div class="firma-card">
      <span class="firma-label">Ingresado por</span>
      <input class="firma-name-input" name="ingresado_por" type="text" required placeholder="Nombre de quien ingresa" aria-label="Nombre de quien ingresa las muestras" value="<?= htmlspecialchars((string) ($_POST['ingresado_por'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"/>
      <input class="firma-email-input" name="correo_ingresado_por" type="email" placeholder="correo@ejemplo.com" aria-label="Correo de quien ingresa las muestras" value="<?= htmlspecialchars((string) ($_POST['correo_ingresado_por'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"/>
      <canvas class="firma-canvas" id="canvas-ingreso" aria-label="Firma de quien ingresa las muestras"></canvas>
      <div class="firma-actions">
        <button type="button" class="btn-clear" data-clear-canvas="canvas-ingreso">
          <span class="material-symbols-outlined">ink_eraser</span> Limpiar
        </button>
        <span class="firma-hint">Firme con el cursor o el dedo</span>
      </div>
    </div>
    <div class="firma-card">
      <span class="firma-label">Recibido por</span>
      <input class="firma-name-input" name="recibido_por" type="text" required placeholder="Nombre de quien recibe" aria-label="Nombre de quien recibe las muestras" value="<?= htmlspecialchars((string) ($_POST['recibido_por'] ?? ($usuarioActualSolicitud['nombre'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" <?= !empty($usuarioActualSolicitud['nombre']) && !$idSolicitudGet ? 'readonly aria-readonly="true"' : '' ?>/>
      <input class="firma-email-input" name="correo_recibido_por" type="email" placeholder="correo@ejemplo.com" aria-label="Correo de quien recibe las muestras" value="<?= htmlspecialchars((string) ($_POST['correo_recibido_por'] ?? ($usuarioActualSolicitud['correo'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" <?= !empty($usuarioActualSolicitud['correo']) && !$idSolicitudGet ? 'readonly aria-readonly="true"' : '' ?>/>
      <canvas class="firma-canvas" id="canvas-recibe" aria-label="Firma de quien recibe las muestras"></canvas>
      <div class="firma-actions">
        <button type="button" class="btn-clear" data-clear-canvas="canvas-recibe">
          <span class="material-symbols-outlined">ink_eraser</span> Limpiar
        </button>
        <span class="firma-hint">Firme con el cursor o el dedo</span>
      </div>
    </div>
  </div>
   <!-- OBSERVACIONES -->
  <div class="section-title">Observaciones</div>
  <div class="field">
    <textarea id="observaciones" name="observaciones" rows="4"
      placeholder="Detalles adicionales sobre las muestras o el envío..."></textarea>
  </div>
  <?php if ($idEdicion > 0): ?>
  <div class="section-title">Edición de la recepción</div>
  <div class="reception-edit">
    <div class="field">
      <label for="motivo_edicion">Motivo de la edición</label>
      <textarea id="motivo_edicion" name="motivo_edicion" rows="2" required minlength="5" maxlength="500"
        placeholder="Ej. El cliente agregó Boro a las muestras; se corrigió el correo de quien ingresó las muestras."><?= htmlspecialchars((string) ($_POST['motivo_edicion'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
      <small class="card-sub">Al guardar se crea una nueva versión con este motivo, su usuario y la fecha. La versión anterior se conserva.</small>
    </div>
    <div class="reception-versions">
      <h3>Historial de versiones <span class="card-sub">(<?= count($versionesEdicion) ?>)</span></h3>
      <ol>
        <?php foreach (array_reverse($versionesEdicion) as $version): ?>
        <li>
          <div class="reception-version-head">
            <span class="reception-version-num">v<?= (int) $version['version'] ?></span>
            <span><?= htmlspecialchars($version['fecha'] !== '' ? date((int) $version['version'] === 1 ? 'd/m/Y' : 'd/m/Y H:i', strtotime($version['fecha'])) : '—', ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($version['usuario'] ?: '—', ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <div class="reception-version-motivo"><?= htmlspecialchars($version['motivo'], ENT_QUOTES, 'UTF-8') ?></div>
          <?php if ($version['cambios']): ?>
          <details>
            <summary><?= count($version['cambios']) ?> campo(s): <?= htmlspecialchars(implode(', ', array_column($version['cambios'], 'etiqueta')), ENT_QUOTES, 'UTF-8') ?></summary>
            <table class="ticket-table reception-version-cambios">
              <thead><tr><th>Campo</th><th>Anterior</th><th>Nuevo</th></tr></thead>
              <tbody>
                <?php foreach ($version['cambios'] as $cambio): ?>
                <tr><td><?= htmlspecialchars((string) $cambio['etiqueta'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($versionValor($cambio['anterior'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($versionValor($cambio['nuevo'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </details>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ol>
    </div>
  </div>
  <?php endif; ?>
  <div class="form-completion">
    <span>Al registrar se asignan el lote y los números de laboratorio; la boleta queda disponible para ver, descargar o enviar por correo.</span>
    <button type="button" class="reception-btn reception-btn--primary" id="ticket-finalizar">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6M4 4h16v16H4z"/></svg>
      Registrar recepción
    </button>
  </div>
  <!-- FOOTER -->
  <footer class="doc-footer">
    <div class="footer-info">
        <span class = "footer-title">Laboratorio Agroindustrial</span>
      <span>
        <span class="material-symbols-outlined">location_on</span>
        Km 92.5 Carretera a Santa Lucia Cotzumalguapa, Escuintla || Guatemala
    </span>
    <span>
        <span class="material-symbols-outlined">call</span>
            +502 3135-5033
        </span>
    <span>
        <span class="material-symbols-outlined">mail</span>
            laboratorioagroindustrial@cengicana.org
        </span>
    </div>
  </footer>
  </form>

  <section class="reception-card reception-history" aria-labelledby="recepciones-titulo">
    <div class="section-head">
      <div><h2 id="recepciones-titulo">Recepciones registradas</h2><p class="card-sub" id="recepciones-resumen" role="status"><?= (int) $paginaRecepciones['total'] ?> recepciones · <?= (int) $paginaRecepciones['muestras'] ?> muestras</p></div>
      <div class="reception-history-filters">
        <input type="search" id="recepciones-buscar" aria-label="Buscar recepción" placeholder="Buscar lote, cliente o número de laboratorio">
      </div>
    </div>
    <?php // Pestañas por tipo de muestra, en el orden del laboratorio (como En curso / Finalizados en Planificación). ?>
    <input type="hidden" id="recepciones-tipo" value="<?= (int) $tipoInicialRec ?>">
    <div class="reception-type-tabs" role="tablist" aria-label="Recepciones por tipo de muestra">
      <?php foreach ($tabsRecepcion as $tab): ?>
        <button type="button" role="tab" aria-selected="<?= (int) $tab['id'] === (int) $tipoInicialRec ? 'true' : 'false' ?>" data-rec-tipo="<?= (int) $tab['id'] ?>"><?= htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8') ?> <span data-rec-count>(<?= (int) $tab['total'] ?>)</span></button>
      <?php endforeach; ?>
      <?php if (count($tabsRecepcion) > 1): ?>
        <button type="button" role="tab" aria-selected="false" data-rec-tipo="0">Todas <span data-rec-count>(<?= array_sum(array_column($tabsRecepcion, 'total')) ?>)</span></button>
      <?php endif; ?>
    </div>
    <p class="card-sub">Se muestran las <?= LAB_RECEPCIONES_POR_PAGINA ?> recepciones más recientes de cada tipo de muestra; las anteriores están en las páginas siguientes.</p>
    <div class="reception-history-scroll"><table class="ticket-table">
      <thead><tr><th>Lote</th><th>Tipo</th><th>Cliente / institución</th><th>Muestras</th><th>No. laboratorio</th><th>Ingreso</th><th>Recibió</th><th>Acciones</th></tr></thead>
      <tbody id="recepciones-body">
      <?= implode('', array_map('labRecepcionFilaHtml', $paginaRecepciones['filas'])) ?>
      </tbody>
    </table></div>
    <nav class="reception-pager" id="recepciones-paginas" aria-label="Páginas de recepciones registradas"<?= $paginaRecepciones['paginas'] > 1 ? '' : ' hidden' ?>>
      <span class="reception-pager-size" id="recepciones-rango"></span>
      <div class="reception-pager-nav">
        <button type="button" class="reception-btn reception-btn--ghost" id="recepciones-anterior" aria-label="Página anterior" disabled><span class="material-symbols-outlined" aria-hidden="true">chevron_left</span></button>
        <span id="recepciones-pagina-info" aria-live="polite">Página 1 de <?= (int) $paginaRecepciones['paginas'] ?></span>
        <button type="button" class="reception-btn reception-btn--ghost" id="recepciones-siguiente" aria-label="Página siguiente"<?= $paginaRecepciones['paginas'] > 1 ? '' : ' disabled' ?>><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></button>
      </div>
    </nav>
    <script type="application/json" id="recepciones-estado"><?= json_encode(['pagina' => 1, 'paginas' => $paginaRecepciones['paginas'], 'total' => $paginaRecepciones['total'], 'por_pagina' => LAB_RECEPCIONES_POR_PAGINA], JSON_HEX_TAG) ?></script>
    <p id="recepciones-aviso" role="status" class="card-sub"></p><p id="recepciones-vacio" class="reception-empty"<?= $paginaRecepciones['total'] > 0 ? ' hidden' : '' ?>>Sin recepciones con los filtros actuales.</p>
  </section>
</main>
<dialog id="recepcion-boleta-dialog" class="recepcion-boleta-dialog" aria-labelledby="boleta-dialog-titulo">
  <div class="boleta-dialog-head"><h2 id="boleta-dialog-titulo" hidden>Boleta de recepción</h2>
    <button type="button" id="boleta-dialog-cerrar" class="reception-btn reception-btn--ghost" autofocus>Cerrar</button>
  </div>
  <div id="boleta-dialog-contenido"></div>
  <div class="boleta-dialog-actions"><p id="boleta-dialog-aviso" role="status"></p><button type="button" id="boleta-dialog-pdf" class="reception-btn reception-btn--primary">Descargar PDF</button></div>
</dialog>
<script type="application/json" id="solicitudes-db"><?php echo json_encode($solicitudesDb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?></script>
<script type="application/json" id="correlativos-lote-db"><?php echo json_encode($correlativosLoteDb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?></script>
<script type="application/json" id="analisis-catalogo"><?php echo json_encode($catalogoAnalisis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?></script>
<script src="https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>
<script src="../js/solicitud_pdf_layout.js?v=<?= (int) filemtime(__DIR__ . '/../js/solicitud_pdf_layout.js') ?>"></script>
<script src="../../cengicursos/js/qrcode-generator.js"></script>
<script src="../js/recepcion_workflow.js?v=<?= (int) filemtime(__DIR__ . '/../js/recepcion_workflow.js') ?>"></script>
<script src="../js/solicitud_formulario.js?v=<?= (int) filemtime(__DIR__ . '/../js/solicitud_formulario.js') ?>"></script>
<?php lab_shell_content_close(); ?>
</body>
</html>
