<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

use App\Core\Shared\Domain\ValueNormalizer;
use App\Core\Privacy\Application\PrivacyResourceUsePolicy;
use DomainException;
use InvalidArgumentException;

final readonly class UpdatePersonContact
{
    public function __construct(private PersonContactRepository $contacts,
        private ContactNormalizer $normalizer, private PrivacyResourceUsePolicy $privacy) {}

    /** @param array<string, mixed> $changes */
    public function execute(string $personId, string $contactId, array $changes): ?PersonContactView
    {
        if (!$this->privacy->allowed($personId, 'core.person_contact', $contactId, 'identity')) {
            throw new DomainException('Person contact processing is restricted.');
        }
        if ($changes === [] || array_diff(array_keys($changes), ['value', 'label', 'isPrimary']) !== []) {
            throw new DomainException('Invalid contact correction fields.');
        }
        $contact = null;
        foreach ($this->contacts->findByPersonId($personId) as $candidate) {
            if ($candidate->id === $contactId) {
                $contact = $candidate;
                break;
            }
        }
        if ($contact === null) {
            throw new DomainException('Contact does not belong to subject.');
        }
        $valueProvided = array_key_exists('value', $changes);
        $labelProvided = array_key_exists('label', $changes);
        $value = $changes['value'] ?? null;
        $label = $changes['label'] ?? null;
        $isPrimary = $changes['isPrimary'] ?? null;
        if (($valueProvided && (!is_string($value) || strlen($value) > 320))
            || ($labelProvided && $label !== null && (!is_string($label) || strlen($label) > 80))
            || (array_key_exists('isPrimary', $changes) && !is_bool($isPrimary))) {
            throw new DomainException('Invalid contact correction value.');
        }
        try {
            $normalized = $valueProvided ? $this->normalizer->normalize($contact->contactType, $value) : null;
            $label = $labelProvided ? ValueNormalizer::normalizeLabel($label) : null;
        } catch (InvalidArgumentException) {
            throw new DomainException('Invalid contact correction value.');
        }
        return $this->contacts->updateOwned($personId, $contactId, is_string($value) ? $value : null, $normalized,
            $valueProvided, $label, $labelProvided, is_bool($isPrimary) ? $isPrimary : null);
    }
}
