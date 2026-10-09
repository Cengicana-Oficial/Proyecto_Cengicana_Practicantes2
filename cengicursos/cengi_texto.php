<?php
/**
 * Funciones sin dependencias de sesion ni de permisos, compartidas por revisar_permisos.php
 * (paginas con login) y por las paginas publicas y el cron (inscripcion.php,
 * guardar_inscripcion.php, cron_comunicaciones.php via planificacion_helpers.php), que no
 * pueden incluir revisar_permisos.php porque este redirige al login.
 */

// Nota final minima para aprobar un curso (post-evaluacion / promedio de modulos).
// Unico lugar donde se define: lo usan Seguimiento por curso, Participantes por curso,
// Certificacion, constancia de notas, Pagos de inscripcion e Informe de gestion.
const CENGI_NOTA_APROBACION = 70;

function cengi_texto_normalizado($valor)
{
    $valor = trim((string) $valor);
    $valor = mb_strtolower($valor, 'UTF-8');
    $valor = strtr($valor, [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ñ' => 'n',
        'Á' => 'a',
        'É' => 'e',
        'Í' => 'i',
        'Ó' => 'o',
        'Ú' => 'u',
        'Ñ' => 'n',
    ]);

    return preg_replace('/\s+/', '', $valor);
}

function cengi_usuario_actual_id()
{
    return isset($_SESSION['id_usuario']) ? (int) $_SESSION['id_usuario'] : 0;
}
