<?php
/**
 * INFOCAMPO - API: Última foto de una infraestructura
 *
 * GET ?infra_id=123
 *
 * Devuelve JSON: { "url": "https://..." } o { "url": null }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['url' => null, 'error' => 'No autenticado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['url' => null, 'error' => 'Método no permitido']);
    exit;
}

$infraId = isset($_GET['infra_id']) ? (int) $_GET['infra_id'] : 0;
$empresaId = (int) ($_SESSION['empresa_id'] ?? 0);

if ($infraId <= 0) {
    http_response_code(400);
    echo json_encode(['url' => null, 'error' => 'infra_id requerido']);
    exit;
}

try {
    $pdo = getDB();

    // Scoped by empresa_id para aislamiento multi-tenant
    $stmt = $pdo->prepare(
        "SELECT r.url_cloudinary
         FROM registros r
         INNER JOIN infraestructuras i ON r.infra_id = i.id
         WHERE r.infra_id = :infra_id AND i.empresa_id = :empresa_id
         ORDER BY r.fecha DESC
         LIMIT 1"
    );
    $stmt->execute([':infra_id' => $infraId, ':empresa_id' => $empresaId]);
    $row = $stmt->fetch();

    echo json_encode([
        'url' => $row ? $row['url_cloudinary'] : null,
    ]);

} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['url' => null, 'error' => 'Error de base de datos']);
}
