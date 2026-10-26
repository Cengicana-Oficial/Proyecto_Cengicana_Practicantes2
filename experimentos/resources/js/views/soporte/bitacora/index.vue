<template>
  <div>
    <div class="card">
      <div class="card-header">
        <div class="row">
          <div class="col-md-4">
            <select class="form-control" v-model="filtroEnsayo">
              <option value="">Todos los ensayos</option>
              <option v-for="e in ensayos" :key="e.id" :value="e.id">{{ e.codigo }}</option>
            </select>
          </div>
          <div class="col-md-4">
            <select class="form-control" v-model="filtroTipo">
              <option value="">Todos los tipos</option>
              <option v-for="t in tipos" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
          <div class="col-md-4 text-right">
            <button v-if="puedeEscribir" class="btn btn-success" @click="abrirCrear">
              <i class="fas fa-plus"></i> Nueva entrada
            </button>
          </div>
        </div>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Ensayo</th>
              <th>Tipo</th>
              <th>Descripcion</th>
              <th>Responsable</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="b in entradasFiltradas" :key="b.id">
              <td>{{ b.fecha }}</td>
              <td>{{ b.ensayo ? b.ensayo.codigo : '-' }}</td>
              <td><span class="badge badge-light">{{ b.tipo }}</span></td>
              <td>{{ b.descripcion }}</td>
              <td>{{ b.responsable ? b.responsable.name : '-' }}</td>
              <td class="text-right">
                <a href="#" class="text-muted mr-2" v-if="puedeEscribir" @click.prevent="abrirEditar(b)"><i class="fas fa-pen"></i></a>
                <a href="#" class="text-danger" v-if="puedeEliminar" @click.prevent="eliminar(b)"><i class="fas fa-trash"></i></a>
              </td>
            </tr>
            <tr v-if="!entradasFiltradas.length">
              <td colspan="6" class="text-center text-muted py-4">Sin entradas de bitacora.</td>
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
              <h5 class="modal-title">{{ editando ? 'Editar entrada' : 'Nueva entrada de bitacora' }}</h5>
              <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
              <div class="form-group">
                <label>Ensayo</label>
                <select class="form-control" :class="{ 'is-invalid': errors.ensayo_id }" v-model="form.ensayo_id">
                  <option :value="null">Seleccione...</option>
                  <option v-for="e in ensayos" :key="e.id" :value="e.id">{{ e.codigo }}</option>
                </select>
                <span class="invalid-feedback" v-if="errors.ensayo_id">{{ errors.ensayo_id[0] }}</span>
              </div>
              <div class="form-group">
                <label>Fecha</label>
                <input type="date" class="form-control" :class="{ 'is-invalid': errors.fecha }" v-model="form.fecha">
                <span class="invalid-feedback" v-if="errors.fecha">{{ errors.fecha[0] }}</span>
              </div>
              <div class="form-group">
                <label>Tipo</label>
                <select class="form-control" v-model="form.tipo">
                  <option v-for="t in tipos" :key="t" :value="t">{{ t }}</option>
                </select>
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
  name: 'BitacoraIndex',
  props: {
    entradas: { type: Array, default: () => [] },
    ensayos: { type: Array, default: () => [] },
    tipos: { type: Array, default: () => [] },
    puedeEscribir: { type: Boolean, default: false },
    puedeEliminar: { type: Boolean, default: false },
  },
  data() {
    return {
      items: this.entradas.slice(),
      filtroEnsayo: '',
      filtroTipo: '',
      editando: null,
      form: this.formVacio(),
      errors: {},
      guardando: false,
    };
  },
  computed: {
    entradasFiltradas() {
      return this.items.filter((b) => {
        const matchEns = !this.filtroEnsayo || b.ensayo_id === this.filtroEnsayo;
        const matchTipo = !this.filtroTipo || b.tipo === this.filtroTipo;
        return matchEns && matchTipo;
      });
    },
  },
  methods: {
    formVacio() {
      return { ensayo_id: null, fecha: new Date().toISOString().substr(0, 10), tipo: this.tipos[0] || 'Otro', descripcion: '' };
    },
    abrirCrear() {
      this.editando = null;
      this.form = this.formVacio();
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    abrirEditar(b) {
      this.editando = b;
      this.form = { ensayo_id: b.ensayo_id, fecha: b.fecha, tipo: b.tipo, descripcion: b.descripcion };
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    guardar() {
      this.guardando = true;
      this.errors = {};
      const peticion = this.editando
        ? window.axios.put(`/soporte/bitacora/${this.editando.id}`, this.form)
        : window.axios.post('/soporte/bitacora', this.form);

      peticion
        .then(({ data }) => {
          if (this.editando) {
            const idx = this.items.findIndex((i) => i.id === data.id);
            this.$set(this.items, idx, data);
          } else {
            this.items.unshift(data);
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
    eliminar(b) {
      this.$swal.fire({
        title: 'Eliminar esta entrada?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#c4471b',
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/soporte/bitacora/${b.id}`).then(() => {
          this.items = this.items.filter((i) => i.id !== b.id);
          this.$swal.fire({ icon: 'success', title: 'Eliminado', timer: 1200, showConfirmButton: false });
        });
      });
    },
  },
};
</script>
