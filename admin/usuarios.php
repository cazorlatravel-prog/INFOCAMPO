<?php
/**
 * INFOCAMPO SaaS - Gestión de Usuarios (Admin de Empresa)
 *
 * Permite al administrador de empresa crear, gestionar usuarios
 * y obtener automáticamente el enlace de acceso para cada operador.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'supervisor', 'superadmin']);

$pdo = getDB();
$currentPage = 'usuarios';

// Obtener empresa_id (de la sesión si admin autenticado, o de la query si superadmin suplantando)
$empresaId = getEmpresaIdSeguro();

// ---------------------------------------------------------------
// Procesar acciones POST
// ---------------------------------------------------------------
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrf()) {
    $action = $_POST['action'] ?? '';

    // --- Crear usuario ---
    if ($action === 'create_user') {
        $nombre   = trim($_POST['nombre'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $password = $_POST['password'] ?? '';
        $rol      = $_POST['rol'] ?? 'operador';

        // Limpiar teléfono: solo dígitos y +
        if ($telefono !== '') {
            $telefono = preg_replace('/[^0-9+]/', '', $telefono);
        }

        // Email vacío se guarda como NULL
        if ($email === '') $email = null;
        if ($telefono === '') $telefono = null;

        if ($nombre === '' || $password === '') {
            $msg = 'El nombre y la contraseña son obligatorios.';
            $msgType = 'danger';
        } elseif ($email === null && $telefono === null) {
            $msg = 'Debes introducir al menos un email o un teléfono.';
            $msgType = 'danger';
        } elseif (strlen($password) < 12) {
            $msg = 'La contraseña debe tener al menos 12 caracteres.';
            $msgType = 'danger';
        } elseif (!in_array($rol, ['admin', 'supervisor', 'operador'], true)) {
            $msg = 'Rol no válido.';
            $msgType = 'danger';
        } elseif ($empresaId <= 0) {
            $msg = 'No se ha podido identificar la empresa.';
            $msgType = 'danger';
        } elseif ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'El email no es válido.';
            $msgType = 'danger';
        } else {
            // Verificar email único (si se proporcionó)
            $duplicado = false;
            if ($email !== null) {
                $check = $pdo->prepare("SELECT id FROM usuarios WHERE email = :email");
                $check->execute([':email' => $email]);
                if ($check->fetch()) {
                    $msg = 'Ya existe un usuario con ese email.';
                    $msgType = 'danger';
                    $duplicado = true;
                }
            }
            // Verificar teléfono único (si se proporcionó)
            if (!$duplicado && $telefono !== null) {
                $check = $pdo->prepare("SELECT id FROM usuarios WHERE telefono = :tel");
                $check->execute([':tel' => $telefono]);
                if ($check->fetch()) {
                    $msg = 'Ya existe un usuario con ese teléfono.';
                    $msgType = 'danger';
                    $duplicado = true;
                }
            }

            if (!$duplicado) {
                // Verificar límite de usuarios de la empresa
                $empStmt = $pdo->prepare("SELECT max_usuarios FROM empresas WHERE id = :id");
                $empStmt->execute([':id' => $empresaId]);
                $empresa = $empStmt->fetch();

                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE empresa_id = :id AND rol != 'superadmin'");
                $countStmt->execute([':id' => $empresaId]);
                $currentCount = (int) $countStmt->fetchColumn();

                if ($empresa && $currentCount >= (int) $empresa['max_usuarios']) {
                    $msg = 'Se ha alcanzado el límite máximo de usuarios (' . $empresa['max_usuarios'] . ').';
                    $msgType = 'warning';
                } else {
                    $hash = hashPassword($password);
                    $stmt = $pdo->prepare(
                        "INSERT INTO usuarios (empresa_id, nombre, email, telefono, password, rol, activo)
                         VALUES (:emp_id, :nombre, :email, :telefono, :password, :rol, 1)"
                    );
                    $stmt->execute([
                        ':emp_id'    => $empresaId,
                        ':nombre'    => $nombre,
                        ':email'     => $email,
                        ':telefono'  => $telefono,
                        ':password'  => $hash,
                        ':rol'       => $rol,
                    ]);
                    $newUserId = (int) $pdo->lastInsertId();
                    $msg = 'Usuario creado correctamente.';
                    $msgType = 'success';

                    // Si es operador, mostrar el enlace generado
                    if ($rol === 'operador') {
                        $link = APP_URL . '/public/operador.php?user=' . $newUserId . '&empresa=' . $empresaId;
                        $msg .= ' Enlace de acceso: <br><code>' . htmlspecialchars($link) . '</code>';
                    }
                }
            }
        }
    }

    // --- Cambiar contraseña ---
    if ($action === 'change_password') {
        $targetId    = (int) ($_POST['user_id'] ?? 0);
        $newPass     = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if ($targetId <= 0) {
            $msg = 'Usuario no válido.';
            $msgType = 'danger';
        } elseif (strlen($newPass) < 12) {
            $msg = 'La contraseña debe tener al menos 12 caracteres.';
            $msgType = 'danger';
        } elseif ($newPass !== $confirmPass) {
            $msg = 'Las contraseñas no coinciden.';
            $msgType = 'danger';
        } else {
            $hash = hashPassword($newPass);
            $stmt = $pdo->prepare(
                "UPDATE usuarios SET password = :pass WHERE id = :id AND empresa_id = :emp_id AND rol != 'superadmin'"
            );
            $stmt->execute([':pass' => $hash, ':id' => $targetId, ':emp_id' => $empresaId]);
            if ($stmt->rowCount() > 0) {
                $msg = 'Contraseña actualizada correctamente.';
                $msgType = 'success';
            } else {
                $msg = 'No se pudo actualizar la contraseña.';
                $msgType = 'danger';
            }
        }
    }

    // --- Activar/Desactivar usuario ---
    if ($action === 'toggle_user') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        if ($targetId > 0) {
            $pdo->prepare(
                "UPDATE usuarios SET activo = NOT activo WHERE id = :id AND empresa_id = :emp_id AND rol != 'superadmin'"
            )->execute([':id' => $targetId, ':emp_id' => $empresaId]);
            $msg = 'Estado del usuario actualizado.';
            $msgType = 'info';
        }
    }

    // --- Eliminar usuario ---
    if ($action === 'delete_user') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        if ($targetId > 0 && $empresaId > 0) {
            // Verificar que el usuario pertenece a esta empresa y no es superadmin
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = :id AND empresa_id = :emp_id AND rol != 'superadmin'");
            $stmt->execute([':id' => $targetId, ':emp_id' => $empresaId]);
            if ($stmt->fetch()) {
                // Eliminar registros asociados al usuario (valores_campo se borran en cascada)
                $pdo->prepare("DELETE FROM valores_campo WHERE registro_id IN (SELECT id FROM registros WHERE usuario_id = :uid)")
                    ->execute([':uid' => $targetId]);
                $pdo->prepare("DELETE FROM registros WHERE usuario_id = :uid")
                    ->execute([':uid' => $targetId]);
                // Eliminar el usuario
                $pdo->prepare("DELETE FROM usuarios WHERE id = :id AND empresa_id = :emp_id AND rol != 'superadmin'")
                    ->execute([':id' => $targetId, ':emp_id' => $empresaId]);
                $msg = 'Usuario eliminado correctamente.';
                $msgType = 'success';
            } else {
                $msg = 'No se pudo eliminar el usuario.';
                $msgType = 'danger';
            }
        }
    }
}

// ---------------------------------------------------------------
// Cargar empresas (solo superadmin)
// ---------------------------------------------------------------
$isSuperadmin = ($_SESSION['user_role'] ?? '') === 'superadmin';
if ($isSuperadmin) {
    $empresas = $pdo->query(
        "SELECT id, nombre FROM empresas WHERE activa = 1 AND id != 9999 ORDER BY nombre"
    )->fetchAll();
} else {
    $empresas = [];
}

// ---------------------------------------------------------------
// Cargar datos de la empresa y usuarios
// ---------------------------------------------------------------
$usuarios = [];
$empresaNombre = '';
$empresaData = null;
if ($empresaId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM empresas WHERE id = :id");
    $stmt->execute([':id' => $empresaId]);
    $empresaData = $stmt->fetch();
    if ($empresaData) $empresaNombre = $empresaData['nombre'];

    $stmt = $pdo->prepare(
        "SELECT * FROM usuarios
         WHERE empresa_id = :emp_id AND rol != 'superadmin'
         ORDER BY rol ASC, nombre ASC"
    );
    $stmt->execute([':emp_id' => $empresaId]);
    $usuarios = $stmt->fetchAll();
}

// Contar usuarios activos
$countActivos = 0;
foreach ($usuarios as $u) {
    if ($u['activo']) $countActivos++;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FotoGPS.app - Gestión de Usuarios</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/admin/css/admin.css" rel="stylesheet">
    <style>
        .stat-card { text-align: center; }
        .stat-card .stat-label { font-size: 0.8rem; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h4 class="mb-0"><i class="bi bi-people me-2"></i>Usuarios</h4>

            <div class="d-flex gap-2 align-items-center">
                <?php if ($isSuperadmin): ?>
                <form method="get" class="d-flex gap-2 align-items-center">
                    <label class="fw-semibold small text-nowrap">Empresa:</label>
                    <select name="empresa_id" class="form-select form-select-sm" style="width:220px;" onchange="this.form.submit()">
                        <option value="">-- Seleccionar --</option>
                        <?php foreach ($empresas as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= $empresaId === (int)$emp['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($emp['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php endif; ?>
                <?php if ($empresaId > 0): ?>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formUsuario">
                        <i class="bi bi-plus-lg"></i> Nuevo Usuario
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?= $msgType ?> alert-dismissible fade show" role="alert">
                <?= $msg /* ya tiene htmlspecialchars donde corresponde */ ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($empresaId > 0): ?>

            <!-- Estadísticas rápidas -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-number"><?= count($usuarios) ?></div>
                        <div class="stat-label">Total Usuarios</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-number"><?= $countActivos ?></div>
                        <div class="stat-label">Activos</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-number"><?= $empresaData ? $empresaData['max_usuarios'] : '-' ?></div>
                        <div class="stat-label">Límite Plan</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-number"><?= $empresaData ? ((int)$empresaData['max_usuarios'] - $countActivos) : '-' ?></div>
                        <div class="stat-label">Disponibles</div>
                    </div>
                </div>
            </div>

            <!-- Formulario crear usuario -->
            <div class="collapse" id="formUsuario">
                <div class="form-section">
                    <h5 class="mb-3"><i class="bi bi-person-plus me-2"></i>Nuevo Usuario</h5>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="create_user">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label fw-semibold small">Nombre completo *</label>
                                <input type="text" name="nombre" class="form-control" required placeholder="Juan García">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold small">Email</label>
                                <input type="email" name="email" class="form-control" placeholder="juan@empresa.com">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold small">Teléfono</label>
                                <input type="tel" name="telefono" class="form-control" placeholder="600123456">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold small">Rol *</label>
                                <select name="rol" class="form-select">
                                    <option value="operador" selected>Operador</option>
                                    <option value="supervisor">Supervisor</option>
                                    <option value="admin">Administrador</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold small">Contraseña *</label>
                                <input type="password" name="password" class="form-control" required minlength="12" placeholder="Min. 12 caracteres">
                            </div>
                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-person-plus"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-text mt-2"><i class="bi bi-info-circle me-1"></i>Email o teléfono: al menos uno es obligatorio. Los operadores sin email pueden acceder con su teléfono.</div>
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
                                    <th>Rol</th>
                                    <th>Estado</th>
                                    <th>Enlace de Acceso</th>
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
                                    $operadorLink = APP_URL . '/public/operador.php?user=' . $u['id'] . '&empresa=' . $empresaId;
                                    ?>
                                    <tr class="<?= $u['activo'] ? '' : 'opacity-50' ?>">
                                        <td>
                                            <strong><?= htmlspecialchars($u['nombre']) ?></strong>
                                            <?php if (!empty($u['email'])): ?>
                                                <br><small class="text-muted"><i class="bi bi-envelope"></i> <?= htmlspecialchars($u['email']) ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($u['telefono'])): ?>
                                                <br><small class="text-muted"><i class="bi bi-phone"></i> <?= htmlspecialchars($u['telefono']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge <?= $rolBadge ?> badge-rol"><?= $u['rol'] ?></span></td>
                                        <td>
                                            <?php if ($u['activo']): ?>
                                                <span class="badge bg-success badge-rol">Activo</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger badge-rol">Inactivo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($u['rol'] === 'operador'): ?>
                                                <div class="link-box">
                                                    <code id="link-<?= $u['id'] ?>"><?= htmlspecialchars($operadorLink) ?></code>
                                                    <button type="button" class="btn-copy" onclick="copyLink(<?= $u['id'] ?>, this)">
                                                        <i class="bi bi-clipboard"></i> Copiar
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">Accede por login</span>
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

                                                <!-- Eliminar -->
                                                <form method="post" class="d-inline" onsubmit="return confirm('¿ELIMINAR este usuario permanentemente? Se borrarán también todos sus registros e inspecciones. Esta acción no se puede deshacer.')">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar usuario">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($usuarios)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">
                                            No hay usuarios. Crea el primero con el botón "Nuevo Usuario".
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <div class="text-center py-5">
                <i class="bi bi-people" style="font-size:3rem;color:#adb5bd;"></i>
                <h5 class="mt-3 text-muted">Selecciona una empresa</h5>
                <p class="text-muted">Elige una empresa del selector para gestionar sus usuarios.</p>
            </div>
        <?php endif; ?>
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
                            <input type="password" name="new_password" class="form-control" required minlength="12"
                                   placeholder="Mínimo 12 caracteres">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Confirmar contraseña</label>
                            <input type="password" name="confirm_password" class="form-control" required minlength="12"
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

    function copyLink(userId, btn) {
        var code = document.getElementById('link-' + userId);
        var text = code.textContent;

        navigator.clipboard.writeText(text).then(function() {
            btn.innerHTML = '<i class="bi bi-check-lg"></i> Copiado';
            btn.classList.add('copied');
            setTimeout(function() {
                btn.innerHTML = '<i class="bi bi-clipboard"></i> Copiar';
                btn.classList.remove('copied');
            }, 2000);
        }).catch(function() {
            // Fallback para navegadores que no soportan clipboard API
            var range = document.createRange();
            range.selectNode(code);
            window.getSelection().removeAllRanges();
            window.getSelection().addRange(range);
            document.execCommand('copy');
            window.getSelection().removeAllRanges();

            btn.innerHTML = '<i class="bi bi-check-lg"></i> Copiado';
            btn.classList.add('copied');
            setTimeout(function() {
                btn.innerHTML = '<i class="bi bi-clipboard"></i> Copiar';
                btn.classList.remove('copied');
            }, 2000);
        });
    }
    </script>
</body>
</html>
