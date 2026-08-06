<?php
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Página no encontrada | CENGICURSOS</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Inter:wght@400;500;600;700&display=swap');

        :root {
            --cengi-primary: #73BC25;
            --cengi-primary-strong: #5e9b1d;
            --cengi-primary-deep: #1f5632;
            --cengi-primary-deep-2: #163d24;
            --cengi-secondary: #A3D300;
            --cengi-amarillo: #FFCC00;
            --cengi-ink: #294033;
            --cengi-muted: #55705f;
            --cengi-surface: #ffffff;
            --cengi-border: #dbe8dc;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 24px;
            font-family: 'Inter', sans-serif;
            color: var(--cengi-ink);
            background:
                radial-gradient(circle at 15% 20%, rgba(163, 211, 0, 0.25), transparent 40%),
                radial-gradient(circle at 85% 80%, rgba(115, 188, 37, 0.25), transparent 40%),
                linear-gradient(160deg, var(--cengi-primary-deep-2), var(--cengi-primary-deep));
        }

        .cengi-404-card {
            width: 100%;
            max-width: 520px;
            background: var(--cengi-surface);
            border-radius: 20px;
            padding: 48px 44px;
            text-align: center;
            box-shadow: 0 24px 60px rgba(22, 61, 36, 0.35);
            border-top: 5px solid var(--cengi-primary);
            animation: cengiFadeIn 0.5s ease;
        }

        .cengi-404-logo {
            height: 64px;
            margin-bottom: 20px;
        }

        .cengi-404-code {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 88px;
            font-weight: 700;
            line-height: 1;
            margin: 0 0 8px;
            background: linear-gradient(135deg, var(--cengi-primary), var(--cengi-secondary));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .cengi-404-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 21px;
            font-weight: 700;
            margin: 0 0 10px;
            color: var(--cengi-primary-deep);
        }

        .cengi-404-text {
            font-size: 14px;
            color: var(--cengi-muted);
            line-height: 1.55;
            margin: 0 0 30px;
        }

        .cengi-404-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
        }

        .cengi-404-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 13px 26px;
            border-radius: 12px;
            font-size: 14.5px;
            font-weight: 600;
            text-decoration: none;
            transition: transform 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
        }

        .cengi-404-btn.primary {
            background: linear-gradient(135deg, var(--cengi-primary), var(--cengi-primary-strong));
            color: #fff;
        }

        .cengi-404-btn.primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(115, 188, 37, 0.45);
        }

        .cengi-404-btn.secondary {
            border: 1.5px solid var(--cengi-border);
            color: var(--cengi-primary-deep);
            background: #fff;
        }

        .cengi-404-btn.secondary:hover {
            border-color: var(--cengi-primary);
            background: #f3faec;
        }

        @keyframes cengiFadeIn {
            from {
                opacity: 0;
                transform: translateY(16px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 480px) {
            .cengi-404-card {
                padding: 34px 26px;
            }

            .cengi-404-code {
                font-size: 64px;
            }
        }
    </style>
</head>
<body>

<div class="cengi-404-card">
    <img class="cengi-404-logo" src="img/logo-comite-capacitacion.png" alt="CENGICAÑA">
    <p class="cengi-404-code">404</p>
    <h1 class="cengi-404-title">Página no encontrada</h1>
    <p class="cengi-404-text">
        La página de CENGICURSOS que buscas no existe, se movió o el enlace ya no está disponible.
    </p>
    <div class="cengi-404-actions">
        <a class="cengi-404-btn primary" href="index.php">Ir al inicio de CENGICURSOS</a>
        <a class="cengi-404-btn secondary" href="../login/Menu.php">Ir al menú principal</a>
    </div>
</div>

</body>
</html>
