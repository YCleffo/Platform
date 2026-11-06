<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Catalog\Authorization\CatalogOperator;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use ControlPlane\Privacy\ControlPrivacyOperator;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Operator read models only. Existing application services still authorize every write. */
#[Route('/api/control/operator', name: 'control_operator_')]
final class OperatorWorkspaceController extends AbstractController
{
    public function __construct(private readonly Connection $db, private readonly ControlPrivacyOperator $privacy, private readonly CatalogOperator $catalogOperators) {}

    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->actor();
        $privacy = $this->isPrivacy($user->id());
        $finance = $this->isFinance($user->id());
        $catalog = $this->catalogOperators->isAuthorized($user->id());
        if (!$privacy && !$finance && !$catalog) return $this->privateResponse(['error' => ['code' => 'operator_authorization_required', 'message' => 'Operator authorization required.']], 403);
        return $this->privateResponse(['user' => ['id' => $user->id(), 'email' => $user->getUserIdentifier()], 'privacy' => $privacy, 'finance' => $finance, 'catalog' => $catalog]);
    }

    #[Route('/privacy/requests', name: 'requests', methods: ['GET'])]
    public function requests(Request $request): JsonResponse
    {
        $this->privacy->assertAuthorized($this->actor()->id());
        [$limit, $offset] = $this->pagination($request);
        return $this->privateResponse(['items' => $this->db->fetchAllAssociative('SELECT id, subject_kind, subject_reference, billing_profile_id, invoice_id, receipt_id, request_type, source_channel, status, received_at, completed_at, resolution_code FROM commercial.privacy_subject_requests ORDER BY received_at DESC, id DESC LIMIT ? OFFSET ?', [$limit, $offset]), 'total' => (int) $this->db->fetchOne('SELECT count(*) FROM commercial.privacy_subject_requests')]);
    }

    #[Route('/privacy/incidents', name: 'incidents', methods: ['GET'])]
    public function incidents(Request $request): JsonResponse
    {
        $this->privacy->assertAuthorized($this->actor()->id());
        [$limit, $offset] = $this->pagination($request);
        return $this->privateResponse(['items' => $this->db->fetchAllAssociative('SELECT id, status, detected_at, initial_notification_due_at, investigation_notification_due_at, estimated_subjects, containment_reference, investigation_reference, initial_notification_reference, investigation_notification_reference FROM commercial.privacy_incidents ORDER BY detected_at DESC, id DESC LIMIT ? OFFSET ?', [$limit, $offset]), 'total' => (int) $this->db->fetchOne('SELECT count(*) FROM commercial.privacy_incidents')]);
    }

    #[Route('/compensations', name: 'compensations', methods: ['GET'])]
    public function compensations(Request $request): JsonResponse
    {
        if (!$this->isFinance($this->actor()->id())) throw $this->createAccessDeniedException('Financial operator authorization required.');
        [$limit, $offset] = $this->pagination($request);
        return $this->privateResponse(['items' => $this->db->fetchAllAssociative(<<<'SQL'
SELECT c.id, c.commercial_account_id, ac.display_name AS account_name, c.status, c.compensation_status,
 a.id AS attestation_id, a.status AS attestation_status, a.proposed_by,
 a.external_transaction_reference, a.document_reference, a.document_sha256,
 COALESCE(a.amount_minor, CASE WHEN p.settled_count=1 THEN p.amount_minor END) AS amount_minor,
 COALESCE(a.currency, c.currency) AS currency
FROM commercial.subscription_plan_changes c
JOIN commercial.commercial_accounts ac ON ac.id=c.commercial_account_id
LEFT JOIN LATERAL (
 SELECT count(*) AS settled_count, min(amount_minor) AS amount_minor
 FROM commercial.payments WHERE invoice_id=c.invoice_id AND status='succeeded'
) p ON true
LEFT JOIN commercial.external_compensation_attestations a ON a.plan_change_id=c.id AND a.status IN ('proposed','approved')
WHERE c.compensation_status='recovery_required' OR a.id IS NOT NULL
ORDER BY c.requested_at DESC, c.id DESC LIMIT ? OFFSET ?
SQL, [$limit, $offset]), 'total' => (int) $this->db->fetchOne("SELECT count(*) FROM commercial.subscription_plan_changes c WHERE c.compensation_status='recovery_required' OR EXISTS (SELECT 1 FROM commercial.external_compensation_attestations a WHERE a.plan_change_id=c.id AND a.status IN ('proposed','approved'))")]);
    }

    private function isPrivacy(string $id): bool
    {
        return $this->db->fetchOne("SELECT 1 FROM commercial.privacy_operators o JOIN commercial.control_users u ON u.id=o.control_user_id WHERE o.control_user_id=? AND o.authorization_reference<>'' AND u.status='active'", [$id]) !== false;
    }

    private function isFinance(string $id): bool
    {
        return $this->db->fetchOne("SELECT 1 FROM commercial.financial_operators o JOIN commercial.control_users u ON u.id=o.control_user_id WHERE o.control_user_id=? AND o.revoked_at IS NULL AND u.status='active'", [$id]) !== false;
    }

    /** @return array{int,int} */
    private function pagination(Request $request): array
    {
        $limit = filter_var($request->query->get('limit', '25'), FILTER_VALIDATE_INT);
        $offset = filter_var($request->query->get('offset', '0'), FILTER_VALIDATE_INT);
        if ($limit === false || $offset === false || $limit < 1 || $limit > 100 || $offset < 0) throw new \InvalidArgumentException('Invalid pagination.');
        return [$limit, $offset];
    }

    /** @param array<string,mixed> $data */
    private function privateResponse(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    private function actor(): ControlUserPrincipal
    {
        $user = $this->getUser();
        if (!$user instanceof ControlUserPrincipal) throw $this->createAccessDeniedException();
        return $user;
    }
}
