<?php
/**
 * INFOCAMPO SaaS - API: Obtener campos dinámicos de una empresa
 *
 * GET ?empresa_id=X  → devuelve los campos activos en JSON
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
// Campos dinámicos rara vez cambian — cachear 5 minutos
header('Cache-Control: private, max-age=300');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

// Verificar autenticación y aislamiento multi-tenant
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
        "SELECT id, nombre, slug, tipo, opciones, obligatorio, orden
         FROM campos_formulario
         WHERE empresa_id = :emp_id AND activo = 1
         ORDER BY orden ASC, id ASC"
    );
    $stmt->execute([':emp_id' => $empresaId]);
    $campos = $stmt->fetchAll();

    // Decodificar opciones JSON en cada campo
    foreach ($campos as &$campo) {
        $campo['obligatorio'] = (bool) $campo['obligatorio'];
        if ($campo['opciones']) {
            $campo['opciones'] = json_decode($campo['opciones'], true);
        } else {
            $campo['opciones'] = null;
        }
    }
    unset($campo);

    echo json_encode(['ok' => true, 'campos' => $campos]);

} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error de base de datos']);
}
