<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Payment\Application\ReceiveYooKassaWebhook;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class YooKassaWebhookController
{
    public function __construct(
        private ReceiveYooKassaWebhook $webhook,
        private DatabaseRateLimiter $rateLimiter,
        private string $allowedIps = '',
    ) {
    }

    #[Route('/api/control/webhooks/yookassa', name: 'control_webhook_yookassa', methods: ['POST'])]
    public function receive(Request $request): JsonResponse
    {
        $ip = $request->getClientIp() ?? 'unknown';
        $allowedIps = array_values(array_filter(array_map('trim', explode(',', $this->allowedIps))));
        if ($allowedIps !== [] && !IpUtils::checkIp($ip, $allowedIps)) {
            throw new AccessDeniedHttpException('Webhook source is not allowed.');
        }
        $this->rateLimiter->consume('webhook.yookassa.ip', $ip, 120, 60);

        $contentType = $request->headers->get('Content-Type', '');

        return new JsonResponse($this->webhook->receive(
            $request->getContent(),
            is_string($contentType) ? $contentType : '',
            ApiRequest::correlationId($request),
        ));
    }
}
