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
 *   - estado_incidencia : antes | durante | despues
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
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/imagekit_helper.php';
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
// Validar autenticación y CSRF
// ---------------------------------------------------------------
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

if (!validateCsrf()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido']);
    exit;
}

// ---------------------------------------------------------------
// 1. Validar campos obligatorios
// ---------------------------------------------------------------
$requiredFields = ['infra_id', 'usuario_id', 'lat_real', 'lon_real'];
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
$incidencia  = $_POST['estado_incidencia'] ?? 'antes';
$observaciones = isset($_POST['observaciones']) ? trim((string)$_POST['observaciones']) : null;
$datosTecnicos = isset($_POST['datos_tecnicos']) ? $_POST['datos_tecnicos'] : null;

// Campos v3: tipo de foto, secuencia, nombre, unidad de obra
$tipoFoto              = $_POST['tipo_foto'] ?? 'aleatorio';
$secuenciaComparativa  = isset($_POST['secuencia_comparativa']) && $_POST['secuencia_comparativa'] !== '' ? (int) $_POST['secuencia_comparativa'] : null;
$nombreArchivo         = isset($_POST['nombre_archivo']) ? trim((string) $_POST['nombre_archivo']) : null;
$unidadObraId          = isset($_POST['unidad_obra_id']) && $_POST['unidad_obra_id'] !== '' ? (int) $_POST['unidad_obra_id'] : null;
$tipoTrabajoId         = isset($_POST['tipo_trabajo_id']) && $_POST['tipo_trabajo_id'] !== '' ? (int) $_POST['tipo_trabajo_id'] : null;

// Validar enum de situación
$situacionesPermitidas = ['antes', 'durante', 'despues'];
if (!in_array($incidencia, $situacionesPermitidas, true)) {
    $incidencia = 'antes';
}

// Validar tipo de foto
if (!in_array($tipoFoto, ['aleatorio', 'comparativo'], true)) {
    $tipoFoto = 'aleatorio';
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
// 2. Generar nombre de archivo según formato configurado por la empresa
// ---------------------------------------------------------------
try {
    $pdo = getDB();

    // Obtener código de infraestructura y empresa_id
    $stmtInfra = $pdo->prepare(
        "SELECT i.codigo, i.nombre, i.empresa_id FROM infraestructuras i WHERE i.id = :id"
    );
    $stmtInfra->execute([':id' => $infraId]);
    $infraRow = $stmtInfra->fetch();

    if (!$infraRow) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Infraestructura no encontrada']);
        exit;
    }

    $infraCodigo = $infraRow['codigo'] ?: $infraRow['nombre'];
    $infraEmpresaId = (int) $infraRow['empresa_id'];

    // Validar que la infraestructura pertenece a la empresa del usuario
    $sessionEmpresaId = (int) ($_SESSION['empresa_id'] ?? 0);
    if ($sessionEmpresaId > 0 && $infraEmpresaId !== $sessionEmpresaId) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Infraestructura no pertenece a tu empresa']);
        exit;
    }

    // Obtener formato de nombre configurado para la empresa
    $stmtFmt = $pdo->prepare("SELECT formato_nombre_foto FROM empresas WHERE id = :id");
    $stmtFmt->execute([':id' => $infraEmpresaId]);
    $fmtRow = $stmtFmt->fetch();
    $formatoNombre = (int) ($fmtRow['formato_nombre_foto'] ?? 1);

    // Contar fotos existentes para esta infraestructura (para numeración secuencial)
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM registros WHERE infra_id = :infra_id");
    $stmtCount->execute([':infra_id' => $infraId]);
    $numFoto = (int) $stmtCount->fetchColumn() + 1;
    $numFotoStr = str_pad((string) $numFoto, 3, '0', STR_PAD_LEFT);

    // Sanitizar código de infraestructura para nombre de archivo
    $codigoSafe = preg_replace('/[^a-zA-Z0-9_\-áéíóúñÁÉÍÓÚÑ]/u', '_', $infraCodigo);
    $codigoSafe = substr($codigoSafe, 0, 60);

    // Construir nombre según formato
    switch ($formatoNombre) {
        case 2:
            // CODIGO_INFRA_TIPO_TRABAJO_NºFOTO
            $tipoTrabajoNombre = '';
            if ($tipoTrabajoId) {
                $stmtTT = $pdo->prepare("SELECT nombre FROM tipos_trabajo WHERE id = :id");
                $stmtTT->execute([':id' => $tipoTrabajoId]);
                $ttRow = $stmtTT->fetch();
                if ($ttRow) {
                    $tipoTrabajoNombre = preg_replace('/[^a-zA-Z0-9_\-áéíóúñÁÉÍÓÚÑ]/u', '_', $ttRow['nombre']);
                }
            }
            $nombreArchivo = $tipoTrabajoNombre
                ? "{$codigoSafe}_{$tipoTrabajoNombre}_{$numFotoStr}"
                : "{$codigoSafe}_{$numFotoStr}";
            break;

        case 3:
            // CODIGO_INFRA_TIPO_TRABAJO_TIPO_FOTO_NºFOTO
            $tipoTrabajoNombre = '';
            if ($tipoTrabajoId) {
                $stmtTT = $pdo->prepare("SELECT nombre FROM tipos_trabajo WHERE id = :id");
                $stmtTT->execute([':id' => $tipoTrabajoId]);
                $ttRow = $stmtTT->fetch();
                if ($ttRow) {
                    $tipoTrabajoNombre = preg_replace('/[^a-zA-Z0-9_\-áéíóúñÁÉÍÓÚÑ]/u', '_', $ttRow['nombre']);
                }
            }
            $tipoFotoLabel = $tipoFoto === 'comparativo' ? 'Comparativa' : 'Aleatoria';
            if ($tipoTrabajoNombre) {
                $nombreArchivo = "{$codigoSafe}_{$tipoTrabajoNombre}_{$tipoFotoLabel}_{$numFotoStr}";
            } else {
                $nombreArchivo = "{$codigoSafe}_{$tipoFotoLabel}_{$numFotoStr}";
            }
            break;

        default: // case 1
            // CODIGO_INFRA_NºFOTO
            $nombreArchivo = "{$codigoSafe}_{$numFotoStr}";
            break;
    }
} catch (\Exception $e) {
    // Si falla la generación de nombre, usar el nombre original del frontend
    if (!$nombreArchivo) {
        $nombreArchivo = 'foto_' . time();
    }
}

// ---------------------------------------------------------------
// 3. Subir imagen a ImageKit (o Cloudinary legacy, o local)
// ---------------------------------------------------------------
$cloudinaryUrl = '';

try {
    if (ImageKitHelper::isConfigured()) {
        // ImageKit configurado — subir
        $folder = $tipoFoto === 'comparativo'
            ? '/infocampo/comparativas'
            : '/infocampo/aleatorias';

        $cloudinaryUrl = ImageKitHelper::upload(
            $_FILES['imagen']['tmp_name'],
            $folder,
            $nombreArchivo ?: null
        );
    } elseif (CloudinaryHelper::isConfigured()) {
        // Cloudinary (legacy fallback)
        $folder = $tipoFoto === 'comparativo'
            ? 'infocampo/comparativas'
            : 'infocampo/aleatorias';
        $publicId = $nombreArchivo ?: null;

        $cloudinaryUrl = CloudinaryHelper::upload(
            $_FILES['imagen']['tmp_name'],
            $folder,
            $publicId
        );
    } else {
        // Ningún servicio de imágenes configurado — guardar en uploads/ local
        $uploadsDir = __DIR__ . '/uploads';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }
        $subDir = $tipoFoto === 'comparativo' ? 'comparativas' : 'aleatorias';
        $targetDir = $uploadsDir . '/' . $subDir;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $nombreArchivo ?: ('foto_' . time()));
        $localFile = $safeName . '.jpg';
        $destPath  = $targetDir . '/' . $localFile;

        // Evitar sobrescribir
        if (file_exists($destPath)) {
            $localFile = $safeName . '_' . time() . '.jpg';
            $destPath  = $targetDir . '/' . $localFile;
        }

        move_uploaded_file($_FILES['imagen']['tmp_name'], $destPath);

        // Generar URL relativa accesible desde el navegador
        $cloudinaryUrl = APP_URL . '/uploads/' . $subDir . '/' . $localFile;
    }
} catch (\Exception $e) {
    // Registrar la subida fallida para notificar al admin
    try {
        $pdo = getDB();
        // Obtener empresa_id de la infraestructura
        $empStmt = $pdo->prepare("SELECT empresa_id FROM infraestructuras WHERE id = :id");
        $empStmt->execute([':id' => $infraId]);
        $empRow = $empStmt->fetch();
        $failEmpresaId = $empRow ? (int) $empRow['empresa_id'] : 0;

        if ($failEmpresaId > 0) {
            // Verificar si la tabla existe (puede no existir si no se ha migrado)
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'subidas_fallidas'")->fetchColumn();
            if ($tableCheck) {
                $failStmt = $pdo->prepare(
                    "INSERT INTO subidas_fallidas (empresa_id, usuario_id, infra_id, nombre_archivo, estado_incidencia, tipo_foto, motivo_error)
                     VALUES (:emp, :usr, :infra, :nombre, :estado, :tipo, :motivo)"
                );
                $failStmt->execute([
                    ':emp'    => $failEmpresaId,
                    ':usr'    => $usuarioId,
                    ':infra'  => $infraId,
                    ':nombre' => $nombreArchivo,
                    ':estado' => $incidencia,
                    ':tipo'   => $tipoFoto,
                    ':motivo' => $e->getMessage(),
                ]);
            }
        }
    } catch (\Exception $logErr) {
        // Silenciar error de logging para no enmascarar el original
    }

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Error al subir imagen: ' . $e->getMessage(),
        'upload_failed' => true,
        'keep_photo' => true,
    ]);
    exit;
}

// ---------------------------------------------------------------
// 4. Guardar en base de datos (prepared statement)
// ---------------------------------------------------------------
try {
    if (!isset($pdo)) $pdo = getDB();

    $sql = "INSERT INTO registros
                (infra_id, unidad_obra_id, tipo_trabajo_id, usuario_id, fecha, lat_real, lon_real,
                 url_cloudinary, datos_tecnicos, estado_incidencia, observaciones,
                 tipo_foto, secuencia_comparativa, nombre_archivo)
            VALUES
                (:infra_id, :unidad_obra_id, :tipo_trabajo_id, :usuario_id, NOW(), :lat_real, :lon_real,
                 :url_cloudinary, :datos_tecnicos, :estado_incidencia, :observaciones,
                 :tipo_foto, :secuencia_comp, :nombre_archivo)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':infra_id'          => $infraId,
        ':unidad_obra_id'    => $unidadObraId,
        ':tipo_trabajo_id'   => $tipoTrabajoId,
        ':usuario_id'        => $usuarioId,
        ':lat_real'          => $latReal,
        ':lon_real'          => $lonReal,
        ':url_cloudinary'    => $cloudinaryUrl,
        ':datos_tecnicos'    => $datosTecnicos,
        ':estado_incidencia' => $incidencia,
        ':observaciones'     => $observaciones,
        ':tipo_foto'         => $tipoFoto,
        ':secuencia_comp'    => $secuenciaComparativa,
        ':nombre_archivo'    => $nombreArchivo,
    ]);

    $registroId = (int) $pdo->lastInsertId();

    // ---------------------------------------------------------------
    // 5. Guardar campos dinámicos (si existen)
    // ---------------------------------------------------------------
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
        'url_imagen'  => $cloudinaryUrl,
    ]);

} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error de base de datos']);
    exit;
}
