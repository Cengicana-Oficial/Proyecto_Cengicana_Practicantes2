<?php

// Recordatorio de cupo dirigido al instructor de un curso que esta por comenzar
// (dentro de los proximos 7 dias) y que todavia no ha alcanzado su cupo. Se espera
// que este archivo se incluya con la variable $curso ya en scope, con las llaves:
//   nombre_cursos, inicio_formateada, cupo, inscritos, instructor_nombre
// Ver cengicursos/cron_recordatorio_cupo.php para quien arma $curso y hace el include.
//
// Mismo layout visual (header verde con logo embebido, tarjeta blanca, footer gris)
// que cengicursos/correos/plantilla_aprobado.php, usado aqui como base/referencia.

$instructorNombre = htmlspecialchars($curso['instructor_nombre'] ?? 'Instructor/a');
$nombreCurso = htmlspecialchars($curso['nombre_cursos'] ?? 'el curso');
$fechaInicio = htmlspecialchars($curso['inicio_formateada'] ?? '');
$cupo = (int) ($curso['cupo'] ?? 0);
$inscritos = (int) ($curso['inscritos'] ?? 0);
$faltantes = max(0, $cupo - $inscritos);

$html = "
<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Recordatorio de cupo</title>
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
                            Estimado/a <strong>{$instructorNombre}</strong>:
                        </p>

                        <p style='
                            color:#555555;
                            font-size:15px;
                            line-height:1.7;
                        '>
                            Le recordamos que el curso
                            <strong>{$nombreCurso}</strong>
                            que usted imparte inicia el
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
                                        CUPOS OCUPADOS
                                    </span>

                                    <br>

                                    <span style='
                                        display:inline-block;
                                        margin-top:8px;
                                        color:#176b3a;
                                        font-size:18px;
                                        font-weight:bold;
                                    '>
                                        {$inscritos} de {$cupo}
                                    </span>

                                </td>
                            </tr>

                        </table>


                        <p style='
                            color:#555555;
                            font-size:15px;
                            line-height:1.7;
                        '>
                            Actualmente faltan <strong>{$faltantes}</strong>
                            cupo(s) por llenar antes del inicio del curso.
                        </p>

                        <p style='
                            color:#555555;
                            font-size:15px;
                            line-height:1.7;
                        '>
                            Este es solo un aviso informativo; si lo considera
                            necesario, puede ayudarnos a difundir el curso o
                            gestionar mas inscripciones antes de la fecha de
                            inicio.
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
