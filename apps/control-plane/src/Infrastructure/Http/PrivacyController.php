<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Identity\Security\ControlUserPrincipal;
use ControlPlane\Privacy\ControlPersonalDataExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/control/privacy', name: 'control_privacy_')]
final class PrivacyController extends AbstractController
{
    public function __construct(private readonly ControlPersonalDataExporter $exporter)
    {
    }

    #[Route('/me/export', name: 'self_export', methods: ['GET'])]
    public function exportSelf(): JsonResponse
    {
        $principal = $this->getUser();
        if (!$principal instanceof ControlUserPrincipal) {
            throw $this->createAccessDeniedException();
        }
        $response = new JsonResponse($this->exporter->exportSelf($principal->id(), $principal->id()));
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }
}
