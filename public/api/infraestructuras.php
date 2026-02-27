<?php
/**
 * INFOCAMPO SaaS - API: Buscar/crear infraestructuras
 *
 * GET  ?empresa_id=X&q=texto  → buscar por nombre/código
 * POST empresa_id, nombre, lat, lon → crear nueva infraestructura
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';

$pdo = getDB();

// ---------------------------------------------------------------
// GET: buscar infraestructuras
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $empresaId = (int) ($_GET['empresa_id'] ?? 0);
    $q = trim($_GET['q'] ?? '');

    if ($empresaId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'empresa_id requerido']);
        exit;
    }

    if ($q === '') {
        // Devolver todas las infraestructuras activas de la empresa
        $stmt = $pdo->prepare(
            "SELECT id, nombre, codigo_unico, lat_teorica, lon_teorica, tipo
             FROM infraestructuras
             WHERE empresa_id = :emp_id AND activa = 1
             ORDER BY nombre ASC
             LIMIT 50"
        );
        $stmt->execute([':emp_id' => $empresaId]);
    } else {
        // Buscar por nombre o código
        $stmt = $pdo->prepare(
            "SELECT id, nombre, codigo_unico, lat_teorica, lon_teorica, tipo
             FROM infraestructuras
             WHERE empresa_id = :emp_id AND activa = 1
               AND (nombre LIKE :q OR codigo_unico LIKE :q)
             ORDER BY nombre ASC
             LIMIT 20"
        );
        $stmt->execute([':emp_id' => $empresaId, ':q' => '%' . $q . '%']);
    }

    echo json_encode(['ok' => true, 'infraestructuras' => $stmt->fetchAll()]);
    exit;
}

// ---------------------------------------------------------------
// POST: crear nueva infraestructura
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $empresaId = (int) ($_POST['empresa_id'] ?? 0);
    $nombre    = trim($_POST['nombre'] ?? '');
    $lat       = (float) ($_POST['lat'] ?? 0);
    $lon       = (float) ($_POST['lon'] ?? 0);

    if ($empresaId <= 0 || $nombre === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'empresa_id y nombre son requeridos']);
        exit;
    }

    // Auto-generar código único
    $codigo = 'INF-' . strtoupper(substr(md5($nombre . time()), 0, 8));

    $stmt = $pdo->prepare(
        "INSERT INTO infraestructuras (empresa_id, nombre, codigo_unico, lat_teorica, lon_teorica, tipo, activa)
         VALUES (:emp_id, :nombre, :codigo, :lat, :lon, NULL, 1)"
    );
    $stmt->execute([
        ':emp_id'  => $empresaId,
        ':nombre'  => $nombre,
        ':codigo'  => $codigo,
        ':lat'     => $lat,
        ':lon'     => $lon,
    ]);

    $newId = (int) $pdo->lastInsertId();

    echo json_encode([
        'ok' => true,
        'infraestructura' => [
            'id'            => $newId,
            'nombre'        => $nombre,
            'codigo_unico'  => $codigo,
            'lat_teorica'   => $lat,
            'lon_teorica'   => $lon,
        ]
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
