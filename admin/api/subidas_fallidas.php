<?php
/**
 * INFOCAMPO - API: Subidas fallidas
 *
 * GET: Lista las subidas fallidas sin resolver para la empresa
 * POST action=resolver: Marca una subida como resuelta
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();

// Verificar que la tabla existe
$tableCheck = $pdo->query("SHOW TABLES LIKE 'subidas_fallidas'")->fetchColumn();
if (!$tableCheck) {
    echo json_encode(['ok' => true, 'subidas' => [], 'count' => 0], JSON_UNESCAPED_UNICODE);
    exit;
}

// Solo superadmins pueden especificar empresa_id diferente
$empresaId = (int) ($_SESSION['empresa_id'] ?? 0);
if (($_SESSION['user_rol'] ?? '') === 'superadmin' && (isset($_GET['empresa_id']) || isset($_POST['empresa_id']))) {
    $empresaId = (int) ($_GET['empresa_id'] ?? $_POST['empresa_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'resolver' && $id > 0) {
        $stmt = $pdo->prepare(
            "UPDATE subidas_fallidas SET resuelta = 1, fecha_resolucion = NOW()
             WHERE id = :id AND empresa_id = :emp"
        );
        $stmt->execute([':id' => $id, ':emp' => $empresaId]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// GET: listar fallidas
$stmt = $pdo->prepare(
    "SELECT sf.*, i.nombre AS infra_nombre, i.codigo_unico, u.nombre AS usuario_nombre
     FROM subidas_fallidas sf
     INNER JOIN infraestructuras i ON sf.infra_id = i.id
     INNER JOIN usuarios u ON sf.usuario_id = u.id
     WHERE sf.empresa_id = :emp AND sf.resuelta = 0
     ORDER BY sf.fecha_fallo DESC
     LIMIT 50"
);
$stmt->execute([':emp' => $empresaId]);
$fallidas = $stmt->fetchAll();

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM subidas_fallidas WHERE empresa_id = :emp AND resuelta = 0"
);
$countStmt->execute([':emp' => $empresaId]);
$totalFallidas = (int) $countStmt->fetchColumn();

echo json_encode([
    'ok' => true,
    'subidas' => $fallidas,
    'count' => $totalFallidas,
], JSON_UNESCAPED_UNICODE);
