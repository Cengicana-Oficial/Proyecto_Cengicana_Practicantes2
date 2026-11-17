<template>
  <div class="mu">
    <div class="mu-tabs" role="tablist" aria-label="Muestras">
      <button v-if="generar" type="button" role="tab" :class="{ 'is-on': tab === 'generar' }" :aria-selected="tab === 'generar'" @click="cambiar('generar')">
        <i class="fas fa-qrcode"></i> Generar etiquetas <small>crear lote e imprimir QR</small>
      </button>
      <button type="button" role="tab" :class="{ 'is-on': tab === 'seguimiento' }" :aria-selected="tab === 'seguimiento'" @click="cambiar('seguimiento')">
        <i class="fas fa-tasks"></i> Seguimiento <small>estado, Laboratorio y resultados</small>
        <span v-if="nuevas" class="mu-nuevas" title="Muestras generadas en esta sesion">+{{ nuevas }}</span>
      </button>
      <a v-if="evaluacionesUrl" :href="evaluacionesUrl" class="mu-link" title="Los resultados se analizan junto a los datos de campo">
        <i class="fas fa-chart-bar"></i> Analizar resultados en Evaluaciones
      </a>
    </div>

    <p v-if="nuevas && tab === 'seguimiento'" class="mu-aviso">
      <i class="fas fa-info-circle"></i> Se generaron {{ nuevas }} muestra(s) nuevas.
      <a href="#" @click.prevent="recargar">Actualizar el seguimiento</a> para verlas en la lista.
    </p>

    <!-- Las dos pestañas quedan montadas: cambiar de una a otra no pierde lo que se estaba haciendo. -->
    <muestras-gen-index v-if="generar" v-show="tab === 'generar'" v-bind="generar" @generadas="(n) => (nuevas += n)"></muestras-gen-index>
    <muestras-consulta-index v-show="tab === 'seguimiento'" v-bind="seguimiento"></muestras-consulta-index>
  </div>
</template>

<script>
/** Muestras: pestañas Generar etiquetas (muestras-gen-index) y Seguimiento (muestras-consulta-index). */
export default {
  name: 'MuestrasIndex',
  props: {
    generar: { type: Object, default: null }, // null si el usuario no puede generar muestras
    seguimiento: { type: Object, required: true },
  },
  data() {
    const qs = new URLSearchParams(window.location.search);
    // Por defecto Seguimiento (tambien para enlaces a una muestra o busqueda); ?tab=generar abre la otra.
    return {
      tab: qs.get('tab') === 'generar' && this.generar ? 'generar' : 'seguimiento',
      nuevas: 0,
      evaluacionesUrl: `${((document.querySelector('meta[name="app-base"]') || {}).content || '').replace(/\/+$/, '')}/investigacion/evaluaciones`,
    };
  },
  methods: {
    cambiar(t) {
      this.tab = t;
      try { const u = new URL(window.location.href); u.searchParams.set('tab', t); window.history.replaceState(null, '', u); } catch (e) { /* sin URL API */ }
    },
    recargar() {
      const u = new URL(window.location.href);
      u.searchParams.set('tab', 'seguimiento');
      window.location.href = u.toString();
    },
  },
};
</script>

<style scoped>
.mu-tabs { display: flex; flex-wrap: wrap; align-items: stretch; gap: 8px; margin-bottom: 14px; }
.mu-tabs button {
  display: flex; flex-direction: column; align-items: flex-start; gap: 1px; position: relative; padding: 9px 16px; border: 1px solid #e3e7e1;
  border-radius: 12px; background: #fff; color: #33433a; font-size: 13.5px; font-weight: 700; text-align: left;
}
.mu-tabs button i { margin-right: 6px; color: #73BC25; }
.mu-tabs button small { color: #6b7568; font-size: 11px; font-weight: 500; }
.mu-tabs button.is-on { border-color: #73BC25; background: #f3f9ea; color: #1f5632; box-shadow: inset 0 -3px 0 #73BC25; }
.mu-nuevas { position: absolute; top: -7px; right: -7px; padding: 1px 7px; border-radius: 999px; background: #FF6B00; color: #fff; font-size: 11px; font-weight: 700; }
.mu-link { align-self: center; margin-left: auto; color: #3f7a12; font-size: 12.5px; font-weight: 600; }
.mu-link i { margin-right: 4px; }
.mu-aviso { margin: -4px 0 12px; padding: 8px 12px; border-radius: 10px; background: #fff7e6; color: #7a4a00; font-size: 12.5px; }
.mu-aviso a { color: #b84d00; font-weight: 700; }
@media (max-width: 640px) { .mu-link { margin-left: 0; } }
</style>
