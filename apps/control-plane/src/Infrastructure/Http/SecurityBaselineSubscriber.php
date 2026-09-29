<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class SecurityBaselineSubscriber implements EventSubscriberInterface
{
    private const MAX_BODY_BYTES = 65_536;

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }
        $request = $event->getRequest();
        $contentLength = $request->headers->get('Content-Length');
        if ((is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength > self::MAX_BODY_BYTES)
            || strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            $event->setResponse(new JsonResponse(['error' => ['code' => 'request_too_large', 'message' => 'The request body is too large.']], Response::HTTP_REQUEST_ENTITY_TOO_LARGE));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $response = $event->getResponse();
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
        if (str_starts_with($event->getRequest()->getPathInfo(), '/api/control/auth')
            || str_starts_with($event->getRequest()->getPathInfo(), '/api/m2m/')) {
            $response->headers->set('Cache-Control', 'no-store');
            $response->headers->set('Pragma', 'no-cache');
        }
    }

    /** @return array<string, array{string, int}> */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 64],
            KernelEvents::RESPONSE => ['onResponse', -64],
        ];
    }
}
