<template>
  <div>
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <input type="text" class="form-control w-auto" style="min-width: 260px" placeholder="Buscar variable..." v-model="busqueda">
        <button v-if="puedeEditar" class="btn btn-success" @click="abrirCrear">
          <i class="fas fa-plus"></i> Nueva variable
        </button>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Unidad</th>
              <th>Tipo</th>
              <th>Categoria</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="v in variablesFiltradas" :key="v.id">
              <td>{{ v.nombre }}</td>
              <td>{{ v.unidad }}</td>
              <td>{{ v.tipo }}</td>
              <td><span :class="'badge ' + (v.categoria === 'Cosecha' ? 'badge-success' : 'badge-info')">{{ v.categoria }}</span></td>
              <td class="text-right">
                <template v-if="puedeEditar">
                  <a href="#" class="text-muted mr-2" @click.prevent="abrirEditar(v)"><i class="fas fa-pen"></i></a>
                  <a href="#" class="text-danger" @click.prevent="eliminar(v)"><i class="fas fa-trash"></i></a>
                </template>
              </td>
            </tr>
            <tr v-if="!variablesFiltradas.length">
              <td colspan="5" class="text-center text-muted py-4">Sin variables para mostrar.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="modal fade" ref="modal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <form @submit.prevent="guardar">
            <div class="modal-header">
              <h5 class="modal-title">{{ editando ? 'Editar variable' : 'Nueva variable' }}</h5>
              <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
              <div class="form-group">
                <label>Nombre</label>
                <input type="text" class="form-control" :class="{ 'is-invalid': errors.nombre }" v-model="form.nombre">
                <span class="invalid-feedback" v-if="errors.nombre">{{ errors.nombre[0] }}</span>
              </div>
              <div class="form-group">
                <label>Unidad</label>
                <input type="text" class="form-control" v-model="form.unidad">
              </div>
              <div class="form-group">
                <label>Tipo</label>
                <select class="form-control" v-model="form.tipo">
                  <option value="Numerica">Numerica</option>
                  <option value="Categorica">Categorica</option>
                  <option value="Texto">Texto</option>
                </select>
              </div>
              <div class="form-group">
                <label>Categoria</label>
                <select class="form-control" v-model="form.categoria">
                  <option value="Desarrollo">Desarrollo</option>
                  <option value="Cosecha">Cosecha</option>
                </select>
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
  name: 'VariablesIndex',
  props: {
    variables: { type: Array, default: () => [] },
    puedeEditar: { type: Boolean, default: false },
  },
  data() {
    return {
      items: this.variables.slice(),
      busqueda: '',
      editando: null,
      form: this.formVacio(),
      errors: {},
      guardando: false,
    };
  },
  computed: {
    variablesFiltradas() {
      const q = this.busqueda.trim().toLowerCase();
      if (!q) return this.items;
      return this.items.filter((v) => v.nombre.toLowerCase().includes(q));
    },
  },
  methods: {
    formVacio() {
      return { nombre: '', unidad: '', tipo: 'Numerica', categoria: 'Desarrollo' };
    },
    abrirCrear() {
      this.editando = null;
      this.form = this.formVacio();
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    abrirEditar(v) {
      this.editando = v;
      this.form = { nombre: v.nombre, unidad: v.unidad, tipo: v.tipo, categoria: v.categoria };
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    guardar() {
      this.guardando = true;
      this.errors = {};
      const peticion = this.editando
        ? window.axios.put(`/investigacion/variables/${this.editando.id}`, this.form)
        : window.axios.post('/investigacion/variables', this.form);

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
    eliminar(v) {
      this.$swal.fire({
        title: `Eliminar "${v.nombre}"?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#c4471b',
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/investigacion/variables/${v.id}`).then(() => {
          this.items = this.items.filter((i) => i.id !== v.id);
          this.$swal.fire({ icon: 'success', title: 'Eliminado', timer: 1200, showConfirmButton: false });
        });
      });
    },
  },
};
</script>
