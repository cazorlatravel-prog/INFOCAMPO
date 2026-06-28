<?php
/**
 * FotoGPS - Refresco de token CSRF / keep-alive de sesión
 *
 * El operador puede tener la app abierta durante horas. Esta llamada
 * periódica (cada ~15 min desde operador.js):
 *   1. Mantiene viva la sesión PHP (evita expiración a mitad de jornada)
 *   2. Devuelve el token CSRF vigente para que el cliente siempre tenga uno válido
 *
 * IMPORTANTE: NO se regenera el token. Rotarlo invalidaría las fotos
 * encoladas offline (que guardan el token en el momento de la captura y se
 * suben más tarde). Devolvemos siempre el token actual de la sesión.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

// csrfToken() devuelve el token existente o crea uno si no hay (no rota)
echo json_encode(['ok' => true, 'token' => csrfToken()]);
