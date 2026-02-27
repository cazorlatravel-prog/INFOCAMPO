<?php
/**
 * INFOCAMPO SaaS - Sistema de Autenticación
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Iniciar sesión si no está activa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Intentar login con email y password.
 * Devuelve los datos del usuario o false.
 */
function login(string $email, string $password): array|false
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT u.*, e.nombre AS empresa_nombre, e.activa AS empresa_activa
         FROM usuarios u
         INNER JOIN empresas e ON u.empresa_id = e.id
         WHERE u.email = :email AND u.activo = 1
         LIMIT 1"
    );
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }

    // Guardar en sesión
    $_SESSION['user_id']    = (int) $user['id'];
    $_SESSION['user_name']  = $user['nombre'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_rol']   = $user['rol'];
    $_SESSION['empresa_id'] = (int) $user['empresa_id'];
    $_SESSION['empresa_nombre'] = $user['empresa_nombre'];

    // Actualizar último login
    $pdo->prepare("UPDATE usuarios SET ultimo_login = NOW() WHERE id = :id")
        ->execute([':id' => $user['id']]);

    return $user;
}

/**
 * Cerrar sesión
 */
function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Verificar si hay sesión activa
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

/**
 * Obtener datos del usuario en sesión
 */
function currentUser(): ?array
{
    if (!isLoggedIn()) return null;
    return [
        'id'        => $_SESSION['user_id'],
        'nombre'    => $_SESSION['user_name'],
        'email'     => $_SESSION['user_email'],
        'rol'       => $_SESSION['user_rol'],
        'empresa_id'=> $_SESSION['empresa_id'],
        'empresa_nombre' => $_SESSION['empresa_nombre'],
    ];
}

/**
 * Exigir autenticación. Redirige al login si no hay sesión.
 */
function requireAuth(string $loginUrl = '/superadmin/login.php'): array
{
    if (!isLoggedIn()) {
        header('Location: ' . $loginUrl);
        exit;
    }
    return currentUser();
}

/**
 * Exigir un rol específico. Redirige si no cumple.
 */
function requireRole(string|array $roles, string $loginUrl = '/superadmin/login.php'): array
{
    $user = requireAuth($loginUrl);
    if (is_string($roles)) $roles = [$roles];

    if (!in_array($user['rol'], $roles, true)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><body><h1>403 - Acceso denegado</h1><p>No tienes permisos para acceder a esta sección.</p></body></html>';
        exit;
    }
    return $user;
}

/**
 * Generar hash de password
 */
function hashPassword(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Generar token CSRF
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validar token CSRF
 */
function validateCsrf(): bool
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals(csrfToken(), $token);
}

/**
 * Campo hidden de CSRF para formularios
 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

/**
 * Iniciar suplantación de usuario (impersonation).
 * Solo disponible para superadmin.
 */
function startImpersonation(int $userId): bool
{
    if (($_SESSION['user_rol'] ?? '') !== 'superadmin') {
        return false;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT u.*, e.nombre AS empresa_nombre
         FROM usuarios u
         INNER JOIN empresas e ON u.empresa_id = e.id
         WHERE u.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $userId]);
    $target = $stmt->fetch();

    if (!$target) {
        return false;
    }

    // Guardar sesión original del superadmin
    $_SESSION['impersonating_from'] = [
        'user_id'        => $_SESSION['user_id'],
        'user_name'      => $_SESSION['user_name'],
        'user_email'     => $_SESSION['user_email'],
        'user_rol'       => $_SESSION['user_rol'],
        'empresa_id'     => $_SESSION['empresa_id'],
        'empresa_nombre' => $_SESSION['empresa_nombre'],
    ];

    // Cambiar a la sesión del usuario objetivo
    $_SESSION['user_id']        = (int) $target['id'];
    $_SESSION['user_name']      = $target['nombre'];
    $_SESSION['user_email']     = $target['email'];
    $_SESSION['user_rol']       = $target['rol'];
    $_SESSION['empresa_id']     = (int) $target['empresa_id'];
    $_SESSION['empresa_nombre'] = $target['empresa_nombre'];

    return true;
}

/**
 * Detener suplantación y volver al superadmin.
 */
function stopImpersonation(): bool
{
    if (!isImpersonating()) {
        return false;
    }

    $original = $_SESSION['impersonating_from'];
    $_SESSION['user_id']        = $original['user_id'];
    $_SESSION['user_name']      = $original['user_name'];
    $_SESSION['user_email']     = $original['user_email'];
    $_SESSION['user_rol']       = $original['user_rol'];
    $_SESSION['empresa_id']     = $original['empresa_id'];
    $_SESSION['empresa_nombre'] = $original['empresa_nombre'];

    unset($_SESSION['impersonating_from']);
    return true;
}

/**
 * Verificar si estamos en modo suplantación.
 */
function isImpersonating(): bool
{
    return isset($_SESSION['impersonating_from']);
}
