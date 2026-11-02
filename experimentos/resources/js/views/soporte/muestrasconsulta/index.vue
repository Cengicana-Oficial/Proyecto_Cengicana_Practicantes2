<template>
  <div>
    <div class="card">
      <div class="card-header">
        <input type="text" class="form-control" placeholder="Buscar por ID de muestra, ensayo o proyecto..." v-model="busqueda">
      </div>
    </div>

    <div class="row">
      <div class="col-md-4" v-for="m in muestrasFiltradas" :key="m.id">
        <div class="card">
          <div class="card-body">
            <div class="d-flex justify-content-between">
              <b>{{ m.id_muestra }}</b>
              <a href="#" v-if="puedeActualizar" @click.prevent="abrirActualizar(m)"><i class="fas fa-pen"></i></a>
            </div>
            <p class="text-muted small mb-2">{{ m.tipo }} · {{ m.ensayo ? m.ensayo.codigo : '-' }} · {{ m.proyecto ? m.proyecto.codigo : '-' }}</p>

            <div class="ciclo-tracker d-flex justify-content-between mb-2">
              <div v-for="(paso, idx) in cicloSteps" :key="paso" class="text-center flex-fill">
                <div class="rounded-circle mx-auto d-flex align-items-center justify-content-center"
                     :style="pasoStyle(m, idx)" style="width:28px;height:28px;">
                  <i class="fas fa-check" v-if="pasoCompletado(m, idx)" style="font-size:11px"></i>
                </div>
                <small class="d-block mt-1">{{ paso }}</small>
              </div>
            </div>

            <p class="mb-1" v-if="m.analistas"><b>Analistas:</b> {{ m.analistas }}</p>
            <p class="mb-1" v-if="m.resultado_texto"><b>Resultado:</b> {{ m.resultado_texto }}</p>
            <p class="mb-0" v-if="m.fecha_resultado"><b>Fecha resultado:</b> {{ m.fecha_resultado }}</p>
          </div>
        </div>
      </div>
      <div class="col-12" v-if="!muestrasFiltradas.length">
        <div class="text-center text-muted py-5">Sin muestras para mostrar.</div>
      </div>
    </div>

    <div class="modal fade" ref="modal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <form @submit.prevent="guardar">
            <div class="modal-header">
              <h5 class="modal-title">Actualizar estado — {{ editando ? editando.id_muestra : '' }}</h5>
              <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
              <div class="form-group">
                <label>Estado</label>
                <select class="form-control" v-model="form.estado">
                  <option value="Recibida">Recibida</option>
                  <option value="Pendiente">Pendiente</option>
                  <option value="En proceso">En proceso</option>
                  <option value="Completado">Completado</option>
                </select>
              </div>
              <div class="form-group">
                <label>Analistas</label>
                <input type="text" class="form-control" v-model="form.analistas">
              </div>
              <div class="form-group">
                <label>Resultado</label>
                <textarea class="form-control" v-model="form.resultado_texto" rows="3"></textarea>
              </div>
              <div class="form-group">
                <label>Fecha resultado</label>
                <input type="date" class="form-control" v-model="form.fecha_resultado">
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
  name: 'MuestrasConsultaIndex',
  props: {
    muestras: { type: Array, default: () => [] },
    cicloSteps: { type: Array, default: () => [] },
    puedeActualizar: { type: Boolean, default: false },
  },
  data() {
    return {
      items: this.muestras.slice(),
      busqueda: '',
      editando: null,
      form: { estado: 'Recibida', analistas: '', resultado_texto: '', fecha_resultado: '' },
      guardando: false,
    };
  },
  computed: {
    muestrasFiltradas() {
      const q = this.busqueda.trim().toLowerCase();
      if (!q) return this.items;
      return this.items.filter((m) => (
        m.id_muestra + ' ' + (m.ensayo ? m.ensayo.codigo : '') + ' ' + (m.proyecto ? m.proyecto.codigo : '')
      ).toLowerCase().includes(q));
    },
  },
  methods: {
    pasoIndice(estado) {
      // Recibida/Pendiente -> paso 0, En proceso -> paso 1, Completado -> paso 2
      if (estado === 'Completado') return 2;
      if (estado === 'En proceso') return 1;
      return 0;
    },
    pasoCompletado(m, idx) {
      return this.pasoIndice(m.estado) >= idx;
    },
    pasoStyle(m, idx) {
      const activo = this.pasoCompletado(m, idx);
      return {
        backgroundColor: activo ? '#73BC25' : '#E3E7E1',
        color: activo ? '#fff' : '#6B7568',
      };
    },
    abrirActualizar(m) {
      this.editando = m;
      this.form = {
        estado: m.estado, analistas: m.analistas || '', resultado_texto: m.resultado_texto || '', fecha_resultado: m.fecha_resultado || '',
      };
      window.$(this.$refs.modal).modal('show');
    },
    guardar() {
      this.guardando = true;
      window.axios.put(`/soporte/muestras/consulta/${this.editando.id}`, this.form)
        .then(({ data }) => {
          const idx = this.items.findIndex((i) => i.id === data.id);
          this.$set(this.items, idx, data);
          window.$(this.$refs.modal).modal('hide');
          this.$swal.fire({ icon: 'success', title: 'Actualizado', timer: 1200, showConfirmButton: false });
        })
        .catch(() => {
          this.$swal.fire({ icon: 'error', title: 'Ocurrio un error' });
        })
        .finally(() => { this.guardando = false; });
    },
  },
};
</script>
