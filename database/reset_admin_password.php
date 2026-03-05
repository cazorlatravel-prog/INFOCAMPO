<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';

$pdo = getDB();
$email = 'rapcajaen@gmail.com';
$password = 'Gallito9431';
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$stmt = $pdo->prepare("UPDATE usuarios SET password = :hash WHERE email = :email");
$stmt->execute([':hash' => $hash, ':email' => $email]);

if ($stmt->rowCount() > 0) {
    echo "OK - Contraseña actualizada correctamente para {$email}";
} else {
    echo "ERROR - No se encontró el usuario con email {$email}";
}
