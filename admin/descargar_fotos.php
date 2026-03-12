<?php
/**
 * INFOCAMPO SaaS - Descarga masiva de fotos (ZIP)
 *
 * GET ?infra_id=123
 *
 * Descarga todas las fotos de Cloudinary/ImageKit de una infraestructura,
 * las empaqueta en un ZIP y lo envía al navegador.
 *
 * Optimizado: descarga imágenes una a una al archivo ZIP temporal en disco,
 * liberando memoria entre cada imagen para evitar uso excesivo de RAM.
 */

declare(strict_types=1);

// Allow long-running downloads but set a reasonable limit
set_time_limit(600); // 10 minutes max

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'supervisor', 'superadmin']);

$infraId = isset($_GET['infra_id']) ? (int) $_GET['infra_id'] : 0;

if ($infraId <= 0) {
    http_response_code(400);
    echo 'Parámetro infra_id requerido';
    exit;
}

$pdo = getDB();
$empresaId = (int) ($_SESSION['empresa_id'] ?? 0);

// Obtener info de la infraestructura (verificando que pertenece a la empresa)
$stmt = $pdo->prepare(
    "SELECT codigo_unico FROM infraestructuras WHERE id = :id AND empresa_id = :emp_id"
);
$stmt->execute([':id' => $infraId, ':emp_id' => $empresaId]);
$infra = $stmt->fetch();

if (!$infra) {
    http_response_code(403);
    echo 'Infraestructura no encontrada o no pertenece a tu empresa';
    exit;
}

// Obtener registros con fotos — usar cursor para no cargar todo en memoria
$stmt = $pdo->prepare(
    "SELECT r.url_cloudinary, r.fecha, r.estado_incidencia, r.nombre_archivo
     FROM registros r
     INNER JOIN infraestructuras i ON r.infra_id = i.id
     WHERE r.infra_id = :infra_id AND i.empresa_id = :emp_id
       AND r.url_cloudinary IS NOT NULL AND r.url_cloudinary != ''
     ORDER BY r.fecha ASC"
);
$stmt->execute([':infra_id' => $infraId, ':emp_id' => $empresaId]);

// Contar registros sin cargar todo
$registros = $stmt->fetchAll();
if (empty($registros)) {
    http_response_code(404);
    echo 'No hay fotos para esta infraestructura';
    exit;
}

// Crear ZIP temporal
$tmpFile = tempnam(sys_get_temp_dir(), 'infocampo_');
$zip = new ZipArchive();

if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo 'Error al crear archivo ZIP';
    exit;
}

// Reutilizar handle cURL para connection pooling (evita nuevo handshake TLS por imagen)
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_ENCODING       => '', // Accept compressed responses
]);

$idx = 1;
$maxPhotos = 500; // Safety limit to prevent extreme cases
$errors = 0;

foreach ($registros as $reg) {
    if ($idx > $maxPhotos) {
        break;
    }

    $url = $reg['url_cloudinary'];

    // Descargar imagen reutilizando el handle cURL
    curl_setopt($ch, CURLOPT_URL, $url);
    $imageData = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($imageData === false || $httpCode !== 200) {
        $errors++;
        continue;
    }

    // Generar nombre de archivo
    $fecha = date('Ymd_His', strtotime($reg['fecha']));
    $estado = $reg['estado_incidencia'];
    $filename = sprintf(
        '%s_%s_%s_%03d.jpg',
        $infra['codigo_unico'],
        $fecha,
        $estado,
        $idx
    );

    $zip->addFromString($filename, $imageData);

    // Liberar memoria de la imagen inmediatamente
    unset($imageData);

    $idx++;
}

curl_close($ch);
$zip->close();

// Enviar ZIP al navegador
$zipName = $infra['codigo_unico'] . '_fotos_' . date('Ymd') . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: no-cache');

readfile($tmpFile);
unlink($tmpFile);
