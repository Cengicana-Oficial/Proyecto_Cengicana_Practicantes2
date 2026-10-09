<?php
/**
 * Proformas de pago (Pagos de inscripcion -> Proformas), como en el prototipo SIGEC:
 *   ?nueva=1[&ingenio=ID][&plan=ID]  generador: inscritos del ingenio y/o cupos por curso,
 *                                    descuento por curso, tipo de cliente, cargo internacional
 *   ?id=N                            documento, participantes amarrados y seguimiento
 *   ?id=N&pdf=1                      descarga en PDF
 * Acciones (POST, solo Administracion): emitir, enviar (correo con el PDF), pago, anular,
 * amarrar, cuentas. Logica en proformas_helpers.php.
 */
require_once __DIR__ . '/proformas_helpers.php';
require_once __DIR__ . '/menu.php';

if (!cengi_ins_puede_ver_pagos()) {
    header('Location: index.php');
    exit;
}

$db = conectar();
$hoy = cengi_pp_hoy();
$puedeGestionar = cengi_ins_puede_validar_pagos();
$limitado = cengi_ins_ingenio_limitado();
$mensaje = '';
$mensajeTipo = 'success';
$cuentas = cengi_pf_cuentas($db);

const CENGI_PF_MENSAJES = [
    'emitida' => 'Proforma emitida.',
    'enviada' => 'Proforma enviada al cliente con el PDF adjunto.',
    'enviada-sin-correo' => 'Proforma marcada como enviada, pero el correo no salió. Descarga el PDF y envíalo manualmente.',
    'pagada' => 'Pago registrado: la orden quedó pagada y los participantes amarrados quedaron validados.',
    'anulada' => 'Proforma anulada: los participantes volvieron a la orden normal de su ingenio, con la cuota regular.',
    'amarrados' => 'Participantes amarrados a la proforma.',
    'cuentas' => 'Cuentas bancarias guardadas.',
];

$volverSeguro = static function ($url) {
    $url = (string) $url;
    return preg_match('/^(pagos_inscripcion|proforma)\.php(\?[^\s"<>]*)?$/', $url) ? $url : 'pagos_inscripcion.php?tab=proformas';
};

// ---------------------------------------------------------------- acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $planes = [];
    try {
        if (!$puedeGestionar) {
            throw new InvalidArgumentException('Solo Administración puede gestionar las proformas.');
        }
        $db->beginTransaction();
        $cargar = static function () use ($db) {
            $pf = cengi_pf_cargar($db, (int) ($_POST['id'] ?? 0), true);
            if (!$pf) {
                throw new InvalidArgumentException('La proforma no existe.');
            }
            return $pf;
        };

        if ($accion === 'cuentas') {
            $q = [];
            $u = [];
            foreach (['inst', 'banco', 'tipo', 'numero', 'correo'] as $k) {
                $q[$k] = mb_substr(trim((string) ($_POST['q_' . $k] ?? '')), 0, 255, 'UTF-8');
                $u[$k] = mb_substr(trim((string) ($_POST['u_' . $k] ?? '')), 0, 255, 'UTF-8');
            }
            $u['swift'] = mb_substr(trim((string) ($_POST['u_swift'] ?? '')), 0, 30, 'UTF-8');
            $u['intermediario'] = mb_substr(trim((string) ($_POST['u_intermediario'] ?? '')), 0, 255, 'UTF-8');
            if (!cengi_pf_cuenta_completa($q)) {
                throw new InvalidArgumentException('La cuenta nacional necesita banco y número de cuenta.');
            }
            cengi_pf_guardar_config($db, 'pf_cuenta_gtq', $q);
            cengi_pf_guardar_config($db, 'pf_cuenta_usd', $u);
            cengi_pf_guardar_config($db, 'pf_cargo_usd', max(0, (float) ($_POST['cargo_usd'] ?? 35)));
            $db->commit();
            header('Location: ' . $volverSeguro($_POST['volver'] ?? '') . (strpos($volverSeguro($_POST['volver'] ?? ''), '?') !== false ? '&' : '?') . 'msg=cuentas');
            exit;
        }

        if ($accion === 'emitir') {
            $ingenioId = (int) ($_POST['ingenio_id'] ?? 0);
            $stmt = $db->prepare('SELECT id, nombre_ingenios FROM ingenios WHERE id = ?');
            $stmt->execute([$ingenioId]);
            if (!$stmt->fetch()) {
                throw new InvalidArgumentException('Elige el ingenio o institución.');
            }
            $tipo = array_key_exists($_POST['tipo_cliente'] ?? '', CENGI_PF_TIPOS) ? $_POST['tipo_cliente'] : 'asociado';
            $cliente = [];
            foreach (['dirigido' => 255, 'contacto' => 80, 'correo' => 255, 'nit' => 30, 'pais' => 80] as $k => $max) {
                $cliente[$k] = mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max, 'UTF-8');
            }
            $fecha = (string) ($_POST['fecha'] ?? $hoy);
            $errores = [];
            if ($cliente['dirigido'] === '') {
                $errores[] = 'Indica a quién va dirigida.';
            }
            if (!filter_var($cliente['correo'], FILTER_VALIDATE_EMAIL)) {
                $errores[] = 'El correo del cliente no es válido.';
            }
            if ($cliente['pais'] === '') {
                $errores[] = 'Indica el país.';
            }
            if (!cengi_pp_es_fecha($fecha)) {
                $errores[] = 'Revisa la fecha de emisión.';
            }
            $moneda = $tipo === 'extranjero' ? 'USD' : 'GTQ';
            if (!cengi_pf_cuenta_completa($cuentas[$moneda])) {
                $errores[] = 'Faltan los datos de la cuenta ' . ($moneda === 'USD' ? 'para pagos del extranjero' : 'nacional') . ' (botón “Cuentas bancarias”).';
            }
            $candidatos = cengi_pf_candidatos($db, $ingenioId);
            $sel = array_flip(array_map('intval', (array) ($_POST['sel'] ?? [])));
            $lineas = [];
            foreach (cengi_pf_cursos($db) as $plan) {
                $pid = (int) $plan['id'];
                $ids = [];
                foreach ($candidatos[$pid] ?? [] as $c) {
                    if (isset($sel[(int) $c['id_solicitud']]) && !$c['en_proforma']) {
                        $ids[] = (int) $c['id_solicitud'];
                    }
                }
                $cupos = max(0, min(500, (int) ($_POST['cupos'][$pid] ?? 0)));
                if (!$ids && !$cupos) {
                    continue;
                }
                if (cengi_ins_monto($plan, $tipo)[0] <= 0) {
                    $errores[] = cengi_pp_h($plan['nombre']) . ' no tiene costo para el tipo de cliente elegido.';
                    continue;
                }
                $lineas[] = ['plan' => $plan, 'inscritos' => $ids, 'cupos' => $cupos,
                    'desc_tipo' => ($_POST['desc_tipo'][$pid] ?? '') === 'pct' ? 'pct' : 'monto', 'desc_valor' => max(0, (float) ($_POST['desc_valor'][$pid] ?? 0))];
            }
            if (!$lineas) {
                $errores[] = 'Selecciona participantes o indica cupos en al menos un curso.';
            }
            if ($errores) {
                throw new InvalidArgumentException(implode('<br>', array_map(static function ($e) {
                    return strpos($e, '&') !== false ? $e : cengi_pp_h($e);
                }, $errores)));
            }
            $id = cengi_pf_emitir($db, ['ingenio_id' => $ingenioId, 'cliente' => $cliente, 'tipo_cliente' => $tipo, 'fecha' => $fecha,
                'obs' => mb_substr(trim((string) ($_POST['obs'] ?? '')), 0, 500, 'UTF-8'), 'cargo' => !empty($_POST['cargo']),
                'cargo_monto' => (float) ($_POST['cargo_monto'] ?? 0), 'lineas' => $lineas]);
            $db->commit();
            header('Location: proforma.php?id=' . $id . '&msg=emitida');
            exit;
        }

        $pf = $cargar();
        $activa = in_array($pf['estado'], ['emitida', 'enviada'], true);
        if ($accion === 'enviar') {
            if (!$activa) {
                throw new InvalidArgumentException('Solo se envía una proforma emitida o enviada.');
            }
            $db->prepare("UPDATE proformas SET estado = 'enviada' WHERE id = ?")->execute([$pf['id']]);
            cengi_pf_log($db, $pf['id'], 'Enviada al cliente', $pf['correo']);
            $db->commit();
            require_once __DIR__ . '/correo.php';
            $cta = $cuentas[$pf['moneda']];
            $texto = 'Estimado(a) ' . $pf['dirigido'] . ":\n\nAdjuntamos la proforma de pago N.° " . $pf['numero'] . ' por ' . cengi_pf_dinero($pf['total'], $pf['moneda'])
                . ' para ' . array_sum(array_column($pf['lineas'], 'cantidad')) . ' participante(s) de ' . $pf['empresa'] . '. Vigencia: hasta el ' . cengi_pp_fecha($pf['vence']) . ".\n\n"
                . 'Al realizar el pago, envíe el comprobante a ' . ($cta['correo'] ?: 'Administración de CENGICAÑA') . ".\n\nCoordinación de Capacitación — CENGICAÑA";
            $asunto = 'Proforma de pago N.° ' . $pf['numero'] . ' — CENGICAÑA';
            $ok = false;
            try {
                $GLOBALS['cengi_correo_categoria'] = 'Proformas';
                $ok = cengi_enviar_correo($pf['correo'], $pf['dirigido'], $asunto, cengi_pp_correo_html($asunto, $texto),
                    [['nombre' => 'Proforma_' . $pf['numero'] . '.pdf', 'contenido' => cengi_pf_pdf($pf, $cuentas), 'tipo' => 'application/pdf']]);
            } catch (Throwable $e) {
                error_log('Proforma ' . $pf['numero'] . ': no se pudo enviar el correo: ' . $e->getMessage());
            }
            header('Location: proforma.php?id=' . $pf['id'] . '&msg=' . ($ok ? 'enviada' : 'enviada-sin-correo'));
            exit;
        }
        if ($accion === 'pago') {
            if (!$activa) {
                throw new InvalidArgumentException('Esta proforma ya no admite registrar el pago.');
            }
            $forma = mb_substr(trim((string) ($_POST['pago_forma'] ?? '')), 0, 60, 'UTF-8');
            $ref = mb_substr(trim((string) ($_POST['pago_referencia'] ?? '')), 0, 120, 'UTF-8');
            $fecha = (string) ($_POST['pago_fecha'] ?? '');
            if ($forma === '' || $ref === '' || !cengi_pp_es_fecha($fecha) || $fecha > $hoy) {
                throw new InvalidArgumentException('Indica la forma, la referencia y la fecha del pago.');
            }
            $planes = cengi_pf_registrar_pago($db, $pf, $forma, $ref, $fecha);
            foreach ($planes as $planId) {
                cengi_ins_procesar_plan($db, $planId);
            }
            $db->commit();
            cengi_ins_enviar_cola($db);
            header('Location: proforma.php?id=' . $pf['id'] . '&msg=pagada');
            exit;
        }
        if ($accion === 'anular') {
            $motivo = mb_substr(trim((string) ($_POST['motivo'] ?? '')), 0, 500, 'UTF-8');
            if (!$activa) {
                throw new InvalidArgumentException('Solo se anula una proforma que aún no está pagada.');
            }
            if ($motivo === '') {
                throw new InvalidArgumentException('Escribe el motivo de la anulación.');
            }
            cengi_pf_anular($db, $pf, $motivo);
            $db->commit();
            header('Location: proforma.php?id=' . $pf['id'] . '&msg=anulada');
            exit;
        }
        if ($accion === 'amarrar') {
            if ($pf['estado'] === 'anulada') {
                throw new InvalidArgumentException('La proforma está anulada.');
            }
            $lineaId = (int) ($_POST['linea'] ?? 0);
            $linea = null;
            foreach ($pf['lineas'] as $l) {
                if ((int) $l['id'] === $lineaId) {
                    $linea = $l;
                }
            }
            if (!$linea || !$linea['grupo_pago_id']) {
                throw new InvalidArgumentException('La línea no existe.');
            }
            $libres = (int) $linea['cantidad'] - count($linea['inscritos']);
            $validos = [];
            foreach (cengi_pf_candidatos($db, $pf['ingenio_id'], $linea['planificacion_id'])[(int) $linea['planificacion_id']] ?? [] as $c) {
                if (!$c['en_proforma']) {
                    $validos[(int) $c['id_solicitud']] = true;
                }
            }
            $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), static function ($id) use ($validos) {
                return isset($validos[$id]);
            }));
            if (!$ids) {
                throw new InvalidArgumentException('Marca al menos un participante.');
            }
            if (count($ids) > $libres) {
                throw new InvalidArgumentException('Solo hay ' . $libres . ' cupo(s) libre(s) en ' . cengi_pp_h($linea['descripcion']) . '.');
            }
            foreach ($ids as $sid) {
                cengi_pf_amarrar($db, $pf, $linea, $sid);
            }
            cengi_pf_log($db, $pf['id'], 'Participantes amarrados', count($ids) . ' en ' . $linea['descripcion']);
            if ($pf['estado'] === 'pagada') {
                cengi_ins_procesar_plan($db, $linea['planificacion_id']);
            }
            $db->commit();
            cengi_ins_enviar_cola($db);
            header('Location: proforma.php?id=' . $pf['id'] . '&msg=amarrados');
            exit;
        }
        throw new InvalidArgumentException('Acción no válida.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $GLOBALS['cengi_ins_cola'] = [];
        $mensajeTipo = 'error';
        if ($e instanceof InvalidArgumentException) {
            $mensaje = $e->getMessage();
        } else {
            error_log('Proformas: ' . $e->getMessage());
            $mensaje = 'No fue posible guardar los cambios.';
        }
    }
}
if ($mensaje === '' && isset(CENGI_PF_MENSAJES[$_GET['msg'] ?? ''])) {
    $mensaje = cengi_pp_h(CENGI_PF_MENSAJES[$_GET['msg']]);
    $mensajeTipo = ($_GET['msg'] ?? '') === 'enviada-sin-correo' ? 'warning' : 'success';
}

// ---------------------------------------------------------------- datos de la vista
$nueva = !empty($_GET['nueva']) || ($_POST['accion'] ?? '') === 'emitir';
$pf = null;
if (!$nueva) {
    $pf = cengi_pf_cargar($db, (int) ($_GET['id'] ?? $_POST['id'] ?? 0));
    if (!$pf || ($limitado !== null && cengi_texto_normalizado($pf['empresa']) !== cengi_texto_normalizado($limitado))) {
        header('Location: pagos_inscripcion.php?tab=proformas');
        exit;
    }
    if (!empty($_GET['pdf'])) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="Proforma_' . (int) $pf['numero'] . '.pdf"');
        echo cengi_pf_pdf($pf, $cuentas);
        exit;
    }
} elseif (!$puedeGestionar) {
    header('Location: pagos_inscripcion.php?tab=proformas');
    exit;
}

$ingenios = $db->query('SELECT id, nombre_ingenios FROM ingenios ORDER BY nombre_ingenios')->fetchAll(PDO::FETCH_ASSOC);
$ingenioSel = (int) ($_POST['ingenio_id'] ?? $_GET['ingenio'] ?? 0);
$planAbierto = (int) ($_GET['plan'] ?? 0);
$cliente = [];
$cursosPf = [];
$candidatos = [];
$tipoSel = 'asociado';
if ($nueva && $ingenioSel) {
    $nombreIngenio = '';
    foreach ($ingenios as $i) {
        if ((int) $i['id'] === $ingenioSel) {
            $nombreIngenio = $i['nombre_ingenios'];
        }
    }
    $guardado = ((array) cengi_pf_config($db, 'pf_clientes', []))[(string) $ingenioSel] ?? [];
    $usuario = $guardado ? [] : (cengi_ins_usuarios_ingenio($nombreIngenio)[0] ?? []);
    $cliente = [
        'dirigido' => $_POST['dirigido'] ?? ($guardado['dirigido'] ?? ($usuario['nombre'] ?? '')),
        'contacto' => $_POST['contacto'] ?? ($guardado['contacto'] ?? ''),
        'correo' => $_POST['correo'] ?? ($guardado['correo'] ?? ($usuario['correo'] ?? '')),
        'nit' => $_POST['nit'] ?? ($guardado['nit'] ?? ''),
        'pais' => $_POST['pais'] ?? ($guardado['pais'] ?? 'Guatemala'),
    ];
    $tipoSel = $_POST['tipo_cliente'] ?? ($guardado['tipo'] ?? 'asociado');
    $cursosPf = cengi_pf_cursos($db);
    $candidatos = cengi_pf_candidatos($db, $ingenioSel);
    usort($cursosPf, static function ($a, $b) use ($candidatos) {
        return [empty($candidatos[(int) $a['id']]), $a['fecha_inicio']] <=> [empty($candidatos[(int) $b['id']]), $b['fecha_inicio']];
    });
}
$selPost = array_flip(array_map('intval', (array) ($_POST['sel'] ?? [])));
$volverAqui = $nueva ? 'proforma.php?nueva=1' . ($ingenioSel ? '&ingenio=' . $ingenioSel : '') : 'proforma.php?id=' . (int) $pf['id'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php cengi_pp_head($nueva ? 'Nueva proforma' : 'Proforma N.° ' . (int) $pf['numero']); ?>
    <style>
        .pf-layout { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr); gap: 16px; align-items: start; }
        .pf-curso { margin-bottom: 8px; border: 1px solid var(--cengi-border); border-radius: 10px; background: #fff; }
        .pf-curso[open] { border-color: #c9dcb6; }
        .pf-curso.is-active { border-color: var(--cengi-primary); box-shadow: inset 3px 0 0 var(--cengi-primary); }
        .pf-curso > summary { display: flex; align-items: center; gap: 10px; padding: 10px 12px; cursor: pointer; list-style: none; }
        .pf-curso > summary::-webkit-details-marker { display: none; }
        .pf-curso > summary::before { content: "+"; display: inline-flex; align-items: center; justify-content: center; flex: none; width: 20px; height: 20px; border-radius: 50%; background: #eef3ea; color: var(--cengi-primary-deep); font-weight: 800; }
        .pf-curso[open] > summary::before { content: "−"; }
        .pf-curso-body { padding: 4px 14px 12px 42px; }
        .pf-curso-body label { display: flex; gap: 8px; align-items: flex-start; margin: 0 0 6px; font-weight: 500; font-size: 12.5px; }
        .pf-curso-body label.is-disabled { opacity: .55; }
        .pf-fila { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 8px; padding-top: 8px; border-top: 1px dashed var(--cengi-border); font-size: 12px; }
        .pf-fila input[type=number], .pf-fila select { width: 80px; height: 30px; padding: 2px 6px; border: 1px solid var(--cengi-border); border-radius: 6px; }
        .pf-grupo-t { margin: 12px 0 6px; color: var(--cengi-muted); font-size: 10.5px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
        .pf-totales { margin-top: 10px; }
        .pf-doc-wrap { padding: 16px; border-radius: 12px; background: #F4F6F1; }
        .pf-amarre { padding: 10px 0; border-bottom: 1px solid #eef2eb; }
        @media (max-width: 1000px) { .pf-layout { grid-template-columns: 1fr; } }
        @media print {
            @page { size: letter portrait; margin: 12mm; }
            body.cengi-canvas { background: #fff; }
            .cengi-sidebar, .cengi-topbar, .pf-no-print, .cengi-feedback, .modal, .modal-backdrop { display: none !important; }
            .cengi-canvas .container.pp-page { max-width: none; margin: 0; padding: 0; }
            .pf-doc-wrap { padding: 0; background: #fff; }
            .pf-doc { border: 0 !important; padding: 0 !important; }
        }
    </style>
</head>
<body class="cengi-canvas">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page">
    <nav class="pp-subnav pf-no-print">
        <a href="inscripciones.php"><span class="glyphicon glyphicon-inbox"></span>Inscripciones</a>
        <a href="pagos_inscripcion.php?tab=proformas" class="is-active"><span class="glyphicon glyphicon-credit-card"></span>Pagos de inscripción</a>
        <a href="planificacion_cursos.php"><span class="glyphicon glyphicon-calendar"></span>Planificación</a>
    </nav>
    <?php cengi_pp_feedback($mensaje, $mensajeTipo); ?>

<?php if ($nueva): ?>
    <section class="pp-card">
        <div class="pp-card-head">
            <div><h3>Nueva proforma de pago</h3><span class="pp-sub">Con participantes ya inscritos, por cupos antes de que se inscriban, o una mezcla de ambos. Cada curso se convierte en la orden de pago del ingenio.</span></div>
            <div class="pp-toolbar-group">
                <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#cuentas-modal"><span class="glyphicon glyphicon-piggy-bank"></span> Cuentas bancarias</button>
                <a class="btn btn-default btn-sm" href="pagos_inscripcion.php?tab=proformas">Volver</a>
            </div>
        </div>
        <div class="pp-card-body">
            <form method="get" class="pp-toolbar" style="padding:0 0 12px;">
                <input type="hidden" name="nueva" value="1">
                <div class="pp-toolbar-group">
                    <label class="pp-sub" for="pf-ingenio" style="margin:0;">Ingenio / institución</label>
                    <select name="ingenio" id="pf-ingenio" onchange="this.form.submit()" style="min-width:260px;">
                        <option value="">Elige…</option>
                        <?php foreach ($ingenios as $i): ?><option value="<?php echo (int) $i['id']; ?>" <?php echo (int) $i['id'] === $ingenioSel ? 'selected' : ''; ?>><?php echo cengi_pp_h($i['nombre_ingenios']); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </form>

            <?php if (!$ingenioSel): ?>
                <p class="pp-empty">Elige el ingenio o la institución que solicita la proforma.</p>
            <?php else: ?>
            <form method="post" id="pf-form" class="pf-layout">
                <input type="hidden" name="accion" value="emitir"><input type="hidden" name="ingenio_id" value="<?php echo $ingenioSel; ?>">
                <div>
                    <input type="search" id="pf-buscar" class="form-control" placeholder="Buscar participante por nombre, CUI o correo…" style="margin-bottom:8px;">
                    <?php
                    $conInscritos = array_filter($cursosPf, static function ($p) use ($candidatos) { return !empty($candidatos[(int) $p['id']]); });
                    $sinInscritos = array_filter($cursosPf, static function ($p) use ($candidatos) { return empty($candidatos[(int) $p['id']]); });
                    $bloque = static function ($p) use ($candidatos, $selPost, $planAbierto) {
                        $pid = (int) $p['id'];
                        $lista = $candidatos[$pid] ?? [];
                        $disp = array_filter($lista, static function ($c) { return !$c['en_proforma']; });
                        $abierto = $pid === $planAbierto || array_intersect_key($selPost, array_flip(array_column($lista, 'id_solicitud'))) || (int) ($_POST['cupos'][$pid] ?? 0) > 0;
                        ob_start(); ?>
                        <details class="pf-curso" data-plan="<?php echo $pid; ?>" data-nombre="<?php echo cengi_pp_h($p['nombre']); ?>"
                            data-precio-asociado="<?php echo (float) $p['costo_asociados']; ?>" data-precio-externo="<?php echo (float) $p['costo_externos']; ?>" data-precio-extranjero="<?php echo (float) $p['costo_extranjero']; ?>"<?php echo $abierto ? ' open' : ''; ?>>
                            <summary><div style="flex:1;min-width:0;"><b><?php echo cengi_pp_h($p['nombre']); ?></b>
                                <div class="pp-sub">inicia <?php echo cengi_pp_fecha_corta($p['fecha_inicio']); ?> · <?php echo $lista ? count($disp) . ' inscrito(s) disponibles' . (count($lista) > count($disp) ? ' · ' . (count($lista) - count($disp)) . ' ya en otra proforma' : '') : 'sin inscritos de este ingenio'; ?> · <span data-precio-txt></span> c/u</div></div>
                                <span class="pp-chip is-ok" data-chip hidden></span></summary>
                            <div class="pf-curso-body">
                                <?php if ($disp): ?><label><input type="checkbox" data-todos> <b>Seleccionar todos (<?php echo count($disp); ?>)</b></label><?php endif; ?>
                                <?php foreach ($lista as $c): ?>
                                    <label class="<?php echo $c['en_proforma'] ? 'is-disabled' : ''; ?>" data-buscar="<?php echo cengi_pp_h(cengi_texto_normalizado($c['nombre_participante'] . ' ' . $c['cui_participante'] . ' ' . $c['correo'])); ?>">
                                        <input type="checkbox" name="sel[]" value="<?php echo (int) $c['id_solicitud']; ?>" <?php echo isset($selPost[(int) $c['id_solicitud']]) ? 'checked' : ''; ?> <?php echo $c['en_proforma'] ? 'disabled' : ''; ?>>
                                        <span><?php echo cengi_pp_h($c['nombre_participante']); ?> <span class="pp-sub" style="display:inline;">· CUI <?php echo cengi_pp_h($c['cui_participante'] ?: '—'); ?> · <?php echo $c['validacion_ingenio'] === 'validada' ? 'validado por el ingenio' : 'pendiente de validación'; ?><?php echo $c['en_proforma'] ? ' · en proforma N.° ' . (int) $c['en_proforma'] : ''; ?></span></span>
                                    </label>
                                <?php endforeach; ?>
                                <div class="pf-fila">
                                    <span>Cupos para personas <b>aún no inscritas</b></span><input type="number" min="0" max="500" name="cupos[<?php echo $pid; ?>]" value="<?php echo (int) ($_POST['cupos'][$pid] ?? 0); ?>" data-cupos aria-label="Cupos sin inscribir">
                                    <span style="margin-left:auto;">Descuento c/u</span>
                                    <select name="desc_tipo[<?php echo $pid; ?>]" data-desc-tipo aria-label="Tipo de descuento"><option value="monto" <?php echo ($_POST['desc_tipo'][$pid] ?? '') !== 'pct' ? 'selected' : ''; ?>>Monto</option><option value="pct" <?php echo ($_POST['desc_tipo'][$pid] ?? '') === 'pct' ? 'selected' : ''; ?>>%</option></select>
                                    <input type="number" min="0" step="0.01" name="desc_valor[<?php echo $pid; ?>]" value="<?php echo cengi_pp_h($_POST['desc_valor'][$pid] ?? '0'); ?>" data-desc-valor aria-label="Descuento por participante">
                                </div>
                            </div>
                        </details>
                        <?php return ob_get_clean();
                    };
                    ?>
                    <?php if ($conInscritos): ?><div class="pf-grupo-t">Cursos con inscritos del ingenio (pago pendiente)</div><?php foreach ($conInscritos as $p) { echo $bloque($p); } ?>
                    <?php else: ?><p class="pp-sub">Este ingenio no tiene inscritos con pago del ingenio pendiente. Puedes armar la proforma por cupos.</p><?php endif; ?>
                    <?php if ($sinInscritos): ?><div class="pf-grupo-t">Otros cursos con convocatoria (proforma antes de inscribir)</div><?php foreach ($sinInscritos as $p) { echo $bloque($p); } ?><?php endif; ?>
                    <?php if (!$cursosPf): ?><p class="pp-empty">No hay cursos convocados vigentes con costo.</p><?php endif; ?>
                </div>

                <div>
                    <div class="cengi-course-modal-grid">
                        <div class="form-group"><label for="pf-dirigido">Dirigido a</label><input class="form-control" id="pf-dirigido" name="dirigido" maxlength="255" value="<?php echo cengi_pp_h($cliente['dirigido']); ?>" required></div>
                        <div class="form-group"><label for="pf-contacto">Contacto (teléfono)</label><input class="form-control" id="pf-contacto" name="contacto" maxlength="80" value="<?php echo cengi_pp_h($cliente['contacto']); ?>"></div>
                        <div class="form-group"><label for="pf-correo">Correo electrónico</label><input type="email" class="form-control" id="pf-correo" name="correo" maxlength="255" value="<?php echo cengi_pp_h($cliente['correo']); ?>" required></div>
                        <div class="form-group"><label for="pf-nit">NIT</label><input class="form-control" id="pf-nit" name="nit" maxlength="30" value="<?php echo cengi_pp_h($cliente['nit']); ?>"></div>
                        <div class="form-group"><label for="pf-pais">País</label><input class="form-control" id="pf-pais" name="pais" maxlength="80" value="<?php echo cengi_pp_h($cliente['pais']); ?>" required></div>
                        <div class="form-group"><label for="pf-fecha">Fecha de emisión</label><input type="date" class="form-control" id="pf-fecha" name="fecha" value="<?php echo cengi_pp_h($_POST['fecha'] ?? $hoy); ?>" required></div>
                        <div class="form-group"><label for="pf-tipo">Tipo de cliente (cuota)</label><select class="form-control" id="pf-tipo" name="tipo_cliente">
                            <?php foreach (CENGI_PF_TIPOS as $k => $t): ?><option value="<?php echo $k; ?>" <?php echo $tipoSel === $k ? 'selected' : ''; ?>><?php echo $t . ($k === 'extranjero' ? ' (US$)' : ' (Q)'); ?></option><?php endforeach; ?>
                        </select></div>
                        <div class="form-group"><label for="pf-obs">Observación adicional</label><input class="form-control" id="pf-obs" name="obs" maxlength="500" value="<?php echo cengi_pp_h($_POST['obs'] ?? ''); ?>" placeholder="Ej. Descuento por grupo de más de 10"></div>
                    </div>
                    <label style="display:flex;gap:8px;align-items:center;font-weight:500;font-size:12.5px;"><input type="checkbox" name="cargo" value="1" id="pf-cargo" <?php echo !empty($_POST['cargo']) || (!$_POST && $tipoSel === 'extranjero') ? 'checked' : ''; ?>> Cargo por transferencia internacional
                        <input type="number" min="0" step="0.01" name="cargo_monto" id="pf-cargo-monto" value="<?php echo cengi_pp_h($_POST['cargo_monto'] ?? $cuentas['cargo']); ?>" style="width:90px;height:30px;border:1px solid var(--cengi-border);border-radius:6px;padding:2px 6px;"></label>
                    <div class="pp-table-wrap" style="margin-top:10px;"><table class="cengi-org-table" style="min-width:0;">
                        <thead><tr><th>Cant.</th><th>Curso</th><th class="pp-num">Precio</th><th class="pp-num">Desc. c/u</th><th class="pp-num">Total</th></tr></thead>
                        <tbody id="pf-lineas"><tr><td colspan="5" class="pp-empty">Abre un curso y selecciona participantes o indica cupos.</td></tr></tbody>
                    </table></div>
                    <div class="pf-totales" id="pf-totales"></div>
                    <?php foreach (['GTQ' => 'nacional', 'USD' => 'para pagos del extranjero'] as $mon => $txt): ?>
                        <?php if (!cengi_pf_cuenta_completa($cuentas[$mon])): ?><p class="pp-form-msg is-warn is-visible" data-falta-cuenta="<?php echo $mon; ?>">Falta configurar la cuenta <?php echo $txt; ?> (banco y número) en “Cuentas bancarias”.</p><?php endif; ?>
                    <?php endforeach; ?>
                    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">
                        <a class="btn btn-default" href="pagos_inscripcion.php?tab=proformas">Cancelar</a>
                        <button type="submit" class="btn btn-primary" id="pf-emitir" disabled>Emitir proforma</button>
                    </div>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </section>

<?php else:
    $est = $pf['estado_vista'];
    $activa = in_array($pf['estado'], ['emitida', 'enviada'], true);
    $libresTotal = 0;
    foreach ($pf['lineas'] as $l) {
        $libresTotal += max(0, (int) $l['cantidad'] - count($l['inscritos']));
    }
?>
    <section class="pp-card pf-no-print">
        <div class="pp-card-head">
            <div><h3>Proforma N.° <?php echo (int) $pf['numero']; ?> <?php echo cengi_pp_badge(CENGI_PF_ESTADOS, $est); ?></h3>
                <span class="pp-sub"><?php echo cengi_pp_h($pf['empresa'] . ' · ' . cengi_pf_dinero($pf['total'], $pf['moneda']) . ' · emitida ' . cengi_pp_fecha($pf['fecha']) . ' · vence ' . cengi_pp_fecha($pf['vence'])); ?>
                    <?php echo $pf['pago_referencia'] ? ' · pagada: ' . cengi_pp_h($pf['pago_forma'] . ' ' . $pf['pago_referencia'] . ' · ' . cengi_pp_fecha($pf['pago_fecha'])) : ''; ?></span></div>
            <div class="pp-toolbar-group">
                <a class="btn btn-default btn-sm" href="pagos_inscripcion.php?tab=proformas">Volver</a>
                <button type="button" class="btn btn-default btn-sm" onclick="window.print()"><span class="glyphicon glyphicon-print"></span> Imprimir</button>
                <a class="btn btn-default btn-sm" href="proforma.php?id=<?php echo (int) $pf['id']; ?>&amp;pdf=1"><span class="glyphicon glyphicon-download-alt"></span> PDF</a>
                <?php if ($puedeGestionar && $activa): ?>
                    <button type="button" class="btn btn-link btn-sm" style="color:#B23223;" data-toggle="modal" data-target="#anular-modal">Anular</button>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Se enviará la proforma en PDF a <?php echo cengi_pp_h($pf['correo']); ?>. ¿Continuar?');"><input type="hidden" name="accion" value="enviar"><input type="hidden" name="id" value="<?php echo (int) $pf['id']; ?>">
                        <button type="submit" class="btn btn-default btn-sm"><span class="glyphicon glyphicon-envelope"></span> <?php echo $pf['estado'] === 'enviada' ? 'Reenviar al cliente' : 'Enviar al cliente'; ?></button></form>
                    <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#pago-pf-modal">Registrar pago</button>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="pf-layout" style="grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);">
        <div class="pf-doc-wrap"><?php echo cengi_pf_html($pf, $cuentas); ?></div>
        <section class="pp-card pf-no-print" style="margin:0;">
            <div class="pp-card-head"><div><h3>Participantes amarrados</h3><span class="pp-sub">✓ = validado. Al registrar el pago, todos quedan validados y cuentan para el cupo del curso.<?php echo $libresTotal && $pf['estado'] !== 'anulada' ? ' Los cupos libres se llenan solos cuando el ingenio inscribe con “Lo paga mi ingenio”.' : ''; ?></span></div></div>
            <div class="pp-card-body">
                <?php foreach ($pf['lineas'] as $l): $libres = max(0, (int) $l['cantidad'] - count($l['inscritos'])); ?>
                    <div class="pf-amarre">
                        <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;">
                            <b><?php echo cengi_pp_h($l['descripcion']); ?></b>
                            <span class="pp-sub" style="display:inline;"><?php echo count($l['inscritos']) . ' de ' . (int) $l['cantidad']; ?> con nombre<?php echo $libres ? ' · <b style="color:#8A6600;">' . $libres . ' libre(s)</b>' : ''; ?></span>
                        </div>
                        <div class="pp-sub"><?php echo $l['inscritos'] ? cengi_pp_h(implode(', ', array_map(static function ($s) {
                            return $s['nombre_participante'] . ($s['validacion_ingenio'] === 'validada' ? ' ✓' : '');
                        }, $l['inscritos']))) : 'Aún sin participantes.'; ?></div>
                        <?php if ($puedeGestionar && $libres && $pf['estado'] !== 'anulada' && $l['grupo_pago_id']):
                            $cands = array_values(array_filter(cengi_pf_candidatos($db, $pf['ingenio_id'], $l['planificacion_id'])[(int) $l['planificacion_id']] ?? [], static function ($c) { return !$c['en_proforma']; })); ?>
                            <?php if ($cands): ?>
                                <form method="post" style="margin-top:6px;"><input type="hidden" name="accion" value="amarrar"><input type="hidden" name="id" value="<?php echo (int) $pf['id']; ?>"><input type="hidden" name="linea" value="<?php echo (int) $l['id']; ?>">
                                    <details><summary class="pp-link" style="cursor:pointer;">Amarrar inscritos (<?php echo count($cands); ?> disponibles)</summary>
                                        <div style="padding:6px 0;"><?php foreach ($cands as $c): ?><label style="display:flex;gap:6px;font-weight:500;font-size:12.5px;margin:0 0 4px;"><input type="checkbox" name="ids[]" value="<?php echo (int) $c['id_solicitud']; ?>"> <?php echo cengi_pp_h($c['nombre_participante']); ?> <span class="pp-sub" style="display:inline;">· CUI <?php echo cengi_pp_h($c['cui_participante'] ?: '—'); ?></span></label><?php endforeach; ?>
                                            <button type="submit" class="btn btn-default btn-sm">Amarrar (máximo <?php echo $libres; ?>)</button></div></details>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <h4 style="font-size:13px;margin:14px 0 6px;">Seguimiento</h4>
                <?php foreach ($pf['historial'] as $h): ?>
                    <div class="pp-sub"><?php echo date('d/m/Y H:i', strtotime($h['creado'])) . ' · <b>' . cengi_pp_h($h['texto']) . '</b> · ' . cengi_pp_h($h['por']) . ($h['detalle'] !== '' ? ' — ' . cengi_pp_h($h['detalle']) : ''); ?></div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <?php if ($puedeGestionar && $activa): ?>
    <div class="modal fade cengi-course-form-modal pp-modal" id="pago-pf-modal" tabindex="-1" role="dialog" aria-labelledby="pago-pf-title">
        <div class="modal-dialog" role="document"><div class="modal-content"><form method="post">
            <input type="hidden" name="accion" value="pago"><input type="hidden" name="id" value="<?php echo (int) $pf['id']; ?>">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><span class="cengi-course-modal-kicker">Registrar pago</span>
                <h4 class="modal-title" id="pago-pf-title">Proforma N.° <?php echo (int) $pf['numero']; ?> · <?php echo cengi_pp_h(cengi_pf_dinero($pf['total'], $pf['moneda'])); ?></h4>
                <p><?php echo cengi_pp_h($pf['empresa']); ?>. Las órdenes quedan pagadas y los participantes amarrados quedan validados (pasan a Inscripciones para asignarse al curso).</p></div>
            <div class="modal-body"><div class="cengi-course-modal-grid">
                <div class="form-group"><label for="pfp-forma">Forma de pago</label><select class="form-control" id="pfp-forma" name="pago_forma"><option>Transferencia</option><option>Depósito</option><option>Cheque</option><option>Tarjeta</option></select></div>
                <div class="form-group"><label for="pfp-ref">Referencia</label><input class="form-control" id="pfp-ref" name="pago_referencia" maxlength="120" required></div>
                <div class="form-group"><label for="pfp-fecha">Fecha del ingreso</label><input type="date" class="form-control" id="pfp-fecha" name="pago_fecha" value="<?php echo $hoy; ?>" max="<?php echo $hoy; ?>" required></div>
            </div></div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Marcar como pagada</button></div>
        </form></div></div>
    </div>
    <div class="modal fade cengi-course-form-modal pp-modal" id="anular-modal" tabindex="-1" role="dialog" aria-labelledby="anular-title">
        <div class="modal-dialog" role="document"><div class="modal-content"><form method="post">
            <input type="hidden" name="accion" value="anular"><input type="hidden" name="id" value="<?php echo (int) $pf['id']; ?>">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><h4 class="modal-title" id="anular-title">Anular la proforma N.° <?php echo (int) $pf['numero']; ?></h4>
                <p>Los participantes amarrados vuelven a la orden normal de su ingenio, con la cuota regular y sin descuento.</p></div>
            <div class="modal-body"><div class="form-group"><label for="anular-motivo">Motivo</label><input class="form-control" id="anular-motivo" name="motivo" maxlength="500" required></div></div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-danger">Anular</button></div>
        </form></div></div>
    </div>
    <?php endif; ?>
<?php endif; ?>
</main>

<?php if ($puedeGestionar) { cengi_pf_modal_cuentas($cuentas, $volverAqui); } ?>

<?php if ($nueva && $ingenioSel): ?>
<script>
(function () {
    'use strict';
    var form = document.getElementById('pf-form');
    var tipo = document.getElementById('pf-tipo');
    var cargo = document.getElementById('pf-cargo');
    var cargoMonto = document.getElementById('pf-cargo-monto');
    var btn = document.getElementById('pf-emitir');
    function num(v) { return (Math.round(v * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function render() {
        var t = tipo.value, mon = t === 'extranjero' ? 'US$' : 'Q', filas = [], sub = 0, n = 0;
        form.querySelectorAll('.pf-curso').forEach(function (c) {
            var precio = parseFloat(c.getAttribute('data-precio-' + t)) || 0;
            c.querySelector('[data-precio-txt]').textContent = mon + num(precio);
            var sel = c.querySelectorAll('input[name="sel[]"]:checked').length;
            var cupos = Math.max(0, parseInt(c.querySelector('[data-cupos]').value, 10) || 0);
            var dv = Math.max(0, parseFloat(c.querySelector('[data-desc-valor]').value) || 0);
            var desc = Math.min(precio, c.querySelector('[data-desc-tipo]').value === 'pct' ? precio * Math.min(100, dv) / 100 : dv);
            var cant = sel + cupos, chip = c.querySelector('[data-chip]');
            c.classList.toggle('is-active', cant > 0);
            chip.hidden = !cant; chip.textContent = (sel ? sel + ' seleccionado(s)' : '') + (sel && cupos ? ' + ' : '') + (cupos ? cupos + ' cupo(s)' : '');
            if (!cant) return;
            var total = cant * (precio - desc); sub += total; n += cant;
            filas.push('<tr><td class="pp-num">' + cant + (cupos ? '<div class="pp-sub">' + sel + ' + ' + cupos + ' cupos</div>' : '') + '</td><td><b>' + esc(c.getAttribute('data-nombre')) + '</b>' + (precio <= 0 ? '<div class="pp-sub" style="color:#B23223;">sin costo para este tipo de cliente</div>' : '') + '</td><td class="pp-num">' + mon + num(precio) + '</td><td class="pp-num">' + (desc ? '−' + mon + num(desc) : '—') + '</td><td class="pp-num"><b>' + mon + num(total) + '</b></td></tr>');
        });
        document.getElementById('pf-lineas').innerHTML = filas.join('') || '<tr><td colspan="5" class="pp-empty">Abre un curso y selecciona participantes o indica cupos.</td></tr>';
        var cg = cargo.checked ? Math.max(0, parseFloat(cargoMonto.value) || 0) : 0;
        document.getElementById('pf-totales').innerHTML = (cg ? '<div class="pp-mini-row"><span>Subtotal servicios</span><b>' + mon + num(sub) + '</b></div><div class="pp-mini-row"><span>Cargo por transferencia internacional</span><b>' + mon + num(cg) + '</b></div>' : '')
            + '<div class="pp-mini-row" style="font-size:14px;"><span><b>Total</b> · ' + n + ' participante(s)</span><b>' + mon + num(sub + cg) + '</b></div>';
        btn.disabled = !n;
        document.querySelectorAll('[data-falta-cuenta]').forEach(function (m) { m.hidden = m.getAttribute('data-falta-cuenta') !== (t === 'extranjero' ? 'USD' : 'GTQ'); });
    }
    form.addEventListener('input', render);
    form.addEventListener('change', function (e) {
        if (e.target.hasAttribute('data-todos')) {
            e.target.closest('.pf-curso').querySelectorAll('input[name="sel[]"]:not(:disabled)').forEach(function (cb) { cb.checked = e.target.checked; });
        }
        if (e.target === tipo) { cargo.checked = tipo.value === 'extranjero'; }
        render();
    });
    document.getElementById('pf-buscar').addEventListener('input', function () {
        var q = this.value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ''); // igual que cengi_texto_normalizado()
        form.querySelectorAll('label[data-buscar]').forEach(function (l) {
            var ok = !q || l.getAttribute('data-buscar').indexOf(q) !== -1;
            l.style.display = ok ? '' : 'none';
            if (q && ok) { l.closest('details').open = true; }
        });
    });
    render();
})();
</script>
<?php endif; ?>
</body>
</html>
