<template>
  <div class="prg-page">
    <!-- Resumen -->
    <div class="prg-stats">
      <component
        :is="s.filtro ? 'button' : 'div'" v-for="s in resumen" :key="s.label" :type="s.filtro ? 'button' : null"
        class="prg-stat" :class="{ 'is-filtro': s.filtro, 'is-on': s.filtro && filtro === s.filtro }" :style="{ '--acento': s.color }"
        :title="s.filtro ? (filtro === s.filtro ? 'Quitar filtro' : 'Filtrar: ' + FILTROS[s.filtro]) : null"
        @click="s.filtro && (filtro = filtro === s.filtro ? '' : s.filtro)"
      >
        <div class="prg-stat-icon"><i :class="s.icon"></i></div>
        <div>
          <div class="prg-stat-value">{{ s.valor }}</div>
          <div class="prg-stat-label">{{ s.label }}</div>
          <div class="prg-stat-sub">{{ s.sub }}</div>
        </div>
      </component>
    </div>

    <!-- Barra de herramientas -->
    <div class="prg-toolbar">
      <div class="prg-search">
        <i class="fas fa-search"></i>
        <input
          type="search"
          class="form-control"
          placeholder="Buscar programa, sigla, lider o proyecto..."
          v-model="busqueda"
        >
      </div>
      <select class="form-control prg-sort" v-model="filtro" aria-label="Filtrar programas">
        <option value="">Todos los programas</option>
        <option value="campo">Con ensayos en campo</option>
        <option value="quietos">Sin actividad reciente</option>
        <option value="vacios">Sin proyectos</option>
      </select>
      <select class="form-control prg-sort" v-model="orden" aria-label="Ordenar programas">
        <option value="nombre">Ordenar: nombre (A-Z)</option>
        <option value="proyectos">Ordenar: mas proyectos</option>
        <option value="ensayos">Ordenar: mas ensayos</option>
        <option value="recientes">Ordenar: mas recientes</option>
      </select>
      <div class="prg-vista" role="group" aria-label="Tipo de vista">
        <button type="button" :class="{ 'is-active': vista === 'lista' }" title="Vista de lista" @click="cambiarVista('lista')">
          <i class="fas fa-list"></i>
        </button>
        <button type="button" :class="{ 'is-active': vista === 'tarjetas' }" title="Vista de tarjetas" @click="cambiarVista('tarjetas')">
          <i class="fas fa-th-large"></i>
        </button>
      </div>
      <button v-if="puedeEditar" class="btn btn-success prg-nuevo" @click="abrirCrear">
        <i class="fas fa-plus"></i> Nuevo programa
      </button>
    </div>

    <p class="prg-resultados" v-if="items.length && (busqueda.trim() || filtro)">
      {{ programasFiltrados.length }} de {{ items.length }} programa{{ items.length !== 1 ? 's' : '' }}
      <template v-if="busqueda.trim()">coinciden con "<strong>{{ busqueda.trim() }}</strong>"</template><template v-if="filtro"> · {{ FILTROS[filtro] }}</template>
      <a href="#" @click.prevent="busqueda = ''; filtro = ''">Limpiar</a>
    </p>

    <div class="prg-leyenda" v-if="programasFiltrados.length && items.some((p) => p.ensayos_count)" aria-label="Colores de estado de los ensayos">
      <span>Ensayos:</span><span v-for="s in ESTADOS_ENSAYO" :key="s.id"><i :style="{ background: s.color }"></i>{{ s.id }}</span>
      <span class="prg-leyenda-tip"><i class="fas fa-mouse-pointer"></i> Clic en un programa para ver sus proyectos y personas</span>
    </div>
    <!-- Lista -->
    <div class="prg-list" v-if="programasFiltrados.length && vista === 'lista'">
      <div class="prg-list-head">
        <span>Programa</span>
        <span>Lider</span>
        <span class="prg-num">Proyectos</span>
        <span>Ensayos por estado</span>
        <span class="prg-num">Muestras</span>
        <span>Actividad</span>
        <span></span>
      </div>
      <div
        class="prg-row" v-for="p in programasFiltrados" :key="p.id" :style="estiloColor(p.color)"
        tabindex="0" role="button" :aria-label="`Ver detalle de ${p.nombre}`"
        @click="abrirDetalle(p)" @keydown.enter.self="abrirDetalle(p)"
      >
        <div class="prg-row-main">
          <div class="prg-avatar prg-avatar-sm" :title="`Sigla ${p.sigla || ''}: encabeza el codigo de sus proyectos`">{{ p.sigla || iniciales(p.nombre) }}</div>
          <div class="prg-row-text">
            <strong :title="p.nombre">{{ p.nombre }}</strong>
            <small :class="{ 'prg-vacio': !p.descripcion }" :title="p.descripcion">{{ p.descripcion || 'Sin descripcion registrada.' }}</small>
          </div>
        </div>
        <div class="prg-row-lider" :class="{ 'prg-vacio': !p.lider }">
          <i class="fas fa-user-tie"></i>{{ p.lider || 'Sin lider asignado' }}
        </div>
        <div class="prg-num" data-label="Proyectos" :title="`${p.proyectos_activos_count || 0} activos de ${p.proyectos_count || 0}`">
          <span class="prg-pill" :class="{ 'prg-pill-activo': p.proyectos_activos_count }"><b>{{ p.proyectos_activos_count || 0 }}</b><small>/{{ p.proyectos_count || 0 }}</small></span>
        </div>
        <div class="prg-estados" data-label="Ensayos">
          <div v-if="p.ensayos_count" class="prg-apilada">
            <span
              v-for="s in ESTADOS_ENSAYO.filter((x) => p.ensayos_estado && p.ensayos_estado[x.id])" :key="s.id"
              :style="{ flexGrow: p.ensayos_estado[s.id], background: s.color, color: s.texto }"
              :title="`${p.ensayos_estado[s.id]} ${s.id.toLowerCase()}`"
            >{{ p.ensayos_estado[s.id] }}</span>
          </div>
          <span v-else class="prg-vacio prg-mini">Sin ensayos</span>
        </div>
        <div class="prg-num" data-label="Muestras" :title="`${(p.muestras || {}).completadas || 0} con resultados de ${(p.muestras || {}).total || 0} generadas`">
          <span v-if="(p.muestras || {}).total" class="prg-mini"><b>{{ p.muestras.completadas }}</b>/{{ p.muestras.total }}</span>
          <span v-else class="prg-vacio prg-mini">—</span>
        </div>
        <div class="prg-actividad" data-label="Actividad" :class="{ 'is-alerta': sinActividad(p) }">
          <i v-if="sinActividad(p)" class="fas fa-exclamation-triangle"></i>
          <span>{{ p.ultima_actividad ? relativo(p.ultima_actividad) : 'Sin registros' }}</span>
          <small v-if="p.evaluaciones_30d">{{ n(p.evaluaciones_30d) }} valores en 30 dias</small>
        </div>
        <div class="prg-row-actions" @click.stop>
          <a class="prg-icon" :href="`${proyectosUrl}?programa=${p.id}`" title="Ver proyectos"><i class="fas fa-folder-open"></i></a>
          <template v-if="puedeEditar">
            <button type="button" class="prg-icon" title="Editar programa" @click="abrirEditar(p)"><i class="fas fa-pen"></i></button>
            <button
              type="button"
              class="prg-icon prg-icon-danger"
              :class="{ 'is-locked': p.proyectos_count > 0 }"
              :title="p.proyectos_count > 0 ? 'Tiene proyectos vinculados' : 'Eliminar programa'"
              @click="eliminar(p)"
            >
              <i class="fas fa-trash"></i>
            </button>
          </template>
        </div>
      </div>
    </div>

    <!-- Tarjetas -->
    <div class="prg-grid" v-else-if="programasFiltrados.length">
      <article class="prg-card" v-for="p in programasFiltrados" :key="p.id" :style="estiloColor(p.color)" tabindex="0" role="button" :aria-label="`Ver detalle de ${p.nombre}`" @click="abrirDetalle(p)" @keydown.enter.self="abrirDetalle(p)">
        <div class="prg-card-body">
          <div class="prg-card-head">
            <div class="prg-avatar" :title="`Sigla ${p.sigla || ''}: encabeza el codigo de sus proyectos`">{{ p.sigla || iniciales(p.nombre) }}</div>
            <div class="prg-card-title">
              <h3 :title="p.nombre">{{ p.nombre }}</h3>
              <div class="prg-lider">
                <i class="fas fa-user-tie"></i>
                <span :class="{ 'prg-vacio': !p.lider }">{{ p.lider || 'Sin lider asignado' }}</span>
              </div>
            </div>
            <div class="prg-actions" v-if="puedeEditar" @click.stop>
              <button type="button" class="prg-icon" title="Editar programa" @click="abrirEditar(p)">
                <i class="fas fa-pen"></i>
              </button>
              <button
                type="button"
                class="prg-icon prg-icon-danger"
                :class="{ 'is-locked': p.proyectos_count > 0 }"
                :title="p.proyectos_count > 0 ? 'Tiene proyectos vinculados' : 'Eliminar programa'"
                @click="eliminar(p)"
              >
                <i class="fas fa-trash"></i>
              </button>
            </div>
          </div>

          <p class="prg-desc" :class="{ 'prg-vacio': !p.descripcion }">
            {{ p.descripcion || 'Sin descripcion registrada.' }}
          </p>

          <div class="prg-metrics">
            <div class="prg-metric">
              <strong>{{ p.proyectos_count || 0 }}</strong>
              <span>Proyecto{{ p.proyectos_count === 1 ? '' : 's' }}</span>
            </div>
            <div class="prg-metric">
              <strong>{{ p.proyectos_activos_count || 0 }}</strong>
              <span>Activo{{ p.proyectos_activos_count === 1 ? '' : 's' }}</span>
            </div>
            <div class="prg-metric">
              <strong>{{ p.ensayos_count || 0 }}</strong>
              <span>Ensayo{{ p.ensayos_count === 1 ? '' : 's' }}</span>
            </div>
          </div>
          <div class="prg-card-estado">
            <div v-if="p.ensayos_count" class="prg-apilada">
              <span
                v-for="s in ESTADOS_ENSAYO.filter((x) => p.ensayos_estado && p.ensayos_estado[x.id])" :key="s.id"
                :style="{ flexGrow: p.ensayos_estado[s.id], background: s.color, color: s.texto }"
                :title="`${p.ensayos_estado[s.id]} ${s.id.toLowerCase()}`"
              >{{ p.ensayos_estado[s.id] }}</span>
            </div>
            <div class="prg-actividad" :class="{ 'is-alerta': sinActividad(p) }">
              <i :class="sinActividad(p) ? 'fas fa-exclamation-triangle' : 'far fa-clock'"></i>
              <span>{{ p.ultima_actividad ? 'Actividad ' + relativo(p.ultima_actividad).toLowerCase() : 'Sin registros de campo' }}</span>
            </div>
          </div>
        </div>

        <a class="prg-card-foot" @click.stop :href="`${proyectosUrl}?programa=${p.id}`">
          <span>Ver proyectos</span><i class="fas fa-arrow-right"></i>
        </a>
      </article>
    </div>

    <!-- Estado vacio -->
    <div class="prg-empty" v-else>
      <div class="prg-empty-icon"><i class="fas fa-layer-group"></i></div>
      <template v-if="items.length">
        <h4>Sin coincidencias</h4>
        <p>Ningun programa coincide con la busqueda o el filtro elegido.</p>
        <button class="btn btn-secondary" @click="busqueda = ''; filtro = ''"><i class="fas fa-times"></i> Limpiar filtros</button>
      </template>
      <template v-else-if="puedeEditar">
        <h4>Aun no hay programas</h4>
        <p>Los programas son las areas tematicas que agrupan los proyectos de investigacion.</p>
        <button class="btn btn-success" @click="abrirCrear"><i class="fas fa-plus"></i> Crear el primer programa</button>
      </template>
      <template v-else>
        <h4>No tienes programas asignados</h4>
        <p>Solicita al administrador de SIGEC que te asigne a un programa de investigacion.</p>
      </template>
    </div>

    <!-- Detalle del programa -->
    <transition name="prg-fade"><div v-if="detalle" class="prg-velo" @click="detalle = null"></div></transition>
    <transition name="prg-slide">
      <aside v-if="detalle" class="prg-panel" :style="estiloColor(detalle.color)" role="dialog" aria-modal="true" :aria-label="`Detalle de ${detalle.nombre}`">
        <header class="prg-panel-h">
          <div class="prg-avatar">{{ detalle.sigla || iniciales(detalle.nombre) }}</div>
          <div class="prg-panel-t">
            <h3>{{ detalle.nombre }}</h3>
            <small><i class="fas fa-user-tie"></i> {{ detalle.lider || 'Sin lider asignado' }}</small>
          </div>
          <button v-if="puedeEditar" type="button" class="prg-icon" title="Editar programa" @click="abrirEditar(detalle)"><i class="fas fa-pen"></i></button>
          <button type="button" class="prg-icon" title="Cerrar (Esc)" @click="detalle = null"><i class="fas fa-times"></i></button>
        </header>
        <div class="prg-panel-b">
          <p class="prg-desc" :class="{ 'prg-vacio': !detalle.descripcion }">{{ detalle.descripcion || 'Sin descripcion registrada.' }}</p>

          <div class="prg-kpis">
            <div><strong>{{ detalle.proyectos_activos_count || 0 }}<small>/{{ detalle.proyectos_count || 0 }}</small></strong><span>Proyectos activos</span></div>
            <div><strong>{{ (detalle.ensayos_estado || {})['En campo'] || 0 }}<small>/{{ detalle.ensayos_count || 0 }}</small></strong><span>Ensayos en campo</span></div>
            <div><strong>{{ n(detalle.evaluaciones_30d) }}</strong><span>Valores evaluados en 30 dias</span></div>
            <div><strong>{{ (detalle.muestras || {}).completadas || 0 }}<small>/{{ (detalle.muestras || {}).total || 0 }}</small></strong><span>Muestras con resultado</span></div>
          </div>
          <p class="prg-actividad prg-panel-act" :class="{ 'is-alerta': sinActividad(detalle) }">
            <i :class="sinActividad(detalle) ? 'fas fa-exclamation-triangle' : 'far fa-clock'"></i>
            <span v-if="detalle.ultima_actividad">Ultima evaluacion, muestreo o bitacora: {{ fecha(detalle.ultima_actividad) }} ({{ relativo(detalle.ultima_actividad).toLowerCase() }})</span>
            <span v-else>Aun no hay evaluaciones, muestras ni bitacora en sus ensayos.</span>
            <template v-if="sinActividad(detalle)"> · tiene ensayos en campo sin movimiento en {{ diasSinActividad }} dias</template>
          </p>

          <section class="prg-sec">
            <div class="prg-sec-h">
              <h4><i class="fas fa-folder-open"></i> Proyectos <span class="prg-badge">{{ (detalle.proyectos || []).length }}</span></h4>
              <a :href="`${proyectosUrl}?programa=${detalle.id}`">Ver en Proyectos <i class="fas fa-arrow-right"></i></a>
            </div>
            <ul v-if="(detalle.proyectos || []).length" class="prg-proys">
              <li v-for="pr in detalle.proyectos" :key="pr.id">
                <div class="prg-proy-main">
                  <span class="prg-cod">{{ pr.codigo }}</span>
                  <b :title="pr.nombre">{{ pr.nombre }}</b>
                  <small>
                    <i class="fas fa-user"></i> {{ pr.responsable || 'Sin responsable' }}
                    <template v-if="pr.inicio"> · {{ fecha(pr.inicio) }}{{ pr.fin ? ' – ' + fecha(pr.fin) : '' }}</template>
                  </small>
                </div>
                <span class="prg-estado" :class="'is-' + pr.estado.toLowerCase()">{{ pr.estado }}</span>
                <a class="prg-proy-ens" :href="`${ensayosUrl}?proyecto=${pr.id}`" :title="`${pr.ensayos_campo} en campo de ${pr.ensayos} ensayo(s)`">
                  <i class="fas fa-seedling"></i> {{ pr.ensayos }}<small v-if="pr.ensayos_campo"> · {{ pr.ensayos_campo }} en campo</small>
                </a>
              </li>
            </ul>
            <div v-else class="prg-vacio-box"><i class="fas fa-folder-plus"></i> Este programa aun no tiene proyectos.</div>
            <div v-if="puedeCrearProyecto && detalle.sigla" class="prg-nuevo-proy">
              <span>Proximo codigo: <b class="prg-cod">{{ detalle.proximo_codigo }}</b></span>
              <a class="btn btn-sm btn-success" :href="`${proyectosUrl}?programa=${detalle.id}&nuevo=1`"><i class="fas fa-plus"></i> Nuevo proyecto en {{ detalle.sigla }}</a>
            </div>
          </section>

          <section class="prg-sec">
            <div class="prg-sec-h">
              <h4><i class="fas fa-users"></i> Personas con acceso <span class="prg-badge">{{ (detalle.miembros || []).length }}</span></h4>
              <a v-if="usuariosUrl" :href="usuariosUrl">Gestionar en Usuarios <i class="fas fa-arrow-right"></i></a>
            </div>
            <div v-if="(detalle.miembros || []).length" class="prg-miembros">
              <span v-for="u in detalle.miembros" :key="u.id"><i>{{ iniciales(u.nombre) || u.nombre.slice(0, 1).toUpperCase() }}</i>{{ u.nombre }}</span>
            </div>
            <p class="prg-nota">
              {{ (detalle.miembros || []).length ? 'Ven este programa y sus proyectos.' : 'Nadie tiene asignado este programa.' }}
              Administradores y directores ven todos los programas; el resto solo los que tiene asignados en Usuarios.
            </p>
          </section>
        </div>
      </aside>
    </transition>

    <!-- Modal crear / editar -->
    <div class="modal fade" ref="modal" tabindex="-1" role="dialog" aria-labelledby="prg-modal-title">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content prg-modal">
          <form @submit.prevent="guardar" novalidate>
            <div class="modal-header">
              <h5 class="modal-title" id="prg-modal-title">
                <i class="fas fa-layer-group"></i>{{ editando ? 'Editar programa' : 'Nuevo programa' }}
              </h5>
              <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
            </div>
            <div class="modal-body">
              <div class="prg-preview" :style="estiloColor(form.color)">
                <div class="prg-avatar">{{ form.sigla || iniciales(form.nombre) || '?' }}</div>
                <div>
                  <strong>{{ form.nombre.trim() || 'Nombre del programa' }}</strong>
                  <small>{{ (form.lider || '').trim() || 'Lider del programa' }}</small>
                </div>
              </div>

              <div class="form-group">
                <label for="prg-nombre">Nombre del programa <span class="prg-req">*</span></label>
                <input
                  id="prg-nombre" ref="nombre" type="text" maxlength="150"
                  class="form-control" :class="{ 'is-invalid': errors.nombre }"
                  v-model="form.nombre" placeholder="Ej. Agronomia" @input="sugerirSigla"
                >
                <span class="invalid-feedback" v-if="errors.nombre">{{ errors.nombre[0] }}</span>
              </div>
              <div class="form-group">
                <label for="prg-sigla">Sigla <span class="prg-req">*</span></label>
                <input
                  id="prg-sigla" type="text" maxlength="4"
                  class="form-control prg-sigla" :class="{ 'is-invalid': errors.sigla }"
                  v-model="form.sigla" placeholder="Ej. AGR" @input="editarSigla"
                >
                <span class="invalid-feedback" v-if="errors.sigla">{{ errors.sigla[0] }}</span>
                <small class="form-text text-muted">
                  Encabeza el codigo de proyectos y ensayos: <b>{{ form.sigla || 'SIGLA' }}-{{ anioCorto }}-01</b> · ensayo <b>{{ form.sigla || 'SIGLA' }}-{{ anioCorto }}-01-E01</b>
                  <template v-if="editando && editando.sigla && form.sigla !== editando.sigla && editando.proyectos_count">
                    <br><span class="prg-aviso"><i class="fas fa-exclamation-triangle"></i> Al cambiarla se recodifican sus {{ editando.proyectos_count }} proyecto(s), sus ensayos y los ID de sus muestras.</span>
                  </template>
                </small>
              </div>
              <div class="form-group">
                <label for="prg-lider">Lider del programa</label>
                <input
                  id="prg-lider" type="text" maxlength="150"
                  class="form-control" :class="{ 'is-invalid': errors.lider }"
                  v-model="form.lider" placeholder="Ej. Ing. Carlos Mejia"
                >
                <span class="invalid-feedback" v-if="errors.lider">{{ errors.lider[0] }}</span>
              </div>
              <div class="form-group">
                <label for="prg-desc">Descripcion</label>
                <textarea
                  id="prg-desc" rows="3" maxlength="1000"
                  class="form-control" :class="{ 'is-invalid': errors.descripcion }"
                  v-model="form.descripcion" placeholder="Breve descripcion del programa"
                ></textarea>
                <small class="prg-counter">{{ (form.descripcion || '').length }}/1000</small>
                <span class="invalid-feedback" v-if="errors.descripcion">{{ errors.descripcion[0] }}</span>
              </div>
              <div class="form-group mb-0">
                <label>Color del programa</label>
                <div class="prg-swatches" role="radiogroup" aria-label="Color del programa">
                  <button
                    v-for="c in paleta" :key="c" type="button" role="radio"
                    class="prg-swatch" :class="{ 'is-active': form.color === c }"
                    :style="{ background: c }" :aria-checked="form.color === c ? 'true' : 'false'"
                    :title="nombreColor(c)" @click="form.color = c"
                  >
                    <i class="fas fa-check" v-if="form.color === c"></i>
                  </button>
                </div>
                <span class="text-danger small" v-if="errors.color">{{ errors.color[0] }}</span>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-success" :disabled="guardando">
                <i :class="guardando ? 'fas fa-spinner fa-spin' : 'fas fa-save'"></i>
                {{ guardando ? 'Guardando...' : (editando ? 'Guardar cambios' : 'Crear programa') }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
// Tono oscuro legible sobre cada color de marca (para texto/iconos sobre el tinte).
const TONOS = {
  '#73BC25': { nombre: 'Verde principal', oscuro: '#3f7a12' },
  '#A3D300': { nombre: 'Verde secundario', oscuro: '#5c7700' },
  '#FFCC00': { nombre: 'Amarillo', oscuro: '#8a6a00' },
  '#FF6B00': { nombre: 'Naranja', oscuro: '#b84d00' },
  '#CED2D5': { nombre: 'Gris', oscuro: '#5b6267' },
};

export default {
  name: 'ProgramasIndex',
  props: {
    programas: { type: Array, default: () => [] },
    puedeEditar: { type: Boolean, default: false },
    paleta: { type: Array, default: () => Object.keys(TONOS) },
    proyectosUrl: { type: String, default: '' },
    ensayosUrl: { type: String, default: '' },
    usuariosUrl: { type: String, default: null },
    puedeCrearProyecto: { type: Boolean, default: false },
    diasSinActividad: { type: Number, default: 30 },
  },
  data() {
    return {
      // Paleta validada (CVD) de estados de ensayo, igual que el dashboard; lleva numero en cada tramo.
      ESTADOS_ENSAYO: [
        { id: 'Planificado', color: '#eda100', texto: '#1f2a12' },
        { id: 'En campo', color: '#5a9e1a', texto: '#ffffff' },
        { id: 'Finalizado', color: '#2a78d6', texto: '#ffffff' },
      ],
      FILTROS: { campo: 'con ensayos en campo', quietos: 'sin actividad reciente', vacios: 'sin proyectos' },
      items: this.programas.slice(),
      filtro: '',
      detalle: null,
      busqueda: '',
      orden: 'nombre',
      vista: this.vistaGuardada(),
      editando: null,
      form: this.formVacio(),
      errors: {},
      guardando: false,
    };
  },
  computed: {
    anioCorto() { return String(new Date().getFullYear()).slice(2); },
    programasFiltrados() {
      const q = this.normalizar(this.busqueda.trim());
      const cumple = {
        '': () => true,
        campo: (p) => ((p.ensayos_estado || {})['En campo'] || 0) > 0,
        quietos: (p) => this.sinActividad(p),
        vacios: (p) => !p.proyectos_count,
      }[this.filtro];
      const lista = this.items.filter((p) => cumple(p) && (!q || this.normalizar([
        p.nombre, p.sigla, p.lider, p.descripcion,
        ...(p.proyectos || []).map((pr) => `${pr.codigo} ${pr.nombre} ${pr.responsable || ''}`),
      ].join(' ')).includes(q)));
      const por = {
        nombre: (a, b) => a.nombre.localeCompare(b.nombre, 'es'),
        proyectos: (a, b) => (b.proyectos_count || 0) - (a.proyectos_count || 0) || a.nombre.localeCompare(b.nombre, 'es'),
        ensayos: (a, b) => (b.ensayos_count || 0) - (a.ensayos_count || 0) || a.nombre.localeCompare(b.nombre, 'es'),
        recientes: (a, b) => String(b.created_at || '').localeCompare(String(a.created_at || '')) || b.id - a.id,
      };
      return lista.sort(por[this.orden]);
    },
    resumen() {
      const suma = (k) => this.items.reduce((s, p) => s + (p[k] || 0), 0);
      const enCampo = this.items.reduce((s, p) => s + ((p.ensayos_estado || {})['En campo'] || 0), 0);
      const quietos = this.items.filter((p) => this.sinActividad(p)).length;
      return [
        { label: 'Programas', valor: this.items.length, sub: `${this.items.filter((p) => p.proyectos_count).length} con proyectos`, icon: 'fas fa-layer-group', color: '#73BC25' },
        { label: 'Proyectos activos', valor: suma('proyectos_activos_count'), sub: `de ${suma('proyectos_count')} proyectos`, icon: 'fas fa-folder-open', color: '#A3D300' },
        { label: 'Ensayos en campo', valor: enCampo, sub: `de ${suma('ensayos_count')} ensayos`, icon: 'fas fa-seedling', color: '#FFCC00', filtro: 'campo' },
        { label: 'Sin actividad reciente', valor: quietos, sub: `ensayos en campo sin movimiento en ${this.diasSinActividad} dias`, icon: 'fas fa-hourglass-half', color: '#FF6B00', filtro: 'quietos' },
      ];
    },
  },
  mounted() {
    window.$(this.$refs.modal).on('shown.bs.modal', () => this.$refs.nombre && this.$refs.nombre.focus());
    window.addEventListener('keydown', this.onEsc);
  },
  beforeDestroy() {
    window.$(this.$refs.modal).off('shown.bs.modal');
    window.removeEventListener('keydown', this.onEsc);
  },
  methods: {
    abrirDetalle(p) { this.detalle = p; },
    onEsc(e) {
      // Con el formulario abierto, Esc lo cierra a el (Bootstrap) y no al panel.
      if (e.key === 'Escape' && this.detalle && !document.body.classList.contains('modal-open')) this.detalle = null;
    },
    // Ensayos en campo sin evaluaciones, muestreos ni bitacora en el plazo.
    sinActividad(p) {
      if (!((p.ensayos_estado || {})['En campo'] > 0)) return false;
      if (!p.ultima_actividad) return true;
      return Date.now() - Date.parse(p.ultima_actividad) > this.diasSinActividad * 86400000;
    },
    n(x) { return Number(x || 0).toLocaleString('es-GT'); },
    fecha(f) { if (!f) return ''; const [y, m, d] = String(f).slice(0, 10).split('-'); return `${d}/${m}/${y}`; },
    relativo(f) {
      const dias = Math.floor((Date.now() - Date.parse(String(f).slice(0, 10))) / 86400000);
      if (dias <= 0) return 'Hoy';
      if (dias === 1) return 'Ayer';
      if (dias < 60) return `Hace ${dias} dias`;
      return `Hace ${Math.round(dias / 30)} meses`;
    },
    formVacio() {
      return { nombre: '', sigla: '', lider: '', descripcion: '', color: this.siguienteColor(), siglaManual: false };
    },
    // Misma regla que App\Services\Codificacion::siglaSugerida: iniciales o 3 primeras letras.
    sugerirSigla() {
      if (this.form.siglaManual) return;
      const vacias = ['de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'en', 'para', 'por', 'a', 'al', 'con'];
      const palabras = this.normalizar(this.form.nombre).toUpperCase().split(/[^A-Z0-9]+/)
        .filter((p) => p && !vacias.includes(p.toLowerCase()));
      let base = palabras.length >= 2 ? palabras.slice(0, 4).map((p) => p[0]).join('') : (palabras[0] || '').slice(0, 3);
      if (!base) { this.form.sigla = ''; return; }
      base = base.padEnd(2, 'X').slice(0, 4);
      const usadas = this.items.filter((i) => !this.editando || i.id !== this.editando.id).map((i) => i.sigla);
      let sigla = base;
      for (let n = 2; usadas.includes(sigla); n += 1) sigla = base.slice(0, 3) + n;
      this.form.sigla = sigla;
    },
    editarSigla() {
      this.form.sigla = this.form.sigla.toUpperCase().replace(/[^A-Z0-9]/g, '');
      this.form.siglaManual = this.form.sigla !== '';
    },
    vistaGuardada() {
      try {
        return window.localStorage.getItem('sigec.programas.vista') === 'tarjetas' ? 'tarjetas' : 'lista';
      } catch (e) {
        return 'lista';
      }
    },
    cambiarVista(v) {
      this.vista = v;
      try { window.localStorage.setItem('sigec.programas.vista', v); } catch (e) { /* sin almacenamiento */ }
    },
    siguienteColor() {
      const n = this.items ? this.items.length : this.programas.length;
      return this.paleta[n % this.paleta.length];
    },
    normalizar(s) {
      return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    },
    iniciales(nombre) {
      const partes = String(nombre || '').trim().split(/\s+/).filter((w) => w.length > 2 || /^[A-Z]/.test(w));
      return partes.slice(0, 2).map((w) => w[0].toUpperCase()).join('');
    },
    tono(color) {
      return TONOS[String(color || '').toUpperCase()] || TONOS['#CED2D5'];
    },
    nombreColor(c) {
      return this.tono(c).nombre;
    },
    estiloColor(color) {
      const c = TONOS[String(color || '').toUpperCase()] ? color : '#CED2D5';
      return { '--prg-color': c, '--prg-oscuro': this.tono(c).oscuro };
    },
    abrirModal() {
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    abrirCrear() {
      this.editando = null;
      this.form = this.formVacio();
      this.abrirModal();
    },
    abrirEditar(p) {
      this.editando = p;
      this.form = {
        nombre: p.nombre || '',
        sigla: p.sigla || '',
        siglaManual: !!p.sigla,
        lider: p.lider || '',
        descripcion: p.descripcion || '',
        color: TONOS[String(p.color || '').toUpperCase()] ? p.color.toUpperCase() : this.paleta[0],
      };
      this.abrirModal();
    },
    guardar() {
      if (!this.form.nombre.trim()) {
        this.errors = { nombre: ['El nombre del programa es obligatorio.'] };
        this.$refs.nombre.focus();
        return;
      }
      if (!/^[A-Z][A-Z0-9]{1,3}$/.test(this.form.sigla)) {
        this.errors = { sigla: ['La sigla debe tener de 2 a 4 letras o numeros y empezar con una letra.'] };
        return;
      }
      const duplicado = this.items.find((i) => (!this.editando || i.id !== this.editando.id)
        && this.normalizar(i.nombre) === this.normalizar(this.form.nombre.trim()));
      if (duplicado) {
        this.errors = { nombre: ['Ya existe un programa con ese nombre.'] };
        return;
      }

      this.guardando = true;
      this.errors = {};
      const payload = {
        nombre: this.form.nombre.trim(),
        sigla: this.form.sigla.trim(),
        lider: this.form.lider.trim(),
        descripcion: this.form.descripcion.trim(),
        color: this.form.color,
      };
      const editando = this.editando;
      const peticion = editando
        ? window.axios.put(`/investigacion/programas/${editando.id}`, payload)
        : window.axios.post('/investigacion/programas', payload);

      peticion
        .then(({ data }) => {
          if (editando) {
            const idx = this.items.findIndex((i) => i.id === data.id);
            this.$set(this.items, idx, data);
            if (this.detalle && this.detalle.id === data.id) this.detalle = data;
          } else {
            this.items.push(data);
          }
          window.$(this.$refs.modal).modal('hide');
          this.$swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: editando ? 'Programa actualizado' : 'Programa creado',
            text: data.recodificados ? `${data.nombre}: ${data.recodificados} codigo(s) actualizados` : data.nombre,
            timer: 2200,
            showConfirmButton: false,
          });
        })
        .catch((err) => {
          if (err.response && err.response.status === 422) {
            this.errors = err.response.data.errors || {};
            if (!err.response.data.errors && err.response.data.message) {
              this.$swal.fire({ icon: 'warning', title: 'No se cambio la sigla', text: err.response.data.message });
            }
          } else if (err.response && err.response.status === 403) {
            this.$swal.fire({ icon: 'error', title: 'Acceso denegado', text: 'Solo el Administrador puede gestionar programas.' });
          } else {
            this.$swal.fire({ icon: 'error', title: 'Ocurrio un error', text: 'No se pudo guardar el programa. Intente de nuevo.' });
          }
        })
        .finally(() => { this.guardando = false; });
    },
    eliminar(p) {
      if (p.proyectos_count > 0) {
        this.$swal.fire({
          icon: 'info',
          title: 'No se puede eliminar',
          html: `<b>${this.escapar(p.nombre)}</b> tiene ${p.proyectos_count} proyecto(s) vinculado(s).<br>Reasigne o elimine esos proyectos primero.`,
          confirmButtonText: 'Ver proyectos',
          showCancelButton: true,
          cancelButtonText: 'Cerrar',
          confirmButtonColor: '#73BC25',
          cancelButtonColor: '#CED2D5',
        }).then((res) => {
          if (res.isConfirmed) window.location.href = `${this.proyectosUrl}?programa=${p.id}`;
        });
        return;
      }
      this.$swal.fire({
        title: 'Eliminar programa',
        html: `Se eliminara <b>${this.escapar(p.nombre)}</b>. Esta accion no se puede deshacer.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Si, eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#FF6B00',
        cancelButtonColor: '#CED2D5',
        focusCancel: true,
      }).then((res) => {
        if (!res.isConfirmed) return;
        window.axios.delete(`/investigacion/programas/${p.id}`)
          .then(() => {
            this.items = this.items.filter((i) => i.id !== p.id);
            if (this.detalle && this.detalle.id === p.id) this.detalle = null;
            this.$swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Programa eliminado', timer: 2000, showConfirmButton: false });
          })
          .catch((err) => {
            const msg = err.response && err.response.data && err.response.data.message;
            this.$swal.fire({ icon: 'error', title: 'No se pudo eliminar', text: msg || 'Ocurrio un error.' });
          });
      });
    },
    escapar(s) {
      const d = document.createElement('div');
      d.textContent = s;
      return d.innerHTML;
    },
  },
};
</script>

<style scoped>
/* ---------- Resumen ---------- */
.prg-stats {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
  margin-bottom: 18px;
}
.prg-stat {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px 18px;
  border: 1px solid var(--cengi-border);
  border-radius: var(--cengi-radius);
  background: var(--cengi-surface);
  box-shadow: var(--cengi-shadow);
  position: relative;
  overflow: hidden;
}
.prg-stat::before {
  content: '';
  position: absolute;
  left: 0; top: 0; bottom: 0;
  width: 4px;
  background: var(--acento);
}
.prg-stat-icon {
  display: grid;
  place-items: center;
  width: 42px; height: 42px;
  flex-shrink: 0;
  border-radius: 11px;
  background: color-mix(in srgb, var(--acento) 16%, #fff);
  color: color-mix(in srgb, var(--acento) 70%, #1f2a1f);
  font-size: 17px;
}
.prg-stat-value {
  color: var(--cengi-primary-deep);
  font-family: 'Space Grotesk', sans-serif;
  font-size: 24px;
  font-weight: 700;
  line-height: 1.1;
}
.prg-stat-label { color: var(--cengi-muted); font-size: 11.5px; font-weight: 600; }

/* ---------- Barra ---------- */
.prg-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  padding: 12px;
  margin-bottom: 14px;
  border: 1px solid var(--cengi-border);
  border-radius: var(--cengi-radius);
  background: var(--cengi-surface);
  box-shadow: var(--cengi-shadow);
}
.prg-search { position: relative; flex: 1 1 280px; }
.prg-search i {
  position: absolute;
  top: 50%; left: 13px;
  transform: translateY(-50%);
  color: #8a9b8e;
  font-size: 12px;
  pointer-events: none;
}
.prg-search .form-control { padding-left: 36px; }
.prg-sort { flex: 0 1 210px; width: auto; }
.prg-nuevo { margin-left: auto; white-space: nowrap; }
.prg-resultados { margin: -4px 0 14px; color: var(--cengi-muted); font-size: 12px; }
.prg-resultados a { margin-left: 6px; font-weight: 700; }

.prg-vista {
  display: inline-flex;
  padding: 3px;
  border: 1px solid var(--cengi-border);
  border-radius: var(--cengi-radius-sm);
  background: #f3f5f1;
}
.prg-vista button {
  width: 34px; height: 30px;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: var(--cengi-muted);
  font-size: 13px;
  cursor: pointer;
}
.prg-vista button.is-active { background: #fff; color: var(--cengi-primary-deep); box-shadow: 0 1px 3px rgba(30, 42, 26, .12); }
.prg-vista button:focus-visible { outline: 2px solid rgba(115, 188, 37, .5); }

/* ---------- Lista ---------- */
.prg-list {
  border: 1px solid var(--cengi-border);
  border-radius: var(--cengi-radius);
  background: var(--cengi-surface);
  box-shadow: var(--cengi-shadow);
  overflow: hidden;
}
.prg-list-head, .prg-row {
  display: grid;
  grid-template-columns: minmax(0, 2.4fr) minmax(0, 1.2fr) 84px minmax(110px, 1fr) 76px 136px 104px;
  align-items: center;
  gap: 12px;
  padding: 0 16px;
}
.prg-list-head {
  min-height: 42px;
  border-bottom: 1px solid var(--cengi-border);
  background: #f7faf3;
  color: var(--cengi-muted);
  font-size: 10.5px;
  font-weight: 700;
  letter-spacing: .06em;
  text-transform: uppercase;
}
.prg-row {
  position: relative;
  min-height: 66px;
  padding-top: 10px;
  padding-bottom: 10px;
  border-bottom: 1px solid #edf1ec;
  transition: background .15s ease;
}
.prg-row:last-child { border-bottom: 0; }
.prg-row::before {
  content: '';
  position: absolute;
  left: 0; top: 10px; bottom: 10px;
  width: 4px;
  border-radius: 0 4px 4px 0;
  background: var(--prg-color);
}
.prg-row:hover { background: color-mix(in srgb, var(--prg-color) 6%, #fff); }
.prg-row-main { display: flex; align-items: center; gap: 12px; min-width: 0; }
.prg-avatar.prg-avatar-sm { width: 42px; height: 38px; border-radius: 10px; font-size: 11.5px; letter-spacing: 0; }
.prg-row-text { min-width: 0; }
.prg-row-text strong, .prg-row-text small {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.prg-row-text strong { color: var(--cengi-primary-deep); font-size: 13.5px; font-weight: 700; }
.prg-row-text small { color: var(--cengi-muted); font-size: 12px; }
.prg-row-lider { overflow: hidden; color: var(--cengi-ink); font-size: 12.5px; text-overflow: ellipsis; white-space: nowrap; }
.prg-row-lider i { margin-right: 6px; color: var(--prg-oscuro); font-size: 11px; }
.prg-num { text-align: center; }
.prg-pill {
  display: inline-block;
  min-width: 34px;
  padding: 3px 9px;
  border-radius: 999px;
  background: #f1f3f0;
  color: var(--cengi-primary-deep);
  font-size: 12.5px;
  font-weight: 700;
}
.prg-pill-activo { background: #eef8df; color: #3f7a12; }
.prg-pill-activo.is-cero { background: #f1f3f0; color: #8a9396; }
.prg-row-actions { display: flex; justify-content: flex-end; gap: 2px; }
.prg-row-actions a.prg-icon:hover { text-decoration: none; }

/* ---------- Tarjetas ---------- */
.prg-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 16px;
}
.prg-card {
  display: flex;
  flex-direction: column;
  border: 1px solid var(--cengi-border);
  border-radius: var(--cengi-radius);
  background: var(--cengi-surface);
  box-shadow: var(--cengi-shadow);
  overflow: hidden;
  position: relative;
  transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}
.prg-card::before {
  content: '';
  height: 5px;
  background: var(--prg-color);
}
.prg-card:hover {
  transform: translateY(-3px);
  border-color: color-mix(in srgb, var(--prg-color) 45%, var(--cengi-border));
  box-shadow: var(--cengi-shadow-lg);
}
.prg-card-body { flex: 1; display: flex; flex-direction: column; padding: 16px 18px 14px; }
.prg-card-head { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 12px; }
.prg-avatar {
  display: grid;
  place-items: center;
  width: 44px; height: 44px;
  flex-shrink: 0;
  border-radius: 12px;
  background: color-mix(in srgb, var(--prg-color) 18%, #fff);
  color: var(--prg-oscuro);
  font-family: 'Space Grotesk', sans-serif;
  font-size: 15px;
  font-weight: 700;
  letter-spacing: .02em;
}
.prg-card-title { flex: 1; min-width: 0; }
.prg-card-title h3 {
  margin: 1px 0 3px;
  overflow: hidden;
  color: var(--cengi-primary-deep);
  font-family: 'Space Grotesk', sans-serif;
  font-size: 16px;
  font-weight: 700;
  line-height: 1.25;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.prg-lider { display: flex; align-items: center; gap: 6px; color: var(--cengi-muted); font-size: 12px; }
.prg-lider i { color: var(--prg-oscuro); font-size: 11px; }
.prg-vacio { color: #9aa3a8 !important; font-style: italic; }

.prg-actions { display: flex; gap: 2px; flex-shrink: 0; opacity: .55; transition: opacity .15s ease; }
.prg-card:hover .prg-actions, .prg-actions:focus-within { opacity: 1; }
.prg-icon {
  display: inline-grid;
  place-items: center;
  width: 30px; height: 30px;
  border: 1px solid transparent;
  border-radius: var(--cengi-radius-sm);
  background: transparent;
  color: var(--cengi-muted);
  font-size: 12px;
  cursor: pointer;
}
.prg-icon:hover, .prg-icon:focus-visible { border-color: var(--cengi-border); background: var(--cengi-accent-soft); color: var(--cengi-primary-deep); outline: none; }
.prg-icon-danger:hover, .prg-icon-danger:focus-visible { border-color: #ffd2b3; background: #fff1e6; color: #FF6B00; }
.prg-icon-danger.is-locked:hover { border-color: #CED2D5; background: #f3f4f5; color: #5b6267; }

.prg-desc {
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  flex: 1;
  min-height: 3em;
  margin: 0 0 14px;
  overflow: hidden;
  color: #4b5e52;
  font-size: 12.5px;
  line-height: 1.55;
}

.prg-metrics {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  border: 1px solid #edf1ec;
  border-radius: var(--cengi-radius-sm);
  background: #fafbf8;
}
.prg-metric { padding: 8px 6px; text-align: center; }
.prg-metric + .prg-metric { border-left: 1px solid #edf1ec; }
.prg-metric strong {
  display: block;
  color: var(--cengi-primary-deep);
  font-family: 'Space Grotesk', sans-serif;
  font-size: 18px;
  line-height: 1.15;
}
.prg-metric span { color: var(--cengi-muted); font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }

.prg-card-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 11px 18px;
  border-top: 1px solid #edf1ec;
  color: var(--prg-oscuro);
  font-size: 12px;
  font-weight: 700;
  text-decoration: none;
  transition: background .15s ease;
}
.prg-card-foot i { font-size: 11px; transition: transform .15s ease; }
.prg-card-foot:hover, .prg-card-foot:focus-visible {
  background: color-mix(in srgb, var(--prg-color) 10%, #fff);
  color: var(--prg-oscuro);
  text-decoration: none;
}
.prg-card-foot:hover i { transform: translateX(3px); }

/* ---------- Vacio ---------- */
.prg-empty {
  padding: 52px 20px;
  border: 1px dashed #CED2D5;
  border-radius: var(--cengi-radius);
  background: var(--cengi-surface);
  text-align: center;
}
.prg-empty-icon {
  display: inline-grid;
  place-items: center;
  width: 64px; height: 64px;
  margin-bottom: 14px;
  border-radius: 18px;
  background: #eef8df;
  color: #73BC25;
  font-size: 26px;
}
.prg-empty h4 {
  margin: 0 0 6px;
  color: var(--cengi-primary-deep);
  font-family: 'Space Grotesk', sans-serif;
  font-size: 17px;
  font-weight: 700;
}
.prg-empty p { max-width: 420px; margin: 0 auto 16px; color: var(--cengi-muted); font-size: 13px; }

/* ---------- Modal ---------- */
.prg-preview {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 18px;
  padding: 12px 14px;
  border: 1px solid color-mix(in srgb, var(--prg-color) 40%, var(--cengi-border));
  border-left: 5px solid var(--prg-color);
  border-radius: var(--cengi-radius-sm);
  background: color-mix(in srgb, var(--prg-color) 7%, #fff);
}
.prg-preview strong { display: block; color: var(--cengi-primary-deep); font-size: 14px; }
.prg-preview small { color: var(--cengi-muted); font-size: 12px; }
.prg-req { color: #FF6B00; }
.prg-sigla { text-transform: uppercase; font-weight: 700; letter-spacing: .06em; max-width: 140px; }
.prg-aviso { color: #b84d00; font-weight: 600; }
.prg-counter { display: block; margin-top: 4px; color: #8a9b8e; font-size: 11px; text-align: right; }
.prg-swatches { display: flex; flex-wrap: wrap; gap: 10px; }
.prg-swatch {
  display: grid;
  place-items: center;
  width: 36px; height: 36px;
  border: 3px solid #fff;
  border-radius: 50%;
  box-shadow: 0 0 0 1px #CED2D5;
  color: #1f2a1f;
  font-size: 13px;
  cursor: pointer;
  transition: transform .12s ease, box-shadow .12s ease;
}
.prg-swatch:hover { transform: scale(1.08); }
.prg-swatch.is-active { box-shadow: 0 0 0 2px var(--cengi-primary-deep); }
.prg-swatch:focus-visible { outline: none; box-shadow: 0 0 0 3px rgba(115, 188, 37, .45); }

/* ---------- Responsive ---------- */

/* ---------- Avance, actividad y filtros ---------- */
.prg-stat-sub { color: var(--cengi-muted); font-size: 11px; line-height: 1.3; margin-top: 1px; }
button.prg-stat { width: 100%; text-align: left; font: inherit; cursor: pointer; transition: box-shadow .15s, border-color .15s; }
button.prg-stat:hover { border-color: var(--acento); }
button.prg-stat:focus-visible { outline: 3px solid #A3D300; outline-offset: 2px; }
.prg-stat.is-on { border-color: var(--acento); box-shadow: 0 0 0 2px color-mix(in srgb, var(--acento) 35%, transparent); }
.prg-row, .prg-card { cursor: pointer; }
.prg-row:focus-visible, .prg-card:focus-visible { outline: 3px solid #A3D300; outline-offset: -3px; }
.prg-pill b { font-weight: 700; }
.prg-pill small { color: var(--cengi-muted); font-size: 11px; font-weight: 600; }
.prg-mini { font-size: 12.5px; color: var(--cengi-ink); }
.prg-apilada { display: flex; gap: 2px; height: 20px; }
.prg-apilada span { flex-basis: 0; min-width: 20px; display: grid; place-items: center; border-radius: 4px; font-size: 11px; font-weight: 700; }
.prg-actividad { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0 6px; font-size: 12.5px; color: var(--cengi-ink); }
.prg-actividad small { flex-basis: 100%; color: var(--cengi-muted); font-size: 11px; }
.prg-actividad i { color: var(--cengi-muted); font-size: 11px; }
.prg-actividad.is-alerta, .prg-actividad.is-alerta i { color: #b84d00; font-weight: 600; }
.prg-card-estado { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; }
.prg-leyenda { display: flex; flex-wrap: wrap; gap: 14px; margin: 0 2px 10px; color: var(--cengi-muted); font-size: 11.5px; }
.prg-leyenda-tip { margin-left: auto; }
.prg-leyenda-tip i { width: auto; height: auto; background: none; color: var(--cengi-muted); }
.prg-leyenda i { display: inline-block; width: 10px; height: 10px; margin-right: 5px; border-radius: 3px; vertical-align: -1px; }

/* ---------- Panel de detalle ---------- */
.prg-velo { position: fixed; inset: 0; z-index: 1064; background: rgba(23, 33, 18, .35); }
.prg-panel { position: fixed; top: 0; right: 0; bottom: 0; z-index: 1065; width: min(560px, 100vw); display: flex; flex-direction: column; background: #fff; box-shadow: -12px 0 32px rgba(23, 33, 18, .18); border-left: 5px solid var(--prg-color); }
.prg-panel-h { display: flex; align-items: center; gap: 12px; padding: 16px 18px; border-bottom: 1px solid var(--cengi-border); background: color-mix(in srgb, var(--prg-color) 7%, #fff); }
.prg-panel-t { flex: 1; min-width: 0; }
.prg-panel-t h3 { margin: 0; font-size: 17px; font-weight: 700; color: var(--cengi-primary-deep); }
.prg-panel-t small { color: var(--cengi-muted); font-size: 12.5px; }
.prg-panel-t small i { color: var(--prg-oscuro); margin-right: 3px; }
.prg-panel-b { flex: 1; overflow-y: auto; padding: 16px 18px 24px; }
.prg-panel-b .prg-desc { margin-bottom: 14px; }
.prg-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; }
.prg-kpis > div { padding: 10px; border: 1px solid var(--cengi-border); border-radius: 10px; background: #fafbf8; }
.prg-kpis strong { display: block; font-size: 20px; line-height: 1.1; color: var(--cengi-primary-deep); }
.prg-kpis strong small { font-size: 12px; color: var(--cengi-muted); font-weight: 600; }
.prg-kpis span { display: block; margin-top: 3px; font-size: 11px; line-height: 1.25; color: var(--cengi-muted); }
.prg-panel-act { display: block; margin: 10px 0 0; font-size: 12px; }
.prg-sec { margin-top: 20px; }
.prg-sec-h { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
.prg-sec-h h4 { margin: 0; font-size: 14px; font-weight: 700; color: var(--cengi-primary-deep); }
.prg-sec-h h4 i { color: var(--prg-oscuro); margin-right: 4px; }
.prg-sec-h a { font-size: 12px; font-weight: 600; color: #3f7a12; white-space: nowrap; }
.prg-badge { display: inline-block; margin-left: 4px; padding: 0 7px; border-radius: 999px; background: #f1f3f0; color: var(--cengi-muted); font-size: 11px; }
.prg-proys { list-style: none; margin: 0; padding: 0; border: 1px solid var(--cengi-border); border-radius: 10px; overflow: hidden; }
.prg-proys li { display: flex; align-items: center; gap: 10px; padding: 10px 12px; }
.prg-proys li + li { border-top: 1px solid #edf1ec; }
.prg-proy-main { flex: 1; min-width: 0; }
.prg-proy-main b { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 13px; color: var(--cengi-ink); }
.prg-proy-main small { color: var(--cengi-muted); font-size: 11.5px; }
.prg-cod { font-family: 'Space Grotesk', sans-serif; font-size: 12px; font-weight: 700; letter-spacing: .03em; color: #3f7a12; }
.prg-estado { flex: none; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; background: #f1f3f0; color: #5b6267; }
.prg-estado.is-activo { background: #eef8df; color: #3f7a12; }
.prg-estado.is-pausado { background: #fff6d1; color: #8a6a00; }
.prg-proy-ens { flex: none; padding: 3px 9px; border-radius: 8px; background: #eef7e3; color: #3f7a12; font-size: 12px; font-weight: 700; white-space: nowrap; }
.prg-proy-ens small { font-weight: 600; }
.prg-proy-ens:hover { background: #73BC25; color: #fff; text-decoration: none; }
.prg-vacio-box { padding: 14px; border: 1px dashed var(--cengi-border); border-radius: 10px; color: var(--cengi-muted); font-size: 12.5px; text-align: center; }
.prg-nuevo-proy { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-top: 10px; padding: 10px 12px; border-radius: 10px; background: #f7faf3; font-size: 12.5px; color: var(--cengi-muted); }
.prg-miembros { display: flex; flex-wrap: wrap; gap: 6px; }
.prg-miembros span { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px 3px 3px; border: 1px solid var(--cengi-border); border-radius: 999px; font-size: 12.5px; }
.prg-miembros i { display: grid; place-items: center; width: 22px; height: 22px; border-radius: 50%; background: #eef7e3; color: #3f7a12; font-style: normal; font-size: 10px; font-weight: 700; }
.prg-nota { margin: 8px 0 0; color: var(--cengi-muted); font-size: 11.5px; }
.prg-fade-enter-active, .prg-fade-leave-active { transition: opacity .2s; }
.prg-fade-enter, .prg-fade-leave-to { opacity: 0; }
.prg-slide-enter-active, .prg-slide-leave-active { transition: transform .22s ease; }
.prg-slide-enter, .prg-slide-leave-to { transform: translateX(100%); }
@media (max-width: 640px) {
  .prg-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .prg-proys li { flex-wrap: wrap; }
}
@media (max-width: 1100px) {
  .prg-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 900px) {
  .prg-list-head { display: none; }
  .prg-row {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    padding: 14px 16px 14px 20px;
  }
  .prg-row-main { grid-column: 1 / -1; padding-right: 110px; }
  .prg-row-lider { grid-column: 1 / -1; }
  .prg-estados, .prg-actividad { grid-column: 1 / -1; }
  .prg-estados { order: 5; }
  .prg-actividad { order: 6; }
  .prg-estados::before { content: attr(data-label); display: block; margin-bottom: 3px; color: var(--cengi-muted); font-size: 10px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
  .prg-row-actions { opacity: 1; }
  .prg-row-actions { position: absolute; top: 12px; right: 12px; }
  .prg-num {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
    padding: 6px 4px;
    border-radius: var(--cengi-radius-sm);
    background: #fafbf8;
  }
  .prg-num::before {
    content: attr(data-label);
    color: var(--cengi-muted);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
  }
}
@media (max-width: 640px) {
  .prg-stats { gap: 10px; }
  .prg-stat { padding: 12px; gap: 10px; }
  .prg-stat-icon { width: 36px; height: 36px; font-size: 15px; }
  .prg-stat-value { font-size: 20px; }
  .prg-search { flex: 1 1 100%; }
  .prg-sort { flex: 1 1 0; min-width: 0; }
  .prg-leyenda-tip { display: none; }
  .prg-nuevo { flex: 1 1 100%; margin-left: 0; }
  .prg-grid { grid-template-columns: 1fr; }
  .prg-actions { opacity: 1; }
}
</style>
