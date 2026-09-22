<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

final readonly class CreatePersonContact
{
    public function __construct(
        private PersonRepository $persons,
        private PersonContactRepository $contacts,
        private ContactNormalizer $normalizer,
    ) {}

    public function execute(CreatePersonContactCommand $command): ?PersonContactView
    {
        $person = $this->persons->findById($command->personId);
        if ($person === null || $person->status === 'archived') {
            return null;
        }

        return $this->contacts->create(
            $command,
            $this->normalizer->normalize($command->contactType, $command->value),
        );
    }
}
