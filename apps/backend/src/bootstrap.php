<?php

declare(strict_types=1);

use App\Environment;

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (class_exists(\Dotenv\Dotenv::class)) {
    $backendRoot = dirname(__DIR__);
    $platformRoot = dirname($backendRoot, 2);

    // Backend-specific local settings.
    \Dotenv\Dotenv::createImmutable($backendRoot)->safeLoad();

    // Shared Platform infrastructure settings.
    \Dotenv\Dotenv::createImmutable($platformRoot)->safeLoad();
}

Environment::prepare();
