/**
 * Reproductor de grabaciones del aula (aula_video_html() en aula_vista.php): la miniatura se cambia por el
 * reproductor al pulsarla y "Ampliar" abre el video en una ventana grande. Usa eventos delegados porque el
 * editor por sesion carga su contenido despues.
 */
(function () {
    'use strict';
    function conAutoplay(url) {
        return url + (url.indexOf('?') === -1 ? '?' : '&') + 'autoplay=1';
    }
    function iframe(url, titulo) {
        var f = document.createElement('iframe');
        f.src = url;
        f.title = titulo || 'Video';
        f.setAttribute('allow', 'autoplay; fullscreen; picture-in-picture; encrypted-media');
        f.setAttribute('allowfullscreen', '');
        f.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        return f;
    }

    // Ventana ampliada (se crea la primera vez).
    var modal = null;
    function cerrar() {
        if (!modal) return;
        modal.classList.remove('is-abierto');
        modal.querySelector('.aula-visor-medio').innerHTML = ''; // detiene el video
        document.body.classList.remove('aula-visor-activo');
    }
    function abrir(nodo, titulo) {
        if (!modal) {
            modal = document.createElement('div');
            modal.className = 'aula-visor';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            modal.innerHTML = '<div class="aula-visor-caja"><div class="aula-visor-cab"><strong class="aula-visor-titulo"></strong>' +
                '<button type="button" class="aula-visor-cerrar" aria-label="Cerrar">&times;</button></div><div class="aula-visor-medio"></div></div>';
            document.body.appendChild(modal);
            modal.addEventListener('click', function (e) { if (e.target === modal || e.target.closest('.aula-visor-cerrar')) cerrar(); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrar(); });
        }
        modal.querySelector('.aula-visor-titulo').textContent = titulo || '';
        var medio = modal.querySelector('.aula-visor-medio');
        medio.innerHTML = '';
        medio.appendChild(nodo);
        modal.classList.add('is-abierto');
        document.body.classList.add('aula-visor-activo');
        modal.querySelector('.aula-visor-cerrar').focus();
    }

    document.addEventListener('click', function (e) {
        var play = e.target.closest('[data-aula-play]');
        var ampliar = e.target.closest('[data-aula-ampliar]');
        if (!play && !ampliar) return;
        var player = (play || ampliar).closest('.aula-player');
        if (!player) return;
        e.preventDefault();
        var titulo = player.getAttribute('data-titulo') || (ampliar && ampliar.getAttribute('data-titulo')) || '';
        if (ampliar) {
            var mp4 = ampliar.getAttribute('data-video');
            var enLinea = player.querySelector('video');
            var nodo;
            if (mp4) {
                nodo = document.createElement('video');
                nodo.src = mp4;
                nodo.controls = true;
                nodo.autoplay = true;
                nodo.setAttribute('playsinline', '');
                if (enLinea && enLinea.currentTime) { nodo.currentTime = enLinea.currentTime; enLinea.pause(); }
            } else {
                var actual = player.querySelector('iframe');
                if (actual) actual.remove(); // evita que suenen dos reproductores
                var portada = player.querySelector('.aula-player-portada');
                if (portada) portada.hidden = false;
                nodo = iframe(conAutoplay(player.getAttribute('data-embed')), titulo);
            }
            abrir(nodo, titulo);
            return;
        }
        // Reproducir en el lugar: la miniatura se cambia por el reproductor.
        if (player.querySelector('iframe')) return;
        var p = player.querySelector('.aula-player-portada');
        var f = iframe(conAutoplay(player.getAttribute('data-embed')), titulo);
        f.className = 'aula-player-iframe';
        if (p) { p.hidden = true; p.parentNode.insertBefore(f, p); } else player.insertBefore(f, player.firstChild);
    });
})();
