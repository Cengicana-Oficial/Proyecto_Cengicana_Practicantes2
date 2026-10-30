<template>
  <div class="mg">
    <!-- ================= ETIQUETAS (resultado) ================= -->
    <template v-if="etiquetas.length">
      <div class="mg-ok">
        <span class="mg-ok-ico"><i class="fas fa-check"></i></span>
        <div class="mg-ok-txt">
          <h5>{{ etiquetas.length }} muestra(s) {{ reimpresion ? 'del lote' : 'generadas' }}</h5>
          <small><b>{{ etiquetas[0].id_muestra }}</b><template v-if="etiquetas.length > 1"> a <b>{{ etiquetas[etiquetas.length - 1].id_muestra }}</b></template> · {{ etiquetas[0].tipo }} · muestreo {{ fmt(etiquetas[0].fecha) }}</small>
        </div>
        <div class="mg-ok-acc">
          <button type="button" class="btn btn-sm mg-btn-sec" @click="copiarIds"><i class="fas fa-copy"></i> Copiar IDs</button>
          <button type="button" class="btn btn-sm mg-btn-sec" @click="descargarCsv"><i class="fas fa-file-csv"></i> CSV</button>
          <a class="btn btn-sm mg-btn-sec" :href="`${base}/soporte/muestras/consulta`"><i class="fas fa-flask"></i> Muestras y resultados</a>
          <button type="button" class="btn btn-sm mg-btn" @click="nuevoLote"><i class="fas fa-plus"></i> Nuevo lote</button>
        </div>
      </div>

      <div class="mg-caja">
        <div class="mg-et-bar">
          <div class="mg-campo">
            <label>Tamaño de etiqueta</label>
            <div class="mg-seg">
              <button v-for="t in tamanos" :key="t.id" type="button" :class="{ 'is-on': tamano === t.id }" @click="tamano = t.id">{{ t.label }} <small>{{ t.mm }}</small></button>
            </div>
          </div>
          <div class="mg-campo">
            <label>Copias por muestra</label>
            <div class="mg-seg">
              <button v-for="c in [1, 2, 3]" :key="c" type="button" :class="{ 'is-on': copias === c }" @click="copias = c">{{ c }}</button>
            </div>
          </div>
          <label class="mg-check"><input type="checkbox" v-model="conDatos"> Mostrar datos de la muestra</label>
          <label class="mg-check" :class="{ 'is-off': !conDatos }"><input type="checkbox" v-model="vaciosALinea" :disabled="!conDatos"> Campos vacios con linea para llenar a mano</label>
          <button type="button" class="btn btn-sm mg-btn mg-imprimir" @click="imprimir"><i class="fas fa-print"></i> Imprimir {{ etiquetas.length * copias }} etiqueta(s)</button>
        </div>
        <p class="mg-nota"><i class="fas fa-info-circle"></i> La etiqueta lleva el ID y su codigo QR; el resto de la informacion queda en la base de datos y se consulta con el ID en <b>Consultar muestras</b>.</p>

        <div class="mg-print" :class="'is-' + tamano">
          <template v-for="m in etiquetas">
            <div v-for="c in copias" :key="m.id + '-' + c" class="mg-etq" :class="{ 'is-denso': conDatos && lineasEtiqueta(m).length > 3, 'is-muy-denso': conDatos && lineasEtiqueta(m).length > 5 }">
              <img v-if="qr[m.id_muestra]" :src="qr[m.id_muestra]" alt="">
              <div class="mg-etq-txt">
                <b><i v-for="(p, pi) in tramosId(m.id_muestra)" :key="pi" class="mg-etq-tramo" :class="{ 'is-largo': p.length > 15 }">{{ p }}</i></b>
                <template v-if="conDatos">
                  <span v-for="(l, li) in lineasEtiqueta(m)" :key="li" :class="{ 'is-vacio': l.vacio }">
                    <template v-if="l.etiqueta">{{ l.etiqueta }}: </template>{{ l.vacio ? '' : l.valor }}
                  </span>
                </template>
              </div>
            </div>
          </template>
        </div>
      </div>
    </template>

    <!-- ================= ARMADO DEL LOTE ================= -->
    <div v-else class="mg-layout">
      <div class="mg-main">
        <!-- Paso 1 -->
        <section class="mg-caja">
          <h6 class="mg-paso"><span>1</span> Sesion de muestreo</h6>
          <div class="mg-sesion">
            <div class="mg-campo">
              <label>Proyecto</label>
              <select class="form-control form-control-sm" v-model="proyectoId" @change="ensayoId = null">
                <option :value="null">Todos</option>
                <option v-for="p in proyectos" :key="p.id" :value="p.id">{{ p.codigo }}{{ p.nombre ? ' · ' + p.nombre : '' }}</option>
              </select>
            </div>
            <div class="mg-campo">
              <label>Ensayo *</label>
              <select class="form-control form-control-sm" v-model="ensayoId" :class="{ 'is-invalid': errors.ensayo_id }">
                <option :value="null">Seleccione...</option>
                <option v-for="e in ensayosFiltrados" :key="e.id" :value="e.id">{{ e.codigo }}{{ e.finca ? ' · ' + e.finca : '' }}{{ e.lote ? ' / ' + e.lote : '' }}</option>
              </select>
            </div>
            <div class="mg-campo">
              <label>Fecha de muestreo *</label>
              <input type="date" class="form-control form-control-sm" v-model="fecha" :max="hoy()" :class="{ 'is-invalid': errors.fecha }">
            </div>
          </div>
          <div class="mg-campo">
            <label>Tipo de muestra *</label>
            <div class="mg-tipos">
              <button v-for="t in tipos" :key="t.nombre" type="button" :class="{ 'is-on': tipo === t.nombre }" @click="tipo = t.nombre">
                <i :class="iconoTipo(t.nombre)"></i>
                <span><b>{{ t.nombre }}</b><small>{{ t.codigo }} · {{ t.analitos ? t.analitos + ' analitos' : 'sin analitos' }}</small></span>
              </button>
            </div>
          </div>
          <div v-if="ensayoSel && tipo" class="mg-preview">
            <i class="fas fa-barcode"></i>
            <div>
              <small>Siguiente ID de esta sesion</small>
              <code>{{ prefijo }}<mark>{{ pad(siguiente) }}</mark>-{{ anio }}</code>
            </div>
            <span class="mg-preview-n">{{ siguiente > 1 ? `${siguiente - 1} muestra(s) previas de ${tipo} en 20${anio}` : `primera muestra de ${tipo} en 20${anio}` }}</span>
          </div>
          <div v-if="tipo" class="mg-campos-tipo">
            <span class="mg-ct-tit"><i class="fas fa-list-alt"></i> Datos que registra el muestreador ({{ tipo }}):</span>
            <span class="mg-ct-base">{{ resumenBase }}</span>
            <span v-for="c in camposActivos" :key="c.id" class="mg-ct-chip">{{ c.etiqueta }}<em v-if="c.unidad"> {{ c.unidad }}</em><b v-if="c.obligatorio">*</b></span>
            <span v-if="!camposActivos.length" class="mg-muted">sin campos adicionales</span>
            <button v-if="puedeConfigurar" type="button" class="mg-link mg-ct-cfg" @click="abrirConfig"><i class="fas fa-cog"></i> Configurar campos</button>
          </div>
        </section>

        <!-- Paso 2 -->
        <section class="mg-caja" :class="{ 'is-bloq': !listo }">
          <div class="mg-paso-cab">
            <h6 class="mg-paso"><span>2</span> Muestras del lote <small v-if="filas.length">{{ filas.length }}</small></h6>
            <button v-if="filas.length" type="button" class="mg-link" @click="filas = []"><i class="fas fa-eraser"></i> Limpiar</button>
          </div>
          <p v-if="!listo" class="mg-nada">Elija ensayo, fecha y tipo de muestra para armar el lote.</p>
          <template v-else>
            <div class="mg-rapido">
              <button type="button" :disabled="!parcelasEnsayo.length || !baseCfg.parcela.activo" :title="baseCfg.parcela.activo ? '' : 'La parcela esta oculta para este tipo de muestra'" @click="unaPorParcela"><i class="fas fa-th"></i> Una por parcela <small>{{ parcelasEnsayo.length }} muestras</small></button>
              <button type="button" :disabled="!tratamientosEnsayo.length" @click="unaPorTratamiento"><i class="fas fa-layer-group"></i> Compuesta por tratamiento <small>{{ tratamientosEnsayo.length }} muestras</small></button>
              <button type="button" :class="{ 'is-on': verCroquis }" :disabled="!parcelasEnsayo.length || !baseCfg.parcela.activo" @click="verCroquis = !verCroquis"><i class="fas fa-hand-pointer"></i> Elegir parcelas <small>clic en la tabla</small></button>
              <button type="button" @click="agregarFila()"><i class="fas fa-plus"></i> Fila manual <small>sin parcela</small></button>
            </div>

            <transition name="mg-fade">
              <div v-if="verCroquis" class="mg-croquis">
                <table>
                  <thead><tr><th></th><th v-for="r in repeticiones" :key="r">R{{ r }}</th></tr></thead>
                  <tbody>
                    <tr v-for="t in tratamientosEnsayo" :key="t.id">
                      <th :title="t.descripcion">{{ t.codigo }}</th>
                      <td v-for="r in repeticiones" :key="r">
                        <button v-if="celdaCroquis(t.id, r)" type="button" :class="{ 'is-on': enLote(celdaCroquis(t.id, r).id) }" @click="toggleParcela(celdaCroquis(t.id, r))">
                          {{ celdaCroquis(t.id, r).codigo }}
                        </button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </transition>

            <div v-if="filas.length" class="mg-tabla-wrap">
              <table class="mg-tabla">
                <thead>
                  <tr>
                    <th>#</th><th>ID de muestra</th>
                    <th v-if="baseCfg.parcela.activo">{{ baseCfg.parcela.etiqueta }}<b v-if="baseCfg.parcela.obligatorio" class="mg-req">*</b></th>
                    <th v-if="baseCfg.tratamiento.activo">{{ baseCfg.tratamiento.etiqueta }}<b v-if="baseCfg.tratamiento.obligatorio" class="mg-req">*</b></th>
                    <th v-if="baseCfg.repeticion.activo">{{ baseCfg.repeticion.etiqueta }}<b v-if="baseCfg.repeticion.obligatorio" class="mg-req">*</b></th>
                    <th v-for="c in camposActivos" :key="c.id" class="mg-th-campo" :title="c.ayuda || ''">
                      {{ c.etiqueta }}<b v-if="c.obligatorio" class="mg-req">*</b><small v-if="c.unidad"> {{ c.unidad }}</small>
                      <button v-if="filas.length > 1" type="button" class="mg-copiar" title="Copiar el valor de la primera fila a todas" @click="copiarATodas(c)"><i class="fas fa-angle-double-down"></i></button>
                    </th>
                    <th v-if="baseCfg.obs.activo">{{ baseCfg.obs.etiqueta }}<b v-if="baseCfg.obs.obligatorio" class="mg-req">*</b></th><th></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(f, i) in filas" :key="f.key" :class="{ 'is-dup': duplicada(f) }">
                    <td class="mg-num">{{ i + 1 }}</td>
                    <td><code class="mg-id">{{ prefijo }}<b>{{ pad(siguiente + i) }}</b>-{{ anio }}</code></td>
                    <td v-if="baseCfg.parcela.activo" :class="{ 'is-err': errBase(i, 'parcela') }" :title="errBase(i, 'parcela') || ''">
                      <select v-model="f.parcela_id" @change="alElegirParcela(f)">
                        <option :value="null">—</option>
                        <option v-for="p in parcelasEnsayo" :key="p.id" :value="p.id">{{ p.codigo }}</option>
                      </select>
                      <span v-if="duplicada(f)" class="mg-dup" title="Esta parcela ya esta en el lote">repetida</span>
                    </td>
                    <td v-if="baseCfg.tratamiento.activo" :class="{ 'is-err': errBase(i, 'tratamiento') }" :title="errBase(i, 'tratamiento') || ''">
                      <select v-model="f.tratamiento_id" :disabled="!!f.parcela_id">
                        <option :value="null">—</option>
                        <option v-for="t in tratamientosEnsayo" :key="t.id" :value="t.id">{{ t.codigo }}</option>
                      </select>
                    </td>
                    <td v-if="baseCfg.repeticion.activo" :class="{ 'is-err': errBase(i, 'repeticion') }"><input v-model.number="f.repeticion" type="number" min="1" :disabled="!!f.parcela_id" class="mg-rep"></td>
                    <td v-for="c in camposActivos" :key="c.id" :class="{ 'is-err': errCampo(i, c) }" :title="errCampo(i, c) || c.ayuda || ''">
                      <select v-if="c.tipo_dato === 'opciones'" v-model="f.datos[c.clave]">
                        <option value=""></option>
                        <option v-for="o in c.opciones || []" :key="o" :value="o">{{ o }}</option>
                      </select>
                      <input v-else-if="c.tipo_dato === 'fecha'" v-model="f.datos[c.clave]" type="date">
                      <input v-else v-model="f.datos[c.clave]" :inputmode="c.tipo_dato === 'numero' ? 'decimal' : 'text'" :placeholder="c.ayuda || '—'" maxlength="255">
                    </td>
                    <td v-if="baseCfg.obs.activo" :class="{ 'is-err': errBase(i, 'obs') }" :title="errBase(i, 'obs') || ''"><input v-model="f.obs" maxlength="500" :placeholder="baseCfg.obs.ayuda || '—'"></td>
                    <td><button type="button" class="mg-icon" title="Quitar" @click="filas.splice(i, 1)"><i class="fas fa-times"></i></button></td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p v-else class="mg-nada">Use un atajo o agregue filas. Cada fila es una muestra con su propio ID.</p>
          </template>
        </section>

        <!-- Generar -->
        <div class="mg-pie" :class="{ 'is-bloq': !listo || !filas.length }">
          <span>
            <template v-if="filas.length && listo"><b>{{ filas.length }}</b> muestra(s): <code>{{ prefijo }}{{ pad(siguiente) }}-{{ anio }}</code><template v-if="filas.length > 1"> a <code>{{ prefijo }}{{ pad(siguiente + filas.length - 1) }}-{{ anio }}</code></template></template>
            <template v-else>Imprima las etiquetas: el modulo Laboratorio las escanea al recibir las muestras y sus resultados regresan a SIGEC.</template>
          </span>
          <p v-if="errorGeneral" class="mg-error"><i class="fas fa-exclamation-circle"></i> {{ errorGeneral }}</p>
          <button type="button" class="btn mg-btn" :disabled="!listo || !filas.length || generando" @click="generar">
            <i :class="generando ? 'fas fa-spinner fa-spin' : 'fas fa-qrcode'"></i> Generar {{ filas.length || '' }} muestra(s) y etiquetas
          </button>
        </div>
      </div>

      <!-- Lotes recientes -->
      <aside class="mg-recientes">
        <h6><i class="fas fa-history"></i> Lotes recientes</h6>
        <p v-if="!lotes.length" class="mg-nada">Todavia no se han generado muestras.</p>
        <article v-for="(l, i) in lotes" :key="i" class="mg-lote">
          <div class="mg-lote-top">
            <span class="mg-lote-tipo" :title="l.tipo"><i :class="iconoTipo(l.tipo)"></i></span>
            <div>
              <b>{{ l.ensayo }} · {{ l.tipo }}</b>
              <small>muestreo {{ fmt(l.fecha) }} · {{ l.solicitante || '—' }}</small>
            </div>
            <span class="mg-lote-n">{{ l.n }}</span>
          </div>
          <code class="mg-lote-rango">{{ l.desde }}<template v-if="l.n > 1"><br>{{ l.hasta }}</template></code>
          <div class="mg-lote-pie">
            <span class="mg-barra" :title="`${l.con_resultado} de ${l.n} con resultado`"><i :style="{ width: (l.n ? (l.con_resultado / l.n) * 100 : 0) + '%' }"></i></span>
            <small>{{ l.con_resultado }}/{{ l.n }} con resultado</small>
            <button type="button" class="mg-link" :disabled="cargandoLote === i" @click="reimprimir(l, i)"><i :class="cargandoLote === i ? 'fas fa-spinner fa-spin' : 'fas fa-tags'"></i> Etiquetas</button>
          </div>
        </article>
      </aside>
    </div>

    <!-- ================= Configuracion de campos por tipo ================= -->
    <transition name="mg-fade"><div v-if="config" class="mg-velo" @click="config = false"></div></transition>
    <transition name="mg-slide">
      <aside v-if="config" class="mg-panel" role="dialog">
        <header class="mg-ph">
          <span class="mg-ph-ico"><i :class="iconoTipo(tipo)"></i></span>
          <div>
            <h5>Campos de {{ tipo }}</h5>
            <small>Datos que llena el muestreador al generar los ID; sirven para el analisis posterior.</small>
          </div>
          <button type="button" class="mg-x" @click="config = false">×</button>
        </header>
        <div class="mg-pb">
          <div class="mg-tipo-sel">
            <button v-for="t in tipos" :key="t.nombre" type="button" :class="{ 'is-on': tipo === t.nombre }" @click="tipo = t.nombre; nuevoCampo()">{{ t.nombre }}</button>
          </div>
          <table class="mg-cfg">
            <thead><tr><th>Orden</th><th>Etiqueta</th><th>Nombre tecnico</th><th>Tipo</th><th>Unidad</th><th>Oblig.</th><th>Estado</th><th></th></tr></thead>
            <tbody>
              <tr v-for="b in BASE" :key="b.clave" class="is-base" :class="{ 'is-off': b.columna && !baseCfg[b.clave].activo, 'is-edit': campoForm.predefinido && campoForm.clave === b.clave }">
                <td>—</td>
                <td><b>{{ b.columna ? baseCfg[b.clave].etiqueta : b.etiqueta }}</b> <span class="mg-pre">Predefinido</span></td>
                <td><code>{{ b.clave }}</code></td>
                <td><span class="mg-tdato">{{ b.tipo }}</span></td>
                <td>—</td>
                <template v-if="b.columna">
                  <td><button type="button" class="mg-oblig" :class="{ 'is-on': baseCfg[b.clave].obligatorio }" :disabled="!baseCfg[b.clave].activo" @click="guardarBase(b, { obligatorio: !baseCfg[b.clave].obligatorio })">{{ baseCfg[b.clave].obligatorio ? 'Si' : 'No' }}</button></td>
                  <td><button type="button" class="mg-estado" :class="{ 'is-on': baseCfg[b.clave].activo }" @click="guardarBase(b, { activo: !baseCfg[b.clave].activo })">{{ baseCfg[b.clave].activo ? 'Activo' : 'Inactivo' }}</button></td>
                  <td><button type="button" class="mg-icon is-edit" title="Editar" @click="editarBase(b)"><i class="fas fa-pen"></i></button></td>
                </template>
                <template v-else>
                  <td>—</td>
                  <td colspan="2" class="mg-auto" :title="b.auto"><i class="fas fa-magic"></i> Automatico</td>
                </template>
              </tr>
              <tr v-for="(c, i) in camposTipo" :key="c.id" :class="{ 'is-off': !c.activo, 'is-edit': campoForm.id === c.id }">
                <td class="mg-orden">
                  <button type="button" :disabled="i === 0" title="Subir" @click="moverCampo(i, -1)"><i class="fas fa-chevron-up"></i></button>
                  <button type="button" :disabled="i === camposTipo.length - 1" title="Bajar" @click="moverCampo(i, 1)"><i class="fas fa-chevron-down"></i></button>
                </td>
                <td><b>{{ c.etiqueta }}</b></td>
                <td><code>{{ c.clave }}</code></td>
                <td><span class="mg-tdato">{{ nombreTipoDato(c.tipo_dato) }}</span></td>
                <td>{{ c.unidad || '—' }}</td>
                <td>{{ c.obligatorio ? 'Si' : 'No' }}</td>
                <td><button type="button" class="mg-estado" :class="{ 'is-on': c.activo }" @click="alternarActivo(c)">{{ c.activo ? 'Activo' : 'Inactivo' }}</button></td>
                <td class="mg-nowrap">
                  <button type="button" class="mg-icon is-edit" title="Editar" @click="editarCampo(c)"><i class="fas fa-pen"></i></button>
                  <button type="button" class="mg-icon" title="Eliminar" @click="eliminarCampo(c)"><i class="fas fa-trash"></i></button>
                </td>
              </tr>
              <tr v-if="!camposTipo.length"><td colspan="8" class="mg-muted mg-cfg-vacio">Sin campos adicionales para {{ tipo }}.</td></tr>
            </tbody>
          </table>

          <h6 class="mg-paso is-form"><i :class="campoForm.predefinido || campoForm.id ? 'fas fa-pen' : 'fas fa-plus-circle'"></i> {{ campoForm.predefinido ? `Dato predefinido: ${campoForm.etiqueta}` : campoForm.id ? `Editar "${campoForm.etiqueta}"` : 'Nuevo campo' }}</h6>
          <p v-if="campoForm.predefinido" class="mg-nota is-cfg"><i class="fas fa-info-circle"></i> Defina si el muestreador ve y llena este dato para <b>{{ tipo }}</b>, si es obligatorio y como se llama en la tabla. Su tipo no cambia.</p>
          <div class="form-row">
            <div class="col-7 form-group">
              <label>Etiqueta *</label>
              <input class="form-control form-control-sm" v-model.trim="campoForm.etiqueta" maxlength="100" :placeholder="ejemplo('etiqueta')">
            </div>
            <div v-if="!campoForm.predefinido" class="col-5 form-group">
              <label>Unidad</label>
              <input class="form-control form-control-sm" v-model.trim="campoForm.unidad" maxlength="30" :placeholder="ejemplo('unidad')">
            </div>
          </div>
          <div v-if="!campoForm.predefinido" class="form-group">
            <label>Tipo de dato</label>
            <div class="mg-seg is-block">
              <button v-for="t in TIPOS_DATO" :key="t.id" type="button" :class="{ 'is-on': campoForm.tipo_dato === t.id }" @click="campoForm.tipo_dato = t.id">{{ t.label }}</button>
            </div>
          </div>
          <div v-if="campoForm.tipo_dato === 'opciones' && !campoForm.predefinido" class="form-group">
            <label>Opciones <small class="mg-muted">(separadas por coma)</small></label>
            <input class="form-control form-control-sm" v-model="campoForm.opcionesTexto" placeholder="Ej. Tercio superior, Tercio medio, Tercio inferior">
          </div>
          <div class="form-group">
            <label>Ayuda para el muestreador</label>
            <input class="form-control form-control-sm" v-model.trim="campoForm.ayuda" maxlength="200" :placeholder="ejemplo('ayuda')">
          </div>
          <div class="mg-checks">
            <label class="mg-check"><input type="checkbox" v-model="campoForm.obligatorio"> Obligatorio</label>
            <label class="mg-check"><input type="checkbox" v-model="campoForm.activo"> Activo</label>
          </div>
          <p v-if="!campoForm.id && !campoForm.predefinido && campoForm.etiqueta" class="mg-muted mg-clave">Nombre tecnico: <code>{{ claveDe(campoForm.etiqueta) }}</code> (no cambia despues)</p>
          <p v-if="errorCampo" class="mg-error is-in"><i class="fas fa-exclamation-circle"></i> {{ errorCampo }}</p>
        </div>
        <footer class="mg-pf">
          <button v-if="campoForm.id || campoForm.predefinido" type="button" class="btn btn-sm mg-btn-sec" @click="nuevoCampo">Cancelar edicion</button>
          <button type="button" class="btn btn-sm mg-btn" :disabled="!campoForm.etiqueta || guardandoCampo" @click="guardarCampo">
            <i :class="guardandoCampo ? 'fas fa-spinner fa-spin' : 'fas fa-save'"></i> {{ campoForm.id || campoForm.predefinido ? 'Guardar cambios' : 'Agregar campo' }}
          </button>
        </footer>
      </aside>
    </transition>
  </div>
</template>

<script>
import QRCode from 'qrcode';

const TAMANOS = [
  { id: 'chica', label: 'Pequeña', mm: '50×25 mm' },
  { id: 'mediana', label: 'Mediana', mm: '70×35 mm' },
  { id: 'grande', label: 'Grande', mm: '100×50 mm' },
];
let llave = 0;
const TIPOS_DATO = [
  { id: 'texto', label: 'Texto' },
  { id: 'numero', label: 'Numero' },
  { id: 'fecha', label: 'Fecha' },
  { id: 'opciones', label: 'Opciones' },
];
// Datos que toda muestra guarda en columnas propias (no configurables).
// auto: lo completa el sistema (fecha de la sesion, finca y lote del ensayo); editable: lo llena el muestreador.
const BASE = [
  { clave: 'fecha', etiqueta: 'Fecha', tipo: 'Fecha', auto: 'de la sesion de muestreo (forma parte del ID)' },
  { clave: 'finca', etiqueta: 'Finca', tipo: 'Texto', auto: 'se toma del ensayo' },
  { clave: 'lote', etiqueta: 'Lote', tipo: 'Texto', auto: 'se toma del ensayo' },
  { clave: 'parcela', etiqueta: 'Parcela', tipo: 'Texto', columna: 'parcela_id' },
  { clave: 'tratamiento', etiqueta: 'Tratamiento', tipo: 'Texto', columna: 'tratamiento_id' },
  { clave: 'repeticion', etiqueta: 'Repeticion', tipo: 'Numero', columna: 'repeticion' },
  { clave: 'obs', etiqueta: 'Observaciones', tipo: 'Texto', columna: 'obs' },
];

export default {
  name: 'MuestrasGenIndex',
  props: {
    proyectos: { type: Array, default: () => [] },
    ensayos: { type: Array, default: () => [] },
    tratamientos: { type: Array, default: () => [] },
    parcelas: { type: Array, default: () => [] },
    tipos: { type: Array, default: () => [] },
    secuencias: { type: Object, default: () => ({}) },
    recientes: { type: Array, default: () => [] },
    campos: { type: Array, default: () => [] },
    puedeConfigurar: { type: Boolean, default: false },
  },
  data() {
    return {
      TIPOS_DATO,
      BASE,
      camposLista: this.campos.slice(),
      config: false,
      campoForm: this.campoVacio(),
      errorCampo: null,
      guardandoCampo: false,
      errCampos: {},
      proyectoId: null,
      ensayoId: null,
      fecha: this.hoy(),
      tipo: null,
      filas: [],
      verCroquis: false,
      generando: false,
      errors: {},
      errorGeneral: null,
      usadas: { ...this.secuencias },
      lotes: this.recientes.slice(),
      cargandoLote: null,
      // etiquetas
      etiquetas: [],
      reimpresion: false,
      qr: {},
      tamanos: TAMANOS,
      tamano: this.leer('tamano', 'mediana'),
      copias: 1,
      conDatos: true,
      vaciosALinea: true,
      base: ((document.querySelector('meta[name="app-base"]') || {}).content || '').replace(/\/+$/, ''),
    };
  },
  computed: {
    ensayosFiltrados() { return this.proyectoId ? this.ensayos.filter((e) => e.proyecto_id === this.proyectoId) : this.ensayos; },
    ensayoSel() { return this.ensayos.find((e) => e.id === this.ensayoId) || null; },
    tipoSel() { return this.tipos.find((t) => t.nombre === this.tipo) || null; },
    listo() { return !!(this.ensayoSel && this.tipoSel && this.fecha); },
    tratamientosEnsayo() {
      return this.tratamientos.filter((t) => t.ensayo_id === this.ensayoId).sort((a, b) => a.codigo.localeCompare(b.codigo, 'es', { numeric: true }));
    },
    tratMap() { return new Map(this.tratamientos.map((t) => [t.id, t])); },
    parcelasEnsayo() {
      const tr = (p) => (this.tratMap.get(p.tratamiento_id) || {}).codigo || '';
      return this.parcelas.filter((p) => p.ensayo_id === this.ensayoId)
        .sort((a, b) => tr(a).localeCompare(tr(b), 'es', { numeric: true }) || (a.repeticion - b.repeticion) || a.codigo.localeCompare(b.codigo, 'es', { numeric: true }));
    },
    repeticiones() { return [...new Set(this.parcelasEnsayo.map((p) => p.repeticion))].filter(Boolean).sort((a, b) => a - b); },
    prefijo() { return this.listo ? `SIGEC-${this.ensayoSel.codigo}-${this.tipoSel.codigo}-` : ''; },
    anio() { return String(this.fecha || this.hoy()).slice(2, 4); },
    siguiente() { return (this.usadas[`${this.prefijo}|${this.anio}`] || 0) + 1; },
    camposTipo() { return this.camposLista.filter((c) => c.tipo_muestra === this.tipo && !c.predefinido).sort((a, b) => a.orden - b.orden || a.id - b.id); },
    /** Ajustes de los datos base para el tipo actual (por defecto: visibles y opcionales). */
    baseCfg() {
      const out = {};
      BASE.filter((b) => b.columna).forEach((b) => {
        const r = this.camposLista.find((c) => c.tipo_muestra === this.tipo && c.predefinido && c.clave === b.clave);
        out[b.clave] = r || { id: null, clave: b.clave, etiqueta: b.etiqueta, activo: true, obligatorio: false, ayuda: '', predefinido: true, tipo_dato: b.tipo === 'Numero' ? 'numero' : 'texto' };
      });
      return out;
    },
    resumenBase() {
      return ['Fecha', 'Finca', 'Lote', ...BASE.filter((b) => b.columna && this.baseCfg[b.clave].activo).map((b) => this.baseCfg[b.clave].etiqueta + (this.baseCfg[b.clave].obligatorio ? '*' : ''))].join(' · ');
    },
    camposActivos() { return this.camposTipo.filter((c) => c.activo); },
  },
  watch: {
    // Al cambiar de tipo (o activar un campo) cada fila debe tener la clave para que el v-model sea reactivo.
    camposActivos() { this.filas.forEach((f) => this.asegurarDatos(f)); this.errCampos = {}; },
    ensayoId() { this.filas = []; this.verCroquis = false; },
    tamano(v) { try { localStorage.setItem('sigec.muestrasgen.tamano', v); } catch (e) { /* sin almacenamiento */ } },
  },
  created() {
    if (this.tipos.length) this.tipo = this.leer('tipo', this.tipos[0].nombre);
    if (!this.tipos.some((t) => t.nombre === this.tipo)) this.tipo = (this.tipos[0] || {}).nombre || null;
  },
  methods: {
    leer(k, def) { try { return localStorage.getItem(`sigec.muestrasgen.${k}`) || def; } catch (e) { return def; } },
    hoy() { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; },
    fmt(s) { if (!s) return ''; const [y, m, d] = String(s).slice(0, 10).split('-'); return `${d}/${m}/${y}`; },
    pad(n) { return String(n).padStart(3, '0'); },
    iconoTipo(t) {
      return { Suelo: 'fas fa-mountain', 'Tejido vegetal': 'fas fa-leaf', Jugos: 'fas fa-tint', Mieles: 'fas fa-flask', Agua: 'fas fa-water', Fertilizantes: 'fas fa-seedling' }[t] || 'fas fa-vial';
    },
    toast(title, text, icon = 'success') { this.$swal.fire({ toast: true, position: 'top-end', icon, title, text, timer: 2400, showConfirmButton: false }); },

    /* ---------- armado ---------- */
    filaDe(p) { return this.asegurarDatos({ key: ++llave, parcela_id: p ? p.id : null, tratamiento_id: p ? p.tratamiento_id : null, repeticion: p ? p.repeticion : null, obs: '', datos: {} }); },
    asegurarDatos(f) {
      if (!f.datos) this.$set(f, 'datos', {});
      this.camposActivos.forEach((c) => { if (f.datos[c.clave] === undefined) this.$set(f.datos, c.clave, ''); });
      return f;
    },
    errCampo(i, c) { return this.errCampos[`${i}|${c.clave}`] || null; },
    errBase(i, clave) { return this.errCampos[`${i}|${clave}`] || null; },
    copiarATodas(c) {
      const v = this.filas[0].datos[c.clave];
      this.filas.forEach((f) => this.$set(f.datos, c.clave, v));
      this.toast(`${c.etiqueta} copiado a ${this.filas.length} filas`, v || '(vacio)', 'info');
    },
    /** Ajuste de un dato base para un tipo cualquiera (la etiqueta puede ser de otro tipo al reimprimir). */
    baseDeTipo(tipo, clave) {
      const r = this.camposLista.find((c) => c.tipo_muestra === tipo && c.predefinido && c.clave === clave);
      const def = BASE.find((b) => b.clave === clave);
      return r || { etiqueta: def.etiqueta, activo: true };
    },
    /**
     * Lineas de la etiqueta: tipo y fecha, parcela/tratamiento, y todos los campos
     * activos del tipo (incluida la observacion). Los vacios salen con linea para
     * escribir a mano, salvo que se desactive.
     */
    // SIGEC-COS-26-01-E01-SU-001-26 -> ["SIGEC-COS-26-01", "-E01-SU-001-26"]: en etiquetas
    // pequeñas el ID se parte entre proyecto y ensayo, no en cualquier letra.
    tramosId(id) {
      const i = String(id).search(/-E\d+-/);
      return i > 0 ? [id.slice(0, i), id.slice(i)] : [id];
    },
    lineasEtiqueta(m) {
      const lineas = [{ valor: `${m.tipo} · ${this.fmt(m.fecha)}` }];
      const ubic = [];
      if (this.baseDeTipo(m.tipo, 'parcela').activo && m.parcela) ubic.push(m.parcela);
      if (this.baseDeTipo(m.tipo, 'tratamiento').activo && m.tratamiento) ubic.push(m.tratamiento);
      if (this.baseDeTipo(m.tipo, 'repeticion').activo && m.repeticion) ubic.push(`R${m.repeticion}`);
      if (ubic.length) lineas.push({ valor: ubic.join(' · ') });
      const datos = m.datos || {};
      this.camposLista
        .filter((c) => c.tipo_muestra === m.tipo && !c.predefinido && (c.activo || datos[c.clave]))
        .sort((a, b) => a.orden - b.orden)
        .forEach((c) => {
          const v = datos[c.clave];
          if (v !== undefined && v !== null && v !== '') lineas.push({ etiqueta: c.etiqueta, valor: `${v}${c.unidad ? ' ' + c.unidad : ''}` });
          else if (this.vaciosALinea) lineas.push({ etiqueta: c.etiqueta + (c.unidad ? ` (${c.unidad})` : ''), vacio: true });
        });
      const obs = this.baseDeTipo(m.tipo, 'obs');
      if (obs.activo) {
        if (m.obs) lineas.push({ etiqueta: obs.etiqueta, valor: m.obs });
        else if (this.vaciosALinea) lineas.push({ etiqueta: obs.etiqueta, vacio: true });
      }
      return lineas;
    },
    resumenDatos(m) {
      const c = this.camposLista.filter((x) => x.tipo_muestra === m.tipo);
      return Object.entries(m.datos || {}).map(([k, v]) => {
        const campo = c.find((x) => x.clave === k);
        return `${campo ? campo.etiqueta : k}: ${v}${campo && campo.unidad ? ' ' + campo.unidad : ''}`;
      }).join(' · ');
    },
    agregarFila(p) { this.filas.push(this.filaDe(p)); },
    unaPorParcela() {
      this.filas = this.parcelasEnsayo.map((p) => this.filaDe(p));
    },
    unaPorTratamiento() {
      this.filas = this.tratamientosEnsayo.map((t) => this.asegurarDatos({ key: ++llave, parcela_id: null, tratamiento_id: t.id, repeticion: null, obs: 'Muestra compuesta', datos: {} }));
    },
    celdaCroquis(tratId, rep) { return this.parcelasEnsayo.find((p) => p.tratamiento_id === tratId && p.repeticion === rep) || null; },
    enLote(parcelaId) { return this.filas.some((f) => f.parcela_id === parcelaId); },
    toggleParcela(p) {
      const i = this.filas.findIndex((f) => f.parcela_id === p.id);
      if (i >= 0) this.filas.splice(i, 1); else this.agregarFila(p);
    },
    alElegirParcela(f) {
      const p = this.parcelasEnsayo.find((x) => x.id === f.parcela_id);
      if (p) { f.tratamiento_id = p.tratamiento_id; f.repeticion = p.repeticion; }
    },
    duplicada(f) { return !!f.parcela_id && this.filas.filter((x) => x.parcela_id === f.parcela_id).length > 1; },

    generar() {
      this.errors = {};
      this.errorGeneral = null;
      // Obligatorios vacios: se marcan antes de enviar.
      const faltan = {};
      this.filas.forEach((f, i) => this.camposActivos.forEach((c) => {
        if (c.obligatorio && !String(f.datos[c.clave] || '').trim()) faltan[`${i}|${c.clave}`] = `${c.etiqueta} es obligatorio`;
      }));
      this.filas.forEach((f, i) => BASE.filter((b) => b.columna).forEach((b) => {
        const cfg = this.baseCfg[b.clave];
        if (cfg.activo && cfg.obligatorio && !String(f[b.columna] === null || f[b.columna] === undefined ? '' : f[b.columna]).trim()) faltan[`${i}|${b.clave}`] = `${cfg.etiqueta} es obligatorio`;
      }));
      this.errCampos = faltan;
      if (Object.keys(faltan).length) {
        this.errorGeneral = `Faltan datos obligatorios en ${new Set(Object.keys(faltan).map((k) => k.split('|')[0])).size} fila(s).`;
        return;
      }
      this.generando = true;
      try { localStorage.setItem('sigec.muestrasgen.tipo', this.tipo); } catch (e) { /* sin almacenamiento */ }
      window.axios.post('/soporte/muestras/generar', {
        ensayo_id: this.ensayoId, tipo: this.tipo, fecha: this.fecha,
        filas: this.filas.map((f) => ({ parcela_id: f.parcela_id, tratamiento_id: f.tratamiento_id, repeticion: f.repeticion || null, obs: this.baseCfg.obs.activo ? f.obs || null : null, datos: f.datos })),
      })
        .then(({ data }) => {
          const ultimo = data[data.length - 1];
          const m = ultimo && ultimo.id_muestra.match(/^(.*-)(\d+)-(\d{2})$/);
          if (m) this.$set(this.usadas, `${m[1]}|${m[3]}`, Number(m[2]));
          this.lotes.unshift({
            ensayo: this.ensayoSel.codigo, tipo: this.tipo, fecha: this.fecha, creado: '', solicitante: data[0].solicitante,
            ids: data.map((x) => x.id), desde: data[0].id_muestra, hasta: ultimo.id_muestra, n: data.length, con_resultado: 0,
          });
          this.mostrarEtiquetas(data, false);
          this.toast(`${data.length} muestra(s) generadas`, 'Imprima las etiquetas para enviarlas al Laboratorio.');
        })
        .catch((err) => {
          const r = err.response;
          if (r && r.status === 422 && r.data.errors) {
            this.errors = r.data.errors;
            this.errorGeneral = Object.values(r.data.errors)[0][0];
            const ec = {};
            Object.entries(r.data.errors).forEach(([k, msg]) => { const m = k.match(/^filas\.(\d+)\.datos\.(.+)$/); if (m) ec[`${m[1]}|${m[2]}`] = msg[0];
              const b = k.match(/^filas\.(\d+)\.(parcela_id|tratamiento_id|repeticion|obs)$/);
              if (b) ec[`${b[1]}|${BASE.find((x) => x.columna === b[2]).clave}`] = msg[0];
            });
            this.errCampos = ec;
          } else this.errorGeneral = r && r.status === 403 ? 'No tiene acceso a este ensayo.' : 'No se pudieron generar las muestras.';
        })
        .finally(() => { this.generando = false; });
    },
    /* ---------- configuracion de campos ---------- */
    campoVacio() { return { id: null, etiqueta: '', unidad: '', tipo_dato: 'texto', opcionesTexto: '', ayuda: '', obligatorio: false, activo: true }; },
    abrirConfig() { this.nuevoCampo(); this.config = true; },
    nuevoCampo() { this.campoForm = this.campoVacio(); this.errorCampo = null; },
    /**
     * Ejemplos del formulario de campos acordes al dato que se edita: para los
     * predefinidos segun su clave y para los campos propios segun el tipo de
     * muestra y el tipo de dato.
     */
    ejemplo(que) {
      const f = this.campoForm;
      const t = this.tipo;
      const porTipo = (mapa, def) => mapa[t] || def;
      if (f.predefinido) {
        if (que !== 'ayuda') return '';
        return {
          parcela: 'Ej. Tomar la muestra en el centro de la parcela, sin bordes',
          tratamiento: 'Ej. Para muestras compuestas, mezclar las repeticiones del tratamiento',
          repeticion: 'Ej. Indicar la repeticion cuando la muestra no tiene parcela',
          obs: porTipo({
            Suelo: 'Ej. Suelo humedo, presencia de piedras o raices',
            'Tejido vegetal': 'Ej. Hojas con sintomas de deficiencia o daño',
            Jugos: 'Ej. Tallos con daño de barrenador, caña volcada',
            Mieles: 'Ej. Condicion o lote de produccion',
            Agua: 'Ej. Agua turbia, nivel bajo del canal',
            Fertilizantes: 'Ej. Producto, presentacion o lote del proveedor',
          }, 'Ej. Condiciones observadas al muestrear'),
        }[f.clave] || '';
      }
      if (que === 'etiqueta') {
        return porTipo({
          Suelo: 'Ej. Profundidad, Humedad del suelo',
          'Tejido vegetal': 'Ej. Pante, Hoja muestreada, Edad del cultivo',
          Jugos: 'Ej. Numero de tallos, Edad de la caña',
          Mieles: 'Ej. Tanque, Lote de produccion',
          Agua: 'Ej. Descripcion, Punto de toma',
          Fertilizantes: 'Ej. Producto, Lote del proveedor',
        }, 'Ej. Nombre del dato');
      }
      if (que === 'unidad') {
        return f.tipo_dato === 'numero' ? porTipo({ Suelo: 'Ej. cm', 'Tejido vegetal': 'Ej. dias', Jugos: 'Ej. tallos', Agua: 'Ej. m', Mieles: 'Ej. L' }, 'Ej. cm, kg, dias') : 'Ej. cm (opcional)';
      }
      // Ayuda para el muestreador segun el tipo de dato del campo.
      if (f.tipo_dato === 'opciones') return 'Ej. Elegir la opcion segun lo observado en campo';
      if (f.tipo_dato === 'fecha') return 'Ej. Fecha de la ultima aplicacion o del corte';
      if (f.tipo_dato === 'numero') {
        return porTipo({
          Suelo: 'Ej. Medir desde la superficie del suelo',
          'Tejido vegetal': 'Ej. Dias despues del corte o siembra',
          Jugos: 'Ej. Tallos molidos para la muestra',
          Agua: 'Ej. Profundidad de toma en metros',
        }, 'Ej. Como medir el valor');
      }
      return porTipo({
        Suelo: 'Ej. 0-20, 20-40',
        'Tejido vegetal': 'Ej. Hoja TVD (+3), tercio medio',
        Jugos: 'Ej. Tallos del surco central',
        Mieles: 'Ej. Tanque o punto de muestreo',
        Agua: 'Ej. Pozo, canal o rio',
        Fertilizantes: 'Ej. Nombre comercial del producto',
      }, 'Ej. Que escribir en este dato');
    },
    nombreTipoDato(t) { return (TIPOS_DATO.find((x) => x.id === t) || {}).label || t; },
    claveDe(etiqueta) {
      const limpio = etiqueta.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9 ]/g, '').trim();
      return limpio.split(/\s+/).map((p, i) => (i ? p.charAt(0).toUpperCase() + p.slice(1) : p)).join('');
    },
    editarCampo(c) {
      this.campoForm = { ...c, unidad: c.unidad || '', ayuda: c.ayuda || '', opcionesTexto: (c.opciones || []).join(', ') };
      this.errorCampo = null;
    },
    editarBase(b) {
      const cfg = this.baseCfg[b.clave];
      this.campoForm = { ...cfg, predefinido: true, clave: b.clave, ayuda: cfg.ayuda || '', unidad: '', opcionesTexto: '' };
      this.errorCampo = null;
    },
    guardarBase(b, cambios) {
      const cfg = { ...this.baseCfg[b.clave], ...cambios };
      window.axios.post('/soporte/muestras/campos', { ...this.payloadCampo(cfg), predefinido: true, clave: b.clave })
        .then(({ data }) => this.reemplazarCampo(data))
        .catch(() => this.toast('No se pudo cambiar', '', 'error'));
    },
    payloadCampo(c) {
      return {
        tipo_muestra: this.tipo, etiqueta: c.etiqueta, tipo_dato: c.tipo_dato, unidad: c.unidad || null, ayuda: c.ayuda || null,
        opciones: c.tipo_dato === 'opciones' ? (c.opcionesTexto || (c.opciones || []).join(',')).split(',').map((o) => o.trim()).filter(Boolean) : null,
        obligatorio: !!c.obligatorio, activo: !!c.activo, orden: c.orden,
      };
    },
    reemplazarCampo(data) {
      const i = this.camposLista.findIndex((x) => x.id === data.id);
      if (i >= 0) this.$set(this.camposLista, i, data); else this.camposLista.push(data);
    },
    guardarCampo() {
      this.guardandoCampo = true;
      this.errorCampo = null;
      const f = this.campoForm;
      let peticion;
      if (f.predefinido) peticion = window.axios.post('/soporte/muestras/campos', { ...this.payloadCampo(f), predefinido: true, clave: f.clave });
      else if (f.id) peticion = window.axios.put(`/soporte/muestras/campos/${f.id}`, this.payloadCampo(f));
      else peticion = window.axios.post('/soporte/muestras/campos', this.payloadCampo(f));
      peticion
        .then(({ data }) => { this.reemplazarCampo(data); this.toast(f.id ? 'Campo actualizado' : 'Campo agregado', `${data.etiqueta} · ${this.tipo}`); this.nuevoCampo(); })
        .catch((err) => { const r = err.response; this.errorCampo = r && r.data && (r.data.errors ? Object.values(r.data.errors)[0][0] : r.data.message) || 'No se pudo guardar.'; })
        .finally(() => { this.guardandoCampo = false; });
    },
    alternarActivo(c) {
      window.axios.put(`/soporte/muestras/campos/${c.id}`, this.payloadCampo({ ...c, activo: !c.activo }))
        .then(({ data }) => this.reemplazarCampo(data))
        .catch(() => this.toast('No se pudo cambiar', '', 'error'));
    },
    moverCampo(i, d) {
      const a = this.camposTipo[i];
      const b = this.camposTipo[i + d];
      const oa = a.orden;
      const ob = b.orden === oa ? oa + d : b.orden;
      Promise.all([
        window.axios.put(`/soporte/muestras/campos/${a.id}`, this.payloadCampo({ ...a, orden: ob })),
        window.axios.put(`/soporte/muestras/campos/${b.id}`, this.payloadCampo({ ...b, orden: oa })),
      ]).then(([ra, rb]) => { this.reemplazarCampo(ra.data); this.reemplazarCampo(rb.data); })
        .catch(() => this.toast('No se pudo reordenar', '', 'error'));
    },
    eliminarCampo(c) {
      this.$swal.fire({
        icon: 'warning', title: `Eliminar "${c.etiqueta}"`, text: 'Si ya tiene datos en muestras, se recomienda desactivarlo.',
        showCancelButton: true, confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#FF6B00',
      }).then((r) => {
        if (!r.isConfirmed) return;
        window.axios.delete(`/soporte/muestras/campos/${c.id}`)
          .then(() => { this.camposLista = this.camposLista.filter((x) => x.id !== c.id); if (this.campoForm.id === c.id) this.nuevoCampo(); })
          .catch((err) => this.$swal.fire({ icon: 'info', title: 'No se elimino', text: (err.response && err.response.data && err.response.data.message) || '' }));
      });
    },
    nuevoLote() {
      // Conserva la sesion (ensayo, tipo y fecha) para el siguiente lote.
      this.etiquetas = [];
      this.filas = [];
    },

    /* ---------- etiquetas ---------- */
    async mostrarEtiquetas(lista, reimpresion) {
      this.reimpresion = reimpresion;
      for (const m of lista) {
        if (!this.qr[m.id_muestra]) {
          // eslint-disable-next-line no-await-in-loop
          this.$set(this.qr, m.id_muestra, await QRCode.toDataURL(m.id_muestra, { width: 256, margin: 0, errorCorrectionLevel: 'M', color: { dark: '#1e2a1a', light: '#ffffff' } }));
        }
      }
      this.etiquetas = lista;
      window.scrollTo(0, 0);
    },
    reimprimir(l, i) {
      this.cargandoLote = i;
      window.axios.get('/soporte/muestras/generar/lote', { params: { ids: l.ids } })
        .then(({ data }) => this.mostrarEtiquetas(data, true))
        .catch(() => this.$swal.fire({ icon: 'error', title: 'No se pudo cargar el lote' }))
        .finally(() => { this.cargandoLote = null; });
    },
    imprimir() { window.print(); },
    copiarIds() {
      const txt = this.etiquetas.map((m) => m.id_muestra).join('\n');
      (navigator.clipboard ? navigator.clipboard.writeText(txt) : Promise.reject())
        .then(() => this.toast('IDs copiados', `${this.etiquetas.length} ID(s) en el portapapeles`))
        .catch(() => this.toast('No se pudo copiar', '', 'error'));
    },
    descargarCsv() {
      const esc = (s) => { const t = String(s === null || s === undefined ? '' : s); return /[;"\n]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t; };
      // Columnas personalizadas presentes en el lote (en el orden configurado).
      const claves = [...new Set(this.etiquetas.flatMap((m) => Object.keys(m.datos || {})))];
      const extra = claves.map((k) => {
        const c = this.camposLista.find((x) => x.tipo_muestra === this.etiquetas[0].tipo && x.clave === k);
        return { k, titulo: c ? `${c.etiqueta}${c.unidad ? ` (${c.unidad})` : ''}` : k, orden: c ? c.orden : 999 };
      }).sort((a, b) => a.orden - b.orden);
      const filas = [['ID muestra', 'Tipo', 'Fecha muestreo', 'Parcela', 'Tratamiento', 'Repeticion', ...extra.map((e) => e.titulo), 'Observacion', 'Estado'].map(esc).join(';')]
        .concat(this.etiquetas.map((m) => [m.id_muestra, m.tipo, m.fecha, m.parcela, m.tratamiento, m.repeticion, ...extra.map((e) => (m.datos || {})[e.k]), m.obs, m.estado].map(esc).join(';')));
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([`﻿${filas.join('\r\n')}`], { type: 'text/csv;charset=utf-8' }));
      a.download = `muestras_${this.etiquetas[0].id_muestra}.csv`;
      a.click();
      setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    },
  },
};
</script>

<style scoped>
/* Paleta Cengicaña */
.mg {
  --verde: #73BC25; --verde-2: #A3D300; --amarillo: #FFCC00; --naranja: #FF6B00; --gris: #CED2D5;
  --verde-osc: #1f5632; --tinta: #1e2a1a; --suave: #6b7568; --borde: #e3e7e1;
}
.mg-layout { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 14px; align-items: start; }
.mg-main { display: flex; flex-direction: column; gap: 12px; min-width: 0; }
.mg-caja { padding: 14px 16px; border: 1px solid var(--borde); border-radius: 14px; background: #fff; transition: opacity .2s; }
.mg-caja.is-bloq { opacity: .75; }
.mg-paso { display: flex; align-items: center; gap: 8px; margin: 0 0 12px; color: var(--verde-osc); font-size: 14px; font-weight: 800; }
.mg-paso span { display: grid; place-items: center; width: 24px; height: 24px; border-radius: 50%; background: var(--verde); color: #fff; font-size: 12px; }
.mg-paso small { padding: 0 8px; border-radius: 999px; background: #eef2ea; color: var(--suave); font-size: 11.5px; }
.mg-paso-cab { display: flex; align-items: center; justify-content: space-between; }
.mg-campo { display: flex; flex-direction: column; gap: 4px; }
.mg-campo > label { margin: 0; color: var(--suave); font-size: 10.5px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; }
.mg-campo .form-control { border-radius: 8px; }
.mg-campo .form-control:focus { border-color: var(--verde); box-shadow: 0 0 0 .15rem rgba(115, 188, 37, .2); }
.mg-sesion { display: grid; grid-template-columns: 1fr 1.3fr 170px; gap: 12px; margin-bottom: 12px; }
.mg-tipos { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 6px; }
.mg-tipos button { display: flex; align-items: center; gap: 8px; padding: 7px 10px; border: 1.5px solid var(--borde); border-radius: 10px; background: #fff; text-align: left; transition: all .15s; }
.mg-tipos button i { display: grid; place-items: center; width: 28px; height: 28px; border-radius: 8px; background: #f3f5f1; color: var(--suave); font-size: 13px; }
.mg-tipos button b { display: block; color: var(--tinta); font-size: 12.5px; }
.mg-tipos button small { color: var(--suave); font-size: 10.5px; }
.mg-tipos button:hover { border-color: var(--verde-2); }
.mg-tipos button.is-on { border-color: var(--verde); background: color-mix(in srgb, var(--verde) 10%, #fff); }
.mg-tipos button.is-on i { background: var(--verde); color: #fff; }
.mg-preview { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 12px; padding: 10px 14px; border: 1.5px dashed color-mix(in srgb, var(--verde) 55%, #fff); border-radius: 12px; background: #f7fbf1; }
.mg-preview > i { color: var(--verde); font-size: 24px; }
.mg-preview small { display: block; color: var(--suave); font-size: 11px; font-weight: 600; }
.mg-preview code { color: var(--tinta); font-size: 17px; font-weight: 700; letter-spacing: .02em; }
.mg-preview mark { padding: 0 3px; border-radius: 4px; background: var(--amarillo); color: var(--tinta); }
.mg-preview-n { margin-left: auto; color: var(--suave); font-size: 12px; }
.mg-nada { margin: 0; padding: 14px; border: 1px dashed var(--gris); border-radius: 10px; color: var(--suave); font-size: 12.5px; text-align: center; }
.mg-rapido { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 8px; margin-bottom: 12px; }
.mg-rapido button { padding: 8px 10px; border: 1.5px solid var(--borde); border-radius: 10px; background: #fff; color: var(--verde-osc); font-size: 12.5px; font-weight: 700; text-align: left; transition: all .15s; }
.mg-rapido button i { margin-right: 4px; color: var(--verde); }
.mg-rapido button small { display: block; color: var(--suave); font-size: 11px; font-weight: 500; }
.mg-rapido button:hover:not(:disabled) { border-color: var(--verde); background: #f7fbf1; }
.mg-rapido button.is-on { border-color: var(--verde); background: color-mix(in srgb, var(--verde) 11%, #fff); }
.mg-rapido button:disabled { opacity: .45; }
.mg-croquis { margin-bottom: 12px; padding: 10px; border: 1px solid var(--borde); border-radius: 10px; background: #fafbf9; overflow-x: auto; }
.mg-croquis table { border-collapse: separate; border-spacing: 4px; }
.mg-croquis th { padding: 0 6px; color: var(--suave); font-size: 11px; font-weight: 700; text-align: center; }
.mg-croquis button { min-width: 64px; padding: 5px 8px; border: 1.5px solid var(--borde); border-radius: 8px; background: #fff; color: var(--tinta); font-size: 12px; font-weight: 700; }
.mg-croquis button:hover { border-color: var(--verde-2); }
.mg-croquis button.is-on { border-color: var(--verde); background: var(--verde); color: #fff; }
.mg-tabla-wrap { max-height: 52vh; overflow: auto; border: 1px solid var(--borde); border-radius: 10px; }
.mg-tabla { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.mg-tabla th { position: sticky; top: 0; z-index: 1; padding: 7px 8px; background: #f7f9f5; color: var(--suave); font-size: 11px; text-transform: uppercase; white-space: nowrap; }
.mg-tabla td { padding: 4px 8px; border-top: 1px solid #edf0eb; vertical-align: middle; }
.mg-tabla tr.is-dup td { background: #fffbea; }
.mg-tabla input::placeholder { color: #b9bfb5; font-style: italic; }
.mg-tabla select, .mg-tabla input { width: 100%; min-width: 70px; height: 30px; padding: 0 6px; border: 1px solid var(--borde); border-radius: 7px; background: #fff; font-size: 12.5px; }
.mg-tabla select:focus, .mg-tabla input:focus { border-color: var(--verde); outline: none; }
.mg-tabla select:disabled, .mg-tabla input:disabled { background: #f6f7f5; color: var(--suave); }
.mg-tabla .mg-rep { width: 60px; min-width: 0; }
.mg-num { color: var(--suave); text-align: center; }
.mg-id { color: var(--tinta); font-size: 12px; white-space: nowrap; }
.mg-id b { color: #3f7a12; }
.mg-dup { display: inline-block; margin-top: 2px; padding: 0 6px; border-radius: 999px; background: var(--amarillo); color: var(--tinta); font-size: 10px; font-weight: 700; }
.mg-icon { width: 28px; height: 28px; border: 0; border-radius: 7px; background: transparent; color: var(--suave); }
.mg-icon:hover { background: #fff1e6; color: var(--naranja); }
.mg-link { border: 0; background: transparent; color: #3f7a12; font-size: 12px; font-weight: 700; }
.mg-link:disabled { opacity: .5; }
.mg-pie { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 12px 16px; border: 1px solid var(--borde); border-left: 5px solid var(--verde); border-radius: 14px; background: #fff; font-size: 12.5px; color: var(--suave); }
.mg-pie code { color: var(--tinta); }
.mg-pie > span { flex: 1; min-width: 240px; }
.mg-pie.is-bloq { border-left-color: var(--gris); }
.mg-error { flex-basis: 100%; order: 3; margin: 0; color: #b84d00; font-size: 12.5px; }
.mg-btn { border: 0; border-radius: 9px; background: var(--verde); color: #fff; font-weight: 700; }
.mg-btn:hover { background: #64a51f; color: #fff; }
.mg-btn:disabled { opacity: .5; }
.mg-btn-sec { border: 1px solid var(--borde); border-radius: 9px; background: #fff; color: var(--verde-osc); font-weight: 600; }
.mg-btn-sec:hover { border-color: var(--verde); color: var(--verde-osc); }
.mg-btn-sec i { color: var(--verde); }

/* Lotes recientes */
.mg-recientes { position: sticky; top: 12px; max-height: calc(100vh - 24px); overflow-y: auto; padding: 12px; border: 1px solid var(--borde); border-radius: 14px; background: #fff; }
.mg-recientes h6 { margin: 0 0 10px; color: var(--verde-osc); font-size: 13.5px; font-weight: 800; }
.mg-recientes h6 i { color: var(--verde); }
.mg-lote { margin-bottom: 8px; padding: 9px 10px; border: 1px solid var(--borde); border-radius: 10px; }
.mg-lote-top { display: flex; align-items: center; gap: 8px; }
.mg-lote-top > div { flex: 1; min-width: 0; }
.mg-lote-top b { display: block; color: var(--tinta); font-size: 12.5px; }
.mg-lote-top small { color: var(--suave); font-size: 11px; }
.mg-lote-tipo { display: grid; place-items: center; width: 28px; height: 28px; border-radius: 8px; background: color-mix(in srgb, var(--verde) 14%, #fff); color: #3f7a12; font-size: 12px; }
.mg-lote-n { min-width: 28px; padding: 1px 8px; border-radius: 999px; background: #eef2ea; color: var(--verde-osc); font-weight: 800; text-align: center; }
.mg-lote-rango { display: block; margin: 6px 0; color: #44503f; font-size: 11px; }
.mg-lote-pie { display: flex; align-items: center; gap: 6px; }
.mg-lote-pie small { color: var(--suave); font-size: 10.5px; }
.mg-lote-pie .mg-link { margin-left: auto; }
.mg-barra { display: inline-block; width: 46px; height: 6px; overflow: hidden; border-radius: 999px; background: #e8ede4; }
.mg-barra i { display: block; height: 100%; background: var(--verde); }

/* Resultado y etiquetas */
.mg-ok { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 12px; padding: 12px 16px; border: 1px solid var(--borde); border-left: 5px solid var(--verde); border-radius: 14px; background: #fff; }
.mg-ok-ico { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 50%; background: var(--verde); color: #fff; font-size: 17px; }
.mg-ok-txt { flex: 1; min-width: 240px; }
.mg-ok-txt h5 { margin: 0; color: var(--tinta); font-weight: 800; }
.mg-ok-txt small { color: var(--suave); }
.mg-ok-acc { display: flex; flex-wrap: wrap; gap: 6px; }
.mg-et-bar { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 16px; margin-bottom: 10px; }
.mg-seg { display: inline-flex; padding: 3px; border: 1px solid var(--borde); border-radius: 9px; background: #f3f5f1; }
.mg-seg button { padding: 3px 11px; border: 0; border-radius: 6px; background: transparent; color: var(--suave); font-size: 12px; font-weight: 600; }
.mg-seg button small { color: inherit; opacity: .75; font-weight: 500; }
.mg-seg button.is-on { background: #fff; color: var(--verde-osc); box-shadow: 0 1px 3px rgba(30, 42, 26, .12); }
.mg-check { display: inline-flex; align-items: center; gap: 6px; margin: 0 0 6px; color: var(--verde-osc); font-size: 12.5px; font-weight: 600; }
.mg-check input { accent-color: var(--verde); }
.mg-imprimir { margin-left: auto; }
.mg-nota { margin: 0 0 12px; padding: 7px 12px; border-radius: 8px; background: #f6f8f3; color: #3d4838; font-size: 12px; }
.mg-nota i { margin-right: 4px; color: var(--verde); }
.mg-print { display: flex; flex-wrap: wrap; gap: 3mm; padding: 10px; border-radius: 10px; background: #eef1ec; }
.mg-etq { display: flex; align-items: center; gap: 2.5mm; overflow: hidden; padding: 2mm; border: 1px dashed #b8bfb4; border-radius: 2mm; background: #fff; }
.mg-etq img { flex: 0 0 auto; }
.mg-etq-txt { display: flex; flex-direction: column; min-width: 0; line-height: 1.2; }
.mg-etq-txt b { color: #000; font-family: 'Consolas', 'Courier New', monospace; word-break: normal; }
.mg-etq-txt b .mg-etq-tramo { display: inline-block; font-style: normal; overflow-wrap: anywhere; }
.mg-etq-txt span { color: #333 !important; }
.mg-etq-txt span.is-vacio::after { content: ""; display: inline-block; width: 18mm; margin-left: 1mm; border-bottom: 0.25mm solid #555; vertical-align: -0.3mm; }
.mg-etq.is-denso .mg-etq-txt span { font-size: 85% !important; }
.mg-etq.is-muy-denso .mg-etq-txt span { font-size: 72% !important; line-height: 1.1; }
.mg-check.is-off { opacity: .5; }
.mg-print.is-chica .mg-etq { width: 50mm; height: 25mm; }
.mg-print.is-chica .mg-etq img { width: 20mm; height: 20mm; }
.mg-print.is-chica .mg-etq-txt b { font-size: 7.5pt; }
.mg-print.is-chica .mg-etq-txt b .is-largo { font-size: 6.6pt; }
.mg-print.is-chica .mg-etq-txt span { font-size: 6pt; }
.mg-print.is-mediana .mg-etq { width: 70mm; height: 35mm; }
.mg-print.is-mediana .mg-etq img { width: 29mm; height: 29mm; }
.mg-print.is-mediana .mg-etq-txt b { font-size: 9pt; }
.mg-print.is-mediana .mg-etq-txt span { font-size: 7.5pt; }
.mg-print.is-grande .mg-etq { width: 100mm; height: 50mm; }
.mg-print.is-grande .mg-etq img { width: 42mm; height: 42mm; }
.mg-print.is-grande .mg-etq-txt b { font-size: 12pt; }
.mg-print.is-grande .mg-etq-txt span { font-size: 9.5pt; }

/* Campos personalizados */
.mg-muted { color: var(--suave); }
.mg-nowrap { white-space: nowrap; }
.mg-campos-tipo { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 10px; padding: 8px 12px; border-radius: 10px; background: #f6f8f3; font-size: 12px; }
.mg-ct-tit { color: var(--verde-osc); font-weight: 700; }
.mg-ct-tit i { color: var(--verde); }
.mg-ct-base { color: var(--suave); }
.mg-ct-chip { padding: 1px 9px; border-radius: 999px; background: color-mix(in srgb, var(--verde-2) 26%, #fff); color: #4f6600; font-weight: 700; }
.mg-ct-chip em { font-style: normal; font-weight: 500; opacity: .8; }
.mg-ct-chip b { color: var(--naranja); }
.mg-ct-cfg { margin-left: auto; }
.mg-th-campo { background: color-mix(in srgb, var(--verde-2) 18%, #f7f9f5) !important; color: #4f6600 !important; }
.mg-th-campo small { text-transform: none; }
.mg-req { color: var(--naranja); }
.mg-copiar { margin-left: 4px; padding: 0 4px; border: 0; border-radius: 4px; background: transparent; color: #4f6600; }
.mg-copiar:hover { background: #fff; }
.mg-tabla td.is-err select, .mg-tabla td.is-err input { border-color: var(--naranja); background: #fff6ef; }
.mg-velo { position: fixed; inset: 0; z-index: 1040; background: rgba(20, 30, 16, .35); }
.mg-panel { position: fixed; top: 0; right: 0; bottom: 0; z-index: 1050; display: flex; flex-direction: column; width: min(760px, 100vw); background: #fff; box-shadow: -10px 0 30px rgba(20, 30, 16, .18); }
.mg-ph { display: flex; align-items: center; gap: 12px; padding: 14px 18px; border-top: 5px solid var(--verde); border-bottom: 1px solid var(--borde); }
.mg-ph h5 { margin: 0; color: var(--tinta); font-weight: 800; }
.mg-ph small { color: var(--suave); }
.mg-ph-ico { display: grid; place-items: center; width: 42px; height: 42px; border-radius: 12px; background: color-mix(in srgb, var(--verde) 16%, #fff); color: var(--verde); font-size: 17px; }
.mg-x { margin-left: auto; border: 0; background: transparent; color: var(--suave); font-size: 26px; line-height: 1; }
.mg-pb { flex: 1; overflow-y: auto; padding: 14px 18px; }
.mg-pb label { color: var(--verde-osc); font-size: 12px; font-weight: 700; }
.mg-pb .form-control:focus { border-color: var(--verde); box-shadow: 0 0 0 .15rem rgba(115, 188, 37, .2); }
.mg-pf { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 18px; border-top: 1px solid var(--borde); background: #fafbf8; }
.mg-tipo-sel { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 12px; }
.mg-tipo-sel button { padding: 4px 12px; border: 1.5px solid var(--borde); border-radius: 999px; background: #fff; color: #44503f; font-size: 12px; font-weight: 700; }
.mg-tipo-sel button.is-on { border-color: var(--verde); background: color-mix(in srgb, var(--verde) 12%, #fff); color: #2f6b0f; }
.mg-cfg { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.mg-cfg th { padding: 7px 8px; background: #f7f9f5; color: var(--suave); font-size: 10.5px; text-transform: uppercase; white-space: nowrap; }
.mg-cfg td { padding: 6px 8px; border-top: 1px solid #edf0eb; vertical-align: middle; }
.mg-cfg tr.is-base td { color: var(--suave); background: #fbfcfa; }
.mg-cfg tr.is-base b { color: #44503f; }
.mg-cfg tr.is-off td { opacity: .55; }
.mg-cfg tr.is-edit td { background: #f4faec; }
.mg-cfg code { color: var(--tinta); font-size: 11.5px; }
.mg-cfg-vacio { padding: 14px !important; text-align: center; }
.mg-pre { padding: 0 7px; border-radius: 999px; background: #eef0ee; color: var(--suave); font-size: 10px; font-weight: 700; }
.mg-tdato { padding: 1px 8px; border-radius: 999px; background: #eef2ea; color: var(--verde-osc); font-size: 10.5px; font-weight: 700; }
.mg-estado { padding: 1px 10px; border: 0; border-radius: 999px; background: #eef0ee; color: var(--suave); font-size: 11px; font-weight: 700; }
.mg-estado.is-on { background: color-mix(in srgb, var(--verde) 18%, #fff); color: #3f7a12; }
.mg-orden { white-space: nowrap; }
.mg-oblig { min-width: 34px; padding: 1px 8px; border: 1px solid var(--borde); border-radius: 999px; background: #fff; color: var(--suave); font-size: 11px; font-weight: 700; }
.mg-oblig.is-on { border-color: var(--naranja); background: #fff1e6; color: #b84d00; }
.mg-oblig:disabled { opacity: .4; }
.mg-auto { color: var(--suave); font-size: 11.5px; white-space: nowrap; }
.mg-auto i { color: var(--verde-2); }
.mg-cfg tr.is-base.is-edit td { background: #f4faec; }
.mg-nota.is-cfg { margin: -4px 0 10px; }
.mg-orden button { padding: 0 3px; border: 0; background: transparent; color: var(--suave); font-size: 10px; }
.mg-orden button:disabled { opacity: .25; }
.mg-icon.is-edit:hover { background: #f1f8e9; color: var(--verde-osc); }
.mg-paso.is-form { margin: 18px 0 10px; font-size: 13.5px; }
.mg-paso.is-form i { color: var(--verde); }
.mg-seg.is-block { display: flex; }
.mg-seg.is-block button { flex: 1; }
.mg-checks { display: flex; gap: 16px; }
.mg-clave { margin: 6px 0 0; font-size: 12px; }
.mg-error.is-in { margin-top: 8px; }
.mg-slide-enter-active, .mg-slide-leave-active { transition: transform .25s ease; }
.mg-slide-enter, .mg-slide-leave-to { transform: translateX(100%); }

.mg-fade-enter-active, .mg-fade-leave-active { transition: opacity .2s; }
.mg-fade-enter, .mg-fade-leave-to { opacity: 0; }

@media (max-width: 1000px) {
  .mg-layout { grid-template-columns: 1fr; }
  .mg-recientes { position: static; max-height: none; }
  .mg-sesion { grid-template-columns: 1fr; }
}
</style>

<style>
/* Impresion: solo la hoja de etiquetas. */
@media print {
  body * { visibility: hidden !important; }
  .mg-print, .mg-print * { visibility: visible !important; }
  .mg-print { position: absolute; top: 0; left: 0; padding: 0 !important; background: #fff !important; }
  .mg-print .mg-etq { border: 0.2mm dashed #999 !important; break-inside: avoid; }
  @page { margin: 8mm; }
}
</style>
