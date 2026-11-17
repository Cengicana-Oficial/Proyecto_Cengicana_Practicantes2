/**
 * Procesamiento de imagenes NDVI en el navegador (se carga bajo demanda):
 *
 * - leerGeoTiff(): abre un GeoTIFF (Sentinel-2/EO Browser, QGIS, Pix4D,
 *   DroneDeploy...) en WGS84 (EPSG:4326), UTM WGS84/NAD83 o Web Mercator.
 * - rasterNdvi(): obtiene la rejilla NDVI (banda NDVI directa o calculada
 *   como (NIR - Rojo) / (NIR + Rojo)) reducida a <= maxLado px y cuantizada a
 *   Uint8 (0..254 = -1..1, 255 = sin dato), que es lo que se guarda.
 * - colorear(): PNG con la rampa NDVI para superponer en el Mapa GPS.
 * - valoresPorParcela(): NDVI medio/min/max de los pixeles dentro de cada
 *   poligono de parcela.
 * - leerKmz() / componerKmz() / ndviDesdeColores(): KMZ de drones (DJI Terra, Pix4D,
 *   Agisoft...) con la imagen en mosaicos GroundOverlay: se unen en una sola imagen y, si
 *   es un mapa NDVI coloreado rojo -> amarillo -> verde, se estima el NDVI por el tono.
 */
import { fromArrayBuffer } from 'geotiff';
import { inflateRaw } from 'pako';
import { rgbNdvi } from './ndvi-color';

/* ------------------------------ proyecciones ------------------------------ */

const A = 6378137;
const F = 1 / 298.257223563;
const E2 = F * (2 - F);
const EP2 = E2 / (1 - E2);
const K0 = 0.9996;
const rad = (d) => (d * Math.PI) / 180;
const grad = (r) => (r * 180) / Math.PI;

function utm(zona, sur) {
  const lon0 = rad((zona - 1) * 6 - 180 + 3);
  const e4 = E2 * E2; const e6 = e4 * E2;
  const m = (phi) => A * ((1 - E2 / 4 - (3 * e4) / 64 - (5 * e6) / 256) * phi
    - ((3 * E2) / 8 + (3 * e4) / 32 + (45 * e6) / 1024) * Math.sin(2 * phi)
    + ((15 * e4) / 256 + (45 * e6) / 1024) * Math.sin(4 * phi)
    - ((35 * e6) / 3072) * Math.sin(6 * phi));
  return {
    deGeo(lat, lng) {
      const phi = rad(lat);
      const n = A / Math.sqrt(1 - E2 * Math.sin(phi) ** 2);
      const t = Math.tan(phi) ** 2;
      const c = EP2 * Math.cos(phi) ** 2;
      const a = Math.cos(phi) * (rad(lng) - lon0);
      const x = K0 * n * (a + ((1 - t + c) * a ** 3) / 6 + ((5 - 18 * t + t * t + 72 * c - 58 * EP2) * a ** 5) / 120) + 500000;
      let y = K0 * (m(phi) + n * Math.tan(phi) * ((a * a) / 2 + ((5 - t + 9 * c + 4 * c * c) * a ** 4) / 24
        + ((61 - 58 * t + t * t + 600 * c - 330 * EP2) * a ** 6) / 720));
      if (sur) y += 10000000;
      return [x, y];
    },
    aGeo(x, y) {
      const mm = (sur ? y - 10000000 : y) / K0;
      const mu = mm / (A * (1 - E2 / 4 - (3 * e4) / 64 - (5 * e6) / 256));
      const e1 = (1 - Math.sqrt(1 - E2)) / (1 + Math.sqrt(1 - E2));
      const p1 = mu + ((3 * e1) / 2 - (27 * e1 ** 3) / 32) * Math.sin(2 * mu)
        + ((21 * e1 * e1) / 16 - (55 * e1 ** 4) / 32) * Math.sin(4 * mu)
        + ((151 * e1 ** 3) / 96) * Math.sin(6 * mu) + ((1097 * e1 ** 4) / 512) * Math.sin(8 * mu);
      const n1 = A / Math.sqrt(1 - E2 * Math.sin(p1) ** 2);
      const t1 = Math.tan(p1) ** 2;
      const c1 = EP2 * Math.cos(p1) ** 2;
      const r1 = (A * (1 - E2)) / (1 - E2 * Math.sin(p1) ** 2) ** 1.5;
      const d = (x - 500000) / (n1 * K0);
      const lat = p1 - ((n1 * Math.tan(p1)) / r1) * ((d * d) / 2 - ((5 + 3 * t1 + 10 * c1 - 4 * c1 * c1 - 9 * EP2) * d ** 4) / 24
        + ((61 + 90 * t1 + 298 * c1 + 45 * t1 * t1 - 252 * EP2 - 3 * c1 * c1) * d ** 6) / 720);
      const lng = lon0 + (d - ((1 + 2 * t1 + c1) * d ** 3) / 6 + ((5 - 2 * c1 + 28 * t1 - 3 * c1 * c1 + 8 * EP2 + 24 * t1 * t1) * d ** 5) / 120) / Math.cos(p1);
      return { lat: grad(lat), lng: grad(lng) };
    },
  };
}

const MERCATOR = {
  deGeo: (lat, lng) => [A * rad(lng), A * Math.log(Math.tan(Math.PI / 4 + rad(lat) / 2))],
  aGeo: (x, y) => ({ lat: grad(2 * Math.atan(Math.exp(y / A)) - Math.PI / 2), lng: grad(x / A) }),
};
const GEOGRAFICA = { deGeo: (lat, lng) => [lng, lat], aGeo: (x, y) => ({ lat: y, lng: x }) };

/** Proyeccion por codigo EPSG o null si no se soporta. */
export function proyeccion(epsg) {
  const e = Number(epsg);
  if ([4326, 4269, 4258, 4979].includes(e)) return GEOGRAFICA;
  if ([3857, 900913, 3785, 102100].includes(e)) return MERCATOR;
  if (e > 32600 && e <= 32660) return utm(e - 32600, false);
  if (e > 32700 && e <= 32760) return utm(e - 32700, true);
  if (e > 26900 && e <= 26923) return utm(e - 26900, false); // NAD83 UTM (~WGS84 a esta escala)
  return null;
}

/* -------------------------------- lectura -------------------------------- */

export async function leerGeoTiff(buffer) {
  const tiff = await fromArrayBuffer(buffer);
  const img = await tiff.getImage();
  const keys = img.getGeoKeys() || {};
  const epsg = keys.ProjectedCSTypeGeoKey || keys.GeographicTypeGeoKey || (keys.GTModelTypeGeoKey === 2 ? 4326 : null);
  if (!proyeccion(epsg)) {
    throw new Error(`Sistema de coordenadas no soportado (EPSG ${epsg || 'desconocido'}). Exporte el GeoTIFF en WGS84 (EPSG:4326), UTM WGS84 o Web Mercator.`);
  }
  let origen; let resol;
  try { origen = img.getOrigin(); resol = img.getResolution(); } catch (e) {
    throw new Error('El TIFF no trae georreferenciado (ModelTiepoint / PixelScale).');
  }
  const nd = img.getGDALNoData();
  return {
    img,
    ancho: img.getWidth(),
    alto: img.getHeight(),
    bandas: img.getSamplesPerPixel(),
    epsg: Number(epsg),
    origen: [origen[0], origen[1]],
    resol: [resol[0], resol[1]],
    nodata: nd === null || nd === undefined || Number.isNaN(Number(nd)) ? null : Number(nd),
  };
}

/**
 * Rejilla NDVI cuantizada. opciones: {banda} para una banda NDVI o
 * {rojo, nir} (indices 0..n-1) para calcularla.
 */
export async function rasterNdvi(info, { banda = 0, rojo = null, nir = null, maxLado = 1400 } = {}) {
  const f = Math.min(1, maxLado / Math.max(info.ancho, info.alto));
  const w = Math.max(1, Math.round(info.ancho * f));
  const h = Math.max(1, Math.round(info.alto * f));
  const calcular = rojo !== null && nir !== null;
  const samples = calcular ? [rojo, nir] : [banda];
  const r = await info.img.readRasters({ samples, width: w, height: h, resampleMethod: 'nearest' });

  const vals = new Float32Array(w * h);
  let maxAbs = 0;
  for (let i = 0; i < w * h; i++) {
    let v;
    if (calcular) {
      const rr = r[0][i]; const nn = r[1][i];
      v = (info.nodata !== null && (rr === info.nodata || nn === info.nodata)) || rr + nn === 0 ? NaN : (nn - rr) / (nn + rr);
    } else {
      v = r[0][i];
      if (info.nodata !== null && v === info.nodata) v = NaN;
    }
    vals[i] = v;
    if (Number.isFinite(v)) maxAbs = Math.max(maxAbs, Math.abs(v));
  }
  // NDVI entero escalado (p. ej. x10000 de Sentinel-2 L2A / GEE).
  let escala = 1;
  if (!calcular && maxAbs > 1.5) {
    if (maxAbs <= 10000) escala = maxAbs > 1000 ? 10000 : maxAbs > 100 ? 1000 : 100;
    else throw new Error(`La banda tiene valores hasta ${Math.round(maxAbs)}: no parece NDVI. Elija las bandas Roja y NIR para calcularlo.`);
  }

  const datos = new Uint8Array(w * h);
  let suma = 0; let n = 0; let min = Infinity; let max = -Infinity;
  for (let i = 0; i < w * h; i++) {
    const v = vals[i] / escala;
    if (!Number.isFinite(v) || v < -1.05 || v > 1.05) { datos[i] = 255; continue; }
    const c = Math.max(-1, Math.min(1, v));
    datos[i] = Math.round((c + 1) * 127);
    suma += c; n++; if (c < min) min = c; if (c > max) max = c;
  }
  if (!n) throw new Error('La imagen no tiene pixeles NDVI validos.');

  const georef = {
    epsg: info.epsg,
    x0: info.origen[0],
    y0: info.origen[1],
    dx: (info.resol[0] * info.ancho) / w,
    dy: (info.resol[1] * info.alto) / h,
  };
  return { ancho: w, alto: h, datos, georef, escala, stats: { media: suma / n, min, max, n } };
}

export const decodificar = (q) => (q === 255 ? null : q / 127 - 1);

/** Esquinas lat/lng {tl, tr, bl, br} del raster. */
export function esquinas(georef, w, h) {
  const p = proyeccion(georef.epsg);
  const g = (i, j) => p.aGeo(georef.x0 + i * georef.dx, georef.y0 + j * georef.dy);
  return { tl: g(0, 0), tr: g(w, 0), bl: g(0, h), br: g(w, h) };
}

/** PNG coloreado (transparente donde no hay dato). */
export function colorear(datos, w, h) {
  const canvas = document.createElement('canvas');
  canvas.width = w; canvas.height = h;
  const ctx = canvas.getContext('2d');
  const im = ctx.createImageData(w, h);
  const cache = new Map();
  for (let i = 0; i < w * h; i++) {
    const q = datos[i];
    if (q === 255) continue;
    let c = cache.get(q);
    if (!c) { c = rgbNdvi(decodificar(q)); cache.set(q, c); }
    im.data[i * 4] = c[0]; im.data[i * 4 + 1] = c[1]; im.data[i * 4 + 2] = c[2]; im.data[i * 4 + 3] = 255;
  }
  ctx.putImageData(im, 0, 0);
  return new Promise((ok) => canvas.toBlob(ok, 'image/png'));
}

function dentro(pts, x, y) {
  let r = false;
  for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
    const [xi, yi] = pts[i]; const [xj, yj] = pts[j];
    if ((yi > y) !== (yj > y) && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) r = !r;
  }
  return r;
}

/** {parcela_id: {media, min, max, n}} con los pixeles cuyo centro cae en el poligono. */
export function valoresPorParcela(datos, w, h, georef, parcelas) {
  const p = proyeccion(georef.epsg);
  const out = {};
  parcelas.filter((x) => x.geometria).forEach((parcela) => {
    const anillo = parcela.geometria.coordinates[0].map(([lng, lat]) => {
      const [x, y] = p.deGeo(lat, lng);
      return [(x - georef.x0) / georef.dx, (y - georef.y0) / georef.dy];
    });
    const xs = anillo.map((a) => a[0]); const ys = anillo.map((a) => a[1]);
    const i0 = Math.max(0, Math.floor(Math.min(...xs))); const i1 = Math.min(w - 1, Math.ceil(Math.max(...xs)));
    const j0 = Math.max(0, Math.floor(Math.min(...ys))); const j1 = Math.min(h - 1, Math.ceil(Math.max(...ys)));
    let suma = 0; let n = 0; let min = Infinity; let max = -Infinity;
    const sumar = (q) => { const v = decodificar(q); if (v === null) return; suma += v; n++; if (v < min) min = v; if (v > max) max = v; };
    for (let j = j0; j <= j1; j++) {
      for (let i = i0; i <= i1; i++) if (dentro(anillo, i + 0.5, j + 0.5)) sumar(datos[j * w + i]);
    }
    // Parcela mas pequena que un pixel (p. ej. Sentinel-2 a 10 m): pixel del centro.
    if (!n) {
      const cx = xs.reduce((a, b) => a + b, 0) / xs.length; const cy = ys.reduce((a, b) => a + b, 0) / ys.length;
      const i = Math.floor(cx); const j = Math.floor(cy);
      if (i >= 0 && j >= 0 && i < w && j < h) sumar(datos[j * w + i]);
    }
    if (n) out[parcela.id] = { media: +(suma / n).toFixed(4), min: +min.toFixed(4), max: +max.toFixed(4), n };
  });
  return out;
}

/* ------------------------------ KMZ de drones ------------------------------ */

/** Entradas de un ZIP: Map nombre -> () => Uint8Array (se descomprime al pedirla). */
function leerZip(buffer) {
  const v = new DataView(buffer);
  let fin = -1;
  for (let i = buffer.byteLength - 22; i >= Math.max(0, buffer.byteLength - 65557); i--) {
    if (v.getUint32(i, true) === 0x06054b50) { fin = i; break; }
  }
  if (fin < 0) throw new Error('El archivo no es un KMZ valido (no se encontro el indice del ZIP).');
  const total = v.getUint16(fin + 10, true);
  let p = v.getUint32(fin + 16, true);
  const texto = new TextDecoder();
  const entradas = new Map();
  for (let k = 0; k < total; k++) {
    if (v.getUint32(p, true) !== 0x02014b50) throw new Error('El indice del KMZ esta danado.');
    const metodo = v.getUint16(p + 10, true);
    const comprimido = v.getUint32(p + 20, true);
    const largoNombre = v.getUint16(p + 28, true);
    const largoExtra = v.getUint16(p + 30, true);
    const largoComent = v.getUint16(p + 32, true);
    const local = v.getUint32(p + 42, true);
    const nombre = texto.decode(new Uint8Array(buffer, p + 46, largoNombre));
    entradas.set(nombre, () => {
      const inicio = local + 30 + v.getUint16(local + 26, true) + v.getUint16(local + 28, true);
      const datos = new Uint8Array(buffer, inicio, comprimido);
      if (metodo === 0) return datos;
      if (metodo === 8) return inflateRaw(datos);
      throw new Error(`Compresion no soportada en ${nombre}.`);
    });
    p += 46 + largoNombre + largoExtra + largoComent;
  }
  return entradas;
}

/**
 * Abre un KMZ con imagenes GroundOverlay (una o en mosaicos, norte arriba).
 * Devuelve los mosaicos, sus limites totales y el tamano del area en metros.
 */
export function leerKmz(buffer) {
  const zip = leerZip(buffer);
  const nombres = [...zip.keys()];
  const kml = nombres.find((n) => /(^|\/)doc\.kml$/i.test(n)) || nombres.find((n) => /\.kml$/i.test(n));
  if (!kml) throw new Error('El KMZ no contiene un archivo KML.');
  const xml = new DOMParser().parseFromString(new TextDecoder().decode(zip.get(kml)()), 'text/xml');
  const carpeta = kml.includes('/') ? kml.slice(0, kml.lastIndexOf('/') + 1) : '';
  const num = (el, tag) => { const x = el.getElementsByTagName(tag)[0]; return x ? Number(x.textContent) : NaN; };

  const overlays = [...xml.getElementsByTagName('GroundOverlay')];
  if (overlays.some((g) => !g.getElementsByTagName('LatLonBox')[0])) {
    throw new Error('El KMZ trae imagenes con esquinas libres (LatLonQuad); exportelo con limites norte/sur/este/oeste.');
  }
  const mosaicos = overlays.map((g) => {
    const href = decodeURIComponent(((g.getElementsByTagName('href')[0] || {}).textContent || '').trim());
    const caja = g.getElementsByTagName('LatLonBox')[0];
    const archivo = zip.has(carpeta + href) ? carpeta + href : href;
    return { archivo, n: num(caja, 'north'), s: num(caja, 'south'), e: num(caja, 'east'), o: num(caja, 'west'), rotacion: num(caja, 'rotation') || 0 };
  }).filter((m) => zip.has(m.archivo) && [m.n, m.s, m.e, m.o].every(Number.isFinite));
  if (!mosaicos.length) throw new Error('El KMZ no trae imagenes georreferenciadas (GroundOverlay).');
  if (mosaicos.some((m) => Math.abs(m.rotacion) > 0.01)) throw new Error('El KMZ trae imagenes rotadas: exportelo con el norte hacia arriba (sin rotacion).');

  const limites = {
    n: Math.max(...mosaicos.map((m) => m.n)), s: Math.min(...mosaicos.map((m) => m.s)),
    e: Math.max(...mosaicos.map((m) => m.e)), o: Math.min(...mosaicos.map((m) => m.o)),
  };
  const cosLat = Math.cos(rad((limites.n + limites.s) / 2));
  return {
    zip,
    nombre: ((xml.getElementsByTagName('name')[0] || {}).textContent || '').trim(),
    mosaicos,
    limites,
    anchoM: (limites.e - limites.o) * 111320 * cosLat,
    altoM: (limites.n - limites.s) * 110574,
    cosLat,
  };
}

function mime(nombre) {
  if (/\.jpe?g$/i.test(nombre)) return 'image/jpeg';
  return /\.gif$/i.test(nombre) ? 'image/gif' : 'image/png';
}

/**
 * Une los mosaicos del KMZ en un canvas de hasta maxLado px. Sin suavizado (vecino mas
 * cercano): conserva los colores exactos de la paleta, que es de donde se lee el NDVI.
 */
export async function componerKmz(kmz, { maxLado = 1600, progreso = () => {} } = {}) {
  const { n, s, e, o } = kmz.limites;
  // Resolucion nativa a partir del primer mosaico.
  const m0 = kmz.mosaicos[0];
  const bmp0 = await createImageBitmap(new Blob([kmz.zip.get(m0.archivo)()], { type: mime(m0.archivo) }));
  const resolNativa = ((m0.e - m0.o) * 111320 * kmz.cosLat) / bmp0.width;
  bmp0.close();
  const resol = Math.max(resolNativa, Math.max(kmz.anchoM, kmz.altoM) / maxLado);
  const w = Math.max(1, Math.round(kmz.anchoM / resol));
  const h = Math.max(1, Math.round(kmz.altoM / resol));

  const canvas = document.createElement('canvas');
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext('2d');
  ctx.imageSmoothingEnabled = false;
  for (let i = 0; i < kmz.mosaicos.length; i++) {
    const m = kmz.mosaicos[i];
    const bmp = await createImageBitmap(new Blob([kmz.zip.get(m.archivo)()], { type: mime(m.archivo) }));
    const x = ((m.o - o) / (e - o)) * w;
    const y = ((n - m.n) / (n - s)) * h;
    // +1 px de traslape para que no queden costuras entre mosaicos al redondear.
    ctx.drawImage(bmp, Math.floor(x), Math.floor(y), Math.ceil(((m.e - m.o) / (e - o)) * w) + 1, Math.ceil(((m.n - m.s) / (n - s)) * h) + 1);
    bmp.close();
    progreso(i + 1, kmz.mosaicos.length);
    if (i % 10 === 9) await new Promise((ok) => setTimeout(ok)); // deja respirar a la pagina
  }
  return {
    canvas,
    ancho: w,
    alto: h,
    resolNativa,
    resol,
    georef: { epsg: 4326, x0: o, y0: n, dx: (e - o) / w, dy: -(n - s) / h },
  };
}

/** Tono (0..360) de un color, o null si es gris/blanco. */
function tono(r, g, b) {
  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  const d = max - min;
  if (d < 24) return null;
  let t;
  if (max === r) t = ((g - b) / d) % 6;
  else if (max === g) t = (b - r) / d + 2;
  else t = (r - g) / d + 4;
  t *= 60;
  return t < 0 ? t + 360 : t;
}

/**
 * NDVI aproximado de un mapa coloreado con la rampa rojo (bajo) -> amarillo -> verde (alto),
 * como los que exportan DJI Terra o Pix4D. Los extremos de la rampa (el tono mas rojo y el
 * mas verde de la imagen) equivalen a los valores minimo y maximo de su leyenda.
 * Devuelve la rejilla cuantizada igual que rasterNdvi().
 */
export function ndviDesdeColores(canvas, { min = 0, max = 1 } = {}) {
  const w = canvas.width;
  const h = canvas.height;
  const px = canvas.getContext('2d').getImageData(0, 0, w, h).data;
  const tonos = new Float32Array(w * h).fill(NaN);
  let tMin = Infinity;
  let tMax = -Infinity;
  for (let i = 0; i < w * h; i++) {
    if (px[i * 4 + 3] < 128) continue;
    let t = tono(px[i * 4], px[i * 4 + 1], px[i * 4 + 2]);
    if (t === null) continue;
    if (t > 300) t -= 360; // rojos al otro lado del circulo
    if (t > 150) continue; // azules/morados: fuera de la rampa
    tonos[i] = t;
    if (t < tMin) tMin = t;
    if (t > tMax) tMax = t;
  }
  if (!(tMax - tMin > 20)) throw new Error('La imagen no tiene una escala de color rojo-amarillo-verde: guardela como "solo imagen".');
  const datos = new Uint8Array(w * h).fill(255);
  let suma = 0;
  let n = 0;
  let vMin = Infinity;
  let vMax = -Infinity;
  for (let i = 0; i < w * h; i++) {
    if (Number.isNaN(tonos[i])) continue;
    const v = Math.max(-1, Math.min(1, min + ((tonos[i] - tMin) / (tMax - tMin)) * (max - min)));
    datos[i] = Math.round((v + 1) * 127);
    suma += v;
    n++;
    if (v < vMin) vMin = v;
    if (v > vMax) vMax = v;
  }
  return { datos, stats: { media: suma / n, min: vMin, max: vMax, n }, tonos: { min: tMin, max: tMax } };
}
