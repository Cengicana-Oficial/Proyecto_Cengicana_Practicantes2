<template>
  <div class="mg">
    <div v-if="!tieneAlgo" class="mg-vacio">
      <i class="fas fa-location-arrow"></i>
      <strong>Sin ubicacion para mostrar</strong>
      <p>Agregue las coordenadas GPS del ensayo o cargue el croquis georreferenciado en "Estructura → Croquis".</p>
      <div class="mg-vacio-acc">
        <button v-if="puedeEditar" type="button" class="btn btn-success btn-sm" @click="$emit('editar')"><i class="fas fa-pen"></i> Agregar GPS al ensayo</button>
        <button type="button" class="btn btn-secondary btn-sm" @click="$emit('ver-croquis')"><i class="fas fa-th"></i> Ir al croquis</button>
      </div>
    </div>

    <template v-else>
      <div class="mg-bar">
        <span v-if="tieneGpsEnsayo" class="mg-coord"><i class="fas fa-map-marker-alt"></i> {{ Number(ensayo.lat).toFixed(5) }}, {{ Number(ensayo.lng).toFixed(5) }}</span>
        <div class="mg-tools">
          <button type="button" class="btn btn-secondary btn-sm" @click="$emit('ver-croquis')" title="Ver la misma navegacion sobre el croquis"><i class="fas fa-th"></i> Croquis</button>
          <button type="button" class="btn btn-secondary btn-sm" @click="encuadrar" title="Encuadrar el ensayo"><i class="fas fa-expand-arrows-alt"></i></button>
          <a v-if="tieneGpsEnsayo" class="btn btn-secondary btn-sm" target="_blank" rel="noopener" :href="`https://www.google.com/maps/dir/?api=1&destination=${ensayo.lat},${ensayo.lng}`" title="Llegar al ensayo con Google Maps">
            <i class="fas fa-car"></i> Llegar al ensayo
          </a>
          <button v-if="tieneGpsEnsayo" type="button" class="btn btn-secondary btn-sm" @click="copiar" title="Copiar coordenadas del ensayo"><i class="fas fa-copy"></i></button>
        </div>
      </div>

      <gps-barra
        :gps-activo="gpsActivo" :gps-error="gpsError" :pos="pos" :seguir="seguir" :nav="nav"
        :parcelas="parcelas" :destino-id="destinoId" :recorrido-m="recorridoM"
        :color-trat="colorTrat" :nombre-trat="nombreTrat"
        @alternar-gps="$emit('alternar-gps')" @seguir="$emit('seguir', $event)"
        @destino="$emit('destino', $event)" @limpiar-recorrido="$emit('limpiar-recorrido')"
      ></gps-barra>

      <!-- NDVI por fecha -->
      <div class="mg-ndvi" v-if="fechasNdvi.length">
        <label class="mb-0 mg-ndvi-tit"><i class="fas fa-satellite"></i> NDVI</label>
        <div class="btn-group btn-group-sm">
          <button type="button" class="btn btn-secondary" :disabled="idxNdvi <= 0" title="Fecha anterior" @click="pasoNdvi(-1)"><i class="fas fa-chevron-left"></i></button>
          <select class="form-control form-control-sm mg-ndvi-sel" v-model="claveNdvi">
            <option :value="null">— sin NDVI —</option>
            <option v-for="f in fechasNdvi" :key="f.clave" :value="f.clave">{{ f.texto }}</option>
          </select>
          <button type="button" class="btn btn-secondary" :disabled="idxNdvi < 0 || idxNdvi >= fechasNdvi.length - 1" title="Fecha siguiente" @click="pasoNdvi(1)"><i class="fas fa-chevron-right"></i></button>
        </div>
        <template v-if="ndviSel">
          <label class="mb-0" v-if="ndviSel.imagen"><input type="checkbox" v-model="verImagenNdvi"> Imagen</label>
          <input v-if="ndviSel.imagen && verImagenNdvi" type="range" min="0.2" max="1" step="0.05" v-model.number="opacidadNdvi" title="Transparencia de la imagen NDVI">
          <label class="mb-0"><input type="checkbox" v-model="valoresParcela"> Valor por parcela</label>
          <span class="mg-ndvi-res" v-if="resumenNdvi">
            Media <b :style="estiloNdvi(resumenNdvi.media)">{{ resumenNdvi.media.toFixed(2) }}</b>
            · {{ resumenNdvi.min.toFixed(2) }}–{{ resumenNdvi.max.toFixed(2) }} · {{ resumenNdvi.n }} parcelas
            <template v-if="ndviSel.origen === 'tratamiento'">(promedio del tratamiento)</template>
          </span>
          <span class="mg-ndvi-res" v-else>Sin valores por parcela para esta fecha.</span>
        </template>
      </div>

      <div class="mg-mapa-wrap">
        <div class="mg-mapa" ref="mapa"></div>
        <div class="mg-ndvi-leyenda" v-if="ndviSel">
          <span>NDVI · {{ ndviSel.corto }}</span>
          <i :style="{ background: degradado }"></i>
          <div><span>0</span><span>0.5</span><span>1</span></div>
        </div>
        <div class="mg-opacidad" v-if="imgCroquis">
          <label class="mb-0"><input type="checkbox" v-model="verCroquis"> Croquis</label>
          <input type="range" min="0.1" max="1" step="0.05" v-model.number="opacidad" :disabled="!verCroquis" title="Transparencia del croquis">
        </div>
      </div>
      <div class="mg-pendientes" v-if="sinUbicar.length">
        <i class="fas fa-exclamation-circle"></i>
        <span>
          <b>{{ sinUbicar.length }} de {{ parcelas.length }} parcelas sin ubicar</b>
          ({{ sinUbicar.length > 8 ? sinUbicar.slice(0, 8).map((p) => p.codigo).join(', ') + '…' : sinUbicar.map((p) => p.codigo).join(', ') }}):
          no se muestran en el mapa hasta delimitarlas sobre el croquis.
        </span>
        <button type="button" class="btn btn-success btn-sm" @click="$emit('ver-croquis')"><i class="fas fa-th"></i> Ubicarlas en el croquis</button>
      </div>
      <p class="mg-nota" v-else-if="croquis && !croquis.georef">
        <i class="fas fa-info-circle"></i> El croquis no esta georreferenciado; no se puede superponer en el mapa.
      </p>
    </template>
  </div>
</template>

<script>
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { obtenerImagen, transformacion } from './croquis-cache';
import GpsBarra from './gps-barra.vue';
import { colorNdvi, tintaNdvi, degradadoNdvi } from './ndvi-color';

const fechaTxt = (f) => {
  const [y, m, d] = String(f).slice(0, 10).split('-');
  return d ? `${d}/${m}/${y}` : `${m}/${y}`;
};

/**
 * Imagen con transformacion afin sobre el mapa (3 esquinas georreferenciadas):
 * permite superponer croquis rotados respecto al norte, cosa que el
 * L.imageOverlay estandar (rectangulo alineado a lat/lng) no hace.
 */
const ImagenAfin = L.Layer.extend({
  initialize(url, esquinas, opciones) {
    this._url = url;
    this._esq = esquinas; // {tl, tr, bl} en L.LatLng
    L.setOptions(this, opciones);
  },
  onAdd(map) {
    this._img = L.DomUtil.create('img', 'leaflet-image-layer leaflet-zoom-hide mg-croquis-img');
    this._img.src = this._url;
    Object.assign(this._img.style, {
      position: 'absolute', left: '0', top: '0', transformOrigin: '0 0',
      width: `${this.options.ancho}px`, height: `${this.options.alto}px`, maxWidth: 'none', pointerEvents: 'none',
    });
    this.getPane().appendChild(this._img);
    this.setOpacity(this.options.opacity);
    map.on('zoomend viewreset', this._reset, this);
    this._reset();
  },
  onRemove(map) {
    L.DomUtil.remove(this._img);
    map.off('zoomend viewreset', this._reset, this);
  },
  setOpacity(o) {
    this.options.opacity = o;
    if (this._img) this._img.style.opacity = o;
  },
  getBounds() {
    return L.latLngBounds([this._esq.tl, this._esq.tr, this._esq.bl, this._esq.br]);
  },
  _reset() {
    const m = this._map;
    const p0 = m.latLngToLayerPoint(this._esq.tl);
    const p1 = m.latLngToLayerPoint(this._esq.tr);
    const p2 = m.latLngToLayerPoint(this._esq.bl);
    const { ancho, alto } = this.options;
    this._img.style.transform = `matrix(${(p1.x - p0.x) / ancho},${(p1.y - p0.y) / ancho},${(p2.x - p0.x) / alto},${(p2.y - p0.y) / alto},${p0.x},${p0.y})`;
  },
});

export default {
  name: 'MapaGps',
  components: { GpsBarra },
  props: {
    ensayo: { type: Object, required: true },
    parcelas: { type: Array, default: () => [] },
    tratamientos: { type: Array, default: () => [] },
    croquis: { type: Object, default: null },
    storageUrl: { type: String, default: '' },
    puedeEditar: { type: Boolean, default: false },
    colorTrat: { type: Function, default: () => '#73BC25' },
    gpsActivo: { type: Boolean, default: false },
    gpsError: { type: String, default: null },
    pos: { type: Object, default: null },
    seguir: { type: Boolean, default: true },
    nav: { type: Object, default: () => ({}) },
    destinoId: { type: Number, default: null },
    recorrido: { type: Array, default: () => [] },
    recorridoM: { type: Number, default: 0 },
    ndvi: { type: Array, default: () => [] },
    ndviImagenes: { type: Array, default: () => [] },
  },
  data() {
    return {
      imgCroquis: null,
      verCroquis: true,
      opacidad: 0.6,
      claveNdvi: undefined,
      verImagenNdvi: true,
      opacidadNdvi: 0.85,
      valoresParcela: true,
      degradado: degradadoNdvi(0, 1),
    };
  },
  computed: {
    tieneGpsEnsayo() {
      return this.ensayo.lat !== null && this.ensayo.lat !== '' && this.ensayo.lng !== null && this.ensayo.lng !== '';
    },
    conGeometria() { return this.parcelas.filter((p) => p.geometria); },
    sinUbicar() { return this.parcelas.filter((p) => !p.geometria); },
    tieneAlgo() { return this.tieneGpsEnsayo || this.conGeometria.length > 0 || !!(this.croquis && this.croquis.georef) || this.ndviImagenes.length > 0; },
    parcelaActualId() { return this.nav.parcela ? this.nav.parcela.id : null; },

    /* NDVI: fechas con imagen (valores por parcela) y periodos de la serie por tratamiento. */
    fechasNdvi() {
      const lista = this.ndviImagenes.map((im) => ({
        clave: `img:${im.id}`,
        fecha: String(im.fecha).slice(0, 10),
        imagen: im,
        origen: 'parcela',
        corto: fechaTxt(im.fecha),
        texto: `${fechaTxt(im.fecha)} · ${im.fuente || 'imagen'}`,
      }));
      const conImagen = new Set(lista.map((f) => f.fecha));
      [...new Set(this.ndvi.map((r) => r.periodo))].filter((p) => !conImagen.has(p)).forEach((p) => {
        lista.push({ clave: `per:${p}`, fecha: p, imagen: null, origen: 'tratamiento', corto: fechaTxt(p), texto: `${fechaTxt(p)} · por tratamiento` });
      });
      return lista.sort((a, b) => a.fecha.localeCompare(b.fecha) || a.clave.localeCompare(b.clave));
    },
    idxNdvi() { return this.fechasNdvi.findIndex((f) => f.clave === this.claveNdvi); },
    ndviSel() { return this.idxNdvi >= 0 ? this.fechasNdvi[this.idxNdvi] : null; },
    valoresNdvi() { return this.valoresDe(this.ndviSel); },
    valoresPrevios() { return this.idxNdvi > 0 ? this.valoresDe(this.fechasNdvi[this.idxNdvi - 1]) : {}; },
    resumenNdvi() {
      const v = Object.values(this.valoresNdvi).map((x) => x.media);
      if (!v.length) return null;
      return { media: v.reduce((a, b) => a + b, 0) / v.length, min: Math.min(...v), max: Math.max(...v), n: v.length };
    },
  },
  watch: {
    parcelas: { handler() { this.pintarParcelas(); }, deep: true },
    parcelaActualId() { this.pintarParcelas(); },
    destinoId() { this.pintarParcelas(); this.pintarGps(); },
    croquis: { handler() { this.cargarCroquis(); }, deep: true },
    pos() { this.pintarGps(); if (this.seguir) this.centrarGps(); },
    recorrido() { this.pintarGps(); },
    seguir(v) { if (v) this.centrarGps(); },
    verCroquis(v) { if (this.capaCroquis) { if (v) this.capaCroquis.addTo(this.mapa); else this.capaCroquis.remove(); } },
    opacidad(o) { if (this.capaCroquis) this.capaCroquis.setOpacity(o); },
    tieneAlgo(v) { if (v) this.$nextTick(this.montar); },
    fechasNdvi: {
      immediate: true,
      handler(lista) {
        // Por defecto la fecha mas reciente; se conserva la elegida mientras exista.
        if (this.claveNdvi === undefined || (this.claveNdvi && !lista.some((f) => f.clave === this.claveNdvi))) {
          this.claveNdvi = lista.length ? lista[lista.length - 1].clave : undefined;
        }
      },
    },
    ndviSel() { this.cargarNdvi(); this.pintarParcelas(); },
    valoresNdvi() { this.pintarParcelas(); },
    valoresParcela() { this.pintarParcelas(); },
    verImagenNdvi() { this.cargarNdvi(); this.pintarParcelas(); },
    opacidadNdvi(o) { if (this.capaNdvi) this.capaNdvi.setOpacity(o); },
  },
  mounted() {
    this.$nextTick(this.montar);
  },
  beforeDestroy() {
    if (this.mapa) { this.mapa.remove(); this.mapa = null; }
  },
  methods: {
    montar() {
      if (this.mapa || !this.$refs.mapa) return;
      const calles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 22, maxNativeZoom: 19, attribution: '&copy; OpenStreetMap',
      });
      const satelite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 22, maxNativeZoom: 18, attribution: 'Imagenes &copy; Esri',
      });
      this.mapa = L.map(this.$refs.mapa, { layers: [satelite], maxZoom: 22, zoomSnap: 0.5 });
      L.control.layers({ Satelite: satelite, Calles: calles }, null, { position: 'topright' }).addTo(this.mapa);
      L.control.scale({ imperial: false }).addTo(this.mapa);
      // Imagenes (croquis y NDVI) por debajo de los poligonos de las parcelas.
      this.mapa.createPane('croquis').style.zIndex = 340;
      this.mapa.createPane('ndvi').style.zIndex = 350;
      this.capaParcelas = L.layerGroup().addTo(this.mapa);
      this.capaGps = L.layerGroup().addTo(this.mapa);
      this.mapa.on('popupopen', this.onPopup);
      this.pintarParcelas();
      this.pintarGps();
      this.encuadrar();
      this.cargarCroquis();
      this.cargarNdvi();
    },
    /** Llamar al mostrar la pestana (el contenedor estaba oculto). */
    mostrar() {
      if (!this.mapa) { this.$nextTick(this.montar); return; }
      setTimeout(() => { if (this.mapa) { this.mapa.invalidateSize(); if (!this.yaEncuadrado) this.encuadrar(); } }, 120);
    },
    encuadrar() {
      if (!this.mapa) return;
      const geo = this.conGeometria.flatMap((p) => p.geometria.coordinates[0].map(([lng, lat]) => [lat, lng]));
      if (geo.length) {
        this.mapa.fitBounds(L.latLngBounds(geo).pad(0.25), { maxZoom: 20 });
      } else if (this.capaCroquis || this.capaNdvi) {
        this.mapa.fitBounds((this.capaCroquis || this.capaNdvi).getBounds(), { maxZoom: 20 });
      } else if (this.tieneGpsEnsayo) {
        this.mapa.setView([Number(this.ensayo.lat), Number(this.ensayo.lng)], 17);
      }
      this.yaEncuadrado = this.$refs.mapa && this.$refs.mapa.offsetWidth > 0;
    },

    /* ---------------- croquis superpuesto ---------------- */
    async cargarCroquis() {
      if (!this.mapa) return;
      if (this.capaCroquis) { this.capaCroquis.remove(); this.capaCroquis = null; }
      this.imgCroquis = null;
      if (!this.croquis || !this.croquis.georef) return;
      try {
        const img = await obtenerImagen(this.croquis, this.storageUrl, window.innerWidth < 768 ? 2000 : 2800);
        const t = transformacion(this.croquis, img);
        if (!t || !this.mapa) return;
        const esq = (x, y) => { const g = t.aGeo(x, y); return L.latLng(g.lat, g.lng); };
        this.capaCroquis = new ImagenAfin(img.url, {
          tl: esq(0, 0), tr: esq(img.ancho, 0), bl: esq(0, img.alto), br: esq(img.ancho, img.alto),
        }, { ancho: img.ancho, alto: img.alto, opacity: this.opacidad, pane: 'croquis' });
        if (this.verCroquis) this.capaCroquis.addTo(this.mapa);
        this.imgCroquis = img;
        if (!this.conGeometria.length) this.encuadrar();
      } catch (e) {
        this.imgCroquis = null;
      }
    },

    /* ---------------- NDVI ---------------- */
    valoresDe(f) {
      const out = {};
      if (!f) return out;
      if (f.imagen) {
        Object.entries(f.imagen.valores || {}).forEach(([id, v]) => {
          if (v && Number.isFinite(Number(v.media))) out[id] = { ...v, media: Number(v.media) };
        });
      } else {
        const porTrat = {};
        this.ndvi.filter((r) => r.periodo === f.fecha).forEach((r) => { porTrat[r.tratamiento_id] = Number(r.valor); });
        this.parcelas.forEach((p) => { if (porTrat[p.tratamiento_id] !== undefined) out[p.id] = { media: porTrat[p.tratamiento_id] }; });
      }
      return out;
    },
    cargarNdvi() {
      if (!this.mapa) return;
      if (this.capaNdvi) { this.capaNdvi.remove(); this.capaNdvi = null; }
      const im = this.ndviSel && this.ndviSel.imagen;
      if (!im || !this.verImagenNdvi) return;
      const e = im.esquinas;
      const ll = (k) => L.latLng(e[k].lat, e[k].lng);
      this.capaNdvi = new ImagenAfin(`${this.storageUrl}/${im.imagen_path}`, { tl: ll('tl'), tr: ll('tr'), bl: ll('bl'), br: ll('br') }, {
        ancho: im.imagen_ancho, alto: im.imagen_alto, opacity: this.opacidadNdvi, pane: 'ndvi',
      }).addTo(this.mapa);
      // La imagen NDVI se ve pixelada a proposito (cada pixel es una medicion).
      if (this.capaNdvi._img) this.capaNdvi._img.style.imageRendering = 'pixelated';
    },
    pasoNdvi(d) {
      const f = this.fechasNdvi[this.idxNdvi + d];
      if (f) this.claveNdvi = f.clave;
    },
    /** Desde la pestana NDVI: muestra la fecha y encuadra la imagen. */
    verNdvi(fecha) {
      const f = this.fechasNdvi.find((x) => x.fecha === String(fecha).slice(0, 10) && x.imagen) || this.fechasNdvi.find((x) => x.fecha === fecha);
      if (!f) return;
      this.claveNdvi = f.clave;
      this.verImagenNdvi = true;
      this.$nextTick(() => {
        if (this.mapa && !this.conGeometria.length && this.capaNdvi) this.mapa.fitBounds(this.capaNdvi.getBounds(), { maxZoom: 20 });
      });
    },
    estiloNdvi(v) { return { background: colorNdvi(v), color: tintaNdvi(v), padding: '0 6px', borderRadius: '999px' }; },

    /* ---------------- parcelas ---------------- */
    nombreTrat(p) {
      return p.tratamiento ? p.tratamiento.codigo : (this.tratamientos.find((t) => t.id === p.tratamiento_id) || {}).codigo || '—';
    },
    pintarParcelas() {
      if (!this.capaParcelas) return;
      this.capaParcelas.clearLayers();
      if (this.tieneGpsEnsayo) {
        L.marker([Number(this.ensayo.lat), Number(this.ensayo.lng)], {
          icon: L.divIcon({ className: '', iconAnchor: [9, 9], html: '<div class="det-pin" title="Punto GPS del ensayo"></div>' }),
        }).bindPopup(`<b>${this.esc(this.ensayo.codigo)}</b><br>${this.esc([this.ensayo.finca, this.ensayo.lote].filter(Boolean).join(' / '))}`)
          .addTo(this.capaParcelas);
      }
      if (this.conGeometria.length) {
        const porNdvi = !!this.ndviSel && this.valoresParcela;
        const conImagen = !!this.capaNdvi;
        this.conGeometria.forEach((p) => {
          const v = porNdvi ? this.valoresNdvi[p.id] : null;
          const color = porNdvi ? colorNdvi(v ? v.media : null) : this.colorTrat(this.nombreTrat(p));
          const esActual = p.id === this.parcelaActualId;
          const esDestino = p.id === this.destinoId;
          // Con la imagen NDVI visible el relleno se aclara para no taparla.
          const relleno = porNdvi ? (conImagen ? 0.25 : 0.8) : 0.3;
          L.polygon(p.geometria.coordinates[0].map(([lng, lat]) => [lat, lng]), {
            color: esActual ? '#ffffff' : esDestino ? '#FF6B00' : porNdvi ? '#1e2a1a' : color,
            weight: esActual || esDestino ? 4 : porNdvi ? 1.5 : 2,
            dashArray: esDestino && !esActual ? '8 5' : null,
            fillColor: color, fillOpacity: esActual ? Math.max(relleno, 0.6) : esDestino ? Math.max(relleno, 0.45) : relleno,
            className: esActual ? 'cq-poly-actual' : '',
          }).bindTooltip(porNdvi ? `${this.esc(p.codigo)}<br><b>${v ? v.media.toFixed(2) : '—'}</b>` : p.codigo, {
            permanent: true, direction: 'center', className: porNdvi ? 'cq-label mg-label-ndvi' : 'cq-label',
          })
            .bindPopup(this.popupParcela(p))
            .addTo(this.capaParcelas);
        });
      }
      // Las parcelas sin poligono no se dibujan: una posicion inventada
      // confundiria con el croquis real. Se listan en el aviso inferior.
    },
    popupParcela(p) {
      const destino = p.id === this.destinoId;
      return `<b>${this.esc(p.codigo)}</b><br>${this.esc(this.nombreTrat(p))} · Rep. ${p.repeticion}`
        + `${p.area ? `<br>${Number(p.area)} m²` : ''}${p.notas ? `<br><i>${this.esc(p.notas)}</i>` : ''}`
        + this.popupNdvi(p)
        + `<div class="cq-pop-acc"><button type="button" class="btn btn-sm ${destino ? 'btn-secondary' : 'btn-success'}" data-navegar="${destino ? '' : p.id}">`
        + `<i class="fas fa-route"></i> ${destino ? 'Quitar destino' : 'Navegar aqui'}</button></div>`;
    },
    popupNdvi(p) {
      if (!this.ndviSel) return '';
      const v = this.valoresNdvi[p.id];
      if (!v) return `<div class="mg-pop-ndvi">NDVI ${this.ndviSel.corto}: sin valor</div>`;
      const ant = this.valoresPrevios[p.id];
      const delta = ant ? v.media - ant.media : null;
      return `<div class="mg-pop-ndvi">NDVI ${this.ndviSel.corto}: <b style="background:${colorNdvi(v.media)};color:${tintaNdvi(v.media)}">${v.media.toFixed(2)}</b>`
        + `${this.ndviSel.origen === 'tratamiento' ? ' <small>(prom. tratamiento)</small>' : ''}`
        + `${v.min !== undefined ? `<br><small>min ${Number(v.min).toFixed(2)} · max ${Number(v.max).toFixed(2)} · ${v.n} px</small>` : ''}`
        + `${delta !== null ? `<br><small>vs ${this.fechasNdvi[this.idxNdvi - 1].corto}: <b style="color:${delta >= 0 ? '#3f7a12' : '#b84d00'}">${delta >= 0 ? '▲' : '▼'} ${Math.abs(delta).toFixed(2)}</b></small>` : ''}`
        + '</div>';
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

    /* ---------------- GPS ---------------- */
    pintarGps() {
      if (!this.capaGps) return;
      this.capaGps.clearLayers();
      if (this.recorrido.length > 1) {
        L.polyline(this.recorrido, { color: '#4ea3ff', weight: 3, opacity: 0.85, dashArray: '2 6', interactive: false }).addTo(this.capaGps);
      }
      if (!this.pos) return;
      const ll = [this.pos.lat, this.pos.lng];
      if (this.nav.destino && !this.nav.llegado) {
        L.polyline([ll, [this.nav.destinoCentro.lat, this.nav.destinoCentro.lng]], { color: '#FF6B00', weight: 3, dashArray: '10 6', interactive: false }).addTo(this.capaGps);
      }
      L.circle(ll, { radius: this.pos.precision, color: '#1f6fbf', weight: 1, fillColor: '#1f6fbf', fillOpacity: 0.12, interactive: false }).addTo(this.capaGps);
      L.marker(ll, { icon: L.divIcon({ className: '', iconAnchor: [10, 10], html: '<div class="cq-gps"></div>' }), interactive: false }).addTo(this.capaGps);
    },
    centrarGps() {
      if (this.mapa && this.pos) this.mapa.panTo([this.pos.lat, this.pos.lng]);
    },
    enfocarParcela(id) {
      const p = this.conGeometria.find((x) => x.id === id);
      if (this.mapa && p) this.mapa.fitBounds(L.latLngBounds(p.geometria.coordinates[0].map(([lng, lat]) => [lat, lng])).pad(1.5), { maxZoom: 21 });
    },

    copiar() {
      const txt = `${this.ensayo.lat}, ${this.ensayo.lng}`;
      (navigator.clipboard ? navigator.clipboard.writeText(txt) : Promise.reject())
        .then(() => this.$swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Coordenadas copiadas', text: txt, timer: 2000, showConfirmButton: false }))
        .catch(() => this.$swal.fire({ icon: 'info', title: 'Coordenadas', text: txt }));
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
.mg-vacio { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 30px 16px; border: 1px dashed #CED2D5; border-radius: 10px; text-align: center; }
.mg-vacio > i { color: #CED2D5; font-size: 26px; }
.mg-vacio strong { color: var(--cengi-primary-deep); }
.mg-vacio p { max-width: 480px; margin: 0; color: var(--cengi-muted); font-size: 12.5px; }
.mg-vacio-acc { display: flex; gap: 8px; margin-top: 6px; }
.mg-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.mg-coord { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 999px; background: #eef8df; color: #3f7a12; font-size: 12px; font-weight: 600; }
.mg-tools { display: flex; flex-wrap: wrap; gap: 6px; }
.mg-mapa-wrap { position: relative; }
.mg-mapa { height: 520px; border: 1px solid var(--cengi-border); border-radius: 10px; overflow: hidden; z-index: 0; }
.mg-opacidad {
  position: absolute; left: 10px; bottom: 28px; z-index: 500; display: flex; align-items: center; gap: 8px;
  padding: 6px 10px; border-radius: 8px; background: rgba(255, 255, 255, .92); box-shadow: 0 1px 5px rgba(0, 0, 0, .25); font-size: 12px; font-weight: 600;
}
.mg-opacidad input[type=range] { width: 110px; }
.mg-nota { margin-top: 8px; color: var(--cengi-muted); font-size: 11.5px; }
.mg-nota i { margin-right: 4px; }
.mg-pendientes {
  display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 8px; padding: 8px 12px;
  border-left: 4px solid #FF6B00; border-radius: 8px; background: #fff4ea; color: #6e3b00; font-size: 12.5px;
}
.mg-pendientes > i { color: #FF6B00; }
.mg-pendientes > span { flex: 1 1 260px; }
.mg-ndvi {
  display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 8px; padding: 7px 10px;
  border-radius: 8px; background: #f1f8e9; color: #2f5a14; font-size: 12.5px;
}
.mg-ndvi-tit { font-weight: 700; }
.mg-ndvi-sel { width: auto; max-width: 240px; border-radius: 0; }
.mg-ndvi input[type=range] { width: 90px; }
.mg-ndvi-res { margin-left: auto; color: #4d6a3a; }
.mg-ndvi-res b { font-weight: 700; }
.mg-ndvi-leyenda {
  position: absolute; right: 10px; bottom: 28px; z-index: 500; width: 170px; padding: 6px 10px; border-radius: 8px;
  background: rgba(255, 255, 255, .94); box-shadow: 0 1px 5px rgba(0, 0, 0, .25); color: #33402a; font-size: 11px; font-weight: 600;
}
.mg-ndvi-leyenda i { display: block; height: 10px; margin: 4px 0 2px; border-radius: 999px; }
.mg-ndvi-leyenda div { display: flex; justify-content: space-between; color: var(--cengi-muted); font-weight: 500; }
@media (max-width: 640px) {
  .mg-mapa { height: 420px; }
  .mg-ndvi-res { margin-left: 0; }
}
</style>

<style>
/* Icono del selector de capas sin depender de layers.png (Mix lo publica sin el prefijo /sigec). */
.mg .leaflet-control-layers-toggle { display: grid; place-items: center; width: 36px; height: 36px; background-image: none; }
.mg .leaflet-control-layers-toggle::after { content: '\f5fd'; color: #1f5632; font-family: 'Font Awesome 5 Free'; font-size: 16px; font-weight: 900; }
.mg .leaflet-control-layers-expanded .leaflet-control-layers-toggle { display: none; }
.mg .leaflet-control-layers-expanded { padding: 8px 12px; font-size: 12.5px; }
.mg .mg-label-ndvi { line-height: 1.15; text-align: center; }
.mg .mg-pop-ndvi { margin-top: 6px; padding-top: 6px; border-top: 1px solid #e6e9e3; }
.mg .mg-pop-ndvi b { padding: 0 6px; border-radius: 999px; }
</style>
