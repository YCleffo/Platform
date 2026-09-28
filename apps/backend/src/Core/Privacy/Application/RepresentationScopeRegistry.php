<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\MachineCode;
use InvalidArgumentException;

/** Scope codes are registered by Core or installed modules, never inferred from a relationship type. */
final readonly class RepresentationScopeRegistry
{
    private const CORE = [
        'privacy.consent.grant', 'privacy.consent.withdraw',
        'privacy.request.submit', 'privacy.request.view',
        'privacy.correction.request', 'privacy.processing_termination.request',
        'privacy.erasure.request', 'privacy.export.request',
    ];

    /** @var list<string> */
    private array $codes;

    /** @param list<string> $additionalCodes */
    public function __construct(array $additionalCodes = [])
    {
        foreach ($additionalCodes as $code) {
            MachineCode::assert($code);
        }
        $codes = [...self::CORE, ...$additionalCodes];
        if (count($codes) !== count(array_unique($codes))) {
            throw new InvalidArgumentException('Duplicate representation scope.');
        }
        $this->codes = $codes;
    }

    /** @param list<string> $scopes */
    public function assertKnownSet(array $scopes): void
    {
        if ($scopes === [] || count($scopes) > 32 || count($scopes) !== count(array_unique($scopes))) {
            throw new InvalidArgumentException('Authority requires distinct explicit scopes.');
        }
        foreach ($scopes as $scope) {
            MachineCode::assert($scope);
            if (!in_array($scope, $this->codes, true)) {
                throw new InvalidArgumentException('Unknown representation scope.');
            }
        }
    }
}
