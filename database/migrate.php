<?php
/**
 * INFOCAMPO SaaS - Migración de base de datos
 * Ejecuta el schema.sql para crear todas las tablas
 *
 * USO: Acceder desde el navegador una sola vez
 *      https://fotogps.app/database/migrate.php
 *
 * SEGURIDAD: Eliminar este archivo después de ejecutar la migración
 */

// Mostrar errores durante la migración
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>INFOCAMPO - Migración</title>
    <style>
        body { font-family: monospace; background: #1a1a2e; color: #eee; padding: 2rem; }
        .ok { color: #0f0; }
        .error { color: #f44; }
        .warn { color: #ff0; }
        .info { color: #4fc3f7; }
        pre { background: #16213e; padding: 1rem; border-radius: 8px; overflow-x: auto; }
    </style>
</head>
<body>
<h1>INFOCAMPO SaaS - Migración</h1>
<pre>
<?php
// --- DEBUG: mostrar dónde busca los archivos ---
$configPath = __DIR__ . '/../includes/config.php';
$envPath    = realpath(__DIR__ . '/..') . '/.env';
$envExists  = file_exists($envPath);

echo "<span class='info'>[DEBUG]</span> migrate.php está en:  " . __DIR__ . "\n";
echo "<span class='info'>[DEBUG]</span> Buscando config.php:  " . $configPath . "\n";
echo "<span class='info'>[DEBUG]</span> config.php existe:     " . (file_exists($configPath) ? 'SÍ' : 'NO') . "\n";
echo "<span class='info'>[DEBUG]</span> Buscando .env en:      " . $envPath . "\n";
echo "<span class='info'>[DEBUG]</span> .env existe:           " . ($envExists ? 'SÍ' : 'NO') . "\n";

if ($envExists) {
    echo "<span class='ok'>[OK]</span> Archivo .env encontrado\n";
    // Mostrar contenido del .env (censurar valores)
    $envLines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    echo "<span class='info'>[DEBUG]</span> Contenido del .env (" . count($envLines) . " líneas):\n";
    foreach ($envLines as $el) {
        $el = trim($el);
        if ($el === '' || $el[0] === '#') {
            echo "  $el\n";
            continue;
        }
        if (strpos($el, '=') !== false) {
            [$k, $v] = explode('=', $el, 2);
            $k = trim($k);
            $v = trim($v);
            // Mostrar clave y primeros 3 chars del valor
            $preview = strlen($v) > 3 ? substr($v, 0, 3) . '***' : $v;
            echo "  <span class='ok'>$k</span> = $preview\n";
        } else {
            echo "  (línea sin '='): $el\n";
        }
    }
    echo "\n";
} else {
    echo "<span class='error'>[ERROR]</span> NO se encuentra .env en: $envPath\n";
    echo "<span class='warn'>[AYUDA]</span> Crea el archivo .env en esa ruta con tus credenciales de BD\n";
    $parentDir = realpath(__DIR__ . '/..');
    echo "\n<span class='info'>[DEBUG]</span> Archivos en $parentDir:\n";
    $files = scandir($parentDir);
    foreach ($files as $f) {
        echo "  - $f\n";
    }
    echo "\n";
}

require_once $configPath;

echo "\n<span class='info'>[DEBUG]</span> Valores cargados:\n";
echo "  DB_HOST: " . DB_HOST . "\n";
echo "  DB_NAME: " . DB_NAME . "\n";
echo "  DB_USER: " . DB_USER . "\n";
echo "  DB_PASS: " . (DB_PASS !== '' ? '****(configurada)' : '(vacía)') . "\n\n";

try {
    echo "<span class='ok'>[OK]</span> Conectando a la base de datos...\n";
    $pdo = getDB();
    echo "<span class='ok'>[OK]</span> Conexión establecida: " . DB_HOST . " / " . DB_NAME . "\n\n";

    // Leer el archivo SQL
    $sqlFile = __DIR__ . '/schema.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("No se encontró el archivo schema.sql en: $sqlFile");
    }

    $sql = file_get_contents($sqlFile);
    echo "<span class='ok'>[OK]</span> Archivo schema.sql leído (" . strlen($sql) . " bytes)\n\n";

    // Verificar si las tablas ya existen
    $stmt = $pdo->query("SHOW TABLES");
    $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($existingTables) > 0) {
        echo "<span class='warn'>[AVISO]</span> Ya existen tablas en la base de datos:\n";
        foreach ($existingTables as $table) {
            echo "  - $table\n";
        }
        echo "\n<span class='warn'>[AVISO]</span> Si deseas recrear las tablas, primero elimínalas desde phpMyAdmin.\n";
        echo "<span class='warn'>[AVISO]</span> Intentando ejecutar igualmente (puede fallar si las tablas ya existen)...\n\n";
    }

    // Ejecutar las sentencias SQL una por una
    // Separar por punto y coma, ignorando los que están dentro de comentarios
    $statements = [];
    $current = '';
    $lines = explode("\n", $sql);

    foreach ($lines as $line) {
        $trimmed = trim($line);
        // Ignorar comentarios de línea
        if (str_starts_with($trimmed, '--') || $trimmed === '') {
            continue;
        }
        $current .= $line . "\n";
        if (str_ends_with($trimmed, ';')) {
            $statements[] = trim($current);
            $current = '';
        }
    }

    $success = 0;
    $errors = 0;

    foreach ($statements as $i => $stmt) {
        try {
            $pdo->exec($stmt);
            // Extraer nombre descriptivo
            if (preg_match('/CREATE\s+TABLE\s+(\w+)/i', $stmt, $m)) {
                echo "<span class='ok'>[OK]</span> Tabla creada: {$m[1]}\n";
            } elseif (preg_match('/CREATE\s+OR\s+REPLACE\s+VIEW\s+(\w+)/i', $stmt, $m)) {
                echo "<span class='ok'>[OK]</span> Vista creada: {$m[1]}\n";
            } elseif (preg_match('/INSERT\s+INTO\s+(\w+)/i', $stmt, $m)) {
                echo "<span class='ok'>[OK]</span> Datos insertados en: {$m[1]}\n";
            } elseif (preg_match('/SET\s+/i', $stmt)) {
                echo "<span class='ok'>[OK]</span> SET ejecutado\n";
            } else {
                echo "<span class='ok'>[OK]</span> Sentencia #" . ($i + 1) . " ejecutada\n";
            }
            $success++;
        } catch (PDOException $e) {
            echo "<span class='error'>[ERROR]</span> Sentencia #" . ($i + 1) . ": " . $e->getMessage() . "\n";
            $errors++;
        }
    }

    echo "\n========================================\n";
    echo "Resultado: <span class='ok'>$success ejecutadas</span>";
    if ($errors > 0) {
        echo ", <span class='error'>$errors errores</span>";
    }
    echo "\n========================================\n\n";

    // Verificar tablas finales
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Tablas en la base de datos:\n";
    foreach ($tables as $table) {
        echo "  <span class='ok'>✓</span> $table\n";
    }

    echo "\n<span class='warn'>⚠ IMPORTANTE: Elimina este archivo (migrate.php) después de la migración por seguridad.</span>\n";

} catch (Exception $e) {
    echo "<span class='error'>[ERROR FATAL]</span> " . $e->getMessage() . "\n";
    echo "\nVerifica los datos de conexión en .env:\n";
    echo "  DB_HOST: " . DB_HOST . "\n";
    echo "  DB_NAME: " . DB_NAME . "\n";
    echo "  DB_USER: " . DB_USER . "\n";
}
?>
</pre>
</body>
</html>
