<?php
/**
 * INFOCAMPO SaaS - Mapa de Infraestructuras (Admin)
 *
 * Visualización geográfica de todas las infraestructuras
 * de la empresa usando Leaflet + OpenStreetMap.
 * Muestra el estado de incidencia de la última inspección.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$pdo = getDB();
$currentPage = 'mapa';

$empresaId = isset($_GET['empresa_id']) ? (int) $_GET['empresa_id'] : ($_SESSION['empresa_id'] ?? 0);

$empresas = $pdo->query(
    "SELECT id, nombre FROM empresas WHERE activa = 1 ORDER BY nombre"
)->fetchAll();

$infraestructuras = [];
$empresaNombre = '';
if ($empresaId > 0) {
    $stmt = $pdo->prepare("SELECT nombre FROM empresas WHERE id = :id");
    $stmt->execute([':id' => $empresaId]);
    $row = $stmt->fetch();
    if ($row) $empresaNombre = $row['nombre'];

    $stmt = $pdo->prepare(
        "SELECT i.*,
                (SELECT COUNT(*) FROM registros r WHERE r.infra_id = i.id) AS num_registros,
                (SELECT r2.estado_incidencia FROM registros r2 WHERE r2.infra_id = i.id ORDER BY r2.fecha DESC LIMIT 1) AS ultimo_estado,
                (SELECT r3.fecha FROM registros r3 WHERE r3.infra_id = i.id ORDER BY r3.fecha DESC LIMIT 1) AS ultima_fecha
         FROM infraestructuras i
         WHERE i.empresa_id = :emp_id AND i.activa = 1
         ORDER BY i.nombre ASC"
    );
    $stmt->execute([':emp_id' => $empresaId]);
    $infraestructuras = $stmt->fetchAll();
}

// Preparar datos para JS
$mapData = [];
foreach ($infraestructuras as $inf) {
    $mapData[] = [
        'id' => (int) $inf['id'],
        'nombre' => $inf['nombre'],
        'codigo' => $inf['codigo_unico'],
        'lat' => (float) $inf['lat_teorica'],
        'lon' => (float) $inf['lon_teorica'],
        'tipo' => $inf['tipo'] ?? '',
        'registros' => (int) $inf['num_registros'],
        'estado' => $inf['ultimo_estado'] ?? 'sin_datos',
        'fecha' => $inf['ultima_fecha'] ? date('d/m/Y H:i', strtotime($inf['ultima_fecha'])) : 'Sin inspecciones',
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INFOCAMPO - Mapa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        body { background: #f4f6f9; }
        .brand-bar { background: linear-gradient(135deg, #1e3a5f, #2d6a9f); color: #fff; padding: 14px 24px; }
        .card { border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border-radius: 12px; }
        .nav-admin { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 0 24px; }
        .nav-admin .nav-link { color: #6b7280; padding: 12px 16px; font-size: 0.9rem; border-bottom: 2px solid transparent; }
        .nav-admin .nav-link:hover, .nav-admin .nav-link.active { color: #1e3a5f; border-bottom-color: #1e3a5f; }
        #map { height: calc(100vh - 200px); min-height: 500px; border-radius: 12px; }
        .map-legend {
            background: #fff; border-radius: 10px; padding: 12px 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1); font-size: 0.8rem;
        }
        .legend-item { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
        .legend-dot { width: 14px; height: 14px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.3); }
        .map-stats { display: flex; gap: 16px; flex-wrap: wrap; }
        .map-stat { background: #fff; border-radius: 10px; padding: 12px 18px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
        .map-stat .num { font-size: 1.3rem; font-weight: 800; color: #1e3a5f; }
        .map-stat .label { font-size: 0.7rem; color: #6b7280; text-transform: uppercase; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h4 class="mb-0"><i class="bi bi-map me-2"></i>Mapa de Infraestructuras</h4>
            <div class="d-flex gap-2 align-items-center">
                <form method="get" class="d-flex gap-2 align-items-center">
                    <select name="empresa_id" class="form-select form-select-sm" style="width:220px;" onchange="this.form.submit()">
                        <option value="">-- Empresa --</option>
                        <?php foreach ($empresas as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= $empresaId === (int)$emp['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($emp['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        </div>

        <?php if ($empresaId > 0 && !empty($infraestructuras)): ?>

        <!-- Stats rápidos -->
        <div class="map-stats mb-3">
            <?php
            $conRegistros = array_filter($infraestructuras, fn($i) => $i['num_registros'] > 0);
            $criticas = array_filter($infraestructuras, fn($i) => $i['ultimo_estado'] === 'critico');
            ?>
            <div class="map-stat"><div class="num"><?= count($infraestructuras) ?></div><div class="label">Infraestructuras</div></div>
            <div class="map-stat"><div class="num"><?= count($conRegistros) ?></div><div class="label">Con inspecciones</div></div>
            <div class="map-stat"><div class="num"><?= count($criticas) ?></div><div class="label">Estado crítico</div></div>
        </div>

        <!-- Mapa -->
        <div class="card">
            <div class="card-body p-0" style="border-radius:12px;overflow:hidden;">
                <div id="map"></div>
            </div>
        </div>

        <?php elseif ($empresaId > 0): ?>
            <div class="text-center py-5">
                <i class="bi bi-map" style="font-size:3rem;color:#adb5bd;"></i>
                <h5 class="mt-3 text-muted">Sin infraestructuras</h5>
                <p class="text-muted">No hay infraestructuras activas para mostrar en el mapa.</p>
            </div>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="bi bi-map" style="font-size:3rem;color:#adb5bd;"></i>
                <h5 class="mt-3 text-muted">Selecciona una empresa</h5>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
    <?php if ($empresaId > 0 && !empty($infraestructuras)): ?>
    (function() {
        var data = <?= json_encode($mapData, JSON_UNESCAPED_UNICODE) ?>;
        var empresaId = <?= $empresaId ?>;

        // Colores por estado
        var stateColors = {
            'bajo': '#22c55e',
            'medio': '#eab308',
            'critico': '#ef4444',
            'sin_datos': '#9ca3af'
        };

        // Centrar mapa
        var bounds = [];
        data.forEach(function(d) {
            if (d.lat !== 0 && d.lon !== 0) bounds.push([d.lat, d.lon]);
        });

        var map = L.map('map');

        if (bounds.length > 0) {
            map.fitBounds(bounds, { padding: [40, 40], maxZoom: 15 });
        } else {
            map.setView([40.416775, -3.703790], 6); // España centro
        }

        // Capa base
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19,
        }).addTo(map);

        // Marcadores
        data.forEach(function(d) {
            if (d.lat === 0 && d.lon === 0) return;

            var color = stateColors[d.estado] || stateColors.sin_datos;
            var icon = L.divIcon({
                className: 'custom-marker',
                html: '<div style="width:20px;height:20px;border-radius:50%;background:' + color +
                      ';border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.3);"></div>',
                iconSize: [20, 20],
                iconAnchor: [10, 10],
            });

            var marker = L.marker([d.lat, d.lon], { icon: icon }).addTo(map);

            var estadoLabel = {
                'bajo': '<span style="color:#22c55e;font-weight:700;">BAJO</span>',
                'medio': '<span style="color:#eab308;font-weight:700;">MEDIO</span>',
                'critico': '<span style="color:#ef4444;font-weight:700;">CRITICO</span>',
                'sin_datos': '<span style="color:#9ca3af;">Sin datos</span>'
            };

            marker.bindPopup(
                '<div style="min-width:200px;">' +
                '<strong>' + d.nombre + '</strong><br>' +
                '<code style="font-size:0.75rem;color:#2d6a9f;">' + d.codigo + '</code>' +
                (d.tipo ? ' <span style="font-size:0.7rem;background:#e0e7ff;color:#4338ca;padding:1px 6px;border-radius:4px;">' + d.tipo + '</span>' : '') +
                '<hr style="margin:6px 0;">' +
                '<div style="font-size:0.8rem;">' +
                'Estado: ' + (estadoLabel[d.estado] || d.estado) + '<br>' +
                'Inspecciones: <strong>' + d.registros + '</strong><br>' +
                'Última: ' + d.fecha +
                '</div>' +
                '<div style="margin-top:8px;">' +
                '<a href="index.php?empresa_id=' + empresaId + '&infra_id=' + d.id + '" class="btn btn-sm btn-primary" style="font-size:0.75rem;">Ver Timeline</a>' +
                '</div></div>'
            );
        });

        // Leyenda
        var legend = L.control({ position: 'bottomright' });
        legend.onAdd = function() {
            var div = L.DomUtil.create('div', 'map-legend');
            div.innerHTML =
                '<strong style="font-size:0.85rem;">Estado</strong><br>' +
                '<div class="legend-item"><div class="legend-dot" style="background:#22c55e;"></div> Bajo</div>' +
                '<div class="legend-item"><div class="legend-dot" style="background:#eab308;"></div> Medio</div>' +
                '<div class="legend-item"><div class="legend-dot" style="background:#ef4444;"></div> Crítico</div>' +
                '<div class="legend-item"><div class="legend-dot" style="background:#9ca3af;"></div> Sin datos</div>';
            return div;
        };
        legend.addTo(map);
    })();
    <?php endif; ?>
    </script>
</body>
</html>
