<?php
/**
 * INFOCAMPO SaaS - Ajustes de Empresa (Admin)
 *
 * Permite al administrador configurar opciones de la empresa,
 * como el formato de nombre de las fotos que toman los operadores.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'superadmin']);

$pdo = getDB();

$currentPage = 'ajustes';

$empresaId = isset($_GET['empresa_id']) ? (int) $_GET['empresa_id'] : ($_SESSION['empresa_id'] ?? 0);

// ---------------------------------------------------------------
// Procesar acciones POST
// ---------------------------------------------------------------
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'guardar_formato_foto') {
        $formato = (int) ($_POST['formato_nombre_foto'] ?? 1);
        if ($formato < 1 || $formato > 3) $formato = 1;

        $targetEmpId = (int) ($_POST['empresa_id'] ?? $empresaId);
        if ($targetEmpId > 0) {
            $stmt = $pdo->prepare(
                "UPDATE empresas SET formato_nombre_foto = :formato WHERE id = :id"
            );
            $stmt->execute([':formato' => $formato, ':id' => $targetEmpId]);
            $msg = 'Formato de nombre de foto actualizado correctamente.';
            $msgType = 'success';
        }
    }

    if ($action === 'guardar_campos_operador') {
        $targetEmpId = (int) ($_POST['empresa_id'] ?? $empresaId);
        if ($targetEmpId > 0) {
            $opEmpresa   = isset($_POST['op_mostrar_empresa']) ? 1 : 0;
            $opInfra     = isset($_POST['op_mostrar_infraestructura']) ? 1 : 0;
            $opSituacion = isset($_POST['op_mostrar_situacion']) ? 1 : 0;
            $opMapa      = isset($_POST['op_mostrar_mapa']) ? 1 : 0;
            $opMapaZoom  = (int) ($_POST['op_mapa_zoom'] ?? 9);
            $allowedZooms = [9, 10, 12, 13];
            if (!in_array($opMapaZoom, $allowedZooms, true)) $opMapaZoom = 9;

            $stmt = $pdo->prepare(
                "UPDATE empresas SET op_mostrar_empresa = :emp, op_mostrar_infraestructura = :inf,
                 op_mostrar_situacion = :sit, op_mostrar_mapa = :mapa, op_mapa_zoom = :zoom WHERE id = :id"
            );
            $stmt->execute([
                ':emp'  => $opEmpresa,
                ':inf'  => $opInfra,
                ':sit'  => $opSituacion,
                ':mapa' => $opMapa,
                ':zoom' => $opMapaZoom,
                ':id'   => $targetEmpId,
            ]);
            $msg = 'Campos del operador actualizados correctamente.';
            $msgType = 'success';

            // Refresh empresa data
            $stmtR = $pdo->prepare("SELECT * FROM empresas WHERE id = :id");
            $stmtR->execute([':id' => $targetEmpId]);
            $empresa = $stmtR->fetch();
        }
    }
}

// ---------------------------------------------------------------
// Cargar empresas (para selector si es superadmin)
// ---------------------------------------------------------------
$empresas = $pdo->query(
    "SELECT id, nombre FROM empresas WHERE activa = 1 AND id != 9999 ORDER BY nombre"
)->fetchAll();

// ---------------------------------------------------------------
// Cargar datos de la empresa seleccionada
// ---------------------------------------------------------------
$empresa = null;
if ($empresaId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM empresas WHERE id = :id");
    $stmt->execute([':id' => $empresaId]);
    $empresa = $stmt->fetch();
}

$formatoActual = (int) ($empresa['formato_nombre_foto'] ?? 1);
$opEmpresa   = (int) ($empresa['op_mostrar_empresa'] ?? 0);
$opInfra     = (int) ($empresa['op_mostrar_infraestructura'] ?? 0);
$opSituacion = (int) ($empresa['op_mostrar_situacion'] ?? 0);
$opMapa      = (int) ($empresa['op_mostrar_mapa'] ?? 0);
$opMapaZoom  = (int) ($empresa['op_mapa_zoom'] ?? 9);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FotoGPS.app - Ajustes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/admin/css/admin.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h4 class="mb-0"><i class="bi bi-gear me-2"></i>Ajustes de Empresa</h4>

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
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?= $msgType ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($empresa): ?>

            <!-- Formato de nombre de foto -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-image me-2"></i>Formato de nombre de fotos</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">
                        Configura cómo se nombran las fotos que toman los operadores al subirlas a la nube.
                        El número de foto se reinicia a 1 por cada infraestructura.
                    </p>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="guardar_formato_foto">
                        <input type="hidden" name="empresa_id" value="<?= $empresaId ?>">

                        <div class="mb-4">
                            <div class="form-check mb-3 p-3 rounded <?= $formatoActual === 1 ? 'bg-primary bg-opacity-10 border border-primary' : 'border' ?>">
                                <input class="form-check-input" type="radio" name="formato_nombre_foto" id="formato1"
                                       value="1" <?= $formatoActual === 1 ? 'checked' : '' ?> onchange="updatePreview()">
                                <label class="form-check-label fw-semibold" for="formato1">
                                    Opción 1: Código Infraestructura + N° Foto
                                </label>
                                <div class="text-muted small mt-1">
                                    Ejemplo: <code>INF-001_001</code>, <code>INF-001_002</code>, <code>INF-001_003</code>
                                </div>
                            </div>

                            <div class="form-check mb-3 p-3 rounded <?= $formatoActual === 2 ? 'bg-primary bg-opacity-10 border border-primary' : 'border' ?>">
                                <input class="form-check-input" type="radio" name="formato_nombre_foto" id="formato2"
                                       value="2" <?= $formatoActual === 2 ? 'checked' : '' ?> onchange="updatePreview()">
                                <label class="form-check-label fw-semibold" for="formato2">
                                    Opción 2: Código Infraestructura + Tipo Trabajo + N° Foto
                                </label>
                                <div class="text-muted small mt-1">
                                    Ejemplo: <code>INF-001_Inspección_001</code>, <code>INF-001_Mantenimiento_001</code>
                                </div>
                            </div>

                            <div class="form-check mb-3 p-3 rounded <?= $formatoActual === 3 ? 'bg-primary bg-opacity-10 border border-primary' : 'border' ?>">
                                <input class="form-check-input" type="radio" name="formato_nombre_foto" id="formato3"
                                       value="3" <?= $formatoActual === 3 ? 'checked' : '' ?> onchange="updatePreview()">
                                <label class="form-check-label fw-semibold" for="formato3">
                                    Opción 3: Código Infraestructura + Tipo Trabajo + Tipo Foto + N° Foto
                                </label>
                                <div class="text-muted small mt-1">
                                    Ejemplo: <code>INF-001_Inspección_Aleatoria_001</code>, <code>INF-001_Inspección_Comparativa_002</code>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3 p-3 bg-light rounded">
                            <strong class="small">Vista previa del formato:</strong>
                            <div id="preview-format" class="mt-2 font-monospace" style="font-size:0.95rem;"></div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i> Guardar formato
                        </button>
                    </form>
                </div>
            </div>

            <!-- Campos visibles para el operador -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-phone me-2"></i>Campos visibles para el operador</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">
                        Selecciona qué campos e información adicional se muestran al operador en la ficha de toma de datos.
                        Por defecto, estos campos están ocultos.
                    </p>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="guardar_campos_operador">
                        <input type="hidden" name="empresa_id" value="<?= $empresaId ?>">

                        <div class="mb-4">
                            <div class="form-check form-switch mb-3 p-3 rounded border">
                                <input class="form-check-input" type="checkbox" id="op_empresa" name="op_mostrar_empresa" value="1" <?= $opEmpresa ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="op_empresa">
                                    <i class="bi bi-building me-1"></i> Empresa
                                </label>
                                <div class="text-muted small mt-1">Muestra el nombre de la empresa en la ficha del operador.</div>
                            </div>

                            <div class="form-check form-switch mb-3 p-3 rounded border">
                                <input class="form-check-input" type="checkbox" id="op_infra" name="op_mostrar_infraestructura" value="1" <?= $opInfra ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="op_infra">
                                    <i class="bi bi-geo-alt me-1"></i> Infraestructura (info detalle)
                                </label>
                                <div class="text-muted small mt-1">Muestra información adicional de la infraestructura seleccionada (tipo, coordenadas).</div>
                            </div>

                            <div class="form-check form-switch mb-3 p-3 rounded border">
                                <input class="form-check-input" type="checkbox" id="op_situacion" name="op_mostrar_situacion" value="1" <?= $opSituacion ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="op_situacion">
                                    <i class="bi bi-flag me-1"></i> Situación de la obra
                                </label>
                                <div class="text-muted small mt-1">Muestra el selector de situación (Antes / Durante / Después).</div>
                            </div>

                            <div class="form-check form-switch mb-3 p-3 rounded border">
                                <input class="form-check-input" type="checkbox" id="op_mapa" name="op_mostrar_mapa" value="1" <?= $opMapa ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="op_mapa">
                                    <i class="bi bi-map me-1"></i> Mapa de localización
                                </label>
                                <div class="text-muted small mt-1">Muestra el botón de mapa de visitas para el operador.</div>
                                <div class="mt-2 ps-4">
                                    <label class="form-label small fw-semibold text-muted mb-1">Escala inicial del mapa:</label>
                                    <select name="op_mapa_zoom" class="form-select form-select-sm" style="width:200px;">
                                        <option value="13" <?= $opMapaZoom === 13 ? 'selected' : '' ?>>1:50.000</option>
                                        <option value="12" <?= $opMapaZoom === 12 ? 'selected' : '' ?>>1:100.000</option>
                                        <option value="10" <?= $opMapaZoom === 10 ? 'selected' : '' ?>>1:250.000</option>
                                        <option value="9" <?= $opMapaZoom === 9 ? 'selected' : '' ?>>1:500.000</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i> Guardar campos operador
                        </button>
                    </form>
                </div>
            </div>

        <?php else: ?>
            <div class="text-center py-5">
                <i class="bi bi-gear" style="font-size:3rem;color:#adb5bd;"></i>
                <h5 class="mt-3 text-muted">Selecciona una empresa</h5>
                <p class="text-muted">Elige una empresa del selector para configurar sus ajustes.</p>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function updatePreview() {
        const val = document.querySelector('input[name="formato_nombre_foto"]:checked').value;
        const el = document.getElementById('preview-format');
        const examples = {
            '1': '<code>TORRE-A42_001.jpg</code> &nbsp; <code>TORRE-A42_002.jpg</code> &nbsp; <code>TORRE-A42_003.jpg</code>',
            '2': '<code>TORRE-A42_Inspección_001.jpg</code> &nbsp; <code>TORRE-A42_Mantenimiento_001.jpg</code>',
            '3': '<code>TORRE-A42_Inspección_Aleatoria_001.jpg</code> &nbsp; <code>TORRE-A42_Inspección_Comparativa_001.jpg</code>',
        };
        el.innerHTML = examples[val] || '';

        // Update visual highlighting
        document.querySelectorAll('.form-check.p-3').forEach(div => {
            div.classList.remove('bg-primary', 'bg-opacity-10', 'border-primary');
            div.classList.add('border');
        });
        const checked = document.querySelector('input[name="formato_nombre_foto"]:checked');
        if (checked) {
            const parent = checked.closest('.form-check');
            parent.classList.add('bg-primary', 'bg-opacity-10', 'border-primary');
            parent.classList.remove('border');
        }
    }
    updatePreview();
    </script>
</body>
</html>
