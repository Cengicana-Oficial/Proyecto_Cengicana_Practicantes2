<?php
/*
 * Modal "Registrar como pagados" de inscripciones (compartido por Validaciones y pagos → Cobros
 * pendientes y la lista de Inscritos). Se incluye DENTRO del formulario POST cuyo id es
 * $loteFormId; las casillas name="claves[]" con data-pago="1" (cengi_ins_lote_datos) son las
 * personas a registrar. El servidor procesa accion=pagados_lote con cengi_ins_pagos_lote().
 */
if (!isset($loteFormId)) { return; }
$hoyLote = function_exists('cengi_pp_hoy') ? cengi_pp_hoy() : date('Y-m-d');
?>
<div class="modal fade cengi-course-form-modal pp-modal" id="lote-modal" tabindex="-1" role="dialog" aria-labelledby="lote-title">
    <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><span class="cengi-course-modal-kicker">Seguimiento del cobro</span><h4 class="modal-title" id="lote-title">Registrar como pagados</h4><p id="lote-resumen"></p></div>
        <div class="modal-body">
            <div class="cob-lote-comunes">
                <span class="cob-lote-comunes-tit">Aplicar a todas las filas</span>
                <label>Forma de pago<select class="form-control input-sm" id="lote-forma-todos"><option value="">—</option><?php foreach (CENGI_INS_LOTE_FORMAS as $lf): ?><option><?php echo cengi_pp_h($lf); ?></option><?php endforeach; ?></select></label>
                <label>Fecha del pago<input type="date" class="form-control input-sm" id="lote-fecha-todos" max="<?php echo $hoyLote; ?>"></label>
                <label>Boleta, N.° de proforma o comentario<input type="text" class="form-control input-sm" id="lote-ref-todos" maxlength="150" placeholder="Ej.: Proforma N.° 125"></label>
                <button type="button" class="btn btn-default btn-sm" id="lote-aplicar">Aplicar</button>
            </div>
            <p class="cob-lote-aviso" id="lote-aviso-proforma" hidden><span class="glyphicon glyphicon-info-sign" aria-hidden="true"></span> Quienes paga su ingenio llevan la forma «Proforma del ingenio» y el número de proforma (aunque la proforma no se haya generado en el sistema): quedan pagados, salen de la orden grupal pendiente y, si faltaba, se registra la validación del ingenio.</p>
            <div class="pp-table-wrap"><table class="cob-lote-tabla">
                <thead><tr><th>Participante</th><th class="pp-num">Cuota</th><th>Monto pagado</th><th>Forma de pago</th><th>Fecha del pago</th><th>Boleta, proforma o comentario</th><th>Revisión</th></tr></thead>
                <tbody id="lote-filas"></tbody>
                <tfoot><tr><th>Total</th><th class="pp-num" id="lote-total-esperado"></th><th id="lote-total-pagado"></th><th colspan="4"></th></tr></tfoot>
            </table></div>
            <template id="lote-formas-opciones"><?php foreach (CENGI_INS_LOTE_FORMAS as $lf): ?><option><?php echo cengi_pp_h($lf); ?></option><?php endforeach; ?></template>
            <p class="cob-lote-ayuda-fila">En la última columna: número de boleta o de proforma; si escribes solo texto (p. ej. «pagado», «efectivo en oficina») se guarda como comentario del pago.</p>
            <div class="form-group" style="margin-top:10px"><label for="lote-nota">Nota (opcional, queda en cada inscripción)</label><input type="text" class="form-control" id="lote-nota" name="pago_nota" maxlength="300" placeholder="Ej.: estado de cuenta de octubre; pagó la diferencia en efectivo…"></div>
            <div class="pp-ics-check"><label><input type="checkbox" name="avisar" value="1" checked><span><b>Avisar al participante</b> que recibimos su pago (solo pago propio con convocatoria)</span></label></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary" name="accion" value="pagados_lote">Registrar pagos</button></div>
    </div></div>
</div>
<script>
(function () {
    var form = document.getElementById(<?php echo json_encode($loteFormId); ?>);
    if (!form || !window.jQuery) return;
    var $id = function (i) { return document.getElementById(i); };
    var hoy = <?php echo json_encode($hoyLote); ?>;
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var dinero = function (n, moneda) { return (moneda === 'USD' ? 'US$' : 'Q') + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    // form.elements incluye las casillas asociadas con el atributo form="..." aunque estén fuera del formulario.
    var seleccionadas = function () { return [].filter.call(form.elements, function (c) { return c.name === 'claves[]' && c.getAttribute('data-pago') === '1' && c.checked; }); };
    var revisarFila = function (tr) {
        var esperado = tr.getAttribute('data-monto'), moneda = tr.getAttribute('data-moneda'), pagador = tr.getAttribute('data-pagador');
        var acepta = tr.querySelector('.lote-acepta'), estado = tr.querySelector('.lote-estado');
        var pagado = parseFloat(String(tr.querySelector('.lote-monto').value).replace(/,/g, ''));
        var proforma = tr.querySelector('.lote-forma').value === 'Proforma del ingenio', ref = tr.querySelector('.lote-ref').value.trim();
        var txt = '', clase = 'is-ok', pideAceptar = false;
        if (pagador === 'Ingenio' && !proforma) { txt = 'Lo paga su ingenio: usa la forma «Proforma del ingenio»'; clase = 'is-error'; }
        else if (pagador === 'Ingenio' && !/\d/.test(ref)) { txt = 'Indica el número de proforma'; clase = 'is-error'; }
        else if (!tr.querySelector('.lote-fecha').value) { txt = 'Indica la fecha del pago'; clase = 'is-error'; }
        else if (!(pagado > 0)) { txt = 'Indica el monto pagado'; clase = 'is-error'; }
        else if (esperado === '') { txt = 'Sin cuota definida: se tomará este monto'; clase = 'is-warn'; pideAceptar = true; }
        else {
            var dif = Math.round((pagado - parseFloat(esperado)) * 100) / 100;
            if (Math.abs(dif) > 0.009) { txt = (dif > 0 ? 'Pagó de más ' : 'Faltan ') + dinero(Math.abs(dif), moneda); clase = 'is-warn'; pideAceptar = true; }
            else txt = 'Coincide con la cuota';
        }
        acepta.parentNode.hidden = !pideAceptar;
        estado.textContent = txt; estado.className = 'lote-estado ' + clase;
        tr.classList.toggle('is-omitida', clase === 'is-error' || (pideAceptar && !acepta.checked));
    };
    var totales = function () {
        var esp = 0, pag = 0, moneda = 'GTQ';
        form.querySelectorAll('#lote-filas tr').forEach(function (tr) {
            moneda = tr.getAttribute('data-moneda') || moneda;
            esp += parseFloat(tr.getAttribute('data-monto') || 0);
            pag += parseFloat(String(tr.querySelector('.lote-monto').value).replace(/,/g, '')) || 0;
        });
        $id('lote-total-esperado').textContent = dinero(esp, moneda); $id('lote-total-pagado').textContent = dinero(pag, moneda);
    };
    var armar = function () {
        var cuerpo = $id('lote-filas'), opciones = $id('lote-formas-opciones').innerHTML || '', sel = seleccionadas();
        cuerpo.innerHTML = '';
        sel.forEach(function (c) {
            var id = c.getAttribute('data-id'), monto = c.getAttribute('data-monto'), moneda = c.getAttribute('data-moneda'), pagador = c.getAttribute('data-pagador');
            var tr = document.createElement('tr');
            ['monto', 'moneda', 'pagador'].forEach(function (k) { tr.setAttribute('data-' + k, c.getAttribute('data-' + k) || ''); });
            tr.innerHTML = '<td><b>' + esc(c.getAttribute('data-nombre')) + '</b><span class="pp-sub">' + (pagador === 'Ingenio' ? 'Paga el ingenio' : 'Pago propio') + (c.getAttribute('data-tarifa') ? ' · cuota ' + esc(c.getAttribute('data-tarifa').toLowerCase()) : '') + '</span></td>'
                + '<td class="pp-num">' + (monto === '' ? '<span class="pp-sub">Por definir</span>' : dinero(monto, moneda)) + '</td>'
                + '<td><input class="form-control input-sm lote-monto" type="number" min="0" step="0.01" name="monto_pagado[' + id + ']" value="' + esc(c.getAttribute('data-comprobante') || monto) + '"></td>'
                + '<td><select class="form-control input-sm lote-forma" name="forma_fila[' + id + ']">' + opciones + '</select></td>'
                + '<td><input class="form-control input-sm lote-fecha" type="date" max="' + hoy + '" name="fecha_fila[' + id + ']" value="' + hoy + '"></td>'
                + '<td><input class="form-control input-sm lote-ref" type="text" maxlength="150" name="ref_fila[' + id + ']" value="' + esc(c.getAttribute('data-boleta')) + '" placeholder="' + (pagador === 'Ingenio' ? 'N.° de proforma' : 'N.° de boleta o comentario') + '"></td>'
                + '<td><span class="lote-estado"></span><label class="lote-acepta-wrap" hidden><input type="checkbox" class="lote-acepta" name="aceptar_dif[' + id + ']" value="1"> aceptar diferencia</label></td>';
            cuerpo.appendChild(tr);
            // Quien paga su ingenio: «Proforma del ingenio» por defecto; pago propio: Depósito.
            tr.querySelector('.lote-forma').value = pagador === 'Ingenio' ? 'Proforma del ingenio' : 'Depósito';
            ['.lote-forma', '.lote-fecha', '.lote-ref'].forEach(function (s) { tr.querySelector(s).addEventListener(s === '.lote-ref' ? 'input' : 'change', function () { revisarFila(tr); }); });
            tr.querySelector('.lote-monto').addEventListener('input', function () { revisarFila(tr); totales(); });
            tr.querySelector('.lote-acepta').addEventListener('change', function () { revisarFila(tr); });
            revisarFila(tr);
        });
        totales();
        $id('lote-resumen').textContent = sel.length + ' persona(s) para registrar';
        $id('lote-aviso-proforma').hidden = !sel.some(function (c) { return c.getAttribute('data-pagador') === 'Ingenio'; });
        $id('lote-forma-todos').value = ''; $id('lote-fecha-todos').value = ''; $id('lote-ref-todos').value = '';
    };
    jQuery('#lote-modal').on('show.bs.modal', armar);
    // "Aplicar a todas las filas": solo copia lo que se llenó arriba. «Proforma del ingenio» se aplica
    // solo a quienes paga su ingenio; las demás formas, solo a pago propio.
    $id('lote-aplicar').addEventListener('click', function () {
        var forma = $id('lote-forma-todos').value, fecha = $id('lote-fecha-todos').value, ref = $id('lote-ref-todos').value.trim();
        form.querySelectorAll('#lote-filas tr').forEach(function (tr) {
            var esIngenio = tr.getAttribute('data-pagador') === 'Ingenio';
            if (forma && (forma === 'Proforma del ingenio') === esIngenio) tr.querySelector('.lote-forma').value = forma;
            if (fecha) tr.querySelector('.lote-fecha').value = fecha;
            if (ref) tr.querySelector('.lote-ref').value = ref;
            revisarFila(tr);
        });
    });
    // Evita enviar si ninguna fila está lista.
    form.addEventListener('submit', function (e) {
        var b = e.submitter; if (!b || b.value !== 'pagados_lote') return;
        var filas = form.querySelectorAll('#lote-filas tr'), omitidas = form.querySelectorAll('#lote-filas tr.is-omitida').length;
        if (!filas.length || omitidas === filas.length) { e.preventDefault(); window.alert('Revisa las filas: ninguna está lista (falta monto, fecha o N.° de proforma, o hay una diferencia sin aceptar).'); return; }
        if (omitidas && !window.confirm(omitidas + ' fila(s) pendientes de revisar se omitirán. ¿Registrar las demás?')) e.preventDefault();
    });
})();
</script>
