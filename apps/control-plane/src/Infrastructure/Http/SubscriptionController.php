<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Identity\Security\ControlUserPrincipal;
use ControlPlane\Identity\Application\AuthSecurityService;
use ControlPlane\Subscription\Application\CancelSubscription;
use ControlPlane\Subscription\Application\CreateSubscription;
use ControlPlane\Subscription\Application\GetSubscription;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/control/accounts/{accountId}/subscriptions', name: 'control_subscriptions_')]
final class SubscriptionController extends AbstractController
{
    public function __construct(private readonly CreateSubscription $creator, private readonly GetSubscription $reader, private readonly CancelSubscription $canceller, private readonly AuthSecurityService $authSecurity)
    {
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $accountId, Request $request): JsonResponse
    {
        $this->authSecurity->requireRecent($this->user(), true);
        $key = $request->headers->get('Idempotency-Key');
        if (!is_string($key)) {
            throw new \InvalidArgumentException('Idempotency-Key is required.');
        }
        $data = ApiRequest::json($request);
        $result = $this->creator->create(
            $this->user()->id(),
            $accountId,
            ApiRequest::string($data, 'plan_price_id'),
            ApiRequest::integer($data, 'quantity'),
            $key,
            ApiRequest::correlationId($request),
        );

        return new JsonResponse($result, Response::HTTP_CREATED);
    }

    #[Route('/{subscriptionId}', name: 'get', methods: ['GET'])]
    public function get(string $accountId, string $subscriptionId): JsonResponse
    {
        return new JsonResponse($this->reader->get($this->user()->id(), $accountId, $subscriptionId));
    }

    #[Route('/{subscriptionId}/cancel', name: 'cancel', methods: ['POST'])]
    public function cancel(string $accountId, string $subscriptionId, Request $request): JsonResponse
    {
        $this->authSecurity->requireRecent($this->user(), true);
        $key = $request->headers->get('Idempotency-Key');
        if (!is_string($key)) {
            throw new \InvalidArgumentException('Idempotency-Key is required.');
        }

        return new JsonResponse($this->canceller->cancel(
            $this->user()->id(),
            $accountId,
            $subscriptionId,
            $key,
            ApiRequest::correlationId($request),
        ));
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
