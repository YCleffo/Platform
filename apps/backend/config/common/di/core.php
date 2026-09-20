<?php

declare(strict_types=1);

use App\Core\Person\Application\PersonRepository;
use App\Core\Person\Infrastructure\PostgresPersonRepository;

return [
    PersonRepository::class => [
        'class' => PostgresPersonRepository::class,
    ],
];
