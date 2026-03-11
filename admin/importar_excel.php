<?php
/**
 * INFOCAMPO SaaS - Importar Infraestructuras desde Excel
 *
 * Acepta archivos .xlsx / .xls / .csv con datos de infraestructuras.
 * Las infraestructuras importadas NO necesitan coordenadas GPS;
 * la georreferenciación se realiza cuando el operador toma fotos en campo.
 *
 * Columnas reconocidas (flexible, case-insensitive):
 *   nombre (obligatorio), codigo, tipo, provincia, municipio, descripcion, lat, lon
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

requireRole(['admin', 'superadmin']);

$pdo = getDB();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!validateCsrf()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$empresaId = (int) ($_POST['empresa_id'] ?? $_SESSION['empresa_id'] ?? 0);
if ($empresaId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Empresa no identificada'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Validar archivo subido
if (!isset($_FILES['archivo_excel']) || $_FILES['archivo_excel']['error'] !== UPLOAD_ERR_OK) {
    $errorMsg = match ($_FILES['archivo_excel']['error'] ?? -1) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo es demasiado grande (máx. 10MB)',
        UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo',
        default => 'Error al subir el archivo',
    };
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $errorMsg], JSON_UNESCAPED_UNICODE);
    exit;
}

$tmpFile  = $_FILES['archivo_excel']['tmp_name'];
$fileName = $_FILES['archivo_excel']['name'];
$fileSize = $_FILES['archivo_excel']['size'];

if ($fileSize > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'El archivo supera los 10MB permitidos'], JSON_UNESCAPED_UNICODE);
    exit;
}

$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Formato no soportado. Solo .xlsx, .xls o .csv'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Leer el archivo Excel/CSV
try {
    $spreadsheet = IOFactory::load($tmpFile);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, true);
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Error al leer el archivo: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

if (count($rows) < 2) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'El archivo está vacío o solo tiene cabecera'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------
// Mapear cabeceras (primera fila) a índices de columna
// ---------------------------------------------------------------
$headerRow = array_shift($rows);
$colMap = [];
$aliases = [
    'nombre'      => ['nombre', 'name', 'infraestructura', 'infra', 'denominacion', 'denominación'],
    'codigo'      => ['codigo', 'código', 'code', 'codigo_unico', 'código_unico', 'ref', 'referencia'],
    'tipo'        => ['tipo', 'type', 'categoria', 'categoría', 'category'],
    'provincia'   => ['provincia', 'province', 'state', 'comunidad'],
    'municipio'   => ['municipio', 'municipality', 'localidad', 'ciudad', 'city', 'termino_municipal', 'término_municipal', 'termino municipal', 'término municipal'],
    'descripcion' => ['descripcion', 'descripción', 'description', 'observaciones', 'notas', 'localizacion', 'localización', 'ubicacion', 'ubicación'],
    'lat'         => ['lat', 'latitud', 'latitude', 'lat_teorica'],
    'lon'         => ['lon', 'lng', 'longitud', 'longitude', 'lon_teorica'],
];

foreach ($headerRow as $colLetter => $headerValue) {
    if ($headerValue === null) continue;
    $normalized = mb_strtolower(trim((string) $headerValue));
    $normalized = preg_replace('/[\s_\-]+/', '_', $normalized);
    foreach ($aliases as $field => $options) {
        foreach ($options as $opt) {
            $optNorm = preg_replace('/[\s_\-]+/', '_', $opt);
            if ($normalized === $optNorm) {
                $colMap[$field] = $colLetter;
                break 2;
            }
        }
    }
}

if (!isset($colMap['nombre'])) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'No se encontró la columna "nombre" en la cabecera. Columnas detectadas: ' .
                   implode(', ', array_filter(array_map(fn($v) => trim((string) ($v ?? '')), $headerRow))),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------
// Verificar límite de infraestructuras
// ---------------------------------------------------------------
$empStmt = $pdo->prepare("SELECT max_infraestructuras FROM empresas WHERE id = :id");
$empStmt->execute([':id' => $empresaId]);
$empresaData = $empStmt->fetch();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM infraestructuras WHERE empresa_id = :id");
$countStmt->execute([':id' => $empresaId]);
$currentCount = (int) $countStmt->fetchColumn();

$maxInfras = $empresaData ? (int) $empresaData['max_infraestructuras'] : 0;
$disponibles = $maxInfras - $currentCount;

if ($disponibles <= 0) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => "Límite de infraestructuras alcanzado ($maxInfras). No se pueden importar más.",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------
// Cargar existentes para detección de duplicados
// ---------------------------------------------------------------
$existStmt = $pdo->prepare(
    "SELECT id, nombre, codigo_unico FROM infraestructuras WHERE empresa_id = :emp_id"
);
$existStmt->execute([':emp_id' => $empresaId]);
$existentes = $existStmt->fetchAll();

$opcionDuplicados = $_POST['duplicados'] ?? 'omitir';

// ---------------------------------------------------------------
// Insertar filas
// ---------------------------------------------------------------
$insertStmt = $pdo->prepare(
    "INSERT INTO infraestructuras (empresa_id, nombre, codigo_unico, lat_teorica, lon_teorica, tipo, provincia, municipio, descripcion, activa)
     VALUES (:emp_id, :nombre, :codigo, :lat, :lon, :tipo, :provincia, :municipio, :desc, 1)"
);

$importados = 0;
$omitidos   = 0;
$errores    = [];
$detalles   = [];

$pdo->beginTransaction();

try {
    $rowNum = 1; // para reportar errores (fila 1 = primera fila de datos)
    foreach ($rows as $row) {
        $rowNum++;
        $nombre = trim((string) ($row[$colMap['nombre']] ?? ''));
        if ($nombre === '') {
            $omitidos++;
            continue;
        }

        // Verificar límite
        if (($currentCount + $importados) >= $maxInfras) {
            $errores[] = "Límite de infraestructuras alcanzado. Se importaron $importados filas.";
            break;
        }

        $codigo     = isset($colMap['codigo'])      ? trim((string) ($row[$colMap['codigo']] ?? ''))      : '';
        $tipo       = isset($colMap['tipo'])         ? trim((string) ($row[$colMap['tipo']] ?? ''))        : '';
        $provincia  = isset($colMap['provincia'])    ? trim((string) ($row[$colMap['provincia']] ?? ''))   : '';
        $municipio  = isset($colMap['municipio'])    ? trim((string) ($row[$colMap['municipio']] ?? ''))   : '';
        $descripcion = isset($colMap['descripcion']) ? trim((string) ($row[$colMap['descripcion']] ?? '')) : '';
        $lat        = isset($colMap['lat'])          ? (float) ($row[$colMap['lat']] ?? 0)                : 0.0;
        $lon        = isset($colMap['lon'])          ? (float) ($row[$colMap['lon']] ?? 0)                : 0.0;

        // Auto-generar código si vacío
        if ($codigo === '') {
            $codigo = 'XLS-' . strtoupper(substr(md5($nombre . $rowNum . time()), 0, 8));
        }

        // Detección de duplicados por nombre
        if ($opcionDuplicados === 'omitir') {
            $esDuplicado = false;
            foreach ($existentes as $ex) {
                if (mb_strtolower(trim($ex['nombre'])) === mb_strtolower($nombre)) {
                    $esDuplicado = true;
                    break;
                }
                if (mb_strtolower(trim($ex['codigo_unico'])) === mb_strtolower($codigo)) {
                    $esDuplicado = true;
                    break;
                }
            }
            if ($esDuplicado) {
                $omitidos++;
                continue;
            }
        }

        // Asegurar código único
        $checkCode = $pdo->prepare(
            "SELECT COUNT(*) FROM infraestructuras WHERE empresa_id = :emp AND codigo_unico = :cod"
        );
        $checkCode->execute([':emp' => $empresaId, ':cod' => $codigo]);
        if ((int) $checkCode->fetchColumn() > 0) {
            $codigo = 'XLS-' . strtoupper(substr(md5($nombre . $rowNum . microtime()), 0, 8));
        }

        $insertStmt->execute([
            ':emp_id'    => $empresaId,
            ':nombre'    => mb_substr($nombre, 0, 250),
            ':codigo'    => $codigo,
            ':lat'       => round($lat, 7),
            ':lon'       => round($lon, 7),
            ':tipo'      => $tipo !== '' ? mb_substr($tipo, 0, 100) : null,
            ':provincia' => $provincia !== '' ? mb_substr($provincia, 0, 100) : null,
            ':municipio' => $municipio !== '' ? mb_substr($municipio, 0, 150) : null,
            ':desc'      => $descripcion !== '' ? mb_substr($descripcion, 0, 500) : null,
        ]);

        $importados++;
        $detalles[] = [
            'nombre'    => $nombre,
            'codigo'    => $codigo,
            'provincia' => $provincia,
            'municipio' => $municipio,
        ];

        $existentes[] = [
            'id'            => $pdo->lastInsertId(),
            'nombre'        => $nombre,
            'codigo_unico'  => $codigo,
        ];
    }

    $pdo->commit();
} catch (\Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Error al importar: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok'         => true,
    'importados' => $importados,
    'omitidos'   => $omitidos,
    'total_filas' => count($rows),
    'errores'    => $errores,
    'detalles'   => $detalles,
], JSON_UNESCAPED_UNICODE);
