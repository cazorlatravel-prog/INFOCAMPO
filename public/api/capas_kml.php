<?php
/**
 * INFOCAMPO - API de Capas KML (Pública / Operador)
 *
 * GET: Devuelve las capas KML activas de la empresa (solo lectura).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$empresaId = (int) ($_SESSION['empresa_id'] ?? 0);

if ($empresaId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empresa_id requerido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();

$stmt = $pdo->prepare(
    "SELECT id, nombre, contenido_kml, color, grosor, opacidad
     FROM capas_kml
     WHERE empresa_id = :emp AND activa = 1
     ORDER BY created_at DESC"
);
$stmt->execute([':emp' => $empresaId]);
$capas = $stmt->fetchAll();

echo json_encode(['ok' => true, 'capas' => $capas], JSON_UNESCAPED_UNICODE);
