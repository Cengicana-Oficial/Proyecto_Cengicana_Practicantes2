<template>
  <div>
    <ul class="nav nav-tabs mb-3">
      <li class="nav-item">
        <a class="nav-link" :class="{ active: tab === 'variable' }" href="#" @click.prevent="tab = 'variable'">Variables de campo</a>
      </li>
      <li class="nav-item">
        <a class="nav-link" :class="{ active: tab === 'analito' }" href="#" @click.prevent="tab = 'analito'">Analitos de laboratorio</a>
      </li>
    </ul>

    <div class="card" v-if="tab === 'variable'">
      <div class="card-header">
        <div class="row">
          <div class="col-md-5 form-group mb-0">
            <label>Ensayo</label>
            <select class="form-control" v-model="varForm.ensayo_id" @change="cargarVariable">
              <option :value="null">Seleccione...</option>
              <option v-for="e in ensayos" :key="e.id" :value="e.id">{{ e.codigo }}</option>
            </select>
          </div>
          <div class="col-md-5 form-group mb-0">
            <label>Variable</label>
            <select class="form-control" v-model="varForm.variable_id" @change="cargarVariable">
              <option :value="null">Seleccione...</option>
              <option v-for="v in variables" :key="v.id" :value="v.id">{{ v.nombre }}</option>
            </select>
          </div>
        </div>
      </div>
      <div class="card-body">
        <highcharts v-if="varSeries.length" :options="varOptions"></highcharts>
        <div class="text-center text-muted py-5" v-else>Selecciona un ensayo y una variable.</div>
      </div>
    </div>

    <div class="card" v-if="tab === 'analito'">
      <div class="card-header">
        <div class="row">
          <div class="col-md-4 form-group mb-0">
            <label>Ensayo</label>
            <select class="form-control" v-model="anaForm.ensayo_id" @change="cargarAnalito">
              <option :value="null">Seleccione...</option>
              <option v-for="e in ensayos" :key="e.id" :value="e.id">{{ e.codigo }}</option>
            </select>
          </div>
          <div class="col-md-4 form-group mb-0">
            <label>Tipo de muestra</label>
            <select class="form-control" v-model="anaForm.tipo" @change="anaForm.analito = null; cargarAnalito()">
              <option :value="null">Seleccione...</option>
              <option v-for="t in tiposMuestra" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
          <div class="col-md-4 form-group mb-0">
            <label>Analito</label>
            <select class="form-control" v-model="anaForm.analito" @change="cargarAnalito">
              <option :value="null">Seleccione...</option>
              <option v-for="a in analitosDelTipo" :key="a.clave" :value="a.clave">{{ a.label }}</option>
            </select>
          </div>
        </div>
      </div>
      <div class="card-body">
        <highcharts v-if="anaSeries.length" :options="anaOptions"></highcharts>
        <div class="text-center text-muted py-5" v-else>Selecciona ensayo, tipo y analito.</div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'GraficasIndex',
  props: {
    ensayos: { type: Array, default: () => [] },
    variables: { type: Array, default: () => [] },
    analitosPorTipo: { type: Object, default: () => ({}) },
    tiposMuestra: { type: Array, default: () => [] },
  },
  data() {
    return {
      tab: 'variable',
      varForm: { ensayo_id: null, variable_id: null },
      anaForm: { ensayo_id: null, tipo: null, analito: null },
      varSeries: [],
      anaSeries: [],
    };
  },
  computed: {
    analitosDelTipo() {
      return this.analitosPorTipo[this.anaForm.tipo] || [];
    },
    varOptions() {
      const variable = this.variables.find((v) => v.id === this.varForm.variable_id);
      return {
        chart: { type: 'line', height: 350 },
        title: { text: null },
        credits: { enabled: false },
        xAxis: { type: 'datetime' },
        yAxis: { title: { text: variable ? `${variable.nombre} (${variable.unidad || ''})` : '' } },
        series: this.varSeries,
      };
    },
    anaOptions() {
      const analito = this.analitosDelTipo.find((a) => a.clave === this.anaForm.analito);
      return {
        chart: { type: 'line', height: 350 },
        title: { text: null },
        credits: { enabled: false },
        xAxis: { type: 'datetime' },
        yAxis: { title: { text: analito ? `${analito.label} (${analito.unidad || ''})` : '' } },
        series: this.anaSeries,
      };
    },
  },
  methods: {
    cargarVariable() {
      if (!this.varForm.ensayo_id || !this.varForm.variable_id) { this.varSeries = []; return; }
      window.axios.get('/soporte/graficas/datos-variable', { params: this.varForm }).then(({ data }) => {
        this.varSeries = data.series.map((s) => ({ name: s.name, data: s.data.map(([f, v]) => [new Date(f).getTime(), v]) }));
      });
    },
    cargarAnalito() {
      if (!this.anaForm.ensayo_id || !this.anaForm.tipo || !this.anaForm.analito) { this.anaSeries = []; return; }
      window.axios.get('/soporte/graficas/datos-analito', { params: this.anaForm }).then(({ data }) => {
        this.anaSeries = data.series.map((s) => ({ name: s.name, data: s.data.map(([f, v]) => [new Date(f).getTime(), v]) }));
      });
    },
  },
};
</script>
