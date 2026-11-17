import { anova, media, agruparMuestreos, areaBajoCurva } from '../../../estadistica';

export function numero(valor) {
  if (valor === null || valor === undefined) return null;
  const s = String(valor).trim().replace(',', '.');
  return s !== '' && Number.isFinite(Number(s)) ? Number(s) : null;
}

// Usa el mismo motor y la misma unidad experimental de Evaluaciones:
// una media por parcela en cada muestreo, sin tratar lecturas repetidas como repeticiones.
export function analizarVariable(variable, registros, parcelas, ensayo, ventana = 7, alfa = 0.05) {
  const mapa = new Map(parcelas.map((p) => [p.id, p]));
  const puntos = registros.filter((r) => r.variable_id === variable.id).map((r) => ({ ...r, valor: numero(r.valor), p: mapa.get(r.parcela_id) })).filter((r) => r.valor !== null && r.p);
  const bloques = typeof ensayo.usa_bloques === 'boolean' ? ensayo.usa_bloques : /bloque/i.test(ensayo.diseno || '') || !ensayo.diseno;
  const muestreos = agruparMuestreos(puntos.map((p) => p.fecha), ventana).map((m) => {
    const fechas = new Set(m.fechas); const porParcela = new Map();
    puntos.filter((p) => fechas.has(p.fecha)).forEach((r) => {
      if (!porParcela.has(r.parcela_id)) porParcela.set(r.parcela_id, { parcela: r.parcela_id, trat: r.p.tratamiento || 'Sin trat.', bloque: r.p.repeticion, valores: [] });
      porParcela.get(r.parcela_id).valores.push(r.valor);
    });
    const obs = [...porParcela.values()].map((p) => ({ parcela: p.parcela, trat: p.trat, bloque: p.bloque, valor: media(p.valores) }));
    return { ...m, obs, res: anova(obs, { bloques, alfa }) };
  });
  let auc = null;
  if (muestreos.length > 1) {
    const serie = new Map(); const inicio = Date.parse(muestreos[0].centro);
    muestreos.forEach((m) => m.obs.forEach((o) => {
      if (!serie.has(o.parcela)) serie.set(o.parcela, { ...o, puntos: [] });
      serie.get(o.parcela).puntos.push({ x: (Date.parse(m.centro) - inicio) / 86400000, y: o.valor });
    }));
    const completas = [...serie.values()].filter((p) => p.puntos.length === muestreos.length);
    if (completas.length >= 2) auc = { res: anova(completas.map((p) => ({ trat: p.trat, bloque: p.bloque, valor: areaBajoCurva(p.puntos) })), { bloques, alfa }), excluidas: serie.size - completas.length };
  }
  return { variable, puntos: puntos.length, muestreos, auc };
}
