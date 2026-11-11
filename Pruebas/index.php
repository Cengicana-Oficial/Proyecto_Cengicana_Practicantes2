<?php
require __DIR__ . "/bootstrap.php";
require_page_user();
?>
<!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
<meta charset="UTF-8">
<title>CengiST · Servicios Técnicos</title>
<link rel="icon" type="image/svg+xml" href="assets/img/logo-cengist.svg">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="assets/vendor/chart.umd.min.js"></script>
<script src="assets/vendor/jspdf.umd.min.js"></script>
<link rel="stylesheet" href="assets/app.css">
<link rel="stylesheet" href="assets/sidebar.css?v=20261109-1">
</head>
<body>
<div class="app-layout">
  <aside class="tech-sidebar" id="techSidebar" aria-label="Servicios Técnicos">
    <div class="tech-brand-row">
      <a class="tech-brand" href="index.php" aria-label="Inicio de CengiST, Servicios Técnicos">
        <img class="cst-logo" src="assets/img/logo-cengist.svg" alt="" aria-hidden="true">
        <span class="tech-brand-copy"><strong>Cengi<b>ST</b></strong><small>Servicios Técnicos</small></span>
      </a>
      <button class="tech-menu-close" type="button" aria-label="Cerrar menú" onclick="setSidebarOpen(false)"><svg class="tech-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6"/></svg></button>
    </div>
    <nav class="tech-sidebar-nav" aria-label="Navegación de Servicios Técnicos">
      <div class="tech-nav-group">
        <div class="tech-nav-label">Gestión de servicios</div>
        <button class="nav-tab active" id="tabList" type="button" aria-current="page" onclick="showView('list')"><svg class="tech-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="16" rx="2"/><path d="M9 5V3h6v2M8 10h8M8 14h8M8 18h5"/></svg><span>Registro de Encuestas</span></button>
        <button class="nav-tab" id="tabContacts" type="button" onclick="showView('contacts')"><svg class="tech-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 4v2"/></svg><span>Base de Contactos</span></button>
      </div>
      <div class="tech-nav-group">
        <div class="tech-nav-label">Reportes</div>
        <button class="nav-tab" id="tabDashboard" type="button" onclick="showView('dashboard')"><svg class="tech-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3v18h18M8 16v-5m5 5V6m5 10v-8"/></svg><span>Estadísticas</span></button>
      </div>
      <div class="tech-nav-group">
        <div class="tech-nav-label">Sistema</div>
        <button class="nav-tab" id="tabUsers" type="button" onclick="showView('users')"><svg class="tech-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6zM8 12l3 3 5-6"/></svg><span>Usuarios y Permisos</span></button>
      </div>
    </nav>
    <div class="tech-sidebar-foot">
      <a class="tech-nav-link" href="../login/Menu.php"><svg class="tech-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 9-7 9 7M5 9v12h5v-7h4v7h5V9"/></svg><span>Menú principal</span></a>
      <button class="tech-nav-link tech-logout" type="button" onclick="logout()"><svg class="tech-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4H4v16h5m-1-8h13m-4-4 4 4-4 4"/></svg><span>Cerrar sesión</span></button>
      <div class="tech-sidebar-version">CengiST · Servicios Técnicos · Cengicaña</div>
    </div>
  </aside>
  <div class="tech-sidebar-backdrop" id="techSidebarBackdrop" hidden></div>

  <main class="main-content">
  <div class="wrap">

  <div class="top-header">
    <button class="tech-menu-toggle" id="techMenuToggle" type="button" aria-label="Abrir menú" aria-controls="techSidebar" aria-expanded="false" onclick="setSidebarOpen(true)"><svg class="tech-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
    <div class="role-switcher">
      <button class="btn btn-primary btn-sm" id="newServiceBtn" onclick="openNewService()">+ Nuevo Servicio</button>
      <label for="currentUserSelect">Sesión</label>
      <select id="currentUserSelect" disabled aria-label="Usuario actual"></select>
    </div>
  </div>

  <!-- ============ LIST VIEW ============ -->
  <div id="appNotice" role="status" aria-live="polite" hidden></div><div id="listView"><div class="page-heading"><div><p class="eyebrow">SERVICIOS TÉCNICOS</p><h1>Registro de encuestas</h1><p>El seguimiento de cada servicio, en un solo lugar.</p></div><span class="page-tag">Cengicaña</span></div>
    <p class="role-note" id="jefeNoteList" style="display:none;">El Profesional puede consultar y crear servicios de su área. Solo el Admin puede responder encuestas desde el módulo.</p>
    <p class="role-note" id="areaNoteList" style="display:none; background:rgba(115,188,37,0.15); color:var(--verde-text);"></p>
    <div class="cards-row">
      <div class="stat-card total">
        <div class="lbl">Total</div>
        <div class="num" id="statTotal">—</div>
      </div>
      <div class="stat-card pend">
        <div class="lbl">Encuestas pendientes</div>
        <div class="num amarillo" id="statPend">—</div>
      </div>
      <div class="stat-card prom">
        <div class="lbl">Promedio</div>
        <div class="num naranja">★ <span id="statProm">—</span></div>
      </div>
    </div>

    <div class="filters">
      <input aria-label="Buscar servicios" type="search" id="fSearch" class="search-input" placeholder="Buscar por ID, cliente, institución o asesor..." oninput="applyFilters()">
      <select aria-label="Filtrar por estado" id="fEstado" onchange="applyFilters()">
        <option value="">Todos los estados</option>
        <option value="Finalizado">Finalizado</option>
        <option value="Abierto">Abierto</option>
      </select>
      <select aria-label="Filtrar por encuesta" id="fEncuesta" onchange="applyFilters()">
        <option value="">Toda encuesta</option>
        <option value="respondida">Respondida</option>
        <option value="pendiente">Pendiente</option>
        <option value="sin-generar">Sin generar</option>
      </select>
      <button class="clear" onclick="clearFilters()">Limpiar</button>
      <button class="btn btn-outline btn-sm" id="exportListBtn" style="margin-left:auto;" onclick="exportListCSV()">↓ Exportar CSV</button>
    </div>

    <div class="card">
      <div class="table-scroll">
        <table class="wide">
          <thead>
            <tr>
              <th>ID</th>
              <th>Cliente / Institución</th>
              <th>Área / Asesor</th>
              <th>Actividad</th>
              <th>Lugar</th>
              <th>Fecha</th>
              <th>Días Abierto</th>
              <th>Estado</th>
              <th>Encuesta</th>
              <th>Puntuación</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody id="tableBody"><tr><td colspan="11" class="empty-state">Cargando servicios…</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ============ DETAIL VIEW ============ -->
  <div id="detailView">
    <div class="back-row"><button id="editServiceBtn" class="btn btn-secondary" onclick="editService(currentId)">Editar servicio</button>
      <button class="btn btn-outline" onclick="showList()">&larr; Volver al listado</button>
    </div>

    <div class="detail-grid" style="margin-bottom:18px;">
      <div class="card" style="margin-bottom:0;">
        <h2 id="dTitle">Detalle del Servicio Técnico</h2>
        <div class="body">
          <div class="field-row">
            <div class="field"><label>Área</label><div class="val" id="dArea">-</div></div>
            <div class="field"><label>Tipo de servicio</label><div class="val" id="dTipo">-</div></div>
          </div>
          <div class="field-row">
            <div class="field"><label>Asesor responsable</label><div class="val" id="dAsesor">-</div></div>
            <div class="field"><label>Estado actual</label><div class="val" id="dEstado">-</div></div>
          </div>
          <div class="field" style="margin-bottom:14px;">
            <label>Descripción de la actividad</label>
            <div class="val" id="dDesc">-</div>
          </div>
          <div class="field-row">
            <div class="field"><label>Fecha de solicitud</label><div class="val" id="dFecha">-</div></div>
            <div class="field"><label>Lugar</label><div class="val" id="dLugar">-</div></div>
          </div>
          <div class="field-row">
            <div class="field"><label>Días abierto</label><div class="val" id="dDias">-</div></div>
            <div class="field"></div>
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:0;">
        <h2>Información de contacto</h2>
        <div class="body">
          <div class="field" style="margin-bottom:14px;">
            <label>Institución / Cliente</label>
            <div class="val" id="dCliente">-</div>
          </div>
          <div class="field" style="margin-bottom:14px;">
            <label>Celular</label>
            <div class="val" id="dCelular">-</div>
          </div>
          <div class="field">
            <label>Email cliente</label>
            <div class="val" id="dEmail">-</div>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <h2>Encuesta de Satisfacción</h2>
      <div class="body">
        <p class="role-note" id="jefeNoteDetail" style="display:none;">Como Profesional puedes consultar esta encuesta, pero no responderla.</p>
        <div class="survey-box-header">
          <span style="font-size:13px;color:var(--text-s);" id="dSurveyStatusLabel">-</span>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button class="btn btn-outline btn-sm" onclick="openPreviewModal(currentId)">Vista previa de la encuesta</button>
            <button class="responder-btn" id="dResponderBtn" onclick="openResponderModal(currentId)">Responder encuesta</button>
          </div>
        </div>

        <div id="detailStateSinGenerar" style="display:none;">
          <p style="font-size:13px;color:var(--text-s);margin:0 0 12px;">Este servicio aún no tiene un enlace de encuesta generado.</p>
          <button class="btn btn-primary" onclick="generarLink()">Generar enlace de encuesta</button>
        </div>

        <div id="detailStatePendiente" style="display:none;">
          <div class="link-box">
            <input id="linkInput" type="text" readonly value="">
            <button class="btn btn-outline btn-sm" onclick="copiarLink()">Copiar enlace</button>
            <a class="btn btn-secondary btn-sm" id="mailBtn" href="#">Abrir correo</a>
            <span class="copy-feedback" id="copyFeedback">¡Copiado!</span>
          </div>
          <div class="pending-note" id="pendingNote">Enlace generado · Aún sin respuesta del cliente</div>
        </div>

        <div id="detailStateRespondida" style="display:none;">
          <div class="answered-box">
            <div>
              <div style="font-size:11.5px;color:var(--text-s);margin-bottom:2px;">Calificación</div>
              <div class="stars-display" id="dStars">-</div>
            </div>
            <div class="comment-display" id="dComment">-</div>
          </div>
          <div class="persona-respondio">Persona que respondió: <strong id="dPersona">-</strong></div>

          <div class="field" style="margin-top:14px;">
            <label>Cargo del cliente</label>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
              <input type="text" id="dCargoInput" placeholder="Ej. Encargado de finca, Agrónomo, Jefe de campo" style="flex:1; min-width:200px; padding:9px 11px; border-radius:8px; border:1px solid var(--border); background:var(--card); color:var(--text-p); font-size:13.5px;">
              <button class="btn btn-secondary btn-sm" onclick="guardarCargoDetalle()">Guardar</button>
            </div>
            <p style="font-size:11.5px;color:var(--text-s);margin:6px 0 0;">Se mantiene sincronizado con la Base de Contactos.</p>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <h2>Transferencia</h2>
      <div class="body">
        <div class="field-row">
          <div class="field"><label>Envío Encuesta</label><div class="val" id="dEnvioEncuesta">-</div></div>
          <div class="field"><label>Transferencia</label><div class="val" id="dTransferencia">-</div></div>
        </div>
        <div id="transferSiBlock">
          <div class="field-row">
            <div class="field"><label>Fecha Transferencia</label><div class="val" id="dFechaTransfer">-</div></div>
            <div class="field"><label>Lugar Transferencia</label><div class="val" id="dLugarTransfer">-</div></div>
          </div>
          <div class="field-row">
            <div class="field"><label>Evidencia (foto, documento, etc.)</label><div class="val" id="dEvidencia">-</div></div>
            <div class="field"><label>Descripción Transferencia</label><div class="val" id="dDescTransfer">-</div></div>
          </div>
        </div>
        <p class="no-transfer-note" id="transferNoBlock" style="display:none;">No se realizó transferencia a otro ingenio para este servicio.</p>
      </div>
    </div>

    <div class="card" id="seguimientoCard" style="display:none;">
      <h2>Seguimiento y notificación al asesor</h2>
      <div class="body">
        <div class="survey-box-header">
          <span id="segEstadoPill">-</span>
          <button class="btn btn-primary btn-sm" id="notificarBtn" onclick="notificarAsesor()">Notificar al asesor</button>
        </div>
        <p class="pending-note" id="notificadoNote" style="display:none;"></p>
        <p class="role-note" id="segNoAccessNote" style="display:none;">Solo el Encargado de Seguimiento (o Administrador) puede notificar al asesor y clasificar este caso. Tu rol actual solo puede consultar el estado.</p>

        <div id="segControls" style="margin-top:10px;">
          <div class="field" style="margin-bottom:14px;">
            <label>Actualizar clasificación</label>
            <div class="seg-pill-group" id="segEstadoPills">
              <button type="button" class="seg-pill seg-pill-pendiente" data-v="pendiente" onclick="selectSegEstado('pendiente')">Pendiente</button>
              <button type="button" class="seg-pill seg-pill-contactado" data-v="contactado" onclick="selectSegEstado('contactado')">Contactado</button>
              <button type="button" class="seg-pill seg-pill-resuelto" data-v="resuelto" onclick="selectSegEstado('resuelto')">Resuelto</button>
            </div>
          </div>
          <div class="field" style="margin-bottom:12px;">
            <label>Nota de seguimiento</label>
            <input type="text" id="segNotaInput" placeholder="Ej. Se llamó al cliente, se reprogramó visita">
          </div>
          <button class="btn btn-secondary btn-sm" onclick="registrarSeguimiento()">Registrar seguimiento</button>
        </div>

        <div id="segHistorial" style="margin-top:16px;"></div>
      </div>
    </div>

    <div class="card">
      <h2>Historial de acciones del servicio</h2>
      <div class="body">
        <div id="trackHistorial"></div>
      </div>
    </div>
  </div>

  <!-- ============ CONTACTS VIEW ============ -->
  <div id="contactsView">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; margin-bottom:20px;">
      <p class="role-note" style="background:rgba(115,188,37,0.15); color:var(--verde-text); margin-bottom:0; flex:1; min-width:260px;">Contactos consolidados a partir de los servicios registrados. Descarga el directorio completo o un contacto para importarlo en tu sistema institucional.</p>
      <button class="btn btn-secondary btn-sm" id="bulkSyncBtn" onclick="bulkSyncAll()">Exportar contactos</button>
    </div>
    <p class="section-title">Asesores / personal técnico</p>
    <div class="card">
      <div class="table-scroll">
        <table>
          <thead>
            <tr><th>Nombre</th><th>Área(s)</th><th>Servicios atendidos</th><th>Email</th><th>Exportar contacto</th></tr>
          </thead>
          <tbody id="asesoresBody"></tbody>
        </table>
      </div>
    </div>

    <p class="section-title">Clientes / personas que reciben la encuesta</p>
    <div class="card">
      <div class="table-scroll">
        <table>
          <thead>
            <tr><th>Nombre</th><th>Institución</th><th>Email</th><th>Celular</th><th>Servicios</th><th>Última encuesta</th><th>Exportar contacto</th></tr>
          </thead>
          <tbody id="clientesBody"></tbody>
        </table>
      </div>
    </div>

    <p class="section-title">Contactos de transferencia (auto-registrados)</p>
    <div class="card">
      <p class="report-note" style="padding:16px 20px 0;">Los destinos se consolidan automáticamente a partir de las transferencias registradas.</p>
      <div class="table-scroll">
        <table>
          <thead>
            <tr><th>Nombre / Ingenio</th><th>Origen (servicio)</th><th>Fecha</th><th>Descripción</th><th>Exportar contacto</th></tr>
          </thead>
          <tbody id="transferContactsBody"></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ============ DASHBOARD VIEW ============ -->
  <div id="dashboardView">
    <div class="filters">
      <select id="dashIngenio" onchange="renderDashboard()">
        <option value="">Todos los ingenios</option>
      </select>
      <select id="dashMes" onchange="renderDashboard()">
        <option value="">Todos los meses</option>
      </select>
      <button class="clear" onclick="clearDashboardFilters()">Limpiar</button>
      <button class="btn btn-secondary btn-sm" id="exportFullReportBtn" style="margin-left:auto;" onclick="exportFullReportCSV()">Descargar reporte completo</button>
      <button class="btn btn-primary btn-sm" id="exportPdfBtn" onclick="exportDashboardPDF()">Descargar PDF</button>
      <button class="btn btn-outline btn-sm" id="exportCommentsBtn" onclick="exportCommentsCSV()">Exportar comentarios y calificaciones</button>
    </div>

    <div class="cards-row four">
      <div class="stat-card total">
        <div class="lbl">Servicios</div>
        <div class="num" id="dashTotal">-</div>
      </div>
      <div class="stat-card total" style="border-left-color:var(--verde-sec);">
        <div class="lbl">Encuestas enviadas</div>
        <div class="num" id="dashEnviadas">-</div>
      </div>
      <div class="stat-card pend">
        <div class="lbl">% de respuesta</div>
        <div class="num amarillo" id="dashPct">-</div>
      </div>
      <div class="stat-card prom">
        <div class="lbl">Promedio</div>
        <div class="num naranja">★ <span id="dashProm">-</span></div>
      </div>
    </div>

    <div class="charts-row">
      <div class="chart-card">
        <h3>Estado de la encuesta (según filtro)</h3>
        <div class="chart-wrap"><canvas id="chartEstado"></canvas></div>
      </div>
      <div class="chart-card">
        <h3>Promedio por ingenio (según filtros)</h3>
        <div class="chart-wrap"><canvas id="chartIngenio"></canvas></div>
      </div>
      <div class="chart-card">
        <h3>Servicios por área, coloreado por ingenio</h3>
        <div class="chart-wrap"><canvas id="chartArea"></canvas></div>
      </div>
    </div>

    <div class="card">
      <h2 style="display:flex;justify-content:space-between;align-items:center;">
        <span>Resumen por ingenio</span>
        <button class="btn btn-outline btn-sm" id="exportReportBtn" onclick="exportReportCSV()">Exportar CSV</button>
      </h2>
      <div class="body" style="padding:0;">
        <div class="table-scroll">
          <table>
            <thead>
              <tr><th>Ingenio</th><th>Servicios</th><th>Enviadas</th><th>Respondidas</th><th>% Respuesta</th><th>Promedio</th></tr>
            </thead>
            <tbody id="reportBody"></tbody>
          </table>
        </div>
        <p class="report-note" style="padding:0 20px 16px;">Las tablas y gráficas respetan los filtros de institución y mes seleccionados.</p>
      </div>
    </div>

    <div class="card">
      <h2 style="display:flex;justify-content:space-between;align-items:center;">
        <span>Resumen por área</span>
        <button class="btn btn-outline btn-sm" id="exportAreaBtn" onclick="exportAreaCSV()">Exportar CSV</button>
      </h2>
      <div class="body" style="padding:0;">
        <div class="table-scroll">
          <table>
            <thead>
              <tr id="areaReportHead"><th>Área</th><th>Servicios</th><th>% del total (mes filtrado)</th></tr>
            </thead>
            <tbody id="areaReportBody"></tbody>
          </table>
        </div>
      </div>
    </div>

    <p class="section-title">Transferencias entre ingenios</p>
    <div class="cards-row">
      <div class="stat-card total">
        <div class="lbl">Transferencias registradas</div>
        <div class="num" id="dashTransferTotal">-</div>
      </div>
      <div class="stat-card pend">
        <div class="lbl">% de servicios con transferencia</div>
        <div class="num amarillo" id="dashTransferPct">-</div>
      </div>
      <div class="stat-card prom">
        <div class="lbl">Ingenios destino distintos</div>
        <div class="num naranja" id="dashTransferDestinos">-</div>
      </div>
    </div>
    <div class="charts-row">
      <div class="chart-card">
        <h3>Transferencias por ingenio destino</h3>
        <div class="chart-wrap"><canvas id="chartTransferDestino"></canvas></div>
      </div>
      <div class="chart-card">
        <h3>Transferencias por área de origen</h3>
        <div class="chart-wrap"><canvas id="chartTransferArea"></canvas></div>
      </div>
    </div>
    <div class="card">
      <h2 style="display:flex;justify-content:space-between;align-items:center;">
        <span>Detalle de transferencias</span>
        <button class="btn btn-outline btn-sm" id="exportTransferBtn" onclick="exportTransferCSV()">Exportar CSV</button>
      </h2>
      <div class="body" style="padding:0;">
        <div class="table-scroll">
          <table>
            <thead>
              <tr><th>Servicio</th><th>Cliente</th><th>Institución origen</th><th>Destino</th><th>Área</th><th>Fecha</th></tr>
            </thead>
            <tbody id="transferDetailBody"></tbody>
          </table>
        </div>
      </div>
    </div>

    <p class="section-title">Servicios por contacto (acumulado)</p>
    <div class="charts-row" style="grid-template-columns:1fr 1fr;">
      <div class="chart-card">
        <h3>Top clientes por servicios recibidos</h3>
        <div class="chart-wrap"><canvas id="chartTopClientes"></canvas></div>
      </div>
      <div class="chart-card">
        <h3>Top asesores por servicios / encuestas enviadas</h3>
        <div class="chart-wrap"><canvas id="chartTopAsesores"></canvas></div>
      </div>
    </div>
  </div>

  <!-- ============ NEW SERVICE FORM ============ -->
  <div id="newServiceView">
    <div class="back-row">
      <button class="btn btn-outline" onclick="showView('list')">&larr; Regresar</button>
    </div>

    <div id="newServiceBlocked" class="card" style="display:none;">
      <div class="role-block">No tienes permiso para registrar un nuevo servicio con el rol actual (<strong id="blockedRoleLabel"></strong>). Esta acción está disponible para Profesional y Admin.</div>
    </div>

    <div class="card" id="newServiceForm">
      <h2>Registro de Nuevo Servicio</h2>
      <div class="body">

        <div class="form-section-title">1. Datos del Cliente e Institución</div>
        <div class="form-grid">
          <div class="form-field">
            <label>Institución / Empresa <span class="req">*</span></label>
            <select id="nsInstitucion" onchange="document.getElementById('nsInstitucionOtra').style.display = this.value === '__otra' ? 'block' : 'none';">
                        <option value="">Seleccione una opción...</option>
                        <option value="Pantaleon">Pantaleon</option>
                        <option value="Palo Gordo">Palo Gordo</option>
                        <option value="Madre Tierra">Madre Tierra</option>
                        <option value="Tulula">Tulula</option>
                        <option value="La Unión">La Unión</option>
                        <option value="Santa Ana">Santa Ana</option>
                        <option value="Magdalena">Magdalena</option>
                        <option value="San Diego/Trinidad">San Diego/Trinidad</option>
                        <option value="ICC">ICC</option>
                        <option value="Asazgua">Asazgua</option>
                        <option value="Todos los ingenios">Todos los ingenios</option>
                        <option value="__otra">Otros</option>
                    </select>
            <input type="text" id="nsInstitucionOtra" placeholder="Nombre de la institución" style="margin-top:8px; display:none;">
          </div>
          <div class="form-field">
            <label>Nombre del Cliente <span class="req">*</span></label>
            <input type="text" id="nsCliente" placeholder="Nombre y apellido">
          </div>
        </div>
        <div class="form-grid three">
          <div class="form-field">
            <label>Email Cliente</label>
            <input type="email" id="nsEmail" placeholder="cliente@correo.com">
          </div>
          <div class="form-field">
            <label>Celular Cliente</label>
            <input type="text" id="nsCelular" placeholder="00000000">
          </div>
          <div class="form-field">
            <label>Fecha Solicitud</label>
            <input type="date" id="nsFechaSolicitud">
          </div>
        </div>

        <div class="form-section-title">2. Detalles del Servicio Técnico</div>
        <div class="form-grid three">
          <div class="form-field">
            <label>Área <span class="req">*</span></label>
            <select id="nsArea">
                        <option value="">Seleccione área...</option>
                        <option value="Dirección">Dirección</option>
                        <option value="Variedades">Variedades</option>
                        <option value="Fitopatología">Fitopatología</option>
                        <option value="Biotecnología">Biotecnología</option>
                        <option value="Estación Experimental">Estación Experimental</option>
                        <option value="Manejo Integrado de Plagas">Manejo Integrado de Plagas</option>
                        <option value="Agricultura Inteligente">Agricultura Inteligente</option>
                        <option value="Mecanización">Mecanización</option>
                        <option value="Riegos">Riegos</option>
                        <option value="Malezas y Madurantes">Malezas y Madurantes</option>
                        <option value="Capacitación y Transferencia de Tecnología">Capacitación y Transferencia de Tecnología</option>
                        <option value="Eficiencia Energética">Eficiencia Energética</option>
                        <option value="Recuperación de Sacarosa">Recuperación de Sacarosa</option>
                        <option value="Laboratorio Agroindustrial">Laboratorio Agroindustrial</option>
                        <option value="Innovación">Innovación</option>
                    </select>
          </div>
          <div class="form-field">
            <label>Asesor Asignado <span class="req">*</span></label>
            <select id="nsAsesor" onchange="autofillAsesorEmail()">
                        <option value="">Seleccione asesor...</option>
                        <option value="Ing. Mario Sagastume">Ing. Mario Sagastume</option>
                        <option value="Dr. Héctor Orozco">Dr. Héctor Orozco</option>
                        <option value="Dr. Luis Molina">Dr. Luis Molina</option>
                        <option value="Dr. José Quemé">Dr. José Quemé</option>
                        <option value="Ing. Aldo Salazar">Ing. Aldo Salazar</option>
                        <option value="Ing. Salomón García">Ing. Salomón García</option>
                        <option value="Ing. Braulio Villatoro">Ing. Braulio Villatoro</option>
                        <option value="Dr. Gerardo Espinoza">Dr. Gerardo Espinoza</option>
                        <option value="Ing. Hector Monterroso">Ing. Hector Monterroso</option>
                        <option value="Ing. Boris Elías">Ing. Boris Elías</option>
                        <option value="Ing. Mario Muñoz">Ing. Mario Muñoz</option>
                        <option value="Inga. Raisa Vega">Inga. Raisa Vega</option>
                        <option value="Ing. Fernando Rosales">Ing. Fernando Rosales</option>
                        <option value="Ing. Manuel Tobar">Ing. Manuel Tobar</option>
                        <option value="Ing. Héctor Martinez">Ing. Héctor Martinez</option>
                        <option value="Inga. Mariela Samayoa">Inga. Mariela Samayoa</option>
                        <option value="Lic. Jorge Martinez">Lic. Jorge Martinez</option>
                        <option value="Ing. Enio Duque">Ing. Enio Duque</option>
                        <option value="Ing. Danny Santos">Ing. Danny Santos</option>
                        <option value="Ing. Angel Espinoza">Ing. Angel Espinoza</option>
                        <option value="Ing. Henry Rodas">Ing. Henry Rodas</option>
                        <option value="Ing. Luis Castillo">Ing. Luis Castillo</option>
                        <option value="Ing. Werner de León">Ing. Werner de León</option>
                    </select>
          </div>
          <div class="form-field">
            <label>Email Profesional</label>
            <input type="email" id="nsEmailProfesional" placeholder="asesor@cengicana.org">
          </div>
        </div>
        <div class="form-grid three">
          <div class="form-field">
            <label>Tipo de Servicio <span class="req">*</span></label>
            <select id="nsTipo">
                        <option value="">Seleccione tipo...</option>
                        <option value="Presentación de resultados">Presentación de resultados</option>
                        <option value="Asesoria/consultoria">Asesoria/consultoria</option>
                        <option value="Conferencia">Conferencia</option>
                        <option value="Gira de campo">Gira de campo</option>
                        <option value="Análisis de Información">Análisis de Información</option>
                        <option value="Capacitación">Capacitación</option>
                    </select>
          </div>
          <div class="form-field">
            <label>Estado Actual</label>
            <select id="nsEstado">
              <option value="Abierto">Abierto</option>
              <option value="Finalizado">Finalizado</option>
            </select>
          </div>
          <div class="form-field">
            <label>Fecha de Cierre de Servicio</label>
            <input type="date" id="nsFechaCierre">
          </div>
        </div>
        <div class="form-field" style="margin-bottom:16px;">
          <label>Descripción Detallada <span class="req">*</span></label>
          <textarea id="nsDescripcion" placeholder="Describa la actividad realizada"></textarea>
        </div>
        <div class="form-grid three">
          <div class="form-field">
            <label>Lugar / Ubicación</label>
            <input type="text" id="nsLugar" placeholder="Lugar donde se brindó el servicio">
          </div>
          <div class="form-field">
            <label>Medio de Respuesta</label>
            <input type="text" id="nsMedio" placeholder="Presencial, llamada, etc.">
          </div>
          <div class="form-field">
            <label>N. de Participantes</label>
            <input type="number" id="nsParticipantes" min="0" value="0">
          </div>
        </div>

        <div class="form-section-title">3. Contacto y Evidencia Documental</div>
        <div class="form-grid three">
          <div class="form-field">
            <label>Adjuntar Evidencia (Foto/Archivo)</label>
            <label class="file-fake" for="nsEvidenciaFile">
              <span id="nsEvidenciaLabel">Seleccionar archivo · sin adjunto</span>
            </label>
            <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" id="nsEvidenciaFile" style="display:none;" onchange="updateEvidenciaLabel()">
          </div>
          <div class="form-field">
            <label>¿Envío Encuesta?</label>
            <div class="toggle-group">
              <button type="button" class="toggle-btn active" id="nsEnvioSi" onclick="setToggle('nsEnvio','si')">Sí</button>
              <button type="button" class="toggle-btn" id="nsEnvioNo" onclick="setToggle('nsEnvio','no')">No</button>
            </div>
          </div>
          <div class="form-field">
            <label>¿Realizará Transferencia a otros ingenios?</label>
            <div class="toggle-group">
              <button type="button" class="toggle-btn" id="nsTransferSi" onclick="setToggle('nsTransfer','si')">Sí</button>
              <button type="button" class="toggle-btn active" id="nsTransferNo" onclick="setToggle('nsTransfer','no')">No</button>
            </div>
          </div>
        </div>

        <div id="nsTransferFields" style="display:none;">
          <div class="form-grid">
            <div class="form-field">
              <label>Fecha Transferencia</label>
              <input type="date" id="nsFechaTransfer">
            </div>
            <div class="form-field">
              <label>Lugar Transferencia</label>
              <input type="text" id="nsLugarTransfer" placeholder="Ingenio destino">
            </div>
          </div>
          <div class="form-field" style="margin-bottom:16px;">
            <label>Descripción Transferencia</label>
            <textarea id="nsDescTransfer" placeholder="Detalle de lo que se transfiere"></textarea>
          </div>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
          <button class="btn btn-outline" onclick="showView('list')">Cancelar</button>
          <button class="btn btn-secondary" onclick="guardarNuevoServicio()">Guardar y Ver Detalle</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ============ USERS & PERMISSIONS ============ -->
  <div id="usersView">
    <div id="usersBlocked" class="card" style="display:none;">
      <div class="role-block">No tienes permiso para ver esta sección con el rol actual (<strong id="blockedRoleLabelUsers"></strong>). La gestión de usuarios y permisos está reservada para Administrador.</div>
    </div>

    <div id="usersContent">
      <div class="page-heading"><div><h1>Usuarios y Permisos</h1><p>Asigna un perfil y área a los usuarios de Módulos y pulsa Guardar.</p></div></div>
      <details class="access-permissions">
      <summary>Ver permisos de Admin y Profesional</summary>
      <div class="card">
        <div class="table-scroll">
          <table>
            <thead>
              <tr><th>Acción</th><th>Admin</th><th>Profesional</th></tr>
            </thead>
            <tbody id="permMatrixBody"></tbody>
          </table>
        </div>
        <p class="report-note" style="padding:0 20px 16px;">Admin: acceso completo a todas las áreas y respuesta de encuestas. Profesional: consulta y creación en su área, sin permiso para responder. Selecciona el perfil y área de cada usuario y pulsa Guardar. Sin acceso retira únicamente el acceso a este módulo.</p>
      </div>

      </details>
      <p class="section-title" style="display:flex; justify-content:space-between; align-items:center;">
        <span>Usuarios de Módulos</span>
        <a class="btn btn-primary btn-sm" href="../login/usuarios/usuarios.php">Administrar en Módulos</a>
      </p>
      <div class="card">
        <div class="table-scroll">
          <table>
            <thead>
              <tr><th>Nombre</th><th>Email</th><th>Perfil</th><th>Área</th><th>Acciones</th></tr>
            </thead>
            <tbody id="usersBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  </div>
  </main>
</div>

<!-- ============ MODAL: LLENAR POR AUXILIAR ============ -->
<div class="modal-overlay" id="modalOverlay">
  <div class="modal-box">
    <span class="modal-tag">Admin</span>
    <h3>Registrar respuesta por llamada</h3>
    <p class="modal-sub" id="modalSub">Servicio ST-2026-000 · La persona no llenó la encuesta en línea, se le contactó por teléfono.</p>

    <div class="open-field">
      <label>Nombre de quien responde</label>
      <input type="text" id="mNombre" placeholder="Nombre y apellido">
      <p class="error-txt" id="mNombreErr">Ingrese el nombre de la persona.</p>
    </div>

    <div class="open-field">
      <label>Cargo de quien responde</label>
      <input type="text" id="mCargo" placeholder="Ej. Encargado de finca, Agrónomo, Jefe de campo">
      <p style="font-size:11.5px;color:var(--text-s);margin:5px 0 0;">Se usará para completar el contacto en la Base de Contactos.</p>
    </div>

    <div class="open-field">
      <label>Calificación sobre el servicio recibido *</label>
      <div class="star-picker" id="modalStarPicker">
        <button data-v="1" onclick="rateModal(1)">★</button>
        <button data-v="2" onclick="rateModal(2)">★</button>
        <button data-v="3" onclick="rateModal(3)">★</button>
        <button data-v="4" onclick="rateModal(4)">★</button>
        <button data-v="5" onclick="rateModal(5)">★</button>
      </div>
      <p class="error-txt" id="mStarsErr">Seleccione una calificación.</p>
    </div>

    <div class="open-field">
      <label>Comentarios</label>
      <textarea id="mComentario" placeholder="Comentario de la persona"></textarea>
    </div>

    <div class="modal-actions">
      <button class="btn btn-outline btn-sm" onclick="closeResponderModal()">Cancelar</button>
      <button class="btn btn-secondary btn-sm" onclick="submitResponderModal()">Guardar respuesta</button>
    </div>
  </div>
</div>

<!-- ============ MODAL: VISTA PREVIA DE LA ENCUESTA ============ -->
<div class="modal-overlay" id="previewModalOverlay">
  <div class="modal-box wide">
    <div class="preview-page" id="previewPage">
      <h3>Encuesta de Satisfacción</h3>
      <p class="preview-banner">Vista previa · así la vería la persona que recibe el enlace. No guarda datos reales.</p>

      <div class="link-box" style="margin-bottom:16px;">
        <input id="pvLinkInput" type="text" readonly value="">
        <button class="btn btn-outline btn-sm" onclick="copiarLinkPreview()">Copiar enlace</button>
        <span class="copy-feedback" id="pvCopyFeedback">¡Copiado!</span>
      </div>
      <p style="font-size:11.5px;color:var(--text-s);margin:-10px 0 16px;">Puedes copiar este enlace y enviarlo por cualquier otro medio (WhatsApp, SMS, etc.) sin pasar por el correo.</p>

      <div class="locked-field">
        <label>Código de Servicio</label>
        <div class="locked-input"><span id="pvCodigo">-</span><span>&#128274;</span></div>
      </div>
      <div class="locked-field">
        <label>Descripción de la Actividad</label>
        <div class="locked-input"><span id="pvDesc">-</span><span>&#128274;</span></div>
      </div>
      <div class="field-row" style="grid-template-columns:1fr 1fr;">
        <div class="locked-field">
          <label>Asesor</label>
          <div class="locked-input"><span id="pvAsesor">-</span><span>&#128274;</span></div>
        </div>
        <div class="locked-field">
          <label>Fecha</label>
          <div class="locked-input"><span id="pvFecha">-</span><span>&#128274;</span></div>
        </div>
      </div>
      <div class="locked-field">
        <label>Ingenio</label>
        <div class="locked-input"><span id="pvIngenio">-</span><span>&#128274;</span></div>
      </div>

      <div class="open-field">
        <label>Nombre y Apellido</label>
        <input type="text" id="pvNombre" placeholder="Escriba su nombre completo">
      </div>

      <div class="open-field">
        <label>Cargo</label>
        <input type="text" id="pvCargo" placeholder="Ej. Encargado de finca, Agrónomo, Jefe de campo">
      </div>

      <div class="open-field">
        <label>Calificación sobre el Servicio Recibido *</label>
        <div class="star-picker" id="previewStarPicker">
          <button data-v="1" onclick="ratePreview(1)">★</button>
          <button data-v="2" onclick="ratePreview(2)">★</button>
          <button data-v="3" onclick="ratePreview(3)">★</button>
          <button data-v="4" onclick="ratePreview(4)">★</button>
          <button data-v="5" onclick="ratePreview(5)">★</button>
        </div>
        <p style="font-size:11.5px;color:var(--text-s);margin:2px 0 0;">Siendo 1 Muy insatisfecho y 5 Muy satisfecho</p>
      </div>

      <div class="open-field">
        <label>Comentarios *</label>
        <textarea id="pvComentario" placeholder="Cuéntenos su experiencia"></textarea>
      </div>

      <div class="modal-actions" style="justify-content:space-between;">
        <button class="btn btn-outline btn-sm" onclick="closePreviewModal()">Cerrar vista previa</button>
        <button class="btn btn-secondary btn-sm" onclick="alert('Esto es solo una vista previa: en la encuesta real este botón enviaría la respuesta del cliente.')">Enviar</button>
      </div>
    </div>
  </div>
</div>

<!-- ============ MODAL: TRACK DE ACCIONES ============ -->
<div class="modal-overlay" id="trackModalOverlay">
  <div class="modal-box">
    <span class="modal-tag" style="background:rgba(115,188,37,0.12); color:var(--verde-text);">Track</span>
    <h3 id="trackModalTitle">Historial de acciones</h3>
    <p class="modal-sub" id="trackModalSub">-</p>
    <div id="trackModalHistorial" style="max-height:320px; overflow-y:auto;"></div>
    <div class="modal-actions">
      <button class="btn btn-outline btn-sm" onclick="closeTrackModal()">Cerrar</button>
      <button class="btn btn-secondary btn-sm" onclick="openDetail(trackModalId); closeTrackModal();">Ver detalle completo</button>
    </div>
  </div>
</div>

<!-- ============ MODAL: ENVIAR A SISTEMA EXTERNO ============ -->
<div class="modal-overlay" id="syncModalOverlay">
  <div class="modal-box">
    <span class="modal-tag" style="background:rgba(255,107,0,0.12); color:var(--naranja-text);">Exportar contacto</span>
    <h3>Exportar contacto</h3>
    <p class="modal-sub">Descarga estos datos como CSV para importarlos en tu directorio institucional.</p>

    <div class="locked-field">
      <label>Nombre</label>
      <div class="locked-input"><span id="syncNombre">-</span></div>
    </div>
    <div class="locked-field">
      <label>Cargo</label>
      <div class="locked-input"><span id="syncCargo">-</span></div>
    </div>
    <div class="field-row">
      <div class="locked-field" style="margin-bottom:0;">
        <label>Empresa / Ingenio</label>
        <div class="locked-input"><span id="syncEmpresa">-</span></div>
      </div>
      <div class="locked-field" style="margin-bottom:0;">
        <label>Servicios</label>
        <div class="locked-input"><span id="syncServicios">-</span></div>
      </div>
    </div>
    <div class="field-row" style="margin-top:14px;">
      <div class="locked-field" style="margin-bottom:0;">
        <label>Teléfono</label>
        <div class="locked-input"><span id="syncTelefono">-</span></div>
      </div>
      <div class="locked-field" style="margin-bottom:0;">
        <label>Correo</label>
        <div class="locked-input"><span id="syncCorreo">-</span></div>
      </div>
    </div>

    <div class="modal-actions">
      <button class="btn btn-outline btn-sm" onclick="closeSyncModal()">Cancelar</button>
      <button class="btn btn-secondary btn-sm" onclick="confirmSync()">Descargar contacto</button>
    </div>
  </div>
</div>

<script src="assets/prototype.js"></script>
<script src="assets/app.js"></script>
</body>
</html>
