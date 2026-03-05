<?php
/**
 * INFOCAMPO SaaS - Comparador de Fotos Side-by-Side (Admin)
 *
 * Muestra visitas comparativas de una infraestructura en formato
 * before/after con slider deslizante.
 *
 * GET: infra_id, empresa_id
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'supervisor', 'superadmin']);

$pdo = getDB();
$currentPage = 'infraestructuras';

$empresaId = (int) ($_GET['empresa_id'] ?? $_SESSION['empresa_id'] ?? 0);
$infraId   = (int) ($_GET['infra_id'] ?? 0);

// Verificar pertenencia
$infra = null;
if ($infraId > 0 && $empresaId > 0) {
    $stmt = $pdo->prepare(
        "SELECT i.*, e.nombre AS empresa_nombre
         FROM infraestructuras i
         INNER JOIN empresas e ON i.empresa_id = e.id
         WHERE i.id = :id AND i.empresa_id = :emp"
    );
    $stmt->execute([':id' => $infraId, ':emp' => $empresaId]);
    $infra = $stmt->fetch();
}

// Cargar todas las visitas comparativas agrupadas por fecha
$visitas = [];
if ($infra) {
    $stmt = $pdo->prepare(
        "SELECT r.id, r.url_cloudinary, r.secuencia_comparativa, r.nombre_archivo,
                r.fecha, r.estado_incidencia, r.observaciones,
                u.nombre AS operador
         FROM registros r
         INNER JOIN usuarios u ON r.usuario_id = u.id
         WHERE r.infra_id = :infra_id AND r.tipo_foto = 'comparativo'
         ORDER BY r.fecha ASC, r.secuencia_comparativa ASC"
    );
    $stmt->execute([':infra_id' => $infraId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $r) {
        $fecha = date('Y-m-d', strtotime($r['fecha']));
        if (!isset($visitas[$fecha])) {
            $visitas[$fecha] = [
                'fecha' => $fecha,
                'fecha_display' => date('d/m/Y', strtotime($r['fecha'])),
                'operador' => $r['operador'],
                'estado' => $r['estado_incidencia'],
                'fotos' => [],
            ];
        }
        $visitas[$fecha]['fotos'][] = [
            'url' => $r['url_cloudinary'],
            'seq' => (int) ($r['secuencia_comparativa'] ?? 0),
            'estado' => $r['estado_incidencia'],
            'obs' => $r['observaciones'] ?? '',
            'hora' => date('H:i', strtotime($r['fecha'])),
        ];
    }
    $visitas = array_values($visitas);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FotoGPS.app - Comparador de Fotos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
        .brand-bar { background: linear-gradient(135deg, #1e3a5f, #2d6a9f); color: #fff; padding: 14px 24px; }
        .nav-admin { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 0 24px; }
        .nav-admin .nav-link { color: #6b7280; padding: 12px 16px; font-size: 0.9rem; border-bottom: 2px solid transparent; }
        .nav-admin .nav-link:hover, .nav-admin .nav-link.active { color: #1e3a5f; border-bottom-color: #1e3a5f; }

        .compare-container {
            position: relative;
            overflow: hidden;
            border-radius: 12px;
            background: #000;
            cursor: col-resize;
            touch-action: none;
        }
        .compare-container img {
            display: block;
            width: 100%;
            height: auto;
            pointer-events: none;
            user-select: none;
        }
        .compare-after {
            position: absolute;
            top: 0;
            left: 0;
            width: 50%;
            height: 100%;
            overflow: hidden;
        }
        .compare-after img {
            position: absolute;
            top: 0;
            left: 0;
            width: 200%;
            max-width: none;
        }
        .compare-slider {
            position: absolute;
            top: 0;
            bottom: 0;
            left: 50%;
            width: 4px;
            background: #fff;
            cursor: col-resize;
            z-index: 10;
            box-shadow: 0 0 8px rgba(0,0,0,0.5);
        }
        .compare-slider::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .compare-slider::before {
            content: '\2194';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1;
            font-size: 1.2rem;
            color: #1e3a5f;
            font-weight: 700;
        }
        .compare-label {
            position: absolute;
            top: 10px;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #fff;
            z-index: 5;
        }
        .compare-label.left { left: 10px; background: rgba(30,58,95,0.8); }
        .compare-label.right { right: 10px; background: rgba(139,92,246,0.8); }

        .visit-card {
            background: #fff;
            border-radius: 10px;
            padding: 12px 16px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            cursor: pointer;
            transition: all 0.15s;
            border: 2px solid transparent;
        }
        .visit-card:hover { border-color: #2d6a9f; }
        .visit-card.active { border-color: #1e3a5f; background: #f0f4ff; }
        .visit-card.selected-left { border-color: #1e3a5f; }
        .visit-card.selected-right { border-color: #8b5cf6; }

        .badge-estado { font-size: 0.6rem; padding: 2px 6px; border-radius: 4px; text-transform: uppercase; font-weight: 700; }
        .badge-estado.antes { background: #dbeafe; color: #1e40af; }
        .badge-estado.durante { background: #fef9c3; color: #854d0e; }
        .badge-estado.despues { background: #dcfce7; color: #166534; }

        .side-by-side {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .side-photo {
            border-radius: 10px;
            overflow: hidden;
            background: #000;
            position: relative;
        }
        .side-photo img { width: 100%; display: block; }
        .side-label {
            position: absolute;
            bottom: 8px;
            left: 8px;
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 700;
            color: #fff;
        }

        @media (max-width: 768px) {
            .side-by-side { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="container-fluid py-4">
        <?php if (!$infra): ?>
            <div class="text-center py-5">
                <i class="bi bi-images" style="font-size:3rem;color:#adb5bd;"></i>
                <h5 class="mt-3 text-muted">Infraestructura no encontrada</h5>
                <a href="infraestructuras.php?empresa_id=<?= $empresaId ?>" class="btn btn-primary mt-2">Volver</a>
            </div>
        <?php elseif (count($visitas) < 2): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <h5><?= htmlspecialchars($infra['nombre']) ?></h5>
                    <p class="text-muted mb-0">
                        <code style="color:#2d6a9f;"><?= htmlspecialchars($infra['codigo_unico']) ?></code>
                    </p>
                </div>
            </div>
            <div class="text-center py-5">
                <i class="bi bi-images" style="font-size:3rem;color:#adb5bd;"></i>
                <h5 class="mt-3 text-muted">Se necesitan al menos 2 visitas comparativas</h5>
                <p class="text-muted">Actualmente hay <?= count($visitas) ?> visita<?= count($visitas) !== 1 ? 's' : '' ?>.</p>
                <a href="index.php?empresa_id=<?= $empresaId ?>&infra_id=<?= $infraId ?>" class="btn btn-outline-primary mt-2">Ver Timeline</a>
            </div>
        <?php else: ?>
            <!-- Header -->
            <div class="card mb-3">
                <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h5 class="mb-1"><i class="bi bi-images me-2"></i>Comparador de Fotos</h5>
                        <span class="text-muted small">
                            <?= htmlspecialchars($infra['nombre']) ?> |
                            <code style="color:#2d6a9f;"><?= htmlspecialchars($infra['codigo_unico']) ?></code> |
                            <?= count($visitas) ?> visitas
                        </span>
                    </div>
                    <a href="index.php?empresa_id=<?= $empresaId ?>&infra_id=<?= $infraId ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left me-1"></i>Timeline
                    </a>
                </div>
            </div>

            <!-- Selector de visitas -->
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="fw-bold small text-muted mb-2 d-block" style="text-transform:uppercase;letter-spacing:0.5px;">
                        <span style="color:#1e3a5f;"><i class="bi bi-arrow-left-circle me-1"></i>Visita Anterior (Antes)</span>
                    </label>
                    <select id="select-left" class="form-select form-select-sm" onchange="updateComparison()">
                        <?php foreach ($visitas as $i => $v): ?>
                            <option value="<?= $i ?>" <?= $i === 0 ? 'selected' : '' ?>>
                                <?= $v['fecha_display'] ?> - <?= $v['operador'] ?> (<?= count($v['fotos']) ?> fotos)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="fw-bold small text-muted mb-2 d-block" style="text-transform:uppercase;letter-spacing:0.5px;">
                        <span style="color:#8b5cf6;"><i class="bi bi-arrow-right-circle me-1"></i>Visita Posterior (Después)</span>
                    </label>
                    <select id="select-right" class="form-select form-select-sm" onchange="updateComparison()">
                        <?php foreach ($visitas as $i => $v): ?>
                            <option value="<?= $i ?>" <?= $i === count($visitas) - 1 ? 'selected' : '' ?>>
                                <?= $v['fecha_display'] ?> - <?= $v['operador'] ?> (<?= count($v['fotos']) ?> fotos)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Modo de vista -->
            <div class="btn-group mb-3" role="group">
                <button type="button" class="btn btn-sm btn-outline-secondary active" id="btn-mode-slider" onclick="setMode('slider')">
                    <i class="bi bi-arrows-expand"></i> Slider
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-mode-side" onclick="setMode('side')">
                    <i class="bi bi-layout-split"></i> Lado a lado
                </button>
            </div>

            <!-- Comparador slider -->
            <div id="compare-area"></div>

        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($infra && count($visitas) >= 2): ?>
    <script>
    var visitas = <?= json_encode($visitas, JSON_UNESCAPED_UNICODE) ?>;
    var currentMode = 'slider';

    function setMode(mode) {
        currentMode = mode;
        document.getElementById('btn-mode-slider').classList.toggle('active', mode === 'slider');
        document.getElementById('btn-mode-side').classList.toggle('active', mode === 'side');
        updateComparison();
    }

    function updateComparison() {
        var leftIdx = parseInt(document.getElementById('select-left').value);
        var rightIdx = parseInt(document.getElementById('select-right').value);
        var left = visitas[leftIdx];
        var right = visitas[rightIdx];

        if (!left || !right) return;

        var area = document.getElementById('compare-area');
        var maxPairs = Math.max(left.fotos.length, right.fotos.length);
        var html = '';

        for (var i = 0; i < maxPairs; i++) {
            var fotoL = left.fotos[i] || null;
            var fotoR = right.fotos[i] || null;

            if (currentMode === 'slider' && fotoL && fotoR) {
                html += '<div class="mb-4">';
                html += '<div class="small text-muted mb-1"><strong>Punto ' + (i + 1) + '</strong></div>';
                html += '<div class="compare-container" id="compare-' + i + '" onmousedown="startDrag(event,' + i + ')" ontouchstart="startDrag(event,' + i + ')">';
                html += '<img src="' + fotoL.url + '" alt="Antes" loading="lazy">';
                html += '<div class="compare-after" id="after-' + i + '">';
                html += '<img src="' + fotoR.url + '" alt="Después" loading="lazy">';
                html += '</div>';
                html += '<div class="compare-slider" id="slider-' + i + '"></div>';
                html += '<div class="compare-label left">' + left.fecha_display + '</div>';
                html += '<div class="compare-label right">' + right.fecha_display + '</div>';
                html += '</div>';
                html += '<div class="d-flex justify-content-between mt-1 small text-muted">';
                if (fotoL.obs) html += '<em>' + fotoL.obs.substring(0, 60) + '</em>';
                if (fotoR.obs) html += '<em>' + fotoR.obs.substring(0, 60) + '</em>';
                html += '</div>';
                html += '</div>';
            } else {
                // Side by side
                html += '<div class="mb-4">';
                html += '<div class="small text-muted mb-1"><strong>Punto ' + (i + 1) + '</strong></div>';
                html += '<div class="side-by-side">';
                if (fotoL) {
                    html += '<div class="side-photo">';
                    html += '<img src="' + fotoL.url + '" alt="Antes" loading="lazy">';
                    html += '<div class="side-label" style="background:rgba(30,58,95,0.8);">' + left.fecha_display + ' ' + fotoL.hora + '</div>';
                    html += '</div>';
                } else {
                    html += '<div class="side-photo d-flex align-items-center justify-content-center" style="background:#f3f4f6;min-height:200px;"><span class="text-muted">Sin foto</span></div>';
                }
                if (fotoR) {
                    html += '<div class="side-photo">';
                    html += '<img src="' + fotoR.url + '" alt="Después" loading="lazy">';
                    html += '<div class="side-label" style="background:rgba(139,92,246,0.8);">' + right.fecha_display + ' ' + fotoR.hora + '</div>';
                    html += '</div>';
                } else {
                    html += '<div class="side-photo d-flex align-items-center justify-content-center" style="background:#f3f4f6;min-height:200px;"><span class="text-muted">Sin foto</span></div>';
                }
                html += '</div>';
                html += '</div>';
            }
        }

        area.innerHTML = html;
    }

    // Slider drag logic
    var dragging = null;

    function startDrag(e, idx) {
        e.preventDefault();
        dragging = idx;
        moveDrag(e, idx);
    }

    document.addEventListener('mousemove', function(e) {
        if (dragging !== null) moveDrag(e, dragging);
    });
    document.addEventListener('touchmove', function(e) {
        if (dragging !== null) moveDrag(e, dragging);
    });
    document.addEventListener('mouseup', function() { dragging = null; });
    document.addEventListener('touchend', function() { dragging = null; });

    function moveDrag(e, idx) {
        var container = document.getElementById('compare-' + idx);
        if (!container) return;

        var rect = container.getBoundingClientRect();
        var clientX = e.touches ? e.touches[0].clientX : e.clientX;
        var x = clientX - rect.left;
        var pct = Math.max(0, Math.min(100, (x / rect.width) * 100));

        var afterDiv = document.getElementById('after-' + idx);
        var sliderDiv = document.getElementById('slider-' + idx);

        afterDiv.style.width = pct + '%';
        afterDiv.querySelector('img').style.width = (10000 / pct) + '%';
        sliderDiv.style.left = pct + '%';
    }

    // Initial render
    updateComparison();
    </script>
    <?php endif; ?>
</body>
</html>
