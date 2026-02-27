<?php
/**
 * INFOCAMPO SaaS - API: Obtener fotos comparativas anteriores de una infraestructura
 *
 * GET ?infra_id=X → devuelve las últimas fotos comparativas ordenadas por secuencia
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';

$infraId = isset($_GET['infra_id']) ? (int) $_GET['infra_id'] : 0;

if ($infraId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'infra_id requerido']);
    exit;
}

try {
    $pdo = getDB();

    // Obtener la fecha de la última visita comparativa
    $stmtFecha = $pdo->prepare(
        "SELECT DATE(fecha) AS ultima_fecha
         FROM registros
         WHERE infra_id = :infra_id AND tipo_foto = 'comparativo'
         ORDER BY fecha DESC
         LIMIT 1"
    );
    $stmtFecha->execute([':infra_id' => $infraId]);
    $row = $stmtFecha->fetch();

    if (!$row) {
        echo json_encode(['ok' => true, 'fotos' => [], 'fecha_visita' => null]);
        exit;
    }

    $ultimaFecha = $row['ultima_fecha'];

    // Obtener todas las comparativas de esa última visita
    $stmt = $pdo->prepare(
        "SELECT id, url_cloudinary, secuencia_comparativa, nombre_archivo, fecha
         FROM registros
         WHERE infra_id = :infra_id
           AND tipo_foto = 'comparativo'
           AND DATE(fecha) = :fecha
         ORDER BY secuencia_comparativa ASC"
    );
    $stmt->execute([':infra_id' => $infraId, ':fecha' => $ultimaFecha]);

    echo json_encode([
        'ok'           => true,
        'fotos'        => $stmt->fetchAll(),
        'fecha_visita' => $ultimaFecha,
    ]);

} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error de base de datos']);
}
