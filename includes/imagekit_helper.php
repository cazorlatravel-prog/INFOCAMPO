<?php
/**
 * INFOCAMPO SaaS - Helper para ImageKit.io
 *
 * Encapsula la subida de imagenes mediante la API REST de ImageKit.
 * No requiere SDK externo — usa cURL directamente.
 */

declare(strict_types=1);

class ImageKitHelper
{
    /**
     * Comprueba si ImageKit esta configurado con credenciales reales.
     */
    public static function isConfigured(): bool
    {
        $urlEndpoint = IMAGEKIT_URL_ENDPOINT;
        $publicKey   = IMAGEKIT_PUBLIC_KEY;
        $privateKey  = IMAGEKIT_PRIVATE_KEY;

        if (empty($urlEndpoint) || empty($publicKey) || empty($privateKey)) {
            return false;
        }
        $placeholders = ['your_url_endpoint', 'your_public_key', 'your_private_key', 'xxx', 'changeme'];
        if (in_array(strtolower($publicKey), $placeholders, true)
            || in_array(strtolower($privateKey), $placeholders, true)) {
            return false;
        }
        return true;
    }

    /**
     * Sube un archivo a ImageKit y devuelve la URL.
     *
     * @param string $filePath   Ruta local al archivo temporal
     * @param string $folder     Carpeta destino en ImageKit
     * @param string|null $fileName Nombre del archivo (sin extension)
     * @return string            URL publica de la imagen
     * @throws \RuntimeException Si la subida falla
     */
    public static function upload(string $filePath, string $folder = '/infocampo', ?string $fileName = null): string
    {
        $privateKey = IMAGEKIT_PRIVATE_KEY;

        if (!self::isConfigured()) {
            throw new \RuntimeException(
                'Credenciales de ImageKit no configuradas. '
                . 'Define IMAGEKIT_URL_ENDPOINT, IMAGEKIT_PUBLIC_KEY y IMAGEKIT_PRIVATE_KEY en .env'
            );
        }

        $url = 'https://upload.imagekit.io/api/v1/files/upload';

        $postFields = [
            'file'     => new \CURLFile($filePath),
            'fileName' => ($fileName ?: ('foto_' . time())) . '.jpg',
            'folder'   => $folder,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERPWD        => $privateKey . ':',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Error cURL: ' . $curlError);
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200 || !isset($data['url'])) {
            $errorMsg = $data['message'] ?? ($data['error'] ?? 'Respuesta inesperada de ImageKit');
            throw new \RuntimeException("ImageKit error ({$httpCode}): {$errorMsg}");
        }

        return $data['url'];
    }
}
