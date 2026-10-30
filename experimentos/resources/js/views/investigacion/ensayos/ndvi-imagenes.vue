<template>
  <div class="ni">
    <div class="det-sec-head">
      <h6><i class="fas fa-layer-group"></i> Imagenes NDVI por fecha</h6>
      <button v-if="puedeEditar" type="button" class="btn btn-success btn-sm" @click="panel = !panel">
        <i class="fas fa-upload"></i> Agregar imagen
      </button>
    </div>

    <!-- Imagenes ya cargadas en el modulo "Imagenes Geoespaciales" -->
    <div class="ni-geo" v-if="geoDisponibles.length">
      <div class="ni-geo-tit">
        <i class="fas fa-satellite-dish"></i>
        <span><b>Ya cargadas en Imagenes Geoespaciales</b> para este ensayo · se procesan sin volver a subirlas</span>
      </div>
      <div v-for="g in geoDisponibles" :key="g.id" class="ni-geo-fila">
        <span class="det-tag">{{ g.tipo }}</span>
        <b>{{ fechaTxt(g.fecha) }}</b>
        <span class="ni-geo-nom" :title="g.nombre">{{ g.sensor ? g.sensor + ' · ' : '' }}{{ g.nombre }}</span>
        <span v-if="procesada(g)" class="ni-ok"><i class="fas fa-check-circle"></i> NDVI del {{ fechaTxt(procesada(g).fecha) }}</span>
        <button v-else-if="puedeEditar" type="button" class="btn btn-success btn-sm" :disabled="!!trayendo || ocupado" @click="importarGeo(g)">
          <i :class="trayendo === g.id ? 'fas fa-spinner fa-spin' : 'fas fa-cogs'"></i> Procesar NDVI
        </button>
      </div>
    </div>

    <!-- Carga -->
    <div class="det-panel" v-if="panel && puedeEditar">
      <div v-if="origenGeo" class="ni-info ni-origen">
        <i class="fas fa-satellite-dish"></i> Origen: Imagenes Geoespaciales · <b>{{ origenGeo.nombre }}</b>
        <span v-if="origenGeo.bandas">· bandas: {{ origenGeo.bandas }}</span>
      </div>
      <p>
        Suba un <b>GeoTIFF</b> NDVI (Sentinel-2/EO Browser, QGIS, Pix4D, DroneDeploy...) para calcular el NDVI de cada parcela,
        o una imagen <b>PNG/JPG</b> ya coloreada indicando sus limites. La imagen se muestra en el Mapa GPS segun su fecha.
      </p>
      <div class="form-row">
        <div class="col-md-5 form-group">
          <label>Archivo</label>
          <div class="custom-file">
            <input type="file" class="custom-file-input" id="ni-archivo" accept=".tif,.tiff,.png,.jpg,.jpeg" @change="elegir($event.target.files[0] || null)">
            <label class="custom-file-label" for="ni-archivo">{{ archivo ? archivo.name : 'GeoTIFF, PNG o JPG' }}</label>
          </div>
        </div>
        <div class="col-md-3 form-group">
          <label>Fecha de captura</label>
          <input type="date" class="form-control" v-model="form.fecha">
        </div>
        <div class="col-md-4 form-group">
          <label>Fuente</label>
          <select class="form-control" v-model="form.fuente">
            <option :value="null">—</option>
            <option v-for="f in fuentes" :key="f" :value="f">{{ f }}</option>
          </select>
        </div>
      </div>

      <div v-if="leyendo" class="ni-info"><i class="fas fa-spinner fa-spin"></i> Leyendo GeoTIFF...</div>
      <template v-else-if="tif">
        <div class="ni-info">
          <i class="fas fa-check-circle"></i>
          {{ tif.ancho }} × {{ tif.alto }} px · {{ tif.bandas }} banda(s) · EPSG:{{ tif.epsg }}
          <span v-if="resolM">· {{ resolM }} m/pixel</span>
        </div>
        <div class="form-row" v-if="tif.bandas > 1">
          <div class="col-md-4 form-group">
            <label>Contenido</label>
            <select class="form-control" v-model="form.modo">
              <option value="calcular">Calcular NDVI con bandas Roja y NIR</option>
              <option value="banda">Una banda ya es NDVI</option>
            </select>
          </div>
          <template v-if="form.modo === 'calcular'">
            <div class="col-md-2 form-group">
              <label>Banda Roja</label>
              <select class="form-control" v-model.number="form.rojo"><option v-for="b in tif.bandas" :key="b" :value="b - 1">Banda {{ b }}</option></select>
            </div>
            <div class="col-md-2 form-group">
              <label>Banda NIR</label>
              <select class="form-control" v-model.number="form.nir"><option v-for="b in tif.bandas" :key="b" :value="b - 1">Banda {{ b }}</option></select>
            </div>
          </template>
          <div v-else class="col-md-2 form-group">
            <label>Banda NDVI</label>
            <select class="form-control" v-model.number="form.banda"><option v-for="b in tif.bandas" :key="b" :value="b - 1">Banda {{ b }}</option></select>
          </div>
        </div>
        <p v-if="tif.bandas === 3" class="det-warn"><i class="fas fa-exclamation-triangle"></i> Con 3 bandas suele ser una imagen RGB visual; verifique que contenga NIR.</p>
      </template>
      <template v-else-if="archivo && !esTif">
        <p class="ni-ayuda">Limites de la imagen (grados decimales, WGS84):</p>
        <div class="form-row">
          <div class="col-6 col-md-2 form-group"><label>Norte</label><input type="number" step="any" class="form-control" v-model.number="form.n"></div>
          <div class="col-6 col-md-2 form-group"><label>Sur</label><input type="number" step="any" class="form-control" v-model.number="form.s"></div>
          <div class="col-6 col-md-2 form-group"><label>Este</label><input type="number" step="any" class="form-control" v-model.number="form.e"></div>
          <div class="col-6 col-md-2 form-group"><label>Oeste</label><input type="number" step="any" class="form-control" v-model.number="form.o"></div>
          <div class="col-md-4 form-group d-flex align-items-end">
            <button type="button" class="btn btn-secondary btn-block" :disabled="!conGeometria.length" @click="usarExtension">
              <i class="fas fa-expand"></i> Usar extension de las parcelas
            </button>
          </div>
        </div>
        <p class="ni-ayuda">Sin valores de pixel: el NDVI por parcela se puede capturar a mano en la tabla inferior.</p>
      </template>

      <div v-if="error" class="det-warn"><i class="fas fa-exclamation-triangle"></i> {{ error }}</div>
      <div class="text-right">
        <button type="button" class="btn btn-success" :disabled="!puedeGuardar || ocupado" @click="guardar">
          <i :class="ocupado ? 'fas fa-spinner fa-spin' : 'fas fa-save'"></i> {{ ocupado ? estado : 'Procesar y guardar' }}
        </button>
      </div>
    </div>

    <!-- Galeria -->
    <div v-if="!imagenes.length" class="det-empty-box">
      <i class="fas fa-layer-group"></i>
      Sin imagenes NDVI. Agregue un GeoTIFF para ver la variacion del indice por parcela en el Mapa GPS.
    </div>
    <div v-else class="ni-galeria">
      <div v-for="im in imagenesDesc" :key="im.id" class="ni-card">
        <div class="ni-thumb" :style="{ backgroundImage: `url(${storageUrl}/${im.imagen_path})` }"></div>
        <div class="ni-card-body">
          <strong>{{ fechaTxt(im.fecha) }}</strong>
          <small>{{ im.fuente || 'Fuente no indicada' }} · {{ im.tipo === 'geotiff' ? 'GeoTIFF' : 'Imagen' }}</small>
          <div class="ni-card-stats">
            <span v-if="mediaImagen(im) !== null" class="ni-val" :style="estiloValor(mediaImagen(im))">{{ mediaImagen(im).toFixed(2) }}</span>
            <small>{{ nValores(im) }} de {{ conGeometria.length }} parcelas</small>
          </div>
          <small v-if="faltantes(im).length && im.raster_path" class="ni-falta">
            <i class="fas fa-exclamation-circle"></i> {{ faltantes(im).length }} parcela(s) sin valor
          </small>
        </div>
        <div class="ni-card-acc">
          <button type="button" class="det-icon" title="Ver en el Mapa GPS" @click="$emit('ver-mapa', im.fecha)"><i class="fas fa-map-marked-alt"></i></button>
          <template v-if="puedeEditar">
            <button v-if="im.raster_path" type="button" class="det-icon" title="Recalcular NDVI por parcela (tras cambiar los poligonos)" :disabled="ocupado" @click="recalcular(im)"><i class="fas fa-sync-alt"></i></button>
            <button v-if="nValores(im)" type="button" class="det-icon" title="Registrar el promedio por tratamiento en la serie NDVI" :disabled="ocupado" @click="aTratamientos(im)"><i class="fas fa-flask"></i></button>
            <button type="button" class="det-icon det-icon-danger" title="Eliminar imagen" :disabled="ocupado" @click="eliminar(im)"><i class="fas fa-trash"></i></button>
          </template>
        </div>
      </div>
    </div>

    <!-- Matriz parcela x fecha -->
    <template v-if="imagenes.length && parcelasTabla.length">
      <div class="det-sec-head mt-3">
        <h6><i class="fas fa-th-list"></i> NDVI por parcela</h6>
        <div class="ni-leyenda">
          <span>0</span><i :style="{ background: degradado }"></i><span>1</span>
        </div>
      </div>
      <div class="det-table-wrap">
        <table class="table det-table ni-tabla">
          <thead>
            <tr>
              <th>Parcela</th><th>Trat.</th><th>Rep.</th>
              <th v-for="im in imagenes" :key="im.id" class="text-center">{{ fechaCorta(im.fecha) }}</th>
              <th v-if="imagenes.length > 1" class="text-center" title="Cambio entre la primera y la ultima fecha con valor">Δ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in parcelasTabla" :key="p.id">
              <td><strong>{{ p.codigo }}</strong></td>
              <td><span class="det-tag" :style="{ '--c': colorTrat(nombreTrat(p)) }">{{ nombreTrat(p) }}</span></td>
              <td>{{ p.repeticion }}</td>
              <td v-for="im in imagenes" :key="im.id" class="text-center">
                <input
                  v-if="puedeEditar && im.tipo === 'imagen'" type="number" min="-1" max="1" step="0.01" class="form-control form-control-sm ni-input"
                  :value="valor(im, p) === null ? '' : valor(im, p)" :style="estiloValor(valor(im, p))" @change="capturar(im, p, $event.target.value)"
                >
                <span v-else-if="valor(im, p) !== null" class="ni-val" :style="estiloValor(valor(im, p))" :title="rango(im, p)">{{ valor(im, p).toFixed(2) }}</span>
                <span v-else class="det-muted">—</span>
              </td>
              <td v-if="imagenes.length > 1" class="text-center">
                <span v-if="delta(p) !== null" class="ni-delta" :class="delta(p) >= 0 ? 'is-sube' : 'is-baja'">
                  <i :class="delta(p) >= 0 ? 'fas fa-arrow-up' : 'fas fa-arrow-down'"></i> {{ Math.abs(delta(p)).toFixed(2) }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<script>
import { colorNdvi, tintaNdvi, degradadoNdvi } from './ndvi-color';

const hoy = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

export default {
  name: 'NdviImagenes',
  props: {
    ensayoId: { type: Number, required: true },
    imagenes: { type: Array, default: () => [] },
    geo: { type: Array, default: () => [] },
    parcelas: { type: Array, default: () => [] },
    tratamientos: { type: Array, default: () => [] },
    fuentes: { type: Array, default: () => [] },
    storageUrl: { type: String, default: '' },
    puedeEditar: { type: Boolean, default: false },
    colorTrat: { type: Function, default: () => '#73BC25' },
  },
  data() {
    return {
      panel: false,
      archivo: null,
      tif: null,
      leyendo: false,
      origenGeo: null,
      trayendo: null,
      ocupado: false,
      estado: '',
      error: null,
      form: this.formVacio(),
      degradado: degradadoNdvi(0, 1),
    };
  },
  computed: {
    esTif() { return !!this.archivo && /\.tiff?$/i.test(this.archivo.name); },
    conGeometria() { return this.parcelas.filter((p) => p.geometria); },
    imagenesDesc() { return this.imagenes.slice().reverse(); },
    // RGB y termicas no sirven para NDVI.
    geoDisponibles() {
      return this.geo.filter((g) => ['NDVI', 'Multiespectral'].includes(g.tipo) && /\.(tiff?|png|jpe?g)$/i.test(g.path || ''));
    },
    parcelasTabla() {
      return this.parcelas.filter((p) => p.geometria || this.imagenes.some((im) => this.valor(im, p) !== null));
    },
    resolM() {
      if (!this.tif) return null;
      const r = Math.abs(this.tif.resol[0]);
      const m = this.tif.epsg === 4326 ? r * 111320 : r;
      return m < 1 ? m.toFixed(2) : m.toFixed(1);
    },
    puedeGuardar() {
      if (!this.archivo || !this.form.fecha) return false;
      if (this.esTif) return !!this.tif && (this.form.modo !== 'calcular' || this.form.rojo !== this.form.nir);
      const { n, s, e, o } = this.form;
      return [n, s, e, o].every((v) => typeof v === 'number' && Number.isFinite(v)) && n > s && e > o;
    },
  },
  methods: {
    formVacio() {
      return { fecha: hoy(), fuente: this.fuentes ? this.fuentes[0] || null : null, modo: 'banda', banda: 0, rojo: 0, nir: 1, n: null, s: null, e: null, o: null };
    },
    raster() { return import(/* webpackChunkName: "ndvi" */ './ndvi-raster'); },
    procesada(g) { return this.imagenes.find((im) => im.imagen_geo_id === g.id) || null; },
    /** Consulta el archivo ya guardado en Imagenes Geoespaciales y lo pasa por el mismo proceso. */
    async importarGeo(g) {
      this.trayendo = g.id;
      this.panel = true;
      this.error = null;
      try {
        const r = await fetch(`${this.storageUrl}/${g.path}`);
        if (!r.ok) throw new Error(`No se pudo leer el archivo (${r.status}).`);
        const blob = await r.blob();
        // El nombre conserva la extension del archivo guardado (define si es TIFF).
        const ext = (g.path.match(/\.[^.]+$/) || [''])[0];
        const nombre = new RegExp(`${ext.replace('.', '\\.')}$`, 'i').test(g.nombre) ? g.nombre : `${g.nombre}${ext}`;
        await this.elegir(new File([blob], nombre, { type: blob.type }));
        this.origenGeo = g;
        if (g.fecha) this.form.fecha = g.fecha;
        const fuente = this.fuenteDeSensor(g.sensor);
        if (fuente) this.form.fuente = fuente;
      } catch (e) {
        this.error = e.message || String(e);
      } finally {
        this.trayendo = null;
      }
    },
    fuenteDeSensor(sensor) {
      const s = String(sensor || '').toLowerCase();
      const buscar = (re) => this.fuentes.find((f) => re.test(f.toLowerCase()));
      if (/sentinel|copernicus|s2/.test(s)) return buscar(/sentinel/);
      if (/landsat/.test(s)) return buscar(/landsat/);
      if (/dron|drone|uav|dji|micasense|parrot|sequoia|phantom|mavic/.test(s)) return buscar(/dron/);
      if (/earth engine|gee/.test(s)) return buscar(/earth engine/);
      return null;
    },
    async elegir(file) {
      this.archivo = file;
      this.origenGeo = null;
      this.tif = null;
      this.error = null;
      if (!this.archivo) return;
      const m = this.archivo.name.match(/(20\d{2})[-_]?(\d{2})[-_]?(\d{2})/);
      if (m) this.form.fecha = `${m[1]}-${m[2]}-${m[3]}`;
      if (!this.esTif) { if (this.form.n === null && this.conGeometria.length) this.usarExtension(); return; }
      this.leyendo = true;
      try {
        const nr = await this.raster();
        this.tif = Object.freeze(await nr.leerGeoTiff(await this.archivo.arrayBuffer()));
        const b = this.tif.bandas;
        if (b === 1) Object.assign(this.form, { modo: 'banda', banda: 0 });
        else if (b === 4) Object.assign(this.form, { modo: 'calcular', rojo: 0, nir: 3 });
        else if (b >= 5) Object.assign(this.form, { modo: 'calcular', rojo: 2, nir: 4 });
        else Object.assign(this.form, { modo: 'calcular', rojo: 0, nir: b - 1 });
      } catch (e) {
        this.error = e.message || String(e);
      } finally {
        this.leyendo = false;
      }
    },
    usarExtension() {
      const pts = this.conGeometria.flatMap((p) => p.geometria.coordinates[0]);
      if (!pts.length) return;
      const lats = pts.map((c) => c[1]); const lngs = pts.map((c) => c[0]);
      const r = (v) => Number(v.toFixed(7));
      Object.assign(this.form, { n: r(Math.max(...lats)), s: r(Math.min(...lats)), e: r(Math.max(...lngs)), o: r(Math.min(...lngs)) });
    },
    async guardar() {
      this.ocupado = true;
      this.error = null;
      try {
        const fd = new FormData();
        fd.append('fecha', this.form.fecha);
        if (this.form.fuente) fd.append('fuente', this.form.fuente);
        fd.append('nombre', this.archivo.name);
        if (this.origenGeo) fd.append('imagen_geo_id', this.origenGeo.id);
        if (this.esTif) {
          this.estado = 'Calculando NDVI...';
          const nr = await this.raster();
          const opc = this.form.modo === 'calcular' ? { rojo: this.form.rojo, nir: this.form.nir } : { banda: this.form.banda };
          const r = await nr.rasterNdvi(this.tif, opc);
          this.estado = 'Generando imagen...';
          const png = await nr.colorear(r.datos, r.ancho, r.alto);
          const valores = nr.valoresPorParcela(r.datos, r.ancho, r.alto, r.georef, this.parcelas);
          fd.append('tipo', 'geotiff');
          fd.append('imagen', png, 'ndvi.png');
          fd.append('imagen_ancho', r.ancho);
          fd.append('imagen_alto', r.alto);
          fd.append('raster', new Blob([r.datos], { type: 'application/octet-stream' }), 'ndvi.bin');
          fd.append('esquinas', JSON.stringify(nr.esquinas(r.georef, r.ancho, r.alto)));
          fd.append('meta', JSON.stringify({
            georef: r.georef, raster: { ancho: r.ancho, alto: r.alto }, original: { ancho: this.tif.ancho, alto: this.tif.alto, bandas: this.tif.bandas },
            ...opc, escala: r.escala, stats: r.stats,
          }));
          fd.append('valores', JSON.stringify(valores));
        } else {
          const dim = await new Promise((ok, mal) => {
            const im = new Image();
            im.onload = () => { ok({ w: im.naturalWidth, h: im.naturalHeight }); URL.revokeObjectURL(im.src); };
            im.onerror = () => mal(new Error('No se pudo leer la imagen.'));
            im.src = URL.createObjectURL(this.archivo);
          });
          const { n, s, e, o } = this.form;
          fd.append('tipo', 'imagen');
          fd.append('imagen', this.archivo);
          fd.append('imagen_ancho', dim.w);
          fd.append('imagen_alto', dim.h);
          fd.append('esquinas', JSON.stringify({ tl: { lat: n, lng: o }, tr: { lat: n, lng: e }, bl: { lat: s, lng: o }, br: { lat: s, lng: e } }));
        }
        this.estado = 'Guardando...';
        const { data } = await window.axios.post(`/investigacion/ensayos/${this.ensayoId}/ndvi-imagenes`, fd);
        this.$emit('actualizado', data);
        const im = data.ndvi_imagenes.find((x) => x.nombre === this.archivo.name && x.fecha === this.form.fecha);
        this.toast('Imagen NDVI guardada', im && im.valores ? `${Object.keys(im.valores).length} parcela(s) con valor` : '');
        this.panel = false;
        this.archivo = null;
        this.origenGeo = null;
        this.tif = null;
        this.form = this.formVacio();
      } catch (e) {
        this.error = this.mensaje(e);
      } finally {
        this.ocupado = false;
      }
    },
    async recalcular(im) {
      this.ocupado = true;
      try {
        const [nr, buf] = await Promise.all([
          this.raster(),
          fetch(`${this.storageUrl}/${im.raster_path}`).then((r) => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.arrayBuffer(); }),
        ]);
        const { georef, raster } = im.meta;
        const valores = nr.valoresPorParcela(new Uint8Array(buf), raster.ancho, raster.alto, georef, this.parcelas);
        const { data } = await window.axios.put(`/investigacion/ndvi-imagenes/${im.id}`, { valores });
        this.$emit('actualizado', data);
        this.toast('NDVI recalculado', `${Object.keys(valores).length} parcela(s) con valor`);
      } catch (e) {
        this.falla(e);
      } finally {
        this.ocupado = false;
      }
    },
    aTratamientos(im) {
      this.confirmar('Registrar por tratamiento', `Se guardara el NDVI promedio de cada tratamiento del ${this.fechaTxt(im.fecha)} en la serie NDVI (periodo ${im.fecha}).`)
        .then((ok) => ok && this.pedir(window.axios.post(`/investigacion/ndvi-imagenes/${im.id}/tratamientos`), 'Promedios registrados'));
    },
    eliminar(im) {
      this.confirmar('Eliminar imagen NDVI', `Se eliminara la imagen del ${this.fechaTxt(im.fecha)} y sus valores por parcela.`)
        .then((ok) => ok && this.pedir(window.axios.delete(`/investigacion/ndvi-imagenes/${im.id}`), 'Imagen eliminada'));
    },
    capturar(im, p, txt) {
      const valores = { ...(im.valores || {}) };
      const v = parseFloat(txt);
      if (txt === '' || Number.isNaN(v)) delete valores[p.id];
      else valores[p.id] = { media: Math.max(-1, Math.min(1, v)) };
      this.pedir(window.axios.put(`/investigacion/ndvi-imagenes/${im.id}`, { valores }), `NDVI ${p.codigo} guardado`);
    },
    pedir(promesa, ok) {
      this.ocupado = true;
      return promesa
        .then(({ data }) => { this.$emit('actualizado', data); this.toast(ok); })
        .catch(this.falla)
        .finally(() => { this.ocupado = false; });
    },

    valor(im, p) {
      const v = im.valores && im.valores[p.id];
      return v && Number.isFinite(Number(v.media)) ? Number(v.media) : null;
    },
    rango(im, p) {
      const v = im.valores && im.valores[p.id];
      return v && v.min !== undefined ? `min ${Number(v.min).toFixed(2)} · max ${Number(v.max).toFixed(2)} · ${v.n} px` : '';
    },
    nValores(im) { return this.parcelas.filter((p) => this.valor(im, p) !== null).length; },
    faltantes(im) { return this.conGeometria.filter((p) => this.valor(im, p) === null); },
    mediaImagen(im) {
      const v = this.parcelas.map((p) => this.valor(im, p)).filter((x) => x !== null);
      return v.length ? v.reduce((a, b) => a + b, 0) / v.length : null;
    },
    delta(p) {
      const v = this.imagenes.map((im) => this.valor(im, p)).filter((x) => x !== null);
      return v.length > 1 ? v[v.length - 1] - v[0] : null;
    },
    estiloValor(v) { return v === null ? {} : { background: colorNdvi(v), color: tintaNdvi(v) }; },
    nombreTrat(p) {
      return p.tratamiento ? p.tratamiento.codigo : (this.tratamientos.find((t) => t.id === p.tratamiento_id) || {}).codigo || '—';
    },
    fechaTxt(f) {
      const [y, m, d] = String(f).slice(0, 10).split('-');
      return `${d}/${m}/${y}`;
    },
    fechaCorta(f) {
      const [y, m, d] = String(f).slice(0, 10).split('-');
      return `${d}/${m}/${y.slice(2)}`;
    },
    mensaje(e) {
      const r = e && e.response;
      if (r && r.status === 422 && r.data.errors) return Object.values(r.data.errors).flat().join(' ');
      if (r && r.status === 413) return 'El archivo excede el limite de carga del servidor.';
      return (r && r.data && r.data.message) || e.message || 'No se pudo completar la operacion.';
    },
    falla(e) { this.$swal.fire({ icon: 'error', title: 'Error', text: this.mensaje(e) }); },
    confirmar(title, text) {
      return this.$swal.fire({
        title, text, icon: 'warning', showCancelButton: true, confirmButtonText: 'Si, continuar', cancelButtonText: 'Cancelar',
        confirmButtonColor: '#FF6B00', cancelButtonColor: '#CED2D5', focusCancel: true,
      }).then((r) => r.isConfirmed);
    },
    toast(title, text) {
      this.$swal.fire({ toast: true, position: 'top-end', icon: 'success', title, text, timer: 2600, showConfirmButton: false });
    },
  },
};
</script>

<style scoped>
.ni { margin-bottom: 18px; }
.ni-info { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-bottom: 10px; padding: 6px 10px; border-radius: 8px; background: #eef8df; color: #3f7a12; font-size: 12.5px; }
.ni-geo { margin-bottom: 12px; padding: 8px 12px; border: 1px solid #d6e4f3; border-radius: 10px; background: #f4f8fd; font-size: 12.5px; }
.ni-geo-tit { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; color: #1d4f80; }
.ni-geo-fila { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 6px 0; border-top: 1px solid #e3ecf6; }
.ni-geo-nom { flex: 1 1 200px; overflow: hidden; color: var(--cengi-muted); text-overflow: ellipsis; white-space: nowrap; }
.ni-ok { color: #3f7a12; font-weight: 600; }
.ni-origen { background: #eef4fb; color: #1d4f80; }
.ni-ayuda { margin-bottom: 6px; color: var(--cengi-muted); font-size: 12px; }
.ni-galeria { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; }
.ni-card { display: flex; flex-direction: column; overflow: hidden; border: 1px solid var(--cengi-border); border-radius: 10px; background: #fff; }
.ni-thumb { height: 110px; background: #f3f5f1 center / contain no-repeat; border-bottom: 1px solid var(--cengi-border); }
.ni-card-body { display: flex; flex-direction: column; gap: 3px; padding: 8px 10px; }
.ni-card-body strong { color: var(--cengi-primary-deep); font-size: 13px; }
.ni-card-body small { color: var(--cengi-muted); font-size: 11.5px; }
.ni-card-stats { display: flex; align-items: center; gap: 8px; }
.ni-falta { color: #b84d00 !important; }
.ni-card-acc { display: flex; gap: 6px; justify-content: flex-end; margin-top: auto; padding: 0 10px 8px; }
.ni-val { display: inline-block; min-width: 44px; padding: 2px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 700; text-align: center; }
.ni-input { width: 72px; margin: 0 auto; font-weight: 700; text-align: center; }
.ni-delta { font-size: 11.5px; font-weight: 700; white-space: nowrap; }
.ni-delta.is-sube { color: #3f7a12; }
.ni-delta.is-baja { color: #b84d00; }
.ni-leyenda { display: inline-flex; align-items: center; gap: 6px; color: var(--cengi-muted); font-size: 11px; }
.ni-leyenda i { display: inline-block; width: 140px; height: 10px; border-radius: 999px; }
.ni-tabla th, .ni-tabla td { white-space: nowrap; }
</style>
