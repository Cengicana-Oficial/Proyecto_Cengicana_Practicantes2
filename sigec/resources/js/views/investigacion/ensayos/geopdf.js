/**
 * Utilidades del croquis georreferenciado (estilo Avenza Maps).
 *
 * - leerGeoPdf(): extrae el georreferenciado de un GeoPDF (ISO 32000 / Adobe:
 *   /VP -> /Measure /Subtype /GEO con /GPTS lat-lon y /LPTS), que es lo que
 *   exportan Avenza Maps, QGIS, ArcGIS y Global Mapper. Devuelve los puntos de
 *   control normalizados (0..1, origen arriba-izquierda) sobre la pagina.
 * - renderPagina(): rasteriza la pagina con pdf.js para mostrarla en Leaflet.
 * - crearTransformacion(): pixel <-> lat/lng (homografia con 4 puntos, afin
 *   por minimos cuadrados con 3 o mas).
 *
 * Se carga bajo demanda (import dinamico) para no pesar en el resto de SIGEC.
 */
import * as pdfjsNs from 'pdfjs-dist/legacy/build/pdf';
import { PDFDocument, PDFName, PDFArray, PDFDict, PDFNumber } from 'pdf-lib';

// UMD de pdf.js: webpack expone los exports directo; Node los deja en .default.
const pdfjsLib = pdfjsNs.GlobalWorkerOptions ? pdfjsNs : pdfjsNs.default;

const base = (document.querySelector('meta[name="app-base"]') || {}).content || '';
pdfjsLib.GlobalWorkerOptions.workerSrc = `${base.replace(/\/+$/, '')}/dist/js/pdf.worker.min.js`;

function numeros(arr) {
  if (!(arr instanceof PDFArray)) return null;
  const out = [];
  for (let i = 0; i < arr.size(); i++) {
    const n = arr.lookup(i);
    if (!(n instanceof PDFNumber)) return null;
    out.push(n.asNumber());
  }
  return out;
}

/** Georreferenciado de cada pagina del GeoPDF: [{pagina, puntosPdf: [{x, y, lat, lng}]}]. */
async function extraerVP(buffer) {
  const doc = await PDFDocument.load(buffer, { ignoreEncryption: true, updateMetadata: false });
  const res = [];
  doc.getPages().forEach((page, idx) => {
    const vp = page.node.lookup(PDFName.of('VP'));
    if (!(vp instanceof PDFArray)) return;
    let mejor = null;
    for (let i = 0; i < vp.size(); i++) {
      const d = vp.lookup(i);
      if (!(d instanceof PDFDict)) continue;
      const bbox = numeros(d.lookup(PDFName.of('BBox')));
      const measure = d.lookup(PDFName.of('Measure'));
      if (!bbox || !(measure instanceof PDFDict)) continue;
      const sub = measure.lookup(PDFName.of('Subtype'));
      if (sub && sub.toString() !== '/GEO') continue;
      const gpts = numeros(measure.lookup(PDFName.of('GPTS')));
      if (!gpts || gpts.length < 6) continue;
      const lpts = numeros(measure.lookup(PDFName.of('LPTS')))
        || numeros(measure.lookup(PDFName.of('Bounds')))
        || [0, 0, 0, 1, 1, 1, 1, 0];
      const [x1, y1, x2, y2] = [Math.min(bbox[0], bbox[2]), Math.min(bbox[1], bbox[3]), Math.max(bbox[0], bbox[2]), Math.max(bbox[1], bbox[3])];
      const area = (x2 - x1) * (y2 - y1);
      const puntos = [];
      for (let k = 0; k * 2 + 1 < gpts.length && k * 2 + 1 < lpts.length; k++) {
        puntos.push({
          x: x1 + lpts[k * 2] * (x2 - x1),
          y: y1 + lpts[k * 2 + 1] * (y2 - y1),
          lat: gpts[k * 2],
          lng: gpts[k * 2 + 1],
        });
      }
      // Un GeoPDF puede traer varios viewports (mapa, minimapa...): se usa el mayor.
      if (puntos.length >= 3 && (!mejor || area > mejor.area)) mejor = { area, puntos };
    }
    if (mejor) res.push({ pagina: idx + 1, puntosPdf: mejor.puntos });
  });
  return res;
}

/**
 * Lee un PDF: numero de paginas y, si es GeoPDF, el georreferenciado de la
 * primera pagina georreferenciada con puntos normalizados sobre la pagina tal
 * como la dibuja pdf.js (considera CropBox y /Rotate).
 */
export async function leerGeoPdf(buffer) {
  const pdf = await pdfjsLib.getDocument({ data: buffer.slice(0) }).promise;
  let geo = [];
  try {
    geo = await extraerVP(buffer.slice(0));
  } catch (e) {
    geo = [];
  }
  const elegido = geo[0];
  if (!elegido) return { paginas: pdf.numPages, pagina: 1, georef: null };

  const page = await pdf.getPage(elegido.pagina);
  const vp = page.getViewport({ scale: 1 });
  const puntos = elegido.puntosPdf.map((p) => {
    const [vx, vy] = vp.convertToViewportPoint(p.x, p.y);
    return { x: vx / vp.width, y: vy / vp.height, lat: p.lat, lng: p.lng };
  });
  return { paginas: pdf.numPages, pagina: elegido.pagina, georef: { metodo: 'geopdf', puntos } };
}

/** Rasteriza la pagina a una imagen (blob URL) de lado mayor ~maxLado px. */
export async function renderPagina(buffer, pagina, maxLado = 2600) {
  // pdf.js transfiere el buffer al worker: se pasa una copia.
  const pdf = await pdfjsLib.getDocument({ data: buffer.slice(0) }).promise;
  const page = await pdf.getPage(Math.min(Math.max(1, pagina || 1), pdf.numPages));
  const v1 = page.getViewport({ scale: 1 });
  const escala = maxLado / Math.max(v1.width, v1.height);
  const vp = page.getViewport({ scale: escala });
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(vp.width);
  canvas.height = Math.round(vp.height);
  await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
  const blob = await new Promise((ok) => canvas.toBlob(ok, 'image/jpeg', 0.9));
  return { url: URL.createObjectURL(blob), ancho: canvas.width, alto: canvas.height };
}

/* ------------------- Deteccion de parcelas en el croquis ------------------- */

const mult = (m, n) => [
  m[0] * n[0] + m[2] * n[1], m[1] * n[0] + m[3] * n[1],
  m[0] * n[2] + m[2] * n[3], m[1] * n[2] + m[3] * n[3],
  m[0] * n[4] + m[2] * n[5] + m[4], m[1] * n[4] + m[3] * n[5] + m[5],
];
const aplicarM = (m, x, y) => [m[0] * x + m[2] * y + m[4], m[1] * x + m[3] * y + m[5]];

function areaPoligono(pts) {
  let a = 0;
  for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) a += (pts[j][0] + pts[i][0]) * (pts[j][1] - pts[i][1]);
  return Math.abs(a / 2);
}

function contiene(pts, x, y) {
  let dentro = false;
  for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
    const [xi, yi] = pts[i]; const [xj, yj] = pts[j];
    if ((yi > y) !== (yj > y) && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) dentro = !dentro;
  }
  return dentro;
}

/** Poligonos cerrados (rectangulos y trazos de 3-8 lados) del contenido de la pagina, en coordenadas de usuario PDF. */
async function poligonosPagina(page) {
  const { OPS } = pdfjsLib;
  const ops = await page.getOperatorList();
  const polys = [];
  let ctm = [1, 0, 0, 1, 0, 0];
  const pila = [];
  for (let i = 0; i < ops.fnArray.length; i++) {
    const fn = ops.fnArray[i];
    const args = ops.argsArray[i];
    if (fn === OPS.save) pila.push(ctm);
    else if (fn === OPS.restore) ctm = pila.pop() || [1, 0, 0, 1, 0, 0];
    else if (fn === OPS.transform) ctm = mult(ctm, args);
    else if (fn === OPS.paintFormXObjectBegin) { pila.push(ctm); if (args[0]) ctm = mult(ctm, args[0]); }
    else if (fn === OPS.paintFormXObjectEnd) ctm = pila.pop() || [1, 0, 0, 1, 0, 0];
    else if (fn === OPS.constructPath) {
      const [sub, coords] = args;
      let k = 0;
      let actual = [];
      const cerrar = () => {
        // El ultimo punto puede repetir el primero.
        if (actual.length > 3 && Math.hypot(actual[0][0] - actual[actual.length - 1][0], actual[0][1] - actual[actual.length - 1][1]) < 0.5) actual.pop();
        if (actual.length >= 3 && actual.length <= 8) polys.push(actual);
        actual = [];
      };
      sub.forEach((op) => {
        if (op === OPS.rectangle) {
          const [x, y, w, h] = coords.slice(k, k + 4); k += 4;
          polys.push([[x, y], [x + w, y], [x + w, y + h], [x, y + h]].map(([a, b]) => aplicarM(ctm, a, b)));
        } else if (op === OPS.moveTo) {
          if (actual.length) cerrar();
          actual = [aplicarM(ctm, coords[k], coords[k + 1])]; k += 2;
        } else if (op === OPS.lineTo) {
          actual.push(aplicarM(ctm, coords[k], coords[k + 1])); k += 2;
        } else if (op === OPS.curveTo) { k += 6; actual = []; } else if (op === OPS.curveTo2 || op === OPS.curveTo3) { k += 4; actual = []; } else if (op === OPS.closePath) cerrar();
      });
      if (actual.length) cerrar();
    }
  }
  return polys;
}

/** Codigo comparable: "P-01", "p 1" y "P1" -> "P1". */
export function normalizarCodigo(txt) {
  const m = String(txt || '').toUpperCase().match(/^\s*([A-Z]*)[\s\-_.]*0*(\d+)\s*$/);
  return m ? `${m[1]}${m[2]}` : null;
}

/**
 * Busca en la pagina las etiquetas de parcela (P-01, P-02...) y el contorno
 * dibujado que contiene a cada una. Devuelve coordenadas normalizadas 0..1
 * (origen arriba-izquierda, igual que la imagen rasterizada):
 * [{codigo, centro: [x, y], anillo: [[x, y]...], exacto: bool}].
 * Si una etiqueta no tiene contorno propio se estima con la separacion entre
 * etiquetas vecinas (rejilla regular).
 */
export async function detectarParcelas(buffer, pagina, codigos) {
  const buscados = new Map(codigos.map((c) => [normalizarCodigo(c), c]).filter(([k]) => k));
  const pdf = await pdfjsLib.getDocument({ data: buffer.slice(0) }).promise;
  const page = await pdf.getPage(Math.min(Math.max(1, pagina || 1), pdf.numPages));
  const vp = page.getViewport({ scale: 1 });
  const norm = ([x, y]) => { const [vx, vy] = vp.convertToViewportPoint(x, y); return [vx / vp.width, vy / vp.height]; };

  // 1. Etiquetas que coinciden con los codigos de las parcelas del ensayo.
  const texto = await page.getTextContent();
  const etiquetas = [];
  texto.items.forEach((it) => {
    const k = normalizarCodigo(it.str);
    if (!k || !buscados.has(k) || etiquetas.some((e) => e.k === k)) return;
    const [a, b, , , e, f] = it.transform;
    const alto = it.height || Math.hypot(it.transform[2], it.transform[3]);
    const ux = a / (Math.hypot(a, b) || 1); const uy = b / (Math.hypot(a, b) || 1);
    // Centro de la caja del texto (respeta texto rotado).
    const cx = e + (ux * it.width) / 2 - (uy * alto) / 2;
    const cy = f + (uy * it.width) / 2 + (ux * alto) / 2;
    etiquetas.push({ k, codigo: buscados.get(k), pdf: [cx, cy] });
  });
  if (!etiquetas.length) return [];

  // 2. Contorno: el poligono mas pequeno que contiene la etiqueta y ninguna otra.
  const polys = (await poligonosPagina(page))
    .map((p) => ({ p, area: areaPoligono(p) }))
    .filter((o) => o.area > 4 && o.area < vp.width * vp.height * 0.25);
  etiquetas.forEach((et) => {
    const cand = polys
      .filter((o) => contiene(o.p, et.pdf[0], et.pdf[1]) && !etiquetas.some((x) => x !== et && contiene(o.p, x.pdf[0], x.pdf[1])))
      .sort((a, b) => a.area - b.area)[0];
    if (cand) et.anilloPdf = cand.p;
  });

  // 3. Sin contorno: rectangulo del tamano de la rejilla (mediana de separaciones).
  const exactos = etiquetas.filter((e) => e.anilloPdf);
  let w = null; let h = null;
  if (exactos.length) {
    const bb = exactos.map((e) => { const xs = e.anilloPdf.map((p) => p[0]); const ys = e.anilloPdf.map((p) => p[1]); return [Math.max(...xs) - Math.min(...xs), Math.max(...ys) - Math.min(...ys)]; });
    const med = (arr) => arr.sort((a, b) => a - b)[Math.floor(arr.length / 2)];
    w = med(bb.map((b) => b[0])); h = med(bb.map((b) => b[1]));
  } else if (etiquetas.length > 1) {
    const sep = (eje) => {
      const ds = [];
      etiquetas.forEach((a) => {
        let min = Infinity;
        etiquetas.forEach((b) => {
          const d = Math.abs(a.pdf[eje] - b.pdf[eje]);
          if (a !== b && d > 1 && Math.abs(a.pdf[1 - eje] - b.pdf[1 - eje]) < d / 2) min = Math.min(min, d);
        });
        if (Number.isFinite(min)) ds.push(min);
      });
      return ds.length ? ds.sort((x, y) => x - y)[Math.floor(ds.length / 2)] : null;
    };
    w = sep(0); h = sep(1);
    if (w && !h) h = w; if (h && !w) w = h;
  }

  return etiquetas.map((et) => {
    let anillo = et.anilloPdf;
    if (!anillo && w && h) {
      const [x, y] = et.pdf;
      anillo = [[x - w / 2, y - h / 2], [x + w / 2, y - h / 2], [x + w / 2, y + h / 2], [x - w / 2, y + h / 2]];
    }
    return { codigo: et.codigo, centro: norm(et.pdf), anillo: anillo ? anillo.map(norm) : null, exacto: !!et.anilloPdf };
  }).filter((r) => r.anillo);
}

export * from './geo-util';
