const SOLICITUDES_DB = readJsonData("solicitudes-db", []);
const CORRELATIVOS_LOTE_DB = readJsonData("correlativos-lote-db", {});
const PDF_LOGO_URL = "../assets/logo.png";
let solicitudSeleccionada = null;

const ANALISIS = readJsonData("analisis-catalogo", {});
function readJsonData(id, fallback) {
  const script = document.getElementById(id);
  if (!script) return fallback;

  try {
    return JSON.parse(script.textContent || "");
  } catch {
    return fallback;
  }
}

function setTipoFormulario(tipo) {
  const input = document.getElementById("tipo_form");
  if (input) input.value = tipo;
}

function getTipoFormularioPorDefecto() {
  const activo = document.querySelector(".tipo-btn.active:not([disabled])");
  if (activo?.dataset.tipo) return activo.dataset.tipo;

  const primero = document.querySelector(".tipo-btn:not([disabled])");
  if (primero?.dataset.tipo) return primero.dataset.tipo;

  return "suelos";
}

function renderAnalisis(tipo) {
  const data = ANALISIS[tipo];
  const body = document.getElementById("analisis-body");
  if (!body) return;
  setTipoFormulario(tipo);
  CengiRecepcion.renderAnalisis(data, tipo);
  document.getElementById("tipo-label-header").textContent = data?.label || tipo;
  const search = document.getElementById("analisis-buscar");
  if (search) search.value = "";
  updateNumeroLaboratorio();
  syncSelectAllAnalisis();
  filtrarAnalisisRecepcion();
}

function normalizarBusquedaRecepcion(value) {
  return String(value || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLocaleLowerCase().trim();
}

function filtrarAnalisisRecepcion() {
  const query = normalizarBusquedaRecepcion(document.getElementById("analisis-buscar")?.value);
  let visibles = 0;
  document.querySelectorAll(".reception-analysis").forEach(card => {
    card.hidden = !normalizarBusquedaRecepcion(card.dataset.search).includes(query);
    if (!card.hidden) visibles++;
  });
  document.querySelectorAll(".reception-analysis-group").forEach(group => {
    group.hidden = ![...group.querySelectorAll(".reception-analysis")].some(card => !card.hidden);
  });
  const empty = document.getElementById("analisis-vacio");
  if (empty) {
    empty.hidden = visibles > 0;
    empty.textContent = getAnalisisCheckboxes().length
      ? "No hay análisis que coincidan con la búsqueda."
      : "No hay análisis activos para este tipo de muestra.";
  }
}
function getAnalisisCheckboxes() {
  return Array.from(document.querySelectorAll('#analisis-body input[name="analisis[]"]'));
}

function syncSelectAllAnalisis() {
  const selectAll = document.getElementById('select-all-analisis');
  if (!selectAll) return;

  const checks = getAnalisisCheckboxes().filter(input => !input.dataset.implicito);
  const total = checks.length;
  const checked = checks.filter(input => input.checked).length;

  selectAll.disabled = total === 0;
  selectAll.checked = total > 0 && checked === total;
  selectAll.indeterminate = checked > 0 && checked < total;
  CengiRecepcion.actualizarSeleccion();
  const resumen = document.getElementById("analisis-resumen");
  if (resumen) resumen.textContent = checked + " de " + total + " análisis seleccionados · Se aplican a todas las muestras del lote.";
  actualizarAvanceRecepcion();
}

function toggleSelectAllAnalisis(checked) {
  getAnalisisCheckboxes().forEach(input => {
    input.checked = checked;
  });
  CengiRecepcion.dependenciasSeleccionadas();
  syncSelectAllAnalisis();
}

function initSeleccionAnalisis() {
  const selectAll = document.getElementById('select-all-analisis');
  const body = document.getElementById('analisis-body');

  if (selectAll) {
    selectAll.addEventListener('change', () => toggleSelectAllAnalisis(selectAll.checked));
  }

  if (body) {
    body.addEventListener('change', event => {
      if (event.target.matches('input[name="analisis[]"]')) {
        CengiRecepcion.dependenciasSeleccionadas();
        syncSelectAllAnalisis();
      }
    });
  }
}

function getInicialTipo(tipo) {
  if (solicitudSeleccionada?.prefijo && getTipoPorPrefijo(solicitudSeleccionada.prefijo) === tipo) {
    return solicitudSeleccionada.prefijo.toUpperCase();
  }

  if (CORRELATIVOS_LOTE_DB[tipo]?.prefijo) {
    return String(CORRELATIVOS_LOTE_DB[tipo].prefijo).toUpperCase();
  }

  const iniciales = {
    "suelos": "S",
    "suelo-fisico": "S",
    "suelo-quimico": "S",
    "foliares": "F",
    "cana": "C",
    "miel": "M",
    "agua": "A",
  };

  return iniciales[tipo] || "S";
}

function formatLote(numero, longitud) {
  return String(numero).padStart(longitud, "0");
}

function getMesAnio(fecha) {
  if (!fecha) return "";

  const partes = fecha.split("-");
  if (partes.length < 2) return "";

  return `${partes[1]}-${partes[0].slice(-2)}`;
}

/* Vista previa: correlativo de laboratorio independiente por tipo (inicia en 1); se confirma al registrar. */
function getSiguienteNumeroPorTipo(tipo) {
  return parseInt(CORRELATIVOS_LOTE_DB[tipo]?.siguiente_laboratorio, 10) || 1;
}

function updateNumeroLote() {
  const tipoActivo = document.querySelector(".tipo-btn.active")?.dataset.tipo || "suelos";
  const loteInput = document.getElementById("lote");
  const loteResumen = document.getElementById("lote-resumen");
  if (!loteInput) return;

  const tipoSolicitud = getTipoPorPrefijo(solicitudSeleccionada?.prefijo);
  const codigo = solicitudSeleccionada && tipoSolicitud === tipoActivo
    ? String(solicitudSeleccionada.codigo_lote || "")
    : String(CORRELATIVOS_LOTE_DB[tipoActivo]?.siguiente_codigo || "");

  loteInput.value = codigo;
  if (loteResumen) loteResumen.value = codigo;
  actualizarVistaBoleta();
}

function updateNumeroLaboratorio() {
  if (solicitudSeleccionada) {
    const codigos = JSON.parse(solicitudSeleccionada.codigos_laboratorio || "[]").sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
    if (codigos.length) {
      document.getElementById("n_laboratorio_inicio").value = codigos[0];
      document.getElementById("n_laboratorio_fin").value = codigos[codigos.length - 1];
      document.getElementById("n_laboratorio").value = codigos[0] + " / " + codigos[codigos.length - 1];
      updateNumeroLote();
      return;
    }
  }
  const tipoActivo = document.querySelector(".tipo-btn.active")?.dataset.tipo || "suelos";
  const fecha = document.getElementById("fecha_muestreo")?.value || "";
  const muestras = parseInt(document.getElementById("numero_muestras")?.value, 10);
  const inicioInput = document.getElementById("n_laboratorio_inicio");
  const finInput = document.getElementById("n_laboratorio_fin");
  const ocultoInput = document.getElementById("n_laboratorio");

  if (!inicioInput || !finInput || !ocultoInput) return;

  sincronizarFechasSolicitudVisuales(tipoActivo);
  updateNumeroLote();

  const inicial = getInicialTipo(tipoActivo);
  const inicioGuardado = solicitudSeleccionada?.inicio_laboratorio ? parseInt(solicitudSeleccionada.inicio_laboratorio, 10) : null;
  const loteInicial = inicioGuardado || getSiguienteNumeroPorTipo(tipoActivo);
  const mesAnio = getMesAnio(fecha);

  if (Number.isNaN(loteInicial) || !mesAnio || Number.isNaN(muestras) || muestras < 1) {
    inicioInput.value = "";
    finInput.value = "";
    ocultoInput.value = "";
    return;
  }

  const loteFinal = loteInicial + muestras - 1;
  const longitudLote = Math.max(3, String(loteFinal).length);
  const codigoInicio = `${inicial}-${formatLote(loteInicial, longitudLote)}-${mesAnio}`;
  const codigoFin = `${inicial}-${formatLote(loteFinal, longitudLote)}-${mesAnio}`;

  inicioInput.value = codigoInicio;
  finInput.value = codigoFin;
  ocultoInput.value = `${codigoInicio} / ${codigoFin}`;
}

function formatearFechaVisual(fecha) {
  if (!(fecha instanceof Date) || Number.isNaN(fecha.getTime())) {
    return "";
  }

  const dia = String(fecha.getDate()).padStart(2, "0");
  const mes = String(fecha.getMonth() + 1).padStart(2, "0");
  const anio = String(fecha.getFullYear());

  return `${dia}/${mes}/${anio}`;
}

function obtenerFechaVisualActual() {
  return formatearFechaVisual(new Date());
}

function formatearFechaBaseAVisual(fechaTexto) {
  const texto = String(fechaTexto || "").trim();
  if (!texto) {
    return "";
  }

  const partes = texto.split("-");
  if (partes.length === 3 && partes[0].length === 4) {
    const [anio, mes, dia] = partes;
    return `${dia.padStart(2, "0")}/${mes.padStart(2, "0")}/${anio}`;
  }

  return texto;
}

function parsearFechaVisual(fechaTexto) {
  const partes = String(fechaTexto || "").trim().split("/");
  if (partes.length !== 3) {
    return null;
  }

  const dia = parseInt(partes[0], 10);
  const mes = parseInt(partes[1], 10);
  const anio = parseInt(partes[2], 10);

  if (Number.isNaN(dia) || Number.isNaN(mes) || Number.isNaN(anio)) {
    return null;
  }

  const fecha = new Date(anio, mes - 1, dia);
  if (
    Number.isNaN(fecha.getTime()) ||
    fecha.getFullYear() !== anio ||
    fecha.getMonth() !== mes - 1 ||
    fecha.getDate() !== dia
  ) {
    return null;
  }

  return fecha;
}

function obtenerDiasCompromisoVisual(tipo) {
  switch (String(tipo || "").toLowerCase()) {
    case "suelos":
    case "foliares":
      return 28;
    case "agua":
      return 15;
    case "cana":
    case "miel":
      return 9;
    default:
      return 0;
  }
}

/* Días de entrega: la mayor "Duración (días)" del Catálogo de análisis entre los análisis
   marcados (el reporte sale cuando termina el más largo); sin duración configurada se usan
   los días de compromiso por tipo de muestra. Mismo criterio que el servidor al guardar. */
function diasEntregaSeleccion(tipo) {
  const items = (ANALISIS[tipo] && ANALISIS[tipo].items) || [];
  const marcados = new Set(getAnalisisCheckboxes().filter(c => c.checked).map(c => String(c.value)));
  let max = 0;
  let analisisMax = "";
  items.forEach(item => {
    const dias = Number(item.duracion_dias) || 0;
    if (marcados.has(String(item.id_tipo)) && dias > max) {
      max = dias;
      analisisMax = item.nombre || "";
    }
  });
  return { dias: max, analisis: analisisMax };
}

function calcularFechaEstimadaVisual(fechaIngresoTexto, tipo) {
  const fechaIngreso = parsearFechaVisual(fechaIngresoTexto);
  const diasCompromiso = diasEntregaSeleccion(tipo).dias || obtenerDiasCompromisoVisual(tipo);

  if (!fechaIngreso || diasCompromiso <= 0) {
    return "";
  }

  fechaIngreso.setDate(fechaIngreso.getDate() + diasCompromiso);
  return formatearFechaVisual(fechaIngreso);
}

function sincronizarFechasSolicitudVisuales(tipoForzado = null) {
  const fechaIngresoInput = document.getElementById("fecha_ingreso");
  const fechaEstimadaInput = document.getElementById("fecha_estimada");

  if (!fechaIngresoInput || !fechaEstimadaInput) {
    return;
  }

  if (solicitudSeleccionada) {
    if (solicitudSeleccionada.fecha_ingreso) {
      fechaIngresoInput.value = formatearFechaBaseAVisual(solicitudSeleccionada.fecha_ingreso);
    } else if (!fechaIngresoInput.value) {
      fechaIngresoInput.value = obtenerFechaVisualActual();
    }
  } else {
    fechaIngresoInput.value = obtenerFechaVisualActual();
  }

  const tipoActivo = tipoForzado || document.querySelector(".tipo-btn.active")?.dataset.tipo || "suelos";
  if (solicitudSeleccionada?.fecha_estimada) {
    fechaEstimadaInput.value = formatearFechaBaseAVisual(solicitudSeleccionada.fecha_estimada);
  } else {
    fechaEstimadaInput.value = calcularFechaEstimadaVisual(fechaIngresoInput.value, tipoActivo);
  }
}
function setCamposReadonly(readonly) {
  ["numero_de_muestra", "fecha_muestreo", "numero_muestras"].forEach(id => {
    const input = document.getElementById(id);
    if (input) input.readOnly = readonly;
  });

  const lote = document.getElementById("lote");
  if (lote) lote.readOnly = true;
}

function getTipoPorPrefijo(prefijo) {
  const tipos = {
    "S": "suelos",
    "F": "foliares",
    "C": "cana",
    "M": "miel",
    "A": "agua",
  };

  return tipos[String(prefijo || "").toUpperCase()] || null;
}

function seleccionarTipoPorSolicitud(solicitud) {
  const tipo = getTipoPorPrefijo(solicitud?.prefijo);
  if (!tipo || !ANALISIS[tipo]) return;

  const btn = document.querySelector(`.tipo-btn[data-tipo="${tipo}"]`);
  if (btn) {
    document.querySelectorAll(".tipo-btn").forEach(b => b.classList.remove("active"));
    btn.classList.add("active");
  }

  renderAnalisis(tipo);
}

function aplicarSolicitudDb(idSolicitud) {
  solicitudSeleccionada = SOLICITUDES_DB.find(solicitud => String(solicitud.id_solicitud) === String(idSolicitud)) || null;

  if (!solicitudSeleccionada) {
    setCamposReadonly(false);
    updateNumeroLaboratorio();
    return;
  }

  seleccionarTipoPorSolicitud(solicitudSeleccionada);
  const analisisSeleccionados = new Set(
    String(solicitudSeleccionada.analisis_ids || "")
      .split(",")
      .map(id => id.trim())
      .filter(Boolean)
  );
  getAnalisisCheckboxes().forEach(input => {
    input.checked = analisisSeleccionados.has(String(input.value));
  });
  syncSelectAllAnalisis();
  document.getElementById("numero_de_muestra").value = solicitudSeleccionada.codigo_muestreo || "";
  document.getElementById("lote").value = solicitudSeleccionada.codigo_lote || "";
  document.getElementById("fecha_muestreo").value = solicitudSeleccionada.fecha_muestreo || "";
  document.getElementById("numero_muestras").value = solicitudSeleccionada.numero_muestras || "";
  const institucion = document.getElementById("institucion");
  const responsableEnvio = document.getElementById("responsable_envio");
  const ingresadoPor = document.querySelector('input[name="ingresado_por"]');
  const correoIngresado = document.querySelector('input[name="correo_ingresado_por"]');
  const recibidoPor = document.querySelector('input[name="recibido_por"]');
  const correoRecibido = document.querySelector('input[name="correo_recibido_por"]');
  const observaciones = document.getElementById("observaciones");
  if (institucion) institucion.value = solicitudSeleccionada.institucion || "";
  if (responsableEnvio) responsableEnvio.value = solicitudSeleccionada.responsable_envio || "";
  if (ingresadoPor) ingresadoPor.value = solicitudSeleccionada.ingresado_por || "";
  if (correoIngresado) correoIngresado.value = solicitudSeleccionada.correo_ingresado || "";
  if (recibidoPor) recibidoPor.value = solicitudSeleccionada.recibido_por || "";
  if (correoRecibido) correoRecibido.value = solicitudSeleccionada.correo_recibido || "";
  if (observaciones) observaciones.value = solicitudSeleccionada.observaciones || "";
  setCamposReadonly(false);
  updateNumeroLaboratorio();

  const fechaIngresoInput = document.getElementById("fecha_ingreso");
  const fechaEstimadaInput = document.getElementById("fecha_estimada");

  if (fechaIngresoInput) {
    fechaIngresoInput.value = formatearFechaBaseAVisual(solicitudSeleccionada.fecha_ingreso) || fechaIngresoInput.value || "";
  }

  if (fechaEstimadaInput) {
    fechaEstimadaInput.value = formatearFechaBaseAVisual(solicitudSeleccionada.fecha_estimada) || fechaEstimadaInput.value || "";
  }
}

function initTipoButtons() {
  const tipoBtns = document.getElementById("tipo-btns");
  if (!tipoBtns) return;

  tipoBtns.addEventListener("click", event => {
    const btn = event.target.closest(".tipo-btn");
    if (!btn || btn.disabled) return;

    document.querySelectorAll(".tipo-btn").forEach(item => item.classList.remove("active"));
    btn.classList.add("active");
    renderAnalisis(btn.dataset.tipo);
  });
}

function initLaboratorioInputs() {
  ["fecha_muestreo", "numero_muestras"].forEach(id => {
    const input = document.getElementById(id);
    if (input) input.addEventListener("input", updateNumeroLaboratorio);
  });
}

function initSolicitudSelect() {
  const solicitudSelect = document.getElementById("solicitud_registrada");
  if (solicitudSelect) {
    solicitudSelect.addEventListener("change", () => aplicarSolicitudDb(solicitudSelect.value));
  }

  return solicitudSelect;
}

function initTipoDesdeQuery() {
  const params = new URLSearchParams(window.location.search);
  const aliasTipos = {
    "suelo-fisico": "suelos",
    "suelo-quimico": "suelos",
  };
  const qOriginal = params.get("tipo");
  const q = aliasTipos[qOriginal] || qOriginal;
  const btn = q ? document.querySelector(`.tipo-btn[data-tipo="${q}"]`) : null;

  if (!q || !ANALISIS[q] || !btn || btn.disabled) {
    renderAnalisis(getTipoFormularioPorDefecto());
    return;
  }

  const tiposCont = document.getElementById("tipo-btns");
  if (tiposCont) tiposCont.style.display = "none";

  if (btn) {
    document.querySelectorAll(".tipo-btn").forEach(item => item.classList.remove("active"));
    btn.classList.add("active");
  }

  renderAnalisis(q);
}

function initSolicitudDesdeQuery(solicitudSelect) {
  const params = new URLSearchParams(window.location.search);
  const idSolicitud = params.get("id_solicitud");

  if (idSolicitud) {
    if (solicitudSelect) solicitudSelect.value = idSolicitud;
    aplicarSolicitudDb(idSolicitud);
    document.getElementById("numero_muestras").readOnly = true;
    document.querySelectorAll(".tipo-btn").forEach(btn => btn.disabled = true);
    document.getElementById("ticket-finalizar").textContent = "Guardar cambios de recepción";
  }
}

function makeDrawable(canvasId) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;

  function resize() {
    const parent = canvas.parentElement;
    const rect = parent.getBoundingClientRect();
    const parentStyles = window.getComputedStyle(parent);
    const paddingX = parseFloat(parentStyles.paddingLeft) + parseFloat(parentStyles.paddingRight);
    const canvasWidth = rect.width - paddingX;
    canvas.style.height = "";
    const styles = window.getComputedStyle(canvas);
    const canvasHeight = parseFloat(styles.height) || 90;
    const ratio = window.devicePixelRatio || 1;
    const snapshot = canvas.toDataURL();

    canvas.width = canvasWidth * ratio;
    canvas.height = canvasHeight * ratio;
    canvas.style.width = canvasWidth + "px";
    canvas.style.height = canvasHeight + "px";

    const ctx = canvas.getContext("2d");
    ctx.scale(ratio, ratio);
    ctx.strokeStyle = "#73BC25";
    ctx.lineWidth = 2;
    ctx.lineCap = "round";
    ctx.lineJoin = "round";

    const img = new Image();
    img.onload = () => ctx.drawImage(img, 0, 0, canvasWidth, canvasHeight);
    img.src = snapshot;
  }

  resize();
  window.addEventListener("resize", resize);

  const ctx = canvas.getContext("2d");
  let drawing = false;
  let lx = 0;
  let ly = 0;

  function getPos(event) {
    const rect = canvas.getBoundingClientRect();
    const src = event.touches ? event.touches[0] : event;
    return {
      x: src.clientX - rect.left,
      y: src.clientY - rect.top,
    };
  }

  canvas.addEventListener("pointerdown", event => {
    drawing = true;
    const position = getPos(event);
    lx = position.x;
    ly = position.y;
    canvas.setPointerCapture(event.pointerId);
  });

  canvas.addEventListener("pointermove", event => {
    if (!drawing) return;

    const position = getPos(event);
    ctx.beginPath();
    ctx.moveTo(lx, ly);
    ctx.lineTo(position.x, position.y);
    ctx.stroke();
    lx = position.x;
    ly = position.y;
  });

  canvas.addEventListener("pointerup", () => drawing = false);
  canvas.addEventListener("pointercancel", () => drawing = false);
}

function clearCanvas(id) {
  const canvas = document.getElementById(id);
  if (!canvas) return;
  canvas.getContext("2d").clearRect(0, 0, canvas.width, canvas.height);
}

function initFirmas() {
  makeDrawable("canvas-ingreso");
  makeDrawable("canvas-recibe");

  document.querySelectorAll("[data-clear-canvas]").forEach(button => {
    button.addEventListener("click", () => clearCanvas(button.dataset.clearCanvas));
  });
}

function limpiarTextoPdf(value) {
  return String(value ?? "")
    .replace(/\r/g, "")
    .replace(/[–—]/g, "-")
    .replace(/[“”]/g, '"')
    .replace(/[‘’]/g, "'")
    .replace(/[^\n\x20-\x7e\xa0-\xff]/g, "")
    .trim();
}

function getInputValue(selector) {
  const input = document.querySelector(selector);
  return limpiarTextoPdf(input?.value || input?.textContent || "");
}

function getAnalisisSeleccionados() {
  return Array.from(document.querySelectorAll('input[name="analisis[]"]:checked')).map(input => {
    const row = input.closest(".reception-analysis, tr");
    const nombre = row?.querySelector(".name")?.textContent || input.getAttribute("aria-label") || input.value;
    return {
      nombre: limpiarTextoPdf(nombre.replace(/^Solicitar\s+/i, "")),
      tipo: limpiarTextoPdf(row?.dataset.grupo || row?.querySelector(".analisis-tag")?.textContent || input.dataset.grupo || ""),
    };
  });
}

function getCorreosResponsables() {
  return [
    getInputValue('input[name="correo_ingresado_por"]'),
    getInputValue('input[name="correo_recibido_por"]'),
  ].filter(Boolean);
}

function validarCorreosResponsables() {
  const inputs = Array.from(document.querySelectorAll('input[name="correo_ingresado_por"], input[name="correo_recibido_por"]'));
  const invalido = inputs.find(input => input.value.trim() && !input.validity.valid);

  if (invalido) {
    invalido.reportValidity();
    return false;
  }

  return true;
}

function canvasTieneFirma(canvas) {
  if (!canvas || !canvas.width || !canvas.height) return false;

  const pixels = canvas.getContext("2d").getImageData(0, 0, canvas.width, canvas.height).data;
  for (let i = 3; i < pixels.length; i += 4) {
    if (pixels[i] > 0) return true;
  }

  return false;
}

function getFirmaDataUrl(canvasId) {
  const canvas = document.getElementById(canvasId);
  return canvasTieneFirma(canvas) ? canvas.toDataURL("image/png") : "";
}

function syncFirmaInputs() {
  const firmaIngresoInput = document.getElementById("firma_ingreso");
  const firmaRecibeInput = document.getElementById("firma_recibe");

  if (firmaIngresoInput) firmaIngresoInput.value = getFirmaDataUrl("canvas-ingreso");
  if (firmaRecibeInput) firmaRecibeInput.value = getFirmaDataUrl("canvas-recibe");
}

function getDatosSolicitudPdf() {
  syncFirmaInputs();

  return {
    idSolicitud: getInputValue("#id_solicitud"),
    preview: true,
    tipo: getInputValue("#tipo-label-header"),
    institucion: getInputValue("#institucion"),
    responsableEnvio: getInputValue("#responsable_envio"),
    lote: getInputValue("#lote"),
    codigoMuestreo: getInputValue("#numero_de_muestra"),
    fechaMuestreo: getInputValue("#fecha_muestreo"),
    numeroMuestras: getInputValue("#numero_muestras"),
    numeroLaboratorioInicio: getInputValue("#n_laboratorio_inicio"),
    numeroLaboratorioFin: getInputValue("#n_laboratorio_fin"),
    fechaIngreso: getInputValue("#fecha_ingreso"),
    fechaEstimada: getInputValue("#fecha_estimada"),
    ingresadoPor: getInputValue('input[name="ingresado_por"]'),
    correoIngresadoPor: getInputValue('input[name="correo_ingresado_por"]'),
    recibidoPor: getInputValue('input[name="recibido_por"]'),
    correoRecibidoPor: getInputValue('input[name="correo_recibido_por"]'),
    observaciones: getInputValue("#observaciones"),
    analisis: getAnalisisSeleccionados(),
    correos: getCorreosResponsables(),
    firmaIngreso: getFirmaDataUrl("canvas-ingreso"),
    firmaRecibe: getFirmaDataUrl("canvas-recibe"),
  };
}

function wrapPdfText(text, font, size, maxWidth) {
  const paragraphs = limpiarTextoPdf(text).split("\n");
  const lines = [];

  paragraphs.forEach(paragraph => {
    const words = paragraph.split(/\s+/).filter(Boolean);

    if (!words.length) {
      lines.push("");
      return;
    }

    let line = "";
    words.forEach(word => {
      const testLine = line ? `${line} ${word}` : word;

      if (font.widthOfTextAtSize(testLine, size) <= maxWidth) {
        line = testLine;
        return;
      }

      if (line) lines.push(line);

      if (font.widthOfTextAtSize(word, size) <= maxWidth) {
        line = word;
        return;
      }

      let fragment = "";
      Array.from(word).forEach(char => {
        const testFragment = fragment + char;
        if (font.widthOfTextAtSize(testFragment, size) <= maxWidth) {
          fragment = testFragment;
        } else {
          if (fragment) lines.push(fragment);
          fragment = char;
        }
      });
      line = fragment;
    });

    if (line) lines.push(line);
  });

  return lines;
}

function buildPdfFileName(datos) {
  const fecha = new Date().toISOString().slice(0, 10);
  const base = datos.lote || datos.numeroLaboratorioInicio || "solicitud";
  const safeBase = base.toLowerCase().replace(/[^a-z0-9_-]+/g, "-").replace(/^-+|-+$/g, "") || "solicitud";
  return `solicitud_${safeBase}_${fecha}.pdf`;
}

function descargarPdf(pdfBytes, fileName) {
  const blob = new Blob([pdfBytes], { type: "application/pdf" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = fileName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function bytesToBase64(bytes) {
  const chunkSize = 0x8000;
  let binary = "";

  for (let i = 0; i < bytes.length; i += chunkSize) {
    binary += String.fromCharCode(...bytes.subarray(i, i + chunkSize));
  }

  return btoa(binary);
}

async function cargarLogoPdf(pdfDoc) {
  try {
    const response = await fetch(PDF_LOGO_URL, { cache: "no-store" });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);

    const imageBytes = new Uint8Array(await response.arrayBuffer());
    return await pdfDoc.embedPng(imageBytes);
  } catch (error) {
    console.warn("No se pudo cargar el logo de Cengicana para el PDF.", error);
    return null;
  }
}

async function enviarPdfPorCorreo(pdfBytes, fileName, datos) {
  if (!datos.correos.length) {
    return {
      sent: false,
      message: "PDF descargado. No se envio correo porque no se ingreso ningun correo de responsable.",
    };
  }

  const response = await fetch("../controllers/enviar_solicitud_pdf.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "Accept": "application/json",
    },
    body: JSON.stringify({
      pdf_base64: bytesToBase64(pdfBytes),
      file_name: fileName,
      emails: datos.correos,
      solicitud: {
        tipo: datos.tipo,
        institucion: datos.institucion,
        responsable_envio: datos.responsableEnvio,
        lote: datos.lote,
        codigo_muestreo: datos.codigoMuestreo,
        fecha_muestreo: datos.fechaMuestreo,
        numero_muestras: datos.numeroMuestras,
        laboratorio_inicio: datos.numeroLaboratorioInicio,
        laboratorio_fin: datos.numeroLaboratorioFin,
        fecha_ingreso: datos.fechaIngreso,
        fecha_estimada: datos.fechaEstimada,
        ingresado_por: datos.ingresadoPor,
        correo_ingresado_por: datos.correoIngresadoPor,
        recibido_por: datos.recibidoPor,
        correo_recibido_por: datos.correoRecibidoPor,
        observaciones: datos.observaciones,
      },
      analisis: datos.analisis,
    }),
  });

  let payload = null;
  const responseText = await response.text();
  try {
    payload = JSON.parse(responseText);
  } catch {
    const detalleHttp = response.status ? ` (HTTP ${response.status})` : "";
    payload = {
      ok: false,
      message: `El servidor no devolvio una respuesta valida al enviar el correo${detalleHttp}.`,
    };
  }

  if (!response.ok || !payload?.ok) {
    throw new Error(payload?.message || "No se pudo enviar el correo.");
  }

  return {
    sent: true,
    message: payload.message || "Correo enviado correctamente.",
  };
}

/* Registrar primero (prototipo): al guardar se asignan lote y números de laboratorio
   definitivos; la boleta de ese registro se ve, descarga o envía después. */
function finalizarSolicitud() {
  const form = document.getElementById('solicitud-form');
  if (!form || !form.reportValidity()) return;
  if (!validarCorreosResponsables()) return;

  const faltan = [];
  const motivo = document.getElementById('motivo_edicion');
  if (motivo && motivo.value.trim().length < 5) faltan.push('el motivo de la edición');
  if (!getAnalisisCheckboxes().some(input => input.checked)) faltan.push('al menos un análisis');
  if (!canvasTieneFirma(document.getElementById('canvas-ingreso'))) faltan.push('firma de quien ingresa');
  if (!canvasTieneFirma(document.getElementById('canvas-recibe'))) faltan.push('firma de quien recibe');
  if (faltan.length) {
    alert('Falta: ' + faltan.join(', ') + '.');
    if (motivo && motivo.value.trim().length < 5) motivo.focus();
    return;
  }

  const btn = document.getElementById('ticket-finalizar');
  if (btn) btn.disabled = true;
  syncFirmaInputs();
  form.submit();
}

function initFirmaSubmitSync() {
  const form = document.getElementById("solicitud-form");
  if (form) form.addEventListener("submit", syncFirmaInputs);
}

/* Sin vista previa lateral: solo actualiza el avance (Datos · Análisis · Firmas). */
function actualizarVistaBoleta() {
  actualizarAvanceRecepcion();
  actualizarFechaEstimadaPorAnalisis();
}

/* Recalcula la fecha estimada al marcar o desmarcar análisis (solo en recepciones nuevas:
   al editar se conserva la fecha comprometida) y explica de dónde sale. */
function actualizarFechaEstimadaPorAnalisis() {
  const fechaIngresoInput = document.getElementById("fecha_ingreso");
  const fechaEstimadaInput = document.getElementById("fecha_estimada");
  if (!fechaIngresoInput || !fechaEstimadaInput || solicitudSeleccionada) return;
  const tipo = document.querySelector(".tipo-btn.active")?.dataset.tipo || "suelos";
  fechaEstimadaInput.value = calcularFechaEstimadaVisual(fechaIngresoInput.value, tipo);
  let ayuda = document.getElementById("fecha_estimada_ayuda");
  if (!ayuda) {
    ayuda = document.createElement("small");
    ayuda.id = "fecha_estimada_ayuda";
    ayuda.className = "fecha-estimada-ayuda";
    ayuda.setAttribute("aria-live", "polite");
    fechaEstimadaInput.insertAdjacentElement("afterend", ayuda);
  }
  const entrega = diasEntregaSeleccion(tipo);
  ayuda.textContent = entrega.dias
    ? entrega.dias + " día(s) · análisis más largo: " + entrega.analisis
    : (obtenerDiasCompromisoVisual(tipo) ? obtenerDiasCompromisoVisual(tipo) + " día(s) · plazo general del tipo de muestra" : "");
}
function initVistaBoleta() {
  const form = document.getElementById("solicitud-form");
  if (form) {
    form.addEventListener("input", actualizarVistaBoleta);
    form.addEventListener("change", actualizarVistaBoleta);
  }
  actualizarVistaBoleta();
}

initSeleccionAnalisis();
initTipoButtons();
initLaboratorioInputs();
const solicitudSelect = initSolicitudSelect();
sincronizarFechasSolicitudVisuales();
initTipoDesdeQuery();
initSolicitudDesdeQuery(solicitudSelect);
initFirmas();
initFirmaSubmitSync();
initVistaBoleta();
document.getElementById('ticket-finalizar')?.addEventListener('click', finalizarSolicitud);

function actualizarAvanceRecepcion() {
  const datos = Boolean(getInputValue("#institucion") && getInputValue("#fecha_muestreo") && Number(getInputValue("#numero_muestras")) > 0);
  const analisis = getAnalisisCheckboxes().some(input => input.checked);
  const firmas = Boolean(getInputValue('input[name="ingresado_por"]') && getInputValue('input[name="recibido_por"]')
    && canvasTieneFirma(document.getElementById("canvas-ingreso")) && canvasTieneFirma(document.getElementById("canvas-recibe")));
  Object.entries({ datos, analisis, firmas }).forEach(([paso, completo]) => {
    const item = document.querySelector('[data-step="' + paso + '"]');
    if (item) {
      item.classList.toggle("complete", completo);
      item.setAttribute("aria-label", paso + (completo ? ": completo" : ": pendiente"));
    }
  });
}

// Recepciones registradas: 25 por página y por tipo de muestra. La primera página llega con la
// vista; las demás páginas, el cambio de pestaña y la búsqueda se piden al servidor.
const estadoRecepciones = Object.assign({ pagina: 1, paginas: 1, total: 0, por_pagina: 25 }, readJsonData("recepciones-estado", {}));
let peticionRecepciones = 0;
let temporizadorBusquedaRec = null;

function pintarPaginadorRecepciones() {
  const { pagina, paginas, total, por_pagina: porPagina } = estadoRecepciones;
  const pager = document.getElementById("recepciones-paginas");
  if (!pager) return;
  pager.hidden = paginas <= 1;
  const inicio = total ? (pagina - 1) * porPagina + 1 : 0;
  document.getElementById("recepciones-rango").textContent = total ? inicio + "–" + Math.min(pagina * porPagina, total) + " de " + total : "";
  document.getElementById("recepciones-pagina-info").textContent = "Página " + pagina + " de " + paginas;
  document.getElementById("recepciones-anterior").disabled = pagina <= 1;
  document.getElementById("recepciones-siguiente").disabled = pagina >= paginas;
}

async function cargarRecepciones(pagina) {
  const tipo = document.getElementById("recepciones-tipo")?.value || "0";
  const q = (document.getElementById("recepciones-buscar")?.value || "").trim();
  const aviso = document.getElementById("recepciones-aviso");
  const body = document.getElementById("recepciones-body");
  const version = ++peticionRecepciones;
  document.querySelectorAll("[data-rec-tipo]").forEach(tab => tab.setAttribute("aria-selected", String(tab.dataset.recTipo === tipo)));
  body.setAttribute("aria-busy", "true");
  try {
    const params = new URLSearchParams({ tipo, pagina: String(pagina), q });
    const resp = await fetch("../controllers/recepciones_historial.php?" + params, { headers: { Accept: "application/json" }, credentials: "same-origin" });
    const datos = await resp.json();
    if (version !== peticionRecepciones) return;
    if (!resp.ok) throw new Error(datos.error || "No se pudieron cargar las recepciones.");
    body.innerHTML = datos.html;
    Object.assign(estadoRecepciones, { pagina: datos.pagina, paginas: datos.paginas, total: datos.total, por_pagina: datos.por_pagina });
    const totales = Object.fromEntries((datos.tabs || []).map(t => [String(t.id), t.total]));
    document.querySelectorAll("[data-rec-tipo]").forEach(tab => {
      const contador = tab.querySelector("[data-rec-count]");
      if (contador) contador.textContent = "(" + (tab.dataset.recTipo === "0" ? datos.todas : (totales[tab.dataset.recTipo] || 0)) + ")";
    });
    document.getElementById("recepciones-resumen").textContent = datos.total + " recepciones · " + datos.muestras + " muestras";
    document.getElementById("recepciones-vacio").hidden = datos.total > 0;
    aviso.textContent = "";
    pintarPaginadorRecepciones();
  } catch (error) {
    if (version === peticionRecepciones) aviso.textContent = error.message || "No se pudieron cargar las recepciones.";
  } finally {
    if (version === peticionRecepciones) body.removeAttribute("aria-busy");
  }
}

document.getElementById("analisis-buscar")?.addEventListener("input", filtrarAnalisisRecepcion);
document.getElementById("recepciones-buscar")?.addEventListener("input", () => {
  clearTimeout(temporizadorBusquedaRec);
  temporizadorBusquedaRec = setTimeout(() => cargarRecepciones(1), 300);
});
document.querySelectorAll("[data-rec-tipo]").forEach(tab => tab.addEventListener("click", () => {
  document.getElementById("recepciones-tipo").value = tab.dataset.recTipo;
  cargarRecepciones(1);
}));
[["recepciones-anterior", -1], ["recepciones-siguiente", 1]].forEach(([id, paso]) => {
  document.getElementById(id)?.addEventListener("click", async () => {
    await cargarRecepciones(estadoRecepciones.pagina + paso);
    document.getElementById("recepciones-titulo")?.scrollIntoView({ behavior: "smooth", block: "start" });
  });
});
document.querySelectorAll(".firma-canvas").forEach(canvas => {
  canvas.addEventListener("pointerup", actualizarAvanceRecepcion);
  canvas.addEventListener("pointercancel", actualizarAvanceRecepcion);
});
document.querySelectorAll("[data-clear-canvas]").forEach(button => button.addEventListener("click", actualizarAvanceRecepcion));
pintarPaginadorRecepciones();
actualizarAvanceRecepcion();
CengiRecepcion.init();
async function recuperarFirmasEdicion() {
  const id = document.getElementById("id_solicitud")?.value;
  if (!id) return;
  try {
    const datos = await CengiRecepcion.consultarBoleta(id);
    for (const [canvasId, firma] of [["canvas-ingreso", datos.firmaIngreso], ["canvas-recibe", datos.firmaRecibe]]) {
      if (!firma) continue;
      const canvas = document.getElementById(canvasId);
      const img = new Image();
      img.onload = () => {
        if (canvasTieneFirma(canvas)) return;
        const ctx = canvas.getContext("2d");
        ctx.save();
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        ctx.restore();
        actualizarVistaBoleta();
      };
      img.src = firma;
    }
  } catch (error) {
    document.getElementById("ticket-aviso").textContent = error.message;
  }
}
recuperarFirmasEdicion();
