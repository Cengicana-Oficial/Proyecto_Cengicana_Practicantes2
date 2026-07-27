function filterRows(inputId, tableId) {
  const query = document.getElementById(inputId).value.toLowerCase();
  document.querySelectorAll(`#${tableId} tbody tr`).forEach((row) => {
    row.style.display = row.textContent.toLowerCase().includes(query) ? '' : 'none';
  });
}

function setStatusFilter(button, tableId, estado) {
  button.closest('.filters').querySelectorAll('.filter-chip').forEach((chip) => chip.classList.remove('active'));
  button.classList.add('active');
  document.querySelectorAll(`#${tableId} tbody tr`).forEach((row) => {
    row.style.display = estado === 'todas' || row.dataset.estado === estado ? '' : 'none';
  });
}

function updateTipoForm() {
  const tipo = document.getElementById('tipo').value;
  const campoProgramaDestino = document.getElementById('campo-programa-destino');
  const programaOrigen = document.getElementById('programa_origen_id');
  const programaOrigenLabel = document.getElementById('programa_origen_label');
  const programaOrigenHint = document.getElementById('programa_origen_hint');
  const programaDestino = document.getElementById('programa_destino_id');

  campoProgramaDestino.classList.toggle('hidden', tipo !== 'apoyo');
  document.getElementById('campos-compra').classList.toggle('hidden', tipo !== 'compra');

  if (!programaOrigen) return;

  const administracionOption = Array.from(programaOrigen.options).find((option) => {
    return option.dataset.programName === 'administracion';
  });

  if (tipo === 'compra' || tipo === 'ti') {
    if (administracionOption) programaOrigen.value = administracionOption.value;
    programaOrigen.disabled = true;
    programaOrigen.classList.add('is-locked');
    if (programaOrigenLabel) {
      programaOrigenLabel.innerHTML = 'Area responsable <span class="req">*</span>';
    }
    if (programaOrigenHint) {
      programaOrigenHint.textContent = 'Compras y Soporte TI se asignan automaticamente a Administracion.';
    }
  } else {
    if (tipo === 'apoyo' && programaOrigen.dataset.userProgramId) {
      programaOrigen.value = programaOrigen.dataset.userProgramId;
    }
    programaOrigen.disabled = false;
    programaOrigen.classList.remove('is-locked');
    if (programaOrigenLabel) {
      programaOrigenLabel.innerHTML = 'Area / programa origen <span class="req">*</span>';
    }
    if (programaOrigenHint) {
      programaOrigenHint.textContent = tipo === 'apoyo'
        ? 'Para apoyo, la solicitud sale desde tu area y se envia al area seleccionada abajo.'
        : '';
    }
  }

  if (programaDestino) {
    programaDestino.required = tipo === 'apoyo';
    if (tipo !== 'apoyo') programaDestino.value = '';
  }
}

function updatePermissionPanels() {
  const app = document.querySelector('.app');
  const rolesWithManualPermissions = app?.dataset.manualPermissionRoles
    ? JSON.parse(app.dataset.manualPermissionRoles)
    : [];

  document.querySelectorAll('[data-permission-role]').forEach((select) => {
    const targetId = select.dataset.targetPanel;
    const panel = targetId
      ? document.getElementById(targetId)
      : select.closest('.form-grid')?.querySelector('[data-permission-panel]');

    if (!panel) return;
    const isManual = rolesWithManualPermissions.includes(select.value);
    panel.classList.toggle('permissions-readonly', !isManual);
    panel.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
      checkbox.disabled = !isManual;
    });

    const hint = panel.querySelector('[data-permission-hint]');
    if (hint) {
      hint.textContent = isManual
        ? 'Puedes ajustar permisos para este rol.'
        : 'Permisos automaticos por jerarquia del rol.';
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  const tipo = document.getElementById('tipo');
  if (tipo) {
    tipo.addEventListener('change', updateTipoForm);
    updateTipoForm();
  }

  document.querySelectorAll('[data-permission-role]').forEach((select) => {
    select.addEventListener('change', updatePermissionPanels);
  });
  updatePermissionPanels();

  const seguimientoAdjuntos = document.getElementById('seguimiento_adjuntos');
  if (seguimientoAdjuntos) {
    seguimientoAdjuntos.addEventListener('change', () => {
      const list = document.getElementById('seguimiento_adjuntos_list');
      list.innerHTML = '';
      Array.from(seguimientoAdjuntos.files).forEach((file) => {
        const item = document.createElement('div');
        item.className = 'file-selected-pill';
        item.innerHTML = `<i class="ti ti-file"></i>${file.name}`;
        list.appendChild(item);
      });
    });
  }
});
