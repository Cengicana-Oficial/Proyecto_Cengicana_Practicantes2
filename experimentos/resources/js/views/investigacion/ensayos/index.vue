<template>
  <div class="ens-page">
    <!-- Resumen -->
    <div class="ens-stats">
      <button
        v-for="s in resumen" :key="s.label" type="button"
        class="ens-stat" :class="{ 'is-active': filtroEstado === s.filtro && s.filtro !== null }"
        :style="{ '--acento': s.color }"
        @click="s.filtro !== null && (filtroEstado = filtroEstado === s.filtro ? '' : s.filtro)"
      >
        <div class="ens-stat-icon"><i :class="s.icon"></i></div>
        <div>
          <div class="ens-stat-value">{{ s.valor }}</div>
          <div class="ens-stat-label">{{ s.label }}</div>
        </div>
      </button>
    </div>

    <!-- Barra -->
    <div class="ens-toolbar">
      <div class="ens-search">
        <i class="fas fa-search"></i>
        <input type="search" class="form-control" placeholder="Buscar por codigo, finca o variedad..." v-model="busqueda">
      </div>
      <select class="form-control ens-filter" v-model="filtroProyecto" aria-label="Filtrar por proyecto">
        <option :value="null">Todos los proyectos</option>
        <option v-for="p in proyectos" :key="p.id" :value="p.id">{{ p.codigo }} - {{ p.nombre }}</option>
      </select>
      <select class="form-control ens-filter" v-model="filtroEstado" aria-label="Filtrar por estado">
        <option value="">Todos los estados</option>
        <option v-for="e in estados" :key="e" :value="e">{{ e }}</option>
      </select>
      <button v-if="permisos.crear" class="btn btn-success ens-nuevo" @click="abrirCrear">
        <i class="fas fa-plus"></i> Nuevo ensayo
      </button>
    </div>

    <!-- Tabla -->
    <div class="ens-table-card">
      <div class="ens-table-wrap">
        <table class="table ens-table mb-0">
          <thead>
            <tr>
              <th>Codigo</th>
              <th>Proyecto</th>
              <th>Ingenio</th>
              <th>Finca / Lote</th>
              <th>Variedad</th>
              <th>Diseno</th>
              <th>Estructura</th>
              <th>Estado</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="e in pagina" :key="e.id" class="ens-row" @dblclick="ver(e)">
              <td>
                <a href="#" class="ens-codigo" @click.prevent="ver(e)">{{ e.codigo }}</a>
                <i v-if="e.lat && e.lng" class="fas fa-map-marker-alt ens-gps" title="Con coordenadas GPS"></i>
              </td>
              <td><span class="ens-proy" :title="e.proyecto && e.proyecto.nombre">{{ e.proyecto ? e.proyecto.codigo : '—' }}</span></td>
              <td>{{ e.ingenio ? e.ingenio.nombre : '—' }}</td>
              <td>{{ [e.finca, e.lote].filter(Boolean).join(' / ') || '—' }}</td>
              <td>{{ e.variedad || '—' }}</td>
              <td class="ens-muted">{{ e.diseno || '—' }}</td>
              <td class="ens-muted ens-nowrap">
                <span title="Tratamientos"><i class="fas fa-flask"></i> {{ e.tratamientos_count || 0 }}</span>
                <span title="Parcelas"><i class="fas fa-border-all"></i> {{ e.parcelas_count || 0 }}</span>
              </td>
              <td><span class="ens-estado" :class="claseEstado(e.estado)">{{ e.estado }}</span></td>
              <td class="ens-actions">
                <button type="button" class="ens-icon" title="Ver detalle" @click="ver(e)"><i class="fas fa-eye"></i></button>
                <button v-if="permisos.editar" type="button" class="ens-icon" title="Editar" @click="abrirEditar(e)"><i class="fas fa-pen"></i></button>
                <button v-if="permisos.eliminar" type="button" class="ens-icon ens-icon-danger" title="Eliminar" @click="eliminar(e)"><i class="fas fa-trash"></i></button>
              </td>
            </tr>
            <tr v-if="!filtrados.length">
              <td colspan="9" class="ens-empty">
                <i class="fas fa-seedling"></i>
                <div>{{ items.length ? 'No se encontraron ensayos con esos filtros.' : 'Aun no hay ensayos registrados.' }}</div>
                <button v-if="!items.length && permisos.crear" class="btn btn-success btn-sm mt-2" @click="abrirCrear">
                  <i class="fas fa-plus"></i> Crear el primer ensayo
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="ens-pagination" v-if="filtrados.length">
        <span>
          {{ (paginaActual - 1) * porPagina + 1 }}–{{ Math.min(paginaActual * porPagina, filtrados.length) }}
          de {{ filtrados.length }} ensayo{{ filtrados.length !== 1 ? 's' : '' }}
        </span>
        <div class="ens-pages">
          <select class="form-control form-control-sm" v-model.number="porPagina" aria-label="Filas por pagina">
            <option :value="10">10 / pag.</option>
            <option :value="25">25 / pag.</option>
            <option :value="50">50 / pag.</option>
          </select>
          <button type="button" :disabled="paginaActual === 1" @click="paginaActual--" aria-label="Anterior"><i class="fas fa-chevron-left"></i></button>
          <button
            v-for="n in totalPaginas" :key="n" type="button"
            :class="{ 'is-active': n === paginaActual }" @click="paginaActual = n"
          >{{ n }}</button>
          <button type="button" :disabled="paginaActual === totalPaginas" @click="paginaActual++" aria-label="Siguiente"><i class="fas fa-chevron-right"></i></button>
        </div>
      </div>
    </div>

    <!-- Modal crear / editar -->
    <div class="modal fade" ref="modal" tabindex="-1" role="dialog">
      <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
          <form @submit.prevent="guardar" novalidate class="ens-form">
            <div class="modal-header">
              <h5 class="modal-title"><i class="fas fa-seedling"></i>{{ editando ? 'Editar ensayo' : 'Nuevo ensayo' }}</h5>
              <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
            </div>
            <div class="modal-body">
              <div class="form-row">
                <div class="col-md-5 form-group">
                  <label>Codigo del ensayo <span class="ens-req">*</span></label>
                  <input type="text" maxlength="30" class="form-control" :class="{ 'is-invalid': errors.codigo }" v-model="form.codigo" placeholder="Ej. E-AG001-03">
                  <span class="invalid-feedback" v-if="errors.codigo">{{ errors.codigo[0] }}</span>
                </div>
                <div class="col-md-7 form-group">
                  <label>Proyecto <span class="ens-req">*</span></label>
                  <select class="form-control" :class="{ 'is-invalid': errors.proyecto_id }" v-model="form.proyecto_id">
                    <option :value="null">Seleccione...</option>
                    <option v-for="p in proyectos" :key="p.id" :value="p.id">{{ p.codigo }} — {{ p.nombre }}</option>
                  </select>
                  <span class="invalid-feedback" v-if="errors.proyecto_id">{{ errors.proyecto_id[0] }}</span>
                </div>
              </div>
              <div class="form-row">
                <div class="col-md-4 form-group">
                  <label>Ingenio</label>
                  <select class="form-control" v-model="form.ingenio_id">
                    <option :value="null">Sin asignar</option>
                    <option v-for="i in ingenios" :key="i.id" :value="i.id">{{ i.nombre }}</option>
                  </select>
                </div>
                <div class="col-md-4 form-group">
                  <label>Finca</label>
                  <input type="text" maxlength="150" class="form-control" v-model="form.finca">
                </div>
                <div class="col-md-4 form-group">
                  <label>Lote</label>
                  <input type="text" maxlength="150" class="form-control" v-model="form.lote">
                </div>
              </div>
              <div class="form-row">
                <div class="col-md-4 form-group">
                  <label>Diseno experimental</label>
                  <select class="form-control" v-model="form.diseno">
                    <option :value="null">Sin definir</option>
                    <option v-for="d in opcionesDiseno" :key="d" :value="d">{{ d }}</option>
                  </select>
                </div>
                <div class="col-md-4 form-group">
                  <label>Cultivo</label>
                  <input type="text" maxlength="150" class="form-control" v-model="form.cultivo">
                </div>
                <div class="col-md-4 form-group">
                  <label>Variedad</label>
                  <input type="text" maxlength="150" class="form-control" v-model="form.variedad" placeholder="Ej. CG98-78">
                </div>
              </div>
              <div class="form-row">
                <div class="col-md-6 form-group">
                  <label>Responsable</label>
                  <select class="form-control" v-model="form.responsable_id">
                    <option :value="null">Sin asignar</option>
                    <option v-for="u in responsables" :key="u.id" :value="u.id">{{ u.name }}</option>
                  </select>
                </div>
                <div class="col-md-6 form-group">
                  <label>Estado <span class="ens-req">*</span></label>
                  <select class="form-control" v-model="form.estado">
                    <option v-for="e in estados" :key="e" :value="e">{{ e }}</option>
                  </select>
                </div>
              </div>

              <div class="ens-section">
                <i class="fas fa-map-marker-alt"></i> Coordenadas GPS del lote
                <small>opcional — habilita el mapa y los datos climaticos</small>
              </div>
              <div class="form-row">
                <div class="col-md-5 form-group">
                  <label>Latitud</label>
                  <input type="number" step="0.000001" class="form-control" :class="{ 'is-invalid': errors.lat }" v-model="form.lat" placeholder="Ej. 14.5892">
                  <span class="invalid-feedback" v-if="errors.lat">{{ errors.lat[0] }}</span>
                </div>
                <div class="col-md-5 form-group">
                  <label>Longitud</label>
                  <input type="number" step="0.000001" class="form-control" :class="{ 'is-invalid': errors.lng }" v-model="form.lng" placeholder="Ej. -91.5143">
                  <span class="invalid-feedback" v-if="errors.lng">{{ errors.lng[0] }}</span>
                </div>
                <div class="col-md-2 form-group d-flex align-items-end">
                  <button type="button" class="btn btn-secondary btn-block" title="Usar mi ubicacion actual" @click="usarUbicacion" :disabled="ubicando">
                    <i :class="ubicando ? 'fas fa-spinner fa-spin' : 'fas fa-crosshairs'"></i>
                  </button>
                </div>
              </div>

              <div class="ens-section">
                <i class="fas fa-border-all"></i> Configuracion inicial del diseno
                <small>{{ editando ? 'se edita en la pestana "Estructura" del detalle' : 'crea T1..Tn; editable luego en "Estructura"' }}</small>
              </div>
              <div class="form-row">
                <div class="col-md-4 form-group">
                  <label>Numero de tratamientos</label>
                  <input type="number" min="0" max="50" class="form-control" v-model.number="form.num_tratamientos" :disabled="!!editando">
                </div>
                <div class="col-md-4 form-group">
                  <label>Numero de repeticiones</label>
                  <input type="number" min="0" max="20" class="form-control" v-model.number="form.num_repeticiones" :disabled="!!editando">
                </div>
                <div class="col-md-4 form-group">
                  <label>Area por parcela (m²)</label>
                  <input type="number" step="0.1" min="0" class="form-control" v-model="form.area_parcela" placeholder="50">
                </div>
              </div>
              <p class="ens-hint mb-0" v-if="!editando && form.num_tratamientos > 0 && form.num_repeticiones > 0">
                <i class="fas fa-info-circle"></i>
                Se crearan {{ form.num_tratamientos }} tratamientos; luego podra generar
                {{ form.num_tratamientos * form.num_repeticiones }} parcelas desde "Estructura".
              </p>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-success" :disabled="guardando">
                <i :class="guardando ? 'fas fa-spinner fa-spin' : 'fas fa-save'"></i>
                {{ guardando ? 'Guardando...' : (editando ? 'Guardar cambios' : 'Crear ensayo') }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <ensayo-detalle
      ref="detalle"
      :fuentes-ndvi="fuentesNdvi"
      :carpetas="carpetas"
      :tipos-bitacora="tiposBitacora"
      :storage-url="storageUrl"
      :permisos="permisos"
      @editar="abrirEditar"
      @actualizado="actualizarFila"
    ></ensayo-detalle>
  </div>
</template>

<script>
import EnsayoDetalle from './detalle.vue';

const ESTADOS =['Planificado', 'En campo', 'Finalizado'];

export default {
  name: 'EnsayosIndex',
  components: { EnsayoDetalle },
  props: {
    ensayos: { type: Array, default: () => [] },
    proyectos: { type: Array, default: () => [] },
    ingenios: { type: Array, default: () => [] },
    responsables: { type: Array, default: () => [] },
    disenos: { type: Array, default: () => [] },
    fuentesNdvi: { type: Array, default: () => [] },
    carpetas: { type: Array, default: () => [] },
    tiposBitacora: { type: Array, default: () => [] },
    usuarioId: { type: [Number, String], default: null },
    storageUrl: { type: String, default: '' },
    permisos: { type: Object, default: () => ({}) },
  },
  data() {
    return {
      items: this.ensayos.slice(),
      estados: ESTADOS,
      busqueda: '',
      filtroEstado: '',
      filtroProyecto: Number(new URLSearchParams(window.location.search).get('proyecto')) || null,
      paginaActual: 1,
      porPagina: 10,
      editando: null,
      form: this.formVacio(),
      errors: {},
      guardando: false,
      ubicando: false,
    };
  },
  computed: {
    filtrados() {
      const q = this.normalizar(this.busqueda.trim());
      return this.items.filter((e) => {
        const texto = this.normalizar([e.codigo, e.finca, e.lote, e.variedad].join(' '));
        return (!q || texto.includes(q))
          && (!this.filtroEstado || e.estado === this.filtroEstado)
          && (!this.filtroProyecto || e.proyecto_id === this.filtroProyecto);
      });
    },
    totalPaginas() {
      return Math.max(1, Math.ceil(this.filtrados.length / this.porPagina));
    },
    pagina() {
      const ini = (this.paginaActual - 1) * this.porPagina;
      return this.filtrados.slice(ini, ini + this.porPagina);
    },
    resumen() {
      const n = (estado) => this.items.filter((e) => e.estado === estado).length;
      return [
        { label: 'Ensayos', valor: this.items.length, icon: 'fas fa-seedling', color: '#73BC25', filtro: null },
        { label: 'Planificados', valor: n('Planificado'), icon: 'fas fa-calendar-alt', color: '#FFCC00', filtro: 'Planificado' },
        { label: 'En campo', valor: n('En campo'), icon: 'fas fa-tractor', color: '#A3D300', filtro: 'En campo' },
        { label: 'Finalizados', valor: n('Finalizado'), icon: 'fas fa-flag-checkered', color: '#CED2D5', filtro: 'Finalizado' },
      ];
    },
    opcionesDiseno() {
      // Conserva un diseno heredado que no este en el catalogo.
      const d = this.form.diseno;
      return d && !this.disenos.includes(d) ? [...this.disenos, d] : this.disenos;
    },
  },
  mounted() {
    // Enlace directo: ?ensayo=ID[&tab=estructura|mapa|ndvi|clima|archivos|bitacora]
    const qs = new URLSearchParams(window.location.search);
    const id = Number(qs.get('ensayo'));
    if (id && this.items.some((e) => e.id === id)) {
      this.$refs.detalle.abrir(id);
      if (qs.get('tab')) this.$refs.detalle.cambiarTab(qs.get('tab'));
    }
  },
  watch: {
    busqueda() { this.paginaActual = 1; },
    filtroEstado() { this.paginaActual = 1; },
    filtroProyecto() { this.paginaActual = 1; },
    porPagina() { this.paginaActual = 1; },
    totalPaginas(n) { if (this.paginaActual > n) this.paginaActual = n; },
  },
  methods: {
    formVacio() {
      return {
        codigo: '', proyecto_id: this.filtroProyecto || null, ingenio_id: null, finca: '', lote: '',
        diseno: 'Bloques al azar', cultivo: 'Caña de azúcar', variedad: '',
        responsable_id: this.usuarioId ? Number(this.usuarioId) : null, estado: 'Planificado',
        lat: null, lng: null, num_tratamientos: 3, num_repeticiones: 3, area_parcela: 50,
      };
    },
    normalizar(s) {
      return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    },
    claseEstado(estado) {
      return { Planificado: 'is-plan', 'En campo': 'is-campo', Finalizado: 'is-fin' }[estado] || '';
    },
    ver(e) {
      this.$refs.detalle.abrir(e.id);
    },
    abrirCrear() {
      this.editando = null;
      this.form = this.formVacio();
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    abrirEditar(e) {
      this.editando = e;
      this.form = {
        codigo: e.codigo, proyecto_id: e.proyecto_id, ingenio_id: e.ingenio_id, finca: e.finca || '', lote: e.lote || '',
        diseno: e.diseno || null, cultivo: e.cultivo || '', variedad: e.variedad || '', responsable_id: e.responsable_id,
        estado: e.estado, lat: e.lat, lng: e.lng, num_tratamientos: e.num_tratamientos,
        num_repeticiones: e.num_repeticiones, area_parcela: e.area_parcela,
      };
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    usarUbicacion() {
      if (!navigator.geolocation) {
        this.$swal.fire({ icon: 'info', title: 'No disponible', text: 'Este navegador no permite obtener la ubicacion.' });
        return;
      }
      this.ubicando = true;
      navigator.geolocation.getCurrentPosition(
        (pos) => {
          this.form.lat = pos.coords.latitude.toFixed(6);
          this.form.lng = pos.coords.longitude.toFixed(6);
          this.ubicando = false;
        },
        () => {
          this.ubicando = false;
          this.$swal.fire({ icon: 'info', title: 'Sin ubicacion', text: 'No se pudo obtener la ubicacion (permiso denegado o sin senal).' });
        },
        { enableHighAccuracy: true, timeout: 10000 }
      );
    },
    guardar() {
      const faltan = {};
      if (!String(this.form.codigo || '').trim()) faltan.codigo = ['El codigo del ensayo es obligatorio.'];
      if (!this.form.proyecto_id) faltan.proyecto_id = ['Seleccione el proyecto.'];
      if (Object.keys(faltan).length) { this.errors = faltan; return; }

      this.guardando = true;
      this.errors = {};
      const payload = { ...this.form, codigo: this.form.codigo.trim() };
      ['lat', 'lng', 'area_parcela'].forEach((k) => { if (payload[k] === '') payload[k] = null; });
      const editando = this.editando;
      const peticion = editando
        ? window.axios.put(`/investigacion/ensayos/${editando.id}`, payload)
        : window.axios.post('/investigacion/ensayos', payload);

      peticion
        .then(({ data }) => {
          this.actualizarFila(data);
          window.$(this.$refs.modal).modal('hide');
          this.toast(editando ? 'Ensayo actualizado' : 'Ensayo creado', data.codigo);
          if (this.$refs.detalle.ensayoId === data.id) this.$refs.detalle.cargar();
        })
        .catch((err) => {
          if (err.response && err.response.status === 422) {
            this.errors = err.response.data.errors || {};
          } else {
            const msg = err.response && err.response.data && err.response.data.message;
            this.$swal.fire({ icon: 'error', title: 'No se pudo guardar', text: msg || 'Intente de nuevo.' });
          }
        })
        .finally(() => { this.guardando = false; });
    },
    actualizarFila(data) {
      const idx = this.items.findIndex((i) => i.id === data.id);
      if (idx >= 0) this.$set(this.items, idx, data);
      else this.items.push(data);
      this.items.sort((a, b) => a.codigo.localeCompare(b.codigo, 'es'));
    },
    eliminar(e) {
      const partes = [];
      if (e.tratamientos_count) partes.push(`${e.tratamientos_count} tratamiento(s)`);
      if (e.parcelas_count) partes.push(`${e.parcelas_count} parcela(s) con sus evaluaciones`);
      if (e.archivos_count) partes.push(`${e.archivos_count} archivo(s)`);
      if (e.bitacora_count) partes.push(`${e.bitacora_count} entrada(s) de bitacora`);
      const div = document.createElement('div');
      div.textContent = e.codigo;
      this.$swal.fire({
        title: 'Eliminar ensayo',
        html: `Se eliminara <b>${div.innerHTML}</b>${partes.length ? ' junto con ' + partes.join(', ') : ''}.<br>Esta accion no se puede deshacer.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Si, eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#FF6B00',
        cancelButtonColor: '#CED2D5',
        focusCancel: true,
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/investigacion/ensayos/${e.id}`)
          .then(() => {
            this.items = this.items.filter((i) => i.id !== e.id);
            this.toast('Ensayo eliminado', e.codigo);
          })
          .catch((err) => {
            const msg = err.response && err.response.data && err.response.data.message;
            this.$swal.fire({ icon: 'error', title: 'No se pudo eliminar', text: msg || 'Ocurrio un error.' });
          });
      });
    },
    toast(title, text) {
      this.$swal.fire({ toast: true, position: 'top-end', icon: 'success', title, text, timer: 2200, showConfirmButton: false });
    },
  },
};
</script>

<style scoped>
.ens-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
.ens-stat {
  position: relative;
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px 18px;
  overflow: hidden;
  border: 1px solid var(--cengi-border);
  border-radius: var(--cengi-radius);
  background: var(--cengi-surface);
  box-shadow: var(--cengi-shadow);
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition: border-color .15s ease, transform .15s ease;
}
.ens-stat:first-child { cursor: default; }
.ens-stat::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--acento); }
.ens-stat:not(:first-child):hover { transform: translateY(-2px); }
.ens-stat.is-active { border-color: var(--acento); box-shadow: 0 0 0 2px color-mix(in srgb, var(--acento) 35%, transparent); }
.ens-stat-icon {
  display: grid; place-items: center; flex-shrink: 0;
  width: 42px; height: 42px; border-radius: 11px;
  background: color-mix(in srgb, var(--acento) 18%, #fff);
  color: color-mix(in srgb, var(--acento) 65%, #1f2a1f);
  font-size: 17px;
}
.ens-stat-value { color: var(--cengi-primary-deep); font-family: 'Space Grotesk', sans-serif; font-size: 24px; font-weight: 700; line-height: 1.1; }
.ens-stat-label { color: var(--cengi-muted); font-size: 11.5px; font-weight: 600; }

.ens-toolbar {
  display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
  padding: 12px; margin-bottom: 14px;
  border: 1px solid var(--cengi-border); border-radius: var(--cengi-radius);
  background: var(--cengi-surface); box-shadow: var(--cengi-shadow);
}
.ens-search { position: relative; flex: 1 1 260px; }
.ens-search i { position: absolute; top: 50%; left: 13px; transform: translateY(-50%); color: #8a9b8e; font-size: 12px; pointer-events: none; }
.ens-search .form-control { padding-left: 36px; }
.ens-filter { flex: 0 1 220px; width: auto; }
.ens-nuevo { margin-left: auto; white-space: nowrap; }

.ens-table-card {
  border: 1px solid var(--cengi-border); border-radius: var(--cengi-radius);
  background: var(--cengi-surface); box-shadow: var(--cengi-shadow); overflow: hidden;
}
.ens-table-wrap { overflow-x: auto; }
.ens-table { min-width: 940px; }
.ens-table td { vertical-align: middle; }
.ens-row { cursor: default; }
.ens-codigo { color: var(--cengi-primary-deep); font-weight: 700; }
.ens-codigo:hover { color: #73BC25; }
.ens-gps { margin-left: 6px; color: #FF6B00; font-size: 11px; }
.ens-proy { padding: 3px 8px; border-radius: 6px; background: #f1f4ef; color: var(--cengi-ink); font-size: 11.5px; font-weight: 600; }
.ens-muted { color: var(--cengi-muted); }
.ens-nowrap { white-space: nowrap; }
.ens-nowrap span + span { margin-left: 10px; }
.ens-nowrap i { margin-right: 3px; color: #8a9b8e; font-size: 11px; }
.ens-estado { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.ens-estado.is-plan { background: #fff5cc; color: #7a5d00; }
.ens-estado.is-campo { background: #eef8df; color: #3f7a12; }
.ens-estado.is-fin { background: #eef0f1; color: #5b6267; border: 1px solid #CED2D5; }
.ens-actions { white-space: nowrap; text-align: right; }
.ens-icon {
  display: inline-grid; place-items: center; width: 30px; height: 30px;
  border: 1px solid transparent; border-radius: var(--cengi-radius-sm);
  background: transparent; color: var(--cengi-muted); font-size: 12px; cursor: pointer;
}
.ens-icon:hover, .ens-icon:focus-visible { border-color: var(--cengi-border); background: var(--cengi-accent-soft); color: var(--cengi-primary-deep); outline: none; }
.ens-icon-danger:hover, .ens-icon-danger:focus-visible { border-color: #ffd2b3; background: #fff1e6; color: #FF6B00; }
.ens-empty { padding: 44px 16px !important; color: var(--cengi-muted); text-align: center; }
.ens-empty > i { display: block; margin-bottom: 8px; color: #CED2D5; font-size: 28px; }

.ens-pagination {
  display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px;
  padding: 10px 14px; border-top: 1px solid var(--cengi-border); background: #fbfcf9;
  color: var(--cengi-muted); font-size: 12px;
}
.ens-pages { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; }
.ens-pages select { width: auto; margin-right: 6px; }
.ens-pages button {
  min-width: 30px; height: 30px; padding: 0 8px;
  border: 1px solid var(--cengi-border); border-radius: 7px; background: #fff;
  color: var(--cengi-ink); font-size: 12px; font-weight: 600; cursor: pointer;
}
.ens-pages button.is-active { border-color: #73BC25; background: #73BC25; color: #fff; }
.ens-pages button:disabled { opacity: .4; cursor: default; }

.ens-section {
  display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px;
  margin: 6px 0 12px; padding-top: 14px; border-top: 1px solid var(--cengi-border);
  color: var(--cengi-primary-deep); font-size: 12.5px; font-weight: 700;
}
.ens-section i { color: #73BC25; }
.ens-section small { color: var(--cengi-muted); font-weight: 500; }
.ens-req { color: #FF6B00; }
.ens-hint { padding: 8px 12px; border-radius: 8px; background: #f5faef; color: var(--cengi-muted); font-size: 12px; }
.ens-hint i { margin-right: 4px; color: #73BC25; }

@media (max-width: 1100px) { .ens-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 640px) {
  .ens-stats { gap: 10px; }
  .ens-stat { padding: 12px; gap: 10px; }
  .ens-stat-value { font-size: 20px; }
  .ens-filter, .ens-nuevo { flex: 1 1 100%; margin-left: 0; }
}
</style>
