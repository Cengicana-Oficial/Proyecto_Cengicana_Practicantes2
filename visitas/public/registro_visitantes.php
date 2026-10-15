<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/VisitGuestForm.php';

$conn = Conexion::conectar();
$token = trim((string) ($_GET['token'] ?? ''));
$request = VisitGuestForm::find($conn, $token);

function h_guest($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (!$request) {
    http_response_code(404);
    $pageError = 'El enlace no existe, ya venció o fue generado incorrectamente.';
}

$status = strtolower(trim((string) ($request['nombre_estado'] ?? '')));
$blocked = isset($request) && in_array($status, ['rechazado', 'cancelado', 'cancelada'], true);
$maxVisitors = isset($request) ? max(1, (int) $request['cantidad_visitantes']) : 1;
$existingNames = isset($request) ? array_map(static fn(array $item): string => (string) $item['nombre_completo'], $request['visitantes'] ?? []) : [];
$rows = max(count($existingNames), $maxVisitors);
$rows = max(1, $rows);
$formError = trim((string) ($_GET['error'] ?? ''));
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registro de visitantes | CENGICAÑA</title>
    <style>
        :root{--green:#2e7d32;--green-dark:#1b5e20;--green-soft:#edf7ee;--blue:#1565c0;--text:#173b29;--muted:#6b7d72;--border:#d8e5dc}
        *{box-sizing:border-box}body{margin:0;background:#f4f7f5;color:var(--text);font-family:Segoe UI,Arial,sans-serif}
        .page{width:min(900px,calc(100% - 32px));margin:32px auto 48px}.brand{display:flex;align-items:center;gap:14px;margin-bottom:22px}.brand img{width:58px;height:58px;object-fit:contain;background:#fff;border-radius:14px;padding:7px}.brand strong{display:block;font-size:1.5rem;color:var(--green-dark)}.brand span{color:var(--muted);font-size:.92rem}
        .card{background:#fff;border:1px solid var(--border);border-radius:18px;box-shadow:0 12px 32px rgba(24,70,40,.08);padding:30px}.eyebrow{color:var(--green);font-weight:700;text-transform:uppercase;letter-spacing:.08em;font-size:.78rem}.card h1{margin:6px 0 8px;font-size:clamp(1.55rem,3vw,2.25rem)}.intro{margin:0 0 24px;color:var(--muted);line-height:1.55}
        .details{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:25px}.detail{padding:13px 15px;background:var(--green-soft);border-radius:11px}.detail small{display:block;color:var(--muted);font-size:.75rem;margin-bottom:4px}.detail strong{font-size:.96rem}
        .notice{padding:13px 15px;border-radius:10px;background:#fff6df;color:#76550b;margin-bottom:20px}.success{padding:16px;border-radius:11px;background:#e7f6e8;color:var(--green-dark);font-weight:600;margin-bottom:20px}.error{padding:16px;border-radius:11px;background:#fff0f0;color:#a52828;font-weight:600}
        .section-title{display:flex;justify-content:space-between;align-items:end;gap:12px;margin:22px 0 12px}.section-title h2{font-size:1.2rem;margin:0}.section-title span{font-size:.85rem;color:var(--muted)}
        .visitor-list{display:grid;gap:10px}.visitor-row{display:grid;grid-template-columns:44px 1fr;align-items:center;gap:10px}.visitor-number{height:42px;width:42px;border-radius:50%;display:grid;place-items:center;background:#e7f3e9;color:var(--green);font-weight:700}.visitor-row input{width:100%;border:1px solid var(--border);border-radius:9px;padding:11px 13px;font:inherit;color:var(--text)}.visitor-row input:focus{outline:2px solid rgba(21,101,192,.2);border-color:var(--blue)}
        .upload{margin-top:22px;padding:16px;border:1px dashed #b5cbb9;border-radius:11px;background:#fbfdfb}.upload label{display:block;font-weight:600;margin-bottom:7px}.upload small{display:block;color:var(--muted);margin-top:7px}.upload input{max-width:100%}
        .actions{display:flex;justify-content:flex-end;gap:10px;margin-top:25px}.button{border:0;border-radius:9px;padding:12px 20px;font:600 1rem inherit;cursor:pointer}.button.primary{background:var(--green);color:#fff}.button.primary:hover{background:var(--green-dark)}.button.secondary{background:#eef3ef;color:var(--text)}
        @media(max-width:700px){.page{width:min(100% - 18px,900px);margin-top:18px}.card{padding:21px}.details{grid-template-columns:1fr}.actions{flex-direction:column}.button{width:100%}}
    </style>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&amp;family=Space+Grotesk:wght@500;600;700&amp;display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/module-theme.css?v=20261008-1">
</head>
<body class="visits-public">
<main class="page">
    <div class="brand"><img src="../assets/img/logo.png" alt="Cengicaña"><div><strong>Visitas</strong></div></div>
    <section class="card">
        <?php if (isset($pageError)): ?>
            <div class="error"><?= h_guest($pageError) ?></div>
        <?php elseif ($blocked): ?>
            <div class="eyebrow">Formulario de visitantes</div>
            <h1>Solicitud no disponible</h1>
            <p class="intro">Esta visita se encuentra <?= h_guest($request['nombre_estado']) ?> y ya no puede recibir modificaciones.</p>
        <?php else: ?>
            <div class="eyebrow">Formulario de visitantes</div>
            <h1>Registra a las personas que asistirán</h1>
            <p class="intro">Completa los nombres de los visitantes para la visita programada. Puedes actualizar la información mientras el enlace permanezca vigente.</p>
            <div class="details">
                <div class="detail"><small>Institución</small><strong><?= h_guest($request['nombre_institucion']) ?></strong></div>
                <div class="detail"><small>Fecha y hora</small><strong><?= h_guest($request['fecha_visita']) ?> · <?= h_guest(substr((string) $request['hora_visita'], 0, 5)) ?></strong></div>
                <div class="detail"><small>Cantidad solicitada</small><strong><?= $maxVisitors ?> personas</strong></div>
            </div>
            <?php if ($formError !== ''): ?><div class="error"><?= h_guest($formError) ?></div><?php endif; ?>
            <?php if (!empty($request['fecha_completado'])): ?>
                <div class="success"><strong>Visitantes registrados</strong><br>El listado de asistentes ya fue recibido correctamente. Este formulario ya no permite modificaciones.</div>
            <?php else: ?>
            <form method="post" action="guardar_visitantes.php" enctype="multipart/form-data">
                <input type="hidden" name="token" value="<?= h_guest($token) ?>">
                <div class="section-title"><h2>Nombres de los visitantes</h2><span>Máximo <?= $maxVisitors ?> registros</span></div>
                <div class="visitor-list">
                    <?php for ($index = 0; $index < $rows; $index++): ?>
                        <label class="visitor-row"><span class="visitor-number"><?= $index + 1 ?></span><input type="text" name="visitantes[]" maxlength="180" value="<?= h_guest($existingNames[$index] ?? '') ?>" placeholder="Nombre completo del visitante" <?= $index === 0 ? 'required' : '' ?>></label>
                    <?php endfor; ?>
                </div>
                <div class="upload"><label for="listado_visitantes">Listado adjunto (opcional)</label><input type="file" id="listado_visitantes" name="listado_visitantes" accept=".pdf,.xlsx,.xls"><small>Puedes adjuntar un listado en PDF o Excel. Tamaño máximo: 10 MB.</small></div>
                <div class="actions"><button class="button secondary" type="reset">Limpiar</button><button class="button primary" type="submit">Guardar listado</button></div>
            </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
