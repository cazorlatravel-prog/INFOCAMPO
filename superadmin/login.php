<?php
/**
 * INFOCAMPO SaaS - Login Super Administrador
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

// Si ya tiene sesión de superadmin, redirigir al dashboard
if (isLoggedIn() && ($_SESSION['user_rol'] ?? '') === 'superadmin') {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Introduce email y contraseña.';
    } else {
        $user = login($email, $password);
        if ($user && $user['rol'] === 'superadmin') {
            header('Location: index.php');
            exit;
        } else {
            if ($user) logout(); // Si logueó pero no es superadmin
            $error = 'Credenciales incorrectas o sin permisos de superadmin.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INFOCAMPO - Super Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        .login-card {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border-radius: 16px;
            padding: 40px 36px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .brand {
            text-align: center;
            margin-bottom: 32px;
        }
        .brand h1 {
            font-size: 1.8rem;
            font-weight: 800;
            color: #1e3a5f;
            margin: 0;
            letter-spacing: -0.5px;
        }
        .brand p {
            color: #6b7280;
            font-size: 0.85rem;
            margin: 6px 0 0;
        }
        .form-control {
            border-radius: 10px;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            transition: border-color 0.2s;
        }
        .form-control:focus {
            border-color: #2d6a9f;
            box-shadow: 0 0 0 3px rgba(45,106,159,0.1);
        }
        .btn-login {
            width: 100%;
            padding: 12px;
            border-radius: 10px;
            background: linear-gradient(135deg, #1e3a5f, #2d6a9f);
            border: none;
            color: #fff;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(30,58,95,0.3);
        }
        .alert { border-radius: 10px; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="brand">
            <h1>INFOCAMPO</h1>
            <p>Panel de Super Administración</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 mb-3"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <div class="mb-3">
                <label for="email" class="form-label fw-semibold small text-muted">Email</label>
                <input type="email" name="email" id="email" class="form-control"
                       placeholder="superadmin@infocampo.app"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       required autofocus>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label fw-semibold small text-muted">Contraseña</label>
                <input type="password" name="password" id="password" class="form-control"
                       placeholder="Tu contraseña" required>
            </div>

            <button type="submit" class="btn-login">Acceder</button>
        </form>
    </div>
</body>
</html>
