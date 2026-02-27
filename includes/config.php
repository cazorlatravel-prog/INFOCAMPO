<?php
/**
 * INFOCAMPO SaaS - Configuración global
 */

// -----------------------------------------------------------
// Base de datos
// -----------------------------------------------------------
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'infocampo_saas');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// -----------------------------------------------------------
// Cloudinary
// -----------------------------------------------------------
define('CLOUDINARY_CLOUD_NAME', getenv('CLOUDINARY_CLOUD_NAME') ?: '');
define('CLOUDINARY_API_KEY',    getenv('CLOUDINARY_API_KEY')    ?: '');
define('CLOUDINARY_API_SECRET', getenv('CLOUDINARY_API_SECRET') ?: '');

// -----------------------------------------------------------
// App
// -----------------------------------------------------------
define('APP_URL', getenv('APP_URL') ?: 'http://localhost');
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
