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
            $error = 'Introduce tu email o telefono y contraseña.';
        } else {
            $result = login($identifier, $password);
            if ($result === false) {
                $error = 'Credenciales incorrectas o cuenta desactivada.';
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 50%, #2d6a9f 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .login-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 420px;
            overflow: hidden;
        }
        .login-header {
            background: linear-gradient(135deg, #1e3a5f, #2d6a9f);
            color: #fff;
            padding: 32px 28px 24px;
            text-align: center;
        }
        .login-header h1 {
            font-size: 1.6rem;
            font-weight: 800;
            letter-spacing: 1px;
            margin: 0 0 4px;
        }
        .login-header p {
            margin: 0;
            opacity: 0.7;
            font-size: 0.85rem;
        }
        .login-body { padding: 32px 28px; }
        .form-floating { margin-bottom: 16px; }
        .form-floating .form-control {
            border-radius: 10px;
            border: 2px solid #e5e7eb;
            padding: 16px 14px 8px;
        }
        .form-floating .form-control:focus {
            border-color: #2d6a9f;
            box-shadow: 0 0 0 3px rgba(45,106,159,0.15);
        }
        .btn-login {
            width: 100%;
            padding: 12px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 1rem;
            background: linear-gradient(135deg, #1e3a5f, #2d6a9f);
            border: none;
            color: #fff;
            transition: all 0.2s;
        }
        .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(30,58,95,0.4);
            color: #fff;
        }
        .login-footer {
            text-align: center;
            padding: 0 28px 24px;
            font-size: 0.8rem;
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <h1><i class="bi bi-geo-alt-fill me-2"></i>FotoGPS.app</h1>
            <p>Acceso a la plataforma</p>
        </div>
        <div class="login-body">
            <?php if ($error): ?>
                <div class="alert alert-danger py-2 small" role="alert">
                    <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" autocomplete="on">
                <?= csrfField() ?>
                <div class="form-floating">
                    <input type="text" name="identifier" id="identifier" class="form-control"
                           placeholder="Email o telefono" required autofocus
                           autocomplete="username"
                           value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>">
                    <label for="identifier"><i class="bi bi-person me-1"></i> Email o Telefono</label>
                </div>
                <div class="form-floating">
                    <input type="password" name="password" id="password" class="form-control"
                           placeholder="Contraseña" required>
                    <label for="password"><i class="bi bi-lock me-1"></i> Contraseña</label>
                </div>
                <button type="submit" class="btn btn-login mt-2">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Acceder
                </button>
            </form>
        </div>
        <div class="login-footer">
            FotoGPS.app &mdash; Tu APP de recogida de datos en Campo
        </div>
    </div>
</body>
</html>
