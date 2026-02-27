<?php
/**
 * INFOCAMPO SaaS - Login de Administrador de Empresa
 *
 * Permite a los administradores y supervisores de empresa
 * iniciar sesión directamente sin necesidad de superadmin.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

// Manejar logout
if (isset($_GET['logout'])) {
    logout();
    header('Location: login.php');
    exit;
}

// Si ya está autenticado como admin/supervisor, redirigir al panel
if (isLoggedIn()) {
    $rol = $_SESSION['user_rol'] ?? '';
    if (in_array($rol, ['admin', 'supervisor'], true)) {
        header('Location: dashboard.php');
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
            if (in_array($rol, ['admin', 'supervisor'], true)) {
                header('Location: dashboard.php');
                exit;
            } elseif ($rol === 'superadmin') {
                header('Location: /superadmin/index.php');
                exit;
            } else {
                // Operador: no tiene acceso al panel admin
                logout();
                $error = 'Los operadores acceden desde su enlace directo, no desde este panel.';
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
    <title>INFOCAMPO - Acceso Administración</title>
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
            <h1><i class="bi bi-geo-alt-fill me-2"></i>INFOCAMPO</h1>
            <p>Panel de Administración</p>
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
                    <i class="bi bi-box-arrow-in-right me-1"></i> Acceder
                </button>
            </form>
        </div>
        <div class="login-footer">
            INFOCAMPO SaaS &mdash; Inspección de Infraestructuras
        </div>
    </div>
</body>
</html>
