<?php
/**
 * INFOCAMPO SaaS - Constructor de Campos Dinámicos (Super Admin)
 *
 * Permite al superadmin crear, editar, reordenar y eliminar
 * campos personalizados del formulario de visita por empresa.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = requireRole('superadmin');

$pdo = getDB();

// ---------------------------------------------------------------
// Cargar empresas para el selector
// ---------------------------------------------------------------
$empresas = $pdo->query(
    "SELECT id, nombre FROM empresas WHERE id != 9999 AND activa = 1 ORDER BY nombre"
)->fetchAll();

$empresaId = isset($_GET['empresa_id']) ? (int) $_GET['empresa_id'] : 0;

// ---------------------------------------------------------------
// Procesar acciones POST
// ---------------------------------------------------------------
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_campo' || $action === 'update_campo') {
        $campoId     = (int) ($_POST['campo_id'] ?? 0);
        $targetEmpId = (int) ($_POST['empresa_id'] ?? 0);
        $nombre      = trim($_POST['nombre'] ?? '');
        $slug        = trim($_POST['slug'] ?? '');
        $tipo        = $_POST['tipo'] ?? 'texto';
        $opciones    = trim($_POST['opciones'] ?? '');
        $obligatorio = isset($_POST['obligatorio']) ? 1 : 0;
        $orden       = (int) ($_POST['orden'] ?? 0);

        // Generar slug automáticamente si está vacío
        if ($slug === '' && $nombre !== '') {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $nombre)));
            $slug = trim($slug, '_');
        }

        // Convertir opciones texto a JSON array
        $opcionesJson = null;
        if ($tipo === 'select' && $opciones !== '') {
            $optsArray = array_map('trim', explode("\n", $opciones));
            $optsArray = array_filter($optsArray, fn($v) => $v !== '');
            $opcionesJson = json_encode(array_values($optsArray), JSON_UNESCAPED_UNICODE);
        }

        if ($nombre === '' || $targetEmpId <= 0) {
            $msg = 'Nombre del campo y empresa son obligatorios.';
            $msgType = 'danger';
        } else {
            if ($action === 'create_campo') {
                $stmt = $pdo->prepare(
                    "INSERT INTO campos_formulario (empresa_id, nombre, slug, tipo, opciones, obligatorio, orden)
                     VALUES (:emp_id, :nombre, :slug, :tipo, :opciones, :obligatorio, :orden)"
                );
                $stmt->execute([
                    ':emp_id'      => $targetEmpId,
                    ':nombre'      => $nombre,
                    ':slug'        => $slug,
                    ':tipo'        => $tipo,
                    ':opciones'    => $opcionesJson,
                    ':obligatorio' => $obligatorio,
                    ':orden'       => $orden,
                ]);
                $msg = 'Campo creado correctamente.';
                $msgType = 'success';
                $empresaId = $targetEmpId;
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE campos_formulario SET nombre = :nombre, slug = :slug, tipo = :tipo,
                     opciones = :opciones, obligatorio = :obligatorio, orden = :orden
                     WHERE id = :id"
                );
                $stmt->execute([
                    ':nombre'      => $nombre,
                    ':slug'        => $slug,
                    ':tipo'        => $tipo,
                    ':opciones'    => $opcionesJson,
                    ':obligatorio' => $obligatorio,
                    ':orden'       => $orden,
                    ':id'          => $campoId,
                ]);
                $msg = 'Campo actualizado correctamente.';
                $msgType = 'success';
            }
        }
    }

    if ($action === 'delete_campo') {
        $campoId = (int) ($_POST['campo_id'] ?? 0);
        if ($campoId > 0) {
            $pdo->prepare("UPDATE campos_formulario SET activo = 0 WHERE id = :id")
                ->execute([':id' => $campoId]);
            $msg = 'Campo eliminado.';
            $msgType = 'info';
        }
    }

    if ($action === 'reorder') {
        $order = $_POST['order'] ?? '';
        if ($order) {
            $ids = explode(',', $order);
            foreach ($ids as $pos => $id) {
                $pdo->prepare("UPDATE campos_formulario SET orden = :orden WHERE id = :id")
                    ->execute([':orden' => $pos, ':id' => (int) $id]);
            }
            $msg = 'Orden actualizado.';
            $msgType = 'success';
        }
    }
}

// ---------------------------------------------------------------
// Cargar campos de la empresa seleccionada
// ---------------------------------------------------------------
$campos = [];
$empresaSeleccionada = null;
if ($empresaId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM empresas WHERE id = :id");
    $stmt->execute([':id' => $empresaId]);
    $empresaSeleccionada = $stmt->fetch();

    $stmt = $pdo->prepare(
        "SELECT * FROM campos_formulario
         WHERE empresa_id = :emp_id AND activo = 1
         ORDER BY orden ASC, id ASC"
    );
    $stmt->execute([':emp_id' => $empresaId]);
    $campos = $stmt->fetchAll();
}

// Si hay ?edit_campo=ID
$editCampo = null;
if (isset($_GET['edit_campo'])) {
    $ecId = (int) $_GET['edit_campo'];
    $stmt = $pdo->prepare("SELECT * FROM campos_formulario WHERE id = :id");
    $stmt->execute([':id' => $ecId]);
    $editCampo = $stmt->fetch();
    if ($editCampo) {
        $empresaId = (int) $editCampo['empresa_id'];
    }
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
    <title>FotoGPS.app - Campos Formulario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root { --sa-primary: #1e3a5f; --sa-secondary: #2d6a9f; --sa-bg: #f0f2f5; }
        body { background: var(--sa-bg); }
        .sa-sidebar {
            position: fixed; top: 0; left: 0; bottom: 0; width: 260px;
            background: linear-gradient(180deg, var(--sa-primary), #162d4a);
            color: #fff; z-index: 1000; overflow-y: auto;
        }
        .sa-sidebar .brand { padding: 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sa-sidebar .brand h2 { font-size: 1.2rem; font-weight: 800; margin: 0; }
        .sa-sidebar .brand small { opacity: 0.6; font-size: 0.75rem; }
        .sa-sidebar .nav-link {
            color: rgba(255,255,255,0.7); padding: 12px 20px; font-size: 0.9rem;
            display: flex; align-items: center; gap: 10px; transition: all 0.2s;
            border-left: 3px solid transparent;
        }
        .sa-sidebar .nav-link:hover, .sa-sidebar .nav-link.active {
            color: #fff; background: rgba(255,255,255,0.08); border-left-color: #4da6ff;
        }
        .sa-main { margin-left: 260px; padding: 24px 32px; min-height: 100vh; }
        .sa-topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; flex-wrap: wrap; gap: 12px; }
        .sa-topbar h1 { font-size: 1.5rem; font-weight: 700; color: #1f2937; margin: 0; }
        .card { border: none; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
        .form-section { background: #fff; border-radius: 12px; padding: 28px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); margin-bottom: 24px; }

        /* Campo card */
        .campo-card {
            background: #fff;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            gap: 16px;
            cursor: grab;
            transition: box-shadow 0.15s;
            border-left: 4px solid #dee2e6;
        }
        .campo-card:hover { box-shadow: 0 3px 12px rgba(0,0,0,0.1); }
        .campo-card.obligatorio { border-left-color: #dc3545; }
        .campo-card .campo-drag { color: #adb5bd; font-size: 1.2rem; cursor: grab; }
        .campo-card .campo-info { flex: 1; }
        .campo-card .campo-nombre { font-weight: 600; font-size: 0.95rem; }
        .campo-card .campo-meta { font-size: 0.78rem; color: #6b7280; }
        .campo-card .campo-actions { display: flex; gap: 6px; }
        .campo-card.dragging { opacity: 0.5; box-shadow: 0 8px 30px rgba(0,0,0,0.15); }

        /* Preview */
        .preview-form { background: #f8f9fa; border-radius: 10px; padding: 20px; border: 2px dashed #dee2e6; }
        .preview-form h6 { color: #6b7280; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; }

        @media (max-width: 767px) {
            .sa-sidebar { position: relative; width: 100%; }
            .sa-main { margin-left: 0; padding: 16px; }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <nav class="sa-sidebar">
        <div class="brand">
            <h2>FotoGPS.app</h2>
            <small>Super Administración</small>
        </div>
        <ul class="nav flex-column mt-2">
            <li><a href="index.php" class="nav-link"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
            <li><a href="empresas.php" class="nav-link"><i class="bi bi-building"></i> Empresas</a></li>
            <li><a href="usuarios.php" class="nav-link"><i class="bi bi-people"></i> Usuarios</a></li>
            <li><a href="campos.php" class="nav-link active"><i class="bi bi-ui-checks-grid"></i> Campos Formulario</a></li>
        </ul>
        <div style="position:absolute;bottom:0;width:100%;border-top:1px solid rgba(255,255,255,0.1);padding:16px 20px;">
            <a href="perfil.php" class="btn btn-sm btn-outline-light w-100 mb-2" style="font-size:0.8rem;">
                <i class="bi bi-person-gear"></i> Mi Perfil
            </a>
            <a href="logout.php" class="btn btn-sm btn-outline-light w-100" style="opacity:0.6;font-size:0.8rem;">
                <i class="bi bi-box-arrow-left"></i> Cerrar sesión
            </a>
        </div>
    </nav>

    <div class="sa-main">
        <div class="sa-topbar">
            <h1><i class="bi bi-ui-checks-grid me-2"></i>Campos del Formulario</h1>

            <!-- Selector de empresa -->
            <form method="get" class="d-flex gap-2 align-items-center">
                <label class="fw-semibold small text-nowrap">Empresa:</label>
                <select name="empresa_id" class="form-select form-select-sm" style="width:250px;" onchange="this.form.submit()">
                    <option value="">-- Seleccionar empresa --</option>
                    <?php foreach ($empresas as $emp): ?>
                        <option value="<?= $emp['id'] ?>" <?= $empresaId === (int)$emp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($emp['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?= $msgType ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($empresaId > 0 && $empresaSeleccionada): ?>

            <div class="row g-4">
                <!-- Columna izquierda: Lista de campos + Drag & Drop -->
                <div class="col-lg-7">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">
                            Campos de <strong><?= htmlspecialchars($empresaSeleccionada['nombre']) ?></strong>
                        </h5>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#formCampo">
                            <i class="bi bi-plus-lg"></i> Nuevo campo
                        </button>
                    </div>

                    <!-- Form crear/editar campo -->
                    <div class="collapse <?= $editCampo ? 'show' : '' ?>" id="formCampo">
                        <div class="form-section">
                            <h6 class="mb-3"><?= $editCampo ? 'Editar campo' : 'Nuevo campo' ?></h6>
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="<?= $editCampo ? 'update_campo' : 'create_campo' ?>">
                                <input type="hidden" name="empresa_id" value="<?= $empresaId ?>">
                                <?php if ($editCampo): ?>
                                    <input type="hidden" name="campo_id" value="<?= $editCampo['id'] ?>">
                                <?php endif; ?>

                                <div class="row g-3">
                                    <div class="col-md-5">
                                        <label class="form-label fw-semibold small">Nombre del campo *</label>
                                        <input type="text" name="nombre" class="form-control" required
                                               placeholder="Ej: Temperatura ambiente"
                                               value="<?= htmlspecialchars($editCampo['nombre'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold small">Slug (auto)</label>
                                        <input type="text" name="slug" class="form-control"
                                               placeholder="temperatura_ambiente"
                                               value="<?= htmlspecialchars($editCampo['slug'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold small">Tipo de campo</label>
                                        <select name="tipo" class="form-select" id="selectTipo" onchange="toggleOpciones()">
                                            <?php foreach ($tiposCampo as $val => $label): ?>
                                                <option value="<?= $val ?>"
                                                    <?= ($editCampo['tipo'] ?? 'texto') === $val ? 'selected' : '' ?>>
                                                    <?= $label ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6" id="divOpciones"
                                         style="<?= ($editCampo['tipo'] ?? '') !== 'select' ? 'display:none;' : '' ?>">
                                        <label class="form-label fw-semibold small">Opciones (una por línea)</label>
                                        <textarea name="opciones" class="form-control" rows="3"
                                                  placeholder="Opción 1&#10;Opción 2&#10;Opción 3"><?php
                                            if ($editCampo && $editCampo['opciones']) {
                                                $opts = json_decode($editCampo['opciones'], true);
                                                echo htmlspecialchars(implode("\n", $opts ?? []));
                                            }
                                        ?></textarea>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold small">Orden</label>
                                        <input type="number" name="orden" class="form-control" min="0"
                                               value="<?= $editCampo['orden'] ?? count($campos) ?>">
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <div class="form-check">
                                            <input type="checkbox" name="obligatorio" class="form-check-input" id="chkOblig"
                                                <?= ($editCampo['obligatorio'] ?? 0) ? 'checked' : '' ?>>
                                            <label class="form-check-label small fw-semibold" for="chkOblig">Obligatorio</label>
                                        </div>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary btn-sm w-100">
                                            <i class="bi bi-check-lg"></i> <?= $editCampo ? 'Guardar' : 'Crear' ?>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Lista de campos (drag & drop) -->
                    <div id="camposList">
                        <?php if (empty($campos)): ?>
                            <div class="text-center text-muted py-5">
                                <i class="bi bi-ui-checks-grid" style="font-size:2.5rem;opacity:0.3;"></i>
                                <p class="mt-2">No hay campos personalizados para esta empresa.<br>
                                Crea el primero con el botón "Nuevo campo".</p>
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
                                 data-id="<?= $campo['id'] ?>" draggable="true">
                                <div class="campo-drag"><i class="bi bi-grip-vertical"></i></div>
                                <div class="campo-info">
                                    <div class="campo-nombre">
                                        <?= htmlspecialchars($campo['nombre']) ?>
                                        <?php if ($campo['obligatorio']): ?>
                                            <span class="badge bg-danger" style="font-size:0.65rem;">Obligatorio</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="campo-meta">
                                        <span class="badge bg-light text-dark"><?= $tipoLabel ?></span>
                                        <span class="text-muted">slug: <?= htmlspecialchars($campo['slug']) ?></span>
                                        <?php if ($opcsList): ?>
                                            &middot; <span class="text-muted">Opciones: <?= htmlspecialchars($opcsList) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="campo-actions">
                                    <a href="?empresa_id=<?= $empresaId ?>&edit_campo=<?= $campo['id'] ?>"
                                       class="btn btn-sm btn-outline-primary" title="Editar">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar este campo?')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_campo">
                                        <input type="hidden" name="campo_id" value="<?= $campo['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Form oculto para reordenar -->
                    <form method="post" id="reorderForm" style="display:none;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="reorder">
                        <input type="hidden" name="order" id="reorderInput">
                    </form>
                </div>

                <!-- Columna derecha: Preview del formulario -->
                <div class="col-lg-5">
                    <h5 class="mb-3">Vista previa del formulario</h5>
                    <div class="preview-form">
                        <h6 class="mb-3"><i class="bi bi-eye"></i> Así verá el operador en campo</h6>

                        <!-- Campos fijos (siempre presentes) -->
                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-muted">Nivel de incidencia</label>
                            <select class="form-select form-select-sm" disabled>
                                <option>BAJO</option>
                                <option>MEDIO</option>
                                <option>CRITICO</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-muted">Observaciones</label>
                            <textarea class="form-control form-control-sm" rows="2" disabled placeholder="Observaciones técnicas..."></textarea>
                        </div>

                        <hr>
                        <h6 class="mb-3 text-primary"><i class="bi bi-stars"></i> Campos personalizados</h6>

                        <?php if (empty($campos)): ?>
                            <p class="text-muted small text-center">Sin campos personalizados</p>
                        <?php endif; ?>

                        <?php foreach ($campos as $campo): ?>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">
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
                                        echo '<select class="form-select form-select-sm" disabled>';
                                        echo '<option value="">-- Seleccionar --</option>';
                                        $opts = json_decode($campo['opciones'] ?? '[]', true) ?: [];
                                        foreach ($opts as $opt) {
                                            echo '<option>' . htmlspecialchars($opt) . '</option>';
                                        }
                                        echo '</select>';
                                        break;
                                    case 'checkbox':
                                        echo '<div class="form-check"><input type="checkbox" class="form-check-input" disabled>';
                                        echo '<label class="form-check-label small">' . htmlspecialchars($campo['nombre']) . '</label></div>';
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

        <?php else: ?>
            <div class="text-center py-5">
                <i class="bi bi-building" style="font-size:3rem;color:#adb5bd;"></i>
                <h5 class="mt-3 text-muted">Selecciona una empresa</h5>
                <p class="text-muted">Elige una empresa del selector superior para gestionar sus campos de formulario.</p>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Toggle opciones field based on tipo
    function toggleOpciones() {
        const tipo = document.getElementById('selectTipo').value;
        document.getElementById('divOpciones').style.display = tipo === 'select' ? '' : 'none';
    }

    // Drag & drop reordering
    (function() {
        const list = document.getElementById('camposList');
        if (!list) return;

        let dragItem = null;

        list.addEventListener('dragstart', function(e) {
            const card = e.target.closest('.campo-card');
            if (!card) return;
            dragItem = card;
            card.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });

        list.addEventListener('dragend', function(e) {
            if (dragItem) dragItem.classList.remove('dragging');
            dragItem = null;
        });

        list.addEventListener('dragover', function(e) {
            e.preventDefault();
            const afterElement = getDragAfterElement(list, e.clientY);
            if (dragItem) {
                if (afterElement == null) {
                    list.appendChild(dragItem);
                } else {
                    list.insertBefore(dragItem, afterElement);
                }
            }
        });

        list.addEventListener('drop', function(e) {
            e.preventDefault();
            // Save new order
            const cards = list.querySelectorAll('.campo-card');
            const ids = Array.from(cards).map(c => c.dataset.id);
            document.getElementById('reorderInput').value = ids.join(',');
            document.getElementById('reorderForm').submit();
        });

        function getDragAfterElement(container, y) {
            const elements = [...container.querySelectorAll('.campo-card:not(.dragging)')];
            return elements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                } else {
                    return closest;
                }
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }
    })();
    </script>
</body>
</html>
