<?php
/**
 * INFOCAMPO SaaS - API: Puntos de mapa personalizados
 *
 * GET  ?empresa_id=X              → listar puntos activos (operador)
 * GET  ?empresa_id=X&todos=1      → listar todos incluidos inactivos (admin)
 * POST action=crear               → crear punto
 * POST action=editar              → editar punto
 * POST action=eliminar            → eliminar punto
 * POST action=toggle              → activar/desactivar punto
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Requiere autenticación para evitar fuga de datos entre empresas
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'No autenticado']);
        exit;
    }

    // empresa_id siempre desde la sesión (el superadmin puede pasar uno explícito)
    $empresaId = (int) ($_SESSION['empresa_id'] ?? 0);
    if (($_SESSION['user_rol'] ?? '') === 'superadmin' && isset($_GET['empresa_id'])) {
        $empresaId = (int) $_GET['empresa_id'];
    }
    $todos = isset($_GET['todos']) && $_GET['todos'] === '1';

    if ($empresaId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'empresa_id requerido']);
        exit;
    }

    try {
        $pdo = getDB();
        $sql = "SELECT id, nombre, descripcion, lat, lon, icono, color, activo, created_at
                FROM puntos_mapa
                WHERE empresa_id = :empresa_id";
        if (!$todos) {
            $sql .= " AND activo = 1";
        }
        $sql .= " ORDER BY nombre ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':empresa_id' => $empresaId]);

        echo json_encode(['ok' => true, 'puntos' => $stmt->fetchAll()]);
    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Error de base de datos']);
    }
    exit;
}

// POST — requiere sesión admin
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userRole = $_SESSION['user_rol'] ?? '';
if (!in_array($userRole, ['admin', 'superadmin'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
    exit;
}

validateCsrf();

$action = $_POST['action'] ?? '';
// empresa_id siempre desde la sesión (el superadmin puede pasar uno explícito)
$empresaId = (int) ($_SESSION['empresa_id'] ?? 0);
if ($userRole === 'superadmin' && isset($_POST['empresa_id'])) {
    $empresaId = (int) $_POST['empresa_id'];
}

if ($empresaId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'empresa_id requerido']);
    exit;
}

$pdo = getDB();

try {
    switch ($action) {
        case 'crear':
            $nombre = trim($_POST['nombre'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            $lat = (float) ($_POST['lat'] ?? 0);
            $lon = (float) ($_POST['lon'] ?? 0);
            $icono = trim($_POST['icono'] ?? 'pin');
            $color = trim($_POST['color'] ?? '#e74c3c');

            if ($nombre === '' || ($lat == 0 && $lon == 0)) {
                echo json_encode(['ok' => false, 'error' => 'Nombre y coordenadas requeridos']);
                exit;
            }

            $stmt = $pdo->prepare(
                "INSERT INTO puntos_mapa (empresa_id, nombre, descripcion, lat, lon, icono, color, created_by)
                 VALUES (:empresa_id, :nombre, :descripcion, :lat, :lon, :icono, :color, :created_by)"
            );
            $stmt->execute([
                ':empresa_id' => $empresaId,
                ':nombre' => $nombre,
                ':descripcion' => $descripcion,
                ':lat' => $lat,
                ':lon' => $lon,
                ':icono' => $icono,
                ':color' => $color,
                ':created_by' => (int) ($_SESSION['user_id'] ?? 0),
            ]);

            echo json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
            break;

        case 'editar':
            $id = (int) ($_POST['id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            $lat = (float) ($_POST['lat'] ?? 0);
            $lon = (float) ($_POST['lon'] ?? 0);
            $icono = trim($_POST['icono'] ?? 'pin');
            $color = trim($_POST['color'] ?? '#e74c3c');

            if ($id <= 0 || $nombre === '') {
                echo json_encode(['ok' => false, 'error' => 'ID y nombre requeridos']);
                exit;
            }

            $stmt = $pdo->prepare(
                "UPDATE puntos_mapa SET nombre = :nombre, descripcion = :descripcion,
                        lat = :lat, lon = :lon, icono = :icono, color = :color
                 WHERE id = :id AND empresa_id = :empresa_id"
            );
            $stmt->execute([
                ':id' => $id,
                ':empresa_id' => $empresaId,
                ':nombre' => $nombre,
                ':descripcion' => $descripcion,
                ':lat' => $lat,
                ':lon' => $lon,
                ':icono' => $icono,
                ':color' => $color,
            ]);

            echo json_encode(['ok' => true]);
            break;

        case 'eliminar':
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'ID requerido']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM puntos_mapa WHERE id = :id AND empresa_id = :empresa_id");
            $stmt->execute([':id' => $id, ':empresa_id' => $empresaId]);

            echo json_encode(['ok' => true]);
            break;

        case 'toggle':
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'ID requerido']);
                exit;
            }

            $stmt = $pdo->prepare(
                "UPDATE puntos_mapa SET activo = NOT activo WHERE id = :id AND empresa_id = :empresa_id"
            );
            $stmt->execute([':id' => $id, ':empresa_id' => $empresaId]);

            echo json_encode(['ok' => true]);
            break;

        default:
            echo json_encode(['ok' => false, 'error' => 'Acción no reconocida']);
    }
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error de base de datos']);
}
