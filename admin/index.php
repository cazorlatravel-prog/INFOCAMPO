<?php
/**
 * INFOCAMPO SaaS - Panel de Administración
 *
 * Lista de infraestructuras filtrables por empresa.
 * Al seleccionar una, muestra la línea de tiempo con fotos.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$pdo = getDB();

// Detectar si estamos en modo suplantación
$impersonating = isImpersonating();

// ---------------------------------------------------------------
// Cargar empresas para el selector de filtro
// ---------------------------------------------------------------
$empresas = $pdo->query(
    "SELECT id, nombre FROM empresas WHERE activa = 1 ORDER BY nombre"
)->fetchAll();

// ---------------------------------------------------------------
// Filtro de empresa (por query string)
// ---------------------------------------------------------------
$empresaId = isset($_GET['empresa_id']) ? (int) $_GET['empresa_id'] : 0;
$infraId   = isset($_GET['infra_id'])   ? (int) $_GET['infra_id']   : 0;

// Si estamos suplantando y no se ha seleccionado empresa, usar la del usuario suplantado
if ($impersonating && $empresaId === 0 && isset($_SESSION['empresa_id'])) {
    $empresaId = (int) $_SESSION['empresa_id'];
}

// ---------------------------------------------------------------
// Cargar infraestructuras de la empresa seleccionada
// ---------------------------------------------------------------
$infraestructuras = [];
if ($empresaId > 0) {
    $stmt = $pdo->prepare(
        "SELECT id, nombre, codigo_unico, lat_teorica, lon_teorica, tipo
         FROM infraestructuras
         WHERE empresa_id = :empresa_id AND activa = 1
         ORDER BY nombre"
    );
    $stmt->execute([':empresa_id' => $empresaId]);
    $infraestructuras = $stmt->fetchAll();
}

// ---------------------------------------------------------------
// Cargar registros (timeline) de la infraestructura seleccionada
// ---------------------------------------------------------------
$registros = [];
$infraSeleccionada = null;
if ($infraId > 0) {
    $stmt = $pdo->prepare(
        "SELECT r.*, u.nombre AS usuario_nombre
         FROM registros r
         INNER JOIN usuarios u ON r.usuario_id = u.id
         WHERE r.infra_id = :infra_id
         ORDER BY r.fecha DESC"
    );
    $stmt->execute([':infra_id' => $infraId]);
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INFOCAMPO - Panel de Administración</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
        .brand-bar {
            background: linear-gradient(135deg, #1e3a5f, #2d6a9f);
            color: #fff;
            padding: 18px 24px;
        }
        .brand-bar h1 { font-size: 1.3rem; margin: 0; font-weight: 700; }
        .card { border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .timeline { position: relative; padding-left: 40px; }
        .timeline::before {
            content: '';
            position: absolute;
            left: 16px; top: 0; bottom: 0;
            width: 3px;
            background: #dee2e6;
            border-radius: 2px;
        }
        .timeline-item {
            position: relative;
            margin-bottom: 24px;
        }
        .timeline-dot {
            position: absolute;
            left: -32px; top: 6px;
            width: 14px; height: 14px;
            border-radius: 50%;
            border: 3px solid #fff;
            box-shadow: 0 0 0 2px #adb5bd;
        }
        .timeline-dot.bajo    { background: #22c55e; box-shadow: 0 0 0 2px #22c55e; }
        .timeline-dot.medio   { background: #eab308; box-shadow: 0 0 0 2px #eab308; }
        .timeline-dot.critico { background: #ef4444; box-shadow: 0 0 0 2px #ef4444; }

        .timeline-photo {
            max-width: 100%;
            border-radius: 8px;
            margin-top: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .badge-inc {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .infra-list .list-group-item.active {
            background: #1e3a5f;
            border-color: #1e3a5f;
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
    <!-- Barra de suplantación -->
    <div style="background:linear-gradient(90deg,#f59e0b,#d97706);color:#fff;padding:10px 24px;display:flex;align-items:center;justify-content:space-between;font-size:0.9rem;position:sticky;top:0;z-index:9999;">
        <div>
            <i class="bi bi-eye" style="margin-right:6px;"></i>
            Estás viendo como: <strong><?= htmlspecialchars($_SESSION['user_name']) ?></strong>
            (<?= htmlspecialchars($_SESSION['user_rol']) ?> - <?= htmlspecialchars($_SESSION['empresa_nombre']) ?>)
        </div>
        <a href="/superadmin/impersonate.php?stop=1" class="btn btn-sm btn-light fw-semibold"
           style="color:#92400e;">
            <i class="bi bi-box-arrow-left"></i> Volver a Super Admin
        </a>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="brand-bar d-flex align-items-center justify-content-between">
        <h1>INFOCAMPO &mdash; Panel de Administración</h1>
        <span class="small opacity-75"><?= date('d/m/Y H:i') ?></span>
    </div>

    <!-- Navigation -->
    <nav class="nav-admin">
        <ul class="nav">
            <li><a href="index.php<?= $empresaId ? '?empresa_id=' . $empresaId : '' ?>" class="nav-link active">
                <i class="bi bi-speedometer2"></i> Infraestructuras
            </a></li>
            <li><a href="unidades_obra.php<?= $empresaId ? '?empresa_id=' . $empresaId : '' ?>" class="nav-link">
                <i class="bi bi-tools"></i> Unidades de Obra
            </a></li>
        </ul>
    </nav>

    <div class="container-fluid py-4">
        <div class="row g-4">

            <!-- ==========================================
                 COLUMNA IZQUIERDA: Filtros
                 ========================================== -->
            <div class="col-lg-3">
                <!-- Selector de empresa -->
                <div class="card mb-3">
                    <div class="card-body">
                        <h6 class="card-title text-muted mb-3">Filtrar por empresa</h6>
                        <form method="get" action="">
                            <select name="empresa_id" class="form-select mb-2"
                                    onchange="this.form.submit()">
                                <option value="">-- Seleccionar empresa --</option>
                                <?php foreach ($empresas as $emp): ?>
                                    <option value="<?= $emp['id'] ?>"
                                        <?= $empresaId === (int)$emp['id'] ? 'selected' : '' ?>>
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
                        <h6 class="card-title text-muted mb-3">Infraestructuras</h6>
                        <div class="list-group list-group-flush infra-list">
                            <?php foreach ($infraestructuras as $inf): ?>
                                <a href="?empresa_id=<?= $empresaId ?>&infra_id=<?= $inf['id'] ?>"
                                   class="list-group-item list-group-item-action <?= $infraId === (int)$inf['id'] ? 'active' : '' ?>">
                                    <strong><?= htmlspecialchars($inf['codigo_unico']) ?></strong>
                                    <br>
                                    <small><?= htmlspecialchars($inf['nombre']) ?></small>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ==========================================
                 COLUMNA DERECHA: Timeline
                 ========================================== -->
            <div class="col-lg-9">
                <?php if ($infraSeleccionada): ?>
                    <!-- Cabecera de la infra -->
                    <div class="card mb-4">
                        <div class="card-body d-flex justify-content-between align-items-start">
                            <div>
                                <h4 class="mb-1">
                                    <?= htmlspecialchars($infraSeleccionada['nombre']) ?>
                                </h4>
                                <p class="text-muted mb-1">
                                    <strong>Código:</strong>
                                    <?= htmlspecialchars($infraSeleccionada['codigo_unico']) ?>
                                    &nbsp;|&nbsp;
                                    <strong>Empresa:</strong>
                                    <?= htmlspecialchars($infraSeleccionada['empresa_nombre']) ?>
                                </p>
                                <p class="text-muted mb-0 small">
                                    GPS teórico: <?= $infraSeleccionada['lat_teorica'] ?>,
                                    <?= $infraSeleccionada['lon_teorica'] ?>
                                    <?php if ($infraSeleccionada['tipo']): ?>
                                        &nbsp;|&nbsp;
                                        Tipo: <?= htmlspecialchars($infraSeleccionada['tipo']) ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="descargar_fotos.php?infra_id=<?= $infraId ?>"
                                   class="btn btn-outline-success btn-sm">
                                    Descargar Fotos (ZIP)
                                </a>
                                <a href="generar_pdf.php?infra_id=<?= $infraId ?>"
                                   class="btn btn-outline-primary btn-sm"
                                   target="_blank">
                                    Generar Informe PDF
                                </a>
                            </div>
                        </div>
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
                                                <span>
                                                    <?php
                                                    $badgeClass = match($reg['estado_incidencia']) {
                                                        'bajo'    => 'bg-success',
                                                        'medio'   => 'bg-warning text-dark',
                                                        'critico' => 'bg-danger',
                                                        default   => 'bg-secondary',
                                                    };
                                                    ?>
                                                    <span class="badge <?= $badgeClass ?> badge-inc">
                                                        <?= strtoupper($reg['estado_incidencia']) ?>
                                                    </span>
                                                </span>
                                            </div>

                                            <a href="<?= htmlspecialchars($reg['url_cloudinary']) ?>"
                                               target="_blank" class="d-block position-relative">
                                                <img src="<?= htmlspecialchars($reg['url_cloudinary']) ?>"
                                                     alt="Foto inspección"
                                                     class="timeline-photo"
                                                     loading="lazy">
                                            </a>

                                            <div class="mt-2 d-flex justify-content-between align-items-center">
                                                <div class="small text-muted">
                                                    <strong>Operador:</strong>
                                                    <?= htmlspecialchars($reg['usuario_nombre']) ?>
                                                    &nbsp;|&nbsp;
                                                    <strong>GPS real:</strong>
                                                    <?= $reg['lat_real'] ?>, <?= $reg['lon_real'] ?>
                                                </div>
                                                <?php
                                                // fl_attachment fuerza descarga directa desde Cloudinary
                                                $downloadUrl = preg_replace(
                                                    '#/upload/#',
                                                    '/upload/fl_attachment/',
                                                    $reg['url_cloudinary'],
                                                    1
                                                );
                                                ?>
                                                <a href="<?= htmlspecialchars($downloadUrl) ?>"
                                                   class="btn btn-sm btn-outline-secondary"
                                                   title="Descargar foto original">
                                                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                                        <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/>
                                                        <path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/>
                                                    </svg>
                                                </a>
                                            </div>

                                            <?php if ($reg['observaciones']): ?>
                                                <p class="mt-2 mb-0 small">
                                                    <?= nl2br(htmlspecialchars($reg['observaciones'])) ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            No hay registros de inspección para esta infraestructura.
                        </div>
                    <?php endif; ?>

                <?php elseif ($empresaId > 0): ?>
                    <div class="alert alert-secondary">
                        Selecciona una infraestructura del panel izquierdo para ver su línea de tiempo.
                    </div>
                <?php else: ?>
                    <div class="alert alert-secondary">
                        Selecciona una empresa para comenzar.
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
