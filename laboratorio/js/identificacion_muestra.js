(function () {
  'use strict';

  const page = document.querySelector('[data-ident-page]');
  if (!page) return;

  const modeButtons = Array.from(page.querySelectorAll('[data-ident-mode]'));
  const modePanels = Array.from(page.querySelectorAll('[data-ident-panel]'));
  const manualInput = page.querySelector('input[name="muestra"]');

  function setMode(mode) {
    modeButtons.forEach(function (button) {
      const active = button.dataset.identMode === mode;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    modePanels.forEach(function (panel) {
      panel.hidden = panel.dataset.identPanel !== mode;
    });
  }

  modeButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      setMode(button.dataset.identMode || 'manual');
    });
  });

  page.querySelectorAll('[data-focus-reader]').forEach(function (button) {
    button.addEventListener('click', function () {
      setMode('manual');
      if (manualInput) {
        manualInput.focus();
        manualInput.select();
      }
    });
  });

  const requestSelect = page.querySelector('[data-request-select]');
  const requestType = page.querySelector('[data-request-type]');
  if (requestSelect && requestType) {
    requestSelect.addEventListener('change', function () {
      const selected = requestSelect.options[requestSelect.selectedIndex];
      requestType.value = selected && selected.dataset.type ? selected.dataset.type : 'Seleccione un lote';
    });
  }

  const tabButtons = Array.from(page.querySelectorAll('[data-ident-tab]'));
  const tabPanels = Array.from(page.querySelectorAll('[data-ident-tab-panel]'));
  tabButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      const target = button.dataset.identTab;
      tabButtons.forEach(function (tab) {
        const active = tab === button;
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      tabPanels.forEach(function (panel) {
        const active = panel.dataset.identTabPanel === target;
        panel.classList.toggle('is-active', active);
        panel.hidden = !active;
      });
    });
  });

  const baseFilter = page.querySelector('[data-base-filter]');
  const baseTypeFilter = page.querySelector('[data-base-type-filter]');
  const baseBody = page.querySelector('[data-base-body]');
  const baseCount = page.querySelector('[data-base-count]');
  const baseRows = Array.from(page.querySelectorAll('[data-base-row]'));
  let dynamicEmpty = null;

  function filterBaseRows() {
    if (!baseBody) return;
    const value = baseFilter ? baseFilter.value.trim().toLocaleLowerCase('es') : '';
    const type = baseTypeFilter ? baseTypeFilter.value : '';
    let visible = 0;
    baseRows.forEach(function (row) {
      const matchesText = !value || (row.dataset.search || '').includes(value);
      const matchesType = !type || row.dataset.type === type;
      const match = matchesText && matchesType;
      row.hidden = !match;
      if (match) visible += 1;
    });

    if (dynamicEmpty) dynamicEmpty.remove();
    dynamicEmpty = null;
    if (baseRows.length && visible === 0) {
      dynamicEmpty = document.createElement('tr');
      dynamicEmpty.className = 'ident-filter-empty';
      dynamicEmpty.innerHTML = '<td colspan="5">Sin resultados para estos filtros.</td>';
      baseBody.appendChild(dynamicEmpty);
    }
    if (baseCount) {
      const visibleLabel = visible + (visible === 1 ? ' registrada' : ' registradas');
      const totalLabel = baseRows.length + (baseRows.length === 1 ? ' registrada' : ' registradas');
      baseCount.textContent = (value || type) ? visibleLabel + ' de ' + totalLabel : totalLabel;
    }
  }

  if (baseFilter) baseFilter.addEventListener('input', filterBaseRows);
  if (baseTypeFilter) baseTypeFilter.addEventListener('change', filterBaseRows);
  if (baseBody) {
    filterBaseRows();
  }

  const modal = document.querySelector('[data-scanner-modal]');
  const video = modal ? modal.querySelector('[data-scanner-video]') : null;
  const canvas = modal ? modal.querySelector('[data-scanner-canvas]') : null;
  const status = modal ? modal.querySelector('[data-scanner-status]') : null;
  const scannerForm = document.querySelector('[data-scanner-submit]');
  const scannerValue = scannerForm ? scannerForm.querySelector('[data-scanner-value]') : null;
  let stream = null;
  let animationFrame = null;

  function setScannerStatus(message, warning) {
    if (!status) return;
    status.classList.toggle('ident-alert--warn', Boolean(warning));
    status.classList.toggle('ident-alert--info', !warning);
    const content = status.querySelector('div');
    if (content) content.textContent = message;
  }

  function closeScanner() {
    if (animationFrame) cancelAnimationFrame(animationFrame);
    animationFrame = null;
    if (stream) {
      stream.getTracks().forEach(function (track) { track.stop(); });
      stream = null;
    }
    if (video) video.srcObject = null;
    if (modal) modal.hidden = true;
  }

  function submitScannedValue(value) {
    value = String(value || '').trim();
    if (!value || !scannerForm || !scannerValue) return;
    closeScanner();
    scannerValue.value = value;
    scannerForm.submit();
  }

  async function openScanner() {
    if (!modal || !video || !canvas) return;
    modal.hidden = false;
    setScannerStatus('Solicitando acceso a la cámara…', false);

    if (!('BarcodeDetector' in window)) {
      setScannerStatus('Este navegador no admite lectura nativa de códigos. Utiliza el ingreso manual o un lector conectado.', true);
      return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setScannerStatus('No hay una cámara disponible. Utiliza el ingreso manual o un lector conectado.', true);
      return;
    }

    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
      video.srcObject = stream;
      await video.play();
      setScannerStatus('Apunta la cámara al código QR o de barras…', false);

      let detector;
      try {
        detector = new BarcodeDetector({ formats: ['qr_code', 'code_128', 'code_39', 'ean_13', 'ean_8', 'upc_a', 'upc_e'] });
      } catch (error) {
        detector = new BarcodeDetector();
      }

      const context = canvas.getContext('2d', { willReadFrequently: true });
      const scan = async function () {
        if (!stream) return;
        if (video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
          canvas.width = video.videoWidth;
          canvas.height = video.videoHeight;
          context.drawImage(video, 0, 0, canvas.width, canvas.height);
          try {
            const codes = await detector.detect(canvas);
            if (codes.length) {
              submitScannedValue(codes[0].rawValue);
              return;
            }
          } catch (error) {
            setScannerStatus('No se pudo leer el código. Mantén la cámara estable o usa el ingreso manual.', true);
          }
        }
        animationFrame = requestAnimationFrame(scan);
      };
      scan();
    } catch (error) {
      setScannerStatus('No se pudo acceder a la cámara. Revisa el permiso del navegador o usa el ingreso manual.', true);
    }
  }

  page.querySelectorAll('[data-open-scanner]').forEach(function (button) {
    button.addEventListener('click', openScanner);
  });
  if (modal) {
    modal.querySelectorAll('[data-close-scanner]').forEach(function (button) {
      button.addEventListener('click', closeScanner);
    });
    modal.addEventListener('click', function (event) {
      if (event.target === modal) closeScanner();
    });
  }
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && modal && !modal.hidden) closeScanner();
  });
}());
