<?php
/**
 * INFOCAMPO - Importar Infraestructuras desde KML
 *
 * Acepta archivos KML/KMZ, extrae los Placemarks con coordenadas
 * y los inserta como infraestructuras de la empresa seleccionada.
 *
 * Soporta:
 * - <Placemark> con <Point> (lat/lon)
 * - <name> como nombre de infraestructura
 * - <description> como descripción
 * - <ExtendedData> para tipo, provincia, municipio
 * - Detección de duplicados por coordenadas o nombre
 * - Verificación de límite de licencia
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

// Solo admin y superadmin pueden importar
requireRole(['admin', 'superadmin']);

$pdo = getDB();

header('Content-Type: application/json; charset=utf-8');

// Solo POST
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
        'error' => "El archivo excede el límite del servidor (post_max_size: {$maxSize}). Contacta al administrador para aumentar el límite.",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Validar CSRF
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

// Validar archivo subido
if (!isset($_FILES['archivo_kml']) || $_FILES['archivo_kml']['error'] !== UPLOAD_ERR_OK) {
    $errorMsg = match ($_FILES['archivo_kml']['error'] ?? -1) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo es demasiado grande (máx. 10MB)',
        UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo',
        default => 'Error al subir el archivo',
    };
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $errorMsg], JSON_UNESCAPED_UNICODE);
    exit;
}

$tmpFile = $_FILES['archivo_kml']['tmp_name'];
$fileName = $_FILES['archivo_kml']['name'];
$fileSize = $_FILES['archivo_kml']['size'];

// Límite de 10MB
if ($fileSize > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'El archivo supera los 10MB permitidos'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Determinar si es KML o KMZ
$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$kmlContent = '';

if ($extension === 'kmz') {
    // KMZ es un ZIP que contiene un doc.kml
    $zip = new ZipArchive();
    if ($zip->open($tmpFile) !== true) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No se pudo abrir el archivo KMZ'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Buscar el archivo .kml dentro del ZIP
    $kmlFound = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'kml') {
            $kmlContent = $zip->getFromIndex($i);
            $kmlFound = true;
            break;
        }
    }
    $zip->close();
    if (!$kmlFound || $kmlContent === '' || $kmlContent === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No se encontró un archivo KML dentro del KMZ'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} elseif ($extension === 'kml') {
    $kmlContent = file_get_contents($tmpFile);
    if ($kmlContent === false || $kmlContent === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'El archivo KML está vacío o no se pudo leer'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Formato no soportado. Solo se aceptan archivos .kml y .kmz'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Parsear KML con SimpleXML
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

// Registrar namespaces KML
$namespaces = $xml->getNamespaces(true);
$kmlNs = $namespaces[''] ?? 'http://www.opengis.net/kml/2.2';

// Extraer todos los Placemarks (recursivo, soporta Folder y Document)
$placemarks = [];
extractPlacemarks($xml, $kmlNs, $placemarks);

if (empty($placemarks)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No se encontraron puntos (Placemark) en el archivo KML'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Cargar infraestructuras existentes para detección de duplicados
$existStmt = $pdo->prepare(
    "SELECT id, nombre, codigo_unico, lat_teorica, lon_teorica
     FROM infraestructuras WHERE empresa_id = :emp_id"
);
$existStmt->execute([':emp_id' => $empresaId]);
$existentes = $existStmt->fetchAll();

$opcionDuplicados = $_POST['duplicados'] ?? 'omitir'; // 'omitir' o 'importar'

// Preparar INSERT
$insertStmt = $pdo->prepare(dbReturningId(
    "INSERT INTO infraestructuras (empresa_id, nombre, codigo_unico, lat_teorica, lon_teorica, tipo, provincia, municipio, monte, descripcion, activa)
     VALUES (:emp_id, :nombre, :codigo, :lat, :lon, :tipo, :provincia, :municipio, :monte, :desc, 1)"
));

$importados = 0;
$omitidos = 0;
$errores = [];
$detalles = [];

$pdo->beginTransaction();

try {
    foreach ($placemarks as $idx => $pm) {
        // Validar que tenga coordenadas
        if ($pm['lat'] === null || $pm['lon'] === null) {
            $omitidos++;
            continue;
        }

        // Nombre obligatorio
        $nombre = $pm['nombre'] ?: 'Punto KML ' . ($idx + 1);

        // Detección de duplicados (por coordenadas cercanas o nombre exacto)
        if ($opcionDuplicados === 'omitir') {
            $esDuplicado = false;
            foreach ($existentes as $ex) {
                // Duplicado por nombre exacto
                if (mb_strtolower(trim($ex['nombre'])) === mb_strtolower(trim($nombre))) {
                    $esDuplicado = true;
                    break;
                }
                // Duplicado por coordenadas muy cercanas (< 1 metro)
                if ($ex['lat_teorica'] && $ex['lon_teorica']) {
                    $distLat = abs((float) $ex['lat_teorica'] - $pm['lat']);
                    $distLon = abs((float) $ex['lon_teorica'] - $pm['lon']);
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

        // Generar código único
        $codigo = 'KML-' . strtoupper(substr(md5($nombre . $pm['lat'] . $pm['lon'] . $idx), 0, 8));

        // Verificar que el código no exista ya
        $checkCode = $pdo->prepare(
            "SELECT COUNT(*) FROM infraestructuras WHERE empresa_id = :emp AND codigo_unico = :cod"
        );
        $checkCode->execute([':emp' => $empresaId, ':cod' => $codigo]);
        if ((int) $checkCode->fetchColumn() > 0) {
            $codigo = 'KML-' . strtoupper(substr(md5($nombre . $pm['lat'] . $pm['lon'] . $idx . time()), 0, 8));
        }

        $insertStmt->execute([
            ':emp_id'    => $empresaId,
            ':nombre'    => mb_substr($nombre, 0, 250),
            ':codigo'    => $codigo,
            ':lat'       => round($pm['lat'], 7),
            ':lon'       => round($pm['lon'], 7),
            ':tipo'      => $pm['tipo'] ? mb_substr($pm['tipo'], 0, 100) : null,
            ':provincia' => $pm['provincia'] ? mb_substr($pm['provincia'], 0, 100) : null,
            ':municipio' => $pm['municipio'] ? mb_substr($pm['municipio'], 0, 150) : null,
            ':monte'     => ($pm['monte'] ?? '') !== '' ? mb_substr($pm['monte'], 0, 200) : null,
            ':desc'      => $pm['descripcion'] ? mb_substr($pm['descripcion'], 0, 500) : null,
        ]);

        $importados++;
        $detalles[] = [
            'nombre' => $nombre,
            'codigo' => $codigo,
            'lat'    => $pm['lat'],
            'lon'    => $pm['lon'],
        ];

        // Añadir a la lista de existentes para evitar duplicados en la misma importación
        $existentes[] = [
            'id' => dbLastId($pdo, $insertStmt),
            'nombre' => $nombre,
            'codigo_unico' => $codigo,
            'lat_teorica' => $pm['lat'],
            'lon_teorica' => $pm['lon'],
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
    'total_kml'  => count($placemarks),
    'errores'    => $errores,
    'detalles'   => $detalles,
], JSON_UNESCAPED_UNICODE);
exit;

// ---------------------------------------------------------------
// Función recursiva para extraer Placemarks del KML
// ---------------------------------------------------------------
function extractPlacemarks(SimpleXMLElement $element, string $ns, array &$result): void
{
    $element->registerXPathNamespace('kml', $ns);

    // Buscar Placemarks directos
    $marks = $element->xpath('.//kml:Placemark');
    if (!$marks) {
        // Intentar sin namespace (algunos KML no usan namespace)
        $marks = $element->xpath('.//Placemark');
    }

    if ($marks) {
        foreach ($marks as $pm) {
            $pm->registerXPathNamespace('kml', $ns);

            // Extraer nombre
            $nameNodes = $pm->xpath('kml:name');
            if (!$nameNodes) $nameNodes = $pm->xpath('name');
            $nombre = $nameNodes ? trim((string) $nameNodes[0]) : '';

            // Extraer descripción
            $descNodes = $pm->xpath('kml:description');
            if (!$descNodes) $descNodes = $pm->xpath('description');
            $descripcion = $descNodes ? trim(strip_tags((string) $descNodes[0])) : '';

            // Extraer coordenadas: Point, LineString o Polygon (centroide)
            $lat = null;
            $lon = null;

            // 1) Intentar Point
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

            // 2) Si no hay Point, intentar LineString (centroide)
            if ($lat === null || $lon === null) {
                $lineNodes = $pm->xpath('.//kml:LineString/kml:coordinates');
                if (!$lineNodes) $lineNodes = $pm->xpath('.//LineString/coordinates');
                if (!$lineNodes) {
                    // 3) Intentar Polygon
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

            // Extraer datos extendidos si existen
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

            // También buscar en SimpleData (SchemaData)
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
