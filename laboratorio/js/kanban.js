/**
 * kanban.js
 * Drag & drop del tablero de planificacion (view/kanban_view.php).
 * Al soltar una tarjeta en otra columna llama a
 * controllers/kanban_controller.php para persistir el cambio de estado.
 */
(function () {
  var board = document.getElementById('kanbanBoard');
  if (!board) {
    return;
  }

  var endpoint = board.dataset.endpoint || '../controllers/kanban_controller.php';
  var draggingCard = null;

  function showToast(msg, isError) {
    var toast = document.getElementById('kanbanToast');
    if (!toast) {
      return;
    }
    toast.textContent = msg;
    toast.style.borderLeft = '4px solid ' + (isError ? '#c94f4f' : '#73BC25');
    toast.classList.add('show');
    window.clearTimeout(toast._timer);
    toast._timer = window.setTimeout(function () {
      toast.classList.remove('show');
    }, 2600);
  }

  board.addEventListener('dragstart', function (e) {
    var card = e.target.closest('.cengi-kanban-card');
    if (!card) {
      return;
    }
    draggingCard = card;
    card.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', card.dataset.idFormulario || '');
  });

  board.addEventListener('dragend', function (e) {
    var card = e.target.closest('.cengi-kanban-card');
    if (card) {
      card.classList.remove('dragging');
    }
    draggingCard = null;
  });

  board.querySelectorAll('.cengi-kanban-col').forEach(function (col) {
    col.addEventListener('dragover', function (e) {
      e.preventDefault();
      col.classList.add('drag-over');
    });
    col.addEventListener('dragleave', function () {
      col.classList.remove('drag-over');
    });
    col.addEventListener('drop', function (e) {
      e.preventDefault();
      col.classList.remove('drag-over');

      if (!draggingCard) {
        return;
      }

      var idFormulario = draggingCard.dataset.idFormulario;
      var estadoDestino = col.dataset.estado;
      var estadoOrigen = draggingCard.closest('.cengi-kanban-col')
        ? draggingCard.closest('.cengi-kanban-col').dataset.estado
        : null;

      if (!idFormulario || !estadoDestino || estadoDestino === estadoOrigen) {
        return;
      }

      col.querySelector('.cengi-kanban-col-body').appendChild(draggingCard);

      fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id_formulario: idFormulario, estado: estadoDestino }),
      })
        .then(function (res) { return res.json().then(function (data) { return { status: res.status, data: data }; }); })
        .then(function (result) {
          if (!result.data || !result.data.ok) {
            throw new Error((result.data && result.data.error) || 'Error al mover');
          }
          showToast('Analisis movido a "' + estadoDestino + '"');
        })
        .catch(function () {
          showToast('No se pudo guardar el cambio, recargue la pagina.', true);
        });
    });
  });
})();
