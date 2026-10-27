/**
 * Imagen rasterizada del croquis compartida entre la vista Croquis
 * (Estructura) y el Mapa GPS: el PDF se descarga y dibuja una sola vez por
 * archivo/pagina. La transformacion pixel <-> lat/lng se arma con geo-util.
 */
import { crearTransformacion } from './geo-util';

const cache = new Map();

function clave(croquis) {
  return `${croquis.path}#${croquis.pagina || 1}`;
}

/** Promesa de {url, ancho, alto} para el croquis (PDF o imagen). */
export function obtenerImagen(croquis, storageUrl, maxLado) {
  const k = clave(croquis);
  if (!cache.has(k)) {
    const url = `${storageUrl}/${croquis.path}`;
    const p = /pdf/i.test(croquis.mime)
      ? Promise.all([
        import(/* webpackChunkName: "geopdf" */ './geopdf'),
        fetch(url).then((r) => { if (!r.ok) throw new Error(r.status); return r.arrayBuffer(); }),
      ]).then(([geo, buf]) => geo.renderPagina(buf, croquis.pagina, maxLado))
      : new Promise((ok, mal) => {
        const im = new Image();
        im.onload = () => ok({ url, ancho: im.naturalWidth, alto: im.naturalHeight });
        im.onerror = mal;
        im.src = url;
      });
    // Si falla, se descarta para poder reintentar.
    p.catch(() => cache.delete(k));
    cache.set(k, p);
  }
  return cache.get(k);
}

/** Transformacion del croquis o null si no esta georreferenciado. */
export function transformacion(croquis, img) {
  if (!croquis || !croquis.georef || !img) return null;
  return crearTransformacion(croquis.georef.puntos, img.ancho, img.alto);
}
