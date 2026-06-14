<?php
/**
 * INFOCAMPO - Timeline de Inspecciones (Admin)
 *
 * Lista infraestructuras por empresa. Al seleccionar una,
 * muestra la línea de tiempo con fotos.
 * Incluye filtros por fecha, operador y nivel de incidencia.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'supervisor', 'superadmin']);

$pdo = getDB();
$currentPage = 'infraestructuras';

// ---------------------------------------------------------------
// Filtros
// ---------------------------------------------------------------
$empresaId = getEmpresaIdSeguro();
$infraId   = isset($_GET['infra_id'])   ? (int) $_GET['infra_id']   : 0;
$filtroEstado   = $_GET['estado'] ?? '';
$filtroUsuario  = isset($_GET['usuario_id']) ? (int) $_GET['usuario_id'] : 0;
$filtroFechaDesde = $_GET['fecha_desde'] ?? '';
$filtroFechaHasta = $_GET['fecha_hasta'] ?? '';

// ---------------------------------------------------------------
// Cargar infraestructuras + contadores de incidencias
// ---------------------------------------------------------------
$infraestructuras = [];
if ($empresaId > 0) {
    $stmt = $pdo->prepare(
        "SELECT i.id, i.nombre, i.codigo_unico, i.lat_teorica, i.lon_teorica, i.tipo,
                COUNT(r.id) AS total_registros,
                SUM(CASE WHEN r.estado_incidencia = 'durante' THEN 1 ELSE 0 END) AS num_durante,
                (SELECT r2.estado_incidencia FROM registros r2 WHERE r2.infra_id = i.id ORDER BY r2.fecha DESC LIMIT 1) AS ultimo_estado
         FROM infraestructuras i
         LEFT JOIN registros r ON r.infra_id = i.id
         WHERE i.empresa_id = :empresa_id AND i.activa = 1
         GROUP BY i.id
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
            INNER JOIN infraestructuras i ON r.infra_id = i.id
            WHERE r.infra_id = :infra_id AND i.empresa_id = :empresa_id";
    $params = [':infra_id' => $infraId, ':empresa_id' => $empresaId];

    if ($filtroEstado !== '' && in_array($filtroEstado, ['antes', 'durante', 'despues'], true)) {
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
         WHERE i.id = :id AND i.empresa_id = :empresa_id"
    );
    $stmt2->execute([':id' => $infraId, ':empresa_id' => $empresaId]);
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
    <link href="/admin/css/admin.css" rel="stylesheet">
    <style>
        .infra-status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
        .result-count { font-size: 0.8rem; color: var(--text-muted); padding: 8px 0; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="container-fluid py-4">
        <div class="row g-4">

            <!-- COLUMNA IZQUIERDA: Filtros -->
            <div class="col-lg-3">
                <!-- Lista de infraestructuras -->
                <?php if ($infraestructuras): ?>
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title text-muted mb-3"><i class="bi bi-geo-alt me-1"></i>Infraestructuras</h6>
                        <div class="list-group list-group-flush infra-list">
                            <?php foreach ($infraestructuras as $inf): ?>
                                <?php
                                $statusColor = match($inf['ultimo_estado']) {
                                    'antes' => '#3b82f6', 'durante' => '#f59e0b', 'despues' => '#22c55e', default => '#d1d5db',
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
                                    <a href="comparador.php?empresa_id=<?= $empresaId ?>&infra_id=<?= $infraId ?>"
                                       class="btn btn-outline-info btn-sm" title="Comparar fotos entre visitas">
                                        <i class="bi bi-images"></i> Comparar
                                    </a>
                                    <a href="exportar_csv.php?tipo=registros&empresa_id=<?= $empresaId ?>&infra_id=<?= $infraId ?><?= $filtroEstado ? '&estado=' . urlencode($filtroEstado) : '' ?><?= $filtroFechaDesde ? '&fecha_desde=' . urlencode($filtroFechaDesde) : '' ?><?= $filtroFechaHasta ? '&fecha_hasta=' . urlencode($filtroFechaHasta) : '' ?>"
                                       class="btn btn-outline-secondary btn-sm" title="Exportar inspecciones a CSV">
                                        <i class="bi bi-file-earmark-spreadsheet"></i> CSV
                                    </a>
                                    <a href="descargar_fotos.php?infra_id=<?= $infraId ?>" class="btn btn-outline-success btn-sm">
                                        <i class="bi bi-file-earmark-zip"></i> ZIP
                                    </a>
                                    <a href="/public/api/waypoints.php?empresa_id=<?= $empresaId ?>&infra_id=<?= $infraId ?>" class="btn btn-outline-success btn-sm" title="Descargar waypoints comparativos GPX">
                                        <i class="bi bi-geo-alt"></i> GPX
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
                                <div class="filter-label">Situación</div>
                                <select name="estado" class="form-select form-select-sm">
                                    <option value="">Todos</option>
                                    <option value="antes" <?= $filtroEstado === 'antes' ? 'selected' : '' ?>>Antes</option>
                                    <option value="durante" <?= $filtroEstado === 'durante' ? 'selected' : '' ?>>Durante</option>
                                    <option value="despues" <?= $filtroEstado === 'despues' ? 'selected' : '' ?>>Después</option>
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

                    <div class="d-flex justify-content-between align-items-center">
                        <div class="result-count">
                            <?= count($registros) ?> inspeccione<?= count($registros) !== 1 ? 's' : '' ?> encontrada<?= count($registros) !== 1 ? 's' : '' ?>
                        </div>
                        <?php if (!empty($registros)): ?>
                        <div class="btn-group view-toggle" role="group">
                            <button type="button" class="btn btn-outline-secondary btn-sm active" id="btn-view-timeline" onclick="switchView('timeline')">
                                <i class="bi bi-list-ul"></i> Timeline
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-view-gallery" onclick="switchView('gallery')">
                                <i class="bi bi-grid-3x3-gap"></i> Galería
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Timeline de registros -->
                    <?php if ($registros): ?>
                        <!-- Vista Gallery -->
                        <div class="gallery-grid" id="view-gallery" style="display:none;">
                            <?php foreach ($registros as $idx => $reg): ?>
                                <?php
                                $badgeClass2 = $reg['estado_incidencia'];
                                ?>
                                <div class="gallery-item" onclick="openLightbox(<?= $idx ?>)" tabindex="0" role="button" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                                    <img src="<?= htmlspecialchars($reg['url_cloudinary']) ?>"
                                         alt="Inspección <?= date('d/m/Y', strtotime($reg['fecha'])) ?>" loading="lazy">
                                    <div class="gallery-item-info">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="badge-sm <?= $badgeClass2 ?>"><?= strtoupper($reg['estado_incidencia']) ?></span>
                                            <small><?= date('d/m/Y H:i', strtotime($reg['fecha'])) ?></small>
                                        </div>
                                        <div class="mt-1">
                                            <i class="bi bi-person"></i> <?= htmlspecialchars($reg['usuario_nombre']) ?>
                                            <?php if (isset($reg['tipo_foto']) && $reg['tipo_foto']): ?>
                                                | <?= strtoupper($reg['tipo_foto']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Vista Timeline -->
                        <div class="timeline" id="view-timeline">
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
                                                        'antes' => 'bg-primary', 'durante' => 'bg-warning text-dark',
                                                        'despues' => 'bg-success', default => 'bg-secondary',
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
                                                $downloadUrl = $reg['url_cloudinary'];
                                                ?>
                                                <a href="<?= htmlspecialchars($downloadUrl) ?>" download class="btn btn-sm btn-outline-secondary" title="Descargar">
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

    <!-- Lightbox -->
    <div class="lightbox-overlay" id="lightbox">
        <button class="lightbox-close" onclick="closeLightbox()"><i class="bi bi-x-lg"></i></button>
        <button class="lightbox-nav lightbox-prev" onclick="navLightbox(-1)"><i class="bi bi-chevron-left"></i></button>
        <button class="lightbox-nav lightbox-next" onclick="navLightbox(1)"><i class="bi bi-chevron-right"></i></button>
        <img id="lightbox-img" class="lightbox-img" src="" alt="Foto inspección">
        <div id="lightbox-meta" class="lightbox-meta"></div>
        <div id="lightbox-actions" class="lightbox-actions"></div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // View toggle
    function switchView(view) {
        var timeline = document.getElementById('view-timeline');
        var gallery = document.getElementById('view-gallery');
        var btnTimeline = document.getElementById('btn-view-timeline');
        var btnGallery = document.getElementById('btn-view-gallery');

        if (!timeline || !gallery) return;

        if (view === 'gallery') {
            timeline.style.display = 'none';
            gallery.style.display = 'grid';
            btnTimeline.classList.remove('active');
            btnGallery.classList.add('active');
        } else {
            timeline.style.display = 'block';
            gallery.style.display = 'none';
            btnTimeline.classList.add('active');
            btnGallery.classList.remove('active');
        }
    }

    // Lightbox
    var lightboxData = <?= json_encode(array_map(function($r) use ($empresaId) {
        return [
            'url' => $r['url_cloudinary'],
            'fecha' => date('d/m/Y H:i', strtotime($r['fecha'])),
            'operador' => $r['usuario_nombre'],
            'estado' => $r['estado_incidencia'],
            'tipo' => $r['tipo_foto'] ?? 'aleatorio',
            'obs' => $r['observaciones'] ?? '',
            'lat' => $r['lat_real'],
            'lon' => $r['lon_real'],
            'download' => $r['url_cloudinary'],
        ];
    }, $registros), JSON_UNESCAPED_UNICODE) ?>;

    var currentLightboxIdx = 0;

    function openLightbox(idx) {
        currentLightboxIdx = idx;
        renderLightbox();
        document.getElementById('lightbox').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        document.getElementById('lightbox').classList.remove('active');
        document.body.style.overflow = '';
    }

    function navLightbox(dir) {
        currentLightboxIdx += dir;
        if (currentLightboxIdx < 0) currentLightboxIdx = lightboxData.length - 1;
        if (currentLightboxIdx >= lightboxData.length) currentLightboxIdx = 0;
        renderLightbox();
    }

    function renderLightbox() {
        var d = lightboxData[currentLightboxIdx];
        if (!d) return;
        document.getElementById('lightbox-img').src = d.url;
        document.getElementById('lightbox-meta').innerHTML =
            '<strong>' + d.fecha + '</strong> | ' +
            '<span style="text-transform:uppercase;">' + d.estado + '</span> | ' +
            d.operador +
            (d.obs ? '<br><em style="opacity:0.7;">' + d.obs.substring(0, 120) + '</em>' : '') +
            '<br><small style="opacity:0.5;">' + (currentLightboxIdx + 1) + ' / ' + lightboxData.length + '</small>';
        document.getElementById('lightbox-actions').innerHTML =
            '<a href="' + d.download + '" class="btn btn-sm btn-outline-light"><i class="bi bi-download me-1"></i>Descargar</a>' +
            '<a href="' + d.url + '" target="_blank" class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir</a>';
    }

    // Keyboard navigation
    document.addEventListener('keydown', function(e) {
        var lb = document.getElementById('lightbox');
        if (!lb || !lb.classList.contains('active')) return;
        if (e.key === 'Escape') closeLightbox();
        if (e.key === 'ArrowLeft') navLightbox(-1);
        if (e.key === 'ArrowRight') navLightbox(1);
    });

    // Click outside image to close
    document.getElementById('lightbox').addEventListener('click', function(e) {
        if (e.target === this) closeLightbox();
    });
    </script>
</body>
</html>
