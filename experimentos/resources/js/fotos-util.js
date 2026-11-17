// Fotos de parcela: se reducen en el navegador (respetando la orientacion de la camara)
// antes de subirlas, para que en campo con poca señal no se envien fotos de 8-12 MB.

async function cargarImagen(file) {
  if (window.createImageBitmap) {
    try { return await createImageBitmap(file, { imageOrientation: 'from-image' }); } catch (e) { /* navegador sin soporte: usa <img> */ }
  }
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('No se pudo leer la imagen.')); };
    img.src = url;
  });
}

function aJpeg(img, maxLado, calidad) {
  const ancho = img.width; const alto = img.height;
  const f = Math.min(1, maxLado / Math.max(ancho, alto));
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(ancho * f); canvas.height = Math.round(alto * f);
  canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
  return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', calidad));
}

/** Imagen principal (lado mayor 1920 px) y miniatura (480 px) en JPEG. */
export async function prepararFoto(file) {
  if (!/^image\//.test(file.type) && !/\.(jpe?g|png|webp|heic)$/i.test(file.name)) throw new Error(`${file.name}: no es una imagen.`);
  const img = await cargarImagen(file);
  const [imagen, miniatura] = await Promise.all([aJpeg(img, 1920, 0.85), aJpeg(img, 480, 0.75)]);
  if (img.close) img.close();
  return { imagen, miniatura };
}

/** Sube una foto de parcela; devuelve la foto creada (forma de FotosParcelaController::dto). */
export async function subirFoto({ ensayoId, parcelaId, fecha, nota, formularioId, file, progreso }) {
  const { imagen, miniatura } = await prepararFoto(file);
  const fd = new FormData();
  fd.append('parcela_id', parcelaId);
  fd.append('fecha', fecha);
  if (nota) fd.append('nota', nota);
  if (formularioId) fd.append('formulario_id', formularioId);
  fd.append('nombre', file.name);
  fd.append('imagen', imagen, file.name.replace(/\.[^.]+$/, '') + '.jpg');
  fd.append('miniatura', miniatura, 'miniatura.jpg');
  const { data } = await window.axios.post(`/investigacion/ensayos/${ensayoId}/fotos`, fd, {
    onUploadProgress: (e) => progreso && e.total && progreso(Math.round((e.loaded / e.total) * 100)),
  });
  return data;
}

export function mensajeError(err) {
  const d = err && err.response && err.response.data;
  if (d && d.errors) return Object.values(d.errors).flat().join(' ');
  if (d && d.message) return d.message;
  return (err && err.message) || 'No se pudo subir la foto.';
}
