<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Identity\Application\AuthSecurityService;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use ControlPlane\Subscription\Application\CancelSubscriptionPlanChange;
use ControlPlane\Subscription\Application\GetSubscriptionPlanChange;
use ControlPlane\Subscription\Application\RequestSubscriptionPlanChange;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/control/accounts/{accountId}', name: 'control_plan_changes_')]
final class SubscriptionPlanChangeController extends AbstractController
{
    public function __construct(private readonly RequestSubscriptionPlanChange $requests,
        private readonly GetSubscriptionPlanChange $reader,
        private readonly CancelSubscriptionPlanChange $cancellations,
        private readonly AuthSecurityService $authSecurity) {}

    #[Route('/subscriptions/{subscriptionId}/plan-changes', name: 'create', methods: ['POST'])]
    public function create(string $accountId, string $subscriptionId, Request $request): JsonResponse
    {
        $this->authSecurity->requireSensitiveMfa($this->user());
        $key = $request->headers->get('Idempotency-Key');
        if (!is_string($key)) {
            throw new \InvalidArgumentException('Idempotency-Key is required.');
        }
        $body = ApiRequest::json($request);

        return new JsonResponse($this->requests->request($this->user()->id(), $accountId, $subscriptionId,
            ApiRequest::string($body, 'plan_price_id'), ApiRequest::integer($body, 'quantity'), $key,
            ApiRequest::correlationId($request)), Response::HTTP_CREATED);
    }

    #[Route('/plan-changes/{changeId}', name: 'get', methods: ['GET'])]
    public function get(string $accountId, string $changeId): JsonResponse
    {
        return new JsonResponse($this->reader->get($this->user()->id(), $accountId, $changeId));
    }

    #[Route('/plan-changes/{changeId}/cancel', name: 'cancel', methods: ['POST'])]
    public function cancel(string $accountId, string $changeId, Request $request): JsonResponse
    {
        $this->authSecurity->requireSensitiveMfa($this->user());
        $key = $request->headers->get('Idempotency-Key');
        if (!is_string($key)) {
            throw new \InvalidArgumentException('Idempotency-Key is required.');
        }
        return new JsonResponse($this->cancellations->cancel($this->user()->id(), $accountId, $changeId,
            $key, ApiRequest::correlationId($request)));
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
