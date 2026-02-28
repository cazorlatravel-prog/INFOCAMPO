<?php
/**
 * INFOCAMPO SaaS - Login de Operador de Campo
 *
 * Permite a los operadores iniciar sesión con email y contraseña
 * y los redirige a la interfaz de operador con sus parámetros.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

// Manejar logout
if (isset($_GET['logout'])) {
    logout();
    header('Location: login.php');
    exit;
}

// Si ya está autenticado como operador, redirigir a la interfaz
if (isLoggedIn()) {
    $rol = $_SESSION['user_rol'] ?? '';
    if ($rol === 'operador') {
        header('Location: operador.php?user=' . $_SESSION['user_id'] . '&empresa=' . $_SESSION['empresa_id']);
        exit;
    }
    // Si es otro rol, redirigir a su panel correspondiente
    if (in_array($rol, ['admin', 'supervisor'], true)) {
        header('Location: /admin/dashboard.php');
        exit;
    }
    if ($rol === 'superadmin') {
        header('Location: /superadmin/index.php');
        exit;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Introduce tu email y contraseña.';
    } else {
        $result = login($email, $password);
        if ($result === false) {
            $error = 'Credenciales incorrectas o cuenta desactivada.';
        } else {
            $rol = $result['rol'];
            if ($rol === 'operador') {
                header('Location: operador.php?user=' . $result['id'] . '&empresa=' . $result['empresa_id']);
                exit;
            } elseif (in_array($rol, ['admin', 'supervisor'], true)) {
                header('Location: /admin/dashboard.php');
                exit;
            } elseif ($rol === 'superadmin') {
                header('Location: /superadmin/index.php');
                exit;
            } else {
                logout();
                $error = 'Rol de usuario no reconocido.';
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
    <title>FotoGPS.app - Acceso Operador de Campo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0a1628 0%, #0f2847 50%, #1a4a7a 100%);
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
            background: linear-gradient(135deg, #1e40af, #3b82f6);
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
            opacity: 0.8;
            font-size: 0.85rem;
        }
        .login-header .icon-circle {
            width: 60px; height: 60px;
            border-radius: 50%;
            background: rgba(255,255,255,0.15);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 14px;
            font-size: 1.5rem;
        }
        .login-body { padding: 32px 28px; }
        .form-floating { margin-bottom: 16px; }
        .form-floating .form-control {
            border-radius: 10px;
            border: 2px solid #e5e7eb;
            padding: 16px 14px 8px;
        }
        .form-floating .form-control:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
        }
        .btn-login {
            width: 100%;
            padding: 12px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 1rem;
            background: linear-gradient(135deg, #1e40af, #3b82f6);
            border: none;
            color: #fff;
            transition: all 0.2s;
        }
        .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(59,130,246,0.4);
            color: #fff;
        }
        .login-footer {
            text-align: center;
            padding: 0 28px 24px;
            font-size: 0.8rem;
            color: #9ca3af;
        }
        .login-footer a {
            color: #3b82f6;
            text-decoration: none;
        }
        .login-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <div class="icon-circle">
                <i class="bi bi-phone-fill"></i>
            </div>
            <h1><i class="bi bi-geo-alt-fill me-2"></i>FotoGPS.app</h1>
            <p>Acceso Operador de Campo</p>
        </div>
        <div class="login-body">
            <?php if ($error): ?>
                <div class="alert alert-danger py-2 small" role="alert">
                    <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" autocomplete="on">
                <div class="form-floating">
                    <input type="email" name="email" id="email" class="form-control"
                           placeholder="tu@empresa.com" required autofocus
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    <label for="email"><i class="bi bi-envelope me-1"></i> Email</label>
                </div>
                <div class="form-floating">
                    <input type="password" name="password" id="password" class="form-control"
                           placeholder="Contraseña" required>
                    <label for="password"><i class="bi bi-lock me-1"></i> Contraseña</label>
                </div>
                <button type="submit" class="btn btn-login mt-2">
                    <i class="bi bi-camera me-1"></i> Acceder como Operador
                </button>
            </form>
        </div>
        <div class="login-footer">
            <a href="/"><i class="bi bi-arrow-left me-1"></i>Volver a la página principal</a>
        </div>
    </div>
</body>
</html>
