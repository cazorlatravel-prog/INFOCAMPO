<?php
/**
 * Diagnóstico y reparación del superadmin.
 * Acceder desde el navegador: https://fotogps.app/database/fix_superadmin.php
 * BORRAR DESPUÉS DE USAR.
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: text/html; charset=utf-8');
echo '<h2>Diagnóstico SuperAdmin - FotoGPS.app</h2>';
echo '<pre>';

try {
    $pdo = getDB();
    echo "[OK] Conexión a base de datos exitosa.\n\n";

    // 1. Verificar empresa 9999
    $stmt = $pdo->query("SELECT id, nombre, activa FROM empresas WHERE id = 9999");
    $emp = $stmt->fetch();
    if ($emp) {
        echo "[OK] Empresa 9999 existe: '{$emp['nombre']}', activa={$emp['activa']}\n";
        if (!$emp['activa']) {
            $pdo->exec("UPDATE empresas SET activa = 1 WHERE id = 9999");
            echo "[FIX] Empresa 9999 estaba INACTIVA -> activada.\n";
        }
    } else {
        echo "[ERROR] Empresa 9999 NO existe. Creándola...\n";
        $pdo->exec(
            "INSERT INTO empresas (id, nombre, plan_suscripcion, email_contacto, activa, licencia_inicio, licencia_fin, max_usuarios, max_infraestructuras)
             VALUES (9999, 'INFOCAMPO Platform', 'enterprise', 'admin@infocampo.app', 1, '2024-01-01', '2099-12-31', 999, 9999)"
        );
        echo "[FIX] Empresa 9999 creada.\n";
    }

    echo "\n";

    // 2. Verificar usuario superadmin
    $stmt = $pdo->query("SELECT id, nombre, email, rol, activo, empresa_id, password FROM usuarios WHERE email = 'superadmin@infocampo.app'");
    $user = $stmt->fetch();
    if ($user) {
        echo "[OK] Usuario superadmin existe: id={$user['id']}, rol={$user['rol']}, activo={$user['activo']}, empresa_id={$user['empresa_id']}\n";

        // Verificar hash
        if (password_verify('InfoCampo2024!', $user['password'])) {
            echo "[OK] La contraseña 'InfoCampo2024!' es CORRECTA.\n";
        } else {
            echo "[ERROR] La contraseña 'InfoCampo2024!' NO coincide con el hash guardado. Reseteando...\n";
            $newHash = password_hash('InfoCampo2024!', PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare("UPDATE usuarios SET password = :hash WHERE email = 'superadmin@infocampo.app'")
                ->execute([':hash' => $newHash]);
            echo "[FIX] Contraseña reseteada a 'InfoCampo2024!'.\n";
        }

        // Verificar rol
        if ($user['rol'] !== 'superadmin') {
            echo "[ERROR] El rol es '{$user['rol']}' en vez de 'superadmin'. Corrigiendo...\n";
            $pdo->exec("UPDATE usuarios SET rol = 'superadmin' WHERE email = 'superadmin@infocampo.app'");
            echo "[FIX] Rol actualizado a 'superadmin'.\n";
        }

        // Verificar activo
        if (!$user['activo']) {
            echo "[ERROR] El usuario está INACTIVO. Activando...\n";
            $pdo->exec("UPDATE usuarios SET activo = 1 WHERE email = 'superadmin@infocampo.app'");
            echo "[FIX] Usuario activado.\n";
        }

        // Verificar empresa_id
        if ((int) $user['empresa_id'] !== 9999) {
            echo "[ERROR] empresa_id es {$user['empresa_id']} en vez de 9999. Corrigiendo...\n";
            $pdo->exec("UPDATE usuarios SET empresa_id = 9999 WHERE email = 'superadmin@infocampo.app'");
            echo "[FIX] empresa_id actualizado a 9999.\n";
        }
    } else {
        echo "[ERROR] Usuario superadmin NO existe. Creándolo...\n";
        $hash = password_hash('InfoCampo2024!', PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare(
            "INSERT INTO usuarios (empresa_id, nombre, email, password, rol, activo)
             VALUES (9999, 'Super Administrador', 'superadmin@infocampo.app', :hash, 'superadmin', 1)"
        );
        $stmt->execute([':hash' => $hash]);
        echo "[FIX] Usuario superadmin creado.\n";
    }

    echo "\n";

    // 3. Verificar login
    echo "--- Verificación final ---\n";
    $stmt = $pdo->prepare(
        "SELECT u.*, e.nombre AS empresa_nombre, e.activa AS empresa_activa
         FROM usuarios u
         INNER JOIN empresas e ON u.empresa_id = e.id
         WHERE u.email = 'superadmin@infocampo.app' AND u.activo = 1
         LIMIT 1"
    );
    $stmt->execute();
    $test = $stmt->fetch();
    if ($test && password_verify('InfoCampo2024!', $test['password'])) {
        echo "[OK] Login funcionará correctamente.\n";
        echo "     Email: superadmin@infocampo.app\n";
        echo "     Password: InfoCampo2024!\n";
        echo "     Rol: {$test['rol']}\n";
        echo "     Empresa: {$test['empresa_nombre']} (activa={$test['empresa_activa']})\n";
    } else {
        echo "[ERROR] El login seguirá fallando. Revisa manualmente la BD.\n";
        if (!$test) {
            echo "  -> La query INNER JOIN no devuelve resultados.\n";
            echo "  -> Posiblemente la tabla 'empresas' o 'usuarios' tiene problemas.\n";
        }
    }

} catch (Exception $e) {
    echo "[ERROR FATAL] " . $e->getMessage() . "\n";
}

echo "\n\n*** BORRA ESTE ARCHIVO DEL SERVIDOR DESPUÉS DE USAR ***";
echo '</pre>';
