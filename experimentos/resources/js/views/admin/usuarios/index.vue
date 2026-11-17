<template>
  <div>
    <div class="alert alert-light border d-flex flex-wrap align-items-center justify-content-between">
      <div class="mr-3">
        <strong><i class="fas fa-link text-success"></i> Usuarios del portal CENGICAÑA.</strong>
        Las personas se crean una sola vez en el portal; aquí solo se asigna su rol en Experimentos y sus programas.
        El rol también se puede asignar en el portal (Usuarios → módulo Experimentos).
      </div>
      <a class="btn btn-outline-success btn-sm mt-2 mt-md-0" href="/login/usuarios/usuarios.php" target="_blank" rel="noopener">
        <i class="fas fa-user-plus"></i> Crear o editar personas en el portal
      </a>
    </div>

    <div class="row mb-3">
      <div class="col-6 col-md mb-2" v-for="r in resumenRoles" :key="r.nombre">
        <div class="card mb-0 h-100" :style="{ borderTop: '3px solid ' + r.color }">
          <div class="card-body py-2 px-3">
            <div class="h4 mb-0">{{ r.total }}</div>
            <small class="text-muted">{{ r.nombre }}</small>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
        <div class="d-flex flex-wrap">
          <input type="text" class="form-control w-auto mr-2 mb-1" style="min-width: 240px" placeholder="Buscar por nombre o correo..." v-model="busqueda">
          <select class="form-control w-auto mb-1" v-model="filtroRol">
            <option value="">Todos los roles</option>
            <option value="superadmin">Superadmin</option>
            <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.nombre }}</option>
          </select>
        </div>
        <button class="btn btn-success mb-1" @click="abrirAgregar" :disabled="!sinAcceso.length">
          <i class="fas fa-user-check"></i> Dar acceso a Experimentos
        </button>
      </div>
      <div class="card-body p-0 table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr><th>Persona</th><th>Rol en Experimentos</th><th>Programas</th><th>Ingenio</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="u in filtrados" :key="u.portal_id">
              <td><strong>{{ u.nombre }}</strong><div class="small text-muted">{{ u.correo }}</div></td>
              <td>
                <span class="badge" :style="{ backgroundColor: color(u), color: '#fff' }">{{ u.rol || 'Sin rol' }}</span>
                <div class="small text-warning" v-if="!u.superadmin && !u.rol_sigec"><i class="fas fa-exclamation-triangle"></i> Asigne un rol de Experimentos</div>
              </td>
              <td>
                <span v-if="u.superadmin" class="small text-muted">Todos</span>
                <span class="badge badge-light mr-1" v-for="p in u.programas" :key="p.id">{{ p.nombre }}</span>
                <span v-if="!u.superadmin && !u.programas.length" class="small text-muted">—</span>
              </td>
              <td>
                {{ u.ingenio || '—' }}
                <div class="small text-warning" v-if="u.rol_sigec === 'ingenio' && !u.ingenio_id"><i class="fas fa-exclamation-triangle"></i> Falta el ingenio (se asigna en el portal)</div>
              </td>
              <td class="text-right text-nowrap">
                <template v-if="!u.superadmin && u.correo !== correoActual">
                  <a href="#" class="text-muted mr-2" title="Cambiar rol y programas" @click.prevent="abrirEditar(u)"><i class="fas fa-pen"></i></a>
                  <a href="#" class="text-danger" title="Quitar acceso a Experimentos" @click.prevent="quitar(u)"><i class="fas fa-user-slash"></i></a>
                </template>
                <small v-else class="text-muted">{{ u.superadmin ? 'Acceso total' : 'Usted' }}</small>
              </td>
            </tr>
            <tr v-if="!filtrados.length">
              <td colspan="5" class="text-center text-muted py-4">Sin usuarios para mostrar.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card border-warning" v-if="soloSigec.length">
      <div class="card-header bg-light">
        <i class="fas fa-exclamation-triangle text-warning"></i>
        <strong>{{ soloSigec.length }} usuario(s) creados antes solo en SIGEC</strong>
        <span class="small text-muted">— no existen en el portal, por lo que no pueden entrar. Créelos en el portal con el mismo correo y conservarán sus programas y ensayos.</span>
      </div>
      <div class="card-body py-2">
        <span class="badge badge-light mr-1 mb-1" v-for="u in soloSigec" :key="u.id">{{ u.nombre }} · {{ u.correo }}</span>
      </div>
    </div>

    <div class="modal fade" ref="modal" tabindex="-1">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <form @submit.prevent="guardar">
            <div class="modal-header">
              <h5 class="modal-title">{{ editando ? 'Rol de ' + editando.nombre : 'Dar acceso a Experimentos' }}</h5>
              <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
              <div class="form-group" v-if="!editando">
                <label>Persona del portal</label>
                <select class="form-control" :class="{ 'is-invalid': errors.portal_id }" v-model="form.portal_id">
                  <option :value="null">Seleccione...</option>
                  <option v-for="u in sinAcceso" :key="u.portal_id" :value="u.portal_id">{{ u.nombre }} · {{ u.correo }}</option>
                </select>
                <small class="form-text text-muted">¿No aparece? Créela primero en el portal.</small>
              </div>
              <div class="text-danger small mb-2" v-if="editando && errors.portal_id">{{ errors.portal_id[0] }}</div>
              <label>Rol en Experimentos</label>
              <div class="row">
                <div class="col-md-6 mb-2" v-for="r in roles" :key="r.id">
                  <label class="border rounded p-2 d-block h-100 mb-0" :style="form.rol_id === r.id ? { borderColor: r.color + ' !important', background: '#f6fbef' } : {}" style="cursor:pointer">
                    <input type="radio" class="mr-1" :value="r.id" v-model="form.rol_id">
                    <strong :style="{ color: r.color }">{{ r.nombre }}</strong>
                    <div class="small text-muted">{{ r.descripcion }}</div>
                  </label>
                </div>
              </div>
              <div class="text-danger small" v-if="errors.rol_id">{{ errors.rol_id[0] }}</div>
              <div class="form-group mt-3" v-if="rolElegido && rolElegido.rol !== 'muestreador'">
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
              <p class="small text-muted mb-0">El cambio se guarda en el portal y se aplica la próxima vez que la persona entre a Experimentos.</p>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-success" :disabled="guardando || !form.portal_id || !form.rol_id">Guardar</button>
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
    conAcceso: { type: Array, default: () => [] },
    sinAccesoInicial: { type: Array, default: () => [] },
    soloSigecInicial: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
    programas: { type: Array, default: () => [] },
    correoActual: { type: String, default: '' },
  },
  data() {
    return {
      items: this.conAcceso.slice(),
      sinAcceso: this.sinAccesoInicial.slice(),
      soloSigec: this.soloSigecInicial.slice(),
      busqueda: '',
      filtroRol: '',
      editando: null,
      form: { portal_id: null, rol_id: null, programas: [] },
      errors: {},
      guardando: false,
    };
  },
  computed: {
    filtrados() {
      const q = this.busqueda.trim().toLowerCase();
      return this.items.filter((u) => (!q || (u.nombre + ' ' + u.correo).toLowerCase().includes(q))
        && (!this.filtroRol || (this.filtroRol === 'superadmin' ? u.superadmin : u.rol_id === this.filtroRol)));
    },
    resumenRoles() {
      const filas = [{ nombre: 'Superadmin', color: '#3d6614', total: this.items.filter((u) => u.superadmin).length }];
      this.roles.forEach((r) => filas.push({ nombre: r.nombre, color: r.color, total: this.items.filter((u) => u.rol_id === r.id).length }));
      return filas;
    },
    rolElegido() {
      return this.roles.find((r) => r.id === this.form.rol_id);
    },
  },
  methods: {
    color(u) {
      if (u.superadmin) return '#3d6614';
      const r = this.roles.find((x) => x.id === u.rol_id);
      return r ? r.color : '#CED2D5';
    },
    abrirAgregar() {
      this.editando = null;
      this.form = { portal_id: null, rol_id: null, programas: [] };
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    abrirEditar(u) {
      this.editando = u;
      this.form = { portal_id: u.portal_id, rol_id: u.rol_id, programas: u.programas.map((p) => p.id) };
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    actualizar(data) {
      this.items = data.conAcceso;
      this.sinAcceso = data.sinAcceso;
      this.soloSigec = data.soloSigec;
    },
    guardar() {
      this.guardando = true;
      this.errors = {};
      const datos = { ...this.form, programas: this.rolElegido && this.rolElegido.rol === 'muestreador' ? [] : this.form.programas };
      window.axios.post('/admin/usuarios/acceso', datos)
        .then(({ data }) => {
          this.actualizar(data);
          window.$(this.$refs.modal).modal('hide');
          this.$swal.fire({ icon: 'success', title: data.mensaje, timer: 1800, showConfirmButton: false });
        })
        .catch((err) => {
          if (err.response && err.response.status === 422) {
            this.errors = err.response.data.errors || {};
          } else {
            this.$swal.fire({ icon: 'error', title: 'No se pudo guardar el rol' });
          }
        })
        .finally(() => { this.guardando = false; });
    },
    quitar(u) {
      this.$swal.fire({
        title: `¿Quitar a "${u.nombre}" de Experimentos?`,
        text: 'La persona sigue existiendo en el portal; solo pierde el acceso a este módulo.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Quitar acceso',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#FF6B00',
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/admin/usuarios/acceso/${u.portal_id}`).then(({ data }) => {
          this.actualizar(data);
          this.$swal.fire({ icon: 'success', title: data.mensaje, timer: 1500, showConfirmButton: false });
        }).catch((err) => {
          const e = err.response && err.response.data;
          this.$swal.fire({ icon: 'error', title: (e && e.errors && Object.values(e.errors)[0][0]) || 'No se pudo quitar el acceso' });
        });
      });
    },
  },
};
</script>
