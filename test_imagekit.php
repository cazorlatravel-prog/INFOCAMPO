<?php
/**
 * Diagnóstico de ImageKit - BORRAR DESPUÉS DE USAR
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/imagekit_helper.php';

echo "<h2>Diagnóstico ImageKit</h2>";

// 1. Verificar constantes
echo "<h3>1. Variables de entorno</h3>";
echo "<pre>";
echo "IMAGEKIT_URL_ENDPOINT = [" . IMAGEKIT_URL_ENDPOINT . "]\n";
echo "IMAGEKIT_PUBLIC_KEY   = [" . IMAGEKIT_PUBLIC_KEY . "]\n";
echo "IMAGEKIT_PRIVATE_KEY  = [" . (IMAGEKIT_PRIVATE_KEY !== '' ? substr(IMAGEKIT_PRIVATE_KEY, 0, 10) . '...' : 'VACÍO') . "]\n";
echo "</pre>";

// 2. Verificar isConfigured
echo "<h3>2. ImageKitHelper::isConfigured()</h3>";
echo "<pre>";
$configured = ImageKitHelper::isConfigured();
echo "Resultado: " . ($configured ? "TRUE (OK)" : "FALSE (PROBLEMA!)") . "\n";
echo "</pre>";

// 3. Verificar empty() de cada variable
echo "<h3>3. Comprobación de empty()</h3>";
echo "<pre>";
echo "empty(IMAGEKIT_URL_ENDPOINT) = " . (empty(IMAGEKIT_URL_ENDPOINT) ? 'true (MAL)' : 'false (OK)') . "\n";
echo "empty(IMAGEKIT_PUBLIC_KEY)   = " . (empty(IMAGEKIT_PUBLIC_KEY) ? 'true (MAL)' : 'false (OK)') . "\n";
echo "empty(IMAGEKIT_PRIVATE_KEY)  = " . (empty(IMAGEKIT_PRIVATE_KEY) ? 'true (MAL)' : 'false (OK)') . "\n";
echo "</pre>";

// 4. Verificar cURL
echo "<h3>4. Extensión cURL</h3>";
echo "<pre>";
echo "cURL disponible: " . (function_exists('curl_init') ? 'SÍ (OK)' : 'NO (PROBLEMA!)') . "\n";
if (function_exists('curl_version')) {
    $v = curl_version();
    echo "cURL versión: " . $v['version'] . "\n";
    echo "SSL versión: " . $v['ssl_version'] . "\n";
}
echo "</pre>";

// 5. Verificar ruta del .env
echo "<h3>5. Ruta del .env</h3>";
echo "<pre>";
$envPath = __DIR__ . '/.env';
echo "Buscando .env en: " . $envPath . "\n";
echo "Existe: " . (file_exists($envPath) ? 'SÍ' : 'NO') . "\n";
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    echo "Líneas en .env: " . count($lines) . "\n";
    foreach ($lines as $line) {
        $line = trim($line);
        if (stripos($line, 'IMAGEKIT') !== false) {
            // Ocultar valores sensibles
            $parts = explode('=', $line, 2);
            echo $parts[0] . " = [" . substr($parts[1] ?? '', 0, 15) . "...]\n";
        }
    }
}
echo "</pre>";

// 6. Test de conexión a ImageKit (sin subir)
echo "<h3>6. Test de conexión a ImageKit API</h3>";
echo "<pre>";
if ($configured && function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://upload.imagekit.io/api/v1/files/upload',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERPWD        => IMAGEKIT_PRIVATE_KEY . ':',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['fileName' => 'test.txt', 'file' => 'data:text/plain;base64,dGVzdA=='],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if ($curlError) {
        echo "ERROR cURL #{$curlErrno}: {$curlError}\n";
    } else {
        echo "HTTP Code: {$httpCode}\n";
        $data = json_decode($response, true);
        if ($httpCode === 200 && isset($data['url'])) {
            echo "ÉXITO! Imagen de test subida a: " . $data['url'] . "\n";
        } else {
            echo "Respuesta: " . $response . "\n";
        }
    }
} else {
    echo "No se puede probar (ImageKit no configurado o cURL no disponible)\n";
}
echo "</pre>";

echo "<hr><p><strong>IMPORTANTE:</strong> Borra este archivo después de usarlo (contiene info sensible).</p>";
