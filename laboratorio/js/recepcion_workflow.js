/* Recepción workflow v3: catálogo real, boleta y consulta de registros. */
(function (root) {
  "use strict";
  const esc = value => String(value ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[c]));
  const fecha = value => String(value || "").replace(/^(\d{4})-(\d{2})-(\d{2})(?:.*)?$/, "$3/$2/$1");
  let catalogoActual = null;
  let documentoAbierto = null;
  let peticionBoleta = 0;

  function firmaHTML(rol, nombre, correo, firma) {
    const segura = /^data:image\/png;base64,[A-Za-z0-9+/=]+$/.test(firma || "");
    return '<div class="bol-firma"><div class="bol-firma-img">' + (segura
      ? '<img src="' + firma + '" alt="Firma de ' + esc(nombre || rol) + '">'
      : '<span class="bol-vacio">Sin firma digital</span>') +
      '</div><strong>' + esc(nombre || "—") + '</strong><span>' + esc(rol) +
      '</span><small>' + esc(correo || "—") + '</small></div>';
  }

  function qrBoleta(datos) {
    if (!datos.idSolicitud || typeof root.qrcode !== "function") return "";
    const qr = root.qrcode(0, "M");
    qr.addData(["SIGELAB", datos.lote, datos.numeroLaboratorioInicio, datos.numeroLaboratorioFin, datos.idSolicitud].join("|"));
    qr.make();
    return '<div class="bol-qr" aria-label="QR de identificación de la recepción">' + qr.createSvgTag({ cellSize: 2, margin: 4, scalable: true }) + "</div>";
  }

  function htmlBoleta(datos, preview = false) {
    const d = datos;
    const campo = (nombre, valor) => '<div><dt>' + esc(nombre) + '</dt><dd>' + esc(valor || "—") + '</dd></div>';
    const analisis = Array.isArray(d.analisis) ? d.analisis : [];
    const rango = [d.numeroLaboratorioInicio, d.numeroLaboratorioFin !== d.numeroLaboratorioInicio ? d.numeroLaboratorioFin : ""].filter(Boolean).join(" → ");
    return '<article class="recepcion-boleta">' +
      '<header class="bol-head"><div class="bol-marca"><img src="../assets/logo.png" alt="CENGICAÑA"><div><strong>CENGICAÑA</strong><span>Laboratorio Agroindustrial</span></div></div>' +
      '<dl class="bol-meta">' + campo("Código", "CG-R-SLA-001") + campo("Versión", "001") + campo("Fecha", fecha(d.fechaIngreso)) + '</dl>' + qrBoleta(d) + '</header>' +
      '<div class="bol-titulo"><h2>Registro de recepción de muestras</h2><span>' + (preview ? "Vista previa · cambios sin guardar" : "Recepción registrada · solicitud #" + esc(d.idSolicitud)) + '</span></div>' +
      '<dl class="bol-claves">' + campo("Número de lote", d.lote) + campo("Tipo de muestra", d.tipo) + campo("Muestras", d.numeroMuestras) + campo("Número de laboratorio", rango) + '</dl>' +
      '<section class="bol-sec"><h3>Datos del muestreo</h3><dl class="bol-grid">' +
      campo("Cliente / institución", d.institucion) + campo("Responsable del envío", d.responsableEnvio) +
      campo("Nombre del ensayo", d.codigoMuestreo) + campo("Fecha de muestreo", fecha(d.fechaMuestreo)) +
      campo("Fecha de ingreso", fecha(d.fechaIngreso)) + campo("Fecha estimada de entrega", fecha(d.fechaEstimada)) + '</dl></section>' +
      '<section class="bol-sec"><h3>Análisis solicitados <small>' + analisis.length + ' análisis</small></h3>' +
      (analisis.length ? '<ul class="bol-checks">' + analisis.map(a => '<li><span aria-hidden="true">✓</span>' + esc(a.nombre) + '</li>').join("") + '</ul>' : '<p class="bol-vacio">Sin análisis seleccionados.</p>') + '</section>' +
      '<section class="bol-sec"><h3>Observaciones</h3><p class="bol-obs">' + esc(d.observaciones || "Sin observaciones.") + '</p></section>' +
      '<section class="bol-sec"><h3>Responsables y firmas</h3><div class="bol-firmas">' +
      firmaHTML("Ingresado por", d.ingresadoPor, d.correoIngresadoPor, d.firmaIngreso) +
      firmaHTML("Recibido por", d.recibidoPor, d.correoRecibidoPor, d.firmaRecibe) + '</div></section>' +
      '<footer class="bol-pie">Km 92.5 Carretera a Santa Lucía Cotzumalguapa, Escuintla · Guatemala<br>laboratorioagroindustrial@cengicana.org · +502 3135-5033</footer></article>';
  }

  // Implícitos (Humedad residual con Textura, Rendimiento con Brix y Pol): no se ofrecen;
  // su casilla queda oculta y la marca dependenciasSeleccionadas() según la regla.
  const esImplicito = a => Array.isArray(a.implicito_con) && a.implicito_con.length > 0;
  const checkImplicito = a => '<input type="checkbox" name="analisis[]" value="' + esc(a.id_tipo) + '" hidden data-implicito="1" aria-label="Solicitar ' + esc(a.nombre) + '" data-grupo="' + esc(a.grupo || "Otros análisis") + '">';

  function renderAnalisis(data, tipo) {
    catalogoActual = data || { items: [], paquetes: [], grupos: [] };
    const grupos = [...new Set([...(catalogoActual.grupos || []), ...catalogoActual.items.map(a => a.grupo || "Otros análisis")])];
    document.getElementById("analisis-body").innerHTML = grupos.map((grupo, index) => {
      const items = catalogoActual.items.filter(a => (a.grupo || "Otros análisis") === grupo);
      if (!items.some(a => !esImplicito(a))) return items.map(checkImplicito).join("");
      return '<section class="reception-analysis-group" data-group="' + index + '"><div class="reception-group-head"><h3>' + esc(grupo) +
        '</h3><span class="group-count"></span><button type="button" data-select-group="1">Todos</button><button type="button" data-select-group="0">Ninguno</button></div><div class="reception-analysis-grid">' +
        // Formato compacto: casilla + nombre; el método y la norma quedan en el tooltip (y en la búsqueda),
        // el tipo lo indica el encabezado del grupo y se guarda en data-grupo para la boleta PDF.
        items.map(a => {
          if (esImplicito(a)) return checkImplicito(a);
          const metodo = [a.metodo, a.norma].filter(Boolean).join(" · ") || "Método no especificado";
          const deps = (a.dependencias || []).map(d => d.nombre).join(", ");
          return '<label class="reception-analysis" data-grupo="' + esc(grupo) + '" title="' + esc(a.nombre + " — " + metodo) + '" data-search="' + esc([a.nombre, a.nombre_original, a.metodo, a.norma].join(" ")) + '">' +
            '<input type="checkbox" name="analisis[]" value="' + esc(a.id_tipo) + '" id="chk-' + esc(tipo) + '-' + esc(a.id_tipo) + '" aria-label="Solicitar ' + esc(a.nombre) + '">' +
            '<span class="name">' + esc(a.nombre) + '</span>' +
            (deps ? '<span class="analysis-dependency" title="Al solicitarlo se agrega: ' + esc(deps) + '">+ ' + esc(deps) + '</span>' : "") + '</label>';
        }).join("") + '</div></section>';
    }).join("");
    document.getElementById("analisis-paquetes").innerHTML = (catalogoActual.paquetes || []).map((p, i) => {
      const items = catalogoActual.items.filter(a => p.analisis.includes(a.nombre));
      const completo = p.analisis.every(nombre => items.some(a => a.nombre === nombre));
      return '<button type="button" class="reception-package" data-package="' + i + '" aria-pressed="false" ' +
        (completo ? "" : 'disabled title="Hay análisis de este paquete desactivados en el catálogo"') + '>' + esc(p.nombre) + ' <span>' + items.filter(a => !esImplicito(a)).length + '</span></button>';
    }).join("");
    const aviso = document.getElementById("analisis-aviso");
    if (aviso) aviso.textContent = "";
  }

  function actualizarSeleccion() {
    const checks = getAnalisisCheckboxes();
    const elegidos = new Set(checks.filter(c => c.checked).map(c => String(c.value)));
    document.querySelectorAll(".reception-analysis-group").forEach(group => {
      const inputs = [...group.querySelectorAll('input[name="analisis[]"]:not([data-implicito])')];
      group.querySelector(".group-count").textContent = inputs.filter(i => i.checked).length + " de " + inputs.length;
    });
    document.querySelectorAll("[data-package]").forEach(button => {
      const p = catalogoActual?.paquetes?.[Number(button.dataset.package)];
      const items = catalogoActual?.items.filter(a => p?.analisis.includes(a.nombre)) || [];
      button.setAttribute("aria-pressed", String(items.length > 0 && items.every(a => elegidos.has(String(a.id_tipo)))));
    });
  }

  function dependenciasSeleccionadas() {
    const avisos = [];
    const checks = getAnalisisCheckboxes();
    for (const item of catalogoActual?.items || []) {
      const check = checks.find(c => String(c.value) === String(item.id_tipo));
      if (!check?.checked || esImplicito(item)) continue;
      for (const dep of item.dependencias || []) {
        const requerido = catalogoActual.items.find(a => a.nombre === dep.nombre);
        const destino = checks.find(c => String(c.value) === String(requerido?.id_tipo));
        if (destino && !destino.checked) {
          destino.checked = true;
          avisos.push(dep.nombre + ": " + dep.motivo);
        } else if (!destino) {
          check.checked = false;
          avisos.push("No se puede solicitar " + item.nombre + ": " + dep.nombre + " no está disponible.");
        }
      }
    }
    const elegidos = new Set((catalogoActual?.items || []).filter(a => checks.find(c => String(c.value) === String(a.id_tipo))?.checked).map(a => a.nombre));
    for (const item of (catalogoActual?.items || []).filter(esImplicito)) {
      const check = checks.find(c => String(c.value) === String(item.id_tipo));
      if (check) check.checked = item.implicito_con.every(nombre => elegidos.has(nombre));
    }
    document.getElementById("analisis-aviso").textContent = avisos.join(" · ");
  }

  async function consultarBoleta(id) {
    const response = await fetch("../controllers/solicitud_boleta.php?id_solicitud=" + encodeURIComponent(id), { headers: { Accept: "application/json" }, cache: "no-store" });
    if (response.redirected) throw new Error("La sesión expiró. Vuelva a iniciar sesión.");
    let datos;
    try { datos = await response.json(); } catch { throw new Error("No se pudo leer la boleta. Revise su sesión."); }
    if (!response.ok || datos.error) throw new Error(datos.error || "No se pudo cargar la boleta.");
    return datos;
  }

  async function pdfBoleta(datos) {
    if (!root.PDFLib || !root.CengiSolicitudPdf) throw new Error("No se pudo cargar el generador de PDF. Recargue la página.");
    const pdfDoc = await root.PDFLib.PDFDocument.create();
    const logo = await cargarLogoPdf(pdfDoc);
    return root.CengiSolicitudPdf.crearPdfSolicitud({ pdfDoc, PDFLib: root.PDFLib, datos, logo });
  }

  async function descargarBoleta(datos) {
    descargarPdf(await pdfBoleta(datos), buildPdfFileName(datos));
  }

  /* Envía por correo la boleta del registro ya guardado (números de lote y laboratorio definitivos). */
  async function enviarBoleta(datos) {
    const correos = [datos.correoIngresadoPor, datos.correoRecibidoPor].map(c => String(c || "").trim()).filter(Boolean);
    if (!correos.length) return "No se envió correo: la recepción no tiene correos de responsables.";
    const envio = await enviarPdfPorCorreo(await pdfBoleta(datos), buildPdfFileName(datos), { ...datos, correos: [...new Set(correos)] });
    return envio.message;
  }

  async function accionHistorial(button) {
    const id = button.dataset.solicitud;
    const accion = button.dataset.boletaAction;
    const ver = accion === "ver";
    const dialog = document.getElementById("recepcion-boleta-dialog");
    const version = ++peticionBoleta;
    const status = button.closest("#recepcion-guardada") ? document.getElementById("recepcion-guardada-estado") : document.getElementById("recepciones-aviso");
    status.textContent = "";
    document.getElementById("boleta-dialog-aviso").textContent = "";
    button.disabled = true;
    if (ver) {
      documentoAbierto = null;
      document.getElementById("boleta-dialog-pdf").disabled = true;
      document.getElementById("boleta-dialog-contenido").textContent = "Cargando boleta…";
      document.getElementById("boleta-dialog-titulo").textContent = "Boleta de recepción";
      dialog.showModal();
    }
    try {
      const datos = await consultarBoleta(id);
      if (ver) {
        if (version !== peticionBoleta || !dialog.open) return;
        documentoAbierto = datos;
        document.getElementById("boleta-dialog-contenido").innerHTML = htmlBoleta(datos);
        document.getElementById("boleta-dialog-pdf").disabled = false;
      } else if (accion === "correo") {
        const lote = datos.lote ? "Lote " + datos.lote + ": " : "";
        status.textContent = lote + "enviando boleta por correo…";
        status.textContent = lote + await enviarBoleta(datos);
      } else {
        await descargarBoleta(datos);
      }
    } catch (error) {
      status.textContent = error.message;
      if (ver && version === peticionBoleta) document.getElementById("boleta-dialog-contenido").textContent = error.message;
    } finally {
      button.disabled = false;
    }
  }

  function init() {
    document.getElementById("analisis-body").addEventListener("click", event => {
      const button = event.target.closest("[data-select-group]");
      if (!button) return;
      button.closest(".reception-analysis-group").querySelectorAll('input[name="analisis[]"]').forEach(c => c.checked = button.dataset.selectGroup === "1");
      dependenciasSeleccionadas();
      syncSelectAllAnalisis();
      actualizarVistaBoleta();
    });
    document.getElementById("analisis-paquetes").addEventListener("click", event => {
      const button = event.target.closest("[data-package]");
      if (!button || button.disabled) return;
      const paquete = catalogoActual.paquetes[Number(button.dataset.package)];
      const ids = new Set(catalogoActual.items.filter(a => paquete.analisis.includes(a.nombre)).map(a => String(a.id_tipo)));
      const seleccionar = button.getAttribute("aria-pressed") !== "true";
      getAnalisisCheckboxes().forEach(c => { if (ids.has(c.value)) c.checked = seleccionar; });
      dependenciasSeleccionadas();
      syncSelectAllAnalisis();
      actualizarVistaBoleta();
    });
    document.getElementById("recepciones-body").addEventListener("click", event => {
      const button = event.target.closest("[data-boleta-action]");
      if (button) {
        button.closest("details.reception-menu")?.removeAttribute("open");
        accionHistorial(button);
      }
    });
    // Menú "Acciones" de cada recepción: uno abierto a la vez, posición fija para que el
    // contenedor con scroll de la tabla no lo recorte, y hacia arriba si no cabe abajo.
    const menus = () => document.querySelectorAll("details.reception-menu");
    document.getElementById("recepciones-body").addEventListener("toggle", event => {
      const menu = event.target;
      if (!menu.matches?.("details.reception-menu") || !menu.open) return;
      menus().forEach(m => { if (m !== menu) m.removeAttribute("open"); });
      const lista = menu.querySelector(".reception-menu-list");
      const caja = menu.querySelector("summary").getBoundingClientRect();
      lista.style.left = Math.max(8, caja.right - lista.offsetWidth) + "px";
      const abajo = caja.bottom + 4 + lista.offsetHeight <= window.innerHeight;
      lista.style.top = (abajo ? caja.bottom + 4 : Math.max(8, caja.top - 4 - lista.offsetHeight)) + "px";
    }, true);
    document.addEventListener("click", event => {
      if (!event.target.closest("details.reception-menu")) menus().forEach(m => m.removeAttribute("open"));
    });
    document.addEventListener("keydown", event => {
      if (event.key === "Escape") menus().forEach(m => m.removeAttribute("open"));
    });
    window.addEventListener("scroll", () => menus().forEach(m => m.removeAttribute("open")), true);
    const guardada = document.getElementById("recepcion-guardada");
    if (guardada) {
      guardada.addEventListener("click", event => {
        const button = event.target.closest("[data-boleta-action]");
        if (button) accionHistorial(button);
      });
      // Recepción recién registrada: enviar su boleta definitiva a los responsables.
      // Si el correo falla, el registro ya está guardado y puede reenviarse con "Enviar por correo".
      if (guardada.dataset.enviarCorreo === "1" && Number(guardada.dataset.solicitud) > 0) {
        const estado = document.getElementById("recepcion-guardada-estado");
        estado.textContent = "Enviando la boleta por correo a los responsables…";
        consultarBoleta(guardada.dataset.solicitud)
          .then(enviarBoleta)
          .then(msg => { estado.textContent = msg; })
          .catch(error => { estado.textContent = "La recepción quedó guardada, pero no se pudo enviar la boleta por correo: " + error.message + " Puede reenviarla con «Enviar por correo»."; });
      }
    }
    const dialog = document.getElementById("recepcion-boleta-dialog");
    document.getElementById("boleta-dialog-cerrar").addEventListener("click", () => dialog.close());
    dialog.addEventListener("close", () => { documentoAbierto = null; peticionBoleta++; });
    document.getElementById("boleta-dialog-pdf").addEventListener("click", async event => {
      const button = event.currentTarget;
      if (!documentoAbierto) return;
      button.disabled = true;
      try { await descargarBoleta(documentoAbierto); }
      catch (error) { document.getElementById("boleta-dialog-aviso").textContent = error.message; }
      finally { button.disabled = false; }
    });
    document.querySelectorAll(".firma-canvas").forEach(c => {
      c.addEventListener("pointerup", actualizarVistaBoleta);
      c.addEventListener("pointercancel", actualizarVistaBoleta);
    });
    document.querySelectorAll("[data-clear-canvas]").forEach(b => b.addEventListener("click", actualizarVistaBoleta));
    actualizarSeleccion();
  }

  root.CengiRecepcion = { htmlBoleta, renderAnalisis, actualizarSeleccion, dependenciasSeleccionadas, consultarBoleta, init };
})(window);

