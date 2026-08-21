<template>
  <div>
    <div class="card" v-if="!generadas.length">
      <div class="card-header">
        <h3 class="card-title">Generar lote de muestras</h3>
      </div>
      <div class="card-body">
        <div class="row">
          <div class="col-md-3 form-group">
            <label>Proyecto</label>
            <select class="form-control" v-model="cabecera.proyecto_id">
              <option :value="null">Seleccione...</option>
              <option v-for="p in proyectos" :key="p.id" :value="p.id">{{ p.codigo }}</option>
            </select>
          </div>
          <div class="col-md-3 form-group">
            <label>Ensayo</label>
            <select class="form-control" :class="{ 'is-invalid': errors.ensayo_id }" v-model="cabecera.ensayo_id">
              <option :value="null">Seleccione...</option>
              <option v-for="e in ensayosDelProyecto" :key="e.id" :value="e.id">{{ e.codigo }}</option>
            </select>
            <span class="invalid-feedback" v-if="errors.ensayo_id">{{ errors.ensayo_id[0] }}</span>
          </div>
          <div class="col-md-3 form-group">
            <label>Tipo de muestra</label>
            <select class="form-control" v-model="cabecera.tipo">
              <option v-for="t in tiposMuestra" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
          <div class="col-md-3 form-group">
            <label>Fecha</label>
            <input type="date" class="form-control" v-model="cabecera.fecha">
          </div>
        </div>

        <table class="table">
          <thead>
            <tr>
              <th>Tratamiento</th>
              <th>Parcela</th>
              <th>Repeticion</th>
              <th>Observaciones</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(fila, idx) in filas" :key="idx">
              <td>
                <select class="form-control" v-model="fila.tratamiento_id">
                  <option :value="null">-</option>
                  <option v-for="t in tratamientosDelEnsayo" :key="t.id" :value="t.id">{{ t.codigo }}</option>
                </select>
              </td>
              <td>
                <select class="form-control" v-model="fila.parcela_id">
                  <option :value="null">-</option>
                  <option v-for="p in parcelasDelEnsayo" :key="p.id" :value="p.id">{{ p.codigo }}</option>
                </select>
              </td>
              <td><input type="number" class="form-control" v-model="fila.repeticion"></td>
              <td><input type="text" class="form-control" v-model="fila.obs"></td>
              <td><a href="#" class="text-danger" @click.prevent="filas.splice(idx, 1)"><i class="fas fa-trash"></i></a></td>
            </tr>
          </tbody>
        </table>
        <button class="btn btn-secondary btn-sm" @click="filas.push({ tratamiento_id: null, parcela_id: null, repeticion: null, obs: '' })">
          <i class="fas fa-plus"></i> Agregar fila
        </button>

        <div class="mt-3">
          <button class="btn btn-success" :disabled="generando || !filas.length" @click="generar">
            <i class="fas fa-barcode"></i> Generar y guardar ({{ filas.length }})
          </button>
        </div>
      </div>
    </div>

    <div class="card" v-else>
      <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title">Muestras generadas</h3>
        <div>
          <button class="btn btn-secondary btn-sm mr-2" @click="imprimir"><i class="fas fa-print"></i> Imprimir</button>
          <button class="btn btn-success btn-sm" @click="nuevoLote"><i class="fas fa-plus"></i> Nuevo lote</button>
        </div>
      </div>
      <div class="card-body">
        <div class="row">
          <div class="col-md-3 mb-3" v-for="m in generadas" :key="m.id">
            <div class="card card-outline card-success text-center p-2">
              <img :src="qrCodes[m.id_muestra]" class="mx-auto" style="width:120px;height:120px" v-if="qrCodes[m.id_muestra]">
              <b class="mt-2">{{ m.id_muestra }}</b>
              <small class="text-muted">{{ m.tipo }}</small>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import QRCode from 'qrcode';

export default {
  name: 'MuestrasGenIndex',
  props: {
    proyectos: { type: Array, default: () => [] },
    ensayos: { type: Array, default: () => [] },
    tratamientos: { type: Array, default: () => [] },
    parcelas: { type: Array, default: () => [] },
    tiposMuestra: { type: Array, default: () => [] },
  },
  data() {
    return {
      cabecera: { proyecto_id: null, ensayo_id: null, tipo: this.tiposMuestra[0] || 'Suelo', fecha: new Date().toISOString().substr(0, 10) },
      filas: [{ tratamiento_id: null, parcela_id: null, repeticion: null, obs: '' }],
      errors: {},
      generando: false,
      generadas: [],
      qrCodes: {},
    };
  },
  computed: {
    ensayosDelProyecto() {
      if (!this.cabecera.proyecto_id) return this.ensayos;
      return this.ensayos.filter((e) => e.proyecto_id === this.cabecera.proyecto_id);
    },
    tratamientosDelEnsayo() {
      if (!this.cabecera.ensayo_id) return [];
      return this.tratamientos.filter((t) => t.ensayo_id === this.cabecera.ensayo_id);
    },
    parcelasDelEnsayo() {
      if (!this.cabecera.ensayo_id) return [];
      return this.parcelas.filter((p) => p.ensayo_id === this.cabecera.ensayo_id);
    },
  },
  methods: {
    generar() {
      this.generando = true;
      this.errors = {};
      window.axios.post('/soporte/muestras/generar', { ...this.cabecera, filas: this.filas })
        .then(async ({ data }) => {
          this.generadas = data;
          for (const m of data) {
            this.qrCodes[m.id_muestra] = await QRCode.toDataURL(m.id_muestra, { width: 150 });
          }
          this.$forceUpdate();
          this.$swal.fire({ icon: 'success', title: `${data.length} muestra(s) generada(s)`, timer: 1500, showConfirmButton: false });
        })
        .catch((err) => {
          if (err.response && err.response.status === 422) {
            this.errors = err.response.data.errors;
          } else {
            this.$swal.fire({ icon: 'error', title: 'Ocurrio un error' });
          }
        })
        .finally(() => { this.generando = false; });
    },
    imprimir() {
      window.print();
    },
    nuevoLote() {
      this.generadas = [];
      this.qrCodes = {};
      this.filas = [{ tratamiento_id: null, parcela_id: null, repeticion: null, obs: '' }];
    },
  },
};
</script>
