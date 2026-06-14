<?php
/**
 * INFOCAMPO SaaS - Descarga masiva de fotos (ZIP)
 *
 * GET ?infra_id=123
 *
 * Descarga todas las fotos de Cloudinary de una infraestructura,
 * las empaqueta en un ZIP y lo envía al navegador.
 */

declare(strict_types=1);

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

// Obtener info de la infraestructura (scoped por empresa)
$stmt = $pdo->prepare(
    "SELECT codigo_unico FROM infraestructuras WHERE id = :id AND empresa_id = :emp"
);
$stmt->execute([':id' => $infraId, ':emp' => $empresaId]);
$infra = $stmt->fetch();

if (!$infra) {
    http_response_code(404);
    echo 'Infraestructura no encontrada';
    exit;
}

// Obtener todos los registros con fotos (scoped por empresa)
$stmt = $pdo->prepare(
    "SELECT r.url_cloudinary, r.fecha, r.estado_incidencia, u.nombre AS usuario_nombre
     FROM registros r
     INNER JOIN usuarios u ON r.usuario_id = u.id
     INNER JOIN infraestructuras i ON r.infra_id = i.id
     WHERE r.infra_id = :infra_id AND i.empresa_id = :emp
     ORDER BY r.fecha ASC"
);
$stmt->execute([':infra_id' => $infraId, ':emp' => $empresaId]);
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

$idx = 1;
$added = 0;
foreach ($registros as $reg) {
    $url = $reg['url_cloudinary'];
    if (empty($url)) {
        continue;
    }

    // Descargar imagen vía cURL verificando que la respuesta sea 200 OK
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FAILONERROR    => true, // devuelve false en respuestas >= 400
    ]);
    $imageData = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Solo aceptar datos reales de una respuesta 200 OK
    if ($imageData === false || $httpCode !== 200 || $imageData === '') {
        continue;
    }

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
    $idx++;
    $added++;
}

$zip->close();

// Si no se pudo descargar ninguna foto, no servir un ZIP vacío
if ($added === 0) {
    @unlink($tmpFile);
    http_response_code(502);
    echo 'No se pudo descargar ninguna foto (las imágenes remotas no están disponibles).';
    exit;
}

// Enviar ZIP al navegador
$zipName = $infra['codigo_unico'] . '_fotos_' . date('Ymd') . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: no-cache');

readfile($tmpFile);
unlink($tmpFile);
