<template>
  <div class="cp">
    <div class="cp-layout">
      <!-- ============ Seleccion ============ -->
      <aside class="cp-panel">
        <div class="cp-bloque">
          <label class="cp-tit">Estaciones a comparar <small>({{ sel.length }} de 8)</small></label>
          <div class="cp-elegidas" v-if="sel.length">
            <span v-for="(id, i) in sel" :key="id" class="cp-chip" :style="{ '--c': colorDe(i) }">
              {{ nombre(id) }} <button type="button" title="Quitar" @click="quitar(id)">×</button>
            </span>
          </div>
          <p v-else class="cp-muted">Marque de 2 a 8 estaciones.</p>
          <input class="form-control form-control-sm" v-model="buscar" placeholder="Buscar estacion...">
          <div class="cp-estratos">
            <button v-for="e in estratosTodos" :key="e" type="button" :class="{ 'is-on': filtroEstrato === e }" :style="{ '--c': colorEstrato(e) }" @click="filtroEstrato = filtroEstrato === e ? '' : e">
              {{ e === 'SIN ESTRATO' ? 'Sin estrato' : e }}
            </button>
          </div>
          <div class="cp-lista">
            <label v-for="s in visibles" :key="s.id" class="cp-est" :class="{ 'is-off': !sel.includes(s.id) && sel.length >= 8 }">
              <input type="checkbox" :checked="sel.includes(s.id)" :disabled="!sel.includes(s.id) && sel.length >= 8" @change="alternar(s.id)">
              <span>
                <b>{{ s.nombre }}</b>
                <small><i class="cp-dot" :style="{ background: colorEstrato(s.estrato || 'SIN ESTRATO') }"></i>{{ s.estrato || 'Sin estrato' }}{{ s.zona ? ' · ' + s.zona : '' }} · {{ s.codigo }}</small>
              </span>
            </label>
            <p v-if="!visibles.length" class="cp-muted p-2">Ninguna estacion coincide.</p>
          </div>
        </div>

        <div class="cp-bloque">
          <label class="cp-tit">Comparar contra</label>
          <select class="form-control form-control-sm" v-model="referencia">
            <option value="sel">Promedio de las estaciones elegidas</option>
            <option value="red">Promedio de toda la red (todos los estratos)</option>
            <option v-for="e in ['ALTO', 'MEDIO', 'BAJO', 'LITORAL']" :key="e" :value="e" :disabled="!cuentaEstrato(e)">
              Promedio estrato {{ e }} ({{ cuentaEstrato(e) }} est.)
            </option>
          </select>
          <small v-if="referencia !== 'sel'" class="cp-muted">Se muestra como linea gris y se usa en la columna "vs" y en las diferencias.</small>
          <label class="cp-tit mt-1">Variable</label>
          <select class="form-control form-control-sm" v-model="variable">
            <option v-for="v in variables" :key="v.id" :value="v.id">{{ v.nombre }} ({{ v.unidad }})</option>
          </select>
          <div v-if="variable === 'gdd'" class="cp-fila">
            <small>T base</small><input type="number" min="0" max="40" step="0.5" class="form-control form-control-sm cp-tbase" v-model.number="tbase"><small>°C</small>
          </div>
        </div>

        <div class="cp-bloque">
          <label class="cp-tit">Periodo</label>
          <select class="form-control form-control-sm" v-model="preset" @change="aplicarPreset">
            <option v-for="p in presets" :key="p.id" :value="p.id">{{ p.label }}</option>
          </select>
          <div class="cp-fila">
            <input type="date" class="form-control form-control-sm" v-model="desde" :max="hasta || hoy" @change="preset = 'custom'">
            <input type="date" class="form-control form-control-sm" v-model="hasta" :min="desde" :max="hoy" @change="preset = 'custom'">
          </div>
          <label class="cp-tit mt-1">Agrupar</label>
          <div class="cp-seg">
            <button v-for="a in agrupaciones" :key="a.id" type="button" :class="{ 'is-on': agrupar === a.id }" @click="agrupar = a.id">{{ a.label }}</button>
          </div>
        </div>

        <p v-if="errorFiltro" class="cp-error"><i class="fas fa-exclamation-circle"></i> {{ errorFiltro }}</p>
        <button type="button" class="btn btn-success btn-block" :disabled="cargando || !!errorFiltro" @click="comparar">
          <i :class="cargando ? 'fas fa-spinner fa-spin' : 'fas fa-balance-scale'"></i> {{ cargando ? 'Consultando...' : 'Comparar' }}
        </button>
      </aside>

      <!-- ============ Resultados ============ -->
      <section class="cp-res">
        <div v-if="cargando" class="cp-vacio"><i class="fas fa-spinner fa-spin"></i> Consultando las estaciones...</div>
        <div v-else-if="error" class="cp-vacio is-error"><i class="fas fa-exclamation-circle"></i> {{ error }}</div>
        <div v-else-if="!res" class="cp-vacio">
          <i class="fas fa-balance-scale"></i>
          Elija de 2 a 8 estaciones, la variable y el periodo, y pulse <b>Comparar</b>.
        </div>
        <template v-else>
          <p v-if="res.sync && res.sync.error" class="cp-aviso"><i class="fas fa-exclamation-triangle"></i> API del ICC: {{ res.sync.error }}</p>

          <div class="cp-head">
            <h6><i class="fas fa-table"></i> {{ meta.nombre }} · {{ fmt(res.desde) }} → {{ fmt(res.hasta) }}</h6>
            <button type="button" class="btn btn-success btn-sm" @click="exportar"><i class="fas fa-file-csv"></i> Exportar CSV</button>
          </div>
          <div class="cp-tabla-wrap">
            <table class="table cp-tabla">
              <thead>
                <tr>
                  <th>Estacion</th><th>Estrato</th><th>Zona</th><th class="text-right">Dias con datos</th>
                  <th class="text-right">{{ meta.sumable ? 'Total' : 'Promedio' }} <small>{{ meta.unidad }}</small></th>
                  <th v-if="variosAnios && meta.sumable" class="text-right">Prom. anual <small>{{ meta.unidad }}/año</small></th>
                  <th class="text-right">Min. {{ etiquetaPeriodo.toLowerCase() }}</th><th class="text-right">Max. {{ etiquetaPeriodo.toLowerCase() }}</th>
                  <th class="text-right" :title="`Diferencia con: ${refNombre}`">vs {{ refCorto }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in resumen" :key="r.id">
                  <td><i class="cp-dot cp-dot-l" :style="{ background: r.color }"></i><b>{{ r.nombre }}</b> <small class="cp-muted">{{ r.codigo }}</small></td>
                  <td><span v-if="r.estrato" class="cp-estr" :style="{ '--c': colorEstrato(r.estrato) }">{{ r.estrato }}</span><span v-else class="cp-muted">—</span></td>
                  <td>{{ r.zona || '—' }}</td>
                  <td class="text-right" :class="{ 'cp-bajo': r.cobertura < 80 }" :title="`${r.cobertura} % del periodo`">{{ r.dias }} / {{ res.totalDias }}</td>
                  <td class="text-right"><b>{{ num(r.valor, meta.dec) }}</b></td>
                  <td v-if="variosAnios && meta.sumable" class="text-right"><b>{{ num(r.anual, meta.dec) }}</b></td>
                  <td class="text-right">{{ num(r.min, meta.dec) }}</td>
                  <td class="text-right">{{ num(r.max, meta.dec) }}</td>
                  <td class="text-right" :class="r.dif === null ? '' : r.dif >= 0 ? 'cp-sube' : 'cp-baja'">
                    <template v-if="r.dif !== null">{{ r.dif >= 0 ? '+' : '' }}{{ num(r.dif, meta.dec) }}<small v-if="r.difPct !== null"> ({{ r.difPct >= 0 ? '+' : '' }}{{ r.difPct.toFixed(0) }} %)</small></template>
                    <template v-else>—</template>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="cp-grid">
            <div class="cp-card cp-ancho"><highcharts :options="chartSerie"></highcharts></div>
            <div class="cp-card"><highcharts :options="chartSegundo"></highcharts></div>
            <div class="cp-card"><highcharts :options="chartTotales"></highcharts></div>
          </div>
          <p class="cp-nota">
            <i class="fas fa-info-circle"></i>
            {{ meta.sumable ? 'Se suma' : 'Se promedia' }} por {{ etiquetaPeriodo.toLowerCase() }} en cada estacion; los periodos sin datos quedan en blanco.
            <template v-if="variable === 'vpd'"> DPV (FAO-56) con Tmax, Tmin y humedad relativa minima y maxima del dia.</template>
            <template v-if="variable === 'gdd'"> Grados dia = max(0, (Tmax + Tmin)/2 − {{ res.tbase }} °C).</template>
          </p>
        </template>
      </section>
    </div>
  </div>
</template>

<script>
import { PALETA, COLOR_ESTRATO, GRIS } from './clima-util';

const DIA = 86400000;
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const parse = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const VARIABLES = [
  { id: 'lluvia', nombre: 'Precipitacion', unidad: 'mm', dec: 1, sumable: true },
  { id: 'radiacion', nombre: 'Radiacion solar', unidad: 'MJ/m²·dia', dec: 1, sumable: false },
  { id: 'tmed', nombre: 'Temperatura media', unidad: '°C', dec: 1, sumable: false },
  { id: 'tmax', nombre: 'Temperatura maxima', unidad: '°C', dec: 1, sumable: false },
  { id: 'tmin', nombre: 'Temperatura minima', unidad: '°C', dec: 1, sumable: false },
  { id: 'hr', nombre: 'Humedad relativa', unidad: '%', dec: 0, sumable: false },
  { id: 'vpd', nombre: 'Deficit de presion de vapor', unidad: 'kPa', dec: 2, sumable: false },
  { id: 'gdd', nombre: 'Grados dia', unidad: '°C·dia', dec: 0, sumable: true },
  { id: 'eto', nombre: 'Evapotranspiracion (ETo)', unidad: 'mm', dec: 1, sumable: true },
  { id: 'viento', nombre: 'Viento', unidad: 'km/h', dec: 1, sumable: false },
];

export default {
  name: 'ClimaComparar',
  props: {
    estaciones: { type: Array, default: () => [] },
  },
  data() {
    const hoy = iso(new Date());
    return {
      hoy,
      variables: VARIABLES,
      variable: 'lluvia',
      referencia: 'sel',
      tbase: 18,
      sel: [],
      buscar: '',
      filtroEstrato: '',
      preset: 'anio',
      desde: `${new Date().getFullYear()}-01-01`,
      hasta: hoy,
      agrupar: 'semana',
      agrupaciones: [{ id: 'dia', label: 'Dia' }, { id: 'semana', label: 'Semana' }, { id: 'mes', label: 'Mes' }, { id: 'anio', label: 'Año' }],
      cargando: false,
      error: null,
      res: null,
    };
  },
  computed: {
    estratosTodos() { return ['ALTO', 'MEDIO', 'BAJO', 'LITORAL', 'SIN ESTRATO']; },
    presets() {
      return [
        { id: 'anio', label: 'Este año' }, { id: 'anioPrev', label: 'Año anterior' }, { id: 'ult90', label: 'Ultimos 90 dias' },
        { id: 'ult12m', label: 'Ultimos 12 meses' }, { id: 'zafra', label: 'Zafra actual (nov–may)' }, { id: 'zafraPrev', label: 'Zafra anterior' },
        { id: 'ult5', label: 'Ultimos 5 años' }, { id: 'ult10', label: 'Ultimos 10 años' }, { id: 'todo', label: 'Todo el historial (desde 2009)' },
        { id: 'custom', label: 'Personalizado' },
      ];
    },
    visibles() {
      const q = this.buscar.trim().toLowerCase();
      const orden = { ALTO: 1, MEDIO: 2, BAJO: 3, LITORAL: 4 };
      return this.estaciones
        .filter((s) => (!this.filtroEstrato || (s.estrato || 'SIN ESTRATO') === this.filtroEstrato)
          && (!q || `${s.nombre} ${s.codigo} ${s.ubicacion || ''}`.toLowerCase().includes(q)))
        .sort((a, b) => (orden[a.estrato] || 9) - (orden[b.estrato] || 9) || a.nombre.localeCompare(b.nombre));
    },
    errorFiltro() {
      if (this.sel.length < 2) return 'Elija al menos 2 estaciones.';
      if (!this.desde || !this.hasta || this.desde > this.hasta) return 'Revise las fechas del periodo.';
      const dias = (parse(this.hasta) - parse(this.desde)) / DIA;
      if (this.agrupar === 'dia' && dias > 1100) return 'Por dia se comparan hasta 3 años: agrupe por semana, mes o año para periodos mas largos.';
      if (this.agrupar === 'semana' && dias > 3700) return 'Por semana se comparan hasta 10 años: agrupe por mes o año para periodos mas largos.';
      return null;
    },
    // La variable se puede cambiar sin volver a consultar (el servidor envia todas).
    meta() { return VARIABLES.find((v) => v.id === this.variable); },
    etiquetaPeriodo() { return { dia: 'Dia', semana: 'Semana', mes: 'Mes', anio: 'Año' }[this.res ? this.res.agrupar : this.agrupar]; },
    /** Mas de un año: se agrega el promedio anual de las variables que se suman. */
    variosAnios() { return this.res && this.res.totalDias > 400; },
    /** Periodos (orden cronologico) y valor por estacion y periodo, calculados en el servidor. */
    serie() {
      if (!this.res) return { claves: [], etiquetas: [], porEst: {} };
      const porEst = {};
      this.res.sel.forEach((id) => {
        const e = this.res.porEst[id];
        porEst[id] = e ? e.serie[this.variable] : [];
      });
      return { claves: this.res.periodos, etiquetas: this.res.periodos.map((k) => this.etiqueta(k)), porEst };
    },
    resumen() {
      if (!this.res) return [];
      const filas = this.res.sel.map((id, i) => {
        const s = this.estaciones.find((e) => e.id === id) || { id, nombre: `#${id}`, codigo: '' };
        const e = this.res.porEst[id] || { dias: 0, totales: {} };
        const periodos = (this.serie.porEst[id] || []).filter((x) => x !== null);
        const valor = e.totales[this.variable] ?? null;
        return {
          id, nombre: s.nombre, codigo: s.codigo, estrato: s.estrato, zona: s.zona, color: this.colorDe(i),
          dias: e.dias, cobertura: Math.round((e.dias / this.res.totalDias) * 100),
          valor,
          anual: valor !== null && this.meta.sumable && e.dias ? (valor / e.dias) * 365.25 : null,
          min: periodos.length ? Math.min(...periodos) : null,
          max: periodos.length ? Math.max(...periodos) : null,
        };
      });
      const prom = this.refValor;
      filas.forEach((f) => {
        f.dif = f.valor !== null && prom !== null ? f.valor - prom : null;
        f.difPct = f.dif !== null && prom ? (f.dif / prom) * 100 : null;
      });
      return filas;
    },
    /* ---- Referencia: promedio de las elegidas, de la red o de un estrato (calculado en el servidor) ---- */
    refExterna() { return this.res && this.res.referencia ? this.res.referencia : null; },
    refNombre() {
      return this.refExterna ? `${this.refExterna.nombre} (${this.refExterna.estaciones} est.)` : 'Promedio de las estaciones elegidas';
    },
    refCorto() {
      if (!this.refExterna) return 'promedio';
      return this.refExterna.clave === 'red' ? 'red' : `estrato ${this.refExterna.clave}`;
    },
    /** Valor de referencia por periodo. */
    refSerie() {
      if (!this.res) return [];
      if (this.refExterna) return this.refExterna.serie[this.variable] || [];
      return this.serie.claves.map((_, k) => {
        const xs = this.res.sel.map((id) => (this.serie.porEst[id] || [])[k]).filter((x) => x !== null && x !== undefined);
        return xs.length ? xs.reduce((a, b) => a + b, 0) / xs.length : null;
      });
    },
    /** Valor de referencia del periodo completo (total o promedio). */
    refValor() {
      if (!this.res) return null;
      if (this.refExterna) return this.refExterna.totales[this.variable] ?? null;
      const xs = this.res.sel.map((id) => (this.res.porEst[id] ? this.res.porEst[id].totales[this.variable] : null)).filter((x) => x !== null && x !== undefined);
      return xs.length ? xs.reduce((a, b) => a + b, 0) / xs.length : null;
    },
    serieRef() {
      return { name: this.refExterna ? this.refExterna.nombre : 'Promedio elegidas', color: GRIS, dashStyle: 'ShortDash', lineWidth: 2 };
    },
    chartSerie() {
      const series = this.res.sel.map((id, i) => ({ name: this.nombre(id), color: this.colorDe(i), data: this.serie.porEst[id] || [], lineWidth: 2 }));
      if (this.refExterna) series.push({ ...this.serieRef, data: this.refSerie });
      return this.base(`${this.meta.nombre} por ${this.etiquetaPeriodo.toLowerCase()}`, series, 360);
    },
    chartSegundo() {
      if (this.meta.sumable) {
        // Varios años: el acumulado reinicia el 1 de enero de cada año (se ve el total de cada año y la diferencia entre años).
        const porAnio = this.variosAnios && this.res.agrupar !== 'anio';
        const claves = this.serie.claves;
        const acumular = (datos) => {
          let s = 0;
          return datos.map((v, k) => {
            if (porAnio && k > 0 && claves[k].slice(0, 4) !== claves[k - 1].slice(0, 4)) s = 0;
            s += v || 0;
            return v === null ? null : s;
          });
        };
        const series = this.res.sel.map((id, i) => ({ name: this.nombre(id), color: this.colorDe(i), lineWidth: 2, data: acumular(this.serie.porEst[id] || []) }));
        if (this.refExterna) series.push({ ...this.serieRef, data: acumular(this.refSerie) });
        const opt = this.base(`${this.meta.nombre} acumulada${porAnio ? ' por año' : ''}`, series, 320, 7);
        if (porAnio) {
          // Lineas en cada cambio de año.
          opt.xAxis.plotLines = claves.map((k, j) => (j > 0 && k.slice(0, 4) !== claves[j - 1].slice(0, 4)
            ? { value: j - 0.5, color: '#CED2D5', width: 1, dashStyle: 'Dash', label: { text: k.slice(0, 4), rotation: 0, y: 12, style: { color: GRIS, fontSize: '10px' } } }
            : null)).filter(Boolean);
        }
        return opt;
      }
      const prom = this.refSerie;
      const series = this.res.sel.map((id, i) => ({
        name: this.nombre(id), color: this.colorDe(i), lineWidth: 2,
        data: (this.serie.porEst[id] || []).map((v, k) => (v === null || prom[k] === null || prom[k] === undefined ? null : v - prom[k])),
      }));
      const contra = this.refExterna ? this.refExterna.nombre.replace('Promedio', 'el promedio') : 'el promedio de las estaciones elegidas';
      const opt = this.base(`${this.meta.nombre}: diferencia con ${contra}`, series, 320, 7);
      opt.yAxis.plotLines = [{ value: 0, color: GRIS, width: 1 }];
      return opt;
    },
    /**
     * Valor de cada estacion por año (total para variables acumulables;
     * promedio ponderado por dias para el resto), a partir de la serie.
     */
    porAnio() {
      if (!this.res) return { anios: [], valores: {} };
      const claves = this.serie.claves;
      const anios = [...new Set(claves.map((k) => k.slice(0, 4)))];
      const valores = {};
      this.res.sel.forEach((id) => {
        const e = this.res.porEst[id];
        const serie = this.serie.porEst[id] || [];
        const acc = {};
        claves.forEach((k, j) => {
          const v = serie[j];
          if (v === null || v === undefined) return;
          const d = e && e.dias_periodo ? e.dias_periodo[j] || 1 : 1;
          const a = (acc[k.slice(0, 4)] = acc[k.slice(0, 4)] || [0, 0]);
          if (this.meta.sumable) a[0] += v; else { a[0] += v * d; a[1] += d; }
        });
        valores[id] = anios.map((a) => (acc[a] ? (this.meta.sumable ? acc[a][0] : acc[a][0] / (acc[a][1] || 1)) : null));
      });
      // Referencia externa por año (sin ponderar por dias: ya es un promedio de estaciones).
      let ref = null;
      if (this.refExterna) {
        const acc = {};
        claves.forEach((k, j) => {
          const v = this.refSerie[j];
          if (v === null || v === undefined) return;
          const a = (acc[k.slice(0, 4)] = acc[k.slice(0, 4)] || [0, 0]);
          a[0] += v;
          a[1] += 1;
        });
        ref = anios.map((a) => (acc[a] ? (this.meta.sumable ? acc[a][0] : acc[a][0] / acc[a][1]) : null));
      }
      return { anios, valores, ref };
    },
    chartTotales() {
      // Varios años: columnas por año y estacion para ver la diferencia entre años.
      if (this.variosAnios) {
        const { anios, valores, ref } = this.porAnio;
        const series = this.res.sel.map((id, i) => ({ name: this.nombre(id), color: this.colorDe(i), data: valores[id] }));
        if (ref) series.push({ name: this.refExterna.nombre, color: '#9aa39a', data: ref });
        return {
          chart: { type: 'column', height: 320, style: { fontFamily: 'inherit' } },
          title: { text: `${this.meta.nombre}: ${this.meta.sumable ? 'total' : 'promedio'} por año`, style: this.estiloTitulo },
          credits: { enabled: false },
          xAxis: { categories: anios, lineColor: '#CED2D5', crosshair: true },
          yAxis: { title: { text: this.meta.unidad }, gridLineColor: '#edf1ec' },
          tooltip: { shared: true, valueDecimals: this.meta.dec, valueSuffix: ` ${this.meta.unidad}` },
          plotOptions: { column: { borderRadius: 3, borderWidth: 0, groupPadding: 0.12, pointPadding: 0.04, dataLabels: { enabled: anios.length * this.res.sel.length <= 24, format: `{y:.${this.meta.sumable ? 0 : Math.min(this.meta.dec, 1)}f}`, style: { fontSize: '10px', fontWeight: '600', textOutline: 'none', color: '#33402a' } } } },
          legend: { itemStyle: { fontWeight: '600', fontSize: '11px' } },
          series,
        };
      }
      const anual = false;
      const filas = this.resumen.filter((r) => r.valor !== null).map((r) => ({ ...r, valor: anual ? r.anual : r.valor })).sort((a, b) => b.valor - a.valor);
      const prom = this.refValor;
      return {
        chart: { type: 'bar', height: Math.max(220, 34 * filas.length + 90), style: { fontFamily: 'inherit' } },
        title: { text: `${this.meta.nombre}: ${anual ? 'promedio anual' : this.meta.sumable ? 'total del periodo' : 'promedio del periodo'}`, style: this.estiloTitulo },
        credits: { enabled: false }, legend: { enabled: false },
        xAxis: { categories: filas.map((f) => f.nombre), lineColor: '#CED2D5' },
        yAxis: {
          title: { text: this.meta.unidad }, gridLineColor: '#edf1ec',
          plotLines: prom === null ? [] : [{ value: prom, color: GRIS, width: 1.5, dashStyle: 'ShortDash', zIndex: 5, label: { text: `${this.refExterna ? this.refExterna.nombre : 'Promedio'} ${prom.toFixed(this.meta.dec)}`, rotation: 0, align: 'right', textAlign: 'right', y: -6, x: -6, style: { color: GRIS, fontSize: '11px', fontWeight: '600' } } }],
        },
        tooltip: { valueDecimals: this.meta.dec, valueSuffix: ` ${this.meta.unidad}` },
        plotOptions: { bar: { borderRadius: 4, borderWidth: 0, pointPadding: 0.12, groupPadding: 0.05, dataLabels: { enabled: true, format: `{y:.${this.meta.dec}f}`, style: { fontWeight: '600', textOutline: 'none', color: '#33402a' } } } },
        series: [{ name: this.meta.nombre, data: filas.map((f) => ({ y: f.valor, color: f.color })) }],
      };
    },
    estiloTitulo() { return { fontSize: '13px', fontWeight: '700', color: '#1f5632' }; },
  },
  watch: {
    // La referencia de la red o de un estrato se calcula en el servidor: se vuelve a consultar.
    referencia() { if (this.res && !this.errorFiltro && !this.cargando) this.comparar(); },
  },
  methods: {
    cuentaEstrato(e) { return this.estaciones.filter((s) => s.estrato === e).length; },
    colorDe(i) { return PALETA[i % PALETA.length]; },
    colorEstrato(e) { return COLOR_ESTRATO[e] || GRIS; },
    nombre(id) { const s = this.estaciones.find((e) => e.id === id); return s ? s.nombre : `#${id}`; },
    alternar(id) {
      const i = this.sel.indexOf(id);
      if (i >= 0) this.sel.splice(i, 1);
      else if (this.sel.length < 8) this.sel.push(id);
    },
    quitar(id) { this.sel = this.sel.filter((x) => x !== id); },
    aplicarPreset() {
      const h = new Date();
      const y = h.getFullYear();
      const zafraIni = h.getMonth() >= 10 ? y : y - 1;
      const menor = (a, b) => (a < b ? a : b);
      const r = {
        anio: [`${y}-01-01`, this.hoy],
        ult5: [`${y - 4}-01-01`, this.hoy],
        ult10: [`${y - 9}-01-01`, this.hoy],
        todo: ['2009-01-01', this.hoy],
        anioPrev: [`${y - 1}-01-01`, `${y - 1}-12-31`],
        ult90: [iso(new Date(Date.now() - 89 * DIA)), this.hoy],
        ult12m: [iso(new Date(y - 1, h.getMonth(), h.getDate() + 1)), this.hoy],
        zafra: [`${zafraIni}-11-01`, menor(`${zafraIni + 1}-05-31`, this.hoy)],
        zafraPrev: [`${zafraIni - 1}-11-01`, `${zafraIni}-05-31`],
      }[this.preset];
      if (r) [this.desde, this.hasta] = r;
    },
    /** Etiqueta de la clave de periodo que envia el servidor (Y-m-d, lunes Y-m-d, Y-m o Y). */
    etiqueta(k) {
      const agr = this.res.agrupar;
      if (agr === 'anio') return k;
      if (agr === 'mes') {
        const [y, m] = k.split('-');
        return `${MESES[Number(m) - 1]} ${y.slice(2)}`;
      }
      // Dia o semana: dd/mm, y con el año si el periodo abarca varios años.
      const [y, m, d] = k.split('-');
      return this.res.totalDias > 400 ? `${d}/${m}/${y.slice(2)}` : `${d}/${m}`;
    },
    base(titulo, series, alto, maxEtiquetas = 14) {
      return {
        chart: { type: 'line', height: alto, zoomType: 'x', style: { fontFamily: 'inherit' } },
        title: { text: titulo, style: this.estiloTitulo },
        credits: { enabled: false },
        xAxis: { categories: this.serie.etiquetas, lineColor: '#CED2D5', crosshair: true, labels: { step: Math.max(1, Math.ceil(this.serie.etiquetas.length / maxEtiquetas)) } },
        yAxis: { title: { text: this.meta.unidad }, gridLineColor: '#edf1ec' },
        tooltip: { shared: true, valueDecimals: this.meta.dec, valueSuffix: ` ${this.meta.unidad}` },
        plotOptions: { series: { marker: { enabled: false, states: { hover: { enabled: true, radius: 4 } } }, connectNulls: false } },
        legend: { itemStyle: { fontWeight: '600', fontSize: '11px' } },
        series,
      };
    },
    comparar() {
      if (this.errorFiltro) return;
      this.cargando = true;
      this.error = null;
      const pedido = { sel: this.sel.slice(), agrupar: this.agrupar, tbase: Number(this.tbase) || 0, desde: this.desde, hasta: this.hasta };
      // Agregado en el servidor: permite comparar periodos largos (todo el historial por mes o año).
      window.axios.get('/investigacion/clima/serie', { params: { estaciones: pedido.sel, desde: pedido.desde, hasta: pedido.hasta, agrupar: pedido.agrupar, tbase: pedido.tbase, referencia: this.referencia === 'sel' ? undefined : this.referencia } })
        .then(({ data }) => {
          const porEst = {};
          data.estaciones.forEach((e) => { porEst[e.id] = e; });
          this.res = { ...pedido, sync: data.sync, totalDias: data.total_dias, periodos: data.periodos, porEst, referencia: data.referencia };
        })
        .catch((err) => {
          const r = err.response;
          this.error = (r && r.data && (r.data.errors ? Object.values(r.data.errors).flat()[0] : r.data.message)) || 'No se pudo consultar.';
        })
        .finally(() => { this.cargando = false; });
    },
    /** CSV ancho: una columna por estacion. */
    exportar() {
      const num = (v, d) => (v === null || v === undefined ? '' : Number(v).toFixed(d).replace('.', ','));
      const d = this.meta.dec;
      const lineas = [
        `Variable;${this.meta.nombre} (${this.meta.unidad})`,
        `Periodo;${this.res.desde} a ${this.res.hasta}`,
        '',
        [this.etiquetaPeriodo, ...this.res.sel.map((id) => this.nombre(id))].join(';'),
        ...this.serie.claves.map((k, i) => [k, ...this.res.sel.map((id) => num((this.serie.porEst[id] || [])[i], d))].join(';')),
        '',
        ['Resumen', 'Estrato', 'Zona', 'Dias', this.meta.sumable ? 'Total' : 'Promedio', 'Min', 'Max'].join(';'),
        ...this.resumen.map((r) => [r.nombre, r.estrato || '', r.zona || '', r.dias, num(r.valor, d), num(r.min, d), num(r.max, d)].join(';')),
      ];
      const blob = new Blob([`﻿${lineas.join('\r\n')}`], { type: 'text/csv;charset=utf-8' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = `comparacion_${this.variable}_${this.res.desde}_${this.res.hasta}.csv`;
      document.body.appendChild(a);
      a.click();
      setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    },
    num(v, d) { return v === null || v === undefined ? '—' : Number(v).toLocaleString('es-GT', { minimumFractionDigits: d, maximumFractionDigits: d }); },
    fmt(s) { if (!s) return ''; const [y, m, d] = String(s).slice(0, 10).split('-'); return `${d}/${m}/${y}`; },
  },
};
</script>

<style scoped>
.cp-layout { display: grid; grid-template-columns: 300px minmax(0, 1fr); gap: 14px; align-items: start; }
.cp-panel { position: sticky; top: 12px; display: flex; flex-direction: column; gap: 10px; padding: 12px; border: 1px solid #e3e7e1; border-radius: 12px; background: #fff; }
.cp-bloque { display: flex; flex-direction: column; gap: 6px; padding-bottom: 10px; border-bottom: 1px solid #edf1ec; }
.cp-tit { margin: 0; color: #1f5632; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
.cp-tit small { color: #6b7568; font-weight: 600; text-transform: none; }
.cp-elegidas { display: flex; flex-wrap: wrap; gap: 4px; }
.cp-chip { display: inline-flex; align-items: center; gap: 4px; padding: 2px 4px 2px 9px; border-left: 4px solid var(--c); border-radius: 6px; background: color-mix(in srgb, var(--c) 12%, #fff); color: #26331f; font-size: 11.5px; font-weight: 600; }
.cp-chip button { border: 0; background: transparent; color: #6b7568; font-size: 14px; line-height: 1; }
.cp-estratos { display: flex; flex-wrap: wrap; gap: 4px; }
.cp-estratos button { padding: 1px 8px; border: 1px solid #d8ddd5; border-left: 4px solid var(--c); border-radius: 6px; background: #fff; color: #5b6658; font-size: 11px; font-weight: 600; }
.cp-estratos button.is-on { background: color-mix(in srgb, var(--c) 16%, #fff); color: #26331f; }
.cp-lista { max-height: 300px; overflow-y: auto; border: 1px solid #edf1ec; border-radius: 8px; }
.cp-est { display: flex; gap: 8px; align-items: flex-start; margin: 0; padding: 6px 8px; border-bottom: 1px solid #f2f4f1; cursor: pointer; font-weight: 400; }
.cp-est:hover { background: #f7fbf1; }
.cp-est.is-off { opacity: .45; cursor: default; }
.cp-est input { margin-top: 3px; }
.cp-est b { display: block; color: #26331f; font-size: 12.5px; }
.cp-est small { color: #6b7568; font-size: 11px; }
.cp-dot { display: inline-block; width: 8px; height: 8px; margin-right: 4px; border-radius: 50%; vertical-align: middle; }
.cp-dot-l { width: 10px; height: 10px; margin-right: 6px; }
.cp-fila { display: flex; align-items: center; gap: 6px; }
.cp-fila small { color: #6b7568; font-size: 11px; }
.cp-tbase { width: 80px; }
.cp-seg { display: inline-flex; padding: 3px; border: 1px solid #e3e7e1; border-radius: 8px; background: #f3f5f1; }
.cp-seg button { flex: 1; padding: 4px 10px; border: 0; border-radius: 6px; background: transparent; color: #6b7568; font-size: 12px; font-weight: 600; }
.cp-seg button.is-on { background: #73BC25; color: #fff; }
.cp-error { margin: 0; color: #b84d00; font-size: 12px; }
.cp-muted { color: #6b7568; font-size: 12px; }
.cp-res { min-width: 0; }
.cp-vacio { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 40px 16px; border: 1px dashed #CED2D5; border-radius: 12px; color: #6b7568; font-size: 13px; text-align: center; }
.cp-vacio > i { color: #CED2D5; font-size: 26px; }
.cp-vacio.is-error { border-color: #ffd2b3; color: #b84d00; }
.cp-aviso { margin: 0 0 10px; padding: 7px 12px; border-radius: 8px; background: #fff8ec; color: #7a4a00; font-size: 12.5px; }
.cp-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.cp-head h6 { margin: 0; color: #1f5632; font-size: 14px; font-weight: 700; }
.cp-head h6 i { margin-right: 6px; color: #73BC25; }
.cp-tabla-wrap { overflow-x: auto; border: 1px solid #edf1ec; border-radius: 10px; background: #fff; }
.cp-tabla { margin: 0; font-size: 12.5px; }
.cp-tabla th { background: #f7f9f5; color: #6b7568; font-size: 11px; white-space: nowrap; }
.cp-tabla th small { font-weight: 500; text-transform: none; }
.cp-tabla td { vertical-align: middle; font-variant-numeric: tabular-nums; }
.cp-estr { padding: 1px 8px; border-left: 4px solid var(--c); border-radius: 6px; background: color-mix(in srgb, var(--c) 12%, #fff); font-size: 11px; font-weight: 700; }
.cp-bajo { color: #b84d00; font-weight: 600; }
.cp-sube { color: #2f5a14; }
.cp-baja { color: #b84d00; }
.cp-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-top: 12px; }
.cp-ancho { grid-column: 1 / -1; }
.cp-card { padding: 8px; border: 1px solid #e3e7e1; border-radius: 12px; background: #fff; }
.cp-nota { margin-top: 10px; color: #6b7568; font-size: 11.5px; }
@media (max-width: 991px) {
  .cp-layout, .cp-grid { grid-template-columns: 1fr; }
  .cp-panel { position: static; }
}
</style>
