<?php
/**
 * INFOCAMPO SaaS - API: Descarga de Waypoints (GPX)
 *
 * Genera un archivo GPX con los waypoints de fotos comparativas.
 *
 * GET ?empresa_id=X&infra_id=Y           → waypoints de una infraestructura
 * GET ?empresa_id=X&usuario_id=Z         → waypoints de un operador
 * GET ?empresa_id=X&infra_id=Y&fecha=... → waypoints de una visita específica
 * GET ?empresa_id=X&format=json          → devuelve JSON en vez de GPX
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';

$empresaId = isset($_GET['empresa_id']) ? (int) $_GET['empresa_id'] : 0;
$infraId   = isset($_GET['infra_id']) ? (int) $_GET['infra_id'] : 0;
$usuarioId = isset($_GET['usuario_id']) ? (int) $_GET['usuario_id'] : 0;
$fecha     = isset($_GET['fecha']) ? trim($_GET['fecha']) : '';
$format    = $_GET['format'] ?? 'gpx';

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

    $sql = "SELECT r.id, r.lat_real, r.lon_real, r.nombre_archivo, r.fecha,
                   r.secuencia_comparativa, r.estado_incidencia,
                   i.nombre AS infra_nombre, i.codigo_unico
            FROM registros r
            INNER JOIN infraestructuras i ON r.infra_id = i.id
            WHERE i.empresa_id = :empresa_id
              AND r.tipo_foto = 'comparativo'
              AND r.lat_real != 0 AND r.lon_real != 0";

    $params = [':empresa_id' => $empresaId];

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

    $sql .= " ORDER BY r.fecha ASC, r.secuencia_comparativa ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $registros = $stmt->fetchAll();

    if (empty($registros)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'No hay waypoints comparativos']);
        exit;
    }

    // JSON format — return data for UI listing
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        $waypoints = [];
        foreach ($registros as $r) {
            $waypoints[] = [
                'id' => (int) $r['id'],
                'lat' => (float) $r['lat_real'],
                'lon' => (float) $r['lon_real'],
                'nombre_archivo' => $r['nombre_archivo'],
                'fecha' => $r['fecha'],
                'seq' => $r['secuencia_comparativa'],
                'estado' => $r['estado_incidencia'],
                'infra_nombre' => $r['infra_nombre'],
                'infra_codigo' => $r['codigo_unico'],
            ];
        }
        echo json_encode(['ok' => true, 'waypoints' => $waypoints, 'total' => count($waypoints)]);
        exit;
    }

    // GPX format — download file
    $infraNombre = $registros[0]['infra_nombre'] ?? 'infraestructura';
    $infraCodigo = $registros[0]['codigo_unico'] ?? '';
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $infraNombre);

    $gpx = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $gpx .= '<gpx version="1.1" creator="INFOCAMPO" xmlns="http://www.topografix.com/GPX/1/1">' . "\n";
    $gpx .= "  <metadata>\n";
    $gpx .= "    <name>Waypoints comparativos - " . htmlspecialchars($infraNombre, ENT_XML1) . "</name>\n";
    $gpx .= "    <desc>Puntos GPS de fotos comparativas" . ($infraCodigo ? " - " . htmlspecialchars($infraCodigo, ENT_XML1) : "") . "</desc>\n";
    $gpx .= "    <time>" . date('c') . "</time>\n";
    $gpx .= "  </metadata>\n";

    foreach ($registros as $r) {
        $lat = (float) $r['lat_real'];
        $lon = (float) $r['lon_real'];
        $name = htmlspecialchars($r['nombre_archivo'] ?? ('WPT_' . $r['id']), ENT_XML1);
        $time = date('c', strtotime($r['fecha']));
        $desc = htmlspecialchars(
            "Foto comparativa seq " . ($r['secuencia_comparativa'] ?? 0) .
            " - " . ($r['infra_nombre'] ?? '') .
            " [" . ($r['estado_incidencia'] ?? '') . "]",
            ENT_XML1
        );

        $gpx .= "  <wpt lat=\"{$lat}\" lon=\"{$lon}\">\n";
        $gpx .= "    <name>{$name}</name>\n";
        $gpx .= "    <time>{$time}</time>\n";
        $gpx .= "    <desc>{$desc}</desc>\n";
        $gpx .= "  </wpt>\n";
    }

    $gpx .= "</gpx>\n";

    $filename = $safeName . '_WAYPOINTS_' . date('Ymd') . '.gpx';
    header('Content-Type: application/gpx+xml');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($gpx));
    echo $gpx;

} catch (\PDOException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error de base de datos']);
}
