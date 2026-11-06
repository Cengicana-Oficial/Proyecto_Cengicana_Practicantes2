<template>
  <div class="dp">
    <!-- Barra de acciones -->
    <div class="dp-barra">
      <template v-if="puedeEditar">
        <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="ocupado" @click="$refs.archivo.click()"><i class="fas fa-file-import"></i> Importar Excel</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="ocupado" @click="pegar"><i class="fas fa-paste"></i> Pegar desde Excel</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" @click="agregarColumna"><i class="fas fa-columns"></i> Columna</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="!columnas.length" @click="agregarFila"><i class="fas fa-plus"></i> Fila</button>
      </template>
      <a class="btn btn-outline-secondary btn-sm" :href="urlExcel(true)"><i class="fas fa-file-download"></i> Plantilla</a>
      <a v-if="filas.length" class="btn btn-outline-secondary btn-sm" :href="urlExcel(false)"><i class="fas fa-file-excel"></i> Exportar</a>
      <input ref="archivo" type="file" accept=".xlsx,.xls,.csv" hidden @change="importar">
      <span class="dp-estado" v-if="puedeEditar">
        <template v-if="sucio"><i class="fas fa-circle text-warning"></i> Cambios sin guardar</template>
        <template v-else-if="datos && datos.updated_at"><i class="fas fa-check text-success"></i> Guardado</template>
      </span>
      <button v-if="puedeEditar" type="button" class="btn btn-success btn-sm" :disabled="!sucio || ocupado" @click="guardar"><i class="fas fa-save"></i> Guardar datos</button>
    </div>

    <div v-if="!columnas.length" class="dp-vacio">
      <i class="fas fa-table"></i>
      <b>Esta prueba aún no tiene datos.</b>
      <small>Descargue la plantilla, llénela (una fila por muestra o medición; la unidad entre paréntesis: "Humedad (%)") e impórtela,
        o pegue directamente un rango copiado de Excel. Para una calibración, después elija en la gráfica la lectura del sensor (X) y el valor de laboratorio (Y).</small>
    </div>

    <template v-else>
      <!-- Grafica de calibracion / relacion entre dos variables -->
      <div class="dp-grafica" v-if="numericas.length >= 2">
        <div class="dp-grafica-ctrl">
          <h6><i class="fas fa-chart-line"></i> Relación entre variables</h6>
          <label>Eje X <small>(p. ej. lectura del sensor)</small>
            <select class="form-control form-control-sm" v-model="grafico.x" @change="sucio = puedeEditar">
              <option :value="null">Elija...</option>
              <option v-for="c in numericas" :key="c.clave" :value="c.clave">{{ etiqueta(c) }}</option>
            </select>
          </label>
          <label>Eje Y <small>(p. ej. valor de laboratorio)</small>
            <select class="form-control form-control-sm" v-model="grafico.y" @change="sucio = puedeEditar">
              <option :value="null">Elija...</option>
              <option v-for="c in numericas" :key="c.clave" :value="c.clave">{{ etiqueta(c) }}</option>
            </select>
          </label>
          <div v-if="regresion" class="dp-ajuste">
            <div class="dp-ecuacion">{{ regresion.ecuacion }}</div>
            <div class="dp-metricas">
              <span><b :class="claseR2">{{ regresion.r2.toFixed(3) }}</b>R²</span>
              <span><b>{{ fmt(regresion.rmse) }}</b>Error (RMSE)</span>
              <span><b>{{ regresion.n }}</b>Pares de datos</span>
            </div>
            <small class="text-muted">{{ textoR2 }}</small>
          </div>
          <small v-else-if="grafico.x && grafico.y" class="text-muted">Se necesitan al menos 3 filas con ambos valores.</small>
        </div>
        <div class="dp-chart"><highcharts v-if="opcionesGrafica" :options="opcionesGrafica"></highcharts>
          <p v-else class="dp-chart-vacia">Elija las columnas X y Y para ver la gráfica y la ecuación de ajuste.</p></div>
      </div>

      <!-- Resumen por columna numerica -->
      <div class="dp-resumen" v-if="numericas.length">
        <div v-for="r in resumen" :key="r.clave">
          <b>{{ r.nombre }}</b>
          <small>n {{ r.n }} · media {{ fmt(r.media) }} · mín {{ fmt(r.min) }} · máx {{ fmt(r.max) }} · DE {{ fmt(r.de) }}</small>
        </div>
      </div>

      <!-- Tabla editable -->
      <div class="dp-tabla-wrap">
        <table class="dp-tabla">
          <thead>
            <tr>
              <th class="dp-n">#</th>
              <th v-for="(c, i) in columnas" :key="c.clave">
                <template v-if="puedeEditar">
                  <input class="dp-th-nombre" v-model="c.nombre" maxlength="80" @input="sucio = true" :aria-label="`Nombre de la columna ${i + 1}`">
                  <div class="dp-th-meta">
                    <input v-model="c.unidad" maxlength="30" placeholder="unidad" @input="sucio = true" aria-label="Unidad">
                    <select v-model="c.tipo" @change="sucio = true" aria-label="Tipo de dato">
                      <option value="numero">Número</option><option value="texto">Texto</option><option value="fecha">Fecha</option>
                    </select>
                    <button type="button" title="Quitar columna" @click="quitarColumna(i)"><i class="fas fa-times"></i></button>
                  </div>
                </template>
                <template v-else>{{ etiqueta(c) }}</template>
              </th>
              <th v-if="puedeEditar" class="dp-n"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(f, r) in filasVisibles" :key="r">
              <td class="dp-n">{{ r + 1 }}</td>
              <td v-for="c in columnas" :key="c.clave" :class="{ 'is-num': c.tipo === 'numero' }">
                <input v-if="puedeEditar" :type="c.tipo === 'fecha' ? 'date' : 'text'" :inputmode="c.tipo === 'numero' ? 'decimal' : null"
                  v-model="f[c.clave]" @input="sucio = true" :aria-label="`${c.nombre}, fila ${r + 1}`">
                <span v-else>{{ c.tipo === 'fecha' ? fmtFecha(f[c.clave]) : f[c.clave] }}</span>
              </td>
              <td v-if="puedeEditar" class="dp-n"><button type="button" class="dp-quitar" title="Quitar fila" @click="quitarFila(r)"><i class="fas fa-trash"></i></button></td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="filas.length > limite" class="dp-mas">
        Mostrando {{ limite }} de {{ filas.length }} filas. <a href="#" @click.prevent="limite = filas.length">Mostrar todas</a>
      </p>
    </template>
  </div>
</template>

<script>

export default {
  name: 'DatosPrueba',
  props: {
    ensayoId: { type: Number, required: true },
    datos: { type: Object, default: null },
    puedeEditar: { type: Boolean, default: false },
  },
  data() {
    return { columnas: [], filas: [], grafico: { x: null, y: null }, sucio: false, ocupado: false, limite: 200 };
  },
  watch: {
    datos: { immediate: true, handler() { this.cargar(); } },
  },
  computed: {
    numericas() { return this.columnas.filter((c) => c.tipo === 'numero'); },
    filasVisibles() { return this.filas.slice(0, this.limite); },
    resumen() {
      return this.numericas.map((c) => {
        const v = this.filas.map((f) => this.num(f[c.clave])).filter((x) => x !== null);
        const n = v.length;
        const media = n ? v.reduce((a, b) => a + b, 0) / n : null;
        const de = n > 1 ? Math.sqrt(v.reduce((a, b) => a + (b - media) ** 2, 0) / (n - 1)) : null;
        return { clave: c.clave, nombre: this.etiqueta(c), n, media, min: n ? Math.min(...v) : null, max: n ? Math.max(...v) : null, de };
      });
    },
    pares() {
      if (!this.grafico.x || !this.grafico.y) return [];
      return this.filas.map((f) => [this.num(f[this.grafico.x]), this.num(f[this.grafico.y])]).filter((p) => p[0] !== null && p[1] !== null);
    },
    /** Ajuste lineal por minimos cuadrados: y = a·x + b, R² y RMSE. */
    regresion() {
      const p = this.pares;
      const n = p.length;
      if (n < 3) return null;
      const mx = p.reduce((s, q) => s + q[0], 0) / n;
      const my = p.reduce((s, q) => s + q[1], 0) / n;
      const sxx = p.reduce((s, q) => s + (q[0] - mx) ** 2, 0);
      if (!sxx) return null;
      const a = p.reduce((s, q) => s + (q[0] - mx) * (q[1] - my), 0) / sxx;
      const b = my - a * mx;
      const sse = p.reduce((s, q) => s + (q[1] - (a * q[0] + b)) ** 2, 0);
      const sst = p.reduce((s, q) => s + (q[1] - my) ** 2, 0);
      const r2 = sst ? 1 - sse / sst : 1;
      const cx = this.columnas.find((c) => c.clave === this.grafico.x);
      const cy = this.columnas.find((c) => c.clave === this.grafico.y);
      const signo = b < 0 ? '−' : '+';
      return {
        a, b, r2, n, rmse: Math.sqrt(sse / n),
        ecuacion: `${cy ? cy.nombre : 'y'} = ${this.fmt(a, 4)} × ${cx ? cx.nombre : 'x'} ${signo} ${this.fmt(Math.abs(b), 4)}`,
      };
    },
    claseR2() {
      if (!this.regresion) return '';
      return this.regresion.r2 >= 0.9 ? 'is-bien' : this.regresion.r2 >= 0.7 ? 'is-medio' : 'is-bajo';
    },
    textoR2() {
      const r2 = this.regresion ? this.regresion.r2 : 0;
      if (r2 >= 0.9) return 'Ajuste muy bueno: la ecuación sirve para convertir lecturas del sensor.';
      if (r2 >= 0.7) return 'Ajuste aceptable: revise puntos alejados de la línea o amplíe el rango de datos.';
      return 'Ajuste débil: la relación lineal explica poco; revise los datos o pruebe otra variable.';
    },
    opcionesGrafica() {
      if (!this.pares.length) return null;
      const cx = this.columnas.find((c) => c.clave === this.grafico.x);
      const cy = this.columnas.find((c) => c.clave === this.grafico.y);
      const series = [{ type: 'scatter', name: 'Datos', data: this.pares, color: '#2a78d6', marker: { radius: 4 } }];
      if (this.regresion) {
        const xs = this.pares.map((q) => q[0]);
        const x0 = Math.min(...xs);
        const x1 = Math.max(...xs);
        series.push({ type: 'line', name: 'Ajuste lineal', data: [[x0, this.regresion.a * x0 + this.regresion.b], [x1, this.regresion.a * x1 + this.regresion.b]],
          color: '#FF6B00', marker: { enabled: false }, enableMouseTracking: false, dashStyle: 'ShortDash' });
      }
      return {
        chart: { height: 300, zoomType: 'xy' },
        title: { text: null },
        xAxis: { title: { text: cx ? this.etiqueta(cx) : 'X' }, gridLineWidth: 1 },
        yAxis: { title: { text: cy ? this.etiqueta(cy) : 'Y' } },
        legend: { enabled: !!this.regresion },
        tooltip: { pointFormat: 'X: <b>{point.x}</b><br>Y: <b>{point.y}</b>' },
        credits: { enabled: false },
        series,
      };
    },
  },
  methods: {
    cargar() {
      const d = this.datos || {};
      this.columnas = JSON.parse(JSON.stringify(d.columnas || []));
      this.filas = JSON.parse(JSON.stringify(d.filas || []));
      this.grafico = { x: (d.grafico || {}).x || null, y: (d.grafico || {}).y || null };
      this.sucio = false;
    },
    num(v) {
      if (v === null || v === undefined || v === '') return null;
      const n = Number(String(v).replace(',', '.'));
      return Number.isFinite(n) ? n : null;
    },
    fmt(v, dec) {
      if (v === null || v === undefined || !Number.isFinite(v)) return '—';
      const d = dec !== undefined ? dec : Math.abs(v) >= 100 ? 1 : Math.abs(v) >= 1 ? 2 : 4;
      return Number(v.toFixed(d)).toLocaleString('es-GT', { maximumFractionDigits: d });
    },
    fmtFecha(v) {
      const m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
      return m ? `${m[3]}/${m[2]}/${m[1]}` : (v || '');
    },
    etiqueta(c) { return c.nombre + (c.unidad ? ` (${c.unidad})` : ''); },
    urlExcel(plantilla) {
      return `${window.axios.defaults.baseURL || ''}/investigacion/ensayos/${this.ensayoId}/datos/excel${plantilla ? '?plantilla=1' : ''}`;
    },
    claveNueva(nombre) {
      const base = String(nombre || 'columna').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 34) || 'col';
      let clave = base;
      for (let i = 2; this.columnas.some((c) => c.clave === clave); i++) clave = `${base}_${i}`;
      return clave;
    },
    agregarColumna() {
      const nombre = `Columna ${this.columnas.length + 1}`;
      this.columnas.push({ clave: this.claveNueva(nombre), nombre, unidad: null, tipo: 'numero' });
      if (!this.filas.length) this.filas.push({});
      this.sucio = true;
    },
    quitarColumna(i) {
      const c = this.columnas[i];
      if (!window.confirm(`¿Quitar la columna "${c.nombre}" y sus datos?`)) return;
      this.columnas.splice(i, 1);
      this.filas.forEach((f) => this.$delete(f, c.clave));
      if (this.grafico.x === c.clave) this.grafico.x = null;
      if (this.grafico.y === c.clave) this.grafico.y = null;
      this.sucio = true;
    },
    agregarFila() { this.filas.push({}); this.limite = Math.max(this.limite, this.filas.length); this.sucio = true; },
    quitarFila(i) { this.filas.splice(i, 1); this.sucio = true; },
    /** Pega un rango copiado de Excel (separado por tabuladores). Sin columnas, la primera fila son los encabezados. */
    pegar() {
      this.$swal.fire({
        title: 'Pegar desde Excel',
        html: `<p class="small text-left mb-1">${this.columnas.length
          ? `Las filas se agregan en el orden de las columnas actuales (${this.columnas.map((c) => this.etiqueta(c)).join(', ')}). Si la primera fila son los encabezados, se omite.`
          : 'Copie en Excel el rango con los encabezados en la primera fila (la unidad entre paréntesis) y péguelo aquí.'}</p>`,
        input: 'textarea', inputAttributes: { rows: 8, 'aria-label': 'Datos pegados' },
        showCancelButton: true, confirmButtonText: 'Agregar', cancelButtonText: 'Cancelar', confirmButtonColor: '#5a9e1a',
      }).then((r) => {
        if (!r.isConfirmed || !r.value) return;
        const lineas = r.value.replace(/\r/g, '').split('\n').filter((l) => l.trim() !== '').map((l) => l.split('\t'));
        if (!lineas.length) return;
        let datos = lineas;
        if (!this.columnas.length) {
          lineas[0].forEach((enc) => {
            const m = String(enc).trim().match(/^(.*?)\s*\(([^()]{1,30})\)\s*$/);
            const nombre = (m ? m[1] : String(enc)).trim() || `Columna ${this.columnas.length + 1}`;
            this.columnas.push({ clave: this.claveNueva(nombre), nombre, unidad: m ? m[2].trim() : null, tipo: 'texto' });
          });
          datos = lineas.slice(1);
        } else if (lineas[0].some((v) => v.trim() !== '' && this.num(v) === null)
          && lineas[0].map((v) => v.trim().toLowerCase()).some((v) => this.columnas.some((c) => c.nombre.toLowerCase() === v || this.etiqueta(c).toLowerCase() === v))) {
          datos = lineas.slice(1);
        }
        datos.forEach((l) => {
          const f = {};
          this.columnas.forEach((c, i) => { f[c.clave] = l[i] !== undefined && l[i].trim() !== '' ? l[i].trim() : null; });
          this.filas.push(f);
        });
        this.inferirTipos();
        this.limite = Math.max(this.limite, this.filas.length);
        this.sucio = true;
      });
    },
    inferirTipos() {
      this.columnas.forEach((c) => {
        const v = this.filas.map((f) => f[c.clave]).filter((x) => x !== null && x !== undefined && x !== '');
        if (v.length && c.tipo === 'texto' && v.every((x) => this.num(x) !== null)) c.tipo = 'numero';
      });
    },
    importar(ev) {
      const archivo = ev.target.files[0];
      ev.target.value = '';
      if (!archivo) return;
      const form = new FormData();
      form.append('archivo', archivo);
      this.ocupado = true;
      window.axios.post(`/investigacion/ensayos/${this.ensayoId}/datos/importar`, form).then(({ data }) => {
        this.ocupado = false;
        const mismas = this.columnas.length && data.columnas.length === this.columnas.length
          && data.columnas.every((c, i) => c.nombre.toLowerCase() === this.columnas[i].nombre.toLowerCase());
        this.$swal.fire({
          title: 'Datos del archivo',
          html: `<div class="small text-left"><p><b>${data.filas.length}</b> fila(s) y <b>${data.columnas.length}</b> columna(s):</p>
              <p>${data.columnas.map((c) => `<span class="badge badge-light mr-1">${this.esc(this.etiqueta(c))} · ${c.tipo}</span>`).join('')}</p>
              ${this.filas.length ? `<p class="mb-0">La prueba ya tiene ${this.filas.length} fila(s). ${mismas ? '¿Reemplazarlas o agregar las nuevas al final?' : 'Las columnas no coinciden: se reemplazarán los datos actuales.'}</p>` : ''}</div>`,
          showCancelButton: true,
          showDenyButton: !!(this.filas.length && mismas),
          confirmButtonText: this.filas.length ? 'Reemplazar' : 'Cargar datos',
          denyButtonText: 'Agregar al final',
          cancelButtonText: 'Cancelar',
          confirmButtonColor: '#5a9e1a',
          denyButtonColor: '#2a78d6',
        }).then((r) => {
          if (r.isConfirmed) {
            this.columnas = data.columnas;
            this.filas = data.filas;
            this.grafico = { x: null, y: null };
          } else if (r.isDenied) {
            data.filas.forEach((f) => {
              const fila = {};
              this.columnas.forEach((c, i) => { fila[c.clave] = f[data.columnas[i].clave]; });
              this.filas.push(fila);
            });
          } else return;
          this.limite = Math.max(200, this.filas.length);
          this.sucio = true;
          this.guardar();
        });
      }).catch((err) => {
        this.ocupado = false;
        this.$swal.fire({ icon: 'error', title: 'No se pudo leer el archivo', text: (err.response && err.response.data && err.response.data.message) || 'Revise el formato del archivo.' });
      });
    },
    guardar() {
      this.ocupado = true;
      return window.axios.put(`/investigacion/ensayos/${this.ensayoId}/datos`, { columnas: this.columnas, filas: this.filas, grafico: this.grafico })
        .then(({ data }) => {
          this.$emit('guardado', data);
          this.$swal.fire({ toast: true, position: 'top-end', icon: 'success', title: `Datos guardados (${data.filas.length} filas)`, timer: 2000, showConfirmButton: false });
        })
        .catch((err) => {
          const r = err.response;
          const msg = r && r.status === 422 && r.data.errors ? Object.values(r.data.errors).flat().join(' ') : 'No se pudieron guardar los datos.';
          this.$swal.fire({ icon: 'error', title: 'No se guardó', text: msg });
        })
        .finally(() => { this.ocupado = false; });
    },
    esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; },
  },
};
</script>

<style scoped>
.dp-barra { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-bottom: 12px; }
.dp-estado { margin-left: auto; font-size: 12px; color: #5f6b66; }
.dp-estado i { font-size: 8px; vertical-align: middle; }
.dp-vacio { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 34px 16px; border: 1px dashed #ced2d5; border-radius: 12px; text-align: center; color: #5f6b66; }
.dp-vacio i { font-size: 26px; color: #73BC25; }
.dp-vacio b { color: #1f2a1a; }
.dp-vacio small { max-width: 620px; }
.dp-grafica { display: grid; grid-template-columns: 280px minmax(0, 1fr); gap: 12px; margin-bottom: 12px; padding: 12px; border: 1px solid #e3e7e4; border-radius: 12px; background: #fff; }
.dp-grafica-ctrl h6 { font-size: 13px; font-weight: 700; margin-bottom: 8px; }
.dp-grafica-ctrl h6 i { color: #5a9e1a; }
.dp-grafica-ctrl label { display: block; margin-bottom: 8px; font-size: 12px; font-weight: 600; }
.dp-grafica-ctrl label small { font-weight: 400; color: #8a9396; }
.dp-ajuste { margin-top: 6px; padding: 10px; border-radius: 10px; background: #f6faf1; }
.dp-ecuacion { font-family: 'Space Grotesk', monospace; font-size: 12.5px; font-weight: 700; color: #1f2a1a; word-break: break-word; }
.dp-metricas { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin: 8px 0 6px; }
.dp-metricas span { display: grid; font-size: 10.5px; color: #5f6b66; }
.dp-metricas b { font-size: 16px; color: #1f2a1a; }
.dp-metricas b.is-bien { color: #3f7a12; } .dp-metricas b.is-medio { color: #8a6d00; } .dp-metricas b.is-bajo { color: #c24f00; }
.dp-chart { min-width: 0; }
.dp-chart-vacia { display: grid; place-items: center; height: 100%; min-height: 200px; margin: 0; color: #8a9396; font-size: 12.5px; text-align: center; }
.dp-resumen { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 6px; margin-bottom: 10px; }
.dp-resumen div { padding: 6px 10px; border: 1px solid #edf1ec; border-radius: 8px; background: #fbfcfa; font-size: 12px; }
.dp-resumen b, .dp-resumen small { display: block; }
.dp-resumen small { color: #5f6b66; font-size: 11px; }
.dp-tabla-wrap { overflow: auto; max-height: 520px; border: 1px solid #e3e7e4; border-radius: 10px; background: #fff; }
.dp-tabla { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.dp-tabla th { position: sticky; top: 0; z-index: 1; padding: 6px; background: #f6f8f5; border-bottom: 1px solid #e3e7e4; vertical-align: top; min-width: 130px; font-weight: 700; }
.dp-tabla td { padding: 0; border-bottom: 1px solid #f1f3f0; border-right: 1px solid #f6f7f5; }
.dp-tabla td span { display: block; padding: 6px 8px; }
.dp-tabla td.is-num input, .dp-tabla td.is-num span { text-align: right; }
.dp-tabla input { width: 100%; padding: 5px 8px; border: 0; background: transparent; font-size: 12.5px; }
.dp-tabla input:focus { outline: 2px solid #A3D300; outline-offset: -2px; background: #fbfdf7; }
.dp-tabla .dp-n { width: 34px; min-width: 34px; text-align: center; color: #8a9396; font-size: 11px; }
.dp-th-nombre { font-weight: 700; border-bottom: 1px dashed #ced2d5 !important; }
.dp-th-meta { display: flex; gap: 2px; margin-top: 2px; }
.dp-th-meta input, .dp-th-meta select { flex: 1; min-width: 0; padding: 1px 4px; border: 1px solid #e3e7e4; border-radius: 4px; background: #fff; font-size: 11px; font-weight: 400; }
.dp-th-meta button, .dp-quitar { border: 0; background: none; color: #8a9396; font-size: 11px; cursor: pointer; }
.dp-th-meta button:hover, .dp-quitar:hover { color: #c24f00; }
.dp-mas { margin: 6px 0 0; font-size: 12px; color: #5f6b66; }
@media (max-width: 760px) { .dp-grafica { grid-template-columns: 1fr; } .dp-estado { margin-left: 0; width: 100%; } }
</style>
