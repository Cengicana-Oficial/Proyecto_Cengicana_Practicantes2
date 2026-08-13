<?php

// Recordatorio de fecha de inicio dirigido a un participante ya inscrito
// activamente en un curso que esta por comenzar (dentro de los proximos 7 dias).
// Se espera que este archivo se incluya con las variables $curso (con
// nombre_cursos, inicio_formateada) y $participante (con nombre_participantes)
// ya en scope. Ver cengicursos/cron_recordatorio_cupo.php para quien las arma y
// hace el include.
//
// A diferencia de la plantilla dirigida al instructor, aqui no se menciona el
// cupo/capacidad del curso: para el participante no es informacion accionable,
// solo interesa la fecha de inicio.
//
// Mismo layout visual (header verde con logo embebido, tarjeta blanca, footer gris)
// que cengicursos/correos/plantilla_aprobado.php, usado aqui como base/referencia.

$nombreParticipante = htmlspecialchars($participante['nombre_participantes'] ?? 'Participante');
$nombreCurso = htmlspecialchars($curso['nombre_cursos'] ?? 'el curso');
$fechaInicio = htmlspecialchars($curso['inicio_formateada'] ?? '');

$html = "
<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Recordatorio de curso</title>
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
                            color:#176b3a;
                            margin-top:0;
                            font-size:22px;
                        '>
                            Su curso esta por comenzar
                        </h2>

                        <p style='
                            color:#333333;
                            font-size:16px;
                            line-height:1.6;
                        '>
                            Estimado/a <strong>{$nombreParticipante}</strong>:
                        </p>

                        <p style='
                            color:#555555;
                            font-size:15px;
                            line-height:1.7;
                        '>
                            Le recordamos que el curso
                            <strong>{$nombreCurso}</strong>,
                            en el cual usted esta inscrito/a,
                            inicia el
                            <strong>{$fechaInicio}</strong>.
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
                                        FECHA DE INICIO
                                    </span>

                                    <br>

                                    <span style='
                                        display:inline-block;
                                        margin-top:8px;
                                        color:#176b3a;
                                        font-size:18px;
                                        font-weight:bold;
                                    '>
                                        {$fechaInicio}
                                    </span>

                                </td>
                            </tr>

                        </table>


                        <p style='
                            color:#555555;
                            font-size:15px;
                            line-height:1.7;
                        '>
                            Le pedimos estar atento/a y prepararse con
                            anticipacion para participar en el curso.
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
