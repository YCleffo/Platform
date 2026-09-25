<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Payment\Application\ReceiveYooKassaWebhook;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class YooKassaWebhookController
{
    public function __construct(private ReceiveYooKassaWebhook $webhook)
    {
    }

    #[Route('/api/control/webhooks/yookassa', name: 'control_webhook_yookassa', methods: ['POST'])]
    public function receive(Request $request): JsonResponse
    {
        $contentType = $request->headers->get('Content-Type', '');

        return new JsonResponse($this->webhook->receive(
            $request->getContent(),
            is_string($contentType) ? $contentType : '',
            ApiRequest::correlationId($request),
        ));
    }
}
