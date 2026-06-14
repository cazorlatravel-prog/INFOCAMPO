<?php
/**
 * INFOCAMPO SaaS - Importar Infraestructuras desde KML + Excel combinado
 *
 * Recibe un archivo KML (coordenadas) y un Excel (datos: municipio, monte, etc.)
 * y los cruza por nombre para crear infraestructuras completas.
 *
 * Matching por nombre: compara el <name> del Placemark KML con la columna "nombre" del Excel.
 * - Match exacto (case-insensitive)
 * - Match parcial (uno contiene al otro)
 * - Match por similitud (similar_text > 80%)
 *
 * El resultado es: coordenadas del KML + atributos del Excel en un solo registro.
 * Puntos KML sin match en Excel se importan solo con coordenadas.
 * Filas Excel sin match en KML se importan sin coordenadas.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

// Verificar Composer para PhpSpreadsheet
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Dependencias no instaladas. Ejecuta "composer install" en el servidor.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $autoloadPath;

use PhpOffice\PhpSpreadsheet\IOFactory;

requireRole(['admin', 'superadmin']);

$pdo = getDB();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Detectar si PHP descartó los datos por exceder post_max_size
if (empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > 0) {
    $maxSize = ini_get('post_max_size');
    http_response_code(413);
    echo json_encode([
        'ok' => false,
        'error' => "Los archivos exceden el límite del servidor (post_max_size: {$maxSize}).",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!validateCsrf()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$empresaId = getEmpresaIdSeguro();
if ($empresaId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Empresa no identificada'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------
// 1) Validar y leer archivo KML
// ---------------------------------------------------------------
if (!isset($_FILES['archivo_kml']) || $_FILES['archivo_kml']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No se recibió el archivo KML'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_FILES['archivo_kml']['size'] > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'El archivo KML supera los 10MB'], JSON_UNESCAPED_UNICODE);
    exit;
}

$kmlContent = '';
$kmlExt = strtolower(pathinfo($_FILES['archivo_kml']['name'], PATHINFO_EXTENSION));

if ($kmlExt === 'kmz') {
    $zip = new ZipArchive();
    if ($zip->open($_FILES['archivo_kml']['tmp_name']) !== true) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No se pudo abrir el archivo KMZ'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'kml') {
            $kmlContent = $zip->getFromIndex($i);
            break;
        }
    }
    $zip->close();
    if ($kmlContent === '' || $kmlContent === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No se encontró un archivo KML dentro del KMZ'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} elseif ($kmlExt === 'kml') {
    $kmlContent = file_get_contents($_FILES['archivo_kml']['tmp_name']);
    if ($kmlContent === false || $kmlContent === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'El archivo KML está vacío'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Formato KML no soportado. Solo .kml y .kmz'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Parsear KML
libxml_use_internal_errors(true);
$xml = simplexml_load_string($kmlContent);
if ($xml === false) {
    $errors = libxml_get_errors();
    $errorDetail = !empty($errors) ? $errors[0]->message : 'XML inválido';
    libxml_clear_errors();
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Error al parsear el KML: ' . trim($errorDetail)], JSON_UNESCAPED_UNICODE);
    exit;
}

$namespaces = $xml->getNamespaces(true);
$kmlNs = $namespaces[''] ?? 'http://www.opengis.net/kml/2.2';

$placemarks = [];
extractPlacemarks($xml, $kmlNs, $placemarks);

if (empty($placemarks)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No se encontraron puntos (Placemarks) en el KML'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------
// 2) Validar y leer archivo Excel
// ---------------------------------------------------------------
if (!isset($_FILES['archivo_excel']) || $_FILES['archivo_excel']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No se recibió el archivo Excel'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_FILES['archivo_excel']['size'] > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'El archivo Excel supera los 10MB'], JSON_UNESCAPED_UNICODE);
    exit;
}

$excelExt = strtolower(pathinfo($_FILES['archivo_excel']['name'], PATHINFO_EXTENSION));
if (!in_array($excelExt, ['xlsx', 'xls', 'csv'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Formato Excel no soportado. Solo .xlsx, .xls o .csv'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $spreadsheet = IOFactory::load($_FILES['archivo_excel']['tmp_name']);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, true);
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Error al leer el Excel: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

if (count($rows) < 2) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'El Excel está vacío o solo tiene cabecera'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Mapear cabeceras
$headerRow = array_shift($rows);
$colMap = [];
$aliases = [
    'nombre'      => ['nombre', 'name', 'infraestructura', 'infra', 'denominacion', 'denominación'],
    'codigo'      => ['codigo', 'código', 'code', 'codigo_unico', 'código_unico', 'ref', 'referencia'],
    'tipo'        => ['tipo', 'type', 'categoria', 'categoría', 'category'],
    'provincia'   => ['provincia', 'province', 'state', 'comunidad'],
    'municipio'   => ['municipio', 'municipality', 'localidad', 'ciudad', 'city', 'termino_municipal', 'término_municipal', 'termino municipal', 'término municipal'],
    'monte'       => ['monte', 'forest', 'bosque', 'zona_forestal', 'zona forestal', 'monte_publico', 'monte público', 'monte publico'],
    'descripcion' => ['descripcion', 'descripción', 'description', 'observaciones', 'notas', 'localizacion', 'localización', 'ubicacion', 'ubicación'],
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
        'error' => 'No se encontró la columna "nombre" en el Excel. Columnas detectadas: ' .
                   implode(', ', array_filter(array_map(fn($v) => trim((string) ($v ?? '')), $headerRow))),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------
// 3) Construir array de filas Excel indexado para matching
// ---------------------------------------------------------------
$excelRows = [];
foreach ($rows as $row) {
    $nombre = trim((string) ($row[$colMap['nombre']] ?? ''));
    if ($nombre === '') continue;

    $excelRows[] = [
        'nombre'      => $nombre,
        'nombre_lower' => mb_strtolower($nombre),
        'codigo'      => isset($colMap['codigo'])      ? trim((string) ($row[$colMap['codigo']] ?? ''))      : '',
        'tipo'        => isset($colMap['tipo'])         ? trim((string) ($row[$colMap['tipo']] ?? ''))        : '',
        'provincia'   => isset($colMap['provincia'])    ? trim((string) ($row[$colMap['provincia']] ?? ''))   : '',
        'municipio'   => isset($colMap['municipio'])    ? trim((string) ($row[$colMap['municipio']] ?? ''))   : '',
        'monte'       => isset($colMap['monte'])        ? trim((string) ($row[$colMap['monte']] ?? ''))      : '',
        'descripcion' => isset($colMap['descripcion'])  ? trim((string) ($row[$colMap['descripcion']] ?? '')) : '',
        'matched'     => false,
    ];
}

// ---------------------------------------------------------------
// 4) Matching: KML placemarks <-> Excel rows por nombre
// ---------------------------------------------------------------
$matchResults = []; // Cada entrada tiene: kml data + excel data (si match)

foreach ($placemarks as $idx => $pm) {
    if ($pm['lat'] === null || $pm['lon'] === null) continue;

    $kmlNombre = $pm['nombre'] ?: 'Punto KML ' . ($idx + 1);
    $kmlNombreLower = mb_strtolower(trim($kmlNombre));

    $bestMatch = null;
    $bestMatchIdx = -1;
    $bestScore = 0;

    foreach ($excelRows as $exIdx => &$exRow) {
        if ($exRow['matched']) continue;

        // 1) Match exacto
        if ($exRow['nombre_lower'] === $kmlNombreLower) {
            $bestMatch = $exRow;
            $bestMatchIdx = $exIdx;
            $bestScore = 100;
            break;
        }

        // 2) Match parcial (uno contiene al otro)
        if ($kmlNombreLower !== '' && $exRow['nombre_lower'] !== '') {
            if (mb_strpos($exRow['nombre_lower'], $kmlNombreLower) !== false ||
                mb_strpos($kmlNombreLower, $exRow['nombre_lower']) !== false) {
                if ($bestScore < 90) {
                    $bestMatch = $exRow;
                    $bestMatchIdx = $exIdx;
                    $bestScore = 90;
                }
            }
        }

        // 3) Match por similitud > 80%
        if ($bestScore < 80) {
            similar_text($kmlNombreLower, $exRow['nombre_lower'], $percent);
            if ($percent > 80 && $percent > $bestScore) {
                $bestMatch = $exRow;
                $bestMatchIdx = $exIdx;
                $bestScore = $percent;
            }
        }
    }
    unset($exRow);

    if ($bestMatch !== null && $bestMatchIdx >= 0) {
        $excelRows[$bestMatchIdx]['matched'] = true;
    }

    $matchResults[] = [
        'kml_nombre'  => $kmlNombre,
        'lat'         => $pm['lat'],
        'lon'         => $pm['lon'],
        'kml_tipo'    => $pm['tipo'],
        'kml_provincia' => $pm['provincia'],
        'kml_municipio' => $pm['municipio'],
        'kml_monte'   => $pm['monte'],
        'kml_desc'    => $pm['descripcion'],
        'excel'       => $bestMatch,
        'match_score' => $bestScore,
    ];
}

// Filas Excel sin match → importar sin coordenadas
$excelSinMatch = [];
foreach ($excelRows as $exRow) {
    if (!$exRow['matched']) {
        $excelSinMatch[] = $exRow;
    }
}

// ---------------------------------------------------------------
// 5) Cargar existentes para detección de duplicados
// ---------------------------------------------------------------
$existStmt = $pdo->prepare(
    "SELECT id, nombre, codigo_unico, lat_teorica, lon_teorica
     FROM infraestructuras WHERE empresa_id = :emp_id"
);
$existStmt->execute([':emp_id' => $empresaId]);
$existentes = $existStmt->fetchAll();

$opcionDuplicados = $_POST['duplicados'] ?? 'omitir';

// ---------------------------------------------------------------
// 7) Insertar registros combinados
// ---------------------------------------------------------------
$insertStmt = $pdo->prepare(
    "INSERT INTO infraestructuras (empresa_id, nombre, codigo_unico, lat_teorica, lon_teorica, tipo, provincia, municipio, monte, descripcion, activa)
     VALUES (:emp_id, :nombre, :codigo, :lat, :lon, :tipo, :provincia, :municipio, :monte, :desc, 1)"
);

$importados = 0;
$omitidos = 0;
$conMatch = 0;
$sinMatch = 0;
$errores = [];
$detalles = [];

$pdo->beginTransaction();

try {
    // A) Insertar puntos KML (con o sin datos de Excel)
    foreach ($matchResults as $mr) {
        // Combinar datos: Excel tiene prioridad para atributos, KML para coordenadas
        $excel = $mr['excel'];
        $nombre     = $excel ? $excel['nombre'] : $mr['kml_nombre'];
        $codigo     = ($excel && $excel['codigo'] !== '') ? $excel['codigo'] : '';
        $tipo       = ($excel && $excel['tipo'] !== '')   ? $excel['tipo']   : ($mr['kml_tipo'] ?: '');
        $provincia  = ($excel && $excel['provincia'] !== '') ? $excel['provincia'] : ($mr['kml_provincia'] ?: '');
        $municipio  = ($excel && $excel['municipio'] !== '') ? $excel['municipio'] : ($mr['kml_municipio'] ?: '');
        $monte      = ($excel && $excel['monte'] !== '')     ? $excel['monte']     : ($mr['kml_monte'] ?: '');
        $descripcion = ($excel && $excel['descripcion'] !== '') ? $excel['descripcion'] : ($mr['kml_desc'] ?: '');
        $lat = $mr['lat'];
        $lon = $mr['lon'];

        // Auto-generar código
        if ($codigo === '') {
            $codigo = 'CMB-' . strtoupper(substr(md5($nombre . $lat . $lon . $importados), 0, 8));
        }

        // Detección de duplicados
        if ($opcionDuplicados === 'omitir') {
            $esDuplicado = false;
            foreach ($existentes as $ex) {
                if (mb_strtolower(trim($ex['nombre'])) === mb_strtolower(trim($nombre))) {
                    $esDuplicado = true;
                    break;
                }
                if ($ex['lat_teorica'] && $ex['lon_teorica']) {
                    $distLat = abs((float) $ex['lat_teorica'] - $lat);
                    $distLon = abs((float) $ex['lon_teorica'] - $lon);
                    if ($distLat < 0.00001 && $distLon < 0.00001) {
                        $esDuplicado = true;
                        break;
                    }
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
            $codigo = 'CMB-' . strtoupper(substr(md5($nombre . $lat . $lon . $importados . microtime()), 0, 8));
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
            ':monte'     => $monte !== '' ? mb_substr($monte, 0, 200) : null,
            ':desc'      => $descripcion !== '' ? mb_substr($descripcion, 0, 500) : null,
        ]);

        $importados++;
        if ($excel) {
            $conMatch++;
        } else {
            $sinMatch++;
        }

        $detalles[] = [
            'nombre'    => $nombre,
            'codigo'    => $codigo,
            'lat'       => $lat,
            'lon'       => $lon,
            'provincia' => $provincia,
            'municipio' => $municipio,
            'monte'     => $monte,
            'match'     => $excel ? 'KML+Excel (score: ' . round($mr['match_score']) . '%)' : 'Solo KML',
        ];

        $existentes[] = [
            'id' => $pdo->lastInsertId(),
            'nombre' => $nombre,
            'codigo_unico' => $codigo,
            'lat_teorica' => $lat,
            'lon_teorica' => $lon,
        ];
    }

    // B) Insertar filas Excel sin match (sin coordenadas)
    foreach ($excelSinMatch as $exRow) {

        $nombre = $exRow['nombre'];
        $codigo = $exRow['codigo'] !== '' ? $exRow['codigo'] : 'XLS-' . strtoupper(substr(md5($nombre . $importados . microtime()), 0, 8));

        // Detección de duplicados
        if ($opcionDuplicados === 'omitir') {
            $esDuplicado = false;
            foreach ($existentes as $ex) {
                if (mb_strtolower(trim($ex['nombre'])) === mb_strtolower(trim($nombre))) {
                    $esDuplicado = true;
                    break;
                }
            }
            if ($esDuplicado) {
                $omitidos++;
                continue;
            }
        }

        $checkCode = $pdo->prepare(
            "SELECT COUNT(*) FROM infraestructuras WHERE empresa_id = :emp AND codigo_unico = :cod"
        );
        $checkCode->execute([':emp' => $empresaId, ':cod' => $codigo]);
        if ((int) $checkCode->fetchColumn() > 0) {
            $codigo = 'XLS-' . strtoupper(substr(md5($nombre . $importados . microtime() . rand()), 0, 8));
        }

        $insertStmt->execute([
            ':emp_id'    => $empresaId,
            ':nombre'    => mb_substr($nombre, 0, 250),
            ':codigo'    => $codigo,
            ':lat'       => 0,
            ':lon'       => 0,
            ':tipo'      => $exRow['tipo'] !== '' ? mb_substr($exRow['tipo'], 0, 100) : null,
            ':provincia' => $exRow['provincia'] !== '' ? mb_substr($exRow['provincia'], 0, 100) : null,
            ':municipio' => $exRow['municipio'] !== '' ? mb_substr($exRow['municipio'], 0, 150) : null,
            ':monte'     => $exRow['monte'] !== '' ? mb_substr($exRow['monte'], 0, 200) : null,
            ':desc'      => $exRow['descripcion'] !== '' ? mb_substr($exRow['descripcion'], 0, 500) : null,
        ]);

        $importados++;
        $sinMatch++;

        $detalles[] = [
            'nombre'    => $nombre,
            'codigo'    => $codigo,
            'lat'       => 0,
            'lon'       => 0,
            'provincia' => $exRow['provincia'],
            'municipio' => $exRow['municipio'],
            'monte'     => $exRow['monte'],
            'match'     => 'Solo Excel (sin GPS)',
        ];

        $existentes[] = [
            'id' => $pdo->lastInsertId(),
            'nombre' => $nombre,
            'codigo_unico' => $codigo,
            'lat_teorica' => 0,
            'lon_teorica' => 0,
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
    'ok'              => true,
    'importados'      => $importados,
    'omitidos'        => $omitidos,
    'con_match'       => $conMatch,
    'sin_match_kml'   => $sinMatch,
    'total_kml'       => count($placemarks),
    'total_excel'     => count($excelRows),
    'excel_sin_match' => count($excelSinMatch),
    'errores'         => $errores,
    'detalles'        => $detalles,
], JSON_UNESCAPED_UNICODE);
exit;

// ---------------------------------------------------------------
// Función recursiva para extraer Placemarks del KML
// (misma que importar_kml.php)
// ---------------------------------------------------------------
function extractPlacemarks(SimpleXMLElement $element, string $ns, array &$result): void
{
    $element->registerXPathNamespace('kml', $ns);

    $marks = $element->xpath('.//kml:Placemark');
    if (!$marks) {
        $marks = $element->xpath('.//Placemark');
    }

    if ($marks) {
        foreach ($marks as $pm) {
            $pm->registerXPathNamespace('kml', $ns);

            $nameNodes = $pm->xpath('kml:name');
            if (!$nameNodes) $nameNodes = $pm->xpath('name');
            $nombre = $nameNodes ? trim((string) $nameNodes[0]) : '';

            $descNodes = $pm->xpath('kml:description');
            if (!$descNodes) $descNodes = $pm->xpath('description');
            $descripcion = $descNodes ? trim(strip_tags((string) $descNodes[0])) : '';

            $lat = null;
            $lon = null;

            // Point
            $coordNodes = $pm->xpath('.//kml:Point/kml:coordinates');
            if (!$coordNodes) $coordNodes = $pm->xpath('.//Point/coordinates');

            if ($coordNodes) {
                $coordStr = trim((string) $coordNodes[0]);
                $parts = explode(',', $coordStr);
                if (count($parts) >= 2) {
                    $lon = (float) trim($parts[0]);
                    $lat = (float) trim($parts[1]);
                }
            }

            // LineString / Polygon centroid
            if ($lat === null || $lon === null) {
                $lineNodes = $pm->xpath('.//kml:LineString/kml:coordinates');
                if (!$lineNodes) $lineNodes = $pm->xpath('.//LineString/coordinates');
                if (!$lineNodes) {
                    $lineNodes = $pm->xpath('.//kml:Polygon//kml:coordinates');
                    if (!$lineNodes) $lineNodes = $pm->xpath('.//Polygon//coordinates');
                }

                if ($lineNodes) {
                    $coordStr = trim((string) $lineNodes[0]);
                    $points = preg_split('/\s+/', $coordStr);
                    $sumLat = 0.0;
                    $sumLon = 0.0;
                    $count = 0;
                    foreach ($points as $pt) {
                        $parts = explode(',', trim($pt));
                        if (count($parts) >= 2) {
                            $pLon = (float) $parts[0];
                            $pLat = (float) $parts[1];
                            if ($pLat != 0.0 || $pLon != 0.0) {
                                $sumLon += $pLon;
                                $sumLat += $pLat;
                                $count++;
                            }
                        }
                    }
                    if ($count > 0) {
                        $lon = round($sumLon / $count, 7);
                        $lat = round($sumLat / $count, 7);
                    }
                }
            }

            // ExtendedData
            $tipo = '';
            $provincia = '';
            $municipio = '';
            $monte = '';

            $extData = $pm->xpath('.//kml:ExtendedData/kml:Data');
            if (!$extData) $extData = $pm->xpath('.//ExtendedData/Data');

            if ($extData) {
                foreach ($extData as $data) {
                    $attrName = mb_strtolower(trim((string) ($data['name'] ?? '')));
                    $valueNodes = $data->xpath('kml:value');
                    if (!$valueNodes) $valueNodes = $data->xpath('value');
                    $value = $valueNodes ? trim((string) $valueNodes[0]) : '';

                    if (in_array($attrName, ['tipo', 'type', 'category', 'categoria'])) {
                        $tipo = $value;
                    } elseif (in_array($attrName, ['provincia', 'province', 'state'])) {
                        $provincia = $value;
                    } elseif (in_array($attrName, ['municipio', 'municipality', 'city', 'ciudad', 'localidad'])) {
                        $municipio = $value;
                    } elseif (in_array($attrName, ['monte', 'forest', 'bosque', 'zona_forestal'])) {
                        $monte = $value;
                    }
                }
            }

            // SchemaData
            $simpleData = $pm->xpath('.//kml:ExtendedData/kml:SchemaData/kml:SimpleData');
            if (!$simpleData) $simpleData = $pm->xpath('.//ExtendedData/SchemaData/SimpleData');

            if ($simpleData) {
                foreach ($simpleData as $sd) {
                    $attrName = mb_strtolower(trim((string) ($sd['name'] ?? '')));
                    $value = trim((string) $sd);

                    if (in_array($attrName, ['tipo', 'type', 'category', 'categoria'])) {
                        $tipo = $value;
                    } elseif (in_array($attrName, ['provincia', 'province', 'state'])) {
                        $provincia = $value;
                    } elseif (in_array($attrName, ['municipio', 'municipality', 'city', 'ciudad', 'localidad'])) {
                        $municipio = $value;
                    } elseif (in_array($attrName, ['monte', 'forest', 'bosque', 'zona_forestal'])) {
                        $monte = $value;
                    }
                }
            }

            $result[] = [
                'nombre'      => $nombre,
                'descripcion' => $descripcion,
                'lat'         => $lat,
                'lon'         => $lon,
                'tipo'        => $tipo,
                'provincia'   => $provincia,
                'municipio'   => $municipio,
                'monte'       => $monte,
            ];
        }
    }
}
