<template>
  <div class="aa">
    <!-- Resumen por zafra: activos, nuevos y finalizados frente a la zafra anterior -->
    <div class="aa-zafras">
      <button
        v-for="z in resumenZafras" :key="z.zafra" type="button" class="aa-zafra"
        :class="{ 'is-on': zafraSel === z.zafra, 'is-actual': z.zafra === actual }"
        :title="zafraSel === z.zafra ? 'Quitar resaltado' : 'Resaltar esta zafra'"
        @click="$emit('update:zafraSel', zafraSel === z.zafra ? '' : z.zafra)"
      >
        <span class="aa-zafra-nom">Zafra {{ z.zafra }}<em v-if="z.zafra === actual">actual</em></span>
        <b>{{ z.activos }}</b><small>ensayos activos</small>
        <span class="aa-zafra-det">
          <i class="is-nuevo">+{{ z.nuevos }} nuevos</i>
          <i class="is-fin">{{ z.finalizan }} finalizan</i>
          <i v-if="z.planificados" class="is-plan">{{ z.planificados }} planificados</i>
        </span>
        <small class="aa-zafra-proy">{{ z.proyectos }} proyectos · {{ z.proyectosNuevos }} nuevos</small>
      </button>
    </div>

    <div class="aa-barra">
      <div class="aa-segmento" role="group" aria-label="Agrupar por">
        <span>Agrupar por</span>
        <button v-for="a in AGRUPAR" :key="a.id" type="button" :class="{ 'is-on': agrupar === a.id }" @click="agrupar = a.id"><i :class="a.icon"></i> {{ a.label }}</button>
      </div>
      <label class="aa-check"><input v-model="soloRepetidos" type="checkbox"> {{ agrupar === 'localidad' ? 'Solo localidades con ensayos en varias zafras' : 'Solo proyectos con ensayos en varias zafras' }}</label>
      <div class="aa-leyenda">
        <span v-for="(s, id) in leyenda" :key="id"><i :style="{ background: s.fondo, borderColor: s.color }"></i>{{ s.texto }}</span>
      </div>
    </div>

    <div v-if="!grupos.length" class="aa-vacio"><icono-cana :tamano="40" apagado></icono-cana><b>No hay ensayos con fechas para ubicar en el tiempo.</b><span>Registra la fecha de siembra o corte de cada ensayo.</span></div>

    <div v-else class="aa-tabla-wrap">
      <table class="aa-tabla">
        <thead>
          <tr>
            <th class="aa-col-nom">{{ agrupar === 'localidad' ? 'Localidad / ensayo' : 'Proyecto / ensayo' }}</th>
            <th v-for="z in zafras" :key="z" :class="{ 'is-sel': zafraSel === z, 'is-actual': z === actual }">{{ z }}</th>
          </tr>
        </thead>
        <tbody v-for="g in grupos" :key="g.clave">
          <tr class="aa-grupo">
            <th class="aa-col-nom"><div class="aa-grupo-cab">
              <button type="button" class="aa-plegar" :aria-expanded="!plegados.includes(g.clave)" @click="plegar(g.clave)"><i class="fas" :class="plegados.includes(g.clave) ? 'fa-chevron-right' : 'fa-chevron-down'"></i></button>
              <span class="aa-grupo-nom" :title="g.titulo"><b>{{ g.titulo }}</b><small>{{ g.subtitulo }}</small></span>
              <span class="aa-badge" :title="`${g.ensayos.length} ensayos en ${g.zafrasConEnsayos} zafras`">{{ g.ensayos.length }} · {{ g.zafrasConEnsayos }} zafras</span>
            </div></th>
            <td v-for="z in zafras" :key="z" :class="{ 'is-sel': zafraSel === z }">
              <span v-if="g.situacion[z]" class="aa-proy-barra" :style="estilo(g.situacion[z])" :title="`${g.titulo} · ${SITUACIONES[g.situacion[z]].texto} en ${z}`"></span>
              <span v-if="g.conteo[z]" class="aa-conteo">{{ g.conteo[z] }}</span>
            </td>
          </tr>
          <template v-if="!plegados.includes(g.clave)">
            <tr v-for="e in g.ensayos" :key="e.id" class="aa-ensayo">
              <th class="aa-col-nom">
                <a href="#" @click.prevent="$emit('ver', e)">{{ e.codigo }}</a>
                <small>{{ detalleEnsayo(e) }}</small>
              </th>
              <td v-for="z in zafras" :key="z" :class="{ 'is-sel': zafraSel === z }">
                <span v-if="e.situacion[z]" class="aa-celda" :style="estilo(e.situacion[z])" :title="tituloCelda(e, z)">{{ SITUACIONES[e.situacion[z]].texto }}<em v-if="e.ciclo && (e.situacion[z] === 'nuevo' || e.situacion[z] === 'nuevo-finaliza')"> · {{ e.ciclo }}</em></span>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
    <p class="aa-nota">La zafra va del 1 de noviembre al 31 de octubre. Cada ensayo se ubica desde su siembra o corte (si no tiene, desde su registro) hasta su cosecha; los ensayos sin cosecha siguen activos hasta la zafra actual.</p>
  </div>
</template>

<script>
import { zafraDe, zafraActual, zafrasEntre, periodoEnsayo, situacionEnZafra, SITUACIONES } from '../../../zafra';

const AGRUPAR = [
  { id: 'proyecto', label: 'Proyecto', icon: 'fas fa-folder-open' },
  { id: 'localidad', label: 'Localidad', icon: 'fas fa-map-marker-alt' },
];
const normalizar = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').trim().toLowerCase();

export default {
  name: 'EnsayosAnioConAnio',
  props: {
    ensayos: { type: Array, default: () => [] },
    zafraSel: { type: String, default: '' },
  },
  data() {
    let agrupar = 'proyecto';
    try { agrupar = localStorage.getItem('ensayos-anio-agrupar') || 'proyecto'; } catch (e) { /* sin almacenamiento */ }
    return { AGRUPAR, SITUACIONES, agrupar, soloRepetidos: false, plegados: [], actual: zafraActual() };
  },
  computed: {
    leyenda() { return Object.fromEntries(Object.entries(SITUACIONES).filter(([id]) => id !== 'nuevo-finaliza')); },
    conPeriodo() {
      return this.ensayos.map((e) => ({ e, periodo: periodoEnsayo(e) })).filter((x) => x.periodo.inicio);
    },
    // Desde la primera zafra con ensayos hasta la actual (o la ultima planificada).
    zafras() {
      if (!this.conPeriodo.length) return [];
      const inicios = this.conPeriodo.map((x) => zafraDe(x.periodo.inicio)).sort();
      const ultima = [inicios[inicios.length - 1], this.actual].sort().pop();
      return zafrasEntre(inicios[0], ultima);
    },
    filas() {
      return this.conPeriodo.map(({ e, periodo }) => ({
        ...e, situacion: Object.fromEntries(this.zafras.map((z) => [z, situacionEnZafra(periodo, z)]).filter(([, s]) => s)),
      }));
    },
    grupos() {
      const mapa = new Map();
      this.filas.forEach((e) => {
        const [clave, titulo, subtitulo] = this.agrupar === 'localidad'
          ? [`${e.ingenio_id || ''}|${normalizar(e.finca)}`, e.finca || 'Sin finca registrada', (e.ingenio && e.ingenio.nombre) || 'Sin ingenio']
          : [`p${e.proyecto_id || 0}`, e.proyecto ? `${e.proyecto.codigo} · ${e.proyecto.nombre}` : 'Sin proyecto', e.proyecto && e.proyecto.programa ? `${e.proyecto.programa.sigla} · ${e.proyecto.programa.nombre}` : ''];
        if (!mapa.has(clave)) mapa.set(clave, { clave, titulo, subtitulo, ensayos: [] });
        mapa.get(clave).ensayos.push(e);
      });
      const orden = ['nuevo-finaliza', 'nuevo', 'planificado', 'finaliza', 'continua'];
      return [...mapa.values()].map((g) => {
        const situacion = {}; const conteo = {};
        this.zafras.forEach((z) => {
          const s = g.ensayos.map((e) => e.situacion[z]).filter(Boolean);
          if (!s.length) return;
          conteo[z] = s.length;
          // El grupo es "nuevo" si empieza en esta zafra; si no, toma la situacion mas reciente.
          const zAnt = this.zafras[this.zafras.indexOf(z) - 1];
          const venia = zAnt && g.ensayos.some((e) => e.situacion[zAnt]);
          situacion[z] = venia ? (s.every((x) => x === 'finaliza' || x === 'nuevo-finaliza') ? 'finaliza' : 'continua') : orden.find((o) => s.includes(o));
        });
        const zafrasConEnsayos = new Set(g.ensayos.map((e) => zafraDe(periodoEnsayo(e).inicio))).size;
        g.ensayos.sort((a, b) => String(periodoEnsayo(a).inicio).localeCompare(String(periodoEnsayo(b).inicio)));
        return { ...g, situacion, conteo, zafrasConEnsayos };
      })
        .filter((g) => !this.soloRepetidos || g.zafrasConEnsayos > 1)
        .sort((a, b) => a.titulo.localeCompare(b.titulo, 'es', { numeric: true }));
    },
    resumenZafras() {
      return this.zafras.map((z, i) => {
        const activos = this.filas.filter((e) => e.situacion[z]);
        const cuenta = (...s) => activos.filter((e) => s.includes(e.situacion[z])).length;
        const proyectos = new Set(activos.map((e) => e.proyecto_id));
        const antes = new Set(i ? this.filas.filter((e) => e.situacion[this.zafras[i - 1]]).map((e) => e.proyecto_id) : []);
        return {
          zafra: z, activos: activos.length, nuevos: cuenta('nuevo', 'nuevo-finaliza'), finalizan: cuenta('finaliza', 'nuevo-finaliza'), planificados: cuenta('planificado'),
          proyectos: proyectos.size, proyectosNuevos: [...proyectos].filter((p) => !antes.has(p)).length,
        };
      }).reverse();
    },
  },
  watch: {
    agrupar(v) { try { localStorage.setItem('ensayos-anio-agrupar', v); } catch (e) { /* sin almacenamiento */ } },
  },
  methods: {
    plegar(clave) { this.plegados = this.plegados.includes(clave) ? this.plegados.filter((c) => c !== clave) : [...this.plegados, clave]; },
    estilo(s) { const x = SITUACIONES[s]; return { background: x.fondo, color: x.color, borderColor: x.color }; },
    detalleEnsayo(e) {
      return (this.agrupar === 'localidad'
        ? [e.lote && `Lote ${e.lote}`, e.proyecto && e.proyecto.codigo, e.variedad]
        : [[e.finca, e.lote].filter(Boolean).join(' / '), e.variedad]).filter(Boolean).join(' · ');
    },
    tituloCelda(e, z) {
      const p = periodoEnsayo(e); const f = (d) => (d ? d.split('-').reverse().join('/') : '—');
      return `${e.codigo} · ${SITUACIONES[e.situacion[z]].texto} en zafra ${z}\nSiembra/corte: ${f(e.fecha_siembra)}${e.ciclo ? ' (' + e.ciclo + ')' : ''}\nCosecha: ${p.fin ? f(p.fin) : 'pendiente'}\nEstado: ${e.estado}`;
    },
  },
};
</script>

<style scoped>
.aa{display:flex;flex-direction:column;gap:14px}
.aa-zafras{display:flex;gap:10px;overflow-x:auto;padding-bottom:4px}
.aa-zafra{flex:0 0 210px;display:flex;flex-direction:column;align-items:flex-start;gap:2px;padding:12px 14px;text-align:left;background:#fff;border:1px solid #e1e7dc;border-radius:12px;color:#29382f}
.aa-zafra:hover{border-color:#a3d300}.aa-zafra.is-on{border-color:#73bc25;box-shadow:0 0 0 2px rgba(115,188,37,.25)}
.aa-zafra.is-actual{background:#f7fbf1}
.aa-zafra-nom{font-size:12px;font-weight:700;color:#527443}.aa-zafra-nom em{margin-left:6px;padding:1px 7px;border-radius:8px;background:#73bc25;color:#fff;font-size:10px;font-style:normal}
.aa-zafra>b{font-size:24px;line-height:1.1}.aa-zafra>small{font-size:11px;color:#69766e}
.aa-zafra-det{display:flex;flex-wrap:wrap;gap:4px 8px;margin-top:4px}.aa-zafra-det i{font-style:normal;font-size:11px;font-weight:600}
.aa-zafra-det .is-nuevo{color:#3f7a12}.aa-zafra-det .is-fin{color:#2a78d6}.aa-zafra-det .is-plan{color:#8a6a00}
.aa-zafra .aa-zafra-proy{margin-top:3px}
.aa-barra{display:flex;align-items:center;flex-wrap:wrap;gap:12px 20px;padding:10px 14px;background:#fff;border:1px solid #e1e7dc;border-radius:10px}
.aa-segmento{display:flex;align-items:center;gap:6px;font-size:12px;color:#69766e}
.aa-segmento button{border:1px solid #cfdcc6;background:#fff;border-radius:7px;padding:4px 10px;font-size:12px;color:#4d5a48}
.aa-segmento button.is-on{background:#73bc25;border-color:#73bc25;color:#fff;font-weight:600}
.aa-check{display:flex;align-items:center;gap:6px;margin:0;font-size:12px;font-weight:500}.aa-check input{accent-color:#73bc25}
.aa-leyenda{display:flex;flex-wrap:wrap;gap:12px;margin-left:auto;font-size:11px;color:#4d5a48}
.aa-leyenda i{display:inline-block;width:12px;height:12px;margin-right:4px;border:1px solid;border-radius:3px;vertical-align:-2px}
.aa-tabla-wrap{overflow-x:auto;background:#fff;border:1px solid #e1e7dc;border-radius:12px}
.aa-tabla{width:100%;border-collapse:separate;border-spacing:0;font-size:12px}
.aa-tabla th,.aa-tabla td{padding:6px 8px;border-bottom:1px solid #eef2eb;text-align:center;white-space:nowrap}
.aa-tabla thead th{position:sticky;top:0;z-index:1;background:#f4f8ef;font-size:11px;font-weight:700;color:#4d5a48}
.aa-tabla thead th.is-actual{color:#3f7a12}
.aa-tabla .is-sel{background:#fbfde9}.aa-tabla thead th.is-sel{background:#eef7c8}
.aa-col-nom{position:sticky;left:0;z-index:1;min-width:260px;max-width:340px;text-align:left!important;background:#fff;white-space:normal!important}
.aa-tabla thead .aa-col-nom{z-index:2;background:#f4f8ef}
.aa-grupo th,.aa-grupo td{background:#f8faf6;border-top:1px solid #e1e7dc}
.aa-grupo .aa-col-nom{background:#f8faf6}.aa-grupo-cab{display:flex;align-items:center;gap:8px}
.aa-plegar{border:0;background:none;padding:0 2px;color:#69766e}
.aa-grupo-nom{flex:1;min-width:0}.aa-grupo-nom b,.aa-grupo-nom small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.aa-grupo-nom small{font-size:11px;font-weight:400;color:#69766e}
.aa-badge{flex:none;padding:1px 8px;border-radius:9px;background:#e6f2d8;color:#3f7a12;font-size:10px;font-weight:700}
.aa-grupo td{position:relative}
.aa-proy-barra{position:absolute;left:0;right:0;top:50%;height:8px;margin-top:-4px;border-top:1px solid;border-bottom:1px solid}
.aa-conteo{position:relative;padding:0 6px;border-radius:8px;background:#fff;font-size:10px;font-weight:700;color:#3f5a2f;box-shadow:0 0 0 1px #dce8d3}
.aa-ensayo .aa-col-nom{padding-left:34px;font-weight:400}
.aa-ensayo .aa-col-nom a{font-weight:700;color:#3f7a12}
.aa-ensayo .aa-col-nom small{display:block;color:#69766e;font-size:11px}
.aa-celda{display:inline-block;min-width:92px;padding:2px 8px;border:1px solid;border-radius:6px;font-size:11px;font-weight:600}
.aa-celda em{font-style:normal;font-weight:400}
.aa-vacio{display:flex;flex-direction:column;align-items:center;gap:6px;padding:40px;background:#fff;border:1px dashed #ced2d5;border-radius:12px;color:#69766e}
.aa-nota{font-size:11px;color:#69766e;margin:0}
</style>
