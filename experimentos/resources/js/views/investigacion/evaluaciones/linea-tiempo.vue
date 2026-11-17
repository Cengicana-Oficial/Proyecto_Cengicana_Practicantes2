<template>
  <div class="lt">
    <!-- Series disponibles -->
    <aside class="lt-series">
      <h6><i class="fas fa-seedling"></i> Campo <small>evaluaciones</small></h6>
      <label v-for="s in fuentesCampo" :key="s.key" class="lt-op" :class="{ 'is-on': sel.includes(s.key) }">
        <input type="checkbox" :value="s.key" v-model="sel">
        <span><b>{{ s.nombre }}</b> <em>{{ s.unidad }}</em><small>{{ s.n }} datos · {{ s.fechas }} fecha(s)</small></span>
      </label>
      <p v-if="!fuentesCampo.length" class="lt-nada">Sin variables numericas con datos.</p>

      <h6><i class="fas fa-flask"></i> Laboratorio <small>analitos</small></h6>
      <p v-if="cargandoLab" class="lt-nada"><i class="fas fa-spinner fa-spin"></i> Buscando resultados...</p>
      <template v-else>
        <label v-for="s in fuentesLab" :key="s.key" class="lt-op" :class="{ 'is-on': sel.includes(s.key) }">
          <input type="checkbox" :value="s.key" v-model="sel">
          <span><b>{{ s.nombre }}</b> <em>{{ s.unidad }}</em><small>{{ s.tipo }} · {{ s.puntos.length }} resultados</small></span>
        </label>
        <p v-if="!fuentesLab.length" class="lt-nada">Sin resultados de laboratorio para este ensayo.</p>
      </template>
      <p class="lt-nota"><i class="fas fa-cloud-sun"></i> El clima del ensayo esta en <a :href="`${base}/investigacion/ensayos?ensayo=${ensayo.id}&tab=clima`">Ensayos › Clima</a>.</p>
    </aside>

    <section class="lt-main">
      <div class="lt-bar">
        <div class="lt-seg">
          <button v-for="a in agrupaciones" :key="a.id" type="button" :class="{ 'is-on': agrupar === a.id }" :title="a.ayuda" @click="agrupar = a.id">{{ a.label }}</button>
        </div>
        <span v-if="rango" class="lt-rango"><i class="fas fa-calendar-alt"></i> {{ fmt(rango.desde) }} – {{ fmt(rango.hasta) }}</span>
        <span v-if="agrupar !== 'media' && tratamientos.length" class="lt-leyenda">
          <span v-for="t in tratamientos" :key="t"><i :style="{ background: colorTrat(t) }"></i>{{ t }}</span>
        </span>
        <div class="lt-vista">
          <button type="button" :class="{ 'is-on': vista === 'graficos' }" title="Graficos" @click="vista = 'graficos'"><i class="fas fa-chart-line"></i></button>
          <button type="button" :class="{ 'is-on': vista === 'tabla' }" title="Tabla de datos" @click="vista = 'tabla'"><i class="fas fa-table"></i></button>
        </div>
      </div>

      <div v-if="!sel.length" class="lt-vacio">
        <i class="fas fa-hand-point-left"></i>
        <strong>Elija las series a comparar.</strong>
        <span>Cada serie va en su propio panel con el mismo eje de fechas; al pasar el cursor se marca la misma fecha en todos.</span>
      </div>

      <div v-else-if="vista === 'graficos'" class="lt-paneles" @mousemove="sincronizar" @mouseleave="limpiarHover">
        <div v-for="(p, i) in paneles" :key="p.key" class="lt-panel" :class="'is-' + p.fuente">
          <div class="lt-panel-cab">
            <span class="lt-fuente" :class="'is-' + p.fuente"><i :class="p.fuente === 'lab' ? 'fas fa-flask' : 'fas fa-seedling'"></i></span>
            <b>{{ p.nombre }}</b> <em>{{ p.unidad }}</em>
            <small v-if="p.tipo" class="lt-muted">· {{ p.tipo }}</small>
            <button type="button" class="lt-x" title="Quitar" @click="sel = sel.filter((k) => k !== p.key)">×</button>
          </div>
          <div v-if="!p.series.some((s) => s.data.length)" class="lt-panel-vacio">Sin datos con los filtros aplicados.</div>
          <highcharts v-else :ref="'ch' + i" :options="p.opciones"></highcharts>
        </div>
      </div>

      <div v-else class="lt-tablas">
        <div v-for="p in paneles" :key="p.key" class="lt-tabla-caja">
          <h6><span class="lt-fuente" :class="'is-' + p.fuente"><i :class="p.fuente === 'lab' ? 'fas fa-flask' : 'fas fa-seedling'"></i></span> {{ p.nombre }} <em>{{ p.unidad }}</em></h6>
          <div class="lt-tabla-scroll">
            <table class="table lt-tabla">
              <thead><tr><th>Fecha</th><th v-for="s in p.series" :key="s.name" class="text-right">{{ s.name }}</th></tr></thead>
              <tbody>
                <tr v-for="f in fechasTabla(p)" :key="f">
                  <td>{{ fmt(f) }}</td>
                  <td v-for="s in p.series" :key="s.name" class="text-right">{{ valorTabla(s, f, p) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script>
const DIA = 86400000;
const PALETA = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
const AGRUPACIONES = [
  { id: 'trat', label: 'Tratamiento', ayuda: 'promedio de las repeticiones de cada tratamiento' },
  { id: 'parcela', label: 'Parcela', ayuda: 'una linea por parcela, coloreada por su tratamiento' },
  { id: 'media', label: 'Media ensayo', ayuda: 'promedio de todas las parcelas' },
];

/**
 * Linea de tiempo de un ensayo: variables de campo (ya cargadas en Evaluaciones)
 * y analitos de laboratorio, en paneles con el mismo eje de fechas.
 */
export default {
  name: 'LineaTiempo',
  props: {
    ensayo: { type: Object, required: true },
    registros: { type: Array, default: () => [] }, // evaluaciones ya filtradas por la pagina
    variables: { type: Array, default: () => [] },
    parcelas: { type: Array, default: () => [] }, // parcelas del ensayo
    tratamientosEnsayo: { type: Array, default: () => [] }, // orden fijo de colores (el de la pagina)
    base: { type: String, default: '' },
  },
  data() {
    return {
      agrupaciones: AGRUPACIONES,
      agrupar: 'trat',
      vista: 'graficos',
      sel: [],
      lab: [],
      cargandoLab: false,
    };
  },
  computed: {
    varMap() { return new Map(this.variables.map((v) => [v.id, v])); },
    parcMap() { return new Map(this.parcelas.map((p) => [p.id, p])); },
    /* Puntos de campo: solo variables numericas con valor numerico. */
    puntosCampo() {
      const m = new Map();
      this.registros.forEach((e) => {
        const v = this.varMap.get(e.variable_id);
        const p = this.parcMap.get(e.parcela_id);
        const y = this.num(e.valor);
        if (!v || v.tipo !== 'Numerica' || !p || y === null) return;
        const k = `v:${v.id}`;
        if (!m.has(k)) m.set(k, []);
        m.get(k).push({ fecha: e.fecha, parcela: p.codigo, tratamiento: p.tratamiento, valor: y });
      });
      return m;
    },
    fuentesCampo() {
      return [...this.puntosCampo.entries()].map(([k, pts]) => {
        const v = this.varMap.get(Number(k.slice(2)));
        return { key: k, fuente: 'campo', nombre: v.nombre, unidad: v.unidad || '', decimales: v.decimales, puntos: pts, n: pts.length, fechas: new Set(pts.map((p) => p.fecha)).size };
      }).sort((a, b) => a.nombre.localeCompare(b.nombre, 'es'));
    },
    fuentesLab() {
      return this.lab.map((a) => ({ key: `a:${a.tipo}:${a.clave}`, fuente: 'lab', nombre: a.label, unidad: a.unidad || '', tipo: a.tipo, decimales: 2, puntos: a.puntos }));
    },
    fuenteMap() { return new Map([...this.fuentesCampo, ...this.fuentesLab].map((f) => [f.key, f])); },
    seleccionadas() { return this.sel.map((k) => this.fuenteMap.get(k)).filter(Boolean); },
    rango() {
      const fs = this.seleccionadas.flatMap((f) => f.puntos.map((p) => p.fecha));
      if (!fs.length) return null;
      fs.sort();
      return { desde: this.sumarDias(fs[0], -10), hasta: this.sumarDias(fs[fs.length - 1], 10) };
    },
    tratamientos() {
      const s = new Set();
      this.seleccionadas.forEach((f) => f.puntos.forEach((p) => s.add(p.tratamiento || 'Sin trat.')));
      return [...s].sort((a, b) => a.localeCompare(b, 'es', { numeric: true }));
    },
    paneles() {
      return this.seleccionadas.map((f) => {
        const series = this.series(f);
        return { ...f, series, opciones: this.opciones(f, series) };
      });
    },
  },
  watch: {
    'ensayo.id': {
      immediate: true,
      handler() {
        this.sel = this.fuentesCampo.slice().sort((a, b) => b.fechas - a.fechas).slice(0, 2).map((f) => f.key);
        this.cargarLab();
      },
    },
    // Si un filtro deja una serie sin datos, se quita de la seleccion.
    fuenteMap(m) { const s = this.sel.filter((k) => m.has(k) || k.startsWith('a:')); if (s.length !== this.sel.length) this.sel = s; },
  },
  methods: {
    num(s) {
      if (s === null || s === undefined) return null;
      const t = String(s).trim().replace(',', '.');
      return t !== '' && !Number.isNaN(Number(t)) ? Number(t) : null;
    },
    sumarDias(f, n) { return new Date(Date.parse(f) + n * DIA).toISOString().slice(0, 10); },
    fmt(s) { if (!s) return ''; const [y, m, d] = String(s).slice(0, 10).split('-'); return `${d}/${m}/${y}`; },
    colorTrat(t) {
      // Mismo orden de color que el resto de Evaluaciones (tratamientos del ensayo).
      let i = this.tratamientosEnsayo.indexOf(t);
      if (i < 0) i = this.tratamientos.indexOf(t);
      return PALETA[(i < 0 ? 0 : i) % PALETA.length];
    },
    cargarLab() {
      this.lab = [];
      this.cargandoLab = true;
      const id = this.ensayo.id;
      window.axios.get('/investigacion/evaluaciones/laboratorio', { params: { ensayo_id: id } })
        .then(({ data }) => { if (this.ensayo.id === id) this.lab = data.analitos; })
        .catch(() => { this.lab = []; })
        .finally(() => { this.cargandoLab = false; });
    },
    series(f) {
      const promedio = (lista) => {
        const m = new Map();
        lista.forEach((p) => { if (!m.has(p.fecha)) m.set(p.fecha, []); m.get(p.fecha).push(p.valor); });
        return [...m.entries()].sort((a, b) => a[0].localeCompare(b[0])).map(([fe, xs]) => ({ x: Date.parse(fe), y: Number((xs.reduce((a, b) => a + b, 0) / xs.length).toFixed(4)) }));
      };
      if (this.agrupar === 'media') return [{ name: 'Media del ensayo', color: '#3f7a12', data: promedio(f.puntos) }];
      if (this.agrupar === 'trat') {
        return this.tratamientos.map((t) => ({ name: t, color: this.colorTrat(t), data: promedio(f.puntos.filter((p) => (p.tratamiento || 'Sin trat.') === t)) })).filter((s) => s.data.length);
      }
      const porParcela = new Map();
      f.puntos.forEach((p) => { const k = p.parcela || 'sin parcela'; if (!porParcela.has(k)) porParcela.set(k, []); porParcela.get(k).push(p); });
      return [...porParcela.entries()].sort((a, b) => a[0].localeCompare(b[0], 'es', { numeric: true })).map(([parc, lista]) => {
        const t = lista[0].tratamiento || 'Sin trat.';
        return { name: `${parc} (${t})`, color: this.colorTrat(t), lineWidth: 1.5, data: promedio(lista) };
      });
    },
    opciones(f, series) {
      const dec = f.decimales !== null && f.decimales !== undefined ? f.decimales : 2;
      return {
        chart: { height: 220, backgroundColor: '#ffffff', spacing: [8, 8, 6, 4], style: { fontFamily: 'inherit' } },
        title: { text: null },
        credits: { enabled: false },
        // La leyenda de tratamientos es comun a todos los paneles (barra superior).
        legend: { enabled: false },
        exporting: { enabled: true, filename: `${this.ensayo.codigo}_${f.nombre}`.replace(/\s+/g, '_') },
        xAxis: {
          type: 'datetime', min: this.rango ? Date.parse(this.rango.desde) : null, max: this.rango ? Date.parse(this.rango.hasta) : null,
          crosshair: { color: '#CED2D5', width: 1 }, lineColor: '#CED2D5', tickColor: '#CED2D5', labels: { style: { color: '#6b7568', fontSize: '11px' } },
        },
        yAxis: { title: { text: f.unidad || null, style: { color: '#6b7568', fontSize: '11px' } }, gridLineColor: '#edf1ec', labels: { style: { color: '#6b7568', fontSize: '11px' } } },
        tooltip: { shared: true, xDateFormat: '%d/%m/%Y', valueDecimals: dec, valueSuffix: f.unidad ? ` ${f.unidad}` : '', backgroundColor: '#ffffff', borderColor: '#CED2D5', borderRadius: 8, shadow: false },
        plotOptions: {
          series: { animation: false, connectNulls: true, states: { inactive: { opacity: 0.35 } } },
          line: { lineWidth: 2, marker: { enabled: true, radius: 4, lineWidth: 2, lineColor: '#ffffff', symbol: 'circle' } },
        },
        series: series.map((s) => ({ type: 'line', ...s })),
      };
    },
    graficas() {
      return this.paneles.map((p, i) => { const r = this.$refs[`ch${i}`]; return r && r[0] && r[0].chart; }).filter(Boolean);
    },
    sincronizar(ev) {
      this.graficas().forEach((chart) => {
        try {
          const e = chart.pointer.normalize(ev);
          const pt = chart.series.filter((s) => s.visible).map((s) => s.searchPoint(e, true)).filter(Boolean)[0];
          if (!pt) return;
          const mismos = chart.series.filter((s) => s.visible).map((s) => s.points.find((q) => q.x === pt.x)).filter(Boolean);
          if (mismos.length) { chart.tooltip.refresh(mismos); chart.xAxis[0].drawCrosshair(e, mismos[0]); }
        } catch (err) { /* grafica sin puntos */ }
      });
    },
    limpiarHover() {
      this.graficas().forEach((chart) => { try { chart.tooltip.hide(0); chart.xAxis[0].hideCrosshair(); } catch (e) { /* nada */ } });
    },
    fechasTabla(p) {
      const s = new Set();
      p.series.forEach((x) => x.data.forEach((d) => s.add(new Date(d.x).toISOString().slice(0, 10))));
      return [...s].sort();
    },
    valorTabla(s, f, p) {
      const d = s.data.find((x) => x.x === Date.parse(f));
      if (!d) return '—';
      const dec = p.decimales !== null && p.decimales !== undefined ? p.decimales : 2;
      return Number(d.y).toLocaleString('es-GT', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    },
  },
};
</script>

<style scoped>
.lt {
  --verde: #73BC25; --verde-2: #A3D300; --amarillo: #FFCC00; --naranja: #FF6B00; --gris: #CED2D5;
  --verde-osc: #1f5632; --tinta: #1e2a1a; --suave: #6b7568; --borde: #e3e7e1;
  display: grid; grid-template-columns: 260px minmax(0, 1fr); gap: 14px; align-items: start;
}
.lt-muted { color: var(--suave); }
.lt-series { position: sticky; top: 12px; max-height: calc(100vh - 24px); overflow-y: auto; padding: 10px; border: 1px solid var(--borde); border-radius: 12px; }
.lt-series h6 { display: flex; align-items: center; gap: 6px; margin: 12px 0 6px; color: var(--verde-osc); font-size: 13px; font-weight: 800; }
.lt-series h6:first-child { margin-top: 0; }
.lt-series h6 small { color: var(--suave); font-weight: 500; }
.lt-series h6 i { color: var(--verde); }
.lt-op { display: flex; gap: 8px; align-items: flex-start; margin: 0 0 4px; padding: 6px 8px; border: 1px solid transparent; border-radius: 9px; cursor: pointer; font-weight: 400; }
.lt-op:hover { background: #f7fbf1; }
.lt-op.is-on { border-color: color-mix(in srgb, var(--verde) 45%, #fff); background: #f4faec; }
.lt-op input { margin-top: 3px; accent-color: var(--verde); }
.lt-op b { color: var(--tinta); font-size: 12.5px; }
.lt-op em { color: var(--suave); font-size: 11px; font-style: normal; }
.lt-op small { display: block; color: var(--suave); font-size: 11px; }
.lt-nada { margin: 0 0 6px; padding: 6px 8px; border-radius: 8px; background: #fafbf9; color: var(--suave); font-size: 11.5px; }
.lt-nota { margin: 12px 0 0; padding: 7px 9px; border-radius: 8px; background: #f4faec; color: #2e5c0e; font-size: 11.5px; }
.lt-nota a { color: #3f7a12; font-weight: 700; }
.lt-main { min-width: 0; }
.lt-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 10px; color: var(--verde-osc); font-size: 12.5px; font-weight: 600; }
.lt-seg, .lt-vista { display: inline-flex; padding: 3px; border: 1px solid var(--borde); border-radius: 9px; background: #f3f5f1; }
.lt-seg button, .lt-vista button { padding: 3px 11px; border: 0; border-radius: 6px; background: transparent; color: var(--suave); font-size: 12px; font-weight: 600; }
.lt-seg button.is-on, .lt-vista button.is-on { background: #fff; color: var(--verde-osc); box-shadow: 0 1px 3px rgba(30, 42, 26, .12); }
.lt-vista { margin-left: auto; }
.lt-rango i { color: var(--verde); }
.lt-leyenda { display: flex; flex-wrap: wrap; gap: 10px; color: var(--tinta); font-size: 12px; }
.lt-leyenda i { display: inline-block; width: 10px; height: 10px; margin-right: 4px; border-radius: 50%; vertical-align: -1px; }
.lt-vacio { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 50px 16px; border: 1px dashed var(--gris); border-radius: 12px; color: var(--suave); text-align: center; }
.lt-vacio > i { color: var(--gris); font-size: 26px; }
.lt-vacio strong { color: var(--tinta); }
.lt-paneles { display: flex; flex-direction: column; gap: 10px; }
.lt-panel { padding: 8px 10px 4px; border: 1px solid var(--borde); border-left: 4px solid var(--verde); border-radius: 12px; }
.lt-panel.is-lab { border-left-color: var(--amarillo); }
.lt-panel-cab { display: flex; align-items: center; gap: 6px; color: var(--tinta); font-size: 13px; }
.lt-panel-cab em { color: var(--suave); font-size: 11.5px; font-style: normal; }
.lt-panel-cab small { font-size: 11px; }
.lt-fuente { display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 6px; font-size: 10.5px; }
.lt-fuente.is-campo { background: color-mix(in srgb, var(--verde) 18%, #fff); color: #3f7a12; }
.lt-fuente.is-lab { background: color-mix(in srgb, var(--amarillo) 28%, #fff); color: #7d6100; }
.lt-x { margin-left: auto; border: 0; background: transparent; color: var(--suave); font-size: 20px; line-height: 1; }
.lt-x:hover { color: var(--naranja); }
.lt-panel-vacio { padding: 26px; color: var(--suave); font-size: 12.5px; text-align: center; }
.lt-tablas { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 10px; }
.lt-tabla-caja { padding: 10px; border: 1px solid var(--borde); border-radius: 12px; }
.lt-tabla-caja h6 { display: flex; align-items: center; gap: 6px; margin: 0 0 8px; color: var(--verde-osc); font-size: 13px; font-weight: 800; }
.lt-tabla-caja h6 em { color: var(--suave); font-size: 11px; font-style: normal; font-weight: 500; }
.lt-tabla-scroll { max-height: 320px; overflow: auto; }
.lt-tabla { margin: 0; font-size: 12px; font-variant-numeric: tabular-nums; }
.lt-tabla th { position: sticky; top: 0; background: #f7f9f5; color: var(--suave); font-size: 11px; }
@media (max-width: 900px) {
  .lt { grid-template-columns: 1fr; }
  .lt-series { position: static; max-height: none; }
}
</style>
