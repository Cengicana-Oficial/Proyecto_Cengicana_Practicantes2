<template>
  <div class="vr">
    <!-- ============ Indicadores ============ -->
    <div class="vr-kpis">
      <button type="button" class="vr-kpi" :class="{ 'is-on': !filtroCat }" @click="filtroCat = ''">
        <i class="fas fa-list-ul"></i>
        <div><strong>{{ items.length }}</strong><span>Variables</span><small>{{ activas }} activas</small></div>
      </button>
      <button type="button" class="vr-kpi is-des" :class="{ 'is-on': filtroCat === 'Desarrollo' }" @click="filtroCat = filtroCat === 'Desarrollo' ? '' : 'Desarrollo'">
        <i class="fas fa-seedling"></i>
        <div><strong>{{ porCategoria('Desarrollo') }}</strong><span>Desarrollo del cultivo</span><small>durante el ciclo</small></div>
      </button>
      <button type="button" class="vr-kpi is-cos" :class="{ 'is-on': filtroCat === 'Cosecha' }" @click="filtroCat = filtroCat === 'Cosecha' ? '' : 'Cosecha'">
        <i class="fas fa-tractor"></i>
        <div><strong>{{ porCategoria('Cosecha') }}</strong><span>Cosecha / fabrica</span><small>al momento de cosecha</small></div>
      </button>
      <div class="vr-kpi is-uso">
        <i class="fas fa-clipboard-check"></i>
        <div><strong>{{ enUso }}</strong><span>En uso</span><small>{{ n(totalRegistros) }} registros en evaluaciones</small></div>
      </div>
    </div>

    <!-- ============ Barra ============ -->
    <div class="vr-bar">
      <div class="vr-buscar">
        <i class="fas fa-search"></i>
        <input type="text" v-model="busqueda" placeholder="Buscar por nombre, unidad o descripcion...">
        <button v-if="busqueda" type="button" @click="busqueda = ''" title="Limpiar">×</button>
      </div>
      <div class="vr-chips">
        <button v-for="t in tipos" :key="t.id" type="button" :class="['vr-chip', 'is-' + t.id.toLowerCase(), { 'is-on': filtroTipo === t.id }]" @click="filtroTipo = filtroTipo === t.id ? '' : t.id">
          <i :class="t.icon"></i> {{ t.label }} <small>{{ porTipo(t.id) }}</small>
        </button>
        <label class="vr-inact"><input type="checkbox" v-model="verInactivas"> Ver inactivas</label>
      </div>
      <div class="vr-acc">
        <select class="form-control form-control-sm vr-orden" v-model="orden" title="Ordenar">
          <option value="nombre">Nombre A–Z</option>
          <option value="uso">Mas usadas</option>
          <option value="reciente">Ultimo registro</option>
        </select>
        <div class="vr-vista">
          <button type="button" :class="{ 'is-on': vista === 'tarjetas' }" title="Tarjetas" @click="vista = 'tarjetas'"><i class="fas fa-th-large"></i></button>
          <button type="button" :class="{ 'is-on': vista === 'tabla' }" title="Tabla" @click="vista = 'tabla'"><i class="fas fa-list"></i></button>
        </div>
        <template v-if="puedeEditar">
          <button type="button" class="btn btn-sm vr-btn-sec" @click="abrirSugeridas"><i class="fas fa-magic"></i> Variables comunes</button>
          <button type="button" class="btn btn-sm vr-btn" @click="abrirCrear"><i class="fas fa-plus"></i> Nueva variable</button>
        </template>
      </div>
    </div>

    <!-- ============ Vacio ============ -->
    <div v-if="!filtradas.length" class="vr-vacio">
      <i class="fas fa-flask"></i>
      <template v-if="items.length">
        <strong>Ninguna variable coincide con los filtros.</strong>
        <a href="#" @click.prevent="limpiarFiltros">Quitar filtros</a>
      </template>
      <template v-else>
        <strong>Aun no hay variables.</strong>
        <span v-if="puedeEditar">Cree la primera o cargue el <a href="#" @click.prevent="abrirSugeridas">catalogo de variables comunes de caña</a>.</span>
      </template>
    </div>

    <!-- ============ Tarjetas ============ -->
    <template v-else-if="vista === 'tarjetas'">
      <section v-for="g in grupos" :key="g.categoria" class="vr-grupo" :class="g.categoria === 'Cosecha' ? 'is-cos' : 'is-des'">
        <h6><i :class="g.categoria === 'Cosecha' ? 'fas fa-tractor' : 'fas fa-seedling'"></i> {{ g.categoria === 'Cosecha' ? 'Cosecha / fabrica' : 'Desarrollo del cultivo' }} <small>{{ g.items.length }}</small></h6>
        <div class="vr-grid">
          <article v-for="v in g.items" :key="v.id" class="vr-card" :class="{ 'is-off': !v.activa }" tabindex="0" @click="abrirDetalle(v)" @keyup.enter="abrirDetalle(v)">
            <div class="vr-card-top">
              <span class="vr-ico" :class="'is-' + v.tipo.toLowerCase()"><i :class="icono(v.tipo)"></i></span>
              <div class="vr-card-tit">
                <b>{{ v.nombre }}</b>
                <small>{{ v.unidad || 'sin unidad' }}</small>
              </div>
              <div v-if="puedeEditar" class="vr-card-acc" @click.stop>
                <button type="button" title="Editar" @click="abrirEditar(v)"><i class="fas fa-pen"></i></button>
                <button type="button" title="Eliminar" class="is-del" @click="eliminar(v)"><i class="fas fa-trash"></i></button>
              </div>
            </div>
            <p class="vr-desc">{{ v.descripcion || descripcionTipo(v) }}</p>
            <div class="vr-tags">
              <span class="vr-tag" :class="'is-' + v.tipo.toLowerCase()">{{ nombreTipo(v.tipo) }}</span>
              <span v-if="v.tipo === 'Numerica' && (v.valor_min !== null || v.valor_max !== null)" class="vr-tag is-rango" title="Rango esperado">{{ rango(v) }}</span>
              <span v-if="v.tipo === 'Categorica' && v.opciones && v.opciones.length" class="vr-tag is-rango">{{ v.opciones.length }} opciones</span>
              <span v-if="!v.activa" class="vr-tag is-off">Inactiva</span>
            </div>
            <div class="vr-uso">
              <span :class="{ 'is-cero': !v.registros }"><i class="fas fa-clipboard-list"></i> {{ n(v.registros) }} registros</span>
              <span :class="{ 'is-cero': !v.ensayos }"><i class="fas fa-flask"></i> {{ v.ensayos }} ensayos</span>
              <span v-if="v.ultima" class="vr-ult">{{ fmt(v.ultima) }}</span>
            </div>
          </article>
        </div>
      </section>
    </template>

    <!-- ============ Tabla ============ -->
    <div v-else class="vr-tabla-wrap">
      <table class="table vr-tabla">
        <thead>
          <tr><th>Variable</th><th>Unidad</th><th>Tipo</th><th>Categoria</th><th>Rango / opciones</th><th class="text-right">Registros</th><th class="text-right">Ensayos</th><th>Ultimo</th><th v-if="puedeEditar"></th></tr>
        </thead>
        <tbody>
          <tr v-for="v in filtradas" :key="v.id" :class="{ 'is-off': !v.activa }" @click="abrirDetalle(v)">
            <td><span class="vr-ico vr-ico-s" :class="'is-' + v.tipo.toLowerCase()"><i :class="icono(v.tipo)"></i></span> <b>{{ v.nombre }}</b><small v-if="!v.activa" class="vr-muted"> · inactiva</small></td>
            <td>{{ v.unidad || '—' }}</td>
            <td><span class="vr-tag" :class="'is-' + v.tipo.toLowerCase()">{{ nombreTipo(v.tipo) }}</span></td>
            <td><span class="vr-cat" :class="v.categoria === 'Cosecha' ? 'is-cos' : 'is-des'">{{ v.categoria }}</span></td>
            <td class="vr-muted">{{ v.tipo === 'Numerica' ? rango(v) : v.tipo === 'Categorica' && v.opciones ? v.opciones.join(', ') : '—' }}</td>
            <td class="text-right">{{ n(v.registros) }}</td>
            <td class="text-right">{{ v.ensayos }}</td>
            <td class="vr-muted">{{ v.ultima ? fmt(v.ultima) : '—' }}</td>
            <td v-if="puedeEditar" class="text-right vr-nowrap" @click.stop>
              <button type="button" class="vr-icon" title="Editar" @click="abrirEditar(v)"><i class="fas fa-pen"></i></button>
              <button type="button" class="vr-icon is-del" title="Eliminar" @click="eliminar(v)"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ============ Panel lateral: detalle / formulario / sugeridas ============ -->
    <transition name="vr-fade"><div v-if="panel" class="vr-velo" @click="cerrar"></div></transition>
    <transition name="vr-slide">
      <aside v-if="panel" class="vr-panel" role="dialog">
        <!-- Detalle -->
        <template v-if="panel === 'detalle' && sel">
          <header class="vr-ph" :class="sel.categoria === 'Cosecha' ? 'is-cos' : 'is-des'">
            <span class="vr-ico vr-ico-l" :class="'is-' + sel.tipo.toLowerCase()"><i :class="icono(sel.tipo)"></i></span>
            <div>
              <h5>{{ sel.nombre }}</h5>
              <small>{{ sel.unidad || 'sin unidad' }} · {{ nombreTipo(sel.tipo) }} · {{ sel.categoria }}{{ sel.activa ? '' : ' · inactiva' }}</small>
            </div>
            <button type="button" class="vr-x" @click="cerrar">×</button>
          </header>
          <div class="vr-pb">
            <p class="vr-pdesc">{{ sel.descripcion || descripcionTipo(sel) }}</p>
            <div class="vr-ficha">
              <div v-if="sel.tipo === 'Numerica'"><span>Rango esperado</span><b>{{ rango(sel) }}</b></div>
              <div v-if="sel.tipo === 'Numerica'"><span>Decimales</span><b>{{ sel.decimales !== null ? sel.decimales : 'libre' }}</b></div>
              <div v-if="sel.tipo === 'Categorica'" class="vr-ficha-full"><span>Opciones</span>
                <b><span v-for="o in sel.opciones || []" :key="o" class="vr-op">{{ o }}</span><template v-if="!(sel.opciones || []).length">libres</template></b>
              </div>
            </div>

            <div v-if="cargandoDet" class="vr-cargando"><i class="fas fa-spinner fa-spin"></i> Cargando uso...</div>
            <template v-else-if="det">
              <div class="vr-stats">
                <div><strong>{{ n(det.registros) }}</strong><span>registros</span></div>
                <div><strong>{{ det.ensayos }}</strong><span>ensayos</span></div>
                <div><strong>{{ det.primera ? fmt(det.primera) : '—' }}</strong><span>primer registro</span></div>
                <div><strong>{{ det.ultima ? fmt(det.ultima) : '—' }}</strong><span>ultimo registro</span></div>
              </div>
              <div v-if="!det.registros" class="vr-sin-uso">
                <i class="fas fa-info-circle"></i> Esta variable aun no tiene registros. Se registra en <b>Evaluaciones</b> para las parcelas de cada ensayo.
              </div>
              <template v-else>
                <div v-if="det.estadisticas" class="vr-stats is-num">
                  <div><strong>{{ dec(det.estadisticas.media) }}</strong><span>promedio</span></div>
                  <div><strong>{{ dec(det.estadisticas.min) }} – {{ dec(det.estadisticas.max) }}</strong><span>minimo – maximo</span></div>
                  <div><strong>{{ dec(det.estadisticas.sd) }}</strong><span>desv. estandar</span></div>
                  <div><strong>{{ det.estadisticas.cv !== null ? det.estadisticas.cv.toFixed(1) + ' %' : '—' }}</strong><span>coef. variacion</span></div>
                </div>
                <p v-if="det.fuera_rango" class="vr-alerta"><i class="fas fa-exclamation-triangle"></i> {{ det.fuera_rango }} registro(s) fuera del rango esperado ({{ rango(sel) }}).</p>
                <div class="vr-chart"><highcharts :options="chartDetalle"></highcharts></div>
                <h6 class="vr-sub">Ensayos donde se usa</h6>
                <table class="table vr-mini">
                  <thead><tr><th>Ensayo</th><th class="text-right">Registros</th><th v-if="sel.tipo === 'Numerica'" class="text-right">Promedio</th><th>Ultimo</th></tr></thead>
                  <tbody>
                    <tr v-for="e in det.por_ensayo" :key="e.ensayo_id">
                      <td><a :href="`${base}/investigacion/ensayos?ensayo=${e.ensayo_id}`">{{ e.ensayo }}</a></td>
                      <td class="text-right">{{ e.n }}</td>
                      <td v-if="sel.tipo === 'Numerica'" class="text-right">{{ dec(e.media) }}</td>
                      <td>{{ fmt(e.ultima) }}</td>
                    </tr>
                  </tbody>
                </table>
                <h6 class="vr-sub">Ultimos registros</h6>
                <table class="table vr-mini">
                  <thead><tr><th>Fecha</th><th>Ensayo</th><th>Parcela</th><th class="text-right">Valor</th></tr></thead>
                  <tbody>
                    <tr v-for="(r, i) in det.ultimos" :key="i"><td>{{ fmt(r.fecha) }}</td><td>{{ r.ensayo || '—' }}</td><td>{{ r.parcela || '—' }}</td><td class="text-right"><b>{{ r.valor }}</b> <small class="vr-muted">{{ sel.unidad }}</small></td></tr>
                  </tbody>
                </table>
              </template>
            </template>
          </div>
          <footer v-if="puedeEditar" class="vr-pf">
            <button type="button" class="btn btn-sm vr-btn-del" @click="eliminar(sel)"><i class="fas fa-trash"></i> Eliminar</button>
            <button type="button" class="btn btn-sm vr-btn" @click="abrirEditar(sel)"><i class="fas fa-pen"></i> Editar</button>
          </footer>
        </template>

        <!-- Formulario -->
        <template v-else-if="panel === 'form'">
          <header class="vr-ph" :class="form.categoria === 'Cosecha' ? 'is-cos' : 'is-des'">
            <span class="vr-ico vr-ico-l" :class="'is-' + form.tipo.toLowerCase()"><i :class="icono(form.tipo)"></i></span>
            <div><h5>{{ editando ? 'Editar variable' : 'Nueva variable' }}</h5><small>{{ form.nombre || 'Nombre de la variable' }}{{ form.unidad ? ' · ' + form.unidad : '' }}</small></div>
            <button type="button" class="vr-x" @click="cerrar">×</button>
          </header>
          <form class="vr-pb" @submit.prevent="guardar" novalidate>
            <div class="form-group">
              <label>Nombre *</label>
              <input class="form-control" ref="nombre" maxlength="150" v-model.trim="form.nombre" :class="{ 'is-invalid': errors.nombre }" placeholder="Ej. Brix">
              <span class="invalid-feedback" v-if="errors.nombre">{{ errors.nombre[0] }}</span>
            </div>
            <div class="form-group">
              <label>Categoria de evaluacion</label>
              <div class="vr-seg">
                <button type="button" class="is-des" :class="{ 'is-on': form.categoria === 'Desarrollo' }" @click="form.categoria = 'Desarrollo'"><i class="fas fa-seedling"></i> Desarrollo del cultivo</button>
                <button type="button" class="is-cos" :class="{ 'is-on': form.categoria === 'Cosecha' }" @click="form.categoria = 'Cosecha'"><i class="fas fa-tractor"></i> Cosecha / fabrica</button>
              </div>
            </div>
            <div class="form-group">
              <label>Tipo de dato</label>
              <div class="vr-seg is-tipos">
                <button v-for="t in tipos" :key="t.id" type="button" :class="['is-' + t.id.toLowerCase(), { 'is-on': form.tipo === t.id }]" @click="form.tipo = t.id">
                  <i :class="t.icon"></i> {{ t.label }}<small>{{ t.ayuda }}</small>
                </button>
              </div>
            </div>
            <div class="form-row">
              <div class="col-6 form-group">
                <label>Unidad</label>
                <input class="form-control" list="vr-unidades" maxlength="50" v-model.trim="form.unidad" placeholder="Ej. °Bx, cm, t/ha">
                <datalist id="vr-unidades"><option v-for="u in unidades" :key="u" :value="u"></option></datalist>
              </div>
              <div v-if="form.tipo === 'Numerica'" class="col-6 form-group">
                <label>Decimales</label>
                <select class="form-control" v-model="form.decimales">
                  <option :value="null">Libre</option>
                  <option v-for="d in [0, 1, 2, 3, 4]" :key="d" :value="d">{{ d }}</option>
                </select>
              </div>
            </div>
            <div v-if="form.tipo === 'Numerica'" class="form-group">
              <label>Rango esperado <small class="vr-muted">(sirve para detectar valores fuera de lo normal)</small></label>
              <div class="vr-rango">
                <input type="number" step="any" class="form-control" v-model="form.valor_min" placeholder="Minimo" :class="{ 'is-invalid': errors.valor_min }">
                <span>a</span>
                <input type="number" step="any" class="form-control" v-model="form.valor_max" placeholder="Maximo">
              </div>
              <span class="vr-error" v-if="errors.valor_min">{{ errors.valor_min[0] }}</span>
            </div>
            <div v-if="form.tipo === 'Categorica'" class="form-group">
              <label>Opciones <small class="vr-muted">(Enter para agregar)</small></label>
              <div class="vr-opciones">
                <span v-for="(o, i) in form.opciones" :key="o" class="vr-op">{{ o }} <button type="button" @click="form.opciones.splice(i, 1)">×</button></span>
                <input v-model="nuevaOpcion" placeholder="Ej. Alta" @keydown.enter.prevent="agregarOpcion" @keydown.188.prevent="agregarOpcion" @blur="agregarOpcion">
              </div>
            </div>
            <div class="form-group">
              <label>Descripcion / metodo de medicion</label>
              <textarea class="form-control" rows="3" maxlength="1000" v-model="form.descripcion" placeholder="Como y donde se mide, instrumento, criterio..."></textarea>
            </div>
            <label class="vr-check"><input type="checkbox" v-model="form.activa"> Activa <small class="vr-muted">(las inactivas no se ofrecen al registrar evaluaciones)</small></label>
            <p v-if="errorGeneral" class="vr-error"><i class="fas fa-exclamation-circle"></i> {{ errorGeneral }}</p>
          </form>
          <footer class="vr-pf">
            <button type="button" class="btn btn-sm vr-btn-sec" @click="editando ? abrirDetalle(editando) : cerrar()">Cancelar</button>
            <button type="button" class="btn btn-sm vr-btn" :disabled="guardando || !form.nombre" @click="guardar">
              <i :class="guardando ? 'fas fa-spinner fa-spin' : 'fas fa-save'"></i> Guardar
            </button>
          </footer>
        </template>

        <!-- Variables comunes -->
        <template v-else-if="panel === 'sugeridas'">
          <header class="vr-ph is-des">
            <span class="vr-ico vr-ico-l is-categorica"><i class="fas fa-magic"></i></span>
            <div><h5>Variables comunes de caña</h5><small>Marque las que quiere agregar al catalogo</small></div>
            <button type="button" class="vr-x" @click="cerrar">×</button>
          </header>
          <div class="vr-pb">
            <div v-for="cat in ['Desarrollo', 'Cosecha']" :key="cat" class="vr-sug-grupo">
              <h6 :class="cat === 'Cosecha' ? 'is-cos' : 'is-des'">{{ cat === 'Cosecha' ? 'Cosecha / fabrica' : 'Desarrollo del cultivo' }}</h6>
              <label v-for="s in sugeridas.filter((x) => x.categoria === cat)" :key="s.nombre" class="vr-sug" :class="{ 'is-existe': existe(s.nombre) }">
                <input type="checkbox" :value="s.nombre" v-model="sugSel" :disabled="existe(s.nombre)">
                <span>
                  <b>{{ s.nombre }}</b> <small>{{ s.unidad || '' }}</small>
                  <em>{{ existe(s.nombre) ? 'ya existe' : s.descripcion || nombreTipo(s.tipo) }}</em>
                </span>
              </label>
            </div>
          </div>
          <footer class="vr-pf">
            <button type="button" class="btn btn-sm vr-btn-sec" @click="marcarSugeridas">{{ sugSel.length ? 'Desmarcar todas' : 'Marcar todas' }}</button>
            <button type="button" class="btn btn-sm vr-btn" :disabled="!sugSel.length || guardando" @click="agregarSugeridas">
              <i :class="guardando ? 'fas fa-spinner fa-spin' : 'fas fa-plus'"></i> Agregar {{ sugSel.length || '' }}
            </button>
          </footer>
        </template>
      </aside>
    </transition>
  </div>
</template>

<script>
const TIPOS = [
  { id: 'Numerica', label: 'Numerica', icon: 'fas fa-ruler-combined', ayuda: 'medicion con numero' },
  { id: 'Categorica', label: 'Categorica', icon: 'fas fa-tags', ayuda: 'escala u opciones' },
  { id: 'Texto', label: 'Texto', icon: 'fas fa-font', ayuda: 'observacion libre' },
];
const UNIDADES = ['cm', 'm', 'mm', 'kg', 'g', 't/ha', 'kg/t', '%', '°Bx', 'tallos/m', 'tallos/ha', 'hojas/tallo', 'entrenudos', 'unidades', 'escala 1-9', 'dias'];

export default {
  name: 'VariablesIndex',
  props: {
    variables: { type: Array, default: () => [] },
    sugeridas: { type: Array, default: () => [] },
    puedeEditar: { type: Boolean, default: false },
  },
  data() {
    return {
      items: this.variables.slice(),
      tipos: TIPOS,
      unidades: UNIDADES,
      busqueda: '',
      filtroCat: '',
      filtroTipo: '',
      verInactivas: true,
      orden: 'nombre',
      vista: this.leerVista(),
      panel: null,
      sel: null,
      det: null,
      cargandoDet: false,
      editando: null,
      form: this.formVacio(),
      nuevaOpcion: '',
      errors: {},
      errorGeneral: null,
      guardando: false,
      sugSel: [],
      base: ((document.querySelector('meta[name="app-base"]') || {}).content || '').replace(/\/+$/, ''),
    };
  },
  computed: {
    activas() { return this.items.filter((v) => v.activa).length; },
    enUso() { return this.items.filter((v) => v.registros > 0).length; },
    totalRegistros() { return this.items.reduce((a, v) => a + (v.registros || 0), 0); },
    filtradas() {
      const q = this.sinTildes(this.busqueda.trim().toLowerCase());
      const lista = this.items.filter((v) => (!this.filtroCat || v.categoria === this.filtroCat)
        && (!this.filtroTipo || v.tipo === this.filtroTipo)
        && (this.verInactivas || v.activa)
        && (!q || this.sinTildes(`${v.nombre} ${v.unidad || ''} ${v.descripcion || ''}`.toLowerCase()).includes(q)));
      const porNombre = (a, b) => a.nombre.localeCompare(b.nombre, 'es');
      if (this.orden === 'uso') return lista.sort((a, b) => b.registros - a.registros || porNombre(a, b));
      if (this.orden === 'reciente') return lista.sort((a, b) => String(b.ultima || '').localeCompare(String(a.ultima || '')) || porNombre(a, b));
      return lista.sort(porNombre);
    },
    grupos() {
      return ['Desarrollo', 'Cosecha'].map((c) => ({ categoria: c, items: this.filtradas.filter((v) => v.categoria === c) })).filter((g) => g.items.length);
    },
    chartDetalle() {
      const d = this.det;
      const base = {
        chart: { height: 230, backgroundColor: '#ffffff', style: { fontFamily: 'inherit' } },
        credits: { enabled: false }, legend: { enabled: false },
        exporting: { enabled: true, filename: `variable_${this.sel.nombre}` },
      };
      if (this.sel.tipo === 'Numerica') {
        const banda = this.sel.valor_min !== null || this.sel.valor_max !== null
          ? [{ from: this.sel.valor_min ?? -1e9, to: this.sel.valor_max ?? 1e9, color: 'rgba(115, 188, 37, 0.08)', label: { text: 'rango esperado', style: { color: '#5b7f2a', fontSize: '10px' } } }] : [];
        return {
          ...base,
          title: { text: 'Promedio mensual de los registros', style: { fontSize: '12.5px', fontWeight: '700', color: '#1f5632' } },
          xAxis: { categories: d.mensual.map((m) => this.mesTxt(m.mes)), lineColor: '#CED2D5' },
          yAxis: { title: { text: this.sel.unidad || null }, gridLineColor: '#edf1ec', plotBands: banda },
          tooltip: { valueDecimals: this.sel.decimales ?? 2, valueSuffix: this.sel.unidad ? ` ${this.sel.unidad}` : '' },
          series: [{ type: 'line', name: this.sel.nombre, color: '#73BC25', lineWidth: 2, marker: { radius: 3 }, data: d.mensual.map((m) => (m.media === null ? null : Number(m.media.toFixed(4)))) }],
        };
      }
      return {
        ...base,
        title: { text: 'Distribucion de los valores registrados', style: { fontSize: '12.5px', fontWeight: '700', color: '#1f5632' } },
        xAxis: { categories: d.distribucion.map((x) => x.valor || '(vacio)'), lineColor: '#CED2D5' },
        yAxis: { title: { text: 'registros' }, gridLineColor: '#edf1ec', allowDecimals: false },
        plotOptions: { column: { borderRadius: 3, borderWidth: 0, dataLabels: { enabled: true } } },
        series: [{ type: 'column', name: 'Registros', color: '#A3D300', data: d.distribucion.map((x) => x.n) }],
      };
    },
  },
  watch: {
    vista(v) { try { localStorage.setItem('sigec.variables.vista', v); } catch (e) { /* sin almacenamiento */ } },
  },
  mounted() {
    window.addEventListener('keydown', this.onEsc);
  },
  beforeDestroy() {
    window.removeEventListener('keydown', this.onEsc);
  },
  methods: {
    leerVista() { try { return localStorage.getItem('sigec.variables.vista') || 'tarjetas'; } catch (e) { return 'tarjetas'; } },
    onEsc(e) { if (e.key === 'Escape' && this.panel) this.cerrar(); },
    formVacio() {
      return { nombre: '', descripcion: '', unidad: '', tipo: 'Numerica', categoria: 'Desarrollo', valor_min: '', valor_max: '', decimales: null, opciones: [], activa: true };
    },
    porCategoria(c) { return this.items.filter((v) => v.categoria === c).length; },
    porTipo(t) { return this.items.filter((v) => v.tipo === t).length; },
    limpiarFiltros() { this.busqueda = ''; this.filtroCat = ''; this.filtroTipo = ''; this.verInactivas = true; },
    icono(t) { return (TIPOS.find((x) => x.id === t) || TIPOS[0]).icon; },
    nombreTipo(t) { return { Numerica: 'Numerica', Categorica: 'Categorica', Texto: 'Texto' }[t] || t; },
    descripcionTipo(v) {
      if (v.tipo === 'Categorica') return 'Variable por categorias u opciones.';
      if (v.tipo === 'Texto') return 'Observacion en texto libre.';
      return `Medicion numerica${v.unidad ? ` en ${v.unidad}` : ''}.`;
    },
    rango(v) {
      const f = (x) => Number(x).toLocaleString('es-GT', { maximumFractionDigits: 4 });
      if (v.valor_min === null && v.valor_max === null) return 'sin rango';
      if (v.valor_min === null) return `hasta ${f(v.valor_max)} ${v.unidad || ''}`.trim();
      if (v.valor_max === null) return `desde ${f(v.valor_min)} ${v.unidad || ''}`.trim();
      return `${f(v.valor_min)} – ${f(v.valor_max)} ${v.unidad || ''}`.trim();
    },
    existe(nombre) { const k = this.sinTildes(nombre.toLowerCase()); return this.items.some((v) => this.sinTildes(v.nombre.toLowerCase()) === k); },
    sinTildes(s) { return s.normalize('NFD').replace(/[̀-ͯ]/g, ''); },

    /* ---------------- panel ---------------- */
    cerrar() { this.panel = null; this.sel = null; this.det = null; this.editando = null; },
    abrirDetalle(v) {
      this.sel = v;
      this.panel = 'detalle';
      this.det = null;
      this.cargandoDet = true;
      window.axios.get(`/investigacion/variables/${v.id}/resumen`)
        .then(({ data }) => { if (this.sel && this.sel.id === v.id) this.det = data; })
        .catch(() => { this.det = null; })
        .finally(() => { this.cargandoDet = false; });
    },
    abrirCrear() {
      this.editando = null;
      this.form = this.formVacio();
      if (this.filtroCat) this.form.categoria = this.filtroCat;
      if (this.filtroTipo) this.form.tipo = this.filtroTipo;
      this.errors = {};
      this.errorGeneral = null;
      this.panel = 'form';
      this.$nextTick(() => this.$refs.nombre && this.$refs.nombre.focus());
    },
    abrirEditar(v) {
      this.editando = v;
      this.form = {
        nombre: v.nombre, descripcion: v.descripcion || '', unidad: v.unidad || '', tipo: v.tipo, categoria: v.categoria,
        valor_min: v.valor_min ?? '', valor_max: v.valor_max ?? '', decimales: v.decimales ?? null, opciones: (v.opciones || []).slice(), activa: v.activa !== false,
      };
      this.errors = {};
      this.errorGeneral = null;
      this.panel = 'form';
    },
    agregarOpcion() {
      const o = this.nuevaOpcion.trim();
      if (o && !this.form.opciones.includes(o)) this.form.opciones.push(o);
      this.nuevaOpcion = '';
    },
    guardar() {
      this.agregarOpcion();
      this.guardando = true;
      this.errors = {};
      this.errorGeneral = null;
      const f = this.form;
      const payload = { ...f, valor_min: f.valor_min === '' ? null : f.valor_min, valor_max: f.valor_max === '' ? null : f.valor_max, unidad: f.unidad || null, descripcion: f.descripcion || null };
      const peticion = this.editando
        ? window.axios.put(`/investigacion/variables/${this.editando.id}`, payload)
        : window.axios.post('/investigacion/variables', payload);
      peticion
        .then(({ data }) => {
          const i = this.items.findIndex((x) => x.id === data.id);
          if (i >= 0) this.$set(this.items, i, data); else this.items.push(data);
          this.toast(this.editando ? 'Variable actualizada' : 'Variable creada', data.nombre);
          this.abrirDetalle(data);
        })
        .catch((err) => {
          const r = err.response;
          if (r && r.status === 422) {
            this.errors = r.data.errors || {};
            this.errorGeneral = r.data.errors ? null : r.data.message;
          } else this.errorGeneral = 'No se pudo guardar.';
        })
        .finally(() => { this.guardando = false; });
    },
    eliminar(v) {
      this.$swal.fire({
        title: `Eliminar "${v.nombre}"`, icon: 'warning', showCancelButton: true,
        text: v.registros ? `Tiene ${v.registros} registro(s): no se podra eliminar; puede marcarla como inactiva.` : 'La variable se quitara del catalogo.',
        confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#FF6B00', cancelButtonColor: '#CED2D5', focusCancel: true,
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/investigacion/variables/${v.id}`)
          .then(() => {
            this.items = this.items.filter((i) => i.id !== v.id);
            if (this.sel && this.sel.id === v.id) this.cerrar();
            this.toast('Variable eliminada', v.nombre);
          })
          .catch((err) => this.$swal.fire({ icon: 'error', title: 'No se pudo eliminar', text: (err.response && err.response.data && err.response.data.message) || 'Error inesperado.' }));
      });
    },
    abrirSugeridas() { this.sugSel = []; this.panel = 'sugeridas'; },
    marcarSugeridas() {
      this.sugSel = this.sugSel.length ? [] : this.sugeridas.filter((s) => !this.existe(s.nombre)).map((s) => s.nombre);
    },
    agregarSugeridas() {
      this.guardando = true;
      window.axios.post('/investigacion/variables/lote', { nombres: this.sugSel })
        .then(({ data }) => {
          data.creadas.forEach((v) => this.items.push(v));
          this.toast(`${data.creadas.length} variable(s) agregada(s)`, 'Puede ajustar unidad y rangos en cada una.');
          this.cerrar();
        })
        .catch(() => this.$swal.fire({ icon: 'error', title: 'No se pudieron agregar' }))
        .finally(() => { this.guardando = false; });
    },
    toast(title, text) { this.$swal.fire({ toast: true, position: 'top-end', icon: 'success', title, text, timer: 2200, showConfirmButton: false }); },
    n(x) { return Number(x || 0).toLocaleString('es-GT'); },
    dec(x) {
      if (x === null || x === undefined) return '—';
      const d = this.sel && this.sel.decimales !== null && this.sel.decimales !== undefined ? this.sel.decimales : 2;
      return Number(x).toLocaleString('es-GT', { minimumFractionDigits: d, maximumFractionDigits: d });
    },
    fmt(s) { if (!s) return ''; const [y, m, d] = String(s).slice(0, 10).split('-'); return `${d}/${m}/${y}`; },
    mesTxt(k) { const [y, m] = k.split('-'); return `${['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'][Number(m) - 1]} ${y.slice(2)}`; },
  },
};
</script>

<style scoped>
/* Paleta Cengicaña */
.vr {
  --verde: #73BC25; --verde-2: #A3D300; --amarillo: #FFCC00; --naranja: #FF6B00; --gris: #CED2D5;
  --verde-osc: #1f5632; --tinta: #1e2a1a; --suave: #6b7568; --borde: #e3e7e1;
}
.vr-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; margin-bottom: 14px; }
.vr-kpi {
  display: flex; align-items: center; gap: 12px; padding: 14px 16px; border: 1px solid var(--borde); border-left: 5px solid var(--verde);
  border-radius: 14px; background: #fff; text-align: left; transition: transform .15s ease, box-shadow .15s ease;
}
button.vr-kpi { cursor: pointer; }
button.vr-kpi:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(31, 86, 50, .1); }
.vr-kpi.is-on { box-shadow: 0 0 0 2px var(--verde) inset, 0 6px 16px rgba(31, 86, 50, .1); }
.vr-kpi > i { display: grid; place-items: center; width: 42px; height: 42px; border-radius: 12px; background: color-mix(in srgb, var(--verde) 16%, #fff); color: var(--verde); font-size: 18px; }
.vr-kpi strong { display: block; color: var(--tinta); font-size: 24px; line-height: 1.1; }
.vr-kpi span { display: block; color: var(--verde-osc); font-size: 12.5px; font-weight: 700; }
.vr-kpi small { color: var(--suave); font-size: 11.5px; }
.vr-kpi.is-des { border-left-color: var(--verde-2); }
.vr-kpi.is-des > i { background: color-mix(in srgb, var(--verde-2) 22%, #fff); color: #5f7a00; }
.vr-kpi.is-des.is-on { box-shadow: 0 0 0 2px var(--verde-2) inset, 0 6px 16px rgba(31, 86, 50, .1); }
.vr-kpi.is-cos { border-left-color: var(--naranja); }
.vr-kpi.is-cos > i { background: color-mix(in srgb, var(--naranja) 14%, #fff); color: var(--naranja); }
.vr-kpi.is-cos.is-on { box-shadow: 0 0 0 2px var(--naranja) inset, 0 6px 16px rgba(31, 86, 50, .1); }
.vr-kpi.is-uso { border-left-color: var(--amarillo); }
.vr-kpi.is-uso > i { background: color-mix(in srgb, var(--amarillo) 24%, #fff); color: #a07c00; }

.vr-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 14px; padding: 10px 12px; border: 1px solid var(--borde); border-radius: 14px; background: #fff; }
.vr-buscar { position: relative; display: flex; align-items: center; flex: 1 1 260px; max-width: 380px; }
.vr-buscar i { position: absolute; left: 12px; color: var(--suave); font-size: 13px; }
.vr-buscar input { width: 100%; height: 36px; padding: 0 30px 0 34px; border: 1px solid var(--borde); border-radius: 999px; background: #f7f9f5; font-size: 13px; outline: none; transition: border-color .15s, background .15s; }
.vr-buscar input:focus { border-color: var(--verde); background: #fff; }
.vr-buscar button { position: absolute; right: 8px; border: 0; background: transparent; color: var(--suave); font-size: 18px; line-height: 1; }
.vr-chips { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.vr-chip { display: inline-flex; align-items: center; gap: 5px; padding: 5px 11px; border: 1px solid var(--borde); border-radius: 999px; background: #fff; color: #44503f; font-size: 12px; font-weight: 600; transition: all .15s; }
.vr-chip small { color: var(--suave); font-weight: 500; }
.vr-chip.is-numerica i { color: #b38f00; }
.vr-chip.is-categorica i { color: #6f8f00; }
.vr-chip.is-texto i { color: #7d848a; }
.vr-chip.is-on.is-numerica { border-color: var(--amarillo); background: color-mix(in srgb, var(--amarillo) 20%, #fff); }
.vr-chip.is-on.is-categorica { border-color: var(--verde-2); background: color-mix(in srgb, var(--verde-2) 20%, #fff); }
.vr-chip.is-on.is-texto { border-color: #9aa1a6; background: color-mix(in srgb, var(--gris) 45%, #fff); }
.vr-inact { display: inline-flex; align-items: center; gap: 5px; margin: 0 0 0 4px; color: var(--suave); font-size: 12px; font-weight: 500; }
.vr-acc { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-left: auto; }
.vr-orden { width: auto; height: 32px; border-radius: 9px; font-size: 12.5px; }
.vr-vista { display: inline-flex; padding: 3px; border: 1px solid var(--borde); border-radius: 9px; background: #f3f5f1; }
.vr-vista button { width: 30px; height: 26px; border: 0; border-radius: 6px; background: transparent; color: var(--suave); }
.vr-vista button.is-on { background: #fff; color: var(--verde-osc); box-shadow: 0 1px 3px rgba(30, 42, 26, .12); }
.vr-btn { border: 0; border-radius: 9px; background: var(--verde); color: #fff; font-weight: 700; }
.vr-btn:hover { background: #64a51f; color: #fff; }
.vr-btn:disabled { opacity: .55; }
.vr-btn-sec { border: 1px solid var(--borde); border-radius: 9px; background: #fff; color: var(--verde-osc); font-weight: 600; }
.vr-btn-sec:hover { border-color: var(--verde); }
.vr-btn-sec i { color: var(--verde); }
.vr-btn-del { border: 1px solid #ffd2b3; border-radius: 9px; background: #fff; color: var(--naranja); font-weight: 600; }

.vr-vacio { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 46px 16px; border: 1px dashed var(--gris); border-radius: 14px; background: #fff; color: var(--suave); text-align: center; }
.vr-vacio > i { color: var(--gris); font-size: 30px; }
.vr-vacio strong { color: var(--tinta); }

.vr-grupo { margin-bottom: 18px; }
.vr-grupo h6 { display: flex; align-items: center; gap: 8px; margin: 0 0 10px; color: var(--verde-osc); font-size: 14px; font-weight: 800; }
.vr-grupo h6 i { display: grid; place-items: center; width: 26px; height: 26px; border-radius: 8px; color: #fff; font-size: 12px; }
.vr-grupo.is-des h6 i { background: var(--verde); }
.vr-grupo.is-cos h6 i { background: var(--naranja); }
.vr-grupo h6 small { padding: 1px 8px; border-radius: 999px; background: #eef2ea; color: var(--suave); font-size: 11.5px; }
.vr-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
.vr-card {
  position: relative; display: flex; flex-direction: column; gap: 8px; padding: 14px; border: 1px solid var(--borde); border-radius: 14px;
  background: #fff; cursor: pointer; outline: none; transition: transform .15s ease, box-shadow .15s ease, border-color .15s;
}
.vr-grupo.is-des .vr-card { border-top: 3px solid var(--verde); }
.vr-grupo.is-cos .vr-card { border-top: 3px solid var(--naranja); }
.vr-card:hover, .vr-card:focus { transform: translateY(-3px); box-shadow: 0 10px 22px rgba(31, 86, 50, .12); }
.vr-card.is-off { opacity: .6; }
.vr-card-top { display: flex; align-items: flex-start; gap: 10px; }
.vr-ico { display: inline-grid; flex: 0 0 auto; place-items: center; width: 36px; height: 36px; border-radius: 10px; font-size: 15px; }
.vr-ico.is-numerica { background: color-mix(in srgb, var(--amarillo) 26%, #fff); color: #9a7600; }
.vr-ico.is-categorica { background: color-mix(in srgb, var(--verde-2) 26%, #fff); color: #5f7a00; }
.vr-ico.is-texto { background: color-mix(in srgb, var(--gris) 50%, #fff); color: #5b6267; }
.vr-ico-s { width: 24px; height: 24px; border-radius: 7px; font-size: 11px; vertical-align: middle; }
.vr-ico-l { width: 44px; height: 44px; border-radius: 12px; font-size: 18px; }
.vr-card-tit { flex: 1; min-width: 0; }
.vr-card-tit b { display: block; overflow: hidden; color: var(--tinta); font-size: 15px; text-overflow: ellipsis; white-space: nowrap; }
.vr-card-tit small { color: var(--suave); font-size: 12px; }
.vr-card-acc { display: flex; gap: 2px; opacity: 0; transition: opacity .15s; }
.vr-card:hover .vr-card-acc, .vr-card:focus-within .vr-card-acc { opacity: 1; }
.vr-card-acc button, .vr-icon { display: inline-grid; place-items: center; width: 28px; height: 28px; border: 1px solid transparent; border-radius: 8px; background: transparent; color: var(--suave); font-size: 12px; }
.vr-card-acc button:hover, .vr-icon:hover { border-color: var(--borde); background: #f1f8e9; color: var(--verde-osc); }
.vr-card-acc .is-del:hover, .vr-icon.is-del:hover { border-color: #ffd2b3; background: #fff1e6; color: var(--naranja); }
.vr-desc { display: -webkit-box; min-height: 34px; margin: 0; overflow: hidden; color: #4d5a48; font-size: 12.5px; line-height: 1.4; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.vr-tags { display: flex; flex-wrap: wrap; gap: 5px; }
.vr-tag { padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.vr-tag.is-numerica { background: color-mix(in srgb, var(--amarillo) 26%, #fff); color: #7d6100; }
.vr-tag.is-categorica { background: color-mix(in srgb, var(--verde-2) 26%, #fff); color: #4f6600; }
.vr-tag.is-texto { background: color-mix(in srgb, var(--gris) 55%, #fff); color: #4f565b; }
.vr-tag.is-rango { background: #f3f5f1; color: #44503f; font-weight: 600; }
.vr-tag.is-off { background: #fff1e6; color: #b84d00; }
.vr-uso { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: auto; padding-top: 8px; border-top: 1px dashed #e8ece5; color: var(--verde-osc); font-size: 12px; font-weight: 600; }
.vr-uso i { margin-right: 3px; color: var(--verde); }
.vr-uso .is-cero { color: #a2a8a0; font-weight: 500; }
.vr-uso .is-cero i { color: var(--gris); }
.vr-ult { margin-left: auto; color: var(--suave); font-weight: 500; }

.vr-tabla-wrap { overflow-x: auto; border: 1px solid var(--borde); border-radius: 14px; background: #fff; }
.vr-tabla { margin: 0; font-size: 13px; }
.vr-tabla th { background: #f7f9f5; color: var(--suave); font-size: 11px; text-transform: uppercase; letter-spacing: .03em; white-space: nowrap; }
.vr-tabla td { vertical-align: middle; }
.vr-tabla tbody tr { cursor: pointer; transition: background .12s; }
.vr-tabla tbody tr:hover { background: #f7fbf1; }
.vr-tabla tr.is-off td { opacity: .6; }
.vr-cat { padding: 2px 9px; border-radius: 6px; font-size: 11.5px; font-weight: 700; }
.vr-cat.is-des { background: color-mix(in srgb, var(--verde) 15%, #fff); color: #3f7a12; }
.vr-cat.is-cos { background: color-mix(in srgb, var(--naranja) 13%, #fff); color: #b84d00; }
.vr-muted { color: var(--suave); }
.vr-nowrap { white-space: nowrap; }

/* Panel lateral */
.vr-velo { position: fixed; inset: 0; z-index: 1040; background: rgba(20, 30, 16, .35); }
.vr-panel { position: fixed; top: 0; right: 0; bottom: 0; z-index: 1050; display: flex; flex-direction: column; width: min(560px, 100vw); background: #fff; box-shadow: -10px 0 30px rgba(20, 30, 16, .18); }
.vr-ph { display: flex; align-items: center; gap: 12px; padding: 16px 18px; border-bottom: 1px solid var(--borde); }
.vr-ph.is-des { border-top: 5px solid var(--verde); }
.vr-ph.is-cos { border-top: 5px solid var(--naranja); }
.vr-ph h5 { margin: 0; color: var(--tinta); font-size: 17px; font-weight: 800; }
.vr-ph small { color: var(--suave); font-size: 12px; }
.vr-x { margin-left: auto; border: 0; background: transparent; color: var(--suave); font-size: 26px; line-height: 1; }
.vr-pb { flex: 1; overflow-y: auto; padding: 16px 18px; }
.vr-pf { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 18px; border-top: 1px solid var(--borde); background: #fafbf8; }
.vr-pf .vr-btn-del { margin-right: auto; }
.vr-pdesc { color: #3d4838; font-size: 13px; }
.vr-ficha { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px; }
.vr-ficha > div { padding: 8px 10px; border-radius: 10px; background: #f6f8f3; }
.vr-ficha span { display: block; color: var(--suave); font-size: 11px; font-weight: 700; text-transform: uppercase; }
.vr-ficha b { color: var(--tinta); font-size: 13px; }
.vr-ficha-full { grid-column: 1 / -1; }
.vr-op { display: inline-flex; align-items: center; gap: 4px; margin: 2px 4px 2px 0; padding: 1px 9px; border-radius: 999px; background: color-mix(in srgb, var(--verde-2) 24%, #fff); color: #4f6600; font-size: 12px; font-weight: 600; }
.vr-op button { border: 0; background: transparent; color: #4f6600; font-size: 14px; line-height: 1; }
.vr-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 10px; }
.vr-stats > div { padding: 9px 8px; border: 1px solid var(--borde); border-radius: 10px; text-align: center; }
.vr-stats strong { display: block; color: var(--verde-osc); font-size: 15px; }
.vr-stats span { color: var(--suave); font-size: 11px; }
.vr-stats.is-num > div { border-color: color-mix(in srgb, var(--amarillo) 50%, #fff); background: color-mix(in srgb, var(--amarillo) 7%, #fff); }
.vr-sin-uso { padding: 12px 14px; border-radius: 10px; background: #f6f8f3; color: #3d4838; font-size: 12.5px; }
.vr-sin-uso i { margin-right: 4px; color: var(--verde); }
.vr-alerta { padding: 7px 10px; border-radius: 8px; background: #fff1e6; color: #b84d00; font-size: 12.5px; }
.vr-chart { margin-bottom: 12px; padding: 6px; border: 1px solid var(--borde); border-radius: 12px; }
.vr-sub { margin: 12px 0 6px; color: var(--verde-osc); font-size: 13px; font-weight: 800; }
.vr-mini { margin: 0; font-size: 12.5px; }
.vr-mini th { color: var(--suave); font-size: 11px; }
.vr-cargando { padding: 20px; color: var(--suave); text-align: center; }

/* Formulario */
.vr-pb label { color: var(--verde-osc); font-size: 12.5px; font-weight: 700; }
.vr-pb .form-control:focus { border-color: var(--verde); box-shadow: 0 0 0 .15rem rgba(115, 188, 37, .2); }
.vr-seg { display: flex; gap: 6px; }
.vr-seg button { flex: 1; padding: 9px 10px; border: 1.5px solid var(--borde); border-radius: 10px; background: #fff; color: #44503f; font-size: 12.5px; font-weight: 700; text-align: left; transition: all .15s; }
.vr-seg button i { margin-right: 4px; }
.vr-seg button small { display: block; color: var(--suave); font-size: 11px; font-weight: 500; }
.vr-seg button.is-des.is-on { border-color: var(--verde); background: color-mix(in srgb, var(--verde) 12%, #fff); color: #2f6b0f; }
.vr-seg button.is-cos.is-on { border-color: var(--naranja); background: color-mix(in srgb, var(--naranja) 10%, #fff); color: #b84d00; }
.vr-seg button.is-numerica.is-on { border-color: var(--amarillo); background: color-mix(in srgb, var(--amarillo) 18%, #fff); }
.vr-seg button.is-categorica.is-on { border-color: var(--verde-2); background: color-mix(in srgb, var(--verde-2) 18%, #fff); }
.vr-seg button.is-texto.is-on { border-color: #9aa1a6; background: color-mix(in srgb, var(--gris) 40%, #fff); }
.vr-rango { display: flex; align-items: center; gap: 8px; }
.vr-rango span { color: var(--suave); }
.vr-opciones { display: flex; flex-wrap: wrap; align-items: center; gap: 2px; min-height: 40px; padding: 5px 8px; border: 1px solid #ced4da; border-radius: 6px; }
.vr-opciones input { flex: 1 1 120px; min-width: 100px; border: 0; outline: none; font-size: 13px; }
.vr-check { display: flex; align-items: center; gap: 6px; font-weight: 600 !important; }
.vr-error { margin: 4px 0 0; color: #b84d00; font-size: 12px; }
.vr-sug-grupo h6 { margin: 6px 0 8px; font-size: 13px; font-weight: 800; }
.vr-sug-grupo h6.is-des { color: #3f7a12; }
.vr-sug-grupo h6.is-cos { color: #b84d00; }
.vr-sug { display: flex; gap: 10px; align-items: flex-start; margin: 0 0 6px; padding: 8px 10px; border: 1px solid var(--borde); border-radius: 10px; cursor: pointer; font-weight: 400 !important; }
.vr-sug:hover { border-color: var(--verde); background: #f9fcf5; }
.vr-sug input { margin-top: 3px; }
.vr-sug b { color: var(--tinta); }
.vr-sug small { color: var(--suave); }
.vr-sug em { display: block; color: var(--suave); font-size: 11.5px; font-style: normal; }
.vr-sug.is-existe { opacity: .5; cursor: default; }

.vr-fade-enter-active, .vr-fade-leave-active { transition: opacity .2s; }
.vr-fade-enter, .vr-fade-leave-to { opacity: 0; }
.vr-slide-enter-active, .vr-slide-leave-active { transition: transform .25s ease; }
.vr-slide-enter, .vr-slide-leave-to { transform: translateX(100%); }

@media (max-width: 640px) {
  .vr-stats { grid-template-columns: repeat(2, 1fr); }
  .vr-acc { margin-left: 0; }
  .vr-card-acc { opacity: 1; }
}
</style>
