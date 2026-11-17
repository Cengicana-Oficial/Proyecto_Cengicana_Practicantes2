import './public-path';

require('./bootstrap');

window.Vue = require('vue').default;
import Vue from 'vue';

import VueSweetalert2 from 'vue-sweetalert2';
// Estilos de SweetAlert2 (sin ellos los avisos se ven como texto plano con un boton "OK").
import 'sweetalert2/dist/sweetalert2.min.css';

// Avisos con la paleta de Cengicaña y textos en español.
Vue.use(VueSweetalert2, {
  confirmButtonColor: '#73BC25',
  cancelButtonColor: '#CED2D5',
  confirmButtonText: 'Aceptar',
  cancelButtonText: 'Cancelar',
  reverseButtons: true,
});

import Highcharts from 'highcharts';
import HighchartsMore from 'highcharts/highcharts-more';
import HighchartsExporting from 'highcharts/modules/exporting';
import HighchartsOfflineExporting from 'highcharts/modules/offline-exporting';
import HighchartsExportData from 'highcharts/modules/export-data';
import HighchartsFullScreen from 'highcharts/modules/full-screen';
import HighchartsVue from 'highcharts-vue';

// Descarga (PNG/JPEG/PDF/SVG/CSV) y pantalla completa: se generan en el navegador, sin
// enviar datos al servidor de exportacion de Highcharts. Desactivado por
// defecto; cada grafico lo habilita con `exporting: { enabled: true }`.
// El PDF usa jsPDF/svg2pdf copiados a dist/js/highcharts-lib (webpack.mix.js).
const appBase = document.querySelector('meta[name="app-base"]');
const highchartsLibURL = (appBase && appBase.content ? appBase.content.replace(/\/+$/, '') : '') + '/dist/js/highcharts-lib/';
HighchartsMore(Highcharts); // barras de error (media ± EE) del analisis estadistico
HighchartsExporting(Highcharts);
HighchartsOfflineExporting(Highcharts);
HighchartsExportData(Highcharts);
HighchartsFullScreen(Highcharts);
Highcharts.setOptions({
    lang: {
        contextButtonTitle: 'Ampliar o descargar',
        viewFullscreen: 'Ver en pantalla completa',
        exitFullscreen: 'Salir de pantalla completa',
        printChart: 'Imprimir',
        downloadPNG: 'Descargar imagen PNG',
        downloadJPEG: 'Descargar imagen JPEG',
        downloadSVG: 'Descargar imagen SVG',
        downloadPDF: 'Descargar PDF',
        downloadCSV: 'Descargar datos (CSV)',
        downloadXLS: 'Descargar datos (Excel)',
        viewData: 'Ver tabla de datos',
        hideData: 'Ocultar tabla de datos',
        months: ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
        shortMonths: ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'],
        weekdays: ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'],
        decimalPoint: '.',
        thousandsSep: ',',
    },
    exporting: {
        enabled: false,
        fallbackToExportServer: false,
        libURL: highchartsLibURL,
        sourceWidth: 1200,
        sourceHeight: 600,
        scale: 1,
        csv: { itemDelimiter: ';', decimalPoint: ',' },
        buttons: {
            contextButton: {
                menuItems: ['viewFullscreen', 'printChart', 'separator', 'downloadPNG', 'downloadSVG', 'separator', 'downloadCSV', 'downloadXLS'],
            },
        },
    },
});
Vue.use(HighchartsVue, { highcharts: Highcharts });

Vue.filter('fecha', function (value) {
    if (!value) return '';
    const d = new Date(value);
    return d.toLocaleDateString('es-GT');
});

// Cada modulo registra aqui su componente raiz (module-prefixed PascalCase),
// montado desde Blade via resources/views/component.blade.php.
// Icono de caña (ensayos): mismo dibujo del menu lateral, disponible en todas las pantallas.
Vue.component('IconoCana', require('./components/icono-cana.vue').default);
Vue.component('DashboardIndex', require('./views/dashboard/index.vue').default);
Vue.component('ProyectosIndex', require('./views/investigacion/proyectos/index.vue').default);
Vue.component('EnsayosIndex', require('./views/investigacion/ensayos/index.vue').default);
Vue.component('ClimaIndex', require('./views/investigacion/clima/index.vue').default);
Vue.component('VariablesIndex', require('./views/investigacion/variables/index.vue').default);
Vue.component('EvaluacionesIndex', require('./views/investigacion/evaluaciones/index.vue').default);
Vue.component('ArchivosIndex', require('./views/soporte/archivos/index.vue').default);
Vue.component('UsuariosIndex', require('./views/admin/usuarios/index.vue').default);
Vue.component('CatalogosIndex', require('./views/admin/catalogos/index.vue').default);
Vue.component('MuestrasGenIndex', require('./views/soporte/muestrasgen/index.vue').default);
Vue.component('MuestrasConsultaIndex', require('./views/soporte/muestrasconsulta/index.vue').default);
Vue.component('MuestrasIndex', require('./views/soporte/muestras/index.vue').default);
Vue.component('FormulariosIndex', require('./views/soporte/formularios/index.vue').default);
Vue.component('ImagenesGeoIndex', require('./views/soporte/imagenesgeo/index.vue').default);
Vue.component('ReportesIndex', require('./views/soporte/reportes/index.vue').default);
Vue.component('InformeEnsayo', require('./views/soporte/reportes/informe-ensayo.vue').default);
Vue.component('AnalisisIndex', require('./views/analisis/index.vue').default);

const app = new Vue({
    el: '#app',
});
