<?php
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Página no encontrada | CENGICAÑA</title>
    <link rel="stylesheet" href="assets/login.css">
    <style>
        .error-container {
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .error-card {
            backdrop-filter: blur(12px);
            background: rgba(255, 255, 255, 0.92);
            width: 100%;
            max-width: 480px;
            padding: 48px 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
            border-top: 5px solid var(--verde-sostenible);
            text-align: center;
            animation: fadeIn 0.6s ease;
        }

        .error-code {
            font-size: 96px;
            font-weight: 700;
            line-height: 1;
            margin: 0 0 8px;
            background: linear-gradient(135deg, var(--verde-sostenible), var(--verde-tecnologico));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .error-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--texto-oscuro);
            margin-bottom: 10px;
        }

        .error-text {
            font-size: 14px;
            color: #666;
            margin-bottom: 30px;
            line-height: 1.5;
        }

        .error-actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .error-btn {
            display: inline-block;
            padding: 14px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--verde-sostenible), var(--verde-tecnologico));
            color: white;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: 0.3s;
            letter-spacing: 0.5px;
        }

        .error-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(115, 188, 37, 0.5);
        }

        .error-btn.secondary {
            background: none;
            color: var(--verde-oscuro);
            border: 1.5px solid var(--gris-inteligente);
        }

        .error-btn.secondary:hover {
            box-shadow: none;
            border-color: var(--verde-sostenible);
            transform: none;
        }

        @media (max-width: 480px) {
            .error-card {
                padding: 32px 24px;
            }

            .error-code {
                font-size: 72px;
            }
        }
    </style>
</head>
<body>

<div class="logo-top">
    <img src="assets/img/logo.png" alt="Logo CENGICAÑA">
</div>

<div class="error-container">
    <div class="error-card">
        <p class="error-code">404</p>
        <h1 class="error-title">Página no encontrada</h1>
        <p class="error-text">
            La página que buscas no existe, cambió de dirección o el enlace ya no está disponible.
        </p>
        <div class="error-actions">
            <a class="error-btn" href="login.php">Ir al inicio de sesión</a>
            <a class="error-btn secondary" href="Menu.php">Ir al menú principal</a>
        </div>
    </div>
</div>

</body>
</html>
