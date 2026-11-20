<?php

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, '"\'');
            if (!empty($key)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
}

if (!defined('DB_HOST')) {
    define('DB_HOST', appEnv('VISITORPASS_DB_HOST', 'localhost'));
}
if (!defined('DB_NAME')) {
    define('DB_NAME', appEnv('VISITORPASS_DB_NAME', 'visitorpass'));
}
if (!defined('DB_USER')) {
    define('DB_USER', appEnv('VISITORPASS_DB_USER', 'vpuser'));
}
if (!defined('DB_PASS')) {
    $pass = appEnv('VISITORPASS_DB_PASS', '');
    if (empty($pass)) {
        throw new RuntimeException('Variável de ambiente VISITORPASS_DB_PASS é obrigatória');
    }
    define('DB_PASS', $pass);
}

if (!defined('GLOBAL_IP_SCOPE')) {
    define('GLOBAL_IP_SCOPE', '__IP_GLOBAL__');
}

if (!defined('APP_VERSION')) {
    // Versao exibida no rodape. Pode ser definida via VISITORPASS_APP_VERSION
    // (compose/.env) para acompanhar a tag de release sem editar codigo.
    define('APP_VERSION', appEnv('VISITORPASS_APP_VERSION', 'v1.0.0'));
}
