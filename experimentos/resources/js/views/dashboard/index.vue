<template>
  <div>
    <div class="row">
      <div class="col-lg-2 col-6" v-for="kpi in kpiCards" :key="kpi.key">
        <div class="small-box bg-sigec">
          <div class="inner">
            <h3>{{ kpi.value }}</h3>
            <p>{{ kpi.label }}</p>
          </div>
          <div class="icon">
            <i :class="kpi.icon"></i>
          </div>
        </div>
      </div>
    </div>

    <div class="row">
      <div class="col-md-7">
        <div class="card card-outline card-success">
          <div class="card-header">
            <h3 class="card-title">Ensayos por programa</h3>
          </div>
          <div class="card-body">
            <highcharts :options="barOptions"></highcharts>
          </div>
        </div>
      </div>
      <div class="col-md-5">
        <div class="card card-outline card-success">
          <div class="card-header">
            <h3 class="card-title">Estado de ensayos</h3>
          </div>
          <div class="card-body">
            <highcharts :options="donutOptions"></highcharts>
          </div>
        </div>
      </div>
    </div>

    <div class="row">
      <div class="col-12">
        <div class="card card-outline card-success">
          <div class="card-header">
            <h3 class="card-title">Actividad reciente</h3>
          </div>
          <div class="card-body p-0">
            <ul class="list-group list-group-flush" v-if="actividadReciente.length">
              <li class="list-group-item" v-for="(item, idx) in actividadReciente" :key="idx">
                <b>{{ item.titulo }}</b> — {{ item.detalle }}
                <span class="float-right text-muted">{{ item.fecha }}</span>
              </li>
            </ul>
            <div class="text-center text-muted py-4" v-else>
              Sin actividad reciente registrada.
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'DashboardIndex',
  props: {
    kpis: { type: Object, default: () => ({}) },
    ensayosPorPrograma: { type: Object, default: () => ({}) },
    estadoEnsayos: { type: Object, default: () => ({}) },
    actividadReciente: { type: Array, default: () => [] },
  },
  computed: {
    kpiCards() {
      return [
        { key: 'proyectos', label: 'Proyectos', value: this.kpis.proyectos ?? 0, icon: 'fas fa-folder-open' },
        { key: 'programas', label: 'Programas', value: this.kpis.programas ?? 0, icon: 'fas fa-layer-group' },
        { key: 'ensayos_activos', label: 'Ensayos activos', value: this.kpis.ensayos_activos ?? 0, icon: 'fas fa-seedling' },
        { key: 'ensayos_finalizados', label: 'Ensayos finalizados', value: this.kpis.ensayos_finalizados ?? 0, icon: 'fas fa-circle-check' },
        { key: 'ingenios', label: 'Ingenios', value: this.kpis.ingenios ?? 0, icon: 'fas fa-industry' },
        { key: 'investigadores', label: 'Investigadores', value: this.kpis.investigadores ?? 0, icon: 'fas fa-flask' },
      ];
    },
    barOptions() {
      const entries = Object.entries(this.ensayosPorPrograma || {});
      return {
        chart: { type: 'column', height: 300 },
        title: { text: null },
        xAxis: { categories: entries.map(([k]) => k) },
        yAxis: { title: { text: 'Ensayos' }, allowDecimals: false },
        legend: { enabled: false },
        credits: { enabled: false },
        series: [{ name: 'Ensayos', data: entries.map(([, v]) => v), color: '#73BC25' }],
      };
    },
    donutOptions() {
      const entries = Object.entries(this.estadoEnsayos || {});
      const colors = { Planificado: '#FFCC00', 'En campo': '#73BC25', Finalizado: '#1f6fbf' };
      return {
        chart: { type: 'pie', height: 300 },
        title: { text: null },
        credits: { enabled: false },
        plotOptions: { pie: { innerSize: '60%', dataLabels: { enabled: true } } },
        series: [{
          name: 'Ensayos',
          data: entries.map(([k, v]) => ({ name: k, y: v, color: colors[k] || '#CED2D5' })),
        }],
      };
    },
  },
};
</script>
