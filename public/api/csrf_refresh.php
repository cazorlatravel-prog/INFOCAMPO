<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

echo json_encode(['ok' => true, 'token' => $_SESSION['csrf_token']]);
