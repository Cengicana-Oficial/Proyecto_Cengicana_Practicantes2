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
              {{ s.nombre }} ({{ s.codigo }}){{ s.distancia_km !== null ? ' · ' + s.distancia_km + ' km' : '' }}{{ s.registros_count ? ' · ' + s.registros_count + ' dias' : ' · sin datos importados' }}{{ s.activa ? '' : ' · inactiva' }}
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
        <button v-if="puedeGestionar || puedeImportar" type="button" class="btn btn-secondary" :class="{ active: panel }" @click="panel = !panel" title="Estaciones y carga de datos">
          <i class="fas fa-broadcast-tower"></i>
        </button>
      </div>
    </div>
    <label class="cl-check" v-if="estacion">
      <input type="checkbox" v-model="completar"> Completar dias sin registro de la estacion con Open-Meteo (en sus coordenadas)
    </label>
    <p class="cl-error" v-if="!rangoValido"><i class="fas fa-exclamation-circle"></i> {{ errorRango }}</p>

    <!-- ============ Gestion de estaciones ============ -->
    <div class="cl-panel" v-if="panel">
      <div class="cl-panel-head">
        <h6><i class="fas fa-broadcast-tower"></i> Estaciones meteorologicas</h6>
        <button v-if="puedeGestionar" type="button" class="btn btn-success btn-sm" @click="nuevaEstacion"><i class="fas fa-plus"></i> Nueva estacion</button>
      </div>

      <form v-if="formEst" class="cl-form" @submit.prevent="guardarEstacion" novalidate>
        <div class="form-row">
          <div class="col-md-2 form-group"><label>Codigo *</label><input class="form-control form-control-sm" maxlength="30" v-model="formEst.codigo" :class="{ 'is-invalid': errEst.codigo }"><span class="invalid-feedback" v-if="errEst.codigo">{{ errEst.codigo[0] }}</span></div>
          <div class="col-md-4 form-group"><label>Nombre *</label><input class="form-control form-control-sm" maxlength="150" v-model="formEst.nombre" :class="{ 'is-invalid': errEst.nombre }"><span class="invalid-feedback" v-if="errEst.nombre">{{ errEst.nombre[0] }}</span></div>
          <div class="col-md-3 form-group"><label>Red</label><select class="form-control form-control-sm" v-model="formEst.red"><option v-for="r in redes" :key="r" :value="r">{{ r }}</option></select></div>
          <div class="col-md-3 form-group"><label>Ubicacion</label><input class="form-control form-control-sm" maxlength="150" v-model="formEst.ubicacion" placeholder="Municipio, finca..."></div>
        </div>
        <div class="form-row">
          <div class="col-md-3 form-group"><label>Latitud *</label><input type="number" step="0.000001" class="form-control form-control-sm" v-model="formEst.lat" :class="{ 'is-invalid': errEst.lat }"><span class="invalid-feedback" v-if="errEst.lat">{{ errEst.lat[0] }}</span></div>
          <div class="col-md-3 form-group"><label>Longitud *</label><input type="number" step="0.000001" class="form-control form-control-sm" v-model="formEst.lng" :class="{ 'is-invalid': errEst.lng }"><span class="invalid-feedback" v-if="errEst.lng">{{ errEst.lng[0] }}</span></div>
          <div class="col-md-2 form-group"><label>Altitud (msnm)</label><input type="number" class="form-control form-control-sm" v-model="formEst.altitud"></div>
          <div class="col-md-2 form-group d-flex align-items-end"><label class="cl-check mb-2"><input type="checkbox" v-model="formEst.activa"> Activa</label></div>
          <div class="col-md-2 form-group d-flex align-items-end" style="gap:6px">
            <button type="button" class="btn btn-secondary btn-sm" @click="formEst = null">Cancelar</button>
            <button type="submit" class="btn btn-success btn-sm" :disabled="guardandoEst"><i class="fas fa-save"></i> Guardar</button>
          </div>
        </div>
      </form>

      <div class="cl-table-wrap" v-if="estaciones.length">
        <table class="table cl-table">
          <thead><tr><th>Codigo</th><th>Nombre</th><th>Red</th><th>Distancia</th><th>Datos importados</th><th></th></tr></thead>
          <tbody>
            <tr v-for="s in estaciones" :key="s.id" :class="{ 'is-off': !s.activa }">
              <td><strong>{{ s.codigo }}</strong></td>
              <td>{{ s.nombre }}<small v-if="s.ubicacion"> · {{ s.ubicacion }}</small></td>
              <td>{{ s.red }}</td>
              <td>{{ s.distancia_km !== null ? s.distancia_km + ' km' : '—' }}</td>
              <td>
                <span v-if="s.registros_count">{{ s.registros_count }} dias · {{ fmt(s.registros_min_fecha) }} → {{ fmt(s.registros_max_fecha) }}</span>
                <span v-else class="cl-muted">Sin datos</span>
              </td>
              <td class="text-right cl-nowrap">
                <button v-if="puedeImportar" type="button" class="cl-icon" title="Importar datos" @click="importSel = s.id"><i class="fas fa-file-upload"></i></button>
                <button v-if="puedeGestionar" type="button" class="cl-icon" title="Editar" @click="editarEstacion(s)"><i class="fas fa-pen"></i></button>
                <button v-if="puedeGestionar" type="button" class="cl-icon cl-icon-danger" title="Eliminar" @click="eliminarEstacion(s)"><i class="fas fa-trash"></i></button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else class="cl-muted mb-2">Aun no hay estaciones registradas. Agregue las estaciones de la red ICC (REDMET) cercanas a sus ensayos.</p>

      <div class="cl-import" v-if="puedeImportar && estaciones.length">
        <h6><i class="fas fa-file-upload"></i> Importar datos de estacion</h6>
        <div class="form-row align-items-end">
          <div class="col-md-4 form-group mb-2">
            <label>Estacion</label>
            <select class="form-control form-control-sm" v-model="importSel">
              <option :value="null">Seleccione...</option>
              <option v-for="s in estaciones" :key="s.id" :value="s.id">{{ s.nombre }} ({{ s.codigo }})</option>
            </select>
          </div>
          <div class="col-md-5 form-group mb-2">
            <label>Archivo exportado de REDMET (CSV / Excel, max. 20 MB)</label>
            <input type="file" ref="archivoClima" accept=".csv,.txt,.xlsx,.xls" class="form-control-file" @change="archivoClima = $event.target.files[0] || null">
          </div>
          <div class="col-md-3 form-group mb-2">
            <button type="button" class="btn btn-success btn-sm btn-block" @click="importar" :disabled="!importSel || !archivoClima || importando">
              <i :class="importando ? 'fas fa-spinner fa-spin' : 'fas fa-upload'"></i> {{ importando ? 'Importando...' : 'Importar' }}
            </button>
          </div>
        </div>
        <p class="cl-help">
          La primera fila debe tener encabezados. Se reconocen: <b>Fecha</b> (o Fecha/Hora; datos diarios o cada 15 min),
          <b>Lluvia/Precipitacion</b> (mm), <b>Temp. max/min/media</b> o <b>Temperatura</b> (°C), <b>HR/Humedad relativa</b> (%),
          <b>Velocidad del viento</b> (km/h o m/s), <b>Radiacion</b> (W/m² o MJ/m²) y <b>ETo</b> (mm).
          Los datos subdiarios se agregan por dia; reimportar un periodo reemplaza sus valores.
        </p>
        <div v-if="resultadoImport" class="cl-result">
          <i class="fas fa-check-circle"></i>
          {{ resultadoImport.dias }} dias importados ({{ fmt(resultadoImport.desde) }} → {{ fmt(resultadoImport.hasta) }}) de {{ resultadoImport.filas_leidas }} filas.
          Columnas: {{ Object.keys(resultadoImport.columnas).filter(k => k !== 'fecha').map(k => nombres[k]).join(', ') }}.
          <span v-if="resultadoImport.omitidas.length"> {{ resultadoImport.omitidas.length }} fila(s) omitidas (p. ej. fila {{ resultadoImport.omitidas[0].fila }}: {{ resultadoImport.omitidas[0].motivo }}).</span>
        </div>
      </div>
    </div>

    <!-- ============ Resultados ============ -->
    <div v-if="cargando" class="cl-loading"><i class="fas fa-spinner fa-spin"></i> Consultando datos climaticos...</div>
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
        <div class="cl-chart"><highcharts :options="chartAmbiente"></highcharts></div>
        <div class="cl-chart"><highcharts :options="chartBalance"></highcharts></div>
      </div>

      <details class="cl-datos">
        <summary><i class="fas fa-table"></i> Ver tabla de datos ({{ grupos.length }} {{ agrupar === 'dia' ? 'dias' : agrupar === 'semana' ? 'semanas' : 'meses' }})</summary>
        <div class="cl-table-wrap cl-table-scroll">
          <table class="table cl-table">
            <thead>
              <tr><th>{{ etiquetaGrupo }}</th><th>Lluvia mm</th><th>T max °C</th><th>T min °C</th><th>T media °C</th><th>HR %</th><th>Viento km/h</th><th>Rad. MJ/m²·d</th><th>ETo mm</th><th>Balance mm</th></tr>
            </thead>
            <tbody>
              <tr v-for="g in grupos" :key="g.clave">
                <td class="cl-nowrap">{{ g.etiqueta }}<sup v-if="g.modelados" title="Incluye dias completados con Open-Meteo">*</sup></td>
                <td>{{ n(g.lluvia, 1) }}</td><td>{{ n(g.tmax, 1) }}</td><td>{{ n(g.tmin, 1) }}</td><td>{{ n(g.tmed, 1) }}</td>
                <td>{{ n(g.hr, 0) }}</td><td>{{ n(g.viento, 1) }}</td><td>{{ n(g.radiacion, 1) }}</td><td>{{ n(g.eto, 1) }}</td>
                <td :class="g.balance < 0 ? 'cl-neg' : 'cl-pos'">{{ n(g.balance, 1) }}</td>
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
    puedeGestionar: { type: Boolean, default: false },
    puedeImportar: { type: Boolean, default: false },
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
      cargando: false,
      consultado: false,
      error: null,
      dias: [],
      fuente: {},
      panel: false,
      formEst: null,
      errEst: {},
      guardandoEst: false,
      importSel: null,
      archivoClima: null,
      importando: false,
      resultadoImport: null,
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
    grupos() {
      const mapa = {};
      this.dias.forEach((d) => {
        const { clave, etiqueta } = this.claveGrupo(d.fecha);
        if (!mapa[clave]) mapa[clave] = { clave, etiqueta, dias: [] };
        mapa[clave].dias.push(d);
      });
      return Object.values(mapa).sort((a, b) => a.clave.localeCompare(b.clave)).map((g) => {
        const vals = (k) => g.dias.map((d) => d[k]).filter((v) => v !== null && v !== undefined);
        const r = { clave: g.clave, etiqueta: g.etiqueta, modelados: g.dias.some((d) => d.modelado) };
        r.lluvia = vals('lluvia').length ? sumar(vals('lluvia')) : null;
        r.eto = vals('eto').length ? sumar(vals('eto')) : null;
        ['tmax', 'tmin', 'tmed', 'hr', 'viento', 'radiacion'].forEach((k) => { r[k] = prom(vals(k)); });
        r.balance = r.lluvia !== null && r.eto !== null ? r.lluvia - r.eto : null;
        return r;
      });
    },
    etiquetaGrupo() { return { dia: 'Fecha', semana: 'Semana (desde)', mes: 'Mes' }[this.agrupar]; },
    resumen() {
      const v = (k) => this.dias.map((d) => d[k]).filter((x) => x !== null && x !== undefined);
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
        chart: { height: 260, backgroundColor: 'transparent', style: { fontFamily: 'Inter, Arial, sans-serif' } },
        credits: { enabled: false },
        xAxis: { categories: this.grupos.map((g) => g.etiqueta), labels: { style: { fontSize: '10px' } } },
        tooltip: { shared: true },
        legend: { itemStyle: { fontSize: '11px' } },
      };
    },
    chartLluvia() {
      return {
        ...this.chartBase,
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
        title: { text: 'Temperatura (°C)', style: { fontSize: '13px', fontWeight: '700' } },
        yAxis: { title: { text: '°C' } },
        tooltip: { shared: true, valueSuffix: ' °C', valueDecimals: 1 },
        series: [
          { type: 'line', name: 'Maxima', color: '#FF6B00', data: this.grupos.map((g) => this.r(g.tmax, 1)), marker: { radius: 2 } },
          { type: 'line', name: 'Media', color: '#FFCC00', data: this.grupos.map((g) => this.r(g.tmed, 1)), marker: { radius: 2 } },
          { type: 'line', name: 'Minima', color: '#73BC25', data: this.grupos.map((g) => this.r(g.tmin, 1)), marker: { radius: 2 } },
        ],
      };
    },
    chartAmbiente() {
      return {
        ...this.chartBase,
        title: { text: 'Humedad, viento y radiacion', style: { fontSize: '13px', fontWeight: '700' } },
        yAxis: [
          { title: { text: 'HR %' }, min: 0, max: 100 },
          { title: { text: 'km/h · MJ/m²' }, min: 0, opposite: true },
        ],
        series: [
          { type: 'line', name: 'HR (%)', color: '#73BC25', yAxis: 0, data: this.grupos.map((g) => this.r(g.hr, 0)), marker: { radius: 2 } },
          { type: 'line', name: 'Viento (km/h)', color: '#5b6267', yAxis: 1, data: this.grupos.map((g) => this.r(g.viento, 1)), marker: { radius: 2 } },
          { type: 'line', name: 'Radiacion (MJ/m²·d)', color: '#FFCC00', yAxis: 1, data: this.grupos.map((g) => this.r(g.radiacion, 1)), marker: { radius: 2 } },
        ],
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
    this.cargarEstaciones(true);
  },
  methods: {
    /* ---------------- estaciones ---------------- */
    cargarEstaciones(inicial) {
      this.cargandoEst = true;
      const params = this.tieneGps ? { lat: this.ensayo.lat, lng: this.ensayo.lng } : {};
      return window.axios.get('/investigacion/clima/estaciones', { params })
        .then(({ data }) => {
          this.estaciones = data.estaciones;
          this.redes = data.redes;
          if (inicial) {
            // Sugerencia: la estacion activa mas cercana con datos; si no, la mas cercana; si no, el punto del ensayo.
            const activas = this.estaciones.filter((s) => s.activa);
            const sug = activas.find((s) => s.registros_count) || (this.tieneGps ? activas[0] : null);
            if (sug && sug.id !== this.estacionSel) this.estacionSel = sug.id;
            else this.consultar();
          }
        })
        .catch(() => { if (inicial) this.consultar(); })
        .finally(() => { this.cargandoEst = false; });
    },
    nuevaEstacion() {
      this.errEst = {};
      this.formEst = {
        id: null, codigo: '', nombre: '', red: this.redes[0] || 'ICC REDMET', ubicacion: '',
        lat: this.tieneGps ? this.ensayo.lat : '', lng: this.tieneGps ? this.ensayo.lng : '', altitud: '', activa: true,
      };
    },
    editarEstacion(s) {
      this.errEst = {};
      this.formEst = { ...s, ubicacion: s.ubicacion || '', altitud: s.altitud === null ? '' : s.altitud };
    },
    guardarEstacion() {
      const f = this.formEst;
      const payload = {
        codigo: String(f.codigo || '').trim(), nombre: String(f.nombre || '').trim(), red: f.red,
        ubicacion: f.ubicacion || null, lat: f.lat, lng: f.lng, altitud: f.altitud === '' ? null : f.altitud, activa: !!f.activa,
      };
      this.guardandoEst = true;
      this.errEst = {};
      (f.id ? window.axios.put(`/investigacion/clima/estaciones/${f.id}`, payload) : window.axios.post('/investigacion/clima/estaciones', payload))
        .then(({ data }) => {
          this.toast(f.id ? 'Estacion actualizada' : 'Estacion creada', data.nombre);
          this.formEst = null;
          return this.cargarEstaciones(false);
        })
        .catch((err) => {
          if (err.response && err.response.status === 422) this.errEst = err.response.data.errors || {};
          else this.falla(err);
        })
        .finally(() => { this.guardandoEst = false; });
    },
    eliminarEstacion(s) {
      this.$swal.fire({
        title: 'Eliminar estacion', icon: 'warning',
        text: `Se eliminara ${s.nombre} (${s.codigo})${s.registros_count ? ` y sus ${s.registros_count} dias de datos importados` : ''}.`,
        showCancelButton: true, confirmButtonText: 'Si, eliminar', cancelButtonText: 'Cancelar',
        confirmButtonColor: '#FF6B00', cancelButtonColor: '#CED2D5', focusCancel: true,
      }).then((r) => {
        if (!r.isConfirmed) return;
        window.axios.delete(`/investigacion/clima/estaciones/${s.id}`)
          .then(() => {
            this.toast('Estacion eliminada', s.nombre);
            if (this.estacionSel === s.id) this.estacionSel = 'punto';
            return this.cargarEstaciones(false);
          })
          .catch(this.falla);
      });
    },
    importar() {
      const fd = new FormData();
      fd.append('archivo', this.archivoClima);
      this.importando = true;
      this.resultadoImport = null;
      window.axios.post(`/investigacion/clima/estaciones/${this.importSel}/importar`, fd)
        .then(({ data }) => {
          this.resultadoImport = data;
          this.archivoClima = null;
          if (this.$refs.archivoClima) this.$refs.archivoClima.value = '';
          this.toast('Datos importados', `${data.dias} dias`);
          return this.cargarEstaciones(false).then(() => {
            if (this.estacionSel === this.importSel) this.consultar();
            else this.estacionSel = this.importSel;
          });
        })
        .catch(this.falla)
        .finally(() => { this.importando = false; });
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

      const pedirEstacion = est
        ? window.axios.get(`/investigacion/clima/estaciones/${est.id}/datos`, { params: { desde: this.desde, hasta: this.hasta } }).then((r) => r.data.dias)
        : Promise.resolve([]);

      pedirEstacion
        .then((propios) => {
          const total = Math.round((parse(this.hasta) - parse(this.desde)) / DIA) + 1;
          const faltan = total - propios.length;
          const usarModelo = !est || (this.completar && faltan > 0);
          const lat = est ? est.lat : this.ensayo.lat;
          const lng = est ? est.lng : this.ensayo.lng;
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
      if (!propios.length) {
        return {
          tipo: 'modelo', modelados: false, etoEstimada: false,
          titulo: `${est.nombre} (${est.codigo}) · sin datos importados en el periodo`,
          detalle: `Se muestran datos de Open-Meteo en las coordenadas de la estacion · ${rango}. Importe el archivo de REDMET para usar las mediciones reales.`,
        };
      }
      return {
        tipo: 'estacion',
        modelados: completados > 0,
        etoEstimada: propios.some((p) => p.eto_estimada),
        titulo: `${est.nombre} (${est.codigo}) · Red ${est.red}`,
        detalle: `${propios.length} de ${total} dias con mediciones (${cobertura} %)${completados ? ` · ${completados} dia(s) completados con Open-Meteo` : ''} · ${rango}${est.distancia_km !== null ? ` · a ${est.distancia_km} km del ensayo` : ''}.`,
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
      const enc = [this.etiquetaGrupo, 'Lluvia (mm)', 'T max (°C)', 'T min (°C)', 'T media (°C)', 'HR (%)', 'Viento (km/h)', 'Radiacion (MJ/m2/d)', 'ETo (mm)', 'Balance (mm)', 'Fuente'];
      const num = (v, d) => (v === null || v === undefined ? '' : Number(v).toFixed(d).replace('.', ','));
      const lineas = [
        `Estacion;${est ? `${est.nombre} (${est.codigo}) - ${est.red}` : 'Punto del ensayo (Open-Meteo)'}`,
        `Ensayo;${this.ensayo.codigo}`,
        `Periodo;${this.desde} a ${this.hasta}`,
        '',
        enc.join(';'),
        ...this.grupos.map((g) => [
          g.etiqueta, num(g.lluvia, 1), num(g.tmax, 1), num(g.tmin, 1), num(g.tmed, 1), num(g.hr, 0),
          num(g.viento, 1), num(g.radiacion, 2), num(g.eto, 2), num(g.balance, 1),
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

.cl-panel { margin-top: 12px; padding: 14px; border: 1px solid var(--cengi-border); border-radius: 10px; background: #fff; }
.cl-panel-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
.cl-panel h6 { margin: 0; color: var(--cengi-primary-deep); font-size: 13.5px; font-weight: 700; }
.cl-panel h6 i { margin-right: 6px; color: #73BC25; }
.cl-form { margin-bottom: 12px; padding: 12px; border-radius: 8px; background: #fafbf8; }
.cl-form label { font-size: 11px; }
.cl-import { margin-top: 14px; padding-top: 12px; border-top: 1px solid #edf1ec; }
.cl-import h6 { margin-bottom: 10px; }
.cl-help { margin: 4px 0 0; color: var(--cengi-muted); font-size: 11.5px; line-height: 1.5; }
.cl-result { margin-top: 10px; padding: 9px 12px; border-radius: 8px; background: #eef8df; color: #3f7a12; font-size: 12px; }
.cl-result i { margin-right: 5px; }

.cl-table-wrap { overflow-x: auto; border: 1px solid #edf1ec; border-radius: 10px; }
.cl-table-scroll { max-height: 360px; overflow-y: auto; }
.cl-table { min-width: 640px; margin: 0; font-size: 12px; }
.cl-table td { vertical-align: middle; }
.cl-table tr.is-off td { color: #9aa3a8; }
.cl-table small { color: var(--cengi-muted); }
.cl-nowrap { white-space: nowrap; }
.cl-muted { color: var(--cengi-muted); font-size: 12px; }
.cl-neg { color: #b84d00; font-weight: 700; }
.cl-pos { color: #3f7a12; font-weight: 700; }
.cl-icon { display: inline-grid; place-items: center; width: 28px; height: 28px; border: 1px solid transparent; border-radius: 7px; background: transparent; color: var(--cengi-muted); font-size: 12px; cursor: pointer; }
.cl-icon:hover { border-color: var(--cengi-border); background: var(--cengi-accent-soft); color: var(--cengi-primary-deep); }
.cl-icon-danger:hover { border-color: #ffd2b3; background: #fff1e6; color: #FF6B00; }

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
.cl-chart { min-width: 0; padding: 8px; border: 1px solid #edf1ec; border-radius: 10px; background: #fbfcf9; }
.cl-datos summary { margin-bottom: 8px; color: var(--cengi-primary-deep); font-size: 12.5px; font-weight: 700; cursor: pointer; }
.cl-datos summary i { margin-right: 5px; color: #73BC25; }
.cl-note { margin-top: 10px; color: var(--cengi-muted); font-size: 11.5px; }
.cl-note i { margin-right: 5px; }

@media (max-width: 900px) {
  .cl-charts { grid-template-columns: 1fr; }
  .cl-actions { margin-left: 0; }
}
</style>
