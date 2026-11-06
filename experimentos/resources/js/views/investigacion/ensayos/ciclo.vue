<template>
  <div class="cc" :class="{ 'is-compacto': compacto }">
    <div class="cc-leyenda" aria-label="Leyenda del cronograma">
      <span v-for="s in estados" :key="s.id"><i class="cc-ley-barra" :style="{ background: s.color }"></i>{{ s.id }}</span>
      <span><i class="cc-ley-ev"></i>Evaluacion</span>
      <span><i class="cc-ley-mu"></i>Muestreo</span>
      <span v-if="ensayos.some((e) => ((e.hitos || {}).programadas || []).length)"><i class="cc-ley-plan"></i>Actividad programada</span>
      <span><i class="fas fa-flag cc-ley-flag"></i>Cosecha prevista</span>
      <span v-if="ensayos.some((e) => (e.ciclos || []).length > 1)"><i class="fas fa-flag-checkered cc-ley-flag"></i>Cosecha de un ciclo</span>
      <span><i class="cc-ley-hoy"></i>Hoy</span>
    </div>

    <div v-if="!rango" class="cc-vacio">
      <i class="fas fa-calendar-alt"></i>
      <b>No hay fechas para dibujar el cronograma.</b>
      <small>Registre la fecha de siembra o corte y la cosecha prevista de los ensayos, o sus evaluaciones y muestreos.</small>
    </div>

    <div v-else class="cc-tabla">
      <!-- Eje de meses -->
      <div class="cc-fila cc-eje">
        <div class="cc-label"><small>{{ ensayos.length }} ensayo(s)</small></div>
        <div class="cc-pista">
          <span v-for="m in meses" :key="m.t" class="cc-mes" :class="{ 'is-anio': m.anio }" :style="{ left: pos(m.t) + '%' }">{{ m.texto }}</span>
          <span class="cc-hoy-tag" :style="{ left: pos(hoy) + '%' }">Hoy</span>
        </div>
      </div>

      <template v-for="g in grupos">
        <div v-if="!compacto" :key="'g' + g.id" class="cc-grupo">
          <span class="cc-cod">{{ g.codigo }}</span> {{ g.nombre }}
          <small>· {{ g.items.length }} ensayo(s)</small>
        </div>
        <div
          v-for="e in g.items" :key="e.id" class="cc-fila" tabindex="0" role="button"
          :aria-label="`Abrir ensayo ${e.codigo}`" @click="!compacto && $emit('ver', e)" @keydown.enter="!compacto && $emit('ver', e)"
        >
          <div class="cc-label">
            <b class="cc-cod">{{ e.codigo }}</b>
            <small :title="e.nombre">{{ e.nombre || e.variedad || 'Sin nombre' }}</small>
            <small class="cc-edad" :class="{ 'is-alerta': alerta(e) }">{{ resumenCiclo(e) }}</small>
          </div>
          <div class="cc-pista">
            <span v-for="m in meses" :key="m.t" class="cc-grid" :class="{ 'is-anio': m.anio }" :style="{ left: pos(m.t) + '%' }"></span>
            <span class="cc-hoy" :style="{ left: pos(hoy) + '%' }"></span>

            <!-- Ciclos anteriores en las mismas parcelas (plantia, soca 1...), cerrados con su cosecha -->
            <template v-for="c in ciclosPrevios(e)">
              <div
                :key="'c' + c.id" class="cc-barra is-previo"
                :style="{ left: c.izq + '%', width: c.ancho + '%', '--c': color('Finalizado') }" :title="c.titulo"
              ><span class="cc-barra-txt">{{ c.ciclo }}</span></div>
              <i
                v-if="c.cosecha" :key="'cf' + c.id" class="fas fa-flag-checkered cc-flag is-real"
                :style="{ left: pos(dia(c.cosecha)) + '%' }" :title="`${c.ciclo} cosechado ${fecha(c.cosecha)}`"
              ></i>
            </template>

            <!-- Periodo de cultivo actual: siembra/corte -> cosecha (real, prevista o hoy si no hay) -->
            <div
              v-if="barra(e)" class="cc-barra" :class="{ 'is-abierta': barra(e).abierta }"
              :style="{ left: barra(e).izq + '%', width: barra(e).ancho + '%', '--c': color(e.estado) }"
              :title="barra(e).titulo"
            ><span v-if="(e.ciclos || []).length > 1" class="cc-barra-txt">{{ e.ciclo }}</span></div>
            <span v-else class="cc-sin">{{ e.tipo_estudio === 'prueba' ? 'Sin fecha de inicio' : 'Sin fecha de siembra o corte' }}</span>

            <i
              v-if="e.fecha_cosecha_prevista && !e.fecha_cosecha" class="fas fa-flag cc-flag"
              :class="{ 'is-vencida': dia(e.fecha_cosecha_prevista) < hoy && e.estado !== 'Finalizado' }"
              :style="{ left: pos(dia(e.fecha_cosecha_prevista)) + '%' }"
              :title="`Cosecha prevista ${fecha(e.fecha_cosecha_prevista)}`"
            ></i>
            <i
              v-if="e.fecha_cosecha" class="fas fa-flag-checkered cc-flag is-real"
              :style="{ left: pos(dia(e.fecha_cosecha)) + '%' }" :title="`Cosechado ${fecha(e.fecha_cosecha)}`"
            ></i>

            <span
              v-for="h in (e.hitos || {}).evaluaciones || []" :key="'ev' + h.fecha" class="cc-ev"
              :style="{ left: pos(dia(h.fecha)) + '%' }"
              :title="`Evaluacion ${fecha(h.fecha)}: ${h.n} valores de ${h.variables} variable(s)${dds(e, h.fecha)}`"
            ></span>
            <span
              v-for="(p, i) in (e.hitos || {}).programadas || []" :key="'pl' + i" class="cc-plan" :class="{ 'is-atrasada': dia(p.fecha) < hoy }"
              :style="{ left: pos(dia(p.fecha)) + '%' }"
              :title="`${dia(p.fecha) < hoy ? 'Atrasada' : 'Programada'} ${fecha(p.fecha)}: ${p.tipo}${p.descripcion ? ' · ' + p.descripcion : ''}`"
            ></span>
            <span
              v-for="h in (e.hitos || {}).muestreos || []" :key="'mu' + h.fecha + h.tipo" class="cc-mu"
              :style="{ left: pos(dia(h.fecha)) + '%' }"
              :title="`Muestreo ${fecha(h.fecha)}: ${h.n} muestra(s) de ${h.tipo}${dds(e, h.fecha)}`"
            ></span>
          </div>
        </div>
      </template>
    </div>
    <p v-if="!compacto" class="cc-nota">La barra va de la siembra o el corte a la cosecha; si aun no hay cosecha prevista llega hasta hoy (borde punteado). Pase el cursor sobre cada marca para ver el detalle y haga clic en una fila para abrir el ensayo.</p>
  </div>
</template>

<script>
const DIA = 86400000;
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

export default {
  name: 'EnsayosCiclo',
  props: {
    ensayos: { type: Array, default: () => [] },
    // [{ id, color, texto }] paleta validada de estados
    estados: { type: Array, default: () => [] },
    // Dentro del detalle de un ensayo: sin encabezado de proyecto, sin nota y sin clic.
    compacto: { type: Boolean, default: false },
  },
  computed: {
    hoy() { const d = new Date(); return Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()); },
    rango() {
      const fechas = [];
      this.ensayos.forEach((e) => {
        [e.fecha_siembra, e.fecha_cosecha_prevista, e.fecha_cosecha, e.fecha_inicio, e.fecha_fin].forEach((f) => f && fechas.push(this.dia(f)));
        (e.ciclos || []).forEach((c) => c.fecha_inicio && fechas.push(this.dia(c.fecha_inicio)));
        const h = e.hitos || {};
        [...(h.evaluaciones || []), ...(h.muestreos || []), ...(h.programadas || [])].forEach((x) => fechas.push(this.dia(x.fecha)));
      });
      if (!fechas.length) return null;
      fechas.push(this.hoy);
      const min = new Date(Math.min(...fechas));
      const max = new Date(Math.max(...fechas));
      // Meses completos, con un mes de margen a cada lado.
      const ini = Date.UTC(min.getUTCFullYear(), min.getUTCMonth() - 1, 1);
      const fin = Date.UTC(max.getUTCFullYear(), max.getUTCMonth() + 2, 1);
      return { ini, fin };
    },
    meses() {
      if (!this.rango) return [];
      const out = [];
      const d = new Date(this.rango.ini);
      const total = (this.rango.fin - this.rango.ini) / (30.44 * DIA);
      const paso = total > 30 ? 3 : total > 15 ? 2 : 1;
      for (let i = 0; Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + i, 1) < this.rango.fin; i += 1) {
        const t = Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + i, 1);
        const m = new Date(t).getUTCMonth();
        if (i % paso === 0 || m === 0) out.push({ t, anio: m === 0, texto: m === 0 || i === 0 ? `${MESES[m]} ${String(new Date(t).getUTCFullYear()).slice(2)}` : MESES[m] });
      }
      return out;
    },
    grupos() {
      const g = {};
      this.ensayos.forEach((e) => {
        const p = e.proyecto || {};
        const k = p.id || 0;
        if (!g[k]) g[k] = { id: k, codigo: p.codigo || '—', nombre: p.nombre || 'Sin proyecto', items: [] };
        g[k].items.push(e);
      });
      return Object.values(g).sort((a, b) => a.codigo.localeCompare(b.codigo, 'es'));
    },
  },
  methods: {
    dia(f) { return Date.parse(String(f).slice(0, 10)); },
    pos(t) { return Math.max(0, Math.min(100, ((t - this.rango.ini) / (this.rango.fin - this.rango.ini)) * 100)); },
    fecha(f) { const [y, m, d] = String(f).slice(0, 10).split('-'); return `${d}/${m}/${y}`; },
    color(estado) { const s = this.estados.find((x) => x.id === estado); return s ? s.color : '#CED2D5'; },
    /** Ciclo (de ensayo_ciclos) al que pertenece una fecha: el ultimo que inicio antes o ese dia. */
    cicloDe(e, f) {
      const t = this.dia(f);
      const lista = (e.ciclos || []).filter((c) => this.dia(c.fecha_inicio) <= t);
      return lista.length ? lista[lista.length - 1] : null;
    },
    dds(e, f) {
      const c = this.cicloDe(e, f);
      const inicio = c ? c.fecha_inicio : e.fecha_siembra;
      const ciclo = c ? c.ciclo : e.ciclo;
      if (!inicio) return '';
      const d = Math.round((this.dia(f) - this.dia(inicio)) / DIA);
      const nombre = (e.ciclos || []).length > 1 && ciclo ? ` (${ciclo})` : '';
      return d >= 0 ? ` · ${d} dias ${ciclo && ciclo !== 'Plantia' ? 'despues del corte' : 'despues de siembra'}${nombre}` : '';
    },
    /** Barras de los ciclos ya cerrados (todos menos el actual). */
    ciclosPrevios(e) {
      const ciclos = e.ciclos || [];
      return ciclos.slice(0, -1).map((c, i) => {
        const a = this.dia(c.fecha_inicio);
        const b = this.dia(c.fecha_cosecha || ciclos[i + 1].fecha_inicio);
        const izq = this.pos(a);
        return {
          id: c.id, ciclo: c.ciclo || `Ciclo ${c.orden}`, cosecha: c.fecha_cosecha, izq, ancho: Math.max(0.6, this.pos(b) - izq),
          titulo: `${c.ciclo || 'Ciclo ' + c.orden}: ${this.fecha(c.fecha_inicio)} → ${c.fecha_cosecha ? 'cosechado ' + this.fecha(c.fecha_cosecha) : this.fecha(ciclos[i + 1].fecha_inicio)}`,
        };
      });
    },
    barra(e) {
      // Prueba tecnica: barra del inicio al fin de la prueba (sin siembra ni cosecha).
      if (e.tipo_estudio === 'prueba') {
        if (!e.fecha_inicio) return null;
        const a = this.dia(e.fecha_inicio);
        const b = e.fecha_fin ? this.dia(e.fecha_fin) : Math.max(this.hoy, a);
        const izq = this.pos(a);
        return { izq, ancho: Math.max(0.6, this.pos(b) - izq), abierta: !e.fecha_fin,
          titulo: `${e.subtipo || 'Prueba técnica'}: ${this.fecha(e.fecha_inicio)} → ${e.fecha_fin ? this.fecha(e.fecha_fin) : 'sin fecha de fin'}` };
      }
      if (!e.fecha_siembra) return null;
      const a = this.dia(e.fecha_siembra);
      const finReal = e.fecha_cosecha ? this.dia(e.fecha_cosecha) : null;
      const finPrev = e.fecha_cosecha_prevista ? this.dia(e.fecha_cosecha_prevista) : null;
      const b = finReal || finPrev || Math.max(this.hoy, a);
      const izq = this.pos(a);
      return {
        izq,
        ancho: Math.max(0.6, this.pos(b) - izq),
        abierta: !finReal && !finPrev,
        titulo: `${e.ciclo || 'Ciclo'}: ${this.fecha(e.fecha_siembra)} → ${finReal ? 'cosechado ' + this.fecha(e.fecha_cosecha) : finPrev ? 'cosecha prevista ' + this.fecha(e.fecha_cosecha_prevista) : 'sin cosecha prevista'}`,
      };
    },
    resumenCiclo(e) {
      if (e.tipo_estudio === 'prueba') {
        if (!e.fecha_inicio) return e.subtipo || 'Prueba técnica';
        const dias = Math.round(((e.fecha_fin ? Math.min(this.dia(e.fecha_fin), this.hoy) : this.hoy) - this.dia(e.fecha_inicio)) / DIA);
        return [e.subtipo || 'Prueba técnica', dias >= 0 ? `${dias} días` : `inicia en ${-dias} días`].join(' · ');
      }
      if (!e.fecha_siembra) return e.ciclo || 'Edad sin calcular';
      const dias = Math.round(((e.fecha_cosecha ? this.dia(e.fecha_cosecha) : this.hoy) - this.dia(e.fecha_siembra)) / DIA);
      const n = (e.ciclos || []).length;
      const partes = [n > 1 ? `${e.ciclo} (ciclo ${n})` : e.ciclo, dias >= 0 ? `${(dias / 30.44).toFixed(1)} meses` : `siembra en ${-dias} dias`];
      if (e.fecha_cosecha) partes.push('cosechado');
      else if (e.fecha_cosecha_prevista) {
        const f = Math.round((this.dia(e.fecha_cosecha_prevista) - this.hoy) / DIA);
        partes.push(f >= 0 ? `cosecha en ${f} d` : `cosecha vencida ${-f} d`);
      }
      return partes.filter(Boolean).join(' · ');
    },
    alerta(e) {
      return !e.fecha_cosecha && e.estado !== 'Finalizado' && e.fecha_cosecha_prevista
        && this.dia(e.fecha_cosecha_prevista) - this.hoy <= 30 * DIA;
    },
  },
};
</script>

<style scoped>
.cc { background: #fff; border: 1px solid #e3e7e4; border-radius: 12px; padding: 14px 16px 10px; }
.cc-leyenda { display: flex; flex-wrap: wrap; gap: 6px 16px; margin-bottom: 12px; font-size: 11.5px; color: #5f6b66; }
.cc-leyenda span { display: inline-flex; align-items: center; gap: 6px; }
.cc-ley-barra { width: 18px; height: 8px; border-radius: 3px; }
.cc-ley-ev { width: 9px; height: 9px; border-radius: 50%; background: #1f6f3a; border: 2px solid #fff; box-shadow: 0 0 0 1px #1f6f3a; }
.cc-ley-mu { width: 9px; height: 9px; background: #FF6B00; transform: rotate(45deg); border: 1.5px solid #fff; box-shadow: 0 0 0 1px #c24f00; }
.cc-ley-flag { color: #3f4a44; font-size: 11px; }
.cc-ley-hoy { width: 2px; height: 12px; background: #FF6B00; }

.cc-tabla { overflow-x: auto; }
.cc-fila { display: grid; grid-template-columns: 240px minmax(560px, 1fr); align-items: center; min-height: 56px; border-top: 1px solid #f0f2f1; cursor: pointer; }
.cc-fila:hover { background: #fafcf7; }
.cc-fila:focus-visible { outline: 3px solid #A3D300; outline-offset: -3px; }
.cc-eje { min-height: 26px; border-top: 0; cursor: default; }
.cc-eje:hover { background: none; }
.cc-label { padding: 6px 12px 6px 4px; min-width: 0; }
.cc-label b, .cc-label small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cc-label small { color: #5f6b66; font-size: 11.5px; }
.cc-edad { color: #1f2a1a !important; }
.cc-edad.is-alerta { color: #b84d00 !important; font-weight: 600; }
.cc-cod { font-family: 'Space Grotesk', sans-serif; font-size: 12px; font-weight: 700; letter-spacing: .03em; color: #3f7a12; }
.cc-grupo { padding: 8px 4px 4px; border-top: 1px solid #e3e7e4; font-size: 12.5px; font-weight: 600; color: #1f2a1a; background: #f7faf3; }
.cc-grupo small { color: #5f6b66; font-weight: 400; }

.cc-pista { position: relative; height: 100%; min-height: 26px; }
.cc-mes { position: absolute; top: 4px; transform: translateX(-50%); font-size: 10.5px; color: #5f6b66; white-space: nowrap; }
.cc-mes.is-anio { font-weight: 700; color: #1f2a1a; }
.cc-hoy-tag { position: absolute; top: 4px; transform: translateX(-50%); padding: 0 5px; border-radius: 4px; background: #FF6B00; color: #fff; font-size: 10px; font-weight: 700; }
.cc-grid { position: absolute; top: 0; bottom: 0; width: 1px; background: #f0f2f1; }
.cc-grid.is-anio { background: #d9dfda; }
.cc-hoy { position: absolute; top: 0; bottom: 0; width: 2px; margin-left: -1px; background: #FF6B00; opacity: .8; z-index: 2; }
.cc-barra { position: absolute; top: 50%; height: 12px; margin-top: -6px; border-radius: 4px; background: color-mix(in srgb, var(--c) 30%, #fff); border: 1.5px solid var(--c); z-index: 1; }
.cc-barra.is-abierta { border-right-style: dashed; border-top-right-radius: 0; border-bottom-right-radius: 0; }
.cc-barra.is-previo { opacity: .85; }
.cc-barra-txt { position: absolute; left: 4px; right: 4px; top: -15px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; font-size: 9.5px; font-weight: 700; color: #3f4a44; pointer-events: none; }
.cc-sin { position: absolute; top: 50%; left: 8px; transform: translateY(-50%); font-size: 11px; font-style: italic; color: #8a9396; }
.cc-ev { position: absolute; top: 50%; width: 10px; height: 10px; margin: -5px 0 0 -5px; border-radius: 50%; background: #1f6f3a; border: 2px solid #fff; box-shadow: 0 0 0 1px #1f6f3a; z-index: 3; }
.cc-mu { position: absolute; top: 22%; width: 10px; height: 10px; margin: -5px 0 0 -5px; background: #FF6B00; border: 1.5px solid #fff; box-shadow: 0 0 0 1px #c24f00; transform: rotate(45deg); z-index: 4; }
.cc-plan { position: absolute; top: 78%; width: 10px; height: 10px; margin: -5px 0 0 -5px; border-radius: 2px; background: #fff; border: 2px solid #2a78d6; z-index: 4; }
.cc-plan.is-atrasada { border-color: #c24f00; background: #fff0e6; }
.cc-ley-plan { width: 9px; height: 9px; border-radius: 2px; border: 2px solid #2a78d6; background: #fff; }
.cc-ev:hover, .cc-mu:hover { box-shadow: 0 0 0 3px rgba(31, 111, 58, .3); }
.cc-flag { position: absolute; top: 4px; margin-left: -2px; font-size: 12px; color: #3f4a44; z-index: 3; }
.cc-flag.is-vencida { color: #c24f00; }
.cc-flag.is-real { color: #1f2a1a; }
.cc-vacio { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 30px 10px; color: #5f6b66; text-align: center; }
.cc-vacio i { font-size: 22px; color: #73BC25; }
.cc-vacio b { color: #1f2a1a; }
.cc-nota { margin: 10px 0 0; font-size: 11.5px; color: #5f6b66; }
.cc.is-compacto { padding: 10px 12px 6px; }
.cc.is-compacto .cc-fila { cursor: default; grid-template-columns: 170px minmax(460px, 1fr); }
.cc.is-compacto .cc-fila:hover { background: none; }
.cc.is-compacto .cc-leyenda { margin-bottom: 6px; }
@media (max-width: 640px) {
  .cc-fila { grid-template-columns: 150px minmax(480px, 1fr); }
}
</style>
