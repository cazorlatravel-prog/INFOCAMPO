<?php
/**
 * INFOCAMPO SaaS - API: Obtener unidades de obra de una empresa
 *
 * GET ?empresa_id=X → devuelve las unidades activas en JSON
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

$empresaId = (int) ($_SESSION['empresa_id'] ?? 0);

if ($empresaId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'empresa_id requerido']);
    exit;
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare(
        "SELECT id, nombre, codigo
         FROM unidades_obra
         WHERE empresa_id = :emp_id AND activa = 1
         ORDER BY nombre ASC"
    );
    $stmt->execute([':emp_id' => $empresaId]);

    echo json_encode(['ok' => true, 'unidades' => $stmt->fetchAll()]);

} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error de base de datos']);
}
