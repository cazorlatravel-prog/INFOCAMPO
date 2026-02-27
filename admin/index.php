<?php
/**
 * INFOCAMPO SaaS - Timeline de Inspecciones (Admin)
 *
 * Lista infraestructuras por empresa. Al seleccionar una,
 * muestra la línea de tiempo con fotos.
 * Incluye filtros por fecha, operador y nivel de incidencia.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$pdo = getDB();
$currentPage = 'infraestructuras';

// ---------------------------------------------------------------
// Filtros
// ---------------------------------------------------------------
$empresaId = isset($_GET['empresa_id']) ? (int) $_GET['empresa_id'] : ($_SESSION['empresa_id'] ?? 0);
$infraId   = isset($_GET['infra_id'])   ? (int) $_GET['infra_id']   : 0;
$filtroEstado   = $_GET['estado'] ?? '';
$filtroUsuario  = isset($_GET['usuario_id']) ? (int) $_GET['usuario_id'] : 0;
$filtroFechaDesde = $_GET['fecha_desde'] ?? '';
$filtroFechaHasta = $_GET['fecha_hasta'] ?? '';

$impersonating = isImpersonating();

if ($impersonating && $empresaId === 0 && isset($_SESSION['empresa_id'])) {
    $empresaId = (int) $_SESSION['empresa_id'];
}

// ---------------------------------------------------------------
// Cargar empresas para filtro
// ---------------------------------------------------------------
$empresas = $pdo->query(
    "SELECT id, nombre FROM empresas WHERE activa = 1 ORDER BY nombre"
)->fetchAll();

// ---------------------------------------------------------------
// Cargar infraestructuras + contadores de incidencias
// ---------------------------------------------------------------
$infraestructuras = [];
if ($empresaId > 0) {
    $stmt = $pdo->prepare(
        "SELECT i.id, i.nombre, i.codigo_unico, i.lat_teorica, i.lon_teorica, i.tipo,
                (SELECT COUNT(*) FROM registros r WHERE r.infra_id = i.id) AS total_registros,
                (SELECT COUNT(*) FROM registros r WHERE r.infra_id = i.id AND r.estado_incidencia = 'critico') AS criticas,
                (SELECT r2.estado_incidencia FROM registros r2 WHERE r2.infra_id = i.id ORDER BY r2.fecha DESC LIMIT 1) AS ultimo_estado
         FROM infraestructuras i
         WHERE i.empresa_id = :empresa_id AND i.activa = 1
         ORDER BY i.nombre"
    );
    $stmt->execute([':empresa_id' => $empresaId]);
    $infraestructuras = $stmt->fetchAll();
}

// ---------------------------------------------------------------
// Cargar operadores para filtro
// ---------------------------------------------------------------
$operadores = [];
if ($empresaId > 0) {
    $stmt = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE empresa_id = :emp_id AND activo = 1 ORDER BY nombre");
    $stmt->execute([':emp_id' => $empresaId]);
    $operadores = $stmt->fetchAll();
}

// ---------------------------------------------------------------
// Cargar registros (timeline) con filtros
// ---------------------------------------------------------------
$registros = [];
$infraSeleccionada = null;
if ($infraId > 0) {
    $sql = "SELECT r.*, u.nombre AS usuario_nombre
            FROM registros r
            INNER JOIN usuarios u ON r.usuario_id = u.id
            WHERE r.infra_id = :infra_id";
    $params = [':infra_id' => $infraId];

    if ($filtroEstado !== '' && in_array($filtroEstado, ['bajo', 'medio', 'critico'], true)) {
        $sql .= " AND r.estado_incidencia = :estado";
        $params[':estado'] = $filtroEstado;
    }
    if ($filtroUsuario > 0) {
        $sql .= " AND r.usuario_id = :usuario_id";
        $params[':usuario_id'] = $filtroUsuario;
    }
    if ($filtroFechaDesde !== '') {
        $sql .= " AND r.fecha >= :fecha_desde";
        $params[':fecha_desde'] = $filtroFechaDesde . ' 00:00:00';
    }
    if ($filtroFechaHasta !== '') {
        $sql .= " AND r.fecha <= :fecha_hasta";
        $params[':fecha_hasta'] = $filtroFechaHasta . ' 23:59:59';
    }

    $sql .= " ORDER BY r.fecha DESC LIMIT 100";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $registros = $stmt->fetchAll();

    // Info de la infra
    $stmt2 = $pdo->prepare(
        "SELECT i.*, e.nombre AS empresa_nombre
         FROM infraestructuras i
         INNER JOIN empresas e ON i.empresa_id = e.id
         WHERE i.id = :id"
    );
    $stmt2->execute([':id' => $infraId]);
    $infraSeleccionada = $stmt2->fetch();
}

// Construir query base para links
$baseQuery = 'empresa_id=' . $empresaId . '&infra_id=' . $infraId;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INFOCAMPO - Inspecciones</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
        .brand-bar { background: linear-gradient(135deg, #1e3a5f, #2d6a9f); color: #fff; padding: 14px 24px; }
        .card { border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border-radius: 12px; }
        .nav-admin { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 0 24px; }
        .nav-admin .nav-link { color: #6b7280; padding: 12px 16px; font-size: 0.9rem; border-bottom: 2px solid transparent; }
        .nav-admin .nav-link:hover, .nav-admin .nav-link.active { color: #1e3a5f; border-bottom-color: #1e3a5f; }
        .timeline { position: relative; padding-left: 40px; }
        .timeline::before {
            content: ''; position: absolute; left: 16px; top: 0; bottom: 0;
            width: 3px; background: #dee2e6; border-radius: 2px;
        }
        .timeline-item { position: relative; margin-bottom: 24px; }
        .timeline-dot {
            position: absolute; left: -32px; top: 6px;
            width: 14px; height: 14px; border-radius: 50%;
            border: 3px solid #fff;
        }
        .timeline-dot.bajo    { background: #22c55e; box-shadow: 0 0 0 2px #22c55e; }
        .timeline-dot.medio   { background: #eab308; box-shadow: 0 0 0 2px #eab308; }
        .timeline-dot.critico { background: #ef4444; box-shadow: 0 0 0 2px #ef4444; }
        .timeline-photo {
            max-width: 100%; border-radius: 8px; margin-top: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .badge-inc { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .infra-list .list-group-item.active { background: #1e3a5f; border-color: #1e3a5f; }
        .infra-list .list-group-item { transition: all 0.15s; border-radius: 8px !important; margin-bottom: 4px; }
        .infra-list .list-group-item:hover { background: #f0f4ff; }
        .infra-status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
        .filter-section {
            background: #fff; border-radius: 10px; padding: 14px 16px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06); margin-bottom: 12px;
        }
        .filter-label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; font-weight: 700; margin-bottom: 4px; }
        .result-count { font-size: 0.8rem; color: #6b7280; padding: 8px 0; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="container-fluid py-4">
        <div class="row g-4">

            <!-- COLUMNA IZQUIERDA: Filtros -->
            <div class="col-lg-3">
                <!-- Selector de empresa -->
                <div class="card mb-3">
                    <div class="card-body">
                        <h6 class="card-title text-muted mb-3"><i class="bi bi-building me-1"></i>Empresa</h6>
                        <form method="get">
                            <select name="empresa_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">-- Seleccionar empresa --</option>
                                <?php foreach ($empresas as $emp): ?>
                                    <option value="<?= $emp['id'] ?>" <?= $empresaId === (int)$emp['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($emp['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                </div>

                <!-- Lista de infraestructuras -->
                <?php if ($infraestructuras): ?>
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title text-muted mb-3"><i class="bi bi-geo-alt me-1"></i>Infraestructuras</h6>
                        <div class="list-group list-group-flush infra-list">
                            <?php foreach ($infraestructuras as $inf): ?>
                                <?php
                                $statusColor = match($inf['ultimo_estado']) {
                                    'critico' => '#ef4444', 'medio' => '#eab308', 'bajo' => '#22c55e', default => '#d1d5db',
                                };
                                ?>
                                <a href="?empresa_id=<?= $empresaId ?>&infra_id=<?= $inf['id'] ?>"
                                   class="list-group-item list-group-item-action <?= $infraId === (int)$inf['id'] ? 'active' : '' ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong class="small"><?= htmlspecialchars($inf['codigo_unico']) ?></strong>
                                            <br><small><?= htmlspecialchars($inf['nombre']) ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span class="infra-status-dot" style="background:<?= $statusColor ?>;"></span>
                                            <br><small class="<?= $infraId === (int)$inf['id'] ? '' : 'text-muted' ?>"><?= $inf['total_registros'] ?></small>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- COLUMNA DERECHA: Timeline -->
            <div class="col-lg-9">
                <?php if ($infraSeleccionada): ?>
                    <!-- Cabecera de la infra -->
                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div>
                                    <h5 class="mb-1"><?= htmlspecialchars($infraSeleccionada['nombre']) ?></h5>
                                    <div class="text-muted small">
                                        <code style="color:#2d6a9f;"><?= htmlspecialchars($infraSeleccionada['codigo_unico']) ?></code>
                                        &nbsp;|&nbsp; GPS: <?= $infraSeleccionada['lat_teorica'] ?>, <?= $infraSeleccionada['lon_teorica'] ?>
                                        <?php if ($infraSeleccionada['tipo']): ?>
                                            &nbsp;|&nbsp; <?= htmlspecialchars($infraSeleccionada['tipo']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <a href="descargar_fotos.php?infra_id=<?= $infraId ?>" class="btn btn-outline-success btn-sm">
                                        <i class="bi bi-file-earmark-zip"></i> ZIP
                                    </a>
                                    <a href="generar_pdf.php?infra_id=<?= $infraId ?>" class="btn btn-outline-primary btn-sm" target="_blank">
                                        <i class="bi bi-file-pdf"></i> PDF
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtros de Timeline -->
                    <div class="filter-section">
                        <form method="get" class="row g-2 align-items-end">
                            <input type="hidden" name="empresa_id" value="<?= $empresaId ?>">
                            <input type="hidden" name="infra_id" value="<?= $infraId ?>">
                            <div class="col-md-2">
                                <div class="filter-label">Desde</div>
                                <input type="date" name="fecha_desde" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($filtroFechaDesde) ?>">
                            </div>
                            <div class="col-md-2">
                                <div class="filter-label">Hasta</div>
                                <input type="date" name="fecha_hasta" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($filtroFechaHasta) ?>">
                            </div>
                            <div class="col-md-2">
                                <div class="filter-label">Estado</div>
                                <select name="estado" class="form-select form-select-sm">
                                    <option value="">Todos</option>
                                    <option value="bajo" <?= $filtroEstado === 'bajo' ? 'selected' : '' ?>>Bajo</option>
                                    <option value="medio" <?= $filtroEstado === 'medio' ? 'selected' : '' ?>>Medio</option>
                                    <option value="critico" <?= $filtroEstado === 'critico' ? 'selected' : '' ?>>Critico</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <div class="filter-label">Operador</div>
                                <select name="usuario_id" class="form-select form-select-sm">
                                    <option value="">Todos</option>
                                    <?php foreach ($operadores as $op): ?>
                                        <option value="<?= $op['id'] ?>" <?= $filtroUsuario === (int)$op['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($op['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex gap-1">
                                <button type="submit" class="btn btn-sm btn-primary flex-grow-1">
                                    <i class="bi bi-funnel"></i> Filtrar
                                </button>
                                <a href="?empresa_id=<?= $empresaId ?>&infra_id=<?= $infraId ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-x-lg"></i>
                                </a>
                            </div>
                        </form>
                    </div>

                    <div class="result-count">
                        <?= count($registros) ?> inspeccione<?= count($registros) !== 1 ? 's' : '' ?> encontrada<?= count($registros) !== 1 ? 's' : '' ?>
                    </div>

                    <!-- Timeline de registros -->
                    <?php if ($registros): ?>
                        <div class="timeline">
                            <?php foreach ($registros as $reg): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot <?= $reg['estado_incidencia'] ?>"></div>
                                    <div class="card">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <span class="fw-bold">
                                                    <?= date('d/m/Y H:i', strtotime($reg['fecha'])) ?>
                                                </span>
                                                <div class="d-flex align-items-center gap-2">
                                                    <small class="text-muted"><i class="bi bi-person"></i> <?= htmlspecialchars($reg['usuario_nombre']) ?></small>
                                                    <?php
                                                    $badgeClass = match($reg['estado_incidencia']) {
                                                        'bajo' => 'bg-success', 'medio' => 'bg-warning text-dark',
                                                        'critico' => 'bg-danger', default => 'bg-secondary',
                                                    };
                                                    ?>
                                                    <span class="badge <?= $badgeClass ?> badge-inc"><?= strtoupper($reg['estado_incidencia']) ?></span>
                                                </div>
                                            </div>

                                            <a href="<?= htmlspecialchars($reg['url_cloudinary']) ?>" target="_blank" class="d-block">
                                                <img src="<?= htmlspecialchars($reg['url_cloudinary']) ?>"
                                                     alt="Foto inspección" class="timeline-photo" loading="lazy">
                                            </a>

                                            <div class="mt-2 d-flex justify-content-between align-items-center">
                                                <div class="small text-muted">
                                                    <i class="bi bi-geo-alt"></i> <?= $reg['lat_real'] ?>, <?= $reg['lon_real'] ?>
                                                    <?php if (isset($reg['tipo_foto']) && $reg['tipo_foto']): ?>
                                                        &nbsp;|&nbsp;
                                                        <span class="badge <?= $reg['tipo_foto'] === 'comparativo' ? 'bg-purple' : 'bg-info' ?>" style="<?= $reg['tipo_foto'] === 'comparativo' ? 'background:#a855f7;' : '' ?>font-size:0.6rem;">
                                                            <?= strtoupper($reg['tipo_foto']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php
                                                $downloadUrl = preg_replace('#/upload/#', '/upload/fl_attachment/', $reg['url_cloudinary'], 1);
                                                ?>
                                                <a href="<?= htmlspecialchars($downloadUrl) ?>" class="btn btn-sm btn-outline-secondary" title="Descargar">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                            </div>

                                            <?php if ($reg['observaciones']): ?>
                                                <p class="mt-2 mb-0 small fst-italic text-muted">
                                                    <?= nl2br(htmlspecialchars($reg['observaciones'])) ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="bi bi-camera" style="font-size:2.5rem;color:#adb5bd;"></i>
                            <h6 class="mt-3 text-muted">Sin inspecciones</h6>
                            <p class="text-muted small">
                                No se encontraron inspecciones con los filtros seleccionados.
                            </p>
                        </div>
                    <?php endif; ?>

                <?php elseif ($empresaId > 0): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-hand-index" style="font-size:2.5rem;color:#adb5bd;"></i>
                        <h6 class="mt-3 text-muted">Selecciona una infraestructura</h6>
                        <p class="text-muted small">Elige del panel izquierdo para ver su timeline.</p>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="bi bi-building" style="font-size:2.5rem;color:#adb5bd;"></i>
                        <h6 class="mt-3 text-muted">Selecciona una empresa</h6>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
