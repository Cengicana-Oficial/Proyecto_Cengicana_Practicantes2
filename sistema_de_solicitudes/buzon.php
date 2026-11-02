<?php
declare(strict_types=1);

/*
 * Buzon de ideas (publico)
 * ------------------------
 * Cualquier persona con el link puede enviar una idea: NO requiere sesion.
 * Las ideas se revisan dentro del modulo en index.php?view=buzon.
 */

session_start();

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';

$errorEnvio = '';
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;

    try {
        // Campo trampa: invisible para personas, los bots suelen llenarlo.
        if (trim((string) ($_POST['sitio_web'] ?? '')) !== '') {
            header('Location: buzon.php?enviada=1#formulario');
            exit;
        }

        $ultimoEnvio = (int) ($_SESSION['buzon_ultimo_envio'] ?? 0);
        if ($ultimoEnvio > 0 && time() - $ultimoEnvio < BUZON_ESPERA_SEGUNDOS) {
            throw new RuntimeException('Acabas de enviar una idea. Espera unos segundos antes de enviar otra.');
        }

        $idea = validar_idea($_POST);
        $foto = guardar_foto_idea($_FILES['foto'] ?? null);

        $pdo->prepare(
            'INSERT INTO ideas (area, area_otra, situacion, propuesta, beneficio, foto_ruta, foto_mime, anonimo, nombre, correo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $idea['area'],
            $idea['area_otra'],
            $idea['situacion'],
            $idea['propuesta'],
            $idea['beneficio'],
            $foto['ruta'] ?? null,
            $foto['mime'] ?? null,
            $idea['anonimo'],
            $idea['nombre'],
            $idea['correo'],
        ]);

        $_SESSION['buzon_ultimo_envio'] = time();
        header('Location: buzon.php?enviada=1#formulario');
        exit;
    } catch (RuntimeException $exception) {
        $errorEnvio = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('[buzon] ' . $exception->getMessage());
        $errorEnvio = 'No pudimos guardar tu idea en este momento. Intenta de nuevo en unos minutos.';
    }
}

$enviada = isset($_GET['enviada']) && $errorEnvio === '';
$oldValue = static fn(string $key): string => e((string) ($old[$key] ?? ''));
$oldArea = (string) ($old['area'] ?? '');
$oldAnonimo = !empty($old['anonimo']);
$assetVersion = static fn(string $path): int => (int) @filemtime(__DIR__ . '/' . $path);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Buzón de ideas · Cada mejora cuenta</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700&family=Source+Sans+3:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/buzon.css?v=<?= $assetVersion('assets/css/buzon.css') ?>">
</head>
<body>

<header class="hero">
  <div class="hero-inner">
    <div>
      <span class="tag">Buzón de ideas · Cada mejora cuenta</span>
      <h1>¡Ayúdanos a mejorar nuestro centro de investigación!</h1>
      <p>Invitamos a todo el personal, desde campo hasta las áreas técnicas, profesionales y administrativas, a compartir sus ideas, sugerencias y oportunidades de mejora.</p>
      <p>No importa el puesto que desempeñes: tu experiencia diaria es valiosa y puede ayudarnos a trabajar mejor, fortalecer nuestros procesos y construir un entorno más seguro, eficiente y colaborativo.</p>
      <p class="cierre">Una idea puede convertirse en una gran mejora.</p>
      <a class="btn" href="#formulario">Compartir mi idea</a>
    </div>
    <img class="hero-img" src="assets/img/buzon-hero.jpg" alt="Foco encendido con plantas de caña de azúcar en su interior">
  </div>
</header>

<main>
  <section id="para-quien">
    <h2>Tu voz cuenta, estés donde estés</h2>
    <p class="intro">Las mejores ideas suelen venir de quien hace el trabajo todos los días.</p>
    <div class="roles">
      <div class="role"><svg viewBox="0 0 48 48" aria-hidden="true"><path d="M24 40V18M24 18c-8 0-12-5-12-12 8 0 12 5 12 12zM24 28c7 0 11-4 11-10-7 0-11 4-11 10z"/><path d="M8 40h32"/></svg><strong>Campo</strong></div>
      <div class="role"><svg viewBox="0 0 48 48" aria-hidden="true"><path d="M18 6h12M20 6v14L9 40a3 3 0 0 0 3 4h24a3 3 0 0 0 3-4L28 20V6"/><path d="M14 32h20"/></svg><strong>Áreas técnicas</strong></div>
      <div class="role"><svg viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="15" r="8"/><path d="M8 42c0-10 7-15 16-15s16 5 16 15"/></svg><strong>Profesionales</strong></div>
      <div class="role"><svg viewBox="0 0 48 48" aria-hidden="true"><rect x="9" y="8" width="30" height="34" rx="3"/><path d="M16 18h16M16 26h16M16 34h9"/></svg><strong>Administrativas</strong></div>
    </div>
  </section>

  <section id="categorias">
    <h2>¿Sobre qué puedes opinar?</h2>
    <p class="intro">Elige el tema que más se acerque a tu idea. Si no encaja en ninguno, usa «Otra».</p>

    <div class="cats">
      <!-- Para agregar fotos: guarda la imagen en assets/img/ y agrega --img:url('assets/img/archivo.jpg') al style de .photo. -->
      <article class="cat">
        <div class="photo" style="--c1:#3e8e5a;--c2:#1f5a3a">
          <svg viewBox="0 0 48 48" aria-hidden="true"><path d="M6 38c6-10 12-14 18-14s12 4 18 14"/><path d="M24 24V10M24 10l-6 6M24 10l6 6"/></svg>
        </div>
        <div class="txt"><h3>Procesos y trabajo de campo</h3><p>Optimizar actividades, reducir tiempos, facilitar labores y organizar mejor el trabajo diario.</p></div>
      </article>

      <article class="cat">
        <div class="photo" style="--c1:#2d7f8e;--c2:#164650">
          <svg viewBox="0 0 48 48" aria-hidden="true"><path d="M18 6h12M20 6v14L9 40a3 3 0 0 0 3 4h24a3 3 0 0 0 3-4L28 20V6"/><path d="M14 32h20"/></svg>
        </div>
        <div class="txt"><h3>Investigación y calidad</h3><p>Mejorar protocolos, registros, manejo de muestras, ensayos, resultados y calidad de los datos.</p></div>
      </article>

      <article class="cat">
        <div class="photo" style="--c1:#d9962b;--c2:#8a5410">
          <svg viewBox="0 0 48 48" aria-hidden="true"><path d="M24 5l16 6v12c0 10-7 17-16 20C15 40 8 33 8 23V11z"/><path d="M17 24l5 5 9-10"/></svg>
        </div>
        <div class="txt"><h3>Seguridad y bienestar</h3><p>Prevenir accidentes, mejorar las condiciones de trabajo y cuidar la salud del equipo.</p></div>
      </article>

      <article class="cat">
        <div class="photo" style="--c1:#7a9a2e;--c2:#41561a">
          <svg viewBox="0 0 48 48" aria-hidden="true"><path d="M8 40V24M20 40V14M32 40V28M44 40H4"/><path d="M8 18l12-8 12 10 10-10"/></svg>
        </div>
        <div class="txt"><h3>Recursos y eficiencia</h3><p>Aprovechar mejor materiales, equipos, instalaciones, tiempo y presupuesto.</p></div>
      </article>

      <article class="cat">
        <div class="photo" style="--c1:#5a6fb0;--c2:#2a3568">
          <svg viewBox="0 0 48 48" aria-hidden="true"><circle cx="16" cy="18" r="6"/><circle cx="33" cy="20" r="5"/><path d="M5 40c0-8 5-12 11-12s11 4 11 12M28 31c2-1 4-1.5 5-1.5 6 0 10 4 10 11"/></svg>
        </div>
        <div class="txt"><h3>Comunicación y trabajo en equipo</h3><p>Mejorar la colaboración entre departamentos, el ambiente laboral y la comunicación interna.</p></div>
      </article>
    </div>
  </section>

  <section id="formulario">
    <div class="form-wrap">
      <?php if ($enviada): ?>
        <div class="resultado" role="status">
          <h3>¡Gracias! Recibimos tu idea</h3>
          <p>Tu propuesta llegó al equipo responsable del buzón. Cada idea se revisa con atención.</p>
          <a class="btn" href="buzon.php#formulario">Enviar otra idea</a>
        </div>
      <?php else: ?>
      <div class="form-head">
        <h2>Comparte tu idea</h2>
        <p>Tu experiencia, tus ideas y tus propuestas pueden hacer la diferencia. Puedes presentar una idea sencilla o una propuesta más desarrollada.</p>
      </div>

      <?php if ($errorEnvio !== ''): ?>
        <div class="alerta-error" role="alert">
          <?= e($errorEnvio) ?>
          <?php if (!empty($_FILES['foto']['name'])): ?><br>Si habías elegido una foto, vuelve a seleccionarla.<?php endif; ?>
        </div>
      <?php endif; ?>

      <form id="formIdea" action="buzon.php#formulario" method="post" enctype="multipart/form-data" novalidate>
        <div class="trampa" aria-hidden="true">
          <label for="sitio_web">No llenes este campo</label>
          <input type="text" id="sitio_web" name="sitio_web" tabindex="-1" autocomplete="off">
        </div>

        <fieldset>
          <legend>1. Área de mejora</legend>
          <div class="choices" id="areas">
            <?php foreach (BUZON_AREAS as $index => $area): ?>
              <label class="choice"><input type="radio" name="area" value="<?= e($area) ?>"<?= $index === 0 ? ' required' : '' ?><?= $oldArea === $area ? ' checked' : '' ?>><span><?= e($area) ?></span></label>
            <?php endforeach; ?>
          </div>
          <div id="otraWrap" hidden>
            <label for="otra" class="label" style="font-size:1rem">¿Cuál?</label>
            <input type="text" id="otra" name="area_otra" maxlength="80" placeholder="Escribe el tema de tu idea" value="<?= $oldValue('area_otra') ?>">
          </div>
          <div class="error" id="errArea" style="display:none">Elige un área de mejora.</div>
          <div class="ideas" id="ideas" hidden></div>
        </fieldset>

        <fieldset>
          <label class="label" for="situacion">2. ¿Qué situación o problema has identificado?</label>
          <p class="hint">Describe qué pasa, dónde y con qué frecuencia.</p>
          <textarea id="situacion" name="situacion" maxlength="800" required><?= $oldValue('situacion') ?></textarea>
          <div class="count"><span data-count="situacion">0</span>/800</div>
          <div class="error">Cuéntanos brevemente qué has observado.</div>
        </fieldset>

        <fieldset>
          <label class="label" for="propuesta">3. ¿Cuál es tu propuesta de mejora?</label>
          <p class="hint">No tiene que ser perfecta. Una idea inicial también vale.</p>
          <textarea id="propuesta" name="propuesta" maxlength="800" required><?= $oldValue('propuesta') ?></textarea>
          <div class="count"><span data-count="propuesta">0</span>/800</div>
          <div class="error">Escribe tu propuesta de mejora.</div>
        </fieldset>

        <fieldset>
          <label class="label" for="beneficio">4. ¿Qué beneficio tendría esta propuesta?</label>
          <p class="hint">Por ejemplo: ahorro de tiempo, menos riesgos, mejores datos, menor costo.</p>
          <textarea id="beneficio" name="beneficio" maxlength="500" required><?= $oldValue('beneficio') ?></textarea>
          <div class="count"><span data-count="beneficio">0</span>/500</div>
          <div class="error">Indica qué se lograría con tu idea.</div>
        </fieldset>

        <fieldset>
          <span class="label">5. Foto de apoyo (opcional)</span>
          <p class="hint">Una imagen ayuda a entender el problema. Formatos JPG o PNG, hasta 5 MB.</p>
          <label class="drop" for="foto">
            <svg viewBox="0 0 48 48" aria-hidden="true"><rect x="6" y="12" width="36" height="28" rx="4"/><circle cx="24" cy="26" r="7"/><path d="M17 12l3-5h8l3 5"/></svg>
            <span><strong>Toca para elegir o tomar una foto</strong><br><span id="nombreFoto" style="color:var(--ink-soft);font-size:.93rem">Ningún archivo seleccionado</span></span>
            <input type="file" id="foto" name="foto" accept="image/jpeg,image/png">
          </label>
          <div class="error" id="errFoto">El archivo debe ser JPG o PNG y pesar menos de 5 MB.</div>
          <img id="preview" alt="Vista previa de la foto seleccionada">
          <button type="button" id="quitarFoto">Quitar foto</button>
        </fieldset>

        <fieldset>
          <label class="anon" for="anonimo">
            <input type="checkbox" id="anonimo" name="anonimo" value="1"<?= $oldAnonimo ? ' checked' : '' ?>>
            <span><strong>Prefiero compartir mi sugerencia de forma anónima.</strong><br>
            <span style="color:var(--ink-soft);font-size:.93rem">Si no marcas esta casilla, puedes dejar tus datos para que te contactemos.</span></span>
          </label>
          <div id="datosPersonales"<?= $oldAnonimo ? ' hidden' : '' ?>>
            <div>
              <label for="nombre">Nombre (opcional)</label>
              <input type="text" id="nombre" name="nombre" maxlength="80" autocomplete="name" value="<?= $oldValue('nombre') ?>">
            </div>
            <div>
              <label for="correo">Correo (opcional)</label>
              <input type="email" id="correo" name="correo" maxlength="120" autocomplete="email" value="<?= $oldValue('correo') ?>">
              <div class="error">Escribe un correo válido.</div>
            </div>
          </div>
        </fieldset>

        <div class="actions">
          <button type="submit" class="btn" id="btnEnviar">Enviar mi idea</button>
          <button type="reset" class="btn secondary" id="btnLimpiar">Limpiar formulario</button>
        </div>

        <p class="note">Tu idea llegará al equipo responsable del buzón. Si eliges enviarla de forma anónima, no se guarda tu nombre ni tu correo.</p>
      </form>
      <?php endif; ?>
    </div>
  </section>
</main>

<footer>Buzón de ideas · Cada mejora cuenta · CENGICANA</footer>

<script src="assets/js/buzon.js?v=<?= $assetVersion('assets/js/buzon.js') ?>"></script>
</body>
</html>
