<?php
/**
 * INFOCAMPO SaaS - Gestión de Usuarios (Super Admin)
 *
 * Permite al superadmin ver, crear, editar contraseñas y gestionar
 * los usuarios de cada empresa.
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

    // --- Cambiar contraseña ---
    if ($action === 'change_password') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $newPass  = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if ($targetId <= 0) {
            $msg = 'Usuario no válido.';
            $msgType = 'danger';
        } elseif (strlen($newPass) < 6) {
            $msg = 'La contraseña debe tener al menos 6 caracteres.';
            $msgType = 'danger';
        } elseif ($newPass !== $confirmPass) {
            $msg = 'Las contraseñas no coinciden.';
            $msgType = 'danger';
        } else {
            $hash = hashPassword($newPass);
            $stmt = $pdo->prepare("UPDATE usuarios SET password = :pass WHERE id = :id AND rol != 'superadmin'");
            $stmt->execute([':pass' => $hash, ':id' => $targetId]);
            if ($stmt->rowCount() > 0) {
                $msg = 'Contraseña actualizada correctamente.';
                $msgType = 'success';
            } else {
                $msg = 'No se pudo actualizar la contraseña.';
                $msgType = 'danger';
            }
        }
    }

    // --- Crear usuario ---
    if ($action === 'create_user') {
        $nombre    = trim($_POST['nombre'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $rol       = $_POST['rol'] ?? 'operador';
        $empId     = (int) ($_POST['empresa_id'] ?? 0);

        if ($nombre === '' || $email === '' || $password === '' || $empId <= 0) {
            $msg = 'Todos los campos son obligatorios.';
            $msgType = 'danger';
        } elseif (strlen($password) < 6) {
            $msg = 'La contraseña debe tener al menos 6 caracteres.';
            $msgType = 'danger';
        } elseif (!in_array($rol, ['admin', 'supervisor', 'operador'], true)) {
            $msg = 'Rol no válido.';
            $msgType = 'danger';
        } else {
            // Verificar email único
            $check = $pdo->prepare("SELECT id FROM usuarios WHERE email = :email");
            $check->execute([':email' => $email]);
            if ($check->fetch()) {
                $msg = 'Ya existe un usuario con ese email.';
                $msgType = 'danger';
            } else {
                // Verificar límite de usuarios de la empresa
                $empStmt = $pdo->prepare("SELECT max_usuarios FROM empresas WHERE id = :id");
                $empStmt->execute([':id' => $empId]);
                $empresa = $empStmt->fetch();

                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE empresa_id = :id AND rol != 'superadmin'");
                $countStmt->execute([':id' => $empId]);
                $currentCount = (int) $countStmt->fetchColumn();

                if ($empresa && $currentCount >= (int) $empresa['max_usuarios']) {
                    $msg = 'La empresa ha alcanzado el límite máximo de usuarios (' . $empresa['max_usuarios'] . ').';
                    $msgType = 'warning';
                } else {
                    $hash = hashPassword($password);
                    $stmt = $pdo->prepare(
                        "INSERT INTO usuarios (empresa_id, nombre, email, password, rol, activo)
                         VALUES (:emp_id, :nombre, :email, :password, :rol, 1)"
                    );
                    $stmt->execute([
                        ':emp_id'   => $empId,
                        ':nombre'   => $nombre,
                        ':email'    => $email,
                        ':password' => $hash,
                        ':rol'      => $rol,
                    ]);
                    $msg = 'Usuario creado correctamente.';
                    $msgType = 'success';
                }
            }
        }
    }

    // --- Activar/Desactivar usuario ---
    if ($action === 'toggle_user') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        if ($targetId > 0) {
            $pdo->prepare("UPDATE usuarios SET activo = NOT activo WHERE id = :id AND rol != 'superadmin'")
                ->execute([':id' => $targetId]);
            $msg = 'Estado del usuario actualizado.';
            $msgType = 'info';
        }
    }
}

// ---------------------------------------------------------------
// Cargar empresas para filtro
// ---------------------------------------------------------------
$empresas = $pdo->query(
    "SELECT id, nombre FROM empresas WHERE id != 9999 ORDER BY nombre"
)->fetchAll();

$empresaId = isset($_GET['empresa_id']) ? (int) $_GET['empresa_id'] : 0;

// ---------------------------------------------------------------
// Cargar usuarios
// ---------------------------------------------------------------
$usuarios = [];
$empresaSeleccionada = null;
if ($empresaId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM empresas WHERE id = :id AND id != 9999");
    $stmt->execute([':id' => $empresaId]);
    $empresaSeleccionada = $stmt->fetch();

    $stmt = $pdo->prepare(
        "SELECT u.*, e.nombre AS empresa_nombre
         FROM usuarios u
         INNER JOIN empresas e ON u.empresa_id = e.id
         WHERE u.empresa_id = :emp_id AND u.rol != 'superadmin'
         ORDER BY u.rol ASC, u.nombre ASC"
    );
    $stmt->execute([':emp_id' => $empresaId]);
    $usuarios = $stmt->fetchAll();
} else {
    // Mostrar todos los usuarios (excepto superadmin)
    $usuarios = $pdo->query(
        "SELECT u.*, e.nombre AS empresa_nombre
         FROM usuarios u
         INNER JOIN empresas e ON u.empresa_id = e.id
         WHERE u.rol != 'superadmin'
         ORDER BY e.nombre ASC, u.rol ASC, u.nombre ASC"
    )->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INFOCAMPO - Gestión de Usuarios</title>
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
        .card { border: none; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
        .form-section { background: #fff; border-radius: 12px; padding: 28px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); margin-bottom: 24px; }
        .badge-rol {
            font-size: 0.7rem; padding: 4px 10px; border-radius: 6px;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
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
            <h2>INFOCAMPO</h2>
            <small>Super Administración</small>
        </div>
        <ul class="nav flex-column mt-2">
            <li><a href="index.php" class="nav-link"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
            <li><a href="empresas.php" class="nav-link"><i class="bi bi-building"></i> Empresas</a></li>
            <li><a href="usuarios.php" class="nav-link active"><i class="bi bi-people"></i> Usuarios</a></li>
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
            <a href="logout.php" class="btn btn-sm btn-outline-light w-100" style="opacity:0.6;font-size:0.8rem;">
                <i class="bi bi-box-arrow-left"></i> Cerrar sesión
            </a>
        </div>
    </nav>

    <div class="sa-main">
        <div class="sa-topbar">
            <h1><i class="bi bi-people me-2"></i>Gestión de Usuarios</h1>

            <div class="d-flex gap-2 align-items-center">
                <!-- Filtro por empresa -->
                <form method="get" class="d-flex gap-2 align-items-center">
                    <label class="fw-semibold small text-nowrap">Empresa:</label>
                    <select name="empresa_id" class="form-select form-select-sm" style="width:220px;" onchange="this.form.submit()">
                        <option value="">-- Todas --</option>
                        <?php foreach ($empresas as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= $empresaId === (int)$emp['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($emp['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formUsuario">
                    <i class="bi bi-plus-lg"></i> Nuevo Usuario
                </button>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?= $msgType ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Formulario crear usuario -->
        <div class="collapse" id="formUsuario">
            <div class="form-section">
                <h5 class="mb-3">Nuevo Usuario</h5>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create_user">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Empresa *</label>
                            <select name="empresa_id" class="form-select" required>
                                <option value="">-- Seleccionar --</option>
                                <?php foreach ($empresas as $emp): ?>
                                    <option value="<?= $emp['id'] ?>" <?= $empresaId === (int)$emp['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($emp['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Nombre completo *</label>
                            <input type="text" name="nombre" class="form-control" required placeholder="Juan García">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Email *</label>
                            <input type="email" name="email" class="form-control" required placeholder="juan@empresa.com">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Rol *</label>
                            <select name="rol" class="form-select">
                                <option value="admin">Administrador</option>
                                <option value="supervisor">Supervisor</option>
                                <option value="operador" selected>Operador</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Contraseña *</label>
                            <input type="password" name="password" class="form-control" required minlength="6" placeholder="Min. 6 caracteres">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-person-plus"></i> Crear usuario
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tabla de usuarios -->
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr class="text-muted small">
                                <th>Usuario</th>
                                <th>Empresa</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Último login</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios as $u): ?>
                                <?php
                                $rolBadge = match($u['rol']) {
                                    'admin'      => 'bg-primary',
                                    'supervisor'  => 'bg-info',
                                    'operador'    => 'bg-secondary',
                                    default       => 'bg-dark',
                                };
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($u['nombre']) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($u['email']) ?></small>
                                    </td>
                                    <td class="small"><?= htmlspecialchars($u['empresa_nombre']) ?></td>
                                    <td><span class="badge <?= $rolBadge ?> badge-rol"><?= $u['rol'] ?></span></td>
                                    <td>
                                        <?php if ($u['activo']): ?>
                                            <span class="badge bg-success badge-rol">Activo</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger badge-rol">Inactivo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted">
                                        <?= $u['ultimo_login'] ? date('d/m/Y H:i', strtotime($u['ultimo_login'])) : 'Nunca' ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex gap-1 justify-content-end">
                                            <!-- Cambiar contraseña -->
                                            <button type="button" class="btn btn-sm btn-outline-warning"
                                                    title="Cambiar contraseña"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalPassword"
                                                    onclick="setPasswordUser(<?= $u['id'] ?>, '<?= htmlspecialchars($u['nombre'], ENT_QUOTES) ?>')">
                                                <i class="bi bi-key"></i>
                                            </button>

                                            <!-- Suplantar -->
                                            <form method="post" action="impersonate.php" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <input type="hidden" name="empresa_id" value="<?= $u['empresa_id'] ?>">
                                                <input type="hidden" name="target_role" value="<?= $u['rol'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-info" title="Acceder como este usuario">
                                                    <i class="bi bi-box-arrow-in-right"></i>
                                                </button>
                                            </form>

                                            <!-- Activar/Desactivar -->
                                            <form method="post" class="d-inline" onsubmit="return confirm('¿Cambiar estado de este usuario?')">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="toggle_user">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-<?= $u['activo'] ? 'danger' : 'success' ?>"
                                                        title="<?= $u['activo'] ? 'Desactivar' : 'Activar' ?>">
                                                    <i class="bi bi-<?= $u['activo'] ? 'person-x' : 'person-check' ?>"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($usuarios)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        <?php if ($empresaId > 0): ?>
                                            No hay usuarios en esta empresa. Crea uno con el botón "Nuevo Usuario".
                                        <?php else: ?>
                                            No hay usuarios registrados aún. Selecciona una empresa o crea un usuario nuevo.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal cambiar contraseña -->
    <div class="modal fade" id="modalPassword" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="user_id" id="passUserId" value="">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-key me-2"></i>Cambiar contraseña
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted mb-3">
                            Cambiar contraseña de: <strong id="passUserName"></strong>
                        </p>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Nueva contraseña</label>
                            <input type="password" name="new_password" class="form-control" required minlength="6"
                                   placeholder="Mínimo 6 caracteres">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Confirmar contraseña</label>
                            <input type="password" name="confirm_password" class="form-control" required minlength="6"
                                   placeholder="Repetir contraseña">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-check-lg"></i> Cambiar contraseña
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function setPasswordUser(id, name) {
        document.getElementById('passUserId').value = id;
        document.getElementById('passUserName').textContent = name;
    }
    </script>
</body>
</html>
