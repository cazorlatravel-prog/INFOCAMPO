<?php
/**
 * INFOCAMPO SaaS - API: Descarga de Waypoints (GPX)
 *
 * Genera un archivo GPX con los waypoints de fotos comparativas en situación "antes".
 * Los waypoints se nombran con el formato: CODIGO_INFRA W1, W2, W3...
 * ordenados por fecha de toma dentro de cada infraestructura.
 *
 * GET ?empresa_id=X&infra_id=Y           -> waypoints de una infraestructura
 * GET ?empresa_id=X&usuario_id=Z         -> waypoints de un operador
 * GET ?empresa_id=X&infra_id=Y&fecha=... -> waypoints de una visita específica
 * GET ?empresa_id=X&format=json          -> devuelve JSON en vez de GPX
 * GET ?empresa_id=X&estado=todos         -> incluir todos los estados (no solo "antes")
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';

$empresaId = isset($_GET['empresa_id']) ? (int) $_GET['empresa_id'] : 0;
$infraId   = isset($_GET['infra_id']) ? (int) $_GET['infra_id'] : 0;
$usuarioId = isset($_GET['usuario_id']) ? (int) $_GET['usuario_id'] : 0;
$fecha     = isset($_GET['fecha']) ? trim($_GET['fecha']) : '';
$format    = $_GET['format'] ?? 'gpx';
$estado    = $_GET['estado'] ?? 'antes'; // por defecto solo "antes"

if ($empresaId <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'empresa_id requerido']);
    exit;
}

if ($infraId <= 0 && $usuarioId <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'infra_id o usuario_id requerido']);
    exit;
}

try {
    $pdo = getDB();

    $sql = "SELECT r.id, r.infra_id, r.lat_real, r.lon_real, r.nombre_archivo, r.fecha,
                   r.secuencia_comparativa, r.estado_incidencia,
                   i.nombre AS infra_nombre, i.codigo_unico
            FROM registros r
            INNER JOIN infraestructuras i ON r.infra_id = i.id
            WHERE i.empresa_id = :empresa_id
              AND r.tipo_foto = 'comparativo'
              AND r.lat_real != 0 AND r.lon_real != 0";

    $params = [':empresa_id' => $empresaId];

    // Filtrar por estado (por defecto solo "antes" genera waypoints)
    if ($estado !== 'todos') {
        $sql .= " AND r.estado_incidencia = :estado";
        $params[':estado'] = $estado;
    }

    if ($infraId > 0) {
        $sql .= " AND r.infra_id = :infra_id";
        $params[':infra_id'] = $infraId;
    }

    if ($usuarioId > 0) {
        $sql .= " AND r.usuario_id = :usuario_id";
        $params[':usuario_id'] = $usuarioId;
    }

    if ($fecha !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        $sql .= " AND DATE(r.fecha) = :fecha";
        $params[':fecha'] = $fecha;
    }

    // Ordenar por infraestructura y luego por fecha de toma para numerar W1, W2, W3...
    $sql .= " ORDER BY r.infra_id ASC, r.fecha ASC, r.secuencia_comparativa ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $registros = $stmt->fetchAll();

    if (empty($registros)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'No hay waypoints comparativos']);
        exit;
    }

    // Numerar waypoints por infraestructura: CODIGO_W1, CODIGO_W2...
    $counterByInfra = [];
    foreach ($registros as &$r) {
        $iid = (int) $r['infra_id'];
        if (!isset($counterByInfra[$iid])) {
            $counterByInfra[$iid] = 0;
        }
        $counterByInfra[$iid]++;
        $codigo = $r['codigo_unico'] ?: ('INF-' . $iid);
        $r['waypoint_name'] = $codigo . ' W' . $counterByInfra[$iid];
        $r['waypoint_num'] = $counterByInfra[$iid];
    }
    unset($r);

    // JSON format — return data for UI listing / map rendering
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        $waypoints = [];
        foreach ($registros as $r) {
            $waypoints[] = [
                'id'             => (int) $r['id'],
                'lat'            => (float) $r['lat_real'],
                'lon'            => (float) $r['lon_real'],
                'nombre'         => $r['waypoint_name'],
                'num'            => $r['waypoint_num'],
                'nombre_archivo' => $r['nombre_archivo'],
                'fecha'          => $r['fecha'],
                'seq'            => $r['secuencia_comparativa'],
                'estado'         => $r['estado_incidencia'],
                'infra_id'       => (int) $r['infra_id'],
                'infra_nombre'   => $r['infra_nombre'],
                'infra_codigo'   => $r['codigo_unico'],
            ];
        }
        echo json_encode(['ok' => true, 'waypoints' => $waypoints, 'total' => count($waypoints)]);
        exit;
    }

    // GPX format — download file
    $infraCodigo = $registros[0]['codigo_unico'] ?? '';
    $infraNombre = $registros[0]['infra_nombre'] ?? 'infraestructura';
    $safeCode = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $infraCodigo ?: $infraNombre);

    $gpx = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $gpx .= '<gpx version="1.1" creator="INFOCAMPO"' . "\n";
    $gpx .= '     xmlns="http://www.topografix.com/GPX/1/1"' . "\n";
    $gpx .= '     xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' . "\n";
    $gpx .= '     xsi:schemaLocation="http://www.topografix.com/GPX/1/1 http://www.topografix.com/GPX/1/1/gpx.xsd">' . "\n";
    $gpx .= "  <metadata>\n";
    $gpx .= "    <name>" . htmlspecialchars($safeCode . ' - Waypoints', ENT_XML1) . "</name>\n";
    $gpx .= "    <desc>Waypoints de fotos comparativas (situacion: " . htmlspecialchars($estado, ENT_XML1) . ")</desc>\n";
    $gpx .= "    <author><name>INFOCAMPO</name></author>\n";
    $gpx .= "    <time>" . date('c') . "</time>\n";
    $gpx .= "  </metadata>\n";

    foreach ($registros as $r) {
        $lat = number_format((float) $r['lat_real'], 7, '.', '');
        $lon = number_format((float) $r['lon_real'], 7, '.', '');
        $name = htmlspecialchars($r['waypoint_name'], ENT_XML1);
        $time = date('c', strtotime($r['fecha']));
        $desc = htmlspecialchars(
            $r['infra_nombre'] . ' | ' . ($r['estado_incidencia'] ?? 'antes') .
            ' | ' . $r['fecha'],
            ENT_XML1
        );
        $ele = '0'; // Elevacion no disponible — campo reservado

        $gpx .= "  <wpt lat=\"{$lat}\" lon=\"{$lon}\">\n";
        $gpx .= "    <ele>{$ele}</ele>\n";
        $gpx .= "    <time>{$time}</time>\n";
        $gpx .= "    <name>{$name}</name>\n";
        $gpx .= "    <desc>{$desc}</desc>\n";
        $gpx .= "    <sym>Flag, Blue</sym>\n";
        $gpx .= "  </wpt>\n";
    }

    $gpx .= "</gpx>\n";

    $filename = $safeCode . '_W' . count($registros) . '_' . date('Ymd') . '.gpx';
    header('Content-Type: application/gpx+xml');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($gpx));
    echo $gpx;

} catch (\PDOException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error de base de datos']);
}
