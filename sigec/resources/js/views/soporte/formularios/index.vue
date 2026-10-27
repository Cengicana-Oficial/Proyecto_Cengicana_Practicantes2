<template>
  <div class="fm">
    <!-- ================= CAPTURA (muestreador o gestion) ================= -->
    <template v-if="cap">
      <div class="fm-cap-cab" :class="'is-' + cap.formulario.promocion">
        <button type="button" class="fm-volver" title="Volver" @click="cerrarCaptura"><i class="fas fa-arrow-left"></i></button>
        <div class="fm-cap-tit">
          <h5>{{ cap.formulario.nombre }}</h5>
          <small>
            <b>{{ ensayoCodigo(cap.formulario.ensayo_id) }}</b> · {{ capParcelas.length }} parcelas · asignado a {{ cap.asignacion.usuario || '—' }}
            <template v-if="cap.asignacion.fecha_inicio || cap.asignacion.fecha_fin"> · periodo {{ fmt(cap.asignacion.fecha_inicio) || '…' }} a {{ fmt(cap.asignacion.fecha_fin) || '…' }}</template>
          </small>
        </div>
        <span class="fm-modo" :class="'is-' + cap.formulario.promocion">
          <i :class="cap.formulario.promocion === 'automatica' ? 'fas fa-bolt' : 'fas fa-user-check'"></i>
          {{ cap.formulario.promocion === 'automatica' ? 'Pasa directo a Evaluaciones' : 'Se revisa antes de pasar a Evaluaciones' }}
        </span>
      </div>

      <div class="fm-caja">
        <div class="fm-cap-bar">
          <div class="fm-campo">
            <label>Fecha de muestreo</label>
            <input type="date" class="form-control form-control-sm" v-model="capFecha" :max="hoy()">
          </div>
          <div class="fm-campo fm-cap-fechas">
            <label>Fechas ya capturadas</label>
            <div class="fm-chips">
              <button v-for="f in capFechas" :key="f.fecha" type="button" class="fm-chip" :class="{ 'is-on': f.fecha === capFecha, 'is-ok': f.pendientes === 0 }" @click="capFecha = f.fecha">
                {{ fmt(f.fecha) }} <small>{{ f.n }}</small>
              </button>
              <span v-if="!capFechas.length" class="fm-muted">Ninguna todavia</span>
            </div>
          </div>
          <div v-if="fueraPeriodo" class="fm-aviso"><i class="fas fa-calendar-times"></i> La fecha esta fuera del periodo asignado.</div>
        </div>

        <div v-if="capCargando" class="fm-cargando"><i class="fas fa-spinner fa-spin"></i> Cargando capturas...</div>
        <template v-else>
          <p class="fm-ayuda"><i class="fas fa-keyboard"></i> <kbd>Enter</kbd> baja a la siguiente parcela · puede <b>pegar columnas desde Excel</b> · <span class="fm-req">*</span> obligatorio cuando la parcela tiene datos · dejar una fila vacia descarta su captura.</p>
          <div class="fm-tabla-scroll">
            <table class="fm-planilla">
              <thead>
                <tr>
                  <th class="fx fx1">Parcela</th>
                  <th v-for="c in cap.formulario.campos" :key="c.id" :class="c.variable_id ? 'is-var-' + catDe(c) : 'is-libre'">
                    {{ c.label }}<span v-if="c.requerido" class="fm-req">*</span>
                    <small>{{ ayudaCampo(c) }}</small>
                  </th>
                  <th class="is-libre fm-th-obs">Observaciones</th>
                  <th class="fm-th-est"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(p, r) in capParcelas" :key="p.codigo" :class="{ 'is-corte': corte(capParcelas, r) }">
                  <td class="fx fx1">
                    <b>{{ p.codigo }}</b>
                    <small>{{ p.tratamiento || '—' }} · R{{ p.repeticion }}</small>
                  </td>
                  <td v-for="(c, ci) in cap.formulario.campos" :key="c.id" class="fm-cel" :class="claseCap(p.codigo, c)" :title="capErr[clave(p.codigo, c.id)] || ''">
                    <select v-if="c.tipo === 'select'" :data-cap="r + '-' + ci" v-model="capVals[clave(p.codigo, c.id)]" @keydown.enter.prevent="moverCap(r, ci, 1)">
                      <option value=""></option>
                      <option v-for="o in c.opciones" :key="o" :value="o">{{ o }}</option>
                    </select>
                    <input
                      v-else
                      :data-cap="r + '-' + ci"
                      v-model="capVals[clave(p.codigo, c.id)]"
                      :inputmode="c.tipo === 'numero' ? 'decimal' : 'text'"
                      autocomplete="off"
                      @keydown.enter.prevent="moverCap(r, ci, $event.shiftKey ? -1 : 1)"
                      @keydown.down.prevent="moverCap(r, ci, 1)"
                      @keydown.up.prevent="moverCap(r, ci, -1)"
                      @paste="pegarCap($event, r, ci)"
                    >
                  </td>
                  <td class="fm-cel fm-cel-obs" :class="{ 'is-sucio': (capObs[p.codigo] || '') !== (capObsOrig[p.codigo] || '') }">
                    <input v-model="capObs[p.codigo]" maxlength="1000" placeholder="—" :data-cap="r + '-' + cap.formulario.campos.length" @keydown.enter.prevent="moverCap(r, cap.formulario.campos.length, 1)">
                  </td>
                  <td class="fm-est">
                    <i v-if="estadoFila(p.codigo) === 'promovida'" class="fas fa-check-double is-prom" title="Ya esta en Evaluaciones"></i>
                    <i v-else-if="estadoFila(p.codigo) === 'pendiente'" class="fas fa-hourglass-half is-pend" title="Capturada, pendiente de revision"></i>
                    <i v-else-if="estadoFila(p.codigo) === 'incompleta'" class="fas fa-exclamation-circle is-inc" title="Faltan campos obligatorios"></i>
                    <i v-else-if="estadoFila(p.codigo) === 'editada'" class="fas fa-pen is-edit" title="Cambios sin guardar"></i>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="fm-cap-pie">
            <span class="fm-muted">
              <b>{{ capResumen.capturadas }}</b> / {{ capParcelas.length }} parcelas capturadas en {{ fmt(capFecha) }}
              <template v-if="capResumen.promovidas"> · <span class="fm-txt-ok">{{ capResumen.promovidas }} en Evaluaciones</span></template>
              <template v-if="capSucias"> · <b class="fm-txt-am">{{ capSucias }} con cambios</b></template>
              <template v-if="capAlertas.fuera"> · <span class="fm-txt-nar">{{ capAlertas.fuera }} fuera de rango</span></template>
              <template v-if="capAlertas.invalidos"> · <span class="fm-txt-err">{{ capAlertas.invalidos }} no validos</span></template>
            </span>
            <button type="button" class="btn btn-sm fm-btn-sec" :disabled="!capSucias || capGuardando" @click="initCaptura(true)">Descartar</button>
            <button type="button" class="btn btn-sm fm-btn" :disabled="!capSucias || capGuardando || capAlertas.invalidos > 0 || !puedeEscribirCaptura" @click="guardarCaptura">
              <i :class="capGuardando ? 'fas fa-spinner fa-spin' : 'fas fa-save'"></i> Guardar captura
            </button>
          </div>
          <p v-if="!puedeEscribirCaptura" class="fm-aviso is-block"><i class="fas fa-lock"></i> La asignacion esta finalizada o el formulario inactivo: solo lectura.</p>
        </template>
      </div>
    </template>

    <!-- ================= MUESTREADOR ================= -->
    <template v-else-if="modo === 'muestreador'">
      <div class="fm-bienvenida">
        <i class="fas fa-clipboard-list"></i>
        <div>
          <h5>Mis formularios de campo</h5>
          <small>Elija un formulario y capture los datos de cada parcela por fecha de muestreo.</small>
        </div>
      </div>
      <div v-if="!misAsignaciones.length" class="fm-vacio"><i class="fas fa-inbox"></i><strong>No tiene formularios asignados por el momento.</strong></div>
      <div class="fm-grid">
        <article v-for="a in misAsignaciones" :key="a.id" class="fm-card" :class="{ 'is-off': a.estado !== 'Activo' }">
          <div class="fm-card-top">
            <span class="fm-ico"><i class="fas fa-clipboard-list"></i></span>
            <div class="fm-card-tit">
              <b>{{ formDe(a) ? formDe(a).nombre : 'Formulario' }}</b>
              <small>{{ ensayoCodigo(a.ensayo_id) }} · {{ a.parcelas.length || parcelasDeEnsayo(a.ensayo_id).length }} parcelas</small>
            </div>
            <span class="fm-tag" :class="'is-' + vigencia(a).clase">{{ vigencia(a).texto }}</span>
          </div>
          <div class="fm-campos-mini">
            <span v-for="c in (formDe(a) ? formDe(a).campos : [])" :key="c.id" class="fm-cm" :class="c.variable_id ? 'is-var' : 'is-libre'">{{ c.label }}</span>
          </div>
          <div class="fm-card-stats">
            <span><b>{{ a.fechas }}</b> fechas</span>
            <span><b>{{ a.capturas }}</b> capturas</span>
            <span v-if="a.pendientes" class="fm-txt-am"><b>{{ a.pendientes }}</b> en revision</span>
            <span v-if="a.ultima" class="fm-ult">ultima {{ fmt(a.ultima) }}</span>
          </div>
          <button type="button" class="btn btn-sm fm-btn fm-card-btn" @click="abrirCaptura(a)"><i class="fas fa-pen-alt"></i> {{ a.estado === 'Activo' ? 'Capturar datos' : 'Ver capturas' }}</button>
        </article>
      </div>
    </template>

    <!-- ================= GESTION ================= -->
    <template v-else>
      <div class="fm-kpis">
        <div class="fm-kpi">
          <i class="fas fa-clipboard-list"></i>
          <div><strong>{{ items.filter((f) => f.estado === 'Activo').length }}</strong><span>Formularios activos</span><small>{{ items.length }} en total</small></div>
        </div>
        <div class="fm-kpi is-asig">
          <i class="fas fa-user-friends"></i>
          <div><strong>{{ asignacionesActivas }}</strong><span>Asignaciones activas</span><small>muestreadores en campo</small></div>
        </div>
        <div class="fm-kpi is-cap">
          <i class="fas fa-inbox"></i>
          <div><strong>{{ n(totalCapturas) }}</strong><span>Capturas recibidas</span><small>una por parcela y fecha</small></div>
        </div>
        <button type="button" class="fm-kpi is-pend" :class="{ 'is-on': tab === 'capturas' }" @click="irCapturas(null)">
          <i class="fas fa-hourglass-half"></i>
          <div><strong>{{ n(totalPendientes) }}</strong><span>Pendientes de revision</span><small>{{ totalPendientes ? 'clic para pasarlas a Evaluaciones' : 'todo al dia' }}</small></div>
        </button>
      </div>

      <div class="fm-tabs">
        <button type="button" :class="{ 'is-on': tab === 'formularios' }" @click="tab = 'formularios'"><i class="fas fa-clipboard-list"></i> Formularios</button>
        <button type="button" :class="{ 'is-on': tab === 'capturas' }" @click="irCapturas(null)"><i class="fas fa-inbox"></i> Capturas recibidas <small v-if="totalPendientes">{{ totalPendientes }}</small></button>
      </div>

      <!-- ---------- Formularios ---------- -->
      <section v-if="tab === 'formularios'" class="fm-caja">
        <div class="fm-bar">
          <div class="fm-buscar">
            <i class="fas fa-search"></i>
            <input type="text" v-model="busqueda" placeholder="Buscar formulario, ensayo o variable...">
          </div>
          <select class="form-control form-control-sm fm-sel" v-model="filtroEnsayo">
            <option value="">Todos los ensayos</option>
            <option v-for="e in ensayos" :key="e.id" :value="e.id">{{ e.codigo }}</option>
          </select>
          <label class="fm-check-in"><input type="checkbox" v-model="verInactivos"> Ver inactivos</label>
          <div class="fm-vista fm-bar-btn">
            <button type="button" :class="{ 'is-on': vista === 'lista' }" title="Lista" @click="vista = 'lista'"><i class="fas fa-list"></i></button>
            <button type="button" :class="{ 'is-on': vista === 'tarjetas' }" title="Tarjetas" @click="vista = 'tarjetas'"><i class="fas fa-th-large"></i></button>
          </div>
          <button type="button" class="btn btn-sm fm-btn" @click="abrirCrear"><i class="fas fa-plus"></i> Nuevo formulario</button>
        </div>

        <div v-if="!formulariosFiltrados.length" class="fm-vacio">
          <i class="fas fa-clipboard-list"></i>
          <strong>{{ items.length ? 'Ningun formulario coincide con los filtros.' : 'Aun no hay formularios de campo.' }}</strong>
          <span v-if="!items.length">Cree uno eligiendo las <b>variables</b> que se mediran; lo capturado pasara a <b>Evaluaciones</b>.</span>
        </div>

        <!-- Lista -->
        <div v-else-if="vista === 'lista'" class="fm-lista-wrap">
          <table class="fm-lista">
            <thead>
              <tr>
                <th class="fm-col-exp"></th>
                <th>Formulario</th>
                <th>Ensayo</th>
                <th>Variables / campos</th>
                <th>Paso</th>
                <th>Muestreadores</th>
                <th class="text-right">Capturas</th>
                <th class="text-right">Por revisar</th>
                <th>Estado</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="f in formulariosFiltrados">
                <tr :key="f.id" class="fm-fila" :class="{ 'is-off': f.estado !== 'Activo', 'is-abierta': expandido === f.id }" @click="expandido = expandido === f.id ? null : f.id">
                  <td class="fm-col-exp"><i :class="expandido === f.id ? 'fas fa-chevron-down' : 'fas fa-chevron-right'"></i></td>
                  <td>
                    <b class="fm-l-nom">{{ f.nombre }}</b>
                    <small class="fm-muted">creado {{ fmt(f.fecha) }}{{ f.creado_por ? ' por ' + f.creado_por : '' }}</small>
                  </td>
                  <td class="fm-nowrap"><span class="fm-codigo is-ens" :title="`Proyecto ${proyectoCodigo(f.proyecto_id)}`">{{ ensayoCodigo(f.ensayo_id) }}</span></td>
                  <td>
                    <div class="fm-l-campos">
                      <span v-for="c in f.campos.slice(0, 2)" :key="c.id" class="fm-cm" :class="c.variable_id ? 'is-var is-' + catDe(c) : 'is-libre'">{{ c.label }}</span>
                      <span v-if="f.campos.length > 2" class="fm-cm is-mas" :title="f.campos.slice(2).map((c) => c.label).join(', ')">+{{ f.campos.length - 2 }}</span>
                    </div>
                  </td>
                  <td class="fm-nowrap">
                    <span class="fm-tag" :class="f.promocion === 'automatica' ? 'is-auto' : 'is-pend'">
                      <i :class="f.promocion === 'automatica' ? 'fas fa-bolt' : 'fas fa-user-check'"></i> {{ f.promocion === 'automatica' ? 'Automatico' : 'Revision' }}
                    </span>
                  </td>
                  <td>
                    <div class="fm-avatares">
                      <span v-for="a in f.asignaciones.slice(0, 4)" :key="a.id" class="fm-avatar" :class="{ 'is-off': a.estado !== 'Activo' }" :title="`${a.usuario} · ${a.estado}`">{{ inicial(a.usuario) }}</span>
                      <small v-if="f.asignaciones.length > 4" class="fm-muted">+{{ f.asignaciones.length - 4 }}</small>
                      <small v-if="!f.asignaciones.length" class="fm-muted">sin asignar</small>
                    </div>
                  </td>
                  <td class="text-right fm-nowrap" :title="`${f.fechas} fecha(s) de muestreo`"><b>{{ f.capturas }}</b></td>
                  <td class="text-right" @click.stop>
                    <a v-if="f.pendientes" href="#" class="fm-pend-n" @click.prevent="irCapturas(f.id)">{{ f.pendientes }}</a>
                    <span v-else class="fm-muted">—</span>
                  </td>
                  <td><span class="fm-tag" :class="f.estado === 'Activo' ? 'is-act' : 'is-inact'">{{ f.estado }}</span></td>
                  <td class="text-right fm-nowrap" @click.stop>
                    <button type="button" class="fm-icon" title="Capturar yo mismo" :disabled="f.estado !== 'Activo'" @click="capturarYo(f)"><i class="fas fa-pen-alt"></i></button>
                    <button type="button" class="fm-icon" title="Asignar muestreadores" @click="abrirAsignar(f)"><i class="fas fa-user-plus"></i></button>
                    <button type="button" class="fm-icon" title="Editar" @click="abrirEditar(f)"><i class="fas fa-pen"></i></button>
                    <button v-if="puedeEliminar" type="button" class="fm-icon is-del" title="Eliminar" @click="eliminar(f)"><i class="fas fa-trash"></i></button>
                  </td>
                </tr>
                <tr v-if="expandido === f.id" :key="f.id + '-det'" class="fm-det">
                  <td></td>
                  <td colspan="9">
                    <div class="fm-det-grid">
                      <div>
                        <h6>Campos ({{ f.campos.length }})</h6>
                        <p v-if="f.descripcion" class="fm-desc">{{ f.descripcion }}</p>
                        <div class="fm-campos-mini">
                          <span v-for="c in f.campos" :key="c.id" class="fm-cm" :class="c.variable_id ? 'is-var is-' + catDe(c) : 'is-libre'" :title="c.variable_id ? 'Variable → pasa a Evaluaciones' : 'Campo libre (solo queda en la captura)'">
                            <i :class="c.variable_id ? 'fas fa-link' : 'fas fa-pen'"></i> {{ c.label }}<em v-if="unidadDe(c)"> {{ unidadDe(c) }}</em><span v-if="c.requerido" class="fm-req">*</span>
                          </span>
                        </div>
                      </div>
                      <div>
                        <h6>Muestreadores ({{ f.asignaciones.length }})</h6>
                        <div class="fm-asigs">
                          <div v-for="a in f.asignaciones" :key="a.id" class="fm-asig" :class="{ 'is-off': a.estado !== 'Activo' }">
                            <span class="fm-avatar">{{ inicial(a.usuario) }}</span>
                            <div>
                              <b>{{ a.usuario }}</b>
                              <small>{{ a.parcelas.length ? a.parcelas.length + ' parcelas' : 'todas las parcelas' }} · {{ periodo(a) }} · {{ a.estado }}</small>
                            </div>
                            <span class="fm-asig-n" :title="`${a.capturas} capturas en ${a.fechas} fecha(s)`">{{ a.capturas }}</span>
                          </div>
                          <a v-if="!f.asignaciones.length" href="#" class="fm-txt-ok" @click.prevent="abrirAsignar(f)"><i class="fas fa-user-plus"></i> Asignar muestreador</a>
                        </div>
                      </div>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <div v-else class="fm-grid is-anchas">
          <article v-for="f in formulariosFiltrados" :key="f.id" class="fm-card" :class="{ 'is-off': f.estado !== 'Activo' }">
            <div class="fm-card-top">
              <span class="fm-ico"><i class="fas fa-clipboard-list"></i></span>
              <div class="fm-card-tit">
                <b>{{ f.nombre }}</b>
                <small><span class="fm-codigo">{{ proyectoCodigo(f.proyecto_id) }}</span> <span class="fm-codigo is-ens">{{ ensayoCodigo(f.ensayo_id) }}</span> · creado {{ fmt(f.fecha) }}{{ f.creado_por ? ' por ' + f.creado_por : '' }}</small>
              </div>
              <span class="fm-tag" :class="f.estado === 'Activo' ? 'is-act' : 'is-inact'">{{ f.estado }}</span>
            </div>
            <p v-if="f.descripcion" class="fm-desc">{{ f.descripcion }}</p>
            <div class="fm-campos-mini">
              <span v-for="c in f.campos" :key="c.id" class="fm-cm" :class="c.variable_id ? 'is-var is-' + catDe(c) : 'is-libre'" :title="c.variable_id ? 'Variable → pasa a Evaluaciones' : 'Campo libre (solo queda en la captura)'">
                <i :class="c.variable_id ? 'fas fa-link' : 'fas fa-pen'"></i> {{ c.label }}<em v-if="unidadDe(c)"> {{ unidadDe(c) }}</em><span v-if="c.requerido" class="fm-req">*</span>
              </span>
            </div>
            <div class="fm-promo" :class="'is-' + f.promocion">
              <i :class="f.promocion === 'automatica' ? 'fas fa-bolt' : 'fas fa-user-check'"></i>
              {{ f.promocion === 'automatica' ? 'Las capturas pasan directo a Evaluaciones' : 'Las capturas se revisan antes de pasar a Evaluaciones' }}
            </div>
            <div class="fm-asigs">
              <div v-for="a in f.asignaciones" :key="a.id" class="fm-asig" :class="{ 'is-off': a.estado !== 'Activo' }">
                <span class="fm-avatar">{{ inicial(a.usuario) }}</span>
                <div>
                  <b>{{ a.usuario }}</b>
                  <small>{{ a.parcelas.length ? a.parcelas.length + ' parcelas' : 'todas las parcelas' }} · {{ periodo(a) }}</small>
                </div>
                <span class="fm-asig-n" :title="`${a.capturas} capturas en ${a.fechas} fecha(s)`">{{ a.capturas }}</span>
              </div>
              <span v-if="!f.asignaciones.length" class="fm-muted">Sin muestreadores asignados</span>
            </div>
            <div class="fm-card-pie">
              <span class="fm-card-stats">
                <span><b>{{ f.capturas }}</b> capturas</span>
                <span><b>{{ f.fechas }}</b> fechas</span>
                <a v-if="f.pendientes" href="#" class="fm-txt-am" @click.prevent="irCapturas(f.id)"><b>{{ f.pendientes }}</b> por revisar</a>
              </span>
              <div class="fm-card-acc">
                <button type="button" class="fm-icon" title="Capturar yo mismo" :disabled="f.estado !== 'Activo'" @click="capturarYo(f)"><i class="fas fa-pen-alt"></i></button>
                <button type="button" class="fm-icon" title="Asignar muestreadores" @click="abrirAsignar(f)"><i class="fas fa-user-plus"></i></button>
                <button type="button" class="fm-icon" title="Ver capturas" @click="irCapturas(f.id)"><i class="fas fa-inbox"></i></button>
                <button type="button" class="fm-icon" title="Editar" @click="abrirEditar(f)"><i class="fas fa-pen"></i></button>
                <button v-if="puedeEliminar" type="button" class="fm-icon is-del" title="Eliminar" @click="eliminar(f)"><i class="fas fa-trash"></i></button>
              </div>
            </div>
          </article>
        </div>
      </section>

      <!-- ---------- Capturas recibidas ---------- -->
      <section v-else class="fm-caja">
        <div class="fm-bar">
          <select class="form-control form-control-sm fm-sel is-ancho" v-model="revForm">
            <option :value="null">Todos los formularios</option>
            <option v-for="f in items" :key="f.id" :value="f.id">{{ f.nombre }} · {{ ensayoCodigo(f.ensayo_id) }}</option>
          </select>
          <div class="fm-seg">
            <button v-for="e in [['pend', 'Pendientes'], ['prom', 'En Evaluaciones'], ['todas', 'Todas']]" :key="e[0]" type="button" :class="{ 'is-on': revEstado === e[0] }" @click="revEstado = e[0]">{{ e[1] }}</button>
          </div>
          <button v-if="puedePromover" type="button" class="btn btn-sm fm-btn fm-bar-btn" :disabled="!pendientesVisibles.length || promoviendo" @click="promover(pendientesVisibles)">
            <i :class="promoviendo ? 'fas fa-spinner fa-spin' : 'fas fa-arrow-circle-right'"></i> Pasar {{ pendientesVisibles.length }} pendiente(s) a Evaluaciones
          </button>
        </div>
        <div v-if="revCargando" class="fm-cargando"><i class="fas fa-spinner fa-spin"></i> Cargando capturas...</div>
        <div v-else-if="!lotes.length" class="fm-vacio">
          <i class="fas fa-inbox"></i>
          <strong>{{ revEstado === 'pend' ? 'No hay capturas pendientes de revision.' : 'No hay capturas.' }}</strong>
          <span>Las capturas aparecen aqui cuando un muestreador guarda datos de un formulario.</span>
        </div>
        <article v-for="l in lotes" :key="l.clave" class="fm-lote" :class="{ 'is-prom': !l.pendientes.length }">
          <header>
            <div>
              <b>{{ l.formulario.nombre }}</b>
              <small><span class="fm-codigo is-ens">{{ ensayoCodigo(l.formulario.ensayo_id) }}</span> · muestreo {{ fmt(l.fecha) }} · {{ l.responsable || '—' }} · {{ l.respuestas.length }} parcela(s)</small>
            </div>
            <span v-if="l.pendientes.length" class="fm-tag is-pend">{{ l.pendientes.length }} pendiente(s)</span>
            <span v-else class="fm-tag is-act"><i class="fas fa-check-double"></i> En Evaluaciones</span>
            <button v-if="puedePromover && l.pendientes.length" type="button" class="btn btn-sm fm-btn" :disabled="promoviendo" @click="promover(l.pendientes)"><i class="fas fa-arrow-circle-right"></i> Pasar a Evaluaciones</button>
          </header>
          <div class="fm-tabla-scroll is-bajo">
            <table class="fm-rev">
              <thead>
                <tr>
                  <th>Parcela</th>
                  <th v-for="c in l.formulario.campos" :key="c.id" :class="c.variable_id ? 'is-var-' + catDe(c) : 'is-libre'">
                    <i v-if="c.variable_id" class="fas fa-link"></i> {{ c.label }} <small>{{ unidadDe(c) }}</small>
                  </th>
                  <th class="is-libre">Obs.</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in l.respuestas" :key="r.id" :class="{ 'is-prom': r.promovido }">
                  <td><b>{{ r.parcela_codigo }}</b> <small class="fm-muted">{{ tratDe(l.formulario.ensayo_id, r.parcela_codigo) }}</small></td>
                  <td v-for="c in l.formulario.campos" :key="c.id" class="fm-rev-val" :class="'is-' + estadoCampo(c, r.valores[c.id])">{{ valorDe(r, c) }}</td>
                  <td class="fm-muted fm-rev-obs" :title="r.observaciones">{{ r.observaciones || '' }}</td>
                  <td class="fm-nowrap text-right">
                    <i v-if="r.promovido" class="fas fa-check-double fm-txt-ok" :title="`Pasada a Evaluaciones ${r.promovido_en || ''} por ${r.promovido_por || '—'}`"></i>
                    <button v-if="!r.promovido" type="button" class="fm-icon is-del" title="Descartar captura" @click="descartar(r)"><i class="fas fa-times"></i></button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </article>
      </section>
    </template>

    <!-- ================= Panel lateral ================= -->
    <transition name="fm-fade"><div v-if="panel" class="fm-velo" @click="cerrarPanel"></div></transition>
    <transition name="fm-slide">
      <aside v-if="panel" class="fm-panel" :class="{ 'is-ancho': panel === 'form' }" role="dialog">
        <!-- Diseñador -->
        <template v-if="panel === 'form'">
          <header class="fm-ph">
            <span class="fm-ico"><i class="fas fa-drafting-compass"></i></span>
            <div><h5>{{ form.id ? 'Editar formulario' : 'Nuevo formulario' }}</h5><small>{{ form.nombre || 'Nombre del formulario' }}{{ form.ensayo_id ? ' · ' + ensayoCodigo(form.ensayo_id) : '' }}</small></div>
            <button type="button" class="fm-x" @click="cerrarPanel">×</button>
          </header>
          <div class="fm-pb fm-disenador">
            <div class="fm-col">
              <h6 class="fm-sub"><span>1</span> Datos generales</h6>
              <div class="form-group">
                <label>Nombre *</label>
                <input class="form-control" maxlength="150" v-model.trim="form.nombre" :class="{ 'is-invalid': errors.nombre }" placeholder="Ej. Evaluacion fenologica 4 meses">
                <span class="invalid-feedback" v-if="errors.nombre">{{ errors.nombre[0] }}</span>
              </div>
              <div class="form-row">
                <div class="col-6 form-group">
                  <label>Proyecto *</label>
                  <select class="form-control" v-model="form.proyecto_id" :class="{ 'is-invalid': errors.proyecto_id }" @change="form.ensayo_id = null">
                    <option :value="null">Seleccione...</option>
                    <option v-for="p in proyectos" :key="p.id" :value="p.id">{{ p.codigo }}</option>
                  </select>
                </div>
                <div class="col-6 form-group">
                  <label>Ensayo *</label>
                  <select class="form-control" v-model="form.ensayo_id" :class="{ 'is-invalid': errors.ensayo_id }" @change="alElegirEnsayo">
                    <option :value="null">Seleccione...</option>
                    <option v-for="e in ensayosDelProyecto" :key="e.id" :value="e.id">{{ e.codigo }}</option>
                  </select>
                  <span class="invalid-feedback" v-if="errors.ensayo_id">{{ errors.ensayo_id[0] }}</span>
                </div>
              </div>
              <div class="form-group">
                <label>Instrucciones para el muestreador</label>
                <textarea class="form-control" rows="2" maxlength="1000" v-model="form.descripcion" placeholder="Que medir, como y en que parte de la parcela..."></textarea>
              </div>
              <div class="form-group">
                <label>Paso a Evaluaciones</label>
                <div class="fm-seg-g">
                  <button type="button" :class="{ 'is-on': form.promocion === 'revision' }" @click="form.promocion = 'revision'">
                    <i class="fas fa-user-check"></i> Con revision<small>un encargado revisa y aprueba las capturas</small>
                  </button>
                  <button type="button" :class="{ 'is-on': form.promocion === 'automatica' }" @click="form.promocion = 'automatica'">
                    <i class="fas fa-bolt"></i> Automatico<small>al guardar, los datos pasan a Evaluaciones</small>
                  </button>
                </div>
              </div>
              <label class="fm-check"><input type="checkbox" :checked="form.estado === 'Activo'" @change="form.estado = $event.target.checked ? 'Activo' : 'Inactivo'"> Activo <small class="fm-muted">(los inactivos no se pueden capturar)</small></label>

              <h6 class="fm-sub"><span>2</span> Agregar variables del catalogo</h6>
              <div class="fm-buscar is-full">
                <i class="fas fa-search"></i>
                <input type="text" v-model="busVar" placeholder="Buscar variable...">
              </div>
              <div v-for="cat in ['Desarrollo', 'Cosecha']" :key="cat" class="fm-cat">
                <small :class="'is-' + cat.toLowerCase()">{{ cat === 'Cosecha' ? 'Cosecha / fabrica' : 'Desarrollo del cultivo' }}</small>
                <div class="fm-chips">
                  <button
                    v-for="v in variablesCatalogo(cat)"
                    :key="v.id"
                    type="button"
                    class="fm-chip-var"
                    :class="['is-' + cat.toLowerCase(), { 'is-on': enFormulario(v.id) }]"
                    :title="v.descripcion || ''"
                    @click="toggleVariable(v)"
                  >
                    <i :class="enFormulario(v.id) ? 'fas fa-check' : 'fas fa-plus'"></i> {{ v.nombre }} <em v-if="v.unidad">{{ v.unidad }}</em>
                  </button>
                </div>
              </div>
              <button type="button" class="fm-libre-btn" @click="agregarLibre"><i class="fas fa-pen"></i> Agregar campo libre <small>(no pasa a Evaluaciones)</small></button>
            </div>

            <div class="fm-col">
              <h6 class="fm-sub"><span>3</span> Campos del formulario <small>{{ form.campos.length }}</small></h6>
              <div v-if="!form.campos.length" class="fm-vacio is-mini"><i class="fas fa-hand-point-left"></i><span>Elija variables del catalogo para armar el formulario.</span></div>
              <div v-for="(c, i) in form.campos" :key="c.key" class="fm-campo-row" :class="c.variable_id ? 'is-var is-' + catDe(c) : 'is-libre'">
                <div class="fm-orden">
                  <button type="button" :disabled="i === 0" title="Subir" @click="mover(i, -1)"><i class="fas fa-chevron-up"></i></button>
                  <button type="button" :disabled="i === form.campos.length - 1" title="Bajar" @click="mover(i, 1)"><i class="fas fa-chevron-down"></i></button>
                </div>
                <div class="fm-campo-info">
                  <template v-if="c.variable_id">
                    <b><i class="fas fa-link"></i> {{ varMap.get(c.variable_id) ? varMap.get(c.variable_id).nombre : c.label }}</b>
                    <small>{{ ayudaCampo(c) }}</small>
                  </template>
                  <template v-else>
                    <input class="form-control form-control-sm" v-model="c.label" placeholder="Nombre del campo libre" maxlength="150" :class="{ 'is-invalid': errors[`campos.${i}.label`] }">
                    <div class="fm-libre-cfg">
                      <select class="form-control form-control-sm" v-model="c.tipo">
                        <option value="texto">Texto</option>
                        <option value="numero">Numero</option>
                        <option value="select">Opciones</option>
                      </select>
                      <input v-if="c.tipo === 'select'" class="form-control form-control-sm" v-model="c.opcionesTexto" placeholder="Opciones separadas por coma" :class="{ 'is-invalid': errors[`campos.${i}.opciones`] }">
                    </div>
                  </template>
                </div>
                <label class="fm-req-tg" :class="{ 'is-on': c.requerido }"><input type="checkbox" v-model="c.requerido"> Obligatorio</label>
                <button type="button" class="fm-icon is-del" title="Quitar" @click="form.campos.splice(i, 1)"><i class="fas fa-times"></i></button>
              </div>
              <p v-if="errorCampos" class="fm-error"><i class="fas fa-exclamation-circle"></i> {{ errorCampos }}</p>
              <div v-if="form.campos.length" class="fm-nota">
                <i class="fas fa-info-circle"></i> <b>{{ form.campos.filter((c) => c.variable_id).length }}</b> campo(s) enlazados a variables pasaran a Evaluaciones con la parcela y la fecha de muestreo; los campos libres quedan solo en la captura.
              </div>
              <p v-if="form.id && formOriginal && formOriginal.capturas" class="fm-aviso is-block"><i class="fas fa-exclamation-triangle"></i> Este formulario ya tiene {{ formOriginal.capturas }} captura(s). Si quita un campo, sus valores capturados dejan de mostrarse.</p>
            </div>
          </div>
          <footer class="fm-pf">
            <p v-if="errorGeneral" class="fm-error"><i class="fas fa-exclamation-circle"></i> {{ errorGeneral }}</p>
            <button type="button" class="btn btn-sm fm-btn-sec" @click="cerrarPanel">Cancelar</button>
            <button type="button" class="btn btn-sm fm-btn" :disabled="guardando || !form.nombre || !form.ensayo_id || !form.campos.length" @click="guardarFormulario">
              <i :class="guardando ? 'fas fa-spinner fa-spin' : 'fas fa-save'"></i> Guardar formulario
            </button>
          </footer>
        </template>

        <!-- Asignaciones -->
        <template v-else-if="panel === 'asignar' && fActivo">
          <header class="fm-ph">
            <span class="fm-ico"><i class="fas fa-user-plus"></i></span>
            <div><h5>Asignar muestreadores</h5><small>{{ fActivo.nombre }} · {{ ensayoCodigo(fActivo.ensayo_id) }}</small></div>
            <button type="button" class="fm-x" @click="cerrarPanel">×</button>
          </header>
          <div class="fm-pb">
            <h6 class="fm-sub">Asignaciones actuales</h6>
            <div v-if="!fActivo.asignaciones.length" class="fm-muted fm-nota-s">Todavia no hay muestreadores asignados.</div>
            <div v-for="a in fActivo.asignaciones" :key="a.id" class="fm-asig is-panel" :class="{ 'is-off': a.estado !== 'Activo', 'is-sel': asignEdit && asignEdit.id === a.id }">
              <span class="fm-avatar">{{ inicial(a.usuario) }}</span>
              <div>
                <b>{{ a.usuario }}</b> <span class="fm-tag" :class="a.estado === 'Activo' ? 'is-act' : 'is-inact'">{{ a.estado }}</span>
                <small>{{ a.parcelas.length ? a.parcelas.join(', ') : 'todas las parcelas' }} · {{ periodo(a) }}</small>
                <small>{{ a.capturas }} capturas en {{ a.fechas }} fecha(s){{ a.pendientes ? ' · ' + a.pendientes + ' por revisar' : '' }}</small>
              </div>
              <div class="fm-asig-acc">
                <button type="button" class="fm-icon" title="Editar" @click="editarAsignacion(a)"><i class="fas fa-pen"></i></button>
                <button type="button" class="fm-icon" :title="a.estado === 'Activo' ? 'Finalizar' : 'Reactivar'" @click="cambiarEstadoAsig(a)"><i :class="a.estado === 'Activo' ? 'fas fa-flag-checkered' : 'fas fa-redo'"></i></button>
                <button v-if="!a.capturas" type="button" class="fm-icon is-del" title="Quitar" @click="quitarAsignacion(a)"><i class="fas fa-trash"></i></button>
              </div>
            </div>

            <h6 class="fm-sub">{{ asignEdit ? 'Editar asignacion de ' + asignEdit.usuario : 'Nueva asignacion' }}</h6>
            <div v-if="!asignEdit" class="form-group">
              <label>Muestreador *</label>
              <select class="form-control" v-model="asignForm.usuario_id">
                <option :value="null">Seleccione...</option>
                <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.name }}{{ u.rol ? ' · ' + u.rol : '' }}</option>
              </select>
              <small v-if="!usuarios.some((u) => u.rol === 'muestreador')" class="fm-muted">No hay usuarios con rol muestreador; puede asignar a otros usuarios de investigacion.</small>
            </div>
            <div class="form-row">
              <div class="col-6 form-group"><label>Desde</label><input type="date" class="form-control" v-model="asignForm.fecha_inicio"></div>
              <div class="col-6 form-group"><label>Hasta</label><input type="date" class="form-control" v-model="asignForm.fecha_fin" :class="{ 'is-invalid': errors.fecha_fin }"><span class="invalid-feedback" v-if="errors.fecha_fin">{{ errors.fecha_fin[0] }}</span></div>
            </div>
            <div class="form-group">
              <label>Parcelas <small class="fm-muted">({{ asignForm.parcelas.length ? asignForm.parcelas.length + ' seleccionadas' : 'todas' }})</small></label>
              <div class="fm-parcelas">
                <button type="button" class="fm-p-todas" :class="{ 'is-on': !asignForm.parcelas.length }" @click="asignForm.parcelas = []">Todas</button>
                <button
                  v-for="p in parcelasOrdenadas(fActivo.ensayo_id)"
                  :key="p.codigo"
                  type="button"
                  :class="{ 'is-on': asignForm.parcelas.includes(p.codigo) }"
                  @click="toggleParcela(p.codigo)"
                >
                  <b>{{ p.codigo }}</b><small>{{ p.tratamiento }} · R{{ p.repeticion }}</small>
                </button>
              </div>
            </div>
            <p v-if="errorGeneral" class="fm-error"><i class="fas fa-exclamation-circle"></i> {{ errorGeneral }}</p>
          </div>
          <footer class="fm-pf">
            <button v-if="asignEdit" type="button" class="btn btn-sm fm-btn-sec" @click="nuevaAsignacion">Cancelar edicion</button>
            <button type="button" class="btn btn-sm fm-btn" :disabled="asignando || (!asignEdit && !asignForm.usuario_id)" @click="guardarAsignacion">
              <i :class="asignando ? 'fas fa-spinner fa-spin' : 'fas fa-check'"></i> {{ asignEdit ? 'Guardar cambios' : 'Asignar' }}
            </button>
          </footer>
        </template>
      </aside>
    </transition>
  </div>
</template>

<script>
const TIPO_DE_VARIABLE = { Numerica: 'numero', Categorica: 'select', Texto: 'texto' };
let llave = 0;

export default {
  name: 'FormulariosIndex',
  props: {
    modo: { type: String, default: 'gestion' },
    formularios: { type: Array, default: () => [] },
    asignaciones: { type: Array, default: () => [] },
    proyectos: { type: Array, default: () => [] },
    ensayos: { type: Array, default: () => [] },
    parcelas: { type: Array, default: () => [] },
    variables: { type: Array, default: () => [] },
    usuarios: { type: Array, default: () => [] },
    usuarioId: { type: Number, default: null },
    puedePromover: { type: Boolean, default: false },
    puedeEliminar: { type: Boolean, default: false },
  },
  data() {
    const q = new URLSearchParams(window.location.search);
    return {
      items: this.formularios.slice(),
      misAsignaciones: this.asignaciones.slice(),
      tab: q.get('tab') === 'capturas' ? 'capturas' : 'formularios',
      busqueda: '',
      vista: this.leerVista(),
      expandido: null,
      filtroEnsayo: '',
      verInactivos: false,
      // panel
      panel: null,
      form: this.formVacio(),
      formOriginal: null,
      busVar: '',
      errors: {},
      errorCampos: null,
      errorGeneral: null,
      guardando: false,
      fActivo: null,
      asignEdit: null,
      asignForm: this.asignVacia(),
      asignando: false,
      // captura
      cap: null,
      capFecha: this.hoy(),
      capResp: [],
      capVals: {},
      capOrig: {},
      capObs: {},
      capObsOrig: {},
      capErr: {},
      capCargando: false,
      capGuardando: false,
      // revision
      revForm: null,
      revEstado: 'pend',
      revResp: {},
      revCargando: false,
      promoviendo: false,
    };
  },
  computed: {
    varMap() { return new Map(this.variables.map((v) => [v.id, v])); },
    ensMap() { return new Map(this.ensayos.map((e) => [e.id, e])); },
    formMap() { return new Map(this.items.map((f) => [f.id, f])); },
    asignacionesActivas() { return this.items.reduce((a, f) => a + f.asignaciones.filter((x) => x.estado === 'Activo').length, 0); },
    totalCapturas() { return this.items.reduce((a, f) => a + f.capturas, 0); },
    totalPendientes() { return this.items.reduce((a, f) => a + f.pendientes, 0); },
    formulariosFiltrados() {
      const q = this.sinTildes(this.busqueda.trim().toLowerCase());
      return this.items.filter((f) => (this.verInactivos || f.estado === 'Activo')
        && (!this.filtroEnsayo || f.ensayo_id === this.filtroEnsayo)
        && (!q || this.sinTildes(`${f.nombre} ${this.ensayoCodigo(f.ensayo_id)} ${f.campos.map((c) => c.label).join(' ')}`.toLowerCase()).includes(q)));
    },
    ensayosDelProyecto() { return this.form.proyecto_id ? this.ensayos.filter((e) => e.proyecto_id === this.form.proyecto_id) : this.ensayos; },

    /* ---------- captura ---------- */
    capParcelas() {
      if (!this.cap) return [];
      const lista = this.parcelasOrdenadas(this.cap.asignacion.ensayo_id);
      const sel = this.cap.asignacion.parcelas;
      return sel && sel.length ? lista.filter((p) => sel.includes(p.codigo)) : lista;
    },
    capFechas() {
      const m = new Map();
      this.capResp.forEach((r) => {
        if (!m.has(r.fecha)) m.set(r.fecha, { fecha: r.fecha, n: 0, pendientes: 0 });
        const x = m.get(r.fecha);
        x.n += 1;
        if (!r.promovido) x.pendientes += 1;
      });
      return [...m.values()].sort((a, b) => b.fecha.localeCompare(a.fecha));
    },
    capRespFecha() { return new Map(this.capResp.filter((r) => r.fecha === this.capFecha).map((r) => [r.parcela_codigo, r])); },
    capSucias() { return this.capParcelas.filter((p) => this.filaSucia(p.codigo)).length; },
    capAlertas() {
      let fuera = 0;
      let invalidos = 0;
      if (!this.cap) return { fuera, invalidos };
      this.capParcelas.forEach((p) => this.cap.formulario.campos.forEach((c) => {
        const k = this.clave(p.codigo, c.id);
        const val = (this.capVals[k] || '').trim();
        if (!val || val === (this.capOrig[k] || '')) return;
        const e = this.estadoCampo(c, val);
        if (e === 'fuera') fuera += 1;
        if (e === 'invalido') invalidos += 1;
      }));
      return { fuera, invalidos };
    },
    capResumen() {
      const rs = [...this.capRespFecha.values()];
      return { capturadas: rs.length, promovidas: rs.filter((r) => r.promovido).length };
    },
    fueraPeriodo() {
      if (!this.cap) return false;
      const a = this.cap.asignacion;
      return (a.fecha_inicio && this.capFecha < a.fecha_inicio) || (a.fecha_fin && this.capFecha > a.fecha_fin);
    },
    puedeEscribirCaptura() {
      if (!this.cap) return false;
      if (this.modo === 'gestion') return true;
      return this.cap.asignacion.estado === 'Activo' && this.cap.formulario.estado === 'Activo';
    },

    /* ---------- revision ---------- */
    lotes() {
      const ids = this.revForm ? [this.revForm] : this.items.map((f) => f.id);
      const out = new Map();
      ids.forEach((fid) => {
        const f = this.formMap.get(fid);
        (this.revResp[fid] || []).forEach((r) => {
          if (this.revEstado === 'pend' && r.promovido) return;
          if (this.revEstado === 'prom' && !r.promovido) return;
          const k = `${fid}|${r.fecha}|${r.responsable_id}`;
          if (!out.has(k)) out.set(k, { clave: k, formulario: f, fecha: r.fecha, responsable: r.responsable, respuestas: [] });
          out.get(k).respuestas.push(r);
        });
      });
      return [...out.values()]
        .map((l) => ({ ...l, respuestas: l.respuestas.sort((a, b) => a.parcela_codigo.localeCompare(b.parcela_codigo, 'es', { numeric: true })), pendientes: l.respuestas.filter((r) => !r.promovido) }))
        .sort((a, b) => b.fecha.localeCompare(a.fecha));
    },
    pendientesVisibles() { return this.lotes.flatMap((l) => l.pendientes); },
  },
  watch: {
    capFecha() { this.initCaptura(false); },
    tab(v) { if (v === 'capturas') this.cargarRevision(); },
    vista(v) { try { localStorage.setItem('sigec.formularios.vista', v); } catch (e) { /* sin almacenamiento */ } },
    revForm() { this.cargarRevision(); },
  },
  mounted() {
    window.addEventListener('keydown', this.onEsc);
    window.addEventListener('beforeunload', this.onSalir);
    if (this.tab === 'capturas' && this.modo === 'gestion') this.cargarRevision();
  },
  beforeDestroy() {
    window.removeEventListener('keydown', this.onEsc);
    window.removeEventListener('beforeunload', this.onSalir);
  },
  methods: {
    /* ---------- utilidades ---------- */
    leerVista() { try { return localStorage.getItem('sigec.formularios.vista') || 'lista'; } catch (e) { return 'lista'; } },
    hoy() { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; },
    fmt(s) { if (!s) return ''; const [y, m, d] = String(s).slice(0, 10).split('-'); return `${d}/${m}/${y}`; },
    n(x) { return Number(x || 0).toLocaleString('es-GT'); },
    sinTildes(s) { return s.normalize('NFD').replace(/[̀-ͯ]/g, ''); },
    inicial(s) { return (s || '?').trim().charAt(0).toUpperCase(); },
    clave(p, c) { return `${p}|${c}`; },
    ensayoCodigo(id) { const e = this.ensMap.get(id); return e ? e.codigo : '—'; },
    proyectoCodigo(id) { const p = this.proyectos.find((x) => x.id === id); return p ? p.codigo : ''; },
    formDe(a) { return this.formMap.get(a.formulario_id); },
    catDe(c) { const v = c.variable_id ? this.varMap.get(c.variable_id) : null; return v && v.categoria === 'Cosecha' ? 'cosecha' : 'desarrollo'; },
    unidadDe(c) { const v = c.variable_id ? this.varMap.get(c.variable_id) : null; return v ? v.unidad || '' : ''; },
    toast(title, text, icon = 'success') { this.$swal.fire({ toast: true, position: 'top-end', icon, title, text, timer: 2600, showConfirmButton: false }); },
    onEsc(e) { if (e.key === 'Escape' && this.panel) this.cerrarPanel(); },
    onSalir(e) {
      if (!this.capSucias) return undefined;
      e.preventDefault();
      e.returnValue = '';
      return '';
    },
    parcelasDeEnsayo(id) { return this.parcelas.filter((p) => p.ensayo_id === id); },
    parcelasOrdenadas(id) {
      const cmp = (a, b) => String(a || '').localeCompare(String(b || ''), 'es', { numeric: true });
      return this.parcelasDeEnsayo(id).sort((a, b) => cmp(a.tratamiento, b.tratamiento) || (a.repeticion - b.repeticion) || cmp(a.codigo, b.codigo));
    },
    tratDe(ensayoId, codigo) { const p = this.parcelas.find((x) => x.ensayo_id === ensayoId && x.codigo === codigo); return p ? `${p.tratamiento || ''} · R${p.repeticion}` : ''; },
    corte(lista, i) { return i > 0 && lista[i - 1].tratamiento !== lista[i].tratamiento; },
    periodo(a) {
      if (!a.fecha_inicio && !a.fecha_fin) return 'sin periodo';
      return `${this.fmt(a.fecha_inicio) || '…'} → ${this.fmt(a.fecha_fin) || '…'}`;
    },
    vigencia(a) {
      if (a.estado !== 'Activo') return { clase: 'inact', texto: 'Finalizada' };
      const h = this.hoy();
      if (a.fecha_inicio && h < a.fecha_inicio) return { clase: 'pend', texto: 'Por iniciar' };
      if (a.fecha_fin && h > a.fecha_fin) return { clase: 'venc', texto: 'Periodo vencido' };
      return { clase: 'act', texto: 'Vigente' };
    },
    ayudaCampo(c) {
      const v = c.variable_id ? this.varMap.get(c.variable_id) : null;
      if (v) {
        if (v.tipo === 'Numerica') {
          const f = (x) => Number(x).toLocaleString('es-GT', { maximumFractionDigits: 4 });
          const r = v.valor_min !== null && v.valor_max !== null ? `${f(v.valor_min)}–${f(v.valor_max)}` : v.valor_min !== null ? `≥ ${f(v.valor_min)}` : v.valor_max !== null ? `≤ ${f(v.valor_max)}` : '';
          return [v.unidad, r].filter(Boolean).join(' · ') || 'numero';
        }
        return v.tipo === 'Categorica' ? 'opciones' : 'texto';
      }
      return { numero: 'numero libre', select: 'opciones', texto: 'texto libre' }[c.tipo] || 'libre';
    },
    /* vacio | invalido | fuera | ok | sin */
    estadoCampo(c, valor) {
      const s = String(valor === null || valor === undefined ? '' : valor).trim();
      if (!s) return 'vacio';
      const v = c.variable_id ? this.varMap.get(c.variable_id) : null;
      if (c.tipo === 'select') return (c.opciones || []).some((o) => o.toLowerCase() === s.toLowerCase()) ? 'sin' : 'invalido';
      if (c.tipo !== 'numero') return 'sin';
      const t = s.replace(/\s/g, '').replace(',', '.');
      if (t === '' || Number.isNaN(Number(t))) return 'invalido';
      if (!v || (v.valor_min === null && v.valor_max === null)) return 'sin';
      const x = Number(t);
      return (v.valor_min !== null && x < v.valor_min) || (v.valor_max !== null && x > v.valor_max) ? 'fuera' : 'ok';
    },
    valorDe(r, c) {
      const v = r.valores[c.id];
      return v === undefined || v === null || v === '' ? '—' : v;
    },

    /* ---------- disenador ---------- */
    formVacio() { return { id: null, nombre: '', descripcion: '', proyecto_id: null, ensayo_id: null, estado: 'Activo', promocion: 'revision', campos: [] }; },
    abrirCrear() {
      this.form = this.formVacio();
      if (this.filtroEnsayo) {
        const e = this.ensMap.get(this.filtroEnsayo);
        this.form.ensayo_id = e.id;
        this.form.proyecto_id = e.proyecto_id;
      }
      this.formOriginal = null;
      this.prepararPanel('form');
    },
    abrirEditar(f) {
      this.form = {
        id: f.id, nombre: f.nombre, descripcion: f.descripcion || '', proyecto_id: f.proyecto_id, ensayo_id: f.ensayo_id, estado: f.estado, promocion: f.promocion,
        campos: f.campos.map((c) => ({ ...c, key: ++llave, opcionesTexto: (c.opciones || []).join(', ') })),
      };
      this.formOriginal = f;
      this.prepararPanel('form');
    },
    prepararPanel(p) {
      this.errors = {};
      this.errorCampos = null;
      this.errorGeneral = null;
      this.busVar = '';
      this.panel = p;
    },
    cerrarPanel() { this.panel = null; this.fActivo = null; this.asignEdit = null; },
    alElegirEnsayo() {
      const e = this.ensMap.get(this.form.ensayo_id);
      if (e && !this.form.proyecto_id) this.form.proyecto_id = e.proyecto_id;
    },
    variablesCatalogo(cat) {
      const q = this.sinTildes(this.busVar.trim().toLowerCase());
      return this.variables.filter((v) => v.categoria === cat && (v.activa !== false || this.enFormulario(v.id))
        && (!q || this.sinTildes(`${v.nombre} ${v.unidad || ''}`.toLowerCase()).includes(q)));
    },
    enFormulario(id) { return this.form.campos.some((c) => c.variable_id === id); },
    toggleVariable(v) {
      const i = this.form.campos.findIndex((c) => c.variable_id === v.id);
      if (i >= 0) { this.form.campos.splice(i, 1); return; }
      this.form.campos.push({ key: ++llave, id: null, variable_id: v.id, label: v.nombre, tipo: TIPO_DE_VARIABLE[v.tipo] || 'texto', requerido: false, opciones: v.opciones || [], opcionesTexto: '' });
    },
    agregarLibre() { this.form.campos.push({ key: ++llave, id: null, variable_id: null, label: '', tipo: 'texto', requerido: false, opciones: [], opcionesTexto: '' }); },
    mover(i, d) {
      const c = this.form.campos.splice(i, 1)[0];
      this.form.campos.splice(i + d, 0, c);
    },
    guardarFormulario() {
      this.guardando = true;
      this.errors = {};
      this.errorCampos = null;
      this.errorGeneral = null;
      const f = this.form;
      const payload = {
        nombre: f.nombre, descripcion: f.descripcion || null, proyecto_id: f.proyecto_id, ensayo_id: f.ensayo_id, estado: f.estado, promocion: f.promocion,
        campos: f.campos.map((c) => ({
          id: c.id, variable_id: c.variable_id, label: c.label, tipo: c.tipo, requerido: !!c.requerido,
          opciones: c.variable_id ? [] : (c.opcionesTexto || '').split(',').map((o) => o.trim()).filter(Boolean),
        })),
      };
      const peticion = f.id ? window.axios.put(`/soporte/formularios/${f.id}`, payload) : window.axios.post('/soporte/formularios', payload);
      peticion
        .then(({ data }) => {
          const i = this.items.findIndex((x) => x.id === data.id);
          if (i >= 0) this.$set(this.items, i, data); else this.items.unshift(data);
          this.toast(f.id ? 'Formulario actualizado' : 'Formulario creado', f.id ? data.nombre : 'Ahora asigne los muestreadores.');
          if (!f.id) this.abrirAsignar(data); else this.cerrarPanel();
        })
        .catch((err) => {
          const r = err.response;
          if (r && r.status === 422 && r.data.errors) {
            this.errors = r.data.errors;
            const k = Object.keys(r.data.errors).find((x) => x.startsWith('campos'));
            this.errorCampos = k ? r.data.errors[k][0] : null;
          } else this.errorGeneral = r && r.status === 403 ? 'No tiene permiso sobre este ensayo.' : 'No se pudo guardar.';
        })
        .finally(() => { this.guardando = false; });
    },
    eliminar(f) {
      this.$swal.fire({
        title: `Eliminar "${f.nombre}"`, icon: 'warning', showCancelButton: true,
        text: f.capturas ? `Se eliminaran ${f.capturas} captura(s) y sus asignaciones. Los datos que ya pasaron a Evaluaciones se conservan.` : 'Se eliminara el formulario y sus asignaciones.',
        confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#FF6B00', cancelButtonColor: '#CED2D5', focusCancel: true,
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/soporte/formularios/${f.id}`)
          .then(() => { this.items = this.items.filter((i) => i.id !== f.id); this.toast('Formulario eliminado', f.nombre); })
          .catch(() => this.$swal.fire({ icon: 'error', title: 'No se pudo eliminar' }));
      });
    },

    /* ---------- asignaciones ---------- */
    asignVacia() { return { usuario_id: null, parcelas: [], fecha_inicio: this.hoy(), fecha_fin: '' }; },
    abrirAsignar(f) {
      this.fActivo = f;
      this.nuevaAsignacion();
      this.prepararPanel('asignar');
    },
    nuevaAsignacion() { this.asignEdit = null; this.asignForm = this.asignVacia(); this.errors = {}; this.errorGeneral = null; },
    editarAsignacion(a) {
      this.asignEdit = a;
      this.asignForm = { usuario_id: a.usuario_id, parcelas: a.parcelas.slice(), fecha_inicio: a.fecha_inicio || '', fecha_fin: a.fecha_fin || '' };
      this.errors = {};
    },
    toggleParcela(c) {
      const i = this.asignForm.parcelas.indexOf(c);
      if (i >= 0) this.asignForm.parcelas.splice(i, 1); else this.asignForm.parcelas.push(c);
    },
    reemplazarAsignacion(a) {
      const f = this.formMap.get(a.formulario_id);
      if (!f) return;
      const i = f.asignaciones.findIndex((x) => x.id === a.id);
      if (i >= 0) f.asignaciones.splice(i, 1, a); else f.asignaciones.push(a);
    },
    guardarAsignacion() {
      this.asignando = true;
      this.errors = {};
      this.errorGeneral = null;
      const datos = { ...this.asignForm, fecha_inicio: this.asignForm.fecha_inicio || null, fecha_fin: this.asignForm.fecha_fin || null };
      const peticion = this.asignEdit
        ? window.axios.put(`/soporte/formularios/asignaciones/${this.asignEdit.id}`, datos)
        : window.axios.post(`/soporte/formularios/${this.fActivo.id}/asignaciones`, datos);
      peticion
        .then(({ data }) => {
          this.reemplazarAsignacion(data);
          this.toast(this.asignEdit ? 'Asignacion actualizada' : 'Muestreador asignado', data.usuario);
          this.nuevaAsignacion();
        })
        .catch((err) => {
          const r = err.response;
          if (r && r.status === 422 && r.data.errors) { this.errors = r.data.errors; this.errorGeneral = Object.values(r.data.errors)[0][0]; } else this.errorGeneral = 'No se pudo guardar.';
        })
        .finally(() => { this.asignando = false; });
    },
    cambiarEstadoAsig(a) {
      window.axios.put(`/soporte/formularios/asignaciones/${a.id}`, { parcelas: a.parcelas, fecha_inicio: a.fecha_inicio, fecha_fin: a.fecha_fin, estado: a.estado === 'Activo' ? 'Finalizado' : 'Activo' })
        .then(({ data }) => { this.reemplazarAsignacion(data); this.toast(data.estado === 'Activo' ? 'Asignacion reactivada' : 'Asignacion finalizada', data.usuario); })
        .catch(() => this.$swal.fire({ icon: 'error', title: 'No se pudo cambiar' }));
    },
    quitarAsignacion(a) {
      window.axios.delete(`/soporte/formularios/asignaciones/${a.id}`)
        .then(() => {
          const f = this.formMap.get(a.formulario_id);
          f.asignaciones = f.asignaciones.filter((x) => x.id !== a.id);
          if (this.asignEdit && this.asignEdit.id === a.id) this.nuevaAsignacion();
        })
        .catch((err) => this.$swal.fire({ icon: 'error', title: 'No se pudo quitar', text: (err.response && err.response.data && err.response.data.message) || '' }));
    },

    /* ---------- captura ---------- */
    capturarYo(f) {
      window.axios.post(`/soporte/formularios/${f.id}/mi-asignacion`)
        .then(({ data }) => { this.reemplazarAsignacion(data); this.abrirCaptura(data, f); })
        .catch(() => this.$swal.fire({ icon: 'error', title: 'No se pudo abrir la captura' }));
    },
    abrirCaptura(a, f) {
      const formulario = f || this.formDe(a);
      if (!formulario) return;
      this.cap = { asignacion: a, formulario };
      this.capResp = [];
      this.capCargando = true;
      this.capFecha = this.hoy();
      window.scrollTo(0, 0);
      window.axios.get(`/soporte/formularios/asignaciones/${a.id}/respuestas`)
        .then(({ data }) => { this.capResp = data; })
        .finally(() => { this.capCargando = false; this.initCaptura(true); });
    },
    cerrarCaptura() {
      const salir = () => { this.cap = null; this.capVals = {}; this.capOrig = {}; };
      if (!this.capSucias) { salir(); return; }
      this.$swal.fire({ icon: 'question', title: 'Hay cambios sin guardar', text: 'Si sale ahora se perderan.', showCancelButton: true, confirmButtonText: 'Salir sin guardar', cancelButtonText: 'Seguir capturando', confirmButtonColor: '#FF6B00' })
        .then((r) => { if (r.isConfirmed) salir(); });
    },
    /** Carga los valores de la fecha; con reset=false conserva lo ya escrito. */
    initCaptura(reset) {
      if (!this.cap) return;
      const vals = {};
      const orig = {};
      const obs = {};
      const obsOrig = {};
      this.capParcelas.forEach((p) => {
        const r = this.capRespFecha.get(p.codigo);
        this.cap.formulario.campos.forEach((c) => {
          const k = this.clave(p.codigo, c.id);
          orig[k] = r && r.valores[c.id] !== undefined && r.valores[c.id] !== null ? String(r.valores[c.id]) : '';
          const previo = this.capVals[k];
          vals[k] = !reset && previo !== undefined && (previo || '').trim() !== (this.capOrig[k] || '') ? previo : orig[k];
        });
        obsOrig[p.codigo] = r && r.observaciones ? r.observaciones : '';
        const po = this.capObs[p.codigo];
        obs[p.codigo] = !reset && po !== undefined && (po || '') !== (this.capObsOrig[p.codigo] || '') ? po : obsOrig[p.codigo];
      });
      this.capOrig = orig;
      this.capVals = vals;
      this.capObsOrig = obsOrig;
      this.capObs = obs;
      this.capErr = {};
    },
    filaSucia(codigo) {
      if ((this.capObs[codigo] || '') !== (this.capObsOrig[codigo] || '')) return true;
      return this.cap.formulario.campos.some((c) => { const k = this.clave(codigo, c.id); return (this.capVals[k] || '').trim() !== (this.capOrig[k] || ''); });
    },
    estadoFila(codigo) {
      const r = this.capRespFecha.get(codigo);
      const sucia = this.filaSucia(codigo);
      const tiene = this.cap.formulario.campos.some((c) => (this.capVals[this.clave(codigo, c.id)] || '').trim());
      if (tiene && this.cap.formulario.campos.some((c) => c.requerido && !(this.capVals[this.clave(codigo, c.id)] || '').trim())) return 'incompleta';
      if (sucia) return 'editada';
      if (r) return r.promovido ? 'promovida' : 'pendiente';
      return '';
    },
    claseCap(codigo, c) {
      const k = this.clave(codigo, c.id);
      const val = (this.capVals[k] || '').trim();
      const cls = [];
      if (val !== (this.capOrig[k] || '')) cls.push('is-sucio');
      if (this.capErr[k]) cls.push('is-invalido');
      else cls.push(`is-${this.estadoCampo(c, val)}`);
      if (!val && c.requerido && this.cap.formulario.campos.some((x) => (this.capVals[this.clave(codigo, x.id)] || '').trim())) cls.push('is-falta');
      return cls;
    },
    moverCap(r, c, dr) {
      const el = this.$el.querySelector(`[data-cap="${r + dr}-${c}"]`) || (dr > 0 ? this.$el.querySelector(`[data-cap="0-${c + 1}"]`) : null);
      if (el) { el.focus(); if (el.select) el.select(); }
    },
    pegarCap(ev, r, c) {
      const txt = (ev.clipboardData || window.clipboardData).getData('text');
      if (!/[\t\n]/.test(txt.trim())) return;
      ev.preventDefault();
      let n = 0;
      txt.replace(/\r/g, '').replace(/\n+$/, '').split('\n').forEach((fila, i) => fila.split('\t').forEach((val, j) => {
        const p = this.capParcelas[r + i];
        const campo = this.cap.formulario.campos[c + j];
        if (!p || !campo) return;
        this.$set(this.capVals, this.clave(p.codigo, campo.id), val.trim());
        n += 1;
      }));
      this.toast(`${n} valor(es) pegados`, 'Revise y presione Guardar captura.', 'info');
    },
    guardarCaptura() {
      const filas = this.capParcelas.filter((p) => this.filaSucia(p.codigo)).map((p) => ({
        parcela_codigo: p.codigo,
        valores: Object.fromEntries(this.cap.formulario.campos.map((c) => [c.id, (this.capVals[this.clave(p.codigo, c.id)] || '').trim()])),
        observaciones: (this.capObs[p.codigo] || '').trim() || null,
      }));
      const incompletas = filas.filter((f) => this.estadoFila(f.parcela_codigo) === 'incompleta').map((f) => f.parcela_codigo);
      if (incompletas.length) {
        this.$swal.fire({ icon: 'warning', title: 'Faltan campos obligatorios', text: `Complete: ${incompletas.join(', ')}` });
        return;
      }
      this.capGuardando = true;
      this.capErr = {};
      window.axios.post(`/soporte/formularios/asignaciones/${this.cap.asignacion.id}/respuestas`, { fecha: this.capFecha, filas })
        .then(({ data }) => {
          const borrar = new Set(data.eliminadas);
          let lista = this.capResp.filter((r) => !borrar.has(r.id));
          data.respuestas.forEach((r) => { const i = lista.findIndex((x) => x.id === r.id); if (i >= 0) lista[i] = r; else lista = [...lista, r]; });
          this.capResp = lista;
          this.initCaptura(true);
          this.actualizarContadores();
          const p = data.promocion;
          if (p) this.toast('Captura guardada y enviada a Evaluaciones', `${p.creadas + p.actualizadas} dato(s) en Evaluaciones${p.errores.length ? ` · ${p.errores.length} con error` : ''}`);
          else this.toast('Captura guardada', `${data.respuestas.length} parcela(s) · ${this.fmt(this.capFecha)} · queda pendiente de revision`);
        })
        .catch((err) => {
          const r = err.response;
          if (r && r.status === 422 && r.data.errors) {
            const errs = {};
            Object.entries(r.data.errors).forEach(([campo, msgs]) => {
              const m = campo.match(/^filas\.(\d+)\.valores\.(.+)$/);
              if (m && filas[m[1]]) errs[this.clave(filas[m[1]].parcela_codigo, m[2])] = msgs[0];
            });
            this.capErr = errs;
            this.$swal.fire({ icon: 'warning', title: 'Revise los valores marcados', text: Object.values(r.data.errors)[0][0] });
          } else this.$swal.fire({ icon: 'error', title: 'No se pudo guardar', text: (r && r.data && r.data.message) || '' });
        })
        .finally(() => { this.capGuardando = false; });
    },
    /** Recalcula los contadores de la asignacion abierta y su formulario. */
    actualizarContadores() {
      const a = this.cap.asignacion;
      a.capturas = this.capResp.length;
      a.pendientes = this.capResp.filter((r) => !r.promovido).length;
      a.fechas = new Set(this.capResp.map((r) => r.fecha)).size;
      a.ultima = this.capResp.reduce((m, r) => (r.fecha > m ? r.fecha : m), '') || null;
      const f = this.formMap.get(a.formulario_id);
      if (f && this.modo === 'gestion') this.recontarFormulario(f.id);
      if (this.revResp[a.formulario_id]) this.$delete(this.revResp, a.formulario_id);
    },
    recontarFormulario(fid) {
      const f = this.formMap.get(fid);
      if (!f) return;
      f.capturas = f.asignaciones.reduce((s, x) => s + x.capturas, 0);
      f.pendientes = f.asignaciones.reduce((s, x) => s + x.pendientes, 0);
    },

    /* ---------- revision ---------- */
    irCapturas(fid) { this.revForm = fid; this.revEstado = 'pend'; this.tab = 'capturas'; this.cargarRevision(); },
    cargarRevision() {
      const ids = (this.revForm ? [this.revForm] : this.items.filter((f) => f.capturas).map((f) => f.id)).filter((id) => !this.revResp[id]);
      if (!ids.length) return;
      this.revCargando = true;
      Promise.all(ids.map((id) => window.axios.get(`/soporte/formularios/${id}/respuestas`).then(({ data }) => this.$set(this.revResp, id, data))))
        .catch(() => this.$swal.fire({ icon: 'error', title: 'No se pudieron cargar las capturas' }))
        .finally(() => { this.revCargando = false; });
    },
    promover(respuestas) {
      const ids = respuestas.map((r) => r.id);
      this.$swal.fire({
        icon: 'question', title: `Pasar ${ids.length} captura(s) a Evaluaciones`,
        text: 'Los campos enlazados a variables se registraran en Evaluaciones con su parcela y fecha. Si ya existe un dato de la misma parcela, variable y fecha, se actualizara.',
        showCancelButton: true, confirmButtonText: 'Pasar a Evaluaciones', cancelButtonText: 'Cancelar', confirmButtonColor: '#73BC25',
      }).then((res) => {
        if (!res.isConfirmed) return;
        this.promoviendo = true;
        window.axios.post('/soporte/formularios/respuestas/promover', { ids })
          .then(({ data }) => {
            data.respuestas.forEach((r) => {
              const lista = this.revResp[r.formulario_id] || [];
              const i = lista.findIndex((x) => x.id === r.id);
              if (i >= 0) lista.splice(i, 1, r);
            });
            this.recontarDesdeRevision();
            this.$swal.fire({
              icon: data.errores.length ? 'warning' : 'success', title: 'Datos enviados a Evaluaciones',
              html: `<b>${data.creadas}</b> nuevo(s) · <b>${data.actualizadas}</b> actualizado(s) · ${data.promovidas} captura(s)${data.errores.length ? `<br><small>${data.errores.slice(0, 5).join('<br>')}</small>` : ''}`,
            });
          })
          .catch(() => this.$swal.fire({ icon: 'error', title: 'No se pudo completar' }))
          .finally(() => { this.promoviendo = false; });
      });
    },
    recontarDesdeRevision() {
      Object.entries(this.revResp).forEach(([fid, lista]) => {
        const f = this.formMap.get(Number(fid));
        if (!f) return;
        f.asignaciones.forEach((a) => {
          const rs = lista.filter((r) => r.asignacion_id === a.id);
          a.capturas = rs.length;
          a.pendientes = rs.filter((r) => !r.promovido).length;
        });
        f.capturas = lista.length;
        f.pendientes = lista.filter((r) => !r.promovido).length;
      });
    },
    descartar(r) {
      this.$swal.fire({
        icon: 'warning', title: `Descartar captura de ${r.parcela_codigo}`, text: `${this.fmt(r.fecha)} · ${r.responsable || ''}. No pasara a Evaluaciones.`,
        showCancelButton: true, confirmButtonText: 'Descartar', cancelButtonText: 'Cancelar', confirmButtonColor: '#FF6B00',
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/soporte/formularios/respuestas/${r.id}`)
          .then(() => {
            const lista = this.revResp[r.formulario_id] || [];
            this.$set(this.revResp, r.formulario_id, lista.filter((x) => x.id !== r.id));
            this.recontarDesdeRevision();
          })
          .catch(() => this.$swal.fire({ icon: 'error', title: 'No se pudo descartar' }));
      });
    },
  },
};
</script>

<style scoped>
/* Paleta Cengicaña */
.fm {
  --verde: #73BC25; --verde-2: #A3D300; --amarillo: #FFCC00; --naranja: #FF6B00; --gris: #CED2D5;
  --verde-osc: #1f5632; --tinta: #1e2a1a; --suave: #6b7568; --borde: #e3e7e1;
}
.fm-muted { color: var(--suave); }
.fm-nowrap { white-space: nowrap; }
.fm-txt-ok { color: #3f7a12; }
.fm-txt-am { color: #9a6c00; }
.fm-txt-nar { color: #c95500; }
.fm-txt-err { color: #c0392b; font-weight: 700; }
.fm-req { margin-left: 2px; color: var(--naranja); font-weight: 800; }
kbd { padding: 1px 5px; border: 1px solid var(--borde); border-radius: 4px; background: #f7f9f5; color: var(--tinta); font-size: 11px; box-shadow: none; }

/* KPIs */
.fm-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; margin-bottom: 14px; }
.fm-kpi { display: flex; align-items: center; gap: 12px; padding: 13px 15px; border: 1px solid var(--borde); border-left: 5px solid var(--verde); border-radius: 14px; background: #fff; text-align: left; }
.fm-kpi > i { display: grid; flex: 0 0 auto; place-items: center; width: 40px; height: 40px; border-radius: 12px; background: color-mix(in srgb, var(--verde) 16%, #fff); color: var(--verde); font-size: 17px; }
.fm-kpi strong { display: block; color: var(--tinta); font-size: 22px; line-height: 1.1; }
.fm-kpi span { display: block; color: var(--verde-osc); font-size: 12.5px; font-weight: 700; }
.fm-kpi small { color: var(--suave); font-size: 11.5px; }
.fm-kpi.is-asig { border-left-color: var(--verde-2); }
.fm-kpi.is-asig > i { background: color-mix(in srgb, var(--verde-2) 22%, #fff); color: #5f7a00; }
.fm-kpi.is-cap { border-left-color: var(--gris); }
.fm-kpi.is-cap > i { background: color-mix(in srgb, var(--gris) 45%, #fff); color: #5b6267; }
.fm-kpi.is-pend { border-left-color: var(--naranja); cursor: pointer; transition: transform .15s, box-shadow .15s; }
.fm-kpi.is-pend > i { background: color-mix(in srgb, var(--naranja) 14%, #fff); color: var(--naranja); }
.fm-kpi.is-pend:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(31, 86, 50, .1); }
.fm-kpi.is-pend.is-on { box-shadow: 0 0 0 2px var(--naranja) inset; }

/* Pestañas y cajas */
.fm-tabs { display: flex; gap: 4px; margin-bottom: -1px; }
.fm-tabs > button { padding: 9px 16px; border: 1px solid transparent; border-bottom: 0; border-radius: 12px 12px 0 0; background: transparent; color: var(--suave); font-size: 13px; font-weight: 700; }
.fm-tabs > button.is-on { border-color: var(--borde); background: #fff; color: var(--verde-osc); box-shadow: inset 0 3px 0 var(--verde); }
.fm-tabs > button small { margin-left: 4px; padding: 0 6px; border-radius: 999px; background: var(--naranja); color: #fff; font-size: 10.5px; }
.fm-caja { padding: 14px; border: 1px solid var(--borde); border-radius: 0 14px 14px 14px; background: #fff; }
.fm-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 14px; }
.fm-bar-btn { margin-left: auto; }
.fm-buscar { position: relative; display: flex; flex: 1 1 240px; align-items: center; max-width: 360px; }
.fm-buscar.is-full { max-width: none; margin-bottom: 8px; }
.fm-buscar i { position: absolute; left: 12px; color: var(--suave); font-size: 13px; }
.fm-buscar input { width: 100%; height: 34px; padding: 0 12px 0 34px; border: 1px solid var(--borde); border-radius: 999px; background: #f7f9f5; font-size: 13px; outline: none; }
.fm-buscar input:focus { border-color: var(--verde); background: #fff; }
.fm-sel { width: auto; min-width: 170px; height: 34px; border-radius: 9px; }
.fm-sel.is-ancho { min-width: 280px; }
.fm-check-in { display: inline-flex; align-items: center; gap: 5px; margin: 0; color: var(--suave); font-size: 12.5px; font-weight: 600; }
.fm-seg { display: inline-flex; padding: 3px; border: 1px solid var(--borde); border-radius: 9px; background: #f3f5f1; }
.fm-seg button { padding: 3px 11px; border: 0; border-radius: 6px; background: transparent; color: var(--suave); font-size: 12px; font-weight: 600; }
.fm-seg button.is-on { background: #fff; color: var(--verde-osc); box-shadow: 0 1px 3px rgba(30, 42, 26, .12); }
.fm-btn { border: 0; border-radius: 9px; background: var(--verde); color: #fff; font-weight: 700; }
.fm-btn:hover { background: #64a51f; color: #fff; }
.fm-btn:disabled { opacity: .55; }
.fm-btn-sec { border: 1px solid var(--borde); border-radius: 9px; background: #fff; color: var(--verde-osc); font-weight: 600; }
.fm-btn-sec:hover { border-color: var(--verde); }
.fm-icon { display: inline-grid; place-items: center; width: 30px; height: 30px; border: 1px solid transparent; border-radius: 8px; background: transparent; color: var(--suave); font-size: 12.5px; }
.fm-icon:hover:not(:disabled) { border-color: var(--borde); background: #f1f8e9; color: var(--verde-osc); }
.fm-icon.is-del:hover { border-color: #ffd2b3; background: #fff1e6; color: var(--naranja); }
.fm-icon:disabled { opacity: .35; }
.fm-vacio { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 40px 16px; border: 1px dashed var(--gris); border-radius: 12px; color: var(--suave); text-align: center; }
.fm-vacio > i { color: var(--gris); font-size: 28px; }
.fm-vacio strong { color: var(--tinta); }
.fm-vacio.is-mini { padding: 18px; }
.fm-vacio.is-mini > i { font-size: 18px; }
.fm-cargando { padding: 30px; color: var(--suave); text-align: center; }

/* Lista */
.fm-vista { display: inline-flex; padding: 3px; border: 1px solid var(--borde); border-radius: 9px; background: #f3f5f1; }
.fm-vista button { width: 30px; height: 26px; border: 0; border-radius: 6px; background: transparent; color: var(--suave); }
.fm-vista button.is-on { background: #fff; color: var(--verde-osc); box-shadow: 0 1px 3px rgba(30, 42, 26, .12); }
.fm-lista-wrap { overflow-x: auto; border: 1px solid var(--borde); border-radius: 12px; }
.fm-lista { width: 100%; border-collapse: collapse; font-size: 13px; }
.fm-lista thead th { padding: 9px 8px; background: #f7f9f5; color: var(--suave); font-size: 11px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; white-space: nowrap; }
.fm-lista td { padding: 9px 8px; border-top: 1px solid #edf0eb; vertical-align: middle; }
.fm-fila { cursor: pointer; transition: background .12s; }
.fm-fila:hover { background: #f7fbf1; }
.fm-fila.is-abierta { background: #f4faec; }
.fm-fila.is-off td { opacity: .6; }
.fm-lista .fm-icon { width: 25px; }
.fm-lista td:last-child { padding-right: 10px; }
.fm-col-exp { width: 22px; color: var(--suave); font-size: 11px; text-align: center; }
.fm-l-nom { display: block; color: var(--tinta); white-space: nowrap; }
.fm-lista td > small.fm-muted { white-space: nowrap; }
.fm-lista small { font-size: 11px; }
.fm-l-campos { display: flex; flex-wrap: nowrap; gap: 3px; }
.fm-l-campos .fm-cm { white-space: nowrap; }
.fm-cm.is-mas { background: #eef2ea; color: var(--suave); }
.fm-tag.is-auto { background: color-mix(in srgb, var(--verde-2) 22%, #fff); color: #4f6600; }
.fm-avatares { display: flex; align-items: center; }
.fm-avatares .fm-avatar { width: 26px; height: 26px; margin-right: -6px; border: 2px solid #fff; font-size: 11px; }
.fm-avatares .fm-avatar.is-off { background: var(--gris); }
.fm-avatares small { margin-left: 10px; }
.fm-pend-n { display: inline-block; min-width: 26px; padding: 1px 8px; border-radius: 999px; background: color-mix(in srgb, var(--amarillo) 32%, #fff); color: #7d6100; font-weight: 800; text-align: center; }
.fm-pend-n:hover { background: var(--amarillo); color: #4d3c00; text-decoration: none; }
.fm-det td { padding: 4px 10px 14px; border-top: 0; background: #f4faec; }
.fm-det-grid { display: grid; grid-template-columns: 1.3fr 1fr; gap: 16px; padding: 10px 12px; border: 1px solid var(--borde); border-radius: 10px; background: #fff; }
.fm-det-grid h6 { margin: 0 0 8px; color: var(--verde-osc); font-size: 12.5px; font-weight: 800; }
.fm-det-grid .fm-desc { margin-bottom: 8px; }

/* Tarjetas */
.fm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
.fm-grid.is-anchas { grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); }
.fm-card { display: flex; flex-direction: column; gap: 10px; padding: 14px; border: 1px solid var(--borde); border-top: 3px solid var(--verde); border-radius: 14px; background: #fff; transition: box-shadow .15s; }
.fm-card:hover { box-shadow: 0 10px 22px rgba(31, 86, 50, .1); }
.fm-card.is-off { border-top-color: var(--gris); opacity: .7; }
.fm-card-top { display: flex; align-items: flex-start; gap: 10px; }
.fm-ico { display: grid; flex: 0 0 auto; place-items: center; width: 38px; height: 38px; border-radius: 11px; background: color-mix(in srgb, var(--verde) 16%, #fff); color: var(--verde); font-size: 16px; }
.fm-card-tit { flex: 1; min-width: 0; }
.fm-card-tit b { display: block; color: var(--tinta); font-size: 15px; }
.fm-card-tit small { color: var(--suave); font-size: 11.5px; }
.fm-codigo { padding: 0 6px; border-radius: 5px; background: #eef2ea; color: var(--verde-osc); font-weight: 700; }
.fm-codigo.is-ens { background: color-mix(in srgb, var(--verde) 16%, #fff); color: #3f7a12; }
.fm-tag { padding: 2px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 700; white-space: nowrap; }
.fm-tag.is-act { background: color-mix(in srgb, var(--verde) 18%, #fff); color: #3f7a12; }
.fm-tag.is-inact { background: color-mix(in srgb, var(--gris) 50%, #fff); color: #4f565b; }
.fm-tag.is-pend { background: color-mix(in srgb, var(--amarillo) 30%, #fff); color: #7d6100; }
.fm-tag.is-venc { background: #fff1e6; color: #b84d00; }
.fm-desc { margin: 0; color: #4d5a48; font-size: 12.5px; }
.fm-campos-mini { display: flex; flex-wrap: wrap; gap: 5px; }
.fm-cm { display: inline-flex; align-items: center; gap: 4px; padding: 2px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 600; }
.fm-cm i { font-size: 9px; }
.fm-cm em { color: inherit; opacity: .7; font-style: normal; font-weight: 500; }
.fm-cm.is-var { background: color-mix(in srgb, var(--verde) 14%, #fff); color: #2f6b0f; }
.fm-cm.is-var.is-cosecha { background: color-mix(in srgb, var(--naranja) 12%, #fff); color: #b84d00; }
.fm-cm.is-libre { border: 1px dashed var(--gris); background: #fafbf9; color: #5b6267; }
.fm-promo { padding: 6px 10px; border-radius: 8px; font-size: 12px; font-weight: 600; }
.fm-promo i { margin-right: 4px; }
.fm-promo.is-revision { background: color-mix(in srgb, var(--amarillo) 14%, #fff); color: #7d6100; }
.fm-promo.is-automatica { background: color-mix(in srgb, var(--verde-2) 18%, #fff); color: #4f6600; }
.fm-asigs { display: flex; flex-direction: column; gap: 5px; font-size: 12px; }
.fm-asig { display: flex; align-items: center; gap: 8px; padding: 5px 8px; border-radius: 9px; background: #f7f9f5; }
.fm-asig.is-off { opacity: .6; }
.fm-asig > div { flex: 1; min-width: 0; }
.fm-asig b { color: var(--tinta); font-size: 12.5px; }
.fm-asig small { display: block; color: var(--suave); font-size: 11px; }
.fm-asig.is-panel { margin-bottom: 6px; padding: 8px 10px; border: 1px solid var(--borde); background: #fff; }
.fm-asig.is-sel { border-color: var(--verde); background: #f7fbf1; }
.fm-asig-acc { display: flex; }
.fm-avatar { display: grid; flex: 0 0 auto; place-items: center; width: 28px; height: 28px; border-radius: 50%; background: var(--verde); color: #fff; font-size: 12px; font-weight: 800; }
.fm-asig-n { min-width: 26px; padding: 1px 7px; border-radius: 999px; background: #fff; color: var(--verde-osc); font-weight: 800; text-align: center; }
.fm-card-pie { display: flex; align-items: center; gap: 8px; margin-top: auto; padding-top: 8px; border-top: 1px dashed #e8ece5; }
.fm-card-stats { display: flex; flex-wrap: wrap; gap: 10px; color: var(--suave); font-size: 12px; }
.fm-card-stats b { color: var(--verde-osc); }
.fm-card-stats a b { color: inherit; }
.fm-card-acc { display: flex; margin-left: auto; }
.fm-ult { margin-left: auto; }
.fm-card-btn { align-self: flex-start; }
.fm-bienvenida { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; padding: 14px 16px; border: 1px solid var(--borde); border-left: 5px solid var(--verde); border-radius: 14px; background: #fff; }
.fm-bienvenida > i { color: var(--verde); font-size: 24px; }
.fm-bienvenida h5 { margin: 0; color: var(--tinta); font-weight: 800; }
.fm-bienvenida small { color: var(--suave); }

/* Captura */
.fm-cap-cab { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 12px; padding: 12px 14px; border: 1px solid var(--borde); border-top: 4px solid var(--verde); border-radius: 14px; background: #fff; }
.fm-cap-cab.is-revision { border-top-color: var(--amarillo); }
.fm-volver { width: 36px; height: 36px; border: 1px solid var(--borde); border-radius: 10px; background: #fff; color: var(--verde-osc); }
.fm-volver:hover { border-color: var(--verde); }
.fm-cap-tit { flex: 1; min-width: 220px; }
.fm-cap-tit h5 { margin: 0; color: var(--tinta); font-weight: 800; }
.fm-cap-tit small { color: var(--suave); }
.fm-modo { padding: 5px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; }
.fm-modo.is-revision { background: color-mix(in srgb, var(--amarillo) 20%, #fff); color: #7d6100; }
.fm-modo.is-automatica { background: color-mix(in srgb, var(--verde-2) 22%, #fff); color: #4f6600; }
.fm-modo i { margin-right: 4px; }
.fm-cap-cab + .fm-caja { border-radius: 14px; }
.fm-cap-bar { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 14px; margin-bottom: 10px; }
.fm-campo { display: flex; flex-direction: column; gap: 3px; }
.fm-campo > label { margin: 0; color: var(--suave); font-size: 10.5px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; }
.fm-cap-fechas { flex: 1; }
.fm-chips { display: flex; flex-wrap: wrap; gap: 5px; }
.fm-chip { padding: 3px 10px; border: 1px solid var(--borde); border-radius: 999px; background: #fff; color: #44503f; font-size: 12px; font-weight: 600; }
.fm-chip small { margin-left: 3px; padding: 0 5px; border-radius: 999px; background: color-mix(in srgb, var(--amarillo) 30%, #fff); color: #7d6100; }
.fm-chip.is-ok small { background: color-mix(in srgb, var(--verde) 20%, #fff); color: #3f7a12; }
.fm-chip.is-on { border-color: var(--verde); background: color-mix(in srgb, var(--verde) 12%, #fff); color: var(--verde-osc); }
.fm-aviso { padding: 5px 10px; border-radius: 8px; background: #fff1e6; color: #b84d00; font-size: 12px; font-weight: 600; }
.fm-aviso.is-block { margin: 10px 0 0; }
.fm-ayuda { margin: 0 0 10px; padding: 7px 12px; border-radius: 8px; background: #f6f8f3; color: #3d4838; font-size: 12px; }
.fm-ayuda > i { margin-right: 5px; color: var(--verde); }
.fm-tabla-scroll { max-height: 64vh; overflow: auto; border: 1px solid var(--borde); border-radius: 10px; }
.fm-tabla-scroll.is-bajo { max-height: 340px; }
.fm-planilla, .fm-rev { min-width: 100%; border-collapse: separate; border-spacing: 0; font-size: 12.5px; }
.fm-planilla th, .fm-planilla td, .fm-rev th, .fm-rev td { border-right: 1px solid #edf0eb; border-bottom: 1px solid #edf0eb; }
.fm-planilla thead th, .fm-rev thead th { position: sticky; top: 0; z-index: 2; padding: 6px 10px; background: #f7f9f5; color: var(--suave); font-size: 11.5px; font-weight: 700; text-align: center; white-space: nowrap; }
.fm-planilla thead th small, .fm-rev thead th small { display: block; font-size: 10px; font-weight: 500; opacity: .85; }
.fm-planilla thead th.is-var-desarrollo, .fm-rev thead th.is-var-desarrollo { background: #3f7a12; color: #fff; }
.fm-planilla thead th.is-var-cosecha, .fm-rev thead th.is-var-cosecha { background: #c95500; color: #fff; }
.fm-planilla thead th.is-libre, .fm-rev thead th.is-libre { background: #eef0ee; color: #4f565b; }
.fm-planilla thead th .fm-req { color: var(--amarillo); }
.fm-planilla .fx { position: sticky; left: 0; z-index: 1; min-width: 92px; padding: 4px 10px; border-right: 2px solid var(--borde); background: #fff; white-space: nowrap; }
.fm-planilla thead .fx { z-index: 3; background: #f7f9f5; text-align: left; text-transform: uppercase; }
.fm-planilla td.fx b { display: block; color: var(--tinta); }
.fm-planilla td.fx small { color: var(--suave); font-size: 10.5px; }
.fm-planilla tr.is-corte td { border-top: 2px solid color-mix(in srgb, var(--verde) 45%, #fff); }
.fm-cel { min-width: 110px; padding: 0 !important; }
.fm-cel input, .fm-cel select { width: 100%; height: 40px; padding: 0 8px; border: 0; outline: none; background: transparent; font-size: 13px; font-variant-numeric: tabular-nums; text-align: center; }
.fm-cel input:focus, .fm-cel select:focus { background: #fff; box-shadow: inset 0 0 0 2px var(--verde); }
.fm-cel-obs { min-width: 180px; }
.fm-cel-obs input { text-align: left; }
.fm-cel.is-ok { background: color-mix(in srgb, var(--verde) 8%, #fff); }
.fm-cel.is-fuera { background: color-mix(in srgb, var(--naranja) 13%, #fff); }
.fm-cel.is-fuera input { color: #b84d00; font-weight: 700; }
.fm-cel.is-invalido { background: #fdecea; }
.fm-cel.is-invalido input, .fm-cel.is-invalido select { color: #c0392b; font-weight: 700; }
.fm-cel.is-sucio { box-shadow: inset 0 -3px 0 var(--amarillo); }
.fm-cel.is-falta { background: repeating-linear-gradient(45deg, #fff, #fff 5px, #fff4e8 5px, #fff4e8 10px); }
.fm-th-est, .fm-est { width: 34px; text-align: center; }
.fm-est i { font-size: 13px; }
.fm-est .is-prom { color: #3f7a12; }
.fm-est .is-pend { color: #b38f00; }
.fm-est .is-inc { color: var(--naranja); }
.fm-est .is-edit { color: #9a7600; }
.fm-cap-pie { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 8px; margin-top: 10px; font-size: 12.5px; }
.fm-cap-pie > span { margin-right: auto; }

/* Revision */
.fm-lote { margin-bottom: 12px; border: 1px solid var(--borde); border-left: 4px solid var(--amarillo); border-radius: 12px; }
.fm-lote.is-prom { border-left-color: var(--verde); }
.fm-lote header { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 10px 12px; border-bottom: 1px solid var(--borde); }
.fm-lote header > div { flex: 1; min-width: 220px; }
.fm-lote header b { display: block; color: var(--tinta); }
.fm-lote header small { color: var(--suave); font-size: 12px; }
.fm-lote .fm-tabla-scroll { border: 0; border-radius: 0 0 12px 12px; }
.fm-rev td { padding: 6px 10px; }
.fm-rev tbody tr.is-prom td { background: #fafcf7; }
.fm-rev-val { text-align: center; font-variant-numeric: tabular-nums; font-weight: 600; }
.fm-rev-val.is-ok { color: #24480c; }
.fm-rev-val.is-fuera { background: color-mix(in srgb, var(--naranja) 13%, #fff); color: #b84d00; }
.fm-rev-val.is-invalido { color: #c0392b; }
.fm-rev-val.is-vacio { color: #c3c8c0; font-weight: 400; }
.fm-rev-obs { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* Panel */
.fm-velo { position: fixed; inset: 0; z-index: 1040; background: rgba(20, 30, 16, .35); }
.fm-panel { position: fixed; top: 0; right: 0; bottom: 0; z-index: 1050; display: flex; flex-direction: column; width: min(520px, 100vw); background: #fff; box-shadow: -10px 0 30px rgba(20, 30, 16, .18); }
.fm-panel.is-ancho { width: min(980px, 100vw); }
.fm-ph { display: flex; align-items: center; gap: 12px; padding: 14px 18px; border-top: 5px solid var(--verde); border-bottom: 1px solid var(--borde); }
.fm-ph h5 { margin: 0; color: var(--tinta); font-size: 17px; font-weight: 800; }
.fm-ph small { color: var(--suave); font-size: 12px; }
.fm-x { margin-left: auto; border: 0; background: transparent; color: var(--suave); font-size: 26px; line-height: 1; }
.fm-pb { flex: 1; overflow-y: auto; padding: 14px 18px; }
.fm-pb label { color: var(--verde-osc); font-size: 12.5px; font-weight: 700; }
.fm-pb .form-control:focus { border-color: var(--verde); box-shadow: 0 0 0 .15rem rgba(115, 188, 37, .2); }
.fm-pf { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 8px; padding: 12px 18px; border-top: 1px solid var(--borde); background: #fafbf8; }
.fm-pf .fm-error { flex: 1; margin: 0; }
.fm-disenador { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.fm-col { min-width: 0; }
.fm-sub { display: flex; align-items: center; gap: 7px; margin: 4px 0 10px; color: var(--verde-osc); font-size: 13.5px; font-weight: 800; }
.fm-sub span { display: grid; place-items: center; width: 22px; height: 22px; border-radius: 50%; background: var(--verde); color: #fff; font-size: 11.5px; }
.fm-sub small { padding: 0 7px; border-radius: 999px; background: #eef2ea; color: var(--suave); font-size: 11px; }
.fm-col .fm-sub:not(:first-child) { margin-top: 18px; }
.fm-seg-g { display: flex; gap: 6px; }
.fm-seg-g button { flex: 1; padding: 8px 10px; border: 1.5px solid var(--borde); border-radius: 10px; background: #fff; color: #44503f; font-size: 12.5px; font-weight: 700; text-align: left; }
.fm-seg-g button small { display: block; color: var(--suave); font-size: 11px; font-weight: 500; }
.fm-seg-g button.is-on { border-color: var(--verde); background: color-mix(in srgb, var(--verde) 11%, #fff); color: #2f6b0f; }
.fm-check { display: flex; align-items: center; gap: 6px; }
.fm-cat { margin-bottom: 8px; }
.fm-cat > small { display: block; margin-bottom: 4px; font-size: 11px; font-weight: 800; text-transform: uppercase; }
.fm-cat > small.is-desarrollo { color: #3f7a12; }
.fm-cat > small.is-cosecha { color: #b84d00; }
.fm-chip-var { padding: 4px 10px; border: 1px solid var(--borde); border-radius: 999px; background: #fff; color: #44503f; font-size: 12px; font-weight: 600; transition: all .12s; }
.fm-chip-var i { margin-right: 2px; color: var(--suave); font-size: 9.5px; }
.fm-chip-var em { color: var(--suave); font-size: 10.5px; font-style: normal; font-weight: 500; }
.fm-chip-var.is-desarrollo.is-on { border-color: var(--verde); background: color-mix(in srgb, var(--verde) 14%, #fff); color: #2f6b0f; }
.fm-chip-var.is-cosecha.is-on { border-color: var(--naranja); background: color-mix(in srgb, var(--naranja) 11%, #fff); color: #b84d00; }
.fm-chip-var.is-on i { color: inherit; }
.fm-libre-btn { margin-top: 8px; padding: 6px 12px; border: 1.5px dashed var(--gris); border-radius: 9px; background: #fff; color: #5b6267; font-size: 12.5px; font-weight: 700; }
.fm-libre-btn small { color: var(--suave); font-weight: 500; }
.fm-campo-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; padding: 7px 8px; border: 1px solid var(--borde); border-left: 4px solid var(--verde); border-radius: 10px; background: #fff; }
.fm-campo-row.is-cosecha { border-left-color: var(--naranja); }
.fm-campo-row.is-libre { border-left: 4px dashed var(--gris); background: #fafbf9; }
.fm-orden { display: flex; flex-direction: column; }
.fm-orden button { height: 15px; padding: 0 4px; border: 0; background: transparent; color: var(--suave); font-size: 10px; line-height: 1; }
.fm-orden button:disabled { opacity: .25; }
.fm-campo-info { flex: 1; min-width: 0; }
.fm-campo-info b { display: block; color: var(--tinta); font-size: 13px; }
.fm-campo-info b i { color: var(--verde); font-size: 10px; }
.fm-campo-row.is-cosecha .fm-campo-info b i { color: var(--naranja); }
.fm-campo-info small { color: var(--suave); font-size: 11px; }
.fm-libre-cfg { display: flex; gap: 5px; margin-top: 4px; }
.fm-libre-cfg select { flex: 0 0 110px; }
.fm-req-tg { display: inline-flex; align-items: center; gap: 4px; margin: 0; padding: 2px 8px; border-radius: 999px; color: var(--suave) !important; font-size: 11px !important; font-weight: 600 !important; cursor: pointer; white-space: nowrap; }
.fm-req-tg.is-on { background: #fff1e6; color: #b84d00 !important; }
.fm-req-tg input { margin: 0; }
.fm-nota { margin-top: 10px; padding: 8px 10px; border-radius: 8px; background: #f4faec; color: #2e5c0e; font-size: 12px; }
.fm-nota-s { margin-bottom: 8px; font-size: 12.5px; }
.fm-error { margin: 6px 0 0; color: #b84d00; font-size: 12px; }
.fm-parcelas { display: grid; grid-template-columns: repeat(auto-fill, minmax(78px, 1fr)); gap: 5px; max-height: 260px; overflow-y: auto; padding: 2px; }
.fm-parcelas button { display: flex; flex-direction: column; align-items: center; padding: 4px; border: 1.5px solid var(--borde); border-radius: 9px; background: #fff; line-height: 1.2; }
.fm-parcelas button b { color: var(--tinta); font-size: 12px; }
.fm-parcelas button small { color: var(--suave); font-size: 10px; }
.fm-parcelas button.is-on { border-color: var(--verde); background: color-mix(in srgb, var(--verde) 12%, #fff); }
.fm-parcelas .fm-p-todas { justify-content: center; color: var(--verde-osc); font-size: 12px; font-weight: 800; }

.fm-fade-enter-active, .fm-fade-leave-active { transition: opacity .2s; }
.fm-fade-enter, .fm-fade-leave-to { opacity: 0; }
.fm-slide-enter-active, .fm-slide-leave-active { transition: transform .25s ease; }
.fm-slide-enter, .fm-slide-leave-to { transform: translateX(100%); }

@media (max-width: 860px) {
  .fm-disenador { grid-template-columns: 1fr; }
  .fm-grid.is-anchas { grid-template-columns: 1fr; }
  .fm-bar-btn { margin-left: 0; }
}
</style>
