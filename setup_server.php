<?php
/**
 * INFOCAMPO - Configuración automática del servidor
 *
 * INSTRUCCIONES:
 * 1. Sube este archivo a la raíz de tu hosting (junto a composer.json)
 * 2. Abre en el navegador: https://tu-dominio.com/setup_server.php
 * 3. Espera a que termine
 * 4. ELIMINA este archivo después de usarlo
 */

set_time_limit(300);
ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INFOCAMPO - Configuración del servidor</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #1a1a2e; color: #eee; padding: 2rem; max-width: 800px; margin: 0 auto; }
        h1 { color: #4fc3f7; }
        .step { background: #16213e; padding: 1rem 1.5rem; border-radius: 10px; margin: 1rem 0; }
        .ok { color: #4caf50; font-weight: bold; }
        .error { color: #f44336; font-weight: bold; }
        .warn { color: #ff9800; font-weight: bold; }
        .info { color: #4fc3f7; }
        code { background: #0d1117; padding: 2px 6px; border-radius: 4px; }
        .delete-warning { background: #f44336; color: #fff; padding: 1rem; border-radius: 10px; margin-top: 2rem; text-align: center; font-size: 1.2rem; }
    </style>
</head>
<body>
<h1>INFOCAMPO - Configuración automática</h1>

<?php
$baseDir = __DIR__;
$steps = [];

// ============================================================
// PASO 1: Instalar Composer si no existe
// ============================================================
echo '<div class="step">';
echo '<h3>Paso 1: Verificar Composer</h3>';

$composerPhar = $baseDir . '/composer.phar';
$composerAvailable = false;

// Verificar si composer está en el PATH
$composerCmd = null;
exec('which composer 2>/dev/null', $output, $retval);
if ($retval === 0 && !empty($output[0])) {
    $composerCmd = 'composer';
    $composerAvailable = true;
    echo '<p class="ok">✓ Composer encontrado en el sistema</p>';
} elseif (file_exists($composerPhar)) {
    $composerCmd = 'php ' . escapeshellarg($composerPhar);
    $composerAvailable = true;
    echo '<p class="ok">✓ composer.phar encontrado</p>';
} else {
    echo '<p class="info">→ Descargando Composer...</p>';

    // Descargar el instalador de Composer
    $installerUrl = 'https://getcomposer.org/installer';
    $installerFile = $baseDir . '/composer-setup.php';

    $ctx = stream_context_create(['http' => ['timeout' => 60]]);
    $installer = @file_get_contents($installerUrl, false, $ctx);

    if ($installer === false) {
        // Intentar con curl
        if (function_exists('curl_init')) {
            $ch = curl_init($installerUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $installer = curl_exec($ch);
            curl_close($ch);
        }
    }

    if ($installer !== false && strlen($installer) > 100) {
        file_put_contents($installerFile, $installer);

        // Ejecutar el instalador
        $installOutput = [];
        exec('cd ' . escapeshellarg($baseDir) . ' && php composer-setup.php 2>&1', $installOutput, $installRet);

        // Limpiar instalador
        @unlink($installerFile);

        if (file_exists($composerPhar)) {
            $composerCmd = 'php ' . escapeshellarg($composerPhar);
            $composerAvailable = true;
            echo '<p class="ok">✓ Composer instalado correctamente</p>';
        } else {
            echo '<p class="error">✗ No se pudo instalar Composer</p>';
            echo '<pre>' . implode("\n", $installOutput) . '</pre>';
        }
    } else {
        echo '<p class="error">✗ No se pudo descargar Composer</p>';
    }
}
echo '</div>';

// ============================================================
// PASO 2: Instalar dependencias (composer install)
// ============================================================
echo '<div class="step">';
echo '<h3>Paso 2: Instalar dependencias PHP</h3>';

$vendorExists = file_exists($baseDir . '/vendor/autoload.php');

if ($vendorExists) {
    echo '<p class="ok">✓ Las dependencias ya están instaladas (vendor/ existe)</p>';
} elseif ($composerAvailable) {
    echo '<p class="info">→ Ejecutando composer install... (puede tardar 1-2 minutos)</p>';
    flush();

    // Fijar HOME y COMPOSER_HOME para hosting compartido
    $homeDir = getenv('HOME') ?: (getenv('USERPROFILE') ?: $baseDir);
    $composerHome = $baseDir . '/.composer_cache';
    if (!is_dir($composerHome)) {
        @mkdir($composerHome, 0755, true);
    }

    $installOutput = [];
    $envVars = 'HOME=' . escapeshellarg($homeDir) . ' COMPOSER_HOME=' . escapeshellarg($composerHome);
    $cmd = 'cd ' . escapeshellarg($baseDir) . ' && ' . $envVars . ' ' . $composerCmd . ' install --no-dev --optimize-autoloader 2>&1';
    exec($cmd, $installOutput, $installRet);

    if (file_exists($baseDir . '/vendor/autoload.php')) {
        echo '<p class="ok">✓ Dependencias instaladas correctamente</p>';
        $vendorExists = true;
    } else {
        echo '<p class="error">✗ Error al instalar dependencias</p>';
        echo '<pre>' . htmlspecialchars(implode("\n", $installOutput)) . '</pre>';
    }
} else {
    echo '<p class="error">✗ No se puede instalar: Composer no disponible</p>';
    echo '<p class="warn">Solución manual: sube la carpeta <code>vendor/</code> desde tu PC al hosting via Administrador de archivos</p>';
}
echo '</div>';

// ============================================================
// PASO 3: Verificar extensiones PHP necesarias
// ============================================================
echo '<div class="step">';
echo '<h3>Paso 3: Verificar extensiones PHP</h3>';

$extensions = [
    'pdo' => 'Base de datos',
    'pdo_mysql' => 'MySQL',
    'curl' => 'Cloudinary / descargas',
    'json' => 'API JSON',
    'fileinfo' => 'Detección de archivos',
    'zip' => 'Importar KMZ',
    'simplexml' => 'Importar KML',
    'mbstring' => 'Texto UTF-8',
    'gd' => 'Imágenes / watermark',
];

$allOk = true;
foreach ($extensions as $ext => $desc) {
    if (extension_loaded($ext)) {
        echo '<p class="ok">✓ ' . $ext . ' — ' . $desc . '</p>';
    } else {
        echo '<p class="error">✗ ' . $ext . ' — ' . $desc . ' (FALTA - actívala en Configuración PHP de Hostinger)</p>';
        $allOk = false;
    }
}
echo '</div>';

// ============================================================
// PASO 4: Verificar/crear .user.ini con límites de subida
// ============================================================
echo '<div class="step">';
echo '<h3>Paso 4: Configurar límites de subida</h3>';

$userIni = $baseDir . '/.user.ini';
$userIniContent = "; INFOCAMPO - Configuración PHP para subida de archivos
upload_max_filesize = 12M
post_max_size = 15M
max_execution_time = 120
memory_limit = 256M
";

$currentUpload = ini_get('upload_max_filesize');
$currentPost = ini_get('post_max_size');

echo '<p class="info">Límites actuales: upload_max_filesize=' . $currentUpload . ', post_max_size=' . $currentPost . '</p>';

// Convertir a bytes para comparar
function toBytes(string $val): int {
    $val = trim($val);
    $num = (int) $val;
    $unit = strtolower(substr($val, -1));
    return match($unit) {
        'g' => $num * 1024 * 1024 * 1024,
        'm' => $num * 1024 * 1024,
        'k' => $num * 1024,
        default => $num,
    };
}

$needsUpdate = toBytes($currentUpload) < toBytes('10M') || toBytes($currentPost) < toBytes('12M');

if ($needsUpdate) {
    if (file_put_contents($userIni, $userIniContent) !== false) {
        echo '<p class="ok">✓ Archivo .user.ini creado/actualizado con límites correctos</p>';
        echo '<p class="warn">⚠ Los cambios de .user.ini pueden tardar hasta 5 minutos en aplicarse</p>';
        echo '<p class="info">→ Para aplicar inmediato: ve a <strong>Configuración PHP</strong> en Hostinger y cambia upload_max_filesize a 12M y post_max_size a 15M</p>';
    } else {
        echo '<p class="error">✗ No se pudo crear .user.ini</p>';
        echo '<p class="warn">→ Cambia los límites manualmente en <strong>hPanel > Avanzado > Configuración PHP</strong></p>';
    }
} else {
    echo '<p class="ok">✓ Los límites de subida ya son suficientes</p>';
}
echo '</div>';

// ============================================================
// PASO 5: Verificar archivo .env
// ============================================================
echo '<div class="step">';
echo '<h3>Paso 5: Verificar configuración (.env)</h3>';

if (file_exists($baseDir . '/.env')) {
    echo '<p class="ok">✓ Archivo .env encontrado</p>';

    // Verificar que tiene las variables necesarias
    $envContent = file_get_contents($baseDir . '/.env');
    $required = ['DB_HOST', 'DB_NAME', 'DB_USER'];
    foreach ($required as $var) {
        if (strpos($envContent, $var) !== false) {
            echo '<p class="ok">  ✓ ' . $var . ' configurado</p>';
        } else {
            echo '<p class="error">  ✗ ' . $var . ' no encontrado en .env</p>';
        }
    }
} else {
    echo '<p class="error">✗ Archivo .env NO encontrado</p>';
    if (file_exists($baseDir . '/.env.example')) {
        echo '<p class="warn">→ Existe .env.example. Cópialo como .env y edítalo con tus datos de BD</p>';
    }
}
echo '</div>';

// ============================================================
// PASO 6: Verificar conexión a BD y migraciones
// ============================================================
echo '<div class="step">';
echo '<h3>Paso 6: Verificar base de datos</h3>';

try {
    require_once $baseDir . '/includes/config.php';
    $pdo = getDB();
    echo '<p class="ok">✓ Conexión a base de datos exitosa</p>';

    // Verificar si existen las tablas principales
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $requiredTables = ['empresas', 'usuarios', 'infraestructuras', 'registros'];
    foreach ($requiredTables as $table) {
        if (in_array($table, $tables)) {
            echo '<p class="ok">  ✓ Tabla ' . $table . '</p>';
        } else {
            echo '<p class="error">  ✗ Tabla ' . $table . ' NO existe — ejecuta las migraciones</p>';
        }
    }

    // Verificar columna 'monte' en infraestructuras
    if (in_array('infraestructuras', $tables)) {
        $cols = $pdo->query("SHOW COLUMNS FROM infraestructuras")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('monte', $cols)) {
            echo '<p class="ok">  ✓ Columna "monte" existe en infraestructuras</p>';
        } else {
            echo '<p class="warn">  ⚠ Columna "monte" NO existe — ejecutando migración v17...</p>';
            try {
                $pdo->exec("ALTER TABLE infraestructuras ADD COLUMN IF NOT EXISTS monte VARCHAR(200) NULL AFTER municipio");
                $pdo->exec("ALTER TABLE infraestructuras ADD INDEX IF NOT EXISTS idx_infra_monte (empresa_id, monte)");
                echo '<p class="ok">  ✓ Columna "monte" creada correctamente</p>';
            } catch (Exception $e) {
                echo '<p class="error">  ✗ Error al crear columna monte: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
        }
    }

} catch (Exception $e) {
    echo '<p class="error">✗ Error de conexión: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p class="warn">→ Verifica los datos en el archivo .env</p>';
}
echo '</div>';

// ============================================================
// PASO 7: Verificar que PhpSpreadsheet funciona
// ============================================================
echo '<div class="step">';
echo '<h3>Paso 7: Verificar importación Excel (PhpSpreadsheet)</h3>';

if ($vendorExists) {
    require_once $baseDir . '/vendor/autoload.php';
    if (class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
        echo '<p class="ok">✓ PhpSpreadsheet disponible y funcionando</p>';
    } else {
        echo '<p class="error">✗ PhpSpreadsheet no se cargó correctamente</p>';
    }
} else {
    echo '<p class="error">✗ No se puede verificar — vendor/ no instalado</p>';
}
echo '</div>';

// ============================================================
// RESUMEN
// ============================================================
echo '<div class="step" style="border: 2px solid #4fc3f7;">';
echo '<h3>Resumen</h3>';

$issues = [];
if (!$vendorExists) $issues[] = 'Instalar dependencias (composer install)';
if (!$allOk) $issues[] = 'Activar extensiones PHP faltantes';
if ($needsUpdate) $issues[] = 'Cambiar límites de subida en Configuración PHP de Hostinger';
if (!file_exists($baseDir . '/.env')) $issues[] = 'Crear archivo .env con datos de BD';

if (empty($issues)) {
    echo '<p class="ok" style="font-size: 1.3rem;">✓ Todo está configurado correctamente. La importación de Excel y KML debería funcionar.</p>';
} else {
    echo '<p class="warn">⚠ Quedan cosas por resolver:</p><ul>';
    foreach ($issues as $issue) {
        echo '<li class="warn">' . $issue . '</li>';
    }
    echo '</ul>';
}
echo '</div>';

// Limpiar composer.phar si lo descargamos
// (dejarlo por si hace falta otra vez)
?>

<div class="delete-warning">
    ⚠ IMPORTANTE: Elimina este archivo (setup_server.php) cuando termines, por seguridad.
</div>

</body>
</html>
