<?php
/**
 * INFOCAMPO SaaS - Endpoint de subida de inspección
 *
 * Recibe por POST:
 *   - imagen       : archivo JPEG del canvas
 *   - infra_id     : ID de la infraestructura
 *   - usuario_id   : ID del usuario operador
 *   - lat_real     : latitud GPS real
 *   - lon_real     : longitud GPS real
 *   - estado_incidencia : bajo | medio | critico
 *   - datos_tecnicos    : JSON string
 *   - observaciones     : texto libre
 *
 * Flujo:
 *   1. Valida los datos de entrada
 *   2. Sube la imagen a Cloudinary (SDK PHP)
 *   3. Guarda el registro en MySQL con prepared statements
 *   4. Responde JSON
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/cloudinary_helper.php';

// ---------------------------------------------------------------
// Solo aceptar POST
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

// ---------------------------------------------------------------
// 1. Validar campos obligatorios
// ---------------------------------------------------------------
$requiredFields = ['infra_id', 'usuario_id', 'lat_real', 'lon_real', 'estado_incidencia'];
foreach ($requiredFields as $field) {
    if (!isset($_POST[$field]) || trim((string)$_POST[$field]) === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "Campo requerido: {$field}"]);
        exit;
    }
}

// Validar archivo de imagen
if (!isset($_FILES['imagen']) || $_FILES['imagen']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Archivo de imagen requerido']);
    exit;
}

// Validar tipo MIME
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($_FILES['imagen']['tmp_name']);
if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Tipo de imagen no válido']);
    exit;
}

// Sanitizar y castear
$infraId     = (int) $_POST['infra_id'];
$usuarioId   = (int) $_POST['usuario_id'];
$latReal     = (float) $_POST['lat_real'];
$lonReal     = (float) $_POST['lon_real'];
$incidencia  = $_POST['estado_incidencia'];
$observaciones = isset($_POST['observaciones']) ? trim((string)$_POST['observaciones']) : null;
$datosTecnicos = isset($_POST['datos_tecnicos']) ? $_POST['datos_tecnicos'] : null;

// Validar enum de incidencia
$incidenciasPermitidas = ['bajo', 'medio', 'critico'];
if (!in_array($incidencia, $incidenciasPermitidas, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Valor de incidencia no válido']);
    exit;
}

// Validar JSON de datos técnicos
if ($datosTecnicos !== null) {
    json_decode($datosTecnicos);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'datos_tecnicos debe ser un JSON válido']);
        exit;
    }
}

// Validar que el ID sea > 0
if ($infraId <= 0 || $usuarioId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'IDs inválidos']);
    exit;
}

// ---------------------------------------------------------------
// 2. Subir imagen a Cloudinary
// ---------------------------------------------------------------
try {
    $cloudinaryUrl = CloudinaryHelper::upload(
        $_FILES['imagen']['tmp_name'],
        'infocampo/inspecciones'
    );
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al subir imagen: ' . $e->getMessage()]);
    exit;
}

// ---------------------------------------------------------------
// 3. Guardar en base de datos (prepared statement)
// ---------------------------------------------------------------
try {
    $pdo = getDB();

    $sql = "INSERT INTO registros
                (infra_id, usuario_id, fecha, lat_real, lon_real,
                 url_cloudinary, datos_tecnicos, estado_incidencia, observaciones)
            VALUES
                (:infra_id, :usuario_id, NOW(), :lat_real, :lon_real,
                 :url_cloudinary, :datos_tecnicos, :estado_incidencia, :observaciones)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':infra_id'          => $infraId,
        ':usuario_id'        => $usuarioId,
        ':lat_real'          => $latReal,
        ':lon_real'          => $lonReal,
        ':url_cloudinary'    => $cloudinaryUrl,
        ':datos_tecnicos'    => $datosTecnicos,
        ':estado_incidencia' => $incidencia,
        ':observaciones'     => $observaciones,
    ]);

    $registroId = (int) $pdo->lastInsertId();

    echo json_encode([
        'ok'          => true,
        'registro_id' => $registroId,
        'url_imagen'  => $cloudinaryUrl,
    ]);

} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error de base de datos']);
    // En producción: loggear $e->getMessage()
    exit;
}
