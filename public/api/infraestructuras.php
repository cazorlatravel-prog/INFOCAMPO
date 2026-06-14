<?php
/**
 * INFOCAMPO - API: Buscar/crear infraestructuras
 *
 * GET  ?empresa_id=X&q=texto                    → buscar por nombre/código
 * GET  ?empresa_id=X&provincia=Y                → filtrar por provincia
 * GET  ?empresa_id=X&provincia=Y&municipio=Z    → filtrar por provincia y municipio
 * GET  ?empresa_id=X&action=provincias          → lista de provincias únicas
 * GET  ?empresa_id=X&action=municipios&provincia=Y → lista de municipios de una provincia
 * GET  ?empresa_id=X&action=montes[&provincia=Y&municipio=Z] → lista de montes
 * POST empresa_id, nombre, lat, lon             → crear nueva infraestructura
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

// Las columnas provincia, municipio y monte existen desde schema_v4+
$hasLocationCols = true;
$hasMonteCols = true;

// ---------------------------------------------------------------
// GET: buscar infraestructuras / listas de provincias/municipios
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $empresaId = (int) ($_SESSION['empresa_id'] ?? 0);
    $action    = trim($_GET['action'] ?? '');

    if ($empresaId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'empresa_id requerido']);
        exit;
    }

    // Listar provincias únicas
    if ($action === 'provincias') {
        if (!$hasLocationCols) {
            echo json_encode(['ok' => true, 'provincias' => []]);
            exit;
        }
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
        if (!$hasLocationCols) {
            echo json_encode(['ok' => true, 'municipios' => []]);
            exit;
        }
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

    // Listar montes de una provincia (y opcionalmente municipio)
    if ($action === 'montes') {
        if (!$hasMonteCols) {
            echo json_encode(['ok' => true, 'montes' => []]);
            exit;
        }
        $provincia = trim($_GET['provincia'] ?? '');
        $municipio = trim($_GET['municipio'] ?? '');

        $sql = "SELECT DISTINCT monte FROM infraestructuras
                WHERE empresa_id = :emp_id AND activa = 1 AND monte IS NOT NULL AND monte != ''";
        $params = [':emp_id' => $empresaId];

        if ($hasLocationCols && $provincia !== '') {
            $sql .= " AND provincia = :provincia";
            $params[':provincia'] = $provincia;
        }
        if ($hasLocationCols && $municipio !== '') {
            $sql .= " AND municipio = :municipio";
            $params[':municipio'] = $municipio;
        }
        $sql .= " ORDER BY monte ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $montes = array_column($stmt->fetchAll(), 'monte');
        echo json_encode(['ok' => true, 'montes' => $montes]);
        exit;
    }

    // Buscar infraestructuras con filtros opcionales
    $q         = trim($_GET['q'] ?? '');
    $provincia = trim($_GET['provincia'] ?? '');
    $municipio = trim($_GET['municipio'] ?? '');

    $where  = "empresa_id = :emp_id AND activa = 1";
    $params = [':emp_id' => $empresaId];

    if ($hasLocationCols && $provincia !== '') {
        $where .= " AND provincia = :provincia";
        $params[':provincia'] = $provincia;
    }
    if ($hasLocationCols && $municipio !== '') {
        $where .= " AND municipio = :municipio";
        $params[':municipio'] = $municipio;
    }

    $monte = trim($_GET['monte'] ?? '');
    if ($hasMonteCols && $monte !== '') {
        $where .= " AND monte = :monte";
        $params[':monte'] = $monte;
    }

    if ($q !== '') {
        $where .= " AND (nombre LIKE :q OR codigo_unico LIKE :q OR descripcion LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    $selectCols = "id, nombre, codigo_unico, lat_teorica, lon_teorica, tipo, descripcion";
    if ($hasLocationCols) {
        $selectCols .= ", provincia, municipio";
    }
    if ($hasMonteCols) {
        $selectCols .= ", monte";
    }

    $stmt = $pdo->prepare(
        "SELECT $selectCols
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
    $empresaId = (int) ($_SESSION['empresa_id'] ?? 0);
    $nombre    = trim($_POST['nombre'] ?? '');
    $lat       = (float) ($_POST['lat'] ?? 0);
    $lon       = (float) ($_POST['lon'] ?? 0);
    $provincia = trim($_POST['provincia'] ?? '');
    $municipio = trim($_POST['municipio'] ?? '');
    $monte     = trim($_POST['monte'] ?? '');

    if ($empresaId <= 0 || $nombre === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'empresa_id y nombre son requeridos']);
        exit;
    }

    // Auto-generar código único
    $codigo = 'INF-' . strtoupper(substr(md5($nombre . time()), 0, 8));

    if ($hasLocationCols) {
        if ($hasMonteCols) {
            $stmt = $pdo->prepare(
                "INSERT INTO infraestructuras (empresa_id, nombre, codigo_unico, lat_teorica, lon_teorica, tipo, provincia, municipio, monte, activa)
                 VALUES (:emp_id, :nombre, :codigo, :lat, :lon, NULL, :provincia, :municipio, :monte, 1)"
            );
            $stmt->execute([
                ':emp_id'    => $empresaId,
                ':nombre'    => $nombre,
                ':codigo'    => $codigo,
                ':lat'       => $lat,
                ':lon'       => $lon,
                ':provincia' => $provincia ?: null,
                ':municipio' => $municipio ?: null,
                ':monte'     => $monte ?: null,
            ]);
        } else {
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
        }
    } else {
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
    }

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
            'monte'         => $monte ?: null,
        ]
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
