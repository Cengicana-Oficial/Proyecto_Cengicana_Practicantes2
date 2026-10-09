/*
 * Dibujo de diplomas (diplomas.php, validar_diploma.php). Requiere js/qrcode-generator.js
 * y css/diplomas.css. Uso:
 *   CengiDiplomas.datos(actividad, participante, plantilla, codigo, urlValidar) -> valores {nombre}, {actividad}...
 *   CengiDiplomas.html(plantilla, valores, {edit: bool, campoSel: id}) -> HTML de una hoja
 *   CengiDiplomas.imprimir([html, ...], plantilla, cssUrl) -> abre el dialogo de impresion (una hoja por pagina)
 */
(function (global) {
    'use strict';

    var FUENTES = {
        serif: "Georgia,'Times New Roman',serif",
        script: "'Brush Script MT','Segoe Script','Lucida Handwriting',cursive",
        sans: 'Arial,Helvetica,sans-serif',
        cond: "'Arial Narrow','Roboto Condensed',Arial,sans-serif",
        elegante: "'Palatino Linotype',Palatino,'Book Antiqua',Georgia,serif"
    };
    var CAMPOS = [['{nombre}', 'Nombre'], ['{actividad}', 'Actividad'], ['{tipo_actividad}', 'Tipo'], ['{fecha}', 'Fecha'], ['{lugar}', 'Lugar'],
        ['{horas}', 'Horas'], ['{institucion}', 'Institución'], ['{rol}', 'Rol'], ['{cui}', 'CUI'], ['{codigo}', 'Código']];
    var MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }
    // Solo se aceptan imagenes data: o rutas internas (el servidor valida lo mismo al guardar).
    function img(src) {
        return typeof src === 'string' && (/^data:image\/(png|jpeg|webp);base64,/.test(src) || /^(css\/images|img)\/[\w.-]+\.(png|jpe?g|webp)$/.test(src)) ? src : '';
    }
    function fechaLarga(iso) {
        if (!iso) return '';
        var p = String(iso).split('-');
        return (+p[2]) + ' de ' + MESES[(+p[1]) - 1] + ' de ' + p[0];
    }
    function texto(s, v) {
        return esc(s).replace(/\{(\w+)\}/g, function (m, k) { return v[k] !== undefined ? '<b>' + esc(v[k]) + '</b>' : m; }).replace(/\n/g, '<br>');
    }
    function qr(textoQr, tam) {
        if (typeof global.qrcode !== 'function' || !textoQr) return '';
        var q = global.qrcode(0, 'M');
        q.addData(String(textoQr), 'Byte');
        q.make();
        return q.createSvgTag({ cellSize: 3, margin: 0, scalable: true }).replace('<svg ', '<svg width="' + tam + '" height="' + tam + '" ');
    }

    function canvaDefecto() {
        return { fondo: null, nombre: '', w: 1123, h: 794, campos: [
            { id: 'c1', tipo: 'texto', contenido: '{nombre}', x: 50, y: 47, w: 70, size: 46, color: '#0F2A1C', fuente: 'script', bold: false, italic: false, upper: false, align: 'center' },
            { id: 'c2', tipo: 'texto', contenido: '{fecha} · {lugar}', x: 50, y: 60, w: 60, size: 16, color: '#3A4236', fuente: 'sans', bold: false, italic: false, upper: false, align: 'center' },
            { id: 'c3', tipo: 'texto', contenido: 'Código de validación: {codigo}', x: 17, y: 94, w: 30, size: 10, color: '#5B6459', fuente: 'sans', bold: false, italic: false, upper: false, align: 'left' },
            { id: 'c4', tipo: 'qr', x: 90, y: 86, size: 86 }
        ] };
    }

    function datos(act, p, t, codigo, url) {
        return {
            nombre: p.nombre, cui: p.cui || '', institucion: p.institucion || '', rol: (p.rol || 'participante').toLowerCase(),
            actividad: act.actividad, tipo_actividad: act.tipo_actividad, fecha: act.fecha, lugar: act.lugar, horas: t.horas || '',
            codigo: codigo || 'CEN-XX-0000-0000-XXXX', fecha_emision: fechaLarga(t.emision), documento: String(t.documento || '').toLowerCase(),
            url: url || ''
        };
    }

    function htmlCanva(t, v, op) {
        var c = t.canva || canvaDefecto();
        var campos = c.campos.map(function (f) {
            var sel = op.edit && op.campoSel === f.id ? ' sel' : '', ed = op.edit ? ' ed' : '';
            if (f.tipo === 'qr') {
                return '<div class="dpc-campo' + ed + sel + '" data-campo="' + esc(f.id) + '" style="left:' + f.x + '%;top:' + f.y + '%;transform:translate(-50%,-50%);background:#fff;padding:4px;line-height:0;">' + qr(v.url || v.codigo, f.size) + '</div>';
            }
            var tx = esc(f.contenido).replace(/\{(\w+)\}/g, function (m, k) { return v[k] !== undefined ? esc(v[k]) : m; }).replace(/\n/g, '<br>');
            var tr = f.align === 'left' ? '0' : f.align === 'right' ? '-100%' : '-50%';
            return '<div class="dpc-campo' + ed + sel + '" data-campo="' + esc(f.id) + '" style="left:' + f.x + '%;top:' + f.y + '%;width:' + f.w + '%;transform:translate(' + tr + ',-50%);text-align:' + f.align +
                ';font-family:' + (FUENTES[f.fuente] || FUENTES.sans) + ';font-size:' + f.size + 'px;color:' + esc(f.color) + ';font-weight:' + (f.bold ? 700 : 400) +
                ';font-style:' + (f.italic ? 'italic' : 'normal') + ';' + (f.upper ? 'text-transform:uppercase;letter-spacing:.08em;' : '') + '">' + tx + '</div>';
        }).join('');
        var fondo = img(c.fondo) ? '<img class="dpc-fondo" src="' + img(c.fondo) + '" alt="">'
            : '<div class="dpc-vacio"><b>Sube el diseño que exportaste de Canva</b><span>PNG o JPG · tamaño carta o A4 horizontal · deja libre el espacio del nombre</span></div>';
        return '<div class="dip-page dpc" style="height:' + (c.h || 794) + 'px;">' + fondo + campos + '</div>';
    }

    function html(t, v, op) {
        op = op || {};
        if (t.estilo === 'canva') return htmlCanva(t, v, op);
        var col = /^#[0-9a-fA-F]{6}$/.test(t.color) ? t.color : '#2F6B12';
        var fondo = t.fondo === 'propia' ? img(t.fondoImg) : '';
        var logos = (t.logos || []).slice(0, 3).map(function (l) { return img(l) ? '<img src="' + img(l) + '" alt="">' : '<span></span>'; });
        while (logos.length < 3) logos.push('<span></span>');
        var clasico = t.estilo === 'clasico';
        var firmas = (t.firmas || []).slice(0, t.nFirmas || 0).map(function (f) {
            return '<div class="dp-firma">' + (img(f.img) ? '<img src="' + img(f.img) + '" alt="">' : '<div style="height:62px;"></div>') +
                '<div class="dp-linea" style="border-color:' + (clasico ? '#7A6630' : '#1C2517') + ';"></div><b>' + (esc(f.nombre) || '&nbsp;') + '</b><span>' + esc(f.cargo) + '</span></div>';
        }).join('');
        var qrHtml = t.qr ? '<div class="dp-qr">' + qr(v.url || v.codigo, 92) + '<div>' + esc(v.codigo) + '</div></div>' : '';
        var cod = t.qr ? '<div class="dp-cod">Verifica este documento con el código <b>' + esc(v.codigo) + '</b> en el portal de CENGICAÑA</div>' : '';
        var doc = esc(String(t.documento || '').toUpperCase()), largo = String(t.documento || '').length > 12 ? ' largo' : '';
        if (t.estilo === 'moderno') {
            return '<div class="dip-page dp-moderno"><div class="dp-banda" style="background:linear-gradient(170deg,' + col + ',#0F2A1C);">' +
                (fondo ? '<div class="dp-bg" style="background-image:url(\'' + fondo + '\');opacity:.35;"></div>' : '') +
                '<div class="dp-banda-in">' + (t.logos || []).filter(img).map(function (l) { return '<div class="dp-logo-b"><img src="' + img(l) + '" alt=""></div>'; }).join('') +
                '<div class="dp-banda-t">' + esc(v.actividad) + '</div><div class="dp-banda-s">' + esc(v.fecha) + '</div></div></div>' +
                '<div class="dp-cont"><div class="dp-doc' + largo + '" style="color:' + col + ';">' + doc + '</div>' +
                '<div class="dp-intro">' + texto(t.intro, v) + ' a</div><div class="dp-nom">' + esc(v.nombre) + '</div><div class="dp-bar" style="background:' + col + ';"></div>' +
                '<div class="dp-cuerpo">' + texto(t.cuerpo, v) + '</div><div class="dp-lf">' + texto(t.lugarFecha, v) + '</div>' +
                '<div class="dp-firmas">' + firmas + '</div>' + qrHtml + cod + '</div></div>';
        }
        var marco = clasico ? '#B08D3C' : col;
        return '<div class="dip-page ' + (clasico ? 'dp-clasico' : 'dp-inst') + '" style="' + (clasico ? 'background:#FBF7EC;' : '') + '">' +
            (fondo ? '<div class="dp-bg" style="background-image:url(\'' + fondo + '\');opacity:' + ((t.fondoOp || 14) / 100) + ';"></div>' : '') +
            '<div class="dp-marco1" style="border-color:' + marco + ';"></div><div class="dp-marco2" style="border-color:' + marco + ';"></div>' +
            (clasico ? ['tl', 'tr', 'bl', 'br'].map(function (c) { return '<div class="dp-orn dp-' + c + '" style="color:#B08D3C;">❦</div>'; }).join('') : '<div class="dp-cinta" style="background:' + col + ';"></div>') +
            '<div class="dp-in"><div class="dp-logos">' + logos.join('') + '</div>' +
            '<div class="dp-doc' + largo + '" style="color:' + (clasico ? '#7A6630' : col) + ';">' + doc + '</div>' +
            '<div class="dp-intro">' + texto(t.intro, v) + ' a</div><div class="dp-nom" style="border-color:' + marco + ';">' + esc(v.nombre) + '</div>' +
            '<div class="dp-cuerpo">' + texto(t.cuerpo, v) + '</div><div class="dp-lf">' + texto(t.lugarFecha, v) + '</div>' +
            '<div class="dp-firmas">' + firmas + '</div></div>' + qrHtml + cod + '</div>';
    }

    /** Ajusta la hoja (1123 px de ancho) al papel horizontal elegido. */
    function zoom(t) {
        var mm = t.papel === 'a4' ? [297, 210] : [279.4, 215.9];
        var alto = t.estilo === 'canva' && t.canva ? t.canva.h : 794;
        return Math.min(mm[0] * 96 / 25.4 / 1123, mm[1] * 96 / 25.4 / alto);
    }

    function imprimir(paginas, t, cssUrl) {
        var marco = document.createElement('iframe');
        marco.setAttribute('aria-hidden', 'true');
        marco.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
        document.body.appendChild(marco);
        var doc = marco.contentWindow.document;
        doc.open();
        doc.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Diplomas</title><base href="' + esc(document.baseURI) + '">' +
            '<link rel="stylesheet" href="' + esc(cssUrl) + '"><style>@page{size:' + (t.papel === 'a4' ? 'A4' : 'letter') + ' landscape;margin:0;}' +
            'html,body{margin:0;padding:0;background:#fff;}.dip-page{zoom:' + zoom(t).toFixed(4) + ';}.dip-page:last-child{page-break-after:auto;break-after:auto;}</style></head><body>' +
            paginas.join('') + '</body></html>');
        doc.close();
        var hecho = false;
        var listo = function () {
            if (hecho) return;
            hecho = true;
            marco.contentWindow.focus();
            marco.contentWindow.print();
            setTimeout(function () { marco.remove(); }, 2000);
        };
        // Espera a que carguen la hoja de estilos y las imagenes.
        var imgs = [].slice.call(doc.images), pendientes = imgs.filter(function (i) { return !i.complete; }).length;
        var hoja = doc.querySelector('link');
        var esperar = function () { if (--pendientes <= 0) setTimeout(listo, 150); };
        pendientes += 1;
        hoja.addEventListener('load', esperar); hoja.addEventListener('error', esperar);
        imgs.forEach(function (i) { if (!i.complete) { i.addEventListener('load', esperar); i.addEventListener('error', esperar); } });
        setTimeout(function () { if (pendientes > 0) { pendientes = 0; listo(); } }, 4000);
    }

    global.CengiDiplomas = { html: html, datos: datos, imprimir: imprimir, canvaDefecto: canvaDefecto, FUENTES: FUENTES, CAMPOS: CAMPOS, esc: esc, fechaLarga: fechaLarga };
})(window);
