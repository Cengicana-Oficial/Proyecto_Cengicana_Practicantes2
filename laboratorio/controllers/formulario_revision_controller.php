<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../models/formulario_revision_model.php';

lab_require_module_access();

if (!lab_can_view_error_forms() && !lab_can_any([
    'laboratorio.consolidacion.aprobar',
    'laboratorio.formularios.guardar_corregidos',
    'laboratorio.formularios.guardar_errores',
])) {
    lab_forbidden('No tiene permisos para ver esta revision.');
}

$idRango = filter_input(INPUT_GET, 'id_rango', FILTER_VALIDATE_INT);
if (!$idRango) {
    http_response_code(400);
    echo 'Rango no valido.';
    exit;
}

$mensajeRevision = null;
$errorRevision = null;
$usuarioActual = lab_current_user();
$usuarioRevision = (string) ($usuarioActual['nombre'] ?? $usuarioActual['correo'] ?? 'Usuario');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');

    $permisosAccion = [
        'guardar' => 'laboratorio.formularios.guardar_corregidos',
        'marcar_error' => 'laboratorio.formularios.guardar_errores',
        'aprobar' => 'laboratorio.consolidacion.aprobar',
    ];

    if (!isset($permisosAccion[$accion]) || !lab_can($permisosAccion[$accion])) {
        lab_forbidden('No tiene permisos para completar esta accion.');
    }

    $comentarioRaw = $_POST['comentario_revision'] ?? '';
    if (is_array($comentarioRaw)) {
        $comentarios = [];
        foreach ($comentarioRaw as $idComentario => $textoComentario) {
            $textoComentario = trim((string) $textoComentario);
            if ($textoComentario !== '') {
                $comentarios[] = 'Formulario #' . (int) $idComentario . ': ' . $textoComentario;
            }
        }
        $comentario = implode("\n", $comentarios);
    } else {
        $comentario = trim((string) $comentarioRaw);
    }

    try {
        if ($accion === 'guardar') {
            guardarRevisionFormularios(
                $_POST['formulario'] ?? [],
                $_POST['datos'] ?? [],
                $usuarioRevision,
                $comentario
            );
            $mensajeRevision = 'Cambios guardados, original con errores registrado y version corregida registrada.';
        } elseif ($accion === 'marcar_error') {
            marcarFormulariosRangoConErrores((int) $idRango, $usuarioRevision, $comentario);
            $mensajeRevision = 'Formulario guardado como original con errores.';
        } elseif ($accion === 'aprobar') {
            aprobarFormulariosRango((int) $idRango, $usuarioRevision, $comentario);
            $mensajeRevision = 'Formulario aprobado correctamente.';
        }
    } catch (Throwable $e) {
        $errorRevision = 'No se pudo completar la accion: ' . $e->getMessage();
    }
}

$resumenRango = obtenerResumenRevisionRango((int) $idRango);
if (!$resumenRango) {
    http_response_code(404);
    echo 'No se encontro el rango solicitado.';
    exit;
}

$formulariosRevision = labFormularioRevisionAgruparRango(obtenerFormulariosRevisionRango((int) $idRango));
$puedeAprobarRevision = lab_can('laboratorio.consolidacion.aprobar');
$puedeGuardarErrores = lab_can('laboratorio.formularios.guardar_errores');

require_once __DIR__ . '/../view/formulario_revision_view.php';

?>
