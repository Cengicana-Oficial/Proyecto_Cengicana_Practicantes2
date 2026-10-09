<?php
require_once __DIR__ . '/../../includes/auth.php';
lab_require_permission('laboratorio.analisis.ver');
$datos_curva = $datos_curva ?? [];
?>
<!DOCTYPE html>
<html lang="es">
<head>

    <meta charset="UTF-8">
    <title>Curva de Calibración</title>
    <link rel="stylesheet" href="../../styles/curva.css?v=20261006">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

</head>

<body>
<div class="page-wrap">
    <div class="card curva-card">

        <h2>Curva de Calibración</h2>

        <a class="back-link" href="../../view/labc_index.php">← Volver</a>

        <div class="chart-container">
            <canvas id="grafica"></canvas>
        </div>

        <div id="estadisticas">
    <p><strong>Ecuación de la recta:</strong> <span id="ecuacion"></span></p>
    <p><strong>R²:</strong> <span id="r2"></span></p>
</div>

<script>

const datos = <?= json_encode($datos_curva) ?>;

// X = punto curva
const puntos = datos.map(d => d.punto_curva);

// Y = absorbancia
const absorbancias = datos.map(d => d.absorbancia);

// Función para calcular regresión lineal
function calcularRegresionLineal(x, y) {
    const n = x.length;
    const sumX = x.reduce((a, b) => a + b, 0);
    const sumY = y.reduce((a, b) => a + b, 0);
    const sumXY = x.reduce((sum, xi, i) => sum + xi * y[i], 0);
    const sumX2 = x.reduce((sum, xi) => sum + xi * xi, 0);
    
    const pendiente = (n * sumXY - sumX * sumY) / (n * sumX2 - sumX * sumX);
    const interseccion = (sumY - pendiente * sumX) / n;
    
    // Calcular R²
    const yPromedio = sumY / n;
    const ssTotal = y.reduce((sum, yi) => sum + Math.pow(yi - yPromedio, 2), 0);
    const ssResidual = y.reduce((sum, yi, i) => {
        const yPredicho = pendiente * x[i] + interseccion;
        return sum + Math.pow(yi - yPredicho, 2);
    }, 0);
    const r2 = 1 - (ssResidual / ssTotal);
    
    return { pendiente, interseccion, r2 };
}

const { pendiente, interseccion, r2 } = calcularRegresionLineal(puntos, absorbancias);

// Mostrar ecuación y R²
const signoInterseccion = interseccion >= 0 ? '+' : '';
document.getElementById('ecuacion').textContent = `y = ${pendiente.toFixed(4)}x ${signoInterseccion} ${interseccion.toFixed(4)}`;
document.getElementById('r2').textContent = (r2 * 100).toFixed(2) + '%';

// Generar puntos para la línea de tendencia
const minX = Math.min(...puntos);
const maxX = Math.max(...puntos);
const datosTendencia = [
    { x: minX, y: pendiente * minX + interseccion },
    { x: maxX, y: pendiente * maxX + interseccion }
];

const ctx = document.getElementById('grafica');
const rootStyles = getComputedStyle(document.documentElement);
const chartColor = rootStyles.getPropertyValue('--green-600').trim();
const trendColor = rootStyles.getPropertyValue('--green-400').trim();

new Chart(ctx, {
    type: 'scatter',

    data: {
        datasets: [
            {
                label: 'Curva de calibración',
                data: puntos.map((p, i) => ({
                    x: p,
                    y: absorbancias[i]
                })),
                borderColor: chartColor,
                backgroundColor: chartColor,
                showLine: false
            },
            {
                label: 'Línea de tendencia',
                data: datosTendencia,
                type: 'line',
                borderColor: trendColor,
                backgroundColor: 'transparent',
                borderWidth: 2,
                fill: false,
                pointRadius: 0,
                tension: 0
            }
        ]
    },

    options: {
        plugins: {
            legend: {
                display: true
            }
        },
        scales: {
            x: {
                title: {
                    display: true,
                    text: 'Punto Curva'
                }
            },
            y: {
                title: {
                    display: true,
                    text: 'Absorbancia'
                }
            }
        }
    }
});

</script>
    </div>
</div>
</body>
</html>
