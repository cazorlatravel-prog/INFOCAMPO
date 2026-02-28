<?php
/**
 * INFOCAMPO SaaS - Informe de Inspección (HTML imprimible)
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

requireRole(['admin', 'supervisor']);

// ---------------------------------------------------------------
// Validar parámetro
// ---------------------------------------------------------------
$infraId = isset($_GET['infra_id']) ? (int) $_GET['infra_id'] : 0;

if ($infraId <= 0) {
    http_response_code(400);
    echo 'Parámetro infra_id requerido';
    exit;
}

$pdo = getDB();
$empresaId = $_SESSION['empresa_id'];

// ---------------------------------------------------------------
// Obtener datos de la infraestructura (scoped by empresa_id)
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT i.*, e.nombre AS empresa_nombre
     FROM infraestructuras i
     INNER JOIN empresas e ON i.empresa_id = e.id
     WHERE i.id = :id AND i.empresa_id = :emp_id"
);
$stmt->execute([':id' => $infraId, ':emp_id' => $empresaId]);
$infra = $stmt->fetch();

if (!$infra) {
    http_response_code(404);
    echo 'Infraestructura no encontrada';
    exit;
}

// ---------------------------------------------------------------
// Obtener todos los registros
// ---------------------------------------------------------------
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

// ---------------------------------------------------------------
// Separar por tipo
// ---------------------------------------------------------------
$comparativas = array_filter($registros, fn($r) => ($r['tipo_foto'] ?? '') === 'comparativo');
$aleatorias   = array_filter($registros, fn($r) => ($r['tipo_foto'] ?? '') !== 'comparativo');

// Agrupar comparativas por visita (día)
$visitasComp = [];
foreach ($comparativas as $r) {
    $dia = date('Y-m-d', strtotime($r['fecha']));
    $visitasComp[$dia][] = $r;
}

// ---------------------------------------------------------------
// Conteo de incidencias
// ---------------------------------------------------------------
$conteo = ['bajo' => 0, 'medio' => 0, 'critico' => 0];
foreach ($registros as $r) {
    $conteo[$r['estado_incidencia']]++;
}

$fechaGeneracion = date('d/m/Y H:i');
$codigoInfra     = htmlspecialchars($infra['codigo_unico']);
$nombreInfra     = htmlspecialchars($infra['nombre']);
$empresaNombre   = htmlspecialchars($infra['empresa_nombre']);
$tipoInfra       = htmlspecialchars($infra['tipo'] ?? 'N/A');
$totalRegistros  = count($registros);
$totalComp       = count($comparativas);
$totalAlea       = count($aleatorias);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Informe <?= $codigoInfra ?> - FotoGPS.app</title>
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
    .badge-bajo    { color: #166534; font-weight: bold; }
    .badge-medio   { color: #854d0e; font-weight: bold; }
    .badge-critico { color: #dc2626; font-weight: bold; }
    .badge-comp    { color: #6d28d9; font-weight: bold; }
    .badge-alea    { color: #1d4ed8; font-weight: bold; }

    /* Foto blocks */
    .foto-block {
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 12px;
        margin-bottom: 16px;
        page-break-inside: avoid;
    }

    .foto-block img {
        max-width: 100%;
        max-height: 320px;
        display: block;
        margin: 10px auto;
        border-radius: 4px;
    }

    .foto-meta {
        font-size: 11px;
        color: #666;
        line-height: 1.6;
    }

    .foto-meta strong { color: #333; }

    /* Comparativas lado a lado */
    .comp-pair {
        display: flex;
        gap: 12px;
        margin-bottom: 16px;
        page-break-inside: avoid;
    }

    .comp-col {
        flex: 1;
    }

    .comp-col img {
        width: 100%;
        max-height: 240px;
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
        .badge-bajo, .badge-medio, .badge-critico,
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

        .foto-block,
        .comp-pair {
            page-break-inside: avoid;
        }

        .section h2 {
            page-break-after: avoid;
        }

        /* Ajustar márgenes de sección */
        .section { padding: 14px 24px; }
        .report-header { padding: 20px 24px; }

        /* Imágenes a tamaño razonable */
        .foto-block img { max-height: 280px; }
        .comp-col img { max-height: 220px; }

        /* Footer */
        .report-footer { position: fixed; bottom: 0; left: 0; right: 0; }
    }

    /* Responsive para vista en pantalla */
    @media (max-width: 600px) {
        .page-container { margin: 56px 0 0; border-radius: 0; }
        .section { padding: 16px; }
        .report-header { padding: 20px 16px; }
        .report-header h1 { font-size: 17px; }
        .comp-pair { flex-direction: column; }
        .toolbar-title span { display: none; }
    }
</style>
</head>
<body>

<!-- Toolbar (solo visible en pantalla, oculto al imprimir) -->
<div class="toolbar">
    <div class="toolbar-title">
        <i class="bi bi-file-earmark-pdf"></i>
        <span>Informe de Inspección &mdash; <?= $codigoInfra ?></span>
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

    <!-- Cabecera -->
    <div class="report-header">
        <h1>FotoGPS.app &mdash; Informe de Inspección</h1>
        <p>Generado el <?= $fechaGeneracion ?> &mdash; <?= $empresaNombre ?></p>
    </div>

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
                    <th>Bajo</th>
                    <th>Medio</th>
                    <th>Crítico</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong><?= $totalRegistros ?></strong></td>
                    <td class="badge-comp"><?= $totalComp ?></td>
                    <td class="badge-alea"><?= $totalAlea ?></td>
                    <td class="badge-bajo"><?= $conteo['bajo'] ?></td>
                    <td class="badge-medio"><?= $conteo['medio'] ?></td>
                    <td class="badge-critico"><?= $conteo['critico'] ?></td>
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
                    <th>Incidencia</th>
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
                $tipo      = ($reg['tipo_foto'] ?? 'aleatorio') === 'comparativo' ? 'COMP' : 'ALEA';
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
                    <div class="comp-label">W<?= $seq ?> &mdash; <span class="<?= $badgeF ?>"><?= $estadoF ?></span></div>
                    <img src="<?= $url ?>" alt="W<?= $seq ?>">
                    <div class="comp-gps">GPS: <?= $f['lat_real'] ?>, <?= $f['lon_real'] ?></div>
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
        foreach ($fotosAlea as $idx => $reg):
            if ($idx > 0 && $idx % 4 === 0):
        ?>
        </div>
        <div class="page-break"></div>
        <div class="section">
            <h2>Fotos Aleatorias (cont.)</h2>
        <?php endif;
            $fecha      = date('d/m/Y H:i', strtotime($reg['fecha']));
            $operador   = htmlspecialchars($reg['usuario_nombre']);
            $estado     = strtoupper($reg['estado_incidencia']);
            $badgeClass = "badge-{$reg['estado_incidencia']}";
            $url        = htmlspecialchars($reg['url_cloudinary']);
            $uo         = !empty($reg['unidad_obra_nombre']) ? ' | U.Obra: ' . htmlspecialchars($reg['unidad_obra_nombre']) : '';
        ?>
            <div class="foto-block">
                <div class="foto-meta">
                    <strong><?= $fecha ?></strong> &mdash; Operador: <?= $operador ?>
                    &mdash; <span class="<?= $badgeClass ?>"><?= $estado ?></span>
                    &mdash; GPS: <?= $reg['lat_real'] ?>, <?= $reg['lon_real'] ?><?= $uo ?>
                </div>
                <img src="<?= $url ?>" alt="Inspección">
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="report-footer">
        FotoGPS.app &mdash; Informe generado automáticamente &mdash; <?= $fechaGeneracion ?>
    </div>

</div><!-- /page-container -->

</body>
</html>
