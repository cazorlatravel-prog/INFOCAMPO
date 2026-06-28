<?php
/**
 * INFOCAMPO - Vista de Progreso por Infraestructura
 *
 * Muestra la evolucion visual de una infraestructura:
 * antes -> durante -> despues, con fotos en orden cronologico.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

requireRole(['admin', 'supervisor', 'superadmin']);

$pdo = getDB();
$empresaId = getEmpresaIdSeguro();
$currentPage = 'progreso';

// Obtener infra_id del parametro GET
$infraId = isset($_GET['infra_id']) ? (int)$_GET['infra_id'] : 0;
if ($infraId <= 0) {
    header('Location: index.php');
    exit;
}

// Obtener datos de la infraestructura
$stmtInfra = $pdo->prepare(
    "SELECT id, nombre, codigo_unico, tipo, lat_teorica, lon_teorica, provincia, municipio
     FROM infraestructuras
     WHERE id = :infra_id AND empresa_id = :empresa_id"
);
$stmtInfra->execute([':infra_id' => $infraId, ':empresa_id' => $empresaId]);
$infra = $stmtInfra->fetch(PDO::FETCH_ASSOC);

if (!$infra) {
    header('Location: index.php');
    exit;
}

// Obtener registros con nombre de usuario
$stmtRegs = $pdo->prepare(
    "SELECT r.*, u.nombre AS usuario_nombre
     FROM registros r
     INNER JOIN usuarios u ON r.usuario_id = u.id
     INNER JOIN infraestructuras i ON r.infra_id = i.id
     WHERE r.infra_id = :infra_id AND i.empresa_id = :empresa_id
     ORDER BY r.fecha ASC"
);
$stmtRegs->execute([':infra_id' => $infraId, ':empresa_id' => $empresaId]);
$registros = $stmtRegs->fetchAll(PDO::FETCH_ASSOC);

// Clasificar por estado
$antes = array_filter($registros, fn($r) => $r['estado_incidencia'] === 'antes');
$durante = array_filter($registros, fn($r) => $r['estado_incidencia'] === 'durante');
$despues = array_filter($registros, fn($r) => $r['estado_incidencia'] === 'despues');

$total = count($registros);
$pctAntes = $total > 0 ? round(count($antes) / $total * 100) : 0;
$pctDurante = $total > 0 ? round(count($durante) / $total * 100) : 0;
$pctDespues = $total > 0 ? round(count($despues) / $total * 100) : 0;

// Estadisticas
$operadores = array_unique(array_column($registros, 'usuario_nombre'));
$fechaMin = $total > 0 ? $registros[0]['fecha'] : null;
$fechaMax = $total > 0 ? $registros[$total - 1]['fecha'] : null;
$diasDesdeInicio = $fechaMin ? (int)((time() - strtotime($fechaMin)) / 86400) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Progreso - <?= htmlspecialchars($infra['nombre']) ?> | INFOCAMPO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        .progress-section { margin-bottom: 2rem; }
        .estado-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-weight: 700;
            font-size: 1rem;
        }
        .estado-header--antes { background: #e0edff; color: #2563eb; }
        .estado-header--durante { background: #fff8e1; color: #b8860b; }
        .estado-header--despues { background: #e8faf0; color: #0d9f5f; }

        .photo-card {
            border: 1px solid #e8ecf1;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 1rem;
            transition: box-shadow 0.2s;
        }
        .photo-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.1); }
        .photo-card img {
            width: 100%;
            aspect-ratio: 16/10;
            object-fit: cover;
            cursor: pointer;
            display: block;
        }
        .photo-card-info {
            padding: 0.75rem 1rem;
            font-size: 0.82rem;
            color: #5a6675;
        }
        .photo-card-info .date { font-weight: 600; color: #1a1a2e; }
        .photo-card-info .operator { color: #4f6ef7; }
        .photo-card-info .obs {
            margin-top: 0.4rem;
            font-style: italic;
            color: #737f8c;
        }

        .progress-bar-antes { background-color: #3b82f6; }
        .progress-bar-durante { background-color: #f59e0b; }
        .progress-bar-despues { background-color: #22c55e; }

        .stat-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            background: #f5f7fa;
            color: #5a6675;
        }

        .infra-header-card {
            background: #fff;
            border: 1px solid #e8ecf1;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #adb5bd;
        }
        .empty-state i { font-size: 2.5rem; margin-bottom: 0.5rem; }

        /* Lightbox */
        .lightbox-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.9);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .lightbox-overlay.active { display: flex; }
        .lightbox-overlay img {
            max-width: 95vw;
            max-height: 95vh;
            object-fit: contain;
            border-radius: 8px;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>

<div id="main-content" class="container-fluid py-4" style="max-width: 1400px;">

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">Timeline</a></li>
            <li class="breadcrumb-item"><a href="index.php?infra_id=<?= $infraId ?>">
                <?= htmlspecialchars($infra['nombre']) ?>
            </a></li>
            <li class="breadcrumb-item active">Progreso</li>
        </ol>
    </nav>

    <!-- Infrastructure Header -->
    <div class="infra-header-card">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h4 class="mb-1">
                    <i class="bi bi-bar-chart-steps text-warning me-2"></i>
                    <?= htmlspecialchars($infra['nombre']) ?>
                </h4>
                <div class="text-muted">
                    <code style="color:#2d6a9f;"><?= htmlspecialchars($infra['codigo_unico']) ?></code>
                    <?php if ($infra['tipo']): ?>
                        &nbsp;|&nbsp; <?= htmlspecialchars($infra['tipo']) ?>
                    <?php endif; ?>
                    &nbsp;|&nbsp; GPS: <?= $infra['lat_teorica'] ?>, <?= $infra['lon_teorica'] ?>
                    <?php if ($infra['provincia']): ?>
                        &nbsp;|&nbsp; <?= htmlspecialchars($infra['provincia']) ?>
                        <?php if ($infra['municipio']): ?>
                            , <?= htmlspecialchars($infra['municipio']) ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <a href="index.php?infra_id=<?= $infraId ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Volver al Timeline
            </a>
        </div>
    </div>

    <!-- Summary Stats -->
    <div class="d-flex flex-wrap gap-2 mb-4">
        <span class="stat-badge"><i class="bi bi-camera"></i> <?= $total ?> fotos</span>
        <?php if ($fechaMin): ?>
            <span class="stat-badge"><i class="bi bi-calendar-range"></i>
                <?= date('d/m/Y', strtotime($fechaMin)) ?> - <?= date('d/m/Y', strtotime($fechaMax)) ?>
            </span>
        <?php endif; ?>
        <span class="stat-badge"><i class="bi bi-people"></i> <?= count($operadores) ?> operador<?= count($operadores) !== 1 ? 'es' : '' ?></span>
        <?php if ($diasDesdeInicio > 0): ?>
            <span class="stat-badge"><i class="bi bi-clock-history"></i> <?= $diasDesdeInicio ?> dias desde primera visita</span>
        <?php endif; ?>
    </div>

    <!-- Progress Bar -->
    <?php if ($total > 0): ?>
    <div class="progress-section">
        <div class="d-flex justify-content-between mb-2" style="font-size:0.82rem;font-weight:600;">
            <span style="color:#3b82f6;">Antes (<?= count($antes) ?>)</span>
            <span style="color:#f59e0b;">Durante (<?= count($durante) ?>)</span>
            <span style="color:#22c55e;">Despues (<?= count($despues) ?>)</span>
        </div>
        <div class="progress" style="height: 12px; border-radius: 6px;">
            <div class="progress-bar progress-bar-antes" style="width: <?= $pctAntes ?>%"
                 title="Antes: <?= $pctAntes ?>%"></div>
            <div class="progress-bar progress-bar-durante" style="width: <?= $pctDurante ?>%"
                 title="Durante: <?= $pctDurante ?>%"></div>
            <div class="progress-bar progress-bar-despues" style="width: <?= $pctDespues ?>%"
                 title="Despues: <?= $pctDespues ?>%"></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Three Columns -->
    <div class="row">
        <!-- ANTES -->
        <div class="col-lg-4">
            <div class="estado-header estado-header--antes">
                <i class="bi bi-circle-fill" style="font-size:0.6rem;"></i>
                ANTES
                <span class="badge bg-primary ms-auto"><?= count($antes) ?></span>
            </div>
            <?php if (count($antes) === 0): ?>
                <div class="empty-state">
                    <i class="bi bi-camera d-block"></i>
                    <span>Sin fotos en estado "antes"</span>
                </div>
            <?php else: ?>
                <?php foreach ($antes as $reg): ?>
                    <div class="photo-card">
                        <img src="<?= htmlspecialchars($reg['url_cloudinary'] ?? '') ?>"
                             alt="<?= htmlspecialchars($reg['nombre_archivo'] ?? '') ?>"
                             loading="lazy"
                             onclick="openLightbox(this.src)">
                        <div class="photo-card-info">
                            <div class="date"><?= date('d/m/Y H:i', strtotime($reg['fecha'])) ?></div>
                            <div class="operator"><i class="bi bi-person"></i> <?= htmlspecialchars($reg['usuario_nombre']) ?></div>
                            <?php if (!empty($reg['observaciones'])): ?>
                                <div class="obs"><?= htmlspecialchars($reg['observaciones']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- DURANTE -->
        <div class="col-lg-4">
            <div class="estado-header estado-header--durante">
                <i class="bi bi-circle-fill" style="font-size:0.6rem;"></i>
                DURANTE
                <span class="badge bg-warning text-dark ms-auto"><?= count($durante) ?></span>
            </div>
            <?php if (count($durante) === 0): ?>
                <div class="empty-state">
                    <i class="bi bi-camera d-block"></i>
                    <span>Sin fotos en estado "durante"</span>
                </div>
            <?php else: ?>
                <?php foreach ($durante as $reg): ?>
                    <div class="photo-card">
                        <img src="<?= htmlspecialchars($reg['url_cloudinary'] ?? '') ?>"
                             alt="<?= htmlspecialchars($reg['nombre_archivo'] ?? '') ?>"
                             loading="lazy"
                             onclick="openLightbox(this.src)">
                        <div class="photo-card-info">
                            <div class="date"><?= date('d/m/Y H:i', strtotime($reg['fecha'])) ?></div>
                            <div class="operator"><i class="bi bi-person"></i> <?= htmlspecialchars($reg['usuario_nombre']) ?></div>
                            <?php if (!empty($reg['observaciones'])): ?>
                                <div class="obs"><?= htmlspecialchars($reg['observaciones']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- DESPUES -->
        <div class="col-lg-4">
            <div class="estado-header estado-header--despues">
                <i class="bi bi-circle-fill" style="font-size:0.6rem;"></i>
                DESPUES
                <span class="badge bg-success ms-auto"><?= count($despues) ?></span>
            </div>
            <?php if (count($despues) === 0): ?>
                <div class="empty-state">
                    <i class="bi bi-camera d-block"></i>
                    <span>Sin fotos en estado "despues"</span>
                </div>
            <?php else: ?>
                <?php foreach ($despues as $reg): ?>
                    <div class="photo-card">
                        <img src="<?= htmlspecialchars($reg['url_cloudinary'] ?? '') ?>"
                             alt="<?= htmlspecialchars($reg['nombre_archivo'] ?? '') ?>"
                             loading="lazy"
                             onclick="openLightbox(this.src)">
                        <div class="photo-card-info">
                            <div class="date"><?= date('d/m/Y H:i', strtotime($reg['fecha'])) ?></div>
                            <div class="operator"><i class="bi bi-person"></i> <?= htmlspecialchars($reg['usuario_nombre']) ?></div>
                            <?php if (!empty($reg['observaciones'])): ?>
                                <div class="obs"><?= htmlspecialchars($reg['observaciones']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Lightbox -->
<div class="lightbox-overlay" id="lightbox" onclick="closeLightbox()">
    <img src="" alt="Foto ampliada" id="lightbox-img">
</div>

<script>
function openLightbox(src) {
    const lb = document.getElementById('lightbox');
    document.getElementById('lightbox-img').src = src;
    lb.classList.add('active');
}
function closeLightbox() {
    document.getElementById('lightbox').classList.remove('active');
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeLightbox();
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
