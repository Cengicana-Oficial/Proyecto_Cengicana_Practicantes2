<template>
  <div>
    <div class="row">
      <div class="col-lg-3 col-6" v-for="kpi in kpiCards" :key="kpi.key">
        <div class="small-box bg-sigec">
          <div class="inner">
            <h3>{{ kpi.value }}</h3>
            <p>{{ kpi.label }}</p>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="row">
          <div class="col-md-4">
            <input type="text" class="form-control" placeholder="Buscar por ID de muestra..." v-model="busqueda">
          </div>
          <div class="col-md-3">
            <select class="form-control" v-model="filtroTipo">
              <option value="">Todos los tipos</option>
              <option v-for="t in tiposMuestra" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
          <div class="col-md-2">
            <select class="form-control" v-model="filtroEstado">
              <option value="">Todos los estados</option>
              <option value="Recibida">Recibida</option>
              <option value="Pendiente">Pendiente</option>
              <option value="En proceso">En proceso</option>
              <option value="Completado">Completado</option>
            </select>
          </div>
          <div class="col-md-3 text-right">
            <button v-if="puedeCrear" class="btn btn-success" @click="abrirCrear">
              <i class="fas fa-plus"></i> Nueva muestra
            </button>
          </div>
        </div>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>ID Muestra</th>
              <th>Tipo</th>
              <th>Fecha</th>
              <th>Ensayo</th>
              <th>Tratamiento</th>
              <th>Parcela</th>
              <th>Estado</th>
              <th>Solicitante</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in muestrasFiltradas" :key="m.id">
              <td><b>{{ m.id_muestra }}</b></td>
              <td>{{ m.tipo }}</td>
              <td>{{ m.fecha }}</td>
              <td>{{ m.ensayo ? m.ensayo.codigo : '-' }}</td>
              <td>{{ m.tratamiento ? m.tratamiento.codigo : '-' }}</td>
              <td>{{ m.parcela ? m.parcela.codigo : '-' }}</td>
              <td><span :class="'badge ' + badgeEstado(m.estado)">{{ m.estado }}</span></td>
              <td>{{ m.solicitante ? m.solicitante.name : '-' }}</td>
              <td class="text-right">
                <a href="#" class="text-muted mr-2" v-if="puedeCrear" @click.prevent="abrirEditar(m)"><i class="fas fa-pen"></i></a>
                <a href="#" class="text-danger" v-if="puedeEliminar" @click.prevent="eliminar(m)"><i class="fas fa-trash"></i></a>
              </td>
            </tr>
            <tr v-if="!muestrasFiltradas.length">
              <td colspan="9" class="text-center text-muted py-4">Sin muestras para mostrar.</td>
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
              <h5 class="modal-title">{{ editando ? ('Editar muestra ' + editando.id_muestra) : 'Nueva muestra' }}</h5>
              <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
              <div class="row">
                <div class="col-md-4 form-group">
                  <label>Proyecto</label>
                  <select class="form-control" v-model="form.proyecto_id">
                    <option :value="null">Seleccione...</option>
                    <option v-for="p in proyectos" :key="p.id" :value="p.id">{{ p.codigo }}</option>
                  </select>
                </div>
                <div class="col-md-4 form-group">
                  <label>Ensayo</label>
                  <select class="form-control" :class="{ 'is-invalid': errors.ensayo_id }" v-model="form.ensayo_id">
                    <option :value="null">Seleccione...</option>
                    <option v-for="e in ensayosDelProyecto" :key="e.id" :value="e.id">{{ e.codigo }}</option>
                  </select>
                  <span class="invalid-feedback" v-if="errors.ensayo_id">{{ errors.ensayo_id[0] }}</span>
                </div>
                <div class="col-md-4 form-group">
                  <label>Tipo de muestra</label>
                  <select class="form-control" v-model="form.tipo" :disabled="!!editando">
                    <option v-for="t in tiposMuestra" :key="t" :value="t">{{ t }}</option>
                  </select>
                </div>
              </div>
              <div class="row">
                <div class="col-md-4 form-group">
                  <label>Fecha</label>
                  <input type="date" class="form-control" v-model="form.fecha">
                </div>
                <div class="col-md-4 form-group">
                  <label>Tratamiento</label>
                  <select class="form-control" v-model="form.tratamiento_id">
                    <option :value="null">Sin asignar</option>
                    <option v-for="t in tratamientosDelEnsayo" :key="t.id" :value="t.id">{{ t.codigo }}</option>
                  </select>
                </div>
                <div class="col-md-4 form-group">
                  <label>Parcela</label>
                  <select class="form-control" v-model="form.parcela_id">
                    <option :value="null">Sin asignar</option>
                    <option v-for="p in parcelasDelEnsayo" :key="p.id" :value="p.id">{{ p.codigo }}</option>
                  </select>
                </div>
              </div>
              <div class="row" v-if="editando">
                <div class="col-md-4 form-group">
                  <label>Estado</label>
                  <select class="form-control" v-model="form.estado">
                    <option value="Recibida">Recibida</option>
                    <option value="Pendiente">Pendiente</option>
                    <option value="En proceso">En proceso</option>
                    <option value="Completado">Completado</option>
                  </select>
                </div>
                <div class="col-md-4 form-group">
                  <label>Analistas</label>
                  <input type="text" class="form-control" v-model="form.analistas">
                </div>
                <div class="col-md-4 form-group">
                  <label>Fecha resultado</label>
                  <input type="date" class="form-control" v-model="form.fecha_resultado">
                </div>
              </div>

              <hr>
              <h6>Analitos ({{ form.tipo }})</h6>
              <div class="row">
                <div class="col-md-3 form-group" v-for="a in analitosDelTipo" :key="a.clave">
                  <label>{{ a.label }} <small class="text-muted" v-if="a.unidad">({{ a.unidad }})</small></label>
                  <input type="text" class="form-control" v-model="form.analitos[a.clave]">
                </div>
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
  name: 'LaboratorioIndex',
  props: {
    muestras: { type: Array, default: () => [] },
    analitosPorTipo: { type: Object, default: () => ({}) },
    tiposMuestra: { type: Array, default: () => [] },
    proyectos: { type: Array, default: () => [] },
    ensayos: { type: Array, default: () => [] },
    tratamientos: { type: Array, default: () => [] },
    parcelas: { type: Array, default: () => [] },
    kpis: { type: Object, default: () => ({}) },
    puedeCrear: { type: Boolean, default: false },
    puedeEliminar: { type: Boolean, default: false },
  },
  data() {
    return {
      items: this.muestras.slice(),
      busqueda: '',
      filtroTipo: '',
      filtroEstado: '',
      editando: null,
      form: this.formVacio(),
      errors: {},
      guardando: false,
    };
  },
  computed: {
    kpiCards() {
      return [
        { key: 'total', label: 'Total', value: this.kpis.total ?? 0 },
        { key: 'completadas', label: 'Completadas', value: this.kpis.completadas ?? 0 },
        { key: 'en_proceso', label: 'En proceso', value: this.kpis.en_proceso ?? 0 },
        { key: 'pendientes', label: 'Pendientes', value: this.kpis.pendientes ?? 0 },
      ];
    },
    muestrasFiltradas() {
      const q = this.busqueda.trim().toLowerCase();
      return this.items.filter((m) => {
        const matchQ = !q || m.id_muestra.toLowerCase().includes(q);
        const matchTipo = !this.filtroTipo || m.tipo === this.filtroTipo;
        const matchEstado = !this.filtroEstado || m.estado === this.filtroEstado;
        return matchQ && matchTipo && matchEstado;
      });
    },
    ensayosDelProyecto() {
      if (!this.form.proyecto_id) return this.ensayos;
      return this.ensayos.filter((e) => e.proyecto_id === this.form.proyecto_id);
    },
    tratamientosDelEnsayo() {
      if (!this.form.ensayo_id) return [];
      return this.tratamientos.filter((t) => t.ensayo_id === this.form.ensayo_id);
    },
    parcelasDelEnsayo() {
      if (!this.form.ensayo_id) return [];
      return this.parcelas.filter((p) => p.ensayo_id === this.form.ensayo_id);
    },
    analitosDelTipo() {
      return this.analitosPorTipo[this.form.tipo] || [];
    },
  },
  methods: {
    badgeEstado(estado) {
      return {
        Recibida: 'badge-info', Pendiente: 'badge-warning', 'En proceso': 'badge-primary', Completado: 'badge-success',
      }[estado] || 'badge-light';
    },
    formVacio() {
      return {
        proyecto_id: null, ensayo_id: null, tipo: this.tiposMuestra[0] || 'Suelo', fecha: new Date().toISOString().substr(0, 10),
        tratamiento_id: null, parcela_id: null, estado: 'Pendiente', analistas: '', fecha_resultado: '', analitos: {}, obs: '',
      };
    },
    abrirCrear() {
      this.editando = null;
      this.form = this.formVacio();
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    abrirEditar(m) {
      this.editando = m;
      this.form = {
        proyecto_id: m.proyecto_id, ensayo_id: m.ensayo_id, tipo: m.tipo, fecha: m.fecha,
        tratamiento_id: m.tratamiento_id, parcela_id: m.parcela_id, estado: m.estado,
        analistas: m.analistas, fecha_resultado: m.fecha_resultado, analitos: { ...(m.analitos || {}) }, obs: m.obs,
      };
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    guardar() {
      this.guardando = true;
      this.errors = {};
      const peticion = this.editando
        ? window.axios.put(`/soporte/laboratorio/${this.editando.id}`, this.form)
        : window.axios.post('/soporte/laboratorio', this.form);

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
    eliminar(m) {
      this.$swal.fire({
        title: `Eliminar "${m.id_muestra}"?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#c4471b',
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/soporte/laboratorio/${m.id}`).then(() => {
          this.items = this.items.filter((i) => i.id !== m.id);
          this.$swal.fire({ icon: 'success', title: 'Eliminado', timer: 1200, showConfirmButton: false });
        });
      });
    },
  },
};
</script>
