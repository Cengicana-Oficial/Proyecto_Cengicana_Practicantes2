/* Ejecutar: node laboratorio/tests/recepcion_workflow.test.js */
const fs = require("node:fs");
const path = require("node:path");
const assert = (condition, message) => { if (!condition) throw new Error(message); };
const base = path.resolve(__dirname, "..");
const source = name => fs.readFileSync(path.join(base, name), "utf8");

async function run() {
  const config = JSON.parse(source("config/recepcion_catalogo_v3.json"));
  const nodes = new Map();
  const document = {
    getElementById(id) {
      if (!nodes.has(id)) nodes.set(id, { innerHTML: "", textContent: "" });
      return nodes.get(id);
    },
  };
  let checks = [];
  const window = {};
  new Function("window", "document", "getAnalisisCheckboxes", source("js/recepcion_workflow.js"))(window, document, () => checks);
  const api = window.CengiRecepcion;
  for (const [tipo, definition] of Object.entries(config)) {
    const data = {
      grupos: definition.grupos.map(g => g.nombre),
      paquetes: definition.paquetes,
      items: definition.items.map((a, i) => ({ ...a, id_tipo: i + 1, grupo: definition.grupos.find(g => !g.analisis || g.analisis.includes(a.nombre)).nombre })),
    };
    api.renderAnalisis(data, tipo);
    assert((nodes.get("analisis-body").innerHTML.match(/name="analisis\[\]"/g) || []).length === data.items.length, tipo + ": faltan análisis");
    for (const item of data.items.filter(a => a.dependencias.length)) {
      checks = data.items.map(a => ({ value: String(a.id_tipo), checked: a.id_tipo === item.id_tipo }));
      api.dependenciasSeleccionadas();
      for (const dep of item.dependencias) {
        const target = data.items.find(a => a.nombre === dep.nombre);
        assert(checks.find(c => c.value === String(target.id_tipo)).checked, "Falta dependencia: " + dep.nombre);
      }
    }
  }
  const tipoIncompleto = {
    grupos: ["Físicos"], paquetes: [],
    items: [{ id_tipo: 1, nombre: "Textura", grupo: "Físicos", dependencias: [{ nombre: "Humedad residual", motivo: "corrección" }] }],
  };
  api.renderAnalisis(tipoIncompleto, "suelos");
  checks = [{ value: "1", checked: true }];
  api.dependenciasSeleccionadas();
  assert(!checks[0].checked, "Debe impedir Textura sin Humedad residual disponible");

  const datos = {
    idSolicitud: 7, lote: "S-12", tipo: "Suelos", numeroMuestras: 3,
    numeroLaboratorioInicio: "S-001-10-26", numeroLaboratorioFin: "S-003-10-26",
    institucion: '<script>alert("x")</script>', observaciones: "Línea 1\nLínea 2",
    analisis: [{ nombre: "Textura" }], firmaIngreso: "javascript:alert(1)",
  };
  const html = api.htmlBoleta(datos);
  assert(!html.includes("<script>") && !html.includes('src="javascript:'), "La boleta debe escapar contenido y validar firmas");
  for (const text of ["CG-R-SLA-001", "Datos del muestreo", "Textura", "Responsables y firmas", "Línea 1", "S-003-10-26"]) {
    assert(html.includes(text), "Boleta incompleta: " + text);
  }
  assert(api.htmlBoleta(datos, true).includes("cambios sin guardar"), "Debe distinguir el borrador del registro");
  const modulePdf = { exports: {} };
  new Function("module", source("js/solicitud_pdf_layout.js"))(modulePdf);
  const calls = [], pages = [];
  const font = { widthOfTextAtSize: (text, size) => text.length * size * 0.51 };
  const pdfDoc = {
    embedFont: async () => font,
    embedPng: async () => ({ width: 200, height: 70 }),
    addPage: () => {
      const page = { drawText: (text, opts) => calls.push({ text, ...opts }), drawRectangle() {}, drawLine() {}, drawImage() {} };
      pages.push(page);
      return page;
    },
    getPages: () => pages, save: async () => new Uint8Array([1]),
  };
  await modulePdf.exports.crearPdfSolicitud({
    pdfDoc, PDFLib: { StandardFonts: { Helvetica: "regular", HelveticaBold: "bold" }, rgb: (...values) => values },
    datos: { ...datos, institucion: "Cliente de prueba", observaciones: "Observación extensa de control ".repeat(220), analisis: config.suelos.items },
  });
  assert(pages.length > 1, "Las observaciones largas deben paginar");
  assert(calls.every(call => call.y >= 0 && call.y <= 842), "Hay texto fuera de la página");
  assert(calls.some(call => call.text === "CG-R-SLA-001"), "Formato incorrecto de PDF");

  // Editar debe funcionar aunque el antiguo selector de solicitudes no exista.
  const main = source("js/solicitud_formulario.js");
  const editFn = main.slice(main.indexOf("function initSolicitudDesdeQuery("), main.indexOf("function makeDrawable("));
  let loaded = null;
  const editDocument = { getElementById: () => ({}), querySelectorAll: () => [] };
  const initEdit = new Function("window", "document", "aplicarSolicitudDb", editFn + "; return initSolicitudDesdeQuery;")(
    { location: { search: "?id_solicitud=42" } }, editDocument, id => { loaded = id; });
  initEdit(null);
  assert(loaded === "42", "Editar no carga la solicitud sin el selector antiguo");
  console.log("OK: catálogo, dependencias, escape HTML, boleta, paginación PDF y carga de edición.");
}
run().catch(error => { console.error(error); process.exitCode = 1; });
