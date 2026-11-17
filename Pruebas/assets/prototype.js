
const asesorEmails = {};

const AREAS = ["Riegos", "Malezas y Madurantes", "Recuperación de Sacarosa", "Variedades", "Estación Experimental", "Capacitación y Transferencia de Tecnología"];
const ROLE_KEYS = ["admin", "profesional"];

let usersData = [];


const permissionsMatrix = [];

const data = [];

const monthNames = ["Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"];




/* ---------- BASE DE CONTACTOS DE TRANSFERENCIA (consolidada) ---------- */
let transferContactsDB = [];

// Siembra inicial silenciosa: consolida los destinos de transferencia ya existentes en los datos de ejemplo


let currentId = null;
let currentRole = "admin";
let currentArea = "Todas";
let currentUserId = 1;
let currentUserName = "";
const roleLabels = { admin: "Admin", profesional: "Profesional" };
function canFillSurvey() { return hasPermission("Llenar encuesta por llamada telefónica", currentRole); }
function canManageServices() { return hasPermission("Registrar nuevo servicio", currentRole); }
function isAreaRestricted() { return currentRole !== "admin" && currentArea && currentArea !== "Todas"; }




let modalRating = 0;
let previewRating = 0;
let modalTargetId = null;
let chartEstado = null;
let chartIngenio = null;
let chartArea = null;
let chartTransferDestino = null;
let chartTransferArea = null;
let chartTopClientes = null;
let chartTopAsesores = null;
let lastTransferRows = [];
let lastFilteredList = data;
let lastReportRows = [];
let lastAreaRows = [];

/* ---------- CSV EXPORT ---------- */

function exportListCSV() {
  const headers = ["ID","Cliente","Institucion","Area","Asesor","Actividad","Lugar","Fecha","DiasAbierto","Estado","Encuesta","Puntuacion"];
  const rows = lastFilteredList.map(r => [r.id, r.cliente, r.institucion, r.area, r.asesor, r.desc, r.lugar, r.fecha, r.diasAbierto ?? "", r.estado, r.encuesta, r.puntuacion ?? ""]);
  exportCSV(rows, headers, "registro_encuestas.csv");
}
function exportReportCSV() {
  const headers = ["Ingenio","Servicios","Enviadas","Respondidas","PorcentajeRespuesta","Promedio"];
  const rows = lastReportRows.map(r => [r.ing, r.total, r.env, r.resp, r.pctIng + "%", r.avgIng]);
  exportCSV(rows, headers, "resumen_por_ingenio.csv");
}
function exportAreaCSV() {
  if (!lastAreaRows.length) { alert("No hay datos para exportar."); return; }
  const ingenios = Object.keys(lastAreaRows[0].perIngenio);
  const headers = ["Area", ...ingenios, "Total", "PorcentajeDelTotal"];
  const rows = lastAreaRows.map(r => [r.area, ...ingenios.map(i => r.perIngenio[i]), r.total, r.pct + "%"]);
  exportCSV(rows, headers, "resumen_por_area.csv");
}
function exportCommentsCSV() {
  const ingenio = document.getElementById("dashIngenio").value;
  const mes = document.getElementById("dashMes").value;
  const filtered = data.filter(r => (!ingenio || r.institucion === ingenio) && (!mes || r.mesLabel === mes) && r.encuesta === "respondida");
  const headers = ["ID","Asesor","Cliente","Institucion","Area","Fecha","Puntuacion","Comentario","PersonaQueRespondio","EstadoSeguimiento"];
  const rows = filtered.map(r => [r.id, r.asesor, r.cliente, r.institucion, r.area, r.fecha, r.puntuacion, r.comentario, r.personaRespondio, segLabels[r.seguimiento.estado]]);
  exportCSV(rows, headers, "comentarios_y_calificaciones.csv");
}


async function exportDashboardPDF() {
  if (!permissionsMatrix[8]?.[currentRole]) return notice('No tienes permiso para exportar.', true);
  if (!window.jspdf) { alert("No se pudo cargar el generador de PDF."); return; }

  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ unit: "mm", format: "a4" });
  const ingenio = document.getElementById("dashIngenio").value || "Todos";
  const mes = document.getElementById("dashMes").value || "Todos";
  const marginX = 15;
  let y = 20;

  doc.setFontSize(16); doc.setFont(undefined, "bold");
  doc.text("Reporte Mensual de Encuestas - Cengicaña", marginX, y); y += 8;
  doc.setFontSize(10); doc.setFont(undefined, "normal");
  doc.text("Ingenio: " + ingenio + "    Mes: " + mes, marginX, y); y += 5;
  doc.text("Generado: " + new Date().toLocaleString("es-GT") + "    Por: " + currentUserName + " (" + roleLabels[currentRole] + ")", marginX, y); y += 10;

  doc.setFontSize(12); doc.setFont(undefined, "bold");
  doc.text("Resumen general", marginX, y); y += 6;
  doc.setFontSize(10); doc.setFont(undefined, "normal");
  doc.text("Servicios: " + document.getElementById("dashTotal").textContent, marginX, y); y += 5;
  doc.text("Encuestas enviadas: " + document.getElementById("dashEnviadas").textContent, marginX, y); y += 5;
  doc.text("% de respuesta: " + document.getElementById("dashPct").textContent, marginX, y); y += 5;
  doc.text("Promedio: " + document.getElementById("dashProm").textContent, marginX, y); y += 10;

  function addChartImage(canvasId, title) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    let imgData;
    try { imgData = canvas.toDataURL("image/png", 1.0); } catch (e) { return; }
    doc.setFontSize(12); doc.setFont(undefined, "bold");
    if (y > 250) { doc.addPage(); y = 20; }
    doc.text(title, marginX, y); y += 5;
    const imgWidth = Math.min(180, 105 * canvas.width / canvas.height);
    const imgHeight = imgWidth * canvas.height / canvas.width;
    if (y + imgHeight > 280) { doc.addPage(); y = 20; }
    doc.addImage(imgData, "PNG", marginX, y, imgWidth, imgHeight);
    y += imgHeight + 10;
  }

  addChartImage("chartEstado", "Estado de la encuesta");
  addChartImage("chartIngenio", "Promedio de calificación por ingenio");
  doc.addPage(); y = 20;
  addChartImage("chartArea", "Servicios por área, coloreado por ingenio");
  doc.addPage(); y = 20;
  addChartImage("chartTransferDestino", "Transferencias por ingenio destino");
  addChartImage("chartTransferArea", "Transferencias por área de origen");

  if (y > 250) { doc.addPage(); y = 20; }
  doc.setFontSize(12); doc.setFont(undefined, "bold");
  doc.text("Resumen por ingenio", marginX, y); y += 6;
  doc.setFontSize(9); doc.setFont(undefined, "normal");
  lastReportRows.forEach(r => {
    if (y > 280) { doc.addPage(); y = 20; }
    const lines = doc.splitTextToSize(r.ing + ": " + r.total + " servicios, " + r.env + " enviadas, " + r.resp + " respondidas, " + r.pctIng + "% respuesta, promedio " + r.avgIng, 180);
    if (y + lines.length * 5 > 280) { doc.addPage(); y = 20; }
    doc.text(lines, marginX, y); y += lines.length * 5 + 2;
  });
  y += 6;
  if (y > 260) { doc.addPage(); y = 20; }
  doc.setFontSize(12); doc.setFont(undefined, "bold");
  doc.text("Resumen por área", marginX, y); y += 6;
  doc.setFontSize(9); doc.setFont(undefined, "normal");
  lastAreaRows.forEach(r => {
    if (y > 280) { doc.addPage(); y = 20; }
    const detalle = Object.entries(r.perIngenio).map(([k, v]) => k + ": " + v).join(", ");
    const lines = doc.splitTextToSize(r.area + " (Total " + r.total + ", " + r.pct + "%): " + detalle, 180);
    if (y + lines.length * 5 > 280) { doc.addPage(); y = 20; }
    doc.text(lines, marginX, y); y += lines.length * 5 + 2;
  });

  const blob = doc.output("blob");
  try {
    downloadBlob(blob, "dashboard_encuestas.pdf");
  } catch (e) {
    if (e && e.code !== "declined") alert("No se pudo exportar el PDF (" + (e && e.code || "error") + ").");
  }
}

/* ---------- NAV ---------- */
function showView(name) {
  document.getElementById("listView").style.display = name === "list" ? "block" : "none";
  document.getElementById("detailView").style.display = "none";
  document.getElementById("contactsView").style.display = name === "contacts" ? "block" : "none";
  document.getElementById("dashboardView").style.display = name === "dashboard" ? "block" : "none";
  document.getElementById("newServiceView").style.display = name === "newservice" ? "block" : "none";
  document.getElementById("usersView").style.display = name === "users" ? "block" : "none";
  document.getElementById("tabList").classList.toggle("active", name === "list");
  document.getElementById("tabContacts").classList.toggle("active", name === "contacts");
  document.getElementById("tabDashboard").classList.toggle("active", name === "dashboard");
  document.getElementById("tabUsers").classList.toggle("active", name === "users");
  if (name === "contacts") renderContacts();
  if (name === "dashboard") { initDashboardFilters(); renderDashboard(); }
  if (name === "users") renderUsersView();
  window.scrollTo(0, 0);
}

/* ---------- LIST ---------- */
function pillEstado(estado) {
  return estado === "Finalizado"
    ? '<span class="pill pill-finalizado">Finalizado</span>'
    : '<span class="pill pill-abierto">Abierto</span>';
}
function pillEncuesta(row) {
  if (row.encuesta === "respondida") return '<span class="pill-si">Sí</span>';
  if (row.encuesta === "pendiente") return '<span class="pill-pend">Pendiente</span>';
  return '<span class="pill-no">No</span>';
}
function puntuacionCell(row) {
  if (row.encuesta === "respondida") return '<span class="stars-mini">★ ' + row.puntuacion + '</span>';
  return '<span class="na">N/A</span>';
}
function diasAbiertoCell(row) {
  if (row.diasAbierto === null) return '<span class="na">-</span>';
  const clase = row.diasAbierto > 60 ? "dias-abierto alerta" : "dias-abierto";
  return '<span class="' + clase + '">' + row.diasAbierto + '</span>';
}

function renderTable(filtered) {
  lastFilteredList = filtered;
  const body = document.getElementById("tableBody");
  body.innerHTML = filtered.length ? filtered.map(row => `
    <tr>
      <td><a class="id-link" href="detalle_servicio.php?id=${encodeURIComponent(row.id)}">${escapeHtml(row.id)}</a></td>
      <td><div class="cliente-inst">${escapeHtml(row.cliente)}</div><div class="cliente-sub">${escapeHtml(row.institucion)}</div></td>
      <td><div class="area-txt">${escapeHtml(row.area)}</div><div class="asesor-sub">Asesor: ${escapeHtml(row.asesor)}</div></td>
      <td class="txt-sm">${escapeHtml(row.desc)}</td>
      <td class="txt-sm">${escapeHtml(row.lugar)}</td>
      <td>${escapeHtml(row.fecha)}</td>
      <td>${diasAbiertoCell(row)}</td>
      <td>${pillEstado(row.estado)}</td>
      <td>${pillEncuesta(row)}</td>
      <td>${puntuacionCell(row)}</td>
      <td>
        <div class="actions-cell">
          <button class="icon-btn ver" title="Ver detalle" onclick="openDetail(decodeURIComponent('${encodeURIComponent(row.id).replace(/'/g, "%27")}'))">&#128065;</button>
          <button class="icon-btn preview" title="Vista previa de la encuesta" onclick="openPreviewModal(decodeURIComponent('${encodeURIComponent(row.id).replace(/'/g, "%27")}'))">&#128279;</button>
          <button class="icon-btn track" title="Ver track de acciones" onclick="openTrackModal(decodeURIComponent('${encodeURIComponent(row.id).replace(/'/g, "%27")}'))">&#128203;</button>
          ${row.encuesta === "sin-generar" && hasPermission("Generar / copiar enlace de encuesta", currentRole) ? `<button class="icon-btn send" title="Enviar encuesta por correo" onclick="enviarEncuestaRapido(decodeURIComponent('${encodeURIComponent(row.id).replace(/'/g, "%27")}'))">&#9993;</button>` : ""}
          ${row.encuesta !== "respondida" && canFillSurvey() ? `<button class="responder-btn" title="Llenar por auxiliar de capacitación" onclick="openResponderModal(decodeURIComponent('${encodeURIComponent(row.id).replace(/'/g, "%27")}'))">Llenar</button>` : ""}
        </div>
      </td>
    </tr>
  `).join("") : '<tr><td colspan="11" class="empty-state">No hay servicios que coincidan con los filtros.</td></tr>';
}

function updateStats(rows) {
  document.getElementById("statTotal").textContent = rows.length;
  document.getElementById("statPend").textContent = rows.filter(r => r.encuesta === "pendiente").length;
  const scored = rows.filter(r => r.encuesta === "respondida");
  const avg = scored.length ? (scored.reduce((a, r) => a + r.puntuacion, 0) / scored.length).toFixed(1) : "-";
  document.getElementById("statProm").textContent = avg;
}

function applyFilters() {
  const est = document.getElementById("fEstado").value;
  const enc = document.getElementById("fEncuesta").value;
  const q = document.getElementById("fSearch").value.trim().toLowerCase();
  const filtered = data.filter(r =>
    (!est || r.estado === est) &&
    (!enc || r.encuesta === enc) &&
    (!isAreaRestricted() || r.area === currentArea) &&
    (!q || r.id.toLowerCase().includes(q) || r.cliente.toLowerCase().includes(q) || r.institucion.toLowerCase().includes(q) || r.asesor.toLowerCase().includes(q) || r.area.toLowerCase().includes(q))
  );
  renderTable(filtered);
  updateStats(filtered);
}
function clearFilters() {
  document.getElementById("fEstado").value = "";
  document.getElementById("fEncuesta").value = "";
  document.getElementById("fSearch").value = "";
  applyFilters();
}

function showList() { showView("list"); }

function openDetail(id) {
  const row = data.find(r => r.id === id);
  if (!row) return;
  if (isAreaRestricted() && row.area !== currentArea) {
    alert("Este servicio pertenece al área \"" + row.area + "\", fuera de tu área asignada (" + currentArea + ").");
    return;
  }
  currentId = id;
  document.getElementById("dTitle").textContent = "Detalle del Servicio Técnico · " + row.id;
  document.getElementById("dArea").textContent = row.area;
  document.getElementById("dTipo").textContent = row.tipo;
  document.getElementById("dAsesor").textContent = row.asesor;
  document.getElementById("dEstado").innerHTML = pillEstado(row.estado);
  document.getElementById("dDesc").textContent = row.desc;
  document.getElementById("dFecha").textContent = row.fecha;
  document.getElementById("dLugar").textContent = row.lugar;
  document.getElementById("dDias").textContent = row.diasAbierto === null ? "-" : row.diasAbierto + " días";
  document.getElementById("dCliente").textContent = row.institucion + " | " + row.cliente;
  document.getElementById("dCelular").textContent = row.celular;
  document.getElementById("dEmail").textContent = row.email;
  refreshSurveyPanel(row);
  refreshTransferPanel(row);
  refreshSeguimientoPanel(row);
  refreshTrackPanel(row);

  document.getElementById("listView").style.display = "none";
  document.getElementById("contactsView").style.display = "none";
  document.getElementById("dashboardView").style.display = "none";
  document.getElementById("newServiceView").style.display = "none";
  document.getElementById("usersView").style.display = "none";
  document.getElementById("detailView").style.display = "block";
  window.scrollTo(0, 0);
}

function refreshSurveyPanel(row) {
  const labels = {
    "sin-generar": "Sin enlace generado",
    "pendiente": "Pendiente de respuesta",
    "respondida": "Respondida"
  };
  document.getElementById("dSurveyStatusLabel").textContent = labels[row.encuesta];
  const showResponder = row.encuesta !== "respondida" && canFillSurvey();
  document.getElementById("dResponderBtn").style.display = showResponder ? "inline-flex" : "none";
  document.getElementById("jefeNoteDetail").style.display = (currentRole === "profesional" && row.encuesta !== "respondida") ? "block" : "none";

  document.getElementById("detailStateSinGenerar").style.display = row.encuesta === "sin-generar" ? "block" : "none";
  document.getElementById("detailStatePendiente").style.display = row.encuesta === "pendiente" ? "block" : "none";
  document.getElementById("detailStateRespondida").style.display = row.encuesta === "respondida" ? "block" : "none";

  if (row.encuesta === "pendiente") {
    
    const link = surveyURL(row);
    document.getElementById("linkInput").value = link;
    const subject = encodeURIComponent("Encuesta de satisfacción - Cengicaña");
    const bodyText = encodeURIComponent("Le compartimos el enlace de la encuesta de satisfacción del servicio " + row.id + ": " + link);
    document.getElementById("mailBtn").href = "mailto:" + row.email + "?subject=" + subject + "&body=" + bodyText;
  }
  if (row.encuesta === "respondida") {
    document.getElementById("dStars").textContent = "★".repeat(row.puntuacion) + "☆".repeat(5 - row.puntuacion);
    document.getElementById("dComment").textContent = row.comentario;
    document.getElementById("dPersona").textContent = row.personaRespondio;
    document.getElementById("dCargoInput").value = row.cargoRespondente || "";
  }
}

function refreshTransferPanel(row) {
  document.getElementById("dEnvioEncuesta").innerHTML = row.envioEncuesta === "Si"
    ? '<span class="pill-si">Sí</span>' : '<span class="pill-no">No</span>';
  document.getElementById("dTransferencia").innerHTML = row.transferencia === "Si"
    ? '<span class="pill-si">Sí</span>' : '<span class="pill-no">No</span>';

  const showBlock = row.transferencia === "Si";
  document.getElementById("transferSiBlock").style.display = showBlock ? "block" : "none";
  document.getElementById("transferNoBlock").style.display = showBlock ? "none" : "block";
  if (showBlock) {
    document.getElementById("dFechaTransfer").textContent = row.fechaTransferencia;
    document.getElementById("dLugarTransfer").textContent = row.lugarTransferencia;
    document.getElementById("dEvidencia").textContent = row.evidencia;
    document.getElementById("dDescTransfer").textContent = row.descripcionTransferencia;
  }
}

const segLabels = { "pendiente": "Pendiente de seguimiento", "contactado": "Asesor contactado", "resuelto": "Seguimiento resuelto", "no-requiere": "No requiere seguimiento" };

function refreshSeguimientoPanel(row) {
  const card = document.getElementById("seguimientoCard");
  if (row.encuesta !== "respondida") { card.style.display = "none"; return; }
  card.style.display = "block";

  const seg = row.seguimiento;
  const needsFollowUp = row.puntuacion <= 3;
  const canHandle = hasPermission("Notificar al asesor y clasificar seguimiento", currentRole);

  document.getElementById("segEstadoPill").innerHTML =
    '<span class="pill pill-seg-' + seg.estado + '">' + segLabels[seg.estado] + '</span>';

  const selectedEstado = seg.estado === "no-requiere" ? "pendiente" : seg.estado;
  segEstadoSeleccionado = selectedEstado;
  document.querySelectorAll("#segEstadoPills .seg-pill").forEach(b => {
    b.classList.toggle("selected", b.dataset.v === selectedEstado);
    b.disabled = !canHandle;
  });
  document.getElementById("segNotaInput").disabled = !canHandle;

  const notifBtn = document.getElementById("notificarBtn");
  notifBtn.style.display = canHandle ? "inline-flex" : "none";
  notifBtn.textContent = needsFollowUp ? "Notificar al asesor (seguimiento requerido)" : "Compartir comentario con el asesor";
  notifBtn.className = needsFollowUp ? "btn btn-primary btn-sm" : "btn btn-outline btn-sm";

  document.getElementById("segControls").style.display = canHandle ? "block" : "none";
  document.getElementById("segNoAccessNote").style.display = canHandle ? "none" : "block";

  document.getElementById("notificadoNote").style.display = seg.notificado ? "block" : "none";
  if (seg.notificado) {
    document.getElementById("notificadoNote").textContent = "Se notificó a " + row.asesor + " sobre esta calificación y comentario.";
  }

  const hist = document.getElementById("segHistorial");
  if (!seg.notas.length) {
    hist.innerHTML = '<p class="seg-empty">Aún no hay registros de seguimiento para este caso.</p>';
  } else {
    hist.innerHTML = seg.notas.slice().reverse().map(n => `
      <div class="seg-entry">
        <span class="seg-date">${escapeHtml(n.fecha)} · ${segLabels[n.estado]}</span>
        ${escapeHtml(n.texto)}
      </div>
    `).join("");
  }
}

let segEstadoSeleccionado = "pendiente";
function selectSegEstado(v) {
  segEstadoSeleccionado = v;
  document.querySelectorAll("#segEstadoPills .seg-pill").forEach(b => b.classList.toggle("selected", b.dataset.v === v));
}





function refreshTrackPanel(row) {
  const el = document.getElementById("trackHistorial");
  if (!row.track.length) {
    el.innerHTML = '<p class="seg-empty">Sin registros todavía.</p>';
    return;
  }
  el.innerHTML = row.track.slice().reverse().map(t => `
    <div class="track-entry">
      <span class="track-date">${escapeHtml(t.fecha)} · ${escapeHtml(t.hora)} — <strong>${escapeHtml(t.rol)}</strong></span>
      <span class="track-accion">${escapeHtml(t.accion)}</span>
      <span class="track-detalle">${escapeHtml(t.detalle)}</span>
    </div>
  `).join("");
}







function copiarLink() {
  const input = document.getElementById("linkInput");
  const fb = document.getElementById("copyFeedback");
  try {
    navigator.clipboard.writeText(input.value).then(() => {
      fb.classList.add("show");
      setTimeout(() => fb.classList.remove("show"), 1500);
    });
  } catch (e) {
    input.select();
  }
}

/* ---------- TRACK MODAL (desde el listado) ---------- */
let trackModalId = null;
function openTrackModal(id) {
  const row = data.find(r => r.id === id);
  if (!row) return;
  trackModalId = id;
  document.getElementById("trackModalTitle").textContent = "Track de acciones · " + row.id;
  document.getElementById("trackModalSub").textContent = row.cliente + " (" + row.institucion + ") · " + row.area;
  const el = document.getElementById("trackModalHistorial");
  if (!row.track.length) {
    el.innerHTML = '<p class="seg-empty">Sin registros todavía.</p>';
  } else {
    el.innerHTML = row.track.slice().reverse().map(t => `
      <div class="track-entry">
        <span class="track-date">${escapeHtml(t.fecha)} · ${escapeHtml(t.hora)} — <strong>${escapeHtml(t.rol)}</strong></span>
        <span class="track-accion">${escapeHtml(t.accion)}</span>
        <span class="track-detalle">${escapeHtml(t.detalle)}</span>
      </div>
    `).join("");
  }
  document.getElementById("trackModalOverlay").classList.add("show");
}
function closeTrackModal() {
  document.getElementById("trackModalOverlay").classList.remove("show");
}

/* ---------- PREVIEW MODAL ---------- */
function openPreviewModal(id) {
  const row = data.find(r => r.id === id);
  document.getElementById("pvCodigo").textContent = row.id;
  document.getElementById("pvDesc").textContent = row.desc;
  document.getElementById("pvAsesor").textContent = row.asesor;
  document.getElementById("pvFecha").textContent = row.fecha;
  document.getElementById("pvIngenio").textContent = row.institucion;
  document.getElementById("pvNombre").value = "";
  document.getElementById("pvCargo").value = "";
  document.getElementById("pvComentario").value = "";
  
  document.getElementById("pvLinkInput").value = surveyURL(row);
  previewRating = 0;
  document.querySelectorAll("#previewStarPicker button").forEach(b => b.classList.remove("filled"));
  document.getElementById("previewModalOverlay").classList.add("show");
}
function copiarLinkPreview() {
  const input = document.getElementById("pvLinkInput");
  const fb = document.getElementById("pvCopyFeedback");
  try {
    navigator.clipboard.writeText(input.value).then(() => {
      fb.classList.add("show");
      setTimeout(() => fb.classList.remove("show"), 1500);
    });
  } catch (e) {
    input.select();
  }
}
function closePreviewModal() {
  document.getElementById("previewModalOverlay").classList.remove("show");
}
function ratePreview(v) {
  previewRating = v;
  document.querySelectorAll("#previewStarPicker button").forEach(b => {
    b.classList.toggle("filled", parseInt(b.dataset.v) <= v);
  });
}

/* ---------- MODAL ---------- */
function openResponderModal(id) {
  if (!canFillSurvey()) { notice("Solo el Admin puede responder encuestas desde el módulo.", true); return; }
  modalTargetId = id;
  modalRating = 0;
  document.getElementById("mNombre").value = "";
  document.getElementById("mCargo").value = "";
  document.getElementById("mComentario").value = "";
  document.getElementById("mNombreErr").classList.remove("show");
  document.getElementById("mStarsErr").classList.remove("show");
  document.querySelectorAll("#modalStarPicker button").forEach(b => b.classList.remove("filled"));
  document.getElementById("modalSub").textContent = "Servicio " + id + " · La persona no llenó la encuesta en línea, se le contactó por teléfono.";
  document.getElementById("modalOverlay").classList.add("show");
}
function closeResponderModal() {
  document.getElementById("modalOverlay").classList.remove("show");
}
function rateModal(v) {
  modalRating = v;
  document.querySelectorAll("#modalStarPicker button").forEach(b => {
    b.classList.toggle("filled", parseInt(b.dataset.v) <= v);
  });
  document.getElementById("mStarsErr").classList.remove("show");
}


/* ---------- CONTACTS ---------- */
/* ---------- INTEGRACIÓN CON SISTEMA EXTERNO DE CONTACTOS ---------- */
// Simula la base de datos de otro sistema (CRM/directorio institucional) ya existente.



let syncRegistry = {};
let syncIdCounter = 0;
let currentSyncId = null;

function registerSyncCandidate(nombre, empresa, telefono, correo, cargo, servicios) {
  const id = "sync" + (syncIdCounter++);
  syncRegistry[id] = {
    nombre, empresa, telefono: telefono || "-", correo: correo || "-",
    cargo: cargo || "", servicios: servicios || "-"
  };
  return id;
}



function openSyncModal(syncId) {
  const c = syncRegistry[syncId];
  if (!c) return;
  currentSyncId = syncId;
  document.getElementById("syncNombre").textContent = c.nombre;
  document.getElementById("syncEmpresa").textContent = c.empresa || "-";
  document.getElementById("syncServicios").textContent = c.servicios;
  document.getElementById("syncTelefono").textContent = c.telefono;
  document.getElementById("syncCorreo").textContent = c.correo;
  const cargoEl = document.getElementById("syncCargo");
  if (c.cargo) {
    cargoEl.textContent = c.cargo;
    cargoEl.style.fontStyle = "normal";
    cargoEl.style.color = "var(--text-p)";
  } else {
    cargoEl.textContent = "Pendiente de definir";
    cargoEl.style.fontStyle = "italic";
    cargoEl.style.color = "var(--text-s)";
  }
  document.getElementById("syncModalOverlay").classList.add("show");
}
function closeSyncModal() {
  document.getElementById("syncModalOverlay").classList.remove("show");
}






/* ---------- DASHBOARD ---------- */
function initDashboardFilters() {
  const ingenioSel = document.getElementById("dashIngenio");
  if (ingenioSel.options.length <= 1) {
    const ingenios = [...new Set(data.map(r => r.institucion))];
    ingenios.forEach(i => {
      const opt = document.createElement("option");
      opt.value = i; opt.textContent = i;
      ingenioSel.appendChild(opt);
    });
  }
  const mesSel = document.getElementById("dashMes");
  if (mesSel.options.length <= 1) {
    const meses = [...new Set(data.map(r => r.mesLabel))];
    meses.forEach(m => {
      const opt = document.createElement("option");
      opt.value = m; opt.textContent = m;
      mesSel.appendChild(opt);
    });
  }
}
function clearDashboardFilters() {
  document.getElementById("dashIngenio").value = "";
  document.getElementById("dashMes").value = "";
  renderDashboard();
}

function renderDashboard() {
  const ingenio = document.getElementById("dashIngenio").value;
  const mes = document.getElementById("dashMes").value;

  const filtered = data.filter(r => (!ingenio || r.institucion === ingenio) && (!mes || r.mesLabel === mes));
  const enviadas = filtered.filter(r => r.envioEncuesta === "Si").length;
  const respondidas = filtered.filter(r => r.encuesta === "respondida").length;
  const pendientes = filtered.filter(r => r.encuesta === "pendiente").length;
  const sinGenerar = filtered.filter(r => r.encuesta === "sin-generar").length;
  const pct = enviadas ? Math.round((respondidas / enviadas) * 100) : 0;
  const scored = filtered.filter(r => r.encuesta === "respondida");
  const avg = scored.length ? (scored.reduce((a, r) => a + r.puntuacion, 0) / scored.length).toFixed(1) : "-";

  document.getElementById("dashTotal").textContent = filtered.length;
  document.getElementById("dashEnviadas").textContent = enviadas;
  document.getElementById("dashPct").textContent = pct + "%";
  document.getElementById("dashProm").textContent = avg;

  const pctCard = document.getElementById("dashPct").closest(".stat-card");
  pctCard.style.borderLeftColor = pct >= 70 ? "#73BC25" : (pct >= 40 ? "#FFCC00" : "#FF6B00");

  const dark = matchMedia("(prefers-color-scheme: dark)").matches;
  const textColor = dark ? "#ECEDEE" : "#22292F";

  const ctx1 = document.getElementById("chartEstado").getContext("2d");
  if (chartEstado) chartEstado.destroy();
  chartEstado = new Chart(ctx1, {
    type: "doughnut",
    data: {
      labels: ["Respondida", "Pendiente", "Sin generar"],
      datasets: [{ data: [respondidas, pendientes, sinGenerar], backgroundColor: ["#73BC25", "#FFCC00", "#CED2D5"], borderWidth: 0 }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: "bottom", labels: { color: textColor, font: { size: 11 } } } }
    }
  });

  const porIngenioFiltradoPorMes = filtered;
  const ingeniosSet = [...new Set(porIngenioFiltradoPorMes.map(r => r.institucion))];
  const avgPorIngenio = ingeniosSet.map(ing => {
    const rows = porIngenioFiltradoPorMes.filter(r => r.institucion === ing && r.encuesta === "respondida");
    return rows.length ? (rows.reduce((a, r) => a + r.puntuacion, 0) / rows.length) : 0;
  });

  const ctx2 = document.getElementById("chartIngenio").getContext("2d");
  if (chartIngenio) chartIngenio.destroy();
  chartIngenio = new Chart(ctx2, {
    type: "bar",
    data: {
      labels: ingeniosSet,
      datasets: [{ label: "Promedio", data: avgPorIngenio, backgroundColor: "#FF6B00", borderRadius: 6, maxBarThickness: 46 }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      scales: {
        y: { beginAtZero: true, max: 5, ticks: { color: textColor, font: { size: 11 } }, grid: { color: dark ? "#2C2F34" : "#E4E7E9" } },
        x: { ticks: { color: textColor, font: { size: 11 } }, grid: { display: false } }
      },
      plugins: { legend: { display: false } }
    }
  });

  const reportRows = ingeniosSet.map(ing => {
    const rows = porIngenioFiltradoPorMes.filter(r => r.institucion === ing);
    const env = rows.filter(r => r.envioEncuesta === "Si").length;
    const resp = rows.filter(r => r.encuesta === "respondida").length;
    const pctIng = env ? Math.round((resp / env) * 100) : 0;
    const scoredIng = rows.filter(r => r.encuesta === "respondida");
    const avgIng = scoredIng.length ? (scoredIng.reduce((a, r) => a + r.puntuacion, 0) / scoredIng.length).toFixed(1) : "-";
    return { ing, total: rows.length, env, resp, pctIng, avgIng };
  });
  lastReportRows = reportRows;
  document.getElementById("reportBody").innerHTML = reportRows.map(r => `
    <tr>
      <td><strong>${escapeHtml(r.ing)}</strong></td>
      <td>${escapeHtml(r.total)}</td>
      <td>${escapeHtml(r.env)}</td>
      <td>${escapeHtml(r.resp)}</td>
      <td>${escapeHtml(r.pctIng)}%</td>
      <td>${escapeHtml(r.avgIng)}</td>
    </tr>
  `).join("");

  const areasSet = [...new Set(porIngenioFiltradoPorMes.map(r => r.area))];
  const ingenioPalette = ["#73BC25", "#FF6B00", "#FFCC00", "#3E6B12", "#A3D300", "#854F0B", "#185FA5"];
  const areaDatasets = ingeniosSet.map((ing, i) => ({
    label: ing,
    data: areasSet.map(a => porIngenioFiltradoPorMes.filter(r => r.area === a && r.institucion === ing).length),
    backgroundColor: ingenioPalette[i % ingenioPalette.length],
    borderRadius: 4,
    maxBarThickness: 34
  }));
  const totalAreaServicios = porIngenioFiltradoPorMes.length;

  const ctx3 = document.getElementById("chartArea").getContext("2d");
  if (chartArea) chartArea.destroy();
  chartArea = new Chart(ctx3, {
    type: "bar",
    data: { labels: areasSet, datasets: areaDatasets },
    options: {
      indexAxis: "y",
      responsive: true, maintainAspectRatio: false,
      scales: {
        x: { stacked: true, beginAtZero: true, ticks: { color: textColor, font: { size: 11 }, precision: 0 }, grid: { color: dark ? "#2C2F34" : "#E4E7E9" } },
        y: { stacked: true, ticks: { color: textColor, font: { size: 10.5 } }, grid: { display: false } }
      },
      plugins: { legend: { position: "bottom", labels: { color: textColor, font: { size: 10.5 }, boxWidth: 12 } } }
    }
  });

  lastAreaRows = areasSet.map(a => {
    const perIngenio = {};
    ingeniosSet.forEach(ing => { perIngenio[ing] = porIngenioFiltradoPorMes.filter(r => r.area === a && r.institucion === ing).length; });
    const total = Object.values(perIngenio).reduce((x, y) => x + y, 0);
    return { area: a, perIngenio, total, pct: totalAreaServicios ? Math.round((total / totalAreaServicios) * 100) : 0 };
  });

  const areaTableHead = document.getElementById("areaReportHead");
  areaTableHead.innerHTML = "<th>Área</th>" + ingeniosSet.map(ing => "<th>" + escapeHtml(ing) + "</th>").join("") + "<th>Total</th><th>% del total</th>";
  document.getElementById("areaReportBody").innerHTML = lastAreaRows.map(r => `
    <tr>
      <td><strong>${escapeHtml(r.area)}</strong></td>
      ${ingeniosSet.map(ing => "<td>" + r.perIngenio[ing] + "</td>").join("")}
      <td><strong>${escapeHtml(r.total)}</strong></td>
      <td>${escapeHtml(r.pct)}%</td>
    </tr>
  `).join("");

  /* ---- Transferencias ---- */
  const transferRows = porIngenioFiltradoPorMes.filter(r => r.transferencia === "Si");
  const destinosSet = [...new Set(transferRows.map(r => r.lugarTransferencia).filter(d => d && d !== "-"))];
  document.getElementById("dashTransferTotal").textContent = transferRows.length;
  document.getElementById("dashTransferPct").textContent = (porIngenioFiltradoPorMes.length ? Math.round((transferRows.length / porIngenioFiltradoPorMes.length) * 100) : 0) + "%";
  document.getElementById("dashTransferDestinos").textContent = destinosSet.length;

  const countPorDestino = destinosSet.map(d => transferRows.filter(r => r.lugarTransferencia === d).length);
  const ctxTD = document.getElementById("chartTransferDestino").getContext("2d");
  if (chartTransferDestino) chartTransferDestino.destroy();
  chartTransferDestino = new Chart(ctxTD, {
    type: "bar",
    data: { labels: destinosSet.length ? destinosSet : ["Sin datos"], datasets: [{ label: "Transferencias", data: destinosSet.length ? countPorDestino : [0], backgroundColor: "#3E6B12", borderRadius: 6, maxBarThickness: 40 }] },
    options: {
      indexAxis: "y", responsive: true, maintainAspectRatio: false,
      scales: {
        x: { beginAtZero: true, ticks: { color: textColor, font: { size: 11 }, precision: 0 }, grid: { color: dark ? "#2C2F34" : "#E4E7E9" } },
        y: { ticks: { color: textColor, font: { size: 10.5 } }, grid: { display: false } }
      },
      plugins: { legend: { display: false } }
    }
  });

  const areasConTransfer = [...new Set(transferRows.map(r => r.area))];
  const countPorAreaTransfer = areasConTransfer.map(a => transferRows.filter(r => r.area === a).length);
  const ctxTA = document.getElementById("chartTransferArea").getContext("2d");
  if (chartTransferArea) chartTransferArea.destroy();
  chartTransferArea = new Chart(ctxTA, {
    type: "doughnut",
    data: { labels: areasConTransfer.length ? areasConTransfer : ["Sin datos"], datasets: [{ data: areasConTransfer.length ? countPorAreaTransfer : [1], backgroundColor: ["#73BC25", "#FF6B00", "#FFCC00", "#3E6B12", "#A3D300", "#854F0B"], borderWidth: 0 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: "bottom", labels: { color: textColor, font: { size: 10.5 }, boxWidth: 12 } } } }
  });

  lastTransferRows = transferRows.map(r => ({ id: r.id, cliente: r.cliente, institucion: r.institucion, destino: r.lugarTransferencia, area: r.area, fecha: r.fechaTransferencia }));
  document.getElementById("transferDetailBody").innerHTML = lastTransferRows.length
    ? lastTransferRows.map(r => `
      <tr>
        <td class="id-link">${escapeHtml(r.id)}</td>
        <td>${escapeHtml(r.cliente)}</td>
        <td>${escapeHtml(r.institucion)}</td>
        <td>${escapeHtml(r.destino)}</td>
        <td>${escapeHtml(r.area)}</td>
        <td>${escapeHtml(r.fecha)}</td>
      </tr>
    `).join("")
    : '<tr><td colspan="6"><p class="seg-empty" style="margin:0;">No hay transferencias registradas con este filtro.</p></td></tr>';

  /* ---- Top clientes y asesores (acumulado, según filtro de mes) ---- */
  const clientesCount = {};
  porIngenioFiltradoPorMes.forEach(r => { clientesCount[r.cliente] = (clientesCount[r.cliente] || 0) + 1; });
  const topClientes = Object.entries(clientesCount).sort((a, b) => b[1] - a[1]).slice(0, 8);
  const ctxTC = document.getElementById("chartTopClientes").getContext("2d");
  if (chartTopClientes) chartTopClientes.destroy();
  chartTopClientes = new Chart(ctxTC, {
    type: "bar",
    data: { labels: topClientes.map(c => c[0]), datasets: [{ label: "Servicios", data: topClientes.map(c => c[1]), backgroundColor: "#FF6B00", borderRadius: 6, maxBarThickness: 30 }] },
    options: {
      indexAxis: "y", responsive: true, maintainAspectRatio: false,
      scales: {
        x: { beginAtZero: true, ticks: { color: textColor, font: { size: 11 }, precision: 0 }, grid: { color: dark ? "#2C2F34" : "#E4E7E9" } },
        y: { ticks: { color: textColor, font: { size: 10.5 } }, grid: { display: false } }
      },
      plugins: { legend: { display: false } }
    }
  });

  const asesoresCount = {};
  porIngenioFiltradoPorMes.forEach(r => { if (r.envioEncuesta === "Si") asesoresCount[r.asesor] = (asesoresCount[r.asesor] || 0) + 1; });
  const topAsesores = Object.entries(asesoresCount).sort((a, b) => b[1] - a[1]).slice(0, 8);
  const ctxTAs = document.getElementById("chartTopAsesores").getContext("2d");
  if (chartTopAsesores) chartTopAsesores.destroy();
  chartTopAsesores = new Chart(ctxTAs, {
    type: "bar",
    data: { labels: topAsesores.map(c => c[0]), datasets: [{ label: "Encuestas enviadas", data: topAsesores.map(c => c[1]), backgroundColor: "#73BC25", borderRadius: 6, maxBarThickness: 30 }] },
    options: {
      indexAxis: "y", responsive: true, maintainAspectRatio: false,
      scales: {
        x: { beginAtZero: true, ticks: { color: textColor, font: { size: 11 }, precision: 0 }, grid: { color: dark ? "#2C2F34" : "#E4E7E9" } },
        y: { ticks: { color: textColor, font: { size: 10.5 } }, grid: { display: false } }
      },
      plugins: { legend: { display: false } }
    }
  });
}

function exportTransferCSV() {
  const headers = ["Servicio", "Cliente", "InstitucionOrigen", "Destino", "Area", "Fecha"];
  const rows = lastTransferRows.map(r => [r.id, r.cliente, r.institucion, r.destino, r.area, r.fecha]);
  exportCSV(rows, headers, "detalle_transferencias.csv");
}

/* ---------- NEW SERVICE ---------- */
function openNewService() {
  document.getElementById("nsInstitucion").value = "";
  document.getElementById("nsInstitucionOtra").style.display = "none";
  document.getElementById("nsInstitucionOtra").value = "";
  document.getElementById("nsCliente").value = "";
  document.getElementById("nsEmail").value = "";
  document.getElementById("nsCelular").value = "";
  const hoy = new Date().toISOString().slice(0, 10);
  document.getElementById("nsFechaSolicitud").value = hoy;
  document.getElementById("nsArea").value = "";
  document.getElementById("nsArea").disabled = false;
  if (currentRole === "profesional" && currentArea && currentArea !== "Todas" && AREAS.includes(currentArea)) {
    document.getElementById("nsArea").value = currentArea;
    document.getElementById("nsArea").disabled = true;
  }
  document.getElementById("nsAsesor").value = "";
  document.getElementById("nsEmailProfesional").value = "";
  document.getElementById("nsTipo").value = "";
  document.getElementById("nsEstado").value = "Abierto";
  document.getElementById("nsFechaCierre").value = "";
  document.getElementById("nsDescripcion").value = "";
  document.getElementById("nsLugar").value = "";
  document.getElementById("nsMedio").value = "";
  document.getElementById("nsParticipantes").value = "0";
  document.getElementById("nsEvidenciaLabel").textContent = "Seleccionar archivo · sin adjunto";
  setToggle("nsEnvio", "si");
  setToggle("nsTransfer", "no");

  document.getElementById("newServiceForm").style.display = canManageServices() ? "block" : "none";
  document.getElementById("newServiceBlocked").style.display = canManageServices() ? "none" : "block";
  document.getElementById("blockedRoleLabel").textContent = roleLabels[currentRole];

  showView("newservice");
}

function setToggle(group, value) {
  const siBtn = document.getElementById(group + "Si");
  const noBtn = document.getElementById(group + "No");
  siBtn.classList.toggle("active", value === "si");
  noBtn.classList.toggle("active", value === "no");
  siBtn.dataset.value = value;
  if (group === "nsTransfer") {
    document.getElementById("nsTransferFields").style.display = value === "si" ? "block" : "none";
  }
}

function autofillAsesorEmail() {
  const asesor = document.getElementById("nsAsesor").value;
  document.getElementById("nsEmailProfesional").value = asesorEmails[asesor] || "";
}

function updateEvidenciaLabel() {
  const file = document.getElementById("nsEvidenciaFile").files[0];
  document.getElementById("nsEvidenciaLabel").textContent = file ? file.name : "Seleccionar archivo · sin adjunto";
}


function toDDMMYYYY(isoDate) {
  if (!isoDate) return "-";
  const [y, m, d] = isoDate.split("-");
  return d + "/" + m + "/" + y;
}



/* ---------- USERS & PERMISSIONS ---------- */
function renderUsersView() {
  const isAdmin = hasPermission("Gestionar usuarios y permisos", currentRole);
  document.getElementById("usersContent").style.display = isAdmin ? "block" : "none";
  document.getElementById("usersBlocked").style.display = isAdmin ? "none" : "block";
  document.getElementById("blockedRoleLabelUsers").textContent = roleLabels[currentRole];
  renderPermMatrix();
  if (!isAdmin) return;
  renderUsersTable();
}

function renderPermMatrix() {
  const cell = (p, i, roleKey) => {
    const ok = p[roleKey];
    return `<td><span class="${ok ? 'perm-check' : 'perm-cross'}" aria-label="${ok ? 'Permitido' : 'No permitido'}">${ok ? '&#10003;' : '—'}</span></td>`;
  };
  document.getElementById("permMatrixBody").innerHTML = permissionsMatrix.map((p, i) => `
    <tr>
      <td>${p.accion}</td>
      ${ROLE_KEYS.map(rk => cell(p, i, rk)).join("")}
    </tr>
  `).join("");
}

function hasPermission(accion, rol) {
  const row = permissionsMatrix.find(p => p.accion === accion);
  return row ? !!row[rol] : false;
}



function refreshAfterPermissionChange() {
  applyFilters();
  updateExportButtonsVisibility();
  if (document.getElementById("detailView").style.display === "block" && currentId) {
    const row = data.find(r => r.id === currentId);
    if (row) refreshSurveyPanel(row);
  }
  if (document.getElementById("newServiceView").style.display === "block") {
    document.getElementById("newServiceForm").style.display = canManageServices() ? "block" : "none";
    document.getElementById("newServiceBlocked").style.display = canManageServices() ? "none" : "block";
    document.getElementById("blockedRoleLabel").textContent = roleLabels[currentRole];
  }
  const isAdmin = hasPermission("Gestionar usuarios y permisos", currentRole);
  document.getElementById("usersContent").style.display = isAdmin ? "block" : "none";
  document.getElementById("usersBlocked").style.display = isAdmin ? "none" : "block";
}

function updateExportButtonsVisibility() {
  const canExport = hasPermission("Exportar reportes (CSV / PDF)", currentRole);
  ["exportListBtn", "exportReportBtn", "exportAreaBtn", "exportFullReportBtn", "exportCommentsBtn", "exportPdfBtn", "exportTransferBtn"].forEach(id => {
    const b = document.getElementById(id);
    if (b) b.style.display = canExport ? "" : "none";
  });
}








