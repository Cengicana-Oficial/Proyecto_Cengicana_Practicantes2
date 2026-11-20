<?php
declare(strict_types=1);
// Marco comun: menu lateral y topbar con el shell de CengiLabs (mismas clases .cengi-* que
// laboratorio/includes/shell_sidebar.php), mensajes y dialogo de firma.
// Variables: $user, $view, $secciones, $permitidas, $contenido, $pendientes, $mensajes.
$grupos = [];
foreach ($permitidas as $clave => $s) {
    $grupos[$s[2]][$clave] = $s;
}
$rolEtiqueta = $user['es_superadmin'] === 1 ? 'Superadmin' : ($user['nombre_rol'] !== '' ? $user['nombre_rol'] : 'Sin rol');
$tituloVista = $view === 'vale' ? 'Detalle del vale' : ($secciones[$view][0] ?? 'Inventario de precursores');
$ubicacion = $user['lab_id'] > 0 ? 'Lab. ' . $user['lab_nombre'] : 'Bodega Central';
$cssVer = static fn(string $f): int => (int) @filemtime(__DIR__ . '/../assets/css/' . $f);
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($tituloVista) ?> · SICIR · CENGICAÑA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/inventario.css?v=<?= $cssVer('inventario.css') ?>">
<link rel="stylesheet" href="assets/css/cengilabs-shell.css?v=<?= $cssVer('cengilabs-shell.css') ?>">
</head>
<body class="cengi-canvas" data-firma-guardada="<?= e($user['firma_guardada']) ?>">
<div class="cengi-sidebar-wrap">
<aside class="cengi-sidebar" id="cengiLabSidebar">
  <a class="cengi-brand" href="<?= e(inv_url(['view' => 'dashboard'])) ?>">
    <span class="cengi-brand-mark inv-brand-mark" aria-hidden="true"><span class="material-symbols-outlined">inventory_2</span></span>
    <div class="cengi-brand-copy">
      <strong>SI<b>CIR</b></strong>
      <small>Bodega de precursores</small>
    </div>
  </a>

  <nav class="cengi-sidebar-nav" id="cengiLabNav" aria-label="Navegación del módulo">
    <?php foreach ($grupos as $titulo => $items): ?>
      <div class="cengi-nav-group">
        <div class="cengi-nav-label"><?= e($titulo) ?></div>
        <?php foreach ($items as $clave => $s): $activo = $view === $clave || ($view === 'vale' && $clave === 'vales'); ?>
          <a href="<?= e(inv_url(['view' => $clave])) ?>" class="cengi-nav-item<?= $activo ? ' is-active' : '' ?>"<?= $activo ? ' aria-current="page"' : '' ?>>
            <span class="material-symbols-outlined" aria-hidden="true"><?= e($s[3]) ?></span>
            <span><?= e($s[0]) ?></span>
            <?php if ($clave === 'vales' && $pendientes > 0): ?><span class="cengi-nav-badge" title="Vales que esperan su acción"><?= (int) $pendientes ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </nav>

  <div class="cengi-sidebar-foot">
    <a href="../login/Menu.php" class="cengi-nav-item cengi-menu-link">
      <span class="material-symbols-outlined" aria-hidden="true">home</span>
      <span>Menú principal</span>
    </a>
    <a href="index.php?logout=1" class="cengi-nav-item cengi-logout-link">
      <span class="material-symbols-outlined" aria-hidden="true">logout</span>
      <span>Cerrar sesión</span>
    </a>
    <div class="cengi-sidebar-version">SICIR &middot; <?= e($ubicacion) ?></div>
  </div>
</aside>

<header class="cengi-topbar">
  <button type="button" class="cengi-menu-toggle" id="cengiLabMenuToggle" aria-label="Abrir menú" aria-expanded="false" aria-controls="cengiLabSidebar">
    <span></span><span></span><span></span>
  </button>
  <div class="cengi-topbar-titles">
    <div class="cengi-topbar-title"><?= e($tituloVista) ?></div>
    <div class="cengi-topbar-sub">Sistema de Control de Inventario de Precursores y Reactivos · <?= e($ubicacion) ?></div>
  </div>
  <div class="cengi-topbar-right">
    <span class="cengi-pill"><?= e($rolEtiqueta) ?></span>
    <div class="cengi-userbox">
      <div class="cengi-avatar"><?= e(inv_iniciales($user['nombre'])) ?></div>
      <div class="inv-userbox-copy">
        <div class="cengi-userbox-u1"><?= e($user['nombre']) ?></div>
        <div class="cengi-userbox-u2"><?= e($ubicacion) ?></div>
      </div>
    </div>
  </div>
</header>
</div>

<main class="cengi-lab-content" id="app">
  <?php foreach ($mensajes as $m): ?>
    <div class="alert <?= $m['tipo'] === 'ok' ? 'ok' : 'bad' ?> flash" role="<?= $m['tipo'] === 'ok' ? 'status' : 'alert' ?>"><?= e($m['mensaje']) ?></div>
  <?php endforeach; ?>
  <?= $contenido ?>
</main>

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
