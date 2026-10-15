<?php
require __DIR__ . '/bootstrap.php';
$id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
$error = ''; $done = false; $s = null;
try {
    $m = meta($id);
    if (!$token || empty($m['token']) || !hash_equals($m['token'],$token)) fail('Este enlace no es válido. Solicita el enlace de la encuesta a tu asesor.',404);
    $candidate = service($id);
    if (!empty($_SESSION['id_usuario'])) {
        $viewer = require_user();
        scope($viewer,$candidate);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') permit($viewer,4);
    }
    $s = $candidate;
    $done = (bool)query(primary(),'SELECT COUNT(*) FROM Respuestas WHERE ID_Servicio_Tecnico=?',[$id])->fetchColumn();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf(); storage()->beginTransaction(); save_response($id,$_POST,'Cliente',true); storage()->commit(); $done = true;
    }
} catch (Throwable $e) { if (storage()->inTransaction()) storage()->rollBack(); $error = $e instanceof DomainException ? $e->getMessage() : 'No pudimos guardar tu respuesta. Intenta nuevamente.'; }
?>
<!doctype html><html lang="es" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tu opinión · Cengicaña</title><link rel="stylesheet" href="assets/app.css"></head><body class="auth-page"><main class="auth-card survey-public">
<div class="sidebar-brand"><span class="logo-dot">C</span><div><strong>CENGICAÑA</strong><p>Encuesta de satisfacción</p></div></div>
<?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif ?>
<?php if ($done): ?><div class="success-symbol">✓</div><h1>¡Gracias por tu opinión!</h1><p>La respuesta de este servicio ya está registrada.</p>
<?php elseif ($s): ?><p class="eyebrow"><?= h($id) ?></p><h1>Tu experiencia nos ayuda a mejorar.</h1><p class="muted"><?= h($s['Tipo_Servicio']) ?> · <?= h($s['Area']) ?></p><div class="notice"><?= h($s['Descripcion_Actividad']) ?></div>
<form method="post" class="auth-form"><input type="hidden" name="csrf" value="<?= h($_SESSION['tech_csrf']) ?>"><label for="nombre">Nombre de quien responde</label><input id="nombre" name="nombre" required maxlength="120" autocomplete="name" value="<?= h($_POST['nombre'] ?? '') ?>"><label for="cargo">Cargo (opcional)</label><input id="cargo" name="cargo" maxlength="150" value="<?= h($_POST['cargo'] ?? '') ?>">
<fieldset class="rating-field"><legend>¿Cómo calificarías el servicio?</legend><div class="public-stars"><?php for ($n=1;$n<=5;$n++): ?><label><input type="radio" name="puntuacion" value="<?= $n ?>" required <?= ($_POST['puntuacion'] ?? '') == $n ? 'checked' : '' ?>><span>★<small><?= $n ?></small></span></label><?php endfor ?></div><p class="muted">1 = Muy insatisfecho · 5 = Muy satisfecho</p></fieldset>
<label for="comentario">Comentarios (opcional)</label><textarea id="comentario" name="comentario" maxlength="5000" rows="4"><?= h($_POST['comentario'] ?? '') ?></textarea><button type="submit" class="btn btn-secondary">Enviar mi respuesta →</button></form><?php endif ?></main></body></html>
