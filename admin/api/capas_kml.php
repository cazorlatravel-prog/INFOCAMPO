<?php
/**
 * INFOCAMPO SaaS - API de Capas KML (Admin)
 *
 * CRUD para gestionar capas KML persistentes por empresa.
 * GET:  Listar capas activas de la empresa
 * POST: Guardar nueva capa KML o eliminar existente
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

requireRole(['admin', 'superadmin']);

header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();
// Solo superadmins pueden especificar empresa_id diferente
$empresaId = (int) ($_SESSION['empresa_id'] ?? 0);
if (($_SESSION['user_rol'] ?? '') === 'superadmin' && (isset($_GET['empresa_id']) || isset($_POST['empresa_id']))) {
    $empresaId = (int) ($_GET['empresa_id'] ?? $_POST['empresa_id']);
}

if ($empresaId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empresa_id requerido'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- GET: Listar capas ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $capaId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($capaId > 0) {
        // Cargar una sola capa (lazy loading desde el mapa)
        $stmt = $pdo->prepare(
            "SELECT id, nombre, contenido_kml, color, grosor, opacidad
             FROM capas_kml
             WHERE id = :id AND empresa_id = :emp AND activa = 1"
        );
        $stmt->execute([':id' => $capaId, ':emp' => $empresaId]);
        $capa = $stmt->fetch();
        if (!$capa) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Capa no encontrada'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // KML content rarely changes — cache for 10 minutes
        header('Cache-Control: private, max-age=600');
        echo json_encode(['ok' => true, 'capa' => $capa], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Listar todas (metadatos sin contenido KML para reducir payload)
    $includeContent = isset($_GET['full']);
    $cols = $includeContent
        ? "id, nombre, contenido_kml, color, grosor, opacidad, activa, created_at"
        : "id, nombre, color, grosor, opacidad, activa, created_at";

    $stmt = $pdo->prepare(
        "SELECT $cols FROM capas_kml WHERE empresa_id = :emp AND activa = 1 ORDER BY created_at DESC"
    );
    $stmt->execute([':emp' => $empresaId]);
    $capas = $stmt->fetchAll();

    header('Cache-Control: private, max-age=60');
    echo json_encode(['ok' => true, 'capas' => $capas], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- POST: Guardar / Eliminar ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $action = $_POST['action'] ?? '';

    // --- Eliminar capa ---
    if ($action === 'eliminar') {
        $capaId = (int) ($_POST['capa_id'] ?? 0);
        if ($capaId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'capa_id requerido'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare(
            "UPDATE capas_kml SET activa = 0 WHERE id = :id AND empresa_id = :emp"
        );
        $stmt->execute([':id' => $capaId, ':emp' => $empresaId]);

        echo json_encode(['ok' => true, 'eliminada' => $capaId], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- Actualizar estilo (grosor/opacidad) ---
    if ($action === 'actualizar_estilo') {
        $capaId = (int) ($_POST['capa_id'] ?? 0);
        if ($capaId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'capa_id requerido'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $grosor = isset($_POST['grosor']) ? max(1, min(10, (int) $_POST['grosor'])) : null;
        $opacidad = isset($_POST['opacidad']) ? max(0.0, min(1.0, (float) $_POST['opacidad'])) : null;
        $color = isset($_POST['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color']) ? $_POST['color'] : null;

        $updates = [];
        $params = [':id' => $capaId, ':emp' => $empresaId];

        if ($grosor !== null) {
            $updates[] = 'grosor = :grosor';
            $params[':grosor'] = $grosor;
        }
        if ($opacidad !== null) {
            $updates[] = 'opacidad = :opacidad';
            $params[':opacidad'] = $opacidad;
        }
        if ($color !== null) {
            $updates[] = 'color = :color';
            $params[':color'] = $color;
        }

        if (empty($updates)) {
            echo json_encode(['ok' => false, 'error' => 'No hay campos para actualizar'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare(
            "UPDATE capas_kml SET " . implode(', ', $updates) . " WHERE id = :id AND empresa_id = :emp"
        );
        $stmt->execute($params);

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- Guardar nueva capa ---
    $nombre = trim($_POST['nombre'] ?? '');
    $contenidoKml = trim($_POST['contenido_kml'] ?? '');
    $color = trim($_POST['color'] ?? '#8b5cf6');

    if ($nombre === '' || $contenidoKml === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'nombre y contenido_kml son requeridos'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Validar que es XML válido
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($contenidoKml);
    if ($xml === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'El archivo KML no es XML válido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Validar color hex
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $color = '#8b5cf6';
    }

    $grosor = isset($_POST['grosor']) ? max(1, min(10, (int) $_POST['grosor'])) : 3;
    $opacidad = isset($_POST['opacidad']) ? max(0.0, min(1.0, (float) $_POST['opacidad'])) : 0.80;

    $stmt = $pdo->prepare(
        "INSERT INTO capas_kml (empresa_id, nombre, contenido_kml, color, grosor, opacidad)
         VALUES (:emp, :nombre, :kml, :color, :grosor, :opacidad)"
    );
    $stmt->execute([
        ':emp' => $empresaId,
        ':nombre' => $nombre,
        ':kml' => $contenidoKml,
        ':color' => $color,
        ':grosor' => $grosor,
        ':opacidad' => $opacidad,
    ]);

    $newId = (int) $pdo->lastInsertId();

    echo json_encode([
        'ok' => true,
        'id' => $newId,
        'nombre' => $nombre,
        'color' => $color,
        'grosor' => $grosor,
        'opacidad' => $opacidad,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
