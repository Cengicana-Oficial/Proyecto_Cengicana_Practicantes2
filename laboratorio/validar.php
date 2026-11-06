<?php
/**
 * Página pública de verificación de documentos del Laboratorio Agroindustrial (enlace del QR de la
 * boleta de recepción y del informe de resultados), con el mismo modelo que validar_diploma.php.
 * ?c=<código>: indica si el documento es auténtico y vigente y muestra solo los datos ya impresos en él.
 * No requiere sesión. Los códigos llevan una parte aleatoria, así que no se pueden recorrer.
 */
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/includes/verificacion_documentos_helper.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');

$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$codigo = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string) ($_GET['c'] ?? '')));
$codigo = substr($codigo, 0, 32);
$doc = null;
$error = null;
if ($codigo !== '') {
    try {
        $doc = lab_verif_buscar(Conexion::conectar(), $codigo);
    } catch (Throwable $ex) {
        error_log('[laboratorio][validar] ' . $ex->getMessage());
        $error = 'No se pudo consultar el código en este momento. Intente de nuevo más tarde.';
    }
    if (!$doc && !$error) {
        usleep(400000); // frena intentos masivos de adivinar códigos
    }
}
$fecha = static function ($v): string {
    $v = trim((string) $v);
    if ($v === '') return '—';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/', $v, $m)) {
        return $m[3] . '/' . $m[2] . '/' . $m[1] . (isset($m[4]) && $m[4] . $m[5] !== '0000' ? ' ' . $m[4] . ':' . $m[5] : '');
    }
    return $v;
};
$d = $doc['datos'] ?? [];
$esInforme = ($doc['tipo'] ?? '') === 'informe';
$estado = $doc['estado'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Verificación de documentos · Laboratorio CENGICAÑA</title>
<style>
:root{--verde:#73BC25;--verde2:#A3D300;--amarillo:#FFCC00;--naranja:#FF6B00;--deep:#1f5632;--ink:#1E2A1A;--muted:#55705F;--ok:#2E7D32;--warn:#B26A00;--bad:#C0392B}
*{box-sizing:border-box}
body{margin:0;background:#F5F6F7;color:var(--ink);font-family:"Inter","Segoe UI",Arial,Helvetica,sans-serif}
.franja{height:6px;background:linear-gradient(90deg,var(--verde) 0 25%,var(--verde2) 25% 50%,var(--amarillo) 50% 75%,var(--naranja) 75% 100%)}
.top{padding:18px 20px;background:var(--deep);color:#fff}
.top h1{margin:0;font-size:18px}.top span{display:block;color:var(--verde2);font-size:12.5px}
main{max-width:760px;margin:0 auto;padding:24px 16px 40px}
.card{margin-bottom:18px;padding:20px;border:1px solid #E4E7E1;border-radius:14px;background:#fff;box-shadow:0 6px 20px -14px rgba(31,86,50,.4)}
form{display:flex;flex-wrap:wrap;gap:8px}
form label{width:100%;font-size:13px;font-weight:700}
form input{flex:1;min-width:220px;padding:11px 12px;border:1px solid #CED2D5;border-radius:10px;font:15px Consolas,'Courier New',monospace;letter-spacing:.03em;text-transform:uppercase}
form button{padding:11px 18px;border:0;border-radius:10px;background:var(--deep);color:#fff;font-weight:700;cursor:pointer}
.estado{display:flex;gap:14px;align-items:flex-start}
.estado .ico{flex:none;width:44px;height:44px;border-radius:50%;display:grid;place-items:center;color:#fff;font-size:24px;font-weight:800}
.estado h2{margin:0 0 4px;font-size:19px}.estado p{margin:0;color:var(--muted);font-size:14px;line-height:1.45}
.ok .ico{background:var(--ok)}.ok h2{color:var(--ok)}
.warn .ico{background:var(--warn)}.warn h2{color:var(--warn)}
.bad .ico{background:var(--bad)}.bad h2{color:var(--bad)}
dl{display:grid;grid-template-columns:minmax(140px,max-content) 1fr;gap:8px 16px;margin:16px 0 0;font-size:14px}
dt{color:var(--muted);font-weight:600}dd{margin:0;font-weight:600;word-break:break-word}
.mono{font-family:Consolas,'Courier New',monospace}
.chips{display:flex;flex-wrap:wrap;gap:5px}.chips span{padding:2px 9px;border-radius:999px;background:#E3F1D3;color:#3A6312;font-size:12.5px;font-weight:600}
.nota{margin-top:14px;font-size:12.5px;color:var(--muted);line-height:1.5}
footer{text-align:center;color:var(--muted);font-size:12px}
@media (max-width:520px){dl{grid-template-columns:1fr}dt{margin-top:6px}}
</style>
</head>
<body>
<div class="franja"></div>
<header class="top"><h1>Verificación de documentos<span>Laboratorio Agroindustrial · CENGICAÑA</span></h1></header>
<main>
  <section class="card">
    <form method="get" action="">
      <label for="c">Código de verificación (impreso junto al QR del documento)</label>
      <input id="c" name="c" value="<?= $e($codigo) ?>" placeholder="LAB-I-2026-00001-XXXX" maxlength="32" autocomplete="off" required>
      <button type="submit">Verificar</button>
    </form>
  </section>

<?php if ($error): ?>
  <section class="card estado bad"><div class="ico">!</div><div><h2>No se pudo verificar</h2><p><?= $e($error) ?></p></div></section>
<?php elseif ($codigo !== '' && !$doc): ?>
  <section class="card estado bad"><div class="ico">✕</div><div><h2>Documento no encontrado</h2>
    <p>El código <b class="mono"><?= $e($codigo) ?></b> no corresponde a ningún documento emitido por el laboratorio. Revise que esté escrito igual que en el documento; si persiste, el documento no es auténtico.</p></div></section>
<?php elseif ($doc): ?>
  <?php if ($estado === 'vigente'): ?>
  <section class="card estado ok"><div class="ico">✓</div><div><h2>Documento auténtico y vigente</h2>
    <p><?= $e($d['documento'] ?? ($esInforme ? 'Informe de resultados' : 'Boleta de recepción')) ?> <?= $esInforme ? 'emitido' : 'emitida' ?> por el Laboratorio Agroindustrial de CENGICAÑA. Compare los datos siguientes con los del documento que tiene a la vista.</p></div></section>
  <?php elseif ($estado === 'reemplazado'): ?>
  <section class="card estado warn"><div class="ico">!</div><div><h2>Documento auténtico, pero reemplazado</h2>
    <p>Este <?= $esInforme ? 'informe' : 'documento' ?> fue emitido por el laboratorio, pero existe una versión más reciente<?= !empty($doc['reemplazado_por']) ? ': <a class="mono" href="?c=' . $e(rawurlencode($doc['reemplazado_por'])) . '">' . $e($doc['reemplazado_por']) . '</a>' : '' ?>. Solicite y utilice la versión vigente.</p></div></section>
  <?php else: ?>
  <section class="card estado bad"><div class="ico">✕</div><div><h2>Documento anulado</h2><p>Este documento fue anulado por el laboratorio y no tiene validez.</p></div></section>
  <?php endif; ?>

  <section class="card">
    <dl>
      <dt>Código de verificación</dt><dd class="mono"><?= $e($doc['codigo']) ?></dd>
      <dt>Documento</dt><dd><?= $e($d['documento'] ?? '') ?><?= $esInforme && !empty($d['informe']) ? ' ' . $e($d['informe']) : '' ?><?= $esInforme ? ' · versión ' . (int) ($d['version'] ?? $doc['version']) : '' ?></dd>
      <dt>Lote</dt><dd><?= $e($d['lote'] ?? '—') ?></dd>
      <dt>Tipo de muestra</dt><dd><?= $e($d['tipo_muestra'] ?? '—') ?></dd>
      <dt>Cliente / institución</dt><dd><?= $e(($d['cliente'] ?? '') !== '' ? $d['cliente'] : '—') ?></dd>
      <dt>Muestras</dt><dd><?= (int) ($d['muestras'] ?? 0) ?><?= !empty($d['rango']) ? ' · ' . $e($d['rango']) : '' ?></dd>
      <?php if (!empty($d['analisis'])): ?><dt>Análisis</dt><dd class="chips"><?php foreach ((array) $d['analisis'] as $a): ?><span><?= $e($a) ?></span><?php endforeach; ?></dd><?php endif; ?>
      <?php if ($esInforme): ?>
      <dt>Aprobado y firmado por</dt><dd><?= $e($d['firmado_por'] ?? '—') ?></dd>
      <dt>Fecha de firma</dt><dd><?= $e($d['fecha_firma'] ?? $fecha($doc['emitido_en'])) ?></dd>
      <dt>Huella del documento</dt><dd class="mono" title="SHA-256 del informe emitido"><?= $e(substr((string) $doc['huella'], 0, 16)) ?>…</dd>
      <?php else: ?>
      <dt>Fecha de ingreso</dt><dd><?= $e($fecha($d['fecha_ingreso'] ?? '')) ?></dd>
      <dt>Recibido por</dt><dd><?= $e(($d['recibido_por'] ?? '') !== '' ? $d['recibido_por'] : '—') ?></dd>
      <?php endif; ?>
      <dt>Emitido</dt><dd><?= $e($fecha($doc['emitido_en'])) ?><?= !empty($doc['actualizado_en']) && !$esInforme ? ' · datos actualizados el ' . $e($fecha($doc['actualizado_en'])) : '' ?></dd>
    </dl>
    <p class="nota"><?= $esInforme
        ? 'Los resultados completos están en el informe impreso. Si algún dato no coincide con su copia, el documento pudo haber sido alterado: comuníquese con el laboratorio.'
        : 'Se muestran los datos vigentes de la recepción. Si la boleta se corrigió después de imprimirse, algunos datos pueden diferir de una copia anterior.' ?></p>
  </section>
<?php endif; ?>
  <footer>Laboratorio Agroindustrial · CENGICAÑA</footer>
</main>
</body>
</html>
