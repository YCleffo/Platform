<?php

declare(strict_types=1);

// Worktrees can reuse an installed vendor tree while testing their own sources.
$vendor = getenv('PLATFORM_TEST_VENDOR_AUTOLOAD') ?: dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($vendor)) {
    throw new RuntimeException('Set PLATFORM_TEST_VENDOR_AUTOLOAD to the installed Composer autoloader.');
}
/** @var Composer\Autoload\ClassLoader $loader */
$loader = require $vendor;
$classroomSource = dirname(__DIR__) . '/src';
$loader->addPsr4('App\\', $classroomSource, true);
// Composer's optimized App classmap may point at the original checkout.
// The explicit prepend loader makes reused vendors honor this worktree too.
spl_autoload_register(static function (string $class) use ($classroomSource): void {
    if (!str_starts_with($class, 'App\\')) return;
    $file = $classroomSource . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
}, prepend: true);

