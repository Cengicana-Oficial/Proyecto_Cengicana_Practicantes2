<?php
require_once __DIR__ . "/conexion.php";

const CENGI_INSCRIPCION_MASIVA_MAX_FILAS = 500;
const CENGI_INSCRIPCION_MASIVA_MAX_BYTES = 5 * 1024 * 1024;

function cengi_carga_inscripcion_error($mensaje)
{
    header("Location: inscripcion.php?resultado=error&mensaje=" . rawurlencode($mensaje));
    exit;
}

function cengi_carga_inscripcion_valor_excel($valor)
{
    if ($valor === null) {
        return '';
    }

    return trim((string) $valor);
}

function cengi_carga_inscripcion_filas($archivoTemporal, $extension)
{
    if ($extension === 'csv') {
        $handle = fopen($archivoTemporal, 'r');
        if ($handle === false) {
            cengi_carga_inscripcion_error('No fue posible abrir el archivo.');
        }

        $filas = [];
        while (($fila = fgetcsv($handle, 0, ',')) !== false) {
            $filas[] = $fila;
        }
        fclose($handle);

        return $filas;
    }

    require_once __DIR__ . '/classes/PHPExcel.php';
    require_once __DIR__ . '/classes/PHPExcel/IOFactory.php';

    // La libreria PHPExcel vendorizada es de 2014 y crea propiedades dinamicas
    // en sus objetos (algo deprecado desde PHP 8.1+), incluyendo en
    // PHPExcel_Calculation, que es un singleton de todo el proceso: su aviso
    // se dispara al destruirse, ya al final del script, sin importar cuando
    // se restaure error_reporting antes. Por eso aqui NO se restaura: se deja
    // suprimido (salvo errores fatales) por el resto de la ejecucion, igual
    // que hace classes/export_helpers.php (que tampoco lo restaura).
    error_reporting(E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR);

    try {
        $libro = PHPExcel_IOFactory::load($archivoTemporal);
    } catch (Throwable $e) {
        cengi_carga_inscripcion_error('El archivo Excel está dañado o no tiene un formato válido.');
    }

    $hoja = $libro->getActiveSheet();
    $maxFila = $hoja->getHighestRow();
    $filas = [];

    for ($fila = 1; $fila <= $maxFila; $fila++) {
        $filas[] = [
            cengi_carga_inscripcion_valor_excel($hoja->getCellByColumnAndRow(0, $fila)->getCalculatedValue()),
            cengi_carga_inscripcion_valor_excel($hoja->getCellByColumnAndRow(1, $fila)->getCalculatedValue()),
            cengi_carga_inscripcion_valor_excel($hoja->getCellByColumnAndRow(2, $fila)->getCalculatedValue()),
            cengi_carga_inscripcion_valor_excel($hoja->getCellByColumnAndRow(3, $fila)->getCalculatedValue()),
            cengi_carga_inscripcion_valor_excel($hoja->getCellByColumnAndRow(4, $fila)->getCalculatedValue()),
            cengi_carga_inscripcion_valor_excel($hoja->getCellByColumnAndRow(5, $fila)->getCalculatedValue()),
            cengi_carga_inscripcion_valor_excel($hoja->getCellByColumnAndRow(6, $fila)->getCalculatedValue()),
            cengi_carga_inscripcion_valor_excel($hoja->getCellByColumnAndRow(7, $fila)->getCalculatedValue()),
        ];
    }

    return $filas;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: inscripcion.php");
    exit;
}

$db = conectar();

$cursoId = (int) ($_POST['curso_id'] ?? 0);
$tipoPago = trim((string) ($_POST['tipo_pago'] ?? ''));

if ($cursoId <= 0) {
    cengi_carga_inscripcion_error('Selecciona un curso.');
}

if (!in_array($tipoPago, ['Ingenio', 'Propio'], true)) {
    cengi_carga_inscripcion_error('Selecciona un tipo de pago válido.');
}

$stmtCurso = $db->prepare("SELECT id FROM cursos WHERE id = ?");
$stmtCurso->execute([$cursoId]);
if (!$stmtCurso->fetch()) {
    cengi_carga_inscripcion_error('El curso seleccionado no es válido.');
}

if (!isset($_FILES['archivo']) || !is_uploaded_file($_FILES['archivo']['tmp_name'])) {
    cengi_carga_inscripcion_error('No se recibió ningún archivo.');
}

if ($_FILES['archivo']['size'] > CENGI_INSCRIPCION_MASIVA_MAX_BYTES) {
    cengi_carga_inscripcion_error('El archivo es demasiado grande (máximo 5 MB).');
}

$nombreArchivo = $_FILES['archivo']['name'] ?? '';
$extension = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));

if (!in_array($extension, ['csv', 'xls'], true)) {
    cengi_carga_inscripcion_error('Solo se permiten archivos CSV o Excel (.xls).');
}

$filas = cengi_carga_inscripcion_filas($_FILES['archivo']['tmp_name'], $extension);

if (count($filas) <= 1) {
    cengi_carga_inscripcion_error('El archivo no contiene datos para importar.');
}

$filasDatos = array_slice($filas, 1, CENGI_INSCRIPCION_MASIVA_MAX_FILAS);

$sql = "
    INSERT INTO solicitudes_inscripcion (
        nombre_participante,
        cui_participante,
        puesto_participante,
        area_participante,
        grado_academico,
        correo,
        telefono,
        ingenio_id,
        curso_id,
        tipo_pago
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";

$procesados = 0;
$omitidos = 0;
$advertencias = [];

try {
    $stmt = $db->prepare($sql);
    $stmtBuscarIngenio = $db->prepare("SELECT id FROM ingenios WHERE TRIM(nombre_ingenios) = TRIM(?) LIMIT 1");
    $cacheIngenios = [];

    $db->beginTransaction();

    foreach ($filasDatos as $indice => $fila) {
        $lineaReal = $indice + 2;

        $ingenioNombre = trim((string) ($fila[0] ?? ''));
        $cui = trim((string) ($fila[1] ?? ''));
        $nombre = trim((string) ($fila[2] ?? ''));
        $puesto = trim((string) ($fila[3] ?? ''));
        $area = trim((string) ($fila[4] ?? ''));
        $correo = trim((string) ($fila[5] ?? ''));
        $gradoAcademico = trim((string) ($fila[6] ?? ''));
        $telefono = trim((string) ($fila[7] ?? ''));

        if ($ingenioNombre === '' && $cui === '' && $nombre === '' && $puesto === '' && $area === '' && $correo === '' && $gradoAcademico === '' && $telefono === '') {
            continue;
        }

        if ($cui === '' || $nombre === '' || $puesto === '' || $area === '' || $gradoAcademico === '' || $telefono === '') {
            $advertencias[] = "Línea {$lineaReal}: se omitió porque faltan datos obligatorios.";
            $omitidos++;
            continue;
        }

        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $advertencias[] = "Línea {$lineaReal}: se omitió porque el correo electrónico no es válido.";
            $omitidos++;
            continue;
        }

        if ($ingenioNombre === '') {
            $advertencias[] = "Línea {$lineaReal}: se omitió porque falta el ingenio.";
            $omitidos++;
            continue;
        }

        $claveIngenio = mb_strtolower($ingenioNombre);
        if (array_key_exists($claveIngenio, $cacheIngenios)) {
            $ingenioId = $cacheIngenios[$claveIngenio];
        } else {
            $stmtBuscarIngenio->execute([$ingenioNombre]);
            $ingenioId = $stmtBuscarIngenio->fetchColumn();
            $ingenioId = $ingenioId !== false ? (int) $ingenioId : 0;
            $cacheIngenios[$claveIngenio] = $ingenioId;
        }

        if ($ingenioId <= 0) {
            $advertencias[] = "Línea {$lineaReal}: se omitió porque el ingenio \"{$ingenioNombre}\" no coincide con ningún ingenio registrado.";
            $omitidos++;
            continue;
        }

        $stmt->execute([
            $nombre,
            $cui,
            $puesto,
            $area,
            $gradoAcademico,
            $correo,
            $telefono,
            $ingenioId,
            $cursoId,
            $tipoPago,
        ]);

        $procesados++;
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Error en carga masiva de inscripcion: " . $e->getMessage());
    cengi_carga_inscripcion_error('No fue posible procesar el archivo. Intenta más tarde.');
}
?>
<!DOCTYPE html>
<html class="light" lang="es">

<head>
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Carga masiva de inscripción | CENGICURSOS</title>
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
                    fontFamily: { body: ["Montserrat"] }
                }
            }
        }
    </script>
</head>

<body class="bg-background font-body text-gray-800 overflow-x-hidden">

<main class="max-w-3xl mx-auto px-4 md:px-10 py-16">

    <div class="bg-white border border-outline rounded-2xl shadow-lg p-6 md:p-8">

        <?php if ($procesados > 0): ?>
            <div class="flex items-center gap-3 mb-4">
                <span class="material-symbols-outlined text-secondary text-3xl">check_circle</span>
                <h2 class="text-2xl font-bold text-primary">Carga completada</h2>
            </div>
            <p class="text-gray-600 mb-4">
                Se enviaron <?php echo $procesados; ?> solicitud<?php echo $procesados === 1 ? '' : 'es'; ?> de inscripción para revisión<?php echo $omitidos > 0 ? " ({$omitidos} fila" . ($omitidos === 1 ? '' : 's') . " omitida" . ($omitidos === 1 ? '' : 's') . ")" : ''; ?>.
            </p>
        <?php else: ?>
            <div class="flex items-center gap-3 mb-4">
                <span class="material-symbols-outlined text-red-600 text-3xl">error</span>
                <h2 class="text-2xl font-bold text-primary">No se cargó ningún participante</h2>
            </div>
            <p class="text-gray-600 mb-4">Revisa el archivo e intenta nuevamente.</p>
        <?php endif; ?>

        <?php if ($advertencias): ?>
            <div class="cengi-alert is-error items-start mb-4">
                <span class="material-symbols-outlined">warning</span>
                <div>
                    <strong>Advertencias</strong>
                    <ul class="list-disc pl-5 mt-1">
                        <?php foreach ($advertencias as $advertencia): ?>
                            <li><?php echo htmlspecialchars($advertencia); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <a href="inscripcion.php" class="btn-submit inline-flex w-auto px-8">Regresar</a>

    </div>

</main>

</body>
</html>
