<template>
  <div class="ce">
    <div class="ce-bar">
      <div class="ce-cifras">
        <span><b>{{ estaciones.length }}</b> estaciones</span>
        <span class="is-ok"><i class="fas fa-map-marker-alt"></i> {{ conCoord.length }} con ubicacion</span>
        <span v-if="sinCoord.length" class="is-falta"><i class="fas fa-exclamation-circle"></i> {{ sinCoord.length }} sin ubicacion</span>
      </div>
      <div class="ce-acc">
        <div class="dropdown d-inline-block">
          <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-toggle="dropdown">
            <i class="fas fa-file-excel"></i> Plantilla de importacion
          </button>
          <div class="dropdown-menu dropdown-menu-right">
            <a class="dropdown-item" :href="base + '/investigacion/clima/estaciones-plantilla'">
              <i class="fas fa-list"></i> Con las estaciones actuales <small class="d-block text-muted">Complete estrato, zona y coordenadas y vuelva a importarla</small>
            </a>
            <a class="dropdown-item" :href="base + '/investigacion/clima/estaciones-plantilla?vacia=1'">
              <i class="far fa-file"></i> Vacia <small class="d-block text-muted">Solo encabezados, listas desplegables e instrucciones</small>
            </a>
            <div class="dropdown-divider"></div>
            <a class="dropdown-item" href="#" @click.prevent="descargar"><i class="fas fa-file-csv"></i> Listado actual en CSV</a>
          </div>
        </div>
        <template v-if="puedeGestionar">
          <label class="btn btn-success btn-sm mb-0" :class="{ disabled: importando }">
            <i :class="importando ? 'fas fa-spinner fa-spin' : 'fas fa-file-upload'"></i> Importar listado
            <input type="file" accept=".xlsx,.xls,.csv,.txt" hidden :disabled="importando" @change="importar">
          </label>
          <button type="button" class="btn btn-success btn-sm" @click="nueva"><i class="fas fa-plus"></i> Nueva estacion</button>
        </template>
      </div>
    </div>

    <p v-if="!puedeGestionar" class="ce-permiso">
      <i class="fas fa-lock"></i> Solo los administradores pueden agregar o editar estaciones. Puede consultar el listado y el mapa.
    </p>

    <!-- Alta de una estacion -->
    <form v-if="form" class="ce-form" @submit.prevent="crear" novalidate>
      <div class="ce-form-tit"><i class="fas fa-broadcast-tower"></i> Nueva estacion <small>· puede tocar el mapa para tomar la latitud y longitud</small></div>
      <div class="form-row">
        <div class="col-6 col-md-2 form-group"><label>Codigo *</label><input class="form-control form-control-sm" maxlength="30" v-model="form.codigo" placeholder="CEN-CEN"></div>
        <div class="col-6 col-md-2 form-group"><label>Estacionid (API ICC)</label><input class="form-control form-control-sm" maxlength="60" v-model="form.codigo_api" placeholder="1"></div>
        <div class="col-md-4 form-group"><label>Nombre *</label><input class="form-control form-control-sm" maxlength="150" v-model="form.nombre" placeholder="Cengicana"></div>
        <div class="col-md-4 form-group"><label>Red</label><select class="form-control form-control-sm" v-model="form.red"><option v-for="r in redes" :key="r" :value="r">{{ r }}</option></select></div>
      </div>
      <div class="form-row">
        <div class="col-6 col-md-2 form-group"><label>Latitud *</label><input class="form-control form-control-sm" v-model="form.lat" placeholder="14.2909"></div>
        <div class="col-6 col-md-2 form-group"><label>Longitud *</label><input class="form-control form-control-sm" v-model="form.lng" placeholder="-91.0550"></div>
        <div class="col-6 col-md-2 form-group"><label>Altitud (msnm)</label><input type="number" class="form-control form-control-sm" v-model="form.altitud"></div>
        <div class="col-6 col-md-2 form-group"><label>Estrato</label><select class="form-control form-control-sm" v-model="form.estrato"><option value="">—</option><option v-for="x in estratos" :key="x" :value="x">{{ x }}</option></select></div>
        <div class="col-6 col-md-2 form-group"><label>Zona</label><select class="form-control form-control-sm" v-model="form.zona"><option value="">—</option><option v-for="x in zonas" :key="x" :value="x">{{ x }}</option></select></div>
        <div class="col-6 col-md-3 form-group"><label>Ubicacion</label><input class="form-control form-control-sm" maxlength="150" v-model="form.ubicacion" placeholder="Municipio, finca..."></div>
        <div class="col-md-3 form-group d-flex align-items-end" style="gap:6px">
          <button type="button" class="btn btn-secondary btn-sm" @click="form = null">Cancelar</button>
          <button type="submit" class="btn btn-success btn-sm" :disabled="creando"><i :class="creando ? 'fas fa-spinner fa-spin' : 'fas fa-save'"></i> Guardar</button>
        </div>
      </div>
      <p v-if="errorForm" class="ce-error mb-0"><i class="fas fa-exclamation-circle"></i> {{ errorForm }}</p>
    </form>

    <p class="ce-ayuda">
      Con la ubicacion de cada estacion, cada ensayo elige automaticamente la <b>estacion mas cercana</b> en su pestaña Clima.
      Para cargar el listado: descargue la <b>Plantilla de importacion</b> (Excel, con listas desplegables e instrucciones), complete <i>Latitud</i>, <i>Longitud</i>, <i>Estrato</i> y <i>Zona</i> (grados decimales, p. ej. 14.2909 / -91.0550,
      o grados-minutos-segundos) y use <b>Importar listado</b>. Columnas: Codigo, Estacionid (id en la API del ICC), Nombre, Latitud, Longitud, Altitud, Estrato, Zona, Ubicacion, Red, Activa.
    </p>

    <div v-if="resultado" class="ce-res" :class="{ 'is-warn': resultado.omitidas.length || resultado.corregidas.length }">
      <i class="fas fa-check-circle"></i>
      {{ resultado.actualizadas }} estacion(es) actualizada(s), {{ resultado.creadas }} nueva(s).
      <div v-for="c in resultado.corregidas" :key="'c' + c.fila"><i class="fas fa-wrench"></i> Fila {{ c.fila }} ({{ c.codigo }}): {{ c.motivo }}</div>
      <div v-for="o in resultado.omitidas" :key="'o' + o.fila"><i class="fas fa-times-circle"></i> Fila {{ o.fila }} omitida: {{ o.motivo }}</div>
    </div>

    <div class="ce-vistas">
      <button type="button" :class="{ 'is-on': vistaCat === 'categorias' }" @click="vistaCat = 'categorias'"><i class="fas fa-tags"></i> Categorias (estrato y zona)</button>
      <button type="button" :class="{ 'is-on': vistaCat === 'mapa' }" @click="mostrarMapa"><i class="fas fa-map-marked-alt"></i> Mapa y ubicaciones</button>
    </div>

    <!-- ============ Tabla de categorias ============ -->
    <div v-if="vistaCat === 'categorias'" class="cc">
      <!-- Resumen estrato x zona -->
      <div class="cc-matriz">
        <table class="table cc-tm">
          <thead><tr><th>Estrato \ Zona</th><th v-for="z in zonasCol" :key="z">{{ z }}</th><th>Total</th></tr></thead>
          <tbody>
            <tr v-for="e in estratosFila" :key="e">
              <th><span class="ce-estrato" :style="{ '--c': colorEstrato(e) }">{{ e }}</span></th>
              <td v-for="z in zonasCol" :key="z" :class="{ 'is-cero': !cruce(e, z) }">
                <a v-if="cruce(e, z)" href="#" @click.prevent="filtrarCelda(e, z)">{{ cruce(e, z) }}</a><template v-else>·</template>
              </td>
              <td><b>{{ cruce(e, null) }}</b></td>
            </tr>
          </tbody>
        </table>
        <p class="cc-ayuda">
          El <b>estrato</b> y la <b>zona</b> de cada estacion agrupan los graficos del analisis comparativo.
          Toque un numero del cuadro para ver esas estaciones. Los cambios se marcan en naranja hasta pulsar <b>Guardar cambios</b>.
        </p>
      </div>

      <div class="cc-bar">
        <input class="form-control form-control-sm cc-buscar" v-model="buscar" placeholder="Buscar codigo, nombre o ubicacion...">
        <select class="form-control form-control-sm cc-filtro" v-model="filtroEstrato">
          <option value="">Todos los estratos</option>
          <option v-for="e in estratosFila" :key="e" :value="e">{{ e }}</option>
        </select>
        <select class="form-control form-control-sm cc-filtro" v-model="filtroZona">
          <option value="">Todas las zonas</option>
          <option v-for="z in zonasCol" :key="z" :value="z">{{ z }}</option>
        </select>
        <button v-if="filtroEstrato || filtroZona || buscar" type="button" class="btn btn-link btn-sm" @click="filtroEstrato = ''; filtroZona = ''; buscar = ''">Quitar filtros</button>
        <span class="cc-cuenta">{{ categorizables.length }} estacion(es)</span>
      </div>

      <div v-if="puedeGestionar" class="cc-lote" :class="{ 'is-activo': marcadas.length }">
        <b>{{ marcadas.length }} seleccionada(s)</b>
        <span>Asignar:</span>
        <select class="form-control form-control-sm cc-filtro" v-model="lote.estrato">
          <option value="">Estrato (sin cambio)</option>
          <option v-for="e in estratos" :key="e" :value="e">{{ e }}</option>
          <option value="-">(quitar estrato)</option>
        </select>
        <select class="form-control form-control-sm cc-filtro" v-model="lote.zona">
          <option value="">Zona (sin cambio)</option>
          <option v-for="z in zonas" :key="z" :value="z">{{ z }}</option>
          <option value="-">(quitar zona)</option>
        </select>
        <button type="button" class="btn btn-secondary btn-sm" :disabled="!marcadas.length || (!lote.estrato && !lote.zona)" @click="aplicarLote">Aplicar</button>
        <button type="button" class="btn btn-link btn-sm" @click="verUmbrales = !verUmbrales"><i class="fas fa-magic"></i> Sugerir estrato por altitud</button>
        <button v-if="sucias.length" type="button" class="btn btn-success btn-sm ml-auto" :disabled="guardandoTodos" @click="guardarTodos">
          <i :class="guardandoTodos ? 'fas fa-spinner fa-spin' : 'fas fa-save'"></i> Guardar cambios ({{ sucias.length }})
        </button>
      </div>
      <div v-if="verUmbrales && puedeGestionar" class="ce-umbrales">
        Criterio (msnm, editable): LITORAL &lt; <input type="number" v-model.number="umbrales.litoral"> ≤ BAJO &lt; <input type="number" v-model.number="umbrales.bajo"> ≤ MEDIO &lt; <input type="number" v-model.number="umbrales.medio"> ≤ ALTO
        <button type="button" class="btn btn-success btn-sm" @click="sugerirEstratos">Aplicar a estaciones sin estrato</button>
      </div>

      <div class="cc-tabla-wrap">
        <table class="table cc-tabla">
          <thead>
            <tr>
              <th v-if="puedeGestionar" class="cc-chk"><input type="checkbox" :checked="todasMarcadas" @change="marcarTodas($event.target.checked)" title="Seleccionar las visibles"></th>
              <th>Codigo</th><th>Estacion</th><th>Id API</th><th>Latitud</th><th>Longitud</th><th class="text-right">Altitud</th><th>Ubicacion</th><th>Estrato</th><th>Zona</th><th class="text-right">Dias con datos</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in categorizables" :key="s.id" :class="{ 'is-sucia': sucio(s), 'is-marcada': marcadas.includes(s.id), 'is-off': !s.activa }">
              <td v-if="puedeGestionar" class="cc-chk"><input type="checkbox" :value="s.id" v-model="marcadas"></td>
              <td class="cc-cod">{{ s.codigo }}</td>
              <td><b>{{ s.nombre }}</b><small v-if="!s.activa"> · inactiva</small></td>
              <td class="cc-muted">{{ s.codigo_api || '—' }}</td>
              <td>
                <input v-if="puedeGestionar" type="number" step="0.000001" class="form-control form-control-sm cc-coord" v-model="edicion[s.id].lat"
                       :class="{ 'is-falta': edicion[s.id].lat === '' }" placeholder="14.2909" title="Grados decimales (norte positivo)">
                <template v-else>{{ s.lat !== null ? Number(s.lat).toFixed(5) : '—' }}</template>
              </td>
              <td>
                <input v-if="puedeGestionar" type="number" step="0.000001" class="form-control form-control-sm cc-coord" v-model="edicion[s.id].lng"
                       :class="{ 'is-falta': edicion[s.id].lng === '' }" placeholder="-91.0550" title="Grados decimales (oeste negativo)" @change="corregirSigno(s)">
                <template v-else>{{ s.lng !== null ? Number(s.lng).toFixed(5) : '—' }}</template>
              </td>
              <td class="text-right">
                <input v-if="puedeGestionar" type="number" class="form-control form-control-sm cc-alt" v-model="edicion[s.id].altitud">
                <template v-else>{{ s.altitud !== null ? s.altitud : '—' }}</template>
              </td>
              <td class="cc-muted">{{ s.ubicacion || '—' }}</td>
              <td>
                <select v-if="puedeGestionar" class="form-control form-control-sm ce-sel" v-model="edicion[s.id].estrato" :style="{ borderLeftColor: colorEstrato(edicion[s.id].estrato) }">
                  <option value="">—</option><option v-for="x in estratos" :key="x" :value="x">{{ x }}</option>
                </select>
                <span v-else-if="s.estrato" class="ce-estrato" :style="{ '--c': colorEstrato(s.estrato) }">{{ s.estrato }}</span>
                <template v-else>—</template>
              </td>
              <td>
                <select v-if="puedeGestionar" class="form-control form-control-sm ce-sel" v-model="edicion[s.id].zona">
                  <option value="">—</option><option v-for="x in zonas" :key="x" :value="x">{{ x }}</option>
                </select>
                <template v-else>{{ s.zona || '—' }}</template>
              </td>
              <td class="text-right cc-muted">{{ s.registros_count || 0 }}</td>
            </tr>
            <tr v-if="!categorizables.length"><td :colspan="puedeGestionar ? 11 : 10" class="text-center cc-muted">Ninguna estacion coincide.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="ce-grid" v-show="vistaCat === 'mapa'">
      <div class="ce-mapa-wrap">
        <div class="ce-mapa" ref="mapa"></div>
        <div v-if="ubicando" class="ce-ubicando">
          <i class="fas fa-crosshairs"></i> Toque en el mapa la ubicacion de <b>{{ ubicando.nombre }}</b>
          <button type="button" class="btn btn-link btn-sm" @click="ubicando = null">Cancelar</button>
        </div>
      </div>

      <div class="ce-lista">
        <div class="ce-filtro">
          <input class="form-control form-control-sm" v-model="buscar" placeholder="Buscar estacion...">
          <label class="mb-0"><input type="checkbox" v-model="soloSin"> Solo sin ubicacion</label>
        </div>
        <div class="ce-estratos">
          <span v-for="x in estratos" :key="x" class="ce-estrato" :style="{ '--c': colorEstrato(x) }">{{ x }} <small>{{ estaciones.filter((s) => s.estrato === x).length }}</small></span>
          <span class="ce-estrato" style="--c:#8a8f87">Sin estrato <small>{{ estaciones.filter((s) => !s.estrato).length }}</small></span>
          <template v-if="puedeGestionar">
            <button type="button" class="btn btn-link btn-sm p-0" @click="verUmbrales = !verUmbrales"><i class="fas fa-magic"></i> Sugerir estrato por altitud</button>
            <button v-if="sucias.length" type="button" class="btn btn-success btn-sm" :disabled="guardandoTodos" @click="guardarTodos">
              <i :class="guardandoTodos ? 'fas fa-spinner fa-spin' : 'fas fa-save'"></i> Guardar cambios ({{ sucias.length }})
            </button>
          </template>
        </div>
        <div v-if="verUmbrales" class="ce-umbrales">
          Criterio (msnm, editable): LITORAL &lt; <input type="number" v-model.number="umbrales.litoral"> ≤ BAJO &lt; <input type="number" v-model.number="umbrales.bajo"> ≤ MEDIO &lt; <input type="number" v-model.number="umbrales.medio"> ≤ ALTO
          <button type="button" class="btn btn-success btn-sm" @click="sugerirEstratos">Aplicar a estaciones sin estrato</button>
        </div>
        <div class="ce-tabla-wrap">
          <table class="table ce-tabla">
            <thead>
              <tr><th>Estacion</th><th>Latitud</th><th>Longitud</th><th>Alt.</th><th>Estrato</th><th>Zona</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="s in filtradas" :key="s.id" :class="{ 'is-sel': seleccion === s.id, 'is-off': !s.activa }" @click="enfocar(s)">
                <td>
                  <b>{{ s.nombre }}</b>
                  <small>{{ s.codigo }}{{ s.codigo_api ? ` · API ${s.codigo_api}` : '' }} · {{ s.red }}{{ s.activa ? '' : ' · inactiva' }}</small>
                </td>
                <template v-if="puedeGestionar">
                  <td><input type="number" step="0.000001" class="form-control form-control-sm ce-num" v-model="edicion[s.id].lat" :class="{ 'is-sucio': sucio(s) }" @click.stop></td>
                  <td><input type="number" step="0.000001" class="form-control form-control-sm ce-num" v-model="edicion[s.id].lng" :class="{ 'is-sucio': sucio(s) }" @click.stop></td>
                  <td><input type="number" class="form-control form-control-sm ce-alt" v-model="edicion[s.id].altitud" @click.stop></td>
                  <td>
                    <select class="form-control form-control-sm ce-sel" v-model="edicion[s.id].estrato" :style="{ borderLeftColor: colorEstrato(edicion[s.id].estrato) }" @click.stop>
                      <option value="">—</option><option v-for="x in estratos" :key="x" :value="x">{{ x }}</option>
                    </select>
                  </td>
                  <td>
                    <select class="form-control form-control-sm ce-sel" v-model="edicion[s.id].zona" @click.stop>
                      <option value="">—</option><option v-for="x in zonas" :key="x" :value="x">{{ x }}</option>
                    </select>
                  </td>
                  <td class="ce-nowrap">
                    <button type="button" class="ce-icon" title="Ubicar tocando el mapa" @click.stop="ubicar(s)"><i class="fas fa-crosshairs"></i></button>
                    <button v-if="sucio(s)" type="button" class="ce-icon is-guardar" title="Guardar" :disabled="guardando === s.id" @click.stop="guardar(s)">
                      <i :class="guardando === s.id ? 'fas fa-spinner fa-spin' : 'fas fa-save'"></i>
                    </button>
                  </td>
                </template>
                <template v-else>
                  <td>{{ s.lat !== null ? Number(s.lat).toFixed(5) : '—' }}</td>
                  <td>{{ s.lng !== null ? Number(s.lng).toFixed(5) : '—' }}</td>
                  <td>{{ s.altitud !== null ? s.altitud : '—' }}</td>
                  <td><span v-if="s.estrato" class="ce-estrato" :style="{ '--c': colorEstrato(s.estrato) }">{{ s.estrato }}</span><template v-else>—</template></td>
                  <td>{{ s.zona || '—' }}</td>
                  <td></td>
                </template>
              </tr>
              <tr v-if="!filtradas.length"><td colspan="7" class="ce-muted text-center">Sin estaciones.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

// Mismos colores de estrato que el analisis comparativo.
const COLOR_ESTRATO = { ALTO: '#2a78d6', MEDIO: '#eb6834', BAJO: '#1baf7a', LITORAL: '#eda100' };

export default {
  name: 'ClimaEstaciones',
  props: {
    estaciones: { type: Array, default: () => [] },
    puedeGestionar: { type: Boolean, default: false },
  },
  data() {
    return {
      buscar: '', soloSin: false, edicion: {}, guardando: null, importando: false, resultado: null, seleccion: null, ubicando: null,
      form: null, creando: false, errorForm: null, redes: ['ICC REDMET', 'INSIVUMEH', 'Ingenio', 'Otra'],
      base: ((document.querySelector('meta[name="app-base"]') || {}).content || '').replace(/\/+$/, ''),
      estratos: ['ALTO', 'MEDIO', 'BAJO', 'LITORAL'],
      zonas: ['OESTE', 'CENTRO-OESTE', 'CENTRO', 'CENTRO-ESTE', 'ESTE', 'ESTE-ESTE'],
      // Limites superiores (msnm) para sugerir estrato: LITORAL < 40 <= BAJO < 100 <= MEDIO < 300 <= ALTO.
      umbrales: { litoral: 40, bajo: 100, medio: 300 },
      verUmbrales: false,
      guardandoTodos: false,
      // Tabla de categorias
      vistaCat: 'categorias',
      filtroEstrato: '',
      filtroZona: '',
      marcadas: [],
      lote: { estrato: '', zona: '' },
    };
  },
  computed: {
    estratosFila() { return [...this.estratos, 'SIN ESTRATO']; },
    zonasCol() { return [...this.zonas, 'SIN ZONA']; },
    /** Estaciones de la tabla de categorias, con los valores en edicion (cambios sin guardar incluidos). */
    categorizables() {
      const q = this.buscar.trim().toLowerCase();
      return this.estaciones.filter((s) => {
        const e = this.edicion[s.id] || {};
        return (!this.filtroEstrato || (e.estrato || 'SIN ESTRATO') === this.filtroEstrato)
          && (!this.filtroZona || (e.zona || 'SIN ZONA') === this.filtroZona)
          && (!q || `${s.codigo} ${s.nombre} ${s.ubicacion || ''} ${s.codigo_api || ''}`.toLowerCase().includes(q));
      }).sort((a, b) => a.nombre.localeCompare(b.nombre));
    },
    todasMarcadas() { return this.categorizables.length > 0 && this.categorizables.every((s) => this.marcadas.includes(s.id)); },
    conCoord() { return this.estaciones.filter((s) => s.lat !== null && s.lng !== null); },
    sinCoord() { return this.estaciones.filter((s) => s.lat === null || s.lng === null); },
    sucias() { return Object.keys(this.edicion).length ? this.estaciones.filter((s) => this.sucio(s)) : []; },
    filtradas() {
      const q = this.buscar.trim().toLowerCase();
      return this.estaciones.filter((s) => (!this.soloSin || s.lat === null)
        && (!q || `${s.nombre} ${s.codigo} ${s.codigo_api || ''} ${s.ubicacion || ''}`.toLowerCase().includes(q)));
    },
  },
  watch: {
    estaciones: { immediate: true, handler() { this.prepararEdicion(); this.$nextTick(() => this.pintar(!this.encuadrado)); } },
  },
  mounted() {
    this.mapa = L.map(this.$refs.mapa, { zoomSnap: 0.5 }).setView([14.3, -91.0], 9);
    const sat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, maxNativeZoom: 18, attribution: 'Imagenes &copy; Esri' });
    const calles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(this.mapa);
    L.control.layers({ Calles: calles, Satelite: sat }, null, { position: 'topright' }).addTo(this.mapa);
    L.control.scale({ imperial: false }).addTo(this.mapa);
    this.capa = L.layerGroup().addTo(this.mapa);
    this.mapa.on('click', this.clickMapa);
    this.pintar(true);
  },
  beforeDestroy() {
    if (this.mapa) this.mapa.remove();
  },
  methods: {
    prepararEdicion() {
      const e = {};
      this.estaciones.forEach((s) => {
        e[s.id] = { lat: s.lat === null ? '' : s.lat, lng: s.lng === null ? '' : s.lng, altitud: s.altitud === null ? '' : s.altitud, estrato: s.estrato || '', zona: s.zona || '' };
      });
      this.edicion = e;
    },
    sucio(s) {
      const e = this.edicion[s.id];
      if (!e) return false;
      const igual = (a, b) => (a === '' || a === null ? null : Number(a)) === (b === null ? null : Number(b));
      return !igual(e.lat, s.lat) || !igual(e.lng, s.lng) || !igual(e.altitud, s.altitud) || e.estrato !== (s.estrato || '') || e.zona !== (s.zona || '');
    },
    pintar(encuadrar) {
      if (!this.capa) return;
      this.capa.clearLayers();
      const pts = [];
      this.conCoord.forEach((s) => {
        const ll = [Number(s.lat), Number(s.lng)];
        pts.push(ll);
        L.circleMarker(ll, {
          radius: this.seleccion === s.id ? 9 : 6, weight: 2, color: '#ffffff',
          fillColor: s.activa ? (COLOR_ESTRATO[s.estrato] || '#8a8f87') : '#CED2D5', fillOpacity: 0.95,
        }).bindTooltip(s.nombre, { direction: 'top', offset: [0, -6] })
          .bindPopup(`<b>${this.esc(s.nombre)}</b><br>${this.esc(s.codigo)} · ${this.esc(s.red)}<br>${Number(s.lat).toFixed(5)}, ${Number(s.lng).toFixed(5)}${s.altitud !== null ? ` · ${s.altitud} msnm` : ''}${s.ubicacion ? `<br>${this.esc(s.ubicacion)}` : ''}`)
          .on('click', () => { this.seleccion = s.id; })
          .addTo(this.capa);
      });
      if (encuadrar && pts.length) {
        this.mapa.fitBounds(L.latLngBounds(pts).pad(0.15), { maxZoom: 13 });
        this.encuadrado = true;
      }
    },
    enfocar(s) {
      this.seleccion = s.id;
      if (s.lat !== null && this.mapa) this.mapa.setView([Number(s.lat), Number(s.lng)], Math.max(this.mapa.getZoom(), 12));
      this.pintar(false);
    },
    ubicar(s) {
      this.ubicando = s;
      this.seleccion = s.id;
      this.$refs.mapa.scrollIntoView({ behavior: 'smooth', block: 'center' });
    },
    nueva() {
      this.errorForm = null;
      this.form = { codigo: '', codigo_api: '', nombre: '', red: 'ICC REDMET', lat: '', lng: '', altitud: '', estrato: '', zona: '', ubicacion: '' };
    },
    crear() {
      const f = this.form;
      const num = (v) => (String(v).trim() === '' ? null : Number(String(v).replace(',', '.')));
      const lat = num(f.lat);
      const lng = num(f.lng);
      if (!String(f.codigo).trim() || !String(f.nombre).trim()) { this.errorForm = 'Codigo y nombre son obligatorios.'; return; }
      if (lat === null || lng === null || Number.isNaN(lat) || Number.isNaN(lng)) { this.errorForm = 'Indique latitud y longitud en grados decimales (o toque el mapa).'; return; }
      this.creando = true;
      this.errorForm = null;
      window.axios.post('/investigacion/clima/estaciones', {
        codigo: f.codigo.trim(), codigo_api: String(f.codigo_api).trim() || null, nombre: f.nombre.trim(), red: f.red,
        lat, lng, altitud: f.altitud === '' ? null : f.altitud, estrato: f.estrato || null, zona: f.zona || null, ubicacion: f.ubicacion || null, activa: true,
      })
        .then(({ data }) => {
          this.$swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Estacion agregada', text: data.nombre, timer: 2000, showConfirmButton: false });
          this.form = null;
          this.$emit('recargar');
          if (this.mapa) this.mapa.setView([lat, lng], Math.max(this.mapa.getZoom(), 11));
        })
        .catch((err) => {
          const r = err.response;
          this.errorForm = (r && r.data && (r.data.errors ? Object.values(r.data.errors).flat().join(' ') : r.data.message)) || 'No se pudo guardar.';
        })
        .finally(() => { this.creando = false; });
    },
    clickMapa(ev) {
      if (this.form && !this.ubicando) {
        this.form.lat = ev.latlng.lat.toFixed(6);
        this.form.lng = ev.latlng.lng.toFixed(6);
        return;
      }
      if (!this.ubicando) return;
      const e = this.edicion[this.ubicando.id];
      e.lat = Number(ev.latlng.lat.toFixed(6));
      e.lng = Number(ev.latlng.lng.toFixed(6));
      const s = this.ubicando;
      this.ubicando = null;
      this.guardar(s);
    },
    guardar(s, enLote = false) {
      const e = this.edicion[s.id];
      this.guardando = s.id;
      const payload = {
        codigo: s.codigo, codigo_api: s.codigo_api, nombre: s.nombre, red: s.red, ubicacion: s.ubicacion, activa: !!s.activa,
        lat: e.lat === '' ? null : e.lat, lng: e.lng === '' ? null : e.lng, altitud: e.altitud === '' ? null : e.altitud,
        estrato: e.estrato || null, zona: e.zona || null,
      };
      return window.axios.put(`/investigacion/clima/estaciones/${s.id}`, payload)
        .then(() => {
          if (enLote) return;
          this.$swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Estacion guardada', text: s.nombre, timer: 2000, showConfirmButton: false });
          this.$emit('recargar');
        })
        .catch((err) => {
          const r = err.response;
          const msg = (r && r.data && (r.data.errors ? Object.values(r.data.errors).flat().join(' ') : r.data.message)) || 'No se pudo guardar.';
          this.$swal.fire({ icon: 'error', title: `Error en ${s.nombre}`, text: msg });
          if (enLote) throw err;
        })
        .finally(() => { this.guardando = null; });
    },
    /** Guarda todas las filas modificadas (p. ej. tras sugerir estratos). */
    async guardarTodos() {
      const lista = this.sucias.slice();
      this.guardandoTodos = true;
      let ok = 0;
      try {
        for (const s of lista) {
          await this.guardar(s, true);
          ok++;
        }
        this.$swal.fire({ toast: true, position: 'top-end', icon: 'success', title: `${ok} estacion(es) guardada(s)`, timer: 2200, showConfirmButton: false });
      } catch (e) {
        // el error ya se mostro
      } finally {
        this.guardandoTodos = false;
        this.$emit('recargar');
      }
    },
    /**
     * Estrato sugerido por altitud para las estaciones sin estrato (criterio
     * editable; revise antes de guardar).
     */
    sugerirEstratos() {
      const u = this.umbrales;
      let n = 0;
      this.estaciones.forEach((s) => {
        const e = this.edicion[s.id];
        const alt = e.altitud === '' ? null : Number(e.altitud);
        if (e.estrato || alt === null || Number.isNaN(alt)) return;
        e.estrato = alt < u.litoral ? 'LITORAL' : alt < u.bajo ? 'BAJO' : alt < u.medio ? 'MEDIO' : 'ALTO';
        n++;
      });
      this.verUmbrales = false;
      this.$swal.fire({
        toast: true, position: 'top-end', icon: n ? 'success' : 'info', timer: 3000, showConfirmButton: false,
        title: n ? `Estrato sugerido en ${n} estacion(es)` : 'Sin cambios',
        text: n ? 'Revise y pulse "Guardar cambios".' : 'Solo se sugiere a estaciones sin estrato y con altitud.',
      });
    },
    colorEstrato(e) { return COLOR_ESTRATO[e] || '#CED2D5'; },
    /** Longitud de Guatemala/El Salvador escrita sin el signo negativo: se corrige al salir del campo. */
    corregirSigno(s) {
      const e = this.edicion[s.id];
      const lat = Number(e.lat);
      const lng = Number(e.lng);
      if (e.lng !== '' && lng > 86 && lng < 93 && (e.lat === '' || (lat > 12 && lat < 19))) {
        e.lng = -lng;
        this.$swal.fire({ toast: true, position: 'top-end', icon: 'info', title: 'Longitud corregida', text: `${s.nombre}: ${-lng} (oeste es negativo)`, timer: 2400, showConfirmButton: false });
      }
    },
    /* ---------------- tabla de categorias ---------------- */
    cruce(estrato, zona) {
      return this.estaciones.filter((s) => {
        const e = this.edicion[s.id] || {};
        return (e.estrato || 'SIN ESTRATO') === estrato && (zona === null || (e.zona || 'SIN ZONA') === zona);
      }).length;
    },
    filtrarCelda(estrato, zona) {
      this.filtroEstrato = estrato;
      this.filtroZona = zona;
      this.buscar = '';
    },
    marcarTodas(si) {
      const ids = this.categorizables.map((s) => s.id);
      this.marcadas = si ? [...new Set([...this.marcadas, ...ids])] : this.marcadas.filter((id) => !ids.includes(id));
    },
    aplicarLote() {
      this.marcadas.forEach((id) => {
        const e = this.edicion[id];
        if (!e) return;
        if (this.lote.estrato) e.estrato = this.lote.estrato === '-' ? '' : this.lote.estrato;
        if (this.lote.zona) e.zona = this.lote.zona === '-' ? '' : this.lote.zona;
      });
      this.$swal.fire({ toast: true, position: 'top-end', icon: 'info', title: `${this.marcadas.length} estacion(es) actualizada(s)`, text: 'Pulse "Guardar cambios" para confirmar.', timer: 2600, showConfirmButton: false });
      this.marcadas = [];
      this.lote = { estrato: '', zona: '' };
    },
    mostrarMapa() {
      this.vistaCat = 'mapa';
      this.$nextTick(() => {
        if (!this.mapa) return;
        this.mapa.invalidateSize();
        // El mapa se creo oculto: al mostrarlo por primera vez se encuadran las estaciones.
        this.pintar(!this.mapaVisto);
        this.mapaVisto = true;
      });
    },
    importar(ev) {
      const f = ev.target.files[0];
      ev.target.value = '';
      if (!f) return;
      const fd = new FormData();
      fd.append('archivo', f);
      this.importando = true;
      this.resultado = null;
      window.axios.post('/investigacion/clima/estaciones-catalogo', fd)
        .then(({ data }) => { this.resultado = data; this.$emit('recargar'); })
        .catch((err) => {
          const r = err.response;
          this.$swal.fire({ icon: 'error', title: 'No se pudo importar', text: (r && r.data && (r.data.message || (r.data.errors && Object.values(r.data.errors).flat()[0]))) || 'Error al leer el archivo.' });
        })
        .finally(() => { this.importando = false; });
    },
    descargar() {
      const c = (v) => (v === null || v === undefined ? '' : String(v).replace(/;/g, ','));
      const n = (v) => (v === null || v === undefined ? '' : String(v).replace('.', ','));
      const lineas = [
        'Codigo;Estacionid;Nombre;Latitud;Longitud;Altitud;Estrato;Zona;Ubicacion;Red;Activa',
        ...this.estaciones.map((s) => [c(s.codigo), c(s.codigo_api), c(s.nombre), n(s.lat), n(s.lng), c(s.altitud), c(s.estrato), c(s.zona), c(s.ubicacion), c(s.red), s.activa ? 'si' : 'no'].join(';')),
      ];
      const blob = new Blob([`﻿${lineas.join('\r\n')}`], { type: 'text/csv;charset=utf-8' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'estaciones_meteorologicas.csv';
      document.body.appendChild(a);
      a.click();
      setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    },
    esc(s) {
      const d = document.createElement('div');
      d.textContent = s == null ? '' : String(s);
      return d.innerHTML;
    },
  },
};
</script>

<style scoped>
.ce-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
.ce-cifras { display: flex; flex-wrap: wrap; gap: 12px; color: #26331f; font-size: 13px; }
.ce-cifras .is-ok { color: #3f7a12; }
.ce-cifras .is-falta { color: #b84d00; font-weight: 600; }
.ce-acc { display: flex; gap: 6px; }
.ce-permiso { margin-bottom: 8px; padding: 7px 12px; border-radius: 8px; background: #f3f5f1; color: #5b6658; font-size: 12.5px; }
.ce-form { margin-bottom: 12px; padding: 12px; border: 1px solid #dcebc8; border-radius: 10px; background: #f7fbf1; }
.ce-form-tit { margin-bottom: 8px; color: #1f5632; font-size: 13px; font-weight: 700; }
.ce-form-tit i { margin-right: 6px; color: #73BC25; }
.ce-form-tit small { color: #6b7568; font-weight: 500; }
.ce-form label { margin-bottom: 2px; font-size: 11.5px; font-weight: 600; }
.ce-error { color: #b84d00; font-size: 12px; }
.ce-ayuda { margin-bottom: 10px; color: var(--cengi-muted, #6b7568); font-size: 12px; line-height: 1.5; }
.ce-res { margin-bottom: 10px; padding: 8px 12px; border-radius: 8px; background: #eef8df; color: #3f7a12; font-size: 12.5px; }
.ce-res.is-warn { background: #fff8ec; color: #7a4a00; }
.ce-res i { margin-right: 5px; }
.ce-grid { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr); gap: 12px; }
.ce-mapa-wrap { position: relative; }
.ce-mapa { height: 560px; border: 1px solid #e3e7e1; border-radius: 12px; z-index: 0; }
.ce-ubicando {
  position: absolute; top: 10px; left: 50%; z-index: 500; transform: translateX(-50%); padding: 6px 12px; border-radius: 8px;
  background: #fff; box-shadow: 0 2px 8px rgba(0, 0, 0, .25); color: #6e3b00; font-size: 12.5px; white-space: nowrap;
}
.ce-lista { display: flex; flex-direction: column; min-width: 0; }
.ce-filtro { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 8px; font-size: 12px; }
.ce-filtro .form-control { flex: 1 1 180px; }
.ce-tabla-wrap { max-height: 520px; overflow: auto; border: 1px solid #edf1ec; border-radius: 10px; background: #fff; }
.ce-tabla { margin: 0; font-size: 12.5px; }
.ce-tabla th { position: sticky; top: 0; z-index: 1; background: #f7f9f5; color: var(--cengi-muted, #6b7568); font-size: 11px; white-space: nowrap; }
.ce-tabla td { vertical-align: middle; }
.ce-tabla td small { display: block; color: var(--cengi-muted, #6b7568); font-size: 11px; }
.ce-tabla tr { cursor: pointer; }
.ce-tabla tr.is-sel td { background: #f1f8e9; }
.ce-tabla tr.is-off td { opacity: .6; }
.ce-vistas { display: inline-flex; gap: 4px; margin-bottom: 10px; padding: 3px; border: 1px solid #e3e7e1; border-radius: 9px; background: #f3f5f1; }
.ce-vistas button { padding: 5px 12px; border: 0; border-radius: 7px; background: transparent; color: #5b6658; font-size: 12.5px; font-weight: 600; }
.ce-vistas button.is-on { background: #fff; color: #1f5632; box-shadow: 0 1px 3px rgba(30, 42, 26, .12); }
.ce-vistas button i { margin-right: 4px; color: #73BC25; }
.cc-matriz { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-start; margin-bottom: 10px; }
.cc-tm { width: auto; margin: 0; border: 1px solid #e3e7e1; border-radius: 10px; background: #fff; font-size: 12.5px; }
.cc-tm th, .cc-tm td { padding: 5px 12px; text-align: center; vertical-align: middle; }
.cc-tm thead th { background: #f7f9f5; color: #6b7568; font-size: 11px; }
.cc-tm tbody th { text-align: left; }
.cc-tm td.is-cero { color: #CED2D5; }
.cc-tm td a { font-weight: 700; }
.cc-ayuda { flex: 1 1 260px; margin: 0; color: #6b7568; font-size: 12px; line-height: 1.5; }
.cc-bar, .cc-lote { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 8px; }
.cc-buscar { flex: 1 1 220px; max-width: 360px; }
.cc-filtro { width: auto; }
.cc-cuenta { margin-left: auto; color: #6b7568; font-size: 12px; }
.cc-lote { padding: 7px 10px; border: 1px dashed #d8ddd5; border-radius: 9px; color: #5b6658; font-size: 12.5px; }
.cc-lote.is-activo { border-style: solid; border-color: #73BC25; background: #f7fbf1; }
.cc-tabla-wrap { max-height: 560px; overflow: auto; border: 1px solid #edf1ec; border-radius: 10px; background: #fff; }
.cc-tabla { margin: 0; font-size: 12.5px; }
.cc-tabla th { position: sticky; top: 0; z-index: 1; background: #f7f9f5; color: #6b7568; font-size: 11px; white-space: nowrap; }
.cc-tabla td { vertical-align: middle; }
.cc-tabla tr.is-sucia td { background: #fff8f0; }
.cc-tabla tr.is-sucia td:first-child { box-shadow: inset 3px 0 0 #FF6B00; }
.cc-tabla tr.is-marcada td { background: #f1f8e9; }
.cc-tabla tr.is-off td { opacity: .6; }
.cc-chk { width: 34px; }
.cc-cod { font-family: SFMono-Regular, Consolas, monospace; font-size: 12px; white-space: nowrap; }
.cc-muted { color: #6b7568; }
.cc-alt { width: 80px; margin-left: auto; text-align: right; }
.cc-coord { width: 118px; }
.cc-coord.is-falta { border-color: #f3c9a6; background: #fff8f2; }
.ce-estratos { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-bottom: 8px; }
.ce-estrato { --c: #8a8f87; padding: 1px 9px; border-left: 4px solid var(--c); border-radius: 6px; background: color-mix(in srgb, var(--c) 12%, #fff); color: #26331f; font-size: 11.5px; font-weight: 700; }
.ce-estrato small { color: #6b7568; font-weight: 500; }
.ce-umbrales { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-bottom: 8px; padding: 8px 10px; border-radius: 8px; background: #f7fbf1; color: #33402a; font-size: 12px; }
.ce-umbrales input { width: 64px; padding: 2px 6px; border: 1px solid #d8ddd5; border-radius: 6px; }
.ce-sel { width: 132px; border-left-width: 4px; }
.ce-num { width: 108px; }
.ce-alt { width: 70px; }
.ce-num.is-sucio { border-color: #FF6B00; }
.ce-nowrap { white-space: nowrap; }
.ce-icon { display: inline-grid; place-items: center; width: 28px; height: 28px; border: 1px solid transparent; border-radius: 7px; background: transparent; color: var(--cengi-muted, #6b7568); font-size: 12px; }
.ce-icon:hover { border-color: #e3e7e1; background: #f1f8e9; color: #1f5632; }
.ce-icon.is-guardar { color: #fff; background: #73BC25; }
.ce-muted { color: var(--cengi-muted, #6b7568); }
@media (max-width: 991px) {
  .ce-grid { grid-template-columns: 1fr; }
  .ce-mapa { height: 380px; }
}
</style>

<style>
/* Icono del selector de capas sin layers.png (Mix lo publica sin el prefijo /sigec). */
.ce .leaflet-control-layers-toggle { display: grid; place-items: center; width: 36px; height: 36px; background-image: none; }
.ce .leaflet-control-layers-toggle::after { content: '\f5fd'; color: #1f5632; font-family: 'Font Awesome 5 Free'; font-size: 16px; font-weight: 900; }
.ce .leaflet-control-layers-expanded .leaflet-control-layers-toggle { display: none; }
.ce .leaflet-control-layers-expanded { padding: 8px 12px; font-size: 12.5px; }
</style>
