<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Catalog\Application\CatalogManagement;
use ControlPlane\Catalog\Authorization\CatalogOperator;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/control/operator/catalog', name: 'control_operator_catalog_')]
final class CatalogOperatorController extends AbstractController
{
    public function __construct(
        private readonly CatalogManagement $catalog,
        private readonly CatalogOperator $operators,
    ) {}

    #[Route('/plans', name: 'plans', methods: ['GET'])]
    public function plans(): JsonResponse
    {
        $this->authorize();
        return $this->privateResponse(['plans' => $this->catalog->all()]);
    }

    #[Route('/plans', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->authorize();
        return $this->privateResponse(
            ['plan' => $this->catalog->createDraft(
                $this->actor()->id(),
                ApiRequest::json($request),
                ApiRequest::correlationId($request),
            )],
            201,
        );
    }

    #[Route('/plans/{planId}', name: 'update', methods: ['PATCH'])]
    public function update(string $planId, Request $request): JsonResponse
    {
        $this->authorize();
        $this->assertUuid($planId);
        return $this->privateResponse(['plan' => $this->catalog->updateDraft(
            $this->actor()->id(),
            $planId,
            ApiRequest::json($request),
            ApiRequest::correlationId($request),
        )]);
    }

    #[Route('/plans/{planId}/publish', name: 'publish', methods: ['POST'])]
    public function publish(string $planId, Request $request): JsonResponse
    {
        $this->authorize();
        $this->assertUuid($planId);
        return $this->privateResponse(['plan' => $this->catalog->publish(
            $this->actor()->id(),
            $planId,
            ApiRequest::correlationId($request),
        )]);
    }

    #[Route('/plans/{planId}/versions', name: 'version', methods: ['POST'])]
    public function version(string $planId, Request $request): JsonResponse
    {
        $this->authorize();
        $this->assertUuid($planId);
        return $this->privateResponse(['plan' => $this->catalog->newVersion(
            $this->actor()->id(),
            $planId,
            ApiRequest::correlationId($request),
        )], 201);
    }

    private function authorize(): void
    {
        $this->operators->assertAuthorized($this->actor()->id());
    }

    private function assertUuid(string $value): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            throw new NotFoundHttpException('Catalog plan not found.');
        }
    }

    /** @param array<string, mixed> $data */
    private function privateResponse(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    private function actor(): ControlUserPrincipal
    {
        $user = $this->getUser();
        if (!$user instanceof ControlUserPrincipal) {
            throw $this->createAccessDeniedException();
        }
        return $user;
    }
}
