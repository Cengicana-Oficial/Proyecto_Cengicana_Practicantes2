        <section class="pp-card">
            <div class="pp-card-head"><div><h3>Proformas emitidas</h3><span class="pp-sub">Con participantes inscritos o por cupos antes de la inscripción. Vincula los participantes y registra el pago en la misma proforma. La aceptación del ingenio se registra por separado. Vigencia: <?php echo CENGI_PF_VIGENCIA_DIAS; ?> días.</span></div></div>
            <div class="pp-table-wrap"><table class="cengi-org-table" style="min-width:980px;">
                <thead><tr><th>Proforma</th><th>Emisión</th><th>Cliente</th><th>Participantes</th><th class="pp-num">Total</th><th>Estado</th><th></th></tr></thead>
                <tbody>
                    <?php if (!$proformas): ?><tr><td colspan="7" class="pp-empty">No hay proformas<?php echo $busqueda !== '' ? ' que coincidan con la búsqueda' : ' en esta selección'; ?>.</td></tr><?php endif; ?>
                    <?php foreach ($proformas as $pf): $estPf = cengi_pf_estado($pf); $libres = $pf['estado'] === 'anulada' ? 0 : max(0, (int) $pf['cantidad'] - (int) $pf['amarrados']); ?>
                        <tr<?php echo $pf['estado'] === 'anulada' ? ' class="pp-muted-row"' : ''; ?>>
                            <td class="pp-mono"><a class="pp-link" href="proforma.php?id=<?php echo (int) $pf['id']; ?><?= $vincularProformas?'&amp;contexto=pagos':'' ?>"><b>N.° <?php echo (int) $pf['numero']; ?></b></a></td>
                            <td class="pp-num"><?php echo cengi_pp_fecha($pf['fecha']); ?><span class="pp-sub">vence <?php echo cengi_pp_fecha($pf['vence']); ?></span></td>
                            <td><strong><?php echo cengi_pp_h($pf['empresa']); ?></strong><span class="pp-sub"><?php echo cengi_pp_h($pf['dirigido']); ?></span></td>
                            <td><?php echo (int) $pf['cantidad']; ?> <span class="pp-sub" style="display:inline;">(<?php echo (int) $pf['amarrados']; ?> con nombre<?php echo $libres ? ', <b style="color:#8A6600;">' . $libres . ' cupo(s) libre(s)</b>' : ''; ?>)</span><span class="pp-sub"><?php echo cengi_pp_h($pf['cursos']); ?></span></td>
                            <td class="pp-num"><b><?php echo cengi_pp_h(cengi_pf_dinero($pf['total'], $pf['moneda'])); ?></b><?php echo (float) $pf['cargo_monto'] > 0 ? '<span class="pp-sub">incluye transferencia internacional</span>' : ''; ?><?php echo (float) $pf['con_descuento'] > 0 ? '<span class="pp-sub">con descuento</span>' : ''; ?></td>
                            <td><?php echo cengi_pp_badge(CENGI_PF_ESTADOS, $estPf); ?><?php echo $pf['pago_referencia'] ? '<span class="pp-sub">' . cengi_pp_h($pf['pago_forma'] . ' ' . $pf['pago_referencia']) . '</span>' : ''; ?></td>
                            <td style="text-align:right;white-space:nowrap;">
                                <a class="btn btn-default btn-sm" href="proforma.php?id=<?php echo (int) $pf['id']; ?><?= $vincularProformas?'&amp;contexto=pagos':'' ?>"><?= $vincularProformas ? 'Gestionar participantes y pago' : 'Ver' ?></a>
                                <a class="btn btn-default btn-sm" href="proforma.php?id=<?php echo (int) $pf['id']; ?>&amp;pdf=1" title="Descargar PDF"><span class="glyphicon glyphicon-download-alt"></span></a>
                                <?php if ($puedeValidarPagos && in_array($pf['estado'], ['emitida', 'enviada'], true)): ?><a class="btn btn-primary btn-sm" href="proforma.php?id=<?php echo (int) $pf['id']; ?><?= $vincularProformas?'&amp;contexto=pagos':'' ?>">Registrar pago</a><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>
