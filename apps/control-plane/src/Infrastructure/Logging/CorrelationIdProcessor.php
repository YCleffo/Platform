<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Logging;

use ControlPlane\Infrastructure\Http\CorrelationIdSubscriber;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class CorrelationIdProcessor
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $correlationId = $this->requestStack->getCurrentRequest()?->attributes->get(CorrelationIdSubscriber::ATTRIBUTE);
        if (!is_string($correlationId)) {
            return $record;
        }

        return $record->with(extra: [...$record->extra, 'correlation_id' => $correlationId]);
    }
}
