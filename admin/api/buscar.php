<?php
/**
 * INFOCAMPO SaaS - API de Búsqueda Global (Admin)
 *
 * Busca simultáneamente en infraestructuras, registros y usuarios.
 * Responde JSON para alimentar el buscador global del header.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();

$q = trim($_GET['q'] ?? '');
$empresaId = getEmpresaIdSeguro();

if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'results' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($empresaId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Empresa no identificada'], JSON_UNESCAPED_UNICODE);
    exit;
}

$searchTerm = '%' . $q . '%';
$results = [];

// Buscar infraestructuras
$stmt = $pdo->prepare(
    "SELECT id, nombre, codigo_unico, tipo, provincia, municipio, lat_teorica, lon_teorica
     FROM infraestructuras
     WHERE empresa_id = :emp AND activa = 1
       AND (nombre LIKE :q OR codigo_unico LIKE :q2 OR tipo LIKE :q3 OR provincia LIKE :q4 OR municipio LIKE :q5)
     ORDER BY nombre
     LIMIT 10"
);
$stmt->execute([
    ':emp' => $empresaId,
    ':q' => $searchTerm, ':q2' => $searchTerm, ':q3' => $searchTerm,
    ':q4' => $searchTerm, ':q5' => $searchTerm,
]);
foreach ($stmt->fetchAll() as $row) {
    $results[] = [
        'type' => 'infraestructura',
        'icon' => 'bi-geo-alt',
        'title' => $row['nombre'],
        'subtitle' => $row['codigo_unico'] . ($row['tipo'] ? ' - ' . $row['tipo'] : ''),
        'url' => 'index.php?empresa_id=' . $empresaId . '&infra_id=' . $row['id'],
        'extra' => trim(($row['municipio'] ?? '') . ', ' . ($row['provincia'] ?? ''), ', '),
    ];
}

// Buscar registros (por observaciones)
$stmt = $pdo->prepare(
    "SELECT r.id, r.fecha, r.estado_incidencia, r.observaciones, r.infra_id,
            i.nombre AS infra_nombre, i.codigo_unico, u.nombre AS operador
     FROM registros r
     INNER JOIN infraestructuras i ON r.infra_id = i.id
     INNER JOIN usuarios u ON r.usuario_id = u.id
     WHERE i.empresa_id = :emp
       AND (r.observaciones LIKE :q OR r.nombre_archivo LIKE :q2)
     ORDER BY r.fecha DESC
     LIMIT 8"
);
$stmt->execute([':emp' => $empresaId, ':q' => $searchTerm, ':q2' => $searchTerm]);
foreach ($stmt->fetchAll() as $row) {
    $results[] = [
        'type' => 'registro',
        'icon' => 'bi-camera',
        'title' => $row['infra_nombre'] . ' - ' . date('d/m/Y H:i', strtotime($row['fecha'])),
        'subtitle' => $row['operador'] . ' | ' . strtoupper($row['estado_incidencia']),
        'url' => 'index.php?empresa_id=' . $empresaId . '&infra_id=' . $row['infra_id'],
        'extra' => mb_substr($row['observaciones'] ?? '', 0, 80),
    ];
}

// Buscar usuarios
$stmt = $pdo->prepare(
    "SELECT id, nombre, email, rol
     FROM usuarios
     WHERE empresa_id = :emp AND activo = 1
       AND (nombre LIKE :q OR email LIKE :q2)
     ORDER BY nombre
     LIMIT 5"
);
$stmt->execute([':emp' => $empresaId, ':q' => $searchTerm, ':q2' => $searchTerm]);
foreach ($stmt->fetchAll() as $row) {
    $results[] = [
        'type' => 'usuario',
        'icon' => 'bi-person',
        'title' => $row['nombre'],
        'subtitle' => $row['email'] . ' | ' . ucfirst($row['rol']),
        'url' => 'usuarios.php?empresa_id=' . $empresaId,
        'extra' => '',
    ];
}

echo json_encode([
    'ok' => true,
    'results' => $results,
    'total' => count($results),
], JSON_UNESCAPED_UNICODE);
