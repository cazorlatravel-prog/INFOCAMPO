<?php
/**
 * INFOCAMPO SaaS - Puntos de Mapa para Operadores (Admin)
 *
 * Permite al administrador crear, editar y eliminar puntos
 * personalizados que aparecerán en el mapa del operador.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'superadmin']);

$pdo = getDB();
$currentPage = 'puntos_mapa';
$empresaId = isset($_GET['empresa_id']) ? (int) $_GET['empresa_id'] : ($_SESSION['empresa_id'] ?? 0);

$puntos = [];
if ($empresaId > 0) {
    $stmt = $pdo->prepare(
        "SELECT p.*, u.nombre AS creador_nombre
         FROM puntos_mapa p
         LEFT JOIN usuarios u ON p.created_by = u.id
         WHERE p.empresa_id = :emp
         ORDER BY p.nombre ASC"
    );
    $stmt->execute([':emp' => $empresaId]);
    $puntos = $stmt->fetchAll();
}

$csrfToken = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FotoGPS.app - Puntos de Mapa</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="/admin/css/admin.css" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
    #punto-map { height: 350px; border-radius: 10px; border: 1px solid #dee2e6; margin-bottom: 12px; cursor: crosshair; }
    .punto-card {
        background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
        padding: 14px 16px; margin-bottom: 8px; transition: box-shadow 0.15s;
    }
    .punto-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
    .punto-card.inactivo { opacity: 0.5; }
    .punto-dot {
        width: 14px; height: 14px; border-radius: 50%;
        display: inline-block; flex-shrink: 0; border: 2px solid #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,0.3);
    }
    .color-opt { width: 28px; height: 28px; border-radius: 50%; border: 3px solid transparent;
        cursor: pointer; transition: all 0.1s; display: inline-block; }
    .color-opt:hover, .color-opt.active { border-color: #333; transform: scale(1.15); }
    .icono-opt { width: 36px; height: 36px; border-radius: 8px; border: 2px solid transparent;
        cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
        font-size: 1rem; background: #f3f4f6; transition: all 0.1s; }
    .icono-opt:hover, .icono-opt.active { border-color: #4f6ef7; background: #eef2ff; }
    .stats-row { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
    .stat-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 16px; flex: 1; min-width: 120px; }
    .stat-card .stat-num { font-size: 1.5rem; font-weight: 800; }
    .stat-card .stat-label { font-size: 0.75rem; color: #6b7280; }
</style>
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h4 class="mb-0"><i class="bi bi-geo-fill me-2"></i>Puntos de Mapa</h4>
            <button class="btn btn-primary btn-sm" onclick="openModal()">
                <i class="bi bi-plus-lg"></i> Nuevo Punto
            </button>
        </div>

        <p class="text-muted small mb-3">
            Crea puntos personalizados que aparecerán en el mapa del operador. Útil para señalizar ubicaciones de referencia, almacenes, puntos de encuentro, etc.
        </p>

        <?php if ($empresaId <= 0): ?>
            <div class="alert alert-warning">Seleccione una empresa para gestionar sus puntos de mapa.</div>
        <?php else: ?>

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-num" style="color:#4f6ef7;"><?= count($puntos) ?></div>
                <div class="stat-label">Total puntos</div>
            </div>
            <div class="stat-card">
                <div class="stat-num" style="color:#22c55e;"><?= count(array_filter($puntos, fn($p) => $p['activo'])) ?></div>
                <div class="stat-label">Activos</div>
            </div>
            <div class="stat-card">
                <div class="stat-num" style="color:#9ca3af;"><?= count(array_filter($puntos, fn($p) => !$p['activo'])) ?></div>
                <div class="stat-label">Inactivos</div>
            </div>
        </div>

        <!-- Lista de puntos -->
        <?php if (empty($puntos)): ?>
            <div class="text-center py-5">
                <i class="bi bi-geo-alt" style="font-size:3rem;color:#d1d5db;"></i>
                <p class="text-muted mt-2">No hay puntos de mapa creados.<br>Pulsa <strong>"Nuevo Punto"</strong> para añadir uno.</p>
            </div>
        <?php else: ?>
            <div id="puntos-list">
            <?php foreach ($puntos as $p): ?>
                <div class="punto-card <?= !$p['activo'] ? 'inactivo' : '' ?>" id="punto-<?= $p['id'] ?>">
                    <div class="d-flex align-items-start gap-3">
                        <span class="punto-dot mt-1" style="background:<?= htmlspecialchars($p['color']) ?>;"></span>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <strong><?= htmlspecialchars($p['nombre']) ?></strong>
                                    <?php if (!$p['activo']): ?>
                                        <span class="badge bg-secondary ms-1" style="font-size:0.6rem;">Inactivo</span>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex gap-1">
                                    <button class="btn btn-sm btn-outline-primary" onclick='editPunto(<?= json_encode($p) ?>)' title="Editar">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-warning" onclick="togglePunto(<?= $p['id'] ?>)" title="<?= $p['activo'] ? 'Desactivar' : 'Activar' ?>">
                                        <i class="bi bi-<?= $p['activo'] ? 'eye-slash' : 'eye' ?>"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deletePunto(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['nombre'])) ?>')" title="Eliminar">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <?php if ($p['descripcion']): ?>
                                <small class="text-muted d-block mt-1"><?= htmlspecialchars($p['descripcion']) ?></small>
                            <?php endif; ?>
                            <small class="text-muted" style="font-size:0.7rem;">
                                <i class="bi bi-crosshair"></i> <?= number_format((float)$p['lat'], 6) ?>, <?= number_format((float)$p['lon'], 6) ?>
                                <?php if ($p['creador_nombre']): ?>
                                    &middot; <i class="bi bi-person"></i> <?= htmlspecialchars($p['creador_nombre']) ?>
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

    <!-- Modal crear/editar punto -->
    <div class="modal fade" id="puntoModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"><i class="bi bi-geo-fill me-2"></i>Nuevo Punto</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="punto-id" value="">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nombre *</label>
                        <input type="text" id="punto-nombre" class="form-control" placeholder="Ej: Almacén central, Punto de encuentro...">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Descripción</label>
                        <textarea id="punto-descripcion" class="form-control" rows="2" placeholder="Descripción opcional..."></textarea>
                    </div>

                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Latitud *</label>
                            <input type="number" id="punto-lat" class="form-control" step="0.0000001" placeholder="40.416775">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Longitud *</label>
                            <input type="number" id="punto-lon" class="form-control" step="0.0000001" placeholder="-3.703790">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Haz clic en el mapa para seleccionar ubicación</label>
                        <div id="punto-map"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Color</label>
                        <div class="d-flex gap-2 flex-wrap" id="color-picker">
                            <span class="color-opt active" data-color="#e74c3c" style="background:#e74c3c;" title="Rojo"></span>
                            <span class="color-opt" data-color="#f59e0b" style="background:#f59e0b;" title="Naranja"></span>
                            <span class="color-opt" data-color="#22c55e" style="background:#22c55e;" title="Verde"></span>
                            <span class="color-opt" data-color="#3b82f6" style="background:#3b82f6;" title="Azul"></span>
                            <span class="color-opt" data-color="#8b5cf6" style="background:#8b5cf6;" title="Violeta"></span>
                            <span class="color-opt" data-color="#ec4899" style="background:#ec4899;" title="Rosa"></span>
                            <span class="color-opt" data-color="#6b7280" style="background:#6b7280;" title="Gris"></span>
                            <span class="color-opt" data-color="#000000" style="background:#000000;" title="Negro"></span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Icono</label>
                        <div class="d-flex gap-2 flex-wrap" id="icono-picker">
                            <span class="icono-opt active" data-icono="pin" title="Pin"><i class="bi bi-geo-alt-fill"></i></span>
                            <span class="icono-opt" data-icono="star" title="Estrella"><i class="bi bi-star-fill"></i></span>
                            <span class="icono-opt" data-icono="flag" title="Bandera"><i class="bi bi-flag-fill"></i></span>
                            <span class="icono-opt" data-icono="house" title="Casa"><i class="bi bi-house-fill"></i></span>
                            <span class="icono-opt" data-icono="box" title="Almacén"><i class="bi bi-box-fill"></i></span>
                            <span class="icono-opt" data-icono="exclamation" title="Alerta"><i class="bi bi-exclamation-triangle-fill"></i></span>
                            <span class="icono-opt" data-icono="tools" title="Herramientas"><i class="bi bi-tools"></i></span>
                            <span class="icono-opt" data-icono="person" title="Persona"><i class="bi bi-person-fill"></i></span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="btn-save-punto" onclick="savePunto()">
                        <i class="bi bi-check-lg"></i> Guardar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
    const CSRF = <?= json_encode($csrfToken) ?>;
    const EMPRESA_ID = <?= $empresaId ?>;
    let modalMap = null;
    let modalMarker = null;
    let modalInstance = null;
    let selectedColor = '#e74c3c';
    let selectedIcono = 'pin';

    function openModal(isEdit) {
        if (!modalInstance) {
            modalInstance = new bootstrap.Modal(document.getElementById('puntoModal'));
        }
        if (!isEdit) {
            document.getElementById('modalTitle').innerHTML = '<i class="bi bi-geo-fill me-2"></i>Nuevo Punto';
            document.getElementById('punto-id').value = '';
            document.getElementById('punto-nombre').value = '';
            document.getElementById('punto-descripcion').value = '';
            document.getElementById('punto-lat').value = '';
            document.getElementById('punto-lon').value = '';
            selectColor('#e74c3c');
            selectIcono('pin');
        }
        modalInstance.show();

        setTimeout(() => {
            if (!modalMap) {
                modalMap = L.map('punto-map').setView([40.416775, -3.703790], 6);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OSM', maxZoom: 19
                }).addTo(modalMap);
                modalMap.on('click', function(e) {
                    setMarker(e.latlng.lat, e.latlng.lng);
                });
            } else {
                modalMap.invalidateSize();
            }

            // If editing with coords, center map
            const lat = parseFloat(document.getElementById('punto-lat').value);
            const lon = parseFloat(document.getElementById('punto-lon').value);
            if (lat && lon) {
                setMarker(lat, lon);
                modalMap.setView([lat, lon], 14);
            } else if (modalMarker) {
                modalMap.removeLayer(modalMarker);
                modalMarker = null;
            }
        }, 300);
    }

    function setMarker(lat, lon) {
        document.getElementById('punto-lat').value = lat.toFixed(7);
        document.getElementById('punto-lon').value = lon.toFixed(7);
        if (modalMarker) {
            modalMarker.setLatLng([lat, lon]);
        } else {
            modalMarker = L.marker([lat, lon]).addTo(modalMap);
        }
    }

    // Color picker
    document.querySelectorAll('.color-opt').forEach(el => {
        el.addEventListener('click', () => selectColor(el.dataset.color));
    });

    function selectColor(color) {
        selectedColor = color;
        document.querySelectorAll('.color-opt').forEach(el => {
            el.classList.toggle('active', el.dataset.color === color);
        });
    }

    // Icon picker
    document.querySelectorAll('.icono-opt').forEach(el => {
        el.addEventListener('click', () => selectIcono(el.dataset.icono));
    });

    function selectIcono(icono) {
        selectedIcono = icono;
        document.querySelectorAll('.icono-opt').forEach(el => {
            el.classList.toggle('active', el.dataset.icono === icono);
        });
    }

    function editPunto(p) {
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Editar Punto';
        document.getElementById('punto-id').value = p.id;
        document.getElementById('punto-nombre').value = p.nombre;
        document.getElementById('punto-descripcion').value = p.descripcion || '';
        document.getElementById('punto-lat').value = p.lat;
        document.getElementById('punto-lon').value = p.lon;
        selectColor(p.color || '#e74c3c');
        selectIcono(p.icono || 'pin');
        openModal(true);
    }

    async function savePunto() {
        const id = document.getElementById('punto-id').value;
        const nombre = document.getElementById('punto-nombre').value.trim();
        const lat = document.getElementById('punto-lat').value;
        const lon = document.getElementById('punto-lon').value;

        if (!nombre) { alert('El nombre es obligatorio'); return; }
        if (!lat || !lon) { alert('Selecciona una ubicación en el mapa'); return; }

        const body = new URLSearchParams({
            action: id ? 'editar' : 'crear',
            empresa_id: EMPRESA_ID,
            csrf_token: CSRF,
            nombre: nombre,
            descripcion: document.getElementById('punto-descripcion').value.trim(),
            lat: lat,
            lon: lon,
            icono: selectedIcono,
            color: selectedColor,
        });
        if (id) body.set('id', id);

        try {
            const res = await fetch('/public/api/puntos_mapa.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': CSRF },
                body: body,
            });
            const data = await res.json();
            if (data.ok) {
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'desconocido'));
            }
        } catch (err) {
            alert('Error de conexión');
        }
    }

    async function togglePunto(id) {
        const body = new URLSearchParams({
            action: 'toggle',
            empresa_id: EMPRESA_ID,
            csrf_token: CSRF,
            id: id,
        });
        try {
            const res = await fetch('/public/api/puntos_mapa.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': CSRF },
                body: body,
            });
            const data = await res.json();
            if (data.ok) location.reload();
            else alert('Error: ' + (data.error || 'desconocido'));
        } catch (err) {
            alert('Error de conexión');
        }
    }

    async function deletePunto(id, nombre) {
        if (!confirm('¿Eliminar el punto "' + nombre + '"? Esta acción no se puede deshacer.')) return;

        const body = new URLSearchParams({
            action: 'eliminar',
            empresa_id: EMPRESA_ID,
            csrf_token: CSRF,
            id: id,
        });
        try {
            const res = await fetch('/public/api/puntos_mapa.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': CSRF },
                body: body,
            });
            const data = await res.json();
            if (data.ok) location.reload();
            else alert('Error: ' + (data.error || 'desconocido'));
        } catch (err) {
            alert('Error de conexión');
        }
    }
    </script>
</body>
</html>
