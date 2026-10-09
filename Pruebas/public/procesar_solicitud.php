<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once("../config/conexion.php");
require_once("../vendor/autoload.php");

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$conn = conexion::conectar();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Acceso no permitido");
}

/* =========================
   CAPTURAR Y VALIDAR DATOS
========================= */
$nombre      = trim($_POST['nombre_solicitante'] ?? '');
$institucion = trim($_POST['institucion'] ?? '');
$correo      = trim($_POST['correo'] ?? '');
$telefono    = trim($_POST['telefono'] ?? '');
$fecha       = trim($_POST['fecha_visita'] ?? '');
$hora        = trim($_POST['hora_visita'] ?? '');
$id_nivel    = trim($_POST['id_nivel'] ?? '');
$areas       = $_POST['areas'] ?? [];

if (
    empty($nombre) ||
    empty($institucion) ||
    empty($correo) ||
    empty($telefono) ||
    empty($fecha) ||
    empty($hora) ||
    empty($id_nivel)
) {
    die("Todos los campos son obligatorios.");
}

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    die("Correo electrónico inválido.");
}

if (empty($_POST['cantidad_visitantes'])) {
    die("Debe ingresar la cantidad de visitantes.");
}

$cantidad = (int) $_POST['cantidad_visitantes'];

if ($cantidad <= 0) {
    die("Cantidad de visitantes inválida.");
}

if (empty($areas)) {
    die("Debe seleccionar al menos un área.");
}

/* =========================
   VALIDAR ARCHIVO
========================= */
if (!isset($_FILES['carta_pdf']) || $_FILES['carta_pdf']['error'] != 0) {
    die("Debe adjuntar la carta.");
}

$extension = strtolower(pathinfo($_FILES['carta_pdf']['name'], PATHINFO_EXTENSION));

if (!in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'])) {
    die("Solo se permiten archivos PDF, JPG o PNG.");
}

/* =========================
   GUARDAR ARCHIVO
========================= */
$carpeta = __DIR__ . "/../uploads/cartas/";

if (!is_dir($carpeta)) {
    mkdir($carpeta, 0777, true);
}

$nombreOriginal = pathinfo($_FILES['carta_pdf']['name'], PATHINFO_FILENAME);
$nombreLimpio   = preg_replace('/[^a-zA-Z0-9_-]/', '_', $nombreOriginal);
$nombreArchivo  = time() . "_" . $nombreLimpio . "." . $extension;
$rutaDestino    = $carpeta . $nombreArchivo;

if (!move_uploaded_file($_FILES['carta_pdf']['tmp_name'], $rutaDestino)) {
    die("No se pudo guardar el archivo.");
}

/* =========================
   CREAR NUEVO SOLICITANTE
========================= */
$stmt = $conn->prepare("
    INSERT INTO solicitantes (
        nombre_solicitante,
        nombre_institucion,
        correo,
        telefono
    )
    VALUES (?, ?, ?, ?)
");

$stmt->execute([
    $nombre,
    $institucion,
    $correo,
    $telefono
]);

$id_solicitante = $conn->lastInsertId();

/* ========================
   INSERTAR SOLICITUD
========================= */
$stmt = $conn->prepare("
    INSERT INTO solicitudes
    (id_solicitante, fecha_visita, hora_visita, cantidad_visitantes, id_nivel, ruta_carta_pdf, nombre_archivo_pdf, id_estado)
    VALUES (?, ?, ?, ?, ?, ?, ?, 4)
");
$stmt->execute([
    $id_solicitante,
    $fecha,
    $hora,
    $cantidad,
    $id_nivel,
    $rutaDestino,
    $nombreArchivo
]);

$id_solicitud = $conn->lastInsertId();

$visitaMuseo = isset($_POST['visita_museo']);
$totalMuseo = 0;

$listaRuta = null;
$listaNombreArchivo = null;

if ($visitaMuseo) {
    $cant_extranjeros      = max(0, (int)($_POST['cant_extranjeros'] ?? 0));
    $cant_adultos          = max(0, (int)($_POST['cant_adultos'] ?? 0));
    $cant_adultos_mayores  = max(0, (int)($_POST['cant_adultos_mayores'] ?? 0));
    $cant_estudiantes      = max(0, (int)($_POST['cant_estudiantes'] ?? 0));
    $cant_ninos            = max(0, (int)($_POST['cant_ninos'] ?? 0));
    $totalMuseo            = floatval($_POST['total_museo'] ?? 0);

    if ($cant_extranjeros + $cant_adultos + $cant_adultos_mayores + $cant_estudiantes + $cant_ninos <= 0) {
        die("Debe ingresar al menos un visitante para la visita al museo.");
    }

    if (!isset($_FILES['listado_pdf']) || $_FILES['listado_pdf']['error'] != 0) {
        die("Debe adjuntar el listado de solicitantes para la visita al museo.");
    }

    $extensionListado = strtolower(pathinfo($_FILES['listado_pdf']['name'], PATHINFO_EXTENSION));
    if ($extensionListado !== 'pdf') {
        die("Debe subir el formato oficial en PDF.");
    }

    $mime = mime_content_type($_FILES['listado_pdf']['tmp_name']);

    if ($mime !== 'application/pdf') {    
        die("El archivo debe ser un PDF válido.");
    }

    $carpetaListado = __DIR__ . "/../uploads/listado/";
    if (!is_dir($carpetaListado)) {
        mkdir($carpetaListado, 0777, true);
    }

    $nombreOriginalListado = pathinfo($_FILES['listado_pdf']['name'], PATHINFO_FILENAME);
    $nombreLimpioListado   = preg_replace('/[^a-zA-Z0-9_-]/', '_', $nombreOriginalListado);
    $nombreArchivoListado  = time() . "_" . $nombreLimpioListado . "." . $extensionListado;
    $rutaListado           = $carpetaListado . $nombreArchivoListado;

    if (!move_uploaded_file($_FILES['listado_pdf']['tmp_name'], $rutaListado)) {
        die("No se pudo guardar el listado.");
    }

    $listaRuta = $rutaListado;
    $listaNombreArchivo = $nombreArchivoListado;

    $stmtMuseo = $conn->prepare("
        INSERT INTO solicitud_museo (
            id_solicitud,
            cant_extranjeros,
            precio_extranjero,
            cant_adultos,
            precio_adulto,
            cant_adultos_mayores,
            precio_adulto_mayor,
            cant_estudiantes,
            precio_estudiante,
            cant_ninos,
            precio_nino,
            total,
            forma_pago,
            moneda,
            nombre_factura,
            nit,
            direccion,
            ruta_carta_pdf,
            nombre_archivo_pdf
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $precio_extranjero      = 80;
    $precio_adulto          = 25;
    $precio_adulto_mayor    = 15;
    $precio_estudiante      = 15;
    $precio_nino            = 15;

    $stmtMuseo->execute([
        $id_solicitud,
        $cant_extranjeros,
        $precio_extranjero,
        $cant_adultos,
        $precio_adulto,
        $cant_adultos_mayores,
        $precio_adulto_mayor,
        $cant_estudiantes,
        $precio_estudiante,
        $cant_ninos,
        $precio_nino,
        $totalMuseo,
        $_POST['forma_pago'] ?? '',
        $_POST['moneda'] ?? '',
        $_POST['nombre_factura'] ?? '',
        $_POST['nit'] ?? '',
        $_POST['direccion'] ?? '',
        $listaRuta,
        $listaNombreArchivo
    ]);
}
/* =========================
   INSERTAR ÁREAS
========================= */
foreach ($areas as $id_area) {
    $stmt = $conn->prepare("
        INSERT INTO solicitud_areas (id_solicitud, id_area)
        VALUES (?, ?)
    ");
    $stmt->execute([$id_solicitud, $id_area]);
}



/* =========================
   ENVIAR CORREO
========================= */
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . "/..");
    $dotenv->load();

    // Obtener nombre del nivel
    $stmtNivel = $conn->prepare("SELECT nombre_nivel FROM niveles_academicos WHERE id_nivel = ?");
    $stmtNivel->execute([$id_nivel]);
    $nivelData   = $stmtNivel->fetch(PDO::FETCH_ASSOC);
    $nombreNivel = htmlspecialchars($nivelData['nombre_nivel'] ?? '');

    // Obtener nombres de áreas
    $stmtAreasNombres = $conn->prepare("
        SELECT GROUP_CONCAT(a.nombre_area SEPARATOR ', ') AS areas
        FROM solicitud_areas sa
        JOIN areas_interes a ON sa.id_area = a.id_area
        WHERE sa.id_solicitud = ?
    ");
    $stmtAreasNombres->execute([$id_solicitud]);
    $areasData   = $stmtAreasNombres->fetch(PDO::FETCH_ASSOC);
    $nombreAreas = htmlspecialchars($areasData['areas'] ?? '');

    // Formatear fecha y hora
    $fechaFormato = date('d/m/Y', strtotime($fecha));
    $horaFormato  = substr($hora, 0, 5);

    // Sanitizar variables para el HTML
    $nombreSafe      = htmlspecialchars($nombre);
    $institucionSafe = htmlspecialchars($institucion);
    $correoSafe      = htmlspecialchars($correo);
    $telefonoSafe    = htmlspecialchars($telefono);
    $cantidadSafe    = htmlspecialchars($cantidad);
    $totalMuseoSafe = htmlspecialchars(number_format($totalMuseo, 2, '.', '.'));

    $seccionMuseo = '';
    if ($visitaMuseo && $totalMuseo > 0) {
        $seccionMuseo = <<<HTML
                      <tr>
                        <td style="padding:9px 0; color:#1b3d2f; font-size:14px; border-bottom:1px solid #d0e8d3;">
                          <strong>💰 Total Museo:</strong>
                          <span style="color:#2e7d32; font-weight:bold;">Q{$totalMuseoSafe}</span>
                        </td>
                      </tr>
        HTML;
    }

    $cuerpoCorreo = <<<HTML
    <!DOCTYPE html>
    <html lang="es">
    <head><meta charset="UTF-8"><title>Solicitud Recibida - CENGICAÑA</title></head>
    <body style="margin:0; padding:0; background-color:#f2f2f2; font-family:Arial, sans-serif;">

          <tr><td align="center">

        <!-- Contenedor principal -->
        <table width="600" cellpadding="0" cellspacing="0"
          style="background:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.08);">

          <!-- Header -->
          <tr>
            <td style="background:linear-gradient(135deg,#2e7d32 0%,#43a047 100%); padding:35px 30px; text-align:center; border-bottom:4px solid #1b5e20;">
              <p style="margin:0; font-size:28px; font-weight:700; color:#ffffff; letter-spacing:1px;">
                CENGICAÑA
              </p>
              <p style="margin:8px 0 0; font-size:13px; color:#c8e6c9; letter-spacing:0.5px;">
                Centro Guatemalteco de Investigación y Capacitación de la Caña de Azúcar
              </p>
            </td>
          </tr>

          <!-- Cuerpo -->
          <tr>
            <td style="padding:35px 30px;">

              <p style="margin:0 0 15px; color:#1b3d2f; font-size:17px;">
                Hola <strong>{$nombreSafe}</strong>,
              </p>

              <p style="margin:0 0 25px; color:#424242; line-height:1.7; font-size:15px;">
                Gracias por tu interés en visitar <strong>Cengicaña</strong>.
                Hemos recibido tu solicitud y actualmente se encuentra en
                proceso de revisión por parte de nuestro equipo.
              </p>

              <!-- Caja de datos -->
              <table width="100%" cellpadding="0" cellspacing="0"
                style="background:#eaf4ec; border:1px solid #b7d3bf; border-radius:12px; margin-bottom:25px;">
                <tr>
                  <td style="padding:20px;">

                    <p style="margin:0 0 15px; color:#2e7d32; font-weight:bold; font-size:16px;">
                      📄 Datos de tu solicitud de visita
                    </p>

                    <table width="100%" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="padding:9px 0; color:#1b3d2f; font-size:14px; border-bottom:1px solid #d0e8d3;">
                          <strong>Solicitante:</strong> {$nombreSafe}
                        </td>
                      </tr>
                      <tr>
                        <td style="padding:9px 0; color:#1b3d2f; font-size:14px; border-bottom:1px solid #d0e8d3;">
                          <strong>Institución:</strong> {$institucionSafe}
                        </td>
                      </tr>
                      <tr>
                        <td style="padding:9px 0; color:#1b3d2f; font-size:14px; border-bottom:1px solid #d0e8d3;">
                          <strong>Email:</strong>
                          <a href="mailto:{$correoSafe}" style="color:#1a73e8; text-decoration:none;">{$correoSafe}</a>
                        </td>
                      </tr>
                      <tr>
                        <td style="padding:9px 0; color:#1b3d2f; font-size:14px; border-bottom:1px solid #d0e8d3;">
                          <strong>Teléfono:</strong>
                          <a href="tel:{$telefonoSafe}" style="color:#1a73e8; text-decoration:none;">{$telefonoSafe}</a>
                        </td>
                      <tr>
                        <td style="padding:9px 0; color:#1b3d2f; font-size:14px; border-bottom:1px solid #d0e8d3;">
                          <strong>Áreas de interés:</strong> {$nombreAreas}
                        </td>
                      </tr>
                      <tr>
                        <td style="padding:9px 0; color:#1b3d2f; font-size:14px; border-bottom:1px solid #d0e8d3;">
                          <strong>Fecha solicitada:</strong> {$fechaFormato}
                        </td>
                      </tr>
                      <tr>
                        <td style="padding:9px 0; color:#1b3d2f; font-size:14px; border-bottom:1px solid #d0e8d3;">
                          <strong>Hora solicitada:</strong> {$horaFormato}
                        </td>
                      </tr>
                      <tr>
                        <td style="padding:9px 0; color:#1b3d2f; font-size:14px; border-bottom:1px solid #d0e8d3;">
                          <strong>Cantidad de asistentes:</strong> {$cantidadSafe}
                        </td>
                      </tr>
                      <tr>
                        <td style="padding:9px 0; color:#1b3d2f; font-size:14px; border-bottom:1px solid #d0e8d3;">
                          <strong>Nivel académico:</strong> {$nombreNivel}
                        </td>
                      </tr>
                      <tr>
                        <td style="padding:9px 0; color:#1b3d2f; font-size:14px;">
                          <strong>Estado:</strong>
                          <span style="color:#f57c00; font-weight:bold;">⏳ Pendiente de aprobación</span>
                        </td>
                      </tr>
                    </table>

                  </td>
                </tr>
              </table>

              <p style="margin:0 0 15px; color:#424242; line-height:1.7; font-size:15px;">
                Nuestro equipo estará evaluando la disponibilidad para coordinar la visita.
                Estaremos enviando próximamente un correo con la confirmación,
                detalles logísticos y fecha asignada.
              </p>

              <p style="margin:0; color:#4f7f5f; font-size:14px; line-height:1.6;">
                Si tienes alguna consulta adicional sobre tu solicitud, no dudes en contactarnos.<br>
                📞 <strong>7828-1003</strong>
              </p>

            </td>
          </tr>

        </table>

        <!-- Footer -->
        <table width="600" cellpadding="0" cellspacing="0"
          style="margin-top:15px; background:#e6e6e6; border-radius:10px; padding:20px;">
          <tr>
            <td style="text-align:center; font-size:12px; color:#2e7d32;">
              <strong>CENGICAÑA - Centro Guatemalteco de Investigación y Capacitación de la Caña de Azúcar</strong>
            </td>
          </tr>
          <tr>
            <td style="text-align:center; font-size:12px; color:#555; padding-top:10px;">
              Este correo fue generado automáticamente por el sistema.
            </td>
          </tr>
          <tr>
            <td style="text-align:center; font-size:12px; color:#555; padding-top:10px;">
              © 2026 CENGICAÑA. Todos los derechos reservados.
            </td>
          </tr>
          <tr>
            <td style="text-align:center; font-size:12px; color:#555; padding-top:5px;">
              Km 92.5 Carretera al Pacífico, Santa Lucía Cotzumalguapa, Escuintla, Guatemala
            </td>
          </tr>
        </table>

      </td></tr>
    </table>
    </body>
    </html>
    HTML;

    // ===================================
    // CORREO PARA EL USUARIO VISITAS (ADMIN)
    // ===================================
    $cuerpoCorreoAdmin = <<<HTML
    <!DOCTYPE html>
    <head>
      <meta charset="UTF-8">
      <title>Nueva Solicitud de Visita - CENGICAÑA</title>
      <html lang="es">
    </head>
    
    <body style="margin:0; padding:0; background-color:#f4f6f8; font-family:Arial, sans-serif;">
    
      <tr>
        <td align="center">
          
          <!-- Contenedor -->
          
          <table width="600" cellpadding="0" cellspacing="0"
          style="background:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 3px 10px rgba(0,0,0,0.08);">
          
          <!-- Header -->
          
          <tr>
            <td style="background:#d32f2f; padding:28px; text-align:center;">
              <p style="margin:0; font-size:24px; font-weight:700; color:#ffffff;">
                Nueva Solicitud de Visita
              </p>
              <p style="margin:6px 0 0; font-size:13px; color:#ffebee;">
                CENGICAÑA
              </p>
            </td>
          </tr>
          
          <!-- Cuerpo -->
          
          <tr>
            <td style="padding:30px;">
              
              <p style="margin:0 0 10px; color:#2e7d32; font-size:16px;">
                <strong>Se ha registrado una nueva solicitud.</strong>
              </p>
              
              <p style="margin:0 0 20px; color:#555; font-size:14px; line-height:1.6;">
                Requiere revisión y aprobación por el equipo de visitas.
              </p>
              
              <!-- Datos -->
              
              <table width="100%" cellpadding="0" cellspacing="0"
              style="border:1px solid #e0e0e0; border-radius:8px; overflow:hidden; margin-bottom:20px;">
              
              <tr style="background:#fafafa;">
                <td colspan="2" style="padding:12px 15px; font-weight:bold; color:#333;">
                  Detalles de la Solicitud
                </td>
              </tr>
              
              <tr>
                <td style="padding:10px 15px; font-size:13px; color:#777; width:45%;">ID Solicitud</td>
                <td style="padding:10px 15px; font-size:14px; color:#333;">#{$id_solicitud}</td>
              </tr>
              
              <tr style="background:#fafafa;">
                <td style="padding:10px 15px; font-size:13px; color:#777;">Solicitante</td>
                <td style="padding:10px 15px; font-size:14px;">{$nombreSafe}</td>
              </tr>
              
              <tr>
                <td style="padding:10px 15px; font-size:13px; color:#777;">Institución</td>
                <td style="padding:10px 15px; font-size:14px;">{$institucionSafe}</td>
              </tr>
              
              <tr style="background:#fafafa;">
                <td style="padding:10px 15px; font-size:13px; color:#777;">Email</td>
                <td style="padding:10px 15px; font-size:14px;">
                  <a href="mailto:{$correoSafe}" style="color:#1976d2; text-decoration:none;">{$correoSafe}</a>
                </td>
              </tr>
              
              <tr>
                <td style="padding:10px 15px; font-size:13px; color:#777;">Teléfono</td>
                <td style="padding:10px 15px; font-size:14px;">
                  <a href="tel:{$telefonoSafe}" style="color:#1976d2; text-decoration:none;">{$telefonoSafe}</a>
                </td>
              </tr>
              
              <tr style="background:#fafafa;">
                <td style="padding:10px 15px; font-size:13px; color:#777;">Áreas de interés</td>
                <td style="padding:10px 15px; font-size:14px;">{$nombreAreas}</td>
              </tr>
              
              <tr>
                <td style="padding:10px 15px; font-size:13px; color:#777;">Fecha</td>
                <td style="padding:10px 15px; font-size:14px;">{$fechaFormato}</td>
              </tr>
              
              <tr style="background:#fafafa;">
                <td style="padding:10px 15px; font-size:13px; color:#777;">Hora</td>
                <td style="padding:10px 15px; font-size:14px;">{$horaFormato}</td>
              </tr>
              
              <tr>
                <td style="padding:10px 15px; font-size:13px; color:#777;">Asistentes</td>
                <td style="padding:10px 15px; font-size:14px;">{$cantidadSafe}</td>
              </tr>
              
              <tr style="background:#fafafa;">
                <td style="padding:10px 15px; font-size:13px; color:#777;">Nivel académico</td>
                <td style="padding:10px 15px; font-size:14px;">{$nombreNivel}</td>
              </tr>
              
              <tr>
                <td style="padding:10px 15px; font-size:13px; color:#777;">Visita al Museo</td>
                <td style="padding:10px 15px; font-size:14px;">{($visitaMuseo ? 'Sí' : 'No')}</td>
              </tr>
              
              {$seccionMuseo}
              
              <tr style="background:#fff3e0;">
                <td style="padding:10px 15px; font-size:13px; color:#777;">Estado</td>
                <td style="padding:10px 15px; font-size:14px; color:#ef6c00; font-weight:bold;">
                  Pendiente de aprobación
                </td>
              </tr>
              
            </table>
            
            <p style="margin:0 0 20px; color:#555; font-size:14px;">
              Ingresa al sistema para revisar y gestionar esta solicitud.
            </p>
            
            <!-- Botón -->
            
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td align="center">
                  <a href="#" style="display:inline-block; background:#d32f2f; color:white; padding:12px 25px; text-decoration:none; border-radius:6px; font-weight:bold; font-size:14px;">
                    Revisar Solicitud
                  </a>
                </td>
              </tr>
            </table>
            
          </td>
        </tr>
        
      </table>
      
      <!-- Footer -->
      
      <table width="600" cellpadding="0" cellspacing="0"
      style="margin-top:12px; text-align:center;">
      
      <tr>
        <td style="font-size:12px; color:#888;">
          Correo automático - No responder
        </td>
      </tr>
      
      <tr>
        <td style="font-size:12px; color:#aaa; padding-top:5px;">
          © 2026 CENGICAÑA
        </td>
      </tr>
      
    </table>
    
  </td>
</tr>
</table>
</body>
</html>
HTML;

 // ===================================
    // ENVIAR CORREO AL SOLICITANTE
    // ===================================
    $mail = new PHPMailer(true);
 
    $mail->isSMTP();
    $mail->Host       = $_ENV['MAIL_HOST'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $_ENV['MAIL_USER'];
    $mail->Password   = $_ENV['MAIL_PASS'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $_ENV['MAIL_PORT'];
 
    $mail->setFrom($_ENV['MAIL_USER'], $_ENV['MAIL_NAME']);
    $mail->addAddress($correo, $nombre);
 
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = 'Solicitud de Visita Ingresada - CENGICAÑA';
    $mail->Body    = $cuerpoCorreo;
    $mail->AltBody = strip_tags($cuerpoCorreo);
    
    $mail->send();
    
    // Obtener email del usuario visitas (supervisor) desde la base de datos usuarios_menu
    $emailAdmin = null;
    try {
$connUsuarios = Conexion::conectarUsuariosMenu();
$stmtEmailAdmin = $connUsuarios->prepare("
 SELECT u.correo, r.nombre_rol
FROM usuarios u
INNER JOIN usuario_modulo um ON um.usuario_id = u.id
INNER JOIN modulos m ON m.id = um.modulo_id AND LOWER(m.nombre) = 'solicitud de visitas'
INNER JOIN roles r ON r.id = um.rol_id
WHERE r.nombre_rol = 'Visitante'
LIMIT 1;
");
 
        $stmtEmailAdmin->execute();
        $usuarioVisitas = $stmtEmailAdmin->fetch(PDO::FETCH_ASSOC);
        $emailAdmin = $usuarioVisitas['correo'] ?? null;
    } catch (Exception $e) {
        $emailAdmin = null;
    }
 
    if (!empty($emailAdmin)) {
        $mail->clearAddresses(); // Limpiar direcciones anteriores
        $mail->addAddress($emailAdmin, 'Usuario Visitas');
 
        $mail->Subject = 'Nueva Solicitud de Visita - CENGICAÑA [ID: ' . $id_solicitud . ']';
        $mail->Body    = $cuerpoCorreoAdmin;
        $mail->AltBody = strip_tags($cuerpoCorreoAdmin);
 
        $mail->send();
    }
 
} catch (Exception $e) {
    error_log("Error correo: " . $mail->ErrorInfo);
}
 
/* =========================
   REDIRECCIONAR
========================= */
header("Location: solicitud_visita.php?ok=1");
exit;
?>