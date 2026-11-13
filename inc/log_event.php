<?php

require_once __DIR__ . '/security.php';

// Normaliza o X-Request-ID recebido do proxy. Valor fora do padrao (ou vazio)
// gera um ID local: o header vem do cliente, entao nao pode ser confiado.
function normalizeRequestId(?string $fromProxy): string
{
    if ($fromProxy !== null && preg_match('/^[A-Za-z0-9._-]{8,128}$/', $fromProxy) === 1) {
        return $fromProxy;
    }

    return bin2hex(random_bytes(16));
}

function getRequestId(): string
{
    static $requestId = null;
    if ($requestId === null) {
        // O nginx gera um $request_id por requisicao e o encaminha em
        // X-Request-ID; reutilizar preserva a correlacao nginx -> PHP nos logs.
        $requestId = normalizeRequestId($_SERVER['HTTP_X_REQUEST_ID'] ?? null);
    }
    return $requestId;
}

function logEvent(string $event, array $context = []): void
{
    $userLevel = isset($_SESSION['usuario']) ? ($_SESSION['usuario']->nivel_acesso ?? null) : null;

    $payload = array_merge([
        'event' => $event,
        'request_id' => getRequestId(),
        'timestamp' => gmdate('c'),
        'user_id' => currentUserId(),
        'user_level' => $userLevel,
        // IP real do cliente: REMOTE_ADDR foi reescrito pelo mod_remoteip
        // (ver inc/security.php).
        'ip' => getClientIp(),
        'route' => $_SERVER['REQUEST_URI'] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    ], $context);

    error_log('AUDIT ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}