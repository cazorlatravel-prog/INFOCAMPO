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
    // Intentar encontrar un usuario del rol solicitado
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

    // Si no hay usuario del rol solicitado, intentar con cualquier usuario activo de la empresa
    if ($userId === 0) {
        $stmt = $pdo->prepare(
            "SELECT id FROM usuarios
             WHERE empresa_id = :emp_id AND activo = 1
             ORDER BY FIELD(rol, 'admin', 'supervisor', 'operador'), id ASC
             LIMIT 1"
        );
        $stmt->execute([':emp_id' => $empresaId]);
        $found = $stmt->fetch();
        if ($found) {
            $userId = (int) $found['id'];
        }
    }
}

// Si no se encontró ningún usuario, acceder directamente como admin virtual de la empresa
if ($userId <= 0 && $empresaId > 0) {
    // Verificar que la empresa existe
    $stmt = $pdo->prepare("SELECT id, nombre FROM empresas WHERE id = :id");
    $stmt->execute([':id' => $empresaId]);
    $empresa = $stmt->fetch();

    if (!$empresa) {
        $_SESSION['flash_msg'] = 'Empresa no encontrada.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /superadmin/empresas.php');
        exit;
    }

    // Crear sesión virtual de admin para esta empresa (sin usuario real)
    $_SESSION['impersonating_from'] = [
        'user_id'        => $_SESSION['user_id'],
        'user_name'      => $_SESSION['user_name'],
        'user_email'     => $_SESSION['user_email'],
        'user_rol'       => $_SESSION['user_rol'],
        'empresa_id'     => $_SESSION['empresa_id'],
        'empresa_nombre' => $_SESSION['empresa_nombre'],
    ];

    $_SESSION['user_id']        = 0;
    $_SESSION['user_name']      = 'SuperAdmin';
    $_SESSION['user_email']     = $_SESSION['impersonating_from']['user_email'];
    $_SESSION['user_rol']       = 'admin';
    $_SESSION['empresa_id']     = (int) $empresa['id'];
    $_SESSION['empresa_nombre'] = $empresa['nombre'];

    header('Location: /admin/dashboard.php');
    exit;
}

if ($userId <= 0) {
    $_SESSION['flash_msg'] = 'No se encontró un usuario ni empresa válida para acceder.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /superadmin/empresas.php');
    exit;
}

if (startImpersonation($userId)) {
    header('Location: /admin/dashboard.php');
} else {
    $_SESSION['flash_msg'] = 'Error al suplantar usuario.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /superadmin/empresas.php');
}
exit;
