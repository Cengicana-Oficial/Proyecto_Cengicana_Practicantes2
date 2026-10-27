<template>
  <div class="ev">
    <!-- ============ Indicadores ============ -->
    <div class="ev-kpis">
      <div class="ev-kpi">
        <i class="fas fa-clipboard-list"></i>
        <div><strong>{{ n(registros.length) }}</strong><span>Registros</span><small>{{ fechasBase }} fechas de muestreo</small></div>
      </div>
      <div class="ev-kpi is-ens">
        <i class="fas fa-flask"></i>
        <div><strong>{{ ensayosConDatos }}<em>/ {{ ensayos.length }}</em></strong><span>Ensayos evaluados</span><small>con registros</small></div>
      </div>
      <div class="ev-kpi is-var">
        <i class="fas fa-ruler-combined"></i>
        <div><strong>{{ variablesMedidas.total }}</strong><span>Variables medidas</span><small>{{ variablesMedidas.des }} desarrollo · {{ variablesMedidas.cos }} cosecha</small></div>
      </div>
      <div class="ev-kpi is-ult">
        <i class="fas fa-calendar-check"></i>
        <div><strong>{{ ultimoMuestreo ? fmt(ultimoMuestreo.fecha) : '—' }}</strong><span>Ultimo muestreo</span><small>{{ ultimoMuestreo ? ultimoMuestreo.n + ' registros ese dia' : 'sin registros' }}</small></div>
      </div>
      <button type="button" class="ev-kpi is-alerta" :class="{ 'is-on': soloFuera }" @click="verFueraRango">
        <i class="fas fa-exclamation-triangle"></i>
        <div><strong>{{ n(fueraTotal) }}</strong><span>Fuera de rango</span><small>{{ fueraTotal ? 'clic para revisarlos' : 'todo dentro de lo esperado' }}</small></div>
      </button>
    </div>

    <!-- ============ Capturas de formularios pendientes ============ -->
    <div v-if="Number(pendientesFormularios) > 0" class="ev-pend">
      <i class="fas fa-clipboard-list"></i>
      <span><b>{{ n(pendientesFormularios) }}</b> captura(s) de formularios de campo pendientes de revisar antes de pasar a Evaluaciones.</span>
      <a v-if="puedeVerFormularios" class="btn btn-sm ev-btn-nar" :href="`${base}/soporte/formularios?tab=capturas`"><i class="fas fa-arrow-right"></i> Revisar capturas</a>
    </div>

    <!-- ============ Filtros ============ -->
    <div class="ev-bar">
      <div class="ev-campo ev-campo-ens">
        <label>Ensayo</label>
        <select class="form-control form-control-sm" v-model="filtroEnsayo">
          <option value="">Todos los ensayos</option>
          <option v-for="e in ensayos" :key="e.id" :value="e.id">{{ e.codigo }}{{ e.finca ? ' · ' + e.finca : '' }}</option>
        </select>
      </div>
      <div class="ev-campo">
        <label>Categoria</label>
        <div class="ev-seg-mini">
          <button type="button" :class="{ 'is-on': !filtroCat }" @click="filtroCat = ''">Todas</button>
          <button type="button" class="is-des" :class="{ 'is-on': filtroCat === 'Desarrollo' }" @click="filtroCat = 'Desarrollo'"><i class="fas fa-seedling"></i> Desarrollo</button>
          <button type="button" class="is-cos" :class="{ 'is-on': filtroCat === 'Cosecha' }" @click="filtroCat = 'Cosecha'"><i class="fas fa-tractor"></i> Cosecha</button>
        </div>
      </div>
      <div class="ev-campo">
        <label>Variable</label>
        <select class="form-control form-control-sm" v-model="filtroVar">
          <option value="">Todas</option>
          <option v-for="v in variablesFiltro" :key="v.id" :value="v.id">{{ v.nombre }}{{ v.unidad ? ' (' + v.unidad + ')' : '' }}</option>
        </select>
      </div>
      <div class="ev-campo">
        <label>Fechas</label>
        <div class="ev-fechas">
          <input type="date" class="form-control form-control-sm" v-model="desde" title="Desde">
          <span>a</span>
          <input type="date" class="form-control form-control-sm" v-model="hasta" title="Hasta">
        </div>
      </div>
      <button v-if="hayFiltros" type="button" class="ev-limpiar" @click="limpiarFiltros"><i class="fas fa-times"></i> Limpiar</button>
      <div class="ev-acc">
        <a v-if="puedeExportar" class="btn btn-sm ev-btn-sec" :href="urlExportar"><i class="fas fa-file-excel"></i> Exportar Excel</a>
        <button v-if="puedeRegistrar" type="button" class="btn btn-sm ev-btn" @click="abrirCrear()"><i class="fas fa-plus"></i> Registrar</button>
      </div>
    </div>

    <!-- ============ Pestañas ============ -->
    <div class="ev-tabs">
      <button v-for="t in pestanas" :key="t.id" type="button" :class="{ 'is-on': tab === t.id }" @click="tab = t.id">
        <i :class="t.icon"></i> {{ t.label }}
        <small v-if="t.id === 'planilla' && plSucios">{{ plSucios }}</small>
      </button>
      <span v-if="ensayoSel" class="ev-tabs-ens"><i class="fas fa-flask"></i> {{ ensayoSel.codigo }} <em>{{ parcelasEnsayo.length }} parcelas</em></span>
    </div>

    <!-- ============ Selector de ensayo (pestañas que lo requieren) ============ -->
    <div v-if="requiereEnsayo && !ensayoSel" class="ev-picker">
      <p class="ev-picker-tit"><i class="fas fa-hand-pointer"></i> Seleccione un ensayo para {{ tab === 'matriz' ? 'ver su base matricial' : tab === 'planilla' ? 'capturar datos' : 'graficar sus variables' }}</p>
      <div v-if="!ensayos.length" class="ev-vacio"><i class="fas fa-flask"></i><strong>No hay ensayos disponibles.</strong></div>
      <div class="ev-picker-grid">
        <article v-for="r in resumenEnsayos" :key="r.e.id" class="ev-ens-card" tabindex="0" @click="filtroEnsayo = r.e.id" @keyup.enter="filtroEnsayo = r.e.id">
          <div class="ev-ens-top">
            <b>{{ r.e.codigo }}</b>
            <span class="ev-estado" :class="'is-' + estadoClase(r.e.estado)">{{ r.e.estado }}</span>
          </div>
          <small>{{ [r.e.finca, r.e.variedad].filter(Boolean).join(' · ') || 'sin finca / variedad' }}</small>
          <div class="ev-ens-stats">
            <span><b>{{ r.parcelas }}</b> parcelas</span>
            <span><b>{{ n(r.registros) }}</b> registros</span>
            <span><b>{{ r.variables }}</b> variables</span>
          </div>
          <div class="ev-ens-pie">
            <span v-if="r.ultima"><i class="fas fa-calendar-alt"></i> ultimo {{ fmt(r.ultima) }}</span>
            <span v-else class="ev-muted">sin evaluaciones</span>
            <span v-if="!r.parcelas" class="ev-aviso-mini">sin parcelas</span>
          </div>
        </article>
      </div>
    </div>

    <!-- ============ MATRIZ ============ -->
    <section v-else-if="tab === 'matriz'" class="ev-caja">
      <div v-if="!parcelasEnsayo.length" class="ev-vacio"><i class="fas fa-th"></i><strong>El ensayo no tiene parcelas.</strong><span>Genere las parcelas desde el detalle del ensayo.</span></div>
      <template v-else>
        <div class="ev-info">
          <i class="fas fa-info-circle"></i>
          <span><b>{{ parcelasEnsayo.length }}</b> parcelas · <b>{{ matGrupos.length }}</b> variables · <b>{{ matColumnas }}</b> columnas variable × fecha</span>
          <span class="ev-cobertura" :title="`${matLlenas} de ${matTotal} celdas con dato`">
            <span class="ev-cob-barra"><i :style="{ width: matPct + '%' }"></i></span> {{ matPct }} % completo
          </span>
          <div class="ev-info-acc">
            <select class="form-control form-control-sm" v-model="matOrden" title="Orden de filas">
              <option value="trat">Por tratamiento</option>
              <option value="rep">Por repeticion</option>
              <option value="codigo">Por codigo de parcela</option>
            </select>
            <button type="button" class="btn btn-sm ev-btn-sec" :disabled="!matGrupos.length" @click="descargarMatriz"><i class="fas fa-download"></i> CSV</button>
            <button v-if="puedeRegistrar" type="button" class="btn btn-sm ev-btn-sec" @click="colNueva = colNueva ? null : { variable_id: '', fecha: hoy() }"><i class="fas fa-calendar-plus"></i> Nueva columna</button>
          </div>
        </div>

        <transition name="ev-fade">
          <div v-if="colNueva" class="ev-colnueva">
            <span>Agregar fecha de muestreo:</span>
            <select class="form-control form-control-sm" v-model="colNueva.variable_id">
              <option value="">Variable...</option>
              <optgroup v-for="c in ['Desarrollo', 'Cosecha']" :key="c" :label="c">
                <option v-for="v in variablesActivas.filter((x) => x.categoria === c)" :key="v.id" :value="v.id">{{ v.nombre }}{{ v.unidad ? ' (' + v.unidad + ')' : '' }}</option>
              </optgroup>
            </select>
            <input type="date" class="form-control form-control-sm" v-model="colNueva.fecha">
            <button type="button" class="btn btn-sm ev-btn" :disabled="!colNueva.variable_id || !colNueva.fecha" @click="agregarColumna">Agregar</button>
            <button type="button" class="ev-x-mini" @click="colNueva = null">×</button>
          </div>
        </transition>

        <div v-if="!matGrupos.length" class="ev-vacio is-in">
          <i class="fas fa-table"></i>
          <strong>Este ensayo aun no tiene evaluaciones{{ hayFiltros ? ' con los filtros aplicados' : '' }}.</strong>
          <span v-if="puedeRegistrar">Agregue una <a href="#" @click.prevent="colNueva = { variable_id: '', fecha: hoy() }">nueva columna</a> o use la <a href="#" @click.prevent="tab = 'planilla'">planilla de captura</a>.</span>
        </div>
        <div v-else class="ev-mat-scroll">
          <table class="ev-mat">
            <thead>
              <tr>
                <th class="fx fx1" rowspan="2">Parcela</th>
                <th class="fx fx2" rowspan="2">Trat.</th>
                <th class="fx fx3" rowspan="2">Rep.</th>
                <th v-for="g in matGrupos" :key="g.v.id" :colspan="g.fechas.length" class="ev-th-var" :class="g.v.categoria === 'Cosecha' ? 'is-cos' : 'is-des'" :title="rango(g.v)">
                  {{ g.v.nombre }} <small>{{ g.v.unidad }}</small>
                </th>
              </tr>
              <tr>
                <template v-for="g in matGrupos">
                  <th v-for="(f, fi) in g.fechas" :key="g.v.id + f" class="ev-th-fecha" :class="{ 'is-ini': fi === 0, 'is-nueva': esColNueva(g.v.id, f) }">{{ fmtCorto(f) }}</th>
                </template>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(p, pi) in matParcelas" :key="p.id" :class="{ 'is-corte': corteFila(pi) }">
                <td class="fx fx1"><b>{{ p.codigo }}</b></td>
                <td class="fx fx2"><span class="ev-trat" :style="{ background: colorTrat(p.tratamiento, 0.16), color: colorTrat(p.tratamiento) }" :title="p.tratamiento_desc">{{ p.tratamiento || '—' }}</span></td>
                <td class="fx fx3">R{{ p.repeticion }}</td>
                <template v-for="g in matGrupos">
                  <td
                    v-for="(f, fi) in g.fechas"
                    :key="g.v.id + f"
                    class="ev-cel"
                    :class="[claseCelda(p, g.v, f), { 'is-fm': esDeFormulario(p, g.v, f), 'is-ini': fi === 0, 'is-edit': esEdit(p, g.v, f), 'is-ro': !puedeRegistrar }]"
                    :title="tituloCelda(p, g.v, f)"
                    @click="editarCelda(p, g.v, f)"
                  >
                    <template v-if="esEdit(p, g.v, f)">
                      <select v-if="g.v.tipo === 'Categorica' && g.v.opciones && g.v.opciones.length" v-model="editVal" class="ev-cel-in" @keydown.enter.prevent="guardarCelda(1)" @keydown.esc.prevent="cancelarCelda" @change="blurCelda(p, g.v, f)" @blur="blurCelda(p, g.v, f)">
                        <option value="">(vacio)</option>
                        <option v-for="o in g.v.opciones" :key="o" :value="o">{{ o }}</option>
                      </select>
                      <input v-else v-model="editVal" class="ev-cel-in" :inputmode="g.v.tipo === 'Numerica' ? 'decimal' : 'text'" @keydown.enter.prevent="guardarCelda(1)" @keydown.tab.prevent="guardarCelda(1)" @keydown.esc.prevent="cancelarCelda" @keydown.up.prevent="guardarCelda(-1)" @keydown.down.prevent="guardarCelda(1)" @blur="blurCelda(p, g.v, f)">
                    </template>
                    <template v-else-if="celda(p, g.v, f)">{{ celda(p, g.v, f).valor }}</template>
                    <span v-else class="ev-mas">+</span>
                  </td>
                </template>
              </tr>
            </tbody>
            <tfoot>
              <tr>
                <td class="fx fx1 ev-pie-tit" colspan="3">Promedio</td>
                <template v-for="g in matGrupos">
                  <td v-for="(f, fi) in g.fechas" :key="g.v.id + f" class="ev-pie" :class="{ 'is-ini': fi === 0 }">{{ promedioColumna(g.v, f) }}</td>
                </template>
              </tr>
            </tfoot>
          </table>
        </div>
        <div class="ev-leyenda">
          <span><i class="is-ok"></i> Dentro del rango esperado</span>
          <span><i class="is-fuera"></i> Fuera de rango</span>
          <span><i class="is-sin"></i> Sin rango definido / categorica</span>
          <span><i class="is-vacia"></i> Sin dato{{ puedeRegistrar ? ' (clic para ingresar)' : '' }}</span>
          <span><i class="is-fm"></i> Viene de un formulario de campo</span>
          <span v-if="puedeRegistrar" class="ev-teclas"><kbd>Enter</kbd> guarda y baja · <kbd>Esc</kbd> cancela · borrar el valor elimina el dato</span>
        </div>
      </template>
    </section>

    <!-- ============ PLANILLA DE CAPTURA ============ -->
    <section v-else-if="tab === 'planilla'" class="ev-caja">
      <div v-if="!puedeRegistrar" class="ev-vacio is-in"><i class="fas fa-lock"></i><strong>No tiene permiso para registrar evaluaciones.</strong></div>
      <div v-else-if="!parcelasEnsayo.length" class="ev-vacio is-in"><i class="fas fa-th"></i><strong>El ensayo no tiene parcelas.</strong></div>
      <template v-else>
        <div class="ev-pl-cab">
          <div class="ev-campo">
            <label>Fecha de muestreo</label>
            <input type="date" class="form-control form-control-sm" v-model="plFecha" :max="hoy()">
          </div>
          <div class="ev-campo ev-pl-vars">
            <label>Variables a capturar <small class="ev-muted">({{ plVars.length }} seleccionadas)</small></label>
            <div class="ev-chips">
              <button
                v-for="v in variablesActivas.filter((x) => !filtroCat || x.categoria === filtroCat)"
                :key="v.id"
                type="button"
                class="ev-chip"
                :class="[v.categoria === 'Cosecha' ? 'is-cos' : 'is-des', { 'is-on': plVars.includes(v.id) }]"
                @click="toggleVar(v.id)"
              >
                <i :class="plVars.includes(v.id) ? 'fas fa-check' : 'fas fa-plus'"></i> {{ v.nombre }}
              </button>
              <span v-if="!variablesActivas.length" class="ev-muted">No hay variables activas. Creelas en Variables.</span>
            </div>
          </div>
        </div>
        <div v-if="!plColumnas.length" class="ev-vacio is-in"><i class="fas fa-columns"></i><strong>Elija las variables a capturar.</strong><span>Cada variable sera una columna; cada parcela una fila.</span></div>
        <template v-else>
          <p class="ev-ayuda"><i class="fas fa-keyboard"></i> Escriba y presione <kbd>Enter</kbd> para bajar a la siguiente parcela. Puede <b>pegar una columna desde Excel</b> en la primera celda. Los valores existentes de la fecha aparecen precargados; dejar vacio un valor existente lo elimina.</p>
          <div class="ev-mat-scroll">
            <table class="ev-mat ev-pl">
              <thead>
                <tr>
                  <th class="fx fx1">Parcela</th>
                  <th class="fx fx2">Trat.</th>
                  <th class="fx fx3">Rep.</th>
                  <th v-for="v in plColumnas" :key="v.id" class="ev-th-var" :class="v.categoria === 'Cosecha' ? 'is-cos' : 'is-des'">
                    {{ v.nombre }} <small>{{ v.unidad }}</small>
                    <em>{{ v.tipo === 'Numerica' ? rango(v) : v.tipo === 'Categorica' ? 'opciones' : 'texto' }}</em>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(p, r) in matParcelas" :key="p.id" :class="{ 'is-corte': corteFila(r) }">
                  <td class="fx fx1"><b>{{ p.codigo }}</b></td>
                  <td class="fx fx2"><span class="ev-trat" :style="{ background: colorTrat(p.tratamiento, 0.16), color: colorTrat(p.tratamiento) }">{{ p.tratamiento || '—' }}</span></td>
                  <td class="fx fx3">R{{ p.repeticion }}</td>
                  <td v-for="(v, c) in plColumnas" :key="v.id" class="ev-pl-cel" :class="clasePlanilla(p, v)" :title="plErr[clave(p.id, v.id)] || ''">
                    <select v-if="v.tipo === 'Categorica' && v.opciones && v.opciones.length" :data-pl="r + '-' + c" v-model="plVals[clave(p.id, v.id)]" @keydown.enter.prevent="moverPl(r, c, 1)">
                      <option value=""></option>
                      <option v-for="o in v.opciones" :key="o" :value="o">{{ o }}</option>
                    </select>
                    <input
                      v-else
                      :data-pl="r + '-' + c"
                      v-model="plVals[clave(p.id, v.id)]"
                      :inputmode="v.tipo === 'Numerica' ? 'decimal' : 'text'"
                      autocomplete="off"
                      @keydown.enter.prevent="moverPl(r, c, $event.shiftKey ? -1 : 1)"
                      @keydown.down.prevent="moverPl(r, c, 1)"
                      @keydown.up.prevent="moverPl(r, c, -1)"
                      @paste="pegar($event, r, c)"
                    >
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="ev-pl-pie">
            <span class="ev-muted">
              <b>{{ plSucios }}</b> cambio(s) sin guardar
              <template v-if="plAlertas.fuera"> · <span class="ev-txt-nar">{{ plAlertas.fuera }} fuera de rango</span></template>
              <template v-if="plAlertas.invalidos"> · <span class="ev-txt-err">{{ plAlertas.invalidos }} no validos</span></template>
            </span>
            <button type="button" class="btn btn-sm ev-btn-sec" :disabled="!plSucios || plGuardando" @click="initPlanilla(true)">Descartar</button>
            <button type="button" class="btn btn-sm ev-btn" :disabled="!plSucios || plGuardando || plAlertas.invalidos > 0" @click="guardarPlanilla">
              <i :class="plGuardando ? 'fas fa-spinner fa-spin' : 'fas fa-save'"></i> Guardar {{ plSucios || '' }}
            </button>
          </div>
        </template>
      </template>
    </section>

    <!-- ============ LISTADO ============ -->
    <section v-else-if="tab === 'listado'" class="ev-caja is-flush">
      <div class="ev-lst-bar">
        <div class="ev-buscar">
          <i class="fas fa-search"></i>
          <input type="text" v-model="busqueda" placeholder="Buscar parcela, tratamiento, variable, valor, responsable...">
          <button v-if="busqueda" type="button" @click="busqueda = ''">×</button>
        </div>
        <label class="ev-check-in"><input type="checkbox" v-model="soloFuera"> Solo fuera de rango</label>
        <span class="ev-muted ev-lst-ct">{{ n(listado.length) }} registro(s)</span>
      </div>
      <div v-if="!listado.length" class="ev-vacio is-in">
        <i class="fas fa-clipboard"></i>
        <strong>{{ items.length ? 'Ningun registro coincide con los filtros.' : 'Aun no hay evaluaciones registradas.' }}</strong>
        <a v-if="items.length" href="#" @click.prevent="limpiarFiltros">Quitar filtros</a>
      </div>
      <div v-else class="ev-tabla-wrap">
        <table class="table ev-tabla">
          <thead>
            <tr><th>Fecha</th><th>Ensayo</th><th>Parcela</th><th>Trat.</th><th>Rep.</th><th>Variable</th><th class="text-right">Valor</th><th>Responsable</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="e in paginaListado" :key="e.id" :class="{ 'is-fuera': esFuera(e) }" @click="abrirEditar(e)">
              <td class="ev-nowrap">{{ fmt(e.fecha) }}</td>
              <td>{{ ensayoDe(e) ? ensayoDe(e).codigo : '—' }}</td>
              <td><b>{{ parcelaDe(e) ? parcelaDe(e).codigo : '—' }}</b></td>
              <td><span v-if="parcelaDe(e)" class="ev-trat" :style="{ background: colorTrat(parcelaDe(e).tratamiento, 0.16), color: colorTrat(parcelaDe(e).tratamiento) }">{{ parcelaDe(e).tratamiento || '—' }}</span></td>
              <td>{{ parcelaDe(e) ? 'R' + parcelaDe(e).repeticion : '' }}</td>
              <td>
                <span class="ev-cat-dot" :class="variableDe(e) && variableDe(e).categoria === 'Cosecha' ? 'is-cos' : 'is-des'"></span>
                {{ variableDe(e) ? variableDe(e).nombre : '—' }}
              </td>
              <td class="text-right ev-nowrap">
                <i v-if="esFuera(e)" class="fas fa-exclamation-triangle ev-txt-nar" :title="'Fuera de rango: ' + rango(variableDe(e))"></i>
                <b>{{ e.valor }}</b> <small class="ev-muted">{{ variableDe(e) ? variableDe(e).unidad : '' }}</small>
                <i v-if="e.formulario" class="fas fa-clipboard-list ev-txt-fm" :title="`Desde formulario: ${e.formulario}`"></i>
                <i v-if="e.obs" class="fas fa-comment-dots ev-muted" :title="e.obs"></i>
              </td>
              <td class="ev-muted">{{ e.responsable || '—' }}</td>
              <td class="text-right ev-nowrap" @click.stop>
                <button v-if="puedeRegistrar" type="button" class="ev-icon" title="Editar" @click="abrirEditar(e)"><i class="fas fa-pen"></i></button>
                <button v-if="puedeEliminar" type="button" class="ev-icon is-del" title="Eliminar" @click="eliminar(e)"><i class="fas fa-trash"></i></button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="paginas > 1" class="ev-paginas">
        <button type="button" :disabled="pagina === 1" @click="pagina--"><i class="fas fa-chevron-left"></i></button>
        <span>Pagina {{ pagina }} de {{ paginas }}</span>
        <button type="button" :disabled="pagina === paginas" @click="pagina++"><i class="fas fa-chevron-right"></i></button>
      </div>
    </section>

    <!-- ============ GRAFICOS ============ -->
    <section v-else-if="tab === 'graficos'" class="ev-caja">
      <div class="ev-g-vistas">
        <button type="button" :class="{ 'is-on': gVista === 'tratamientos' }" @click="gVista = 'tratamientos'">
          <i class="fas fa-chart-bar"></i> Comparar tratamientos <small>una fecha · medias y estadisticos</small>
        </button>
        <button type="button" :class="{ 'is-on': gVista === 'tiempo' }" @click="gVista = 'tiempo'">
          <i class="fas fa-stream"></i> Linea de tiempo <small>evolucion de campo y laboratorio</small>
        </button>
      </div>

      <linea-tiempo
        v-if="gVista === 'tiempo'"
        :ensayo="ensayoSel"
        :registros="registros"
        :variables="variables"
        :parcelas="parcelasEnsayo"
        :tratamientos-ensayo="tratamientos"
        :base="base"
      ></linea-tiempo>

      <div v-else-if="!gVariables.length" class="ev-vacio is-in"><i class="fas fa-chart-line"></i><strong>Este ensayo aun no tiene datos para graficar.</strong></div>
      <template v-else>
        <div class="ev-g-cab">
          <div class="ev-campo">
            <label>Variable</label>
            <select class="form-control form-control-sm" v-model="gVar">
              <option v-for="v in gVariables" :key="v.id" :value="v.id">{{ v.nombre }}{{ v.unidad ? ' (' + v.unidad + ')' : '' }}</option>
            </select>
          </div>
          <div class="ev-campo">
            <label>Fecha para comparar tratamientos</label>
            <select class="form-control form-control-sm" v-model="gFecha">
              <option v-for="f in gFechas" :key="f" :value="f">{{ fmt(f) }}</option>
            </select>
          </div>
        </div>
        <div class="ev-g-grid">
          <div class="ev-chart"><highcharts :options="chartTratamientos"></highcharts></div>
          <div v-if="gVarObj && gVarObj.tipo === 'Numerica'" class="ev-g-tabla">
            <h6>Estadisticos por tratamiento · {{ fmt(gFecha) }}</h6>
            <table class="table ev-mini">
              <thead><tr><th>Trat.</th><th class="text-right">n</th><th class="text-right">Media</th><th class="text-right">D.E.</th><th class="text-right">C.V.</th><th class="text-right">Min – Max</th><th class="text-right">vs. media</th></tr></thead>
              <tbody>
                <tr v-for="s in gStats" :key="s.trat">
                  <td><span class="ev-trat" :style="{ background: colorTrat(s.trat, 0.16), color: colorTrat(s.trat) }">{{ s.trat }}</span></td>
                  <td class="text-right">{{ s.n }}</td>
                  <td class="text-right"><b>{{ dec(s.media, gVarObj) }}</b></td>
                  <td class="text-right">{{ dec(s.sd, gVarObj) }}</td>
                  <td class="text-right" :class="{ 'ev-txt-nar': s.cv > 20 }">{{ s.cv !== null ? s.cv.toFixed(1) + ' %' : '—' }}</td>
                  <td class="text-right ev-muted">{{ dec(s.min, gVarObj) }} – {{ dec(s.max, gVarObj) }}</td>
                  <td class="text-right" :class="s.dif >= 0 ? 'ev-txt-ok' : 'ev-txt-nar'">{{ s.dif !== null ? (s.dif >= 0 ? '+' : '') + s.dif.toFixed(1) + ' %' : '—' }}</td>
                </tr>
              </tbody>
            </table>
            <p class="ev-muted ev-nota">C.V. mayor a 20 % se resalta: indica alta variabilidad entre repeticiones.</p>
          </div>
        </div>
      </template>
    </section>

    <!-- ============ Panel lateral: registro / edicion ============ -->
    <transition name="ev-fade"><div v-if="panel" class="ev-velo" @click="cerrar"></div></transition>
    <transition name="ev-slide">
      <aside v-if="panel" class="ev-panel" role="dialog">
        <header class="ev-ph" :class="formVar && formVar.categoria === 'Cosecha' ? 'is-cos' : 'is-des'">
          <span class="ev-ico"><i :class="form.id ? 'fas fa-pen' : 'fas fa-plus'"></i></span>
          <div>
            <h5>{{ form.id ? 'Editar evaluacion' : 'Registrar evaluacion' }}</h5>
            <small>{{ formVar ? formVar.nombre : 'Seleccione variable' }}{{ formParcela ? ' · parcela ' + formParcela.codigo : '' }}</small>
          </div>
          <button type="button" class="ev-x" @click="cerrar">×</button>
        </header>
        <form class="ev-pb" novalidate @submit.prevent="guardar">
          <fieldset :disabled="!puedeRegistrar">
            <div class="form-row">
              <div class="col-7 form-group">
                <label>Ensayo *</label>
                <select class="form-control" v-model="form.ensayo_id" @change="form.parcela_id = null">
                  <option :value="null">Seleccione...</option>
                  <option v-for="e in ensayos" :key="e.id" :value="e.id">{{ e.codigo }}</option>
                </select>
              </div>
              <div class="col-5 form-group">
                <label>Fecha *</label>
                <input type="date" class="form-control" v-model="form.fecha" :class="{ 'is-invalid': errors.fecha }">
                <span class="invalid-feedback" v-if="errors.fecha">{{ errors.fecha[0] }}</span>
              </div>
            </div>
            <div class="form-group">
              <label>Parcela *</label>
              <div v-if="form.ensayo_id && formParcelas.length" class="ev-parcelas">
                <button v-for="p in formParcelas" :key="p.id" type="button" :class="{ 'is-on': form.parcela_id === p.id }" :style="form.parcela_id === p.id ? { borderColor: colorTrat(p.tratamiento), background: colorTrat(p.tratamiento, 0.14) } : {}" @click="form.parcela_id = p.id">
                  <b>{{ p.codigo }}</b><small>{{ p.tratamiento }} · R{{ p.repeticion }}</small>
                </button>
              </div>
              <p v-else class="ev-muted ev-nota">{{ form.ensayo_id ? 'El ensayo no tiene parcelas.' : 'Elija primero el ensayo.' }}</p>
              <span class="ev-error" v-if="errors.parcela_id">{{ errors.parcela_id[0] }}</span>
            </div>
            <div class="form-group">
              <label>Variable *</label>
              <select class="form-control" v-model="form.variable_id" :class="{ 'is-invalid': errors.variable_id }">
                <option :value="null">Seleccione...</option>
                <optgroup v-for="c in ['Desarrollo', 'Cosecha']" :key="c" :label="c === 'Cosecha' ? 'Cosecha / fabrica' : 'Desarrollo del cultivo'">
                  <option v-for="v in variablesForm.filter((x) => x.categoria === c)" :key="v.id" :value="v.id">{{ v.nombre }}{{ v.unidad ? ' (' + v.unidad + ')' : '' }}</option>
                </optgroup>
              </select>
              <span class="invalid-feedback" v-if="errors.variable_id">{{ errors.variable_id[0] }}</span>
            </div>
            <div class="form-group">
              <label>Valor * <small v-if="formVar" class="ev-muted">{{ formVar.tipo === 'Numerica' ? 'rango esperado: ' + rango(formVar) : formVar.tipo === 'Categorica' ? 'elija una opcion' : 'texto libre' }}</small></label>
              <div v-if="formVar && formVar.tipo === 'Categorica' && formVar.opciones && formVar.opciones.length" class="ev-opciones">
                <button v-for="o in formVar.opciones" :key="o" type="button" :class="{ 'is-on': form.valor === o }" @click="form.valor = o">{{ o }}</button>
              </div>
              <div v-else class="ev-valor" :class="'is-' + estadoForm">
                <input class="form-control" ref="valor" v-model.trim="form.valor" :inputmode="formVar && formVar.tipo === 'Numerica' ? 'decimal' : 'text'" maxlength="150" :class="{ 'is-invalid': errors.valor }" placeholder="Valor medido">
                <span v-if="formVar && formVar.unidad">{{ formVar.unidad }}</span>
              </div>
              <span class="ev-error" v-if="errors.valor">{{ errors.valor[0] }}</span>
              <p v-else-if="estadoForm === 'fuera'" class="ev-alerta"><i class="fas fa-exclamation-triangle"></i> Fuera del rango esperado ({{ rango(formVar) }}). Verifique antes de guardar.</p>
              <p v-else-if="estadoForm === 'invalido'" class="ev-error">Debe ser un numero.</p>
            </div>
            <div v-if="formPrevio" class="ev-previo">
              <i class="fas fa-history"></i> Ya existe un registro de esta parcela y variable en la fecha: <b>{{ formPrevio.valor }}</b>. Se actualizara.
            </div>
            <div class="form-group">
              <label>Observaciones</label>
              <textarea class="form-control" rows="2" maxlength="1000" v-model="form.obs" placeholder="Condicion de la muestra, incidencias..."></textarea>
            </div>
            <div v-if="form.id && formOriginal" class="ev-meta">
              Registrado por <b>{{ formOriginal.responsable || '—' }}</b><template v-if="formOriginal.creado"> el {{ fmt(formOriginal.creado) }}</template>
            </div>
            <p v-if="errorGeneral" class="ev-error"><i class="fas fa-exclamation-circle"></i> {{ errorGeneral }}</p>
          </fieldset>
        </form>
        <footer class="ev-pf">
          <button v-if="form.id && puedeEliminar" type="button" class="btn btn-sm ev-btn-del" @click="eliminar(formOriginal)"><i class="fas fa-trash"></i> Eliminar</button>
          <label v-if="!form.id && puedeRegistrar" class="ev-otra"><input type="checkbox" v-model="seguir"> Registrar otra</label>
          <button type="button" class="btn btn-sm ev-btn-sec" @click="cerrar">Cancelar</button>
          <button v-if="puedeRegistrar" type="button" class="btn btn-sm ev-btn" :disabled="guardando || !formListo" @click="guardar">
            <i :class="guardando ? 'fas fa-spinner fa-spin' : 'fas fa-save'"></i> Guardar
          </button>
        </footer>
      </aside>
    </transition>
  </div>
</template>

<script>
const PALETA = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948', '#5b8a99', '#a0522d'];
const POR_PAGINA = 50;
const PESTANAS = [
  { id: 'matriz', label: 'Base matricial', icon: 'fas fa-th' },
  { id: 'planilla', label: 'Planilla de captura', icon: 'fas fa-keyboard' },
  { id: 'listado', label: 'Listado', icon: 'fas fa-list' },
  { id: 'graficos', label: 'Graficos', icon: 'fas fa-chart-line' },
];

import LineaTiempo from './linea-tiempo.vue';

export default {
  name: 'EvaluacionesIndex',
  components: { LineaTiempo },
  props: {
    evaluaciones: { type: Array, default: () => [] },
    variables: { type: Array, default: () => [] },
    parcelas: { type: Array, default: () => [] },
    ensayos: { type: Array, default: () => [] },
    usuarioId: { type: Number, default: null },
    puedeRegistrar: { type: Boolean, default: false },
    puedeEliminar: { type: Boolean, default: false },
    puedeExportar: { type: Boolean, default: false },
    pendientesFormularios: { type: [Number, String], default: 0 },
    puedeVerFormularios: { type: Boolean, default: false },
  },
  data() {
    return {
      items: this.evaluaciones.slice(),
      pestanas: PESTANAS,
      tab: this.tabInicial(),
      gVista: new URLSearchParams(window.location.search).get('vista') === 'tiempo' ? 'tiempo' : 'tratamientos',
      filtroEnsayo: this.ensayoInicial(),
      filtroCat: '',
      filtroVar: '',
      desde: '',
      hasta: '',
      busqueda: '',
      soloFuera: false,
      pagina: 1,
      // matriz
      matOrden: 'trat',
      colNueva: null,
      colsExtra: [],
      editCell: null,
      editVal: '',
      guardandoCeldas: {},
      // planilla
      plFecha: this.hoy(),
      plVars: [],
      plVals: {},
      plOrig: {},
      plErr: {},
      plGuardando: false,
      // graficos
      gVar: null,
      gFecha: null,
      // panel
      panel: null,
      form: this.formVacio(),
      formOriginal: null,
      errors: {},
      errorGeneral: null,
      guardando: false,
      seguir: false,
      base: ((document.querySelector('meta[name="app-base"]') || {}).content || '').replace(/\/+$/, ''),
    };
  },
  computed: {
    varMap() { return new Map(this.variables.map((v) => [v.id, v])); },
    parcMap() { return new Map(this.parcelas.map((p) => [p.id, p])); },
    ensMap() { return new Map(this.ensayos.map((e) => [e.id, e])); },
    ensayoSel() { return this.filtroEnsayo ? this.ensMap.get(this.filtroEnsayo) || null : null; },
    variablesActivas() { return this.variables.filter((v) => v.activa !== false); },
    variablesFiltro() { return this.variables.filter((v) => !this.filtroCat || v.categoria === this.filtroCat); },
    requiereEnsayo() { return this.tab !== 'listado'; },
    hayFiltros() { return !!(this.filtroCat || this.filtroVar || this.desde || this.hasta || this.busqueda || this.soloFuera); },
    urlExportar() { return `${this.base}/soporte/reportes/exportar/evaluaciones${this.filtroEnsayo ? `?ensayo_id=${this.filtroEnsayo}` : ''}`; },

    /* Registros que cumplen ensayo, categoria, variable y fechas (base de KPIs, matriz y listado). */
    registros() {
      return this.items.filter((e) => {
        const p = this.parcMap.get(e.parcela_id);
        const v = this.varMap.get(e.variable_id);
        if (!p || !v) return false;
        if (this.filtroEnsayo && p.ensayo_id !== this.filtroEnsayo) return false;
        if (this.filtroCat && v.categoria !== this.filtroCat) return false;
        if (this.filtroVar && v.id !== this.filtroVar) return false;
        if (this.desde && e.fecha < this.desde) return false;
        if (this.hasta && e.fecha > this.hasta) return false;
        return true;
      });
    },
    fechasBase() { return new Set(this.registros.map((e) => e.fecha)).size; },
    ensayosConDatos() { return new Set(this.items.map((e) => (this.parcMap.get(e.parcela_id) || {}).ensayo_id).filter(Boolean)).size; },
    variablesMedidas() {
      const ids = new Set(this.registros.map((e) => e.variable_id));
      const vs = [...ids].map((id) => this.varMap.get(id)).filter(Boolean);
      return { total: vs.length, des: vs.filter((v) => v.categoria !== 'Cosecha').length, cos: vs.filter((v) => v.categoria === 'Cosecha').length };
    },
    ultimoMuestreo() {
      if (!this.registros.length) return null;
      const fecha = this.registros.reduce((m, e) => (e.fecha > m ? e.fecha : m), '');
      return { fecha, n: this.registros.filter((e) => e.fecha === fecha).length };
    },
    fueraTotal() { return this.registros.filter((e) => this.esFuera(e)).length; },
    resumenEnsayos() {
      const r = new Map(this.ensayos.map((e) => [e.id, { e, parcelas: 0, registros: 0, vars: new Set(), ultima: null }]));
      this.parcelas.forEach((p) => { const x = r.get(p.ensayo_id); if (x) x.parcelas += 1; });
      this.items.forEach((ev) => {
        const p = this.parcMap.get(ev.parcela_id);
        const x = p && r.get(p.ensayo_id);
        if (!x) return;
        x.registros += 1;
        x.vars.add(ev.variable_id);
        if (!x.ultima || ev.fecha > x.ultima) x.ultima = ev.fecha;
      });
      return [...r.values()].map((x) => ({ ...x, variables: x.vars.size }))
        .sort((a, b) => String(b.ultima || '').localeCompare(String(a.ultima || '')) || a.e.codigo.localeCompare(b.e.codigo, 'es', { numeric: true }));
    },

    /* ---------- matriz ---------- */
    parcelasEnsayo() { return this.filtroEnsayo ? this.parcelas.filter((p) => p.ensayo_id === this.filtroEnsayo) : []; },
    matParcelas() {
      const cmp = (a, b) => String(a || '').localeCompare(String(b || ''), 'es', { numeric: true });
      const lista = this.parcelasEnsayo.slice();
      if (this.matOrden === 'codigo') return lista.sort((a, b) => cmp(a.codigo, b.codigo));
      if (this.matOrden === 'rep') return lista.sort((a, b) => (a.repeticion - b.repeticion) || cmp(a.tratamiento, b.tratamiento));
      return lista.sort((a, b) => cmp(a.tratamiento, b.tratamiento) || (a.repeticion - b.repeticion));
    },
    celdas() {
      const m = new Map();
      this.items.forEach((e) => { m.set(this.claveCelda(e.parcela_id, e.variable_id, e.fecha), e); });
      return m;
    },
    matGrupos() {
      if (!this.filtroEnsayo) return [];
      const porVar = new Map();
      const add = (vid, f) => {
        const v = this.varMap.get(vid);
        if (!v) return;
        if (!porVar.has(vid)) porVar.set(vid, { v, fechas: new Set() });
        porVar.get(vid).fechas.add(f);
      };
      this.registros.forEach((e) => add(e.variable_id, e.fecha));
      this.colsExtra.filter((c) => c.ensayo_id === this.filtroEnsayo).forEach((c) => {
        const v = this.varMap.get(c.variable_id);
        if (v && (!this.filtroCat || v.categoria === this.filtroCat) && (!this.filtroVar || v.id === this.filtroVar)) add(c.variable_id, c.fecha);
      });
      const orden = { Desarrollo: 0, Cosecha: 1 };
      return [...porVar.values()]
        .map((g) => ({ v: g.v, fechas: [...g.fechas].sort() }))
        .sort((a, b) => (orden[a.v.categoria] || 0) - (orden[b.v.categoria] || 0) || a.v.nombre.localeCompare(b.v.nombre, 'es'));
    },
    matColumnas() { return this.matGrupos.reduce((a, g) => a + g.fechas.length, 0); },
    matTotal() { return this.matColumnas * this.parcelasEnsayo.length; },
    matLlenas() {
      let n = 0;
      this.matGrupos.forEach((g) => g.fechas.forEach((f) => this.parcelasEnsayo.forEach((p) => { if (this.celda(p, g.v, f)) n += 1; })));
      return n;
    },
    matPct() { return this.matTotal ? Math.round((this.matLlenas / this.matTotal) * 100) : 0; },
    tratamientos() {
      return [...new Set(this.parcelasEnsayo.map((p) => p.tratamiento || '—'))].sort((a, b) => a.localeCompare(b, 'es', { numeric: true }));
    },

    /* ---------- planilla ---------- */
    plColumnas() { return this.plVars.map((id) => this.varMap.get(id)).filter(Boolean); },
    plSucios() { return Object.keys(this.plVals).filter((k) => (this.plVals[k] || '').trim() !== (this.plOrig[k] || '')).length; },
    plAlertas() {
      let fuera = 0;
      let invalidos = 0;
      Object.keys(this.plVals).forEach((k) => {
        const val = (this.plVals[k] || '').trim();
        if (!val || val === (this.plOrig[k] || '')) return;
        const est = this.estadoValor(this.varMap.get(Number(k.split('|')[1])), val);
        if (est === 'fuera') fuera += 1;
        if (est === 'invalido') invalidos += 1;
      });
      return { fuera, invalidos };
    },

    /* ---------- listado ---------- */
    listado() {
      const q = this.sinTildes(this.busqueda.trim().toLowerCase());
      return this.registros.filter((e) => {
        if (this.soloFuera && !this.esFuera(e)) return false;
        if (!q) return true;
        const p = this.parcelaDe(e) || {};
        const v = this.variableDe(e) || {};
        const en = this.ensayoDe(e) || {};
        return this.sinTildes(`${p.codigo} ${p.tratamiento} ${v.nombre} ${e.valor} ${e.responsable || ''} ${en.codigo} ${e.obs || ''}`.toLowerCase()).includes(q);
      }).sort((a, b) => b.fecha.localeCompare(a.fecha) || b.id - a.id);
    },
    paginas() { return Math.max(1, Math.ceil(this.listado.length / POR_PAGINA)); },
    paginaListado() { return this.listado.slice((this.pagina - 1) * POR_PAGINA, this.pagina * POR_PAGINA); },

    /* ---------- graficos ---------- */
    gVariables() {
      const ids = new Set(this.registros.map((e) => e.variable_id));
      return [...ids].map((id) => this.varMap.get(id)).filter((v) => v && v.tipo !== 'Texto').sort((a, b) => a.nombre.localeCompare(b.nombre, 'es'));
    },
    gVarObj() { return this.varMap.get(this.gVar) || null; },
    gRegistros() { return this.registros.filter((e) => e.variable_id === this.gVar); },
    gFechas() { return [...new Set(this.gRegistros.map((e) => e.fecha))].sort(); },
    gStats() {
      const regs = this.gRegistros.filter((e) => e.fecha === this.gFecha);
      const todos = regs.map((e) => this.num(e.valor)).filter((x) => x !== null);
      const mediaGral = todos.length ? todos.reduce((a, b) => a + b, 0) / todos.length : null;
      return this.tratamientos.map((t) => {
        const xs = regs.filter((e) => (this.parcelaDe(e) || {}).tratamiento === t || (t === '—' && !(this.parcelaDe(e) || {}).tratamiento))
          .map((e) => this.num(e.valor)).filter((x) => x !== null);
        if (!xs.length) return { trat: t, n: 0, media: null, sd: null, cv: null, min: null, max: null, dif: null };
        const media = xs.reduce((a, b) => a + b, 0) / xs.length;
        const sd = xs.length > 1 ? Math.sqrt(xs.reduce((a, x) => a + (x - media) ** 2, 0) / (xs.length - 1)) : null;
        return {
          trat: t, n: xs.length, media, sd, cv: sd !== null && media ? (sd / Math.abs(media)) * 100 : null,
          min: Math.min(...xs), max: Math.max(...xs), dif: mediaGral ? ((media - mediaGral) / Math.abs(mediaGral)) * 100 : null,
        };
      }).filter((s) => s.n);
    },
    chartBase() {
      return {
        chart: { backgroundColor: '#ffffff', style: { fontFamily: 'inherit' } },
        credits: { enabled: false },
        exporting: { enabled: true, filename: `evaluaciones_${this.ensayoSel ? this.ensayoSel.codigo : ''}_${this.gVarObj ? this.gVarObj.nombre : ''}` },
        legend: { itemStyle: { fontSize: '11.5px', fontWeight: '600', color: '#1e2a1a' } },
      };
    },
    chartTratamientos() {
      const v = this.gVarObj;
      if (!v) return {};
      if (v.tipo === 'Categorica') {
        const regs = this.gRegistros.filter((e) => e.fecha === this.gFecha);
        const opciones = (v.opciones && v.opciones.length ? v.opciones : [...new Set(regs.map((e) => e.valor))]);
        return {
          ...this.chartBase,
          chart: { ...this.chartBase.chart, type: 'column', height: 300 },
          title: { text: `${v.nombre} por tratamiento · ${this.fmt(this.gFecha)}`, style: this.estiloTitulo },
          xAxis: { categories: this.tratamientos, lineColor: '#CED2D5' },
          yAxis: { title: { text: 'parcelas' }, allowDecimals: false, gridLineColor: '#edf1ec' },
          plotOptions: { column: { stacking: 'normal', borderWidth: 0, borderRadius: 2 } },
          series: opciones.map((o, i) => ({
            name: o,
            color: PALETA[i % PALETA.length],
            data: this.tratamientos.map((t) => regs.filter((e) => e.valor === o && ((this.parcelaDe(e) || {}).tratamiento || '—') === t).length),
          })),
        };
      }
      const stats = this.gStats;
      return {
        ...this.chartBase,
        chart: { ...this.chartBase.chart, type: 'column', height: 300 },
        title: { text: `${v.nombre} por tratamiento · ${this.fmt(this.gFecha)}`, style: this.estiloTitulo },
        subtitle: { text: `media de repeticiones; linea gris = media del ensayo${this.notaBanda}`, style: { color: '#6b7568', fontSize: '11px' } },
        legend: { enabled: false },
        xAxis: { categories: stats.map((s) => s.trat), lineColor: '#CED2D5' },
        yAxis: {
          title: { text: v.unidad || null },
          gridLineColor: '#edf1ec',
          plotBands: this.bandaRango(v),
          plotLines: this.mediaFecha !== null ? [{ value: this.mediaFecha, color: '#6b7568', dashStyle: 'Dash', width: 1.5, zIndex: 4 }] : [],
        },
        tooltip: { valueDecimals: v.decimales !== null && v.decimales !== undefined ? v.decimales : 2, valueSuffix: v.unidad ? ` ${v.unidad}` : '' },
        plotOptions: { column: { borderWidth: 0, borderRadius: 3, dataLabels: { enabled: true, format: `{point.y:.${v.decimales !== null && v.decimales !== undefined ? v.decimales : 1}f}`, style: { fontSize: '11px', textOutline: 'none' } } } },
        series: [{ name: v.nombre, data: stats.map((s) => ({ y: Number(s.media.toFixed(4)), color: this.colorTrat(s.trat) })) }],
      };
    },
    mediaFecha() {
      const xs = this.gRegistros.filter((e) => e.fecha === this.gFecha).map((e) => this.num(e.valor)).filter((x) => x !== null);
      return xs.length ? xs.reduce((a, b) => a + b, 0) / xs.length : null;
    },
    estiloTitulo() { return { fontSize: '13px', fontWeight: '700', color: '#1f5632' }; },
    notaBanda() { return this.bandaRango(this.gVarObj).length ? ' · fondo verde = rango esperado' : ''; },

    /* ---------- panel ---------- */
    formVar() { return this.form.variable_id ? this.varMap.get(this.form.variable_id) : null; },
    formParcela() { return this.form.parcela_id ? this.parcMap.get(this.form.parcela_id) : null; },
    formParcelas() {
      const cmp = (a, b) => String(a || '').localeCompare(String(b || ''), 'es', { numeric: true });
      return this.parcelas.filter((p) => p.ensayo_id === this.form.ensayo_id).sort((a, b) => cmp(a.tratamiento, b.tratamiento) || (a.repeticion - b.repeticion));
    },
    variablesForm() {
      // Al registrar solo se ofrecen las activas; al editar se mantiene la variable original aunque este inactiva.
      return this.variables.filter((v) => v.activa !== false || v.id === this.form.variable_id);
    },
    estadoForm() { return this.formVar ? this.estadoValor(this.formVar, this.form.valor) : 'vacio'; },
    formPrevio() {
      if (this.form.id || !this.form.parcela_id || !this.form.variable_id || !this.form.fecha) return null;
      return this.celdas.get(this.claveCelda(this.form.parcela_id, this.form.variable_id, this.form.fecha)) || null;
    },
    formListo() { return this.form.fecha && this.form.parcela_id && this.form.variable_id && String(this.form.valor || '').trim() !== '' && this.estadoForm !== 'invalido'; },
  },
  watch: {
    tab(v) { this.guardar_('tab', v); this.editCell = null; },
    filtroEnsayo(v) {
      this.guardar_('ensayo', v || '');
      this.editCell = null;
      this.colNueva = null;
      this.plVars = [];
      this.initPlanilla(true);
      this.ajustarGrafico();
    },
    filtroCat() { this.pagina = 1; if (this.filtroVar && this.varMap.get(this.filtroVar).categoria !== this.filtroCat && this.filtroCat) this.filtroVar = ''; },
    filtroVar() { this.pagina = 1; },
    busqueda() { this.pagina = 1; },
    soloFuera() { this.pagina = 1; },
    plFecha() { this.initPlanilla(false); },
    // Lo guardado desde la matriz o el panel actualiza los valores base de la planilla.
    items() { this.initPlanilla(false); },
    plVars() { this.initPlanilla(false); },
    gVariables() { this.ajustarGrafico(); },
    gVar() { this.gFecha = this.gFechas[this.gFechas.length - 1] || null; },
  },
  created() {
    this.initPlanilla(true);
    this.ajustarGrafico();
  },
  mounted() {
    window.addEventListener('keydown', this.onEsc);
    window.addEventListener('beforeunload', this.onSalir);
  },
  beforeDestroy() {
    window.removeEventListener('keydown', this.onEsc);
    window.removeEventListener('beforeunload', this.onSalir);
  },
  methods: {
    /* ---------- utilidades ---------- */
    tabInicial() {
      const q = new URLSearchParams(window.location.search).get('tab');
      return PESTANAS.some((t) => t.id === q) ? q : this.leer('tab', 'matriz');
    },
    leer(k, def) { try { return localStorage.getItem(`sigec.evaluaciones.${k}`) || def; } catch (e) { return def; } },
    guardar_(k, v) { try { localStorage.setItem(`sigec.evaluaciones.${k}`, v); } catch (e) { /* sin almacenamiento */ } },
    ensayoInicial() {
      const q = Number(new URLSearchParams(window.location.search).get('ensayo'));
      const id = q || Number(this.leer('ensayo', '')) || '';
      return id && this.ensayos.some((e) => e.id === id) ? id : '';
    },
    onEsc(e) {
      if (e.key !== 'Escape') return;
      if (this.panel) this.cerrar();
    },
    onSalir(e) {
      if (!this.plSucios) return undefined;
      e.preventDefault();
      e.returnValue = '';
      return '';
    },
    hoy() { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; },
    formVacio() { return { id: null, ensayo_id: null, fecha: this.hoy(), parcela_id: null, variable_id: null, valor: '', obs: '' }; },
    limpiarFiltros() { this.filtroCat = ''; this.filtroVar = ''; this.desde = ''; this.hasta = ''; this.busqueda = ''; this.soloFuera = false; },
    verFueraRango() { this.soloFuera = !this.soloFuera; if (this.soloFuera) this.tab = 'listado'; },
    sinTildes(s) { return s.normalize('NFD').replace(/[̀-ͯ]/g, ''); },
    n(x) { return Number(x || 0).toLocaleString('es-GT'); },
    num(s) {
      if (s === null || s === undefined) return null;
      const t = String(s).trim().replace(',', '.');
      return t !== '' && !Number.isNaN(Number(t)) ? Number(t) : null;
    },
    dec(x, v) {
      if (x === null || x === undefined) return '—';
      const d = v && v.decimales !== null && v.decimales !== undefined ? v.decimales : 2;
      return Number(x).toLocaleString('es-GT', { minimumFractionDigits: d, maximumFractionDigits: d });
    },
    fmt(s) { if (!s) return ''; const [y, m, d] = String(s).slice(0, 10).split('-'); return `${d}/${m}/${y}`; },
    fmtCorto(s) { const [y, m, d] = String(s).split('-'); return `${d}/${m}/${y.slice(2)}`; },
    estadoClase(e) { return { Planificado: 'plan', 'En campo': 'campo', Finalizado: 'fin' }[e] || 'plan'; },
    rango(v) {
      if (!v) return '';
      if (v.tipo !== 'Numerica') return v.tipo === 'Categorica' ? (v.opciones || []).join(', ') : 'texto';
      const f = (x) => Number(x).toLocaleString('es-GT', { maximumFractionDigits: 4 });
      if (v.valor_min === null && v.valor_max === null) return 'sin rango';
      if (v.valor_min === null) return `hasta ${f(v.valor_max)}`;
      if (v.valor_max === null) return `desde ${f(v.valor_min)}`;
      return `${f(v.valor_min)} – ${f(v.valor_max)}`;
    },
    bandaRango(v) {
      if (!v || (v.valor_min === null && v.valor_max === null)) return [];
      return [{ from: v.valor_min !== null ? v.valor_min : -1e9, to: v.valor_max !== null ? v.valor_max : 1e9, color: 'rgba(115, 188, 37, 0.08)' }];
    },
    /* vacio | invalido | fuera | ok | sin (sin rango o no numerica) */
    estadoValor(v, valor) {
      const s = String(valor === null || valor === undefined ? '' : valor).trim();
      if (!s) return 'vacio';
      if (!v || v.tipo !== 'Numerica') {
        if (v && v.tipo === 'Categorica' && v.opciones && v.opciones.length && !v.opciones.some((o) => o.toLowerCase() === s.toLowerCase())) return 'invalido';
        return 'sin';
      }
      const x = this.num(s);
      if (x === null) return 'invalido';
      if (v.valor_min === null && v.valor_max === null) return 'sin';
      if ((v.valor_min !== null && x < v.valor_min) || (v.valor_max !== null && x > v.valor_max)) return 'fuera';
      return 'ok';
    },
    esFuera(e) { return this.estadoValor(this.varMap.get(e.variable_id), e.valor) === 'fuera'; },
    parcelaDe(e) { return this.parcMap.get(e.parcela_id); },
    variableDe(e) { return this.varMap.get(e.variable_id); },
    ensayoDe(e) { const p = this.parcMap.get(e.parcela_id); return p ? this.ensMap.get(p.ensayo_id) : null; },
    colorTrat(t, alfa) {
      let i = this.tratamientos.indexOf(t || '—');
      // Fuera del ensayo seleccionado (listado general) se asigna un color estable por codigo.
      if (i < 0) i = String(t || '').split('').reduce((a, ch) => a + ch.charCodeAt(0), 0);
      const c = PALETA[i % PALETA.length];
      if (!alfa) return c;
      const r = parseInt(c.slice(1, 3), 16);
      const g = parseInt(c.slice(3, 5), 16);
      const b = parseInt(c.slice(5, 7), 16);
      return `rgba(${r}, ${g}, ${b}, ${alfa})`;
    },
    clave(p, v) { return `${p}|${v}`; },
    claveCelda(p, v, f) { return `${p}|${v}|${f}`; },
    toast(title, text, icon = 'success') { this.$swal.fire({ toast: true, position: 'top-end', icon, title, text, timer: 2200, showConfirmButton: false }); },
    /** Une al estado local la respuesta del endpoint de lote. */
    aplicarLote(data) {
      const borrar = new Set(data.eliminadas || []);
      let lista = this.items.filter((i) => !borrar.has(i.id));
      (data.guardadas || []).forEach((g) => {
        const i = lista.findIndex((x) => x.id === g.id);
        if (i >= 0) lista[i] = g; else lista = [g, ...lista];
      });
      this.items = lista;
    },
    mensajeError(err) {
      const r = err.response;
      if (r && r.status === 422 && r.data.errors) return Object.values(r.data.errors)[0][0];
      if (r && r.status === 403) return 'No tiene permiso sobre este ensayo.';
      return 'No se pudo guardar.';
    },

    /* ---------- matriz ---------- */
    celda(p, v, f) { return this.celdas.get(this.claveCelda(p.id, v.id, f)) || null; },
    esDeFormulario(p, v, f) { const e = this.celda(p, v, f); return !!(e && e.respuesta_id); },
    corteFila(i) {
      if (i === 0 || this.matOrden === 'codigo') return false;
      const a = this.matParcelas[i - 1];
      const b = this.matParcelas[i];
      return this.matOrden === 'rep' ? a.repeticion !== b.repeticion : a.tratamiento !== b.tratamiento;
    },
    claseCelda(p, v, f) {
      const k = this.claveCelda(p.id, v.id, f);
      if (this.guardandoCeldas[k]) return 'is-guardando';
      const e = this.celdas.get(k);
      if (!e) return 'is-vacia';
      return `is-${this.estadoValor(v, e.valor)}`;
    },
    tituloCelda(p, v, f) {
      const e = this.celda(p, v, f);
      if (!e) return `${p.codigo} · ${v.nombre} · ${this.fmt(f)}: sin dato`;
      return `${p.codigo} · ${v.nombre} · ${this.fmt(f)}: ${e.valor} ${v.unidad || ''}\nRegistro: ${e.responsable || '—'}${e.formulario ? `\nFormulario: ${e.formulario}` : ''}${e.obs ? `\nObs: ${e.obs}` : ''}`;
    },
    esEdit(p, v, f) { const c = this.editCell; return !!c && c.p === p.id && c.v === v.id && c.f === f; },
    esColNueva(vid, f) { return this.colsExtra.some((c) => c.ensayo_id === this.filtroEnsayo && c.variable_id === vid && c.fecha === f); },
    editarCelda(p, v, f) {
      if (!this.puedeRegistrar || this.esEdit(p, v, f)) return;
      const e = this.celda(p, v, f);
      this.editCell = { p: p.id, v: v.id, f };
      this.editVal = e ? e.valor : '';
      this.$nextTick(() => {
        const el = this.$el.querySelector('.ev-cel-in');
        if (el) { el.focus(); if (el.select) el.select(); }
      });
    },
    cancelarCelda() { this.editCell = null; },
    // El blur de una celda que ya se cerro (al bajar con Enter) no debe cerrar la siguiente.
    blurCelda(p, v, f) { if (this.esEdit(p, v, f)) this.guardarCelda(0); },
    guardarCelda(mover) {
      const c = this.editCell;
      if (!c) return;
      this.editCell = null;
      const v = this.varMap.get(c.v);
      const actual = this.celdas.get(this.claveCelda(c.p, c.v, c.f));
      const val = String(this.editVal || '').trim();
      if (mover) {
        const i = this.matParcelas.findIndex((p) => p.id === c.p) + mover;
        const sig = this.matParcelas[i];
        if (sig) this.editarCelda(sig, v, c.f);
      }
      if (val === (actual ? actual.valor : '')) return;
      if (this.estadoValor(v, val) === 'invalido') {
        this.toast('Valor no valido', `"${val}" no es valido para ${v.nombre}.`, 'error');
        return;
      }
      if (!val && actual && !(this.puedeEliminar || actual.responsable_id === this.usuarioId)) {
        this.toast('No se puede borrar', 'Solo el responsable o un administrador puede eliminar el dato.', 'warning');
        return;
      }
      const k = this.claveCelda(c.p, c.v, c.f);
      this.$set(this.guardandoCeldas, k, true);
      window.axios.post('/investigacion/evaluaciones/lote', { fecha: c.f, items: [{ parcela_id: c.p, variable_id: c.v, valor: val }] })
        .then(({ data }) => {
          this.aplicarLote(data);
          if (this.estadoValor(v, val) === 'fuera') this.toast('Guardado fuera de rango', `${val} ${v.unidad || ''} · esperado ${this.rango(v)}`, 'warning');
        })
        .catch((err) => this.$swal.fire({ icon: 'error', title: 'No se guardo la celda', text: this.mensajeError(err) }))
        .finally(() => { this.$delete(this.guardandoCeldas, k); });
    },
    agregarColumna() {
      const c = { ensayo_id: this.filtroEnsayo, variable_id: this.colNueva.variable_id, fecha: this.colNueva.fecha };
      if (!this.esColNueva(c.variable_id, c.fecha)) this.colsExtra.push(c);
      if (this.filtroVar && this.filtroVar !== c.variable_id) this.filtroVar = '';
      const v = this.varMap.get(c.variable_id);
      if (this.filtroCat && v.categoria !== this.filtroCat) this.filtroCat = '';
      this.colNueva = null;
      this.$nextTick(() => { if (this.matParcelas[0]) this.editarCelda(this.matParcelas[0], v, c.fecha); });
    },
    promedioColumna(v, f) {
      if (v.tipo !== 'Numerica') {
        const n = this.parcelasEnsayo.filter((p) => this.celda(p, v, f)).length;
        return n ? `${n} dato(s)` : '';
      }
      const xs = this.parcelasEnsayo.map((p) => this.celda(p, v, f)).filter(Boolean).map((e) => this.num(e.valor)).filter((x) => x !== null);
      return xs.length ? this.dec(xs.reduce((a, b) => a + b, 0) / xs.length, v) : '';
    },
    descargarMatriz() {
      const esc = (s) => { const t = String(s === null || s === undefined ? '' : s); return /[;"\n]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t; };
      const cab = ['Parcela', 'Tratamiento', 'Repeticion'];
      this.matGrupos.forEach((g) => g.fechas.forEach((f) => cab.push(`${g.v.nombre}${g.v.unidad ? ` (${g.v.unidad})` : ''} ${f}`)));
      const filas = [cab.map(esc).join(';')];
      this.matParcelas.forEach((p) => {
        const r = [p.codigo, p.tratamiento || '', p.repeticion];
        this.matGrupos.forEach((g) => g.fechas.forEach((f) => { const e = this.celda(p, g.v, f); r.push(e ? String(e.valor).replace('.', ',') : ''); }));
        filas.push(r.map(esc).join(';'));
      });
      const blob = new Blob([`﻿${filas.join('\r\n')}`], { type: 'text/csv;charset=utf-8' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = `matriz_evaluaciones_${this.ensayoSel.codigo}.csv`;
      a.click();
      setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    },

    /* ---------- planilla ---------- */
    toggleVar(id) {
      const i = this.plVars.indexOf(id);
      if (i >= 0) this.plVars.splice(i, 1); else this.plVars.push(id);
    },
    /**
     * Prepara la planilla con los valores existentes de la fecha. Con reset=false
     * conserva lo que el usuario ya escribio (p. ej. si cambia la fecha despues).
     */
    initPlanilla(reset) {
      if (!this.filtroEnsayo) { this.plVals = {}; this.plOrig = {}; this.plErr = {}; return; }
      if (reset && !this.plVars.length) {
        // Por defecto, las variables activas que ya se miden en el ensayo.
        const usadas = new Set(this.items.filter((e) => (this.parcMap.get(e.parcela_id) || {}).ensayo_id === this.filtroEnsayo).map((e) => e.variable_id));
        this.plVars = this.variablesActivas.filter((v) => usadas.has(v.id)).map((v) => v.id);
        return; // el watcher de plVars vuelve a llamar
      }
      const vals = {};
      const orig = {};
      this.parcelasEnsayo.forEach((p) => this.plVars.forEach((vid) => {
        const k = this.clave(p.id, vid);
        const e = this.celdas.get(this.claveCelda(p.id, vid, this.plFecha));
        orig[k] = e ? e.valor : '';
        const previo = this.plVals[k];
        const sucio = !reset && previo !== undefined && (previo || '').trim() !== (this.plOrig[k] || '');
        vals[k] = sucio ? previo : orig[k];
      }));
      this.plOrig = orig;
      this.plVals = vals;
      this.plErr = {};
    },
    clasePlanilla(p, v) {
      const k = this.clave(p.id, v.id);
      const val = (this.plVals[k] || '').trim();
      const cls = [];
      if (val !== (this.plOrig[k] || '')) cls.push('is-sucio');
      if (this.plErr[k]) cls.push('is-invalido');
      else cls.push(`is-${this.estadoValor(v, val)}`);
      return cls;
    },
    moverPl(r, c, dr) {
      const el = this.$el.querySelector(`[data-pl="${r + dr}-${c}"]`) || (dr > 0 ? this.$el.querySelector(`[data-pl="0-${c + 1}"]`) : null);
      if (el) { el.focus(); if (el.select) el.select(); }
    },
    pegar(ev, r, c) {
      const txt = (ev.clipboardData || window.clipboardData).getData('text');
      if (!/[\t\n]/.test(txt.trim())) return;
      ev.preventDefault();
      const filas = txt.replace(/\r/g, '').replace(/\n+$/, '').split('\n');
      let n = 0;
      filas.forEach((fila, i) => fila.split('\t').forEach((val, j) => {
        const p = this.matParcelas[r + i];
        const v = this.plColumnas[c + j];
        if (!p || !v) return;
        this.$set(this.plVals, this.clave(p.id, v.id), val.trim());
        n += 1;
      }));
      this.toast(`${n} valor(es) pegados`, 'Revise y presione Guardar.', 'info');
    },
    guardarPlanilla() {
      const claves = Object.keys(this.plVals).filter((k) => (this.plVals[k] || '').trim() !== (this.plOrig[k] || ''));
      const items = claves.map((k) => { const [p, v] = k.split('|').map(Number); return { parcela_id: p, variable_id: v, valor: (this.plVals[k] || '').trim() }; });
      const borra = items.filter((i) => !i.valor).length;
      const enviar = () => {
        this.plGuardando = true;
        this.plErr = {};
        window.axios.post('/investigacion/evaluaciones/lote', { fecha: this.plFecha, items })
          .then(({ data }) => {
            this.aplicarLote(data);
            this.initPlanilla(true);
            this.toast('Planilla guardada', `${data.guardadas.length} guardado(s)${data.eliminadas.length ? `, ${data.eliminadas.length} eliminado(s)` : ''} · ${this.fmt(this.plFecha)}`);
          })
          .catch((err) => {
            const r = err.response;
            if (r && r.status === 422 && r.data.errors) {
              const errs = {};
              Object.entries(r.data.errors).forEach(([campo, msgs]) => {
                const m = campo.match(/^items\.(\d+)\./);
                if (m && claves[m[1]]) errs[claves[m[1]]] = msgs[0];
              });
              this.plErr = errs;
              this.$swal.fire({ icon: 'warning', title: 'Revise los valores marcados', text: Object.values(r.data.errors)[0][0] });
            } else this.$swal.fire({ icon: 'error', title: 'No se pudo guardar', text: this.mensajeError(err) });
          })
          .finally(() => { this.plGuardando = false; });
      };
      if (!borra) { enviar(); return; }
      this.$swal.fire({
        icon: 'question', title: `Eliminar ${borra} dato(s)`, text: 'Dejo vacias celdas que tenian valor; esos registros se eliminaran.',
        showCancelButton: true, confirmButtonText: 'Guardar y eliminar', cancelButtonText: 'Revisar', confirmButtonColor: '#FF6B00',
      }).then((res) => { if (res.isConfirmed) enviar(); });
    },

    /* ---------- graficos ---------- */
    ajustarGrafico() {
      if (!this.gVariables.some((v) => v.id === this.gVar)) {
        const num = this.gVariables.find((v) => v.tipo === 'Numerica');
        this.gVar = (num || this.gVariables[0] || {}).id || null;
      }
      if (!this.gFechas.includes(this.gFecha)) this.gFecha = this.gFechas[this.gFechas.length - 1] || null;
    },

    /* ---------- panel ---------- */
    cerrar() { this.panel = null; this.formOriginal = null; },
    abrirCrear(pre = {}) {
      this.form = { ...this.formVacio(), ensayo_id: this.filtroEnsayo || null, variable_id: this.filtroVar || null, ...pre };
      this.formOriginal = null;
      this.errors = {};
      this.errorGeneral = null;
      this.panel = 'form';
    },
    abrirEditar(e) {
      const p = this.parcelaDe(e);
      this.form = { id: e.id, ensayo_id: p ? p.ensayo_id : null, fecha: e.fecha, parcela_id: e.parcela_id, variable_id: e.variable_id, valor: e.valor, obs: e.obs || '' };
      this.formOriginal = e;
      this.errors = {};
      this.errorGeneral = null;
      this.panel = 'form';
    },
    guardar() {
      if (!this.formListo) return;
      this.guardando = true;
      this.errors = {};
      this.errorGeneral = null;
      const f = this.form;
      const peticion = f.id
        ? window.axios.put(`/investigacion/evaluaciones/${f.id}`, { fecha: f.fecha, parcela_id: f.parcela_id, variable_id: f.variable_id, valor: String(f.valor).trim(), obs: f.obs || null })
          .then(({ data }) => this.aplicarLote({ guardadas: [data] }))
        : window.axios.post('/investigacion/evaluaciones/lote', { fecha: f.fecha, items: [{ parcela_id: f.parcela_id, variable_id: f.variable_id, valor: String(f.valor).trim(), obs: f.obs || null }] })
          .then(({ data }) => this.aplicarLote(data));
      peticion
        .then(() => {
          const v = this.formVar;
          this.toast(f.id ? 'Evaluacion actualizada' : 'Evaluacion registrada', `${v.nombre}: ${f.valor} ${v.unidad || ''}`);
          if (!f.id && this.seguir) {
            // Siguiente parcela del ensayo con la misma variable y fecha.
            const i = this.formParcelas.findIndex((p) => p.id === f.parcela_id);
            const sig = this.formParcelas[i + 1];
            this.form = { ...f, parcela_id: sig ? sig.id : null, valor: '', obs: '' };
            this.$nextTick(() => this.$refs.valor && this.$refs.valor.focus());
          } else this.cerrar();
        })
        .catch((err) => {
          const r = err.response;
          if (r && r.status === 422 && r.data.errors) {
            const e = {};
            Object.entries(r.data.errors).forEach(([k, m]) => { e[k.replace(/^items\.\d+\./, '')] = m; });
            this.errors = e;
          } else this.errorGeneral = this.mensajeError(err);
        })
        .finally(() => { this.guardando = false; });
    },
    eliminar(e) {
      const v = this.variableDe(e);
      const p = this.parcelaDe(e);
      this.$swal.fire({
        title: 'Eliminar evaluacion', icon: 'warning', showCancelButton: true,
        text: `${v ? v.nombre : ''} = ${e.valor} · parcela ${p ? p.codigo : ''} · ${this.fmt(e.fecha)}`,
        confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#FF6B00', cancelButtonColor: '#CED2D5', focusCancel: true,
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/investigacion/evaluaciones/${e.id}`)
          .then(() => {
            this.items = this.items.filter((i) => i.id !== e.id);
            if (this.formOriginal && this.formOriginal.id === e.id) this.cerrar();
            this.toast('Evaluacion eliminada');
          })
          .catch(() => this.$swal.fire({ icon: 'error', title: 'No se pudo eliminar' }));
      });
    },
  },
};
</script>

<style scoped>
/* Paleta Cengicaña */
.ev {
  --verde: #73BC25; --verde-2: #A3D300; --amarillo: #FFCC00; --naranja: #FF6B00; --gris: #CED2D5;
  --verde-osc: #1f5632; --tinta: #1e2a1a; --suave: #6b7568; --borde: #e3e7e1;
}
.ev-muted { color: var(--suave); }
.ev-nowrap { white-space: nowrap; }
.ev-txt-nar { color: #c95500; }
.ev-txt-err { color: #c0392b; font-weight: 700; }
.ev-txt-ok { color: #3f7a12; }
kbd { padding: 1px 5px; border: 1px solid var(--borde); border-radius: 4px; background: #f7f9f5; color: var(--tinta); font-size: 11px; box-shadow: none; }

/* KPIs */
.ev-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 14px; }
.ev-kpi { display: flex; align-items: center; gap: 12px; padding: 13px 15px; border: 1px solid var(--borde); border-left: 5px solid var(--verde); border-radius: 14px; background: #fff; text-align: left; }
.ev-kpi > i { display: grid; flex: 0 0 auto; place-items: center; width: 40px; height: 40px; border-radius: 12px; background: color-mix(in srgb, var(--verde) 16%, #fff); color: var(--verde); font-size: 17px; }
.ev-kpi strong { display: block; color: var(--tinta); font-size: 22px; line-height: 1.1; }
.ev-kpi strong em { margin-left: 3px; color: var(--suave); font-size: 13px; font-style: normal; font-weight: 600; }
.ev-kpi span { display: block; color: var(--verde-osc); font-size: 12.5px; font-weight: 700; }
.ev-kpi small { color: var(--suave); font-size: 11.5px; }
.ev-kpi.is-ens { border-left-color: var(--verde-2); }
.ev-kpi.is-ens > i { background: color-mix(in srgb, var(--verde-2) 22%, #fff); color: #5f7a00; }
.ev-kpi.is-var { border-left-color: var(--gris); }
.ev-kpi.is-var > i { background: color-mix(in srgb, var(--gris) 45%, #fff); color: #5b6267; }
.ev-kpi.is-ult { border-left-color: var(--amarillo); }
.ev-kpi.is-ult > i { background: color-mix(in srgb, var(--amarillo) 24%, #fff); color: #a07c00; }
.ev-kpi.is-alerta { border-left-color: var(--naranja); cursor: pointer; transition: transform .15s, box-shadow .15s; }
.ev-kpi.is-alerta > i { background: color-mix(in srgb, var(--naranja) 14%, #fff); color: var(--naranja); }
.ev-kpi.is-alerta:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(31, 86, 50, .1); }
.ev-kpi.is-alerta.is-on { box-shadow: 0 0 0 2px var(--naranja) inset; }

/* Filtros */
.ev-bar { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; margin-bottom: 12px; padding: 10px 14px; border: 1px solid var(--borde); border-radius: 14px; background: #fff; }
.ev-campo { display: flex; flex-direction: column; gap: 3px; min-width: 150px; }
.ev-campo > label { margin: 0; color: var(--suave); font-size: 10.5px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; }
.ev-campo-ens { flex: 1 1 220px; max-width: 300px; }
.ev-campo .form-control { border-radius: 8px; }
.ev-campo .form-control:focus, .ev-pb .form-control:focus { border-color: var(--verde); box-shadow: 0 0 0 .15rem rgba(115, 188, 37, .2); }
.ev-seg-mini { display: inline-flex; align-items: stretch; height: 38px; padding: 3px; border: 1px solid var(--borde); border-radius: 9px; background: #f3f5f1; }
.ev-seg-mini button { padding: 2px 10px; border: 0; border-radius: 6px; background: transparent; color: var(--suave); font-size: 12px; font-weight: 600; }
.ev-seg-mini button.is-on { background: #fff; color: var(--verde-osc); box-shadow: 0 1px 3px rgba(30, 42, 26, .12); }
.ev-seg-mini button.is-des.is-on { color: #3f7a12; }
.ev-seg-mini button.is-cos.is-on { color: #b84d00; }
.ev-fechas { display: flex; align-items: center; gap: 5px; }
.ev-fechas .form-control { width: 140px; }
.ev-fechas span { color: var(--suave); font-size: 12px; }
.ev-limpiar { align-self: center; border: 0; background: transparent; color: var(--naranja); font-size: 12px; font-weight: 600; }
.ev-acc { display: flex; gap: 8px; margin-left: auto; }
.ev-btn { border: 0; border-radius: 9px; background: var(--verde); color: #fff; font-weight: 700; }
.ev-btn:hover { background: #64a51f; color: #fff; }
.ev-btn:disabled { opacity: .55; }
.ev-btn-sec { border: 1px solid var(--borde); border-radius: 9px; background: #fff; color: var(--verde-osc); font-weight: 600; }
.ev-btn-sec:hover { border-color: var(--verde); color: var(--verde-osc); }
.ev-btn-sec i { color: var(--verde); }
.ev-btn-del { border: 1px solid #ffd2b3; border-radius: 9px; background: #fff; color: var(--naranja); font-weight: 600; }

/* Pestañas */
.ev-tabs { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; margin-bottom: -1px; }
.ev-tabs > button { position: relative; padding: 9px 16px; border: 1px solid transparent; border-bottom: 0; border-radius: 12px 12px 0 0; background: transparent; color: var(--suave); font-size: 13px; font-weight: 700; }
.ev-tabs > button:hover { color: var(--verde-osc); }
.ev-tabs > button.is-on { border-color: var(--borde); background: #fff; color: var(--verde-osc); box-shadow: inset 0 3px 0 var(--verde); }
.ev-tabs > button small { margin-left: 4px; padding: 0 6px; border-radius: 999px; background: var(--naranja); color: #fff; font-size: 10.5px; }
.ev-tabs-ens { margin-left: auto; padding: 4px 12px; border-radius: 999px; background: color-mix(in srgb, var(--verde) 12%, #fff); color: var(--verde-osc); font-size: 12.5px; font-weight: 700; }
.ev-tabs-ens em { margin-left: 4px; color: var(--suave); font-style: normal; font-weight: 500; }
.ev-caja { padding: 14px; border: 1px solid var(--borde); border-radius: 0 14px 14px 14px; background: #fff; }
.ev-caja.is-flush { padding: 0; overflow: hidden; }

/* Selector de ensayo */
.ev-picker { padding: 16px; border: 1px solid var(--borde); border-radius: 0 14px 14px 14px; background: #fff; }
.ev-picker-tit { margin: 0 0 12px; color: var(--verde-osc); font-weight: 700; }
.ev-picker-tit i { margin-right: 6px; color: var(--verde); }
.ev-picker-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
.ev-ens-card { display: flex; flex-direction: column; gap: 6px; padding: 13px 14px; border: 1px solid var(--borde); border-top: 3px solid var(--verde); border-radius: 12px; cursor: pointer; outline: none; transition: transform .15s, box-shadow .15s; }
.ev-ens-card:hover, .ev-ens-card:focus { transform: translateY(-3px); box-shadow: 0 10px 22px rgba(31, 86, 50, .12); }
.ev-ens-top { display: flex; align-items: center; justify-content: space-between; gap: 6px; }
.ev-ens-top b { color: var(--tinta); font-size: 15px; }
.ev-ens-card > small { color: var(--suave); font-size: 12px; }
.ev-estado { padding: 1px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 700; }
.ev-estado.is-plan { background: color-mix(in srgb, var(--gris) 50%, #fff); color: #4f565b; }
.ev-estado.is-campo { background: color-mix(in srgb, var(--verde) 18%, #fff); color: #3f7a12; }
.ev-estado.is-fin { background: color-mix(in srgb, var(--amarillo) 26%, #fff); color: #7d6100; }
.ev-ens-stats { display: flex; gap: 10px; color: var(--suave); font-size: 11.5px; }
.ev-ens-stats b { color: var(--verde-osc); font-size: 13px; }
.ev-ens-pie { display: flex; justify-content: space-between; padding-top: 6px; border-top: 1px dashed #e8ece5; color: var(--verde-osc); font-size: 11.5px; font-weight: 600; }
.ev-ens-pie i { color: var(--verde); }
.ev-aviso-mini { color: var(--naranja); }

/* Vacio */
.ev-vacio { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 42px 16px; border: 1px dashed var(--gris); border-radius: 14px; background: #fff; color: var(--suave); text-align: center; }
.ev-vacio.is-in { margin: 6px 0; border-radius: 12px; }
.ev-vacio > i { color: var(--gris); font-size: 30px; }
.ev-vacio strong { color: var(--tinta); }
.ev-vacio a { color: #3f7a12; font-weight: 600; }

/* Matriz */
.ev-info { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 10px; padding: 8px 12px; border: 1px solid color-mix(in srgb, var(--verde) 35%, #fff); border-radius: 10px; background: #f4faec; color: #2e5c0e; font-size: 12.5px; }
.ev-cobertura { display: inline-flex; align-items: center; gap: 6px; font-weight: 700; }
.ev-cob-barra { display: inline-block; width: 90px; height: 7px; overflow: hidden; border-radius: 999px; background: #dfe8d6; }
.ev-cob-barra i { display: block; height: 100%; background: linear-gradient(90deg, var(--verde), var(--verde-2)); }
.ev-info-acc { display: flex; gap: 6px; margin-left: auto; }
.ev-info-acc .form-control { width: auto; height: 31px; border-radius: 8px; font-size: 12px; }
.ev-colnueva { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 10px; padding: 8px 12px; border: 1.5px dashed var(--verde); border-radius: 10px; background: #fbfdf8; font-size: 12.5px; font-weight: 600; color: var(--verde-osc); }
.ev-colnueva .form-control { width: auto; min-width: 150px; border-radius: 8px; }
.ev-x-mini { border: 0; background: transparent; color: var(--suave); font-size: 20px; line-height: 1; }
.ev-mat-scroll { max-height: 64vh; overflow: auto; border: 1px solid var(--borde); border-radius: 10px; }
.ev-mat { min-width: 100%; border-collapse: separate; border-spacing: 0; font-size: 12.5px; }
.ev-mat th, .ev-mat td { border-right: 1px solid #edf0eb; border-bottom: 1px solid #edf0eb; }
.ev-mat thead th { position: sticky; z-index: 2; background: #f7f9f5; color: var(--suave); font-size: 11px; font-weight: 700; white-space: nowrap; }
.ev-mat thead tr:first-child th { top: 0; }
.ev-mat thead tr:nth-child(2) th { top: 31px; }
.ev-mat .fx { position: sticky; z-index: 1; background: #fff; white-space: nowrap; }
.ev-mat thead .fx { z-index: 3; top: 0; background: #f7f9f5; vertical-align: bottom; text-transform: uppercase; }
.ev-mat .fx1 { left: 0; min-width: 78px; padding: 5px 10px; }
.ev-mat .fx2 { left: 78px; min-width: 64px; padding: 5px 6px; text-align: center; }
.ev-mat .fx3 { left: 142px; min-width: 50px; padding: 5px 6px; border-right: 2px solid var(--borde); color: var(--suave); text-align: center; }
.ev-th-var { height: 31px; padding: 5px 10px; color: #fff !important; text-align: center; }
.ev-th-var.is-des { background: #3f7a12 !important; }
.ev-th-var.is-cos { background: #c95500 !important; }
.ev-th-var small { opacity: .8; font-weight: 500; }
.ev-th-var em { display: block; opacity: .85; font-size: 10px; font-style: normal; font-weight: 500; }
.ev-th-fecha { padding: 4px 8px; text-align: center; }
.ev-th-fecha.is-nueva { color: var(--verde-osc) !important; background: #eef7e2 !important; }
.ev-mat .is-ini { border-left: 2px solid var(--borde); }
.ev-mat tbody tr.is-corte td { border-top: 2px solid color-mix(in srgb, var(--verde) 45%, #fff); }
.ev-mat tbody tr:hover td { background-color: #fafcf7; }
.ev-mat tbody tr:hover .fx { background: #f4f8ee; }
.ev-trat { display: inline-block; min-width: 30px; padding: 1px 8px; border-radius: 999px; font-size: 11px; font-weight: 800; text-align: center; }
.ev-cel { min-width: 70px; height: 32px; padding: 3px 8px; cursor: pointer; font-variant-numeric: tabular-nums; text-align: center; transition: background .12s; }
.ev-cel.is-ro { cursor: default; }
.ev-cel.is-ok { background: color-mix(in srgb, var(--verde) 10%, #fff); color: #24480c; font-weight: 600; }
.ev-cel.is-sin { color: var(--tinta); font-weight: 600; }
.ev-cel.is-fuera { position: relative; background: color-mix(in srgb, var(--naranja) 13%, #fff); color: #b84d00; font-weight: 700; }
.ev-cel.is-fuera::after { content: ''; position: absolute; top: 0; right: 0; border-top: 7px solid var(--naranja); border-left: 7px solid transparent; }
.ev-cel.is-invalido { background: #fdecea; color: #c0392b; }
.ev-cel.is-guardando { background: repeating-linear-gradient(45deg, #f4faec, #f4faec 6px, #fff 6px, #fff 12px); color: var(--suave); }
.ev-mas { color: #cfd5cb; font-weight: 700; opacity: 0; transition: opacity .12s; }
.ev-cel:not(.is-ro):hover .ev-mas { opacity: 1; color: var(--verde); }
.ev-cel:not(.is-ro):hover { box-shadow: inset 0 0 0 1.5px var(--verde-2); }
.ev-cel.is-edit { padding: 0; box-shadow: inset 0 0 0 2px var(--verde); }
.ev-cel-in { width: 100%; min-width: 70px; height: 31px; padding: 0 6px; border: 0; outline: none; background: #fff; font-size: 12.5px; text-align: center; }
.ev-mat tfoot td { position: sticky; bottom: 0; z-index: 1; padding: 5px 8px; border-top: 2px solid var(--borde); background: #f7f9f5; color: var(--verde-osc); font-size: 11.5px; font-weight: 700; text-align: center; }
.ev-mat tfoot .fx { z-index: 3; }
.ev-pie-tit { text-align: left !important; text-transform: uppercase; }
.ev-leyenda { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 10px; color: var(--suave); font-size: 11.5px; }
.ev-leyenda i { display: inline-block; width: 12px; height: 12px; margin-right: 4px; border-radius: 3px; vertical-align: -1px; }
.ev-leyenda i.is-ok { background: color-mix(in srgb, var(--verde) 25%, #fff); }
.ev-leyenda i.is-fuera { background: color-mix(in srgb, var(--naranja) 35%, #fff); }
.ev-leyenda i.is-sin { border: 1px solid var(--borde); background: #fff; }
.ev-leyenda i.is-fm { border-left: 3px solid #6250d6; background: #fff; }
.ev-leyenda i.is-vacia { border: 1px dashed var(--gris); background: #fff; }
.ev-teclas { margin-left: auto; }

/* Planilla */
.ev-pl-cab { display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 10px; }
.ev-pl-vars { flex: 1; }
.ev-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.ev-chip { padding: 4px 11px; border: 1px solid var(--borde); border-radius: 999px; background: #fff; color: #44503f; font-size: 12px; font-weight: 600; transition: all .15s; }
.ev-chip i { margin-right: 3px; font-size: 10px; color: var(--suave); }
.ev-chip.is-des.is-on { border-color: var(--verde); background: color-mix(in srgb, var(--verde) 14%, #fff); color: #2f6b0f; }
.ev-chip.is-cos.is-on { border-color: var(--naranja); background: color-mix(in srgb, var(--naranja) 11%, #fff); color: #b84d00; }
.ev-chip.is-on i { color: inherit; }
.ev-ayuda { margin: 0 0 10px; padding: 7px 12px; border-radius: 8px; background: #f6f8f3; color: #3d4838; font-size: 12px; }
.ev-ayuda > i { margin-right: 5px; color: var(--verde); }
.ev-pl thead tr:first-child th { top: 0; }
.ev-pl-cel { min-width: 120px; padding: 0 !important; }
.ev-pl-cel input, .ev-pl-cel select { width: 100%; height: 32px; padding: 0 8px; border: 0; outline: none; background: transparent; font-size: 13px; font-variant-numeric: tabular-nums; text-align: center; }
.ev-pl-cel input:focus, .ev-pl-cel select:focus { background: #fff; box-shadow: inset 0 0 0 2px var(--verde); }
.ev-pl-cel.is-sucio { background: color-mix(in srgb, var(--amarillo) 16%, #fff); }
.ev-pl-cel.is-fuera { background: color-mix(in srgb, var(--naranja) 13%, #fff); }
.ev-pl-cel.is-fuera input { color: #b84d00; font-weight: 700; }
.ev-pl-cel.is-invalido { background: #fdecea; }
.ev-pl-cel.is-invalido input { color: #c0392b; font-weight: 700; }
.ev-pl-pie { display: flex; align-items: center; justify-content: flex-end; gap: 8px; margin-top: 10px; font-size: 12.5px; }
.ev-pl-pie > span { margin-right: auto; }

/* Listado */
.ev-lst-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 10px 14px; border-bottom: 1px solid var(--borde); }
.ev-buscar { position: relative; display: flex; flex: 1 1 260px; align-items: center; max-width: 420px; }
.ev-buscar i { position: absolute; left: 12px; color: var(--suave); font-size: 13px; }
.ev-buscar input { width: 100%; height: 34px; padding: 0 30px 0 34px; border: 1px solid var(--borde); border-radius: 999px; background: #f7f9f5; font-size: 13px; outline: none; }
.ev-buscar input:focus { border-color: var(--verde); background: #fff; }
.ev-buscar button { position: absolute; right: 8px; border: 0; background: transparent; color: var(--suave); font-size: 18px; line-height: 1; }
.ev-check-in { display: inline-flex; align-items: center; gap: 5px; margin: 0; color: var(--suave); font-size: 12.5px; font-weight: 600; }
.ev-lst-ct { margin-left: auto; font-size: 12px; }
.ev-tabla-wrap { overflow-x: auto; }
.ev-tabla { margin: 0; font-size: 13px; }
.ev-tabla th { background: #f7f9f5; color: var(--suave); font-size: 11px; letter-spacing: .03em; text-transform: uppercase; white-space: nowrap; }
.ev-tabla td { vertical-align: middle; }
.ev-tabla tbody tr { cursor: pointer; transition: background .12s; }
.ev-tabla tbody tr:hover { background: #f7fbf1; }
.ev-tabla tr.is-fuera { background: #fff8f2; }
.ev-pend { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 12px; padding: 9px 14px; border: 1px solid #ffd2b3; border-left: 5px solid var(--naranja); border-radius: 12px; background: #fff8f2; color: #8a3b00; font-size: 13px; }
.ev-pend > i { color: var(--naranja); font-size: 16px; }
.ev-pend .ev-btn-nar { margin-left: auto; border: 0; border-radius: 9px; background: var(--naranja); color: #fff; font-weight: 700; }
.ev-txt-fm { color: #6250d6; }
.ev-cel.is-fm { box-shadow: inset 3px 0 0 #6250d6; }
.ev-cat-dot { display: inline-block; width: 8px; height: 8px; margin-right: 4px; border-radius: 50%; }
.ev-cat-dot.is-des { background: var(--verde); }
.ev-cat-dot.is-cos { background: var(--naranja); }
.ev-icon { display: inline-grid; place-items: center; width: 28px; height: 28px; border: 1px solid transparent; border-radius: 8px; background: transparent; color: var(--suave); font-size: 12px; }
.ev-icon:hover { border-color: var(--borde); background: #f1f8e9; color: var(--verde-osc); }
.ev-icon.is-del:hover { border-color: #ffd2b3; background: #fff1e6; color: var(--naranja); }
.ev-paginas { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 10px; border-top: 1px solid var(--borde); color: var(--suave); font-size: 12.5px; }
.ev-paginas button { width: 30px; height: 28px; border: 1px solid var(--borde); border-radius: 8px; background: #fff; color: var(--verde-osc); }
.ev-paginas button:disabled { opacity: .4; }

/* Graficos */
.ev-g-cab { display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 12px; }
.ev-g-cab .ev-campo { min-width: 220px; }
.ev-g-vistas { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.ev-g-vistas button { flex: 1 1 240px; max-width: 340px; padding: 8px 12px; border: 1.5px solid var(--borde); border-radius: 10px; background: #fff; color: #44503f; font-size: 13px; font-weight: 700; text-align: left; transition: all .15s; }
.ev-g-vistas button i { margin-right: 5px; color: var(--suave); }
.ev-g-vistas button small { display: block; color: var(--suave); font-size: 11px; font-weight: 500; }
.ev-g-vistas button.is-on { border-color: var(--verde); background: color-mix(in srgb, var(--verde) 11%, #fff); color: #2f6b0f; }
.ev-g-vistas button.is-on i { color: var(--verde); }
.ev-g-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.ev-chart { padding: 6px; border: 1px solid var(--borde); border-radius: 12px; }
.ev-chart.is-ancho { grid-column: 1 / -1; }
.ev-g-tabla { padding: 10px 12px; border: 1px solid var(--borde); border-radius: 12px; }
.ev-g-tabla h6 { margin: 0 0 8px; color: var(--verde-osc); font-size: 13px; font-weight: 800; }
.ev-mini { margin: 0; font-size: 12.5px; }
.ev-mini th { color: var(--suave); font-size: 11px; }
.ev-nota { margin: 6px 0 0; font-size: 11.5px; }

/* Panel lateral */
.ev-velo { position: fixed; inset: 0; z-index: 1040; background: rgba(20, 30, 16, .35); }
.ev-panel { position: fixed; top: 0; right: 0; bottom: 0; z-index: 1050; display: flex; flex-direction: column; width: min(520px, 100vw); background: #fff; box-shadow: -10px 0 30px rgba(20, 30, 16, .18); }
.ev-ph { display: flex; align-items: center; gap: 12px; padding: 16px 18px; border-bottom: 1px solid var(--borde); }
.ev-ph.is-des { border-top: 5px solid var(--verde); }
.ev-ph.is-cos { border-top: 5px solid var(--naranja); }
.ev-ph h5 { margin: 0; color: var(--tinta); font-size: 17px; font-weight: 800; }
.ev-ph small { color: var(--suave); font-size: 12px; }
.ev-ico { display: grid; place-items: center; width: 42px; height: 42px; border-radius: 12px; background: color-mix(in srgb, var(--verde) 16%, #fff); color: var(--verde); font-size: 16px; }
.ev-ph.is-cos .ev-ico { background: color-mix(in srgb, var(--naranja) 14%, #fff); color: var(--naranja); }
.ev-x { margin-left: auto; border: 0; background: transparent; color: var(--suave); font-size: 26px; line-height: 1; }
.ev-pb { flex: 1; overflow-y: auto; padding: 16px 18px; }
.ev-pb fieldset { min-width: 0; margin: 0; padding: 0; border: 0; }
.ev-pb label { color: var(--verde-osc); font-size: 12.5px; font-weight: 700; }
.ev-pf { display: flex; align-items: center; justify-content: flex-end; gap: 8px; padding: 12px 18px; border-top: 1px solid var(--borde); background: #fafbf8; }
.ev-pf .ev-btn-del, .ev-otra { margin-right: auto; }
.ev-otra { display: inline-flex; align-items: center; gap: 5px; margin-bottom: 0; color: var(--suave); font-size: 12.5px; font-weight: 600; }
.ev-parcelas { display: grid; grid-template-columns: repeat(auto-fill, minmax(84px, 1fr)); gap: 6px; max-height: 220px; overflow-y: auto; padding: 2px; }
.ev-parcelas button { display: flex; flex-direction: column; align-items: center; padding: 5px 4px; border: 1.5px solid var(--borde); border-radius: 9px; background: #fff; line-height: 1.2; transition: all .12s; }
.ev-parcelas button:hover { border-color: var(--verde-2); }
.ev-parcelas b { color: var(--tinta); font-size: 12.5px; }
.ev-parcelas small { color: var(--suave); font-size: 10.5px; }
.ev-opciones { display: flex; flex-wrap: wrap; gap: 6px; }
.ev-opciones button { padding: 6px 14px; border: 1.5px solid var(--borde); border-radius: 999px; background: #fff; color: #44503f; font-size: 13px; font-weight: 600; }
.ev-opciones button.is-on { border-color: var(--verde-2); background: color-mix(in srgb, var(--verde-2) 22%, #fff); color: #4f6600; }
.ev-valor { display: flex; align-items: center; gap: 8px; }
.ev-valor .form-control { font-size: 18px; font-weight: 700; font-variant-numeric: tabular-nums; }
.ev-valor span { color: var(--suave); font-weight: 600; white-space: nowrap; }
.ev-valor.is-ok .form-control { border-color: var(--verde); background: #f6fbf0; }
.ev-valor.is-fuera .form-control { border-color: var(--naranja); background: #fff6ef; }
.ev-alerta { margin: 6px 0 0; padding: 6px 10px; border-radius: 8px; background: #fff1e6; color: #b84d00; font-size: 12.5px; }
.ev-previo { margin-bottom: 12px; padding: 7px 10px; border-radius: 8px; background: color-mix(in srgb, var(--amarillo) 16%, #fff); color: #6b5300; font-size: 12.5px; }
.ev-meta { color: var(--suave); font-size: 12px; }
.ev-error { margin: 4px 0 0; color: #b84d00; font-size: 12px; }

.ev-fade-enter-active, .ev-fade-leave-active { transition: opacity .2s; }
.ev-fade-enter, .ev-fade-leave-to { opacity: 0; }
.ev-slide-enter-active, .ev-slide-leave-active { transition: transform .25s ease; }
.ev-slide-enter, .ev-slide-leave-to { transform: translateX(100%); }

@media (max-width: 900px) {
  .ev-g-grid { grid-template-columns: 1fr; }
  .ev-acc, .ev-info-acc, .ev-teclas { margin-left: 0; }
}
</style>
