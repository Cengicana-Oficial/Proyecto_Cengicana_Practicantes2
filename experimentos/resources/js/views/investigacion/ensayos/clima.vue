<template>
  <div class="cl">
    <!-- ============ Controles ============ -->
    <div class="cl-controls">
      <div class="cl-field cl-field-est">
        <label>Estacion meteorologica</label>
        <select class="form-control" v-model="estacionSel" :disabled="cargandoEst">
          <option value="punto">Punto del ensayo — Open-Meteo (modelo)</option>
          <optgroup v-for="g in estacionesPorRed" :key="g.red" :label="g.red">
            <option v-for="s in g.items" :key="s.id" :value="s.id">
              {{ s.nombre }} ({{ s.codigo }}){{ s.distancia_km !== null ? ' · ' + s.distancia_km + ' km' : '' }}{{ usaApi(s) ? ' · API ICC' : s.registros_count ? ' · ' + s.registros_count + ' dias' : ' · sin datos importados' }}{{ s.activa ? '' : ' · inactiva' }}
            </option>
          </optgroup>
        </select>
      </div>
      <div class="cl-field">
        <label>Periodo</label>
        <select class="form-control" v-model="preset" @change="aplicarPreset">
          <option v-for="p in presets" :key="p.id" :value="p.id">{{ p.label }}</option>
        </select>
      </div>
      <div class="cl-field cl-field-fecha">
        <label>Desde</label>
        <input type="date" class="form-control" v-model="desde" :max="hasta || hoy" @change="preset = 'custom'">
      </div>
      <div class="cl-field cl-field-fecha">
        <label>Hasta</label>
        <input type="date" class="form-control" v-model="hasta" :min="desde" :max="hoy" @change="preset = 'custom'">
      </div>
      <div class="cl-field">
        <label>Agrupar</label>
        <div class="cl-seg">
          <button v-for="a in agrupaciones" :key="a.id" type="button" :class="{ 'is-active': agrupar === a.id }" @click="agrupar = a.id">{{ a.label }}</button>
        </div>
      </div>
      <div class="cl-actions">
        <button type="button" class="btn btn-success" @click="consultar" :disabled="cargando || !rangoValido">
          <i :class="cargando ? 'fas fa-spinner fa-spin' : 'fas fa-search'"></i> Consultar
        </button>
        <button type="button" class="btn btn-secondary" @click="exportarCsv" :disabled="!filas.length" title="Exportar a CSV (Excel)">
          <i class="fas fa-file-csv"></i>
        </button>
      </div>
    </div>
    <p class="cl-cercana" v-if="!cargandoEst">
      <template v-if="!tieneGps">
        <i class="fas fa-map-marker-alt"></i> Agregue las coordenadas GPS del ensayo para elegir automaticamente la estacion mas cercana.
      </template>
      <template v-else-if="cercana">
        <i class="fas fa-map-marker-alt"></i> Estacion mas cercana al ensayo: <b>{{ cercana.nombre }}</b> ({{ cercana.codigo }}) a <b>{{ cercana.distancia_km }} km</b>
        <span v-if="cercana.distancia_km > 25" class="cl-lejos"> · esta lejos: los datos pueden no representar el ensayo</span>
        <a v-if="estacionSel !== cercana.id" href="#" @click.prevent="estacionSel = cercana.id"> · usar esta</a>
        · <a :href="base + '/investigacion/clima?vista=estaciones'">ver estaciones en Clima y estaciones</a>
      </template>
      <template v-else>
        <i class="fas fa-map-marker-alt"></i> Ninguna estacion tiene ubicacion cargada: registrelas en <a :href="base + '/investigacion/clima?vista=estaciones'">Clima y estaciones → Estaciones y ubicaciones</a>.
      </template>
    </p>
    <label class="cl-check" v-if="estacion">
      <input type="checkbox" v-model="completar"> Completar dias sin registro de la estacion con Open-Meteo (en sus coordenadas)
    </label>
    <p class="cl-error" v-if="!rangoValido"><i class="fas fa-exclamation-circle"></i> {{ errorRango }}</p>

    <!-- ============ Resultados ============ -->
    <div v-if="cargando" class="cl-loading">
      <i class="fas fa-spinner fa-spin"></i>
      {{ estacion && usaApi(estacion) ? `Consultando ${estacion.nombre} en la API del ICC...` : 'Consultando datos climaticos...' }}
    </div>
    <div v-else-if="error" class="cl-empty">
      <i class="fas fa-exclamation-circle"></i> {{ error }}
      <button type="button" class="btn btn-secondary btn-sm mt-2" @click="consultar"><i class="fas fa-redo"></i> Reintentar</button>
    </div>
    <template v-else-if="filas.length">
      <div class="cl-fuente" :class="{ 'is-modelo': fuente.tipo !== 'estacion' }">
        <i :class="fuente.tipo === 'estacion' ? 'fas fa-broadcast-tower' : 'fas fa-globe-americas'"></i>
        <div>
          <strong>{{ fuente.titulo }}</strong>
          <small>{{ fuente.detalle }}</small>
        </div>
      </div>

      <div class="cl-kpis">
        <div v-for="k in kpis" :key="k.label" class="cl-kpi" :style="{ '--c': k.color }">
          <i :class="k.icon"></i>
          <div>
            <strong>{{ k.valor }}</strong>
            <span>{{ k.label }}</span>
            <small v-if="k.sub">{{ k.sub }}</small>
          </div>
        </div>
      </div>

      <div v-if="alertas.length" class="cl-alertas">
        <div v-for="(a, i) in alertas" :key="i"><i class="fas fa-exclamation-triangle"></i>{{ a }}</div>
      </div>

      <div class="cl-charts">
        <div class="cl-chart"><highcharts :options="chartLluvia"></highcharts></div>
        <div class="cl-chart"><highcharts :options="chartTemp"></highcharts></div>
        <div class="cl-chart"><highcharts :options="chartHr"></highcharts></div>
        <div class="cl-chart"><highcharts :options="chartViento"></highcharts></div>
        <div class="cl-chart"><highcharts :options="chartRadiacion"></highcharts></div>
        <div class="cl-chart"><highcharts :options="chartBalance"></highcharts></div>
        <div class="cl-chart"><highcharts :options="chartDpv"></highcharts></div>
        <div class="cl-chart">
          <div class="cl-tbase">
            <label class="mb-0">T base <input type="number" min="0" max="40" step="0.5" v-model.number="tbase"> °C</label>
          </div>
          <highcharts :options="chartGdd"></highcharts>
        </div>
      </div>

      <details class="cl-datos">
        <summary><i class="fas fa-table"></i> Ver tabla de datos ({{ grupos.length }} {{ agrupar === 'dia' ? 'dias' : agrupar === 'semana' ? 'semanas' : 'meses' }})</summary>
        <div class="cl-table-wrap cl-table-scroll">
          <table class="table cl-table">
            <thead>
              <tr><th>{{ etiquetaGrupo }}</th><th>Lluvia mm</th><th>T max °C</th><th>T min °C</th><th>T media °C</th><th>ΔT °C</th><th>HR %</th><th>Viento km/h</th><th>Rad. MJ/m²·d</th><th>ETo mm</th><th>Balance mm</th><th>DPV kPa</th><th>Grados dia</th></tr>
            </thead>
            <tbody>
              <tr v-for="g in grupos" :key="g.clave">
                <td class="cl-nowrap">{{ g.etiqueta }}<sup v-if="g.modelados" title="Incluye dias completados con Open-Meteo">*</sup></td>
                <td>{{ n(g.lluvia, 1) }}</td><td>{{ n(g.tmax, 1) }}</td><td>{{ n(g.tmin, 1) }}</td><td>{{ n(g.tmed, 1) }}</td><td>{{ n(g.at, 1) }}</td>
                <td>{{ n(g.hr, 0) }}</td><td>{{ n(g.viento, 1) }}</td><td>{{ n(g.radiacion, 1) }}</td><td>{{ n(g.eto, 1) }}</td>
                <td :class="g.balance < 0 ? 'cl-neg' : 'cl-pos'">{{ n(g.balance, 1) }}</td>
                <td>{{ n(g.vpd, 2) }}</td><td>{{ n(g.gdd, 1) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </details>
      <p class="cl-note">
        <i class="fas fa-info-circle"></i>
        Balance hidrico = lluvia − ETo. Lluvia y ETo se suman por {{ agrupar === 'dia' ? 'dia' : agrupar === 'semana' ? 'semana' : 'mes' }}; las demas variables son promedios de los valores diarios.
        <template v-if="fuente.etoEstimada"> La ETo de dias sin dato de la estacion se estimo con Hargreaves-Samani.</template>
        <template v-if="fuente.modelados"> * Periodos con dias completados con Open-Meteo.</template>
      </p>
    </template>
    <div v-else-if="consultado" class="cl-empty"><i class="fas fa-cloud"></i> No hay datos para la estacion y el periodo seleccionados.</div>
  </div>
</template>

<script>
import { derivados } from '../clima/clima-util';

const NOMBRES = { lluvia: 'Lluvia', tmax: 'T max', tmin: 'T min', tmed: 'T media', hr: 'HR', viento: 'Viento', radiacion: 'Radiacion', eto: 'ETo' };
const VARS = Object.keys(NOMBRES);
const TZ = 'America/Guatemala';
const MAX_DIAS = 366 * 5;
const DIA = 86400000;

function iso(d) {
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}
function parse(s) {
  const [y, m, d] = s.split('-').map(Number);
  return new Date(y, m - 1, d);
}
function sumar(a) { return a.reduce((s, v) => s + v, 0); }
function prom(a) { return a.length ? sumar(a) / a.length : null; }

export default {
  name: 'EnsayoClima',
  props: {
    ensayo: { type: Object, required: true },
  },
  data() {
    const hoy = iso(new Date());
    return {
      hoy,
      nombres: NOMBRES,
      estaciones: [],
      redes: [],
      cargandoEst: false,
      estacionSel: 'punto',
      preset: 'ult90',
      desde: iso(new Date(Date.now() - 89 * DIA)),
      hasta: hoy,
      agrupar: 'semana',
      agrupaciones: [{ id: 'dia', label: 'Dia' }, { id: 'semana', label: 'Semana' }, { id: 'mes', label: 'Mes' }],
      completar: true,
      tbase: 18,
      cargando: false,
      consultado: false,
      error: null,
      dias: [],
      fuente: {},
      // API agromet del ICC
      icc: { configurada: false, servidor: null, cobertura: null, estaciones: 0, sin_coordenadas: 0 },
      sync: null,
      cercana: null,
      base: ((document.querySelector('meta[name="app-base"]') || {}).content || '').replace(/\/+$/, ''),
    };
  },
  computed: {
    tieneGps() { return this.ensayo.lat !== null && this.ensayo.lat !== '' && this.ensayo.lng !== null && this.ensayo.lng !== ''; },
    estacion() { return this.estaciones.find((s) => s.id === this.estacionSel) || null; },
    estacionesPorRed() {
      const g = {};
      this.estaciones.forEach((s) => { (g[s.red] = g[s.red] || []).push(s); });
      return Object.keys(g).map((red) => ({ red: 'Red ' + red, items: g[red] }));
    },
    presets() {
      const hoy = new Date();
      const inicioZafra = hoy.getMonth() >= 10 ? hoy.getFullYear() : hoy.getFullYear() - 1;
      return [
        { id: 'ult30', label: 'Ultimos 30 dias' },
        { id: 'ult90', label: 'Ultimos 90 dias' },
        { id: 'ult12m', label: 'Ultimos 12 meses' },
        { id: 'anio', label: `Año ${hoy.getFullYear()}` },
        { id: 'anioPrev', label: `Año ${hoy.getFullYear() - 1}` },
        { id: 'zafra', label: `Zafra ${inicioZafra}–${inicioZafra + 1} (nov–may)` },
        { id: 'zafraPrev', label: `Zafra ${inicioZafra - 1}–${inicioZafra} (nov–may)` },
        { id: 'custom', label: 'Personalizado' },
      ];
    },
    errorRango() {
      if (!this.desde || !this.hasta) return 'Indique las fechas del periodo.';
      if (this.desde > this.hasta) return 'La fecha inicial debe ser anterior a la final.';
      if (this.hasta > this.hoy) return 'La fecha final no puede ser futura.';
      if ((parse(this.hasta) - parse(this.desde)) / DIA > MAX_DIAS) return 'El periodo maximo es de 5 años.';
      return null;
    },
    rangoValido() { return !this.errorRango; },
    filas() { return this.dias; },
    /** Dias con DPV, grados dia (T base) y amplitud termica (Tmax - Tmin). */
    diasCalc() {
      const tb = Number(this.tbase) || 0;
      return this.dias.map((d) => {
        const x = derivados(d, tb);
        x.at = d.tmax !== null && d.tmax !== undefined && d.tmin !== null && d.tmin !== undefined ? d.tmax - d.tmin : null;
        return x;
      });
    },
    grupos() {
      const mapa = {};
      this.diasCalc.forEach((d) => {
        const { clave, etiqueta } = this.claveGrupo(d.fecha);
        if (!mapa[clave]) mapa[clave] = { clave, etiqueta, dias: [] };
        mapa[clave].dias.push(d);
      });
      return Object.values(mapa).sort((a, b) => a.clave.localeCompare(b.clave)).map((g) => {
        const vals = (k) => g.dias.map((d) => d[k]).filter((v) => v !== null && v !== undefined);
        const r = { clave: g.clave, etiqueta: g.etiqueta, modelados: g.dias.some((d) => d.modelado) };
        r.lluvia = vals('lluvia').length ? sumar(vals('lluvia')) : null;
        r.eto = vals('eto').length ? sumar(vals('eto')) : null;
        ['tmax', 'tmin', 'tmed', 'hr', 'hr_min', 'hr_max', 'viento', 'radiacion'].forEach((k) => { r[k] = prom(vals(k)); });
        r.balance = r.lluvia !== null && r.eto !== null ? r.lluvia - r.eto : null;
        r.gdd = vals('gdd').length ? sumar(vals('gdd')) : null;
        r.vpd = prom(vals('vpd'));
        r.at = prom(vals('at'));
        return r;
      });
    },
    etiquetaGrupo() { return { dia: 'Fecha', semana: 'Semana (desde)', mes: 'Mes' }[this.agrupar]; },
    resumen() {
      const v = (k) => this.diasCalc.map((d) => d[k]).filter((x) => x !== null && x !== undefined);
      const lluvia = v('lluvia');
      const eto = v('eto');
      return {
        lluvia: lluvia.length ? sumar(lluvia) : null,
        diasLluvia: lluvia.filter((x) => x >= 1).length,
        lluviaMax: lluvia.length ? Math.max(...lluvia) : null,
        tmaxProm: prom(v('tmax')), tmaxAbs: v('tmax').length ? Math.max(...v('tmax')) : null,
        tminProm: prom(v('tmin')), tminAbs: v('tmin').length ? Math.min(...v('tmin')) : null,
        tmed: prom(v('tmed')), hr: prom(v('hr')), viento: prom(v('viento')), radiacion: prom(v('radiacion')),
        eto: eto.length ? sumar(eto) : null,
        diasCalor: v('tmax').filter((x) => x >= 35).length,
        vpd: prom(v('vpd')), vpdMax: v('vpd').length ? Math.max(...v('vpd')) : null,
        gdd: v('gdd').length ? sumar(v('gdd')) : null, diasGdd: v('gdd').length,
        at: prom(v('at')), atMax: v('at').length ? Math.max(...v('at')) : null,
        maxSeco: this.rachaSeca(),
      };
    },
    kpis() {
      const r = this.resumen;
      const bal = r.lluvia !== null && r.eto !== null ? r.lluvia - r.eto : null;
      return [
        { label: 'Lluvia total', valor: this.u(r.lluvia, 0, 'mm'), sub: `${r.diasLluvia} dias con lluvia · max ${this.u(r.lluviaMax, 0, 'mm')}`, icon: 'fas fa-cloud-rain', color: '#73BC25' },
        { label: 'Temp. maxima', valor: this.u(r.tmaxProm, 1, '°C'), sub: `prom. · extrema ${this.u(r.tmaxAbs, 1, '°C')}`, icon: 'fas fa-temperature-high', color: '#FF6B00' },
        { label: 'Temp. minima', valor: this.u(r.tminProm, 1, '°C'), sub: `prom. · extrema ${this.u(r.tminAbs, 1, '°C')}`, icon: 'fas fa-temperature-low', color: '#FFCC00' },
        { label: 'Temp. media', valor: this.u(r.tmed, 1, '°C'), icon: 'fas fa-thermometer-half', color: '#FF6B00' },
        { label: 'Amplitud termica (ΔT)', valor: this.u(r.at, 1, '°C'), sub: `Tmax − Tmin · max ${this.u(r.atMax, 1, '°C')}`, icon: 'fas fa-arrows-alt-v', color: '#FF6B00' },
        { label: 'Deficit de presion de vapor', valor: this.u(r.vpd, 2, 'kPa'), sub: `promedio · max ${this.u(r.vpdMax, 2, 'kPa')}`, icon: 'fas fa-smog', color: '#A3D300' },
        { label: 'Grados dia acumulados', valor: this.u(r.gdd, 0, '°C·d'), sub: r.gdd !== null && r.diasGdd ? `base ${this.tbase} °C · ${this.n(r.gdd / r.diasGdd, 1)} por dia` : `base ${this.tbase} °C`, icon: 'fas fa-temperature-high', color: '#FFCC00' },
        { label: 'Humedad relativa', valor: this.u(r.hr, 0, '%'), sub: 'promedio', icon: 'fas fa-tint', color: '#73BC25' },
        { label: 'Viento', valor: this.u(r.viento, 1, 'km/h'), sub: 'promedio', icon: 'fas fa-wind', color: '#A3D300' },
        { label: 'Radiacion solar', valor: this.u(r.radiacion, 1, 'MJ/m²·d'), sub: 'promedio diario', icon: 'fas fa-sun', color: '#FFCC00' },
        { label: 'ETo acumulada', valor: this.u(r.eto, 0, 'mm'), sub: r.eto !== null ? `${this.n(r.eto / this.dias.length, 1)} mm/dia` : '', icon: 'fas fa-leaf', color: '#A3D300' },
        { label: 'Balance hidrico', valor: this.u(bal, 0, 'mm'), sub: bal === null ? '' : bal < 0 ? 'deficit (lluvia < ETo)' : 'excedente (lluvia > ETo)', icon: 'fas fa-balance-scale', color: bal !== null && bal < 0 ? '#FF6B00' : '#73BC25' },
      ];
    },
    alertas() {
      const r = this.resumen;
      const a = [];
      const bal = r.lluvia !== null && r.eto !== null ? r.lluvia - r.eto : null;
      if (bal !== null && bal < -50) a.push(`Deficit hidrico acumulado de ${Math.abs(bal).toFixed(0)} mm en el periodo (la ETo supera a la lluvia).`);
      if (r.maxSeco >= 15) a.push(`Racha seca de ${r.maxSeco} dias consecutivos sin lluvia significativa (< 1 mm).`);
      if (r.diasCalor) a.push(`${r.diasCalor} dia(s) con temperatura maxima ≥ 35 °C (estres termico).`);
      if (r.lluviaMax !== null && r.lluviaMax >= 80) a.push(`Evento de lluvia intensa: ${r.lluviaMax.toFixed(0)} mm en un dia (riesgo de anegamiento).`);
      return a;
    },
    chartBase() {
      return {
        chart: { height: 260, backgroundColor: '#fbfcf9', style: { fontFamily: 'Inter, Arial, sans-serif' } },
        credits: { enabled: false },
        xAxis: { categories: this.grupos.map((g) => g.etiqueta), labels: { style: { fontSize: '10px' } } },
        tooltip: { shared: true },
        legend: { itemStyle: { fontSize: '11px' } },
      };
    },
    chartLluvia() {
      return {
        ...this.chartBase,
        exporting: this.exp('lluvia_eto'),
        title: { text: 'Lluvia y ETo (mm)', style: { fontSize: '13px', fontWeight: '700' } },
        yAxis: { min: 0, title: { text: 'mm' } },
        plotOptions: { column: { borderRadius: 3 } },
        series: [
          { type: 'column', name: 'Lluvia', color: '#73BC25', data: this.grupos.map((g) => this.r(g.lluvia, 1)), tooltip: { valueSuffix: ' mm' } },
          { type: 'line', name: 'ETo', color: '#FF6B00', data: this.grupos.map((g) => this.r(g.eto, 1)), marker: { radius: 2 }, tooltip: { valueSuffix: ' mm' } },
        ],
      };
    },
    chartTemp() {
      return {
        ...this.chartBase,
        exporting: this.exp('temperatura'),
        title: { text: 'Temperatura (°C)', style: { fontSize: '13px', fontWeight: '700' } },
        yAxis: { title: { text: '°C' } },
        tooltip: { shared: true, valueSuffix: ' °C', valueDecimals: 1 },
        plotOptions: { column: { borderRadius: 2, borderWidth: 0, pointPadding: 0.05, groupPadding: 0.05 } },
        series: [
          // ΔT en el mismo eje (°C), como columnas tenues detras de las lineas.
          { type: 'column', name: 'ΔT (Tmax − Tmin)', color: 'rgba(42, 120, 214, 0.28)', data: this.grupos.map((g) => this.r(g.at, 1)), zIndex: 0 },
          { type: 'line', name: 'Maxima', color: '#FF6B00', data: this.grupos.map((g) => this.r(g.tmax, 1)), marker: { radius: 2 }, zIndex: 2 },
          { type: 'line', name: 'Media', color: '#FFCC00', data: this.grupos.map((g) => this.r(g.tmed, 1)), marker: { radius: 2 }, zIndex: 2 },
          { type: 'line', name: 'Minima', color: '#73BC25', data: this.grupos.map((g) => this.r(g.tmin, 1)), marker: { radius: 2 }, zIndex: 2 },
        ],
      };
    },
    chartDpv() {
      return {
        ...this.chartBase,
        exporting: this.exp('deficit_presion_vapor'),
        title: { text: 'Deficit de presion de vapor (kPa)', style: { fontSize: '13px', fontWeight: '700' } },
        yAxis: { min: 0, title: { text: 'kPa' } },
        tooltip: { shared: true, valueSuffix: ' kPa', valueDecimals: 2 },
        series: [
          { type: 'line', name: 'DPV', color: '#2a78d6', lineWidth: 2, data: this.grupos.map((g) => this.r(g.vpd, 2)), marker: { radius: 2 } },
        ],
        legend: { enabled: false },
      };
    },
    chartGdd() {
      // Columnas por periodo; el acumulado se muestra en el tooltip (un solo eje).
      let acum = 0;
      const acumulado = this.grupos.map((g) => (acum += g.gdd || 0));
      return {
        ...this.chartBase,
        exporting: this.exp('grados_dia'),
        title: { text: `Grados dia (base ${this.tbase} °C)`, style: { fontSize: '13px', fontWeight: '700' } },
        yAxis: { min: 0, title: { text: '°C·dia' } },
        tooltip: {
          shared: true,
          formatter() {
            const p = this.points[0];
            return `<b>${this.x}</b><br>Grados dia: <b>${p.y === null ? '—' : p.y.toFixed(1)}</b> °C·d<br>Acumulado: <b>${acumulado[p.point.index].toFixed(0)}</b> °C·d`;
          },
        },
        plotOptions: { column: { borderRadius: 3, borderWidth: 0 } },
        series: [{ type: 'column', name: 'Grados dia', color: '#FFCC00', data: this.grupos.map((g) => this.r(g.gdd, 1)) }],
        legend: { enabled: false },
      };
    },
    // Humedad, viento y radiacion por separado: cada uno con su propia escala.
    chartHr() {
      return {
        ...this.chartBase,
        exporting: this.exp('humedad_relativa'),
        title: { text: 'Humedad relativa (%)', style: { fontSize: '13px', fontWeight: '700' } },
        yAxis: { title: { text: '%' }, max: 100 },
        tooltip: { shared: true, valueSuffix: ' %', valueDecimals: 0 },
        series: [
          { type: 'line', name: 'HR maxima', color: '#2a78d6', data: this.grupos.map((g) => this.r(g.hr_max, 0)), marker: { radius: 2 }, dashStyle: 'ShortDash' },
          { type: 'line', name: 'HR media', color: '#1baf7a', data: this.grupos.map((g) => this.r(g.hr, 0)), marker: { radius: 2 } },
          { type: 'line', name: 'HR minima', color: '#eb6834', data: this.grupos.map((g) => this.r(g.hr_min, 0)), marker: { radius: 2 }, dashStyle: 'ShortDash' },
        ].filter((s) => s.data.some((v) => v !== null)),
      };
    },
    chartViento() {
      return {
        ...this.chartBase,
        exporting: this.exp('velocidad_viento'),
        title: { text: 'Velocidad del viento (km/h)', style: { fontSize: '13px', fontWeight: '700' } },
        yAxis: { min: 0, title: { text: 'km/h' } },
        tooltip: { shared: true, valueSuffix: ' km/h', valueDecimals: 1 },
        legend: { enabled: false },
        series: [{ type: 'line', name: 'Viento promedio', color: '#5b6267', data: this.grupos.map((g) => this.r(g.viento, 1)), marker: { radius: 2 } }],
      };
    },
    chartRadiacion() {
      return {
        ...this.chartBase,
        exporting: this.exp('radiacion_solar'),
        title: { text: 'Radiacion solar (MJ/m²·dia)', style: { fontSize: '13px', fontWeight: '700' } },
        yAxis: { min: 0, title: { text: 'MJ/m²·dia' } },
        tooltip: { shared: true, valueSuffix: ' MJ/m²·d', valueDecimals: 1 },
        legend: { enabled: false },
        series: [{ type: 'line', name: 'Radiacion', color: '#eda100', lineWidth: 2, data: this.grupos.map((g) => this.r(g.radiacion, 1)), marker: { radius: 2 } }],
      };
    },
    chartBalance() {
      let acum = 0;
      const datos = this.grupos.map((g) => {
        if (g.balance === null) return null;
        acum += g.balance;
        return this.r(acum, 1);
      });
      return {
        ...this.chartBase,
        exporting: this.exp('balance_hidrico'),
        title: { text: 'Balance hidrico acumulado (lluvia − ETo, mm)', style: { fontSize: '13px', fontWeight: '700' } },
        yAxis: { title: { text: 'mm' }, plotLines: [{ value: 0, color: '#CED2D5', width: 2 }] },
        tooltip: { valueSuffix: ' mm', valueDecimals: 1 },
        legend: { enabled: false },
        series: [{
          type: 'area', name: 'Balance acumulado', data: datos, threshold: 0, lineWidth: 2,
          color: '#73BC25', fillOpacity: 0.25, negativeColor: '#FF6B00', marker: { radius: 2 },
        }],
      };
    },
  },
  watch: {
    estacionSel() { this.consultar(); },
    agrupar() { /* reagrupa en el cliente, sin nueva consulta */ },
  },
  mounted() {
    // El estado de la API define que estaciones tienen datos; luego se elige la mas cercana.
    this.cargarIcc().then(() => this.cargarEstaciones(true));
  },
  methods: {
    /* ---------------- API ICC ---------------- */
    usaApi(s) { return !!s && this.icc.configurada && !!s.codigo_api; },
    cargarIcc() {
      return window.axios.get('/investigacion/clima/icc').then(({ data }) => { this.icc = data; }).catch(() => {});
    },

    /* ---------------- estaciones ---------------- */
    cargarEstaciones(inicial) {
      this.cargandoEst = true;
      const params = this.tieneGps ? { lat: this.ensayo.lat, lng: this.ensayo.lng } : {};
      return window.axios.get('/investigacion/clima/estaciones', { params })
        .then(({ data }) => {
          this.estaciones = data.estaciones;
          this.redes = data.redes;
          // Estacion mas cercana al ensayo: activa, con ubicacion y con datos
          // (guardados o de la API del ICC). La lista ya viene ordenada por distancia.
          const activas = this.estaciones.filter((s) => s.activa);
          this.cercana = this.tieneGps
            ? (activas.find((s) => s.distancia_km !== null && (this.usaApi(s) || s.registros_count)) || activas.find((s) => s.distancia_km !== null) || null)
            : null;
          if (inicial) {
            const sug = this.cercana || activas.find((s) => s.registros_count) || null;
            if (sug && sug.id !== this.estacionSel) this.estacionSel = sug.id;
            else this.consultar();
          }
        })
        .catch(() => { if (inicial) this.consultar(); })
        .finally(() => { this.cargandoEst = false; });
    },

    /* ---------------- periodo ---------------- */
    aplicarPreset() {
      const hoy = new Date();
      const y = hoy.getFullYear();
      const zafraIni = hoy.getMonth() >= 10 ? y : y - 1;
      const menor = (a, b) => (a < b ? a : b);
      const rangos = {
        ult30: [iso(new Date(Date.now() - 29 * DIA)), this.hoy],
        ult90: [iso(new Date(Date.now() - 89 * DIA)), this.hoy],
        ult12m: [iso(new Date(y - 1, hoy.getMonth(), hoy.getDate() + 1)), this.hoy],
        anio: [`${y}-01-01`, this.hoy],
        anioPrev: [`${y - 1}-01-01`, `${y - 1}-12-31`],
        zafra: [`${zafraIni}-11-01`, menor(`${zafraIni + 1}-05-31`, this.hoy)],
        zafraPrev: [`${zafraIni - 1}-11-01`, `${zafraIni}-05-31`],
      };
      if (rangos[this.preset]) {
        [this.desde, this.hasta] = rangos[this.preset];
        this.agrupar = this.preset === 'ult30' ? 'dia' : this.preset === 'ult90' ? 'semana' : 'mes';
        this.consultar();
      }
    },

    /* ---------------- consulta ---------------- */
    consultar() {
      if (!this.rangoValido) return;
      const est = this.estacion;
      if (!est && !this.tieneGps) {
        this.dias = [];
        this.consultado = true;
        this.error = 'El ensayo no tiene coordenadas GPS: seleccione una estacion o agregue las coordenadas del ensayo.';
        return;
      }
      this.cargando = true;
      this.error = null;
      const token = (this.consultaId = (this.consultaId || 0) + 1);

      this.sync = null;
      // Estaciones ICC: el servidor completa desde la API los dias que falten del periodo.
      const pedirEstacion = est
        ? window.axios.get(`/investigacion/clima/estaciones/${est.id}/datos`, { params: { desde: this.desde, hasta: this.hasta, sincronizar: 1 } })
          .then((r) => {
            if (token === this.consultaId) this.sync = r.data.sync || null;
            if (r.data.sync && r.data.sync.consultada) { this.cargarEstaciones(false); this.cargarIcc(); }
            return r.data.dias;
          })
        : Promise.resolve([]);

      pedirEstacion
        .then((propios) => {
          const total = Math.round((parse(this.hasta) - parse(this.desde)) / DIA) + 1;
          const faltan = total - propios.length;
          // Estacion sin coordenadas (creada desde la API): Open-Meteo en el punto del ensayo.
          const estConGps = est && est.lat !== null && est.lng !== null;
          const lat = estConGps ? est.lat : this.ensayo.lat;
          const lng = estConGps ? est.lng : this.ensayo.lng;
          const hayPunto = lat !== null && lat !== '' && lng !== null && lng !== '';
          const usarModelo = hayPunto && (!est || (this.completar && faltan > 0));
          return (usarModelo ? this.openMeteo(lat, lng, this.desde, this.hasta) : Promise.resolve([])).then((modelo) => ({ propios, modelo, total }));
        })
        .then(({ propios, modelo, total }) => {
          if (token !== this.consultaId) return;
          this.dias = this.combinar(propios, modelo);
          this.fuente = this.describirFuente(est, propios, modelo, total);
          this.consultado = true;
        })
        .catch(() => {
          if (token !== this.consultaId) return;
          this.dias = [];
          this.error = 'No se pudieron obtener los datos climaticos. Verifique la conexion a internet e intente de nuevo.';
        })
        .finally(() => { if (token === this.consultaId) this.cargando = false; });
    },
    /**
     * Open-Meteo: reanalisis ERA5 (archive-api) hasta hace ~6 dias y API de
     * pronostico para los ultimos dias. Mismas variables diarias en ambas.
     */
    openMeteo(lat, lng, desde, hasta) {
      const vars = 'precipitation_sum,temperature_2m_max,temperature_2m_min,temperature_2m_mean,relative_humidity_2m_mean,wind_speed_10m_mean,shortwave_radiation_sum,et0_fao_evapotranspiration';
      const corte = iso(new Date(Date.now() - 6 * DIA));
      const base = `latitude=${Number(lat).toFixed(4)}&longitude=${Number(lng).toFixed(4)}&daily=${vars}&timezone=${encodeURIComponent(TZ)}`;
      const pedidos = [];
      if (desde <= corte) {
        pedidos.push(`https://archive-api.open-meteo.com/v1/archive?${base}&start_date=${desde}&end_date=${hasta < corte ? hasta : corte}`);
      }
      if (hasta > corte) {
        const ini = desde > corte ? desde : iso(new Date(parse(corte).getTime() + DIA));
        pedidos.push(`https://api.open-meteo.com/v1/forecast?${base}&start_date=${ini}&end_date=${hasta}`);
      }
      return Promise.all(pedidos.map((u) => fetch(u).then((r) => { if (!r.ok) throw new Error(r.status); return r.json(); })))
        .then((resps) => {
          const dias = [];
          resps.forEach((j) => {
            const d = j.daily;
            d.time.forEach((t, i) => dias.push({
              fecha: t, lluvia: d.precipitation_sum[i], tmax: d.temperature_2m_max[i], tmin: d.temperature_2m_min[i],
              tmed: d.temperature_2m_mean[i], hr: d.relative_humidity_2m_mean[i], viento: d.wind_speed_10m_mean[i],
              radiacion: d.shortwave_radiation_sum[i], eto: d.et0_fao_evapotranspiration[i],
            }));
          });
          return dias;
        });
    },
    combinar(propios, modelo) {
      const porFecha = {};
      modelo.forEach((m) => { porFecha[m.fecha] = { ...m, modelado: true }; });
      propios.forEach((p) => {
        const m = porFecha[p.fecha];
        const fila = { fecha: p.fecha, modelado: false, eto_estimada: !!p.eto_estimada };
        VARS.forEach((k) => {
          if (p[k] !== null && p[k] !== undefined) fila[k] = p[k];
          else if (m && this.completar) { fila[k] = m[k]; fila.modelado = true; } else fila[k] = null;
        });
        porFecha[p.fecha] = fila;
      });
      return Object.values(porFecha).sort((a, b) => a.fecha.localeCompare(b.fecha));
    },
    describirFuente(est, propios, modelo, total) {
      const rango = `${this.fmt(this.desde)} → ${this.fmt(this.hasta)}`;
      if (!est) {
        return {
          tipo: 'modelo', modelados: false, etoEstimada: false,
          titulo: 'Open-Meteo (reanalisis ERA5 y modelos) en el punto del ensayo',
          detalle: `${Number(this.ensayo.lat).toFixed(4)}, ${Number(this.ensayo.lng).toFixed(4)} · ${rango} · datos modelados, no medidos en estacion.`,
        };
      }
      const cobertura = Math.round((propios.length / total) * 100);
      const completados = this.dias.filter((d) => d.modelado).length;
      const s = this.sync || {};
      const api = s.usa_api
        ? (s.error ? ` · API ICC: ${s.error}` : s.consultada ? ` · API ICC: ${s.dias} dia(s) descargados ahora` : ' · API ICC al dia')
        : '';
      if (!propios.length) {
        return {
          tipo: 'modelo', modelados: false, etoEstimada: false, apiError: !!s.error,
          titulo: `${est.nombre} (${est.codigo}) · sin mediciones en el periodo`,
          detalle: `Se muestran datos de Open-Meteo en las coordenadas de la estacion · ${rango}${api}.${s.usa_api ? '' : ' Importe el archivo de REDMET para usar las mediciones reales.'}`,
        };
      }
      return {
        tipo: 'estacion',
        modelados: completados > 0,
        etoEstimada: propios.some((p) => p.eto_estimada),
        apiError: !!s.error,
        titulo: `${est.nombre} (${est.codigo}) · Red ${est.red}${s.usa_api ? ' · API' : ''}`,
        detalle: `${propios.length} de ${total} dias con mediciones (${cobertura} %)${completados ? ` · ${completados} dia(s) completados con Open-Meteo` : ''} · ${rango}${est.distancia_km !== null ? ` · a ${est.distancia_km} km del ensayo` : ''}${api}.`,
      };
    },
    claveGrupo(fecha) {
      if (this.agrupar === 'dia') return { clave: fecha, etiqueta: this.fmt(fecha) };
      if (this.agrupar === 'mes') {
        const [y, m] = fecha.split('-').map(Number);
        return { clave: fecha.slice(0, 7), etiqueta: new Date(y, m - 1, 1).toLocaleDateString('es-GT', { month: 'short', year: 'numeric' }) };
      }
      const d = parse(fecha);
      const lunes = new Date(d.getTime() - ((d.getDay() + 6) % 7) * DIA);
      const k = iso(lunes);
      return { clave: k, etiqueta: this.fmt(k) };
    },
    /** Menu de cada grafico: pantalla completa, imprimir y descargar (PNG, SVG, CSV, Excel). */
    exp(nombre) {
      const est = this.estacion;
      return {
        enabled: true,
        filename: `clima_${this.ensayo.codigo}_${nombre}_${this.desde}_${this.hasta}`,
        chartOptions: {
          chart: { backgroundColor: '#ffffff' },
          subtitle: { text: `${est ? est.nombre : 'Punto del ensayo (Open-Meteo)'} · ${this.fmt(this.desde)} a ${this.fmt(this.hasta)} · Ensayo ${this.ensayo.codigo}` },
        },
      };
    },
    rachaSeca() {
      let max = 0;
      let act = 0;
      this.dias.forEach((d) => {
        if (d.lluvia !== null && d.lluvia < 1) { act++; max = Math.max(max, act); } else act = 0;
      });
      return max;
    },

    /* ---------------- exportar ---------------- */
    exportarCsv() {
      const est = this.estacion;
      const enc = [this.etiquetaGrupo, 'Lluvia (mm)', 'T max (°C)', 'T min (°C)', 'T media (°C)', 'DeltaT Tmax-Tmin (°C)', 'HR (%)', 'Viento (km/h)', 'Radiacion (MJ/m2/d)', 'ETo (mm)', 'Balance (mm)', 'DPV (kPa)', `Grados dia base ${this.tbase} (°C·d)`, 'Fuente'];
      const num = (v, d) => (v === null || v === undefined ? '' : Number(v).toFixed(d).replace('.', ','));
      const lineas = [
        `Estacion;${est ? `${est.nombre} (${est.codigo}) - ${est.red}` : 'Punto del ensayo (Open-Meteo)'}`,
        `Ensayo;${this.ensayo.codigo}`,
        `Periodo;${this.desde} a ${this.hasta}`,
        '',
        enc.join(';'),
        ...this.grupos.map((g) => [
          g.etiqueta, num(g.lluvia, 1), num(g.tmax, 1), num(g.tmin, 1), num(g.tmed, 1), num(g.at, 1), num(g.hr, 0),
          num(g.viento, 1), num(g.radiacion, 2), num(g.eto, 2), num(g.balance, 1), num(g.vpd, 2), num(g.gdd, 1),
          !est ? 'Open-Meteo' : g.modelados ? 'Estacion + Open-Meteo' : 'Estacion',
        ].join(';')),
      ];
      const blob = new Blob(['﻿' + lineas.join('\r\n')], { type: 'text/csv;charset=utf-8' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = `clima_${est ? est.codigo : 'punto'}_${this.ensayo.codigo}_${this.desde}_${this.hasta}_${this.agrupar}.csv`;
      document.body.appendChild(a);
      a.click();
      setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    },

    /* ---------------- utilidades ---------------- */
    fmt(v) {
      const m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
      return m ? `${m[3]}/${m[2]}/${m[1]}` : '—';
    },
    n(v, d) { return v === null || v === undefined ? '—' : Number(v).toFixed(d); },
    r(v, d) { return v === null || v === undefined ? null : Number(Number(v).toFixed(d)); },
    u(v, d, unidad) { return v === null || v === undefined ? '—' : `${Number(v).toFixed(d)} ${unidad}`; },
    toast(title, text) {
      this.$swal.fire({ toast: true, position: 'top-end', icon: 'success', title, text, timer: 2200, showConfirmButton: false });
    },
    falla(err) {
      const r = err && err.response;
      let msg = r && r.data && r.data.message;
      if (r && r.status === 422 && r.data.errors) msg = Object.values(r.data.errors).flat().join(' ');
      this.$swal.fire({ icon: 'error', title: 'No se pudo completar', text: msg || 'Ocurrio un error. Intente de nuevo.' });
    },
  },
};
</script>

<style scoped>
.cl-controls {
  display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px;
  padding: 12px; border: 1px solid #dcebc8; border-radius: 10px; background: #f7fbf1;
}
.cl-field { display: flex; flex-direction: column; flex: 0 1 auto; min-width: 0; }
.cl-field label { margin-bottom: 4px; color: var(--cengi-ink); font-size: 11px; font-weight: 700; }
.cl-field-est { flex: 1 1 280px; }
.cl-field-fecha { flex: 0 1 150px; }
.cl-seg { display: inline-flex; padding: 3px; border: 1px solid var(--cengi-border); border-radius: 8px; background: #fff; }
.cl-seg button { padding: 5px 10px; border: 0; border-radius: 6px; background: transparent; color: var(--cengi-muted); font-size: 12px; font-weight: 600; cursor: pointer; }
.cl-seg button.is-active { background: #73BC25; color: #fff; }
.cl-actions { display: flex; gap: 6px; margin-left: auto; }
.cl-actions .btn.active { border-color: #73BC25; background: #eef8df; }
.cl-check { display: inline-flex; align-items: center; gap: 6px; margin: 8px 0 0; color: var(--cengi-muted); font-size: 12px; font-weight: 500; cursor: pointer; }
.cl-error { margin: 8px 0 0; color: #b84d00; font-size: 12px; font-weight: 600; }

.cl-cercana { margin: 8px 0 0; color: #3f7a12; font-size: 12.5px; }
.cl-cercana i { margin-right: 4px; }
.cl-lejos { color: #b84d00; }

.cl-table-wrap { overflow-x: auto; border: 1px solid #edf1ec; border-radius: 10px; }
.cl-table-scroll { max-height: 360px; overflow-y: auto; }
.cl-table { min-width: 640px; margin: 0; font-size: 12px; }
.cl-table td { vertical-align: middle; }
.cl-table tr.is-off td { color: #9aa3a8; }
.cl-table small { color: var(--cengi-muted); }
.cl-nowrap { white-space: nowrap; }
.cl-neg { color: #b84d00; font-weight: 700; }
.cl-pos { color: #3f7a12; font-weight: 700; }

.cl-loading { padding: 40px; color: var(--cengi-muted); text-align: center; }
.cl-empty { display: flex; flex-direction: column; align-items: center; gap: 6px; margin-top: 14px; padding: 30px 16px; border: 1px dashed #CED2D5; border-radius: 10px; color: var(--cengi-muted); font-size: 12.5px; text-align: center; }
.cl-empty > i { color: #CED2D5; font-size: 24px; }

.cl-fuente { display: flex; align-items: center; gap: 12px; margin: 14px 0; padding: 10px 14px; border-left: 4px solid #73BC25; border-radius: 8px; background: #f5faef; }
.cl-fuente > i { color: #73BC25; font-size: 18px; }
.cl-fuente.is-modelo { border-left-color: #FFCC00; background: #fffbea; }
.cl-fuente.is-modelo > i { color: #c79f00; }
.cl-fuente strong { display: block; color: var(--cengi-primary-deep); font-size: 13px; }
.cl-fuente small { color: var(--cengi-muted); font-size: 11.5px; }

.cl-kpis { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 10px; margin-bottom: 14px; }
.cl-kpi { display: flex; align-items: flex-start; gap: 10px; padding: 12px; border: 1px solid #edf1ec; border-top: 3px solid var(--c); border-radius: 10px; background: #fff; }
.cl-kpi > i { margin-top: 3px; color: var(--c); font-size: 16px; }
.cl-kpi strong { display: block; color: var(--cengi-primary-deep); font-family: 'Space Grotesk', sans-serif; font-size: 18px; line-height: 1.2; }
.cl-kpi span { display: block; color: var(--cengi-ink); font-size: 11.5px; font-weight: 600; }
.cl-kpi small { display: block; color: var(--cengi-muted); font-size: 10.5px; }

.cl-alertas { margin-bottom: 14px; padding: 10px 14px; border: 1px solid #ffd9bf; border-radius: 10px; background: #fff8f2; font-size: 12.5px; }
.cl-alertas div { display: flex; gap: 8px; padding: 4px 0; }
.cl-alertas i { margin-top: 2px; color: #FF6B00; }

.cl-charts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
.cl-chart { position: relative; min-width: 0; padding: 8px; border: 1px solid #edf1ec; border-radius: 10px; background: #fbfcf9; }
.cl-tbase { position: absolute; top: 10px; left: 12px; z-index: 2; color: #6b7568; font-size: 11.5px; font-weight: 600; }
.cl-tbase input { width: 54px; padding: 1px 4px; border: 1px solid #d8ddd5; border-radius: 6px; }
.cl-datos summary { margin-bottom: 8px; color: var(--cengi-primary-deep); font-size: 12.5px; font-weight: 700; cursor: pointer; }
.cl-datos summary i { margin-right: 5px; color: #73BC25; }
.cl-note { margin-top: 10px; color: var(--cengi-muted); font-size: 11.5px; }
.cl-note i { margin-right: 5px; }

@media (max-width: 900px) {
  .cl-charts { grid-template-columns: 1fr; }
  .cl-actions { margin-left: 0; }
}
</style>
