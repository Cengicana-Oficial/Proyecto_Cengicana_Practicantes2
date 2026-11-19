<?php
declare(strict_types=1);
$alcance = inv_alcance_lab($user);
$anio = (int) ($_GET['anio'] ?? date('Y'));
$mes = (int) ($_GET['mes'] ?? date('n'));
if ($anio < 2000 || $anio > 2100) {
    $anio = (int) date('Y');
}
if ($mes < 1 || $mes > 12) {
    $mes = (int) date('n');
}
$labSel = $alcance > 0 ? $alcance : (int) ($_GET['lab'] ?? 0);
$soloFisc = (string) ($_GET['fisc'] ?? '1') !== '0';
$lab = $labSel > 0 ? inv_laboratorio($pdo, $labSel) : null;
if ($labSel > 0 && !$lab) {
    $labSel = 0;
}
if ($alcance === -1) {
    echo '<div class="alert warn">Su usuario no tiene un laboratorio asignado.</div>';
    return;
}
$cierre = inv_cierre_obtener($pdo, $anio, $mes, $labSel, $soloFisc);
$congelado = $cierre && is_array($cierre['datos']);
$datos = $congelado ? $cierre['datos'] : inv_cierre_datos($pdo, $anio, $mes, $labSel, $soloFisc);
$firmas = $cierre['firmas'] ?? [];
$puedeExportar = inv_puede($user, 'inventario.informes.exportar');

if (($_GET['export'] ?? '') === 'csv' && $puedeExportar) {
    $csv = [['Código', 'Precursor', 'CAS', 'Unidad', 'Inventario inicial', 'Ingresos', 'Egresos', 'Inventario final']];
    foreach ($datos['filas'] as $r) {
        $csv[] = [$r['codigo'], $r['nombre'], $r['cas'], $r['unidad'], inv_dec($r['inicial']), inv_dec($r['ingresos']), inv_dec($r['egresos']), inv_dec($r['final'])];
    }
    inv_enviar_csv(sprintf('cierre_%04d-%02d%s', $anio, $mes, $labSel > 0 ? '-' . $lab['codigo'] : ''), $csv);
}
$estadoCierre = isset($firmas['apr']) ? 'Aprobado' : (isset($firmas['elab']) ? 'En revisión' : 'Borrador');
$qs = ['view' => 'cierre', 'anio' => $anio, 'mes' => $mes, 'lab' => $labSel, 'fisc' => $soloFisc ? 1 : 0];
$labs = inv_laboratorios($pdo);

$caja = static function (string $tipo, string $rol, string $cargo) use ($firmas, $user, $anio, $mes, $labSel, $soloFisc): string {
    $f = $firmas[$tipo] ?? null;
    $html = '<div class="sig ' . ($f ? '' : 'pend') . '"><div class="rl">' . e($rol) . '</div><div class="im">';
    if ($f) {
        $html .= '<img src="' . e($f['imagen']) . '" alt="Firma de ' . e($f['nombre']) . '">';
    } else {
        $previo = ['elab' => null, 'rev' => 'elab', 'apr' => 'rev'][$tipo];
        $puede = inv_puede($user, INV_CIERRE_PERMISO[$tipo]) && ($previo === null || isset($firmas[$previo]));
        if ($puede) {
            $html .= '<form method="post" action="' . e(inv_url(['view' => 'cierre'])) . '" ' . inv_firma_attr([['p' => 'cie', 'titulo' => $rol . ' — cierre ' . INV_MESES[$mes - 1] . ' ' . $anio, 'nombre' => $user['nombre'], 'fijo' => true]]) . '>'
                . inv_form_campos('cierre_firmar') . '<input type="hidden" name="volver_cierre" value="1"><input type="hidden" name="tipo" value="' . e($tipo) . '">'
                . '<input type="hidden" name="anio" value="' . $anio . '"><input type="hidden" name="mes" value="' . $mes . '"><input type="hidden" name="laboratorio_id" value="' . $labSel . '">'
                . ($soloFisc ? '<input type="hidden" name="solo_fiscalizados" value="1">' : '') . '<button class="btn sm p noprint" type="submit">✍ Firmar</button></form>';
        } else {
            $html .= 'Pendiente';
        }
    }
    return $html . '</div><div class="ln"><b>' . ($f ? e($f['nombre']) : '&nbsp;') . '</b><br><span class="hint">' . e($cargo) . ($f ? ' · ' . e(inv_fecha($f['firmado_en'])) : '') . '</span></div></div>';
};
?>
<div class="row noprint"><div class="grow"><h1 class="pt">Informe mensual de control de precursores químicos</h1><p class="sub">Formato de balance oficial para declaración e inspección regulatoria (Ministerio de Salud).</p></div>
  <?php if ($puedeExportar): ?><a class="btn" href="<?= e(inv_url($qs + ['export' => 'csv'])) ?>">⤓ CSV</a><?php endif; ?><button class="btn p" type="button" data-imprimir>🖨 Imprimir informe</button></div>
<div class="card noprint"><form method="get" class="row" data-autosubmit><input type="hidden" name="view" value="cierre">
  <label class="f">Mes<select name="mes"><?php foreach (INV_MESES as $i => $m): ?><option value="<?= $i + 1 ?>" <?= $mes === $i + 1 ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select></label>
  <label class="f">Año<select name="anio"><?php for ($y = (int) date('Y') - 3; $y <= (int) date('Y') + 1; $y++): ?><option <?= $anio === $y ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></label>
  <label class="f">Alcance<select name="lab" <?= $alcance > 0 ? 'disabled' : '' ?>><option value="">Consolidado Bodega Central</option><?php foreach ($labs as $l): ?><option value="<?= (int) $l['id'] ?>" <?= $labSel === (int) $l['id'] ? 'selected' : '' ?>>Lab. <?= e($l['nombre']) ?></option><?php endforeach; ?></select></label>
  <label class="f">Productos<select name="fisc"><option value="1" <?= $soloFisc ? 'selected' : '' ?>>Solo precursores fiscalizados</option><option value="0" <?= !$soloFisc ? 'selected' : '' ?>>Todos</option></select></label><noscript><button class="btn">Ver</button></noscript></form></div>
<?php if ($congelado): ?><div class="alert info noprint">Las cifras corresponden a la fecha en que se firmó «Elaborado por» y no cambian aunque se corrijan entradas después.</div><?php endif; ?>
<div class="doc">
  <div class="dh"><img src="../login/assets/img/logo-cengicana.png" alt="CENGICAÑA"><div class="t"><b>INFORME MENSUAL DE CONTROL DE PRECURSORES QUÍMICOS</b><span>Formato de balance oficial para declaración e inspección regulatoria (Ministerio de Salud)</span></div><div class="no">Emitido<b style="color:var(--gd)"><?= e(inv_fecha(inv_hoy())) ?></b><?= e($user['nombre']) ?></div></div>
  <div class="kv"><div>Mes de declaración</div><div><?= e(INV_MESES[$mes - 1]) ?></div><div>Año</div><div><?= $anio ?></div><div>Fecha inicio periodo</div><div><?= e(inv_fecha($datos['inicio'])) ?></div><div>Fecha fin periodo</div><div><?= e(inv_fecha($datos['fin'])) ?></div><div>Alcance</div><div><?= $lab ? 'Laboratorio ' . e($lab['nombre']) : 'Consolidado Bodega Central' ?></div><div>Estado</div><div><?= e($estadoCierre) ?></div></div>
  <table style="margin-top:12px"><thead><tr><th>Código</th><th>Precursor químico</th><th>No. CAS</th><th>Unidad</th><th class="n">Inventario inicial<br><span class="hint">(saldo mes anterior)</span></th><th class="n">Ingresos del mes<br><span class="hint">(cargas)</span></th><th class="n">Egresos del mes<br><span class="hint">(consumo total)</span></th><th class="n">Inventario final<br><span class="hint">(saldo para el mes siguiente)</span></th></tr></thead><tbody>
  <?php foreach ($datos['filas'] as $r): ?><tr><td class="mono"><?= e($r['codigo']) ?></td><td><?= e($r['nombre']) ?></td><td><?= e($r['cas']) ?></td><td><?= e($r['unidad']) ?></td><td class="n"><?= e(inv_fmt($r['inicial'])) ?></td><td class="n"><?= e(inv_fmt($r['ingresos'])) ?></td><td class="n"><?= e(inv_fmt($r['egresos'])) ?></td><td class="n"><b><?= e(inv_fmt($r['final'])) ?></b></td></tr><?php endforeach; ?>
  <?php if (!$datos['filas']): ?><tr><td colspan="8" class="empty">Sin productos en el alcance elegido</td></tr><?php endif; ?></tbody></table>
  <div class="sigs" style="grid-template-columns:repeat(3,1fr);margin-top:22px"><?= $caja('elab', 'Elaborado por', 'Encargado de Bodega Central') ?><?= $caja('rev', 'Revisado por', 'Responsable de Bodega Central') ?><?= $caja('apr', 'Aprobado por', 'Director General') ?></div>
  <p class="hint noprint" style="margin-top:8px">Las firmas se habilitan según los permisos «Firmar Elaborado / Revisado / Aprobado» del rol y deben firmarse en ese orden por personas distintas.</p>
</div>
