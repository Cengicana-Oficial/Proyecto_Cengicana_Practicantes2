<template>
  <div>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <input
        type="text"
        class="form-control w-auto"
        style="min-width: 260px"
        placeholder="Buscar programa..."
        v-model="busqueda"
      >
      <button v-if="puedeEditar" class="btn btn-success" @click="abrirCrear">
        <i class="fas fa-plus"></i> Nuevo programa
      </button>
    </div>

    <div class="row">
      <div class="col-md-4" v-for="p in programasFiltrados" :key="p.id">
        <div class="card" :style="{ borderLeft: '4px solid ' + p.color }">
          <div class="card-body">
            <div class="d-flex justify-content-between">
              <h5 class="card-title mb-1">{{ p.nombre }}</h5>
              <div v-if="puedeEditar">
                <a href="#" class="text-muted mr-2" @click.prevent="abrirEditar(p)"><i class="fas fa-pen"></i></a>
                <a href="#" class="text-danger" @click.prevent="eliminar(p)"><i class="fas fa-trash"></i></a>
              </div>
            </div>
            <p class="text-muted small mb-2">{{ p.lider || 'Sin lider asignado' }}</p>
            <p class="mb-2">{{ p.descripcion }}</p>
            <span class="badge badge-light">{{ p.proyectos_count }} proyecto(s)</span>
          </div>
        </div>
      </div>
      <div class="col-12" v-if="!programasFiltrados.length">
        <div class="text-center text-muted py-5">Sin programas para mostrar.</div>
      </div>
    </div>

    <div class="modal fade" ref="modal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <form @submit.prevent="guardar">
            <div class="modal-header">
              <h5 class="modal-title">{{ editando ? 'Editar programa' : 'Nuevo programa' }}</h5>
              <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
              <div class="form-group">
                <label>Nombre</label>
                <input type="text" class="form-control" :class="{ 'is-invalid': errors.nombre }" v-model="form.nombre">
                <span class="invalid-feedback" v-if="errors.nombre">{{ errors.nombre[0] }}</span>
              </div>
              <div class="form-group">
                <label>Lider</label>
                <input type="text" class="form-control" :class="{ 'is-invalid': errors.lider }" v-model="form.lider">
                <span class="invalid-feedback" v-if="errors.lider">{{ errors.lider[0] }}</span>
              </div>
              <div class="form-group">
                <label>Descripcion</label>
                <textarea class="form-control" :class="{ 'is-invalid': errors.descripcion }" v-model="form.descripcion" rows="3"></textarea>
                <span class="invalid-feedback" v-if="errors.descripcion">{{ errors.descripcion[0] }}</span>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-success" :disabled="guardando">Guardar</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'ProgramasIndex',
  props: {
    programas: { type: Array, default: () => [] },
    puedeEditar: { type: Boolean, default: false },
  },
  data() {
    return {
      items: this.programas.slice(),
      busqueda: '',
      editando: null,
      form: { nombre: '', lider: '', descripcion: '' },
      errors: {},
      guardando: false,
    };
  },
  computed: {
    programasFiltrados() {
      const q = this.busqueda.trim().toLowerCase();
      if (!q) return this.items;
      return this.items.filter((p) => (p.nombre + ' ' + (p.lider || '')).toLowerCase().includes(q));
    },
  },
  methods: {
    abrirCrear() {
      this.editando = null;
      this.form = { nombre: '', lider: '', descripcion: '' };
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    abrirEditar(p) {
      this.editando = p;
      this.form = { nombre: p.nombre, lider: p.lider, descripcion: p.descripcion };
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    guardar() {
      this.guardando = true;
      this.errors = {};
      const peticion = this.editando
        ? window.axios.put(`/investigacion/programas/${this.editando.id}`, this.form)
        : window.axios.post('/investigacion/programas', this.form);

      peticion
        .then(({ data }) => {
          if (this.editando) {
            const idx = this.items.findIndex((i) => i.id === data.id);
            this.$set(this.items, idx, data);
          } else {
            this.items.push(data);
          }
          window.$(this.$refs.modal).modal('hide');
          this.$swal.fire({ icon: 'success', title: 'Guardado', timer: 1200, showConfirmButton: false });
        })
        .catch((err) => {
          if (err.response && err.response.status === 422) {
            this.errors = err.response.data.errors;
          } else {
            this.$swal.fire({ icon: 'error', title: 'Ocurrio un error' });
          }
        })
        .finally(() => { this.guardando = false; });
    },
    eliminar(p) {
      this.$swal.fire({
        title: `Eliminar "${p.nombre}"?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#c4471b',
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/investigacion/programas/${p.id}`).then(() => {
          this.items = this.items.filter((i) => i.id !== p.id);
          this.$swal.fire({ icon: 'success', title: 'Eliminado', timer: 1200, showConfirmButton: false });
        });
      });
    },
  },
};
</script>
