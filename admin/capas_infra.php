<?php
/**
 * INFOCAMPO SaaS - Capas de Infraestructuras (Admin)
 *
 * Permite subir Shapefiles (ZIP), KML, KMZ o GeoJSON con datos de
 * infraestructuras. El admin configura qué campo de la capa se
 * vincula con qué campo de la tabla infraestructuras.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'superadmin']);

$pdo = getDB();
$currentPage = 'capas_infra';
$empresaId = getEmpresaIdSeguro();

$isSuperadmin = ($_SESSION['user_role'] ?? '') === 'superadmin';
if ($isSuperadmin) {
    $empresas = $pdo->query(
        "SELECT id, nombre FROM empresas WHERE activa = 1 AND id != 9999 ORDER BY nombre"
    )->fetchAll();
} else {
    $empresas = [];
}

$capas = [];
if ($empresaId > 0) {
    $stmt = $pdo->prepare(
        "SELECT * FROM capas_infraestructuras WHERE empresa_id = :emp AND activa = 1 ORDER BY created_at DESC"
    );
    $stmt->execute([':emp' => $empresaId]);
    $capas = $stmt->fetchAll();
}

$csrfToken = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FotoGPS.app - Capas de Infraestructuras</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="/admin/css/admin.css" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
    #preview-map { height: 350px; border-radius: 8px; border: 1px solid #dee2e6; margin-bottom: 16px; }
    .step { display: none; }
    .step.active { display: block; }
    .attr-list { max-height: 200px; overflow-y: auto; }
    .attr-badge { cursor: pointer; }
    .attr-badge:hover { opacity: 0.8; }
    .attr-badge.selected { outline: 3px solid #0d6efd; }
    .capa-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; margin-bottom: 10px; }
</style>
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h4 class="mb-0"><i class="bi bi-layers me-2"></i>Capas de Infraestructuras</h4>
            <div class="d-flex gap-2 align-items-center">
                <?php if ($isSuperadmin): ?>
                <form method="get" class="d-flex gap-2 align-items-center">
                    <label class="fw-semibold small text-nowrap">Empresa:</label>
                    <select name="empresa_id" class="form-select form-select-sm" style="width:220px;" onchange="this.form.submit()">
                        <option value="">-- Seleccionar --</option>
                        <?php foreach ($empresas as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= $empresaId === (int)$emp['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($emp['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($empresaId > 0): ?>

        <!-- Subir nueva capa -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-upload me-2"></i>Subir nueva capa</h5>
                <span class="badge bg-info">KML, KMZ, GeoJSON, Shapefile (ZIP)</span>
            </div>
            <div class="card-body">

                <!-- Paso 1: Seleccionar archivo -->
                <div class="step active" id="step1">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Archivo de capa geográfica</label>
                        <input type="file" id="file-input" class="form-control"
                               accept=".kml,.kmz,.geojson,.json,.zip,.shp">
                        <div class="form-text">
                            Formatos: KML, KMZ, GeoJSON (.geojson/.json), Shapefile (ZIP con .shp+.dbf+.shx)
                        </div>
                    </div>
                    <div id="upload-progress" style="display:none;">
                        <div class="spinner-border spinner-border-sm text-primary me-2"></div>
                        <span>Analizando archivo...</span>
                    </div>
                </div>

                <!-- Paso 2: Configurar mapeo -->
                <div class="step" id="step2">
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Se encontraron <strong id="feature-count">0</strong> elementos con
                        <strong id="attr-count">0</strong> atributos.
                    </div>

                    <div id="preview-map"></div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Nombre de la capa *</label>
                            <input type="text" id="capa-nombre" class="form-control" placeholder="Ej: Torres eléctricas">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Campo de la capa (código) *</label>
                            <select id="campo-capa" class="form-select">
                                <option value="">-- Seleccionar atributo --</option>
                            </select>
                            <div class="form-text">Atributo del archivo que contiene el código de la infraestructura</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Campo de la tabla infraestructuras</label>
                            <select id="campo-tabla" class="form-select">
                                <option value="codigo_unico" selected>Código único (codigo_unico)</option>
                                <option value="nombre">Nombre</option>
                            </select>
                            <div class="form-text">Campo de la BD con el que se vincula</div>
                        </div>
                    </div>

                    <!-- Previsualización de valores del campo seleccionado -->
                    <div id="campo-preview" class="mb-3" style="display:none;">
                        <label class="form-label fw-semibold small">Valores del campo seleccionado (primeros 10):</label>
                        <div id="campo-valores" class="d-flex flex-wrap gap-1"></div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Color</label>
                            <input type="color" id="capa-color" class="form-control form-control-color" value="#e74c3c">
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button class="btn btn-secondary" onclick="resetUpload()">
                            <i class="bi bi-arrow-left"></i> Volver
                        </button>
                        <button class="btn btn-primary" id="btn-guardar" onclick="guardarCapa()">
                            <i class="bi bi-check-lg"></i> Guardar capa
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Capas existentes -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-stack me-2"></i>Capas guardadas</h5>
            </div>
            <div class="card-body" id="capas-list">
                <?php if (empty($capas)): ?>
                    <p class="text-center text-muted py-3">No hay capas de infraestructuras. Sube la primera arriba.</p>
                <?php else: ?>
                    <?php foreach ($capas as $c): ?>
                    <div class="capa-card" id="capa-card-<?= $c['id'] ?>">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:<?= htmlspecialchars($c['color']) ?>;vertical-align:middle;margin-right:6px;"></span>
                                <strong><?= htmlspecialchars($c['nombre']) ?></strong>
                                <span class="badge bg-secondary ms-2" style="font-size:0.7rem;">
                                    <?= htmlspecialchars($c['campo_capa']) ?> → <?= htmlspecialchars($c['campo_tabla']) ?>
                                </span>
                                <?php
                                    $gj = json_decode($c['geojson'], true);
                                    $nf = count($gj['features'] ?? []);
                                ?>
                                <span class="badge bg-info ms-1" style="font-size:0.7rem;"><?= $nf ?> elementos</span>
                            </div>
                            <div class="d-flex gap-1">
                                <button class="btn btn-sm btn-outline-danger"
                                        onclick="eliminarCapa(<?= $c['id'] ?>, '<?= addslashes(htmlspecialchars($c['nombre'])) ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php else: ?>
        <div class="text-center py-5">
            <i class="bi bi-layers" style="font-size:3rem;color:#adb5bd;"></i>
            <h5 class="mt-3 text-muted">Selecciona una empresa</h5>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <!-- shp.js para parsear Shapefiles en el navegador -->
    <script src="https://unpkg.com/shpjs@latest/dist/shp.js"></script>

    <script>
    var empresaId = <?= $empresaId ?>;
    var csrfToken = <?= json_encode($csrfToken) ?>;
    var pendingGeoJSON = null;
    var previewMap = null;
    var previewLayer = null;

    // --- File input handler ---
    document.getElementById('file-input')?.addEventListener('change', async function() {
        var file = this.files[0];
        if (!file) return;

        var ext = file.name.split('.').pop().toLowerCase();
        document.getElementById('upload-progress').style.display = 'block';

        try {
            if (ext === 'zip' || ext === 'shp') {
                // Shapefile — parse with shp.js
                var arrayBuf = await file.arrayBuffer();
                var geojson = await shp(arrayBuf);
                // shp.js may return a single FeatureCollection or an array
                if (Array.isArray(geojson)) geojson = geojson[0];
                handleGeoJSON(geojson, file.name);
            } else if (ext === 'geojson' || ext === 'json') {
                var text = await file.text();
                var geojson = JSON.parse(text);
                handleGeoJSON(geojson, file.name);
            } else if (ext === 'kml' || ext === 'kmz') {
                // Send to server for parsing
                var formData = new FormData();
                formData.append('archivo', file);
                formData.append('empresa_id', empresaId);
                formData.append('action', 'analizar');
                formData.append('csrf_token', csrfToken);

                var resp = await fetch('api/capas_infra.php', { method: 'POST', body: formData });
                var data = await resp.json();

                if (data.ok) {
                    handleGeoJSON(data.geojson, file.name);
                } else {
                    alert('Error: ' + (data.error || 'No se pudo analizar'));
                }
            } else {
                alert('Formato no soportado: ' + ext);
            }
        } catch (err) {
            alert('Error al procesar archivo: ' + err.message);
        }

        document.getElementById('upload-progress').style.display = 'none';
    });

    function handleGeoJSON(geojson, fileName) {
        pendingGeoJSON = geojson;

        var features = geojson.features || [];
        var attrs = {};
        features.forEach(function(f) {
            Object.keys(f.properties || {}).forEach(function(k) { attrs[k] = true; });
        });
        var attrList = Object.keys(attrs);

        document.getElementById('feature-count').textContent = features.length;
        document.getElementById('attr-count').textContent = attrList.length;

        // Populate campo_capa dropdown
        var select = document.getElementById('campo-capa');
        select.innerHTML = '<option value="">-- Seleccionar atributo --</option>';
        attrList.forEach(function(a) {
            var opt = document.createElement('option');
            opt.value = a;
            opt.textContent = a;
            // Auto-select if it looks like a code field
            if (['codigo', 'code', 'cod', 'id', 'codigo_unico', 'NAME', 'name', 'CODIGO'].indexOf(a) >= 0) {
                opt.selected = true;
            }
            select.appendChild(opt);
        });

        // Set default name from filename
        var nameInput = document.getElementById('capa-nombre');
        if (!nameInput.value) {
            nameInput.value = fileName.replace(/\.[^.]+$/, '');
        }

        // Show step 2
        document.getElementById('step1').classList.remove('active');
        document.getElementById('step2').classList.add('active');

        // Init preview map
        setTimeout(function() {
            if (!previewMap) {
                previewMap = L.map('preview-map').setView([40, -3.7], 6);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OSM', maxZoom: 19
                }).addTo(previewMap);
            }
            renderPreview();
        }, 100);

        // Trigger campo preview
        select.dispatchEvent(new Event('change'));
    }

    // Show sample values when campo_capa changes
    document.getElementById('campo-capa')?.addEventListener('change', function() {
        var campo = this.value;
        var container = document.getElementById('campo-valores');
        var wrapper = document.getElementById('campo-preview');

        if (!campo || !pendingGeoJSON) {
            wrapper.style.display = 'none';
            return;
        }

        var values = [];
        (pendingGeoJSON.features || []).forEach(function(f) {
            var val = (f.properties || {})[campo];
            if (val !== undefined && val !== null && val !== '' && values.indexOf(String(val)) < 0 && values.length < 10) {
                values.push(String(val));
            }
        });

        container.innerHTML = values.map(function(v) {
            return '<span class="badge bg-light text-dark border" style="font-size:0.8rem;">' + v + '</span>';
        }).join('');
        wrapper.style.display = values.length ? 'block' : 'none';
    });

    function renderPreview() {
        if (!previewMap || !pendingGeoJSON) return;

        if (previewLayer) previewMap.removeLayer(previewLayer);

        var color = document.getElementById('capa-color').value || '#e74c3c';

        previewLayer = L.geoJSON(pendingGeoJSON, {
            style: function() {
                return { color: color, weight: 2, opacity: 0.8, fillColor: color, fillOpacity: 0.15 };
            },
            pointToLayer: function(feature, latlng) {
                return L.circleMarker(latlng, {
                    radius: 6, fillColor: color, color: '#fff',
                    weight: 2, opacity: 1, fillOpacity: 0.8
                });
            },
            onEachFeature: function(feature, layer) {
                var props = feature.properties || {};
                var html = '<div style="max-width:250px;">';
                Object.keys(props).forEach(function(k) {
                    if (props[k]) html += '<strong>' + k + ':</strong> ' + String(props[k]).substring(0, 100) + '<br>';
                });
                html += '</div>';
                layer.bindPopup(html);
            }
        }).addTo(previewMap);

        var bounds = previewLayer.getBounds();
        if (bounds.isValid()) previewMap.fitBounds(bounds, { padding: [20, 20] });
    }

    document.getElementById('capa-color')?.addEventListener('input', renderPreview);

    function resetUpload() {
        pendingGeoJSON = null;
        if (previewLayer && previewMap) previewMap.removeLayer(previewLayer);
        document.getElementById('step1').classList.add('active');
        document.getElementById('step2').classList.remove('active');
        document.getElementById('file-input').value = '';
        document.getElementById('capa-nombre').value = '';
    }

    async function guardarCapa() {
        var nombre = document.getElementById('capa-nombre').value.trim();
        var campoCapa = document.getElementById('campo-capa').value;
        var campoTabla = document.getElementById('campo-tabla').value;
        var color = document.getElementById('capa-color').value;

        if (!nombre) { alert('Escribe un nombre para la capa'); return; }
        if (!campoCapa) { alert('Selecciona el campo de la capa que contiene el código'); return; }
        if (!pendingGeoJSON) { alert('No hay datos cargados'); return; }

        var btn = document.getElementById('btn-guardar');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Guardando...';

        try {
            var formData = new FormData();
            formData.append('empresa_id', empresaId);
            formData.append('action', 'guardar');
            formData.append('nombre', nombre);
            formData.append('geojson', JSON.stringify(pendingGeoJSON));
            formData.append('campo_capa', campoCapa);
            formData.append('campo_tabla', campoTabla);
            formData.append('color', color);
            formData.append('csrf_token', csrfToken);

            var resp = await fetch('api/capas_infra.php', { method: 'POST', body: formData });
            var data = await resp.json();

            if (data.ok) {
                alert('Capa guardada correctamente');
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'No se pudo guardar'));
            }
        } catch (err) {
            alert('Error de conexión: ' + err.message);
        }

        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg"></i> Guardar capa';
    }

    async function eliminarCapa(capaId, nombre) {
        if (!confirm('¿Eliminar la capa "' + nombre + '"?')) return;

        var formData = new FormData();
        formData.append('empresa_id', empresaId);
        formData.append('action', 'eliminar');
        formData.append('capa_id', capaId);
        formData.append('csrf_token', csrfToken);

        try {
            var resp = await fetch('api/capas_infra.php', { method: 'POST', body: formData });
            var data = await resp.json();
            if (data.ok) {
                var card = document.getElementById('capa-card-' + capaId);
                if (card) card.remove();
            }
        } catch (err) {
            alert('Error: ' + err.message);
        }
    }
    </script>
</body>
</html>
