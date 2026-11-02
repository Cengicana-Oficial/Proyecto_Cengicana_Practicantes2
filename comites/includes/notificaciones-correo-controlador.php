<?php
declare(strict_types=1);

// Configuración > Notificaciones por correo: guarda la configuración de cada evento,
// envía correos de prueba y administra la cola de envío.

$_SESSION['correo_csrf'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with((string) ($_POST['accion'] ?? ''), 'correo_')) {
    $accionCorreo = (string) $_POST['accion'];
    $volverCorreo = 'index.php?view=notificaciones' . (preg_match('/^[a-z_]+$/', (string) ($_POST['tab'] ?? '')) ? '&tab=' . $_POST['tab'] : '');
    try {
        if (!can_manage_resource($user, 'configuracion')) {
            throw new RuntimeException('Solo quien administra la configuración puede cambiar las notificaciones.');
        }
        if (!hash_equals($_SESSION['correo_csrf'], (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('El formulario expiró. Recarga la página.');
        }
        if ($accionCorreo === 'correo_guardar') {
            correo_guardar_config($pdo, $_POST, (string) $user['nombre']);
            $msgCorreo = 'Configuración de notificaciones guardada.';
        } elseif ($accionCorreo === 'correo_prueba') {
            $evento = (string) ($_POST['evento'] ?? '');
            $cfg = correo_config($pdo)[$evento] ?? null;
            $destino = mb_strtolower(trim((string) ($user['correo'] ?? '')));
            if (!$cfg) {
                throw new RuntimeException('Evento no válido.');
            }
            if (!filter_var($destino, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Tu usuario no tiene un correo válido para recibir la prueba.');
            }
            if (!correo_smtp_listo()) {
                throw new RuntimeException('El servidor de correo (SMTP) no está configurado: completa MAIL_USERNAME, MAIL_PASSWORD y MAIL_FROM_ADDRESS en el archivo .env.');
            }
            $ejemplo = array_combine(array_keys($cfg['vars']), array_map(static fn($d) => '[' . $d . ']', $cfg['vars'])) + ['nombre' => (string) $user['nombre']];
            $enlace = correo_url_base($pdo) . '/index.php';
            $r = comites_enviar_correo($destino, (string) $user['nombre'], '[Prueba] ' . correo_reemplazar($cfg['asunto_efectivo'], $ejemplo), correo_html(correo_reemplazar($cfg['cuerpo_efectivo'], $ejemplo), $enlace));
            if (!$r['ok']) {
                throw new RuntimeException((string) $r['error']);
            }
            $msgCorreo = 'Correo de prueba enviado a ' . $destino . '.';
        } elseif ($accionCorreo === 'correo_enviar_cola') {
            if (!correo_smtp_listo()) {
                throw new RuntimeException('El servidor de correo (SMTP) no está configurado. Los correos siguen en la cola.');
            }
            [$enviados, $errores] = correo_despachar($pdo, 50);
            $msgCorreo = "$enviados correo(s) enviados" . ($errores ? " · $errores con error (se reintentarán)" : '') . '.';
        } elseif ($accionCorreo === 'correo_reintentar') {
            $n = $pdo->exec("UPDATE correo_cola SET estado = 'pendiente', intentos = 0, error = NULL WHERE estado = 'error'");
            $msgCorreo = "$n correo(s) con error volvieron a la cola.";
        } elseif ($accionCorreo === 'correo_descartar') {
            $n = $pdo->exec("DELETE FROM correo_cola WHERE estado IN ('pendiente','error')");
            $msgCorreo = "$n correo(s) pendientes descartados.";
        } elseif ($accionCorreo === 'correo_recordatorios') {
            $n = correo_recordatorios($pdo);
            correo_guardar_ajuste($pdo, 'recordatorios_fecha', date('Y-m-d'));
            $msgCorreo = $n ? "$n recordatorio(s) agregados a la cola." : 'No hay recordatorios nuevos para enviar hoy.';
        } else {
            throw new RuntimeException('Acción no válida.');
        }
        header('Location: ' . $volverCorreo . '&msg=' . urlencode($msgCorreo));
    } catch (Throwable $e) {
        header('Location: ' . $volverCorreo . '&error=' . urlencode($e instanceof RuntimeException ? $e->getMessage() : 'No se pudo guardar la configuración.'));
        if (!$e instanceof RuntimeException) {
            error_log('Notificaciones por correo: ' . $e->getMessage());
        }
    }
    exit;
}

if (can_view_resource($user, 'configuracion')) {
    $correoCfg = correo_config($pdo);
    $correoAjustes = [
        'activo' => correo_ajuste($pdo, 'activo', '1') === '1',
        'redirigir' => correo_ajuste($pdo, 'redirigir'),
        'url_base' => correo_url_base($pdo),
    ];
    $correoConteo = ['pendiente' => 0, 'enviando' => 0, 'enviado' => 0, 'error' => 0];
    foreach ($pdo->query('SELECT estado, COUNT(*) AS n FROM correo_cola GROUP BY estado') as $fila) {
        $correoConteo[$fila['estado']] = (int) $fila['n'];
    }
    $correoHistorial = $pdo->query('SELECT id, evento, destinatario, destinatario_original, asunto, estado, error, intentos, creado_en, enviado_en FROM correo_cola ORDER BY id DESC LIMIT 100')->fetchAll();
    $correoSmtp = correo_smtp_listo();
}
