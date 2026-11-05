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

// Syntax alone accepts dangling $ref values; check all local JSON pointers as well.
foreach (glob(__DIR__ . '/../../contracts/openapi/*.yaml') ?: [] as $file) {
    $document = Yaml::parseFile($file);
    if (!is_array($document) || !isset($document['openapi'], $document['paths'])) {
        throw new RuntimeException('Invalid OpenAPI document: ' . basename($file));
    }
    $pending = [$document];
    while ($pending !== []) {
        $node = array_pop($pending);
        if (!is_array($node)) continue;
        $reference = $node['$ref'] ?? null;
        if (is_string($reference) && str_starts_with($reference, '#/')) {
            $target = $document;
            foreach (explode('/', substr($reference, 2)) as $part) {
                $part = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($part));
                if (!is_array($target) || !array_key_exists($part, $target)) {
                    throw new RuntimeException('Broken OpenAPI reference in ' . basename($file) . ': ' . $reference);
                }
                $target = $target[$part];
            }
        }
        foreach ($node as $child) if (is_array($child)) $pending[] = $child;
    }
    echo 'OpenAPI refs OK: ' . basename($file) . PHP_EOL;
}
