<template>
  <div class="bt">
    <!-- ============ Filtros propios de la bitacora ============ -->
    <div class="bt-bar">
      <div class="bt-tipos" role="group" aria-label="Tipo de actividad">
        <button type="button" :class="{ 'is-on': !tipo }" @click="tipo = ''">Todas <small>{{ delPeriodo.length }}</small></button>
        <button v-for="t in tiposConDatos" :key="t" type="button" :class="{ 'is-on': tipo === t }" :style="{ '--c': colorTipo(t) }" @click="tipo = tipo === t ? '' : t">
          <i :class="iconoTipo(t)"></i> {{ t }} <small>{{ cuentaTipo(t) }}</small>
        </button>
      </div>
      <div class="bt-acc">
        <select v-model.number="dias" class="form-control form-control-sm bt-periodo" aria-label="Periodo">
          <option :value="0">Todo el periodo</option>
          <option :value="7">Ultimos 7 dias</option>
          <option :value="30">Ultimos 30 dias</option>
          <option :value="90">Ultimos 90 dias</option>
        </select>
        <button type="button" class="btn btn-sm bt-btn-sec" :disabled="!lista.length" title="Exportar las anotaciones filtradas (se abre en Excel)" @click="exportar"><i class="fas fa-file-excel"></i></button>
        <button v-if="permisos.bitacora" type="button" class="btn btn-sm btn-success" :disabled="!ensayos.length" @click="nueva"><i class="fas fa-plus"></i> Nueva anotacion</button>
      </div>
    </div>
    <p class="bt-res">
      <b>{{ lista.length }}</b> anotacion(es) de <b>{{ ensayosConDatos }}</b> ensayo(s)
      <span v-if="ensayos.length !== totalEnsayos"> · filtrado por la barra de arriba ({{ ensayos.length }} de {{ totalEnsayos }} ensayos)</span>
    </p>

    <div v-if="cargando" class="bt-vacio"><i class="fas fa-spinner fa-spin"></i> Cargando bitacora...</div>
    <div v-else-if="!lista.length" class="bt-vacio">
      <i class="fas fa-book-open"></i>
      <strong>{{ entradas.length ? 'Ninguna anotacion coincide con los filtros.' : 'Aun no hay anotaciones en la bitacora.' }}</strong>
      <span>Registre aplicaciones, riegos, visitas, muestreos o incidencias de cada ensayo.</span>
    </div>

    <!-- ============ Linea de tiempo ============ -->
    <div v-else class="bt-linea">
      <section v-for="g in grupos" :key="g.mes" class="bt-mes">
        <h6>{{ g.titulo }} <small>{{ g.n }}</small></h6>
        <div v-for="d in g.dias" :key="d.fecha" class="bt-dia">
          <div class="bt-fecha"><b>{{ d.dia }}</b><small>{{ d.semana }}</small></div>
          <div class="bt-items">
            <article v-for="e in d.entradas" :key="e.id" class="bt-item" :style="{ '--c': colorTipo(e.tipo) }">
              <span class="bt-ico"><i :class="iconoTipo(e.tipo)"></i></span>
              <div class="bt-cuerpo">
                <div class="bt-cab">
                  <b class="bt-tipo">{{ e.tipo }}</b>
                  <button v-if="e.ensayo" type="button" class="bt-ens" :title="`Abrir ${e.ensayo.codigo}`" @click="$emit('ver', e.ensayo_id)">{{ e.ensayo.codigo }}</button>
                  <span class="bt-resp"><i class="fas fa-user"></i> {{ e.responsable ? e.responsable.name : '—' }}</span>
                  <span class="bt-item-acc">
                    <button v-if="permisos.bitacora" type="button" title="Editar" @click="editar(e)"><i class="fas fa-pen"></i></button>
                    <button v-if="permisos.eliminar" type="button" class="is-del" title="Eliminar" @click="eliminar(e)"><i class="fas fa-trash"></i></button>
                  </span>
                </div>
                <p>{{ e.descripcion }}</p>
              </div>
            </article>
          </div>
        </div>
      </section>
    </div>

    <!-- ============ Alta / edicion ============ -->
    <div class="modal fade" ref="modal" tabindex="-1" role="dialog">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
          <form @submit.prevent="guardar" novalidate>
            <div class="modal-header">
              <h5 class="modal-title"><i class="fas fa-book-open"></i>{{ form.id ? 'Editar anotacion' : 'Nueva anotacion' }}</h5>
              <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
            </div>
            <div class="modal-body">
              <div class="form-row">
                <div class="col-7 form-group">
                  <label>Ensayo *</label>
                  <select v-model="form.ensayo_id" class="form-control" :class="{ 'is-invalid': errors.ensayo_id }">
                    <option :value="null">Seleccione...</option>
                    <option v-for="e in ensayosForm" :key="e.id" :value="e.id">{{ e.codigo }}{{ e.nombre ? ' — ' + e.nombre : '' }}</option>
                  </select>
                  <span class="invalid-feedback" v-if="errors.ensayo_id">{{ errors.ensayo_id[0] }}</span>
                </div>
                <div class="col-5 form-group">
                  <label>Fecha *</label>
                  <input type="date" v-model="form.fecha" class="form-control" :class="{ 'is-invalid': errors.fecha }">
                  <span class="invalid-feedback" v-if="errors.fecha">{{ errors.fecha[0] }}</span>
                </div>
              </div>
              <div class="form-group">
                <label>Tipo de actividad *</label>
                <select v-model="form.tipo" class="form-control">
                  <option v-for="t in tipos" :key="t" :value="t">{{ t }}</option>
                </select>
              </div>
              <div class="form-group mb-0">
                <label>Descripcion *</label>
                <textarea v-model="form.descripcion" rows="4" maxlength="2000" class="form-control" :class="{ 'is-invalid': errors.descripcion }" placeholder="Que se hizo, producto y dosis, observaciones..."></textarea>
                <span class="invalid-feedback" v-if="errors.descripcion">{{ errors.descripcion[0] }}</span>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-success" :disabled="guardando"><i :class="guardando ? 'fas fa-spinner fa-spin' : 'fas fa-save'"></i> Guardar</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
const DIA = 86400000;
const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const SEMANA = ['dom', 'lun', 'mar', 'mie', 'jue', 'vie', 'sab'];
const ESTILO = {
  Siembra: ['fas fa-seedling', '#5a9e1a'],
  'Fertilización': ['fas fa-flask', '#2a78d6'],
  'Control de maleza': ['fas fa-leaf', '#1baf7a'],
  'Aplicación de producto': ['fas fa-spray-can', '#6250d6'],
  'Evaluación de campo': ['fas fa-clipboard-check', '#73BC25'],
  Muestreo: ['fas fa-vial', '#eda100'],
  Cosecha: ['fas fa-tractor', '#FF6B00'],
  Mantenimiento: ['fas fa-tools', '#8a8f87'],
  'Incidencia fitosanitaria': ['fas fa-bug', '#e34948'],
  'Visita técnica': ['fas fa-user-check', '#008300'],
  Otro: ['fas fa-sticky-note', '#8a8f87'],
};

/**
 * Vista "Bitacora" de Ensayos: linea de tiempo de las anotaciones de los ensayos que dejan
 * pasar los filtros de la pagina (programa, proyecto, estado, busqueda).
 */
export default {
  name: 'BitacoraEnsayos',
  props: {
    ensayos: { type: Array, default: () => [] }, // ensayos filtrados en la pagina
    totalEnsayos: { type: Number, default: 0 },
    tipos: { type: Array, default: () => [] },
    permisos: { type: Object, default: () => ({}) },
  },
  data() {
    return { entradas: [], cargando: true, tipo: '', dias: 0, form: this.formVacio(), errors: {}, guardando: false };
  },
  computed: {
    idsVisibles() { return new Set(this.ensayos.map((e) => e.id)); },
    delPeriodo() {
      const desde = this.dias ? Date.now() - this.dias * DIA : null;
      return this.entradas.filter((e) => this.idsVisibles.has(e.ensayo_id) && (!desde || Date.parse(this.fecha(e)) >= desde));
    },
    lista() { return this.delPeriodo.filter((e) => !this.tipo || e.tipo === this.tipo); },
    tiposConDatos() { return this.tipos.filter((t) => this.cuentaTipo(t) > 0 || t === this.tipo); },
    ensayosConDatos() { return new Set(this.lista.map((e) => e.ensayo_id)).size; },
    ensayosForm() { return this.ensayos.length ? this.ensayos : []; },
    grupos() {
      const meses = new Map();
      this.lista.forEach((e) => {
        const f = this.fecha(e);
        const mes = f.slice(0, 7);
        if (!meses.has(mes)) meses.set(mes, new Map());
        const dias = meses.get(mes);
        if (!dias.has(f)) dias.set(f, []);
        dias.get(f).push(e);
      });
      return [...meses.entries()].map(([mes, dias]) => {
        const [y, m] = mes.split('-');
        return {
          mes,
          titulo: `${MESES[Number(m) - 1]} ${y}`,
          n: [...dias.values()].reduce((s, x) => s + x.length, 0),
          dias: [...dias.entries()].map(([f, entradas]) => ({ fecha: f, dia: f.slice(8, 10), semana: SEMANA[new Date(`${f}T12:00:00`).getDay()], entradas })),
        };
      });
    },
  },
  mounted() { this.cargar(); },
  methods: {
    fecha(e) { return String(e.fecha).slice(0, 10); },
    iconoTipo(t) { return (ESTILO[t] || ESTILO.Otro)[0]; },
    colorTipo(t) { return (ESTILO[t] || ESTILO.Otro)[1]; },
    cuentaTipo(t) { return this.delPeriodo.filter((e) => e.tipo === t).length; },
    formVacio() {
      return { id: null, ensayo_id: null, fecha: new Date().toISOString().slice(0, 10), tipo: this.tipos[0] || 'Otro', descripcion: '' };
    },
    cargar() {
      this.cargando = true;
      window.axios.get('/soporte/bitacora/listado')
        .then(({ data }) => { this.entradas = data.entradas; })
        .catch(() => { this.entradas = []; })
        .finally(() => { this.cargando = false; });
    },
    nueva() {
      this.form = this.formVacio();
      // Si la pagina esta filtrada a un solo ensayo, la anotacion ya viene con ese ensayo.
      if (this.ensayos.length === 1) this.form.ensayo_id = this.ensayos[0].id;
      if (this.tipo) this.form.tipo = this.tipo;
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    editar(e) {
      this.form = { id: e.id, ensayo_id: e.ensayo_id, fecha: this.fecha(e), tipo: e.tipo, descripcion: e.descripcion };
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    guardar() {
      this.guardando = true;
      this.errors = {};
      const { id, ...datos } = this.form;
      (id ? window.axios.put(`/soporte/bitacora/${id}`, datos) : window.axios.post('/soporte/bitacora', datos))
        .then(({ data }) => {
          const i = this.entradas.findIndex((x) => x.id === data.id);
          if (i >= 0) this.$set(this.entradas, i, data); else this.entradas.unshift(data);
          this.entradas.sort((a, b) => this.fecha(b).localeCompare(this.fecha(a)) || b.id - a.id);
          window.$(this.$refs.modal).modal('hide');
          this.$swal.fire({ toast: true, position: 'top-end', icon: 'success', title: id ? 'Anotacion actualizada' : 'Anotacion registrada', timer: 2000, showConfirmButton: false });
        })
        .catch((err) => {
          if (err.response && err.response.status === 422) this.errors = err.response.data.errors || {};
          else this.$swal.fire({ icon: 'error', title: 'No se pudo guardar', text: 'Intente de nuevo.' });
        })
        .finally(() => { this.guardando = false; });
    },
    eliminar(e) {
      this.$swal.fire({
        title: 'Eliminar anotacion', text: `${e.tipo} del ${this.fmt(this.fecha(e))} en ${e.ensayo ? e.ensayo.codigo : 'el ensayo'}.`, icon: 'warning',
        showCancelButton: true, confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#FF6B00', cancelButtonColor: '#CED2D5', focusCancel: true,
      }).then((r) => {
        if (!r.isConfirmed) return;
        window.axios.delete(`/soporte/bitacora/${e.id}`)
          .then(() => { this.entradas = this.entradas.filter((x) => x.id !== e.id); })
          .catch(() => this.$swal.fire({ icon: 'error', title: 'No se pudo eliminar' }));
      });
    },
    fmt(s) { const [y, m, d] = String(s).slice(0, 10).split('-'); return `${d}/${m}/${y}`; },
    exportar() {
      const celda = (x) => { const s = x === null || x === undefined ? '' : String(x); return /[;"\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s; };
      const filas = [['Fecha', 'Ensayo', 'Tipo', 'Descripcion', 'Responsable'],
        ...this.lista.map((e) => [this.fmt(this.fecha(e)), e.ensayo ? e.ensayo.codigo : '', e.tipo, e.descripcion, e.responsable ? e.responsable.name : ''])];
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob(['﻿' + filas.map((f) => f.map(celda).join(';')).join('\r\n')], { type: 'text/csv;charset=utf-8' }));
      a.download = `bitacora_${new Date().toISOString().slice(0, 10)}.csv`;
      document.body.appendChild(a);
      a.click();
      setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 0);
    },
  },
};
</script>

<style scoped>
.bt { --verde: #73BC25; --verde-osc: #1f5632; --borde: #e3e7e1; --suave: #6b7568; --tinta: #1e2a1a; }
.bt-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 8px; }
.bt-tipos { display: flex; flex: 1 1 auto; flex-wrap: wrap; gap: 6px; }
.bt-tipos button { --c: #73BC25; display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border: 1px solid #CED2D5; border-radius: 999px; background: #fff; color: var(--suave); font-size: 12px; font-weight: 600; }
.bt-tipos button i { color: var(--c); }
.bt-tipos button small { padding: 0 5px; border-radius: 999px; background: #f0f2f0; font-size: 10.5px; font-weight: 700; }
.bt-tipos button.is-on { border-color: var(--c); background: color-mix(in srgb, var(--c) 14%, #fff); color: var(--tinta); }
.bt-acc { display: flex; align-items: center; gap: 6px; margin-left: auto; }
.bt-periodo { width: auto; }
.bt-btn-sec { border: 1px solid var(--borde); background: #fff; color: #1d6f42; }
.bt-res { margin: 0 0 12px; color: var(--suave); font-size: 12.5px; }
.bt-res b { color: var(--tinta); }
.bt-vacio { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 40px 16px; border: 1px dashed #CED2D5; border-radius: 14px; background: #fff; color: var(--suave); text-align: center; }
.bt-vacio > i { color: #CED2D5; font-size: 28px; }
.bt-vacio strong { color: var(--tinta); }
.bt-linea { padding: 6px 16px 16px; border: 1px solid var(--borde); border-radius: 14px; background: #fff; }
.bt-mes h6 { position: sticky; top: 64px; z-index: 1; margin: 0; padding: 10px 0 6px; background: #fff; color: var(--verde-osc); font-size: 13px; font-weight: 800; text-transform: capitalize; }
.bt-mes h6 small { margin-left: 4px; padding: 0 7px; border-radius: 999px; background: #eef6e2; color: #3e6b14; font-size: 11px; }
.bt-dia { display: grid; grid-template-columns: 54px 1fr; gap: 10px; padding: 6px 0; border-top: 1px dashed #edf1ec; }
.bt-fecha { display: flex; flex-direction: column; align-items: center; padding-top: 4px; line-height: 1.1; }
.bt-fecha b { color: var(--tinta); font-family: 'Space Grotesk', sans-serif; font-size: 20px; }
.bt-fecha small { color: var(--suave); font-size: 11px; text-transform: uppercase; }
.bt-items { display: flex; flex-direction: column; gap: 6px; }
.bt-item { --c: #73BC25; display: flex; gap: 10px; padding: 8px 10px; border: 1px solid var(--borde); border-left: 3px solid var(--c); border-radius: 10px; }
.bt-item:hover { background: #fafcf7; }
.bt-ico { display: grid; flex: none; place-items: center; width: 30px; height: 30px; border-radius: 8px; background: color-mix(in srgb, var(--c) 15%, #fff); color: var(--c); font-size: 13px; }
.bt-cuerpo { flex: 1; min-width: 0; }
.bt-cab { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 10px; }
.bt-tipo { color: var(--tinta); font-size: 13px; }
.bt-ens { padding: 0 7px; border: 0; border-radius: 6px; background: #eef6e2; color: #3e6b14; font-size: 11.5px; font-weight: 700; }
.bt-ens:hover { background: #dcefc4; text-decoration: underline; }
.bt-resp { color: var(--suave); font-size: 11.5px; }
.bt-item-acc { display: flex; gap: 2px; margin-left: auto; opacity: 0; transition: opacity .15s; }
.bt-item:hover .bt-item-acc, .bt-item:focus-within .bt-item-acc { opacity: 1; }
.bt-item-acc button { display: grid; place-items: center; width: 26px; height: 26px; border: 0; border-radius: 6px; background: transparent; color: var(--suave); font-size: 11px; }
.bt-item-acc button:hover { background: #eef6e2; color: var(--verde-osc); }
.bt-item-acc button.is-del:hover { background: #fff1e6; color: #FF6B00; }
.bt-cuerpo p { margin: 3px 0 0; color: #3d4838; font-size: 12.5px; white-space: pre-line; }
.modal-title i { margin-right: 8px; color: var(--verde); }
@media (max-width: 640px) { .bt-acc { margin-left: 0; } .bt-item-acc { opacity: 1; } }
</style>
