<?php
/**
 * INFOCAMPO SaaS - Gestión de Infraestructuras (Admin)
 *
 * CRUD completo para que los administradores de empresa
 * gestionen sus infraestructuras directamente.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'supervisor', 'superadmin']);

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
        $monte     = trim($_POST['monte'] ?? '');
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
                        "INSERT INTO infraestructuras (empresa_id, nombre, codigo_unico, lat_teorica, lon_teorica, tipo, provincia, municipio, monte, descripcion, activa)
                         VALUES (:emp_id, :nombre, :codigo, :lat, :lon, :tipo, :provincia, :municipio, :monte, :desc, 1)"
                    );
                    $stmt->execute([
                        ':emp_id' => $empresaId, ':nombre' => $nombre, ':codigo' => $codigo,
                        ':lat' => $lat, ':lon' => $lon, ':tipo' => $tipo ?: null,
                        ':provincia' => $provincia ?: null, ':municipio' => $municipio ?: null,
                        ':monte' => $monte ?: null, ':desc' => $desc ?: null,
                    ]);
                    $msg = 'Infraestructura creada correctamente.';
                    $msgType = 'success';
                }
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE infraestructuras SET nombre = :nombre, codigo_unico = :codigo,
                     lat_teorica = :lat, lon_teorica = :lon, tipo = :tipo,
                     provincia = :provincia, municipio = :municipio, monte = :monte, descripcion = :desc
                     WHERE id = :id AND empresa_id = :emp_id"
                );
                $stmt->execute([
                    ':nombre' => $nombre, ':codigo' => $codigo,
                    ':lat' => $lat, ':lon' => $lon, ':tipo' => $tipo ?: null,
                    ':provincia' => $provincia ?: null, ':municipio' => $municipio ?: null,
                    ':monte' => $monte ?: null, ':desc' => $desc ?: null,
                    ':id' => $id, ':emp_id' => $empresaId,
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

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && $empresaId > 0) {
            $stmt = $pdo->prepare("SELECT id FROM infraestructuras WHERE id = :id AND empresa_id = :emp_id");
            $stmt->execute([':id' => $id, ':emp_id' => $empresaId]);
            if ($stmt->fetch()) {
                // Eliminar valores_campo de registros asociados, luego registros, luego infraestructura
                $pdo->prepare("DELETE FROM valores_campo WHERE registro_id IN (SELECT id FROM registros WHERE infra_id = :iid)")
                    ->execute([':iid' => $id]);
                $pdo->prepare("DELETE FROM registros WHERE infra_id = :iid")
                    ->execute([':iid' => $id]);
                $pdo->prepare("DELETE FROM infraestructuras WHERE id = :id AND empresa_id = :emp_id")
                    ->execute([':id' => $id, ':emp_id' => $empresaId]);
                $msg = 'Infraestructura eliminada correctamente.';
                $msgType = 'success';
            } else {
                $msg = 'No se pudo eliminar la infraestructura.';
                $msgType = 'danger';
            }
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
        "SELECT i.*,
                (SELECT COUNT(*) FROM registros r WHERE r.infra_id = i.id) AS num_registros,
                (SELECT COUNT(*) FROM registros r WHERE r.infra_id = i.id AND r.estado_incidencia = 'durante') AS num_durante,
                (SELECT r2.estado_incidencia FROM registros r2 WHERE r2.infra_id = i.id ORDER BY r2.fecha DESC LIMIT 1) AS ultimo_estado,
                (SELECT r3.fecha FROM registros r3 WHERE r3.infra_id = i.id ORDER BY r3.fecha DESC LIMIT 1) AS ultima_inspeccion
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
    <title>FotoGPS.app - Gestión de Infraestructuras</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/admin/css/admin.css" rel="stylesheet">
    <style>
        .infra-card {
            background: #fff; border-radius: 12px; padding: 18px 22px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06); margin-bottom: 12px;
            border-left: 4px solid #2d6a9f; transition: transform 0.1s;
        }
        .infra-card:hover { transform: translateX(2px); }
        .infra-card.inactive { opacity: 0.5; border-left-color: #d1d5db; }
        .infra-card.status-despues { border-left-color: #22c55e; }
        .infra-card.status-durante { border-left-color: #f59e0b; }
        .infra-card.status-antes { border-left-color: #3b82f6; }
        .infra-card.status-none { border-left-color: #d1d5db; }
        .tipo-badge { font-size: 0.65rem; padding: 3px 8px; border-radius: 6px; background: #e0e7ff; color: #4338ca; }
        .coord-text { font-size: 0.75rem; color: #6b7280; font-family: monospace; }
        .status-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
        .status-dot.antes { background: #3b82f6; }
        .status-dot.durante { background: #f59e0b; animation: pulse-durante 2s infinite; }
        .status-dot.despues { background: #22c55e; }
        .status-dot.none { background: #d1d5db; }
        @keyframes pulse-durante { 0%, 100% { box-shadow: 0 0 0 0 rgba(245,158,11,0.4); } 50% { box-shadow: 0 0 0 6px rgba(245,158,11,0); } }
        .durante-badge { font-size: 0.6rem; padding: 2px 6px; border-radius: 4px; background: #fef3c7; color: #d97706; font-weight: 700; }
        .time-ago { font-size: 0.7rem; color: #9ca3af; }
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
                    <a href="exportar_csv.php?tipo=infraestructuras&empresa_id=<?= $empresaId ?>" class="btn btn-outline-secondary btn-sm" title="Exportar a CSV/Excel">
                        <i class="bi bi-file-earmark-spreadsheet"></i> Exportar CSV
                    </a>
                    <button class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalExcel">
                        <i class="bi bi-file-earmark-spreadsheet"></i> Importar Excel
                    </button>
                    <button class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalKml">
                        <i class="bi bi-file-earmark-arrow-up"></i> Importar KML
                    </button>
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
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Monte</label>
                            <input type="text" name="monte" class="form-control"
                                   placeholder="Nombre del monte"
                                   value="<?= htmlspecialchars($editInfra['monte'] ?? '') ?>">
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
            <?php
            $lastStatus = $inf['ultimo_estado'] ?? 'none';
            $statusClass = $inf['activa'] ? 'status-' . $lastStatus : 'inactive';
            $ultimaFecha = $inf['ultima_inspeccion'] ?? null;
            $diasSinInspeccion = $ultimaFecha ? (int)((time() - strtotime($ultimaFecha)) / 86400) : null;
            ?>
            <div class="infra-card <?= $statusClass ?>">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="status-dot <?= $lastStatus ?>" title="Último estado: <?= $lastStatus === 'none' ? 'sin inspecciones' : $lastStatus ?>"></span>
                            <strong><?= htmlspecialchars($inf['nombre']) ?></strong>
                            <code class="small" style="color:#2d6a9f;"><?= htmlspecialchars($inf['codigo_unico']) ?></code>
                            <?php if ($inf['tipo']): ?>
                                <span class="tipo-badge"><?= htmlspecialchars($inf['tipo']) ?></span>
                            <?php endif; ?>
                            <?php if (!$inf['activa']): ?>
                                <span class="badge bg-danger" style="font-size:0.65rem;">Inactiva</span>
                            <?php endif; ?>
                            <?php if ((int)($inf['num_durante'] ?? 0) > 0): ?>
                                <span class="durante-badge"><i class="bi bi-clock-history"></i> <?= $inf['num_durante'] ?> durante</span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-3 flex-wrap align-items-center">
                            <?php if (!empty($inf['provincia']) || !empty($inf['municipio']) || !empty($inf['monte'])): ?>
                                <span class="small text-muted">
                                    <i class="bi bi-pin-map"></i>
                                    <?= htmlspecialchars(trim(implode(', ', array_filter([
                                        $inf['monte'] ?? '',
                                        $inf['municipio'] ?? '',
                                        $inf['provincia'] ?? ''
                                    ])), ', ')) ?>
                                </span>
                            <?php endif; ?>
                            <span class="coord-text">
                                <i class="bi bi-geo-alt"></i>
                                <?= $inf['lat_teorica'] ?>, <?= $inf['lon_teorica'] ?>
                            </span>
                            <span class="small text-muted">
                                <i class="bi bi-camera"></i> <?= $inf['num_registros'] ?> inspecciones
                            </span>
                            <?php if ($ultimaFecha): ?>
                                <span class="time-ago" title="<?= date('d/m/Y H:i', strtotime($ultimaFecha)) ?>">
                                    <i class="bi bi-clock"></i>
                                    <?php if ($diasSinInspeccion === 0): ?>
                                        Hoy
                                    <?php elseif ($diasSinInspeccion === 1): ?>
                                        Ayer
                                    <?php elseif ($diasSinInspeccion < 30): ?>
                                        Hace <?= $diasSinInspeccion ?> días
                                    <?php else: ?>
                                        <?= date('d/m/Y', strtotime($ultimaFecha)) ?>
                                    <?php endif; ?>
                                </span>
                            <?php else: ?>
                                <span class="time-ago"><i class="bi bi-clock"></i> Sin inspecciones</span>
                            <?php endif; ?>
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
                        <form method="post" class="d-inline" onsubmit="return confirm('¿ELIMINAR esta infraestructura permanentemente? Se borrarán también todos sus registros e inspecciones (<?= $inf['num_registros'] ?>). Esta acción no se puede deshacer.')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $inf['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar infraestructura">
                                <i class="bi bi-trash"></i>
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

    <!-- Modal Importar Excel -->
    <?php if ($empresaId > 0): ?>
    <div class="modal fade" id="modalExcel" tabindex="-1" aria-labelledby="modalExcelLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #1e5f3a, #2d9f6a); color: #fff;">
                    <h5 class="modal-title" id="modalExcelLabel">
                        <i class="bi bi-file-earmark-spreadsheet me-2"></i>Importar Infraestructuras desde Excel
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Sube un archivo <strong>.xlsx</strong>, <strong>.xls</strong> o <strong>.csv</strong> con las infraestructuras.
                        <strong>No es necesario incluir coordenadas GPS</strong>; se georreferenciarán cuando el operador tome fotos en campo.
                        <br><br>
                        <strong>Columnas reconocidas:</strong>
                        <ul class="mb-0 mt-1">
                            <li><strong>nombre</strong> (obligatorio)</li>
                            <li>codigo, tipo, provincia, municipio, monte, descripción</li>
                            <li>lat, lon (opcionales)</li>
                        </ul>
                    </div>

                    <form id="form-excel" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="empresa_id" value="<?= $empresaId ?>">

                        <div class="mb-3">
                            <label for="archivo_excel" class="form-label fw-semibold">Archivo Excel / CSV</label>
                            <input type="file" class="form-control" id="archivo_excel" name="archivo_excel"
                                   accept=".xlsx,.xls,.csv" required>
                            <div class="form-text">Tamaño máximo: 10 MB</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Duplicados</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="duplicados" id="excel_dup_omitir" value="omitir" checked>
                                <label class="form-check-label" for="excel_dup_omitir">
                                    Omitir duplicados (nombre o código coincidentes)
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="duplicados" id="excel_dup_importar" value="importar">
                                <label class="form-check-label" for="excel_dup_importar">
                                    Importar todos (pueden crearse duplicados)
                                </label>
                            </div>
                        </div>
                    </form>

                    <!-- Resultado -->
                    <div id="excel-result" style="display:none;" class="mt-3"></div>

                    <!-- Progreso -->
                    <div id="excel-progress" style="display:none;" class="mt-3">
                        <div class="progress">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width:100%">
                                Importando...
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-success" id="btn-importar-excel" onclick="importarExcel()">
                        <i class="bi bi-cloud-upload me-1"></i>Importar
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal Importar KML -->
    <?php if ($empresaId > 0): ?>
    <div class="modal fade" id="modalKml" tabindex="-1" aria-labelledby="modalKmlLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #1e3a5f, #2d6a9f); color: #fff;">
                    <h5 class="modal-title" id="modalKmlLabel">
                        <i class="bi bi-file-earmark-arrow-up me-2"></i>Importar Infraestructuras desde KML
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Sube un archivo <strong>.kml</strong> o <strong>.kmz</strong> con puntos (Placemarks).
                        Cada punto se importará como una infraestructura con sus coordenadas.
                        <br>
                        <strong>Campos reconocidos:</strong> nombre, descripción, coordenadas, y datos extendidos
                        (tipo, provincia, municipio, monte).
                    </div>

                    <form id="form-kml" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="empresa_id" value="<?= $empresaId ?>">

                        <div class="mb-3">
                            <label for="archivo_kml" class="form-label fw-semibold">Archivo KML / KMZ</label>
                            <input type="file" class="form-control" id="archivo_kml" name="archivo_kml"
                                   accept=".kml,.kmz" required>
                            <div class="form-text">Tamaño máximo: 10 MB</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Duplicados</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="duplicados" id="dup_omitir" value="omitir" checked>
                                <label class="form-check-label" for="dup_omitir">
                                    Omitir duplicados (nombre o coordenadas coincidentes)
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="duplicados" id="dup_importar" value="importar">
                                <label class="form-check-label" for="dup_importar">
                                    Importar todos (pueden crearse duplicados)
                                </label>
                            </div>
                        </div>
                    </form>

                    <!-- Previsualización del mapa -->
                    <div id="kml-preview-section" style="display:none;">
                        <hr>
                        <h6><i class="bi bi-eye me-1"></i>Previsualización</h6>
                        <div id="kml-preview-map" style="height: 300px; border-radius: 10px; border: 2px solid #e5e7eb;"></div>
                        <div id="kml-preview-stats" class="mt-2 small text-muted"></div>
                    </div>

                    <!-- Resultado -->
                    <div id="kml-result" style="display:none;" class="mt-3"></div>

                    <!-- Progreso -->
                    <div id="kml-progress" style="display:none;" class="mt-3">
                        <div class="progress">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width:100%">
                                Importando...
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-success" id="btn-importar-kml" onclick="importarKml()">
                        <i class="bi bi-cloud-upload me-1"></i>Importar
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

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

        // ---------------------------------------------------------------
        // KML Import
        // ---------------------------------------------------------------
        let kmlPreviewMap = null;
        let kmlPreviewMarkers = [];

        // Previsualización al seleccionar archivo
        document.getElementById('archivo_kml')?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;

            const ext = file.name.split('.').pop().toLowerCase();
            if (ext === 'kml') {
                const reader = new FileReader();
                reader.onload = function(ev) {
                    previewKml(ev.target.result);
                };
                reader.readAsText(file);
            } else if (ext === 'kmz') {
                // No podemos previsualizar KMZ en el navegador fácilmente
                document.getElementById('kml-preview-section').style.display = 'none';
                document.getElementById('kml-preview-stats').textContent = '';
            }
        });

        function previewKml(kmlText) {
            const parser = new DOMParser();
            const xmlDoc = parser.parseFromString(kmlText, 'text/xml');

            const placemarks = xmlDoc.querySelectorAll('Placemark');
            if (placemarks.length === 0) {
                document.getElementById('kml-preview-section').style.display = 'none';
                return;
            }

            document.getElementById('kml-preview-section').style.display = 'block';

            // Inicializar mapa de previsualización
            if (!kmlPreviewMap) {
                kmlPreviewMap = L.map('kml-preview-map').setView([40.416775, -3.703790], 6);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OSM', maxZoom: 19,
                }).addTo(kmlPreviewMap);
            }

            // Limpiar marcadores previos
            kmlPreviewMarkers.forEach(m => kmlPreviewMap.removeLayer(m));
            kmlPreviewMarkers = [];

            const bounds = [];
            let pointCount = 0;

            placemarks.forEach(pm => {
                let lat = null, lon = null;

                // 1) Intentar Point
                const pointCoord = pm.querySelector('Point coordinates');
                if (pointCoord) {
                    const parts = pointCoord.textContent.trim().split(',');
                    if (parts.length >= 2) {
                        lon = parseFloat(parts[0]);
                        lat = parseFloat(parts[1]);
                    }
                }

                // 2) Si no hay Point, intentar LineString o Polygon (centroide)
                if (lat === null || isNaN(lat)) {
                    let coordEl = pm.querySelector('LineString coordinates');
                    if (!coordEl) coordEl = pm.querySelector('Polygon coordinates');

                    if (coordEl) {
                        const allPoints = coordEl.textContent.trim().split(/\s+/);
                        let sumLat = 0, sumLon = 0, count = 0;
                        allPoints.forEach(pt => {
                            const parts = pt.split(',');
                            if (parts.length >= 2) {
                                const pLon = parseFloat(parts[0]);
                                const pLat = parseFloat(parts[1]);
                                if (!isNaN(pLat) && !isNaN(pLon)) {
                                    sumLon += pLon;
                                    sumLat += pLat;
                                    count++;
                                }
                            }
                        });
                        if (count > 0) {
                            lon = sumLon / count;
                            lat = sumLat / count;
                        }
                    }
                }

                if (lat === null || lon === null || isNaN(lat) || isNaN(lon)) return;

                const nameEl = pm.querySelector('name');
                const nombre = nameEl ? nameEl.textContent.trim() : 'Sin nombre';

                const marker = L.marker([lat, lon])
                    .bindPopup('<strong>' + nombre + '</strong><br><small>' + lat.toFixed(6) + ', ' + lon.toFixed(6) + '</small>')
                    .addTo(kmlPreviewMap);

                kmlPreviewMarkers.push(marker);
                bounds.push([lat, lon]);
                pointCount++;
            });

            if (bounds.length > 0) {
                kmlPreviewMap.fitBounds(bounds, { padding: [30, 30], maxZoom: 14 });
            }

            document.getElementById('kml-preview-stats').innerHTML =
                '<i class="bi bi-geo-alt"></i> <strong>' + pointCount + '</strong> punto' + (pointCount !== 1 ? 's' : '') +
                ' encontrado' + (pointCount !== 1 ? 's' : '') + ' en el archivo';

            setTimeout(() => kmlPreviewMap.invalidateSize(), 200);
        }

        async function importarKml() {
            const form = document.getElementById('form-kml');
            const fileInput = document.getElementById('archivo_kml');
            const resultDiv = document.getElementById('kml-result');
            const progressDiv = document.getElementById('kml-progress');
            const btnImportar = document.getElementById('btn-importar-kml');

            if (!fileInput.files[0]) {
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Selecciona un archivo KML o KMZ</div>';
                return;
            }

            // Mostrar progreso
            progressDiv.style.display = 'block';
            resultDiv.style.display = 'none';
            btnImportar.disabled = true;
            btnImportar.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importando...';

            const formData = new FormData(form);

            try {
                const resp = await fetch('importar_kml.php', {
                    method: 'POST',
                    body: formData,
                });

                const data = await resp.json();
                progressDiv.style.display = 'none';
                resultDiv.style.display = 'block';

                if (data.ok) {
                    let html = '<div class="alert alert-success">' +
                        '<i class="bi bi-check-circle me-1"></i>' +
                        '<strong>' + data.importados + '</strong> infraestructura' + (data.importados !== 1 ? 's' : '') + ' importada' + (data.importados !== 1 ? 's' : '') + ' correctamente.';

                    if (data.omitidos > 0) {
                        html += '<br><small>' + data.omitidos + ' omitida' + (data.omitidos !== 1 ? 's' : '') + ' (duplicadas o sin coordenadas)</small>';
                    }

                    if (data.errores && data.errores.length > 0) {
                        html += '<br><small class="text-warning">' + data.errores.join('<br>') + '</small>';
                    }

                    html += '</div>';

                    // Tabla de detalles
                    if (data.detalles && data.detalles.length > 0) {
                        html += '<div style="max-height: 200px; overflow-y: auto;">';
                        html += '<table class="table table-sm table-striped small">';
                        html += '<thead><tr><th>Nombre</th><th>Código</th><th>Lat</th><th>Lon</th></tr></thead><tbody>';
                        data.detalles.forEach(d => {
                            html += '<tr><td>' + d.nombre + '</td><td><code>' + d.codigo + '</code></td>' +
                                    '<td>' + d.lat.toFixed(6) + '</td><td>' + d.lon.toFixed(6) + '</td></tr>';
                        });
                        html += '</tbody></table></div>';
                    }

                    resultDiv.innerHTML = html;

                    // Recargar la página tras 2 segundos para mostrar las nuevas infraestructuras
                    if (data.importados > 0) {
                        setTimeout(() => location.reload(), 2500);
                    }
                } else {
                    resultDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i>' + (data.error || 'Error desconocido') + '</div>';
                }
            } catch (err) {
                progressDiv.style.display = 'none';
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i>Error de conexión: ' + err.message + '</div>';
            }

            btnImportar.disabled = false;
            btnImportar.innerHTML = '<i class="bi bi-cloud-upload me-1"></i>Importar';
        }

        // ---------------------------------------------------------------
        // Excel Import
        // ---------------------------------------------------------------
        async function importarExcel() {
            const form = document.getElementById('form-excel');
            const fileInput = document.getElementById('archivo_excel');
            const resultDiv = document.getElementById('excel-result');
            const progressDiv = document.getElementById('excel-progress');
            const btnImportar = document.getElementById('btn-importar-excel');

            if (!fileInput.files[0]) {
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Selecciona un archivo Excel o CSV</div>';
                return;
            }

            progressDiv.style.display = 'block';
            resultDiv.style.display = 'none';
            btnImportar.disabled = true;
            btnImportar.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importando...';

            const formData = new FormData(form);

            try {
                const resp = await fetch('importar_excel.php', {
                    method: 'POST',
                    body: formData,
                });

                const data = await resp.json();
                progressDiv.style.display = 'none';
                resultDiv.style.display = 'block';

                if (data.ok) {
                    let html = '<div class="alert alert-success">' +
                        '<i class="bi bi-check-circle me-1"></i>' +
                        '<strong>' + data.importados + '</strong> infraestructura' + (data.importados !== 1 ? 's' : '') + ' importada' + (data.importados !== 1 ? 's' : '') + ' correctamente.';

                    if (data.omitidos > 0) {
                        html += '<br><small>' + data.omitidos + ' omitida' + (data.omitidos !== 1 ? 's' : '') + ' (duplicadas o sin nombre)</small>';
                    }

                    if (data.errores && data.errores.length > 0) {
                        html += '<br><small class="text-warning">' + data.errores.join('<br>') + '</small>';
                    }

                    html += '</div>';

                    if (data.detalles && data.detalles.length > 0) {
                        html += '<div style="max-height: 200px; overflow-y: auto;">';
                        html += '<table class="table table-sm table-striped small">';
                        html += '<thead><tr><th>Nombre</th><th>Código</th><th>Provincia</th><th>Municipio</th><th>Monte</th></tr></thead><tbody>';
                        data.detalles.forEach(d => {
                            html += '<tr><td>' + (d.nombre || '') + '</td><td><code>' + (d.codigo || '') + '</code></td>' +
                                    '<td>' + (d.provincia || '-') + '</td><td>' + (d.municipio || '-') + '</td><td>' + (d.monte || '-') + '</td></tr>';
                        });
                        html += '</tbody></table></div>';
                    }

                    resultDiv.innerHTML = html;

                    if (data.importados > 0) {
                        setTimeout(() => location.reload(), 2500);
                    }
                } else {
                    resultDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i>' + (data.error || 'Error desconocido') + '</div>';
                }
            } catch (err) {
                progressDiv.style.display = 'none';
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i>Error de conexión: ' + err.message + '</div>';
            }

            btnImportar.disabled = false;
            btnImportar.innerHTML = '<i class="bi bi-cloud-upload me-1"></i>Importar';
        }

        // Limpiar modal Excel al cerrar
        document.getElementById('modalExcel')?.addEventListener('hidden.bs.modal', function() {
            document.getElementById('excel-result').style.display = 'none';
            document.getElementById('excel-progress').style.display = 'none';
            document.getElementById('archivo_excel').value = '';
        });

        // Reinicializar preview al abrir el modal
        document.getElementById('modalKml')?.addEventListener('shown.bs.modal', function() {
            if (kmlPreviewMap) {
                setTimeout(() => kmlPreviewMap.invalidateSize(), 200);
            }
        });

        // Limpiar al cerrar el modal
        document.getElementById('modalKml')?.addEventListener('hidden.bs.modal', function() {
            document.getElementById('kml-result').style.display = 'none';
            document.getElementById('kml-progress').style.display = 'none';
            document.getElementById('kml-preview-section').style.display = 'none';
            document.getElementById('archivo_kml').value = '';
        });
    </script>
</body>
</html>
