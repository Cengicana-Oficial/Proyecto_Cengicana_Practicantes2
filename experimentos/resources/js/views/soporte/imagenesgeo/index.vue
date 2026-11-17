<template>
  <div class="ig">
    <!-- Guia: que archivo subir y que se obtiene -->
    <section class="ig-guia" :class="{ 'is-plegada': !verGuia }">
      <header>
        <i class="fas fa-satellite"></i>
        <div><b>¿Cómo cargar imágenes de satélite o dron?</b><small>Se guardan por ensayo y fecha; las de NDVI se convierten en un valor por parcela.</small></div>
        <button type="button" class="btn btn-sm btn-outline-secondary" @click="verGuia = !verGuia">{{ verGuia ? 'Ocultar guía' : 'Ver guía' }}</button>
      </header>
      <template v-if="verGuia">
        <ol class="ig-flujo">
          <li><span>1</span><div><b>Sube la imagen aquí</b><small>Elige el ensayo, el archivo, el tipo y la fecha de captura.</small></div></li>
          <li><span>2</span><div><b>Procesa el NDVI en el ensayo</b><small>En la pestaña <b>NDVI</b> del ensayo, botón <b>"Procesar NDVI"</b>: calcula la media de cada parcela.</small></div></li>
          <li><span>3</span><div><b>Úsalo en el análisis</b><small>Mapa GPS por fecha, gráficas en Evaluaciones y tablas del Informe técnico.</small></div></li>
        </ol>
        <div class="ig-tabla-wrap">
          <table class="ig-tabla">
            <thead><tr><th>Lo que tienes</th><th>Archivo</th><th>Tipo a elegir</th><th>Resultado</th></tr></thead>
            <tbody>
              <tr v-for="g in GUIA" :key="g.que"><td><b>{{ g.que }}</b><small>{{ g.ej }}</small></td><td><code>{{ g.archivo }}</code></td><td><span class="ig-tipo" :style="estiloTipo(g.tipo)">{{ g.tipo }}</span></td><td :class="{ 'is-ndvi': g.ndvi }"><i :class="g.ndvi ? 'fas fa-check-circle' : 'fas fa-image'"></i> {{ g.resultado }}</td></tr>
            </tbody>
          </table>
        </div>
        <p class="ig-req"><i class="fas fa-draw-polygon"></i> <span><b>Requisito para el NDVI por parcela:</b> el ensayo debe tener sus parcelas dibujadas en el mapa (ensayo → Estructura → Croquis / Mapa GPS). Sin polígonos, la imagen se guarda y se ve en el mapa, pero no se puede calcular el valor de cada parcela.</span></p>
      </template>
    </section>

    <!-- Carga -->
    <section v-if="puedeSubir" class="ig-card">
      <h3><i class="fas fa-cloud-upload-alt"></i> Subir imagen</h3>
      <div class="ig-form">
        <div class="ig-col">
          <label>1. Ensayo
            <select v-model="form.ensayo_id" class="form-control" :class="{ 'is-invalid': errors.ensayo_id }">
              <option :value="null">Selecciona el ensayo…</option>
              <option v-for="e in ensayos" :key="e.id" :value="e.id">{{ e.codigo }}{{ e.nombre ? ' · ' + e.nombre : '' }}</option>
            </select>
          </label>
          <p v-if="ensayoForm" class="ig-estado-ens" :class="ensayoForm.con_poligono ? 'is-ok' : 'is-aviso'">
            <i :class="ensayoForm.con_poligono ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle'"></i>
            <span v-if="ensayoForm.con_poligono">{{ ensayoForm.con_poligono }} de {{ ensayoForm.parcelas }} parcelas dibujadas en el mapa: se podrá calcular el NDVI por parcela.</span>
            <span v-else>Este ensayo aún no tiene parcelas dibujadas en el mapa. Puedes subir la imagen, pero para el NDVI por parcela primero <a :href="`${ensayosUrl}?ensayo=${ensayoForm.id}&tab=estructura&vista=croquis`">dibuja las parcelas</a>.</span>
          </p>

          <label class="ig-mt">2. Archivo</label>
          <label class="ig-drop" :class="{ 'is-arrastre': arrastrando, 'is-lleno': form.archivo, 'is-invalid': errors.archivo }" @dragover.prevent="arrastrando = true" @dragleave.prevent="arrastrando = false" @drop.prevent="arrastrando = false; elegir($event.dataTransfer.files[0])">
            <input ref="fileInput" type="file" :accept="ACEPTA" @change="elegir($event.target.files[0])">
            <i :class="form.archivo ? 'fas fa-file-image' : 'fas fa-cloud-upload-alt'"></i>
            <template v-if="form.archivo"><b>{{ form.archivo.name }}</b><small>{{ tamano(form.archivo.size) }} · clic para cambiarlo</small></template>
            <template v-else><b>Arrastra aquí la imagen</b><small>GeoTIFF (.tif), KMZ de dron, PNG, JPG · máx. 50 MB</small></template>
          </label>
          <p v-if="errors.archivo" class="ig-error">{{ errors.archivo[0] }}</p>
          <p v-if="pesado" class="ig-error">El archivo pesa más de 50 MB. Súbelo directo en la pestaña NDVI del ensayo: allí se procesa en tu navegador sin límite de tamaño. <a v-if="form.ensayo_id" :href="`${ensayosUrl}?ensayo=${form.ensayo_id}&tab=ndvi`">Ir a la pestaña NDVI</a></p>
          <p v-if="detectado" class="ig-detectado"><i class="fas fa-magic"></i> {{ detectado }}</p>
        </div>

        <div class="ig-col">
          <label>3. Tipo de imagen</label>
          <div class="ig-tipos">
            <button v-for="t in TIPOS" :key="t.id" type="button" :class="{ 'is-on': form.tipo === t.id }" :style="{ '--c': t.color }" @click="form.tipo = t.id">
              <b>{{ t.label }}</b><small>{{ t.texto }}</small>
            </button>
          </div>
          <div class="ig-fila">
            <label>Fecha de captura<input v-model="form.fecha" type="date" class="form-control" :max="hoy" :class="{ 'is-invalid': errors.fecha }"></label>
            <label>Sensor o fuente
              <input v-model="form.sensor" class="form-control" list="ig-sensores" maxlength="100" placeholder="Sentinel-2, MicaSense…">
              <datalist id="ig-sensores"><option v-for="s in SENSORES" :key="s" :value="s"></option></datalist>
            </label>
          </div>
          <div class="ig-fila">
            <label>Resolución <small>(opcional)</small><input v-model="form.resolucion" class="form-control" maxlength="50" placeholder="10 m, 5 cm…"></label>
            <label v-if="form.tipo === 'Multiespectral'">Orden de bandas<input v-model="form.bandas" class="form-control" maxlength="50" placeholder="B, G, R, RE, NIR"></label>
          </div>
          <label>Notas <small>(opcional)</small><input v-model="form.notas" class="form-control" maxlength="1000" placeholder="Nubosidad, altura de vuelo, hora…"></label>
        </div>
      </div>
      <div class="ig-acciones">
        <span>{{ resumenForm }}</span>
        <button type="button" class="btn btn-success" :disabled="subiendo || !listo" @click="subir"><i :class="subiendo ? 'fas fa-spinner fa-spin' : 'fas fa-upload'"></i> {{ subiendo ? `Subiendo ${avance} %` : 'Subir imagen' }}</button>
      </div>
      <div v-if="recien" class="ig-siguiente">
        <i class="fas fa-check-circle"></i>
        <span><b>{{ recien.nombre }}</b> quedó guardada en {{ codigoEnsayo(recien.ensayo_id) }}.
          <template v-if="esNdvi(recien)"> Siguiente paso: calcula el NDVI de cada parcela.</template>
          <template v-else> Es una imagen de referencia: se consulta aquí y en los archivos del ensayo.</template>
        </span>
        <a v-if="esNdvi(recien)" class="btn btn-success btn-sm" :href="`${ensayosUrl}?ensayo=${recien.ensayo_id}&tab=ndvi`"><i class="fas fa-cogs"></i> Procesar NDVI en el ensayo</a>
      </div>
    </section>

    <!-- Imagenes cargadas -->
    <div class="ig-toolbar">
      <h3>Imágenes cargadas <small>{{ filtradas.length }} de {{ items.length }}</small></h3>
      <select v-model="fEnsayo" class="form-control" aria-label="Ensayo"><option :value="null">Todos los ensayos</option><option v-for="e in ensayosConImagen" :key="e.id" :value="e.id">{{ e.codigo }}</option></select>
      <select v-model="fTipo" class="form-control" aria-label="Tipo"><option value="">Todos los tipos</option><option v-for="t in TIPOS" :key="t.id" :value="t.id">{{ t.label }}</option></select>
      <div class="ig-seg" role="group" aria-label="Estado">
        <button v-for="s in ESTADOS" :key="s.id" type="button" :class="{ 'is-on': fEstado === s.id }" @click="fEstado = s.id">{{ s.label }} <em>{{ conteoEstado(s.id) }}</em></button>
      </div>
    </div>

    <div v-if="!filtradas.length" class="ig-vacio"><i class="fas fa-satellite"></i><b>{{ items.length ? 'Ninguna imagen coincide con los filtros.' : 'Todavía no hay imágenes cargadas.' }}</b><span v-if="!items.length">Sigue la guía de arriba para subir la primera.</span></div>

    <div class="ig-grid">
      <article v-for="img in filtradas" :key="img.id" class="ig-img">
        <a class="ig-thumb" :href="img.url" target="_blank" rel="noopener" :title="'Abrir ' + img.nombre">
          <img v-if="['png', 'jpg', 'jpeg', 'webp'].includes(img.extension)" :src="img.url" :alt="img.nombre" loading="lazy">
          <span v-else class="ig-thumb-ico"><i :class="img.extension === 'kmz' ? 'fas fa-globe-americas' : 'fas fa-layer-group'"></i><small>{{ img.extension.toUpperCase() }}</small></span>
          <span class="ig-tipo is-flota" :style="estiloTipo(img.tipo)">{{ img.tipo }}</span>
        </a>
        <div class="ig-info">
          <b :title="img.nombre">{{ img.nombre }}</b>
          <small><icono-cana :tamano="13"></icono-cana> {{ codigoEnsayo(img.ensayo_id) }} · <i class="far fa-calendar"></i> {{ fecha(img.fecha) }}</small>
          <small v-if="img.sensor || img.resolucion">{{ [img.sensor, img.resolucion].filter(Boolean).join(' · ') }}</small>
          <small class="ig-gris">{{ [img.tamano ? tamano(img.tamano) : '', img.width ? img.width + '×' + img.height + ' px' : '', img.subido_por].filter(Boolean).join(' · ') }}</small>
        </div>
        <div class="ig-estado" :class="'is-' + estado(img)">
          <template v-if="estado(img) === 'procesada'"><i class="fas fa-check-circle"></i> NDVI por parcela del {{ fecha(img.ndvi.fecha) }}</template>
          <template v-else-if="estado(img) === 'pendiente'"><i class="fas fa-hourglass-half"></i> Pendiente de procesar</template>
          <template v-else><i class="fas fa-image"></i> Imagen de referencia</template>
        </div>
        <footer>
          <a v-if="estado(img) === 'pendiente'" class="btn btn-success btn-sm" :href="`${ensayosUrl}?ensayo=${img.ensayo_id}&tab=ndvi`"><i class="fas fa-cogs"></i> Procesar NDVI</a>
          <a v-else-if="estado(img) === 'procesada'" class="btn btn-outline-success btn-sm" :href="`${ensayosUrl}?ensayo=${img.ensayo_id}&tab=ndvi`"><i class="fas fa-th"></i> Ver por parcela</a>
          <a class="ig-icon" :href="img.url" :download="img.nombre" title="Descargar"><i class="fas fa-download"></i></a>
          <button v-if="img.puede_eliminar" type="button" class="ig-icon is-peligro" title="Eliminar" @click="eliminar(img)"><i class="fas fa-trash"></i></button>
        </footer>
      </article>
    </div>
  </div>
</template>

<script>
const TIPOS = [
  { id: 'NDVI', label: 'NDVI', color: '#3f7a12', texto: 'Índice ya calculado (1 banda o mapa de color)' },
  { id: 'Multiespectral', label: 'Multiespectral', color: '#8a6a00', texto: 'Varias bandas con Rojo e Infrarrojo (NIR)' },
  { id: 'RGB', label: 'RGB (color)', color: '#2a78d6', texto: 'Foto u ortomosaico en color, de referencia' },
  { id: 'Termica', label: 'Térmica', color: '#d24f00', texto: 'Temperatura de la superficie, de referencia' },
];
const GUIA = [
  { que: 'NDVI de satélite', ej: 'Sentinel-2 / EO Browser, Landsat, QGIS, Google Earth Engine', archivo: '.tif (1 banda)', tipo: 'NDVI', ndvi: true, resultado: 'NDVI por parcela' },
  { que: 'Imagen multiespectral de dron', ej: 'MicaSense, Parrot Sequoia, DJI Multispectral', archivo: '.tif (varias bandas)', tipo: 'Multiespectral', ndvi: true, resultado: 'NDVI calculado (Rojo/NIR) por parcela' },
  { que: 'Mapa NDVI exportado del dron', ej: 'DJI Terra, Pix4D, Agisoft, DroneDeploy', archivo: '.kmz', tipo: 'NDVI', ndvi: true, resultado: 'NDVI por parcela, leído de la escala de color' },
  { que: 'Ortomosaico o foto aérea en color', ej: 'Vuelo RGB, captura de pantalla georreferenciada', archivo: '.kmz · .tif · .jpg', tipo: 'RGB', ndvi: false, resultado: 'Referencia visual en el Mapa GPS' },
  { que: 'Imagen térmica', ej: 'Cámara térmica de dron', archivo: '.tif · .jpg', tipo: 'Termica', ndvi: false, resultado: 'Referencia (estrés hídrico)' },
];
const ESTADOS = [{ id: '', label: 'Todas' }, { id: 'pendiente', label: 'Pendientes' }, { id: 'procesada', label: 'Con NDVI' }, { id: 'referencia', label: 'Referencia' }];
const SENSORES = ['Sentinel-2 (Copernicus)', 'Landsat-8/9', 'Google Earth Engine', 'Dron DJI Mavic 3 Multispectral', 'Dron DJI Phantom 4 RTK', 'MicaSense RedEdge (dron)', 'Parrot Sequoia (dron)'];
const ACEPTA = '.tif,.tiff,.kmz,.png,.jpg,.jpeg,.webp';
const MAX = 50 * 1048576;

function leerGuia() { try { return localStorage.getItem('experimentos.imagenes.guia') !== '0'; } catch (e) { return true; } }

export default {
  name: 'ImagenesGeoIndex',
  props: {
    imagenes: { type: Array, default: () => [] },
    ensayos: { type: Array, default: () => [] },
    ensayosUrl: { type: String, default: '' },
    puedeSubir: { type: Boolean, default: false },
  },
  data() {
    const qs = new URLSearchParams(window.location.search);
    const ens = Number(qs.get('ensayo')) || null;
    return {
      TIPOS, GUIA, ESTADOS, SENSORES, ACEPTA,
      items: this.imagenes.slice(),
      verGuia: leerGuia(),
      form: this.formVacio(this.ensayos.some((e) => e.id === ens) ? ens : null),
      errors: {}, subiendo: false, avance: 0, arrastrando: false, detectado: '', recien: null,
      fEnsayo: ens, fTipo: '', fEstado: '',
      hoy: new Date().toISOString().slice(0, 10),
    };
  },
  computed: {
    ensMap() { return new Map(this.ensayos.map((e) => [e.id, e])); },
    ensayoForm() { return this.ensMap.get(this.form.ensayo_id) || null; },
    ensayosConImagen() { const ids = new Set(this.items.map((i) => i.ensayo_id)); return this.ensayos.filter((e) => ids.has(e.id)); },
    pesado() { return !!this.form.archivo && this.form.archivo.size > MAX; },
    listo() { return !!(this.form.ensayo_id && this.form.archivo && !this.pesado && this.form.fecha && this.form.tipo); },
    resumenForm() {
      if (!this.form.ensayo_id) return 'Elige el ensayo.';
      if (!this.form.archivo) return 'Elige el archivo.';
      if (!this.form.fecha) return 'Indica la fecha de captura.';
      return `${this.form.tipo} · ${this.codigoEnsayo(this.form.ensayo_id)} · ${this.fecha(this.form.fecha)}`;
    },
    base() { return this.items.filter((i) => (!this.fEnsayo || i.ensayo_id === this.fEnsayo) && (!this.fTipo || i.tipo === this.fTipo)); },
    filtradas() { return this.base.filter((i) => !this.fEstado || this.estado(i) === this.fEstado); },
  },
  watch: { verGuia(v) { try { localStorage.setItem('experimentos.imagenes.guia', v ? '1' : '0'); } catch (e) { /* sin almacenamiento */ } } },
  methods: {
    formVacio(ensayoId = null) {
      return { ensayo_id: ensayoId, tipo: 'NDVI', fecha: '', sensor: '', resolucion: '', bandas: '', notas: '', archivo: null };
    },
    codigoEnsayo(id) { return (this.ensMap.get(id) || {}).codigo || '—'; },
    esNdvi(img) { return ['NDVI', 'Multiespectral'].includes(img.tipo) && ['tif', 'tiff', 'kmz', 'png', 'jpg', 'jpeg'].includes(img.extension); },
    estado(img) { return img.ndvi ? 'procesada' : this.esNdvi(img) ? 'pendiente' : 'referencia'; },
    conteoEstado(id) { return id ? this.base.filter((i) => this.estado(i) === id).length : this.base.length; },
    estiloTipo(tipo) { const t = TIPOS.find((x) => x.id === tipo) || {}; return { '--c': t.color || '#69766e' }; },
    fecha(f) { return f ? String(f).slice(0, 10).split('-').reverse().join('/') : '—'; },
    tamano(b) { return b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`; },
    /** Sugiere tipo, fecha y sensor a partir del nombre (p. ej. "NDVI_S2_2026-10-15.tif", "dji_ndvi_20261020.kmz"). */
    elegir(file) {
      this.errors = {}; this.detectado = ''; this.recien = null;
      if (!file) return;
      const ext = (file.name.split('.').pop() || '').toLowerCase();
      if (!ACEPTA.includes('.' + ext)) { this.errors = { archivo: ['Formato no admitido. Usa GeoTIFF (.tif), KMZ de dron, PNG, JPG o WEBP.'] }; return; }
      this.form.archivo = file;
      const n = file.name.toLowerCase(); const pistas = [];
      if (/ndvi|indice|index/.test(n)) this.form.tipo = 'NDVI';
      else if (/multi|rededge|sequoia|band/.test(n)) this.form.tipo = 'Multiespectral';
      else if (/therm|termic|thermal/.test(n)) this.form.tipo = 'Termica';
      else if (/rgb|orto|ortho|color/.test(n)) this.form.tipo = 'RGB';
      pistas.push(`tipo ${this.form.tipo}`);
      const m = n.match(/(20\d{2})[-_.]?(0[1-9]|1[0-2])[-_.]?(0[1-9]|[12]\d|3[01])/);
      if (m) { this.form.fecha = `${m[1]}-${m[2]}-${m[3]}`; pistas.push(`fecha ${this.fecha(this.form.fecha)}`); }
      if (!this.form.sensor) {
        const s = /sentinel|s2[_-]/.test(n) ? 'Sentinel-2 (Copernicus)' : /landsat/.test(n) ? 'Landsat-8/9' : /rededge|micasense/.test(n) ? 'MicaSense RedEdge (dron)' : /dji|mavic|phantom|dron|drone|uav/.test(n) || ext === 'kmz' ? 'Dron' : '';
        if (s) { this.form.sensor = s; pistas.push(`fuente ${s}`); }
      }
      this.detectado = `Detectado del nombre del archivo: ${pistas.join(', ')}. Revísalo antes de subir.`;
    },
    subir() {
      if (!this.listo) return;
      this.subiendo = true; this.avance = 0; this.errors = {};
      const fd = new FormData();
      ['ensayo_id', 'tipo', 'fecha', 'sensor', 'resolucion', 'bandas', 'notas'].forEach((k) => fd.append(k, this.form[k] || ''));
      fd.append('archivo', this.form.archivo);
      window.axios.post('/soporte/imagenes-geo', fd, { onUploadProgress: (e) => { this.avance = e.total ? Math.round((e.loaded / e.total) * 100) : 0; } })
        .then(({ data }) => {
          this.items.unshift(data);
          this.recien = data;
          this.form = this.formVacio(this.form.ensayo_id);
          this.detectado = '';
          if (this.$refs.fileInput) this.$refs.fileInput.value = '';
        })
        .catch((err) => {
          if (err.response && err.response.status === 422) this.errors = err.response.data.errors || {};
          else if (err.response && err.response.status === 413) this.errors = { archivo: ['El servidor rechazó el archivo por su tamaño. Súbelo directo en la pestaña NDVI del ensayo.'] };
          else this.$swal.fire({ icon: 'error', title: 'No se pudo subir la imagen' });
        })
        .finally(() => { this.subiendo = false; });
    },
    eliminar(img) {
      this.$swal.fire({
        title: `¿Eliminar "${img.nombre}"?`,
        text: img.ndvi ? 'El NDVI por parcela ya calculado se conserva en el ensayo.' : 'El archivo se borra definitivamente.',
        icon: 'warning', showCancelButton: true, confirmButtonText: 'Eliminar', confirmButtonColor: '#FF6B00',
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/soporte/imagenes-geo/${img.id}`)
          .then(() => { this.items = this.items.filter((i) => i.id !== img.id); if (this.recien && this.recien.id === img.id) this.recien = null; })
          .catch(() => this.$swal.fire({ icon: 'error', title: 'No se pudo eliminar' }));
      });
    },
  },
};
</script>

<style scoped>
.ig{display:flex;flex-direction:column;gap:16px;color:#29382f}
.ig-guia{padding:18px 20px;background:#f3f8ec;border:1px solid #d6e7c2;border-radius:12px}
.ig-guia header{display:flex;align-items:center;gap:12px}
.ig-guia header>i{display:grid;place-items:center;flex:none;width:42px;height:42px;border-radius:10px;background:#73bc25;color:#fff;font-size:18px}
.ig-guia header div{flex:1}.ig-guia header b{display:block;font-size:16px}.ig-guia header small{font-size:12px;color:#61705f}
.ig-flujo{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:16px 0;padding:0;list-style:none}
.ig-flujo li{display:flex;gap:10px;padding:12px;background:#fff;border:1px solid #dcebc8;border-radius:10px}
.ig-flujo li>span{display:grid;place-items:center;flex:none;width:28px;height:28px;border-radius:50%;background:#73bc25;color:#fff;font-weight:700}
.ig-flujo b{display:block;font-size:13px}.ig-flujo small{font-size:12px;color:#61705f;line-height:1.5}
.ig-tabla-wrap{overflow-x:auto;background:#fff;border:1px solid #dcebc8;border-radius:10px}
.ig-tabla{width:100%;border-collapse:collapse;font-size:12px}
.ig-tabla th{padding:8px 12px;background:#e9f3dc;text-align:left;font-size:11px;text-transform:uppercase;color:#4d5a48}
.ig-tabla td{padding:8px 12px;border-top:1px solid #eef3e8;vertical-align:top}
.ig-tabla td small{display:block;color:#69766e}.ig-tabla code{font-size:11px;color:#3f5a2f;background:#f3f6ef;padding:1px 6px;border-radius:4px}
.ig-tabla td.is-ndvi{color:#3f7a12;font-weight:600}.ig-tabla td i{margin-right:4px}
.ig-tipo{display:inline-block;padding:1px 9px;border-radius:9px;background:color-mix(in srgb,var(--c) 14%,#fff);color:var(--c);font-size:11px;font-weight:700}
.ig-req{display:flex;gap:10px;margin:14px 0 0;padding:10px 12px;background:#fffbe6;border:1px solid #f3df8c;border-radius:8px;font-size:12px;color:#5c4a00}.ig-req i{margin-top:2px;color:#b38600}
.ig-card{padding:20px;background:#fff;border:1px solid #e1e7dc;border-radius:12px}
.ig-card h3{font-size:16px;font-weight:700;margin:0 0 14px}.ig-card h3 i{color:#73bc25;margin-right:6px}
.ig-form{display:grid;grid-template-columns:1fr 1fr;gap:24px}
.ig-col label{display:block;margin:0 0 10px;font-size:12px;font-weight:700}.ig-col label small{font-weight:400;color:#8a948c}
.ig-col .form-control{margin-top:4px}.ig-mt{margin-top:6px!important}
.ig-estado-ens{display:flex;gap:8px;margin:-4px 0 12px;padding:8px 10px;border-radius:8px;font-size:12px}
.ig-estado-ens.is-ok{background:#f0f8e6;color:#3f7a12}.ig-estado-ens.is-aviso{background:#fff7e6;color:#7a4a00}.ig-estado-ens i{margin-top:2px}
.ig-drop{display:flex!important;flex-direction:column;align-items:center;gap:4px;padding:26px 14px;border:2px dashed #b3c4aa;border-radius:12px;background:#fafcf8;text-align:center;cursor:pointer;font-weight:400!important}
.ig-drop input{display:none}.ig-drop i{font-size:28px;color:#73bc25}.ig-drop b{font-size:14px}.ig-drop small{color:#69766e;font-size:12px}
.ig-drop.is-arrastre{border-color:#73bc25;background:#eef7e3}.ig-drop.is-lleno{border-style:solid;border-color:#a3d300;background:#f7fbf1}.ig-drop.is-invalid{border-color:#ff6b00}
.ig-error{margin:4px 0 8px;font-size:12px;color:#b84d00}
.ig-detectado{margin:4px 0 0;font-size:12px;color:#527443}
.ig-tipos{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:4px 0 12px}
.ig-tipos button{display:flex;flex-direction:column;align-items:flex-start;gap:2px;padding:9px 11px;border:1px solid #dfe5e0;border-left:4px solid var(--c);border-radius:8px;background:#fff;text-align:left;color:#29382f}
.ig-tipos button b{font-size:13px}.ig-tipos button small{font-size:11px;color:#69766e;line-height:1.35}
.ig-tipos button.is-on{background:color-mix(in srgb,var(--c) 9%,#fff);box-shadow:inset 0 0 0 1px var(--c)}
.ig-fila{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.ig-acciones{display:flex;align-items:center;justify-content:flex-end;gap:12px;margin-top:8px;padding-top:12px;border-top:1px solid #eef2eb}
.ig-acciones span{margin-right:auto;font-size:12px;color:#527443}
.ig-siguiente{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin-top:12px;padding:12px 14px;background:#f0f8e6;border:1px solid #cfe3b8;border-radius:10px;font-size:13px}
.ig-siguiente>i{color:#73bc25;font-size:18px}.ig-siguiente span{flex:1;min-width:220px}
.ig-toolbar{display:flex;align-items:center;flex-wrap:wrap;gap:10px}
.ig-toolbar h3{margin:0 auto 0 0;font-size:16px;font-weight:700}.ig-toolbar h3 small{font-size:12px;font-weight:400;color:#69766e}
.ig-toolbar .form-control{width:auto;min-width:170px}
.ig-seg{display:flex;border:1px solid #cfdcc6;border-radius:8px;overflow:hidden}
.ig-seg button{border:0;background:#fff;padding:7px 12px;font-size:12px;color:#4d5a48}.ig-seg button+button{border-left:1px solid #cfdcc6}
.ig-seg button em{font-style:normal;font-size:10px;opacity:.7}.ig-seg button.is-on{background:#73bc25;color:#fff;font-weight:600}
.ig-vacio{display:flex;flex-direction:column;align-items:center;gap:6px;padding:40px;background:#fff;border:1px dashed #ced2d5;border-radius:12px;color:#69766e}.ig-vacio i{font-size:30px;color:#ced2d5}
.ig-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px}
.ig-img{display:flex;flex-direction:column;background:#fff;border:1px solid #e1e7dc;border-radius:12px;overflow:hidden}
.ig-thumb{position:relative;display:grid;place-items:center;height:140px;background:linear-gradient(135deg,#eef4e8,#f8faf6);overflow:hidden}
.ig-thumb img{width:100%;height:100%;object-fit:cover}
.ig-thumb-ico{display:flex;flex-direction:column;align-items:center;gap:4px;color:#73bc25}.ig-thumb-ico i{font-size:38px}.ig-thumb-ico small{font-size:11px;font-weight:700;color:#69766e}
.ig-tipo.is-flota{position:absolute;top:8px;left:8px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.12)}
.ig-info{display:flex;flex-direction:column;gap:2px;padding:10px 12px 6px;min-width:0}
.ig-info b{font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ig-info small{font-size:11px;color:#4d5a48}.ig-info .ig-gris{color:#8a948c}
.ig-estado{margin:4px 12px;padding:5px 9px;border-radius:7px;font-size:11px;font-weight:600}
.ig-estado.is-procesada{background:#e3f2d1;color:#3f7a12}.ig-estado.is-pendiente{background:#fff4cc;color:#7a5a00}.ig-estado.is-referencia{background:#eef1ec;color:#69766e}
.ig-img footer{display:flex;align-items:center;gap:4px;margin-top:auto;padding:8px 12px 10px}.ig-img footer .btn{margin-right:auto}
.ig-icon{display:inline-grid;place-items:center;width:30px;height:30px;border:0;border-radius:7px;background:none;color:#69766e;margin-left:auto}
.ig-icon+.ig-icon{margin-left:0}.ig-icon:hover{background:#eef7e3;color:#3f7a12;text-decoration:none}.ig-icon.is-peligro:hover{background:#fff1e8;color:#d24f00}
@media(max-width:900px){.ig-form,.ig-flujo{grid-template-columns:1fr}.ig-fila,.ig-tipos{grid-template-columns:1fr}}
</style>
