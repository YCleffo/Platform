<?php

declare(strict_types=1);

if (getenv('APP_ENV') === false && !isset($_ENV['APP_ENV'])) {
    putenv('APP_ENV=test');
    $_ENV['APP_ENV'] = 'test';
}

require_once dirname(__DIR__) . '/src/bootstrap.php';
