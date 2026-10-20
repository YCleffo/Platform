<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\MachineCode;
use DomainException;

final readonly class PersonalDataErasureHandlerRegistry
{
    /** @var array<string, PersonalDataErasureHandler> */
    private array $handlers;

    /** @param iterable<PersonalDataErasureHandler> $handlers */
    public function __construct(iterable $handlers = [])
    {
        $byCode = [];
        foreach ($handlers as $handler) {
            MachineCode::assert($handler->code());
            MachineCode::assert($handler->resourceType());
            if (isset($byCode[$handler->code()])) {
                throw new DomainException('Duplicate erasure handler code.');
            }
            $byCode[$handler->code()] = $handler;
        }
        $this->handlers = $byCode;
    }

    public function forAction(string $resourceType, string $action): ?PersonalDataErasureHandler
    {
        $found = null;
        foreach ($this->handlers as $handler) {
            if ($handler->resourceType() === $resourceType && $handler->supportsAction($action)) {
                if ($found !== null) {
                    throw new DomainException('Ambiguous erasure handler ownership.');
                }
                $found = $handler;
            }
        }
        return $found;
    }

    public function available(): bool { return $this->handlers !== []; }
}
