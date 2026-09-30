<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

use App\Core\Privacy\Application\PrivacyResourceUsePolicy;
use DomainException;

final readonly class CreatePersonContact
{
    public function __construct(
        private PersonRepository $persons,
        private PersonContactRepository $contacts,
        private ContactNormalizer $normalizer,
        private PrivacyResourceUsePolicy $privacy,
    ) {}

    public function execute(CreatePersonContactCommand $command): ?PersonContactView
    {
        $person = $this->persons->findById($command->personId);
        if ($person === null || $person->status === 'archived') {
            return null;
        }
        if (!$this->privacy->allowed($command->personId, 'core.person_contact',
            $command->personId, 'identity')) {
            throw new DomainException('Person contact processing is restricted.');
        }

        return $this->contacts->create(
            $command,
            $this->normalizer->normalize($command->contactType, $command->value),
        );
    }
}
