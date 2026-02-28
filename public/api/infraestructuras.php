<?php
/**
 * INFOCAMPO SaaS - API: Buscar/crear infraestructuras
 *
 * GET  ?empresa_id=X&q=texto                    → buscar por nombre/código
 * GET  ?empresa_id=X&provincia=Y                → filtrar por provincia
 * GET  ?empresa_id=X&provincia=Y&municipio=Z    → filtrar por provincia y municipio
 * GET  ?empresa_id=X&action=provincias          → lista de provincias únicas
 * GET  ?empresa_id=X&action=municipios&provincia=Y → lista de municipios de una provincia
 * POST empresa_id, nombre, lat, lon             → crear nueva infraestructura
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';

$pdo = getDB();

// ---------------------------------------------------------------
// GET: buscar infraestructuras / listas de provincias/municipios
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $empresaId = (int) ($_GET['empresa_id'] ?? 0);
    $action    = trim($_GET['action'] ?? '');

    if ($empresaId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'empresa_id requerido']);
        exit;
    }

    // Listar provincias únicas
    if ($action === 'provincias') {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT provincia
             FROM infraestructuras
             WHERE empresa_id = :emp_id AND activa = 1 AND provincia IS NOT NULL AND provincia != ''
             ORDER BY provincia ASC"
        );
        $stmt->execute([':emp_id' => $empresaId]);
        $provincias = array_column($stmt->fetchAll(), 'provincia');
        echo json_encode(['ok' => true, 'provincias' => $provincias]);
        exit;
    }

    // Listar municipios de una provincia
    if ($action === 'municipios') {
        $provincia = trim($_GET['provincia'] ?? '');
        if ($provincia === '') {
            echo json_encode(['ok' => true, 'municipios' => []]);
            exit;
        }
        $stmt = $pdo->prepare(
            "SELECT DISTINCT municipio
             FROM infraestructuras
             WHERE empresa_id = :emp_id AND activa = 1
               AND provincia = :provincia AND municipio IS NOT NULL AND municipio != ''
             ORDER BY municipio ASC"
        );
        $stmt->execute([':emp_id' => $empresaId, ':provincia' => $provincia]);
        $municipios = array_column($stmt->fetchAll(), 'municipio');
        echo json_encode(['ok' => true, 'municipios' => $municipios]);
        exit;
    }

    // Buscar infraestructuras con filtros opcionales
    $q         = trim($_GET['q'] ?? '');
    $provincia = trim($_GET['provincia'] ?? '');
    $municipio = trim($_GET['municipio'] ?? '');

    $where  = "empresa_id = :emp_id AND activa = 1";
    $params = [':emp_id' => $empresaId];

    if ($provincia !== '') {
        $where .= " AND provincia = :provincia";
        $params[':provincia'] = $provincia;
    }
    if ($municipio !== '') {
        $where .= " AND municipio = :municipio";
        $params[':municipio'] = $municipio;
    }

    if ($q !== '') {
        $where .= " AND (nombre LIKE :q OR codigo_unico LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    $stmt = $pdo->prepare(
        "SELECT id, nombre, codigo_unico, lat_teorica, lon_teorica, tipo, provincia, municipio
         FROM infraestructuras
         WHERE $where
         ORDER BY nombre ASC
         LIMIT 50"
    );
    $stmt->execute($params);

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
    $provincia = trim($_POST['provincia'] ?? '');
    $municipio = trim($_POST['municipio'] ?? '');

    if ($empresaId <= 0 || $nombre === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'empresa_id y nombre son requeridos']);
        exit;
    }

    // Auto-generar código único
    $codigo = 'INF-' . strtoupper(substr(md5($nombre . time()), 0, 8));

    $stmt = $pdo->prepare(
        "INSERT INTO infraestructuras (empresa_id, nombre, codigo_unico, lat_teorica, lon_teorica, tipo, provincia, municipio, activa)
         VALUES (:emp_id, :nombre, :codigo, :lat, :lon, NULL, :provincia, :municipio, 1)"
    );
    $stmt->execute([
        ':emp_id'    => $empresaId,
        ':nombre'    => $nombre,
        ':codigo'    => $codigo,
        ':lat'       => $lat,
        ':lon'       => $lon,
        ':provincia' => $provincia ?: null,
        ':municipio' => $municipio ?: null,
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
            'provincia'     => $provincia ?: null,
            'municipio'     => $municipio ?: null,
        ]
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
