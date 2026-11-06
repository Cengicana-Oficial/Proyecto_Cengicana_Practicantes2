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
  let scannerBatch = false, scannerGeneration = 0, scannerTrigger = null;
  let animationFrame = null;

  function setScannerStatus(message, warning) {
    if (!status) return;
    status.classList.toggle('ident-alert--warn', Boolean(warning));
    status.classList.toggle('ident-alert--info', !warning);
    const content = status.querySelector('div');
    if (content) content.textContent = message;
  }

  function closeScanner() {
    scannerGeneration++;
    if (animationFrame) cancelAnimationFrame(animationFrame);
    animationFrame = null;
    if (stream) {
      stream.getTracks().forEach(function (track) { track.stop(); });
      stream = null;
    }
    if (video) video.srcObject = null;
    if (modal) modal.hidden = true;
    scannerTrigger?.focus();
  }

  function submitScannedValue(value) {
    value = String(value || '').trim();
    if (!value || !scannerForm || !scannerValue) return;
    closeScanner();
    if (scannerBatch) {
      page.dispatchEvent(new CustomEvent("ident:lectura", { detail: value }));
      return;
    }
    scannerValue.value = value;
    scannerForm.submit();
  }

  async function openScanner(event) {
    scannerBatch = Boolean(event?.currentTarget?.hasAttribute("data-batch-camera"));
    scannerTrigger = event?.currentTarget || null;
    const generation = ++scannerGeneration;
    if (!modal || !video || !canvas) return;
    if (stream) { stream.getTracks().forEach(track => track.stop()); stream = null; }
    if (animationFrame) cancelAnimationFrame(animationFrame);
    animationFrame = null;
    modal.hidden = false;
    modal.querySelector("[data-close-scanner]")?.focus();
    setScannerStatus('Solicitando acceso a la cámara…', false);

    if (window.isSecureContext === false) {
      setScannerStatus('Para habilitar la cámara, abre esta página con HTTPS o desde localhost. Una dirección IP con HTTP no permite usar la cámara.', true);
      return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setScannerStatus('Este navegador no permite solicitar la cámara. Abre la página en un navegador actualizado con HTTPS o localhost.', true);
      return;
    }

    try {
      let acquired;
      try {
        acquired = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' } } });
      } catch (error) {
        if (generation !== scannerGeneration || modal.hidden) return;
        if (error.name !== 'OverconstrainedError') throw error;
        acquired = await navigator.mediaDevices.getUserMedia({ audio: false, video: true });
      }
      if (generation !== scannerGeneration || modal.hidden) {
        acquired.getTracks().forEach(track => track.stop());
        return;
      }
      stream = acquired;
      video.srcObject = stream;
      await video.play();
      if (generation !== scannerGeneration || modal.hidden) return;
      let detector = null;
      if (typeof window.BarcodeDetector === 'function') {
        try {
          const requested = ['qr_code', 'code_128', 'code_39', 'ean_13', 'ean_8', 'upc_a', 'upc_e'];
          const supported = typeof window.BarcodeDetector.getSupportedFormats === 'function'
            ? await window.BarcodeDetector.getSupportedFormats() : requested;
          const formats = requested.filter(format => supported.includes(format));
          if (formats.length) detector = new window.BarcodeDetector({ formats });
        } catch (error) { /* El lector QR local funciona sin BarcodeDetector. */ }
      }
      if (generation !== scannerGeneration || modal.hidden) return;
      const qrAvailable = typeof window.jsQR === 'function';
      if (!detector && !qrAvailable) throw new Error('ScannerUnavailable');
      setScannerStatus(detector ? 'Apunta la cámara al código QR o de barras…'
        : 'Cámara activa. Apunta al código QR; para códigos de barras utiliza un lector conectado.', false);

      const context = canvas.getContext('2d', { willReadFrequently: true });
      if (!context) throw new Error('ScannerUnavailable');
      const scan = async function () {
        if (!stream || generation !== scannerGeneration) return;
        if (video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA && video.videoWidth && video.videoHeight) {
          const scale = Math.min(1, 1280 / video.videoWidth);
          canvas.width = Math.round(video.videoWidth * scale);
          canvas.height = Math.round(video.videoHeight * scale);
          context.drawImage(video, 0, 0, canvas.width, canvas.height);
          try {
            let codes = [];
            if (detector) {
              try { codes = await detector.detect(canvas); }
              catch (error) { if (!qrAvailable) throw error; detector = null; setScannerStatus('Apunta al código QR; para códigos de barras utiliza un lector conectado.', false); }
            }
            if (!codes.length && qrAvailable) {
              const frame = context.getImageData(0, 0, canvas.width, canvas.height);
              const qr = window.jsQR(frame.data, frame.width, frame.height, { inversionAttempts: 'attemptBoth' });
              if (qr?.data) codes = [{ rawValue: qr.data }];
            }
            if (!stream || generation !== scannerGeneration) return;
            if (codes.length) {
              submitScannedValue(codes[0].rawValue);
              return;
            }
          } catch (error) {
            setScannerStatus('No se pudo leer el código. Mantén la cámara estable o usa el ingreso manual.', true);
          }
        }
        if (stream && generation === scannerGeneration) animationFrame = requestAnimationFrame(scan);
      };
      scan();
    } catch (error) {
      if (generation !== scannerGeneration) return;
      if (stream) { stream.getTracks().forEach(track => track.stop()); stream = null; }
      video.srcObject = null;
      const messages = {
        NotAllowedError: 'El permiso de cámara está bloqueado. Permite la cámara en la configuración de este sitio y vuelve a intentarlo.',
        SecurityError: 'El navegador bloqueó la cámara. Revisa los permisos de este sitio.',
        NotFoundError: 'No se encontró una cámara conectada. Conecta una cámara y vuelve a intentarlo.',
        NotReadableError: 'No se puede abrir la cámara. Cierra otras aplicaciones que la estén usando y vuelve a intentarlo.',
        OverconstrainedError: 'La cámara no admite la configuración solicitada. Prueba con otra cámara.',
      };
      setScannerStatus(error.message === 'ScannerUnavailable' ? 'No se pudo iniciar el lector de códigos. Recarga la página y vuelve a intentarlo.'
        : messages[error.name] || 'No se pudo acceder a la cámara. Revisa el permiso del navegador o usa el ingreso manual.', true);
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
  window.addEventListener("pagehide", closeScanner);
}());
