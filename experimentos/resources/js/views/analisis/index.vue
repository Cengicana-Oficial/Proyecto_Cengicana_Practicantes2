<template>
  <div class="im">
    <header class="im-hero">
      <div>
        <span>EXPERIMENTOS · DATOS DEL ENSAYO</span>
        <h2>Importación de datos</h2>
        <p>Descarga una plantilla con la base de tu ensayo, complétala en Excel y revisa los cambios antes de guardarlos.</p>
      </div>
      <ol class="im-pasos" aria-label="Avance">
        <li v-for="(p, i) in PASOS" :key="p" :class="{ 'is-hecho': paso > i + 1, 'is-actual': paso === i + 1 }"><b>{{ paso > i + 1 ? '✓' : i + 1 }}</b>{{ p }}</li>
      </ol>
    </header>

    <div v-if="!tipos.length" class="alert alert-info">Tu usuario no tiene permisos para importar datos. Consulta al administrador para habilitar el tipo de carga correspondiente.</div>
    <template v-else>
      <!-- 1. Destino -->
      <section class="im-card">
        <h3><span class="im-step">1</span> ¿Qué vas a cargar y en qué ensayo?</h3>
        <div class="im-types">
          <button v-for="t in tiposDisponibles" :key="t.id" type="button" :class="{ activo: tipo === t.id }" :aria-pressed="tipo === t.id" :disabled="ocupado" @click="tipo = t.id">
            <i :class="t.icon" aria-hidden="true"></i><strong>{{ t.nombre }}</strong><small>{{ t.resumen }}</small>
          </button>
        </div>
        <div class="im-fields">
          <div>
            <label for="im-proyecto">Proyecto</label>
            <select id="im-proyecto" v-model="proyecto" class="form-control" :disabled="ocupado">
              <option value="">Todos los proyectos</option>
              <option v-for="p in proyectos" :key="p.id" :value="p.id">{{ p.codigo }} · {{ p.nombre }}</option>
            </select>
          </div>
          <div>
            <label for="im-ensayo">Ensayo</label>
            <select id="im-ensayo" v-model="ensayoId" class="form-control" :disabled="ocupado">
              <option value="">Selecciona un ensayo</option>
              <option v-for="e in ensayosFiltrados" :key="e.id" :value="e.id">{{ e.codigo }}{{ e.nombre ? ' · ' + e.nombre : '' }}</option>
            </select>
          </div>
        </div>
        <div v-if="ensayo" class="im-ensayo">
          <icono-cana :tamano="34"></icono-cana>
          <div class="im-ensayo-nom"><b>{{ ensayo.codigo }}</b><small>{{ [ensayo.nombre, [ensayo.finca, ensayo.lote].filter(Boolean).join(' / '), ensayo.estado].filter(Boolean).join(' · ') }}</small></div>
          <span><b>{{ ensayo.parcelas_count }}</b> parcelas</span>
          <span><b>{{ ensayo.tratamientos_count }}</b> tratamientos</span>
          <span><b>{{ ensayo.evaluaciones_count || 0 }}</b> evaluaciones<small v-if="ensayo.ultima_evaluacion"> · última {{ fechaCorta(ensayo.ultima_evaluacion) }}</small></span>
          <span><b>{{ ensayo.muestras_count }}</b> muestras</span>
        </div>
        <p v-if="ensayo && !ensayo.parcelas_count" class="im-warning">Este ensayo no tiene parcelas: créalas en Ensayos → Estructura antes de importar datos.</p>
        <p class="im-info"><i class="fas fa-info-circle" aria-hidden="true"></i> {{ config.ayuda }}</p>
      </section>

      <div class="im-columns">
        <!-- 2. Plantilla -->
        <section class="im-card">
          <h3><span class="im-step">2</span> Descarga la plantilla</h3>
          <template v-if="tipo === 'evaluaciones'">
            <label>Formato del Excel</label>
            <div class="im-formatos">
              <button v-for="f in FORMATOS" :key="f.id" type="button" :class="{ activo: formato === f.id }" :disabled="ocupado" @click="formato = f.id">
                <strong>{{ f.nombre }}</strong><small>{{ f.texto }}</small>
                <table class="im-mini" aria-hidden="true"><tr v-for="(fila, i) in f.ejemplo" :key="i"><td v-for="(c, j) in fila" :key="j" :class="{ 'is-cab': i === 0 }">{{ c }}</td></tr></table>
              </button>
            </div>

            <div class="im-var-cab">
              <label for="im-buscar-variable">Variables · {{ variableIds.length }} seleccionadas</label>
              <div class="im-variable-actions">
                <button v-if="ensayo && (ensayo.variables_evaluadas || []).length" type="button" :disabled="ocupado" @click="variableIds = [...ensayo.variables_evaluadas]">Las ya evaluadas en este ensayo ({{ ensayo.variables_evaluadas.length }})</button>
                <button type="button" :disabled="ocupado || !variablesVisibles.length" @click="seleccionarVariables">Marcar visibles</button>
                <button type="button" :disabled="ocupado || !variableIds.length" @click="variableIds = []">Quitar selección</button>
              </div>
            </div>
            <input id="im-buscar-variable" v-model="buscarVariable" type="search" class="form-control" placeholder="Buscar altura, diámetro, floración…" :disabled="ocupado">
            <div class="im-variable-list" role="group" aria-label="Variables a incluir en la plantilla">
              <div v-for="g in variablesAgrupadas" :key="g.categoria" class="im-var-grupo">
                <b>{{ g.categoria }}</b>
                <label v-for="v in g.variables" :key="v.id">
                  <input v-model="variableIds" type="checkbox" :value="v.id" :disabled="ocupado">
                  <span>{{ v.nombre }}{{ v.unidad ? ' (' + v.unidad + ')' : '' }}<em v-if="evaluadaEnEnsayo(v.id)" title="Ya tiene datos en este ensayo">con datos</em><small v-if="!v.activa">Inactiva · solo corrección de datos existentes</small></span>
                </label>
              </div>
              <p v-if="!variablesAgrupadas.length">No hay variables que coincidan con la búsqueda.</p>
            </div>

            <label for="im-fecha">Fecha de evaluación</label>
            <div class="im-fecha">
              <input id="im-fecha" v-model="fecha" class="form-control" type="date" :disabled="ocupado">
              <button type="button" :disabled="ocupado" @click="fecha = hoy">Hoy</button>
              <button v-if="ensayo && ensayo.ultima_evaluacion" type="button" :disabled="ocupado" title="Para corregir o completar la última evaluación" @click="fecha = ensayo.ultima_evaluacion">Última ({{ fechaCorta(ensayo.ultima_evaluacion) }})</button>
            </div>
            <p v-if="ensayo && variableIds.length" class="im-estimado">La plantilla tendrá <b>{{ filasEstimadas }}</b> filas{{ formato === 'ancho' ? ` (una por parcela) y ${variableIds.length} columnas de variables` : ' (parcela × variable)' }}.</p>
          </template>
          <div class="im-base"><b>Datos precargados</b><p>{{ config.base }}</p><small v-if="tipo === 'evaluaciones'">Si ya hay datos para esa fecha, la plantilla los trae para corregirlos.</small></div>
          <button type="button" class="btn btn-outline-success" :disabled="!puedeDescargar" @click="plantilla"><i :class="descargando ? 'fas fa-spinner fa-spin' : 'fas fa-download'"></i> {{ descargando ? 'Preparando plantilla…' : 'Descargar plantilla Excel' }}</button>
          <small v-if="ensayo && !puedeDescargar && !ocupado && tipo === 'evaluaciones'" class="im-hint">{{ !variableIds.length ? 'Selecciona al menos una variable.' : 'Indica la fecha de evaluación.' }}</small>
        </section>

        <!-- 3. Archivo -->
        <section class="im-card">
          <h3><span class="im-step">3</span> Sube el archivo completado</h3>
          <label
            class="im-drop" :class="{ 'is-arrastre': arrastrando, 'is-lleno': archivo, 'is-off': ocupado || !ensayoId }"
            @dragover.prevent="arrastrando = !!ensayoId" @dragleave.prevent="arrastrando = false" @drop.prevent="soltar"
          >
            <input ref="archivoInput" type="file" accept=".xlsx,.xls,.csv" :disabled="ocupado || !ensayoId" @change="onFile">
            <i :class="archivo ? 'fas fa-file-excel' : 'fas fa-cloud-upload-alt'" aria-hidden="true"></i>
            <template v-if="archivo"><b>{{ archivo.name }}</b><small>{{ tamano(archivo.size) }} · haz clic para cambiarlo</small></template>
            <template v-else-if="ensayoId"><b>Arrastra aquí el Excel o CSV</b><small>o haz clic para buscarlo · máximo 5 MB y 10 000 filas</small></template>
            <template v-else><b>Selecciona primero el ensayo</b><small>en el paso 1</small></template>
          </label>
          <p class="im-info" v-if="ensayo"><i class="fas fa-bullseye" aria-hidden="true"></i> Destino: <b>{{ ensayo.codigo }}</b> · {{ config.nombre }}. Se lee solo la primera hoja; acepta el formato ancho y el largo.</p>
          <button type="button" class="btn btn-success" :disabled="!archivo || !ensayoId || ocupado" @click="enviar(false)"><i :class="importando && !aplicando ? 'fas fa-spinner fa-spin' : 'fas fa-search'"></i> {{ importando && !aplicando ? 'Revisando…' : 'Revisar archivo' }}</button>
          <small class="im-hint">La revisión no guarda nada: muestra qué se creará, qué se actualizará y qué filas tienen errores.</small>
        </section>
      </div>

      <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>

      <!-- 4. Revision y aplicacion -->
      <section v-if="revision" ref="revision" class="im-card" aria-live="polite">
        <h3><span class="im-step" :class="{ 'is-ok': revision.aplicado }">{{ revision.aplicado ? '✓' : 4 }}</span> {{ revision.aplicado ? 'Importación completada' : 'Revisa y aplica' }}</h3>
        <p><b>{{ ensayo.codigo }}</b> · {{ config.nombre }} · {{ archivo && archivo.name }}. {{ revision.aplicado ? 'Se guardaron las filas válidas.' : 'Todavía no se ha guardado ningún cambio.' }}</p>
        <div class="im-counts">
          <button v-for="c in contadores" :key="c.id" type="button" :class="['is-' + c.id, { activo: filtroRevision === c.id }]" :disabled="!c.filtrable" @click="filtroRevision = filtroRevision === c.id ? '' : c.id">
            <b>{{ c.valor }}</b><span>{{ c.texto }}</span>
          </button>
        </div>
        <p v-if="revision.omitidas.length && !revision.aplicado" class="im-warning"><i class="fas fa-exclamation-triangle"></i> {{ revision.omitidas.length }} filas tienen errores y no se importarán. Corrige el archivo y vuelve a revisarlo, o aplica solo las filas válidas.</p>

        <div v-if="filtroRevision === 'omitidas'" class="im-scroll">
          <table class="table table-sm"><thead><tr><th>Fila</th><th>Registro</th><th>Motivo</th></tr></thead>
            <tbody><tr v-for="(o, i) in revision.omitidas" :key="i"><td>{{ o.fila }}</td><td>{{ o.referencia || '—' }}</td><td class="im-motivo">{{ o.motivo }}</td></tr></tbody>
          </table>
        </div>
        <div v-else-if="detalleFiltrado.length" class="im-scroll">
          <table class="table table-sm"><thead><tr><th>Fila</th><th>Registro</th><th>Acción</th><th>Valor actual</th><th>Valor del archivo</th></tr></thead>
            <tbody><tr v-for="(d, i) in detalleFiltrado" :key="i"><td>{{ d.fila }}</td><td>{{ d.referencia }}</td><td><span class="im-accion" :class="d.accion === 'Crear' ? 'is-crear' : 'is-act'">{{ d.accion }}</span></td><td class="im-antes">{{ d.antes === '' || d.antes === null ? '—' : d.antes }}</td><td><b>{{ d.despues }}</b></td></tr></tbody>
          </table>
        </div>
        <small v-if="revision.creadas + revision.actualizadas > 100 && filtroRevision !== 'omitidas'" class="im-hint">Se muestran los primeros 100 cambios; los contadores incluyen todo el archivo.</small>

        <div class="im-acciones">
          <button v-if="!revision.aplicado" type="button" class="btn btn-success" :disabled="ocupado || !(revision.creadas + revision.actualizadas)" @click="enviar(true)"><i :class="aplicando ? 'fas fa-spinner fa-spin' : 'fas fa-upload'"></i> {{ aplicando ? 'Guardando…' : `Aplicar ${revision.creadas + revision.actualizadas} cambios` }}</button>
          <button v-if="revision.omitidas.length" type="button" class="btn btn-outline-secondary" @click="descargarOmitidas"><i class="fas fa-file-csv"></i> Descargar filas con error</button>
          <template v-if="revision.aplicado">
            <a v-if="tipo !== 'parcelas' && evaluacionesUrl" class="btn btn-outline-success" :href="evaluacionesUrl + '?ensayo=' + ensayoId"><i class="fas fa-chart-bar"></i> Ver en Evaluaciones y Análisis</a>
            <button type="button" class="btn btn-outline-secondary" @click="otraCarga"><i class="fas fa-redo"></i> Otra carga</button>
          </template>
        </div>
      </section>
    </template>
  </div>
</template>

<script>
const TIPOS = [
  { id: 'evaluaciones', nombre: 'Evaluaciones de campo', icon: 'fas fa-clipboard-list', resumen: 'Varias variables y parcelas en un archivo', ayuda: 'Carga altura, diámetro, población y otras variables en un mismo archivo. Cada Parcela + Variable + Fecha se actualiza si existe o se crea si es nueva; las celdas vacías no borran datos.', base: 'Todas las parcelas del ensayo con su tratamiento y repetición, las variables elegidas y la fecha.' },
  { id: 'laboratorio', nombre: 'Resultados de laboratorio', icon: 'fas fa-flask', resumen: 'Muestras manuales y sus analitos', ayuda: 'Una fila por muestra y analito. Solo para muestras manuales: las vinculadas al módulo Laboratorio se actualizan desde ese módulo.', base: 'Las muestras manuales asociadas a parcelas, con todos los analitos de su tipo, unidades y resultados actuales.' },
  { id: 'parcelas', nombre: 'Datos de parcelas', icon: 'fas fa-th', resumen: 'Área y notas de todas las parcelas', ayuda: 'Actualiza el área en m² y las notas de varias parcelas a la vez. No crea parcelas ni cambia códigos, tratamientos o repeticiones.', base: 'Todas las parcelas del ensayo con su tratamiento, repetición, área y notas actuales.' },
];
const FORMATOS = [
  { id: 'ancho', nombre: 'Una fila por parcela', texto: 'Recomendado para captura de campo: una columna por variable.', ejemplo: [['Parcela', 'Altura', 'Tallos'], ['P-01', '2.10', '84'], ['P-02', '1.95', '79']] },
  { id: 'largo', nombre: 'Una fila por dato', texto: 'Parcela × variable. Útil para pegar datos de otros sistemas.', ejemplo: [['Parcela', 'Variable', 'Valor'], ['P-01', 'Altura', '2.10'], ['P-01', 'Tallos', '84']] },
];
const PASOS = ['Destino', 'Plantilla', 'Archivo', 'Revisión'];
const MEMORIA = 'experimentos.importar';

function leer() { try { return JSON.parse(localStorage.getItem(MEMORIA)) || {}; } catch (e) { return {}; } }

export default {
  name: 'AnalisisIndex',
  props: {
    ensayos: { type: Array, default: () => [] },
    variables: { type: Array, default: () => [] },
    tipos: { type: Array, default: () => [] },
    baseUrl: { type: String, required: true },
    evaluacionesUrl: { type: String, default: '' },
  },
  data() {
    const m = leer();
    const qs = new URLSearchParams(window.location.search);
    const id = Number(qs.get('ensayo')) || m.ensayoId;
    return {
      TIPOS, FORMATOS, PASOS,
      proyecto: '',
      ensayoId: this.ensayos.some((e) => e.id === id) ? id : '',
      tipo: this.tipos.includes(qs.get('tipo')) ? qs.get('tipo') : this.tipos.includes(m.tipo) ? m.tipo : this.tipos[0] || 'evaluaciones',
      formato: m.formato === 'largo' ? 'largo' : 'ancho',
      variableIds: [], buscarVariable: '', fecha: '', hoy: new Date().toISOString().slice(0, 10),
      archivo: null, arrastrando: false, revision: null, filtroRevision: '', error: '',
      importando: false, aplicando: false, descargando: false, descargada: false,
    };
  },
  computed: {
    tiposDisponibles() { return TIPOS.filter((t) => this.tipos.includes(t.id)); },
    config() { return TIPOS.find((t) => t.id === this.tipo) || TIPOS[0]; },
    ocupado() { return this.importando || this.descargando; },
    ensayo() { return this.ensayos.find((e) => e.id === this.ensayoId); },
    proyectos() { return [...new Map(this.ensayos.filter((e) => e.proyecto).map((e) => [e.proyecto.id, e.proyecto])).values()]; },
    ensayosFiltrados() { return this.ensayos.filter((e) => !this.proyecto || e.proyecto_id === this.proyecto); },
    variablesAgrupadas() {
      const q = this.buscarVariable.trim().toLocaleLowerCase('es');
      const grupos = new Map();
      // Primero las variables con datos en el ensayo; luego el resto por categoria.
      this.variables.filter((v) => !q || v.nombre.toLocaleLowerCase('es').includes(q)).forEach((v) => {
        const c = this.evaluadaEnEnsayo(v.id) ? '★ Ya evaluadas en este ensayo' : v.categoria || 'Sin categoría';
        if (!grupos.has(c)) grupos.set(c, []);
        grupos.get(c).push(v);
      });
      return [...grupos.entries()].sort((a, b) => (a[0].startsWith('★') ? -1 : b[0].startsWith('★') ? 1 : a[0].localeCompare(b[0], 'es')))
        .map(([categoria, variables]) => ({ categoria, variables }));
    },
    variablesVisibles() { return this.variablesAgrupadas.flatMap((g) => g.variables); },
    filasEstimadas() { const p = (this.ensayo && this.ensayo.parcelas_count) || 0; return this.formato === 'ancho' ? p : p * this.variableIds.length; },
    puedeDescargar() { return !!this.ensayoId && !this.ocupado && (this.tipo !== 'evaluaciones' || (this.variableIds.length && this.fecha)); },
    paso() {
      if (!this.ensayoId) return 1;
      if (this.revision) return 4;
      if (this.archivo) return 3;
      return this.descargada ? 3 : 2;
    },
    contadores() {
      const r = this.revision; const a = r.aplicado;
      return [
        { id: 'Crear', texto: a ? 'Creadas' : 'Por crear', valor: r.creadas, filtrable: !!r.creadas },
        { id: 'Actualizar', texto: a ? 'Actualizadas' : 'Por actualizar', valor: r.actualizadas, filtrable: !!r.actualizadas },
        { id: 'sin', texto: 'Sin cambios', valor: r.sin_cambios, filtrable: false },
        { id: 'vacias', texto: 'Celdas vacías', valor: r.vacias, filtrable: false },
        { id: 'omitidas', texto: 'Con errores', valor: r.omitidas.length, filtrable: !!r.omitidas.length },
      ];
    },
    detalleFiltrado() {
      const d = (this.revision && this.revision.detalle) || [];
      return this.filtroRevision && this.filtroRevision !== 'omitidas' ? d.filter((x) => x.accion === this.filtroRevision) : d;
    },
  },
  watch: {
    proyecto() { if (this.ensayoId && !this.ensayosFiltrados.some((e) => e.id === this.ensayoId)) this.ensayoId = ''; },
    ensayoId() { this.reiniciar(); this.descargada = false; this.recordar(); },
    tipo() { this.reiniciar(); this.descargada = false; this.recordar(); },
    formato() { this.recordar(); },
  },
  methods: {
    recordar() { try { localStorage.setItem(MEMORIA, JSON.stringify({ ensayoId: this.ensayoId, tipo: this.tipo, formato: this.formato })); } catch (e) { /* sin almacenamiento */ } },
    evaluadaEnEnsayo(id) { return !!this.ensayo && (this.ensayo.variables_evaluadas || []).includes(id); },
    seleccionarVariables() { this.variableIds = [...new Set([...this.variableIds, ...this.variablesVisibles.map((v) => v.id)])]; },
    fechaCorta(f) { return f ? String(f).slice(0, 10).split('-').reverse().join('/') : ''; },
    tamano(b) { return b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`; },
    reiniciar() { this.archivo = null; this.revision = null; this.filtroRevision = ''; this.error = ''; if (this.$refs.archivoInput) this.$refs.archivoInput.value = ''; },
    otraCarga() { this.reiniciar(); this.descargada = false; window.scrollTo({ top: 0, behavior: 'smooth' }); },
    tomarArchivo(archivo) {
      this.revision = null; this.error = ''; this.archivo = null;
      if (!archivo) return;
      if (!/\.(xlsx|xls|csv)$/i.test(archivo.name) || archivo.size > 5 * 1024 * 1024) { this.error = 'Selecciona un Excel o CSV de hasta 5 MB.'; return; }
      this.archivo = archivo;
    },
    onFile(e) { this.tomarArchivo(e.target.files[0]); if (!this.archivo) e.target.value = ''; },
    soltar(e) { this.arrastrando = false; if (this.ensayoId && !this.ocupado) this.tomarArchivo(e.dataTransfer.files[0]); },
    async mensaje(err) {
      let d = err.response && err.response.data;
      if (d instanceof Blob) { try { d = JSON.parse(await d.text()); } catch (_) { d = null; } }
      if (d && d.errors) return Object.values(d.errors).flat().join(' ');
      if (err.response && err.response.status === 403) return 'No tienes permiso para realizar esta carga.';
      if (err.response && err.response.status === 404) return 'El ensayo ya no está disponible para tu usuario.';
      return (d && d.message) || 'No se pudo completar la operación. Vuelve a revisar el archivo antes de aplicar cambios.';
    },
    async plantilla() {
      if (!this.puedeDescargar) return;
      this.descargando = true; this.error = '';
      try {
        const params = { ensayo_id: this.ensayoId, tipo: this.tipo };
        if (this.tipo === 'evaluaciones') Object.assign(params, { variable_ids: this.variableIds, fecha: this.fecha, formato: this.formato });
        const { data } = await window.axios.get(this.baseUrl.replace(/\/$/, '') + '/plantilla', { params, responseType: 'blob' });
        this.guardarArchivo(data, `Experimentos_${this.tipo}_${this.ensayo.codigo.replace(/[^\w-]/g, '_')}${this.tipo === 'evaluaciones' ? '_' + this.fecha : ''}.xlsx`);
        this.descargada = true;
      } catch (err) { this.error = await this.mensaje(err); }
      finally { this.descargando = false; }
    },
    guardarArchivo(blob, nombre) {
      const url = URL.createObjectURL(blob); const a = document.createElement('a');
      a.href = url; a.download = nombre; document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    },
    descargarOmitidas() {
      const celda = (v) => `"${String(v == null ? '' : v).replace(/"/g, '""')}"`;
      const filas = [['Fila', 'Registro', 'Motivo'], ...this.revision.omitidas.map((o) => [o.fila, o.referencia, o.motivo])];
      this.guardarArchivo(new Blob(['﻿' + filas.map((f) => f.map(celda).join(';')).join('\r\n')], { type: 'text/csv;charset=utf-8' }), `Errores_importacion_${this.ensayo.codigo}.csv`);
    },
    async enviar(aplicar) {
      if (!this.archivo || !this.ensayoId || this.ocupado || (aplicar && (!this.revision || this.revision.aplicado))) return;
      this.importando = true; this.aplicando = aplicar; this.error = '';
      const fd = new FormData();
      fd.append('ensayo_id', this.ensayoId); fd.append('tipo', this.tipo); fd.append('archivo', this.archivo); fd.append('accion', aplicar ? 'aplicar' : 'revisar');
      if (aplicar) fd.append('firma', this.revision.firma); else this.revision = null;
      try {
        const { data } = await window.axios.post(this.baseUrl.replace(/\/$/, '') + '/importar', fd);
        this.revision = data;
        this.filtroRevision = !aplicar && data.omitidas.length && !(data.creadas + data.actualizadas) ? 'omitidas' : '';
        if (aplicar && data.aplicado) {
          const e = this.ensayo;
          if (this.tipo === 'evaluaciones') e.evaluaciones_count = (e.evaluaciones_count || 0) + data.creadas;
        }
        this.$nextTick(() => this.$refs.revision && this.$refs.revision.scrollIntoView({ behavior: 'smooth', block: 'start' }));
      } catch (err) { this.revision = null; this.error = await this.mensaje(err); }
      finally { this.importando = false; this.aplicando = false; }
    },
  },
};
</script>

<style scoped>
.im{max-width:1440px;margin:auto;color:#29382f}
.im-hero{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;padding:24px 28px;background:#edf4e9;border:1px solid #dce8d5;border-radius:12px;margin-bottom:20px}
.im-hero span{font-size:11px;letter-spacing:1.3px;font-weight:700;color:#527443}.im-hero h2{font-size:26px;font-weight:700;margin:8px 0}.im-hero p{margin:0;color:#61705f;font-size:14px;max-width:620px}
.im-pasos{display:flex;gap:6px;margin:0;padding:0;list-style:none}
.im-pasos li{display:flex;align-items:center;gap:6px;padding:6px 12px 6px 6px;border-radius:999px;background:#fff;border:1px solid #dce8d5;font-size:12px;color:#8a948c}
.im-pasos li b{display:grid;place-items:center;width:22px;height:22px;border-radius:50%;background:#eef1ec;font-size:11px}
.im-pasos li.is-actual{color:#29382f;border-color:#73bc25;font-weight:600}.im-pasos li.is-actual b{background:#73bc25;color:#fff}
.im-pasos li.is-hecho{color:#527443}.im-pasos li.is-hecho b{background:#dcefc6;color:#3f7a12}
.im-card{padding:24px;border:1px solid #dfe5e0;background:#fff;border-radius:12px;margin-bottom:20px;min-width:0}
.im-card h3{display:flex;gap:10px;align-items:center;font-size:18px;font-weight:700;margin-bottom:18px}
.im-step{display:inline-grid;place-items:center;width:30px;height:30px;border-radius:8px;background:#edf4e9;color:#527443;font-size:14px}.im-step.is-ok{background:#73bc25;color:#fff}
.im-card p{font-size:13px;line-height:1.65}
.im label{font-size:12px;font-weight:700}
.im .form-control{width:100%;min-width:0;height:42px;padding:8px 12px}
.im-types{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px}
.im-types button,.im-formatos button{display:flex;flex-direction:column;align-items:flex-start;gap:6px;padding:16px;border:1px solid #dfe5e0;border-radius:10px;background:#fff;color:#29382f;text-align:left}
.im-types button.activo,.im-formatos button.activo{border-color:#78a44f;background:#f2f8ec;box-shadow:inset 0 0 0 1px #78a44f}
.im-types i{font-size:20px;color:#68964b}.im-types small,.im-formatos small{font-size:12px;color:#69766e}
.im-fields{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px}.im-fields>div{min-width:0}
.im-ensayo{display:flex;align-items:center;flex-wrap:wrap;gap:10px 22px;margin-top:16px;padding:12px 16px;background:#f7fbf1;border:1px solid #dcebc8;border-radius:10px;font-size:12px;color:#69766e}
.im-ensayo-nom{flex:1;min-width:200px}.im-ensayo-nom b{display:block;font-size:15px;color:#29382f}.im-ensayo-nom small{font-size:12px}
.im-ensayo>span b{font-size:18px;color:#3f7a12;margin-right:3px}
.im-info{padding:12px 14px;background:#f5f8f2;border-radius:8px;color:#52664a;margin:16px 0 0}
.im-columns{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,1fr);gap:20px;align-items:start}
.im-formatos{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:6px 0 18px}
.im-mini{margin-top:6px;border-collapse:collapse;font-size:10px;font-family:monospace}
.im-mini td{padding:1px 6px;border:1px solid #dfe5e0;background:#fff;color:#4d5a48}.im-mini td.is-cab{background:#e6f2d8;font-weight:700}
.im-var-cab{display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:4px 14px}
.im-variable-actions{display:flex;flex-wrap:wrap;gap:14px;margin:4px 0 8px}
.im-variable-actions button{border:0;background:transparent;color:#527443;font-size:12px;padding:0;text-decoration:underline}.im-variable-actions button:disabled{opacity:.5}
.im-variable-list{max-height:260px;overflow:auto;border:1px solid #dfe5e0;border-radius:8px;padding:6px 12px;margin:8px 0 16px}
.im-var-grupo>b{display:block;margin:8px 0 2px;font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:#69766e}
.im-variable-list label{display:flex;align-items:flex-start;gap:10px;padding:5px 0;margin:0;font-weight:500;cursor:pointer}
.im-variable-list input{margin-top:3px;accent-color:#73bc25;flex-shrink:0}
.im-variable-list em{margin-left:6px;padding:0 6px;border-radius:8px;background:#e6f2d8;color:#3f7a12;font-size:10px;font-style:normal;font-weight:700}
.im-variable-list small{display:block;color:#69766e;font-size:11px}.im-variable-list p{margin:8px 0;color:#69766e}
.im-fecha{display:flex;gap:8px}.im-fecha .form-control{flex:1}
.im-fecha button{flex:none;border:1px solid #cfdcc6;background:#fff;border-radius:8px;padding:0 12px;font-size:12px;color:#3f5a2f}
.im-estimado{margin:10px 0 0;font-size:12px!important;color:#527443}
.im-base{padding:14px;background:#f6f8f5;border-radius:8px;margin:16px 0;font-size:13px}.im-base p{margin:7px 0}.im-base small{color:#69766e}
.im-hint{display:block;margin:10px 0 0;color:#69766e;font-size:12px;line-height:1.6}
.im-drop{display:flex;flex-direction:column;align-items:center;gap:6px;padding:34px 16px;border:2px dashed #b3c4aa;border-radius:12px;background:#fafcf8;text-align:center;cursor:pointer;transition:.15s}
.im-drop input{display:none}.im-drop i{font-size:30px;color:#73bc25}.im-drop b{font-size:14px}.im-drop small{font-size:12px;color:#69766e;font-weight:400}
.im-drop.is-arrastre{border-color:#73bc25;background:#eef7e3}.im-drop.is-lleno{border-style:solid;border-color:#a3d300;background:#f7fbf1}
.im-drop.is-off{cursor:not-allowed;opacity:.6}
.im-counts{display:flex;flex-wrap:wrap;gap:10px;margin:18px 0}
.im-counts button{min-width:130px;padding:12px 16px;border:1px solid #e1e7dc;border-radius:10px;background:#f6f8f5;text-align:left;color:#29382f}
.im-counts button:disabled{cursor:default}.im-counts button.activo{border-color:#73bc25;box-shadow:inset 0 0 0 1px #73bc25;background:#fff}
.im-counts b{display:block;font-size:24px;color:#527443}.im-counts span{font-size:12px}
.im-counts .is-Actualizar b{color:#2a78d6}.im-counts .is-omitidas b{color:#b84d00}.im-counts .is-sin b,.im-counts .is-vacias b{color:#8a948c}
.im-scroll{overflow:auto;max-height:380px;margin:14px 0;border:1px solid #eef2eb;border-radius:8px}
.im-scroll th{position:sticky;top:0;background:#f2f6ed;white-space:nowrap;font-size:11px}
.im-scroll td{font-size:12px;overflow-wrap:anywhere;min-width:60px;max-width:350px}
.im-accion{padding:1px 8px;border-radius:8px;font-size:11px;font-weight:700}.im-accion.is-crear{background:#e3f2d1;color:#3f7a12}.im-accion.is-act{background:#e4effb;color:#2a78d6}
.im-antes{color:#8a948c;text-decoration:line-through}.im-motivo{color:#7a4a00}
.im-warning{background:#fff7e6;padding:12px;border-radius:8px;color:#7a4a00}
.im-acciones{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
@media(max-width:1050px){.im-columns{grid-template-columns:1fr;gap:0}}
@media(max-width:650px){.im-types,.im-fields,.im-formatos{grid-template-columns:1fr}.im-card,.im-hero{padding:18px}.im-hero h2{font-size:23px}.im-pasos{flex-wrap:wrap}}
</style>
