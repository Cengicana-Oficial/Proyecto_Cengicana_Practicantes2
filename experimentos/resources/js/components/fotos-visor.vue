<template>
  <div class="fv" role="dialog" aria-modal="true" @click.self="$emit('cerrar')">
    <button type="button" class="fv-btn fv-cerrar" title="Cerrar (Esc)" @click="$emit('cerrar')"><i class="fas fa-times"></i></button>
    <button v-if="fotos.length > 1" type="button" class="fv-btn fv-ant" title="Anterior (←)" @click="mover(-1)"><i class="fas fa-chevron-left"></i></button>
    <figure>
      <img :src="foto.url" :alt="titulo(foto)">
      <figcaption>
        <b>{{ titulo(foto) }}</b>
        <span>{{ fecha(foto.fecha) }}<template v-if="foto.subido_por"> · {{ foto.subido_por }}</template> · {{ i + 1 }} de {{ fotos.length }}</span>
        <em v-if="foto.nota">{{ foto.nota }}</em>
        <a :href="foto.url" :download="foto.nombre || 'foto.jpg'" class="fv-desc"><i class="fas fa-download"></i> Descargar</a>
        <button v-if="foto.editable && eliminable" type="button" class="fv-desc is-peligro" @click="$emit('eliminar', foto)"><i class="fas fa-trash"></i> Eliminar</button>
      </figcaption>
    </figure>
    <button v-if="fotos.length > 1" type="button" class="fv-btn fv-sig" title="Siguiente (→)" @click="mover(1)"><i class="fas fa-chevron-right"></i></button>
  </div>
</template>

<script>
/** Visor de fotos a pantalla completa con flechas; describe = (foto) => 'P-01 · T2 · R1'. */
export default {
  name: 'FotosVisor',
  props: {
    fotos: { type: Array, required: true },
    inicio: { type: Number, default: 0 },
    describe: { type: Function, default: () => '' },
    eliminable: { type: Boolean, default: true },
  },
  data() { return { i: Math.min(this.inicio, this.fotos.length - 1) }; },
  computed: { foto() { return this.fotos[this.i] || {}; } },
  watch: { fotos(f) { if (!f.length) this.$emit('cerrar'); else if (this.i >= f.length) this.i = f.length - 1; } },
  mounted() { document.addEventListener('keydown', this.tecla); },
  beforeDestroy() { document.removeEventListener('keydown', this.tecla); },
  methods: {
    mover(d) { this.i = (this.i + d + this.fotos.length) % this.fotos.length; },
    tecla(e) {
      if (e.key === 'Escape') this.$emit('cerrar');
      else if (e.key === 'ArrowLeft') this.mover(-1);
      else if (e.key === 'ArrowRight') this.mover(1);
    },
    titulo(f) { return this.describe(f) || f.nombre || 'Foto'; },
    fecha(f) { return f ? String(f).slice(0, 10).split('-').reverse().join('/') : ''; },
  },
};
</script>

<style scoped>
.fv{position:fixed;inset:0;z-index:2000;display:flex;align-items:center;justify-content:center;gap:10px;padding:20px;background:rgba(12,18,12,.92)}
.fv figure{display:flex;flex-direction:column;align-items:center;max-width:min(1400px,100%);max-height:100%;margin:0}
.fv img{max-width:100%;max-height:calc(100vh - 150px);object-fit:contain;border-radius:6px;box-shadow:0 10px 40px rgba(0,0,0,.5)}
.fv figcaption{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:6px 14px;margin-top:12px;color:#e8efe3;font-size:13px;text-align:center}
.fv figcaption b{font-size:15px}.fv figcaption span{color:#b9c7b1}.fv figcaption em{flex-basis:100%;font-style:normal;color:#dfe8d6}
.fv-desc{border:0;background:none;color:#a3d300;font-size:12px}.fv-desc:hover{color:#fff;text-decoration:none}.fv-desc.is-peligro{color:#ffab7a}
.fv-btn{display:grid;place-items:center;flex:none;width:44px;height:44px;border:0;border-radius:50%;background:rgba(255,255,255,.12);color:#fff;font-size:18px}
.fv-btn:hover{background:rgba(255,255,255,.25)}
.fv-cerrar{position:absolute;top:16px;right:16px}
@media(max-width:600px){.fv{padding:8px}.fv-ant,.fv-sig{position:absolute;bottom:16px}.fv-ant{left:16px}.fv-sig{right:16px}}
</style>
