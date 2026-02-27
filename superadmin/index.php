<?php
/**
 * INFOCAMPO SaaS - Dashboard Super Administrador
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = requireRole('superadmin');

$pdo = getDB();

// Métricas
$stats = [];

$stats['total_empresas'] = (int) $pdo->query(
    "SELECT COUNT(*) FROM empresas WHERE id != 9999"
)->fetchColumn();

$stats['empresas_activas'] = (int) $pdo->query(
    "SELECT COUNT(*) FROM empresas WHERE activa = 1 AND id != 9999"
)->fetchColumn();

$stats['licencias_expiradas'] = (int) $pdo->query(
    "SELECT COUNT(*) FROM empresas WHERE licencia_fin < CURDATE() AND id != 9999"
)->fetchColumn();

$stats['total_usuarios'] = (int) $pdo->query(
    "SELECT COUNT(*) FROM usuarios WHERE rol != 'superadmin'"
)->fetchColumn();

$stats['total_infraestructuras'] = (int) $pdo->query(
    "SELECT COUNT(*) FROM infraestructuras"
)->fetchColumn();

$stats['total_registros'] = (int) $pdo->query(
    "SELECT COUNT(*) FROM registros"
)->fetchColumn();

// Últimas empresas
$ultimasEmpresas = $pdo->query(
    "SELECT e.*,
            (SELECT COUNT(*) FROM usuarios u WHERE u.empresa_id = e.id AND u.rol != 'superadmin') AS num_usuarios,
            (SELECT COUNT(*) FROM infraestructuras i WHERE i.empresa_id = e.id) AS num_infras
     FROM empresas e
     WHERE e.id != 9999
     ORDER BY e.created_at DESC
     LIMIT 5"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INFOCAMPO - Super Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --sa-primary: #1e3a5f;
            --sa-secondary: #2d6a9f;
            --sa-bg: #f0f2f5;
        }
        body { background: var(--sa-bg); }
        .sa-sidebar {
            position: fixed;
            top: 0; left: 0; bottom: 0;
            width: 260px;
            background: linear-gradient(180deg, var(--sa-primary), #162d4a);
            color: #fff;
            padding-top: 0;
            z-index: 1000;
            overflow-y: auto;
        }
        .sa-sidebar .brand {
            padding: 24px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sa-sidebar .brand h2 {
            font-size: 1.2rem;
            font-weight: 800;
            margin: 0;
        }
        .sa-sidebar .brand small {
            opacity: 0.6;
            font-size: 0.75rem;
        }
        .sa-sidebar .nav-link {
            color: rgba(255,255,255,0.7);
            padding: 12px 20px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }
        .sa-sidebar .nav-link:hover,
        .sa-sidebar .nav-link.active {
            color: #fff;
            background: rgba(255,255,255,0.08);
            border-left-color: #4da6ff;
        }
        .sa-main {
            margin-left: 260px;
            padding: 24px 32px;
            min-height: 100vh;
        }
        .sa-topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
        }
        .sa-topbar h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1f2937;
            margin: 0;
        }
        .stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px 24px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            transition: transform 0.15s;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-card .stat-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
        }
        .stat-card .stat-value {
            font-size: 1.8rem;
            font-weight: 800;
            color: #1f2937;
        }
        .stat-card .stat-label {
            font-size: 0.8rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .card { border: none; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
        .badge-licencia {
            font-size: 0.7rem;
            padding: 4px 8px;
            border-radius: 6px;
        }
        @media (max-width: 991px) {
            .sa-sidebar { width: 220px; }
            .sa-main { margin-left: 220px; padding: 16px; }
        }
        @media (max-width: 767px) {
            .sa-sidebar {
                position: relative;
                width: 100%;
                padding-top: 0;
            }
            .sa-main { margin-left: 0; }
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
            <li><a href="index.php" class="nav-link active"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
            <li><a href="empresas.php" class="nav-link"><i class="bi bi-building"></i> Empresas</a></li>
            <li><a href="campos.php" class="nav-link"><i class="bi bi-ui-checks-grid"></i> Campos Formulario</a></li>
        </ul>
        <div class="mt-auto" style="position:absolute;bottom:0;width:100%;border-top:1px solid rgba(255,255,255,0.1);padding:16px 20px;">
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

    <!-- Main content -->
    <div class="sa-main">
        <div class="sa-topbar">
            <h1>Dashboard</h1>
            <span class="text-muted small"><?= date('d/m/Y H:i') ?></span>
        </div>

        <!-- Stats cards -->
        <div class="row g-3 mb-4">
            <div class="col-xl-2 col-md-4 col-6">
                <div class="stat-card">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="stat-icon" style="background:#dbeafe;color:#2563eb;"><i class="bi bi-building"></i></div>
                    </div>
                    <div class="stat-value"><?= $stats['total_empresas'] ?></div>
                    <div class="stat-label">Empresas</div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="stat-card">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="stat-icon" style="background:#d1fae5;color:#059669;"><i class="bi bi-check-circle"></i></div>
                    </div>
                    <div class="stat-value"><?= $stats['empresas_activas'] ?></div>
                    <div class="stat-label">Activas</div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="stat-card">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="stat-icon" style="background:#fee2e2;color:#dc2626;"><i class="bi bi-exclamation-triangle"></i></div>
                    </div>
                    <div class="stat-value"><?= $stats['licencias_expiradas'] ?></div>
                    <div class="stat-label">Lic. Expiradas</div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="stat-card">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="stat-icon" style="background:#e0e7ff;color:#4f46e5;"><i class="bi bi-people"></i></div>
                    </div>
                    <div class="stat-value"><?= $stats['total_usuarios'] ?></div>
                    <div class="stat-label">Usuarios</div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="stat-card">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="stat-icon" style="background:#fef3c7;color:#d97706;"><i class="bi bi-geo-alt"></i></div>
                    </div>
                    <div class="stat-value"><?= $stats['total_infraestructuras'] ?></div>
                    <div class="stat-label">Infraestructuras</div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="stat-card">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="stat-icon" style="background:#ede9fe;color:#7c3aed;"><i class="bi bi-camera"></i></div>
                    </div>
                    <div class="stat-value"><?= $stats['total_registros'] ?></div>
                    <div class="stat-label">Inspecciones</div>
                </div>
            </div>
        </div>

        <!-- Últimas empresas -->
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="card-title mb-0">Últimas empresas creadas</h5>
                    <a href="empresas.php" class="btn btn-sm btn-outline-primary">Ver todas</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr class="text-muted small">
                                <th>Empresa</th>
                                <th>Plan</th>
                                <th>Licencia</th>
                                <th>Usuarios</th>
                                <th>Infraestructuras</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ultimasEmpresas as $emp): ?>
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
                                    <td><span class="badge <?= $planBadge ?> badge-licencia"><?= strtoupper($emp['plan_suscripcion']) ?></span></td>
                                    <td>
                                        <?php if ($emp['licencia_fin']): ?>
                                            <span class="small <?= $licExpirada ? 'text-danger fw-bold' : 'text-muted' ?>">
                                                <?= date('d/m/Y', strtotime($emp['licencia_fin'])) ?>
                                                <?= $licExpirada ? ' (Expirada)' : '' ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">Sin definir</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?= $emp['num_usuarios'] ?></span>
                                        <span class="text-muted">/ <?= $emp['max_usuarios'] ?? '?' ?></span>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?= $emp['num_infras'] ?></span>
                                        <span class="text-muted">/ <?= $emp['max_infraestructuras'] ?? '?' ?></span>
                                    </td>
                                    <td>
                                        <?php if ($emp['activa']): ?>
                                            <span class="badge bg-success badge-licencia">Activa</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger badge-licencia">Inactiva</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($ultimasEmpresas)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No hay empresas registradas aún.</td></tr>
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
