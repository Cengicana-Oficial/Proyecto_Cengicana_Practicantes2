/**
 * Estadistica para ensayos agronomicos, calculada en el navegador (sin motor externo):
 * ANOVA de bloques completos al azar (DBCA) y completamente al azar (DCA), prueba de
 * Tukey (HSD / Tukey-Kramer) con letras, error estandar y area bajo la curva.
 *
 * Distribuciones: F con la funcion beta incompleta regularizada; rango estudentizado
 * (Tukey) por integracion numerica de su definicion.
 */

/* ------------------------------ basicos ------------------------------ */

export const suma = (xs) => xs.reduce((a, b) => a + b, 0);
export const media = (xs) => (xs.length ? suma(xs) / xs.length : null);
export function desv(xs) {
  if (xs.length < 2) return null;
  const m = media(xs);
  return Math.sqrt(suma(xs.map((x) => (x - m) ** 2)) / (xs.length - 1));
}
export function errorEstandar(xs) {
  const s = desv(xs);
  return s === null ? null : s / Math.sqrt(xs.length);
}

/* ------------------------------ funciones especiales ------------------------------ */

function lnGamma(x) {
  const c = [76.18009172947146, -86.50532032941677, 24.01409824083091, -1.231739572450155, 0.1208650973866179e-2, -0.5395239384953e-5];
  let y = x;
  const t = x + 5.5 - (x + 0.5) * Math.log(x + 5.5);
  let s = 1.000000000190015;
  for (let j = 0; j < 6; j += 1) { y += 1; s += c[j] / y; }
  return -t + Math.log((2.5066282746310005 * s) / x);
}

function betaCF(a, b, x) {
  const MAXIT = 300; const EPS = 3e-14; const FPMIN = 1e-300;
  let c = 1; let d = 1 - ((a + b) * x) / (a + 1);
  if (Math.abs(d) < FPMIN) d = FPMIN;
  d = 1 / d;
  let h = d;
  for (let m = 1; m <= MAXIT; m += 1) {
    const m2 = 2 * m;
    let aa = (m * (b - m) * x) / ((a - 1 + m2) * (a + m2));
    d = 1 + aa * d; if (Math.abs(d) < FPMIN) d = FPMIN;
    c = 1 + aa / c; if (Math.abs(c) < FPMIN) c = FPMIN;
    d = 1 / d; h *= d * c;
    aa = (-(a + m) * (a + b + m) * x) / ((a + m2) * (a + 1 + m2));
    d = 1 + aa * d; if (Math.abs(d) < FPMIN) d = FPMIN;
    c = 1 + aa / c; if (Math.abs(c) < FPMIN) c = FPMIN;
    d = 1 / d;
    const del = d * c;
    h *= del;
    if (Math.abs(del - 1) < EPS) break;
  }
  return h;
}

/** Beta incompleta regularizada I_x(a, b). */
export function betaInc(x, a, b) {
  if (x <= 0) return 0;
  if (x >= 1) return 1;
  const bt = Math.exp(lnGamma(a + b) - lnGamma(a) - lnGamma(b) + a * Math.log(x) + b * Math.log(1 - x));
  return x < (a + 1) / (a + b + 2) ? (bt * betaCF(a, b, x)) / a : 1 - (bt * betaCF(b, a, 1 - x)) / b;
}

/** P(F > f) con (d1, d2) grados de libertad. */
export function pValorF(f, d1, d2) {
  if (!(f > 0) || d1 <= 0 || d2 <= 0) return f === 0 ? 1 : null;
  return betaInc(d2 / (d2 + d1 * f), d2 / 2, d1 / 2);
}

const phi = (z) => Math.exp(-0.5 * z * z) / Math.sqrt(2 * Math.PI);
function Phi(z) {
  // Error de Abramowitz-Stegun 7.1.26 mejorado con la serie de erf de Numerical Recipes.
  const t = 1 / (1 + 0.5 * Math.abs(z) / Math.SQRT2);
  const y = t * Math.exp(-(z * z) / 2 - 1.26551223 + t * (1.00002368 + t * (0.37409196 + t * (0.09678418 + t * (-0.18628806
    + t * (0.27886807 + t * (-1.13520398 + t * (1.48851587 + t * (-0.82215223 + t * 0.17087277)))))))));
  return z >= 0 ? 1 - y / 2 : y / 2;
}

/** Integral de Simpson de f en [a, b] con n tramos (par). */
function simpson(f, a, b, n) {
  const h = (b - a) / n;
  let s = f(a) + f(b);
  for (let i = 1; i < n; i += 1) s += (i % 2 ? 4 : 2) * f(a + i * h);
  return (s * h) / 3;
}

/** P(rango de k normales estandar < w) (rango estudentizado con df infinitos). */
function rangoNormal(w, k) {
  if (w <= 0) return 0;
  const f = (z) => phi(z) * (Phi(z) - Phi(z - w)) ** (k - 1);
  return Math.min(1, k * simpson(f, -8, 8 + w, 400));
}

/** P(Q < q) de la distribucion del rango estudentizado con k medias y df grados de libertad. */
export function ptukey(q, k, df) {
  if (q <= 0) return 0;
  if (!Number.isFinite(df) || df > 5000) return rangoNormal(q, k);
  // s = sqrt(chi2_df / df): densidad g(s) = df^(df/2) s^(df-1) e^(-df s^2 / 2) / (Gamma(df/2) 2^(df/2 - 1))
  const lnC = (df / 2) * Math.log(df) - lnGamma(df / 2) - (df / 2 - 1) * Math.log(2);
  const g = (s) => (s <= 0 ? 0 : Math.exp(lnC + (df - 1) * Math.log(s) - (df * s * s) / 2));
  const sMax = 1 + 10 / Math.sqrt(df) + 3;
  return Math.min(1, simpson((s) => g(s) * rangoNormal(q * s, k), 1e-6, sMax, 240));
}

/** Valor critico q(1 - alfa; k, df) por biseccion. */
export function qtukey(p, k, df) {
  let lo = 0; let hi = 50;
  for (let i = 0; i < 60; i += 1) {
    const mid = (lo + hi) / 2;
    if (ptukey(mid, k, df) < p) lo = mid; else hi = mid;
  }
  return (lo + hi) / 2;
}

/* ------------------------------ ANOVA ------------------------------ */

/**
 * ANOVA de un muestreo. obs = [{ trat, bloque, valor }] (un valor por parcela).
 * Usa DBCA si el diseno es en bloques y la tabla tratamiento x bloque esta completa;
 * si no, DCA (desbalanceado admitido). Devuelve medias, tabla ANOVA, CV y Tukey.
 */
export function anova(obs, { bloques = true, alfa = 0.05 } = {}) {
  const trats = [...new Set(obs.map((o) => o.trat))].sort((a, b) => String(a).localeCompare(String(b), 'es', { numeric: true }));
  const porTrat = new Map(trats.map((t) => [t, obs.filter((o) => o.trat === t).map((o) => o.valor)]));
  const resumen = trats.map((t) => {
    const xs = porTrat.get(t);
    return { trat: t, n: xs.length, media: media(xs), sd: desv(xs), ee: errorEstandar(xs) };
  });
  const N = obs.length;
  const k = trats.length;
  const base = { trats, resumen, N, k, diseno: null, filas: [], cv: null, p: null, tukey: null, aviso: null };
  if (k < 2 || N <= k) return { ...base, aviso: k < 2 ? 'Se necesitan al menos 2 tratamientos.' : 'Se necesitan repeticiones para estimar el error.' };

  const gm = media(obs.map((o) => o.valor));
  const scTotal = suma(obs.map((o) => (o.valor - gm) ** 2));
  const scTrat = suma(resumen.map((r) => r.n * (r.media - gm) ** 2));

  const blqs = [...new Set(obs.map((o) => o.bloque).filter((b) => b !== null && b !== undefined && b !== ''))];
  const completo = bloques && blqs.length >= 2
    && trats.every((t) => blqs.every((b) => obs.filter((o) => o.trat === t && o.bloque === b).length === 1));

  let filas; let scError; let glError; let diseno;
  if (completo) {
    diseno = 'DBCA';
    const r = blqs.length;
    const scBloq = suma(blqs.map((b) => {
      const mb = media(obs.filter((o) => o.bloque === b).map((o) => o.valor));
      return k * (mb - gm) ** 2;
    }));
    scError = Math.max(0, scTotal - scTrat - scBloq);
    glError = (k - 1) * (r - 1);
    const cmBloq = scBloq / (r - 1);
    filas = [
      { fuente: 'Bloques (repeticiones)', gl: r - 1, sc: scBloq, cm: cmBloq },
      { fuente: 'Tratamientos', gl: k - 1, sc: scTrat, cm: scTrat / (k - 1) },
    ];
  } else {
    diseno = 'DCA';
    scError = Math.max(0, scTotal - scTrat);
    glError = N - k;
    filas = [{ fuente: 'Tratamientos', gl: k - 1, sc: scTrat, cm: scTrat / (k - 1) }];
  }
  const cmError = glError > 0 ? scError / glError : null;
  filas.forEach((f) => {
    f.F = cmError ? f.cm / cmError : null;
    f.p = f.F !== null ? pValorF(f.F, f.gl, glError) : null;
  });
  filas.push({ fuente: 'Error', gl: glError, sc: scError, cm: cmError });
  filas.push({ fuente: 'Total', gl: N - 1, sc: scTotal });
  const fTrat = filas.find((f) => f.fuente === 'Tratamientos');

  return {
    ...base,
    diseno,
    aviso: bloques && !completo && blqs.length >= 2 ? 'Faltan datos en algun bloque: se analizo como completamente al azar.' : null,
    filas,
    cmError,
    glError,
    cv: cmError !== null && gm ? (Math.sqrt(cmError) / Math.abs(gm)) * 100 : null,
    p: fTrat.p,
    tukey: cmError ? tukey(resumen, cmError, glError, alfa) : null,
  };
}

/**
 * Tukey HSD (Tukey-Kramer si los n difieren) y letras: medias que comparten una letra
 * no difieren estadisticamente (alfa).
 */
export function tukey(resumen, cmError, glError, alfa = 0.05) {
  const grupos = resumen.filter((r) => r.n > 0 && r.media !== null);
  const k = grupos.length;
  if (k < 2 || glError < 1) return null;
  const q = qtukey(1 - alfa, k, glError);
  const dms = (a, b) => q * Math.sqrt((cmError / 2) * (1 / a.n + 1 / b.n));
  const difiere = (a, b) => Math.abs(a.media - b.media) > dms(a, b);

  // Letras sobre las medias ordenadas de mayor a menor.
  const orden = grupos.slice().sort((a, b) => b.media - a.media);
  const letras = new Map(orden.map((g) => [g.trat, '']));
  const abc = 'abcdefghijklmnopqrstuvwxyz';
  let usadas = 0;
  let finAnterior = -1;
  for (let i = 0; i < orden.length; i += 1) {
    let j = i;
    while (j + 1 < orden.length && !difiere(orden[i], orden[j + 1])) j += 1;
    if (j > finAnterior) {
      const letra = abc[usadas] || '*';
      usadas += 1;
      for (let x = i; x <= j; x += 1) letras.set(orden[x].trat, letras.get(orden[x].trat) + letra);
      finAnterior = j;
    }
  }
  const nComun = grupos.every((g) => g.n === grupos[0].n) ? grupos[0].n : null;
  return {
    q,
    alfa,
    dms: nComun ? q * Math.sqrt(cmError / nComun) : null, // diferencia minima significativa (balanceado)
    letras: Object.fromEntries(letras),
  };
}

/* ------------------------------ series en el tiempo ------------------------------ */

/**
 * Agrupa fechas cercanas en muestreos: una ronda de muestreo puede tomar varios dias.
 * Devuelve [{ n, desde, hasta, fechas, centro }] en orden.
 */
export function agruparMuestreos(fechas, ventanaDias = 7) {
  const dia = 86400000;
  const unicas = [...new Set(fechas)].sort();
  const grupos = [];
  unicas.forEach((f) => {
    const g = grupos[grupos.length - 1];
    if (g && (Date.parse(f) - Date.parse(g.desde)) / dia <= ventanaDias) {
      g.hasta = f;
      g.fechas.push(f);
    } else {
      grupos.push({ desde: f, hasta: f, fechas: [f] });
    }
  });
  return grupos.map((g, i) => ({
    ...g,
    n: i + 1,
    centro: new Date((Date.parse(g.desde) + Date.parse(g.hasta)) / 2).toISOString().slice(0, 10),
  }));
}

/** Area bajo la curva (trapecios) de puntos [{ x, y }] ordenados por x. */
export function areaBajoCurva(puntos) {
  const p = puntos.filter((q) => q.y !== null && Number.isFinite(q.y)).sort((a, b) => a.x - b.x);
  let a = 0;
  for (let i = 1; i < p.length; i += 1) a += ((p[i].y + p[i - 1].y) / 2) * (p[i].x - p[i - 1].x);
  return p.length >= 2 ? a : null;
}

/** "p = 0.012 *" con la notacion habitual de significancia. */
export function significancia(p) {
  if (p === null || p === undefined) return { texto: '—', marca: '' };
  const marca = p < 0.001 ? '***' : p < 0.01 ? '**' : p < 0.05 ? '*' : 'ns';
  return { texto: p < 0.001 ? '< 0.001' : p.toFixed(3), marca };
}
