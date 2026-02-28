<?php
/**
 * INFOCAMPO SaaS - API: Registros con GPS para mapa
 *
 * GET ?empresa_id=X                         → todos los registros de la empresa
 * GET ?empresa_id=X&infra_id=Y              → registros de una infraestructura
 * GET ?empresa_id=X&usuario_id=Z            → registros de un operador
 * GET ?empresa_id=X&unidad_obra_id=W        → registros de una unidad de obra
 * GET ?empresa_id=X&tipo_foto=comparativo   → solo comparativas
 * GET ?empresa_id=X&limit=200               → limitar resultados
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';

$empresaId    = isset($_GET['empresa_id']) ? (int) $_GET['empresa_id'] : 0;
$infraId      = isset($_GET['infra_id']) ? (int) $_GET['infra_id'] : 0;
$usuarioId    = isset($_GET['usuario_id']) ? (int) $_GET['usuario_id'] : 0;
$unidadObraId = isset($_GET['unidad_obra_id']) ? (int) $_GET['unidad_obra_id'] : 0;
$tipoFoto     = $_GET['tipo_foto'] ?? '';
$limit        = isset($_GET['limit']) ? min((int) $_GET['limit'], 1000) : 500;

if ($empresaId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'empresa_id requerido']);
    exit;
}

try {
    $pdo = getDB();

    $sql = "SELECT r.id, r.infra_id, r.usuario_id, r.fecha, r.lat_real, r.lon_real,
                   r.url_cloudinary, r.estado_incidencia, r.observaciones,
                   r.tipo_foto, r.secuencia_comparativa, r.nombre_archivo, r.unidad_obra_id,
                   i.nombre AS infra_nombre, i.codigo_unico, i.lat_teorica, i.lon_teorica, i.tipo AS infra_tipo,
                   u.nombre AS usuario_nombre,
                   uo.nombre AS unidad_obra_nombre
            FROM registros r
            INNER JOIN infraestructuras i ON r.infra_id = i.id
            INNER JOIN usuarios u ON r.usuario_id = u.id
            LEFT JOIN unidades_obra uo ON r.unidad_obra_id = uo.id
            WHERE i.empresa_id = :empresa_id";

    $params = [':empresa_id' => $empresaId];

    if ($infraId > 0) {
        $sql .= " AND r.infra_id = :infra_id";
        $params[':infra_id'] = $infraId;
    }

    if ($usuarioId > 0) {
        $sql .= " AND r.usuario_id = :usuario_id";
        $params[':usuario_id'] = $usuarioId;
    }

    if ($unidadObraId > 0) {
        $sql .= " AND r.unidad_obra_id = :unidad_obra_id";
        $params[':unidad_obra_id'] = $unidadObraId;
    }

    if ($tipoFoto !== '' && in_array($tipoFoto, ['aleatorio', 'comparativo'], true)) {
        $sql .= " AND r.tipo_foto = :tipo_foto";
        $params[':tipo_foto'] = $tipoFoto;
    }

    $sql .= " ORDER BY r.fecha DESC LIMIT " . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'ok' => true,
        'registros' => $stmt->fetchAll(),
    ]);

} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error de base de datos']);
}
