<?php
require_once __DIR__ . "/conexion.php";

$db = conectar();

$ingenios = $db->query("SELECT id, nombre_ingenios FROM ingenios ORDER BY nombre_ingenios ASC")->fetchAll(PDO::FETCH_ASSOC);

$cursos = $db->query("
    SELECT id, nombre_cursos, tipo, jornada_cursos, dias, horario, inicio, fin
    FROM cursos
    WHERE fin IS NULL OR fin >= CURDATE()
    ORDER BY inicio ASC, nombre_cursos ASC
")->fetchAll(PDO::FETCH_ASSOC);

$cursoIdParam = (int) ($_GET['curso_id'] ?? 0);
$cursoBloqueado = null;

if ($cursoIdParam > 0) {
    foreach ($cursos as $curso) {
        if ((int) $curso['id'] === $cursoIdParam) {
            $cursoBloqueado = $curso;
            break;
        }
    }

    if ($cursoBloqueado !== null) {
        $cursos = [$cursoBloqueado];
    }
}

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

            <?php if ($cursoBloqueado !== null): ?>
                <p class="text-lg md:text-xl text-white/90">
                    Curso: <?php echo htmlspecialchars(cengi_curso_etiqueta($cursoBloqueado)); ?>
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
            <p>Tu solicitud fue enviada correctamente. Nuestro equipo la revisará pronto.</p>
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

            <form action="guardar_inscripcion.php" method="POST" id="formInscripcion">

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

                <div class="mt-6">

                    <label class="label-form mb-3 block">
                        Ingenio
                    </label>

                    <select
                        name="ingenio_id"
                        class="input-form"
                        required
                    >

                        <option value="">
                            Seleccione
                        </option>

                        <?php foreach ($ingenios as $ingenio): ?>
                            <option value="<?php echo (int) $ingenio['id']; ?>">
                                <?php echo htmlspecialchars($ingenio['nombre_ingenios']); ?>
                            </option>
                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- CURSOS -->

                <div class="mt-8">

                    <label class="label-form mb-3 block">
                        Curso
                    </label>

                    <?php if ($cursoBloqueado !== null): ?>

                        <input
                            type="text"
                            class="input-form"
                            value="<?php echo htmlspecialchars(cengi_curso_etiqueta($cursoBloqueado)); ?>"
                            readonly
                            disabled
                        >

                        <input type="hidden" name="curso_id" value="<?php echo (int) $cursoBloqueado['id']; ?>">

                    <?php else: ?>

                        <select
                            name="curso_id"
                            class="input-form"
                            required
                            <?php echo !$cursos ? 'disabled' : ''; ?>
                        >

                            <option value="">
                                <?php echo $cursos ? 'Seleccione' : 'No hay cursos disponibles por el momento'; ?>
                            </option>

                            <?php foreach ($cursos as $curso): ?>
                                <option value="<?php echo (int) $curso['id']; ?>">
                                    <?php echo htmlspecialchars(cengi_curso_etiqueta($curso)); ?>
                                </option>
                            <?php endforeach; ?>

                        </select>

                    <?php endif; ?>

                </div>


                <!-- TIPO PAGO -->

                <div class="mt-8">

                    <label class="label-form mb-3 block">
                        Tipo Pago
                    </label>

                    <select
                        name="tipo_pago"
                        class="input-form"
                        required
                    >

                        <option value="">
                            Seleccione
                        </option>

                        <option value="Ingenio">
                            Ingenio
                        </option>

                        <option value="Propio">
                            Propio
                        </option>

                    </select>

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

<script src="js/inscripcion.js?v=<?php echo rawurlencode((string) filemtime(__DIR__ . '/js/inscripcion.js')); ?>"></script>

</body>
</html>
