<template>
  <div>
    <div class="card">
      <div class="card-header">
        <div class="row">
          <div class="col-md-4">
            <select class="form-control" v-model="filtroVariable">
              <option value="">Todas las variables</option>
              <option v-for="v in variables" :key="v.id" :value="v.id">{{ v.nombre }}</option>
            </select>
          </div>
          <div class="col-md-4">
            <select class="form-control" v-model="filtroEnsayo">
              <option value="">Todos los ensayos</option>
              <option v-for="e in ensayosUnicos" :key="e.id" :value="e.id">{{ e.codigo }}</option>
            </select>
          </div>
          <div class="col-md-4 text-right">
            <button v-if="puedeRegistrar" class="btn btn-success" @click="abrirCrear">
              <i class="fas fa-plus"></i> Nueva evaluacion
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
              <th>Parcela</th>
              <th>Tratamiento</th>
              <th>Variable</th>
              <th>Valor</th>
              <th>Responsable</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="ev in evaluacionesFiltradas" :key="ev.id">
              <td>{{ ev.fecha }}</td>
              <td>{{ ev.parcela && ev.parcela.ensayo ? ev.parcela.ensayo.codigo : '-' }}</td>
              <td>{{ ev.parcela ? ev.parcela.codigo : '-' }}</td>
              <td>{{ ev.parcela && ev.parcela.tratamiento ? ev.parcela.tratamiento.codigo : '-' }}</td>
              <td>{{ ev.variable ? ev.variable.nombre : '-' }}</td>
              <td>{{ ev.valor }} <small class="text-muted" v-if="ev.variable">{{ ev.variable.unidad }}</small></td>
              <td>{{ ev.responsable ? ev.responsable.name : '-' }}</td>
              <td class="text-right">
                <template v-if="puedeRegistrar">
                  <a href="#" class="text-muted mr-2" @click.prevent="abrirEditar(ev)"><i class="fas fa-pen"></i></a>
                </template>
                <a href="#" class="text-danger" v-if="puedeEliminar" @click.prevent="eliminar(ev)"><i class="fas fa-trash"></i></a>
              </td>
            </tr>
            <tr v-if="!evaluacionesFiltradas.length">
              <td colspan="8" class="text-center text-muted py-4">Sin evaluaciones para mostrar.</td>
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
              <h5 class="modal-title">{{ editando ? 'Editar evaluacion' : 'Nueva evaluacion' }}</h5>
              <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
              <div class="form-group">
                <label>Fecha</label>
                <input type="date" class="form-control" :class="{ 'is-invalid': errors.fecha }" v-model="form.fecha">
                <span class="invalid-feedback" v-if="errors.fecha">{{ errors.fecha[0] }}</span>
              </div>
              <div class="form-group">
                <label>Parcela</label>
                <select class="form-control" :class="{ 'is-invalid': errors.parcela_id }" v-model="form.parcela_id">
                  <option :value="null">Seleccione...</option>
                  <option v-for="p in parcelas" :key="p.id" :value="p.id">
                    {{ p.ensayo ? p.ensayo.codigo : '' }} - {{ p.codigo }} ({{ p.tratamiento ? p.tratamiento.codigo : '' }} rep. {{ p.repeticion }})
                  </option>
                </select>
                <span class="invalid-feedback" v-if="errors.parcela_id">{{ errors.parcela_id[0] }}</span>
              </div>
              <div class="form-group">
                <label>Variable</label>
                <select class="form-control" :class="{ 'is-invalid': errors.variable_id }" v-model="form.variable_id">
                  <option :value="null">Seleccione...</option>
                  <option v-for="v in variables" :key="v.id" :value="v.id">{{ v.nombre }} ({{ v.unidad }})</option>
                </select>
                <span class="invalid-feedback" v-if="errors.variable_id">{{ errors.variable_id[0] }}</span>
              </div>
              <div class="form-group">
                <label>Valor</label>
                <input type="text" class="form-control" :class="{ 'is-invalid': errors.valor }" v-model="form.valor">
                <span class="invalid-feedback" v-if="errors.valor">{{ errors.valor[0] }}</span>
              </div>
              <div class="form-group">
                <label>Observaciones</label>
                <textarea class="form-control" v-model="form.obs" rows="2"></textarea>
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
  name: 'EvaluacionesIndex',
  props: {
    evaluaciones: { type: Array, default: () => [] },
    variables: { type: Array, default: () => [] },
    parcelas: { type: Array, default: () => [] },
    puedeRegistrar: { type: Boolean, default: false },
    puedeEliminar: { type: Boolean, default: false },
  },
  data() {
    return {
      items: this.evaluaciones.slice(),
      filtroVariable: '',
      filtroEnsayo: '',
      editando: null,
      form: this.formVacio(),
      errors: {},
      guardando: false,
    };
  },
  computed: {
    ensayosUnicos() {
      const map = new Map();
      this.parcelas.forEach((p) => { if (p.ensayo) map.set(p.ensayo.id, p.ensayo); });
      return Array.from(map.values());
    },
    evaluacionesFiltradas() {
      return this.items.filter((ev) => {
        const matchVar = !this.filtroVariable || ev.variable_id === this.filtroVariable;
        const matchEns = !this.filtroEnsayo || (ev.parcela && ev.parcela.ensayo && ev.parcela.ensayo.id === this.filtroEnsayo);
        return matchVar && matchEns;
      });
    },
  },
  methods: {
    formVacio() {
      return { fecha: new Date().toISOString().substr(0, 10), parcela_id: null, variable_id: null, valor: '', obs: '' };
    },
    abrirCrear() {
      this.editando = null;
      this.form = this.formVacio();
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    abrirEditar(ev) {
      this.editando = ev;
      this.form = { fecha: ev.fecha, parcela_id: ev.parcela_id, variable_id: ev.variable_id, valor: ev.valor, obs: ev.obs };
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    guardar() {
      this.guardando = true;
      this.errors = {};
      const peticion = this.editando
        ? window.axios.put(`/investigacion/evaluaciones/${this.editando.id}`, this.form)
        : window.axios.post('/investigacion/evaluaciones', this.form);

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
    eliminar(ev) {
      this.$swal.fire({
        title: 'Eliminar esta evaluacion?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#c4471b',
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/investigacion/evaluaciones/${ev.id}`).then(() => {
          this.items = this.items.filter((i) => i.id !== ev.id);
          this.$swal.fire({ icon: 'success', title: 'Eliminado', timer: 1200, showConfirmButton: false });
        });
      });
    },
  },
};
</script>
