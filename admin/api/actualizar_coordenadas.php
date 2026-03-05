<?php
/**
 * INFOCAMPO SaaS - Actualizar coordenadas de infraestructura via AJAX
 *
 * Permite al admin reubicar una infraestructura arrastrando su marcador en el mapa.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

requireRole(['admin', 'superadmin']);

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

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    // Try POST form data
    $input = $_POST;
}

$infraId = (int) ($input['infra_id'] ?? 0);
$lat = isset($input['lat']) ? (float) $input['lat'] : null;
$lon = isset($input['lon']) ? (float) $input['lon'] : null;
$empresaId = (int) ($input['empresa_id'] ?? $_SESSION['empresa_id'] ?? 0);

if ($infraId <= 0 || $lat === null || $lon === null || $empresaId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Parámetros incompletos'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Validar rango de coordenadas
if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Coordenadas fuera de rango'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();

// Verificar que la infraestructura pertenece a la empresa
$stmt = $pdo->prepare(
    "SELECT id, nombre FROM infraestructuras WHERE id = :id AND empresa_id = :emp"
);
$stmt->execute([':id' => $infraId, ':emp' => $empresaId]);
$infra = $stmt->fetch();

if (!$infra) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Infraestructura no encontrada'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare(
    "UPDATE infraestructuras SET lat_teorica = :lat, lon_teorica = :lon, updated_at = NOW()
     WHERE id = :id AND empresa_id = :emp"
);
$stmt->execute([
    ':lat' => round($lat, 7),
    ':lon' => round($lon, 7),
    ':id' => $infraId,
    ':emp' => $empresaId,
]);

echo json_encode([
    'ok' => true,
    'infra_id' => $infraId,
    'nombre' => $infra['nombre'],
    'lat' => round($lat, 7),
    'lon' => round($lon, 7),
], JSON_UNESCAPED_UNICODE);
