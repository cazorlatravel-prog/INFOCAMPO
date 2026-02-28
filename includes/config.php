<?php
/**
 * INFOCAMPO SaaS - Configuración global
 */

// -----------------------------------------------------------
// Cargar variables de entorno desde .env
// -----------------------------------------------------------
$_ENV_VARS = [];
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        // Quitar comillas si las tiene
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'")) {
            $value = substr($value, 1, -1);
        }
        $_ENV_VARS[$key] = $value;
    }
}

// Helper: leer del .env parseado, con fallback
function env(string $key, string $default = ''): string {
    global $_ENV_VARS;
    return $_ENV_VARS[$key] ?? $default;
}

// -----------------------------------------------------------
// Base de datos
// -----------------------------------------------------------
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'infocampo_saas'));
define('DB_USER', env('DB_USER', 'root'));
// Aceptar DB_PASS o DB_PASSWORD (compatibilidad)
$dbPass = env('DB_PASS');
if ($dbPass === '') {
    $dbPass = env('DB_PASSWORD');
}
define('DB_PASS', $dbPass);
define('DB_CHARSET', 'utf8mb4');

// -----------------------------------------------------------
// Cloudinary
// -----------------------------------------------------------
define('CLOUDINARY_CLOUD_NAME', env('CLOUDINARY_CLOUD_NAME'));
define('CLOUDINARY_API_KEY',    env('CLOUDINARY_API_KEY'));
define('CLOUDINARY_API_SECRET', env('CLOUDINARY_API_SECRET'));

// -----------------------------------------------------------
// App
// -----------------------------------------------------------
$_appUrl = env('APP_URL');
if ($_appUrl === '') {
    // Auto-detectar URL base desde la petición HTTP
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_appUrl = $scheme . '://' . $host;
}
// Quitar barra final si la tiene
$_appUrl = rtrim($_appUrl, '/');
define('APP_URL', $_appUrl);
define('APP_NAME', 'INFOCAMPO');

// -----------------------------------------------------------
// Conexión PDO (singleton simple)
// -----------------------------------------------------------
function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
