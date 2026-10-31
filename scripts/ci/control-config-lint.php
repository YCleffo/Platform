<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require __DIR__ . '/../../apps/control-plane/vendor/autoload.php';

$configDir = __DIR__ . '/../../apps/control-plane/config';
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($configDir, FilesystemIterator::SKIP_DOTS));
$parsed = 0;

foreach ($files as $file) {
    if (!str_ends_with($file->getFilename(), '.yaml')) {
        continue;
    }

    Yaml::parseFile($file->getPathname(), Yaml::PARSE_CUSTOM_TAGS);
    $parsed++;
    echo 'YAML OK: ' . $file->getPathname() . PHP_EOL;
}

if ($parsed === 0) {
    fwrite(STDERR, "No YAML files found under {$configDir}.\n");
    exit(1);
}
