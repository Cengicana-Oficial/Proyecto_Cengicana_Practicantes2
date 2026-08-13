(function () {
  const DATA_SELECTOR = 'script[data-lab-table-config]';
  const SKIP_TYPES = new Set(['hidden', 'submit', 'button', 'reset']);

  function parseConfig(form) {
    const configNode = form.querySelector(DATA_SELECTOR);
    if (!configNode) {
      return { lotes: [], muestras: {}, muestrasUsadas: {}, loteActual: '', solicitudes: [] };
    }

    try {
      return JSON.parse(configNode.textContent || '{}');
    } catch (error) {
      return { lotes: [], muestras: {}, muestrasUsadas: {}, loteActual: '', solicitudes: [] };
    }
  }

  function getControlLabel(control) {
    const id = control.getAttribute('id');
    const escapedId = id && window.CSS && CSS.escape ? CSS.escape(id) : id;
    const explicit = escapedId ? control.ownerDocument.querySelector(`label[for="${escapedId}"]`) : null;
    if (explicit) {
      return explicit.textContent.trim();
    }

    const label = control.closest('label');
    if (label) {
      return Array.from(label.childNodes)
        .filter((node) => node.nodeType === Node.TEXT_NODE)
        .map((node) => node.textContent.trim())
        .join(' ')
        .trim() || control.name;
    }

    return control.name.replace(/_/g, ' ');
  }

  function normalizeText(value) {
    return (value || '')
      .toString()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase();
  }

  function normalizedValue(value) {
    return (value || '').toString().trim();
  }

  function normalizedObjectLookup(source, key) {
    if (!source || typeof source !== 'object') {
      return undefined;
    }

    if (Object.prototype.hasOwnProperty.call(source, key)) {
      return source[key];
    }

    const trimmedKey = normalizedValue(key);
    if (trimmedKey && Object.prototype.hasOwnProperty.call(source, trimmedKey)) {
      return source[trimmedKey];
    }

    const match = Object.keys(source).find((candidate) => normalizedValue(candidate) === trimmedKey);
    return match ? source[match] : undefined;
  }

  function lotesFromConfig(config) {
    const lotes = Array.isArray(config.lotes) ? config.lotes : [];
    const merged = [...lotes, ...Object.keys(config.muestras || {})];
    const seen = new Set();

    return merged
      .map((value) => normalizedValue(value))
      .filter((value) => {
        if (!value || seen.has(value)) {
          return false;
        }

        seen.add(value);
        return true;
      });
  }

  function buildSelectionState(config) {
    const solicitudes = Array.isArray(config.solicitudes) ? config.solicitudes : [];
    const normalizedSolicitudes = [];
    const lotes = [];
    const muestras = {};
    const muestrasUsadas = {};
    const loteLabels = {};
    const idsByCodigo = {};
    const seenIds = new Set();

    solicitudes.forEach((solicitud) => {
      const idSolicitud = normalizedValue(solicitud?.id_solicitud);
      const codigoLote = normalizedValue(solicitud?.codigo_lote);
      if (!idSolicitud || !codigoLote || seenIds.has(idSolicitud)) {
        return;
      }

      seenIds.add(idSolicitud);
      lotes.push(idSolicitud);
      loteLabels[idSolicitud] = codigoLote;
      muestras[idSolicitud] = Array.isArray(solicitud?.muestras)
        ? solicitud.muestras.map((value) => normalizedValue(value)).filter(Boolean)
        : [];
      muestrasUsadas[idSolicitud] = Array.isArray(solicitud?.muestrasUsadas)
        ? solicitud.muestrasUsadas.map((value) => normalizedValue(value)).filter(Boolean)
        : [];
      idsByCodigo[codigoLote] ??= [];
      idsByCodigo[codigoLote].push(idSolicitud);
      normalizedSolicitudes.push({
        id_solicitud: idSolicitud,
        id_lote: normalizedValue(solicitud?.id_lote),
        codigo_lote: codigoLote,
        tipo_muestra: normalizedValue(solicitud?.tipo_muestra),
        muestras: muestras[idSolicitud],
        muestrasUsadas: muestrasUsadas[idSolicitud],
      });
    });

    if (normalizedSolicitudes.length) {
      return {
        useSolicitudes: true,
        lotes,
        muestras,
        muestrasUsadas,
        loteLabels,
        idsByCodigo,
        solicitudes: normalizedSolicitudes,
      };
    }

    return {
      useSolicitudes: false,
      lotes: lotesFromConfig(config),
      muestras: config.muestras || {},
      muestrasUsadas: config.muestrasUsadas || {},
      loteLabels: {},
      idsByCodigo: {},
      solicitudes: [],
    };
  }

  function resolveInitialLoteKey(config, state) {
    const requested = normalizedValue(config.loteActual);
    if (!requested || !state.lotes.length) {
      return state.lotes[0] || '';
    }

    if (!state.useSolicitudes) {
      return requested;
    }

    if (state.lotes.includes(requested)) {
      return requested;
    }

    const matches = state.idsByCodigo?.[requested] || [];
    return matches[0] || state.lotes[0] || '';
  }

  function muestrasForLote(muestras, lote) {
    const values = normalizedObjectLookup(muestras, lote);
    return Array.isArray(values) ? values : [];
  }

  function muestrasUsadasForLote(muestrasUsadas, lote) {
    const values = normalizedObjectLookup(muestrasUsadas, lote);
    return Array.isArray(values) ? values : [];
  }

  function isSharedAnalysisControl(control) {
    const scope = (control.dataset.labScope || '').toLowerCase();
    return scope === 'single' || control.dataset.labSingle === '1';
  }

  function isPrimaryControl(control, footer) {
    if (!control.name || control.closest('.form-footer') || control.closest('.table-wrap')) {
      return false;
    }

    if (footer && footer.contains(control)) {
      return false;
    }

    const type = (control.getAttribute('type') || '').toLowerCase();
    if (SKIP_TYPES.has(type)) {
      return false;
    }

    return !control.name.endsWith('[]');
  }

  function hideOriginal(control) {
    control.disabled = true;

    const field = control.closest('.field');
    if (field && !field.querySelector('.form-footer')) {
      const fieldControls = Array.from(field.querySelectorAll('input, select, textarea'))
        .filter((item) => !item.closest('.table-wrap'));

      if (fieldControls.length <= 1) {
        field.classList.add('lab-original-field-hidden');
        return;
      }
    }

    const label = control.closest('label');
    if (label) {
      label.classList.add('lab-original-field-hidden');
    }
  }

  function buildDataInput(definition, rowIndex) {
    const input = definition.template.cloneNode(true);
    input.disabled = false;
    input.name = `${definition.name}[]`;
    input.removeAttribute('id');
    input.dataset.baseName = definition.name;
    input.dataset.rowIndex = String(rowIndex);
    return input;
  }

  function buildPlaceholderInput(name, rowIndex) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = `${name}[]`;
    input.value = '';
    input.dataset.baseName = name;
    input.dataset.rowIndex = String(rowIndex);
    return input;
  }

  function fillLoteOptions(select, lotes, selected, loteLabels = {}) {
    select.innerHTML = '';

    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = 'Seleccione';
    select.appendChild(empty);

    lotes.forEach((lote) => {
      const option = document.createElement('option');
      option.value = lote;
      option.textContent = loteLabels[lote] || lote;
      option.selected = lote === selected;
      if (loteLabels[lote]) {
        option.dataset.codigoLote = loteLabels[lote];
      }
      select.appendChild(option);
    });
  }

  function getLoteSelect(row) {
    if (!row) {
      return null;
    }

    return row.querySelector('select[data-lab-lote-select="1"]')
      || row.querySelector('select[name="id_solicitud[]"]')
      || row.querySelector('select[name="lote[]"]');
  }

  function buildLaboratorioSpecialOptions(definitions) {
    return definitions.map((definition) => ({
      value: `__shared__:${definition.name}`,
      label: definition.label,
    }));
  }

  function sharedRowDefinitions(definitions) {
    return definitions.map((definition) => ({
      value: `__shared__:${definition.name}`,
      label: definition.label,
      definition,
    }));
  }

  function laboratorioValues(muestras, lote, specialOptions = [], muestrasUsadas = {}) {
    if (!lote) {
      return [''];
    }

    const usedValues = new Set(['']);
    const usedInDatabase = new Set(muestrasUsadasForLote(muestrasUsadas, lote).map((value) => String(value).trim()).filter(Boolean));
    const values = [];

    specialOptions.forEach(({ value }) => {
      const normalized = String(value || '').trim();
      if (!normalized || usedValues.has(normalized)) {
        return;
      }

      usedValues.add(normalized);
      values.push(normalized);
    });

    muestrasForLote(muestras, lote).forEach((numero) => {
      const value = String(numero || '').trim();
      if (!value || usedValues.has(value) || usedInDatabase.has(value)) {
        return;
      }

      usedValues.add(value);
      values.push(value);
    });

    return values.length ? values : [''];
  }

  function firstAvailableLaboratorioValue(select) {
    const firstEnabled = Array.from(select.options).find((option) => option.value && !option.disabled);
    return firstEnabled ? firstEnabled.value : '';
  }

  function fillLaboratorioOptions(select, muestras, lote, preferred, specialOptions = [], muestrasUsadas = {}) {
    const values = muestrasForLote(muestras, lote);
    const usedInDatabase = new Set(muestrasUsadasForLote(muestrasUsadas, lote).map((value) => String(value).trim()).filter(Boolean));
    select.innerHTML = '';

    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = values.length || specialOptions.length ? 'Seleccione' : 'Sin número';
    select.appendChild(empty);

    const usedValues = new Set(['']);
    specialOptions.forEach(({ value, label }) => {
      const option = document.createElement('option');
      option.value = value;
      option.textContent = label;
      option.selected = value === preferred;
      select.appendChild(option);
      usedValues.add(value);
    });

    values.forEach((numero) => {
      const value = String(numero);
      if (usedValues.has(value)) {
        return;
      }

      const option = document.createElement('option');
      option.value = value;
      option.textContent = value;
      option.disabled = usedInDatabase.has(value);
      option.selected = value === preferred;
      select.appendChild(option);
      usedValues.add(value);
    });

    const hasPreferred = preferred && Array.from(select.options).some((option) => option.value === preferred && !option.disabled);
    if (!hasPreferred) {
      select.value = firstAvailableLaboratorioValue(select);
    }
  }

  function laboratorioKey(row) {
    const lote = getLoteSelect(row)?.value || '';
    const numero = row.querySelector('select[name="numero_laboratorio[]"]')?.value || '';
    return lote && numero ? `${lote}||${numero}` : '';
  }

  function updateLaboratorioAvailability(tbody) {
    if (!tbody) {
      return;
    }

    const rows = dataRows(tbody);
    const selected = new Map();

    rows.forEach((row) => {
      const key = laboratorioKey(row);
      if (!key) {
        return;
      }
      selected.set(key, (selected.get(key) || 0) + 1);
    });

    rows.forEach((row) => {
      const loteSelect = getLoteSelect(row);
      const loteValue = loteSelect?.value || '';
      const labSelect = row.querySelector('select[name="numero_laboratorio[]"]');
      const current = labSelect?.value || '';

      if (!labSelect) {
        return;
      }

      let hasDuplicate = false;
      Array.from(labSelect.options).forEach((option) => {
        if (!option.value) {
          option.disabled = false;
          return;
        }

        const key = loteValue ? `${loteValue}||${option.value}` : '';
        const count = selected.get(key) || 0;
        const isCurrent = option.value === current;
        option.disabled = !isCurrent && count > 0;

        if (isCurrent && count > 1) {
          hasDuplicate = true;
        }
      });

      labSelect.setCustomValidity(hasDuplicate
        ? 'Este número de laboratorio ya fue seleccionado para este lote.'
        : '');
    });
  }

  function hasDuplicateLaboratorios(tbody) {
    if (!tbody) {
      return false;
    }

    const seen = new Set();

    for (const row of dataRows(tbody)) {
      const key = laboratorioKey(row);
      if (!key) {
        continue;
      }

      if (seen.has(key)) {
        return true;
      }

      seen.add(key);
    }

    return false;
  }

  function dataRows(tbody) {
    return tbody ? Array.from(tbody.querySelectorAll('tr.lab-data-row')) : [];
  }

  function lastDataRow(tbody) {
    const rows = dataRows(tbody);
    return rows[rows.length - 1] || null;
  }

  function loteGroupRows(tbody, groupId) {
    return dataRows(tbody).filter((row) => row.dataset.loteGroup === groupId);
  }

  function nextLote(lotes, current) {
    if (!lotes.length) {
      return '';
    }

    const index = lotes.indexOf(current);
    return lotes[index + 1] || lotes[0];
  }

  function reindexRows(tbody) {
    dataRows(tbody).forEach((row, index) => {
      row.querySelector('.lab-row-index').textContent = String(index + 1);
      row.querySelectorAll('[data-row-index]').forEach((input) => {
        input.dataset.rowIndex = String(index);
      });
    });
  }

  function cleanupOriginalLayout(formBody) {
    formBody.querySelectorAll('.field-group, .grid2').forEach((group) => {
      if (group.querySelector('.form-footer')) {
        return;
      }

      const controls = Array.from(group.querySelectorAll('input, select, textarea'));
      if (controls.length && controls.every((control) => control.disabled)) {
        group.classList.add('lab-original-field-hidden');
      }
    });

    formBody.querySelectorAll('h3').forEach((heading) => {
      const next = heading.nextElementSibling;
      if (next && next.classList.contains('lab-original-field-hidden')) {
        heading.classList.add('lab-original-field-hidden');
      }
    });
  }

  function calibrationPoints(table) {
    const pointInputs = Array.from(table.querySelectorAll('input[name="punto_curva[]"]'));
    const absorbanceInputs = Array.from(table.querySelectorAll('input[name="abs_curva[]"]'));

    return pointInputs.map((input, index) => ({
      x: Number.parseFloat(input.value),
      y: Number.parseFloat(absorbanceInputs[index]?.value || ''),
    })).filter((point) => Number.isFinite(point.x) && Number.isFinite(point.y));
  }

  function linearRegression(points) {
    const n = points.length;
    if (n < 2) {
      return null;
    }

    const sumX = points.reduce((sum, point) => sum + point.x, 0);
    const sumY = points.reduce((sum, point) => sum + point.y, 0);
    const sumXY = points.reduce((sum, point) => sum + point.x * point.y, 0);
    const sumX2 = points.reduce((sum, point) => sum + point.x * point.x, 0);
    const denominator = n * sumX2 - sumX * sumX;

    if (denominator === 0) {
      return null;
    }

    const slope = (n * sumXY - sumX * sumY) / denominator;
    const intercept = (sumY - slope * sumX) / n;
    const meanY = sumY / n;
    const total = points.reduce((sum, point) => sum + Math.pow(point.y - meanY, 2), 0);
    const residual = points.reduce((sum, point) => {
      const predicted = slope * point.x + intercept;
      return sum + Math.pow(point.y - predicted, 2);
    }, 0);
    const r2 = total === 0 ? 1 : 1 - residual / total;

    return { slope, intercept, r2 };
  }

  function paddedRange(values) {
    const min = Math.min(...values);
    const max = Math.max(...values);

    if (min === max) {
      const padding = Math.max(1, Math.abs(min) * 0.1);
      return { min: min - padding, max: max + padding };
    }

    const padding = (max - min) * 0.12;
    return { min: min - padding, max: max + padding };
  }

  function drawCalibrationChart(canvas, statsNode, points) {
    const rect = canvas.getBoundingClientRect();
    const width = Math.max(280, Math.round(rect.width || 360));
    const height = Math.max(200, Math.round(rect.height || 220));
    const ratio = window.devicePixelRatio || 1;

    canvas.width = width * ratio;
    canvas.height = height * ratio;

    const ctx = canvas.getContext('2d');
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.clearRect(0, 0, width, height);

    const styles = getComputedStyle(document.documentElement);
    const textColor = styles.getPropertyValue('--text-mute').trim() || '#61744e';
    const axisColor = styles.getPropertyValue('--green-200').trim() || '#a8c97a';
    const pointColor = styles.getPropertyValue('--green-600').trim() || '#24480a';
    const trendColor = styles.getPropertyValue('--green-400').trim() || '#97c459';
    const bgColor = styles.getPropertyValue('--green-50').trim() || '#f8fbf3';

    ctx.fillStyle = bgColor;
    ctx.fillRect(0, 0, width, height);

    const padding = { top: 20, right: 18, bottom: 34, left: 42 };
    const chartWidth = width - padding.left - padding.right;
    const chartHeight = height - padding.top - padding.bottom;
    const plotLeft = padding.left;
    const plotTop = padding.top;
    const plotBottom = padding.top + chartHeight;

    ctx.strokeStyle = axisColor;
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(plotLeft, plotTop);
    ctx.lineTo(plotLeft, plotBottom);
    ctx.lineTo(plotLeft + chartWidth, plotBottom);
    ctx.stroke();

    ctx.fillStyle = textColor;
    ctx.font = '11px sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('Punto curva', plotLeft + chartWidth / 2, height - 8);
    ctx.save();
    ctx.translate(12, plotTop + chartHeight / 2);
    ctx.rotate(-Math.PI / 2);
    ctx.fillText('Absorbancia', 0, 0);
    ctx.restore();

    if (points.length < 2) {
      statsNode.innerHTML = '<span>Ingrese al menos 2 absorbancias para graficar.</span>';
      ctx.fillStyle = textColor;
      ctx.textAlign = 'center';
      ctx.fillText('Sin datos suficientes', plotLeft + chartWidth / 2, plotTop + chartHeight / 2);
      return;
    }

    const regression = linearRegression(points);
    if (!regression) {
      statsNode.innerHTML = '<span>No se puede calcular la linea con estos puntos.</span>';
      return;
    }

    const xRange = paddedRange(points.map((point) => point.x));
    const predicted = [
      { x: xRange.min, y: regression.slope * xRange.min + regression.intercept },
      { x: xRange.max, y: regression.slope * xRange.max + regression.intercept },
    ];
    const yRange = paddedRange(points.map((point) => point.y).concat(predicted.map((point) => point.y)));
    const toX = (value) => plotLeft + ((value - xRange.min) / (xRange.max - xRange.min)) * chartWidth;
    const toY = (value) => plotBottom - ((value - yRange.min) / (yRange.max - yRange.min)) * chartHeight;

    ctx.strokeStyle = 'rgba(168, 201, 122, .55)';
    for (let index = 1; index <= 3; index += 1) {
      const x = plotLeft + (chartWidth / 4) * index;
      const y = plotTop + (chartHeight / 4) * index;
      ctx.beginPath();
      ctx.moveTo(x, plotTop);
      ctx.lineTo(x, plotBottom);
      ctx.moveTo(plotLeft, y);
      ctx.lineTo(plotLeft + chartWidth, y);
      ctx.stroke();
    }

    ctx.strokeStyle = trendColor;
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(toX(predicted[0].x), toY(predicted[0].y));
    ctx.lineTo(toX(predicted[1].x), toY(predicted[1].y));
    ctx.stroke();

    ctx.fillStyle = pointColor;
    points.forEach((point) => {
      ctx.beginPath();
      ctx.arc(toX(point.x), toY(point.y), 4, 0, Math.PI * 2);
      ctx.fill();
    });

    const sign = regression.intercept >= 0 ? '+' : '-';
    statsNode.innerHTML = `
      <span><strong>Ecuacion:</strong> y = ${regression.slope.toFixed(4)}x ${sign} ${Math.abs(regression.intercept).toFixed(4)}</span>
      <span><strong>R2:</strong> ${(regression.r2 * 100).toFixed(2)}%</span>
    `;
  }

  function attachCalibrationChart(table) {
    if (table.dataset.calibrationChartReady === '1') {
      return;
    }

    const wrapper = table.closest('.table-wrap');
    if (!wrapper) {
      return;
    }

    const layout = document.createElement('div');
    layout.className = 'calibration-layout';
    Object.assign(layout.style, {
      display: 'flex',
      alignItems: 'stretch',
      flexWrap: 'wrap',
      gap: '16px',
      marginBottom: '1rem',
    });
    wrapper.insertAdjacentElement('beforebegin', layout);
    layout.appendChild(wrapper);

    const panel = document.createElement('div');
    panel.className = 'calibration-chart-panel';
    Object.assign(panel.style, {
      flex: '1 1 340px',
      minWidth: '280px',
      maxWidth: '560px',
      padding: '10px',
      background: 'var(--white)',
      border: '1px solid var(--border)',
      borderRadius: '8px',
    });
    panel.innerHTML = `
      <canvas class="calibration-chart-canvas" aria-label="Grafica de curva de calibracion"></canvas>
      <div class="calibration-chart-stats"></div>
    `;
    layout.appendChild(panel);

    const canvas = panel.querySelector('canvas');
    const statsNode = panel.querySelector('.calibration-chart-stats');
    Object.assign(canvas.style, {
      display: 'block',
      width: '100%',
      height: '220px',
    });
    Object.assign(statsNode.style, {
      display: 'flex',
      flexWrap: 'wrap',
      gap: '8px',
      marginTop: '8px',
      color: 'var(--text-mute)',
      fontSize: '11px',
    });
    const update = () => drawCalibrationChart(canvas, statsNode, calibrationPoints(table));

    table.querySelectorAll('input[name="punto_curva[]"], input[name="abs_curva[]"]').forEach((input) => {
      input.addEventListener('input', update);
    });
    window.addEventListener('resize', update);

    table.dataset.calibrationChartReady = '1';
    update();
  }

  function compactCalibrationTables(root = document) {
    root.querySelectorAll('table').forEach((table) => {
      const hasCurveInputs = table.querySelector('input[name="punto_curva[]"], input[name="abs_curva[]"]');
      if (!hasCurveInputs && !table.classList.contains('calibration-table')) {
        return;
      }

      const wrapper = table.closest('.table-wrap');
      table.classList.add('calibration-table');

      Object.assign(table.style, {
        width: '100%',
        minWidth: '0',
        tableLayout: 'fixed',
        fontSize: '11px',
      });

      if (wrapper) {
        wrapper.classList.add('calibration-table-wrap');
        Object.assign(wrapper.style, {
          alignSelf: 'flex-start',
          width: '280px',
          maxWidth: '100%',
          marginBottom: '1rem',
        });
      }

      table.querySelectorAll('th').forEach((cell) => {
        Object.assign(cell.style, {
          padding: '5px 6px',
          textAlign: 'center',
        });
      });

      table.querySelectorAll('td').forEach((cell) => {
        Object.assign(cell.style, {
          padding: '4px 5px',
          textAlign: 'center',
        });
      });

      table.querySelectorAll('input, select, textarea').forEach((input) => {
        Object.assign(input.style, {
          width: '78px',
          minWidth: '0',
          minHeight: '28px',
          padding: '3px 6px',
          fontSize: '11px',
        });
      });

      if (hasCurveInputs) {
        attachCalibrationChart(table);
      }
    });
  }

  function createRow({
    columnDefinitions,
    lotes,
    muestras,
    muestrasUsadas,
    loteLabels,
    useSolicitudes,
    selectedLote,
    selectedLaboratorio,
    rowIndex,
    groupId,
    specialOptions,
    rowDefinitions,
    fixedLaboratorioLabel,
    fixedLaboratorioValue,
    onLoteChange,
  }) {
    const row = document.createElement('tr');
    row.className = 'lab-data-row';
    row.dataset.loteGroup = groupId || '';

    const indexCell = document.createElement('td');
    indexCell.className = 'lab-row-index';
    indexCell.textContent = String(rowIndex + 1);
    row.appendChild(indexCell);

    const loteCell = document.createElement('td');
    const loteSelect = document.createElement('select');
    loteSelect.name = useSolicitudes ? 'id_solicitud[]' : 'lote[]';
    loteSelect.required = true;
    loteSelect.dataset.labLoteSelect = '1';
    fillLoteOptions(loteSelect, lotes, selectedLote, loteLabels || {});
    loteCell.appendChild(loteSelect);

    let loteHiddenInput = null;
    if (useSolicitudes) {
      loteHiddenInput = document.createElement('input');
      loteHiddenInput.type = 'hidden';
      loteHiddenInput.name = 'lote[]';
      loteHiddenInput.value = loteLabels?.[selectedLote] || '';
      loteHiddenInput.dataset.labLoteMirror = '1';
      loteCell.appendChild(loteHiddenInput);
    }
    row.appendChild(loteCell);

    const labCell = document.createElement('td');
    let labSelect = null;
    if (fixedLaboratorioLabel) {
      const badge = document.createElement('span');
      badge.textContent = fixedLaboratorioLabel;
      badge.className = 'lab-fixed-row-label';
      Object.assign(badge.style, {
        display: 'inline-flex',
        alignItems: 'center',
        minHeight: '28px',
        fontWeight: '600',
      });

      const hiddenInput = document.createElement('input');
      hiddenInput.type = 'hidden';
      hiddenInput.name = 'numero_laboratorio[]';
      hiddenInput.value = fixedLaboratorioValue || '';

      labCell.appendChild(badge);
      labCell.appendChild(hiddenInput);
    } else {
      labSelect = document.createElement('select');
      labSelect.name = 'numero_laboratorio[]';
      fillLaboratorioOptions(labSelect, muestras, loteSelect.value, selectedLaboratorio || '', specialOptions, muestrasUsadas);
      labCell.appendChild(labSelect);
    }
    row.appendChild(labCell);

    loteSelect.addEventListener('change', () => {
      if (loteHiddenInput) {
        loteHiddenInput.value = loteLabels?.[loteSelect.value] || '';
      }

      if (typeof onLoteChange === 'function') {
        onLoteChange(row, loteSelect.value);
        return;
      }

      if (labSelect) {
        fillLaboratorioOptions(labSelect, muestras, loteSelect.value, '', specialOptions, muestrasUsadas);
      }
      updateLaboratorioAvailability(row.parentElement);
    });

    if (labSelect) {
      labSelect.addEventListener('change', () => {
        updateLaboratorioAvailability(row.parentElement);
      });
    }

    const activeDefinitions = Array.isArray(rowDefinitions) && rowDefinitions.length
      ? rowDefinitions
      : columnDefinitions;
    const isSharedRow = Boolean(fixedLaboratorioLabel);

    columnDefinitions.forEach((definition, index) => {
      const cell = document.createElement('td');
      let rowDefinition = null;
      if (isSharedRow) {
        rowDefinition = activeDefinitions[index] || null;
      } else {
        rowDefinition = activeDefinitions.find((item) => item.name === definition.name);
      }
      if (rowDefinition) {
        cell.appendChild(buildDataInput(rowDefinition, rowIndex));
        if (isSharedRow && rowDefinition.name !== definition.name) {
          cell.appendChild(buildPlaceholderInput(definition.name, rowIndex));
        }
      } else {
        cell.appendChild(buildPlaceholderInput(definition.name, rowIndex));
      }
      row.appendChild(cell);
    });

    const actionCell = document.createElement('td');
    const removeButton = document.createElement('button');
    removeButton.type = 'button';
    removeButton.className = 'lab-table-icon-button';
    removeButton.textContent = 'X';
    removeButton.title = 'Quitar fila';
    removeButton.addEventListener('click', () => {
      const tbody = row.parentElement;
      if (!tbody || dataRows(tbody).length <= 1) {
        return;
      }
      row.remove();
      reindexRows(tbody);
      updateLaboratorioAvailability(tbody);
    });
    actionCell.appendChild(removeButton);
    row.appendChild(actionCell);

    return row;
  }

  function initForm(form) {
    if (form.dataset.labTableReady === '1') {
      return;
    }

    const footer = form.querySelector('.form-footer');
    const formBody = form.querySelector('.form-body') || form;
    const config = parseConfig(form);
    const captureState = buildSelectionState(config);
    const lotes = captureState.lotes;
    const useSharedRows = form.dataset.labSharedRows === '1';
    const primaryControls = Array.from(formBody.querySelectorAll('input, select, textarea'))
      .filter((control) => isPrimaryControl(control, footer));
    const controls = primaryControls.filter((control) => !isSharedAnalysisControl(control));
    const sharedControls = primaryControls.filter(isSharedAnalysisControl);

    if (!controls.length && !sharedControls.length) {
      return;
    }

    const definitions = controls.map((control) => ({
      name: control.name,
      label: getControlLabel(control),
      template: control.cloneNode(true),
    }));
    const sharedDefinitions = sharedControls.map((control) => ({
      name: control.name,
      label: getControlLabel(control),
      template: control.cloneNode(true),
    }));
    const laboratorioSpecialOptions = [];
    const fixedSharedRows = useSharedRows ? sharedRowDefinitions(sharedDefinitions) : [];
    const columnDefinitions = definitions;

    controls.forEach(hideOriginal);
    if (useSharedRows) {
      sharedControls.forEach(hideOriginal);
    }
    cleanupOriginalLayout(formBody);

    const wrapper = document.createElement('section');
    wrapper.className = 'lab-table-panel';
    wrapper.innerHTML = `
      <div class="lab-table-toolbar">
        <div class="section-title">Datos de análisis por muestra</div>
        <div class="lab-table-actions">
          <button type="button" class="lab-table-button" data-add-row>+ Agregar fila</button>
          <button type="button" class="lab-table-button" data-add-lote>+ Agregar lote</button>
        </div>
      </div>
      <div class="table-wrap lab-entry-table-wrap">
        <table class="lab-entry-table">
          <thead>
            <tr></tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    `;

    const header = wrapper.querySelector('thead tr');
    ['#', 'Lote', 'Numero de laboratorio', ...columnDefinitions.map((definition) => definition.label), ''].forEach((label) => {
      const th = document.createElement('th');
      th.textContent = label;
      header.appendChild(th);
    })
    const tbody = wrapper.querySelector('tbody');
    let loteGroupCounter = 0;

    const buildRowsForLote = (selectedLote, groupId) => {
      const rows = [];

      fixedSharedRows.forEach((sharedRow) => {
        rows.push(createRow({
          columnDefinitions,
          lotes,
          muestras: captureState.muestras,
          muestrasUsadas: captureState.muestrasUsadas,
          loteLabels: captureState.loteLabels,
          useSolicitudes: captureState.useSolicitudes,
          selectedLote,
          selectedLaboratorio: '',
          rowIndex: dataRows(tbody).length + rows.length,
          groupId,
          specialOptions: laboratorioSpecialOptions,
          rowDefinitions: [sharedRow.definition],
          fixedLaboratorioLabel: sharedRow.label,
          fixedLaboratorioValue: sharedRow.value,
          onLoteChange: replaceLoteGroup,
        }));
      });

      laboratorioValues(
        captureState.muestras,
        selectedLote,
        laboratorioSpecialOptions,
        captureState.muestrasUsadas
      ).forEach((numeroLaboratorio) => {
        rows.push(createRow({
          columnDefinitions,
          lotes,
          muestras: captureState.muestras,
          muestrasUsadas: captureState.muestrasUsadas,
          loteLabels: captureState.loteLabels,
          useSolicitudes: captureState.useSolicitudes,
          selectedLote,
          selectedLaboratorio: numeroLaboratorio,
          rowIndex: dataRows(tbody).length + rows.length,
          groupId,
          specialOptions: laboratorioSpecialOptions,
          rowDefinitions: definitions,
          onLoteChange: replaceLoteGroup,
        }));
      });

      return rows;
    };

    const appendLoteGroup = (selectedLote, existingGroupId) => {
      const groupId = existingGroupId || String(loteGroupCounter);
      if (!existingGroupId) {
        loteGroupCounter += 1;
      }

      const rows = buildRowsForLote(selectedLote, groupId);
      rows.forEach((row) => tbody.appendChild(row));
      reindexRows(tbody);
      updateLaboratorioAvailability(tbody);
      return groupId;
    };

    function replaceLoteGroup(row, selectedLote) {
      const groupId = row.dataset.loteGroup || String(loteGroupCounter);
      const allRows = dataRows(tbody);
      const groupRows = loteGroupRows(tbody, groupId);
      if (!groupRows.length) {
        appendLoteGroup(selectedLote, groupId);
        return;
      }

      const lastGroupIndex = Math.max(...groupRows.map((groupRow) => allRows.indexOf(groupRow)));
      const nextRow = allRows.slice(lastGroupIndex + 1)
        .find((candidate) => candidate.dataset.loteGroup !== groupId) || null;
      const fragment = document.createDocumentFragment();

      buildRowsForLote(selectedLote, groupId).forEach((newRow) => fragment.appendChild(newRow));
      groupRows.forEach((groupRow) => groupRow.remove());
      tbody.insertBefore(fragment, nextRow);
      reindexRows(tbody);
      updateLaboratorioAvailability(tbody);
    }

    appendLoteGroup(resolveInitialLoteKey(config, captureState) || lotes[0] || '');

    wrapper.querySelector('[data-add-row]').addEventListener('click', () => {
      const lastRow = lastDataRow(tbody);
      const lastLote = getLoteSelect(lastRow)?.value || resolveInitialLoteKey(config, captureState) || '';
      tbody.appendChild(createRow({
        columnDefinitions,
        lotes,
        muestras: captureState.muestras,
        muestrasUsadas: captureState.muestrasUsadas,
        loteLabels: captureState.loteLabels,
        useSolicitudes: captureState.useSolicitudes,
        selectedLote: lastLote,
        selectedLaboratorio: '',
        rowIndex: dataRows(tbody).length,
        groupId: lastRow?.dataset.loteGroup || '',
        specialOptions: laboratorioSpecialOptions,
        rowDefinitions: definitions,
        onLoteChange: replaceLoteGroup,
      }));
      reindexRows(tbody);
      updateLaboratorioAvailability(tbody);
    });

    wrapper.querySelector('[data-add-lote]').addEventListener('click', () => {
      const lastLote = getLoteSelect(lastDataRow(tbody))?.value || resolveInitialLoteKey(config, captureState) || '';
      appendLoteGroup(nextLote(lotes, lastLote));
    });

    form.addEventListener('submit', (event) => {
      updateLaboratorioAvailability(tbody);
      if (hasDuplicateLaboratorios(tbody)) {
        event.preventDefault();
        form.reportValidity();
      }
    });

    if (footer) {
      footer.insertAdjacentElement('afterend', wrapper);
    } else {
      formBody.appendChild(wrapper);
    }

    form.dataset.labTableReady = '1';
  }

  document.addEventListener('DOMContentLoaded', () => {
    compactCalibrationTables();
    document.querySelectorAll('form').forEach(initForm);
  });
})();

