/**
 * Avance de grabaciones en la modalidad asincrónica (aula_asincronica.php, aula_sesiones_vista.php).
 * - YouTube, Vimeo y MP4: se marcan los tramos de 5 s realmente reproducidos (adelantar el video no cuenta).
 * - Google Drive y otros: se cuenta el tiempo con el reproductor abierto y la página visible.
 * El avance se envía cada 15 s y al salir; con el mínimo (70 %) se habilita "Ya lo vi".
 */
(function () {
    'use strict';
    var trackers = [];

    function decode(b64) {
        try { var s = atob(b64 || ''), a = new Uint8Array(s.length); for (var i = 0; i < s.length; i++) a[i] = s.charCodeAt(i); return a; }
        catch (e) { return new Uint8Array(0); }
    }
    function encode(a) { var s = ''; for (var i = 0; i < a.length; i++) s += String.fromCharCode(a[i]); return btoa(s); }

    function Tracker(el) {
        this.el = el; this.id = el.getAttribute('data-recurso'); this.fuente = el.getAttribute('data-fuente');
        this.tramo = parseInt(el.getAttribute('data-tramo'), 10) || 5; this.minimo = parseFloat(el.getAttribute('data-minimo')) || 70;
        this.dur = parseInt(el.getAttribute('data-duracion'), 10) || 0; this.bits = decode(el.getAttribute('data-segmentos'));
        this.pct = parseFloat(el.getAttribute('data-porcentaje')) || 0; this.visto = el.getAttribute('data-visto') === '1';
        this.csrf = el.getAttribute('data-csrf'); this.ultimo = null; this.sucio = false; this.segundos = 0; this.nodos = [];
        var art = el.closest('article'); this.player = art && art.querySelector('.aula-player');
        var v = this.player && this.player.querySelector('video'); if (v) this.conectar(v);
    }
    Tracker.prototype.marcar = function (desde, hasta) {
        var n = Math.ceil(this.dur / this.tramo); if (!n) return;
        if (this.bits.length < Math.ceil(n / 8)) { var b = new Uint8Array(Math.ceil(n / 8)); b.set(this.bits); this.bits = b; }
        for (var i = Math.floor(desde / this.tramo); i <= Math.floor(hasta / this.tramo) && i < n; i++) {
            if (!(this.bits[i >> 3] & (1 << (i & 7)))) { this.bits[i >> 3] |= 1 << (i & 7); this.sucio = true; }
        }
    };
    Tracker.prototype.posicion = function (t, dur, reproduciendo) {
        if (dur > 0 && isFinite(dur)) this.dur = Math.max(this.dur, Math.round(dur));
        if (!(t >= 0) || !this.dur) return;
        // Solo cuenta el avance continuo: un salto de más de 3 s (adelantar) no marca los tramos intermedios.
        if (reproduciendo !== false && this.ultimo !== null && t >= this.ultimo && t - this.ultimo <= 3) this.marcar(this.ultimo, t);
        this.ultimo = t;
        var n = Math.ceil(this.dur / this.tramo), c = 0;
        for (var i = 0; i < n; i++) if (this.bits[i >> 3] & (1 << (i & 7))) c++;
        this.mostrar(Math.max(this.pct, Math.min(100, 100 * c / n)));
    };
    Tracker.prototype.mostrar = function (pct) {
        this.pct = pct;
        var barra = this.el.querySelector('.aula-avance-barra span'); if (barra) barra.style.width = Math.min(100, pct) + '%';
        if (this.visto) return;
        var txt = this.el.querySelector('[data-avance-texto]');
        if (txt) txt.textContent = 'Visto ' + Math.floor(pct) + ' % · ' + (pct >= this.minimo ? 'ya puedes marcarla como vista' : 'necesitas ' + this.minimo + ' %');
        var btn = this.el.querySelector('[data-avance-marcar]'); if (btn) btn.disabled = pct < this.minimo;
    };
    Tracker.prototype.conectar = function (nodo) {
        var self = this; this.nodos.push(nodo); this.ultimo = null;
        if (nodo.tagName === 'VIDEO') {
            nodo.addEventListener('timeupdate', function () { self.posicion(nodo.currentTime, nodo.duration, !nodo.paused); });
            nodo.addEventListener('seeking', function () { self.ultimo = null; });
            nodo.addEventListener('pause', function () { self.enviar(); });
            return;
        }
        if (nodo.tagName !== 'IFRAME') return;
        var hola = function () {
            try {
                if (self.fuente === 'youtube') nodo.contentWindow.postMessage(JSON.stringify({ event: 'listening', id: self.id, channel: 'widget' }), '*');
                if (self.fuente === 'vimeo') { nodo.contentWindow.postMessage(JSON.stringify({ method: 'addEventListener', value: 'timeupdate' }), '*'); nodo.contentWindow.postMessage(JSON.stringify({ method: 'addEventListener', value: 'seeked' }), '*'); }
            } catch (e) {}
        };
        nodo.addEventListener('load', function () { hola(); var k = 0, t = setInterval(function () { if (self.recibio || ++k > 10) clearInterval(t); else hola(); }, 1000); });
    };
    Tracker.prototype.abierto = function () {
        return this.nodos.some(function (n) { return n.isConnected; });
    };
    Tracker.prototype.enviar = function (alSalir) {
        if (this.visto) return;
        var otro = this.fuente === 'otro';
        if (otro ? this.segundos < 1 : !this.sucio) return;
        var fd = new FormData(), self = this, segs = this.segundos;
        fd.append('accion', 'video_avance'); fd.append('csrf', this.csrf); fd.append('recurso', this.id);
        if (otro) { fd.append('segundos', String(segs)); this.segundos = 0; }
        else { fd.append('segmentos', encode(this.bits)); fd.append('duracion', String(this.dur)); this.sucio = false; }
        var p = fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: !!alSalir });
        if (alSalir) return;
        p.then(function (r) { return r.json(); }).then(function (j) {
            if (j.ok && typeof j.porcentaje === 'number') self.mostrar(Math.max(self.pct, j.porcentaje));
            else if (!j.ok && otro) self.segundos += segs;
        }).catch(function () { if (otro) self.segundos += segs; else self.sucio = true; });
    };
    Tracker.prototype.marcarVisto = function (btn) {
        var fd = new FormData(), self = this; btn.disabled = true;
        this.enviar();
        fd.append('accion', 'video_visto'); fd.append('csrf', this.csrf); fd.append('recurso', this.id);
        fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
            var txt = self.el.querySelector('[data-avance-texto]');
            if (!j.ok) { btn.disabled = false; if (txt) txt.textContent = j.mensaje; return; }
            self.visto = true; self.el.classList.add('is-visto'); btn.remove();
            if (txt) txt.textContent = '✓ ' + j.mensaje;
        }).catch(function () { btn.disabled = false; });
    };

    document.querySelectorAll('[data-avance]').forEach(function (el) { trackers.push(new Tracker(el)); });
    if (!trackers.length) return;

    // Reproductores creados al pulsar "Reproducir" o "Ampliar" (js/aula_video.js).
    document.addEventListener('aula:reproductor', function (e) {
        trackers.forEach(function (t) { if (t.player === e.detail.player) t.conectar(e.detail.nodo); });
    });
    // Mensajes de YouTube (infoDelivery) y Vimeo (timeupdate).
    window.addEventListener('message', function (e) {
        if (!/^https:\/\/(www\.)?(youtube(-nocookie)?\.com|player\.vimeo\.com)$/.test(e.origin)) return;
        var d; try { d = typeof e.data === 'string' ? JSON.parse(e.data) : e.data; } catch (x) { return; }
        if (!d) return;
        trackers.forEach(function (t) {
            if (!t.nodos.some(function (n) { return n.contentWindow === e.source; })) return;
            t.recibio = true;
            if (t.fuente === 'youtube' && d.info) {
                if (d.info.duration) t.dur = Math.max(t.dur, Math.round(d.info.duration));
                if (typeof d.info.currentTime === 'number') t.posicion(d.info.currentTime, d.info.duration || 0, d.info.playerState === undefined || d.info.playerState === 1);
                if (d.info.playerState === 2 || d.info.playerState === 0) t.enviar();
            } else if (t.fuente === 'vimeo') {
                if (d.event === 'ready') { try { e.source.postMessage(JSON.stringify({ method: 'addEventListener', value: 'timeupdate' }), e.origin); e.source.postMessage(JSON.stringify({ method: 'addEventListener', value: 'seeked' }), e.origin); } catch (x) {} }
                if (d.event === 'seeked') t.ultimo = null;
                if (d.event === 'timeupdate' && d.data) t.posicion(d.data.seconds, d.data.duration, true);
            }
        });
    });
    // Plataformas sin medición: un segundo por cada segundo con el reproductor abierto y la página visible.
    setInterval(function () {
        if (document.visibilityState !== 'visible') return;
        trackers.forEach(function (t) {
            if (t.fuente !== 'otro' || t.visto || !t.abierto()) return;
            t.segundos++;
            var dur = t.dur, el = t.el.querySelector('[data-avance-texto]');
            if (el && t.segundos % 5 === 0) el.textContent = 'Contando tiempo de reproducción…';
        });
    }, 1000);
    setInterval(function () { trackers.forEach(function (t) { t.enviar(); }); }, 15000);
    window.addEventListener('pagehide', function () { trackers.forEach(function (t) { t.enviar(true); }); });
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-avance-marcar]'); if (!b) return;
        trackers.forEach(function (t) { if (t.el.contains(b)) t.marcarVisto(b); });
    });
})();
