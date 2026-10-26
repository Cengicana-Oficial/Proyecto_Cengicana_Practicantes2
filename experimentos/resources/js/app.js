require('./bootstrap');

window.Vue = require('vue').default;
import Vue from 'vue';

import VueSweetalert2 from 'vue-sweetalert2';
Vue.use(VueSweetalert2);

import HighchartsVue from 'highcharts-vue';
Vue.use(HighchartsVue);

Vue.filter('fecha', function (value) {
    if (!value) return '';
    const d = new Date(value);
    return d.toLocaleDateString('es-GT');
});

// Cada modulo registra aqui su componente raiz (module-prefixed PascalCase),
// montado desde Blade via resources/views/component.blade.php.
Vue.component('DashboardIndex', require('./views/dashboard/index.vue').default);
Vue.component('ProgramasIndex', require('./views/investigacion/programas/index.vue').default);
Vue.component('ProyectosIndex', require('./views/investigacion/proyectos/index.vue').default);
Vue.component('EnsayosIndex', require('./views/investigacion/ensayos/index.vue').default);
Vue.component('VariablesIndex', require('./views/investigacion/variables/index.vue').default);
Vue.component('EvaluacionesIndex', require('./views/investigacion/evaluaciones/index.vue').default);
Vue.component('ArchivosIndex', require('./views/soporte/archivos/index.vue').default);
Vue.component('BitacoraIndex', require('./views/soporte/bitacora/index.vue').default);
Vue.component('UsuariosIndex', require('./views/admin/usuarios/index.vue').default);
Vue.component('LaboratorioIndex', require('./views/soporte/laboratorio/index.vue').default);
Vue.component('MuestrasGenIndex', require('./views/soporte/muestrasgen/index.vue').default);
Vue.component('MuestrasConsultaIndex', require('./views/soporte/muestrasconsulta/index.vue').default);
Vue.component('GraficasIndex', require('./views/soporte/graficas/index.vue').default);
Vue.component('FormulariosIndex', require('./views/soporte/formularios/index.vue').default);
Vue.component('ImagenesGeoIndex', require('./views/soporte/imagenesgeo/index.vue').default);
Vue.component('ReportesIndex', require('./views/soporte/reportes/index.vue').default);
Vue.component('AnalisisIndex', require('./views/analisis/index.vue').default);

const app = new Vue({
    el: '#app',
});
