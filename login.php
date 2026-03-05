<?php
/**
 * INFOCAMPO SaaS - Login Unificado
 *
 * Punto de entrada unico para todos los roles.
 * Segun el rol del usuario, redirige al panel correspondiente.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Manejar logout
if (isset($_GET['logout'])) {
    logout();
    header('Location: /login.php');
    exit;
}

// Si ya esta autenticado, redirigir segun rol
if (isLoggedIn()) {
    $rol = $_SESSION['user_rol'] ?? '';
    if ($rol === 'operador') {
        header('Location: /public/operador.php?user=' . ($_SESSION['user_id'] ?? 0) . '&empresa=' . ($_SESSION['empresa_id'] ?? 0));
        exit;
    }
    if (in_array($rol, ['admin', 'supervisor', 'superadmin'], true)) {
        header('Location: /admin/dashboard.php');
        exit;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        $error = 'Token de seguridad invalido. Recarga la pagina e intenta de nuevo.';
    } else {
        $identifier = trim($_POST['identifier'] ?? '');
        $password   = $_POST['password'] ?? '';

        if ($identifier === '' || $password === '') {
            $error = 'Introduce tu email o telefono y contrasena.';
        } else {
            $result = login($identifier, $password);
            if ($result === false) {
                $pdo = getDB();
                $isEmail = str_contains($identifier, '@');
                $campo = $isEmail ? 'email' : 'telefono';
                $cleanId = $isEmail ? $identifier : preg_replace('/[^0-9+]/', '', $identifier);
                $checkStmt = $pdo->prepare("SELECT id, activo, empresa_id, rol FROM usuarios WHERE {$campo} = :id LIMIT 1");
                $checkStmt->execute([':id' => $cleanId]);
                $checkUser = $checkStmt->fetch();

                if (!$checkUser) {
                    $error = 'No existe ningun usuario con ese ' . ($isEmail ? 'email' : 'telefono') . '.';
                } elseif (!$checkUser['activo']) {
                    $error = 'Esta cuenta esta desactivada. Contacta con tu administrador.';
                } else {
                    $empStmt = $pdo->prepare("SELECT id FROM empresas WHERE id = :id");
                    $empStmt->execute([':id' => $checkUser['empresa_id']]);
                    if (!$empStmt->fetch()) {
                        $error = 'Error de configuracion: la empresa asociada no existe (ID: ' . $checkUser['empresa_id'] . '). Contacta con el superadministrador.';
                    } else {
                        $error = 'Contrasena incorrecta.';
                    }
                }
            } else {
                $rol = $result['rol'];
                if ($rol === 'operador') {
                    header('Location: /public/operador.php?user=' . $result['id'] . '&empresa=' . $result['empresa_id']);
                    exit;
                } elseif (in_array($rol, ['admin', 'supervisor', 'superadmin'], true)) {
                    header('Location: /admin/dashboard.php');
                    exit;
                } else {
                    logout();
                    $error = 'Rol de usuario no reconocido.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FotoGPS.app - Acceso</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --login-primary: #1e3a5f;
            --login-primary-light: #2d6a9f;
            --login-accent: #3b82f6;
            --login-card: #ffffff;
            --login-text: #1f2937;
            --login-muted: #6b7280;
            --login-border: #e5e7eb;
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 50%, #2d6a9f 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            padding: 20px;
            -webkit-font-smoothing: antialiased;
        }

        .login-card {
            background: var(--login-card);
            border-radius: 20px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 420px;
            overflow: hidden;
            animation: cardIn 0.4s ease;
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-header {
            background: linear-gradient(135deg, var(--login-primary), var(--login-primary-light));
            color: #fff;
            padding: 36px 28px 28px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .login-header::before {
            content: '';
            position: absolute;
            top: -40%;
            right: -20%;
            width: 200px;
            height: 200px;
            background: rgba(255,255,255,0.05);
            border-radius: 50%;
        }

        .login-icon {
            width: 56px;
            height: 56px;
            background: rgba(255,255,255,0.15);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            margin: 0 auto 14px;
            backdrop-filter: blur(4px);
            position: relative;
        }

        .login-header h1 {
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            margin: 0 0 4px;
            position: relative;
        }

        .login-header h1 span {
            font-weight: 400;
            opacity: 0.6;
        }

        .login-header p {
            margin: 0;
            opacity: 0.7;
            font-size: 0.85rem;
            position: relative;
        }

        .login-body {
            padding: 32px 28px 24px;
        }

        .login-alert {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 0.84rem;
            color: #dc2626;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 20px;
            line-height: 1.4;
        }

        .login-alert i {
            font-size: 1rem;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .field-group {
            margin-bottom: 18px;
        }

        .field-label {
            display: block;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--login-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .field-input-wrap {
            position: relative;
        }

        .field-input-wrap > i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 1rem;
            pointer-events: none;
            transition: color 0.2s;
        }

        .field-input {
            width: 100%;
            border: 2px solid var(--login-border);
            border-radius: 12px;
            padding: 14px 14px 14px 42px;
            font-size: 0.95rem;
            color: var(--login-text);
            background: var(--login-card);
            transition: border-color 0.2s, box-shadow 0.2s;
            font-family: inherit;
        }

        .field-input:focus {
            outline: none;
            border-color: var(--login-accent);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.12);
        }

        .field-input-wrap:focus-within > i {
            color: var(--login-accent);
        }

        .field-input::placeholder {
            color: #c7cbd0;
        }

        .btn-toggle-pass {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            padding: 4px;
            font-size: 1.1rem;
            transition: color 0.2s;
        }

        .btn-toggle-pass:hover { color: var(--login-text); }

        .btn-login {
            width: 100%;
            padding: 14px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1rem;
            background: linear-gradient(135deg, var(--login-primary), var(--login-primary-light));
            border: none;
            color: #fff;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 6px;
            min-height: 52px;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(30,58,95,0.4);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .login-footer {
            text-align: center;
            padding: 0 28px 24px;
            font-size: 0.78rem;
            color: #9ca3af;
        }

        :focus-visible {
            outline: 2px solid var(--login-accent);
            outline-offset: 2px;
        }

        @media (max-width: 480px) {
            body { padding: 16px; }
            .login-card { border-radius: 16px; }
            .login-header { padding: 28px 20px 22px; }
            .login-body { padding: 24px 20px 20px; }
            .login-footer { padding: 0 20px 20px; }
        }

        @media (max-height: 600px) {
            .login-header { padding: 20px 20px 16px; }
            .login-icon { width: 44px; height: 44px; font-size: 1.3rem; margin-bottom: 10px; }
            .login-header h1 { font-size: 1.3rem; }
        }

        @media (prefers-reduced-motion: reduce) {
            .login-card { animation: none; }
            * { transition-duration: 0.01ms !important; }
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <div class="login-icon">
                <i class="bi bi-geo-alt-fill"></i>
            </div>
            <h1>FotoGPS<span>.app</span></h1>
            <p>Acceso a la plataforma</p>
        </div>
        <div class="login-body">
            <?php if ($error): ?>
                <div class="login-alert" role="alert">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" autocomplete="on">
                <?= csrfField() ?>

                <div class="field-group">
                    <label class="field-label" for="identifier">Email o Telefono</label>
                    <div class="field-input-wrap">
                        <i class="bi bi-person"></i>
                        <input type="text" name="identifier" id="identifier" class="field-input"
                               placeholder="tu@email.com o 600123456" required autofocus
                               autocomplete="username"
                               value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>">
                    </div>
                </div>

                <div class="field-group">
                    <label class="field-label" for="password">Contrasena</label>
                    <div class="field-input-wrap">
                        <i class="bi bi-lock"></i>
                        <input type="password" name="password" id="password" class="field-input"
                               placeholder="Tu contrasena" required
                               autocomplete="current-password"
                               style="padding-right:44px;">
                        <button type="button" class="btn-toggle-pass" id="toggle-pass" aria-label="Mostrar contrasena">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="bi bi-box-arrow-in-right"></i> Acceder
                </button>
            </form>
        </div>
        <div class="login-footer">
            FotoGPS.app &mdash; Tu APP de recogida de datos en Campo
        </div>
    </div>

    <script>
    document.getElementById('toggle-pass').addEventListener('click', function() {
        const input = document.getElementById('password');
        const icon = this.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'bi bi-eye-slash';
        } else {
            input.type = 'password';
            icon.className = 'bi bi-eye';
        }
    });
    </script>
</body>
</html>
