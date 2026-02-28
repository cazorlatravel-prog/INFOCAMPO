<?php
/**
 * INFOCAMPO SaaS - Generador de Informe PDF Avanzado
 *
 * GET ?infra_id=123
 *
 * Genera un PDF con:
 *   - Datos de la infraestructura
 *   - Tabla resumen de incidencias (desglosada por tipo de foto)
 *   - Sección de fotos comparativas (agrupadas por visita y secuencia)
 *   - Sección de fotos aleatorias
 *   - Detalle de formulario por registro con unidad de obra
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
$aleatorias = array_filter($registros, fn($r) => ($r['tipo_foto'] ?? '') !== 'comparativo');

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

// ---------------------------------------------------------------
// Generar HTML para el PDF
// ---------------------------------------------------------------
$fechaGeneracion = date('d/m/Y H:i');
$codigoInfra = htmlspecialchars($infra['codigo_unico']);
$nombreInfra = htmlspecialchars($infra['nombre']);
$empresaNombre = htmlspecialchars($infra['empresa_nombre']);
$tipoInfra = htmlspecialchars($infra['tipo'] ?? 'N/A');

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
        margin: 0; padding: 0;
    }
    .header {
        background: #1e3a5f; color: #fff;
        padding: 24px 30px; margin-bottom: 20px;
    }
    .header h1 { margin: 0; font-size: 20px; }
    .header p { margin: 4px 0 0; opacity: 0.8; font-size: 10px; }
    .section { padding: 0 30px; margin-bottom: 18px; }
    .section h2 {
        font-size: 14px; color: #1e3a5f;
        border-bottom: 2px solid #1e3a5f;
        padding-bottom: 4px; margin-bottom: 10px;
    }
    .section h3 {
        font-size: 12px; color: #444;
        margin: 12px 0 6px;
        padding: 4px 8px;
        background: #f0f4ff;
        border-left: 3px solid #1e3a5f;
    }
    table.info {
        width: 100%; border-collapse: collapse; margin-bottom: 12px;
    }
    table.info td {
        padding: 6px 10px; border: 1px solid #dee2e6; font-size: 11px;
    }
    table.info td.label {
        background: #f8f9fa; font-weight: bold; width: 25%;
    }
    table.data {
        width: 100%; border-collapse: collapse;
    }
    table.data th, table.data td {
        padding: 6px 8px; border: 1px solid #dee2e6;
        text-align: center; font-size: 10px;
    }
    table.data th { background: #1e3a5f; color: #fff; font-size: 9px; }
    .badge-bajo    { color: #166534; font-weight: bold; }
    .badge-medio   { color: #854d0e; font-weight: bold; }
    .badge-critico { color: #dc2626; font-weight: bold; }
    .badge-comp    { color: #6d28d9; font-weight: bold; }
    .badge-alea    { color: #1d4ed8; font-weight: bold; }
    .page-break { page-break-before: always; }
    .foto-block {
        border: 1px solid #dee2e6; border-radius: 4px;
        padding: 10px; margin-bottom: 14px;
        page-break-inside: avoid;
    }
    .foto-block img {
        max-width: 100%; max-height: 260px;
        display: block; margin: 8px auto;
    }
    .foto-meta { font-size: 10px; color: #666; line-height: 1.6; }
    .foto-meta strong { color: #333; }
    .comp-pair {
        display: table; width: 100%; margin-bottom: 14px;
        page-break-inside: avoid;
    }
    .comp-col {
        display: table-cell; width: 48%; vertical-align: top; padding: 4px;
    }
    .comp-col img {
        max-width: 100%; max-height: 200px; display: block; margin: 0 auto;
    }
    .comp-label {
        text-align: center; font-size: 10px; font-weight: bold;
        color: #6d28d9; margin-bottom: 4px;
    }
    .footer {
        text-align: center; font-size: 9px; color: #999;
        border-top: 1px solid #dee2e6; padding: 8px; margin-top: 20px;
    }
</style>
</head>
<body>
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
                    <td><strong>__TOTAL__</strong></td>
                    <td class="badge-comp">__COMP__</td>
                    <td class="badge-alea">__ALEA__</td>
                    <td class="badge-bajo">{$conteo['bajo']}</td>
                    <td class="badge-medio">{$conteo['medio']}</td>
                    <td class="badge-critico">{$conteo['critico']}</td>
                </tr>
            </tbody>
        </table>
    </div>
HTML;

$html = str_replace('__TOTAL__', (string) count($registros), $html);
$html = str_replace('__COMP__', (string) count($comparativas), $html);
$html = str_replace('__ALEA__', (string) count($aleatorias), $html);

// ---------------------------------------------------------------
// Detalle de registros (tabla compacta)
// ---------------------------------------------------------------
$html .= '<div class="section"><h2>Detalle de Registros</h2>';
$html .= '<table class="data">';
$html .= '<thead><tr>
    <th>Fecha</th><th>Operador</th><th>Tipo</th><th>GPS Real</th><th>Incidencia</th><th>U. Obra</th><th>Observaciones</th>
</tr></thead><tbody>';

foreach ($registros as $reg) {
    $fecha = date('d/m/Y H:i', strtotime($reg['fecha']));
    $operador = htmlspecialchars($reg['usuario_nombre']);
    $gps = $reg['lat_real'] . ', ' . $reg['lon_real'];
    $estado = $reg['estado_incidencia'];
    $tipo = ($reg['tipo_foto'] ?? 'aleatorio') === 'comparativo' ? 'COMP' : 'ALEA';
    $tipoClass = ($reg['tipo_foto'] ?? 'aleatorio') === 'comparativo' ? 'badge-comp' : 'badge-alea';
    $badgeClass = "badge-{$estado}";
    $obs = htmlspecialchars(mb_substr($reg['observaciones'] ?? '-', 0, 50));
    $uo = htmlspecialchars($reg['unidad_obra_nombre'] ?? '-');

    $html .= "<tr>
        <td>{$fecha}</td>
        <td>{$operador}</td>
        <td class=\"{$tipoClass}\">{$tipo}</td>
        <td style=\"font-size:9px;\">{$gps}</td>
        <td class=\"{$badgeClass}\">" . strtoupper($estado) . "</td>
        <td>{$uo}</td>
        <td style=\"text-align:left;\">{$obs}</td>
    </tr>";
}

$html .= '</tbody></table></div>';

// ---------------------------------------------------------------
// Fotos comparativas (agrupadas por visita)
// ---------------------------------------------------------------
if (!empty($visitasComp)) {
    $html .= '<div class="page-break"></div>';
    $html .= '<div class="section"><h2>Fotos Comparativas</h2>';
    $html .= '<p style="font-size:10px;color:#666;">Fotos tomadas en modo comparativo con ghosting, agrupadas por visita y ordenadas por secuencia.</p>';

    $visitaNum = 0;
    foreach ($visitasComp as $dia => $fotos) {
        $visitaNum++;
        $fechaDia = date('d/m/Y', strtotime($dia));
        $operadorVisita = htmlspecialchars($fotos[0]['usuario_nombre']);

        if ($visitaNum > 1) {
            $html .= '<div class="page-break"></div><div class="section">';
            $html .= '<h2>Fotos Comparativas (cont.)</h2>';
        }

        $html .= "<h3>Visita {$visitaNum} &mdash; {$fechaDia} &mdash; Operador: {$operadorVisita}</h3>";

        usort($fotos, fn($a, $b) => ($a['secuencia_comparativa'] ?? 0) - ($b['secuencia_comparativa'] ?? 0));

        $pares = array_chunk($fotos, 2);
        foreach ($pares as $par) {
            $html .= '<div class="comp-pair">';
            foreach ($par as $f) {
                $seq = $f['secuencia_comparativa'] ?? '?';
                $url = htmlspecialchars($f['url_cloudinary']);
                $estadoF = strtoupper($f['estado_incidencia']);
                $badgeF = "badge-{$f['estado_incidencia']}";

                $html .= "<div class=\"comp-col\">
                    <div class=\"comp-label\">W{$seq} &mdash; <span class=\"{$badgeF}\">{$estadoF}</span></div>
                    <img src=\"{$url}\" alt=\"W{$seq}\">
                    <div style=\"text-align:center;font-size:9px;color:#666;margin-top:4px;\">
                        GPS: {$f['lat_real']}, {$f['lon_real']}
                    </div>
                </div>";
            }
            if (count($par) === 1) {
                $html .= '<div class="comp-col"></div>';
            }
            $html .= '</div>';
        }

        if ($visitaNum > 1) {
            $html .= '</div>';
        }
    }

    if ($visitaNum <= 1) {
        $html .= '</div>';
    }
}

// ---------------------------------------------------------------
// Fotos aleatorias
// ---------------------------------------------------------------
if (!empty($aleatorias)) {
    $html .= '<div class="page-break"></div>';
    $html .= '<div class="section"><h2>Fotos Aleatorias</h2>';

    $fotosPares = array_chunk(array_values($aleatorias), 2);
    $pageCount = 0;

    foreach ($fotosPares as $idx => $par) {
        if ($idx > 0 && $idx % 3 === 0) {
            $html .= '</div><div class="page-break"></div><div class="section">';
            $html .= '<h2>Fotos Aleatorias (cont.)</h2>';
        }

        foreach ($par as $reg) {
            $fecha = date('d/m/Y H:i', strtotime($reg['fecha']));
            $operador = htmlspecialchars($reg['usuario_nombre']);
            $estado = strtoupper($reg['estado_incidencia']);
            $badgeClass = "badge-{$reg['estado_incidencia']}";
            $url = htmlspecialchars($reg['url_cloudinary']);
            $uo = !empty($reg['unidad_obra_nombre']) ? ' | U.Obra: ' . htmlspecialchars($reg['unidad_obra_nombre']) : '';

            $html .= "<div class=\"foto-block\">
                <div class=\"foto-meta\">
                    <strong>{$fecha}</strong> &mdash; Operador: {$operador}
                    &mdash; <span class=\"{$badgeClass}\">{$estado}</span>
                    &mdash; GPS: {$reg['lat_real']}, {$reg['lon_real']}{$uo}
                </div>
                <img src=\"{$url}\" alt=\"Inspección\">
            </div>";
        }
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

$filename = "informe_{$infra['codigo_unico']}_" . date('Ymd_His') . '.pdf';

$dompdf->stream($filename, ['Attachment' => true]);
