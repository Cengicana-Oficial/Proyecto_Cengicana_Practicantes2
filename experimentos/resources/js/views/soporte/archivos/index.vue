<template>
  <div class="ar">
    <!-- Resumen -->
    <div class="ar-stats">
      <div class="ar-stat"><i class="fas fa-paperclip"></i><div><b>{{ items.length }}</b><span>archivos</span></div></div>
      <div class="ar-stat"><i class="fas fa-hdd"></i><div><b>{{ tamano(items.reduce((s, a) => s + a.tamano, 0)) }}</b><span>espacio usado</span></div></div>
      <div class="ar-stat"><icono-cana :tamano="26"></icono-cana><div><b>{{ new Set(items.filter(a => a.ensayo_id).map(a => a.ensayo_id)).size }}</b><span>ensayos con archivos</span></div></div>
      <div class="ar-stat"><i class="fas fa-clock"></i><div><b>{{ items.filter(a => a.fecha && a.fecha >= hace30).length }}</b><span>subidos en 30 días</span></div></div>
      <button v-if="puedeSubir" type="button" class="btn btn-success ar-btn-subir" @click="panelSubir = !panelSubir"><i :class="panelSubir ? 'fas fa-times' : 'fas fa-cloud-upload-alt'"></i> {{ panelSubir ? 'Cerrar' : 'Subir archivos' }}</button>
    </div>

    <!-- Subida: varios archivos a la vez, con avance por archivo -->
    <section v-if="panelSubir" class="ar-subir">
      <div class="ar-subir-campos">
        <label>Proyecto
          <select v-model="destino.proyecto_id" class="form-control" :class="{ 'is-invalid': errDestino }">
            <option :value="null">Selecciona…</option>
            <option v-for="p in proyectos" :key="p.id" :value="p.id">{{ p.codigo }} · {{ p.nombre }}</option>
          </select>
        </label>
        <label>Ensayo
          <select v-model="destino.ensayo_id" class="form-control" :disabled="!destino.proyecto_id">
            <option :value="null">Todo el proyecto</option>
            <option v-for="e in ensayosDe(destino.proyecto_id)" :key="e.id" :value="e.id">{{ e.codigo }}{{ e.nombre ? ' · ' + e.nombre : '' }}</option>
          </select>
        </label>
        <label>Carpeta
          <div class="ar-carpetas-sel">
            <button v-for="c in carpetasTodas" :key="c" type="button" :class="{ 'is-on': destino.carpeta === c }" @click="destino.carpeta = c"><i :class="iconoCarpeta(c)"></i> {{ c }}</button>
          </div>
        </label>
      </div>
      <label class="ar-drop" :class="{ 'is-arrastre': arrastrando }" @dragover.prevent="arrastrando = true" @dragleave.prevent="arrastrando = false" @drop.prevent="soltar">
        <input ref="fileInput" type="file" multiple @change="agregar($event.target.files); $event.target.value = ''">
        <i class="fas fa-cloud-upload-alt"></i>
        <b>Arrastra aquí uno o varios archivos</b>
        <small>o haz clic para buscarlos · máximo 20 MB por archivo · protocolos, croquis, datos, informes, fotos</small>
      </label>
      <p v-if="errDestino" class="ar-error">{{ errDestino }}</p>
      <ul v-if="cola.length" class="ar-cola">
        <li v-for="(c, i) in cola" :key="i" :class="'is-' + c.estado">
          <i :class="iconoTipo(tipoDe(c.file.name))"></i>
          <span class="ar-cola-nom">{{ c.file.name }}<small>{{ tamano(c.file.size) }}<template v-if="c.error"> · {{ c.error }}</template></small></span>
          <span class="ar-barra"><i :style="{ width: c.avance + '%' }"></i></span>
          <span class="ar-cola-est">{{ { pendiente: 'En cola', subiendo: c.avance + ' %', listo: 'Listo', error: 'Error' }[c.estado] }}</span>
          <button v-if="c.estado !== 'subiendo'" type="button" class="ar-icon" title="Quitar" @click="cola.splice(i, 1)"><i class="fas fa-times"></i></button>
        </li>
      </ul>
      <div class="ar-subir-acciones">
        <span>{{ destinoTexto }}</span>
        <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="subiendo || !cola.some(c => c.estado !== 'pendiente')" @click="cola = cola.filter(c => c.estado === 'pendiente')">Limpiar terminados</button>
        <button type="button" class="btn btn-success" :disabled="subiendo || !pendientes" @click="subirTodo"><i :class="subiendo ? 'fas fa-spinner fa-spin' : 'fas fa-upload'"></i> {{ subiendo ? 'Subiendo…' : `Subir ${pendientes} archivo${pendientes === 1 ? '' : 's'}` }}</button>
      </div>
    </section>

    <!-- Filtros -->
    <div class="ar-toolbar">
      <div class="ar-search"><i class="fas fa-search"></i><input v-model="busqueda" type="search" class="form-control" placeholder="Buscar por nombre, ensayo, proyecto o quién lo subió…"></div>
      <select v-model="fProyecto" class="form-control ar-sel" aria-label="Proyecto">
        <option :value="null">Todos los proyectos</option>
        <option v-for="p in proyectos" :key="p.id" :value="p.id">{{ p.codigo }} · {{ p.nombre }}</option>
      </select>
      <select v-model="fEnsayo" class="form-control ar-sel" aria-label="Ensayo">
        <option :value="null">Todos los ensayos</option>
        <option value="sin">Solo del proyecto (sin ensayo)</option>
        <option v-for="e in ensayosDe(fProyecto)" :key="e.id" :value="e.id">{{ e.codigo }}</option>
      </select>
      <select v-model="orden" class="form-control ar-sel ar-sel-corto" aria-label="Ordenar">
        <option value="fecha">Más recientes</option><option value="nombre">Nombre A–Z</option><option value="tamano">Más pesados</option>
      </select>
      <div class="ar-seg" role="group" aria-label="Vista">
        <button type="button" :class="{ 'is-on': vista === 'lista' }" title="Lista" @click="vista = 'lista'"><i class="fas fa-list"></i></button>
        <button type="button" :class="{ 'is-on': vista === 'galeria' }" title="Galería" @click="vista = 'galeria'"><i class="fas fa-th-large"></i></button>
      </div>
    </div>
    <div class="ar-chips">
      <span class="ar-chips-lbl">Carpeta</span>
      <button v-for="c in conteoCarpetas" :key="c.id" type="button" :class="{ 'is-on': fCarpeta === c.id }" @click="fCarpeta = fCarpeta === c.id ? '' : c.id"><i :class="iconoCarpeta(c.id)"></i> {{ c.id }} <em>{{ c.n }}</em></button>
      <span class="ar-chips-lbl">Tipo</span>
      <button v-for="t in conteoTipos" :key="t.id" type="button" :class="{ 'is-on': fTipo === t.id }" @click="fTipo = fTipo === t.id ? '' : t.id"><i :class="iconoTipo(t.id)" :style="{ color: COLOR_TIPO[t.id] }"></i> {{ t.id }} <em>{{ t.n }}</em></button>
      <span class="ar-chips-lbl">Agrupar</span>
      <select v-model="agrupar" class="form-control form-control-sm ar-sel-mini" aria-label="Agrupar por"><option value="ensayo">Por ensayo</option><option value="carpeta">Por carpeta</option><option value="">Sin agrupar</option></select>
      <a v-if="hayFiltros" href="#" class="ar-limpiar" @click.prevent="limpiar">Limpiar filtros</a>
    </div>

    <div v-if="!filtrados.length" class="ar-vacio">
      <i class="fas fa-folder-open"></i>
      <b>{{ items.length ? 'Ningún archivo coincide con los filtros.' : 'Todavía no hay archivos.' }}</b>
      <span v-if="!items.length && puedeSubir">Sube protocolos, croquis, datos o informes con el botón "Subir archivos".</span>
    </div>

    <section v-for="g in grupos" :key="g.clave" class="ar-grupo">
      <h3 v-if="agrupar">
        <icono-cana v-if="g.ensayoId" :tamano="18"></icono-cana><i v-else :class="g.icono"></i> <span>{{ g.titulo }}</span><small v-if="g.sub">{{ g.sub }}</small><em>{{ g.items.length }} · {{ tamano(g.items.reduce((s, a) => s + a.tamano, 0)) }}</em>
        <a v-if="g.ensayoId" :href="`${ensayosUrl}?ensayo=${g.ensayoId}&tab=archivos`" class="ar-ir" title="Abrir el ensayo">Abrir ensayo <i class="fas fa-external-link-alt"></i></a>
      </h3>

      <div v-if="vista === 'lista'" class="ar-lista">
        <div v-for="a in g.items" :key="a.id" class="ar-fila">
          <span class="ar-tipo" :style="{ '--c': COLOR_TIPO[a.tipo] || '#8a948c' }"><i :class="iconoTipo(a.tipo)"></i></span>
          <div class="ar-nom">
            <a href="#" :title="a.nombre" @click.prevent="previsualizar(a)">{{ a.nombre }}</a>
            <small>
              <span class="ar-tag"><i :class="iconoCarpeta(a.carpeta)"></i> {{ a.carpeta }}</span>
              <template v-if="agrupar !== 'ensayo'"> {{ ubicacion(a) }}</template>
            </small>
          </div>
          <span class="ar-meta">{{ tamano(a.tamano) }}</span>
          <span class="ar-meta" :title="a.creado">{{ fecha(a.fecha) }}</span>
          <span class="ar-meta ar-quien"><i class="fas fa-user"></i> {{ a.subido_por || '—' }}</span>
          <span class="ar-acciones">
            <button type="button" class="ar-icon" title="Ver" @click="previsualizar(a)"><i class="fas fa-eye"></i></button>
            <a class="ar-icon" :href="a.url" :download="a.nombre" title="Descargar"><i class="fas fa-download"></i></a>
            <button v-if="a.puede_editar" type="button" class="ar-icon" title="Renombrar o mover" @click="editar(a)"><i class="fas fa-pen"></i></button>
            <button v-if="a.puede_editar" type="button" class="ar-icon is-peligro" title="Eliminar" @click="eliminar(a)"><i class="fas fa-trash"></i></button>
          </span>
        </div>
      </div>

      <div v-else class="ar-galeria">
        <div v-for="a in g.items" :key="a.id" class="ar-card">
          <button type="button" class="ar-card-prev" :title="'Ver ' + a.nombre" @click="previsualizar(a)">
            <img v-if="a.tipo === 'Imagen'" :src="a.url" :alt="a.nombre" loading="lazy">
            <i v-else :class="iconoTipo(a.tipo)" :style="{ color: COLOR_TIPO[a.tipo] || '#8a948c' }"></i>
          </button>
          <div class="ar-card-info">
            <b :title="a.nombre">{{ a.nombre }}</b>
            <small>{{ a.carpeta }} · {{ tamano(a.tamano) }} · {{ fecha(a.fecha) }}</small>
          </div>
          <div class="ar-card-acc">
            <a class="ar-icon" :href="a.url" :download="a.nombre" title="Descargar"><i class="fas fa-download"></i></a>
            <button v-if="a.puede_editar" type="button" class="ar-icon" title="Renombrar o mover" @click="editar(a)"><i class="fas fa-pen"></i></button>
            <button v-if="a.puede_editar" type="button" class="ar-icon is-peligro" title="Eliminar" @click="eliminar(a)"><i class="fas fa-trash"></i></button>
          </div>
        </div>
      </div>
    </section>

    <!-- Vista previa -->
    <div v-if="vistaPrevia" class="ar-modal" role="dialog" aria-modal="true" @click.self="vistaPrevia = null" @keydown.esc="vistaPrevia = null">
      <div class="ar-modal-caja is-grande">
        <header><i :class="iconoTipo(vistaPrevia.tipo)"></i><b>{{ vistaPrevia.nombre }}</b>
          <a class="btn btn-sm btn-outline-success" :href="vistaPrevia.url" :download="vistaPrevia.nombre"><i class="fas fa-download"></i> Descargar</a>
          <a class="btn btn-sm btn-outline-secondary" :href="vistaPrevia.url" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i></a>
          <button type="button" class="ar-icon" title="Cerrar" @click="vistaPrevia = null"><i class="fas fa-times"></i></button>
        </header>
        <div class="ar-prev">
          <img v-if="vistaPrevia.tipo === 'Imagen'" :src="vistaPrevia.url" :alt="vistaPrevia.nombre">
          <iframe v-else-if="vistaPrevia.tipo === 'PDF'" :src="vistaPrevia.url" :title="vistaPrevia.nombre"></iframe>
          <div v-else class="ar-prev-no"><i :class="iconoTipo(vistaPrevia.tipo)" :style="{ color: COLOR_TIPO[vistaPrevia.tipo] }"></i><b>Este tipo de archivo no se puede ver en el navegador.</b><span>Descárgalo para abrirlo con {{ { Excel: 'Excel', Word: 'Word', ZIP: 'un descompresor' }[vistaPrevia.tipo] || 'su programa' }}.</span></div>
        </div>
        <footer>{{ ubicacion(vistaPrevia) }} · {{ vistaPrevia.carpeta }} · {{ tamano(vistaPrevia.tamano) }} · {{ fecha(vistaPrevia.fecha) }}{{ vistaPrevia.subido_por ? ' · ' + vistaPrevia.subido_por : '' }}</footer>
      </div>
    </div>

    <!-- Renombrar o mover -->
    <div v-if="edicion" class="ar-modal" role="dialog" aria-modal="true" @click.self="edicion = null">
      <form class="ar-modal-caja" @submit.prevent="guardarEdicion">
        <header><i class="fas fa-pen"></i><b>Renombrar o mover</b><button type="button" class="ar-icon" title="Cerrar" @click="edicion = null"><i class="fas fa-times"></i></button></header>
        <div class="ar-form">
          <label>Nombre<input v-model.trim="edicion.nombre" class="form-control" maxlength="255" required></label>
          <label>Proyecto
            <select v-model="edicion.proyecto_id" class="form-control" @change="edicion.ensayo_id = null"><option v-for="p in proyectos" :key="p.id" :value="p.id">{{ p.codigo }} · {{ p.nombre }}</option></select>
          </label>
          <label>Ensayo
            <select v-model="edicion.ensayo_id" class="form-control"><option :value="null">Todo el proyecto</option><option v-for="e in ensayosDe(edicion.proyecto_id)" :key="e.id" :value="e.id">{{ e.codigo }}</option></select>
          </label>
          <label>Carpeta
            <select v-model="edicion.carpeta" class="form-control"><option v-for="c in carpetasTodas" :key="c" :value="c">{{ c }}</option></select>
          </label>
          <p v-if="edicion.error" class="ar-error">{{ edicion.error }}</p>
        </div>
        <footer class="ar-modal-pie"><button type="button" class="btn btn-outline-secondary" @click="edicion = null">Cancelar</button><button type="submit" class="btn btn-success" :disabled="edicion.guardando"><i class="fas fa-save"></i> Guardar</button></footer>
      </form>
    </div>
  </div>
</template>

<script>
const COLOR_TIPO = { PDF: '#d64545', Imagen: '#2a78d6', Excel: '#1d7a45', Word: '#2b5797', ZIP: '#8a6a00', Otro: '#69766e' };
const ICONO_TIPO = { PDF: 'fas fa-file-pdf', Imagen: 'fas fa-file-image', Excel: 'fas fa-file-excel', Word: 'fas fa-file-word', ZIP: 'fas fa-file-archive', Otro: 'fas fa-file' };
const ICONO_CARPETA = { Protocolo: 'fas fa-file-signature', Croquis: 'fas fa-map', Datos: 'fas fa-table', Informes: 'fas fa-file-alt', Otro: 'fas fa-folder' };

function leer() { try { return JSON.parse(localStorage.getItem('experimentos.archivos')) || {}; } catch (e) { return {}; } }

export default {
  name: 'ArchivosIndex',
  props: {
    archivos: { type: Array, default: () => [] },
    proyectos: { type: Array, default: () => [] },
    ensayos: { type: Array, default: () => [] },
    carpetas: { type: Array, default: () => ['Protocolo', 'Croquis', 'Datos', 'Informes', 'Otro'] },
    ensayosUrl: { type: String, default: '' },
    puedeSubir: { type: Boolean, default: false },
    esAdmin: { type: Boolean, default: false },
  },
  data() {
    const m = leer();
    const qs = new URLSearchParams(window.location.search);
    return {
      COLOR_TIPO,
      items: this.archivos.slice(),
      busqueda: '', fProyecto: Number(qs.get('proyecto')) || null, fEnsayo: Number(qs.get('ensayo')) || null, fCarpeta: '', fTipo: '',
      orden: 'fecha', vista: m.vista === 'galeria' ? 'galeria' : 'lista', agrupar: m.agrupar !== undefined ? m.agrupar : 'ensayo',
      panelSubir: false, arrastrando: false, subiendo: false, errDestino: '',
      destino: { proyecto_id: null, ensayo_id: null, carpeta: 'Protocolo' },
      cola: [], vistaPrevia: null, edicion: null,
      hace30: new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10),
    };
  },
  computed: {
    proyMap() { return new Map(this.proyectos.map((p) => [p.id, p])); },
    ensMap() { return new Map(this.ensayos.map((e) => [e.id, e])); },
    carpetasTodas() { return [...new Set([...this.carpetas, ...this.items.map((a) => a.carpeta)])]; },
    base() {
      const q = this.normalizar(this.busqueda.trim());
      return this.items.filter((a) => (!this.fProyecto || a.proyecto_id === this.fProyecto)
        && (!this.fEnsayo || (this.fEnsayo === 'sin' ? !a.ensayo_id : a.ensayo_id === this.fEnsayo))
        && (!q || this.normalizar([a.nombre, a.carpeta, a.subido_por, this.ubicacion(a)].join(' ')).includes(q)));
    },
    filtrados() {
      const lista = this.base.filter((a) => (!this.fCarpeta || a.carpeta === this.fCarpeta) && (!this.fTipo || a.tipo === this.fTipo));
      const cmp = { fecha: (x, y) => String(y.fecha).localeCompare(String(x.fecha)) || y.id - x.id, nombre: (x, y) => x.nombre.localeCompare(y.nombre, 'es', { numeric: true }), tamano: (x, y) => y.tamano - x.tamano }[this.orden];
      return lista.sort(cmp);
    },
    // Conteos sobre la busqueda actual, sin el propio filtro, para ver cuantos hay de cada uno.
    conteoCarpetas() { return this.contar(this.base.filter((a) => !this.fTipo || a.tipo === this.fTipo), 'carpeta', this.carpetasTodas); },
    conteoTipos() { return this.contar(this.base.filter((a) => !this.fCarpeta || a.carpeta === this.fCarpeta), 'tipo', Object.keys(ICONO_TIPO)); },
    grupos() {
      if (!this.agrupar) return [{ clave: 'todos', items: this.filtrados }];
      const mapa = new Map();
      this.filtrados.forEach((a) => {
        let g;
        if (this.agrupar === 'carpeta') g = { clave: a.carpeta, titulo: a.carpeta, icono: ICONO_CARPETA[a.carpeta] || 'fas fa-folder' };
        else {
          const e = this.ensMap.get(a.ensayo_id); const p = this.proyMap.get(a.proyecto_id);
          g = e ? { clave: 'e' + e.id, titulo: e.codigo, sub: e.nombre, icono: 'fas fa-seedling', ensayoId: e.id }
            : { clave: 'p' + a.proyecto_id, titulo: p ? `${p.codigo} · documentos del proyecto` : 'Sin proyecto', sub: p && p.nombre, icono: 'fas fa-folder-open' };
        }
        if (!mapa.has(g.clave)) mapa.set(g.clave, { ...g, items: [] });
        mapa.get(g.clave).items.push(a);
      });
      return [...mapa.values()].sort((x, y) => x.titulo.localeCompare(y.titulo, 'es', { numeric: true }));
    },
    hayFiltros() { return !!(this.busqueda || this.fProyecto || this.fEnsayo || this.fCarpeta || this.fTipo); },
    pendientes() { return this.cola.filter((c) => c.estado === 'pendiente').length; },
    destinoTexto() {
      const p = this.proyMap.get(this.destino.proyecto_id); const e = this.ensMap.get(this.destino.ensayo_id);
      return p ? `Destino: ${e ? e.codigo : p.codigo + ' (todo el proyecto)'} › ${this.destino.carpeta}` : 'Elige el proyecto de destino.';
    },
  },
  watch: {
    vista() { this.recordar(); }, agrupar() { this.recordar(); },
    fProyecto() { if (this.fEnsayo && this.fEnsayo !== 'sin' && !this.ensayosDe(this.fProyecto).some((e) => e.id === this.fEnsayo)) this.fEnsayo = null; },
    'destino.proyecto_id'() { this.destino.ensayo_id = null; this.errDestino = ''; },
  },
  mounted() { document.addEventListener('keydown', this.tecla); },
  beforeDestroy() { document.removeEventListener('keydown', this.tecla); },
  methods: {
    recordar() { try { localStorage.setItem('experimentos.archivos', JSON.stringify({ vista: this.vista, agrupar: this.agrupar })); } catch (e) { /* sin almacenamiento */ } },
    tecla(e) { if (e.key === 'Escape') { this.vistaPrevia = null; this.edicion = null; } },
    normalizar(s) { return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); },
    contar(lista, campo, orden) {
      const n = {}; lista.forEach((a) => { n[a[campo]] = (n[a[campo]] || 0) + 1; });
      return orden.filter((id) => n[id]).map((id) => ({ id, n: n[id] }));
    },
    ensayosDe(proyectoId) { return proyectoId ? this.ensayos.filter((e) => e.proyecto_id === proyectoId) : this.ensayos; },
    ubicacion(a) {
      const e = this.ensMap.get(a.ensayo_id); const p = this.proyMap.get(a.proyecto_id);
      return e ? `${e.codigo}` : p ? `${p.codigo} (proyecto)` : '';
    },
    tipoDe(nombre) {
      const ext = (nombre.split('.').pop() || '').toLowerCase();
      if (ext === 'pdf') return 'PDF';
      if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) return 'Imagen';
      if (['xls', 'xlsx', 'csv'].includes(ext)) return 'Excel';
      if (['doc', 'docx'].includes(ext)) return 'Word';
      if (['zip', 'rar', '7z'].includes(ext)) return 'ZIP';
      return 'Otro';
    },
    iconoTipo(t) { return ICONO_TIPO[t] || ICONO_TIPO.Otro; },
    iconoCarpeta(c) { return ICONO_CARPETA[c] || 'fas fa-folder'; },
    tamano(b) { if (!b) return '0 KB'; const kb = b / 1024; return kb < 1024 ? `${Math.max(1, Math.round(kb))} KB` : kb < 1048576 ? `${(kb / 1024).toFixed(1)} MB` : `${(kb / 1048576).toFixed(2)} GB`; },
    fecha(f) { return f ? String(f).slice(0, 10).split('-').reverse().join('/') : '—'; },
    limpiar() { this.busqueda = ''; this.fProyecto = null; this.fEnsayo = null; this.fCarpeta = ''; this.fTipo = ''; },
    agregar(files) {
      [...files].forEach((file) => this.cola.push({ file, estado: file.size > 20 * 1048576 ? 'error' : 'pendiente', avance: 0, error: file.size > 20 * 1048576 ? 'supera 20 MB' : '' }));
    },
    soltar(e) { this.arrastrando = false; this.agregar(e.dataTransfer.files); },
    async subirTodo() {
      if (!this.destino.proyecto_id) { this.errDestino = 'Elige el proyecto (y si aplica, el ensayo) antes de subir.'; return; }
      this.subiendo = true;
      for (const c of this.cola.filter((x) => x.estado === 'pendiente')) {
        c.estado = 'subiendo';
        const fd = new FormData();
        fd.append('proyecto_id', this.destino.proyecto_id);
        if (this.destino.ensayo_id) fd.append('ensayo_id', this.destino.ensayo_id);
        fd.append('carpeta', this.destino.carpeta); fd.append('archivo', c.file); fd.append('fila', '1');
        try {
          const { data } = await window.axios.post('/soporte/archivos', fd, { onUploadProgress: (ev) => { c.avance = ev.total ? Math.round((ev.loaded / ev.total) * 100) : 50; } });
          this.items.unshift(data); c.estado = 'listo'; c.avance = 100;
        } catch (err) {
          c.estado = 'error';
          const d = err.response && err.response.data;
          c.error = d && d.errors ? Object.values(d.errors).flat()[0] : err.response && err.response.status === 413 ? 'archivo demasiado grande' : 'no se pudo subir';
        }
      }
      this.subiendo = false;
      const listos = this.cola.filter((x) => x.estado === 'listo').length;
      if (listos && !this.cola.some((x) => x.estado === 'error')) this.$swal.fire({ icon: 'success', title: `${listos} archivo${listos === 1 ? '' : 's'} subido${listos === 1 ? '' : 's'}`, timer: 1400, showConfirmButton: false });
    },
    previsualizar(a) { this.vistaPrevia = a; },
    editar(a) { this.edicion = { id: a.id, nombre: a.nombre, proyecto_id: a.proyecto_id, ensayo_id: a.ensayo_id, carpeta: a.carpeta, error: '', guardando: false }; },
    async guardarEdicion() {
      const ed = this.edicion; ed.guardando = true; ed.error = '';
      try {
        const { data } = await window.axios.put(`/soporte/archivos/${ed.id}`, { nombre: ed.nombre, proyecto_id: ed.proyecto_id, ensayo_id: ed.ensayo_id, carpeta: ed.carpeta });
        this.items.splice(this.items.findIndex((a) => a.id === ed.id), 1, data);
        this.edicion = null;
      } catch (err) {
        const d = err.response && err.response.data;
        ed.error = d && d.errors ? Object.values(d.errors).flat().join(' ') : 'No se pudo guardar el cambio.';
        ed.guardando = false;
      }
    },
    eliminar(a) {
      this.$swal.fire({ title: `¿Eliminar "${a.nombre}"?`, text: 'El archivo se borra definitivamente.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Eliminar', confirmButtonColor: '#FF6B00' }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/soporte/archivos/${a.id}`).then(() => {
          this.items = this.items.filter((i) => i.id !== a.id);
          if (this.vistaPrevia && this.vistaPrevia.id === a.id) this.vistaPrevia = null;
        }).catch(() => this.$swal.fire({ icon: 'error', title: 'No se pudo eliminar' }));
      });
    },
  },
};
</script>

<style scoped>
.ar{display:flex;flex-direction:column;gap:14px;color:#29382f}
.ar-stats{display:flex;flex-wrap:wrap;align-items:center;gap:12px}
.ar-stat{display:flex;align-items:center;gap:12px;flex:1 1 170px;padding:14px 16px;background:#fff;border:1px solid #e1e7dc;border-radius:12px}
.ar-stat>i{display:grid;place-items:center;width:38px;height:38px;border-radius:10px;background:#eef7e3;color:#73bc25;font-size:16px}
.ar-stat b{display:block;font-size:20px;line-height:1.1}.ar-stat span{font-size:12px;color:#69766e}
.ar-btn-subir{flex:none;height:48px;padding:0 20px;font-weight:600}
.ar-subir{padding:18px;background:#fff;border:1px solid #cfe3b8;border-radius:12px;box-shadow:0 4px 14px rgba(115,188,37,.08)}
.ar-subir-campos{display:grid;grid-template-columns:1fr 1fr 1.6fr;gap:14px;margin-bottom:14px}
.ar-subir-campos label{display:block;margin:0;font-size:12px;font-weight:700}
.ar-subir-campos .form-control{margin-top:4px}
.ar-carpetas-sel{display:flex;flex-wrap:wrap;gap:6px;margin-top:4px}
.ar-carpetas-sel button{border:1px solid #d6dfd0;background:#fff;border-radius:8px;padding:6px 10px;font-size:12px;color:#4d5a48}
.ar-carpetas-sel button.is-on{border-color:#73bc25;background:#73bc25;color:#fff;font-weight:600}
.ar-drop{display:flex;flex-direction:column;align-items:center;gap:4px;margin:0;padding:26px;border:2px dashed #b3c4aa;border-radius:12px;background:#fafcf8;text-align:center;cursor:pointer}
.ar-drop input{display:none}.ar-drop i{font-size:28px;color:#73bc25}.ar-drop b{font-size:14px}.ar-drop small{font-weight:400;color:#69766e;font-size:12px}
.ar-drop.is-arrastre{border-color:#73bc25;background:#eef7e3}
.ar-error{margin:8px 0 0;color:#b84d00;font-size:12px}
.ar-cola{list-style:none;margin:12px 0 0;padding:0;display:flex;flex-direction:column;gap:6px}
.ar-cola li{display:flex;align-items:center;gap:10px;padding:8px 10px;border:1px solid #eef2eb;border-radius:8px;font-size:12px}
.ar-cola li.is-error{border-color:#ffd2b8;background:#fff6f0}.ar-cola li.is-listo{background:#f7fbf1}
.ar-cola-nom{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ar-cola-nom small{display:block;color:#69766e}
.ar-barra{flex:0 0 120px;height:6px;border-radius:3px;background:#eef1ec;overflow:hidden}.ar-barra i{display:block;height:100%;background:#73bc25;transition:width .2s}
.is-error .ar-barra i{background:#ff6b00}
.ar-cola-est{flex:0 0 60px;text-align:right;font-weight:600;color:#527443}.is-error .ar-cola-est{color:#b84d00}
.ar-subir-acciones{display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-top:12px}.ar-subir-acciones span{margin-right:auto;font-size:12px;color:#527443}
.ar-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.ar-search{position:relative;flex:1 1 280px}.ar-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a948c}.ar-search .form-control{padding-left:34px}
.ar-sel{flex:0 1 220px}.ar-sel-corto{flex-basis:150px}
.ar-seg{display:flex;border:1px solid #cfdcc6;border-radius:8px;overflow:hidden}
.ar-seg button{border:0;background:#fff;padding:7px 12px;color:#4d5a48}.ar-seg button+button{border-left:1px solid #cfdcc6}.ar-seg button.is-on{background:#73bc25;color:#fff}
.ar-chips{display:flex;flex-wrap:wrap;align-items:center;gap:6px}
.ar-chips-lbl{margin:0 2px 0 8px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#8a948c}.ar-chips-lbl:first-child{margin-left:0}
.ar-chips button{border:1px solid #d6dfd0;background:#fff;border-radius:999px;padding:3px 10px;font-size:12px;color:#4d5a48}
.ar-chips button em{font-style:normal;font-size:10px;color:#8a948c;margin-left:2px}
.ar-chips button.is-on{border-color:#73bc25;background:#eef7e3;color:#29382f;font-weight:600}
.ar-sel-mini{width:auto;height:30px;font-size:12px}
.ar-limpiar{margin-left:auto;font-size:12px;color:#527443}
.ar-vacio{display:flex;flex-direction:column;align-items:center;gap:6px;padding:44px;background:#fff;border:1px dashed #ced2d5;border-radius:12px;color:#69766e}.ar-vacio i{font-size:30px;color:#ced2d5}
.ar-grupo{background:#fff;border:1px solid #e1e7dc;border-radius:12px;overflow:hidden}
.ar-grupo h3{display:flex;align-items:center;gap:8px;margin:0;padding:10px 16px;background:#f7faf4;border-bottom:1px solid #e1e7dc;font-size:14px;font-weight:700}
.ar-grupo h3>i{color:#73bc25}.ar-grupo h3 small{font-weight:400;color:#69766e;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ar-grupo h3 em{margin-left:auto;flex:none;font-style:normal;font-size:11px;font-weight:600;color:#69766e}
.ar-ir{flex:none;font-size:12px;font-weight:600;color:#3f7a12}
.ar-fila{display:grid;grid-template-columns:36px minmax(0,1fr) 80px 90px 150px auto;align-items:center;gap:12px;padding:8px 16px;border-bottom:1px solid #f0f3ee}
.ar-fila:last-child{border-bottom:0}.ar-fila:hover{background:#fafcf8}
.ar-tipo{display:grid;place-items:center;width:34px;height:34px;border-radius:8px;background:color-mix(in srgb,var(--c) 12%,#fff);color:var(--c);font-size:16px}
.ar-nom{min-width:0}.ar-nom a{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;color:#29382f}
.ar-nom small{font-size:11px;color:#69766e}
.ar-tag{display:inline-flex;align-items:center;gap:4px;padding:0 7px;border-radius:8px;background:#f0f4ec;color:#527443;font-size:10px;font-weight:600}
.ar-meta{font-size:12px;color:#69766e;white-space:nowrap}.ar-quien{overflow:hidden;text-overflow:ellipsis}
.ar-acciones{display:flex;gap:2px}
.ar-icon{display:inline-grid;place-items:center;width:30px;height:30px;border:0;border-radius:7px;background:none;color:#69766e}
.ar-icon:hover{background:#eef7e3;color:#3f7a12;text-decoration:none}.ar-icon.is-peligro:hover{background:#fff1e8;color:#d24f00}
.ar-galeria{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px;padding:14px}
.ar-card{display:flex;flex-direction:column;border:1px solid #e6ebe2;border-radius:10px;overflow:hidden;background:#fff}
.ar-card-prev{display:grid;place-items:center;height:120px;border:0;background:#f4f7f1;overflow:hidden}
.ar-card-prev img{width:100%;height:100%;object-fit:cover}.ar-card-prev i{font-size:40px}
.ar-card-info{padding:8px 10px 0;min-width:0}.ar-card-info b{display:block;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ar-card-info small{font-size:11px;color:#69766e}
.ar-card-acc{display:flex;justify-content:flex-end;padding:2px 6px 6px}
.ar-modal{position:fixed;inset:0;z-index:1050;display:grid;place-items:center;padding:20px;background:rgba(20,30,20,.55)}
.ar-modal-caja{display:flex;flex-direction:column;width:min(520px,100%);max-height:calc(100vh - 40px);background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 20px 50px rgba(0,0,0,.25)}
.ar-modal-caja.is-grande{width:min(1100px,100%);height:calc(100vh - 40px)}
.ar-modal-caja header{display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid #e1e7dc}
.ar-modal-caja header b{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ar-modal-caja header>i{color:#73bc25}
.ar-modal-caja>footer{padding:10px 16px;border-top:1px solid #e1e7dc;font-size:12px;color:#69766e}
.ar-prev{flex:1;display:grid;place-items:center;min-height:0;background:#f4f6f2}
.ar-prev img{max-width:100%;max-height:100%;object-fit:contain}.ar-prev iframe{width:100%;height:100%;border:0}
.ar-prev-no{display:flex;flex-direction:column;align-items:center;gap:8px;color:#69766e}.ar-prev-no i{font-size:54px}
.ar-form{display:flex;flex-direction:column;gap:12px;padding:16px}.ar-form label{margin:0;font-size:12px;font-weight:700}.ar-form .form-control{margin-top:4px}
.ar-modal-pie{display:flex;justify-content:flex-end;gap:8px}
@media(max-width:900px){.ar-subir-campos{grid-template-columns:1fr}.ar-fila{grid-template-columns:36px minmax(0,1fr) auto}.ar-fila .ar-meta{display:none}}
</style>
