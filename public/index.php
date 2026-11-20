<?php

use App\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;

require_once __DIR__ . '/../config/app.php';
configureSessionSecurity();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../inc/security.php';
require_once __DIR__ . '/../inc/single_session.php';
require_once __DIR__ . '/../inc/log_event.php';
require_once __DIR__ . '/../inc/rate_limit.php';
require_once __DIR__ . '/../config/database.php';
header('Content-Type: text/html; charset=UTF-8');
ob_start();

$tempo_maximo = SESSION_TIMEOUT;
$page = $_GET['page'] ?? 'home';

$publicBasePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($publicBasePath === '/') {
    $publicBasePath = '';
}
$GLOBALS['APP_BASE_PATH'] = $publicBasePath;
$GLOBALS['APP_PUBLIC_PATH'] = $publicBasePath;

$isProduction = defined('ENVIRONMENT') && ENVIRONMENT === 'production';
$scriptEvalPolicy = $isProduction ? "" : " 'unsafe-eval'";
$GLOBALS['IS_PRODUCTION'] = $isProduction;
$cspNonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
$GLOBALS['CSP_NONCE'] = $cspNonce;

$cspPolicy = "default-src 'self'; "
    . "script-src 'self' 'nonce-{$cspNonce}'{$scriptEvalPolicy} https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://code.jquery.com https://cdn.datatables.net; "
    . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.datatables.net; "
    . "style-src-elem 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.datatables.net; "
    . "style-src-attr 'unsafe-inline'; "
    . "img-src 'self' data: blob:; "
    . "font-src 'self' data: https://cdnjs.cloudflare.com; "
    . "connect-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://code.jquery.com https://cdn.datatables.net; "
    . "frame-ancestors 'self'; "
    . "object-src 'none'; "
    . "base-uri 'self'; "
    . "form-action 'self'";
header('Content-Security-Policy: ' . $cspPolicy);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
header('X-Frame-Options: SAMEORIGIN');

// HSTS apenas em HTTPS (dev HTTP nao deve enviar para nao quebrar a navegacao local)
if (isHttpsRequest()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

$isApi = str_starts_with($page, 'api/');

function verificarTimeout($isApi = false, $tempo_maximo = SESSION_TIMEOUT)
{
    if (!isset($_SESSION['usuario'])) {
        return 0;
    }

    if (!isset($_SESSION['ultimo_acesso'])) {
        $_SESSION['ultimo_acesso'] = time();
        return $tempo_maximo;
    }

    $inatividade = time() - $_SESSION['ultimo_acesso'];
    $time_left = $tempo_maximo - $inatividade;

    if ($inatividade >= $tempo_maximo) {
        $usuarioId = currentUserId();
        $sessionToken = $_SESSION['session_token'] ?? null;
        if ($usuarioId) {
            try {
                $db = new database();
                invalidateUniqueSession($db, $usuarioId, $sessionToken);
            } catch (Throwable $e) {
                error_log('Erro ao invalidar sessao por timeout: ' . $e->getMessage());
            }
        }

        clearLocalSessionState();
        logEvent('SESSION_EXPIRED', ['reason' => 'inactivity']);

        if ($isApi) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
            echo json_encode([
                'status' => 'expired',
                'success' => false,
                'error' => 'Sessao expirada',
                'message' => 'Sessao encerrada por inatividade'
            ]);
            exit;
        }

        header('Location: index.php?page=login&timeout=1');
        exit;
    }

    $_SESSION['ultimo_acesso'] = time();

    return max(0, $time_left);
}

function enforceUniqueSession(bool $isApi = false): void
{
    if (!isset($_SESSION['usuario'])) {
        return;
    }

    $usuarioId = currentUserId();
    $sessionToken = $_SESSION['session_token'] ?? null;
    if (!$usuarioId || !$sessionToken) {
        clearLocalSessionState();
        if ($isApi) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => false,
                'status' => 'expired',
                'reason' => 'concurrent_login',
                'message' => 'Sessao invalida. Realize login novamente.'
            ]);
            exit;
        }

        header('Location: index.php?page=login&concurrent=1');
        exit;
    }

    try {
        $db = new database();
        $valid = isUniqueSessionValid($db, $usuarioId, $sessionToken);
    } catch (Throwable $e) {
        error_log('Erro ao validar sessao unica: ' . $e->getMessage());
        $valid = false;
    }

    if ($valid) {
        return;
    }

    clearLocalSessionState();
    logEvent('SESSION_REVOKED', ['reason' => 'concurrent_login']);

    if ($isApi) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'status' => 'expired',
            'reason' => 'concurrent_login',
            'message' => 'Sua conta foi acessada em outro dispositivo.'
        ]);
        exit;
    }

    header('Location: index.php?page=login&concurrent=1');
    exit;
}

if ($page === 'logout') {
    require_once __DIR__ . '/../scripts/logout.php';
    exit;
}

$time_left = verificarTimeout($isApi, $tempo_maximo);
enforceUniqueSession($isApi);
getCsrfToken();

require_once __DIR__ . '/../config/config.php';

$router = new Router();
$router->addGlobalMiddleware(new CsrfMiddleware());

// Rotas publicas (sem auth)
$router->any('login', function () {
    require_once __DIR__ . '/../inc/header.php';
    require_once __DIR__ . '/../scripts/login.php';
    $GLOBALS['time_left'] = 0;
    $GLOBALS['tempo_maximo'] = SESSION_TIMEOUT;
    require_once __DIR__ . '/../inc/footer.php';
});

$router->any('login_submit', function () {
    require_once __DIR__ . '/../scripts/login_submit.php';
    exit;
});

$router->any('404', function () {
    require_once __DIR__ . '/../inc/header.php';
    require_once __DIR__ . '/../scripts/404.php';
    $GLOBALS['time_left'] = 0;
    $GLOBALS['tempo_maximo'] = SESSION_TIMEOUT;
    require_once __DIR__ . '/../inc/footer.php';
});

$router->any('nao_autorizado', function () {
    require_once __DIR__ . '/../inc/header.php';
    require_once __DIR__ . '/../scripts/nao_autorizado.php';
    $GLOBALS['time_left'] = 0;
    $GLOBALS['tempo_maximo'] = SESSION_TIMEOUT;
    require_once __DIR__ . '/../inc/footer.php';
});

// Rotas com auth user
$userRoutes = ['home', 'trocar_senha', 'cadastro_visitantes', 'listar_visitantes'];
foreach ($userRoutes as $route) {
    $router->any($route, function () use ($route) {
        require_once __DIR__ . '/../inc/header.php';
        require_once __DIR__ . '/../scripts/' . $route . '.php';
        $GLOBALS['time_left'] = $GLOBALS['time_left'] ?? 0;
        $GLOBALS['tempo_maximo'] = SESSION_TIMEOUT;
        require_once __DIR__ . '/../inc/footer.php';
    }, [new AuthMiddleware('user')]);
}

// Rota relatorios (supervisor)
$router->any('relatorios_visitantes', function () {
    require_once __DIR__ . '/../inc/header.php';
    require_once __DIR__ . '/../scripts/relatorios_visitantes.php';
    $GLOBALS['time_left'] = $GLOBALS['time_left'] ?? 0;
    $GLOBALS['tempo_maximo'] = SESSION_TIMEOUT;
    require_once __DIR__ . '/../inc/footer.php';
}, [new AuthMiddleware('supervisor')]);

// Rotas admin
$adminRoutes = ['administracao', 'cadastroUsuario', 'listarUsuario', 'editarUsuario',
    'deletarUsuario', 'bloquearUsuario', 'ativarUsuario', 'admin_auditoria',
    'admin_login_locks', 'desbloquear_login'];
foreach ($adminRoutes as $route) {
    $router->any($route, function () use ($route) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                $db = new database();
                enforceRateLimit($db, 'admin_' . $route, 30, 60);
            } catch (Throwable $e) {
                error_log('Erro no rate limit admin: ' . $e->getMessage());
            }
        }
        require_once __DIR__ . '/../inc/header.php';
        require_once __DIR__ . '/../scripts/' . $route . '.php';
        $GLOBALS['time_left'] = $GLOBALS['time_left'] ?? 0;
        $GLOBALS['tempo_maximo'] = SESSION_TIMEOUT;
        require_once __DIR__ . '/../inc/footer.php';
    }, [new AuthMiddleware('admin')]);
}

// Rotas de API
$apiRoutes = ['api_cadastro_visitante', 'api_detalhes', 'api_estatisticas',
    'api_export', 'api_relatorios', 'exportar_auditoria', 'get_active_visitors',
    'get_auditoria', 'get_auditoria_detalhe', 'get_photo', 'reactivate_visitor',
    'renovar_sessao', 'session_check', 'signout'];

foreach ($apiRoutes as $apiRoute) {
    $router->any('api/' . $apiRoute, function () use ($apiRoute) {
        $path = __DIR__ . '/../api/' . $apiRoute . '.php';
        if (!file_exists($path)) {
            http_response_code(404);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'API endpoint not found']);
            exit;
        }
        require_once $path;
        exit;
    }, [new AuthMiddleware('user')]);
}

// Body class para background
$bodyBgZoom = ['listar_visitantes'];
$bodyBgShadow = ['cadastro_visitantes', 'listarUsuario', 'editarUsuario'];
if ($page === 'home') {
    // Fundo claro: a imagem institucional e escura e precisa de contraste.
    $GLOBALS['body_class'] = 'page-home';
} elseif (in_array($page, $bodyBgZoom, true)) {
    $GLOBALS['body_class'] = 'page-bg';
} elseif (in_array($page, $bodyBgShadow, true)) {
    $GLOBALS['body_class'] = 'page-bg--shadow';
} else {
    $GLOBALS['body_class'] = '';
}

// Executar roteador
$router->dispatch();
