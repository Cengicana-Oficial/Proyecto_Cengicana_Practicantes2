/**
 * Geometria y georreferenciado del ensayo, sin dependencias pesadas: la usan
 * el croquis (Estructura), el Mapa GPS y la navegacion compartida del detalle.
 * Las funciones de PDF (pdf.js / pdf-lib) estan en geopdf.js (carga diferida).
 */

/* ------------------------------ Transformacion ------------------------------ */

function resolver(A, b) {
  // Eliminacion gaussiana con pivoteo parcial.
  const n = b.length;
  const M = A.map((fila, i) => [...fila, b[i]]);
  for (let c = 0; c < n; c++) {
    let p = c;
    for (let r = c + 1; r < n; r++) if (Math.abs(M[r][c]) > Math.abs(M[p][c])) p = r;
    if (Math.abs(M[p][c]) < 1e-12) return null;
    [M[c], M[p]] = [M[p], M[c]];
    for (let r = 0; r < n; r++) {
      if (r === c) continue;
      const f = M[r][c] / M[c][c];
      for (let k = c; k <= n; k++) M[r][k] -= f * M[c][k];
    }
  }
  return M.map((fila, i) => fila[n] / fila[i]);
}

function homografia(src, dst) {
  const A = [];
  const b = [];
  src.forEach(([x, y], i) => {
    const [u, v] = dst[i];
    A.push([x, y, 1, 0, 0, 0, -u * x, -u * y]); b.push(u);
    A.push([0, 0, 0, x, y, 1, -v * x, -v * y]); b.push(v);
  });
  const h = resolver(A, b);
  if (!h) return null;
  const H = [[h[0], h[1], h[2]], [h[3], h[4], h[5]], [h[6], h[7], 1]];
  return (x, y) => {
    const w = H[2][0] * x + H[2][1] * y + H[2][2];
    return [(H[0][0] * x + H[0][1] * y + H[0][2]) / w, (H[1][0] * x + H[1][1] * y + H[1][2]) / w];
  };
}

function afin(src, dst) {
  // Minimos cuadrados: [u v] = [x y 1] * C
  const AtA = [[0, 0, 0], [0, 0, 0], [0, 0, 0]];
  const Atu = [0, 0, 0];
  const Atv = [0, 0, 0];
  src.forEach(([x, y], i) => {
    const f = [x, y, 1];
    for (let r = 0; r < 3; r++) {
      for (let c = 0; c < 3; c++) AtA[r][c] += f[r] * f[c];
      Atu[r] += f[r] * dst[i][0];
      Atv[r] += f[r] * dst[i][1];
    }
  });
  const cu = resolver(AtA, Atu);
  const cv = resolver(AtA, Atv);
  if (!cu || !cv) return null;
  return (x, y) => [cu[0] * x + cu[1] * y + cu[2], cv[0] * x + cv[1] * y + cv[2]];
}

/**
 * puntos: [{x, y (0..1), lat, lng}] ; ancho/alto de la imagen renderizada.
 * Devuelve {aGeo(px, py) -> {lat, lng}, aPx(lat, lng) -> {x, y}, mPorPx}.
 * Las coordenadas geo se escalan (lng*cos(lat)) para un ajuste bien condicionado.
 */
export function crearTransformacion(puntos, ancho, alto) {
  if (!puntos || puntos.length < 3) return null;
  const lat0 = puntos.reduce((s, p) => s + p.lat, 0) / puntos.length;
  const lng0 = puntos.reduce((s, p) => s + p.lng, 0) / puntos.length;
  const k = Math.cos((lat0 * Math.PI) / 180);
  const px = puntos.map((p) => [p.x * ancho, p.y * alto]);
  const geo = puntos.map((p) => [(p.lng - lng0) * k * 1e4, (p.lat - lat0) * 1e4]);

  const usarH = puntos.length === 4;
  const ida = (usarH && homografia(px, geo)) || afin(px, geo);
  const vuelta = (usarH && homografia(geo, px)) || afin(geo, px);
  if (!ida || !vuelta) return null;

  const aGeo = (x, y) => {
    const [u, v] = ida(x, y);
    return { lat: v / 1e4 + lat0, lng: u / (k * 1e4) + lng0 };
  };
  const aPx = (lat, lng) => {
    const [x, y] = vuelta((lng - lng0) * k * 1e4, (lat - lat0) * 1e4);
    return { x, y };
  };
  const a = aGeo(ancho / 2, alto / 2);
  const b = aGeo(ancho / 2 + 100, alto / 2);
  return { aGeo, aPx, mPorPx: distanciaM(a.lat, a.lng, b.lat, b.lng) / 100 };
}

/* --------------------------------- Geometria --------------------------------- */

export function distanciaM(lat1, lng1, lat2, lng2) {
  const R = 6371000;
  const r = Math.PI / 180;
  const dLat = (lat2 - lat1) * r;
  const dLng = (lng2 - lng1) * r;
  const a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * r) * Math.cos(lat2 * r) * Math.sin(dLng / 2) ** 2;
  return 2 * R * Math.asin(Math.min(1, Math.sqrt(a)));
}

/** Punto (lng, lat) dentro de un anillo GeoJSON [[lng, lat], ...] (ray casting). */
export function puntoEnPoligono(lng, lat, anillo) {
  let dentro = false;
  for (let i = 0, j = anillo.length - 1; i < anillo.length; j = i++) {
    const [xi, yi] = anillo[i];
    const [xj, yj] = anillo[j];
    if ((yi > lat) !== (yj > lat) && lng < ((xj - xi) * (lat - yi)) / (yj - yi) + xi) dentro = !dentro;
  }
  return dentro;
}

export function centroide(anillo) {
  const pts = anillo.slice(0, -1).length >= 3 ? anillo.slice(0, -1) : anillo;
  return {
    lng: pts.reduce((s, c) => s + c[0], 0) / pts.length,
    lat: pts.reduce((s, c) => s + c[1], 0) / pts.length,
  };
}

/** Distancia (m) aproximada del punto al borde mas cercano del poligono. */
export function distanciaAPoligono(lat, lng, anillo) {
  const k = Math.cos((lat * Math.PI) / 180) * 111320;
  const P = [lng * k, lat * 110540];
  let min = Infinity;
  for (let i = 0; i < anillo.length - 1; i++) {
    const A = [anillo[i][0] * k, anillo[i][1] * 110540];
    const B = [anillo[i + 1][0] * k, anillo[i + 1][1] * 110540];
    const dx = B[0] - A[0];
    const dy = B[1] - A[1];
    const t = Math.max(0, Math.min(1, ((P[0] - A[0]) * dx + (P[1] - A[1]) * dy) / (dx * dx + dy * dy || 1)));
    min = Math.min(min, Math.hypot(P[0] - (A[0] + t * dx), P[1] - (A[1] + t * dy)));
  }
  return min;
}

/** Poligonos desde GeoJSON o KML: [{nombre, props, anillo: [[lng, lat], ...]}]. */
export function leerPoligonos(texto, nombreArchivo) {
  const out = [];
  if (/\.kml$/i.test(nombreArchivo) || /^\s*<\?xml|<kml/i.test(texto)) {
    const xml = new DOMParser().parseFromString(texto, 'application/xml');
    Array.from(xml.getElementsByTagName('Placemark')).forEach((pm) => {
      const nombre = (pm.getElementsByTagName('name')[0] || {}).textContent || '';
      const props = { name: nombre.trim() };
      Array.from(pm.getElementsByTagName('SimpleData')).forEach((sd) => { props[sd.getAttribute('name')] = sd.textContent.trim(); });
      Array.from(pm.getElementsByTagName('Data')).forEach((d) => {
        const v = d.getElementsByTagName('value')[0];
        if (v) props[d.getAttribute('name')] = v.textContent.trim();
      });
      const outer = pm.getElementsByTagName('outerBoundaryIs')[0] || pm.getElementsByTagName('Polygon')[0];
      const coords = outer && outer.getElementsByTagName('coordinates')[0];
      if (!coords) return;
      const anillo = coords.textContent.trim().split(/\s+/).map((t) => t.split(',').map(Number)).filter((c) => c.length >= 2 && !Number.isNaN(c[0]))
        .map((c) => [c[0], c[1]]);
      if (anillo.length >= 3) out.push({ nombre: props.name, props, anillo: cerrar(anillo) });
    });
    return out;
  }
  const gj = JSON.parse(texto);
  const feats = gj.type === 'FeatureCollection' ? gj.features : gj.type === 'Feature' ? [gj] : [];
  feats.forEach((f) => {
    const g = f.geometry || {};
    const props = f.properties || {};
    const polys = g.type === 'Polygon' ? [g.coordinates] : g.type === 'MultiPolygon' ? g.coordinates : [];
    polys.forEach((p) => {
      if (p && p[0] && p[0].length >= 3) out.push({ nombre: String(props.codigo || props.name || props.Name || ''), props, anillo: cerrar(p[0].map((c) => [c[0], c[1]])) });
    });
  });
  return out;
}

export function cerrar(anillo) {
  const a = anillo[0];
  const z = anillo[anillo.length - 1];
  return a[0] === z[0] && a[1] === z[1] ? anillo : [...anillo, a];
}

/** Rumbo inicial (grados 0-360, 0 = norte) de A hacia B. */
export function rumbo(lat1, lng1, lat2, lng2) {
  const r = Math.PI / 180;
  const y = Math.sin((lng2 - lng1) * r) * Math.cos(lat2 * r);
  const x = Math.cos(lat1 * r) * Math.sin(lat2 * r) - Math.sin(lat1 * r) * Math.cos(lat2 * r) * Math.cos((lng2 - lng1) * r);
  return ((Math.atan2(y, x) / r) + 360) % 360;
}

export function cardinal(grados) {
  return ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO'][Math.round(grados / 45) % 8];
}

/* -------------------------------- Navegacion -------------------------------- */

/** Parcela que contiene el punto, o la mas cercana y su distancia (m). */
export function ubicarEnParcelas(lat, lng, parcelas) {
  let cercana = null;
  let distancia = Infinity;
  for (const p of parcelas) {
    if (!p.geometria || !p.geometria.coordinates) continue;
    const anillo = p.geometria.coordinates[0];
    if (puntoEnPoligono(lng, lat, anillo)) return { parcela: p, cercana: null, distancia: 0 };
    const d = distanciaAPoligono(lat, lng, anillo);
    if (d < distancia) { distancia = d; cercana = p; }
  }
  return { parcela: null, cercana, distancia: cercana ? distancia : null };
}

/**
 * Estado de navegacion compartido por el croquis y el Mapa GPS: parcela
 * actual / cercana, y distancia y rumbo hacia la parcela destino.
 */
export function navegacion(pos, parcelas, destinoId) {
  const nav = { parcela: null, cercana: null, distancia: null, destino: null, destinoCentro: null, distDestino: null, rumbo: null, cardinal: null, llegado: false };
  const destino = destinoId ? parcelas.find((p) => p.id === destinoId && p.geometria) : null;
  if (destino) {
    nav.destino = destino;
    nav.destinoCentro = centroide(destino.geometria.coordinates[0]);
  }
  if (!pos) return nav;
  Object.assign(nav, ubicarEnParcelas(pos.lat, pos.lng, parcelas));
  if (destino) {
    nav.llegado = !!nav.parcela && nav.parcela.id === destino.id;
    nav.distDestino = nav.llegado ? 0 : distanciaM(pos.lat, pos.lng, nav.destinoCentro.lat, nav.destinoCentro.lng);
    nav.rumbo = rumbo(pos.lat, pos.lng, nav.destinoCentro.lat, nav.destinoCentro.lng);
    nav.cardinal = cardinal(nav.rumbo);
  }
  return nav;
}
