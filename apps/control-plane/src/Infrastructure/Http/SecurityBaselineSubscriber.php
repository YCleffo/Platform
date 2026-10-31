<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class SecurityBaselineSubscriber implements EventSubscriberInterface
{
    private const MAX_BODY_BYTES = 65_536;
    private const SENSITIVE_API_PATH = '~^/api/(?:control|m2m)(?:/|$)~';

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }
        if (!$this->bodyTooLarge($event->getRequest())) {
            return;
        }
        $event->setResponse(new JsonResponse(['error' => ['code' => 'request_too_large', 'message' => 'The request body is too large.']], Response::HTTP_REQUEST_ENTITY_TOO_LARGE));
    }

    private function bodyTooLarge(Request $request): bool
    {
        $contentLength = $request->headers->get('Content-Length');
        if (is_string($contentLength) && ctype_digit($contentLength)) {
            return (int) $contentLength > self::MAX_BODY_BYTES;
        }
        $resource = $request->getContent(true);
        if (!is_resource($resource)) {
            return false;
        }
        $prefix = fread($resource, self::MAX_BODY_BYTES + 1);

        return is_string($prefix) && strlen($prefix) > self::MAX_BODY_BYTES;
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
        if (preg_match(self::SENSITIVE_API_PATH, $event->getRequest()->getPathInfo()) === 1) {
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
