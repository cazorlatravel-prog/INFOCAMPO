<?php
/**
 * INFOCAMPO - Dashboard de Administración
 *
 * Panel principal del administrador de empresa con:
 * - Estadísticas generales (infraestructuras, fotos, operadores, incidencias)
 * - Gráfico de actividad reciente
 * - Últimas inspecciones
 * - Registros en progreso (durante)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

// Manejar logout
if (isset($_GET['logout'])) {
    logout();
    header('Location: /login.php');
    exit;
}

// Exigir rol de panel
requireRole(['admin', 'supervisor', 'superadmin']);

$pdo = getDB();
$currentPage = 'dashboard';

// Obtener empresa_id de la sesión (single-tenant: siempre TRAGSA)
$empresaId = getEmpresaIdSeguro();

// ---------------------------------------------------------------
// Cargar estadísticas de la empresa
// ---------------------------------------------------------------
$stats = [
    'infraestructuras' => 0,
    'registros' => 0,
    'operadores' => 0,
    'durante_24h' => 0,
    'registros_7d' => 0,
    'registros_30d' => 0,
];
$ultimosRegistros = [];
$registrosDurante = [];
$actividadDiaria = [];
$empresaNombre = '';

if ($empresaId > 0) {
    $stmt = $pdo->prepare("SELECT nombre FROM empresas WHERE id = :id");
    $stmt->execute([':id' => $empresaId]);
    $row = $stmt->fetch();
    if ($row) $empresaNombre = $row['nombre'];

    // Fechas de corte calculadas en PHP (portable MySQL/PostgreSQL: evita
    // las diferencias de sintaxis de INTERVAL entre motores)
    $cut24 = (new DateTime('-24 hours'))->format('Y-m-d H:i:s');
    $cut7  = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
    $cut30 = (new DateTime('-30 days'))->format('Y-m-d H:i:s');
    $cut14 = (new DateTime('-14 days'))->format('Y-m-d H:i:s');

    // Estadísticas combinadas en una sola query con agregación condicional
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_registros,
            SUM(CASE WHEN r.estado_incidencia = 'durante' AND r.fecha >= :cut24 THEN 1 ELSE 0 END) AS durante_24h,
            SUM(CASE WHEN r.fecha >= :cut7 THEN 1 ELSE 0 END) AS registros_7d,
            SUM(CASE WHEN r.fecha >= :cut30 THEN 1 ELSE 0 END) AS registros_30d
         FROM registros r
         INNER JOIN infraestructuras i ON r.infra_id = i.id
         WHERE i.empresa_id = :id"
    );
    $stmt->execute([':id' => $empresaId, ':cut24' => $cut24, ':cut7' => $cut7, ':cut30' => $cut30]);
    $aggRow = $stmt->fetch();
    $stats['registros']    = (int) ($aggRow['total_registros'] ?? 0);
    $stats['durante_24h']  = (int) ($aggRow['durante_24h'] ?? 0);
    $stats['registros_7d'] = (int) ($aggRow['registros_7d'] ?? 0);
    $stats['registros_30d'] = (int) ($aggRow['registros_30d'] ?? 0);

    // Infraestructuras y operadores (queries ligeras, no se pueden combinar)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM infraestructuras WHERE empresa_id = :id AND activa = 1");
    $stmt->execute([':id' => $empresaId]);
    $stats['infraestructuras'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE empresa_id = :id AND rol = 'operador' AND activo = 1");
    $stmt->execute([':id' => $empresaId]);
    $stats['operadores'] = (int) $stmt->fetchColumn();

    // Actividad diaria últimos 14 días (desglosada por fase)
    $stmt = $pdo->prepare(
        "SELECT DATE(r.fecha) AS dia,
                SUM(CASE WHEN r.estado_incidencia = 'antes' THEN 1 ELSE 0 END) AS fot_antes,
                SUM(CASE WHEN r.estado_incidencia = 'durante' THEN 1 ELSE 0 END) AS fot_durante,
                SUM(CASE WHEN r.estado_incidencia = 'despues' THEN 1 ELSE 0 END) AS fot_despues
         FROM registros r
         INNER JOIN infraestructuras i ON r.infra_id = i.id
         WHERE i.empresa_id = :id AND r.fecha >= :cut14
         GROUP BY DATE(r.fecha)
         ORDER BY dia ASC"
    );
    $stmt->execute([':id' => $empresaId, ':cut14' => $cut14]);
    $actividadDiaria = $stmt->fetchAll();

    // Últimos 10 registros
    $stmt = $pdo->prepare(
        "SELECT r.*, i.nombre AS infra_nombre, i.codigo_unico, u.nombre AS usuario_nombre
         FROM registros r
         INNER JOIN infraestructuras i ON r.infra_id = i.id
         INNER JOIN usuarios u ON r.usuario_id = u.id
         WHERE i.empresa_id = :id
         ORDER BY r.fecha DESC
         LIMIT 10"
    );
    $stmt->execute([':id' => $empresaId]);
    $ultimosRegistros = $stmt->fetchAll();

    // Registros recientes "durante" (en progreso)
    $stmt = $pdo->prepare(
        "SELECT r.*, i.nombre AS infra_nombre, i.codigo_unico, u.nombre AS usuario_nombre
         FROM registros r
         INNER JOIN infraestructuras i ON r.infra_id = i.id
         INNER JOIN usuarios u ON r.usuario_id = u.id
         WHERE i.empresa_id = :id AND r.estado_incidencia = 'durante'
         ORDER BY r.fecha DESC
         LIMIT 5"
    );
    $stmt->execute([':id' => $empresaId]);
    $registrosDurante = $stmt->fetchAll();

    // Subidas fallidas pendientes
    $subidasFallidas = [];
    $stats['fallidas'] = 0;
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM subidas_fallidas WHERE empresa_id = :id AND resuelta = 0"
        );
        $stmt->execute([':id' => $empresaId]);
        $stats['fallidas'] = (int) $stmt->fetchColumn();

        if ($stats['fallidas'] > 0) {
            $stmt = $pdo->prepare(
                "SELECT sf.*, i.nombre AS infra_nombre, i.codigo_unico, u.nombre AS usuario_nombre
                 FROM subidas_fallidas sf
                 INNER JOIN infraestructuras i ON sf.infra_id = i.id
                 INNER JOIN usuarios u ON sf.usuario_id = u.id
                 WHERE sf.empresa_id = :id AND sf.resuelta = 0
                 ORDER BY sf.fecha_fallo DESC
                 LIMIT 10"
            );
            $stmt->execute([':id' => $empresaId]);
            $subidasFallidas = $stmt->fetchAll();
        }
    } catch (\Exception $e) {}
}

// Preparar datos para gráfico (3 series: Antes, Durante, Después)
$chartLabels = [];
$chartAntes = [];
$chartDurante = [];
$chartDespues = [];
for ($i = 13; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $chartLabels[] = date('d/m', strtotime($date));
    $found = false;
    foreach ($actividadDiaria as $act) {
        if ($act['dia'] === $date) {
            $chartAntes[] = (int) $act['fot_antes'];
            $chartDurante[] = (int) $act['fot_durante'];
            $chartDespues[] = (int) $act['fot_despues'];
            $found = true;
            break;
        }
    }
    if (!$found) {
        $chartAntes[] = 0;
        $chartDurante[] = 0;
        $chartDespues[] = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FotoGPS.app - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/admin/css/admin.css" rel="stylesheet">
    <style>
        .durante-card {
            background: linear-gradient(135deg, #eff6ff, #f0f7ff);
            border-left: 4px solid #3b82f6;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="container-fluid py-4">
        <?php if ($empresaId <= 0): ?>
            <div class="text-center py-5">
                <i class="bi bi-speedometer2" style="font-size:3rem;color:#adb5bd;"></i>
                <h5 class="mt-3 text-muted">No tienes empresa asignada</h5>
            </div>
        <?php else: ?>

        <!-- Stats cards -->
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="stat-icon" style="background:#dbeafe;color:#2563eb;">
                            <i class="bi bi-geo-alt-fill"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $stats['infraestructuras'] ?></div>
                            <div class="stat-label">Infraestructuras</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="stat-icon" style="background:#ede9fe;color:#7c3aed;">
                            <i class="bi bi-camera-fill"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $stats['registros'] ?></div>
                            <div class="stat-label">Inspecciones</div>
                            <div class="stat-delta text-success"><i class="bi bi-arrow-up"></i> <?= $stats['registros_7d'] ?> esta semana</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="stat-icon" style="background:#d1fae5;color:#059669;">
                            <i class="bi bi-people-fill"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $stats['operadores'] ?></div>
                            <div class="stat-label">Operadores activos</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card" <?= $stats['durante_24h'] > 0 ? 'style="border:2px solid #93c5fd;"' : '' ?>>
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="stat-icon" style="background:#dbeafe;color:#2563eb;">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $stats['durante_24h'] ?></div>
                            <div class="stat-label">Durante (24h)</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($subidasFallidas)): ?>
        <!-- Alerta de subidas fallidas -->
        <div class="alert alert-warning alert-dismissible fade show d-flex align-items-start gap-3 mb-4" role="alert"
             style="border-left:4px solid #f59e0b;border-radius:12px;">
            <div style="font-size:1.6rem;color:#f59e0b;"><i class="bi bi-cloud-slash-fill"></i></div>
            <div class="flex-grow-1">
                <strong><?= $stats['fallidas'] ?> foto<?= $stats['fallidas'] > 1 ? 's' : '' ?> pendiente<?= $stats['fallidas'] > 1 ? 's' : '' ?> de subir</strong>
                <p class="mb-2 small">
                    Las siguientes fotos no se pudieron subir a la nube. Los operadores deben <strong>no eliminarlas</strong> de sus dispositivos
                    hasta que se resuelva el problema.
                </p>
                <div class="table-responsive" style="max-height:200px;overflow-y:auto;">
                    <table class="table table-sm table-striped small mb-0">
                        <thead><tr><th>Operador</th><th>Infraestructura</th><th>Archivo</th><th>Error</th><th>Fecha</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($subidasFallidas as $sf): ?>
                            <tr>
                                <td><?= htmlspecialchars($sf['usuario_nombre']) ?></td>
                                <td>
                                    <code style="font-size:0.7rem;"><?= htmlspecialchars($sf['codigo_unico']) ?></code>
                                    <?= htmlspecialchars($sf['infra_nombre']) ?>
                                </td>
                                <td><code style="font-size:0.7rem;"><?= htmlspecialchars($sf['nombre_archivo'] ?? '—') ?></code></td>
                                <td><span class="text-danger" style="font-size:0.7rem;"><?= htmlspecialchars(mb_substr($sf['motivo_error'], 0, 60)) ?></span></td>
                                <td style="white-space:nowrap;"><?= date('d/m H:i', strtotime($sf['fecha_fallo'])) ?></td>
                                <td>
                                    <form method="post" action="api/subidas_fallidas.php" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="resolver">
                                        <input type="hidden" name="id" value="<?= $sf['id'] ?>">
                                        <input type="hidden" name="empresa_id" value="<?= $empresaId ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success py-0 px-1" title="Marcar como resuelta"
                                                onclick="return confirm('¿Marcar esta subida como resuelta?')">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Columna izquierda: Gráfico + Actividad -->
            <div class="col-lg-8">
                <!-- Gráfico de actividad -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="card-title mb-0"><i class="bi bi-graph-up me-2"></i>Actividad - Últimos 14 días</h6>
                            <span class="text-muted small"><?= $stats['registros_30d'] ?> inspecciones en 30 días</span>
                        </div>
                        <div class="chart-container">
                            <canvas id="activityChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Últimas inspecciones -->
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>Últimas inspecciones</h6>
                            <a href="index.php?empresa_id=<?= $empresaId ?>" class="btn btn-sm btn-outline-primary">Ver todas</a>
                        </div>
                        <?php if (empty($ultimosRegistros)): ?>
                            <p class="text-muted text-center py-3">No hay inspecciones registradas.</p>
                        <?php else: ?>
                            <?php foreach ($ultimosRegistros as $reg): ?>
                                <div class="activity-item">
                                    <div class="activity-dot <?= $reg['estado_incidencia'] ?>"></div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <strong class="small"><?= htmlspecialchars($reg['infra_nombre']) ?></strong>
                                                <span class="text-muted small ms-1"><?= htmlspecialchars($reg['codigo_unico']) ?></span>
                                            </div>
                                            <?php
                                            $badgeClass = match($reg['estado_incidencia']) {
                                                'antes' => 'bg-primary', 'durante' => 'bg-warning text-dark', 'despues' => 'bg-success', default => 'bg-secondary',
                                            };
                                            ?>
                                            <span class="badge <?= $badgeClass ?> badge-inc"><?= strtoupper($reg['estado_incidencia']) ?></span>
                                        </div>
                                        <div class="small text-muted mt-1">
                                            <i class="bi bi-person"></i> <?= htmlspecialchars($reg['usuario_nombre']) ?>
                                            &mdash; <?= date('d/m/Y H:i', strtotime($reg['fecha'])) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Columna derecha: Registros en progreso + Accesos rápidos -->
            <div class="col-lg-4">
                <!-- Registros en fase "Durante" -->
                <div class="card mb-4">
                    <div class="card-body">
                        <h6 class="card-title mb-3">
                            <i class="bi bi-clock-history text-primary me-2"></i>Registros Durante (recientes)
                        </h6>
                        <?php if (empty($registrosDurante)): ?>
                            <div class="text-center py-3">
                                <i class="bi bi-check-circle text-success" style="font-size:2rem;"></i>
                                <p class="text-muted small mt-2 mb-0">Sin registros en fase "durante" recientes</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($registrosDurante as $crit): ?>
                                <div class="durante-card">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <strong class="small"><?= htmlspecialchars($crit['infra_nombre']) ?></strong>
                                        <span class="small text-muted"><?= date('d/m H:i', strtotime($crit['fecha'])) ?></span>
                                    </div>
                                    <div class="small text-muted mt-1">
                                        <i class="bi bi-person"></i> <?= htmlspecialchars($crit['usuario_nombre']) ?>
                                    </div>
                                    <?php if ($crit['observaciones']): ?>
                                        <p class="small mb-0 mt-1" style="color:#991b1b;"><?= htmlspecialchars(mb_substr($crit['observaciones'], 0, 100)) ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Accesos rápidos -->
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title mb-3"><i class="bi bi-lightning me-2"></i>Accesos rápidos</h6>
                        <div class="d-grid gap-2">
                            <a href="index.php?empresa_id=<?= $empresaId ?>" class="btn btn-outline-primary btn-sm text-start">
                                <i class="bi bi-geo-alt me-2"></i>Ver Infraestructuras
                            </a>
                            <a href="usuarios.php?empresa_id=<?= $empresaId ?>" class="btn btn-outline-primary btn-sm text-start">
                                <i class="bi bi-people me-2"></i>Gestionar Usuarios
                            </a>
                            <a href="mapa.php?empresa_id=<?= $empresaId ?>" class="btn btn-outline-primary btn-sm text-start">
                                <i class="bi bi-map me-2"></i>Ver en Mapa
                            </a>
                            <a href="infraestructuras.php?empresa_id=<?= $empresaId ?>" class="btn btn-outline-primary btn-sm text-start">
                                <i class="bi bi-plus-circle me-2"></i>Gestionar Infraestructuras
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
    <?php if ($empresaId > 0): ?>
    const ctx = document.getElementById('activityChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [{
                    label: 'Fot. Antes',
                    data: <?= json_encode($chartAntes) ?>,
                    backgroundColor: 'rgba(59,130,246,0.7)',
                    borderRadius: 6,
                    borderSkipped: false,
                }, {
                    label: 'Fot. Durante',
                    data: <?= json_encode($chartDurante) ?>,
                    backgroundColor: 'rgba(245,158,11,0.7)',
                    borderRadius: 6,
                    borderSkipped: false,
                }, {
                    label: 'Fot. Después',
                    data: <?= json_encode($chartDespues) ?>,
                    backgroundColor: 'rgba(34,197,94,0.7)',
                    borderRadius: 6,
                    borderSkipped: false,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 11 } }, grid: { color: '#f3f4f6' } },
                    x: { ticks: { font: { size: 10 } }, grid: { display: false } }
                }
            }
        });
    }
    <?php endif; ?>
    </script>
</body>
</html>
