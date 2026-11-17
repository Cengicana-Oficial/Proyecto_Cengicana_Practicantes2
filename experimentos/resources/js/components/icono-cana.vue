<template>
  <!-- Mismo logo que resources/views/components/icono-cana.blade.php (marca y menu de Ensayos). -->
  <img
    :src="src" alt="" aria-hidden="true" :width="tamano" :height="tamano"
    class="icono-cana" :class="{ 'is-apagado': apagado }"
  >
</template>

<script>
const meta = document.querySelector('meta[name="app-base"]');
const base = meta && meta.content ? meta.content.replace(/\/+$/, '') : '';

/** Logo de Experimentos (caña + matraz). apagado: version gris para estados vacios. */
export default {
  name: 'IconoCana',
  props: {
    tamano: { type: Number, default: 20 }, // lado en px
    apagado: { type: Boolean, default: false },
  },
  data: () => ({ src: `${base}/img/experimentos-logo.png` }),
};
</script>

<style scoped>
.icono-cana { display: inline-block; flex-shrink: 0; vertical-align: middle; object-fit: contain; }
.icono-cana.is-apagado { filter: grayscale(1); opacity: .45; }
</style>
