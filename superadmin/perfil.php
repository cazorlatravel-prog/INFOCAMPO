<?php
/**
 * INFOCAMPO SaaS - Mi Perfil (Super Admin)
 *
 * Permite al superadmin cambiar su propio nombre y contraseña.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = requireRole('superadmin');

$pdo = getDB();

// ---------------------------------------------------------------
// Procesar acciones POST
// ---------------------------------------------------------------
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrf()) {
    $action = $_POST['action'] ?? '';

    // --- Actualizar nombre y email ---
    if ($action === 'update_profile') {
        $newNombre = trim($_POST['nombre'] ?? '');
        $newEmail  = trim($_POST['email'] ?? '');

        if ($newNombre === '' || $newEmail === '') {
            $msg = 'El nombre y el email son obligatorios.';
            $msgType = 'danger';
        } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $msg = 'El email no es válido.';
            $msgType = 'danger';
        } else {
            // Verificar que el email no esté en uso por otro usuario
            $check = $pdo->prepare("SELECT id FROM usuarios WHERE email = :email AND id != :id");
            $check->execute([':email' => $newEmail, ':id' => $user['id']]);
            if ($check->fetch()) {
                $msg = 'Ya existe otro usuario con ese email.';
                $msgType = 'danger';
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE usuarios SET nombre = :nombre, email = :email WHERE id = :id"
                );
                $stmt->execute([':nombre' => $newNombre, ':email' => $newEmail, ':id' => $user['id']]);

                // Actualizar sesión
                $_SESSION['user_name']  = $newNombre;
                $_SESSION['user_email'] = $newEmail;
                $user['nombre'] = $newNombre;
                $user['email']  = $newEmail;

                $msg = 'Perfil actualizado correctamente.';
                $msgType = 'success';
            }
        }
    }

    // --- Cambiar contraseña propia ---
    if ($action === 'change_password') {
        $currentPass = $_POST['current_password'] ?? '';
        $newPass     = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if ($currentPass === '' || $newPass === '' || $confirmPass === '') {
            $msg = 'Todos los campos de contraseña son obligatorios.';
            $msgType = 'danger';
        } elseif (strlen($newPass) < 6) {
            $msg = 'La nueva contraseña debe tener al menos 6 caracteres.';
            $msgType = 'danger';
        } elseif ($newPass !== $confirmPass) {
            $msg = 'Las contraseñas nuevas no coinciden.';
            $msgType = 'danger';
        } else {
            // Verificar contraseña actual
            $stmt = $pdo->prepare("SELECT password FROM usuarios WHERE id = :id");
            $stmt->execute([':id' => $user['id']]);
            $row = $stmt->fetch();

            if (!$row || !password_verify($currentPass, $row['password'])) {
                $msg = 'La contraseña actual no es correcta.';
                $msgType = 'danger';
            } else {
                $hash = hashPassword($newPass);
                $pdo->prepare("UPDATE usuarios SET password = :pass WHERE id = :id")
                    ->execute([':pass' => $hash, ':id' => $user['id']]);
                $msg = 'Contraseña actualizada correctamente.';
                $msgType = 'success';
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
    <title>FotoGPS.app - Mi Perfil</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root { --sa-primary: #1e3a5f; --sa-secondary: #2d6a9f; --sa-bg: #f0f2f5; }
        body { background: var(--sa-bg); }
        .sa-sidebar {
            position: fixed; top: 0; left: 0; bottom: 0; width: 260px;
            background: linear-gradient(180deg, var(--sa-primary), #162d4a);
            color: #fff; z-index: 1000; overflow-y: auto;
        }
        .sa-sidebar .brand { padding: 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sa-sidebar .brand h2 { font-size: 1.2rem; font-weight: 800; margin: 0; }
        .sa-sidebar .brand small { opacity: 0.6; font-size: 0.75rem; }
        .sa-sidebar .nav-link {
            color: rgba(255,255,255,0.7); padding: 12px 20px; font-size: 0.9rem;
            display: flex; align-items: center; gap: 10px; transition: all 0.2s;
            border-left: 3px solid transparent;
        }
        .sa-sidebar .nav-link:hover, .sa-sidebar .nav-link.active {
            color: #fff; background: rgba(255,255,255,0.08); border-left-color: #4da6ff;
        }
        .sa-main { margin-left: 260px; padding: 24px 32px; min-height: 100vh; }
        .sa-topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; flex-wrap: wrap; gap: 12px; }
        .sa-topbar h1 { font-size: 1.5rem; font-weight: 700; color: #1f2937; margin: 0; }
        .form-section { background: #fff; border-radius: 12px; padding: 28px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); margin-bottom: 24px; }
        @media (max-width: 767px) {
            .sa-sidebar { position: relative; width: 100%; }
            .sa-main { margin-left: 0; padding: 16px; }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <nav class="sa-sidebar">
        <div class="brand">
            <h2>FotoGPS.app</h2>
            <small>Super Administración</small>
        </div>
        <ul class="nav flex-column mt-2">
            <li><a href="index.php" class="nav-link"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
            <li><a href="empresas.php" class="nav-link"><i class="bi bi-building"></i> Empresas</a></li>
            <li><a href="usuarios.php" class="nav-link"><i class="bi bi-people"></i> Usuarios</a></li>
            <li><a href="campos.php" class="nav-link"><i class="bi bi-ui-checks-grid"></i> Campos Formulario</a></li>
        </ul>
        <div style="position:absolute;bottom:0;width:100%;border-top:1px solid rgba(255,255,255,0.1);padding:16px 20px;">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="bi bi-person-circle" style="font-size:1.4rem;opacity:0.7;"></i>
                <div>
                    <div class="small fw-semibold"><?= htmlspecialchars($user['nombre']) ?></div>
                    <div class="small" style="opacity:0.5;font-size:0.7rem;"><?= htmlspecialchars($user['email']) ?></div>
                </div>
            </div>
            <a href="perfil.php" class="btn btn-sm btn-outline-light w-100 mb-2" style="font-size:0.8rem;">
                <i class="bi bi-person-gear"></i> Mi Perfil
            </a>
            <a href="logout.php" class="btn btn-sm btn-outline-light w-100" style="opacity:0.6;font-size:0.8rem;">
                <i class="bi bi-box-arrow-left"></i> Cerrar sesión
            </a>
        </div>
    </nav>

    <div class="sa-main">
        <div class="sa-topbar">
            <h1><i class="bi bi-person-gear me-2"></i>Mi Perfil</h1>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?= $msgType ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Datos del perfil -->
            <div class="col-lg-6">
                <div class="form-section">
                    <h5 class="mb-3"><i class="bi bi-person me-2"></i>Datos personales</h5>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="update_profile">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Nombre</label>
                            <input type="text" name="nombre" class="form-control" required
                                   value="<?= htmlspecialchars($user['nombre']) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" name="email" class="form-control" required
                                   value="<?= htmlspecialchars($user['email']) ?>">
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Guardar cambios
                        </button>
                    </form>
                </div>
            </div>

            <!-- Cambiar contraseña -->
            <div class="col-lg-6">
                <div class="form-section">
                    <h5 class="mb-3"><i class="bi bi-key me-2"></i>Cambiar contraseña</h5>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="change_password">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Contraseña actual</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Nueva contraseña</label>
                            <input type="password" name="new_password" class="form-control" required minlength="6"
                                   placeholder="Mínimo 6 caracteres">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Confirmar nueva contraseña</label>
                            <input type="password" name="confirm_password" class="form-control" required minlength="6">
                        </div>
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-shield-lock me-1"></i>Cambiar contraseña
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
