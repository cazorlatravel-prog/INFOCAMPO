<?php
/**
 * INFOCAMPO SaaS - Generador de Informe PDF
 *
 * GET ?infra_id=123
 *
 * Genera un PDF con:
 *   - Datos de la infraestructura
 *   - Tabla resumen de incidencias
 *   - Línea de tiempo con fotos (máximo 2 fotos por página)
 *
 * Usa la librería dompdf.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../vendor/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

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

// ---------------------------------------------------------------
// Obtener datos de la infraestructura
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT i.*, e.nombre AS empresa_nombre
     FROM infraestructuras i
     INNER JOIN empresas e ON i.empresa_id = e.id
     WHERE i.id = :id"
);
$stmt->execute([':id' => $infraId]);
$infra = $stmt->fetch();

if (!$infra) {
    http_response_code(404);
    echo 'Infraestructura no encontrada';
    exit;
}

// ---------------------------------------------------------------
// Obtener registros
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT r.*, u.nombre AS usuario_nombre
     FROM registros r
     INNER JOIN usuarios u ON r.usuario_id = u.id
     WHERE r.infra_id = :infra_id
     ORDER BY r.fecha ASC"
);
$stmt->execute([':infra_id' => $infraId]);
$registros = $stmt->fetchAll();

// ---------------------------------------------------------------
// Conteo de incidencias
// ---------------------------------------------------------------
$conteo = ['bajo' => 0, 'medio' => 0, 'critico' => 0];
foreach ($registros as $r) {
    $conteo[$r['estado_incidencia']]++;
}

// ---------------------------------------------------------------
// Generar HTML para el PDF
// ---------------------------------------------------------------
$fechaGeneracion = date('d/m/Y H:i');
$codigoInfra = htmlspecialchars($infra['codigo_unico']);
$nombreInfra = htmlspecialchars($infra['nombre']);
$empresaNombre = htmlspecialchars($infra['empresa_nombre']);
$tipoInfra = htmlspecialchars($infra['tipo'] ?? 'N/A');

// Preparar fotos en pares (máximo 2 por página)
$fotosPares = array_chunk($registros, 2);

$html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    body {
        font-family: 'Helvetica', 'Arial', sans-serif;
        font-size: 11px;
        color: #333;
        margin: 0;
        padding: 0;
    }
    .header {
        background: #1e3a5f;
        color: #fff;
        padding: 20px 30px;
        margin-bottom: 20px;
    }
    .header h1 {
        margin: 0;
        font-size: 20px;
    }
    .header p {
        margin: 4px 0 0;
        opacity: 0.8;
        font-size: 10px;
    }
    .section { padding: 0 30px; margin-bottom: 18px; }
    .section h2 {
        font-size: 14px;
        color: #1e3a5f;
        border-bottom: 2px solid #1e3a5f;
        padding-bottom: 4px;
        margin-bottom: 10px;
    }
    table.info {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 12px;
    }
    table.info td {
        padding: 6px 10px;
        border: 1px solid #dee2e6;
        font-size: 11px;
    }
    table.info td.label {
        background: #f8f9fa;
        font-weight: bold;
        width: 30%;
    }
    table.incidencias {
        width: 100%;
        border-collapse: collapse;
    }
    table.incidencias th,
    table.incidencias td {
        padding: 8px 10px;
        border: 1px solid #dee2e6;
        text-align: center;
        font-size: 11px;
    }
    table.incidencias th {
        background: #1e3a5f;
        color: #fff;
    }
    .badge-bajo    { color: #166534; font-weight: bold; }
    .badge-medio   { color: #854d0e; font-weight: bold; }
    .badge-critico { color: #dc2626; font-weight: bold; }

    .page-break { page-break-before: always; }

    .foto-block {
        border: 1px solid #dee2e6;
        border-radius: 4px;
        padding: 10px;
        margin-bottom: 14px;
        page-break-inside: avoid;
    }
    .foto-block img {
        max-width: 100%;
        max-height: 280px;
        display: block;
        margin: 8px auto;
    }
    .foto-meta {
        font-size: 10px;
        color: #666;
    }
    .footer {
        text-align: center;
        font-size: 9px;
        color: #999;
        border-top: 1px solid #dee2e6;
        padding: 8px;
        margin-top: 20px;
    }
</style>
</head>
<body>
    <!-- PORTADA / DATOS -->
    <div class="header">
        <h1>INFOCAMPO &mdash; Informe de Inspección</h1>
        <p>Generado el {$fechaGeneracion}</p>
    </div>

    <div class="section">
        <h2>Datos de la Infraestructura</h2>
        <table class="info">
            <tr>
                <td class="label">Código</td>
                <td>{$codigoInfra}</td>
                <td class="label">Empresa</td>
                <td>{$empresaNombre}</td>
            </tr>
            <tr>
                <td class="label">Nombre</td>
                <td>{$nombreInfra}</td>
                <td class="label">Tipo</td>
                <td>{$tipoInfra}</td>
            </tr>
            <tr>
                <td class="label">Latitud teórica</td>
                <td>{$infra['lat_teorica']}</td>
                <td class="label">Longitud teórica</td>
                <td>{$infra['lon_teorica']}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Resumen de Incidencias</h2>
        <table class="incidencias">
            <thead>
                <tr>
                    <th>Total Registros</th>
                    <th>Bajo</th>
                    <th>Medio</th>
                    <th>Crítico</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>TOTAL_REGISTROS</strong></td>
                    <td class="badge-bajo">{$conteo['bajo']}</td>
                    <td class="badge-medio">{$conteo['medio']}</td>
                    <td class="badge-critico">{$conteo['critico']}</td>
                </tr>
            </tbody>
        </table>
    </div>
HTML;

// Reemplazar total
$html = str_replace('TOTAL_REGISTROS', (string) count($registros), $html);

// ---------------------------------------------------------------
// Tabla detallada de incidencias
// ---------------------------------------------------------------
$html .= '<div class="section"><h2>Detalle de Registros</h2>';
$html .= '<table class="incidencias">';
$html .= '<thead><tr>
    <th>Fecha</th><th>Operador</th><th>GPS Real</th><th>Incidencia</th><th>Observaciones</th>
</tr></thead><tbody>';

foreach ($registros as $reg) {
    $fecha = date('d/m/Y H:i', strtotime($reg['fecha']));
    $operador = htmlspecialchars($reg['usuario_nombre']);
    $gps = $reg['lat_real'] . ', ' . $reg['lon_real'];
    $estado = $reg['estado_incidencia'];
    $badgeClass = "badge-{$estado}";
    $obs = htmlspecialchars($reg['observaciones'] ?? '-');

    $html .= "<tr>
        <td>{$fecha}</td>
        <td>{$operador}</td>
        <td>{$gps}</td>
        <td class=\"{$badgeClass}\">" . strtoupper($estado) . "</td>
        <td>{$obs}</td>
    </tr>";
}

$html .= '</tbody></table></div>';

// ---------------------------------------------------------------
// Páginas de fotos (máximo 2 por página)
// ---------------------------------------------------------------
foreach ($fotosPares as $idx => $par) {
    $html .= '<div class="page-break"></div>';
    $html .= '<div class="section">';
    $html .= '<h2>Fotos de Inspección (Página ' . ($idx + 1) . ')</h2>';

    foreach ($par as $reg) {
        $fecha = date('d/m/Y H:i', strtotime($reg['fecha']));
        $operador = htmlspecialchars($reg['usuario_nombre']);
        $estado = strtoupper($reg['estado_incidencia']);
        $url = htmlspecialchars($reg['url_cloudinary']);

        $html .= "<div class=\"foto-block\">
            <div class=\"foto-meta\">
                <strong>{$fecha}</strong> &mdash; Operador: {$operador}
                &mdash; Incidencia: {$estado}
                &mdash; GPS: {$reg['lat_real']}, {$reg['lon_real']}
            </div>
            <img src=\"{$url}\" alt=\"Inspección\">
        </div>";
    }

    $html .= '</div>';
}

// Footer
$html .= '<div class="footer">
    INFOCAMPO SaaS &mdash; Informe generado automáticamente &mdash; ' . $fechaGeneracion . '
</div>';

$html .= '</body></html>';

// ---------------------------------------------------------------
// Renderizar PDF con dompdf
// ---------------------------------------------------------------
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'Helvetica');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Nombre del archivo
$filename = "informe_{$infra['codigo_unico']}_" . date('Ymd_His') . '.pdf';

$dompdf->stream($filename, ['Attachment' => true]);
