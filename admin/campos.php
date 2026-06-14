<?php
/**
 * INFOCAMPO - Gestión de Campos del Formulario (Admin)
 *
 * Permite a admins gestionar los campos dinámicos del formulario
 * de visita: crear, editar, reordenar, importar CSV y exportar CSV/Excel.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = requireRole(['admin', 'supervisor', 'superadmin']);

$pdo = getDB();
$currentPage = 'campos';

$empresaId = (int) ($_SESSION['empresa_id'] ?? 0);
$empresaNombre = $_SESSION['empresa_nombre'] ?? '';
$isSupervisor = ($_SESSION['user_rol'] === 'supervisor');

// Cargar campos
$campos = [];
if ($empresaId > 0) {
    $stmt = $pdo->prepare(
        "SELECT * FROM campos_formulario
         WHERE empresa_id = :emp AND activo = 1
         ORDER BY orden ASC, id ASC"
    );
    $stmt->execute([':emp' => $empresaId]);
    $campos = $stmt->fetchAll();
}

$tiposCampo = [
    'texto'    => 'Texto corto',
    'numero'   => 'Número',
    'select'   => 'Desplegable (opciones)',
    'checkbox' => 'Casilla Sí/No',
    'textarea' => 'Texto largo',
    'fecha'    => 'Fecha',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INFOCAMPO - Campos del Formulario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/admin/css/admin.css" rel="stylesheet">
    <style>
        .page-content { padding: 24px 32px; max-width: 1200px; margin: 0 auto; }

        .campo-card {
            background: #fff; border-radius: 10px; padding: 14px 18px; margin-bottom: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 14px;
            cursor: grab; border-left: 4px solid #dee2e6; transition: box-shadow 0.15s;
        }
        .campo-card:hover { box-shadow: 0 3px 12px rgba(0,0,0,0.1); }
        .campo-card.obligatorio { border-left-color: #dc3545; }
        .campo-card .campo-drag { color: #adb5bd; font-size: 1.1rem; cursor: grab; }
        .campo-card .campo-info { flex: 1; min-width: 0; }
        .campo-card .campo-nombre { font-weight: 600; font-size: 0.92rem; }
        .campo-card .campo-meta { font-size: 0.76rem; color: #6b7280; }
        .campo-card .campo-actions { display: flex; gap: 4px; }
        .campo-card.dragging { opacity: 0.5; }

        .form-section { background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); margin-bottom: 20px; }

        .preview-form { background: #f8f9fa; border-radius: 10px; padding: 18px; border: 2px dashed #dee2e6; }
        .preview-form h6 { color: #6b7280; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; }

        .import-export-bar { background: #fff; border-radius: 10px; padding: 14px 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); margin-bottom: 20px; }

        .toast-msg {
            position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
            padding: 10px 24px; border-radius: 10px; font-size: 0.85rem; z-index: 9999;
            box-shadow: 0 4px 16px rgba(0,0,0,0.3); animation: fadeUp 0.3s ease; color: #fff;
        }
        @keyframes fadeUp { from { opacity: 0; transform: translateX(-50%) translateY(10px); } to { opacity: 1; transform: translateX(-50%) translateY(0); } }

        @media (max-width: 768px) {
            .page-content { padding: 16px; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="page-content">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h4 class="mb-0"><i class="bi bi-ui-checks-grid me-2"></i>Campos del Formulario</h4>
                <small class="text-muted">Configura los campos que verán los operadores en la ficha de visita</small>
            </div>
            <?php if (!$isSupervisor): ?>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary" onclick="createDefaults()" title="Crear campos predeterminados">
                    <i class="bi bi-magic"></i> Campos por defecto
                </button>
                <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#formNewCampo">
                    <i class="bi bi-plus-lg"></i> Nuevo campo
                </button>
            </div>
            <?php endif; ?>
        </div>

        <!-- Import/Export bar -->
        <div class="import-export-bar">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <span class="fw-semibold small text-muted"><i class="bi bi-arrow-left-right"></i> Importar / Exportar</span>
                <a href="api/campos.php?empresa_id=<?= $empresaId ?>&export=csv" class="btn btn-sm btn-outline-success" title="Descargar CSV">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Exportar CSV
                </a>
                <?php if (!$isSupervisor): ?>
                <div class="d-flex gap-1 align-items-center">
                    <input type="file" id="csv-import-input" accept=".csv,.xlsx,.xls" class="form-control form-control-sm" style="width:200px;font-size:0.8rem;" aria-label="Importar CSV">
                    <button class="btn btn-sm btn-outline-primary" onclick="importCsv()" id="btn-import-csv" title="Importar campos desde CSV">
                        <i class="bi bi-upload"></i> Importar
                    </button>
                </div>
                <a href="data:text/csv;charset=utf-8,%EF%BB%BFnombre;slug;tipo;opciones;obligatorio;orden%0ACOD%20Infraestructura;cod_infraestructura;texto;;NO;0%0ANombre%20Infraestructura;nombre_infraestructura;texto;;NO;1%0AMonte;monte;texto;;NO;2%0AMunicipio;municipio;texto;;NO;3%0AEstado;estado;select;Bueno%7CRegular%7CMalo;NO;4"
                   download="plantilla_campos.csv" class="btn btn-sm btn-outline-secondary" title="Descargar plantilla CSV">
                    <i class="bi bi-download"></i> Plantilla CSV
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Form nuevo campo -->
        <?php if (!$isSupervisor): ?>
        <div class="collapse" id="formNewCampo">
            <div class="form-section">
                <h6 class="mb-3"><i class="bi bi-plus-circle"></i> Nuevo campo</h6>
                <div class="row g-3" id="new-campo-form">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Nombre del campo *</label>
                        <input type="text" id="nc-nombre" class="form-control form-control-sm" placeholder="Ej: Monte">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold small">Slug (auto)</label>
                        <input type="text" id="nc-slug" class="form-control form-control-sm" placeholder="monte">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold small">Tipo</label>
                        <select id="nc-tipo" class="form-select form-select-sm" onchange="toggleNewOpciones()">
                            <?php foreach ($tiposCampo as $val => $label): ?>
                                <option value="<?= $val ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3" id="nc-opciones-wrap" style="display:none;">
                        <label class="form-label fw-semibold small">Opciones (una por línea)</label>
                        <textarea id="nc-opciones" class="form-control form-control-sm" rows="2" placeholder="Opción 1&#10;Opción 2"></textarea>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-semibold small">Orden</label>
                        <input type="number" id="nc-orden" class="form-control form-control-sm" min="0" value="<?= count($campos) ?>">
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <div class="form-check">
                            <input type="checkbox" id="nc-obligatorio" class="form-check-input">
                            <label class="form-check-label small" for="nc-obligatorio">Oblig.</label>
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="button" class="btn btn-primary btn-sm w-100" onclick="createCampo()">
                            <i class="bi bi-check-lg"></i> Crear
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Lista de campos -->
            <div class="col-lg-7">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0 text-muted"><i class="bi bi-list-ol"></i> <?= count($campos) ?> campo(s) configurados</h6>
                </div>
                <div id="campos-list">
                    <?php if (empty($campos)): ?>
                        <div class="text-center text-muted py-5" id="empty-state">
                            <i class="bi bi-ui-checks-grid" style="font-size:2.5rem;opacity:0.3;"></i>
                            <p class="mt-2">No hay campos configurados.<br>
                            Pulsa "Campos por defecto" para empezar rápidamente o crea campos manualmente.</p>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($campos as $campo): ?>
                        <?php
                        $tipoLabel = $tiposCampo[$campo['tipo']] ?? $campo['tipo'];
                        $opcsList = '';
                        if ($campo['tipo'] === 'select' && $campo['opciones']) {
                            $opts = json_decode($campo['opciones'], true);
                            $opcsList = $opts ? implode(', ', $opts) : '';
                        }
                        ?>
                        <div class="campo-card <?= $campo['obligatorio'] ? 'obligatorio' : '' ?>"
                             data-id="<?= $campo['id'] ?>" draggable="<?= $isSupervisor ? 'false' : 'true' ?>">
                            <div class="campo-drag"><i class="bi bi-grip-vertical"></i></div>
                            <div class="campo-info">
                                <div class="campo-nombre">
                                    <?= htmlspecialchars($campo['nombre']) ?>
                                    <?php if ($campo['obligatorio']): ?>
                                        <span class="badge bg-danger" style="font-size:0.6rem;">Obligatorio</span>
                                    <?php endif; ?>
                                </div>
                                <div class="campo-meta">
                                    <span class="badge bg-light text-dark"><?= $tipoLabel ?></span>
                                    <span class="text-muted">slug: <?= htmlspecialchars($campo['slug']) ?></span>
                                    <?php if ($opcsList): ?>
                                        &middot; <span class="text-muted"><?= htmlspecialchars($opcsList) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!$isSupervisor): ?>
                            <div class="campo-actions">
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteCampo(<?= $campo['id'] ?>, '<?= htmlspecialchars(addslashes($campo['nombre'])) ?>')" title="Eliminar">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Preview -->
            <div class="col-lg-5">
                <h6 class="mb-2 text-muted"><i class="bi bi-eye"></i> Vista previa (así lo verá el operador)</h6>
                <div class="preview-form">
                    <!-- Campos fijos -->
                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-muted">Situación</label>
                        <select class="form-select form-select-sm" disabled>
                            <option>ANTES</option><option>DURANTE</option><option>DESPUÉS</option>
                        </select>
                    </div>

                    <hr>
                    <h6 class="mb-2 text-primary"><i class="bi bi-stars"></i> Campos personalizados</h6>

                    <?php if (empty($campos)): ?>
                        <p class="text-muted small text-center">Sin campos configurados</p>
                    <?php endif; ?>

                    <?php foreach ($campos as $campo): ?>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold mb-0">
                                <?= htmlspecialchars($campo['nombre']) ?>
                                <?= $campo['obligatorio'] ? '<span class="text-danger">*</span>' : '' ?>
                            </label>
                            <?php
                            switch ($campo['tipo']) {
                                case 'texto':
                                    echo '<input type="text" class="form-control form-control-sm" disabled placeholder="' . htmlspecialchars($campo['nombre']) . '">';
                                    break;
                                case 'numero':
                                    echo '<input type="number" class="form-control form-control-sm" disabled placeholder="0">';
                                    break;
                                case 'select':
                                    echo '<select class="form-select form-select-sm" disabled><option value="">-- Seleccionar --</option>';
                                    $opts = json_decode($campo['opciones'] ?? '[]', true) ?: [];
                                    foreach ($opts as $opt) echo '<option>' . htmlspecialchars($opt) . '</option>';
                                    echo '</select>';
                                    break;
                                case 'checkbox':
                                    echo '<div class="form-check"><input type="checkbox" class="form-check-input" disabled><label class="form-check-label small">' . htmlspecialchars($campo['nombre']) . '</label></div>';
                                    break;
                                case 'textarea':
                                    echo '<textarea class="form-control form-control-sm" rows="2" disabled placeholder="' . htmlspecialchars($campo['nombre']) . '"></textarea>';
                                    break;
                                case 'fecha':
                                    echo '<input type="date" class="form-control form-control-sm" disabled>';
                                    break;
                            }
                            ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    var empresaId = <?= $empresaId ?>;
    var csrfToken = '<?= csrfToken() ?>';

    function showToast(msg, type) {
        var t = document.createElement('div');
        t.className = 'toast-msg';
        t.style.background = type === 'success' ? '#059669' : '#dc2626';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function() { t.remove(); }, 3000);
    }

    function toggleNewOpciones() {
        document.getElementById('nc-opciones-wrap').style.display =
            document.getElementById('nc-tipo').value === 'select' ? '' : 'none';
    }

    async function createCampo() {
        var fd = new FormData();
        fd.append('action', 'create');
        fd.append('empresa_id', empresaId);
        fd.append('csrf_token', csrfToken);
        fd.append('nombre', document.getElementById('nc-nombre').value);
        fd.append('slug', document.getElementById('nc-slug').value);
        fd.append('tipo', document.getElementById('nc-tipo').value);
        fd.append('opciones', document.getElementById('nc-opciones').value);
        fd.append('orden', document.getElementById('nc-orden').value);
        if (document.getElementById('nc-obligatorio').checked) fd.append('obligatorio', '1');

        try {
            var r = await fetch('api/campos.php', { method: 'POST', body: fd });
            var d = await r.json();
            if (d.ok) {
                showToast('Campo creado', 'success');
                setTimeout(function() { location.reload(); }, 500);
            } else {
                showToast(d.error || 'Error', 'error');
            }
        } catch (e) { showToast('Error de conexión', 'error'); }
    }

    async function createDefaults() {
        if (!confirm('¿Crear los campos por defecto?\n\n• COD Infraestructura\n• Nombre Infraestructura\n• Monte\n• Municipio\n• Unidad de obra\n• Observaciones\n\nNo se duplicarán si ya existen.')) return;

        var fd = new FormData();
        fd.append('action', 'create_defaults');
        fd.append('empresa_id', empresaId);
        fd.append('csrf_token', csrfToken);

        try {
            var r = await fetch('api/campos.php', { method: 'POST', body: fd });
            var d = await r.json();
            if (d.ok) {
                showToast(d.inserted + ' campos creados', 'success');
                setTimeout(function() { location.reload(); }, 500);
            } else {
                showToast(d.error || 'Error', 'error');
            }
        } catch (e) { showToast('Error de conexión', 'error'); }
    }

    async function deleteCampo(id, nombre) {
        if (!confirm('¿Eliminar el campo "' + nombre + '"?')) return;

        var fd = new FormData();
        fd.append('action', 'delete');
        fd.append('empresa_id', empresaId);
        fd.append('campo_id', id);
        fd.append('csrf_token', csrfToken);

        try {
            var r = await fetch('api/campos.php', { method: 'POST', body: fd });
            var d = await r.json();
            if (d.ok) {
                showToast('Campo eliminado', 'success');
                setTimeout(function() { location.reload(); }, 500);
            } else {
                showToast(d.error || 'Error', 'error');
            }
        } catch (e) { showToast('Error de conexión', 'error'); }
    }

    async function importCsv() {
        var input = document.getElementById('csv-import-input');
        var file = input.files[0];
        if (!file) { showToast('Selecciona un archivo CSV', 'error'); return; }

        var fd = new FormData();
        fd.append('action', 'import_csv');
        fd.append('empresa_id', empresaId);
        fd.append('csrf_token', csrfToken);
        fd.append('csv_file', file);

        try {
            var r = await fetch('api/campos.php', { method: 'POST', body: fd });
            var d = await r.json();
            if (d.ok) {
                var msg = d.imported + ' campos importados';
                if (d.errors && d.errors.length > 0) {
                    msg += ' (' + d.errors.length + ' errores)';
                }
                showToast(msg, 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showToast(d.error || 'Error', 'error');
            }
        } catch (e) { showToast('Error de conexión', 'error'); }
    }

    // Drag & Drop reordering
    (function() {
        var list = document.getElementById('campos-list');
        if (!list) return;
        var dragItem = null;

        list.addEventListener('dragstart', function(e) {
            var card = e.target.closest('.campo-card');
            if (!card) return;
            dragItem = card;
            card.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });

        list.addEventListener('dragend', function() {
            if (dragItem) dragItem.classList.remove('dragging');
            dragItem = null;
        });

        list.addEventListener('dragover', function(e) {
            e.preventDefault();
            if (!dragItem) return;
            var afterEl = getDragAfter(list, e.clientY);
            if (!afterEl) list.appendChild(dragItem);
            else list.insertBefore(dragItem, afterEl);
        });

        list.addEventListener('drop', function(e) {
            e.preventDefault();
            var cards = list.querySelectorAll('.campo-card');
            var ids = Array.from(cards).map(function(c) { return c.dataset.id; });
            saveOrder(ids.join(','));
        });

        function getDragAfter(container, y) {
            var els = [...container.querySelectorAll('.campo-card:not(.dragging)')];
            return els.reduce(function(closest, child) {
                var box = child.getBoundingClientRect();
                var offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) return { offset: offset, element: child };
                return closest;
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }

        async function saveOrder(order) {
            var fd = new FormData();
            fd.append('action', 'reorder');
            fd.append('empresa_id', empresaId);
            fd.append('order', order);
            fd.append('csrf_token', csrfToken);
            try {
                await fetch('api/campos.php', { method: 'POST', body: fd });
                showToast('Orden actualizado', 'success');
            } catch (e) {}
        }
    })();
    </script>
</body>
</html>
