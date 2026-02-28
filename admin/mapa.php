<?php
/**
 * INFOCAMPO SaaS - Mapa Avanzado de Fotos (Admin)
 *
 * Visualiza TODAS las fotos de campo geolocalizadas:
 * - Marcadores por infraestructura (agrupados)
 * - Marcadores individuales por foto
 * - Filtros por operador, unidad de obra, infraestructura, tipo de foto
 * - Popups con preview de fotos y descarga
 * - Enlace a generar informes por infraestructura
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$pdo = getDB();
$currentPage = 'mapa';

$empresaId = isset($_GET['empresa_id']) ? (int) $_GET['empresa_id'] : ($_SESSION['empresa_id'] ?? 0);

$empresas = $pdo->query(
    "SELECT id, nombre FROM empresas WHERE activa = 1 ORDER BY nombre"
)->fetchAll();

// Cargar datos para filtros
$operadores = [];
$unidadesObra = [];
$infraestructuras = [];
$registros = [];
$empresaNombre = '';

if ($empresaId > 0) {
    $stmt = $pdo->prepare("SELECT nombre FROM empresas WHERE id = :id");
    $stmt->execute([':id' => $empresaId]);
    $row = $stmt->fetch();
    if ($row) $empresaNombre = $row['nombre'];

    // Operadores
    $stmt = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE empresa_id = :emp AND activo = 1 ORDER BY nombre");
    $stmt->execute([':emp' => $empresaId]);
    $operadores = $stmt->fetchAll();

    // Unidades de obra
    $stmt = $pdo->prepare("SELECT id, nombre, codigo FROM unidades_obra WHERE empresa_id = :emp AND activa = 1 ORDER BY nombre");
    $stmt->execute([':emp' => $empresaId]);
    $unidadesObra = $stmt->fetchAll();

    // Infraestructuras
    $stmt = $pdo->prepare("SELECT id, nombre, codigo_unico FROM infraestructuras WHERE empresa_id = :emp AND activa = 1 ORDER BY nombre");
    $stmt->execute([':emp' => $empresaId]);
    $infraestructuras = $stmt->fetchAll();

    // Todos los registros con GPS
    $stmt = $pdo->prepare(
        "SELECT r.id, r.infra_id, r.usuario_id, r.fecha, r.lat_real, r.lon_real,
                r.url_cloudinary, r.estado_incidencia, r.observaciones,
                r.tipo_foto, r.secuencia_comparativa, r.nombre_archivo, r.unidad_obra_id,
                i.nombre AS infra_nombre, i.codigo_unico, i.lat_teorica, i.lon_teorica,
                u.nombre AS usuario_nombre,
                uo.nombre AS unidad_obra_nombre
         FROM registros r
         INNER JOIN infraestructuras i ON r.infra_id = i.id
         INNER JOIN usuarios u ON r.usuario_id = u.id
         LEFT JOIN unidades_obra uo ON r.unidad_obra_id = uo.id
         WHERE i.empresa_id = :emp
         ORDER BY r.fecha DESC
         LIMIT 1000"
    );
    $stmt->execute([':emp' => $empresaId]);
    $registros = $stmt->fetchAll();
}

// Preparar datos de infraestructuras con coordenadas teóricas y conteo de fotos
$jsInfras = [];
if ($empresaId > 0) {
    $stmtInfra = $pdo->prepare(
        "SELECT i.id, i.nombre, i.codigo_unico, i.lat_teorica, i.lon_teorica, i.tipo,
                COUNT(r.id) AS num_fotos,
                (SELECT r2.estado_incidencia FROM registros r2 WHERE r2.infra_id = i.id ORDER BY r2.fecha DESC LIMIT 1) AS ultimo_estado
         FROM infraestructuras i
         LEFT JOIN registros r ON r.infra_id = i.id
         WHERE i.empresa_id = :emp AND i.activa = 1 AND i.lat_teorica IS NOT NULL AND i.lon_teorica IS NOT NULL
         GROUP BY i.id"
    );
    $stmtInfra->execute([':emp' => $empresaId]);
    foreach ($stmtInfra->fetchAll() as $inf) {
        $jsInfras[] = [
            'id' => (int) $inf['id'],
            'nombre' => $inf['nombre'],
            'codigo' => $inf['codigo_unico'],
            'lat' => (float) $inf['lat_teorica'],
            'lon' => (float) $inf['lon_teorica'],
            'tipo' => $inf['tipo'] ?? '',
            'num_fotos' => (int) $inf['num_fotos'],
            'ultimo_estado' => $inf['ultimo_estado'] ?? '',
        ];
    }
}

// Cargar capas KML guardadas
$jsCapasKml = [];
if ($empresaId > 0) {
    $stmtKml = $pdo->prepare(
        "SELECT id, nombre, contenido_kml, color FROM capas_kml WHERE empresa_id = :emp AND activa = 1 ORDER BY created_at DESC"
    );
    $stmtKml->execute([':emp' => $empresaId]);
    $jsCapasKml = $stmtKml->fetchAll();
}

// Preparar datos para JS
$jsRegistros = [];
foreach ($registros as $r) {
    $jsRegistros[] = [
        'id'         => (int)$r['id'],
        'infra_id'   => (int)$r['infra_id'],
        'usuario_id' => (int)$r['usuario_id'],
        'fecha'      => date('d/m/Y H:i', strtotime($r['fecha'])),
        'lat'        => (float)$r['lat_real'],
        'lon'        => (float)$r['lon_real'],
        'url'        => $r['url_cloudinary'],
        'estado'     => $r['estado_incidencia'],
        'tipo'       => $r['tipo_foto'] ?? 'aleatorio',
        'seq'        => (int)($r['secuencia_comparativa'] ?? 0),
        'archivo'    => $r['nombre_archivo'] ?? '',
        'obs'        => $r['observaciones'] ?? '',
        'infra'      => $r['infra_nombre'],
        'codigo'     => $r['codigo_unico'],
        'operador'   => $r['usuario_nombre'],
        'uo_id'      => (int)($r['unidad_obra_id'] ?? 0),
        'uo_nombre'  => $r['unidad_obra_nombre'] ?? '',
    ];
}

// Stats
$totalFotos = count($registros);
$totalComp = count(array_filter($registros, fn($r) => ($r['tipo_foto'] ?? '') === 'comparativo'));
$totalCriticas = count(array_filter($registros, fn($r) => $r['estado_incidencia'] === 'durante'));
$totalInfras = count(array_unique(array_column($registros, 'infra_id')));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FotoGPS.app - Mapa de Fotos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
    <style>
        body { background: #f4f6f9; margin: 0; }
        .brand-bar { background: linear-gradient(135deg, #1e3a5f, #2d6a9f); color: #fff; padding: 14px 24px; }
        .nav-admin { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 0 24px; }
        .nav-admin .nav-link { color: #6b7280; padding: 12px 16px; font-size: 0.9rem; border-bottom: 2px solid transparent; }
        .nav-admin .nav-link:hover, .nav-admin .nav-link.active { color: #1e3a5f; border-bottom-color: #1e3a5f; }

        .map-container { position: relative; }
        #map { height: calc(100vh - 190px); min-height: 500px; }

        .map-filter-bar {
            background: #fff; padding: 10px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
        }
        .map-filter-bar .form-select, .map-filter-bar .form-control { font-size: 0.8rem; height: 34px; }
        .map-filter-bar label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: #6b7280; letter-spacing: 0.5px; margin-bottom: 0; }
        .filter-group { display: flex; flex-direction: column; gap: 2px; }

        /* Responsive: mobile filter drawer */
        .filter-toggle-btn {
            display: none;
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #1e3a5f;
            color: #fff;
            border: none;
            box-shadow: 0 4px 16px rgba(0,0,0,0.3);
            font-size: 1.2rem;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            #map { height: calc(100vh - 130px); min-height: 300px; }
            .map-filter-bar {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 1100;
                background: #fff;
                padding: 20px;
                flex-direction: column;
                align-items: stretch;
                overflow-y: auto;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            .map-filter-bar.open {
                transform: translateX(0);
            }
            .map-filter-bar .filter-group { width: 100%; }
            .map-filter-bar .form-select, .map-filter-bar .form-control { width: 100% !important; }
            .filter-toggle-btn { display: flex; align-items: center; justify-content: center; }
            .map-stats-bar { flex-wrap: wrap; gap: 10px; padding: 6px 12px; }
            .stat-pill { font-size: 0.72rem; }
            .filter-close-btn {
                display: flex;
                align-self: flex-end;
                background: none;
                border: none;
                font-size: 1.4rem;
                color: #6b7280;
                cursor: pointer;
                margin-bottom: 10px;
            }
        }
        @media (min-width: 769px) {
            .filter-close-btn { display: none; }
        }

        .map-stats-bar {
            background: #fff; padding: 8px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex; gap: 20px; align-items: center; font-size: 0.8rem;
        }
        .stat-pill {
            display: flex; align-items: center; gap: 6px; color: #6b7280;
        }
        .stat-pill strong { color: #1e3a5f; font-size: 1rem; }

        .map-legend {
            background: #fff; border-radius: 10px; padding: 10px 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.12); font-size: 0.75rem;
        }
        .legend-row { display: flex; align-items: center; gap: 6px; margin-bottom: 3px; }
        .legend-dot { width: 12px; height: 12px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }

        /* Photo popup */
        .popup-photo { width: 280px; }
        .popup-photo img { width: 100%; border-radius: 6px; margin-bottom: 6px; }
        .popup-photo .popup-meta { font-size: 0.75rem; color: #666; line-height: 1.5; }
        .popup-photo .popup-meta strong { color: #333; }
        .popup-photo .popup-actions { margin-top: 6px; display: flex; gap: 4px; }
        .popup-photo .popup-actions a { font-size: 0.7rem; }
        .popup-badge { display: inline-block; font-size: 0.6rem; font-weight: 800; padding: 1px 6px; border-radius: 4px; text-transform: uppercase; }
        .popup-badge.antes { background: #dbeafe; color: #1e40af; }
        .popup-badge.durante { background: #fef9c3; color: #854d0e; }
        .popup-badge.despues { background: #dcfce7; color: #166534; }
        .popup-badge.aleatorio { background: #dbeafe; color: #1d4ed8; }
        .popup-badge.comparativo { background: #ede9fe; color: #6d28d9; }

        /* Drag toast */
        .drag-toast {
            position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
            background: #1e3a5f; color: #fff; padding: 10px 20px; border-radius: 10px;
            font-size: 0.85rem; z-index: 9999; box-shadow: 0 4px 16px rgba(0,0,0,0.3);
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateX(-50%) translateY(10px); } to { opacity: 1; transform: translateX(-50%) translateY(0); } }

        /* Cluster override */
        .marker-cluster-small { background-color: rgba(45,106,159,0.3); }
        .marker-cluster-small div { background-color: rgba(45,106,159,0.7); color: #fff; }
        .marker-cluster-medium { background-color: rgba(234,179,8,0.3); }
        .marker-cluster-medium div { background-color: rgba(234,179,8,0.7); color: #fff; }
        .marker-cluster-large { background-color: rgba(239,68,68,0.3); }
        .marker-cluster-large div { background-color: rgba(239,68,68,0.7); color: #fff; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <?php if ($empresaId > 0): ?>

    <!-- Mobile filter toggle -->
    <button class="filter-toggle-btn" id="btn-filter-toggle" onclick="toggleFilterDrawer()">
        <i class="bi bi-funnel-fill"></i>
    </button>

    <!-- Filter bar -->
    <div class="map-filter-bar" id="filter-drawer">
        <button class="filter-close-btn" onclick="toggleFilterDrawer()"><i class="bi bi-x-lg"></i> Cerrar filtros</button>
        <div class="filter-group">
            <label>Empresa</label>
            <form method="get" id="form-empresa">
                <select name="empresa_id" class="form-select" style="width:180px;" onchange="this.form.submit()">
                    <?php foreach ($empresas as $emp): ?>
                        <option value="<?= $emp['id'] ?>" <?= $empresaId === (int)$emp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($emp['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <div class="filter-group">
            <label>Operador</label>
            <select id="filter-operador" class="form-select" style="width:160px;">
                <option value="">Todos</option>
                <?php foreach ($operadores as $op): ?>
                    <option value="<?= $op['id'] ?>"><?= htmlspecialchars($op['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Infraestructura</label>
            <select id="filter-infra" class="form-select" style="width:180px;">
                <option value="">Todas</option>
                <?php foreach ($infraestructuras as $inf): ?>
                    <option value="<?= $inf['id'] ?>"><?= htmlspecialchars($inf['codigo_unico'] . ' - ' . $inf['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Unidad de Obra</label>
            <select id="filter-uo" class="form-select" style="width:160px;">
                <option value="">Todas</option>
                <?php foreach ($unidadesObra as $uo): ?>
                    <option value="<?= $uo['id'] ?>"><?= htmlspecialchars(($uo['codigo'] ? $uo['codigo'] . ' - ' : '') . $uo['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Tipo Foto</label>
            <select id="filter-tipo" class="form-select" style="width:130px;">
                <option value="">Todas</option>
                <option value="aleatorio">Aleatorias</option>
                <option value="comparativo">Comparativas</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Situación</label>
            <select id="filter-estado" class="form-select" style="width:110px;">
                <option value="">Todos</option>
                <option value="antes">Antes</option>
                <option value="durante">Durante</option>
                <option value="despues">Después</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Infraestructuras</label>
            <div class="d-flex gap-1">
                <button class="btn btn-sm btn-primary" id="btn-toggle-infra-markers" onclick="toggleInfraMarkers()" title="Mostrar/ocultar marcadores de infraestructuras">
                    <i class="bi bi-geo-alt-fill"></i> Infras
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="btn-toggle-drag-markers" onclick="toggleDragMarkers()" title="Mostrar marcadores arrastrables para reubicar">
                    <i class="bi bi-arrows-move"></i>
                </button>
            </div>
        </div>
        <div class="filter-group">
            <label>Capas KML</label>
            <div class="d-flex gap-1 align-items-center">
                <input type="file" id="kml-overlay-input" accept=".kml" class="form-control" style="font-size:0.8rem;height:34px;width:160px;">
                <button class="btn btn-sm btn-outline-success" id="btn-save-kml" onclick="saveKmlToDb()" style="display:none;" title="Guardar capa KML en la base de datos">
                    <i class="bi bi-cloud-upload"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" id="btn-remove-kml" onclick="removeKmlLayer()" style="display:none;" title="Quitar capa KML del mapa">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>
        <div class="filter-group" id="saved-kml-group" style="display:none;">
            <label>KML Guardados</label>
            <div id="saved-kml-list" class="d-flex gap-1 flex-wrap"></div>
        </div>
        <div class="filter-group">
            <label>Mapa Base</label>
            <select id="filter-base-layer" class="form-select" style="width:160px;" onchange="switchBaseLayer(this.value)">
                <option value="osm">OpenStreetMap</option>
                <option value="ortofoto">Ortofoto Andalucía 2020</option>
                <option value="topografico">Topográfico Andalucía</option>
            </select>
        </div>
        <div class="filter-group" style="margin-left:auto;">
            <label>&nbsp;</label>
            <button class="btn btn-sm btn-outline-secondary" onclick="resetFilters()">
                <i class="bi bi-x-lg"></i> Limpiar
            </button>
        </div>
    </div>

    <!-- Stats bar -->
    <div class="map-stats-bar" id="stats-bar">
        <div class="stat-pill"><strong id="stat-fotos"><?= $totalFotos ?></strong> fotos</div>
        <div class="stat-pill"><strong id="stat-infras"><?= $totalInfras ?></strong> infraestructuras</div>
        <div class="stat-pill" style="color:#7c3aed;"><strong id="stat-comp"><?= $totalComp ?></strong> comparativas</div>
        <div class="stat-pill" style="color:#f59e0b;"><strong id="stat-criticas"><?= $totalCriticas ?></strong> durante</div>
    </div>

    <!-- Map -->
    <div class="map-container">
        <div id="map"></div>
    </div>

    <?php else: ?>
        <div class="container-fluid py-4">
            <div class="text-center py-5">
                <i class="bi bi-map" style="font-size:3rem;color:#adb5bd;"></i>
                <h5 class="mt-3 text-muted">Selecciona una empresa</h5>
                <form method="get" class="d-inline-flex gap-2 mt-3">
                    <select name="empresa_id" class="form-select" style="width:280px;">
                        <option value="">-- Seleccionar empresa --</option>
                        <?php foreach ($empresas as $emp): ?>
                            <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-primary">Ir</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
    <script>
    <?php if ($empresaId > 0): ?>
    (function() {
        var allData = <?= json_encode($jsRegistros, JSON_UNESCAPED_UNICODE) ?>;
        var empresaId = <?= $empresaId ?>;
        var map = L.map('map');
        var clusterGroup = null;

        var stateColors = { 'antes': '#3b82f6', 'durante': '#f59e0b', 'despues': '#22c55e' };

        // Base layers
        var baseLayers = {
            osm: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors', maxZoom: 19,
            }),
            ortofoto: L.tileLayer.wms('https://www.juntadeandalucia.es/medioambiente/mapwms/REDIAM_Ortofoto_2020?', {
                layers: 'ortofoto_2020',
                format: 'image/png',
                transparent: false,
                attribution: '&copy; Junta de Andalucía - Ortofoto 2020',
                maxZoom: 20,
            }),
            topografico: L.tileLayer.wms('https://www.ideandalucia.es/wms/mta10r_2001-2013?', {
                layers: 'mta10r_2001-2013',
                format: 'image/png',
                transparent: false,
                attribution: '&copy; IDEAndalucía - MTA 1:10.000',
                maxZoom: 20,
            }),
        };
        var activeBaseLayer = baseLayers.osm;
        activeBaseLayer.addTo(map);

        window.switchBaseLayer = function(key) {
            if (activeBaseLayer) map.removeLayer(activeBaseLayer);
            activeBaseLayer = baseLayers[key] || baseLayers.osm;
            activeBaseLayer.addTo(map);
            activeBaseLayer.bringToBack();
        };

        // Legend
        var legend = L.control({ position: 'bottomright' });
        legend.onAdd = function() {
            var div = L.DomUtil.create('div', 'map-legend');
            div.innerHTML =
                '<strong style="font-size:0.8rem;">Leyenda</strong><br>' +
                '<div class="legend-row"><div class="legend-dot" style="background:#3b82f6;"></div> Infra: Antes</div>' +
                '<div class="legend-row"><div class="legend-dot" style="background:#f59e0b;"></div> Infra: Durante</div>' +
                '<div class="legend-row"><div class="legend-dot" style="background:#22c55e;"></div> Infra: Después</div>' +
                '<div class="legend-row"><div class="legend-dot" style="background:#9ca3af;"></div> Infra: Sin visitar</div>' +
                '<hr style="margin:4px 0;">' +
                '<div class="legend-row"><div class="legend-dot" style="background:#3b82f6;width:8px;height:8px;"></div> Foto aleatoria</div>' +
                '<div class="legend-row"><div class="legend-dot" style="background:#a855f7;width:8px;height:8px;"></div> Foto comparativa</div>' +
                '<div class="legend-row"><div class="legend-dot" style="background:#8b5cf6;"></div> Capa KML</div>';
            return div;
        };
        legend.addTo(map);

        function renderMarkers(data) {
            if (clusterGroup) { map.removeLayer(clusterGroup); }
            clusterGroup = L.markerClusterGroup({
                maxClusterRadius: 40,
                spiderfyOnMaxZoom: true,
                showCoverageOnHover: false,
            });

            var bounds = [];

            data.forEach(function(r) {
                if (!r.lat || !r.lon) return;
                bounds.push([r.lat, r.lon]);

                var isComp = r.tipo === 'comparativo';
                var color = stateColors[r.estado] || '#9ca3af';
                var size = isComp ? 16 : 12;

                var icon = L.divIcon({
                    className: 'photo-marker',
                    html: '<div style="width:' + size + 'px;height:' + size + 'px;border-radius:50%;' +
                          'background:' + color + ';border:2px solid #fff;' +
                          'box-shadow:0 1px 4px rgba(0,0,0,0.3);' +
                          (isComp ? 'outline:2px solid rgba(168,85,247,0.5);' : '') + '"></div>',
                    iconSize: [size, size],
                    iconAnchor: [size/2, size/2],
                });

                var marker = L.marker([r.lat, r.lon], { icon: icon });

                var downloadUrl = r.url.replace('/upload/', '/upload/fl_attachment/');
                var popupHtml =
                    '<div class="popup-photo">' +
                    '<img src="' + r.url + '" alt="" loading="lazy">' +
                    '<div class="popup-meta">' +
                    '<strong>' + r.infra + '</strong> <code style="font-size:0.7rem;color:#2d6a9f;">' + r.codigo + '</code><br>' +
                    '<span class="popup-badge ' + r.estado + '">' + r.estado.toUpperCase() + '</span> ' +
                    '<span class="popup-badge ' + r.tipo + '">' + (isComp ? 'COMP W' + r.seq : 'ALEA') + '</span><br>' +
                    '<i class="bi bi-person"></i> ' + r.operador + '<br>' +
                    '<i class="bi bi-calendar3"></i> ' + r.fecha + '<br>' +
                    '<i class="bi bi-geo-alt"></i> ' + r.lat.toFixed(7) + ', ' + r.lon.toFixed(7) +
                    (r.uo_nombre ? '<br><i class="bi bi-tools"></i> ' + r.uo_nombre : '') +
                    (r.obs ? '<br><em style="color:#999;">' + r.obs.substring(0, 80) + '</em>' : '') +
                    '</div>' +
                    '<div class="popup-actions">' +
                    '<a href="' + downloadUrl + '" class="btn btn-sm btn-outline-success" style="font-size:0.7rem;"><i class="bi bi-download"></i> Descargar</a> ' +
                    '<a href="index.php?empresa_id=' + empresaId + '&infra_id=' + r.infra_id + '" class="btn btn-sm btn-outline-primary" style="font-size:0.7rem;"><i class="bi bi-clock-history"></i> Timeline</a> ' +
                    '<a href="generar_pdf.php?infra_id=' + r.infra_id + '" target="_blank" class="btn btn-sm btn-outline-secondary" style="font-size:0.7rem;"><i class="bi bi-file-pdf"></i> PDF</a>' +
                    '</div></div>';

                marker.bindPopup(popupHtml, { maxWidth: 300 });
                clusterGroup.addLayer(marker);
            });

            map.addLayer(clusterGroup);

            if (bounds.length > 0) {
                map.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 });
            } else {
                map.setView([40.416775, -3.703790], 6);
            }

            // Update stats
            var infras = {};
            data.forEach(function(r) { infras[r.infra_id] = true; });
            document.getElementById('stat-fotos').textContent = data.length;
            document.getElementById('stat-infras').textContent = Object.keys(infras).length;
            document.getElementById('stat-comp').textContent = data.filter(function(r) { return r.tipo === 'comparativo'; }).length;
            document.getElementById('stat-criticas').textContent = data.filter(function(r) { return r.estado === 'durante'; }).length;
        }

        function applyFilters() {
            var opVal = document.getElementById('filter-operador').value;
            var infraVal = document.getElementById('filter-infra').value;
            var uoVal = document.getElementById('filter-uo').value;
            var tipoVal = document.getElementById('filter-tipo').value;
            var estadoVal = document.getElementById('filter-estado').value;

            var filtered = allData.filter(function(r) {
                if (opVal && r.usuario_id !== parseInt(opVal)) return false;
                if (infraVal && r.infra_id !== parseInt(infraVal)) return false;
                if (uoVal && r.uo_id !== parseInt(uoVal)) return false;
                if (tipoVal && r.tipo !== tipoVal) return false;
                if (estadoVal && r.estado !== estadoVal) return false;
                return true;
            });

            renderMarkers(filtered);
        }

        window.resetFilters = function() {
            document.getElementById('filter-operador').value = '';
            document.getElementById('filter-infra').value = '';
            document.getElementById('filter-uo').value = '';
            document.getElementById('filter-tipo').value = '';
            document.getElementById('filter-estado').value = '';
            renderMarkers(allData);
        };

        // Bind filter changes
        ['filter-operador', 'filter-infra', 'filter-uo', 'filter-tipo', 'filter-estado'].forEach(function(id) {
            document.getElementById(id).addEventListener('change', applyFilters);
        });

        // Initial render
        renderMarkers(allData);

        // ---------------------------------------------------------------
        // Infraestructura Circle Markers (como en el operador)
        // ---------------------------------------------------------------
        var infraData = <?= json_encode($jsInfras, JSON_UNESCAPED_UNICODE) ?>;
        var csrfToken = '<?= csrfToken() ?>';
        var infraCircleGroup = null;
        var infraCirclesVisible = true;
        var infraDragGroup = null;
        var infraDragVisible = false;

        var infraStateColors = { 'antes': '#3b82f6', 'durante': '#f59e0b', 'despues': '#22c55e' };

        function renderInfraCircles() {
            if (infraCircleGroup) map.removeLayer(infraCircleGroup);
            infraCircleGroup = L.layerGroup().addTo(map);

            infraData.forEach(function(inf) {
                if (!inf.lat || !inf.lon) return;

                var hasPhotos = inf.num_fotos > 0;
                var icon;

                if (hasPhotos) {
                    var color = infraStateColors[inf.ultimo_estado] || '#9ca3af';
                    icon = L.divIcon({
                        className: 'infra-circle-marker',
                        html: '<div style="width:32px;height:32px;border-radius:50%;background:' + color + ';' +
                              'border:3px solid rgba(255,255,255,0.9);box-shadow:0 2px 8px rgba(0,0,0,0.4);' +
                              'display:flex;align-items:center;justify-content:center;' +
                              'font-size:11px;font-weight:800;color:#fff;">' + inf.num_fotos + '</div>',
                        iconSize: [32, 32],
                        iconAnchor: [16, 16],
                    });
                } else {
                    icon = L.divIcon({
                        className: 'infra-circle-marker-unvisited',
                        html: '<div style="width:28px;height:28px;border-radius:50%;background:#9ca3af;' +
                              'border:3px solid rgba(255,255,255,0.9);box-shadow:0 2px 8px rgba(0,0,0,0.3);' +
                              'display:flex;align-items:center;justify-content:center;font-size:13px;color:#fff;">' +
                              '<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">' +
                              '<path d="M8 0a5 5 0 0 0-5 5c0 4.5 5 11 5 11s5-6.5 5-11a5 5 0 0 0-5-5zm0 7.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z"/>' +
                              '</svg></div>',
                        iconSize: [28, 28],
                        iconAnchor: [14, 14],
                    });
                }

                var popupHtml = '<div style="min-width:200px;">' +
                    '<strong>' + inf.nombre + '</strong><br>' +
                    '<code style="color:#2d6a9f;font-size:0.75rem;">' + inf.codigo + '</code>' +
                    (inf.tipo ? '<br><span class="badge" style="background:#e0e7ff;color:#4338ca;font-size:0.6rem;">' + inf.tipo + '</span>' : '') +
                    '<br><small class="text-muted"><i class="bi bi-geo-alt"></i> ' + inf.lat.toFixed(7) + ', ' + inf.lon.toFixed(7) + '</small>' +
                    '<br><small><i class="bi bi-camera"></i> ' + inf.num_fotos + ' foto' + (inf.num_fotos !== 1 ? 's' : '') + '</small>' +
                    '<div style="margin-top:6px;">' +
                    '<a href="index.php?empresa_id=' + empresaId + '&infra_id=' + inf.id + '" class="btn btn-sm btn-outline-primary" style="font-size:0.7rem;"><i class="bi bi-clock-history"></i> Timeline</a> ' +
                    '<a href="generar_pdf.php?infra_id=' + inf.id + '" target="_blank" class="btn btn-sm btn-outline-secondary" style="font-size:0.7rem;"><i class="bi bi-file-pdf"></i> PDF</a>' +
                    '</div></div>';

                var marker = L.marker([inf.lat, inf.lon], { icon: icon, zIndexOffset: hasPhotos ? 100 : -50 });
                marker.bindPopup(popupHtml, { maxWidth: 280 });
                marker.bindTooltip(inf.nombre, { direction: 'top', offset: [0, -16] });
                marker.addTo(infraCircleGroup);
            });
        }

        // Show infra circles by default
        renderInfraCircles();

        // If no photos but there are infrastructures, fit map to infra bounds
        if (allData.length === 0 && infraData.length > 0) {
            var infraBounds = [];
            infraData.forEach(function(inf) {
                if (inf.lat && inf.lon) infraBounds.push([inf.lat, inf.lon]);
            });
            if (infraBounds.length > 0) {
                map.fitBounds(infraBounds, { padding: [30, 30], maxZoom: 16 });
            }
        }

        window.toggleInfraMarkers = function() {
            var btn = document.getElementById('btn-toggle-infra-markers');
            if (infraCirclesVisible) {
                if (infraCircleGroup) map.removeLayer(infraCircleGroup);
                infraCirclesVisible = false;
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-outline-primary');
            } else {
                renderInfraCircles();
                infraCirclesVisible = true;
                btn.classList.remove('btn-outline-primary');
                btn.classList.add('btn-primary');
            }
        };

        // ---------------------------------------------------------------
        // Drag markers para reubicar infraestructuras
        // ---------------------------------------------------------------
        window.toggleDragMarkers = function() {
            var btn = document.getElementById('btn-toggle-drag-markers');
            if (infraDragVisible) {
                if (infraDragGroup) map.removeLayer(infraDragGroup);
                infraDragVisible = false;
                btn.classList.remove('btn-secondary');
                btn.classList.add('btn-outline-secondary');
            } else {
                renderDragMarkers();
                infraDragVisible = true;
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-secondary');
            }
        };

        function renderDragMarkers() {
            if (infraDragGroup) map.removeLayer(infraDragGroup);
            infraDragGroup = L.layerGroup().addTo(map);

            infraData.forEach(function(inf) {
                if (!inf.lat || !inf.lon) return;

                var icon = L.divIcon({
                    className: 'infra-drag-marker',
                    html: '<div style="width:20px;height:20px;border-radius:4px;background:#1e3a5f;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;cursor:grab;">' +
                          '<i class="bi bi-arrows-move" style="color:#fff;font-size:10px;"></i></div>',
                    iconSize: [20, 20],
                    iconAnchor: [10, 10],
                });

                var marker = L.marker([inf.lat, inf.lon], {
                    icon: icon,
                    draggable: true,
                    title: inf.nombre + ' (' + inf.codigo + ') - Arrastra para reubicar',
                });

                marker.bindPopup(
                    '<div style="min-width:180px;">' +
                    '<strong>' + inf.nombre + '</strong><br>' +
                    '<code style="color:#2d6a9f;font-size:0.75rem;">' + inf.codigo + '</code>' +
                    '<br><small class="text-muted"><i class="bi bi-geo-alt"></i> ' + inf.lat.toFixed(7) + ', ' + inf.lon.toFixed(7) + '</small>' +
                    '<br><small style="color:#059669;"><i class="bi bi-arrows-move"></i> Arrastra para reubicar</small>' +
                    '</div>'
                );

                marker.on('dragend', function(e) {
                    var newLatLng = e.target.getLatLng();
                    updateInfraCoords(inf.id, inf.nombre, newLatLng.lat, newLatLng.lng, e.target);
                });

                marker.addTo(infraDragGroup);
            });
        }

        async function updateInfraCoords(infraId, nombre, lat, lon, marker) {
            var formData = new FormData();
            formData.append('infra_id', infraId);
            formData.append('lat', lat.toFixed(7));
            formData.append('lon', lon.toFixed(7));
            formData.append('empresa_id', empresaId);
            formData.append('csrf_token', csrfToken);

            try {
                var resp = await fetch('api/actualizar_coordenadas.php', {
                    method: 'POST',
                    body: formData,
                });
                var data = await resp.json();

                if (data.ok) {
                    infraData.forEach(function(inf) {
                        if (inf.id === infraId) { inf.lat = lat; inf.lon = lon; }
                    });

                    marker.setPopupContent(
                        '<div style="min-width:180px;">' +
                        '<strong>' + nombre + '</strong><br>' +
                        '<small class="text-muted"><i class="bi bi-geo-alt"></i> ' + lat.toFixed(7) + ', ' + lon.toFixed(7) + '</small>' +
                        '<br><span style="color:#059669;font-size:0.75rem;"><i class="bi bi-check-circle"></i> Ubicación guardada</span>' +
                        '</div>'
                    );

                    showDragToast(nombre + ' reubicada correctamente', 'success');

                    // Refresh circle markers to update position
                    if (infraCirclesVisible) renderInfraCircles();
                } else {
                    showDragToast('Error: ' + (data.error || 'No se pudo guardar'), 'error');
                }
            } catch (err) {
                showDragToast('Error de conexión al guardar', 'error');
            }
        }

        function showDragToast(message, type) {
            var existing = document.querySelector('.drag-toast');
            if (existing) existing.remove();

            var toast = document.createElement('div');
            toast.className = 'drag-toast';
            toast.style.background = type === 'success' ? '#059669' : '#dc2626';
            toast.innerHTML = '<i class="bi bi-' + (type === 'success' ? 'check-circle' : 'x-circle') + ' me-1"></i>' + message;
            document.body.appendChild(toast);

            setTimeout(function() { toast.remove(); }, 3000);
        }

        // ---------------------------------------------------------------
        // KML: Renderizado, carga desde archivo, guardado en BD
        // ---------------------------------------------------------------
        var kmlLayerGroups = {};   // { id_or_temp: L.layerGroup }
        var pendingKmlText = null; // KML text pending to be saved
        var pendingKmlName = '';

        document.getElementById('kml-overlay-input').addEventListener('change', function(e) {
            var file = e.target.files[0];
            if (!file) return;

            pendingKmlName = file.name.replace(/\.kml$/i, '');
            var reader = new FileReader();
            reader.onload = function(ev) {
                pendingKmlText = ev.target.result;
                renderKmlOnMap(pendingKmlText, 'temp', '#8b5cf6');
                document.getElementById('btn-save-kml').style.display = 'inline-block';
                document.getElementById('btn-remove-kml').style.display = 'inline-block';
            };
            reader.readAsText(file);
        });

        function renderKmlOnMap(kmlText, layerId, color) {
            // Remove existing layer with same id
            if (kmlLayerGroups[layerId]) {
                map.removeLayer(kmlLayerGroups[layerId]);
            }
            var group = L.layerGroup().addTo(map);
            kmlLayerGroups[layerId] = group;

            var parser = new DOMParser();
            var xmlDoc = parser.parseFromString(kmlText, 'text/xml');
            var placemarks = xmlDoc.querySelectorAll('Placemark');
            var bounds = [];
            var totalElements = 0;

            placemarks.forEach(function(pm) {
                var nameEl = pm.querySelector('name');
                var nombre = nameEl ? nameEl.textContent.trim() : '';
                var descEl = pm.querySelector('description');
                var desc = descEl ? descEl.textContent.trim() : '';

                var popupContent = '<div style="max-width:250px;">';
                if (nombre) popupContent += '<strong style="color:' + color + ';">' + nombre + '</strong><br>';
                if (desc) popupContent += '<small>' + desc.substring(0, 150) + '</small><br>';
                popupContent += '<span class="badge" style="background:' + color + ';font-size:0.6rem;">KML</span>';
                popupContent += '</div>';

                // Points
                var pointEl = pm.querySelector('Point coordinates');
                if (pointEl) {
                    var coords = pointEl.textContent.trim().split(',');
                    if (coords.length >= 2) {
                        var lat = parseFloat(coords[1]);
                        var lon = parseFloat(coords[0]);
                        if (!isNaN(lat) && !isNaN(lon)) {
                            var icon = L.divIcon({
                                className: 'kml-marker',
                                html: '<div style="width:14px;height:14px;border-radius:50%;background:' + color + ';border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,0.3);"></div>',
                                iconSize: [14, 14],
                                iconAnchor: [7, 7],
                            });
                            L.marker([lat, lon], { icon: icon })
                                .bindPopup(popupContent)
                                .addTo(group);
                            bounds.push([lat, lon]);
                            totalElements++;
                        }
                    }
                }

                // LineStrings
                var lineEl = pm.querySelector('LineString coordinates');
                if (lineEl) {
                    var lineCoords = parseKmlCoordinates(lineEl.textContent);
                    if (lineCoords.length > 0) {
                        L.polyline(lineCoords, { color: color, weight: 3, opacity: 0.8 })
                            .bindPopup(popupContent)
                            .addTo(group);
                        lineCoords.forEach(function(c) { bounds.push(c); });
                        totalElements++;
                    }
                }

                // Polygons
                var polyEl = pm.querySelector('Polygon outerBoundaryIs LinearRing coordinates');
                if (polyEl) {
                    var polyCoords = parseKmlCoordinates(polyEl.textContent);
                    if (polyCoords.length > 0) {
                        L.polygon(polyCoords, { color: color, fillColor: color, fillOpacity: 0.15, weight: 2 })
                            .bindPopup(popupContent)
                            .addTo(group);
                        polyCoords.forEach(function(c) { bounds.push(c); });
                        totalElements++;
                    }
                }
            });

            if (bounds.length > 0 && layerId === 'temp') {
                map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
            }

            updateKmlStats();
            return totalElements;
        }

        function parseKmlCoordinates(text) {
            var coords = [];
            var tuples = text.trim().split(/\s+/);
            tuples.forEach(function(t) {
                var parts = t.split(',');
                if (parts.length >= 2) {
                    var lon = parseFloat(parts[0]);
                    var lat = parseFloat(parts[1]);
                    if (!isNaN(lat) && !isNaN(lon)) {
                        coords.push([lat, lon]);
                    }
                }
            });
            return coords;
        }

        function updateKmlStats() {
            var count = Object.keys(kmlLayerGroups).length;
            var statKml = document.getElementById('stat-kml');
            if (count > 0) {
                if (!statKml) {
                    var statsBar = document.getElementById('stats-bar');
                    var pill = document.createElement('div');
                    pill.className = 'stat-pill';
                    pill.id = 'stat-kml';
                    pill.style.color = '#8b5cf6';
                    statsBar.appendChild(pill);
                    statKml = pill;
                }
                statKml.innerHTML = '<strong>' + count + '</strong> capa' + (count !== 1 ? 's' : '') + ' KML';
            } else if (statKml) {
                statKml.remove();
            }
        }

        // Save pending KML to database
        window.saveKmlToDb = async function() {
            if (!pendingKmlText) return;

            var nombre = prompt('Nombre para la capa KML:', pendingKmlName);
            if (!nombre) return;

            var formData = new FormData();
            formData.append('empresa_id', empresaId);
            formData.append('nombre', nombre);
            formData.append('contenido_kml', pendingKmlText);
            formData.append('color', '#8b5cf6');
            formData.append('csrf_token', csrfToken);

            try {
                var resp = await fetch('api/capas_kml.php', { method: 'POST', body: formData });
                var data = await resp.json();

                if (data.ok) {
                    // Replace temp layer with saved layer id
                    if (kmlLayerGroups['temp']) {
                        map.removeLayer(kmlLayerGroups['temp']);
                        delete kmlLayerGroups['temp'];
                    }
                    renderKmlOnMap(pendingKmlText, 'kml-' + data.id, data.color || '#8b5cf6');

                    pendingKmlText = null;
                    pendingKmlName = '';
                    document.getElementById('kml-overlay-input').value = '';
                    document.getElementById('btn-save-kml').style.display = 'none';
                    document.getElementById('btn-remove-kml').style.display = 'none';

                    showDragToast('Capa "' + nombre + '" guardada correctamente', 'success');
                    renderSavedKmlList();
                } else {
                    showDragToast('Error: ' + (data.error || 'No se pudo guardar'), 'error');
                }
            } catch (err) {
                showDragToast('Error de conexión al guardar KML', 'error');
            }
        };

        window.removeKmlLayer = function() {
            if (kmlLayerGroups['temp']) {
                map.removeLayer(kmlLayerGroups['temp']);
                delete kmlLayerGroups['temp'];
            }
            pendingKmlText = null;
            pendingKmlName = '';
            document.getElementById('kml-overlay-input').value = '';
            document.getElementById('btn-save-kml').style.display = 'none';
            document.getElementById('btn-remove-kml').style.display = 'none';
            updateKmlStats();
        };

        window.toggleSavedKml = function(capaId, kmlText, color) {
            var key = 'kml-' + capaId;
            if (kmlLayerGroups[key]) {
                map.removeLayer(kmlLayerGroups[key]);
                delete kmlLayerGroups[key];
                var btn = document.getElementById('btn-kml-' + capaId);
                if (btn) { btn.classList.remove('btn-primary'); btn.classList.add('btn-outline-primary'); }
            } else {
                renderKmlOnMap(kmlText, key, color);
                var btn = document.getElementById('btn-kml-' + capaId);
                if (btn) { btn.classList.remove('btn-outline-primary'); btn.classList.add('btn-primary'); }
            }
            updateKmlStats();
        };

        window.deleteSavedKml = async function(capaId, nombre) {
            if (!confirm('¿Eliminar la capa "' + nombre + '"?')) return;

            var formData = new FormData();
            formData.append('empresa_id', empresaId);
            formData.append('action', 'eliminar');
            formData.append('capa_id', capaId);
            formData.append('csrf_token', csrfToken);

            try {
                var resp = await fetch('api/capas_kml.php', { method: 'POST', body: formData });
                var data = await resp.json();

                if (data.ok) {
                    var key = 'kml-' + capaId;
                    if (kmlLayerGroups[key]) {
                        map.removeLayer(kmlLayerGroups[key]);
                        delete kmlLayerGroups[key];
                    }
                    showDragToast('Capa "' + nombre + '" eliminada', 'success');
                    renderSavedKmlList();
                    updateKmlStats();
                } else {
                    showDragToast('Error: ' + (data.error || 'No se pudo eliminar'), 'error');
                }
            } catch (err) {
                showDragToast('Error de conexión', 'error');
            }
        };

        // Load saved KML layers from DB
        var savedCapasKml = <?= json_encode($jsCapasKml, JSON_UNESCAPED_UNICODE) ?>;

        function renderSavedKmlList() {
            fetch('api/capas_kml.php?empresa_id=' + empresaId)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.ok) {
                        savedCapasKml = data.capas;
                        buildSavedKmlUI();
                    }
                });
        }

        function buildSavedKmlUI() {
            var container = document.getElementById('saved-kml-list');
            var group = document.getElementById('saved-kml-group');

            if (savedCapasKml.length === 0) {
                group.style.display = 'none';
                container.innerHTML = '';
                return;
            }

            group.style.display = '';
            container.innerHTML = '';

            savedCapasKml.forEach(function(capa) {
                var isActive = !!kmlLayerGroups['kml-' + capa.id];
                var wrapper = document.createElement('div');
                wrapper.className = 'd-flex gap-1';
                wrapper.innerHTML =
                    '<button id="btn-kml-' + capa.id + '" class="btn btn-sm ' + (isActive ? 'btn-primary' : 'btn-outline-primary') + '" ' +
                    'style="font-size:0.75rem;white-space:nowrap;" title="Mostrar/ocultar capa">' +
                    '<i class="bi bi-layers"></i> ' + capa.nombre +
                    '</button>' +
                    '<button class="btn btn-sm btn-outline-danger" style="font-size:0.7rem;" title="Eliminar capa">' +
                    '<i class="bi bi-trash"></i></button>';

                var toggleBtn = wrapper.querySelector('#btn-kml-' + capa.id);
                var deleteBtn = wrapper.querySelectorAll('button')[1];

                (function(c) {
                    toggleBtn.addEventListener('click', function() {
                        toggleSavedKml(c.id, c.contenido_kml, c.color);
                    });
                    deleteBtn.addEventListener('click', function() {
                        deleteSavedKml(c.id, c.nombre);
                    });
                })(capa);

                container.appendChild(wrapper);
            });
        }

        // Initialize: render saved KML list and auto-load active layers
        buildSavedKmlUI();
        savedCapasKml.forEach(function(capa) {
            renderKmlOnMap(capa.contenido_kml, 'kml-' + capa.id, capa.color);
        });
        updateKmlStats();
    })();
    <?php endif; ?>

    // Filter drawer toggle (mobile)
    window.toggleFilterDrawer = function() {
        var drawer = document.getElementById('filter-drawer');
        if (drawer) drawer.classList.toggle('open');
    };
    </script>
</body>
</html>
