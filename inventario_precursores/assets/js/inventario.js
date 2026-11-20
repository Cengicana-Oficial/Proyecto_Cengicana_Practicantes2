(function () {
  'use strict';

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var datos = function (el, attr) { try { return JSON.parse(el.getAttribute(attr) || '{}'); } catch (e) { return {}; } };

  // ---------------------------------------------------------------- Dialogos
  function abrir(dlg) { if (dlg && !dlg.open) { dlg.showModal(); } }
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-abrir]');
    if (t) { abrir(document.getElementById(t.getAttribute('data-abrir'))); return; }
    t = ev.target.closest('[data-cerrar]');
    if (t) { var d = t.closest('dialog'); if (d) { d.close(); } return; }
    if (ev.target.closest('[data-imprimir]')) { window.print(); }
  });

  // ---------------------------------------------------------------- Formularios sencillos
  $$('form[data-autosubmit]').forEach(function (f) { f.addEventListener('change', function () { f.submit(); }); });
  $$('form[data-autosubmit-post]').forEach(function (f) { f.addEventListener('change', function () { f.submit(); }); });
  $$('[data-ir-lab]').forEach(function (s) {
    s.addEventListener('change', function () {
      var base = s.getAttribute('data-ir-lab');
      window.location.href = base + (base.indexOf('?') >= 0 ? '&' : '?') + 'lab=' + encodeURIComponent(s.value);
    });
  });
  $$('form[data-confirmar]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (!window.confirm(f.getAttribute('data-confirmar'))) { ev.preventDefault(); return; }
      if (f.hasAttribute('data-marcar-confirmado') && !f.querySelector('[name=confirmado]')) {
        var h = document.createElement('input'); h.type = 'hidden'; h.name = 'confirmado'; h.value = '1'; f.appendChild(h);
      }
    });
  });
  $$('.flash.ok').forEach(function (m) { setTimeout(function () { m.style.display = 'none'; }, 6000); });

  // ---------------------------------------------------------------- Productos por laboratorio (entradas)
  var contenedor = $('#entradasDatos');
  var matriz = contenedor ? datos(contenedor, 'data-matriz') : {};
  function llenarProductos(selLab, selProd, elegido) {
    var lista = matriz[selLab.value] || [];
    selProd.innerHTML = '';
    lista.forEach(function (p) {
      if (!p.activo && String(p.id) !== String(elegido)) { return; }
      var o = document.createElement('option'); o.value = p.id; o.textContent = p.texto + (p.activo ? '' : ' [inactivo]');
      if (String(p.id) === String(elegido)) { o.selected = true; }
      selProd.appendChild(o);
    });
    if (!selProd.options.length) { selProd.innerHTML = '<option value="">Este laboratorio no tiene productos asignados</option>'; }
  }
  $$('[data-matriz-lab]').forEach(function (selLab) {
    var selProd = $(selLab.getAttribute('data-matriz-lab'));
    llenarProductos(selLab, selProd, '');
    selLab.addEventListener('change', function () { llenarProductos(selLab, selProd, ''); });
  });

  // Corregir / anular entradas
  $$('[data-corregir]').forEach(function (b) {
    b.addEventListener('click', function () {
      var d = datos(b, 'data-corregir'), dlg = $('#dlgCorregir'), f = $('#formCorregir');
      $('#corTitulo').textContent = '✎ Corregir entrada ' + d.codigo + ' · ' + d.lote;
      f.elements.id.value = d.id;
      ['fecha_ingreso', 'cantidad', 'caducidad', 'proveedor', 'factura', 'lote_proveedor', 'coa'].forEach(function (k) { f.elements[k].value = d[k] || ''; });
      f.elements.error_tipo.value = ''; f.elements.motivo.value = ''; f.elements.documento.value = '';
      f.elements.laboratorio_id.value = d.laboratorio_id;
      llenarProductos(f.elements.laboratorio_id, f.elements.producto_id, d.producto_id);
      var bloq = $('#corBloqueo');
      bloq.hidden = !d.bloqueado;
      if (d.bloqueado) {
        bloq.textContent = 'Este lote ya tiene ' + d.consumido + ' ' + d.unidad + ' descargados en vales. No se puede cambiar el laboratorio ni el producto, y la cantidad no puede ser menor a lo consumido (el servidor lo valida).';
      }
      abrir(dlg);
    });
  });
  $$('[data-anular]').forEach(function (b) {
    b.addEventListener('click', function () {
      var d = datos(b, 'data-anular'), f = $('#formAnularEntrada');
      $('#anuTitulo').textContent = '⊘ Anular entrada ' + d.codigo;
      $('#anuResumen').textContent = 'Se anulará el ingreso de ' + d.cantidad + ' ' + d.unidad + ' de ' + d.producto + ' (lote ' + d.lote + '). El registro se conserva tachado para auditoría y deja de sumar al inventario.';
      f.elements.id.value = d.id; f.elements.motivo.value = '';
      abrir($('#dlgAnularEntrada'));
    });
  });

  // ---------------------------------------------------------------- Catalogo
  var cat = $('#catalogoDatos');
  function productoDialogo(p) {
    var f = $('#dlgProducto form');
    f.elements.id.value = p ? p.id : 0;
    $('#prodTitulo').textContent = p ? 'Editar ' + p.codigo : 'Nuevo producto';
    f.elements.tipo.value = p ? p.tipo : 'Precursor fiscalizado';
    f.elements.codigo.value = p ? p.codigo : (datos(cat, 'data-proximos')[f.elements.tipo.value] || '');
    f.elements.codigo.readOnly = !!p;
    ['nombre', 'concentracion', 'cas'].forEach(function (k) { f.elements[k].value = p ? p[k] : ''; });
    f.elements.unidad.value = p ? p.unidad : 'L';
    f.elements.minimo.value = p ? p.minimo : '0';
    f.elements.activo.value = p ? String(p.activo) : '1';
    $$('input[name="labs[]"]', f).forEach(function (c) { c.checked = !!p && p.labs.indexOf(parseInt(c.value, 10)) >= 0; });
    abrir($('#dlgProducto'));
  }
  if (cat) {
    $('[data-nuevo-producto]').addEventListener('click', function () { productoDialogo(null); });
    $$('[data-editar-producto]').forEach(function (b) { b.addEventListener('click', function () { productoDialogo(datos(b, 'data-editar-producto')); }); });
    $('#dlgProducto form').elements.tipo.addEventListener('change', function (ev) {
      var f = ev.target.form;
      if (!f.elements.codigo.readOnly) { f.elements.codigo.value = datos(cat, 'data-proximos')[ev.target.value] || ''; }
    });
  }

  // ---------------------------------------------------------------- Laboratorios
  $$('[data-editar-lab]').forEach(function (b) {
    b.addEventListener('click', function () {
      var raw = b.getAttribute('data-editar-lab'), l = raw ? JSON.parse(raw) : null, f = $('#dlgLab form');
      f.elements.id.value = l ? l.id : 0;
      $('#labTitulo').textContent = l ? 'Editar laboratorio' : 'Nuevo laboratorio';
      f.elements.codigo.value = l ? l.codigo : ''; f.elements.codigo.readOnly = !!l;
      f.elements.nombre.value = l ? l.nombre : '';
      f.elements.jefe_usuario_id.value = l ? String(l.jefe || 0) : '0';
      abrir($('#dlgLab'));
    });
  });
  $$('[data-baja-lab]').forEach(function (b) {
    b.addEventListener('click', function () {
      var l = datos(b, 'data-baja-lab'), f = $('#dlgBajaLab form');
      f.elements.id.value = l.id; f.elements.motivo.value = ''; $('#bajaTitulo').textContent = 'Desactivar laboratorio ' + l.nombre;
      abrir($('#dlgBajaLab'));
    });
  });
  $$('[data-eliminar-lab]').forEach(function (b) {
    b.addEventListener('click', function () {
      var l = datos(b, 'data-eliminar-lab'), f = $('#dlgEliminarLab form');
      f.elements.id.value = l.id; f.elements.confirmar_codigo.value = ''; $('#elimTitulo').textContent = 'Eliminar laboratorio ' + l.nombre; $('#elimCodigo').textContent = l.codigo;
      abrir($('#dlgEliminarLab'));
    });
  });

  // ---------------------------------------------------------------- Lineas del vale nuevo
  var lineas = $('#lineas');
  if (lineas) {
    var libres = datos(lineas, 'data-libres');
    var plantilla = $('#plantillaLinea');
    var texto = function (n) { return (Math.round(n) / 1000).toLocaleString('es-GT', { maximumFractionDigits: 3 }); };
    var numerar = function () {
      $$('tr.linea', lineas).forEach(function (tr, i) {
        $('.num', tr).textContent = i + 1;
        $$('[name]', tr).forEach(function (c) { c.name = c.name.replace(/items\[[^\]]*\]/, 'items[' + i + ']'); });
      });
    };
    var refrescar = function (tr) {
      var sel = $('.prod', tr), op = sel.options[sel.selectedIndex], id = sel.value;
      $('.unidad', tr).textContent = id && op ? op.getAttribute('data-unidad') : '';
      var libre = id ? (libres[id] || 0) : 0;
      $('.libre', tr).textContent = id ? texto(libre) : '';
      var cant = parseFloat(($('.cant', tr).value || '').replace(',', '.')), exceso = $('.exceso', tr);
      var fuera = id && cant * 1000 > libre + 0.5;
      exceso.hidden = !fuera; exceso.textContent = fuera ? 'Excede lo libre (' + texto(libre) + ')' : '';
      $('.cant', tr).style.borderColor = fuera ? 'var(--red)' : '';
      // Un producto solo puede estar en una linea.
      $$('tr.linea', lineas).forEach(function (otra) {
        $$('option', $('.prod', otra)).forEach(function (o) {
          var usado = o.value && $$('tr.linea .prod', lineas).some(function (s) { return s !== $('.prod', otra) && s.value === o.value; });
          o.disabled = !!usado;
        });
      });
    };
    lineas.addEventListener('input', function (ev) { var tr = ev.target.closest('tr.linea'); if (tr) { refrescar(tr); } });
    lineas.addEventListener('change', function (ev) { var tr = ev.target.closest('tr.linea'); if (tr) { refrescar(tr); } });
    lineas.addEventListener('click', function (ev) {
      if (!ev.target.closest('.quitar')) { return; }
      var tr = ev.target.closest('tr.linea');
      if ($$('tr.linea', lineas).length > 1) { tr.remove(); } else { $('.prod', tr).value = ''; $('.cant', tr).value = ''; }
      numerar(); $$('tr.linea', lineas).forEach(refrescar);
    });
    $('#agregarLinea').addEventListener('click', function () {
      lineas.appendChild(plantilla.content.cloneNode(true)); numerar(); $$('tr.linea', lineas).forEach(refrescar);
    });
    $$('tr.linea', lineas).forEach(refrescar);
  }

  // ---------------------------------------------------------------- Firma electronica
  var dlg = $('#firmaPad') && document.getElementById('dlgFirma');
  if (!dlg) { return; }
  var pad = $('#firmaPad'), ctx = null, dibujando = false, hayTinta = false, ultimo = null, pendiente = null;

  function iniciarLienzo() {
    var r = pad.getBoundingClientRect();
    pad.width = Math.max(300, r.width) * 2; pad.height = Math.max(120, r.height) * 2;
    ctx = pad.getContext('2d'); ctx.scale(2, 2); ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#1b3a8a';
    hayTinta = false;
  }
  function punto(ev) { var b = pad.getBoundingClientRect(); return [ev.clientX - b.left, ev.clientY - b.top]; }
  pad.addEventListener('pointerdown', function (ev) { dibujando = true; ultimo = punto(ev); pad.setPointerCapture(ev.pointerId); });
  pad.addEventListener('pointermove', function (ev) {
    if (!dibujando) { return; }
    var p = punto(ev); ctx.beginPath(); ctx.moveTo(ultimo[0], ultimo[1]); ctx.lineTo(p[0], p[1]); ctx.stroke(); ultimo = p; hayTinta = true;
  });
  pad.addEventListener('pointerup', function () { dibujando = false; });
  $('#firmaLimpiar').addEventListener('click', function () { iniciarLienzo(); });
  $('#firmaUsar').addEventListener('click', function () {
    var img = new Image(), r = pad.getBoundingClientRect();
    img.onload = function () { iniciarLienzo(); ctx.drawImage(img, 0, 0, r.width, r.height); hayTinta = true; };
    img.src = document.body.getAttribute('data-firma-guardada');
  });
  function error(msg) { var e = $('#firmaError'); e.hidden = !msg; e.textContent = msg || ''; }

  // Pide una firma y devuelve una promesa con {nombre, img, guardar} o null si se cancela.
  function pedirFirma(f) {
    return new Promise(function (resolve) {
      $('#firmaDescripcion').textContent = f.titulo;
      var n = $('#firmaNombre'); n.value = f.nombre || ''; n.disabled = !!f.fijo;
      $('#firmaFecha').value = new Date().toLocaleString('es-GT');
      $('#firmaOk').checked = false; error('');
      var guardada = document.body.getAttribute('data-firma-guardada');
      $('#firmaUsar').hidden = !(guardada && f.fijo);
      $('#firmaGuardarFila').hidden = !f.fijo; $('#firmaGuardar').checked = !guardada;
      abrir(dlg); iniciarLienzo();
      var fin = function (v) { $('#firmaConfirmar').onclick = null; dlg.onclose = null; dlg.close(); resolve(v); };
      $('#firmaConfirmar').onclick = function () {
        if (!hayTinta) { return error('Dibuje su firma en el recuadro.'); }
        if (!$('#firmaOk').checked) { return error('Confirme la declaración.'); }
        if (!n.value.trim()) { return error('Indique el nombre del firmante.'); }
        fin({ nombre: n.value.trim(), img: pad.toDataURL('image/png'), guardar: $('#firmaGuardar').checked });
      };
      $$('[data-firma-cancelar]').forEach(function (b) { b.onclick = function () { fin(null); }; });
      dlg.onclose = function () { resolve(null); };
    });
  }
  function campo(f, nombre, valor) {
    var h = f.querySelector('input[data-firma-campo="' + nombre + '"]');
    if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = nombre; h.setAttribute('data-firma-campo', nombre); f.appendChild(h); }
    h.value = valor;
  }
  $$('form[data-firma]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (f.getAttribute('data-firmado') === '1') { return; }
      ev.preventDefault();
      if (pendiente || !f.reportValidity()) { return; }
      pendiente = true;
      var firmantes = datos(f, 'data-firma'), i = 0;
      (function siguiente() {
        if (i >= firmantes.length) { pendiente = null; f.setAttribute('data-firmado', '1'); f.submit(); return; }
        var s = firmantes[i];
        if (s.campo && f.elements[s.campo]) { s.nombre = f.elements[s.campo].value; }
        pedirFirma(s).then(function (r) {
          if (!r) { pendiente = null; return; }
          campo(f, s.p + '_img', r.img); campo(f, s.p + '_ok', '1');
          if (!s.fijo) { campo(f, s.p + '_nombre', r.nombre); }
          campo(f, s.p + '_guardar', r.guardar && s.fijo ? '1' : '');
          i++; siguiente();
        });
      })();
    });
  });

  // ---------------------------------------------------------------- Menu lateral en pantallas pequenas
  (function () {
    // Mismo comportamiento que el shell de CengiLabs (laboratorio/includes/shell_sidebar.php).
    var side = document.getElementById('cengiLabSidebar');
    var toggle = document.getElementById('cengiLabMenuToggle');
    if (!side || !toggle) { return; }
    function poner(abierto) {
      side.classList.toggle('is-open', abierto);
      toggle.setAttribute('aria-expanded', abierto ? 'true' : 'false');
    }
    toggle.addEventListener('click', function () { poner(!side.classList.contains('is-open')); });
    document.addEventListener('click', function (ev) {
      if (side.classList.contains('is-open') && !side.contains(ev.target) && !toggle.contains(ev.target)) { poner(false); }
    });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && side.classList.contains('is-open')) { poner(false); toggle.focus(); } });
    side.addEventListener('click', function (ev) { if (ev.target.closest('a')) { poner(false); } });
  })();
})();
