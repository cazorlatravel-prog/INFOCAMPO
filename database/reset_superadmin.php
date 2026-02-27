<?php
/**
 * Script temporal para resetear la contraseña del superadmin.
 * BORRAR DESPUÉS DE USAR.
 */
require_once __DIR__ . '/../includes/config.php';

$password = 'InfoCampo2024!';
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$pdo = getDB();
$stmt = $pdo->prepare("UPDATE usuarios SET password = :hash WHERE email = 'superadmin@infocampo.app'");
$stmt->execute([':hash' => $hash]);

if ($stmt->rowCount() > 0) {
    echo "Contraseña del superadmin actualizada correctamente.<br>";
    echo "Email: superadmin@infocampo.app<br>";
    echo "Password: InfoCampo2024!<br><br>";
    echo "<strong>BORRA ESTE ARCHIVO DEL SERVIDOR AHORA.</strong>";
} else {
    echo "ERROR: No se encontró el usuario superadmin@infocampo.app";
}
