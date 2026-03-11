<?php
/**
 * INFOCAMPO SaaS - API: Tipos de Trabajo
 *
 * GET ?empresa_id=X → lista de tipos de trabajo activos
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

try {
    $pdo = getDB();
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error de conexión a base de datos']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$empresaId = (int) ($_SESSION['empresa_id'] ?? 0);
if ($empresaId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'empresa_id requerido']);
    exit;
}

// Verificar si la tabla existe
try {
    $stmt = $pdo->prepare(
        "SELECT id, nombre, codigo FROM tipos_trabajo
         WHERE empresa_id = :emp_id AND activa = 1
         ORDER BY nombre ASC"
    );
    $stmt->execute([':emp_id' => $empresaId]);
    $tipos = $stmt->fetchAll();
} catch (\Exception $e) {
    // La tabla puede no existir todavía
    $tipos = [];
}

echo json_encode(['ok' => true, 'tipos' => $tipos]);
