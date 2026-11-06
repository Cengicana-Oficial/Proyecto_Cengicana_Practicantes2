<template>
  <div class="ae">
    <!-- ============ Seleccion ============ -->
    <div v-if="fuentes.length" class="ae-cab">
      <div class="ae-campo ae-var">
        <label>Variable</label>
        <select aria-label="Variable del análisis estadístico" class="form-control ae-variable-select" v-model="clave" :disabled="!fuentes.length" :title="fuente ? fuente.nombre : 'Sin variables numéricas con datos'">
          <option v-if="!fuentes.length" :value="null">{{ cargandoLab ? 'Cargando variables…' : 'Sin variables numéricas con datos' }}</option>
          <optgroup v-if="fuentesCampo.length" label="Campo (evaluaciones)">
            <option v-for="f in fuentesCampo" :key="f.key" :value="f.key">{{ f.nombre }}{{ f.unidad ? ' (' + f.unidad + ')' : '' }}</option>
          </optgroup>
          <optgroup v-if="fuentesLab.length" label="Laboratorio (resultados)">
            <option v-for="f in fuentesLab" :key="f.key" :value="f.key">{{ f.nombre }}{{ f.unidad ? ' (' + f.unidad + ')' : '' }} · {{ f.tipo }}</option>
          </optgroup>
        </select>
        <span v-if="fuente" class="ae-variable-nombre">{{ fuente.nombre }}{{ fuente.unidad ? ' (' + fuente.unidad + ')' : '' }} · {{ fuente.fuente === 'lab' ? 'Laboratorio' : 'Campo' }}</span>
      </div>
      <div class="ae-campo">
        <label>Eje del tiempo</label>
        <div class="ae-seg">
          <button v-for="e in EJES" :key="e.id" type="button" :class="{ 'is-on': eje === e.id }" :disabled="e.id === 'edad' && !siembra"
            :title="e.id === 'edad' && !siembra ? 'Registre la fecha de siembra del ensayo para usar la edad del cultivo' : e.ayuda" @click="eje = e.id">{{ e.label }}</button>
        </div>
      </div>
      <div class="ae-campo">
        <label>Agrupar fechas en un muestreo</label>
        <select class="form-control form-control-sm" v-model.number="ventana" title="Fechas de una misma ronda de muestreo">
          <option :value="0">Cada fecha por separado</option>
          <option :value="3">Hasta 3 dias</option>
          <option :value="7">Hasta 7 dias</option>
          <option :value="15">Hasta 15 dias</option>
        </select>
      </div>
      <div class="ae-campo">
        <label>Significancia</label>
        <select class="form-control form-control-sm" v-model.number="alfa">
          <option :value="0.05">5 % (α = 0.05)</option>
          <option :value="0.01">1 % (α = 0.01)</option>
        </select>
      </div>
      <button type="button" class="btn btn-sm ae-btn-sec" :disabled="!fuente" title="Datos por parcela y resumen (se abre en Excel)" @click="exportar"><i class="fas fa-file-excel"></i> Exportar</button>
    </div>

    <p v-if="cargandoLab" class="ae-nota"><i class="fas fa-spinner fa-spin"></i> Buscando resultados de laboratorio...</p>
    <div v-if="!fuente && !cargandoLab" class="ae-vacio">
      <i class="fas fa-chart-bar"></i>
      <strong>{{ ensayo.codigo }} aún no tiene variables numéricas disponibles para analizar.</strong>
      <span>Para mostrar el análisis, registra evaluaciones numéricas en sus parcelas o incorpora resultados de laboratorio asociados a sus tratamientos.</span>
    </div>

    <template v-if="fuente">
      <!-- ============ Ficha del analisis ============ -->
      <div class="ae-ficha">
        <span class="ae-tipo" :class="serie ? 'is-serie' : 'is-unico'">
          <i :class="serie ? 'fas fa-stream' : 'fas fa-dot-circle'"></i> {{ serie ? `Serie en el tiempo · ${muestreos.length} muestreos` : 'Un solo muestreo' }}
        </span>
        <span><i class="fas fa-th"></i> {{ disenoTexto }}</span>
        <span><i class="fas fa-layer-group"></i> {{ tratamientos.length }} tratamientos · {{ repeticiones }} repeticiones</span>
        <span v-if="siembra"><i class="fas fa-seedling"></i> Siembra {{ fmt(siembra) }}</span>
        <span v-else class="ae-aviso"><i class="fas fa-exclamation-circle"></i> Sin fecha de siembra: no se calcula la edad del cultivo</span>
      </div>

      <!-- ============ Grafico ============ -->
      <!-- :key recrea la grafica al cambiar de eje o de variable: highcharts-vue combina las opciones
           nuevas con las anteriores y el eje "Muestreo" heredaba el tipo y las etiquetas del eje de fechas. -->
      <div class="ae-chart"><highcharts :key="`${clave}|${eje}|${serie ? 's' : 'u'}`" :options="grafico"></highcharts></div>

      <!-- ============ Serie: tratamiento x muestreo ============ -->
      <template v-if="serie">
        <h6 class="ae-sub"><i class="fas fa-table"></i> Media ± error estandar por tratamiento y muestreo
          <small>· letras distintas en una columna = diferencia significativa (Tukey, α = {{ alfa }})</small></h6>
        <div class="ae-tabla-scroll">
          <table class="table ae-tabla">
            <thead>
              <tr>
                <th>Tratamiento</th>
                <th v-for="m in analisis" :key="m.n" class="text-right" :class="{ 'is-sel': detalle === m.n }" @click="detalle = m.n">
                  M{{ m.n }}<small>{{ etiquetaTiempo(m) }}</small>
                </th>
                <th v-if="auc" class="text-right ae-auc" :class="{ 'is-sel': detalle === 'auc' }" @click="detalle = 'auc'" title="Area bajo la curva: resume toda la serie en un valor por parcela">
                  ABC<small>{{ auc.unidad }}</small>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="t in tratamientos" :key="t">
                <td><span class="ae-trat" :style="{ '--c': colorTrat(t) }">{{ t }}</span></td>
                <td v-for="m in analisis" :key="m.n" class="text-right">
                  <template v-if="celda(m.res, t)">
                    <b>{{ dec(celda(m.res, t).media) }}</b><span class="ae-ee"> ± {{ dec(celda(m.res, t).ee) }}</span>
                    <sup class="ae-letra">{{ letra(m.res, t) }}</sup>
                    <small v-if="celda(m.res, t).n < repeticiones" class="ae-n" :title="`Solo ${celda(m.res, t).n} de ${repeticiones} repeticiones`">n={{ celda(m.res, t).n }}</small>
                  </template>
                  <span v-else class="ae-muted">—</span>
                </td>
                <td v-if="auc" class="text-right ae-auc">
                  <template v-if="celda(auc.res, t)"><b>{{ dec(celda(auc.res, t).media, 0) }}</b><sup class="ae-letra">{{ letra(auc.res, t) }}</sup></template>
                  <span v-else class="ae-muted">—</span>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr>
                <td>Tratamientos (p)</td>
                <td v-for="m in analisis" :key="m.n" class="text-right" :class="claseP(m.res.p)">{{ sig(m.res.p) }}</td>
                <td v-if="auc" class="text-right" :class="claseP(auc.res.p)">{{ sig(auc.res.p) }}</td>
              </tr>
              <tr>
                <td>C.V.</td>
                <td v-for="m in analisis" :key="m.n" class="text-right" :class="{ 'ae-txt-nar': m.res.cv > 20 }">{{ m.res.cv !== null ? m.res.cv.toFixed(1) + ' %' : '—' }}</td>
                <td v-if="auc" class="text-right">{{ auc.res.cv !== null ? auc.res.cv.toFixed(1) + ' %' : '—' }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
        <p class="ae-nota">
          Haga clic en un muestreo (o en ABC) para ver su ANOVA completo.
          <template v-if="auc"> <b>ABC</b> = area bajo la curva de cada parcela ({{ auc.unidad }}): compara los tratamientos en todo el periodo con un solo valor<template v-if="auc.excluidas">; se excluyeron {{ auc.excluidas }} parcela(s) sin datos en todos los muestreos</template>.</template>
        </p>
      </template>

      <!-- ============ ANOVA del muestreo elegido (o del unico) ============ -->
      <div v-if="anovaSel" class="ae-anova">
        <h6 class="ae-sub"><i class="fas fa-calculator"></i> {{ tituloAnova }}</h6>
        <p v-if="anovaSel.aviso" class="ae-aviso-box"><i class="fas fa-info-circle"></i> {{ anovaSel.aviso }}</p>
        <div v-if="anovaSel.filas.length" class="ae-anova-grid">
          <table class="table ae-tabla">
            <thead><tr><th>Fuente de variacion</th><th class="text-right">GL</th><th class="text-right">SC</th><th class="text-right">CM</th><th class="text-right">F</th><th class="text-right">p</th></tr></thead>
            <tbody>
              <tr v-for="f in anovaSel.filas" :key="f.fuente" :class="{ 'is-total': f.fuente === 'Total' }">
                <td>{{ f.fuente }}</td>
                <td class="text-right">{{ f.gl }}</td>
                <td class="text-right">{{ num4(f.sc) }}</td>
                <td class="text-right">{{ f.cm !== undefined ? num4(f.cm) : '' }}</td>
                <td class="text-right">{{ f.F !== undefined && f.F !== null ? f.F.toFixed(2) : '' }}</td>
                <td class="text-right" :class="claseP(f.p)">{{ f.p !== undefined && f.p !== null ? sig(f.p) : '' }}</td>
              </tr>
            </tbody>
          </table>
          <div class="ae-tukey">
            <div class="ae-kpi" :class="anovaSel.p !== null && anovaSel.p < alfa ? 'is-si' : 'is-no'">
              <b>{{ anovaSel.p !== null && anovaSel.p < alfa ? 'Hay diferencias entre tratamientos' : 'Sin diferencias significativas' }}</b>
              <span>p = {{ sig(anovaSel.p) }} · {{ anovaSel.diseno === 'DBCA' ? 'bloques completos al azar' : 'completamente al azar' }} · C.V. {{ anovaSel.cv !== null ? anovaSel.cv.toFixed(1) + ' %' : '—' }}</span>
            </div>
            <table v-if="anovaSel.tukey" class="table ae-tabla">
              <thead><tr><th>Tratamiento</th><th class="text-right">Media</th><th class="text-right">n</th><th>Grupo</th></tr></thead>
              <tbody>
                <tr v-for="r in ordenadas(anovaSel)" :key="r.trat">
                  <td><span class="ae-trat" :style="{ '--c': colorTrat(r.trat) }">{{ r.trat }}</span></td>
                  <td class="text-right"><b>{{ dec(r.media, detalle === 'auc' ? 0 : null) }}</b></td>
                  <td class="text-right">{{ r.n }}</td>
                  <td><span class="ae-grupo">{{ anovaSel.tukey.letras[r.trat] }}</span></td>
                </tr>
              </tbody>
            </table>
            <p v-if="anovaSel.tukey" class="ae-nota">
              Tukey α = {{ alfa }}: q = {{ anovaSel.tukey.q.toFixed(3) }}<template v-if="anovaSel.tukey.dms !== null"> · diferencia minima significativa = {{ dec(anovaSel.tukey.dms, detalle === 'auc' ? 0 : null) }}</template>.
              Tratamientos con la misma letra no difieren.
            </p>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script>
import { anova, agruparMuestreos, areaBajoCurva, media, significancia } from '../../../estadistica';
import { EXPORTAR } from '../clima/clima-util';

const DIA = 86400000;
// Misma paleta y orden de color que Linea de tiempo y Comparar tratamientos.
const PALETA = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
const EJES = [
  { id: 'edad', label: 'Edad del cultivo', ayuda: 'dias despues de la siembra' },
  { id: 'fecha', label: 'Fecha', ayuda: 'fecha del muestreo' },
  { id: 'muestreo', label: 'Muestreo', ayuda: 'muestreo 1, 2, 3...' },
];

/**
 * Analisis estadistico de una variable del ensayo (campo o laboratorio): agrupa las fechas
 * en muestreos y, en cada uno, ANOVA (DBCA con la repeticion como bloque, o DCA) y Tukey.
 * Con varios muestreos, ademas, el area bajo la curva por parcela resume toda la serie.
 */
export default {
  name: 'AnalisisEstadistico',
  props: {
    ensayo: { type: Object, required: true },
    registros: { type: Array, default: () => [] },
    variables: { type: Array, default: () => [] },
    parcelas: { type: Array, default: () => [] },
    tratamientosEnsayo: { type: Array, default: () => [] },
  },
  data() {
    return { EJES, clave: null, eje: 'fecha', ventana: 7, alfa: 0.05, detalle: null, lab: [], cargandoLab: false };
  },
  computed: {
    siembra() { return this.ensayo.fecha_siembra ? String(this.ensayo.fecha_siembra).slice(0, 10) : null; },
    // Catalogo de disenos (Sistema > Catalogos > "Analiza con bloques"); si no esta, por el nombre.
    bloques() {
      if (typeof this.ensayo.usa_bloques === 'boolean') return this.ensayo.usa_bloques;
      return /bloque/i.test(this.ensayo.diseno || '') || !this.ensayo.diseno;
    },
    disenoTexto() { return this.ensayo.diseno ? `Diseno: ${this.ensayo.diseno}` : 'Diseno no registrado (se usan las repeticiones como bloques)'; },
    varMap() { return new Map(this.variables.map((v) => [v.id, v])); },
    parcMap() { return new Map(this.parcelas.map((p) => [p.id, p])); },
    parcCodigo() { return new Map(this.parcelas.map((p) => [p.codigo, p])); },
    fuentesCampo() {
      const m = new Map();
      this.registros.forEach((e) => {
        const v = this.varMap.get(e.variable_id);
        const p = this.parcMap.get(e.parcela_id);
        const y = this.num(e.valor);
        if (!v || v.tipo !== 'Numerica' || !p || y === null) return;
        const k = `v:${v.id}`;
        if (!m.has(k)) m.set(k, { key: k, fuente: 'campo', nombre: v.nombre, unidad: v.unidad || '', decimales: v.decimales, puntos: [] });
        m.get(k).puntos.push({ fecha: String(e.fecha).slice(0, 10), parcela: p.codigo, tratamiento: p.tratamiento, repeticion: p.repeticion, valor: y });
      });
      return [...m.values()].sort((a, b) => a.nombre.localeCompare(b.nombre, 'es'));
    },
    fuentesLab() {
      return this.lab.map((a) => ({
        key: `a:${a.tipo}:${a.clave}`, fuente: 'lab', nombre: a.label, unidad: a.unidad || '', tipo: a.tipo, decimales: null,
        puntos: a.puntos.filter((p) => p.tratamiento).map((p) => ({
          ...p, repeticion: p.repeticion ?? (this.parcCodigo.get(p.parcela) || {}).repeticion ?? null,
        })),
      })).filter((f) => f.puntos.length);
    },
    fuentes() { return [...this.fuentesCampo, ...this.fuentesLab]; },
    fuente() { return this.fuentes.find((f) => f.key === this.clave) || null; },
    muestreos() { return this.fuente ? agruparMuestreos(this.fuente.puntos.map((p) => p.fecha), this.ventana) : []; },
    serie() { return this.muestreos.length > 1; },
    tratamientos() {
      const s = new Set(this.fuente ? this.fuente.puntos.map((p) => p.tratamiento || 'Sin trat.') : []);
      return [...s].sort((a, b) => this.ordenTrat(a) - this.ordenTrat(b) || a.localeCompare(b, 'es', { numeric: true }));
    },
    repeticiones() {
      return new Set(this.fuente ? this.fuente.puntos.map((p) => p.repeticion).filter((r) => r !== null && r !== undefined) : []).size || '—';
    },
    /** Por muestreo: un valor por parcela (promedio si se midio mas de una vez) y su ANOVA. */
    analisis() {
      if (!this.fuente) return [];
      return this.muestreos.map((m) => {
        const fechas = new Set(m.fechas);
        const porParcela = new Map();
        this.fuente.puntos.filter((p) => fechas.has(p.fecha)).forEach((p) => {
          const k = p.parcela || `${p.tratamiento}|${p.repeticion}`;
          if (!porParcela.has(k)) porParcela.set(k, { trat: p.tratamiento || 'Sin trat.', bloque: p.repeticion, parcela: p.parcela, xs: [] });
          porParcela.get(k).xs.push(p.valor);
        });
        const obs = [...porParcela.values()].map((o) => ({ trat: o.trat, bloque: o.bloque, parcela: o.parcela, valor: media(o.xs) }));
        return { ...m, edad: this.edad(m.centro), obs, res: anova(obs, { bloques: this.bloques, alfa: this.alfa }) };
      });
    },
    /** Area bajo la curva por parcela (solo parcelas con dato en todos los muestreos) y su ANOVA. */
    auc() {
      if (!this.serie) return null;
      const n = this.analisis.length;
      const x0 = Date.parse(this.analisis[0].centro);
      const xDe = (m) => (m.edad !== null ? m.edad : (Date.parse(m.centro) - x0) / DIA);
      const parcelas = new Map();
      this.analisis.forEach((m) => m.obs.forEach((o) => {
        const k = o.parcela || `${o.trat}|${o.bloque}`;
        if (!parcelas.has(k)) parcelas.set(k, { trat: o.trat, bloque: o.bloque, parcela: o.parcela, pts: [] });
        parcelas.get(k).pts.push({ x: xDe(m), y: o.valor });
      }));
      const completas = [...parcelas.values()].filter((p) => p.pts.length === n);
      if (completas.length < 2) return null;
      const obs = completas.map((p) => ({ trat: p.trat, bloque: p.bloque, parcela: p.parcela, valor: areaBajoCurva(p.pts) }));
      return {
        res: anova(obs, { bloques: this.bloques, alfa: this.alfa }),
        excluidas: parcelas.size - completas.length,
        unidad: `${this.fuente.unidad ? this.fuente.unidad + ' · ' : ''}dias`,
      };
    },
    anovaSel() {
      if (!this.analisis.length) return null;
      if (this.detalle === 'auc' && this.auc) return this.auc.res;
      const m = this.analisis.find((x) => x.n === this.detalle) || this.analisis[this.analisis.length - 1];
      return m.res;
    },
    tituloAnova() {
      if (this.detalle === 'auc' && this.auc) return `ANOVA del area bajo la curva (${this.auc.unidad})`;
      const m = this.analisis.find((x) => x.n === this.detalle) || this.analisis[this.analisis.length - 1];
      return `ANOVA · ${this.fuente.nombre} · ${this.serie ? `muestreo ${m.n} · ` : ''}${this.etiquetaTiempo(m, true)}`;
    },
    grafico() {
      const f = this.fuente;
      const tit = { fontSize: '13px', fontWeight: '700', color: '#1f5632' };
      const base = {
        credits: { enabled: false },
        exporting: { ...EXPORTAR, filename: `${this.ensayo.codigo}_${f.nombre}` },
        chart: { height: 360, style: { fontFamily: 'inherit' } },
        legend: { itemStyle: { fontWeight: '600', fontSize: '11px' } },
        yAxis: { title: { text: f.unidad || f.nombre }, gridLineColor: '#edf1ec' },
      };
      if (!this.serie) {
        // Un solo muestreo: barras por tratamiento, media ± EE y letras de Tukey.
        const m = this.analisis[0];
        const filas = this.tratamientos.map((t) => this.celda(m.res, t)).filter(Boolean);
        return {
          ...base,
          chart: { ...base.chart, type: 'column' },
          title: { text: `${f.nombre} por tratamiento · ${this.etiquetaTiempo(m, true)}`, style: tit },
          subtitle: { text: `Media ± error estandar · letras: Tukey α = ${this.alfa} · p = ${this.sig(m.res.p)}` },
          xAxis: { categories: filas.map((r) => r.trat), lineColor: '#CED2D5' },
          legend: { enabled: false },
          tooltip: { shared: true, valueDecimals: this.decimales(), valueSuffix: f.unidad ? ` ${f.unidad}` : '' },
          plotOptions: { column: { borderRadius: 3, borderWidth: 0, colorByPoint: true } },
          colors: filas.map((r) => this.colorTrat(r.trat)),
          series: [
            {
              name: f.nombre,
              data: filas.map((r) => ({ y: this.redondear(r.media), letra: this.letra(m.res, r.trat) })),
              dataLabels: { enabled: true, format: '{point.letra}', y: -14, style: { fontSize: '13px', fontWeight: '700', textOutline: 'none', color: '#1f5632' } },
            },
            { name: 'Error estandar', type: 'errorbar', data: filas.map((r) => (r.ee !== null ? [this.redondear(r.media - r.ee), this.redondear(r.media + r.ee)] : null)), color: '#33402a', whiskerLength: '30%' },
          ],
        };
      }
      // Serie: una linea por tratamiento con su error estandar en cada muestreo.
      const x = (m, i) => (this.eje === 'edad' && m.edad !== null ? m.edad : this.eje === 'fecha' ? Date.parse(m.centro) : i);
      // Marcas solo en los muestreos (no una por dia): como maximo 10, repartidas, siempre el primero y el ultimo.
      const xs = this.analisis.map((m, i) => x(m, i));
      const marcas = xs.length <= 10 ? xs : [...new Set(Array.from({ length: 10 }, (_, k) => xs[Math.round((k * (xs.length - 1)) / 9)]))];
      const ejeBase = {
        lineColor: '#CED2D5', tickPositions: marcas, startOnTick: false, endOnTick: false, minPadding: 0.04, maxPadding: 0.04,
        labels: { autoRotation: false, style: { fontSize: '11px', textAlign: 'center' }, useHTML: false },
      };
      const DIA_MS = 86400000;
      const xAxis = this.eje === 'edad'
        ? { ...ejeBase, title: { text: 'Edad del cultivo (dias despues de la siembra)' }, min: Math.min(...xs) - 5, max: Math.max(...xs) + 5, labels: { ...ejeBase.labels, formatter() { return `${this.value} d<br><span style="color:#8a8f87">${(this.value / 30.44).toFixed(1)} meses</span>`; } } }
        : this.eje === 'fecha'
          ? {
            ...ejeBase,
            type: 'datetime',
            title: { text: 'Fecha del muestreo' },
            // Margen de unos dias a cada lado para que el primer y el ultimo punto no queden en el borde.
            min: Math.min(...xs) - 5 * DIA_MS,
            max: Math.max(...xs) + 5 * DIA_MS,
            labels: { ...ejeBase.labels, formatter() { const d = new Date(this.value); return `${String(d.getUTCDate()).padStart(2, '0')}/${String(d.getUTCMonth() + 1).padStart(2, '0')}/${String(d.getUTCFullYear()).slice(2)}`; } },
          }
          : { categories: this.analisis.map((m) => `M${m.n}`), title: { text: 'Muestreo' }, lineColor: '#CED2D5' };
      const series = [];
      this.tratamientos.forEach((t) => {
        const color = this.colorTrat(t);
        const pts = this.analisis.map((m, i) => ({ m, i, c: this.celda(m.res, t) })).filter((p) => p.c);
        series.push({ name: t, id: `t-${t}`, color, type: 'line', lineWidth: 2, marker: { radius: 4, symbol: 'circle' }, data: pts.map((p) => [x(p.m, p.i), this.redondear(p.c.media)]) });
        series.push({ name: `${t} ± EE`, linkedTo: `t-${t}`, type: 'errorbar', color, data: pts.filter((p) => p.c.ee !== null).map((p) => [x(p.m, p.i), this.redondear(p.c.media - p.c.ee), this.redondear(p.c.media + p.c.ee)]), enableMouseTracking: false });
      });
      return {
        ...base,
        title: { text: `${f.nombre} en el tiempo por tratamiento`, style: tit },
        subtitle: { text: 'Media de las repeticiones ± error estandar' },
        xAxis,
        tooltip: { shared: true, valueDecimals: this.decimales(), valueSuffix: f.unidad ? ` ${f.unidad}` : '' },
        series,
      };
    },
  },
  watch: {
    'ensayo.id': { immediate: true, handler() { this.cargarLab(); this.elegirInicial(); } },
    fuentes() { if (!this.fuente) this.elegirInicial(); },
    clave() { this.detalle = null; },
    siembra: { immediate: true, handler(s) { this.eje = s ? 'edad' : 'fecha'; } },
  },
  methods: {
    num(s) {
      if (s === null || s === undefined) return null;
      const t = String(s).trim().replace(',', '.');
      return t !== '' && !Number.isNaN(Number(t)) ? Number(t) : null;
    },
    elegirInicial() {
      // Primero la variable con mas muestreos: es la que mas informa en el tiempo.
      const conFechas = this.fuentes.map((f) => ({ f, n: new Set(f.puntos.map((p) => p.fecha)).size })).sort((a, b) => b.n - a.n);
      this.clave = conFechas.length ? conFechas[0].f.key : null;
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
    edad(fecha) { return this.siembra ? Math.round((Date.parse(fecha) - Date.parse(this.siembra)) / DIA) : null; },
    etiquetaTiempo(m, largo = false) {
      const f = m.desde === m.hasta ? this.fmt(m.desde) : `${this.fmt(m.desde)}–${this.fmt(m.hasta)}`;
      if (m.edad === null) return f;
      return largo ? `${f} · ${m.edad} dias (${(m.edad / 30.44).toFixed(1)} meses)` : `${m.edad} d · ${this.fmt(m.centro).slice(0, 5)}`;
    },
    ordenTrat(t) { const i = this.tratamientosEnsayo.indexOf(t); return i < 0 ? 999 : i; },
    colorTrat(t) {
      let i = this.tratamientosEnsayo.indexOf(t);
      if (i < 0) i = this.tratamientos.indexOf(t);
      return PALETA[(i < 0 ? 0 : i) % PALETA.length];
    },
    celda(res, t) { return res ? res.resumen.find((r) => r.trat === t && r.n > 0) || null : null; },
    letra(res, t) { return res && res.tukey && res.p !== null && res.p < this.alfa ? res.tukey.letras[t] || '' : ''; },
    ordenadas(res) { return res.resumen.filter((r) => r.n > 0).slice().sort((a, b) => b.media - a.media); },
    sig(p) { const s = significancia(p); return s.marca && s.marca !== 'ns' ? `${s.texto} ${s.marca}` : s.marca === 'ns' ? `${s.texto} ns` : s.texto; },
    claseP(p) { return p === null || p === undefined ? '' : p < this.alfa ? 'ae-txt-si' : 'ae-muted'; },
    decimales() {
      if (this.fuente && this.fuente.decimales !== null && this.fuente.decimales !== undefined) return this.fuente.decimales;
      const xs = this.fuente ? this.fuente.puntos.map((p) => Math.abs(p.valor)) : [];
      const m = xs.length ? Math.max(...xs) : 0;
      return m >= 100 ? 1 : m >= 10 ? 2 : 3;
    },
    redondear(x) { return x === null || x === undefined ? null : Number(x.toFixed(this.decimales() + 1)); },
    dec(x, d = null) {
      if (x === null || x === undefined) return '—';
      const n = d === null ? this.decimales() : d;
      return Number(x).toLocaleString('es-GT', { minimumFractionDigits: n, maximumFractionDigits: n });
    },
    num4(x) { return x === null || x === undefined ? '' : Number(x).toLocaleString('es-GT', { maximumFractionDigits: 4 }); },
    fmt(s) { if (!s) return ''; const [y, m, d] = String(s).slice(0, 10).split('-'); return `${d}/${m}/${y}`; },
    /** CSV (Excel en espanol): datos por parcela y muestreo + resumen por tratamiento. */
    exportar() {
      const f = this.fuente;
      const celda = (x) => { const s = x === null || x === undefined ? '' : String(x); return /[;"\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s; };
      const n = (x) => (x === null || x === undefined ? '' : String(this.redondear(x)).replace('.', ','));
      const filas = [[`Ensayo ${this.ensayo.codigo}`, `Variable ${f.nombre}${f.unidad ? ' (' + f.unidad + ')' : ''}`, f.fuente === 'lab' ? 'Laboratorio' : 'Campo'], []];
      filas.push(['DATOS POR PARCELA']);
      filas.push(['Muestreo', 'Desde', 'Hasta', 'Dias despues de siembra', 'Parcela', 'Tratamiento', 'Repeticion', 'Valor']);
      this.analisis.forEach((m) => m.obs.slice().sort((a, b) => String(a.trat).localeCompare(String(b.trat), 'es', { numeric: true }) || (a.bloque || 0) - (b.bloque || 0))
        .forEach((o) => filas.push([m.n, this.fmt(m.desde), this.fmt(m.hasta), m.edad ?? '', o.parcela, o.trat, o.bloque, n(o.valor)])));
      filas.push([]);
      filas.push(['RESUMEN (media, error estandar, grupo Tukey)']);
      filas.push(['Muestreo', 'Tratamiento', 'n', 'Media', 'EE', 'Grupo', 'p tratamientos', 'CV %']);
      const resumen = (etq, res, d) => res.resumen.filter((r) => r.n > 0).forEach((r) => filas.push([etq, r.trat, r.n, d(r.media), d(r.ee), this.letra(res, r.trat), res.p !== null ? res.p.toFixed(4).replace('.', ',') : '', res.cv !== null ? res.cv.toFixed(1).replace('.', ',') : '']));
      this.analisis.forEach((m) => resumen(`M${m.n} ${this.etiquetaTiempo(m, true)}`, m.res, n));
      if (this.auc) resumen(`Area bajo la curva (${this.auc.unidad})`, this.auc.res, (x) => (x === null ? '' : x.toFixed(1).replace('.', ',')));
      const csv = '﻿' + filas.map((r) => r.map(celda).join(';')).join('\r\n');
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
      a.download = `analisis_${this.ensayo.codigo}_${f.nombre.replace(/[^\w-]+/g, '_')}.csv`;
      document.body.appendChild(a);
      a.click();
      setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 0);
    },
  },
};
</script>

<style scoped>
.ae { --verde: #73BC25; --verde-osc: #1f5632; --borde: #e3e7e1; --suave: #6b7568; --tinta: #1e2a1a; }
.ae-cab { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px 14px; margin-bottom: 10px; }
.ae-campo { display: flex; flex-direction: column; gap: 4px; }
.ae-campo label { margin: 0; color: var(--verde-osc); font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
.ae-var { flex: 1 0 100%; width: 100%; min-width: 0; max-width: none; }
.ae-variable-select { display: block; width: 100%; min-width: 0; height: 44px; min-height: 44px; padding: 8px 36px 8px 12px; font-size: 14px; line-height: 1.5; color: #1e2a1a; background-color: #fff; border: 1px solid #a8b89e; border-radius: 8px; }
.ae-variable-select option, .ae-variable-select optgroup { color: #1e2a1a; background: #fff; }
.ae-variable-nombre { color: var(--verde-osc); font-size: 13px; line-height: 1.5; overflow-wrap: anywhere; }
.ae-seg { display: inline-flex; padding: 3px; border: 1px solid var(--borde); border-radius: 8px; background: #f3f5f1; }
.ae-seg button { padding: 4px 11px; border: 0; border-radius: 6px; background: transparent; color: #5b6658; font-size: 12px; font-weight: 600; }
.ae-seg button.is-on { background: var(--verde); color: #fff; }
.ae-seg button:disabled { opacity: .45; cursor: not-allowed; }
.ae-btn-sec { margin-left: auto; border: 1px solid var(--borde); border-radius: 9px; background: #fff; color: var(--verde-osc); font-weight: 600; }
.ae-btn-sec i { color: #1d6f42; }
.ae-ficha { display: flex; flex-wrap: wrap; gap: 6px 14px; align-items: center; margin-bottom: 10px; color: var(--suave); font-size: 12px; }
.ae-ficha i { margin-right: 3px; color: var(--verde); }
.ae-tipo { padding: 3px 10px; border-radius: 999px; font-weight: 700; }
.ae-tipo.is-serie { background: #eef6e2; color: #3e6b14; }
.ae-tipo.is-unico { background: #fff7d6; color: #8a6a00; }
.ae-tipo i { color: inherit; }
.ae-aviso, .ae-aviso i { color: #b84d00; }
.ae-chart { margin-bottom: 12px; padding: 6px; border: 1px solid var(--borde); border-radius: 12px; background: #fff; }
.ae-sub { margin: 14px 0 8px; color: var(--verde-osc); font-size: 13.5px; font-weight: 800; }
.ae-sub small { color: var(--suave); font-weight: 500; }
.ae-sub i { margin-right: 4px; color: var(--verde); }
.ae-tabla-scroll { overflow-x: auto; border: 1px solid var(--borde); border-radius: 10px; }
.ae-tabla { margin: 0; font-size: 12.5px; font-variant-numeric: tabular-nums; }
.ae-tabla th { background: #f7f9f5; color: var(--suave); font-size: 11px; white-space: nowrap; }
.ae-tabla th small { display: block; font-weight: 500; }
.ae-tabla-scroll th[class*='text-right'] { cursor: pointer; }
.ae-tabla th.is-sel { background: #eef6e2; color: var(--verde-osc); box-shadow: inset 0 -2px 0 var(--verde); }
.ae-tabla td { vertical-align: middle; white-space: nowrap; }
.ae-tabla tfoot td { background: #fafbf8; color: var(--suave); font-size: 11.5px; }
.ae-tabla tr.is-total td { color: var(--suave); }
.ae-ee { color: var(--suave); font-size: 11.5px; }
.ae-letra { margin-left: 2px; color: #b84d00; font-size: 11px; font-weight: 800; }
.ae-n { margin-left: 4px; padding: 0 4px; border-radius: 4px; background: #fff1e6; color: #b84d00; font-size: 10px; }
.ae-auc { background: #fbfdf7; }
.ae-trat { display: inline-block; padding: 1px 8px; border-radius: 6px; background: color-mix(in srgb, var(--c) 15%, #fff); color: var(--c); font-weight: 800; filter: saturate(1.1); }
.ae-grupo { display: inline-block; min-width: 22px; padding: 0 6px; border-radius: 5px; background: #fff7d6; color: #8a5a00; font-weight: 800; text-align: center; }
.ae-muted { color: var(--suave); }
.ae-txt-si { color: #1f5632; font-weight: 700; }
.ae-txt-nar { color: #b84d00; }
.ae-nota { margin: 6px 0 0; color: var(--suave); font-size: 11.5px; }
.ae-anova { margin-top: 6px; }
.ae-anova-grid { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); gap: 12px; align-items: start; }
.ae-anova-grid > table, .ae-tukey > table { border: 1px solid var(--borde); }
.ae-tukey { display: flex; flex-direction: column; gap: 8px; }
.ae-kpi { display: flex; flex-direction: column; padding: 8px 12px; border-radius: 10px; font-size: 12px; }
.ae-kpi b { font-size: 13px; }
.ae-kpi.is-si { border: 1px solid #A3D300; background: #f4fae8; color: #3e6b14; }
.ae-kpi.is-no { border: 1px solid #CED2D5; background: #f7f8f8; color: #5b6267; }
.ae-aviso-box { padding: 7px 10px; border-radius: 8px; background: #fff7e6; color: #7a4a00; font-size: 12px; }
.ae-vacio { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 40px 16px; border: 1px dashed #CED2D5; border-radius: 12px; color: var(--suave); text-align: center; }
.ae-vacio > i { color: #CED2D5; font-size: 28px; }
@media (max-width: 991px) { .ae-anova-grid { grid-template-columns: 1fr; } .ae-btn-sec { margin-left: 0; } }
</style>
