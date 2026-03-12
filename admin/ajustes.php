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

$empresaId = getEmpresaIdSeguro();

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

    if ($action === 'guardar_watermark') {
        $targetEmpId = (int) ($_POST['empresa_id'] ?? $empresaId);
        if ($targetEmpId > 0) {
            // Base fields
            $wmFecha       = isset($_POST['wm_mostrar_fecha']) ? 1 : 0;
            $wmCoordenadas = isset($_POST['wm_mostrar_coordenadas']) ? 1 : 0;
            $wmOrientacion = isset($_POST['wm_mostrar_orientacion']) ? 1 : 0;
            $wmUbicacion   = isset($_POST['wm_mostrar_ubicacion']) ? 1 : 0;
            $wmPais        = isset($_POST['wm_mostrar_pais']) ? 1 : 0;
            $wmBrujula     = isset($_POST['wm_mostrar_brujula']) ? 1 : 0;
            // Optional fields
            $wmCodigoInfra = isset($_POST['wm_mostrar_codigo_infra']) ? 1 : 0;
            $wmSituacion   = isset($_POST['wm_mostrar_situacion']) ? 1 : 0;
            $wmTipoFoto    = isset($_POST['wm_mostrar_tipo_foto']) ? 1 : 0;
            $wmMapa        = isset($_POST['wm_mostrar_mapa']) ? 1 : 0;
            $wmMapaZoom    = (int) ($_POST['wm_mapa_zoom'] ?? 15);
            $wmMapaTamano  = (int) ($_POST['wm_mapa_tamano'] ?? 2);
            $wmTextoTamano = (int) ($_POST['wm_texto_tamano'] ?? 2);

            $allowedZooms = [13, 14, 15, 16, 17];
            if (!in_array($wmMapaZoom, $allowedZooms, true)) $wmMapaZoom = 15;
            $allowedSizes = [1, 2, 3];
            if (!in_array($wmMapaTamano, $allowedSizes, true)) $wmMapaTamano = 2;
            $allowedTextSizes = [1, 2, 3, 4];
            if (!in_array($wmTextoTamano, $allowedTextSizes, true)) $wmTextoTamano = 2;

            $stmt = $pdo->prepare(
                "UPDATE empresas SET
                 wm_mostrar_fecha = :fecha, wm_mostrar_coordenadas = :coord,
                 wm_mostrar_orientacion = :orient, wm_mostrar_ubicacion = :ubic,
                 wm_mostrar_pais = :pais, wm_mostrar_brujula = :bruj,
                 wm_mostrar_codigo_infra = :ci, wm_mostrar_situacion = :sit,
                 wm_mostrar_tipo_foto = :tf, wm_mostrar_mapa = :mapa,
                 wm_mapa_zoom = :zoom, wm_mapa_tamano = :tam, wm_texto_tamano = :txt
                 WHERE id = :id"
            );
            $stmt->execute([
                ':fecha'  => $wmFecha,
                ':coord'  => $wmCoordenadas,
                ':orient' => $wmOrientacion,
                ':ubic'   => $wmUbicacion,
                ':pais'   => $wmPais,
                ':bruj'   => $wmBrujula,
                ':ci'     => $wmCodigoInfra,
                ':sit'    => $wmSituacion,
                ':tf'     => $wmTipoFoto,
                ':mapa'   => $wmMapa,
                ':zoom'   => $wmMapaZoom,
                ':tam'    => $wmMapaTamano,
                ':txt'    => $wmTextoTamano,
                ':id'     => $targetEmpId,
            ]);
            $msg = 'Configuración de marca de agua actualizada correctamente.';
            $msgType = 'success';

            $stmtR = $pdo->prepare("SELECT * FROM empresas WHERE id = :id");
            $stmtR->execute([':id' => $targetEmpId]);
            $empresa = $stmtR->fetch();
        }
    }

    if ($action === 'guardar_campos_operador') {
        $targetEmpId = (int) ($_POST['empresa_id'] ?? $empresaId);
        if ($targetEmpId > 0) {
            $opEmpresa   = isset($_POST['op_mostrar_empresa']) ? 1 : 0;
            $opInfra     = isset($_POST['op_mostrar_infraestructura']) ? 1 : 0;
            $opSituacion = isset($_POST['op_mostrar_situacion']) ? 1 : 0;
            $opMapa      = isset($_POST['op_mostrar_mapa']) ? 1 : 0;
            $opCapasInfra = isset($_POST['op_mostrar_capas_infra']) ? 1 : 0;
            $opMapaZoom  = (int) ($_POST['op_mapa_zoom'] ?? 9);
            $allowedZooms = [9, 10, 12, 13];
            if (!in_array($opMapaZoom, $allowedZooms, true)) $opMapaZoom = 9;

            $stmt = $pdo->prepare(
                "UPDATE empresas SET op_mostrar_empresa = :emp, op_mostrar_infraestructura = :inf,
                 op_mostrar_situacion = :sit, op_mostrar_mapa = :mapa, op_mostrar_capas_infra = :capas, op_mapa_zoom = :zoom WHERE id = :id"
            );
            $stmt->execute([
                ':emp'   => $opEmpresa,
                ':inf'   => $opInfra,
                ':sit'   => $opSituacion,
                ':mapa'  => $opMapa,
                ':capas' => $opCapasInfra,
                ':zoom'  => $opMapaZoom,
                ':id'    => $targetEmpId,
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
// Cargar empresas (solo superadmin)
// ---------------------------------------------------------------
$isSuperadmin = ($_SESSION['user_role'] ?? '') === 'superadmin';
if ($isSuperadmin) {
    $empresas = $pdo->query(
        "SELECT id, nombre FROM empresas WHERE activa = 1 AND id != 9999 ORDER BY nombre"
    )->fetchAll();
} else {
    $empresas = [];
}

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
$opCapasInfra = (int) ($empresa['op_mostrar_capas_infra'] ?? 0);
$opMapaZoom  = (int) ($empresa['op_mapa_zoom'] ?? 9);

// Watermark settings — base fields (default ON)
$wmFecha       = (int) ($empresa['wm_mostrar_fecha'] ?? 1);
$wmCoordenadas = (int) ($empresa['wm_mostrar_coordenadas'] ?? 1);
$wmOrientacion = (int) ($empresa['wm_mostrar_orientacion'] ?? 1);
$wmUbicacion   = (int) ($empresa['wm_mostrar_ubicacion'] ?? 1);
$wmPais        = (int) ($empresa['wm_mostrar_pais'] ?? 1);
$wmBrujula     = (int) ($empresa['wm_mostrar_brujula'] ?? 1);
// Watermark settings — optional fields (default OFF)
$wmCodigoInfra = (int) ($empresa['wm_mostrar_codigo_infra'] ?? 0);
$wmSituacion   = (int) ($empresa['wm_mostrar_situacion'] ?? 0);
$wmTipoFoto    = (int) ($empresa['wm_mostrar_tipo_foto'] ?? 0);
$wmMapa        = (int) ($empresa['wm_mostrar_mapa'] ?? 0);
$wmMapaZoom    = (int) ($empresa['wm_mapa_zoom'] ?? 15);
$wmMapaTamano  = (int) ($empresa['wm_mapa_tamano'] ?? 2);
$wmTextoTamano = (int) ($empresa['wm_texto_tamano'] ?? 2);
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

                            <div class="form-check form-switch mb-3 p-3 rounded border">
                                <input class="form-check-input" type="checkbox" id="op_capas_infra" name="op_mostrar_capas_infra" value="1" <?= $opCapasInfra ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="op_capas_infra">
                                    <i class="bi bi-layers me-1"></i> Capas de infraestructuras en mapa
                                </label>
                                <div class="text-muted small mt-1">Permite al operador ver las capas GeoJSON de infraestructuras en el mapa de visitas.</div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i> Guardar campos operador
                        </button>
                    </form>
                </div>
            </div>

            <!-- Marca de agua en fotos -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-badge-wc me-2"></i>Marca de agua en fotos</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-2">
                        Configura qué información adicional aparece sobre las fotos del operador.
                    </p>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="guardar_watermark">
                        <input type="hidden" name="empresa_id" value="<?= $empresaId ?>">

                        <div class="mb-4">
                            <!-- Tamaño del texto -->
                            <div class="mb-3 p-3 rounded border">
                                <label class="fw-semibold d-block mb-2">
                                    <i class="bi bi-fonts me-1"></i> Tamaño del texto
                                </label>
                                <div class="text-muted small mb-2">Ajusta el tamaño de la información sobreimpresa en las fotos.</div>
                                <div class="d-flex gap-2 flex-wrap">
                                    <?php
                                    $textSizes = [
                                        1 => ['label' => 'Pequeño', 'desc' => 'Discreto'],
                                        2 => ['label' => 'Mediano', 'desc' => 'Por defecto'],
                                        3 => ['label' => 'Grande', 'desc' => '50% más grande'],
                                        4 => ['label' => 'Muy grande', 'desc' => 'Doble tamaño'],
                                    ];
                                    foreach ($textSizes as $val => $info):
                                    ?>
                                    <label class="btn btn-outline-primary btn-sm <?= $wmTextoTamano === $val ? 'active' : '' ?>" style="min-width:100px;">
                                        <input type="radio" name="wm_texto_tamano" value="<?= $val ?>" class="btn-check" <?= $wmTextoTamano === $val ? 'checked' : '' ?>>
                                        <strong><?= $info['label'] ?></strong><br>
                                        <span class="small" style="font-size:0.7rem;"><?= $info['desc'] ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Información base -->
                            <h6 class="text-muted fw-bold small text-uppercase mt-4 mb-3">
                                <i class="bi bi-eye me-1"></i> Información base
                            </h6>

                            <div class="form-check form-switch mb-2 p-3 rounded border">
                                <input class="form-check-input" type="checkbox" id="wm_fecha" name="wm_mostrar_fecha" value="1" <?= $wmFecha ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="wm_fecha">
                                    <i class="bi bi-clock me-1"></i> Fecha y hora
                                </label>
                                <div class="text-muted small mt-1">Ej: <code>24 feb 2026 18:46:04</code> (zona horaria Madrid)</div>
                            </div>

                            <div class="form-check form-switch mb-2 p-3 rounded border">
                                <input class="form-check-input" type="checkbox" id="wm_coordenadas" name="wm_mostrar_coordenadas" value="1" <?= $wmCoordenadas ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="wm_coordenadas">
                                    <i class="bi bi-crosshair me-1"></i> Coordenadas UTM
                                </label>
                                <div class="text-muted small mt-1">Ej: <code>30S 445357 4117173</code></div>
                            </div>

                            <div class="form-check form-switch mb-2 p-3 rounded border">
                                <input class="form-check-input" type="checkbox" id="wm_orientacion" name="wm_mostrar_orientacion" value="1" <?= $wmOrientacion ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="wm_orientacion">
                                    <i class="bi bi-compass me-1"></i> Orientación
                                </label>
                                <div class="text-muted small mt-1">Ej: <code>99° E</code></div>
                            </div>

                            <div class="form-check form-switch mb-2 p-3 rounded border">
                                <input class="form-check-input" type="checkbox" id="wm_ubicacion" name="wm_mostrar_ubicacion" value="1" <?= $wmUbicacion ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="wm_ubicacion">
                                    <i class="bi bi-geo me-1"></i> Municipio, provincia y CP
                                </label>
                                <div class="text-muted small mt-1">Ej: <code>Granada, Granada 18014</code></div>
                            </div>

                            <div class="form-check form-switch mb-2 p-3 rounded border">
                                <input class="form-check-input" type="checkbox" id="wm_pais" name="wm_mostrar_pais" value="1" <?= $wmPais ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="wm_pais">
                                    <i class="bi bi-globe me-1"></i> País
                                </label>
                                <div class="text-muted small mt-1">Ej: <code>España</code></div>
                            </div>

                            <div class="form-check form-switch mb-2 p-3 rounded border">
                                <input class="form-check-input" type="checkbox" id="wm_brujula" name="wm_mostrar_brujula" value="1" <?= $wmBrujula ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="wm_brujula">
                                    <i class="bi bi-compass me-1"></i> Brújula gráfica
                                </label>
                                <div class="text-muted small mt-1">Rosa de los vientos en la esquina superior izquierda.</div>
                            </div>

                            <!-- Información adicional -->
                            <h6 class="text-muted fw-bold small text-uppercase mt-4 mb-3">
                                <i class="bi bi-plus-circle me-1"></i> Información adicional
                            </h6>

                            <div class="form-check form-switch mb-2 p-3 rounded border">
                                <input class="form-check-input" type="checkbox" id="wm_codigo_infra" name="wm_mostrar_codigo_infra" value="1" <?= $wmCodigoInfra ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="wm_codigo_infra">
                                    <i class="bi bi-hash me-1"></i> Código de infraestructura
                                </label>
                                <div class="text-muted small mt-1">Muestra el código de la infraestructura en la foto (ej: <code>TORRE-A42</code>).</div>
                            </div>

                            <div class="form-check form-switch mb-3 p-3 rounded border">
                                <input class="form-check-input" type="checkbox" id="wm_situacion" name="wm_mostrar_situacion" value="1" <?= $wmSituacion ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="wm_situacion">
                                    <i class="bi bi-flag me-1"></i> Situación de la obra
                                </label>
                                <div class="text-muted small mt-1">Muestra ANTES, DURANTE o DESPUÉS en la foto.</div>
                            </div>

                            <div class="form-check form-switch mb-3 p-3 rounded border">
                                <input class="form-check-input" type="checkbox" id="wm_tipo_foto" name="wm_mostrar_tipo_foto" value="1" <?= $wmTipoFoto ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="wm_tipo_foto">
                                    <i class="bi bi-camera me-1"></i> Tipo de foto
                                </label>
                                <div class="text-muted small mt-1">Muestra <code>FOT ALE</code> (aleatoria) o <code>FOT COM</code> (comparativa).</div>
                            </div>

                            <div class="form-check form-switch mb-3 p-3 rounded border">
                                <input class="form-check-input" type="checkbox" id="wm_mapa" name="wm_mostrar_mapa" value="1" <?= $wmMapa ? 'checked' : '' ?> onchange="toggleWmMapaOpts()">
                                <label class="form-check-label fw-semibold" for="wm_mapa">
                                    <i class="bi bi-map me-1"></i> Mini-mapa de localización
                                </label>
                                <div class="text-muted small mt-1">Muestra un pequeño mapa en la esquina inferior izquierda de la foto.</div>
                                <div class="mt-2 ps-4 d-flex gap-3 flex-wrap" id="wm-mapa-opts" style="<?= $wmMapa ? '' : 'display:none !important;' ?>">
                                    <div>
                                        <label class="form-label small fw-semibold text-muted mb-1">Escala:</label>
                                        <select name="wm_mapa_zoom" class="form-select form-select-sm" style="width:160px;">
                                            <option value="13" <?= $wmMapaZoom === 13 ? 'selected' : '' ?>>Alejado (ciudad)</option>
                                            <option value="14" <?= $wmMapaZoom === 14 ? 'selected' : '' ?>>Medio-lejos</option>
                                            <option value="15" <?= $wmMapaZoom === 15 ? 'selected' : '' ?>>Medio (barrio)</option>
                                            <option value="16" <?= $wmMapaZoom === 16 ? 'selected' : '' ?>>Cercano (calle)</option>
                                            <option value="17" <?= $wmMapaZoom === 17 ? 'selected' : '' ?>>Muy cercano</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label small fw-semibold text-muted mb-1">Tamaño:</label>
                                        <select name="wm_mapa_tamano" class="form-select form-select-sm" style="width:160px;">
                                            <option value="1" <?= $wmMapaTamano === 1 ? 'selected' : '' ?>>Pequeño</option>
                                            <option value="2" <?= $wmMapaTamano === 2 ? 'selected' : '' ?>>Mediano</option>
                                            <option value="3" <?= $wmMapaTamano === 3 ? 'selected' : '' ?>>Grande</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i> Guardar marca de agua
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

    function toggleWmMapaOpts() {
        const checked = document.getElementById('wm_mapa').checked;
        const opts = document.getElementById('wm-mapa-opts');
        if (opts) opts.style.display = checked ? '' : 'none';
    }
    </script>
</body>
</html>
