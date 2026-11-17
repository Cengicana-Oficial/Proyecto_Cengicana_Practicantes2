<template>
  <div class="it">
    <div class="it-toolbar no-print">
      <a :href="reportesUrl" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Reportes</a>
      <div class="it-toolbar-der">
        <span class="it-resumen-filtros">{{ registrosPeriodo.length }} registros · {{ tratsInforme.length }} de {{ tratamientos.length }} tratamientos · {{ variablesInforme.length }} variables</span>
        <button type="button" class="btn btn-outline-secondary btn-sm" @click="restablecer"><i class="fas fa-undo"></i> Restablecer</button>
        <button type="button" class="btn btn-success" @click="imprimir"><i class="fas fa-print"></i> Imprimir / Guardar como PDF</button>
      </div>
    </div>

    <div class="it-layout">
      <!-- Panel de filtros: todo se recalcula en el navegador, sin recargar la pagina. -->
      <aside class="it-panel no-print" aria-label="Filtros del informe">
        <section class="it-grupo">
          <h3><i class="far fa-calendar-alt"></i> Periodo</h3>
          <select v-model="preset" class="form-control form-control-sm" @change="aplicarPreset">
            <option value="todo">Historial completo</option>
            <option v-if="ensayo.fecha_siembra" value="siembra">Desde la siembra / corte</option>
            <option value="30">Últimos 30 días</option>
            <option value="90">Últimos 90 días</option>
            <option value="180">Últimos 6 meses</option>
            <option value="anio">Año en curso</option>
            <option value="personalizado">Personalizado</option>
          </select>
          <div class="it-fechas">
            <label>Desde<input v-model="desde" type="date" class="form-control form-control-sm" :max="hasta || null" @input="preset = 'personalizado'"></label>
            <label>Hasta<input v-model="hasta" type="date" class="form-control form-control-sm" :min="desde || null" @input="preset = 'personalizado'"></label>
          </div>
          <p class="it-ayuda">Datos disponibles: {{ rangoDatos }}</p>
        </section>

        <section class="it-grupo">
          <h3><i class="fas fa-th"></i> Tratamientos <small>{{ tratSel.length }}/{{ tratamientos.length }}</small></h3>
          <div class="it-chips">
            <label v-for="t in tratamientos" :key="t.codigo" class="it-chip" :class="{ 'is-on': tratSel.includes(t.codigo) }" :style="{ '--c': colorTrat(t.codigo) }" :title="t.descripcion || ''">
              <input v-model="tratSel" type="checkbox" :value="t.codigo"> {{ t.codigo }}
            </label>
          </div>
          <div class="it-links"><button type="button" @click="tratSel = tratamientos.map(t => t.codigo)">Todos</button><button type="button" @click="tratSel = []">Ninguno</button></div>
          <p v-if="tratSel.length && tratSel.length < 2" class="it-ayuda is-aviso">Elige al menos 2 tratamientos para comparar.</p>
        </section>

        <section class="it-grupo">
          <h3><i class="fas fa-chart-line"></i> Variables <small>{{ seleccionEfectiva.length }}/{{ variablesConDatos.length }}</small></h3>
          <div class="it-segmento" role="group" aria-label="Origen de la variable">
            <button v-for="o in ORIGENES" :key="o.v" type="button" :class="{ 'is-on': origen === o.v }" @click="origen = o.v">{{ o.t }}</button>
          </div>
          <input v-model="buscarVar" type="search" class="form-control form-control-sm" placeholder="Buscar variable" aria-label="Buscar variable">
          <div class="it-links"><button type="button" @click="marcarVisibles(true)">Marcar visibles</button><button type="button" @click="marcarVisibles(false)">Desmarcar visibles</button></div>
          <div class="it-vars">
            <div v-for="g in variablesAgrupadas" :key="g.categoria" class="it-vars-grupo">
              <b>{{ g.categoria }}</b>
              <label v-for="v in g.variables" :key="v.id" :class="{ 'is-vacio': !v.n }">
                <input v-model="seleccion" type="checkbox" :value="v.id">
                <span>{{ v.nombre }}<small v-if="v.unidad"> ({{ v.unidad }})</small></span>
                <em>{{ v.n }}</em>
              </label>
            </div>
            <p v-if="!variablesAgrupadas.length" class="it-ayuda">Ninguna variable coincide.</p>
          </div>
        </section>

        <section class="it-grupo">
          <h3><i class="fas fa-calculator"></i> Análisis</h3>
          <label class="it-campo">Agrupar fechas de un muestreo
            <select v-model.number="ventana" class="form-control form-control-sm"><option :value="0">Cada fecha por separado</option><option :value="3">Hasta 3 días</option><option :value="7">Hasta 7 días</option><option :value="15">Hasta 15 días</option><option :value="30">Hasta 30 días</option></select>
          </label>
          <label class="it-campo">Significancia
            <select v-model.number="alfa" class="form-control form-control-sm"><option :value="0.05">5 % (α = 0.05)</option><option :value="0.01">1 % (α = 0.01)</option></select>
          </label>
          <label class="it-campo">Detalle por muestreo
            <select v-model="detalle" class="form-control form-control-sm"><option value="todos">Todos los muestreos</option><option value="ultimo">Solo el último muestreo</option><option value="ninguno">Sin tablas (solo resumen y gráficas)</option></select>
          </label>
          <label class="it-check"><input v-model="verAuc" type="checkbox"> Área bajo la curva (periodo completo)</label>
        </section>

        <section class="it-grupo">
          <h3><i class="fas fa-list-ol"></i> Secciones</h3>
          <label v-for="s in SECCIONES" :key="s.clave" class="it-check"><input v-model="ver[s.clave]" type="checkbox"> {{ s.titulo }}</label>
        </section>
      </aside>

      <article class="it-document">
        <header class="it-header">
          <div class="it-header-marca"><icono-cana :tamano="46"></icono-cana><span>CENGICAÑA · EXPERIMENTOS</span></div>
          <h1>Informe técnico del ensayo</h1>
          <h2>{{ ensayo.codigo }}<small>{{ ensayo.nombre }}</small></h2>
          <p>{{ periodoTexto }}<span v-if="tratSel.length < tratamientos.length"> · Tratamientos incluidos: {{ tratSel.join(', ') || 'ninguno' }}</span></p>
          <small>Generado: {{ generado.fecha }} · {{ generado.por }}</small>
        </header>

        <nav v-if="indice.length > 2" class="it-indice" aria-label="Contenido">
          <b>Contenido</b>
          <ol><li v-for="s in indice" :key="s.clave"><a :href="'#it-' + s.clave">{{ s.titulo }}</a></li></ol>
        </nav>

        <section v-if="ver.ficha" id="it-ficha" class="it-section">
          <h3>{{ num('ficha') }}. Identificación y diseño experimental</h3>
          <dl class="it-ficha"><div v-for="f in ficha" :key="f[0]"><dt>{{ f[0] }}</dt><dd>{{ f[1] || '—' }}</dd></div></dl>
          <div v-if="ensayo.objetivo" class="it-objective"><b>Objetivo</b><p>{{ ensayo.objetivo }}</p></div>
        </section>

        <section v-if="ver.tratamientos" id="it-tratamientos" class="it-section">
          <h3>{{ num('tratamientos') }}. Tratamientos y cobertura</h3>
          <div class="it-kpis">
            <span><b>{{ tratsInforme.length }}</b> tratamientos</span>
            <span><b>{{ parcelasSel.length }}</b> parcelas</span>
            <span><b>{{ registrosInforme }}</b> registros analizados</span>
            <span><b>{{ muestrasPeriodo.length }}</b> muestras de laboratorio</span>
          </div>
          <table class="table it-table">
            <thead><tr><th>Tratamiento</th><th>Descripción</th><th>Parcelas</th><th>Registros en el periodo</th></tr></thead>
            <tbody>
              <tr v-for="t in tratsInforme" :key="t.codigo"><td><span class="it-trat" :style="{ '--c': colorTrat(t.codigo) }">{{ t.codigo }}</span></td><td>{{ t.descripcion || '—' }}</td><td>{{ parcelas.filter(p => p.tratamiento === t.codigo).length }}</td><td>{{ registrosPorTrat[t.codigo] || 0 }}</td></tr>
              <tr v-if="!tratsInforme.length"><td colspan="4">Sin tratamientos seleccionados.</td></tr>
            </tbody>
          </table>
          <p class="it-note">La ficha, los tratamientos y las parcelas muestran la configuración actual del ensayo; registros, muestras, NDVI y bitácora corresponden al periodo indicado.</p>
        </section>

        <section v-if="ver.resumen" id="it-resumen" class="it-section">
          <h3>{{ num('resumen') }}. Resumen de resultados</h3>
          <p class="it-note">Último muestreo de cada variable numérica: media por tratamiento y grupo de Tukey (α = {{ alfa }}). Significancia: *** p &lt; 0.001 · ** p &lt; 0.01 · * p &lt; 0.05 · ns no significativo.</p>
          <p v-if="!resumenFilas.length" class="it-empty">No hay variables numéricas con datos para resumir en este periodo.</p>
          <div v-else class="it-scroll">
            <table class="table it-table it-tabla-resumen">
              <thead><tr><th>Variable</th><th>Muestreo</th><th v-for="t in tratsInforme" :key="t.codigo"><span class="it-trat" :style="{ '--c': colorTrat(t.codigo) }">{{ t.codigo }}</span></th><th>p</th><th>C.V. %</th></tr></thead>
              <tbody>
                <tr v-for="f in resumenFilas" :key="f.id" :class="{ 'is-sig': f.sig }">
                  <td><b>{{ f.nombre }}</b><small v-if="f.unidad"> ({{ f.unidad }})</small></td>
                  <td>M{{ f.muestreo.n }} · {{ fecha(f.muestreo.centro) }}</td>
                  <td v-for="t in tratsInforme" :key="t.codigo" :class="{ 'is-mejor': f.mejor === t.codigo }">{{ f.medias[t.codigo] ? formato(f.medias[t.codigo].media, f.decimales) : '—' }}<sup v-if="f.medias[t.codigo] && f.medias[t.codigo].letra">{{ f.medias[t.codigo].letra }}</sup></td>
                  <td>{{ pvalor(f.p) }} <b class="it-marca">{{ f.marca }}</b></td>
                  <td>{{ formato(f.cv, 1) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="resumenFilas.length" class="it-hallazgos"><b>{{ resumenFilas.filter(f => f.sig).length }}</b> de {{ resumenFilas.length }} variables muestran diferencias significativas entre tratamientos en su último muestreo.<template v-if="resumenFilas.some(f => f.cv > 30)"> Algunas variables tienen C.V. mayor a 30 %: interpreta sus resultados con cautela.</template></p>
        </section>

        <section v-if="ver.resultados" id="it-resultados" class="it-section">
          <h3>{{ num('resultados') }}. Resultados y análisis estadístico por variable</h3>
          <p class="it-note">Motor de ANOVA y Tukey de Evaluaciones y Análisis. Cada muestreo usa una media por parcela; agrupación de {{ ventana ? 'hasta ' + ventana + ' días' : 'cada fecha por separado' }} y α = {{ alfa }}. DBCA cuando hay bloques completos; en los demás casos, DCA.</p>
          <p v-if="!seleccionEfectiva.length" class="it-empty">No se seleccionaron variables para incluir en el informe.</p>
          <p v-else-if="!analisis.length && !descriptivos.length" class="it-empty">No hay registros de las variables seleccionadas en este periodo.</p>
          <section v-for="a in analisis" :key="a.variable.id" class="it-variable">
            <h4>{{ a.variable.nombre }}{{ a.variable.unidad ? ' (' + a.variable.unidad + ')' : '' }} <small>{{ a.variable.origen === 'lab' ? 'Laboratorio' : 'Campo' }} · {{ a.variable.categoria || 'Sin categoría' }} · {{ a.puntos }} registros · {{ a.muestreos.length }} muestreos</small></h4>
            <div v-if="ver.graficos" class="it-chart"><highcharts :key="claveGrafico(a)" :options="grafico(a)"></highcharts></div>
            <div v-for="m in resultados(a)" :key="m.clave" class="it-result">
              <h5>{{ m.titulo }}</h5>
              <p class="it-stat">{{ m.res.diseno || 'Análisis descriptivo' }} · p = {{ pvalor(m.res.p) }} · C.V. {{ formato(m.res.cv, 2) }} %<span v-if="m.res.p !== null"> · {{ m.res.p < alfa ? 'Diferencias significativas entre tratamientos' : 'Sin diferencias significativas entre tratamientos' }}</span></p>
              <p v-if="m.res.aviso" class="it-warning">{{ m.res.aviso }}</p>
              <p v-else-if="m.res.p === null" class="it-note">No se pudo estimar la significancia con estos datos.</p>
              <p v-if="m.excluidas" class="it-note">Se excluyeron {{ m.excluidas }} parcelas sin datos en todos los muestreos del área bajo la curva.</p>
              <div class="it-tables">
                <table class="table it-table"><caption>ANOVA</caption>
                  <thead><tr><th>Fuente</th><th>GL</th><th>SC</th><th>CM</th><th>F</th><th>p</th></tr></thead>
                  <tbody><tr v-for="f in m.res.filas" :key="f.fuente"><td>{{ f.fuente }}</td><td>{{ f.gl }}</td><td>{{ formato(f.sc) }}</td><td>{{ formato(f.cm) }}</td><td>{{ formato(f.F) }}</td><td>{{ pvalor(f.p) }}</td></tr><tr v-if="!m.res.filas.length"><td colspan="6">No hay datos suficientes para ANOVA.</td></tr></tbody>
                </table>
                <table class="table it-table"><caption>Medias por tratamiento{{ m.unidad ? ' · ' + m.unidad : '' }}</caption>
                  <thead><tr><th>Tratamiento</th><th>n</th><th>Media</th><th>EE</th><th>Grupo</th></tr></thead>
                  <tbody><tr v-for="r in m.res.resumen" :key="r.trat"><td><span class="it-trat" :style="{ '--c': colorTrat(r.trat) }">{{ r.trat }}</span></td><td>{{ r.n }}</td><td>{{ formato(r.media, decimales(a.variable)) }}</td><td>{{ formato(r.ee) }}</td><td>{{ letra(m.res, r.trat) || '—' }}</td></tr></tbody>
                </table>
              </div>
              <p v-if="m.res.tukey && m.res.p !== null && m.res.p < alfa" class="it-note">Tukey: tratamientos que comparten al menos una letra no difieren significativamente (α = {{ alfa }}). EE: error estándar de la media.</p>
            </div>
          </section>
          <section v-for="d in descriptivos" :key="d.variable.id" class="it-variable">
            <h4>{{ d.variable.nombre }} <small>Resumen descriptivo · {{ d.variable.origen === 'lab' ? 'Laboratorio' : 'Campo' }}</small></h4>
            <table class="table it-table"><thead><tr><th>Tratamiento</th><th>Valor registrado</th><th>Registros</th></tr></thead><tbody><tr v-for="(r, i) in d.filas" :key="i"><td>{{ r.trat }}</td><td>{{ r.valor }}</td><td>{{ r.n }}</td></tr></tbody></table>
          </section>
        </section>

        <section v-if="ver.fotos && fotos.length" id="it-fotos" class="it-section">
          <h3>{{ num('fotos') }}. Registro fotográfico</h3>
          <p class="it-note">Fotos por tratamiento y fecha de muestreo{{ ver.fotosTodas ? ', todas las repeticiones' : ' (una por tratamiento: la repetición de menor número con foto)' }}.<template v-if="aplicacionFotos"> DDA: días después de la aplicación del {{ fecha(aplicacionFotos) }} registrada en la bitácora.</template></p>
          <p v-if="!fotosTabla.fechas.length" class="it-empty">No hay fotos de los tratamientos seleccionados en este periodo.</p>
          <div v-else class="it-scroll">
            <table class="table it-table it-fotos">
              <thead><tr><th>Tratamiento</th><th v-for="f in fotosTabla.fechas" :key="f">{{ fecha(f) }}<small v-if="ddaFoto(f) !== null"> · {{ ddaFoto(f) }} DDA</small></th></tr></thead>
              <tbody>
                <tr v-for="t in tratsInforme" :key="t.codigo">
                  <td><span class="it-trat" :style="{ '--c': colorTrat(t.codigo) }">{{ t.codigo }}</span><small class="it-fotos-desc">{{ t.descripcion }}</small></td>
                  <td v-for="f in fotosTabla.fechas" :key="f">
                    <figure v-for="x in (fotosTabla.celdas[t.codigo + '|' + f] || [])" :key="x.id">
                      <img :src="x.url" :alt="`${t.codigo} ${fecha(f)}`" loading="lazy">
                      <figcaption>{{ x.etiqueta }}<template v-if="x.nota"> · {{ x.nota }}</template></figcaption>
                    </figure>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section v-if="ver.muestras" id="it-muestras" class="it-section">
          <h3>{{ num('muestras') }}. Muestras de laboratorio</h3>
          <table class="table it-table">
            <thead><tr><th>Tipo de muestra</th><th v-for="e in estadosMuestra" :key="e">{{ e }}</th><th>Total</th></tr></thead>
            <tbody>
              <tr v-for="f in tablaMuestras" :key="f.tipo"><td>{{ f.tipo }}</td><td v-for="e in estadosMuestra" :key="e">{{ f[e] || '—' }}</td><td><b>{{ f.total }}</b></td></tr>
              <tr v-if="!tablaMuestras.length"><td :colspan="estadosMuestra.length + 2">Sin muestras en el periodo seleccionado.</td></tr>
            </tbody>
          </table>
        </section>

        <section v-if="ver.ndvi" id="it-ndvi" class="it-section">
          <h3>{{ num('ndvi') }}. NDVI</h3>
          <p class="it-note">Media del NDVI de las parcelas de cada tratamiento por imagen. Cada parcela aporta la media de sus píxeles.</p>
          <div class="it-scroll">
            <table class="table it-table">
              <thead><tr><th>Fecha</th><th>Fuente</th><th v-for="t in tratsInforme" :key="t.codigo"><span class="it-trat" :style="{ '--c': colorTrat(t.codigo) }">{{ t.codigo }}</span></th></tr></thead>
              <tbody>
                <tr v-for="f in ndviPorTrat" :key="f.id"><td>{{ fecha(f.fecha) }}</td><td>{{ f.fuente }}</td><td v-for="t in tratsInforme" :key="t.codigo">{{ formato(f.medias[t.codigo], 3) }}</td></tr>
                <tr v-if="!ndviPorTrat.length"><td :colspan="tratsInforme.length + 2">Sin valores NDVI por parcela en este periodo.</td></tr>
              </tbody>
            </table>
          </div>
          <details v-if="filasNdvi.length" class="it-detalle-ndvi" :open="ver.ndviParcelas">
            <summary class="no-print">Ver detalle por parcela ({{ filasNdvi.length }} filas)</summary>
            <table class="table it-table"><thead><tr><th>Fecha</th><th>Parcela</th><th>Tratamiento</th><th>Media</th><th>Mín.</th><th>Máx.</th><th>Píxeles</th></tr></thead><tbody><tr v-for="r in filasNdvi" :key="r.key"><td>{{ fecha(r.fecha) }}</td><td>{{ r.parcela }}</td><td>{{ r.tratamiento }}</td><td>{{ formato(r.media) }}</td><td>{{ formato(r.min) }}</td><td>{{ formato(r.max) }}</td><td>{{ r.n }}</td></tr></tbody></table>
          </details>
        </section>

        <section v-if="ver.bitacora" id="it-bitacora" class="it-section">
          <h3>{{ num('bitacora') }}. Bitácora del ensayo</h3>
          <table class="table it-table">
            <thead><tr><th>Fecha</th><th>Actividad</th><th>Descripción</th><th>Responsable</th></tr></thead>
            <tbody><tr v-for="(b, i) in bitacoraPeriodo" :key="i"><td>{{ fecha(b.fecha) }}</td><td>{{ b.tipo }}</td><td class="it-pre">{{ b.descripcion }}</td><td>{{ b.responsable || '—' }}</td></tr><tr v-if="!bitacoraPeriodo.length"><td colspan="4">Sin actividades registradas en este periodo.</td></tr></tbody>
          </table>
        </section>

        <section v-if="ver.observaciones" id="it-observaciones" class="it-section">
          <h3>{{ num('observaciones') }}. Observaciones y conclusiones</h3>
          <textarea v-model="observaciones" class="form-control it-obs no-print" rows="5" placeholder="Escribe aquí la interpretación de los resultados, conclusiones y recomendaciones. Se guarda en este navegador y aparece en el PDF."></textarea>
          <p class="it-obs-print it-pre">{{ observaciones || 'Sin observaciones.' }}</p>
        </section>

        <footer class="it-footer">CENGICAÑA · Experimentos · {{ ensayo.codigo }} · Generado por {{ generado.por }} el {{ generado.fecha }}</footer>
      </article>
    </div>
  </div>
</template>

<script>
import { analizarVariable, numero } from './reporte-estadistica';
import { significancia } from '../../../estadistica';

// Misma paleta de tratamientos que el detalle del ensayo (croquis, parcelas, graficas).
const COLORES_TRAT = ['#73BC25', '#FF6B00', '#FFCC00', '#A3D300', '#5b6267', '#3f7a12', '#b84d00', '#8a6a00'];
const ORIGENES = [{ v: 'todas', t: 'Todas' }, { v: 'campo', t: 'Campo' }, { v: 'lab', t: 'Laboratorio' }];
const SECCIONES = [
  { clave: 'ficha', titulo: 'Identificación y diseño' },
  { clave: 'tratamientos', titulo: 'Tratamientos y cobertura' },
  { clave: 'resumen', titulo: 'Resumen de resultados' },
  { clave: 'resultados', titulo: 'Análisis por variable' },
  { clave: 'graficos', titulo: 'Gráficas de evolución', sub: true },
  { clave: 'fotos', titulo: 'Registro fotográfico' },
  { clave: 'fotosTodas', titulo: 'Fotos de todas las repeticiones', sub: true },
  { clave: 'muestras', titulo: 'Muestras de laboratorio' },
  { clave: 'ndvi', titulo: 'NDVI' },
  { clave: 'ndviParcelas', titulo: 'NDVI por parcela', sub: true },
  { clave: 'bitacora', titulo: 'Bitácora' },
  { clave: 'observaciones', titulo: 'Observaciones y conclusiones' },
];
const VER_INICIAL = { ficha: true, tratamientos: true, resumen: true, resultados: true, graficos: true, fotos: true, fotosTodas: false, muestras: true, ndvi: true, ndviParcelas: false, bitacora: true, observaciones: true };

function leer(clave) { try { return JSON.parse(localStorage.getItem(clave)); } catch (e) { return null; } }
function guardar(clave, valor) { try { localStorage.setItem(clave, JSON.stringify(valor)); } catch (e) { /* sin almacenamiento */ } }
const hoy = () => new Date().toISOString().slice(0, 10);
const restarDias = (n) => new Date(Date.now() - n * 86400000).toISOString().slice(0, 10);

export default {
  name: 'InformeEnsayo',
  props: {
    ensayo: { type: Object, required: true },
    tratamientos: { type: Array, default: () => [] },
    parcelas: { type: Array, default: () => [] },
    variables: { type: Array, default: () => [] },
    registros: { type: Array, default: () => [] },
    ndvi: { type: Array, default: () => [] },
    muestras: { type: Array, default: () => [] },
    bitacora: { type: Array, default: () => [] },
    fotos: { type: Array, default: () => [] },
    generado: { type: Object, required: true },
    periodo: { type: Object, default: () => ({}) },
    reportesUrl: { type: String, required: true },
  },
  data() {
    const inicial = this.configInicial();
    // La configuracion guardada (por ensayo) se aplica salvo que la URL traiga un periodo.
    const guardada = this.periodo.desde || this.periodo.hasta ? null : leer(this.claveConfig());
    return {
      ORIGENES, SECCIONES,
      ...inicial,
      ...(guardada || {}),
      ver: { ...VER_INICIAL, ...((guardada && guardada.ver) || {}) },
      buscarVar: '',
      observaciones: leer(`informe-obs-${this.ensayo.id}`) || '',
    };
  },
  computed: {
    config() {
      return { preset: this.preset, desde: this.desde, hasta: this.hasta, tratSel: this.tratSel, seleccion: this.seleccion, origen: this.origen, ventana: this.ventana, alfa: this.alfa, detalle: this.detalle, verAuc: this.verAuc, ver: this.ver };
    },
    periodoTexto() {
      if (!this.desde && !this.hasta) return 'Periodo: historial completo del ensayo';
      return `Periodo: ${this.desde ? this.fecha(this.desde) : 'inicio del ensayo'} al ${this.hasta ? this.fecha(this.hasta) : 'último registro'}`;
    },
    rangoDatos() {
      const fechas = this.registros.map((r) => r.fecha).filter(Boolean).sort();
      return fechas.length ? `${this.fecha(fechas[0])} al ${this.fecha(fechas[fechas.length - 1])}` : 'sin registros';
    },
    ficha() {
      const e = this.ensayo;
      return [
        ['Programa', e.programa ? `${e.programa.sigla} · ${e.programa.nombre}` : ''],
        ['Proyecto', e.proyecto ? `${e.proyecto.codigo} · ${e.proyecto.nombre}` : ''],
        ['Responsables', (e.responsables || []).join(', ')],
        ['Estado', e.estado], ['Diseño experimental', e.diseno],
        ['Tratamientos / repeticiones', `${this.tratamientos.length} / ${e.num_repeticiones || '—'}`],
        ['Ingenio', e.ingenio], ['Finca / lote', [e.finca, e.lote].filter(Boolean).join(' / ')],
        ['Cultivo / variedad', [e.cultivo, e.variedad].filter(Boolean).join(' / ')], ['Ciclo', e.ciclo],
        ['Siembra / corte', this.fecha(e.fecha_siembra)], ['Cosecha prevista', this.fecha(e.fecha_cosecha_prevista)],
        ['Cosecha real', this.fecha(e.fecha_cosecha)], ['Área total (ha)', e.area_total],
        ['Estrato / zona', [e.estrato, e.zona].filter(Boolean).join(' / ')],
      ];
    },
    tratsInforme() { return this.tratamientos.filter((t) => this.tratSel.includes(t.codigo)); },
    parcelasSel() {
      const todos = this.tratSel.length === this.tratamientos.length;
      return this.parcelas.filter((p) => (p.tratamiento ? this.tratSel.includes(p.tratamiento) : todos));
    },
    idsParcelasSel() { return new Set(this.parcelasSel.map((p) => p.id)); },
    registrosPeriodo() { return this.registros.filter((r) => this.enRango(r.fecha) && this.idsParcelasSel.has(r.parcela_id)); },
    conteoPorVariable() {
      const n = {};
      this.registrosPeriodo.forEach((r) => { n[r.variable_id] = (n[r.variable_id] || 0) + 1; });
      return n;
    },
    variablesConDatos() { return this.variables.map((v) => ({ ...v, n: this.conteoPorVariable[v.id] || 0 })); },
    variablesAgrupadas() {
      const q = this.buscarVar.trim().toLowerCase();
      const grupos = new Map();
      this.variablesConDatos
        .filter((v) => (this.origen === 'todas' || (v.origen === 'lab' ? 'lab' : 'campo') === this.origen) && (!q || v.nombre.toLowerCase().includes(q)))
        .forEach((v) => {
          const c = v.origen === 'lab' ? `Laboratorio${v.categoria ? ' · ' + v.categoria : ''}` : v.categoria || 'Sin categoría';
          if (!grupos.has(c)) grupos.set(c, []);
          grupos.get(c).push(v);
        });
      return [...grupos.entries()].sort((a, b) => a[0].localeCompare(b[0], 'es')).map(([categoria, variables]) => ({ categoria, variables }));
    },
    // Variables marcadas, del origen elegido y con datos en el periodo.
    variablesInforme() {
      return this.variablesConDatos.filter((v) => this.seleccion.includes(v.id) && v.n && (this.origen === 'todas' || (v.origen === 'lab' ? 'lab' : 'campo') === this.origen));
    },
    seleccionEfectiva() { return this.variablesInforme.map((v) => v.id); },
    registrosInforme() { return this.variablesInforme.reduce((n, v) => n + v.n, 0); },
    registrosPorTrat() {
      const p = new Map(this.parcelas.map((x) => [x.id, x.tratamiento]));
      const n = {};
      this.registrosPeriodo.forEach((r) => { const t = p.get(r.parcela_id); if (t) n[t] = (n[t] || 0) + 1; });
      return n;
    },
    analisis() {
      return this.variablesInforme.filter((v) => v.tipo === 'Numerica')
        .map((v) => analizarVariable(v, this.registrosPeriodo, this.parcelasSel, this.ensayo, this.ventana, this.alfa))
        .filter((a) => a.muestreos.length);
    },
    resumenFilas() {
      return this.analisis.map((a) => {
        const m = a.muestreos[a.muestreos.length - 1];
        const sig = m.res.p !== null && m.res.p < this.alfa;
        const medias = {};
        m.res.resumen.forEach((r) => { medias[r.trat] = { media: r.media, letra: this.letra(m.res, r.trat) }; });
        const conMedia = m.res.resumen.filter((r) => r.media !== null);
        const mejor = sig && conMedia.length ? conMedia.reduce((x, y) => (y.media > x.media ? y : x)).trat : null;
        return {
          id: a.variable.id, nombre: a.variable.nombre, unidad: a.variable.unidad, decimales: this.decimales(a.variable),
          muestreo: m, medias, p: m.res.p, cv: m.res.cv, sig, mejor, marca: significancia(m.res.p).marca,
        };
      });
    },
    descriptivos() {
      const p = new Map(this.parcelas.map((x) => [x.id, x]));
      return this.variablesInforme.filter((v) => v.tipo !== 'Numerica').map((variable) => {
        const filas = new Map();
        this.registrosPeriodo.filter((r) => r.variable_id === variable.id && r.valor !== null && String(r.valor).trim() !== '').forEach((r) => {
          const trat = (p.get(r.parcela_id) || {}).tratamiento || 'Sin trat.';
          const key = JSON.stringify([trat, r.valor]);
          if (!filas.has(key)) filas.set(key, { trat, valor: r.valor, n: 0 });
          filas.get(key).n++;
        });
        return { variable, filas: [...filas.values()] };
      }).filter((d) => d.filas.length);
    },
    muestrasPeriodo() { return this.muestras.filter((m) => this.enRango(m.fecha) && (!m.parcela_id || this.idsParcelasSel.has(m.parcela_id))); },
    estadosMuestra() { return [...new Set(this.muestrasPeriodo.map((m) => m.estado || 'Sin estado'))].sort(); },
    tablaMuestras() {
      const filas = new Map();
      this.muestrasPeriodo.forEach((m) => {
        const tipo = m.tipo || 'Sin tipo';
        if (!filas.has(tipo)) filas.set(tipo, { tipo, total: 0 });
        const f = filas.get(tipo); const e = m.estado || 'Sin estado';
        f[e] = (f[e] || 0) + 1; f.total++;
      });
      return [...filas.values()];
    },
    ndviPeriodo() { return this.ndvi.filter((i) => this.enRango(i.fecha)); },
    filasNdvi() {
      const p = new Map(this.parcelasSel.map((x) => [String(x.id), x])); const filas = [];
      this.ndviPeriodo.forEach((i) => Object.entries(i.valores || {}).forEach(([id, v]) => {
        const parcela = p.get(id);
        if (!parcela || !v || numero(v.media) === null) return;
        filas.push({ ...v, key: `${i.id}:${id}`, fecha: i.fecha, fuente: i.fuente, parcela: parcela.codigo, tratamiento: parcela.tratamiento });
      }));
      return filas;
    },
    ndviPorTrat() {
      const porImagen = new Map();
      this.filasNdvi.forEach((r) => {
        const id = r.key.split(':')[0];
        if (!porImagen.has(id)) porImagen.set(id, { id, fecha: r.fecha, fuente: r.fuente, valores: {} });
        const v = porImagen.get(id).valores;
        (v[r.tratamiento] = v[r.tratamiento] || []).push(numero(r.media));
      });
      return [...porImagen.values()].map((f) => ({
        ...f, medias: Object.fromEntries(Object.entries(f.valores).map(([t, xs]) => [t, xs.reduce((a, b) => a + b, 0) / xs.length])),
      }));
    },
    bitacoraPeriodo() { return this.bitacora.filter((b) => this.enRango(b.fecha)); },
    // Ultima aplicacion de producto / control de maleza en la bitacora antes de las fotos (para DDA).
    aplicacionesFotos() { return this.bitacora.filter((b) => /aplicaci|control de maleza/i.test(b.tipo || '')).map((b) => String(b.fecha).slice(0, 10)).sort(); },
    aplicacionFotos() { return this.aplicacionesFotos.filter((a) => this.fotosTabla.fechas.some((f) => f >= a)).shift() || null; },
    fotosTabla() {
      const p = new Map(this.parcelasSel.map((x) => [x.id, x]));
      const celdas = {}; const fechas = new Set();
      this.fotos.filter((f) => this.enRango(f.fecha) && p.has(f.parcela_id))
        .sort((a, b) => (p.get(a.parcela_id).repeticion || 0) - (p.get(b.parcela_id).repeticion || 0))
        .forEach((f) => {
          const parcela = p.get(f.parcela_id); const k = `${parcela.tratamiento}|${f.fecha}`;
          const lista = celdas[k] || (celdas[k] = []);
          // Sin "todas las repeticiones": una foto por tratamiento y fecha (la de menor repeticion).
          if (!this.ver.fotosTodas && lista.length) return;
          lista.push({ ...f, etiqueta: `${parcela.codigo} · R${parcela.repeticion}` });
          fechas.add(f.fecha);
        });
      return { celdas, fechas: [...fechas].sort() };
    },
    indice() { return SECCIONES.filter((s) => !s.sub && this.ver[s.clave] && (s.clave !== 'fotos' || this.fotos.length)); },
  },
  watch: {
    config: { deep: true, handler(c) { guardar(this.claveConfig(), c); } },
    observaciones(t) { guardar(`informe-obs-${this.ensayo.id}`, t); },
  },
  methods: {
    claveConfig() { return `informe-config-${this.ensayo.id}`; },
    configInicial() {
      const conPeriodo = this.periodo.desde || this.periodo.hasta;
      return {
        preset: conPeriodo ? 'personalizado' : 'todo',
        desde: this.periodo.desde || '', hasta: this.periodo.hasta || '',
        tratSel: this.tratamientos.map((t) => t.codigo),
        seleccion: this.variables.map((v) => v.id),
        origen: 'todas', ventana: 7, alfa: 0.05, detalle: 'todos', verAuc: true,
      };
    },
    restablecer() {
      Object.assign(this, this.configInicial(), { preset: 'todo', desde: '', hasta: '', ver: { ...VER_INICIAL }, buscarVar: '' });
    },
    aplicarPreset() {
      const p = this.preset;
      if (p === 'personalizado') return;
      this.hasta = '';
      this.desde = p === 'todo' ? '' : p === 'siembra' ? this.ensayo.fecha_siembra || '' : p === 'anio' ? `${hoy().slice(0, 4)}-01-01` : restarDias(Number(p));
    },
    enRango(f) {
      if (!f) return !this.desde && !this.hasta;
      const d = String(f).slice(0, 10);
      return (!this.desde || d >= this.desde) && (!this.hasta || d <= this.hasta);
    },
    marcarVisibles(marcar) {
      const ids = this.variablesAgrupadas.flatMap((g) => g.variables.map((v) => v.id));
      this.seleccion = marcar ? [...new Set([...this.seleccion, ...ids])] : this.seleccion.filter((id) => !ids.includes(id));
    },
    num(clave) { return this.indice.findIndex((s) => s.clave === clave) + 1; },
    colorTrat(codigo) {
      const i = this.tratamientos.findIndex((t) => t.codigo === codigo);
      return COLORES_TRAT[(i < 0 ? 0 : i) % COLORES_TRAT.length];
    },
    letra(res, trat) { return res.tukey && res.p !== null && res.p < this.alfa ? res.tukey.letras[trat] || '' : ''; },
    decimales(v) { return v.decimales == null ? 3 : v.decimales; },
    imprimir() { window.print(); },
    fecha(f) { return f ? String(f).slice(0, 10).split('-').reverse().join('/') : '—'; },
    formato(n, d = 3) { return n === null || n === undefined || !Number.isFinite(Number(n)) ? '—' : Number(n).toLocaleString('es-GT', { minimumFractionDigits: Math.min(6, d), maximumFractionDigits: Math.min(6, d) }); },
    pvalor(p) { return p === null || p === undefined ? '—' : p < 0.001 ? '< 0.001' : p.toFixed(3); },
    ddaFoto(f) { const a = this.aplicacionesFotos.filter((x) => x <= f).pop(); return a ? Math.round((Date.parse(f) - Date.parse(a)) / 86400000) : null; },
    resultados(a) {
      if (this.detalle === 'ninguno') return [];
      const muestreos = this.detalle === 'ultimo' ? a.muestreos.slice(-1) : a.muestreos;
      const filas = muestreos.map((m) => ({ clave: m.n, titulo: `Muestreo ${m.n} · ${this.fecha(m.desde)}${m.hasta !== m.desde ? ' al ' + this.fecha(m.hasta) : ''}`, res: m.res, unidad: a.variable.unidad }));
      if (a.auc && this.verAuc) filas.push({ clave: 'auc', titulo: 'Área bajo la curva · periodo completo seleccionado', res: a.auc.res, excluidas: a.auc.excluidas, unidad: `${a.variable.unidad || ''} · días` });
      return filas;
    },
    // highcharts-vue mezcla opciones al actualizar: la clave fuerza un grafico nuevo al cambiar filtros.
    claveGrafico(a) { return [a.variable.id, this.tratSel.join(','), this.ventana, this.desde, this.hasta, a.muestreos.length].join('|'); },
    grafico(a) {
      const tratamientos = [...new Set(a.muestreos.flatMap((m) => m.res.resumen.map((r) => r.trat)))];
      return {
        chart: { type: 'line', height: 270, style: { fontFamily: 'Arial, sans-serif' }, animation: false },
        title: { text: null }, credits: { enabled: false }, exporting: { enabled: false },
        xAxis: { categories: a.muestreos.map((m) => `M${m.n} · ${this.fecha(m.centro)}`) },
        yAxis: { title: { text: a.variable.unidad || 'Valor' } },
        tooltip: { shared: true, valueDecimals: this.decimales(a.variable) },
        plotOptions: { series: { animation: false, marker: { radius: 4 } } },
        series: tratamientos.map((t) => ({
          name: t, color: this.colorTrat(t), connectNulls: false,
          data: a.muestreos.map((m) => { const r = m.res.resumen.find((x) => x.trat === t); return r ? r.media : null; }),
        })),
      };
    },
  },
};
</script>

<style scoped>
.it{max-width:1500px;margin:auto;color:#29382f}
.it-toolbar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px}
.it-toolbar-der{display:flex;align-items:center;flex-wrap:wrap;gap:10px}
.it-resumen-filtros{font-size:12px;color:#69766e}
.it-layout{display:grid;grid-template-columns:290px minmax(0,1fr);gap:20px;align-items:start}
.it-panel{position:sticky;top:12px;max-height:calc(100vh - 24px);overflow:auto;padding:14px;background:#f6f9f2;border:1px solid #dce8d3;border-radius:12px}
.it-grupo{padding:12px 0;border-bottom:1px solid #e1eadb}.it-grupo:first-child{padding-top:0}.it-grupo:last-child{border-bottom:0}
.it-grupo h3{display:flex;align-items:center;gap:7px;font-size:13px;font-weight:700;margin:0 0 9px;color:#3f5a2f}
.it-grupo h3 i{color:#73bc25;width:15px;text-align:center}.it-grupo h3 small{margin-left:auto;font-size:11px;font-weight:500;color:#69766e}
.it-panel .form-control{font-size:12px;margin-bottom:6px}
.it-panel input[type=checkbox]{accent-color:#73bc25}
.it-fechas{display:grid;grid-template-columns:1fr 1fr;gap:8px}.it-fechas label,.it-campo{display:block;font-size:11px;font-weight:600;color:#4d5a48;margin:0 0 4px}
.it-campo .form-control{margin-top:3px}
.it-ayuda{font-size:11px;color:#69766e;margin:2px 0 0}.it-ayuda.is-aviso{color:#b84d00}
.it-chips{display:flex;flex-wrap:wrap;gap:6px}
.it-chip{display:inline-flex;align-items:center;gap:5px;margin:0;padding:3px 9px;border:1px solid #d6dfd0;border-radius:999px;background:#fff;font-size:12px;font-weight:600;color:#8a948c;cursor:pointer}
.it-chip input{display:none}.it-chip::before{content:'';width:9px;height:9px;border-radius:50%;background:#ced2d5}
.it-chip.is-on{color:#29382f;border-color:var(--c)}.it-chip.is-on::before{background:var(--c)}
.it-links{display:flex;gap:14px;margin:6px 0}.it-links button{border:0;padding:0;background:none;color:#527443;font-size:11px;text-decoration:underline}
.it-segmento{display:flex;margin-bottom:7px;border:1px solid #cfdcc6;border-radius:7px;overflow:hidden}
.it-segmento button{flex:1;border:0;padding:5px 4px;background:#fff;font-size:11px;color:#4d5a48}.it-segmento button+button{border-left:1px solid #cfdcc6}
.it-segmento button.is-on{background:#73bc25;color:#fff;font-weight:600}
.it-vars{max-height:300px;overflow:auto;padding-right:4px}
.it-vars-grupo{margin-bottom:8px}.it-vars-grupo>b{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:#69766e;margin:4px 0}
.it-vars label{display:flex;align-items:flex-start;gap:6px;margin:0;padding:3px 0;font-size:12px;font-weight:500;line-height:1.3}
.it-vars label span{flex:1}.it-vars label small{color:#69766e}.it-vars label em{font-style:normal;font-size:10px;color:#527443;background:#e6f2d8;border-radius:8px;padding:0 6px}
.it-vars label.is-vacio{opacity:.5}.it-vars label.is-vacio em{background:#eceeec;color:#8a948c}
.it-check{display:flex;align-items:center;gap:7px;margin:0;padding:3px 0;font-size:12px;font-weight:500}
.it-document{padding:38px;background:#fff;border:1px solid #dfe5e0;border-radius:12px;min-width:0}
.it-header{border-bottom:3px solid #73bc25;padding-bottom:22px}
.it-header-marca{display:flex;align-items:center;gap:10px}.it-header-marca span{font-size:11px;font-weight:700;letter-spacing:1.3px;color:#527443}
.it-header h1{font-size:26px;font-weight:700;margin:10px 0}.it-header h2{font-size:23px;margin:0 0 12px}.it-header h2 small{display:block;font-size:15px;margin-top:8px;color:#69766e}
.it-header p,.it-header>small{font-size:12px;color:#69766e;margin:7px 0}
.it-indice{margin-top:18px;padding:12px 16px;background:#f7f9f5;border-radius:8px;font-size:12px}.it-indice ol{margin:6px 0 0;padding-left:20px;columns:2}.it-indice a{color:#3f5a2f}
.it-section{margin-top:28px}.it-section>h3{font-size:18px;font-weight:700;padding-bottom:10px;border-bottom:1px solid #dfe5e0}
.it-ficha{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-top:18px}.it-ficha dt{font-size:11px;text-transform:uppercase;color:#69766e}.it-ficha dd{font-size:13px;margin:4px 0 0;overflow-wrap:anywhere}
.it-objective{padding:14px;background:#f7f9f5;border-radius:8px;font-size:13px}.it-objective p{white-space:pre-wrap;margin:6px 0}
.it-kpis{display:flex;flex-wrap:wrap;gap:24px;padding:14px 0}.it-kpis span{font-size:12px;color:#69766e}.it-kpis b{font-size:22px;color:#527443;margin-right:5px}
.it-note{font-size:11px;line-height:1.6;color:#69766e}
.it-scroll{overflow-x:auto}
.it-table{font-size:11px;table-layout:auto;width:100%;min-width:0!important;margin:12px 0}
.it-table caption{caption-side:top;font-size:12px;font-weight:700;color:#29382f;padding:7px 0}
.it-table th{font-size:10px;background:#f1f5ed;text-transform:uppercase}
.it-table td,.it-table th{padding:7px 8px;overflow-wrap:anywhere;vertical-align:top;border-color:#e0e6dd}
.it-trat{display:inline-flex;align-items:center;gap:5px;font-weight:700;text-transform:none}.it-trat::before{content:'';width:8px;height:8px;border-radius:50%;background:var(--c)}
.it-tabla-resumen td{white-space:nowrap}.it-tabla-resumen td:first-child{white-space:normal;min-width:160px}
.it-tabla-resumen sup{margin-left:2px;font-weight:700;color:#527443}
.it-tabla-resumen tr.is-sig td:first-child{box-shadow:inset 3px 0 #73bc25}
.it-tabla-resumen td.is-mejor{background:#eef7e3;font-weight:700}
.it-marca{color:#b84d00}
.it-hallazgos{font-size:12px;padding:10px 12px;background:#f7f9f5;border-left:3px solid #73bc25;border-radius:4px}
.it-variable{margin-top:25px;padding-top:12px;border-top:2px solid #dae6d2}.it-variable h4{font-size:17px;font-weight:700}.it-variable h4 small{display:block;font-size:11px;font-weight:400;color:#69766e;margin-top:7px}
.it-result{margin:18px 0}.it-result h5{font-size:13px;font-weight:700;margin:0 0 7px}.it-stat{font-size:11px;margin:7px 0}
.it-tables{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:16px;align-items:start}
.it-warning{font-size:11px;background:#fff7e6;padding:9px;color:#7a4a00;border-radius:6px}
.it-chart{margin:14px 0}.it-pre{white-space:pre-wrap}
.it-empty{padding:20px;background:#f7f9f5;font-size:13px;color:#69766e}
.it-detalle-ndvi summary{font-size:12px;color:#527443;cursor:pointer}
.it-obs{font-size:13px}.it-obs-print{display:none;font-size:13px}
.it-footer{border-top:1px solid #dfe5e0;margin-top:28px;padding-top:12px;font-size:10px;color:#69766e}
.it-fotos td{min-width:150px}.it-fotos figure{margin:0 0 6px}.it-fotos img{display:block;width:100%;max-width:220px;aspect-ratio:4/3;object-fit:cover;border-radius:4px;border:1px solid #dfe5e0}
.it-fotos figcaption{font-size:9px;color:#69766e;margin-top:2px}.it-fotos-desc{display:block;font-size:10px;color:#69766e;margin-top:3px;text-transform:none}
@media(max-width:1100px){.it-layout{grid-template-columns:1fr}.it-panel{position:static;max-height:none}}
@media(max-width:900px){.it-tables{grid-template-columns:1fr}.it-ficha{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.it-document{padding:18px}.it-ficha{grid-template-columns:1fr}.it-header h1{font-size:22px}.it-indice ol{columns:1}}
@media print{
  .it{max-width:none;color:#18231a}.it-layout{display:block}
  .it-document{padding:0;border:0;border-radius:0}.it-header h1{font-size:21px}
  .it-indice{display:none}
  .it-ficha{grid-template-columns:repeat(3,minmax(0,1fr))}.it-tables{display:block}
  .it-scroll{overflow:visible}
  .it-table{width:100%;font-size:9px}.it-table th{font-size:8px}.it-table td,.it-table th{padding:5px}.it-table thead{display:table-header-group}
  .it-table tr,.it-ficha>div,.it-chart{break-inside:avoid}.it-section>h3,.it-variable h4,.it-result h5{break-after:avoid}
  .it-chart{max-width:100%}.it-footer{font-size:8px}
  .it-fotos img{max-width:120px}.it-fotos tr{break-inside:avoid}
  .it-obs-print{display:block}
  .it-document{print-color-adjust:exact;-webkit-print-color-adjust:exact}
  @page{size:A4;margin:14mm}
}
</style>
