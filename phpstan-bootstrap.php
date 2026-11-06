<?php

// Bootstrap do PHPStan: carrega constantes e aliases do projeto sem conectar ao banco.
require_once __DIR__ . '/vendor/autoload.php';

if (getenv('VISITORPASS_DB_PASS') === false || getenv('VISITORPASS_DB_PASS') === '') {
    putenv('VISITORPASS_DB_PASS=phpstan');
}

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
