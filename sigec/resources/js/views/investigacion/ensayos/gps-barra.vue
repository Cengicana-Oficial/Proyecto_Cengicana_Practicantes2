<template>
  <div class="gb">
    <div class="gb-fila">
      <button type="button" class="btn btn-sm" :class="gpsActivo ? 'btn-success' : 'btn-secondary'" @click="$emit('alternar-gps')">
        <i class="fas fa-crosshairs"></i> {{ gpsActivo ? 'GPS activo' : 'Activar GPS' }}
      </button>
      <button v-if="gpsActivo" type="button" class="btn btn-secondary btn-sm" :class="{ active: seguir }" title="Seguir mi ubicacion" @click="$emit('seguir', !seguir)">
        <i class="fas fa-location-arrow"></i>
      </button>

      <span v-if="gpsError" class="gb-error"><i class="fas fa-exclamation-triangle"></i> {{ gpsError }}</span>
      <span v-else-if="gpsActivo && !pos" class="gb-muted"><i class="fas fa-spinner fa-spin"></i> Obteniendo posicion...</span>
      <template v-else-if="pos">
        <span class="gb-pre" :class="{ 'is-mala': pos.precision > 15 }" title="Precision del GPS">±{{ Math.round(pos.precision) }} m</span>
        <span v-if="nav.parcela" class="gb-aqui" :style="{ '--c': colorTrat(nombreTrat(nav.parcela)) }">
          <i class="fas fa-map-marker-alt"></i> Esta en <b>{{ nav.parcela.codigo }}</b> · {{ nombreTrat(nav.parcela) }} · Rep. {{ nav.parcela.repeticion }}
        </span>
        <span v-else-if="nav.cercana" class="gb-muted">Fuera de las parcelas · cercana <b>{{ nav.cercana.codigo }}</b> a {{ metros(nav.distancia) }}</span>
        <span v-else class="gb-muted">Sin parcelas delimitadas</span>
      </template>
      <span v-else class="gb-muted">Active el GPS para ubicarse en el ensayo.</span>
    </div>

    <div class="gb-fila gb-nav" v-if="conGeometria.length">
      <label class="mb-0"><i class="fas fa-route"></i> Navegar a</label>
      <select class="form-control form-control-sm gb-sel" :value="destinoId" @change="$emit('destino', $event.target.value ? Number($event.target.value) : null)">
        <option value="">— elegir parcela —</option>
        <option v-for="p in conGeometria" :key="p.id" :value="p.id">{{ p.codigo }} · {{ nombreTrat(p) }} · Rep. {{ p.repeticion }}</option>
      </select>
      <template v-if="nav.destino">
        <span v-if="nav.llegado" class="gb-llegado"><i class="fas fa-flag-checkered"></i> Llego a {{ nav.destino.codigo }}</span>
        <span v-else-if="pos" class="gb-dir">
          <i class="fas fa-location-arrow gb-flecha" :style="{ transform: `rotate(${nav.rumbo - 45}deg)` }"></i>
          <b>{{ metros(nav.distDestino) }}</b> al {{ nav.cardinal }} ({{ Math.round(nav.rumbo) }}°)
          <small v-if="nav.distDestino > 20">· ~{{ Math.max(1, Math.round(nav.distDestino / 70)) }} min a pie</small>
        </span>
        <span v-else class="gb-muted">Active el GPS para ver la distancia.</span>
        <a class="btn btn-secondary btn-sm" target="_blank" rel="noopener" :href="`https://www.google.com/maps/dir/?api=1&destination=${nav.destinoCentro.lat},${nav.destinoCentro.lng}&travelmode=walking`" title="Abrir ruta en Google Maps">
          <i class="fas fa-external-link-alt"></i> Maps
        </a>
        <button type="button" class="btn btn-link btn-sm" @click="$emit('destino', null)">Quitar</button>
      </template>
      <span class="gb-rec" v-if="recorridoM > 0">
        <i class="fas fa-shoe-prints"></i> {{ metros(recorridoM) }}
        <button type="button" class="btn btn-link btn-sm p-0" title="Borrar recorrido" @click="$emit('limpiar-recorrido')"><i class="fas fa-times"></i></button>
      </span>
    </div>
  </div>
</template>

<script>
export default {
  name: 'GpsBarra',
  props: {
    gpsActivo: { type: Boolean, default: false },
    gpsError: { type: String, default: null },
    pos: { type: Object, default: null },
    seguir: { type: Boolean, default: true },
    nav: { type: Object, default: () => ({}) },
    parcelas: { type: Array, default: () => [] },
    destinoId: { type: Number, default: null },
    recorridoM: { type: Number, default: 0 },
    colorTrat: { type: Function, default: () => '#73BC25' },
    nombreTrat: { type: Function, default: () => '' },
  },
  computed: {
    conGeometria() { return this.parcelas.filter((p) => p.geometria); },
  },
  methods: {
    metros(m) {
      if (m === null || m === undefined || !Number.isFinite(m)) return '—';
      return m >= 1000 ? `${(m / 1000).toFixed(2)} km` : `${Math.round(m)} m`;
    },
  },
};
</script>

<style scoped>
.gb { margin-bottom: 8px; padding: 8px 10px; border-radius: 8px; background: #eef4fb; color: #1d4f80; font-size: 12.5px; }
.gb-fila { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.gb-nav { margin-top: 8px; padding-top: 8px; border-top: 1px solid #d6e4f3; }
.gb .btn.active { border-color: #73BC25; background: #eef8df; }
.gb-pre { padding: 1px 7px; border-radius: 999px; background: #fff; font-weight: 700; }
.gb-pre.is-mala { background: #fff1e6; color: #b84d00; }
.gb-aqui { padding: 3px 10px; border-left: 4px solid var(--c); border-radius: 6px; background: #fff; color: var(--cengi-primary-deep); }
.gb-muted { color: #4d6a87; }
.gb-error { color: #b84d00; font-weight: 600; }
.gb-sel { width: auto; max-width: 240px; }
.gb-dir { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 6px; background: #fff; color: var(--cengi-primary-deep); }
.gb-flecha { color: #FF6B00; font-size: 15px; transition: transform .4s ease; }
.gb-llegado { padding: 3px 10px; border-radius: 6px; background: #eef8df; color: #3f7a12; font-weight: 700; }
.gb-rec { margin-left: auto; color: #4d6a87; }
.gb-rec i { margin-right: 3px; }
</style>
