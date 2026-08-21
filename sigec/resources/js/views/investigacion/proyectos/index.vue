<template>
  <div>
    <div class="card">
      <div class="card-header">
        <div class="row">
          <div class="col-md-4">
            <input type="text" class="form-control" placeholder="Buscar por codigo o nombre..." v-model="busqueda">
          </div>
          <div class="col-md-3">
            <select class="form-control" v-model="filtroEstado">
              <option value="">Todos los estados</option>
              <option value="Activo">Activo</option>
              <option value="Pausado">Pausado</option>
              <option value="Finalizado">Finalizado</option>
            </select>
          </div>
          <div class="col-md-5 text-right">
            <button v-if="puedeCrear" class="btn btn-success" @click="abrirCrear">
              <i class="fas fa-plus"></i> Nuevo proyecto
            </button>
          </div>
        </div>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Codigo</th>
              <th>Nombre</th>
              <th>Programa</th>
              <th>Responsable</th>
              <th>Inicio</th>
              <th>Fin</th>
              <th>Estado</th>
              <th>Ensayos</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in proyectosFiltrados" :key="p.id">
              <td><b>{{ p.codigo }}</b></td>
              <td>{{ p.nombre }}</td>
              <td>{{ p.programa ? p.programa.nombre : '-' }}</td>
              <td>{{ p.responsable ? p.responsable.name : '-' }}</td>
              <td>{{ p.inicio }}</td>
              <td>{{ p.fin }}</td>
              <td><span :class="'badge ' + badgeEstado(p.estado)">{{ p.estado }}</span></td>
              <td>{{ p.ensayos_count }}</td>
              <td class="text-right">
                <a href="#" class="text-muted mr-2" v-if="puedeEditar" @click.prevent="abrirEditar(p)"><i class="fas fa-pen"></i></a>
                <a href="#" class="text-danger" v-if="puedeEliminar" @click.prevent="eliminar(p)"><i class="fas fa-trash"></i></a>
              </td>
            </tr>
            <tr v-if="!proyectosFiltrados.length">
              <td colspan="9" class="text-center text-muted py-4">Sin proyectos para mostrar.</td>
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
              <h5 class="modal-title">{{ editando ? 'Editar proyecto' : 'Nuevo proyecto' }}</h5>
              <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
              <div class="row">
                <div class="col-md-4 form-group">
                  <label>Codigo</label>
                  <input type="text" class="form-control" :class="{ 'is-invalid': errors.codigo }" v-model="form.codigo">
                  <span class="invalid-feedback" v-if="errors.codigo">{{ errors.codigo[0] }}</span>
                </div>
                <div class="col-md-8 form-group">
                  <label>Nombre</label>
                  <input type="text" class="form-control" :class="{ 'is-invalid': errors.nombre }" v-model="form.nombre">
                  <span class="invalid-feedback" v-if="errors.nombre">{{ errors.nombre[0] }}</span>
                </div>
              </div>
              <div class="row">
                <div class="col-md-6 form-group">
                  <label>Programa</label>
                  <select class="form-control" :class="{ 'is-invalid': errors.programa_id }" v-model="form.programa_id">
                    <option :value="null">Seleccione...</option>
                    <option v-for="prog in programas" :key="prog.id" :value="prog.id">{{ prog.nombre }}</option>
                  </select>
                  <span class="invalid-feedback" v-if="errors.programa_id">{{ errors.programa_id[0] }}</span>
                </div>
                <div class="col-md-6 form-group">
                  <label>Responsable</label>
                  <select class="form-control" v-model="form.responsable_id">
                    <option :value="null">Sin asignar</option>
                    <option v-for="u in responsables" :key="u.id" :value="u.id">{{ u.name }}</option>
                  </select>
                </div>
              </div>
              <div class="form-group">
                <label>Objetivo</label>
                <textarea class="form-control" v-model="form.objetivo" rows="2"></textarea>
              </div>
              <div class="row">
                <div class="col-md-4 form-group">
                  <label>Inicio</label>
                  <input type="date" class="form-control" v-model="form.inicio">
                </div>
                <div class="col-md-4 form-group">
                  <label>Fin</label>
                  <input type="date" class="form-control" :class="{ 'is-invalid': errors.fin }" v-model="form.fin">
                  <span class="invalid-feedback" v-if="errors.fin">{{ errors.fin[0] }}</span>
                </div>
                <div class="col-md-4 form-group">
                  <label>Estado</label>
                  <select class="form-control" v-model="form.estado">
                    <option value="Activo">Activo</option>
                    <option value="Pausado">Pausado</option>
                    <option value="Finalizado">Finalizado</option>
                  </select>
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
  name: 'ProyectosIndex',
  props: {
    proyectos: { type: Array, default: () => [] },
    programas: { type: Array, default: () => [] },
    responsables: { type: Array, default: () => [] },
    puedeCrear: { type: Boolean, default: false },
    puedeEditar: { type: Boolean, default: false },
    puedeEliminar: { type: Boolean, default: false },
  },
  data() {
    return {
      items: this.proyectos.slice(),
      busqueda: '',
      filtroEstado: '',
      editando: null,
      form: this.formVacio(),
      errors: {},
      guardando: false,
    };
  },
  computed: {
    proyectosFiltrados() {
      const q = this.busqueda.trim().toLowerCase();
      return this.items.filter((p) => {
        const matchQ = !q || (p.codigo + ' ' + p.nombre).toLowerCase().includes(q);
        const matchEstado = !this.filtroEstado || p.estado === this.filtroEstado;
        return matchQ && matchEstado;
      });
    },
  },
  methods: {
    formVacio() {
      return { codigo: '', nombre: '', programa_id: null, responsable_id: null, objetivo: '', inicio: '', fin: '', estado: 'Activo' };
    },
    badgeEstado(estado) {
      return { Activo: 'badge-success', Pausado: 'badge-warning', Finalizado: 'badge-secondary' }[estado] || 'badge-light';
    },
    abrirCrear() {
      this.editando = null;
      this.form = this.formVacio();
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    abrirEditar(p) {
      this.editando = p;
      this.form = {
        codigo: p.codigo, nombre: p.nombre, programa_id: p.programa_id, responsable_id: p.responsable_id,
        objetivo: p.objetivo, inicio: p.inicio, fin: p.fin, estado: p.estado,
      };
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    guardar() {
      this.guardando = true;
      this.errors = {};
      const peticion = this.editando
        ? window.axios.put(`/investigacion/proyectos/${this.editando.id}`, this.form)
        : window.axios.post('/investigacion/proyectos', this.form);

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
        title: `Eliminar "${p.codigo}"?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#c4471b',
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/investigacion/proyectos/${p.id}`).then(() => {
          this.items = this.items.filter((i) => i.id !== p.id);
          this.$swal.fire({ icon: 'success', title: 'Eliminado', timer: 1200, showConfirmButton: false });
        });
      });
    },
  },
};
</script>
