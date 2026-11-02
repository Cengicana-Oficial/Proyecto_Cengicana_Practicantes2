<?php
/**
 * Página pública (sin inicio de sesión) para que un participante con pago propio envíe o
 * reemplace el comprobante de su inscripción. Se llega por el enlace firmado de los
 * recordatorios de pago: ?s=<id_solicitud>&t=<token> (cobranza_helpers.php).
 * El comprobante queda "por validar" en Pagos de inscripción.
 */
require_once __DIR__ . '/cobranza_helpers.php';

$db = conectar();
$id = (int) ($_GET['s'] ?? $_POST['s'] ?? 0);
$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$s = $id ? cengi_ins_solicitud($db, $id) : null;
if ($s) {
    $plan = cengi_pp_plan($db, $s['planificacion_id']);
    $s['fecha_inicio'] = $plan['fecha_inicio'] ?? null;
}
$valido = $s && cengi_cob_token_valido($db, $s, $token);
$error = '';
$enviado = false;

$h = static function ($t) {
    return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
};

// Estado: solo un pago propio, de una inscripción vigente y aún no validado, admite comprobante.
$estado = 'abierto';
if (!$valido) {
    $estado = 'invalido';
} elseif ($s['estado'] === 'Rechazado' || ($plan['estado'] ?? '') === 'cancelado') {
    $estado = 'cerrado';
} elseif ($s['tipo_pago'] !== 'Propio') {
    $estado = 'no-aplica';
} elseif (in_array($s['pago_estado'], ['validado', 'pagado'], true)) {
    $estado = 'validado';
}

if ($estado === 'abierto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $boleta = mb_substr(trim((string) ($_POST['boleta'] ?? $s['boleta'] ?? '')), 0, 60, 'UTF-8');
        $fecha = (string) ($_POST['pago_fecha'] ?? $s['pago_fecha'] ?? '');
        $monto = (string) ($_POST['monto_comprobante'] ?? '');
        if ($boleta === '' || !cengi_pp_es_fecha($fecha) || $fecha > cengi_pp_hoy() || !is_numeric($monto) || (float) $monto <= 0) {
            throw new InvalidArgumentException('Indica el número de boleta o transferencia, la fecha del pago (no futura) y el monto pagado.');
        }
        if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] === UPLOAD_ERR_NO_FILE) {
            throw new InvalidArgumentException('Adjunta el comprobante (PDF, JPG o PNG).');
        }
        [$ruta, $nombre] = cengi_ins_guardar_comprobante($_FILES['archivo'], 'p' . (int) $s['planificacion_id']);
        $db->beginTransaction();
        $actual=cengi_ins_solicitud($db,$id,true);
        if(!$actual || $actual['estado']==='Rechazado' || in_array($actual['pago_estado'],['validado','pagado'],true))throw new InvalidArgumentException('El pago ya fue gestionado. Actualiza la página.');
        $db->prepare("UPDATE solicitudes_inscripcion SET boleta = ?, pago_fecha = ?, monto_comprobante = ?, comprobante_ruta = ?, comprobante_nombre = ?,
            pago_estado = 'en revisión', pago_nota = '',pago_validado_por='',pago_validado_rol='',pago_validado_en=NULL,pago_validacion_evidencia=NULL WHERE id_solicitud = ? AND tipo_pago = 'Propio' AND COALESCE(pago_estado, '') NOT IN ('validado', 'pagado')")
            ->execute([$boleta, $fecha, round((float) $monto, 2), $ruta, $nombre, $s['id_solicitud']]);
        $db->prepare('INSERT INTO inscripcion_historial(solicitud_id,accion,usuario,motivo,antes,despues) VALUES(?,?,?,?,?,?)')->execute([$id,'actualizar_boleta','Participante mediante enlace seguro','Comprobante nuevo; requiere nueva validación',json_encode($actual,JSON_UNESCAPED_UNICODE),json_encode(cengi_ins_solicitud($db,$id),JSON_UNESCAPED_UNICODE)]);
        $db->commit();
        $enviado = true;
        $s = cengi_ins_solicitud($db, $id) + ['fecha_inicio' => $plan['fecha_inicio'] ?? null];
    } catch (InvalidArgumentException $e) {
        if($db->inTransaction())$db->rollBack();
        $error = $e->getMessage();
    } catch (Throwable $e) {
        if($db->inTransaction())$db->rollBack();
        error_log('Comprobante público: ' . $e->getMessage());
        $error = 'No fue posible guardar el comprobante. Intenta de nuevo más tarde.';
    }
}
$cuenta = $valido ? cengi_cob_texto_cuenta($db, $s['moneda']) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Comprobante de pago · CENGICAÑA</title>
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
    <style>
        :root { --verde: #73BC25; --verde2: #A3D300; --amarillo: #FFCC00; --naranja: #FF6B00; --gris: #CED2D5; --deep: #1f5632; --ink: #1E2A1A; --muted: #55705F; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #F5F6F7; color: var(--ink); font: 15px/1.5 "Inter", "Segoe UI", Arial, sans-serif; }
        .franja { height: 6px; background: linear-gradient(90deg, var(--verde) 0 25%, var(--verde2) 25% 50%, var(--amarillo) 50% 75%, var(--naranja) 75% 100%); }
        header { padding: 22px 16px; background: var(--deep); color: #fff; text-align: center; }
        header b { display: block; font-size: 20px; letter-spacing: .02em; }
        header span { color: var(--verde2); font-size: 13px; }
        main { max-width: 620px; margin: 24px auto 48px; padding: 0 16px; }
        .tarjeta { padding: 22px; border: 1px solid #E4E7E1; border-radius: 14px; background: #fff; box-shadow: 0 6px 20px -12px rgba(31, 86, 50, .35); }
        h1 { margin: 0 0 4px; font-size: 21px; }
        .sub { margin: 0 0 18px; color: var(--muted); font-size: 14px; }
        .resumen { display: grid; gap: 6px; margin-bottom: 18px; padding: 14px; border-radius: 10px; background: #F4FAD9; }
        .resumen div { display: flex; justify-content: space-between; gap: 12px; font-size: 14px; }
        .resumen b { text-align: right; }
        .cuenta { margin: 0 0 18px; padding: 12px 14px; border-left: 4px solid var(--amarillo); border-radius: 8px; background: #FFF7D6; font-size: 13.5px; white-space: pre-line; }
        label { display: block; margin: 12px 0 4px; font-size: 13px; font-weight: 700; }
        input { width: 100%; padding: 10px 12px; border: 1px solid var(--gris); border-radius: 9px; font: inherit; background: #fff; }
        input:focus { border-color: var(--verde); outline: 0; box-shadow: 0 0 0 3px rgba(115, 188, 37, .22); }
        .fila { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        button { width: 100%; margin-top: 20px; padding: 12px; border: 0; border-radius: 10px; background: var(--deep); color: #fff; font: 700 15px/1 inherit; cursor: pointer; }
        button:hover { background: #163d24; }
        .aviso { margin-bottom: 16px; padding: 12px 14px; border-radius: 10px; font-size: 14px; }
        .aviso.ok { border: 1px solid #CFE6B4; background: #EEF7E3; color: var(--deep); }
        .aviso.error { border: 1px solid #F3B5AE; background: #FDF0EE; color: #8E2A1E; }
        .aviso.info { border: 1px solid #F5E39A; background: #FFF7D6; color: #5f4700; }
        .ayuda { margin-top: 6px; color: var(--muted); font-size: 12.5px; }
        footer { margin-top: 18px; color: #8A958C; font-size: 12px; text-align: center; }
        @media (max-width: 520px) { .fila { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="franja"></div>
<header><b>CENGICAÑA</b><span>Coordinación de Capacitación</span></header>
<main>
    <div class="tarjeta">
        <?php if ($estado === 'invalido'): ?>
            <h1>Enlace no válido</h1>
            <p class="sub">El enlace está incompleto o ya no es válido. Usa el enlace más reciente que te enviamos por correo o comunícate con la Coordinación de Capacitación.</p>
        <?php else: ?>
            <h1><?php echo $enviado ? '¡Comprobante recibido!' : 'Comprobante de pago'; ?></h1>
            <p class="sub">Hola <?php echo $h($s['nombre_participante']); ?>, esta es tu inscripción:</p>
            <div class="resumen">
                <div><span>Curso</span><b><?php echo $h($s['plan_nombre']); ?></b></div>
                <?php if ($s['fecha_inicio']): ?><div><span>Inicia</span><b><?php echo $h(cengi_pp_fecha($s['fecha_inicio'])); ?></b></div><?php endif; ?>
                <div><span>Monto a pagar</span><b><?php echo $h(cengi_ins_dinero($s['monto'], $s['moneda'])); ?></b></div>
                <div><span>Estado del pago</span><b><?php echo $h(CENGI_INS_ESTADOS_PAGO[$s['pago_estado'] ?: 'pendiente'][0] ?? 'Pendiente'); ?></b></div>
            </div>

            <?php if ($enviado): ?>
                <div class="aviso ok">Recibimos tu comprobante (boleta <?php echo $h($s['boleta']); ?>). Administración lo revisará y te avisará por correo cuando quede validado. Si te equivocaste, puedes enviarlo de nuevo con este mismo enlace.</div>
            <?php elseif ($estado === 'validado'): ?>
                <div class="aviso ok">Tu pago ya está validado. No necesitas enviar nada más.</div>
            <?php elseif ($estado === 'no-aplica'): ?>
                <div class="aviso info">Tu inscripción no es de pago propio (la cubre tu ingenio o no tiene costo): no necesitas enviar comprobante.</div>
            <?php elseif ($estado === 'cerrado'): ?>
                <div class="aviso info">Esta inscripción ya no está activa. Si tienes dudas, comunícate con la Coordinación de Capacitación.</div>
            <?php endif; ?>

            <?php if ($estado === 'abierto'): ?>
                <?php if ($error !== ''): ?><div class="aviso error"><?php echo $h($error); ?></div><?php endif; ?>
                <?php if ($s['pago_estado'] === 'rechazado' && !$enviado): ?><div class="aviso error">Tu comprobante anterior no se pudo validar<?php echo $s['pago_nota'] ? ': ' . $h($s['pago_nota']) : ''; ?>. Envía uno nuevo.</div><?php endif; ?>
                <?php if ($s['pago_estado'] === 'en revisión' && !$enviado): ?><div class="aviso info">Ya tenemos un comprobante tuyo en revisión. Envía otro solo si necesitas reemplazarlo.</div><?php endif; ?>
                <?php if ($cuenta !== '' && !$enviado): ?><div class="cuenta"><?php echo $h($cuenta); ?></div><?php endif; ?>
                <?php if (!$enviado): ?>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="s" value="<?php echo (int) $s['id_solicitud']; ?>">
                        <input type="hidden" name="t" value="<?php echo $h($token); ?>">
                        <label for="boleta">N.° de boleta o transferencia</label>
                        <input type="text" id="boleta" name="boleta" maxlength="60" required value="<?php echo $h($_POST['boleta'] ?? $s['boleta'] ?? ''); ?>">
                        <div class="fila">
                            <div><label for="fecha">Fecha del pago</label><input type="date" id="fecha" name="pago_fecha" max="<?php echo cengi_pp_hoy(); ?>" required value="<?php echo $h($_POST['pago_fecha'] ?? $s['pago_fecha'] ?? ''); ?>"></div>
                            <div><label for="monto">Monto pagado (<?php echo $s['moneda'] === 'USD' ? 'US$' : 'Q'; ?>)</label><input type="number" id="monto" name="monto_comprobante" min="0" step="0.01" required value="<?php echo $h($_POST['monto_comprobante'] ?? $s['monto']); ?>"></div>
                        </div>
                        <label for="archivo">Comprobante</label>
                        <input type="file" id="archivo" name="archivo" accept=".pdf,.jpg,.jpeg,.png" required>
                        <p class="ayuda">PDF, JPG o PNG de hasta 5 MB (puede ser una foto legible de la boleta).</p>
                        <button type="submit">Enviar comprobante</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <footer>Centro Guatemalteco de Investigación y Capacitación de la Caña de Azúcar</footer>
</main>
<script>
    var a = document.getElementById('archivo');
    if (a) a.addEventListener('change', function () { if (this.files[0] && this.files[0].size > 5 * 1024 * 1024) { alert('El comprobante supera los 5 MB.'); this.value = ''; } });
</script>
</body>
</html>
