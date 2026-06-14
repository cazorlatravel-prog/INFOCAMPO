<?php
/**
 * INFOCAMPO - Descarga masiva de fotos seleccionadas (ZIP)
 *
 * GET ?ids=1,2,3
 *
 * Descarga las fotos seleccionadas por ID, empaquetadas en ZIP.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'supervisor', 'superadmin']);

$idsRaw = $_GET['ids'] ?? '';
$ids = array_filter(array_map('intval', explode(',', $idsRaw)), fn($id) => $id > 0);

if (empty($ids)) {
    http_response_code(400);
    echo 'Parametro ids requerido';
    exit;
}

$pdo = getDB();
$empresaId = $_SESSION['empresa_id'];

// Build placeholders for IN clause
$placeholders = implode(',', array_fill(0, count($ids), '?'));

$stmt = $pdo->prepare(
    "SELECT r.id, r.url_cloudinary, r.fecha, r.estado_incidencia,
            i.codigo_unico, u.nombre AS usuario_nombre
     FROM registros r
     INNER JOIN infraestructuras i ON r.infra_id = i.id
     INNER JOIN usuarios u ON r.usuario_id = u.id
     WHERE r.id IN ($placeholders) AND i.empresa_id = ?"
);

$params = $ids;
$params[] = $empresaId;
$stmt->execute($params);
$registros = $stmt->fetchAll();

if (empty($registros)) {
    http_response_code(404);
    echo 'No se encontraron fotos';
    exit;
}

$tmpFile = tempnam(sys_get_temp_dir(), 'infocampo_sel_');
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
    if (empty($url)) continue;

    // Descargar vía cURL verificando respuesta 200 OK
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FAILONERROR    => true,
    ]);
    $imageData = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($imageData === false || $httpCode !== 200 || $imageData === '') continue;

    $fecha = date('Ymd_His', strtotime($reg['fecha']));
    $filename = sprintf(
        '%s_%s_%s_%03d.jpg',
        $reg['codigo_unico'],
        $fecha,
        $reg['estado_incidencia'],
        $idx
    );

    $zip->addFromString($filename, $imageData);
    $idx++;
    $added++;
}

$zip->close();

if ($added === 0) {
    @unlink($tmpFile);
    http_response_code(502);
    echo 'No se pudo descargar ninguna foto (las imágenes remotas no están disponibles).';
    exit;
}

$zipName = 'fotos_seleccionadas_' . date('Ymd_His') . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: no-cache');

readfile($tmpFile);
unlink($tmpFile);
