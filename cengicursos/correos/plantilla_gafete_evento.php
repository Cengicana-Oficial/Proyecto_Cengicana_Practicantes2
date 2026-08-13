<?php

// Correo con el gafete/badge del evento adjunto en PDF, enviado desde
// cengicursos/enviar_gafetes_evento.php (envio individual o masivo a
// participantes seleccionados en el modal de "Participantes" de
// cengicursos/eventos_qr.php).
//
// Se espera que este archivo se incluya con las variables $participanteNombre,
// $eventoNombre y $codigoQr ya en scope (armadas por quien hace el include).
// El PDF del gafete se adjunta aparte via cengi_enviar_correo($adjuntos), este
// archivo solo arma el cuerpo del correo.
//
// Mismo layout visual (header verde con logo embebido, tarjeta blanca, footer
// gris) que cengicursos/correos/plantilla_recordatorio_cupo_participante.php,
// usado aqui como base/referencia.

$nombreParticipanteHtml = htmlspecialchars((string) ($participanteNombre ?? 'Participante'), ENT_QUOTES, 'UTF-8');
$nombreEventoHtml = htmlspecialchars((string) ($eventoNombre ?? 'el evento'), ENT_QUOTES, 'UTF-8');
$codigoQrHtml = htmlspecialchars((string) ($codigoQr ?? ''), ENT_QUOTES, 'UTF-8');

$html = "
<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Gafete del evento</title>
</head>

<body style='
    margin:0;
    padding:0;
    background-color:#f4f6f8;
    font-family:Arial, Helvetica, sans-serif;
'>

<table width='100%' cellpadding='0' cellspacing='0'
       style='background-color:#f4f6f8; padding:40px 15px;'>

    <tr>
        <td align='center'>

            <table width='600'
                   cellpadding='0'
                   cellspacing='0'
                   style='
                       width:100%;
                       max-width:600px;
                       background:#ffffff;
                       border-radius:10px;
                       overflow:hidden;
                       box-shadow:0 2px 8px rgba(0,0,0,0.08);
                   '>

                <!-- ENCABEZADO -->
                <tr>
                    <td style='
                        background:#176b3a;
                        padding:30px;
                        text-align:center;
                        color:#ffffff;
                    '>

                        <table cellpadding='0' cellspacing='0' style='margin:0 auto;'>
                            <tr>
                                <td style='
                                    background:#ffffff;
                                    border-radius:12px;
                                    padding:12px 22px;
                                '>
                                    <img src='cid:logo_cengicana'
                                         alt='CENGICAÑA'
                                         style='
                                             display:block;
                                             max-width:170px;
                                             height:auto;
                                         '>
                                </td>
                            </tr>
                        </table>

                        <p style='
                            margin:14px 0 0;
                            font-size:14px;
                        '>
                            Eventos técnicos
                        </p>

                    </td>
                </tr>


                <!-- CONTENIDO -->
                <tr>
                    <td style='padding:40px 35px;'>

                        <h2 style='
                            color:#176b3a;
                            margin-top:0;
                            font-size:22px;
                        '>
                            Su gafete de acceso está listo
                        </h2>

                        <p style='
                            color:#333333;
                            font-size:16px;
                            line-height:1.6;
                        '>
                            Estimado/a <strong>{$nombreParticipanteHtml}</strong>:
                        </p>

                        <p style='
                            color:#555555;
                            font-size:15px;
                            line-height:1.7;
                        '>
                            Adjunto a este correo encontrará su gafete personal para
                            el evento <strong>{$nombreEventoHtml}</strong>, con su
                            código QR único de ingreso.
                        </p>


                        <!-- ESTADO -->
                        <table width='100%'
                               cellpadding='0'
                               cellspacing='0'
                               style='
                                   margin:25px 0;
                                   background:#eef8f2;
                                   border-left:4px solid #176b3a;
                                   border-radius:4px;
                               '>

                            <tr>
                                <td style='padding:18px 20px;'>

                                    <span style='
                                        color:#176b3a;
                                        font-size:14px;
                                        font-weight:bold;
                                    '>
                                        CÓDIGO DE INGRESO
                                    </span>

                                    <br>

                                    <span style='
                                        display:inline-block;
                                        margin-top:8px;
                                        color:#176b3a;
                                        font-size:18px;
                                        font-weight:bold;
                                    '>
                                        {$codigoQrHtml}
                                    </span>

                                </td>
                            </tr>

                        </table>


                        <p style='
                            color:#555555;
                            font-size:15px;
                            line-height:1.7;
                        '>
                            Le recomendamos presentar este gafete (impreso o desde su
                            teléfono) el día del evento para agilizar su ingreso
                            escaneando el código QR.
                        </p>


                        <p style='
                            margin-top:35px;
                            color:#555555;
                            font-size:15px;
                            line-height:1.6;
                        '>
                            Atentamente,<br>
                            <strong>Equipo CENGICANA</strong>
                        </p>

                    </td>
                </tr>


                <!-- FOOTER -->
                <tr>
                    <td style='
                        background:#f0f2f3;
                        padding:22px 30px;
                        text-align:center;
                        color:#777777;
                        font-size:12px;
                        line-height:1.5;
                    '>

                        Este mensaje fue generado automáticamente por
                        la plataforma de CENGICANA.

                        <br><br>

                        Por favor, no responda a este correo.

                    </td>
                </tr>

            </table>

        </td>
    </tr>

</table>

</body>
</html>
";
