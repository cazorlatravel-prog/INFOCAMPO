<?php
/**
 * INFOCAMPO - Gestión de Tipos de Trabajo (Admin)
 *
 * Permite al administrador de empresa crear, editar y gestionar
 * los tipos de trabajo que verán los operadores en campo.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'supervisor', 'superadmin']);

$pdo = getDB();

$currentPage = 'tipos_trabajo';

$empresaId = getEmpresaIdSeguro();

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
                    "INSERT INTO tipos_trabajo (empresa_id, nombre, codigo, descripcion)
                     VALUES (:emp_id, :nombre, :codigo, :descripcion)"
                );
                $stmt->execute([
                    ':emp_id'      => $targetEmpId,
                    ':nombre'      => $nombre,
                    ':codigo'      => $codigo ?: null,
                    ':descripcion' => $descripcion ?: null,
                ]);
                $msg = 'Tipo de trabajo creado correctamente.';
                $msgType = 'success';
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE tipos_trabajo SET nombre = :nombre, codigo = :codigo, descripcion = :descripcion
                     WHERE id = :id AND empresa_id = :emp_id"
                );
                $stmt->execute([
                    ':nombre'      => $nombre,
                    ':codigo'      => $codigo ?: null,
                    ':descripcion' => $descripcion ?: null,
                    ':id'          => $id,
                    ':emp_id'      => $empresaId,
                ]);
                $msg = 'Tipo de trabajo actualizado.';
                $msgType = 'success';
            }
        }
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE tipos_trabajo SET activa = NOT activa WHERE id = :id AND empresa_id = :emp_id")
                ->execute([':id' => $id, ':emp_id' => $empresaId]);
            $msg = 'Estado actualizado.';
            $msgType = 'info';
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM tipos_trabajo WHERE id = :id AND empresa_id = :emp_id")
                ->execute([':id' => $id, ':emp_id' => $empresaId]);
            $msg = 'Tipo de trabajo eliminado correctamente.';
            $msgType = 'success';
        }
    }
}

// ---------------------------------------------------------------
// Cargar tipos de trabajo
// ---------------------------------------------------------------
$tipos = [];
$empresaNombre = '';
if ($empresaId > 0) {
    $stmt = $pdo->prepare("SELECT nombre FROM empresas WHERE id = :id");
    $stmt->execute([':id' => $empresaId]);
    $row = $stmt->fetch();
    if ($row) $empresaNombre = $row['nombre'];

    $stmt = $pdo->prepare(
        "SELECT * FROM tipos_trabajo WHERE empresa_id = :emp_id ORDER BY activa DESC, nombre ASC"
    );
    $stmt->execute([':emp_id' => $empresaId]);
    $tipos = $stmt->fetchAll();
}

// Si hay ?edit=ID
$editTipo = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM tipos_trabajo WHERE id = :id AND empresa_id = :emp_id");
    $stmt->execute([':id' => $editId, ':emp_id' => $empresaId]);
    $editTipo = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FotoGPS.app - Tipos de Trabajo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/admin/css/admin.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h4 class="mb-0"><i class="bi bi-briefcase me-2"></i>Tipos de Trabajo</h4>

            <div class="d-flex gap-2 align-items-center">
                <?php if ($empresaId > 0): ?>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formTipo">
                        <i class="bi bi-plus-lg"></i> Nuevo Tipo
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
            <div class="collapse <?= $editTipo ? 'show' : '' ?>" id="formTipo">
                <div class="form-section">
                    <h5 class="mb-3"><?= $editTipo ? 'Editar Tipo de Trabajo' : 'Nuevo Tipo de Trabajo' ?></h5>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="<?= $editTipo ? 'update' : 'create' ?>">
                        <input type="hidden" name="empresa_id" value="<?= $empresaId ?>">
                        <?php if ($editTipo): ?>
                            <input type="hidden" name="id" value="<?= $editTipo['id'] ?>">
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Nombre *</label>
                                <input type="text" name="nombre" class="form-control" required
                                       placeholder="Ej: Inspección, Mantenimiento, Reparación" value="<?= htmlspecialchars($editTipo['nombre'] ?? '') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Código</label>
                                <input type="text" name="codigo" class="form-control"
                                       placeholder="Ej: INSP, MANT" value="<?= htmlspecialchars($editTipo['codigo'] ?? '') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Descripción</label>
                                <input type="text" name="descripcion" class="form-control"
                                       placeholder="Descripción breve" value="<?= htmlspecialchars($editTipo['descripcion'] ?? '') ?>">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-check-lg"></i> <?= $editTipo ? 'Guardar' : 'Crear' ?>
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
                                <?php foreach ($tipos as $t): ?>
                                    <tr class="<?= $t['activa'] ? '' : 'opacity-50' ?>">
                                        <td><strong><?= htmlspecialchars($t['nombre']) ?></strong></td>
                                        <td class="small"><?= htmlspecialchars($t['codigo'] ?? '-') ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars($t['descripcion'] ?? '-') ?></td>
                                        <td>
                                            <?php if ($t['activa']): ?>
                                                <span class="badge bg-success" style="font-size:0.7rem;">Activo</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger" style="font-size:0.7rem;">Inactivo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex gap-1 justify-content-end">
                                                <a href="?empresa_id=<?= $empresaId ?>&edit=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <form method="post" class="d-inline" onsubmit="return confirm('¿Cambiar estado?')">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-<?= $t['activa'] ? 'warning' : 'success' ?>">
                                                        <i class="bi bi-<?= $t['activa'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                                    </button>
                                                </form>
                                                <form method="post" class="d-inline" onsubmit="return confirm('¿ELIMINAR este tipo de trabajo permanentemente? Esta acción no se puede deshacer.')">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar tipo de trabajo">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($tipos)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">
                                            No hay tipos de trabajo. Crea el primero con el botón "Nuevo Tipo".
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
                <i class="bi bi-briefcase" style="font-size:3rem;color:#adb5bd;"></i>
                <h5 class="mt-3 text-muted">Selecciona una empresa</h5>
                <p class="text-muted">Elige una empresa del selector para gestionar sus tipos de trabajo.</p>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
