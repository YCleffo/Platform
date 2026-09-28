<?php

declare(strict_types=1);

use App\Core\Consent\Application\ConsentDefinition;

return [
    'consentDefinitions' => [
        new ConsentDefinition('test.processing', 'test-v1', str_repeat('a', 64), true),
        new ConsentDefinition('test.required', 'test-v1', str_repeat('b', 64), false),
    ],
];
