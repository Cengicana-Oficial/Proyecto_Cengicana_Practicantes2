/**
 * Utilidades compartidas de la pagina Clima y estaciones.
 */

// Paleta categorica validada (guia de visualizacion): orden fijo, el color sigue a la entidad.
export const PALETA = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];

export const COLOR_ESTRATO = { ALTO: '#2a78d6', MEDIO: '#eb6834', BAJO: '#1baf7a', LITORAL: '#eda100', 'SIN ESTRATO': '#8a8f87' };

export const GRIS = '#52514e';

const eSat = (t) => 0.6108 * Math.exp((17.27 * t) / (t + 237.3));

/**
 * Agrega a un registro diario el DPV (kPa, FAO-56) y los grados dia (°C·dia):
 * - DPV = es - ea; es = [e°(Tmax) + e°(Tmin)] / 2; ea con HR max/min
 *   [e°(Tmin)·HRmax + e°(Tmax)·HRmin] / 200, o HR media si faltan.
 * - Grados dia = max(0, (Tmax + Tmin) / 2 - Tbase).
 */
export function derivados(f, tbase) {
  const ok = (v) => v !== null && v !== undefined;
  let vpd = null;
  let gdd = null;
  if (ok(f.tmax) && ok(f.tmin)) {
    gdd = Math.max(0, (f.tmax + f.tmin) / 2 - tbase);
    const es = (eSat(f.tmax) + eSat(f.tmin)) / 2;
    let ea = null;
    if (ok(f.hr_min) && ok(f.hr_max)) ea = (eSat(f.tmin) * f.hr_max + eSat(f.tmax) * f.hr_min) / 200;
    else if (ok(f.hr)) ea = (es * f.hr) / 100;
    if (ea !== null) vpd = Math.max(0, es - ea);
  }
  return { ...f, vpd, gdd };
}
