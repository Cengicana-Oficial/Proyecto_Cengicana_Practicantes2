// Zafra como eje de tiempo de proyectos y ensayos. El año agricola va del 1 de noviembre al
// 31 de octubre (la zafra de corte es nov–may, como en Clima): "2025-2026" = 2025-11-01 a 2026-10-31.
const MES_INICIO = 11;

const dia = (f) => (f ? String(f).slice(0, 10) : null);
const hoy = () => new Date().toISOString().slice(0, 10);

/** Zafra de una fecha 'YYYY-MM-DD' → '2025-2026'. */
export function zafraDe(fecha) {
  const f = dia(fecha);
  if (!f) return null;
  const y = Number(f.slice(0, 4));
  const ini = Number(f.slice(5, 7)) >= MES_INICIO ? y : y - 1;
  return `${ini}-${ini + 1}`;
}

export const zafraActual = () => zafraDe(hoy());

export function rangoZafra(z) {
  const ini = Number(String(z).slice(0, 4));
  const mes = String(MES_INICIO).padStart(2, '0');
  const fin = new Date(Date.UTC(ini + 1, MES_INICIO - 1, 0)).toISOString().slice(0, 10);
  return { desde: `${ini}-${mes}-01`, hasta: fin };
}

export const zafraSiguiente = (z) => { const i = Number(String(z).slice(0, 4)) + 1; return `${i}-${i + 1}`; };

/** Zafras consecutivas entre la primera y la ultima (incluidas). */
export function zafrasEntre(primera, ultima) {
  const lista = [];
  for (let z = primera; z && z <= ultima; z = zafraSiguiente(z)) lista.push(z);
  return lista;
}

/** Inicio y fin de un ensayo: siembra/corte → cosecha; sin fechas se usan registro y cierre. */
export function periodoEnsayo(e) {
  // Las pruebas tecnicas van de su fecha de inicio a la de fin (no tienen siembra ni cosecha).
  const inicio = dia(e.fecha_siembra) || dia(e.fecha_inicio) || dia(e.created_at);
  const fin = dia(e.fecha_cosecha) || (e.tipo_estudio === 'prueba' && e.estado === 'Finalizado' ? dia(e.fecha_fin) : null)
    || (e.estado === 'Finalizado' ? dia(e.updated_at) : null);
  return { inicio, fin, planificado: e.estado === 'Planificado' };
}

export function periodoProyecto(p) {
  return { inicio: dia(p.inicio) || dia(p.created_at), fin: p.estado === 'Finalizado' ? dia(p.fin) || dia(p.updated_at) : null };
}

/**
 * Situacion en una zafra: 'nuevo' (empieza en ella), 'continua' (viene de antes y sigue),
 * 'finaliza' (termina en ella), 'planificado' (empieza despues de hoy) o null (no activo).
 */
export function situacionEnZafra({ inicio, fin, planificado }, z) {
  if (!inicio || !z) return null;
  const r = rangoZafra(z);
  if (inicio > r.hasta || (fin && fin < r.desde)) return null;
  if (planificado || inicio > hoy()) return 'planificado';
  if (inicio >= r.desde) return fin && fin <= r.hasta ? 'nuevo-finaliza' : 'nuevo';
  return fin && fin <= r.hasta ? 'finaliza' : 'continua';
}

export const activoEnZafra = (periodo, z) => situacionEnZafra(periodo, z) !== null;

export const SITUACIONES = {
  nuevo: { texto: 'Nuevo', color: '#3f7a12', fondo: '#e3f2d1' },
  continua: { texto: 'Continúa', color: '#527443', fondo: '#f0f6e8' },
  finaliza: { texto: 'Finaliza', color: '#2a78d6', fondo: '#e4effb' },
  'nuevo-finaliza': { texto: 'Nuevo y finaliza', color: '#2a78d6', fondo: '#e3f2d1' },
  planificado: { texto: 'Planificado', color: '#8a6a00', fondo: '#fff4cc' },
};
