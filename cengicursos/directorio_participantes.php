<?php
require_once __DIR__ . '/inscripcion_registro.php'; // incluye planificacion_helpers.php
require_once __DIR__ . '/menu.php';

cengi_require_ver_participantes();
$db = conectar();
$puedeEliminar = cengi_puede_eliminar_participantes();
$puedeEditar = cengi_puede_editar_participantes();
// Mismos permisos que exigen las páginas a las que se enlaza desde la ficha.
$puedeAsignar = cengi_es_admin();          // asignar_participante_existente.php
$puedeVerCurso = cengi_puede_calificar();  // ver_participante_curso.php
$veTodo = cengi_ve_todo_por_rol_o_ingenio();
$ingenioPropio = cengi_ingenio_id_actual();

const CENGI_DIR_POR_PAGINA = 50;
const CENGI_DIR_SITUACIONES = [
    'cursando' => 'Con cursos activos',
    'sin_reciente' => 'Sin capacitación en 12 meses',
    'sin_cursos' => 'Sin cursos registrados',
    'con_diploma' => 'Con diploma',
    'incompletos' => 'Sin correo o teléfono',
    'inactivos' => 'Desactivados',
];
const CENGI_DIR_ORDENES = [
    'nombre' => ['Nombre (A–Z)', 'p.nombre_participantes'],
    'reciente' => ['Capacitación más reciente', 't.ultima_capacitacion IS NULL, t.ultima_capacitacion DESC, p.nombre_participantes'],
    'cursos' => ['Más cursos', 'COALESCE(t.total_cursos, 0) DESC, p.nombre_participantes'],
    'evaluacion' => ['Mejor evaluación', 't.evaluacion_promedio IS NULL, t.evaluacion_promedio DESC, p.nombre_participantes'],
];

function cengi_dir_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cengi_dir_iniciales($nombre)
{
    $partes = preg_split('/\s+/', trim((string) $nombre));
    $iniciales = '';
    foreach (array_slice(array_filter($partes), 0, 2) as $parte) {
        $iniciales .= mb_strtoupper(mb_substr($parte, 0, 1, 'UTF-8'), 'UTF-8');
    }
    return $iniciales ?: 'P';
}

function cengi_dir_fecha($valor)
{
    $valor = trim((string) ($valor ?? ''));
    if ($valor === '' || $valor === '0000-00-00') {
        return '—';
    }
    $fecha = strtotime($valor);
    return $fecha ? date('d/m/Y', $fecha) : '—';
}

/** Meses completos transcurridos desde una fecha (null si no hay fecha o es futura). */
function cengi_dir_meses_desde($valor)
{
    if (!cengi_pp_es_fecha(substr((string) $valor, 0, 10))) {
        return null;
    }
    $desde = new DateTime(substr((string) $valor, 0, 10));
    $hoy = new DateTime(cengi_pp_hoy());
    if ($desde > $hoy) {
        return null;
    }
    $d = $desde->diff($hoy);
    return $d->y * 12 + $d->m;
}

/** Tono de la evaluación promedio (escala de 0 a 100 puntos). */
function cengi_dir_tono_nota($nota)
{
    if ($nota === null) {
        return 'is-gris';
    }
    return $nota >= 80 ? 'is-verde' : ($nota >= 60 ? 'is-amarillo' : 'is-naranja');
}

/** Alcance por ingenio para usuarios que no ven todo. */
function cengi_dir_scope(array &$condiciones, array &$params, $veTodo, $ingenioPropio)
{
    if (!$veTodo) {
        $condiciones[] = 'p.ingenio_id = ?';
        $params[] = $ingenioPropio;
    }
}

// Resumen por persona: primero se agrupa por asignación (control_cursos y diplomas pueden
// tener varias filas) y luego por participante, así no se duplican conteos.
const CENGI_DIR_SQL_RESUMEN = "SELECT x.participantes_id, COUNT(*) AS total_cursos,
        SUM(x.fin IS NOT NULL AND x.fin < CURDATE()) AS cursos_completados,
        SUM(x.fin IS NULL OR x.fin >= CURDATE()) AS cursos_activos,
        AVG(x.post) AS evaluacion_promedio,
        SUM(x.con_diploma) AS diplomas,
        MAX(x.inicio) AS ultima_capacitacion,
        SUBSTRING_INDEX(GROUP_CONCAT(x.nombre_cursos ORDER BY x.inicio DESC SEPARATOR '||'), '||', 1) AS ultimo_curso
    FROM (
        SELECT a.id, a.participantes_id, c.inicio, c.fin, c.nombre_cursos,
            MAX(CASE WHEN cc.posevaluacion REGEXP '^[0-9]+(\\\\.[0-9]+)?$' THEN CAST(cc.posevaluacion AS DECIMAL(6,2)) END) AS post,
            MAX(CASE WHEN COALESCE(NULLIF(d.pdf_path, ''), NULLIF(cc.diploma, '')) IS NOT NULL THEN 1 ELSE 0 END) AS con_diploma
        FROM asignaciones a
        INNER JOIN cursos c ON c.id = a.cursos_id
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        LEFT JOIN diplomas d ON d.tipo = 'curso' AND d.asignacion_id = a.id
        WHERE a.estado_asignaciones = 1
        GROUP BY a.id, a.participantes_id, c.inicio, c.fin, c.nombre_cursos
    ) x
    GROUP BY x.participantes_id";

/** Devuelve el participante si existe y está dentro del alcance del usuario. */
function cengi_dir_participante(PDO $db, $id, $veTodo, $ingenioPropio)
{
    $condiciones = ['p.id = ?'];
    $params = [(int) $id];
    cengi_dir_scope($condiciones, $params, $veTodo, $ingenioPropio);
    $stmt = $db->prepare('SELECT p.id, p.ingenio_id, p.nombre_participantes, p.cui_participantes, p.cui_anterior,
            p.puesto_participantes, p.area_participantes, p.correo_participantes,
            p.grado_academico_participantes, p.telefono_participantes,
            p.estado_participantes, p.creado, p.actualizado, i.nombre_ingenios
        FROM participantes p
        INNER JOIN ingenios i ON i.id = p.ingenio_id
        WHERE ' . implode(' AND ', $condiciones) . ' LIMIT 1');
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** URL de regreso al listado conservando solo los filtros conocidos. */
function cengi_dir_url_lista($consulta, array $extra = [])
{
    parse_str((string) $consulta, $q);
    $q = array_intersect_key(is_array($q) ? $q : [], array_flip(['q', 'ingenio_id', 'cantidad', 'situacion', 'orden', 'p']));
    $q = array_filter(array_merge($q, $extra), static function ($v, $k) {
        return $v !== '' && $v !== null && !($k === 'ingenio_id' && (int) $v === 0) && !($k === 'p' && (int) $v <= 1);
    }, ARRAY_FILTER_USE_BOTH);
    return 'directorio_participantes.php' . ($q ? '?' . http_build_query($q) : '');
}

/** Planificación (convocatoria) de la que salió el curso, o null si el curso se creó directo. */
function cengi_dir_plan_de_curso(PDO $db, $cursoId)
{
    $stmt = $db->prepare("SELECT id FROM planificacion_cursos WHERE curso_id = ? AND estado <> 'cancelado' ORDER BY id DESC LIMIT 1");
    $stmt->execute([(int) $cursoId]);
    $planId = (int) $stmt->fetchColumn();
    return $planId ? cengi_pp_plan($db, $planId) : null;
}

/**
 * Inscribe a un participante del directorio en un curso. Si el curso viene de una
 * planificación se crea la solicitud de inscripción con su cuota y quién paga (igual que el
 * formulario público), así aparece en Inscripciones y en Pagos de inscripción para el
 * seguimiento del cobro; luego se asigna al curso o queda esperando la validación del pago.
 * Sirve también para registrar el cobro de una asignación hecha antes sin solicitud.
 * Devuelve [mensaje, tipo, solicitudId|null].
 */
function cengi_dir_inscribir(PDO $db, array $persona, $cursoId, $cuota, $tipoPago, $asignar, $avisar)
{
    $stmt = $db->prepare('SELECT id, nombre_cursos FROM cursos WHERE id = ?');
    $stmt->execute([(int) $cursoId]);
    $curso = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$curso) {
        return ['Selecciona un curso válido.', 'error', null];
    }
    $plan = cengi_dir_plan_de_curso($db, $curso['id']);

    if (!$plan) {
        $db->beginTransaction();
        try {
            $id = cengi_ins_registrar_persona($db, $persona['id'], $cursoId, $tipoPago, $cuota);
            $db->commit();
            return ['Inscripción registrada por curso. Configura la cuota y valida al responsable del pago antes de asignar.', 'success', $id];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['No fue posible registrar la inscripción.', 'error', null];
        }
    }
    $stmt = $db->prepare("SELECT id_solicitud FROM solicitudes_inscripcion WHERE planificacion_id = ? AND estado <> 'Rechazado'
        AND (cui_participante = ? OR (correo <> '' AND LOWER(correo) = LOWER(?))) LIMIT 1");
    $stmt->execute([(int) $plan['id'], $persona['cui_participantes'], (string) $persona['correo_participantes']]);
    if ($stmt->fetchColumn()) {
        return [$persona['nombre_participantes'] . ' ya tiene una inscripción en ' . $plan['nombre'] . '. Su pago se gestiona en Pagos de inscripción.', 'error', null];
    }

    $ingenioDefecto = cengi_curso_ingenio_por_defecto($db);
    $deIngenio = (int) $persona['ingenio_id'] > 0 && (int) $persona['ingenio_id'] !== $ingenioDefecto;
    $cuota = array_key_exists($cuota, CENGI_INS_CUOTAS) ? $cuota : ($deIngenio ? 'asociado' : 'externo');
    [$monto, $moneda] = cengi_ins_monto($plan, $cuota);
    $descuento = null;
    if ($monto <= 0) {
        $tipoPago = 'Exonerado';
    } elseif ($tipoPago === 'Exonerado') {
        // Beca / invitado: no paga; la cuota que se le exonera queda como descuento.
        $descuento = $monto;
        $monto = 0;
    } elseif ($tipoPago === 'Ingenio' && !$deIngenio) {
        return ['El pago por ingenio solo aplica a participantes de un ingenio asociado. Elige pago propio o sin costo.', 'error', null];
    } elseif (!in_array($tipoPago, ['Ingenio', 'Propio'], true)) {
        return ['Indica quién paga la inscripción.', 'error', null];
    }

    $db->beginTransaction();
    try {
        $grupoId = $tipoPago === 'Ingenio' ? cengi_ins_grupo_abierto($db, $plan['id'], (int) $persona['ingenio_id']) : null;
        $db->prepare("INSERT INTO solicitudes_inscripcion (nombre_participante, cui_participante, puesto_participante, area_participante, grado_academico,
                correo, telefono, ingenio_id, institucion_otra, curso_id, planificacion_id, tipo_pago, como_se_entero, cuota, monto, descuento, moneda,
                pago_estado, validacion_ingenio, grupo_pago_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$persona['nombre_participantes'], $persona['cui_participantes'], (string) $persona['puesto_participantes'], (string) $persona['area_participantes'],
                (string) $persona['grado_academico_participantes'], (string) $persona['correo_participantes'], (string) $persona['telefono_participantes'],
                (int) $persona['ingenio_id'] ?: null, $plan['curso_id'] ?: null, (int) $plan['id'], $tipoPago, 'Registrado desde el directorio', $cuota, $monto, $descuento, $moneda,
                $tipoPago === 'Exonerado' ? 'exonerado' : ($tipoPago === 'Propio' ? 'pendiente' : null), $tipoPago === 'Ingenio' ? 'pendiente' : null, $grupoId]);
        $solicitudId = (int) $db->lastInsertId();
        $db->prepare("UPDATE solicitudes_inscripcion SET origen_inscripcion='registro_interno' WHERE id_solicitud=?")->execute([$solicitudId]);
        cengi_ins_recalcular_grupo($db, $grupoId);
        if ($tipoPago === 'Ingenio') {
            require_once __DIR__ . '/proformas_helpers.php';
            cengi_pf_auto_amarrar($db, $solicitudId, $plan['id'], (int) $persona['ingenio_id']);
        }
        $asignado = false;
        if ($asignar && !empty($plan['curso_id']) && cengi_ins_admitida(cengi_ins_solicitud($db, $solicitudId))) {
            cengi_ins_asignar($db, cengi_ins_solicitud($db, $solicitudId, true), cengi_pp_usuario_nombre() . ' (directorio)');
            $asignado = true;
        } else {
            // Sin asignar ahora: entra al curso cuando se valide (o de inmediato si es sin costo y hay cupo).
            cengi_ins_procesar_plan($db, $plan['id']);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Directorio: no se pudo inscribir al participante ' . $persona['id'] . ' en el plan ' . $plan['id'] . ': ' . $e->getMessage());
        return ['No fue posible registrar la inscripción. Intenta nuevamente.', 'error', null];
    }
    try {
        cengi_ins_enviar_cola($db);
    } catch (Throwable $e) {
        error_log('Directorio: cola de correos de inscripción: ' . $e->getMessage());
    }

    $s = cengi_ins_solicitud($db, $solicitudId);
    $asignado = $asignado || ($s && $s['estado'] === 'Aprobado');
    $dinero = cengi_ins_dinero($tipoPago === 'Exonerado' ? (float) $descuento : $monto, $moneda);
    $textoPago = [
        'Propio' => 'Pago propio de ' . $dinero . ' pendiente: queda en Pagos de inscripción para registrar el comprobante y darle seguimiento.',
        'Ingenio' => 'Cuota de ' . $dinero . ' agregada a la orden de pago del ingenio; el ingenio debe validar la inscripción en Pagos de inscripción.',
        'Exonerado' => $descuento ? 'Sin costo (se exoneró la cuota de ' . $dinero . ').' : 'El curso no tiene costo para esta cuota.',
    ][$tipoPago];
    $mensaje = 'Inscripción registrada en ' . $plan['nombre'] . '. ' . ($asignado ? 'Ya está asignado al curso. ' : 'Se asignará al curso cuando se valide. ') . $textoPago;

    if ($avisar && $tipoPago === 'Propio' && $s && filter_var($s['correo'], FILTER_VALIDATE_EMAIL)) {
        try {
            require_once __DIR__ . '/cobranza_helpers.php';
            $s['fecha_inicio'] = $plan['fecha_inicio'];
            $mensaje .= cengi_cob_recordar_participante($db, $s) ? ' Se le envió el correo con el monto y el enlace para subir su comprobante.' : ' No se pudo enviar el correo (revisa Comunicaciones).';
        } catch (Throwable $e) {
            error_log('Directorio: aviso de pago: ' . $e->getMessage());
            $mensaje .= ' No se pudo enviar el correo de aviso.';
        }
    }
    return [$mensaje, 'success', $solicitudId];
}

// ---------------------------------------------------------------- acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $volver = (string) ($_POST['volver'] ?? '');
    $persona = $id > 0 ? cengi_dir_participante($db, $id, $veTodo, $ingenioPropio) : null;

    if ($accion === 'inscribir') {
        if (!$puedeAsignar || !$persona || (int) $persona['estado_participantes'] !== 1) {
            header('Location: ' . cengi_dir_url_lista($volver, ['msg' => 'error']));
            exit;
        }
        [$texto, $tipo] = cengi_dir_inscribir($db, $persona, (int) ($_POST['curso_id'] ?? 0), (string) ($_POST['cuota'] ?? ''),
            (string) ($_POST['tipo_pago'] ?? ''), !empty($_POST['asignar']), !empty($_POST['avisar']));
        $_SESSION['cengi_dir_aviso'] = [$texto, $tipo];
        header('Location: ' . cengi_dir_url_lista($volver, ['msg' => 'aviso', 'ver' => $id]));
        exit;
    }

    if (!$puedeEditar || !$persona || !in_array($accion, ['editar', 'reactivar'], true)) {
        header('Location: ' . cengi_dir_url_lista($volver, ['msg' => 'error']));
        exit;
    }

    if ($accion === 'reactivar') {
        $db->prepare('UPDATE participantes SET estado_participantes = 1, actualizado = NOW() WHERE id = ?')->execute([$id]);
        header('Location: ' . cengi_dir_url_lista($volver, ['msg' => 'reactivado', 'ver' => $id]));
        exit;
    }

    $datos = [];
    foreach (['nombre', 'cui', 'puesto', 'area', 'correo', 'grado', 'telefono'] as $campo) {
        $datos[$campo] = trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$campo] ?? '')));
    }
    $ingenioId = $veTodo ? (int) ($_POST['ingenio_id'] ?? 0) : $ingenioPropio;
    $error = '';
    $cuiHistorial = null;
    if ($datos['nombre'] === '' || $datos['puesto'] === '' || $datos['area'] === '' || $ingenioId <= 0) {
        $error = 'requeridos';
    } elseif ($datos['correo'] !== '' && !filter_var($datos['correo'], FILTER_VALIDATE_EMAIL)) {
        $error = 'correo';
    } else {
        [$datos['cui'], $cuiHistorial] = cengi_cui_para_guardar($db, $id, $datos['cui']);
        $stmt = $db->prepare('SELECT COUNT(*) FROM participantes WHERE cui_participantes = ? AND id <> ?');
        $stmt->execute([$datos['cui'], $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            $error = 'cui';
        }
    }
    if ($error === '') {
        try {
            $db->prepare('UPDATE participantes SET ingenio_id = ?, nombre_participantes = ?, cui_participantes = ?, cui_anterior = COALESCE(?, cui_anterior),
                    puesto_participantes = ?, area_participantes = ?, correo_participantes = ?,
                    grado_academico_participantes = ?, telefono_participantes = ?, actualizado = NOW()
                WHERE id = ?')
                ->execute([$ingenioId, $datos['nombre'], $datos['cui'], $cuiHistorial, $datos['puesto'], $datos['area'], $datos['correo'], $datos['grado'], $datos['telefono'], $id]);
        } catch (PDOException $e) {
            error_log('Directorio: error al actualizar participante ' . $id . ': ' . $e->getMessage());
            $error = $e->getCode() === '23000' ? 'cui' : 'guardar';
        }
    }
    header('Location: ' . cengi_dir_url_lista($volver, $error === '' ? ['msg' => 'editado', 'ver' => $id] : ['msg' => 'error_' . $error, 'ver' => $id]));
    exit;
}

// ---------------------------------------------------------------- ficha (JSON)
// La ficha se obtiene al abrir el modal para no cargar todos los historiales en la tabla.
$fichaId = (int) ($_GET['ficha_id'] ?? 0);
if ($fichaId > 0) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $ficha = cengi_dir_participante($db, $fichaId, $veTodo, $ingenioPropio);
    if (!$ficha) {
        http_response_code(404);
        echo json_encode(['error' => 'El participante no existe o no está disponible.']);
        exit;
    }

    $stmtHistorial = $db->prepare("SELECT a.id AS asignacion_id, c.id AS curso_id, c.nombre_cursos,
            ca.descripcion_categorias_cursos AS categoria, c.tipo AS modalidad,
            c.inicio, c.fin, a.estado_asignaciones, cc.asistencia, cc.evaluacion,
            cc.posevaluacion,
            COALESCE(NULLIF(d.pdf_path, ''), NULLIF(cc.diploma, '')) AS diploma,
            d.codigo_unico AS diploma_codigo, d.emitido_en AS diploma_fecha,
            CASE
                WHEN c.fin IS NOT NULL AND c.fin < CURDATE() THEN 'finalizado'
                WHEN c.inicio IS NOT NULL AND c.inicio > CURDATE() THEN 'por_iniciar'
                ELSE 'activo'
            END AS estado_curso
        FROM asignaciones a
        INNER JOIN cursos c ON c.id = a.cursos_id
        INNER JOIN categorias_cursos ca ON ca.id = c.categoria_curso_id
        LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
        LEFT JOIN (
            SELECT asignacion_id, MAX(pdf_path) AS pdf_path,
                   MAX(codigo_unico) AS codigo_unico, MAX(emitido_en) AS emitido_en
            FROM diplomas WHERE tipo = 'curso' GROUP BY asignacion_id
        ) d ON d.asignacion_id = a.id
        WHERE a.participantes_id = ? AND a.estado_asignaciones = 1
        ORDER BY COALESCE(c.inicio, c.creado) DESC, c.nombre_cursos");
    $stmtHistorial->execute([$fichaId]);
    $cursosFicha = $stmtHistorial->fetchAll(PDO::FETCH_ASSOC);
    $resumen = ['total' => count($cursosFicha), 'completados' => 0, 'activos' => 0, 'diplomas' => 0];
    foreach ($cursosFicha as &$cursoFicha) {
        // El path guardado en BD (diplomas.pdf_path o control_cursos.diploma) puede venir en
        // formatos legados ("../uploads/...", rutas absolutas); se normaliza antes de exponerlo.
        $cursoFicha['diploma'] = cengi_normalizar_url_archivo($cursoFicha['diploma']);
        if ($cursoFicha['estado_curso'] === 'finalizado') {
            $resumen['completados']++;
        } else {
            $resumen['activos']++;
        }
        if (!empty($cursoFicha['diploma'])) {
            $resumen['diplomas']++;
        }
        // Cobro de la inscripción: solo los cursos que vienen de una planificación tienen cuota.
        $planCurso = cengi_dir_plan_de_curso($db, $cursoFicha['curso_id']);
        $cursoFicha['cobro'] = null;
        if ($planCurso) {
            $stmtSol = $db->prepare("SELECT id_solicitud FROM solicitudes_inscripcion WHERE planificacion_id = ? AND estado <> 'Rechazado'
                AND (cui_participante = ? OR (correo <> '' AND LOWER(correo) = LOWER(?))) ORDER BY id_solicitud DESC LIMIT 1");
            $stmtSol->execute([(int) $planCurso['id'], $ficha['cui_participantes'], (string) $ficha['correo_participantes']]);
            $sol = ($solId = (int) $stmtSol->fetchColumn()) ? cengi_ins_solicitud($db, $solId) : null;
            if (!$sol) {
                $cuotasCurso = [];
                foreach (array_keys(CENGI_INS_CUOTAS) as $claveCuota) {
                    [$montoCuota, $monedaCuota] = cengi_ins_monto($planCurso, $claveCuota);
                    $cuotasCurso[$claveCuota] = ['monto' => $montoCuota, 'texto' => cengi_ins_dinero($montoCuota, $monedaCuota)];
                }
                $cursoFicha['cobro'] = ['estado' => 'sin_registro', 'texto' => 'Sin registro de pago', 'tono' => 'is-naranja', 'cuotas' => $cuotasCurso];
            } else {
                $estadoCobro = cengi_ins_estado_cobro($sol);
                $req = cengi_ins_requisito($sol);
                $texto = CENGI_INS_ESTADOS_PAGO[$estadoCobro][0] ?? ucfirst($estadoCobro);
                if ($sol['tipo_pago'] === 'Ingenio' && !$req['ok']) {
                    $texto = $req['texto'];
                }
                $cursoFicha['cobro'] = [
                    'estado' => $estadoCobro, 'texto' => $texto,
                    'tono' => $estadoCobro === 'pagado' ? 'is-verde' : ($estadoCobro === 'exonerado' || $estadoCobro === 'reembolsado' ? 'is-gris' : ($estadoCobro === 'rechazado' ? 'is-rojo' : 'is-amarillo')),
                    'quien' => ['Propio' => 'Pago propio', 'Ingenio' => 'Lo paga ' . ($sol['nombre_ingenios'] ?: 'el ingenio'), 'Exonerado' => 'Sin costo'][$sol['tipo_pago']] ?? $sol['tipo_pago'],
                    'monto' => $sol['tipo_pago'] === 'Exonerado' ? '' : cengi_ins_dinero($sol['monto'], $sol['moneda']),
                    'anio' => (int) $planCurso['anio'], 'plan_id' => (int) $planCurso['id'],
                ];
            }
        }
    }
    unset($cursoFicha);

    // Cursos vigentes en los que todavía no está, para asignarlo desde la ficha.
    $disponibles = [];
    if ($puedeAsignar && (int) $ficha['estado_participantes'] === 1) {
        $stmtDisp = $db->prepare("SELECT c.id, c.nombre_cursos, c.inicio, c.fin FROM cursos c
            WHERE (c.fin IS NULL OR c.fin >= CURDATE())
              AND c.id NOT IN (SELECT cursos_id FROM asignaciones WHERE participantes_id = ? AND estado_asignaciones = 1)
            ORDER BY c.inicio IS NULL, c.inicio, c.nombre_cursos LIMIT 200");
        $stmtDisp->execute([$fichaId]);
        $disponibles = $stmtDisp->fetchAll(PDO::FETCH_ASSOC);
        foreach ($disponibles as &$disp) {
            $planDisp = cengi_dir_plan_de_curso($db, $disp['id']);
            $disp['cuotas'] = null;
            if ($planDisp) {
                foreach (array_keys(CENGI_INS_CUOTAS) as $claveCuota) {
                    [$montoCuota, $monedaCuota] = cengi_ins_monto($planDisp, $claveCuota);
                    $disp['cuotas'][$claveCuota] = ['monto' => $montoCuota, 'texto' => cengi_ins_dinero($montoCuota, $monedaCuota)];
                }
            }
        }
        unset($disp);
    }
    $ingenioDefecto = cengi_curso_ingenio_por_defecto($db);
    $deIngenio = (int) $ficha['ingenio_id'] > 0 && (int) $ficha['ingenio_id'] !== $ingenioDefecto;

    echo json_encode([
        'participante' => $ficha, 'resumen' => $resumen, 'cursos' => $cursosFicha, 'disponibles' => $disponibles,
        'inscripcion' => ['de_ingenio' => $deIngenio, 'cuota_sugerida' => $deIngenio ? 'asociado' : 'externo'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------- listado
$busqueda = trim((string) ($_GET['q'] ?? ''));
$ingenioFiltro = $veTodo ? (int) ($_GET['ingenio_id'] ?? 0) : 0;
$cantidadFiltro = in_array($_GET['cantidad'] ?? '', ['0', '1', '2-4', '5+'], true) ? $_GET['cantidad'] : '';
$situacion = array_key_exists($_GET['situacion'] ?? '', CENGI_DIR_SITUACIONES) ? $_GET['situacion'] : '';
$orden = array_key_exists($_GET['orden'] ?? '', CENGI_DIR_ORDENES) ? $_GET['orden'] : 'nombre';
$pagina = max(1, (int) ($_GET['p'] ?? 1));

$ingenioNombre = '';
if ($ingenioFiltro > 0 || !$veTodo) {
    $stmtIng = $db->prepare('SELECT nombre_ingenios FROM ingenios WHERE id = ?');
    $stmtIng->execute([$ingenioFiltro > 0 ? $ingenioFiltro : $ingenioPropio]);
    $ingenioNombre = (string) $stmtIng->fetchColumn();
}

$condiciones = [$situacion === 'inactivos' ? 'p.estado_participantes = 0' : 'p.estado_participantes = 1'];
$params = [];
if ($busqueda !== '') {
    $condiciones[] = '(p.nombre_participantes LIKE ? OR p.cui_participantes LIKE ? OR p.cui_anterior LIKE ? OR p.correo_participantes LIKE ?
        OR p.telefono_participantes LIKE ? OR p.puesto_participantes LIKE ? OR p.area_participantes LIKE ? OR p.grado_academico_participantes LIKE ?)';
    array_push($params, ...array_fill(0, 8, '%' . $busqueda . '%'));
}
if ($ingenioFiltro > 0) {
    $condiciones[] = 'p.ingenio_id = ?';
    $params[] = $ingenioFiltro;
}
cengi_dir_scope($condiciones, $params, $veTodo, $ingenioPropio);
$condiciones[] = [
    '' => '1 = 1', '0' => 't.participantes_id IS NULL', '1' => 't.total_cursos = 1',
    '2-4' => 't.total_cursos BETWEEN 2 AND 4', '5+' => 't.total_cursos >= 5',
][$cantidadFiltro];
$condiciones[] = [
    '' => '1 = 1', 'inactivos' => '1 = 1',
    'cursando' => 't.cursos_activos > 0',
    'sin_reciente' => 't.ultima_capacitacion < DATE_SUB(CURDATE(), INTERVAL 12 MONTH)',
    'sin_cursos' => 't.participantes_id IS NULL',
    'con_diploma' => 't.diplomas > 0',
    'incompletos' => "(COALESCE(p.correo_participantes, '') = '' OR COALESCE(p.telefono_participantes, '') = '')",
][$situacion];
$desde = 'FROM participantes p
    INNER JOIN ingenios i ON i.id = p.ingenio_id
    LEFT JOIN (' . CENGI_DIR_SQL_RESUMEN . ') t ON t.participantes_id = p.id
    WHERE ' . implode(' AND ', $condiciones);
$columnas = 'SELECT p.id, p.nombre_participantes, p.cui_participantes, p.puesto_participantes, p.area_participantes,
        p.correo_participantes, p.grado_academico_participantes, p.telefono_participantes, p.estado_participantes, i.nombre_ingenios,
        COALESCE(t.total_cursos, 0) AS total_cursos, COALESCE(t.cursos_completados, 0) AS cursos_completados,
        COALESCE(t.cursos_activos, 0) AS cursos_activos, t.evaluacion_promedio, COALESCE(t.diplomas, 0) AS diplomas,
        t.ultima_capacitacion, t.ultimo_curso ';
$ordenSql = ' ORDER BY ' . CENGI_DIR_ORDENES[$orden][1];

if (in_array($_GET['export'] ?? '', ['excel', 'csv'], true)) {
    require_once __DIR__ . '/cobranza_helpers.php';
    $stmt = $db->prepare($columnas . $desde . $ordenSql);
    $stmt->execute($params);
    $filas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $filas[] = [$p['nombre_participantes'], $p['cui_participantes'], $p['nombre_ingenios'], $p['puesto_participantes'], $p['area_participantes'],
            $p['correo_participantes'], $p['telefono_participantes'], $p['grado_academico_participantes'], $p['total_cursos'], $p['cursos_completados'],
            $p['cursos_activos'], $p['evaluacion_promedio'], $p['diplomas'], $p['ultimo_curso'], $p['ultima_capacitacion'],
            (int) $p['estado_participantes'] === 1 ? 'Activo' : 'Desactivado'];
    }
    $filtrosTexto = array_filter([
        $ingenioNombre !== '' ? 'ingenio ' . $ingenioNombre : '', $busqueda !== '' ? 'búsqueda “' . $busqueda . '”' : '', CENGI_DIR_SITUACIONES[$situacion] ?? '',
        $cantidadFiltro !== '' ? ($cantidadFiltro === '0' ? 'sin cursos' : $cantidadFiltro . ' curso(s)') : '',
    ]);
    cengi_cob_excel('Directorio de participantes', count($filas) . ' persona(s)' . ($filtrosTexto ? ' · ' . implode(' · ', $filtrosTexto) : ''), [
        ['Participante', 30], ['CUI', 16], ['Ingenio', 20], ['Puesto', 22], ['Área', 18], ['Correo electrónico', 28], ['Teléfono', 14],
        ['Grado académico', 18], ['Cursos', 9, 'entero'], ['Completados', 12, 'entero'], ['Activos', 9, 'entero'],
        ['Evaluación promedio', 12, 'decimal'], ['Diplomas', 10, 'entero'], ['Último curso', 30], ['Última capacitación', 14, 'fecha'], ['Estado', 12],
    ], $filas, 'directorio_participantes_' . date('Y-m-d'));
}

$stmtTotal = $db->prepare('SELECT COUNT(*) ' . $desde);
$stmtTotal->execute($params);
$totalLista = (int) $stmtTotal->fetchColumn();
$paginas = max(1, (int) ceil($totalLista / CENGI_DIR_POR_PAGINA));
$pagina = min($pagina, $paginas);
$stmt = $db->prepare($columnas . $desde . $ordenSql . ' LIMIT ' . CENGI_DIR_POR_PAGINA . ' OFFSET ' . (($pagina - 1) * CENGI_DIR_POR_PAGINA));
$stmt->execute($params);
$participantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Indicadores: personas activas del alcance del usuario y, si se eligió, del ingenio filtrado
// (los demás filtros no los cambian, para que sirvan de referencia).
$condKpi = ['p.estado_participantes = 1'];
$paramsKpi = [];
cengi_dir_scope($condKpi, $paramsKpi, $veTodo, $ingenioPropio);
if ($ingenioFiltro > 0) {
    $condKpi[] = 'p.ingenio_id = ?';
    $paramsKpi[] = $ingenioFiltro;
}
$stmtKpi = $db->prepare("SELECT COUNT(*) AS personas,
        COALESCE(SUM(t.cursos_activos > 0), 0) AS cursando,
        COALESCE(SUM(t.diplomas), 0) AS diplomas, COALESCE(SUM(t.diplomas > 0), 0) AS con_diploma,
        COALESCE(AVG(COALESCE(t.total_cursos, 0)), 0) AS promedio_cursos,
        COALESCE(SUM(t.ultima_capacitacion < DATE_SUB(CURDATE(), INTERVAL 12 MONTH)), 0) AS sin_reciente,
        COALESCE(SUM(t.participantes_id IS NULL), 0) AS sin_cursos,
        COALESCE(SUM(COALESCE(p.correo_participantes, '') = '' OR COALESCE(p.telefono_participantes, '') = ''), 0) AS incompletos,
        COALESCE(SUM(COALESCE(p.correo_participantes, '') = ''), 0) AS sin_correo
    FROM participantes p
    LEFT JOIN (" . CENGI_DIR_SQL_RESUMEN . ") t ON t.participantes_id = p.id
    WHERE " . implode(' AND ', $condKpi));
$stmtKpi->execute($paramsKpi);
$kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);
$condInac = ['p.estado_participantes = 0'];
$paramsInac = [];
cengi_dir_scope($condInac, $paramsInac, $veTodo, $ingenioPropio);
if ($ingenioFiltro > 0) {
    $condInac[] = 'p.ingenio_id = ?';
    $paramsInac[] = $ingenioFiltro;
}
$stmtInac = $db->prepare('SELECT COUNT(*) FROM participantes p WHERE ' . implode(' AND ', $condInac));
$stmtInac->execute($paramsInac);
$inactivos = (int) $stmtInac->fetchColumn();

$ingenios = $veTodo
    ? $db->query('SELECT id, nombre_ingenios FROM ingenios ORDER BY nombre_ingenios')->fetchAll(PDO::FETCH_ASSOC)
    : [];

$filtrosActuales = ['q' => $busqueda, 'ingenio_id' => $ingenioFiltro, 'cantidad' => $cantidadFiltro, 'situacion' => $situacion, 'orden' => $orden === 'nombre' ? '' : $orden];
$urlDir = static function (array $cambios = []) use ($filtrosActuales, $pagina) {
    return cengi_dir_url_lista(http_build_query(array_merge($filtrosActuales, ['p' => $pagina > 1 ? $pagina : ''])), $cambios);
};
$consultaActual = http_build_query(array_filter(array_merge($filtrosActuales, ['p' => $pagina > 1 ? $pagina : '']), 'strlen'));
$hayFiltros = $busqueda !== '' || $ingenioFiltro > 0 || $cantidadFiltro !== '' || $situacion !== '';

$mensajes = [
    'editado' => ['Los datos del participante se guardaron.', 'success'],
    'reactivado' => ['El participante se reactivó. Sus inscripciones anteriores no se restauran: asígnalo de nuevo a los cursos que correspondan.', 'success'],
    'error_requeridos' => ['Nombre, CUI, ingenio, puesto y área son obligatorios. No se guardaron los cambios.', 'error'],
    'error_correo' => ['El correo electrónico no es válido. No se guardaron los cambios.', 'error'],
    'error_cui' => ['Ya existe otro participante con ese CUI. No se guardaron los cambios.', 'error'],
    'error_guardar' => ['No fue posible guardar los cambios. Intenta nuevamente.', 'error'],
    'error' => ['No tienes permiso para esa acción o el participante no está disponible.', 'error'],
];
[$mensaje, $mensajeTipo] = $mensajes[$_GET['msg'] ?? ''] ?? ['', 'success'];
if (($_GET['msg'] ?? '') === 'aviso' && isset($_SESSION['cengi_dir_aviso'])) {
    [$mensaje, $mensajeTipo] = $_SESSION['cengi_dir_aviso'];
    unset($_SESSION['cengi_dir_aviso']);
}
if (($_GET['mensaje'] ?? '') === 'desactivado') {
    [$mensaje, $mensajeTipo] = ['El participante se desactivó y se retiró de sus cursos. Puedes verlo en “Desactivados”.', 'success'];
} elseif (($_GET['error'] ?? '') === 'eliminar') {
    [$mensaje, $mensajeTipo] = ['No fue posible desactivar al participante.', 'error'];
}
$verId = (int) ($_GET['ver'] ?? 0);

// Evita que el navegador sirva una copia cacheada (p. ej. al volver con "atrás") con conteos desactualizados.
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head('Directorio de participantes'); ?>
    <link rel="stylesheet" href="css/directorio.css?v=<?php echo (int) (@filemtime(__DIR__ . '/css/directorio.css') ?: 1); ?>">
</head>
<body class="cengi-canvas cg-tema">
<?php menu_render(); ?>

<main class="container dir-page">
    <header class="dir-hero">
        <div>
            <small><?php echo $ingenioNombre !== '' ? 'Directorio · ' . cengi_dir_html($ingenioNombre) : 'Directorio · todos los ingenios'; ?></small>
            <h1><?php echo $ingenioNombre !== '' ? 'Participantes de ' . cengi_dir_html($ingenioNombre) : 'Directorio de participantes'; ?></h1>
            <p>Cada persona con su historial de cursos, evaluación, diplomas y última capacitación. Abre su ficha para ver el detalle, corregir datos o inscribirla en un curso.</p>
        </div>
        <div class="dir-hero-acciones">
            <?php if ($veTodo && $ingenioFiltro > 0): ?>
                <?php if (function_exists('cengi_puede_ver_dashboard_ingenio') && cengi_puede_ver_dashboard_ingenio()): ?><a class="dir-hero-btn is-claro" href="dashboard_ingenio.php?ingenio_id=<?php echo $ingenioFiltro; ?>"><span class="glyphicon glyphicon-stats"></span> Seguimiento del ingenio</a><?php endif; ?>
                <a class="dir-hero-btn" href="<?php echo cengi_dir_html($urlDir(['ingenio_id' => '', 'p' => ''])); ?>"><span class="glyphicon glyphicon-globe"></span> Ver todos los ingenios</a>
            <?php endif; ?>
            <a class="dir-hero-btn" href="<?php echo cengi_dir_html($urlDir(['export' => 'excel', 'p' => ''])); ?>"><span class="glyphicon glyphicon-download-alt"></span> Exportar a Excel</a>
        </div>
    </header>
    <?php cengi_pp_feedback(cengi_dir_html($mensaje), $mensajeTipo); ?>

    <div class="cengi-kpi-grid dir-kpis">
        <a href="<?php echo cengi_dir_html($urlDir(['situacion' => '', 'p' => ''])); ?>" class="<?php echo $situacion === '' ? 'is-active' : ''; ?>"><?php echo cengi_pp_kpi(number_format((int) $kpi['personas']), 'Personas registradas', number_format((float) $kpi['promedio_cursos'], 1) . ' curso(s) por persona en promedio', '#3E7A12', 'user'); ?></a>
        <a href="<?php echo cengi_dir_html($urlDir(['situacion' => 'cursando', 'p' => ''])); ?>" class="<?php echo $situacion === 'cursando' ? 'is-active' : ''; ?>"><?php echo cengi_pp_kpi(number_format((int) $kpi['cursando']), 'Con cursos activos', 'En curso o por iniciar', '#4d6400', 'education', '#F4FAD9'); ?></a>
        <a href="<?php echo cengi_dir_html($urlDir(['situacion' => 'con_diploma', 'p' => ''])); ?>" class="<?php echo $situacion === 'con_diploma' ? 'is-active' : ''; ?>"><?php echo cengi_pp_kpi(number_format((int) $kpi['diplomas']), 'Diplomas acumulados', number_format((int) $kpi['con_diploma']) . ' persona(s) con diploma', '#1f5632', 'certificate'); ?></a>
        <a href="<?php echo cengi_dir_html($urlDir(['situacion' => 'sin_reciente', 'p' => ''])); ?>" class="<?php echo $situacion === 'sin_reciente' ? 'is-active' : ''; ?>"><?php echo cengi_pp_kpi(number_format((int) $kpi['sin_reciente']), 'Sin capacitación en 12 meses', number_format((int) $kpi['sin_cursos']) . ' sin ningún curso registrado', '#B34E00', 'time', '#FFEEE0'); ?></a>
        <a href="<?php echo cengi_dir_html($urlDir(['situacion' => 'incompletos', 'p' => ''])); ?>" class="<?php echo $situacion === 'incompletos' ? 'is-active' : ''; ?>"><?php echo cengi_pp_kpi(number_format((int) $kpi['incompletos']), 'Sin correo o teléfono', number_format((int) $kpi['sin_correo']) . ' sin correo: no reciben avisos', '#7A5C00', 'earphone', '#FFF7D6'); ?></a>
    </div>

    <section class="pp-card dir-lista">
        <nav class="dir-chips" aria-label="Situación">
            <a href="<?php echo cengi_dir_html($urlDir(['situacion' => '', 'p' => ''])); ?>" class="<?php echo $situacion === '' ? 'is-active' : ''; ?>">Todos <span><?php echo number_format((int) $kpi['personas']); ?></span></a>
            <?php foreach (CENGI_DIR_SITUACIONES as $clave => $texto):
                $n = $clave === 'inactivos' ? $inactivos : (int) $kpi[$clave]; ?>
                <a href="<?php echo cengi_dir_html($urlDir(['situacion' => $clave, 'p' => ''])); ?>" class="<?php echo $situacion === $clave ? 'is-active' : ''; ?><?php echo $clave === 'inactivos' ? ' is-gris' : ''; ?>"><?php echo cengi_dir_html($texto); ?> <span><?php echo number_format($n); ?></span></a>
            <?php endforeach; ?>
        </nav>

        <form class="ses-filtros-form" method="get" id="dir-filtros">
            <?php if ($situacion !== ''): ?><input type="hidden" name="situacion" value="<?php echo cengi_dir_html($situacion); ?>"><?php endif; ?>
            <label class="ses-campo is-buscar"><span>Buscar</span>
                <span class="ses-buscar"><span class="glyphicon glyphicon-search" aria-hidden="true"></span><input type="search" name="q" value="<?php echo cengi_dir_html($busqueda); ?>" placeholder="Nombre, CUI, correo, teléfono, puesto o área..."></span></label>
            <?php if ($veTodo): ?>
                <label class="ses-campo"><span>Ingenio</span>
                    <select name="ingenio_id">
                        <option value="0">Todos</option>
                        <?php foreach ($ingenios as $ingenio): ?>
                            <option value="<?php echo (int) $ingenio['id']; ?>" <?php echo $ingenioFiltro === (int) $ingenio['id'] ? 'selected' : ''; ?>><?php echo cengi_dir_html($ingenio['nombre_ingenios']); ?></option>
                        <?php endforeach; ?>
                    </select></label>
            <?php endif; ?>
            <label class="ses-campo"><span>Cursos</span>
                <select name="cantidad">
                    <option value="">Cualquier cantidad</option>
                    <?php foreach (['0' => 'Ninguno', '1' => '1 curso', '2-4' => '2 a 4 cursos', '5+' => '5 o más'] as $v => $t): ?>
                        <option value="<?php echo $v; ?>" <?php echo $cantidadFiltro === (string) $v ? 'selected' : ''; ?>><?php echo $t; ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label class="ses-campo"><span>Ordenar por</span>
                <select name="orden">
                    <?php foreach (CENGI_DIR_ORDENES as $v => [$t]): ?>
                        <option value="<?php echo $v; ?>" <?php echo $orden === $v ? 'selected' : ''; ?>><?php echo $t; ?></option>
                    <?php endforeach; ?>
                </select></label>
            <div class="ses-filtros-fin">
                <span class="ses-total"><b><?php echo number_format($totalLista); ?></b> persona(s)</span>
                <?php if ($hayFiltros): ?><a class="ses-limpiar" href="directorio_participantes.php"><span class="glyphicon glyphicon-remove"></span>Quitar filtros</a><?php endif; ?>
                <a class="btn btn-default btn-sm" href="<?php echo cengi_dir_html($urlDir(['export' => 'excel', 'p' => ''])); ?>"><span class="glyphicon glyphicon-download-alt"></span> Excel</a>
                <button type="submit" class="sr-only">Filtrar</button>
            </div>
        </form>

        <?php if ($situacion === 'inactivos'): ?>
            <p class="dir-aviso"><span class="glyphicon glyphicon-info-sign"></span>Personas desactivadas: no aparecen en los cursos ni en los indicadores.<?php echo $puedeEditar ? ' Puedes reactivarlas desde su fila.' : ''; ?></p>
        <?php elseif ($situacion === 'incompletos'): ?>
            <p class="dir-aviso"><span class="glyphicon glyphicon-info-sign"></span>Sin correo no reciben invitaciones, recordatorios ni diplomas por correo.<?php echo $puedeEditar ? ' Abre la ficha y usa “Editar datos” para completarlos.' : ''; ?></p>
        <?php endif; ?>

        <div class="dir-tabla-scroll">
            <table class="dir-tabla">
                <thead><tr><th>Participante</th><th>Ingenio</th><th>Cursos</th><th>Evaluación</th><th>Diplomas</th><th>Última capacitación</th><th><span class="sr-only">Acciones</span></th></tr></thead>
                <tbody>
                <?php if (!$participantes): ?>
                    <tr><td colspan="7"><div class="dir-vacio"><span class="glyphicon glyphicon-user"></span><b><?php echo $hayFiltros ? 'Ninguna persona coincide con los filtros' : 'No hay participantes registrados'; ?></b><span><?php echo $hayFiltros ? 'Prueba con otra búsqueda o quita los filtros.' : 'Los participantes se registran al inscribirlos en un curso.'; ?></span></div></td></tr>
                <?php endif; ?>
                <?php foreach ($participantes as $pt):
                    $id = (int) $pt['id'];
                    $nota = $pt['evaluacion_promedio'] !== null ? (float) $pt['evaluacion_promedio'] : null;
                    $meses = cengi_dir_meses_desde($pt['ultima_capacitacion']);
                    $total = (int) $pt['total_cursos'];
                    $activo = (int) $pt['estado_participantes'] === 1;
                    $tono = 'is-t' . ($id % 5); ?>
                    <tr class="<?php echo !$activo ? 'is-inactivo' : ($total === 0 ? 'is-sin-cursos' : ((int) $pt['cursos_activos'] > 0 ? 'is-cursando' : ($meses !== null && $meses >= 12 ? 'is-sin-reciente' : 'is-al-dia'))); ?>">
                        <td data-label="Participante">
                            <div class="dir-persona">
                                <span class="dir-avatar <?php echo $tono; ?>"><?php echo cengi_dir_html(cengi_dir_iniciales($pt['nombre_participantes'])); ?></span>
                                <span class="dir-persona-txt">
                                    <button type="button" class="dir-nombre" data-ficha="<?php echo $id; ?>"><?php echo cengi_dir_html($pt['nombre_participantes']); ?></button>
                                    <small><?php echo cengi_dir_html(implode(' · ', array_filter([$pt['puesto_participantes'], $pt['area_participantes']]))); ?></small>
                                    <span class="dir-contacto">
                                        <span class="dir-cui">CUI <?php echo cengi_dir_html($pt['cui_participantes']); ?></span><?php if (cengi_cui_nd_es_generado($pt['cui_participantes'])): ?> <span class="cengi-cui-sd" title="Código de referencia asignado por el sistema: reemplázalo por su documento cuando lo tenga">Sin documento</span><?php endif; ?>
                                        <?php if ($pt['correo_participantes']): ?>
                                            <a href="mailto:<?php echo cengi_dir_html($pt['correo_participantes']); ?>" title="<?php echo cengi_dir_html($pt['correo_participantes']); ?>"><span class="glyphicon glyphicon-envelope"></span><span class="sr-only">Correo</span></a>
                                        <?php else: ?><span class="dir-falta" title="Sin correo electrónico"><span class="glyphicon glyphicon-envelope"></span> sin correo</span><?php endif; ?>
                                        <?php if ($pt['telefono_participantes']): ?>
                                            <a href="tel:<?php echo cengi_dir_html(preg_replace('/[^0-9+]/', '', $pt['telefono_participantes'])); ?>" title="<?php echo cengi_dir_html($pt['telefono_participantes']); ?>"><span class="glyphicon glyphicon-earphone"></span><span class="sr-only">Teléfono</span></a>
                                        <?php else: ?><span class="dir-falta" title="Sin teléfono"><span class="glyphicon glyphicon-earphone"></span> sin teléfono</span><?php endif; ?>
                                    </span>
                                </span>
                            </div>
                        </td>
                        <td data-label="Ingenio"><span class="dir-ingenio"><?php echo cengi_dir_html($pt['nombre_ingenios']); ?></span></td>
                        <td data-label="Cursos"><div class="dir-celda">
                            <?php if ($total): ?>
                                <div class="dir-cursos"><b><?php echo $total; ?></b> curso<?php echo $total === 1 ? '' : 's'; ?></div>
                                <div class="dir-barra" aria-hidden="true">
                                    <span class="is-ok" style="width:<?php echo round((int) $pt['cursos_completados'] / $total * 100); ?>%"></span><span class="is-activo" style="width:<?php echo round((int) $pt['cursos_activos'] / $total * 100); ?>%"></span>
                                </div>
                                <small class="dir-sub"><?php echo (int) $pt['cursos_completados']; ?> completado(s) · <?php echo (int) $pt['cursos_activos']; ?> activo(s)</small>
                            <?php else: ?><span class="dir-sub">Sin cursos</span><?php endif; ?>
                        </div></td>
                        <td data-label="Evaluación"><span class="dir-nota <?php echo cengi_dir_tono_nota($nota); ?>"><?php echo $nota !== null ? number_format($nota, 1) . ' pts' : 'Sin nota'; ?></span></td>
                        <td data-label="Diplomas"><span class="dir-diplomas<?php echo (int) $pt['diplomas'] ? ' is-con' : ''; ?>"><span class="glyphicon glyphicon-certificate"></span><?php echo (int) $pt['diplomas']; ?></span></td>
                        <td data-label="Última capacitación"><div class="dir-celda">
                            <?php if ($pt['ultima_capacitacion']): ?>
                                <b class="dir-fecha"><?php echo cengi_dir_html(cengi_dir_fecha($pt['ultima_capacitacion'])); ?></b>
                                <?php if ($meses !== null && $meses >= 12): ?><span class="dir-alerta">hace <?php echo $meses; ?> meses</span><?php elseif ($meses === null): ?><span class="dir-proximo">por iniciar</span><?php endif; ?>
                                <small class="dir-sub dir-ultimo" title="<?php echo cengi_dir_html($pt['ultimo_curso']); ?>"><?php echo cengi_dir_html($pt['ultimo_curso']); ?></small>
                            <?php else: ?><span class="dir-sub">—</span><?php endif; ?>
                        </div></td>
                        <td class="dir-acciones">
                            <button type="button" class="btn btn-default btn-sm" data-ficha="<?php echo $id; ?>"><span class="glyphicon glyphicon-list-alt"></span> Ficha</button>
                            <?php if ($activo && $puedeEditar): ?>
                                <button type="button" class="dir-icono" data-ficha="<?php echo $id; ?>" data-editar="1" title="Editar datos"><span class="glyphicon glyphicon-pencil"></span><span class="sr-only">Editar datos</span></button>
                            <?php endif; ?>
                            <?php if ($activo && $puedeEliminar): ?>
                                <button type="button" class="dir-icono is-peligro" data-toggle="modal" data-target="#dir-desactivar" data-href="eliminar_participante.php?origen=directorio&amp;id=<?php echo $id; ?>" data-nombre="<?php echo cengi_dir_html($pt['nombre_participantes']); ?>" data-cursos="<?php echo (int) $pt['cursos_activos']; ?>" title="Desactivar"><span class="glyphicon glyphicon-trash"></span><span class="sr-only">Desactivar</span></button>
                            <?php endif; ?>
                            <?php if (!$activo && $puedeEditar): ?>
                                <form method="post" class="dir-inline"><input type="hidden" name="accion" value="reactivar"><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="volver" value="<?php echo cengi_dir_html($consultaActual); ?>">
                                    <button type="submit" class="btn btn-sm dir-btn-verde"><span class="glyphicon glyphicon-repeat"></span> Reactivar</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($paginas > 1): ?>
            <nav class="dir-paginas" aria-label="Páginas">
                <a class="<?php echo $pagina <= 1 ? 'is-disabled' : ''; ?>" href="<?php echo cengi_dir_html($urlDir(['p' => $pagina - 1])); ?>"><span class="glyphicon glyphicon-chevron-left"></span> Anterior</a>
                <span>Página <b><?php echo $pagina; ?></b> de <?php echo $paginas; ?> · <?php echo number_format(($pagina - 1) * CENGI_DIR_POR_PAGINA + 1); ?>–<?php echo number_format(min($totalLista, $pagina * CENGI_DIR_POR_PAGINA)); ?> de <?php echo number_format($totalLista); ?></span>
                <a class="<?php echo $pagina >= $paginas ? 'is-disabled' : ''; ?>" href="<?php echo cengi_dir_html($urlDir(['p' => $pagina + 1])); ?>">Siguiente <span class="glyphicon glyphicon-chevron-right"></span></a>
            </nav>
        <?php endif; ?>
    </section>
</main>

<div class="modal fade dir-modal" id="dir-ficha" tabindex="-1" role="dialog" aria-labelledby="dir-ficha-nombre">
    <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
        <header class="dir-ficha-head">
            <span class="dir-ficha-avatar" id="dir-ficha-avatar">P</span>
            <div class="dir-ficha-titulo"><h2 id="dir-ficha-nombre">Ficha del participante</h2><p id="dir-ficha-sub"></p></div>
            <button type="button" class="dir-ficha-cerrar" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">×</span></button>
        </header>
        <div class="modal-body">
            <div class="dir-ficha-estado" id="dir-ficha-cargando"><span class="glyphicon glyphicon-refresh"></span> Cargando ficha…</div>
            <div class="dir-ficha-estado is-error" id="dir-ficha-error" hidden></div>
            <div id="dir-ficha-contenido" hidden>
                <div class="dir-ficha-inactivo" id="dir-ficha-inactivo" hidden><span class="glyphicon glyphicon-ban-circle"></span> Participante desactivado: no aparece en los cursos.</div>
                <div class="dir-ficha-stats">
                    <div><b id="dir-st-total">0</b><span>Cursos</span></div>
                    <div class="is-verde"><b id="dir-st-completados">0</b><span>Completados</span></div>
                    <div class="is-verde2"><b id="dir-st-activos">0</b><span>Activos o por iniciar</span></div>
                    <div class="is-amarillo"><b id="dir-st-diplomas">0</b><span>Diplomas</span></div>
                    <div class="is-naranja"><b id="dir-st-nota">—</b><span>Post-evaluación prom.</span></div>
                </div>

                <div class="dir-ficha-grid">
                    <section class="dir-ficha-bloque">
                        <div class="dir-ficha-bloque-t"><span>Datos de contacto</span>
                            <?php if ($puedeEditar): ?><button type="button" class="dir-link" id="dir-editar-abrir"><span class="glyphicon glyphicon-pencil"></span> Editar datos</button><?php endif; ?>
                        </div>
                        <dl class="dir-datos" id="dir-datos">
                            <div><dt>Puesto</dt><dd data-campo="puesto_participantes"></dd></div>
                            <div><dt>Área</dt><dd data-campo="area_participantes"></dd></div>
                            <div><dt>Correo electrónico</dt><dd data-campo="correo_participantes"></dd></div>
                            <div><dt>Teléfono</dt><dd data-campo="telefono_participantes"></dd></div>
                            <div><dt>Grado académico</dt><dd data-campo="grado_academico_participantes"></dd></div>
                            <div><dt>Registrado</dt><dd data-campo="creado"></dd></div>
                        </dl>
                        <?php if ($puedeEditar): ?>
                            <form method="post" class="dir-editar" id="dir-editar" hidden>
                                <input type="hidden" name="accion" value="editar">
                                <input type="hidden" name="id" value="">
                                <input type="hidden" name="volver" value="<?php echo cengi_dir_html($consultaActual); ?>">
                                <label class="is-ancho">Nombre completo *<input type="text" name="nombre" class="form-control input-sm" required maxlength="150"></label>
                                <label>Documento (DPI, pasaporte u otro)<input type="text" name="cui" class="form-control input-sm" maxlength="25" title="Si no tiene documento, déjalo en blanco: el sistema asigna un código de referencia (ej. 0001ND) que puedes reemplazar después." placeholder="En blanco: código de referencia"></label>
                                <?php if ($veTodo): ?>
                                    <label>Ingenio *<select name="ingenio_id" class="form-control input-sm" required>
                                        <?php foreach ($ingenios as $ingenio): ?><option value="<?php echo (int) $ingenio['id']; ?>"><?php echo cengi_dir_html($ingenio['nombre_ingenios']); ?></option><?php endforeach; ?>
                                    </select></label>
                                <?php endif; ?>
                                <label>Puesto *<input type="text" name="puesto" class="form-control input-sm" required maxlength="150"></label>
                                <label>Área *<input type="text" name="area" class="form-control input-sm" required maxlength="150"></label>
                                <label>Correo electrónico<input type="email" name="correo" class="form-control input-sm" maxlength="150"></label>
                                <label>Teléfono<input type="text" name="telefono" class="form-control input-sm" maxlength="30"></label>
                                <label>Grado académico<input type="text" name="grado" class="form-control input-sm" maxlength="150"></label>
                                <div class="dir-editar-acciones is-ancho">
                                    <button type="button" class="btn btn-default btn-sm" id="dir-editar-cancelar">Cancelar</button>
                                    <button type="submit" class="btn btn-primary btn-sm"><span class="glyphicon glyphicon-floppy-disk"></span> Guardar cambios</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </section>

                    <aside class="dir-ficha-bloque">
                        <div class="dir-ficha-bloque-t"><span>Acciones</span></div>
                        <div class="dir-accesos" id="dir-accesos"></div>
                        <?php if ($puedeAsignar): ?>
                            <form method="post" class="dir-asignar" id="dir-asignar">
                                <input type="hidden" name="accion" value="inscribir">
                                <input type="hidden" name="id" value="">
                                <input type="hidden" name="volver" value="<?php echo cengi_dir_html($consultaActual); ?>">
                                <div class="dir-asignar-t" id="dir-asignar-t">Inscribir en un curso</div>
                                <label>Curso<select name="curso_id" id="dir-asignar-curso" class="form-control input-sm" required></select></label>
                                <div id="dir-asignar-cobro">
                                    <label>Cuota<select name="cuota" id="dir-asignar-cuota" class="form-control input-sm">
                                        <?php foreach (CENGI_INS_CUOTAS as $claveCuota => [$nombreCuota]): ?><option value="<?php echo $claveCuota; ?>"><?php echo cengi_dir_html($nombreCuota); ?></option><?php endforeach; ?>
                                    </select></label>
                                    <div class="dir-pagador" role="radiogroup" aria-label="Quién paga">
                                        <label><input type="radio" name="tipo_pago" value="Propio" checked> Pago propio</label>
                                        <label data-solo-ingenio><input type="radio" name="tipo_pago" value="Ingenio"> Lo paga su ingenio</label>
                                        <label><input type="radio" name="tipo_pago" value="Exonerado"> Sin costo (beca / invitado)</label>
                                    </div>
                                    <div class="dir-monto" id="dir-asignar-monto"></div>
                                    <label class="dir-check" data-solo-propio><input type="checkbox" name="avisar" value="1" checked> Enviarle por correo el monto y el enlace para subir su comprobante</label>
                                </div>
                                <label class="dir-check"><input type="checkbox" name="asignar" value="1" checked> Asignarlo al curso ahora <small>(si no, entra al validarse el pago)</small></label>
                                <button type="submit" class="btn btn-primary btn-sm btn-block"><span class="glyphicon glyphicon-plus"></span> <span id="dir-asignar-btn">Inscribir</span></button>
                                <small id="dir-asignar-ayuda">El pago queda en Pagos de inscripción para su seguimiento.</small>
                            </form>
                        <?php endif; ?>
                    </aside>
                </div>

                <div class="dir-historial-cab">
                    <h3>Historial de capacitación</h3>
                    <div class="dir-historial-filtro" id="dir-historial-filtro" role="group" aria-label="Filtrar historial">
                        <button type="button" class="is-active" data-filtro="">Todos</button>
                        <button type="button" data-filtro="finalizado">Completados</button>
                        <button type="button" data-filtro="activo">Activos</button>
                    </div>
                </div>
                <div class="dir-historial" id="dir-historial"></div>
            </div>
        </div>
        <footer class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button></footer>
    </div></div>
</div>

<?php if ($puedeEliminar): ?>
<div class="modal fade" id="dir-desactivar" tabindex="-1" role="dialog" aria-labelledby="dir-desactivar-t">
    <div class="modal-dialog modal-sm" role="document"><div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title" id="dir-desactivar-t">Desactivar participante</h4>
        </div>
        <div class="modal-body">
            <p>¿Desactivar a <b id="dir-desactivar-nombre"></b>?</p>
            <p class="dir-sub" id="dir-desactivar-detalle">Se retirará de todos sus cursos. Su historial y diplomas se conservan y podrá reactivarse desde “Desactivados”.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
            <a class="btn btn-danger" id="dir-desactivar-ok" href="#">Desactivar</a>
        </div>
    </div></div>
</div>
<?php endif; ?>

<script>
(function ($) {
    'use strict';
    var PUEDE_VER_CURSO = <?php echo $puedeVerCurso ? 'true' : 'false'; ?>;
    var PUEDE_ADMIN = <?php echo $puedeAsignar ? 'true' : 'false'; ?>;
    var PUEDE_EDITAR = <?php echo $puedeEditar ? 'true' : 'false'; ?>;
    var PUEDE_VER_PAGOS = <?php echo cengi_ins_puede_ver_pagos() ? 'true' : 'false'; ?>;
    var cuotasPorCurso = {}, inscripcionCfg = {};
    var request = null, actual = null, abrirEditando = false;
    var $modal = $('#dir-ficha');

    $('#dir-filtros select').on('change', function () { this.form.submit(); });

    $('#dir-desactivar').on('show.bs.modal', function (event) {
        var $t = $(event.relatedTarget), n = Number($t.data('cursos')) || 0;
        $('#dir-desactivar-nombre').text($t.data('nombre'));
        $('#dir-desactivar-ok').attr('href', $t.data('href'));
        $('#dir-desactivar-detalle').text((n ? 'Se retirará de ' + n + ' curso(s) activo(s) y del resto de su historial. ' : 'Se retirará de sus cursos. ')
            + 'Su historial y diplomas se conservan y podrá reactivarse desde “Desactivados”.');
    });

    function initials(name) {
        return String(name || 'P').trim().split(/\s+/).slice(0, 2).map(function (p) { return p.charAt(0).toUpperCase(); }).join('') || 'P';
    }
    function num(v) {
        return v === null || v === undefined || v === '' || isNaN(Number(v)) ? null : Number(v);
    }
    function fmt(v, suf) {
        var n = num(v);
        return n === null ? '—' : (Math.round(n * 10) / 10) + (suf || '');
    }
    function fecha(v) {
        if (!v || String(v).slice(0, 10) === '0000-00-00') return '';
        var p = String(v).slice(0, 10).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : v;
    }
    function tono(n) {
        return n === null ? 'is-gris' : (n >= 80 ? 'is-verde' : (n >= 60 ? 'is-amarillo' : 'is-naranja'));
    }
    function icono(nombre) {
        return $('<span>', {class: 'glyphicon glyphicon-' + nombre, 'aria-hidden': 'true'});
    }

    function curso(c) {
        var estados = {activo: ['Activo', 'is-activo'], por_iniciar: ['Por iniciar', 'is-proximo'], finalizado: ['Finalizado', 'is-fin']};
        var est = estados[c.estado_curso] || estados.activo;
        var fechas = fecha(c.inicio) || 'Sin fecha';
        if (fecha(c.fin) && c.fin !== c.inicio) fechas += ' – ' + fecha(c.fin);
        var pre = num(c.evaluacion), post = num(c.posevaluacion), asis = num(c.asistencia);

        var $c = $('<article>', {class: 'dir-curso', 'data-estado': c.estado_curso === 'finalizado' ? 'finalizado' : 'activo'});
        $c.append($('<div>', {class: 'dir-curso-cab'})
            .append($('<b>').text(c.nombre_cursos || 'Curso sin nombre'))
            .append($('<span>', {class: 'dir-estado ' + est[1]}).text(est[0])));
        $c.append($('<p>', {class: 'dir-sub'}).append(icono('calendar')).append(document.createTextNode(' ' + [fechas, c.categoria, c.modalidad].filter(Boolean).join(' · '))));

        var $m = $('<div>', {class: 'dir-curso-metricas'});
        var $asis = $('<div>').append($('<span>').text('Asistencia')).append($('<b>').text(fmt(asis, '%')));
        if (asis !== null) $asis.append($('<i>', {class: 'dir-mini'}).append($('<em>').css('width', Math.max(0, Math.min(100, asis)) + '%')));
        $m.append($asis);
        var $ev = $('<div>').append($('<span>').text('Evaluación (pre → post)'));
        var $evv = $('<b>').text(fmt(pre) + ' → ').append($('<span>', {class: 'dir-nota ' + tono(post)}).text(fmt(post, ' pts')));
        if (pre !== null && post !== null && post !== pre) {
            $evv.append($('<small>', {class: post > pre ? 'dir-delta is-sube' : 'dir-delta is-baja'}).text((post > pre ? '+' : '') + (Math.round((post - pre) * 10) / 10)));
        }
        $m.append($ev.append($evv));
        $c.append($m);

        var $acc = $('<div>', {class: 'dir-curso-acciones'});
        if (c.diploma && c.asignacion_id) {
            $acc.append($('<a>', {class: 'dir-link is-diploma', href: 'descargar_diploma.php?asignacion_id=' + encodeURIComponent(Number(c.asignacion_id)), target: '_blank', rel: 'noopener'})
                .append(icono('certificate')).append(document.createTextNode(' Diploma' + (c.diploma_codigo ? ' · ' + c.diploma_codigo : ''))));
        }
        if (PUEDE_VER_CURSO && c.curso_id) {
            $acc.append($('<a>', {class: 'dir-link', href: 'ver_participante_curso.php?id=' + encodeURIComponent(Number(c.curso_id))})
                .append(icono('education')).append(document.createTextNode(' Abrir curso')));
        }
        // Cobro de la inscripción (cursos de una planificación).
        if (c.cobro) {
            var $cobro = $('<div>', {class: 'dir-cobro'})
                .append($('<span>').text('Pago de inscripción'))
                .append($('<span>', {class: 'dir-nota ' + c.cobro.tono}).text(c.cobro.texto));
            if (c.cobro.quien) $cobro.append($('<small>', {class: 'dir-sub'}).text(c.cobro.quien + (c.cobro.monto ? ' · ' + c.cobro.monto : '')));
            if (c.cobro.estado === 'sin_registro' && PUEDE_ADMIN) {
                cuotasPorCurso[c.curso_id] = c.cobro.cuotas;
                $cobro.append($('<button>', {type: 'button', class: 'btn btn-default btn-xs dir-registrar-cobro', 'data-curso': c.curso_id, 'data-nombre': c.nombre_cursos})
                    .append(icono('usd')).append(document.createTextNode(' Registrar cobro')));
            } else if (c.cobro.plan_id && PUEDE_VER_PAGOS && actual) {
                $cobro.append($('<a>', {class: 'dir-link', href: 'pagos_inscripcion.php?anio=' + c.cobro.anio + '&plan=' + c.cobro.plan_id + '&q=' + encodeURIComponent(actual.cui_participantes || '')})
                    .append(icono('usd')).append(document.createTextNode(' Ver en Pagos')));
            }
            $c.append($cobro);
        }
        if ($acc.children().length) $c.append($acc);
        return $c;
    }

    function acceso(href, ico, texto, extra) {
        return $('<a>', $.extend({class: 'dir-acceso', href: href}, extra || {})).append(icono(ico)).append($('<span>').text(texto));
    }

    function render(data) {
        var p = data.participante || {}, r = data.resumen || {}, cursos = data.cursos || [];
        actual = p;
        var activo = Number(p.estado_participantes) === 1;
        $('#dir-ficha-avatar').text(initials(p.nombre_participantes));
        $('#dir-ficha-nombre').text(p.nombre_participantes || 'Ficha del participante');
        $('#dir-ficha-sub').text((p.nombre_ingenios || 'Sin ingenio') + ' · CUI ' + (p.cui_participantes || '—') + (/^[0-9]+ND$/i.test(p.cui_participantes || '') ? ' (código de referencia, sin documento)' : '') + (p.cui_anterior ? ' · antes: ' + p.cui_anterior : ''));
        $('#dir-ficha-inactivo').prop('hidden', activo);
        $('#dir-st-total').text(r.total || 0);
        $('#dir-st-completados').text(r.completados || 0);
        $('#dir-st-activos').text(r.activos || 0);
        $('#dir-st-diplomas').text(r.diplomas || 0);
        var posts = cursos.map(function (c) { return num(c.posevaluacion); }).filter(function (n) { return n !== null; });
        $('#dir-st-nota').text(posts.length ? fmt(posts.reduce(function (a, b) { return a + b; }, 0) / posts.length) : '—');

        $('#dir-datos dd').each(function () {
            var campo = $(this).data('campo'), v = p[campo] || '', $dd = $(this).empty().removeClass('is-falta');
            if (campo === 'creado') v = fecha(v);
            if (!v) { $dd.addClass('is-falta').text(campo === 'correo_participantes' || campo === 'telefono_participantes' ? 'Sin registrar' : '—'); return; }
            if (campo === 'correo_participantes') {
                $dd.append($('<a>', {href: 'mailto:' + v}).text(v))
                    .append($('<button>', {type: 'button', class: 'dir-copiar', title: 'Copiar correo', 'data-copiar': v}).append(icono('duplicate')));
            } else if (campo === 'telefono_participantes') {
                $dd.append($('<a>', {href: 'tel:' + String(v).replace(/[^0-9+]/g, '')}).text(v));
            } else {
                $dd.text(v);
            }
        });

        var $acc = $('#dir-accesos').empty();
        if (p.correo_participantes) $acc.append(acceso('mailto:' + p.correo_participantes, 'envelope', 'Escribirle un correo'));
        if (PUEDE_ADMIN) {
            $acc.append(acceso('inscripciones.php?etapa=todas&q=' + encodeURIComponent(p.cui_participantes || p.nombre_participantes || ''), 'inbox', 'Ver sus inscripciones'));
            if (p.correo_participantes) $acc.append(acceso('comunicaciones.php?q=' + encodeURIComponent(p.correo_participantes), 'send', 'Correos que ha recibido'));
            if (r.diplomas) $acc.append(acceso('diplomas.php?tab=emitidos&q=' + encodeURIComponent(p.nombre_participantes || ''), 'certificate', 'Ver en Certificación'));
        }
        if (!$acc.children().length) $acc.append($('<p>', {class: 'dir-sub'}).text('Sin accesos adicionales.'));

        var $asig = $('#dir-asignar');
        cuotasPorCurso = {};
        inscripcionCfg = data.inscripcion || {};
        if ($asig.length) {
            var disp = data.disponibles || [];
            $asig.prop('hidden', !activo).find('[name=id]').val(p.id);
            var $sel = $('#dir-asignar-curso').empty();
            if (!disp.length) $sel.append($('<option>', {value: ''}).text('No hay cursos vigentes disponibles'));
            else $sel.append($('<option>', {value: ''}).text('Selecciona un curso…'));
            disp.forEach(function (c) {
                cuotasPorCurso[c.id] = c.cuotas;
                $sel.append($('<option>', {value: c.id}).text(c.nombre_cursos + (c.inicio ? ' · inicia ' + fecha(c.inicio) : '') + (c.cuotas ? '' : ' · cuota por definir')));
            });
            $('#dir-asignar-cuota').val(inscripcionCfg.cuota_sugerida || 'asociado');
            $asig.find('[data-solo-ingenio]').toggle(!!inscripcionCfg.de_ingenio);
            $asig.find('[name=tipo_pago][value=Propio]').prop('checked', true);
            $asig.find('[name=asignar]').prop('checked', true);
            $('#dir-asignar-t').text('Inscribir en un curso');
            $('#dir-asignar-btn').text('Inscribir');
            $asig.find('button[type=submit]').prop('disabled', !disp.length);
            actualizarCobro();
        }

        $('#dir-editar-abrir').prop('hidden', !activo);
        var $f = $('#dir-editar');
        if ($f.length) {
            $f.find('[name=id]').val(p.id);
            $f.find('[name=nombre]').val(p.nombre_participantes || '');
            $f.find('[name=cui]').val(p.cui_participantes || '');
            $f.find('[name=ingenio_id]').val(p.ingenio_id);
            $f.find('[name=puesto]').val(p.puesto_participantes || '');
            $f.find('[name=area]').val(p.area_participantes || '');
            $f.find('[name=correo]').val(p.correo_participantes || '');
            $f.find('[name=telefono]').val(p.telefono_participantes || '');
            $f.find('[name=grado]').val(p.grado_academico_participantes || '');
        }
        modoEdicion(abrirEditando && activo && PUEDE_EDITAR);

        var $h = $('#dir-historial').empty();
        $('#dir-historial-filtro button').removeClass('is-active').first().addClass('is-active');
        if (!cursos.length) $h.append($('<div>', {class: 'dir-vacio is-chico'}).append(icono('education')).append($('<span>').text('Todavía no tiene cursos registrados.')));
        cursos.forEach(function (c) { $h.append(curso(c)); });

        $('#dir-ficha-cargando, #dir-ficha-error').prop('hidden', true);
        $('#dir-ficha-contenido').prop('hidden', false);
    }

    // Formulario de inscripción: cuota, quién paga y monto según el curso elegido.
    function actualizarCobro() {
        var $f = $('#dir-asignar');
        if (!$f.length) return;
        var cuotas = cuotasPorCurso[$('#dir-asignar-curso').val()];
        var tipo = $f.find('[name=tipo_pago]:checked').val();
        $('#dir-asignar-cobro').show();
        if (!cuotas) {
            $('#dir-asignar-monto').text('');
            $('#dir-asignar-ayuda').text($('#dir-asignar-curso').val()
                ? 'Se crea una inscripción por curso. Define la cuota y valida el pago o patrocinio antes de asignar.'
                : 'El pago queda en Pagos de inscripción para su seguimiento.');
            return;
        }
        var c = cuotas[$('#dir-asignar-cuota').val()] || {monto: 0, texto: ''};
        var gratis = !(Number(c.monto) > 0);
        $f.find('[name=tipo_pago]').prop('disabled', gratis);
        if (gratis) {
            $('#dir-asignar-monto').html('').append($('<b>').text('Sin costo')).append(document.createTextNode(' para esta cuota'));
        } else {
            $('#dir-asignar-monto').html('').append(document.createTextNode(tipo === 'Exonerado' ? 'Se exonera la cuota de ' : 'Monto a pagar: ')).append($('<b>').text(c.texto));
        }
        var propio = !gratis && tipo === 'Propio';
        $f.find('[data-solo-propio]').toggle(propio && !!(actual && actual.correo_participantes));
        $('#dir-asignar-ayuda').text(gratis || tipo === 'Exonerado' ? 'Queda registrado como inscripción sin costo.'
            : (tipo === 'Ingenio' ? 'Se suma a la orden de pago del ingenio; el ingenio valida la inscripción en Pagos de inscripción.'
                : 'Queda pendiente en Pagos de inscripción para registrar el comprobante y darle seguimiento.'));
    }
    $('#dir-asignar').on('change', 'select, [name=tipo_pago]', actualizarCobro);
    // "Registrar cobro" de un curso ya asignado sin solicitud de inscripción.
    $modal.on('click', '.dir-registrar-cobro', function () {
        var id = String($(this).data('curso')), $sel = $('#dir-asignar-curso');
        if (!$sel.find('option[value="' + id + '"]').length) $sel.append($('<option>', {value: id}).text($(this).data('nombre') + ' · ya asignado'));
        $sel.val(id);
        $('#dir-asignar').find('[name=asignar]').prop('checked', true);
        $('#dir-asignar').find('button[type=submit]').prop('disabled', false);
        $('#dir-asignar-t').text('Registrar cobro de la inscripción');
        $('#dir-asignar-btn').text('Registrar cobro');
        actualizarCobro();
        var f = document.getElementById('dir-asignar');
        if (f && f.scrollIntoView) f.scrollIntoView({behavior: 'smooth', block: 'center'});
        $('#dir-asignar-cuota').trigger('focus');
    });

    function modoEdicion(si) {
        $('#dir-editar').prop('hidden', !si);
        $('#dir-datos').prop('hidden', si);
        if (si) setTimeout(function () { $('#dir-editar [name=nombre]').trigger('focus'); }, 50);
    }
    $('#dir-editar-abrir').on('click', function () { modoEdicion(true); });
    $('#dir-editar-cancelar').on('click', function () { modoEdicion(false); });

    $('#dir-historial-filtro').on('click', 'button', function () {
        var f = $(this).data('filtro');
        $(this).addClass('is-active').siblings().removeClass('is-active');
        $('#dir-historial .dir-curso').each(function () { $(this).toggle(!f || $(this).data('estado') === f); });
    });

    $modal.on('click', '.dir-copiar', function () {
        var $b = $(this), texto = String($b.data('copiar'));
        var listo = function () { $b.addClass('is-ok'); setTimeout(function () { $b.removeClass('is-ok'); }, 1200); };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(texto).then(listo);
        else { var $t = $('<textarea>').val(texto).appendTo('body').select(); document.execCommand('copy'); $t.remove(); listo(); }
    });

    function abrirFicha(id, editar) {
        if (request) request.abort();
        abrirEditando = !!editar;
        $('#dir-ficha-avatar').text('P');
        $('#dir-ficha-nombre').text('Ficha del participante');
        $('#dir-ficha-sub').text('');
        $('#dir-ficha-contenido, #dir-ficha-error').prop('hidden', true);
        $('#dir-ficha-cargando').prop('hidden', false);
        $modal.modal('show');
        request = $.ajax({url: 'directorio_participantes.php', data: {ficha_id: id}, dataType: 'json', cache: false})
            .done(render).fail(function (xhr, status) {
                if (status === 'abort') return;
                var msg = xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : 'No fue posible cargar la ficha. Intenta nuevamente.';
                $('#dir-ficha-cargando').prop('hidden', true);
                $('#dir-ficha-error').text(msg).prop('hidden', false);
            });
    }
    $(document).on('click', '[data-ficha]', function () {
        abrirFicha($(this).data('ficha'), $(this).data('editar'));
    });
    $modal.on('hidden.bs.modal', function () {
        if (request) request.abort();
        request = null;
        actual = null;
    });

    // ?ver=ID abre la ficha (enlaces desde otras secciones y regreso tras editar).
    var verId = <?php echo $verId; ?>;
    if (verId > 0) abrirFicha(verId, <?php echo str_starts_with((string) ($_GET['msg'] ?? ''), 'error_') ? 'true' : 'false'; ?>);
})(jQuery);
</script>
</body>
</html>
