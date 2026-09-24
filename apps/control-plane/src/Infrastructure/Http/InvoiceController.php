<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Billing\Application\GetInvoice;
use ControlPlane\Billing\Application\IssueInvoice;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class InvoiceController extends AbstractController
{
    public function __construct(private readonly IssueInvoice $issuer, private readonly GetInvoice $reader)
    {
    }

    #[Route('/api/control/accounts/{accountId}/subscriptions/{subscriptionId}/invoice', name: 'control_invoice_issue', methods: ['POST'])]
    public function issue(string $accountId, string $subscriptionId, Request $request): JsonResponse
    {
        $key = $request->headers->get('Idempotency-Key');
        if (!is_string($key)) {
            throw new \InvalidArgumentException('Idempotency-Key is required.');
        }
        ApiRequest::emptyObject($request);

        return new JsonResponse($this->issuer->issue(
            $this->user()->id(),
            $accountId,
            $subscriptionId,
            $key,
            ApiRequest::correlationId($request),
        ), Response::HTTP_CREATED);
    }

    #[Route('/api/control/accounts/{accountId}/invoices/{invoiceId}', name: 'control_invoice_get', methods: ['GET'])]
    public function get(string $accountId, string $invoiceId): JsonResponse
    {
        return new JsonResponse($this->reader->get($this->user()->id(), $accountId, $invoiceId));
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
