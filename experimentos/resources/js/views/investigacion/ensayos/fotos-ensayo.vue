<template>
  <div class="fe">
    <div class="fe-barra">
      <div class="fe-seg" role="group" aria-label="Filas">
        <button type="button" :class="{ 'is-on': filas === 'tratamiento' }" @click="filas = 'tratamiento'"><i class="fas fa-th"></i> Por tratamiento</button>
        <button type="button" :class="{ 'is-on': filas === 'parcela' }" @click="filas = 'parcela'"><i class="fas fa-border-all"></i> Por parcela</button>
      </div>
      <label v-if="repeticiones.length > 1 && filas === 'tratamiento'" class="fe-rep">Repetición
        <select v-model="repSel" class="form-control form-control-sm"><option value="">Todas</option><option v-for="r in repeticiones" :key="r" :value="r">R{{ r }}</option></select>
      </label>
      <span class="fe-info">{{ fotos.length }} fotos · {{ fechas.length }} fechas<template v-if="aplicacion"> · DDA = días después de la aplicación del {{ fmt(aplicacion) }}</template></span>
      <button v-if="puedeSubir" type="button" class="btn btn-success btn-sm" @click="panel = !panel"><i :class="panel ? 'fas fa-times' : 'fas fa-camera'"></i> {{ panel ? 'Cerrar' : 'Agregar fotos' }}</button>
    </div>

    <!-- Carga desde el detalle: varias fotos a una parcela y fecha -->
    <div v-if="panel" class="fe-panel">
      <label>Fecha de la foto<input v-model="nueva.fecha" type="date" class="form-control form-control-sm" :max="hoy"></label>
      <label>Parcela
        <select v-model="nueva.parcela_id" class="form-control form-control-sm">
          <option :value="null">Elige…</option>
          <option v-for="p in parcelasOrden" :key="p.id" :value="p.id">{{ p.codigo }} · {{ tratDe(p) || 'sin trat.' }} · R{{ p.repeticion }}</option>
        </select>
      </label>
      <label class="fe-nota">Nota (opcional)<input v-model="nueva.nota" class="form-control form-control-sm" maxlength="500" placeholder="p. ej. 80 % de control de hoja ancha"></label>
      <label class="btn btn-success btn-sm fe-elegir" :class="{ disabled: !nueva.parcela_id || !nueva.fecha || cargando }">
        <input type="file" accept="image/*" multiple :disabled="!nueva.parcela_id || !nueva.fecha || cargando" @change="subirVarias">
        <i :class="cargando ? 'fas fa-spinner fa-spin' : 'fas fa-images'"></i> {{ cargando ? cargando : 'Elegir fotos' }}
      </label>
      <p class="fe-ayuda">Consejo: toma la foto siempre desde el mismo punto y altura (por ejemplo, cenital sobre un marco de 1 m² en el centro de la parcela) para que las fechas y tratamientos sean comparables. En el muestreo de campo, activa "Registro fotográfico" en el formulario y se toman desde la planilla.</p>
      <p v-if="error" class="fe-error">{{ error }}</p>
    </div>

    <div v-if="cargandoLista" class="fe-vacio"><i class="fas fa-spinner fa-spin"></i> Cargando fotos…</div>
    <div v-else-if="!fotos.length" class="fe-vacio">
      <i class="fas fa-camera"></i>
      <b>Sin fotos de parcelas todavía.</b>
      <span>Registra el efecto de cada tratamiento con fotos por parcela y fecha: aquí se comparan lado a lado, por ejemplo el testigo contra cada herbicida a 7, 14 y 28 DDA.</span>
    </div>

    <div v-else class="fe-tabla-wrap">
      <table class="fe-tabla">
        <thead>
          <tr>
            <th class="fe-col-fila">{{ filas === 'tratamiento' ? 'Tratamiento' : 'Parcela' }}</th>
            <th v-for="f in fechas" :key="f"><b>{{ fmt(f) }}</b><small v-if="dda(f) !== null">{{ dda(f) }} DDA</small></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in renglones" :key="r.clave">
            <th class="fe-col-fila">
              <span class="fe-trat" :style="{ '--c': r.color }">{{ r.titulo }}</span>
              <small v-if="r.sub" :title="r.sub">{{ r.sub }}</small>
            </th>
            <td v-for="f in fechas" :key="f">
              <div class="fe-celda">
                <button v-for="x in r.fotos[f] || []" :key="x.id" type="button" class="fe-foto" :title="describir(x)" @click="ver(r.fotos[f], x)">
                  <img :src="x.miniatura" :alt="describir(x)" loading="lazy">
                  <em v-if="filas === 'tratamiento'">R{{ (parcMap.get(x.parcela_id) || {}).repeticion }}</em>
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <fotos-visor v-if="visor" :fotos="visor.fotos" :inicio="visor.i" :describe="describir" @cerrar="visor = null" @eliminar="eliminar"></fotos-visor>
  </div>
</template>

<script>
import FotosVisor from '../../../components/fotos-visor.vue';
import { subirFoto, mensajeError } from '../../../fotos-util';

const hoyIso = () => new Date().toISOString().slice(0, 10);

/** Pestaña Fotos del ensayo: cuadricula tratamiento (o parcela) x fecha para ver el efecto de los tratamientos. */
export default {
  name: 'FotosEnsayo',
  components: { FotosVisor },
  props: {
    ensayoId: { type: Number, required: true },
    parcelas: { type: Array, default: () => [] },
    tratamientos: { type: Array, default: () => [] },
    bitacora: { type: Array, default: () => [] },
    colorTrat: { type: Function, default: () => '#73BC25' },
  },
  data() {
    return {
      fotos: [], puedeSubir: false, cargandoLista: true, panel: false, cargando: '', error: '',
      filas: 'tratamiento', repSel: '', visor: null, hoy: hoyIso(),
      nueva: { fecha: hoyIso(), parcela_id: null, nota: '' },
    };
  },
  computed: {
    parcMap() { return new Map(this.parcelas.map((p) => [p.id, p])); },
    parcelasOrden() { return this.parcelas.slice().sort((a, b) => String(a.codigo).localeCompare(String(b.codigo), 'es', { numeric: true })); },
    repeticiones() { return [...new Set(this.parcelas.map((p) => p.repeticion).filter((r) => r != null))].sort((a, b) => a - b); },
    visibles() { return this.fotos.filter((f) => !this.repSel || this.filas !== 'tratamiento' || (this.parcMap.get(f.parcela_id) || {}).repeticion === this.repSel); },
    fechas() { return [...new Set(this.visibles.map((f) => f.fecha))].sort(); },
    // Ultima aplicacion de producto o control de maleza registrada en la bitacora, para calcular DDA.
    aplicaciones() { return this.bitacora.filter((b) => /aplicaci|control de maleza/i.test(b.tipo || '')).map((b) => String(b.fecha).slice(0, 10)).sort(); },
    aplicacion() { return this.aplicaciones.filter((a) => this.fechas.some((f) => f >= a)).shift() || null; },
    renglones() {
      const porFecha = (lista) => { const m = {}; lista.forEach((f) => { (m[f.fecha] = m[f.fecha] || []).push(f); }); return m; };
      if (this.filas === 'parcela') {
        return this.parcelasOrden.map((p) => ({ clave: 'p' + p.id, titulo: p.codigo, sub: `${this.tratDe(p) || 'sin trat.'} · R${p.repeticion}`, color: this.colorTrat(this.tratDe(p)), fotos: porFecha(this.visibles.filter((f) => f.parcela_id === p.id)) }))
          .filter((r) => Object.keys(r.fotos).length);
      }
      return this.tratamientos.map((t) => {
        const ids = new Set(this.parcelas.filter((p) => this.tratDe(p) === t.codigo).map((p) => p.id));
        const lista = this.visibles.filter((f) => ids.has(f.parcela_id)).sort((a, b) => ((this.parcMap.get(a.parcela_id) || {}).repeticion || 0) - ((this.parcMap.get(b.parcela_id) || {}).repeticion || 0));
        return { clave: 't' + t.id, titulo: t.codigo, sub: t.descripcion, color: this.colorTrat(t.codigo), fotos: porFecha(lista) };
      });
    },
  },
  mounted() { this.cargar(); },
  methods: {
    async cargar() {
      this.cargandoLista = true;
      try {
        const { data } = await window.axios.get(`/investigacion/ensayos/${this.ensayoId}/fotos`);
        this.fotos = data.fotos; this.puedeSubir = data.puede_subir;
        this.$emit('conteo', this.fotos.length);
      } catch (e) { this.error = mensajeError(e); }
      finally { this.cargandoLista = false; }
    },
    tratDe(p) { return p.tratamiento ? p.tratamiento.codigo || p.tratamiento : null; },
    fmt(f) { return f ? String(f).slice(0, 10).split('-').reverse().join('/') : ''; },
    dda(f) {
      const a = this.aplicaciones.filter((x) => x <= f).pop();
      return a ? Math.round((Date.parse(f) - Date.parse(a)) / 86400000) : null;
    },
    describir(f) {
      const p = this.parcMap.get(f.parcela_id) || {};
      const d = this.dda(f.fecha);
      return [p.codigo, this.tratDe(p), p.repeticion != null ? 'R' + p.repeticion : '', this.fmt(f.fecha), d !== null ? d + ' DDA' : ''].filter(Boolean).join(' · ');
    },
    ver(lista, foto) { this.visor = { fotos: lista, i: lista.indexOf(foto) }; },
    async subirVarias(e) {
      const archivos = [...e.target.files]; e.target.value = '';
      this.error = '';
      for (let i = 0; i < archivos.length; i++) {
        this.cargando = `Subiendo ${i + 1} de ${archivos.length}…`;
        try {
          const f = await subirFoto({ ensayoId: this.ensayoId, parcelaId: this.nueva.parcela_id, fecha: this.nueva.fecha, nota: this.nueva.nota, file: archivos[i] });
          this.fotos.push(f);
        } catch (err) { this.error = mensajeError(err); break; }
      }
      this.cargando = '';
      this.nueva.nota = '';
      this.$emit('conteo', this.fotos.length);
    },
    eliminar(foto) {
      this.$swal.fire({ title: '¿Eliminar esta foto?', text: this.describir(foto), icon: 'warning', showCancelButton: true, confirmButtonText: 'Eliminar', confirmButtonColor: '#FF6B00' }).then((r) => {
        if (!r.isConfirmed) return;
        window.axios.delete(`/investigacion/fotos/${foto.id}`).then(() => {
          this.fotos = this.fotos.filter((f) => f.id !== foto.id);
          if (this.visor) this.visor.fotos = this.visor.fotos.filter((f) => f.id !== foto.id);
          this.$emit('conteo', this.fotos.length);
        }).catch((err) => this.$swal.fire({ icon: 'error', title: mensajeError(err) }));
      });
    },
  },
};
</script>

<style scoped>
.fe{display:flex;flex-direction:column;gap:12px}
.fe-barra{display:flex;align-items:center;flex-wrap:wrap;gap:10px}
.fe-seg{display:flex;border:1px solid #cfdcc6;border-radius:8px;overflow:hidden}
.fe-seg button{border:0;background:#fff;padding:6px 11px;font-size:12px;color:#4d5a48}.fe-seg button+button{border-left:1px solid #cfdcc6}
.fe-seg button.is-on{background:#73bc25;color:#fff;font-weight:600}
.fe-rep{display:flex;align-items:center;gap:6px;margin:0;font-size:12px;font-weight:600}.fe-rep .form-control{width:auto}
.fe-info{margin-right:auto;font-size:12px;color:#69766e}
.fe-panel{display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;padding:12px;background:#f7fbf1;border:1px solid #dcebc8;border-radius:10px}
.fe-panel label{margin:0;font-size:11px;font-weight:700}.fe-panel .form-control{margin-top:3px}
.fe-nota{flex:1 1 220px}
.fe-elegir{margin:0!important;font-size:12px!important;cursor:pointer}.fe-elegir input{display:none}
.fe-ayuda{flex-basis:100%;margin:0;font-size:11px;color:#61705f}
.fe-error{flex-basis:100%;margin:0;color:#b84d00;font-size:12px}
.fe-vacio{display:flex;flex-direction:column;align-items:center;gap:6px;padding:32px;border:1px dashed #ced2d5;border-radius:12px;color:#69766e;text-align:center}
.fe-vacio i{font-size:28px;color:#ced2d5}.fe-vacio span{max-width:520px;font-size:12px}
.fe-tabla-wrap{overflow:auto;border:1px solid #e1e7dc;border-radius:10px;max-height:70vh}
.fe-tabla{border-collapse:separate;border-spacing:0;font-size:12px}
.fe-tabla th,.fe-tabla td{padding:6px;border-bottom:1px solid #eef2eb;border-right:1px solid #f3f5f1;vertical-align:top}
.fe-tabla thead th{position:sticky;top:0;z-index:1;background:#f4f8ef;text-align:center;white-space:nowrap}
.fe-tabla thead th small{display:block;font-weight:600;color:#b84d00}
.fe-col-fila{position:sticky;left:0;z-index:1;min-width:130px;max-width:170px;background:#fff;text-align:left}
.fe-tabla thead .fe-col-fila{z-index:2;background:#f4f8ef}
.fe-col-fila small{display:block;font-weight:400;color:#69766e;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.fe-trat{display:inline-flex;align-items:center;gap:6px;font-weight:700}.fe-trat::before{content:'';width:10px;height:10px;border-radius:50%;background:var(--c)}
.fe-celda{display:flex;flex-wrap:wrap;gap:4px;min-width:150px}
.fe-foto{position:relative;width:110px;height:82px;padding:0;border:1px solid #dce3d6;border-radius:6px;overflow:hidden;background:#f4f7f1}
.fe-foto img{width:100%;height:100%;object-fit:cover;transition:transform .15s}.fe-foto:hover img{transform:scale(1.05)}
.fe-foto em{position:absolute;left:3px;bottom:3px;padding:0 5px;border-radius:6px;background:rgba(0,0,0,.6);color:#fff;font-size:10px;font-style:normal}
</style>
