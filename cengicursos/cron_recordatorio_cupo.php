<?php

/**
 * Recordatorio automatico de cupo.
 *
 * Cuando un curso todavia no se ha llenado (inscritos activos < cursos.cupo) y su
 * fecha de "inicio" esta entre hoy y los proximos 7 dias (inclusive), este script
 * envia un correo de aviso al instructor del curso y a todos los participantes ya
 * inscritos activamente, avisando que el curso esta por comenzar.
 *
 * El aviso se envia una sola vez por curso: al terminar de procesar un curso (haya
 * fallado o no algun correo individual) se marca cursos.recordatorio_cupo_enviado_en
 * = NOW(), y solo se seleccionan cursos con esa columna en NULL. Asi el script puede
 * ejecutarse a diario sin reenviar el aviso para cursos que ya lo recibieron.
 *
 * No requiere revisar_permisos.php / sesion de usuario: no hay un usuario logueado
 * cuando esto lo dispara un cron. La seguridad para el modo HTTP es un token
 * compartido (ver mas abajo).
 *
 * Modos de ejecucion:
 *
 *   1) CLI (recomendado): `php cron_recordatorio_cupo.php` desde dentro de
 *      cengicursos/, o via cron del sistema/contenedor. No exige token: quien puede
 *      ejecutar PHP en el servidor ya tiene el acceso equivalente.
 *
 *   2) HTTP: por si se prefiere programar un cron-job.org o un cron de hosting
 *      compartido que solo puede pegar una URL. Exige ?token=... que debe coincidir
 *      (con hash_equals(), nunca ===) contra la variable de entorno
 *      CRON_RECORDATORIO_SECRET (leida via cengicursos_env(), ver
 *      deploy/env/cengicursos.env.example). Si no coincide, o la variable de entorno
 *      no esta configurada, responde 403 y no hace nada.
 */

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/correo.php';

$esCli = (php_sapi_name() === 'cli');

if (!$esCli) {

    $env = cengicursos_env();
    $secretoEsperado = (string) ($env['CRON_RECORDATORIO_SECRET'] ?? '');
    $tokenRecibido = (string) ($_GET['token'] ?? '');

    if ($secretoEsperado === '' || !hash_equals($secretoEsperado, $tokenRecibido)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'mensaje' => 'Acceso no autorizado.',
        ]);
        exit();
    }

    header('Content-Type: application/json; charset=utf-8');
}

/**
 * Formatea una fecha "Y-m-d" al formato legible "d/m/Y" usado en el resto del
 * modulo (ver cengicursos/inscripcion.php). Devuelve la fecha cruda si no se
 * puede parsear.
 */
function cengi_recordatorio_formatear_fecha(string $fecha): string
{
    $timestamp = strtotime($fecha);

    if ($timestamp === false) {
        return $fecha;
    }

    return date('d/m/Y', $timestamp);
}

$db = conectar();

$totalCursosProcesados = 0;
$totalCorreosEnviados = 0;
$totalCorreosFallidos = 0;

try {

    $sqlCandidatos = "
        SELECT
            c.id,
            c.nombre_cursos,
            c.inicio,
            c.cupo,
            c.instructor_id,
            i.nombre AS instructor_nombre,
            i.correo AS instructor_correo,
            (
                SELECT COUNT(*)
                FROM asignaciones a
                WHERE a.cursos_id = c.id
                AND a.estado_asignaciones = 1
            ) AS inscritos
        FROM cursos c
        LEFT JOIN instructores i
            ON i.id = c.instructor_id
        WHERE c.inicio >= CURDATE()
        AND c.inicio <= (CURDATE() + INTERVAL 7 DAY)
        AND c.cupo IS NOT NULL
        AND c.recordatorio_cupo_enviado_en IS NULL
    ";

    $stmtCandidatos = $db->prepare($sqlCandidatos);
    $stmtCandidatos->execute();

    $cursosCandidatos = $stmtCandidatos->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cursosCandidatos as $cursoFila) {

        $inscritos = (int) $cursoFila['inscritos'];
        $cupo = (int) $cursoFila['cupo'];

        // El COUNT(...) de inscritos se resuelve en SQL, pero el criterio de
        // "no lleno" (inscritos < cupo) se filtra aqui en PHP: es mas claro de
        // leer que forzar un HAVING sobre una columna calculada en un subquery
        // correlacionado, y el volumen de filas candidatas ya viene acotado por
        // el filtro de fecha de la consulta.
        if ($inscritos >= $cupo) {
            continue;
        }

        $cursoId = (int) $cursoFila['id'];
        $totalCursosProcesados++;

        $curso = [
            'nombre_cursos' => $cursoFila['nombre_cursos'],
            'inicio_formateada' => cengi_recordatorio_formatear_fecha((string) $cursoFila['inicio']),
            'cupo' => $cupo,
            'inscritos' => $inscritos,
            'instructor_nombre' => $cursoFila['instructor_nombre'],
        ];

        // ==========================================
        // CORREO AL INSTRUCTOR
        // ==========================================

        $instructorCorreo = trim((string) ($cursoFila['instructor_correo'] ?? ''));

        if ($instructorCorreo !== '') {

            try {

                require __DIR__ . '/correos/plantilla_recordatorio_cupo_instructor.php';

                $enviado = cengi_enviar_correo(
                    $instructorCorreo,
                    (string) ($cursoFila['instructor_nombre'] ?? ''),
                    'Recordatorio: su curso esta por comenzar',
                    $html
                );

                if ($enviado) {
                    $totalCorreosEnviados++;
                } else {
                    $totalCorreosFallidos++;
                    error_log(
                        "cron_recordatorio_cupo: no se pudo enviar correo al instructor " .
                        "del curso {$cursoId}"
                    );
                }

            } catch (Throwable $mailError) {
                $totalCorreosFallidos++;
                error_log(
                    "cron_recordatorio_cupo: error enviando correo al instructor " .
                    "del curso {$cursoId}: " . $mailError->getMessage()
                );
            }
        }

        // ==========================================
        // CORREO A LOS PARTICIPANTES INSCRITOS ACTIVAMENTE
        // ==========================================

        try {

            $sqlParticipantes = "
                SELECT
                    p.nombre_participantes,
                    p.correo_participantes
                FROM asignaciones a
                INNER JOIN participantes p
                    ON p.id = a.participantes_id
                WHERE a.cursos_id = ?
                AND a.estado_asignaciones = 1
                AND p.correo_participantes IS NOT NULL
                AND p.correo_participantes <> ''
            ";

            $stmtParticipantes = $db->prepare($sqlParticipantes);
            $stmtParticipantes->execute([$cursoId]);

            $participantes = $stmtParticipantes->fetchAll(PDO::FETCH_ASSOC);

            foreach ($participantes as $participante) {

                try {

                    require __DIR__ . '/correos/plantilla_recordatorio_cupo_participante.php';

                    $enviado = cengi_enviar_correo(
                        $participante['correo_participantes'],
                        (string) ($participante['nombre_participantes'] ?? ''),
                        'Recordatorio: su curso esta por comenzar',
                        $html
                    );

                    if ($enviado) {
                        $totalCorreosEnviados++;
                    } else {
                        $totalCorreosFallidos++;
                        error_log(
                            "cron_recordatorio_cupo: no se pudo enviar correo al " .
                            "participante del curso {$cursoId}"
                        );
                    }

                } catch (Throwable $mailError) {
                    $totalCorreosFallidos++;
                    error_log(
                        "cron_recordatorio_cupo: error enviando correo a un " .
                        "participante del curso {$cursoId}: " . $mailError->getMessage()
                    );
                }
            }

        } catch (Throwable $consultaError) {
            error_log(
                "cron_recordatorio_cupo: error consultando participantes del curso " .
                "{$cursoId}: " . $consultaError->getMessage()
            );
        }

        // Se marca como enviado independientemente de si algun correo individual
        // fallo, para cumplir la regla de "una sola vez por curso" y no quedar
        // reintentando indefinidamente por un correo roto de un participante.
        $stmtMarcar = $db->prepare("
            UPDATE cursos
            SET recordatorio_cupo_enviado_en = NOW()
            WHERE id = ?
        ");
        $stmtMarcar->execute([$cursoId]);
    }

} catch (Throwable $e) {

    error_log('cron_recordatorio_cupo: error general: ' . $e->getMessage());

    if (!$esCli) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'mensaje' => 'Ocurrio un error al procesar los recordatorios.',
        ]);
        exit();
    }

    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$resumen = [
    'ok' => true,
    'cursos_procesados' => $totalCursosProcesados,
    'correos_enviados' => $totalCorreosEnviados,
    'correos_fallidos' => $totalCorreosFallidos,
];

if ($esCli) {
    echo "Recordatorio de cupo finalizado." . PHP_EOL;
    echo "Cursos procesados: {$totalCursosProcesados}" . PHP_EOL;
    echo "Correos enviados: {$totalCorreosEnviados}" . PHP_EOL;
    echo "Correos fallidos: {$totalCorreosFallidos}" . PHP_EOL;
} else {
    echo json_encode($resumen);
}
