<template>
  <div>
    <div class="row">
      <div class="col-md-6">
        <div class="card card-outline card-success">
          <div class="card-header"><h3 class="card-title"><i class="fas fa-file-excel"></i> Exportar a Excel</h3></div>
          <div class="card-body">
            <p class="text-muted">Descarga los datos reales del sistema (respetando lo que tu rol puede ver).</p>
            <a :href="'/soporte/reportes/exportar/evaluaciones' + filtro" class="btn btn-success btn-block mb-2">
              <i class="fas fa-download"></i> Evaluaciones {{ ensayoSeleccionado ? '(' + ensayoSeleccionado.codigo + ')' : '(todas)' }}
            </a>
            <a :href="'/soporte/reportes/exportar/muestras-lab' + filtro" class="btn btn-success btn-block">
              <i class="fas fa-download"></i> Muestras de laboratorio {{ ensayoSeleccionado ? '(' + ensayoSeleccionado.codigo + ')' : '(todas)' }}
            </a>
          </div>
        </div>
      </div>

      <div class="col-md-6">
        <div class="card card-outline card-success">
          <div class="card-header"><h3 class="card-title"><i class="fas fa-file-alt"></i> Resumen de ensayo (PDF)</h3></div>
          <div class="card-body">
            <div class="form-group">
              <label>Ensayo</label>
              <select class="form-control" v-model="ensayoId">
                <option :value="null">Todos (sin filtrar)</option>
                <option v-for="e in ensayos" :key="e.id" :value="e.id">{{ e.codigo }}</option>
              </select>
            </div>
            <a v-if="ensayoId" :href="'/soporte/reportes/resumen/' + ensayoId" target="_blank" class="btn btn-success btn-block">
              <i class="fas fa-print"></i> Ver / imprimir resumen de {{ ensayoSeleccionado.codigo }}
            </a>
            <p class="text-muted small mb-0" v-else>Selecciona un ensayo para ver su resumen imprimible.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'ReportesIndex',
  props: {
    ensayos: { type: Array, default: () => [] },
  },
  data() {
    return { ensayoId: null };
  },
  computed: {
    ensayoSeleccionado() {
      return this.ensayos.find((e) => e.id === this.ensayoId) || null;
    },
    filtro() {
      return this.ensayoId ? `?ensayo_id=${this.ensayoId}` : '';
    },
  },
};
</script>
