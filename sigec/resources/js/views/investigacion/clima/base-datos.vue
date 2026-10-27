<template>
  <div class="bd">
    <div class="bd-sub">
      <button v-for="s in secciones" :key="s.id" type="button" :class="{ 'is-on': seccion === s.id }" @click="seccion = s.id">
        <i :class="s.icon"></i> {{ s.label }}
        <span v-if="s.id === 'calidad' && est && est.calidad.pendientes" class="bd-badge">{{ est.calidad.pendientes.toLocaleString('es-GT') }}</span>
      </button>
    </div>

    <!-- ===================== ESTADO Y COBERTURA ===================== -->
    <section v-show="seccion === 'estado'">
      <div v-if="!est" class="bd-vacio"><i class="fas fa-spinner fa-spin"></i> Cargando estado de la base...</div>
      <template v-else>
        <div class="bd-cards">
          <div class="bd-card"><span>Periodo en la base</span><strong>{{ fmt(est.desde) }} → {{ fmt(est.hasta) }}</strong><small>{{ aniosBase }} años</small></div>
          <div class="bd-card"><span>Registros diarios</span><strong>{{ n(est.registros) }}</strong><small>{{ est.estaciones_con_datos }} estaciones con datos</small></div>
          <div class="bd-card"><span>Ultima sincronizacion</span><strong>{{ est.ultima_sync ? haceCuanto(est.ultima_sync.created_at) : '—' }}</strong><small v-if="est.ultima_sync">{{ tipoTxt(est.ultima_sync.tipo) }} · {{ fmt(est.ultima_sync.desde) }} → {{ fmt(est.ultima_sync.hasta) }}</small></div>
          <div class="bd-card" :class="{ 'is-warn': est.calidad.pendientes }">
            <span>Control de calidad</span>
            <strong>{{ n(est.calidad.excluidos) }} <small>excluidos</small></strong>
            <small><a href="#" @click.prevent="irCalidad('pendiente')">{{ n(est.calidad.pendientes) }} pendientes de revisar</a> · {{ n(est.calidad.validados) }} validados</small>
          </div>
        </div>

        <div class="bd-head">
          <h6><i class="fas fa-th"></i> Cobertura: % de dias con datos por estacion y año</h6>
          <div class="bd-leyenda">
            <span v-for="p in escala" :key="p.label"><i :style="{ background: p.color }"></i>{{ p.label }}</span>
          </div>
        </div>
        <div class="bd-filtro">
          <input class="form-control form-control-sm" v-model="buscarCob" placeholder="Buscar estacion...">
          <label class="mb-0"><input type="checkbox" v-model="soloConDatos"> Solo estaciones con datos</label>
        </div>
        <div class="bd-matriz-wrap">
          <table class="bd-matriz">
            <thead>
              <tr><th class="bd-est">Estacion</th><th v-for="a in aniosCob" :key="a">{{ String(a).slice(2) }}</th><th>Total</th></tr>
            </thead>
            <tbody>
              <tr v-for="s in estacionesCob" :key="s.id">
                <th class="bd-est" :title="`${s.codigo}${s.estrato ? ' · ' + s.estrato : ''}${s.zona ? ' · ' + s.zona : ''}`">{{ s.nombre }}</th>
                <td v-for="a in aniosCob" :key="a" :style="celdaEstilo(pct(s, a))" :title="`${s.nombre} ${a}: ${s.anios[a] || 0} de ${diasAnio(a)} dias`" @click="detalleCelda(s, a)">
                  {{ pct(s, a) ? pct(s, a) : '' }}
                </td>
                <td class="bd-total">{{ n(totalEst(s)) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="celda" class="bd-detalle">
          <b>{{ celda.estacion }} · {{ celda.anio }}</b> — dias con datos por mes:
          <span v-for="m in celda.meses" :key="m.mes" class="bd-mes" :style="celdaEstilo(Math.round((m.dias / m.total) * 100))">{{ MESES[m.mes - 1] }} {{ m.dias }}/{{ m.total }}</span>
          <a href="#" class="ml-2" @click.prevent="celda = null">cerrar</a>
        </div>
      </template>
    </section>

    <!-- ===================== SINCRONIZACION ===================== -->
    <section v-show="seccion === 'sync'">
      <div v-if="!configurada" class="bd-aviso"><i class="fas fa-plug"></i> Configure primero la conexion en la pestaña <b>Conexion API ICC</b>.</div>
      <div class="bd-acciones">
        <div class="bd-accion">
          <h6><i class="fas fa-sync-alt"></i> Actualizar ahora</h6>
          <p>Vuelve a pedir los ultimos {{ diasActualizar }} dias (el ICC los sigue completando) y aplica el control de calidad. Se hace solo al abrir Clima si pasaron mas de 3 horas.</p>
          <button type="button" class="btn btn-success btn-sm" :disabled="!configurada || ocupado || !puedeSincronizar" @click="actualizar">
            <i :class="ocupado === 'actualizar' ? 'fas fa-spinner fa-spin' : 'fas fa-sync-alt'"></i> Actualizar ultimos {{ diasActualizar }} dias
          </button>
        </div>
        <div class="bd-accion">
          <h6><i class="fas fa-redo"></i> Volver a descargar un periodo</h6>
          <p>Reemplaza lo guardado con lo que hoy tiene la API (p. ej. tras correcciones del ICC). Las exclusiones del control de calidad se mantienen.</p>
          <div class="bd-fila">
            <input type="date" class="form-control form-control-sm" v-model="rd.desde" :max="hoy">
            <input type="date" class="form-control form-control-sm" v-model="rd.hasta" :min="rd.desde" :max="hoy">
            <button type="button" class="btn btn-secondary btn-sm" :disabled="!configurada || ocupado || !rd.desde || !rd.hasta || !puedeSincronizar" @click="redescargar">
              <i :class="ocupado === 'redescarga' ? 'fas fa-spinner fa-spin' : 'fas fa-redo'"></i> Descargar
            </button>
          </div>
        </div>
      </div>

      <clima-historial v-if="puedeSincronizar" class="mt-3" :configurada="configurada" @actualizado="recargar"></clima-historial>

      <div class="bd-head mt-3">
        <h6><i class="fas fa-list"></i> Bitacora de sincronizaciones</h6>
        <button type="button" class="btn btn-link btn-sm" @click="cargarBitacora"><i class="fas fa-redo"></i> Actualizar</button>
      </div>
      <div class="bd-tabla-wrap">
        <table class="table bd-tabla">
          <thead><tr><th>Fecha y hora</th><th>Tipo</th><th>Periodo</th><th class="text-right">Dias</th><th class="text-right">Registros</th><th class="text-right">Excluidos</th><th>Usuario</th><th>Resultado</th></tr></thead>
          <tbody>
            <tr v-for="b in bitacora" :key="b.id">
              <td class="bd-nowrap">{{ fechaHora(b.created_at) }}</td>
              <td>{{ tipoTxt(b.tipo) }}</td>
              <td class="bd-nowrap">{{ fmt(b.desde) }} → {{ fmt(b.hasta) }}</td>
              <td class="text-right">{{ n(b.dias) }}</td>
              <td class="text-right">{{ n(b.registros) }}</td>
              <td class="text-right">{{ b.excluidos ? n(b.excluidos) : '' }}</td>
              <td>{{ b.usuario || 'Sistema' }}</td>
              <td><span v-if="b.error" class="bd-err" :title="b.error"><i class="fas fa-times-circle"></i> {{ b.error.slice(0, 60) }}</span><span v-else class="bd-ok"><i class="fas fa-check-circle"></i> Correcto</span></td>
            </tr>
            <tr v-if="!bitacora.length"><td colspan="8" class="text-center bd-muted">Sin sincronizaciones registradas.</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ===================== CONTROL DE CALIDAD ===================== -->
    <section v-show="seccion === 'calidad'">
      <div class="bd-head">
        <h6><i class="fas fa-ruler-combined"></i> Rangos por variable (toda la red)</h6>
        <div class="bd-fila">
          <button v-if="puedeGestionar && rangosSucios" type="button" class="btn btn-success btn-sm" :disabled="guardandoRangos" @click="guardarRangos">
            <i :class="guardandoRangos ? 'fas fa-spinner fa-spin' : 'fas fa-save'"></i> Guardar rangos
          </button>
        </div>
      </div>
      <p class="bd-ayuda">
        <b class="bd-err">Error</b>: fuera del rango valido → se excluye solo (reversible).
        <b class="bd-atip">Atipico</b>: fuera del rango de alerta, o a mas de {{ z }} desviaciones del promedio de esa estacion en ese mes → queda pendiente de revision y se sigue usando.
        Tras cambiar rangos pulse <b>Revisar toda la base</b> para aplicarlos.
      </p>
      <div class="bd-tabla-wrap">
        <table class="table bd-tabla bd-rangos">
          <thead>
            <tr><th>Variable</th><th>Unidad</th><th colspan="2" class="text-center">Rango valido (error fuera)</th><th colspan="2" class="text-center">Rango de alerta (atipico fuera)</th><th class="text-center">Revision estadistica</th></tr>
            <tr class="bd-sub-h"><th></th><th></th><th>Min</th><th>Max</th><th>Min</th><th>Max</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="r in rangos" :key="r.variable">
              <td><b>{{ nombreVar(r.variable) }}</b></td>
              <td class="bd-muted">{{ unidadVar(r.variable) }}</td>
              <td v-for="k in ['valido_min', 'valido_max', 'alerta_min', 'alerta_max']" :key="k">
                <input type="number" step="any" class="form-control form-control-sm bd-num" v-model="r[k]" :disabled="!puedeGestionar" placeholder="—">
              </td>
              <td class="text-center"><input type="checkbox" v-model="r.estadistico" :disabled="!puedeGestionar"></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="bd-fila mt-2">
        <label class="mb-0">Umbral estadistico:
          <input type="number" min="2" max="6" step="0.5" class="form-control form-control-sm bd-num d-inline-block" v-model.number="z" :disabled="!puedeGestionar"> desviaciones
        </label>
        <button v-if="puedeSincronizar" type="button" class="btn btn-secondary btn-sm ml-auto" :disabled="!!revisando" @click="revisarTodo">
          <i :class="revisando ? 'fas fa-spinner fa-spin' : 'fas fa-search'"></i> {{ revisando ? `Revisando ${revisando}...` : 'Revisar toda la base' }}
        </button>
      </div>
      <div v-if="progresoRev" class="bd-prog">
        <div class="bd-barra"><i :style="{ width: progresoRev.pct + '%' }"></i></div>
        <small>{{ progresoRev.texto }}</small>
      </div>

      <div class="bd-head mt-3">
        <h6><i class="fas fa-clipboard-check"></i> Revision de valores</h6>
      </div>
      <div class="bd-filtros">
        <select class="form-control form-control-sm" v-model="fh.estado" @change="cargarHallazgos(1)">
          <option value="">Todos los estados</option><option value="pendiente">Pendientes</option><option value="excluido">Excluidos</option><option value="validado">Validados</option>
        </select>
        <select class="form-control form-control-sm" v-model="fh.nivel" @change="cargarHallazgos(1)">
          <option value="">Errores y atipicos</option><option value="error">Solo errores</option><option value="atipico">Solo atipicos</option>
        </select>
        <select class="form-control form-control-sm" v-model="fh.variable" @change="cargarHallazgos(1)">
          <option value="">Todas las variables</option><option v-for="v in VARS" :key="v.id" :value="v.id">{{ v.nombre }}</option>
        </select>
        <select class="form-control form-control-sm" v-model="fh.estacion" @change="cargarHallazgos(1)">
          <option value="">Todas las estaciones</option><option v-for="s in estacionesOrden" :key="s.id" :value="s.id">{{ s.nombre }}</option>
        </select>
        <select class="form-control form-control-sm" v-model="fh.anio" @change="cargarHallazgos(1)">
          <option value="">Todos los años</option><option v-for="a in aniosCob.slice().reverse()" :key="a" :value="a">{{ a }}</option>
        </select>
      </div>

      <div v-if="resumenVars.length" class="bd-resumen">
        <span v-for="r in resumenVars" :key="r.variable" class="bd-chip" @click="fh.variable = r.variable; cargarHallazgos(1)">
          {{ nombreVar(r.variable) }}: <b>{{ n(r.total) }}</b>
          <small v-if="r.pendiente">· {{ n(r.pendiente) }} pend.</small>
        </span>
      </div>

      <div v-if="puedeSincronizar" class="bd-lote" :class="{ 'is-on': marcados.length }">
        <b>{{ marcados.length }} seleccionado(s)</b>
        <button type="button" class="btn btn-sm btn-outline-danger" :disabled="!marcados.length || accionando" @click="accion('excluir')"><i class="fas fa-ban"></i> Excluir</button>
        <button type="button" class="btn btn-sm btn-outline-success" :disabled="!marcados.length || accionando" @click="accion('validar')"><i class="fas fa-check"></i> Validar (conservar)</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="!marcados.length || accionando" @click="accion('pendiente')"><i class="fas fa-undo"></i> Volver a pendiente</button>
        <input class="form-control form-control-sm bd-nota" v-model="nota" maxlength="255" placeholder="Nota (opcional)">
      </div>

      <div class="bd-tabla-wrap">
        <table class="table bd-tabla">
          <thead>
            <tr>
              <th v-if="puedeSincronizar" class="bd-chk"><input type="checkbox" :checked="todosMarcados" @change="marcarTodos($event.target.checked)"></th>
              <th>Fecha</th><th>Estacion</th><th>Variable</th><th class="text-right">Valor</th><th>Nivel</th><th>Motivo</th><th>Estado</th><th>Revisado</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="cargandoH"><td colspan="9" class="text-center bd-muted"><i class="fas fa-spinner fa-spin"></i> Cargando...</td></tr>
            <template v-else>
              <tr v-for="h in hallazgos" :key="h.id" :class="{ 'is-marcado': marcados.includes(h.id) }">
                <td v-if="puedeSincronizar" class="bd-chk"><input type="checkbox" :value="h.id" v-model="marcados"></td>
                <td class="bd-nowrap">{{ fmt(h.fecha) }}</td>
                <td>{{ h.estacion }} <small class="bd-muted">{{ h.codigo }}</small></td>
                <td>{{ nombreVar(h.variable) }}</td>
                <td class="text-right bd-valor">{{ Number(h.valor).toLocaleString('es-GT', { maximumFractionDigits: 2 }) }} <small>{{ unidadVar(h.variable) }}</small></td>
                <td><span :class="h.nivel === 'error' ? 'bd-err' : 'bd-atip'"><i :class="h.nivel === 'error' ? 'fas fa-times-circle' : 'fas fa-exclamation-triangle'"></i> {{ h.nivel === 'error' ? 'Error' : 'Atipico' }}</span></td>
                <td class="bd-motivo">{{ h.detalle }}</td>
                <td><span class="bd-estado" :class="'is-' + h.estado">{{ { pendiente: 'Pendiente', excluido: 'Excluido', validado: 'Validado' }[h.estado] }}</span><small v-if="h.automatico" class="bd-muted"> auto</small></td>
                <td class="bd-muted bd-nowrap"><template v-if="h.revisor">{{ h.revisor }} · {{ fmt(h.revisado_en) }}</template><small v-if="h.nota" class="d-block" :title="h.nota">{{ h.nota }}</small></td>
              </tr>
              <tr v-if="!hallazgos.length"><td colspan="9" class="text-center bd-muted">Sin hallazgos con estos filtros.</td></tr>
            </template>
          </tbody>
        </table>
      </div>
      <div v-if="paginacion.last_page > 1" class="bd-pag">
        <button type="button" class="btn btn-sm btn-secondary" :disabled="paginacion.current_page <= 1" @click="cargarHallazgos(paginacion.current_page - 1)"><i class="fas fa-chevron-left"></i></button>
        <span>Pagina {{ paginacion.current_page }} de {{ paginacion.last_page }} · {{ n(paginacion.total) }} hallazgos</span>
        <button type="button" class="btn btn-sm btn-secondary" :disabled="paginacion.current_page >= paginacion.last_page" @click="cargarHallazgos(paginacion.current_page + 1)"><i class="fas fa-chevron-right"></i></button>
      </div>
    </section>
  </div>
</template>

<script>
import ClimaHistorial from './historial.vue';

const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const VARS = [
  { id: 'lluvia', nombre: 'Lluvia', unidad: 'mm' }, { id: 'tmax', nombre: 'T max', unidad: '°C' }, { id: 'tmin', nombre: 'T min', unidad: '°C' },
  { id: 'tmed', nombre: 'T media', unidad: '°C' }, { id: 'hr', nombre: 'HR media', unidad: '%' }, { id: 'hr_min', nombre: 'HR min', unidad: '%' },
  { id: 'hr_max', nombre: 'HR max', unidad: '%' }, { id: 'viento', nombre: 'Viento', unidad: 'km/h' }, { id: 'radiacion', nombre: 'Radiacion', unidad: 'MJ/m²' },
  { id: 'eto', nombre: 'ETo', unidad: 'mm' },
];
// Escala ordinal (un solo tono, de claro a oscuro) para el % de cobertura; el numero siempre se muestra.
const ESCALA = [
  { min: 100, color: '#2f6b0f', tinta: '#fff', label: '100 %' },
  { min: 90, color: '#4f9a1d', tinta: '#fff', label: '90–99' },
  { min: 50, color: '#86c04a', tinta: '#1e2a1a', label: '50–89' },
  { min: 1, color: '#c9e6a6', tinta: '#1e2a1a', label: '1–49' },
  { min: 0, color: '#f3f5f1', tinta: '#8a8f87', label: 'Sin datos' },
];

export default {
  name: 'ClimaBaseDatos',
  components: { ClimaHistorial },
  props: {
    configurada: { type: Boolean, default: false },
    puedeSincronizar: { type: Boolean, default: false },
    puedeGestionar: { type: Boolean, default: false },
  },
  data() {
    const hoy = new Date();
    const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    return {
      MESES,
      VARS,
      escala: ESCALA,
      hoy: iso(hoy),
      seccion: 'estado',
      secciones: [
        { id: 'estado', label: 'Estado y cobertura', icon: 'fas fa-database' },
        { id: 'sync', label: 'Sincronizacion', icon: 'fas fa-sync-alt' },
        { id: 'calidad', label: 'Control de calidad', icon: 'fas fa-clipboard-check' },
      ],
      est: null,
      buscarCob: '',
      soloConDatos: true,
      celda: null,
      diasActualizar: 7,
      ocupado: null,
      rd: { desde: '', hasta: '' },
      bitacora: [],
      rangos: [],
      rangosOrig: '',
      z: 3,
      zOrig: 3,
      guardandoRangos: false,
      revisando: null,
      progresoRev: null,
      fh: { estado: 'pendiente', nivel: '', variable: '', estacion: '', anio: '' },
      hallazgos: [],
      resumen: [],
      paginacion: {},
      cargandoH: false,
      marcados: [],
      nota: '',
      accionando: false,
    };
  },
  computed: {
    aniosCob() {
      const y = new Date().getFullYear();
      const desde = this.est && this.est.desde ? Math.min(2009, Number(this.est.desde.slice(0, 4))) : 2009;
      return Array.from({ length: y - desde + 1 }, (_, i) => desde + i);
    },
    aniosBase() { return this.est && this.est.desde ? Number(this.est.hasta.slice(0, 4)) - Number(this.est.desde.slice(0, 4)) + 1 : 0; },
    estacionesOrden() { return this.est ? this.est.estaciones.slice().sort((a, b) => a.nombre.localeCompare(b.nombre)) : []; },
    estacionesCob() {
      const q = this.buscarCob.trim().toLowerCase();
      return this.estacionesOrden.filter((s) => (!this.soloConDatos || this.totalEst(s) > 0) && (!q || `${s.nombre} ${s.codigo}`.toLowerCase().includes(q)));
    },
    rangosSucios() { return JSON.stringify(this.rangos) !== this.rangosOrig || this.z !== this.zOrig; },
    resumenVars() {
      const m = {};
      this.resumen.forEach((r) => {
        const x = (m[r.variable] = m[r.variable] || { variable: r.variable, total: 0, pendiente: 0 });
        x.total += r.n;
        if (r.estado === 'pendiente') x.pendiente += r.n;
      });
      return Object.values(m).sort((a, b) => b.total - a.total);
    },
    todosMarcados() { return this.hallazgos.length > 0 && this.hallazgos.every((h) => this.marcados.includes(h.id)); },
  },
  watch: {
    seccion(s) {
      if (s === 'sync' && !this.bitacora.length) this.cargarBitacora();
      if (s === 'calidad' && !this.rangos.length) { this.cargarRangos(); this.cargarHallazgos(1); }
    },
  },
  mounted() {
    this.cargarEstado();
  },
  methods: {
    recargar() { this.cargarEstado(); this.cargarBitacora(); this.$emit('actualizado'); },
    cargarEstado() { return window.axios.get('/investigacion/clima/base/estado').then(({ data }) => { this.est = data; }); },
    cargarBitacora() { return window.axios.get('/investigacion/clima/base/bitacora').then(({ data }) => { this.bitacora = data; }); },
    cargarRangos() {
      return window.axios.get('/investigacion/clima/calidad/rangos').then(({ data }) => {
        this.rangos = data.rangos.map((r) => ({
          variable: r.variable, estadistico: !!r.estadistico,
          valido_min: this.numTxt(r.valido_min), valido_max: this.numTxt(r.valido_max), alerta_min: this.numTxt(r.alerta_min), alerta_max: this.numTxt(r.alerta_max),
        }));
        this.rangosOrig = JSON.stringify(this.rangos);
        this.z = Number(data.z);
        this.zOrig = this.z;
      });
    },
    numTxt(v) { return v === null || v === undefined ? '' : String(Number(v)); },
    guardarRangos() {
      this.guardandoRangos = true;
      const val = (v) => (v === '' || v === null ? null : Number(v));
      window.axios.put('/investigacion/clima/calidad/rangos', {
        z: this.z,
        rangos: this.rangos.map((r) => ({ variable: r.variable, estadistico: r.estadistico, valido_min: val(r.valido_min), valido_max: val(r.valido_max), alerta_min: val(r.alerta_min), alerta_max: val(r.alerta_max) })),
      })
        .then(() => {
          this.rangosOrig = JSON.stringify(this.rangos);
          this.zOrig = this.z;
          this.$swal.fire({ icon: 'success', title: 'Rangos guardados', text: 'Pulse "Revisar toda la base" para aplicarlos a los datos ya guardados.' });
        })
        .catch(this.falla)
        .finally(() => { this.guardandoRangos = false; });
    },
    async revisarTodo() {
      if (!this.est || !this.est.desde) return;
      const ini = Number(this.est.desde.slice(0, 4));
      const fin = Number(this.est.hasta.slice(0, 4));
      const tot = { errores: 0, atipicos: 0, nuevos: 0, excluidos: 0, restaurados: 0, resueltos: 0 };
      for (let a = ini; a <= fin; a++) {
        this.revisando = a;
        this.progresoRev = { pct: Math.round(((a - ini) / (fin - ini + 1)) * 100), texto: `Revisando ${a} (${a - ini + 1} de ${fin - ini + 1})...` };
        try {
          // eslint-disable-next-line no-await-in-loop
          const { data } = await window.axios.post('/investigacion/clima/calidad/revisar', { anio: a });
          Object.keys(tot).forEach((k) => { tot[k] += data[k] || 0; });
        } catch (e) {
          this.falla(e);
          break;
        }
      }
      this.revisando = null;
      this.progresoRev = { pct: 100, texto: `Listo: ${this.n(tot.errores)} errores y ${this.n(tot.atipicos)} atipicos en la base · ${this.n(tot.nuevos)} hallazgos nuevos, ${this.n(tot.excluidos)} excluidos, ${this.n(tot.restaurados)} restaurados (ya no estan fuera de rango), ${this.n(tot.resueltos)} resueltos.` };
      this.cargarHallazgos(1);
      this.cargarEstado();
      this.$emit('actualizado');
    },
    cargarHallazgos(pagina) {
      this.cargandoH = true;
      this.marcados = [];
      const params = { page: pagina };
      Object.keys(this.fh).forEach((k) => { if (this.fh[k] !== '') params[k] = this.fh[k]; });
      return window.axios.get('/investigacion/clima/calidad/hallazgos', { params })
        .then(({ data }) => {
          this.hallazgos = data.pagina.data;
          this.paginacion = { current_page: data.pagina.current_page, last_page: data.pagina.last_page, total: data.pagina.total };
          this.resumen = data.resumen;
        })
        .finally(() => { this.cargandoH = false; });
    },
    marcarTodos(si) { this.marcados = si ? this.hallazgos.map((h) => h.id) : []; },
    accion(accion) {
      this.accionando = true;
      window.axios.post('/investigacion/clima/calidad/accion', { ids: this.marcados, accion, nota: this.nota || null })
        .then(({ data }) => {
          this.$swal.fire({ toast: true, position: 'top-end', icon: 'success', title: `${data.n} valor(es) ${{ excluir: 'excluido(s)', validar: 'validado(s)', pendiente: 'pendiente(s)' }[accion]}`, timer: 2200, showConfirmButton: false });
          this.nota = '';
          this.cargarHallazgos(this.paginacion.current_page || 1);
          this.cargarEstado();
          this.$emit('actualizado');
        })
        .catch(this.falla)
        .finally(() => { this.accionando = false; });
    },
    irCalidad(estado) { this.fh.estado = estado; this.seccion = 'calidad'; this.cargarHallazgos(1); },
    sincronizar(desde, hasta, tipo, forzar) {
      this.ocupado = tipo;
      return window.axios.post('/investigacion/clima/icc/sincronizar', { desde, hasta, tipo, forzar: forzar ? 1 : 0 })
        .then(({ data }) => {
          this.$swal.fire({ icon: 'success', title: 'Sincronizacion terminada', text: `${this.n(data.registros || 0)} registros de ${this.n(data.dias || 0)} dias${data.excluidos ? ` · ${this.n(data.excluidos)} valores excluidos por control de calidad` : ''}.` });
          this.recargar();
        })
        .catch(this.falla)
        .finally(() => { this.ocupado = null; });
    },
    actualizar() {
      const h = new Date();
      const d = new Date(h.getTime() - (this.diasActualizar - 1) * 86400000);
      const iso = (x) => `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`;
      this.sincronizar(iso(d), this.hoy, 'actualizar', true);
    },
    redescargar() {
      this.$swal.fire({
        title: 'Volver a descargar', icon: 'question', showCancelButton: true, confirmButtonText: 'Descargar', cancelButtonText: 'Cancelar',
        text: `Se pediran a la API todos los dias del ${this.fmt(this.rd.desde)} al ${this.fmt(this.rd.hasta)} (todas las estaciones) y se reemplazaran los valores guardados.`,
        confirmButtonColor: '#73BC25', cancelButtonColor: '#CED2D5',
      }).then((r) => { if (r.isConfirmed) this.sincronizar(this.rd.desde, this.rd.hasta, 'redescarga', true); });
    },
    detalleCelda(s, a) {
      if (!s.anios[a]) { this.celda = null; return; }
      window.axios.get('/investigacion/clima/base/cobertura-mes', { params: { estacion: s.id, anio: a } })
        .then(({ data }) => { this.celda = { estacion: s.nombre, anio: a, meses: data }; });
    },
    diasAnio(a) {
      const h = new Date();
      if (a === h.getFullYear()) return Math.floor((h - new Date(a, 0, 1)) / 86400000) + 1;
      return (a % 4 === 0 && a % 100 !== 0) || a % 400 === 0 ? 366 : 365;
    },
    pct(s, a) { return s.anios[a] ? Math.min(100, Math.round((s.anios[a] / this.diasAnio(a)) * 100)) : 0; },
    totalEst(s) { return Object.values(s.anios || {}).reduce((x, y) => x + y, 0); },
    celdaEstilo(p) {
      const e = ESCALA.find((x) => p >= x.min) || ESCALA[ESCALA.length - 1];
      return { background: e.color, color: e.tinta };
    },
    nombreVar(v) { return (VARS.find((x) => x.id === v) || {}).nombre || v; },
    unidadVar(v) { return (VARS.find((x) => x.id === v) || {}).unidad || ''; },
    tipoTxt(t) { return { actualizar: 'Actualizacion', historial: 'Historial', redescarga: 'Re-descarga', automatica: 'Automatica', programada: 'Programada', consulta: 'Desde un ensayo' }[t] || t; },
    haceCuanto(f) {
      const min = Math.round((Date.now() - new Date(f.replace(' ', 'T'))) / 60000);
      if (min < 60) return `hace ${Math.max(1, min)} min`;
      if (min < 1440) return `hace ${Math.round(min / 60)} h`;
      return `hace ${Math.round(min / 1440)} dias`;
    },
    fechaHora(f) { const d = new Date(f.replace(' ', 'T')); return `${this.fmt(f)} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`; },
    n(x) { return Number(x || 0).toLocaleString('es-GT'); },
    fmt(s) { if (!s) return '—'; const [y, m, d] = String(s).slice(0, 10).split('-'); return `${d}/${m}/${y}`; },
    falla(e) {
      const r = e && e.response;
      this.$swal.fire({ icon: 'error', title: 'Error', text: (r && r.data && (r.data.message || (r.data.errors && Object.values(r.data.errors).flat()[0]))) || 'No se pudo completar la operacion.' });
    },
  },
};
</script>

<style scoped>
.bd-sub { display: inline-flex; flex-wrap: wrap; gap: 4px; margin-bottom: 12px; padding: 3px; border: 1px solid #e3e7e1; border-radius: 9px; background: #f3f5f1; }
.bd-sub button { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border: 0; border-radius: 7px; background: transparent; color: #5b6658; font-size: 12.5px; font-weight: 600; }
.bd-sub button.is-on { background: #fff; color: #1f5632; box-shadow: 0 1px 3px rgba(30, 42, 26, .12); }
.bd-sub button i { color: #73BC25; }
.bd-badge { min-width: 18px; padding: 0 6px; border-radius: 999px; background: #FF6B00; color: #fff; font-size: 11px; line-height: 18px; }
.bd-vacio { padding: 30px; color: #6b7568; text-align: center; }
.bd-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; margin-bottom: 14px; }
.bd-card { display: flex; flex-direction: column; gap: 2px; padding: 10px 14px; border: 1px solid #e3e7e1; border-left: 4px solid #73BC25; border-radius: 10px; background: #fff; }
.bd-card.is-warn { border-left-color: #FF6B00; }
.bd-card span { color: #6b7568; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
.bd-card strong { color: #1e2a1a; font-size: 18px; font-variant-numeric: tabular-nums; }
.bd-card strong small { font-size: 12px; font-weight: 500; color: #6b7568; }
.bd-card > small { color: #6b7568; font-size: 11.5px; }
.bd-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.bd-head h6 { margin: 0; color: #1f5632; font-size: 14px; font-weight: 700; }
.bd-head h6 i { margin-right: 6px; color: #73BC25; }
.bd-leyenda { display: flex; flex-wrap: wrap; gap: 10px; color: #6b7568; font-size: 11.5px; }
.bd-leyenda i { display: inline-block; width: 14px; height: 10px; margin-right: 4px; border-radius: 2px; vertical-align: middle; border: 1px solid #e3e7e1; }
.bd-filtro { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 8px; font-size: 12px; }
.bd-filtro .form-control { max-width: 260px; }
.bd-matriz-wrap { max-height: 560px; overflow: auto; border: 1px solid #e3e7e1; border-radius: 10px; background: #fff; }
.bd-matriz { border-collapse: separate; border-spacing: 2px; font-size: 11px; }
.bd-matriz th { position: sticky; top: 0; z-index: 1; padding: 4px 3px; background: #fff; color: #6b7568; font-weight: 700; text-align: center; }
.bd-matriz th.bd-est { left: 0; z-index: 2; min-width: 170px; max-width: 200px; overflow: hidden; padding-right: 8px; color: #26331f; font-weight: 600; text-align: left; text-overflow: ellipsis; white-space: nowrap; }
.bd-matriz tbody th.bd-est { position: sticky; z-index: 1; }
.bd-matriz td { min-width: 30px; height: 22px; border-radius: 3px; font-variant-numeric: tabular-nums; text-align: center; cursor: pointer; }
.bd-matriz td.bd-total { background: transparent; color: #33402a; font-weight: 600; cursor: default; padding: 0 6px; }
.bd-detalle { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; margin-top: 8px; padding: 8px 10px; border-radius: 8px; background: #f7fbf1; font-size: 12px; }
.bd-mes { padding: 1px 6px; border-radius: 4px; font-size: 11px; }
.bd-aviso { margin-bottom: 10px; padding: 8px 12px; border-radius: 8px; background: #fffbea; color: #7a5d00; font-size: 12.5px; }
.bd-acciones { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 12px; }
.bd-accion { padding: 12px 14px; border: 1px solid #e3e7e1; border-radius: 12px; background: #fff; }
.bd-accion h6 { margin: 0 0 4px; color: #1f5632; font-size: 13.5px; font-weight: 700; }
.bd-accion h6 i { margin-right: 6px; color: #73BC25; }
.bd-accion p { margin-bottom: 8px; color: #6b7568; font-size: 12px; }
.bd-fila { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.bd-fila input[type=date] { width: 150px; }
.bd-tabla-wrap { max-height: 520px; overflow: auto; border: 1px solid #edf1ec; border-radius: 10px; background: #fff; }
.bd-tabla { margin: 0; font-size: 12.5px; }
.bd-tabla th { position: sticky; top: 0; z-index: 1; background: #f7f9f5; color: #6b7568; font-size: 11px; white-space: nowrap; }
.bd-tabla td { vertical-align: middle; }
.bd-tabla tr.is-marcado td { background: #f1f8e9; }
.bd-rangos .bd-sub-h th { top: 30px; }
.bd-num { width: 90px; }
.bd-nowrap { white-space: nowrap; }
.bd-muted { color: #6b7568; }
.bd-ok { color: #3f7a12; }
.bd-err { color: #b8322f; font-weight: 600; }
.bd-atip { color: #8a5a00; font-weight: 600; }
.bd-ayuda { margin-bottom: 8px; color: #33402a; font-size: 12px; line-height: 1.6; }
.bd-prog { margin-top: 8px; }
.bd-barra { height: 8px; overflow: hidden; border-radius: 999px; background: #edf1ec; }
.bd-barra i { display: block; height: 100%; background: #73BC25; transition: width .3s; }
.bd-prog small { color: #33402a; font-size: 12px; }
.bd-filtros { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.bd-filtros .form-control { width: auto; }
.bd-resumen { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.bd-chip { padding: 2px 9px; border: 1px solid #e3e7e1; border-radius: 999px; background: #fff; font-size: 11.5px; cursor: pointer; }
.bd-chip:hover { border-color: #73BC25; }
.bd-lote { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-bottom: 8px; padding: 6px 10px; border: 1px dashed #d8ddd5; border-radius: 9px; font-size: 12.5px; }
.bd-lote.is-on { border-style: solid; border-color: #73BC25; background: #f7fbf1; }
.bd-nota { flex: 1 1 200px; max-width: 320px; }
.bd-chk { width: 32px; }
.bd-valor { font-variant-numeric: tabular-nums; font-weight: 600; }
.bd-motivo { max-width: 320px; color: #33402a; font-size: 12px; }
.bd-estado { padding: 1px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.bd-estado.is-pendiente { background: #fff1d6; color: #8a5a00; }
.bd-estado.is-excluido { background: #fde3e2; color: #a12b29; }
.bd-estado.is-validado { background: #e3f3d3; color: #2f6b0f; }
.bd-pag { display: flex; align-items: center; justify-content: center; gap: 10px; margin-top: 8px; font-size: 12.5px; }
</style>
