<template>
  <div>
    <!-- Modo muestreador: solo lectura de asignaciones propias -->
    <div v-if="modo === 'muestreador'">
      <div class="card" v-for="a in asignaciones" :key="a.id">
        <div class="card-body">
          <h5>{{ a.formulario ? a.formulario.nombre : '-' }}</h5>
          <p class="text-muted small mb-1">Ensayo: {{ a.ensayo ? a.ensayo.codigo : '-' }}</p>
          <p class="mb-1"><b>Parcelas:</b> {{ (a.parcelas || []).join(', ') || 'Todas' }}</p>
          <p class="mb-1"><b>Periodo:</b> {{ a.fecha_inicio || '-' }} a {{ a.fecha_fin || '-' }}</p>
          <span :class="'badge ' + (a.estado === 'Activo' ? 'badge-success' : 'badge-secondary')">{{ a.estado }}</span>
        </div>
      </div>
      <div class="text-center text-muted py-5" v-if="!asignaciones.length">
        No tienes formularios asignados por el momento.
      </div>
    </div>

    <!-- Modo builder: admin/encargado/experto -->
    <div v-else>
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title">Formularios de campo</h3>
          <button class="btn btn-success" @click="abrirCrear"><i class="fas fa-plus"></i> Nuevo formulario</button>
        </div>
        <div class="card-body p-0">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>Nombre</th>
                <th>Proyecto</th>
                <th>Ensayo</th>
                <th>Campos</th>
                <th>Asignaciones</th>
                <th>Estado</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in items" :key="f.id">
                <td>{{ f.nombre }}</td>
                <td>{{ f.proyecto ? f.proyecto.codigo : '-' }}</td>
                <td>{{ f.ensayo ? f.ensayo.codigo : '-' }}</td>
                <td>{{ (f.campos || []).length }}</td>
                <td>{{ (f.asignaciones || []).length }}</td>
                <td><span :class="'badge ' + (f.estado === 'Activo' ? 'badge-success' : 'badge-secondary')">{{ f.estado }}</span></td>
                <td class="text-right">
                  <a href="#" class="text-muted mr-2" @click.prevent="abrirAsignar(f)"><i class="fas fa-user-plus"></i></a>
                  <a href="#" class="text-muted mr-2" @click.prevent="abrirEditar(f)"><i class="fas fa-pen"></i></a>
                  <a href="#" class="text-danger" @click.prevent="eliminar(f)"><i class="fas fa-trash"></i></a>
                </td>
              </tr>
              <tr v-if="!items.length">
                <td colspan="7" class="text-center text-muted py-4">Sin formularios creados.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Modal crear/editar formulario -->
      <div class="modal fade" ref="modalForm" tabindex="-1">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <form @submit.prevent="guardar">
              <div class="modal-header">
                <h5 class="modal-title">{{ editando ? 'Editar formulario' : 'Nuevo formulario' }}</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
              </div>
              <div class="modal-body">
                <div class="row">
                  <div class="col-md-6 form-group">
                    <label>Nombre</label>
                    <input type="text" class="form-control" :class="{ 'is-invalid': errors.nombre }" v-model="form.nombre">
                    <span class="invalid-feedback" v-if="errors.nombre">{{ errors.nombre[0] }}</span>
                  </div>
                  <div class="col-md-3 form-group">
                    <label>Proyecto</label>
                    <select class="form-control" v-model="form.proyecto_id">
                      <option :value="null">Seleccione...</option>
                      <option v-for="p in proyectos" :key="p.id" :value="p.id">{{ p.codigo }}</option>
                    </select>
                  </div>
                  <div class="col-md-3 form-group">
                    <label>Ensayo</label>
                    <select class="form-control" :class="{ 'is-invalid': errors.ensayo_id }" v-model="form.ensayo_id">
                      <option :value="null">Seleccione...</option>
                      <option v-for="e in ensayosDelProyecto" :key="e.id" :value="e.id">{{ e.codigo }}</option>
                    </select>
                    <span class="invalid-feedback" v-if="errors.ensayo_id">{{ errors.ensayo_id[0] }}</span>
                  </div>
                </div>

                <hr>
                <h6>Campos del formulario</h6>
                <div class="row align-items-end mb-2" v-for="(campo, idx) in form.campos" :key="idx">
                  <div class="col-md-4">
                    <label v-if="idx === 0">Etiqueta</label>
                    <input type="text" class="form-control" v-model="campo.label" placeholder="Etiqueta del campo">
                  </div>
                  <div class="col-md-2">
                    <label v-if="idx === 0">Tipo</label>
                    <select class="form-control" v-model="campo.tipo">
                      <option value="numero">Numero</option>
                      <option value="texto">Texto</option>
                      <option value="select">Select</option>
                      <option value="foto">Foto</option>
                    </select>
                  </div>
                  <div class="col-md-3" v-if="campo.tipo === 'select'">
                    <label v-if="idx === 0">Opciones (coma)</label>
                    <input type="text" class="form-control" v-model="campo.opcionesTexto" placeholder="Bueno,Regular,Malo">
                  </div>
                  <div class="col-md-2" v-else></div>
                  <div class="col-md-1">
                    <div class="form-check">
                      <input type="checkbox" class="form-check-input" v-model="campo.requerido">
                      <label class="form-check-label small">Req.</label>
                    </div>
                  </div>
                  <div class="col-md-1 text-right">
                    <a href="#" class="text-danger" @click.prevent="form.campos.splice(idx, 1)"><i class="fas fa-trash"></i></a>
                  </div>
                </div>
                <span class="invalid-feedback d-block" v-if="errors.campos">{{ errors.campos[0] }}</span>
                <button type="button" class="btn btn-secondary btn-sm" @click="agregarCampo">
                  <i class="fas fa-plus"></i> Agregar campo
                </button>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success" :disabled="guardando">Guardar</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Modal asignar muestreador -->
      <div class="modal fade" ref="modalAsignar" tabindex="-1">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Asignaciones — {{ formularioActivo ? formularioActivo.nombre : '' }}</h5>
              <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
              <ul class="list-group mb-3">
                <li class="list-group-item d-flex justify-content-between align-items-center" v-for="a in (formularioActivo ? formularioActivo.asignaciones : [])" :key="a.id">
                  {{ a.usuario ? a.usuario.name : '-' }}
                  <a href="#" class="text-danger" @click.prevent="quitarAsignacion(a)"><i class="fas fa-trash"></i></a>
                </li>
                <li class="list-group-item text-muted" v-if="!formularioActivo || !formularioActivo.asignaciones.length">Sin asignaciones aun.</li>
              </ul>
              <form @submit.prevent="asignar">
                <div class="form-group">
                  <label>Muestreador</label>
                  <select class="form-control" v-model="asignForm.usuario_id">
                    <option :value="null">Seleccione...</option>
                    <option v-for="u in muestreadores" :key="u.id" :value="u.id">{{ u.name }}</option>
                  </select>
                </div>
                <div class="row">
                  <div class="col-md-6 form-group">
                    <label>Fecha inicio</label>
                    <input type="date" class="form-control" v-model="asignForm.fecha_inicio">
                  </div>
                  <div class="col-md-6 form-group">
                    <label>Fecha fin</label>
                    <input type="date" class="form-control" v-model="asignForm.fecha_fin">
                  </div>
                </div>
                <button type="submit" class="btn btn-success" :disabled="asignando">
                  <i class="fas fa-plus"></i> Asignar
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'FormulariosIndex',
  props: {
    modo: { type: String, default: 'builder' },
    formularios: { type: Array, default: () => [] },
    asignaciones: { type: Array, default: () => [] },
    proyectos: { type: Array, default: () => [] },
    ensayos: { type: Array, default: () => [] },
    parcelas: { type: Array, default: () => [] },
    muestreadores: { type: Array, default: () => [] },
  },
  data() {
    return {
      items: this.formularios.slice(),
      editando: null,
      form: this.formVacio(),
      errors: {},
      guardando: false,
      formularioActivo: null,
      asignForm: { usuario_id: null, fecha_inicio: '', fecha_fin: '' },
      asignando: false,
    };
  },
  computed: {
    ensayosDelProyecto() {
      if (!this.form.proyecto_id) return this.ensayos;
      return this.ensayos.filter((e) => e.proyecto_id === this.form.proyecto_id);
    },
  },
  methods: {
    formVacio() {
      return { nombre: '', proyecto_id: null, ensayo_id: null, campos: [] };
    },
    campoVacio() {
      return { label: '', tipo: 'numero', requerido: false, opcionesTexto: '' };
    },
    agregarCampo() {
      this.form.campos.push(this.campoVacio());
    },
    abrirCrear() {
      this.editando = null;
      this.form = this.formVacio();
      this.agregarCampo();
      this.errors = {};
      window.$(this.$refs.modalForm).modal('show');
    },
    abrirEditar(f) {
      this.editando = f;
      this.form = {
        nombre: f.nombre,
        proyecto_id: f.proyecto_id,
        ensayo_id: f.ensayo_id,
        campos: (f.campos || []).map((c) => ({ ...c, opcionesTexto: (c.opciones || []).join(',') })),
      };
      this.errors = {};
      window.$(this.$refs.modalForm).modal('show');
    },
    guardar() {
      this.guardando = true;
      this.errors = {};
      const payload = {
        ...this.form,
        campos: this.form.campos.map((c) => ({
          label: c.label,
          tipo: c.tipo,
          requerido: !!c.requerido,
          opciones: c.tipo === 'select' ? (c.opcionesTexto || '').split(',').map((o) => o.trim()).filter(Boolean) : [],
        })),
      };
      const peticion = this.editando
        ? window.axios.put(`/soporte/formularios/${this.editando.id}`, payload)
        : window.axios.post('/soporte/formularios', payload);

      peticion
        .then(({ data }) => {
          if (this.editando) {
            const idx = this.items.findIndex((i) => i.id === data.id);
            this.$set(this.items, idx, data);
          } else {
            this.items.unshift(data);
          }
          window.$(this.$refs.modalForm).modal('hide');
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
    eliminar(f) {
      this.$swal.fire({
        title: `Eliminar "${f.nombre}"?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#c4471b',
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/soporte/formularios/${f.id}`).then(() => {
          this.items = this.items.filter((i) => i.id !== f.id);
        });
      });
    },
    abrirAsignar(f) {
      this.formularioActivo = f;
      this.asignForm = { usuario_id: null, fecha_inicio: '', fecha_fin: '' };
      window.$(this.$refs.modalAsignar).modal('show');
    },
    asignar() {
      this.asignando = true;
      window.axios.post(`/soporte/formularios/${this.formularioActivo.id}/asignaciones`, this.asignForm)
        .then(({ data }) => {
          this.formularioActivo.asignaciones.push(data);
          this.asignForm = { usuario_id: null, fecha_inicio: '', fecha_fin: '' };
        })
        .catch(() => {
          this.$swal.fire({ icon: 'error', title: 'Ocurrio un error' });
        })
        .finally(() => { this.asignando = false; });
    },
    quitarAsignacion(a) {
      window.axios.delete(`/soporte/formularios/asignaciones/${a.id}`).then(() => {
        this.formularioActivo.asignaciones = this.formularioActivo.asignaciones.filter((i) => i.id !== a.id);
      });
    },
  },
};
</script>
