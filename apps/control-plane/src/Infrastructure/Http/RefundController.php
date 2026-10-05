<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Identity\Security\ControlUserPrincipal;
use ControlPlane\Identity\Application\AuthSecurityService;
use ControlPlane\Refund\Application\CreateRefund;
use ControlPlane\Refund\Application\GetRefunds;
use ControlPlane\Refund\Application\SubmitYooKassaRefund;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RefundController extends AbstractController
{
    public function __construct(private readonly CreateRefund $creator, private readonly GetRefunds $reader, private readonly SubmitYooKassaRefund $submission, private readonly AuthSecurityService $authSecurity)
    {
    }

    #[Route('/api/control/accounts/{accountId}/payments/{paymentId}/refunds', name: 'control_refund_create', methods: ['POST'])]
    public function create(string $accountId, string $paymentId, Request $request): JsonResponse
    {
        $this->authSecurity->requireRecent($this->user(), true);
        $key = $request->headers->get('Idempotency-Key');
        if (!is_string($key)) {
            throw new \InvalidArgumentException('Idempotency-Key is required.');
        }
        $body = ApiRequest::json($request);
        if (array_keys($body) !== ['items'] || !is_array($body['items']) || !array_is_list($body['items'])) {
            throw new \InvalidArgumentException('Only an items array is accepted.');
        }
        $items = [];
        foreach ($body['items'] as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new \InvalidArgumentException('Every refund item must be an object.');
            }
            $keys = array_keys($item);
            sort($keys);
            if ($keys !== ['invoice_line_id', 'quantity', 'unit_amount_minor']) {
                throw new \InvalidArgumentException('Refund items require invoice_line_id, quantity and unit_amount_minor.');
            }
            $items[] = ['invoice_line_id' => ApiRequest::string($item, 'invoice_line_id'), 'quantity' => ApiRequest::integer($item, 'quantity'), 'unit_amount_minor' => ApiRequest::integer($item, 'unit_amount_minor')];
        }
        $correlationId = ApiRequest::correlationId($request);
        $refund = $this->creator->create($this->user()->id(), $accountId, $paymentId, $items, $key, $correlationId);
        $this->submission->submit((string) $refund['id'], $correlationId);

        return new JsonResponse($this->reader->one($this->user()->id(), $accountId, (string) $refund['id']), Response::HTTP_CREATED);
    }

    #[Route('/api/control/accounts/{accountId}/payments/{paymentId}/refunds', name: 'control_refund_list', methods: ['GET'])]
    public function list(string $accountId, string $paymentId): JsonResponse
    {
        return new JsonResponse(['items' => $this->reader->forPayment($this->user()->id(), $accountId, $paymentId)]);
    }

    #[Route('/api/control/accounts/{accountId}/refunds/{refundId}', name: 'control_refund_get', methods: ['GET'])]
    public function get(string $accountId, string $refundId): JsonResponse
    {
        return new JsonResponse($this->reader->one($this->user()->id(), $accountId, $refundId));
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
