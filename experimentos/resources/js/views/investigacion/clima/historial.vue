<template>
  <div class="hi">
    <div class="hi-head">
      <span class="hi-num"><i class="fas fa-history"></i></span>
      <div>
        <h6>Historial de las estaciones</h6>
        <p>
          Descarga una sola vez los datos diarios guardados en la API (todas las estaciones) para consultarlos y compararlos en SIGEC.
          Se pide por trimestres y se omiten los dias ya descargados; puede pausar y continuar despues.
        </p>
      </div>
    </div>

    <!-- Estado por año -->
    <div class="hi-anios">
      <div v-for="a in anios" :key="a.anio" class="hi-anio" :class="'is-' + a.estado" :title="a.titulo">
        <b>{{ a.anio }}</b>
        <small>{{ a.estado === 'completo' ? 'completo' : a.estado === 'parcial' ? `${a.pct} %` : 'pendiente' }}</small>
      </div>
    </div>

    <div class="hi-controles">
      <label>Desde
        <select class="form-control form-control-sm" v-model.number="desde" :disabled="corriendo">
          <option v-for="a in rangoAnios" :key="a" :value="a">{{ a }}</option>
        </select>
      </label>
      <label>Hasta
        <select class="form-control form-control-sm" v-model.number="hasta" :disabled="corriendo">
          <option v-for="a in rangoAnios" :key="a" :value="a" :disabled="a < desde">{{ a }}</option>
        </select>
      </label>
      <button v-if="!corriendo" type="button" class="btn btn-success btn-sm" :disabled="!configurada" @click="iniciar">
        <i class="fas fa-cloud-download-alt"></i> {{ hechos && hechos < tramos.length ? 'Continuar descarga' : 'Descargar historial' }}
      </button>
      <button v-else type="button" class="btn btn-secondary btn-sm" @click="pausar"><i class="fas fa-pause"></i> Pausar</button>
      <span v-if="!configurada" class="hi-muted">Guarde primero una conexion que funcione.</span>
    </div>

    <div v-if="tramos.length" class="hi-progreso">
      <div class="hi-barra"><i :style="{ width: pct + '%' }"></i></div>
      <div class="hi-detalle">
        <span>
          <i v-if="corriendo" class="fas fa-spinner fa-spin"></i>
          <i v-else-if="hechos === tramos.length" class="fas fa-check-circle hi-ok"></i>
          {{ hechos }} de {{ tramos.length }} trimestres ({{ pct }} %)
          <template v-if="corriendo && actual"> · descargando {{ actual }}</template>
          <template v-else-if="!corriendo && hechos < tramos.length"> · en pausa</template>
        </span>
        <span>{{ registros.toLocaleString('es-GT') }} registros nuevos · {{ dias.toLocaleString('es-GT') }} dias<template v-if="nuevas"> · {{ nuevas }} estacion(es) nueva(s)</template></span>
      </div>
      <div v-if="corriendo && restante" class="hi-muted">Tiempo estimado restante: {{ restante }}. Puede seguir usando SIGEC en otra pestaña; no cierre esta.</div>
      <div v-for="(e, i) in errores" :key="i" class="hi-error"><i class="fas fa-exclamation-triangle"></i> {{ e }}</div>
    </div>
  </div>
</template>

<script>
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const esperar = (ms) => new Promise((ok) => setTimeout(ok, ms));

export default {
  name: 'ClimaHistorial',
  props: {
    configurada: { type: Boolean, default: false },
  },
  data() {
    const y = new Date().getFullYear();
    return {
      estado: [],
      desde: 2009,
      hasta: y,
      rangoAnios: Array.from({ length: y - 2000 + 1 }, (_, i) => 2000 + i),
      tramos: [],
      hechos: 0,
      corriendo: false,
      detener: false,
      actual: '',
      registros: 0,
      dias: 0,
      nuevas: 0,
      errores: [],
      inicio: null,
      hechosAlIniciar: 0,
    };
  },
  computed: {
    pct() { return this.tramos.length ? Math.round((this.hechos / this.tramos.length) * 100) : 0; },
    anios() {
      const hoy = new Date();
      return Array.from({ length: hoy.getFullYear() - 2009 + 1 }, (_, i) => 2009 + i).map((a) => {
        const e = this.estado.find((x) => x.anio === a);
        const total = a === hoy.getFullYear() ? Math.floor((hoy - new Date(a, 0, 1)) / 86400000) + 1 : (a % 4 === 0 ? 366 : 365);
        const dias = e ? e.dias : 0;
        const pct = Math.min(100, Math.round((dias / total) * 100));
        const estado = !dias ? 'pendiente' : pct >= 99 ? 'completo' : 'parcial';
        return { anio: a, pct, estado, titulo: e ? `${e.dias} dias consultados, ${e.con_datos} con datos` : 'Sin descargar' };
      });
    },
    restante() {
      if (!this.inicio || this.hechos <= this.hechosAlIniciar) return '';
      const porTramo = (Date.now() - this.inicio) / (this.hechos - this.hechosAlIniciar);
      const s = Math.round((porTramo * (this.tramos.length - this.hechos)) / 1000);
      return s < 60 ? `${s} s` : `${Math.round(s / 60)} min`;
    },
  },
  mounted() {
    this.cargarEstado();
    window.addEventListener('beforeunload', this.avisarSalida);
  },
  beforeDestroy() {
    this.detener = true;
    window.removeEventListener('beforeunload', this.avisarSalida);
  },
  methods: {
    cargarEstado() {
      return window.axios.get('/investigacion/clima/icc').then(({ data }) => { this.estado = data.por_anio || []; }).catch(() => {});
    },
    avisarSalida(ev) {
      if (!this.corriendo) return undefined;
      ev.preventDefault();
      ev.returnValue = '';
      return '';
    },
    /** Trimestres del rango (hasta hoy). */
    armarTramos() {
      const hoy = iso(new Date());
      const t = [];
      for (let a = this.desde; a <= this.hasta; a++) {
        [[1, 3], [4, 6], [7, 9], [10, 12]].forEach(([m1, m2]) => {
          const ini = `${a}-${String(m1).padStart(2, '0')}-01`;
          if (ini > hoy) return;
          let fin = iso(new Date(a, m2, 0));
          if (fin > hoy) fin = hoy;
          t.push({ desde: ini, hasta: fin, etiqueta: `${['ene–mar', 'abr–jun', 'jul–sep', 'oct–dic'][(m1 - 1) / 3]} ${a}` });
        });
      }
      return t;
    },
    async iniciar() {
      const nuevos = this.armarTramos();
      const mismo = this.tramos.length && nuevos.length === this.tramos.length && nuevos[0].desde === this.tramos[0].desde;
      if (!mismo) {
        this.tramos = nuevos;
        this.hechos = 0;
        this.registros = 0;
        this.dias = 0;
        this.nuevas = 0;
        this.errores = [];
      }
      this.corriendo = true;
      this.detener = false;
      this.inicio = Date.now();
      this.hechosAlIniciar = this.hechos;

      while (this.hechos < this.tramos.length && !this.detener) {
        const t = this.tramos[this.hechos];
        this.actual = t.etiqueta;
        let intento = 0;
        let ok = false;
        while (!ok && intento < 3 && !this.detener) {
          try {
            // eslint-disable-next-line no-await-in-loop
            const { data } = await window.axios.post('/investigacion/clima/icc/sincronizar', { desde: t.desde, hasta: t.hasta, tipo: 'historial' });
            this.registros += data.registros || 0;
            this.dias += data.dias || 0;
            this.nuevas += data.estaciones_nuevas || 0;
            ok = true;
          } catch (err) {
            intento++;
            const r = err.response;
            const msg = (r && r.data && r.data.message) || 'Sin respuesta del servidor';
            if (r && [401, 403, 422].includes(r.status)) {
              // Credenciales o permisos: no tiene caso reintentar.
              this.errores.push(`${t.etiqueta}: ${msg}`);
              this.detener = true;
              break;
            }
            if (intento >= 3) this.errores.push(`${t.etiqueta}: ${msg} (se omitio; vuelva a pulsar "Continuar" para reintentarlo)`);
            // eslint-disable-next-line no-await-in-loop
            else await esperar(3000 * intento);
          }
        }
        if (ok || intento >= 3) this.hechos++;
        if (this.hechos % 4 === 0) this.cargarEstado();
      }

      this.corriendo = false;
      this.actual = '';
      await this.cargarEstado();
      this.$emit('actualizado');
      if (this.hechos === this.tramos.length) {
        this.$swal.fire({ icon: 'success', title: 'Historial descargado', text: `${this.registros.toLocaleString('es-GT')} registros nuevos de ${this.dias.toLocaleString('es-GT')} dias.` });
      }
    },
    pausar() { this.detener = true; },
  },
};
</script>

<style scoped>
.hi { padding: 14px 16px; border: 1px solid #e3e7e1; border-radius: 12px; background: #fff; }
.hi-head { display: flex; gap: 14px; }
.hi-num { flex: 0 0 28px; height: 28px; border-radius: 50%; background: #73BC25; color: #fff; line-height: 28px; text-align: center; font-size: 13px; }
.hi-head h6 { margin: 3px 0 4px; color: #1f5632; font-size: 14px; font-weight: 700; }
.hi-head p { margin-bottom: 10px; color: #6b7568; font-size: 12.5px; }
.hi-anios { display: grid; grid-template-columns: repeat(auto-fill, minmax(78px, 1fr)); gap: 6px; margin-bottom: 12px; }
.hi-anio { display: flex; flex-direction: column; align-items: center; padding: 5px 4px; border: 1px solid #e3e7e1; border-radius: 8px; background: #f7f8f6; font-size: 12px; }
.hi-anio b { color: #26331f; }
.hi-anio small { color: #8a8f87; font-size: 10.5px; }
.hi-anio.is-completo { border-color: #b9dc93; background: #eef8df; }
.hi-anio.is-completo small { color: #3f7a12; font-weight: 600; }
.hi-anio.is-parcial { border-color: #f3d48c; background: #fff8e6; }
.hi-anio.is-parcial small { color: #8a6100; font-weight: 600; }
.hi-controles { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px; }
.hi-controles label { display: flex; flex-direction: column; gap: 2px; margin: 0; font-size: 11.5px; font-weight: 600; }
.hi-controles select { width: 100px; }
.hi-progreso { margin-top: 12px; }
.hi-barra { height: 10px; overflow: hidden; border-radius: 999px; background: #edf1ec; }
.hi-barra i { display: block; height: 100%; border-radius: 999px; background: #73BC25; transition: width .4s ease; }
.hi-detalle { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 6px; margin-top: 6px; color: #33402a; font-size: 12.5px; }
.hi-ok { color: #3f7a12; }
.hi-muted { color: #6b7568; font-size: 12px; }
.hi-error { margin-top: 4px; color: #b84d00; font-size: 12px; }
</style>
