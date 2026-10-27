/**
 * Escala de color NDVI compartida por el Mapa GPS, la tabla por parcela y la
 * imagen generada desde el GeoTIFF. Rampa roja-amarilla-verde (convencion
 * agronomica): suelo/planta debil -> vegetacion densa. Los valores siempre se
 * muestran como numero junto al color.
 */
export const RAMPA_NDVI = [
  [-1, [120, 120, 120]],
  [0, [165, 0, 38]],
  [0.2, [244, 109, 67]],
  [0.35, [253, 224, 139]],
  [0.5, [217, 239, 139]],
  [0.65, [102, 189, 99]],
  [0.8, [26, 152, 80]],
  [1, [0, 104, 55]],
];

export function rgbNdvi(v) {
  if (v === null || v === undefined || !Number.isFinite(v)) return null;
  const x = Math.max(-1, Math.min(1, v));
  for (let i = 1; i < RAMPA_NDVI.length; i++) {
    const [v1, c1] = RAMPA_NDVI[i];
    if (x <= v1) {
      const [v0, c0] = RAMPA_NDVI[i - 1];
      const t = (x - v0) / (v1 - v0 || 1);
      return c0.map((c, k) => Math.round(c + (c1[k] - c) * t));
    }
  }
  return RAMPA_NDVI[RAMPA_NDVI.length - 1][1];
}

export function colorNdvi(v) {
  const c = rgbNdvi(v);
  return c ? `rgb(${c[0]},${c[1]},${c[2]})` : '#CED2D5';
}

/** Degradado CSS de la leyenda (de minimo a maximo). */
export function degradadoNdvi(min = 0, max = 1) {
  const pasos = 8;
  const stops = Array.from({ length: pasos + 1 }, (_, i) => {
    const v = min + ((max - min) * i) / pasos;
    return `${colorNdvi(v)} ${(i / pasos) * 100}%`;
  });
  return `linear-gradient(90deg, ${stops.join(', ')})`;
}

/** Texto oscuro o claro segun el fondo. */
export function tintaNdvi(v) {
  const c = rgbNdvi(v);
  if (!c) return '#33402a';
  return (0.299 * c[0] + 0.587 * c[1] + 0.114 * c[2]) > 150 ? '#1e2a1a' : '#ffffff';
}

export function claseNdvi(v) {
  if (v === null || v === undefined) return '';
  return v >= 0.6 ? 'is-alto' : v >= 0.3 ? 'is-medio' : 'is-bajo';
}
