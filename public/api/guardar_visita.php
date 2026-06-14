<?php
/**
 * INFOCAMPO - Guardar visita sin foto
 *
 * Permite al operador registrar una visita a una infraestructura
 * sin necesidad de tomar una foto. Guarda observaciones, campos
 * dinámicos, unidad de obra, tipo de trabajo, situación y GPS.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

// Autenticación
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

// Protección CSRF
if (!validateCsrf()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido']);
    exit;
}

// Validar campo obligatorio
$infraId = isset($_POST['infra_id']) ? (int) $_POST['infra_id'] : 0;
if ($infraId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Infraestructura requerida']);
    exit;
}

$usuarioId     = (int) ($_SESSION['user_id'] ?? 0);
$latReal       = isset($_POST['lat_real']) ? (float) $_POST['lat_real'] : 0.0;
$lonReal       = isset($_POST['lon_real']) ? (float) $_POST['lon_real'] : 0.0;
$incidencia    = $_POST['estado_incidencia'] ?? 'antes';
$observaciones = isset($_POST['observaciones']) ? trim((string) $_POST['observaciones']) : null;
$unidadObraId  = isset($_POST['unidad_obra_id']) && $_POST['unidad_obra_id'] !== '' ? (int) $_POST['unidad_obra_id'] : null;
$tipoTrabajoId = isset($_POST['tipo_trabajo_id']) && $_POST['tipo_trabajo_id'] !== '' ? (int) $_POST['tipo_trabajo_id'] : null;

// Validar enum de situación
$situacionesPermitidas = ['antes', 'durante', 'despues'];
if (!in_array($incidencia, $situacionesPermitidas, true)) {
    $incidencia = 'antes';
}

try {
    $pdo = getDB();

    // Validar que la infraestructura existe y pertenece a la empresa del usuario
    $stmtInfra = $pdo->prepare(
        "SELECT id, empresa_id FROM infraestructuras WHERE id = :id"
    );
    $stmtInfra->execute([':id' => $infraId]);
    $infraRow = $stmtInfra->fetch();

    if (!$infraRow) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Infraestructura no encontrada']);
        exit;
    }

    $sessionEmpresaId = (int) ($_SESSION['empresa_id'] ?? 0);
    if ($sessionEmpresaId > 0 && (int) $infraRow['empresa_id'] !== $sessionEmpresaId) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Infraestructura no pertenece a tu empresa']);
        exit;
    }

    // Insertar registro de visita sin foto
    $sql = dbReturningId("INSERT INTO registros
                (infra_id, unidad_obra_id, tipo_trabajo_id, usuario_id, fecha,
                 lat_real, lon_real, url_cloudinary, estado_incidencia,
                 observaciones, tipo_foto, es_visita_sin_foto)
            VALUES
                (:infra_id, :unidad_obra_id, :tipo_trabajo_id, :usuario_id, NOW(),
                 :lat_real, :lon_real, NULL, :estado_incidencia,
                 :observaciones, 'aleatorio', 1)");

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':infra_id'          => $infraId,
        ':unidad_obra_id'    => $unidadObraId,
        ':tipo_trabajo_id'   => $tipoTrabajoId,
        ':usuario_id'        => $usuarioId,
        ':lat_real'          => $latReal,
        ':lon_real'          => $lonReal,
        ':estado_incidencia' => $incidencia,
        ':observaciones'     => $observaciones,
    ]);

    $registroId = dbLastId($pdo, $stmt);

    // Guardar campos dinámicos
    $camposDinamicos = $_POST['campos'] ?? [];
    if (is_array($camposDinamicos) && !empty($camposDinamicos)) {
        $stmtCampo = $pdo->prepare(
            "INSERT INTO valores_campo (registro_id, campo_id, valor)
             VALUES (:registro_id, :campo_id, :valor)"
        );
        foreach ($camposDinamicos as $campoId => $valor) {
            $campoId = (int) $campoId;
            if ($campoId > 0) {
                $stmtCampo->execute([
                    ':registro_id' => $registroId,
                    ':campo_id'    => $campoId,
                    ':valor'       => is_string($valor) ? trim($valor) : (string) $valor,
                ]);
            }
        }
    }

    echo json_encode([
        'ok'          => true,
        'registro_id' => $registroId,
        'message'     => 'Visita guardada correctamente',
    ]);

} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error de base de datos']);
    exit;
}
