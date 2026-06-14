<?php
declare(strict_types=1);
/**
 * INFOCAMPO - Configuración global
 */

// -----------------------------------------------------------
// Cabeceras de seguridad (se envían antes de cualquier output)
// -----------------------------------------------------------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// -----------------------------------------------------------
// Cargar variables de entorno desde .env (cached in memory per-request,
// uses APCu if available to avoid re-parsing the file on every request)
// -----------------------------------------------------------
$_ENV_VARS = [];
$envFile = __DIR__ . '/../.env';
$_envCacheKey = 'infocampo_env_' . md5($envFile);
$_envLoaded = false;

// Try APCu cache first (avoids file I/O on every request)
if (function_exists('apcu_fetch')) {
    $_envCached = apcu_fetch($_envCacheKey, $_envLoaded);
    if ($_envLoaded) {
        $_ENV_VARS = $_envCached;
    }
}

if (!$_envLoaded && file_exists($envFile)) {
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
    // Cache for 5 minutes if APCu is available
    if (function_exists('apcu_store')) {
        apcu_store($_envCacheKey, $_ENV_VARS, 300);
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
define('DB_DRIVER', env('DB_DRIVER') ?: 'mysql');
define('DB_PORT', env('DB_PORT') ?: (DB_DRIVER === 'pgsql' ? '5432' : '3306'));

// -----------------------------------------------------------
// ImageKit.io
// -----------------------------------------------------------
define('IMAGEKIT_URL_ENDPOINT', env('IMAGEKIT_URL_ENDPOINT'));
define('IMAGEKIT_PUBLIC_KEY',   env('IMAGEKIT_PUBLIC_KEY'));
define('IMAGEKIT_PRIVATE_KEY',  env('IMAGEKIT_PRIVATE_KEY'));

// -----------------------------------------------------------
// Cloudinary (legacy — mantenido por compatibilidad)
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
define('APP_NAME', 'FotoGPS.app');

// -----------------------------------------------------------
// Conexión PDO (singleton simple — soporta MySQL y PostgreSQL)
// -----------------------------------------------------------
function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        if (DB_DRIVER === 'pgsql') {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
                DB_HOST, DB_PORT, DB_NAME
            );
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
            );
        }
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // En PostgreSQL vía Supabase pooler (transaction mode) los prepared
            // statements del servidor no son fiables: emulamos del lado cliente.
            PDO::ATTR_EMULATE_PREPARES   => (DB_DRIVER === 'pgsql'),
        ]);
        if (DB_DRIVER === 'pgsql') {
            $pdo->exec("SET client_encoding TO 'UTF8'");
        }
    }
    return $pdo;
}

function dbIsPostgres(): bool
{
    return DB_DRIVER === 'pgsql';
}

function dbReturningId(string $sql): string
{
    if (dbIsPostgres()) {
        $sql = rtrim($sql, "; \t\n\r") . ' RETURNING id';
    }
    return $sql;
}

function dbLastId(PDO $pdo, PDOStatement $stmt): int
{
    if (dbIsPostgres()) {
        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : 0;
    }
    return (int) $pdo->lastInsertId();
}
