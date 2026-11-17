<?php
require_once("../config/conexion.php");

$conn = conexion::conectar();

/* =========================
   CARGAR ÁREAS
========================= */
$stmtAreas = $conn->prepare("SELECT * FROM areas_interes WHERE estado = 1");
$stmtAreas->execute();
$areas = $stmtAreas->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   CARGAR NIVELES
========================= */
$stmtNiveles = $conn->prepare("SELECT * FROM niveles_academicos WHERE estado = 1");
$stmtNiveles->execute();
$niveles = $stmtNiveles->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Programa tu Visita - CENGICANA</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/solicitud_visita.css?v=20260918-2">
    <link rel="stylesheet" href="../assets/cengisalas-theme.css?v=20260902-2">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&amp;family=Space+Grotesk:wght@500;600;700&amp;display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/module-theme.css?v=20261008-1">
</head>
<body class="visits-public">

<div class="container">
    <div class="form-content">
        <?php if(isset($_GET['ok'])): ?>
            <div id="mensaje-exito" class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span>¡Solicitud enviada correctamente! En breve revisaremos tu solicitud.</span>
            </div>
        <?php endif; ?>

        <p class="subtitle">Completa los campos para procesar tu solicitud técnica.</p>
        <p class="required-note"><span class="required">*</span> Campos obligatorios</p>

        <form id="solicitud-form" action="procesar_solicitud.php" method="POST" enctype="multipart/form-data">

            <!-- SECCIÓN: TIPO DE VISITA -->
            <div class="form-section visit-type-section">
                <div class="section-title">
                    <i class="fas fa-route"></i>
                    ¿Qué tipo de visita desea solicitar?
                </div>
                <p class="visit-type-help">Seleccione una opción para mostrar el formulario correspondiente.</p>
                <div class="visit-type-grid" role="radiogroup" aria-label="Tipo de visita">
                    <label class="visit-type-card" for="tipo_visita_museo">
                        <input type="radio" id="tipo_visita_museo" name="tipo_visita" value="museo_azucar" required>
                        <span class="visit-type-icon"><i class="fas fa-landmark"></i></span>
                        <span class="visit-type-copy">
                            <strong>Visita a Museo del Azúcar</strong>
                            <small>Recorrido por el museo, historia y patrimonio de la agroindustria azucarera.</small>
                        </span>
                        <i class="fas fa-circle-check visit-type-check" aria-hidden="true"></i>
                    </label>

                    <label class="visit-type-card" for="tipo_visita_cengicana">
                        <input type="radio" id="tipo_visita_cengicana" name="tipo_visita" value="cengicana" required>
                        <span class="visit-type-icon"><i class="fas fa-building"></i></span>
                        <span class="visit-type-copy">
                            <strong>Visita a Cengicaña</strong>
                            <small>Solicitud completa para conocer áreas, instalaciones y laboratorios.</small>
                        </span>
                        <i class="fas fa-circle-check visit-type-check" aria-hidden="true"></i>
                    </label>
                </div>
            </div>

            <div id="visit-form-fields" hidden>

            <!-- SECCIÓN: ÁREAS DE INTERÉS -->
            <div class="form-section" id="areas-section">
                <div class="section-title">
                    <i class="fas fa-leaf"></i>
                    Seleccione las áreas de interés
                </div>
                <div class="areas-grid">
                    <?php foreach($areas as $area): ?>
                        <div class="checkbox-wrapper">
                            <input type="checkbox" id="area_<?= $area['id_area'] ?>" name="areas[]" value="<?= $area['id_area'] ?>">
                            <label for="area_<?= $area['id_area'] ?>" class="checkbox-label">
                                <?= htmlspecialchars($area['nombre_area']) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- SECCIÓN: INFORMACIÓN SOLICITANTE -->
            <div class="form-section">
                <div class="section-title">
                    <i class="fas fa-user"></i>
                    Información del Solicitante
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="nombre_solicitante">
                            Nombre del solicitante<span class="required">*</span>
                        </label>
                        <input type="text" id="nombre_solicitante" name="nombre_solicitante"
                            placeholder="Nombre completo" required>
                    </div>
                    <div class="form-group">
                        <label for="institucion">
                            Nombre de la institución<span class="required">*</span>
                        </label>
                        <input type="text" id="institucion" name="institucion"
                            placeholder="Ej: Universidad Nacional" required>
                    </div>
                </div>
            </div>

            <!-- SECCIÓN: CONTACTO -->
            <div class="form-section">
                <div class="section-title">
                    <i class="fas fa-envelope"></i>
                    Contacto
                </div>
                <div class="form-grid full">
                    <div class="form-group">
                        <label for="correo">
                            Correo electrónico<span class="required">*</span>
                        </label>
                        <input type="email" id="correo" name="correo"
                            placeholder="tu@email.com" required>
                    </div>
                    <div class="form-group">
                        <label for="telefono">
                            Teléfono<span class="required">*</span>
                        </label>
                        <input type="text" id="telefono" name="telefono"
                            placeholder="Ej: 5555-5555" required>
                    </div>
                </div>
            </div>

            <!-- SECCIÓN: DETALLES DE LA VISITA -->
            <div class="form-section">
                <div class="section-title">
                    <i class="fas fa-calendar-alt"></i>
                    Detalles de la Visita
                </div>
                <small style="display:block;font-weight:normal;color:#757575;margin: 0 0 10px 2px;">
                                (El museo abre sus puertas al público de Lunes a Jueves de 8:00 a 17:00, Viernes de 8:00 a 12:00)
                </small>
                <div class="form-grid visit-schedule-grid">
                    <div class="form-group">
                        <label for="fecha_visita">Fecha planificada<span class="required">*</span></label>
                        <input type="date" id="fecha_visita" name="fecha_visita" required>
                    </div>
                    <div class="form-group">
                        <label for="hora_visita">Hora de visita<span class="required">*</span></label>
                        <div class="time-inputs">
                            <input type="time" id="hora_visita" name="hora_visita" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="cantidad_visitantes">Cantidad de personas<span class="required">*</span></label>
                        <input type="number" id="cantidad_visitantes" name="cantidad_visitantes"
                            min="1" placeholder="Ej: 30" required>
                    </div>
                </div>
            </div>

            <!-- LISTADO DE VISITANTES PARA VISITAS A CENGICAÑA -->
            <div class="form-section" id="cengicana-visitors-section" hidden>
                <div class="section-title">
                    <i class="fas fa-users"></i>
                    Listado de visitantes
                </div>
                <p style="margin:0 0 14px;color:#757575;">
                    Registra los nombres de las personas que asistirán. La cantidad de filas se ajusta automáticamente; para más de 15 participantes se habilita la carga masiva mediante CSV.
                </p>
                <div id="cengicana-visitors-list" class="guest-names-list"></div>
                <button type="button" class="secondary-button guest-add-button" id="add-guest-button">
                    <i class="fas fa-user-plus"></i> Agregar visitante
                </button>
                <div id="cengicana-visitors-csv" class="guest-csv-upload" hidden>
                    <p><strong>Más de 15 participantes</strong></p>
                    <p>Descarga la plantilla, agrega un nombre completo por fila y vuelve a cargarla. El archivo debe ser CSV y puede editarse con Excel.</p>
                    <div class="guest-csv-actions">
                        <a class="secondary-button" href="../assets/plantilla_visitantes.csv" download>
                            <i class="fas fa-file-download"></i> Descargar plantilla
                        </a>
                        <label class="secondary-button guest-csv-label" for="visitantes_csv">
                            <i class="fas fa-file-upload"></i> Cargar CSV de Excel
                            <input type="file" id="visitantes_csv" name="visitantes_csv" accept=".csv,text/csv" hidden>
                        </label>
                    </div>
                    <small id="visitantes-csv-name" class="guest-csv-name">Ningún archivo seleccionado.</small>
                </div>
            </div>

            <!-- SECCIÓN: NIVEL ACADÉMICO -->
            <div class="form-section">
                <div class="section-title">
                    <i class="fas fa-graduation-cap"></i>
                    Nivel Académico
                </div>
                <div class="form-grid full">
                    <div class="form-group">
                        <label for="id_nivel">
                            Nivel académico<span class="required">*</span>
                            <small style="display:block;font-weight:normal;color:#757575;margin-top:3px;">
                                (si pertenece a alguna institución educativa favor seleccionar alguna opción, caso contrario seleccionar NA)
                            </small>
                        </label>
                        <select id="id_nivel" name="id_nivel" required>
                            <option value="">Seleccione nivel</option>
                            <?php foreach($niveles as $nivel): ?>
                                <option value="<?= $nivel['id_nivel'] ?>">
                                    <?= htmlspecialchars($nivel['nombre_nivel']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- SECCIÓN: VISITA AL MUSEO ── NUEVO ── -->
            <div class="form-section" id="museo-section">
                <div class="section-title" id="museo-section-title">
                    <i class="fas fa-landmark"></i>
                    Visita al Museo
                </div>

                <div class="museo-toggle-wrapper" id="museo-toggle-wrapper">
                    <label class="switch">
                        <input type="checkbox" id="toggle-museo" name="visita_museo" value="1">
                        <span class="slider"></span>
                    </label>
                    <span style="font-size:14px;color:#334155;">
                        ¿Desea incluir una visita al museo?
                    </span>
                </div>

                <!-- Panel que aparece al activar el toggle -->
                <div id="panel-museo" style="display: none;">
                    <div class="museo-header">
                        <i class="fas fa-landmark"></i>
                        Detalle de Visitantes al Museo
                    </div>

                    <div class="museo-body">

                        <!-- Visitantes extranjeros -->
                        <div class="museo-subsection">
                            <h4>Visitantes Extranjeros</h4>
                            <p class="sub-desc">Detalla la cantidad de personas que visitarán el Museo.</p>
                            <div class="visitantes-grid">
                                <div class="visitante-card">
                                    <div class="tipo">Extranjeros</div>
                                    <div class="precio">Precio: Q 80.00</div>
                                    <input type="number" id="cant_extranjeros" name="cant_extranjeros"
                                        min="0" value="0" placeholder="No. de Visitantes"
                                        data-precio="80">
                                </div>
                            </div>
                        </div>

                        <!-- Visitantes nacionales -->
                        <div class="museo-subsection">
                            <h4>Visitantes Nacionales</h4>
                            <p class="sub-desc">Detalla la cantidad de personas que visitarán el Museo.</p>
                            <div class="visitantes-grid">
                                <div class="visitante-card">
                                    <div class="tipo">Adultos</div>
                                    <div class="precio">Precio: Q 25.00</div>
                                    <input type="number" id="cant_adultos" name="cant_adultos"
                                        min="0" value="0" placeholder="No. Visitantes"
                                        data-precio="25">
                                </div>
                                <div class="visitante-card">
                                    <div class="tipo">Adultos Mayores</div>
                                    <div class="precio">Precio: Q 15.00</div>
                                    <input type="number" id="cant_adultos_mayores" name="cant_adultos_mayores"
                                        min="0" value="0" placeholder="No. Visitantes"
                                        data-precio="15">
                                </div>
                                <div class="visitante-card">
                                    <div class="tipo">Estudiantes</div>
                                    <div class="precio">Precio: Q 15.00</div>
                                    <input type="number" id="cant_estudiantes" name="cant_estudiantes"
                                        min="0" value="0" placeholder="No. Visitantes"
                                        data-precio="15">
                                </div>
                                <div class="visitante-card">
                                    <div class="tipo">Niños</div>
                                    <div class="precio">Precio: Q 15.00</div>
                                    <input type="number" id="cant_ninos" name="cant_ninos"
                                        min="0" value="0" placeholder="No. Visitantes"
                                        data-precio="15">
                                </div>
                            </div>

                            <!-- Total calculado -->
                            <div class="total-box">
                                <span class="label">Total a cancelar</span>
                                <span class="monto" id="total-display">Q 0.00</span>
                                <input type="hidden" id="total_museo" name="total_museo" value="0">
                            </div>
                        </div>

                        <!-- Información de pago -->
                        <div class="museo-subsection">
                            <h4>Información para procesar el pago</h4>

                            <div class="pago-grid">
                                <!-- Forma de pago -->
                                <div>
                                    <p class="radio-group-label">
                                        Forma de pago
                                        <span class="obligatorio-badge">Obligatorio</span>
                                    </p>
                                    <div class="radio-group" id="grupo-forma-pago">
                                        <label>
                                            <input type="radio" name="forma_pago" value="transferencia">
                                            Transferencia bancaria
                                        </label>
                                        <label>
                                            <input type="radio" name="forma_pago" value="debito">
                                            Tarjeta de débito
                                        </label>
                                        <label>
                                            <input type="radio" name="forma_pago" value="credito">
                                            Tarjeta de crédito
                                        </label>
                                    </div>
                                </div>

                                <!-- Moneda -->
                                <div>
                                    <p class="radio-group-label">
                                        Seleccione la moneda de pago
                                        <span class="obligatorio-badge">Obligatorio</span>
                                    </p>
                                    <div class="radio-group" id="grupo-moneda">
                                        <label>
                                            <input type="radio" name="moneda" value="GTQ">
                                            Quetzales (GTQ)
                                        </label>
                                        <label>
                                            <input type="radio" name="moneda" value="USD">
                                            Dólares (USD)
                                        </label>
                                    </div>
                                    <p class="nota-pago">
                                        <i class="fas fa-info-circle"></i>
                                        En breve se le enviará un correo electrónico con el enlace correspondiente para efectuar el pago.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Datos de facturación -->
                        <div class="museo-subsection">
                            <h4>Datos de Facturación</h4>
                            <div class="factura-grid">
                                <div class="museo-field">
                                    <label for="nombre_factura">
                                        Nombre <span class="obligatorio-badge">Obligatorio</span>
                                    </label>
                                    <input type="text" id="nombre_factura" name="nombre_factura"
                                        placeholder="Nombre completo para factura">
                                </div>
                                <div class="factura-row">
                                    <div class="museo-field">
                                        <label for="nit">
                                            NIT <span class="obligatorio-badge">Obligatorio</span>
                                        </label>
                                        <input type="text" id="nit" name="nit"
                                            placeholder="Ej: 1234567-8">
                                    </div>
                                    <div class="museo-field">
                                        <label for="direccion">
                                            Dirección <span class="obligatorio-badge">Obligatorio</span>
                                        </label>
                                        <input type="text" id="direccion" name="direccion"
                                            placeholder="Dirección de facturación">
                                    </div>
                                </div>
                            </div>
                        </div>


                    </div><!-- /museo-body -->
                </div><!-- /panel-museo -->
            </div>

            <div class="museo-slider">
                        <div class="museo-track">
                            <img src="../assets/img/museo.jpg" alt="Museo">
                            <img src="../assets/img/museo1.jpg" alt="Museo">
                            <img src="../assets/img/museo2.jpg" alt="Museo">
                            <img src="../assets/img/museo3.jpg" alt="Museo">
                            <img src="../assets/img/museo4.jpg" alt="Museo">

                            <!-- repetidas para efecto infinito -->
                            <img src="../assets/img/museo1.jpg" alt="Museo">
                            <img src="../assets/img/museo.jpg" alt="Museo">
                            <img src="../assets/img/museo2.jpg" alt="Museo">
                            <img src="../assets/img/museo3.jpg" alt="Museo">
                            <img src="../assets/img/museo.jpg" alt="Museo">
                        </div>
                    </div>

            <!-- SECCIÓN: DOCUMENTO -->
            <div class="form-section" id="documentation-section">
                <div class="section-title">
                    <i class="fas fa-file-pdf"></i>
                    Documentación
                </div>
                <div class="form-grid full">
                    <div class="form-group">
                        <label for="carta_pdf">
                            Carta de solicitud<span class="required">*</span>
                            <small style="display:block;font-weight:normal;color:#757575;margin-top:3px;">
                                Tipos aceptados: PDF, JPG, PNG (Tamaño máximo: 10 MB)
                            </small>
                            <small style="display:block;font-weight:normal;color:#757575;margin-top:3px;">
                                (Favor enviar una carta firmada explicando el motivo de la solicitud de la visita.)
                            </small>
                        </label>
                        <div class="file-upload-wrapper">
                            <label for="carta_pdf" class="file-input-label">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <div class="file-input-text">
                                    <p>Subir archivo</p>
                                    <small>Arrastra tu archivo aquí o haz clic para seleccionar</small>
                                </div>
                            </label>
                            <input type="file" id="carta_pdf" name="carta_pdf"
                                accept=".pdf,.jpg,.jpeg,.png" required>
                            <div class="file-name" id="file-name"></div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- BOTONES -->
            <div class="button-group">
                <button type="submit" id="btn-enviar" class="btn-submit">
                    <i class="fas fa-paper-plane" style="margin-right:8px;"></i>Enviar Solicitud
                </button>
            </div>
            </div><!-- /visit-form-fields -->
        </form>
    </div>
</div>

<script>
/* ═══════════════════════════════════════════════
   MODALIDAD Y PANEL DEL MUSEO
═══════════════════════════════════════════════ */
const toggleMuseo  = document.getElementById('toggle-museo');
const panelMuseo   = document.getElementById('panel-museo');
const formulario = document.getElementById('solicitud-form');
const visitFormFields = document.getElementById('visit-form-fields');
const areasSection = document.getElementById('areas-section');
const documentationSection = document.getElementById('documentation-section');
const museoToggleWrapper = document.getElementById('museo-toggle-wrapper');
const museoSectionTitle = document.getElementById('museo-section-title');
const fileInput = document.getElementById('carta_pdf');
const cantidadVisitantesInput = document.getElementById('cantidad_visitantes');
const cengicanaVisitorsSection = document.getElementById('cengicana-visitors-section');
const cengicanaVisitorsList = document.getElementById('cengicana-visitors-list');
const addGuestButton = document.getElementById('add-guest-button');
const cengicanaVisitorsCsv = document.getElementById('cengicana-visitors-csv');
const visitantesCsvInput = document.getElementById('visitantes_csv');
const visitantesCsvName = document.getElementById('visitantes-csv-name');
const VISITOR_CSV_LIMIT = 15;
let tipoVisitaActual = '';
let visitorNamesCache = [];
let csvModeActive = false;

function rememberVisitorNames() {
    if (!cengicanaVisitorsList) return;
    cengicanaVisitorsList.querySelectorAll('input[name="visitantes[]"]').forEach((input, index) => {
        visitorNamesCache[index] = input.value;
    });
}

function renderCengicanaVisitors(count) {
    if (!cengicanaVisitorsList) return;
    rememberVisitorNames();
    const total = Math.max(1, Number.parseInt(count, 10) || 1);
    const usaCsv = csvModeActive || total > VISITOR_CSV_LIMIT;
    cengicanaVisitorsList.hidden = usaCsv;
    addGuestButton.hidden = usaCsv;
    addGuestButton.disabled = usaCsv;
    cengicanaVisitorsCsv.hidden = !usaCsv;
    visitantesCsvInput.disabled = !usaCsv;
    visitantesCsvInput.required = usaCsv;
    if (usaCsv) {
        cengicanaVisitorsList.innerHTML = '';
        return;
    }
    cengicanaVisitorsList.innerHTML = '';
    for (let index = 0; index < total; index += 1) {
        const row = document.createElement('label');
        row.className = 'guest-name-row';
        row.innerHTML = `<span class="guest-name-number">${index + 1}</span><input type="text" name="visitantes[]" maxlength="180" placeholder="Nombre completo del visitante" required>`;
        const input = row.querySelector('input');
        input.value = visitorNamesCache[index] || '';
        input.addEventListener('input', () => { visitorNamesCache[index] = input.value; quitarError('error-visitantes-cengicana'); });
        cengicanaVisitorsList.appendChild(row);
    }
}

function toggleCengicanaVisitors() {
    if (!cengicanaVisitorsSection) return;
    const enabled = tipoVisitaActual !== '' && tipoVisitaActual !== 'museo_azucar';
    cengicanaVisitorsSection.hidden = !enabled;
    alternarControles(cengicanaVisitorsSection, !enabled);
    if (enabled) renderCengicanaVisitors(cantidadVisitantesInput?.value || 1);
}

function alternarControles(contenedor, deshabilitados) {
    contenedor.querySelectorAll('input, select, textarea, button').forEach(control => {
        control.disabled = deshabilitados;
    });
}

function aplicarTipoVisita(tipo) {
    const tipoAnterior = tipoVisitaActual;
    const esMuseoDirecto = tipo === 'museo_azucar';
    tipoVisitaActual = tipo;

    document.querySelectorAll('.visit-type-card').forEach(tarjeta => {
        const opcion = tarjeta.querySelector('input[name="tipo_visita"]');
        tarjeta.classList.toggle('is-selected', opcion && opcion.value === tipo);
    });

    visitFormFields.hidden = false;
    areasSection.hidden = esMuseoDirecto;
    documentationSection.hidden = esMuseoDirecto;
    alternarControles(areasSection, esMuseoDirecto);
    alternarControles(documentationSection, esMuseoDirecto);
    fileInput.required = !esMuseoDirecto;

    if (esMuseoDirecto) {
        museoToggleWrapper.hidden = true;
        toggleMuseo.checked = true;
        museoSectionTitle.innerHTML = '<i class="fas fa-landmark"></i> Información de la visita al Museo del Azúcar';
    } else {
        museoToggleWrapper.hidden = false;
        museoSectionTitle.innerHTML = '<i class="fas fa-landmark"></i> Visita al Museo';
        if (tipoAnterior === 'museo_azucar') {
            toggleMuseo.checked = false;
            limpiarCamposMuseo();
        }
    }

    ajustarPanelMuseo();
    toggleCengicanaVisitors();
    visitFormFields.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function ajustarPanelMuseo() {
    const esMuseoDirecto = tipoVisitaActual === 'museo_azucar';
    if (toggleMuseo.checked || esMuseoDirecto) {
        panelMuseo.style.display = 'block';
    } else {
        panelMuseo.style.display = 'none';
        limpiarCamposMuseo();
    }
}

ajustarPanelMuseo();
toggleCengicanaVisitors();

cantidadVisitantesInput?.addEventListener('input', function() {
    if (tipoVisitaActual !== 'museo_azucar') renderCengicanaVisitors(this.value || 1);
});

visitantesCsvInput?.addEventListener('change', function() {
    csvModeActive = Boolean(this.files?.length);
    if (visitantesCsvName) {
        visitantesCsvName.textContent = this.files?.length ? this.files[0].name : 'Ningún archivo seleccionado.';
    }
    if (this.files?.length && cantidadVisitantesInput) {
        const archivo = this.files[0];
        archivo.text().then(texto => {
            const delimitador = (texto.split(/\r?\n/, 1)[0].split(';').length > texto.split(/\r?\n/, 1)[0].split(',').length) ? ';' : ',';
            const encabezados = ['nombre', 'nombrecompleto', 'nombrecompletodelvisitante'];
            const filas = texto.split(/\r?\n/).map(linea => linea.trim()).filter(Boolean);
            const nombres = filas.map((linea, index) => {
                const celdas = linea.split(delimitador).map(celda => celda.trim().replace(/^"|"$/g, ''));
                return celdas.filter(Boolean).join(' ').replace(/^\uFEFF/, '').trim();
            }).filter((nombre, index) => nombre && !(index === 0 && encabezados.includes(nombre.toLowerCase().replace(/[\s_]+/g, ''))));
            cantidadVisitantesInput.value = String(nombres.length || 1);
            renderCengicanaVisitors(cantidadVisitantesInput.value);
        }).catch(() => {});
    } else if (cantidadVisitantesInput) {
        renderCengicanaVisitors(cantidadVisitantesInput.value || 1);
    }
    quitarError('error-visitantes-cengicana');
});

addGuestButton?.addEventListener('click', function() {
    const current = Math.max(1, Number.parseInt(cantidadVisitantesInput?.value, 10) || 1);
    if (cantidadVisitantesInput) {
        cantidadVisitantesInput.value = String(current + 1);
        renderCengicanaVisitors(cantidadVisitantesInput.value);
        cantidadVisitantesInput.dispatchEvent(new Event('input', { bubbles: true }));
    }
});

document.querySelectorAll('input[name="tipo_visita"]').forEach(opcion => {
    opcion.addEventListener('change', function() {
        aplicarTipoVisita(this.value);
        quitarError('error-tipo_visita');
    });
});

toggleMuseo.addEventListener('change', function() {
    ajustarPanelMuseo();
});

function limpiarCamposMuseo() {
    document.querySelectorAll('#panel-museo input[type="number"]').forEach(i => i.value = 0);
    document.querySelectorAll('#panel-museo input[type="radio"]').forEach(r => r.checked = false);
    document.querySelectorAll('#panel-museo input[type="text"]').forEach(t => t.value = '');
    document.getElementById('total-display').textContent = 'Q 0.00';
    document.getElementById('total_museo').value = 0;
}

/* ═══════════════════════════════════════════════
   CÁLCULO DE TOTAL
═══════════════════════════════════════════════ */
const inputsVisitantes = document.querySelectorAll('#panel-museo input[data-precio]');

inputsVisitantes.forEach(function(input) {
    input.addEventListener('input', calcularTotal);
});

function calcularTotal() {
    let total = 0;
    inputsVisitantes.forEach(function(input) {
        const cantidad = parseInt(input.value) || 0;
        const precio   = parseFloat(input.dataset.precio) || 0;
        total += cantidad * precio;
    });
    document.getElementById('total-display').textContent = 'Q ' + total.toFixed(2);
    document.getElementById('total_museo').value = total.toFixed(2);
}

/* ═══════════════════════════════════════════════
   NOMBRE DEL ARCHIVO
═══════════════════════════════════════════════ */
document.querySelectorAll('.file-upload-wrapper input[type=file]').forEach(input => {
    input.addEventListener('change', function(e) {
        const fileNameDiv = this.parentElement.querySelector('.file-name');
        if (e.target.files.length > 0) {
            // Mostrar el chequesito y el nombre del archivo
            fileNameDiv.textContent = '✓ ' + e.target.files[0].name;

            // Si manejas validaciones, puedes limpiar el error asociado al input
            // usando su id dinámicamente:
            quitarError('error-' + this.id);
        }
    });
});

/* ═══════════════════════════════════════════════
   HELPERS DE ERRORES
═══════════════════════════════════════════════ */
function mostrarError(id, referencia, mensaje) {
    quitarError(id);
    const error = document.createElement('p');
    error.id = id;
    error.style.cssText = 'color:#d32f2f;font-size:13px;margin-top:8px;display:flex;align-items:center;gap:6px;background:#fdecea;padding:8px 12px;border-radius:6px;border-left:3px solid #d32f2f;';
    error.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + mensaje;
    referencia.insertAdjacentElement('afterend', error);
}

function quitarError(id) {
    const el = document.getElementById(id);
    if (el) el.remove();
}

document.querySelectorAll('input[type="text"], input[type="email"], input[type="number"], input[type="date"], input[type="time"], select').forEach(function(el) {
    el.addEventListener('input', function() { quitarError('error-' + (this.id || this.name)); });
    el.addEventListener('change', function() { quitarError('error-' + (this.id || this.name)); });
});

/* ═══════════════════════════════════════════════
   VALIDACIÓN PRINCIPAL
═══════════════════════════════════════════════ */
formulario.addEventListener('submit', function(event) {
    let hayError   = false;
    let primeroCon = null;

    function marcar(id, referencia, mensaje) {
        mostrarError(id, referencia, mensaje);
        if (!primeroCon) primeroCon = document.getElementById(id);
        hayError = true;
    }

    // 1. Tipo de visita
    const tipoSeleccionado = document.querySelector('input[name="tipo_visita"]:checked');
    if (!tipoSeleccionado) {
        marcar('error-tipo_visita', document.querySelector('.visit-type-grid'), 'Seleccione el tipo de visita que desea solicitar.');
    } else { quitarError('error-tipo_visita'); }

    const esMuseoDirecto = tipoVisitaActual === 'museo_azucar';

    // 2. Áreas de interés (solo para visita a Cengicaña)
    const seleccionadas = document.querySelectorAll('input[name="areas[]"]:checked');
    if (!esMuseoDirecto && seleccionadas.length === 0) {
        marcar('error-areas', document.querySelector('.areas-grid'), 'Debe seleccionar al menos un área de interés.');
    } else { quitarError('error-areas'); }

    // 3. Nombre del solicitante
    const nombre = document.getElementById('nombre_solicitante');
    if (!nombre.value.trim()) {
        marcar('error-nombre_solicitante', nombre, 'El nombre del solicitante es obligatorio.');
    } else { quitarError('error-nombre_solicitante'); }

    // 4. Institución
    const institucion = document.getElementById('institucion');
    if (!institucion.value.trim()) {
        marcar('error-institucion', institucion, 'El nombre de la institución es obligatorio.');
    } else { quitarError('error-institucion'); }

    // 5. Correo
    const correo    = document.getElementById('correo');
    const regexEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!correo.value.trim()) {
        marcar('error-correo', correo, 'El correo electrónico es obligatorio.');
    } else if (!regexEmail.test(correo.value.trim())) {
        marcar('error-correo', correo, 'Ingrese un correo electrónico válido.');
    } else { quitarError('error-correo'); }

    // 6. Teléfono
    const telefono = document.getElementById('telefono');
    const regexTelefono = /^[0-9+\-\s]{8,20}$/;

    if (!telefono.value.trim()) {
        marcar('error-telefono', telefono, 'El número de teléfono es obligatorio.');
    } else if (!regexTelefono.test(telefono.value.trim())) {
        marcar('error-telefono', telefono, 'Ingrese un número de teléfono válido.');
    } else {
        quitarError('error-telefono');
    }

    // 7. Fecha de visita
    const fecha = document.getElementById('fecha_visita');
    if (!fecha.value) {
        marcar('error-fecha_visita', fecha, 'La fecha de visita es obligatoria.');
    } else {
        const hoy = new Date(); hoy.setHours(0,0,0,0);
        const seleccionada = new Date(fecha.value + 'T00:00:00');
        if (seleccionada < hoy) {
            marcar('error-fecha_visita', fecha, 'La fecha de visita no puede ser anterior a hoy.');
        } else { quitarError('error-fecha_visita'); }
    }

    // 8. Hora de visita
    const hora = document.querySelector('input[name="hora_visita"]');
    if (!hora.value) {
        marcar('error-hora_visita', hora, 'La hora de visita es obligatoria.');
    } else { quitarError('error-hora_visita'); }

    // 9. Cantidad de visitantes
    const cantidad = document.getElementById('cantidad_visitantes');
    if (!cantidad.value || parseInt(cantidad.value) < 1) {
        marcar('error-cantidad_visitantes', cantidad, 'Ingrese la cantidad de personas (mínimo 1).');
    } else { quitarError('error-cantidad_visitantes'); }

    // Listado de nombres para visitas a Cengicaña. Hasta 15 se capturan en filas;
    // para cantidades mayores se exige el archivo CSV masivo.
    if (tipoSeleccionado && !esMuseoDirecto) {
        const totalSolicitado = parseInt(cantidad.value, 10) || 0;
        if (totalSolicitado > VISITOR_CSV_LIMIT) {
            if (!visitantesCsvInput?.files?.length) {
                marcar('error-visitantes-cengicana', cengicanaVisitorsSection, 'Cargue el CSV con los nombres de los visitantes.');
            } else if (visitantesCsvInput.files[0].size > 10 * 1024 * 1024) {
                marcar('error-visitantes-cengicana', cengicanaVisitorsSection, 'El CSV supera el tamaño máximo de 10 MB.');
            } else {
                quitarError('error-visitantes-cengicana');
            }
        } else {
            const nombresVisitantes = [...document.querySelectorAll('#cengicana-visitors-list input[name="visitantes[]"]')]
                .map(input => input.value.trim());
            const faltantes = nombresVisitantes.filter(nombre => !nombre).length;
            if (nombresVisitantes.length !== totalSolicitado || faltantes > 0) {
                marcar('error-visitantes-cengicana', cengicanaVisitorsSection, 'Complete el nombre de cada visitante solicitado.');
            } else { quitarError('error-visitantes-cengicana'); }
        }
    }

    // 11. Nivel académico
    const nivel = document.getElementById('id_nivel');
    if (!nivel.value) {
        marcar('error-id_nivel', nivel, 'Seleccione un nivel académico.');
    } else { quitarError('error-id_nivel'); }

    // 12. Validaciones del museo (directo o como parte de una visita a Cengicaña)
    if (toggleMuseo.checked) {

        // 10a. Al menos un visitante con cantidad > 0
        let totalVisitantes = 0;
        inputsVisitantes.forEach(i => totalVisitantes += parseInt(i.value) || 0);
        if (totalVisitantes === 0) {
            marcar('error-visitantes-museo',
                   document.querySelector('.visitantes-grid'),
                   'Debe ingresar al menos un visitante para la visita al museo.');
        } else { quitarError('error-visitantes-museo'); }

        // 10b. Forma de pago
        const formaPago = document.querySelector('input[name="forma_pago"]:checked');
        if (!formaPago) {
            marcar('error-forma_pago',
                   document.getElementById('grupo-forma-pago'),
                   'Seleccione una forma de pago.');
        } else { quitarError('error-forma_pago'); }

        // 10c. Moneda
        const moneda = document.querySelector('input[name="moneda"]:checked');
        if (!moneda) {
            marcar('error-moneda',
                   document.getElementById('grupo-moneda'),
                   'Seleccione la moneda de pago.');
        } else { quitarError('error-moneda'); }

        // 10d. Nombre factura
        const nombreFac = document.getElementById('nombre_factura');
        if (!nombreFac.value.trim()) {
            marcar('error-nombre_factura', nombreFac, 'El nombre para facturación es obligatorio.');
        } else { quitarError('error-nombre_factura'); }

        // 10e. NIT
        const nit = document.getElementById('nit');
        if (!nit.value.trim()) {
            marcar('error-nit', nit, 'El NIT es obligatorio.');
        } else { quitarError('error-nit'); }

        // 10f. Dirección
        const direccion = document.getElementById('direccion');
        if (!direccion.value.trim()) {
            marcar('error-direccion', direccion, 'La dirección de facturación es obligatoria.');
        } else { quitarError('error-direccion'); }
    }

    // 13. Carta de solicitud (solo para visita a Cengicaña)
    if (!esMuseoDirecto && (!fileInput.files || fileInput.files.length === 0)) {
        marcar('error-archivo', document.querySelector('.file-upload-wrapper'),
               'Debe adjuntar la carta de solicitud.');
    } else if (!esMuseoDirecto) {
        if (fileInput.files[0].size > 10 * 1024 * 1024) {
            marcar('error-archivo', document.querySelector('.file-upload-wrapper'),
                   'El archivo supera el tamaño máximo de 10 MB.');
        } else { quitarError('error-archivo'); }
    } else {
        quitarError('error-archivo');
    }

    // Scroll al primer error
    if (hayError) {
        event.preventDefault();
        if (primeroCon) primeroCon.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
});

/* ═══════════════════════════════════════════════
   MENSAJE DE ÉXITO DESAPARECE
═══════════════════════════════════════════════ */
setTimeout(() => {
    const mensaje = document.getElementById("mensaje-exito");
    if (mensaje) {
        mensaje.style.transition = "opacity 0.5s ease";
        mensaje.style.opacity = "0";
        setTimeout(() => {
            mensaje.remove();
            window.history.replaceState({}, document.title, window.location.pathname);
        }, 500);
    }
}, 3500);
</script>

</body>
</html>
