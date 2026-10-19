(function () {
    'use strict';
    var configNode = document.getElementById('ses-inline-config');
    if (!configNode) return;
    var config = JSON.parse(configNode.textContent), dirty = new Set(), editors = new Map();
    var saveSelected = document.getElementById('ses-inline-save-selected'), selectAll = document.getElementById('ses-inline-select-all'), status = document.getElementById('ses-inline-status');
    function updateSelection() {
        var count = 0;
        editors.forEach(function (e, id) { if (dirty.has(id) && e.checkbox().checked) count++; });
        saveSelected.disabled = count === 0; saveSelected.textContent = 'Guardar seleccionadas (' + count + ')';
    }
    function initializeRow(row) {
        if (row.dataset.inlineReady) return;
        row.dataset.inlineReady = '1';
        row.setAttribute('data-sin-menu', '');
        var data = JSON.parse(row.dataset.inlineSession), plan = config.planes[data.planificacion_id] || {};
        var original = Array.from(row.cells).map(function (cell) { return cell.innerHTML; });
        var formId = 'ses-inline-' + data.id;
        var offset = row.dataset.inlineNumero === '1' ? 1 : 0;
        var cells = {fecha:row.cells[offset],modulo:row.cells[offset+1],instructor:row.cells[offset+2],soporte:row.cells[offset+3],lugar:row.cells[offset+4],acciones:row.cells[offset+6]};
        var checkbox;
        function build() {
            row.classList.add('ses-inline-row');
            var form = document.createElement('form');
            form.id = formId; form.method = 'post';
            function hidden(name, value) {
                var e = document.createElement('input'); e.type = 'hidden'; e.name = name; e.value = value == null ? '' : value; form.appendChild(e);
            }
            hidden('accion', 'guardar_sesion'); hidden('edicion_tabla', '1'); hidden('csrf', config.csrf);
            hidden('id', data.id); hidden('revision', row.dataset.inlineRevision);
            hidden('planificacion_id', data.planificacion_id); hidden('tema', data.tema); hidden('estado', data.estado);
            hidden('fecha_original', data.fecha);
            function field(cell, name, value, sourceId, type, locked) {
                var e;
                if (sourceId) {
                    e = document.getElementById(sourceId).cloneNode(true);
                    e.removeAttribute('id'); e.removeAttribute('onchange');
                    if (value != null && !Array.from(e.options).some(function (o) { return o.value === String(value); })) e.add(new Option(String(value), String(value)));
                } else { e = document.createElement('input'); e.type = type || 'text'; }
                e.name = name; e.className = 'form-control'; e.setAttribute('form', formId);
                e.setAttribute('aria-label', {fecha:'Fecha',hora_inicio:'Hora inicio',hora_fin:'Hora fin',modulo:'Módulo',instructor_id:'Instructor',soporte_id:'Soporte técnico',modalidad:'Modalidad',lugar:'Lugar o enlace'}[name]);
                e.value = value == null ? '' : value;
                if (name === 'lugar') { e.maxLength = 255; e.required = true; }
                if (type === 'date' || type === 'time') e.required = true;
                if (locked) { e.disabled = true; hidden(name, value); }
                cell.appendChild(e); return e;
            }
            function note(cell, text) { var n = document.createElement('small'); n.className = 'ses-inline-note'; n.textContent = text; cell.appendChild(n); return n; }
            Object.values(cells).forEach(function (cell) { cell.replaceChildren(); });
            var paid = row.dataset.inlineLiquidacion === '1', supportPaid = row.dataset.inlineSoporte === '1';
            field(cells.fecha, 'fecha', data.fecha, null, 'date', paid);
            field(cells.fecha, 'hora_inicio', data.hora_inicio, null, 'time', false);
            field(cells.fecha, 'hora_fin', data.hora_fin, null, 'time', false);
            var times = document.createElement('div'); times.className = 'ses-inline-times';
            cells.fecha.querySelectorAll('input[type=time]').forEach(function (e) { times.appendChild(e); }); cells.fecha.appendChild(times);
            var dateTime = document.createElement('div'); dateTime.className = 'ses-inline-datetime';
            dateTime.append(cells.fecha.querySelector('input[type=date]'),times);cells.fecha.appendChild(dateTime);
            if (paid) note(cells.fecha, 'Fecha protegida: pago liquidado.');
            var modules = document.createElement('select'); modules.id = formId + '-modules';
            (plan.modulos || []).forEach(function (m) { modules.add(new Option(m, m)); });
            if (!Array.from(modules.options).some(function (o) { return o.value === data.modulo; })) modules.add(new Option(data.modulo || 'Sin módulo', data.modulo || 'Sesión única'));
            cells.modulo.appendChild(modules);
            field(cells.modulo, 'modulo', data.modulo, modules.id, null, !!plan.automatico); modules.remove();
            if (plan.automatico) note(cells.modulo, 'Asignado automáticamente por número de sesión.');
            var instructor = field(cells.instructor, 'instructor_id', data.instructor_id, 'sf-instr', null, paid);
            function showCode() { var option = instructor.options[instructor.selectedIndex]; instructor.title = option ? option.textContent : 'Pendiente de asignar'; }
            instructor.addEventListener('change', showCode); showCode();
            if (paid) note(cells.instructor, 'Instructor protegido: pago liquidado.');
            field(cells.soporte, 'soporte_id', data.soporte_id, 'sf-soporte', null, supportPaid);
            if (supportPaid) note(cells.soporte, 'Soporte protegido: pago liquidado.');
            field(cells.lugar, 'modalidad', data.modalidad, 'sf-modalidad');
            field(cells.lugar, 'lugar', data.lugar, null, 'text');
            var venue = document.createElement('div'); venue.className = 'ses-inline-venue';
            venue.append(cells.lugar.querySelector('select'),cells.lugar.querySelector('input'));cells.lugar.appendChild(venue);
            row.querySelectorAll('.ses-inline-note').forEach(function(n){n.parentElement.title=n.textContent;});
            var actions = document.createElement('div'); actions.className = 'ses-inline-actions';
            checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.disabled = !dirty.has(data.id); checkbox.setAttribute('aria-label', 'Seleccionar sesión ' + data.id + ' para guardar');
            checkbox.addEventListener('change', updateSelection);
            var cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'btn btn-default btn-sm'; cancel.textContent = '↺'; cancel.title = 'Restablecer fila'; cancel.setAttribute('aria-label', 'Restablecer fila');
            cancel.addEventListener('click', function () { original.forEach(function (html, i) { row.cells[i].innerHTML = html; }); dirty.delete(data.id); row.classList.remove('is-dirty'); build(); updateSelection(); });
            actions.append(checkbox, cancel); cells.acciones.append(form, actions);
            form.addEventListener('submit', function (event) { event.preventDefault(); });
        }
        function changed(event) { if(event.target === checkbox)return; dirty.add(data.id); row.classList.add('is-dirty'); checkbox.disabled=false; checkbox.checked=true; updateSelection(); }
        row.addEventListener('input', changed);
        row.addEventListener('change', changed);
        build();
        editors.set(data.id,{checkbox:function(){return checkbox;},form:function(){return document.getElementById(formId);},accept:function(saved){data=saved.datos;row.dataset.inlineRevision=saved.revision;row.dataset.inlineSession=JSON.stringify(data);dirty.delete(data.id);row.classList.remove('is-dirty');build();}});
    }
    document.querySelectorAll('[data-inline-session]').forEach(function (row) {
        var group = row.closest('details.ses-curso-item');
        if (!group || group.open) initializeRow(row);
    });
    document.querySelectorAll('details.ses-curso-item').forEach(function (group) {
        group.addEventListener('toggle', function () {
            if (group.open) group.querySelectorAll('[data-inline-session]').forEach(initializeRow);
        });
    });
    selectAll.addEventListener('change',function(){editors.forEach(function(e,id){e.checkbox().checked=selectAll.checked&&dirty.has(id);});updateSelection();});
    saveSelected.addEventListener('click',async function(){
        var payload=[];
        for(var entry of editors){var id=entry[0],editor=entry[1];if(!dirty.has(id)||!editor.checkbox().checked)continue;if(!editor.form().reportValidity())return;payload.push(Object.fromEntries(new FormData(editor.form())));}
        if(!payload.length)return;
        saveSelected.disabled=true;status.textContent='Guardando '+payload.length+' filas…';
        async function send(confirmed){var body=new FormData();body.append('accion','guardar_tabla');body.append('csrf',config.csrf);body.append('filas',JSON.stringify(payload));if(confirmed)body.append('confirmar','1');var response=await fetch(window.location.href,{method:'POST',body:body});return response.json();}
        try{
            var result=await send(false);
            if(result.confirmar){if(!window.confirm((result.avisos||[]).join('\n')+'\n\n¿Guardar estas filas de todos modos?')){status.textContent='No se guardaron cambios.';return;}result=await send(true);}
            if(!result.ok)throw new Error(result.mensaje||'No se guardaron las filas.');
            (result.filas||[]).forEach(function(saved){var editor=editors.get(saved.id);if(editor)editor.accept(saved);});
            status.textContent=result.guardadas+' filas guardadas.'+(result.aviso?' '+result.aviso:'');selectAll.checked=false;
        }catch(error){status.textContent=error.message||'No fue posible guardar. Tus cambios siguen en la tabla.';}
        finally{updateSelection();}
    });
    window.addEventListener('beforeunload', function (event) { if (dirty.size) { event.preventDefault(); event.returnValue = ''; } });
}());
