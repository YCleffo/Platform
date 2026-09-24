<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

final class CorrelationIdSubscriber implements EventSubscriberInterface
{
    public const ATTRIBUTE = '_control_correlation_id';
    public const HEADER = 'X-Correlation-ID';

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $candidate = $event->getRequest()->headers->get(self::HEADER);
        $correlationId = is_string($candidate) && Uuid::isValid($candidate)
            ? strtolower($candidate)
            : Uuid::v7()->toRfc4122();
        $event->getRequest()->attributes->set(self::ATTRIBUTE, $correlationId);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $correlationId = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if (is_string($correlationId)) {
            $event->getResponse()->headers->set(self::HEADER, $correlationId);
        }
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onRequest',
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }
}
