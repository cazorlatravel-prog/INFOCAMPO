<?php
/**
 * INFOCAMPO SaaS - Suplantación de usuario (Impersonation)
 *
 * Permite al superadmin acceder como admin u operador de cualquier empresa.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

// ---------------------------------------------------------------
// Acción: detener suplantación
// ---------------------------------------------------------------
if (isset($_GET['stop'])) {
    stopImpersonation();
    header('Location: /superadmin/empresas.php');
    exit;
}

// ---------------------------------------------------------------
// Acción: iniciar suplantación (POST)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCsrf()) {
    header('Location: /superadmin/empresas.php');
    exit;
}

// Solo superadmin puede suplantar (o si ya está suplantando, volver primero)
if (isImpersonating()) {
    stopImpersonation();
}

if (($_SESSION['user_rol'] ?? '') !== 'superadmin') {
    http_response_code(403);
    echo 'Acceso denegado.';
    exit;
}

$userId = (int) ($_POST['user_id'] ?? 0);
$targetRole = $_POST['target_role'] ?? '';
$empresaId = (int) ($_POST['empresa_id'] ?? 0);

$pdo = getDB();

// Si se especifica un rol objetivo pero no un user_id, buscar un usuario de ese rol en la empresa
if ($userId === 0 && $empresaId > 0 && $targetRole !== '') {
    $stmt = $pdo->prepare(
        "SELECT id FROM usuarios
         WHERE empresa_id = :emp_id AND rol = :rol AND activo = 1
         ORDER BY id ASC
         LIMIT 1"
    );
    $stmt->execute([':emp_id' => $empresaId, ':rol' => $targetRole]);
    $found = $stmt->fetch();
    if ($found) {
        $userId = (int) $found['id'];
    }
}

if ($userId <= 0) {
    $_SESSION['flash_msg'] = 'No se encontró un usuario de rol "' . htmlspecialchars($targetRole) . '" en esa empresa.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /superadmin/empresas.php');
    exit;
}

if (startImpersonation($userId)) {
    // Redirigir según el rol del usuario suplantado
    $rol = $_SESSION['user_rol'];
    if ($rol === 'admin') {
        header('Location: /admin/index.php?empresa_id=' . $_SESSION['empresa_id']);
    } else {
        // Para operador/supervisor, también redirigir al panel admin para ver sus datos
        header('Location: /admin/index.php?empresa_id=' . $_SESSION['empresa_id']);
    }
} else {
    $_SESSION['flash_msg'] = 'Error al suplantar usuario.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /superadmin/empresas.php');
}
exit;
