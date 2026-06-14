<?php
/**
 * INFOCAMPO - Informe de Inspección (HTML imprimible)
 *
 * GET ?infra_id=123
 *
 * Genera una página HTML optimizada para impresión / guardar como PDF
 * directamente desde el navegador (Ctrl+P > Guardar como PDF).
 * Sin dependencias externas (no requiere Composer ni dompdf).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

// Verificar autenticación (consistente con el resto de páginas admin)
requireRole(['admin', 'supervisor', 'superadmin']);

// ---------------------------------------------------------------
// Validar parámetros (acepta infra_id único o infra_ids múltiple CSV)
// ---------------------------------------------------------------
$infraIds = [];
if (isset($_GET['infra_ids']) && $_GET['infra_ids'] !== '') {
    $infraIds = array_filter(
        array_map('intval', explode(',', (string) $_GET['infra_ids'])),
        fn($id) => $id > 0
    );
} elseif (isset($_GET['infra_id'])) {
    $single = (int) $_GET['infra_id'];
    if ($single > 0) $infraIds = [$single];
}
$infraIds = array_values(array_unique($infraIds));

if (empty($infraIds)) {
    http_response_code(400);
    echo 'Parámetro infra_id o infra_ids requerido';
    exit;
}

$pdo = getDB();
$empresaId = $_SESSION['empresa_id'];

// ---------------------------------------------------------------
// Cargar infraestructuras (scoped by empresa_id)
// ---------------------------------------------------------------
$placeholders = implode(',', array_fill(0, count($infraIds), '?'));
$stmt = $pdo->prepare(
    "SELECT i.*, e.nombre AS empresa_nombre
     FROM infraestructuras i
     INNER JOIN empresas e ON i.empresa_id = e.id
     WHERE i.id IN ($placeholders) AND i.empresa_id = ?
     ORDER BY i.codigo_unico ASC"
);
$stmt->execute([...$infraIds, $empresaId]);
$infras = $stmt->fetchAll();

if (empty($infras)) {
    http_response_code(404);
    echo 'Infraestructura no encontrada';
    exit;
}

// ---------------------------------------------------------------
// Cargar y calcular los datos de una infraestructura concreta
// ---------------------------------------------------------------
function cargarDatosInfra(PDO $pdo, int $infraId): array {
    $stmt = $pdo->prepare(
        "SELECT r.*, u.nombre AS usuario_nombre, uo.nombre AS unidad_obra_nombre
         FROM registros r
         INNER JOIN usuarios u ON r.usuario_id = u.id
         LEFT JOIN unidades_obra uo ON r.unidad_obra_id = uo.id
         WHERE r.infra_id = :infra_id
         ORDER BY r.fecha ASC"
    );
    $stmt->execute([':infra_id' => $infraId]);
    $registros = $stmt->fetchAll();

    $comparativas = array_filter($registros, fn($r) => ($r['tipo_foto'] ?? '') === 'comparativo');
    $aleatorias   = array_filter($registros, fn($r) => ($r['tipo_foto'] ?? '') !== 'comparativo');

    // Agrupar comparativas por visita (día)
    $visitasComp = [];
    foreach ($comparativas as $r) {
        $dia = date('Y-m-d', strtotime($r['fecha']));
        $visitasComp[$dia][] = $r;
    }

    // Conteo de situaciones
    $conteo = ['antes' => 0, 'durante' => 0, 'despues' => 0];
    foreach ($registros as $r) {
        $estado = $r['estado_incidencia'] ?? '';
        if (isset($conteo[$estado])) {
            $conteo[$estado]++;
        }
    }

    return compact('registros', 'comparativas', 'aleatorias', 'visitasComp', 'conteo');
}

$fechaGeneracion = date('d/m/Y H:i');
$empresaNombre   = htmlspecialchars($infras[0]['empresa_nombre']);
// Título global del documento
$tituloDoc = count($infras) === 1
    ? htmlspecialchars($infras[0]['codigo_unico'])
    : (count($infras) . ' infraestructuras');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Informe - INFOCAMPO</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    /* ============================================
       ESTILOS PANTALLA (navegador)
       ============================================ */
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
        font-size: 13px;
        color: #333;
        background: #e9ecef;
        line-height: 1.5;
    }

    /* Toolbar flotante (solo pantalla) */
    .toolbar {
        position: fixed;
        top: 0; left: 0; right: 0;
        z-index: 100;
        background: #1e3a5f;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 20px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.2);
    }

    .toolbar-title {
        font-weight: 700;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .toolbar-actions {
        display: flex;
        gap: 8px;
    }

    .toolbar-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 18px;
        border: none;
        border-radius: 8px;
        font-size: 0.82rem;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.2s;
    }

    .toolbar-btn--print {
        background: #fff;
        color: #1e3a5f;
    }
    .toolbar-btn--print:hover {
        background: #e0e7ff;
    }

    .toolbar-btn--back {
        background: rgba(255,255,255,0.15);
        color: #fff;
    }
    .toolbar-btn--back:hover {
        background: rgba(255,255,255,0.25);
    }

    /* Contenedor principal (simula hoja A4) */
    .page-container {
        max-width: 820px;
        margin: 70px auto 40px;
        background: #fff;
        box-shadow: 0 2px 20px rgba(0,0,0,0.1);
        border-radius: 4px;
    }

    /* Banner logo personalizado */
    .report-logo-banner {
        width: 100%;
        background: #fff;
        text-align: center;
        display: none; /* oculto hasta que se suba logo */
    }

    .report-logo-banner img {
        width: 100%;
        display: block;
        object-fit: contain;
    }

    .report-logo-banner.has-logo { display: block; }

    /* Titulo personalizado */
    .report-custom-title {
        background: #1e3a5f;
        color: #fff;
        text-align: center;
        padding: 14px 36px;
        font-size: 20px;
        font-weight: 800;
        display: none; /* oculto hasta que se escriba título */
    }

    .report-custom-title.has-title { display: block; }

    /* Cabecera del informe */
    .report-header {
        background: #1e3a5f;
        color: #fff;
        padding: 28px 36px;
    }

    .report-header h1 {
        font-size: 22px;
        font-weight: 800;
        margin-bottom: 4px;
    }

    .report-header p {
        opacity: 0.7;
        font-size: 11px;
    }

    /* Controles personalización cabecera (solo pantalla) */
    .header-controls {
        background: #eef2ff;
        border: 1px dashed #6b7fbd;
        border-radius: 8px;
        padding: 12px 16px;
        margin: 0 36px 0;
        display: flex;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
        font-size: 12px;
    }

    .header-controls .ctrl-group {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .header-controls .ctrl-label {
        font-weight: 700;
        color: #1e3a5f;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        white-space: nowrap;
    }

    .header-controls input[type="text"] {
        border: 1px solid #c5cee0;
        border-radius: 6px;
        padding: 5px 10px;
        font-size: 12px;
        width: 260px;
        outline: none;
    }

    .header-controls input[type="text"]:focus {
        border-color: #1e3a5f;
        box-shadow: 0 0 0 2px rgba(30,58,95,0.15);
    }

    .header-controls .btn-upload-logo {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 14px;
        background: #1e3a5f;
        color: #fff;
        border: none;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s;
    }

    .header-controls .btn-upload-logo:hover { background: #2a4f7f; }

    .header-controls .btn-remove-logo {
        display: none;
        align-items: center;
        gap: 4px;
        padding: 5px 10px;
        background: #dc3545;
        color: #fff;
        border: none;
        border-radius: 6px;
        font-size: 11px;
        cursor: pointer;
    }

    .header-controls .btn-remove-logo.visible { display: inline-flex; }

    .header-controls .logo-filename {
        font-size: 10px;
        color: #666;
        font-style: italic;
        max-width: 150px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* Secciones */
    .section {
        padding: 20px 36px;
    }

    .section h2 {
        font-size: 15px;
        color: #1e3a5f;
        border-bottom: 2px solid #1e3a5f;
        padding-bottom: 5px;
        margin-bottom: 14px;
    }

    .section h3 {
        font-size: 13px;
        color: #444;
        margin: 16px 0 8px;
        padding: 5px 10px;
        background: #f0f4ff;
        border-left: 3px solid #1e3a5f;
    }

    /* Tablas */
    table.info {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 14px;
    }

    table.info td {
        padding: 7px 12px;
        border: 1px solid #dee2e6;
        font-size: 12px;
    }

    table.info td.label {
        background: #f8f9fa;
        font-weight: bold;
        width: 22%;
        color: #555;
    }

    table.data {
        width: 100%;
        border-collapse: collapse;
    }

    table.data th,
    table.data td {
        padding: 7px 10px;
        border: 1px solid #dee2e6;
        text-align: center;
        font-size: 11px;
    }

    table.data th {
        background: #1e3a5f;
        color: #fff;
        font-size: 10px;
        font-weight: 700;
    }

    table.data td { vertical-align: middle; }

    /* Badges */
    .badge-antes    { color: #1e40af; font-weight: bold; }
    .badge-durante  { color: #854d0e; font-weight: bold; }
    .badge-despues  { color: #166534; font-weight: bold; }
    .badge-comp    { color: #6d28d9; font-weight: bold; }
    .badge-alea    { color: #1d4ed8; font-weight: bold; }

    /* Fotos aleatorias — grid de 3 por fila, ancho completo */
    .alea-row {
        display: flex;
        gap: 4px;
        margin-bottom: 12px;
        page-break-inside: avoid;
    }

    .alea-col {
        flex: 1;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 6px;
        min-width: 0;
    }

    .alea-col img {
        width: 100%;
        object-fit: contain;
        display: block;
        margin: 4px auto;
        border-radius: 4px;
    }

    .alea-col .foto-meta {
        font-size: 9px;
        color: #666;
        line-height: 1.4;
    }

    .alea-col .foto-meta strong { color: #333; }

    /* Comparativas lado a lado — 2 fotos = ancho completo */
    .comp-pair {
        display: flex;
        gap: 4px;
        margin-bottom: 16px;
        page-break-inside: avoid;
    }

    .comp-col {
        flex: 1;
        min-width: 0;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 6px;
    }

    .comp-col img {
        width: 100%;
        object-fit: contain;
        display: block;
        margin: 0 auto;
        border-radius: 4px;
    }

    .comp-label {
        text-align: center;
        font-size: 11px;
        font-weight: bold;
        color: #6d28d9;
        margin-bottom: 6px;
    }

    .comp-gps {
        text-align: center;
        font-size: 9px;
        color: #888;
        margin-top: 4px;
    }

    .comp-meta {
        text-align: center;
        font-size: 9px;
        color: #666;
        margin-top: 3px;
        line-height: 1.4;
    }

    /* Separador de sección */
    .section-divider {
        border: none;
        border-top: 1px solid #e0e0e0;
        margin: 0;
    }

    /* Footer del informe */
    .report-footer {
        text-align: center;
        font-size: 10px;
        color: #999;
        border-top: 1px solid #dee2e6;
        padding: 12px;
    }

    /* ============================================
       ESTILOS IMPRESION (@media print)
       ============================================ */
    @media print {
        /* Ocultar toolbar */
        .toolbar { display: none !important; }

        /* Resetear fondo y sombras */
        body {
            background: #fff;
            margin: 0;
            padding: 0;
        }

        .page-container {
            margin: 0;
            max-width: 100%;
            box-shadow: none;
            border-radius: 0;
        }

        /* Cabecera: forzar color de fondo en impresión */
        .report-header {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            color-adjust: exact;
        }

        table.data th {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            color-adjust: exact;
        }

        /* Badges también */
        .badge-antes, .badge-durante, .badge-despues,
        .badge-comp, .badge-alea {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .section h3 {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Controlar saltos de página */
        .page-break { page-break-before: always; }

        .alea-row,
        .comp-pair {
            page-break-inside: avoid;
        }

        .section h2 {
            page-break-after: avoid;
        }

        /* Ajustar márgenes de sección */
        .section { padding: 14px 24px; }
        .report-header { padding: 20px 24px; }

        /* Ocultar controles en impresión */
        .info-controls { display: none !important; }
        .header-controls { display: none !important; }

        /* Ocultar elementos marcados como hidden antes de imprimir */
        .meta-hidden { display: none !important; }

        /* Logo y título personalizado en impresión */
        .report-logo-banner.has-logo {
            display: block !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .report-custom-title.has-title {
            display: block !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            color-adjust: exact;
        }

        /* Footer */
        .report-footer { position: fixed; bottom: 0; left: 0; right: 0; }
    }

    /* Panel de controles de info en fotos (solo pantalla) */
    .info-controls {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 10px 16px;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 18px;
        flex-wrap: wrap;
        font-size: 12px;
    }

    .info-controls label {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        cursor: pointer;
        user-select: none;
        font-weight: 500;
        color: #333;
    }

    .info-controls input[type="checkbox"] {
        width: 15px;
        height: 15px;
        cursor: pointer;
        accent-color: #1e3a5f;
    }

    .info-controls .controls-title {
        font-weight: 700;
        color: #1e3a5f;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .meta-hidden { display: none !important; }

    /* Responsive para vista en pantalla */
    @media (max-width: 600px) {
        .page-container { margin: 56px 0 0; border-radius: 0; }
        .section { padding: 16px; }
        .report-header { padding: 20px 16px; }
        .report-header h1 { font-size: 17px; }
        .comp-pair { flex-direction: column; }
        .alea-row { flex-direction: column; }
        .toolbar-title span { display: none; }
        .header-controls { margin: 0 16px; flex-direction: column; align-items: flex-start; }
        .header-controls input[type="text"] { width: 100%; }
    }
</style>
</head>
<body>

<!-- Toolbar (solo visible en pantalla, oculto al imprimir) -->
<div class="toolbar">
    <div class="toolbar-title">
        <i class="bi bi-file-earmark-pdf"></i>
        <span>Informe de Inspección &mdash; <?= $tituloDoc ?></span>
    </div>
    <div class="toolbar-actions">
        <a href="javascript:history.back()" class="toolbar-btn toolbar-btn--back">
            <i class="bi bi-arrow-left"></i> Volver
        </a>
        <button onclick="window.print()" class="toolbar-btn toolbar-btn--print">
            <i class="bi bi-printer"></i> Imprimir / Guardar PDF
        </button>
    </div>
</div>

<!-- Contenido del informe -->
<div class="page-container">

    <!-- Logo personalizado (oculto por defecto) -->
    <div class="report-logo-banner" id="logoBanner">
        <img id="logoImg" src="" alt="Logo">
    </div>

    <!-- Título personalizado (oculto por defecto) -->
    <div class="report-custom-title" id="customTitle"></div>

    <!-- Cabecera -->
    <div class="report-header">
        <h1>INFOCAMPO &mdash; Informe de Inspección</h1>
        <p>Generado el <?= $fechaGeneracion ?> &mdash; <?= $empresaNombre ?></p>
    </div>

    <!-- Controles de personalización (solo pantalla) -->
    <div class="header-controls">
        <span class="ctrl-label"><i class="bi bi-palette"></i> Personalizar cabecera:</span>
        <div class="ctrl-group">
            <label class="ctrl-label" style="font-size:10px;">Logo:</label>
            <button type="button" class="btn-upload-logo" id="btnUploadLogo">
                <i class="bi bi-image"></i> Subir logo
            </button>
            <span class="logo-filename" id="logoFilename"></span>
            <button type="button" class="btn-remove-logo" id="btnRemoveLogo">
                <i class="bi bi-x"></i> Quitar
            </button>
            <input type="file" id="inputLogo" accept="image/*" style="display:none;">
        </div>
        <div class="ctrl-group">
            <label class="ctrl-label" style="font-size:10px;">Título:</label>
            <input type="text" id="inputTitulo" placeholder="Título personalizado del informe...">
        </div>
    </div>

    <?php foreach ($infras as $infraIdx => $infra):
        $datos        = cargarDatosInfra($pdo, (int) $infra['id']);
        $registros    = $datos['registros'];
        $comparativas = $datos['comparativas'];
        $aleatorias   = $datos['aleatorias'];
        $visitasComp  = $datos['visitasComp'];
        $conteo       = $datos['conteo'];

        $codigoInfra    = htmlspecialchars($infra['codigo_unico']);
        $nombreInfra    = htmlspecialchars($infra['nombre']);
        $tipoInfra      = htmlspecialchars($infra['tipo'] ?? 'N/A');
        $totalRegistros = count($registros);
        $totalComp      = count($comparativas);
        $totalAlea      = count($aleatorias);

        if ($infraIdx > 0): ?>
            <div class="page-break"></div>
        <?php endif; ?>

    <!-- Datos de la infraestructura -->
    <div class="section">
        <h2>Datos de la Infraestructura</h2>
        <table class="info">
            <tr>
                <td class="label">Código</td>
                <td><?= $codigoInfra ?></td>
                <td class="label">Empresa</td>
                <td><?= $empresaNombre ?></td>
            </tr>
            <tr>
                <td class="label">Nombre</td>
                <td><?= $nombreInfra ?></td>
                <td class="label">Tipo</td>
                <td><?= $tipoInfra ?></td>
            </tr>
            <tr>
                <td class="label">Latitud teórica</td>
                <td><?= $infra['lat_teorica'] ?></td>
                <td class="label">Longitud teórica</td>
                <td><?= $infra['lon_teorica'] ?></td>
            </tr>
            <?php if (!empty($infra['provincia']) || !empty($infra['municipio'])): ?>
            <tr>
                <td class="label">Provincia</td>
                <td><?= htmlspecialchars($infra['provincia'] ?? '-') ?></td>
                <td class="label">Municipio</td>
                <td><?= htmlspecialchars($infra['municipio'] ?? '-') ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>

    <hr class="section-divider">

    <!-- Resumen -->
    <div class="section">
        <h2>Resumen de Inspecciones</h2>
        <table class="data">
            <thead>
                <tr>
                    <th>Total</th>
                    <th>Comparativas</th>
                    <th>Aleatorias</th>
                    <th>Antes</th>
                    <th>Durante</th>
                    <th>Después</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong><?= $totalRegistros ?></strong></td>
                    <td class="badge-comp"><?= $totalComp ?></td>
                    <td class="badge-alea"><?= $totalAlea ?></td>
                    <td class="badge-antes"><?= $conteo['antes'] ?></td>
                    <td class="badge-durante"><?= $conteo['durante'] ?></td>
                    <td class="badge-despues"><?= $conteo['despues'] ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <hr class="section-divider">

    <!-- Detalle de registros -->
    <div class="section">
        <h2>Detalle de Registros</h2>
        <?php if (empty($registros)): ?>
            <p style="color:#888; font-style:italic;">No hay registros de inspección para esta infraestructura.</p>
        <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Operador</th>
                    <th>Tipo</th>
                    <th>GPS Real</th>
                    <th>Situación</th>
                    <th>U. Obra</th>
                    <th style="text-align:left">Observaciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($registros as $reg):
                $fecha     = date('d/m/Y H:i', strtotime($reg['fecha']));
                $operador  = htmlspecialchars($reg['usuario_nombre']);
                $gps       = $reg['lat_real'] . ', ' . $reg['lon_real'];
                $estado    = $reg['estado_incidencia'];
                $tipo      = ($reg['tipo_foto'] ?? 'aleatorio') === 'comparativo' ? 'Fotos Comparativas' : 'Fotos Aleatorias';
                $tipoClass = ($reg['tipo_foto'] ?? 'aleatorio') === 'comparativo' ? 'badge-comp' : 'badge-alea';
                $obs       = htmlspecialchars(mb_substr($reg['observaciones'] ?? '-', 0, 60));
                $uo        = htmlspecialchars($reg['unidad_obra_nombre'] ?? '-');
            ?>
                <tr>
                    <td><?= $fecha ?></td>
                    <td><?= $operador ?></td>
                    <td class="<?= $tipoClass ?>"><?= $tipo ?></td>
                    <td style="font-size:9px;"><?= $gps ?></td>
                    <td class="badge-<?= $estado ?>"><?= strtoupper($estado) ?></td>
                    <td><?= $uo ?></td>
                    <td style="text-align:left;"><?= $obs ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php if (!empty($visitasComp) || !empty($aleatorias)): ?>
    <div class="section info-controls">
        <span class="controls-title"><i class="bi bi-gear"></i> Info visible en fotos:</span>
        <label><input type="checkbox" id="chkOperador" checked> Operador</label>
        <label><input type="checkbox" id="chkSituacion" checked> Situación</label>
        <label><input type="checkbox" id="chkTrabajo" checked> Tipo de trabajo</label>
    </div>
    <?php endif; ?>

    <?php
    // ---------------------------------------------------------------
    // Fotos comparativas (agrupadas por visita)
    // ---------------------------------------------------------------
    if (!empty($visitasComp)):
    ?>
    <div class="page-break"></div>
    <div class="section">
        <h2>Fotos Comparativas</h2>
        <p style="font-size:11px;color:#666;margin-bottom:12px;">
            Fotos tomadas en modo comparativo con ghosting, agrupadas por visita y ordenadas por secuencia.
        </p>

        <?php
        $visitaNum = 0;
        foreach ($visitasComp as $dia => $fotos):
            $visitaNum++;
            $fechaDia = date('d/m/Y', strtotime($dia));
            $operadorVisita = htmlspecialchars($fotos[0]['usuario_nombre']);

            usort($fotos, fn($a, $b) => ($a['secuencia_comparativa'] ?? 0) - ($b['secuencia_comparativa'] ?? 0));

            if ($visitaNum > 1):
        ?>
        </div>
        <div class="page-break"></div>
        <div class="section">
            <h2>Fotos Comparativas (cont.)</h2>
        <?php endif; ?>

            <h3>Visita <?= $visitaNum ?> &mdash; <?= $fechaDia ?> &mdash; Operador: <?= $operadorVisita ?></h3>

            <?php
            $pares = array_chunk($fotos, 2);
            foreach ($pares as $par):
            ?>
            <div class="comp-pair">
                <?php foreach ($par as $f):
                    $seq     = $f['secuencia_comparativa'] ?? '?';
                    $url     = htmlspecialchars($f['url_cloudinary']);
                    $estadoF = strtoupper($f['estado_incidencia']);
                    $badgeF  = "badge-{$f['estado_incidencia']}";
                ?>
                <div class="comp-col">
                    <div class="comp-label">W<?= $seq ?> &mdash; <span class="<?= $badgeF ?>" data-meta="situacion"><?= $estadoF ?></span></div>
                    <img src="<?= $url ?>" alt="W<?= $seq ?>">
                    <div class="comp-gps">GPS: <?= $f['lat_real'] ?>, <?= $f['lon_real'] ?></div>
                    <div class="comp-meta">
                        <span data-meta="operador"><?= htmlspecialchars($f['usuario_nombre']) ?></span>
                        <?php if (!empty($f['unidad_obra_nombre'])): ?>
                        <span data-meta="trabajo"> | <?= htmlspecialchars($f['unidad_obra_nombre']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (count($par) === 1): ?>
                <div class="comp-col"></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php
    // ---------------------------------------------------------------
    // Fotos aleatorias
    // ---------------------------------------------------------------
    if (!empty($aleatorias)):
    ?>
    <div class="page-break"></div>
    <div class="section">
        <h2>Fotos Aleatorias</h2>

        <?php
        $fotosAlea = array_values($aleatorias);
        $totalAleaFotos = count($fotosAlea);
        // 3 fotos por fila, ~3 filas por página = 9 fotos por página
        $filas = array_chunk($fotosAlea, 3);
        $filaCount = 0;
        foreach ($filas as $fila):
            $filaCount++;
            if ($filaCount > 1 && ($filaCount - 1) % 3 === 0):
        ?>
        </div>
        <div class="page-break"></div>
        <div class="section">
            <h2>Fotos Aleatorias (cont.)</h2>
        <?php endif; ?>
            <div class="alea-row">
                <?php foreach ($fila as $reg):
                    $fecha      = date('d/m/Y H:i', strtotime($reg['fecha']));
                    $operador   = htmlspecialchars($reg['usuario_nombre']);
                    $estado     = strtoupper($reg['estado_incidencia']);
                    $badgeClass = "badge-{$reg['estado_incidencia']}";
                    $url        = htmlspecialchars($reg['url_cloudinary']);
                    $uo         = !empty($reg['unidad_obra_nombre']) ? ' | ' . htmlspecialchars($reg['unidad_obra_nombre']) : '';
                ?>
                <div class="alea-col">
                    <img src="<?= $url ?>" alt="Inspección">
                    <div class="foto-meta">
                        <strong><?= $fecha ?></strong><br>
                        <span data-meta="operador"><?= $operador ?></span>
                        <span data-meta="situacion"> &mdash; <span class="<?= $badgeClass ?>"><?= $estado ?></span></span>
                        <span data-meta="trabajo"><?= $uo ?></span><br>
                        <span style="font-size:8px;">GPS: <?= $reg['lat_real'] ?>, <?= $reg['lon_real'] ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php
                // Rellenar celdas vacías si la fila tiene menos de 3
                $vacias = 3 - count($fila);
                for ($i = 0; $i < $vacias; $i++):
                ?>
                <div class="alea-col" style="border:none;"></div>
                <?php endfor; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php endforeach; // fin bucle infraestructuras ?>

    <!-- Footer -->
    <div class="report-footer">
        <?= $empresaNombre ?> &mdash; Informe generado automáticamente &mdash; <?= $fechaGeneracion ?>
    </div>

</div><!-- /page-container -->

<script>
(() => {
    const map = {
        chkOperador:  'operador',
        chkSituacion: 'situacion',
        chkTrabajo:   'trabajo'
    };

    function toggleMeta(metaName, visible) {
        document.querySelectorAll(`[data-meta="${metaName}"]`).forEach(el => {
            if (visible) {
                el.classList.remove('meta-hidden');
            } else {
                el.classList.add('meta-hidden');
            }
        });
    }

    Object.entries(map).forEach(([checkboxId, metaName]) => {
        const cb = document.getElementById(checkboxId);
        if (!cb) return;
        cb.addEventListener('change', () => toggleMeta(metaName, cb.checked));
    });

    // --- Logo personalizado ---
    const btnUpload = document.getElementById('btnUploadLogo');
    const btnRemove = document.getElementById('btnRemoveLogo');
    const inputLogo = document.getElementById('inputLogo');
    const logoBanner = document.getElementById('logoBanner');
    const logoImg = document.getElementById('logoImg');
    const logoFilename = document.getElementById('logoFilename');

    if (btnUpload) {
        btnUpload.addEventListener('click', () => inputLogo.click());
    }

    if (inputLogo) {
        inputLogo.addEventListener('change', () => {
            const file = inputLogo.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = (e) => {
                logoImg.src = e.target.result;
                logoBanner.classList.add('has-logo');
                logoFilename.textContent = file.name;
                btnRemove.classList.add('visible');
            };
            reader.readAsDataURL(file);
        });
    }

    if (btnRemove) {
        btnRemove.addEventListener('click', () => {
            logoImg.src = '';
            logoBanner.classList.remove('has-logo');
            logoFilename.textContent = '';
            btnRemove.classList.remove('visible');
            inputLogo.value = '';
        });
    }

    // --- Título personalizado ---
    const inputTitulo = document.getElementById('inputTitulo');
    const customTitle = document.getElementById('customTitle');

    if (inputTitulo) {
        inputTitulo.addEventListener('input', () => {
            const val = inputTitulo.value.trim();
            if (val) {
                customTitle.textContent = val;
                customTitle.classList.add('has-title');
            } else {
                customTitle.textContent = '';
                customTitle.classList.remove('has-title');
            }
        });
    }
})();
</script>

</body>
</html>
