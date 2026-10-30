<template>
  <div class="ci">
    <!-- Estado de la API -->
    <div class="ci-api" :class="{ 'is-off': !icc.configurada }">
      <i class="fas fa-plug"></i>
      <span v-if="icc.configurada">
        API agromet del ICC conectada · <b>{{ icc.servidor }}</b>
        <template v-if="icc.cobertura"> · {{ icc.cobertura.dias }} dias descargados entre {{ fmt(icc.cobertura.desde) }} y {{ fmt(icc.cobertura.hasta) }} · {{ icc.estaciones }} estaciones</template>
      </span>
      <span v-else>API del ICC sin configurar: se muestran solo los datos ya guardados en SIGEC.<a v-if="puedeGestionar" href="#" @click.prevent="vista = 'api'"> Configurar la conexion</a></span>
    </div>

    <div class="ci-tabs">
      <button type="button" :class="{ 'is-active': vista === 'base' }" @click="vista = 'base'"><i class="fas fa-database"></i> Base de datos</button>
      <button type="button" :class="{ 'is-active': vista === 'consulta' }" @click="vista = 'consulta'"><i class="fas fa-table"></i> Consulta y exportacion</button>
      <button type="button" :class="{ 'is-active': vista === 'analisis' }" @click="vista = 'analisis'"><i class="fas fa-chart-line"></i> Años y estratos</button>
      <button type="button" :class="{ 'is-active': vista === 'comparar' }" @click="vista = 'comparar'"><i class="fas fa-balance-scale"></i> Comparar estaciones</button>
      <button type="button" :class="{ 'is-active': vista === 'estaciones' }" @click="vista = 'estaciones'">
        <i class="fas fa-tags"></i> Estaciones y categorias
        <span v-if="sinUbicacion" class="ci-badge" :title="`${sinUbicacion} estacion(es) sin coordenadas`">{{ sinUbicacion }}</span>
      </button>
      <button v-if="puedeGestionar" type="button" :class="{ 'is-active': vista === 'api' }" @click="vista = 'api'">
        <i class="fas fa-plug"></i> Conexion API ICC
      </button>
    </div>

    <clima-api-config v-if="vista === 'api'" @guardado="cargarIcc"></clima-api-config>
    <!-- Fuera del v-if: la descarga del historial continua aunque se cambie de pestaña. -->
    <!-- v-show (no v-if): la descarga del historial sigue aunque se cambie de pestaña. -->
    <clima-base-datos v-show="vista === 'base'" :configurada="icc.configurada" :puede-sincronizar="puedeSincronizar" :puede-gestionar="puedeGestionar" @actualizado="cargarIcc(); cargarEstaciones()"></clima-base-datos>
    <clima-analisis v-if="vista === 'analisis'" :estaciones="estaciones"></clima-analisis>
    <clima-comparar v-if="vista === 'comparar'" :estaciones="estaciones"></clima-comparar>
    <clima-estaciones v-if="vista === 'estaciones'" :estaciones="estaciones" :puede-gestionar="puedeGestionar" :puede-cargar-datos="puedeSincronizar" @recargar="cargarEstaciones"></clima-estaciones>

    <div class="ci-layout" v-show="vista === 'consulta'">
      <!-- ============ Filtros ============ -->
      <aside class="ci-filtros">
        <div class="ci-bloque">
          <label class="ci-tit">Estaciones <small>({{ sel.length }} de {{ estaciones.length }})</small></label>
          <input class="form-control form-control-sm" v-model="buscar" placeholder="Buscar nombre o codigo...">
          <div class="ci-fila">
            <select class="form-control form-control-sm" v-model="red">
              <option value="">Todas las redes</option>
              <option v-for="r in redes" :key="r" :value="r">{{ r }}</option>
            </select>
          </div>
          <div class="ci-links">
            <a href="#" @click.prevent="marcar(true)">Marcar visibles</a> · <a href="#" @click.prevent="marcar(false)">Desmarcar</a>
          </div>
          <div class="ci-lista">
            <label v-for="s in visibles" :key="s.id" class="ci-est">
              <input type="checkbox" :value="s.id" v-model="sel">
              <span>
                <b>{{ s.nombre }}</b>
                <small>{{ s.codigo }} · {{ s.red }}{{ s.registros_count ? ` · ${s.registros_count} dias` : '' }}</small>
              </span>
            </label>
            <p v-if="!visibles.length" class="ci-muted">Ninguna estacion coincide.</p>
          </div>
        </div>

        <div class="ci-bloque">
          <label class="ci-tit">Periodo</label>
          <select class="form-control form-control-sm" v-model="preset" @change="aplicarPreset">
            <option v-for="p in presets" :key="p.id" :value="p.id">{{ p.label }}</option>
          </select>
          <div class="ci-fila">
            <div><small>Desde</small><input type="date" class="form-control form-control-sm" v-model="desde" :max="hasta || hoy" @change="preset = 'custom'"></div>
            <div><small>Hasta</small><input type="date" class="form-control form-control-sm" v-model="hasta" :min="desde" :max="hoy" @change="preset = 'custom'"></div>
          </div>
        </div>

        <div class="ci-bloque">
          <label class="ci-tit">Agrupar</label>
          <div class="ci-seg">
            <button v-for="a in agrupaciones" :key="a.id" type="button" :class="{ 'is-active': agrupar === a.id }" @click="agrupar = a.id">{{ a.label }}</button>
          </div>
          <label class="ci-tit mt-2">Variables</label>
          <div class="ci-vars">
            <label v-for="v in variables" :key="v.id"><input type="checkbox" :value="v.id" v-model="varsSel"> {{ v.corto }}</label>
          </div>
          <div class="ci-tbase" v-if="varsSel.includes('gdd')">
            <small>T base grados dia</small>
            <input type="number" min="0" max="40" step="0.5" class="form-control form-control-sm" v-model.number="tbase"> <small>°C</small>
          </div>
        </div>

        <p v-if="errorFiltro" class="ci-error"><i class="fas fa-exclamation-circle"></i> {{ errorFiltro }}</p>
        <button type="button" class="btn btn-success btn-block" :disabled="cargando || !!errorFiltro" @click="consultar">
          <i :class="cargando ? 'fas fa-spinner fa-spin' : 'fas fa-search'"></i> {{ cargando ? 'Consultando...' : 'Consultar' }}
        </button>
        <p class="ci-muted mb-0">
          <i class="fas fa-database"></i> Se consulta lo guardado en la base. Para descargar o corregir datos use
          <a href="#" @click.prevent="vista = 'base'">Base de datos</a>.
        </p>
      </aside>

      <!-- ============ Resultados ============ -->
      <section class="ci-res">
        <div v-if="cargando" class="ci-vacio"><i class="fas fa-spinner fa-spin"></i> {{ icc.configurada ? 'Consultando y completando desde la API del ICC...' : 'Consultando...' }}</div>
        <div v-else-if="error" class="ci-vacio is-error"><i class="fas fa-exclamation-circle"></i> {{ error }}</div>
        <div v-else-if="!consultado" class="ci-vacio">
          <i class="fas fa-cloud-sun-rain"></i>
          Elija estaciones y periodo, y pulse <b>Consultar</b>.
        </div>
        <template v-else>
          <div class="ci-sync" v-if="sync">
            <template v-if="sync.error"><i class="fas fa-exclamation-triangle"></i> API del ICC: {{ sync.error }} Se muestran los datos ya guardados.</template>
            <template v-else-if="sync.consultada"><i class="fas fa-cloud-download-alt"></i> Se descargaron de la API {{ sync.registros }} registros ({{ sync.dias }} dias){{ sync.estaciones_nuevas ? ` y ${sync.estaciones_nuevas} estacion(es) nueva(s)` : '' }}.</template>
            <template v-else-if="sync.usa_api"><i class="fas fa-check-circle"></i> Datos al dia con la API del ICC.</template>
          </div>

          <div class="ci-head">
            <h6><i class="fas fa-list-alt"></i> Resumen por estacion · {{ fmt(desde) }} → {{ fmt(hasta) }}</h6>
            <button type="button" class="btn btn-success btn-sm" :disabled="!grupos.length" @click="exportar"><i class="fas fa-file-csv"></i> Exportar CSV</button>
          </div>
          <div class="ci-tabla-wrap">
            <table class="table ci-tabla">
              <thead>
                <tr>
                  <th>Estacion</th><th class="text-right">Dias</th>
                  <th v-for="v in varsVisibles" :key="v.id" class="text-right">{{ v.corto }}<small>{{ v.resumen }}</small></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in resumen" :key="r.id">
                  <td><b>{{ r.nombre }}</b> <small class="ci-muted">{{ r.codigo }}</small></td>
                  <td class="text-right" :class="{ 'ci-bajo': r.cobertura < 80 }" :title="`${r.cobertura} % del periodo`">{{ r.dias }} / {{ totalDias }}</td>
                  <td v-for="v in varsVisibles" :key="v.id" class="text-right">{{ num(r[v.id], v.dec) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="ci-chart" v-if="resumen.length > 1 && varsSel.includes('lluvia')">
            <highcharts :options="chartLluvia"></highcharts>
          </div>

          <div class="ci-head mt-3">
            <h6><i class="fas fa-table"></i> Detalle por {{ etiquetaGrupo.toLowerCase() }} <small class="ci-muted">({{ grupos.length }} filas)</small></h6>
          </div>
          <div v-if="!grupos.length" class="ci-vacio">Sin registros guardados para las estaciones y el periodo elegidos.</div>
          <div v-else class="ci-tabla-wrap ci-detalle">
            <table class="table ci-tabla">
              <thead>
                <tr><th>Estacion</th><th>{{ etiquetaGrupo }}</th><th v-if="agrupar !== 'dia'" class="text-right">Dias</th><th v-for="v in varsVisibles" :key="v.id" class="text-right">{{ v.corto }}<small>{{ v.unidad }}</small></th></tr>
              </thead>
              <tbody>
                <tr v-for="g in grupos.slice(0, MAX_FILAS)" :key="g.clave">
                  <td>{{ g.estacion }}</td><td class="ci-nowrap">{{ g.etiqueta }}</td>
                  <td v-if="agrupar !== 'dia'" class="text-right">{{ g.dias }}</td>
                  <td v-for="v in varsVisibles" :key="v.id" class="text-right">{{ num(g[v.id], v.dec) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="grupos.length > MAX_FILAS" class="ci-muted mt-2">Se muestran las primeras {{ MAX_FILAS }} filas; el CSV incluye las {{ grupos.length }}.</p>
          <p class="ci-nota">
            <i class="fas fa-info-circle"></i> Lluvia y ETo se suman por periodo; las demas variables se promedian. HR = promedio de la humedad minima y maxima diarias.
            Radiacion global (piranometro) en MJ/m²·dia; viento en km/h.
          </p>
        </template>
      </section>
    </div>
  </div>
</template>

<script>
import ClimaEstaciones from './estaciones.vue';
import ClimaApiConfig from './api-config.vue';
import ClimaAnalisis from './analisis.vue';
import ClimaComparar from './comparar.vue';
import ClimaBaseDatos from './base-datos.vue';


const DIA = 86400000;

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const parse = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
const VARIABLES = [
  { id: 'lluvia', corto: 'Lluvia', unidad: 'mm', dec: 1, resumen: 'total' },
  { id: 'tmax', corto: 'T max', unidad: '°C', dec: 1, resumen: 'prom.' },
  { id: 'tmin', corto: 'T min', unidad: '°C', dec: 1, resumen: 'prom.' },
  { id: 'tmed', corto: 'T media', unidad: '°C', dec: 1, resumen: 'prom.' },
  { id: 'hr', corto: 'HR', unidad: '%', dec: 0, resumen: 'prom.' },
  { id: 'hr_min', corto: 'HR min', unidad: '%', dec: 0, resumen: 'prom.' },
  { id: 'hr_max', corto: 'HR max', unidad: '%', dec: 0, resumen: 'prom.' },
  { id: 'viento', corto: 'Viento', unidad: 'km/h', dec: 1, resumen: 'prom.' },
  { id: 'radiacion', corto: 'Radiacion', unidad: 'MJ/m²', dec: 1, resumen: 'prom.' },
  { id: 'eto', corto: 'ETo', unidad: 'mm', dec: 1, resumen: 'total' },
  { id: 'vpd', corto: 'DPV', unidad: 'kPa', dec: 2, resumen: 'prom.' },
  { id: 'gdd', corto: 'Grados dia', unidad: '°C·d', dec: 1, resumen: 'total' },
];

export default {
  name: 'ClimaIndex',
  components: { ClimaEstaciones, ClimaApiConfig, ClimaAnalisis, ClimaComparar, ClimaBaseDatos },
  props: {
    puedeSincronizar: { type: Boolean, default: false },
    puedeGestionar: { type: Boolean, default: false },
  },
  data() {
    const hoy = iso(new Date());
    return {
      vista: ['estaciones', 'api', 'analisis', 'comparar', 'base'].includes(new URLSearchParams(window.location.search).get('vista')) ? new URLSearchParams(window.location.search).get('vista') : 'consulta',
      MAX_FILAS: 1000,
      hoy,
      icc: { configurada: false },
      estaciones: [],
      redes: [],
      buscar: '',
      red: '',
      sel: [],
      preset: 'ult30',
      desde: iso(new Date(Date.now() - 29 * DIA)),
      hasta: hoy,
      agrupar: 'dia',
      agrupaciones: [{ id: 'dia', label: 'Dia' }, { id: 'semana', label: 'Semana' }, { id: 'mes', label: 'Mes' }, { id: 'anio', label: 'Año' }],
      variables: VARIABLES,
      varsSel: ['lluvia', 'tmax', 'tmin', 'tmed', 'hr', 'viento', 'radiacion', 'eto', 'vpd', 'gdd'],
      tbase: 18,
      cargando: false,
      consultado: false,
      error: null,
      sync: null,
      datos: null,
      consultaSel: [],
      desdeConsulta: null,
      hastaConsulta: null,
    };
  },
  computed: {
    presets() {
      return [
        { id: 'ult7', label: 'Ultimos 7 dias' }, { id: 'ult30', label: 'Ultimos 30 dias' }, { id: 'ult90', label: 'Ultimos 90 dias' },
        { id: 'mes', label: 'Este mes' }, { id: 'mesPrev', label: 'Mes anterior' }, { id: 'anio', label: 'Este año' },
        { id: 'zafra', label: 'Zafra actual (nov–may)' }, { id: 'zafraPrev', label: 'Zafra anterior' },
        { id: 'ult5', label: 'Ultimos 5 años' }, { id: 'ult10', label: 'Ultimos 10 años' }, { id: 'todo', label: 'Todo el historial (desde 2009)' },
        { id: 'custom', label: 'Personalizado' },
      ];
    },
    visibles() {
      const q = this.buscar.trim().toLowerCase();
      return this.estaciones.filter((s) => (!this.red || s.red === this.red)
        && (!q || `${s.nombre} ${s.codigo} ${s.ubicacion || ''}`.toLowerCase().includes(q)));
    },
    errorRango() {
      if (!this.desde || !this.hasta) return 'Indique las fechas.';
      if (this.desde > this.hasta) return 'La fecha inicial es posterior a la final.';
      const dias = (parse(this.hasta) - parse(this.desde)) / DIA;
      if (this.agrupar === 'dia' && dias > 1100) return 'Por dia se consultan hasta 3 años: agrupe por semana, mes o año para periodos mas largos.';
      if (this.agrupar === 'semana' && dias > 3700) return 'Por semana se consultan hasta 10 años: agrupe por mes o año para periodos mas largos.';
      return null;
    },
    errorFiltro() {
      if (!this.sel.length) return 'Elija al menos una estacion.';
      if (!this.varsSel.length) return 'Elija al menos una variable.';
      return this.errorRango;
    },
    sinUbicacion() { return this.estaciones.filter((s) => s.lat === null || s.lng === null).length; },
    varsVisibles() { return this.variables.filter((v) => this.varsSel.includes(v.id)); },
    totalDias() { return this.datos ? this.datos.total_dias : 0; },
    /** Totales del periodo por estacion (calculados en el servidor). */
    resumen() {
      if (!this.datos) return [];
      return this.datos.estaciones.map((e) => {
        const r = { id: e.id, nombre: e.nombre, codigo: e.codigo, dias: e.dias, cobertura: Math.round((e.dias / this.totalDias) * 100) };
        VARIABLES.forEach((v) => { r[v.id] = e.totales[v.id] ?? null; });
        return r;
      }).sort((a, b) => a.nombre.localeCompare(b.nombre));
    },
    etiquetaGrupo() { return { dia: 'Fecha', semana: 'Semana', mes: 'Mes', anio: 'Año' }[this.datos ? this.datos.agrupar : this.agrupar]; },
    /** Detalle por estacion y periodo (solo periodos con datos). */
    grupos() {
      if (!this.datos) return [];
      const out = [];
      const porId = {};
      this.datos.estaciones.forEach((e) => { porId[e.id] = e; });
      this.resumen.forEach((est) => {
        const e = porId[est.id];
        this.datos.periodos.forEach((k, i) => {
          if (!e.dias_periodo[i]) return;
          const fila = { clave: `${est.id}-${k}`, orden: k, etiqueta: this.etiquetaPeriodo(k), estacion: est.nombre, codigo: est.codigo, dias: e.dias_periodo[i] };
          VARIABLES.forEach((v) => { fila[v.id] = e.serie[v.id] ? e.serie[v.id][i] : null; });
          out.push(fila);
        });
      });
      return out;
    },
    chartLluvia() {
      const datos = this.resumen.filter((r) => r.lluvia !== null).sort((a, b) => b.lluvia - a.lluvia);
      return {
        chart: { type: 'bar', height: Math.max(160, 34 * datos.length + 60), style: { fontFamily: 'inherit' } },
        title: { text: 'Lluvia acumulada por estacion (mm)', style: { fontSize: '13px', fontWeight: '700' } },
        credits: { enabled: false }, legend: { enabled: false },
        xAxis: { categories: datos.map((r) => r.nombre), lineColor: '#CED2D5' },
        yAxis: { title: { text: null }, gridLineColor: '#edf1ec' },
        tooltip: { valueSuffix: ' mm', valueDecimals: 1 },
        plotOptions: { bar: { borderRadius: 4, pointPadding: 0.1, groupPadding: 0.08, dataLabels: { enabled: true, format: '{y:.0f}', style: { fontWeight: '600', textOutline: 'none' } } } },
        series: [{ name: 'Lluvia', color: '#73BC25', data: datos.map((r) => r.lluvia) }],
      };
    },
  },
  watch: {
    // Los totales se agregan en el servidor: cambiar la agrupacion o la T base vuelve a consultar.
    agrupar() { if (this.consultado && !this.errorFiltro) this.consultar(); },
    tbase() { if (this.consultado && this.varsSel.includes('gdd') && !this.errorFiltro) this.consultar(); },
  },
  mounted() {
    this.cargarIcc().then(this.actualizarSiHaceFalta);
    this.cargarEstaciones();
  },
  methods: {
    cargarIcc() {
      return window.axios.get('/investigacion/clima/icc').then(({ data }) => { this.icc = data; }).catch(() => {});
    },
    /**
     * Al abrir Clima: si la base no se actualiza desde hace mas de 3 horas,
     * se piden los ultimos 7 dias a la API (queda en la bitacora como "Automatica").
     */
    actualizarSiHaceFalta() {
      if (!this.icc.configurada) return;
      const ultima = this.icc.cobertura && this.icc.cobertura.ultima ? new Date(this.icc.cobertura.ultima.replace(' ', 'T')) : null;
      if (ultima && Date.now() - ultima.getTime() < 3 * 3600 * 1000) return;
      const fecha = (d) => iso(d);
      window.axios.post('/investigacion/clima/icc/sincronizar', {
        desde: fecha(new Date(Date.now() - 6 * DIA)), hasta: this.hoy, tipo: 'automatica', forzar: 1,
      })
        .then(({ data }) => {
          if (data.registros) {
            this.$swal.fire({ toast: true, position: 'top-end', icon: 'info', title: 'Base climatica actualizada', text: `${data.registros} registros de los ultimos dias`, timer: 2600, showConfirmButton: false });
          }
          this.cargarIcc();
          this.cargarEstaciones();
        })
        .catch(() => {});
    },
    cargarEstaciones() {
      return window.axios.get('/investigacion/clima/estaciones').then(({ data }) => {
        this.estaciones = data.estaciones;
        this.redes = [...new Set(data.estaciones.map((s) => s.red))].sort();
        // Sin estaciones con datos todavia: se abre directo el listado de ubicaciones.
        if (!this.estaciones.length && this.vista === 'consulta') this.vista = 'estaciones';
      });
    },
    marcar(si) {
      const ids = this.visibles.map((s) => s.id);
      this.sel = si ? [...new Set([...this.sel, ...ids])] : this.sel.filter((id) => !ids.includes(id));
    },
    aplicarPreset() {
      const h = new Date();
      const y = h.getFullYear();
      const m = h.getMonth();
      const zafraIni = m >= 10 ? y : y - 1;
      const menor = (a, b) => (a < b ? a : b);
      const r = {
        ult7: [iso(new Date(Date.now() - 6 * DIA)), this.hoy],
        ult30: [iso(new Date(Date.now() - 29 * DIA)), this.hoy],
        ult90: [iso(new Date(Date.now() - 89 * DIA)), this.hoy],
        mes: [iso(new Date(y, m, 1)), this.hoy],
        mesPrev: [iso(new Date(y, m - 1, 1)), iso(new Date(y, m, 0))],
        anio: [`${y}-01-01`, this.hoy],
        zafra: [`${zafraIni}-11-01`, menor(`${zafraIni + 1}-05-31`, this.hoy)],
        zafraPrev: [`${zafraIni - 1}-11-01`, `${zafraIni}-05-31`],
        ult5: [`${y - 4}-01-01`, this.hoy],
        ult10: [`${y - 9}-01-01`, this.hoy],
        todo: ['2009-01-01', this.hoy],
      }[this.preset];
      if (!r) return;
      [this.desde, this.hasta] = r;
      this.agrupar = ['ult7', 'ult30', 'mes', 'mesPrev'].includes(this.preset) ? 'dia' : this.preset === 'ult90' ? 'semana' : ['ult10', 'todo'].includes(this.preset) ? 'anio' : 'mes';
    },
    consultar() {
      if (this.errorFiltro) return;
      this.cargando = true;
      this.error = null;
      // Agregado en el servidor (dia, semana, mes o año): permite consultar periodos largos.
      const params = { estaciones: this.sel, desde: this.desde, hasta: this.hasta, agrupar: this.agrupar, tbase: Number(this.tbase) || 0 };
      window.axios.get('/investigacion/clima/serie', { params })
        .then(({ data }) => {
          this.datos = data;
          this.sync = data.sync;
          this.consultaSel = this.sel.slice();
          this.desdeConsulta = this.desde;
          this.hastaConsulta = this.hasta;
          this.consultado = true;
          if (data.sync && data.sync.consultada) {
            window.axios.get('/investigacion/clima/icc').then((r) => { this.icc = r.data; }).catch(() => {});
            window.axios.get('/investigacion/clima/estaciones').then((r) => { this.estaciones = r.data.estaciones; });
          }
        })
        .catch((err) => {
          const r = err.response;
          this.error = (r && r.data && (r.data.message || (r.data.errors && Object.values(r.data.errors).flat()[0]))) || 'No se pudo consultar.';
        })
        .finally(() => { this.cargando = false; });
    },
    /** Etiqueta de la clave de periodo del servidor (Y-m-d, lunes Y-m-d, Y-m o Y). */
    etiquetaPeriodo(k) {
      const agr = this.datos.agrupar;
      if (agr === 'anio') return k;
      if (agr === 'mes') {
        const [y, m] = k.split('-');
        return `${['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'][Number(m) - 1]} ${y}`;
      }
      return agr === 'semana' ? `Sem. ${this.fmt(k)}` : this.fmt(k);
    },
    exportar() {
      const num = (v, d) => (v === null || v === undefined ? '' : Number(v).toFixed(d).replace('.', ','));
      const vars = this.varsVisibles;
      const lineas = [
        `Periodo;${this.desdeConsulta} a ${this.hastaConsulta}`,
        `Agrupacion;${this.etiquetaGrupo}`,
        '',
        ['Estacion', 'Codigo', this.etiquetaGrupo, ...(this.agrupar !== 'dia' ? ['Dias'] : []), ...vars.map((v) => `${v.corto} (${v.unidad})`)].join(';'),
        ...this.grupos.map((g) => [
          g.estacion, g.codigo, this.agrupar === 'dia' ? g.orden : g.etiqueta, ...(this.agrupar !== 'dia' ? [g.dias] : []),
          ...vars.map((v) => num(g[v.id], v.dec)),
        ].join(';')),
      ];
      const blob = new Blob([`﻿${lineas.join('\r\n')}`], { type: 'text/csv;charset=utf-8' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = `clima_${this.desdeConsulta}_${this.hastaConsulta}_${this.agrupar}.csv`;
      document.body.appendChild(a);
      a.click();
      setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    },
    num(v, d) { return v === null || v === undefined ? '—' : Number(v).toFixed(d); },
    fmt(s) {
      if (!s) return '';
      const [y, m, d] = String(s).slice(0, 10).split('-');
      return `${d}/${m}/${y}`;
    },
  },
};
</script>

<style scoped>
.ci-api { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; padding: 8px 12px; border: 1px solid #d6e4f3; border-radius: 10px; background: #f4f8fd; color: #1d4f80; font-size: 12.5px; }
.ci-api.is-off { border-color: #ecdcb0; background: #fffbea; color: #7a5d00; }
.ci-tabs { display: inline-flex; flex-wrap: wrap; gap: 4px; margin-bottom: 12px; padding: 4px; border: 1px solid #e3e7e1; border-radius: 10px; background: #f3f5f1; }
.ci-tabs button { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border: 0; border-radius: 7px; background: transparent; color: #5b6658; font-size: 13px; font-weight: 600; }
.ci-tabs button.is-active { background: #fff; color: #1f5632; box-shadow: 0 1px 3px rgba(30, 42, 26, .12); }
.ci-tabs button i { color: #73BC25; }
.ci-badge { min-width: 18px; padding: 0 6px; border-radius: 999px; background: #FF6B00; color: #fff; font-size: 11px; line-height: 18px; }
.ci-layout { display: grid; grid-template-columns: 290px minmax(0, 1fr); gap: 14px; align-items: start; }
.ci-filtros { position: sticky; top: 12px; display: flex; flex-direction: column; gap: 10px; padding: 12px; border: 1px solid var(--cengi-border, #e3e7e1); border-radius: 12px; background: #fff; }
.ci-bloque { display: flex; flex-direction: column; gap: 6px; padding-bottom: 10px; border-bottom: 1px solid #edf1ec; }
.ci-tit { margin: 0; color: var(--cengi-primary-deep, #1f5632); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
.ci-tit small { color: var(--cengi-muted, #6b7568); font-weight: 600; text-transform: none; }
.ci-fila { display: flex; gap: 6px; }
.ci-fila > div { flex: 1; }
.ci-fila small { color: var(--cengi-muted, #6b7568); font-size: 11px; }
.ci-links { font-size: 11.5px; }
.ci-lista { max-height: 300px; overflow-y: auto; border: 1px solid #edf1ec; border-radius: 8px; }
.ci-est { display: flex; gap: 8px; align-items: flex-start; margin: 0; padding: 6px 8px; border-bottom: 1px solid #f2f4f1; cursor: pointer; font-weight: 400; }
.ci-est:hover { background: #f7fbf1; }
.ci-est input { margin-top: 3px; }
.ci-est b { display: block; color: #26331f; font-size: 12.5px; }
.ci-est small { color: var(--cengi-muted, #6b7568); font-size: 11px; }
.ci-seg { display: inline-flex; padding: 3px; border: 1px solid var(--cengi-border, #e3e7e1); border-radius: 8px; background: #f3f5f1; }
.ci-seg button { flex: 1; padding: 4px 10px; border: 0; border-radius: 6px; background: transparent; color: var(--cengi-muted, #6b7568); font-size: 12px; font-weight: 600; }
.ci-seg button.is-active { background: #73BC25; color: #fff; }
.ci-vars { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 8px; font-size: 12px; }
.ci-vars label { margin: 0; font-weight: 400; }
.ci-tbase { display: flex; align-items: center; gap: 6px; margin-top: 6px; }
.ci-tbase input { width: 70px; }
.ci-tbase small { color: #6b7568; font-size: 11px; }
.ci-error { margin: 0; color: #b84d00; font-size: 12px; }
.ci-res { min-width: 0; }
.ci-vacio { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 40px 16px; border: 1px dashed #CED2D5; border-radius: 12px; color: var(--cengi-muted, #6b7568); font-size: 13px; text-align: center; }
.ci-vacio > i { color: #CED2D5; font-size: 26px; }
.ci-vacio.is-error { border-color: #ffd2b3; color: #b84d00; }
.ci-sync { margin-bottom: 10px; padding: 7px 12px; border-radius: 8px; background: #eef8df; color: #3f7a12; font-size: 12.5px; }
.ci-sync i { margin-right: 5px; }
.ci-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.ci-head h6 { margin: 0; color: var(--cengi-primary-deep, #1f5632); font-size: 14px; font-weight: 700; }
.ci-head h6 i { margin-right: 6px; color: #73BC25; }
.ci-tabla-wrap { overflow-x: auto; border: 1px solid #edf1ec; border-radius: 10px; background: #fff; }
.ci-detalle { max-height: 520px; overflow-y: auto; }
.ci-tabla { margin: 0; font-size: 12.5px; }
.ci-tabla th { position: sticky; top: 0; z-index: 1; background: #f7f9f5; color: var(--cengi-muted, #6b7568); font-size: 11px; white-space: nowrap; }
.ci-tabla th small { display: block; font-weight: 500; text-transform: none; }
.ci-tabla td { vertical-align: middle; font-variant-numeric: tabular-nums; }
.ci-bajo { color: #b84d00; font-weight: 600; }
.ci-nowrap { white-space: nowrap; }
.ci-muted { color: var(--cengi-muted, #6b7568); font-size: 12px; }
.ci-chart { margin-top: 12px; padding: 8px; border: 1px solid #edf1ec; border-radius: 10px; background: #fff; }
.ci-nota { margin-top: 10px; color: var(--cengi-muted, #6b7568); font-size: 11.5px; }
@media (max-width: 991px) {
  .ci-layout { grid-template-columns: 1fr; }
  .ci-filtros { position: static; }
}
</style>
