<template>
  <div class="pry">
    <!-- ============ Programas: primer nivel de la jerarquia (filtran los proyectos) ============ -->
    <section class="pp-progs" aria-label="Programas de investigacion">
      <div class="pp-tabs" role="tablist" aria-label="Filtrar por programa">
        <span class="pp-tabs-l"><i class="fas fa-layer-group"></i> Programa</span>
        <button type="button" role="tab" class="pp-tab is-todos" :class="{ 'is-on': !filtroPrograma }" :aria-selected="!filtroPrograma" @click="filtroPrograma = null">
          Todos <small>{{ items.length }}</small>
        </button>
        <button
          v-for="pg in programasOrdenados" :key="pg.id" type="button" role="tab" class="pp-tab"
          :class="{ 'is-on': filtroPrograma === pg.id, 'is-vacio': !proyectosDe(pg.id) }" :style="estiloPrograma(pg)"
          :aria-selected="filtroPrograma === pg.id"
          :title="`${pg.nombre}: ${proyectosDe(pg.id)} proyecto(s), ${pg.ensayos_count || 0} ensayo(s)${enCampo(pg) ? ', ' + enCampo(pg) + ' en campo' : ''}`"
          @click="elegirPrograma(pg.id)"
        >
          <i class="pp-tab-dot"></i><b>{{ pg.sigla || '?' }}</b><span class="pp-tab-n">{{ pg.nombre }}</span>
          <small>{{ proyectosDe(pg.id) }}</small><i v-if="enCampo(pg)" class="fas fa-seedling pp-tab-campo" :title="`${enCampo(pg)} ensayo(s) en campo`"></i>
        </button>
        <button v-if="puedeGestionarProgramas" type="button" class="pp-tab is-nuevo" title="Nuevo programa" @click="$refs.programaModal.abrirCrear()"><i class="fas fa-plus"></i></button>
      </div>

      <!-- Ficha del programa elegido -->
      <div v-if="programaFiltrado" class="pp-ficha" :style="estiloPrograma(programaFiltrado)">
        <span class="pp-ficha-av">{{ programaFiltrado.sigla }}</span>
        <div class="pp-ficha-txt">
          <b>{{ programaFiltrado.nombre }}</b>
          <small>
            <template v-if="programaFiltrado.lider"><i class="fas fa-user-tie"></i> {{ programaFiltrado.lider }} · </template>
            {{ proyectosDe(programaFiltrado.id) }} proyecto(s) · {{ programaFiltrado.ensayos_count || 0 }} ensayo(s)<template v-if="enCampo(programaFiltrado)">, {{ enCampo(programaFiltrado) }} en campo</template>
            · <i class="fas fa-users"></i> {{ (programaFiltrado.miembros || []).length }} con acceso
            <template v-if="programaFiltrado.ultima_actividad"> · actividad {{ relativo(programaFiltrado.ultima_actividad).toLowerCase() }}</template>
          </small>
          <p v-if="programaFiltrado.descripcion" :title="programaFiltrado.descripcion">{{ programaFiltrado.descripcion }}</p>
        </div>
        <div class="pp-ficha-acc">
          <template v-if="puedeGestionarProgramas">
            <button type="button" class="pry-icon" title="Editar programa" @click="$refs.programaModal.abrirEditar(programaFiltrado)"><i class="fas fa-pen"></i></button>
            <button type="button" class="pry-icon is-danger" :class="{ 'is-locked': proyectosDe(programaFiltrado.id) }" :title="proyectosDe(programaFiltrado.id) ? 'Tiene proyectos: no se puede eliminar' : 'Eliminar programa'" @click="eliminarPrograma(programaFiltrado)"><i class="fas fa-trash"></i></button>
          </template>
          <button v-if="puedeCrear" type="button" class="btn btn-sm btn-success" @click="abrirCrear(programaFiltrado.id)">
            <i class="fas fa-plus"></i> Nuevo proyecto<small v-if="programaFiltrado.proximo_codigo"> · {{ programaFiltrado.proximo_codigo }}</small>
          </button>
        </div>
      </div>
    </section>

    <!-- ============ Resumen (los indicadores filtran la lista) ============ -->
    <div class="pry-stats">
      <button
        v-for="s in resumen" :key="s.id" type="button" class="pry-stat"
        :class="{ 'is-on': filtroRapido === s.id }" :style="{ '--acento': s.color }"
        :title="filtroRapido === s.id ? 'Quitar filtro' : 'Filtrar: ' + s.label.toLowerCase()"
        @click="filtroRapido = filtroRapido === s.id ? '' : s.id"
      >
        <span class="pry-stat-icon"><i :class="s.icon"></i></span>
        <span>
          <strong>{{ s.valor }}</strong>
          <b>{{ s.label }}</b>
          <small>{{ s.sub }}</small>
        </span>
      </button>
    </div>

    <!-- ============ Herramientas ============ -->
    <div class="pry-toolbar">
      <div class="pry-search">
        <i class="fas fa-search"></i>
        <input type="search" class="form-control" v-model="busqueda" placeholder="Buscar proyecto, codigo, responsable o ensayo...">
      </div>
      <select class="form-control pry-sel" v-model="filtroZafra" aria-label="Zafra" title="Proyectos activos en la zafra">
        <option value="">Todas las zafras</option>
        <option v-for="z in zafrasDisponibles" :key="z" :value="z">Zafra {{ z }}{{ z === zafraHoy ? ' (actual)' : '' }}</option>
      </select>
      <select class="form-control pry-sel" v-model="filtroEstado" aria-label="Estado">
        <option value="">Todos los estados</option>
        <option v-for="e in ESTADOS" :key="e" :value="e">{{ e }}</option>
      </select>
      <select class="form-control pry-sel" v-model="orden" aria-label="Ordenar">
        <option value="codigo">Ordenar: codigo</option>
        <option value="vence">Ordenar: fecha de fin</option>
        <option value="actividad">Ordenar: ultima actividad</option>
        <option value="ensayos">Ordenar: mas ensayos</option>
      </select>
      <button v-if="puedeCrear" class="btn btn-success pry-nuevo" @click="abrirCrear(filtroPrograma)">
        <i class="fas fa-plus"></i> Nuevo proyecto
      </button>
    </div>

    <p v-if="hayFiltros" class="pry-resultados">
      {{ proyectosFiltrados.length }} de {{ items.length }} proyecto(s)
      <template v-if="filtroRapido"> · {{ (resumen.find((s) => s.id === filtroRapido) || {}).label }}</template>
      <template v-if="programaFiltrado"> · {{ programaFiltrado.nombre }}</template>
      <a href="#" @click.prevent="limpiar">Limpiar filtros</a>
    </p>
    <div v-if="items.some((p) => p.ensayos_count)" class="pry-leyenda" aria-label="Colores de estado de los ensayos">
      <span>Ensayos:</span><span v-for="s in ESTADOS_ENSAYO" :key="s.id"><i :style="{ background: s.color }"></i>{{ s.id }}</span>
      <span class="pry-leyenda-tip"><i class="fas fa-mouse-pointer"></i> Clic en un proyecto para ver sus ensayos y su equipo</span>
    </div>

    <!-- ============ Lista ============ -->
    <div v-if="proyectosFiltrados.length" class="pry-lista">
      <div class="pry-head">
        <span>Proyecto</span><span>Responsable</span><span>Periodo</span><span>Ensayos por estado</span><span class="pry-c">Muestras</span><span>Actividad</span><span></span>
      </div>
      <template v-for="g in grupos">
      <div v-if="!filtroPrograma" :key="'g' + g.id" class="pry-grupo" :style="estiloPrograma(g.programa)">
        <a href="#" @click.prevent="elegirPrograma(g.id)"><span class="pry-grupo-av">{{ g.programa ? g.programa.sigla : '?' }}</span> {{ g.programa ? g.programa.nombre : 'Sin programa' }}</a>
        <small>{{ g.items.length }} proyecto(s)</small>
        <button v-if="puedeCrear && g.programa" type="button" class="pry-grupo-add" :title="`Nuevo proyecto en ${g.programa.nombre}`" @click="abrirCrear(g.id)"><i class="fas fa-plus"></i> Proyecto</button>
      </div>
      <div
        v-for="p in g.items" :key="p.id" class="pry-fila" :style="{ '--prog': colorPrograma(p) }"
        tabindex="0" role="button" :aria-label="`Ver detalle de ${p.codigo}`"
        @click="abrirDetalle(p)" @keydown.enter.self="abrirDetalle(p)"
      >
        <div class="pry-main">
          <span class="pry-cod">{{ p.codigo }}<span v-if="situacion(p)" class="pry-zafra-tag" :style="estiloSituacion(p)" :title="`Situacion en la zafra ${filtroZafra}`">{{ SITUACIONES[situacion(p)].texto }}</span></span>
          <b :title="p.nombre">{{ p.nombre }}</b>
          <small :title="p.objetivo">{{ p.programa ? p.programa.nombre : 'Sin programa' }}<template v-if="p.objetivo"> · {{ p.objetivo }}</template></small>
        </div>
        <div class="pry-resp" data-label="Responsable">
          <span :class="{ 'pry-vacio': !p.responsable }"><i class="fas fa-user-tie"></i> {{ p.responsable ? p.responsable.name : 'Sin responsable' }}</span>
          <small v-if="(p.equipo || []).length"><i class="fas fa-users"></i> {{ p.equipo.length }} en el equipo</small>
        </div>
        <div class="pry-periodo" data-label="Periodo">
          <template v-if="p.inicio || p.fin">
            <span>{{ fecha(p.inicio) || '¿?' }} – {{ fecha(p.fin) || '¿?' }}</span>
            <div v-if="avance(p) !== null" class="pry-tiempo" :title="`${avance(p)} % del periodo transcurrido`"><i :style="{ width: avance(p) + '%' }" :class="{ 'is-vencido': vencido(p) }"></i></div>
            <small v-if="p.estado !== 'Finalizado' && diasParaFin(p) !== null" :class="{ 'is-alerta': diasParaFin(p) <= 30 }">
              {{ diasParaFin(p) < 0 ? `Vencido hace ${-diasParaFin(p)} dias` : diasParaFin(p) === 0 ? 'Vence hoy' : `Vence en ${diasParaFin(p)} dias` }}
            </small>
          </template>
          <span v-else class="pry-vacio">Sin fechas</span>
        </div>
        <div class="pry-estados" data-label="Ensayos">
          <div v-if="p.ensayos_count" class="pry-apilada">
            <span
              v-for="s in ESTADOS_ENSAYO.filter((x) => (p.ensayos_estado || {})[x.id])" :key="s.id"
              :style="{ flexGrow: p.ensayos_estado[s.id], background: s.color, color: s.texto }"
              :title="`${p.ensayos_estado[s.id]} ${s.id.toLowerCase()}`"
            >{{ p.ensayos_estado[s.id] }}</span>
          </div>
          <span v-else class="pry-vacio">Sin ensayos</span>
        </div>
        <div class="pry-c" data-label="Muestras" :title="`${p.muestras_completadas || 0} con resultados de ${p.muestras_total || 0}`">
          <span v-if="p.muestras_total"><b>{{ p.muestras_completadas }}</b>/{{ p.muestras_total }}</span>
          <span v-else class="pry-vacio">—</span>
        </div>
        <div class="pry-act" data-label="Actividad" :class="{ 'is-alerta': sinActividad(p) }">
          <i v-if="sinActividad(p)" class="fas fa-exclamation-triangle"></i>
          <span>{{ p.ultima_actividad ? relativo(p.ultima_actividad) : 'Sin registros' }}</span>
          <small v-if="p.evaluaciones_total">{{ n(p.evaluaciones_total) }} valores evaluados</small>
        </div>
        <div class="pry-acciones" @click.stop>
          <span class="pry-estado" :class="'is-' + p.estado.toLowerCase()">{{ p.estado }}</span>
          <a v-if="puedeCrearEnsayo && p.estado !== 'Finalizado'" :href="`${ensayosUrl}?proyecto=${p.id}&nuevo=1`" class="pry-icon" :title="`Nuevo ensayo (${p.proximo_ensayo})`"><i class="fas fa-plus-circle"></i></a>
          <button v-if="puedeEditar" type="button" class="pry-icon" title="Editar proyecto" @click="abrirEditar(p)"><i class="fas fa-pen"></i></button>
          <button v-if="puedeEliminar" type="button" class="pry-icon is-danger" :class="{ 'is-locked': p.ensayos_count }" :title="p.ensayos_count ? 'Tiene ensayos: no se puede eliminar' : 'Eliminar proyecto'" @click="eliminar(p)"><i class="fas fa-trash"></i></button>
        </div>
      </div>
      </template>
    </div>

    <div v-else class="pry-vacio-box">
      <i class="fas fa-folder-open"></i>
      <template v-if="programaFiltrado && !proyectosDe(programaFiltrado.id)">
        <b>{{ programaFiltrado.nombre }} aun no tiene proyectos.</b>
        <button v-if="puedeCrear" type="button" class="btn btn-sm btn-success" @click="abrirCrear(programaFiltrado.id)"><i class="fas fa-plus"></i> Crear el primer proyecto de {{ programaFiltrado.sigla }}</button>
      </template>
      <template v-else-if="items.length">
        <b>Ningun proyecto coincide con los filtros.</b>
        <button type="button" class="btn btn-sm btn-secondary" @click="limpiar">Limpiar filtros</button>
      </template>
      <template v-else>
        <b>Aun no hay proyectos.</b>
        <button v-if="puedeCrear" type="button" class="btn btn-sm btn-success" @click="abrirCrear"><i class="fas fa-plus"></i> Crear el primer proyecto</button>
      </template>
    </div>

    <!-- ============ Detalle del proyecto ============ -->
    <transition name="pry-fade"><div v-if="detalle" class="pry-velo" @click="detalle = null"></div></transition>
    <transition name="pry-slide">
      <aside v-if="detalle" class="pry-panel" :style="{ '--prog': colorPrograma(detalle) }" role="dialog" aria-modal="true" :aria-label="`Detalle de ${detalle.codigo}`">
        <header class="pry-panel-h">
          <div class="pry-panel-t">
            <nav class="pry-migas" aria-label="Ubicacion">
              <a v-if="detalle.programa" href="#" :title="`Ver todos los proyectos de ${detalle.programa.nombre}`" @click.prevent="elegirPrograma(detalle.programa_id); detalle = null">
                <i class="fas fa-layer-group"></i> {{ detalle.programa.sigla }} · {{ detalle.programa.nombre }}
              </a>
              <i class="fas fa-chevron-right"></i>
              <span class="pry-cod">{{ detalle.codigo }}</span>
              <span class="pry-estado" :class="'is-' + detalle.estado.toLowerCase()">{{ detalle.estado }}</span>
            </nav>
            <h3>{{ detalle.nombre }}</h3>
          </div>
          <button v-if="puedeEditar" type="button" class="pry-icon" title="Editar proyecto" @click="abrirEditar(detalle)"><i class="fas fa-pen"></i></button>
          <button type="button" class="pry-icon" title="Cerrar (Esc)" @click="detalle = null"><i class="fas fa-times"></i></button>
        </header>
        <div class="pry-panel-b">
          <p class="pry-objetivo" :class="{ 'pry-vacio': !detalle.objetivo }"><b>Objetivo:</b> {{ detalle.objetivo || 'Sin objetivo registrado.' }}</p>

          <div v-if="detalle.inicio || detalle.fin" class="pry-crono">
            <div class="pry-crono-h">
              <span><i class="far fa-calendar"></i> {{ fecha(detalle.inicio) || '¿?' }} – {{ fecha(detalle.fin) || '¿?' }}</span>
              <span v-if="avance(detalle) !== null">{{ avance(detalle) }} % del periodo</span>
            </div>
            <div class="pry-tiempo is-grande"><i :style="{ width: (avance(detalle) || 0) + '%' }" :class="{ 'is-vencido': vencido(detalle) }"></i></div>
            <small v-if="detalle.estado !== 'Finalizado' && diasParaFin(detalle) !== null" :class="{ 'is-alerta': diasParaFin(detalle) <= 30 }">
              {{ diasParaFin(detalle) < 0 ? `El periodo termino hace ${-diasParaFin(detalle)} dias: actualice la fecha de fin o finalice el proyecto.` : `Faltan ${diasParaFin(detalle)} dias para la fecha de fin.` }}
            </small>
          </div>

          <div class="pry-kpis">
            <div><strong>{{ (detalle.ensayos_estado || {})['En campo'] || 0 }}<small>/{{ detalle.ensayos_count || 0 }}</small></strong><span>Ensayos en campo</span></div>
            <div><strong>{{ n(detalle.evaluaciones_total) }}</strong><span>Valores evaluados</span></div>
            <div><strong>{{ detalle.muestras_completadas || 0 }}<small>/{{ detalle.muestras_total || 0 }}</small></strong><span>Muestras con resultado</span></div>
            <div><strong>{{ (detalle.equipo || []).length }}</strong><span>Personas en el equipo</span></div>
          </div>

          <!-- Ensayos -->
          <section class="pry-sec">
            <div class="pry-sec-h">
              <h4><icono-cana :tamano="20"></icono-cana> Ensayos <span class="pry-badge">{{ (detalle.ensayos || []).length }}</span></h4>
              <a v-if="(detalle.ensayos || []).length" :href="`${ensayosUrl}?proyecto=${detalle.id}`">Ver en Ensayos <i class="fas fa-arrow-right"></i></a>
            </div>
            <ul v-if="(detalle.ensayos || []).length" class="pry-ens">
              <li v-for="e in detalle.ensayos" :key="e.id">
                <div class="pry-ens-main">
                  <div>
                    <a :href="`${ensayosUrl}?ensayo=${e.id}`" class="pry-cod">{{ e.codigo }}</a>
                    <span class="pry-ens-estado" :style="estiloEstado(e.estado)">{{ e.estado }}</span>
                  </div>
                  <b :title="e.nombre">{{ e.nombre || 'Sin nombre' }}</b>
                  <small>
                    {{ [e.finca, e.lote].filter(Boolean).join(' / ') || 'Sin ubicacion' }}<template v-if="e.variedad"> · {{ e.variedad }}</template>
                    <template v-if="e.responsable"> · {{ e.responsable }}</template>
                  </small>
                  <small :class="{ 'is-alerta': e.estado === 'En campo' && esViejo(e.ultima_actividad) }">
                    <i class="far fa-clock"></i> {{ e.ultima_actividad ? 'Actividad ' + relativo(e.ultima_actividad).toLowerCase() : 'Sin evaluaciones ni muestreos' }}
                  </small>
                </div>
                <div class="pry-ens-links">
                  <a :href="`${ensayosUrl}?ensayo=${e.id}`" title="Abrir ensayo"><i class="fas fa-eye"></i></a>
                  <a v-if="evaluacionesUrl" :href="`${evaluacionesUrl}?ensayo=${e.id}`" :title="`${e.evaluaciones} valores evaluados`"><i class="fas fa-clipboard-check"></i><small>{{ e.evaluaciones }}</small></a>
                  <a v-if="muestrasUrl" :href="`${muestrasUrl}?buscar=${encodeURIComponent(e.codigo)}`" :title="`${e.muestras_completadas} de ${e.muestras} muestras con resultado`"><i class="fas fa-vial"></i><small>{{ e.muestras_completadas }}/{{ e.muestras }}</small></a>
                </div>
              </li>
            </ul>
            <div v-else class="pry-vacio-box is-mini"><icono-cana :tamano="18" apagado></icono-cana> Este proyecto aun no tiene ensayos.</div>
            <div v-if="puedeCrearEnsayo && detalle.estado !== 'Finalizado'" class="pry-proximo">
              <span>Proximo ensayo: <b class="pry-cod">{{ detalle.proximo_ensayo }}</b></span>
              <a class="btn btn-sm btn-success" :href="`${ensayosUrl}?proyecto=${detalle.id}&nuevo=1`"><i class="fas fa-plus"></i> Nuevo ensayo</a>
            </div>
          </section>

          <!-- Archivos del proyecto y de sus ensayos -->
          <section class="pry-sec">
            <div class="pry-sec-h">
              <h4><i class="fas fa-paperclip"></i> Archivos <span class="pry-badge">{{ (detalle.archivos || []).length }}</span></h4>
              <a v-if="archivosUrl" :href="archivosUrl">Ir a Archivos <i class="fas fa-arrow-right"></i></a>
            </div>
            <ul v-if="(detalle.archivos || []).length" class="pry-arch">
              <li v-for="a in detalle.archivos" :key="a.id">
                <i :class="iconoArchivo(a)"></i>
                <a :href="`${storageUrl}/${a.path}`" target="_blank" rel="noopener" :title="`Abrir ${a.nombre}`">{{ a.nombre }}</a>
                <small>{{ a.ensayo || 'Proyecto' }}<template v-if="a.carpeta"> · {{ a.carpeta }}</template><template v-if="a.fecha"> · {{ fecha(a.fecha) }}</template></small>
              </li>
            </ul>
            <p v-else class="pry-vacio pry-arch-vacio">Sin archivos. Se suben en Archivos (al proyecto) o en el detalle de cada ensayo.</p>
          </section>

          <!-- Equipo -->
          <section class="pry-sec">
            <div class="pry-sec-h">
              <h4><i class="fas fa-users"></i> Equipo <span class="pry-badge">{{ (detalle.equipo || []).length }}</span></h4>
            </div>
            <ul class="pry-equipo">
              <li v-if="detalle.responsable" class="is-resp">
                <i class="pry-ini">{{ iniciales(detalle.responsable.name) }}</i>
                <span>{{ detalle.responsable.name }}</span><em>Responsable</em>
              </li>
              <li v-for="a in detalle.equipo" :key="a.id">
                <i class="pry-ini">{{ iniciales(a.nombre) }}</i>
                <span>{{ a.nombre }}</span><em>{{ a.rol }}</em>
                <button v-if="puedeEditar" type="button" class="pry-icon is-danger" :title="`Quitar a ${a.nombre}`" @click="quitarIntegrante(a)"><i class="fas fa-times"></i></button>
              </li>
            </ul>
            <form v-if="puedeEditar" class="pry-agregar" @submit.prevent="agregarIntegrante">
              <select class="form-control form-control-sm" v-model="nuevoIntegrante.usuario_id" aria-label="Persona">
                <option :value="null">Agregar persona...</option>
                <option v-for="u in disponibles" :key="u.id" :value="u.id">{{ u.name }}</option>
              </select>
              <select class="form-control form-control-sm" v-model="nuevoIntegrante.rol" aria-label="Rol">
                <option v-for="r in rolesEquipo" :key="r" :value="r">{{ r }}</option>
              </select>
              <button type="submit" class="btn btn-sm btn-success" :disabled="!nuevoIntegrante.usuario_id || guardandoEquipo"><i class="fas fa-user-plus"></i></button>
            </form>
            <p class="pry-nota">Quien esta en el equipo ve este proyecto y sus ensayos aunque no tenga asignado el programa.</p>
          </section>
        </div>
      </aside>
    </transition>

    <!-- ============ Crear / editar ============ -->
    <div class="modal fade" ref="modal" tabindex="-1">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <form @submit.prevent="guardar">
            <div class="modal-header">
              <h5 class="modal-title"><i class="fas fa-folder-open text-success mr-1"></i> {{ editando ? 'Editar proyecto' : 'Nuevo proyecto' }}</h5>
              <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
              <div class="row">
                <div class="col-md-6 form-group">
                  <label>Programa <span class="pry-req">*</span></label>
                  <select class="form-control" :class="{ 'is-invalid': errors.programa_id }" v-model="form.programa_id">
                    <option :value="null">Seleccione...</option>
                    <option v-for="prog in programasItems" :key="prog.id" :value="prog.id">{{ prog.sigla ? prog.sigla + ' · ' : '' }}{{ prog.nombre }}</option>
                  </select>
                  <span class="invalid-feedback" v-if="errors.programa_id">{{ errors.programa_id[0] }}</span>
                </div>
                <div class="col-md-6 form-group">
                  <label>Codigo <small class="text-muted">(automatico)</small></label>
                  <div class="pry-codigo" :class="{ 'is-cambia': codigo.cambia, 'is-bloqueo': codigo.bloqueo }">
                    <i class="fas fa-hashtag"></i>
                    <b>{{ codigo.valor || 'Elija el programa' }}</b>
                  </div>
                  <small class="form-text text-muted" v-if="!codigo.cambia">Programa · año de inicio · consecutivo</small>
                  <small class="form-text pry-aviso" v-else-if="codigo.bloqueo"><i class="fas fa-lock"></i> {{ codigo.bloqueo }}</small>
                  <small class="form-text pry-aviso" v-else><i class="fas fa-sync-alt"></i> Antes {{ editando.codigo }}: tambien se recodifican sus ensayos y muestras.</small>
                </div>
              </div>
              <div class="form-group">
                <label>Nombre <span class="pry-req">*</span></label>
                <input type="text" maxlength="200" class="form-control" :class="{ 'is-invalid': errors.nombre }" v-model="form.nombre" placeholder="Ej. Evaluacion de madurantes en variedades de ciclo intermedio">
                <span class="invalid-feedback" v-if="errors.nombre">{{ errors.nombre[0] }}</span>
              </div>
              <div class="form-group">
                <label>Objetivo</label>
                <textarea class="form-control" maxlength="2000" v-model="form.objetivo" rows="2" placeholder="Que busca responder el proyecto"></textarea>
              </div>
              <div class="row">
                <div class="col-md-6 form-group">
                  <label>Responsable</label>
                  <select class="form-control" v-model="form.responsable_id">
                    <option :value="null">Sin asignar</option>
                    <option v-for="u in responsables" :key="u.id" :value="u.id">{{ u.name }}</option>
                  </select>
                </div>
                <div class="col-md-6 form-group">
                  <label>Estado</label>
                  <select class="form-control" v-model="form.estado">
                    <option v-for="e in ESTADOS" :key="e" :value="e">{{ e }}</option>
                  </select>
                </div>
              </div>
              <div class="row">
                <div class="col-md-6 form-group">
                  <label>Inicio</label>
                  <input type="date" class="form-control" v-model="form.inicio">
                  <small class="form-text text-muted">Su año forma parte del codigo.</small>
                </div>
                <div class="col-md-6 form-group">
                  <label>Fin previsto</label>
                  <input type="date" class="form-control" :class="{ 'is-invalid': errors.fin }" v-model="form.fin">
                  <span class="invalid-feedback" v-if="errors.fin">{{ errors.fin[0] }}</span>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-success" :disabled="guardando"><i class="fas fa-save"></i> Guardar</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <programa-modal v-if="puedeGestionarProgramas" ref="programaModal" :programas="programasItems" :paleta="paleta" @guardado="programaGuardado"></programa-modal>
  </div>
</template>

<script>
import ProgramaModal, { TONOS } from '../programas/programa-modal.vue';
import { zafraDe, zafraActual, zafrasEntre, periodoProyecto, situacionEnZafra, SITUACIONES } from '../../../zafra';

const DIA = 86400000;

export default {
  name: 'ProyectosIndex',
  components: { ProgramaModal },
  props: {
    proyectos: { type: Array, default: () => [] },
    // Programas con conteos y resumen (ProgramasController::listado).
    programas: { type: Array, default: () => [] },
    paleta: { type: Array, default: () => Object.keys(TONOS) },
    puedeGestionarProgramas: { type: Boolean, default: false },
    responsables: { type: Array, default: () => [] },
    rolesEquipo: { type: Array, default: () => [] },
    diasSinActividad: { type: Number, default: 30 },
    puedeCrear: { type: Boolean, default: false },
    puedeEditar: { type: Boolean, default: false },
    puedeEliminar: { type: Boolean, default: false },
    puedeCrearEnsayo: { type: Boolean, default: false },
    ensayosUrl: { type: String, default: '' },
    evaluacionesUrl: { type: String, default: null },
    muestrasUrl: { type: String, default: null },
    programasUrl: { type: String, default: '' },
    archivosUrl: { type: String, default: '' },
    storageUrl: { type: String, default: '' },
  },
  data() {
    const qs = new URLSearchParams(window.location.search);
    return {
      ESTADOS: ['Activo', 'Pausado', 'Finalizado'],
      // Paleta validada (CVD) de estados de ensayo, igual que dashboard y programas.
      ESTADOS_ENSAYO: [
        { id: 'Planificado', color: '#eda100', texto: '#1f2a12' },
        { id: 'En campo', color: '#5a9e1a', texto: '#ffffff' },
        { id: 'Finalizado', color: '#2a78d6', texto: '#ffffff' },
      ],
      items: this.proyectos.slice(),
      programasItems: this.programas.slice(),
      busqueda: '',
      filtroEstado: '',
      filtroRapido: '',
      SITUACIONES,
      zafraHoy: zafraActual(),
      filtroZafra: /^\d{4}-\d{4}$/.test(qs.get('zafra') || '') ? qs.get('zafra') : '',
      // ?programa=ID llega desde Programas.
      filtroPrograma: Number(qs.get('programa')) || null,
      orden: 'codigo',
      detalle: null,
      editando: null,
      form: this.formVacio(),
      errors: {},
      guardando: false,
      codigo: { valor: '', cambia: false, bloqueo: null },
      nuevoIntegrante: { usuario_id: null, rol: this.rolesEquipo[1] || this.rolesEquipo[0] || '' },
      guardandoEquipo: false,
    };
  },
  computed: {
    hoy() { const d = new Date(); return Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()); },
    programaFiltrado() { return this.programasItems.find((p) => p.id === this.filtroPrograma) || null; },
    // Primero los programas con proyectos; dentro de cada grupo, por sigla.
    programasOrdenados() {
      const sigla = (a, b) => String(a.sigla || a.nombre).localeCompare(String(b.sigla || b.nombre), 'es');
      return this.programasItems.slice().sort((a, b) => (this.proyectosDe(b.id) > 0) - (this.proyectosDe(a.id) > 0) || sigla(a, b));
    },
    /** Proyectos filtrados agrupados por programa (en orden de sigla), conservando el orden elegido. */
    grupos() {
      const orden = this.programasItems.slice().sort((a, b) => String(a.sigla || a.nombre).localeCompare(String(b.sigla || b.nombre), 'es'));
      const grupos = orden.map((pg) => ({ id: pg.id, programa: pg, items: [] }));
      const sinPrograma = { id: 0, programa: null, items: [] };
      this.proyectosFiltrados.forEach((p) => (grupos.find((g) => g.id === p.programa_id) || sinPrograma).items.push(p));
      return [...grupos, sinPrograma].filter((g) => g.items.length);
    },
    hayFiltros() { return !!(this.busqueda.trim() || this.filtroEstado || this.filtroRapido || this.filtroPrograma || this.filtroZafra); },
    zafrasDisponibles() {
      const inicios = this.items.map((p) => zafraDe(periodoProyecto(p).inicio)).filter(Boolean).sort();
      return inicios.length ? zafrasEntre(inicios[0], [inicios[inicios.length - 1], this.zafraHoy].sort().pop()).reverse() : [this.zafraHoy];
    },
    resumen() {
      const activos = this.items.filter((p) => p.estado === 'Activo');
      const enCampo = this.items.reduce((s, p) => s + ((p.ensayos_estado || {})['En campo'] || 0), 0);
      return [
        { id: 'activos', label: 'Proyectos activos', valor: activos.length, sub: `${this.items.filter((p) => p.estado === 'Pausado').length} pausados · ${this.items.filter((p) => p.estado === 'Finalizado').length} finalizados`, icon: 'fas fa-folder-open', color: '#73BC25' },
        { id: 'campo', label: 'Con ensayos en campo', valor: this.items.filter((p) => (p.ensayos_estado || {})['En campo']).length, sub: `${enCampo} ensayo(s) en campo`, icon: 'fas fa-seedling', color: '#A3D300' },
        { id: 'vence', label: 'Por vencer o vencidos', valor: this.items.filter((p) => this.porVencer(p)).length, sub: 'fin previsto en 30 dias o ya pasado', icon: 'far fa-calendar-times', color: '#FFCC00' },
        { id: 'quietos', label: 'Sin actividad reciente', valor: this.items.filter((p) => this.sinActividad(p)).length, sub: `ensayos en campo sin movimiento en ${this.diasSinActividad} dias`, icon: 'fas fa-hourglass-half', color: '#FF6B00' },
      ];
    },
    proyectosFiltrados() {
      const q = this.normalizar(this.busqueda.trim());
      const rapido = {
        '': () => true,
        activos: (p) => p.estado === 'Activo',
        campo: (p) => (p.ensayos_estado || {})['En campo'] > 0,
        vence: (p) => this.porVencer(p),
        quietos: (p) => this.sinActividad(p),
      }[this.filtroRapido];
      const lista = this.items.filter((p) => rapido(p)
        && (!this.filtroEstado || p.estado === this.filtroEstado)
        && (!this.filtroPrograma || p.programa_id === this.filtroPrograma)
        && (!this.filtroZafra || !!this.situacion(p))
        && (!q || this.normalizar([
          p.codigo, p.nombre, p.objetivo, p.programa && p.programa.nombre, p.responsable && p.responsable.name,
          ...(p.equipo || []).map((a) => a.nombre),
          ...(p.ensayos || []).map((e) => `${e.codigo} ${e.nombre || ''} ${e.finca || ''}`),
        ].join(' ')).includes(q)));
      const por = {
        codigo: (a, b) => a.codigo.localeCompare(b.codigo, 'es'),
        vence: (a, b) => String(a.fin || '9999').localeCompare(String(b.fin || '9999')),
        actividad: (a, b) => String(b.ultima_actividad || '').localeCompare(String(a.ultima_actividad || '')),
        ensayos: (a, b) => (b.ensayos_count || 0) - (a.ensayos_count || 0) || a.codigo.localeCompare(b.codigo, 'es'),
      };
      return lista.sort(por[this.orden]);
    },
    disponibles() {
      if (!this.detalle) return [];
      const usados = (this.detalle.equipo || []).map((a) => a.usuario_id);
      return this.responsables.filter((u) => !usados.includes(u.id));
    },
  },
  mounted() {
    // Desde Programas: ?programa=ID&nuevo=1 abre el alta con el programa ya elegido.
    const qs = new URLSearchParams(window.location.search);
    if (qs.get('nuevo') === '1' && this.puedeCrear) this.$nextTick(() => this.abrirCrear());
    // Desde el detalle de un ensayo: ?proyecto=ID abre la ficha de su proyecto.
    const proyecto = this.items.find((p) => p.id === Number(qs.get('proyecto')));
    if (proyecto) this.abrirDetalle(proyecto);
    window.addEventListener('keydown', this.onEsc);
  },
  beforeDestroy() { window.removeEventListener('keydown', this.onEsc); },
  watch: {
    // El programa elegido queda en la URL (?programa=ID) para compartir o volver al mismo punto.
    filtroPrograma(id) {
      try {
        const url = new URL(window.location.href);
        if (id) url.searchParams.set('programa', id); else url.searchParams.delete('programa');
        url.searchParams.delete('nuevo');
        window.history.replaceState(null, '', url);
      } catch (e) { /* navegador sin URL API */ }
    },
    'form.programa_id': 'previsualizarCodigo',
    'form.inicio': 'previsualizarCodigo',
  },
  methods: {
    normalizar(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); },
    n(x) { return Number(x || 0).toLocaleString('es-GT'); },
    fecha(f) { if (!f) return ''; const [y, m, d] = String(f).slice(0, 10).split('-'); return `${d}/${m}/${y}`; },
    dia(f) { return f ? Date.parse(String(f).slice(0, 10)) : null; },
    relativo(f) {
      const dias = Math.floor((this.hoy - this.dia(f)) / DIA);
      if (dias <= 0) return 'Hoy';
      if (dias === 1) return 'Ayer';
      if (dias < 60) return `Hace ${dias} dias`;
      return `Hace ${Math.round(dias / 30)} meses`;
    },
    esViejo(f) { return !f || this.hoy - this.dia(f) > this.diasSinActividad * DIA; },
    iniciales(nombre) { return String(nombre || '').trim().split(/\s+/).slice(0, 2).map((w) => w[0].toUpperCase()).join(''); },
    colorPrograma(p) { const prog = this.programasItems.find((x) => x.id === p.programa_id); return (prog && prog.color) || '#CED2D5'; },
    estiloPrograma(pg) {
      const c = (pg && pg.color && TONOS[String(pg.color).toUpperCase()]) ? pg.color : '#CED2D5';
      return { '--pg': c, '--pg-osc': TONOS[String(c).toUpperCase()].oscuro };
    },
    proyectosDe(id) { return this.items.filter((p) => p.programa_id === id).length; },
    iconoArchivo(a) {
      const ext = String(a.nombre || '').split('.').pop().toLowerCase();
      if (ext === 'pdf') return 'far fa-file-pdf';
      if (['xls', 'xlsx', 'csv'].includes(ext)) return 'far fa-file-excel';
      if (['doc', 'docx'].includes(ext)) return 'far fa-file-word';
      if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff'].includes(ext)) return 'far fa-file-image';
      return 'far fa-file';
    },
    enCampo(pg) { return (pg.ensayos_estado || {})['En campo'] || 0; },
    elegirPrograma(id) { this.filtroPrograma = this.filtroPrograma === id ? null : id; },
    programaGuardado(data, editado) {
      // Cambio de sigla: se recodificaron proyectos, ensayos y muestras; se recarga para verlos.
      if (data.recodificados) { window.location.reload(); return; }
      const idx = this.programasItems.findIndex((x) => x.id === data.id);
      if (idx >= 0) this.$set(this.programasItems, idx, data); else this.programasItems.push(data);
      // Los proyectos traen su programa embebido (nombre/sigla del detalle).
      if (editado) this.items.forEach((p) => { if (p.programa_id === data.id && p.programa) Object.assign(p.programa, { nombre: data.nombre, sigla: data.sigla, color: data.color }); });
      else this.filtroPrograma = data.id;
    },
    eliminarPrograma(pg) {
      const n = this.proyectosDe(pg.id) || pg.proyectos_count || 0;
      if (n) {
        this.$swal.fire({ icon: 'info', title: 'No se puede eliminar', html: `<b>${this.escapar(pg.nombre)}</b> tiene ${n} proyecto(s).<br>Reasigne o elimine esos proyectos primero.`, confirmButtonColor: '#73BC25' });
        return;
      }
      this.$swal.fire({
        title: 'Eliminar programa', html: `Se eliminara <b>${this.escapar(pg.nombre)}</b>. Esta accion no se puede deshacer.`, icon: 'warning',
        showCancelButton: true, confirmButtonText: 'Si, eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#FF6B00', cancelButtonColor: '#CED2D5', focusCancel: true,
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/investigacion/programas/${pg.id}`)
          .then(() => {
            this.programasItems = this.programasItems.filter((x) => x.id !== pg.id);
            if (this.filtroPrograma === pg.id) this.filtroPrograma = null;
            this.$swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Programa eliminado', timer: 2000, showConfirmButton: false });
          })
          .catch((err) => this.$swal.fire({ icon: 'error', title: 'No se pudo eliminar', text: (err.response && err.response.data && err.response.data.message) || 'Ocurrio un error.' }));
      });
    },
    escapar(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; },
    estiloEstado(estado) { const s = this.ESTADOS_ENSAYO.find((x) => x.id === estado); return s ? { background: s.color, color: s.texto } : {}; },
    // Porcentaje del periodo (inicio-fin) ya transcurrido.
    avance(p) {
      const a = this.dia(p.inicio); const b = this.dia(p.fin);
      if (!a || !b || b <= a) return null;
      return Math.max(0, Math.min(100, Math.round(((this.hoy - a) / (b - a)) * 100)));
    },
    diasParaFin(p) { const b = this.dia(p.fin); return b ? Math.round((b - this.hoy) / DIA) : null; },
    vencido(p) { return p.estado !== 'Finalizado' && this.diasParaFin(p) !== null && this.diasParaFin(p) < 0; },
    porVencer(p) { return p.estado !== 'Finalizado' && this.diasParaFin(p) !== null && this.diasParaFin(p) <= 30; },
    sinActividad(p) { return (p.ensayos_estado || {})['En campo'] > 0 && this.esViejo(p.ultima_actividad); },
    limpiar() { this.busqueda = ''; this.filtroEstado = ''; this.filtroRapido = ''; this.filtroPrograma = null; this.filtroZafra = ''; },
    situacion(p) { return this.filtroZafra ? situacionEnZafra(periodoProyecto(p), this.filtroZafra) : null; },
    estiloSituacion(p) { const s = SITUACIONES[this.situacion(p)]; return { background: s.fondo, color: s.color, borderColor: s.color }; },
    abrirDetalle(p) { this.detalle = p; this.nuevoIntegrante.usuario_id = null; },
    onEsc(e) {
      if (e.key === 'Escape' && this.detalle && !document.body.classList.contains('modal-open')) this.detalle = null;
    },
    reemplazar(data) {
      const idx = this.items.findIndex((i) => i.id === data.id);
      if (idx >= 0) this.$set(this.items, idx, data); else this.items.push(data);
      if (this.detalle && this.detalle.id === data.id) this.detalle = data;
    },
    // Codigo que asignara el servidor (SIGLA-AA-NN); solo informativo.
    previsualizarCodigo() {
      if (!this.form.programa_id) { this.codigo = { valor: '', cambia: false, bloqueo: null }; return; }
      const params = { programa_id: this.form.programa_id, inicio: this.form.inicio || undefined, proyecto_id: this.editando ? this.editando.id : undefined };
      const pedido = (this.pedidoCodigo = {});
      window.axios.get('/investigacion/proyectos/codigo', { params }).then(({ data }) => {
        if (pedido === this.pedidoCodigo) this.codigo = { valor: data.codigo, cambia: !!data.cambia, bloqueo: data.bloqueo || null };
      }).catch(() => {});
    },
    formVacio() {
      return { nombre: '', programa_id: null, responsable_id: null, objetivo: '', inicio: '', fin: '', estado: 'Activo' };
    },
    abrirCrear(programaId = null) {
      this.editando = null;
      this.form = { ...this.formVacio(), programa_id: (typeof programaId === 'number' && programaId) || this.filtroPrograma };
      this.errors = {};
      this.previsualizarCodigo();
      window.$(this.$refs.modal).modal('show');
    },
    abrirEditar(p) {
      this.editando = p;
      this.form = {
        nombre: p.nombre, programa_id: p.programa_id, responsable_id: p.responsable_id,
        objetivo: p.objetivo || '', inicio: p.inicio || '', fin: p.fin || '', estado: p.estado,
      };
      this.errors = {};
      this.previsualizarCodigo();
      window.$(this.$refs.modal).modal('show');
    },
    guardar() {
      this.guardando = true;
      this.errors = {};
      const payload = { ...this.form };
      ['inicio', 'fin', 'objetivo'].forEach((k) => { if (payload[k] === '') payload[k] = null; });
      const peticion = this.editando
        ? window.axios.put(`/investigacion/proyectos/${this.editando.id}`, payload)
        : window.axios.post('/investigacion/proyectos', payload);

      peticion
        .then(({ data }) => {
          this.reemplazar(data);
          window.$(this.$refs.modal).modal('hide');
          this.$swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: this.editando ? 'Proyecto actualizado' : 'Proyecto creado',
            text: data.recodificados ? `Codigo ${data.codigo}: ${data.recodificados} codigo(s) actualizados` : `Codigo ${data.codigo}`,
            timer: 2200,
            showConfirmButton: false,
          });
        })
        .catch((err) => {
          if (err.response && err.response.status === 422) {
            this.errors = err.response.data.errors || {};
            if (!err.response.data.errors && err.response.data.message) {
              this.$swal.fire({ icon: 'warning', title: 'No se puede cambiar el codigo', text: err.response.data.message });
            }
          } else {
            this.$swal.fire({ icon: 'error', title: 'Ocurrio un error' });
          }
        })
        .finally(() => { this.guardando = false; });
    },
    eliminar(p) {
      if (p.ensayos_count) {
        this.$swal.fire({
          icon: 'info',
          title: 'No se puede eliminar',
          text: `${p.codigo} tiene ${p.ensayos_count} ensayo(s). Para conservar su informacion, cambie el estado del proyecto a "Finalizado".`,
        });
        return;
      }
      this.$swal.fire({
        title: `¿Eliminar ${p.codigo}?`,
        text: p.nombre,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        confirmButtonColor: '#FF6B00',
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/investigacion/proyectos/${p.id}`).then(() => {
          this.items = this.items.filter((i) => i.id !== p.id);
          if (this.detalle && this.detalle.id === p.id) this.detalle = null;
          this.$swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Proyecto eliminado', timer: 1800, showConfirmButton: false });
        }).catch((err) => {
          this.$swal.fire({ icon: 'error', title: 'No se pudo eliminar', text: (err.response && err.response.data && err.response.data.message) || 'Intente de nuevo.' });
        });
      });
    },
    agregarIntegrante() {
      this.guardandoEquipo = true;
      window.axios.post(`/investigacion/proyectos/${this.detalle.id}/equipo`, this.nuevoIntegrante)
        .then(({ data }) => { this.reemplazar(data); this.nuevoIntegrante.usuario_id = null; })
        .catch((err) => {
          this.$swal.fire({ icon: 'error', title: 'No se pudo agregar', text: (err.response && err.response.data && err.response.data.message) || 'Intente de nuevo.' });
        })
        .finally(() => { this.guardandoEquipo = false; });
    },
    quitarIntegrante(a) {
      this.$swal.fire({
        title: `¿Quitar a ${a.nombre} del equipo?`,
        text: 'Dejara de ver el proyecto si no tiene asignado su programa.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Quitar',
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/investigacion/proyectos/${this.detalle.id}/equipo/${a.id}`)
          .then(({ data }) => this.reemplazar(data))
          .catch(() => this.$swal.fire({ icon: 'error', title: 'No se pudo quitar' }));
      });
    },
  },
};
</script>

<style scoped>
.pry { --verde: #73BC25; --verde-osc: #3f7a12; --borde: #e3e7e4; --suave: #5f6b66; --tinta: #1f2a1a; }

/* ---------- Programas (primer nivel) ---------- */
.pp-progs { margin-bottom: 14px; }
/* Una sola fila de pestanas; si no caben, se desplaza horizontalmente. */
.pp-tabs {
  display: flex; align-items: center; gap: 6px; padding: 6px; overflow-x: auto; border: 1px solid var(--borde); border-radius: 12px; background: #fff;
  scrollbar-width: thin;
}
.pp-tabs-l { flex: none; padding: 0 6px 0 4px; color: var(--suave); font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; white-space: nowrap; }
.pp-tabs-l i { margin-right: 3px; color: var(--verde); }
.pp-tab {
  --pg: #CED2D5; --pg-osc: #5b6267;
  display: inline-flex; flex: none; align-items: center; gap: 6px; height: 34px; padding: 0 10px; border: 1px solid transparent; border-radius: 9px;
  background: transparent; color: #33433a; font-size: 12.5px; white-space: nowrap; cursor: pointer; transition: background .12s, border-color .12s;
}
.pp-tab:hover { background: #f4f7f1; }
.pp-tab:focus-visible { outline: 2px solid rgba(115, 188, 37, .55); outline-offset: 1px; }
.pp-tab b { color: var(--pg-osc); font-family: 'Space Grotesk', sans-serif; font-size: 12.5px; }
.pp-tab-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--pg); }
.pp-tab-n { max-width: 130px; overflow: hidden; color: var(--suave); text-overflow: ellipsis; }
.pp-tab small { min-width: 20px; padding: 1px 6px; border-radius: 999px; background: #f0f2f0; color: #5b6267; font-size: 11px; font-weight: 700; text-align: center; }
.pp-tab-campo { color: #5a9e1a; font-size: 11px; }
.pp-tab.is-vacio { opacity: .62; }
.pp-tab.is-vacio:hover, .pp-tab.is-vacio.is-on { opacity: 1; }
.pp-tab.is-on { border-color: var(--pg); background: color-mix(in srgb, var(--pg) 14%, #fff); }
.pp-tab.is-on .pp-tab-n { color: var(--tinta); }
.pp-tab.is-on small { background: var(--pg); color: #fff; }
.pp-tab.is-todos { --pg: #73BC25; --pg-osc: #3f7a12; font-weight: 700; }
.pp-tab.is-nuevo { width: 34px; justify-content: center; margin-left: auto; border: 1px dashed #CED2D5; color: var(--verde); }
.pp-tab.is-nuevo:hover { border-color: var(--verde); background: #f3f9ea; }
/* Ficha del programa elegido: una franja delgada */
.pp-ficha {
  --pg: #CED2D5; --pg-osc: #5b6267;
  display: flex; align-items: center; gap: 12px; margin-top: 8px; padding: 8px 12px; border: 1px solid color-mix(in srgb, var(--pg) 45%, #fff);
  border-left: 4px solid var(--pg); border-radius: 10px; background: color-mix(in srgb, var(--pg) 7%, #fff);
}
.pp-ficha-av { display: grid; flex: none; place-items: center; width: 36px; height: 36px; border-radius: 9px; background: color-mix(in srgb, var(--pg) 22%, #fff); color: var(--pg-osc); font-family: 'Space Grotesk', sans-serif; font-size: 12px; font-weight: 700; }
.pp-ficha-txt { flex: 1; min-width: 0; line-height: 1.3; }
.pp-ficha-txt b { color: var(--tinta); font-size: 13.5px; }
.pp-ficha-txt small { display: block; color: var(--suave); font-size: 11.5px; }
.pp-ficha-txt small i { color: var(--pg-osc); }
.pp-ficha-txt p { margin: 2px 0 0; overflow: hidden; color: #4d5a48; font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
.pp-ficha-acc { display: flex; flex: none; align-items: center; gap: 4px; }
.pp-ficha .btn small { opacity: .85; font-weight: 500; }
@media (max-width: 767px) {
  .pp-ficha { flex-wrap: wrap; }
  .pp-ficha-acc { width: 100%; justify-content: flex-end; }
  .pp-tab-n { display: none; }
}

/* ---------- Grupos por programa en la lista ---------- */
.pry-grupo {
  --pg: #CED2D5; --pg-osc: #5b6267;
  display: flex; align-items: center; gap: 10px; padding: 8px 16px; border-bottom: 1px solid #edf1ec;
  background: color-mix(in srgb, var(--pg) 8%, #fff);
}
.pry-grupo a { display: inline-flex; align-items: center; gap: 8px; color: var(--pg-osc); font-size: 13px; font-weight: 700; }
.pry-grupo-av { padding: 1px 7px; border-radius: 6px; background: var(--pg); color: #fff; font-family: 'Space Grotesk', sans-serif; font-size: 11px; }
.pry-grupo small { color: var(--suave); font-size: 11.5px; }
.pry-grupo-add { margin-left: auto; padding: 2px 9px; border: 1px solid color-mix(in srgb, var(--pg) 50%, #fff); border-radius: 7px; background: #fff; color: var(--pg-osc); font-size: 11.5px; font-weight: 600; }
.pry-grupo-add:hover { border-color: var(--pg); }

/* ---------- Archivos en el detalle ---------- */
.pry-arch { margin: 0; padding: 0; list-style: none; }
.pry-arch li { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 8px; padding: 6px 2px; border-bottom: 1px dashed #edf1ec; font-size: 12.5px; }
.pry-arch li:last-child { border-bottom: 0; }
.pry-arch li > i { color: var(--verde); }
.pry-arch a { color: var(--tinta); font-weight: 600; word-break: break-word; }
.pry-arch a:hover { color: var(--verde-osc); text-decoration: underline; }
.pry-arch small { color: var(--suave); }
.pry-arch-vacio { margin: 0; font-size: 12px; }

/* ---------- Ruta en el detalle ---------- */
.pry-migas { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-bottom: 4px; font-size: 12px; }
.pry-migas a { color: var(--verde-osc); font-weight: 600; }
.pry-migas > i { color: #CED2D5; font-size: 9px; }

/* Resumen */
.pry-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 16px; }
.pry-stat { display: flex; align-items: center; gap: 14px; padding: 14px 16px; text-align: left; font: inherit; background: #fff; border: 1px solid var(--borde); border-left: 4px solid var(--acento); border-radius: 12px; cursor: pointer; transition: box-shadow .15s, border-color .15s; }
.pry-stat:hover { border-color: var(--acento); }
.pry-stat:focus-visible { outline: 3px solid #A3D300; outline-offset: 2px; }
.pry-stat.is-on { border-color: var(--acento); box-shadow: 0 0 0 2px color-mix(in srgb, var(--acento) 35%, transparent); }
.pry-stat-icon { flex: none; width: 42px; height: 42px; border-radius: 11px; display: grid; place-items: center; background: color-mix(in srgb, var(--acento) 16%, #fff); color: color-mix(in srgb, var(--acento) 70%, #1f2a1a); font-size: 17px; }
.pry-stat strong { display: block; font-size: 24px; line-height: 1.1; color: var(--tinta); font-family: 'Space Grotesk', sans-serif; }
.pry-stat b { display: block; font-size: 13px; color: var(--tinta); }
.pry-stat small { display: block; font-size: 11px; color: var(--suave); line-height: 1.3; }

/* Herramientas */
.pry-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 12px; margin-bottom: 12px; background: #fff; border: 1px solid var(--borde); border-radius: 12px; }
.pry-search { position: relative; flex: 1 1 260px; }
.pry-search i { position: absolute; top: 50%; left: 13px; transform: translateY(-50%); color: #8a9b8e; font-size: 12px; pointer-events: none; }
.pry-search input { padding-left: 34px; }
.pry-sel { flex: 0 1 190px; width: auto; }
.pry-nuevo { margin-left: auto; white-space: nowrap; }
.pry-resultados { margin: -2px 2px 10px; color: var(--suave); font-size: 12px; }
.pry-resultados a { margin-left: 6px; font-weight: 700; }
.pry-leyenda { display: flex; flex-wrap: wrap; gap: 14px; margin: 0 2px 10px; color: var(--suave); font-size: 11.5px; }
.pry-leyenda i { display: inline-block; width: 10px; height: 10px; margin-right: 5px; border-radius: 3px; vertical-align: -1px; }
.pry-leyenda-tip { margin-left: auto; }
.pry-leyenda-tip i { width: auto; height: auto; background: none; }

/* Lista */
.pry-lista { background: #fff; border: 1px solid var(--borde); border-radius: 12px; overflow: hidden; }
.pry-head, .pry-fila { display: grid; grid-template-columns: minmax(0, 2.3fr) minmax(0, 1.1fr) minmax(130px, 1fr) minmax(100px, .9fr) 70px 120px 150px; gap: 12px; align-items: center; padding: 0 16px; }
.pry-head { min-height: 42px; background: #f7faf3; border-bottom: 1px solid var(--borde); color: var(--suave); font-size: 10.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
.pry-fila { position: relative; min-height: 70px; padding-top: 10px; padding-bottom: 10px; border-bottom: 1px solid #edf1ec; cursor: pointer; transition: background .15s; }
.pry-fila:last-child { border-bottom: 0; }
.pry-fila::before { content: ''; position: absolute; left: 0; top: 10px; bottom: 10px; width: 4px; border-radius: 0 4px 4px 0; background: var(--prog); }
.pry-fila:hover { background: #fafcf7; }
.pry-fila:focus-visible { outline: 3px solid #A3D300; outline-offset: -3px; }
.pry-c { text-align: center; }
.pry-main { min-width: 0; }
.pry-main b, .pry-main small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pry-main b { font-size: 13.5px; color: var(--tinta); }
.pry-main small { font-size: 11.5px; color: var(--suave); }
.pry-cod { font-family: 'Space Grotesk', sans-serif; font-size: 12px; font-weight: 700; letter-spacing: .03em; color: var(--verde-osc); }
.pry-zafra-tag { display: inline-block; margin-left: 6px; padding: 0 7px; border: 1px solid; border-radius: 8px; font-family: inherit; font-size: 10px; letter-spacing: 0; vertical-align: 1px; }
.pry-resp { min-width: 0; font-size: 12.5px; }
.pry-resp span, .pry-resp small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pry-resp i { color: var(--suave); font-size: 11px; margin-right: 3px; }
.pry-resp small { color: var(--suave); font-size: 11px; }
.pry-periodo { font-size: 12px; color: var(--tinta); }
.pry-periodo small { display: block; font-size: 11px; color: var(--suave); }
.pry-tiempo { height: 5px; margin: 4px 0 3px; background: #edf0ee; border-radius: 99px; overflow: hidden; }
.pry-tiempo.is-grande { height: 8px; }
.pry-tiempo i { display: block; height: 100%; background: var(--verde); border-radius: 99px; }
.pry-tiempo i.is-vencido { background: #FF6B00; }
.is-alerta { color: #b84d00 !important; font-weight: 600; }
.pry-apilada { display: flex; gap: 2px; height: 20px; }
.pry-apilada span { flex-basis: 0; min-width: 20px; display: grid; place-items: center; border-radius: 4px; font-size: 11px; font-weight: 700; }
.pry-act { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0 5px; font-size: 12.5px; }
.pry-act small { flex-basis: 100%; color: var(--suave); font-size: 11px; }
.pry-act i { font-size: 11px; }
.pry-act.is-alerta i { color: #b84d00; }
.pry-vacio { color: #8a9396; font-style: italic; font-size: 12px; }
.pry-acciones { display: flex; align-items: center; justify-content: flex-end; gap: 2px; }
.pry-estado { display: inline-block; margin-right: 4px; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; background: #f1f3f0; color: #5b6267; }
.pry-estado.is-activo { background: #eef8df; color: var(--verde-osc); }
.pry-estado.is-pausado { background: #fff6d1; color: #8a6a00; }
.pry-icon { display: inline-grid; place-items: center; width: 30px; height: 30px; border: 0; border-radius: 8px; background: transparent; color: var(--suave); font-size: 13px; }
.pry-icon:hover { background: #eef7e3; color: var(--verde-osc); text-decoration: none; }
.pry-icon.is-danger:hover { background: #fff0e5; color: #c24f00; }
.pry-icon.is-locked { opacity: .45; }
.pry-vacio-box { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 34px 16px; background: #fff; border: 1px dashed var(--borde); border-radius: 12px; color: var(--suave); text-align: center; }
.pry-vacio-box > i { font-size: 24px; color: var(--verde); }
.pry-vacio-box.is-mini { flex-direction: row; justify-content: center; padding: 14px; font-size: 12.5px; }
.pry-vacio-box.is-mini > i { font-size: 14px; }

/* Panel */
.pry-velo { position: fixed; inset: 0; z-index: 1064; background: rgba(23, 33, 18, .35); }
.pry-panel { position: fixed; top: 0; right: 0; bottom: 0; z-index: 1065; width: min(600px, 100vw); display: flex; flex-direction: column; background: #fff; border-left: 5px solid var(--prog); box-shadow: -12px 0 32px rgba(23, 33, 18, .18); }
.pry-panel-h { display: flex; align-items: flex-start; gap: 8px; padding: 16px 18px; border-bottom: 1px solid var(--borde); background: color-mix(in srgb, var(--prog) 7%, #fff); }
.pry-panel-t { flex: 1; min-width: 0; }
.pry-panel-t h3 { margin: 2px 0 4px; font-size: 17px; font-weight: 700; color: var(--tinta); }
.pry-panel-t small { color: var(--suave); font-size: 12.5px; }
.pry-panel-t small a { color: var(--verde-osc); font-weight: 600; }
.pry-panel-b { flex: 1; overflow-y: auto; padding: 16px 18px 24px; }
.pry-objetivo { font-size: 13px; color: var(--tinta); margin-bottom: 12px; }
.pry-crono { padding: 10px 12px; margin-bottom: 12px; border: 1px solid var(--borde); border-radius: 10px; background: #fafbf8; font-size: 12.5px; }
.pry-crono-h { display: flex; justify-content: space-between; gap: 10px; color: var(--tinta); }
.pry-crono small { display: block; color: var(--suave); font-size: 11.5px; }
.pry-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; }
.pry-kpis > div { padding: 10px; border: 1px solid var(--borde); border-radius: 10px; background: #fafbf8; }
.pry-kpis strong { display: block; font-size: 20px; line-height: 1.1; color: var(--tinta); }
.pry-kpis strong small { font-size: 12px; color: var(--suave); font-weight: 600; }
.pry-kpis span { display: block; margin-top: 3px; font-size: 11px; line-height: 1.25; color: var(--suave); }
.pry-sec { margin-top: 20px; }
.pry-sec-h { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
.pry-sec-h h4 { margin: 0; font-size: 14px; font-weight: 700; color: var(--tinta); }
.pry-sec-h h4 i { color: var(--verde); margin-right: 4px; }
.pry-sec-h a { font-size: 12px; font-weight: 600; color: var(--verde-osc); white-space: nowrap; }
.pry-badge { display: inline-block; margin-left: 4px; padding: 0 7px; border-radius: 999px; background: #f1f3f0; color: var(--suave); font-size: 11px; }
.pry-ens { list-style: none; margin: 0; padding: 0; border: 1px solid var(--borde); border-radius: 10px; overflow: hidden; }
.pry-ens li { display: flex; align-items: center; gap: 10px; padding: 10px 12px; }
.pry-ens li + li { border-top: 1px solid #edf1ec; }
.pry-ens-main { flex: 1; min-width: 0; }
.pry-ens-main b { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 13px; color: var(--tinta); }
.pry-ens-main small { display: block; color: var(--suave); font-size: 11.5px; }
.pry-ens-estado { margin-left: 6px; padding: 1px 7px; border-radius: 999px; font-size: 10.5px; font-weight: 700; }
.pry-ens-links { display: flex; gap: 4px; flex: none; }
.pry-ens-links a { display: inline-flex; align-items: center; gap: 4px; height: 30px; padding: 0 8px; border-radius: 8px; background: #f3f6f1; color: var(--suave); font-size: 12px; }
.pry-ens-links a small { font-weight: 700; }
.pry-ens-links a:hover { background: #73BC25; color: #fff; text-decoration: none; }
.pry-proximo { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-top: 10px; padding: 10px 12px; border-radius: 10px; background: #f7faf3; font-size: 12.5px; color: var(--suave); }
.pry-equipo { list-style: none; margin: 0; padding: 0; }
.pry-equipo li { display: flex; align-items: center; gap: 8px; padding: 6px 0; font-size: 13px; }
.pry-equipo li + li { border-top: 1px solid #f0f2f1; }
.pry-equipo span { flex: 1; min-width: 0; color: var(--tinta); }
.pry-equipo em { font-style: normal; font-size: 11.5px; color: var(--suave); }
.pry-equipo li.is-resp em { color: var(--verde-osc); font-weight: 700; }
.pry-ini { display: grid; place-items: center; width: 26px; height: 26px; border-radius: 50%; background: #eef7e3; color: var(--verde-osc); font-style: normal; font-size: 10.5px; font-weight: 700; }
.pry-agregar { display: flex; gap: 6px; margin-top: 8px; }
.pry-agregar select:first-child { flex: 1 1 auto; }
.pry-agregar select:nth-child(2) { flex: 0 1 160px; }
.pry-nota { margin: 8px 0 0; color: var(--suave); font-size: 11.5px; }
.pry-fade-enter-active, .pry-fade-leave-active { transition: opacity .2s; }
.pry-fade-enter, .pry-fade-leave-to { opacity: 0; }
.pry-slide-enter-active, .pry-slide-leave-active { transition: transform .22s ease; }
.pry-slide-enter, .pry-slide-leave-to { transform: translateX(100%); }

/* Formulario */
.pry-req { color: #FF6B00; }
.pry-codigo { display: flex; align-items: center; gap: 8px; height: calc(2.25rem + 2px); padding: 0 12px; border: 1px dashed #CED2D5; border-radius: 6px; background: #f7f9f6; color: var(--verde-osc); font-family: 'Space Grotesk', sans-serif; letter-spacing: .03em; }
.pry-codigo i { color: var(--verde); font-size: 12px; }
.pry-codigo.is-cambia { border-color: #FFCC00; background: #fffbe6; }
.pry-codigo.is-bloqueo { border-color: #FF6B00; background: #fff3eb; }
.pry-aviso { color: #b84d00; }

@media (max-width: 1280px) {
  .pry-head, .pry-fila { grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) minmax(120px, 1fr) minmax(90px, .8fr) 64px 110px 140px; }
}
@media (max-width: 1100px) {
  .pry-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .pry-head { display: none; }
  .pry-fila { grid-template-columns: repeat(2, minmax(0, 1fr)); padding: 14px 16px 14px 20px; }
  .pry-main { grid-column: 1 / -1; padding-right: 150px; }
  .pry-acciones { position: absolute; top: 10px; right: 10px; }
  .pry-estados, .pry-act { grid-column: 1 / -1; }
  .pry-c { text-align: left; }
  [data-label]::before { content: attr(data-label); display: block; margin-bottom: 2px; color: var(--suave); font-size: 10px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; flex-basis: 100%; }
}
@media (max-width: 640px) {
  .pry-stats { gap: 10px; }
  .pry-stat { padding: 10px 12px; gap: 10px; }
  .pry-stat-icon { width: 34px; height: 34px; font-size: 14px; }
  .pry-stat strong { font-size: 20px; }
  .pry-search { flex: 1 1 100%; }
  .pry-sel { flex: 1 1 calc(50% - 5px); min-width: 0; }
  .pry-nuevo { flex: 1 1 100%; margin-left: 0; }
  .pry-leyenda-tip { display: none; }
  .pry-main { padding-right: 0; padding-top: 34px; }
  .pry-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .pry-ens li { flex-wrap: wrap; }
}
</style>
