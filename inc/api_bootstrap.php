<?php

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/single_session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/log_event.php';

function logApiException(Throwable $e, array $context = []): void
{
    $payload = array_merge([
        'type' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'endpoint' => $_SERVER['REQUEST_URI'] ?? '',
        'user_id' => currentUserId(),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'request_id' => getRequestId(),
    ], $context);

    error_log('API_ERROR ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Fronteira de saida JSON da API: Content-Type application/json
 * (+ X-Content-Type-Options: nosniff), o browser nunca a interpreta
 * como HTML. JSON_HEX_TAG/JSON_HEX_AMP hex-escapam <, > e & nos valores
 * (o Psalm remove o taint html nativamente com essas flags); as aspas
 * que delimitam strings JSON nao sao sink de atributo HTML, entao o
 * rastreamento de taint e desligado apenas nesta funcao de saida.
 *
 * @psalm-suppress TaintedInput
 */
function apiJsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    // Impede o navegador de interpretar a resposta como HTML (anti-sniffing)
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

function apiSuccess(mixed $data = null, string $message = '', int $status = 200): never
{
    $payload = ['success' => true];
    if ($data !== null) {
        $payload['data'] = $data;
    }
    if ($message !== '') {
        $payload['message'] = $message;
    }
    apiJsonResponse($payload, $status);
}

function apiError(string $error, int $status = 400): never
{
    apiJsonResponse(['success' => false, 'error' => $error], $status);
}

function apiGetJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function apiRequireEndpointAccess(): void
{
    $map = require __DIR__ . '/../config/permissions.php';

    // Sob o roteador (index.php?page=api/<endpoint>), SCRIPT_NAME e sempre
    // index.php; o endpoint vem do parametro 'page'.
    $endpoint = resolveApiEndpoint($_GET['page'] ?? '', $_SERVER['SCRIPT_NAME'] ?? '');

    $requiredAccess = $map[$endpoint] ?? 'user';
    apiRequireAuth($requiredAccess);
}

function apiRequireAuth(string $requiredAccess = 'user'): void
{
    if (!isset($_SESSION['usuario'])) {
        apiJsonResponse([
            'success' => false,
            'status' => 'expired',
            'error' => 'Sessao nao encontrada',
        ], 401);
    }

    $usuarioId = currentUserId();
    $sessionToken = $_SESSION['session_token'] ?? null;
    if (!$usuarioId || !$sessionToken) {
        clearLocalSessionState();
        apiJsonResponse([
            'success' => false,
            'status' => 'expired',
            'reason' => 'concurrent_login',
            'error' => 'Sessao invalida. Realize login novamente.',
        ], 401);
    }

    try {
        $db = new database();
        $valid = isUniqueSessionValid($db, $usuarioId, $sessionToken);
    } catch (Throwable $e) {
        error_log('Erro ao validar sessao unica na API: ' . $e->getMessage());
        $valid = false;
    }

    if (!$valid) {
        clearLocalSessionState();
        apiJsonResponse([
            'success' => false,
            'status' => 'expired',
            'reason' => 'concurrent_login',
            'error' => 'Sua conta foi acessada em outro dispositivo.',
        ], 401);
    }

    if (!userHasAccess($requiredAccess)) {
        logEvent('AUTHORIZATION_DENIED', [
            'endpoint' => $_SERVER['SCRIPT_NAME'] ?? '',
            'required' => $requiredAccess,
            'granted' => $_SESSION['usuario']->nivel_acesso ?? 'user',
        ]);
        apiError('Acesso negado', 403);
    }
}

function apiRequireCsrf(): void
{
    $json = apiGetJsonInput();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ($json['csrf_token'] ?? null));

    if (!validateCsrfToken($token)) {
        apiError('CSRF token invalido', 403);
    }
}
