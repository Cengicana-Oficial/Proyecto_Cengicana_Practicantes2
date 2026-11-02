<template>
  <div>
    <div class="card" v-if="puedeSubir">
      <div class="card-header">
        <h3 class="card-title">Subir imagen geoespacial</h3>
      </div>
      <div class="card-body">
        <form @submit.prevent="subir">
          <div class="row">
            <div class="col-md-3 form-group">
              <label>Ensayo</label>
              <select class="form-control" :class="{ 'is-invalid': errors.ensayo_id }" v-model="form.ensayo_id">
                <option :value="null">Seleccione...</option>
                <option v-for="e in ensayos" :key="e.id" :value="e.id">{{ e.codigo }}</option>
              </select>
              <span class="invalid-feedback" v-if="errors.ensayo_id">{{ errors.ensayo_id[0] }}</span>
            </div>
            <div class="col-md-2 form-group">
              <label>Tipo</label>
              <select class="form-control" v-model="form.tipo">
                <option value="NDVI">NDVI</option>
                <option value="RGB">RGB</option>
                <option value="Termica">Termica</option>
                <option value="Multiespectral">Multiespectral</option>
              </select>
            </div>
            <div class="col-md-2 form-group">
              <label>Fecha</label>
              <input type="date" class="form-control" v-model="form.fecha">
            </div>
            <div class="col-md-2 form-group">
              <label>Sensor</label>
              <input type="text" class="form-control" v-model="form.sensor" placeholder="Sentinel-2...">
            </div>
            <div class="col-md-3 form-group">
              <label>Archivo</label>
              <input type="file" class="form-control-file" :class="{ 'is-invalid': errors.archivo }" @change="onFile" ref="fileInput">
              <span class="invalid-feedback d-block" v-if="errors.archivo">{{ errors.archivo[0] }}</span>
            </div>
          </div>
          <div class="row">
            <div class="col-md-3 form-group">
              <label>Resolucion</label>
              <input type="text" class="form-control" v-model="form.resolucion" placeholder="10m, 3cm...">
            </div>
            <div class="col-md-3 form-group">
              <label>Bandas</label>
              <input type="text" class="form-control" v-model="form.bandas">
            </div>
            <div class="col-md-6 form-group">
              <label>Notas</label>
              <input type="text" class="form-control" v-model="form.notas">
            </div>
          </div>
          <button type="submit" class="btn btn-success" :disabled="subiendo">
            <i class="fas fa-upload"></i> Subir
          </button>
        </form>
      </div>
    </div>

    <div class="row">
      <div class="col-md-4" v-for="img in items" :key="img.id">
        <div class="card">
          <div class="card-body">
            <div class="d-flex justify-content-between">
              <span :class="'badge ' + badgeTipo(img.tipo)">{{ img.tipo }}</span>
              <a href="#" class="text-danger" v-if="puedeEliminar" @click.prevent="eliminar(img)"><i class="fas fa-trash"></i></a>
            </div>
            <p class="mt-2 mb-1"><b>{{ img.nombre }}</b></p>
            <p class="text-muted small mb-1">{{ img.ensayo ? img.ensayo.codigo : '-' }} · {{ img.fecha }}</p>
            <p class="text-muted small mb-1" v-if="img.sensor">Sensor: {{ img.sensor }}</p>
            <p class="text-muted small mb-1" v-if="img.resolucion">Resolucion: {{ img.resolucion }}</p>
            <p class="text-muted small mb-2" v-if="img.width">{{ img.width }}x{{ img.height }} px</p>
            <a :href="'/storage/' + img.path" target="_blank" class="btn btn-secondary btn-sm">
              <i class="fas fa-download"></i> Descargar
            </a>
          </div>
        </div>
      </div>
      <div class="col-12" v-if="!items.length">
        <div class="text-center text-muted py-5">Sin imagenes geoespaciales registradas.</div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'ImagenesGeoIndex',
  props: {
    imagenes: { type: Array, default: () => [] },
    proyectos: { type: Array, default: () => [] },
    ensayos: { type: Array, default: () => [] },
    puedeSubir: { type: Boolean, default: false },
    puedeEliminar: { type: Boolean, default: false },
  },
  data() {
    return {
      items: this.imagenes.slice(),
      form: this.formVacio(),
      errors: {},
      subiendo: false,
    };
  },
  methods: {
    formVacio() {
      return {
        ensayo_id: null, proyecto_id: null, tipo: 'RGB', fecha: new Date().toISOString().substr(0, 10),
        sensor: '', resolucion: '', bandas: '', notas: '', archivo: null,
      };
    },
    badgeTipo(tipo) {
      return { NDVI: 'badge-success', RGB: 'badge-info', Termica: 'badge-danger', Multiespectral: 'badge-warning' }[tipo] || 'badge-light';
    },
    onFile(e) {
      this.form.archivo = e.target.files[0] || null;
    },
    subir() {
      this.subiendo = true;
      this.errors = {};
      const ensayo = this.ensayos.find((e) => e.id === this.form.ensayo_id);
      const fd = new FormData();
      fd.append('ensayo_id', this.form.ensayo_id || '');
      if (ensayo) fd.append('proyecto_id', ensayo.proyecto_id);
      fd.append('tipo', this.form.tipo);
      fd.append('fecha', this.form.fecha);
      fd.append('sensor', this.form.sensor || '');
      fd.append('resolucion', this.form.resolucion || '');
      fd.append('bandas', this.form.bandas || '');
      fd.append('notas', this.form.notas || '');
      if (this.form.archivo) fd.append('archivo', this.form.archivo);

      window.axios.post('/soporte/imagenes-geo', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
        .then(({ data }) => {
          this.items.unshift(data);
          this.form = this.formVacio();
          if (this.$refs.fileInput) this.$refs.fileInput.value = '';
          this.$swal.fire({ icon: 'success', title: 'Imagen subida', timer: 1200, showConfirmButton: false });
        })
        .catch((err) => {
          if (err.response && err.response.status === 422) {
            this.errors = err.response.data.errors;
          } else {
            this.$swal.fire({ icon: 'error', title: 'Ocurrio un error' });
          }
        })
        .finally(() => { this.subiendo = false; });
    },
    eliminar(img) {
      this.$swal.fire({
        title: `Eliminar "${img.nombre}"?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#c4471b',
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/soporte/imagenes-geo/${img.id}`).then(() => {
          this.items = this.items.filter((i) => i.id !== img.id);
          this.$swal.fire({ icon: 'success', title: 'Eliminado', timer: 1200, showConfirmButton: false });
        });
      });
    },
  },
};
</script>
