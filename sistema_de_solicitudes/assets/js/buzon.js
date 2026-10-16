// Buzon de ideas (buzon.php): ejemplos por area, contadores, vista previa de foto y validacion.
(function () {
  const IDEAS = {
    "Procesos y trabajo de campo": [
      "Una ruta o secuencia de tareas más corta en las visitas a campo",
      "Plantillas o listas de verificación para actividades repetitivas",
      "Mejor coordinación de horarios, vehículos o cuadrillas",
      "Registro digital en campo en lugar de papel"],
    "Investigación y calidad": [
      "Formatos de registro más claros o con menos pasos duplicados",
      "Mejoras en el etiquetado, almacenamiento y traslado de muestras",
      "Controles para detectar errores en los datos a tiempo",
      "Protocolos documentados para ensayos que hoy se hacen «de memoria»"],
    "Seguridad y bienestar": [
      "Riesgos que has visto en áreas de trabajo, bodegas o laboratorios",
      "Equipo de protección que falta, está incómodo o es inadecuado",
      "Descansos, hidratación y sombra en trabajo de campo",
      "Señalización, iluminación u orden en espacios compartidos"],
    "Recursos y eficiencia": [
      "Materiales que se desperdician o podrían reutilizarse",
      "Equipos subutilizados que podrían compartirse entre áreas",
      "Compras conjuntas o inventario visible para evitar faltantes",
      "Ahorro de energía, agua o combustible"],
    "Comunicación y trabajo en equipo": [
      "Reuniones breves entre departamentos con objetivos claros",
      "Un canal único para avisos importantes",
      "Reconocimiento al trabajo bien hecho",
      "Inducción más clara para personal nuevo"],
    "Otra": [
      "Cualquier idea que no encaje en las demás categorías es bienvenida"]
  };

  const form = document.getElementById('formIdea');
  if (!form) return; // pantalla de "idea enviada"
  const $ = id => document.getElementById(id);

  // --- Categoria: muestra ejemplos y campo "Otra"
  function mostrarArea(v) {
    $('errArea').style.display = 'none';
    $('otraWrap').hidden = v !== 'Otra';
    const box = $('ideas');
    box.hidden = false;
    box.innerHTML = '';
    const titulo = document.createElement('strong');
    titulo.textContent = 'Ideas que puedes aportar aquí';
    const lista = document.createElement('ul');
    (IDEAS[v] || []).forEach(t => {
      const li = document.createElement('li');
      li.textContent = t;
      lista.appendChild(li);
    });
    box.append(titulo, lista);
  }
  form.querySelectorAll('input[name=area]').forEach(r => r.addEventListener('change', () => mostrarArea(r.value)));
  const marcada = form.querySelector('input[name=area]:checked');
  if (marcada) mostrarArea(marcada.value);

  // --- Contadores de caracteres
  document.querySelectorAll('textarea').forEach(t => {
    const c = document.querySelector('[data-count="' + t.id + '"]');
    c.textContent = t.value.length;
    t.addEventListener('input', () => { c.textContent = t.value.length; t.classList.remove('invalid'); });
  });

  // --- Foto con vista previa
  const foto = $('foto');
  function limpiarFoto() {
    foto.value = ''; $('preview').style.display = 'none'; $('quitarFoto').style.display = 'none';
    $('nombreFoto').textContent = 'Ningún archivo seleccionado'; $('errFoto').style.display = 'none';
  }
  foto.addEventListener('change', () => {
    const f = foto.files[0];
    if (!f) { limpiarFoto(); return; }
    const ok = ['image/jpeg', 'image/png'].includes(f.type) && f.size <= 5 * 1024 * 1024;
    if (!ok) { limpiarFoto(); $('errFoto').style.display = 'block'; return; }
    $('errFoto').style.display = 'none';
    $('nombreFoto').textContent = f.name;
    $('preview').src = URL.createObjectURL(f);
    $('preview').style.display = 'block';
    $('quitarFoto').style.display = 'inline';
  });
  $('quitarFoto').addEventListener('click', limpiarFoto);

  // --- Anonimo: oculta datos personales
  $('anonimo').addEventListener('change', e => {
    $('datosPersonales').hidden = e.target.checked;
    if (e.target.checked) { $('nombre').value = ''; $('correo').value = ''; $('correo').classList.remove('invalid'); }
  });

  // --- Validacion
  function validar() {
    let ok = true;
    if (!form.querySelector('input[name=area]:checked')) { $('errArea').style.display = 'block'; ok = false; }
    ['situacion', 'propuesta', 'beneficio'].forEach(id => {
      const el = $(id);
      const bad = el.value.trim().length < 5;
      el.classList.toggle('invalid', bad);
      if (bad) ok = false;
    });
    const mail = $('correo');
    const badMail = mail.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(mail.value.trim());
    mail.classList.toggle('invalid', badMail);
    if (badMail) ok = false;
    if (!ok) {
      const first = document.querySelector('.invalid, #errArea[style*="block"]');
      if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    return ok;
  }

  form.addEventListener('submit', e => {
    if (!validar()) { e.preventDefault(); return; }
    const btn = $('btnEnviar');
    btn.disabled = true;
    btn.textContent = 'Enviando...';
  });

  // --- Limpiar (vacia tambien lo que el servidor devolvio tras un error)
  form.addEventListener('reset', e => {
    e.preventDefault();
    form.querySelectorAll('input[type=text], input[type=email], textarea').forEach(el => { el.value = ''; });
    form.querySelectorAll('input[type=radio], input[type=checkbox]').forEach(el => { el.checked = false; });
    limpiarFoto();
    $('ideas').hidden = true; $('otraWrap').hidden = true; $('datosPersonales').hidden = false;
    document.querySelectorAll('[data-count]').forEach(c => c.textContent = '0');
    document.querySelectorAll('.invalid').forEach(el => el.classList.remove('invalid'));
    $('errArea').style.display = 'none';
  });
})();
