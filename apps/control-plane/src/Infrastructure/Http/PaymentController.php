<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Identity\Security\ControlUserPrincipal;
use ControlPlane\Identity\Application\AuthSecurityService;
use ControlPlane\Fiscalization\Application\GetReceipt;
use ControlPlane\Payment\Application\CreatePayment;
use ControlPlane\Payment\Application\GetPayment;
use ControlPlane\Payment\Application\ManageSavedPaymentMethods;
use ControlPlane\Payment\Application\StartYooKassaPayment;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PaymentController extends AbstractController
{
    public function __construct(
        private readonly CreatePayment $creator,
        private readonly GetPayment $reader,
        private readonly ManageSavedPaymentMethods $savedMethods,
        private readonly StartYooKassaPayment $checkout,
        private readonly GetReceipt $receipts,
        private readonly AuthSecurityService $authSecurity,
    )
    {
    }

    #[Route('/api/control/accounts/{accountId}/invoices/{invoiceId}/payment', name: 'control_payment_create', methods: ['POST'])]
    public function create(string $accountId, string $invoiceId, Request $request): JsonResponse
    {
        $this->authSecurity->requireSensitiveMfa($this->user());
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

    #[Route('/api/control/accounts/{accountId}/payment-methods', name: 'control_saved_payment_method_list', methods: ['GET'])]
    public function listSavedMethods(string $accountId): JsonResponse
    {
        return new JsonResponse(['items' => $this->savedMethods->list($this->user()->id(), $accountId)]);
    }

    #[Route('/api/control/accounts/{accountId}/payment-methods/{methodId}/default', name: 'control_saved_payment_method_default', methods: ['POST'])]
    public function setDefaultSavedMethod(string $accountId, string $methodId, Request $request): JsonResponse
    {
        $this->authSecurity->requireSensitiveMfa($this->user());
        ApiRequest::emptyObject($request);

        return new JsonResponse($this->savedMethods->setDefault($this->user()->id(), $accountId, $methodId, ApiRequest::correlationId($request)));
    }

    #[Route('/api/control/accounts/{accountId}/payment-methods/{methodId}', name: 'control_saved_payment_method_disable', methods: ['DELETE'])]
    public function disableSavedMethod(string $accountId, string $methodId, Request $request): Response
    {
        $this->authSecurity->requireSensitiveMfa($this->user());
        ApiRequest::emptyObject($request);
        $this->savedMethods->disable($this->user()->id(), $accountId, $methodId, ApiRequest::correlationId($request));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/control/accounts/{accountId}/payments/{paymentId}/checkout', name: 'control_payment_checkout', methods: ['POST'])]
    public function checkout(string $accountId, string $paymentId, Request $request): JsonResponse
    {
        $this->authSecurity->requireSensitiveMfa($this->user());
        $key = $request->headers->get('Idempotency-Key');
        if (!is_string($key)) {
            throw new \InvalidArgumentException('Idempotency-Key is required.');
        }
        $body = ApiRequest::json($request);
        $keys = array_keys($body);
        sort($keys);
        if ($keys !== ['method', 'provider'] && $keys !== ['method', 'provider', 'save_payment_method']) {
            throw new \InvalidArgumentException('Only provider, method and save_payment_method fields are accepted.');
        }
        $save = $body['save_payment_method'] ?? false;
        if (!is_bool($save)) throw new \InvalidArgumentException('save_payment_method must be boolean.');

        return new JsonResponse($this->checkout->start(
            $this->user()->id(),
            $accountId,
            $paymentId,
            ApiRequest::string($body, 'provider'),
            ApiRequest::string($body, 'method'),
            $save,
            $key,
            ApiRequest::correlationId($request),
        ));
    }

    #[Route('/api/control/accounts/{accountId}/payments/{paymentId}/receipt', name: 'control_payment_receipt_get', methods: ['GET'])]
    public function receipt(string $accountId, string $paymentId): JsonResponse
    {
        return new JsonResponse($this->receipts->get($this->user()->id(), $accountId, $paymentId));
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
