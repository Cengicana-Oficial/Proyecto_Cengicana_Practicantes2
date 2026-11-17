<template>
  <div class="rp">
    <header class="rp-hero"><div><span>EXPERIMENTOS · INFORMACIÓN PARA COMPARTIR</span><h2>Reportes</h2><p>Exporta los datos de tus ensayos o prepara un informe técnico con sus resultados.</p></div><i class="fas fa-file-alt" aria-hidden="true"></i></header>
    <section class="rp-card">
      <div class="rp-title"><h3>Selecciona el alcance</h3><button v-if="programa || proyecto || ensayoId || desde || hasta" type="button" class="rp-clear" @click="limpiar">Limpiar filtros</button></div>
      <div class="rp-filters">
        <div><label for="rp-programa">Programa</label><select id="rp-programa" v-model="programa" class="form-control"><option value="">Todos los programas</option><option v-for="p in programas" :key="p.id" :value="p.id">{{ p.sigla }} · {{ p.nombre }}</option></select></div>
        <div><label for="rp-proyecto">Proyecto</label><select id="rp-proyecto" v-model="proyecto" class="form-control"><option value="">Todos los proyectos</option><option v-for="p in proyectosDisponibles" :key="p.id" :value="p.id">{{ p.codigo }} · {{ p.nombre }}</option></select></div>
        <div><label for="rp-ensayo">Ensayo</label><select id="rp-ensayo" v-model="ensayoId" class="form-control"><option value="">Todos los ensayos</option><option v-for="e in ensayosDisponibles" :key="e.id" :value="e.id">{{ e.codigo }}{{ e.nombre ? ' · ' + e.nombre : '' }}</option></select></div>
        <div><label for="rp-desde">Desde</label><input id="rp-desde" v-model="desde" class="form-control" type="date"></div>
        <div><label for="rp-hasta">Hasta</label><input id="rp-hasta" v-model="hasta" class="form-control" type="date" :min="desde || undefined"></div>
      </div>
      <p v-if="errorFechas" class="text-danger mt-2" role="alert">La fecha final debe ser igual o posterior a la inicial.</p>
      <p class="rp-note">El periodo filtra evaluaciones, muestras, bitácora, NDVI y el contenido del informe. La cartera y las parcelas muestran su información actual.</p>
    </section>
    <div class="rp-kpis"><div><b>{{ seleccion.length }}</b><span>Ensayos seleccionados</span></div><div><b>{{ total('evaluaciones') }}</b><span>Evaluaciones de campo</span></div><div><b>{{ total('muestras') }}</b><span>Muestras</span></div><div><b>{{ total('parcelas') }}</b><span>Parcelas</span></div></div>
    <small class="rp-note">Los indicadores muestran los totales de los ensayos seleccionados, sin recortar por fechas.</small>
    <p v-if="error" class="alert alert-danger mt-3" role="alert">{{ error }}</p>
    <section class="rp-card mt-3">
      <div class="rp-title"><h3>Descargas en Excel</h3><span>Datos de los {{ seleccion.length }} ensayos seleccionados</span></div>
      <div class="rp-exports"><article v-for="r in exportaciones" :key="r.id"><i :class="r.icon" aria-hidden="true"></i><h4>{{ r.nombre }}</h4><p>{{ r.texto }}</p><small>{{ r.periodo ? 'Aplica el periodo seleccionado' : 'Información actual, sin filtro de fechas' }}</small><button type="button" class="btn btn-outline-success btn-sm" :disabled="!seleccion.length || errorFechas || !!descargando" @click="descargar(r)"><i :class="descargando === r.id ? 'fas fa-spinner fa-spin' : 'fas fa-download'"></i> {{ descargando === r.id ? 'Preparando…' : 'Descargar Excel' }}</button></article></div>
    </section>
    <section class="rp-card">
      <div class="rp-title"><div><h3>Informes técnicos por ensayo</h3><p>Ficha, tratamientos, resultados, ANOVA y Tukey, NDVI y bitácora. Abre el informe para elegir variables y guardarlo como PDF desde la impresión del navegador.</p></div></div>
      <input v-model="busqueda" type="search" class="form-control rp-search" placeholder="Buscar por código o nombre" aria-label="Buscar ensayo para informe">
      <div v-if="!filas.length" class="rp-empty"><i class="fas fa-folder-open"></i><strong>No hay ensayos con esta selección.</strong><span>Revisa los filtros o el texto de búsqueda.</span></div>
      <div v-else class="rp-scroll"><table class="table"><thead><tr><th>Ensayo</th><th>Estado</th><th>Campo</th><th>Muestras</th><th>NDVI</th><th>Informe técnico</th></tr></thead><tbody><tr v-for="e in filas" :key="e.id"><td><b>{{ e.codigo }}</b><small class="rp-name">{{ e.nombre || 'Sin nombre registrado' }}</small></td><td>{{ e.estado }}</td><td>{{ e.evaluaciones }}</td><td>{{ e.muestras }}</td><td>{{ e.ndvi }}</td><td><a v-if="!errorFechas" class="btn btn-sm btn-outline-success" :href="informeUrl(e.id)" target="_blank" rel="noopener"><i class="fas fa-file-alt"></i> Ver informe / PDF</a><span v-else class="text-muted">Corrige el periodo</span></td></tr></tbody></table></div>
      <small class="rp-note">Cada ensayo conserva su propio diseño experimental y análisis estadístico. Los conteos de la tabla corresponden al ensayo completo.</small>
    </section>
  </div>
</template>

<script>
const EXPORTACIONES = [
  { id: 'evaluaciones', nombre: 'Evaluaciones y resultados', icon: 'fas fa-clipboard-list', periodo: true, texto: 'Variables de campo y resultados de laboratorio, con parcela, tratamiento, repetición, valor y unidad.' },
  { id: 'muestras-lab', nombre: 'Muestras de laboratorio', icon: 'fas fa-flask', periodo: true, texto: 'Identificación, estado, resultados, vínculo con Laboratorio e informe de cada muestra.' },
  { id: 'ensayos', nombre: 'Cartera de ensayos', icon: 'fas fa-layer-group', periodo: false, texto: 'Programa, proyecto, responsables, diseño, ciclo y avance de los ensayos seleccionados.' },
  { id: 'parcelas', nombre: 'Parcelas y tratamientos', icon: 'fas fa-th', periodo: false, texto: 'Distribución de parcelas, tratamientos, repeticiones, áreas y notas actualizadas.' },
  { id: 'bitacora', nombre: 'Bitácora de actividades', icon: 'fas fa-book', periodo: true, texto: 'Actividades realizadas por fecha, ensayo, tipo y responsable del registro.' },
  { id: 'ndvi', nombre: 'NDVI por parcela', icon: 'fas fa-leaf', periodo: true, texto: 'Valores calculados de las imágenes: media, mínimo, máximo y píxeles por parcela y fecha.' },
];
export default {
  name: 'ReportesIndex',
  props: { programas: { type: Array, default: () => [] }, proyectos: { type: Array, default: () => [] }, ensayos: { type: Array, default: () => [] }, baseUrl: { type: String, required: true } },
  data() { return { programa: '', proyecto: '', ensayoId: '', desde: '', hasta: '', busqueda: '', descargando: '', error: '', exportaciones: EXPORTACIONES }; },
  computed: {
    proyectosDisponibles() { return this.proyectos.filter((p) => !this.programa || p.programa_id === this.programa); },
    ensayosDisponibles() { return this.ensayos.filter((e) => (!this.programa || e.programa_id === this.programa) && (!this.proyecto || e.proyecto_id === this.proyecto)); },
    seleccion() { return this.ensayosDisponibles.filter((e) => !this.ensayoId || e.id === this.ensayoId); },
    filas() { const q = this.busqueda.trim().toLocaleLowerCase('es'); return this.seleccion.filter((e) => !q || `${e.codigo} ${e.nombre || ''}`.toLocaleLowerCase('es').includes(q)); },
    errorFechas() { return !!(this.desde && this.hasta && this.hasta < this.desde); },
  },
  watch: {
    programa() { if (!this.proyectosDisponibles.some((p) => p.id === this.proyecto)) this.proyecto = ''; this.ajustarEnsayo(); },
    proyecto() { this.ajustarEnsayo(); },
  },
  methods: {
    ajustarEnsayo() { if (!this.ensayosDisponibles.some((e) => e.id === this.ensayoId)) this.ensayoId = ''; },
    total(campo) { return this.seleccion.reduce((s, e) => s + Number(e[campo] || 0), 0).toLocaleString('es-GT'); },
    limpiar() { this.programa = ''; this.proyecto = ''; this.ensayoId = ''; this.desde = ''; this.hasta = ''; this.busqueda = ''; this.error = ''; },
    parametros(periodo = true) { const p = {}; if (this.programa) p.programa_id = this.programa; if (this.proyecto) p.proyecto_id = this.proyecto; if (this.ensayoId) p.ensayo_id = this.ensayoId; if (periodo && this.desde) p.desde = this.desde; if (periodo && this.hasta) p.hasta = this.hasta; return p; },
    informeUrl(id) { const u = new URL(`${this.baseUrl.replace(/\/$/, '')}/informe/${id}`, window.location.origin); if (this.desde) u.searchParams.set('desde', this.desde); if (this.hasta) u.searchParams.set('hasta', this.hasta); return u.toString(); },
    async descargar(r) {
      if (this.descargando || this.errorFechas || !this.seleccion.length) return;
      this.descargando = r.id; this.error = '';
      try {
        const response = await window.axios.get(`${this.baseUrl.replace(/\/$/, '')}/exportar/${r.id}`, { params: this.parametros(r.periodo), responseType: 'blob' });
        const url = URL.createObjectURL(response.data); const a = document.createElement('a'); a.href = url;
        const nombre = (response.headers['content-disposition'] || '').match(/filename="?([^";]+)"?/i);
        a.download = nombre ? nombre[1] : `Experimentos_${r.id}.xlsx`; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
      } catch (err) {
        let d = err.response && err.response.data; if (d instanceof Blob) { try { d = JSON.parse(await d.text()); } catch (_) { d = null; } }
        this.error = d && d.errors ? Object.values(d.errors).flat().join(' ') : (err.response && err.response.status === 403 ? 'No tienes permiso para descargar este reporte.' : 'No se pudo generar el archivo. Revisa los filtros e inténtalo de nuevo.');
      } finally { this.descargando = ''; }
    },
  },
};
</script>

<style scoped>
.rp{max-width:1440px;margin:auto;color:#29382f}.rp-hero{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:26px 28px;background:#edf4e9;border:1px solid #dce8d5;border-radius:12px;margin-bottom:20px}.rp-hero span{font-size:11px;letter-spacing:1.3px;font-weight:700;color:#527443}.rp-hero h2{font-size:26px;font-weight:700;margin:8px 0}.rp-hero p{margin:0;color:#61705f;font-size:14px}.rp-hero>i{font-size:36px;color:#6f9c50}.rp-card{padding:24px;background:white;border:1px solid #dfe5e0;border-radius:12px;margin-bottom:20px}.rp-title{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:18px}.rp-title h3{font-size:18px;font-weight:700;margin:0 0 7px}.rp-title p{font-size:13px;line-height:1.6;margin:0;color:#69766e}.rp-title>span,.rp-note{font-size:12px;color:#69766e;line-height:1.6}.rp-note{margin:14px 0 0}.rp-clear{border:0;background:transparent;color:#527443;font-size:12px;text-decoration:underline}.rp-filters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) repeat(2,minmax(130px,.6fr));gap:14px}.rp-filters>div{min-width:0}.rp label{font-size:12px;font-weight:700}.rp .form-control{height:42px;width:100%;min-width:0;padding:8px 12px}.rp-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}.rp-kpis>div{padding:16px 20px;border:1px solid #dfe5e0;border-radius:10px;background:#fff}.rp-kpis b{display:block;font-size:26px;color:#527443}.rp-kpis span{font-size:12px;color:#69766e}.rp-exports{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.rp-exports article{display:flex;flex-direction:column;align-items:flex-start;gap:10px;padding:20px;border:1px solid #e1e7de;border-radius:10px}.rp-exports article>i{font-size:22px;color:#68964b}.rp-exports h4{font-size:16px;font-weight:700;margin:0}.rp-exports p{font-size:13px;line-height:1.6;color:#69766e;margin:0}.rp-exports small{font-size:11px;color:#69766e;margin-top:auto}.rp-exports button{margin-top:6px}.rp-scroll{overflow:auto;margin:16px 0}.rp-scroll th{background:#f3f7ef;white-space:nowrap;font-size:12px}.rp-scroll td{vertical-align:middle;font-size:13px}.rp-name{display:block;color:#69766e;margin-top:4px}.rp-search{max-width:480px}.rp-empty{display:flex;flex-direction:column;align-items:center;gap:12px;padding:36px;color:#69766e}.rp-empty i{font-size:30px}@media(max-width:1150px){.rp-filters{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:800px){.rp-exports,.rp-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.rp-filters{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:550px){.rp-exports,.rp-filters{grid-template-columns:1fr}.rp-card,.rp-hero{padding:18px}.rp-hero>i{display:none}}
</style>
