<?php
require_once __DIR__ . "/inscripcion_helpers.php";

$db = conectar();

$ingenios = $db->query("SELECT id, nombre_ingenios FROM ingenios ORDER BY nombre_ingenios ASC")->fetchAll(PDO::FETCH_ASSOC);

// Convocatorias abiertas: planificaciones aprobadas que aun no terminan. Su curso se crea
// al llegar al cupo minimo de inscripciones validadas (ver inscripcion_helpers.php).
$convocatorias = $db->query("
    SELECT p.*, c.codigo_curso
    FROM planificacion_cursos p LEFT JOIN cursos c ON c.id = p.curso_id
    WHERE p.estado IN ('aprobado', 'reprogramado') AND p.fecha_fin >= CURDATE()
    ORDER BY p.fecha_inicio, p.nombre
")->fetchAll(PDO::FETCH_ASSOC);

// Cursos creados directamente en Gestion de cursos (sin planificacion): flujo anterior.
$cursos = $db->query("
    SELECT id, nombre_cursos, tipo, jornada_cursos, dias, horario, inicio, fin
    FROM cursos
    WHERE (fin IS NULL OR fin >= CURDATE())
      AND id NOT IN (SELECT curso_id FROM planificacion_cursos WHERE curso_id IS NOT NULL)
    ORDER BY inicio ASC, nombre_cursos ASC
")->fetchAll(PDO::FETCH_ASSOC);

$conteoCupo = cengi_pp_conteo_inscritos($db);
$convocatoriasJs = [];
foreach ($convocatorias as $p) {
    $convocatoriasJs[(int) $p['id']] = [
        'nombre' => $p['nombre'], 'vigente' => cengi_pp_programacion_vigente($db, $p),
        'asociado' => (float) $p['costo_asociados'], 'externo' => (float) $p['costo_externos'], 'extranjero' => (float) $p['costo_extranjero'],
        'cupoMin' => (int) $p['cupo_minimo'], 'cupoMax' => $p['cupo_maximo'] ? (int) $p['cupo_maximo'] : null,
        'validados' => (int) ($conteoCupo[$p['id']] ?? 0), 'abierto' => !empty($p['curso_id']),
    ];
}

// ?plan=ID o ?curso_id=ID (enlace copiado desde Cursos) fijan el curso del formulario.
$destinoBloqueado = null;
$planParam = (int) ($_GET['plan'] ?? 0);
$cursoIdParam = (int) ($_GET['curso_id'] ?? 0);
foreach ($convocatorias as $p) {
    if (($planParam && (int) $p['id'] === $planParam) || ($cursoIdParam && (int) $p['curso_id'] === $cursoIdParam)) {
        $destinoBloqueado = ['valor' => 'p:' . $p['id'], 'etiqueta' => $p['nombre'] . ' — ' . $p['modalidad'] . ' · inicia ' . date('d/m/Y', strtotime($p['fecha_inicio']))];
    }
}
$cursoBloqueado = null;
if (!$destinoBloqueado && $cursoIdParam > 0) {
    foreach ($cursos as $curso) {
        if ((int) $curso['id'] === $cursoIdParam) {
            $cursoBloqueado = $curso;
            $destinoBloqueado = ['valor' => 'c:' . $curso['id'], 'etiqueta' => null];
            break;
        }
    }
}
$hayDestinos = $convocatorias || $cursos;

function cengi_curso_etiqueta(array $curso)
{
    $detalle = [$curso['tipo']];
    if ($curso['jornada_cursos'] !== '') {
        $detalle[] = $curso['jornada_cursos'];
    }
    if ($curso['dias'] !== '') {
        $detalle[] = $curso['dias'];
    }
    if ($curso['horario'] !== '') {
        $detalle[] = $curso['horario'];
    }
    if ($curso['inicio']) {
        $detalle[] = 'Inicia ' . date('d/m/Y', strtotime($curso['inicio']));
    }

    return $curso['nombre_cursos'] . ' — ' . implode(' · ', $detalle);
}

$resultado = trim($_GET['resultado'] ?? '');
$mensajeError = trim($_GET['mensaje'] ?? '');
?>

<!DOCTYPE html>
<html class="light" lang="es">

<head>
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">

    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>

    <title>Solicitud de Inscripción | CENGICURSOS</title>

    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>

    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap" rel="stylesheet"/>

    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>

    <link rel="stylesheet" href="css/inscripcion.css">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: "#03251d",
                        secondary: "#326b00",
                        background: "#f8f9ff",
                        surface: "#ffffff",
                        outline: "#c1c8c4",
                        container: "#eff4ff"
                    },
                    fontFamily: {
                        body: ["Montserrat"]
                    }
                }
            }
        }
    </script>

</head>

<body class="bg-background font-body text-gray-800 overflow-x-hidden">

<!-- HERO -->

<section class="hero-section relative">

    <img
        src="css/images/formulario.jpeg"
        class="absolute inset-0 w-full h-full object-cover"
    >

    <div class="hero-overlay"></div>

    <div class="relative z-10 max-w-7xl mx-auto px-4 md:px-10 h-full flex items-center">

        <div class="text-white max-w-2xl">

            <h2 class="text-4xl md:text-6xl font-extrabold mb-4">
                Solicitud de Inscripción
            </h2>

            <?php if ($destinoBloqueado !== null): ?>
                <p class="text-lg md:text-xl text-white/90">
                    Curso: <?php echo htmlspecialchars($destinoBloqueado['etiqueta'] ?? cengi_curso_etiqueta($cursoBloqueado)); ?>
                </p>
            <?php else: ?>
                <p class="text-lg md:text-xl text-white/90">
                    Gestión moderna de capacitaciones industriales y agrícolas.
                </p>
            <?php endif; ?>

        </div>

    </div>

</section>


<!-- CONTENIDO -->

<main class="max-w-7xl mx-auto px-4 md:px-10 -mt-16 relative z-20 pb-10">

    <?php if ($resultado === 'ok'): ?>
        <div class="cengi-alert is-success" role="status">
            <span class="material-symbols-outlined">check_circle</span>
            <p><?php echo ($_GET['tipo'] ?? '') === 'convocatoria'
                ? 'Tu inscripción fue registrada. Te enviamos un correo con la programación vigente y los siguientes pasos para validar tu pago.'
                : 'Tu solicitud fue enviada correctamente. Nuestro equipo la revisará pronto.'; ?></p>
        </div>
    <?php elseif ($resultado === 'error'): ?>
        <div class="cengi-alert is-error" role="alert">
            <span class="material-symbols-outlined">error</span>
            <p><?php echo htmlspecialchars($mensajeError !== '' ? $mensajeError : 'No se pudo enviar la solicitud. Intenta nuevamente.'); ?></p>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

        <!-- FORMULARIO -->

        <div class="lg:col-span-8 bg-white border border-outline rounded-2xl shadow-lg p-6 md:p-8">

            <div class="flex items-center gap-3 mb-8">

                <span class="material-symbols-outlined text-primary text-3xl">
                    assignment_ind
                </span>

                <h2 class="text-3xl font-bold text-primary">
                    Datos del Participante
                </h2>

            </div>

            <form action="guardar_inscripcion.php" method="POST" id="formInscripcion" enctype="multipart/form-data">

                <!-- NOMBRE Y CUI -->

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

                    <div>
                        <label class="label-form">
                            Nombre Participante
                        </label>

                        <input
                            type="text"
                            name="nombre_participante"
                            class="input-form"
                            maxlength="255"
                            required
                        >
                    </div>

                    <div>
                        <label class="label-form">
                            CUI Participante <span class="text-gray-400 font-normal">(opcional)</span>
                        </label>

                        <input
                            type="text"
                            name="cui_participante"
                            class="input-form"
                            maxlength="25"
                        >

                        <p class="text-xs text-gray-500 mt-1">
                            Si no se proporciona, se asignará un identificador correlativo automático.
                        </p>
                    </div>

                </div>

                <!-- PUESTO, AREA Y GRADO ACADEMICO -->

                <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mt-5">

                    <div>
                        <label class="label-form">
                            Puesto Participante
                        </label>

                        <input
                            type="text"
                            name="puesto_participante"
                            class="input-form"
                            maxlength="255"
                            required
                        >
                    </div>

                    <div>
                        <label class="label-form">
                            Área Participante
                        </label>

                        <input
                            type="text"
                            name="area_participante"
                            class="input-form"
                            maxlength="255"
                            required
                        >
                    </div>

                    <div>
                        <label class="label-form">Grado Académico</label>
                        <input type="text" name="grado_academico" class="input-form" maxlength="255" required>
                    </div>

                </div>

                <!-- CORREO Y TELEFONO -->

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-5">

                    <div>
                        <label class="label-form">
                            Correo
                        </label>

                        <input
                            type="email"
                            name="correo"
                            class="input-form"
                            maxlength="255"
                            required
                        >
                    </div>

                    <div>
                        <label class="label-form">
                            Teléfono
                        </label>

                        <input
                            type="text"
                            name="telefono"
                            class="input-form"
                            maxlength="50"
                            required
                        >
                    </div>

                </div>


                <!-- INGENIOS -->

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-6">

                    <div>
                        <label class="label-form mb-3 block" for="insIngenio">
                            Ingenio / Institución
                        </label>

                        <select name="ingenio_id" id="insIngenio" class="input-form" required>
                            <option value="">Seleccione</option>
                            <?php foreach ($ingenios as $ingenio): ?>
                                <option value="<?php echo (int) $ingenio['id']; ?>">
                                    <?php echo htmlspecialchars($ingenio['nombre_ingenios']); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="otra">Otra institución u organización</option>
                        </select>
                    </div>

                    <div id="insOtraWrap" hidden>
                        <label class="label-form mb-3 block" for="insOtra">Nombre de la institución</label>
                        <input type="text" name="institucion_otra" id="insOtra" class="input-form" maxlength="255">
                    </div>

                </div>


                <!-- CURSOS -->

                <div class="mt-8">

                    <label class="label-form mb-3 block" for="insDestino">
                        Curso
                    </label>

                    <?php if ($destinoBloqueado !== null): ?>

                        <input type="text" class="input-form" readonly disabled
                            value="<?php echo htmlspecialchars($destinoBloqueado['etiqueta'] ?? cengi_curso_etiqueta($cursoBloqueado)); ?>">
                        <input type="hidden" name="destino" id="insDestino" value="<?php echo htmlspecialchars($destinoBloqueado['valor']); ?>">

                    <?php else: ?>

                        <select name="destino" id="insDestino" class="input-form" required <?php echo !$hayDestinos ? 'disabled' : ''; ?>>
                            <option value=""><?php echo $hayDestinos ? 'Seleccione' : 'No hay cursos disponibles por el momento'; ?></option>
                            <?php if ($convocatorias): ?>
                                <optgroup label="Convocatorias abiertas">
                                    <?php foreach ($convocatorias as $p): ?>
                                        <option value="p:<?php echo (int) $p['id']; ?>">
                                            <?php echo htmlspecialchars($p['nombre'] . ' — ' . $p['modalidad'] . ' · ' . cengi_pp_horario($p['hora_inicio'], $p['hora_fin']) . ' · inicia ' . date('d/m/Y', strtotime($p['fecha_inicio']))); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <?php if ($cursos): ?>
                                <optgroup label="Otros cursos">
                                    <?php foreach ($cursos as $curso): ?>
                                        <option value="c:<?php echo (int) $curso['id']; ?>"><?php echo htmlspecialchars(cengi_curso_etiqueta($curso)); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>

                    <?php endif; ?>

                    <!-- Programacion vigente y cupo de la convocatoria -->
                    <div id="insVigente" class="mt-3 rounded-xl bg-container p-4 text-sm text-gray-700" hidden>
                        <p class="font-semibold text-primary mb-1">Programación vigente</p>
                        <p id="insVigenteTexto" style="white-space:pre-line;"></p>
                        <div class="mt-3">
                            <div class="h-2 rounded-full bg-white overflow-hidden"><div id="insCupoBarra" class="h-2 bg-secondary" style="width:0%"></div></div>
                            <p id="insCupoTexto" class="text-xs text-gray-500 mt-1"></p>
                        </div>
                    </div>

                </div>


                <!-- CUOTA Y PAGO -->

                <div id="insPagoBloque" class="mt-8">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5" id="insCuotaWrap" hidden>
                        <div>
                            <label class="label-form mb-3 block" for="insCuota">Cuota que le corresponde</label>
                            <select name="cuota" id="insCuota" class="input-form">
                                <?php foreach (CENGI_INS_CUOTAS as $clave => [$texto, $detalle]): ?>
                                    <option value="<?php echo $clave; ?>"><?php echo $texto . ' (' . $detalle . ')'; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <p id="insMontoTexto" class="text-sm text-gray-700 pb-3"></p>
                        </div>
                    </div>

                    <div class="mt-5" id="insQuienPagaWrap">
                        <label class="label-form mb-3 block" for="insTipoPago">¿Quién paga la inscripción?</label>
                        <select name="tipo_pago" id="insTipoPago" class="input-form" required>
                            <option value="">Seleccione</option>
                            <option value="Propio">Pago propio: pago yo y envío el comprobante</option>
                            <option value="Ingenio">Lo paga mi ingenio (orden de compra o factura de grupo)</option>
                        </select>
                        <p id="insQuienPagaAyuda" class="text-xs text-gray-500 mt-1"></p>
                    </div>

                    <div id="insPropioWrap" class="mt-5 rounded-xl border border-outline p-4" hidden>
                        <p class="text-sm text-gray-600 mb-4">Si ya pagaste, adjunta el comprobante (boleta o transferencia). También puedes enviarlo después; Administración lo valida antes de asignarte al curso.</p>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                            <div>
                                <label class="label-form" for="insBoleta">N.° de boleta / transferencia</label>
                                <input type="text" name="boleta" id="insBoleta" class="input-form" maxlength="60">
                            </div>
                            <div>
                                <label class="label-form" for="insFechaPago">Fecha del pago</label>
                                <input type="date" name="pago_fecha" id="insFechaPago" class="input-form" max="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div>
                                <label class="label-form" for="insMontoComp">Monto pagado</label>
                                <input type="number" name="monto_comprobante" id="insMontoComp" class="input-form" min="0" step="0.01">
                            </div>
                        </div>
                        <div class="mt-4">
                            <label class="label-form" for="insComprobante">Comprobante <span class="text-gray-400 font-normal">(PDF, JPG o PNG · máx. 5 MB)</span></label>
                            <input type="file" name="comprobante" id="insComprobante" class="input-form" accept=".pdf,.jpg,.jpeg,.png">
                        </div>
                    </div>

                    <div class="mt-5">
                        <label class="label-form mb-3 block" for="insEntero">¿Cómo se enteró del curso? <span class="text-gray-400 font-normal">(opcional)</span></label>
                        <select name="como_se_entero" id="insEntero" class="input-form">
                            <option value="">Seleccione</option>
                            <option>Correo institucional</option>
                            <option>Su ingenio</option>
                            <option>Redes sociales</option>
                            <option>Recomendación</option>
                            <option>Otro</option>
                        </select>
                    </div>

                </div>


<!-- BOTON -->

                <div class="mt-10">

                    <button
                        type="submit"
                        id="btnEnviar"
                        class="btn-submit"
                    >

                        <span class="material-symbols-outlined">
                            send
                        </span>

                        Guardar Solicitud

                    </button>

                </div>

            </form>

        </div>


        <!-- SIDEBAR -->

        <aside class="lg:col-span-4 flex flex-col gap-6">

            <div class="sidebar-card-primary">

                <div class="space-y-3 text-sm">

                    <div class="flex gap-2">
                        <span class="material-symbols-outlined">
                            check_circle
                        </span>

                        <p>Certificación avalada.</p>
                    </div>

                    <div class="flex gap-2">
                        <span class="material-symbols-outlined">
                            check_circle
                        </span>

                        <p>Capacitación especializada.</p>
                    </div>

                </div>

            </div>

            <div class="bg-white rounded-2xl shadow-md overflow-hidden">

                <img
                    src="css/images/cengi.png"
                    class="w-40 mx-auto object-contain py-4"
                >

                <div class="p-5">

                    <h3 class="font-bold text-xl text-primary mb-2">
                        CENGICAÑA DIGITAL
                        PARA INSCRIBIRTE A LOS CURSOS QUE QUIERAS
                    </h3>

                </div>

            </div>

            <div class="bg-white border border-outline rounded-2xl shadow-md p-5">

                <div class="flex items-center gap-2 mb-2">
                    <span class="material-symbols-outlined text-secondary">groups</span>
                    <h3 class="font-bold text-primary">¿Varios participantes?</h3>
                </div>

                <p class="text-sm text-gray-500 mb-4">
                    Si inscribes a un grupo, carga un CSV con los datos de todos en lugar de llenar el formulario uno por uno.
                </p>

                <button
                    type="button"
                    id="btnAbrirCargaMasiva"
                    class="w-full px-4 py-3 rounded-xl bg-secondary text-white font-semibold flex items-center justify-center gap-2 hover:opacity-90 transition"
                >
                    <span class="material-symbols-outlined">upload_file</span>
                    Carga masiva (CSV)
                </button>

            </div>

        </aside>

    </div>

</main>


<!-- MODAL CARGA MASIVA -->

<div id="modalCargaMasiva" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">

    <div class="bg-white rounded-2xl shadow-lg w-full max-w-xl p-6 md:p-8 relative max-h-[90vh] overflow-y-auto">

        <button type="button" id="btnCerrarCargaMasiva" class="absolute top-4 right-4 text-gray-400 hover:text-gray-700">
            <span class="material-symbols-outlined">close</span>
        </button>

        <div class="flex items-center gap-3 mb-6">
            <span class="material-symbols-outlined text-primary text-3xl">upload_file</span>
            <h2 class="text-2xl font-bold text-primary">Carga masiva de participantes</h2>
        </div>

        <p class="text-sm text-gray-500 mb-6">
            Todos los participantes del archivo se inscribirán al mismo curso y tipo de pago que selecciones aquí; el ingenio de cada participante se toma del archivo.
        </p>

        <form action="carga_inscripcion.php" method="POST" enctype="multipart/form-data" id="formCargaMasiva">

            <div class="mt-5">
                <label class="label-form">Curso</label>
                <select name="curso_id" class="input-form" required <?php echo !$cursos ? 'disabled' : ''; ?>>
                    <option value="">
                        <?php echo $cursos ? 'Seleccione' : 'No hay cursos disponibles por el momento'; ?>
                    </option>
                    <?php foreach ($cursos as $curso): ?>
                        <option value="<?php echo (int) $curso['id']; ?>">
                            <?php echo htmlspecialchars(cengi_curso_etiqueta($curso)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mt-5">
                <label class="label-form">Tipo Pago</label>
                <select name="tipo_pago" class="input-form" required>
                    <option value="">Seleccione</option>
                    <option value="Ingenio">Ingenio</option>
                    <option value="Propio">Propio</option>
                </select>
            </div>

            <div class="mt-6 border-t border-outline pt-5">

                <label class="label-form">Archivo CSV</label>

                <div class="rounded-xl bg-container p-4 text-sm text-gray-600 mb-3">
                    <strong>Formato requerido:</strong> INGENIO, CUI, NOMBRE, PUESTO, AREA, CORREO_ELECTRONICO, GRADO_ACADEMICO, TELEFONO
                    <br>
                    <a href="plantilla_participantes.php" class="text-secondary font-semibold underline">Descargar plantilla (Excel)</a>
                    <br>
                    <span class="text-xs text-gray-500">Completa la plantilla y sube el mismo archivo (Excel o CSV).</span>
                </div>

                <input type="file" name="archivo" accept=".csv,.xls" class="input-form" required>

            </div>

            <div class="mt-8">
                <button type="submit" id="btnEnviarCargaMasiva" class="btn-submit">
                    <span class="material-symbols-outlined">send</span>
                    Cargar participantes
                </button>
            </div>

        </form>

    </div>

</div>

<script>
// Convocatorias: programacion vigente, cupo y cuotas. Los cursos "c:" usan el flujo anterior.
(function () {
    'use strict';
    var CONV = <?php echo json_encode($convocatoriasJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var NOMBRES = { asociado: 'asociado', externo: 'externo', extranjero: 'extranjero' };
    function $(id) { return document.getElementById(id); }
    function dinero(m, cuota) { return (cuota === 'extranjero' ? 'US$' : 'Q') + Number(m).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    var destino = $('insDestino'), ingenio = $('insIngenio'), cuota = $('insCuota'), tipo = $('insTipoPago');
    var cuotaManual = false;

    function plan() { var v = destino ? destino.value : ''; return v.indexOf('p:') === 0 ? CONV[v.slice(2)] : null; }
    function actualizar() {
        var p = plan(), otra = ingenio.value === 'otra';
        $('insOtraWrap').hidden = !otra; $('insOtra').required = otra;
        if (!cuotaManual) cuota.value = otra ? 'externo' : 'asociado';
        var opIngenio = tipo.querySelector('option[value="Ingenio"]');
        opIngenio.disabled = otra || !ingenio.value;
        if (opIngenio.disabled && tipo.value === 'Ingenio') tipo.value = '';

        $('insVigente').hidden = !p;
        $('insCuotaWrap').hidden = !p;
        var monto = p ? p[cuota.value] : null, gratis = p && monto === 0;
        if (p) {
            $('insVigenteTexto').textContent = p.vigente;
            var pct = Math.min(100, Math.round(p.validados * 100 / Math.max(1, p.cupoMin)));
            $('insCupoBarra').style.width = pct + '%';
            $('insCupoTexto').textContent = p.abierto
                ? 'Curso confirmado · ' + p.validados + ' inscritos' + (p.cupoMax ? ' de ' + p.cupoMax + ' lugares' : '') + (p.cupoMax && p.validados >= p.cupoMax ? ' (cupo lleno: quedarías en lista de espera)' : '')
                : p.validados + ' de ' + p.cupoMin + ' inscripciones validadas para abrir el curso';
            [].forEach.call(cuota.options, function (o) { o.textContent = o.textContent.replace(/ — .*$/, '') + ' — ' + (p[o.value] === 0 ? 'sin costo' : dinero(p[o.value], o.value)); });
            $('insMontoTexto').innerHTML = gratis ? '<b>Este curso no tiene costo para tu cuota.</b>' : 'Debes pagar <b>' + dinero(monto, cuota.value) + '</b> (cuota ' + NOMBRES[cuota.value] + ').';
        }
        // Sin costo: no se pregunta quien paga.
        $('insQuienPagaWrap').hidden = !!gratis; tipo.required = !gratis;
        if (gratis) tipo.value = '';
        $('insPropioWrap').hidden = !(p && !gratis && tipo.value === 'Propio');
        $('insQuienPagaAyuda').textContent = tipo.value === 'Ingenio' && p
            ? 'Tu ingenio debe validar tu inscripción y cubre la cuota en su orden de grupo; no necesitas pagar.'
            : (tipo.value === 'Propio' && p ? 'Administración valida tu comprobante antes de asignarte al curso.' : '');
        var archivo = $('insComprobante');
        ['insBoleta', 'insFechaPago', 'insMontoComp'].forEach(function (id) { $(id).required = !!(archivo.files && archivo.files.length) && !$('insPropioWrap').hidden; });
    }
    [destino, ingenio, tipo].forEach(function (e) { if (e) e.addEventListener('change', actualizar); });
    cuota.addEventListener('change', function () { cuotaManual = true; actualizar(); });
    $('insComprobante').addEventListener('change', function () {
        if (this.files[0] && this.files[0].size > 5 * 1024 * 1024) { alert('El comprobante supera los 5 MB.'); this.value = ''; }
        actualizar();
    });
    actualizar();
})();
</script>

<script src="js/inscripcion.js?v=<?php echo rawurlencode((string) filemtime(__DIR__ . '/js/inscripcion.js')); ?>"></script>

</body>
</html>
