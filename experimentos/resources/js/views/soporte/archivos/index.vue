<template>
  <div>
    <div class="card" v-if="puedeSubir">
      <div class="card-header">
        <h3 class="card-title">Subir archivo</h3>
      </div>
      <div class="card-body">
        <form @submit.prevent="subir">
          <div class="row">
            <div class="col-md-3 form-group">
              <label>Proyecto</label>
              <select class="form-control" :class="{ 'is-invalid': errors.proyecto_id }" v-model="form.proyecto_id">
                <option :value="null">Seleccione...</option>
                <option v-for="p in proyectos" :key="p.id" :value="p.id">{{ p.codigo }}</option>
              </select>
              <span class="invalid-feedback" v-if="errors.proyecto_id">{{ errors.proyecto_id[0] }}</span>
            </div>
            <div class="col-md-3 form-group">
              <label>Ensayo</label>
              <select class="form-control" v-model="form.ensayo_id">
                <option :value="null">Sin asignar</option>
                <option v-for="e in ensayosDelProyecto" :key="e.id" :value="e.id">{{ e.codigo }}</option>
              </select>
            </div>
            <div class="col-md-3 form-group">
              <label>Carpeta</label>
              <input type="text" class="form-control" v-model="form.carpeta" placeholder="Protocolo, Datos...">
            </div>
            <div class="col-md-3 form-group">
              <label>Archivo</label>
              <input type="file" class="form-control-file" :class="{ 'is-invalid': errors.archivo }" @change="onFile" ref="fileInput">
              <span class="invalid-feedback d-block" v-if="errors.archivo">{{ errors.archivo[0] }}</span>
            </div>
          </div>
          <button type="submit" class="btn btn-success" :disabled="subiendo">
            <i class="fas fa-upload"></i> Subir
          </button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-body p-0">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Proyecto</th>
              <th>Ensayo</th>
              <th>Carpeta</th>
              <th>Tipo</th>
              <th>Tamano</th>
              <th>Fecha</th>
              <th>Subido por</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="a in items" :key="a.id">
              <td>{{ a.nombre }}</td>
              <td>{{ a.proyecto ? a.proyecto.codigo : '-' }}</td>
              <td>{{ a.ensayo ? a.ensayo.codigo : '-' }}</td>
              <td>{{ a.carpeta }}</td>
              <td><span class="badge badge-light">{{ a.tipo }}</span></td>
              <td>{{ formatoTamano(a.tamano) }}</td>
              <td>{{ a.fecha }}</td>
              <td>{{ a.subido_por_rel ? a.subido_por_rel.name : (a.subidoPor ? a.subidoPor.name : '-') }}</td>
              <td class="text-right">
                <a :href="'/storage/' + a.path" target="_blank" class="text-muted mr-2"><i class="fas fa-download"></i></a>
                <a href="#" class="text-danger" v-if="puedeEliminar" @click.prevent="eliminar(a)"><i class="fas fa-trash"></i></a>
              </td>
            </tr>
            <tr v-if="!items.length">
              <td colspan="9" class="text-center text-muted py-4">Sin archivos para mostrar.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'ArchivosIndex',
  props: {
    archivos: { type: Array, default: () => [] },
    proyectos: { type: Array, default: () => [] },
    ensayos: { type: Array, default: () => [] },
    puedeSubir: { type: Boolean, default: false },
    puedeEliminar: { type: Boolean, default: false },
  },
  data() {
    return {
      items: this.archivos.slice(),
      form: { proyecto_id: null, ensayo_id: null, carpeta: '', archivo: null },
      errors: {},
      subiendo: false,
    };
  },
  computed: {
    ensayosDelProyecto() {
      if (!this.form.proyecto_id) return [];
      return this.ensayos.filter((e) => e.proyecto_id === this.form.proyecto_id);
    },
  },
  methods: {
    formatoTamano(bytes) {
      if (!bytes) return '-';
      const kb = bytes / 1024;
      return kb < 1024 ? `${kb.toFixed(0)} KB` : `${(kb / 1024).toFixed(1)} MB`;
    },
    onFile(e) {
      this.form.archivo = e.target.files[0] || null;
    },
    subir() {
      this.subiendo = true;
      this.errors = {};
      const fd = new FormData();
      fd.append('proyecto_id', this.form.proyecto_id || '');
      if (this.form.ensayo_id) fd.append('ensayo_id', this.form.ensayo_id);
      fd.append('carpeta', this.form.carpeta || '');
      if (this.form.archivo) fd.append('archivo', this.form.archivo);

      window.axios.post('/soporte/archivos', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
        .then(({ data }) => {
          this.items.unshift(data);
          this.form = { proyecto_id: null, ensayo_id: null, carpeta: '', archivo: null };
          if (this.$refs.fileInput) this.$refs.fileInput.value = '';
          this.$swal.fire({ icon: 'success', title: 'Archivo subido', timer: 1200, showConfirmButton: false });
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
    eliminar(a) {
      this.$swal.fire({
        title: `Eliminar "${a.nombre}"?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#c4471b',
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/soporte/archivos/${a.id}`).then(() => {
          this.items = this.items.filter((i) => i.id !== a.id);
          this.$swal.fire({ icon: 'success', title: 'Eliminado', timer: 1200, showConfirmButton: false });
        });
      });
    },
  },
};
</script>
