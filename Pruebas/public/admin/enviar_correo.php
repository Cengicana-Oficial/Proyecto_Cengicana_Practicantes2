<?php
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once("../../config/conexion.php");
require_once("../../config/auth.php"); visitas_exigir_permiso(["gestionar_solicitudes", "enviar_correos"], true);
require_once("../../vendor/autoload.php");

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$conn = conexion::conectar();

// Validar sesión
if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

// Validar ID
$id_solicitud = $_POST['id_solicitud'] ?? null;

if (!$id_solicitud) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de solicitud no proporcionado']);
    exit;
}

/* =========================
  OBTENER DATOS
========================= */
$sql = "
SELECT 
    s.id_solicitud,
    s.correo_enviado,
    so.correo AS correo_solicitante,
    so.nombre_solicitante,
    so.nombre_institucion,
    s.fecha_visita,
    s.hora_visita,
    s.cantidad_visitantes,
    n.nombre_nivel,
    e.nombre_estado,
    GROUP_CONCAT(DISTINCT ai.nombre_area SEPARATOR ', ') AS areas
FROM solicitudes s
INNER JOIN solicitantes so      ON s.id_solicitante = so.id_solicitante
INNER JOIN estados e            ON s.id_estado      = e.id_estado
INNER JOIN niveles_academicos n ON s.id_nivel       = n.id_nivel
LEFT JOIN solicitud_areas sa    ON s.id_solicitud   = sa.id_solicitud
LEFT JOIN areas_interes ai      ON sa.id_area       = ai.id_area
WHERE s.id_solicitud = ?
GROUP BY s.id_solicitud
";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id_solicitud]);
    $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$solicitud) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Solicitud no encontrada']);
        exit;
    }

    $estado      = strtoupper(trim($solicitud['nombre_estado']));
    $nombre      = htmlspecialchars($solicitud['nombre_solicitante']);
    $institucion = htmlspecialchars($solicitud['nombre_institucion']);
    $fecha       = htmlspecialchars($solicitud['fecha_visita']);
    $hora        = htmlspecialchars($solicitud['hora_visita']);
    $visitantes  = htmlspecialchars($solicitud['cantidad_visitantes']);
    $areas       = htmlspecialchars($solicitud['areas']);
    $nivel       = htmlspecialchars($solicitud['nombre_nivel']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error en la base de datos: ' . $e->getMessage()]);
    exit;
}

/* =========================
  FUNCIÓN ENVIAR CORREO
========================= */
function enviarCorreo($destino, $asunto, $mensajeHTML)
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $_ENV['MAIL_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['MAIL_USER'];
        $mail->Password   = $_ENV['MAIL_PASS'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $_ENV['MAIL_PORT'];

        $mail->setFrom($_ENV['MAIL_USER'], $_ENV['MAIL_NAME']);
        $mail->addAddress($destino);

        // Validar que el logo existe
        $logoPath = realpath(__DIR__ . '/../../Pruebas/assets/img/logo.png');
        if ($logoPath && file_exists($logoPath)) {
            $mail->addEmbeddedImage(
                $logoPath,              // Ruta absoluta
                'logo_cengicana',       // CID que usas en el HTML
                'logo.png',             // Nombre del archivo
                'base64',               // Codificación
                'image/png'             // Tipo MIME
            );
        }

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $asunto;
        $mail->Body    = $mensajeHTML;

        return $mail->send();

    } catch (Exception $e) {
        return false;
    }
}

/* =========================
  FOOTER
========================= */
$footer = <<<HTML
<table width="600" cellpadding="0" cellspacing="0" style="margin-top:15px; background:#e6e6e6; border-radius:10px; padding:20px;">
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
HTML;

/* =========================================================
  APROBADO
========================================================= */
if ($estado === 'APROBADO') {

    $mensajeSolicitante = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Solicitud de Visita Aprobada - CENGICAÑA</title>
</head>

<body style="margin:0; padding:0; background-color:#f2f2f2; font-family: Arial, sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="padding:20px;">
  <tr>
    <td align="center">

      <!-- CONTENEDOR PRINCIPAL -->
      <table width="600" cellpadding="0" cellspacing="0"
        style="background:#ffffff; border-radius:12px; padding:30px;">

        <!-- LOGO -->
        <tr>
          <td align="center" style="padding-bottom:20px;">
            <img src="cid:logo_cengicana"
                alt="CENGICAÑA"
                width="180"
                style="display:block; margin:auto;">
          </td>
        </tr>

        <!-- SALUDO -->
        <tr>
          <td style="color:#1b3d2f; font-size:16px;">
            Hola <strong>{$nombre}</strong>,
          </td>
        </tr>

        <!-- MENSAJE -->
        <tr>
          <td style="padding-top:15px; color:#1b3d2f; line-height:1.7;">
            Nos complace informarte que tu solicitud de visita a
            <strong>CENGICAÑA</strong> ha sido
            <span style="color:#2e7d32; font-weight:bold;">
              aprobada ✅
            </span>.
            <br><br>
            A continuación encontrarás los detalles registrados de tu visita.
          </td>
        </tr>

        <!-- CAJA DATOS -->
        <tr>
          <td style="padding-top:25px;">
            <table width="100%" cellpadding="0" cellspacing="0"
              style="background:#f4faf5; border:1px solid #d6ead9; border-radius:12px; padding:20px;">
              <tr>
                <td style="font-size:18px; color:#2e7d32; font-weight:bold; padding-bottom:15px;">
                  📄 Datos de la visita
                </td>
              </tr>
              <tr><td style="padding:8px 0; color:#1b3d2f;"><strong>Institución:</strong> {$institucion}</td></tr>
              <tr><td style="padding:8px 0; color:#1b3d2f;"><strong>Nivel académico:</strong> {$nivel}</td></tr>
              <tr><td style="padding:8px 0; color:#1b3d2f;"><strong>Áreas de interés:</strong> {$areas}</td></tr>
              <tr><td style="padding:8px 0; color:#1b3d2f;"><strong>Fecha de visita:</strong> {$fecha}</td></tr>
              <tr><td style="padding:8px 0; color:#1b3d2f;"><strong>Hora:</strong> {$hora}</td></tr>
              <tr><td style="padding:8px 0; color:#1b3d2f;"><strong>Cantidad de visitantes:</strong> {$visitantes}</td></tr>
              <tr><td style="padding:8px 0; color:#1b3d2f;"><strong>Estado:</strong> <span style="color:#2e7d32; font-weight:bold;">Aprobado</span></td></tr>
              <tr><td style="padding:8px 0; color:#1b3d2f;"><strong>Ubicación:</strong><br>Kilómetro 92.5 Carretera al Pacífico, Santa Lucía Cotzumalguapa, Escuintla, Guatemala</td></tr>
            </table>
          </td>
        </tr>

        <!-- INFORMACIÓN -->
        <tr>
          <td style="padding-top:20px; color:#1b3d2f; line-height:1.7;">
            Te recomendamos presentarte con <strong>10 minutos de anticipación</strong>.
            <br><br>
            Nuestro equipo estará preparado para brindarte la mejor experiencia durante tu visita.
          </td>
        </tr>

        <!-- CONTACTO -->
        <tr>
          <td style="padding-top:20px; color:#4f7f5f; line-height:1.6;">
            Si tienes alguna consulta adicional, no dudes en comunicarte con nosotros.
            <br><br>
            📞 Para asistencia directa: <strong style="color:#1a73e8;">7828-1003</strong>
          </td>
        </tr>

      </table>

      {$footer}

    </td>
  </tr>
</table>

</body>
</html>

HTML;

    $correoEnviado = enviarCorreo(
        $solicitud['correo_solicitante'],
        "Solicitud de visita aprobada - CENGICAÑA",
        $mensajeSolicitante
    );

    if ($correoEnviado) {
        try {
            $update = $conn->prepare("
                UPDATE solicitudes
                SET correo_enviado = 1
                WHERE id_solicitud = ?
            ");
            $update->execute([$id_solicitud]);

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Correo enviado y registrado correctamente',
                'id_solicitud' => $id_solicitud
            ]);
            exit;

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Correo enviado pero no se registró en BD: ' . $e->getMessage()
            ]);
            exit;
        }
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Error al enviar el correo. Verifica credenciales SMTP'
        ]);
        exit;
    }
}

/* =========================================================
  RECHAZADO
========================================================= */
if ($estado === 'RECHAZADO') {

    $mensajeRechazo = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Solicitud de Visita Rechazada - CENGICAÑA</title>
</head>

<body style="margin:0; padding:0; background-color:#f2f2f2; font-family: Arial, sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="padding:20px;">
  <tr>
    <td align="center">

      <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:10px; padding:30px;">

        <tr>
          <td style="color:#1b3d2f; font-size:16px;">
            Estimado/a <strong>{$nombre}</strong>,
          </td>
        </tr>

        <tr>
          <td style="padding-top:15px; color:#1b3d2f; line-height:1.6;">
            Reciba un cordial saludo de parte de <strong>Cengicaña</strong>.
            <br><br>
            Luego de la revisión correspondiente, le informamos que su solicitud de visita ha sido
            <strong style="color:#c62828;">rechazada</strong>.
          </td>
        </tr>

        <tr>
          <td style="padding-top:20px; color:#1b3d2f; line-height:1.6;">
            Le invitamos cordialmente a realizar las correcciones necesarias y enviar nuevamente su solicitud a través del siguiente enlace:
          </td>
        </tr>

        <tr>
          <td style="padding-top:15px; text-align:center;">
            <a href="https://www.cengicana.org/programa-tu-visita/"
              style="background:#2e7d32; color:#ffffff; padding:12px 20px; text-decoration:none; border-radius:8px; display:inline-block; font-weight:bold;">
              Reenviar solicitud
            </a>
          </td>
        </tr>

        <tr>
          <td style="padding-top:20px; color:#4f7f5f;">
            Si requiere mayor información o asistencia adicional, puede comunicarse al <strong>7828-1003</strong>.
          </td>
        </tr>

        <tr>
          <td style="padding-top:15px; color:#1b3d2f;">
            Agradecemos su comprensión y quedamos atentos a su nueva solicitud.
            <br><br>
            Saludos cordiales,
          </td>
        </tr>

      </table>

      {$footer}

    </td>
  </tr>
</table>

</body>
</html>
HTML;

    $correoEnviado = enviarCorreo(
        $solicitud['correo_solicitante'],
        "Solicitud de visita rechazada - CENGICAÑA",
        $mensajeRechazo
    );

    if ($correoEnviado) {
        try {
            $update = $conn->prepare("
                UPDATE solicitudes
                SET correo_enviado = 1
                WHERE id_solicitud = ?
            ");
            $update->execute([$id_solicitud]);

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Correo enviado y registrado correctamente',
                'id_solicitud' => $id_solicitud
            ]);
            exit;

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Correo enviado pero no se registró en BD: ' . $e->getMessage()
            ]);
            exit;
        }
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Error al enviar el correo. Verifica credenciales SMTP'
        ]);
        exit;
    }
}

// Si el estado no es APROBADO ni RECHAZADO
http_response_code(400);
echo json_encode([
    'success' => false,
    'error' => 'Estado de solicitud no válido para envío de correo'
]);
?>