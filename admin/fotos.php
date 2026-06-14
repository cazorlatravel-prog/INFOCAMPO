<?php
/**
 * INFOCAMPO - Galeria de Fotos (Admin)
 *
 * Pagina completa de gestion de fotos con:
 * - Galeria filtrable por infraestructura, operador, fecha, tipo, estado
 * - Descarga individual y masiva (ZIP)
 * - Comparador visual lado a lado
 * - Generacion de informes desde seleccion
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'supervisor', 'superadmin']);

$pdo = getDB();
$currentPage = 'fotos';

$empresaId = getEmpresaIdSeguro();

// Cargar nombre de empresa
$empresaNombre = '';
if ($empresaId > 0) {
    $stmt = $pdo->prepare("SELECT nombre FROM empresas WHERE id = :id");
    $stmt->execute([':id' => $empresaId]);
    $row = $stmt->fetch();
    if ($row) $empresaNombre = $row['nombre'];
}

// Cargar filtros disponibles
$operadores = [];
$infraestructuras = [];
$unidadesObra = [];

if ($empresaId > 0) {
    $stmt = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE empresa_id = :emp AND activo = 1 ORDER BY nombre");
    $stmt->execute([':emp' => $empresaId]);
    $operadores = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, nombre, codigo_unico FROM infraestructuras WHERE empresa_id = :emp AND activa = 1 ORDER BY nombre");
    $stmt->execute([':emp' => $empresaId]);
    $infraestructuras = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, nombre FROM unidades_obra WHERE empresa_id = :emp AND activa = 1 ORDER BY nombre");
    $stmt->execute([':emp' => $empresaId]);
    $unidadesObra = $stmt->fetchAll();
}

// Cargar registros con fotos
$registros = [];
$stats = ['total' => 0, 'comparativas' => 0, 'aleatorias' => 0, 'antes' => 0, 'durante' => 0, 'despues' => 0];

if ($empresaId > 0) {
    $stmt = $pdo->prepare(
        "SELECT r.id, r.infra_id, r.usuario_id, r.fecha, r.lat_real, r.lon_real,
                r.url_cloudinary, r.estado_incidencia, r.observaciones,
                r.tipo_foto, r.secuencia_comparativa, r.unidad_obra_id,
                i.nombre AS infra_nombre, i.codigo_unico,
                u.nombre AS usuario_nombre,
                uo.nombre AS unidad_obra_nombre
         FROM registros r
         INNER JOIN infraestructuras i ON r.infra_id = i.id
         INNER JOIN usuarios u ON r.usuario_id = u.id
         LEFT JOIN unidades_obra uo ON r.unidad_obra_id = uo.id
         WHERE i.empresa_id = :emp AND r.url_cloudinary IS NOT NULL AND r.url_cloudinary != ''
         ORDER BY r.fecha DESC"
    );
    $stmt->execute([':emp' => $empresaId]);
    $registros = $stmt->fetchAll();

    $stats['total'] = count($registros);
    foreach ($registros as $r) {
        if (($r['tipo_foto'] ?? '') === 'comparativo') $stats['comparativas']++;
        else $stats['aleatorias']++;
        $stats[$r['estado_incidencia']]++;
    }
}

$jsRegistros = [];
foreach ($registros as $r) {
    $jsRegistros[] = [
        'id'         => (int)$r['id'],
        'infra_id'   => (int)$r['infra_id'],
        'usuario_id' => (int)$r['usuario_id'],
        'fecha'      => $r['fecha'],
        'fecha_fmt'  => date('d/m/Y H:i', strtotime($r['fecha'])),
        'lat'        => (float)$r['lat_real'],
        'lon'        => (float)$r['lon_real'],
        'url'        => $r['url_cloudinary'],
        'estado'     => $r['estado_incidencia'],
        'tipo'       => $r['tipo_foto'] ?? 'aleatorio',
        'seq'        => (int)($r['secuencia_comparativa'] ?? 0),
        'obs'        => $r['observaciones'] ?? '',
        'infra'      => $r['infra_nombre'],
        'codigo'     => $r['codigo_unico'],
        'operador'   => $r['usuario_nombre'],
        'uo_id'      => (int)($r['unidad_obra_id'] ?? 0),
        'uo_nombre'  => $r['unidad_obra_nombre'] ?? '',
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FotoGPS.app - Galeria de Fotos</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="/admin/css/admin.css" rel="stylesheet">
<style>
    /* Stats bar */
    .stats-bar {
        display: flex; gap: 16px; padding: 20px 0; flex-wrap: wrap;
    }
    .stat-pill {
        display: flex; align-items: center; gap: 8px;
        background: #fff; border-radius: 12px; padding: 10px 18px;
        box-shadow: var(--card-shadow); font-size: 0.85rem;
        transition: transform 0.15s;
    }
    .stat-pill:hover { transform: translateY(-1px); }
    .stat-pill .stat-num { font-weight: 800; font-size: 1.2rem; }
    .stat-pill .stat-label { color: #6b7280; font-size: 0.75rem; }

    /* Filter panel */
    .filter-panel {
        background: #fff; border-radius: 14px; padding: 20px 24px;
        box-shadow: var(--card-shadow); margin-bottom: 20px;
    }
    .filter-panel .form-select, .filter-panel .form-control {
        border-radius: 10px; font-size: 0.85rem; border-color: #e5e7eb;
    }
    .filter-panel .form-select:focus, .filter-panel .form-control:focus {
        border-color: var(--accent); box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
    }

    /* Toolbar */
    .toolbar-actions {
        display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
    }
    .toolbar-actions .btn { border-radius: 10px; font-size: 0.82rem; font-weight: 600; }

    /* Gallery grid */
    .gallery-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: 16px;
    }

    .foto-card {
        background: #fff; border-radius: 14px; overflow: hidden;
        box-shadow: var(--card-shadow);
        transition: all 0.2s; cursor: pointer; position: relative;
    }
    .foto-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.12); }
    .foto-card.selected { outline: 3px solid var(--accent); outline-offset: -3px; }

    .foto-card .foto-img-wrapper {
        position: relative; overflow: hidden;
        aspect-ratio: 4/3; background: #f0f0f0;
    }
    .foto-card .foto-img-wrapper img {
        width: 100%; height: 100%; object-fit: cover;
        transition: transform 0.3s;
    }
    .foto-card:hover .foto-img-wrapper img { transform: scale(1.05); }

    .foto-card .foto-checkbox {
        position: absolute; top: 10px; left: 10px; z-index: 2;
        width: 24px; height: 24px; border-radius: 8px;
        border: 2px solid #fff; background: rgba(0,0,0,0.3);
        display: flex; align-items: center; justify-content: center;
        cursor: pointer; transition: all 0.2s;
    }
    .foto-card .foto-checkbox:hover { background: var(--accent); border-color: var(--accent); }
    .foto-card.selected .foto-checkbox { background: var(--accent); border-color: var(--accent); }
    .foto-card .foto-checkbox i { color: #fff; font-size: 14px; }

    .foto-card .foto-badges {
        position: absolute; top: 10px; right: 10px; z-index: 2;
        display: flex; gap: 4px;
    }
    .foto-badge {
        padding: 3px 8px; border-radius: 6px; font-size: 0.65rem;
        font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;
    }
    .foto-badge.antes { background: rgba(59,130,246,0.9); color: #fff; }
    .foto-badge.durante { background: rgba(245,158,11,0.9); color: #fff; }
    .foto-badge.despues { background: rgba(34,197,94,0.9); color: #fff; }
    .foto-badge.comp { background: rgba(139,92,246,0.9); color: #fff; }

    .foto-card .foto-info {
        padding: 12px 14px;
    }
    .foto-card .foto-infra {
        font-weight: 700; font-size: 0.85rem; color: #1f2937;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .foto-card .foto-meta-line {
        font-size: 0.75rem; color: #6b7280; margin-top: 2px;
        display: flex; gap: 8px; align-items: center;
    }

    /* Lightbox */
    .lightbox-overlay {
        display: none; position: fixed; inset: 0; z-index: 10000;
        background: rgba(0,0,0,0.92); backdrop-filter: blur(8px);
        justify-content: center; align-items: center;
        flex-direction: column;
    }
    .lightbox-overlay.active { display: flex; }
    .lightbox-overlay img {
        max-width: 90vw; max-height: 80vh; object-fit: contain;
        border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.5);
    }
    .lightbox-close {
        position: absolute; top: 20px; right: 24px;
        background: rgba(255,255,255,0.15); border: none; color: #fff;
        width: 44px; height: 44px; border-radius: 50%; font-size: 1.2rem;
        cursor: pointer; transition: background 0.2s;
    }
    .lightbox-close:hover { background: rgba(255,255,255,0.3); }
    .lightbox-nav {
        position: absolute; top: 50%; transform: translateY(-50%);
        background: rgba(255,255,255,0.15); border: none; color: #fff;
        width: 50px; height: 50px; border-radius: 50%; font-size: 1.3rem;
        cursor: pointer; transition: background 0.2s;
    }
    .lightbox-nav:hover { background: rgba(255,255,255,0.3); }
    .lightbox-nav.prev { left: 20px; }
    .lightbox-nav.next { right: 20px; }
    .lightbox-info {
        color: #fff; text-align: center; margin-top: 16px;
        font-size: 0.85rem; opacity: 0.8;
    }
    .lightbox-actions {
        display: flex; gap: 10px; margin-top: 12px;
    }
    .lightbox-actions .btn { border-radius: 10px; font-size: 0.8rem; }

    /* Comparador */
    .comparador-panel {
        display: none; position: fixed; inset: 0; z-index: 10001;
        background: #fff;
        flex-direction: column;
    }
    .comparador-panel.active { display: flex; }
    .comparador-header {
        background: var(--primary); color: #fff; padding: 14px 24px;
        display: flex; align-items: center; justify-content: space-between;
    }
    .comparador-body {
        flex: 1; display: flex; overflow: hidden;
    }
    .comparador-col {
        flex: 1; display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        padding: 20px; position: relative;
    }
    .comparador-col + .comparador-col { border-left: 3px solid #e5e7eb; }
    .comparador-col img {
        max-width: 100%; max-height: 70vh; object-fit: contain;
        border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    }
    .comparador-col .comp-info {
        margin-top: 14px; text-align: center; font-size: 0.82rem; color: #555;
    }
    .comparador-slider-wrapper {
        flex: 1; display: none; flex-direction: column;
        align-items: center; justify-content: center;
        padding: 20px; position: relative; overflow: hidden;
    }
    .comparador-slider-wrapper.active { display: flex; }
    .slider-container {
        position: relative; width: 90%; max-width: 900px;
        aspect-ratio: 4/3; overflow: hidden; border-radius: 10px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    }
    .slider-container img {
        position: absolute; top: 0; left: 0; width: 100%; height: 100%;
        object-fit: contain; background: #f8f8f8;
    }
    .slider-container .img-clip {
        clip-path: inset(0 0 0 0);
    }
    .slider-handle {
        position: absolute; top: 0; bottom: 0; width: 4px;
        background: var(--accent); cursor: ew-resize; z-index: 5;
    }
    .slider-handle::after {
        content: ''; position: absolute; top: 50%; left: 50%;
        transform: translate(-50%, -50%);
        width: 36px; height: 36px; border-radius: 50%;
        background: var(--accent); border: 3px solid #fff;
        box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    }

    /* Selection bar */
    .selection-bar {
        display: none; position: fixed; bottom: 0; left: 0; right: 0;
        z-index: 9999; background: var(--primary);
        color: #fff; padding: 14px 24px;
        box-shadow: 0 -4px 20px rgba(0,0,0,0.2);
        animation: slideUp 0.3s ease;
    }
    .selection-bar.active { display: flex; align-items: center; justify-content: space-between; }
    @keyframes slideUp {
        from { transform: translateY(100%); }
        to { transform: translateY(0); }
    }

    /* Empty state */
    .empty-state {
        text-align: center; padding: 60px 20px; color: #9ca3af;
    }
    .empty-state i { font-size: 4rem; margin-bottom: 16px; display: block; }

    /* View mode toggle */
    .view-toggle .btn { padding: 6px 12px; }
    .view-toggle .btn.active { background: var(--primary); color: #fff; }

    .gallery-list .foto-card {
        display: flex; flex-direction: row; border-radius: 12px;
    }
    .gallery-list .foto-card .foto-img-wrapper {
        width: 180px; min-width: 180px; aspect-ratio: auto; height: 120px;
    }
    .gallery-list .foto-card .foto-info {
        flex: 1; display: flex; flex-direction: column; justify-content: center;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .gallery-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; }
        .comparador-body { flex-direction: column; }
        .comparador-col + .comparador-col { border-left: none; border-top: 3px solid #e5e7eb; }
        .stats-bar { gap: 8px; }
        .stat-pill { padding: 8px 12px; }
    }
</style>
</head>
<body>

<?php include __DIR__ . '/includes/header.php'; ?>

<div class="container-fluid px-4 py-3">

    <!-- Stats -->
    <div class="stats-bar">
        <div class="stat-pill">
            <div>
                <div class="stat-num" style="color:var(--primary);"><?= $stats['total'] ?></div>
                <div class="stat-label">Total Fotos</div>
            </div>
        </div>
        <div class="stat-pill">
            <div>
                <div class="stat-num" style="color:#8b5cf6;"><?= $stats['comparativas'] ?></div>
                <div class="stat-label">Comparativas</div>
            </div>
        </div>
        <div class="stat-pill">
            <div>
                <div class="stat-num" style="color:var(--accent);"><?= $stats['aleatorias'] ?></div>
                <div class="stat-label">Aleatorias</div>
            </div>
        </div>
        <div class="stat-pill">
            <div>
                <div class="stat-num" style="color:#3b82f6;"><?= $stats['antes'] ?></div>
                <div class="stat-label">Antes</div>
            </div>
        </div>
        <div class="stat-pill">
            <div>
                <div class="stat-num" style="color:#f59e0b;"><?= $stats['durante'] ?></div>
                <div class="stat-label">Durante</div>
            </div>
        </div>
        <div class="stat-pill">
            <div>
                <div class="stat-num" style="color:#22c55e;"><?= $stats['despues'] ?></div>
                <div class="stat-label">Despues</div>
            </div>
        </div>
    </div>

    <!-- Filter panel -->
    <div class="filter-panel">
        <div class="row g-3 align-items-end">
            <div class="col-md-3 col-sm-6">
                <label class="form-label fw-semibold" style="font-size:0.78rem;color:#555;">
                    <i class="bi bi-geo-alt me-1"></i>Infraestructura
                </label>
                <select id="filtro-infra" class="form-select">
                    <option value="">Todas</option>
                    <?php foreach ($infraestructuras as $inf): ?>
                        <option value="<?= $inf['id'] ?>"><?= htmlspecialchars($inf['codigo_unico'] . ' - ' . $inf['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-sm-6">
                <label class="form-label fw-semibold" style="font-size:0.78rem;color:#555;">
                    <i class="bi bi-person me-1"></i>Operador
                </label>
                <select id="filtro-operador" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($operadores as $op): ?>
                        <option value="<?= $op['id'] ?>"><?= htmlspecialchars($op['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-sm-6">
                <label class="form-label fw-semibold" style="font-size:0.78rem;color:#555;">
                    <i class="bi bi-camera me-1"></i>Tipo
                </label>
                <select id="filtro-tipo" class="form-select">
                    <option value="">Todos</option>
                    <option value="comparativo">Comparativas</option>
                    <option value="aleatorio">Aleatorias</option>
                </select>
            </div>
            <div class="col-md-2 col-sm-6">
                <label class="form-label fw-semibold" style="font-size:0.78rem;color:#555;">
                    <i class="bi bi-flag me-1"></i>Estado
                </label>
                <select id="filtro-estado" class="form-select">
                    <option value="">Todos</option>
                    <option value="antes">Antes</option>
                    <option value="durante">Durante</option>
                    <option value="despues">Despues</option>
                </select>
            </div>
            <div class="col-md-1 col-sm-3">
                <label class="form-label fw-semibold" style="font-size:0.78rem;color:#555;">Desde</label>
                <input type="date" id="filtro-desde" class="form-control">
            </div>
            <div class="col-md-1 col-sm-3">
                <label class="form-label fw-semibold" style="font-size:0.78rem;color:#555;">Hasta</label>
                <input type="date" id="filtro-hasta" class="form-control">
            </div>
            <div class="col-md-1 col-sm-6">
                <button id="btn-limpiar-filtros" class="btn btn-outline-secondary w-100" style="border-radius:10px;font-size:0.82rem;">
                    <i class="bi bi-x-circle"></i> Limpiar
                </button>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="d-flex justify-content-between align-items-center mt-3 pt-3" style="border-top:1px solid #f0f0f0;">
            <div class="d-flex align-items-center gap-3">
                <span id="contador-fotos" class="fw-semibold" style="font-size:0.85rem;color:#555;"></span>
                <div class="view-toggle btn-group btn-group-sm">
                    <button class="btn btn-outline-secondary active" data-view="grid" title="Vista cuadricula">
                        <i class="bi bi-grid-3x3-gap"></i>
                    </button>
                    <button class="btn btn-outline-secondary" data-view="list" title="Vista lista">
                        <i class="bi bi-list-ul"></i>
                    </button>
                </div>
            </div>
            <div class="toolbar-actions">
                <button id="btn-select-all" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-check2-all"></i> Seleccionar todo
                </button>
                <button id="btn-comparar" class="btn btn-purple btn-sm" disabled style="background:#8b5cf6;color:#fff;border:none;">
                    <i class="bi bi-layout-split"></i> Comparar
                </button>
                <button id="btn-descargar" class="btn btn-success btn-sm" disabled>
                    <i class="bi bi-download"></i> Descargar
                </button>
                <button id="btn-informe" class="btn btn-primary btn-sm" disabled>
                    <i class="bi bi-file-earmark-pdf"></i> Generar Informe
                </button>
            </div>
        </div>
    </div>

    <!-- Gallery -->
    <div id="gallery-container" class="gallery-grid">
        <!-- Populated by JS -->
    </div>

    <div id="empty-state" class="empty-state" style="display:none;">
        <i class="bi bi-images"></i>
        <h4>No hay fotos</h4>
        <p>No se encontraron fotos con los filtros seleccionados.</p>
    </div>

</div>

<!-- Lightbox -->
<div id="lightbox" class="lightbox-overlay">
    <button class="lightbox-close" onclick="Fotos.closeLightbox()"><i class="bi bi-x-lg"></i></button>
    <button class="lightbox-nav prev" onclick="Fotos.lightboxNav(-1)"><i class="bi bi-chevron-left"></i></button>
    <button class="lightbox-nav next" onclick="Fotos.lightboxNav(1)"><i class="bi bi-chevron-right"></i></button>
    <img id="lightbox-img" src="" alt="Foto">
    <div id="lightbox-info" class="lightbox-info"></div>
    <div class="lightbox-actions">
        <a id="lightbox-download" href="#" download class="btn btn-sm btn-outline-light">
            <i class="bi bi-download"></i> Descargar
        </a>
        <button id="lightbox-add-compare" class="btn btn-sm" style="background:#8b5cf6;color:#fff;" onclick="Fotos.addToCompare()">
            <i class="bi bi-plus-lg"></i> Comparar
        </button>
    </div>
</div>

<!-- Comparador -->
<div id="comparador" class="comparador-panel">
    <div class="comparador-header">
        <div class="d-flex align-items-center gap-3">
            <i class="bi bi-layout-split" style="font-size:1.2rem;"></i>
            <h5 class="mb-0 fw-bold">Comparador de Fotos</h5>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-light" id="btn-comp-mode-side" onclick="Fotos.setCompareMode('side')">
                <i class="bi bi-layout-split"></i> Lado a Lado
            </button>
            <button class="btn btn-sm btn-outline-light" id="btn-comp-mode-slider" onclick="Fotos.setCompareMode('slider')">
                <i class="bi bi-sliders"></i> Deslizador
            </button>
            <button class="btn btn-sm btn-light fw-semibold" onclick="Fotos.closeComparador()">
                <i class="bi bi-x-lg"></i> Cerrar
            </button>
        </div>
    </div>
    <div class="comparador-body" id="comparador-side">
        <div class="comparador-col" id="comp-col-1">
            <div class="text-center text-muted"><i class="bi bi-image" style="font-size:3rem;"></i><br>Selecciona una foto</div>
        </div>
        <div class="comparador-col" id="comp-col-2">
            <div class="text-center text-muted"><i class="bi bi-image" style="font-size:3rem;"></i><br>Selecciona una foto</div>
        </div>
    </div>
    <div class="comparador-slider-wrapper" id="comparador-slider">
        <div class="slider-container" id="slider-container">
            <img id="slider-img-1" src="" alt="Foto 1">
            <img id="slider-img-2" src="" alt="Foto 2" class="img-clip">
            <div class="slider-handle" id="slider-handle"></div>
        </div>
        <div class="d-flex gap-4 mt-3">
            <div id="slider-info-1" class="text-muted" style="font-size:0.8rem;"></div>
            <div id="slider-info-2" class="text-muted" style="font-size:0.8rem;"></div>
        </div>
    </div>
</div>

<!-- Selection bar -->
<div id="selection-bar" class="selection-bar">
    <div class="d-flex align-items-center gap-3">
        <span id="sel-count" class="fw-bold"></span>
        <button class="btn btn-sm btn-outline-light" onclick="Fotos.clearSelection()">
            <i class="bi bi-x-circle"></i> Deseleccionar
        </button>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-sm" style="background:#8b5cf6;color:#fff;" onclick="Fotos.openComparador()">
            <i class="bi bi-layout-split"></i> Comparar
        </button>
        <button class="btn btn-sm btn-success" onclick="Fotos.downloadSelected()">
            <i class="bi bi-download"></i> Descargar ZIP
        </button>
        <button class="btn btn-sm btn-light fw-semibold" onclick="Fotos.generateReport()">
            <i class="bi bi-file-earmark-pdf"></i> Informe
        </button>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const Fotos = (() => {
    const allFotos = <?= json_encode($jsRegistros, JSON_UNESCAPED_UNICODE) ?>;
    let filtered = [...allFotos];
    let selected = new Set();
    let compareList = [];
    let lightboxIdx = -1;
    let viewMode = 'grid';
    let compareMode = 'side';

    function init() {
        bindFilters();
        bindToolbar();
        bindKeyboard();
        render();
        updateCounter();
    }

    function bindFilters() {
        ['filtro-infra', 'filtro-operador', 'filtro-tipo', 'filtro-estado'].forEach(id => {
            document.getElementById(id).addEventListener('change', applyFilters);
        });
        ['filtro-desde', 'filtro-hasta'].forEach(id => {
            document.getElementById(id).addEventListener('change', applyFilters);
        });
        document.getElementById('btn-limpiar-filtros').addEventListener('click', () => {
            ['filtro-infra', 'filtro-operador', 'filtro-tipo', 'filtro-estado', 'filtro-desde', 'filtro-hasta'].forEach(id => {
                document.getElementById(id).value = '';
            });
            applyFilters();
        });
    }

    function bindToolbar() {
        document.getElementById('btn-select-all').addEventListener('click', toggleSelectAll);
        document.getElementById('btn-comparar').addEventListener('click', openComparador);
        document.getElementById('btn-descargar').addEventListener('click', downloadSelected);
        document.getElementById('btn-informe').addEventListener('click', generateReport);

        document.querySelectorAll('.view-toggle .btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.view-toggle .btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                viewMode = btn.dataset.view;
                render();
            });
        });
    }

    function bindKeyboard() {
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                closeLightbox();
                closeComparador();
            }
            if (document.getElementById('lightbox').classList.contains('active')) {
                if (e.key === 'ArrowLeft') lightboxNav(-1);
                if (e.key === 'ArrowRight') lightboxNav(1);
            }
        });
    }

    function applyFilters() {
        const infraId = document.getElementById('filtro-infra').value;
        const operadorId = document.getElementById('filtro-operador').value;
        const tipo = document.getElementById('filtro-tipo').value;
        const estado = document.getElementById('filtro-estado').value;
        const desde = document.getElementById('filtro-desde').value;
        const hasta = document.getElementById('filtro-hasta').value;

        filtered = allFotos.filter(f => {
            if (infraId && f.infra_id !== parseInt(infraId)) return false;
            if (operadorId && f.usuario_id !== parseInt(operadorId)) return false;
            if (tipo && f.tipo !== tipo) return false;
            if (estado && f.estado !== estado) return false;
            if (desde && f.fecha < desde) return false;
            if (hasta && f.fecha > hasta + ' 23:59:59') return false;
            return true;
        });

        selected.clear();
        updateSelectionUI();
        render();
        updateCounter();
    }

    function render() {
        const container = document.getElementById('gallery-container');
        const empty = document.getElementById('empty-state');

        if (filtered.length === 0) {
            container.innerHTML = '';
            empty.style.display = 'block';
            return;
        }
        empty.style.display = 'none';

        container.className = viewMode === 'list' ? 'gallery-grid gallery-list' : 'gallery-grid';
        if (viewMode === 'list') {
            container.style.gridTemplateColumns = '1fr';
        } else {
            container.style.gridTemplateColumns = '';
        }

        container.innerHTML = filtered.map((f, idx) => {
            const isSelected = selected.has(f.id);
            const tipoLabel = f.tipo === 'comparativo' ? 'comp' : '';
            const estadoMap = { antes: 'Antes', durante: 'Durante', despues: 'Despues' };

            return `
                <div class="foto-card ${isSelected ? 'selected' : ''}" data-id="${f.id}" data-idx="${idx}">
                    <div class="foto-checkbox" onclick="event.stopPropagation();Fotos.toggleSelect(${f.id})">
                        <i class="bi ${isSelected ? 'bi-check' : ''}"></i>
                    </div>
                    <div class="foto-badges">
                        ${tipoLabel ? `<span class="foto-badge comp">COMP</span>` : ''}
                        <span class="foto-badge ${f.estado}">${estadoMap[f.estado] || f.estado}</span>
                    </div>
                    <div class="foto-img-wrapper" onclick="Fotos.openLightbox(${idx})">
                        <img src="${f.url}" alt="${f.infra}" loading="lazy">
                    </div>
                    <div class="foto-info">
                        <div class="foto-infra">${f.codigo} - ${f.infra}</div>
                        <div class="foto-meta-line">
                            <span><i class="bi bi-person"></i> ${f.operador}</span>
                            <span><i class="bi bi-calendar"></i> ${f.fecha_fmt}</span>
                        </div>
                        ${f.uo_nombre ? `<div class="foto-meta-line"><span><i class="bi bi-tools"></i> ${f.uo_nombre}</span></div>` : ''}
                    </div>
                </div>`;
        }).join('');
    }

    function updateCounter() {
        const el = document.getElementById('contador-fotos');
        el.textContent = `${filtered.length} foto${filtered.length !== 1 ? 's' : ''} encontrada${filtered.length !== 1 ? 's' : ''}`;
    }

    function toggleSelect(id) {
        if (selected.has(id)) selected.delete(id);
        else selected.add(id);
        updateSelectionUI();
        // Update card visual
        const card = document.querySelector(`.foto-card[data-id="${id}"]`);
        if (card) {
            card.classList.toggle('selected', selected.has(id));
            card.querySelector('.foto-checkbox i').className = selected.has(id) ? 'bi bi-check' : 'bi';
        }
    }

    function toggleSelectAll() {
        if (selected.size === filtered.length) {
            selected.clear();
        } else {
            filtered.forEach(f => selected.add(f.id));
        }
        updateSelectionUI();
        render();
    }

    function updateSelectionUI() {
        const count = selected.size;
        const bar = document.getElementById('selection-bar');
        const btnComp = document.getElementById('btn-comparar');
        const btnDl = document.getElementById('btn-descargar');
        const btnInf = document.getElementById('btn-informe');

        if (count > 0) {
            bar.classList.add('active');
            document.getElementById('sel-count').textContent = `${count} foto${count > 1 ? 's' : ''} seleccionada${count > 1 ? 's' : ''}`;
        } else {
            bar.classList.remove('active');
        }

        btnComp.disabled = count < 2;
        btnDl.disabled = count === 0;
        btnInf.disabled = count === 0;
    }

    function clearSelection() {
        selected.clear();
        updateSelectionUI();
        render();
    }

    function esc(s) {
        if (s == null) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }

    // Lightbox
    function openLightbox(idx) {
        lightboxIdx = idx;
        const f = filtered[idx];
        const lb = document.getElementById('lightbox');
        document.getElementById('lightbox-img').src = f.url;
        document.getElementById('lightbox-info').innerHTML =
            `<strong>${esc(f.codigo)} - ${esc(f.infra)}</strong> | ${esc(f.operador)} | ${esc(f.fecha_fmt)} | ${esc(String(f.estado).toUpperCase())}` +
            (f.obs ? `<br>${esc(f.obs)}` : '');
        document.getElementById('lightbox-download').href = f.url;
        lb.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        document.getElementById('lightbox').classList.remove('active');
        document.body.style.overflow = '';
    }

    function lightboxNav(dir) {
        lightboxIdx = (lightboxIdx + dir + filtered.length) % filtered.length;
        const f = filtered[lightboxIdx];
        document.getElementById('lightbox-img').src = f.url;
        document.getElementById('lightbox-info').innerHTML =
            `<strong>${esc(f.codigo)} - ${esc(f.infra)}</strong> | ${esc(f.operador)} | ${esc(f.fecha_fmt)} | ${esc(String(f.estado).toUpperCase())}` +
            (f.obs ? `<br>${esc(f.obs)}` : '');
        document.getElementById('lightbox-download').href = f.url;
    }

    function addToCompare() {
        if (lightboxIdx < 0) return;
        const f = filtered[lightboxIdx];
        if (!selected.has(f.id)) {
            selected.add(f.id);
            updateSelectionUI();
            render();
        }
        closeLightbox();
    }

    // Comparador
    function openComparador() {
        const sel = allFotos.filter(f => selected.has(f.id));
        if (sel.length < 2) {
            alert('Selecciona al menos 2 fotos para comparar.');
            return;
        }
        compareList = sel.slice(0, 2);
        renderComparador();
        document.getElementById('comparador').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeComparador() {
        document.getElementById('comparador').classList.remove('active');
        document.body.style.overflow = '';
    }

    function setCompareMode(mode) {
        compareMode = mode;
        renderComparador();
    }

    function renderComparador() {
        const side = document.getElementById('comparador-side');
        const slider = document.getElementById('comparador-slider');

        if (compareMode === 'side') {
            side.style.display = 'flex';
            slider.classList.remove('active');
            renderSideBySlide();
        } else {
            side.style.display = 'none';
            slider.classList.add('active');
            renderSlider();
        }
    }

    function renderSideBySlide() {
        const [f1, f2] = compareList;
        document.getElementById('comp-col-1').innerHTML = `
            <img src="${f1.url}" alt="">
            <div class="comp-info">
                <strong>${esc(f1.codigo)} - ${esc(f1.infra)}</strong><br>
                ${esc(f1.operador)} | ${esc(f1.fecha_fmt)}<br>
                <span style="text-transform:uppercase;font-weight:700;">${esc(f1.estado)}</span>
                ${f1.obs ? '<br><em>' + esc(f1.obs) + '</em>' : ''}
            </div>`;
        document.getElementById('comp-col-2').innerHTML = `
            <img src="${f2.url}" alt="">
            <div class="comp-info">
                <strong>${esc(f2.codigo)} - ${esc(f2.infra)}</strong><br>
                ${esc(f2.operador)} | ${esc(f2.fecha_fmt)}<br>
                <span style="text-transform:uppercase;font-weight:700;">${esc(f2.estado)}</span>
                ${f2.obs ? '<br><em>' + esc(f2.obs) + '</em>' : ''}
            </div>`;
    }

    function renderSlider() {
        const [f1, f2] = compareList;
        document.getElementById('slider-img-1').src = f1.url;
        document.getElementById('slider-img-2').src = f2.url;
        document.getElementById('slider-info-1').innerHTML = `<strong>${esc(f1.codigo)}</strong> ${esc(f1.fecha_fmt)} - ${esc(String(f1.estado).toUpperCase())}`;
        document.getElementById('slider-info-2').innerHTML = `<strong>${esc(f2.codigo)}</strong> ${esc(f2.fecha_fmt)} - ${esc(String(f2.estado).toUpperCase())}`;

        // Init slider drag
        const container = document.getElementById('slider-container');
        const handle = document.getElementById('slider-handle');
        const img2 = document.getElementById('slider-img-2');

        let pos = 50;
        updateSliderPos(pos);

        function updateSliderPos(pct) {
            handle.style.left = pct + '%';
            img2.style.clipPath = `inset(0 0 0 ${pct}%)`;
        }

        function onMove(clientX) {
            const rect = container.getBoundingClientRect();
            let pct = ((clientX - rect.left) / rect.width) * 100;
            pct = Math.max(0, Math.min(100, pct));
            updateSliderPos(pct);
        }

        handle.onmousedown = e => {
            e.preventDefault();
            const move = ev => onMove(ev.clientX);
            const up = () => { document.removeEventListener('mousemove', move); document.removeEventListener('mouseup', up); };
            document.addEventListener('mousemove', move);
            document.addEventListener('mouseup', up);
        };

        handle.ontouchstart = e => {
            const move = ev => onMove(ev.touches[0].clientX);
            const end = () => { document.removeEventListener('touchmove', move); document.removeEventListener('touchend', end); };
            document.addEventListener('touchmove', move);
            document.addEventListener('touchend', end);
        };
    }

    // Download
    function downloadSelected() {
        const ids = [...selected];
        if (ids.length === 0) return;

        if (ids.length === 1) {
            const f = allFotos.find(f => f.id === ids[0]);
            if (f) {
                const a = document.createElement('a');
                a.href = f.url;
                a.download = `${f.codigo}_${f.estado}_${f.id}.jpg`;
                a.click();
            }
            return;
        }

        // Multiple: open download endpoint
        const idsParam = ids.join(',');
        window.location.href = `descargar_fotos_sel.php?ids=${idsParam}`;
    }

    // Report
    function generateReport() {
        const ids = [...selected];
        if (ids.length === 0) return;

        // Group by infra
        const infraIds = new Set();
        allFotos.filter(f => selected.has(f.id)).forEach(f => infraIds.add(f.infra_id));

        if (infraIds.size === 1) {
            window.open(`generar_pdf.php?infra_id=${[...infraIds][0]}`, '_blank');
        } else {
            const idsParam = [...infraIds].join(',');
            window.open(`generar_pdf.php?infra_ids=${idsParam}`, '_blank');
        }
    }

    return { init, toggleSelect, openLightbox, closeLightbox, lightboxNav, addToCompare, openComparador, closeComparador, setCompareMode, clearSelection, downloadSelected, generateReport };
})();

document.addEventListener('DOMContentLoaded', Fotos.init);
</script>
</body>
</html>
