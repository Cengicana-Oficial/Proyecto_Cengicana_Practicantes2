<?php
require_once "revisar_permisos.php";
cengi_require_admin('ver_cursos.php');

require_once "conexion.php";

$db = conectar();
$cursoId = (int) ($_POST['id'] ?? 0);
$categoriaId = (int) ($_POST['categorias_cursos'] ?? $_POST['categorias'] ?? 0);
$ingenioId = (int) ($_POST['ingenio'] ?? 0);
$tipo = trim((string) ($_POST['tipo'] ?? ''));
$nombre = trim((string) ($_POST['nombre_cursos'] ?? ''));
$jornada = trim((string) ($_POST['jornada_cursos'] ?? ''));
$dias = trim((string) ($_POST['dias'] ?? ''));
$horario = trim((string) ($_POST['horario'] ?? ''));
$inicio = trim((string) ($_POST['inicio'] ?? ''));
$fin = trim((string) ($_POST['fin'] ?? ''));

$datosValidos = $cursoId > 0
    && $categoriaId > 0
    && $ingenioId > 0
    && $tipo !== ''
    && $nombre !== ''
    && $jornada !== ''
    && $dias !== ''
    && $horario !== ''
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio)
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin)
    && $fin >= $inicio;

if (!$datosValidos) {
    header('Location: ver_cursos.php?error=datos');
    exit;
}

try {
    $stmt = $db->prepare("
        UPDATE cursos
        SET
            categoria_curso_id = ?,
            ingenio_id = ?,
            tipo = ?,
            nombre_cursos = ?,
            jornada_cursos = ?,
            dias = ?,
            horario = ?,
            inicio = ?,
            fin = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $categoriaId,
        $ingenioId,
        $tipo,
        $nombre,
        $jornada,
        $dias,
        $horario,
        $inicio,
        $fin,
        $cursoId,
    ]);
} catch (PDOException $e) {
    error_log('No fue posible actualizar el curso: ' . $e->getMessage());
    header('Location: ver_cursos.php?error=actualizar');
    exit;
}

header('Location: ver_cursos.php?mensaje=actualizado');
exit;
