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

require_once __DIR__ . '/../includes/config.php';

$infraId = isset($_GET['infra_id']) ? (int) $_GET['infra_id'] : 0;

if ($infraId <= 0) {
    http_response_code(400);
    echo 'Parámetro infra_id requerido';
    exit;
}

$pdo = getDB();

// Obtener info de la infraestructura
$stmt = $pdo->prepare(
    "SELECT codigo_unico FROM infraestructuras WHERE id = :id"
);
$stmt->execute([':id' => $infraId]);
$infra = $stmt->fetch();

if (!$infra) {
    http_response_code(404);
    echo 'Infraestructura no encontrada';
    exit;
}

// Obtener todos los registros con fotos
$stmt = $pdo->prepare(
    "SELECT r.url_cloudinary, r.fecha, r.estado_incidencia, u.nombre AS usuario_nombre
     FROM registros r
     INNER JOIN usuarios u ON r.usuario_id = u.id
     WHERE r.infra_id = :infra_id
     ORDER BY r.fecha ASC"
);
$stmt->execute([':infra_id' => $infraId]);
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
foreach ($registros as $reg) {
    $url = $reg['url_cloudinary'];
    if (empty($url)) {
        continue;
    }

    // Descargar imagen desde Cloudinary
    $imageData = @file_get_contents($url);
    if ($imageData === false) {
        // Reintentar con cURL si file_get_contents falla
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $imageData = curl_exec($ch);
        curl_close($ch);
    }

    if ($imageData === false) {
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
}

$zip->close();

// Enviar ZIP al navegador
$zipName = $infra['codigo_unico'] . '_fotos_' . date('Ymd') . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: no-cache');

readfile($tmpFile);
unlink($tmpFile);
