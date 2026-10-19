(function(){
    'use strict';
    var form=document.getElementById('ci-form');
    if(form){
        function mode(){var single=form.querySelector('[name=modo]:checked').value==='unico',course=document.getElementById('ci-course');document.getElementById('ci-course-wrap').hidden=!single;course.required=single;course.disabled=!single;}
        form.querySelectorAll('[name=modo]').forEach(function(e){e.addEventListener('change',mode);});mode();
        document.getElementById('ci-file').addEventListener('change',function(){document.getElementById('ci-file-name').textContent=this.files.length?this.files[0].name:'Todavía no has seleccionado un archivo.';});
        form.addEventListener('submit',function(){var button=form.querySelector('button[type=submit]');button.disabled=true;button.textContent='Analizando archivo…';});
    }
    var search=document.getElementById('ci-preview-search'),state=document.getElementById('ci-preview-state');
    if(search&&state){var normalize=function(s){return s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();};function filter(){var q=normalize(search.value.trim());document.querySelectorAll('#ci-preview-rows tr').forEach(function(row){row.hidden=!!((state.value&&row.dataset.state!==state.value)||(q&&!normalize(row.textContent).includes(q)));});}search.addEventListener('input',filter);state.addEventListener('change',filter);}
    document.querySelectorAll('.ci-confirm form').forEach(function(f){f.addEventListener('submit',function(){f.querySelectorAll('button').forEach(function(b){b.disabled=true;});});});
}());
