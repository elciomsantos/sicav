<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';

if (!class_exists('database', false)) {
    class_alias('App\Database', 'database');
}
