<?php
/**
 * INFOCAMPO SaaS - Helper para Cloudinary
 *
 * Encapsula la subida de imágenes mediante la API REST de Cloudinary.
 * No requiere SDK externo — usa cURL directamente para mantener
 * el proyecto ligero. Compatible con cualquier plan de Cloudinary.
 */

declare(strict_types=1);

class CloudinaryHelper
{
    /**
     * Comprueba si Cloudinary está configurado con credenciales reales.
     */
    public static function isConfigured(): bool
    {
        $cloudName = CLOUDINARY_CLOUD_NAME;
        $apiKey    = CLOUDINARY_API_KEY;
        $apiSecret = CLOUDINARY_API_SECRET;

        if (empty($cloudName) || empty($apiKey) || empty($apiSecret)) {
            return false;
        }
        $placeholders = ['your_cloud_name', 'your_api_key', 'your_api_secret', 'xxx', 'changeme'];
        if (in_array(strtolower($cloudName), $placeholders, true)
            || in_array(strtolower($apiKey), $placeholders, true)
            || in_array(strtolower($apiSecret), $placeholders, true)) {
            return false;
        }
        return true;
    }

    /**
     * Sube un archivo a Cloudinary y devuelve la URL segura.
     *
     * @param string $filePath   Ruta local al archivo temporal
     * @param string $folder     Carpeta destino en Cloudinary
     * @return string            URL pública (secure_url) de la imagen
     * @throws \RuntimeException Si la subida falla
     */
    public static function upload(string $filePath, string $folder = 'infocampo', ?string $publicId = null): string
    {
        $cloudName = CLOUDINARY_CLOUD_NAME;
        $apiKey    = CLOUDINARY_API_KEY;
        $apiSecret = CLOUDINARY_API_SECRET;

        if (!self::isConfigured()) {
            throw new \RuntimeException(
                'Credenciales de Cloudinary no configuradas. '
                . 'Define CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY y CLOUDINARY_API_SECRET en .env'
            );
        }

        $timestamp = time();
        $paramsToSign = [
            'folder'    => $folder,
            'timestamp' => $timestamp,
        ];

        if ($publicId !== null) {
            $paramsToSign['public_id'] = $publicId;
        }

        // Generar firma (signature) según API de Cloudinary
        // No usar http_build_query() porque URL-codifica los valores
        // (ej: / → %2F) y Cloudinary espera valores sin codificar.
        ksort($paramsToSign);
        $parts = [];
        foreach ($paramsToSign as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        $signatureString = implode('&', $parts) . $apiSecret;
        // Cuentas Cloudinary creadas desde julio 2023 requieren SHA-256
        $signature = hash('sha256', $signatureString);

        $url = "https://api.cloudinary.com/v1_1/{$cloudName}/image/upload";

        $postFields = [
            'file'                => new \CURLFile($filePath),
            'folder'              => $folder,
            'timestamp'           => $timestamp,
            'api_key'             => $apiKey,
            'signature'           => $signature,
            'signature_algorithm' => 'sha256',
        ];

        if ($publicId !== null) {
            $postFields['public_id'] = $publicId;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Error cURL: ' . $curlError);
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200 || !isset($data['secure_url'])) {
            $errorMsg = $data['error']['message'] ?? 'Respuesta inesperada de Cloudinary';
            throw new \RuntimeException("Cloudinary error ({$httpCode}): {$errorMsg}");
        }

        return $data['secure_url'];
    }
}
