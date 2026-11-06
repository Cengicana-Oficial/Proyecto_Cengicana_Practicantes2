<template>
  <div class="ens-page">
    <!-- Resumen: cada indicador filtra la lista -->
    <div class="ens-stats">
      <button
        v-for="s in resumen" :key="s.id" type="button"
        class="ens-stat" :class="{ 'is-active': filtroRapido === s.id }"
        :style="{ '--acento': s.color }" :title="filtroRapido === s.id ? 'Quitar filtro' : 'Filtrar: ' + s.label.toLowerCase()"
        @click="filtroRapido = filtroRapido === s.id ? '' : s.id"
      >
        <div class="ens-stat-icon"><i :class="s.icon"></i></div>
        <div>
          <div class="ens-stat-value">{{ s.valor }}</div>
          <div class="ens-stat-label">{{ s.label }}</div>
          <div class="ens-stat-sub">{{ s.sub }}</div>
        </div>
      </button>
    </div>

    <!-- Barra -->
    <div class="ens-toolbar">
      <div class="ens-search">
        <i class="fas fa-search"></i>
        <input type="search" class="form-control" placeholder="Buscar codigo, nombre, finca, variedad o responsable..." v-model="busqueda">
      </div>
      <select v-if="programas.length" class="form-control ens-filter" v-model="filtroPrograma" aria-label="Filtrar por programa">
        <option :value="null">Todos los programas</option>
        <option v-for="pg in programas" :key="pg.id" :value="pg.id">{{ pg.sigla }} · {{ pg.nombre }}</option>
      </select>
      <select class="form-control ens-filter" v-model="filtroProyecto" aria-label="Filtrar por proyecto">
        <option :value="null">{{ filtroPrograma ? 'Todos los proyectos del programa' : 'Todos los proyectos' }}</option>
        <option v-for="p in proyectosDelPrograma" :key="p.id" :value="p.id">{{ p.codigo }} - {{ p.nombre }}</option>
      </select>
      <select class="form-control ens-filter" v-model="filtroZafra" aria-label="Filtrar por zafra" :title="vista === 'anios' ? 'En esta vista la zafra se resalta en lugar de filtrar' : 'Ensayos activos en la zafra'">
        <option value="">Todas las zafras</option>
        <option v-for="z in zafrasDisponibles" :key="z" :value="z">Zafra {{ z }}{{ z === zafraHoy ? ' (actual)' : '' }}</option>
      </select>
      <select class="form-control ens-filter" v-model="filtroTipo" aria-label="Filtrar por tipo de estudio">
        <option value="">Ensayos y pruebas</option>
        <option value="ensayo">Ensayos experimentales</option>
        <option value="prueba">Pruebas técnicas</option>
      </select>
      <select class="form-control ens-filter" v-model="filtroEstado" aria-label="Filtrar por estado">
        <option value="">Todos los estados</option>
        <option v-for="e in estados" :key="e" :value="e">{{ e }}</option>
      </select>
      <div class="ens-vistas" role="group" aria-label="Vista">
        <button v-for="v in VISTAS" :key="v.id" type="button" :class="{ 'is-active': vista === v.id }" :title="v.titulo" @click="cambiarVista(v.id)">
          <i :class="v.icon"></i><span>{{ v.label }}</span>
        </button>
      </div>
      <button type="button" class="btn ens-btn-sec" :disabled="!filtrados.length" title="Exportar el listado filtrado (CSV)" aria-label="Exportar" @click="exportar"><i class="fas fa-file-csv"></i></button>
      <button v-if="permisos.crear" class="btn btn-success ens-nuevo" @click="abrirCrear">
        <i class="fas fa-plus"></i> Nuevo ensayo o prueba
      </button>
    </div>

    <!-- Tabla -->
    <div v-if="vista === 'lista'" class="ens-table-card">
      <div class="ens-table-wrap">
        <table class="table ens-table mb-0">
          <thead>
            <tr>
              <th v-for="c in columnas" :key="c.id" :class="[c.clase, { 'is-sort': c.orden }]" :title="c.orden ? 'Ordenar' : ''" @click="c.orden && ordenarPor(c.id)">
                {{ c.label }}
                <i v-if="c.orden" class="fas" :class="orden.campo === c.id ? (orden.asc ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort ens-sort-off'"></i>
              </th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="e in pagina" :key="e.id" class="ens-row" @dblclick="ver(e)">
              <td class="ens-col-ens">
                <a href="#" class="ens-codigo" @click.prevent="ver(e)">{{ e.codigo }}</a>
                <span v-if="e.tipo_estudio === 'prueba'" class="ens-prueba-tag" title="Prueba técnica: sin diseño experimental ni cosecha"><i class="fas fa-vial"></i> Prueba</span>
                <span v-if="situacion(e)" class="ens-zafra-tag" :style="estiloSituacion(e)" :title="`Situacion en la zafra ${filtroZafra}`">{{ SITUACIONES[situacion(e)].texto }}</span>
                <i v-if="e.lat && e.lng" class="fas fa-map-marker-alt ens-gps" title="Con coordenadas GPS"></i>
                <small v-if="e.nombre" class="ens-nombre" :title="e.nombre">{{ e.nombre }}</small>
                <small class="ens-sub ens-proy-sub" :title="e.proyecto && e.proyecto.nombre"><i class="fas fa-folder-open"></i> {{ e.proyecto ? e.proyecto.nombre : 'Sin proyecto' }}</small>
              </td>
              <td>
                {{ [e.finca, e.lote].filter(Boolean).join(' / ') || '—' }}
                <small v-if="e.ingenio || e.estrato || e.zona" class="ens-sub">{{ [e.ingenio && e.ingenio.nombre, e.estrato && 'Estrato ' + e.estrato.toLowerCase(), e.zona && 'zona ' + e.zona.toLowerCase()].filter(Boolean).join(' · ') }}</small>
              </td>
              <td v-if="e.tipo_estudio === 'prueba'">
                {{ e.subtipo || 'Prueba técnica' }}
                <small class="ens-sub">{{ [e.lugar, e.materiales].filter(Boolean).join(' · ') || 'sin diseño experimental' }}</small>
              </td>
              <td v-else>
                {{ e.variedad || '—' }}
                <small class="ens-sub" :title="`${e.tratamientos_count || 0} tratamientos · ${e.parcelas_count || 0} parcelas`">{{ [e.diseno, `${e.tratamientos_count || 0} trat. · ${e.parcelas_count || 0} parc.`].filter(Boolean).join(' · ') }}</small>
              </td>
              <td v-if="e.tipo_estudio === 'prueba'" class="ens-ciclo">
                <template v-if="e.fecha_inicio">
                  <b>{{ fmt(e.fecha_inicio) }}</b><small v-if="e.fecha_fin"> → {{ fmt(e.fecha_fin) }}</small>
                  <small class="ens-sub">{{ textoPrueba(e) }}</small>
                </template>
                <span v-else class="ens-muted">sin fecha de inicio</span>
              </td>
              <td v-else class="ens-ciclo">
                <template v-if="edad(e)">
                  <b>{{ edad(e).texto }}</b><small v-if="e.ciclo"> · {{ e.ciclo }}</small>
                  <div v-if="avanceCiclo(e) !== null" class="ens-ciclo-barra" :title="`${avanceCiclo(e)} % del ciclo hasta la cosecha prevista`"><i :style="{ width: avanceCiclo(e) + '%' }"></i></div>
                  <small class="ens-sub" :class="{ 'is-alerta': cosechaProxima(e) }">{{ textoCosecha(e) || edad(e).detalle }}</small>
                </template>
                <span v-else class="ens-muted" title="Registre la fecha de siembra o corte">{{ e.ciclo || 'sin fecha de siembra' }}</span>
              </td>
              <td class="ens-muted" :title="nombresResponsables(e).join(', ')">
                <template v-if="nombresResponsables(e).length">{{ nombresResponsables(e)[0] }}<span v-if="nombresResponsables(e).length > 1" class="ens-resp-mas"> +{{ nombresResponsables(e).length - 1 }}</span></template>
                <template v-else>—</template>
              </td>
              <td class="ens-nowrap">
                <span class="ens-av" :title="e.ultima_evaluacion ? 'Ultima evaluacion ' + fmt(e.ultima_evaluacion) : 'Sin evaluaciones'"><i class="fas fa-clipboard-check"></i> {{ e.evaluaciones_count || 0 }}</span>
                <span class="ens-av" :title="`${e.muestras_completadas_count || 0} de ${e.muestras_count || 0} muestras con resultado`"><i class="fas fa-vial"></i> {{ e.muestras_completadas_count || 0 }}/{{ e.muestras_count || 0 }}</span>
                <small class="ens-sub" :class="{ 'is-alerta': sinActividad(e) }" :title="sinActividad(e) ? 'Mas de 30 dias sin evaluaciones ni muestreos' : ''">{{ actividad(e) ? 'ult. ' + fmt(actividad(e)) : 'sin actividad' }}</small>
              </td>
              <td><span class="ens-estado" :class="claseEstado(e.estado)">{{ e.tipo_estudio === 'prueba' && e.estado === 'En campo' ? 'En curso' : e.estado }}</span></td>
              <td class="ens-actions">
                <button type="button" class="ens-icon" title="Ver detalle" @click="ver(e)"><i class="fas fa-eye"></i></button>
                <button v-if="permisos.editar" type="button" class="ens-icon" title="Editar" @click="abrirEditar(e)"><i class="fas fa-pen"></i></button>
                <button v-if="permisos.eliminar" type="button" class="ens-icon ens-icon-danger" title="Eliminar" @click="eliminar(e)"><i class="fas fa-trash"></i></button>
              </td>
            </tr>
            <tr v-if="!filtrados.length">
              <td colspan="8" class="ens-empty">
                <icono-cana :tamano="40" apagado></icono-cana>
                <div>{{ items.length ? 'No se encontraron ensayos con esos filtros.' : 'Aun no hay ensayos registrados.' }}</div>
                <button v-if="!items.length && permisos.crear" class="btn btn-success btn-sm mt-2" @click="abrirCrear">
                  <i class="fas fa-plus"></i> Crear el primer ensayo
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="ens-pagination" v-if="filtrados.length">
        <span>
          {{ (paginaActual - 1) * porPagina + 1 }}–{{ Math.min(paginaActual * porPagina, filtrados.length) }}
          de {{ filtrados.length }} ensayo{{ filtrados.length !== 1 ? 's' : '' }}
        </span>
        <div class="ens-pages">
          <select class="form-control form-control-sm" v-model.number="porPagina" aria-label="Filas por pagina">
            <option :value="10">10 / pag.</option>
            <option :value="25">25 / pag.</option>
            <option :value="50">50 / pag.</option>
          </select>
          <button type="button" :disabled="paginaActual === 1" @click="paginaActual--" aria-label="Anterior"><i class="fas fa-chevron-left"></i></button>
          <button
            v-for="n in totalPaginas" :key="n" type="button"
            :class="{ 'is-active': n === paginaActual }" @click="paginaActual = n"
          >{{ n }}</button>
          <button type="button" :disabled="paginaActual === totalPaginas" @click="paginaActual++" aria-label="Siguiente"><i class="fas fa-chevron-right"></i></button>
        </div>
      </div>
    </div>

    <ensayos-anio-con-anio v-if="vista === 'anios'" :ensayos="filtrados" :zafra-sel.sync="filtroZafra" @ver="ver"></ensayos-anio-con-anio>
    <ensayos-ciclo v-if="vista === 'ciclo'" :ensayos="filtrados" :estados="ESTADOS_ENSAYO" @ver="ver"></ensayos-ciclo>
    <bitacora-ensayos v-if="vista === 'bitacora'" :ensayos="filtrados" :total-ensayos="items.length" :tipos="tiposBitacora" :permisos="permisos" @ver="(id) => ver({ id })"></bitacora-ensayos>
    <ensayos-mapa v-if="vista === 'mapa'" :ensayos="filtrados" :estados="ESTADOS_ENSAYO" :puede-editar="!!permisos.editar" @ver="ver" @editar="abrirEditar"></ensayos-mapa>

    <!-- Modal crear / editar -->
    <div class="modal fade" ref="modal" tabindex="-1" role="dialog">
      <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
          <form @submit.prevent="guardar" novalidate class="ens-form">
            <div class="modal-header">
              <h5 class="modal-title"><icono-cana :tamano="24"></icono-cana> {{ editando ? 'Editar' : 'Nuevo' }} {{ esPruebaForm ? (editando ? 'prueba técnica' : 'registro: prueba técnica') : (editando ? 'ensayo' : 'registro: ensayo experimental') }}</h5>
              <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
            </div>
            <div class="modal-body">
              <!-- Tipo de estudio: ensayo con diseno experimental o prueba tecnica breve -->
              <div class="ens-tipo" role="radiogroup" aria-label="Tipo de estudio">
                <button type="button" role="radio" :aria-checked="!esPruebaForm" :class="{ 'is-active': !esPruebaForm }" @click="form.tipo_estudio = 'ensayo'">
                  <i class="fas fa-th"></i><b>Ensayo experimental</b>
                  <small>Diseño, tratamientos y parcelas; cultivo, siembra o corte y cosecha.</small>
                </button>
                <button type="button" role="radio" :aria-checked="esPruebaForm" :class="{ 'is-active': esPruebaForm }" @click="form.tipo_estudio = 'prueba'">
                  <i class="fas fa-vial"></i><b>Prueba técnica</b>
                  <small>Calibraciones, pruebas de laboratorio o de productos: sin diseño ni cosecha, con su tabla de datos.</small>
                </button>
              </div>
              <div class="form-row">
                <div class="col-md-5 form-group">
                  <label>Codigo {{ esPruebaForm ? 'de la prueba' : 'del ensayo' }} <small class="text-muted">(automatico)</small></label>
                  <div class="ens-codigo-auto" :class="{ 'is-cambia': codigo.cambia, 'is-bloqueo': codigo.bloqueo }">
                    <i class="fas fa-hashtag"></i><b>{{ codigo.valor || 'Elija el proyecto' }}</b>
                  </div>
                  <small class="form-text text-muted" v-if="!codigo.cambia">Codigo del proyecto + {{ esPruebaForm ? 'P y número de prueba' : 'E y número de ensayo' }}</small>
                  <small class="form-text ens-aviso" v-else-if="codigo.bloqueo"><i class="fas fa-lock"></i> {{ codigo.bloqueo }}</small>
                  <small class="form-text ens-aviso" v-else><i class="fas fa-sync-alt"></i> Antes {{ editando.codigo }}: tambien cambian los ID de sus muestras.</small>
                </div>
                <div class="col-md-7 form-group">
                  <label>Proyecto <span class="ens-req">*</span></label>
                  <select class="form-control" :class="{ 'is-invalid': errors.proyecto_id }" v-model="form.proyecto_id">
                    <option :value="null">Seleccione...</option>
                    <option v-for="p in proyectos" :key="p.id" :value="p.id">{{ p.codigo }} — {{ p.nombre }}</option>
                  </select>
                  <span class="invalid-feedback" v-if="errors.proyecto_id">{{ errors.proyecto_id[0] }}</span>
                </div>
              </div>
              <div class="form-group">
                <label>Nombre {{ esPruebaForm ? 'de la prueba' : 'del ensayo' }}</label>
                <input type="text" maxlength="200" class="form-control" :class="{ 'is-invalid': errors.nombre }" v-model="form.nombre" :placeholder="esPruebaForm ? 'Ej. Calibración de sensores de humedad TDR en suelo franco' : 'Ej. Evaluacion de densidades de siembra en CG02-163'">
                <span class="invalid-feedback" v-if="errors.nombre">{{ errors.nombre[0] }}</span>
              </div>
              <div class="form-row">
                <div class="col-md-4 form-group">
                  <label>Ingenio <a v-if="catalogosUrl" :href="catalogosUrl + '?tab=ingenios'" class="ens-cat-link" title="Catalogo de ingenios (viene del portal)">administrar</a></label>
                  <select class="form-control" v-model="form.ingenio_id">
                    <option :value="null">Sin asignar</option>
                    <option v-for="i in ingenios" :key="i.id" :value="i.id">{{ i.nombre }}</option>
                  </select>
                </div>
                <div class="col-md-4 form-group">
                  <label>Finca</label>
                  <input type="text" maxlength="150" class="form-control" v-model="form.finca">
                </div>
                <div class="col-md-4 form-group">
                  <label>Lote</label>
                  <input type="text" maxlength="150" class="form-control" v-model="form.lote">
                </div>
              </div>
              <!-- Prueba tecnica: tipo, lugar, periodo y materiales -->
              <template v-if="esPruebaForm">
                <div class="form-row">
                  <div class="col-md-6 form-group">
                    <label>Tipo de prueba</label>
                    <select class="form-control" v-model="form.subtipo">
                      <option :value="null">Sin definir</option>
                      <option v-for="s in subtiposPrueba" :key="s" :value="s">{{ s }}</option>
                    </select>
                  </div>
                  <div class="col-md-6 form-group">
                    <label>Lugar</label>
                    <select class="form-control" v-model="form.lugar">
                      <option :value="null">Sin definir</option>
                      <option v-for="l in lugaresPrueba" :key="l" :value="l">{{ l }}</option>
                    </select>
                  </div>
                </div>
                <div class="form-row">
                  <div class="col-md-6 form-group">
                    <label>Fecha de inicio</label>
                    <input type="date" class="form-control" :class="{ 'is-invalid': errors.fecha_inicio }" v-model="form.fecha_inicio">
                    <span class="invalid-feedback" v-if="errors.fecha_inicio">{{ errors.fecha_inicio[0] }}</span>
                  </div>
                  <div class="col-md-6 form-group">
                    <label>Fecha de fin <small class="text-muted">(prevista o real)</small></label>
                    <input type="date" class="form-control" :class="{ 'is-invalid': errors.fecha_fin }" v-model="form.fecha_fin">
                    <span class="invalid-feedback" v-if="errors.fecha_fin">{{ errors.fecha_fin[0] }}</span>
                  </div>
                </div>
                <div class="form-group">
                  <label>Equipos y materiales</label>
                  <textarea rows="2" maxlength="2000" class="form-control" v-model="form.materiales" placeholder="Ej. 4 sensores TDR modelo X, estufa a 105 °C; hidrogel lote 2026-03, dosis 2-6 g/kg"></textarea>
                </div>
              </template>

              <div v-if="!esPruebaForm" class="form-row">
                <div class="col-md-4 form-group">
                  <label>Diseno experimental <a v-if="catalogosUrl" :href="catalogosUrl + '?tab=disenos'" class="ens-cat-link" title="Agregar o editar disenos experimentales">administrar</a></label>
                  <select class="form-control" v-model="form.diseno">
                    <option :value="null">Sin definir</option>
                    <option v-for="d in opcionesDiseno" :key="d" :value="d">{{ d }}</option>
                  </select>
                </div>
                <div class="col-md-4 form-group">
                  <label>Cultivo</label>
                  <input type="text" maxlength="150" class="form-control" v-model="form.cultivo">
                </div>
                <div class="col-md-4 form-group">
                  <label>Variedad</label>
                  <input type="text" maxlength="150" class="form-control" v-model="form.variedad" placeholder="Ej. CG98-78">
                </div>
              </div>
              <div class="form-row">
                <div class="col-md-6 form-group">
                  <label>Responsables <a v-if="catalogosUrl" :href="catalogosUrl + '?tab=responsables'" class="ens-cat-link" title="Personas con el modulo Experimentos en el portal">administrar</a></label>
                  <!-- Varios responsables; el primero (★) es el principal. -->
                  <div v-if="form.responsables.length" class="ens-resp">
                    <span v-for="(id, i) in form.responsables" :key="id" class="ens-resp-chip" :class="{ 'is-principal': i === 0 }">
                      <button type="button" class="ens-resp-star" :title="i === 0 ? 'Responsable principal' : 'Hacer responsable principal'" @click="principal(id)"><i :class="i === 0 ? 'fas fa-star' : 'far fa-star'"></i></button>
                      {{ nombreUsuario(id) }}
                      <button type="button" class="ens-resp-x" title="Quitar" @click="form.responsables.splice(i, 1)">×</button>
                    </span>
                  </div>
                  <select class="form-control" :value="''" @change="agregarResponsable($event)">
                    <option value="">{{ form.responsables.length ? '+ Agregar otro responsable' : 'Sin asignar · elija un responsable' }}</option>
                    <option v-for="u in responsables.filter((x) => !form.responsables.includes(x.id))" :key="u.id" :value="u.id">{{ u.name }}</option>
                  </select>
                </div>
                <div class="col-md-6 form-group">
                  <label>Estado <span class="ens-req">*</span></label>
                  <select class="form-control" v-model="form.estado">
                    <option v-for="e in estados" :key="e" :value="e">{{ esPruebaForm && e === 'En campo' ? 'En curso' : e }}</option>
                  </select>
                </div>
              </div>

              <div class="form-group">
                <label>Objetivo</label>
                <textarea rows="2" maxlength="2000" class="form-control" v-model="form.objetivo" :placeholder="esPruebaForm ? 'Ej. Obtener la ecuación de calibración del sensor frente a la humedad gravimétrica de laboratorio' : 'Que se busca evaluar o demostrar con el ensayo'"></textarea>
              </div>

              <template v-if="!esPruebaForm">
              <div class="ens-section">
                <i class="fas fa-seedling"></i> Ciclo del cultivo
                <small>la fecha de siembra o corte calcula la edad del cultivo en evaluaciones, NDVI y clima</small>
              </div>
              <div class="form-row">
                <div class="col-md-3 form-group">
                  <label>Ciclo</label>
                  <select class="form-control" v-model="form.ciclo">
                    <option :value="null">Sin definir</option>
                    <option v-for="c in ciclos" :key="c" :value="c">{{ c }}</option>
                  </select>
                </div>
                <div class="col-md-3 form-group">
                  <label>{{ form.ciclo && form.ciclo !== 'Plantia' ? 'Fecha del corte anterior' : 'Fecha de siembra' }}</label>
                  <input type="date" class="form-control" :class="{ 'is-invalid': errors.fecha_siembra }" v-model="form.fecha_siembra">
                  <span class="invalid-feedback" v-if="errors.fecha_siembra">{{ errors.fecha_siembra[0] }}</span>
                </div>
                <div class="col-md-3 form-group">
                  <label>Cosecha prevista</label>
                  <input type="date" class="form-control" :class="{ 'is-invalid': errors.fecha_cosecha_prevista }" v-model="form.fecha_cosecha_prevista">
                  <span class="invalid-feedback" v-if="errors.fecha_cosecha_prevista">{{ errors.fecha_cosecha_prevista[0] }}</span>
                </div>
                <div class="col-md-3 form-group">
                  <label>Cosecha real</label>
                  <input type="date" class="form-control" :class="{ 'is-invalid': errors.fecha_cosecha }" v-model="form.fecha_cosecha">
                  <span class="invalid-feedback" v-if="errors.fecha_cosecha">{{ errors.fecha_cosecha[0] }}</span>
                </div>
              </div>
              <p class="ens-hint" v-if="edadForm">
                <i class="fas fa-info-circle"></i> Edad del cultivo: <b>{{ edadForm.texto }}</b> ({{ edadForm.detalle }}).
              </p>
              </template>

              <div class="ens-section">
                <i class="fas fa-map-marker-alt"></i> {{ esPruebaForm ? 'Ubicación (opcional)' : 'Ubicacion y coordenadas GPS del lote' }}
                <small>{{ esPruebaForm ? 'si la prueba se hace en campo, el GPS habilita el mapa' : 'el GPS habilita el mapa y los datos climaticos; estrato y zona son los mismos de las estaciones' }}</small>
              </div>
              <div v-if="!esPruebaForm" class="form-row">
                <div class="col-md-3 form-group">
                  <label>Estrato</label>
                  <select class="form-control" v-model="form.estrato">
                    <option :value="null">Sin definir</option>
                    <option v-for="s in estratos" :key="s" :value="s">{{ s }}</option>
                  </select>
                </div>
                <div class="col-md-3 form-group">
                  <label>Zona</label>
                  <select class="form-control" v-model="form.zona">
                    <option :value="null">Sin definir</option>
                    <option v-for="z in zonas" :key="z" :value="z">{{ z }}</option>
                  </select>
                </div>
                <div class="col-md-3 form-group">
                  <label>Area total (ha)</label>
                  <input type="number" step="0.01" min="0" class="form-control" v-model="form.area_total">
                </div>
                <div class="col-md-3 form-group">
                  <label>Tipo de suelo</label>
                  <input type="text" maxlength="100" class="form-control" v-model="form.tipo_suelo" placeholder="Ej. Franco arcilloso">
                </div>
              </div>
              <div class="form-row">
                <div class="col-md-5 form-group">
                  <label>Latitud</label>
                  <input type="number" step="0.000001" class="form-control" :class="{ 'is-invalid': errors.lat }" v-model="form.lat" placeholder="Ej. 14.5892">
                  <span class="invalid-feedback" v-if="errors.lat">{{ errors.lat[0] }}</span>
                </div>
                <div class="col-md-5 form-group">
                  <label>Longitud</label>
                  <input type="number" step="0.000001" class="form-control" :class="{ 'is-invalid': errors.lng }" v-model="form.lng" placeholder="Ej. -91.5143">
                  <span class="invalid-feedback" v-if="errors.lng">{{ errors.lng[0] }}</span>
                </div>
                <div class="col-md-2 form-group d-flex align-items-end">
                  <button type="button" class="btn btn-secondary btn-block" title="Usar mi ubicacion actual" @click="usarUbicacion" :disabled="ubicando">
                    <i :class="ubicando ? 'fas fa-spinner fa-spin' : 'fas fa-crosshairs'"></i>
                  </button>
                </div>
              </div>

              <template v-if="!esPruebaForm">
              <div class="ens-section">
                <i class="fas fa-border-all"></i> Configuracion inicial del diseno
                <small>{{ editando ? 'se edita en la pestana "Estructura" del detalle' : 'crea T1..Tn; editable luego en "Estructura"' }}</small>
              </div>
              <div class="form-row">
                <div class="col-md-4 form-group">
                  <label>Numero de tratamientos</label>
                  <input type="number" min="0" max="50" class="form-control" v-model.number="form.num_tratamientos" :disabled="!!editando">
                </div>
                <div class="col-md-4 form-group">
                  <label>Numero de repeticiones</label>
                  <input type="number" min="0" max="20" class="form-control" v-model.number="form.num_repeticiones" :disabled="!!editando">
                </div>
                <div class="col-md-4 form-group">
                  <label>Area por parcela (m²)</label>
                  <input type="number" step="0.1" min="0" class="form-control" v-model="form.area_parcela" placeholder="50">
                </div>
              </div>
              <p class="ens-hint mb-0" v-if="!editando && form.num_tratamientos > 0 && form.num_repeticiones > 0">
                <i class="fas fa-info-circle"></i>
                Se crearan {{ form.num_tratamientos }} tratamientos; luego podra generar
                {{ form.num_tratamientos * form.num_repeticiones }} parcelas desde "Estructura".
              </p>
              </template>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-success" :disabled="guardando">
                <i :class="guardando ? 'fas fa-spinner fa-spin' : 'fas fa-save'"></i>
                {{ guardando ? 'Guardando...' : (editando ? 'Guardar cambios' : (esPruebaForm ? 'Crear prueba técnica' : 'Crear ensayo')) }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <ensayo-detalle
      ref="detalle"
      :fuentes-ndvi="fuentesNdvi"
      :carpetas="carpetas"
      :tipos-bitacora="tiposBitacora"
      :storage-url="storageUrl"
      :permisos="permisos"
      :evaluaciones-url="evaluacionesUrl"
      :muestras-url="muestrasUrl"
      :proyectos-url="proyectosUrl"
      :ciclos-catalogo="ciclos"
      :usuarios="responsables"
      :usuario-id="usuarioId ? Number(usuarioId) : null"
      @editar="abrirEditar"
      @actualizado="actualizarFila"
    ></ensayo-detalle>
  </div>
</template>

<script>
import EnsayoDetalle from './detalle.vue';
import EnsayosCiclo from './ciclo.vue';
import EnsayosMapa from './mapa-ensayos.vue';
import BitacoraEnsayos from './bitacora-ensayos.vue';
import EnsayosAnioConAnio from './anio-con-anio.vue';
import { zafraDe, zafraActual, zafrasEntre, periodoEnsayo, situacionEnZafra, SITUACIONES } from '../../../zafra';

const ESTADOS =['Planificado', 'En campo', 'Finalizado'];
const DIA = 86400000;
const VISTAS = [
  { id: 'lista', label: 'Lista', icon: 'fas fa-list', titulo: 'Tabla de ensayos' },
  { id: 'ciclo', label: 'Ciclo', icon: 'fas fa-stream', titulo: 'Cronograma de siembra a cosecha con evaluaciones y muestreos' },
  { id: 'anios', label: 'Año con año', icon: 'fas fa-calendar-alt', titulo: 'Proyectos y ensayos por zafra: nuevos, que continuan y que finalizan' },
  { id: 'mapa', label: 'Mapa', icon: 'fas fa-map-marked-alt', titulo: 'Ubicacion de los ensayos' },
  { id: 'bitacora', label: 'Bitacora', icon: 'fas fa-book-open', titulo: 'Actividades registradas en los ensayos filtrados' },
];

export default {
  name: 'EnsayosIndex',
  components: { EnsayoDetalle, EnsayosCiclo, EnsayosMapa, BitacoraEnsayos, EnsayosAnioConAnio },
  props: {
    ensayos: { type: Array, default: () => [] },
    proyectos: { type: Array, default: () => [] },
    programas: { type: Array, default: () => [] },
    proyectosUrl: { type: String, default: '' },
    ingenios: { type: Array, default: () => [] },
    responsables: { type: Array, default: () => [] },
    disenos: { type: Array, default: () => [] },
    ciclos: { type: Array, default: () => [] },
    subtiposPrueba: { type: Array, default: () => [] },
    lugaresPrueba: { type: Array, default: () => [] },
    estratos: { type: Array, default: () => [] },
    zonas: { type: Array, default: () => [] },
    fuentesNdvi: { type: Array, default: () => [] },
    carpetas: { type: Array, default: () => [] },
    tiposBitacora: { type: Array, default: () => [] },
    usuarioId: { type: [Number, String], default: null },
    storageUrl: { type: String, default: '' },
    permisos: { type: Object, default: () => ({}) },
    evaluacionesUrl: { type: String, default: null },
    muestrasUrl: { type: String, default: null },
    catalogosUrl: { type: String, default: null }, // Sistema > Catalogos (solo administradores)
  },
  data() {
    return {
      items: this.ensayos.slice(),
      estados: ESTADOS,
      VISTAS,
      SITUACIONES,
      zafraHoy: zafraActual(),
      filtroZafra: /^\d{4}-\d{4}$/.test(new URLSearchParams(window.location.search).get('zafra') || '') ? new URLSearchParams(window.location.search).get('zafra') : '',
      vista: this.leerVista(),
      filtroRapido: '',
      // Paleta validada (CVD) de estados de ensayo, igual que dashboard, programas y proyectos.
      ESTADOS_ENSAYO: [
        { id: 'Planificado', color: '#eda100', texto: '#1f2a12' },
        { id: 'En campo', color: '#5a9e1a', texto: '#ffffff' },
        { id: 'Finalizado', color: '#2a78d6', texto: '#ffffff' },
      ],
      busqueda: '',
      filtroTipo: ['ensayo', 'prueba'].includes(new URLSearchParams(window.location.search).get('tipo')) ? new URLSearchParams(window.location.search).get('tipo') : '',
      filtroEstado: ESTADOS.includes(new URLSearchParams(window.location.search).get('estado')) ? new URLSearchParams(window.location.search).get('estado') : '',
      filtroProyecto: Number(new URLSearchParams(window.location.search).get('proyecto')) || null,
      filtroPrograma: Number(new URLSearchParams(window.location.search).get('programa')) || null,
      paginaActual: 1,
      porPagina: 10,
      orden: { campo: 'codigo', asc: true },
      columnas: [
        { id: 'codigo', label: 'Ensayo', orden: true },
        { id: 'ubicacion', label: 'Finca / Lote', orden: true },
        { id: 'variedad', label: 'Variedad y diseño', orden: true },
        { id: 'edad', label: 'Ciclo del cultivo', orden: true },
        { id: 'responsable', label: 'Responsable', orden: true },
        { id: 'actividad', label: 'Avance', orden: true },
        { id: 'estado', label: 'Estado', orden: true },
      ],
      editando: null,
      form: this.formVacio(),
      errors: {},
      guardando: false,
      codigo: { valor: '', cambia: false, bloqueo: null },
      ubicando: false,
    };
  },
  computed: {
    zafrasDisponibles() {
      const inicios = this.items.map((e) => zafraDe(periodoEnsayo(e).inicio)).filter(Boolean).sort();
      return inicios.length ? zafrasEntre(inicios[0], [inicios[inicios.length - 1], this.zafraHoy].sort().pop()).reverse() : [this.zafraHoy];
    },
    proyectosDelPrograma() {
      return this.filtroPrograma ? this.proyectos.filter((p) => p.programa_id === this.filtroPrograma) : this.proyectos;
    },
    filtrados() {
      const q = this.normalizar(this.busqueda.trim());
      const lista = this.items.filter((e) => {
        const texto = this.normalizar([e.codigo, e.nombre, e.finca, e.lote, e.variedad, e.ciclo, e.subtipo, e.materiales, e.estrato, e.zona, this.nombresResponsables(e).join(' '), e.proyecto && e.proyecto.codigo, e.proyecto && e.proyecto.nombre].join(' '));
        const rapido = {
          '': () => true,
          campo: (x) => x.estado === 'En campo',
          cosecha: (x) => this.cosechaProxima(x, 60),
          quietos: (x) => this.sinActividad(x),
          incompletos: (x) => this.faltantes(x).length > 0,
        }[this.filtroRapido];
        return (!q || texto.includes(q)) && rapido(e)
          && (!this.filtroEstado || e.estado === this.filtroEstado)
          && (!this.filtroTipo || (e.tipo_estudio || 'ensayo') === this.filtroTipo)
          && (!this.filtroProyecto || e.proyecto_id === this.filtroProyecto)
          && (!this.filtroPrograma || (e.proyecto && e.proyecto.programa_id === this.filtroPrograma))
          // En "Año con año" la zafra solo se resalta: la vista necesita todas las zafras.
          && (!this.filtroZafra || this.vista === 'anios' || !!this.situacion(e));
      });
      const valor = {
        codigo: (e) => e.codigo,
        proyecto: (e) => (e.proyecto ? e.proyecto.codigo : ''),
        ubicacion: (e) => [e.finca, e.lote].filter(Boolean).join(' '),
        variedad: (e) => e.variedad || '',
        // Mayor edad primero = fecha de siembra mas antigua; sin fecha al final.
        edad: (e) => e.fecha_siembra || '9999',
        responsable: (e) => (e.responsable ? e.responsable.name : ''),
        actividad: (e) => this.actividad(e) || '',
        estado: (e) => ESTADOS.indexOf(e.estado),
      }[this.orden.campo];
      const dir = this.orden.asc ? 1 : -1;
      return lista.sort((a, b) => {
        const x = valor(a);
        const y = valor(b);
        const r = typeof x === 'number' ? x - y : String(x).localeCompare(String(y), 'es', { numeric: true });
        return r * dir || a.codigo.localeCompare(b.codigo, 'es', { numeric: true });
      });
    },
    edadForm() { return this.edad(this.form); },
    esPruebaForm() { return this.form.tipo_estudio === 'prueba'; },
    totalPaginas() {
      return Math.max(1, Math.ceil(this.filtrados.length / this.porPagina));
    },
    pagina() {
      const ini = (this.paginaActual - 1) * this.porPagina;
      return this.filtrados.slice(ini, ini + this.porPagina);
    },
    resumen() {
      const n = (estado) => this.items.filter((e) => e.estado === estado).length;
      const incompletos = this.items.filter((e) => this.faltantes(e).length);
      const quePasa = ['siembra', 'ubicacion', 'parcelas'].map((k) => [k, incompletos.filter((e) => this.faltantes(e).includes(k)).length])
        .filter(([, c]) => c).map(([k, c]) => `${c} sin ${k}`).join(' · ');
      return [
        { id: 'campo', label: 'En campo', valor: n('En campo'), sub: `${n('Planificado')} planificados · ${n('Finalizado')} finalizados`, icon: 'fas fa-tractor', color: '#73BC25' },
        { id: 'cosecha', label: 'Cosecha proxima', valor: this.items.filter((e) => this.cosechaProxima(e, 60)).length, sub: 'prevista en 60 dias o vencida', icon: 'fas fa-flag', color: '#FFCC00' },
        { id: 'quietos', label: 'Sin actividad reciente', valor: this.items.filter((e) => this.sinActividad(e)).length, sub: 'en campo sin evaluar ni muestrear en 30 dias', icon: 'fas fa-hourglass-half', color: '#FF6B00' },
        { id: 'incompletos', label: 'Datos por completar', valor: incompletos.length, sub: quePasa || 'siembra, ubicacion y parcelas al dia', icon: 'fas fa-clipboard-list', color: '#CED2D5' },
      ];
    },
    opcionesDiseno() {
      // Conserva un diseno heredado que no este en el catalogo.
      const d = this.form.diseno;
      return d && !this.disenos.includes(d) ? [...this.disenos, d] : this.disenos;
    },
  },
  mounted() {
    // Enlace directo: ?ensayo=ID[&tab=estructura|mapa|ndvi|clima|archivos|bitacora][&vista=croquis]
    const qs = new URLSearchParams(window.location.search);
    const id = Number(qs.get('ensayo'));
    if (id && this.items.some((e) => e.id === id)) {
      this.$refs.detalle.abrir(id);
      if (qs.get('vista') === 'croquis') {
        this.$refs.detalle.vistaParcelas = 'croquis';
        this.$refs.detalle.cambiarTab(qs.get('tab') || 'estructura');
      } else if (qs.get('tab')) {
        this.$refs.detalle.cambiarTab(qs.get('tab'));
      }
    }
    // Desde Proyectos: ?proyecto=ID&nuevo=1 abre el alta con el proyecto ya elegido.
    if (qs.get('nuevo') === '1' && this.permisos.crear) this.$nextTick(() => this.abrirCrear());
  },
  watch: {
    busqueda() { this.paginaActual = 1; },
    filtroEstado() { this.paginaActual = 1; },
    filtroProyecto() { this.paginaActual = 1; },
    filtroPrograma(id) {
      this.paginaActual = 1;
      // Si el proyecto elegido no es de este programa, se suelta.
      if (id && this.filtroProyecto && !this.proyectosDelPrograma.some((p) => p.id === this.filtroProyecto)) this.filtroProyecto = null;
    },
    filtroRapido() { this.paginaActual = 1; },
    'form.proyecto_id': 'previsualizarCodigo',
    'form.tipo_estudio': 'previsualizarCodigo',
    filtroTipo() { this.paginaActual = 1; },
    porPagina() { this.paginaActual = 1; },
    totalPaginas(n) { if (this.paginaActual > n) this.paginaActual = n; },
  },
  methods: {
    // Codigo que asignara el servidor (PROYECTO-ENN); solo informativo.
    previsualizarCodigo() {
      if (!this.form.proyecto_id) { this.codigo = { valor: '', cambia: false, bloqueo: null }; return; }
      const params = { proyecto_id: this.form.proyecto_id, ensayo_id: this.editando ? this.editando.id : undefined, tipo_estudio: this.form.tipo_estudio };
      const pedido = (this.pedidoCodigo = {});
      window.axios.get('/investigacion/ensayos/codigo', { params }).then(({ data }) => {
        if (pedido === this.pedidoCodigo) this.codigo = { valor: data.codigo, cambia: !!data.cambia, bloqueo: data.bloqueo || null };
      }).catch(() => {});
    },
    formVacio() {
      return {
        proyecto_id: this.filtroProyecto || null, ingenio_id: null, finca: '', lote: '',
        diseno: 'Bloques al azar', cultivo: 'Caña de azúcar', variedad: '',
        responsables: this.usuarioId ? [Number(this.usuarioId)] : [], estado: 'Planificado',
        lat: null, lng: null, num_tratamientos: 3, num_repeticiones: 3, area_parcela: 50,
        nombre: '', objetivo: '', ciclo: null, fecha_siembra: '', fecha_cosecha_prevista: '', fecha_cosecha: '',
        estrato: null, zona: null, area_total: null, tipo_suelo: '',
        tipo_estudio: this.filtroTipo === 'prueba' ? 'prueba' : 'ensayo', subtipo: null, lugar: null, fecha_inicio: '', fecha_fin: '', materiales: '',
      };
    },
    /** Duracion o estado de una prueba tecnica (columna de periodo en la lista). */
    textoPrueba(e) {
      const hoy = this.hoyUtc();
      const ini = Date.parse(String(e.fecha_inicio).slice(0, 10));
      if (ini > hoy) return `inicia en ${Math.round((ini - hoy) / DIA)} días`;
      const fin = e.fecha_fin ? Date.parse(String(e.fecha_fin).slice(0, 10)) : null;
      if (e.estado === 'Finalizado' || (fin && fin < hoy)) return `${Math.round(((fin || hoy) - ini) / DIA)} días de prueba`;
      return `día ${Math.round((hoy - ini) / DIA) + 1}${fin ? ` · faltan ${Math.round((fin - hoy) / DIA)} días` : ''}`;
    },
    fmt(s) { if (!s) return ''; const [y, m, d] = String(s).slice(0, 10).split('-'); return `${d}/${m}/${y}`; },
    /** Edad del cultivo desde la siembra o el corte anterior (hasta la cosecha si ya ocurrio). */
    edad(e) {
      if (!e || !e.fecha_siembra) return null;
      const ini = Date.parse(String(e.fecha_siembra).slice(0, 10));
      const fin = e.fecha_cosecha ? Date.parse(String(e.fecha_cosecha).slice(0, 10)) : Date.now();
      const dias = Math.floor((fin - ini) / 86400000);
      if (Number.isNaN(dias)) return null;
      if (dias < 0) return { texto: 'por sembrar', detalle: `en ${-dias} dias` };
      const meses = dias / 30.44;
      return {
        texto: meses >= 1 ? `${meses.toFixed(1)} meses` : `${dias} dias`,
        detalle: e.fecha_cosecha ? `${dias} dias al cosechar` : `${dias} dias${e.ciclo && e.ciclo !== 'Plantia' ? ' desde el corte' : ' desde siembra'}`,
      };
    },
    situacion(e) { return this.filtroZafra ? situacionEnZafra(periodoEnsayo(e), this.filtroZafra) : null; },
    estiloSituacion(e) { const s = SITUACIONES[this.situacion(e)]; return { background: s.fondo, color: s.color, borderColor: s.color }; },
    leerVista() {
      const q = new URLSearchParams(window.location.search).get('vista');
      if (VISTAS.some((v) => v.id === q)) return q;
      try { const v = window.localStorage.getItem('sigec.ensayos.vista'); return VISTAS.some((x) => x.id === v) ? v : 'lista'; } catch (err) { return 'lista'; }
    },
    cambiarVista(v) {
      this.vista = v;
      try { window.localStorage.setItem('sigec.ensayos.vista', v); } catch (err) { /* sin almacenamiento */ }
    },
    hoyUtc() { const d = new Date(); return Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()); },
    // % del ciclo transcurrido entre siembra/corte y la cosecha prevista.
    avanceCiclo(e) {
      if (!e.fecha_siembra || !e.fecha_cosecha_prevista) return null;
      const a = Date.parse(String(e.fecha_siembra).slice(0, 10));
      const b = Date.parse(String(e.fecha_cosecha_prevista).slice(0, 10));
      if (!(b > a)) return null;
      const fin = e.fecha_cosecha ? Date.parse(String(e.fecha_cosecha).slice(0, 10)) : this.hoyUtc();
      return Math.max(0, Math.min(100, Math.round(((fin - a) / (b - a)) * 100)));
    },
    diasACosecha(e) {
      if (!e.fecha_cosecha_prevista || e.fecha_cosecha) return null;
      return Math.round((Date.parse(String(e.fecha_cosecha_prevista).slice(0, 10)) - this.hoyUtc()) / DIA);
    },
    cosechaProxima(e, dias = 30) {
      const d = this.diasACosecha(e);
      return e.estado !== 'Finalizado' && d !== null && d <= dias;
    },
    textoCosecha(e) {
      if (e.fecha_cosecha) return `Cosechado el ${this.fmt(e.fecha_cosecha)}`;
      const d = this.diasACosecha(e);
      if (d === null) return '';
      if (d < 0) return `Cosecha prevista vencida hace ${-d} dias`;
      return d === 0 ? 'Cosecha prevista hoy' : `Cosecha en ${d} dias (${this.fmt(e.fecha_cosecha_prevista)})`;
    },
    // Lo que falta para seguir el ensayo en las demas paginas (edad, mapa, evaluaciones por parcela).
    faltantes(e) {
      if (e.estado === 'Finalizado' || e.tipo_estudio === 'prueba') return [];
      return [
        !e.fecha_siembra && 'siembra',
        !(e.lat && e.lng) && 'ubicacion',
        !e.parcelas_count && 'parcelas',
      ].filter(Boolean);
    },
    actividad(e) {
      return [e.ultima_evaluacion, e.ultimo_muestreo].filter(Boolean).map((f) => String(f).slice(0, 10)).sort().pop() || null;
    },
    sinActividad(e) {
      const a = this.actividad(e);
      return e.tipo_estudio !== 'prueba' && e.estado === 'En campo' && (!a || Date.now() - Date.parse(a) > 30 * 86400000);
    },
    ordenarPor(campo) {
      this.orden = { campo, asc: this.orden.campo === campo ? !this.orden.asc : true };
    },
    exportar() {
      const esc = (s) => { const t = String(s === null || s === undefined ? '' : s); return /[;"\n]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t; };
      const cab = ['Codigo', 'Nombre', 'Proyecto', 'Ingenio', 'Finca', 'Lote', 'Estrato', 'Zona', 'Cultivo', 'Variedad', 'Ciclo', 'Fecha siembra/corte', 'Cosecha prevista', 'Cosecha real',
        'Edad', 'Diseno', 'Tratamientos', 'Repeticiones', 'Parcelas', 'Area parcela (m2)', 'Area total (ha)', 'Responsable', 'Estado', 'Evaluaciones', 'Ultima evaluacion', 'Muestras', 'Muestras con resultado', 'Latitud', 'Longitud'];
      const filas = this.filtrados.map((e) => [
        e.codigo, e.nombre, e.proyecto && e.proyecto.codigo, e.ingenio && e.ingenio.nombre, e.finca, e.lote, e.estrato, e.zona, e.cultivo, e.variedad, e.ciclo,
        e.fecha_siembra, e.fecha_cosecha_prevista, e.fecha_cosecha, this.edad(e) ? this.edad(e).texto : '', e.diseno, e.tratamientos_count, e.num_repeticiones, e.parcelas_count,
        e.area_parcela, e.area_total, this.nombresResponsables(e).join(', '), e.estado, e.evaluaciones_count, e.ultima_evaluacion, e.muestras_count, e.muestras_completadas_count, e.lat, e.lng,
      ].map(esc).join(';'));
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([`﻿${[cab.join(';'), ...filas].join('\r\n')}`], { type: 'text/csv;charset=utf-8' }));
      a.download = `ensayos_${new Date().toISOString().slice(0, 10)}.csv`;
      a.click();
      setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    },
    normalizar(s) {
      return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    },
    claseEstado(estado) {
      return { Planificado: 'is-plan', 'En campo': 'is-campo', Finalizado: 'is-fin' }[estado] || '';
    },
    ver(e) {
      this.$refs.detalle.abrir(e.id);
    },
    abrirCrear() {
      this.editando = null;
      this.form = this.formVacio();
      this.errors = {};
      this.previsualizarCodigo();
      window.$(this.$refs.modal).modal('show');
    },
    abrirEditar(e) {
      this.editando = e;
      this.form = {
        proyecto_id: e.proyecto_id, ingenio_id: e.ingenio_id, finca: e.finca || '', lote: e.lote || '',
        diseno: e.diseno || null, cultivo: e.cultivo || '', variedad: e.variedad || '', responsables: (e.responsables || []).map((u) => u.id).length ? e.responsables.map((u) => u.id) : (e.responsable_id ? [e.responsable_id] : []),
        estado: e.estado, lat: e.lat, lng: e.lng, num_tratamientos: e.num_tratamientos,
        num_repeticiones: e.num_repeticiones, area_parcela: e.area_parcela,
        nombre: e.nombre || '', objetivo: e.objetivo || '', ciclo: e.ciclo || null,
        fecha_siembra: e.fecha_siembra || '', fecha_cosecha_prevista: e.fecha_cosecha_prevista || '', fecha_cosecha: e.fecha_cosecha || '',
        estrato: e.estrato || null, zona: e.zona || null, area_total: e.area_total, tipo_suelo: e.tipo_suelo || '',
        tipo_estudio: e.tipo_estudio || 'ensayo', subtipo: e.subtipo || null, lugar: e.lugar || null,
        fecha_inicio: e.fecha_inicio || '', fecha_fin: e.fecha_fin || '', materiales: e.materiales || '',
      };
      this.errors = {};
      this.previsualizarCodigo();
      window.$(this.$refs.modal).modal('show');
    },
    usarUbicacion() {
      if (!navigator.geolocation) {
        this.$swal.fire({ icon: 'info', title: 'No disponible', text: 'Este navegador no permite obtener la ubicacion.' });
        return;
      }
      this.ubicando = true;
      navigator.geolocation.getCurrentPosition(
        (pos) => {
          this.form.lat = pos.coords.latitude.toFixed(6);
          this.form.lng = pos.coords.longitude.toFixed(6);
          this.ubicando = false;
        },
        () => {
          this.ubicando = false;
          this.$swal.fire({ icon: 'info', title: 'Sin ubicacion', text: 'No se pudo obtener la ubicacion (permiso denegado o sin senal).' });
        },
        { enableHighAccuracy: true, timeout: 10000 }
      );
    },
    nombreUsuario(id) { const u = this.responsables.find((x) => x.id === id); return u ? u.name : `#${id}`; },
    agregarResponsable(ev) {
      const id = Number(ev.target.value);
      if (id && !this.form.responsables.includes(id)) this.form.responsables.push(id);
      ev.target.value = '';
    },
    principal(id) { this.form.responsables = [id, ...this.form.responsables.filter((x) => x !== id)]; },
    /** "Ana Lopez +2" con todos los nombres en el title. */
    nombresResponsables(e) {
      const nombres = (e.responsables || []).map((u) => u.name);
      if (!nombres.length && e.responsable) nombres.push(e.responsable.name);
      return nombres;
    },
    guardar() {
      const faltan = {};
      if (!this.form.proyecto_id) faltan.proyecto_id = ['Seleccione el proyecto.'];
      if (Object.keys(faltan).length) { this.errors = faltan; return; }

      this.guardando = true;
      this.errors = {};
      const payload = { ...this.form, responsable_id: this.form.responsables[0] || null };
      ['lat', 'lng', 'area_parcela', 'area_total', 'fecha_siembra', 'fecha_cosecha_prevista', 'fecha_cosecha', 'nombre', 'objetivo', 'tipo_suelo', 'fecha_inicio', 'fecha_fin', 'materiales'].forEach((k) => { if (payload[k] === '') payload[k] = null; });
      // Una prueba tecnica no lleva diseno, cultivo ni fechas de siembra/cosecha.
      if (payload.tipo_estudio === 'prueba') {
        Object.assign(payload, { diseno: null, cultivo: null, variedad: null, ciclo: null, fecha_siembra: null, fecha_cosecha_prevista: null, fecha_cosecha: null,
          num_tratamientos: 0, num_repeticiones: 0, area_parcela: null, estrato: null, zona: null, area_total: null, tipo_suelo: null });
      }
      const editando = this.editando;
      const peticion = editando
        ? window.axios.put(`/investigacion/ensayos/${editando.id}`, payload)
        : window.axios.post('/investigacion/ensayos', payload);

      peticion
        .then(({ data }) => {
          this.actualizarFila(data);
          window.$(this.$refs.modal).modal('hide');
          this.toast(editando ? 'Ensayo actualizado' : 'Ensayo creado', data.recodificado ? `Nuevo codigo ${data.codigo} (muestras actualizadas)` : data.codigo);
          if (this.$refs.detalle.ensayoId === data.id) this.$refs.detalle.cargar();
        })
        .catch((err) => {
          if (err.response && err.response.status === 422 && err.response.data.errors) {
            this.errors = err.response.data.errors;
          } else {
            const msg = err.response && err.response.data && err.response.data.message;
            this.$swal.fire({ icon: 'error', title: 'No se pudo guardar', text: msg || 'Intente de nuevo.' });
          }
        })
        .finally(() => { this.guardando = false; });
    },
    actualizarFila(data) {
      const idx = this.items.findIndex((i) => i.id === data.id);
      if (idx >= 0) this.$set(this.items, idx, data);
      else this.items.push(data);
      this.items.sort((a, b) => a.codigo.localeCompare(b.codigo, 'es'));
    },
    eliminar(e) {
      const partes = [];
      if (e.tratamientos_count) partes.push(`${e.tratamientos_count} tratamiento(s)`);
      if (e.parcelas_count) partes.push(`${e.parcelas_count} parcela(s) con sus evaluaciones`);
      if (e.archivos_count) partes.push(`${e.archivos_count} archivo(s)`);
      if (e.bitacora_count) partes.push(`${e.bitacora_count} entrada(s) de bitacora`);
      const div = document.createElement('div');
      div.textContent = e.codigo;
      this.$swal.fire({
        title: 'Eliminar ensayo',
        html: `Se eliminara <b>${div.innerHTML}</b>${partes.length ? ' junto con ' + partes.join(', ') : ''}.<br>Esta accion no se puede deshacer.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Si, eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#FF6B00',
        cancelButtonColor: '#CED2D5',
        focusCancel: true,
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/investigacion/ensayos/${e.id}`)
          .then(() => {
            this.items = this.items.filter((i) => i.id !== e.id);
            this.toast('Ensayo eliminado', e.codigo);
          })
          .catch((err) => {
            const msg = err.response && err.response.data && err.response.data.message;
            this.$swal.fire({ icon: 'error', title: 'No se pudo eliminar', text: msg || 'Ocurrio un error.' });
          });
      });
    },
    toast(title, text) {
      this.$swal.fire({ toast: true, position: 'top-end', icon: 'success', title, text, timer: 2200, showConfirmButton: false });
    },
  },
};
</script>

<style scoped>
/* Pruebas tecnicas */
.ens-prueba-tag { display: inline-block; margin-left: 6px; padding: 0 7px; border-radius: 8px; background: #e8f1fb; color: #1f5f9e; font-size: 10px; font-weight: 700; vertical-align: 1px; }
.ens-tipo { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
.ens-tipo button { display: grid; grid-template-columns: auto 1fr; column-gap: 10px; align-items: center; padding: 10px 12px; border: 1.5px solid #e3e7e4; border-radius: 12px; background: #fff; text-align: left; cursor: pointer; }
.ens-tipo button i { grid-row: 1 / 3; display: grid; place-items: center; width: 34px; height: 34px; border-radius: 9px; background: #f1f4f0; color: #5f6b66; font-size: 15px; }
.ens-tipo button b { font-size: 13.5px; color: #1f2a1a; }
.ens-tipo button small { color: #5f6b66; font-size: 11.5px; line-height: 1.35; }
.ens-tipo button.is-active { border-color: #5a9e1a; background: #f6fbef; }
.ens-tipo button.is-active i { background: #5a9e1a; color: #fff; }
@media (max-width: 640px) { .ens-tipo { grid-template-columns: 1fr; } }
.ens-zafra-tag { display: inline-block; margin-left: 6px; padding: 0 7px; border: 1px solid; border-radius: 8px; font-size: 10px; font-weight: 700; vertical-align: 1px; }
.ens-codigo-auto { display: flex; align-items: center; gap: 8px; height: calc(2.25rem + 2px); padding: 0 12px; border: 1px dashed #CED2D5; border-radius: 6px; background: #f7f9f6; color: #3f7a12; font-family: 'Space Grotesk', sans-serif; letter-spacing: .03em; }
.ens-codigo-auto i { color: #73BC25; font-size: 12px; }
.ens-codigo-auto.is-cambia { border-color: #FFCC00; background: #fffbe6; }
.ens-codigo-auto.is-bloqueo { border-color: #FF6B00; background: #fff3eb; }
.ens-aviso { color: #b84d00; }
.ens-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
.ens-stat {
  position: relative;
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px 18px;
  overflow: hidden;
  border: 1px solid var(--cengi-border);
  border-radius: var(--cengi-radius);
  background: var(--cengi-surface);
  box-shadow: var(--cengi-shadow);
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition: border-color .15s ease, transform .15s ease;
}
.ens-stat:first-child { cursor: default; }
.ens-stat::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--acento); }
.ens-stat:not(:first-child):hover { transform: translateY(-2px); }
.ens-stat.is-active { border-color: var(--acento); box-shadow: 0 0 0 2px color-mix(in srgb, var(--acento) 35%, transparent); }
.ens-stat-icon {
  display: grid; place-items: center; flex-shrink: 0;
  width: 42px; height: 42px; border-radius: 11px;
  background: color-mix(in srgb, var(--acento) 18%, #fff);
  color: color-mix(in srgb, var(--acento) 65%, #1f2a1f);
  font-size: 17px;
}
.ens-stat-value { color: var(--cengi-primary-deep); font-family: 'Space Grotesk', sans-serif; font-size: 24px; font-weight: 700; line-height: 1.1; }
.ens-stat-label { color: var(--cengi-muted); font-size: 11.5px; font-weight: 600; }

.ens-toolbar {
  display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
  padding: 12px; margin-bottom: 14px;
  border: 1px solid var(--cengi-border); border-radius: var(--cengi-radius);
  background: var(--cengi-surface); box-shadow: var(--cengi-shadow);
}
.ens-search { position: relative; flex: 1 1 260px; }
.ens-search i { position: absolute; top: 50%; left: 13px; transform: translateY(-50%); color: #8a9b8e; font-size: 12px; pointer-events: none; }
.ens-search .form-control { padding-left: 36px; }
.ens-filter { flex: 0 1 180px; width: auto; min-width: 0; }
.ens-nuevo { margin-left: auto; white-space: nowrap; }

.ens-table-card {
  border: 1px solid var(--cengi-border); border-radius: var(--cengi-radius);
  background: var(--cengi-surface); box-shadow: var(--cengi-shadow); overflow: hidden;
}
.ens-table-wrap { overflow-x: auto; }
.ens-table { min-width: 980px; }
.ens-table th, .ens-table td { padding: .65rem .55rem; }
.ens-table th:first-child, .ens-table td:first-child { padding-left: 14px; }
.ens-table th.is-sort { cursor: pointer; user-select: none; white-space: nowrap; }
.ens-table th.is-sort:hover { color: var(--cengi-primary-deep); }
.ens-table th i { margin-left: 3px; font-size: 10px; }
.ens-sort-off { opacity: .3; }
.ens-col-ens { max-width: 240px; }
.ens-nombre { display: block; overflow: hidden; color: var(--cengi-muted); font-size: 11.5px; text-overflow: ellipsis; white-space: nowrap; }
.ens-cat-link { margin-left: 6px; color: #4f8a12; font-size: 11px; font-weight: 600; text-transform: none; }
.ens-cat-link:hover { color: #1f5632; text-decoration: underline; }
.ens-resp { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 6px; }
.ens-resp-chip { display: inline-flex; align-items: center; gap: 4px; padding: 2px 4px 2px 6px; border: 1px solid #CED2D5; border-radius: 999px; background: #fff; color: #33433a; font-size: 12.5px; font-weight: 600; }
.ens-resp-chip.is-principal { border-color: #73BC25; background: #f3f9ea; }
.ens-resp-star, .ens-resp-x { padding: 0 3px; border: 0; background: transparent; line-height: 1; }
.ens-resp-star { color: #c9a400; font-size: 11px; }
.ens-resp-x { color: #8a8f87; font-size: 15px; }
.ens-resp-x:hover { color: #FF6B00; }
.ens-resp-mas { padding: 0 5px; border-radius: 999px; background: #eef6e2; color: #3e6b14; font-size: 11px; font-weight: 700; }
.ens-sub { display: block; color: var(--cengi-muted); font-size: 11px; }
.ens-sub.is-alerta { color: #c95500; font-weight: 700; }
.ens-stat-sub { color: var(--cengi-muted); font-size: 10.5px; line-height: 1.3; }
.ens-vistas { display: inline-flex; padding: 3px; border: 1px solid var(--cengi-border); border-radius: 10px; background: #f7f9f6; }
.ens-vistas button { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border: 0; border-radius: 7px; background: transparent; color: var(--cengi-muted); font-size: 12.5px; font-weight: 600; white-space: nowrap; }
.ens-vistas button.is-active { background: #fff; color: var(--cengi-primary-deep); box-shadow: 0 1px 3px rgba(31, 42, 26, .12); }
.ens-vistas button.is-active i { color: #73BC25; }
.ens-ciclo { min-width: 150px; }
.ens-ciclo small:not(.ens-sub) { color: var(--cengi-muted); font-size: 11px; }
.ens-ciclo-barra { height: 5px; margin: 4px 0 2px; max-width: 160px; background: #edf0ee; border-radius: 99px; overflow: hidden; }
.ens-ciclo-barra i { display: block; height: 100%; background: #5a9e1a; border-radius: 99px; }
.ens-proy-sub { max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ens-proy-sub i { font-size: 10px; margin-right: 2px; }
.ens-av { margin-right: 8px; color: var(--cengi-ink); font-size: 12.5px; font-weight: 600; }
.ens-av i { margin-right: 3px; color: #73BC25; font-size: 11px; }
.ens-btn-sec { border: 1px solid var(--cengi-border); border-radius: 9px; background: #fff; color: var(--cengi-primary-deep); font-weight: 600; white-space: nowrap; }
.ens-btn-sec:hover { border-color: #73BC25; color: var(--cengi-primary-deep); }
.ens-btn-sec i { color: #73BC25; }
.ens-table td { vertical-align: middle; }
.ens-row { cursor: default; }
.ens-codigo { color: var(--cengi-primary-deep); font-weight: 700; }
.ens-codigo:hover { color: #73BC25; }
.ens-gps { margin-left: 6px; color: #FF6B00; font-size: 11px; }
.ens-proy { padding: 3px 8px; border-radius: 6px; background: #f1f4ef; color: var(--cengi-ink); font-size: 11.5px; font-weight: 600; }
.ens-muted { color: var(--cengi-muted); }
.ens-nowrap { white-space: nowrap; }
.ens-nowrap span + span { margin-left: 10px; }
.ens-nowrap i { margin-right: 3px; color: #8a9b8e; font-size: 11px; }
.ens-estado { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.ens-estado.is-plan { background: #fff5cc; color: #7a5d00; }
.ens-estado.is-campo { background: #eef8df; color: #3f7a12; }
.ens-estado.is-fin { background: #eef0f1; color: #5b6267; border: 1px solid #CED2D5; }
.ens-actions { white-space: nowrap; text-align: right; }
.ens-icon {
  display: inline-grid; place-items: center; width: 30px; height: 30px;
  border: 1px solid transparent; border-radius: var(--cengi-radius-sm);
  background: transparent; color: var(--cengi-muted); font-size: 12px; cursor: pointer;
}
.ens-icon:hover, .ens-icon:focus-visible { border-color: var(--cengi-border); background: var(--cengi-accent-soft); color: var(--cengi-primary-deep); outline: none; }
.ens-icon-danger:hover, .ens-icon-danger:focus-visible { border-color: #ffd2b3; background: #fff1e6; color: #FF6B00; }
.ens-empty { padding: 44px 16px !important; color: var(--cengi-muted); text-align: center; }
.ens-empty > i { display: block; margin-bottom: 8px; color: #CED2D5; font-size: 28px; }

.ens-pagination {
  display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px;
  padding: 10px 14px; border-top: 1px solid var(--cengi-border); background: #fbfcf9;
  color: var(--cengi-muted); font-size: 12px;
}
.ens-pages { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; }
.ens-pages select { width: auto; margin-right: 6px; }
.ens-pages button {
  min-width: 30px; height: 30px; padding: 0 8px;
  border: 1px solid var(--cengi-border); border-radius: 7px; background: #fff;
  color: var(--cengi-ink); font-size: 12px; font-weight: 600; cursor: pointer;
}
.ens-pages button.is-active { border-color: #73BC25; background: #73BC25; color: #fff; }
.ens-pages button:disabled { opacity: .4; cursor: default; }

.ens-section {
  display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px;
  margin: 6px 0 12px; padding-top: 14px; border-top: 1px solid var(--cengi-border);
  color: var(--cengi-primary-deep); font-size: 12.5px; font-weight: 700;
}
.ens-section i { color: #73BC25; }
.ens-section small { color: var(--cengi-muted); font-weight: 500; }
.ens-req { color: #FF6B00; }
.ens-hint { padding: 8px 12px; border-radius: 8px; background: #f5faef; color: var(--cengi-muted); font-size: 12px; }
.ens-hint i { margin-right: 4px; color: #73BC25; }

@media (max-width: 1100px) { .ens-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 640px) {
  .ens-stats { gap: 10px; }
  .ens-stat { padding: 12px; gap: 10px; }
  .ens-stat-value { font-size: 20px; }
  .ens-filter, .ens-nuevo { flex: 1 1 100%; margin-left: 0; }
  .ens-vistas { flex: 1 1 100%; }
  .ens-vistas button { flex: 1 1 0; justify-content: center; }
  .ens-vistas button span { display: none; }
}
</style>
