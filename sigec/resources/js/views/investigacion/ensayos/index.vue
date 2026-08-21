<template>
  <div>
    <div class="card">
      <div class="card-header">
        <div class="row">
          <div class="col-md-4">
            <input type="text" class="form-control" placeholder="Buscar por codigo, finca o variedad..." v-model="busqueda">
          </div>
          <div class="col-md-3">
            <select class="form-control" v-model="filtroEstado">
              <option value="">Todos los estados</option>
              <option value="Planificado">Planificado</option>
              <option value="En campo">En campo</option>
              <option value="Finalizado">Finalizado</option>
            </select>
          </div>
          <div class="col-md-5 text-right">
            <button v-if="puedeCrear" class="btn btn-success" @click="abrirCrear">
              <i class="fas fa-plus"></i> Nuevo ensayo
            </button>
          </div>
        </div>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Codigo</th>
              <th>Proyecto</th>
              <th>Ingenio</th>
              <th>Finca / Lote</th>
              <th>Variedad</th>
              <th>Responsable</th>
              <th>Estado</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="e in ensayosFiltrados" :key="e.id">
              <td><b>{{ e.codigo }}</b></td>
              <td>{{ e.proyecto ? e.proyecto.codigo : '-' }}</td>
              <td>{{ e.ingenio ? e.ingenio.nombre : '-' }}</td>
              <td>{{ e.finca }} <span v-if="e.lote">/ {{ e.lote }}</span></td>
              <td>{{ e.variedad }}</td>
              <td>{{ e.responsable ? e.responsable.name : '-' }}</td>
              <td><span :class="'badge ' + badgeEstado(e.estado)">{{ e.estado }}</span></td>
              <td class="text-right">
                <a href="#" class="text-muted mr-2" v-if="puedeEditar" @click.prevent="abrirEditar(e)"><i class="fas fa-pen"></i></a>
                <a href="#" class="text-danger" v-if="puedeEliminar" @click.prevent="eliminar(e)"><i class="fas fa-trash"></i></a>
              </td>
            </tr>
            <tr v-if="!ensayosFiltrados.length">
              <td colspan="8" class="text-center text-muted py-4">Sin ensayos para mostrar.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="modal fade" ref="modal" tabindex="-1">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <form @submit.prevent="guardar">
            <div class="modal-header">
              <h5 class="modal-title">{{ editando ? 'Editar ensayo' : 'Nuevo ensayo' }}</h5>
              <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
              <div class="row">
                <div class="col-md-4 form-group">
                  <label>Codigo</label>
                  <input type="text" class="form-control" :class="{ 'is-invalid': errors.codigo }" v-model="form.codigo">
                  <span class="invalid-feedback" v-if="errors.codigo">{{ errors.codigo[0] }}</span>
                </div>
                <div class="col-md-4 form-group">
                  <label>Proyecto</label>
                  <select class="form-control" :class="{ 'is-invalid': errors.proyecto_id }" v-model="form.proyecto_id">
                    <option :value="null">Seleccione...</option>
                    <option v-for="p in proyectos" :key="p.id" :value="p.id">{{ p.codigo }} - {{ p.nombre }}</option>
                  </select>
                  <span class="invalid-feedback" v-if="errors.proyecto_id">{{ errors.proyecto_id[0] }}</span>
                </div>
                <div class="col-md-4 form-group">
                  <label>Ingenio</label>
                  <select class="form-control" v-model="form.ingenio_id">
                    <option :value="null">Sin asignar</option>
                    <option v-for="i in ingenios" :key="i.id" :value="i.id">{{ i.nombre }}</option>
                  </select>
                </div>
              </div>
              <div class="row">
                <div class="col-md-6 form-group">
                  <label>Finca</label>
                  <input type="text" class="form-control" v-model="form.finca">
                </div>
                <div class="col-md-6 form-group">
                  <label>Lote</label>
                  <input type="text" class="form-control" v-model="form.lote">
                </div>
              </div>
              <div class="row">
                <div class="col-md-4 form-group">
                  <label>Diseno</label>
                  <input type="text" class="form-control" v-model="form.diseno">
                </div>
                <div class="col-md-4 form-group">
                  <label>Cultivo</label>
                  <input type="text" class="form-control" v-model="form.cultivo">
                </div>
                <div class="col-md-4 form-group">
                  <label>Variedad</label>
                  <input type="text" class="form-control" v-model="form.variedad">
                </div>
              </div>
              <div class="row">
                <div class="col-md-4 form-group">
                  <label>Responsable</label>
                  <select class="form-control" v-model="form.responsable_id">
                    <option :value="null">Sin asignar</option>
                    <option v-for="u in responsables" :key="u.id" :value="u.id">{{ u.name }}</option>
                  </select>
                </div>
                <div class="col-md-4 form-group">
                  <label>Estado</label>
                  <select class="form-control" v-model="form.estado">
                    <option value="Planificado">Planificado</option>
                    <option value="En campo">En campo</option>
                    <option value="Finalizado">Finalizado</option>
                  </select>
                </div>
                <div class="col-md-4 form-group">
                  <label>Area por parcela (m²)</label>
                  <input type="number" step="0.01" class="form-control" v-model="form.area_parcela">
                </div>
              </div>
              <div class="row">
                <div class="col-md-3 form-group">
                  <label>Latitud</label>
                  <input type="number" step="0.000001" class="form-control" :class="{ 'is-invalid': errors.lat }" v-model="form.lat">
                  <span class="invalid-feedback" v-if="errors.lat">{{ errors.lat[0] }}</span>
                </div>
                <div class="col-md-3 form-group">
                  <label>Longitud</label>
                  <input type="number" step="0.000001" class="form-control" :class="{ 'is-invalid': errors.lng }" v-model="form.lng">
                  <span class="invalid-feedback" v-if="errors.lng">{{ errors.lng[0] }}</span>
                </div>
                <div class="col-md-3 form-group">
                  <label># Tratamientos</label>
                  <input type="number" class="form-control" v-model="form.num_tratamientos">
                </div>
                <div class="col-md-3 form-group">
                  <label># Repeticiones</label>
                  <input type="number" class="form-control" v-model="form.num_repeticiones">
                </div>
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
  name: 'EnsayosIndex',
  props: {
    ensayos: { type: Array, default: () => [] },
    proyectos: { type: Array, default: () => [] },
    ingenios: { type: Array, default: () => [] },
    responsables: { type: Array, default: () => [] },
    puedeCrear: { type: Boolean, default: false },
    puedeEditar: { type: Boolean, default: false },
    puedeEliminar: { type: Boolean, default: false },
  },
  data() {
    return {
      items: this.ensayos.slice(),
      busqueda: '',
      filtroEstado: '',
      editando: null,
      form: this.formVacio(),
      errors: {},
      guardando: false,
    };
  },
  computed: {
    ensayosFiltrados() {
      const q = this.busqueda.trim().toLowerCase();
      return this.items.filter((e) => {
        const matchQ = !q || (e.codigo + ' ' + (e.finca || '') + ' ' + (e.variedad || '')).toLowerCase().includes(q);
        const matchEstado = !this.filtroEstado || e.estado === this.filtroEstado;
        return matchQ && matchEstado;
      });
    },
  },
  methods: {
    formVacio() {
      return {
        codigo: '', proyecto_id: null, ingenio_id: null, finca: '', lote: '', diseno: '', cultivo: '',
        variedad: '', responsable_id: null, estado: 'Planificado', lat: null, lng: null,
        num_tratamientos: 0, num_repeticiones: 0, area_parcela: null,
      };
    },
    badgeEstado(estado) {
      return { Planificado: 'badge-warning', 'En campo': 'badge-success', Finalizado: 'badge-secondary' }[estado] || 'badge-light';
    },
    abrirCrear() {
      this.editando = null;
      this.form = this.formVacio();
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    abrirEditar(e) {
      this.editando = e;
      this.form = {
        codigo: e.codigo, proyecto_id: e.proyecto_id, ingenio_id: e.ingenio_id, finca: e.finca, lote: e.lote,
        diseno: e.diseno, cultivo: e.cultivo, variedad: e.variedad, responsable_id: e.responsable_id,
        estado: e.estado, lat: e.lat, lng: e.lng, num_tratamientos: e.num_tratamientos,
        num_repeticiones: e.num_repeticiones, area_parcela: e.area_parcela,
      };
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    guardar() {
      this.guardando = true;
      this.errors = {};
      const peticion = this.editando
        ? window.axios.put(`/investigacion/ensayos/${this.editando.id}`, this.form)
        : window.axios.post('/investigacion/ensayos', this.form);

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
    eliminar(e) {
      this.$swal.fire({
        title: `Eliminar "${e.codigo}"?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#c4471b',
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/investigacion/ensayos/${e.id}`).then(() => {
          this.items = this.items.filter((i) => i.id !== e.id);
          this.$swal.fire({ icon: 'success', title: 'Eliminado', timer: 1200, showConfirmButton: false });
        });
      });
    },
  },
};
</script>
