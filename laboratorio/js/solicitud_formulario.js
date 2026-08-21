const SOLICITUDES_DB = readJsonData("solicitudes-db", []);
const CORRELATIVOS_DB = readJsonData("correlativos-db", []);
const PDF_LOGO_URL = "../../assets/Marca%20Cengica%C3%B1a/SinFondo_logo_cengicana_Vertical.png";
let solicitudSeleccionada = null;

const ANALISIS = readJsonData("analisis-catalogo", {});
if (false) {
const ANALISIS = {
  "suelos": {
    label: "Suelos",
    items: [
      { nombre: "Textura", tipo: "Físico" },
      { nombre: "Densidad aparente", tipo: "Físico" },
      { nombre: "Densidad real", tipo: "Físico" },
      { nombre: "Humedad gravimétrica", tipo: "Físico" },
      { nombre: "Porosidad total", tipo: "Físico" },
      { nombre: "pH", tipo: "Químico" },
      { nombre: "Materia orgánica", tipo: "Químico" },
      { nombre: "Nitrógeno total", tipo: "Químico" },
      { nombre: "Fósforo disponible", tipo: "Químico" },
      { nombre: "Potasio intercambiable", tipo: "Químico" },
      { nombre: "CIC (capacidad de intercambio catiónico)", tipo: "Químico" },
    ],
    metodo: "Los análisis de suelos incluyen pruebas físicas y químicas. Los análisis físicos se ejecutan conforme a protocolos ASTM D422 y métodos gravimétricos normalizados; los químicos siguen normas AOAC y métodos Walkley-Black, Kjeldahl y extracción con acetato de amonio.",
  },
  "suelo-fisico": {
    label: "Suelos Físico",
    items: [
      { nombre: "Textura" },
      { nombre: "Densidad aparente" },
      { nombre: "Densidad real" },
      { nombre: "Humedad gravimétrica" },
      { nombre: "Porosidad total" },
    ],
    metodo: "Los análisis físicos de suelo se ejecutan conforme a los protocolos ASTM D422 para textura y métodos gravimétricos normalizados para densidades y humedad. Cada muestra es identificada y trazada desde su recepción hasta la entrega de resultados, con controles de calidad dobles por lote.",
  },
  "suelo-quimico": {
    label: "Suelo Químico",
    items: [
      { nombre: "pH" },
      { nombre: "Materia orgánica" },
      { nombre: "Nitrógeno total" },
      { nombre: "Fósforo disponible" },
      { nombre: "Potasio intercambiable" },
      { nombre: "CIC (capacidad de intercambio catiónico)" },
    ],
    metodo: "Análisis químico bajo normas AOAC y métodos Walkley-Black, Kjeldahl y extracción con acetato de amonio. Los reactivos son de grado analítico certificado y el laboratorio opera con control de temperatura a 20 ± 2°C.",
  },
  "foliares": {
    label: "Foliares",
    items: [
      { nombre: "Nitrógeno foliar" },
      { nombre: "Fósforo foliar" },
      { nombre: "Potasio foliar" },
      { nombre: "Calcio y Magnesio" },
      { nombre: "Micronutrientes (Fe, Mn, Zn, Cu)" },
    ],
    metodo: "Las muestras foliares deben presentarse limpias, previamente secadas a 65°C por 48 horas y molidas a malla 40. Los análisis siguen los protocolos del Instituto Internacional de Nutrición de Plantas (IPNI) y la norma AOAC 965.09 para digestión de tejidos.",
  },
  "cana": {
    label: "Caña",
    items: [
      { nombre: "Brix (jugo)" },
      { nombre: "Pol (sacarosa)" },
      { nombre: "Pureza" },
      { nombre: "Fibra bruta" },
      { nombre: "Humedad del bagazo" },
      { nombre: "Jugo extraído (%)" },
    ],
    metodo: "Análisis de caña conforme a los métodos ICUMSA y las normas de la industria azucarera guatemalteca. Las muestras deben procesarse dentro de las 4 horas posteriores al corte para evitar la inversión enzimática de la sacarosa.",
  },
  "miel": {
    label: "Miel",
    items: [
      { nombre: "Humedad" },
      { nombre: "HMF (Hidroximetilfurfural)" },
      { nombre: "Actividad diastásica" },
      { nombre: "Sólidos solubles (°Brix)" },
      { nombre: "pH y acidez libre" },
    ],
    metodo: "Análisis de mieles bajo la norma CODEX STAN 12-1981 y métodos AOAC International. Se verifica el cumplimiento del Reglamento Técnico Centroamericano RTCA 67.04.40:07. Las muestras deben entregarse en frascos de vidrio ámbar sellados.",
  },
  "agua": {
    label: "Agua",
    items: [
      { nombre: "pH" },
      { nombre: "Conductividad eléctrica (CE)" },
      { nombre: "Sólidos totales disueltos (STD)" },
      { nombre: "Dureza total (CaCO3)" },
      { nombre: "Coliformes totales y fecales" },
      { nombre: "Nitratos / Nitritos" },
    ],
    metodo: "Análisis de agua para uso agrícola conforme a las normas COGUANOR NGO 29001 y métodos estándar APHA-AWWA-WEF (Standard Methods for the Examination of Water and Wastewater, 23ª edición). Las muestras deben recolectarse en frascos estériles y entregarse refrigeradas (4°C) en un máximo de 6 horas.",
  },
};
}

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
  if (!data || !body) return;

  setTipoFormulario(tipo);
  const items = Array.isArray(data.items) ? data.items : [];

  if (!items.length) {
    body.innerHTML = `
      <tr>
        <td colspan="3" class="center" style="padding:24px;color:#4a4d49">
          No hay análisis activos para este tipo de muestra.
        </td>
      </tr>
    `;
    document.getElementById("tipo-label-header").textContent = data.label || tipo;
    updateNumeroLaboratorio();
    syncSelectAllAnalisis();
    return;
  }

  body.innerHTML = items.map((item, i) => `
    <tr>
      <td class="name">${item.nombre}</td>
      <td class="center"><span class="analisis-tag">${item.tipo || data.label}</span></td>
      <td class="center check-cell">
        <input type="checkbox" name="analisis[]" value="${item.id_tipo}" id="chk-${tipo}-${i}" aria-label="Solicitar ${item.nombre}"/>
      </td>
    </tr>
  `).join("");
  document.getElementById("tipo-label-header").textContent = data.label || tipo;
  updateNumeroLaboratorio();
  syncSelectAllAnalisis();
}

function getAnalisisCheckboxes() {
  return Array.from(document.querySelectorAll('#analisis-body input[name="analisis[]"]'));
}

function syncSelectAllAnalisis() {
  const selectAll = document.getElementById('select-all-analisis');
  if (!selectAll) return;

  const checks = getAnalisisCheckboxes();
  const total = checks.length;
  const checked = checks.filter(input => input.checked).length;

  selectAll.disabled = total === 0;
  selectAll.checked = total > 0 && checked === total;
  selectAll.indeterminate = checked > 0 && checked < total;
}

function toggleSelectAllAnalisis(checked) {
  getAnalisisCheckboxes().forEach(input => {
    input.checked = checked;
  });
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
        syncSelectAllAnalisis();
      }
    });
  }
}

function getInicialTipo(tipo) {
  if (solicitudSeleccionada?.prefijo) {
    return solicitudSeleccionada.prefijo.toUpperCase();
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

function getSiguienteNumeroPorPrefijo(prefijo) {
  const correlativo = CORRELATIVOS_DB.find(item => String(item.prefijo || "").toUpperCase() === String(prefijo || "").toUpperCase());
  return correlativo ? parseInt(correlativo.ultimo_numero, 10) + 1 : 492;
}

function updateNumeroLaboratorio() {
  const tipoActivo = document.querySelector(".tipo-btn.active")?.dataset.tipo || "suelos";
  const fecha = document.getElementById("fecha_muestreo")?.value || "";
  const muestras = parseInt(document.getElementById("numero_muestras")?.value, 10);
  const inicioInput = document.getElementById("n_laboratorio_inicio");
  const finInput = document.getElementById("n_laboratorio_fin");
  const ocultoInput = document.getElementById("n_laboratorio");

  if (!inicioInput || !finInput || !ocultoInput) return;

  sincronizarFechasSolicitudVisuales(tipoActivo);

  const inicial = getInicialTipo(tipoActivo);
  const inicioGuardado = solicitudSeleccionada?.inicio_laboratorio ? parseInt(solicitudSeleccionada.inicio_laboratorio, 10) : null;
  const loteInicial = inicioGuardado || getSiguienteNumeroPorPrefijo(inicial);
  const mesAnio = getMesAnio(fecha);

  if (Number.isNaN(loteInicial) || !mesAnio || Number.isNaN(muestras) || muestras < 1) {
    inicioInput.value = "";
    finInput.value = "";
    ocultoInput.value = "";
    return;
  }

  const longitudLote = Math.max(3, String(loteInicial).length);
  const loteFinal = loteInicial + muestras - 1;
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

function calcularFechaEstimadaVisual(fechaIngresoTexto, tipo) {
  const fechaIngreso = parsearFechaVisual(fechaIngresoTexto);
  const diasCompromiso = obtenerDiasCompromisoVisual(tipo);

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
  ["numero_de_muestra", "lote", "fecha_muestreo", "numero_muestras"].forEach(id => {
    const input = document.getElementById(id);
    if (input) input.readOnly = readonly;
  });
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
  setCamposReadonly(true);
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
  ["lote", "fecha_muestreo", "numero_muestras"].forEach(id => {
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

  if (idSolicitud && solicitudSelect) {
    solicitudSelect.value = idSolicitud;
    aplicarSolicitudDb(idSolicitud);
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
    const row = input.closest("tr");
    const nombre = row?.querySelector(".name")?.textContent || input.getAttribute("aria-label") || input.value;
    return {
      nombre: limpiarTextoPdf(nombre.replace(/^Solicitar\s+/i, "")),
      tipo: limpiarTextoPdf(row?.querySelector(".analisis-tag")?.textContent || ""),
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
    tipo: getInputValue("#tipo-label-header"),
    lote: getInputValue("#lote"),
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
        lote: datos.lote,
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
  try {
    payload = await response.json();
  } catch {
    payload = {
      ok: false,
      message: "El servidor no devolvio una respuesta valida al enviar el correo.",
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

async function generarPdfSolicitud(options = {}) {
  const boton = document.getElementById("btn-generar-pdf");
  let pdfDescargado = false;
  const download = options.download !== false;

  if (!window.PDFLib) {
    alert("No se pudo cargar la libreria para generar PDF.");
    return;
  }

  if (!validarCorreosResponsables()) return;

  const datos = getDatosSolicitudPdf();

  try {
    if (boton) {
      boton.disabled = true;
      boton.title = "Generando PDF...";
    }

    const { PDFDocument, StandardFonts, rgb } = window.PDFLib;
    const pdfDoc = await PDFDocument.create();
    const regularFont = await pdfDoc.embedFont(StandardFonts.Helvetica);
    const boldFont = await pdfDoc.embedFont(StandardFonts.HelveticaBold);
    const logoCengicana = await cargarLogoPdf(pdfDoc);
    const pageSize = [595.28, 841.89];
    const margin = 44;
    const bottom = 46;
    const width = pageSize[0];
    const height = pageSize[1];
    const contentWidth = width - margin * 2;
    const green = rgb(0.23, 0.43, 0.07);
    const darkGreen = rgb(0.1, 0.2, 0.04);
    const softGreen = rgb(0.9, 0.96, 0.86);
    const borderGreen = rgb(0.68, 0.79, 0.53);
    const muted = rgb(0.34, 0.44, 0.27);
    let page = pdfDoc.addPage(pageSize);
    let y = height - margin;

    const addHeader = isContinuation => {
      const logoWidth = isContinuation ? 58 : 82;
      const logoX = margin;
      const logoY = y - (isContinuation ? 34 : 50);
      const textX = logoCengicana ? margin + logoWidth + 18 : margin;

      if (logoCengicana) {
        const logoHeight = logoWidth * (logoCengicana.height / logoCengicana.width);
        page.drawImage(logoCengicana, {
          x: logoX,
          y: logoY,
          width: logoWidth,
          height: logoHeight,
        });
      }

      page.drawText("Laboratorio Agroindustrial", {
        x: textX,
        y,
        size: isContinuation ? 12 : 18,
        font: boldFont,
        color: darkGreen,
      });

      page.drawText(isContinuation ? "Boleta de solicitud - continuacion" : "Boleta de solicitud de analisis", {
        x: textX,
        y: y - (isContinuation ? 16 : 22),
        size: isContinuation ? 9 : 11,
        font: regularFont,
        color: muted,
      });

      if (!isContinuation) {
        page.drawRectangle({
          x: width - margin - 78,
          y: y - 26,
          width: 78,
          height: 34,
          borderColor: borderGreen,
          borderWidth: 1,
          color: softGreen,
        });
        page.drawText("VF", { x: width - margin - 64, y: y - 6, size: 8, font: boldFont, color: muted });
        page.drawText("005", { x: width - margin - 64, y: y - 20, size: 12, font: boldFont, color: green });
      }

      y -= isContinuation ? 52 : 78;
    };

    const newPage = () => {
      page = pdfDoc.addPage(pageSize);
      y = height - margin;
      addHeader(true);
    };

    const ensureSpace = needed => {
      if (y - needed < bottom) newPage();
    };

    const drawSection = title => {
      ensureSpace(32);
      page.drawText(limpiarTextoPdf(title).toUpperCase(), {
        x: margin,
        y,
        size: 9,
        font: boldFont,
        color: green,
      });
      page.drawLine({
        start: { x: margin + 150, y: y + 3 },
        end: { x: width - margin, y: y + 3 },
        thickness: 0.8,
        color: borderGreen,
      });
      y -= 20;
    };

    const drawWrapped = (text, x, maxWidth, size = 10, font = regularFont, color = darkGreen, lineHeight = 14) => {
      const lines = wrapPdfText(text, font, size, maxWidth);
      lines.forEach(line => {
        ensureSpace(lineHeight + 2);
        if (line) page.drawText(line, { x, y, size, font, color });
        y -= lineHeight;
      });
    };

    const drawInfoCell = (label, value, x, topY, cellWidth) => {
      page.drawRectangle({
        x,
        y: topY - 38,
        width: cellWidth,
        height: 38,
        borderColor: borderGreen,
        borderWidth: 0.8,
      });
      page.drawText(limpiarTextoPdf(label).toUpperCase(), {
        x: x + 8,
        y: topY - 13,
        size: 7,
        font: boldFont,
        color: muted,
      });

      const valueLines = wrapPdfText(value || "-", regularFont, 9.5, cellWidth - 16).slice(0, 2);
      valueLines.forEach((line, index) => {
        page.drawText(line || "-", {
          x: x + 8,
          y: topY - 28 - index * 10,
          size: 9.5,
          font: regularFont,
          color: darkGreen,
        });
      });
    };

    const drawInfoRows = rows => {
      const gap = 12;
      const cellWidth = (contentWidth - gap) / 2;

      for (let i = 0; i < rows.length; i += 2) {
        ensureSpace(46);
        const topY = y;
        drawInfoCell(rows[i][0], rows[i][1], margin, topY, cellWidth);
        if (rows[i + 1]) drawInfoCell(rows[i + 1][0], rows[i + 1][1], margin + cellWidth + gap, topY, cellWidth);
        y -= 46;
      }
    };

    const drawSignature = async (title, name, email, dataUrl, x, topY, boxWidth) => {
      page.drawText(limpiarTextoPdf(title).toUpperCase(), {
        x,
        y: topY,
        size: 8,
        font: boldFont,
        color: muted,
      });
      page.drawText(limpiarTextoPdf(name || " "), {
        x,
        y: topY - 15,
        size: 9.5,
        font: regularFont,
        color: darkGreen,
      });
      if (email) {
        page.drawText(limpiarTextoPdf(email), {
          x,
          y: topY - 29,
          size: 8.5,
          font: regularFont,
          color: muted,
        });
      }

      if (dataUrl) {
        const signature = await pdfDoc.embedPng(dataUrl);
        const maxImageWidth = boxWidth;
        const maxImageHeight = 54;
        const scale = Math.min(maxImageWidth / signature.width, maxImageHeight / signature.height);
        const imageWidth = signature.width * scale;
        const imageHeight = signature.height * scale;
        page.drawImage(signature, {
          x,
          y: topY - 78,
          width: imageWidth,
          height: imageHeight,
        });
      }

      page.drawLine({
        start: { x, y: topY - 86 },
        end: { x: x + boxWidth, y: topY - 86 },
        thickness: 0.8,
        color: borderGreen,
      });
    };

    addHeader(false);

    drawSection("Datos del muestreo");
    drawInfoRows([
      ["Tipo de muestra", datos.tipo],
      ["Numero de lote", datos.lote],
      ["Fecha de muestreo", datos.fechaMuestreo],
      ["Fecha de ingreso", datos.fechaIngreso],
      ["Fecha estimada", datos.fechaEstimada],
      ["Numero de muestras", datos.numeroMuestras],
      ["Laboratorio inicio", datos.numeroLaboratorioInicio],
      ["Laboratorio fin", datos.numeroLaboratorioFin],
    ]);

    drawSection("Analisis solicitados");
    if (datos.analisis.length) {
      datos.analisis.forEach(item => {
        ensureSpace(18);
        page.drawText("- ", { x: margin, y, size: 10, font: boldFont, color: green });
        drawWrapped(`${item.nombre}${item.tipo ? ` (${item.tipo})` : ""}`, margin + 14, contentWidth - 14, 10, regularFont, darkGreen, 14);
      });
    } else {
      drawWrapped("Sin analisis seleccionados.", margin, contentWidth, 10, regularFont, muted);
    }

    drawSection("Observaciones");
    drawWrapped(datos.observaciones || "Sin observaciones.", margin, contentWidth, 10, regularFont, darkGreen, 14);

    drawSection("Responsables y firmas");
    ensureSpace(108);
    const signatureTop = y;
    const signatureWidth = (contentWidth - 24) / 2;
    await drawSignature("Ingresado por", datos.ingresadoPor, datos.correoIngresadoPor, datos.firmaIngreso, margin, signatureTop, signatureWidth);
    await drawSignature("Recibido por", datos.recibidoPor, datos.correoRecibidoPor, datos.firmaRecibe, margin + signatureWidth + 24, signatureTop, signatureWidth);
    y -= 110;

    ensureSpace(24);
    page.drawLine({
      start: { x: margin, y: bottom + 20 },
      end: { x: width - margin, y: bottom + 20 },
      thickness: 0.6,
      color: borderGreen,
    });
    page.drawText("Generado por TecnoBoris v2.1", {
      x: margin,
      y: bottom + 5,
      size: 8,
      font: regularFont,
      color: muted,
    });

    const pdfBytes = await pdfDoc.save();
    const fileName = buildPdfFileName(datos);
    if (download) {
      descargarPdf(pdfBytes, fileName);
      pdfDescargado = true;
    }

    const envio = await enviarPdfPorCorreo(pdfBytes, fileName, datos);
    if (download) alert(envio.message);
    return envio;
  } catch (error) {
    console.error(error);
    const mensaje = pdfDescargado
      ? `El PDF se genero y se descargo, pero no se pudo enviar por correo: ${error.message}`
      : `No se pudo generar el PDF: ${error.message}`;
    alert(mensaje);
  } finally {
    if (boton) {
      boton.disabled = false;
      boton.title = "Generar PDF";
    }
  }
}

async function finalizarSolicitud(event) {
  if (!validarCorreosResponsables()) return;

  const btn = document.getElementById('btn-finalizar-solicitud');
  if (btn) btn.disabled = true;

  try {
    const envio = await generarPdfSolicitud({ download: false });
    if (envio && envio.sent === false) {
      alert(envio.message || 'No se envio el correo. Se continuara con el guardado de la solicitud.');
    }
    syncFirmaInputs();
    document.getElementById('solicitud-form').submit();
  } catch (err) {
    alert('No se pudo generar o enviar el PDF: ' + (err.message || err));
  } finally {
    if (btn) btn.disabled = false;
  }
}

function initPdfButton() {
  const boton = document.getElementById("btn-generar-pdf");
  if (boton) boton.addEventListener("click", generarPdfSolicitud);
}

function closeFabMenu() {
  const dropdown = document.getElementById('fab-actions');
  const toggle = document.getElementById('fab-actions-toggle');
  const menu = document.getElementById('fab-actions-menu');

  if (!dropdown || !toggle || !menu) return;

  dropdown.classList.remove('open');
  toggle.setAttribute('aria-expanded', 'false');
  menu.hidden = true;
}

function openFabMenu() {
  const dropdown = document.getElementById('fab-actions');
  const toggle = document.getElementById('fab-actions-toggle');
  const menu = document.getElementById('fab-actions-menu');

  if (!dropdown || !toggle || !menu) return;

  dropdown.classList.add('open');
  toggle.setAttribute('aria-expanded', 'true');
  menu.hidden = false;
}

function initFabMenu() {
  const dropdown = document.getElementById('fab-actions');
  const toggle = document.getElementById('fab-actions-toggle');
  const menu = document.getElementById('fab-actions-menu');

  if (!dropdown || !toggle || !menu) return;

  toggle.addEventListener('click', event => {
    event.stopPropagation();
    if (menu.hidden) {
      openFabMenu();
    } else {
      closeFabMenu();
    }
  });

  menu.addEventListener('click', event => {
    if (event.target.closest('.fab-menu-item')) {
      closeFabMenu();
    }
  });

  document.addEventListener('click', event => {
    if (!dropdown.contains(event.target)) {
      closeFabMenu();
    }
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      closeFabMenu();
    }
  });
}

function initFirmaSubmitSync() {
  const form = document.getElementById("solicitud-form");
  if (form) form.addEventListener("submit", syncFirmaInputs);
}

function escaparTicket(valor) {
  return String(valor ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function actualizarVistaBoleta() {
  const lote = getInputValue("#lote");
  const cliente = getInputValue("#institucion");
  const campo = getInputValue("#numero_de_muestra");
  const tipo = getInputValue("#tipo-label-header");
  const fecha = getInputValue("#fecha_muestreo");
  const cantidad = Math.max(0, parseInt(getInputValue("#numero_muestras"), 10) || 0);
  const codigoInicio = getInputValue("#n_laboratorio_inicio");
  const codigoFin = getInputValue("#n_laboratorio_fin");
  const analisis = getAnalisisSeleccionados().map(item => item.nombre).filter(Boolean);

  const ticketLote = document.getElementById("ticket-lote");
  const ticketCliente = document.getElementById("ticket-cliente");
  const ticketCampo = document.getElementById("ticket-campo");
  const ticketMeta = document.getElementById("ticket-meta");
  const ticketMuestras = document.getElementById("ticket-muestras");

  if (ticketLote) ticketLote.textContent = lote ? `LOTE ${lote}` : "LOTE —";
  if (ticketCliente) ticketCliente.textContent = cliente ? cliente : "Cliente —";
  if (ticketCampo) ticketCampo.textContent = campo ? `Lote de campo ${campo}` : "Lote de campo —";
  if (ticketMeta) {
    ticketMeta.textContent = fecha
      ? `${cantidad || 0} muestra(s) · Muestreo ${fecha} · Ingreso ${getInputValue("#fecha_ingreso") || "—"}`
      : "Completa los datos para ver aqui el resumen de la recepcion.";
  }

  if (!ticketMuestras) return;
  if (!cantidad || !fecha) {
    ticketMuestras.innerHTML = '<tr><td colspan="3" class="ticket-empty">Aun no se ha completado la muestra.</td></tr>';
    return;
  }

  const codigos = codigoInicio && codigoFin && codigoInicio !== codigoFin
    ? `${codigoInicio} — ${codigoFin}`
    : (codigoInicio || "Pendiente");
  const resumenAnalisis = analisis.length ? analisis.join(", ") : "Sin analisis seleccionados";
  ticketMuestras.innerHTML = `<tr><td>${escaparTicket(codigos)}</td><td>${escaparTicket(tipo || "Muestra")}</td><td>${escaparTicket(resumenAnalisis)}</td></tr>`;
}

function initVistaBoleta() {
  const form = document.getElementById("solicitud-form");
  if (form) {
    form.addEventListener("input", actualizarVistaBoleta);
    form.addEventListener("change", actualizarVistaBoleta);
  }

  const tipoLabel = document.getElementById("tipo-label-header");
  if (tipoLabel && window.MutationObserver) {
    new MutationObserver(actualizarVistaBoleta).observe(tipoLabel, { childList: true, characterData: true, subtree: true });
  }

  const proxyPdf = document.getElementById("ticket-generar-pdf");
  if (proxyPdf) {
    proxyPdf.addEventListener("click", () => document.getElementById("btn-generar-pdf")?.click());
  }

  const proxyFinalizar = document.getElementById("ticket-finalizar");
  if (proxyFinalizar) {
    proxyFinalizar.addEventListener("click", () => document.getElementById("btn-finalizar-solicitud")?.click());
  }

  actualizarVistaBoleta();
}

initSeleccionAnalisis();
initFabMenu();
initTipoButtons();
initLaboratorioInputs();
const solicitudSelect = initSolicitudSelect();
sincronizarFechasSolicitudVisuales();
initTipoDesdeQuery();
initSolicitudDesdeQuery(solicitudSelect);
initFirmas();
initPdfButton();
initFirmaSubmitSync();
initVistaBoleta();
function initFinalizarButton() {
  const boton = document.getElementById('btn-finalizar-solicitud');
  if (boton) boton.addEventListener('click', finalizarSolicitud);
}
initFinalizarButton();


