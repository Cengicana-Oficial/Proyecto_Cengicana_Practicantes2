<?php

$nombre = htmlspecialchars($solicitud['nombre_participante']);
$curso = htmlspecialchars($solicitud['nombre_cursos'] ?? 'curso solicitado');

$html = "
<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Actualización de solicitud</title>
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
                            Plataforma de Cursos
                        </p>

                    </td>
                </tr>


                <!-- CONTENIDO -->
                <tr>

                    <td style='padding:40px 35px;'>

                        <h2 style='
                            color:#333333;
                            margin-top:0;
                            font-size:22px;
                        '>
                            Actualización de su solicitud
                        </h2>

                        <p style='
                            color:#333333;
                            font-size:16px;
                            line-height:1.6;
                        '>
                            Estimado/a <strong>{$nombre}</strong>:
                        </p>

                        <p style='
                            color:#555555;
                            font-size:15px;
                            line-height:1.7;
                        '>
                            Le informamos que, luego de revisar su solicitud
                            de inscripción al curso
                            <strong>{$curso}</strong>,
                            esta no pudo ser aprobada.
                        </p>


                        <!-- ESTADO -->
                        <table width='100%'
                               cellpadding='0'
                               cellspacing='0'
                               style='
                                   margin:25px 0;
                                   background:#fff3f3;
                                   border-left:4px solid #b42318;
                                   border-radius:4px;
                               '>

                            <tr>

                                <td style='padding:18px 20px;'>

                                    <span style='
                                        color:#8a1c14;
                                        font-size:14px;
                                        font-weight:bold;
                                    '>
                                        ESTADO DE LA SOLICITUD
                                    </span>

                                    <br>

                                    <span style='
                                        display:inline-block;
                                        margin-top:8px;
                                        color:#b42318;
                                        font-size:18px;
                                        font-weight:bold;
                                    '>
                                        NO APROBADA
                                    </span>

                                </td>

                            </tr>

                        </table>


                        <p style='
                            color:#555555;
                            font-size:15px;
                            line-height:1.7;
                        '>
                            Si considera necesario obtener más información
                            sobre el estado de su solicitud, puede comunicarse
                            con el personal responsable de la plataforma.
                        </p>

                        <p style='
                            color:#555555;
                            font-size:15px;
                            line-height:1.7;
                        '>
                            Agradecemos su interés en las actividades
                            de capacitación de CENGICANA.
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