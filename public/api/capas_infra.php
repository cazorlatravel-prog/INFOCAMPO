<?php
/**
 * INFOCAMPO SaaS - API pública de Capas de Infraestructuras
 *
 * GET ?empresa_id=X → capas activas con GeoJSON y mapeo de campos
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';

try {
    $pdo = getDB();
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error de conexión']);
    exit;
}

$empresaId = (int) ($_GET['empresa_id'] ?? 0);
if ($empresaId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'empresa_id requerido']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT id, nombre, geojson, campo_capa, campo_tabla, color, grosor, opacidad
         FROM capas_infraestructuras
         WHERE empresa_id = :emp AND activa = 1
         ORDER BY created_at DESC"
    );
    $stmt->execute([':emp' => $empresaId]);
    $capas = $stmt->fetchAll();
} catch (\Exception $e) {
    $capas = [];
}

echo json_encode(['ok' => true, 'capas' => $capas], JSON_UNESCAPED_UNICODE);
