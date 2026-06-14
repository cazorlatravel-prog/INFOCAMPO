<?php
/**
 * INFOCAMPO - API de Capas de Infraestructuras (Admin)
 *
 * GET:  Listar capas activas de la empresa
 * POST action=analizar: Sube KML/KMZ/SHP(ZIP), parsea a GeoJSON, devuelve atributos
 * POST action=guardar:  Guarda la capa con el mapeo de campos
 * POST action=eliminar: Soft-delete
 * POST action=actualizar_estilo: Actualiza color/grosor/opacidad
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

requireRole(['admin', 'superadmin']);

header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();
// Solo superadmins pueden especificar empresa_id diferente
$empresaId = (int) ($_SESSION['empresa_id'] ?? 0);
if (($_SESSION['user_rol'] ?? '') === 'superadmin' && (isset($_GET['empresa_id']) || isset($_POST['empresa_id']))) {
    $empresaId = (int) ($_GET['empresa_id'] ?? $_POST['empresa_id']);
}

if ($empresaId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empresa_id requerido'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- GET: Listar capas ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $capaId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($capaId > 0) {
        // Cargar una sola capa con su GeoJSON (lazy loading desde el mapa)
        $stmt = $pdo->prepare(
            "SELECT id, nombre, geojson, campo_capa, campo_tabla, color, grosor, opacidad
             FROM capas_infraestructuras
             WHERE id = :id AND empresa_id = :emp AND activa = 1"
        );
        $stmt->execute([':id' => $capaId, ':emp' => $empresaId]);
        $capa = $stmt->fetch();
        if (!$capa) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Capa no encontrada'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // GeoJSON rarely changes — cache for 10 minutes
        header('Cache-Control: private, max-age=600');
        echo json_encode(['ok' => true, 'capa' => $capa], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Listar todas (metadatos sin GeoJSON para reducir payload)
    $includeContent = isset($_GET['full']);
    $cols = $includeContent
        ? "id, nombre, geojson, campo_capa, campo_tabla, color, grosor, opacidad, created_at"
        : "id, nombre, campo_capa, campo_tabla, color, grosor, opacidad, created_at";

    $stmt = $pdo->prepare(
        "SELECT $cols FROM capas_infraestructuras WHERE empresa_id = :emp AND activa = 1 ORDER BY created_at DESC"
    );
    $stmt->execute([':emp' => $empresaId]);
    $capas = $stmt->fetchAll();

    header('Cache-Control: private, max-age=60');
    echo json_encode(['ok' => true, 'capas' => $capas], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- POST ----------
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

$action = $_POST['action'] ?? '';

// ---------- Eliminar ----------
if ($action === 'eliminar') {
    $capaId = (int) ($_POST['capa_id'] ?? 0);
    if ($capaId > 0) {
        $pdo->prepare("UPDATE capas_infraestructuras SET activa = 0 WHERE id = :id AND empresa_id = :emp")
            ->execute([':id' => $capaId, ':emp' => $empresaId]);
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- Actualizar estilo ----------
if ($action === 'actualizar_estilo') {
    $capaId = (int) ($_POST['capa_id'] ?? 0);
    if ($capaId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'capa_id requerido'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $updates = [];
    $params = [':id' => $capaId, ':emp' => $empresaId];

    if (isset($_POST['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'])) {
        $updates[] = 'color = :color';
        $params[':color'] = $_POST['color'];
    }
    if (isset($_POST['grosor'])) {
        $updates[] = 'grosor = :grosor';
        $params[':grosor'] = max(1, min(10, (int) $_POST['grosor']));
    }
    if (isset($_POST['opacidad'])) {
        $updates[] = 'opacidad = :opacidad';
        $params[':opacidad'] = max(0.0, min(1.0, (float) $_POST['opacidad']));
    }
    if (!empty($updates)) {
        $pdo->prepare("UPDATE capas_infraestructuras SET " . implode(', ', $updates) . " WHERE id = :id AND empresa_id = :emp")
            ->execute($params);
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- Analizar archivo (devolver GeoJSON + atributos) ----------
if ($action === 'analizar') {
    if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Archivo requerido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tmpFile = $_FILES['archivo']['tmp_name'];
    $fileName = $_FILES['archivo']['name'];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $geojson = null;

    if ($ext === 'kml') {
        $geojson = kmlToGeoJSON(file_get_contents($tmpFile));
    } elseif ($ext === 'kmz') {
        $zip = new ZipArchive();
        if ($zip->open($tmpFile) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'kml') {
                    $geojson = kmlToGeoJSON($zip->getFromIndex($i));
                    break;
                }
            }
            $zip->close();
        }
        if (!$geojson) {
            echo json_encode(['ok' => false, 'error' => 'No se encontró KML dentro del KMZ'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } elseif ($ext === 'zip') {
        // Shapefile en ZIP — se parsea en el cliente con shp.js, aquí recibimos el GeoJSON directamente
        echo json_encode(['ok' => false, 'error' => 'Para Shapefiles (.zip), el archivo se procesa en el navegador. Sube el archivo usando el formulario.'], JSON_UNESCAPED_UNICODE);
        exit;
    } elseif ($ext === 'geojson' || $ext === 'json') {
        $content = file_get_contents($tmpFile);
        $parsed = json_decode($content, true);
        if ($parsed && isset($parsed['type'])) {
            $geojson = $parsed;
        } else {
            echo json_encode(['ok' => false, 'error' => 'GeoJSON inválido'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        echo json_encode(['ok' => false, 'error' => 'Formato no soportado. Usa KML, KMZ, GeoJSON o Shapefile (ZIP).'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Extraer atributos únicos de las features
    $atributos = extractAttributeNames($geojson);

    echo json_encode([
        'ok' => true,
        'geojson' => $geojson,
        'atributos' => $atributos,
        'total_features' => countFeatures($geojson),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- Guardar capa ----------
if ($action === 'guardar') {
    $nombre = trim($_POST['nombre'] ?? '');
    $geojsonStr = trim($_POST['geojson'] ?? '');
    $campoCapa = trim($_POST['campo_capa'] ?? '');
    $campoTabla = trim($_POST['campo_tabla'] ?? 'codigo_unico');
    $color = trim($_POST['color'] ?? '#e74c3c');

    if ($nombre === '' || $geojsonStr === '' || $campoCapa === '') {
        echo json_encode(['ok' => false, 'error' => 'nombre, geojson y campo_capa son requeridos'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Validar que sea JSON válido
    $parsed = json_decode($geojsonStr, true);
    if (!$parsed) {
        echo json_encode(['ok' => false, 'error' => 'GeoJSON inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#e74c3c';

    // Validar campo_tabla contra lista blanca
    $camposPermitidos = ['codigo_unico', 'nombre', 'id'];
    if (!in_array($campoTabla, $camposPermitidos, true)) {
        $campoTabla = 'codigo_unico';
    }

    $stmt = $pdo->prepare(
        "INSERT INTO capas_infraestructuras (empresa_id, nombre, geojson, campo_capa, campo_tabla, color)
         VALUES (:emp, :nombre, :geojson, :campo_capa, :campo_tabla, :color)"
    );
    $stmt->execute([
        ':emp' => $empresaId,
        ':nombre' => $nombre,
        ':geojson' => $geojsonStr,
        ':campo_capa' => $campoCapa,
        ':campo_tabla' => $campoTabla,
        ':color' => $color,
    ]);

    $newId = (int) $pdo->lastInsertId();

    echo json_encode([
        'ok' => true,
        'id' => $newId,
        'nombre' => $nombre,
        'color' => $color,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Acción no reconocida'], JSON_UNESCAPED_UNICODE);
exit;

// ---------------------------------------------------------------
// Funciones auxiliares
// ---------------------------------------------------------------

function kmlToGeoJSON(string $kmlContent): ?array
{
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($kmlContent);
    if ($xml === false) return null;

    $namespaces = $xml->getNamespaces(true);
    $ns = $namespaces[''] ?? 'http://www.opengis.net/kml/2.2';

    $features = [];
    extractKmlFeatures($xml, $ns, $features);

    return [
        'type' => 'FeatureCollection',
        'features' => $features,
    ];
}

function extractKmlFeatures(\SimpleXMLElement $el, string $ns, array &$features): void
{
    $el->registerXPathNamespace('kml', $ns);

    $marks = $el->xpath('.//kml:Placemark');
    if (!$marks) $marks = $el->xpath('.//Placemark');
    if (!$marks) return;

    foreach ($marks as $pm) {
        $pm->registerXPathNamespace('kml', $ns);

        // Properties
        $props = [];

        // Name
        $nameNodes = $pm->xpath('kml:name');
        if (!$nameNodes) $nameNodes = $pm->xpath('name');
        if ($nameNodes) $props['name'] = trim((string) $nameNodes[0]);

        // Description
        $descNodes = $pm->xpath('kml:description');
        if (!$descNodes) $descNodes = $pm->xpath('description');
        if ($descNodes) $props['description'] = trim(strip_tags((string) $descNodes[0]));

        // ExtendedData
        $extData = $pm->xpath('.//kml:ExtendedData/kml:Data');
        if (!$extData) $extData = $pm->xpath('.//ExtendedData/Data');
        if ($extData) {
            foreach ($extData as $data) {
                $attrName = trim((string) ($data['name'] ?? ''));
                $valueNodes = $data->xpath('kml:value');
                if (!$valueNodes) $valueNodes = $data->xpath('value');
                if ($attrName && $valueNodes) {
                    $props[$attrName] = trim((string) $valueNodes[0]);
                }
            }
        }

        // SimpleData (SchemaData)
        $simpleData = $pm->xpath('.//kml:ExtendedData/kml:SchemaData/kml:SimpleData');
        if (!$simpleData) $simpleData = $pm->xpath('.//ExtendedData/SchemaData/SimpleData');
        if ($simpleData) {
            foreach ($simpleData as $sd) {
                $attrName = trim((string) ($sd['name'] ?? ''));
                if ($attrName) {
                    $props[$attrName] = trim((string) $sd);
                }
            }
        }

        // Geometry
        $geometry = null;

        // Point
        $coordNodes = $pm->xpath('.//kml:Point/kml:coordinates');
        if (!$coordNodes) $coordNodes = $pm->xpath('.//Point/coordinates');
        if ($coordNodes) {
            $parts = explode(',', trim((string) $coordNodes[0]));
            if (count($parts) >= 2) {
                $geometry = [
                    'type' => 'Point',
                    'coordinates' => [(float) $parts[0], (float) $parts[1]],
                ];
            }
        }

        // LineString
        if (!$geometry) {
            $lineNodes = $pm->xpath('.//kml:LineString/kml:coordinates');
            if (!$lineNodes) $lineNodes = $pm->xpath('.//LineString/coordinates');
            if ($lineNodes) {
                $coords = parseKmlCoordString((string) $lineNodes[0]);
                if (!empty($coords)) {
                    $geometry = ['type' => 'LineString', 'coordinates' => $coords];
                }
            }
        }

        // Polygon
        if (!$geometry) {
            $polyNodes = $pm->xpath('.//kml:Polygon//kml:outerBoundaryIs//kml:LinearRing//kml:coordinates');
            if (!$polyNodes) $polyNodes = $pm->xpath('.//Polygon//outerBoundaryIs//LinearRing//coordinates');
            if ($polyNodes) {
                $coords = parseKmlCoordString((string) $polyNodes[0]);
                if (!empty($coords)) {
                    $geometry = ['type' => 'Polygon', 'coordinates' => [$coords]];
                }
            }
        }

        if ($geometry) {
            $features[] = [
                'type' => 'Feature',
                'properties' => $props,
                'geometry' => $geometry,
            ];
        }
    }
}

function parseKmlCoordString(string $coordStr): array
{
    $coords = [];
    $pairs = preg_split('/\s+/', trim($coordStr));
    foreach ($pairs as $pair) {
        $parts = explode(',', $pair);
        if (count($parts) >= 2) {
            $coords[] = [(float) $parts[0], (float) $parts[1]];
        }
    }
    return $coords;
}

function extractAttributeNames(array $geojson): array
{
    $attrs = [];
    $features = $geojson['features'] ?? [];
    foreach ($features as $f) {
        $props = $f['properties'] ?? [];
        foreach (array_keys($props) as $key) {
            $attrs[$key] = true;
        }
    }
    return array_keys($attrs);
}

function countFeatures(array $geojson): int
{
    return count($geojson['features'] ?? []);
}
