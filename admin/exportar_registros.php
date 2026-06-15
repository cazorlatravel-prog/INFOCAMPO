<?php
/**
 * INFOCAMPO - Exportar registros a Excel (.xlsx)
 *
 * Genera un archivo .xlsx con formato usando PhpSpreadsheet.
 * Incluye cabecera con información de la empresa, rango de fechas y filtros aplicados.
 * Los estados de incidencia tienen formato condicional por color.
 *
 * Parámetros GET:
 *   - infra_id: (opcional) filtrar por infraestructura
 *   - estado: (opcional) filtrar por estado de incidencia (antes/durante/despues)
 *   - fecha_desde, fecha_hasta: (opcional) rango de fechas
 *   - usuario_id: (opcional) filtrar por operador
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

requireRole(['admin', 'superadmin', 'supervisor']);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Border;

$pdo = getDB();
$empresaId = getEmpresaIdSeguro();

if ($empresaId <= 0) {
    http_response_code(400);
    echo 'Empresa no identificada';
    exit;
}

// Obtener nombre de la empresa
$empStmt = $pdo->prepare("SELECT nombre FROM empresas WHERE id = :id");
$empStmt->execute([':id' => $empresaId]);
$empRow = $empStmt->fetch();
$empNombre = $empRow ? $empRow['nombre'] : 'Empresa';
$empNombreArchivo = preg_replace('/[^a-zA-Z0-9_-]/', '_', $empNombre);

// ---------------------------------------------------------------
// Filtros
// ---------------------------------------------------------------
$infraId = (int) ($_GET['infra_id'] ?? 0);
$estado = $_GET['estado'] ?? '';
$fechaDesde = $_GET['fecha_desde'] ?? '';
$fechaHasta = $_GET['fecha_hasta'] ?? '';
$usuarioId = (int) ($_GET['usuario_id'] ?? 0);

// ---------------------------------------------------------------
// Consulta principal
// ---------------------------------------------------------------
$sql = "SELECT r.*, i.nombre AS infra_nombre, i.codigo_unico,
               u.nombre AS operador,
               uo.nombre AS unidad_obra_nombre
        FROM registros r
        INNER JOIN infraestructuras i ON r.infra_id = i.id
        INNER JOIN usuarios u ON r.usuario_id = u.id
        LEFT JOIN unidades_obra uo ON r.unidad_obra_id = uo.id
        WHERE i.empresa_id = :emp";
$params = [':emp' => $empresaId];

if ($infraId > 0) {
    $sql .= " AND r.infra_id = :infra_id";
    $params[':infra_id'] = $infraId;
}

if ($estado !== '' && in_array($estado, ['antes', 'durante', 'despues'], true)) {
    $sql .= " AND r.estado_incidencia = :estado";
    $params[':estado'] = $estado;
}

if ($fechaDesde !== '') {
    $sql .= " AND r.fecha >= :f_desde";
    $params[':f_desde'] = $fechaDesde . ' 00:00:00';
}

if ($fechaHasta !== '') {
    $sql .= " AND r.fecha <= :f_hasta";
    $params[':f_hasta'] = $fechaHasta . ' 23:59:59';
}

if ($usuarioId > 0) {
    $sql .= " AND r.usuario_id = :usuario_id";
    $params[':usuario_id'] = $usuarioId;
}

$sql .= " ORDER BY r.fecha DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$registros = $stmt->fetchAll();

// Obtener nombre de infraestructura si se filtró
$infraNombre = '';
if ($infraId > 0) {
    $infraStmt = $pdo->prepare("SELECT nombre FROM infraestructuras WHERE id = :id AND empresa_id = :emp");
    $infraStmt->execute([':id' => $infraId, ':emp' => $empresaId]);
    $infraRow = $infraStmt->fetch();
    $infraNombre = $infraRow ? $infraRow['nombre'] : '';
}

// Obtener nombre del operador si se filtró
$operadorNombre = '';
if ($usuarioId > 0) {
    $opStmt = $pdo->prepare("SELECT nombre FROM usuarios WHERE id = :id AND empresa_id = :emp");
    $opStmt->execute([':id' => $usuarioId, ':emp' => $empresaId]);
    $opRow = $opStmt->fetch();
    $operadorNombre = $opRow ? $opRow['nombre'] : '';
}

// ---------------------------------------------------------------
// Generar Excel
// ---------------------------------------------------------------
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Registros');

// --- Resumen en cabecera ---
$sheet->setCellValue('A1', 'Empresa:');
$sheet->setCellValue('B1', $empNombre);
$sheet->setCellValue('A2', 'Fecha exportación:');
$sheet->setCellValue('B2', date('d/m/Y H:i'));
$sheet->setCellValue('A3', 'Total registros:');
$sheet->setCellValue('B3', count($registros));

// Información de filtros
$filtrosTexto = [];
if ($infraNombre !== '') {
    $filtrosTexto[] = 'Infraestructura: ' . $infraNombre;
}
if ($estado !== '') {
    $filtrosTexto[] = 'Estado: ' . ucfirst($estado);
}
if ($fechaDesde !== '') {
    $filtrosTexto[] = 'Desde: ' . $fechaDesde;
}
if ($fechaHasta !== '') {
    $filtrosTexto[] = 'Hasta: ' . $fechaHasta;
}
if ($operadorNombre !== '') {
    $filtrosTexto[] = 'Operador: ' . $operadorNombre;
}

$sheet->setCellValue('A4', 'Filtros:');
$sheet->setCellValue('B4', !empty($filtrosTexto) ? implode(' | ', $filtrosTexto) : 'Sin filtros');

// Estilo de la sección de resumen
$sheet->getStyle('A1:A4')->getFont()->setBold(true);
$sheet->getStyle('A1:B4')->getFont()->setSize(10);

// --- Cabecera de datos (fila 6) ---
$headerRow = 6;
$headers = [
    'A' => 'Infraestructura',
    'B' => 'Código',
    'C' => 'Unidad de Obra',
    'D' => 'Operador',
    'E' => 'Fecha',
    'F' => 'Latitud Real',
    'G' => 'Longitud Real',
    'H' => 'Estado',
    'I' => 'Tipo Foto',
    'J' => 'Secuencia',
    'K' => 'Observaciones',
    'L' => 'URL Foto',
];

foreach ($headers as $col => $title) {
    $sheet->setCellValue($col . $headerRow, $title);
}

// Estilo de la cabecera
$headerRange = 'A' . $headerRow . ':L' . $headerRow;
$sheet->getStyle($headerRange)->applyFromArray([
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
        'size' => 11,
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '2E7D32'],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000'],
        ],
    ],
]);

// --- Datos ---
$dataRow = $headerRow + 1;
$estadoColores = [
    'antes' => 'BBDEFB',    // Azul claro
    'durante' => 'FFF9C4',  // Amarillo claro
    'despues' => 'C8E6C9',  // Verde claro
];

foreach ($registros as $reg) {
    $sheet->setCellValue('A' . $dataRow, $reg['infra_nombre']);
    $sheet->setCellValue('B' . $dataRow, $reg['codigo_unico']);
    $sheet->setCellValue('C' . $dataRow, $reg['unidad_obra_nombre'] ?? '');
    $sheet->setCellValue('D' . $dataRow, $reg['operador']);
    $sheet->setCellValue('E' . $dataRow, date('d/m/Y H:i', strtotime($reg['fecha'])));
    $sheet->setCellValue('F' . $dataRow, $reg['lat_real']);
    $sheet->setCellValue('G' . $dataRow, $reg['lon_real']);

    $estadoVal = $reg['estado_incidencia'] ?? '';
    $sheet->setCellValue('H' . $dataRow, ucfirst($estadoVal));

    // Colorear celda de estado
    if (isset($estadoColores[$estadoVal])) {
        $sheet->getStyle('H' . $dataRow)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB($estadoColores[$estadoVal]);
    }

    $sheet->setCellValue('I' . $dataRow, ucfirst($reg['tipo_foto'] ?? 'aleatorio'));
    $sheet->setCellValue('J' . $dataRow, $reg['secuencia_comparativa'] ?? '');
    $sheet->setCellValue('K' . $dataRow, $reg['observaciones'] ?? '');
    $sheet->setCellValue('L' . $dataRow, $reg['url_cloudinary'] ?? '');

    $dataRow++;
}

// --- Auto-ajuste de columnas ---
foreach (range('A', 'L') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// --- Formato adicional ---
// Alinear fechas al centro
$lastDataRow = $dataRow - 1;
if ($lastDataRow >= $headerRow + 1) {
    $sheet->getStyle('E' . ($headerRow + 1) . ':E' . $lastDataRow)
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('H' . ($headerRow + 1) . ':H' . $lastDataRow)
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('I' . ($headerRow + 1) . ':I' . $lastDataRow)
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('J' . ($headerRow + 1) . ':J' . $lastDataRow)
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

// ---------------------------------------------------------------
// Descargar archivo
// ---------------------------------------------------------------
$filename = 'registros_' . $empNombreArchivo . '_' . date('Y-m-d') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

$spreadsheet->disconnectWorksheets();
unset($spreadsheet);
