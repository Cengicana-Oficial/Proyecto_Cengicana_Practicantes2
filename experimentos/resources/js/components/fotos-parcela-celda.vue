<template>
  <div class="fc">
    <button v-for="(f, i) in fotos" :key="f.id" type="button" class="fc-mini" :title="f.nota || 'Ver foto'" @click="$emit('ver', i)">
      <img :src="f.miniatura" alt="">
    </button>
    <span v-for="(s, i) in subiendo" :key="'s' + i" class="fc-mini is-subiendo" :title="s.nombre"><i class="fas fa-spinner fa-spin"></i><small>{{ s.avance }}%</small></span>
    <label v-if="editable && fotos.length + subiendo.length < maximo" class="fc-add" :title="`Tomar o elegir foto de ${parcela.codigo}${indicacion ? ': ' + indicacion : ''}`">
      <input type="file" accept="image/*" capture="environment" :multiple="maximo - fotos.length > 1" @change="elegir($event)">
      <i class="fas fa-camera"></i><small v-if="!fotos.length">Foto</small>
    </label>
    <span v-if="error" class="fc-error" :title="error"><i class="fas fa-exclamation-circle"></i></span>
  </div>
</template>

<script>
import { subirFoto, mensajeError } from '../fotos-util';

/** Fotos de una parcela en una fecha, con boton de camara (en el celular abre la camara trasera). */
export default {
  name: 'FotosParcelaCelda',
  props: {
    ensayoId: { type: Number, required: true },
    parcela: { type: Object, required: true }, // { id, codigo }
    fecha: { type: String, required: true },
    fotos: { type: Array, default: () => [] },
    formularioId: { type: Number, default: null },
    maximo: { type: Number, default: 3 },
    indicacion: { type: String, default: '' },
    editable: { type: Boolean, default: true },
  },
  data() { return { subiendo: [], error: '' }; },
  methods: {
    async elegir(e) {
      const archivos = [...e.target.files].slice(0, this.maximo - this.fotos.length);
      e.target.value = '';
      this.error = '';
      for (const file of archivos) {
        const estado = { nombre: file.name, avance: 0 };
        this.subiendo.push(estado);
        try {
          const foto = await subirFoto({
            ensayoId: this.ensayoId, parcelaId: this.parcela.id, fecha: this.fecha, formularioId: this.formularioId, file,
            progreso: (p) => { estado.avance = p; },
          });
          this.$emit('agregada', foto);
        } catch (err) {
          this.error = mensajeError(err);
        } finally {
          this.subiendo.splice(this.subiendo.indexOf(estado), 1);
        }
      }
    },
  },
};
</script>

<style scoped>
.fc{display:flex;align-items:center;gap:4px;min-height:34px}
.fc-mini{position:relative;display:grid;place-items:center;flex:none;width:34px;height:34px;padding:0;border:1px solid #dce8d3;border-radius:6px;background:#f4f7f1;overflow:hidden}
.fc-mini img{width:100%;height:100%;object-fit:cover}
.fc-mini.is-subiendo{color:#73bc25;font-size:11px}.fc-mini.is-subiendo small{position:absolute;bottom:0;font-size:8px}
.fc-add{display:inline-flex;align-items:center;gap:4px;flex:none;height:34px;margin:0;padding:0 9px;border:1px dashed #a9c48f;border-radius:6px;background:#fafcf8;color:#527443;font-size:12px;font-weight:600;cursor:pointer}
.fc-add:hover{border-color:#73bc25;background:#eef7e3}.fc-add input{display:none}
.fc-error{color:#d24f00;cursor:help}
</style>
