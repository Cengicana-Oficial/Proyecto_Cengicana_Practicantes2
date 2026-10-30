<template>
  <div class="modal fade" ref="modal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-xl modal-dialog-scrollable det-dialog" role="document">
      <div class="modal-content det">
        <div class="modal-header det-header">
          <div class="det-title" v-if="e">
            <div class="det-avatar"><i class="fas fa-seedling"></i></div>
            <div>
              <h5 class="modal-title mb-0">Ensayo {{ e.codigo }}<span v-if="e.nombre" class="det-nombre"> · {{ e.nombre }}</span></h5>
              <small>{{ e.proyecto ? e.proyecto.codigo + ' — ' + e.proyecto.nombre : 'Sin proyecto' }}</small>
            </div>
            <span class="det-estado" :class="claseEstado(e.estado)">{{ e.estado }}</span>
          </div>
          <div class="det-title" v-else><h5 class="modal-title mb-0">Cargando ensayo...</h5></div>
          <div class="det-header-actions">
            <button v-if="e && permisos.editar" type="button" class="btn btn-secondary btn-sm" @click="editar">
              <i class="fas fa-pen"></i> Editar
            </button>
            <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
          </div>
        </div>

        <div class="det-tabs" role="tablist" v-if="e">
          <button
            v-for="(t, i) in tabs" :key="t.id" type="button" role="tab"
            :class="{ 'is-active': tab === t.id }" :aria-selected="tab === t.id ? 'true' : 'false'"
            @click="cambiarTab(t.id)"
          >
            <i :class="t.icon"></i><span>{{ t.label }}</span>
            <em v-if="t.count">{{ t.count }}</em>
          </button>
        </div>

        <div class="modal-body det-body">
          <div class="det-loading" v-if="cargando && !d"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>

          <template v-if="d">
            <!-- ============ INFORMACION ============ -->
            <section v-show="tab === 'info'" class="det-info">
              <div class="det-info-main">
                <p v-if="e.objetivo" class="det-objetivo"><i class="fas fa-bullseye"></i> <b>Objetivo:</b> {{ e.objetivo }}</p>
                <div class="det-ficha">
                  <div v-for="g in gruposInfo" :key="g.titulo" class="det-ficha-grupo">
                    <h6><i :class="g.icono"></i> {{ g.titulo }}</h6>
                    <dl>
                      <template v-for="f in g.campos">
                        <dt :key="'t' + f.label">{{ f.label }}</dt>
                        <dd :key="'d' + f.label" :class="{ 'det-vacio': !f.valor }">{{ f.valor || 'Sin dato' }}</dd>
                      </template>
                    </dl>
                  </div>
                </div>
                <div class="det-crono">
                  <h6><i class="fas fa-stream"></i> Cronograma del ensayo <small>siembra o corte, evaluaciones, muestreos y cosecha</small></h6>
                  <ensayos-ciclo :ensayos="[e]" :estados="ESTADOS_ENSAYO" compacto></ensayos-ciclo>
                </div>
              </div>

              <aside class="det-info-side">
                <!-- Ciclo del cultivo -->
                <div class="det-card">
                  <h6><i class="fas fa-seedling"></i> Ciclo del cultivo</h6>
                  <template v-if="e.fecha_siembra">
                    <div class="det-ciclo-edad"><b>{{ edadMeses }}</b> <span>{{ e.ciclo || '' }}</span></div>
                    <div class="det-ciclo-linea">
                      <div class="det-ciclo-barra"><i :style="{ width: (avanceCiclo === null ? 100 : avanceCiclo) + '%' }" :class="{ 'is-abierta': avanceCiclo === null }"></i></div>
                      <div class="det-ciclo-fechas">
                        <span>{{ e.ciclo && e.ciclo !== 'Plantia' ? 'Corte' : 'Siembra' }}<br><b>{{ fmtFecha(e.fecha_siembra) }}</b></span>
                        <span class="text-right">{{ e.fecha_cosecha ? 'Cosechado' : 'Cosecha prevista' }}<br><b>{{ fmtFecha(e.fecha_cosecha || e.fecha_cosecha_prevista) || '—' }}</b></span>
                      </div>
                    </div>
                    <small v-if="textoCosecha" :class="{ 'is-alerta': alertaCosecha }">{{ textoCosecha }}</small>
                  </template>
                  <p v-else class="det-vacio-txt">
                    Registre la fecha de siembra o corte para calcular la edad y los dias despues de siembra de cada evaluacion.
                    <a v-if="permisos.editar" href="#" @click.prevent="editar">Completar</a>
                  </p>
                </div>

                <!-- Avance -->
                <div class="det-card">
                  <h6><i class="fas fa-tasks"></i> Avance del ensayo</h6>
                  <component :is="evaluacionesUrl ? 'a' : 'div'" :href="evaluacionesUrl ? `${evaluacionesUrl}?ensayo=${e.id}` : null" class="det-avance">
                    <i class="fas fa-clipboard-check"></i>
                    <span><b>{{ e.evaluaciones_count || 0 }}</b> valores evaluados<small>{{ e.ultima_evaluacion ? 'Ultima: ' + fmtFecha(e.ultima_evaluacion) + ' · ' + fechasEvaluacion + ' fecha(s)' : 'Sin evaluaciones' }}</small></span>
                    <i v-if="evaluacionesUrl" class="fas fa-chevron-right"></i>
                  </component>
                  <component :is="muestrasUrl ? 'a' : 'div'" :href="muestrasUrl ? `${muestrasUrl}?buscar=${encodeURIComponent(e.codigo)}` : null" class="det-avance">
                    <i class="fas fa-vial"></i>
                    <span><b>{{ e.muestras_completadas_count || 0 }}/{{ e.muestras_count || 0 }}</b> muestras con resultado<small>{{ e.ultimo_muestreo ? 'Ultimo muestreo: ' + fmtFecha(e.ultimo_muestreo) : 'Sin muestreos' }}</small></span>
                    <i v-if="muestrasUrl" class="fas fa-chevron-right"></i>
                  </component>
                  <button type="button" class="det-avance" @click="cambiarTab('ndvi')">
                    <i class="fas fa-satellite"></i>
                    <span><b>{{ (d.ndvi_imagenes || []).length }}</b> imagenes NDVI<small>{{ d.ndvi.length }} lecturas por tratamiento</small></span>
                    <i class="fas fa-chevron-right"></i>
                  </button>
                  <button type="button" class="det-avance" @click="cambiarTab('bitacora')">
                    <i class="fas fa-book-open"></i>
                    <span><b>{{ d.bitacora.length }}</b> entradas de bitacora<small>{{ d.bitacora.length ? 'Ultima: ' + fmtFecha(d.bitacora[0].fecha) + ' · ' + d.bitacora[0].tipo : 'Sin registros de manejo' }}</small></span>
                    <i class="fas fa-chevron-right"></i>
                  </button>
                  <button type="button" class="det-avance" @click="cambiarTab('archivos')">
                    <i class="fas fa-paperclip"></i>
                    <span><b>{{ d.archivos.length }}</b> archivos<small>Protocolo, croquis, datos e informes</small></span>
                    <i class="fas fa-chevron-right"></i>
                  </button>
                </div>

                <button type="button" class="det-gps" :class="{ 'is-off': !tieneGps }" @click="tieneGps ? cambiarTab('mapa') : (permisos.editar && editar())">
                  <i :class="tieneGps ? 'fas fa-map-marker-alt' : 'fas fa-location-arrow'"></i>
                  <template v-if="tieneGps">{{ Number(e.lat).toFixed(5) }}, {{ Number(e.lng).toFixed(5) }} · ver mapa</template>
                  <template v-else>Sin coordenadas GPS: agreguelas para usar mapa y clima</template>
                </button>
              </aside>
            </section>

            <!-- ============ ESTRUCTURA ============ -->
            <section v-show="tab === 'estructura'">
              <div class="det-sec-head">
                <h6><i class="fas fa-flask"></i> Tratamientos</h6>
                <div v-if="permisos.editar" class="det-sec-actions">
                  <button type="button" class="btn btn-secondary btn-sm" @click="agregarTratamiento" :disabled="ocupado">
                    <i class="fas fa-plus"></i> Agregar tratamiento
                  </button>
                  <button type="button" class="btn btn-success btn-sm" @click="panelParcelas = !panelParcelas" :disabled="!d.tratamientos.length">
                    <i class="fas fa-border-all"></i> Generar parcelas
                  </button>
                </div>
              </div>

              <div class="det-panel" v-if="panelParcelas && permisos.editar">
                <p>Defina las repeticiones y el area uniforme por parcela. Se generara la matriz Tratamiento × Repeticion.</p>
                <div class="form-row">
                  <div class="col-sm-4 form-group">
                    <label>Numero de repeticiones</label>
                    <input type="number" min="1" max="20" class="form-control" v-model.number="gen.num_repeticiones">
                  </div>
                  <div class="col-sm-4 form-group">
                    <label>Area por parcela (m²)</label>
                    <input type="number" min="0" step="0.1" class="form-control" v-model="gen.area_parcela">
                  </div>
                  <div class="col-sm-4 form-group d-flex align-items-end">
                    <button type="button" class="btn btn-success btn-block" @click="generarParcelas" :disabled="ocupado">
                      <i class="fas fa-magic"></i> Generar {{ d.tratamientos.length * (gen.num_repeticiones || 0) }} parcelas
                    </button>
                  </div>
                </div>
                <div class="custom-control custom-checkbox mb-2">
                  <input type="checkbox" class="custom-control-input" id="gen-aleatorizar" v-model="gen.aleatorizar">
                  <label class="custom-control-label" for="gen-aleatorizar">
                    Aleatorizar tratamientos dentro de cada repeticion <span class="det-muted">(bloques completos al azar)</span>
                  </label>
                </div>
                <p class="det-warn mb-0" v-if="d.parcelas.length">
                  <i class="fas fa-exclamation-triangle"></i> Se reemplazaran las {{ d.parcelas.length }} parcelas actuales.
                </p>
              </div>

              <div class="det-table-wrap">
                <table class="table det-table is-compacta">
                  <thead><tr><th style="width:110px">Codigo</th><th>Descripcion / factor evaluado</th><th style="width:120px">Unidades (reps)</th><th style="width:90px">Parcelas</th><th v-if="permisos.editar" style="width:50px"></th></tr></thead>
                  <tbody>
                    <tr v-for="t in d.tratamientos" :key="t.id">
                      <td>
                        <input v-if="permisos.editar" class="form-control form-control-sm det-inline" maxlength="20" v-model="t.codigo" @change="guardarTratamiento(t)">
                        <span v-else class="det-tag">{{ t.codigo }}</span>
                      </td>
                      <td>
                        <input v-if="permisos.editar" :ref="'desc-' + t.id" class="form-control form-control-sm det-inline" maxlength="255" v-model="t.descripcion" placeholder="Descripcion del tratamiento" @change="guardarTratamiento(t)">
                        <span v-else>{{ t.descripcion || '—' }}</span>
                      </td>
                      <td>
                        <input v-if="permisos.editar" type="number" min="1" max="50" class="form-control form-control-sm det-inline" v-model="t.unidades" @change="guardarTratamiento(t)">
                        <span v-else>{{ t.unidades || '—' }}</span>
                      </td>
                      <td class="text-center">{{ t.parcelas_count }}</td>
                      <td v-if="permisos.editar" class="text-right">
                        <button type="button" class="det-icon det-icon-danger" title="Eliminar tratamiento" @click="eliminarTratamiento(t)"><i class="fas fa-trash"></i></button>
                      </td>
                    </tr>
                    <tr v-if="!d.tratamientos.length"><td :colspan="permisos.editar ? 5 : 4" class="det-empty-row">Sin tratamientos — agregue el primero.</td></tr>
                  </tbody>
                </table>
              </div>

              <div class="det-sec-head mt-4">
                <h6><i class="fas fa-border-all"></i> Parcelas <small v-if="d.parcelas.length" class="det-muted">{{ d.parcelas.length }} · {{ d.tratamientos.length }} trat. × {{ repeticiones.length }} rep.</small></h6>
                <div class="det-switch" v-if="d.parcelas.length">
                  <button type="button" :class="{ 'is-active': vistaParcelas === 'tabla' }" @click="vistaParcelas = 'tabla'"><i class="fas fa-list"></i> Tabla</button>
                  <button type="button" :class="{ 'is-active': vistaParcelas === 'croquis' }" @click="vistaParcelas = 'croquis'"><i class="fas fa-th"></i> Croquis</button>
                </div>
              </div>

              <div v-if="!d.parcelas.length" class="det-empty-box">
                <i class="fas fa-border-all"></i>
                Sin parcelas — use "Generar parcelas" para crear la matriz Tratamiento × Repeticion.
              </div>
              <div v-else-if="vistaParcelas === 'croquis'" class="det-croquis-wrap">
                <div class="det-croquis-bar">
                  <span class="det-leyenda">
                    <span v-for="t in d.tratamientos" :key="t.id" class="det-tag" :style="{ '--c': colorTrat(t.codigo) }" :title="t.descripcion || ''">{{ t.codigo }}</span>
                  </span>
                  <template v-if="permisos.editar">
                    <small class="det-muted">
                      <template v-if="intercambio">Toque otra parcela de la <b>Rep. {{ intercambio.repeticion }}</b> para intercambiar con {{ intercambio.codigo }} · <a href="#" @click.prevent="intercambio = null">cancelar</a></template>
                      <template v-else>Toque dos parcelas de la misma repeticion para intercambiar sus tratamientos.</template>
                    </small>
                    <button type="button" class="btn btn-secondary btn-sm" @click="aleatorizarParcelas" :disabled="ocupado">
                      <i class="fas fa-random"></i> Aleatorizar
                    </button>
                  </template>
                </div>
                <table class="det-croquis">
                  <thead><tr><th></th><th v-for="c in posiciones" :key="c">{{ c }}</th></tr></thead>
                  <tbody>
                    <tr v-for="r in repeticiones" :key="r">
                      <th>Rep. {{ r }}</th>
                      <template v-for="c in posiciones">
                        <td
                          v-if="celda(r, c)" :key="c" :style="{ '--c': colorTrat(nombreTrat(celda(r, c))) }"
                          :class="{
                            'is-aqui': celda(r, c).id === parcelaGps,
                            'is-geo': celda(r, c).geometria,
                            'is-editable': permisos.editar,
                            'is-sel': intercambio && intercambio.id === celda(r, c).id,
                            'is-otro-bloque': intercambio && intercambio.repeticion !== r,
                          }"
                          :title="celda(r, c).id === parcelaGps ? 'Usted esta aqui (GPS)' : ''"
                          @click="tocarCelda(celda(r, c))"
                        >
                          <strong>{{ celda(r, c).codigo }}</strong>
                          <span class="det-celda-trat">{{ nombreTrat(celda(r, c)) }}</span>
                          <small v-if="celda(r, c).evaluaciones_count">{{ celda(r, c).evaluaciones_count }} eval.</small>
                        </td>
                        <td v-else :key="c" class="is-vacia"></td>
                      </template>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div v-if="vistaParcelas === 'croquis' && concordancia" class="det-conc" :class="concordancia.alertas.length ? 'is-warn' : 'is-ok'">
                <div class="det-conc-h">
                  <i :class="concordancia.alertas.length ? 'fas fa-exclamation-triangle' : 'fas fa-check-circle'"></i>
                  <b>Diseño y croquis</b>
                  <span>Diseño: {{ concordancia.total }} parcelas ({{ d.tratamientos.length }} trat. × {{ repeticiones.length }} rep.) · Croquis: {{ concordancia.ubicadas }} ubicadas · cargado el {{ fmtFecha(String(d.croquis.created_at).slice(0, 10)) }}</span>
                </div>
                <template v-if="concordancia.alertas.length">
                  <ul>
                    <li v-for="(a, i) in concordancia.alertas" :key="i"><i :class="a.icono"></i> {{ a.texto }}</li>
                  </ul>
                  <p class="det-conc-nota">
                    El croquis es el plano fisico: cambiar tratamientos, aleatorizar o regenerar parcelas no lo modifica.
                    Para que coincidan, suba el plano actualizado con las parcelas rotuladas como en SIGEC
                    ({{ d.parcelas[0].codigo }} … {{ d.parcelas[d.parcelas.length - 1].codigo }}) y use <b>Detectar en el PDF</b>,
                    o ubique las parcelas con <b>Rejilla</b> o <b>Dibujar</b>.
                  </p>
                  <div v-if="permisos.editar" class="det-conc-acc">
                    <label class="btn btn-success btn-sm mb-0">
                      <i class="fas fa-sync-alt"></i> Reemplazar croquis
                      <input type="file" accept=".pdf,.png,.jpg,.jpeg" hidden @change="$refs.croquisMapa && $refs.croquisMapa.subir($event)">
                    </label>
                    <button type="button" class="btn btn-secondary btn-sm" @click="$refs.croquisMapa && $refs.croquisMapa.cambiarModo('rejilla')"><i class="fas fa-th"></i> Ubicar con rejilla</button>
                  </div>
                </template>
                <p v-else class="det-conc-nota">Las {{ concordancia.total }} parcelas del diseño estan ubicadas en el croquis y cada repeticion forma un bloque.</p>
              </div>
              <croquis-mapa
                v-if="vistaParcelas === 'croquis' || !d.parcelas.length"
                ref="croquisMapa"
                :ensayo-id="e.id"
                :croquis="d.croquis"
                :parcelas="d.parcelas"
                :tratamientos="d.tratamientos"
                :storage-url="storageUrl"
                :puede-editar="!!permisos.editar"
                :color-trat="colorTrat"
                v-bind="gpsProps"
                @actualizado="aplicar"
                v-on="gpsEventos"
                @ver-mapa="cambiarTab('mapa')"
              ></croquis-mapa>
              <div v-if="vistaParcelas !== 'croquis' && d.parcelas.length" class="det-table-wrap">
                <table class="table det-table is-compacta">
                  <thead><tr><th>Codigo</th><th>Tratamiento</th><th>Repeticion</th><th>Posicion</th><th style="width:130px">Area (m²)</th><th>Notas</th><th>Evaluaciones</th></tr></thead>
                  <tbody>
                    <tr v-for="p in d.parcelas" :key="p.id">
                      <td><strong>{{ p.codigo }}</strong></td>
                      <td><span class="det-tag" :style="{ '--c': colorTrat(p.tratamiento && p.tratamiento.codigo) }">{{ p.tratamiento ? p.tratamiento.codigo : '—' }}</span></td>
                      <td>{{ p.repeticion }}</td>
                      <td>{{ p.posicion || '—' }}</td>
                      <td>
                        <input v-if="permisos.editar" type="number" min="0" step="0.1" class="form-control form-control-sm det-inline" v-model="p.area" @change="guardarParcela(p)">
                        <span v-else>{{ p.area ? p.area + ' m²' : '—' }}</span>
                      </td>
                      <td>
                        <input v-if="permisos.editar" class="form-control form-control-sm det-inline" maxlength="500" v-model="p.notas" placeholder="Agregar nota" @change="guardarParcela(p)">
                        <span v-else class="det-muted">{{ p.notas || '—' }}</span>
                      </td>
                      <td class="text-center">{{ p.evaluaciones_count }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </section>

            <!-- ============ MAPA ============ -->
            <section v-if="mapaVisto" v-show="tab === 'mapa'">
              <mapa-gps
                ref="mapaGps"
                :ensayo="e"
                :parcelas="d.parcelas"
                :tratamientos="d.tratamientos"
                :croquis="d.croquis"
                :storage-url="storageUrl"
                :puede-editar="!!permisos.editar"
                :color-trat="colorTrat"
                v-bind="gpsProps"
                v-on="gpsEventos"
                :ndvi="d.ndvi"
                :ndvi-imagenes="d.ndvi_imagenes || []"
                @ver-croquis="irACroquis"
                @editar="editar"
              ></mapa-gps>
            </section>

            <!-- ============ NDVI ============ -->
            <section v-show="tab === 'ndvi'">
              <ndvi-imagenes
                :ensayo-id="e.id"
                :imagenes="d.ndvi_imagenes || []"
                :geo="d.imagenes_geo || []"
                :parcelas="d.parcelas"
                :tratamientos="d.tratamientos"
                :fuentes="fuentesNdvi"
                :storage-url="storageUrl"
                :puede-editar="!!permisos.ndvi"
                :color-trat="colorTrat"
                @actualizado="aplicar"
                @ver-mapa="verNdviEnMapa"
              ></ndvi-imagenes>
              <div class="det-sec-head"><h6><i class="fas fa-chart-line"></i> Serie NDVI por tratamiento</h6></div>
              <div class="det-panel" v-if="permisos.ndvi && d.tratamientos.length">
                <p>Ingrese valores NDVI de imagenes satelitales o de dron (0.0 a 1.0). Registrar el mismo tratamiento y periodo actualiza el valor.</p>
                <div class="form-row">
                  <div class="col-md-3 form-group">
                    <label>Tratamiento</label>
                    <select class="form-control" v-model="ndviForm.tratamiento_id">
                      <option v-for="t in d.tratamientos" :key="t.id" :value="t.id">{{ t.codigo }}{{ t.descripcion ? ' — ' + t.descripcion : '' }}</option>
                    </select>
                  </div>
                  <div class="col-md-2 form-group">
                    <label>Periodo</label>
                    <input type="month" class="form-control" v-model="ndviForm.periodo">
                  </div>
                  <div class="col-md-2 form-group">
                    <label>Valor NDVI</label>
                    <input type="number" min="0" max="1" step="0.01" class="form-control" v-model="ndviForm.valor" placeholder="0.75">
                  </div>
                  <div class="col-md-3 form-group">
                    <label>Fuente</label>
                    <select class="form-control" v-model="ndviForm.fuente">
                      <option v-for="f in fuentesNdvi" :key="f" :value="f">{{ f }}</option>
                    </select>
                  </div>
                  <div class="col-md-2 form-group d-flex align-items-end">
                    <button type="button" class="btn btn-success btn-block" @click="guardarNdvi" :disabled="ocupado"><i class="fas fa-plus"></i> Registrar</button>
                  </div>
                </div>
              </div>
              <div v-if="permisos.ndvi && !d.tratamientos.length" class="det-empty-box">
                <i class="fas fa-flask"></i> Defina tratamientos en "Estructura" para registrar NDVI.
              </div>

              <template v-if="d.ndvi.length">
                <div class="det-chart"><highcharts :options="ndviChart"></highcharts></div>
                <div class="det-ndvi-grid">
                  <div>
                    <h6 class="det-h6">Ultimo valor por tratamiento</h6>
                    <div class="det-last" v-for="u in ultimosNdvi" :key="u.codigo">
                      <span class="det-dot" :style="{ background: colorTrat(u.codigo) }"></span>
                      <strong>{{ u.codigo }}</strong>
                      <span class="det-ndvi-val" :class="claseNdvi(u.valor)">{{ u.valor.toFixed(2) }}</span>
                      <small>{{ u.periodo }}</small>
                    </div>
                  </div>
                  <div>
                    <h6 class="det-h6">Registros</h6>
                    <div class="det-table-wrap det-table-short">
                      <table class="table det-table">
                        <thead><tr><th>Periodo</th><th>Trat.</th><th>NDVI</th><th>Fuente</th><th v-if="permisos.ndvi"></th></tr></thead>
                        <tbody>
                          <tr v-for="r in ndviOrdenado" :key="r.id">
                            <td>{{ r.periodo }}</td>
                            <td>{{ r.tratamiento ? r.tratamiento.codigo : '—' }}</td>
                            <td><span class="det-ndvi-val" :class="claseNdvi(r.valor)">{{ Number(r.valor).toFixed(2) }}</span></td>
                            <td class="det-muted">{{ r.fuente || '—' }}</td>
                            <td v-if="permisos.ndvi" class="text-right"><button type="button" class="det-icon det-icon-danger" title="Eliminar" @click="eliminarNdvi(r)"><i class="fas fa-trash"></i></button></td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </template>
              <div v-else class="det-empty-box"><i class="fas fa-satellite"></i> Sin datos NDVI para este ensayo.</div>
              <p class="det-note"><i class="fas fa-info-circle"></i> NDVI: 0.0–0.3 suelo / planta debil · 0.3–0.6 vegetacion moderada · 0.6–1.0 vegetacion densa y saludable.</p>
            </section>

            <section v-if="climaVisto" v-show="tab === 'clima'">
              <ensayo-clima :ensayo="e"></ensayo-clima>
            </section>

            <!-- ============ ARCHIVOS ============ -->
            <section v-show="tab === 'archivos'">
              <div class="det-barra-form" v-if="permisos.archivos">
                <label class="btn btn-secondary btn-sm mb-0 det-elegir">
                  <i class="fas fa-paperclip"></i> Elegir archivo
                  <input type="file" ref="archivo" hidden @change="archivoSel = $event.target.files[0] || null">
                </label>
                <span class="det-archivo-sel" :class="{ 'det-muted': !archivoSel }" :title="archivoSel ? archivoSel.name : ''">{{ archivoSel ? archivoSel.name + ' · ' + tamano(archivoSel.size) : 'Ningun archivo elegido (max. 20 MB)' }}</span>
                <select class="form-control form-control-sm det-carpeta" v-model="carpetaSel" aria-label="Carpeta">
                  <option v-for="c in carpetas" :key="c" :value="c">{{ c }}</option>
                </select>
                <button type="button" class="btn btn-success btn-sm" @click="subirArchivo" :disabled="!archivoSel || subiendo !== null">
                  <i :class="subiendo !== null ? 'fas fa-spinner fa-spin' : 'fas fa-upload'"></i>
                  {{ subiendo !== null ? subiendo + ' %' : 'Adjuntar' }}
                </button>
                <small class="det-candado" :title="`Acceso restringido a los usuarios del proyecto ${e.proyecto ? e.proyecto.codigo : ''}`"><i class="fas fa-lock"></i> Solo el proyecto</small>
              </div>
              <div v-if="!d.archivos.length" class="det-empty-box"><i class="fas fa-paperclip"></i> Sin archivos adjuntos en este ensayo.</div>
              <div v-for="g in archivosPorCarpeta" :key="g.carpeta" class="det-folder">
                <div class="det-folder-head"><i class="fas fa-folder-open"></i> {{ g.carpeta }} <em>{{ g.items.length }}</em></div>
                <div v-for="a in g.items" :key="a.id" class="det-file">
                  <i :class="iconoArchivo(a.tipo)"></i>
                  <div class="det-file-name">
                    <a :href="storageUrl + '/' + a.path" target="_blank" rel="noopener">{{ a.nombre }}</a>
                    <small>{{ tamano(a.tamano) }} · {{ fmtFecha(a.fecha) }}{{ a.subido_por ? ' · ' + a.subido_por.name : '' }}</small>
                  </div>
                  <a class="det-icon" :href="storageUrl + '/' + a.path" :download="a.nombre" title="Descargar"><i class="fas fa-download"></i></a>
                  <button v-if="permisos.eliminar" type="button" class="det-icon det-icon-danger" title="Eliminar" @click="eliminarArchivo(a)"><i class="fas fa-trash"></i></button>
                </div>
              </div>
            </section>

            <!-- ============ BITACORA ============ -->
            <section v-show="tab === 'bitacora'">
              <div class="det-barra-form is-bitacora" v-if="permisos.bitacora">
                <input type="date" class="form-control form-control-sm det-bit-fecha" v-model="bitForm.fecha" aria-label="Fecha">
                <select class="form-control form-control-sm det-bit-tipo" v-model="bitForm.tipo" aria-label="Tipo de actividad">
                  <option v-for="t in tiposBitacora" :key="t" :value="t">{{ t }}</option>
                </select>
                <textarea class="form-control form-control-sm det-bit-desc" rows="1" maxlength="2000" v-model="bitForm.descripcion" placeholder="Actividad realizada, insumos, dosis, observaciones..." aria-label="Descripcion"></textarea>
                <button v-if="bitForm.id" type="button" class="btn btn-secondary btn-sm" @click="resetBitacora">Cancelar</button>
                <button type="button" class="btn btn-success btn-sm" @click="guardarBitacora" :disabled="ocupado">
                  <i class="fas fa-save"></i> {{ bitForm.id ? 'Guardar' : 'Agregar' }}
                </button>
              </div>
              <div v-if="!d.bitacora.length" class="det-empty-box"><i class="fas fa-book-open"></i> Sin entradas en la bitacora para este ensayo.</div>
              <div class="det-timeline" v-else>
                <div v-for="b in d.bitacora" :key="b.id" class="det-entry">
                  <span class="det-entry-dot" :style="{ background: colorTipo(b.tipo) }"></span>
                  <div class="det-entry-card">
                    <div class="det-entry-head">
                      <span class="det-entry-tipo" :style="{ '--c': colorTipo(b.tipo) }">{{ b.tipo }}</span>
                      <strong>{{ fmtFecha(b.fecha) }}</strong>
                      <span class="det-entry-actions">
                        <button v-if="permisos.bitacora" type="button" class="det-icon" title="Editar" @click="editarBitacora(b)"><i class="fas fa-pen"></i></button>
                        <button v-if="permisos.eliminar" type="button" class="det-icon det-icon-danger" title="Eliminar" @click="eliminarBitacora(b)"><i class="fas fa-trash"></i></button>
                      </span>
                    </div>
                    <p>{{ b.descripcion }}</p>
                    <small><i class="fas fa-user"></i> {{ b.responsable ? b.responsable.name : '—' }}</small>
                  </div>
                </div>
              </div>
            </section>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import EnsayoClima from './clima.vue';
import CroquisMapa from './croquis-mapa.vue';
import MapaGps from './mapa-gps.vue';
import NdviImagenes from './ndvi-imagenes.vue';
import EnsayosCiclo from './ciclo.vue';
import { navegacion, distanciaM } from './geo-util';

const COLORES_TRAT = ['#73BC25', '#FF6B00', '#FFCC00', '#A3D300', '#5b6267', '#3f7a12', '#b84d00', '#8a6a00'];

function hoyISO() {
  const d = new Date();
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

export default {
  name: 'EnsayoDetalle',
  components: { EnsayoClima, CroquisMapa, MapaGps, NdviImagenes, EnsayosCiclo },
  props: {
    fuentesNdvi: { type: Array, default: () => [] },
    carpetas: { type: Array, default: () => [] },
    tiposBitacora: { type: Array, default: () => [] },
    storageUrl: { type: String, default: '' },
    permisos: { type: Object, default: () => ({}) },
    evaluacionesUrl: { type: String, default: null },
    muestrasUrl: { type: String, default: null },
  },
  data() {
    return {
      ensayoId: null,
      // Paleta validada de estados de ensayo (la misma de la lista y el cronograma).
      ESTADOS_ENSAYO: [
        { id: 'Planificado', color: '#eda100', texto: '#1f2a12' },
        { id: 'En campo', color: '#5a9e1a', texto: '#ffffff' },
        { id: 'Finalizado', color: '#2a78d6', texto: '#ffffff' },
      ],
      d: null,
      cargando: false,
      ocupado: false,
      tab: 'info',
      panelParcelas: false,
      vistaParcelas: 'tabla',
      gen: { num_repeticiones: 3, area_parcela: 50, aleatorizar: true },
      intercambio: null,
      ndviForm: { tratamiento_id: null, periodo: hoyISO().slice(0, 7), valor: '', fuente: this.fuentesNdvi[0] || null },
      bitForm: this.bitacoraVacia(),
      archivoSel: null,
      carpetaSel: this.carpetas[0] || null,
      subiendo: null,
      climaVisto: false,
      mapaVisto: false,
      // GPS y navegacion compartidos por el croquis (Estructura) y el Mapa GPS.
      gps: { activo: false, error: null, pos: null, seguir: true, destinoId: null, recorrido: [] },
    };
  },
  computed: {
    nav() {
      return navegacion(this.gps.pos, this.d ? this.d.parcelas : [], this.gps.destinoId);
    },
    parcelaGps() { return this.nav.parcela ? this.nav.parcela.id : null; },
    recorridoM() {
      const r = this.gps.recorrido;
      let m = 0;
      for (let i = 1; i < r.length; i++) m += distanciaM(r[i - 1][0], r[i - 1][1], r[i][0], r[i][1]);
      return m;
    },
    gpsProps() {
      return {
        gpsActivo: this.gps.activo, gpsError: this.gps.error, pos: this.gps.pos, seguir: this.gps.seguir,
        nav: this.nav, destinoId: this.gps.destinoId, recorrido: this.gps.recorrido, recorridoM: this.recorridoM,
      };
    },
    gpsEventos() {
      return {
        'alternar-gps': this.alternarGps,
        seguir: (v) => { this.gps.seguir = v; },
        destino: this.fijarDestino,
        'limpiar-recorrido': () => { this.gps.recorrido = []; },
      };
    },
    e() { return this.d ? this.d.ensayo : null; },
    tieneGps() { return !!(this.e && this.e.lat !== null && this.e.lng !== null && this.e.lat !== '' && this.e.lng !== ''); },
    tabs() {
      const d = this.d || { tratamientos: [], parcelas: [], ndvi: [], ndvi_imagenes: [], archivos: [], bitacora: [] };
      return [
        { id: 'info', label: 'Informacion', icon: 'fas fa-info-circle' },
        { id: 'estructura', label: 'Estructura', icon: 'fas fa-border-all', count: d.parcelas.length || null },
        { id: 'mapa', label: 'Mapa GPS', icon: 'fas fa-map-marked-alt' },
        { id: 'ndvi', label: 'NDVI', icon: 'fas fa-satellite', count: (d.ndvi.length + (d.ndvi_imagenes || []).length) || null },
        { id: 'clima', label: 'Clima', icon: 'fas fa-cloud-sun' },
        { id: 'archivos', label: 'Archivos', icon: 'fas fa-paperclip', count: d.archivos.length || null },
        { id: 'bitacora', label: 'Bitacora', icon: 'fas fa-book-open', count: d.bitacora.length || null },
      ];
    },
    camposInfo() {
      const e = this.e;
      return [
        { label: 'Proyecto', valor: e.proyecto ? e.proyecto.nombre : null },
        { label: 'Ingenio', valor: e.ingenio ? e.ingenio.nombre : null },
        { label: 'Finca / Lote', valor: [e.finca, e.lote].filter(Boolean).join(' / ') },
        { label: 'Diseno experimental', valor: e.diseno },
        { label: 'Cultivo / Variedad', valor: [e.cultivo, e.variedad].filter(Boolean).join(' / ') },
        { label: 'Responsable', valor: e.responsable ? e.responsable.name : null },
        { label: 'Repeticiones', valor: e.num_repeticiones ? String(e.num_repeticiones) : null },
        { label: 'Area por parcela', valor: e.area_parcela ? `${Number(e.area_parcela)} m²` : null },
        { label: 'Ciclo', valor: e.ciclo },
        { label: e.ciclo && e.ciclo !== 'Plantia' ? 'Corte anterior' : 'Siembra', valor: this.fmtFecha(e.fecha_siembra) },
        { label: 'Edad del cultivo', valor: this.edadCultivo },
        { label: 'Cosecha', valor: e.fecha_cosecha ? `${this.fmtFecha(e.fecha_cosecha)} (real)` : (e.fecha_cosecha_prevista ? `${this.fmtFecha(e.fecha_cosecha_prevista)} (prevista)` : null) },
        { label: 'Estrato / Zona', valor: [e.estrato, e.zona].filter(Boolean).join(' / ') },
        { label: 'Area total', valor: e.area_total ? `${Number(e.area_total)} ha` : null },
        { label: 'Tipo de suelo', valor: e.tipo_suelo },
      ];
    },
    /**
     * Coincidencia entre el diseño (parcelas de SIGEC) y el croquis (plano fisico).
     * El croquis es una imagen fija: cambiar tratamientos o regenerar parcelas no lo
     * modifica, y la deteccion empareja por el rotulo impreso (P-04 del PDF = P-04 de SIGEC).
     */
    concordancia() {
      const d = this.d;
      if (!d || !d.croquis || !d.parcelas.length) return null;
      const ps = d.parcelas;
      const ubicadas = ps.filter((p) => p.geometria && p.geometria.coordinates);
      // Las parcelas sin ubicar ya las avisa la barra del croquis (con sus acciones); aqui solo se cuentan.
      const alertas = [];
      const tCroquis = Date.parse(d.croquis.created_at);
      const tDiseno = Math.max(...ps.map((p) => Date.parse(p.created_at) || 0));
      if (tCroquis && tDiseno > tCroquis + 60000) {
        alertas.push({ icono: 'fas fa-history', texto: `Las parcelas se generaron el ${this.fmtFecha(String(ps[0].created_at).slice(0, 10))}, despues de cargar el croquis: el plano puede corresponder a un diseño anterior.` });
      }
      const mezcla = this.mezclaBloques(ubicadas);
      if (mezcla) alertas.push({ icono: 'fas fa-random', texto: mezcla });
      return { total: ps.length, ubicadas: ubicadas.length, alertas };
    },
    /** Ficha del ensayo en tres grupos, como la consulta un investigador en campo. */
    gruposInfo() {
      const e = this.e;
      const d = this.d || { tratamientos: [], parcelas: [] };
      return [
        { titulo: 'Ubicacion', icono: 'fas fa-map-marker-alt', campos: [
          { label: 'Proyecto', valor: e.proyecto ? e.proyecto.nombre : null },
          { label: 'Ingenio', valor: e.ingenio ? e.ingenio.nombre : null },
          { label: 'Finca / Lote', valor: [e.finca, e.lote].filter(Boolean).join(' / ') },
          { label: 'Estrato / Zona', valor: [e.estrato, e.zona].filter(Boolean).map((x) => x.charAt(0) + x.slice(1).toLowerCase()).join(' / ') },
          { label: 'Area total', valor: e.area_total ? `${Number(e.area_total)} ha` : null },
          { label: 'Tipo de suelo', valor: e.tipo_suelo },
        ] },
        { titulo: 'Cultivo', icono: 'fas fa-leaf', campos: [
          { label: 'Cultivo', valor: e.cultivo },
          { label: 'Variedad', valor: e.variedad },
          { label: 'Ciclo', valor: e.ciclo },
          { label: e.ciclo && e.ciclo !== 'Plantia' ? 'Corte anterior' : 'Siembra', valor: this.fmtFecha(e.fecha_siembra) },
          { label: 'Cosecha', valor: e.fecha_cosecha ? `${this.fmtFecha(e.fecha_cosecha)} (real)` : (e.fecha_cosecha_prevista ? `${this.fmtFecha(e.fecha_cosecha_prevista)} (prevista)` : null) },
          { label: 'Responsable', valor: e.responsable ? e.responsable.name : null },
        ] },
        { titulo: 'Diseño experimental', icono: 'fas fa-th', campos: [
          { label: 'Diseño', valor: e.diseno },
          { label: 'Tratamientos', valor: d.tratamientos.length ? String(d.tratamientos.length) : null },
          { label: 'Repeticiones', valor: this.repeticiones.length ? String(this.repeticiones.length) : (e.num_repeticiones ? String(e.num_repeticiones) : null) },
          { label: 'Parcelas', valor: d.parcelas.length ? String(d.parcelas.length) : null },
          { label: 'Area por parcela', valor: e.area_parcela ? `${Number(e.area_parcela)} m²` : null },
        ] },
      ];
    },
    edadMeses() {
      const e = this.e;
      if (!e || !e.fecha_siembra) return null;
      const fin = e.fecha_cosecha ? Date.parse(e.fecha_cosecha) : Date.now();
      const dias = Math.floor((fin - Date.parse(e.fecha_siembra)) / 86400000);
      if (dias < 0) return `Por sembrar (en ${-dias} dias)`;
      return `${(dias / 30.44).toFixed(1)} meses · ${dias} dias`;
    },
    /** % del ciclo hasta la cosecha prevista; null si no hay cosecha prevista. */
    avanceCiclo() {
      const e = this.e;
      if (!e || !e.fecha_siembra || !(e.fecha_cosecha || e.fecha_cosecha_prevista)) return null;
      const a = Date.parse(e.fecha_siembra);
      const b = Date.parse(e.fecha_cosecha || e.fecha_cosecha_prevista);
      if (!(b > a)) return null;
      const t = e.fecha_cosecha ? b : Date.now();
      return Math.max(0, Math.min(100, Math.round(((t - a) / (b - a)) * 100)));
    },
    diasCosecha() {
      const e = this.e;
      if (!e || e.fecha_cosecha || !e.fecha_cosecha_prevista) return null;
      return Math.ceil((Date.parse(e.fecha_cosecha_prevista) - Date.now()) / 86400000);
    },
    textoCosecha() {
      if (this.e && this.e.fecha_cosecha) return 'Ensayo cosechado.';
      const d = this.diasCosecha;
      if (d === null) return 'Sin cosecha prevista registrada.';
      if (d < 0) return `La cosecha prevista paso hace ${-d} dias.`;
      return `${this.avanceCiclo} % del ciclo · faltan ${d} dias para la cosecha.`;
    },
    alertaCosecha() { return this.diasCosecha !== null && this.diasCosecha <= 30; },
    fechasEvaluacion() { return ((this.e && this.e.hitos && this.e.hitos.evaluaciones) || []).length; },
    /** Edad desde la siembra o el corte anterior (hasta la cosecha si ya ocurrio). */
    edadCultivo() {
      const e = this.e;
      if (!e || !e.fecha_siembra) return null;
      const fin = e.fecha_cosecha ? Date.parse(e.fecha_cosecha) : Date.now();
      const dias = Math.floor((fin - Date.parse(e.fecha_siembra)) / 86400000);
      if (Number.isNaN(dias)) return null;
      if (dias < 0) return `por sembrar (en ${-dias} dias)`;
      return `${(dias / 30.44).toFixed(1)} meses · ${dias} dias${e.fecha_cosecha ? ' al cosechar' : ''}`;
    },
    repeticiones() {
      return [...new Set(this.d.parcelas.map((p) => p.repeticion))].sort((a, b) => a - b);
    },
    // Rejilla fisica: fila = repeticion, columna = posicion en campo (los
    // tratamientos pueden ir aleatorizados dentro de cada bloque).
    mapaCeldas() {
      const m = {};
      const siguiente = {};
      this.d.parcelas.forEach((p) => {
        let pos = p.posicion || (siguiente[p.repeticion] || 0) + 1;
        while (m[`${p.repeticion}#${pos}`]) pos++;
        siguiente[p.repeticion] = Math.max(siguiente[p.repeticion] || 0, pos);
        m[`${p.repeticion}#${pos}`] = p;
      });
      return m;
    },
    posiciones() {
      const max = Math.max(0, ...Object.keys(this.mapaCeldas).map((k) => Number(k.split('#')[1])));
      return Array.from({ length: max }, (_, i) => i + 1);
    },
    ndviOrdenado() {
      return this.d.ndvi.slice().sort((a, b) => b.periodo.localeCompare(a.periodo));
    },
    ultimosNdvi() {
      const por = {};
      this.d.ndvi.forEach((r) => {
        const c = r.tratamiento ? r.tratamiento.codigo : '?';
        if (!por[c] || r.periodo > por[c].periodo) por[c] = { codigo: c, valor: Number(r.valor), periodo: r.periodo };
      });
      return Object.values(por).sort((a, b) => a.codigo.localeCompare(b.codigo, 'es', { numeric: true }));
    },
    ndviChart() {
      const periodos = [...new Set(this.d.ndvi.map((r) => r.periodo))].sort();
      const series = this.d.tratamientos
        .map((t) => ({
          name: t.codigo,
          color: this.colorTrat(t.codigo),
          data: periodos.map((p) => {
            const r = this.d.ndvi.find((x) => x.tratamiento_id === t.id && x.periodo === p);
            return r ? Number(r.valor) : null;
          }),
          connectNulls: true,
        }))
        .filter((s) => s.data.some((v) => v !== null));
      return {
        chart: { type: 'line', height: 280, backgroundColor: 'transparent', style: { fontFamily: 'Inter, Arial, sans-serif' } },
        title: { text: null },
        credits: { enabled: false },
        xAxis: { categories: periodos },
        yAxis: {
          min: 0, max: 1, title: { text: 'NDVI' },
          plotBands: [
            { from: 0, to: 0.3, color: 'rgba(255,107,0,.06)' },
            { from: 0.3, to: 0.6, color: 'rgba(255,204,0,.08)' },
            { from: 0.6, to: 1, color: 'rgba(115,188,37,.07)' },
          ],
        },
        tooltip: { shared: true, valueDecimals: 2 },
        plotOptions: { line: { marker: { radius: 4 }, lineWidth: 2.5 } },
        series,
      };
    },
    archivosPorCarpeta() {
      const grupos = {};
      this.d.archivos.forEach((a) => {
        const c = a.carpeta || 'General';
        (grupos[c] = grupos[c] || []).push(a);
      });
      return Object.keys(grupos).sort().map((c) => ({ carpeta: c, items: grupos[c] }));
    },
  },
  mounted() {
    const $m = window.$(this.$refs.modal);
    $m.on('shown.bs.modal', () => { if (this.tab === 'mapa' && this.$refs.mapaGps) this.$refs.mapaGps.mostrar(); });
    // Al cerrar el detalle se apaga el GPS (ahorra bateria en campo).
    $m.on('hidden.bs.modal', () => { this.detenerGps(); });
  },
  beforeDestroy() {
    window.$(this.$refs.modal).off('shown.bs.modal hidden.bs.modal');
    this.detenerGps();
  },
  methods: {
    /* ---------------- carga ---------------- */
    abrir(id) {
      if (this.ensayoId !== id) {
        this.d = null;
        this.tab = 'info';
        this.panelParcelas = false;
        this.climaVisto = false;
        this.mapaVisto = false;
        this.gps.destinoId = null;
        this.gps.recorrido = [];
        this.resetBitacora();
      }
      this.ensayoId = id;
      window.$(this.$refs.modal).modal('show');
      this.cargar();
    },
    cargar() {
      this.cargando = true;
      return window.axios.get(`/investigacion/ensayos/${this.ensayoId}`)
        .then(({ data }) => this.aplicar(data))
        .catch((err) => this.error(err, 'No se pudo cargar el ensayo'))
        .finally(() => { this.cargando = false; });
    },
    aplicar(data) {
      this.d = data;
      this.$emit('actualizado', data.ensayo);
      this.gen.num_repeticiones = data.ensayo.num_repeticiones || 3;
      this.gen.area_parcela = data.ensayo.area_parcela !== null ? Number(data.ensayo.area_parcela) : 50;
      if (!data.tratamientos.find((t) => t.id === this.ndviForm.tratamiento_id)) {
        this.ndviForm.tratamiento_id = data.tratamientos.length ? data.tratamientos[0].id : null;
      }
      if (this.gps.destinoId && !data.parcelas.some((p) => p.id === this.gps.destinoId && p.geometria)) this.gps.destinoId = null;
      if (this.tab === 'mapa') this.mapaVisto = true;
      if (this.tab === 'clima') this.climaVisto = true;
    },
    cambiarTab(id) {
      this.tab = id;
      if (id === 'mapa') {
        this.mapaVisto = true;
        this.$nextTick(() => {
          if (!this.$refs.mapaGps) return;
          this.$refs.mapaGps.mostrar();
          if (this.gps.destinoId) setTimeout(() => this.$refs.mapaGps && this.$refs.mapaGps.enfocarParcela(this.gps.destinoId), 200);
        });
      }
      if (id === 'clima') this.climaVisto = true;
    },
    verNdviEnMapa(fecha) {
      this.cambiarTab('mapa');
      this.$nextTick(() => setTimeout(() => { if (this.$refs.mapaGps) this.$refs.mapaGps.verNdvi(fecha); }, 150));
    },
    irACroquis() {
      this.vistaParcelas = 'croquis';
      this.cambiarTab('estructura');
      this.$nextTick(() => setTimeout(() => {
        const c = this.$refs.croquisMapa;
        if (!c) return;
        if (c.mapa) c.mapa.invalidateSize();
        if (this.gps.destinoId) c.enfocarParcela(this.gps.destinoId);
        if (c.$el && c.$el.scrollIntoView) c.$el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 150));
    },

    /* ---------------- GPS y navegacion (compartidos) ---------------- */
    alternarGps() {
      if (this.gps.activo) { this.detenerGps(); return; }
      this.gps.error = null;
      if (!window.isSecureContext) { this.gps.error = 'El GPS del navegador requiere HTTPS (use la direccion https de SIGEC).'; return; }
      if (!navigator.geolocation) { this.gps.error = 'Este dispositivo no permite obtener la ubicacion.'; return; }
      this.gps.activo = true;
      this.gps.seguir = true;
      this.watchId = navigator.geolocation.watchPosition(
        (p) => this.onPosicion(p.coords),
        (e) => {
          this.gps.error = e.code === 1 ? 'Permiso de ubicacion denegado. Habilitelo en el navegador.' : 'No se pudo obtener la posicion GPS (sin senal).';
          if (e.code === 1) this.detenerGps(true);
        },
        { enableHighAccuracy: true, maximumAge: 3000, timeout: 30000 }
      );
    },
    onPosicion(c) {
      this.gps.error = null;
      const pos = { lat: c.latitude, lng: c.longitude, precision: c.accuracy };
      this.gps.pos = pos;
      // Recorrido: solo puntos con buena precision y que avanzan al menos 3 m.
      const r = this.gps.recorrido;
      const ult = r[r.length - 1];
      if (pos.precision <= 30 && (!ult || distanciaM(ult[0], ult[1], pos.lat, pos.lng) >= 3)) {
        this.gps.recorrido = [...r.slice(-1999), [pos.lat, pos.lng]];
      }
      const n = this.nav;
      if (n.destino && n.llegado && !this.avisoLlegada) {
        this.avisoLlegada = n.destino.id;
        this.$swal.fire({ toast: true, position: 'top', icon: 'success', title: `Llego a ${n.destino.codigo}`, timer: 3500, showConfirmButton: false });
      }
    },
    detenerGps(conservarError) {
      if (this.watchId !== null && this.watchId !== undefined && navigator.geolocation) navigator.geolocation.clearWatch(this.watchId);
      this.watchId = null;
      this.gps.activo = false;
      this.gps.pos = null;
      if (!conservarError) this.gps.error = null;
    },
    fijarDestino(id) {
      this.gps.destinoId = id;
      this.avisoLlegada = null;
      if (!id) return;
      if (!this.gps.activo) this.alternarGps();
      this.$nextTick(() => {
        if (this.tab === 'mapa' && this.$refs.mapaGps) this.$refs.mapaGps.enfocarParcela(id);
        if (this.tab === 'estructura' && this.$refs.croquisMapa) this.$refs.croquisMapa.enfocarParcela(id);
      });
    },
    editar() {
      const ensayo = this.e;
      const $m = window.$(this.$refs.modal);
      $m.one('hidden.bs.modal', () => this.$emit('editar', ensayo));
      $m.modal('hide');
    },
    peticion(promesa, exito) {
      this.ocupado = true;
      return promesa
        .then(({ data }) => {
          if (data && data.ensayo) this.aplicar(data);
          if (exito) this.toast(exito);
          return data;
        })
        .catch((err) => { this.error(err); throw err; })
        .finally(() => { this.ocupado = false; });
    },

    /* ---------------- estructura ---------------- */
    agregarTratamiento() {
      const usados = this.d.tratamientos.map((t) => t.codigo);
      let n = this.d.tratamientos.length + 1;
      while (usados.includes('T' + n)) n++;
      this.peticion(
        window.axios.post(`/investigacion/ensayos/${this.ensayoId}/tratamientos`, { codigo: 'T' + n, unidades: this.e.num_repeticiones || null }),
        `Tratamiento T${n} agregado`
      ).then((data) => {
        const nuevo = data.tratamientos[data.tratamientos.length - 1];
        this.$nextTick(() => {
          const ref = this.$refs['desc-' + nuevo.id];
          if (ref && ref[0]) ref[0].focus();
        });
      }).catch(() => {});
    },
    guardarTratamiento(t) {
      this.peticion(
        window.axios.put(`/investigacion/tratamientos/${t.id}`, { codigo: t.codigo, descripcion: t.descripcion, unidades: t.unidades || null }),
        'Tratamiento actualizado'
      ).catch(() => this.cargar());
    },
    eliminarTratamiento(t) {
      this.confirmar('Eliminar tratamiento', `Se eliminara <b>${this.esc(t.codigo)}</b>${t.parcelas_count ? ` y sus ${t.parcelas_count} parcela(s)` : ''}.`)
        .then((ok) => ok && this.peticion(window.axios.delete(`/investigacion/tratamientos/${t.id}`), 'Tratamiento eliminado').catch(() => {}));
    },
    /**
     * Cada repeticion es un bloque: en el plano sus parcelas forman una fila (o una columna).
     * Agrupa los centros de las parcelas ubicadas en filas y en columnas; si en ambos sentidos
     * hay grupos que mezclan repeticiones, el croquis no corresponde al diseño.
     */
    mezclaBloques(ubicadas) {
      if (new Set(ubicadas.map((p) => p.repeticion)).size < 2) return null;
      const lat0 = ubicadas[0].geometria.coordinates[0][0][1];
      const kx = 111320 * Math.cos((lat0 * Math.PI) / 180);
      const pts = ubicadas.map((p) => {
        const anillo = p.geometria.coordinates[0].slice(0, -1);
        const x = anillo.reduce((s, c) => s + c[0], 0) / anillo.length;
        const y = anillo.reduce((s, c) => s + c[1], 0) / anillo.length;
        return { p, x: x * kx, y: y * 110540 };
      });
      // Distancia tipica entre parcelas vecinas: separa filas/columnas por saltos mayores a su mitad.
      const vecinas = pts.map((a) => Math.min(...pts.filter((b) => b !== a).map((b) => Math.hypot(a.x - b.x, a.y - b.y))));
      const umbral = vecinas.sort((a, b) => a - b)[Math.floor(vecinas.length / 2)] / 2;
      const agrupar = (eje) => {
        const orden = pts.slice().sort((a, b) => a[eje] - b[eje]);
        const grupos = [[orden[0]]];
        for (let i = 1; i < orden.length; i += 1) {
          if (orden[i][eje] - orden[i - 1][eje] > umbral) grupos.push([]);
          grupos[grupos.length - 1].push(orden[i]);
        }
        return grupos.filter((g) => new Set(g.map((x) => x.p.repeticion)).size > 1);
      };
      const filas = agrupar('y');
      const columnas = agrupar('x');
      if (!filas.length || !columnas.length) return null;
      const [mixtos, sentido] = filas.length <= columnas.length ? [filas, 'fila'] : [columnas, 'columna'];
      const g = mixtos[0].map((x) => x.p).sort((a, b) => a.repeticion - b.repeticion || a.posicion - b.posicion);
      const porRep = {};
      g.forEach((p) => { (porRep[p.repeticion] = porRep[p.repeticion] || []).push(p.codigo); });
      const detalle = Object.entries(porRep).map(([r, cs]) => `${cs.join(', ')} (Rep. ${r})`).join(' y ');
      return `En el croquis, ${detalle} quedaron en la misma ${sentido}${mixtos.length > 1 ? ` (y ${mixtos.length - 1} ${sentido}(s) mas)` : ''}, pero en el diseño cada repeticion es un bloque: el plano no corresponde al diseño actual.`;
    },
    generarParcelas() {
      if (!(this.gen.num_repeticiones >= 1)) {
        this.$swal.fire({ icon: 'warning', title: 'Repeticiones invalidas', text: 'Indique al menos 1 repeticion.' });
        return;
      }
      const hacer = () => this.peticion(
        window.axios.post(`/investigacion/ensayos/${this.ensayoId}/parcelas/generar`, {
          num_repeticiones: this.gen.num_repeticiones,
          area_parcela: this.gen.area_parcela === '' ? null : this.gen.area_parcela,
          aleatorizar: this.gen.aleatorizar,
        })
      ).then((data) => {
        this.panelParcelas = false;
        this.toast('Parcelas generadas', `${data.parcelas.length} parcelas (${data.tratamientos.length} trat. × ${this.gen.num_repeticiones} rep.)`);
      }).catch(() => {});
      if (this.d.parcelas.length) {
        const conGeo = this.d.parcelas.filter((p) => p.geometria).length;
        this.confirmar('Regenerar parcelas', `Se reemplazaran las <b>${this.d.parcelas.length}</b> parcelas actuales${conGeo ? ` y se perderan los poligonos de ${conGeo} parcela(s) en el croquis` : ''}.`).then((ok) => ok && hacer());
      } else {
        hacer();
      }
    },
    guardarParcela(p) {
      this.peticion(window.axios.put(`/investigacion/parcelas/${p.id}`, { area: p.area === '' ? null : p.area, notas: p.notas }), 'Parcela actualizada')
        .catch(() => this.cargar());
    },
    nombreTrat(p) {
      return p.tratamiento ? p.tratamiento.codigo : (this.d.tratamientos.find((t) => t.id === p.tratamiento_id) || {}).codigo || '—';
    },
    celda(rep, pos) {
      return this.mapaCeldas[`${rep}#${pos}`] || null;
    },
    tocarCelda(p) {
      if (!this.permisos.editar || this.ocupado) return;
      if (!this.intercambio || this.intercambio.repeticion !== p.repeticion) { this.intercambio = p; return; }
      if (this.intercambio.id === p.id) { this.intercambio = null; return; }
      const a = this.intercambio;
      this.intercambio = null;
      if (a.tratamiento_id === p.tratamiento_id) return;
      this.peticion(
        window.axios.post(`/investigacion/ensayos/${this.ensayoId}/parcelas/intercambiar`, { a: a.id, b: p.id }),
        `${a.codigo} ⇄ ${p.codigo}`
      ).catch(() => {});
    },
    aleatorizarParcelas() {
      this.confirmar('Aleatorizar tratamientos', 'Se sorteara de nuevo el orden de los tratamientos dentro de cada repeticion. Los codigos y poligonos de las parcelas se conservan.')
        .then((ok) => ok && this.peticion(
          window.axios.post(`/investigacion/ensayos/${this.ensayoId}/parcelas/aleatorizar`),
          'Tratamientos aleatorizados'
        ).catch(() => {}));
    },
    colorTrat(codigo) {
      const idx = this.d ? this.d.tratamientos.findIndex((t) => t.codigo === codigo) : -1;
      return COLORES_TRAT[(idx < 0 ? 0 : idx) % COLORES_TRAT.length];
    },

    /* ---------------- NDVI ---------------- */
    guardarNdvi() {
      const v = parseFloat(this.ndviForm.valor);
      if (!this.ndviForm.tratamiento_id || !this.ndviForm.periodo || Number.isNaN(v) || v < 0 || v > 1) {
        this.$swal.fire({ icon: 'warning', title: 'Datos invalidos', text: 'Verifique el tratamiento, el periodo y que el NDVI este entre 0 y 1.' });
        return;
      }
      const t = this.d.tratamientos.find((x) => x.id === this.ndviForm.tratamiento_id);
      this.peticion(
        window.axios.post(`/investigacion/ensayos/${this.ensayoId}/ndvi`, { ...this.ndviForm, valor: v }),
        `NDVI registrado: ${t ? t.codigo : ''} · ${this.ndviForm.periodo} · ${v.toFixed(2)}`
      ).then(() => { this.ndviForm.valor = ''; }).catch(() => {});
    },
    eliminarNdvi(r) {
      this.confirmar('Eliminar lectura NDVI', `${this.esc(r.tratamiento ? r.tratamiento.codigo : '')} · ${this.esc(r.periodo)} · ${Number(r.valor).toFixed(2)}`)
        .then((ok) => ok && this.peticion(window.axios.delete(`/investigacion/ndvi/${r.id}`), 'Lectura eliminada').catch(() => {}));
    },
    claseNdvi(v) {
      return v > 0.6 ? 'is-alto' : v > 0.3 ? 'is-medio' : 'is-bajo';
    },

    /* ---------------- archivos ---------------- */
    subirArchivo() {
      if (!this.archivoSel) return;
      if (this.archivoSel.size > 20 * 1024 * 1024) {
        this.$swal.fire({ icon: 'warning', title: 'Archivo muy grande', text: 'El limite es 20 MB.' });
        return;
      }
      const fd = new FormData();
      fd.append('archivo', this.archivoSel);
      fd.append('proyecto_id', this.e.proyecto_id);
      fd.append('ensayo_id', this.e.id);
      if (this.carpetaSel) fd.append('carpeta', this.carpetaSel);
      this.subiendo = 0;
      window.axios.post('/soporte/archivos', fd, {
        onUploadProgress: (ev) => { if (ev.total) this.subiendo = Math.round((ev.loaded / ev.total) * 100); },
      })
        .then(() => {
          this.toast('Archivo adjuntado', this.archivoSel.name);
          this.archivoSel = null;
          if (this.$refs.archivo) this.$refs.archivo.value = '';
          return this.cargar();
        })
        .catch((err) => this.error(err, 'No se pudo subir el archivo'))
        .finally(() => { this.subiendo = null; });
    },
    eliminarArchivo(a) {
      this.confirmar('Eliminar archivo', `Se eliminara <b>${this.esc(a.nombre)}</b>.`).then((ok) => {
        if (!ok) return;
        window.axios.delete(`/soporte/archivos/${a.id}`)
          .then(() => { this.toast('Archivo eliminado', a.nombre); return this.cargar(); })
          .catch((err) => this.error(err));
      });
    },
    iconoArchivo(tipo) {
      return {
        PDF: 'fas fa-file-pdf', Excel: 'fas fa-file-excel', Word: 'fas fa-file-word', Imagen: 'fas fa-file-image', ZIP: 'fas fa-file-archive',
      }[tipo] || 'fas fa-file';
    },
    tamano(bytes) {
      if (!bytes) return '—';
      const u = ['B', 'KB', 'MB', 'GB'];
      let i = 0;
      let n = Number(bytes);
      while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
      return `${n.toFixed(i ? 1 : 0)} ${u[i]}`;
    },

    /* ---------------- bitacora ---------------- */
    bitacoraVacia() {
      return { id: null, fecha: hoyISO(), tipo: this.tiposBitacora[0] || 'Otro', descripcion: '' };
    },
    resetBitacora() {
      this.bitForm = this.bitacoraVacia();
    },
    editarBitacora(b) {
      this.bitForm = { id: b.id, fecha: String(b.fecha).slice(0, 10), tipo: b.tipo, descripcion: b.descripcion };
      this.$el.querySelector('.det-body').scrollTop = 0;
    },
    guardarBitacora() {
      if (!this.bitForm.fecha || !this.bitForm.descripcion.trim()) {
        this.$swal.fire({ icon: 'warning', title: 'Falta informacion', text: 'Indique la fecha y la descripcion.' });
        return;
      }
      const payload = { ensayo_id: this.ensayoId, fecha: this.bitForm.fecha, tipo: this.bitForm.tipo, descripcion: this.bitForm.descripcion.trim() };
      const editando = !!this.bitForm.id;
      this.ocupado = true;
      (editando ? window.axios.put(`/soporte/bitacora/${this.bitForm.id}`, payload) : window.axios.post('/soporte/bitacora', payload))
        .then(() => {
          this.toast(editando ? 'Entrada actualizada' : 'Entrada agregada');
          this.resetBitacora();
          return this.cargar();
        })
        .catch((err) => this.error(err))
        .finally(() => { this.ocupado = false; });
    },
    eliminarBitacora(b) {
      this.confirmar('Eliminar entrada', `${this.esc(b.tipo)} del ${this.esc(String(b.fecha).slice(0, 10))}.`).then((ok) => {
        if (!ok) return;
        window.axios.delete(`/soporte/bitacora/${b.id}`)
          .then(() => { this.toast('Entrada eliminada'); return this.cargar(); })
          .catch((err) => this.error(err));
      });
    },
    colorTipo(tipo) {
      const mapa = {
        Siembra: '#73BC25', 'Fertilización': '#A3D300', 'Control de maleza': '#A3D300', 'Aplicación de producto': '#FFCC00',
        'Evaluación de campo': '#73BC25', Muestreo: '#FFCC00', Cosecha: '#FF6B00', Mantenimiento: '#CED2D5',
        'Incidencia fitosanitaria': '#FF6B00', 'Visita técnica': '#73BC25',
      };
      return mapa[tipo] || '#CED2D5';
    },

    /* ---------------- utilidades ---------------- */
    claseEstado(estado) {
      return { Planificado: 'is-plan', 'En campo': 'is-campo', Finalizado: 'is-fin' }[estado] || '';
    },
    fmtFecha(v) {
      // Fechas DATE serializadas a medianoche UTC: se formatea solo AAAA-MM-DD para no correr un dia.
      const m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
      return m ? `${m[3]}/${m[2]}/${m[1]}` : '';
    },
    confirmar(titulo, html) {
      return this.$swal.fire({
        title: titulo, html, icon: 'warning', showCancelButton: true,
        confirmButtonText: 'Si, continuar', cancelButtonText: 'Cancelar',
        confirmButtonColor: '#FF6B00', cancelButtonColor: '#CED2D5', focusCancel: true,
      }).then((r) => r.isConfirmed);
    },
    toast(title, text) {
      this.$swal.fire({ toast: true, position: 'top-end', icon: 'success', title, text, timer: 2200, showConfirmButton: false });
    },
    error(err, titulo) {
      const r = err && err.response;
      let msg = r && r.data && r.data.message;
      if (r && r.status === 422 && r.data.errors) msg = Object.values(r.data.errors).flat().join(' ');
      if (r && r.status === 403) msg = msg || 'No tiene permisos para esta accion.';
      this.$swal.fire({ icon: 'error', title: titulo || 'No se pudo completar', text: msg || 'Ocurrio un error. Intente de nuevo.' });
    },
    esc(s) {
      const div = document.createElement('div');
      div.textContent = s == null ? '' : String(s);
      return div.innerHTML;
    },
  },
};
</script>

<style scoped>
.det-header { align-items: center; gap: 12px; }
.det-title { display: flex; align-items: center; gap: 12px; min-width: 0; flex-wrap: wrap; }
.det-title small { display: block; color: var(--cengi-muted); font-size: 12px; }
.det-avatar { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 11px; background: #eef8df; color: #73BC25; font-size: 17px; }
.det-header-actions { display: flex; align-items: center; gap: 10px; margin-left: auto; }
.det-header-actions .close { margin: 0; padding: 0 4px; }
.det-estado { padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.det-estado.is-plan { background: #fff5cc; color: #7a5d00; }
.det-estado.is-campo { background: #eef8df; color: #3f7a12; }
.det-estado.is-fin { background: #eef0f1; color: #5b6267; border: 1px solid #CED2D5; }

.det-tabs {
  display: flex; gap: 2px; padding: 0 14px; overflow-x: auto;
  border-bottom: 1px solid var(--cengi-border); background: #fbfcf9;
}
.det-tabs button {
  display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0;
  padding: 11px 12px; border: 0; border-bottom: 3px solid transparent; background: transparent;
  color: var(--cengi-muted); font-size: 12.5px; font-weight: 600; cursor: pointer; white-space: nowrap;
}
.det-tabs button:hover { color: var(--cengi-primary-deep); }
.det-tabs button.is-active { border-bottom-color: #73BC25; color: var(--cengi-primary-deep); }
.det-tabs em { padding: 1px 7px; border-radius: 999px; background: #eef8df; color: #3f7a12; font-size: 10.5px; font-style: normal; font-weight: 700; }
.det-body { min-height: 0; }
/* Ventana de alto fijo: no salta al cambiar de pestaña y la cabecera y las pestañas no se encogen. */
.det-dialog { max-width: min(1360px, 96vw); }
.det { height: calc(100vh - 3.5rem); }
.det-header, .det-tabs { flex-shrink: 0; }
.det-header { padding: 12px 18px; }
.det-body { padding: 14px 18px 18px; }
.det-loading { padding: 40px; color: var(--cengi-muted); text-align: center; }

.det-info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 12px; }
.det-field { padding: 12px 14px; border: 1px solid #edf1ec; border-radius: 10px; background: #fafbf8; }
.det-field span { display: block; margin-bottom: 3px; color: var(--cengi-muted); font-size: 10.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
.det-field strong { color: var(--cengi-ink); font-size: 13px; }
.det-vacio { color: #9aa3a8 !important; font-style: italic; font-weight: 500 !important; }
.det-nombre { color: var(--cengi-muted, #6b7568); font-weight: 600; }
.det-objetivo { margin: 0 0 12px; padding: 9px 12px; border-left: 4px solid #73BC25; border-radius: 8px; background: #f5faef; color: #3d4838; font-size: 13px; }
.det-objetivo i { color: #73BC25; }
.det-gps {
  display: inline-flex; align-items: center; gap: 8px; margin-top: 14px; padding: 7px 12px;
  border-radius: 999px; background: #eef8df; color: #3f7a12; font-size: 12px; font-weight: 600;
}
.det-gps.is-off { background: #fff1e6; color: #b84d00; }
.det-pills { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; padding-top: 16px; border-top: 1px solid #edf1ec; }
.det-pill {
  display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px;
  border: 1px solid var(--cengi-border); border-radius: 999px; background: #fff;
  color: var(--cengi-ink); font-size: 12px; font-weight: 600; cursor: pointer;
}
.det-pill:hover { border-color: #73BC25; background: #f5faef; }
.det-pill i { color: #73BC25; }

.det-switch { display: inline-flex; padding: 3px; border: 1px solid var(--cengi-border); border-radius: 8px; background: #f3f5f1; }
.det-switch button { padding: 4px 10px; border: 0; border-radius: 6px; background: transparent; color: var(--cengi-muted); font-size: 12px; font-weight: 600; cursor: pointer; }
.det-switch button.is-active { background: #fff; color: var(--cengi-primary-deep); box-shadow: 0 1px 3px rgba(30, 42, 26, .12); }
.det-croquis-wrap { overflow-x: auto; }
.det-croquis { border-collapse: separate; border-spacing: 6px; }
.det-croquis th { padding: 4px 8px; color: var(--cengi-muted); font-size: 11px; font-weight: 700; text-align: center; white-space: nowrap; }
.det-croquis td {
  min-width: 78px; padding: 10px 8px; border: 1px solid color-mix(in srgb, var(--c) 45%, #fff); border-radius: 8px;
  background: color-mix(in srgb, var(--c) 14%, #fff); text-align: center;
}
.det-croquis td strong { display: block; color: var(--cengi-primary-deep); font-size: 12.5px; }
.det-croquis td small { color: var(--cengi-muted); font-size: 10.5px; }
.det-croquis td.is-geo strong::after { content: ' \25C9'; color: #5b6267; font-size: 9px; }
.det-croquis-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 0 6px 4px; }
.det-croquis-bar small { flex: 1 1 220px; }
.det-leyenda { display: inline-flex; flex-wrap: wrap; gap: 4px; }
.det-celda-trat { display: inline-block; margin-top: 2px; padding: 0 6px; border-radius: 999px; background: color-mix(in srgb, var(--c) 40%, #fff); color: #33402a; font-size: 10.5px; font-weight: 700; }
.det-croquis td.is-vacia { border: 1px dashed #e2e5e7; background: transparent; }
.det-croquis td.is-editable { cursor: pointer; transition: transform .12s ease, box-shadow .12s ease; }
.det-croquis td.is-editable:hover { box-shadow: 0 2px 6px rgba(30, 42, 26, .15); }
.det-croquis td.is-sel { outline: 3px dashed #FF6B00; outline-offset: 1px; transform: scale(1.04); }
.det-croquis td.is-otro-bloque { opacity: .45; }
.det-croquis td.is-aqui { outline: 3px solid #1f6fbf; outline-offset: 1px; animation: det-aqui 1.4s ease-in-out infinite; }
@keyframes det-aqui { 50% { outline-color: rgba(31, 111, 191, .25); } }

.det-map-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 10px; }
.det-map-bar .det-gps { margin-top: 0; }
.det-map { height: 420px; border: 1px solid var(--cengi-border); border-radius: 10px; overflow: hidden; z-index: 0; }
.det-note { margin-top: 10px; color: var(--cengi-muted); font-size: 11.5px; }
.det-note i { margin-right: 5px; }

.det-chart { margin-bottom: 14px; padding: 10px; border: 1px solid #edf1ec; border-radius: 10px; background: #fbfcf9; }
.det-ndvi-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr); gap: 18px; }
.det-h6 { margin-bottom: 8px; color: var(--cengi-primary-deep); font-size: 13px; font-weight: 700; }
.det-last { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #edf1ec; font-size: 12.5px; }
.det-last small { margin-left: auto; color: var(--cengi-muted); }
.det-dot { width: 10px; height: 10px; flex-shrink: 0; border-radius: 3px; }
.det-ndvi-val { padding: 2px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 700; }
.det-ndvi-val.is-alto { background: #eef8df; color: #3f7a12; }
.det-ndvi-val.is-medio { background: #fff5cc; color: #7a5d00; }
.det-ndvi-val.is-bajo { background: #fff1e6; color: #b84d00; }

.det-alertas { padding: 12px 14px; border: 1px solid #ffd9bf; border-radius: 10px; background: #fff8f2; }
.det-alerta { display: flex; gap: 8px; padding: 6px 0; font-size: 12.5px; }
.det-alerta i { margin-top: 2px; color: #FF6B00; }
.det-ok { padding: 10px 14px; border-radius: 10px; background: #f5faef; color: #3f7a12; font-size: 12.5px; }
.det-ok i { margin-right: 6px; }

.det-folder { margin-bottom: 12px; border: 1px solid #edf1ec; border-radius: 10px; overflow: hidden; }
.det-folder-head { display: flex; align-items: center; gap: 8px; padding: 9px 14px; background: #fafbf8; color: var(--cengi-primary-deep); font-size: 12.5px; font-weight: 700; }
.det-folder-head i { color: #FFCC00; }
.det-folder-head em { margin-left: auto; padding: 1px 8px; border-radius: 999px; background: #eef0f1; color: #5b6267; font-size: 10.5px; font-style: normal; }
.det-file { display: flex; align-items: center; gap: 10px; padding: 9px 14px; border-top: 1px solid #f1f3ef; }
.det-file > i { color: #FF6B00; font-size: 18px; }
.det-file-name { flex: 1; min-width: 0; }
.det-file-name a { display: block; overflow: hidden; color: var(--cengi-ink); font-size: 12.5px; font-weight: 600; text-overflow: ellipsis; white-space: nowrap; }
.det-file-name small { color: var(--cengi-muted); font-size: 11px; }

.det-timeline { position: relative; padding-left: 22px; }
.det-timeline::before { content: ''; position: absolute; left: 6px; top: 6px; bottom: 6px; width: 2px; background: #e3eadf; }
.det-entry { position: relative; margin-bottom: 12px; }
.det-entry-dot { position: absolute; left: -21px; top: 14px; width: 12px; height: 12px; border: 2px solid #fff; border-radius: 50%; box-shadow: 0 0 0 1px #CED2D5; }
.det-entry-card { padding: 10px 14px; border: 1px solid #edf1ec; border-radius: 10px; background: #fff; }
.det-entry-head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.det-entry-head strong { font-size: 12.5px; }
.det-entry-tipo {
  padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700;
  background: color-mix(in srgb, var(--c) 20%, #fff); color: color-mix(in srgb, var(--c) 55%, #1f2a1f);
}
.det-entry-actions { margin-left: auto; }
.det-entry-card p { margin: 6px 0; color: var(--cengi-ink); font-size: 12.5px; white-space: pre-line; }
.det-entry-card small { color: var(--cengi-muted); font-size: 11.5px; }

@media (max-width: 768px) {
  .det-ndvi-grid { grid-template-columns: 1fr; }
  .det-map { height: 320px; }
  .det-tabs button span { display: none; }
  .det-tabs button.is-active span { display: inline; }
}
</style>

<style>
/* Bloques comunes del detalle, compartidos con los subcomponentes (ndvi-imagenes.vue). */
.det-sec-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
.det-sec-head h6 { margin: 0; color: var(--cengi-primary-deep); font-size: 14px; font-weight: 700; }
.det-sec-head h6 i { margin-right: 6px; color: #73BC25; }
.det-sec-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.det-panel { margin-bottom: 16px; padding: 14px; border: 1px solid #dcebc8; border-radius: 10px; background: #f7fbf1; }
.det-panel > p { margin-bottom: 10px; color: var(--cengi-muted); font-size: 12.5px; }
.det-warn { color: #b84d00; font-size: 12px; font-weight: 600; }
.det-table-wrap { overflow-x: auto; border: 1px solid #edf1ec; border-radius: 10px; }
.det-table-short { max-height: 300px; overflow-y: auto; }
.det-table { min-width: 560px; margin: 0; }
.det-table td { vertical-align: middle; }
.det-empty-row { padding: 18px !important; color: var(--cengi-muted); text-align: center; }
.det-tag {
  display: inline-block; padding: 2px 9px; border-radius: 6px;
  background: color-mix(in srgb, var(--c, #73BC25) 18%, #fff);
  color: color-mix(in srgb, var(--c, #73BC25) 60%, #1f2a1f); font-size: 11.5px; font-weight: 700;
}
.det-muted { color: var(--cengi-muted); }
.det-icon {
  display: inline-grid; place-items: center; width: 30px; height: 30px;
  border: 1px solid transparent; border-radius: 8px; background: transparent;
  color: var(--cengi-muted); font-size: 12px; cursor: pointer;
}
.det-icon:hover { border-color: var(--cengi-border); background: var(--cengi-accent-soft); color: var(--cengi-primary-deep); text-decoration: none; }
.det-icon-danger:hover { border-color: #ffd2b3; background: #fff1e6; color: #FF6B00; }
.det-empty-box {
  display: flex; flex-direction: column; align-items: center; gap: 6px; margin-bottom: 14px; padding: 30px 16px;
  border: 1px dashed #CED2D5; border-radius: 10px; color: var(--cengi-muted); font-size: 12.5px; text-align: center;
}
.det-empty-box > i { color: #CED2D5; font-size: 24px; }

/* Marcadores Leaflet (fuera del scope: Leaflet inserta su propio DOM). */
.det-pin { width: 18px; height: 18px; border: 3px solid #fff; border-radius: 50%; background: #73BC25; box-shadow: 0 2px 6px rgba(0, 0, 0, .35); }
.det-pin-parcela {
  display: flex; align-items: center; justify-content: center; width: 24px; height: 24px;
  border: 2px solid #fff; border-radius: 5px; background: #FF6B00; color: #fff;
  font-size: 9.5px; font-weight: 700; box-shadow: 0 1px 4px rgba(0, 0, 0, .3);
}

/* ---------- Informacion: ficha agrupada + columna de ciclo y avance ---------- */
.det-info:not([style*="none"]) { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 16px; align-items: start; }
.det-info-main, .det-crono { min-width: 0; }
.det-ficha { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
.det-ficha-grupo { padding: 12px 14px; border: 1px solid #edf1ec; border-radius: 10px; background: #fafbf8; }
.det-ficha-grupo h6, .det-card h6 { margin: 0 0 8px; color: var(--cengi-primary-deep); font-size: 12.5px; font-weight: 700; }
.det-ficha-grupo h6 i, .det-card h6 i { margin-right: 4px; color: #73BC25; }
.det-ficha-grupo dl { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 5px 12px; margin: 0; font-size: 12.5px; }
.det-ficha-grupo dt { color: var(--cengi-muted); font-weight: 500; white-space: nowrap; }
.det-ficha-grupo dd { margin: 0; color: var(--cengi-ink); font-weight: 600; overflow-wrap: anywhere; }
.det-crono { margin-top: 12px; }
.det-crono h6 { margin: 0 0 6px; color: var(--cengi-primary-deep); font-size: 12.5px; font-weight: 700; }
.det-crono h6 i { margin-right: 4px; color: #73BC25; }
.det-crono h6 small { margin-left: 4px; color: var(--cengi-muted); font-weight: 500; }
.det-info-side { display: flex; flex-direction: column; gap: 12px; }
.det-card { padding: 12px 14px; border: 1px solid #edf1ec; border-radius: 10px; background: #fff; }
.det-ciclo-edad b { font-size: 18px; color: var(--cengi-ink); }
.det-ciclo-edad span { color: var(--cengi-muted); font-size: 12px; font-weight: 600; }
.det-ciclo-barra { height: 8px; margin: 8px 0 6px; background: #edf0ee; border-radius: 99px; overflow: hidden; }
.det-ciclo-barra i { display: block; height: 100%; background: #5a9e1a; border-radius: 99px; }
.det-ciclo-barra i.is-abierta { background: repeating-linear-gradient(90deg, #5a9e1a 0 8px, #a9d07c 8px 12px); }
.det-ciclo-fechas { display: flex; justify-content: space-between; color: var(--cengi-muted); font-size: 11px; line-height: 1.3; }
.det-ciclo-fechas b { color: var(--cengi-ink); font-size: 12px; }
.det-card > small { display: block; margin-top: 6px; color: var(--cengi-muted); font-size: 11.5px; }
.det-card > small.is-alerta { color: #b84d00; font-weight: 700; }
.det-vacio-txt { margin: 0; color: var(--cengi-muted); font-size: 12px; }
.det-avance { display: flex; align-items: center; gap: 10px; width: 100%; padding: 7px 6px; border: 0; border-radius: 8px; background: transparent; color: var(--cengi-ink); font-size: 12.5px; text-align: left; text-decoration: none !important; cursor: pointer; }
.det-avance + .det-avance { border-top: 1px solid #f1f3ef; border-radius: 0; }
.det-avance:hover { background: #f5faef; color: var(--cengi-ink); }
.det-avance > i:first-child { width: 18px; color: #73BC25; text-align: center; }
.det-avance > span { flex: 1; min-width: 0; }
.det-avance small { display: block; color: var(--cengi-muted); font-size: 11px; }
.det-avance > i:last-child { color: #b9c2bc; font-size: 10px; }
div.det-avance { cursor: default; }
button.det-gps { margin-top: 0; border: 0; cursor: pointer; text-align: left; }

/* ---------- Estructura: filas compactas, campos que parecen texto hasta editarlos ---------- */
.det-table.is-compacta td, .det-table.is-compacta th { padding: 4px 10px; vertical-align: middle; }
.det-table.is-compacta th { padding-top: 8px; padding-bottom: 8px; }
.det-inline.form-control { min-height: 0; height: 30px; padding: 3px 8px; border-color: transparent; background: transparent; box-shadow: none; transition: border-color .12s, background .12s; }
.det-inline.form-control:hover { border-color: #dfe5dc; background: #fff; }
.det-inline.form-control:focus { border-color: #73BC25; background: #fff; box-shadow: 0 0 0 2px rgba(115, 188, 37, .18); }
.det-inline.form-control::placeholder { color: #b5bdb7; font-style: italic; }
.det-sec-head h6 small { margin-left: 6px; font-size: 11.5px; font-weight: 500; }
.det-conc { margin: 10px 0; padding: 10px 14px; border-radius: 10px; border: 1px solid #e3eedb; background: #f5faef; font-size: 12.5px; }
.det-conc.is-warn { border-color: #ffd9bf; background: #fff8f2; }
.det-conc-h { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 10px; }
.det-conc-h > i { color: #3f7a12; }
.det-conc.is-warn .det-conc-h > i { color: #c24f00; }
.det-conc-h b { color: var(--cengi-ink); }
.det-conc-h span { color: var(--cengi-muted); font-size: 12px; }
.det-conc ul { margin: 8px 0 6px; padding: 0; list-style: none; }
.det-conc li { display: flex; gap: 8px; padding: 3px 0; color: #5a3a1c; }
.det-conc li i { margin-top: 3px; width: 14px; color: #c24f00; text-align: center; }
.det-conc-nota { margin: 4px 0 0; color: var(--cengi-muted); font-size: 11.5px; }
.det-conc-acc { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }

/* ---------- Archivos y bitacora: formulario en una linea ---------- */
.det-barra-form { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 8px; margin-bottom: 14px; padding: 10px 12px; border: 1px solid #e3eedb; border-radius: 10px; background: #f7faf3; }
.det-elegir { cursor: pointer; white-space: nowrap; }
.det-archivo-sel { flex: 1 1 220px; min-width: 0; align-self: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12.5px; }
.det-carpeta { flex: 0 1 170px; width: auto; }
.det-candado { align-self: center; color: var(--cengi-muted); font-size: 11px; white-space: nowrap; }
.det-bit-fecha { flex: 0 0 150px; width: auto; }
.det-bit-tipo { flex: 0 1 190px; width: auto; }
.det-bit-desc.form-control { flex: 1 1 280px; min-height: 0; height: 31px; resize: none; transition: height .15s ease; }
.det-bit-desc.form-control:focus { height: 84px; resize: vertical; }
.det-barra-form .form-control { min-height: 0; }
.det-timeline .det-entry { margin-bottom: 8px; }
.det-timeline .det-entry-card { padding: 8px 12px; }
.det-timeline .det-entry-card p { margin: 4px 0 2px; font-size: 12.5px; }

@media (max-width: 991px) {
  .det-info:not([style*="none"]) { grid-template-columns: minmax(0, 1fr); }
}
</style>
