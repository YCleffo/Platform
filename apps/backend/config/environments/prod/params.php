<?php

declare(strict_types=1);

return (new \App\Core\Privacy\Infrastructure\PrivacyDeploymentConfigLoader())
    ->load((string) getenv('PRIVACY_CONFIG_FILE'));
