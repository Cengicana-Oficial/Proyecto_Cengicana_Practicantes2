<template>
  <div class="me">
    <div class="me-mapa-wrap">
      <div ref="mapa" class="me-mapa" role="region" aria-label="Mapa de ensayos"></div>
      <div class="me-leyenda">
        <span v-for="s in estados" :key="s.id"><i :style="{ background: s.color }"></i>{{ s.id }}</span>
      </div>
    </div>
    <aside class="me-lado">
      <h4><i class="fas fa-map-marker-alt"></i> {{ conGps.length }} con ubicacion</h4>
      <ul class="me-lista">
        <li v-for="e in conGps" :key="e.id" :class="{ 'is-sel': sel === e.id }" @mouseenter="resaltar(e)" @click="centrar(e)">
          <i class="me-punto" :style="{ background: color(e.estado) }"></i>
          <div>
            <b class="me-cod">{{ e.codigo }}</b>
            <small>{{ [e.finca, e.lote].filter(Boolean).join(' / ') || 'Sin finca' }}<template v-if="e.estrato"> · {{ e.estrato.toLowerCase() }}</template></small>
          </div>
        </li>
      </ul>
      <template v-if="sinGps.length">
        <h4 class="is-sin"><i class="fas fa-exclamation-circle"></i> {{ sinGps.length }} sin coordenadas</h4>
        <ul class="me-lista">
          <li v-for="e in sinGps" :key="e.id" class="is-sin">
            <div>
              <b class="me-cod">{{ e.codigo }}</b>
              <small>{{ [e.finca, e.lote].filter(Boolean).join(' / ') || 'Sin finca' }}</small>
            </div>
            <button v-if="puedeEditar" type="button" class="btn btn-sm me-btn" @click.stop="$emit('editar', e)">Ubicar</button>
          </li>
        </ul>
      </template>
    </aside>
  </div>
</template>

<script>
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

export default {
  name: 'EnsayosMapa',
  props: {
    ensayos: { type: Array, default: () => [] },
    estados: { type: Array, default: () => [] },
    puedeEditar: { type: Boolean, default: false },
  },
  data() { return { sel: null }; },
  computed: {
    conGps() { return this.ensayos.filter((e) => e.lat !== null && e.lat !== '' && e.lng !== null && e.lng !== ''); },
    sinGps() { return this.ensayos.filter((e) => !this.conGps.includes(e)); },
  },
  watch: {
    ensayos() { this.$nextTick(() => this.pintar(true)); },
  },
  mounted() {
    this.mapa = L.map(this.$refs.mapa, { zoomSnap: 0.5 }).setView([14.3, -91.0], 9);
    const calles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' });
    const sat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, maxNativeZoom: 18, attribution: 'Imagenes &copy; Esri' }).addTo(this.mapa);
    L.control.layers({ Satelite: sat, Calles: calles }, null, { position: 'topright' }).addTo(this.mapa);
    L.control.scale({ imperial: false }).addTo(this.mapa);
    this.capa = L.layerGroup().addTo(this.mapa);
    // El boton "Abrir ensayo" del popup abre el detalle (el HTML del popup no es Vue).
    this.mapa.on('popupopen', (ev) => {
      const btn = ev.popup.getElement().querySelector('[data-ensayo]');
      if (btn) btn.addEventListener('click', () => this.$emit('ver', this.ensayos.find((x) => x.id === Number(btn.dataset.ensayo))));
    });
    this.pintar(true);
  },
  beforeDestroy() { if (this.mapa) this.mapa.remove(); },
  methods: {
    color(estado) { const s = this.estados.find((x) => x.id === estado); return s ? s.color : '#8a9396'; },
    texto(estado) { const s = this.estados.find((x) => x.id === estado); return s ? s.texto : '#fff'; },
    popup(e) {
      const ubic = [e.finca, e.lote].filter(Boolean).join(' / ');
      const zona = [e.estrato && `Estrato ${e.estrato.toLowerCase()}`, e.zona && `zona ${e.zona.toLowerCase()}`].filter(Boolean).join(', ');
      return `<div class="me-pop">
        <b>${esc(e.codigo)}</b> <span style="background:${this.color(e.estado)};color:${this.texto(e.estado)}">${esc(e.estado)}</span>
        ${e.nombre ? `<div>${esc(e.nombre)}</div>` : ''}
        <small>${esc(ubic || 'Sin finca')}${zona ? ' · ' + esc(zona) : ''}</small>
        <small>${esc([e.variedad, e.ciclo].filter(Boolean).join(' · ') || '')}</small>
        <small>${e.evaluaciones_count || 0} valores evaluados · ${e.muestras_completadas_count || 0}/${e.muestras_count || 0} muestras con resultado</small>
        <button type="button" data-ensayo="${e.id}">Abrir ensayo</button>
      </div>`;
    },
    pintar(encuadrar) {
      if (!this.capa) return;
      this.capa.clearLayers();
      this.marcas = {};
      this.conGps.forEach((e) => {
        const m = L.circleMarker([Number(e.lat), Number(e.lng)], {
          radius: 9, color: '#fff', weight: 2, fillColor: this.color(e.estado), fillOpacity: 1,
        }).bindTooltip(e.codigo, { direction: 'top', offset: [0, -8] }).bindPopup(this.popup(e), { minWidth: 220 });
        m.addTo(this.capa);
        this.marcas[e.id] = m;
      });
      if (encuadrar && this.conGps.length) {
        const b = L.latLngBounds(this.conGps.map((e) => [Number(e.lat), Number(e.lng)]));
        if (this.conGps.length === 1) this.mapa.setView(b.getCenter(), 14);
        else this.mapa.fitBounds(b, { padding: [40, 40], maxZoom: 14 });
      }
    },
    resaltar(e) { this.sel = e.id; },
    centrar(e) {
      this.sel = e.id;
      const m = this.marcas && this.marcas[e.id];
      if (m) { this.mapa.setView(m.getLatLng(), Math.max(this.mapa.getZoom(), 14)); m.openPopup(); }
    },
  },
};
</script>

<style scoped>
.me { display: grid; grid-template-columns: minmax(0, 1fr) 260px; gap: 14px; }
.me-mapa-wrap { position: relative; }
.me-mapa { height: 560px; border: 1px solid #e3e7e4; border-radius: 12px; overflow: hidden; z-index: 0; }
.me-leyenda { position: absolute; left: 10px; bottom: 26px; z-index: 500; display: flex; gap: 10px; padding: 5px 10px; background: rgba(255, 255, 255, .92); border-radius: 8px; font-size: 11.5px; color: #1f2a1a; }
.me-leyenda i { display: inline-block; width: 10px; height: 10px; margin-right: 5px; border-radius: 50%; border: 1.5px solid #fff; box-shadow: 0 0 0 1px #8a9396; vertical-align: -1px; }
.me-lado { max-height: 560px; overflow-y: auto; padding: 12px; background: #fff; border: 1px solid #e3e7e4; border-radius: 12px; }
.me-lado h4 { margin: 0 0 8px; font-size: 13px; font-weight: 700; color: #1f2a1a; }
.me-lado h4 i { color: #73BC25; }
.me-lado h4.is-sin { margin-top: 14px; }
.me-lado h4.is-sin i { color: #c24f00; }
.me-lista { list-style: none; margin: 0; padding: 0; }
.me-lista li { display: flex; align-items: center; gap: 8px; padding: 7px 6px; border-radius: 8px; cursor: pointer; }
.me-lista li:hover, .me-lista li.is-sel { background: #f3f7ee; }
.me-lista li.is-sin { cursor: default; }
.me-lista li > div { flex: 1; min-width: 0; }
.me-lista small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #5f6b66; font-size: 11.5px; }
.me-punto { flex: none; width: 11px; height: 11px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 0 1px #8a9396; }
.me-cod { font-family: 'Space Grotesk', sans-serif; font-size: 12px; color: #3f7a12; }
.me-btn { background: #eef7e3; color: #3f7a12; font-weight: 600; }
.me-btn:hover { background: #73BC25; color: #fff; }
.me >>> .me-pop { font-size: 12px; line-height: 1.35; }
.me >>> .me-pop span { margin-left: 4px; padding: 1px 7px; border-radius: 999px; font-size: 10.5px; font-weight: 700; }
.me >>> .me-pop small { display: block; color: #5f6b66; }
.me >>> .me-pop button { margin-top: 6px; padding: 4px 10px; border: 0; border-radius: 6px; background: #73BC25; color: #fff; font-weight: 600; cursor: pointer; }
@media (max-width: 900px) {
  .me { grid-template-columns: 1fr; }
  .me-mapa { height: 420px; }
  .me-lado { max-height: none; }
}
</style>
