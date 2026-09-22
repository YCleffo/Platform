<?php

declare(strict_types=1);

namespace App\Core\Account\Application;

use App\Core\Person\Application\PersonRepository;

final readonly class CreateAccount
{
    public function __construct(
        private AccountRepository $accounts,
        private PersonRepository $persons,
    ) {}

    public function execute(CreateAccountCommand $command): ?AccountView
    {
        if ($command->personId !== null) {
            $person = $this->persons->findById($command->personId);
            if ($person === null || $person->status === 'archived') {
                return null;
            }
        }

        return $this->accounts->create($command);
    }
}
