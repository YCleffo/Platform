<?php
declare(strict_types=1);
namespace ControlPlane\Infrastructure\Outbox;
final readonly class ControlEmailOutboxDispatcher
{
    /** @param iterable<EmailEventProcessor> $processors */
    public function __construct(private iterable $processors) {}
    public function processOne(): bool { foreach ($this->processors as $processor) { if ($processor->processOne()) return true; } return false; }
}
