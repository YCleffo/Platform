<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Identity\Security\ControlUserPrincipal;
use ControlPlane\Payment\Application\CreatePayment;
use ControlPlane\Payment\Application\GetPayment;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PaymentController extends AbstractController
{
    public function __construct(private readonly CreatePayment $creator, private readonly GetPayment $reader)
    {
    }

    #[Route('/api/control/accounts/{accountId}/invoices/{invoiceId}/payment', name: 'control_payment_create', methods: ['POST'])]
    public function create(string $accountId, string $invoiceId, Request $request): JsonResponse
    {
        $key = $request->headers->get('Idempotency-Key');
        if (!is_string($key)) {
            throw new \InvalidArgumentException('Idempotency-Key is required.');
        }
        ApiRequest::emptyObject($request);

        return new JsonResponse($this->creator->create($this->user()->id(), $accountId, $invoiceId, $key, ApiRequest::correlationId($request)), Response::HTTP_CREATED);
    }

    #[Route('/api/control/accounts/{accountId}/payments/{paymentId}', name: 'control_payment_get', methods: ['GET'])]
    public function get(string $accountId, string $paymentId): JsonResponse
    {
        return new JsonResponse($this->reader->get($this->user()->id(), $accountId, $paymentId));
    }

    private function user(): ControlUserPrincipal
    {
        $user = $this->getUser();
        if (!$user instanceof ControlUserPrincipal) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
