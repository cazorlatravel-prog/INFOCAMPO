<?php
/**
 * INFOCAMPO SaaS - Gestión de Infraestructuras (Admin)
 *
 * CRUD completo para que los administradores de empresa
 * gestionen sus infraestructuras directamente.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$pdo = getDB();
$currentPage = 'infraestructuras';

$empresaId = isset($_GET['empresa_id']) ? (int) $_GET['empresa_id'] : ($_SESSION['empresa_id'] ?? 0);

// ---------------------------------------------------------------
// Procesar acciones POST
// ---------------------------------------------------------------
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $id        = (int) ($_POST['id'] ?? 0);
        $nombre    = trim($_POST['nombre'] ?? '');
        $codigo    = trim($_POST['codigo_unico'] ?? '');
        $lat       = $_POST['lat_teorica'] !== '' ? (float) $_POST['lat_teorica'] : 0;
        $lon       = $_POST['lon_teorica'] !== '' ? (float) $_POST['lon_teorica'] : 0;
        $tipo      = trim($_POST['tipo'] ?? '');
        $provincia = trim($_POST['provincia'] ?? '');
        $municipio = trim($_POST['municipio'] ?? '');
        $desc      = trim($_POST['descripcion'] ?? '');

        if ($nombre === '') {
            $msg = 'El nombre de la infraestructura es obligatorio.';
            $msgType = 'danger';
        } elseif ($empresaId <= 0) {
            $msg = 'Empresa no identificada.';
            $msgType = 'danger';
        } else {
            // Auto-generar código si está vacío
            if ($codigo === '') {
                $codigo = 'INF-' . strtoupper(substr(md5($nombre . time()), 0, 8));
            }

            if ($action === 'create') {
                // Verificar límite de infraestructuras
                $empStmt = $pdo->prepare("SELECT max_infraestructuras FROM empresas WHERE id = :id");
                $empStmt->execute([':id' => $empresaId]);
                $empresaData = $empStmt->fetch();

                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM infraestructuras WHERE empresa_id = :id");
                $countStmt->execute([':id' => $empresaId]);
                $currentCount = (int) $countStmt->fetchColumn();

                if ($empresaData && $currentCount >= (int) $empresaData['max_infraestructuras']) {
                    $msg = 'Límite de infraestructuras alcanzado (' . $empresaData['max_infraestructuras'] . ').';
                    $msgType = 'warning';
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO infraestructuras (empresa_id, nombre, codigo_unico, lat_teorica, lon_teorica, tipo, provincia, municipio, descripcion, activa)
                         VALUES (:emp_id, :nombre, :codigo, :lat, :lon, :tipo, :provincia, :municipio, :desc, 1)"
                    );
                    $stmt->execute([
                        ':emp_id' => $empresaId, ':nombre' => $nombre, ':codigo' => $codigo,
                        ':lat' => $lat, ':lon' => $lon, ':tipo' => $tipo ?: null,
                        ':provincia' => $provincia ?: null, ':municipio' => $municipio ?: null,
                        ':desc' => $desc ?: null,
                    ]);
                    $msg = 'Infraestructura creada correctamente.';
                    $msgType = 'success';
                }
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE infraestructuras SET nombre = :nombre, codigo_unico = :codigo,
                     lat_teorica = :lat, lon_teorica = :lon, tipo = :tipo,
                     provincia = :provincia, municipio = :municipio, descripcion = :desc
                     WHERE id = :id AND empresa_id = :emp_id"
                );
                $stmt->execute([
                    ':nombre' => $nombre, ':codigo' => $codigo,
                    ':lat' => $lat, ':lon' => $lon, ':tipo' => $tipo ?: null,
                    ':provincia' => $provincia ?: null, ':municipio' => $municipio ?: null,
                    ':desc' => $desc ?: null, ':id' => $id, ':emp_id' => $empresaId,
                ]);
                $msg = 'Infraestructura actualizada.';
                $msgType = 'success';
            }
        }
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare(
                "UPDATE infraestructuras SET activa = NOT activa WHERE id = :id AND empresa_id = :emp_id"
            )->execute([':id' => $id, ':emp_id' => $empresaId]);
            $msg = 'Estado actualizado.';
            $msgType = 'info';
        }
    }
}

// ---------------------------------------------------------------
// Cargar datos
// ---------------------------------------------------------------
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
        "SELECT i.*, (SELECT COUNT(*) FROM registros r WHERE r.infra_id = i.id) AS num_registros
         FROM infraestructuras i
         WHERE i.empresa_id = :emp_id
         ORDER BY i.activa DESC, i.nombre ASC"
    );
    $stmt->execute([':emp_id' => $empresaId]);
    $infraestructuras = $stmt->fetchAll();
}

// Editar
$editInfra = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM infraestructuras WHERE id = :id AND empresa_id = :emp_id");
    $stmt->execute([':id' => $editId, ':emp_id' => $empresaId]);
    $editInfra = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INFOCAMPO - Gestión de Infraestructuras</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
        .brand-bar { background: linear-gradient(135deg, #1e3a5f, #2d6a9f); color: #fff; padding: 14px 24px; }
        .card { border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border-radius: 12px; }
        .nav-admin { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 0 24px; }
        .nav-admin .nav-link { color: #6b7280; padding: 12px 16px; font-size: 0.9rem; border-bottom: 2px solid transparent; }
        .nav-admin .nav-link:hover, .nav-admin .nav-link.active { color: #1e3a5f; border-bottom-color: #1e3a5f; }
        .form-section { background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); margin-bottom: 20px; }
        .infra-card {
            background: #fff; border-radius: 12px; padding: 18px 22px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06); margin-bottom: 12px;
            border-left: 4px solid #2d6a9f; transition: transform 0.1s;
        }
        .infra-card:hover { transform: translateX(2px); }
        .infra-card.inactive { opacity: 0.5; border-left-color: #d1d5db; }
        .tipo-badge { font-size: 0.65rem; padding: 3px 8px; border-radius: 6px; background: #e0e7ff; color: #4338ca; }
        .coord-text { font-size: 0.75rem; color: #6b7280; font-family: monospace; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h4 class="mb-0"><i class="bi bi-geo-alt me-2"></i>Infraestructuras</h4>
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
                <?php if ($empresaId > 0): ?>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formInfra">
                        <i class="bi bi-plus-lg"></i> Nueva Infraestructura
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?= $msgType ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($empresaId > 0): ?>

        <!-- Formulario crear/editar -->
        <div class="collapse <?= $editInfra ? 'show' : '' ?>" id="formInfra">
            <div class="form-section">
                <h5 class="mb-3"><?= $editInfra ? 'Editar Infraestructura' : 'Nueva Infraestructura' ?></h5>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editInfra ? 'update' : 'create' ?>">
                    <?php if ($editInfra): ?>
                        <input type="hidden" name="id" value="<?= $editInfra['id'] ?>">
                    <?php endif; ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Nombre *</label>
                            <input type="text" name="nombre" class="form-control" required
                                   placeholder="Torre Alta Tensión KM-42"
                                   value="<?= htmlspecialchars($editInfra['nombre'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold small">Código único</label>
                            <input type="text" name="codigo_unico" class="form-control"
                                   placeholder="Auto si vacío"
                                   value="<?= htmlspecialchars($editInfra['codigo_unico'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold small">Tipo</label>
                            <input type="text" name="tipo" class="form-control"
                                   placeholder="torre, poste..."
                                   value="<?= htmlspecialchars($editInfra['tipo'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold small">Provincia</label>
                            <input type="text" name="provincia" class="form-control"
                                   placeholder="Sevilla, Madrid..."
                                   value="<?= htmlspecialchars($editInfra['provincia'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Municipio</label>
                            <input type="text" name="municipio" class="form-control"
                                   placeholder="Nombre del municipio"
                                   value="<?= htmlspecialchars($editInfra['municipio'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold small">Latitud</label>
                            <input type="number" step="0.0000001" name="lat_teorica" id="lat_teorica" class="form-control"
                                   placeholder="37.3890531"
                                   value="<?= $editInfra['lat_teorica'] ?? '' ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold small">Longitud</label>
                            <input type="number" step="0.0000001" name="lon_teorica" id="lon_teorica" class="form-control"
                                   placeholder="-5.9844589"
                                   value="<?= $editInfra['lon_teorica'] ?? '' ?>">
                        </div>
                        <div class="col-12">
                            <button type="button" id="btn-get-location" class="btn btn-sm btn-outline-info me-2" onclick="getMyLocation()">
                                <i class="bi bi-crosshair"></i> Usar mi ubicación
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleMapPicker()">
                                <i class="bi bi-map"></i> Elegir en mapa
                            </button>
                            <span id="geo-status" class="small text-muted ms-2"></span>
                        </div>
                        <div class="col-12" id="map-picker-wrapper" style="display:none;">
                            <div id="map-picker" style="height:300px;border-radius:10px;border:2px solid #e5e7eb;"></div>
                            <p class="small text-muted mt-1"><i class="bi bi-hand-index"></i> Haz clic en el mapa para colocar el marcador</p>
                        </div>
                        <div class="col-md-10">
                            <label class="form-label fw-semibold small">Descripción</label>
                            <input type="text" name="descripcion" class="form-control"
                                   placeholder="Descripción opcional..."
                                   value="<?= htmlspecialchars($editInfra['descripcion'] ?? '') ?>">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-check-lg"></i> <?= $editInfra ? 'Guardar' : 'Crear' ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Contador -->
        <div class="mb-3">
            <span class="text-muted small">
                <?= count($infraestructuras) ?> infraestructura<?= count($infraestructuras) !== 1 ? 's' : '' ?>
            </span>
        </div>

        <!-- Lista de infraestructuras -->
        <?php foreach ($infraestructuras as $inf): ?>
            <div class="infra-card <?= $inf['activa'] ? '' : 'inactive' ?>">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <strong><?= htmlspecialchars($inf['nombre']) ?></strong>
                            <code class="small" style="color:#2d6a9f;"><?= htmlspecialchars($inf['codigo_unico']) ?></code>
                            <?php if ($inf['tipo']): ?>
                                <span class="tipo-badge"><?= htmlspecialchars($inf['tipo']) ?></span>
                            <?php endif; ?>
                            <?php if (!$inf['activa']): ?>
                                <span class="badge bg-danger" style="font-size:0.65rem;">Inactiva</span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-3 flex-wrap">
                            <?php if (!empty($inf['provincia']) || !empty($inf['municipio'])): ?>
                                <span class="small text-muted">
                                    <i class="bi bi-pin-map"></i>
                                    <?= htmlspecialchars(trim(($inf['municipio'] ?? '') . ', ' . ($inf['provincia'] ?? ''), ', ')) ?>
                                </span>
                            <?php endif; ?>
                            <span class="coord-text">
                                <i class="bi bi-geo-alt"></i>
                                <?= $inf['lat_teorica'] ?>, <?= $inf['lon_teorica'] ?>
                            </span>
                            <span class="small text-muted">
                                <i class="bi bi-camera"></i> <?= $inf['num_registros'] ?> inspecciones
                            </span>
                            <?php if ($inf['descripcion']): ?>
                                <span class="small text-muted">
                                    <?= htmlspecialchars(mb_substr($inf['descripcion'], 0, 60)) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="d-flex gap-1">
                        <a href="index.php?empresa_id=<?= $empresaId ?>&infra_id=<?= $inf['id'] ?>"
                           class="btn btn-sm btn-outline-primary" title="Ver Timeline">
                            <i class="bi bi-clock-history"></i>
                        </a>
                        <a href="?empresa_id=<?= $empresaId ?>&edit=<?= $inf['id'] ?>"
                           class="btn btn-sm btn-outline-secondary" title="Editar">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <form method="post" class="d-inline" onsubmit="return confirm('¿Cambiar estado?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $inf['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-<?= $inf['activa'] ? 'warning' : 'success' ?>"
                                    title="<?= $inf['activa'] ? 'Desactivar' : 'Activar' ?>">
                                <i class="bi bi-<?= $inf['activa'] ? 'pause-circle' : 'play-circle' ?>"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (empty($infraestructuras)): ?>
            <div class="text-center py-5">
                <i class="bi bi-geo-alt" style="font-size:3rem;color:#adb5bd;"></i>
                <h5 class="mt-3 text-muted">Sin infraestructuras</h5>
                <p class="text-muted">Crea la primera con el botón "Nueva Infraestructura".</p>
            </div>
        <?php endif; ?>

        <?php else: ?>
            <div class="text-center py-5">
                <i class="bi bi-geo-alt" style="font-size:3rem;color:#adb5bd;"></i>
                <h5 class="mt-3 text-muted">Selecciona una empresa</h5>
            </div>
        <?php endif; ?>
    </div>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        let pickerMap = null;
        let pickerMarker = null;

        function getMyLocation() {
            const status = document.getElementById('geo-status');
            if (!navigator.geolocation) {
                status.textContent = 'Geolocalización no disponible en este navegador';
                return;
            }
            status.textContent = 'Obteniendo ubicación...';
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    document.getElementById('lat_teorica').value = pos.coords.latitude.toFixed(7);
                    document.getElementById('lon_teorica').value = pos.coords.longitude.toFixed(7);
                    status.textContent = 'Ubicación obtenida';
                    status.style.color = '#22c55e';
                    updateMapMarker(pos.coords.latitude, pos.coords.longitude);
                },
                (err) => {
                    status.textContent = 'Error: ' + err.message;
                    status.style.color = '#ef4444';
                },
                { enableHighAccuracy: true, timeout: 10000 }
            );
        }

        function toggleMapPicker() {
            const wrapper = document.getElementById('map-picker-wrapper');
            const visible = wrapper.style.display !== 'none';
            wrapper.style.display = visible ? 'none' : 'block';

            if (!visible && !pickerMap) {
                const lat = parseFloat(document.getElementById('lat_teorica').value) || 40.416775;
                const lon = parseFloat(document.getElementById('lon_teorica').value) || -3.703790;
                const zoom = (document.getElementById('lat_teorica').value) ? 15 : 6;

                pickerMap = L.map('map-picker').setView([lat, lon], zoom);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OSM', maxZoom: 19,
                }).addTo(pickerMap);

                if (document.getElementById('lat_teorica').value) {
                    pickerMarker = L.marker([lat, lon]).addTo(pickerMap);
                }

                pickerMap.on('click', function(e) {
                    const { lat, lng } = e.latlng;
                    document.getElementById('lat_teorica').value = lat.toFixed(7);
                    document.getElementById('lon_teorica').value = lng.toFixed(7);

                    if (pickerMarker) {
                        pickerMarker.setLatLng([lat, lng]);
                    } else {
                        pickerMarker = L.marker([lat, lng]).addTo(pickerMap);
                    }
                });
            }

            if (!visible && pickerMap) {
                setTimeout(() => pickerMap.invalidateSize(), 100);
            }
        }

        function updateMapMarker(lat, lon) {
            if (!pickerMap) return;
            pickerMap.setView([lat, lon], 15);
            if (pickerMarker) {
                pickerMarker.setLatLng([lat, lon]);
            } else {
                pickerMarker = L.marker([lat, lon]).addTo(pickerMap);
            }
        }
    </script>
</body>
</html>
