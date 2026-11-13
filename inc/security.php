<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function validateCsrfToken(?string $token): bool
{
    if (!$token || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

function currentUserId(): ?int
{
    if (!isset($_SESSION['usuario']) || !isset($_SESSION['usuario']->id)) {
        return null;
    }

    return (int) $_SESSION['usuario']->id;
}

function getClientIp(): string
{
    // O proxy nginx e o unico cliente do container app. O Apache
    // (mod_remoteip, conf em /etc/visitantes/remoteip/remoteip.conf, gerado por
    // docker/entrypoint.sh) ja reescreveu REMOTE_ADDR com o IP real do cliente,
    // aceitando X-Forwarded-For apenas do proxy confiavel. Headers de proxy NAO
    // sao lidos aqui: assim um cliente que alcance o container diretamente nao
    // consegue forjar o IP usado no rate limiting e na auditoria.
    return trim($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function userHasAccess(string $required = 'user'): bool
{
    if (!isset($_SESSION['usuario'])) {
        return false;
    }

    $levels = [
        'user' => 1,
        'supervisor' => 2,
        'admin' => 3,
    ];

    $userLevel = $_SESSION['usuario']->nivel_acesso ?? 'user';
    return ($levels[$userLevel] ?? 0) >= ($levels[$required] ?? 1);
}

function resolveApiEndpoint(string $page, ?string $scriptName): string
{
    if ($page !== '') {
        return str_starts_with($page, 'api/') ? substr($page, 4) : $page;
    }

    return basename((string) $scriptName, '.php');
}
