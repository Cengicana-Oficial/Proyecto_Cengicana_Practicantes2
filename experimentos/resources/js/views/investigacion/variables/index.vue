<template>
  <div class="vr">
    <!-- ============ Indicadores ============ -->
    <div class="vr-kpis">
      <button type="button" class="vr-kpi" :class="{ 'is-on': !hayFiltros }" title="Ver todas" @click="limpiarFiltros">
        <i class="fas fa-list-ul"></i>
        <div><strong>{{ items.length }}</strong><span>Variables</span><small>{{ activas }} activas</small></div>
      </button>
      <button type="button" class="vr-kpi is-des" :class="{ 'is-on': esSolo('catSel', 'Desarrollo') }" title="Filtrar desarrollo del cultivo" @click="soloEn('catSel', 'Desarrollo')">
        <i class="fas fa-seedling"></i>
        <div><strong>{{ porCategoria('Desarrollo') }}</strong><span>Desarrollo del cultivo</span><small>durante el ciclo</small></div>
      </button>
      <button type="button" class="vr-kpi is-cos" :class="{ 'is-on': esSolo('catSel', 'Cosecha') }" title="Filtrar cosecha / fabrica" @click="soloEn('catSel', 'Cosecha')">
        <i class="fas fa-tractor"></i>
        <div><strong>{{ porCategoria('Cosecha') }}</strong><span>Cosecha / fabrica</span><small>al momento de cosecha</small></div>
      </button>
      <button type="button" class="vr-kpi is-uso" :class="{ 'is-on': esSolo('usoSel', 'con') }" title="Filtrar las que tienen registros" @click="soloEn('usoSel', 'con')">
        <i class="fas fa-clipboard-check"></i>
        <div><strong>{{ enUso }}</strong><span>En uso</span><small>{{ n(totalRegistros) }} registros en evaluaciones</small></div>
      </button>
    </div>

    <!-- ============ Barra ============ -->
    <div class="vr-bar">
      <div class="vr-buscar">
        <i class="fas fa-search"></i>
        <input type="text" v-model="busqueda" placeholder="Buscar por nombre, unidad o descripcion...">
        <button v-if="busqueda" type="button" @click="busqueda = ''" title="Limpiar">×</button>
      </div>
      <div class="vr-acc">
        <select class="form-control form-control-sm vr-orden" v-model="orden" title="Ordenar">
          <option value="nombre">Nombre A–Z</option>
          <option value="uso">Mas registros</option>
          <option value="ensayos">Mas ensayos</option>
          <option value="reciente">Ultimo registro</option>
        </select>
        <div class="vr-vista">
          <button type="button" :class="{ 'is-on': vista === 'tarjetas' }" title="Tarjetas" @click="vista = 'tarjetas'"><i class="fas fa-th-large"></i></button>
          <button type="button" :class="{ 'is-on': vista === 'tabla' }" title="Tabla" @click="vista = 'tabla'"><i class="fas fa-list"></i></button>
        </div>
        <button type="button" class="btn btn-sm vr-btn-sec" :disabled="!filtradas.length" title="Descargar las variables filtradas (se abre en Excel)" @click="exportarCsv"><i class="fas fa-file-excel"></i> Exportar</button>
        <template v-if="puedeEditar">
          <button type="button" class="btn btn-sm vr-btn-sec" @click="abrirSugeridas"><i class="fas fa-magic"></i> Variables comunes</button>
          <button type="button" class="btn btn-sm vr-btn" @click="abrirCrear"><i class="fas fa-plus"></i> Nueva variable</button>
        </template>
      </div>
    </div>

    <!-- ============ Filtros ============ -->
    <div class="vr-filtros">
      <section v-for="f in filtros" :key="f.clave" class="vr-fpanel" :style="{ '--acento': f.acento }">
        <header>
          <span class="vr-ft"><i :class="f.icon"></i> {{ f.titulo }}</span>
          <span class="vr-cuenta">{{ marcadas(f).length }}/{{ f.opciones.length }}</span>
          <button v-if="marcadas(f).length < f.opciones.length" type="button" class="vr-todas" @click="todas(f)">Todas</button>
        </header>
        <div class="vr-fchips">
          <button v-for="o in f.opciones" :key="o.id" type="button" :class="{ 'is-on': marcadas(f).includes(o.id) }" :style="{ '--c': o.color }" :aria-pressed="marcadas(f).includes(o.id)" @click="alternar(f.clave, o.id)">
            <span class="vr-dot"><i class="fas fa-check"></i></span>{{ o.label }}<small>{{ o.cuenta }}</small>
          </button>
        </div>
      </section>
    </div>
    <div class="vr-res">
      Mostrando <b>{{ filtradas.length }}</b> de {{ items.length }} variables
      <a v-if="hayFiltros" href="#" @click.prevent="limpiarFiltros"><i class="fas fa-times"></i> Quitar filtros</a>
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
          <tr>
            <th class="vr-ord" :class="{ 'is-on': orden === 'nombre' }" @click="orden = 'nombre'">Variable <i class="fas fa-sort-alpha-down"></i></th>
            <th>Unidad</th><th>Tipo</th><th>Categoria</th><th>Rango / opciones</th>
            <th class="text-right vr-ord" :class="{ 'is-on': orden === 'uso' }" title="Ordenar por registros" @click="orden = 'uso'">Registros <i class="fas fa-sort-amount-down"></i></th>
            <th class="text-right vr-ord" :class="{ 'is-on': orden === 'ensayos' }" title="Ordenar por ensayos" @click="orden = 'ensayos'">Ensayos <i class="fas fa-sort-amount-down"></i></th>
            <th class="vr-ord" :class="{ 'is-on': orden === 'reciente' }" title="Ordenar por ultimo registro" @click="orden = 'reciente'">Ultimo <i class="fas fa-sort-amount-down"></i></th>
            <th v-if="puedeEditar"></th>
          </tr>
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
                <div v-if="barraRango" class="vr-rbar">
                  <div class="vr-rbar-t"><span>Rango esperado vs. valores registrados</span><small><i class="vr-sw is-esp"></i> esperado <i class="vr-sw is-obs"></i> registrado <i class="vr-sw is-media"></i> promedio</small></div>
                  <div class="vr-rbar-pista">
                    <div v-if="barraRango.esp" class="vr-rbar-esp" :style="{ left: barraRango.esp[0] + '%', width: (barraRango.esp[1] - barraRango.esp[0]) + '%' }"></div>
                    <div class="vr-rbar-obs" :class="{ 'is-fuera': det.fuera_rango }" :style="{ left: barraRango.obs[0] + '%', width: Math.max(barraRango.obs[1] - barraRango.obs[0], 0.8) + '%' }"></div>
                    <div class="vr-rbar-media" :style="{ left: barraRango.media + '%' }" :title="'Promedio ' + dec(det.estadisticas.media)"></div>
                  </div>
                  <div class="vr-rbar-esc"><span>{{ dec(barraRango.lo) }}</span><span>{{ dec(barraRango.hi) }} {{ sel.unidad || '' }}</span></div>
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
            <button type="button" class="btn btn-sm vr-btn-sec" title="Crear una variable nueva a partir de esta" @click="duplicar(sel)"><i class="far fa-copy"></i> Duplicar</button>
            <button type="button" class="btn btn-sm vr-btn-sec" :disabled="guardando" :title="sel.activa ? 'No se ofrecera al registrar evaluaciones' : 'Volver a ofrecerla al registrar evaluaciones'" @click="alternarActiva(sel)">
              <i :class="sel.activa ? 'fas fa-toggle-off' : 'fas fa-toggle-on'"></i> {{ sel.activa ? 'Desactivar' : 'Activar' }}
            </button>
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
import { EXPORTAR } from '../clima/clima-util';

const CATEGORIAS = [
  { id: 'Desarrollo', label: 'Desarrollo', color: '#73BC25' },
  { id: 'Cosecha', label: 'Cosecha / fabrica', color: '#FF6B00' },
];
const ESTADOS = [
  { id: 'activa', label: 'Activas', color: '#73BC25' },
  { id: 'inactiva', label: 'Inactivas', color: '#9aa1a6' },
];
const USOS = [
  { id: 'con', label: 'Con registros', color: '#1f5632' },
  { id: 'sin', label: 'Sin registros', color: '#9aa1a6' },
];
const COLOR_TIPO = { Numerica: '#e0b400', Categorica: '#A3D300', Texto: '#9aa1a6' };
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
      // Filtros multiples: una variable se muestra si su valor esta encendido en cada grupo.
      catSel: CATEGORIAS.map((o) => o.id),
      tipoSel: TIPOS.map((o) => o.id),
      estadoSel: ESTADOS.map((o) => o.id),
      usoSel: USOS.map((o) => o.id),
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
    filtros() {
      const cuenta = (fn) => this.items.filter(fn).length;
      return [
        { clave: 'catSel', titulo: 'Categoria', icon: 'fas fa-layer-group', acento: '#73BC25', opciones: CATEGORIAS.map((o) => ({ ...o, cuenta: cuenta((v) => v.categoria === o.id) })) },
        { clave: 'tipoSel', titulo: 'Tipo de dato', icon: 'fas fa-ruler-combined', acento: '#A3D300', opciones: TIPOS.map((t) => ({ id: t.id, label: t.label, color: COLOR_TIPO[t.id], cuenta: cuenta((v) => v.tipo === t.id) })) },
        { clave: 'estadoSel', titulo: 'Estado', icon: 'fas fa-toggle-on', acento: '#FFCC00', opciones: ESTADOS.map((o) => ({ ...o, cuenta: cuenta((v) => this.estadoDe(v) === o.id) })) },
        { clave: 'usoSel', titulo: 'Uso en evaluaciones', icon: 'fas fa-clipboard-check', acento: '#FF6B00', opciones: USOS.map((o) => ({ ...o, cuenta: cuenta((v) => this.usoDe(v) === o.id) })) },
      ];
    },
    hayFiltros() {
      return !!this.busqueda.trim() || this.filtros.some((f) => this[f.clave].length < f.opciones.length);
    },
    filtradas() {
      const q = this.sinTildes(this.busqueda.trim().toLowerCase());
      const lista = this.items.filter((v) => this.catSel.includes(v.categoria)
        && this.tipoSel.includes(v.tipo)
        && this.estadoSel.includes(this.estadoDe(v))
        && this.usoSel.includes(this.usoDe(v))
        && (!q || this.sinTildes(`${v.nombre} ${v.unidad || ''} ${v.descripcion || ''}`.toLowerCase()).includes(q)));
      const porNombre = (a, b) => a.nombre.localeCompare(b.nombre, 'es');
      if (this.orden === 'uso') return lista.sort((a, b) => b.registros - a.registros || porNombre(a, b));
      if (this.orden === 'ensayos') return lista.sort((a, b) => b.ensayos - a.ensayos || b.registros - a.registros || porNombre(a, b));
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
        exporting: { ...EXPORTAR, filename: `variable_${this.sel.nombre}` },
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
    /** Posiciones (%) de la barra rango esperado vs. registrado, sobre una escala comun. */
    barraRango() {
      const e = this.det && this.det.estadisticas;
      if (!e || this.sel.tipo !== 'Numerica') return null;
      const vmin = this.sel.valor_min;
      const vmax = this.sel.valor_max;
      let lo = Math.min(e.min, vmin ?? e.min);
      let hi = Math.max(e.max, vmax ?? e.max);
      const margen = (hi - lo) * 0.06 || Math.abs(hi) * 0.1 || 1;
      lo -= margen;
      hi += margen;
      const pct = (x) => ((x - lo) / (hi - lo)) * 100;
      const tieneEsp = vmin !== null || vmax !== null;
      return {
        lo, hi,
        esp: tieneEsp ? [pct(vmin ?? lo), pct(vmax ?? hi)] : null,
        obs: [pct(e.min), pct(e.max)],
        media: pct(e.media),
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
    estadoDe(v) { return v.activa ? 'activa' : 'inactiva'; },
    usoDe(v) { return v.registros > 0 ? 'con' : 'sin'; },
    alternar(clave, id) {
      const lista = this[clave];
      const i = lista.indexOf(id);
      if (i >= 0) lista.splice(i, 1); else lista.push(id);
    },
    marcadas(f) { return this[f.clave]; },
    todas(f) { this[f.clave] = f.opciones.map((o) => o.id); },
    esSolo(clave, id) { return this[clave].length === 1 && this[clave][0] === id; },
    /** Indicadores: muestra solo ese valor; un segundo clic vuelve a todos. */
    soloEn(clave, id) {
      const f = this.filtros.find((x) => x.clave === clave);
      this[clave] = this.esSolo(clave, id) ? f.opciones.map((o) => o.id) : [id];
    },
    limpiarFiltros() {
      this.busqueda = '';
      this.filtros.forEach((f) => this.todas(f));
    },
    exportarCsv() {
      const celda = (x) => {
        const s = x === null || x === undefined ? '' : String(x);
        return /[;"\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
      };
      const num = (x) => (x === null || x === undefined ? '' : String(x).replace('.', ','));
      const filas = [
        ['Variable', 'Unidad', 'Tipo', 'Categoria', 'Minimo esperado', 'Maximo esperado', 'Decimales', 'Opciones', 'Activa', 'Registros', 'Ensayos', 'Ultimo registro', 'Descripcion'],
        ...this.filtradas.map((v) => [
          v.nombre, v.unidad, v.tipo, v.categoria, num(v.valor_min), num(v.valor_max), v.decimales,
          (v.opciones || []).join(', '), v.activa ? 'Si' : 'No', v.registros, v.ensayos, v.ultima ? this.fmt(v.ultima) : '', v.descripcion,
        ]),
      ];
      // BOM + ";" para que Excel en español abra tildes y columnas correctamente.
      const csv = '﻿' + filas.map((f) => f.map(celda).join(';')).join('\r\n');
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
      a.download = `variables_${new Date().toISOString().slice(0, 10)}.csv`;
      document.body.appendChild(a);
      a.click();
      setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 0);
    },
    duplicar(v) {
      this.abrirEditar(v);
      this.editando = null;
      this.form.nombre = `${v.nombre} (copia)`;
      this.$nextTick(() => { if (this.$refs.nombre) { this.$refs.nombre.focus(); this.$refs.nombre.select(); } });
    },
    alternarActiva(v) {
      this.guardando = true;
      const payload = {
        nombre: v.nombre, descripcion: v.descripcion || null, unidad: v.unidad || null, tipo: v.tipo, categoria: v.categoria,
        valor_min: v.valor_min, valor_max: v.valor_max, decimales: v.decimales, opciones: v.opciones || [], activa: !v.activa,
      };
      window.axios.put(`/investigacion/variables/${v.id}`, payload)
        .then(({ data }) => {
          const i = this.items.findIndex((x) => x.id === data.id);
          if (i >= 0) this.$set(this.items, i, data);
          if (this.sel && this.sel.id === data.id) this.sel = data;
          this.toast(data.activa ? 'Variable activada' : 'Variable desactivada', data.nombre);
        })
        .catch((err) => this.$swal.fire({ icon: 'error', title: 'No se pudo cambiar el estado', text: (err.response && err.response.data && err.response.data.message) || 'Error inesperado.' }))
        .finally(() => { this.guardando = false; });
    },
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
      // Si se filtra una sola categoria o tipo, la nueva variable parte de esa seleccion.
      if (this.catSel.length === 1) this.form.categoria = this.catSel[0];
      if (this.tipoSel.length === 1) this.form.tipo = this.tipoSel[0];
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
/* Filtros en paneles (mismo estilo que Clima y estaciones) */
.vr-filtros { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 8px; }
.vr-fpanel { display: flex; flex-direction: column; gap: 9px; padding: 10px 12px 12px; border: 1px solid var(--borde); border-top: 3px solid var(--acento); border-radius: 12px; background: #fff; }
.vr-fpanel > header { display: flex; align-items: center; gap: 8px; }
.vr-ft { color: var(--verde-osc); font-size: 11.5px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; }
.vr-ft i { margin-right: 3px; color: var(--verde); }
.vr-cuenta { padding: 1px 7px; border-radius: 999px; background: #eef6e2; color: #3e6b14; font-size: 11px; font-weight: 700; font-variant-numeric: tabular-nums; }
.vr-todas { margin-left: auto; padding: 1px 6px; border: 0; border-radius: 5px; background: transparent; color: #4f8a12; font-size: 11px; font-weight: 600; }
.vr-todas:hover { background: #eef6e2; color: var(--verde-osc); }
.vr-fchips { display: flex; flex-wrap: wrap; gap: 6px; }
.vr-fchips button {
  display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px 4px 6px; border: 1px solid var(--gris); border-radius: 999px;
  background: #fff; color: var(--suave); font-size: 12px; font-weight: 600; line-height: 1.2; transition: background-color .12s, border-color .12s, color .12s;
}
.vr-fchips button:hover { border-color: var(--c); color: var(--tinta); }
.vr-fchips button:focus-visible { outline: 2px solid rgba(115, 188, 37, .5); outline-offset: 1px; }
.vr-fchips button small { padding: 0 5px; border-radius: 999px; background: #f0f2f0; color: #7c868d; font-size: 10.5px; font-weight: 700; }
.vr-fchips button.is-on { border-color: var(--c); background: color-mix(in srgb, var(--c) 15%, #fff); color: var(--tinta); }
.vr-fchips button.is-on small { background: #fff; color: #33402a; }
.vr-dot { display: inline-flex; flex: none; align-items: center; justify-content: center; width: 15px; height: 15px; border: 2px solid var(--c); border-radius: 50%; background: #fff; }
.vr-dot i { display: none; color: #fff; font-size: 8px; }
.vr-fchips button.is-on .vr-dot { background: var(--c); }
.vr-fchips button.is-on .vr-dot i { display: block; }
.vr-res { display: flex; align-items: center; gap: 10px; margin: 0 2px 12px; color: var(--suave); font-size: 12.5px; }
.vr-res b { color: var(--tinta); }
.vr-res a { color: #b84d00; font-weight: 600; }
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
.vr-tabla th.vr-ord { cursor: pointer; user-select: none; }
.vr-tabla th.vr-ord i { margin-left: 3px; opacity: .3; }
.vr-tabla th.vr-ord:hover { color: var(--verde-osc); }
.vr-tabla th.vr-ord.is-on { color: var(--verde-osc); }
.vr-tabla th.vr-ord.is-on i { color: var(--verde); opacity: 1; }
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
.vr-rbar { margin-bottom: 10px; padding: 10px 12px; border: 1px solid var(--borde); border-radius: 10px; }
.vr-rbar-t { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 6px; margin-bottom: 8px; }
.vr-rbar-t span { color: var(--verde-osc); font-size: 12px; font-weight: 700; }
.vr-rbar-t small { display: flex; align-items: center; gap: 4px; color: var(--suave); font-size: 11px; }
.vr-sw { display: inline-block; width: 12px; height: 8px; margin-left: 6px; border-radius: 2px; }
.vr-sw.is-esp { background: color-mix(in srgb, var(--verde) 30%, #fff); border: 1px solid var(--verde); }
.vr-sw.is-obs { background: var(--amarillo); }
.vr-sw.is-media { width: 3px; height: 12px; background: var(--verde-osc); }
.vr-rbar-pista { position: relative; height: 22px; border-radius: 6px; background: #f1f3f1; }
.vr-rbar-esp { position: absolute; top: 0; bottom: 0; border: 1px solid var(--verde); border-radius: 6px; background: color-mix(in srgb, var(--verde) 22%, #fff); }
.vr-rbar-obs { position: absolute; top: 7px; height: 8px; border-radius: 4px; background: var(--amarillo); }
.vr-rbar-obs.is-fuera { background: var(--naranja); }
.vr-rbar-media { position: absolute; top: 2px; bottom: 2px; width: 3px; margin-left: -1.5px; border-radius: 2px; background: var(--verde-osc); }
.vr-rbar-esc { display: flex; justify-content: space-between; margin-top: 4px; color: var(--suave); font-size: 11px; font-variant-numeric: tabular-nums; }
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

@media (max-width: 1199px) { .vr-filtros { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 640px) {
  .vr-filtros { grid-template-columns: 1fr; }
  .vr-stats { grid-template-columns: repeat(2, 1fr); }
  .vr-acc { margin-left: 0; }
  .vr-card-acc { opacity: 1; }
}
</style>
