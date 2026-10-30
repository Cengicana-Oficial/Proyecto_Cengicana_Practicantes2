<template>
  <div class="mc">
    <!-- ============ Indicadores por estado ============ -->
    <div class="mc-kpis">
      <button type="button" class="mc-kpi" :class="{ 'is-on': !filtroEtapa }" @click="filtroEtapa = ''">
        <i class="fas fa-vials"></i>
        <div><strong>{{ n(items.length) }}</strong><span>Muestras</span><small>{{ ensayosConMuestras }} ensayo(s)</small></div>
      </button>
      <button v-for="e in etapas" :key="e.id" type="button" class="mc-kpi" :class="['is-' + e.id, { 'is-on': filtroEtapa === e.id }]" @click="filtroEtapa = filtroEtapa === e.id ? '' : e.id">
        <i :class="e.icon"></i>
        <div><strong>{{ n(porEtapa[e.id]) }}</strong><span>{{ e.label }}</span><small>{{ pct(porEtapa[e.id]) }} % del total</small></div>
      </button>
    </div>

    <!-- ============ Conexion con el modulo Laboratorio ============ -->
    <div class="mc-conexion" :class="sync && sync.ok === false ? 'is-error' : 'is-ok'">
      <i :class="sync && sync.ok === false ? 'fas fa-unlink' : 'fas fa-link'"></i>
      <span v-if="sync && sync.ok === false"><b>Sin conexion con el modulo Laboratorio.</b> Se muestran los datos de la ultima lectura. <small>{{ sync.error }}</small></span>
      <span v-else>
        <b>Conectado al modulo Laboratorio</b> (solo lectura) ·
        {{ n(vinculadas) }} muestra(s) recibidas por el laboratorio · {{ n(conResultado) }} con informe firmado
        <small v-if="sync && sync.en"> · ultima lectura {{ fmtFechaHora(sync.en) }}</small>
        <small v-if="ultimoEnvio"> · ultimo envio del Laboratorio {{ fmtFechaHora(ultimoEnvio.recibido_en) }} <b :class="ultimoEnvio.respuesta_http === 200 ? 'mc-txt-ok' : 'mc-txt-nar'">{{ ultimoEnvio.respuesta_http === 200 ? 'aceptado' : 'rechazado (' + ultimoEnvio.respuesta_http + ')' }}</b></small>
      </span>
      <span class="mc-conexion-acc">
      <button v-if="puedeVerEnvios" type="button" class="btn btn-sm mc-btn-sec" title="Bitacora de la API del Laboratorio" @click="abrirEnvios"><i class="fas fa-inbox"></i> Envios</button>
      <button v-if="puedeActualizar" type="button" class="btn btn-sm mc-btn-sec mc-btn-vinc" title="Muestras que el laboratorio recibio sin escanear la etiqueta de SIGEC" @click="abrirVinculos"><i class="fas fa-link"></i> Vincular sin escaneo</button>
      <button type="button" class="btn btn-sm mc-btn-sec" :disabled="sincronizando" @click="sincronizar"><i :class="sincronizando ? 'fas fa-spinner fa-spin' : 'fas fa-sync-alt'"></i> Actualizar</button>
      </span>
    </div>

    <!-- ============ Barra ============ -->
    <div class="mc-bar">
      <div class="mc-buscar">
        <i class="fas fa-barcode"></i>
        <input ref="buscar" type="text" v-model="busqueda" placeholder="Escanee o escriba un ID, ensayo, parcela, tratamiento..." @keydown.enter="abrirPorId">
        <button v-if="busqueda" type="button" @click="busqueda = ''">×</button>
      </div>
      <select class="form-control form-control-sm mc-sel" v-model="filtroTipo">
        <option value="">Todos los tipos</option>
        <option v-for="t in tiposPresentes" :key="t" :value="t">{{ t }}</option>
      </select>
      <div class="mc-fechas">
        <input type="date" class="form-control form-control-sm" v-model="desde" title="Muestreo desde">
        <span>a</span>
        <input type="date" class="form-control form-control-sm" v-model="hasta" title="Muestreo hasta">
      </div>
      <div class="mc-campo-agr">
        <span>Agrupar</span>
        <div class="mc-seg">
          <button v-for="a in agrupaciones" :key="a.id" type="button" :class="{ 'is-on': agrupar === a.id }" @click="agrupar = a.id">{{ a.label }}</button>
        </div>
      </div>
      <button v-if="hayFiltros" type="button" class="mc-link" @click="limpiar"><i class="fas fa-times"></i> Limpiar</button>
      <div class="mc-acc">
        <button type="button" class="mc-link" @click="plegarTodo(false)">Expandir</button>
        <span class="mc-muted">·</span>
        <button type="button" class="mc-link" @click="plegarTodo(true)">Contraer</button>
      </div>
    </div>

    <!-- ============ Seleccion masiva ============ -->
    <transition name="mc-fade">
      <div v-if="seleccion.length" class="mc-masivo">
        <b>{{ seleccion.length }}</b> muestra(s) seleccionada(s)
        <span class="mc-muted">→ cambiar a</span>
        <button v-for="e in ['Recibida', 'En proceso', 'Completado']" :key="e" type="button" class="mc-estado-btn" :class="'is-' + claseEstado(e)" :disabled="masivoGuardando" @click="cambiarLote(e)">{{ e }}</button>
        <input v-model="masivoAnalistas" class="form-control form-control-sm" placeholder="Analista(s) (opcional)">
        <button type="button" class="mc-link" @click="seleccion = []">Quitar seleccion</button>
      </div>
    </transition>

    <!-- ============ Grupos ============ -->
    <div v-if="!filtradas.length" class="mc-vacio">
      <i class="fas fa-vials"></i>
      <strong>{{ items.length ? 'Ninguna muestra coincide con los filtros.' : 'Aun no hay muestras registradas.' }}</strong>
      <a v-if="items.length" href="#" @click.prevent="limpiar">Quitar filtros</a>
      <a v-else :href="`${base}/soporte/muestras/generar`">Generar muestras</a>
    </div>

    <section v-for="g in grupos" :key="g.clave" class="mc-grupo" :class="{ 'is-plegado': plegado(g.clave) }">
      <header class="mc-g-cab" @click="togglePliegue(g.clave)">
        <i class="fas fa-chevron-right mc-g-chev"></i>
        <input v-if="puedeActualizar" type="checkbox" class="mc-check" :checked="todoSel(g)" :indeterminate.prop="algoSel(g) && !todoSel(g)" title="Seleccionar el grupo" @click.stop @change="selGrupo(g, $event.target.checked)">
        <div class="mc-g-tit">
          <b>{{ g.titulo }}</b>
          <small>{{ g.subtitulo }}</small>
        </div>
        <div class="mc-g-tipos">
          <span v-for="t in g.tipos" :key="t" class="mc-tipo"><i :class="iconoTipo(t)"></i> {{ t }}</span>
        </div>
        <div class="mc-g-prog" :title="`${g.etapas.recibida} recibidas · ${g.etapas.proceso} en proceso · ${g.etapas.completado} completadas`">
          <div class="mc-barra">
            <i class="is-completado" :style="{ width: (g.etapas.completado / g.items.length) * 100 + '%' }"></i>
            <i class="is-proceso" :style="{ width: (g.etapas.proceso / g.items.length) * 100 + '%' }"></i>
          </div>
          <small><b>{{ g.etapas.completado }}</b>/{{ g.items.length }} completadas</small>
        </div>
        <span class="mc-g-n">{{ g.items.length }}</span>
      </header>

      <div v-if="!plegado(g.clave)" class="mc-cuerpo">
        <div v-for="b in bloques(g)" :key="b.clave" class="mc-muestreo" :class="{ 'is-sub': b.sub, 'is-plegado': b.sub && plegado(b.clave) }">
          <!-- Muestreo dentro del ensayo -->
          <header v-if="b.sub" class="mc-m-cab" @click="togglePliegue(b.clave)">
            <i class="fas fa-chevron-right mc-g-chev"></i>
            <input v-if="puedeActualizar" type="checkbox" class="mc-check" :checked="todoSel(b)" :indeterminate.prop="algoSel(b) && !todoSel(b)" title="Seleccionar el muestreo" @click.stop @change="selGrupo(b, $event.target.checked)">
            <span class="mc-m-fecha"><b>{{ dia(b.fecha) }}</b><small>{{ mesAnio(b.fecha) }}</small></span>
            <div class="mc-m-tit">
              <b>Muestreo {{ b.numero }} · <span class="mc-tipo is-mini"><i :class="iconoTipo(b.tipo)"></i> {{ b.tipo }}</span></b>
              <small>{{ b.items.length }} muestra(s) · {{ b.parcelas }} parcela(s){{ b.solicitante ? ' · ' + b.solicitante : '' }} · {{ b.desde }}{{ b.items.length > 1 ? ' a ' + b.hasta : '' }}</small>
            </div>
            <div class="mc-g-prog is-mini" :title="`${b.etapas.recibida} recibidas · ${b.etapas.proceso} en proceso · ${b.etapas.completado} completadas`">
              <div class="mc-barra">
                <i class="is-completado" :style="{ width: (b.etapas.completado / b.items.length) * 100 + '%' }"></i>
                <i class="is-proceso" :style="{ width: (b.etapas.proceso / b.items.length) * 100 + '%' }"></i>
              </div>
              <small><b>{{ b.etapas.completado }}</b>/{{ b.items.length }} completadas</small>
            </div>
          </header>
          <div v-if="!b.sub || !plegado(b.clave)" class="mc-tabla-wrap">
            <table class="mc-tabla">
              <thead>
                <tr>
                  <th v-if="puedeActualizar" class="mc-c-chk"></th>
                  <th class="mc-w-id">ID de muestra</th>
                  <th v-if="agrupar === 'ninguno'" class="mc-w-tipo">Tipo</th>
                  <th v-if="agrupar === 'ninguno'" class="mc-w-fecha">Muestreo</th>
                  <th v-if="agrupar === 'ninguno'" class="mc-w-ens">Ensayo</th>
                  <th class="mc-w-parc">Parcela</th>
                  <th class="mc-w-ciclo">Ciclo</th>
                  <th class="text-right mc-w-dias">Dias</th>
                  <th>Resultado</th>
                  <th class="mc-w-acc"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="m in visibles(b)" :key="m.id" :class="{ 'is-sel': seleccion.includes(m.id) }" @click="abrir(m)">
                  <td v-if="puedeActualizar" class="mc-c-chk" @click.stop><input type="checkbox" class="mc-check" :value="m.id" v-model="seleccion"></td>
                  <td class="mc-id">{{ m.id_muestra }}</td>
                  <td v-if="agrupar === 'ninguno'"><span class="mc-tipo is-mini"><i :class="iconoTipo(m.tipo)"></i> {{ m.tipo }}</span></td>
                  <td v-if="agrupar === 'ninguno'" class="mc-nowrap">{{ fmt(m.fecha) }}</td>
                  <td v-if="agrupar === 'ninguno'">{{ ensayoCodigo(m.ensayo_id) }}</td>
                  <td class="mc-nowrap">
                    <template v-if="m.parcela || m.tratamiento"><b>{{ m.parcela || '—' }}</b> <small class="mc-muted">{{ [m.tratamiento, m.repeticion ? 'R' + m.repeticion : null].filter(Boolean).join(' · ') }}</small></template>
                    <span v-else class="mc-muted">compuesta</span>
                  </td>
                  <td>
                    <span class="mc-ciclo" :class="'is-' + claseEstado(m.estado)">
                      <i v-for="k in 3" :key="k" :class="{ 'is-on': etapaIdx(m.estado) >= k - 1 }"></i>
                      <em>{{ m.estado }}</em>
                    </span>
                  </td>
                  <td class="text-right" :class="{ 'mc-txt-nar': diasLab(m) > 21 && m.estado !== 'Completado' }" :title="m.estado === 'Completado' ? 'dias hasta el resultado' : 'dias desde el muestreo'">{{ diasLab(m) }}</td>
                  <td class="mc-res">
                    <span v-if="m.n_analitos" class="mc-res-n" :title="m.lab && m.lab.informe ? 'Informe ' + m.lab.informe : ''"><i class="fas fa-flask"></i> {{ m.n_analitos }} resultado(s)</span>
                    <span v-else-if="m.lab" class="mc-lab-chip" :title="m.lab.codigo ? 'Codigo de laboratorio ' + m.lab.codigo : ''">{{ m.lab.estado }}</span>
                    <span v-else-if="m.resultado_texto" class="mc-muted" :title="m.resultado_texto">{{ m.resultado_texto }}</span>
                    <span v-else class="mc-muted">no recibida</span>
                  </td>
                  <td class="text-right" @click.stop>
                    <button v-if="puedeActualizar" type="button" class="mc-icon" title="Actualizar" @click="abrir(m, true)"><i class="fas fa-pen"></i></button>
                  </td>
                </tr>
              </tbody>
            </table>
            <button v-if="b.items.length > limite(b.clave)" type="button" class="mc-mas" @click="verMas(b.clave)">
              Ver {{ Math.min(POR_GRUPO, b.items.length - limite(b.clave)) }} mas · {{ b.items.length - limite(b.clave) }} restantes
            </button>
          </div>
        </div>
      </div>
    </section>

    <!-- ============ Panel de detalle ============ -->
    <transition name="mc-fade"><div v-if="sel" class="mc-velo" @click="cerrar"></div></transition>
    <transition name="mc-slide">
      <aside v-if="sel" class="mc-panel" role="dialog">
        <header class="mc-ph" :class="'is-' + claseEstado(sel.estado)">
          <span class="mc-ph-ico"><i :class="iconoTipo(sel.tipo)"></i></span>
          <div>
            <h5>{{ sel.id_muestra }}</h5>
            <small>{{ sel.tipo }} · {{ ensayoCodigo(sel.ensayo_id) }}{{ sel.proyecto ? ' · ' + sel.proyecto : '' }}</small>
          </div>
          <button type="button" class="mc-x" @click="cerrar">×</button>
        </header>
        <div class="mc-pb">
          <div class="mc-tracker">
            <div v-for="(p, i) in cicloSteps" :key="p" class="mc-paso" :class="{ 'is-on': etapaIdx(sel.estado) >= i, 'is-act': etapaIdx(sel.estado) === i }">
              <span><i v-if="etapaIdx(sel.estado) > i || sel.estado === 'Completado'" class="fas fa-check"></i><template v-else>{{ i + 1 }}</template></span>
              <small>{{ p }}</small>
            </div>
          </div>
          <div class="mc-ficha">
            <div><span>Muestreo</span><b>{{ fmt(sel.fecha) }}</b></div>
            <div><span>Parcela</span><b>{{ sel.parcela || 'compuesta' }}</b></div>
            <div><span>Tratamiento</span><b>{{ sel.tratamiento || '—' }}{{ sel.repeticion ? ' · R' + sel.repeticion : '' }}</b></div>
            <div><span>Solicitante</span><b>{{ sel.solicitante || '—' }}</b></div>
            <div><span>Dias en laboratorio</span><b>{{ diasLab(sel) }}</b></div>
            <div><span>Resultado</span><b>{{ sel.fecha_resultado ? fmt(sel.fecha_resultado) : 'pendiente' }}</b></div>
            <div v-for="d in datosDe(sel)" :key="d.clave" class="is-dato"><span>{{ d.etiqueta }}</span><b>{{ d.valor }}<em v-if="d.unidad"> {{ d.unidad }}</em></b></div>
            <div v-if="sel.obs" class="mc-ficha-full"><span>Observacion</span><b>{{ sel.obs }}</b></div>
          </div>

          <!-- Vinculo con el modulo Laboratorio -->
          <div v-if="sel.lab" class="mc-lab">
            <div class="mc-lab-cab"><i class="fas fa-flask"></i> <b>Modulo Laboratorio</b> <span class="mc-lab-estado" :class="{ 'is-ok': sel.estado === 'Completado' }">{{ sel.lab.estado }}</span></div>
            <div class="mc-lab-grid">
              <div><span>Codigo de laboratorio</span><b>{{ sel.lab.codigo || '—' }}</b></div>
              <div><span>Informe</span><b>{{ sel.lab.informe || 'sin informe aun' }}</b></div>
              <div v-if="sel.lab.firmado_en"><span>Firmado</span><b>{{ fmtFechaHora(sel.lab.firmado_en) }}<em v-if="sel.lab.firmado_por"> · {{ sel.lab.firmado_por }}</em></b></div>
              <div><span>Actualizado desde el Laboratorio</span><b>{{ fmtFechaHora(sel.lab.sincronizado_en) }}</b></div>
              <div class="mc-lab-full">
                <span>Vinculo</span>
                <b>{{ ({ manual: 'Confirmado a mano en SIGEC', api: 'Enviado por el Laboratorio (API)', escaneo: 'Escaneo de la etiqueta en el Laboratorio' })[sel.lab.vinculo] || 'Escaneo de la etiqueta en el Laboratorio' }}</b>
                <button v-if="sel.lab.vinculo === 'manual' && puedeActualizar" type="button" class="mc-link mc-desvinc" @click="desvincular(sel)"><i class="fas fa-unlink"></i> Quitar vinculo</button>
              </div>
            </div>
          </div>
          <p v-else class="mc-nota is-espera"><i class="fas fa-hourglass-half"></i> Aun no recibida en el modulo Laboratorio. Se vincula sola cuando el laboratorio escanea su etiqueta.</p>

          <template v-if="sel.resultados && sel.resultados.length">
            <h6 class="mc-sub"><i class="fas fa-flask"></i> Resultados{{ sel.lab && sel.lab.informe ? ' · informe ' + sel.lab.informe : '' }}</h6>
            <table class="mc-res-tabla">
              <tbody>
                <tr v-for="r in sel.resultados" :key="r.clave"><td>{{ r.nombre }}</td><td class="text-right"><b>{{ r.valor }}</b></td><td class="mc-muted">{{ r.unidad }}</td></tr>
              </tbody>
            </table>
          </template>
          <p v-else-if="sel.lab" class="mc-nota"><i class="fas fa-info-circle"></i> Los resultados aparecen aqui cuando el informe del lote se firma en el Laboratorio.</p>

          <template v-if="puedeActualizar && !sel.lab">
            <h6 class="mc-sub"><i class="fas fa-pen"></i> Actualizar seguimiento</h6>
            <div class="mc-estados">
              <button v-for="e in estados" :key="e" type="button" :class="['is-' + claseEstado(e), { 'is-on': form.estado === e }]" @click="form.estado = e">{{ e }}</button>
            </div>
            <div class="form-row">
              <div class="col-7 form-group">
                <label>Analista(s)</label>
                <input ref="analistas" class="form-control form-control-sm" v-model="form.analistas" maxlength="150">
              </div>
              <div class="col-5 form-group">
                <label>Fecha de resultado</label>
                <input type="date" class="form-control form-control-sm" v-model="form.fecha_resultado">
              </div>
            </div>
            <div class="form-group">
              <label>Resultado / comentario</label>
              <textarea class="form-control form-control-sm" rows="3" maxlength="2000" v-model="form.resultado_texto"></textarea>
            </div>
            <p class="mc-nota"><i class="fas fa-info-circle"></i> Solo para muestras que no pasan por el modulo Laboratorio. Las que el laboratorio recibe se actualizan solas.</p>
          </template>
          <template v-else-if="!sel.lab && (sel.resultado_texto || sel.analistas)">
            <h6 class="mc-sub">Seguimiento</h6>
            <p v-if="sel.analistas"><b>Analistas:</b> {{ sel.analistas }}</p>
            <p v-if="sel.resultado_texto">{{ sel.resultado_texto }}</p>
          </template>
        </div>
        <footer v-if="puedeActualizar && !sel.lab" class="mc-pf">
          <button type="button" class="btn btn-sm mc-btn-sec" @click="cerrar">Cerrar</button>
          <button type="button" class="btn btn-sm mc-btn" :disabled="guardando" @click="guardar"><i :class="guardando ? 'fas fa-spinner fa-spin' : 'fas fa-save'"></i> Guardar</button>
        </footer>
      </aside>
    </transition>

    <!-- ============ Bitacora de envios de la API del Laboratorio ============ -->
    <transition name="mc-fade"><div v-if="envios.abierto" class="mc-velo" @click="envios.abierto = false"></div></transition>
    <transition name="mc-slide">
      <aside v-if="envios.abierto" class="mc-panel is-ancho" role="dialog">
        <header class="mc-ph is-completado">
          <span class="mc-ph-ico"><i class="fas fa-inbox"></i></span>
          <div>
            <h5 class="is-texto">Envios del modulo Laboratorio</h5>
            <small>Ultimos 100 envios recibidos por la API (aceptados y rechazados)</small>
          </div>
          <button type="button" class="mc-x" @click="envios.abierto = false">×</button>
        </header>
        <div class="mc-pb">
          <div v-if="envios.cargando" class="mc-cargando"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>
          <div v-else-if="!envios.lista.length" class="mc-vacio is-mini"><i class="fas fa-inbox"></i><strong>Aun no se han recibido envios.</strong></div>
          <table v-else class="mc-vs-tabla mc-env">
            <thead><tr><th>Recibido</th><th>Informe</th><th>Estado</th><th class="text-right">Muestras</th><th>Resultado</th></tr></thead>
            <tbody>
              <tr v-for="e in envios.lista" :key="e.id">
                <td class="mc-nowrap">{{ fmtFechaHora(e.recibido_en) }}</td>
                <td><b>{{ e.informe || '—' }}</b><small v-if="e.version" class="mc-muted"> v{{ e.version }}</small></td>
                <td>{{ e.estado || '—' }}</td>
                <td class="text-right">{{ e.aplicadas }} / {{ e.muestras }}</td>
                <td>
                  <span class="mc-motivo" :class="{ 'is-revisar': e.respuesta_http !== 200 }">{{ e.respuesta_http === 200 ? 'aceptado' : 'HTTP ' + e.respuesta_http }}</span>
                  <small v-if="e.error" class="mc-muted"> {{ e.error }}</small>
                  <small v-for="(r, i) in e.rechazadas" :key="i" class="mc-env-rech">{{ r.id_sigec }}: {{ r.motivo }}</small>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </aside>
    </transition>

    <!-- ============ Vincular muestras recibidas sin escanear ============ -->
    <transition name="mc-fade"><div v-if="vinc.abierto" class="mc-velo" @click="vinc.abierto = false"></div></transition>
    <transition name="mc-slide">
      <aside v-if="vinc.abierto" class="mc-panel is-ancho" role="dialog">
        <header class="mc-ph is-proceso">
          <span class="mc-ph-ico"><i class="fas fa-link"></i></span>
          <div>
            <h5 class="is-texto">Vincular sin escaneo</h5>
            <small>Muestras que el Laboratorio recibio sin leer la etiqueta de SIGEC</small>
          </div>
          <button type="button" class="mc-x" @click="vinc.abierto = false">×</button>
        </header>
        <div class="mc-pb">
          <p class="mc-nota"><i class="fas fa-info-circle"></i> Se proponen segun el <b>"Nombre del ensayo"</b> que el Laboratorio escribio en Recepcion (codigo o lote del ensayo en SIGEC), el tipo de muestra, la fecha de muestreo (±7 dias) y la parcela. Revise cada pareja antes de confirmar; nada se modifica en el modulo Laboratorio.</p>
          <div v-if="vinc.cargando" class="mc-cargando"><i class="fas fa-spinner fa-spin"></i> Buscando en el modulo Laboratorio...</div>
          <div v-else-if="!vinc.solicitudes.length" class="mc-vacio is-mini">
            <i class="fas fa-check-circle"></i>
            <strong>No hay muestras pendientes de vincular.</strong>
            <span>Si el laboratorio recibio muestras de SIGEC sin escanear, pida que en Recepcion escriba en "Nombre del ensayo" el codigo del ensayo de SIGEC (por ejemplo <b>{{ ejemploEnsayo }}</b>).</span>
          </div>
          <article v-for="s in vinc.solicitudes" :key="s.id_solicitud" class="mc-vs">
            <header>
              <div>
                <b>Laboratorio: «{{ s.nombre_ensayo }}» · {{ s.tipo }} · muestreo {{ fmt(s.fecha_muestreo) }}</b>
                <small>Solicitud {{ s.id_solicitud }}{{ s.institucion ? ' · ' + s.institucion : '' }} → ensayo <b>{{ s.ensayo }}</b> en SIGEC</small>
              </div>
            </header>
            <table class="mc-vs-tabla">
              <thead><tr><th></th><th>Muestra del Laboratorio</th><th>Parcela (Lab.)</th><th>Muestra de SIGEC</th><th></th></tr></thead>
              <tbody>
                <tr v-for="p in s.pares" :key="p.lab_muestra_id" :class="{ 'is-off': !vinc.elegidas[p.lab_muestra_id] }">
                  <td><input type="checkbox" class="mc-check" v-model="vinc.incluir[p.lab_muestra_id]" :disabled="!vinc.elegidas[p.lab_muestra_id]"></td>
                  <td><b>{{ p.codigo_lab }}</b> <small class="mc-muted">#{{ p.numero }}</small></td>
                  <td>{{ p.parcela_lab || '—' }}</td>
                  <td>
                    <select v-model="vinc.elegidas[p.lab_muestra_id]" class="form-control form-control-sm" @change="vinc.incluir[p.lab_muestra_id] = !!vinc.elegidas[p.lab_muestra_id]">
                      <option :value="null">— sin vincular —</option>
                      <option v-for="c in s.candidatas" :key="c.id" :value="c.id">{{ c.id_muestra }} · {{ c.parcela || 'compuesta' }} · {{ fmt(c.fecha) }}</option>
                    </select>
                  </td>
                  <td><span v-if="p.motivo" class="mc-motivo" :class="{ 'is-revisar': p.motivo.includes('revisar') }">{{ p.motivo }}</span></td>
                </tr>
              </tbody>
            </table>
          </article>
        </div>
        <footer class="mc-pf">
          <span class="mc-muted mc-vs-n">{{ paresElegidos.length }} vinculo(s) por confirmar</span>
          <button type="button" class="btn btn-sm mc-btn-sec" @click="vinc.abierto = false">Cerrar</button>
          <button type="button" class="btn btn-sm mc-btn" :disabled="!paresElegidos.length || vinc.guardando" @click="confirmarVinculos">
            <i :class="vinc.guardando ? 'fas fa-spinner fa-spin' : 'fas fa-link'"></i> Vincular {{ paresElegidos.length || '' }}
          </button>
        </footer>
      </aside>
    </transition>
  </div>
</template>

<script>
const POR_GRUPO = 25;
const ETAPAS = [
  { id: 'recibida', label: 'Recibidas', icon: 'fas fa-inbox' },
  { id: 'proceso', label: 'En proceso', icon: 'fas fa-microscope' },
  { id: 'completado', label: 'Completadas', icon: 'fas fa-check-circle' },
];
const AGRUPACIONES = [
  { id: 'ensayo', label: 'Ensayo › muestreo' },
  { id: 'lote', label: 'Muestreo' },
  { id: 'ninguno', label: 'Sin agrupar' },
];

export default {
  name: 'MuestrasConsultaIndex',
  props: {
    muestras: { type: Array, default: () => [] },
    ensayos: { type: Array, default: () => [] },
    analitosPorTipo: { type: Object, default: () => ({}) },
    campos: { type: Array, default: () => [] },
    cicloSteps: { type: Array, default: () => [] },
    puedeActualizar: { type: Boolean, default: false },
    sincronizacion: { type: Object, default: null },
    ultimoEnvio: { type: Object, default: null },
    puedeVerEnvios: { type: Boolean, default: false },
  },
  data() {
    return {
      POR_GRUPO,
      items: this.muestras.slice(),
      etapas: ETAPAS,
      agrupaciones: AGRUPACIONES,
      estados: ['Recibida', 'Pendiente', 'En proceso', 'Completado'],
      // Enlaces del dashboard: ?buscar=texto &etapa=recibida|proceso|completado
      busqueda: new URLSearchParams(window.location.search).get('buscar') || '',
      filtroEtapa: ETAPAS.some((e) => e.id === new URLSearchParams(window.location.search).get('etapa')) ? new URLSearchParams(window.location.search).get('etapa') : '',
      filtroTipo: '',
      desde: '',
      hasta: '',
      agrupar: this.leer('agrupar', 'ensayo'),
      plegados: {},
      limites: {},
      seleccion: [],
      masivoAnalistas: '',
      masivoGuardando: false,
      sel: null,
      form: {},
      guardando: false,
      sync: this.sincronizacion,
      sincronizando: false,
      envios: { abierto: false, cargando: false, lista: [] },
      vinc: { abierto: false, cargando: false, guardando: false, solicitudes: [], elegidas: {}, incluir: {} },
      base: ((document.querySelector('meta[name="app-base"]') || {}).content || '').replace(/\/+$/, ''),
    };
  },
  computed: {
    ensMap() { return new Map(this.ensayos.map((e) => [e.id, e])); },
    vinculadas() { return this.items.filter((m) => m.lab).length; },
    paresElegidos() {
      return Object.keys(this.vinc.elegidas).filter((k) => this.vinc.elegidas[k] && this.vinc.incluir[k]).map((k) => ({ lab_muestra_id: Number(k), sigec_id: this.vinc.elegidas[k] }));
    },
    ejemploEnsayo() { return (this.ensayos[0] || {}).codigo || '01'; },
    conResultado() { return this.items.filter((m) => m.lab && m.estado === 'Completado').length; },
    ensayosConMuestras() { return new Set(this.items.map((m) => m.ensayo_id)).size; },
    tiposPresentes() { return [...new Set(this.items.map((m) => m.tipo))].sort(); },
    porEtapa() {
      const c = { recibida: 0, proceso: 0, completado: 0 };
      this.items.forEach((m) => { c[this.etapa(m.estado)] += 1; });
      return c;
    },
    hayFiltros() { return !!(this.busqueda || this.filtroEtapa || this.filtroTipo || this.desde || this.hasta); },
    filtradas() {
      const q = this.sinTildes(this.busqueda.trim().toLowerCase());
      return this.items.filter((m) => (!this.filtroEtapa || this.etapa(m.estado) === this.filtroEtapa)
        && (!this.filtroTipo || m.tipo === this.filtroTipo)
        && (!this.desde || m.fecha >= this.desde)
        && (!this.hasta || m.fecha <= this.hasta)
        && (!q || this.sinTildes(`${m.id_muestra} ${this.ensayoCodigo(m.ensayo_id)} ${m.proyecto || ''} ${m.parcela || ''} ${m.tratamiento || ''} ${m.tipo} ${m.analistas || ''} ${Object.values(m.datos || {}).join(' ')}`.toLowerCase()).includes(q)));
    },
    grupos() {
      const mapa = new Map();
      this.filtradas.forEach((m) => {
        let clave;
        if (this.agrupar === 'ensayo') clave = `e${m.ensayo_id}`;
        else if (this.agrupar === 'lote') clave = `l${m.ensayo_id}|${m.tipo}|${m.fecha}`;
        else clave = 'todas';
        if (!mapa.has(clave)) mapa.set(clave, []);
        mapa.get(clave).push(m);
      });
      return [...mapa.entries()].map(([clave, items]) => {
        const m0 = items[0];
        const e = this.ensMap.get(m0.ensayo_id) || {};
        const etapas = { recibida: 0, proceso: 0, completado: 0 };
        items.forEach((m) => { etapas[this.etapa(m.estado)] += 1; });
        const fechas = items.map((m) => m.fecha).sort();
        let titulo;
        let subtitulo;
        if (this.agrupar === 'ensayo') {
          titulo = `Ensayo ${e.codigo || '—'}`;
          const nm = new Set(items.map((m) => `${m.fecha}|${m.tipo}`)).size;
          subtitulo = `${[e.finca, e.lote].filter(Boolean).join(' / ') || 'sin finca'} · ${nm} muestreo(s) del ${this.fmt(fechas[0])} al ${this.fmt(fechas[fechas.length - 1])}`;
        } else if (this.agrupar === 'lote') {
          titulo = `${e.codigo || '—'} · ${m0.tipo} · ${this.fmt(m0.fecha)}`;
          subtitulo = `${items[0].id_muestra} a ${items[items.length - 1].id_muestra}${m0.solicitante ? ' · ' + m0.solicitante : ''}`;
        } else {
          titulo = 'Todas las muestras';
          subtitulo = `${this.ensayosConMuestras} ensayo(s)`;
        }
        return {
          clave, titulo, subtitulo, items: items.slice().sort((a, b) => b.fecha.localeCompare(a.fecha) || a.id_muestra.localeCompare(b.id_muestra, 'es', { numeric: true })),
          tipos: [...new Set(items.map((m) => m.tipo))], etapas, ultima: fechas[fechas.length - 1],
        };
      }).sort((a, b) => b.ultima.localeCompare(a.ultima));
    },
  },
  watch: {
    agrupar(v) {
      try { localStorage.setItem('sigec.muestrasconsulta.agrupar', v); } catch (e) { /* sin almacenamiento */ }
      this.limites = {};
      this.plegarInicial();
    },
  },
  mounted() {
    window.addEventListener('keydown', this.onEsc);
    // Por defecto se abre solo el grupo mas reciente cuando hay muchos.
    this.plegarInicial();
    const id = new URLSearchParams(window.location.search).get('muestra');
    if (id) { const m = this.items.find((x) => x.id_muestra === id); if (m) this.abrir(m); }
  },
  beforeDestroy() { window.removeEventListener('keydown', this.onEsc); },
  methods: {
    leer(k, def) { try { return localStorage.getItem(`sigec.muestrasconsulta.${k}`) || def; } catch (e) { return def; } },
    onEsc(e) { if (e.key === 'Escape' && this.sel) this.cerrar(); },
    n(x) { return Number(x || 0).toLocaleString('es-GT'); },
    pct(x) { return this.items.length ? Math.round((x / this.items.length) * 100) : 0; },
    sinTildes(s) { return s.normalize('NFD').replace(/[̀-ͯ]/g, ''); },
    fmt(s) { if (!s) return ''; const [y, m, d] = String(s).slice(0, 10).split('-'); return `${d}/${m}/${y}`; },
    ensayoCodigo(id) { return (this.ensMap.get(id) || {}).codigo || '—'; },
    etapa(estado) { return estado === 'Completado' ? 'completado' : estado === 'En proceso' ? 'proceso' : 'recibida'; },
    etapaIdx(estado) { return { recibida: 0, proceso: 1, completado: 2 }[this.etapa(estado)]; },
    claseEstado(estado) { return estado === 'Pendiente' ? 'pendiente' : this.etapa(estado); },
    iconoTipo(t) {
      return { Suelo: 'fas fa-mountain', 'Tejido vegetal': 'fas fa-leaf', Jugos: 'fas fa-tint', Mieles: 'fas fa-flask', Agua: 'fas fa-water', Fertilizantes: 'fas fa-seedling' }[t] || 'fas fa-vial';
    },
    diasLab(m) {
      if (!m.fecha) return '—';
      const fin = m.estado === 'Completado' && m.fecha_resultado ? Date.parse(m.fecha_resultado) : Date.now();
      return Math.max(0, Math.floor((fin - Date.parse(m.fecha)) / 86400000));
    },
    limpiar() { this.busqueda = ''; this.filtroEtapa = ''; this.filtroTipo = ''; this.desde = ''; this.hasta = ''; },
    toast(title, text, icon = 'success') { this.$swal.fire({ toast: true, position: 'top-end', icon, title, text, timer: 2200, showConfirmButton: false }); },

    /* ---------- grupos ---------- */
    /** Con muchos grupos solo se abre el mas reciente. */
    plegarInicial() {
      const p = {};
      if (this.grupos.length > 3) this.grupos.slice(1).forEach((g) => { p[g.clave] = true; });
      // Dentro de cada ensayo solo queda abierto el muestreo mas reciente.
      if (this.agrupar === 'ensayo') this.grupos.forEach((g) => this.bloques(g).slice(1).forEach((b) => { p[b.clave] = true; }));
      this.plegados = p;
    },
    plegado(k) { return !!this.plegados[k]; },
    contarEtapas(items) {
      const c = { recibida: 0, proceso: 0, completado: 0 };
      items.forEach((m) => { c[this.etapa(m.estado)] += 1; });
      return c;
    },
    /**
     * Contenido de un grupo. Por ensayo: un bloque por muestreo (fecha + tipo),
     * del mas reciente al mas antiguo y numerados en orden cronologico; en las
     * otras agrupaciones, una sola tabla.
     */
    bloques(g) {
      if (this.agrupar !== 'ensayo') return [{ clave: g.clave, items: g.items, sub: false }];
      const mapa = new Map();
      g.items.forEach((m) => {
        const k = `${m.fecha}|${m.tipo}`;
        if (!mapa.has(k)) mapa.set(k, []);
        mapa.get(k).push(m);
      });
      const cron = [...mapa.keys()].sort();
      return [...mapa.entries()].map(([k, items]) => {
        const [fecha, tipo] = k.split('|');
        const orden = items.slice().sort((a, b) => a.id_muestra.localeCompare(b.id_muestra, 'es', { numeric: true }));
        return {
          clave: `${g.clave}|${k}`, sub: true, fecha, tipo, items: orden, numero: cron.indexOf(k) + 1,
          etapas: this.contarEtapas(items), parcelas: new Set(items.map((m) => m.parcela).filter(Boolean)).size,
          desde: orden[0].id_muestra, hasta: orden[orden.length - 1].id_muestra, solicitante: items[0].solicitante,
        };
      }).sort((a, b) => b.fecha.localeCompare(a.fecha) || a.tipo.localeCompare(b.tipo));
    },
    dia(f) { return String(f).slice(8, 10); },
    mesAnio(f) { const [y, m] = String(f).split('-'); return `${['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'][Number(m) - 1]} ${y}`; },
    togglePliegue(k) { this.$set(this.plegados, k, !this.plegados[k]); },
    plegarTodo(v) {
      this.grupos.forEach((g) => {
        this.$set(this.plegados, g.clave, v);
        if (this.agrupar === 'ensayo') this.bloques(g).forEach((b) => this.$set(this.plegados, b.clave, v));
      });
    },
    limite(k) { return this.limites[k] || POR_GRUPO; },
    verMas(k) { this.$set(this.limites, k, this.limite(k) + POR_GRUPO); },
    visibles(g) { return g.items.slice(0, this.limite(g.clave)); },

    /* ---------- seleccion masiva ---------- */
    todoSel(g) { return g.items.every((m) => this.seleccion.includes(m.id)); },
    algoSel(g) { return g.items.some((m) => this.seleccion.includes(m.id)); },
    selGrupo(g, marcar) {
      const ids = g.items.map((m) => m.id);
      this.seleccion = marcar ? [...new Set([...this.seleccion, ...ids])] : this.seleccion.filter((id) => !ids.includes(id));
    },
    reemplazar(lista) {
      lista.forEach((d) => { const i = this.items.findIndex((x) => x.id === d.id); if (i >= 0) this.$set(this.items, i, d); });
    },
    cambiarLote(estado) {
      const ids = this.seleccion.slice();
      this.$swal.fire({
        icon: 'question', title: `Cambiar ${ids.length} muestra(s) a "${estado}"`,
        text: estado === 'Completado' ? 'Se registrara hoy como fecha de resultado.' : '',
        showCancelButton: true, confirmButtonText: 'Cambiar', cancelButtonText: 'Cancelar', confirmButtonColor: '#73BC25',
      }).then((r) => {
        if (!r.isConfirmed) return;
        this.masivoGuardando = true;
        window.axios.post('/soporte/muestras/consulta/lote', { ids, estado, analistas: this.masivoAnalistas || null })
          .then(({ data }) => {
            this.reemplazar(data);
            const omitidas = ids.length - data.length;
            this.seleccion = [];
            this.toast(`${data.length} muestra(s) actualizadas`, omitidas ? `${omitidas} omitida(s): su avance lo registra el modulo Laboratorio` : estado, omitidas ? 'info' : 'success');
          })
          .catch(() => this.$swal.fire({ icon: 'error', title: 'No se pudo actualizar' }))
          .finally(() => { this.masivoGuardando = false; });
      });
    },

    /* ---------- detalle ---------- */
    abrirPorId() {
      const q = this.busqueda.trim().toLowerCase();
      const m = this.items.find((x) => x.id_muestra.toLowerCase() === q);
      if (m) { this.abrir(m); this.busqueda = ''; }
    },
    abrir(m, editar) {
      this.sel = m;
      this.form = { estado: m.estado, analistas: m.analistas || '', resultado_texto: m.resultado_texto || '', fecha_resultado: m.fecha_resultado || '' };
      if (editar) this.$nextTick(() => this.$refs.analistas && this.$refs.analistas.focus());
    },
    cerrar() { this.sel = null; },
    /** Datos de campo capturados al generar la muestra, con su etiqueta. */
    datosDe(m) {
      return Object.entries(m.datos || {}).map(([clave, valor]) => {
        const c = this.campos.find((x) => x.tipo_muestra === m.tipo && x.clave === clave) || {};
        return { clave, valor, etiqueta: c.etiqueta || clave, unidad: c.unidad || '' };
      });
    },
    fmtFechaHora(s) {
      if (!s) return '—';
      const [f, h] = String(s).split(/[ T]/);
      return `${this.fmt(f)}${h ? ' ' + h.slice(0, 5) : ''}`;
    },
    abrirEnvios() {
      this.envios = { abierto: true, cargando: true, lista: [] };
      window.axios.get('/soporte/muestras/consulta/envios-laboratorio')
        .then(({ data }) => { this.envios.lista = data; })
        .catch(() => { this.envios.abierto = false; this.$swal.fire({ icon: 'error', title: 'No se pudo cargar la bitacora' }); })
        .finally(() => { this.envios.cargando = false; });
    },
    /* ---------- vinculos sin escaneo ---------- */
    abrirVinculos() {
      this.vinc = { abierto: true, cargando: true, guardando: false, solicitudes: [], elegidas: {}, incluir: {} };
      window.axios.get('/soporte/muestras/consulta/vinculos-sugeridos')
        .then(({ data }) => {
          const elegidas = {};
          const incluir = {};
          data.solicitudes.forEach((s) => s.pares.forEach((p) => {
            elegidas[p.lab_muestra_id] = p.sigec_id;
            // Las propuestas por orden se dejan sin marcar: deben revisarse.
            incluir[p.lab_muestra_id] = !!p.sigec_id && !(p.motivo || '').includes('revisar');
          }));
          this.vinc.solicitudes = data.solicitudes;
          this.vinc.elegidas = elegidas;
          this.vinc.incluir = incluir;
        })
        .catch(() => { this.vinc.abierto = false; this.$swal.fire({ icon: 'error', title: 'No se pudo leer el modulo Laboratorio' }); })
        .finally(() => { this.vinc.cargando = false; });
    },
    confirmarVinculos() {
      const pares = this.paresElegidos;
      const dup = pares.map((p) => p.sigec_id).filter((id, i, a) => a.indexOf(id) !== i);
      if (dup.length) { this.$swal.fire({ icon: 'warning', title: 'Muestra repetida', text: 'Una muestra de SIGEC esta elegida para dos muestras del Laboratorio.' }); return; }
      this.vinc.guardando = true;
      window.axios.post('/soporte/muestras/consulta/vincular', { pares })
        .then(({ data }) => {
          this.reemplazar(data.muestras);
          this.sync = data.sincronizacion;
          this.vinc.abierto = false;
          this.toast(`${data.vinculadas} muestra(s) vinculadas`, 'Su avance y resultados ya se leen del Laboratorio.');
        })
        .catch((err) => this.$swal.fire({ icon: 'error', title: 'No se pudo vincular', text: (err.response && err.response.data && err.response.data.message) || '' }))
        .finally(() => { this.vinc.guardando = false; });
    },
    desvincular(m) {
      this.$swal.fire({
        icon: 'warning', title: `Quitar vinculo de ${m.id_muestra}`, text: 'Dejara de leer el avance y los resultados del Laboratorio para esta muestra.',
        showCancelButton: true, confirmButtonText: 'Quitar vinculo', confirmButtonColor: '#FF6B00',
      }).then((r) => {
        if (!r.isConfirmed) return;
        window.axios.post(`/soporte/muestras/consulta/${m.id}/desvincular`)
          .then(({ data }) => { this.reemplazar([data]); this.sel = data; this.toast('Vinculo quitado', m.id_muestra); })
          .catch(() => this.$swal.fire({ icon: 'error', title: 'No se pudo quitar el vinculo' }));
      });
    },
    /** Lee de nuevo el modulo Laboratorio y refresca las muestras vinculadas. */
    sincronizar() {
      this.sincronizando = true;
      window.axios.post('/soporte/muestras/consulta/sincronizar-laboratorio')
        .then(({ data }) => {
          this.sync = data.sincronizacion;
          this.reemplazar(data.muestras);
          if (this.sel) this.sel = this.items.find((x) => x.id === this.sel.id) || this.sel;
          this.toast('Datos del Laboratorio actualizados', `${data.sincronizacion.vinculadas} vinculada(s) · ${data.sincronizacion.con_resultado} con resultados`);
        })
        .catch((err) => {
          if (err.response && err.response.data && err.response.data.sincronizacion) this.sync = err.response.data.sincronizacion;
          this.$swal.fire({ icon: 'error', title: 'No se pudo leer el modulo Laboratorio', text: (this.sync && this.sync.error) || '' });
        })
        .finally(() => { this.sincronizando = false; });
    },
    guardar() {
      this.guardando = true;
      window.axios.put(`/soporte/muestras/consulta/${this.sel.id}`, { ...this.form, fecha_resultado: this.form.fecha_resultado || null })
        .then(({ data }) => { this.reemplazar([data]); this.sel = data; this.toast('Muestra actualizada', data.id_muestra); })
        .catch(() => this.$swal.fire({ icon: 'error', title: 'No se pudo guardar' }))
        .finally(() => { this.guardando = false; });
    },
  },
};
</script>

<style scoped>
/* Paleta Cengicaña */
.mc {
  --verde: #73BC25; --verde-2: #A3D300; --amarillo: #FFCC00; --naranja: #FF6B00; --gris: #CED2D5;
  --verde-osc: #1f5632; --tinta: #1e2a1a; --suave: #6b7568; --borde: #e3e7e1;
}
.mc-muted { color: var(--suave); }
.mc-nowrap { white-space: nowrap; }
.mc-txt-nar { color: #c95500; font-weight: 700; }

.mc-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 14px; }
.mc-kpi { display: flex; align-items: center; gap: 12px; padding: 12px 15px; border: 1px solid var(--borde); border-left: 5px solid var(--verde-osc); border-radius: 14px; background: #fff; text-align: left; transition: transform .15s, box-shadow .15s; }
.mc-kpi:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(31, 86, 50, .1); }
.mc-kpi > i { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 12px; background: #eef2ea; color: var(--verde-osc); font-size: 17px; }
.mc-kpi strong { display: block; color: var(--tinta); font-size: 22px; line-height: 1.1; }
.mc-kpi span { display: block; color: var(--verde-osc); font-size: 12.5px; font-weight: 700; }
.mc-kpi small { color: var(--suave); font-size: 11.5px; }
.mc-kpi.is-on { box-shadow: 0 0 0 2px var(--verde-osc) inset; }
.mc-kpi.is-recibida { border-left-color: var(--gris); }
.mc-kpi.is-recibida > i { background: color-mix(in srgb, var(--gris) 45%, #fff); color: #5b6267; }
.mc-kpi.is-recibida.is-on { box-shadow: 0 0 0 2px #9aa1a6 inset; }
.mc-kpi.is-proceso { border-left-color: var(--amarillo); }
.mc-kpi.is-proceso > i { background: color-mix(in srgb, var(--amarillo) 24%, #fff); color: #a07c00; }
.mc-kpi.is-proceso.is-on { box-shadow: 0 0 0 2px var(--amarillo) inset; }
.mc-kpi.is-completado { border-left-color: var(--verde); }
.mc-kpi.is-completado > i { background: color-mix(in srgb, var(--verde) 16%, #fff); color: var(--verde); }
.mc-kpi.is-completado.is-on { box-shadow: 0 0 0 2px var(--verde) inset; }

.mc-conexion { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 12px; padding: 8px 14px; border: 1px solid var(--borde); border-left: 5px solid var(--verde); border-radius: 12px; background: #fff; color: var(--verde-osc); font-size: 12.5px; }
.mc-conexion > i { color: var(--verde); font-size: 15px; }
.mc-conexion small { color: var(--suave); }
.mc-conexion .mc-btn-sec { margin-left: auto; }
.mc-conexion.is-error { border-left-color: var(--naranja); background: #fff8f2; color: #8a3b00; }
.mc-conexion.is-error > i { color: var(--naranja); }
.mc-conexion-acc { display: flex; gap: 6px; margin-left: auto; }
.mc-conexion-acc .mc-btn-sec { margin-left: 0 !important; }
.mc-lab-full { grid-column: 1 / -1; display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 10px; }
.mc-lab-full span { width: 100%; }
.mc-desvinc { color: #b84d00 !important; }
.mc-panel.is-ancho { width: min(820px, 100vw); }
.mc-vacio.is-mini { padding: 24px 14px; }
.mc-vs { margin-top: 12px; border: 1px solid var(--borde); border-left: 4px solid var(--amarillo); border-radius: 10px; overflow: hidden; }
.mc-vs > header { padding: 8px 12px; background: #fffdf3; border-bottom: 1px solid var(--borde); }
.mc-vs > header b { display: block; color: var(--tinta); font-size: 13px; }
.mc-vs > header small { color: var(--suave); }
.mc-vs-tabla { width: 100%; font-size: 12.5px; }
.mc-vs-tabla th { padding: 6px 8px; background: #f7f9f5; color: var(--suave); font-size: 10.5px; text-transform: uppercase; }
.mc-vs-tabla td { padding: 5px 8px; border-top: 1px solid #edf0eb; vertical-align: middle; }
.mc-vs-tabla tr.is-off td { opacity: .6; }
.mc-vs-tabla select { min-width: 250px; border-radius: 7px; font-size: 12px; }
.mc-motivo { padding: 1px 8px; border-radius: 999px; background: color-mix(in srgb, var(--verde) 16%, #fff); color: #3f7a12; font-size: 11px; font-weight: 700; white-space: nowrap; }
.mc-motivo.is-revisar { background: #fff1e6; color: #b84d00; }
.mc-vs-n { margin-right: auto; font-size: 12.5px; }
.mc-ph h5.is-texto { font-family: inherit; }
.mc-env-rech { display: block; color: #b84d00; font-size: 11px; }
.mc-txt-ok { color: #3f7a12; }
.mc-lab-chip { padding: 1px 8px; border-radius: 999px; background: color-mix(in srgb, var(--amarillo) 26%, #fff); color: #7d6100; font-size: 11px; font-weight: 700; }
.mc-lab { margin-bottom: 12px; padding: 10px 12px; border: 1px solid color-mix(in srgb, var(--verde) 40%, #fff); border-radius: 10px; background: #f7fbf1; }
.mc-lab-cab { display: flex; align-items: center; gap: 6px; margin-bottom: 8px; color: var(--verde-osc); font-size: 13px; }
.mc-lab-cab i { color: var(--verde); }
.mc-lab-estado { margin-left: auto; padding: 1px 9px; border-radius: 999px; background: color-mix(in srgb, var(--amarillo) 28%, #fff); color: #7d6100; font-size: 11px; font-weight: 700; }
.mc-lab-estado.is-ok { background: color-mix(in srgb, var(--verde) 20%, #fff); color: #3f7a12; }
.mc-lab-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 12px; }
.mc-lab-grid span { display: block; color: var(--suave); font-size: 10.5px; font-weight: 700; text-transform: uppercase; }
.mc-lab-grid b { color: var(--tinta); font-size: 12.5px; }
.mc-lab-grid em { color: var(--suave); font-style: normal; font-weight: 500; }
.mc-nota.is-espera { margin-bottom: 12px; background: #fffbea; color: #6b5300; }
.mc-res-tabla { width: 100%; margin-bottom: 12px; font-size: 12.5px; }
.mc-res-tabla td { padding: 5px 8px; border-bottom: 1px solid #edf0eb; }
.mc-res-tabla tr:nth-child(odd) td { background: #fafbf9; }
.mc-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 12px; padding: 10px 14px; border: 1px solid var(--borde); border-radius: 14px; background: #fff; }
.mc-buscar { position: relative; display: flex; flex: 1 1 280px; align-items: center; max-width: 420px; }
.mc-buscar i { position: absolute; left: 12px; color: var(--suave); }
.mc-buscar input { width: 100%; height: 34px; padding: 0 30px 0 36px; border: 1px solid var(--borde); border-radius: 999px; background: #f7f9f5; font-size: 13px; outline: none; }
.mc-buscar input:focus { border-color: var(--verde); background: #fff; }
.mc-buscar button { position: absolute; right: 8px; border: 0; background: transparent; color: var(--suave); font-size: 18px; line-height: 1; }
.mc-sel { width: auto; min-width: 150px; height: 34px; border-radius: 9px; }
.mc-fechas { display: flex; align-items: center; gap: 4px; }
.mc-fechas .form-control { width: 135px; height: 34px; border-radius: 9px; }
.mc-fechas span, .mc-campo-agr > span { color: var(--suave); font-size: 12px; }
.mc-campo-agr { display: flex; align-items: center; gap: 6px; }
.mc-seg { display: inline-flex; padding: 3px; border: 1px solid var(--borde); border-radius: 9px; background: #f3f5f1; }
.mc-seg button { padding: 3px 10px; border: 0; border-radius: 6px; background: transparent; color: var(--suave); font-size: 12px; font-weight: 600; }
.mc-seg button.is-on { background: #fff; color: var(--verde-osc); box-shadow: 0 1px 3px rgba(30, 42, 26, .12); }
.mc-acc { display: flex; align-items: center; gap: 4px; margin-left: auto; }
.mc-link { border: 0; background: transparent; color: #3f7a12; font-size: 12px; font-weight: 700; }

.mc-masivo { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 12px; padding: 9px 14px; border: 1px solid color-mix(in srgb, var(--verde) 45%, #fff); border-radius: 12px; background: #f4faec; color: var(--verde-osc); font-size: 13px; }
.mc-masivo .form-control { width: 200px; border-radius: 8px; }
.mc-estado-btn { padding: 4px 12px; border: 1.5px solid; border-radius: 999px; background: #fff; font-size: 12px; font-weight: 700; }
.mc-estado-btn.is-recibida { border-color: #9aa1a6; color: #4f565b; }
.mc-estado-btn.is-proceso { border-color: var(--amarillo); color: #7d6100; }
.mc-estado-btn.is-completado { border-color: var(--verde); color: #3f7a12; }
.mc-estado-btn:hover { filter: brightness(.96); }

.mc-vacio { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 46px 16px; border: 1px dashed var(--gris); border-radius: 14px; background: #fff; color: var(--suave); text-align: center; }
.mc-vacio > i { color: var(--gris); font-size: 28px; }
.mc-vacio strong { color: var(--tinta); }
.mc-vacio a { color: #3f7a12; font-weight: 700; }

.mc-grupo { margin-bottom: 10px; border: 1px solid var(--borde); border-left: 4px solid var(--verde); border-radius: 12px; background: #fff; overflow: hidden; }
.mc-g-cab { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 10px 14px; cursor: pointer; user-select: none; }
.mc-g-cab:hover { background: #fafcf7; }
.mc-g-chev { color: var(--suave); font-size: 12px; transition: transform .15s; transform: rotate(90deg); }
.mc-grupo.is-plegado .mc-g-chev { transform: rotate(0deg); }
.mc-g-tit { flex: 1 1 260px; min-width: 0; }
.mc-g-tit b { display: block; color: var(--tinta); font-size: 14px; }
.mc-g-tit small { color: var(--suave); font-size: 11.5px; }
.mc-g-tipos { display: flex; flex-wrap: wrap; gap: 4px; }
.mc-tipo { display: inline-flex; align-items: center; gap: 4px; padding: 2px 9px; border-radius: 999px; background: #eef2ea; color: var(--verde-osc); font-size: 11px; font-weight: 700; white-space: nowrap; }
.mc-tipo i { color: var(--verde); font-size: 10px; }
.mc-tipo.is-mini { padding: 1px 7px; background: transparent; font-weight: 600; }
.mc-g-prog { display: flex; flex-direction: column; gap: 3px; width: 150px; }
.mc-g-prog small { color: var(--suave); font-size: 11px; }
.mc-g-prog b { color: var(--verde-osc); }
.mc-barra { display: flex; height: 8px; overflow: hidden; border-radius: 999px; background: #e8ede4; }
.mc-barra .is-completado { background: var(--verde); }
.mc-barra .is-proceso { background: var(--amarillo); }
.mc-g-n { min-width: 34px; padding: 2px 9px; border-radius: 999px; background: var(--verde-osc); color: #fff; font-size: 12px; font-weight: 800; text-align: center; }
.mc-check { width: 15px; height: 15px; accent-color: var(--verde); cursor: pointer; }

.mc-tabla-wrap { border-top: 1px solid var(--borde); overflow-x: auto; }
.mc-cuerpo { border-top: 1px solid var(--borde); }
.mc-cuerpo > .mc-muestreo:not(.is-sub) > .mc-tabla-wrap { border-top: 0; }
.mc-muestreo.is-sub { margin: 8px 10px 8px 34px; border: 1px solid var(--borde); border-left: 3px solid var(--verde-2); border-radius: 10px; overflow: hidden; }
.mc-muestreo.is-sub.is-plegado { background: #fafbf9; }
.mc-m-cab { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 7px 12px; cursor: pointer; user-select: none; }
.mc-m-cab:hover { background: #f7fbf1; }
.mc-muestreo.is-plegado .mc-g-chev { transform: rotate(0deg); }
.mc-m-fecha { display: flex; flex-direction: column; align-items: center; min-width: 46px; padding: 2px 6px; border-radius: 8px; background: color-mix(in srgb, var(--verde) 14%, #fff); line-height: 1.1; }
.mc-m-fecha b { color: var(--verde-osc); font-size: 17px; }
.mc-m-fecha small { color: #3f7a12; font-size: 10.5px; font-weight: 700; text-transform: uppercase; }
.mc-m-tit { flex: 1 1 280px; min-width: 0; }
.mc-m-tit > b { display: block; color: var(--tinta); font-size: 13px; }
.mc-m-tit small { color: var(--suave); font-size: 11px; }
.mc-m-tit .mc-tipo { padding: 0 4px; }
.mc-g-prog.is-mini { width: 130px; }
.mc-muestreo.is-sub .mc-tabla-wrap { border-top: 1px solid var(--borde); }
.mc-tabla { width: 100%; min-width: 860px; border-collapse: collapse; table-layout: fixed; font-size: 12.5px; }
.mc-tabla .mc-w-id { width: 230px; }
.mc-tabla .mc-w-tipo { width: 130px; }
.mc-tabla .mc-w-fecha { width: 96px; }
.mc-tabla .mc-w-ens { width: 110px; }
.mc-tabla .mc-w-parc { width: 130px; }
.mc-tabla .mc-w-ciclo { width: 150px; }
.mc-tabla .mc-w-dias { width: 56px; }
.mc-tabla .mc-w-acc { width: 48px; }
.mc-tabla td:last-child { overflow: visible; text-overflow: clip; padding: 0 8px 0 0; }
.mc-tabla th { padding: 7px 10px; background: #f7f9f5; color: var(--suave); font-size: 10.5px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; white-space: nowrap; }
.mc-tabla td { padding: 4px 10px; height: 34px; overflow: hidden; text-overflow: ellipsis; border-top: 1px solid #f0f2ee; vertical-align: middle; }
.mc-tabla tbody tr { cursor: pointer; transition: background .1s; }
.mc-tabla tbody tr:hover { background: #f7fbf1; }
.mc-tabla tbody tr.is-sel { background: #eef7e2; }
.mc-tabla .mc-c-chk { width: 40px; padding: 0 0 0 14px; text-overflow: clip; }
.mc-id { color: var(--tinta); font-family: 'Consolas', 'Courier New', monospace; font-size: 12px; font-weight: 700; white-space: nowrap; }
.mc-ciclo { display: inline-flex; align-items: center; gap: 3px; white-space: nowrap; }
.mc-ciclo i { width: 9px; height: 9px; border-radius: 50%; background: #e3e7e1; }
.mc-ciclo.is-recibida i.is-on, .mc-ciclo.is-pendiente i.is-on { background: #9aa1a6; }
.mc-ciclo.is-proceso i.is-on { background: var(--amarillo); }
.mc-ciclo.is-completado i.is-on { background: var(--verde); }
.mc-ciclo em { margin-left: 4px; color: var(--tinta); font-size: 11.5px; font-style: normal; font-weight: 600; }
.mc-ciclo.is-pendiente em { color: #b84d00; }
.mc-res { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mc-res-n { color: #3f7a12; font-weight: 700; }
.mc-icon { width: 28px; height: 28px; border: 1px solid transparent; border-radius: 8px; background: transparent; color: var(--suave); font-size: 12px; }
.mc-icon:hover { border-color: var(--borde); background: #f1f8e9; color: var(--verde-osc); }
.mc-mas { display: block; width: 100%; padding: 8px; border: 0; border-top: 1px dashed var(--borde); background: #fafbf9; color: #3f7a12; font-size: 12.5px; font-weight: 700; }
.mc-mas:hover { background: #f4faec; }

/* Panel */
.mc-velo { position: fixed; inset: 0; z-index: 1040; background: rgba(20, 30, 16, .35); }
.mc-panel { position: fixed; top: 0; right: 0; bottom: 0; z-index: 1050; display: flex; flex-direction: column; width: min(500px, 100vw); background: #fff; box-shadow: -10px 0 30px rgba(20, 30, 16, .18); }
.mc-ph { display: flex; align-items: center; gap: 12px; padding: 14px 18px; border-top: 5px solid #9aa1a6; border-bottom: 1px solid var(--borde); }
.mc-ph.is-proceso { border-top-color: var(--amarillo); }
.mc-ph.is-completado { border-top-color: var(--verde); }
.mc-ph.is-pendiente { border-top-color: var(--naranja); }
.mc-ph-ico { display: grid; place-items: center; width: 42px; height: 42px; border-radius: 12px; background: color-mix(in srgb, var(--verde) 16%, #fff); color: var(--verde); font-size: 17px; }
.mc-ph h5 { margin: 0; color: var(--tinta); font-family: 'Consolas', 'Courier New', monospace; font-size: 16px; font-weight: 800; }
.mc-ph small { color: var(--suave); }
.mc-x { margin-left: auto; border: 0; background: transparent; color: var(--suave); font-size: 26px; line-height: 1; }
.mc-pb { flex: 1; overflow-y: auto; padding: 16px 18px; }
.mc-pb label { color: var(--verde-osc); font-size: 12px; font-weight: 700; }
.mc-pb .form-control:focus { border-color: var(--verde); box-shadow: 0 0 0 .15rem rgba(115, 188, 37, .2); }
.mc-pf { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 18px; border-top: 1px solid var(--borde); background: #fafbf8; }
.mc-tracker { display: flex; margin-bottom: 16px; }
.mc-paso { position: relative; flex: 1; text-align: center; }
.mc-paso::before { content: ''; position: absolute; top: 15px; left: -50%; width: 100%; height: 3px; background: #e3e7e1; z-index: 0; }
.mc-paso:first-child::before { display: none; }
.mc-paso.is-on::before { background: var(--verde); }
.mc-paso span { position: relative; z-index: 1; display: inline-grid; place-items: center; width: 32px; height: 32px; border-radius: 50%; background: #e3e7e1; color: var(--suave); font-weight: 800; }
.mc-paso.is-on span { background: var(--verde); color: #fff; }
.mc-paso.is-act span { box-shadow: 0 0 0 4px color-mix(in srgb, var(--verde) 25%, #fff); }
.mc-paso small { display: block; margin-top: 4px; color: var(--suave); font-size: 11.5px; font-weight: 600; }
.mc-paso.is-on small { color: var(--verde-osc); }
.mc-ficha { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 14px; }
.mc-ficha > div { padding: 7px 10px; border-radius: 9px; background: #f6f8f3; }
.mc-ficha span { display: block; color: var(--suave); font-size: 10.5px; font-weight: 700; text-transform: uppercase; }
.mc-ficha b { color: var(--tinta); font-size: 13px; }
.mc-ficha-full { grid-column: 1 / -1; }
.mc-ficha > div.is-dato { background: color-mix(in srgb, var(--verde-2) 12%, #fff); }
.mc-ficha em { color: var(--suave); font-size: 11px; font-style: normal; }
.mc-sub { margin: 14px 0 8px; color: var(--verde-osc); font-size: 13px; font-weight: 800; }
.mc-sub i { color: var(--verde); }
.mc-analitos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
.mc-analitos > div { padding: 6px 8px; border: 1px solid color-mix(in srgb, var(--amarillo) 50%, #fff); border-radius: 8px; background: color-mix(in srgb, var(--amarillo) 7%, #fff); }
.mc-analitos span { display: block; color: var(--suave); font-size: 11px; }
.mc-analitos b { color: var(--tinta); font-size: 14px; }
.mc-analitos em { margin-left: 3px; color: var(--suave); font-size: 10.5px; font-style: normal; }
.mc-estados { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
.mc-estados button { flex: 1; padding: 6px 8px; border: 1.5px solid var(--borde); border-radius: 9px; background: #fff; color: #44503f; font-size: 12px; font-weight: 700; }
.mc-estados button.is-on.is-recibida { border-color: #9aa1a6; background: color-mix(in srgb, var(--gris) 40%, #fff); }
.mc-estados button.is-on.is-pendiente { border-color: var(--naranja); background: #fff1e6; color: #b84d00; }
.mc-estados button.is-on.is-proceso { border-color: var(--amarillo); background: color-mix(in srgb, var(--amarillo) 22%, #fff); color: #7d6100; }
.mc-estados button.is-on.is-completado { border-color: var(--verde); background: color-mix(in srgb, var(--verde) 14%, #fff); color: #2f6b0f; }
.mc-nota { margin: 0; padding: 7px 10px; border-radius: 8px; background: #f6f8f3; color: #3d4838; font-size: 12px; }
.mc-nota i { color: var(--verde); }
.mc-nota a { color: #3f7a12; font-weight: 700; }
.mc-btn { border: 0; border-radius: 9px; background: var(--verde); color: #fff; font-weight: 700; }
.mc-btn:hover { background: #64a51f; color: #fff; }
.mc-btn-sec { border: 1px solid var(--borde); border-radius: 9px; background: #fff; color: var(--verde-osc); font-weight: 600; }

.mc-fade-enter-active, .mc-fade-leave-active { transition: opacity .2s; }
.mc-fade-enter, .mc-fade-leave-to { opacity: 0; }
.mc-slide-enter-active, .mc-slide-leave-active { transition: transform .25s ease; }
.mc-slide-enter, .mc-slide-leave-to { transform: translateX(100%); }

@media (max-width: 760px) {
  .mc-g-prog { width: 100%; }
  .mc-acc { margin-left: 0; }
  .mc-analitos { grid-template-columns: repeat(2, 1fr); }
}
</style>
