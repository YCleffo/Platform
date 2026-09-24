<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Catalog\Application\PlanCatalog;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CatalogController
{
    public function __construct(private PlanCatalog $catalog)
    {
    }

    #[Route('/api/control/catalog/plans', name: 'control_catalog_plans', methods: ['GET'])]
    public function plans(): JsonResponse
    {
        return new JsonResponse(['plans' => $this->catalog->published()]);
    }
}
