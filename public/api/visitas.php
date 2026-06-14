<?php
/**
 * INFOCAMPO - API de Visitas del Operador
 *
 * GET: Lista visitas (registros) del operador agrupadas por infraestructura y fecha
 *   - usuario_id: ID del operador
 *   - empresa_id: ID de la empresa
 *   - action=detalle&registro_id=X: Detalle de un registro específico
 *
 * POST: Editar un registro existente
 *   - action=editar
 *   - registro_id: ID del registro a editar
 *   - usuario_id: para verificar propiedad
 *   - observaciones, estado_incidencia, unidad_obra_id: campos editables
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

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $usuarioId = (int) ($_SESSION['user_id'] ?? 0);
    $empresaId = (int) ($_SESSION['empresa_id'] ?? 0);
    $action = $_GET['action'] ?? 'listar';

    if ($usuarioId <= 0 || $empresaId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'usuario_id y empresa_id requeridos']);
        exit;
    }

    // Detalle de un registro
    if ($action === 'detalle') {
        $registroId = isset($_GET['registro_id']) ? (int) $_GET['registro_id'] : 0;
        if ($registroId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'registro_id requerido']);
            exit;
        }

        $stmt = $pdo->prepare(
            "SELECT r.id, r.infra_id, r.fecha, r.lat_real, r.lon_real,
                    r.url_cloudinary, r.estado_incidencia, r.observaciones,
                    r.tipo_foto, r.secuencia_comparativa, r.nombre_archivo,
                    r.unidad_obra_id,
                    i.nombre AS infra_nombre, i.codigo_unico,
                    uo.nombre AS uo_nombre
             FROM registros r
             INNER JOIN infraestructuras i ON r.infra_id = i.id
             LEFT JOIN unidades_obra uo ON r.unidad_obra_id = uo.id
             WHERE r.id = :id AND r.usuario_id = :uid AND i.empresa_id = :emp"
        );
        $stmt->execute([':id' => $registroId, ':uid' => $usuarioId, ':emp' => $empresaId]);
        $registro = $stmt->fetch();

        if (!$registro) {
            echo json_encode(['ok' => false, 'error' => 'Registro no encontrado']);
            exit;
        }

        echo json_encode(['ok' => true, 'registro' => $registro]);
        exit;
    }

    // Listar visitas agrupadas por infraestructura y día
    $stmt = $pdo->prepare(
        "SELECT r.id, r.infra_id, r.fecha, r.lat_real, r.lon_real,
                r.url_cloudinary, r.estado_incidencia, r.observaciones,
                r.tipo_foto, r.secuencia_comparativa, r.nombre_archivo,
                r.unidad_obra_id,
                i.nombre AS infra_nombre, i.codigo_unico,
                uo.nombre AS uo_nombre
         FROM registros r
         INNER JOIN infraestructuras i ON r.infra_id = i.id
         LEFT JOIN unidades_obra uo ON r.unidad_obra_id = uo.id
         WHERE r.usuario_id = :uid AND i.empresa_id = :emp
         ORDER BY r.fecha DESC
         LIMIT 500"
    );
    $stmt->execute([':uid' => $usuarioId, ':emp' => $empresaId]);
    $registros = $stmt->fetchAll();

    // Agrupar por infraestructura + día
    $visitas = [];
    foreach ($registros as $r) {
        $dia = date('Y-m-d', strtotime($r['fecha']));
        $key = $r['infra_id'] . '_' . $dia;

        if (!isset($visitas[$key])) {
            $visitas[$key] = [
                'infra_id' => (int) $r['infra_id'],
                'infra_nombre' => $r['infra_nombre'],
                'infra_codigo' => $r['codigo_unico'],
                'fecha' => $dia,
                'fotos' => [],
            ];
        }

        $visitas[$key]['fotos'][] = [
            'id' => (int) $r['id'],
            'url' => $r['url_cloudinary'],
            'estado' => $r['estado_incidencia'],
            'tipo' => $r['tipo_foto'],
            'seq' => $r['secuencia_comparativa'] ? (int) $r['secuencia_comparativa'] : null,
            'observaciones' => $r['observaciones'] ?? '',
            'nombre' => $r['nombre_archivo'] ?? '',
            'hora' => date('H:i', strtotime($r['fecha'])),
            'uo_nombre' => $r['uo_nombre'] ?? '',
            'unidad_obra_id' => $r['unidad_obra_id'] ? (int) $r['unidad_obra_id'] : null,
        ];
    }

    echo json_encode([
        'ok' => true,
        'visitas' => array_values($visitas),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido']);
        exit;
    }
    $action = $_POST['action'] ?? '';
    $registroId = isset($_POST['registro_id']) ? (int) $_POST['registro_id'] : 0;
    $usuarioId = (int) ($_SESSION['user_id'] ?? 0);

    if ($action === 'editar') {
        if ($registroId <= 0 || $usuarioId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'registro_id y usuario_id requeridos']);
            exit;
        }

        // Verificar que el registro pertenece al usuario
        $stmt = $pdo->prepare("SELECT id FROM registros WHERE id = :id AND usuario_id = :uid");
        $stmt->execute([':id' => $registroId, ':uid' => $usuarioId]);
        if (!$stmt->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'No tienes permiso para editar este registro']);
            exit;
        }

        // Construir UPDATE dinámico solo con campos enviados
        $updates = [];
        $params = [':id' => $registroId];

        if (isset($_POST['observaciones'])) {
            $updates[] = 'observaciones = :obs';
            $params[':obs'] = trim($_POST['observaciones']);
        }
        if (isset($_POST['estado_incidencia'])) {
            $estado = $_POST['estado_incidencia'];
            if (in_array($estado, ['antes', 'durante', 'despues'], true)) {
                $updates[] = 'estado_incidencia = :estado';
                $params[':estado'] = $estado;
            }
        }
        if (isset($_POST['unidad_obra_id'])) {
            $uoId = $_POST['unidad_obra_id'] !== '' ? (int) $_POST['unidad_obra_id'] : null;
            $updates[] = 'unidad_obra_id = :uo_id';
            $params[':uo_id'] = $uoId;
        }

        if (empty($updates)) {
            echo json_encode(['ok' => false, 'error' => 'No hay campos para actualizar']);
            exit;
        }

        $sql = "UPDATE registros SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['ok' => true]);
        exit;
    }

    // Acción: añadir foto a visita existente (misma infra)
    if ($action === 'añadir_foto') {
        // Redirigir a subir.php - este endpoint simplemente confirma que se puede
        echo json_encode(['ok' => true, 'message' => 'Usa el endpoint subir.php para añadir fotos']);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Acción no reconocida']);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
