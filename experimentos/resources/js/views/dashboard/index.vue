<template>
  <div class="db">
    <!-- ============ Contexto ============ -->
    <div class="db-contexto">
      <span><i class="fas fa-layer-group"></i><b>{{ resumen.programas || 0 }}</b> programas</span>
      <span><i class="fas fa-folder-open"></i><b>{{ resumen.proyectos || 0 }}</b> proyectos</span>
      <span><i class="fas fa-industry"></i><b>{{ resumen.ingenios || 0 }}</b> ingenios</span>
      <span><i class="fas fa-user-tie"></i><b>{{ resumen.investigadores || 0 }}</b> responsables</span>
    </div>

    <!-- ============ Flujo de trabajo del ensayo ============ -->
    <section class="db-flujo" aria-label="Flujo de trabajo de los ensayos">
      <template v-for="(f, i) in flujo">
        <component
          :is="f.url ? 'a' : 'div'" :key="f.clave" :href="f.url"
          class="db-paso" :class="['is-' + f.clave, { 'is-link': f.url }]"
        >
          <div class="db-paso-top">
            <span class="db-paso-icono"><i :class="f.icono"></i></span>
            <span class="db-paso-n">{{ i + 1 }}</span>
          </div>
          <strong class="db-paso-valor">{{ n(f.valor) }}</strong>
          <span class="db-paso-titulo">{{ f.titulo }}</span>
          <small class="db-paso-detalle">{{ f.detalle }}</small>
          <svg v-if="f.serie" class="db-spark" viewBox="0 0 120 28" preserveAspectRatio="none" role="img" :aria-label="`${f.serieNombre} por mes, ultimos 12 meses`">
            <g v-for="(v, j) in f.serie" :key="j">
              <rect :x="j * 10 + 1" :y="28 - alto(v, f.serie)" width="8" :height="alto(v, f.serie)" rx="1.5" class="db-bar" :class="{ 'is-cero': !v }" />
              <rect :x="j * 10" y="0" width="10" height="28" class="db-spark-hit"><title>{{ mesLargo(meses[j]) }}: {{ n(v) }} {{ f.serieNombre.toLowerCase() }}</title></rect>
            </g>
          </svg>
          <div v-else class="db-spark-vacio"></div>
          <span v-if="f.url" class="db-paso-ir">Ver <i class="fas fa-arrow-right"></i></span>
        </component>
        <i v-if="i < flujo.length - 2" :key="f.clave + '-flecha'" class="fas fa-chevron-right db-flecha" aria-hidden="true"></i>
        <span v-else-if="i === flujo.length - 2" :key="f.clave + '-sep'" class="db-sep" aria-hidden="true"></span>
      </template>
    </section>
    <p class="db-nota">Barras: actividad de los ultimos 12 meses ({{ mesCorto(meses[0]) }} – {{ mesCorto(meses[meses.length - 1]) }}). Pase el cursor para ver cada mes.</p>

    <div class="db-grid">
      <!-- ============ Requiere atencion ============ -->
      <section class="db-card">
        <header class="db-card-h">
          <h3><i class="fas fa-bell"></i> Requiere atencion</h3>
          <span v-if="atencion.length" class="db-badge">{{ atencion.length }}</span>
        </header>
        <ul v-if="atencion.length" class="db-alertas">
          <li v-for="(a, i) in atencion" :key="i" :class="'is-' + a.nivel">
            <span class="db-alerta-icono"><i :class="a.icono"></i></span>
            <div>
              <span class="db-nivel">{{ NIVELES[a.nivel] }}</span>
              <b>{{ a.texto }}</b>
              <small>{{ a.detalle }}</small>
            </div>
            <a v-if="a.url" :href="a.url" class="db-ir" :title="'Ir: ' + a.texto"><i class="fas fa-arrow-right"></i></a>
          </li>
        </ul>
        <div v-else class="db-vacio"><i class="fas fa-check-circle"></i><b>Todo al dia.</b><small>No hay pendientes entre las etapas.</small></div>
      </section>

      <!-- ============ Ensayos en campo ============ -->
      <section class="db-card is-ancho">
        <header class="db-card-h">
          <h3><i class="fas fa-seedling"></i> Ensayos en campo</h3>
          <small>Avance de cada ensayo en el flujo</small>
        </header>
        <div v-if="enCurso.length" class="db-tabla-wrap">
          <table class="db-tabla">
            <thead>
              <tr>
                <th>Ensayo</th>
                <th class="text-right">Evaluac.</th>
                <th title="Muestras con resultados del Laboratorio / muestras generadas">Resultados</th>
                <th>Ult. actividad</th>
                <th><span class="sr-only">Accesos</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="e in enCurso" :key="e.id">
                <td>
                  <a v-if="e.urls.detalle" :href="e.urls.detalle" class="db-cod">{{ e.codigo }}</a>
                  <b v-else class="db-cod">{{ e.codigo }}</b>
                  <small class="db-sub">{{ e.nombre || e.proyecto || '—' }}</small>
                  <small class="db-sub">
                    <template v-if="e.edad_meses !== null">{{ e.edad_meses }} meses de cultivo</template>
                    <span v-else title="Falta la fecha de siembra o corte">Edad sin calcular</span>
                    <template v-if="e.cosecha_prevista"> · cosecha {{ fecha(e.cosecha_prevista) }}</template>
                  </small>
                </td>
                <td class="text-right">
                  <b>{{ n(e.evaluaciones) }}</b>
                  <small class="db-sub">{{ e.parcelas }} parcelas</small>
                </td>
                <td>
                  <div class="db-avance" :title="`${e.muestras_completadas} de ${e.muestras} muestras con resultados`">
                    <div class="db-avance-barra"><i :style="{ width: pct(e.muestras_completadas, e.muestras) + '%' }"></i></div>
                    <small><b>{{ e.muestras_completadas }}</b>/{{ e.muestras }}</small>
                  </div>
                </td>
                <td>
                  <span :class="{ 'db-alerta-txt': e.sin_actividad }">
                    <i v-if="e.sin_actividad" class="fas fa-exclamation-triangle"></i>
                    {{ e.ultima_actividad ? fecha(e.ultima_actividad) : 'Sin actividad' }}
                  </span>
                </td>
                <td class="db-accesos">
                  <a v-if="e.urls.evaluaciones" :href="e.urls.evaluaciones" title="Evaluaciones del ensayo"><i class="fas fa-clipboard-check"></i></a>
                  <a v-if="e.urls.muestras" :href="e.urls.muestras" title="Muestras y resultados del ensayo"><i class="fas fa-vial"></i></a>
                  <a v-if="e.urls.clima" :href="e.urls.clima" title="Clima del ensayo"><i class="fas fa-cloud-sun-rain"></i></a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="db-vacio"><i class="fas fa-seedling"></i><b>No hay ensayos en campo.</b></div>
      </section>

      <!-- ============ Ensayos por programa ============ -->
      <section class="db-card">
        <header class="db-card-h">
          <h3><i class="fas fa-layer-group"></i> Ensayos por programa</h3>
        </header>
        <div class="db-leyenda">
          <span v-for="s in ESTADOS" :key="s.id"><i :style="{ background: s.color }"></i>{{ s.id }}</span>
        </div>
        <ul v-if="porPrograma.length" class="db-programas">
          <li v-for="p in porPrograma" :key="p.programa">
            <div class="db-prog-h"><span>{{ p.programa }}</span><b>{{ p.total }}</b></div>
            <div class="db-apilada">
              <component
                :is="urlEstado ? 'a' : 'span'" v-for="s in ESTADOS.filter((x) => p[x.id])" :key="s.id"
                :href="urlEstado ? `${urlEstado}?estado=${encodeURIComponent(s.id)}` : null"
                :style="{ flexGrow: p[s.id], background: s.color, color: s.texto }"
                :title="`${p.programa}: ${p[s.id]} ${s.id.toLowerCase()}`"
              >{{ p[s.id] }}</component>
            </div>
          </li>
        </ul>
        <div v-else class="db-vacio"><i class="fas fa-layer-group"></i><b>Sin ensayos registrados.</b></div>
      </section>

      <!-- ============ Actividad reciente ============ -->
      <section class="db-card is-ancho">
        <header class="db-card-h">
          <h3><i class="fas fa-stream"></i> Actividad reciente</h3>
          <small>Bitacora, capturas, evaluaciones, muestras y resultados</small>
        </header>
        <ol v-if="actividad.length" class="db-timeline">
          <li v-for="(a, i) in actividad" :key="i" :class="'is-' + a.tipo">
            <span class="db-tl-icono"><i :class="a.icono"></i></span>
            <div class="db-tl-cuerpo">
              <div>
                <b>{{ a.titulo }}</b>
                <span class="db-chip">{{ a.ensayo }}</span>
              </div>
              <small>{{ a.detalle }}</small>
            </div>
            <time :datetime="a.cuando">{{ relativo(a.cuando) }}</time>
            <a v-if="a.url" :href="a.url" class="db-ir" title="Abrir"><i class="fas fa-arrow-right"></i></a>
          </li>
        </ol>
        <div v-else class="db-vacio"><i class="fas fa-stream"></i><b>Sin actividad registrada.</b></div>
      </section>
    </div>
  </div>
</template>

<script>
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const MESES_LARGOS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

export default {
  name: 'DashboardIndex',
  props: {
    resumen: { type: Object, default: () => ({}) },
    meses: { type: Array, default: () => [] },
    flujo: { type: Array, default: () => [] },
    atencion: { type: Array, default: () => [] },
    enCurso: { type: Array, default: () => [] },
    porPrograma: { type: Array, default: () => [] },
    actividad: { type: Array, default: () => [] },
  },
  data() {
    return {
      NIVELES: { alerta: 'Atender', aviso: 'Revisar', info: 'Completar' },
      // Paleta validada (CVD): el par ambar/verde lleva etiqueta directa y separacion.
      ESTADOS: [
        { id: 'Planificado', color: '#eda100', texto: '#1f2a12' },
        { id: 'En campo', color: '#5a9e1a', texto: '#ffffff' },
        { id: 'Finalizado', color: '#2a78d6', texto: '#ffffff' },
      ],
    };
  },
  computed: {
    urlEstado() {
      const f = this.flujo.find((x) => x.clave === 'ensayos');
      return f && f.url ? f.url : null;
    },
  },
  methods: {
    n(x) { return Number(x || 0).toLocaleString('es-GT'); },
    pct(a, b) { return b ? Math.round((a / b) * 100) : 0; },
    alto(v, serie) {
      const max = Math.max(...serie, 1);
      return v ? Math.max(3, (v / max) * 26) : 1.5;
    },
    mesCorto(m) { if (!m) return ''; const [y, mm] = m.split('-'); return `${MESES[Number(mm) - 1]} ${y.slice(2)}`; },
    mesLargo(m) { if (!m) return ''; const [y, mm] = m.split('-'); return `${MESES_LARGOS[Number(mm) - 1]} ${y}`; },
    fecha(f) { if (!f) return ''; const [y, m, d] = String(f).slice(0, 10).split('-'); return `${d}/${m}/${y}`; },
    relativo(c) {
      const t = Date.parse(String(c).replace(' ', 'T'));
      if (Number.isNaN(t)) return '';
      const dias = Math.floor((Date.now() - t) / 86400000);
      if (dias <= 0) return 'Hoy';
      if (dias === 1) return 'Ayer';
      if (dias < 30) return `Hace ${dias} dias`;
      return this.fecha(c);
    },
  },
};
</script>

<style scoped>
.db { max-width: 100%; overflow-x: hidden; --verde: #73BC25; --verde-osc: #4e8a12; --verde2: #A3D300; --amarillo: #FFCC00; --naranja: #FF6B00; --gris: #CED2D5; --tinta: #1f2a1a; --suave: #5f6b66; --borde: #e3e7e4; }

/* Contexto */
.db-contexto { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.db-contexto span { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; background: #fff; border: 1px solid var(--borde); border-radius: 999px; font-size: 13px; color: var(--suave); }
.db-contexto i { color: var(--verde); font-size: 12px; }
.db-contexto b { color: var(--tinta); }

/* Flujo */
.db-flujo { display: flex; align-items: stretch; gap: 6px; }
.db-paso { flex: 1 1 0; min-width: 0; display: flex; flex-direction: column; padding: 14px 14px 12px; background: #fff; border: 1px solid var(--borde); border-top: 4px solid var(--verde); border-radius: 12px; color: var(--tinta); text-decoration: none; transition: box-shadow .15s, transform .15s; }
.db-paso.is-link:hover { box-shadow: 0 6px 18px rgba(31, 42, 26, .10); transform: translateY(-2px); color: var(--tinta); text-decoration: none; }
.db-paso.is-link:focus-visible { outline: 3px solid var(--verde2); outline-offset: 2px; }
.db-paso.is-formularios { border-top-color: var(--verde2); }
.db-paso.is-muestras { border-top-color: var(--naranja); }
.db-paso.is-resultados { border-top-color: var(--verde-osc); }
.db-paso.is-clima { border-top-color: var(--gris); background: #fbfcfb; }
.db-paso-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.db-paso-icono { width: 34px; height: 34px; border-radius: 9px; display: grid; place-items: center; background: #eef7e3; color: var(--verde-osc); }
.db-paso.is-muestras .db-paso-icono { background: #fff0e5; color: #c24f00; }
.db-paso.is-clima .db-paso-icono { background: #eef1f2; color: var(--suave); }
.db-paso-n { font-size: 11px; font-weight: 700; color: var(--suave); background: #f1f3f2; border-radius: 999px; padding: 1px 8px; }
.db-paso.is-clima .db-paso-n { visibility: hidden; }
.db-paso-valor { font-size: 28px; line-height: 1.1; font-weight: 700; }
.db-paso-titulo { font-size: 13px; font-weight: 600; margin-top: 2px; }
.db-paso-detalle { color: var(--suave); font-size: 11.5px; line-height: 1.35; margin-top: 3px; min-height: 31px; }
.db-spark { width: 100%; height: 28px; margin-top: 8px; display: block; }
.db-spark .db-bar { fill: var(--verde); }
.db-paso.is-muestras .db-bar { fill: var(--naranja); }
.db-paso.is-resultados .db-bar { fill: var(--verde-osc); }
.db-paso .db-bar.is-cero { fill: var(--gris); }
.db-spark .db-spark-hit { fill: transparent; }
.db-spark g:hover .db-bar { opacity: .7; }
.db-spark-vacio { height: 36px; }
.db-paso-ir { margin-top: auto; padding-top: 8px; font-size: 12px; font-weight: 600; color: var(--verde-osc); }
.db-paso-ir i { font-size: 10px; margin-left: 2px; transition: margin .15s; }
.db-paso.is-link:hover .db-paso-ir i { margin-left: 6px; }
.db-flecha { align-self: center; color: var(--gris); font-size: 14px; flex: none; }
.db-sep { flex: none; width: 1px; margin: 8px 6px; background: var(--borde); }
.db-nota { font-size: 11.5px; color: var(--suave); margin: 6px 2px 16px; }

/* Tarjetas */
.db-grid { display: grid; grid-template-columns: minmax(0, 5fr) minmax(0, 7fr); gap: 16px; }
.db-card { background: #fff; border: 1px solid var(--borde); border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; }
.db-card-h { display: flex; align-items: baseline; gap: 10px; padding: 14px 18px; border-bottom: 1px solid var(--borde); }
.db-card-h h3 { font-size: 15px; font-weight: 700; margin: 0; color: var(--tinta); }
.db-card-h h3 i { color: var(--verde); margin-right: 4px; }
.db-card-h small { color: var(--suave); font-size: 12px; margin-left: auto; }
.db-badge { margin-left: auto; background: var(--naranja); color: #fff; font-size: 12px; font-weight: 700; border-radius: 999px; padding: 1px 9px; }

/* Atencion (estado: icono + etiqueta, nunca solo color) */
.db-alertas { list-style: none; margin: 0; padding: 6px 0; }
.db-alertas li { display: flex; align-items: flex-start; gap: 12px; padding: 10px 18px; border-left: 4px solid transparent; }
.db-alertas li + li { border-top: 1px solid #f0f2f1; }
.db-alertas li.is-alerta { border-left-color: var(--naranja); }
.db-alertas li.is-aviso { border-left-color: var(--amarillo); }
.db-alertas li.is-info { border-left-color: var(--gris); }
.db-alerta-icono { width: 30px; height: 30px; border-radius: 8px; display: grid; place-items: center; flex: none; font-size: 13px; }
.is-alerta .db-alerta-icono { background: #fff0e5; color: #c24f00; }
.is-aviso .db-alerta-icono { background: #fff8d6; color: #8a6d00; }
.is-info .db-alerta-icono { background: #f1f3f2; color: var(--suave); }
.db-alertas li > div { flex: 1; min-width: 0; }
.db-alertas b { display: block; font-size: 13px; color: var(--tinta); }
.db-alertas small { display: block; color: var(--suave); font-size: 12px; }
.db-nivel { display: inline-block; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--suave); }
.is-alerta .db-nivel { color: #c24f00; }
.is-aviso .db-nivel { color: #8a6d00; }
.db-ir { flex: none; width: 28px; height: 28px; border-radius: 8px; display: grid; place-items: center; color: var(--verde-osc); background: #eef7e3; font-size: 12px; }
.db-ir:hover { background: var(--verde); color: #fff; }

/* Ensayos en campo */
.db-tabla-wrap { overflow-x: auto; position: relative; }
.db-tabla { width: 100%; border-collapse: collapse; font-size: 13px; }
.db-tabla th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--suave); font-weight: 700; padding: 10px 8px; border-bottom: 1px solid var(--borde); white-space: nowrap; }
.db-tabla td { padding: 10px 8px; border-bottom: 1px solid #f0f2f1; vertical-align: top; }
.db-tabla tr:last-child td { border-bottom: 0; }
.db-tabla th:first-child, .db-tabla td:first-child { padding-left: 18px; }
.db-cod { font-weight: 700; color: var(--verde-osc); }
.db-sub { display: block; color: var(--suave); font-size: 11.5px; max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.db-muted { color: var(--suave); }
.db-avance { display: flex; align-items: center; gap: 6px; min-width: 80px; }
.db-avance-barra { flex: 1; height: 6px; background: #edf0ee; border-radius: 99px; overflow: hidden; }
.db-avance-barra i { display: block; height: 100%; background: var(--verde); border-radius: 99px; }
.db-alerta-txt { color: #c24f00; font-weight: 600; white-space: nowrap; }
.db-accesos { white-space: nowrap; text-align: right; }
.db-accesos a { display: inline-grid; place-items: center; width: 26px; height: 26px; border-radius: 8px; color: var(--suave); }
.db-accesos a:hover { background: #eef7e3; color: var(--verde-osc); }

/* Por programa: barras apiladas con etiqueta directa y 2px de separacion */
.db-leyenda { display: flex; gap: 14px; padding: 12px 18px 0; font-size: 12px; color: var(--suave); }
.db-leyenda i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 5px; vertical-align: -1px; }
.db-programas { list-style: none; margin: 0; padding: 10px 18px 16px; }
.db-programas li + li { margin-top: 12px; }
.db-prog-h { display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 4px; color: var(--tinta); }
.db-apilada { display: flex; gap: 2px; height: 22px; }
.db-apilada > * { flex-basis: 0; min-width: 22px; display: grid; place-items: center; font-size: 11.5px; font-weight: 700; border-radius: 4px; text-decoration: none; }
.db-apilada > a:hover { filter: brightness(1.08); text-decoration: none; }

/* Actividad */
.db-timeline { list-style: none; margin: 0; padding: 6px 0; }
.db-timeline li { display: flex; align-items: center; gap: 12px; padding: 9px 18px; }
.db-timeline li + li { border-top: 1px solid #f0f2f1; }
.db-tl-icono { width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center; flex: none; font-size: 12px; background: #eef7e3; color: var(--verde-osc); }
.db-timeline .is-muestra .db-tl-icono { background: #fff0e5; color: #c24f00; }
.db-timeline .is-bitacora .db-tl-icono { background: #f1f3f2; color: var(--suave); }
.db-timeline .is-resultado .db-tl-icono { background: var(--verde); color: #fff; }
.db-tl-cuerpo { flex: 1; min-width: 0; font-size: 13px; }
.db-tl-cuerpo small { display: block; color: var(--suave); font-size: 12px; }
.db-chip { display: inline-block; margin-left: 6px; padding: 0 8px; border-radius: 999px; background: #f1f3f2; font-size: 11px; font-weight: 600; color: var(--suave); }
.db-timeline time { font-size: 12px; color: var(--suave); white-space: nowrap; }

.db-vacio { padding: 28px 18px; text-align: center; color: var(--suave); }
.db-vacio i { display: block; font-size: 22px; color: var(--verde); margin-bottom: 6px; }
.db-vacio b { display: block; color: var(--tinta); }

@media (max-width: 1280px) {
  .db-flujo { flex-wrap: wrap; }
  .db-paso { flex: 1 1 calc(33.333% - 8px); }
  .db-flecha, .db-sep { display: none; }
}
@media (max-width: 991px) {
  .db-grid { grid-template-columns: minmax(0, 1fr); }
}
@media (max-width: 575px) {
  .db-paso { flex: 1 1 calc(50% - 6px); padding: 12px; }
  .db-paso-valor { font-size: 24px; }
  .db-timeline time { display: none; }
  .db-tabla th:nth-child(4), .db-tabla td:nth-child(4) { display: none; }
}
</style>
