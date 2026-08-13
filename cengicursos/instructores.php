<?php
require_once "conexion.php";
require_once "menu.php";
require_once "curso_form_helpers.php";

cengi_require_ver_instructores();

$db = conectar();
$puedeGestionar = cengi_puede_gestionar_instructores();
$mensaje = '';
$mensajeTipo = 'success';

// Scoping por ingenio para el rol "Administrador de ingenio": ve unicamente
// instructores/cursos/modulos con al menos un participante de su propio
// ingenio (via asignaciones -> participantes), y no tiene acceso a encuestas
// de satisfaccion ni a los informes PDF de instructor. Admin/gestor/formador
// no se ven afectados por estas banderas (siguen viendo todo).
$esAdministradorIngenio = cengi_es_administrador_ingenio();
$ingenioIdActualInstructores = cengi_ingenio_id_actual();
$puedeVerEncuestasInstructor = !$esAdministradorIngenio;
$puedeVerReportesInstructor = cengi_puede_generar_informe_instructor();
// El "Administrador de ingenio" tampoco debe ver el CV del instructor (dato
// personal del instructor, no del curso/participante de su ingenio) ni poder
// generar/copiar el link de evaluacion (eso es potestad de quien gestiona el
// envio de encuestas a nivel central). Son banderas independientes de
// $puedeVerEncuestasInstructor porque conceptualmente cubren cosas distintas.
$puedeVerCvInstructor = !$esAdministradorIngenio;
$puedeVerLinkEvaluacion = !$esAdministradorIngenio;

// Nota: el fragmento SQL "curso con participante del ingenio X" que antes
// vivia aqui como funcion local (cengi_inst_frag_curso_participante_ingenio)
// se movio a conexion.php como cengi_frag_curso_participante_ingenio(), para
// poder reutilizarlo tambien en los 3 reportes PDF de instructor
// (informe_instructor_pdf.php, informe_instructor_curso_pdf.php,
// informe_instructor_modulo_pdf.php) sin duplicar la logica SQL.

/**
 * Traduce los codigos de error de subida de PHP (UPLOAD_ERR_*) a un mensaje
 * legible para el usuario. Antes de este cambio, cualquier error distinto de
 * UPLOAD_ERR_OK (por ejemplo, un CV que supera upload_max_filesize/post_max_size)
 * se ignoraba en silencio: el instructor se guardaba sin CV y el usuario nunca
 * se enteraba de que el archivo no se guardo.
 */
function cengi_mensaje_error_subida_cv(int $codigoError): string
{
    switch ($codigoError) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'El archivo supera el tamaño máximo permitido para subir CVs.';
        case UPLOAD_ERR_PARTIAL:
            return 'El archivo se subió solo parcialmente. Intenta nuevamente.';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            return 'El servidor no pudo procesar el archivo. Contacta al administrador.';
        default:
            return 'No fue posible subir el archivo del CV.';
    }
}

$mensajesCarga = [
    'evaluaciones' => 'La carga masiva de evaluaciones de instructor finalizó correctamente.',
];
$mensajeCarga = trim((string) ($_GET['mensaje'] ?? ''));
$errorCarga = trim((string) ($_GET['error'] ?? ''));
if ($mensajeCarga !== '' && isset($mensajesCarga[$mensajeCarga])) {
    $mensaje = $mensajesCarga[$mensajeCarga];
    $mensajeTipo = 'success';
} elseif ($errorCarga !== '') {
    if (!empty($_SESSION['cengi_error_carga_evaluaciones'])) {
        $mensaje = (string) $_SESSION['cengi_error_carga_evaluaciones'];
        unset($_SESSION['cengi_error_carga_evaluaciones']);
    } else {
        $mensaje = 'No fue posible completar la carga de evaluaciones. Verifica el archivo e inténtalo nuevamente.';
    }
    $mensajeTipo = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeGestionar) {
    $accion = trim((string) ($_POST['accion'] ?? ''));
    $nombre = trim((string) ($_POST['nombre'] ?? ''));
    $especialidad = trim((string) ($_POST['especialidad'] ?? ''));
    $correo = trim((string) ($_POST['correo'] ?? ''));
    $telefono = trim((string) ($_POST['telefono'] ?? ''));

    $cvPath = null;
    $cvError = null;
    if (isset($_FILES['cv']) && $_FILES['cv']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['cv']['error'] !== UPLOAD_ERR_OK) {
            $cvError = cengi_mensaje_error_subida_cv($_FILES['cv']['error']);
        } else {
            $extension = strtolower(pathinfo($_FILES['cv']['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, ['pdf', 'doc', 'docx'], true)) {
                $cvError = 'El CV debe ser un archivo PDF, DOC o DOCX.';
            } else {
                $nombreArchivo = time() . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $_FILES['cv']['name']);
                $directorioDestino = '../uploads/instructores';
                $ruta = $directorioDestino . '/' . $nombreArchivo;

                // En despliegues Docker, "uploads/" suele ser un bind mount cuyo
                // dueño/permisos vienen del host, no de la imagen: el chown hecho
                // en el Dockerfile en tiempo de build no aplica a ese volumen en
                // runtime. Verificamos aquí para poder dar un mensaje concreto en
                // vez del genérico "no fue posible" cuando move_uploaded_file falla.
                if (!is_dir($directorioDestino)) {
                    $creado = @mkdir($directorioDestino, 0775, true);
                    if (!$creado && !is_dir($directorioDestino)) {
                        $cvError = 'El directorio de subida no existe y no pudo crearse en el servidor.';
                        error_log(sprintf(
                            'cengicursos/instructores.php: no se pudo crear el directorio de subida "%s" (revisar permisos del volumen montado en el host).',
                            realpath('..') . '/uploads/instructores'
                        ));
                    }
                }

                if ($cvError === null && !is_writable($directorioDestino)) {
                    $cvError = 'El directorio de subida no tiene permisos de escritura en el servidor.';
                    error_log(sprintf(
                        'cengicursos/instructores.php: el directorio de subida "%s" no es escribible por el usuario del servidor web (revisar chown/chmod del volumen montado, p. ej. "uploads/" en docker-compose.prod.yml).',
                        realpath($directorioDestino) ?: $directorioDestino
                    ));
                }

                if ($cvError === null) {
                    if (move_uploaded_file($_FILES['cv']['tmp_name'], $ruta)) {
                        $cvPath = $ruta;
                    } else {
                        $cvError = 'No fue posible guardar el archivo del CV en el servidor.';
                        $ultimoError = error_get_last();
                        error_log(sprintf(
                            'cengicursos/instructores.php: move_uploaded_file("%s", "%s") fallo. Ultimo error PHP: %s',
                            $_FILES['cv']['tmp_name'],
                            $ruta,
                            $ultimoError['message'] ?? 'desconocido'
                        ));
                    }
                }
            }
        }
    }

    try {
        if ($accion === 'crear' && $nombre !== '') {
            $stmt = $db->prepare("
                INSERT INTO instructores (nombre, especialidad, correo, telefono, cv_path, estado)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$nombre, $especialidad, $correo, $telefono, $cvPath]);
            $mensaje = 'Instructor registrado correctamente.';
            if ($cvError !== null) {
                $mensaje .= ' El CV no se guardó: ' . $cvError;
                $mensajeTipo = 'error';
            }
        } elseif ($accion === 'actualizar') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0 && $nombre !== '') {
                if ($cvPath !== null) {
                    $stmt = $db->prepare("
                        UPDATE instructores SET nombre = ?, especialidad = ?, correo = ?, telefono = ?, cv_path = ? WHERE id = ?
                    ");
                    $stmt->execute([$nombre, $especialidad, $correo, $telefono, $cvPath, $id]);
                } else {
                    $stmt = $db->prepare("
                        UPDATE instructores SET nombre = ?, especialidad = ?, correo = ?, telefono = ? WHERE id = ?
                    ");
                    $stmt->execute([$nombre, $especialidad, $correo, $telefono, $id]);
                }
                $mensaje = 'Instructor actualizado correctamente.';
                if ($cvError !== null) {
                    $mensaje .= ' El CV no se guardó: ' . $cvError;
                    $mensajeTipo = 'error';
                }
            }
        } elseif ($accion === 'toggle_estado') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE instructores SET estado = 1 - estado WHERE id = ?");
                $stmt->execute([$id]);
                $mensaje = 'Estado actualizado.';
            }
        }
    } catch (PDOException $e) {
        $mensaje = 'No fue posible guardar: ' . $e->getMessage();
        $mensajeTipo = 'error';
    }
}

// Scoping por ingenio (solo aplica al rol "Administrador de ingenio"): un
// instructor solo debe listarse -- y sus contadores solo deben reflejar los
// cursos -- si al menos uno de sus cursos (como instructor principal o como
// co-instructor de modulo) tiene un participante inscrito del ingenio del
// usuario actual. Admin/gestor/formador no llevan estas condiciones (quedan
// como cadena vacia) y ven todo, igual que antes.
$paramsInstructores = [];
$filtroTotalCursosIngenio = '';
$filtroEvaluacionPromedioIngenio = '';
$whereInstructoresIngenio = '';
if ($esAdministradorIngenio) {
    if ($ingenioIdActualInstructores > 0) {
        $filtroTotalCursosIngenio = ' AND ' . cengi_frag_curso_participante_ingenio('c', 'tc', $paramsInstructores, $ingenioIdActualInstructores);
        $filtroEvaluacionPromedioIngenio = ' AND ' . cengi_frag_curso_participante_ingenio('c', 'ep', $paramsInstructores, $ingenioIdActualInstructores);
        $fragInstructorPrincipalVisible = "EXISTS (
            SELECT 1 FROM cursos c_vis
            WHERE c_vis.instructor_id = i.id
            AND " . cengi_frag_curso_participante_ingenio('c_vis', 'vp', $paramsInstructores, $ingenioIdActualInstructores) . "
        )";
        $fragInstructorCoModuloVisible = "EXISTS (
            SELECT 1 FROM curso_modulo_instructores cmi_vis
            INNER JOIN curso_modulos cm_vis ON cm_vis.id = cmi_vis.curso_modulo_id
            INNER JOIN cursos c_vis2 ON c_vis2.id = cm_vis.curso_id
            WHERE cmi_vis.instructor_id = i.id
            AND " . cengi_frag_curso_participante_ingenio('c_vis2', 'vc', $paramsInstructores, $ingenioIdActualInstructores) . "
        )";
        $whereInstructoresIngenio = "WHERE ({$fragInstructorPrincipalVisible} OR {$fragInstructorCoModuloVisible})";
    } else {
        $whereInstructoresIngenio = 'WHERE 1 = 0';
    }
}

$stmt = $db->prepare("
    SELECT
        i.*,
        (SELECT COUNT(*) FROM cursos c WHERE c.instructor_id = i.id{$filtroTotalCursosIngenio}) AS total_cursos,
        (
            SELECT AVG(CAST(cc.posevaluacion AS DECIMAL(6,2)))
            FROM cursos c
            INNER JOIN asignaciones a ON a.cursos_id = c.id
            INNER JOIN control_cursos cc ON cc.asignacion_id = a.id
            WHERE c.instructor_id = i.id AND cc.posevaluacion REGEXP '^[0-9]+(\\.[0-9]+)?$'{$filtroEvaluacionPromedioIngenio}
        ) AS evaluacion_promedio
    FROM instructores i
    {$whereInstructoresIngenio}
    ORDER BY total_cursos DESC, i.nombre
");
$stmt->execute($paramsInstructores);
$instructores = $stmt->fetchAll(PDO::FETCH_ASSOC);

// El rol "Administrador de ingenio" no tiene acceso a las encuestas de
// satisfaccion del instructor (promedios ni comentarios): se dejan los
// arreglos vacios sin siquiera consultar la BD, para que
// encuesta_satisfaccion salga en cero/null para cada instructor (ver uso mas
// abajo) y no se filtren datos agregados de otros ingenios.
$statsEncuestaPorInstructor = [];
$comentariosEncuestaPorInstructor = [];
if ($puedeVerEncuestasInstructor) {
    $statsEncuestaStmt = $db->query("
        SELECT
            eei.instructor_id,
            COUNT(*) AS total,
            AVG(ei.instructor_lenguaje_claro) AS avg_lenguaje_claro,
            AVG(ei.instructor_material_adecuado) AS avg_material_adecuado,
            AVG(ei.instructor_conocimiento_tema) AS avg_conocimiento_tema,
            AVG(ei.instructor_respeto_participantes) AS avg_respeto_participantes,
            AVG(ei.instructor_puntualidad_objetivos) AS avg_puntualidad_objetivos,
            AVG(ei.recomendaria_instructor) AS avg_recomendaria_instructor,
            AVG(ei.tema_relevancia_utilidad) AS avg_tema_relevancia_utilidad,
            AVG(ei.logistica_evento) AS avg_logistica_evento,
            AVG(ei.recomendaria_contexto) AS avg_recomendaria_contexto
        FROM evaluaciones_instructor ei
        INNER JOIN enlaces_evaluacion_instructor eei ON eei.id = ei.enlace_id
        GROUP BY eei.instructor_id
    ");
    foreach ($statsEncuestaStmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $statsEncuestaPorInstructor[(int) $fila['instructor_id']] = $fila;
    }

    $comentariosEncuestaStmt = $db->query("
        SELECT instructor_id, areas_mejora, capacitaciones_necesarias, creado
        FROM (
            SELECT
                eei.instructor_id AS instructor_id,
                ei.areas_mejora AS areas_mejora,
                ei.capacitaciones_necesarias AS capacitaciones_necesarias,
                ei.creado AS creado,
                ROW_NUMBER() OVER (PARTITION BY eei.instructor_id ORDER BY ei.creado DESC, ei.id DESC) AS rn
            FROM evaluaciones_instructor ei
            INNER JOIN enlaces_evaluacion_instructor eei ON eei.id = ei.enlace_id
            WHERE
                (ei.areas_mejora IS NOT NULL AND TRIM(ei.areas_mejora) <> '')
                OR (ei.capacitaciones_necesarias IS NOT NULL AND TRIM(ei.capacitaciones_necesarias) <> '')
        ) comentarios_recientes
        WHERE rn <= 5
        ORDER BY instructor_id, creado DESC
    ");
    foreach ($comentariosEncuestaStmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $idInstructor = (int) $fila['instructor_id'];
        if (!isset($comentariosEncuestaPorInstructor[$idInstructor])) {
            $comentariosEncuestaPorInstructor[$idInstructor] = [];
        }
        $comentariosEncuestaPorInstructor[$idInstructor][] = [
            'areas_mejora' => $fila['areas_mejora'],
            'capacitaciones_necesarias' => $fila['capacitaciones_necesarias'],
            'creado' => $fila['creado'],
        ];
    }
}

// Modulos asignados por instructor: un modulo (curso_modulos) puede tener 0, 1 o
// varios instructores propios via curso_modulo_instructores (co-ensenanza). Si un
// modulo no tiene ninguna fila propia, hereda implicitamente al instructor
// principal del curso (cursos.instructor_id) -- mismo criterio de fallback que ya
// se usa en cengicursos/ver_cursos.php al listar los modulos de un curso.
// Scoping por ingenio: para el Administrador de ingenio solo se incluyen los
// modulos de cursos que tengan al menos un participante de su ingenio (no
// hace falta filtrar por instructor aqui, basta con que el CURSO califique);
// $modulosAsignadosPorInstructor y $edicionesModulo (mas abajo) heredan el
// filtro en cascada a partir de este resultado.
$paramsModulosCurso = [];
$whereModulosCursoIngenio = '';
if ($esAdministradorIngenio) {
    $whereModulosCursoIngenio = $ingenioIdActualInstructores > 0
        ? 'WHERE ' . cengi_frag_curso_participante_ingenio('c', 'mc', $paramsModulosCurso, $ingenioIdActualInstructores)
        : 'WHERE 1 = 0';
}

$modulosCursoStmt = $db->prepare("
    SELECT
        cm.id AS modulo_id,
        cm.nombre AS modulo_nombre,
        cm.horas AS modulo_horas,
        cm.orden AS modulo_orden,
        c.id AS curso_id,
        c.nombre_cursos,
        c.instructor_id AS curso_instructor_id,
        c.inicio,
        c.fin,
        ing.nombre_ingenios AS ingenio,
        cmi.instructor_id AS modulo_instructor_id
    FROM curso_modulos cm
    INNER JOIN cursos c ON c.id = cm.curso_id
    INNER JOIN ingenios ing ON ing.id = c.ingenio_id
    LEFT JOIN curso_modulo_instructores cmi ON cmi.curso_modulo_id = cm.id
    LEFT JOIN instructores insm ON insm.id = cmi.instructor_id
    {$whereModulosCursoIngenio}
    ORDER BY c.nombre_cursos, c.inicio DESC, cm.orden, cm.id
");
$modulosCursoStmt->execute($paramsModulosCurso);

$modulosPorModuloId = [];
foreach ($modulosCursoStmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $moduloId = (int) $fila['modulo_id'];
    if (!isset($modulosPorModuloId[$moduloId])) {
        $modulosPorModuloId[$moduloId] = [
            'curso_id' => (int) $fila['curso_id'],
            'nombre_cursos' => $fila['nombre_cursos'],
            'curso_instructor_id' => $fila['curso_instructor_id'] !== null ? (int) $fila['curso_instructor_id'] : null,
            'inicio' => $fila['inicio'],
            'fin' => $fila['fin'],
            'ingenio' => $fila['ingenio'],
            'modulo_id' => $moduloId,
            'modulo_nombre' => $fila['modulo_nombre'],
            'modulo_horas' => $fila['modulo_horas'],
            'modulo_orden' => $fila['modulo_orden'],
            'instructores_propios' => [],
        ];
    }
    if ($fila['modulo_instructor_id'] !== null) {
        $modulosPorModuloId[$moduloId]['instructores_propios'][(int) $fila['modulo_instructor_id']] = true;
    }
}

// Backfill: los cursos guardados antes de que existiera el enlace de evaluacion
// por modulo todavia no tienen fila en enlaces_evaluacion_instructor para los
// modulos con asignacion EXPLICITA de instructor(es) propios. Se asegura aqui,
// de forma idempotente (no crea duplicados si ya existe).
foreach ($modulosPorModuloId as $modulo) {
    if (empty($modulo['instructores_propios'])) {
        continue;
    }
    foreach (array_keys($modulo['instructores_propios']) as $instructorIdModulo) {
        cengi_asegurar_enlace_evaluacion_instructor($db, $modulo['curso_id'], $instructorIdModulo, $modulo['modulo_id']);
    }
}

$enlacesPorClave = [];
$enlacesStmt = $db->query('SELECT curso_id, instructor_id, curso_modulo_id, token FROM enlaces_evaluacion_instructor');
foreach ($enlacesStmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $clave = $fila['curso_id'] . '-' . $fila['instructor_id'] . '-' . ((int) ($fila['curso_modulo_id'] ?? 0));
    $enlacesPorClave[$clave] = $fila['token'];
}

$modulosAsignadosPorInstructor = [];
foreach ($modulosPorModuloId as $modulo) {
    $base = [
        'curso_id' => $modulo['curso_id'],
        'nombre_cursos' => $modulo['nombre_cursos'],
        'ingenio' => $modulo['ingenio'],
        'inicio' => $modulo['inicio'],
        'fin' => $modulo['fin'],
        'modulo_id' => $modulo['modulo_id'],
        'modulo_nombre' => $modulo['modulo_nombre'],
        'modulo_horas' => $modulo['modulo_horas'],
        'modulo_orden' => $modulo['modulo_orden'],
    ];
    if (empty($modulo['instructores_propios'])) {
        // Sin instructores propios: el modulo hereda al instructor principal del curso.
        if ($modulo['curso_instructor_id'] !== null) {
            $instructorIdDestino = $modulo['curso_instructor_id'];
            $modulosAsignadosPorInstructor[$instructorIdDestino][] = $base + [
                'rol' => 'principal',
                'explicito' => false,
                'evaluacion_token' => $enlacesPorClave["{$modulo['curso_id']}-{$instructorIdDestino}-0"] ?? null,
            ];
        }
        continue;
    }
    foreach (array_keys($modulo['instructores_propios']) as $instructorIdModulo) {
        $rol = ($instructorIdModulo === $modulo['curso_instructor_id']) ? 'principal' : 'co-instructor';
        $modulosAsignadosPorInstructor[$instructorIdModulo][] = $base + [
            'rol' => $rol,
            'explicito' => true,
            'evaluacion_token' => $enlacesPorClave["{$modulo['curso_id']}-{$instructorIdModulo}-{$modulo['modulo_id']}"] ?? null,
        ];
    }
}

// cv_path se guarda como ruta relativa (p. ej. "../uploads/instructores/xxx.pdf")
// relativa al directorio de este script; is_file() la resuelve igual que
// move_uploaded_file() la escribió, sin depender de la URL del navegador.
// Registros con cv_path apuntando a un archivo que ya no existe en disco
// (por ejemplo, tras un rebuild del contenedor sin volumen persistente para
// uploads/) deben tratarse como "sin CV" en vez de mostrar un enlace roto.
$instructorTieneCvDisponible = [];
foreach ($instructores as &$instructorFila) {
    $idInstructor = (int) $instructorFila['id'];
    $stats = $statsEncuestaPorInstructor[$idInstructor] ?? null;
    $promedio = static function ($valor) {
        return $valor !== null ? (float) $valor : null;
    };
    $cvDisponible = !empty($instructorFila['cv_path']) && is_file($instructorFila['cv_path']);
    $instructorFila['cv_disponible'] = $cvDisponible;
    $instructorTieneCvDisponible[$idInstructor] = $cvDisponible;
    $instructorFila['encuesta_satisfaccion'] = [
        'total' => $stats ? (int) $stats['total'] : 0,
        'instructor' => [
            'lenguaje_claro' => $stats ? $promedio($stats['avg_lenguaje_claro']) : null,
            'material_adecuado' => $stats ? $promedio($stats['avg_material_adecuado']) : null,
            'conocimiento_tema' => $stats ? $promedio($stats['avg_conocimiento_tema']) : null,
            'respeto_participantes' => $stats ? $promedio($stats['avg_respeto_participantes']) : null,
            'puntualidad_objetivos' => $stats ? $promedio($stats['avg_puntualidad_objetivos']) : null,
        ],
        'recomendaria_instructor' => $stats ? $promedio($stats['avg_recomendaria_instructor']) : null,
        'tema' => [
            'relevancia_utilidad' => $stats ? $promedio($stats['avg_tema_relevancia_utilidad']) : null,
            'logistica_evento' => $stats ? $promedio($stats['avg_logistica_evento']) : null,
        ],
        'recomendaria_contexto' => $stats ? $promedio($stats['avg_recomendaria_contexto']) : null,
        'comentarios' => $comentariosEncuestaPorInstructor[$idInstructor] ?? [],
    ];
    $instructorFila['modulos_asignados'] = $modulosAsignadosPorInstructor[$idInstructor] ?? [];
}
unset($instructorFila);

// Defensa en profundidad: el "Administrador de ingenio" no debe poder ver el
// CV del instructor ni el token del link de evaluacion ni con "ver codigo
// fuente" del HTML, asi que se limpian del arreglo ANTES del json_encode que
// lo embebe en la pagina (ver mas abajo), en vez de confiar solo en que el JS
// no los renderice.
if ($esAdministradorIngenio) {
    foreach ($instructores as &$instructorFila) {
        unset($instructorFila['cv_path'], $instructorFila['cv_disponible']);
        if (!empty($instructorFila['modulos_asignados'])) {
            foreach ($instructorFila['modulos_asignados'] as &$moduloAsignado) {
                $moduloAsignado['evaluacion_token'] = null;
            }
            unset($moduloAsignado);
        }
    }
    unset($instructorFila);
}

// Fila base por curso, con el instructor PRINCIPAL del curso (c.instructor_id) -- este
// es el comportamiento original, sin cambios, salvo el WHERE de scoping por
// ingenio agregado abajo (mismo filtro que $modulosCursoStmt, sobre c.id).
$paramsEdicionesPrincipales = [];
$whereEdicionesPrincipalesIngenio = '';
if ($esAdministradorIngenio) {
    $whereEdicionesPrincipalesIngenio = $ingenioIdActualInstructores > 0
        ? 'WHERE ' . cengi_frag_curso_participante_ingenio('c', 'ep2', $paramsEdicionesPrincipales, $ingenioIdActualInstructores)
        : 'WHERE 1 = 0';
}

$edicionesPrincipalesStmt = $db->prepare("
    SELECT
        ca.descripcion_categorias_cursos AS categoria,
        c.id AS curso_id,
        c.nombre_cursos,
        c.tipo AS modalidad,
        c.cupo,
        c.inicio,
        c.fin,
        YEAR(COALESCE(c.inicio, c.creado)) AS anio,
        ing.nombre_ingenios AS ingenio,
        i.id AS instructor_id,
        i.nombre AS instructor_nombre,
        i.especialidad AS instructor_especialidad,
        i.cv_path AS instructor_cv,
        eei.token AS evaluacion_token,
        (SELECT COUNT(*) FROM asignaciones a WHERE a.cursos_id = c.id) AS total_inscritos,
        (
            SELECT AVG(CAST(cc.posevaluacion AS DECIMAL(6,2)))
            FROM asignaciones a
            INNER JOIN control_cursos cc ON cc.asignacion_id = a.id
            WHERE a.cursos_id = c.id AND cc.posevaluacion REGEXP '^[0-9]+(\\.[0-9]+)?$'
        ) AS evaluacion_promedio
    FROM cursos c
    INNER JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
    INNER JOIN ingenios ing ON ing.id = c.ingenio_id
    LEFT JOIN instructores i ON i.id = c.instructor_id
    LEFT JOIN enlaces_evaluacion_instructor eei ON eei.curso_id = c.id AND eei.instructor_id = i.id AND eei.curso_modulo_id IS NULL
    {$whereEdicionesPrincipalesIngenio}
    ORDER BY c.nombre_cursos, c.inicio DESC
");
$edicionesPrincipalesStmt->execute($paramsEdicionesPrincipales);
$edicionesPrincipales = $edicionesPrincipalesStmt->fetchAll(PDO::FETCH_ASSOC);

// Filas adicionales: un instructor que solo dio uno (o varios) de los modulos de un
// curso, sin ser el instructor principal (cursos.instructor_id), tambien debe poder
// aparecer al filtrar por ese curso/diplomado en el JS (instructorEditions() /
// filteredEditions() filtran "ediciones" por instructor_id + nombre_cursos/anio, ver
// mas abajo). Se reutiliza $modulosPorModuloId (ya calculado arriba para
// $modulosAsignadosPorInstructor) para no repetir la consulta de
// curso_modulo_instructores, y se deduplica por curso+instructor con un array
// asociativo (un mismo instructor puede tener modulos propios en varios modulos del
// mismo curso).
$paresCursoInstructorModulo = [];
foreach ($modulosPorModuloId as $modulo) {
    if (empty($modulo['instructores_propios'])) {
        continue;
    }
    foreach (array_keys($modulo['instructores_propios']) as $instructorIdModulo) {
        if ($instructorIdModulo === $modulo['curso_instructor_id']) {
            // Ya cubierto por la fila principal del curso.
            continue;
        }
        $paresCursoInstructorModulo["{$modulo['curso_id']}-{$instructorIdModulo}"] = [
            'curso_id' => $modulo['curso_id'],
            'instructor_id' => $instructorIdModulo,
        ];
    }
}

$cursoBasePorId = [];
foreach ($edicionesPrincipales as $filaBase) {
    $cursoBasePorId[(int) $filaBase['curso_id']] = $filaBase;
}

$instructoresPorId = [];
foreach ($instructores as $instructorRegistro) {
    $instructoresPorId[(int) $instructorRegistro['id']] = $instructorRegistro;
}

$edicionesModulo = [];
foreach ($paresCursoInstructorModulo as $par) {
    $cursoId = $par['curso_id'];
    $instructorId = $par['instructor_id'];
    if (!isset($cursoBasePorId[$cursoId])) {
        continue;
    }
    $baseCurso = $cursoBasePorId[$cursoId];
    $instructorInfo = $instructoresPorId[$instructorId] ?? null;
    // El enlace de evaluacion de CURSO COMPLETO para un instructor de modulo tambien se
    // crea (ver cengi_curso_asegurar_enlaces_evaluacion()/cengi_asegurar_enlace_evaluacion_instructor()
    // en curso_form_helpers.php), asi que se busca con la misma clave "curso-instructor-0"
    // (curso_modulo_id NULL) que usa $enlacesPorClave para la fila principal.
    $edicionesModulo[] = [
        'categoria' => $baseCurso['categoria'],
        'curso_id' => $cursoId,
        'nombre_cursos' => $baseCurso['nombre_cursos'],
        'modalidad' => $baseCurso['modalidad'],
        'cupo' => $baseCurso['cupo'],
        'inicio' => $baseCurso['inicio'],
        'fin' => $baseCurso['fin'],
        'anio' => $baseCurso['anio'],
        'ingenio' => $baseCurso['ingenio'],
        'instructor_id' => $instructorId,
        'instructor_nombre' => $instructorInfo['nombre'] ?? null,
        'instructor_especialidad' => $instructorInfo['especialidad'] ?? null,
        'instructor_cv' => $instructorInfo['cv_path'] ?? null,
        'evaluacion_token' => $enlacesPorClave["{$cursoId}-{$instructorId}-0"] ?? null,
        'total_inscritos' => $baseCurso['total_inscritos'],
        'evaluacion_promedio' => $baseCurso['evaluacion_promedio'],
    ];
}

$ediciones = array_merge($edicionesPrincipales, $edicionesModulo);

$anios = [];
$nombresCursos = [];
foreach ($ediciones as &$edicionFila) {
    if (!empty($edicionFila['anio'])) {
        $anios[(string) $edicionFila['anio']] = true;
    }
    $nombresCursos[(string) $edicionFila['nombre_cursos']] = true;
    $edicionFila['instructor_cv_disponible'] = $instructorTieneCvDisponible[(int) $edicionFila['instructor_id']] ?? false;
}
unset($edicionFila);

// Misma defensa en profundidad que arriba, aplicada a $ediciones: sin CV ni
// token de evaluacion en el JSON embebido para el "Administrador de ingenio".
if ($esAdministradorIngenio) {
    foreach ($ediciones as &$edicionFila) {
        $edicionFila['instructor_cv'] = null;
        $edicionFila['instructor_cv_disponible'] = false;
        $edicionFila['evaluacion_token'] = null;
    }
    unset($edicionFila);
}

$anios = array_keys($anios);
rsort($anios, SORT_NUMERIC);
$nombresCursos = array_keys($nombresCursos);
sort($nombresCursos, SORT_NATURAL | SORT_FLAG_CASE);

// Historial de cargas de evaluaciones de instructor (cargas masivas + envios
// individuales), para el modal "modalHistorialCargas": permite ver quien/cuando subio
// cada carga y retirarla (carga completa o evaluacion individual) sin tener que borrar
// fila por fila. Es un listado global (no por instructor) porque una sola carga masiva
// puede incluir boletas de varios instructores a la vez (ver
// cengicursos/migrations/20260810_cargas_evaluaciones_instructor.sql).
// El rol "Administrador de ingenio" no tiene acceso a las encuestas de
// instructor, asi que tampoco a su historial de cargas -- se fuerza vacio sin
// importar $puedeGestionar (defensivo, por si algun dia se le otorgara ese
// permiso de gestion desde la UI de roles).
$historialCargasEvaluaciones = [];
if ($puedeGestionar && $puedeVerEncuestasInstructor) {
    $stmtHistorialMasivo = $db->query("
        SELECT
            cei.id AS carga_id,
            cei.archivo_nombre,
            cei.usuario_nombre,
            cei.total_filas,
            cei.creado,
            c.nombre_cursos AS curso_nombre,
            GROUP_CONCAT(DISTINCT i.nombre ORDER BY i.nombre SEPARATOR ', ') AS instructores_nombres
        FROM cargas_evaluaciones_instructor cei
        LEFT JOIN cursos c ON c.id = cei.curso_id
        LEFT JOIN evaluaciones_instructor ei ON ei.carga_id = cei.id
        LEFT JOIN enlaces_evaluacion_instructor eei ON eei.id = ei.enlace_id
        LEFT JOIN instructores i ON i.id = eei.instructor_id
        GROUP BY cei.id, cei.archivo_nombre, cei.usuario_nombre, cei.total_filas, cei.creado, c.nombre_cursos
        ORDER BY cei.creado DESC
        LIMIT 300
    ");
    foreach ($stmtHistorialMasivo->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $historialCargasEvaluaciones[] = [
            'tipo' => 'masiva',
            'carga_id' => (int) $fila['carga_id'],
            'evaluacion_id' => null,
            'fecha' => $fila['creado'],
            'curso_nombre' => $fila['curso_nombre'],
            'archivo_nombre' => $fila['archivo_nombre'],
            'usuario_nombre' => $fila['usuario_nombre'],
            'total_filas' => (int) $fila['total_filas'],
            'instructores_nombres' => $fila['instructores_nombres'],
        ];
    }

    $stmtHistorialIndividual = $db->query("
        SELECT
            ei.id AS evaluacion_id,
            ei.creado,
            ei.curso_nombre,
            ei.conferencista AS instructor_nombre,
            ei.cargo,
            ei.seccion,
            ei.ingenio_otro,
            ing.nombre_ingenios AS ingenio_nombre
        FROM evaluaciones_instructor ei
        LEFT JOIN ingenios ing ON ing.id = ei.ingenio_id
        WHERE ei.carga_id IS NULL
        ORDER BY ei.creado DESC
        LIMIT 300
    ");
    foreach ($stmtHistorialIndividual->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $ingenioReferencia = trim((string) ($fila['ingenio_nombre'] ?? '')) !== ''
            ? $fila['ingenio_nombre']
            : ($fila['ingenio_otro'] ?? '');
        $historialCargasEvaluaciones[] = [
            'tipo' => 'individual',
            'carga_id' => null,
            'evaluacion_id' => (int) $fila['evaluacion_id'],
            'fecha' => $fila['creado'],
            'curso_nombre' => $fila['curso_nombre'],
            'instructor_nombre' => $fila['instructor_nombre'],
            // El formulario publico es anonimo respecto al nombre de quien lo llena, asi
            // que el "quien" que se muestra en el historial es cargo + seccion + ingenio
            // (los unicos datos identificadores que el participante si escribe).
            'cargo' => $fila['cargo'],
            'seccion' => $fila['seccion'],
            'ingenio_referencia' => $ingenioReferencia,
        ];
    }

    usort($historialCargasEvaluaciones, static function ($a, $b) {
        return strcmp((string) $b['fecha'], (string) $a['fecha']);
    });
}

function cengi_inst_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<?php include('head.php'); ?>
<style>
.cengi-instructors-page {
    --inst-ink: #1E2A1A;
    --inst-muted: #4B5A45;
    --inst-line: #E4E7E1;
    --inst-paper: #F6F7F3;
    --inst-green: #73BC25;
    --inst-green-dark: #3E7A12;
    color: var(--inst-ink);
}
.cengi-instructors-page * { box-sizing: border-box; }
.cengi-instructors-page .inst-section {
    margin-bottom: 18px;
    overflow: hidden;
    background: #fff;
    border: 1px solid var(--inst-line);
    border-radius: 10px;
    box-shadow: 0 1px 2px rgba(30,42,26,.06), 0 4px 16px rgba(30,42,26,.05);
}
.cengi-instructors-page .inst-section-body { padding: 18px; }
.cengi-instructors-page .inst-filter-row,
.cengi-instructors-page .inst-filter-group,
.cengi-instructors-page .inst-action-group {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.cengi-instructors-page .inst-filter-row { justify-content: space-between; }
.cengi-instructors-page .inst-search {
    min-width: 210px;
    height: 35px;
    padding: 8px 12px 8px 34px;
    border: 1px solid var(--inst-line);
    border-radius: 8px;
    background: #fff url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2216%22 height=%2216%22 viewBox=%220 0 24 24%22 fill=%22none%22 stroke=%22%23A3AAA0%22 stroke-width=%222%22%3E%3Ccircle cx=%2211%22 cy=%2211%22 r=%228%22/%3E%3Cpath d=%22M21 21l-4.35-4.35%22/%3E%3C/svg%3E') no-repeat 10px center;
    color: var(--inst-ink);
    font: 12.5px 'Inter', sans-serif;
    outline: none;
}
.cengi-instructors-page .inst-search:focus,
.cengi-instructors-page .inst-filter:focus,
.cengi-inst-modal .inst-field input:focus,
.cengi-inst-modal .inst-field select:focus {
    border-color: var(--cengi-primary);
    box-shadow: 0 0 0 2px rgba(163,211,0,.3);
}
.cengi-instructors-page .inst-filter {
    height: 35px;
    padding: 7px 10px;
    border: 1px solid var(--inst-line);
    border-radius: 8px;
    background: #fff;
    color: var(--inst-ink);
    font: 12.5px 'Inter', sans-serif;
    outline: none;
}
.cengi-instructors-page .inst-btn,
.cengi-inst-modal .inst-btn {
    min-height: 34px;
    padding: 7px 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    border: 0;
    border-radius: 8px;
    font: 600 12px 'Inter', sans-serif;
    line-height: 1.2;
    text-decoration: none;
    cursor: pointer;
    transition: filter .12s ease, background-color .12s ease, transform .05s ease;
}
.cengi-instructors-page .inst-btn:active,
.cengi-inst-modal .inst-btn:active { transform: translateY(1px); }
.cengi-instructors-page .inst-btn svg,
.cengi-inst-modal .inst-btn svg { width: 14px; height: 14px; }
.cengi-instructors-page .inst-btn-primary,
.cengi-inst-modal .inst-btn-primary { background: var(--inst-green, #73BC25); color: #fff; }
.cengi-instructors-page .inst-btn-yellow { background: #FFCC00; color: #4A3900; }
.cengi-instructors-page .inst-btn-outline,
.cengi-inst-modal .inst-btn-outline { border: 1px solid var(--inst-line, #E4E7E1); background: #fff; color: var(--inst-ink, #1E2A1A); }
.cengi-instructors-page .inst-btn-ghost { background: transparent; color: var(--inst-muted); }
.cengi-instructors-page .inst-btn:hover,
.cengi-inst-modal .inst-btn:hover { filter: brightness(1.04); text-decoration: none; }
.cengi-instructors-page .inst-btn-sm { min-height: 29px; padding: 6px 10px; font-size: 11.5px; }
.cengi-instructors-page .inst-switch-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}
.cengi-instructors-page .inst-tabs { display: flex; gap: 4px; }
.cengi-instructors-page .inst-tab {
    margin-right: 18px;
    padding: 11px 4px;
    border: 0;
    border-bottom: 2px solid transparent;
    background: transparent;
    color: var(--inst-muted);
    font: 600 12.5px 'Inter', sans-serif;
    cursor: pointer;
}
.cengi-instructors-page .inst-tab.is-active { color: var(--inst-green-dark); border-bottom-color: var(--inst-green); }
.cengi-instructors-page .inst-view-toggle {
    display: flex;
    gap: 2px;
    padding: 2px;
    border: 1px solid var(--inst-line);
    border-radius: 8px;
    background: var(--inst-paper);
}
.cengi-instructors-page .inst-view-btn { min-height: 28px; padding: 5px 8px; border-radius: 6px; }
.cengi-instructors-page .inst-view-btn.is-active { background: var(--inst-ink); color: #fff; }
.cengi-instructors-page .inst-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
.cengi-instructors-page .inst-card { margin: 0; }
.cengi-instructors-page .inst-card .inst-section-body { padding: 18px; }
.cengi-instructors-page .inst-card-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
.cengi-instructors-page .inst-name-cell { display: flex; align-items: center; gap: 9px; min-width: 0; }
.cengi-instructors-page .inst-avatar {
    width: 38px;
    height: 38px;
    flex: 0 0 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #CED2D5;
    color: #4B5A45;
    font: 700 12px 'Space Grotesk', sans-serif;
}
.cengi-instructors-page .inst-name { overflow: hidden; color: var(--inst-ink); font-size: 13.5px; font-weight: 600; text-overflow: ellipsis; white-space: nowrap; }
.cengi-instructors-page .inst-sub { margin-top: 1px; color: var(--inst-muted); font-size: 11px; line-height: 1.35; }
.cengi-instructors-page .inst-card-tools { display: flex; gap: 4px; }
.cengi-instructors-page .inst-icon-btn,
.cengi-inst-modal .inst-icon-btn {
    width: 28px;
    height: 28px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--inst-line, #E4E7E1);
    border-radius: 7px;
    background: #fff;
    color: var(--inst-muted, #4B5A45);
    cursor: pointer;
}
.cengi-instructors-page .inst-icon-btn:hover,
.cengi-inst-modal .inst-icon-btn:hover { background: #F2F4EF; color: var(--inst-ink, #1E2A1A); }
.cengi-instructors-page .inst-stats { display: flex; gap: 20px; margin-top: 14px; }
.cengi-instructors-page .inst-stat-value,
.cengi-inst-modal .inst-stat-value { font: 700 17px 'Space Grotesk', sans-serif; }
.cengi-instructors-page .inst-stat-value.is-green,
.cengi-inst-modal .inst-stat-value.is-green { color: var(--inst-green-dark, #3E7A12); }
.cengi-instructors-page .inst-stat-label,
.cengi-inst-modal .inst-stat-label { margin-top: 2px; color: var(--inst-muted, #4B5A45); font-size: 11.5px; }
.cengi-instructors-page .inst-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 12px; }
.cengi-instructors-page .inst-chip,
.cengi-inst-modal .inst-chip {
    padding: 4px 9px;
    display: inline-flex;
    align-items: center;
    border: 0;
    border-radius: 100px;
    background: #EEF1EC;
    color: var(--inst-muted, #4B5A45);
    font-size: 11px;
    font-weight: 600;
    line-height: 1.25;
}
.cengi-instructors-page button.inst-chip { cursor: pointer; }
.cengi-instructors-page .inst-chip.is-cv,
.cengi-inst-modal .inst-chip.is-cv { background: #EAF6DD; color: #3E7A12; }
.cengi-instructors-page .inst-chip.is-missing,
.cengi-inst-modal .inst-chip.is-missing { background: #FFF6DA; color: #8A6600; }
.cengi-instructors-page .inst-card-actions { display: flex; gap: 8px; margin-top: 14px; }
.cengi-instructors-page .inst-card-actions .inst-btn:first-child { flex: 1; }
.cengi-instructors-page .inst-cv-preview {
    display: none;
    margin: 14px -18px -18px;
    padding: 14px 18px;
    border-top: 1px dashed var(--inst-line);
    background: #FAFBF7;
}
.cengi-instructors-page .inst-cv-preview.is-open { display: block; }
.cengi-instructors-page .inst-cv-row { display: flex; gap: 12px; align-items: flex-start; }
.cengi-instructors-page .inst-cv-doc {
    width: 60px;
    height: 78px;
    flex: 0 0 auto;
    position: relative;
    border: 1px solid var(--inst-line);
    border-radius: 6px;
    background: #fff;
    box-shadow: 0 1px 2px rgba(30,42,26,.06), 0 4px 16px rgba(30,42,26,.05);
}
.cengi-instructors-page .inst-cv-doc::before { content: ""; position: absolute; top: 10px; left: 9px; right: 9px; height: 5px; border-radius: 2px; background: #E4E7E1; }
.cengi-instructors-page .inst-cv-doc::after { content: "PDF"; position: absolute; bottom: 7px; left: 9px; color: #B23223; font-size: 8px; font-weight: 700; }
.cengi-instructors-page .inst-list-wrap { overflow: hidden; }
.cengi-instructors-page .inst-list-row { display: flex; align-items: center; gap: 14px; padding: 12px 16px; border-bottom: 1px solid var(--inst-line); }
.cengi-instructors-page .inst-list-row:last-child { border-bottom: 0; }
.cengi-instructors-page .inst-list-row:hover { background: #FAFBF7; }
.cengi-instructors-page .inst-list-main { flex: 1; min-width: 190px; }
.cengi-instructors-page .inst-list-stat { min-width: 90px; text-align: center; }
.cengi-instructors-page .inst-empty { padding: 34px; text-align: center; color: var(--inst-muted); font-size: 12.5px; }
.cengi-instructors-page .inst-report-head { padding: 16px 18px; border-bottom: 1px solid var(--inst-line); }
.cengi-instructors-page .inst-report-head h3 { margin: 0; font: 600 14.5px 'Space Grotesk', sans-serif; }
.cengi-instructors-page .inst-hint { margin-top: 2px; color: var(--inst-muted); font-size: 11.5px; }
.cengi-instructors-page .inst-report-group { border-bottom: 1px solid var(--inst-line); }
.cengi-instructors-page .inst-report-group:last-child { border-bottom: 0; }
.cengi-instructors-page .inst-report-group-head { display: flex; justify-content: space-between; gap: 12px; padding: 13px 18px; background: #FAFBF7; font-size: 12.5px; font-weight: 700; }
.cengi-instructors-page .inst-report-group-head small { color: var(--inst-muted); font-size: 11px; font-weight: 500; }
.cengi-instructors-page .inst-table-scroll { overflow-x: auto; }
.cengi-instructors-page .inst-table,
.cengi-inst-modal .inst-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.cengi-instructors-page .inst-table th,
.cengi-inst-modal .inst-table th { padding: 10px 12px; border-bottom: 1px solid var(--inst-line, #E4E7E1); background: #FAFBF8; color: var(--inst-muted, #4B5A45); font-size: 10.5px; font-weight: 600; letter-spacing: .05em; text-align: left; text-transform: uppercase; white-space: nowrap; }
.cengi-instructors-page .inst-table td,
.cengi-inst-modal .inst-table td { padding: 11px 12px; border-bottom: 1px solid var(--inst-line, #E4E7E1); vertical-align: middle; }
.cengi-instructors-page .inst-table tr:last-child td,
.cengi-inst-modal .inst-table tr:last-child td { border-bottom: 0; }
.cengi-instructors-page .inst-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 100px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.cengi-instructors-page .inst-badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.cengi-instructors-page .inst-badge.is-active { background: #EAF6DD; color: #3E7A12; }
.cengi-instructors-page .inst-badge.is-finished { background: #EDEFEA; color: #5B6459; }
.cengi-instructors-page .inst-badge.is-planned { background: #FFF6DA; color: #8A6600; }
@media (max-width: 1120px) {
    .cengi-instructors-page .inst-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .cengi-instructors-page .inst-filter-row { align-items: flex-start; }
}
@media (max-width: 760px) {
    .cengi-instructors-page .inst-grid { grid-template-columns: 1fr; }
    .cengi-instructors-page .inst-filter-group, .cengi-instructors-page .inst-action-group { width: 100%; }
    .cengi-instructors-page .inst-search, .cengi-instructors-page .inst-filter { width: 100%; }
    .cengi-instructors-page .inst-action-group .inst-btn { flex: 1; }
    .cengi-instructors-page .inst-tabs { overflow-x: auto; width: 100%; }
    .cengi-instructors-page .inst-tab { flex: 0 0 auto; }
    .cengi-instructors-page .inst-list-row { align-items: flex-start; flex-wrap: wrap; }
    .cengi-instructors-page .inst-list-stat { min-width: 76px; text-align: left; }
}
</style>
<body class="cengi-canvas">
<?php menu_render(); ?>
<main class="container cengi-instructors-page">
    <?php if ($mensaje !== ''): ?>
        <div class="cengi-feedback<?php echo $mensajeTipo === 'error' ? ' is-error' : ''; ?>">
            <div class="cengi-feedback-icon"><span class="glyphicon glyphicon-<?php echo $mensajeTipo === 'error' ? 'remove' : 'ok'; ?>"></span></div>
            <div><p><?php echo cengi_inst_html($mensaje); ?></p></div>
        </div>
    <?php endif; ?>

    <section class="inst-section">
        <div class="inst-section-body">
            <div class="inst-filter-row">
                <div class="inst-filter-group">
                    <input class="inst-search" id="instructoresBuscar" type="search" placeholder="Buscar instructor o especialidad..." autocomplete="off">
                    <select class="inst-filter" id="instructoresAnio" aria-label="Filtrar por año">
                        <option value="todos">Todos los años</option>
                        <?php foreach ($anios as $anio): ?><option value="<?php echo cengi_inst_html($anio); ?>"><?php echo cengi_inst_html($anio); ?></option><?php endforeach; ?>
                    </select>
                    <select class="inst-filter" id="instructoresCurso" aria-label="Filtrar por curso">
                        <option value="todos">Todos los cursos / diplomados</option>
                        <?php foreach ($nombresCursos as $nombreCurso): ?><option value="<?php echo cengi_inst_html($nombreCurso); ?>"><?php echo cengi_inst_html($nombreCurso); ?></option><?php endforeach; ?>
                    </select>
                    <select class="inst-filter" id="instructoresOrden" aria-label="Ordenar instructores">
                        <option value="cursos_desc">Ordenar por: más cursos impartidos</option>
                        <option value="cursos_asc">Ordenar por: menos cursos impartidos</option>
                        <option value="eval_desc">Ordenar por: mejor evaluación</option>
                        <option value="nombre">Ordenar por: nombre (A–Z)</option>
                    </select>
                </div>
                <?php if ($puedeGestionar): ?>
                    <div class="inst-action-group">
                        <button class="inst-btn inst-btn-yellow inst-btn-sm" type="button" data-inst-open="modalCargaEvaluaciones">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></svg>
                            Carga masiva de evaluaciones
                        </button>
                        <button class="inst-btn inst-btn-outline inst-btn-sm" type="button" data-inst-open="modalHistorialCargas">
                            <span class="glyphicon glyphicon-time" aria-hidden="true"></span>
                            Historial de cargas
                        </button>
                        <button class="inst-btn inst-btn-primary" type="button" id="nuevoInstructorBtn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                            Nuevo instructor
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="inst-switch-row">
        <div class="inst-tabs" role="tablist">
            <button class="inst-tab is-active" type="button" data-inst-tab="directorio">Directorio de instructores</button>
            <?php if ($puedeVerReportesInstructor): ?>
            <button class="inst-tab" type="button" data-inst-tab="informe">Informe general por curso y diplomado</button>
            <?php endif; ?>
        </div>
        <div class="inst-view-toggle" id="instViewToggle">
            <button class="inst-btn inst-btn-ghost inst-btn-sm inst-view-btn is-active" type="button" data-inst-view="grid">▦ Tarjetas</button>
            <button class="inst-btn inst-btn-ghost inst-btn-sm inst-view-btn" type="button" data-inst-view="lista">☰ Lista</button>
        </div>
    </div>

    <section data-inst-panel="directorio">
        <div class="inst-grid" id="gridInstructores"></div>
    </section>
    <?php if ($puedeVerReportesInstructor): ?>
    <section data-inst-panel="informe" hidden>
        <div class="inst-section">
            <div class="inst-report-head">
                <h3>Informe de instructores por curso y diplomado</h3>
                <div class="inst-hint" id="informeSub">Agrupado por curso/diplomado · todos los años</div>
            </div>
            <div id="informeInstructores"></div>
        </div>
    </section>
    <?php endif; ?>
</main>

<style>
.cengi-inst-modal {
    --inst-ink: #1E2A1A;
    --inst-muted: #4B5A45;
    --inst-line: #E4E7E1;
    --inst-green: #73BC25;
    --inst-green-dark: #3E7A12;
    position: fixed;
    inset: 0;
    z-index: 2000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(20,26,15,.45);
    color: var(--inst-ink);
    font-family: 'Inter', sans-serif;
}
.cengi-inst-modal.is-open { display: flex; }
.cengi-inst-modal .inst-modal-card {
    width: 480px;
    max-width: 92vw;
    max-height: 90vh;
    overflow-y: auto;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
}
.cengi-inst-modal .inst-modal-card.is-wide { width: 700px; }
.cengi-inst-modal .inst-modal-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 18px 22px; border-bottom: 1px solid var(--inst-line); }
.cengi-inst-modal .inst-modal-head h3 { margin: 0; font: 600 15px 'Space Grotesk', sans-serif; }
.cengi-inst-modal .inst-modal-sub { margin-top: 2px; color: var(--inst-muted); font-size: 11.5px; }
.cengi-inst-modal .inst-modal-body { padding: 22px; }
.cengi-inst-modal .inst-modal-footer { display: flex; justify-content: flex-end; gap: 10px; padding: 16px 22px; border-top: 1px solid var(--inst-line); }
.cengi-inst-modal .inst-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 16px; }
.cengi-inst-modal .inst-field { display: flex; flex-direction: column; gap: 5px; }
.cengi-inst-modal .inst-field.is-full { grid-column: 1 / -1; }
.cengi-inst-modal .inst-field label { margin: 0; color: var(--inst-ink); font-size: 12px; font-weight: 600; }
.cengi-inst-modal .inst-optional { color: var(--inst-muted); font-size: 11px; font-weight: 400; }
.cengi-inst-modal .inst-field input,
.cengi-inst-modal .inst-field select { width: 100%; height: 37px; padding: 8px 11px; border: 1px solid var(--inst-line); border-radius: 8px; background: #fff; color: var(--inst-ink); font: 12.5px 'Inter', sans-serif; outline: none; }
.cengi-inst-modal .inst-dropzone { padding: 20px 16px; border: 1.5px dashed var(--inst-line); border-radius: 9px; color: var(--inst-muted); font-size: 11.5px; text-align: center; cursor: pointer; }
.cengi-inst-modal .inst-dropzone:hover { border-color: var(--inst-green); background: #FAFDF6; }
.cengi-inst-modal .inst-dropzone svg { width: 20px; height: 20px; margin-bottom: 5px; }
.cengi-inst-modal .inst-notice { display: flex; gap: 8px; margin-bottom: 16px; padding: 11px 14px; border: 1px solid #F4E5AC; border-radius: 9px; background: #FFF6DA; color: #7A5D00; font-size: 11.5px; line-height: 1.5; }
.cengi-inst-modal .inst-notice svg { width: 15px; height: 15px; flex: 0 0 auto; margin-top: 1px; }
.cengi-inst-modal .inst-notice.is-error { border-color: #F3B8B8; background: #FDEDED; color: #A32626; }
.cengi-inst-modal .inst-profile-head { display: flex; align-items: center; gap: 14px; padding: 20px 22px; border-bottom: 1px solid var(--inst-line); }
.cengi-inst-modal .inst-profile-avatar { width: 52px; height: 52px; flex: 0 0 52px; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: var(--inst-green); color: #fff; font: 700 17px 'Space Grotesk', sans-serif; }
.cengi-inst-modal .inst-profile-copy { flex: 1; min-width: 0; }
.cengi-inst-modal .inst-profile-name { font: 700 16px 'Space Grotesk', sans-serif; }
.cengi-inst-modal .inst-profile-sub { margin-top: 2px; color: var(--inst-muted); font-size: 12px; }
.cengi-inst-modal .inst-profile-stats { display: flex; gap: 22px; padding: 16px 22px; border-bottom: 1px solid var(--inst-line); }
.cengi-inst-modal .inst-detail-tabs { display: flex; gap: 4px; padding: 0 18px; border-bottom: 1px solid var(--inst-line); }
.cengi-inst-modal .inst-detail-tab { margin-right: 18px; padding: 11px 4px; border: 0; border-bottom: 2px solid transparent; background: transparent; color: var(--inst-muted); font-size: 12.5px; font-weight: 600; cursor: pointer; }
.cengi-inst-modal .inst-detail-tab.is-active { border-bottom-color: var(--inst-green); color: var(--inst-green-dark); }
.cengi-inst-modal .inst-detail-panel { padding: 18px 22px; }
.cengi-inst-modal .inst-detail-empty { padding: 28px 10px; color: var(--inst-muted); font-size: 12.5px; text-align: center; }
.cengi-inst-modal .inst-eval-summary { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.cengi-inst-modal .inst-eval-box { padding: 14px; border: 1px solid var(--inst-line); border-radius: 9px; }
.cengi-inst-modal .inst-progress { width: 100%; height: 7px; margin-top: 8px; overflow: hidden; border-radius: 100px; background: #EDEFEA; }
.cengi-inst-modal .inst-progress span { display: block; height: 100%; border-radius: inherit; background: #FFCC00; }
.cengi-inst-modal .inst-satisfaction-block { margin-bottom: 24px; }
.cengi-inst-modal .inst-block-title { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
.cengi-inst-modal .inst-block-title h4 { margin: 0; font: 700 13.5px 'Space Grotesk', sans-serif; color: var(--inst-ink); }
.cengi-inst-modal .inst-block-hint { margin-top: 2px; color: var(--inst-muted); font-size: 11px; }
.cengi-inst-modal .inst-chart-grid { display: grid; grid-template-columns: 1.5fr 1fr; gap: 14px; margin-bottom: 14px; }
.cengi-inst-modal .inst-chart-card { padding: 14px; border: 1px solid var(--inst-line); border-radius: 9px; background: #FAFBF7; }
.cengi-inst-modal .inst-chart-card-title { margin-bottom: 10px; color: var(--inst-muted); font-size: 11.5px; font-weight: 600; }
.cengi-inst-modal .inst-chart-wrap { position: relative; height: 168px; }
.cengi-inst-modal .inst-score-card .inst-chart-wrap { height: 130px; }
.cengi-inst-modal .inst-score-overlay { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; pointer-events: none; }
.cengi-inst-modal .inst-score-overlay b { font: 700 24px 'Space Grotesk', sans-serif; color: var(--inst-green-dark); }
.cengi-inst-modal .inst-score-overlay span { color: var(--inst-muted); font-size: 10.5px; font-weight: 600; }
.cengi-inst-modal .inst-comments-list { display: flex; flex-direction: column; gap: 8px; }
.cengi-inst-modal .inst-comment-item { padding: 10px 12px; border: 1px solid var(--inst-line); border-radius: 8px; background: #fff; font-size: 12px; line-height: 1.5; }
.cengi-inst-modal .inst-comment-item strong { display: block; margin-bottom: 3px; color: var(--inst-green-dark); font-size: 10.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
.cengi-inst-modal .inst-comment-item time { display: block; margin-top: 5px; color: var(--inst-muted); font-size: 10.5px; }
.cengi-inst-modal .inst-satisfaction-divider { margin: 4px 0 20px; border: 0; border-top: 1px dashed var(--inst-line); }
.cengi-inst-modal .inst-academic-block h4 { margin: 0 0 12px; font: 700 13.5px 'Space Grotesk', sans-serif; color: var(--inst-ink); }
body.inst-modal-open { overflow: hidden; }
@media (max-width: 620px) {
    .cengi-inst-modal { padding: 10px; }
    .cengi-inst-modal .inst-form-grid, .cengi-inst-modal .inst-eval-summary, .cengi-inst-modal .inst-chart-grid { grid-template-columns: 1fr; }
    .cengi-inst-modal .inst-profile-stats { flex-wrap: wrap; }
}
</style>

<?php if ($puedeGestionar): ?>
<div class="cengi-inst-modal" id="modalInstructor" aria-hidden="true">
    <div class="inst-modal-card" role="dialog" aria-modal="true" aria-labelledby="instModalTitulo">
        <form method="POST" enctype="multipart/form-data" id="instForm">
            <div class="inst-modal-head">
                <h3 id="instModalTitulo">Nuevo instructor</h3>
                <button class="inst-icon-btn" type="button" data-inst-close aria-label="Cerrar">✕</button>
            </div>
            <div class="inst-modal-body">
                <input type="hidden" name="accion" id="instAccion" value="crear">
                <input type="hidden" name="id" id="instId" value="">
                <div class="inst-form-grid">
                    <div class="inst-field is-full">
                        <label for="instNombre">Nombre</label>
                        <input type="text" name="nombre" id="instNombre" placeholder="Ej. Ing. Rodolfo Melgar" required>
                    </div>
                    <div class="inst-field">
                        <label for="instEspecialidad">Especialidad</label>
                        <input type="text" name="especialidad" id="instEspecialidad" placeholder="Agronomía / Riego / Fitosanidad">
                    </div>
                    <div class="inst-field">
                        <label for="instTelefono">Teléfono</label>
                        <input type="text" name="telefono" id="instTelefono" placeholder="+502 0000-0000">
                    </div>
                    <div class="inst-field is-full">
                        <label for="instCorreo">Correo</label>
                        <input type="email" name="correo" id="instCorreo" placeholder="instructor@ejemplo.com">
                    </div>
                    <div class="inst-field is-full">
                        <label for="instCv">Hoja de vida (CV) <span class="inst-optional">PDF, DOC o DOCX</span></label>
                        <label class="inst-dropzone" for="instCv">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></svg>
                            <div id="instCvText">Arrastra el CV o haz clic para seleccionar</div>
                        </label>
                        <input type="file" name="cv" id="instCv" accept=".pdf,.doc,.docx" hidden>
                    </div>
                </div>
            </div>
            <div class="inst-modal-footer">
                <button class="inst-btn inst-btn-outline" type="button" data-inst-close>Cancelar</button>
                <button class="inst-btn inst-btn-primary" type="submit">Guardar</button>
            </div>
        </form>
    </div>
</div>

<div class="cengi-inst-modal" id="modalCargaEvaluaciones" aria-hidden="true">
    <div class="inst-modal-card is-wide" role="dialog" aria-modal="true" aria-labelledby="cargaEvalTitulo">
        <div class="inst-modal-head">
            <div>
                <h3 id="cargaEvalTitulo">Carga masiva de evaluaciones de instructor</h3>
                <div class="inst-modal-sub">Sube las boletas de evaluación recolectadas en papel o formulario externo</div>
            </div>
            <button class="inst-icon-btn" type="button" data-inst-close aria-label="Cerrar">✕</button>
        </div>
        <div class="inst-modal-body">
            <form method="POST" action="carga_evaluaciones_instructor.php" enctype="multipart/form-data" id="formCargaEvaluaciones">
                <div class="inst-form-grid" style="margin-bottom:14px;">
                    <div class="inst-field is-full">
                        <label for="cargaEdicion">Edición del curso / diplomado <span class="inst-optional">indica el año correcto — es clave para datos históricos</span></label>
                        <select name="curso_id" id="cargaEdicion"></select>
                    </div>
                </div>
                <div class="inst-notice">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
                    <span>No selecciones un solo instructor: un diplomado puede tener varios módulos con distintos instructores. El Excel debe incluir una columna <b>Instructor</b> para identificar a quién califica cada boleta. La columna opcional <b>Modulo</b> permite evaluar un módulo específico del curso (déjala vacía para evaluar el curso completo).</span>
                </div>
                <div class="inst-field">
                    <label for="evaluacionesCsv">Sube el archivo Excel (.xlsx) con las respuestas</label>
                    <label class="inst-dropzone" for="evaluacionesCsv" style="padding:28px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></svg>
                        <div style="color:var(--inst-ink);font-size:13px;font-weight:600;" id="evaluacionesCsvNombre">Arrastra tu archivo .xlsx aquí</div>
                        <div style="margin-top:3px;">Columnas: <b>Instructor</b>, Ingenio, Cargo, Sección, las 5 preguntas sobre el instructor (1-4), Recomendaría al instructor (1-10), Relevancia del tema y Logística del evento (1-4), Recomendaría el lugar/plataforma (1-10), Capacitaciones necesarias y Áreas de mejora; además, si la edición es Virtual: manejo de la plataforma (1-4); si es Presencial: calidad de las instalaciones (1-4), y opcionalmente Módulo (evaluación de ese módulo específico, vacío = curso completo).</div>
                    </label>
                    <input type="file" name="archivo" id="evaluacionesCsv" accept=".xlsx" hidden required>
                </div>
                <div style="display:flex;gap:10px;justify-content:center;margin-top:14px;">
                    <button class="inst-btn inst-btn-outline" type="button" id="descargarPlantillaCsv">Descargar plantilla Excel</button>
                    <button class="inst-btn inst-btn-primary" type="button" id="seleccionarEvaluacionesCsv">Seleccionar archivo</button>
                </div>
                <div class="inst-notice" id="cargaEvaluacionesAviso" style="display:none;margin:18px 0 0;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
                    <span id="cargaEvaluacionesAvisoTexto">El archivo quedó seleccionado.</span>
                </div>
                <div class="inst-modal-footer">
                    <button class="inst-btn inst-btn-outline" type="button" data-inst-close>Cancelar</button>
                    <button class="inst-btn inst-btn-primary" type="submit">Procesar carga</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="cengi-inst-modal" id="modalHistorialCargas" aria-hidden="true">
    <div class="inst-modal-card is-wide" role="dialog" aria-modal="true" aria-labelledby="historialCargasTitulo">
        <div class="inst-modal-head">
            <div>
                <h3 id="historialCargasTitulo">Historial de cargas de evaluaciones</h3>
                <div class="inst-modal-sub">Retira evaluaciones enviadas por error: una carga masiva completa, o un envío individual del formulario público</div>
            </div>
            <button class="inst-icon-btn" type="button" data-inst-close aria-label="Cerrar">✕</button>
        </div>
        <div class="inst-modal-body">
            <div id="historialCargasLista"></div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="cengi-inst-modal" id="modalInstructorDetalle" aria-hidden="true">
    <div class="inst-modal-card is-wide" role="dialog" aria-modal="true" aria-labelledby="instDetName">
        <div class="inst-profile-head">
            <div class="inst-profile-avatar" id="instDetAv">IN</div>
            <div class="inst-profile-copy">
                <div class="inst-profile-name" id="instDetName">Instructor</div>
                <div class="inst-profile-sub" id="instDetSub">Especialidad</div>
                <div style="margin-top:6px;" id="instDetCv"></div>
            </div>
            <button class="inst-icon-btn" type="button" data-inst-close aria-label="Cerrar">✕</button>
        </div>
        <div class="inst-profile-stats">
            <div><div class="inst-stat-value" id="instDetCursos">0</div><div class="inst-stat-label">Cursos impartidos</div></div>
            <div><div class="inst-stat-value is-green" id="instDetEval">—</div><div class="inst-stat-label">Evaluación promedio</div></div>
            <div><div class="inst-stat-value" id="instDetEstado">Activo</div><div class="inst-stat-label">Estado del instructor</div></div>
        </div>
        <div class="inst-detail-tabs">
            <button class="inst-detail-tab is-active" type="button" data-detail-tab="cursos">Cursos impartidos</button>
            <button class="inst-detail-tab" type="button" data-detail-tab="modulos">Módulos asignados</button>
            <button class="inst-detail-tab" type="button" data-detail-tab="evaluaciones">Evaluaciones recibidas</button>
        </div>
        <div class="inst-detail-panel" data-detail-panel="cursos" id="instDetHistorial"></div>
        <div class="inst-detail-panel" data-detail-panel="modulos" id="instDetModulos" hidden></div>
        <div class="inst-detail-panel" data-detail-panel="evaluaciones" id="instDetEvaluaciones" hidden></div>
        <div class="inst-modal-footer">
            <?php if ($puedeVerReportesInstructor): ?>
            <button class="inst-btn inst-btn-outline" type="button" id="imprimirInformeInstructor">Descargar informe PDF</button>
            <?php endif; ?>
            <button class="inst-btn inst-btn-primary" type="button" data-inst-close>Cerrar</button>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx-js-style@1.2.0/dist/xlsx.bundle.js"></script>
<script>
(function () {
    'use strict';

    if (window.Chart) {
        Chart.defaults.font.family = "'Inter',sans-serif";
        Chart.defaults.font.size = 11;
        Chart.defaults.color = '#55705f';
    }

    var instructores = <?php echo json_encode($instructores, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var ediciones = <?php echo json_encode($ediciones, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var historialCargas = <?php echo json_encode($historialCargasEvaluaciones, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var puedeGestionar = <?php echo $puedeGestionar ? 'true' : 'false'; ?>;
    var puedeVerEncuestasInstructor = <?php echo $puedeVerEncuestasInstructor ? 'true' : 'false'; ?>;
    var puedeVerReportesInstructor = <?php echo $puedeVerReportesInstructor ? 'true' : 'false'; ?>;
    var puedeVerCvInstructor = <?php echo $puedeVerCvInstructor ? 'true' : 'false'; ?>;
    var puedeVerLinkEvaluacion = <?php echo $puedeVerLinkEvaluacion ? 'true' : 'false'; ?>;
    var instDetailCharts = {};
    var instView = 'grid';
    var activeTab = 'directorio';

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function normalize(value) {
        var text = String(value == null ? '' : value).toLowerCase();
        return text.normalize ? text.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : text;
    }
    function initials(name) {
        var cleaned = String(name || '').replace(/\b(Ing|Inga|Dr|Dra|Lic|Licda)\.?\s*/gi, '').trim();
        return cleaned.split(/\s+/).filter(Boolean).map(function (word) { return word.charAt(0); }).slice(0, 2).join('').toUpperCase() || 'IN';
    }
    function evaluationValue(value) {
        if (value === null || value === undefined || value === '') return '—';
        var number = Number(value);
        return Number.isFinite(number) ? number.toFixed(1) : '—';
    }
    function cvName(path) {
        if (!path) return '';
        var parts = String(path).split(/[\\/]/);
        return (parts[parts.length - 1] || 'Hoja de vida').replace(/^\d+_/, '');
    }
    function instructorEditions(id) {
        return ediciones.filter(function (course) { return Number(course.instructor_id) === Number(id); });
    }
    function institutionLabel(courseList) {
        var names = [];
        courseList.forEach(function (course) {
            var name = String(course.ingenio || '').trim();
            if (name && names.indexOf(name) === -1) names.push(name);
        });
        if (!names.length) return 'Sin institución asociada';
        return names.length === 1 ? names[0] : 'Varios ingenios';
    }
    function selectedFilters() {
        return {
            query: normalize(document.getElementById('instructoresBuscar').value).trim(),
            year: document.getElementById('instructoresAnio').value,
            course: document.getElementById('instructoresCurso').value,
            order: document.getElementById('instructoresOrden').value
        };
    }
    function filteredEditions(courseList, filters) {
        return courseList.filter(function (course) {
            return (filters.year === 'todos' || String(course.anio) === filters.year) &&
                (filters.course === 'todos' || String(course.nombre_cursos) === filters.course);
        });
    }
    function getFilteredInstructors() {
        var filters = selectedFilters();
        var filtersActive = filters.year !== 'todos' || filters.course !== 'todos';
        var result = instructores.map(function (instructor) {
            var allEditions = instructorEditions(instructor.id);
            return { instructor: instructor, allEditions: allEditions, matchingEditions: filteredEditions(allEditions, filters) };
        });
        if (filtersActive) result = result.filter(function (item) { return item.matchingEditions.length > 0; });
        if (filters.query) {
            result = result.filter(function (item) {
                return normalize(item.instructor.nombre).indexOf(filters.query) !== -1 || normalize(item.instructor.especialidad).indexOf(filters.query) !== -1;
            });
        }
        result.sort(function (left, right) {
            var a = left.instructor, b = right.instructor;
            if (filters.order === 'cursos_asc') return Number(a.total_cursos || 0) - Number(b.total_cursos || 0);
            if (filters.order === 'eval_desc') return Number(b.evaluacion_promedio || -1) - Number(a.evaluacion_promedio || -1);
            if (filters.order === 'nombre') return String(a.nombre).localeCompare(String(b.nombre), 'es');
            return Number(b.total_cursos || 0) - Number(a.total_cursos || 0);
        });
        return { items: result, filters: filters, filtersActive: filtersActive };
    }
    function managementTools(instructor) {
        if (!puedeGestionar) return '';
        var nextLabel = Number(instructor.estado) === 1 ? 'Desactivar' : 'Activar';
        var icon = Number(instructor.estado) === 1 ? 'glyphicon-eye-close' : 'glyphicon-eye-open';
        return '<div class="inst-card-tools"><button class="inst-icon-btn" type="button" data-edit-instructor="' + Number(instructor.id) + '" title="Editar instructor"><span class="glyphicon glyphicon-pencil"></span></button>' +
            '<form method="POST" style="margin:0;"><input type="hidden" name="accion" value="toggle_estado"><input type="hidden" name="id" value="' + Number(instructor.id) + '">' +
            '<button class="inst-icon-btn" type="submit" title="' + nextLabel + ' instructor"><span class="glyphicon ' + icon + '"></span></button></form></div>';
    }
    function cvChip(instructor, previewId) {
        // El "Administrador de ingenio" no tiene acceso al CV del instructor.
        if (!puedeVerCvInstructor) return '';
        return instructor.cv_disponible ? '<button class="inst-chip is-cv" type="button" data-cv-preview="' + previewId + '">📄 Ver CV</button>' : '<span class="inst-chip is-missing">⚠ Sin CV</span>';
    }
    function evaluationLinkButton(token) {
        // El "Administrador de ingenio" no puede generar/copiar el link de evaluación.
        if (!puedeVerLinkEvaluacion) return '';
        if (!token) return '<button class="inst-btn inst-btn-outline inst-btn-sm" type="button" disabled title="Este curso todavía no tiene enlace de evaluación">Sin enlace</button>';
        return '<button class="inst-btn inst-btn-outline inst-btn-sm" type="button" data-copy-eval-token="' + escapeHtml(token) + '">' +
            '<span class="glyphicon glyphicon-link" aria-hidden="true"></span> Copiar link de evaluación</button>';
    }
    function courseReportButton(course) {
        if (!puedeVerReportesInstructor) return '';
        return '<button class="inst-btn inst-btn-outline inst-btn-sm" type="button" onclick="window.open(\'informe_instructor_curso_pdf.php?instructor_id=' + Number(course.instructor_id) + '&curso_id=' + Number(course.curso_id) + '\', \'_blank\')">' +
            '<span class="glyphicon glyphicon-file" aria-hidden="true"></span> Reporte del curso</button>';
    }
    function moduloReportButton(modulo, instructorId) {
        if (!puedeVerReportesInstructor) return '';
        if (modulo.explicito) {
            return '<button class="inst-btn inst-btn-outline inst-btn-sm" type="button" onclick="window.open(\'informe_instructor_modulo_pdf.php?instructor_id=' + Number(instructorId) + '&curso_modulo_id=' + Number(modulo.modulo_id) + '\', \'_blank\')">' +
                '<span class="glyphicon glyphicon-file" aria-hidden="true"></span> Reporte del módulo</button>';
        }
        return '<button class="inst-btn inst-btn-outline inst-btn-sm" type="button" onclick="window.open(\'informe_instructor_curso_pdf.php?instructor_id=' + Number(instructorId) + '&curso_id=' + Number(modulo.curso_id) + '\', \'_blank\')">' +
            '<span class="glyphicon glyphicon-file" aria-hidden="true"></span> Reporte del curso</button>';
    }
    function copyEvaluationLink(token, button) {
        var url = window.location.origin + '/cengicursos/evaluacion.php?token=' + encodeURIComponent(token);
        var restoreLabel = button.innerHTML;
        function showCopied() {
            button.innerHTML = '<span class="glyphicon glyphicon-ok" aria-hidden="true"></span> ¡Copiado!';
            window.setTimeout(function () { button.innerHTML = restoreLabel; }, 2000);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(showCopied, function () {
                window.prompt('Copia el enlace de evaluación:', url);
            });
        } else {
            window.prompt('Copia el enlace de evaluación:', url);
        }
    }
    function cvPreview(instructor, previewId, isList) {
        if (!puedeVerCvInstructor || !instructor.cv_disponible) return '';
        var path = escapeHtml(instructor.cv_path);
        return '<div class="inst-cv-preview" id="' + previewId + '"' + (isList ? ' style="margin:0;padding-left:60px;"' : '') + '><div class="inst-cv-row"><div class="inst-cv-doc"></div>' +
            '<div style="flex:1;min-width:0;"><div style="margin-bottom:6px;font-size:11.5px;font-weight:600;overflow-wrap:anywhere;">' + escapeHtml(cvName(instructor.cv_path)) + '</div>' +
            '<div style="display:flex;gap:6px;flex-wrap:wrap;"><a class="inst-btn inst-btn-primary inst-btn-sm" href="' + path + '" target="_blank" rel="noopener">Abrir documento</a>' +
            '<a class="inst-btn inst-btn-outline inst-btn-sm" href="' + path + '" download>Descargar</a></div></div></div></div>';
    }
    function renderGridItem(item, filtersActive) {
        var instructor = item.instructor;
        var count = filtersActive ? item.matchingEditions.length : Number(instructor.total_cursos || 0);
        var previewId = 'cvPreviewGrid' + Number(instructor.id);
        return '<article class="inst-section inst-card"><div class="inst-section-body"><div class="inst-card-head"><div class="inst-name-cell">' +
            '<div class="inst-avatar">' + escapeHtml(initials(instructor.nombre)) + '</div><div style="min-width:0;"><div class="inst-name">' + escapeHtml(instructor.nombre) + '</div>' +
            '<div class="inst-sub">' + escapeHtml(instructor.especialidad || 'Sin especialidad registrada') + '</div></div></div>' + managementTools(instructor) + '</div>' +
            '<div class="inst-stats"><div><div class="inst-stat-value">' + count + '</div><div class="inst-stat-label">' + (filtersActive ? 'Ediciones (filtro)' : 'Cursos impartidos') + '</div></div>' +
            '<div><div class="inst-stat-value is-green">' + evaluationValue(instructor.evaluacion_promedio) + '</div><div class="inst-stat-label">Evaluación promedio</div></div></div>' +
            '<div class="inst-chips"><span class="inst-chip">' + escapeHtml(institutionLabel(item.allEditions)) + '</span>' + cvChip(instructor, previewId) + '</div>' +
            '<div class="inst-card-actions"><button class="inst-btn inst-btn-outline inst-btn-sm" type="button" data-detail-instructor="' + Number(instructor.id) + '" data-detail-target="cursos">Ver historial</button>' +
            '<button class="inst-btn inst-btn-ghost inst-btn-sm" type="button" data-detail-instructor="' + Number(instructor.id) + '" data-detail-target="evaluaciones">Informe</button></div>' +
            cvPreview(instructor, previewId, false) + '</div></article>';
    }
    function renderListItem(item, filtersActive) {
        var instructor = item.instructor;
        var count = filtersActive ? item.matchingEditions.length : Number(instructor.total_cursos || 0);
        var previewId = 'cvPreviewList' + Number(instructor.id);
        return '<div class="inst-list-row"><div class="inst-avatar">' + escapeHtml(initials(instructor.nombre)) + '</div>' +
            '<div class="inst-list-main"><div class="inst-name">' + escapeHtml(instructor.nombre) + '</div><div class="inst-sub">' + escapeHtml(instructor.especialidad || 'Sin especialidad') + ' · ' + escapeHtml(institutionLabel(item.allEditions)) + '</div></div>' +
            '<div class="inst-list-stat"><div class="inst-stat-value">' + count + '</div><div class="inst-stat-label">' + (filtersActive ? 'Ediciones' : 'Cursos') + '</div></div>' +
            '<div class="inst-list-stat"><div class="inst-stat-value is-green">' + evaluationValue(instructor.evaluacion_promedio) + '</div><div class="inst-stat-label">Evaluación</div></div>' +
            '<div style="min-width:118px;">' + cvChip(instructor, previewId) + '</div><div style="display:flex;gap:6px;align-items:center;">' +
            '<button class="inst-btn inst-btn-outline inst-btn-sm" type="button" data-detail-instructor="' + Number(instructor.id) + '" data-detail-target="cursos">Historial</button>' +
            '<button class="inst-btn inst-btn-ghost inst-btn-sm" type="button" data-detail-instructor="' + Number(instructor.id) + '" data-detail-target="evaluaciones">Informe</button>' + managementTools(instructor) + '</div></div>' +
            cvPreview(instructor, previewId, true);
    }
    function renderInstructors() {
        var result = getFilteredInstructors();
        var container = document.getElementById('gridInstructores');
        if (!result.items.length) {
            container.className = '';
            container.innerHTML = '<div class="inst-section"><div class="inst-empty">No hay instructores que coincidan con estos filtros.</div></div>';
            return;
        }
        if (instView === 'grid') {
            container.className = 'inst-grid';
            container.innerHTML = result.items.map(function (item) { return renderGridItem(item, result.filtersActive); }).join('');
        } else {
            container.className = '';
            container.innerHTML = '<div class="inst-section inst-list-wrap">' + result.items.map(function (item) { return renderListItem(item, result.filtersActive); }).join('') + '</div>';
        }
    }
    function courseStatus(course) {
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        var start = course.inicio ? new Date(course.inicio + 'T00:00:00') : null;
        var end = course.fin ? new Date(course.fin + 'T00:00:00') : null;
        if (end && end < today) return { label: 'Finalizado', className: 'is-finished' };
        if ((!start || start <= today) && (!end || end >= today)) return { label: 'Activo', className: 'is-active' };
        return { label: 'Planificación', className: 'is-planned' };
    }
    function renderReport() {
        var filters = selectedFilters();
        var reportCourses = filteredEditions(ediciones, filters);
        var subtitle = 'Agrupado por curso/diplomado · ' + (filters.year === 'todos' ? 'todos los años' : 'año ' + filters.year);
        if (filters.course !== 'todos') subtitle += ' · ' + filters.course;
        document.getElementById('informeSub').textContent = subtitle;
        var groups = {};
        reportCourses.forEach(function (course) {
            var key = String(course.nombre_cursos || 'Curso sin nombre');
            if (!groups[key]) groups[key] = [];
            groups[key].push(course);
        });
        var names = Object.keys(groups).sort(function (a, b) { return a.localeCompare(b, 'es'); });
        var container = document.getElementById('informeInstructores');
        if (!names.length) {
            container.innerHTML = '<div class="inst-empty">No hay ediciones registradas para este filtro.</div>';
            return;
        }
        container.innerHTML = names.map(function (name) {
            var rows = groups[name].slice().sort(function (a, b) { return Number(b.anio || 0) - Number(a.anio || 0); });
            var category = rows[0] ? rows[0].categoria : '';
            // Columna "CV" oculta por completo (encabezado y celdas) para el
            // "Administrador de ingenio", que no tiene acceso al CV del instructor.
            var cvHeadCell = puedeVerCvInstructor ? '<th>CV</th>' : '';
            return '<div class="inst-report-group"><div class="inst-report-group-head"><span>' + escapeHtml(name) + '</span><small>' + escapeHtml(category) + ' · ' + rows.length + ' edición(es)</small></div>' +
                '<div class="inst-table-scroll"><table class="inst-table"><thead><tr><th>Año</th><th>Instructor</th><th>Especialidad</th><th>Estado</th><th>Evaluación prom.</th><th>Cupo</th>' + cvHeadCell + '</tr></thead><tbody>' +
                rows.map(function (course) {
                    var status = courseStatus(course);
                    var cvCell = '';
                    if (puedeVerCvInstructor) {
                        var cv = course.instructor_cv_disponible ? '<a class="inst-chip is-cv" href="' + escapeHtml(course.instructor_cv) + '" target="_blank" rel="noopener">📄 Ver CV</a>' : '<span class="inst-chip is-missing">⚠ Sin CV</span>';
                        cvCell = '<td>' + cv + '</td>';
                    }
                    return '<tr><td style="font-family:JetBrains Mono,monospace;font-size:11px;">' + escapeHtml(course.anio || '—') + '</td><td style="font-weight:600;">' + escapeHtml(course.instructor_nombre || 'Sin asignar') + '</td>' +
                        '<td>' + escapeHtml(course.instructor_especialidad || '—') + '</td><td><span class="inst-badge ' + status.className + '">' + status.label + '</span></td>' +
                        '<td>' + evaluationValue(course.evaluacion_promedio) + '</td><td>' + escapeHtml(course.cupo || course.total_inscritos || '—') + '</td>' + cvCell + '</tr>';
                }).join('') + '</tbody></table></div></div>';
        }).join('');
    }
    function renderAll() {
        renderInstructors();
        if (activeTab === 'informe') renderReport();
    }
    function openModal(id) {
        var modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('inst-modal-open');
        var focusable = modal.querySelector('input:not([type="hidden"]), select, button');
        if (focusable) window.setTimeout(function () { focusable.focus(); }, 20);
    }
    function closeModal(modal) {
        if (typeof modal === 'string') modal = document.getElementById(modal);
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        if (!document.querySelector('.cengi-inst-modal.is-open')) document.body.classList.remove('inst-modal-open');
    }
    function newInstructor() {
        var form = document.getElementById('instForm');
        if (!form) return;
        form.reset();
        document.getElementById('instModalTitulo').textContent = 'Nuevo instructor';
        document.getElementById('instAccion').value = 'crear';
        document.getElementById('instId').value = '';
        document.getElementById('instCvText').textContent = 'Arrastra el CV o haz clic para seleccionar';
        openModal('modalInstructor');
    }
    function editInstructor(id) {
        var instructor = instructores.find(function (item) { return Number(item.id) === Number(id); });
        if (!instructor) return;
        document.getElementById('instForm').reset();
        document.getElementById('instModalTitulo').textContent = 'Editar instructor';
        document.getElementById('instAccion').value = 'actualizar';
        document.getElementById('instId').value = instructor.id;
        document.getElementById('instNombre').value = instructor.nombre || '';
        document.getElementById('instEspecialidad').value = instructor.especialidad || '';
        document.getElementById('instCorreo').value = instructor.correo || '';
        document.getElementById('instTelefono').value = instructor.telefono || '';
        document.getElementById('instCvText').textContent = instructor.cv_path ? 'CV actual: ' + cvName(instructor.cv_path) : 'Arrastra el CV o haz clic para seleccionar';
        openModal('modalInstructor');
    }
    function formatDate(dateValue) {
        if (!dateValue) return 'Sin fecha';
        return new Date(dateValue + 'T00:00:00').toLocaleDateString('es-GT', { day: '2-digit', month: 'short', year: 'numeric' });
    }
    function openInstructorDetail(id, targetTab) {
        var instructor = instructores.find(function (item) { return Number(item.id) === Number(id); });
        if (!instructor) return;
        document.getElementById('modalInstructorDetalle').dataset.instructorId = id;
        var courses = instructorEditions(id);
        document.getElementById('instDetAv').textContent = initials(instructor.nombre);
        document.getElementById('instDetName').textContent = instructor.nombre || 'Instructor';
        document.getElementById('instDetSub').textContent = (instructor.especialidad || 'Sin especialidad') + ' · ' + institutionLabel(courses);
        document.getElementById('instDetCursos').textContent = Number(instructor.total_cursos || 0);
        document.getElementById('instDetEval').textContent = evaluationValue(instructor.evaluacion_promedio);
        document.getElementById('instDetEstado').textContent = Number(instructor.estado) === 1 ? 'Activo' : 'Inactivo';
        document.getElementById('instDetCv').innerHTML = !puedeVerCvInstructor ? '' : (instructor.cv_disponible ?
            '<a class="inst-chip is-cv" href="' + escapeHtml(instructor.cv_path) + '" target="_blank" rel="noopener">📄 ' + escapeHtml(cvName(instructor.cv_path)) + '</a>' :
            '<span class="inst-chip is-missing">⚠ Sin CV cargado</span>');
        var history = document.getElementById('instDetHistorial');
        if (!courses.length) {
            history.innerHTML = '<div class="inst-detail-empty">Este instructor todavía no tiene cursos asociados.</div>';
        } else {
            history.innerHTML = '<div style="overflow-x:auto;"><table class="inst-table"><thead><tr><th>Curso</th><th>Ingenio</th><th>Fecha</th><th>Modalidad</th><th>Participantes</th><th>Evaluación</th></tr></thead><tbody>' +
                courses.map(function (course) {
                    var range = formatDate(course.inicio) + (course.fin && course.fin !== course.inicio ? ' – ' + formatDate(course.fin) : '');
                    return '<tr><td style="font-weight:600;">' + escapeHtml(course.nombre_cursos) + '</td><td>' + escapeHtml(course.ingenio || '—') + '</td><td>' + escapeHtml(range) + '</td>' +
                        '<td>' + escapeHtml(course.modalidad || '—') + '</td><td>' + Number(course.total_inscritos || 0) + '</td>' +
                        '<td>' + evaluationLinkButton(course.evaluacion_token) + ' ' + courseReportButton(course) + '</td></tr>';
                }).join('') + '</tbody></table></div>';
        }
        renderModulosTab(instructor);
        renderSatisfactionTab(instructor, courses);
        switchDetailTab(targetTab === 'evaluaciones' ? 'evaluaciones' : (targetTab === 'modulos' ? 'modulos' : 'cursos'));
        openModal('modalInstructorDetalle');
    }
    function renderModulosTab(instructor) {
        var panel = document.getElementById('instDetModulos');
        if (!panel) return;
        var modulos = Array.isArray(instructor.modulos_asignados) ? instructor.modulos_asignados : [];
        if (!modulos.length) {
            panel.innerHTML = '<div class="inst-detail-empty">Este instructor todavía no tiene módulos asignados.</div>';
            return;
        }
        panel.innerHTML = '<div style="overflow-x:auto;"><table class="inst-table"><thead><tr><th>Curso</th><th>Módulo</th><th>Ingenio</th><th>Fechas</th><th>Horas</th><th>Rol</th><th>Evaluación</th></tr></thead><tbody>' +
            modulos.map(function (modulo) {
                var range = formatDate(modulo.inicio) + (modulo.fin && modulo.fin !== modulo.inicio ? ' – ' + formatDate(modulo.fin) : '');
                var rolLabel = modulo.rol === 'principal' ? 'Principal' : 'Co-instructor';
                return '<tr><td style="font-weight:600;">' + escapeHtml(modulo.nombre_cursos) + '</td><td>' + escapeHtml(modulo.modulo_nombre) + '</td>' +
                    '<td>' + escapeHtml(modulo.ingenio || '—') + '</td><td>' + escapeHtml(range) + '</td>' +
                    '<td>' + escapeHtml(modulo.modulo_horas != null ? modulo.modulo_horas : '—') + '</td>' +
                    '<td>' + escapeHtml(rolLabel) + '</td>' +
                    '<td>' + evaluationLinkButton(modulo.evaluacion_token) + ' ' + moduloReportButton(modulo, instructor.id) + '</td></tr>';
            }).join('') + '</tbody></table></div>';
    }
    function destroyDetailChart(key) {
        if (instDetailCharts[key]) {
            instDetailCharts[key].destroy();
            instDetailCharts[key] = null;
        }
    }
    function buildLikertBarChart(canvasId, key, labels, values, maxScale) {
        destroyDetailChart(key);
        var canvas = document.getElementById(canvasId);
        if (!canvas || typeof Chart === 'undefined') return;
        instDetailCharts[key] = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{ label: 'Promedio', data: values, backgroundColor: '#73BC25', borderRadius: 5, maxBarThickness: 26 }]
            },
            options: {
                indexAxis: 'y',
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) { return 'Promedio: ' + (ctx.raw === null ? 'Sin datos' : Number(ctx.raw).toFixed(2)); } } } },
                scales: { x: { min: 0, max: maxScale, ticks: { stepSize: maxScale === 4 ? 1 : 2 } } },
                maintainAspectRatio: false,
                animation: { duration: 350 }
            }
        });
    }
    function buildScoreDoughnutChart(canvasId, key, value, maxScale) {
        destroyDetailChart(key);
        var canvas = document.getElementById(canvasId);
        if (!canvas || typeof Chart === 'undefined') return;
        var ratio = Number.isFinite(value) ? Math.max(0, Math.min(1, value / maxScale)) : 0;
        instDetailCharts[key] = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: ['Puntaje', 'Restante'],
                datasets: [{ data: [ratio, 1 - ratio], backgroundColor: ['#73BC25', '#EDEFEA'], borderWidth: 0 }]
            },
            options: {
                cutout: '72%',
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                maintainAspectRatio: false,
                animation: { duration: 350 }
            }
        });
    }
    function scoreLabel(value, maxScale) {
        return Number.isFinite(value) ? value.toFixed(1) + '/' + maxScale : '—/' + maxScale;
    }
    function renderComentariosSatisfaccion(comentarios) {
        if (!comentarios.length) {
            return '<div class="inst-detail-empty">Todavía no hay comentarios cualitativos registrados.</div>';
        }
        return '<div class="inst-comments-list">' + comentarios.map(function (comentario) {
            var partes = [];
            if (comentario.areas_mejora) partes.push('<div><strong>Oportunidades de mejora</strong>' + escapeHtml(comentario.areas_mejora) + '</div>');
            if (comentario.capacitaciones_necesarias) partes.push('<div><strong>Capacitaciones necesarias</strong>' + escapeHtml(comentario.capacitaciones_necesarias) + '</div>');
            return '<div class="inst-comment-item">' + partes.join('') + '<time>' + escapeHtml(formatDate((comentario.creado || '').slice(0, 10))) + '</time></div>';
        }).join('') + '</div>';
    }
    function renderSatisfactionTab(instructor, courses) {
        var encuesta = instructor.encuesta_satisfaccion || { total: 0, instructor: {}, tema: {}, comentarios: [] };
        var panel = document.getElementById('instDetEvaluaciones');
        var academicoAvg = Number(instructor.evaluacion_promedio);
        var academicoPct = Number.isFinite(academicoAvg) ? Math.max(0, Math.min(100, academicoAvg <= 5 ? academicoAvg * 20 : academicoAvg)) : 0;
        var academicoHtml = '<div class="inst-academic-block"><h4>Desempeño académico (post-evaluación de cursos)</h4>' +
            '<div class="inst-eval-summary"><div class="inst-eval-box"><div class="inst-stat-label">Evaluación promedio registrada</div>' +
            '<div class="inst-stat-value is-green" style="font-size:26px;margin-top:6px;">' + evaluationValue(instructor.evaluacion_promedio) + '</div><div class="inst-progress"><span style="width:' + academicoPct + '%"></span></div></div>' +
            '<div class="inst-eval-box"><div class="inst-stat-label">Cursos con datos de evaluación</div><div class="inst-stat-value" style="font-size:26px;margin-top:6px;">' +
            courses.filter(function (course) { return course.evaluacion_promedio !== null; }).length + '</div><div class="inst-stat-label" style="margin-top:10px;">Nota de examen posterior al curso de los participantes; no mide la satisfacción con el instructor.</div></div></div></div>';

        if (!puedeVerEncuestasInstructor) {
            // El rol "Administrador de ingenio" no tiene acceso a las encuestas de
            // satisfaccion del instructor: no se pinta ni siquiera el bloque
            // "sin datos todavia", solo el desempeño academico (que no es dato de
            // encuesta). El servidor ya envia encuesta_satisfaccion en cero/null
            // para este rol, pero esto evita ademas renderizar la seccion.
            panel.innerHTML = academicoHtml;
            destroyDetailChart('acercaInstructor');
            destroyDetailChart('recomiendaInstructor');
            destroyDetailChart('temaLogistica');
            destroyDetailChart('recomiendaContexto');
            return;
        }

        if (!encuesta.total) {
            panel.innerHTML = '<div class="inst-satisfaction-block"><div class="inst-block-title"><h4>Satisfacción de los participantes (encuesta al instructor)</h4></div>' +
                '<div class="inst-detail-empty">Aún no hay evaluaciones de satisfacción registradas para este instructor todavía.</div></div>' +
                '<hr class="inst-satisfaction-divider">' + academicoHtml;
            destroyDetailChart('acercaInstructor');
            destroyDetailChart('recomiendaInstructor');
            destroyDetailChart('temaLogistica');
            destroyDetailChart('recomiendaContexto');
            return;
        }

        panel.innerHTML =
            '<div class="inst-satisfaction-block">' +
            '<div class="inst-block-title"><div><h4>Satisfacción de los participantes (encuesta al instructor)</h4>' +
            '<div class="inst-block-hint">' + Number(encuesta.total) + ' respuesta(s) recibida(s) · escalas Likert 1 (Deficiente) a 4 (Excelente)</div></div></div>' +
            '<div class="inst-chart-grid">' +
            '<div class="inst-chart-card"><div class="inst-chart-card-title">Acerca del instructor</div><div class="inst-chart-wrap"><canvas id="instChartAcercaInstructor"></canvas></div></div>' +
            '<div class="inst-chart-card inst-score-card"><div class="inst-chart-card-title">¿Recomendaría a este instructor?</div>' +
            '<div class="inst-chart-wrap"><canvas id="instChartRecomiendaInstructor"></canvas><div class="inst-score-overlay"><b>' + scoreLabel(Number(encuesta.recomendaria_instructor), 10) + '</b><span>escala 1–10</span></div></div></div>' +
            '<div class="inst-chart-card"><div class="inst-chart-card-title">Tema y logística del curso</div><div class="inst-chart-wrap"><canvas id="instChartTemaLogistica"></canvas></div></div>' +
            '<div class="inst-chart-card inst-score-card"><div class="inst-chart-card-title">¿Recomendaría el lugar/plataforma?</div>' +
            '<div class="inst-chart-wrap"><canvas id="instChartRecomiendaContexto"></canvas><div class="inst-score-overlay"><b>' + scoreLabel(Number(encuesta.recomendaria_contexto), 10) + '</b><span>escala 1–10</span></div></div></div>' +
            '</div>' +
            '<div class="inst-chart-card-title">Comentarios recientes</div>' +
            renderComentariosSatisfaccion(encuesta.comentarios || []) +
            '</div>' +
            '<hr class="inst-satisfaction-divider">' + academicoHtml;

        var instAspectos = encuesta.instructor || {};
        buildLikertBarChart('instChartAcercaInstructor', 'acercaInstructor',
            ['Lenguaje claro', 'Material adecuado', 'Conocimiento del tema', 'Respeto a participantes', 'Puntualidad y objetivos'],
            [instAspectos.lenguaje_claro, instAspectos.material_adecuado, instAspectos.conocimiento_tema, instAspectos.respeto_participantes, instAspectos.puntualidad_objetivos].map(function (value) {
                return value === null || value === undefined ? null : Number(value);
            }), 4);
        buildScoreDoughnutChart('instChartRecomiendaInstructor', 'recomiendaInstructor', Number(encuesta.recomendaria_instructor), 10);

        var temaAspectos = encuesta.tema || {};
        buildLikertBarChart('instChartTemaLogistica', 'temaLogistica',
            ['Relevancia del tema', 'Logística del evento'],
            [temaAspectos.relevancia_utilidad, temaAspectos.logistica_evento].map(function (value) {
                return value === null || value === undefined ? null : Number(value);
            }), 4);
        buildScoreDoughnutChart('instChartRecomiendaContexto', 'recomiendaContexto', Number(encuesta.recomendaria_contexto), 10);
    }
    function switchDetailTab(tab) {
        document.querySelectorAll('[data-detail-tab]').forEach(function (button) { button.classList.toggle('is-active', button.getAttribute('data-detail-tab') === tab); });
        document.querySelectorAll('[data-detail-panel]').forEach(function (panel) { panel.hidden = panel.getAttribute('data-detail-panel') !== tab; });
    }
    function formatDateTime(dateValue) {
        if (!dateValue) return 'Sin fecha';
        var normalized = String(dateValue).replace(' ', 'T');
        var date = new Date(normalized);
        if (isNaN(date.getTime())) return 'Sin fecha';
        return date.toLocaleString('es-GT', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }
    function renderHistorialCargas() {
        var contenedor = document.getElementById('historialCargasLista');
        if (!contenedor) return;
        if (!historialCargas.length) {
            contenedor.innerHTML = '<div class="inst-detail-empty">Todavía no se ha registrado ninguna carga ni envío de evaluaciones.</div>';
            return;
        }
        contenedor.innerHTML = '<div style="overflow-x:auto;"><table class="inst-table"><thead><tr>' +
            '<th>Tipo</th><th>Fecha</th><th>Curso / edición</th><th>Detalle</th><th>Filas</th><th></th>' +
            '</tr></thead><tbody>' +
            historialCargas.map(function (item) {
                if (item.tipo === 'masiva') {
                    var detalle = 'Archivo: ' + escapeHtml(item.archivo_nombre || 'Sin nombre') +
                        '<br>Instructor(es): ' + escapeHtml(item.instructores_nombres || 'Sin datos') +
                        '<br>Subido por: ' + escapeHtml(item.usuario_nombre || 'Desconocido');
                    return '<tr>' +
                        '<td><span class="inst-badge is-planned">Carga masiva</span></td>' +
                        '<td>' + escapeHtml(formatDateTime(item.fecha)) + '</td>' +
                        '<td>' + escapeHtml(item.curso_nombre || '—') + '</td>' +
                        '<td style="font-size:11.5px;">' + detalle + '</td>' +
                        '<td>' + Number(item.total_filas || 0) + '</td>' +
                        '<td><button class="inst-btn inst-btn-outline inst-btn-sm" type="button" data-retirar-carga="' + Number(item.carga_id) + '">Retirar carga</button></td>' +
                        '</tr>';
                }
                var quien = [item.cargo, item.seccion, item.ingenio_referencia].filter(Boolean).map(escapeHtml).join(' · ') || 'Sin datos de contacto';
                return '<tr>' +
                    '<td><span class="inst-badge is-active">Individual</span></td>' +
                    '<td>' + escapeHtml(formatDateTime(item.fecha)) + '</td>' +
                    '<td>' + escapeHtml(item.curso_nombre || '—') + '</td>' +
                    '<td style="font-size:11.5px;">Instructor: ' + escapeHtml(item.instructor_nombre || '—') + '<br>Enviado por: ' + quien + '</td>' +
                    '<td>1</td>' +
                    '<td><button class="inst-btn inst-btn-outline inst-btn-sm" type="button" data-retirar-evaluacion="' + Number(item.evaluacion_id) + '">Retirar evaluación</button></td>' +
                    '</tr>';
            }).join('') + '</tbody></table></div>';
    }
    function retirarCargaEvaluacion(payload, boton) {
        if (boton) boton.disabled = true;
        var datosFormulario = new FormData();
        Object.keys(payload).forEach(function (clave) { datosFormulario.append(clave, payload[clave]); });
        fetch('eliminar_carga_evaluacion_instructor.php', { method: 'POST', body: datosFormulario })
            .then(function (respuesta) { return respuesta.json(); })
            .then(function (resultado) {
                if (resultado && resultado.ok) {
                    // Recargar la pagina refresca tanto el historial como las
                    // estadisticas agregadas del/los instructor(es) afectado(s), igual
                    // que ya hace la carga masiva tras terminar de procesar un archivo.
                    window.location.reload();
                    return;
                }
                alert((resultado && resultado.mensaje) ? resultado.mensaje : 'No fue posible retirar el registro seleccionado.');
                if (boton) boton.disabled = false;
            })
            .catch(function () {
                alert('No fue posible retirar el registro seleccionado. Verifica tu conexión e inténtalo de nuevo.');
                if (boton) boton.disabled = false;
            });
    }
    function populateLoadEditionSelect() {
        var select = document.getElementById('cargaEdicion');
        if (!select) return;
        // "ediciones" puede tener varias filas para el mismo curso_id cuando el curso
        // tiene co-instructores a nivel de modulo (una fila por instructor, ver
        // instructorEditions()/filteredEditions()); este selector es por CURSO, asi que
        // se deduplica por curso_id para no repetir la misma edicion varias veces.
        var seen = {};
        var uniqueCourses = ediciones.filter(function (course) {
            var id = Number(course.curso_id);
            if (seen[id]) return false;
            seen[id] = true;
            return true;
        });
        if (!uniqueCourses.length) {
            select.innerHTML = '<option value="">No hay ediciones registradas</option>';
            return;
        }
        select.innerHTML = uniqueCourses.map(function (course) {
            return '<option value="' + Number(course.curso_id) + '">' + escapeHtml((course.anio || 'Sin año') + ' · ' + course.nombre_cursos + ' · ' + course.ingenio) + '</option>';
        }).join('');
    }

    ['instructoresBuscar', 'instructoresAnio', 'instructoresCurso', 'instructoresOrden'].forEach(function (id) {
        var element = document.getElementById(id);
        if (element) element.addEventListener(id === 'instructoresBuscar' ? 'input' : 'change', renderAll);
    });
    document.querySelectorAll('[data-inst-view]').forEach(function (button) {
        button.addEventListener('click', function () {
            instView = button.getAttribute('data-inst-view');
            document.querySelectorAll('[data-inst-view]').forEach(function (item) { item.classList.toggle('is-active', item === button); });
            renderInstructors();
        });
    });
    document.querySelectorAll('[data-inst-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            activeTab = button.getAttribute('data-inst-tab');
            document.querySelectorAll('[data-inst-tab]').forEach(function (item) { item.classList.toggle('is-active', item === button); });
            document.querySelectorAll('[data-inst-panel]').forEach(function (panel) { panel.hidden = panel.getAttribute('data-inst-panel') !== activeTab; });
            document.getElementById('instViewToggle').style.display = activeTab === 'directorio' ? 'flex' : 'none';
            if (activeTab === 'informe') renderReport();
        });
    });
    document.addEventListener('click', function (event) {
        var copyEvalButton = event.target.closest('[data-copy-eval-token]');
        if (copyEvalButton) { copyEvaluationLink(copyEvalButton.getAttribute('data-copy-eval-token'), copyEvalButton); return; }
        var previewButton = event.target.closest('[data-cv-preview]');
        if (previewButton) {
            var preview = document.getElementById(previewButton.getAttribute('data-cv-preview'));
            if (preview) preview.classList.toggle('is-open');
            return;
        }
        var detailButton = event.target.closest('[data-detail-instructor]');
        if (detailButton) { openInstructorDetail(detailButton.getAttribute('data-detail-instructor'), detailButton.getAttribute('data-detail-target')); return; }
        var editButton = event.target.closest('[data-edit-instructor]');
        if (editButton) { editInstructor(editButton.getAttribute('data-edit-instructor')); return; }
        var retirarCargaBoton = event.target.closest('[data-retirar-carga]');
        if (retirarCargaBoton) {
            if (window.confirm('¿Retirar esta carga masiva completa? Se eliminarán todas sus evaluaciones y no se puede deshacer.')) {
                retirarCargaEvaluacion({ carga_id: retirarCargaBoton.getAttribute('data-retirar-carga') }, retirarCargaBoton);
            }
            return;
        }
        var retirarEvaluacionBoton = event.target.closest('[data-retirar-evaluacion]');
        if (retirarEvaluacionBoton) {
            if (window.confirm('¿Retirar esta evaluación individual? No se puede deshacer.')) {
                retirarCargaEvaluacion({ evaluacion_id: retirarEvaluacionBoton.getAttribute('data-retirar-evaluacion') }, retirarEvaluacionBoton);
            }
            return;
        }
        var openButton = event.target.closest('[data-inst-open]');
        if (openButton) {
            var modalObjetivo = openButton.getAttribute('data-inst-open');
            if (modalObjetivo === 'modalHistorialCargas') renderHistorialCargas();
            openModal(modalObjetivo);
            return;
        }
        var closeButton = event.target.closest('[data-inst-close]');
        if (closeButton) closeModal(closeButton.closest('.cengi-inst-modal'));
    });
    document.querySelectorAll('.cengi-inst-modal').forEach(function (modal) {
        modal.addEventListener('mousedown', function (event) { if (event.target === modal) closeModal(modal); });
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            var open = document.querySelector('.cengi-inst-modal.is-open');
            if (open) closeModal(open);
        }
    });
    document.querySelectorAll('[data-detail-tab]').forEach(function (button) {
        button.addEventListener('click', function () { switchDetailTab(button.getAttribute('data-detail-tab')); });
    });
    var newButton = document.getElementById('nuevoInstructorBtn');
    if (newButton) newButton.addEventListener('click', newInstructor);
    var cvInput = document.getElementById('instCv');
    if (cvInput) cvInput.addEventListener('change', function () {
        document.getElementById('instCvText').textContent = cvInput.files && cvInput.files[0] ? 'Archivo seleccionado: ' + cvInput.files[0].name : 'Arrastra el CV o haz clic para seleccionar';
    });
    var csvInput = document.getElementById('evaluacionesCsv');
    var csvPicker = document.getElementById('seleccionarEvaluacionesCsv');
    var cargaAviso = document.getElementById('cargaEvaluacionesAviso');
    var cargaAvisoTexto = document.getElementById('cargaEvaluacionesAvisoTexto');
    function mostrarAvisoCarga(texto, esError) {
        if (!cargaAviso || !cargaAvisoTexto) return;
        cargaAvisoTexto.textContent = texto;
        cargaAviso.classList.toggle('is-error', !!esError);
        cargaAviso.style.display = 'flex';
    }
    if (csvPicker && csvInput) csvPicker.addEventListener('click', function () { csvInput.click(); });
    if (csvInput) csvInput.addEventListener('change', function () {
        if (csvInput.files && csvInput.files[0]) {
            document.getElementById('evaluacionesCsvNombre').textContent = 'Archivo seleccionado: ' + csvInput.files[0].name;
            mostrarAvisoCarga('El archivo qued\u00F3 seleccionado.', false);
        }
    });
    var templateButton = document.getElementById('descargarPlantillaCsv');
    if (templateButton) templateButton.addEventListener('click', function () {
        if (typeof XLSX === 'undefined') return;
        var columnas = ['Instructor', 'Ingenio', 'Cargo', 'Seccion', 'InstructorLenguajeClaro', 'InstructorMaterialAdecuado', 'InstructorConocimientoTema', 'InstructorRespetoParticipantes', 'InstructorPuntualidadObjetivos', 'RecomendariaInstructor', 'TemaRelevanciaUtilidad', 'LogisticaEvento', 'RecomendariaContexto', 'CapacitacionesNecesarias', 'AreasMejora', 'InstructorManejoPlataforma', 'CalidadInstalaciones', 'Modulo'];
        var anchoColumnas = [24, 18, 16, 14, 12, 12, 12, 12, 12, 12, 12, 12, 12, 32, 32, 14, 14, 20];
        var estiloEncabezado = {
            font: { bold: true, color: { rgb: 'FFFFFFFF' } },
            fill: { patternType: 'solid', fgColor: { rgb: 'FF3E7A12' } },
            alignment: { horizontal: 'center', vertical: 'center', wrapText: true }
        };
        var worksheet = XLSX.utils.aoa_to_sheet([columnas]);
        columnas.forEach(function (_, indice) {
            var direccionCelda = XLSX.utils.encode_cell({ r: 0, c: indice });
            if (worksheet[direccionCelda]) worksheet[direccionCelda].s = estiloEncabezado;
        });
        worksheet['!cols'] = anchoColumnas.map(function (ancho) { return { wch: ancho }; });
        worksheet['!rows'] = [{ hpt: 24 }];
        var libro = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(libro, worksheet, 'Evaluaciones');
        XLSX.writeFile(libro, 'plantilla_evaluaciones_instructores.xlsx');
    });
    var cargaForm = document.getElementById('formCargaEvaluaciones');
    if (cargaForm) cargaForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var archivo = csvInput && csvInput.files ? csvInput.files[0] : null;
        if (!archivo) {
            mostrarAvisoCarga('Selecciona un archivo Excel (.xlsx) antes de procesar la carga.', true);
            return;
        }
        var botonEnviar = cargaForm.querySelector('button[type="submit"]');
        if (botonEnviar) botonEnviar.disabled = true;
        mostrarAvisoCarga('Procesando el archivo...', false);
        archivo.arrayBuffer().then(function (buffer) {
            var libro = XLSX.read(buffer, { type: 'array' });
            var nombreHoja = libro.SheetNames[0];
            if (!nombreHoja) throw new Error('el archivo Excel no tiene hojas.');
            var hoja = libro.Sheets[nombreHoja];
            var textoCsv = XLSX.utils.sheet_to_csv(hoja);
            if (!textoCsv || !textoCsv.trim()) throw new Error('la hoja seleccionada est\u00E1 vac\u00EDa.');
            var archivoCsv = new File([new Blob([textoCsv], { type: 'text/csv' })], 'evaluaciones.csv', { type: 'text/csv' });
            var datosFormulario = new FormData();
            var edicionSelect = document.getElementById('cargaEdicion');
            datosFormulario.append('curso_id', edicionSelect ? edicionSelect.value : '');
            datosFormulario.append('archivo', archivoCsv);
            return fetch('carga_evaluaciones_instructor.php', { method: 'POST', body: datosFormulario });
        }).then(function (respuesta) {
            if (!respuesta) return;
            window.location.href = respuesta.url;
        }).catch(function (error) {
            mostrarAvisoCarga('No se pudo procesar el archivo Excel: ' + (error && error.message ? error.message : 'formato inv\u00E1lido.') + ' Verifica que sea un .xlsx v\u00E1lido e int\u00E9ntalo de nuevo.', true);
            if (botonEnviar) botonEnviar.disabled = false;
        });
    });
    // El informe PDF se genera en el servidor con Dompdf (informe_instructor_pdf.php)
    // en vez de depender de window.print()/@media print: se abre el endpoint con el
    // id del instructor actualmente mostrado en la ficha y el navegador descarga el
    // PDF resultante directamente.
    var printButton = document.getElementById('imprimirInformeInstructor');
    if (printButton) printButton.addEventListener('click', function () {
        var modal = document.getElementById('modalInstructorDetalle');
        var id = modal ? modal.dataset.instructorId : '';
        if (id) window.open('informe_instructor_pdf.php?id=' + encodeURIComponent(id), '_blank');
    });

    // Auto-oculta el banner de aviso (.cengi-feedback, exito o error de la carga de
    // evaluaciones/creacion-edicion de instructor) despues de unos segundos, para que
    // no quede fijo en pantalla indefinidamente.
    var feedbackBanner = document.querySelector('.cengi-feedback');
    if (feedbackBanner) {
        setTimeout(function () {
            feedbackBanner.style.transition = 'opacity 0.4s ease';
            feedbackBanner.style.opacity = '0';
            setTimeout(function () { feedbackBanner.style.display = 'none'; }, 400);
        }, 3500);
    }

    populateLoadEditionSelect();
    renderInstructors();
})();
</script>
</body>
</html>
