<template>
  <div>
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <input type="text" class="form-control w-auto" style="min-width: 260px" placeholder="Buscar usuario..." v-model="busqueda">
        <button class="btn btn-success" @click="abrirCrear">
          <i class="fas fa-plus"></i> Nuevo usuario
        </button>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Email</th>
              <th>Rol</th>
              <th>Programas</th>
              <th>Ingenio</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in usuariosFiltrados" :key="u.id">
              <td>{{ u.name }}</td>
              <td>{{ u.email }}</td>
              <td>
                <span class="badge" :style="{ backgroundColor: rolColor(u.roles[0] && u.roles[0].name), color: '#fff' }">
                  {{ rolLabel(u.roles[0] && u.roles[0].name) }}
                </span>
              </td>
              <td>
                <span class="badge badge-light mr-1" v-for="p in u.programas" :key="p.id">{{ p.nombre }}</span>
              </td>
              <td>{{ u.ingenio ? u.ingenio.nombre : '-' }}</td>
              <td class="text-right">
                <a href="#" class="text-muted mr-2" @click.prevent="abrirEditar(u)"><i class="fas fa-pen"></i></a>
                <a href="#" class="text-danger" v-if="u.id !== usuarioActualId" @click.prevent="eliminar(u)"><i class="fas fa-trash"></i></a>
              </td>
            </tr>
            <tr v-if="!usuariosFiltrados.length">
              <td colspan="6" class="text-center text-muted py-4">Sin usuarios para mostrar.</td>
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
              <h5 class="modal-title">{{ editando ? 'Editar usuario' : 'Nuevo usuario' }}</h5>
              <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
              <div class="row">
                <div class="col-md-6 form-group">
                  <label>Nombre</label>
                  <input type="text" class="form-control" :class="{ 'is-invalid': errors.name }" v-model="form.name">
                  <span class="invalid-feedback" v-if="errors.name">{{ errors.name[0] }}</span>
                </div>
                <div class="col-md-6 form-group">
                  <label>Email</label>
                  <input type="email" class="form-control" :class="{ 'is-invalid': errors.email }" v-model="form.email">
                  <span class="invalid-feedback" v-if="errors.email">{{ errors.email[0] }}</span>
                </div>
              </div>
              <div class="row">
                <div class="col-md-6 form-group">
                  <label>Password {{ editando ? '(dejar en blanco para no cambiar)' : '' }}</label>
                  <input type="password" class="form-control" :class="{ 'is-invalid': errors.password }" v-model="form.password">
                  <span class="invalid-feedback" v-if="errors.password">{{ errors.password[0] }}</span>
                </div>
                <div class="col-md-6 form-group">
                  <label>Rol</label>
                  <select class="form-control" :class="{ 'is-invalid': errors.rol }" v-model="form.rol">
                    <option v-for="(info, clave) in roles" :key="clave" :value="clave">{{ info.label }}</option>
                  </select>
                  <span class="invalid-feedback" v-if="errors.rol">{{ errors.rol[0] }}</span>
                </div>
              </div>
              <div class="form-group" v-if="form.rol === 'ingenio'">
                <label>Ingenio</label>
                <select class="form-control" v-model="form.ingenio_id">
                  <option :value="null">Seleccione...</option>
                  <option v-for="i in ingenios" :key="i.id" :value="i.id">{{ i.nombre }}</option>
                </select>
              </div>
              <div class="form-group">
                <label>Programas asignados</label>
                <div class="row">
                  <div class="col-md-4" v-for="p in programas" :key="p.id">
                    <div class="form-check">
                      <input type="checkbox" class="form-check-input" :id="'prog-'+p.id" :value="p.id" v-model="form.programas">
                      <label class="form-check-label" :for="'prog-'+p.id">{{ p.nombre }}</label>
                    </div>
                  </div>
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
  name: 'UsuariosIndex',
  props: {
    usuarios: { type: Array, default: () => [] },
    roles: { type: Object, default: () => ({}) },
    programas: { type: Array, default: () => [] },
    ingenios: { type: Array, default: () => [] },
    usuarioActualId: { type: Number, default: null },
  },
  data() {
    return {
      items: this.usuarios.slice(),
      busqueda: '',
      editando: null,
      form: this.formVacio(),
      errors: {},
      guardando: false,
    };
  },
  computed: {
    usuariosFiltrados() {
      const q = this.busqueda.trim().toLowerCase();
      if (!q) return this.items;
      return this.items.filter((u) => (u.name + ' ' + u.email).toLowerCase().includes(q));
    },
  },
  methods: {
    rolLabel(clave) {
      return this.roles[clave] ? this.roles[clave].label : (clave || 'Sin rol');
    },
    rolColor(clave) {
      return this.roles[clave] ? this.roles[clave].color : '#CED2D5';
    },
    formVacio() {
      return { name: '', email: '', password: '', rol: 'investigador', ingenio_id: null, programas: [] };
    },
    abrirCrear() {
      this.editando = null;
      this.form = this.formVacio();
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    abrirEditar(u) {
      this.editando = u;
      this.form = {
        name: u.name,
        email: u.email,
        password: '',
        rol: u.roles[0] ? u.roles[0].name : 'investigador',
        ingenio_id: u.ingenio ? u.ingenio.id : null,
        programas: u.programas.map((p) => p.id),
      };
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    guardar() {
      this.guardando = true;
      this.errors = {};
      const peticion = this.editando
        ? window.axios.put(`/admin/usuarios/${this.editando.id}`, this.form)
        : window.axios.post('/admin/usuarios', this.form);

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
            this.errors = err.response.data.errors || {};
            if (err.response.data.message && !err.response.data.errors) {
              this.$swal.fire({ icon: 'error', title: err.response.data.message });
            }
          } else {
            this.$swal.fire({ icon: 'error', title: 'Ocurrio un error' });
          }
        })
        .finally(() => { this.guardando = false; });
    },
    eliminar(u) {
      this.$swal.fire({
        title: `Eliminar a "${u.name}"?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#c4471b',
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/admin/usuarios/${u.id}`).then(() => {
          this.items = this.items.filter((i) => i.id !== u.id);
          this.$swal.fire({ icon: 'success', title: 'Eliminado', timer: 1200, showConfirmButton: false });
        }).catch((err) => {
          this.$swal.fire({ icon: 'error', title: err.response?.data?.message || 'No se pudo eliminar' });
        });
      });
    },
  },
};
</script>
