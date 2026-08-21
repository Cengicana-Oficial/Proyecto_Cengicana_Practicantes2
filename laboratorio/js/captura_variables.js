(function () {
  'use strict';

  const page = document.querySelector('[data-capture-page]');
  const frame = document.querySelector('[data-capture-frame]');
  const frameState = document.querySelector('[data-frame-state]');
  const modeButtons = Array.from(document.querySelectorAll('[data-capture-mode]'));
  const submitButtons = Array.from(document.querySelectorAll('[data-capture-save], [data-capture-submit]'));
  let mode = 'grid';
  let resizeObserver = null;

  if (!page) return;

  function frameDocument() {
    if (!frame) return null;
    try {
      return frame.contentDocument || frame.contentWindow.document;
    } catch (error) {
      return null;
    }
  }

  function setCellLabels(doc) {
    doc.querySelectorAll('.lab-entry-table').forEach(function (table) {
      const labels = Array.from(table.querySelectorAll('thead th')).map(function (cell) {
        return cell.textContent.trim();
      });
      table.querySelectorAll('tbody tr').forEach(function (row) {
        Array.from(row.children).forEach(function (cell, index) {
          cell.dataset.captureLabel = labels[index] || 'Variable';
        });
      });
    });
  }

  function resizeFrame() {
    const doc = frameDocument();
    if (!doc) return;
    const height = Math.max(
      doc.documentElement ? doc.documentElement.scrollHeight : 0,
      doc.body ? doc.body.scrollHeight : 0,
      620
    );
    frame.style.height = Math.min(height + 8, 1800) + 'px';
  }

  function applyMode() {
    const doc = frameDocument();
    page.classList.toggle('is-tablet', mode === 'tablet');
    modeButtons.forEach(function (button) {
      const active = button.dataset.captureMode === mode;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });

    if (!doc || !doc.body) return;
    doc.body.classList.add('lab-embedded-capture');
    doc.body.classList.toggle('lab-capture-tablet-mode', mode === 'tablet');
    setCellLabels(doc);
    window.setTimeout(resizeFrame, 40);
  }

  function setBusy(busy) {
    submitButtons.forEach(function (button) {
      button.disabled = busy || button.hasAttribute('data-capture-disabled');
    });
  }

  function submitEmbeddedForm() {
    const doc = frameDocument();
    const form = doc ? doc.querySelector('form') : null;
    if (!form) return;

    setBusy(true);
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
    window.setTimeout(function () { setBusy(false); }, 1800);
  }

  modeButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      mode = button.dataset.captureMode === 'tablet' ? 'tablet' : 'grid';
      applyMode();
    });
  });

  submitButtons.forEach(function (button) {
    button.addEventListener('click', submitEmbeddedForm);
  });

  applyMode();
  if (!frame) {
    setBusy(false);
    return;
  }

  frame.addEventListener('load', function () {
    if (frameState) frameState.hidden = true;
    setBusy(false);
    applyMode();

    const doc = frameDocument();
    if (!doc || !doc.body || !window.ResizeObserver) {
      resizeFrame();
      return;
    }

    if (resizeObserver) resizeObserver.disconnect();
    resizeObserver = new ResizeObserver(function () {
      setCellLabels(doc);
      resizeFrame();
    });
    resizeObserver.observe(doc.body);
  });
}());
