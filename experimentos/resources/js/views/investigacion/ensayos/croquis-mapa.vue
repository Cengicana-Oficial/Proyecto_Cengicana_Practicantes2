<template>
  <div class="cq" ref="raiz" :class="{ 'is-full': pantallaCompleta }">
    <!-- Sin croquis -->
    <div v-if="!croquis" class="cq-vacio">
      <i class="fas fa-map"></i>
      <strong>Croquis georreferenciado</strong>
      <p>Suba el PDF georreferenciado del ensayo (GeoPDF exportado de Avenza Maps, QGIS o ArcGIS) para recorrer las parcelas en campo con el GPS del dispositivo.</p>
      <label v-if="puedeEditar" class="btn btn-success btn-sm mb-0" :class="{ disabled: subiendo }">
        <i :class="subiendo ? 'fas fa-spinner fa-spin' : 'fas fa-file-upload'"></i> {{ subiendo ? estadoSubida : 'Cargar PDF / imagen' }}
        <input type="file" accept=".pdf,.png,.jpg,.jpeg" hidden :disabled="subiendo" @change="subir">
      </label>
    </div>

    <template v-else>
      <!-- Barra superior -->
      <div class="cq-bar">
        <div class="cq-file">
          <i class="fas fa-file-pdf"></i>
          <span :title="croquis.nombre">{{ croquis.nombre }}</span>
          <em v-if="georef" class="cq-badge">{{ georef.metodo === 'geopdf' ? 'GeoPDF' : 'Georref. manual' }}</em>
          <em v-else class="cq-badge is-warn">Sin georreferenciar</em>
        </div>
        <div class="cq-tools">
          <button type="button" class="btn btn-secondary btn-sm" @click="$emit('ver-mapa')" title="Ver la misma navegacion sobre el mapa real">
            <i class="fas fa-map-marked-alt"></i> Mapa GPS
          </button>
          <button type="button" class="btn btn-secondary btn-sm" :class="{ active: modo === 'consulta' }" @click="cambiarModo('consulta')" :disabled="!transf" title="Tocar un punto para saber a que parcela corresponde">
            <i class="fas fa-question-circle"></i>
          </button>
          <button type="button" class="btn btn-secondary btn-sm" @click="pantalla" :title="pantallaCompleta ? 'Salir de pantalla completa' : 'Pantalla completa'">
            <i :class="pantallaCompleta ? 'fas fa-compress' : 'fas fa-expand'"></i>
          </button>
          <div v-if="puedeEditar" class="dropdown d-inline-block">
            <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-toggle="dropdown"><i class="fas fa-draw-polygon"></i> Parcelas</button>
            <div class="dropdown-menu dropdown-menu-right">
              <button class="dropdown-item" type="button" :disabled="!transf || !esPdf || !parcelas.length" @click="detectar"><i class="fas fa-magic"></i> Detectar parcelas en el PDF</button>
              <button class="dropdown-item" type="button" :disabled="!transf" @click="cambiarModo('rejilla')"><i class="fas fa-th"></i> Generar rejilla (4 esquinas)</button>
              <button class="dropdown-item" type="button" :disabled="!transf" @click="cambiarModo('dibujar')"><i class="fas fa-draw-polygon"></i> Dibujar parcela</button>
              <label class="dropdown-item mb-0" :class="{ disabled: !parcelas.length }"><i class="fas fa-file-import"></i> Importar GeoJSON / KML
                <input type="file" accept=".geojson,.json,.kml" hidden @change="importarPoligonos">
              </label>
              <div class="dropdown-divider"></div>
              <button class="dropdown-item" type="button" @click="cambiarModo('georef')"><i class="fas fa-map-pin"></i> Georreferenciar manualmente</button>
              <label class="dropdown-item mb-0"><i class="fas fa-sync-alt"></i> Reemplazar croquis
                <input type="file" accept=".pdf,.png,.jpg,.jpeg" hidden @change="subir">
              </label>
              <button class="dropdown-item text-danger" type="button" :disabled="!conGeometria" @click="borrarPoligonos"><i class="fas fa-eraser"></i> Borrar poligonos</button>
              <button class="dropdown-item text-danger" type="button" @click="eliminarCroquis"><i class="fas fa-trash"></i> Eliminar croquis</button>
            </div>
          </div>
        </div>
      </div>

      <!-- GPS y navegacion (compartidos con el Mapa GPS) -->
      <gps-barra
        v-if="transf"
        :gps-activo="gpsActivo" :gps-error="gpsError" :pos="pos" :seguir="seguir" :nav="nav"
        :parcelas="parcelas" :destino-id="destinoId" :recorrido-m="recorridoM"
        :color-trat="colorTrat" :nombre-trat="nombreTrat"
        @alternar-gps="$emit('alternar-gps')" @seguir="$emit('seguir', $event)"
        @destino="$emit('destino', $event)" @limpiar-recorrido="$emit('limpiar-recorrido')"
      ></gps-barra>
      <p class="cq-fuera" v-if="pos && transf && !enCroquis"><i class="fas fa-info-circle"></i> Su ubicacion esta fuera del area del croquis.</p>

      <!-- Parcelas del ensayo que aun no tienen ubicacion en el croquis -->
      <div class="cq-pendientes" v-if="transf && modo === 'ver' && sinUbicar.length">
        <i class="fas fa-exclamation-circle"></i>
        <span>
          <b>{{ sinUbicar.length }} de {{ parcelas.length }} parcelas sin ubicar en el croquis</b>
          ({{ resumenCodigos(sinUbicar) }}). No aparecen en el Mapa GPS ni se puede navegar hasta ellas.
        </span>
        <template v-if="puedeEditar">
          <button v-if="esPdf" type="button" class="btn btn-success btn-sm" @click="detectar"><i class="fas fa-magic"></i> Detectar en el PDF</button>
          <button type="button" class="btn btn-secondary btn-sm" @click="cambiarModo('rejilla')"><i class="fas fa-th"></i> Rejilla</button>
          <button type="button" class="btn btn-secondary btn-sm" @click="cambiarModo('dibujar')"><i class="fas fa-draw-polygon"></i> Dibujar</button>
        </template>
      </div>

      <!-- Ayuda del modo de edicion -->
      <div class="cq-modo" v-if="modo !== 'ver'">
        <template v-if="modo === 'consulta'"><i class="fas fa-question-circle"></i> Toque un punto del croquis para ver la parcela y sus coordenadas.</template>
        <template v-else-if="modo === 'georef'">
          <i class="fas fa-map-pin"></i> Toque un punto reconocible del croquis e indique su latitud/longitud ({{ puntosGeoref.length }} de min. 3).
          <button type="button" class="btn btn-success btn-sm" :disabled="puntosGeoref.length < 3" @click="guardarGeoref">Guardar</button>
          <button type="button" class="btn btn-secondary btn-sm" :disabled="!puntosGeoref.length" @click="puntosGeoref.pop(); pintarTemporal()">Deshacer</button>
        </template>
        <template v-else-if="modo === 'dibujar'">
          <select class="form-control form-control-sm cq-sel" v-model="parcelaDibujo">
            <option v-for="p in parcelas" :key="p.id" :value="p.id">{{ p.codigo }} · {{ nombreTrat(p) }} · Rep. {{ p.repeticion }}{{ p.geometria ? ' ✓' : '' }}</option>
          </select>
          Toque las esquinas ({{ vertices.length }})
          <button type="button" class="btn btn-success btn-sm" :disabled="vertices.length < 3" @click="guardarDibujo">Guardar</button>
          <button type="button" class="btn btn-secondary btn-sm" :disabled="!vertices.length" @click="vertices.pop(); pintarTemporal()">Deshacer</button>
        </template>
        <template v-else-if="modo === 'detectar'">
          <i class="fas fa-magic"></i>
          <template v-if="detectando">Buscando las etiquetas de las parcelas en el PDF...</template>
          <template v-else-if="detectadas.length">
            Se encontraron <b>{{ detectadas.length }} de {{ parcelas.length }}</b> parcelas en el croquis
            <span v-if="detectadas.some((x) => !x.exacto)">({{ detectadas.filter((x) => !x.exacto).length }} con contorno estimado)</span>.
            <span v-if="noDetectadas.length" class="cq-warn">No estan dibujadas en el PDF: {{ resumenCodigos(noDetectadas) }}.</span>
            <button type="button" class="btn btn-success btn-sm" @click="guardarDetectadas">Guardar {{ detectadas.length }}</button>
          </template>
          <template v-else>No se encontraron etiquetas con los codigos de las parcelas ({{ resumenCodigos(parcelas) }}) en el PDF. Use la rejilla o el dibujo.</template>
        </template>
        <template v-else-if="modo === 'rejilla'">
          <i class="fas fa-th"></i>
          <template v-if="vertices.length < 4">Toque la esquina <b>{{ esquinas[vertices.length] }}</b> del ensayo ({{ vertices.length + 1 }}/4).</template>
          <template v-else>Rejilla de {{ repsOrden.length }} rep. × {{ colsRejilla }} parcelas lista.</template>
          <button type="button" class="btn btn-success btn-sm" :disabled="vertices.length < 4" @click="guardarRejilla">Guardar rejilla</button>
          <button type="button" class="btn btn-secondary btn-sm" :disabled="!vertices.length" @click="vertices.pop(); pintarTemporal()">Deshacer</button>
        </template>
        <button type="button" class="btn btn-link btn-sm" @click="cambiarModo('ver')">Cerrar</button>
      </div>

      <div class="cq-mapa-wrap">
        <div class="cq-mapa" ref="mapa"></div>
        <div class="cq-cargando" v-if="cargando"><i class="fas fa-spinner fa-spin"></i> {{ estadoCarga }}</div>
        <div class="cq-sin-georef" v-else-if="!georef && modo !== 'georef'">
          <p><i class="fas fa-info-circle"></i> Este archivo no trae georreferenciado (no es GeoPDF).</p>
          <button v-if="puedeEditar" type="button" class="btn btn-success btn-sm" @click="cambiarModo('georef')">Georreferenciar con puntos de control</button>
        </div>
      </div>

      <div class="cq-leyenda" v-if="tratamientos.length">
        <span v-for="t in tratamientos" :key="t.id"><i :style="{ background: colorTrat(t.codigo) }"></i>{{ t.codigo }}</span>
        <span class="cq-muted">{{ conGeometria }} de {{ parcelas.length }} parcelas delimitadas</span>
        <span class="cq-muted" v-if="transf">· escala ≈ {{ (transf.mPorPx * 100).toFixed(1) }} m / 100 px</span>
      </div>
    </template>
  </div>
</template>

<script>
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import * as geoUtil from './geo-util';
import { obtenerImagen, transformacion } from './croquis-cache';
import GpsBarra from './gps-barra.vue';

const ESQUINAS = ['Rep. 1 – primera parcela', 'Rep. 1 – ultima parcela', 'ultima Rep. – ultima parcela', 'ultima Rep. – primera parcela'];

export default {
  name: 'CroquisMapa',
  components: { GpsBarra },
  props: {
    ensayoId: { type: Number, required: true },
    croquis: { type: Object, default: null },
    parcelas: { type: Array, default: () => [] },
    tratamientos: { type: Array, default: () => [] },
    storageUrl: { type: String, default: '' },
    puedeEditar: { type: Boolean, default: false },
    colorTrat: { type: Function, default: () => '#73BC25' },
    // GPS y navegacion compartidos por el detalle del ensayo (ver detalle.vue).
    gpsActivo: { type: Boolean, default: false },
    gpsError: { type: String, default: null },
    pos: { type: Object, default: null },
    seguir: { type: Boolean, default: true },
    nav: { type: Object, default: () => ({}) },
    destinoId: { type: Number, default: null },
    recorrido: { type: Array, default: () => [] },
    recorridoM: { type: Number, default: 0 },
  },
  data() {
    return {
      geo: geoUtil,
      img: null,
      transf: null,
      cargando: false,
      estadoCarga: '',
      subiendo: false,
      estadoSubida: '',
      modo: 'ver',
      vertices: [],
      puntosGeoref: [],
      parcelaDibujo: null,
      esquinas: ESQUINAS,
      pantallaCompleta: false,
      claveCargada: null,
      detectando: false,
      detectadas: [],
    };
  },
  computed: {
    georef() { return this.croquis && this.croquis.georef; },
    esPdf() { return !!this.croquis && /pdf/i.test(this.croquis.mime); },
    sinUbicar() { return this.parcelas.filter((p) => !p.geometria); },
    noDetectadas() { return this.parcelas.filter((p) => !this.detectadas.some((d) => d.parcela.id === p.id)); },
    conGeometria() { return this.parcelas.filter((p) => p.geometria).length; },
    repsOrden() { return [...new Set(this.parcelas.map((p) => p.repeticion))].sort((a, b) => a - b); },
    // Columnas de la rejilla: parcelas por bloque en orden fisico (posicion); los
    // tratamientos pueden estar aleatorizados dentro de cada repeticion.
    bloquesRejilla() {
      const b = {};
      this.parcelas.forEach((p) => { (b[p.repeticion] = b[p.repeticion] || []).push(p); });
      Object.values(b).forEach((l) => l.sort((x, y) => (x.posicion || 999) - (y.posicion || 999) || x.id - y.id));
      return b;
    },
    colsRejilla() { return Math.max(0, ...Object.values(this.bloquesRejilla).map((l) => l.length)); },
    enCroquis() {
      if (!this.pos || !this.transf || !this.img) return false;
      const px = this.transf.aPx(this.pos.lat, this.pos.lng);
      return px.x >= 0 && px.y >= 0 && px.x <= this.img.ancho && px.y <= this.img.alto;
    },
    parcelaActualId() { return this.nav.parcela ? this.nav.parcela.id : null; },
  },
  watch: {
    croquis: { handler() { this.cargar(); }, deep: true },
    parcelas: { handler() { this.pintarParcelas(); this.pintarGps(); }, deep: true },
    parcelaActualId() { this.pintarParcelas(); },
    destinoId() { this.pintarParcelas(); this.pintarGps(); },
    pos() {
      this.pintarGps();
      if (this.seguir) this.centrarGps();
    },
    recorrido() { this.pintarGps(); },
    seguir(v) { if (v) this.centrarGps(); },
  },
  mounted() {
    document.addEventListener('fullscreenchange', this.onFullscreen);
    this.cargar();
  },
  beforeDestroy() {
    document.removeEventListener('fullscreenchange', this.onFullscreen);
    this.destruirMapa();
  },
  methods: {
    /** Modulo con pdf.js/pdf-lib: solo para leer un GeoPDF al subirlo. */
    moduloPdf() {
      return import(/* webpackChunkName: "geopdf" */ './geopdf');
    },
    /** Hace visible el poligono de una parcela (desde el Mapa GPS o la navegacion). */
    enfocarParcela(id) {
      const p = this.parcelas.find((x) => x.id === id);
      if (!this.mapa || !this.transf || !p || !p.geometria) return;
      const ll = p.geometria.coordinates[0].map(([lng, lat]) => this.ll(lat, lng));
      this.mapa.fitBounds(L.latLngBounds(ll).pad(1.5));
    },

    /* ---------------- carga y render ---------------- */
    async cargar() {
      if (!this.croquis) { this.destruirMapa(); return; }
      const clave = `${this.croquis.path}#${this.croquis.pagina}#${JSON.stringify(this.croquis.georef)}`;
      if (clave === this.claveCargada && this.mapa) { this.pintarParcelas(); return; }
      this.cargando = true;
      try {
        this.estadoCarga = /pdf/i.test(this.croquis.mime) ? 'Dibujando croquis PDF...' : 'Cargando croquis...';
        // Imagen compartida con el Mapa GPS (croquis-cache.js): se dibuja una sola vez.
        this.img = await obtenerImagen(this.croquis, this.storageUrl, window.innerWidth < 768 ? 2000 : 2800);
        this.transf = transformacion(this.croquis, this.img);
        this.claveCargada = clave;
        this.$nextTick(this.montarMapa);
      } catch (e) {
        this.$swal.fire({ icon: 'error', title: 'No se pudo abrir el croquis', text: 'Verifique que el archivo sea un PDF o imagen valido.' });
      } finally {
        this.cargando = false;
      }
    },
    montarMapa() {
      if (!this.$refs.mapa || !this.img) return;
      const { ancho, alto } = this.img;
      const limites = [[-alto, 0], [0, ancho]];
      if (!this.mapa) {
        this.mapa = L.map(this.$refs.mapa, {
          crs: L.CRS.Simple, minZoom: -6, maxZoom: 4, zoomSnap: 0.25, zoomDelta: 0.5,
          attributionControl: false, doubleClickZoom: false,
        });
        this.mapa.on('click', this.onClick);
        this.capaParcelas = L.layerGroup().addTo(this.mapa);
        this.capaTemporal = L.layerGroup().addTo(this.mapa);
        this.capaGps = L.layerGroup().addTo(this.mapa);
        this.mapa.on('popupopen', this.onPopup);
      }
      if (this.overlay) this.overlay.remove();
      this.overlay = L.imageOverlay(this.img.url, limites).addTo(this.mapa);
      this.overlay.bringToBack();
      this.mapa.setMaxBounds(L.latLngBounds(limites).pad(0.5));
      this.mapa.fitBounds(limites);
      this.mapa.invalidateSize();
      this.pintarParcelas();
      this.pintarTemporal();
      this.pintarGps();
    },
    destruirMapa() {
      if (this.mapa) { this.mapa.remove(); this.mapa = null; this.overlay = null; }
    },
    invalidar() {
      if (this.mapa) setTimeout(() => this.mapa && this.mapa.invalidateSize(), 120);
    },
    ll(lat, lng) {
      const p = this.transf.aPx(lat, lng);
      return L.latLng(-p.y, p.x);
    },
    aGeoDesdeLL(latlng) {
      return this.transf.aGeo(latlng.lng, -latlng.lat);
    },

    /* ---------------- parcelas ---------------- */
    nombreTrat(p) {
      return p.tratamiento ? p.tratamiento.codigo : (this.tratamientos.find((t) => t.id === p.tratamiento_id) || {}).codigo || '—';
    },
    pintarParcelas() {
      if (!this.mapa || !this.capaParcelas) return;
      this.capaParcelas.clearLayers();
      if (!this.transf) return;
      const actual = this.parcelaActualId;
      this.parcelas.forEach((p) => {
        if (!p.geometria || !p.geometria.coordinates) return;
        const color = this.colorTrat(this.nombreTrat(p));
        const esActual = p.id === actual;
        const esDestino = p.id === this.destinoId;
        const poly = L.polygon(p.geometria.coordinates[0].map(([lng, lat]) => this.ll(lat, lng)), {
          color: esActual ? '#1f5632' : esDestino ? '#FF6B00' : color,
          weight: esActual || esDestino ? 4 : 2,
          dashArray: esDestino && !esActual ? '8 5' : null,
          fillColor: color, fillOpacity: esActual ? 0.55 : esDestino ? 0.4 : 0.22,
          className: esActual ? 'cq-poly-actual' : '',
        });
        poly.bindTooltip(p.codigo, { permanent: true, direction: 'center', className: 'cq-label' });
        poly.bindPopup(this.popupParcela(p));
        poly.on('click', (ev) => { if (this.modo !== 'ver') { L.DomEvent.stopPropagation(ev); this.onClick(ev); } });
        poly.addTo(this.capaParcelas);
      });
    },
    parcelaEn(lat, lng) {
      return geoUtil.ubicarEnParcelas(lat, lng, this.parcelas);
    },
    popupParcela(p) {
      const destino = p.id === this.destinoId;
      return `<b>${this.esc(p.codigo)}</b><br>${this.esc(this.nombreTrat(p))} · Rep. ${p.repeticion}`
        + `${p.area ? `<br>${Number(p.area)} m²` : ''}${p.notas ? `<br><i>${this.esc(p.notas)}</i>` : ''}`
        + `<div class="cq-pop-acc"><button type="button" class="btn btn-sm ${destino ? 'btn-secondary' : 'btn-success'}" data-navegar="${destino ? '' : p.id}">`
        + `<i class="fas fa-route"></i> ${destino ? 'Quitar destino' : 'Navegar aqui'}</button></div>`;
    },
    onPopup(ev) {
      const btn = ev.popup.getElement() && ev.popup.getElement().querySelector('[data-navegar]');
      if (!btn) return;
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-navegar');
        this.$emit('destino', id ? Number(id) : null);
        this.mapa.closePopup();
      });
    },

    /* ---------------- interaccion ---------------- */
    cambiarModo(m) {
      this.modo = this.modo === m && m !== 'ver' ? 'ver' : m;
      this.vertices = [];
      this.puntosGeoref = this.modo === 'georef' && this.georef && this.georef.metodo === 'manual' ? this.georef.puntos.slice() : [];
      if (this.modo === 'dibujar' && !this.parcelaDibujo) {
        const sin = this.parcelas.find((p) => !p.geometria) || this.parcelas[0];
        this.parcelaDibujo = sin ? sin.id : null;
      }
      this.pintarTemporal();
    },
    onClick(ev) {
      if (this.modo === 'ver' || !this.img) return;
      const { lat, lng } = ev.latlng;
      if (this.modo === 'georef') { this.pedirCoordenada(lng / this.img.ancho, -lat / this.img.alto); return; }
      if (!this.transf) return;
      const g = this.aGeoDesdeLL(ev.latlng);
      if (this.modo === 'consulta') {
        const r = this.parcelaEn(g.lat, g.lng);
        const txt = r.parcela
          ? `<b>${r.parcela.codigo}</b> · ${this.nombreTrat(r.parcela)} · Rep. ${r.parcela.repeticion}`
          : r.cercana ? `Fuera de parcelas · cercana ${r.cercana.codigo} a ${Math.round(r.distancia)} m` : 'Sin parcelas delimitadas';
        L.popup().setLatLng(ev.latlng).setContent(`${txt}<br><small>${g.lat.toFixed(6)}, ${g.lng.toFixed(6)}</small>`).openOn(this.mapa);
        return;
      }
      if (this.modo === 'detectar' || (this.modo === 'rejilla' && this.vertices.length >= 4)) return;
      this.vertices.push(g);
      this.pintarTemporal();
    },
    pintarTemporal() {
      if (!this.capaTemporal) return;
      this.capaTemporal.clearLayers();
      const marca = (latlng, txt, color) => L.marker(latlng, {
        icon: L.divIcon({ className: '', iconAnchor: [9, 9], html: `<div class="cq-pt" style="background:${color}">${txt}</div>` }),
      }).addTo(this.capaTemporal);
      if (this.modo === 'georef' && this.img) {
        this.puntosGeoref.forEach((p, i) => marca(L.latLng(-p.y * this.img.alto, p.x * this.img.ancho), i + 1, '#FF6B00'));
        return;
      }
      if (!this.transf) return;
      const lls = this.vertices.map((v) => this.ll(v.lat, v.lng));
      lls.forEach((l, i) => marca(l, i + 1, '#FF6B00'));
      if (this.modo === 'dibujar' && lls.length >= 2) L.polygon(lls, { color: '#FF6B00', weight: 2, dashArray: '6 4', fillOpacity: 0.15 }).addTo(this.capaTemporal);
      if (this.modo === 'rejilla' && lls.length >= 2) L.polygon(lls, { color: '#FF6B00', weight: 2, dashArray: '6 4', fill: false }).addTo(this.capaTemporal);
      if (this.modo === 'detectar') {
        this.detectadas.forEach((d) => L.polygon(d.anillo.slice(0, -1).map(([lng, lat]) => this.ll(lat, lng)), {
          color: '#FF6B00', weight: 2, dashArray: d.exacto ? null : '6 4',
          fillColor: this.colorTrat(this.nombreTrat(d.parcela)), fillOpacity: 0.45,
        }).bindTooltip(`${d.parcela.codigo} · ${this.nombreTrat(d.parcela)}`, { permanent: true, direction: 'center', className: 'cq-label' }).addTo(this.capaTemporal));
      }
      if (this.modo === 'rejilla' && this.vertices.length === 4) {
        this.celdasRejilla().forEach((c) => L.polygon(c.anillo.slice(0, -1).map(([lng, lat]) => this.ll(lat, lng)), {
          color: this.colorTrat(c.trat), weight: 1.5, fillColor: this.colorTrat(c.trat), fillOpacity: 0.3,
        }).bindTooltip(c.parcela ? c.parcela.codigo : '?', { permanent: true, direction: 'center', className: 'cq-label' }).addTo(this.capaTemporal));
      }
    },

    /* ---------------- deteccion en el PDF ---------------- */
    resumenCodigos(lista) {
      const c = lista.map((p) => p.codigo);
      return c.length > 8 ? `${c.slice(0, 8).join(', ')}… y ${c.length - 8} mas` : c.join(', ');
    },
    async detectar() {
      if (!this.transf || !this.esPdf) return;
      this.modo = 'detectar';
      this.vertices = [];
      this.detectadas = [];
      this.detectando = true;
      this.pintarTemporal();
      try {
        const [geo, buf] = await Promise.all([
          import(/* webpackChunkName: "geopdf" */ './geopdf'),
          fetch(`${this.storageUrl}/${this.croquis.path}`).then((r) => { if (!r.ok) throw new Error(r.status); return r.arrayBuffer(); }),
        ]);
        const res = await geo.detectarParcelas(buf, this.croquis.pagina, this.parcelas.map((p) => p.codigo));
        const aGeo = ([x, y]) => { const g = this.transf.aGeo(x * this.img.ancho, y * this.img.alto); return [g.lng, g.lat]; };
        this.detectadas = res.map((r) => {
          const k = geo.normalizarCodigo(r.codigo);
          const parcela = this.parcelas.find((p) => geo.normalizarCodigo(p.codigo) === k);
          return parcela && { parcela, exacto: r.exacto, anillo: this.geo.cerrar(r.anillo.map(aGeo)) };
        }).filter(Boolean);
      } catch (e) {
        this.$swal.fire({ icon: 'error', title: 'No se pudo leer el PDF', text: String(e.message || e) });
        this.modo = 'ver';
      } finally {
        this.detectando = false;
        this.pintarTemporal();
      }
    },
    guardarDetectadas() {
      const cambios = this.detectadas.map((d) => ({ id: d.parcela.id, geometria: { type: 'Polygon', coordinates: [d.anillo] } }));
      const reemplaza = this.detectadas.filter((d) => d.parcela.geometria).length;
      const ok = reemplaza
        ? this.confirmar('Guardar parcelas detectadas', `Se reemplazara el contorno actual de ${reemplaza} parcela(s) por el del PDF.`)
        : Promise.resolve(true);
      ok.then((si) => si && this.guardarGeometrias(cambios, `${cambios.length} parcelas ubicadas desde el PDF`).then(() => this.cambiarModo('ver')));
    },

    /* ---------------- rejilla ---------------- */
    celdasRejilla() {
      // Interpolacion bilineal en pixeles entre las 4 esquinas (orden: R1-P1, R1-Pn, Rn-Pn, Rn-P1 por posicion).
      const [a, b, c, d] = this.vertices.map((v) => this.transf.aPx(v.lat, v.lng));
      const nT = this.colsRejilla;
      const reps = this.repsOrden;
      const nR = reps.length;
      const punto = (u, v) => {
        const top = { x: a.x + (b.x - a.x) * u, y: a.y + (b.y - a.y) * u };
        const bot = { x: d.x + (c.x - d.x) * u, y: d.y + (c.y - d.y) * u };
        const p = { x: top.x + (bot.x - top.x) * v, y: top.y + (bot.y - top.y) * v };
        const g = this.transf.aGeo(p.x, p.y);
        return [g.lng, g.lat];
      };
      const celdas = [];
      for (let r = 0; r < nR; r++) {
        for (let t = 0; t < nT; t++) {
          const u0 = t / nT; const u1 = (t + 1) / nT; const v0 = r / nR; const v1 = (r + 1) / nR;
          const parcela = (this.bloquesRejilla[reps[r]] || [])[t] || null;
          celdas.push({
            trat: parcela ? this.nombreTrat(parcela) : null,
            parcela,
            anillo: [punto(u0, v0), punto(u1, v0), punto(u1, v1), punto(u0, v1), punto(u0, v0)],
          });
        }
      }
      return celdas;
    },
    guardarRejilla() {
      const cambios = this.celdasRejilla().filter((c) => c.parcela)
        .map((c) => ({ id: c.parcela.id, geometria: { type: 'Polygon', coordinates: [c.anillo] } }));
      if (!cambios.length) { this.$swal.fire({ icon: 'info', title: 'Sin parcelas', text: 'Genere primero las parcelas del ensayo.' }); return; }
      this.guardarGeometrias(cambios, `${cambios.length} parcelas delimitadas`).then(() => this.cambiarModo('ver'));
    },
    guardarDibujo() {
      const anillo = this.geo.cerrar(this.vertices.map((v) => [v.lng, v.lat]));
      const p = this.parcelas.find((x) => x.id === this.parcelaDibujo);
      this.guardarGeometrias([{ id: this.parcelaDibujo, geometria: { type: 'Polygon', coordinates: [anillo] } }], `Parcela ${p ? p.codigo : ''} delimitada`)
        .then(() => {
          this.vertices = [];
          const sig = this.parcelas.find((x) => !x.geometria && x.id !== this.parcelaDibujo);
          if (sig) this.parcelaDibujo = sig.id;
          this.pintarTemporal();
        });
    },
    importarPoligonos(ev) {
      const f = ev.target.files[0];
      ev.target.value = '';
      if (!f) return;
      f.text().then((texto) => {
        const geo = geoUtil;
        let polys;
        try { polys = geo.leerPoligonos(texto, f.name); } catch (e) { polys = []; }
        if (!polys.length) { this.$swal.fire({ icon: 'warning', title: 'Sin poligonos', text: 'El archivo no contiene poligonos GeoJSON/KML validos.' }); return; }
        const num = (s) => { const m = String(s || '').match(/\d+/); return m ? Number(m[0]) : null; };
        const cambios = [];
        const usados = new Set();
        polys.forEach((poly) => {
          const candidatos = [poly.props.codigo, poly.props.Codigo, poly.props.parcela, poly.props.Parcela, poly.props.PARCELA, poly.nombre, poly.props.name, poly.props.Name, poly.props.id];
          const p = this.parcelas.find((x) => !usados.has(x.id) && candidatos.some((c) => c !== undefined && c !== null && c !== ''
            && (String(c).trim().toLowerCase() === x.codigo.toLowerCase() || (num(c) !== null && num(c) === num(x.codigo)))));
          if (p) { usados.add(p.id); cambios.push({ id: p.id, geometria: { type: 'Polygon', coordinates: [poly.anillo] } }); }
        });
        if (!cambios.length) {
          this.$swal.fire({ icon: 'warning', title: 'Sin coincidencias', text: 'Ningun poligono coincide con los codigos de parcela (use la propiedad "codigo" o "name", p. ej. P-01).' });
          return;
        }
        this.guardarGeometrias(cambios, `${cambios.length} de ${polys.length} poligonos importados`);
      });
    },
    borrarPoligonos() {
      this.confirmar('Borrar poligonos', `Se quitaran los poligonos de ${this.conGeometria} parcela(s). Las parcelas y sus datos no se eliminan.`)
        .then((ok) => ok && this.guardarGeometrias(this.parcelas.filter((p) => p.geometria).map((p) => ({ id: p.id, geometria: null })), 'Poligonos borrados'));
    },
    guardarGeometrias(parcelas, mensaje) {
      return window.axios.put(`/investigacion/ensayos/${this.ensayoId}/parcelas/geometria`, { parcelas })
        .then(({ data }) => { this.$emit('actualizado', data); this.toast(mensaje); })
        .catch(this.falla);
    },

    /* ---------------- georreferenciado manual ---------------- */
    pedirCoordenada(x, y) {
      const gps = this.pos ? `${this.pos.lat.toFixed(6)}, ${this.pos.lng.toFixed(6)}` : '';
      this.$swal.fire({
        title: `Punto de control ${this.puntosGeoref.length + 1}`,
        text: 'Latitud, longitud del punto tocado (grados decimales)',
        input: 'text', inputValue: gps, inputPlaceholder: '14.290700, -91.054800',
        showCancelButton: true, confirmButtonText: 'Agregar', cancelButtonText: 'Cancelar', confirmButtonColor: '#73BC25',
        inputValidator: (v) => (/^\s*-?\d+(\.\d+)?\s*[,;\s]\s*-?\d+(\.\d+)?\s*$/.test(v) ? null : 'Formato: 14.2907, -91.0548'),
      }).then((r) => {
        if (!r.isConfirmed) return;
        const [lat, lng] = r.value.split(/[,;\s]+/).filter(Boolean).map(Number);
        if (Math.abs(lat) > 90 || Math.abs(lng) > 180) return;
        this.puntosGeoref.push({ x, y, lat, lng });
        this.pintarTemporal();
      });
    },
    guardarGeoref() {
      window.axios.put(`/investigacion/ensayos/${this.ensayoId}/croquis`, { georef: { metodo: 'manual', puntos: this.puntosGeoref } })
        .then(({ data }) => { this.$emit('actualizado', data); this.toast('Croquis georreferenciado'); this.cambiarModo('ver'); })
        .catch(this.falla);
    },

    /* ---------------- subir / eliminar ---------------- */
    async subir(ev) {
      const f = ev.target.files[0];
      ev.target.value = '';
      if (!f) return;
      if (f.size > 64 * 1024 * 1024) { this.$swal.fire({ icon: 'warning', title: 'Archivo muy grande', text: 'El limite es 64 MB.' }); return; }
      this.subiendo = true;
      try {
        let georef = null;
        let pagina = 1;
        if (/\.pdf$/i.test(f.name) || f.type === 'application/pdf') {
          this.estadoSubida = 'Leyendo GeoPDF...';
          const geo = await this.moduloPdf();
          const info = await geo.leerGeoPdf(await f.arrayBuffer());
          georef = info.georef;
          pagina = info.pagina;
        }
        this.estadoSubida = 'Subiendo...';
        const fd = new FormData();
        fd.append('archivo', f);
        fd.append('pagina', pagina);
        if (georef) fd.append('georef', JSON.stringify(georef));
        const { data } = await window.axios.post(`/investigacion/ensayos/${this.ensayoId}/croquis`, fd);
        this.$emit('actualizado', data);
        this.cambiarModo('ver');
        if (georef) {
          this.toast('Croquis cargado', `GeoPDF georreferenciado con ${georef.puntos.length} puntos (pagina ${pagina})`);
        } else {
          this.$swal.fire({
            icon: 'info', title: 'Croquis cargado sin georreferencia',
            text: 'El archivo no es un GeoPDF. Indique al menos 3 puntos de control para ubicarlo con el GPS.',
            confirmButtonColor: '#73BC25',
          });
        }
      } catch (err) {
        this.falla(err);
      } finally {
        this.subiendo = false;
      }
    },
    eliminarCroquis() {
      this.confirmar('Eliminar croquis', 'Se eliminara el PDF del croquis. Los poligonos de las parcelas se conservan.').then((ok) => {
        if (!ok) return;

        window.axios.delete(`/investigacion/ensayos/${this.ensayoId}/croquis`)
          .then(({ data }) => { this.destruirMapa(); this.img = null; this.transf = null; this.claveCargada = null; this.$emit('actualizado', data); this.toast('Croquis eliminado'); })
          .catch(this.falla);
      });
    },

    /* ---------------- GPS (posicion compartida por el detalle) ---------------- */
    pintarGps() {
      if (!this.capaGps || !this.transf) return;
      this.capaGps.clearLayers();
      // Recorrido realizado
      if (this.recorrido.length > 1) {
        L.polyline(this.recorrido.map(([lat, lng]) => this.ll(lat, lng)), { color: '#1f6fbf', weight: 3, opacity: 0.6, dashArray: '2 6', interactive: false }).addTo(this.capaGps);
      }
      if (!this.pos) return;
      const ll = this.ll(this.pos.lat, this.pos.lng);
      // Linea hacia la parcela destino
      if (this.nav.destino && !this.nav.llegado) {
        const c = this.nav.destinoCentro;
        L.polyline([ll, this.ll(c.lat, c.lng)], { color: '#FF6B00', weight: 3, dashArray: '10 6', interactive: false }).addTo(this.capaGps);
      }
      L.circle(ll, { radius: this.pos.precision / this.transf.mPorPx, color: '#1f6fbf', weight: 1, fillColor: '#1f6fbf', fillOpacity: 0.12, interactive: false }).addTo(this.capaGps);
      L.marker(ll, { icon: L.divIcon({ className: '', iconAnchor: [10, 10], html: '<div class="cq-gps"></div>' }), interactive: false }).addTo(this.capaGps);
    },
    centrarGps() {
      if (this.mapa && this.pos && this.transf && this.enCroquis) this.mapa.panTo(this.ll(this.pos.lat, this.pos.lng));
    },

    /* ---------------- pantalla completa ---------------- */
    pantalla() {
      const el = this.$refs.raiz;
      if (document.fullscreenElement) document.exitFullscreen();
      else if (el.requestFullscreen) el.requestFullscreen().catch(() => { this.pantallaCompleta = !this.pantallaCompleta; this.invalidar(); });
      else { this.pantallaCompleta = !this.pantallaCompleta; this.invalidar(); }
    },
    onFullscreen() {
      this.pantallaCompleta = document.fullscreenElement === this.$refs.raiz;
      this.invalidar();
    },

    /* ---------------- utilidades ---------------- */
    confirmar(title, text) {
      return this.$swal.fire({
        title, text, icon: 'warning', showCancelButton: true, confirmButtonText: 'Si, continuar', cancelButtonText: 'Cancelar',
        confirmButtonColor: '#FF6B00', cancelButtonColor: '#CED2D5', focusCancel: true,
      }).then((r) => r.isConfirmed);
    },
    toast(title, text) {
      this.$swal.fire({ toast: true, position: 'top-end', icon: 'success', title, text, timer: 2600, showConfirmButton: false });
    },
    falla(err) {
      const r = err && err.response;
      let msg = r && r.data && r.data.message;
      if (r && r.status === 422 && r.data.errors) msg = Object.values(r.data.errors).flat().join(' ');
      if (r && r.status === 413) msg = 'El archivo supera el limite permitido por el servidor.';
      this.$swal.fire({ icon: 'error', title: 'No se pudo completar', text: msg || 'Ocurrio un error. Intente de nuevo.' });
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
.cq { margin-top: 16px; }
.cq.is-full { position: fixed; inset: 0; z-index: 2000; margin: 0; padding: 10px; background: #fff; display: flex; flex-direction: column; }
.cq:fullscreen { display: flex; flex-direction: column; margin: 0; padding: 10px; background: #fff; }
.cq-vacio { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 26px 16px; border: 1px dashed #CED2D5; border-radius: 10px; text-align: center; }
.cq-vacio > i { color: #73BC25; font-size: 26px; }
.cq-vacio strong { color: var(--cengi-primary-deep); }
.cq-vacio p { max-width: 520px; margin: 0 0 6px; color: var(--cengi-muted); font-size: 12.5px; }
.cq-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.cq-file { display: flex; align-items: center; gap: 8px; min-width: 0; font-size: 12.5px; font-weight: 600; }
.cq-file > i { color: #FF6B00; }
.cq-file span { overflow: hidden; max-width: 320px; text-overflow: ellipsis; white-space: nowrap; }
.cq-badge { padding: 2px 8px; border-radius: 999px; background: #eef8df; color: #3f7a12; font-size: 10.5px; font-style: normal; font-weight: 700; }
.cq-badge.is-warn { background: #fff5cc; color: #7a5d00; }
.cq-tools { display: flex; flex-wrap: wrap; gap: 6px; }
.cq-tools .btn.active { border-color: #73BC25; background: #eef8df; }
.cq-tools .dropdown-item { font-size: 12.5px; cursor: pointer; }
.cq-tools .dropdown-item i { width: 18px; color: #73BC25; }
.cq-tools .dropdown-item.text-danger i { color: inherit; }
.cq-fuera { margin: -2px 0 8px; color: #b84d00; font-size: 12px; }
.cq-estado, .cq-modo {
  display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 8px; padding: 8px 12px;
  border-radius: 8px; font-size: 12.5px;
}
.cq-estado { background: #eef4fb; color: #1d4f80; }
.cq-pre { padding: 1px 7px; border-radius: 999px; background: #fff; font-weight: 700; }
.cq-aqui { padding: 3px 10px; border-left: 4px solid var(--c); border-radius: 6px; background: #fff; color: var(--cengi-primary-deep); }
.cq-modo { background: #fff8f2; color: #6e3b00; }
.cq-pendientes {
  display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 8px; padding: 8px 12px;
  border-left: 4px solid #FF6B00; border-radius: 8px; background: #fff4ea; color: #6e3b00; font-size: 12.5px;
}
.cq-pendientes > i { color: #FF6B00; }
.cq-pendientes > span { flex: 1 1 260px; }
.cq-warn { color: #b84d00; }
.cq-sel { width: auto; max-width: 260px; }
.cq-mapa-wrap { position: relative; flex: 1; }
.cq-mapa { height: 520px; border: 1px solid var(--cengi-border); border-radius: 10px; background: #eef0ee; }
.cq.is-full .cq-mapa, .cq:fullscreen .cq-mapa { height: 100%; min-height: 300px; }
.cq.is-full .cq-mapa-wrap, .cq:fullscreen .cq-mapa-wrap { display: flex; flex-direction: column; }
.cq-cargando, .cq-sin-georef {
  position: absolute; inset: 0; z-index: 500; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;
  border-radius: 10px; background: rgba(255, 255, 255, .82); color: var(--cengi-muted); font-size: 13px; text-align: center;
}
.cq-sin-georef { inset: auto 12px 12px 12px; padding: 12px; border: 1px solid #f0dfaa; background: rgba(255, 251, 234, .96); }
.cq-sin-georef p { margin: 0; }
.cq-leyenda { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 8px; font-size: 11.5px; font-weight: 600; }
.cq-leyenda i { display: inline-block; width: 12px; height: 12px; margin-right: 4px; border-radius: 3px; vertical-align: -1px; }
.cq-muted { color: var(--cengi-muted); font-weight: 500; }
@media (max-width: 640px) {
  .cq-mapa { height: 420px; }
  .cq-file span { max-width: 180px; }
}
</style>

<style>
.cq-pop-acc { margin-top: 8px; }
.cq-pop-acc .btn { padding: 3px 10px; font-size: 11.5px; }
.cq-label { padding: 1px 5px; border: 0; border-radius: 4px; background: rgba(255, 255, 255, .85); box-shadow: none; color: #1f2a1f; font-size: 10.5px; font-weight: 700; }
.cq-label::before { display: none; }
.cq-pt { display: flex; align-items: center; justify-content: center; width: 18px; height: 18px; border: 2px solid #fff; border-radius: 50%; color: #fff; font-size: 9px; font-weight: 700; box-shadow: 0 1px 4px rgba(0, 0, 0, .35); }
.cq-gps { width: 20px; height: 20px; border: 3px solid #fff; border-radius: 50%; background: #1f6fbf; box-shadow: 0 0 0 0 rgba(31, 111, 191, .6); animation: cq-pulso 1.8s infinite; }
@keyframes cq-pulso { 0% { box-shadow: 0 0 0 0 rgba(31, 111, 191, .55); } 70% { box-shadow: 0 0 0 14px rgba(31, 111, 191, 0); } 100% { box-shadow: 0 0 0 0 rgba(31, 111, 191, 0); } }
.cq-poly-actual { animation: cq-parpadeo 1.4s ease-in-out infinite; }
@keyframes cq-parpadeo { 50% { fill-opacity: .25; } }
</style>
