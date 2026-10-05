<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Account\Application\BillingProfileService;
use ControlPlane\Account\Application\CreateCommercialAccount;
use ControlPlane\Account\Application\MembershipService;
use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Application\AuthSecurityService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/control/accounts', name: 'control_accounts_')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CreateCommercialAccount $accounts,
        private readonly MembershipService $memberships,
        private readonly BillingProfileService $billing,
        private readonly AccountAuthorization $authorization,
        private readonly DatabaseRateLimiter $rateLimiter,
        private readonly AuthSecurityService $authSecurity,
    ) {
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->user();
        $this->rateLimiter->consume('account.create.user', $user->id(), 10, 3600);
        $key = $request->headers->get('Idempotency-Key');
        if (!is_string($key)) {
            throw new \InvalidArgumentException('Idempotency-Key is required.');
        }
        $data = ApiRequest::json($request);
        $id = $this->accounts->create($user->id(), ApiRequest::string($data, 'code'), ApiRequest::string($data, 'display_name'), ApiRequest::nullableString($data, 'legal_name'), $key, ApiRequest::correlationId($request));

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    #[Route('/{accountId}', name: 'get', methods: ['GET'])]
    public function get(string $accountId): JsonResponse
    {
        $this->authorization->requirePermission($this->user()->id(), $accountId, 'account.read');
        $account = $this->connection->fetchAssociative('SELECT id, code, display_name, legal_name, status, onboarding_state, created_at, updated_at FROM commercial.commercial_accounts WHERE id = ?', [$accountId]);
        if ($account === false) {
            throw new \DomainException('Account was not found.');
        }

        return new JsonResponse($account);
    }

    #[Route('/{accountId}/memberships', name: 'membership_add', methods: ['POST'])]
    public function addMembership(string $accountId, Request $request): JsonResponse
    {
        $this->authSecurity->requireSensitiveMfa($this->user());
        $data = ApiRequest::json($request);
        $id = $this->memberships->add($this->user()->id(), $accountId, ApiRequest::string($data, 'user_id'), ApiRequest::string($data, 'role'), ApiRequest::correlationId($request));

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    #[Route('/{accountId}/memberships/{membershipId}', name: 'membership_change', methods: ['PATCH'])]
    public function changeMembership(string $accountId, string $membershipId, Request $request): JsonResponse
    {
        $this->authSecurity->requireSensitiveMfa($this->user());
        $data = ApiRequest::json($request);
        $this->memberships->changeRole($this->user()->id(), $accountId, $membershipId, ApiRequest::string($data, 'role'), ApiRequest::correlationId($request));

        return new JsonResponse(['status' => 'updated']);
    }

    #[Route('/{accountId}/memberships/{membershipId}', name: 'membership_remove', methods: ['DELETE'])]
    public function removeMembership(string $accountId, string $membershipId, Request $request): Response
    {
        $this->authSecurity->requireSensitiveMfa($this->user());
        $this->memberships->remove($this->user()->id(), $accountId, $membershipId, ApiRequest::correlationId($request));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/{accountId}/billing-profile', name: 'billing_profile', methods: ['PUT'])]
    public function billingProfile(string $accountId, Request $request): JsonResponse
    {
        $this->authSecurity->requireSensitiveMfa($this->user());
        $data = ApiRequest::json($request);
        $address = $data['address'] ?? [];
        if (!is_array($address) || array_is_list($address)) {
            throw new \InvalidArgumentException('Address must be a JSON object.');
        }
        $id = $this->billing->save($this->user()->id(), $accountId, ApiRequest::string($data, 'legal_name'), ApiRequest::string($data, 'country_code'), ApiRequest::nullableString($data, 'contact_name'), ApiRequest::nullableString($data, 'contact_email'), ApiRequest::nullableString($data, 'tax_identifier'), $address, ApiRequest::correlationId($request));

        return new JsonResponse(['id' => $id]);
    }

    #[Route('/{accountId}/onboarding/catalog-reviewed', name: 'catalog_reviewed', methods: ['POST'])]
    public function catalogReviewed(string $accountId, Request $request): JsonResponse
    {
        $userId = $this->user()->id();
        $this->authorization->requirePermission($userId, $accountId, 'account.manage');
        $changed = $this->connection->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state = 'ready_for_subscription', updated_at = now() WHERE id = ? AND onboarding_state = 'catalog_selection_pending' AND EXISTS (SELECT 1 FROM commercial.billing_profiles WHERE commercial_account_id = ? AND archived_at IS NULL)", [$accountId, $accountId]);
        if ($changed !== 1) {
            throw new \DomainException('Onboarding state transition is not currently allowed.');
        }
        $this->connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, actor_id, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('control_user', ?, ?, 'commercial_account.onboarding_ready', 'commercial_account', ?, ?, 'success')", [$userId, $accountId, $accountId, ApiRequest::correlationId($request)]);

        return new JsonResponse(['onboarding_state' => 'ready_for_subscription']);
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
