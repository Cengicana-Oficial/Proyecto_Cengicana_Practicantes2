<?php
/**
 * Pagos de inscripcion de las convocatorias:
 *   1. Validacion: el usuario del ingenio valida a sus inscritos (pago del ingenio) y
 *      Administracion valida el comprobante del pago propio. Lo validado cuenta para el
 *      cupo: al llegar al minimo se crea el curso y se asigna (cengi_ins_procesar_plan).
 *   2. Seguimiento del cobro: Administracion marca como pagado el pago propio o la orden
 *      grupal del ingenio, o registra un reembolso.
 * Pestañas: por participante, pagos grupales de ingenios, proformas (proforma.php) y resumen
 * por curso / ingenio.
 */
require_once __DIR__ . '/proformas_helpers.php';
require_once __DIR__ . '/menu.php';

if (!cengi_ins_puede_ver_pagos()) {
    header('Location: index.php');
    exit;
}

$db = conectar();
$hoy = cengi_pp_hoy();
$puedeValidarPagos = cengi_ins_puede_validar_pagos();
$limitado = cengi_ins_ingenio_limitado();
$usuario = cengi_pp_usuario_nombre();
$mensaje = '';
$mensajeTipo = 'success';

const CENGI_PI_MENSAJES = [
    'val-ingenio' => 'Validación del ingenio registrada.',
    'no-ingenio' => 'Se registró que el ingenio no validó la inscripción; se avisó al participante.',
    'comprobante' => 'Comprobante registrado: queda por validar.',
    'validado' => 'Comprobante validado. La inscripción cuenta para el cupo del curso.',
    'rechazado' => 'Comprobante rechazado; se avisó al participante.',
    'pagado' => 'Pago marcado como pagado.',
    'reembolsado' => 'Reembolso registrado.',
    'cuota' => 'Cuota actualizada.',
    'grupo' => 'Orden del ingenio guardada.',
    'grupo-pagado' => 'Orden del ingenio marcada como pagada.',
    'grupo-rechazado' => 'Comprobante de la orden rechazado.',
    'solicitud' => 'Se pidió la validación a los usuarios de los ingenios.',
    'cuentas' => 'Cuentas bancarias guardadas.',
];

function cengi_pi_avisar(PDO $db, array $s, $asunto, $mensaje)
{
    try {
        cengi_pp_enviar_comunicacion($db, ['categoria' => 'Pagos de inscripción', 'origen' => 'sistema', 'planificacion_id' => (int) $s['planificacion_id'],
            'asunto' => $asunto, 'mensaje' => "Hola {nombre}:\n\n" . $mensaje . "\n\nCoordinación de Capacitación — CENGICAÑA"],
            [['nombre' => $s['nombre_participante'], 'correo' => $s['correo']]]);
    } catch (Throwable $e) {
        error_log('Pagos de inscripcion: no se pudo avisar a ' . $s['correo'] . ': ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $codigo = '';
    $planes = [];
    $avisos = [];
    try {
        $db->beginTransaction();
        $cargar = static function ($id) use ($db, $limitado) {
            $s = cengi_ins_solicitud($db, $id, true);
            if (!$s || !$s['planificacion_id'] || ($limitado !== null && cengi_texto_normalizado($s['nombre_ingenios']) !== cengi_texto_normalizado($limitado))) {
                throw new InvalidArgumentException('La inscripción no existe o no tienes acceso a ella.');
            }
            return $s;
        };
        $soloAdmin = static function () use ($puedeValidarPagos) {
            if (!$puedeValidarPagos) {
                throw new InvalidArgumentException('Solo Administración puede gestionar los pagos.');
            }
        };
        $pago = static function () use ($hoy) {
            $forma = mb_substr(trim((string) ($_POST['pago_forma'] ?? '')), 0, 60, 'UTF-8');
            $ref = mb_substr(trim((string) ($_POST['pago_referencia'] ?? '')), 0, 120, 'UTF-8');
            $fecha = (string) ($_POST['pago_fecha'] ?? '');
            if ($forma === '' || $ref === '' || !cengi_pp_es_fecha($fecha) || $fecha > $hoy) {
                throw new InvalidArgumentException('Indica la forma, la referencia y la fecha del pago.');
            }
            return [$forma, $ref, $fecha];
        };

        if ($accion === 'val_ingenio') {
            $decision = ($_POST['decision'] ?? '') === 'rechazada' ? 'rechazada' : 'validada';
            $nota = mb_substr(trim((string) ($_POST['nota'] ?? '')), 0, 500, 'UTF-8');
            if ($decision === 'rechazada' && $nota === '') {
                throw new InvalidArgumentException('Escribe el motivo por el que el ingenio no valida la inscripción.');
            }
            $ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
            if (!$ids) {
                throw new InvalidArgumentException('Marca al menos una inscripción.');
            }
            foreach ($ids as $id) {
                $s = $cargar($id);
                if ($s['tipo_pago'] !== 'Ingenio' || !cengi_ins_puede_validar_ingenio($s['nombre_ingenios'])) {
                    throw new InvalidArgumentException('Solo el usuario de ' . cengi_pp_h($s['nombre_ingenios'] ?: 'su ingenio') . ' puede validar la inscripción de ' . cengi_pp_h($s['nombre_participante']) . '.');
                }
                $db->prepare('UPDATE solicitudes_inscripcion SET validacion_ingenio = ?, validacion_ingenio_por = ?, validacion_ingenio_en = NOW(), validacion_ingenio_nota = ? WHERE id_solicitud = ?')
                    ->execute([$decision, $usuario . ' (' . $s['nombre_ingenios'] . ')', $nota, $id]);
                $planes[$s['planificacion_id']] = true;
                if ($decision === 'rechazada') {
                    $avisos[] = [$s, 'Inscripción no validada: ' . $s['plan_nombre'], $s['nombre_ingenios'] . ' no validó tu inscripción a ' . $s['plan_nombre'] . ":\n" . $nota
                        . "\n\nSi deseas participar, puedes inscribirte con pago propio o comunicarte con tu ingenio."];
                }
            }
            $codigo = $decision === 'validada' ? 'val-ingenio' : 'no-ingenio';
        } elseif ($accion === 'comprobante') {
            $soloAdmin();
            $s = $cargar((int) ($_POST['id'] ?? 0));
            $boleta = mb_substr(trim((string) ($_POST['boleta'] ?? '')), 0, 60, 'UTF-8');
            $fecha = (string) ($_POST['pago_fecha'] ?? '');
            $monto = (string) ($_POST['monto_comprobante'] ?? '');
            if ($s['tipo_pago'] !== 'Propio' || $boleta === '' || !cengi_pp_es_fecha($fecha) || !is_numeric($monto) || (float) $monto <= 0) {
                throw new InvalidArgumentException('Indica la boleta, la fecha y el monto del comprobante.');
            }
            [$ruta, $nombre] = !empty($_FILES['archivo']) && $_FILES['archivo']['error'] !== UPLOAD_ERR_NO_FILE
                ? cengi_ins_guardar_comprobante($_FILES['archivo'], 'p' . (int) $s['planificacion_id'])
                : [$s['comprobante_ruta'], $s['comprobante_nombre']];
            if (!$ruta) {
                throw new InvalidArgumentException('Adjunta el comprobante.');
            }
            $db->prepare("UPDATE solicitudes_inscripcion SET boleta = ?, pago_fecha = ?, monto_comprobante = ?, comprobante_ruta = ?, comprobante_nombre = ?,
                pago_estado = 'en revisión', pago_nota = '' WHERE id_solicitud = ?")->execute([$boleta, $fecha, round((float) $monto, 2), $ruta, $nombre, $s['id_solicitud']]);
            $codigo = 'comprobante';
        } elseif ($accion === 'validar_pago') {
            $soloAdmin();
            $s = $cargar((int) ($_POST['id'] ?? 0));
            if ($s['tipo_pago'] !== 'Propio' || $s['pago_estado'] !== 'en revisión') {
                throw new InvalidArgumentException('Solo se valida un comprobante que está por validar.');
            }
            if (abs((float) $s['monto_comprobante'] - (float) $s['monto']) > 0.009 && empty($_POST['confirmar_monto'])) {
                throw new InvalidArgumentException('El comprobante es por ' . cengi_ins_dinero($s['monto_comprobante'], $s['moneda']) . ' y la cuota es ' . cengi_ins_dinero($s['monto'], $s['moneda']) . '. Marca “Validar aunque el monto no coincida” para continuar.');
            }
            $db->prepare("UPDATE solicitudes_inscripcion SET pago_estado = 'validado', pago_verificado_por = ?, pago_verificado_en = NOW(), pago_nota = ? WHERE id_solicitud = ?")
                ->execute([$usuario, mb_substr(trim((string) ($_POST['nota'] ?? '')), 0, 500, 'UTF-8'), $s['id_solicitud']]);
            $planes[$s['planificacion_id']] = true;
            $codigo = 'validado';
        } elseif ($accion === 'rechazar_pago') {
            $soloAdmin();
            $s = $cargar((int) ($_POST['id'] ?? 0));
            $nota = mb_substr(trim((string) ($_POST['nota'] ?? '')), 0, 500, 'UTF-8');
            if ($nota === '') {
                throw new InvalidArgumentException('Escribe el motivo del rechazo: se le comunica al participante.');
            }
            $db->prepare("UPDATE solicitudes_inscripcion SET pago_estado = 'rechazado', pago_verificado_por = ?, pago_verificado_en = NOW(), pago_nota = ? WHERE id_solicitud = ?")
                ->execute([$usuario, $nota, $s['id_solicitud']]);
            $avisos[] = [$s, 'Comprobante de pago no válido: ' . $s['plan_nombre'], 'Revisamos tu comprobante de pago' . ($s['boleta'] ? ' (boleta ' . $s['boleta'] . ')' : '') . " y no pudimos validarlo:\n" . $nota
                . "\n\nPor favor envía un comprobante válido respondiendo este correo para completar tu inscripción."];
            $codigo = 'rechazado';
        } elseif ($accion === 'marcar_pagado') {
            $soloAdmin();
            $s = $cargar((int) ($_POST['id'] ?? 0));
            if ($s['tipo_pago'] !== 'Propio' || !in_array($s['pago_estado'], ['validado', 'en revisión', 'pendiente'], true)) {
                throw new InvalidArgumentException('Este pago no se puede marcar como pagado.');
            }
            [$forma, $ref, $fecha] = $pago();
            $db->prepare("UPDATE solicitudes_inscripcion SET pago_estado = 'pagado', pago_forma = ?, pago_referencia = ?, pago_fecha = COALESCE(pago_fecha, ?),
                pago_registrado_en = NOW(), pago_registrado_por = ?, pago_verificado_por = IF(pago_verificado_por = '', ?, pago_verificado_por),
                pago_verificado_en = COALESCE(pago_verificado_en, NOW()) WHERE id_solicitud = ?")->execute([$forma, $ref, $fecha, $usuario, $usuario, $s['id_solicitud']]);
            $planes[$s['planificacion_id']] = true;
            $codigo = 'pagado';
        } elseif ($accion === 'reembolsar') {
            $soloAdmin();
            $s = $cargar((int) ($_POST['id'] ?? 0));
            $ref = mb_substr(trim((string) ($_POST['nota'] ?? '')), 0, 400, 'UTF-8');
            $db->prepare("UPDATE solicitudes_inscripcion SET pago_estado = 'reembolsado', pago_nota = ? WHERE id_solicitud = ? AND tipo_pago = 'Propio'")
                ->execute(['Reembolso: ' . ($ref ?: 'sin referencia'), $s['id_solicitud']]);
            $codigo = 'reembolsado';
        } elseif ($accion === 'cuota') {
            $soloAdmin();
            $s = $cargar((int) ($_POST['id'] ?? 0));
            if ($s['grupo_pago_id']) {
                $stmt = $db->prepare('SELECT proforma_id FROM pagos_grupales WHERE id = ?');
                $stmt->execute([(int) $s['grupo_pago_id']]);
                if ($stmt->fetchColumn()) {
                    throw new InvalidArgumentException('La cuota de esta inscripción viene de su proforma: para cambiarla, anula la proforma y emite otra.');
                }
            }
            $cuota = array_key_exists($_POST['cuota'] ?? '', CENGI_INS_CUOTAS) ? $_POST['cuota'] : 'asociado';
            [$monto, $moneda] = cengi_ins_monto(cengi_pp_plan($db, $s['planificacion_id']), $cuota);
            $db->prepare('UPDATE solicitudes_inscripcion SET cuota = ?, monto = ?, moneda = ? WHERE id_solicitud = ?')->execute([$cuota, $monto, $moneda, $s['id_solicitud']]);
            cengi_ins_recalcular_grupo($db, $s['grupo_pago_id']);
            $codigo = 'cuota';
        } elseif (in_array($accion, ['grupo_guardar', 'grupo_pagado', 'grupo_rechazar'], true)) {
            $soloAdmin();
            $stmt = $db->prepare('SELECT g.*, i.nombre_ingenios FROM pagos_grupales g INNER JOIN ingenios i ON i.id = g.ingenio_id WHERE g.id = ? FOR UPDATE');
            $stmt->execute([(int) ($_POST['id'] ?? 0)]);
            $g = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$g) {
                throw new InvalidArgumentException('La orden ya no existe.');
            }
            if ($g['proforma_id']) {
                throw new InvalidArgumentException('Esta orden es de una proforma: registra el pago desde la proforma.');
            }
            if ($accion === 'grupo_guardar') {
                $orden = mb_substr(trim((string) ($_POST['orden'] ?? '')), 0, 80, 'UTF-8');
                [$ruta, $nombre] = !empty($_FILES['archivo']) && $_FILES['archivo']['error'] !== UPLOAD_ERR_NO_FILE
                    ? cengi_ins_guardar_comprobante($_FILES['archivo'], 'g' . (int) $g['id'])
                    : [$g['comprobante_ruta'], $g['comprobante_nombre']];
                $estado = $g['estado'] === 'pagado' ? 'pagado' : ($ruta && ($ruta !== $g['comprobante_ruta'] || in_array($g['estado'], ['pendiente', 'rechazado'], true)) ? 'en revisión' : $g['estado']);
                $db->prepare('UPDATE pagos_grupales SET orden = ?, comprobante_ruta = ?, comprobante_nombre = ?, estado = ? WHERE id = ?')->execute([$orden, $ruta, $nombre, $estado, $g['id']]);
                $codigo = 'grupo';
            } elseif ($accion === 'grupo_pagado') {
                [$forma, $ref, $fecha] = $pago();
                $db->prepare("UPDATE pagos_grupales SET estado = 'pagado', pago_forma = ?, pago_referencia = ?, pago_fecha = ?, verificado_por = ?, verificado_en = NOW() WHERE id = ?")
                    ->execute([$forma, $ref, $fecha, $usuario, $g['id']]);
                $codigo = 'grupo-pagado';
            } else {
                $nota = mb_substr(trim((string) ($_POST['nota'] ?? '')), 0, 500, 'UTF-8');
                if ($nota === '') {
                    throw new InvalidArgumentException('Escribe el motivo del rechazo.');
                }
                $db->prepare("UPDATE pagos_grupales SET estado = 'rechazado', nota = ?, verificado_por = ?, verificado_en = NOW() WHERE id = ?")->execute([$nota, $usuario, $g['id']]);
                $codigo = 'grupo-rechazado';
            }
        } elseif ($accion === 'solicitar_validacion') {
            $soloAdmin();
            $stmt = $db->query("SELECT s.*, i.nombre_ingenios, p.nombre AS plan_nombre, p.fecha_inicio FROM solicitudes_inscripcion s
                INNER JOIN ingenios i ON i.id = s.ingenio_id INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id
                WHERE s.tipo_pago = 'Ingenio' AND s.validacion_ingenio = 'pendiente' AND s.estado = 'Pendiente' ORDER BY i.nombre_ingenios, p.fecha_inicio");
            $porIngenio = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $porIngenio[$s['nombre_ingenios']][] = $s;
            }
            if (!$porIngenio) {
                throw new InvalidArgumentException('No hay inscripciones pendientes de validación de ingenios.');
            }
            $sinUsuario = [];
            $db->commit();
            foreach ($porIngenio as $ingenio => $lista) {
                $usuarios = cengi_ins_usuarios_ingenio($ingenio);
                if (!$usuarios) {
                    $sinUsuario[] = $ingenio;
                    continue;
                }
                $detalle = implode("\n", array_map(static function ($s) {
                    return '• ' . $s['nombre_participante'] . ' — ' . $s['plan_nombre'] . ' (inicia ' . cengi_pp_fecha($s['fecha_inicio']) . '), cuota ' . CENGI_INS_CUOTAS[$s['cuota']][0] . ' ' . cengi_ins_dinero($s['monto'], $s['moneda']);
                }, $lista));
                cengi_pp_enviar_comunicacion($db, ['categoria' => 'Validación del ingenio', 'origen' => 'sistema',
                    'asunto' => 'Validación de inscripciones y pago — ' . $ingenio,
                    'mensaje' => "Estimado(a) {nombre}:\n\nLas siguientes personas de {$ingenio} se inscribieron indicando que el ingenio cubre su pago. Por favor ingrese a Cengicursos → Pagos de inscripción y confirme si valida cada inscripción:\n{$detalle}\n\nUna vez validadas, cuentan para el cupo del curso y la Coordinación de Capacitación las asigna; Administración dará seguimiento al pago de la orden.\n\nCoordinación de Capacitación — CENGICAÑA"],
                    array_map(static function ($u) {
                        return ['nombre' => $u['nombre'], 'correo' => $u['correo']];
                    }, $usuarios));
                $ids = implode(',', array_map(static function ($s) {
                    return (int) $s['id_solicitud'];
                }, $lista));
                $db->exec("UPDATE solicitudes_inscripcion SET validacion_solicitada_en = NOW() WHERE id_solicitud IN ($ids)");
            }
            $_GET = array_diff_key($_GET, array_flip(['msg']));
            if ($sinUsuario) {
                $mensaje = cengi_pp_h('Solicitud enviada. Estos ingenios no tienen un usuario con rol de ingenio y correo en el sistema: ' . implode(', ', $sinUsuario) . '.');
                $mensajeTipo = 'warning';
            } else {
                cengi_pp_redirigir('pagos_inscripcion.php', ['msg' => 'solicitud']);
            }
            $codigo = '';
        } else {
            throw new InvalidArgumentException('Acción no válida.');
        }

        if ($codigo !== '') {
            foreach (array_keys($planes) as $planId) {
                cengi_ins_procesar_plan($db, $planId);
            }
            $db->commit();
            cengi_ins_enviar_cola($db);
            foreach ($avisos as [$s, $asunto, $texto]) {
                cengi_pi_avisar($db, $s, $asunto, $texto);
            }
            $_GET = array_diff_key($_GET, array_flip(['msg']));
            cengi_pp_redirigir('pagos_inscripcion.php', ['msg' => $codigo]);
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $GLOBALS['cengi_ins_cola'] = [];
        $mensajeTipo = 'error';
        if ($e instanceof InvalidArgumentException) {
            $mensaje = $e->getMessage();
        } else {
            error_log('Pagos de inscripcion: ' . $e->getMessage());
            $mensaje = 'No fue posible guardar los cambios.';
        }
    }
}

if ($mensaje === '' && isset(CENGI_PI_MENSAJES[$_GET['msg'] ?? ''])) {
    $mensaje = cengi_pp_h(CENGI_PI_MENSAJES[$_GET['msg']]);
}

// ---------------------------------------------------------------- datos
$tab = in_array($_GET['tab'] ?? '', ['grupos', 'proformas', 'resumen'], true) ? $_GET['tab'] : 'participantes';
$anio = (int) ($_GET['anio'] ?? cengi_pp_anio_actual());
$planFiltro = (int) ($_GET['plan'] ?? 0);
$pagador = in_array($_GET['pagador'] ?? '', ['Propio', 'Ingenio', 'Exonerado'], true) ? $_GET['pagador'] : '';
$etapaFiltro = in_array($_GET['etapa'] ?? '', ['por-validar', 'validado-sin-pago', 'pagado', 'rechazado', 'reembolsado'], true) ? $_GET['etapa'] : '';
$busqueda = trim((string) ($_GET['q'] ?? ''));

$sql = "SELECT s.*, i.nombre_ingenios, p.nombre AS plan_nombre, p.anio, p.fecha_inicio, p.cupo_minimo, p.estado AS plan_estado, p.curso_id AS plan_curso,
        p.costo_asociados, g.estado AS grupo_estado, g.orden AS grupo_orden, g.pago_forma AS grupo_forma, g.pago_referencia AS grupo_ref, g.pago_fecha AS grupo_fecha,
        g.verificado_por AS grupo_verificado_por, g.verificado_en AS grupo_verificado_en, pf.id AS pf_id, pf.numero AS pf_numero,
        cc.asistencia AS res_asistencia, cc.evaluacion AS res_pre, cc.posevaluacion AS res_post
    FROM solicitudes_inscripcion s
    INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id
    LEFT JOIN ingenios i ON i.id = s.ingenio_id
    LEFT JOIN pagos_grupales g ON g.id = s.grupo_pago_id
    LEFT JOIN proformas pf ON pf.id = g.proforma_id
    LEFT JOIN participantes pa ON pa.cui_participantes = s.cui_participante AND s.estado = 'Aprobado'
    LEFT JOIN asignaciones a ON a.participantes_id = pa.id AND a.cursos_id = p.curso_id AND a.estado_asignaciones = 1
    LEFT JOIN control_cursos cc ON cc.asignacion_id = a.id
    WHERE p.anio = ? AND s.estado <> 'Rechazado'" . ($planFiltro ? ' AND p.id = ' . $planFiltro : '') . "
    ORDER BY p.fecha_inicio, s.nombre_participante";
$stmt = $db->prepare($sql);
$stmt->execute([$anio]);
$base = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), static function ($s) use ($limitado) {
    return $limitado === null || cengi_texto_normalizado($s['nombre_ingenios'] ?? '') === cengi_texto_normalizado($limitado);
}));

function cengi_pi_etapa_pago(array $s)
{
    $cobro = cengi_ins_estado_cobro($s);
    if ($cobro === 'exonerado' || $cobro === 'reembolsado') {
        return $cobro;
    }
    if (!cengi_ins_requisito($s)['ok']) {
        return $s['pago_estado'] === 'rechazado' || $s['validacion_ingenio'] === 'rechazada' ? 'rechazado' : 'por-validar';
    }
    return $cobro === 'pagado' ? 'pagado' : 'validado-sin-pago';
}

$lista = array_values(array_filter($base, static function ($s) use ($pagador, $etapaFiltro, $busqueda) {
    if (($pagador !== '' && $s['tipo_pago'] !== $pagador) || ($etapaFiltro !== '' && cengi_pi_etapa_pago($s) !== $etapaFiltro)) {
        return false;
    }
    if ($busqueda === '') {
        return true;
    }
    $texto = cengi_texto_normalizado(implode(' ', [$s['nombre_participante'], $s['correo'], $s['cui_participante'], $s['nombre_ingenios'], $s['institucion_otra'], $s['plan_nombre'], $s['boleta'], $s['grupo_orden'], $s['pf_numero'] ? 'proforma ' . $s['pf_numero'] : '']));
    $digitos = preg_replace('/\D/', '', $busqueda);
    return strpos($texto, cengi_texto_normalizado($busqueda)) !== false
        || (strlen($digitos) >= 3 && strpos(preg_replace('/\D/', '', $s['telefono'] . ' ' . $s['cui_participante'] . ' ' . $s['boleta']), $digitos) !== false);
}));
usort($lista, static function ($a, $b) {
    $orden = ['por-validar' => 0, 'rechazado' => 1, 'validado-sin-pago' => 2, 'pagado' => 3, 'exonerado' => 4, 'reembolsado' => 5];
    return [$orden[cengi_pi_etapa_pago($a)], $a['fecha_inicio'], $a['nombre_participante']] <=> [$orden[cengi_pi_etapa_pago($b)], $b['fecha_inicio'], $b['nombre_participante']];
});

if (($_GET['exportar'] ?? '') === 'csv') {
    $filas = [['Participante', 'CUI', 'Correo', 'Teléfono', 'Ingenio / institución', 'Curso', 'Estado de inscripción', 'Quién paga', 'Cuota', 'Monto', 'Moneda',
        'Descuento', 'Orden del ingenio', 'Proforma', 'Boleta', 'Fecha de pago', 'Monto del comprobante', 'Validación', 'Validado por', 'Estado del cobro', 'Forma de pago', 'Referencia', 'Nota']];
    foreach ($lista as $s) {
        $filas[] = [$s['nombre_participante'], $s['cui_participante'], $s['correo'], $s['telefono'], $s['nombre_ingenios'] ?: $s['institucion_otra'], $s['plan_nombre'],
            CENGI_INS_ETAPAS[cengi_ins_etapa($s)][0], $s['tipo_pago'], CENGI_INS_CUOTAS[$s['cuota']][0] ?? '', $s['monto'], $s['moneda'], $s['descuento'], $s['grupo_orden'], $s['pf_numero'], $s['boleta'],
            $s['pago_fecha'], $s['monto_comprobante'], cengi_ins_requisito($s)['texto'], $s['validacion_ingenio_por'] ?: $s['pago_verificado_por'],
            CENGI_INS_ESTADOS_PAGO[cengi_ins_estado_cobro($s)][0], $s['tipo_pago'] === 'Ingenio' ? $s['grupo_forma'] : $s['pago_forma'],
            $s['tipo_pago'] === 'Ingenio' ? $s['grupo_ref'] : $s['pago_referencia'], $s['pago_nota'] ?: $s['validacion_ingenio_nota']];
    }
    cengi_pp_csv('Pagos_inscripcion_anio_' . cengi_pp_anio_texto($anio), $filas);
}

// KPIs
$conCosto = array_filter($base, static function ($s) {
    return !in_array(cengi_ins_estado_cobro($s), ['exonerado', 'reembolsado'], true);
});
$sumaQ = static function (array $filas) {
    return array_sum(array_map(static function ($s) {
        return cengi_ins_en_quetzales($s['monto'], $s['moneda']);
    }, $filas));
};
$porValIngenio = count(array_filter($base, static function ($s) {
    return $s['tipo_pago'] === 'Ingenio' && $s['validacion_ingenio'] === 'pendiente';
}));
$porValAdmin = count(array_filter($base, static function ($s) {
    return $s['tipo_pago'] === 'Propio' && $s['pago_estado'] === 'en revisión';
}));
$validadoSinPago = array_filter($conCosto, static function ($s) {
    return cengi_pi_etapa_pago($s) === 'validado-sin-pago';
});
$pagados = array_filter($conCosto, static function ($s) {
    return cengi_ins_estado_cobro($s) === 'pagado';
});

// Pagos grupales
$stmt = $db->prepare("SELECT g.*, i.nombre_ingenios, p.nombre AS plan_nombre, pf.numero AS pf_numero, pf.estado AS pf_estado,
        (SELECT COUNT(*) FROM solicitudes_inscripcion s WHERE s.grupo_pago_id = g.id AND s.estado <> 'Rechazado') AS miembros,
        (SELECT COUNT(*) FROM solicitudes_inscripcion s WHERE s.grupo_pago_id = g.id AND s.estado <> 'Rechazado' AND s.validacion_ingenio = 'validada') AS validados,
        (SELECT GROUP_CONCAT(s.nombre_participante ORDER BY s.nombre_participante SEPARATOR ', ') FROM solicitudes_inscripcion s WHERE s.grupo_pago_id = g.id AND s.estado <> 'Rechazado') AS nombres,
        (SELECT GROUP_CONCAT(s.id_solicitud) FROM solicitudes_inscripcion s WHERE s.grupo_pago_id = g.id AND s.estado <> 'Rechazado' AND s.validacion_ingenio = 'pendiente') AS pendientes
    FROM pagos_grupales g INNER JOIN ingenios i ON i.id = g.ingenio_id INNER JOIN planificacion_cursos p ON p.id = g.planificacion_id LEFT JOIN proformas pf ON pf.id = g.proforma_id
    WHERE p.anio = ?" . ($planFiltro ? ' AND p.id = ' . $planFiltro : '') . ' ORDER BY g.creado DESC');
$stmt->execute([$anio]);
$coincide = static function (array $campos) use ($busqueda) {
    return $busqueda === '' || strpos(cengi_texto_normalizado(implode(' ', $campos)), cengi_texto_normalizado($busqueda)) !== false;
};
$grupos = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), static function ($g) use ($limitado, $coincide) {
    return ($g['miembros'] > 0 || $g['proforma_id'])
        && ($limitado === null || cengi_texto_normalizado($g['nombre_ingenios']) === cengi_texto_normalizado($limitado))
        && $coincide([$g['nombre_ingenios'], $g['plan_nombre'], $g['orden'], $g['nombres'], $g['pago_referencia']]);
}));

// Proformas con al menos un curso del año (y del curso filtrado).
$stmt = $db->prepare("SELECT pf.*, i.nombre_ingenios AS empresa,
        (SELECT SUM(l.cantidad) FROM proforma_lineas l WHERE l.proforma_id = pf.id) AS cantidad,
        (SELECT COUNT(*) FROM proforma_lineas l INNER JOIN solicitudes_inscripcion s ON s.grupo_pago_id = l.grupo_pago_id AND s.estado <> 'Rechazado' WHERE l.proforma_id = pf.id) AS amarrados,
        (SELECT GROUP_CONCAT(l.descripcion ORDER BY l.id SEPARATOR ', ') FROM proforma_lineas l WHERE l.proforma_id = pf.id) AS cursos,
        (SELECT MAX(l.desc_unit) FROM proforma_lineas l WHERE l.proforma_id = pf.id) AS con_descuento
    FROM proformas pf INNER JOIN ingenios i ON i.id = pf.ingenio_id
    WHERE EXISTS (SELECT 1 FROM proforma_lineas l INNER JOIN planificacion_cursos p ON p.id = l.planificacion_id
        WHERE l.proforma_id = pf.id AND p.anio = ?" . ($planFiltro ? ' AND p.id = ' . $planFiltro : '') . ')
    ORDER BY pf.numero DESC');
$stmt->execute([$anio]);
$proformas = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), static function ($pf) use ($limitado, $coincide) {
    return ($limitado === null || cengi_texto_normalizado($pf['empresa']) === cengi_texto_normalizado($limitado))
        && $coincide(['n ' . $pf['numero'], $pf['numero'], $pf['empresa'], $pf['dirigido'], $pf['cursos'], $pf['pago_referencia']]);
}));
$proformasActivas = count(array_filter($proformas, static function ($pf) {
    return in_array(cengi_pf_estado($pf), ['emitida', 'enviada'], true);
}));

// Resumen por curso e ingenio
$resumenCursos = [];
$resumenIngenios = [];
foreach ($base as $s) {
    $r = &$resumenCursos[$s['planificacion_id']];
    $r ??= ['nombre' => $s['plan_nombre'], 'estado' => $s['plan_estado'], 'curso' => $s['plan_curso'], 'cupo' => (int) $s['cupo_minimo'], 'inscritos' => 0, 'pagados' => 0, 'esperado' => 0, 'cobrado' => 0,
        'cuota' => (float) $s['costo_asociados']];
    $r['inscritos']++;
    $cobro = cengi_ins_estado_cobro($s);
    if (!in_array($cobro, ['exonerado', 'reembolsado'], true)) {
        $r['esperado'] += cengi_ins_en_quetzales($s['monto'], $s['moneda']);
        if ($cobro === 'pagado') {
            $r['pagados']++;
            $r['cobrado'] += cengi_ins_en_quetzales($s['monto'], $s['moneda']);
        }
    }
    unset($r);
    $k = $s['nombre_ingenios'] ?: ($s['institucion_otra'] ?: 'Sin institución');
    $ri = &$resumenIngenios[$k];
    $ri ??= ['participantes' => 0, 'ingenio' => 0, 'propio' => 0, 'asistencia' => [], 'mejora' => [], 'conNota' => 0, 'aprobados' => 0];
    $ri['participantes']++;
    if ($cobro === 'pagado') {
        $ri[$s['tipo_pago'] === 'Ingenio' ? 'ingenio' : 'propio'] += cengi_ins_en_quetzales($s['monto'], $s['moneda']);
    }
    // Resultados de quienes ya estan asignados al curso (Seguimiento por curso).
    if (is_numeric($s['res_asistencia'])) {
        $ri['asistencia'][] = (float) $s['res_asistencia'];
    }
    if (is_numeric($s['res_pre']) && is_numeric($s['res_post'])) {
        $ri['mejora'][] = (float) $s['res_post'] - (float) $s['res_pre'];
    }
    if (is_numeric($s['res_post'])) {
        $ri['conNota']++;
        $ri['aprobados'] += (float) $s['res_post'] >= CENGI_NOTA_APROBACION ? 1 : 0;
    }
    unset($ri);
}
// Cupos de proformas pagadas que aun no tienen nombre: el dinero ya ingreso.
$stmt = $db->prepare("SELECT l.planificacion_id, i.nombre_ingenios, pf.moneda,
        (l.cantidad - (SELECT COUNT(*) FROM solicitudes_inscripcion s WHERE s.grupo_pago_id = l.grupo_pago_id AND s.estado <> 'Rechazado')) * (l.precio - l.desc_unit) AS monto
    FROM proforma_lineas l INNER JOIN proformas pf ON pf.id = l.proforma_id INNER JOIN ingenios i ON i.id = pf.ingenio_id
    INNER JOIN planificacion_cursos p ON p.id = l.planificacion_id
    WHERE pf.estado = 'pagada' AND p.anio = ?" . ($planFiltro ? ' AND p.id = ' . $planFiltro : ''));
$stmt->execute([$anio]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $montoQ = cengi_ins_en_quetzales(max(0, (float) $c['monto']), $c['moneda']);
    if ($montoQ <= 0 || ($limitado !== null && cengi_texto_normalizado($c['nombre_ingenios']) !== cengi_texto_normalizado($limitado))) {
        continue;
    }
    if (isset($resumenCursos[$c['planificacion_id']])) {
        $resumenCursos[$c['planificacion_id']]['esperado'] += $montoQ;
        $resumenCursos[$c['planificacion_id']]['cobrado'] += $montoQ;
        $resumenCursos[$c['planificacion_id']]['cuposPagados'] = ($resumenCursos[$c['planificacion_id']]['cuposPagados'] ?? 0) + $montoQ;
    }
    $resumenIngenios[$c['nombre_ingenios']] ??= ['participantes' => 0, 'ingenio' => 0, 'propio' => 0, 'asistencia' => [], 'mejora' => [], 'conNota' => 0, 'aprobados' => 0];
    $resumenIngenios[$c['nombre_ingenios']]['ingenio'] += $montoQ;
}

if ($resumenCursos) {
    // Costo de instructores: liquidaciones (no anuladas) + estimado de sesiones aun sin liquidar.
    $ids = implode(',', array_map('intval', array_keys($resumenCursos)));
    $liquidado = $db->query("SELECT d.planificacion_id, SUM(d.total) FROM liquidacion_detalle d INNER JOIN liquidaciones l ON l.id = d.liquidacion_id
        WHERE l.estado <> 'anulada' AND d.planificacion_id IN ($ids) GROUP BY d.planificacion_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $estimado = $db->query("SELECT s.planificacion_id, SUM(COALESCE(t.tarifa, i.tarifa_sesion, 0) * (1 + " . CENGI_PP_IMPUESTO . ")) FROM curso_sesiones s
        INNER JOIN instructores i ON i.id = s.instructor_id LEFT JOIN tarifas_instructor_plan t ON t.instructor_id = s.instructor_id AND t.planificacion_id = s.planificacion_id
        WHERE s.estado <> 'cancelada' AND s.liquidacion_id IS NULL AND s.planificacion_id IN ($ids) GROUP BY s.planificacion_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($resumenCursos as $id => &$r) {
        $r['costo'] = (float) ($liquidado[$id] ?? 0) + (float) ($estimado[$id] ?? 0);
    }
    unset($r);
}

$gruposPlanes = cengi_pp_planes_opciones($db, true);
$filtros = array_filter(['tab' => $tab === 'participantes' ? '' : $tab, 'anio' => $anio, 'plan' => $planFiltro ?: '', 'pagador' => $pagador, 'etapa' => $etapaFiltro, 'q' => $busqueda]);

function cengi_pi_datos(array $s)
{
    return cengi_pp_h(json_encode([
        'id' => (int) $s['id_solicitud'], 'nombre' => $s['nombre_participante'], 'curso' => $s['plan_nombre'], 'ingenio' => $s['nombre_ingenios'] ?: $s['institucion_otra'],
        'monto' => cengi_ins_dinero($s['monto'], $s['moneda']), 'montoNum' => (float) $s['monto'], 'cuota' => $s['cuota'], 'boleta' => $s['boleta'], 'fecha' => $s['pago_fecha'],
        'montoComp' => $s['monto_comprobante'] !== null ? (float) $s['monto_comprobante'] : null, 'comprobante' => $s['comprobante_ruta'] ? 'comprobante_inscripcion.php?s=' . (int) $s['id_solicitud'] : '',
        'estado' => $s['pago_estado'], 'nota' => $s['pago_nota'],
    ], JSON_UNESCAPED_UNICODE));
}

/** Historial del pago de una inscripcion (modal "Ver detalle"): [fecha, texto, detalle]. */
function cengi_pi_historial(array $s)
{
    $f = static function ($ts) {
        return $ts ? date('d/m/Y H:i', strtotime($ts)) : '';
    };
    $h = [[$f($s['creado']), 'Inscripción recibida', ['Ingenio' => 'Paga su ingenio', 'Propio' => 'Pago propio', 'Exonerado' => 'Sin costo'][$s['tipo_pago']] . ' · cuota '
        . mb_strtolower(CENGI_INS_CUOTAS[$s['cuota']][0] ?? '', 'UTF-8') . ' ' . cengi_ins_dinero($s['monto'], $s['moneda']) . ($s['descuento'] ? ' (descuento ' . cengi_ins_dinero($s['descuento'], $s['moneda']) . ')' : '')]];
    if ($s['pf_numero']) {
        $h[] = ['', 'En proforma N.° ' . $s['pf_numero'], $s['grupo_orden']];
    }
    if ($s['validacion_solicitada_en']) {
        $h[] = [$f($s['validacion_solicitada_en']), 'Validación solicitada al ingenio', ''];
    }
    if ($s['validacion_ingenio_en']) {
        $h[] = [$f($s['validacion_ingenio_en']), $s['validacion_ingenio'] === 'validada' ? 'Validada por el ingenio' : 'El ingenio no la validó', trim($s['validacion_ingenio_por'] . ($s['validacion_ingenio_nota'] ? ' — ' . $s['validacion_ingenio_nota'] : ''))];
    }
    if ($s['boleta']) {
        $h[] = [$s['pago_fecha'] ? cengi_pp_fecha($s['pago_fecha']) : '', 'Comprobante', 'Boleta ' . $s['boleta'] . ($s['monto_comprobante'] !== null ? ' · ' . cengi_ins_dinero($s['monto_comprobante'], $s['moneda']) : '')];
    }
    if ($s['pago_verificado_en']) {
        $h[] = [$f($s['pago_verificado_en']), $s['pago_estado'] === 'rechazado' ? 'Comprobante rechazado' : 'Comprobante validado', trim($s['pago_verificado_por'] . ($s['pago_nota'] ? ' — ' . $s['pago_nota'] : ''))];
    }
    if ($s['pago_registrado_en']) {
        $h[] = [$f($s['pago_registrado_en']), 'Marcado como pagado', trim($s['pago_forma'] . ' ' . $s['pago_referencia'] . ' · ' . $s['pago_registrado_por'])];
    }
    if ($s['tipo_pago'] === 'Ingenio' && $s['grupo_estado'] === 'pagado') {
        $h[] = [$f($s['grupo_verificado_en']), 'Orden del ingenio pagada', trim($s['grupo_forma'] . ' ' . $s['grupo_ref'] . ' · ' . $s['grupo_verificado_por'])];
    }
    if ($s['pago_estado'] === 'reembolsado') {
        $h[] = ['', 'Reembolsado', $s['pago_nota']];
    }
    if ($s['asignado_en']) {
        $h[] = [$f($s['asignado_en']), 'Asignado al curso', $s['asignado_por']];
    }
    return $h;
}
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head('Pagos de inscripción'); ?></head>
<body class="cengi-canvas">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page">
    <nav class="pp-subnav">
        <a href="inscripciones.php"><span class="glyphicon glyphicon-inbox"></span>Inscripciones</a>
        <a href="pagos_inscripcion.php" class="is-active" aria-current="page"><span class="glyphicon glyphicon-credit-card"></span>Pagos de inscripción</a>
        <a href="planificacion_cursos.php"><span class="glyphicon glyphicon-calendar"></span>Planificación</a>
    </nav>
    <?php cengi_pp_feedback($mensaje, $mensajeTipo); ?>

    <div class="cengi-feedback is-warning" style="margin-bottom:16px;"><div class="cengi-feedback-icon"><span class="glyphicon glyphicon-info-sign"></span></div>
        <div><p><b>① Validación:</b> si paga el ingenio, lo valida el usuario del ingenio; si paga por su cuenta, Administración valida el comprobante. Lo validado cuenta para el cupo: al llegar al <b>cupo mínimo</b> el curso pasa a Cursos y se asignan los participantes. <b>② Seguimiento del cobro:</b> Administración marca como <b>pagado</b> el pago propio o la orden del ingenio cuando se confirma el ingreso.<?php echo $limitado !== null ? ' <b>Ves solo las inscripciones de ' . cengi_pp_h($limitado) . '.</b>' : ''; ?></p></div></div>

    <div class="cengi-kpi-grid">
        <?php
        echo cengi_pp_kpi($porValIngenio + $porValAdmin, 'Por validar', $porValIngenio . ' del ingenio · ' . $porValAdmin . ' comprobantes para Administración', ($porValIngenio + $porValAdmin) ? '#B34E00' : '#5B6459', 'file', ($porValIngenio + $porValAdmin) ? '#FFE9D9' : '#EDEFEA');
        echo cengi_pp_kpi(cengi_pp_q($sumaQ($validadoSinPago)), 'Validado, falta marcar pagado', count($validadoSinPago) . ' participante(s)', '#8A6600', 'time', '#FFF6DA');
        echo cengi_pp_kpi(cengi_pp_q($sumaQ($pagados)), 'Pagado', count($pagados) . ' de ' . count($conCosto) . ' con costo', '#1f5632', 'ok');
        echo cengi_pp_kpi(cengi_pp_q($sumaQ($conCosto)), 'Ingreso esperado', 'Cuotas en US$ a Q' . CENGI_INS_TIPO_CAMBIO, '#3E7A12', 'usd');
        ?>
    </div>

    <section class="pp-card">
        <nav class="pp-tabs">
            <?php foreach (['participantes' => 'Por participante', 'grupos' => 'Pagos de ingenios (grupales)', 'proformas' => 'Proformas' . ($proformasActivas ? ' (' . $proformasActivas . ')' : ''), 'resumen' => 'Resumen y beneficio por curso'] as $clave => $texto): ?>
                <a href="pagos_inscripcion.php?<?php echo cengi_pp_h(http_build_query(array_merge($filtros, ['tab' => $clave === 'participantes' ? null : $clave]))); ?>" class="<?php echo $tab === $clave ? 'is-active' : ''; ?>"><?php echo $texto; ?></a>
            <?php endforeach; ?>
        </nav>
        <form class="pp-toolbar" method="get">
            <?php if ($tab !== 'participantes'): ?><input type="hidden" name="tab" value="<?php echo $tab; ?>"><?php endif; ?>
            <div class="pp-toolbar-group">
                <select name="anio" aria-label="Año" onchange="this.form.plan.value='';this.form.submit()">
                    <?php foreach (cengi_pp_anios_disponibles($db, $anio) as $z): ?><option value="<?php echo $z; ?>" <?php echo $z === $anio ? 'selected' : ''; ?>>Año <?php echo cengi_pp_anio_texto($z); ?></option><?php endforeach; ?>
                </select>
                <select name="plan" aria-label="Curso" onchange="this.form.submit()">
                    <option value="">Todos los cursos</option>
                    <?php echo cengi_pp_options_planes(array_intersect_key($gruposPlanes, [$anio => 1]), (string) $planFiltro); ?>
                </select>
                <?php if ($tab === 'participantes'): ?>
                    <select name="pagador" aria-label="Quién paga" onchange="this.form.submit()">
                        <option value="">Quién paga: todos</option>
                        <?php foreach (['Propio' => 'Pago propio', 'Ingenio' => 'Pago del ingenio', 'Exonerado' => 'Sin costo'] as $v => $t): ?><option value="<?php echo $v; ?>" <?php echo $pagador === $v ? 'selected' : ''; ?>><?php echo $t; ?></option><?php endforeach; ?>
                    </select>
                    <select name="etapa" aria-label="Etapa" onchange="this.form.submit()">
                        <option value="">Todas las etapas</option>
                        <?php foreach (['por-validar' => 'Por validar', 'validado-sin-pago' => 'Validado, falta marcar pagado', 'pagado' => 'Pagado', 'rechazado' => 'Rechazado / no validado', 'reembolsado' => 'Reembolsado'] as $v => $t): ?><option value="<?php echo $v; ?>" <?php echo $etapaFiltro === $v ? 'selected' : ''; ?>><?php echo $t; ?></option><?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <?php if ($tab !== 'resumen'): ?>
                    <input type="search" name="q" value="<?php echo cengi_pp_h($busqueda); ?>" placeholder="<?php echo cengi_pp_h(['participantes' => 'Nombre, CUI, correo, teléfono, boleta, orden o proforma...', 'grupos' => 'Ingenio, curso, orden o participante...', 'proformas' => 'Número, ingenio, contacto o curso...'][$tab]); ?>" aria-label="Buscar" style="min-width:260px;">
                    <button type="submit" class="btn btn-default btn-sm"><span class="glyphicon glyphicon-search"></span></button>
                <?php endif; ?>
            </div>
            <div class="pp-toolbar-group">
                <?php if ($tab === 'participantes'): ?><span class="pp-sub"><?php echo count($lista); ?> participante(s)</span>
                    <a class="btn btn-default btn-sm" href="pagos_inscripcion.php?<?php echo cengi_pp_h(http_build_query($filtros + ['exportar' => 'csv'])); ?>"><span class="glyphicon glyphicon-download-alt"></span> Exportar</a><?php endif; ?>
                <?php if ($puedeValidarPagos && in_array($tab, ['participantes', 'grupos'], true)): ?>
                    <button type="submit" form="solicitar-form" class="btn btn-default btn-sm" <?php echo $porValIngenio ? '' : 'disabled'; ?>>Solicitar validación a ingenios (<?php echo $porValIngenio; ?>)</button>
                <?php endif; ?>
                <?php if ($puedeValidarPagos && $tab === 'proformas'): ?>
                    <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#cuentas-modal"><span class="glyphicon glyphicon-piggy-bank"></span> Cuentas bancarias</button>
                <?php endif; ?>
                <?php if ($puedeValidarPagos && $tab !== 'resumen'): ?>
                    <a class="btn btn-primary btn-sm" href="proforma.php?<?php echo cengi_pp_h(http_build_query(array_filter(['nueva' => 1, 'plan' => $planFiltro ?: null]))); ?>"><span class="glyphicon glyphicon-file"></span> Nueva proforma</a>
                <?php endif; ?>
            </div>
        </form>
        <form method="post" id="solicitar-form" onsubmit="return confirm('Se enviará un correo a los usuarios de cada ingenio con sus inscripciones pendientes. ¿Continuar?');"><input type="hidden" name="accion" value="solicitar_validacion"></form>
    </section>

    <?php if ($tab === 'participantes'): ?>
        <section class="cengi-courses-section"><div class="pp-table-wrap">
            <table class="cengi-org-table" style="min-width:1100px;">
                <thead><tr><th>Participante</th><th>Curso</th><th>Quién paga · monto</th><th>① Validación</th><th>② Seguimiento del cobro</th><th class="is-actions"></th></tr></thead>
                <tbody>
                    <?php if (!$lista): ?><tr><td colspan="6" class="pp-empty"><?php echo $base ? 'Nadie coincide con esos filtros.' : 'Aún no hay inscripciones en las convocatorias de este año.'; ?></td></tr><?php endif; ?>
                    <?php foreach ($lista as $s):
                        $req = cengi_ins_requisito($s);
                        $cobro = cengi_ins_estado_cobro($s);
                        $etapa = cengi_ins_etapa($s);
                        $puedeIngenio = $s['tipo_pago'] === 'Ingenio' && $s['validacion_ingenio'] !== 'validada' && $s['estado'] !== 'Aprobado' && cengi_ins_puede_validar_ingenio($s['nombre_ingenios']);
                    ?>
                        <tr>
                            <td style="min-width:210px;"><strong><?php echo cengi_pp_h($s['nombre_participante']); ?></strong>
                                <span class="pp-sub"><?php echo cengi_pp_h(($s['nombre_ingenios'] ?: $s['institucion_otra']) . ' · ' . $s['correo']); ?></span>
                                <span class="pp-sub"><?php echo cengi_pp_h('CUI ' . $s['cui_participante'] . ' · Tel. ' . $s['telefono']); ?></span></td>
                            <td style="min-width:150px;"><?php echo cengi_pp_h($s['plan_nombre']); ?>
                                <span class="pp-sub"><?php echo cengi_pp_badge(CENGI_INS_ETAPAS, $etapa); ?></span>
                                <?php if ($s['estado'] === 'Aprobado'): ?><span class="pp-sub" style="color:#1f5632;font-weight:600;">Asignado al curso</span>
                                <?php elseif ($req['ok'] && in_array($s['estado'], ['Pendiente', 'Espera'], true)): ?><span class="pp-sub"><a class="pp-link" href="inscripciones.php?<?php echo cengi_pp_h(http_build_query(['anio' => $anio, 'plan' => $s['planificacion_id']])); ?>"><?php echo $s['estado'] === 'Espera' ? 'En lista de espera' : 'En Inscripciones: por asignar'; ?></a></span>
                                <?php else: ?><span class="pp-sub">Aún no pasa a Inscripciones</span><?php endif; ?></td>
                            <td class="pp-num" style="font-size:12px;"><?php echo ['Ingenio' => 'Su ingenio', 'Exonerado' => 'Sin costo', 'Propio' => 'Pago propio'][$s['tipo_pago']]; ?>
                                <span class="pp-sub">Cuota <?php echo cengi_pp_h(mb_strtolower(CENGI_INS_CUOTAS[$s['cuota']][0] ?? '', 'UTF-8')); ?></span>
                                <b><?php echo cengi_ins_dinero($s['monto'], $s['moneda']); ?></b>
                                <?php if ($s['descuento']): ?><span class="pp-sub">descuento <?php echo cengi_ins_dinero($s['descuento'], $s['moneda']); ?></span><?php endif; ?></td>
                            <td style="font-size:12px;min-width:190px;">
                                <span class="pp-chip <?php echo $req['ok'] ? 'is-ok' : ($s['validacion_ingenio'] === 'rechazada' || $s['pago_estado'] === 'rechazado' ? 'is-danger' : 'is-yellow'); ?>"><?php echo cengi_pp_h($req['ok'] ? ($s['tipo_pago'] === 'Exonerado' ? 'Sin costo' : ($s['tipo_pago'] === 'Ingenio' ? 'Validado por el ingenio' : 'Validado por Administración')) : $req['texto']); ?></span>
                                <?php if ($s['validacion_ingenio_por'] && $s['tipo_pago'] === 'Ingenio'): ?><span class="pp-sub"><?php echo cengi_pp_h($s['validacion_ingenio_por'] . ' · ' . date('d/m/Y H:i', strtotime($s['validacion_ingenio_en']))); ?></span><?php endif; ?>
                                <?php if ($s['validacion_ingenio_nota']): ?><span class="pp-sub"><i><?php echo cengi_pp_h($s['validacion_ingenio_nota']); ?></i></span><?php endif; ?>
                                <?php if ($s['validacion_solicitada_en'] && $s['validacion_ingenio'] === 'pendiente'): ?><span class="pp-sub">Solicitada <?php echo date('d/m H:i', strtotime($s['validacion_solicitada_en'])); ?></span><?php endif; ?>
                                <?php if ($s['tipo_pago'] === 'Propio'): ?>
                                    <?php if ($s['comprobante_ruta']): ?><span class="pp-sub"><a target="_blank" rel="noopener" href="comprobante_inscripcion.php?s=<?php echo (int) $s['id_solicitud']; ?>">Ver comprobante</a> · boleta <span class="pp-mono"><?php echo cengi_pp_h($s['boleta']); ?></span></span>
                                        <?php if ($s['monto_comprobante'] !== null && abs((float) $s['monto_comprobante'] - (float) $s['monto']) > 0.009): ?><span class="pp-sub" style="color:#B23223;font-weight:700;">Comprobante por <?php echo cengi_ins_dinero($s['monto_comprobante'], $s['moneda']); ?> — no coincide</span><?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($s['pago_verificado_por']): ?><span class="pp-sub"><?php echo cengi_pp_h($s['pago_verificado_por']); ?></span><?php endif; ?>
                                    <?php if ($s['pago_nota']): ?><span class="pp-sub"><i><?php echo cengi_pp_h($s['pago_nota']); ?></i></span><?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td style="min-width:160px;"><?php echo cengi_pp_badge(CENGI_INS_ESTADOS_PAGO, $cobro); ?>
                                <?php if ($s['tipo_pago'] === 'Ingenio' && $s['pf_id']): ?><span class="pp-sub"><a class="pp-link" href="proforma.php?id=<?php echo (int) $s['pf_id']; ?>">Proforma N.° <?php echo (int) $s['pf_numero']; ?></a><?php echo $s['grupo_ref'] ? ' · ' . cengi_pp_h($s['grupo_forma'] . ' ' . $s['grupo_ref']) : ''; ?></span>
                                <?php elseif ($s['tipo_pago'] === 'Ingenio'): ?><span class="pp-sub">Orden <span class="pp-mono"><?php echo cengi_pp_h($s['grupo_orden'] ?: 'sin número'); ?></span><?php echo $s['grupo_ref'] ? ' · ' . cengi_pp_h($s['grupo_forma'] . ' ' . $s['grupo_ref']) : ''; ?></span>
                                <?php elseif ($s['pago_referencia']): ?><span class="pp-sub"><?php echo cengi_pp_h($s['pago_forma'] . ' ' . $s['pago_referencia'] . ' · ' . cengi_pp_fecha($s['pago_fecha'])); ?></span><?php endif; ?>
                            </td>
                            <td class="is-actions"><div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;min-width:140px;">
                                <?php if ($puedeIngenio): ?><button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#ingenio-modal" data-ids="<?php echo (int) $s['id_solicitud']; ?>" data-titulo="<?php echo cengi_pp_h($s['nombre_participante'] . ' · ' . $s['plan_nombre']); ?>">Validación del ingenio</button><?php endif; ?>
                                <?php if ($puedeValidarPagos && $s['tipo_pago'] === 'Propio'): ?>
                                    <?php if ($s['pago_estado'] === 'en revisión'): ?><button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#pago-modal" data-s="<?php echo cengi_pi_datos($s); ?>">Validar comprobante</button>
                                    <?php elseif (in_array($s['pago_estado'], ['pendiente', 'rechazado'], true)): ?><button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#comprobante-modal" data-s="<?php echo cengi_pi_datos($s); ?>">Registrar comprobante</button><?php endif; ?>
                                    <?php if (in_array($s['pago_estado'], ['validado', 'en revisión', 'pendiente'], true)): ?><button type="button" class="btn btn-<?php echo $s['pago_estado'] === 'validado' ? 'primary' : 'default'; ?> btn-sm" data-toggle="modal" data-target="#pagado-modal" data-accion="marcar_pagado" data-id="<?php echo (int) $s['id_solicitud']; ?>" data-titulo="<?php echo cengi_pp_h($s['nombre_participante'] . ' · ' . cengi_ins_dinero($s['monto'], $s['moneda'])); ?>" data-ref="<?php echo cengi_pp_h($s['boleta']); ?>">Marcar pagado</button><?php endif; ?>
                                    <?php if (in_array($s['pago_estado'], ['pagado', 'validado'], true)): ?><button type="button" class="btn btn-link btn-sm" data-toggle="modal" data-target="#nota-modal" data-accion="reembolsar" data-id="<?php echo (int) $s['id_solicitud']; ?>" data-titulo="Registrar reembolso" data-label="Referencia del reembolso">Reembolso</button><?php endif; ?>
                                <?php endif; ?>
                                <?php if ($puedeValidarPagos && $s['tipo_pago'] !== 'Exonerado' && $s['estado'] !== 'Aprobado' && !$s['pf_id']): ?><button type="button" class="btn btn-link btn-sm" data-toggle="modal" data-target="#cuota-modal" data-id="<?php echo (int) $s['id_solicitud']; ?>" data-cuota="<?php echo cengi_pp_h($s['cuota']); ?>" data-titulo="<?php echo cengi_pp_h($s['nombre_participante']); ?>">Cuota</button><?php endif; ?>
                                <button type="button" class="btn btn-link btn-sm" data-toggle="modal" data-target="#detalle-modal" data-titulo="<?php echo cengi_pp_h($s['nombre_participante'] . ' · ' . $s['plan_nombre']); ?>" data-historial="<?php echo cengi_pp_h(json_encode(cengi_pi_historial($s), JSON_UNESCAPED_UNICODE)); ?>">Ver detalle</button>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div></section>
    <?php elseif ($tab === 'grupos'): ?>
        <section class="cengi-courses-section"><div class="pp-table-wrap">
            <table class="cengi-org-table" style="min-width:1000px;">
                <thead><tr><th>Ingenio</th><th>Curso</th><th>Participantes cubiertos</th><th>Validación del ingenio</th><th>Orden / factura</th><th>Monto</th><th>Seguimiento del cobro</th><th></th></tr></thead>
                <tbody>
                    <?php if (!$grupos): ?><tr><td colspan="8" class="pp-empty">No hay pagos grupales de ingenios en esta selección.</td></tr><?php endif; ?>
                    <?php foreach ($grupos as $g): ?>
                        <tr>
                            <td><strong><?php echo cengi_pp_h($g['nombre_ingenios']); ?></strong></td>
                            <td><?php echo cengi_pp_h($g['plan_nombre']); ?></td>
                            <td><?php echo (int) $g['miembros']; ?><?php if ($g['proforma_id'] && (int) $g['cupos'] > (int) $g['miembros']): ?> <span class="pp-sub" style="display:inline;color:#8A6600;font-weight:600;">+ <?php echo (int) $g['cupos'] - (int) $g['miembros']; ?> cupo(s) libre(s)</span><?php endif; ?><span class="pp-sub"><?php echo cengi_pp_h($g['nombres'] ?: 'Aún sin participantes'); ?></span></td>
                            <td><?php if ($g['miembros']): ?><span class="pp-chip <?php echo (int) $g['validados'] === (int) $g['miembros'] ? 'is-ok' : 'is-yellow'; ?>"><?php echo (int) $g['validados'] === (int) $g['miembros'] ? 'Todos validados' : $g['validados'] . ' de ' . $g['miembros'] . ' validados'; ?></span><?php else: ?>—<?php endif; ?></td>
                            <td class="pp-mono"><?php if ($g['proforma_id']): ?><a class="pp-link" href="proforma.php?id=<?php echo (int) $g['proforma_id']; ?>">Proforma N.° <?php echo (int) $g['pf_numero']; ?></a><?php else: ?><?php echo cengi_pp_h($g['orden'] ?: '—'); ?><?php endif; ?><?php if ($g['comprobante_ruta']): ?><span class="pp-sub"><a target="_blank" rel="noopener" href="comprobante_inscripcion.php?g=<?php echo (int) $g['id']; ?>">Ver comprobante</a></span><?php endif; ?></td>
                            <td class="pp-num"><b><?php echo $g['moneda'] === 'USD' ? 'US$' . number_format((float) $g['monto'], 2) : cengi_pp_q($g['monto'], 2); ?></b></td>
                            <td><?php echo cengi_pp_badge(CENGI_INS_ESTADOS_PAGO, $g['estado']); ?><?php echo $g['pago_referencia'] ? '<span class="pp-sub">' . cengi_pp_h($g['pago_forma'] . ' ' . $g['pago_referencia'] . ' · ' . cengi_pp_fecha($g['pago_fecha'])) . '</span>' : ''; ?><?php echo $g['nota'] && $g['estado'] === 'rechazado' ? '<span class="pp-sub"><i>' . cengi_pp_h($g['nota']) . '</i></span>' : ''; ?></td>
                            <td style="text-align:right;white-space:nowrap;">
                                <?php if ($g['pendientes'] && cengi_ins_puede_validar_ingenio($g['nombre_ingenios'])): ?><button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#ingenio-modal" data-ids="<?php echo cengi_pp_h($g['pendientes']); ?>" data-titulo="<?php echo cengi_pp_h($g['nombre_ingenios'] . ' · ' . $g['plan_nombre']); ?>">Validar (<?php echo count(explode(',', $g['pendientes'])); ?>)</button><?php endif; ?>
                                <?php if ($g['proforma_id']): ?>
                                    <a class="btn btn-<?php echo $puedeValidarPagos && $g['estado'] !== 'pagado' ? 'primary' : 'default'; ?> btn-sm" href="proforma.php?id=<?php echo (int) $g['proforma_id']; ?>"><?php echo $puedeValidarPagos && $g['estado'] !== 'pagado' ? 'Registrar pago en la proforma' : 'Ver proforma'; ?></a>
                                <?php elseif ($puedeValidarPagos && $g['estado'] !== 'pagado'): ?>
                                    <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#grupo-modal" data-id="<?php echo (int) $g['id']; ?>" data-orden="<?php echo cengi_pp_h($g['orden']); ?>" data-titulo="<?php echo cengi_pp_h($g['nombre_ingenios'] . ' · ' . $g['plan_nombre']); ?>">Orden y comprobante</button>
                                    <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#pagado-modal" data-accion="grupo_pagado" data-id="<?php echo (int) $g['id']; ?>" data-titulo="<?php echo cengi_pp_h($g['nombre_ingenios'] . ' · ' . cengi_pp_q($g['monto'], 2)); ?>" data-ref="<?php echo cengi_pp_h($g['orden']); ?>">Marcar pagado</button>
                                    <?php if ($g['estado'] === 'en revisión'): ?><button type="button" class="btn btn-link btn-sm" data-toggle="modal" data-target="#nota-modal" data-accion="grupo_rechazar" data-id="<?php echo (int) $g['id']; ?>" data-titulo="Rechazar comprobante de la orden" data-label="Motivo del rechazo">Rechazar</button><?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div></section>
    <?php elseif ($tab === 'proformas'): ?>
        <section class="pp-card">
            <div class="pp-card-head"><div><h3>Proformas emitidas</h3><span class="pp-sub">Con participantes inscritos o por cupos antes de la inscripción. Al registrar el pago, los participantes amarrados se validan automáticamente. Vigencia: <?php echo CENGI_PF_VIGENCIA_DIAS; ?> días.</span></div></div>
            <div class="pp-table-wrap"><table class="cengi-org-table" style="min-width:980px;">
                <thead><tr><th>Proforma</th><th>Emisión</th><th>Cliente</th><th>Participantes</th><th class="pp-num">Total</th><th>Estado</th><th></th></tr></thead>
                <tbody>
                    <?php if (!$proformas): ?><tr><td colspan="7" class="pp-empty">No hay proformas<?php echo $busqueda !== '' ? ' que coincidan con la búsqueda' : ' en esta selección'; ?>.</td></tr><?php endif; ?>
                    <?php foreach ($proformas as $pf): $estPf = cengi_pf_estado($pf); $libres = $pf['estado'] === 'anulada' ? 0 : max(0, (int) $pf['cantidad'] - (int) $pf['amarrados']); ?>
                        <tr<?php echo $pf['estado'] === 'anulada' ? ' class="pp-muted-row"' : ''; ?>>
                            <td class="pp-mono"><a class="pp-link" href="proforma.php?id=<?php echo (int) $pf['id']; ?>"><b>N.° <?php echo (int) $pf['numero']; ?></b></a></td>
                            <td class="pp-num"><?php echo cengi_pp_fecha($pf['fecha']); ?><span class="pp-sub">vence <?php echo cengi_pp_fecha($pf['vence']); ?></span></td>
                            <td><strong><?php echo cengi_pp_h($pf['empresa']); ?></strong><span class="pp-sub"><?php echo cengi_pp_h($pf['dirigido']); ?></span></td>
                            <td><?php echo (int) $pf['cantidad']; ?> <span class="pp-sub" style="display:inline;">(<?php echo (int) $pf['amarrados']; ?> con nombre<?php echo $libres ? ', <b style="color:#8A6600;">' . $libres . ' cupo(s) libre(s)</b>' : ''; ?>)</span><span class="pp-sub"><?php echo cengi_pp_h($pf['cursos']); ?></span></td>
                            <td class="pp-num"><b><?php echo cengi_pp_h(cengi_pf_dinero($pf['total'], $pf['moneda'])); ?></b><?php echo (float) $pf['cargo_monto'] > 0 ? '<span class="pp-sub">incluye transferencia internacional</span>' : ''; ?><?php echo (float) $pf['con_descuento'] > 0 ? '<span class="pp-sub">con descuento</span>' : ''; ?></td>
                            <td><?php echo cengi_pp_badge(CENGI_PF_ESTADOS, $estPf); ?><?php echo $pf['pago_referencia'] ? '<span class="pp-sub">' . cengi_pp_h($pf['pago_forma'] . ' ' . $pf['pago_referencia']) . '</span>' : ''; ?></td>
                            <td style="text-align:right;white-space:nowrap;">
                                <a class="btn btn-default btn-sm" href="proforma.php?id=<?php echo (int) $pf['id']; ?>">Ver</a>
                                <a class="btn btn-default btn-sm" href="proforma.php?id=<?php echo (int) $pf['id']; ?>&amp;pdf=1" title="Descargar PDF"><span class="glyphicon glyphicon-download-alt"></span></a>
                                <?php if ($puedeValidarPagos && in_array($pf['estado'], ['emitida', 'enviada'], true)): ?><a class="btn btn-primary btn-sm" href="proforma.php?id=<?php echo (int) $pf['id']; ?>">Registrar pago</a><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>
    <?php else: ?>
        <section class="pp-card">
            <div class="pp-card-head"><div><h3>Ingreso y costo por curso</h3><span class="pp-sub">Cobrado a participantes frente al costo de instructores (liquidaciones + sesiones aún sin liquidar, con 5% de impuesto). Cuotas en US$ a Q<?php echo CENGI_INS_TIPO_CAMBIO; ?>.</span></div></div>
            <div class="pp-table-wrap"><table class="cengi-org-table">
                <thead><tr><th>Curso</th><th>Inscritos</th><th>Pagados / cupo mín.</th><th>Ingreso esperado</th><th>Cobrado</th><th>Pendiente</th><th>Costo de instructores</th><th>Resultado</th></tr></thead>
                <tbody>
                    <?php if (!$resumenCursos): ?><tr><td colspan="8" class="pp-empty">Sin inscritos en esta selección.</td></tr><?php endif; ?>
                    <?php foreach ($resumenCursos as $r):
                        $resultado = $r['cobrado'] - $r['costo'];
                        $equilibrio = $r['cuota'] > 0 && $r['costo'] > 0 ? (int) ceil($r['costo'] / $r['cuota']) : null;
                    ?>
                        <tr>
                            <td><strong><?php echo cengi_pp_h($r['nombre']); ?></strong><span class="pp-sub"><?php echo $r['curso'] ? 'Curso abierto' : 'Convocatoria (sin curso aún)'; ?></span><?php if (!empty($r['cuposPagados'])): ?><span class="pp-sub">Incluye <?php echo cengi_pp_q($r['cuposPagados']); ?> de cupos de proforma pagados aún sin nombre</span><?php endif; ?></td>
                            <td class="pp-num"><?php echo $r['inscritos']; ?></td>
                            <td class="pp-num"><?php echo $r['pagados'] . ' / ' . $r['cupo']; ?><?php echo $r['pagados'] < $r['cupo'] ? '<span class="pp-sub" style="color:#B34E00;">bajo el mínimo</span>' : ''; ?></td>
                            <td class="pp-num"><?php echo cengi_pp_q($r['esperado']); ?></td>
                            <td class="pp-num" style="color:#1f5632;font-weight:700;"><?php echo cengi_pp_q($r['cobrado']); ?></td>
                            <td class="pp-num"><?php echo cengi_pp_q($r['esperado'] - $r['cobrado']); ?></td>
                            <td class="pp-num"><?php echo cengi_pp_q($r['costo']); ?></td>
                            <td class="pp-num" style="font-weight:700;color:<?php echo $resultado >= 0 ? '#1f5632' : '#B23223'; ?>;"><?php echo ($resultado >= 0 ? '+' : '−') . cengi_pp_q(abs($resultado)); ?>
                                <span class="pp-sub" style="font-weight:400;"><?php
                                    if ($r['estado'] === 'cancelado') {
                                        echo 'curso cancelado';
                                    } elseif ($r['cuota'] <= 0) {
                                        echo 'sin costo para participantes';
                                    } elseif ($equilibrio !== null) {
                                        echo 'equilibrio: ' . $equilibrio . ' pagados' . ($r['pagados'] >= $equilibrio ? ' (alcanzado)' : '');
                                    } else {
                                        echo 'sin costo de instructores aún';
                                    }
                                ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>
        <section class="pp-card">
            <div class="pp-card-head"><div><h3>Inversión y resultados por ingenio o institución</h3><span class="pp-sub">Lo pagado por cada ingenio (o sus colaboradores) y lo que lograron sus participantes asignados: asistencia, mejora entre pre y post evaluación y aprobados (nota final ≥ <?php echo CENGI_NOTA_APROBACION; ?>).</span></div></div>
            <div class="pp-table-wrap"><table class="cengi-org-table" style="min-width:980px;">
                <thead><tr><th>Ingenio / institución</th><th class="pp-num">Participantes</th><th class="pp-num">Pagado por el ingenio</th><th class="pp-num">Pago propio</th><th class="pp-num">Inversión total</th><th class="pp-num">Asistencia prom.</th><th class="pp-num">Mejora pre → post</th><th class="pp-num">Aprobados</th><th class="pp-num">Inversión por aprobado</th></tr></thead>
                <tbody>
                    <?php if (!$resumenIngenios): ?><tr><td colspan="9" class="pp-empty">Sin datos.</td></tr><?php endif; ?>
                    <?php uasort($resumenIngenios, static function ($a, $b) {
                        return ($b['ingenio'] + $b['propio']) <=> ($a['ingenio'] + $a['propio']);
                    }); foreach ($resumenIngenios as $nombre => $r):
                        $inversion = $r['ingenio'] + $r['propio'];
                        $asist = $r['asistencia'] ? array_sum($r['asistencia']) / count($r['asistencia']) : null;
                        $mejora = $r['mejora'] ? array_sum($r['mejora']) / count($r['mejora']) : null;
                    ?>
                        <tr><td><strong><?php echo cengi_pp_h($nombre); ?></strong></td><td class="pp-num"><?php echo $r['participantes']; ?></td><td class="pp-num"><?php echo cengi_pp_q($r['ingenio']); ?></td><td class="pp-num"><?php echo cengi_pp_q($r['propio']); ?></td><td class="pp-num"><b><?php echo cengi_pp_q($inversion); ?></b></td>
                            <td class="pp-num"><?php echo $asist !== null ? round($asist) . '%' : '—'; ?></td>
                            <td class="pp-num" style="color:<?php echo $mejora !== null && $mejora < 0 ? '#B23223' : '#1f5632'; ?>;font-weight:600;"><?php echo $mejora !== null ? ($mejora >= 0 ? '+' : '') . number_format($mejora, 1) . ' pts' : '—'; ?></td>
                            <td class="pp-num"><?php echo $r['conNota'] ? $r['aprobados'] . ' de ' . $r['conNota'] : ($r['asistencia'] ? '<span class="pp-sub">en curso</span>' : '—'); ?></td>
                            <td class="pp-num"><?php echo $r['aprobados'] && $inversion > 0 ? cengi_pp_q($inversion / $r['aprobados']) : '—'; ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>
    <?php endif; ?>
</main>

<?php if ($puedeValidarPagos) { cengi_pf_modal_cuentas(cengi_pf_cuentas($db), 'pagos_inscripcion.php?tab=proformas'); } ?>

<!-- detalle del pago del participante -->
<div class="modal fade cengi-course-form-modal pp-modal" id="detalle-modal" tabindex="-1" role="dialog" aria-labelledby="detalle-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><span class="cengi-course-modal-kicker">Pago del participante</span><h4 class="modal-title" id="detalle-title"></h4></div>
        <div class="modal-body"><div class="pp-timeline" id="detalle-historial"></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button></div>
    </div></div>
</div>

<!-- validacion del ingenio -->
<div class="modal fade cengi-course-form-modal pp-modal" id="ingenio-modal" tabindex="-1" role="dialog" aria-labelledby="ingenio-title">
    <div class="modal-dialog" role="document"><div class="modal-content"><form method="post">
        <input type="hidden" name="accion" value="val_ingenio"><div id="ingenio-ids"></div>
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><span class="cengi-course-modal-kicker">Validación del ingenio</span><h4 class="modal-title" id="ingenio-title"></h4></div>
        <div class="modal-body">
            <p class="pp-sub">El ingenio confirma que la persona puede participar y que cubre su cuota en la orden de grupo. Queda registrado quién validó.</p>
            <div class="form-group"><label style="font-weight:600;"><input type="radio" name="decision" value="validada" checked> Validar inscripción y pago</label><br>
                <label style="font-weight:600;"><input type="radio" name="decision" value="rechazada"> No validar</label></div>
            <div class="form-group" id="ingenio-nota-wrap" hidden><label for="ingenio-nota">Motivo (se le comunica al participante)</label><input type="text" class="form-control" id="ingenio-nota" name="nota" maxlength="500"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Registrar</button></div>
    </form></div></div>
</div>

<!-- validar comprobante -->
<div class="modal fade cengi-course-form-modal pp-modal" id="pago-modal" tabindex="-1" role="dialog" aria-labelledby="pago-title">
    <div class="modal-dialog" role="document"><div class="modal-content"><form method="post">
        <input type="hidden" name="id" id="pago-id">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><span class="cengi-course-modal-kicker">Validar comprobante</span><h4 class="modal-title" id="pago-title"></h4></div>
        <div class="modal-body">
            <div id="pago-info"></div>
            <div class="pp-form-msg is-warn" id="pago-aviso"></div>
            <div class="form-group" id="pago-confirmar-wrap" hidden><label><input type="checkbox" name="confirmar_monto" value="1"> Validar aunque el monto no coincida</label></div>
            <div class="form-group"><label for="pago-nota">Nota (obligatoria para rechazar; se le envía al participante)</label><input type="text" class="form-control" id="pago-nota" name="nota" maxlength="500"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-link" name="accion" value="rechazar_pago" style="color:#B23223;">Rechazar comprobante</button>
            <button type="submit" class="btn btn-primary" name="accion" value="validar_pago">Validar</button></div>
    </form></div></div>
</div>

<!-- registrar comprobante (Administracion en nombre del participante) -->
<div class="modal fade cengi-course-form-modal pp-modal" id="comprobante-modal" tabindex="-1" role="dialog" aria-labelledby="comp-title">
    <div class="modal-dialog" role="document"><div class="modal-content"><form method="post" enctype="multipart/form-data">
        <input type="hidden" name="accion" value="comprobante"><input type="hidden" name="id" id="comp-id">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><span class="cengi-course-modal-kicker">Registrar comprobante</span><h4 class="modal-title" id="comp-title"></h4></div>
        <div class="modal-body"><div class="cengi-course-modal-grid">
            <div class="form-group"><label for="comp-boleta">N.° de boleta / transferencia</label><input type="text" class="form-control" id="comp-boleta" name="boleta" maxlength="60" required></div>
            <div class="form-group"><label for="comp-fecha">Fecha del pago</label><input type="date" class="form-control" id="comp-fecha" name="pago_fecha" max="<?php echo $hoy; ?>" required></div>
            <div class="form-group"><label for="comp-monto">Monto del comprobante</label><input type="number" class="form-control" id="comp-monto" name="monto_comprobante" min="0" step="0.01" required></div>
            <div class="form-group"><label for="comp-archivo">Comprobante (PDF, JPG o PNG)</label><input type="file" class="form-control" id="comp-archivo" name="archivo" accept=".pdf,.jpg,.jpeg,.png"></div>
        </div><p class="pp-sub">Queda “por validar”: después usa “Validar comprobante”.</p></div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
    </form></div></div>
</div>

<!-- marcar pagado (pago propio u orden del ingenio) -->
<div class="modal fade cengi-course-form-modal pp-modal" id="pagado-modal" tabindex="-1" role="dialog" aria-labelledby="pagado-title">
    <div class="modal-dialog" role="document"><div class="modal-content"><form method="post">
        <input type="hidden" name="accion" id="pagado-accion"><input type="hidden" name="id" id="pagado-id">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><span class="cengi-course-modal-kicker">Seguimiento del cobro</span><h4 class="modal-title" id="pagado-title"></h4></div>
        <div class="modal-body"><div class="cengi-course-modal-grid">
            <div class="form-group"><label for="pagado-forma">Forma de pago</label><select class="form-control" id="pagado-forma" name="pago_forma"><option>Depósito</option><option>Transferencia</option><option>Cheque</option><option>Tarjeta</option><option>Efectivo</option></select></div>
            <div class="form-group"><label for="pagado-ref">Referencia</label><input type="text" class="form-control" id="pagado-ref" name="pago_referencia" maxlength="120" required></div>
            <div class="form-group"><label for="pagado-fecha">Fecha del ingreso</label><input type="date" class="form-control" id="pagado-fecha" name="pago_fecha" value="<?php echo $hoy; ?>" max="<?php echo $hoy; ?>" required></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Marcar como pagado</button></div>
    </form></div></div>
</div>

<!-- nota: reembolso / rechazo de orden -->
<div class="modal fade cengi-course-form-modal pp-modal" id="nota-modal" tabindex="-1" role="dialog" aria-labelledby="nota-title">
    <div class="modal-dialog" role="document"><div class="modal-content"><form method="post">
        <input type="hidden" name="accion" id="nota-accion"><input type="hidden" name="id" id="nota-id">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><h4 class="modal-title" id="nota-title"></h4></div>
        <div class="modal-body"><div class="form-group"><label for="nota-texto" id="nota-label"></label><input type="text" class="form-control" id="nota-texto" name="nota" maxlength="400" required></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
    </form></div></div>
</div>

<!-- cuota -->
<div class="modal fade cengi-course-form-modal pp-modal" id="cuota-modal" tabindex="-1" role="dialog" aria-labelledby="cuota-title">
    <div class="modal-dialog" role="document"><div class="modal-content"><form method="post">
        <input type="hidden" name="accion" value="cuota"><input type="hidden" name="id" id="cuota-id">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><span class="cengi-course-modal-kicker">Cambiar cuota</span><h4 class="modal-title" id="cuota-title"></h4></div>
        <div class="modal-body"><div class="form-group"><label for="cuota-sel">Cuota</label><select class="form-control" id="cuota-sel" name="cuota">
            <?php foreach (CENGI_INS_CUOTAS as $clave => [$texto, $detalle]): ?><option value="<?php echo $clave; ?>"><?php echo $texto . ' — ' . $detalle; ?></option><?php endforeach; ?>
        </select><p class="help-block">El monto se recalcula con los costos del curso y se actualiza la orden del ingenio.</p></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
    </form></div></div>
</div>

<!-- orden del ingenio -->
<div class="modal fade cengi-course-form-modal pp-modal" id="grupo-modal" tabindex="-1" role="dialog" aria-labelledby="grupo-title">
    <div class="modal-dialog" role="document"><div class="modal-content"><form method="post" enctype="multipart/form-data">
        <input type="hidden" name="accion" value="grupo_guardar"><input type="hidden" name="id" id="grupo-id">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><span class="cengi-course-modal-kicker">Pago del ingenio</span><h4 class="modal-title" id="grupo-title"></h4></div>
        <div class="modal-body"><div class="cengi-course-modal-grid">
            <div class="form-group"><label for="grupo-orden">N.° de orden de compra o factura</label><input type="text" class="form-control" id="grupo-orden" name="orden" maxlength="80"></div>
            <div class="form-group"><label for="grupo-archivo">Comprobante (orden, factura o transferencia)</label><input type="file" class="form-control" id="grupo-archivo" name="archivo" accept=".pdf,.jpg,.jpeg,.png"></div>
        </div><p class="pp-sub">Con un comprobante, la orden queda “por validar” hasta que la marques como pagada. El monto se calcula con las cuotas de sus participantes.</p></div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
    </form></div></div>
</div>

<script>
(function () {
    'use strict';
    function $id(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function datos(e) { try { return JSON.parse(e.relatedTarget.getAttribute('data-s')); } catch (x) { return {}; } }
    function attr(e, n) { return e.relatedTarget.getAttribute('data-' + n) || ''; }

    $('#ingenio-modal').on('show.bs.modal', function (e) {
        $id('ingenio-title').textContent = attr(e, 'titulo');
        $id('ingenio-ids').innerHTML = attr(e, 'ids').split(',').map(function (id) { return '<input type="hidden" name="ids[]" value="' + esc(id) + '">'; }).join('');
        this.querySelector('input[value="validada"]').checked = true; $id('ingenio-nota-wrap').hidden = true; $id('ingenio-nota').value = '';
    });
    document.querySelectorAll('#ingenio-modal input[name="decision"]').forEach(function (r) {
        r.addEventListener('change', function () { var no = this.value === 'rechazada' && this.checked; $id('ingenio-nota-wrap').hidden = !no; $id('ingenio-nota').required = no; });
    });
    $('#pago-modal').on('show.bs.modal', function (e) {
        var s = datos(e), difiere = s.montoComp !== null && Math.abs(s.montoComp - s.montoNum) > 0.009;
        $id('pago-id').value = s.id; $id('pago-title').textContent = s.nombre + ' · ' + s.curso; $id('pago-nota').value = '';
        $id('pago-info').innerHTML = '<div class="pp-mini-row"><span>Cuota a pagar</span><b>' + esc(s.monto) + '</b></div>' +
            '<div class="pp-mini-row"><span>Boleta</span><b class="pp-mono">' + esc(s.boleta || '—') + '</b></div>' +
            '<div class="pp-mini-row"><span>Fecha del pago</span><b>' + esc(s.fecha || '—') + '</b></div>' +
            '<div class="pp-mini-row"><span>Monto del comprobante</span><b>' + (s.montoComp !== null ? esc(s.monto.replace(/[\d.,]+$/, '') + s.montoComp.toFixed(2)) : '—') + '</b></div>' +
            (s.comprobante ? '<p style="margin-top:10px;"><a class="btn btn-default btn-sm" target="_blank" rel="noopener" href="' + esc(s.comprobante) + '">Abrir comprobante</a></p>' : '');
        $id('pago-aviso').textContent = difiere ? 'El monto del comprobante no coincide con la cuota.' : '';
        $id('pago-aviso').classList.toggle('is-visible', difiere); $id('pago-confirmar-wrap').hidden = !difiere;
    });
    $('#comprobante-modal').on('show.bs.modal', function (e) {
        var s = datos(e); this.querySelector('form').reset();
        $id('comp-id').value = s.id; $id('comp-title').textContent = s.nombre + ' · ' + s.monto;
        $id('comp-boleta').value = s.boleta || ''; $id('comp-fecha').value = s.fecha || ''; $id('comp-monto').value = s.montoComp !== null ? s.montoComp : s.montoNum;
        $id('comp-archivo').required = !s.comprobante;
    });
    $('#pagado-modal').on('show.bs.modal', function (e) {
        $id('pagado-accion').value = attr(e, 'accion'); $id('pagado-id').value = attr(e, 'id');
        $id('pagado-title').textContent = attr(e, 'titulo'); $id('pagado-ref').value = attr(e, 'ref');
        $id('pagado-forma').value = attr(e, 'accion') === 'grupo_pagado' ? 'Transferencia' : 'Depósito';
    });
    $('#nota-modal').on('show.bs.modal', function (e) {
        $id('nota-accion').value = attr(e, 'accion'); $id('nota-id').value = attr(e, 'id');
        $id('nota-title').textContent = attr(e, 'titulo'); $id('nota-label').textContent = attr(e, 'label'); $id('nota-texto').value = '';
    });
    $('#detalle-modal').on('show.bs.modal', function (e) {
        var h = [];
        try { h = JSON.parse(attr(e, 'historial')); } catch (x) { h = []; }
        $id('detalle-title').textContent = attr(e, 'titulo');
        $id('detalle-historial').innerHTML = h.map(function (p) {
            return '<div class="pp-timeline-item"><b>' + esc(p[1]) + '</b>' + (p[0] ? ' <span class="pp-sub" style="display:inline;">· ' + esc(p[0]) + '</span>' : '')
                + (p[2] ? '<div class="pp-sub">' + esc(p[2]) + '</div>' : '') + '</div>';
        }).join('');
    });
    $('#cuota-modal').on('show.bs.modal', function (e) { $id('cuota-id').value = attr(e, 'id'); $id('cuota-sel').value = attr(e, 'cuota') || 'asociado'; $id('cuota-title').textContent = attr(e, 'titulo'); });
    $('#grupo-modal').on('show.bs.modal', function (e) { $id('grupo-id').value = attr(e, 'id'); $id('grupo-orden').value = attr(e, 'orden'); $id('grupo-title').textContent = attr(e, 'titulo'); $id('grupo-archivo').value = ''; });
    // El rechazo del comprobante exige una nota.
    document.querySelector('#pago-modal button[value="rechazar_pago"]').addEventListener('click', function (ev) {
        if (!$id('pago-nota').value.trim()) { ev.preventDefault(); $id('pago-nota').focus(); alert('Escribe el motivo del rechazo.'); }
    });
})();
</script>
</body>
</html>
