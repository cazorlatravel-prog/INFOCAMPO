<?php
/**
 * INFOCAMPO SaaS - Gestión de Unidades de Obra (Admin)
 *
 * Permite al administrador de empresa crear, editar y gestionar
 * las unidades de obra que verán los operadores en campo.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$pdo = getDB();

// Detectar si estamos en modo suplantación
$impersonating = isImpersonating();

// Obtener empresa_id (de la sesión si admin autenticado, o de la query si superadmin suplantando)
$empresaId = isset($_GET['empresa_id']) ? (int) $_GET['empresa_id'] : ($_SESSION['empresa_id'] ?? 0);

// ---------------------------------------------------------------
// Procesar acciones POST
// ---------------------------------------------------------------
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $id          = (int) ($_POST['id'] ?? 0);
        $nombre      = trim($_POST['nombre'] ?? '');
        $codigo      = trim($_POST['codigo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $targetEmpId = (int) ($_POST['empresa_id'] ?? $empresaId);

        if ($nombre === '' || $targetEmpId <= 0) {
            $msg = 'El nombre es obligatorio.';
            $msgType = 'danger';
        } else {
            if ($action === 'create') {
                $stmt = $pdo->prepare(
                    "INSERT INTO unidades_obra (empresa_id, nombre, codigo, descripcion)
                     VALUES (:emp_id, :nombre, :codigo, :descripcion)"
                );
                $stmt->execute([
                    ':emp_id'      => $targetEmpId,
                    ':nombre'      => $nombre,
                    ':codigo'      => $codigo ?: null,
                    ':descripcion' => $descripcion ?: null,
                ]);
                $msg = 'Unidad de obra creada correctamente.';
                $msgType = 'success';
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE unidades_obra SET nombre = :nombre, codigo = :codigo, descripcion = :descripcion
                     WHERE id = :id"
                );
                $stmt->execute([
                    ':nombre'      => $nombre,
                    ':codigo'      => $codigo ?: null,
                    ':descripcion' => $descripcion ?: null,
                    ':id'          => $id,
                ]);
                $msg = 'Unidad de obra actualizada.';
                $msgType = 'success';
            }
        }
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE unidades_obra SET activa = NOT activa WHERE id = :id")
                ->execute([':id' => $id]);
            $msg = 'Estado actualizado.';
            $msgType = 'info';
        }
    }
}

// ---------------------------------------------------------------
// Cargar empresas (para selector si es superadmin)
// ---------------------------------------------------------------
$empresas = $pdo->query(
    "SELECT id, nombre FROM empresas WHERE activa = 1 AND id != 9999 ORDER BY nombre"
)->fetchAll();

// ---------------------------------------------------------------
// Cargar unidades de obra
// ---------------------------------------------------------------
$unidades = [];
$empresaNombre = '';
if ($empresaId > 0) {
    $stmt = $pdo->prepare("SELECT nombre FROM empresas WHERE id = :id");
    $stmt->execute([':id' => $empresaId]);
    $row = $stmt->fetch();
    if ($row) $empresaNombre = $row['nombre'];

    $stmt = $pdo->prepare(
        "SELECT * FROM unidades_obra WHERE empresa_id = :emp_id ORDER BY activa DESC, nombre ASC"
    );
    $stmt->execute([':emp_id' => $empresaId]);
    $unidades = $stmt->fetchAll();
}

// Si hay ?edit=ID
$editUnidad = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM unidades_obra WHERE id = :id");
    $stmt->execute([':id' => $editId]);
    $editUnidad = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INFOCAMPO - Unidades de Obra</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
        .brand-bar {
            background: linear-gradient(135deg, #1e3a5f, #2d6a9f);
            color: #fff; padding: 18px 24px;
        }
        .brand-bar h1 { font-size: 1.3rem; margin: 0; font-weight: 700; }
        .card { border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border-radius: 12px; }
        .form-section {
            background: #fff; border-radius: 12px; padding: 24px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06); margin-bottom: 20px;
        }
        .nav-admin {
            background: #fff; border-bottom: 1px solid #e5e7eb; padding: 0 24px;
        }
        .nav-admin .nav-link {
            color: #6b7280; padding: 12px 16px; font-size: 0.9rem;
            border-bottom: 2px solid transparent;
        }
        .nav-admin .nav-link:hover, .nav-admin .nav-link.active {
            color: #1e3a5f; border-bottom-color: #1e3a5f;
        }
    </style>
</head>
<body>
    <?php if ($impersonating): ?>
    <div style="background:linear-gradient(90deg,#f59e0b,#d97706);color:#fff;padding:10px 24px;display:flex;align-items:center;justify-content:space-between;font-size:0.9rem;">
        <div>
            <i class="bi bi-eye"></i>
            Viendo como: <strong><?= htmlspecialchars($_SESSION['user_name']) ?></strong>
            (<?= htmlspecialchars($_SESSION['user_rol']) ?> - <?= htmlspecialchars($_SESSION['empresa_nombre']) ?>)
        </div>
        <a href="/superadmin/impersonate.php?stop=1" class="btn btn-sm btn-light fw-semibold" style="color:#92400e;">
            <i class="bi bi-box-arrow-left"></i> Volver a Super Admin
        </a>
    </div>
    <?php endif; ?>

    <div class="brand-bar d-flex align-items-center justify-content-between">
        <h1>INFOCAMPO &mdash; Panel de Administración</h1>
        <span class="small opacity-75"><?= date('d/m/Y H:i') ?></span>
    </div>

    <!-- Navigation -->
    <nav class="nav-admin">
        <ul class="nav">
            <li><a href="index.php<?= $empresaId ? '?empresa_id=' . $empresaId : '' ?>" class="nav-link">
                <i class="bi bi-speedometer2"></i> Infraestructuras
            </a></li>
            <li><a href="unidades_obra.php<?= $empresaId ? '?empresa_id=' . $empresaId : '' ?>" class="nav-link active">
                <i class="bi bi-tools"></i> Unidades de Obra
            </a></li>
            <li><a href="usuarios.php<?= $empresaId ? '?empresa_id=' . $empresaId : '' ?>" class="nav-link">
                <i class="bi bi-people"></i> Usuarios
            </a></li>
        </ul>
    </nav>

    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h4 class="mb-0"><i class="bi bi-tools me-2"></i>Unidades de Obra</h4>

            <div class="d-flex gap-2 align-items-center">
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
                <?php if ($empresaId > 0): ?>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formUnidad">
                        <i class="bi bi-plus-lg"></i> Nueva Unidad
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?= $msgType ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($empresaId > 0): ?>

            <!-- Form crear/editar -->
            <div class="collapse <?= $editUnidad ? 'show' : '' ?>" id="formUnidad">
                <div class="form-section">
                    <h5 class="mb-3"><?= $editUnidad ? 'Editar Unidad de Obra' : 'Nueva Unidad de Obra' ?></h5>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="<?= $editUnidad ? 'update' : 'create' ?>">
                        <input type="hidden" name="empresa_id" value="<?= $empresaId ?>">
                        <?php if ($editUnidad): ?>
                            <input type="hidden" name="id" value="<?= $editUnidad['id'] ?>">
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Nombre *</label>
                                <input type="text" name="nombre" class="form-control" required
                                       placeholder="Ej: Cimentación" value="<?= htmlspecialchars($editUnidad['nombre'] ?? '') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Código</label>
                                <input type="text" name="codigo" class="form-control"
                                       placeholder="Ej: UO-001" value="<?= htmlspecialchars($editUnidad['codigo'] ?? '') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Descripción</label>
                                <input type="text" name="descripcion" class="form-control"
                                       placeholder="Descripción breve" value="<?= htmlspecialchars($editUnidad['descripcion'] ?? '') ?>">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-check-lg"></i> <?= $editUnidad ? 'Guardar' : 'Crear' ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Table -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr class="text-muted small">
                                    <th>Nombre</th>
                                    <th>Código</th>
                                    <th>Descripción</th>
                                    <th>Estado</th>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($unidades as $u): ?>
                                    <tr class="<?= $u['activa'] ? '' : 'opacity-50' ?>">
                                        <td><strong><?= htmlspecialchars($u['nombre']) ?></strong></td>
                                        <td class="small"><?= htmlspecialchars($u['codigo'] ?? '-') ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars($u['descripcion'] ?? '-') ?></td>
                                        <td>
                                            <?php if ($u['activa']): ?>
                                                <span class="badge bg-success" style="font-size:0.7rem;">Activa</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger" style="font-size:0.7rem;">Inactiva</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex gap-1 justify-content-end">
                                                <a href="?empresa_id=<?= $empresaId ?>&edit=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <form method="post" class="d-inline" onsubmit="return confirm('¿Cambiar estado?')">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-<?= $u['activa'] ? 'warning' : 'success' ?>">
                                                        <i class="bi bi-<?= $u['activa'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($unidades)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">
                                            No hay unidades de obra. Crea la primera con el botón "Nueva Unidad".
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
                <i class="bi bi-tools" style="font-size:3rem;color:#adb5bd;"></i>
                <h5 class="mt-3 text-muted">Selecciona una empresa</h5>
                <p class="text-muted">Elige una empresa del selector para gestionar sus unidades de obra.</p>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
