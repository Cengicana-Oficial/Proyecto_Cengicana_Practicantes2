<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/solicitud_formulario_helpers.php';
require_once __DIR__ . '/../includes/catalogo_analisis_helper.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../models/trazabilidad_model.php';

lab_require_module_access();
asegurarColumnasFirmasSolicitud($conexion);
labCatalogoAnalisisAsegurarEsquema($conexion);

$catalogoMuestras = labCatalogoMuestrasFormularioData($conexion, false);
$catalogoAnalisis = labCatalogoAnalisisFormularioData($conexion);

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
$correlativosDb = [];
$loteSeleccionado = trim((string) ($_GET['lote'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $idSolicitudPost = !empty($_POST['id_solicitud']) ? (int) $_POST['id_solicitud'] : null;
  lab_require_permission($idSolicitudPost ? 'laboratorio.solicitudes.editar' : 'laboratorio.solicitudes.crear');

  try {
    $conexion->beginTransaction();

    $tipoFormulario = (string) ($_POST['tipo_form'] ?? $tipoFormularioInicial);
    $tipoMuestra = labCatalogoMuestrasObtenerPorClave($conexion, $tipoFormulario, !$idSolicitudPost ? true : false);
    if (!$tipoMuestra) {
      throw new RuntimeException('El tipo de muestra seleccionado ya no está disponible.');
    }
    $codigoMuestreo = trim((string) ($_POST['codigo_muestreo'] ?? ''));
    $codigoLote = trim($_POST['lote'] ?? '');
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
    $idSolicitud = $idSolicitudPost;

    if (!is_array($analisisSeleccionados)) {
      $analisisSeleccionados = [$analisisSeleccionados];
    }

    if ($codigoLote === '') {
      throw new RuntimeException('Ingrese o seleccione un número de lote.');
    }

    if (!$fechaMuestreo) {
      throw new RuntimeException('Ingrese la fecha de muestreo.');
    }

    $idLote = obtenerLote($conexion, $codigoLote);
    $fechaIngresoActual = date('Y-m-d');
    $solicitudExistente = null;

    if ($idSolicitud) {
      $stmtSolicitudExistente = $conexion->prepare("
        SELECT id_solicitud, id_tipo, fecha_ingreso, fecha_estimada
        FROM solicitud
        WHERE id_solicitud = ?
        LIMIT 1
      ");
      $stmtSolicitudExistente->execute([$idSolicitud]);
      $solicitudExistente = $stmtSolicitudExistente->fetch(PDO::FETCH_ASSOC) ?: null;

      if (!$solicitudExistente) {
        throw new RuntimeException('La solicitud que intenta editar no existe.');
      }
    }

    $fechaEstimadaNueva = calcularFechaEstimadaSolicitud($fechaIngresoActual, $tipoMuestra);
    $tipoMuestraCambia = $solicitudExistente
      && (int) ($solicitudExistente['id_tipo'] ?? 0) !== (int) $tipoMuestra['id_tipo'];

    if ($idSolicitud) {
      if ($tipoMuestraCambia) {
        $fechaIngresoOriginal = trim((string) ($solicitudExistente['fecha_ingreso'] ?? ''));
        if ($fechaIngresoOriginal === '') {
          $fechaIngresoOriginal = $fechaIngresoActual;
        }

        $fechaEstimadaPersistida = calcularFechaEstimadaSolicitud($fechaIngresoOriginal, $tipoMuestra);

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

    $inicioExistente = obtenerInicioLaboratorioSolicitud($conexion, $idSolicitud);
    $loteInicial = $inicioExistente ?: obtenerInicioLaboratorioNuevo($conexion, $tipoMuestra['prefijo'], $tipoMuestra['nombre'], $numeroMuestras);
    $longitudLote = max(3, strlen((string) $loteInicial));
    $loteFinal = $loteInicial + $numeroMuestras - 1;
    if ($inicioExistente) {
      sincronizarCorrelativo($conexion, $tipoMuestra['prefijo'], $loteFinal);
    }
    $mesAnio = mesAnioDesdeFecha($fechaMuestreo);
    $codigoInicio = construirCodigoLab($tipoMuestra['prefijo'], $loteInicial, $mesAnio, $longitudLote);
    $codigoFin = construirCodigoLab($tipoMuestra['prefijo'], $loteFinal, $mesAnio, $longitudLote);

    $conexion->prepare("DELETE FROM muestra WHERE id_solicitud = ?")->execute([$idSolicitud]);
    $insertMuestra = $conexion->prepare("INSERT INTO muestra (id_solicitud, numero_muestra, codigo_lab) VALUES (?, ?, ?)");

    for ($i = 0; $i < $numeroMuestras; $i++) {
      $numeroLaboratorio = $loteInicial + $i;
      $codigoLab = construirCodigoLab($tipoMuestra['prefijo'], $numeroLaboratorio, $mesAnio, $longitudLote);
      $insertMuestra->execute([$idSolicitud, $numeroLaboratorio, $codigoLab]);
    }

    $idRango = obtenerRango($conexion, $idLote, $loteInicial, $loteFinal);

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

    $conexion->commit();

    $usuarioActualTraz = function_exists('lab_current_user') ? lab_current_user() : [];
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

    $message = "Solicitud #{$idSolicitud} guardada. Número de laboratorio: {$codigoInicio} a {$codigoFin}.";
  } catch (Exception $e) {
    if ($conexion->inTransaction()) {
      $conexion->rollBack();
    }
    $message = 'Error al guardar la solicitud: ' . $e->getMessage();
  }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  lab_require_permission('laboratorio.solicitudes.crear');
}

try {
  sincronizarCorrelativosConMuestras($conexion);

  $stmtCorrelativos = $conexion->query("
    SELECT tipo_muestra, prefijo, ultimo_numero
    FROM correlativo_envio_solicitud
  ");
  $correlativosDb = $stmtCorrelativos->fetchAll();

  $sqlSolicitudes = "
    SELECT
      s.id_solicitud,
      s.codigo_muestreo,
      s.fecha_muestreo,
      s.fecha_ingreso,
      s.fecha_estimada,
      s.numero_muestras,
      s.institucion,
      s.responsable_envio,
      s.ingresado_por,
      s.correo_ingresado,
      s.recibido_por,
      s.correo_recibido,
      s.observaciones,
      l.codigo_lote,
      tm.nombre AS tipo_nombre,
      tm.prefijo,
      mr.inicio_laboratorio,
      mr.fin_laboratorio
    FROM solicitud s
    LEFT JOIN lote l ON l.id_lote = s.id_lote
    LEFT JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo
    LEFT JOIN (
      SELECT
        id_solicitud,
        MIN(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(codigo_lab, '-', 2), '-', -1) AS UNSIGNED)) AS inicio_laboratorio,
        MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(codigo_lab, '-', 2), '-', -1) AS UNSIGNED)) AS fin_laboratorio
      FROM muestra
      WHERE codigo_lab IS NOT NULL AND codigo_lab <> ''
      GROUP BY id_solicitud
    ) mr ON mr.id_solicitud = s.id_solicitud
    ORDER BY s.id_solicitud DESC
    LIMIT 100
  ";

  $stmtSolicitudes = $conexion->query($sqlSolicitudes);
  $solicitudesDb = $stmtSolicitudes->fetchAll();
} catch (Exception $e) {
  $dbWarning = 'No se pudieron cargar las solicitudes desde la base de datos: ' . $e->getMessage();
}

$recepcionesHoy = array_values(array_filter($solicitudesDb, static function (array $solicitud): bool {
  return (string) ($solicitud['fecha_ingreso'] ?? '') === date('Y-m-d');
}));
$recepcionesHoy = array_slice($recepcionesHoy, 0, 5);

?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Laboratorios AgroLab — Boleta de Solicitud</title>
<link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../css/lab_shell.css?v=1">
<link rel="stylesheet" href="../css/solicitud_formulario.css?v=8">
<link rel="stylesheet" href="../styles/recepcion_template.css?v=1">
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

  <?php if (!empty($message)): ?>
    <div style="padding:12px;margin-bottom:14px;border-radius:8px;background:#e9f7e7;border:1px solid #c7e5c8;color:#184d12">
      <?php echo htmlspecialchars($message); ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($dbWarning)): ?>
    <div style="padding:12px;margin-bottom:14px;border-radius:8px;background:#fff7e6;border:1px solid #f1d08a;color:#6b4600">
      <?php echo htmlspecialchars($dbWarning); ?>
    </div>
  <?php endif; ?>

  <form id="solicitud-form" method="post">
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
        <input class="lote-input" type="text" placeholder="Ej. 185" aria-label="Número de lote"/>
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
            placeholder="Ej. 185"
            value="<?= htmlspecialchars($loteSeleccionado, ENT_QUOTES, 'UTF-8') ?>"/>
    </div>
    <div class="field">
        <label for="numero_de_muestra">Lote de campo / codigo de muestreo</label>
        <input id="numero_de_muestra" name="codigo_muestreo" type="text" placeholder="Ej. Lote 4B" value="<?= htmlspecialchars((string) ($_POST['codigo_muestreo'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"/>
    </div>
    <div class="field">
        <label for="fecha_muestreo">
            Fecha de muestreo
        </label>

        <input
            id="fecha_muestreo"
            name="fecha_de_muestreo"
            type="date"/>
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
  <div class="analisis-wrap">
    <table class="analisis-table">
      <thead>
        <tr>
          <th>Análisis</th>
          <th class="center" style="width:110px">Tipo</th>
          <th class="center" style="width:80px">Solicitar</th>
        </tr>
      </thead>
      <tbody id="analisis-body"></tbody>
    </table>
  </div>

  <!-- FIRMAS -->
  <div class="section-title">Responsables y firmas</div>
  <div class="firma-grid">
    <div class="firma-card">
      <span class="firma-label">Ingresado por</span>
      <input class="firma-name-input" name="ingresado_por" type="text" placeholder="Nombre del analista" aria-label="Nombre del analista"/>
      <input class="firma-email-input" name="correo_ingresado_por" type="email" placeholder="correo@ejemplo.com" aria-label="Correo del analista"/>
      <canvas class="firma-canvas" id="canvas-ingreso" aria-label="Campo de firma — ingresado por"></canvas>
      <div class="firma-actions">
        <button type="button" class="btn-clear" data-clear-canvas="canvas-ingreso">
          <span class="material-symbols-outlined">ink_eraser</span> Limpiar
        </button>
        <span class="firma-hint">Firme con el cursor o el dedo</span>
      </div>
    </div>
    <div class="firma-card">
      <span class="firma-label">Recibido por</span>
      <input class="firma-name-input" name="recibido_por" type="text" placeholder="Nombre del receptor" aria-label="Nombre del receptor"/>
      <input class="firma-email-input" name="correo_recibido_por" type="email" placeholder="correo@ejemplo.com" aria-label="Correo del receptor"/>
      <canvas class="firma-canvas" id="canvas-recibe" aria-label="Campo de firma — recibido por"></canvas>
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
  <div class="form-completion">
    <span>Se generara un codigo individual para cada muestra del lote</span>
    <button type="button" class="reception-btn reception-btn--primary" id="ticket-finalizar">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6M4 4h16v16H4z"/></svg>
      Generar boleta de solicitud de analisis
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

  <aside class="reception-side" aria-label="Vista previa de la boleta">
    <section class="reception-card ticket-panel">
      <h2>Boleta de solicitud de analisis</h2>
      <p class="card-sub">Documento generado para respaldar la trazabilidad del lote y enviarlo a los responsables.</p>

      <div class="boleta-doc">
        <div class="boleta-head">
          <div>
            <strong class="ticket-lot" id="ticket-lote">LOTE —</strong>
            <div class="ticket-client" id="ticket-cliente">Cliente —</div>
            <div class="ticket-client" id="ticket-campo">Lote de campo —</div>
            <span class="status-chip">RECIBIDA</span>
          </div>
          <div class="ticket-mark" aria-hidden="true">
            <span>VF</span><b>005</b>
          </div>
        </div>
        <div class="boleta-body">
          <p id="ticket-meta">Completa los datos para ver aqui el resumen de la recepcion.</p>
          <div class="ticket-table-wrap">
            <table class="ticket-table">
              <thead><tr><th>Codigo</th><th>Tipo</th><th>Analisis solicitados</th></tr></thead>
              <tbody id="ticket-muestras"><tr><td colspan="3" class="ticket-empty">Aun no se ha completado la muestra.</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="ticket-actions">
        <button type="button" class="reception-btn reception-btn--primary" id="ticket-generar-pdf">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h8l4 4v16H6zM14 2v5h5M8 15h3a2 2 0 0 0 0-4H8v7M14 18v-7h4"/></svg>
          Generar y enviar PDF
        </button>
        <button type="button" class="reception-btn reception-btn--ghost" onclick="window.print()">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
          Imprimir boleta
        </button>
      </div>

      <div class="panel-divider"></div>
      <div class="recent-head">
        <h2>Lotes recibidos hoy</h2>
        <span><?= count($recepcionesHoy) ?></span>
      </div>
      <div class="recent-list">
        <?php if (!$recepcionesHoy): ?>
          <div class="recent-empty">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16v13H4zM8 4h8v3M8 12h8M8 16h5"/></svg>
            <div><strong>Sin recepciones hoy</strong><small>Los lotes guardados apareceran aqui.</small></div>
          </div>
        <?php else: ?>
          <?php foreach ($recepcionesHoy as $recepcion): ?>
            <a class="recent-item" href="?id_solicitud=<?= (int) $recepcion['id_solicitud'] ?>">
              <span class="recent-icon"><?= htmlspecialchars(strtoupper((string) ($recepcion['prefijo'] ?? 'L')), ENT_QUOTES, 'UTF-8') ?></span>
              <span class="recent-copy">
                <strong><?= htmlspecialchars((string) ($recepcion['codigo_lote'] ?? 'Sin lote'), ENT_QUOTES, 'UTF-8') ?></strong>
                <small><?= htmlspecialchars((string) ($recepcion['tipo_nombre'] ?? 'Muestra'), ENT_QUOTES, 'UTF-8') ?> · <?= (int) ($recepcion['numero_muestras'] ?? 0) ?> muestra(s)</small>
              </span>
              <span class="recent-status">Recibida</span>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>
  </aside>
</main>
<!-- FAB -->
  <aside class="actions-panel" aria-label="Acciones de solicitud">
    <div class="actions-rail">
      <span class="fab-icon material-symbols-outlined">add</span>
      <span class="actions-label">Acciones</span>
    </div>
    <div class="actions-stack">
      <button type="button" class="fab secondary" id="btn-generar-pdf">
        <span class="fab-icon material-symbols-outlined">picture_as_pdf</span>
        <span class="fab-text">
          <span class="fab-label">Generar PDF</span>
          <span class="fab-description">Descarga y envía el PDF</span>
        </span>
      </button>
      <button type="button" class="fab primary" id="btn-finalizar-solicitud">
        <span class="fab-icon material-symbols-outlined">send</span>
        <span class="fab-text">
          <span class="fab-label">Finalizar solicitud</span>
          <span class="fab-description">Genera y envía antes de guardar</span>
        </span>
      </button>
    </div>
  </aside>
<script type="application/json" id="solicitudes-db"><?php echo json_encode($solicitudesDb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?></script>
<script type="application/json" id="correlativos-db"><?php echo json_encode($correlativosDb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?></script>
<script type="application/json" id="analisis-catalogo"><?php echo json_encode($catalogoAnalisis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?></script>
<script src="https://unpkg.com/pdf-lib/dist/pdf-lib.min.js"></script>
<script src="../js/solicitud_formulario.js?v=8"></script>
<?php lab_shell_content_close(); ?>
</body>
</html>
