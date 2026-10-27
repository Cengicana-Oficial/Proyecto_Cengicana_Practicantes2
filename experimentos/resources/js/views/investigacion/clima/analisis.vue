<template>
  <div class="an">
    <!-- ============ Filtros ============ -->
    <div class="an-filtros">
      <div class="an-f">
        <label>Variable</label>
        <select class="form-control form-control-sm" v-model="variable">
          <option v-for="v in variables" :key="v.id" :value="v.id">{{ v.nombre }} ({{ v.unidad }})</option>
        </select>
      </div>
      <div class="an-f" v-if="variable === 'gdd'">
        <label>T base (°C)</label>
        <input type="number" min="0" max="40" step="0.5" class="form-control form-control-sm an-tbase" v-model.number="tbase">
      </div>
      <div class="an-f">
        <label>Agrupar</label>
        <div class="an-seg">
          <button type="button" :class="{ 'is-on': agrupar === 'semana' }" @click="agrupar = 'semana'">Semana</button>
          <button type="button" :class="{ 'is-on': agrupar === 'mes' }" @click="agrupar = 'mes'">Mes</button>
        </div>
      </div>
      <div class="an-f an-f-ancho">
        <label>Estrato</label>
        <div class="an-chips">
          <button v-for="e in estratosTodos" :key="e" type="button" :class="{ 'is-on': estratosSel.includes(e) }" :style="{ '--c': colorEstrato(e) }" @click="alternar(estratosSel, e)">
            {{ e === 'SIN ESTRATO' ? '(Sin estrato)' : e }} <small>{{ cuentaEstrato(e) }}</small>
          </button>
        </div>
      </div>
      <div class="an-f">
        <label>Zona</label>
        <div class="an-chips">
          <button v-for="z in zonasTodas" :key="z" type="button" :class="{ 'is-on': zonasSel.includes(z) }" @click="alternar(zonasSel, z)">{{ z === 'SIN ZONA' ? '(Sin zona)' : z }}</button>
        </div>
      </div>
      <div class="an-f an-f-ancho">
        <label>Años <small>(max. 8)</small></label>
        <div class="an-chips">
          <button v-for="a in aniosTodos" :key="a" type="button" :class="{ 'is-on': aniosSel.includes(a) }" :style="aniosSel.includes(a) ? { '--c': colorAnio(a) } : {}" @click="alternarAnio(a)">{{ a }}</button>
        </div>
      </div>
      <div class="an-f an-f-ancho">
        <label>
          Estaciones <small>({{ estacionesSel.length }} de {{ estacionesFiltro.length }} del filtro)</small>
          <a href="#" @click.prevent="verEstaciones = !verEstaciones">{{ verEstaciones ? 'ocultar' : 'elegir' }}</a>
        </label>
        <div v-if="verEstaciones" class="an-est">
          <label v-for="s in estacionesFiltro" :key="s.id"><input type="checkbox" :checked="!excluidas.includes(s.id)" @change="alternarExcluir(s.id)"> {{ s.nombre }} <small>{{ s.estrato || '—' }}</small></label>
        </div>
      </div>
      <div class="an-f an-f-btn">
        <button type="button" class="btn btn-success" :disabled="cargando || !!errorFiltro" @click="analizar">
          <i :class="cargando ? 'fas fa-spinner fa-spin' : 'fas fa-chart-line'"></i> Analizar
        </button>
      </div>
    </div>
    <p v-if="errorFiltro" class="an-error"><i class="fas fa-exclamation-circle"></i> {{ errorFiltro }}</p>

    <!-- ============ Resultados ============ -->
    <div v-if="cargando" class="an-vacio"><i class="fas fa-spinner fa-spin"></i> Calculando...</div>
    <div v-else-if="error" class="an-vacio is-error"><i class="fas fa-exclamation-circle"></i> {{ error }}</div>
    <div v-else-if="!r" class="an-vacio">
      <i class="fas fa-chart-area"></i>
      Elija variable, estratos y años, y pulse <b>Analizar</b>.
    </div>
    <template v-else>
      <p v-if="r.sync && r.sync.error" class="an-aviso"><i class="fas fa-exclamation-triangle"></i> API del ICC: {{ r.sync.error }}</p>

      <div class="an-resumen">
        <div v-for="a in r.resumen_anio" :key="a.anio" class="an-kpi" :style="{ '--c': colorAnio(a.anio) }">
          <span>{{ a.anio }}</span>
          <strong>{{ num(a.valor, meta.dec) }} <small>{{ meta.unidadAnual }}</small></strong>
          <small :class="{ 'is-bajo': a.cobertura < 80 }">{{ a.estaciones }} est. · {{ a.cobertura }} % de datos</small>
        </div>
      </div>

      <div class="an-grid">
        <div class="an-card"><highcharts :options="chartAnios"></highcharts></div>
        <div class="an-card"><highcharts :options="chartSegundo"></highcharts></div>
        <div class="an-card"><highcharts :options="chartEstratos"></highcharts></div>
        <div class="an-card"><highcharts :options="chartEstratoAnio"></highcharts></div>
      </div>
      <div class="an-card mt-3"><highcharts :options="chartEstaciones"></highcharts></div>

      <p class="an-nota">
        <i class="fas fa-info-circle"></i>
        {{ meta.sumable ? 'Valores sumados' : 'Valores promediados' }} por {{ agruparR === 'mes' ? 'mes' : 'semana (bloques de 7 dias desde el 1 de enero)' }} en cada estacion y luego promediados entre estaciones.
        <template v-if="r.variable === 'vpd'"> DPV (FAO-56): es − ea, con e°(Tmax), e°(Tmin) y la humedad relativa minima y maxima del dia.</template>
        <template v-if="r.variable === 'gdd'"> Grados dia = max(0, (Tmax + Tmin)/2 − {{ r.tbase }} °C).</template>
        La linea punteada es el promedio de los años elegidos.
      </p>
    </template>
  </div>
</template>

<script>
// Paleta categorica validada (guia de visualizacion): orden fijo, color por entidad.
const PALETA = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
const COLOR_ESTRATO = { ALTO: '#2a78d6', MEDIO: '#eb6834', BAJO: '#1baf7a', LITORAL: '#eda100', 'SIN ESTRATO': '#8a8f87' };
const GRIS = '#52514e';
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const VARIABLES = [
  { id: 'lluvia', nombre: 'Precipitacion', unidad: 'mm', unidadAnual: 'mm/año', dec: 0, sumable: true },
  { id: 'radiacion', nombre: 'Radiacion solar', unidad: 'MJ/m²·dia', unidadAnual: 'MJ/m²·dia', dec: 1, sumable: false },
  { id: 'tmed', nombre: 'Temperatura media', unidad: '°C', unidadAnual: '°C', dec: 1, sumable: false },
  { id: 'tmax', nombre: 'Temperatura maxima', unidad: '°C', unidadAnual: '°C', dec: 1, sumable: false },
  { id: 'tmin', nombre: 'Temperatura minima', unidad: '°C', unidadAnual: '°C', dec: 1, sumable: false },
  { id: 'hr', nombre: 'Humedad relativa', unidad: '%', unidadAnual: '%', dec: 0, sumable: false },
  { id: 'vpd', nombre: 'Deficit de presion de vapor', unidad: 'kPa', unidadAnual: 'kPa', dec: 2, sumable: false },
  { id: 'gdd', nombre: 'Grados dia', unidad: '°C·dia', unidadAnual: '°C·dia/año', dec: 0, sumable: true },
  { id: 'eto', nombre: 'Evapotranspiracion (ETo)', unidad: 'mm', unidadAnual: 'mm/año', dec: 0, sumable: true },
  { id: 'viento', nombre: 'Viento', unidad: 'km/h', unidadAnual: 'km/h', dec: 1, sumable: false },
];

export default {
  name: 'ClimaAnalisis',
  props: {
    estaciones: { type: Array, default: () => [] },
  },
  data() {
    const y = new Date().getFullYear();
    return {
      variables: VARIABLES,
      variable: 'lluvia',
      tbase: 18,
      agrupar: 'semana',
      agruparR: 'semana',
      estratosSel: ['ALTO', 'MEDIO', 'BAJO', 'LITORAL', 'SIN ESTRATO'],
      zonasSel: ['OESTE', 'CENTRO-OESTE', 'CENTRO', 'CENTRO-ESTE', 'ESTE', 'ESTE-ESTE', 'SIN ZONA'],
      aniosSel: [y - 4, y - 3, y - 2, y - 1, y],
      aniosTodos: Array.from({ length: y - 2009 + 1 }, (_, i) => 2009 + i),
      excluidas: [],
      verEstaciones: false,
      cargando: false,
      error: null,
      r: null,
    };
  },
  computed: {
    estratosTodos() { return ['ALTO', 'MEDIO', 'BAJO', 'LITORAL', 'SIN ESTRATO']; },
    zonasTodas() { return ['OESTE', 'CENTRO-OESTE', 'CENTRO', 'CENTRO-ESTE', 'ESTE', 'ESTE-ESTE', 'SIN ZONA']; },
    estacionesFiltro() {
      return this.estaciones.filter((s) => this.estratosSel.includes(s.estrato || 'SIN ESTRATO') && this.zonasSel.includes(s.zona || 'SIN ZONA'));
    },
    estacionesSel() { return this.estacionesFiltro.filter((s) => !this.excluidas.includes(s.id)); },
    errorFiltro() {
      if (!this.estacionesSel.length) return 'Ninguna estacion cumple el filtro de estrato/zona.';
      if (!this.aniosSel.length) return 'Elija al menos un año.';
      return null;
    },
    meta() { return VARIABLES.find((v) => v.id === (this.r ? this.r.variable : this.variable)); },
    categorias() {
      if (!this.r) return [];
      return this.r.periodos.map((p) => (this.agruparR === 'mes' ? MESES[p - 1] : p));
    },
    etiquetaPeriodo() { return this.agruparR === 'mes' ? 'Mes' : 'Semana'; },
    chartAnios() {
      const series = Object.keys(this.r.por_anio).map((a) => ({
        name: a, data: this.r.por_anio[a], color: this.colorAnio(Number(a)), lineWidth: 2,
      }));
      series.push({ name: 'Promedio', data: this.r.promedio, color: GRIS, dashStyle: 'ShortDash', lineWidth: 2 });
      return this.base(`${this.meta.nombre} ${this.agruparR === 'mes' ? 'mensual' : 'semanal'} por año`, series, this.meta.unidad);
    },
    chartSegundo() {
      if (this.meta.sumable) {
        const series = Object.keys(this.r.por_anio).map((a) => {
          let s = 0;
          const datos = this.r.por_anio[a];
          const ultimo = datos.reduce((u, v, i) => (v !== null ? i : u), -1);
          return { name: a, color: this.colorAnio(Number(a)), lineWidth: 2, data: datos.map((v, i) => (i > ultimo ? null : (s += v || 0))) };
        });
        return this.base(`${this.meta.nombre} acumulada por año`, series, this.meta.unidad);
      }
      const series = Object.keys(this.r.por_anio).map((a) => ({
        name: a, color: this.colorAnio(Number(a)), lineWidth: 2,
        data: this.r.por_anio[a].map((v, i) => (v === null || this.r.promedio[i] === null ? null : v - this.r.promedio[i])),
      }));
      const opt = this.base(`${this.meta.nombre}: diferencia con el promedio`, series, this.meta.unidad);
      opt.yAxis.plotLines = [{ value: 0, color: GRIS, width: 1 }];
      return opt;
    },
    chartEstratos() {
      const series = Object.keys(this.r.por_estrato).map((e) => ({ name: e === 'SIN ESTRATO' ? '(Sin estrato)' : e, data: this.r.por_estrato[e], color: this.colorEstrato(e), lineWidth: 2 }));
      return this.base(`${this.meta.nombre} por estrato (promedio ${this.aniosTexto})`, series, this.meta.unidad);
    },
    chartEstratoAnio() {
      const anios = this.r.resumen_anio.map((a) => String(a.anio));
      const series = Object.keys(this.r.estrato_anio).map((e) => ({
        name: e === 'SIN ESTRATO' ? '(Sin estrato)' : e, color: this.colorEstrato(e),
        data: anios.map((a) => (this.r.estrato_anio[e][a] !== undefined ? this.r.estrato_anio[e][a] : null)),
      }));
      return {
        chart: { type: 'column', height: 320, style: { fontFamily: 'inherit' } },
        title: { text: `${this.meta.nombre} ${this.meta.sumable ? 'anual' : 'promedio'} por estrato y año`, style: this.estiloTitulo },
        credits: { enabled: false },
        xAxis: { categories: anios, lineColor: '#CED2D5' },
        yAxis: { title: { text: this.meta.unidadAnual }, gridLineColor: '#edf1ec' },
        tooltip: { shared: true, valueDecimals: this.meta.dec, valueSuffix: ` ${this.meta.unidadAnual}` },
        plotOptions: { column: { borderRadius: 3, borderWidth: 0, groupPadding: 0.12, pointPadding: 0.04 } },
        legend: { itemStyle: { fontWeight: '600', fontSize: '11px' } },
        series,
      };
    },
    chartEstaciones() {
      const est = this.r.estaciones.filter((e) => e.promedio !== null);
      const estratos = [...new Set(est.map((e) => e.estrato || 'SIN ESTRATO'))];
      return {
        chart: { type: 'bar', height: Math.max(220, 26 * est.length + 90), style: { fontFamily: 'inherit' } },
        title: { text: `${this.meta.nombre} ${this.meta.sumable ? 'anual' : 'promedio'} por estacion (promedio ${this.aniosTexto})`, style: this.estiloTitulo },
        credits: { enabled: false },
        xAxis: { categories: est.map((e) => e.nombre), lineColor: '#CED2D5' },
        yAxis: { title: { text: this.meta.unidadAnual }, gridLineColor: '#edf1ec' },
        tooltip: { pointFormatter() { return `<b>${Highcharts_num(this.y, this.dec)}</b> ${this.unidad}<br>Estrato: ${this.estrato}`; } },
        plotOptions: { bar: { grouping: false, borderRadius: 3, borderWidth: 0, pointPadding: 0.12, groupPadding: 0.04, dataLabels: { enabled: true, format: `{y:.${this.meta.dec}f}`, style: { fontWeight: '600', textOutline: 'none', color: '#33402a' } } } },
        legend: { enabled: true, itemStyle: { fontWeight: '600', fontSize: '11px' } },
        series: [
          {
            name: this.meta.nombre, showInLegend: false,
            data: est.map((e) => ({ y: e.promedio, color: this.colorEstrato(e.estrato || 'SIN ESTRATO'), estrato: e.estrato || 'Sin estrato', dec: this.meta.dec, unidad: this.meta.unidadAnual })),
          },
          // Leyenda de colores por estrato.
          ...estratos.map((e) => ({ name: e === 'SIN ESTRATO' ? '(Sin estrato)' : e, color: this.colorEstrato(e), data: [], type: 'bar' })),
        ],
      };
    },
    aniosTexto() {
      const a = this.r.resumen_anio.map((x) => x.anio);
      return a.length > 1 ? `${a[0]}–${a[a.length - 1]}` : String(a[0]);
    },
    estiloTitulo() { return { fontSize: '13px', fontWeight: '700', color: '#1f5632' }; },
  },
  methods: {
    colorAnio(a) { return PALETA[((a % 8) + 8) % 8]; },
    colorEstrato(e) { return COLOR_ESTRATO[e] || GRIS; },
    cuentaEstrato(e) { return this.estaciones.filter((s) => (s.estrato || 'SIN ESTRATO') === e).length; },
    alternar(lista, v) {
      const i = lista.indexOf(v);
      if (i >= 0) lista.splice(i, 1); else lista.push(v);
    },
    alternarAnio(a) {
      const i = this.aniosSel.indexOf(a);
      if (i >= 0) this.aniosSel.splice(i, 1);
      else if (this.aniosSel.length < 8) this.aniosSel.push(a);
      else this.$swal.fire({ toast: true, position: 'top-end', icon: 'info', title: 'Maximo 8 años', text: 'Quite un año para agregar otro.', timer: 2200, showConfirmButton: false });
    },
    alternarExcluir(id) { this.alternar(this.excluidas, id); },
    base(titulo, series, unidad) {
      return {
        chart: { type: 'line', height: 330, zoomType: 'x', style: { fontFamily: 'inherit' } },
        title: { text: titulo, style: this.estiloTitulo },
        credits: { enabled: false },
        xAxis: { categories: this.categorias, title: { text: this.etiquetaPeriodo }, tickInterval: this.agruparR === 'mes' ? 1 : 4, lineColor: '#CED2D5', crosshair: true },
        yAxis: { title: { text: unidad }, gridLineColor: '#edf1ec' },
        tooltip: { shared: true, valueDecimals: this.meta.dec, valueSuffix: ` ${unidad}`, headerFormat: `<b>${this.etiquetaPeriodo} {point.key}</b><br>` },
        plotOptions: { series: { marker: { enabled: false, states: { hover: { enabled: true, radius: 4 } } }, connectNulls: false } },
        legend: { itemStyle: { fontWeight: '600', fontSize: '11px' } },
        series,
      };
    },
    analizar() {
      if (this.errorFiltro) return;
      this.cargando = true;
      this.error = null;
      window.axios.get('/investigacion/clima/analisis', {
        params: { estaciones: this.estacionesSel.map((s) => s.id), anios: this.aniosSel.slice().sort(), variable: this.variable, agrupar: this.agrupar, tbase: this.tbase },
      })
        .then(({ data }) => { this.r = data; this.agruparR = this.agrupar; })
        .catch((err) => {
          const res = err.response;
          this.error = (res && res.data && (res.data.errors ? Object.values(res.data.errors).flat()[0] : res.data.message)) || 'No se pudo calcular el analisis.';
        })
        .finally(() => { this.cargando = false; });
    },
    num(v, d) { return v === null || v === undefined ? '—' : Number(v).toLocaleString('es-GT', { minimumFractionDigits: d, maximumFractionDigits: d }); },
  },
};

function Highcharts_num(v, d) {
  return v === null || v === undefined ? '—' : Number(v).toLocaleString('es-GT', { minimumFractionDigits: d, maximumFractionDigits: d });
}
</script>

<style scoped>
.an-filtros { display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: flex-end; padding: 12px 14px; border: 1px solid #e3e7e1; border-radius: 12px; background: #fff; }
.an-f { display: flex; flex-direction: column; gap: 4px; }
.an-f-ancho { flex: 1 1 320px; }
.an-f-btn { margin-left: auto; }
.an-f > label { margin: 0; color: #1f5632; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
.an-f > label small { color: #6b7568; font-weight: 500; text-transform: none; }
.an-f > label a { margin-left: 6px; font-size: 11px; text-transform: none; }
.an-tbase { width: 90px; }
.an-seg { display: inline-flex; padding: 3px; border: 1px solid #e3e7e1; border-radius: 8px; background: #f3f5f1; }
.an-seg button { padding: 4px 12px; border: 0; border-radius: 6px; background: transparent; color: #5b6658; font-size: 12px; font-weight: 600; }
.an-seg button.is-on { background: #73BC25; color: #fff; }
.an-chips { display: flex; flex-wrap: wrap; gap: 5px; }
.an-chips button {
  --c: #73BC25;
  padding: 3px 10px; border: 1px solid #d8ddd5; border-radius: 999px; background: #fff; color: #5b6658; font-size: 12px; font-weight: 600;
}
.an-chips button small { color: #8a8f87; font-weight: 500; }
.an-chips button.is-on { border-color: var(--c); background: color-mix(in srgb, var(--c) 14%, #fff); color: #26331f; box-shadow: inset 3px 0 0 var(--c); }
.an-est { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 2px 10px; max-height: 160px; overflow-y: auto; padding: 6px 8px; border: 1px solid #edf1ec; border-radius: 8px; font-size: 12px; }
.an-est label { margin: 0; font-weight: 400; }
.an-est small { color: #8a8f87; }
.an-error { margin: 6px 0 0; color: #b84d00; font-size: 12px; }
.an-vacio { display: flex; flex-direction: column; align-items: center; gap: 6px; margin-top: 12px; padding: 40px 16px; border: 1px dashed #CED2D5; border-radius: 12px; color: #6b7568; font-size: 13px; text-align: center; }
.an-vacio > i { color: #CED2D5; font-size: 26px; }
.an-vacio.is-error { border-color: #ffd2b3; color: #b84d00; }
.an-aviso { margin: 10px 0 0; padding: 7px 12px; border-radius: 8px; background: #fff8ec; color: #7a4a00; font-size: 12.5px; }
.an-resumen { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; margin: 12px 0; }
.an-kpi { display: flex; flex-direction: column; padding: 8px 12px; border: 1px solid #e3e7e1; border-left: 4px solid var(--c); border-radius: 10px; background: #fff; }
.an-kpi span { color: #6b7568; font-size: 12px; font-weight: 700; }
.an-kpi strong { color: #1e2a1a; font-size: 18px; font-variant-numeric: tabular-nums; }
.an-kpi strong small { color: #6b7568; font-size: 11px; font-weight: 500; }
.an-kpi > small { color: #6b7568; font-size: 11px; }
.an-kpi > small.is-bajo { color: #b84d00; font-weight: 600; }
.an-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.an-card { padding: 8px; border: 1px solid #e3e7e1; border-radius: 12px; background: #fff; }
.an-nota { margin-top: 10px; color: #6b7568; font-size: 11.5px; }
@media (max-width: 991px) { .an-grid { grid-template-columns: 1fr; } }
</style>
