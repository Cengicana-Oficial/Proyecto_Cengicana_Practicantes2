<?php
$identLotes = labIdentificacionLotes($conexion);
$identConfig = labIdentificacionConfig();
?>
<section id="ident-workflow" data-user="<?= ident_e((string) ($usuarioActual['id'] ?? $nombreUsuario)) ?>" data-initial-sample="<?= ident_e($codigoBusqueda) ?>" data-initial-lote="<?= (int) ($_GET['id_solicitud'] ?? 0) ?>">
  <div class="iw-kpis">
    <div><span>Muestras registradas</span><strong id="iw-total">0</strong></div>
    <div><span>Con información de etiqueta</span><strong id="iw-con">0</strong></div>
    <div><span>Solo ID del solicitante</span><strong id="iw-solo">0</strong></div>
    <div><span>Lotes con pendientes</span><strong id="iw-pendientes">0</strong></div>
  </div>
  <div class="iw-tabs" role="tablist" aria-label="Identificación de muestras">
    <button type="button" role="tab" aria-selected="true" aria-controls="iw-panel-registro" data-work-tab="registro">Registrar etiquetas</button>
    <button type="button" role="tab" aria-selected="false" aria-controls="iw-panel-base" data-work-tab="base">Base de datos de muestras</button>
    <button type="button" role="tab" aria-selected="false" aria-controls="iw-panel-etiquetas" data-work-tab="etiquetas">Etiquetas del laboratorio</button>
  </div>
  <p id="iw-status" role="status" aria-live="polite"></p>
  <div id="iw-panel-registro" role="tabpanel" data-work-panel="registro">
    <section class="ident-card iw-lotes-card" id="iw-lotes-view">
      <div class="iw-toolbar iw-lotes-tools">
        <label class="ident-field iw-grow"><span>Buscar lote</span><input type="search" id="iw-lote-buscar" placeholder="Número de lote, cliente o código de laboratorio"></label>
        <label class="ident-field"><span>Tipo de muestra</span><select id="iw-lote-tipo"><option value="">Todos</option></select></label>
        <label class="ident-field"><span>Mostrar</span><select id="iw-lote-estado"><option value="pendientes">Por identificar</option><option value="guardadas">Guardadas</option><option value="">Todos los lotes</option></select></label>
      </div>
      <p id="iw-lotes-resumen" class="iw-muted"></p>
      <div class="iw-grid-wrap iw-lotes-wrap">
        <table class="iw-lotes-table">
          <thead><tr><th>Lote</th><th>Tipo de muestra</th><th>Cliente</th><th>Análisis solicitados</th><th>Muestras</th><th>Avance de identificación</th><th>Estado</th><th>Acciones</th></tr></thead>
          <tbody id="iw-lotes"></tbody>
        </table>
      </div>
    </section>
    <section class="ident-card iw-editor" id="iw-editor-view" hidden>
        <div id="iw-empty" hidden></div>
        <div id="iw-editor-content" hidden>
          <button type="button" class="iw-back" id="iw-volver">← Volver a los lotes</button>
          <div class="iw-editor-head"><div><h2 id="iw-lote-titulo"></h2><p id="iw-lote-meta"></p></div><button type="button" id="iw-edit" class="ident-btn ident-btn--ghost">Editar identificación</button></div>
          <div class="iw-metodo-row">
            <div class="iw-metodo" role="radiogroup" aria-label="¿Cómo vienen las etiquetas de este lote?">
              <button type="button" role="radio" aria-checked="false" data-metodo="qr"><?= ident_svg('qr') ?><span><b>Código QR</b><small>Lector o cámara</small></span></button>
              <button type="button" role="radio" aria-checked="false" data-metodo="barras"><?= ident_svg('barcode') ?><span><b>Código de barras</b><small>Lector USB / Bluetooth</small></span></button>
              <button type="button" role="radio" aria-checked="false" data-metodo="tabular"><?= ident_svg('edit') ?><span><b>Ingreso manual</b><small>Tabular o pegar de Excel</small></span></button>
            </div>
            <label class="ident-field" id="iw-plantilla-wrap"><span>Formato de la etiqueta</span><select id="iw-plantilla"><?php foreach ($identConfig['plantillas'] as $nombre => $_): ?><option><?= ident_e($nombre) ?></option><?php endforeach; ?></select></label>
          </div>
          <div id="iw-scan-wrap" class="iw-scanbar" hidden>
            <div class="iw-scan-target" aria-live="polite">
              <span id="iw-scan-modo">Siguiente muestra</span>
              <b id="iw-scan-codigo">—</b>
              <small id="iw-scan-progreso"></small>
            </div>
            <div class="iw-scan-input">
              <label class="ident-field"><span id="iw-scan-destino">Lectura de etiqueta</span><textarea id="iw-lectura" rows="2" placeholder="Haga clic aquí y escanee. Cada lectura se asigna a la muestra resaltada y avanza sola." autocomplete="off"></textarea></label>
              <div class="iw-scan-actions">
                <button type="button" class="ident-btn ident-btn--primary" id="iw-asignar">Asignar lectura</button>
                <button type="button" class="ident-btn ident-btn--ghost" data-open-scanner data-batch-camera><?= ident_svg('camera') ?> Cámara</button>
                <button type="button" class="ident-btn ident-btn--ghost" id="iw-deshacer" disabled>Deshacer última</button>
                <label class="iw-check" title="Para bolsas que no vienen en orden: escanee la etiqueta del laboratorio y luego la del cliente"><input type="checkbox" id="iw-emparejar"> Emparejar cada etiqueta con el código de laboratorio</label>
              </div>
            </div>
            <details class="iw-scan-help">
              <summary>¿Cómo escanear más rápido?</summary>
              <ol>
                <li><b>Bolsas en orden</b> (mismo orden que los números de laboratorio): escanee una etiqueta tras otra; cada lectura se asigna a la muestra resaltada y salta a la siguiente pendiente.</li>
                <li><b>Bolsas en desorden</b>: active <i>Emparejar</i>. Escanee primero la etiqueta del laboratorio de la bolsa (su código, p. ej. S-104-06-26) y después la etiqueta del cliente. Así no hay que buscar la fila.</li>
                <li>Si se equivoca, use <b>Deshacer última</b> o toque la fila para corregirla. Nada se guarda hasta presionar <b>Guardar identificación</b>.</li>
              </ol>
            </details>
          </div>
          <p id="iw-ayuda" class="ident-help">Pegue hasta 100 filas o más desde Excel. Enter avanza en la misma columna; Shift+Enter retrocede. ↓ rellena las celdas vacías de las muestras filtradas desde la primera fila visible. Cambiar el formato no elimina los datos de otros campos.</p>
          <div class="iw-toolbar iw-sample-tools">
            <label class="ident-field iw-grow"><span>Buscar dentro del lote</span><input type="search" id="iw-muestra-buscar" placeholder="Código de muestra o datos de etiqueta"></label>
            <label class="ident-field"><span>Mostrar muestras</span><select id="iw-muestra-estado"><option value="">Todas</option><option value="pendiente">Sin identificar</option><option value="solo_id">Solo ID del solicitante</option><option value="ok">Con información</option></select></label>
            <button type="button" class="ident-btn ident-btn--ghost" id="iw-limpiar-filtros">Ver todas</button>
            <button type="button" class="ident-btn ident-btn--ghost" id="iw-siguiente-pendiente">Ir a pendiente</button>
          </div>
          <p id="iw-visibles" aria-live="polite"></p>
          <div class="iw-grid-wrap iw-capture-grid"><table class="iw-grid"><thead id="iw-grid-head"></thead><tbody id="iw-grid-body"></tbody></table></div>
          <p id="iw-resumen"></p>
          <p id="iw-save-msg" class="iw-save-msg" role="alert" hidden></p>
          <div id="iw-edit-controls">
            <label class="ident-field" id="iw-motivo-wrap" hidden><span>Motivo de la corrección *</span><textarea id="iw-motivo" maxlength="1000" placeholder="Describa qué se está corrigiendo y por qué"></textarea></label>
            <label class="iw-check" id="iw-parcial-wrap"><input type="checkbox" id="iw-parcial"> Guardar aunque queden muestras pendientes de identificar</label>
            <div class="iw-toolbar iw-save"><span id="iw-draft-status"></span><button type="button" id="iw-descartar" class="ident-btn ident-btn--ghost">Descartar borrador</button><button type="button" id="iw-guardar" class="ident-btn ident-btn--primary">Guardar identificación</button></div>
          </div>
          <details class="iw-history"><summary>Historial de identificación</summary><ol id="iw-historial"></ol></details>
        </div>
    </section>
  </div>
  <section id="iw-panel-base" role="tabpanel" data-work-panel="base" hidden>
    <div class="ident-card">
      <div class="iw-toolbar">
        <label class="ident-field iw-grow"><span>Buscar muestra</span><input type="search" id="iw-base-buscar" placeholder="Código, lote, cliente o texto de etiqueta"></label>
        <label class="ident-field"><span>Lote</span><select id="iw-base-lote"><option value="">Todos los lotes</option></select></label>
        <label class="ident-field"><span>Tipo</span><select id="iw-base-tipo"><option value="">Todos</option><option value="suelos">Suelos</option><option value="foliares">Foliares</option><option value="agua">Aguas</option><option value="cana">Cañas</option><option value="miel">Mieles</option></select></label>
        <label class="ident-field"><span>Identificación</span><select id="iw-base-estado"><option value="">Todas</option><option value="ok">Con información</option><option value="solo_id">Solo ID del solicitante</option><option value="pendiente">Sin identificar</option></select></label>
        <button type="button" id="iw-csv" class="ident-btn ident-btn--ghost">Descargar CSV</button>
      </div>
      <p id="iw-base-resumen"></p>
      <div class="iw-grid-wrap"><table class="iw-base-table"><thead><tr><th>Código de laboratorio</th><th>Lote · cliente</th><th>Método</th><th>Información de etiqueta</th><th>Identificación</th><th>Análisis solicitados</th><th>Acciones</th></tr></thead><tbody id="iw-base-body"></tbody></table></div>
    </div>
  </section>
  <section id="iw-panel-etiquetas" role="tabpanel" data-work-panel="etiquetas" hidden>
    <div class="ident-card">
      <div class="iw-toolbar">
        <label class="ident-field iw-grow"><span>Lote</span><select id="iw-etq-lote"></select></label>
        <label class="iw-check"><input type="checkbox" id="iw-etq-barras" checked> Incluir código de barras</label>
        <label class="iw-check"><input type="checkbox" id="iw-etq-qr"> Incluir QR</label>
        <button type="button" id="iw-imprimir" class="ident-btn ident-btn--primary">Imprimir etiquetas</button>
      </div>
      <p id="iw-etq-resumen"></p>
      <p class="ident-help">Dos etiquetas por fila. Seleccione las muestras que desea imprimir. Imprima a escala 100 %.</p>
      <div id="iw-etq-seleccion" class="iw-etq-seleccion"></div>
      <div id="iw-etq-preview" class="iw-label-sheet"></div>
    </div>
  </section>
</section>
<script type="application/json" id="iw-data"><?= json_encode($identLotes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script type="application/json" id="iw-config"><?= json_encode($identConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<script type="application/json" id="iw-csrf"><?= json_encode($_SESSION['muestra_identificacion_csrf'], JSON_HEX_TAG) ?></script>
