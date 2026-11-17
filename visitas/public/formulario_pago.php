<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/VisitStorage.php';

function payment_page_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function payment_page_message(string $message, bool $success = false): void
{
    $title = $success ? 'Comprobante recibido' : 'No fue posible procesar la solicitud';
    $safeMessage = payment_page_escape($message);
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . ' · CENGICAÑA</title><style>' . payment_page_styles() . '</style><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;family=Space+Grotesk:wght@500;600;700&amp;display=swap" rel="stylesheet"><link rel="stylesheet" href="../assets/module-theme.css?v=20261008-1"></head><body class="visits-public"><main class="card"><div class="brand"><img src="/login/assets/img/logo.png" alt="CENGICAÑA"><div><strong>Visitas</strong><small>CENGICAÑA</small></div></div><div class="notice ' . ($success ? 'success' : 'error') . '"><h1>' . $title . '</h1><p>' . $safeMessage . '</p></div></main></body></html>';
    exit;
}

function payment_page_styles(): string
{
    return ' :root{font-family:Segoe UI,Arial,sans-serif;color:#183b2b;background:#f3f7f4}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px}.card{width:min(1180px,100%);background:#fff;border:1px solid #d8e6dc;border-radius:18px;box-shadow:0 18px 50px #1d513019;padding:28px}.brand{display:flex;align-items:center;gap:12px;border-bottom:1px solid #e2ece5;padding-bottom:18px}.mark{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:#2e7d32;color:#fff;font-size:22px;font-weight:800}.brand strong{display:block;font-size:20px}.brand small{display:block;color:#738279;margin-top:2px}.eyebrow{color:#2e7d32;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin:24px 0 5px}.title{margin:0;font-size:28px;color:#173e2b}.payment-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(360px,.9fr);gap:28px;align-items:start}.form-panel{min-width:0}.preview-panel{min-height:520px;padding:20px;border:1px solid #d8e6dc;border-radius:14px;background:#f7fbf8;position:sticky;top:24px}.preview-panel h2{margin:0;color:#173e2b;font-size:19px}.preview-panel p{color:#718077;font-size:13px;line-height:1.5}.preview-empty{min-height:390px;display:grid;place-items:center;text-align:center;border:1px dashed #b9d5bd;border-radius:11px;color:#718077;padding:24px}.preview-content{display:none;margin-top:14px}.preview-panel.ready .preview-empty{display:none}.preview-panel.ready .preview-content{display:block}.preview-content img,.preview-content iframe{display:block;width:100%;height:420px;border:1px solid #d8e6dc;border-radius:9px;background:#fff;object-fit:contain}.preview-meta{margin-top:9px;color:#718077;font-size:12px;overflow-wrap:anywhere}.summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:18px 0}.summary div{padding:12px;border:1px solid #dce9df;border-radius:10px;background:#f7fbf8}.summary small{display:block;color:#718077;font-size:11px;margin-bottom:4px}.summary strong{display:block;overflow-wrap:anywhere}.total{display:flex;align-items:center;justify-content:space-between;padding:15px 17px;border-radius:11px;background:#eaf5eb;color:#1e6b2b;margin:16px 0;font-size:18px}.total strong{font-size:24px}.file{display:flex;align-items:center;justify-content:center;gap:8px;min-height:62px;border:1px dashed #9fc6a5;border-radius:11px;background:#f6fbf7;color:#286b34;font-weight:600;cursor:pointer}.file:hover{border-color:#2e7d32;background:#edf8ef}.file input{display:none}.selected{display:block;color:#718077;font-size:12px;margin-top:8px}.button{width:100%;border:0;border-radius:10px;padding:13px 16px;background:#2e7d32;color:#fff;font-size:15px;font-weight:700;cursor:pointer;margin-top:15px}.button:hover{background:#25652a}.notice{margin-top:24px;padding:18px;border-radius:11px;background:#f7fbf8;border:1px solid #dce9df}.notice h1{margin:0 0 8px;font-size:22px}.notice p{margin:0;line-height:1.55}.notice.success{background:#edf8ef;border-color:#bce0c1}.notice.error{background:#fff4f2;border-color:#edc5bf;color:#8e3027}.hint{font-size:12px;color:#718077;line-height:1.45;margin-top:10px}@media(max-width:820px){.payment-layout{grid-template-columns:1fr}.preview-panel{position:static;min-height:0}.preview-empty{min-height:180px}.preview-content img,.preview-content iframe{height:360px}}@media(max-width:560px){.card{padding:20px}.summary{grid-template-columns:1fr}.title{font-size:24px}.preview-content img,.preview-content iframe{height:300px}}';
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{40}$/i', $token)) {
    payment_page_message('El enlace de pago no es válido o ya no está disponible.');
}

try {
    $conn = Conexion::conectar();
    $areasAggregate = Conexion::groupConcat('ai.nombre_area', true);
    $sql = "SELECT s.id_solicitud,s.fecha_visita,s.hora_visita,s.cantidad_visitantes,
                   so.nombre_solicitante,so.nombre_institucion,so.correo,
                   e.nombre_estado,COALESCE(m.total,0) AS total,m.id_estado_pago,
                   m.ruta_comprobante_pago,m.nombre_comprobante_pago,{$areasAggregate} AS areas
            FROM solicitud_museo m
            INNER JOIN solicitudes s ON s.id_solicitud=m.id_solicitud
            INNER JOIN solicitantes so ON so.id_solicitante=s.id_solicitante
            INNER JOIN estados e ON e.id_estado=s.id_estado
            LEFT JOIN solicitud_areas sa ON sa.id_solicitud=s.id_solicitud
            LEFT JOIN areas_interes ai ON ai.id_area=sa.id_area
            WHERE m.pago_token=?
            GROUP BY s.id_solicitud,s.fecha_visita,s.hora_visita,s.cantidad_visitantes,
                     so.nombre_solicitante,so.nombre_institucion,so.correo,e.nombre_estado,
                     m.total,m.id_estado_pago,m.ruta_comprobante_pago,m.nombre_comprobante_pago";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$token]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$visit || (float) $visit['total'] <= 0) {
        payment_page_message('El enlace de pago no corresponde a una visita comercial vigente.');
    }
    $state = strtoupper(trim((string) $visit['nombre_estado']));
    if (!in_array($state, ['APROBADO', 'NOTIFICADO'], true)) {
        payment_page_message('Esta solicitud todavía no está habilitada para recibir comprobantes.');
    }

    $confirmation = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $file = $_FILES['comprobante'] ?? null;
        if (!is_array($file)) payment_page_message('Selecciona un PDF o imagen del comprobante.');
        [$storedPath, $storedName] = VisitStorage::storeUploadedFile($file, 'comprobantes', ['pdf','jpg','jpeg','png','webp']);
        $conn->beginTransaction();
        $update = $conn->prepare('UPDATE solicitud_museo SET ruta_comprobante_pago=?,nombre_comprobante_pago=?,fecha_comprobante_pago=CURRENT_TIMESTAMP WHERE id_solicitud=?');
        $update->execute([$storedPath, $storedName, (int) $visit['id_solicitud']]);
        $event = $conn->prepare("INSERT INTO solicitud_eventos_visita (id_solicitud,tipo,descripcion,actor) VALUES (?, 'pago', ?, ?)");
        $event->execute([(int) $visit['id_solicitud'], 'Comprobante de pago enviado por el solicitante para revisión.', 'Solicitante']);
        $conn->commit();
        $confirmation = true;
        $visit['nombre_comprobante_pago'] = $storedName;
    }
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) $conn->rollBack();
    payment_page_message($error->getMessage());
}

if ($confirmation) {
    payment_page_message('Recibimos el archivo de la solicitud #' . (int) $visit['id_solicitud'] . '. El equipo de CENGICAÑA lo revisará y actualizará el estado del pago.', true);
}
$areas = trim((string) ($visit['areas'] ?? '')) ?: 'Visita a CENGICAÑA';
$already = trim((string) ($visit['nombre_comprobante_pago'] ?? '')) !== '';
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Comprobante de pago · CENGICAÑA</title><style><?= payment_page_styles() ?></style><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;family=Space+Grotesk:wght@500;600;700&amp;display=swap" rel="stylesheet"><link rel="stylesheet" href="../assets/module-theme.css?v=20261008-1"></head><body class="visits-public"><main class="card"><div class="brand"><img src="/login/assets/img/logo.png" alt="CENGICAÑA"><div><strong>Visitas</strong><small>CENGICAÑA</small></div></div><div class="payment-layout"><section class="form-panel"><p class="eyebrow">Formulario seguro de pago</p><h1 class="title">Adjuntar comprobante</h1><div class="summary"><div><small>Solicitante</small><strong><?= payment_page_escape((string) $visit['nombre_solicitante']) ?></strong></div><div><small>Institución</small><strong><?= payment_page_escape((string) $visit['nombre_institucion']) ?></strong></div><div><small>Fecha y hora</small><strong><?= payment_page_escape((string) $visit['fecha_visita']) ?> · <?= payment_page_escape(substr((string) $visit['hora_visita'],0,5)) ?></strong></div><div><small>Áreas</small><strong><?= payment_page_escape($areas) ?></strong></div><div><small>Visitantes</small><strong><?= (int) $visit['cantidad_visitantes'] ?></strong></div><div><small>Solicitud</small><strong>#<?= (int) $visit['id_solicitud'] ?></strong></div></div><div class="total"><span>Total de la visita</span><strong>Q <?= number_format((float) $visit['total'],2) ?></strong></div><form method="post" enctype="multipart/form-data"><input type="hidden" name="token" value="<?= payment_page_escape($token) ?>"><label class="file"><input id="paymentFile" type="file" name="comprobante" accept=".pdf,.jpg,.jpeg,.png,.webp" required><span id="fileLabel"><?= $already ? 'Reemplazar comprobante' : 'Seleccionar comprobante (PDF o imagen)' ?></span></label><span id="fileName" class="selected">Tamaño máximo: 10 MB. El comprobante quedará pendiente de revisión.</span><button class="button" type="submit">Enviar comprobante</button></form></section><aside id="previewPanel" class="preview-panel"><h2>Vista preliminar</h2><p>La vista del documento aparecerá aquí antes de enviarlo.</p><div class="preview-empty"><div><strong>Sin documento seleccionado</strong><br><span>Adjunta un PDF o una imagen para revisarlo.</span></div></div><div class="preview-content"><img id="previewImage" alt="Vista preliminar del comprobante"><iframe id="previewPdf" title="Vista preliminar del PDF" loading="lazy"></iframe><div id="previewMeta" class="preview-meta"></div></div></aside></div></main><script>const input=document.getElementById('paymentFile'),label=document.getElementById('fileLabel'),fileName=document.getElementById('fileName'),panel=document.getElementById('previewPanel'),image=document.getElementById('previewImage'),pdf=document.getElementById('previewPdf'),meta=document.getElementById('previewMeta');let previewUrl='';input?.addEventListener('change',()=>{const file=input.files?.[0];if(!file)return;if(previewUrl)URL.revokeObjectURL(previewUrl);previewUrl=URL.createObjectURL(file);label.textContent='Cambiar comprobante';fileName.textContent=file.name+' · '+(file.size/1024/1024).toFixed(2)+' MB';meta.textContent=(file.type||'Archivo')+' · vista previa local';panel.classList.add('ready');if(file.type==='application/pdf'){image.style.display='none';pdf.style.display='block';pdf.src=previewUrl}else if(file.type.startsWith('image/')){pdf.style.display='none';image.style.display='block';image.src=previewUrl}else{image.style.display='none';pdf.style.display='none';meta.textContent='Formato seleccionado. La vista previa no está disponible, pero puedes enviarlo.'}});window.addEventListener('beforeunload',()=>{if(previewUrl)URL.revokeObjectURL(previewUrl)});</script></body></html>
