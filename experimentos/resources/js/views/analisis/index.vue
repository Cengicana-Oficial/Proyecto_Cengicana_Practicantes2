<template>
  <div>
    <div class="card card-outline card-success">
      <div class="card-header"><h3 class="card-title"><i class="fas fa-file-arrow-up"></i> Importar evaluaciones desde Excel/CSV</h3></div>
      <div class="card-body">
        <p class="text-muted">
          El archivo debe tener columnas <b>Fecha</b>, <b>Variable</b>, <b>Parcela</b>, <b>Valor</b> y
          <b>Observaciones</b> (opcional). "Variable" se empareja por nombre exacto y "Parcela" por su
          código dentro del ensayo elegido; las filas que no coincidan se reportan sin detener el resto.
        </p>
        <form @submit.prevent="importar">
          <div class="row">
            <div class="col-md-4 form-group">
              <label>Ensayo</label>
              <select class="form-control" :class="{ 'is-invalid': errors.ensayo_id }" v-model="form.ensayo_id">
                <option :value="null">Seleccione...</option>
                <option v-for="e in ensayos" :key="e.id" :value="e.id">{{ e.codigo }}</option>
              </select>
              <span class="invalid-feedback" v-if="errors.ensayo_id">{{ errors.ensayo_id[0] }}</span>
            </div>
            <div class="col-md-5 form-group">
              <label>Archivo (xlsx, xls, csv)</label>
              <input type="file" class="form-control-file" :class="{ 'is-invalid': errors.archivo }" @change="onFile" ref="fileInput">
              <span class="invalid-feedback d-block" v-if="errors.archivo">{{ errors.archivo[0] }}</span>
            </div>
            <div class="col-md-3 form-group d-flex align-items-end">
              <button type="submit" class="btn btn-success" :disabled="importando || !form.ensayo_id || !form.archivo">
                <i class="fas fa-file-arrow-up"></i> Importar
              </button>
            </div>
          </div>
        </form>

        <div v-if="resultado" class="mt-3">
          <div class="alert alert-success">
            {{ resultado.creadas }} de {{ resultado.total }} fila(s) importadas como evaluaciones.
          </div>
          <table class="table table-sm" v-if="resultado.omitidas.length">
            <thead><tr><th>Fila</th><th>Motivo</th></tr></thead>
            <tbody>
              <tr v-for="o in resultado.omitidas" :key="o.fila">
                <td>{{ o.fila }}</td>
                <td>{{ o.motivo }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="card card-outline card-secondary">
      <div class="card-header"><h3 class="card-title text-muted"><i class="fas fa-flask"></i> Análisis estadístico (ANOVA / Tukey)</h3></div>
      <div class="card-body">
        <div class="alert alert-warning mb-0">
          <i class="fas fa-triangle-exclamation"></i>
          No implementado todavía. El prototipo original simulaba esta funcion asumiendo un motor
          estadistico externo (Python/pandas/scipy o R/agricolae) que este proyecto aun no integra.
          Requiere decidir como ejecutar ese motor desde Laravel antes de construirlo.
        </div>
      </div>
    </div>

    <div class="card card-outline card-secondary">
      <div class="card-header"><h3 class="card-title text-muted"><i class="fas fa-robot"></i> Generar reporte narrativo con IA</h3></div>
      <div class="card-body">
        <div class="alert alert-warning mb-0">
          <i class="fas fa-triangle-exclamation"></i>
          No implementado todavia. Requiere configurar acceso a la API de Claude (Anthropic) en el
          proyecto antes de poder generar reportes narrativos automaticos.
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'AnalisisIndex',
  props: {
    ensayos: { type: Array, default: () => [] },
  },
  data() {
    return {
      form: { ensayo_id: null, archivo: null },
      errors: {},
      importando: false,
      resultado: null,
    };
  },
  methods: {
    onFile(e) {
      this.form.archivo = e.target.files[0] || null;
    },
    importar() {
      this.importando = true;
      this.errors = {};
      this.resultado = null;
      const fd = new FormData();
      fd.append('ensayo_id', this.form.ensayo_id);
      fd.append('archivo', this.form.archivo);

      window.axios.post('/analisis/importar', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
        .then(({ data }) => {
          this.resultado = data;
          this.form.archivo = null;
          if (this.$refs.fileInput) this.$refs.fileInput.value = '';
          this.$swal.fire({ icon: 'success', title: `${data.creadas} evaluacion(es) importada(s)`, timer: 1800, showConfirmButton: false });
        })
        .catch((err) => {
          if (err.response && err.response.status === 422) {
            this.errors = err.response.data.errors;
          } else {
            this.$swal.fire({ icon: 'error', title: 'Ocurrio un error' });
          }
        })
        .finally(() => { this.importando = false; });
    },
  },
};
</script>
