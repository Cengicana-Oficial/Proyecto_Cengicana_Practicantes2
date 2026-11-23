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
            avisar(player, nodo);
            return;
        }
        // Reproducir en el lugar: la miniatura se cambia por el reproductor.
        if (player.querySelector('iframe')) return;
        var p = player.querySelector('.aula-player-portada');
        var f = iframe(conAutoplay(player.getAttribute('data-embed')), titulo);
        f.className = 'aula-player-iframe';
        if (p) { p.hidden = true; p.parentNode.insertBefore(f, p); } else player.insertBefore(f, player.firstChild);
        avisar(player, f);
    });
    // Avance de grabaciones (js/aula_avance.js, modalidad asincrónica): informa qué reproductor se abrió.
    function avisar(player, nodo) {
        try { document.dispatchEvent(new CustomEvent('aula:reproductor', { detail: { player: player, nodo: nodo } })); } catch (e) {}
    }

    // Formularios de grabación ([data-video-detectar]): al pegar el enlace o el código de inserción indica si
    // se reproducirá dentro del aula (misma regla que aula_video_embed() en el servidor).
    function detectar(texto) {
        var v = (texto || '').trim(), m = v.match(/<iframe\b[^>]*\bsrc\s*=\s*["']([^"']+)["']/i);
        if (m) v = m[1].replace(/&amp;/g, '&');
        else if (/^</.test(v)) return { ok: false, txt: 'Solo se acepta un enlace o un código <iframe>.' };
        if (v.indexOf('//') === 0) v = 'https:' + v;
        var u; try { u = new URL(v); } catch (e) { return v ? { ok: false, txt: 'No es un enlace válido (debe empezar con https://).' } : null; }
        if (!/^https?:$/.test(u.protocol)) return { ok: false, txt: 'El enlace debe empezar con https://.' };
        var h = u.hostname.toLowerCase().replace(/^(www\.|m\.|music\.)/, ''), p = u.pathname;
        if (/^(youtube\.com|youtu\.be|youtube-nocookie\.com)$/.test(h)) return { ok: true, txt: 'YouTube' };
        if (/(^|\.)vimeo\.com$/.test(h)) return { ok: true, txt: 'Vimeo' };
        if (h === 'drive.google.com') return { ok: true, txt: 'Google Drive (compártelo con «Cualquier persona con el enlace»)' };
        if (h === 'loom.com') return { ok: true, txt: 'Loom' };
        if (/(^|\.)sharepoint\.com$/.test(h) && /\/_layouts\/15\/embed\.aspx/i.test(p)) return { ok: true, txt: 'Teams / Microsoft Stream (los estudiantes necesitan permiso sobre el archivo)' };
        if (h === 'onedrive.live.com' && p === '/embed') return { ok: true, txt: 'OneDrive' };
        if (/(^|\.)(sharepoint\.com|1drv\.ms|onedrive\.live\.com|stream\.microsoft\.com)$/.test(h)) return { ok: false, txt: 'Enlace para compartir de Teams/Stream: se abrirá aparte. Para verlo dentro del aula usa Compartir → Insertar código.' };
        return { ok: false, txt: 'Se publicará como enlace «Abrir» (no se reproduce dentro del aula).' };
    }
    function actualizar(campo) {
        var form = campo.closest('form'), aviso = campo.parentNode.querySelector('[data-video-detectado]');
        if (!aviso) return;
        var r = detectar(campo.value);
        aviso.textContent = r ? (r.ok ? '✓ Se reproducirá dentro del aula: ' + r.txt : '⚠ ' + r.txt) : '';
        aviso.className = r ? (r.ok ? 'aula-video-detectado is-ok' : 'aula-video-detectado is-aviso') : '';
        var tipo = form && form.querySelector('select[name="tipo_recurso"]');
        if (tipo && r && r.ok) tipo.value = 'video';
    }
    document.addEventListener('input', function (e) { if (e.target.matches && e.target.matches('[data-video-detectar]')) actualizar(e.target); });
    document.querySelectorAll('[data-video-detectar]').forEach(function (c) { if (c.value) actualizar(c); });
})();
