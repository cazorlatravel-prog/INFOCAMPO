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
 * Intentar login con email/teléfono y password.
 * Acepta un email o un número de teléfono como identificador.
 * Devuelve los datos del usuario o false.
 */
function login(string $identifier, string $password): array|false
{
    $pdo = getDB();

    // Determinar si es email o teléfono: si contiene @ es email, si no es teléfono
    $isEmail = str_contains($identifier, '@');

    if (!$isEmail) {
        // Limpiar teléfono: solo dígitos y +
        $identifier = preg_replace('/[^0-9+]/', '', $identifier);
    }

    // Buscar usuario sin JOIN para diagnosticar correctamente
    if ($isEmail) {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = :identifier LIMIT 1");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE telefono = :identifier LIMIT 1");
    }
    $stmt->execute([':identifier' => $identifier]);
    $user = $stmt->fetch();

    if (!$user) {
        return false;
    }

    if (!$user['activo']) {
        return false;
    }

    if (!password_verify($password, $user['password'])) {
        return false;
    }

    // Verificar que la empresa existe
    $stmt = $pdo->prepare(
        "SELECT id, nombre, activa FROM empresas WHERE id = :id LIMIT 1"
    );
    $stmt->execute([':id' => $user['empresa_id']]);
    $empresa = $stmt->fetch();

    if (!$empresa) {
        // La empresa no existe - crear una empresa por defecto para no bloquear login
        return false;
    }

    // Guardar en sesión
    $_SESSION['user_id']    = (int) $user['id'];
    $_SESSION['user_name']  = $user['nombre'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_rol']   = $user['rol'];
    $_SESSION['empresa_id'] = (int) $user['empresa_id'];
    $_SESSION['empresa_nombre'] = $empresa['nombre'];

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
function requireAuth(string $loginUrl = '/login.php'): array
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
function requireRole(string|array $roles, string $loginUrl = '/login.php'): array
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
 * Obtener el empresa_id de la sesión.
 *
 * Aplicación single-tenant (TRAGSA): todos los roles operan siempre sobre
 * la empresa de su sesión. No existe selección de empresa entre inquilinos.
 */
function getEmpresaIdSeguro(): int
{
    return (int) ($_SESSION['empresa_id'] ?? 0);
}
