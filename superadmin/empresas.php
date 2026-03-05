<?php
/**
 * INFOCAMPO SaaS - Gestión de Empresas (Super Admin)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = requireRole('superadmin');

$pdo = getDB();
$currentPage = 'empresas';
$empresaId = (int) ($_SESSION['empresa_id'] ?? 0);

// ---------------------------------------------------------------
// Procesar acciones POST
// ---------------------------------------------------------------
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $id                  = (int) ($_POST['id'] ?? 0);
        $nombre              = trim($_POST['nombre'] ?? '');
        $nif                 = trim($_POST['nif'] ?? '');
        $emailContacto       = trim($_POST['email_contacto'] ?? '');
        $planSuscripcion     = $_POST['plan_suscripcion'] ?? 'free';
        $activa              = isset($_POST['activa']) ? 1 : 0;
        $licenciaInicio      = $_POST['licencia_inicio'] ?: null;
        $licenciaFin         = $_POST['licencia_fin'] ?: null;
        $maxUsuarios         = (int) ($_POST['max_usuarios'] ?? 10);
        $maxInfraestructuras = (int) ($_POST['max_infraestructuras'] ?? 50);

        if ($nombre === '') {
            $msg = 'El nombre de la empresa es obligatorio.';
            $msgType = 'danger';
        } else {
            if ($action === 'create') {
                $stmt = $pdo->prepare(
                    "INSERT INTO empresas (nombre, nif, email_contacto, plan_suscripcion, activa, licencia_inicio, licencia_fin, max_usuarios, max_infraestructuras)
                     VALUES (:nombre, :nif, :email, :plan, :activa, :lic_ini, :lic_fin, :max_u, :max_i)"
                );
                $stmt->execute([
                    ':nombre'  => $nombre,
                    ':nif'     => $nif ?: null,
                    ':email'   => $emailContacto ?: null,
                    ':plan'    => $planSuscripcion,
                    ':activa'  => $activa,
                    ':lic_ini' => $licenciaInicio,
                    ':lic_fin' => $licenciaFin,
                    ':max_u'   => $maxUsuarios,
                    ':max_i'   => $maxInfraestructuras,
                ]);
                $msg = 'Empresa creada correctamente.';
                $msgType = 'success';
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE empresas SET nombre = :nombre, nif = :nif, email_contacto = :email,
                     plan_suscripcion = :plan, activa = :activa, licencia_inicio = :lic_ini,
                     licencia_fin = :lic_fin, max_usuarios = :max_u, max_infraestructuras = :max_i
                     WHERE id = :id AND id != 9999"
                );
                $stmt->execute([
                    ':nombre'  => $nombre,
                    ':nif'     => $nif ?: null,
                    ':email'   => $emailContacto ?: null,
                    ':plan'    => $planSuscripcion,
                    ':activa'  => $activa,
                    ':lic_ini' => $licenciaInicio,
                    ':lic_fin' => $licenciaFin,
                    ':max_u'   => $maxUsuarios,
                    ':max_i'   => $maxInfraestructuras,
                    ':id'      => $id,
                ]);
                $msg = 'Empresa actualizada correctamente.';
                $msgType = 'success';
            }
        }
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && $id !== 9999) {
            $pdo->prepare("UPDATE empresas SET activa = NOT activa WHERE id = :id")
                ->execute([':id' => $id]);
            $msg = 'Estado de empresa actualizado.';
            $msgType = 'info';
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && $id !== 9999) {
            // Eliminar en cascada: valores_campo -> registros -> infraestructuras -> campos_formulario -> unidades_obra -> usuarios -> empresa
            $pdo->prepare("DELETE FROM valores_campo WHERE registro_id IN (SELECT id FROM registros WHERE infra_id IN (SELECT id FROM infraestructuras WHERE empresa_id = :eid))")
                ->execute([':eid' => $id]);
            $pdo->prepare("DELETE FROM registros WHERE infra_id IN (SELECT id FROM infraestructuras WHERE empresa_id = :eid)")
                ->execute([':eid' => $id]);
            $pdo->prepare("DELETE FROM infraestructuras WHERE empresa_id = :eid")
                ->execute([':eid' => $id]);
            $pdo->prepare("DELETE FROM campos_formulario WHERE empresa_id = :eid")
                ->execute([':eid' => $id]);
            $pdo->prepare("DELETE FROM unidades_obra WHERE empresa_id = :eid")
                ->execute([':eid' => $id]);
            $pdo->prepare("DELETE FROM usuarios WHERE empresa_id = :eid AND rol != 'superadmin'")
                ->execute([':eid' => $id]);
            $pdo->prepare("DELETE FROM empresas WHERE id = :eid AND id != 9999")
                ->execute([':eid' => $id]);
            $msg = 'Empresa eliminada correctamente con todos sus datos.';
            $msgType = 'success';
        }
    }
}

// ---------------------------------------------------------------
// Cargar empresas
// ---------------------------------------------------------------
$empresas = $pdo->query(
    "SELECT e.*,
            (SELECT COUNT(*) FROM usuarios u WHERE u.empresa_id = e.id AND u.rol != 'superadmin') AS num_usuarios,
            (SELECT COUNT(*) FROM infraestructuras i WHERE i.empresa_id = e.id) AS num_infras
     FROM empresas e
     WHERE e.id != 9999
     ORDER BY e.created_at DESC"
)->fetchAll();

// Si hay parámetro ?edit=ID, cargar para editar
$editEmpresa = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM empresas WHERE id = :id AND id != 9999");
    $stmt->execute([':id' => $editId]);
    $editEmpresa = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FotoGPS.app - Gestión de Empresas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/admin/css/admin.css" rel="stylesheet">
    <style>
        .sa-main { padding: 24px 32px; min-height: 100vh; max-width: 1400px; margin: 0 auto; }
        .sa-topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; flex-wrap: wrap; gap: 12px; }
        .sa-topbar h1 { font-size: 1.5rem; font-weight: 700; color: var(--text); margin: 0; }
        .badge-licencia { font-size: 0.7rem; padding: 4px 8px; border-radius: 6px; }
        @media (max-width: 768px) {
            .sa-main { padding: 14px; }
            .sa-topbar h1 { font-size: 1.2rem; }
            .sa-topbar { margin-bottom: 16px; }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../admin/includes/header.php'; ?>

    <div class="sa-main">
        <div class="sa-topbar">
            <h1><i class="bi bi-building me-2"></i>Gestión de Empresas</h1>
            <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#formEmpresa">
                <i class="bi bi-plus-lg"></i> Nueva Empresa
            </button>
        </div>

        <?php
        // Flash messages (e.g. from impersonation redirect)
        if (!empty($_SESSION['flash_msg'])) {
            $msg = $_SESSION['flash_msg'];
            $msgType = $_SESSION['flash_type'] ?? 'info';
            unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
        }
        ?>
        <?php if ($msg): ?>
            <div class="alert alert-<?= $msgType ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Formulario crear/editar -->
        <div class="collapse <?= $editEmpresa ? 'show' : '' ?>" id="formEmpresa">
            <div class="form-section">
                <h5 class="mb-3"><?= $editEmpresa ? 'Editar Empresa' : 'Nueva Empresa' ?></h5>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editEmpresa ? 'update' : 'create' ?>">
                    <?php if ($editEmpresa): ?>
                        <input type="hidden" name="id" value="<?= $editEmpresa['id'] ?>">
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Nombre de la empresa *</label>
                            <input type="text" name="nombre" class="form-control" required
                                   value="<?= htmlspecialchars($editEmpresa['nombre'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">NIF / CIF</label>
                            <input type="text" name="nif" class="form-control"
                                   value="<?= htmlspecialchars($editEmpresa['nif'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Plan</label>
                            <select name="plan_suscripcion" class="form-select">
                                <?php foreach (['free','basic','professional','enterprise'] as $plan): ?>
                                    <option value="<?= $plan ?>"
                                        <?= ($editEmpresa['plan_suscripcion'] ?? 'free') === $plan ? 'selected' : '' ?>>
                                        <?= strtoupper($plan) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Email de contacto</label>
                            <input type="email" name="email_contacto" class="form-control"
                                   value="<?= htmlspecialchars($editEmpresa['email_contacto'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Licencia desde</label>
                            <input type="date" name="licencia_inicio" class="form-control"
                                   value="<?= $editEmpresa['licencia_inicio'] ?? date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Licencia hasta</label>
                            <input type="date" name="licencia_fin" class="form-control"
                                   value="<?= $editEmpresa['licencia_fin'] ?? date('Y-m-d', strtotime('+1 year')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Max. Usuarios</label>
                            <input type="number" name="max_usuarios" class="form-control" min="1"
                                   value="<?= $editEmpresa['max_usuarios'] ?? 10 ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Max. Infraestructuras</label>
                            <input type="number" name="max_infraestructuras" class="form-control" min="1"
                                   value="<?= $editEmpresa['max_infraestructuras'] ?? 50 ?>">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" name="activa" class="form-check-input" id="chkActiva"
                                    <?= ($editEmpresa['activa'] ?? 1) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="chkActiva">Empresa activa</label>
                            </div>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-check-lg"></i>
                                <?= $editEmpresa ? 'Guardar cambios' : 'Crear empresa' ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tabla de empresas -->
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-responsive-cards">
                        <thead>
                            <tr class="text-muted small">
                                <th>Empresa</th>
                                <th>NIF</th>
                                <th>Plan</th>
                                <th>Licencia</th>
                                <th>Usuarios</th>
                                <th>Infras</th>
                                <th>Estado</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($empresas as $emp): ?>
                                <?php
                                $licExpirada = $emp['licencia_fin'] && $emp['licencia_fin'] < date('Y-m-d');
                                $planBadge = match($emp['plan_suscripcion']) {
                                    'free'         => 'bg-secondary',
                                    'basic'        => 'bg-info',
                                    'professional' => 'bg-primary',
                                    'enterprise'   => 'bg-warning text-dark',
                                    default        => 'bg-secondary',
                                };
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($emp['nombre']) ?></strong>
                                        <?php if ($emp['email_contacto']): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($emp['email_contacto']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?= htmlspecialchars($emp['nif'] ?? '-') ?></td>
                                    <td><span class="badge <?= $planBadge ?> badge-licencia"><?= strtoupper($emp['plan_suscripcion']) ?></span></td>
                                    <td>
                                        <?php if ($emp['licencia_fin']): ?>
                                            <span class="small <?= $licExpirada ? 'text-danger fw-bold' : '' ?>">
                                                <?= date('d/m/Y', strtotime($emp['licencia_inicio'] ?? '')) ?> -
                                                <?= date('d/m/Y', strtotime($emp['licencia_fin'])) ?>
                                                <?= $licExpirada ? '<br><span class="badge bg-danger badge-licencia">EXPIRADA</span>' : '' ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?= $emp['num_usuarios'] ?></span>
                                        <span class="text-muted small">/ <?= $emp['max_usuarios'] ?></span>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?= $emp['num_infras'] ?></span>
                                        <span class="text-muted small">/ <?= $emp['max_infraestructuras'] ?></span>
                                    </td>
                                    <td>
                                        <?php if ($emp['activa']): ?>
                                            <span class="badge bg-success badge-licencia">Activa</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger badge-licencia">Inactiva</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex gap-1 justify-content-end flex-wrap">
                                            <a href="?edit=<?= $emp['id'] ?>" class="btn btn-sm btn-outline-primary" title="Editar">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="usuarios.php?empresa_id=<?= $emp['id'] ?>" class="btn btn-sm btn-outline-dark" title="Gestionar usuarios">
                                                <i class="bi bi-people"></i>
                                            </a>
                                            <a href="/admin/infraestructuras.php?empresa_id=<?= $emp['id'] ?>" class="btn btn-sm btn-outline-success" title="Gestionar infraestructuras">
                                                <i class="bi bi-geo-alt"></i>
                                            </a>
                                            <a href="campos.php?empresa_id=<?= $emp['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Campos formulario">
                                                <i class="bi bi-ui-checks-grid"></i>
                                            </a>
                                            <!-- Acceder como Admin -->
                                            <form method="post" action="impersonate.php" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="empresa_id" value="<?= $emp['id'] ?>">
                                                <input type="hidden" name="target_role" value="admin">
                                                <button type="submit" class="btn btn-sm btn-outline-info" title="Acceder como Admin">
                                                    <i class="bi bi-box-arrow-in-right"></i> Admin
                                                </button>
                                            </form>
                                            <form method="post" class="d-inline" onsubmit="return confirm('¿Cambiar estado de esta empresa?')">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?= $emp['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-<?= $emp['activa'] ? 'warning' : 'success' ?>" title="<?= $emp['activa'] ? 'Desactivar' : 'Activar' ?>">
                                                    <i class="bi bi-<?= $emp['activa'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                                </button>
                                            </form>
                                            <form method="post" class="d-inline" onsubmit="return confirm('¿ELIMINAR esta empresa permanentemente? Se borrarán TODOS sus datos: usuarios (<?= $emp['num_usuarios'] ?>), infraestructuras (<?= $emp['num_infras'] ?>), registros, fotos, etc. Esta acción NO se puede deshacer.')">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $emp['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar empresa">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($empresas)): ?>
                                <tr><td colspan="8" class="text-center text-muted py-4">No hay empresas registradas. Crea la primera usando el botón "Nueva Empresa".</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
