<?php

declare(strict_types=1);

require '/app/vendor/autoload.php';

$docker = new \ControlPlane\Provisioning\DockerCommand();
$version = $docker->run(['version', '--format', '{{.Client.Version}}']);
if ($version === '' || $docker->inspect('image', 'platform-runtime:local') === null) {
    throw new RuntimeException('Structured Docker command failed.');
}
echo 'structured Docker command passed', PHP_EOL;
