<?php
declare(strict_types=1);
// Marco comun: cabecera, menu lateral, mensajes y dialogo de firma. Variables: $user, $view, $secciones,
// $permitidas, $contenido, $pendientes, $mensajes.
$grupos = [];
foreach ($permitidas as $clave => $s) {
    $grupos[$s[2]][$clave] = $s;
}
$rolEtiqueta = $user['es_superadmin'] === 1 ? 'Superadmin' : ($user['nombre_rol'] !== '' ? $user['nombre_rol'] : 'Sin rol');
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SICIR · Inventario de precursores · CENGICAÑA</title>
<link rel="stylesheet" href="assets/css/inventario.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/inventario.css') ?>">
</head>
<body data-firma-guardada="<?= e($user['firma_guardada']) ?>">
<header class="top">
  <img src="../login/assets/img/logo-cengicana.png" alt="CENGICAÑA">
  <div class="apptitle"><b>SICIR · Bodega Central</b><span>Sistema de Control de Inventario de Precursores y Reactivos</span></div>
  <div class="hright">
    <span class="hchip">📅 <?= e(date('d/m/Y')) ?></span>
    <a class="btn sm" href="../login/Menu.php">← Menú principal</a>
    <a class="btn sm" href="index.php?logout=1">Cerrar sesión</a>
  </div>
</header>
<div class="layout">
  <nav class="side" aria-label="Navegación del módulo">
    <div class="ucard">
      <div class="row" style="gap:10px;flex-wrap:nowrap">
        <div class="avatar lg"><?= e(inv_iniciales($user['nombre'])) ?></div>
        <div style="min-width:0">
          <div class="un"><?= e($user['nombre']) ?></div>
          <div class="ur"><?= e($rolEtiqueta) ?></div>
          <div class="ul"><?= $user['lab_id'] > 0 ? 'Lab. ' . e($user['lab_nombre']) : 'Bodega Central' ?></div>
        </div>
      </div>
    </div>
    <?php foreach ($grupos as $titulo => $items): ?>
      <div class="sec"><?= e($titulo) ?></div>
      <?php foreach ($items as $clave => $s): ?>
        <a href="<?= e(inv_url(['view' => $clave])) ?>" class="<?= $view === $clave || ($view === 'vale' && $clave === 'vales') ? 'on' : '' ?>">
          <span class="ic"><?= e($s[3]) ?></span><?= e($s[0]) ?>
          <?php if ($clave === 'vales' && $pendientes > 0): ?><span class="badge"><?= (int) $pendientes ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <main id="app">
    <?php foreach ($mensajes as $m): ?>
      <div class="alert <?= $m['tipo'] === 'ok' ? 'ok' : 'bad' ?> flash" role="<?= $m['tipo'] === 'ok' ? 'status' : 'alert' ?>"><?= e($m['mensaje']) ?></div>
    <?php endforeach; ?>
    <?= $contenido ?>
  </main>
</div>

<dialog id="dlgFirma" class="md" aria-labelledby="firmaTitulo">
  <form method="dialog" id="firmaForm">
    <header><h3 id="firmaTitulo">✍ Firma electrónica</h3><button type="button" class="x" data-firma-cancelar aria-label="Cerrar">×</button></header>
    <div class="bd">
      <div class="alert info" id="firmaDescripcion" style="margin-top:0"></div>
      <div class="fg">
        <label class="f">Nombre del firmante<input id="firmaNombre" maxlength="160"></label>
        <label class="f">Fecha y hora<input id="firmaFecha" disabled></label>
      </div>
      <div class="row" style="margin:12px 0 6px"><b class="grow" style="font-size:13px">Dibuje su firma</b>
        <button type="button" class="btn sm" id="firmaUsar" hidden>Usar firma registrada</button>
        <button type="button" class="btn sm" id="firmaLimpiar">Limpiar</button></div>
      <canvas class="pad" id="firmaPad" aria-label="Recuadro para dibujar la firma"></canvas>
      <label class="row" style="gap:6px;margin-top:8px;font-size:12.5px"><input type="checkbox" id="firmaOk"> Declaro que la información es correcta y autorizo con mi firma electrónica.</label>
      <label class="row" style="gap:6px;margin-top:4px;font-size:12.5px" id="firmaGuardarFila"><input type="checkbox" id="firmaGuardar"> Guardar esta firma en mi perfil</label>
      <p class="alert bad" id="firmaError" hidden style="margin-top:8px"></p>
    </div>
    <footer><button type="button" class="btn" data-firma-cancelar>Cancelar</button><button type="button" class="btn p" id="firmaConfirmar">Firmar</button></footer>
  </form>
</dialog>
<script src="assets/js/inventario.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/inventario.js') ?>"></script>
</body>
</html>
