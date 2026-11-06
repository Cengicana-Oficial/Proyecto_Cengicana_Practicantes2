<template>
  <div class="cat">
    <div class="cat-tabs" role="tablist">
      <button v-for="t in TABS" :key="t.id" type="button" role="tab" :class="{ 'is-on': tab === t.id }" :aria-selected="tab === t.id" @click="cambiarTab(t.id)">
        <i :class="t.icon"></i> {{ t.label }} <small>{{ cuenta(t.id) }}</small>
      </button>
    </div>

    <!-- ============ Disenos experimentales (se administran aqui) ============ -->
    <section v-if="tab === 'disenos'" class="cat-caja">
      <header class="cat-h">
        <div>
          <h5>Disenos experimentales</h5>
          <p>Aparecen en <b>Nuevo ensayo › Diseno experimental</b>. "Analiza con bloques" indica si en el analisis estadistico la repeticion se toma como bloque (ANOVA en bloques) o no (completamente al azar).</p>
        </div>
        <button type="button" class="btn btn-sm btn-success" @click="nuevo"><i class="fas fa-plus"></i> Nuevo diseno</button>
      </header>
      <table class="table cat-tabla">
        <thead><tr><th>Nombre</th><th>Descripcion</th><th class="text-center">Analiza con bloques</th><th class="text-center">Activo</th><th class="text-right">Ensayos</th><th></th></tr></thead>
        <tbody>
          <tr v-if="form && !form.id" class="is-form">
            <td><input ref="nombre" v-model.trim="form.nombre" class="form-control form-control-sm" maxlength="150" placeholder="Ej. Bloques incompletos"></td>
            <td><input v-model.trim="form.descripcion" class="form-control form-control-sm" maxlength="500" placeholder="Cuando se usa (opcional)"></td>
            <td class="text-center"><input type="checkbox" v-model="form.usa_bloques"></td>
            <td class="text-center"><input type="checkbox" v-model="form.activo"></td>
            <td></td>
            <td class="text-right cat-nowrap">
              <button type="button" class="btn btn-sm btn-success" :disabled="guardando || !form.nombre" @click="guardar">Guardar</button>
              <button type="button" class="btn btn-sm btn-link" @click="form = null">Cancelar</button>
            </td>
          </tr>
          <template v-for="d in disenosItems">
            <tr v-if="form && form.id === d.id" :key="'f' + d.id" class="is-form">
              <td><input v-model.trim="form.nombre" class="form-control form-control-sm" maxlength="150"></td>
              <td><input v-model.trim="form.descripcion" class="form-control form-control-sm" maxlength="500"></td>
              <td class="text-center"><input type="checkbox" v-model="form.usa_bloques"></td>
              <td class="text-center"><input type="checkbox" v-model="form.activo"></td>
              <td class="text-right">{{ d.ensayos }}</td>
              <td class="text-right cat-nowrap">
                <button type="button" class="btn btn-sm btn-success" :disabled="guardando || !form.nombre" @click="guardar">Guardar</button>
                <button type="button" class="btn btn-sm btn-link" @click="form = null">Cancelar</button>
              </td>
            </tr>
            <tr v-else :key="d.id" :class="{ 'is-off': !d.activo }">
              <td><b>{{ d.nombre }}</b></td>
              <td class="cat-muted">{{ d.descripcion || '—' }}</td>
              <td class="text-center"><span class="cat-si" :class="{ 'is-no': !d.usa_bloques }">{{ d.usa_bloques ? 'Si' : 'No' }}</span></td>
              <td class="text-center"><span class="cat-si" :class="{ 'is-no': !d.activo }">{{ d.activo ? 'Activo' : 'Inactivo' }}</span></td>
              <td class="text-right">{{ d.ensayos }}</td>
              <td class="text-right cat-nowrap">
                <button type="button" class="cat-icon" title="Editar" @click="editar(d)"><i class="fas fa-pen"></i></button>
                <button type="button" class="cat-icon is-del" :class="{ 'is-locked': d.ensayos }" :title="d.ensayos ? 'Lo usan ensayos: desactivelo en lugar de eliminarlo' : 'Eliminar'" @click="eliminar(d)"><i class="fas fa-trash"></i></button>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
      <p v-if="error" class="cat-error"><i class="fas fa-exclamation-circle"></i> {{ error }}</p>
    </section>

    <!-- ============ Tipos de muestra (se administran aqui) ============ -->
    <section v-else-if="tab === 'tipos'" class="cat-caja">
      <header class="cat-h">
        <div>
          <h5>Tipos de muestra</h5>
          <p>
            Los <b>activos</b> se ofrecen en <b>Muestras › Generar etiquetas</b>. El <b>codigo</b> va en el ID de la etiqueta
            (p. ej. <code>COS-26-01-E01-<b>AG</b>025</code>); "Tipo en Laboratorio" enlaza con el catalogo del modulo Laboratorio
            y "Formato de informe" indica como se leen sus resultados. Los analitos y campos de cada tipo se configuran en Generar etiquetas.
          </p>
        </div>
        <button type="button" class="btn btn-sm btn-success" @click="nuevoTipo"><i class="fas fa-plus"></i> Nuevo tipo</button>
      </header>
      <table class="table cat-tabla">
        <thead><tr><th>Nombre</th><th>Codigo</th><th>Tipo en Laboratorio</th><th>Formato de informe</th><th class="text-center">Activo</th><th class="text-right">Muestras</th><th class="text-right">Analitos</th><th></th></tr></thead>
        <tbody>
          <template v-for="t in filasTipos">
            <tr v-if="formTipo && formTipo.id === t.id" :key="'f' + (t.id || 'nuevo')" class="is-form">
              <td><input ref="tipoNombre" v-model.trim="formTipo.nombre" class="form-control form-control-sm" maxlength="60" placeholder="Ej. Foliares"></td>
              <td>
                <input v-model.trim="formTipo.codigo" class="form-control form-control-sm cat-cod" maxlength="3" placeholder="FO" @input="formTipo.codigo = formTipo.codigo.toUpperCase().replace(/[^A-Z]/g, '')">
                <small v-if="t.id && t.muestras && formTipo.codigo !== t.codigo" class="cat-aviso">Las {{ t.muestras }} muestras existentes conservan su ID; las nuevas usaran {{ formTipo.codigo }}.</small>
              </td>
              <td>
                <select v-model="formTipo.tipo_lab" class="form-control form-control-sm">
                  <option :value="null">(sin equivalente)</option>
                  <option v-for="x in tiposLabOpciones" :key="x" :value="x">{{ x }}</option>
                </select>
              </td>
              <td>
                <select v-model="formTipo.formato_informe" class="form-control form-control-sm">
                  <option :value="null">(generico)</option>
                  <option v-for="x in formatosInforme" :key="x" :value="x">{{ x }}</option>
                </select>
              </td>
              <td class="text-center"><input type="checkbox" v-model="formTipo.activo"></td>
              <td class="text-right">{{ t.muestras || 0 }}</td>
              <td class="text-right">{{ t.analitos || 0 }}</td>
              <td class="text-right cat-nowrap">
                <button type="button" class="btn btn-sm btn-success" :disabled="guardando || !formTipo.nombre || formTipo.codigo.length < 2" @click="guardarTipo">Guardar</button>
                <button type="button" class="btn btn-sm btn-link" @click="formTipo = null">Cancelar</button>
              </td>
            </tr>
            <tr v-else :key="t.id" :class="{ 'is-off': !t.activo }">
              <td><i :class="t.icono || 'fas fa-vial'" class="cat-tipo-ico"></i> <b>{{ t.nombre }}</b></td>
              <td><code class="cat-cod-v">{{ t.codigo }}</code></td>
              <td>{{ t.tipo_lab || '—' }}</td>
              <td>{{ t.formato_informe || 'generico' }}</td>
              <td class="text-center"><span class="cat-si" :class="{ 'is-no': !t.activo }">{{ t.activo ? 'Activo' : 'Inactivo' }}</span></td>
              <td class="text-right">{{ t.muestras }}</td>
              <td class="text-right">{{ t.analitos }}</td>
              <td class="text-right cat-nowrap">
                <button type="button" class="cat-icon" title="Editar" @click="editarTipo(t)"><i class="fas fa-pen"></i></button>
                <button type="button" class="cat-icon is-del" :class="{ 'is-locked': t.muestras }" :title="t.muestras ? 'Tiene muestras: desactivelo en lugar de eliminarlo' : 'Eliminar'" @click="eliminarTipo(t)"><i class="fas fa-trash"></i></button>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
      <p v-if="error" class="cat-error"><i class="fas fa-exclamation-circle"></i> {{ error }}</p>
    </section>

    <!-- ============ Ingenios y responsables (vienen del portal) ============ -->
    <section v-else class="cat-caja">
      <header class="cat-h">
        <div>
          <h5>{{ tab === 'ingenios' ? 'Ingenios' : 'Responsables' }} <span class="cat-origen"><i class="fas fa-link"></i> del portal</span></h5>
          <p v-if="tab === 'ingenios'">
            Es el mismo catalogo de todo el portal: se agregan o editan en
            <a :href="portalIngeniosUrl" target="_blank" rel="noopener">Administracion › Ingenios</a>.
            Experimentos los copia con el mismo identificador.
          </p>
          <p v-else>
            Son las personas que tienen asignado el modulo <b>Experimentos</b> en
            <a :href="portalUsuariosUrl" target="_blank" rel="noopener">Administracion › Usuarios</a> (y los superadministradores).
            Aparecen como responsables aunque todavia no hayan entrado; su rol se asigna al ingresar.
          </p>
        </div>
        <button type="button" class="btn btn-sm cat-btn-sec" :disabled="sincronizando" @click="sincronizar">
          <i :class="sincronizando ? 'fas fa-spinner fa-spin' : 'fas fa-sync-alt'"></i> Actualizar desde el portal
        </button>
      </header>
      <p v-if="sync && !sync.ok" class="cat-error"><i class="fas fa-exclamation-triangle"></i> {{ sync.error }}</p>
      <p v-else-if="sync" class="cat-muted cat-sync">Ultima actualizacion: {{ fmtFechaHora(sync.en) }} · {{ sync.ingenios }} ingenio(s) · {{ sync.responsables }} responsable(s)<template v-if="sync.nuevos"> · {{ sync.nuevos }} nuevo(s)</template></p>

      <table v-if="tab === 'ingenios'" class="table cat-tabla">
        <thead><tr><th>Ingenio</th><th class="text-right">Ensayos</th></tr></thead>
        <tbody>
          <tr v-for="i in ingeniosItems" :key="i.id"><td><b>{{ i.nombre }}</b></td><td class="text-right">{{ i.ensayos }}</td></tr>
          <tr v-if="!ingeniosItems.length"><td colspan="2" class="cat-muted text-center">Sin ingenios. Agreguelos en el portal y pulse "Actualizar desde el portal".</td></tr>
        </tbody>
      </table>
      <table v-else class="table cat-tabla">
        <thead><tr><th>Nombre</th><th>Correo</th><th>Ingenio</th><th>Rol en Experimentos</th><th class="text-right">Ensayos a cargo</th></tr></thead>
        <tbody>
          <tr v-for="u in responsablesItems" :key="u.id">
            <td><b>{{ u.nombre }}</b></td>
            <td class="cat-muted">{{ u.correo }}</td>
            <td>{{ u.ingenio || '—' }}</td>
            <td><span v-if="u.rol" class="cat-rol">{{ u.rol }}</span><span v-else class="cat-muted" title="Recibe su rol al entrar por primera vez desde el portal">aun no ingresa</span></td>
            <td class="text-right">{{ u.ensayos }}</td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>

<script>
const TABS = [
  { id: 'disenos', label: 'Disenos experimentales', icon: 'fas fa-th' },
  { id: 'tipos', label: 'Tipos de muestra', icon: 'fas fa-vial' },
  { id: 'ingenios', label: 'Ingenios', icon: 'fas fa-industry' },
  { id: 'responsables', label: 'Responsables', icon: 'fas fa-user-tie' },
];

export default {
  name: 'CatalogosIndex',
  props: {
    disenos: { type: Array, default: () => [] },
    tiposMuestra: { type: Array, default: () => [] },
    tiposLaboratorio: { type: Array, default: () => [] }, // catalogo del modulo Laboratorio
    formatosInforme: { type: Array, default: () => [] },
    ingenios: { type: Array, default: () => [] },
    responsables: { type: Array, default: () => [] },
    sincronizacion: { type: Object, default: null },
    portalIngeniosUrl: { type: String, default: '' },
    portalUsuariosUrl: { type: String, default: '' },
  },
  data() {
    const t = new URLSearchParams(window.location.search).get('tab');
    return {
      TABS,
      tab: TABS.some((x) => x.id === t) ? t : 'disenos',
      disenosItems: this.disenos.slice(),
      tiposItems: this.tiposMuestra.slice(),
      formTipo: null,
      ingeniosItems: this.ingenios.slice(),
      responsablesItems: this.responsables.slice(),
      sync: this.sincronizacion,
      form: null,
      guardando: false,
      sincronizando: false,
      error: null,
    };
  },
  computed: {
    // La fila nueva (id null) aparece primero mientras se llena.
    filasTipos() { return this.formTipo && !this.formTipo.id ? [{ id: null }, ...this.tiposItems] : this.tiposItems; },
    // Tipos del Laboratorio + el valor ya guardado (aunque el Laboratorio no responda).
    tiposLabOpciones() {
      const s = new Set(this.tiposLaboratorio);
      this.tiposItems.forEach((t) => { if (t.tipo_lab) s.add(t.tipo_lab); });
      return [...s].sort((a, b) => a.localeCompare(b, 'es'));
    },
  },
  methods: {
    cuenta(id) { return { disenos: this.disenosItems.length, tipos: this.tiposItems.length, ingenios: this.ingeniosItems.length, responsables: this.responsablesItems.length }[id]; },
    nuevoTipo() {
      this.error = null;
      this.formTipo = { id: null, nombre: '', codigo: '', tipo_lab: null, formato_informe: null, icono: null, activo: true };
      this.$nextTick(() => { const r = this.$refs.tipoNombre; (Array.isArray(r) ? r[0] : r) && (Array.isArray(r) ? r[0] : r).focus(); });
    },
    editarTipo(t) {
      this.error = null;
      this.formTipo = { id: t.id, nombre: t.nombre, codigo: t.codigo, tipo_lab: t.tipo_lab, formato_informe: t.formato_informe, icono: t.icono, activo: t.activo };
    },
    guardarTipo() {
      this.guardando = true;
      this.error = null;
      const { id, ...datos } = this.formTipo;
      (id ? window.axios.put(`/admin/catalogos/tipos-muestra/${id}`, datos) : window.axios.post('/admin/catalogos/tipos-muestra', datos))
        .then(({ data }) => { this.tiposItems = data.tiposMuestra; this.formTipo = null; this.toast(id ? 'Tipo actualizado' : 'Tipo agregado'); })
        .catch((e) => { this.error = this.mensaje(e); })
        .finally(() => { this.guardando = false; });
    },
    eliminarTipo(t) {
      if (t.muestras) {
        this.$swal.fire({ icon: 'info', title: 'No se puede eliminar', text: `Hay ${t.muestras} muestra(s) de ${t.nombre}. Desactivelo para que no se ofrezca al generar etiquetas.`, confirmButtonColor: '#73BC25' });
        return;
      }
      this.$swal.fire({
        title: `Eliminar "${t.nombre}"`, html: t.analitos ? `Tambien se eliminan sus ${t.analitos} analito(s) y sus campos configurados.` : '', icon: 'warning',
        showCancelButton: true, confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#FF6B00', cancelButtonColor: '#CED2D5', focusCancel: true,
      }).then((r) => {
        if (!r.isConfirmed) return;
        window.axios.delete(`/admin/catalogos/tipos-muestra/${t.id}`)
          .then(({ data }) => { this.tiposItems = data.tiposMuestra; this.toast('Tipo eliminado'); })
          .catch((e) => this.$swal.fire({ icon: 'error', title: 'No se pudo eliminar', text: this.mensaje(e) }));
      });
    },
    cambiarTab(id) {
      this.tab = id;
      try { const u = new URL(window.location.href); u.searchParams.set('tab', id); window.history.replaceState(null, '', u); } catch (e) { /* sin URL API */ }
    },
    nuevo() { this.error = null; this.form = { id: null, nombre: '', descripcion: '', usa_bloques: true, activo: true }; this.$nextTick(() => this.$refs.nombre && this.$refs.nombre.focus()); },
    editar(d) { this.error = null; this.form = { id: d.id, nombre: d.nombre, descripcion: d.descripcion || '', usa_bloques: d.usa_bloques, activo: d.activo }; },
    guardar() {
      this.guardando = true;
      this.error = null;
      const { id, ...datos } = this.form;
      (id ? window.axios.put(`/admin/catalogos/disenos/${id}`, datos) : window.axios.post('/admin/catalogos/disenos', datos))
        .then(({ data }) => {
          this.disenosItems = data.disenos;
          this.form = null;
          this.toast(id ? 'Diseno actualizado' : 'Diseno agregado');
        })
        .catch((e) => { this.error = this.mensaje(e); })
        .finally(() => { this.guardando = false; });
    },
    eliminar(d) {
      if (d.ensayos) {
        this.$swal.fire({ icon: 'info', title: 'No se puede eliminar', text: `Lo usan ${d.ensayos} ensayo(s). Desactivelo para que no se ofrezca en ensayos nuevos.`, confirmButtonColor: '#73BC25' });
        return;
      }
      this.$swal.fire({
        title: `Eliminar "${d.nombre}"`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar',
        confirmButtonColor: '#FF6B00', cancelButtonColor: '#CED2D5', focusCancel: true,
      }).then((r) => {
        if (!r.isConfirmed) return;
        window.axios.delete(`/admin/catalogos/disenos/${d.id}`)
          .then(({ data }) => { this.disenosItems = data.disenos; this.toast('Diseno eliminado'); })
          .catch((e) => this.$swal.fire({ icon: 'error', title: 'No se pudo eliminar', text: this.mensaje(e) }));
      });
    },
    sincronizar() {
      this.sincronizando = true;
      window.axios.post('/admin/catalogos/sincronizar')
        .then(({ data }) => {
          this.sync = data.sincronizacion;
          this.ingeniosItems = data.ingenios;
          this.responsablesItems = data.responsables;
          if (data.sincronizacion.ok) this.toast('Catalogos actualizados desde el portal');
        })
        .catch((e) => this.$swal.fire({ icon: 'error', title: 'No se pudo actualizar', text: this.mensaje(e) }))
        .finally(() => { this.sincronizando = false; });
    },
    mensaje(e) {
      const r = e.response && e.response.data;
      if (r && r.errors) return Object.values(r.errors)[0][0];
      return (r && r.message) || 'Ocurrio un error.';
    },
    toast(title) { this.$swal.fire({ toast: true, position: 'top-end', icon: 'success', title, timer: 2000, showConfirmButton: false }); },
    fmtFechaHora(s) {
      if (!s) return '';
      const [f, h] = String(s).split(' ');
      const [y, m, d] = f.split('-');
      return `${d}/${m}/${y} ${(h || '').slice(0, 5)}`;
    },
  },
};
</script>

<style scoped>
.cat { --verde: #73BC25; --verde-osc: #1f5632; --borde: #e3e7e1; --suave: #6b7568; }
.cat-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
.cat-tabs button { display: inline-flex; align-items: center; gap: 7px; padding: 8px 14px; border: 1px solid var(--borde); border-radius: 10px; background: #fff; color: #33433a; font-size: 13px; font-weight: 600; }
.cat-tabs button i { color: var(--verde); }
.cat-tabs button small { padding: 0 7px; border-radius: 999px; background: #f0f2f0; color: #5b6267; font-size: 11px; font-weight: 700; }
.cat-tabs button.is-on { border-color: var(--verde); background: #f3f9ea; color: var(--verde-osc); }
.cat-tabs button.is-on small { background: var(--verde); color: #fff; }
.cat-caja { padding: 14px 16px; border: 1px solid var(--borde); border-radius: 14px; background: #fff; }
.cat-h { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 10px 16px; margin-bottom: 12px; }
.cat-h h5 { margin: 0 0 4px; color: var(--verde-osc); font-size: 16px; font-weight: 800; }
.cat-h p { max-width: 760px; margin: 0; color: var(--suave); font-size: 12.5px; }
.cat-origen { margin-left: 6px; padding: 1px 8px; border-radius: 999px; background: #eef6e2; color: #3e6b14; font-size: 11px; font-weight: 700; }
.cat-btn-sec { border: 1px solid var(--borde); border-radius: 9px; background: #fff; color: var(--verde-osc); font-weight: 600; }
.cat-btn-sec i { color: var(--verde); }
.cat-tabla { margin: 0; font-size: 13px; }
.cat-tabla th { background: #f7f9f5; color: var(--suave); font-size: 11px; text-transform: uppercase; letter-spacing: .03em; white-space: nowrap; }
.cat-tabla td { vertical-align: middle; }
.cat-tabla tr.is-off td { opacity: .6; }
.cat-tabla tr.is-form td { background: #fbfdf7; }
.cat-muted { color: var(--suave); }
.cat-nowrap { white-space: nowrap; }
.cat-si { padding: 1px 8px; border-radius: 999px; background: #eef6e2; color: #3e6b14; font-size: 11.5px; font-weight: 700; }
.cat-si.is-no { background: #f0f2f0; color: #6b7568; }
.cat-rol { padding: 1px 8px; border-radius: 6px; background: #fff7d6; color: #8a6a00; font-size: 11.5px; font-weight: 700; }
.cat-icon { display: inline-grid; place-items: center; width: 28px; height: 28px; border: 1px solid transparent; border-radius: 8px; background: transparent; color: var(--suave); }
.cat-icon:hover { border-color: var(--borde); background: #f1f8e9; color: var(--verde-osc); }
.cat-icon.is-del:hover { border-color: #ffd2b3; background: #fff1e6; color: #FF6B00; }
.cat-icon.is-locked { opacity: .45; }
.cat-error { margin: 10px 0 0; color: #b84d00; font-size: 12.5px; }
.cat-sync { margin: 0 0 8px; font-size: 12px; }
.cat-cod { max-width: 80px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
.cat-cod-v { padding: 1px 6px; border-radius: 5px; background: #f0f2f0; color: #1f5632; font-weight: 700; }
.cat-tipo-ico { width: 16px; color: var(--verde); text-align: center; }
.cat-aviso { display: block; margin-top: 3px; color: #b84d00; font-size: 11px; }
</style>
